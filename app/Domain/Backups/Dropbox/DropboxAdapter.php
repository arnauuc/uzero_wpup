<?php

declare(strict_types=1);

namespace App\Domain\Backups\Dropbox;

use League\Flysystem\Config;
use League\Flysystem\DirectoryAttributes;
use League\Flysystem\FileAttributes;
use League\Flysystem\FilesystemAdapter;
use League\Flysystem\UnableToCheckDirectoryExistence;
use League\Flysystem\UnableToCheckFileExistence;
use League\Flysystem\UnableToCopyFile;
use League\Flysystem\UnableToCreateDirectory;
use League\Flysystem\UnableToDeleteDirectory;
use League\Flysystem\UnableToDeleteFile;
use League\Flysystem\UnableToListContents;
use League\Flysystem\UnableToMoveFile;
use League\Flysystem\UnableToReadFile;
use League\Flysystem\UnableToRetrieveMetadata;
use League\Flysystem\UnableToSetVisibility;
use League\Flysystem\UnableToWriteFile;
use League\Flysystem\Visibility;

/**
 * Flysystem v3 adapter over DropboxClient, rooted at a folder inside the App
 * folder of the Dropbox app (the app itself cannot reach anything else).
 * FlysystemTarget does the rest: temporary write and move, SHA-256 and
 * redaction. Dropbox has no visibility (everything is private to the app)
 * nor MIME types.
 */
final readonly class DropboxAdapter implements FilesystemAdapter
{
    private string $root;

    public function __construct(private DropboxClient $client, string $root)
    {
        $this->root = '/'.trim($root, '/');
    }

    public function fileExists(string $path): bool
    {
        try {
            return ($this->client->metadata($this->path($path))['.tag'] ?? null) === 'file';
        } catch (DropboxException $e) {
            throw new UnableToCheckFileExistence("Unable to check existence for: {$path}. {$e->getMessage()}", 0, $e);
        }
    }

    public function directoryExists(string $path): bool
    {
        try {
            return ($this->client->metadata($this->path($path))['.tag'] ?? null) === 'folder';
        } catch (DropboxException $e) {
            throw new UnableToCheckDirectoryExistence("Unable to check existence for: {$path}. {$e->getMessage()}", 0, $e);
        }
    }

    public function write(string $path, string $contents, Config $config): void
    {
        $stream = fopen('php://temp', 'w+b');
        if ($stream === false) {
            throw UnableToWriteFile::atLocation($path, 'no temporary stream');
        }
        fwrite($stream, $contents);
        rewind($stream);

        try {
            $this->writeStream($path, $stream, $config);
        } finally {
            fclose($stream);
        }
    }

    public function writeStream(string $path, $contents, Config $config): void
    {
        try {
            $this->client->upload($this->path($path), $contents);
        } catch (DropboxException $e) {
            throw UnableToWriteFile::atLocation($path, $e->getMessage(), $e);
        }
    }

    public function read(string $path): string
    {
        $stream = $this->readStream($path);
        try {
            return (string) stream_get_contents($stream);
        } finally {
            fclose($stream);
        }
    }

    public function readStream(string $path)
    {
        try {
            return $this->client->download($this->path($path));
        } catch (DropboxException $e) {
            throw UnableToReadFile::fromLocation($path, $e->getMessage(), $e);
        }
    }

    public function delete(string $path): void
    {
        try {
            $this->client->delete($this->path($path));
        } catch (DropboxException $e) {
            if (! $e->isNotFound()) {
                throw UnableToDeleteFile::atLocation($path, $e->getMessage(), $e);
            }
        }
    }

    public function deleteDirectory(string $path): void
    {
        try {
            $this->client->delete($this->path($path));
        } catch (DropboxException $e) {
            if (! $e->isNotFound()) {
                throw UnableToDeleteDirectory::atLocation($path, $e->getMessage(), $e);
            }
        }
    }

    public function createDirectory(string $path, Config $config): void
    {
        try {
            $this->client->createFolder($this->path($path));
        } catch (DropboxException $e) {
            if (! $e->isFolderConflict()) {
                throw UnableToCreateDirectory::atLocation($path, $e->getMessage(), $e);
            }
        }
    }

    public function setVisibility(string $path, string $visibility): void
    {
        throw UnableToSetVisibility::atLocation($path, 'Dropbox has no visibility: everything is private to the app.');
    }

    public function visibility(string $path): FileAttributes
    {
        return new FileAttributes($path, null, Visibility::PRIVATE);
    }

    public function mimeType(string $path): FileAttributes
    {
        throw UnableToRetrieveMetadata::mimeType($path, 'Dropbox does not report MIME types.');
    }

    public function lastModified(string $path): FileAttributes
    {
        return $this->attributes($path, 'lastModified');
    }

    public function fileSize(string $path): FileAttributes
    {
        return $this->attributes($path, 'fileSize');
    }

    public function listContents(string $path, bool $deep): iterable
    {
        try {
            foreach ($this->client->listFolder($this->path($path), $deep) as $entry) {
                $attributes = $this->toAttributes($entry);
                if ($attributes !== null) {
                    yield $attributes;
                }
            }
        } catch (DropboxException $e) {
            // A folder that does not exist has nothing in it.
            if (! $e->isNotFound()) {
                throw UnableToListContents::atLocation($path, $deep, $e);
            }
        }
    }

    public function move(string $source, string $destination, Config $config): void
    {
        try {
            $this->client->move($this->path($source), $this->path($destination));
        } catch (DropboxException $e) {
            throw UnableToMoveFile::because($e->getMessage(), $source, $destination);
        }
    }

    public function copy(string $source, string $destination, Config $config): void
    {
        try {
            $this->client->copy($this->path($source), $this->path($destination));
        } catch (DropboxException $e) {
            throw UnableToCopyFile::because($e->getMessage(), $source, $destination);
        }
    }

    private function path(string $path): string
    {
        $path = trim($path, '/');

        return $path === '' ? $this->root : $this->root.'/'.$path;
    }

    private function attributes(string $path, string $type): FileAttributes
    {
        try {
            $meta = $this->client->metadata($this->path($path));
        } catch (DropboxException $e) {
            throw UnableToRetrieveMetadata::create($path, $type, $e->getMessage(), $e);
        }

        $attributes = $meta === null ? null : $this->toAttributes($meta);

        return $attributes instanceof FileAttributes ? $attributes : throw UnableToRetrieveMetadata::create($path, $type, 'file not found');
    }

    /**
     * @param  array<mixed>  $entry
     */
    private function toAttributes(array $entry): FileAttributes|DirectoryAttributes|null
    {
        $display = $entry['path_display'] ?? null;
        $prefix = $this->root.'/';
        if (! is_string($display) || ! str_starts_with(mb_strtolower($display), mb_strtolower($prefix))) {
            return null;
        }
        $path = substr($display, strlen($prefix));
        if (($entry['.tag'] ?? null) === 'folder') {
            return new DirectoryAttributes($path);
        }
        $modified = is_string($entry['server_modified'] ?? null) ? strtotime($entry['server_modified']) : false;

        return ($entry['.tag'] ?? null) === 'file' ? new FileAttributes(
            $path,
            is_int($entry['size'] ?? null) ? $entry['size'] : null,
            null,
            $modified === false ? null : $modified,
        ) : null;
    }
}
