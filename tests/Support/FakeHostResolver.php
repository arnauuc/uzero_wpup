<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Artifacts\HostResolver;

/**
 * DNS for tests: hosts not listed resolve to a public documentation-free
 * address; IP literals resolve to themselves.
 */
final class FakeHostResolver implements HostResolver
{
    public const string PUBLIC_IP = '93.184.216.34';

    /**
     * @param  array<string, list<string>>  $hosts
     */
    public function __construct(public array $hosts = []) {}

    public function resolve(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        return $this->hosts[$host] ?? [self::PUBLIC_IP];
    }
}
