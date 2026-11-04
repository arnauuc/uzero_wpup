<?php

declare(strict_types=1);

namespace App\Domain\Inventory;

use App\Domain\Drivers\Exceptions\ConnectionFailed;
use App\Domain\Drivers\Exceptions\DriverException;
use App\Domain\Drivers\SiteDriver;
use App\Enums\PrerequisiteName;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * Checks what a site needs before the tool may update it (UZEROWPUP-34): a
 * supported PHP, a verified PHP, room for the backup, WP-CLI and write access.
 * Read only. A failed check blocks the site (see SiteBlocking).
 *
 * - php_minimum: the snapshot's PHP (the web's, when the HTTP probe answered)
 *   against min_site_php (ADR-0007).
 * - php_verified: environment.php.blocked_reason, set when the web's PHP could
 *   not be confirmed or differs from WP-CLI's.
 * - backup_space: (database + files) x factor against the free disk. The
 *   database is the sum of the tables with the site's prefix, as the dump
 *   takes them (a database may be shared); the files come from the driver. If
 *   anything cannot be measured it fails: we never update without a verified
 *   backup.
 * - wp_cli: reaching this point means the inventory ran WP-CLI.
 * - writable: WordPress root, wp-content, plugins and themes.
 */
final readonly class SitePrerequisites
{
    /** ADR-0007: configurable, but never below the agent's syntax. */
    public const string PHP_FLOOR = '7.4';

    /**
     * Stable reasons (no live figures): SiteBlocking compares them to know
     * whether the block changed, so a number that moves every inventory would
     * audit and notify every time. Figures go in PrerequisiteCheck::$value.
     */
    public const string REASON_SPACE_UNKNOWN = "no s'ha pogut estimar l'espai per a la còpia";

    public const string REASON_SPACE_SHORT = 'espai insuficient per a la còpia';

    public const string REASON_WRITE_UNKNOWN = "no s'han pogut comprovar els permisos d'escriptura";

    public const string REASON_WRITE_DENIED = 'el compte no pot escriure a: ';

    public const string REASON_WP_CLI = 'WP-CLI no funciona al lloc';

    public const string REASON_INCOMPLETE = 'prerequisits incomplets';

    public function __construct(
        private WpCliReader $reader,
        private string $minPhp,
        private float $backupSpaceFactor,
        private int $commandTimeoutSeconds,
        private int $duTimeoutSeconds = 180,
    ) {
        self::assertMinimumPhp($minPhp);
    }

    /**
     * @throws InvalidArgumentException when the minimum is not a PHP version or is below 7.4
     */
    public static function assertMinimumPhp(mixed $minPhp): void
    {
        if (! is_string($minPhp) || preg_match('/^\d+\.\d+(\.\d+)?$/', $minPhp) !== 1 || version_compare($minPhp, self::PHP_FLOOR, '<')) {
            throw new InvalidArgumentException('wpup.min_site_php ha de ser una versió de PHP igual o superior a '.self::PHP_FLOOR.' (ADR-0007), no «'.(is_string($minPhp) ? $minPhp : get_debug_type($minPhp)).'».');
        }
    }

    /**
     * @param  string  $phpVersion  the site's PHP as recorded in the snapshot
     * @param  array<string, mixed>  $phpEnvironment  the snapshot's environment.php
     * @param  int|null  $diskFreeBytes  free disk already read by SiteState, if it could
     *
     * @throws ConnectionFailed
     */
    public function check(SiteDriver $driver, string $phpVersion, array $phpEnvironment, ?int $diskFreeBytes): PrerequisitesResult
    {
        $unverified = $phpEnvironment['blocked_reason'] ?? null;
        $warnings = [];

        return new PrerequisitesResult([
            $this->phpMinimum($phpVersion),
            is_string($unverified) && $unverified !== ''
                ? new PrerequisiteCheck(PrerequisiteName::PhpVerified, false, $unverified)
                : new PrerequisiteCheck(PrerequisiteName::PhpVerified, true),
            $this->backupSpace($driver, $diskFreeBytes, $warnings),
            new PrerequisiteCheck(PrerequisiteName::WpCli, true),
            $this->writable($driver, $warnings),
        ], $warnings);
    }

    /**
     * A DriverException that fails a check is never swallowed silently: it is
     * logged (redacted) and kept as a warning of the snapshot.
     *
     * @param  list<string>  $warnings
     */
    private function warn(array &$warnings, string $what, DriverException $e): void
    {
        $message = $this->reader->redact("No s'ha pogut comprovar {$what}: {$e->getMessage()}");
        $warnings[] = $message;
        Log::warning($message);
    }

    private function phpMinimum(string $version): PrerequisiteCheck
    {
        return version_compare($version, $this->minPhp, '>=')
            ? new PrerequisiteCheck(PrerequisiteName::PhpMinimum, true, null, $version)
            : new PrerequisiteCheck(PrerequisiteName::PhpMinimum, false, "PHP < {$this->minPhp}: no suportat", $version);
    }

    /**
     * @param  list<string>  $warnings
     */
    private function backupSpace(SiteDriver $driver, ?int $diskFreeBytes, array &$warnings): PrerequisiteCheck
    {
        $unknown = new PrerequisiteCheck(PrerequisiteName::BackupSpace, false, self::REASON_SPACE_UNKNOWN);

        try {
            $database = $this->databaseBytes($driver, $warnings);
            $files = $driver->diskUsageBytes($this->duTimeoutSeconds);
            $free = $diskFreeBytes ?? $driver->freeDiskBytes();
        } catch (ConnectionFailed $e) {
            throw $e;
        } catch (DriverException $e) {
            $this->warn($warnings, "l'espai per a la còpia", $e);

            return $unknown;
        }
        if ($database === null) {
            return $unknown;
        }

        $needed = (int) ceil(($database + $files) * $this->backupSpaceFactor);

        return $free >= $needed
            ? new PrerequisiteCheck(PrerequisiteName::BackupSpace, true, null, $needed)
            : new PrerequisiteCheck(PrerequisiteName::BackupSpace, false, self::REASON_SPACE_SHORT, $needed);
    }

    /**
     * Sum of the tables with the site's prefix; null if WP-CLI could not tell.
     *
     * @param  list<string>  $warnings
     *
     * @throws ConnectionFailed
     */
    private function databaseBytes(SiteDriver $driver, array &$warnings): ?int
    {
        $result = $this->reader->run($driver, ['db', 'size', '--size_format=b', '--all-tables-with-prefix', '--format=json'], $this->commandTimeoutSeconds, 'la mida de la base de dades', $warnings);
        if ($result === null) {
            return null;
        }
        if (! $result->successful()) {
            $warnings[] = $this->reader->redact("No s'ha pogut llegir la mida de la base de dades (codi {$result->exitCode}): ".trim($result->stderr));

            return null;
        }

        $rows = $this->reader->json($result->stdout);
        if ($rows === null || $rows === []) {
            return null;
        }

        $total = 0;
        foreach ($rows as $row) {
            $size = is_array($row) && is_string($row['Size'] ?? null) ? $row['Size'] : null;
            if ($size === null || preg_match('/^(\d+)\s*B?$/', $size, $m) !== 1) {
                return null;
            }
            $total += (int) $m[1];
        }

        return $total;
    }

    /**
     * @param  list<string>  $warnings
     */
    private function writable(SiteDriver $driver, array &$warnings): PrerequisiteCheck
    {
        try {
            $paths = $driver->unwritablePaths();
        } catch (ConnectionFailed $e) {
            throw $e;
        } catch (DriverException $e) {
            $this->warn($warnings, "els permisos d'escriptura", $e);

            return new PrerequisiteCheck(PrerequisiteName::Writable, false, self::REASON_WRITE_UNKNOWN);
        }

        return $paths === []
            ? new PrerequisiteCheck(PrerequisiteName::Writable, true)
            : new PrerequisiteCheck(PrerequisiteName::Writable, false, self::REASON_WRITE_DENIED.implode(', ', $paths));
    }
}
