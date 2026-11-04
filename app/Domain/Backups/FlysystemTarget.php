<?php

declare(strict_types=1);

namespace App\Domain\Backups;

use Carbon\CarbonImmutable;
use League\Flysystem\FilesystemException;
use League\Flysystem\FilesystemOperator;
use League\Flysystem\StorageAttributes;

/**
 * BackupTarget over any Flysystem filesystem (local disk of the tool server,
 * and the SFTP/S3 adapters of UZEROWPUP-41/42). Writes go to a temporary key
 * and are moved into place only when complete, so a failed upload never
 * leaves a truncated object under the final key.
 */
final readonly class FlysystemTarget implements BackupTarget
{
    private const string PARTIAL_SUFFIX = '.part';

    private const int HASH_CHUNK_BYTES = 1_048_576;

    public function __construct(private FilesystemOperator $filesystem) {}

    public function put(string $key, $stream): StoredObject
    {
        BackupKey::valid($key);
        if (! is_resource($stream)) {
            throw BackupTargetException::notAStream();
        }

        $partial = $key.self::PARTIAL_SUFFIX;

        try {
            if ($this->filesystem->fileExists($key)) {
                throw BackupTargetException::alreadyExists($key);
            }

            $this->filesystem->writeStream($partial, $stream);

            // Re-check right before publishing: another writer may have won.
            if ($this->filesystem->fileExists($key)) {
                $this->filesystem->delete($partial);

                throw BackupTargetException::alreadyExists($key);
            }

            $this->filesystem->move($partial, $key);

            return new StoredObject($key, $this->filesystem->fileSize($key), $this->modified($key));
        } catch (FilesystemException $e) {
            $this->discard($partial);

            throw BackupTargetException::failed("L'escriptura", $key, $e->getMessage());
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
            throw BackupTargetException::failed('La lectura', $key, $e->getMessage());
        }
    }

    public function exists(string $key): bool
    {
        BackupKey::valid($key);

        try {
            return $this->filesystem->fileExists($key);
        } catch (FilesystemException $e) {
            throw BackupTargetException::failed('La comprovació', $key, $e->getMessage());
        }
    }

    public function checksum(string $key): string
    {
        $stream = $this->readStream($key);
        $hash = hash_init('sha256');

        try {
            while (! feof($stream)) {
                $chunk = fread($stream, self::HASH_CHUNK_BYTES);
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
            throw BackupTargetException::failed("L'esborrat", $key, $e->getMessage());
        }
    }

    public function list(string $prefix = ''): array
    {
        BackupKey::validPrefix($prefix);
        $directory = str_contains($prefix, '/') ? substr($prefix, 0, (int) strrpos($prefix, '/')) : '';

        try {
            $objects = [];
            foreach ($this->filesystem->listContents($directory, true) as $item) {
                /** @var StorageAttributes $item */
                $path = $item->path();
                if ($item->isFile() && str_starts_with($path, $prefix) && ! str_ends_with($path, self::PARTIAL_SUFFIX)) {
                    $objects[] = new StoredObject($path, $this->filesystem->fileSize($path), $this->modified($path));
                }
            }
        } catch (FilesystemException $e) {
            throw BackupTargetException::failed('El llistat', $prefix === '' ? '/' : $prefix, $e->getMessage());
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

    private function discard(string $partial): void
    {
        try {
            $this->filesystem->delete($partial);
        } catch (FilesystemException) {
            // Best effort: list() already hides partial objects.
        }
    }
}
