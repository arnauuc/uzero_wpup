<?php

declare(strict_types=1);

namespace App\Console\Commands\Sites;

use App\Console\ChangesState;
use App\Console\Concerns\SelectsSites;
use App\Console\WpupCommand;
use App\Domain\Drivers\Data\ProbeCheck;
use App\Domain\Drivers\Data\ProbeResult;
use App\Domain\Drivers\Exceptions\DriverException;
use App\Domain\Drivers\Ssh\HostKeyPinning;
use App\Domain\Identity\Ability;
use App\Domain\Runs\RunFollower;
use App\Domain\Runs\RunLauncher;
use App\Domain\Sites\SiteTestStep;
use App\Enums\CheckResult;
use App\Enums\RunStatus;
use App\Enums\RunType;
use App\Enums\SiteDriverType;
use App\Models\Run;
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
        foreach ($this->test(array_values($sites->all())) as $name => [$site, $result]) {
            $failed = $failed || $result->worst() === CheckResult::Error;
            $report[$name] = $result;

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

    /**
     * Host keys are confirmed here, before queueing (it needs no credential).
     * The probe itself needs the site's key, which only the workers can reveal
     * (ADR-0011): the sites are queued as site_test runs and this waits for them.
     *
     * @param  list<Site>  $sites
     * @return array<string, array{Site, ProbeResult}> in the order given
     */
    private function test(array $sites): array
    {
        $results = [];
        $queue = [];
        foreach ($sites as $site) {
            $account = $site->hostingAccount()->firstOrFail();
            $pinned = $account->driver === SiteDriverType::Ssh && $account->hostingServer()->firstOrFail()->pinned_host_key === null
                ? $this->pinHostKey($site)
                : null;

            $results[$site->name] = [$site, $pinned === null ? null : new ProbeResult([$pinned])];
            if ($pinned === null) {
                $queue[] = $site;
            }
        }

        if ($queue !== []) {
            $runs = app(RunLauncher::class)->launch(RunType::SiteTest, $queue, $this->actor(), ['dry_run' => $this->isDryRun()]);
            $final = app(RunFollower::class)->wait(array_map(static fn (Run $run): int => $run->id, $runs));

            foreach ($final as $run) {
                $site = $run->site()->firstOrFail();
                $results[$site->name] = [$site, self::resultOf($run)];
            }
        }

        return array_map(static fn (array $entry): array => [$entry[0], $entry[1] ?? new ProbeResult([])], $results);
    }

    private static function resultOf(Run $run): ProbeResult
    {
        if ($run->status === RunStatus::Succeeded) {
            $data = $run->steps()->where('name', SiteTestStep::NAME)->first()?->result;

            return SiteTestStep::resultFrom(is_array($data) ? $data : null);
        }

        $reason = is_string($run->result_summary['reason'] ?? null) ? $run->result_summary['reason'] : "L'execució #{$run->id} ha acabat com a {$run->status->value}.";

        return new ProbeResult([new ProbeCheck('execució', CheckResult::Error, $reason)]);
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
