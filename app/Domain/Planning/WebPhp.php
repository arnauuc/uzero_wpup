<?php

declare(strict_types=1);

namespace App\Domain\Planning;

use App\Domain\Drivers\Data\PhpRuntime;
use App\Enums\PhpVersionSource;
use App\Models\Site;
use App\Models\SiteSnapshot;

/**
 * The PHP a snapshot should be judged with, shared by the plan and the inventory
 * report so they agree. A snapshot whose PHP is the CLI's may not be the web's:
 * the latest probe-verified PHP of the site stands in for it only if it is recent
 * (`$maxAgeSeconds`) and the CLI agrees on the minor version.
 */
final class WebPhp
{
    /**
     * @return array{0: string, 1: PhpVersionSource} version and where it comes from
     */
    public static function of(SiteSnapshot $snapshot, Site $site, int $maxAgeSeconds): array
    {
        $php = $snapshot->php_version;
        $source = $snapshot->php_source;
        if ($source === PhpVersionSource::Cli) {
            $verified = SiteSnapshot::latestWebVerifiedPhp($site, $maxAgeSeconds);
            if ($verified !== null && PhpRuntime::isVersion($php) && PhpRuntime::isVersion($verified->php_version)
                && PhpRuntime::minorOf($verified->php_version) === PhpRuntime::minorOf($php)) {
                return [$verified->php_version, $verified->php_source];
            }
        }

        return [$php, $source];
    }
}
