<?php

declare(strict_types=1);

namespace App\Domain\Locks;

use Carbon\CarbonImmutable;

/**
 * Who holds a lock: shown to whoever finds it busy (spec: "qui el té, des de quan
 * i quina execució és").
 */
final readonly class LockHolder
{
    public function __construct(
        public string $actor,
        public ?int $runId,
        public CarbonImmutable $acquiredAt,
        public int $siteId,
        public int $hostingAccountId,
    ) {}

    public function toJson(): string
    {
        return json_encode([
            'actor' => $this->actor,
            'run_id' => $this->runId,
            'acquired_at' => $this->acquiredAt->toIso8601String(),
            'site_id' => $this->siteId,
            'hosting_account_id' => $this->hostingAccountId,
        ], JSON_THROW_ON_ERROR);
    }

    public static function fromJson(string $json): ?self
    {
        $data = json_decode($json, true);

        if (! is_array($data)
            || ! is_string($data['actor'] ?? null)
            || ! is_string($data['acquired_at'] ?? null)
            || ! is_int($data['site_id'] ?? null)
            || ! is_int($data['hosting_account_id'] ?? null)) {
            return null;
        }

        return new self(
            $data['actor'],
            is_int($data['run_id'] ?? null) ? $data['run_id'] : null,
            CarbonImmutable::parse($data['acquired_at']),
            $data['site_id'],
            $data['hosting_account_id'],
        );
    }

    private static function timezone(): string
    {
        $timezone = config('app.timezone');

        return is_string($timezone) && $timezone !== '' ? $timezone : 'UTC';
    }

    public function describe(): string
    {
        return sprintf(
            '%s, des de %s%s',
            $this->actor,
            $this->acquiredAt->setTimezone(self::timezone())->format('Y-m-d H:i:s'),
            $this->runId === null ? '' : sprintf(' (execució #%d)', $this->runId),
        );
    }
}
