<?php

declare(strict_types=1);

namespace App\Domain\Backups;

use App\Domain\Drivers\Data\RemoteFile;
use App\Domain\Drivers\Data\WorkArea;
use App\Domain\Drivers\Data\WorkFile;
use App\Domain\Drivers\Exceptions\DriverException;
use App\Domain\Drivers\Exceptions\WorkFileNotFound;
use App\Domain\Drivers\SiteDriver;
use App\Domain\Drivers\WorkPath;
use App\Domain\Secrets\SecretRedactor;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\HttpClientException;
use Illuminate\Support\Facades\Log;

/**
 * Restore points on the site's own server (ADR-0009, UZEROWPUP-40): under
 * `{root}/` in the tool's work directory of the site (`~/.wpup`), outside the
 * docroot. Directories are 0700 and files 0600. A rollback reads them in
 * place, without crossing the network.
 *
 * Everything goes through the site's SiteDriver (work-area methods), so it
 * works the same over SSH or the agent.
 *
 * - Before the first write of each instance (one per run), it makes sure the
 *   web does not serve the store: the work directory must not be inside the
 *   WordPress root, and a random canary written to the store must not come
 *   back from any URL where the web could serve it (see canaryUrls()). A site
 *   that does not answer over HTTP does not block it (logged).
 * - put() copies the stream to a private temporary file of the staging
 *   directory, uploads it (checksum verified) under a temporary name of the
 *   work directory, and moves it into place with moveWorkFile(), which never
 *   overwrites: a failed upload never leaves a truncated object under the key.
 * - adopt() is the usual path: a dump or archive already generated in the work
 *   directory enters the store without any transfer, after its SHA-256 is
 *   checked on the site.
 * - Storing the same bytes again under the same key is idempotent; different
 *   bytes are refused (alreadyExists).
 * - Every write first removes this target's own stale temporary files
 *   (purgeStale()).
 */
final class SiteLocalTarget implements BackupTarget
{
    /** Temporary uploads in the work directory ("…part.part" while rsync writes). */
    private const string TEMP_PATTERN = '#^store-[0-9a-f]{32}\.part(\.part)?$#D';

    private const string CANARY_PATTERN = '#^wpup-canary-[0-9a-f]{32}\.txt$#D';

    private bool $outsideWebRoot = false;

    public function __construct(
        private readonly SiteDriver $driver,
        private readonly SecretRedactor $redactor,
        private readonly HttpFactory $http,
        private readonly SiteLocalSettings $settings,
        private readonly string $siteUrl,
    ) {
        if (! WorkPath::isValid($settings->root)) {
            throw BackupTargetException::invalidKey($settings->root);
        }
    }

    public function put(string $key, $stream): StoredObject
    {
        BackupKey::valid($key);
        if (! is_resource($stream)) {
            throw BackupTargetException::notAStream();
        }

        try {
            // The cheap checks first: nothing is copied for a write that cannot happen.
            $this->prepareWrite();
            $existing = $this->driver->statWorkFile($this->path($key));
            if ($existing !== null) {
                return $this->same($key, $existing, $this->hashStream($stream, $key));
            }

            [$local, $sha256] = $this->localCopy($stream, $key);
            $partial = null;

            try {
                $partial = $this->driver->upload($local, self::tempName())->path;
                $stored = $this->publish($partial, $key, $sha256);
                $partial = null;

                return $stored;
            } finally {
                @unlink($local);
                if ($partial !== null) {
                    $this->discard($partial);
                }
            }
        } catch (DriverException $e) {
            throw BackupTargetException::failed("L'escriptura", $key, $this->reason($e));
        }
    }

    /**
     * Moves a file generated in the work directory (DatabaseBackup,
     * FilesBackup) into the store under $key, without transferring it. Its size
     * and SHA-256 are checked on the site first. Afterwards $file no longer
     * exists at its old path: the object is read through this target.
     *
     * @throws BackupTargetException
     */
    public function adopt(RemoteFile $file, string $key): StoredObject
    {
        BackupKey::valid($key);

        try {
            $this->prepareWrite();
            $existing = $this->driver->statWorkFile($this->path($key));
            if ($existing !== null) {
                $stored = $this->same($key, $existing, $file->sha256);
                $this->removeIfSame($file->path, $file->sha256);

                return $this->withSha($stored, $file->sha256);
            }

            $actual = $this->driver->statWorkFile($file->path, true)
                ?? throw new BackupTargetException("El fitxer «{$file->path}» no existeix al lloc: no es pot desar com a «{$key}».");
            if ($actual->sha256 !== $file->sha256 || $actual->sizeBytes !== $file->sizeBytes) {
                throw new BackupTargetException("El fitxer «{$file->path}» no coincideix amb el checksum esperat: no es desa com a «{$key}».");
            }

            return $this->withSha($this->publish($file->path, $key, $file->sha256), $file->sha256);
        } catch (DriverException $e) {
            throw BackupTargetException::failed('El desament', $key, $this->reason($e));
        }
    }

    /**
     * The stored object as a file of the site's work area, to restore it in
     * place (UZEROWPUP-46): nothing is copied or transferred. The driver checks
     * its size and SHA-256 on the site right before using it.
     */
    public function workFile(string $key, int $sizeBytes, string $sha256): RemoteFile
    {
        return new RemoteFile($this->path(BackupKey::valid($key)), $sizeBytes, $sha256);
    }

    public function readStream(string $key)
    {
        BackupKey::valid($key);

        try {
            return $this->driver->openWorkFile($this->path($key));
        } catch (WorkFileNotFound) {
            throw BackupTargetException::notFound($key);
        } catch (DriverException $e) {
            throw BackupTargetException::failed('La lectura', $key, $this->reason($e));
        }
    }

    public function exists(string $key): bool
    {
        BackupKey::valid($key);

        try {
            return $this->driver->statWorkFile($this->path($key)) !== null;
        } catch (DriverException $e) {
            throw BackupTargetException::failed('La comprovació', $key, $this->reason($e));
        }
    }

    /**
     * Computed on the site from the stored bytes (sha256sum), never downloaded.
     */
    public function checksum(string $key): string
    {
        BackupKey::valid($key);

        try {
            $file = $this->driver->statWorkFile($this->path($key), true) ?? throw BackupTargetException::notFound($key);
        } catch (DriverException $e) {
            throw BackupTargetException::failed('El càlcul del checksum', $key, $this->reason($e));
        }

        return $file->sha256 ?? throw BackupTargetException::failed('El càlcul del checksum', $key, 'el lloc no ha tornat cap checksum');
    }

    public function delete(string $key): void
    {
        BackupKey::valid($key);

        try {
            $this->driver->removeWorkFile($this->path($key));
        } catch (DriverException $e) {
            throw BackupTargetException::failed("L'esborrat", $key, $this->reason($e));
        }
    }

    public function list(string $prefix = ''): array
    {
        BackupKey::validPrefix($prefix);
        $directory = $prefix === '' ? $this->settings->root : $this->settings->root.'/'.substr($prefix, 0, (int) strrpos($prefix, '/'));

        try {
            $files = $this->driver->listWorkFiles($directory);
        } catch (DriverException $e) {
            throw BackupTargetException::failed('El llistat', $prefix === '' ? '/' : $prefix, $this->reason($e));
        }

        $objects = [];
        foreach ($files as $file) {
            $key = substr($file->path, strlen($this->settings->root) + 1);
            if (str_starts_with($key, $prefix) && BackupKey::isValid($key) && preg_match(self::CANARY_PATTERN, $key) !== 1) {
                $objects[] = $this->object($key, $file);
            }
        }
        usort($objects, static fn (StoredObject $a, StoredObject $b): int => strcmp($a->key, $b->key));

        return $objects;
    }

    /**
     * Removes this target's own temporary files older than $olderThanSeconds
     * that a crash left behind: uploads under the work directory
     * ("store-….part"), temporary files of a restore (SiteDriver::RESTORE_TEMP_PATTERN)
     * and canaries in the store. Nothing else is touched.
     * Returns how many were removed.
     *
     * @throws BackupTargetException
     */
    public function purgeStale(int $olderThanSeconds): int
    {
        $cutoff = time() - $olderThanSeconds;
        $removed = 0;

        try {
            foreach ($this->driver->listWorkFiles('') as $file) {
                $canary = str_starts_with($file->path, $this->settings->root.'/')
                    && preg_match(self::CANARY_PATTERN, substr($file->path, strlen($this->settings->root) + 1)) === 1;
                $temporary = preg_match(self::TEMP_PATTERN, $file->path) === 1 || preg_match(SiteDriver::RESTORE_TEMP_PATTERN, $file->path) === 1;
                if (($canary || $temporary) && $file->modifiedAt !== null && $file->modifiedAt < $cutoff) {
                    $this->driver->removeWorkFile($file->path);
                    $removed++;
                }
            }
        } catch (DriverException $e) {
            throw BackupTargetException::failed('La neteja dels temporals', $this->settings->root.'/', $this->reason($e));
        }

        return $removed;
    }

    /**
     * Fails unless this target may write: the web must not serve the store.
     * Callers check it before generating anything big for it (a dump, an archive).
     *
     * @throws BackupTargetException
     */
    public function assertWritable(): void
    {
        try {
            $this->prepareWrite();
        } catch (DriverException $e) {
            throw BackupTargetException::failed('La preparació', $this->settings->root.'/', $this->reason($e));
        }
    }

    /**
     * @throws DriverException
     */
    private function prepareWrite(): void
    {
        $this->purgeStale($this->settings->staleAfterSeconds);
        $this->ensureOutsideWebRoot();
    }

    /**
     * Moves $from into the store. If the move reports a failure, the outcome is
     * checked: the object may be in place already (a step after the link
     * failed, or a retry of the same write), which counts as done only when it
     * holds the expected bytes.
     *
     * @throws DriverException
     */
    private function publish(string $from, string $key, string $sha256): StoredObject
    {
        try {
            return $this->object($key, $this->driver->moveWorkFile($from, $this->path($key)));
        } catch (DriverException $e) {
            try {
                $target = $this->driver->statWorkFile($this->path($key), true);
                $source = $target === null ? null : $this->driver->statWorkFile($from, true);
            } catch (DriverException $checkFailed) {
                Log::warning('Moving a file into the site-local store failed, and checking the result failed too.', [
                    'key' => $key,
                    'reason' => $this->reason($e),
                ]);

                throw $checkFailed;
            }

            if ($target === null) {
                throw $e;
            }
            if ($target->sha256 !== $sha256 || ($source !== null && $source->sha256 !== $sha256)) {
                throw BackupTargetException::alreadyExists($key);
            }
            if ($source !== null) {
                $this->discard($from);
            }

            return $this->object($key, $target);
        }
    }

    /**
     * An object already stored under $key: the same bytes make the write a
     * no-op (a retry); others are never replaced.
     *
     * @throws DriverException
     */
    private function same(string $key, WorkFile $existing, string $sha256): StoredObject
    {
        if ($this->driver->statWorkFile($this->path($key), true)?->sha256 !== $sha256) {
            throw BackupTargetException::alreadyExists($key);
        }

        return $this->object($key, $existing);
    }

    /**
     * @throws DriverException
     */
    private function removeIfSame(string $path, string $sha256): void
    {
        if ($this->driver->statWorkFile($path, true)?->sha256 === $sha256) {
            $this->driver->removeWorkFile($path);
        }
    }

    /**
     * The web must not serve the store. Checked once per instance, before the
     * first write.
     *
     * @throws DriverException
     */
    private function ensureOutsideWebRoot(): void
    {
        if ($this->outsideWebRoot) {
            return;
        }
        if ($this->driver->workDirExclude() !== null) {
            throw new BackupTargetException("El directori de treball de l'eina és dins de l'arrel de WordPress: els punts de restauració serien accessibles pel web i no s'hi desen.");
        }

        $canary = $this->settings->root.'/wpup-canary-'.bin2hex(random_bytes(16)).'.txt';
        $urls = $this->canaryUrls($this->driver->workArea(), $this->driver->archiveRoot(), $canary);
        if ($urls !== []) {
            $served = $this->servedCanary($canary, $urls);
            if ($served !== null) {
                throw new BackupTargetException("El web serveix el magatzem de punts de restauració ({$served}): no s'hi desa res. Cal que el directori de treball de l'eina sigui fora del docroot.");
            }
        }

        $this->outsideWebRoot = true;
    }

    /**
     * Where the web could serve $canary: for each directory A from the
     * WordPress root up to the account's home (both included) that contains
     * the work directory, the site's URL path cut to A's level (one segment per
     * level up; "/" when there are no more) followed by the canary's path
     * relative to A. Physical paths on both sides.
     *
     * @return list<string>
     */
    private function canaryUrls(WorkArea $area, string $wpRoot, string $canary): array
    {
        $url = parse_url($this->siteUrl);
        if (! is_array($url) || ! isset($url['scheme'], $url['host'])) {
            Log::warning('The site URL cannot be parsed: the site-local store is not checked over HTTP.', ['url' => $this->siteUrl]);

            return [];
        }
        $origin = $url['scheme'].'://'.$url['host'].(isset($url['port']) ? ':'.$url['port'] : '');
        $segments = array_values(array_filter(explode('/', $url['path'] ?? ''), static fn (string $s): bool => $s !== ''));

        $home = rtrim($area->home, '/');
        $work = rtrim($area->workDir, '/');
        if ($home === '') {
            return [];
        }
        $urls = [];
        for ($dir = rtrim($wpRoot, '/'), $up = 0; $dir === $home || str_starts_with($dir, $home.'/'); $dir = dirname($dir), $up++) {
            if (str_starts_with($work, $dir.'/')) {
                $path = [...array_slice($segments, 0, max(0, count($segments) - $up)), ...explode('/', substr($work, strlen($dir) + 1).'/'.$canary)];
                $urls[] = $origin.'/'.implode('/', array_map(rawurlencode(...), $path));
            }
            if ($dir === $home) {
                break;
            }
        }

        return array_values(array_unique($urls));
    }

    /**
     * Writes a random canary to $canary, requests $urls (no redirects) and
     * returns the first one that answers 2xx with its contents. Always removes
     * the canary.
     *
     * @param  list<string>  $urls
     *
     * @throws DriverException
     */
    private function servedCanary(string $canary, array $urls): ?string
    {
        $secret = bin2hex(random_bytes(32));
        $local = $this->stagingFile($canary);
        file_put_contents($local, $secret);
        $partial = null;

        try {
            $partial = $this->driver->upload($local, self::tempName())->path;
            $this->driver->moveWorkFile($partial, $canary);
            $partial = null;

            foreach ($urls as $url) {
                try {
                    $response = $this->http->withoutRedirecting()->timeout($this->settings->canaryTimeoutSeconds)
                        ->withOptions(['verify' => $this->settings->verifyTls])->get($url);
                } catch (HttpClientException $e) {
                    Log::warning('The site did not answer the site-local store check over HTTP; not blocking.', ['url' => $url, 'reason' => $this->redactor->redact($e->getMessage())]);

                    continue;
                }
                if ($response->successful() && str_contains($response->body(), $secret)) {
                    return $url;
                }
            }

            return null;
        } finally {
            @unlink($local);
            if ($partial !== null) {
                $this->discard($partial);
            }
            try {
                $this->driver->removeWorkFile($canary);
            } catch (DriverException $e) {
                // purgeStale() removes it on a later write.
                Log::warning('Could not remove the site-local store canary.', ['path' => $canary, 'reason' => $this->reason($e)]);
            }
        }
    }

    /**
     * SHA-256 of a stream (rewound when seekable), read in chunks.
     *
     * @param  resource  $stream
     */
    private function hashStream($stream, string $key): string
    {
        if (stream_get_meta_data($stream)['seekable']) {
            rewind($stream);
        }
        $hash = hash_init('sha256');
        while (! feof($stream)) {
            $chunk = fread($stream, max(1, $this->settings->copyChunkBytes));
            if ($chunk === false) {
                throw BackupTargetException::failed("L'escriptura", $key, 'lectura interrompuda');
            }
            hash_update($hash, $chunk);
        }

        return hash_final($hash);
    }

    /**
     * @param  resource  $stream
     * @return array{string, string}
     */
    private function localCopy($stream, string $key): array
    {
        return StagingCopy::copy($stream, $this->settings->stagingDir, $this->settings->copyChunkBytes, "L'escriptura", $key);
    }

    private function stagingFile(string $what): string
    {
        return StagingCopy::path($this->settings->stagingDir, 'put', "L'escriptura", $what);
    }

    private static function tempName(): string
    {
        // A plain name of the work directory, outside the store: never listed.
        return 'store-'.bin2hex(random_bytes(16)).'.part';
    }

    private function path(string $key): string
    {
        return $this->settings->root.'/'.$key;
    }

    private function withSha(StoredObject $object, string $sha256): StoredObject
    {
        return new StoredObject($object->key, $object->sizeBytes, $object->modifiedAt, $sha256);
    }

    private function object(string $key, WorkFile $file): StoredObject
    {
        return new StoredObject($key, $file->sizeBytes, $file->modifiedAt === null ? null : CarbonImmutable::createFromTimestamp($file->modifiedAt));
    }

    /**
     * Driver messages may carry hosts or paths of the account: masked first.
     */
    private function reason(DriverException $e): string
    {
        return $this->redactor->redact($e->getMessage());
    }

    private function discard(string $partial): void
    {
        try {
            $this->driver->removeWorkFile($partial);
        } catch (DriverException $e) {
            // Outside the store (never listed); purgeStale() removes it later.
            Log::warning('Could not remove a temporary file of the site-local store.', ['path' => $partial, 'reason' => $this->reason($e)]);
        }
    }
}
