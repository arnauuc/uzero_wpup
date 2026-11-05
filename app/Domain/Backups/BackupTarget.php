<?php

declare(strict_types=1);

namespace App\Domain\Backups;

/**
 * Where restore points are stored (ADR-0009): the site's own server
 * (UZEROWPUP-40) and optional external destinations (SFTP, S3, Dropbox...).
 * Domain code only talks to destinations through this interface; adding one
 * means implementing it and registering it in BackupTargetRegistry.
 *
 * Rules every implementation follows (tests/Contracts/BackupTargetContract.php):
 * - keys are relative paths checked by BackupKey; never absolute, never `..`;
 * - keys the tool generates are lowercase: a case-insensitive destination
 *   (Dropbox) refuses capital letters rather than mixing two keys;
 * - data is streamed: no archive is ever loaded whole into memory;
 * - an object is immutable once stored: put() never overwrites;
 * - checksum() is the SHA-256 of what the destination actually holds, computed
 *   from its own bytes (never a value stored alongside);
 * - failures throw BackupTargetException with an operator-readable message
 *   that never contains secrets.
 */
interface BackupTarget
{
    /**
     * Stores the stream under $key and returns what was stored.
     *
     * @param  resource  $stream  readable; the whole stream is stored (seekable streams are rewound)
     *
     * @throws BackupTargetException when $key exists or the write fails
     */
    public function put(string $key, $stream): StoredObject;

    /**
     * A readable stream with the object's bytes. The caller closes it.
     *
     * @return resource
     *
     * @throws BackupTargetException when $key does not exist
     */
    public function readStream(string $key);

    /**
     * @throws BackupTargetException
     */
    public function exists(string $key): bool;

    /**
     * SHA-256 (hex) of the stored bytes.
     *
     * @throws BackupTargetException when $key does not exist
     */
    public function checksum(string $key): string;

    /**
     * Removes $key. Does nothing if it does not exist.
     *
     * @throws BackupTargetException
     */
    public function delete(string $key): void;

    /**
     * Objects whose key starts with $prefix (a directory-like prefix such as
     * "site-12/" or ""), ordered by key.
     *
     * @return list<StoredObject>
     *
     * @throws BackupTargetException
     */
    public function list(string $prefix = ''): array;
}
