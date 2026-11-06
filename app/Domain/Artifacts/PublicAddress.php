<?php

declare(strict_types=1);

namespace App\Domain\Artifacts;

/**
 * Whether an IP address is on the public Internet, so a package URL can
 * never make the tool server reach itself, the LAN or the tailnet (SSRF,
 * ADR-0003). These ranges are a security definition, not a tunable.
 */
final class PublicAddress
{
    private const array BLOCKED = [
        '0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8', '169.254.0.0/16', '172.16.0.0/12',
        '192.0.0.0/24', '192.0.2.0/24', '192.88.99.0/24', '192.168.0.0/16', '198.18.0.0/15',
        '198.51.100.0/24', '203.0.113.0/24', '224.0.0.0/4', '240.0.0.0/4',
        // IPv6: unspecified, loopback, v4-mapped and translated (they embed a v4 address),
        // discard, Teredo, documentation, 6to4, ULA (also Tailscale's), link-local, multicast.
        '::/128', '::1/128', '::ffff:0:0/96', '64:ff9b::/96', '64:ff9b:1::/48', '100::/64', '2001::/32',
        '2001:db8::/32', '2002::/16', 'fc00::/7', 'fe80::/10', 'fec0::/10', 'ff00::/8',
    ];

    public static function isPublic(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return false;
        }
        $address = (string) inet_pton($ip);
        foreach (self::BLOCKED as $cidr) {
            [$network, $bits] = explode('/', $cidr);
            $prefix = (string) inet_pton($network);
            if (strlen($prefix) === strlen($address) && self::inPrefix($address, $prefix, (int) $bits)) {
                return false;
            }
        }

        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
    }

    private static function inPrefix(string $address, string $prefix, int $bits): bool
    {
        $bytes = intdiv($bits, 8);
        if (substr($address, 0, $bytes) !== substr($prefix, 0, $bytes)) {
            return false;
        }
        $rest = $bits % 8;
        if ($rest === 0) {
            return true;
        }
        $mask = (0xFF << (8 - $rest)) & 0xFF;

        return (ord($address[$bytes]) & $mask) === (ord($prefix[$bytes]) & $mask);
    }
}
