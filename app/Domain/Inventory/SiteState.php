<?php

declare(strict_types=1);

namespace App\Domain\Inventory;

use App\Domain\Drivers\Data\CommandResult;
use App\Domain\Drivers\Data\InstalledComponents;
use App\Domain\Drivers\Exceptions\ConnectionFailed;
use App\Domain\Drivers\Exceptions\DriverException;
use App\Domain\Drivers\SiteDriver;
use App\Domain\Secrets\SecretRedactor;
use App\Enums\ComponentType;

/**
 * Pre-update state of a site (UZEROWPUP-33), read only through the driver:
 * WooCommerce (DB version, pending DB update, theme templates that override
 * WooCommerce's), WP-Cron (disabled, overdue events), maintenance mode and
 * free disk space.
 *
 * WooCommerce is read with one constant `wp eval` snippet (no interpolation).
 * WooCommerce has no public API for template overrides (its system status
 * report builds them inline), so the snippet mirrors that logic: every file of
 * WooCommerce's templates/ that exists in the active or parent theme, directly
 * or under woocommerce/, compared by its `@version` header. A template is
 * outdated when WooCommerce's version is newer than the theme's (or the theme
 * has none). Cron uses `wp cron event list` (next_run_gmt is UTC); WP-Cron runs
 * on traffic, so an event is overdue only past a configured grace period.
 * `wp maintenance-mode is-active` exits 0 when active and 1 when not: both are
 * answers, any other exit code is a failed read.
 */
final readonly class SiteState
{
    /** Constant on purpose: nothing from the site or the operator is interpolated. */
    public const string WOOCOMMERCE_SNIPPET = <<<'PHP'
if (!function_exists('WC')) { echo json_encode(null); return; }
$base = WC()->plugin_path() . '/templates/';
$version = function ($file) {
    $data = @file_get_contents($file, false, null, 0, 8192);
    if ($data === false) { return ''; }
    return preg_match('/^[ \t\/*#@]*@version(.*)$/mi', str_replace("\r", "\n", $data), $m) ? trim($m[1]) : '';
};
$files = array('content-product_cat.php', 'taxonomy-product_cat.php', 'taxonomy-product_tag.php');
if (is_dir($base)) {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $item) { if ($item->isFile()) { $files[] = substr($item->getPathname(), strlen($base)); } }
}
$dirs = array(get_stylesheet_directory(), get_stylesheet_directory() . '/' . rtrim(WC()->template_path(), '/'), get_template_directory(), get_template_directory() . '/' . rtrim(WC()->template_path(), '/'));
$overridden = array();
foreach (array_unique($files) as $file) {
    foreach ($dirs as $dir) {
        $path = $dir . '/' . $file;
        if (!is_file($path)) { continue; }
        $core = (strpos($file, '-product_cat') !== false || strpos($file, '-product_tag') !== false) ? str_replace('_', '-', $file) : $file;
        $overridden[] = array('file' => str_replace(WP_CONTENT_DIR . '/themes/', '', $path), 'theme_version' => $version($path), 'core_version' => $version($base . $core));
        break;
    }
}
$dbVersion = get_option('woocommerce_db_version');
echo json_encode(array(
    'db_version' => $dbVersion === false ? null : (string) $dbVersion,
    'db_update_needed' => class_exists('WC_Install') && method_exists('WC_Install', 'needs_db_update') ? (bool) WC_Install::needs_db_update() : false,
    'overridden_templates' => $overridden,
));
PHP;

    public const string CRON_DISABLED_SNIPPET = 'echo defined("DISABLE_WP_CRON") && DISABLE_WP_CRON ? "1" : "0";';

    public function __construct(
        private SecretRedactor $redactor,
        private int $commandTimeoutSeconds,
        private int $cronOverdueAfterSeconds,
    ) {}

    /**
     * @param  int  $now  unix time, to judge which cron events are overdue
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
            $warnings[] = $this->redactor->redact("No s'ha pogut llegir l'espai lliure al disc: {$e->getMessage()}");
        }

        return new SiteStateResult(
            $this->woocommerce($driver, $installed, $warnings),
            $this->cron($driver, $now, $warnings),
            $this->maintenance($driver, $warnings),
            $diskFree,
            $warnings,
        );
    }

    /**
     * @param  list<string>  $warnings
     * @return array{version: string, db_version: ?string, db_update_needed: bool, overridden_templates: list<array{file: string, theme_version: ?string, core_version: ?string, outdated: bool}>}|null
     */
    private function woocommerce(SiteDriver $driver, InstalledComponents $installed, array &$warnings): ?array
    {
        $plugin = null;
        foreach ($installed->components as $component) {
            if ($component->type === ComponentType::Woocommerce && $component->active) {
                $plugin = $component;
            }
        }
        if ($plugin === null) {
            return null;
        }

        $data = $this->json($this->run($driver, ['eval', self::WOOCOMMERCE_SNIPPET], 'l\'estat de WooCommerce', $warnings), "l'estat de WooCommerce", $warnings);
        if ($data === null) {
            return ['version' => $plugin->version, 'db_version' => null, 'db_update_needed' => false, 'overridden_templates' => []];
        }

        $templates = [];
        foreach (is_array($data['overridden_templates'] ?? null) ? $data['overridden_templates'] : [] as $row) {
            if (! is_array($row) || ! is_string($row['file'] ?? null)) {
                continue;
            }
            $theme = $this->text($row['theme_version'] ?? null);
            $core = $this->text($row['core_version'] ?? null);
            $templates[] = [
                'file' => $row['file'],
                'theme_version' => $theme,
                'core_version' => $core,
                'outdated' => $core !== null && ($theme === null || version_compare($theme, $core, '<')),
            ];
        }
        usort($templates, static fn (array $a, array $b): int => $a['file'] <=> $b['file']);

        return [
            'version' => $plugin->version,
            'db_version' => $this->text($data['db_version'] ?? null),
            'db_update_needed' => ($data['db_update_needed'] ?? false) === true,
            'overridden_templates' => $templates,
        ];
    }

    /**
     * @param  list<string>  $warnings
     * @return array{disabled: bool, overdue_events: int, oldest_overdue_seconds: ?int}|null
     */
    private function cron(SiteDriver $driver, int $now, array &$warnings): ?array
    {
        $disabled = $this->run($driver, ['eval', self::CRON_DISABLED_SNIPPET], 'si WP-Cron està desactivat', $warnings);
        $events = $this->json($this->run($driver, ['cron', 'event', 'list', '--fields=hook,next_run_gmt', '--format=json'], 'els esdeveniments de cron', $warnings), 'els esdeveniments de cron', $warnings);
        if ($disabled === null || $events === null) {
            return null;
        }

        $overdue = 0;
        $oldest = null;
        foreach ($events as $event) {
            $due = is_array($event) && is_string($event['next_run_gmt'] ?? null) ? strtotime($event['next_run_gmt'].' UTC') : false;
            if ($due === false || $now - $due < $this->cronOverdueAfterSeconds) {
                continue;
            }
            $overdue++;
            $oldest = max($oldest ?? 0, $now - $due);
        }

        return ['disabled' => trim($disabled->stdout) === '1', 'overdue_events' => $overdue, 'oldest_overdue_seconds' => $oldest];
    }

    /**
     * @param  list<string>  $warnings
     */
    private function maintenance(SiteDriver $driver, array &$warnings): ?bool
    {
        $result = $this->run($driver, ['maintenance-mode', 'is-active'], 'el mode manteniment', $warnings, exitCodes: [0, 1]);

        return $result === null ? null : $result->exitCode === 0;
    }

    /**
     * @param  list<string>  $args
     * @param  list<string>  $warnings
     * @param  list<int>  $exitCodes  exit codes that are answers, not failures
     */
    private function run(SiteDriver $driver, array $args, string $what, array &$warnings, array $exitCodes = [0]): ?CommandResult
    {
        try {
            $result = $driver->wp($args, $this->commandTimeoutSeconds);
        } catch (ConnectionFailed $e) {
            throw $e;
        } catch (DriverException $e) {
            $warnings[] = $this->redactor->redact("No s'ha pogut llegir {$what}: {$e->getMessage()}");

            return null;
        }

        if (! in_array($result->exitCode, $exitCodes, true)) {
            $warnings[] = $this->redactor->redact("No s'ha pogut llegir {$what} (codi {$result->exitCode}): ".trim($result->stderr));

            return null;
        }

        return $result;
    }

    /**
     * Last non-empty output line as JSON: plugins may print notices before it.
     *
     * @param  list<string>  $warnings
     * @return array<mixed>|null
     */
    private function json(?CommandResult $result, string $what, array &$warnings): ?array
    {
        if ($result === null) {
            return null;
        }

        $lines = array_values(array_filter(array_map('trim', explode("\n", $result->stdout)), static fn (string $l): bool => $l !== ''));
        $data = $lines === [] ? null : json_decode($lines[count($lines) - 1], true);
        if (! is_array($data)) {
            $warnings[] = "La resposta sobre {$what} no és JSON vàlid.";

            return null;
        }

        return $data;
    }

    private function text(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
