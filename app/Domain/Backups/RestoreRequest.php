<?php

declare(strict_types=1);

namespace App\Domain\Backups;

use App\Enums\BackupKind;
use App\Models\Run;

/**
 * What a restore run restores (UZEROWPUP-46), as the command validated it and
 * as the run carries it in its flags: the restore point (key directory), the
 * candidate `backups` rows of each kind in order of preference (the
 * site-local copy first; RestoreCheckStep takes the first that still holds
 * its bytes), and what the operator explicitly accepted.
 */
final readonly class RestoreRequest
{
    /** Run flag with the key directory of the point; RestorePointRetention holds it while the run is unfinished. */
    public const string POINT_FLAG = 'restore_point';

    public const string BACKUPS_FLAG = 'restore_backups';

    public const string ACCEPT_DATA_LOSS_FLAG = 'accept_data_loss';

    /** The operator typed the site's name after being told a shop's data would be lost (never --force). */
    public const string DATA_LOSS_CONFIRMED_FLAG = 'data_loss_confirmed';

    /** Restore even if the point of the current state cannot be made (broken site; interactive only). */
    public const string WITHOUT_PRE_BACKUP_FLAG = 'without_pre_backup';

    /**
     * @param  array<string, list<int>>  $candidates  BackupKind value => backups.id, preferred first
     */
    public function __construct(
        public string $directory,
        public array $candidates,
        public bool $acceptDataLoss = false,
        public bool $dataLossConfirmed = false,
        public bool $withoutPreBackup = false,
    ) {}

    /**
     * The files first, then the database: the code a database restore runs with is already the copy's.
     *
     * @return list<BackupKind>
     */
    public function kinds(): array
    {
        return array_values(array_filter([BackupKind::Files, BackupKind::Database], fn (BackupKind $k): bool => isset($this->candidates[$k->value])));
    }

    public function restoresDatabase(): bool
    {
        return isset($this->candidates[BackupKind::Database->value]);
    }

    public function with(bool $dataLossConfirmed, bool $withoutPreBackup): self
    {
        return new self($this->directory, $this->candidates, $this->acceptDataLoss, $dataLossConfirmed, $withoutPreBackup);
    }

    /**
     * @return array<string, mixed>
     */
    public function flags(): array
    {
        return [
            self::POINT_FLAG => $this->directory,
            self::BACKUPS_FLAG => $this->candidates,
            self::ACCEPT_DATA_LOSS_FLAG => $this->acceptDataLoss,
            self::DATA_LOSS_CONFIRMED_FLAG => $this->dataLossConfirmed,
            self::WITHOUT_PRE_BACKUP_FLAG => $this->withoutPreBackup,
        ];
    }

    /**
     * @throws RestoreRefused when the run does not carry a valid request
     */
    public static function fromRun(Run $run): self
    {
        $directory = $run->flags[self::POINT_FLAG] ?? null;
        $raw = $run->flags[self::BACKUPS_FLAG] ?? null;
        $candidates = [];
        $valid = is_array($raw) && $raw !== [];
        foreach (is_array($raw) ? $raw : [] as $kind => $ids) {
            $list = is_array($ids) ? array_values(array_filter($ids, is_int(...))) : [];
            if (! is_string($kind) || BackupKind::tryFrom($kind) === null || $list === [] || count($list) !== count((array) $ids)) {
                $valid = false;

                continue;
            }
            $candidates[$kind] = $list;
        }
        if (! is_string($directory) || $directory === '' || ! $valid) {
            throw new RestoreRefused("L'execució no indica què s'ha de restaurar.");
        }

        return new self(
            $directory,
            $candidates,
            ($run->flags[self::ACCEPT_DATA_LOSS_FLAG] ?? false) === true,
            ($run->flags[self::DATA_LOSS_CONFIRMED_FLAG] ?? false) === true,
            ($run->flags[self::WITHOUT_PRE_BACKUP_FLAG] ?? false) === true,
        );
    }
}
