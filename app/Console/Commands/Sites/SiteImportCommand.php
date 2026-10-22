<?php

declare(strict_types=1);

namespace App\Console\Commands\Sites;

use App\Console\ChangesState;
use App\Console\WpupCommand;
use App\Domain\Identity\Ability;
use App\Domain\Sites\Import\ImportAction;
use App\Domain\Sites\Import\ImportFormat;
use App\Domain\Sites\Import\SiteImporter;
use App\Domain\Sites\Import\SiteImportOutcome;
use App\Domain\Sites\SiteValidationException;

final class SiteImportCommand extends WpupCommand implements ChangesState
{
    protected $signature = 'site:import
        {file : Fitxer CSV o YAML amb els llocs (vegeu docs/importacio-de-llocs.md)}
        {--format= : csv o yaml (per defecte, segons l\'extensió)}'
        .self::DRY_RUN.self::JSON;

    protected $description = 'Importa llocs en bloc des d\'un CSV o YAML: tot o res, idempotent (només admin; sense credencials)';

    protected function ability(): Ability
    {
        return Ability::ManageSites;
    }

    protected function perform(): int
    {
        $path = $this->stringArgument('file');
        $given = $this->stringOption('format');
        $format = $given === null ? ImportFormat::fromPath($path) : ImportFormat::tryFrom($given);
        if ($format === null) {
            return $this->failWith(['Indica el format amb --format=csv o --format=yaml (o fes servir l\'extensió .csv, .yaml o .yml).']);
        }
        $content = $this->read($path);
        if (is_array($content)) {
            return $this->failWith($content);
        }

        try {
            $result = app(SiteImporter::class)->import($this->actor(), $content, $format, basename($path), $this->isDryRun());
        } catch (SiteValidationException $e) {
            return $this->failWith($e->errors());
        }

        if ($this->wantsJson()) {
            $this->emitJson([
                'dry_run' => $this->isDryRun(),
                'sites' => array_map(static fn (SiteImportOutcome $o): array => [
                    'name' => $o->name, 'action' => $o->action->value, 'fields' => $o->fields,
                ], $result->outcomes),
            ]);

            return self::SUCCESS;
        }

        $labels = [ImportAction::Create->value => 'crear', ImportAction::Update->value => 'actualitzar', ImportAction::Unchanged->value => 'sense canvis'];
        $this->table(['Lloc', 'Acció', 'Canvis'], array_map(static fn (SiteImportOutcome $o): array => [
            $o->name, $labels[$o->action->value], $o->fields === [] ? '—' : implode(', ', $o->fields),
        ], $result->outcomes));

        $summary = $result->count(ImportAction::Create).' per crear, '.$result->count(ImportAction::Update).' per actualitzar, '
            .$result->count(ImportAction::Unchanged).' sense canvis.';
        if ($this->isDryRun()) {
            $this->info("[dry-run] No s'ha escrit res. {$summary}");
        } elseif (! $result->changes()) {
            $this->info('Importació sense canvis: tots els llocs ja tenen aquests valors.');
        } else {
            $this->info('Importació acabada. '.str_replace(['per crear', 'per actualitzar'], ['creats', 'actualitzats'], $summary));
            $this->line('Les credencials s\'afegeixen a part (ssh-key:*, secrets:*) i la clau del servidor amb site:test.');
        }

        return self::SUCCESS;
    }

    /**
     * @return string|list<string> The content, or the errors.
     */
    private function read(string $path): string|array
    {
        if (! is_file($path) || ! is_readable($path)) {
            return ["No es pot llegir el fitxer «{$path}»."];
        }
        $max = config('wpup.sites.import_max_bytes');
        if (is_int($max) && filesize($path) > $max) {
            return ["El fitxer és massa gran (màxim {$max} bytes)."];
        }
        $content = file_get_contents($path);

        return $content === false ? ["No es pot llegir el fitxer «{$path}»."] : $content;
    }

    /**
     * @param  list<string>  $errors
     */
    private function failWith(array $errors): int
    {
        if ($this->wantsJson()) {
            $this->emitJson(['errors' => $errors]);
        } else {
            foreach ($errors as $error) {
                $this->error($error);
            }
            $this->error('No s\'ha escrit res.');
        }

        return self::FAILURE;
    }
}
