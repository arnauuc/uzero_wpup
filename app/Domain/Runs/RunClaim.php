<?php

declare(strict_types=1);

namespace App\Domain\Runs;

use Illuminate\Redis\Connections\PhpRedisConnection;
use Illuminate\Support\Str;

/**
 * Exclusive claim of a worker on a run, in Redis with a TTL. It doubles as the
 * heartbeat: a run marked running whose claim expired has lost its worker.
 */
final readonly class RunClaim
{
    private const REFRESH = <<<'LUA'
        if redis.call('get', KEYS[1]) == ARGV[1] then
            return redis.call('expire', KEYS[1], ARGV[2])
        end
        return 0
        LUA;

    private const RELEASE = <<<'LUA'
        if redis.call('get', KEYS[1]) == ARGV[1] then
            return redis.call('del', KEYS[1])
        end
        return 0
        LUA;

    public function __construct(
        private PhpRedisConnection $redis,
        private int $ttlSeconds,
    ) {}

    /**
     * Returns the claim token, or null if another worker holds the run.
     */
    public function claim(int $runId): ?string
    {
        $token = (string) Str::uuid();
        $set = $this->redis->command('set', [self::key($runId), $token, ['nx', 'ex' => $this->ttlSeconds]]);

        return $set === true ? $token : null;
    }

    public function refresh(int $runId, string $token): bool
    {
        return $this->redis->eval(self::REFRESH, 1, self::key($runId), $token, $this->ttlSeconds) === 1;
    }

    public function release(int $runId, string $token): void
    {
        $this->redis->eval(self::RELEASE, 1, self::key($runId), $token);
    }

    public function isClaimed(int $runId): bool
    {
        return $this->redis->command('exists', [self::key($runId)]) === 1;
    }

    private static function key(int $runId): string
    {
        return "wpup:run:{$runId}:claim";
    }
}
