<?php

declare(strict_types=1);

namespace App\Domain\Inventory;

use App\Domain\Runs\ReportVersion;
use App\Domain\Sites\BlockOutcome;
use App\Models\Run;
use App\Models\RunStep;
use App\Models\SiteSnapshot;
use RuntimeException;

/**
 * Turns finished inventory runs into rows for the terminal, JSON and CSV
 * (UZEROWPUP-35). It reads the snapshot of each run, never "the latest one".
 */
final class InventoryReport
{
    public const array CSV_COLUMNS = [
        'site', 'url', 'core', 'woocommerce', 'php', 'php_source', 'db_server',
        'pending_updates', 'unlicensed', 'blocked', 'blocked_reason', 'status', 'failure',
    ];

    /** Cells starting with one of these would be run as a formula by a spreadsheet. */
    private const string FORMULA_PREFIXES = "=+-@\t\r";

    /**
     * @param  list<Run>  $runs  finished runs
     * @return list<InventoryRow>
     */
    public function rows(array $runs): array
    {
        $ids = array_map(static fn (Run $run): int => $run->id, $runs);
        // Loaded at once, not per row.
        $loaded = Run::query()->whereKey($ids)->with('site')->get()->keyBy('id');
        $snapshots = SiteSnapshot::query()->whereIn('run_id', $ids)->get()->keyBy('run_id');
        $steps = RunStep::query()->whereIn('run_id', $ids)->where('name', InventoryStep::NAME)->get()->keyBy('run_id');

        $rows = [];
        foreach ($runs as $run) {
            $fresh = $loaded->get($run->id) ?? $run;
            $rows[] = $this->row($fresh, $snapshots->get($run->id), $steps->get($run->id));
        }

        return $rows;
    }

    /**
     * @param  list<InventoryRow>  $rows
     * @return array<string, mixed>
     */
    public function document(array $rows): array
    {
        return [
            'version' => ReportVersion::read('wpup.inventory.report_version'),
            'sites' => array_map(static fn (InventoryRow $row): array => $row->toArray(), $rows),
        ];
    }

    /**
     * @param  list<InventoryRow>  $rows
     */
    public function csv(array $rows): string
    {
        $handle = fopen('php://memory', 'w+');
        if ($handle === false) {
            throw new RuntimeException('Cannot open a memory stream.');
        }

        fputcsv($handle, self::CSV_COLUMNS, ',', '"', '');
        foreach ($rows as $row) {
            fputcsv($handle, array_map(self::cell(...), $row->toCsv()), ',', '"', '');
        }
        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }

    private static function cell(string|int|null $value): string|int
    {
        if (! is_string($value)) {
            return $value ?? '';
        }

        $value = str_replace(["\r\n", "\r", "\n"], ' ', $value);

        return $value !== '' && str_contains(self::FORMULA_PREFIXES, $value[0]) ? "'".$value : $value;
    }

    private function row(Run $run, ?SiteSnapshot $snapshot, ?RunStep $step): InventoryRow
    {
        $site = $run->site;
        $siteBlock = $site?->blocked_reason;
        $failure = $run->failureReason();

        // What the inventory decided about the block. A dry run changes nothing, so
        // it reports what the real one would do; otherwise the site's state is the truth.
        $block = self::array(self::array($step?->result)['block'] ?? null);
        $outcome = $block['outcome'] ?? null;
        $blockedReason = $siteBlock;
        if (($run->flags['dry_run'] ?? false) === true) {
            if ($outcome === BlockOutcome::WouldBlock->value && is_string($block['reason'] ?? null)) {
                $blockedReason = $block['reason'];
            } elseif ($outcome === BlockOutcome::WouldUnblock->value) {
                $blockedReason = null;
            }
        }
        $name = $site->name ?? '(sense lloc)';
        $url = $site->url ?? '';

        if ($snapshot === null) {
            return new InventoryRow($name, $url, $run->id, $run->status, $failure ?? "L'execució no ha deixat cap inventari.",
                null, null, null, null, [], null, null, null, false, false, 0, $blockedReason !== null, $blockedReason);
        }

        $state = self::array($snapshot->environment['state'] ?? null);
        $woocommerce = self::array($state['woocommerce'] ?? null);
        $templates = self::array($woocommerce['overridden_templates'] ?? null);
        $php = self::array($snapshot->environment['php'] ?? null);

        return new InventoryRow(
            $name, $url, $run->id, $run->status, $failure,
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
            $blockedReason !== null,
            $blockedReason,
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
     * @return array<mixed>
     */
    private static function array(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }
}
