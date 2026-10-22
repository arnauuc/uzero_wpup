<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Secrets\Kek\KekDecoder;
use App\Domain\Secrets\Kek\KekProvider;
use App\Domain\Secrets\Secret;
use App\Domain\Secrets\SecretStoreException;

/**
 * Test KEK provider with several versions, to exercise secrets:rekey.
 */
final readonly class StaticKekProvider implements KekProvider
{
    /**
     * @param  array<int, string>  $keys  version => base64 key
     */
    public function __construct(private array $keys, private int $current) {}

    public function currentVersion(): int
    {
        return $this->current;
    }

    public function key(int $version): Secret
    {
        return isset($this->keys[$version])
            ? KekDecoder::decode($this->keys[$version])
            : throw SecretStoreException::kekUnavailable("no test KEK v{$version}");
    }
}
