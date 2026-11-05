<?php

declare(strict_types=1);

namespace App\Domain\Backups;

use App\Enums\BackupKind;
use App\Models\Run;

/**
 * What a restore run restores (UZEROWPUP-46), as the command validated it and
 * as the run carries it in its flags: the restore point (key directory), the
 * chosen `backups` row of each kind, and whether the operator accepted losing
 * the data a shop's database got since the copy.
 */
final readonly class RestoreRequest
{
    /** Run flag with the key directory of the point; RestorePointRetention holds it while the run is unfinished. */
    public const string POINT_FLAG = 'restore_point';

    public const string BACKUPS_FLAG = 'restore_backups';

    public const string ACCEPT_DATA_LOSS_FLAG = 'accept_data_loss';

    /**
     * @param  array<string, int>  $backupIds  BackupKind value => backups.id
     */
    public function __construct(
        public string $directory,
        public array $backupIds,
        public bool $acceptDataLoss,
    ) {}

    /**
     * The files first, then the database: the code a database restore runs with is already the copy's.
     *
     * @return list<BackupKind>
     */
    public function kinds(): array
    {
        return array_values(array_filter([BackupKind::Files, BackupKind::Database], fn (BackupKind $k): bool => isset($this->backupIds[$k->value])));
    }

    public function restoresDatabase(): bool
    {
        return isset($this->backupIds[BackupKind::Database->value]);
    }

    /**
     * @return array{restore_point: string, restore_backups: array<string, int>, accept_data_loss: bool}
     */
    public function flags(): array
    {
        return [self::POINT_FLAG => $this->directory, self::BACKUPS_FLAG => $this->backupIds, self::ACCEPT_DATA_LOSS_FLAG => $this->acceptDataLoss];
    }

    /**
     * @throws RestoreRefused when the run does not carry a valid request
     */
    public static function fromRun(Run $run): self
    {
        $directory = $run->flags[self::POINT_FLAG] ?? null;
        $ids = $run->flags[self::BACKUPS_FLAG] ?? null;
        $backupIds = [];
        foreach (is_array($ids) ? $ids : [] as $kind => $id) {
            if (is_string($kind) && BackupKind::tryFrom($kind) !== null && is_int($id)) {
                $backupIds[$kind] = $id;
            }
        }
        if (! is_string($directory) || $directory === '' || $backupIds === [] || ! is_array($ids) || count($backupIds) !== count($ids)) {
            throw new RestoreRefused("L'execució no indica què s'ha de restaurar.");
        }

        return new self($directory, $backupIds, ($run->flags[self::ACCEPT_DATA_LOSS_FLAG] ?? false) === true);
    }
}
