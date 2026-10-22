<?php

declare(strict_types=1);

namespace App\Domain\Sites\Import;

use App\Domain\Audit\AuditActor;
use App\Domain\Audit\AuditLogger;
use App\Domain\Sites\HostingPlacement;
use App\Domain\Sites\SiteAdministration;
use App\Domain\Sites\SiteValidationException;
use App\Enums\AuditResult;
use App\Enums\HostingPanel;
use App\Models\HostingServer;
use App\Models\Site;
use Illuminate\Support\Facades\DB;

/**
 * Bulk import of sites (UZEROWPUP-26), all or nothing. Every entry is first
 * validated through SiteAdministration in dry-run mode, against the stored data
 * and against the other entries of the file; only if there is no error at all
 * are the entries written, in one transaction. A name that already exists is
 * edited (idempotent re-import); one that does not is created.
 */
final readonly class SiteImporter
{
    public function __construct(
        private SiteImportParser $parser,
        private SiteAdministration $sites,
        private AuditLogger $audit,
    ) {}

    /**
     * @throws SiteValidationException with every error found
     */
    public function import(AuditActor $actor, string $content, ImportFormat $format, string $fileName, bool $dryRun = false): SiteImportResult
    {
        $parsed = $this->parser->parse($content, $format);
        $errors = $parsed->errors;

        $seen = [];
        foreach ($parsed->entries as $entry) {
            if (isset($seen[$entry->name])) {
                $errors[] = "{$entry->label()}: el nom ja apareix al lloc {$seen[$entry->name]} del fitxer.";
            }
            $seen[$entry->name] ??= $entry->position;
        }

        $result = $this->evaluate($parsed->entries, $errors);
        if ($errors !== []) {
            throw new SiteValidationException($errors);
        }
        if ($dryRun || ! $result->changes()) {
            return $result;
        }

        DB::transaction(function () use ($actor, $parsed): void {
            foreach ($parsed->entries as $entry) {
                $this->apply($actor, $entry);
            }
        });

        $this->audit->record($actor, 'site.import', AuditResult::Success, 'file', $fileName, [
            'file' => $fileName,
            'format' => $format->value,
            'created' => $result->count(ImportAction::Create),
            'updated' => $result->count(ImportAction::Update),
            'unchanged' => $result->count(ImportAction::Unchanged),
            'sites' => array_map(static fn (SiteImportOutcome $o): string => $o->name, $result->outcomes),
        ]);

        return $result;
    }

    /**
     * Dry-runs every entry. Servers and accounts that an earlier entry of the
     * file would create are remembered so later entries can reuse them.
     *
     * @param  list<SiteImportEntry>  $entries
     * @param  list<string>  $errors
     */
    private function evaluate(array $entries, array &$errors): SiteImportResult
    {
        $servers = [];
        $accounts = [];
        $outcomes = [];
        foreach ($entries as $entry) {
            $problems = [];

            try {
                $site = Site::query()->with('hostingAccount.hostingServer')->where('name', $entry->name)->first();
                if ($site !== null) {
                    $problems = $this->checkPlacement($entry, $site);
                    $changes = $this->sites->edit($this->silent(), $site, $entry->input, $entry->clear, true);
                    $fields = array_keys($changes);
                    $outcomes[] = new SiteImportOutcome($entry->name, $fields === [] ? ImportAction::Unchanged : ImportAction::Update, $fields);
                } else {
                    $placement = $this->placement($entry, $servers, $accounts, $problems);
                    if ($placement !== null) {
                        $this->sites->add($this->silent(), $entry->name, $entry->input, $placement, true);
                        $outcomes[] = new SiteImportOutcome($entry->name, ImportAction::Create, $entry->fields);
                    }
                }
            } catch (SiteValidationException $e) {
                array_push($problems, ...$e->errors());
            }

            foreach ($problems as $problem) {
                $errors[] = "{$entry->label()}: {$problem}";
            }
        }

        return new SiteImportResult($outcomes);
    }

    private function apply(AuditActor $actor, SiteImportEntry $entry): void
    {
        $site = Site::query()->where('name', $entry->name)->first();
        if ($site !== null) {
            $this->sites->edit($actor, $site, $entry->input, $entry->clear);

            return;
        }
        $problems = [];
        $servers = [];
        $accounts = [];
        $placement = $this->placement($entry, $servers, $accounts, $problems);
        if ($placement === null) {
            throw new SiteValidationException(array_map(static fn (string $p): string => "{$entry->label()}: {$p}", $problems));
        }
        $this->sites->add($actor, $entry->name, $entry->input, $placement);
    }

    /**
     * The placement to pass to SiteAdministration::add(), reconciled with the
     * stored server (or the one an earlier entry creates) so a repeated or
     * shared server definition is accepted when it agrees and rejected when not.
     *
     * @param  array<string, array{hostname: ?string, port: ?int, panel: ?HostingPanel}>  $servers  Servers to be created, by name.
     * @param  array<string, string|null>  $accounts  Driver of the accounts to be created, by «server/user».
     * @param  list<string>  $problems
     */
    private function placement(SiteImportEntry $entry, array &$servers, array &$accounts, array &$problems): ?HostingPlacement
    {
        if ($entry->server === null || $entry->account === null) {
            $problems[] = 'Cal indicar el servidor (server) i el compte (account) d\'un lloc nou.';

            return null;
        }

        $stored = HostingServer::query()->where('name', $entry->server)->first();
        $known = $stored !== null
            ? ['hostname' => $stored->hostname, 'port' => $stored->ssh_port, 'panel' => $stored->panel]
            : ($servers[$entry->server] ?? null);
        $accountKey = "{$entry->server}/{$entry->account}";

        if ($known === null) {
            $servers[$entry->server] = ['hostname' => $entry->hostname, 'port' => $entry->sshPort, 'panel' => $entry->panel];
            $accounts[$accountKey] = $entry->driver?->value;

            return new HostingPlacement($entry->server, $entry->account, $entry->hostname, $entry->sshPort, $entry->panel, $entry->driver);
        }

        $this->agree($entry, $known['hostname'], $known['port'], $known['panel'], $problems);
        if ($stored !== null) {
            return new HostingPlacement($entry->server, $entry->account, null, null, null, $entry->driver);
        }

        $before = $accounts[$accountKey] ?? null;
        if ($before !== null && $entry->driver !== null && $before !== $entry->driver->value) {
            $problems[] = "el compte «{$entry->account}» s'ha definit abans al fitxer amb un altre driver.";
        }
        $accounts[$accountKey] = $entry->driver === null ? $before : $entry->driver->value;

        return new HostingPlacement($entry->server, $entry->account, $known['hostname'], $known['port'], $known['panel'], $entry->driver);
    }

    /**
     * @param  list<string>  $problems
     */
    private function agree(SiteImportEntry $entry, ?string $hostname, ?int $port, ?HostingPanel $panel, array &$problems): void
    {
        if (($entry->hostname !== null && $entry->hostname !== $hostname)
            || ($entry->sshPort !== null && $entry->sshPort !== ($port ?? 22))
            || ($entry->panel !== null && $entry->panel !== ($panel ?? HostingPanel::Plesk))) {
            $problems[] = "el servidor «{$entry->server}» ja està definit amb un altre host, port o tauler: no es pot canviar des d'aquí.";
        }
    }

    /**
     * An existing site cannot move to another server or account.
     *
     * @return list<string>
     */
    private function checkPlacement(SiteImportEntry $entry, Site $site): array
    {
        $account = $site->hostingAccount;
        $server = $account?->hostingServer;
        if ($account === null || $server === null) {
            return ['el lloc no té compte o servidor d\'hosting.'];
        }
        $problems = [];
        if ($entry->server !== null && $entry->server !== $server->name) {
            $problems[] = "el lloc ja existeix al servidor «{$server->name}»: no es pot moure.";
        }
        if ($entry->account !== null && $entry->account !== $account->system_user) {
            $problems[] = "el lloc ja existeix al compte «{$account->system_user}»: no es pot moure.";
        }
        if ($entry->driver !== null && $entry->driver !== $account->driver) {
            $problems[] = "el compte del lloc té el driver {$account->driver->value}: no es pot canviar des d'aquí.";
        }
        if ($entry->hostname !== null || $entry->sshPort !== null || $entry->panel !== null) {
            $this->agree($entry, $server->hostname, $server->ssh_port, $server->panel, $problems);
        }

        return $problems;
    }

    /**
     * Dry-run calls never audit, but the signature wants an actor.
     */
    private function silent(): AuditActor
    {
        return AuditActor::system();
    }
}
