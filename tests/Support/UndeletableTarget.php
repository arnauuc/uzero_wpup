<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Backups\BackupTarget;
use App\Domain\Backups\BackupTargetException;
use App\Domain\Backups\StoredObject;

/** A destination whose deletes fail, with a secret in the message. */
final class UndeletableTarget implements BackupTarget
{
    public const string SECRET = 'sk-secret-1234567890';

    public function put(string $key, $stream): StoredObject
    {
        throw new BackupTargetException('no');
    }

    public function readStream(string $key)
    {
        throw BackupTargetException::notFound($key);
    }

    public function exists(string $key): bool
    {
        return true;
    }

    public function checksum(string $key): string
    {
        return str_repeat('0', 64);
    }

    public function delete(string $key): void
    {
        throw new BackupTargetException("L'esborrat de «{$key}» ha fallat: sense permís (token=".self::SECRET.').');
    }

    public function list(string $prefix = ''): array
    {
        return [];
    }
}
