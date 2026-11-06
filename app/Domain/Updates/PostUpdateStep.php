<?php

declare(strict_types=1);

namespace App\Domain\Updates;

use App\Domain\Audit\AuditActor;
use App\Domain\Drivers\Data\CommandResult;
use App\Domain\Drivers\Exceptions\DriverException;
use App\Domain\Drivers\SiteDriver;
use App\Domain\Identity\Ability;
use App\Domain\Inventory\SiteState;
use App\Domain\Planning\PlanAction;
use App\Domain\Planning\PlannedChange;
use App\Domain\Runs\RunAuthorization;
use App\Domain\Runs\Step;
use App\Domain\Runs\StepContext;
use App\Domain\Runs\StepOutcome;
use App\Domain\Secrets\SecretRedactor;
use App\Enums\ComponentType;
use App\Models\Site;
use Closure;

/**
 * What the site needs after the updates (UZEROWPUP-55), the last update step:
 *
 * 1. `wp core update-db`: WP-CLI itself compares the database version with the
 *    code and does nothing when they match, so it is always safe to run. It
 *    runs with --skip-plugins --skip-themes: a broken plugin cannot break the
 *    schema upgrade.
 * 2. `wp wc update` (WooCommerce's own CLI command; there is no `wc update-db`)
 *    when WooCommerce is active and was updated, or its database update was
 *    already pending. It needs WooCommerce, and its extensions may hook the
 *    update, so plugins are loaded (only the theme is skipped). Afterwards the
 *    state is read again with the same reader as the inventory: still pending,
 *    unreadable or a failing command is an ERROR (rollback is decided by the
 *    update run, UZEROWPUP-60). No flush runs after an error.
 * 3. Cache: the object cache, expired transients and the page cache of the
 *    common caching plugins that are active. A failed flush is only a warning
 *    (stale cache, not a broken site) that goes to the step summary.
 *
 * Idempotent: every command is a no-op when there is nothing left to do.
 */
final readonly class PostUpdateStep implements Step
{
    public const string NAME = 'post-update';

    /** Core-only commands: the site's plugins and theme are not loaded. */
    private const array SAFE_MODE = ['--skip-plugins', '--skip-themes'];

    /**
     * @param  list<PlannedChange>  $changes  the plan's changes (only the updates matter)
     * @param  Closure(Site, AuditActor): SiteDriver  $driverFor
     */
    public function __construct(
        private array $changes,
        private Closure $driverFor,
        private RunAuthorization $authorization,
        private SiteState $state,
        private SecretRedactor $redactor,
        private PostUpdateSettings $settings,
    ) {}

    public function name(): string
    {
        return self::NAME;
    }

    public function timeoutSeconds(): int
    {
        return $this->settings->stepTimeoutSeconds;
    }

    public function describe(StepContext $context): string
    {
        $wc = $this->updated(ComponentType::Woocommerce) ? ' i `wp wc update` (WooCommerce)' : '';

        return "Executarà `wp core update-db`{$wc}, comprovarà que la migració de la base de dades no queda pendent, i buidarà la memòria cau d'objectes, els transients caducats i la cau de pàgines dels plugins de cau actius.";
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
            return StepOutcome::failed("L'execució no té cap lloc: no hi ha res per fer després d'actualitzar.");
        }

        $log = [];
        try {
            $driver = ($this->driverFor)($site, $actor);
            $failure = $this->migrate($driver, $log);
            if ($failure !== null) {
                return StepOutcome::failed($this->redactor->redact("La migració de la base de dades ha fallat: {$failure}"), $this->redactor->redact(implode("\n", $log)));
            }
            $warnings = $this->flush($driver, $log);
        } catch (DriverException $e) {
            return StepOutcome::failed($this->redactor->redact("No s'ha pogut completar la post-actualització: {$e->getMessage()}"), $this->redactor->redact(implode("\n", $log)));
        }

        $summary = $warnings === []
            ? 'Base de dades migrada i caus buidades.'
            : 'Base de dades migrada; '.count($warnings).' avisos en buidar les caus: '.implode(' ', $warnings);

        return StepOutcome::succeeded($this->redactor->redact($summary), $this->redactor->redact(implode("\n", $log)), ['flush_warnings' => count($warnings)]);
    }

    /**
     * @param  list<string>  $log
     * @return string|null why the migration failed; null when nothing is left pending
     *
     * @throws DriverException
     */
    private function migrate(SiteDriver $driver, array &$log): ?string
    {
        $core = $this->command($driver, ['core', 'update-db', ...self::SAFE_MODE], $this->settings->updateDbTimeoutSeconds, $log);
        if (! $core->successful()) {
            return '`wp core update-db` ha acabat amb el codi '.$core->exitCode.'.';
        }

        $installed = $driver->installedComponents();
        $woocommerce = $installed->find(ComponentType::Woocommerce, 'woocommerce');
        if ($woocommerce === null || ! $woocommerce->active) {
            return null;
        }

        $pending = $this->state->read($driver, $installed, time())->woocommerce?->dbUpdateNeeded;
        if ($this->updated(ComponentType::Woocommerce) || $pending !== false) {
            $update = $this->command($driver, ['wc', 'update', '--skip-themes'], $this->settings->updateDbTimeoutSeconds, $log);
            if (! $update->successful()) {
                return '`wp wc update` ha acabat amb el codi '.$update->exitCode.'.';
            }
        }

        $after = $this->state->read($driver, $installed, time())->woocommerce?->dbUpdateNeeded;
        if ($after === null) {
            return "no s'ha pogut comprovar si WooCommerce té la migració pendent.";
        }

        return $after ? 'WooCommerce encara té la migració de la base de dades pendent.' : null;
    }

    /**
     * @param  list<string>  $log
     * @return list<string> warnings
     */
    private function flush(SiteDriver $driver, array &$log): array
    {
        $commands = [
            "la memòria cau d'objectes" => ['cache', 'flush', ...self::SAFE_MODE],
            'els transients caducats' => ['transient', 'delete', '--expired', ...self::SAFE_MODE],
        ];
        $warnings = [];
        try {
            $components = $driver->installedComponents()->components;
        } catch (DriverException $e) {
            $components = [];
            $warnings[] = "No s'han pogut llistar els plugins de cau actius: {$e->getMessage()}";
        }
        foreach ($components as $component) {
            if ($component->type === ComponentType::Plugin && $component->active && isset($this->settings->pageCaches[$component->slug])) {
                // The plugin must be loaded to register its command: no safe mode.
                $commands["la cau de pàgines de «{$component->slug}»"] = $this->settings->pageCaches[$component->slug];
            }
        }

        foreach ($commands as $what => $args) {
            try {
                $result = $this->command($driver, $args, $this->settings->flushTimeoutSeconds, $log);
            } catch (DriverException $e) {
                $warnings[] = "No s'ha pogut buidar {$what}: {$e->getMessage()}";

                continue;
            }
            if (! $result->successful()) {
                $warnings[] = "No s'ha pogut buidar {$what} (codi {$result->exitCode}).";
            }
        }

        return $warnings;
    }

    /**
     * @param  list<string>  $args
     * @param  list<string>  $log
     *
     * @throws DriverException
     */
    private function command(SiteDriver $driver, array $args, int $timeoutSeconds, array &$log): CommandResult
    {
        $result = $driver->wp($args, $timeoutSeconds);
        $log[] = '$ wp '.implode(' ', $args).' (codi '.$result->exitCode.')';
        $output = trim($result->stdout."\n".$result->stderr);
        if ($output !== '') {
            $log[] = $output;
        }

        return $result;
    }

    private function updated(ComponentType $type): bool
    {
        foreach ($this->changes as $change) {
            if ($change->action === PlanAction::Update && $change->type === $type) {
                return true;
            }
        }

        return false;
    }
}
