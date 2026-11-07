<?php

declare(strict_types=1);

namespace App\Domain\Notifications;

use App\Domain\Audit\AuditActor;
use App\Domain\Audit\AuditLogger;
use App\Domain\Identity\Ability;
use App\Domain\Identity\UserAdministration;
use App\Domain\Secrets\Secret;
use App\Domain\Secrets\SecretStore;
use App\Enums\AuditResult;
use App\Enums\NotificationThreshold;
use App\Models\NotificationDestination;
use App\Models\NotificationSubscription;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Destinations of the notification channels and the operators' subscriptions
 * (UZEROWPUP-79). Destinations are an admin matter, from the CLI only
 * (ADR-0008): their token or webhook goes to the SecretStore and nowhere
 * else (ADR-0002). Every change is audited, without the secret and with the
 * address masked; a change that changes nothing is neither saved nor audited.
 *
 * A destination whose channel was removed from the configuration can still be
 * listed, deactivated or have its address edited, but not get a new secret.
 */
final readonly class NotificationAdministration
{
    public const string SECRET_PURPOSE = 'notification-channel';

    public function __construct(
        private NotificationChannels $channels,
        private SecretStore $secrets,
        private AuditLogger $audit,
        private UserAdministration $users,
        private int $nameMaxLength,
        private int $addressMaxLength,
    ) {}

    /**
     * Validates a creation or an edit and says what it would change. A secret
     * is needed for a new destination of a channel with secrets, or when
     * $rotateSecret asks for a new one.
     *
     * @throws DomainException
     */
    public function plan(string $name, ?string $channel, ?string $address, ?bool $active, bool $rotateSecret): DestinationPlan
    {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,'.($this->nameMaxLength - 1).'}$/D', $name) !== 1) {
            throw new DomainException("El nom ha de tenir lletres, xifres, «.», «_» o «-» (màxim {$this->nameMaxLength} caràcters).");
        }
        if ($address !== null && (mb_strlen($address) > $this->addressMaxLength || preg_match('/[\x00-\x1F\x7F]/', $address) === 1)) {
            throw new DomainException("L'adreça no pot tenir caràcters de control ni passar de {$this->addressMaxLength} caràcters.");
        }

        $existing = NotificationDestination::query()->where('name', $name)->first();
        if ($existing === null) {
            if ($channel === null) {
                throw new DomainException("Cal indicar el canal amb --channel ({$this->channels->available()}).");
            }
            if (! in_array($channel, $this->channels->configurable(), true)) {
                throw new DomainException($this->channels->unknownMessage($channel));
            }
            if ($this->channels->needsAddress($channel) && $address === null) {
                throw new DomainException("El canal {$channel} necessita una adreça (--address).");
            }

            $changes = ['address' => ['from' => null, 'to' => $address], 'active' => ['from' => null, 'to' => $active ?? true]];

            return new DestinationPlan($name, $channel, null, $changes, $this->channels->needsSecret($channel));
        }

        if ($channel !== null && $channel !== $existing->channel) {
            throw new DomainException("La destinació «{$name}» és del canal {$existing->channel}; no se'n pot canviar el canal.");
        }
        if ($rotateSecret && ! $this->channels->needsSecret($existing->channel)) {
            throw new DomainException($this->channels->has($existing->channel)
                ? "El canal {$existing->channel} no fa servir cap token ni webhook."
                : $this->channels->unknownMessage($existing->channel));
        }
        $changes = [];
        if ($address !== null && $address !== $existing->address) {
            $changes['address'] = ['from' => $existing->address, 'to' => $address];
        }
        if ($active !== null && $active !== $existing->active) {
            $changes['active'] = ['from' => $existing->active, 'to' => $active];
        }

        return new DestinationPlan($name, $existing->channel, $existing, $changes, $rotateSecret);
    }

    /**
     * Applies a plan. The secret is required when the plan needs one.
     *
     * @throws DomainException
     */
    public function apply(AuditActor $actor, DestinationPlan $plan, #[\SensitiveParameter] ?Secret $secret): NotificationDestination
    {
        if ($plan->needsSecret && ($secret === null || $secret->reveal() === '')) {
            throw new DomainException("Cal el token o webhook del canal {$plan->channel}. No s'ha desat res.");
        }
        if ($plan->changesNothing()) {
            return $plan->existing ?? throw new DomainException('No hi ha cap destinació.');
        }

        return DB::transaction(function () use ($actor, $plan, $secret): NotificationDestination {
            $destination = $plan->existing ?? new NotificationDestination(['name' => $plan->name, 'channel' => $plan->channel]);
            foreach ($plan->changes as $field => $change) {
                $destination->setAttribute($field, $change['to']);
            }
            if ($plan->needsSecret && $secret !== null) {
                $ref = $destination->credential_ref;
                if ($ref !== null && $this->secrets->purpose($ref) === self::SECRET_PURPOSE) {
                    $this->secrets->replace($ref, $secret, $actor);
                } else {
                    $destination->credential_ref = $this->secrets->put(self::SECRET_PURPOSE, $secret, $actor);
                }
            }
            $destination->save();

            $this->audit->record($actor, 'notification.destination.set', AuditResult::Success, 'notification_destination', $destination->id, [
                'name' => $plan->name,
                'channel' => $plan->channel,
                'address' => $destination->address === null ? null : $destination->maskedAddress(),
                'active' => $destination->active,
                'created' => $plan->creates(),
                'secret_set' => $plan->needsSecret,
            ]);

            return $destination;
        });
    }

    /**
     * Who the subscription is for: the operator, or (admins only) another user.
     * A refusal is audited.
     *
     * @throws DomainException
     */
    public function subscriber(AuditActor $actor, User $operator, ?string $username): User
    {
        if ($username === null || $username === $operator->unix_username) {
            return $operator;
        }
        if (! Ability::ManageUsers->allows($operator)) {
            $this->audit->record($actor, 'notification.subscription.denied', AuditResult::Denied, 'user', $operator->id, ['user' => $username]);

            throw new DomainException('Només un admin pot canviar els avisos d\'un altre operador.');
        }

        return $this->users->find($username);
    }

    /**
     * @throws DomainException
     */
    public function subscribe(AuditActor $actor, User $user, string $destinationName, NotificationThreshold $min): NotificationSubscription
    {
        $destination = $this->destination($destinationName);
        $subscription = NotificationSubscription::query()->firstOrNew(['user_id' => $user->id, 'notification_destination_id' => $destination->id]);
        if ($subscription->exists && $subscription->min_severity === $min) {
            return $subscription;
        }
        $subscription->min_severity = $min;
        $subscription->save();
        $this->audit->record($actor, 'notification.subscription.set', AuditResult::Success, 'notification_subscription', $subscription->id, [
            'user' => $user->unix_username,
            'destination' => $destination->name,
            'min_severity' => $min->value,
        ]);

        return $subscription;
    }

    /**
     * @throws DomainException
     */
    public function unsubscribe(AuditActor $actor, User $user, string $destinationName): bool
    {
        $destination = $this->destination($destinationName);
        $deleted = NotificationSubscription::query()->where('user_id', $user->id)->where('notification_destination_id', $destination->id)->delete() > 0;
        if ($deleted) {
            $this->audit->record($actor, 'notification.subscription.removed', AuditResult::Success, 'notification_destination', $destination->id, [
                'user' => $user->unix_username,
                'destination' => $destination->name,
            ]);
        }

        return $deleted;
    }

    /**
     * @throws DomainException
     */
    public function destination(string $name): NotificationDestination
    {
        return NotificationDestination::query()->where('name', $name)->first()
            ?? throw new DomainException("No hi ha cap destinació d'avisos «{$name}».");
    }
}
