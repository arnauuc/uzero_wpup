<?php

declare(strict_types=1);

namespace App\Domain\Updates;

use App\Domain\Audit\AuditActor;
use App\Domain\Drivers\Exceptions\DriverException;
use App\Domain\Drivers\SiteDriver;
use App\Domain\Identity\Ability;
use App\Domain\Inventory\WpCliReader;
use App\Domain\Runs\RunAuthorization;
use App\Domain\Runs\Step;
use App\Domain\Runs\StepContext;
use App\Domain\Runs\StepOutcome;
use App\Enums\ChangeResult;
use App\Enums\ComponentType;
use App\Models\Site;
use Closure;

/**
 * Last of the updates (UZEROWPUP-54): the translations of the core, the
 * plugins and the themes, with `wp language … update`.
 *
 * EXPLICIT EXCEPTION to "packages always come from the tool server": WP-CLI
 * downloads the language packs from WordPress.org on the site itself. They
 * are .po/.mo/.json/.l10n.php translation files, not code packages, so the
 * PHP compatibility analysis has nothing to check in them, and the tool does
 * not hold them as artifacts (docs/especificacio.md, «Traduccions»).
 *
 * One run_changes row per translation that had an update (slug
 * "core:<locale>", "plugin:<slug>:<locale>" or "theme:<slug>:<locale>"; a
 * language pack has no version, so both versions are null). Idempotent: a
 * retried step only finds what is still pending, and the rows it already
 * wrote stay. A translation still pending afterwards fails the step.
 */
final readonly class TranslationsStep implements Step
{
    public const string NAME = 'translations';

    /** WP-CLI's language commands, in update order. */
    private const array KINDS = ['core', 'plugin', 'theme'];

    /**
     * @param  Closure(Site, AuditActor): SiteDriver  $driverFor  resolves the driver of the run's site
     *                                                            (DriverFactory::forSite); credential
     *                                                            reveals are audited as $actor
     */
    public function __construct(
        private Closure $driverFor,
        private RunAuthorization $authorization,
        private UpdateChanges $changes,
        private WpCliReader $reader,
        private int $timeoutSeconds,
        private int $commandTimeoutSeconds,
    ) {}

    public function name(): string
    {
        return self::NAME;
    }

    public function timeoutSeconds(): int
    {
        return $this->timeoutSeconds;
    }

    public function describe(StepContext $context): string
    {
        return 'Actualitzarà les traduccions del nucli, dels connectors i dels temes amb WP-CLI, que les descarrega de WordPress.org des del lloc (són fitxers de traducció, no paquets de codi).';
    }

    public function run(StepContext $context): StepOutcome
    {
        $actor = $this->authorization->actorFor($context->run, Ability::RunOperations);
        if ($actor === null) {
            return StepOutcome::failed(RunAuthorization::deniedMessage($context->run));
        }
        $site = $context->run->site;
        if ($site === null) {
            return StepOutcome::failed("L'execució no té cap lloc: no hi ha res per actualitzar.");
        }

        $output = [];
        $updated = 0;
        $stuck = [];
        try {
            $driver = ($this->driverFor)($site, $actor);
            foreach (self::KINDS as $kind) {
                $before = $this->pending($driver, $kind);
                if ($before === []) {
                    continue;
                }
                $result = $driver->wp(['language', $kind, 'update', ...($kind === 'core' ? [] : ['--all'])], $this->commandTimeoutSeconds);
                $output[] = trim($result->stdout."\n".$result->stderr);
                if (! $result->successful()) {
                    throw new DriverException("`wp language {$kind} update` ha fallat (codi {$result->exitCode}).");
                }
                // Recorded per kind: a retry no longer sees what this attempt already updated.
                $after = $this->pending($driver, $kind);
                foreach ($before as $slug) {
                    $failed = in_array($slug, $after, true);
                    if ($failed) {
                        $stuck[] = $slug;
                    } else {
                        $updated++;
                    }
                    $this->changes->record($context->run, self::NAME, ComponentType::Translation, $slug, null, null,
                        $failed ? ChangeResult::Failed : ChangeResult::Updated, $failed ? "La traducció continua pendent després d'actualitzar-la." : null);
                }
            }
        } catch (DriverException $e) {
            return StepOutcome::failed("No s'han pogut actualitzar les traduccions: {$this->reader->redact($e->getMessage())}", implode("\n", $output));
        }

        return $stuck === []
            ? StepOutcome::succeeded($updated === 0 ? 'Cap traducció pendent.' : "{$updated} traduccions actualitzades.", implode("\n", $output), ['updated' => $updated])
            : StepOutcome::failed(sprintf('%d traduccions continuen pendents: %s.', count($stuck), implode(', ', $stuck)), implode("\n", $output));
    }

    /**
     * Installed translations of $kind with an update available, as run_changes slugs.
     *
     * @return list<string>
     *
     * @throws DriverException
     */
    private function pending(SiteDriver $driver, string $kind): array
    {
        $result = $driver->wp(['language', $kind, 'list', ...($kind === 'core' ? [] : ['--all']), '--format=json'], $this->commandTimeoutSeconds);
        $rows = $result->successful() ? $this->reader->json($result->stdout) : null;
        if ($rows === null) {
            throw new DriverException("No s'han pogut llegir les traduccions ({$kind}) del lloc.");
        }

        $slugs = [];
        foreach ($rows as $row) {
            if (! is_array($row) || ($row['update'] ?? null) !== 'available' || ! in_array($row['status'] ?? null, ['installed', 'active'], true)) {
                continue;
            }
            $language = $this->reader->text($row['language'] ?? null);
            $component = $kind === 'core' ? 'core' : $this->reader->text($row[$kind] ?? null);
            if ($language !== null && $component !== null) {
                $slugs[] = $kind === 'core' ? "core:{$language}" : "{$kind}:{$component}:{$language}";
            }
        }

        return $slugs;
    }
}
