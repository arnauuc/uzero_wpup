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
        {--limit= : Màxim de còpies a mostrar (per defecte, el de la configuració)}
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

        $limit = $this->limit();
        if ($limit === null) {
            return self::FAILURE;
        }

        $listing = app(BackupListing::class);
        $backups = $listing->list($filter, $limit);
        $truncated = $backups->count() > $limit;
        $backups = $backups->take($limit);

        if ($this->wantsJson()) {
            $this->emitJson($listing->document($backups, $truncated));

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
            $b->deleted_at !== null ? 'esborrada' : ($b->expires_at?->format(BackupListing::dateFormat()) ?? '—'),
            $b->protected ? 'sí' : 'no',
        ])->all());
        if ($truncated) {
            $this->warn("N'hi ha més de {$limit}: afina els filtres o puja --limit.");
        }

        return self::SUCCESS;
    }

    private function limit(): ?int
    {
        $value = $this->stringOption('limit') ?? config('wpup.backups.list_limit');
        $value = is_int($value) ? (string) $value : $value;
        if (! is_string($value) || ! ctype_digit($value) || (int) $value < 1) {
            $this->error('--limit ha de ser un enter positiu.');

            return null;
        }

        return (int) $value;
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
