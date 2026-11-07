<?php

declare(strict_types=1);

namespace App\Domain\Notifications;

use App\Domain\Audit\AuditActor;
use App\Domain\Audit\AuditLogger;
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
 * address masked.
 */
final readonly class NotificationAdministration
{
    public const string SECRET_PURPOSE = 'notification-channel';

    private const string NAME_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/D';

    private const int ADDRESS_MAX = 500;

    public function __construct(
        private NotificationChannels $channels,
        private SecretStore $secrets,
        private AuditLogger $audit,
    ) {}

    /**
     * Validates a destination without the secret. Returns the existing one, if any.
     *
     * @throws DomainException
     */
    public function checkDestination(string $name, ?string $channel, ?string $address): ?NotificationDestination
    {
        if (preg_match(self::NAME_PATTERN, $name) !== 1) {
            throw new DomainException('El nom ha de tenir lletres, xifres, «.», «_» o «-» (màxim 64 caràcters).');
        }
        $existing = NotificationDestination::query()->where('name', $name)->first();
        $channel ??= $existing?->channel;
        if ($channel === null) {
            throw new DomainException('Cal indicar el canal amb --channel (opcions: '.implode(', ', $this->configurable()).').');
        }
        if ($existing !== null && $existing->channel !== $channel) {
            throw new DomainException("La destinació «{$name}» és del canal {$existing->channel}; no se'n pot canviar el canal.");
        }
        if (! in_array($channel, $this->configurable(), true)) {
            throw new DomainException("Canal d'avís desconegut «{$channel}» (opcions: ".implode(', ', $this->configurable()).').');
        }
        if ($address !== null && (mb_strlen($address) > self::ADDRESS_MAX || preg_match('/[\x00-\x1F\x7F]/', $address) === 1)) {
            throw new DomainException('L\'adreça no pot tenir caràcters de control ni passar de '.self::ADDRESS_MAX.' caràcters.');
        }
        if ($this->channels->needsAddress($channel) && ($address ?? $existing?->address) === null) {
            throw new DomainException("El canal {$channel} necessita una adreça (--address).");
        }

        return $existing;
    }

    public function needsSecret(string $channel): bool
    {
        return $this->channels->needsSecret($channel);
    }

    /**
     * Creates or updates a destination. A null secret keeps the stored one;
     * a new destination of a channel with secrets needs it.
     *
     * @throws DomainException
     */
    public function setDestination(AuditActor $actor, string $name, ?string $channel, ?string $address, #[\SensitiveParameter] ?Secret $secret, ?bool $active): NotificationDestination
    {
        $existing = $this->checkDestination($name, $channel, $address);
        $channel = $existing->channel ?? (string) $channel;
        if ($existing === null && $secret === null && $this->channels->needsSecret($channel)) {
            throw new DomainException("El canal {$channel} necessita un token o webhook. No s'ha desat res.");
        }

        return DB::transaction(function () use ($actor, $name, $channel, $address, $secret, $active, $existing): NotificationDestination {
            $destination = $existing ?? new NotificationDestination(['name' => $name, 'channel' => $channel]);
            if ($address !== null) {
                $destination->address = $address;
            }
            if ($active !== null) {
                $destination->active = $active;
            }
            $rotated = false;
            if ($secret !== null && $this->channels->needsSecret($channel)) {
                $ref = $destination->credential_ref;
                if ($ref !== null && $this->secrets->purpose($ref) === self::SECRET_PURPOSE) {
                    $this->secrets->replace($ref, $secret, $actor);
                } else {
                    $destination->credential_ref = $this->secrets->put(self::SECRET_PURPOSE, $secret, $actor);
                }
                $rotated = true;
            }
            $destination->save();

            $this->audit->record($actor, 'notification.destination.set', AuditResult::Success, 'notification_destination', $destination->id, [
                'name' => $name,
                'channel' => $channel,
                'address' => $destination->address === null ? null : $destination->maskedAddress(),
                'active' => $destination->active,
                'created' => $existing === null,
                'secret_set' => $rotated,
            ]);

            return $destination;
        });
    }

    /**
     * @throws DomainException
     */
    public function subscribe(AuditActor $actor, User $user, string $destinationName, NotificationThreshold $min): NotificationSubscription
    {
        $destination = $this->destination($destinationName);
        $subscription = NotificationSubscription::query()->updateOrCreate(
            ['user_id' => $user->id, 'notification_destination_id' => $destination->id],
            ['min_severity' => $min],
        );
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

    /**
     * Channels a destination can be created for: all but the built-in log.
     *
     * @return list<string>
     */
    public function configurable(): array
    {
        return array_values(array_diff($this->channels->names(), ['log']));
    }
}
