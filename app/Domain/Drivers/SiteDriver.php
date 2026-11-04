<?php

declare(strict_types=1);

namespace App\Domain\Drivers;

use App\Domain\Drivers\Data\CommandResult;
use App\Domain\Drivers\Data\InstalledComponents;
use App\Domain\Drivers\Data\LogCursor;
use App\Domain\Drivers\Data\PhpRuntime;
use App\Domain\Drivers\Data\ProbeResult;
use App\Domain\Drivers\Data\RemoteFile;
use App\Domain\Drivers\Data\UpdatePackage;
use App\Domain\Drivers\Data\WorkFile;
use App\Domain\Drivers\Exceptions\DriverException;

/**
 * The only way the orchestrator talks to a site (AGENTS.md). Implementations:
 * SshDriver (WP-CLI over SSH) and AgentDriver (temporary PHP agent over HTTPS).
 * Domain code never knows which one it uses.
 *
 * Rules every implementation follows:
 * - arguments are always escaped; no shell string is built from untrusted input;
 * - remote paths are relative to the site's working area unless documented;
 * - output returned in CommandResult is raw: callers pass it through the
 *   SecretRedactor before storing it;
 * - long operations may be resumed: calling them again with the same target
 *   path must not duplicate work or corrupt the previous result;
 * - failures throw DriverException (or a subclass) with an operator-readable
 *   message that never contains secrets.
 *
 * All implementations must pass Tests\Contracts\SiteDriverContract.
 */
interface SiteDriver
{
    /**
     * Connectivity and prerequisites, without changing anything (`site:test`).
     */
    public function probe(): ProbeResult;

    /**
     * The PHP that serves the site (the domain's handler where the panel lets
     * the subscription user read it), never just the CLI default when better
     * is known. WP-CLI runs with this binary.
     *
     * @throws DriverException
     */
    public function phpRuntime(): PhpRuntime;

    /**
     * Switches WP-CLI to the installed PHP of version "X.Y" (the one the HTTP
     * probe saw the web serve), when the guess of phpRuntime() was another.
     * Returns the new runtime, or null if that version is not installed.
     *
     * @throws DriverException
     */
    public function adoptWebPhp(string $minor): ?PhpRuntime;

    /**
     * Versions of core, plugins, themes, drop-ins and must-use plugins.
     *
     * @throws DriverException
     */
    public function installedComponents(): InstalledComponents;

    /**
     * Runs WP-CLI with the domain's PHP binary. $args are passed as separate,
     * escaped arguments; `--path` is added by the driver.
     *
     * @param  list<string>  $args
     *
     * @throws DriverException
     */
    public function wp(array $args, ?int $timeoutSeconds = null): CommandResult;

    /**
     * Dumps every table with the site's prefix to $remotePath (gzip).
     *
     * @throws DriverException
     */
    public function dumpDatabase(string $remotePath): RemoteFile;

    /**
     * Every table with the site's prefix (what dumpDatabase() must contain).
     *
     * @return list<string>
     *
     * @throws DriverException
     */
    public function databaseTables(): array;

    /**
     * The tables a dump made by dumpDatabase() contains (its CREATE TABLE
     * statements), read on the site without downloading the file.
     *
     * @return list<string>
     *
     * @throws DriverException including when the dump is not a valid gzip
     */
    public function dumpedTables(RemoteFile $dump): array;

    /**
     * The physical directory (symlinks resolved) archiveFiles() archives: the
     * WordPress root.
     *
     * @throws DriverException
     */
    public function archiveRoot(): string;

    /**
     * The tool's own work directory relative to archiveRoot(), when it lives
     * inside it (it must never be archived); null when it is outside.
     *
     * @throws DriverException
     */
    public function workDirExclude(): ?string;

    /**
     * Archives the site's code (tar.gz) to $remotePath.
     *
     * @param  list<string>  $excludes  paths relative to the WordPress root
     *
     * @throws DriverException
     */
    public function archiveFiles(string $remotePath, array $excludes): RemoteFile;

    /**
     * Copies a remote file to the tool server and verifies its checksum.
     *
     * @throws DriverException
     */
    public function download(RemoteFile $remote, string $localPath): void;

    /**
     * Copies a local file to the site and verifies its checksum.
     *
     * @throws DriverException
     */
    public function upload(string $localPath, string $remotePath): RemoteFile;

    /**
     * Installs a package that was uploaded by the tool (never downloaded by the
     * site). Idempotent: does nothing if the target version is installed.
     *
     * @throws DriverException
     */
    public function applyUpdate(UpdatePackage $package): CommandResult;

    /**
     * @throws DriverException
     */
    public function setMaintenanceMode(bool $enabled): void;

    /**
     * @throws DriverException
     */
    public function restoreDatabase(RemoteFile $dump): void;

    /**
     * @throws DriverException
     */
    public function restoreFiles(RemoteFile $archive): void;

    /**
     * Current end of the PHP error log, to read only new lines later.
     *
     * @throws DriverException
     */
    public function phpLogCursor(): LogCursor;

    /**
     * Lines appended to the PHP error log since $cursor (bounded by $maxBytes).
     *
     * @return list<string>
     *
     * @throws DriverException
     */
    public function phpLogSince(LogCursor $cursor, int $maxBytes = 1_048_576): array;

    /**
     * Free bytes on the site's filesystem (for backup space checks).
     *
     * @throws DriverException
     */
    public function freeDiskBytes(): int;

    /**
     * Bytes used on disk by the WordPress root (code, uploads…), to estimate
     * the size of a file backup (UZEROWPUP-34).
     *
     * @param  int|null  $timeoutSeconds  a big site may need more than the usual command timeout
     *
     * @throws DriverException including when the measure times out
     */
    public function diskUsageBytes(?int $timeoutSeconds = null): int;

    /**
     * The paths of the code the account must be able to write to update and
     * restore it (WordPress root, wp-content, plugins, themes) that it can NOT
     * write to. Empty means all is writable (UZEROWPUP-34).
     *
     * @return list<string>
     *
     * @throws DriverException
     */
    public function unwritablePaths(): array;

    /**
     * Writes a temporary tool file into the WordPress root, where the web
     * serves it (the HTTP probe, UZEROWPUP-30). $name is a bare file name; an
     * existing file is never overwritten.
     *
     * @throws DriverException
     */
    public function putWebFile(string $name, string $contents): void;

    /**
     * Removes a file written by putWebFile(). Does nothing if it is gone.
     *
     * @throws DriverException
     */
    public function removeWebFile(string $name): void;

    /**
     * Removes a file the tool created (backups, uploaded packages).
     *
     * @throws DriverException
     */
    public function remove(string $remotePath): void;

    /*
     * Work area (UZEROWPUP-40): nested paths (WorkPath) inside the tool's work
     * directory, which is outside the docroot and private (directories 0700,
     * files 0600). They hold the restore points of SiteLocalTarget, so that a
     * rollback never crosses the network. Plain names of the methods above are
     * WorkPaths too: a dump made by dumpDatabase() can be moved into the store.
     */

    /**
     * Size and modification time of a regular file (symlinks do not count), and
     * its SHA-256 computed on the site when $withChecksum. Null if it does not
     * exist. Needed for exists() and checksum() without downloading anything.
     *
     * @throws DriverException
     */
    public function statWorkFile(string $path, bool $withChecksum = false): ?WorkFile;

    /**
     * The regular files under $directory, recursively, with their WorkPath
     * relative to the work area. A missing directory has none. Needed to list
     * restore points (retention, UZEROWPUP-44).
     *
     * @return list<WorkFile>
     *
     * @throws DriverException
     */
    public function listWorkFiles(string $directory): array;

    /**
     * Moves a file to $to within the work area, creating the parent directories
     * (0700) and leaving the file 0600. Never overwrites: fails if $to exists,
     * atomically (a hard link, then the source is removed), so a stored object
     * is never replaced and a half-written one is never published. This is how
     * a file already on the site (a dump, an uploaded object) enters the store
     * without being transferred again.
     *
     * @throws DriverException including when $to exists
     */
    public function moveWorkFile(string $from, string $to): WorkFile;

    /**
     * A readable stream with the file's bytes; the caller closes it. Memory is
     * bounded: the bytes are not held whole in memory (the SSH driver copies the
     * file to a private temporary file of the tool server first).
     *
     * @return resource
     *
     * @throws DriverException including when it does not exist
     */
    public function openWorkFile(string $path);

    /**
     * Removes a file of the work area, and the directories it leaves empty.
     * Does nothing if it does not exist.
     *
     * @throws DriverException
     */
    public function removeWorkFile(string $path): void;
}
