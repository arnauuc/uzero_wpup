<?php

declare(strict_types=1);

namespace App\Console\Commands\Sites;

use App\Domain\Sites\HostingPlacement;
use App\Enums\HostingPanel;
use App\Enums\SiteDriverType;
use App\Enums\UpdateStrategy;
use App\Models\HostingServer;
use BackedEnum;
use DomainException;

final class SiteAddCommand extends SiteCommand
{
    protected $signature = 'site:add
        {name : Nom del lloc (minúscules, xifres, punts i guions)}
        {--server= : Servidor d\'hosting (existent, o nou si s\'indica --hostname)}
        {--hostname= : Nom de host del servidor nou}
        {--ssh-port= : Port SSH del servidor nou (22 per defecte)}
        {--panel= : Tauler del servidor nou: plesk o other}
        {--account= : Usuari de sistema del compte d\'hosting}
        {--driver= : Driver del compte nou: ssh o agent}'
        .self::SITE_OPTIONS.self::DRY_RUN.self::JSON;

    protected $description = 'Dona d\'alta un lloc i el seu perfil (només admin; sense credencials)';

    protected function perform(): int
    {
        $name = $this->stringArgument('name');

        try {
            $interactive = $this->input->isInteractive() && ! $this->wantsJson();
            $strategy = null;
            if ($this->stringOption('strategy') === null && $interactive) {
                $choice = $this->choice('Estratègia', array_column(UpdateStrategy::cases(), 'value'), UpdateStrategy::InPlace->value);
                $strategy = UpdateStrategy::tryFrom(is_string($choice) ? $choice : '');
            }
            $input = $this->siteInput(
                $this->stringOption('url') ?? ($interactive ? $this->askText('URL del lloc (https://…)') : null),
                $this->stringOption('wp-path') ?? ($interactive ? $this->askText('Camí de WordPress al servidor', '/var/www/html') : null),
                $strategy,
            );

            $serverName = $this->stringOption('server') ?? ($interactive ? $this->askText('Servidor d\'hosting') : null);
            $account = $this->stringOption('account') ?? ($interactive ? $this->askText('Usuari de sistema del compte') : null);
            if ($serverName === null || $account === null) {
                throw new DomainException('Cal indicar --server i --account.');
            }
            $hostname = $this->stringOption('hostname');
            if ($hostname === null && $interactive && ! HostingServer::query()->where('name', $serverName)->exists()) {
                $hostname = $this->askText("El servidor «{$serverName}» és nou. Nom de host");
            }

            $placement = new HostingPlacement(
                server: $serverName,
                account: $account,
                hostname: $hostname,
                sshPort: $this->intOption('ssh-port'),
                panel: $this->enumOption(HostingPanel::class, 'panel'),
                driver: $this->enumOption(SiteDriverType::class, 'driver'),
            );

            $summary = $this->administration()->add($this->actor(), $name, $input, $placement, $this->isDryRun());
        } catch (DomainException $e) {
            return $this->reportValidation($e);
        }

        if ($this->wantsJson()) {
            $this->emitJson(['dry_run' => $this->isDryRun(), 'site' => $summary]);

            return self::SUCCESS;
        }

        $this->info($this->isDryRun()
            ? "[dry-run] Es donaria d'alta el lloc «{$name}»:"
            : "Lloc «{$name}» donat d'alta.");
        $this->table(['Camp', 'Valor'], $this->rows($summary));
        if (! $this->isDryRun()) {
            $this->line('Les credencials s\'afegeixen a part (ssh-key:*, secrets:*) i la clau del servidor amb site:test.');
        }

        return self::SUCCESS;
    }

    private function askText(string $question, ?string $default = null): ?string
    {
        $answer = $this->ask($question, $default);

        return is_string($answer) && $answer !== '' ? $answer : null;
    }

    private function intOption(string $name): ?int
    {
        $value = $this->stringOption($name);
        if ($value === null) {
            return null;
        }

        return ctype_digit($value) ? (int) $value : throw new DomainException("--{$name} ha de ser un nombre.");
    }

    /**
     * @template T of BackedEnum
     *
     * @param  class-string<T>  $enum
     * @return T|null
     */
    private function enumOption(string $enum, string $name): ?BackedEnum
    {
        $value = $this->stringOption($name);
        if ($value === null) {
            return null;
        }

        return $enum::tryFrom($value) ?? throw new DomainException("Valor no vàlid per a --{$name}: «{$value}».");
    }
}
