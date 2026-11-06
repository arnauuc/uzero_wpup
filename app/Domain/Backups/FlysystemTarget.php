<?php

declare(strict_types=1);

namespace App\Domain\Backups;

use App\Domain\Secrets\SecretRedactor;
use Carbon\CarbonImmutable;
use League\Flysystem\Config;
use League\Flysystem\FileAttributes;
use League\Flysystem\FilesystemException;
use League\Flysystem\FilesystemOperator;
use League\Flysystem\Visibility;

/**
 * BackupTarget over any Flysystem filesystem (local disk of the tool server,
 * and the SFTP/S3 adapters of UZEROWPUP-41/42).
 *
 * - Writes go to a unique temporary object under TMP_DIR (a name BackupKey can
 *   never produce) and are moved into place only when complete, so a failed
 *   upload never leaves a truncated object under the final key, and two
 *   writers never share a temporary file.
 * - Objects and directories are private (0600 / 0700 on a local disk).
 * - Flysystem rewinds seekable streams: the whole stream is stored.
 * - Concurrent put() of the same key is excluded upstream (one run per site,
 *   UZEROWPUP-19); the existence check right before publishing narrows the
 *   window further but is not atomic on every adapter.
 */
final readonly class FlysystemTarget implements RenamableBackupTarget
{
    /** Starts with ".", which BackupKey forbids: never clashes with a real key. */
    private const string TMP_DIR = '.wpup-tmp';

    private const array PRIVATE_FILES = [
        Config::OPTION_VISIBILITY => Visibility::PRIVATE,
        Config::OPTION_DIRECTORY_VISIBILITY => Visibility::PRIVATE,
    ];

    public function __construct(
        private FilesystemOperator $filesystem,
        private SecretRedactor $redactor,
        private int $hashChunkBytes,
    ) {}

    public function put(string $key, $stream): StoredObject
    {
        BackupKey::valid($key);
        if (! is_resource($stream)) {
            throw BackupTargetException::notAStream();
        }

        $partial = self::TMP_DIR.'/'.bin2hex(random_bytes(16));

        try {
            if ($this->filesystem->fileExists($key)) {
                throw BackupTargetException::alreadyExists($key);
            }

            $this->filesystem->writeStream($partial, $stream, self::PRIVATE_FILES);

            // Re-check right before publishing: another writer may have won.
            if ($this->filesystem->fileExists($key)) {
                $this->filesystem->delete($partial);

                throw BackupTargetException::alreadyExists($key);
            }

            $this->filesystem->move($partial, $key, self::PRIVATE_FILES);

            return new StoredObject($key, $this->filesystem->fileSize($key), $this->modified($key));
        } catch (FilesystemException $e) {
            $this->discard($partial);

            throw BackupTargetException::failed("L'escriptura", $key, $this->reason($e));
        }
    }

    public function rename(string $from, string $to): void
    {
        BackupKey::valid($from);
        BackupKey::valid($to);

        try {
            if (! $this->filesystem->fileExists($from)) {
                throw BackupTargetException::notFound($from);
            }
            if ($this->filesystem->fileExists($to)) {
                throw BackupTargetException::alreadyExists($to);
            }
            $this->filesystem->move($from, $to, self::PRIVATE_FILES);
        } catch (FilesystemException $e) {
            throw BackupTargetException::failed('El canvi de nom', $to, $this->reason($e));
        }
    }

    public function readStream(string $key)
    {
        BackupKey::valid($key);

        try {
            if (! $this->filesystem->fileExists($key)) {
                throw BackupTargetException::notFound($key);
            }

            return $this->filesystem->readStream($key);
        } catch (FilesystemException $e) {
            throw BackupTargetException::failed('La lectura', $key, $this->reason($e));
        }
    }

    public function exists(string $key): bool
    {
        BackupKey::valid($key);

        try {
            return $this->filesystem->fileExists($key);
        } catch (FilesystemException $e) {
            throw BackupTargetException::failed('La comprovació', $key, $this->reason($e));
        }
    }

    public function checksum(string $key): string
    {
        $stream = $this->readStream($key);
        $hash = hash_init('sha256');

        try {
            while (! feof($stream)) {
                $chunk = fread($stream, max(1, $this->hashChunkBytes));
                if ($chunk === false) {
                    throw BackupTargetException::failed('El càlcul del checksum', $key, 'lectura interrompuda');
                }
                hash_update($hash, $chunk);
            }
        } finally {
            fclose($stream);
        }

        return hash_final($hash);
    }

    public function delete(string $key): void
    {
        BackupKey::valid($key);

        try {
            if ($this->filesystem->fileExists($key)) {
                $this->filesystem->delete($key);
            }
        } catch (FilesystemException $e) {
            throw BackupTargetException::failed("L'esborrat", $key, $this->reason($e));
        }
    }

    public function list(string $prefix = ''): array
    {
        BackupKey::validPrefix($prefix);
        $directory = str_contains($prefix, '/') ? substr($prefix, 0, (int) strrpos($prefix, '/')) : '';

        try {
            $objects = [];
            foreach ($this->filesystem->listContents($directory, true) as $item) {
                $path = $item->path();
                if ($item instanceof FileAttributes && str_starts_with($path, $prefix) && ! str_starts_with($path, self::TMP_DIR.'/')) {
                    $modified = $item->lastModified();
                    $objects[] = new StoredObject(
                        $path,
                        $item->fileSize() ?? $this->filesystem->fileSize($path),
                        $modified === null ? null : CarbonImmutable::createFromTimestamp($modified),
                    );
                }
            }
        } catch (FilesystemException $e) {
            throw BackupTargetException::failed('El llistat', $prefix === '' ? '/' : $prefix, $this->reason($e));
        }

        usort($objects, static fn (StoredObject $a, StoredObject $b): int => strcmp($a->key, $b->key));

        return $objects;
    }

    private function modified(string $key): ?CarbonImmutable
    {
        try {
            return CarbonImmutable::createFromTimestamp($this->filesystem->lastModified($key));
        } catch (FilesystemException) {
            // Not every adapter knows it; the size and checksum are what matter.
            return null;
        }
    }

    /**
     * Adapter messages may carry hosts, users or signed URLs: masked first.
     */
    private function reason(FilesystemException $e): string
    {
        return $this->redactor->redact($e->getMessage());
    }

    private function discard(string $partial): void
    {
        try {
            $this->filesystem->delete($partial);
        } catch (FilesystemException) {
            // Best effort: list() never shows the temporary directory.
        }
    }
}
