<?php

declare(strict_types=1);

namespace App\Domain\Artifacts;

/**
 * DNS lookup of a download host, done by the tool so the address it checks
 * is the one the request uses (pinned, against DNS rebinding).
 */
interface HostResolver
{
    /**
     * @return list<string> every IPv4 and IPv6 address of $host (empty if none)
     */
    public function resolve(string $host): array;
}
