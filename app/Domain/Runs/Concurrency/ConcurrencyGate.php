<?php

declare(strict_types=1);

namespace App\Domain\Runs\Concurrency;

use App\Models\Run;
use Illuminate\Redis\Connections\PhpRedisConnection;

/**
 * Concurrency limits (UZEROWPUP-20, ADR-0006): global, per hosting server and
 * for staging. Each level is a sorted set of holders scored by lease expiry,
 * taken all together or not at all (Lua). Leases are short and refreshed by
 * the engine, so a dead worker frees its slots within a minute instead of
 * blocking the fleet. The per-site and per-account locks are SiteLockManager's.
 */
final readonly class ConcurrencyGate
{
    private const PREFIX = 'wpup:slots:';

    private const ACQUIRE = <<<'LUA'
        local now, expiry, token = tonumber(ARGV[1]), tonumber(ARGV[2]), ARGV[3]
        for i, key in ipairs(KEYS) do
            redis.call('zremrangebyscore', key, '-inf', now)
            if not redis.call('zscore', key, token) and redis.call('zcard', key) >= tonumber(ARGV[3 + i]) then
                return i
            end
        end
        for _, key in ipairs(KEYS) do
            redis.call('zadd', key, expiry, token)
        end
        return 0
        LUA;

    /**
     * @param  array{global: int, per_hosting_server: int, staging: int}  $limits
     */
    public function __construct(
        private PhpRedisConnection $redis,
        private array $limits,
        private int $leaseSeconds,
    ) {}

    /**
     * Takes a slot at every level that applies to the run. An admin's run
     * flagged `now` (audited by its command) skips the global queue only.
     *
     * @throws ConcurrencyLimitReached
     */
    public function acquire(Run $run, string $token): SlotLease
    {
        $levels = [];
        if (($run->flags['now'] ?? false) !== true) {
            $levels['global'] = $this->limits['global'];
        }
        if ($run->site !== null) {
            $levels['server:'.$run->site->hostingAccount()->firstOrFail()->hosting_server_id] = $this->limits['per_hosting_server'];
        }
        if (($run->flags['staging'] ?? false) === true) {
            $levels['staging'] = $this->limits['staging'];
        }

        $keys = array_map(static fn (string $level): string => self::PREFIX.$level, array_keys($levels));
        if ($keys === []) {
            return new SlotLease($this, [], $token);
        }

        $now = microtime(true);
        $blocked = $this->redis->eval(self::ACQUIRE, count($keys), ...[...$keys, $now, $now + $this->leaseSeconds, $token, ...array_values($levels)]);

        if (is_int($blocked) && $blocked > 0) {
            throw new ConcurrencyLimitReached(array_keys($levels)[$blocked - 1]);
        }

        return new SlotLease($this, $keys, $token);
    }

    /**
     * Extends the lease. XX only touches slots this execution still holds, so a
     * slot that expired and was taken by someone else is reported as lost.
     */
    public function refresh(SlotLease $lease): bool
    {
        $expiry = microtime(true) + $this->leaseSeconds;
        foreach ($lease->keys as $key) {
            if ($this->redis->command('zadd', [$key, ['XX', 'CH'], $expiry, $lease->token]) !== 1
                && $this->redis->command('zscore', [$key, $lease->token]) === false) {
                return false;
            }
        }

        return true;
    }

    public function release(SlotLease $lease): void
    {
        foreach ($lease->keys as $key) {
            $this->redis->command('zrem', [$key, $lease->token]);
        }
    }
}
