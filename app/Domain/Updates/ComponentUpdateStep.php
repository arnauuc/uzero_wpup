<?php

declare(strict_types=1);

namespace App\Domain\Updates;

use App\Domain\Artifacts\ArtifactException;
use App\Domain\Artifacts\ArtifactStore;
use App\Domain\Artifacts\PackageArtifacts;
use App\Domain\Audit\AuditActor;
use App\Domain\Drivers\Data\UpdatePackage;
use App\Domain\Drivers\Exceptions\DriverException;
use App\Domain\Drivers\Exceptions\RemoteCommandFailed;
use App\Domain\Drivers\SiteDriver;
use App\Domain\Identity\Ability;
use App\Domain\Inventory\InventoryStep;
use App\Domain\Inventory\PendingUpdate;
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
use App\Models\SiteSnapshot;
use Closure;
use Illuminate\Support\Facades\Log;

/**
 * Installs one planned update (UZEROWPUP-54) from the tool's artifact, never
 * from a download by the site: the artifact is obtained for the run and its
 * bytes verified right before the driver uploads them, so staging and
 * production get the same ones.
 *
 * Idempotent: the installed version is read first; if it already is the
 * target nothing is uploaded and the change is recorded as done (a resumed
 * step after a crash). After the install the version is read again and must
 * be the target. Every outcome is one run_changes row (step = update) with
 * the version before and after. The package needs the run's fresh inventory:
 * its pending update says where the artifact comes from.
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
        private PackageArtifacts $artifacts,
        private ArtifactStore $store,
        private Closure $driverFor,
        private RunAuthorization $authorization,
        private UpdateChanges $changes,
        private SecretRedactor $redactor,
        private int $timeoutSeconds,
    ) {}

    public static function nameFor(ComponentType $type, string $slug): string
    {
        return UpdateChanges::STEP.":{$type->value}:{$slug}";
    }

    public function name(): string
    {
        return self::nameFor($this->change->type, $this->change->slug);
    }

    public function timeoutSeconds(): int
    {
        return $this->timeoutSeconds;
    }

    public function describe(StepContext $context): string
    {
        return "Instal·larà {$this->label()} {$this->change->targetVersion} (ara {$this->change->currentVersion}) amb el paquet que ha descarregat l'eina, comprovat just abans de pujar-lo al lloc.";
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

        $target = $this->change->targetVersion;
        try {
            $driver = ($this->driverFor)($site, $actor);
        } catch (DriverException $e) {
            return $this->fail($context->run, null, null, $e->getMessage());
        }
        $before = null;
        try {
            $before = $this->installedVersion($driver);
            if ($before === $target) {
                // Already there: a resumed step whose install had finished. Nothing is uploaded.
                $this->record($context->run, $this->change->currentVersion, $target, ChangeResult::Updated, null);

                return StepOutcome::succeeded("{$this->label()} ja era a la versió {$target}: no s'ha tocat.", '', $this->data($this->change->currentVersion, $target, true));
            }

            $pending = $this->pendingUpdate($context);
            if ($pending === null) {
                return $this->fail($context->run, $before, $before, "No hi ha l'actualització de {$this->label()} {$target} a l'inventari fresc de l'execució.");
            }
            // Obtained (or reused) for the run, then rehashed right before the upload.
            $artifact = $this->store->verify($this->artifacts->obtain($context->run->id, $driver, $pending));
            $result = $driver->applyUpdate(new UpdatePackage($this->change->type, $this->change->slug, $target, $artifact->path, $artifact->sha256, $artifact->sizeBytes));
            $after = $this->installedVersion($driver);
        } catch (ArtifactException|DriverException $e) {
            $output = $e instanceof RemoteCommandFailed && $e->result !== null ? $e->result->stdout."\n".$e->result->stderr : '';

            return $this->fail($context->run, $before, $this->versionAfterFailure($driver), $e->getMessage(), $output);
        }

        $output = trim($result->stdout."\n".$result->stderr);
        if ($after !== $target) {
            return $this->fail($context->run, $before, $after, "Després d'instal·lar {$this->label()} {$target}, el lloc diu que té la versió «".($after ?? 'cap').'».', $output);
        }
        $this->record($context->run, $before ?? $this->change->currentVersion, $after, ChangeResult::Updated, null);

        return StepOutcome::succeeded(
            "{$this->label()}: ".($before ?? $this->change->currentVersion)." → {$after} (paquet sha256 ".substr($artifact->sha256, 0, 12).'…).',
            $output,
            $this->data($before ?? $this->change->currentVersion, $after, false),
        );
    }

    private function fail(Run $run, ?string $before, ?string $after, string $reason, string $output = ''): StepOutcome
    {
        $reason = $this->redactor->redact($reason);
        $this->record($run, $before ?? $this->change->currentVersion, $after, ChangeResult::Failed, $reason);

        return StepOutcome::failed("No s'ha pogut actualitzar {$this->label()}: {$reason}", $output);
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

    /**
     * @throws DriverException
     */
    private function installedVersion(SiteDriver $driver): ?string
    {
        $installed = $driver->installedComponents();

        return $this->change->type === ComponentType::Core
            ? $installed->coreVersion
            : $installed->find($this->change->type, $this->change->slug)?->version;
    }

    /** What the site has after a failure, when it can still be read. */
    private function versionAfterFailure(SiteDriver $driver): ?string
    {
        try {
            return $this->installedVersion($driver);
        } catch (DriverException $e) {
            Log::warning('Could not read the installed version after a failed update.', ['slug' => $this->change->slug, 'reason' => $this->redactor->redact($e->getMessage())]);

            return null;
        }
    }

    private function pendingUpdate(StepContext $context): ?PendingUpdate
    {
        $snapshotId = $context->resultOf(InventoryStep::NAME)['snapshot_id'] ?? null;
        $snapshot = is_int($snapshotId) ? SiteSnapshot::query()->find($snapshotId) : null;
        foreach ($snapshot === null ? [] : $snapshot->pending_updates as $entry) {
            $update = is_array($entry) ? PendingUpdate::fromArray($entry) : null;
            if ($update !== null && $update->type === $this->change->type->value && $update->slug === $this->change->slug && $update->newVersion === $this->change->targetVersion) {
                return $update;
            }
        }

        return null;
    }

    private function label(): string
    {
        return $this->change->type === ComponentType::Core ? 'WordPress' : "{$this->change->type->value} «{$this->change->slug}»";
    }
}
