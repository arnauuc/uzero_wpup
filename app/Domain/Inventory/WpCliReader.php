<?php

declare(strict_types=1);

namespace App\Domain\Inventory;

use App\Domain\Drivers\Data\CommandResult;
use App\Domain\Drivers\Exceptions\ConnectionFailed;
use App\Domain\Drivers\Exceptions\DriverException;
use App\Domain\Drivers\SiteDriver;
use App\Domain\Secrets\SecretRedactor;

/**
 * Shared plumbing of the inventory readers (PendingUpdates, SiteState): run
 * WP-CLI turning a failed command into a redacted warning (a lost connection
 * still propagates) and parse what it printed.
 */
final readonly class WpCliReader
{
    public function __construct(private SecretRedactor $redactor) {}

    /**
     * @param  list<string>  $args
     * @param  list<string>  $warnings  gets the warning when the command could not run
     *
     * @throws ConnectionFailed
     */
    public function run(SiteDriver $driver, array $args, int $timeoutSeconds, string $what, array &$warnings): ?CommandResult
    {
        try {
            return $driver->wp($args, $timeoutSeconds);
        } catch (ConnectionFailed $e) {
            throw $e;
        } catch (DriverException $e) {
            $warnings[] = $this->redact("No s'ha pogut llegir {$what}: {$e->getMessage()}");

            return null;
        }
    }

    public function redact(string $text): string
    {
        return $this->redactor->redact($text);
    }

    /**
     * Last non-empty line of a command's output: plugins may print notices
     * before the answer.
     */
    public function lastLine(string $stdout): ?string
    {
        $lines = array_values(array_filter(array_map('trim', explode("\n", $stdout)), static fn (string $l): bool => $l !== ''));

        return $lines === [] ? null : $lines[count($lines) - 1];
    }

    /**
     * @return array<mixed>|null
     */
    public function json(string $stdout): ?array
    {
        $line = $this->lastLine($stdout);
        $data = $line === null ? null : json_decode($line, true);

        return is_array($data) ? $data : null;
    }

    public function text(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
