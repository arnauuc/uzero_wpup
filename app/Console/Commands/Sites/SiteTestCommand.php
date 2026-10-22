<?php

declare(strict_types=1);

namespace App\Console\Commands\Sites;

use App\Console\ChangesState;
use App\Console\Concerns\SelectsSites;
use App\Console\WpupCommand;
use App\Domain\Drivers\Data\ProbeCheck;
use App\Domain\Drivers\Data\ProbeResult;
use App\Domain\Drivers\DriverFactory;
use App\Domain\Drivers\Exceptions\DriverException;
use App\Domain\Drivers\Ssh\HostKeyPinning;
use App\Domain\Identity\Ability;
use App\Enums\CheckResult;
use App\Enums\SiteDriverType;
use App\Models\Site;

/**
 * Connection and prerequisites of each site, without touching WordPress
 * (UZEROWPUP-27). The only thing it may change is pinning the host key of a
 * server contacted for the first time, which an admin must confirm.
 */
final class SiteTestCommand extends WpupCommand implements ChangesState
{
    protected $signature = 'site:test
        '.self::TARGETS.'
        {--trust-host-key= : Empremta (SHA256:…) confirmada per fixar la host key sense preguntar}
        '.self::DRY_RUN.'
        '.self::JSON;

    protected $description = 'Prova la connexió i els prerequisits dels llocs sense modificar-los';

    use SelectsSites;

    protected function ability(): Ability
    {
        return Ability::RunOperations;
    }

    protected function perform(): int
    {
        $sites = $this->selectedSites();
        if ($sites === null) {
            return self::FAILURE;
        }

        $report = [];
        $failed = false;
        foreach ($sites as $site) {
            $result = $this->test($site);
            $failed = $failed || $result->worst() === CheckResult::Error;
            $report[$site->name] = $result;

            if (! $this->wantsJson()) {
                $this->line('');
                $this->line("<options=bold>{$site->name}</> ({$site->url}): ".self::label($result->worst()));
                $this->table(['Comprovació', 'Resultat', 'Detall'], array_map(static fn (ProbeCheck $check): array => [
                    $check->name, self::label($check->result), $check->detail,
                ], $result->checks));
            }
        }

        if ($this->wantsJson()) {
            $this->emitJson(['sites' => array_map(static fn (ProbeResult $result): array => [
                'result' => $result->worst()->value,
                'checks' => array_map(static fn (ProbeCheck $c): array => ['name' => $c->name, 'result' => $c->result->value, 'detail' => $c->detail], $result->checks),
            ], $report)]);
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    private function test(Site $site): ProbeResult
    {
        $account = $site->hostingAccount()->firstOrFail();

        if ($account->driver === SiteDriverType::Ssh && $account->hostingServer()->firstOrFail()->pinned_host_key === null) {
            $pinned = $this->pinHostKey($site);
            if ($pinned !== null) {
                return new ProbeResult([$pinned]);
            }
        }

        try {
            return app(DriverFactory::class)->forSite($site, $this->actor())->probe();
        } catch (DriverException $e) {
            return new ProbeResult([new ProbeCheck('connexió', CheckResult::Error, $e->getMessage())]);
        }
    }

    /**
     * First contact with a server: shows the fingerprint and pins it once an
     * admin confirms. Returns a check only when the test cannot go on.
     */
    private function pinHostKey(Site $site): ?ProbeCheck
    {
        $server = $site->hostingAccount()->firstOrFail()->hostingServer()->firstOrFail();
        $pinning = app(HostKeyPinning::class);

        try {
            $fingerprint = HostKeyPinning::fingerprint($pinning->fetch($server));
        } catch (DriverException $e) {
            return new ProbeCheck('connexió', CheckResult::Error, $e->getMessage());
        }

        $where = "{$server->hostname}:{$server->ssh_port}";
        if (! Ability::ManageSites->allows($this->operator)) {
            return new ProbeCheck('host key', CheckResult::Error, "La host key de {$where} no està fixada ({$fingerprint}). Un admin l'ha de confirmar amb site:test.");
        }
        if ($this->isDryRun()) {
            return new ProbeCheck('host key', CheckResult::Warning, "[dry-run] Es fixaria la host key de {$where}: {$fingerprint}.");
        }

        $confirmed = $this->stringOption('trust-host-key');
        if ($confirmed === null) {
            if (! $this->input->isInteractive()) {
                return new ProbeCheck('host key', CheckResult::Error, "La host key de {$where} no està fixada ({$fingerprint}). Confirma-la amb --trust-host-key={$fingerprint} després de comprovar-la al servidor.");
            }
            $this->warn("Primer contacte amb {$where}. Empremta de la host key: {$fingerprint}");
            $this->line('Comprova-la al servidor amb: ssh-keygen -lf /etc/ssh/ssh_host_ed25519_key.pub');
            if (! $this->confirm('Coincideix i la vols fixar?', false)) {
                return new ProbeCheck('host key', CheckResult::Error, "Host key de {$where} no confirmada; no s'hi connecta.");
            }
            $confirmed = $fingerprint;
        }

        try {
            $pinning->pin($server, $confirmed, $this->actor());
            $site->unsetRelation('hostingAccount');
        } catch (DriverException $e) {
            return new ProbeCheck('host key', CheckResult::Error, $e->getMessage());
        }

        return null;
    }

    private static function label(CheckResult $result): string
    {
        return match ($result) {
            CheckResult::Ok => '<fg=green>ok</>',
            CheckResult::Warning => '<fg=yellow>avís</>',
            CheckResult::Error => '<fg=red>error</>',
        };
    }
}
