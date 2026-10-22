<?php

declare(strict_types=1);

namespace App\Console\Commands\Sites;

use App\Domain\Sites\SiteInput;
use App\Enums\UpdateStrategy;
use App\Models\Site;
use DomainException;

final class SiteEditCommand extends SiteCommand
{
    protected $signature = 'site:edit
        {name : Nom del lloc}
        {--activate : Reactiva el lloc}
        {--deactivate : Desactiva el lloc}'
        .self::SITE_OPTIONS.self::DRY_RUN.self::JSON;

    protected $description = 'Edita un lloc i el seu perfil: només canvia el que s\'indica (només admin)';

    protected function perform(): int
    {
        $site = Site::query()->where('name', $this->stringArgument('name'))->first();
        if ($site === null) {
            $this->error('El lloc «'.$this->stringArgument('name').'» no existeix.');

            return self::FAILURE;
        }

        try {
            $input = $this->siteInput();
            $clear = $this->clearFields();

            if ($this->isEmpty($input) && $clear === [] && $this->input->isInteractive() && ! $this->wantsJson()) {
                $input = $this->askChanges($site);
            }
            if ($this->isEmpty($input) && $clear === []) {
                throw new DomainException('No has indicat cap canvi.');
            }

            $changes = $this->administration()->edit($this->actor(), $site, $input, $clear, $this->isDryRun());
        } catch (DomainException $e) {
            return $this->reportValidation($e);
        }

        if ($this->wantsJson()) {
            $this->emitJson(['dry_run' => $this->isDryRun(), 'site' => $site->name, 'changes' => $changes]);

            return self::SUCCESS;
        }

        if ($changes === []) {
            $this->info("El lloc «{$site->name}» ja té aquests valors: no hi ha canvis.");

            return self::SUCCESS;
        }

        $this->info($this->isDryRun() ? "[dry-run] Es canviaria el lloc «{$site->name}»:" : "Lloc «{$site->name}» actualitzat.");
        $this->table(['Camp', 'Abans', 'Ara'], array_map(
            fn (string $field, array $change): array => [$field, $this->display($change['from']), $this->display($change['to'])],
            array_keys($changes),
            array_values($changes),
        ));

        return self::SUCCESS;
    }

    private function askChanges(Site $site): SiteInput
    {
        $url = $this->ask('URL', $site->url);
        $path = $this->ask('Camí de WordPress', $site->wp_path);
        $strategy = $this->choice('Estratègia', ['in_place', 'staging_first'], $site->strategy->value);

        return $this->siteInput(
            is_string($url) ? $url : null,
            is_string($path) ? $path : null,
            UpdateStrategy::tryFrom(is_string($strategy) ? $strategy : ''),
        );
    }

    private function isEmpty(SiteInput $input): bool
    {
        return array_filter((array) $input, static fn (mixed $value): bool => $value !== null) === [];
    }
}
