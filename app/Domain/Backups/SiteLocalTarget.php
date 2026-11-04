<?php

declare(strict_types=1);

namespace App\Domain\Backups;

use App\Domain\Drivers\Data\RemoteFile;
use App\Domain\Drivers\Data\WorkFile;
use App\Domain\Drivers\Exceptions\DriverException;
use App\Domain\Drivers\SiteDriver;
use App\Domain\Drivers\WorkPath;
use App\Domain\Secrets\SecretRedactor;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

/**
 * Restore points on the site's own server (ADR-0009, UZEROWPUP-40): under
 * `{root}/` in the tool's work directory of the site (`~/.wpup`), which is
 * outside the docroot and so never served by the web. Directories are 0700 and
 * files 0600. A rollback reads them in place, without crossing the network.
 *
 * Everything goes through the site's SiteDriver (work-area methods), so it
 * works the same over SSH or the agent.
 *
 * - put() copies the stream to a private temporary file of the tool server,
 *   uploads it (checksum verified) under a temporary name of the work
 *   directory, and then moves it into place with moveWorkFile(), which never
 *   overwrites: a failed upload never leaves a truncated object under the key.
 * - adopt() is the usual path: a dump or archive already generated in the work
 *   directory enters the store without any transfer, after its SHA-256 is
 *   checked on the site.
 * - Writes refuse to run when the work directory is inside the WordPress root,
 *   where the web could serve it.
 */
final class SiteLocalTarget implements BackupTarget
{
    private bool $outsideWebRoot = false;

    /**
     * @param  string  $root  WorkPath of the store inside the work directory
     */
    public function __construct(
        private readonly SiteDriver $driver,
        private readonly SecretRedactor $redactor,
        private readonly string $root,
        private readonly int $copyChunkBytes,
    ) {
        try {
            WorkPath::valid($root);
        } catch (DriverException) {
            throw BackupTargetException::invalidKey($root);
        }
    }

    public function put(string $key, $stream): StoredObject
    {
        BackupKey::valid($key);
        if (! is_resource($stream)) {
            throw BackupTargetException::notAStream();
        }

        $local = $this->localCopy($stream, $key);
        $partial = null;

        try {
            $this->ensureOutsideWebRoot();
            if ($this->driver->statWorkFile($this->path($key)) !== null) {
                throw BackupTargetException::alreadyExists($key);
            }

            // A plain name of the work directory, outside the store: never listed.
            $partial = $this->driver->upload($local, 'store-'.bin2hex(random_bytes(16)).'.part')->path;
            $stored = $this->publish($partial, $key);
            $partial = null;

            return $stored;
        } catch (DriverException $e) {
            throw BackupTargetException::failed("L'escriptura", $key, $this->reason($e));
        } finally {
            @unlink($local);
            if ($partial !== null) {
                $this->discard($partial);
            }
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
            $this->ensureOutsideWebRoot();
            if ($this->driver->statWorkFile($this->path($key)) !== null) {
                throw BackupTargetException::alreadyExists($key);
            }

            $actual = $this->driver->statWorkFile($file->path, true)
                ?? throw new BackupTargetException("El fitxer «{$file->path}» no existeix al lloc: no es pot desar com a «{$key}».");
            if ($actual->sha256 !== $file->sha256 || $actual->sizeBytes !== $file->sizeBytes) {
                throw new BackupTargetException("El fitxer «{$file->path}» no coincideix amb el checksum esperat: no es desa com a «{$key}».");
            }

            return $this->publish($file->path, $key);
        } catch (DriverException $e) {
            throw BackupTargetException::failed('El desament', $key, $this->reason($e));
        }
    }

    public function readStream(string $key)
    {
        BackupKey::valid($key);

        try {
            if ($this->driver->statWorkFile($this->path($key)) === null) {
                throw BackupTargetException::notFound($key);
            }

            return $this->driver->openWorkFile($this->path($key));
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
        $directory = $prefix === '' ? $this->root : $this->root.'/'.substr($prefix, 0, (int) strrpos($prefix, '/'));

        try {
            $files = $this->driver->listWorkFiles($directory);
        } catch (DriverException $e) {
            throw BackupTargetException::failed('El llistat', $prefix === '' ? '/' : $prefix, $this->reason($e));
        }

        $objects = [];
        foreach ($files as $file) {
            $key = substr($file->path, strlen($this->root) + 1);
            if (str_starts_with($file->path, $this->root.'/') && str_starts_with($key, $prefix) && self::isKey($key)) {
                $objects[] = $this->object($key, $file);
            }
        }
        usort($objects, static fn (StoredObject $a, StoredObject $b): int => strcmp($a->key, $b->key));

        return $objects;
    }

    private function publish(string $from, string $key): StoredObject
    {
        try {
            return $this->object($key, $this->driver->moveWorkFile($from, $this->path($key)));
        } catch (DriverException $e) {
            // Another writer may have won between the check and the move.
            if ($this->driver->statWorkFile($this->path($key)) !== null) {
                throw BackupTargetException::alreadyExists($key);
            }

            throw $e;
        }
    }

    /**
     * The work directory must not be inside the WordPress root (the web would
     * serve the restore points). Checked once per instance, before any write.
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
        $this->outsideWebRoot = true;
    }

    /**
     * The stream copied, in chunks, to a private temporary file of the tool server.
     *
     * @param  resource  $stream
     */
    private function localCopy($stream, string $key): string
    {
        $path = tempnam(sys_get_temp_dir(), 'wpup-put-');
        $out = $path === false ? false : fopen($path, 'wb');
        if ($path === false || $out === false) {
            throw BackupTargetException::failed("L'escriptura", $key, "no s'ha pogut crear el fitxer temporal");
        }
        chmod($path, 0600);

        try {
            $meta = stream_get_meta_data($stream);
            if ($meta['seekable']) {
                rewind($stream);
            }
            while (! feof($stream)) {
                $chunk = fread($stream, max(1, $this->copyChunkBytes));
                if ($chunk === false || fwrite($out, $chunk) !== strlen($chunk)) {
                    throw BackupTargetException::failed("L'escriptura", $key, 'còpia temporal interrompuda');
                }
            }
        } catch (BackupTargetException $e) {
            @unlink($path);

            throw $e;
        } finally {
            fclose($out);
        }

        return $path;
    }

    private function path(string $key): string
    {
        return $this->root.'/'.$key;
    }

    private function object(string $key, WorkFile $file): StoredObject
    {
        return new StoredObject($key, $file->sizeBytes, $file->modifiedAt === null ? null : CarbonImmutable::createFromTimestamp($file->modifiedAt));
    }

    private static function isKey(string $key): bool
    {
        try {
            BackupKey::valid($key);

            return true;
        } catch (BackupTargetException) {
            return false;
        }
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
            $this->driver->remove($partial);
        } catch (DriverException $e) {
            // Outside the store (never listed); the cleanup job removes it.
            Log::warning('Could not remove a partial upload of the site-local store.', ['path' => $partial, 'reason' => $this->redactor->redact($e->getMessage())]);
        }
    }
}
