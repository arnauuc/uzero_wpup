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
    /** The built-in channel of the tool's log. */
    public const string LOG = 'log';

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

    /**
     * Channels a site or a destination can name: all but the built-in log,
     * which always gets every notification.
     *
     * @return list<string>
     */
    public function configurable(): array
    {
        return array_values(array_diff($this->names(), [self::LOG]));
    }

    /**
     * The channels to choose from, for the operator (Catalan).
     */
    public function available(): string
    {
        $options = $this->configurable();

        return $options === []
            ? "no hi ha cap canal configurat a part del log de l'eina (wpup.notifications.channels)"
            : 'opcions: '.implode(', ', $options);
    }

    /**
     * Operator message (Catalan) for a channel that is not configured.
     */
    public function unknownMessage(string $name): string
    {
        return "Canal d'avís desconegut «{$name}» ({$this->available()}).";
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
        return ($this->channels[$name] ?? null)['class'] ?? throw new DomainException($this->unknownMessage($name));
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
