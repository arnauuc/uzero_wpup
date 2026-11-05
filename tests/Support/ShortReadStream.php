<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * A non-seekable stream wrapper that hands out at most $readBytes per read,
 * like a socket or a pipe under load; with 0 it stalls (never ends, never
 * gives a byte).
 */
final class ShortReadStream
{
    private const string SCHEME = 'wpup-short';

    /** @var resource|null set by PHP */
    public $context;

    /** @var array<string, array{string, int}> contents and read size */
    private static array $contents = [];

    private string $id = '';

    private int $position = 0;

    /**
     * @return resource
     */
    public static function open(string $contents, int $readBytes = 1000)
    {
        if (! in_array(self::SCHEME, stream_get_wrappers(), true)) {
            stream_wrapper_register(self::SCHEME, self::class);
        }
        $id = bin2hex(random_bytes(6));
        self::$contents[$id] = [$contents, $readBytes];
        $stream = fopen(self::SCHEME.'://'.$id, 'rb');
        assert(is_resource($stream));

        return $stream;
    }

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        $this->id = substr($path, strlen(self::SCHEME.'://'));

        return isset(self::$contents[$this->id]);
    }

    public function stream_read(int $count): string
    {
        [$contents, $readBytes] = self::$contents[$this->id];
        $chunk = substr($contents, $this->position, min($count, $readBytes));
        $this->position += strlen($chunk);

        return $chunk;
    }

    public function stream_eof(): bool
    {
        return self::$contents[$this->id][1] > 0 && $this->position >= strlen(self::$contents[$this->id][0]);
    }

    /** Like a pipe: it cannot seek. */
    public function stream_seek(int $offset, int $whence): bool
    {
        return false;
    }

    /**
     * @return array<string, int>
     */
    public function stream_stat(): array
    {
        return [];
    }
}
