<?php

declare(strict_types=1);

namespace App\Domain\Inventory;

use App\Enums\RunStatus;

/**
 * What `wpup inventory` reports about one site, read from the snapshot of its run.
 * Every field but the identity is null/empty when the run produced no snapshot.
 */
final readonly class InventoryRow
{
    /** What the terminal and the CSV show when the environment could not be judged. */
    public const string NOT_EVALUATED = 'no avaluat';

    /**
     * @param  list<string>  $phpWarnings
     * @param  list<string>  $externalBackupWarnings  ExternalBackupStatus warnings (UZEROWPUP-133)
     * @param  list<string>  $externalBackupDetectionWarnings  why the detection could not read something
     * @param  list<string>  $environmentWarnings  FutureMinimums warnings (UZEROWPUP-52)
     */
    public function __construct(
        public string $site,
        public string $url,
        public int $runId,
        public RunStatus $runStatus,
        public ?string $failure,
        public ?string $core,
        public ?string $woocommerce,
        public ?string $php,
        public ?string $phpSource,
        public array $phpWarnings,
        public ?string $dbServer,
        public ?int $pendingUpdates,
        public ?int $unlicensed,
        public bool $maintenance,
        public bool $wcDbUpdateNeeded,
        public int $outdatedTemplates,
        public bool $blocked,
        public ?string $blockedReason,
        public array $externalBackupWarnings = [],
        public array $externalBackupDetectionWarnings = [],
        public array $environmentWarnings = [],
        /** False when a version could not be read (or there is no snapshot): an empty list then means «not evaluated». */
        public bool $environmentEvaluated = false,
    ) {}

    public function hasSnapshot(): bool
    {
        return $this->core !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'site' => $this->site,
            'url' => $this->url,
            'run' => $this->runId,
            'status' => $this->runStatus->value,
            'failure' => $this->failure,
            'core' => $this->core,
            'woocommerce' => $this->woocommerce,
            'php' => ['version' => $this->php, 'source' => $this->phpSource, 'warnings' => $this->phpWarnings],
            'db_server' => $this->dbServer,
            'pending_updates' => $this->pendingUpdates,
            'unlicensed' => $this->unlicensed,
            'state' => [
                'maintenance' => $this->maintenance,
                'wc_db_update_needed' => $this->wcDbUpdateNeeded,
                'outdated_templates' => $this->outdatedTemplates,
            ],
            'blocked' => $this->blocked,
            'blocked_reason' => $this->blockedReason,
            'external_backup_warnings' => $this->externalBackupWarnings,
            'external_backup_detection_warnings' => $this->externalBackupDetectionWarnings,
            'environment_warnings' => $this->environmentWarnings,
            'environment_evaluated' => $this->environmentEvaluated,
        ];
    }

    /**
     * @return list<string|int|null> in InventoryReport::CSV_COLUMNS order
     */
    public function toCsv(): array
    {
        return [
            $this->site, $this->url, $this->core, $this->woocommerce, $this->php, $this->phpSource, $this->dbServer,
            $this->pendingUpdates, $this->unlicensed, $this->blocked ? 'yes' : 'no', $this->blockedReason, $this->runStatus->value, $this->failure,
            implode(' ', $this->externalBackupWarnings),
            $this->environmentEvaluated ? implode(' ', $this->environmentWarnings) : self::NOT_EVALUATED,
        ];
    }
}
