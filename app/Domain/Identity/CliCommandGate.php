<?php

declare(strict_types=1);

namespace App\Domain\Identity;

/**
 * Decides which commands the `wpup` binary may run (defence in depth: the bash
 * wrapper checks the same list first). Internal artisan commands such as tinker,
 * migrate or key:generate would let an operator act as the service user and read
 * the KEK, so they are never reachable through `wpup`.
 */
final readonly class CliCommandGate
{
    /**
     * @param  list<string>  $allowed  exact names, or namespaces ending in ":"
     */
    public function __construct(private array $allowed) {}

    public function allows(?string $command): bool
    {
        if ($command === null || $command === '') {
            return true; // bare `wpup` lists the commands
        }

        foreach ($this->allowed as $entry) {
            if ($entry === $command) {
                return true;
            }

            if (str_ends_with($entry, ':') && str_starts_with($command, $entry)) {
                return true;
            }
        }

        return false;
    }
}
