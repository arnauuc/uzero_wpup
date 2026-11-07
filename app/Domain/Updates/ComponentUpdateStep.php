<?php

declare(strict_types=1);

namespace App\Domain\Updates;

use App\Domain\Artifacts\ArtifactException;
use App\Domain\Artifacts\ArtifactStore;
use App\Domain\Audit\AuditActor;
use App\Domain\Drivers\Data\UpdatePackage;
use App\Domain\Drivers\Exceptions\DriverException;
use App\Domain\Drivers\Exceptions\RemoteCommandFailed;
use App\Domain\Drivers\SiteDriver;
use App\Domain\Identity\Ability;
use App\Domain\Maintenance\MaintenanceGuard;
use App\Domain\Planning\PlannedChange;
use App\Domain\Runs\RunAuthorization;
use App\Domain\Runs\Step;
use App\Domain\Runs\StepContext;
use App\Domain\Runs\StepOutcome;
use App\Domain\Secrets\SecretRedactor;
use App\Enums\ChangeResult;
use App\Enums\ComponentType;
use App\Models\Run;
use App\Models\Site;
use Closure;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Installs one planned update (UZEROWPUP-54) from the artifact the same run
 * already downloaded and analysed: nothing is downloaded here, neither by the
 * tool nor by the site. The artifact is verified right before the driver
 * uploads it, so staging and production get the same bytes.
 *
 * Idempotent, and never acting on a site that changed since the plan: the
 * installed version is read first. The target ⇒ nothing to do, on a first
 * attempt (updated meanwhile, outside the tool) or when this run already
 * recorded the install as done; but on a RESUMED attempt without that record
 * ⇒ fail: the worker died halfway through the install (UZEROWPUP-62) and the
 * version header proves nothing about the other files, so the run rolls back
 * to its restore point. The plan's current version ⇒ install; anything else (newer, older, removed) ⇒ fail, the run must plan
 * again. Every outcome, also an unexpected error or the step timeout, is one
 * run_changes row (step = update) with the version before and after.
 */
final readonly class ComponentUpdateStep implements Step
{
    /**
     * @param  Closure(Site, AuditActor): SiteDriver  $driverFor  resolves the driver of the run's site
     *                                                            (DriverFactory::forSite); credential
     *                                                            reveals are audited as $actor
     */
    public function __construct(
        private PlannedChange $change,
        private ArtifactStore $store,
        private Closure $driverFor,
        private RunAuthorization $authorization,
        private UpdateChanges $changes,
        private SecretRedactor $redactor,
        private int $timeoutSeconds,
        private ?MaintenanceGuard $maintenance = null,
    ) {}

    public function name(): string
    {
        return UpdateChanges::STEP.":{$this->change->type->value}:{$this->change->slug}";
    }

    public function timeoutSeconds(): int
    {
        return $this->timeoutSeconds;
    }

    public function describe(StepContext $context): string
    {
        return "Instal·larà {$this->label()} {$this->change->targetVersion} (ara {$this->change->currentVersion}) amb el paquet que l'execució ha descarregat i analitzat, comprovat just abans de pujar-lo al lloc.";
    }

    public function run(StepContext $context): StepOutcome
    {
        // Connecting reveals the site's credential: checked again as whoever launched the run (ADR-0011).
        $actor = $this->authorization->actorFor($context->run, Ability::RunOperations);
        if ($actor === null) {
            return StepOutcome::failed(RunAuthorization::deniedMessage($context->run));
        }
        $site = $context->run->site;
        if ($site === null) {
            return StepOutcome::failed("L'execució no té cap lloc: no hi ha res per actualitzar.");
        }

        try {
            $driver = ($this->driverFor)($site, $actor);
        } catch (DriverException $e) {
            return $this->fail($context->run, null, null, $e->getMessage());
        }
        $before = null;
        // WordPress ignores a .maintenance file older than 10 minutes (UZEROWPUP-53).
        $this->maintenance?->refresh($driver, $context->run);
        try {
            return $this->update($context, $driver, $before);
        } catch (ArtifactException|DriverException $e) {
            $output = $e instanceof RemoteCommandFailed && $e->result !== null ? $e->result->stdout."\n".$e->result->stderr : '';

            return $this->fail($context->run, $before, $this->versionAfterFailure($driver), $e->getMessage(), $output);
        } catch (Throwable $e) {
            // The step timeout or an unexpected error: still recorded, then the engine handles it.
            $this->recordUnexpected($context->run, $before, $e);

            throw $e;
        }
    }

    /**
     * @param-out string|null $before  the version read first, for the failure record
     *
     * @throws ArtifactException
     * @throws DriverException
     */
    private function update(StepContext $context, SiteDriver $driver, ?string &$before): StepOutcome
    {
        $run = $context->run;
        $target = $this->change->targetVersion;
        $before = $driver->installedState($this->change->type, $this->change->slug)?->version;
        if ($before === $target && $context->resumed() && ! $this->changes->wasUpdated($run, $this->change->type, $this->change->slug)) {
            // Recorded as from the plan's version: the rollback must put that one back.
            return $this->fail($run, $this->change->currentVersion, $before, "l'intent anterior d'aquest pas es va interrompre abans d'acabar la instal·lació i el lloc ja diu que té la versió {$target}: no es pot saber si els fitxers són sencers, cal revertir.");
        }
        if ($before === $target) {
            // Updated meanwhile outside the tool, or a resumed step whose install had finished and was recorded: nothing is uploaded.
            $this->record($run, $this->change->currentVersion, $target, ChangeResult::Updated, null);

            return StepOutcome::succeeded("{$this->label()} ja era a la versió {$target}: no s'ha tocat.", '', $this->data($this->change->currentVersion, $target, true));
        }
        if ($before !== $this->change->currentVersion) {
            return $this->fail($run, $before, $before, "l'estat del lloc ha canviat des del pla (ara «".($before ?? 'no instal·lat')."», el pla esperava «{$this->change->currentVersion}»): cal tornar a planificar.");
        }

        $artifact = $this->store->find($run->id, $this->change->type, $this->change->slug, $target);
        if ($artifact === null) {
            return $this->fail($run, $before, $before, "cap artefacte analitzat per a {$this->label()} {$target} en aquesta execució.");
        }
        // Rehashed right before the upload: the bytes the analysis saw.
        $artifact = $this->store->verify($artifact);
        $applied = $driver->applyUpdate(new UpdatePackage($this->change->type, $this->change->slug, $target, $artifact->path, $artifact->sha256, $artifact->sizeBytes));
        $after = $applied->installed->version;
        $output = trim($applied->result->stdout."\n".$applied->result->stderr);
        if ($after !== $target) {
            return $this->fail($run, $before, $after, "després d'instal·lar-lo, el lloc diu que té la versió «{$after}».", $output);
        }
        $this->record($run, $before, $after, ChangeResult::Updated, null);

        return StepOutcome::succeeded("{$this->label()}: {$before} → {$after} (paquet sha256 ".substr($artifact->sha256, 0, 12).'…).', $output, $this->data($before, $after, false));
    }

    private function fail(Run $run, ?string $before, ?string $after, string $reason, string $output = ''): StepOutcome
    {
        $reason = $this->redactor->redact($reason);
        $this->record($run, $before ?? $this->change->currentVersion, $after, ChangeResult::Failed, $reason);

        return StepOutcome::failed("No s'ha pogut actualitzar {$this->label()}: {$reason}", $output);
    }

    /** Records an unexpected failure without ever hiding it. */
    private function recordUnexpected(Run $run, ?string $before, Throwable $e): void
    {
        try {
            $this->record($run, $before ?? $this->change->currentVersion, null, ChangeResult::Failed, $this->redactor->redact('Error inesperat ('.$e::class."): {$e->getMessage()}"));
        } catch (Throwable $recording) {
            Log::error('Could not record a failed update.', ['run_id' => $run->id, 'slug' => $this->change->slug, 'error' => $recording::class]);
        }
    }

    private function record(Run $run, string $before, ?string $after, ChangeResult $result, ?string $detail): void
    {
        $this->changes->record($run, UpdateChanges::STEP, $this->change->type, $this->change->slug, $before, $after, $result, $detail);
    }

    /**
     * @return array{type: string, slug: string, version_before: string, version_after: string, already_installed: bool}
     */
    private function data(string $before, string $after, bool $alreadyInstalled): array
    {
        return ['type' => $this->change->type->value, 'slug' => $this->change->slug, 'version_before' => $before, 'version_after' => $after, 'already_installed' => $alreadyInstalled];
    }

    /** What the site has after a failure, when it can still be read. */
    private function versionAfterFailure(SiteDriver $driver): ?string
    {
        try {
            return $driver->installedState($this->change->type, $this->change->slug)?->version;
        } catch (DriverException $e) {
            Log::warning('Could not read the installed version after a failed update.', ['slug' => $this->change->slug, 'reason' => $this->redactor->redact($e->getMessage())]);

            return null;
        }
    }

    private function label(): string
    {
        return $this->change->type === ComponentType::Core ? 'WordPress' : "{$this->change->type->value} «{$this->change->slug}»";
    }
}
