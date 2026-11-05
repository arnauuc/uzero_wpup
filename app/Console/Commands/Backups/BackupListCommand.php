<?php

declare(strict_types=1);

namespace App\Console\Commands\Backups;

use App\Console\WpupCommand;
use App\Domain\Backups\BackupListing;
use App\Domain\Backups\BackupListingFilter;
use App\Domain\Backups\ByteSize;
use App\Domain\Identity\Ability;
use App\Models\Backup;
use InvalidArgumentException;

/**
 * Registered backup files (UZEROWPUP-45). Read only.
 */
final class BackupListCommand extends WpupCommand
{
    protected $signature = 'backup:list
        {--site=* : Només les còpies d\'aquest lloc (repetible)}
        {--target=* : Només les còpies d\'aquesta destinació (repetible)}
        {--since= : Creades a partir d\'aquesta data (AAAA-MM-DD)}
        {--until= : Creades fins a aquesta data inclosa (AAAA-MM-DD)}
        {--kind= : Només aquest tipus de fitxer (database o files)}
        {--all : Inclou també les còpies esborrades}
        '.self::JSON;

    protected $description = 'Llista les còpies registrades, amb mida, SHA-256 i caducitat';

    protected function ability(): Ability
    {
        return Ability::ViewReports;
    }

    protected function perform(): int
    {
        try {
            $filter = BackupListingFilter::fromInput(
                $this->repeatedOption('site'),
                $this->repeatedOption('target'),
                $this->stringOption('since'),
                $this->stringOption('until'),
                $this->stringOption('kind'),
                $this->option('all') === true,
            );
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $listing = app(BackupListing::class);
        $backups = $listing->list($filter);

        if ($this->wantsJson()) {
            $this->emitJson($listing->document($backups));

            return self::SUCCESS;
        }

        if ($backups->isEmpty()) {
            $this->line('No hi ha cap còpia amb aquests filtres.');

            return self::SUCCESS;
        }

        $this->table(['#', 'Lloc', 'Execució', 'Tipus', 'Destinació', 'Clau', 'Mida', 'SHA-256', 'Verificat', 'Caduca', 'Protegida'], $backups->map(static fn (Backup $b): array => [
            $b->id,
            (string) $b->site?->name,
            $b->run_id === null ? '—' : "#{$b->run_id}",
            $b->kind->value,
            (string) $b->backupTarget?->name,
            $b->path,
            ByteSize::human($b->size_bytes),
            substr($b->sha256, 0, 12),
            $b->verified_at === null ? 'no' : 'sí',
            $b->deleted_at !== null ? 'esborrada' : ($b->expires_at?->format('Y-m-d H:i') ?? '—'),
            $b->protected ? 'sí' : 'no',
        ])->all());

        return self::SUCCESS;
    }

    /**
     * @return list<string>
     */
    private function repeatedOption(string $name): array
    {
        $values = $this->option($name);

        return is_array($values) ? array_values(array_filter($values, is_string(...))) : [];
    }
}
