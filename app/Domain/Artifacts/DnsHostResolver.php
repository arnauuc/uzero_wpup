<?php

declare(strict_types=1);

namespace App\Domain\Artifacts;

final readonly class DnsHostResolver implements HostResolver
{
    public function resolve(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }
        $v4 = @gethostbynamel($host);
        $v6 = @dns_get_record($host, DNS_AAAA);
        $addresses = is_array($v4) ? $v4 : [];
        foreach (is_array($v6) ? $v6 : [] as $record) {
            if (is_string($record['ipv6'] ?? null)) {
                $addresses[] = $record['ipv6'];
            }
        }

        return array_values(array_unique($addresses));
    }
}
