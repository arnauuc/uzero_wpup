<?php

declare(strict_types=1);

namespace App\Domain\Secrets;

use JsonSerializable;
use LogicException;
use Stringable;

/**
 * A secret value that cannot leak by accident: string conversion, JSON, var_dump,
 * print_r and logging all show a mask, and serialisation is refused. The plain
 * value is only available through reveal(), which must stay as close as possible
 * to the place that uses it (AGENTS.md rule 2).
 *
 * The value is kept XOR-masked with a random per-instance pad, so dumps that
 * bypass __debugInfo() (var_export, array casts) only show random bytes.
 *
 * wipe() overwrites the held copies with sodium_memzero(). PHP may still hold other
 * copies of the string, so this is a best-effort reduction of exposure, not a
 * guarantee.
 */
final class Secret implements JsonSerializable, Stringable
{
    public const MASK = '[secret]';

    private string $masked;

    private string $pad;

    private bool $wiped = false;

    public function __construct(#[\SensitiveParameter] string $value)
    {
        $this->pad = random_bytes(max(1, strlen($value)));
        $this->masked = $value ^ substr($this->pad, 0, strlen($value));
    }

    public function reveal(): string
    {
        if ($this->wiped) {
            throw new LogicException('The secret has been wiped.');
        }

        return $this->masked ^ substr($this->pad, 0, strlen($this->masked));
    }

    public function wipe(): void
    {
        if (! $this->wiped) {
            // Detach the buffers first so the local copies are the only references,
            // then zero them in place.
            $masked = $this->masked;
            $pad = $this->pad;
            $this->masked = '';
            $this->pad = '';
            sodium_memzero($masked);
            sodium_memzero($pad);
            $this->wiped = true;
        }
    }

    public function __toString(): string
    {
        return self::MASK;
    }

    public function jsonSerialize(): string
    {
        return self::MASK;
    }

    /**
     * @return array{value: string}
     */
    public function __debugInfo(): array
    {
        return ['value' => self::MASK];
    }

    /**
     * @return array<string, mixed>
     */
    public function __serialize(): array
    {
        throw new LogicException('Secrets cannot be serialized.');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function __unserialize(array $data): void
    {
        throw new LogicException('Secrets cannot be unserialized.');
    }

    public function __clone()
    {
        throw new LogicException('Secrets cannot be cloned.');
    }
}
