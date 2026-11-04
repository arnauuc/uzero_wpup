<?php

declare(strict_types=1);

namespace App\Domain\Inventory;

use App\Models\Run;
use App\Models\SiteSnapshot;

/**
 * Turns finished inventory runs into rows for the terminal, JSON and CSV
 * (UZEROWPUP-35). It reads the snapshot of each run, never "the latest one".
 */
final class InventoryReport
{
    public const array CSV_COLUMNS = [
        'site', 'url', 'core', 'woocommerce', 'php', 'php_source', 'db_server',
        'pending_updates', 'unlicensed', 'blocked', 'blocked_reason',
    ];

    /** Cells starting with one of these would be run as a formula by a spreadsheet. */
    private const string FORMULA_PREFIXES = "=+-@\t\r";

    /**
     * @param  list<Run>  $runs  finished runs
     * @return list<InventoryRow>
     */
    public function rows(array $runs): array
    {
        return array_map($this->row(...), $runs);
    }

    /**
     * @param  list<InventoryRow>  $rows
     * @return array<string, mixed>
     */
    public function document(array $rows): array
    {
        $version = config('wpup.inventory.report_version');

        return [
            'version' => is_int($version) ? $version : 1,
            'sites' => array_map(static fn (InventoryRow $row): array => $row->toArray(), $rows),
        ];
    }

    /**
     * Refuses to overwrite an existing file.
     *
     * @throws InventoryExportException
     */
    public static function assertWritable(string $path): void
    {
        if (file_exists($path)) {
            throw new InventoryExportException("El fitxer {$path} ja existeix; no se sobreescriu.");
        }
        $directory = dirname($path);
        if (! is_dir($directory) || ! is_writable($directory)) {
            throw new InventoryExportException("No es pot escriure a {$directory}.");
        }
    }

    /**
     * @param  list<InventoryRow>  $rows
     *
     * @throws InventoryExportException
     */
    public function writeCsv(string $path, array $rows): void
    {
        self::assertWritable($path);
        // Mode x: fails instead of overwriting a file created in the meantime.
        $handle = @fopen($path, 'x');
        if ($handle === false) {
            throw new InventoryExportException("No s'ha pogut crear {$path}.");
        }

        try {
            fputcsv($handle, self::CSV_COLUMNS, ',', '"', '');
            foreach ($rows as $row) {
                fputcsv($handle, array_map(self::cell(...), $row->toCsv()), ',', '"', '');
            }
        } finally {
            fclose($handle);
        }
    }

    private static function cell(string|int|null $value): string|int
    {
        if (! is_string($value)) {
            return $value ?? '';
        }

        return $value !== '' && str_contains(self::FORMULA_PREFIXES, $value[0]) ? "'".$value : $value;
    }

    private function row(Run $run): InventoryRow
    {
        $site = $run->site()->firstOrFail();
        $snapshot = SiteSnapshot::query()->where('run_id', $run->id)->first();
        $failure = $run->status->value === 'succeeded' ? null : (is_string($run->result_summary['reason'] ?? null)
            ? $run->result_summary['reason']
            : "L'execució ha acabat com a {$run->status->value}.");

        if ($snapshot === null) {
            return new InventoryRow($site->name, $site->url, $run->id, $run->status->value, $failure ?? "L'execució no ha deixat cap inventari.",
                null, null, null, null, [], null, null, null, false, false, 0, $site->blocked_reason !== null, $site->blocked_reason);
        }

        $state = self::array($snapshot->environment['state'] ?? null);
        $woocommerce = self::array($state['woocommerce'] ?? null);
        $templates = self::array($woocommerce['overridden_templates'] ?? null);
        $php = self::array($snapshot->environment['php'] ?? null);
        $failed = $this->failedPrerequisites($snapshot);
        // A dry run leaves the block of the site as it was: show what it would do.
        $blockedReasons = ($run->flags['dry_run'] ?? false) === true ? $failed : ($site->blocked_reason !== null ? [$site->blocked_reason] : []);

        return new InventoryRow(
            $site->name, $site->url, $run->id, $run->status->value, $failure,
            $snapshot->core_version,
            $this->woocommerceVersion($snapshot),
            $snapshot->php_version,
            $snapshot->php_source->value,
            array_values(array_filter(self::array($php['warnings'] ?? null), is_string(...))),
            $snapshot->db_server_version,
            count($snapshot->pending_updates),
            count(array_filter($snapshot->pending_updates, static fn (mixed $u): bool => is_array($u) && ($u['licensed'] ?? true) === false)),
            ($state['maintenance'] ?? false) === true,
            ($woocommerce['db_update_needed'] ?? false) === true,
            count(array_filter($templates, static fn (mixed $t): bool => is_array($t) && ($t['outdated'] ?? false) === true)),
            $blockedReasons !== [],
            $blockedReasons === [] ? null : implode(' ', $blockedReasons),
        );
    }

    private function woocommerceVersion(SiteSnapshot $snapshot): ?string
    {
        foreach ($snapshot->plugins as $plugin) {
            if (is_array($plugin) && ($plugin['type'] ?? null) === 'woocommerce' && is_string($plugin['version'] ?? null)) {
                return $plugin['version'];
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function failedPrerequisites(SiteSnapshot $snapshot): array
    {
        $reasons = [];
        foreach (self::array($snapshot->environment['prerequisites'] ?? null) as $check) {
            if (is_array($check) && ($check['passed'] ?? true) === false && is_string($check['reason'] ?? null)) {
                $reasons[] = $check['reason'];
            }
        }

        return $reasons;
    }

    /**
     * @return array<mixed>
     */
    private static function array(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }
}
