<?php

declare(strict_types=1);

namespace App\Domain\Notifications;

use App\Domain\Notifications\Channels\NotificationChannel;
use DomainException;

/**
 * The channels the tool knows (wpup.notifications.channels): for each, its
 * class and whether a destination needs an address and a secret.
 */
final readonly class NotificationChannels
{
    /**
     * @param  array<string, array{class: class-string<NotificationChannel>, address?: bool, secret?: bool}>  $channels
     */
    public function __construct(private array $channels) {}

    /**
     * @return list<string>
     */
    public function names(): array
    {
        return array_keys($this->channels);
    }

    public function has(string $name): bool
    {
        return isset($this->channels[$name]);
    }

    /**
     * @return class-string<NotificationChannel>
     *
     * @throws DomainException
     */
    public function classFor(string $name): string
    {
        return ($this->channels[$name] ?? null)['class'] ?? throw new DomainException("Canal d'avís desconegut «{$name}».");
    }

    public function needsAddress(string $name): bool
    {
        return ($this->channels[$name]['address'] ?? false) === true;
    }

    public function needsSecret(string $name): bool
    {
        return ($this->channels[$name]['secret'] ?? false) === true;
    }
}
