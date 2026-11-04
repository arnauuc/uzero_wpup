<?php

declare(strict_types=1);

namespace App\Domain\Inventory;

use App\Domain\Drivers\Data\InstalledComponents;
use App\Domain\Drivers\Exceptions\ConnectionFailed;
use App\Domain\Drivers\Exceptions\DriverException;
use App\Domain\Drivers\SiteDriver;
use App\Enums\ComponentType;

/**
 * Pre-update state of a site (UZEROWPUP-33), read only through the driver:
 * WooCommerce (DB version, pending DB update, theme templates that override
 * WooCommerce's), WP-Cron (disabled, overdue events), maintenance mode and
 * free disk space.
 *
 * Everything but the disk comes from ONE constant `wp eval` snippet (a single
 * WP-CLI process; nothing is interpolated) that prints JSON:
 * - WooCommerce has no public API for template overrides (its system status
 *   report builds them inline), so the snippet mirrors that logic: every file of
 *   WooCommerce's templates/ that exists in the active or parent theme, directly
 *   or under woocommerce/, compared by its `@version` header.
 * - Cron: it returns [timestamp, count] of the events already due; this class
 *   decides which are overdue (config grace period), using the site's clock.
 * - Maintenance: like wp_is_maintenance_mode(): a `.maintenance` file whose
 *   `$upgrading` timestamp is younger than the configured TTL (WordPress
 *   ignores an older one). The file is parsed, never included.
 *
 * Null means "could not be read" (with a warning); if the whole snippet fails,
 * every field it carries is null.
 */
final readonly class SiteState
{
    /** Constant on purpose: nothing from the site or the operator is interpolated. */
    public const string SNIPPET = <<<'PHP'
$now = time();
$flags = JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR;
$overdue = array();
$cron = function_exists('_get_cron_array') ? _get_cron_array() : array();
if (is_array($cron)) {
    foreach ($cron as $timestamp => $hooks) {
        if (!is_numeric($timestamp) || (int) $timestamp > $now || !is_array($hooks)) { continue; }
        $count = 0;
        foreach ($hooks as $events) { if (is_array($events)) { $count += count($events); } }
        if ($count > 0) { $overdue[] = array((int) $timestamp, $count); }
    }
}
$maintenance = array('file' => false, 'upgrading' => null);
$path = ABSPATH . '.maintenance';
if (is_file($path)) {
    $maintenance['file'] = true;
    $contents = @file_get_contents($path, false, null, 0, 4096);
    if (is_string($contents) && preg_match('/\$upgrading\s*=\s*(\d+)/', $contents, $m)) { $maintenance['upgrading'] = (int) $m[1]; }
}
$wc = null;
if (function_exists('WC')) {
    try {
        $base = WC()->plugin_path() . '/templates/';
        $version = function ($file) {
            $data = @file_get_contents($file, false, null, 0, 8192);
            if ($data === false || !preg_match('/^[ \t\/*#@]*@version(.*)$/mi', str_replace("\r", "\n", $data), $m)) { return ''; }
            return function_exists('_cleanup_header_comment') ? _cleanup_header_comment($m[1]) : trim($m[1]);
        };
        $files = array('content-product_cat.php', 'taxonomy-product_cat.php', 'taxonomy-product_tag.php');
        if (is_dir($base)) {
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $item) { if ($item->isFile()) { $files[] = substr($item->getPathname(), strlen($base)); } }
        }
        $templateDir = rtrim(WC()->template_path(), '/');
        $dirs = array(get_stylesheet_directory(), get_stylesheet_directory() . '/' . $templateDir, get_template_directory(), get_template_directory() . '/' . $templateDir);
        $overridden = array();
        foreach (array_unique($files) as $file) {
            foreach ($dirs as $dir) {
                $themeFile = $dir . '/' . $file;
                if (!is_file($themeFile)) { continue; }
                $core = (strpos($file, '-product_cat') !== false || strpos($file, '-product_tag') !== false) ? str_replace('_', '-', $file) : $file;
                $overridden[] = array('file' => str_replace(WP_CONTENT_DIR . '/themes/', '', $themeFile), 'theme_version' => $version($themeFile), 'core_version' => $version($base . $core));
                break;
            }
        }
        $dbVersion = get_option('woocommerce_db_version');
        $needsUpdate = null;
        if (is_callable(array('WC_Install', 'needs_db_update'))) {
            $needsUpdate = (bool) WC_Install::needs_db_update();
        } elseif ($dbVersion !== false) {
            $needsUpdate = version_compare((string) $dbVersion, (string) WC()->version, '<');
        }
        $wc = array('db_version' => $dbVersion === false ? null : (string) $dbVersion, 'db_update_needed' => $needsUpdate, 'overridden_templates' => $overridden);
    } catch (Throwable $e) {
        $wc = array('failed' => true);
    }
}
echo json_encode(array(
    'now' => $now,
    'cron_disabled' => defined('DISABLE_WP_CRON') && DISABLE_WP_CRON,
    'cron_overdue' => $overdue,
    'maintenance' => $maintenance,
    'woocommerce' => $wc,
), $flags);
PHP;

    public function __construct(
        private WpCliReader $reader,
        private int $commandTimeoutSeconds,
        private int $cronOverdueAfterSeconds,
        private int $maintenanceTtlSeconds,
    ) {}

    /**
     * @param  int  $now  the tool's unix time, used only if the site does not report its own
     *
     * @throws ConnectionFailed
     */
    public function read(SiteDriver $driver, InstalledComponents $installed, int $now): SiteStateResult
    {
        $warnings = [];
        $diskFree = null;
        try {
            $diskFree = $driver->freeDiskBytes();
        } catch (ConnectionFailed $e) {
            throw $e;
        } catch (DriverException $e) {
            $warnings[] = $this->reader->redact("No s'ha pogut llegir l'espai lliure al disc: {$e->getMessage()}");
        }

        $data = $this->snippet($driver, $warnings);

        $version = null;
        foreach ($installed->components as $component) {
            if ($component->type === ComponentType::Woocommerce && $component->active) {
                $version = $component->version;
            }
        }

        return new SiteStateResult(
            $version === null ? null : $this->woocommerce($version, $data, $warnings),
            $data === null ? null : $this->cron($data, $now),
            $data === null ? null : $this->maintenance($data, $now),
            $diskFree,
            $warnings,
        );
    }

    /**
     * @param  list<string>  $warnings
     * @return array<mixed>|null
     */
    private function snippet(SiteDriver $driver, array &$warnings): ?array
    {
        $what = "l'estat previ del lloc";
        $result = $this->reader->run($driver, ['eval', self::SNIPPET], $this->commandTimeoutSeconds, $what, $warnings);
        if ($result === null) {
            return null;
        }
        if (! $result->successful()) {
            $warnings[] = $this->reader->redact("No s'ha pogut llegir {$what} (codi {$result->exitCode}): ".trim($result->stderr));

            return null;
        }

        $data = $this->reader->json($result->stdout);
        if ($data === null) {
            $warnings[] = "La resposta sobre {$what} no és JSON vàlid.";
        }

        return $data;
    }

    /**
     * @param  array<mixed>|null  $data
     * @param  list<string>  $warnings
     */
    private function woocommerce(string $version, ?array $data, array &$warnings): WooCommerceState
    {
        if ($data === null) {
            return new WooCommerceState($version, null, null, null);
        }

        $wc = $data['woocommerce'] ?? null;
        if (! is_array($wc) || isset($wc['failed'])) {
            $warnings[] = "No s'ha pogut llegir l'estat de WooCommerce al lloc.";

            return new WooCommerceState($version, null, null, null);
        }

        $templates = [];
        foreach (is_array($wc['overridden_templates'] ?? null) ? $wc['overridden_templates'] : [] as $row) {
            if (is_array($row) && is_string($row['file'] ?? null)) {
                $templates[] = TemplateOverride::fromVersions($row['file'], $this->reader->text($row['theme_version'] ?? null), $this->reader->text($row['core_version'] ?? null));
            }
        }
        usort($templates, static fn (TemplateOverride $a, TemplateOverride $b): int => $a->file <=> $b->file);

        return new WooCommerceState(
            $version,
            $this->reader->text($wc['db_version'] ?? null),
            is_bool($wc['db_update_needed'] ?? null) ? $wc['db_update_needed'] : null,
            is_array($wc['overridden_templates'] ?? null) ? $templates : null,
        );
    }

    /**
     * @param  array<mixed>  $data
     */
    private function cron(array $data, int $now): CronState
    {
        $now = is_int($data['now'] ?? null) ? $data['now'] : $now;
        $overdue = 0;
        $oldest = null;
        foreach (is_array($data['cron_overdue'] ?? null) ? $data['cron_overdue'] : [] as $entry) {
            if (! is_array($entry) || ! is_int($entry[0] ?? null) || ! is_int($entry[1] ?? null) || $now - $entry[0] < $this->cronOverdueAfterSeconds) {
                continue;
            }
            $overdue += $entry[1];
            $oldest = max($oldest ?? 0, $now - $entry[0]);
        }

        return new CronState(($data['cron_disabled'] ?? false) === true, $overdue, $oldest);
    }

    /**
     * @param  array<mixed>  $data
     */
    private function maintenance(array $data, int $now): bool
    {
        $now = is_int($data['now'] ?? null) ? $data['now'] : $now;
        $maintenance = is_array($data['maintenance'] ?? null) ? $data['maintenance'] : [];
        $upgrading = $maintenance['upgrading'] ?? null;

        // Like wp_is_maintenance_mode(): a file without a recent timestamp is ignored.
        return ($maintenance['file'] ?? false) === true && is_int($upgrading) && $now - $upgrading < $this->maintenanceTtlSeconds;
    }
}
