<?php

declare(strict_types=1);

namespace App\Console\Commands\Notifications;

use App\Console\WpupCommand;
use App\Domain\Identity\Ability;
use App\Models\NotificationDestination;
use App\Models\NotificationSubscription;

/**
 * The notification destinations (address masked, never the secret) and who
 * follows each one (UZEROWPUP-79).
 */
final class NotifyListCommand extends WpupCommand
{
    protected $signature = 'notify:list '.self::JSON;

    protected $description = 'Llista les destinacions d\'avisos i qui les segueix';

    protected function ability(): Ability
    {
        return Ability::ViewReports;
    }

    protected function perform(): int
    {
        $rows = NotificationDestination::query()->with('subscriptions.user')->orderBy('name')->get()
            ->map(static fn (NotificationDestination $d): array => [
                'name' => $d->name,
                'channel' => $d->channel,
                'address' => $d->address === null ? null : $d->maskedAddress(),
                'active' => $d->active,
                'secret' => $d->credential_ref !== null,
                'subscribers' => $d->subscriptions->map(static fn (NotificationSubscription $s): array => [
                    'user' => $s->user->unix_username,
                    'min_severity' => $s->min_severity->value,
                ])->values()->all(),
            ])->values()->all();

        if ($this->wantsJson()) {
            $this->emitJson(['destinations' => $rows]);

            return self::SUCCESS;
        }
        $this->line('Tots els avisos van també al log de l\'eina.');
        $this->table(['Destinació', 'Canal', 'Adreça', 'Activa', 'Secret', 'Qui la segueix'], array_map(static fn (array $r): array => [
            $r['name'],
            $r['channel'],
            $r['address'] ?? '—',
            $r['active'] ? 'sí' : 'no',
            $r['secret'] ? 'desat' : '—',
            implode(', ', array_map(static fn (array $s): string => "{$s['user']} ({$s['min_severity']})", $r['subscribers'])) ?: '—',
        ], $rows));

        return self::SUCCESS;
    }
}
