<?php

declare(strict_types=1);

namespace App\Domain\Locks;

use App\Models\Site;
use Carbon\CarbonImmutable;
use Illuminate\Redis\Connections\PhpRedisConnection;
use Illuminate\Support\Str;

/**
 * Atomic locks per site and per hosting account in Redis (UZEROWPUP-19,
 * UZEROWPUP-124). Both are taken together or not at all (Lua scripts), carry
 * holder metadata and expire after the TTL unless refreshed, so a dead worker
 * never blocks a site forever.
 */
final readonly class SiteLockManager
{
    private const PREFIX = 'wpup:lock:';

    private const INDEX = 'wpup:lock:index';

    private const ACQUIRE = <<<'LUA'
        if redis.call('exists', KEYS[1]) == 1 then
            return {'site', redis.call('get', KEYS[3]) or ''}
        end
        if redis.call('exists', KEYS[2]) == 1 then
            return {'hosting_account', redis.call('get', KEYS[4]) or ''}
        end
        redis.call('set', KEYS[1], ARGV[1], 'EX', ARGV[2])
        redis.call('set', KEYS[2], ARGV[1], 'EX', ARGV[2])
        redis.call('set', KEYS[3], ARGV[3], 'EX', ARGV[2])
        redis.call('set', KEYS[4], ARGV[3], 'EX', ARGV[2])
        redis.call('sadd', KEYS[5], ARGV[4], ARGV[5])
        return {'ok'}
        LUA;

    private const REFRESH = <<<'LUA'
        if redis.call('get', KEYS[1]) ~= ARGV[1] or redis.call('get', KEYS[2]) ~= ARGV[1] then
            return 0
        end
        for i = 1, 4 do
            redis.call('expire', KEYS[i], ARGV[2])
        end
        return 1
        LUA;

    private const RELEASE = <<<'LUA'
        for i = 1, 2 do
            if redis.call('get', KEYS[i]) == ARGV[1] then
                redis.call('del', KEYS[i], KEYS[i + 2])
                redis.call('srem', KEYS[5], ARGV[i + 1])
            end
        end
        return 1
        LUA;

    public function __construct(
        private PhpRedisConnection $redis,
        private int $ttlSeconds,
    ) {}

    /**
     * @throws LockedException when the site or its hosting account is busy
     */
    public function acquire(Site $site, string $actor, ?int $runId = null): SiteLease
    {
        $owner = (string) Str::uuid();
        $holder = new LockHolder($actor, $runId, CarbonImmutable::now(), $site->id, $site->hosting_account_id);
        [$siteKey, $accountKey] = [self::key(LockLevel::Site, $site->id), self::key(LockLevel::HostingAccount, $site->hosting_account_id)];

        $result = $this->redis->eval(
            self::ACQUIRE,
            5,
            $siteKey, $accountKey, $siteKey.':meta', $accountKey.':meta', self::INDEX,
            $owner, $this->ttlSeconds, $holder->toJson(), $siteKey, $accountKey,
        );

        if (is_array($result) && ($result[0] ?? null) !== 'ok') {
            $level = LockLevel::tryFrom(is_string($result[0] ?? null) ? $result[0] : '') ?? LockLevel::Site;

            throw new LockedException($level, LockHolder::fromJson(is_string($result[1] ?? null) ? $result[1] : ''));
        }

        return new SiteLease($this, $site->id, $site->hosting_account_id, $owner);
    }

    public function refresh(SiteLease $lease): bool
    {
        return $this->redis->eval(self::REFRESH, 4, ...[...$this->leaseKeys($lease), $lease->owner, $this->ttlSeconds]) === 1;
    }

    public function release(SiteLease $lease): void
    {
        [$siteKey, $accountKey] = [self::key(LockLevel::Site, $lease->siteId), self::key(LockLevel::HostingAccount, $lease->hostingAccountId)];

        $this->redis->eval(self::RELEASE, 5, ...[...$this->leaseKeys($lease), self::INDEX, $lease->owner, $siteKey, $accountKey]);
    }

    /**
     * Admin-only escape hatch (`lock:release`). Returns whether a lock was held.
     */
    public function forceRelease(LockLevel $level, int $objectId): bool
    {
        $key = self::key($level, $objectId);
        $deleted = $this->redis->command('del', [$key, $key.':meta']);
        $this->redis->command('srem', [self::INDEX, $key]);

        return is_int($deleted) && $deleted > 0;
    }

    public function holder(LockLevel $level, int $objectId): ?LockHolder
    {
        $meta = $this->redis->command('get', [self::key($level, $objectId).':meta']);

        return is_string($meta) ? LockHolder::fromJson($meta) : null;
    }

    /**
     * @return list<LockInfo>
     */
    public function status(): array
    {
        $members = $this->redis->command('smembers', [self::INDEX]);
        $locks = [];

        foreach (is_array($members) ? $members : [] as $key) {
            if (! is_string($key) || preg_match('/^'.preg_quote(self::PREFIX, '/').'(site|hosting_account):(\d+)$/', $key, $m) !== 1) {
                continue;
            }

            $meta = $this->redis->command('get', [$key.':meta']);
            $ttl = $this->redis->command('ttl', [$key]);
            $holder = is_string($meta) ? LockHolder::fromJson($meta) : null;

            if ($holder === null || ! is_int($ttl) || $ttl < 0) {
                $this->redis->command('srem', [self::INDEX, $key]); // expired: drop from the index

                continue;
            }

            $locks[] = new LockInfo(LockLevel::from($m[1]), (int) $m[2], $holder, $ttl);
        }

        usort($locks, static fn (LockInfo $a, LockInfo $b): int => [$a->level->value, $a->objectId] <=> [$b->level->value, $b->objectId]);

        return $locks;
    }

    /**
     * @return array{string, string, string, string}
     */
    private function leaseKeys(SiteLease $lease): array
    {
        $siteKey = self::key(LockLevel::Site, $lease->siteId);
        $accountKey = self::key(LockLevel::HostingAccount, $lease->hostingAccountId);

        return [$siteKey, $accountKey, $siteKey.':meta', $accountKey.':meta'];
    }

    private static function key(LockLevel $level, int $id): string
    {
        return self::PREFIX.$level->value.':'.$id;
    }
}
