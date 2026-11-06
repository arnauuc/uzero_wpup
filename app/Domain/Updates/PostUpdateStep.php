<?php

declare(strict_types=1);

namespace App\Domain\Updates;

use App\Domain\Audit\AuditActor;
use App\Domain\Drivers\Data\CommandResult;
use App\Domain\Drivers\Data\InstalledComponents;
use App\Domain\Drivers\Exceptions\ConnectionFailed;
use App\Domain\Drivers\Exceptions\DriverException;
use App\Domain\Drivers\SiteDriver;
use App\Domain\Identity\Ability;
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
 * 2. WooCommerce's database update, when WooCommerce is active. Its own CLI
 *    registers `wc update` (see the UZEROWPUP-55 Plane comment), which needs
 *    WooCommerce and may be hooked by its extensions, so plugins are loaded
 *    (only the theme is skipped). It runs when WooCommerce was updated, or
 *    when a small constant check (WC_Install::needs_db_update(), as the
 *    inventory does) says the migration is pending or cannot be told. After it,
 *    the same check must say "ok": still pending, unknown or a failing command
 *    is an ERROR (the update run decides about the rollback, UZEROWPUP-60).
 *    No flush runs after an error.
 * 3. Caches: the object cache, expired transients and the page cache of the
 *    common caching plugins that are active. Each page cache command is first
 *    looked up with `wp cli has-command`. A failed flush is only a warning.
 *    The step data tells what happened to the page cache:
 *    - `page_cache_purged`: false when an active caching plugin's cache could
 *      not be purged (failed, or the plugin has no WP-CLI command), true
 *      otherwise. The smoke check (UZEROWPUP-56) and the verifier must bypass
 *      caches (cache-busting) when it is false. Note that a cache outside the
 *      site (CDN, host) is not visible here.
 *    - `page_cache_plugins` / `page_cache_failed`: the slugs involved.
 *
 * Idempotent: every command is a no-op when there is nothing left to do. A lost
 * connection fails the step, like everywhere else.
 */
final readonly class PostUpdateStep implements Step
{
    public const string NAME = 'post-update';

    /**
     * Constant on purpose: nothing is interpolated. Prints ok, pending or
     * unknown, like SiteState reads it.
     */
    public const string WC_DB_SNIPPET = <<<'PHP'
$state = 'unknown';
if (function_exists('WC')) {
    if (is_callable(array('WC_Install', 'needs_db_update'))) {
        $state = WC_Install::needs_db_update() ? 'pending' : 'ok';
    } else {
        $version = get_option('woocommerce_db_version');
        if ($version !== false) { $state = version_compare((string) $version, (string) WC()->version, '<') ? 'pending' : 'ok'; }
    }
}
echo $state;
PHP;

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
        $wc = $this->updated(ComponentType::Woocommerce)
            ? 'executarà `wp wc update` (WooCommerce)'
            : 'si WooCommerce és actiu i la seva migració és pendent o no es pot comprovar, executarà `wp wc update`';

        return "Executarà `wp core update-db`; {$wc}; comprovarà que la migració de la base de dades no queda pendent (si no, el pas falla); i buidarà la memòria cau d'objectes, els transients caducats i la cau de pàgines dels plugins de cau actius (si no es pot, avís i s'anota que la cau de pàgines no s'ha buidat).";
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
            $installed = $driver->installedComponents();
            $failure = $this->migrate($driver, $installed, $log);
            if ($failure !== null) {
                return StepOutcome::failed($this->redactor->redact("La migració de la base de dades ha fallat: {$failure}"), $this->redactor->redact(implode("\n", $log)));
            }
            [$warnings, $plugins, $failed] = $this->flush($driver, $installed, $log);
        } catch (DriverException $e) {
            return StepOutcome::failed($this->redactor->redact("No s'ha pogut completar la post-actualització: {$e->getMessage()}"), $this->redactor->redact(implode("\n", $log)));
        }

        $summary = $warnings === []
            ? 'Base de dades migrada i caus buidades.'
            : 'Base de dades migrada; '.count($warnings).' avisos en buidar les caus: '.implode(' ', $warnings);

        return StepOutcome::succeeded($this->redactor->redact($summary), $this->redactor->redact(implode("\n", $log)), [
            'flush_warnings' => count($warnings),
            'page_cache_purged' => $failed === [],
            'page_cache_plugins' => $plugins,
            'page_cache_failed' => $failed,
        ]);
    }

    /**
     * @param  list<string>  $log
     * @return string|null why the migration failed; null when nothing is left pending
     *
     * @throws DriverException
     */
    private function migrate(SiteDriver $driver, InstalledComponents $installed, array &$log): ?string
    {
        $core = $this->command($driver, ['core', 'update-db', ...self::SAFE_MODE], $this->settings->updateDbTimeoutSeconds, $log);
        if (! $core->successful()) {
            return '`wp core update-db` ha acabat amb el codi '.$core->exitCode.'.';
        }

        $woocommerce = $installed->find(ComponentType::Woocommerce, 'woocommerce');
        if ($woocommerce === null || ! $woocommerce->active) {
            return null;
        }

        // Updated ⇒ no need to ask first. Otherwise only run it when it is pending or cannot be told.
        if (! $this->updated(ComponentType::Woocommerce) && $this->woocommerceState($driver, $log) === 'ok') {
            return null;
        }
        $update = $this->command($driver, ['wc', 'update', '--skip-themes'], $this->settings->updateDbTimeoutSeconds, $log);
        if (! $update->successful()) {
            return '`wp wc update` ha acabat amb el codi '.$update->exitCode.'.';
        }

        return match ($this->woocommerceState($driver, $log)) {
            'ok' => null,
            'pending' => 'WooCommerce encara té la migració de la base de dades pendent.',
            default => "no s'ha pogut comprovar si WooCommerce té la migració pendent.",
        };
    }

    /**
     * @param  list<string>  $log
     * @return 'ok'|'pending'|'unknown'
     *
     * @throws ConnectionFailed
     */
    private function woocommerceState(SiteDriver $driver, array &$log): string
    {
        try {
            $result = $this->command($driver, ['eval', self::WC_DB_SNIPPET, '--skip-themes'], $this->settings->commandTimeoutSeconds, $log);
        } catch (ConnectionFailed $e) {
            throw $e;
        } catch (DriverException $e) {
            $log[] = "No s'ha pogut comprovar la migració de WooCommerce: {$e->getMessage()}";

            return 'unknown';
        }
        $lines = array_values(array_filter(array_map('trim', explode("\n", $result->stdout)), static fn (string $l): bool => $l !== ''));
        $answer = $lines === [] ? '' : $lines[count($lines) - 1];

        return $result->successful() && in_array($answer, ['ok', 'pending'], true) ? $answer : 'unknown';
    }

    /**
     * @param  list<string>  $log
     * @return array{list<string>, list<string>, list<string>} warnings, active page cache plugins, those whose cache was not purged
     *
     * @throws ConnectionFailed
     */
    private function flush(SiteDriver $driver, InstalledComponents $installed, array &$log): array
    {
        $warnings = [];
        $plugins = [];
        $failed = [];

        $generic = [
            "la memòria cau d'objectes" => ['cache', 'flush', ...self::SAFE_MODE],
            'els transients caducats' => ['transient', 'delete', '--expired', ...self::SAFE_MODE],
        ];
        foreach ($generic as $what => $args) {
            $this->tryCommand($driver, $args, "No s'ha pogut buidar {$what}", $log, $warnings);
        }

        foreach ($installed->components as $component) {
            if ($component->type !== ComponentType::Plugin || ! $component->active || ! isset($this->settings->pageCaches[$component->slug])) {
                continue;
            }
            $slug = $component->slug;
            $cache = $this->settings->pageCaches[$slug];
            $plugins[] = $slug;
            // The plugin must be loaded to register its command: no safe mode.
            $exists = $this->tryCommand($driver, ['cli', 'has-command', $cache->command], null, $log, $warnings);
            if (! $exists) {
                $warnings[] = "{$slug} està actiu però no té comanda WP-CLI per buidar-ne la cau.";
                $failed[] = $slug;

                continue;
            }
            if (! $this->tryCommand($driver, $cache->args(), "No s'ha pogut buidar la cau de pàgines de «{$slug}»", $log, $warnings)) {
                $failed[] = $slug;
            }
        }

        return [$warnings, $plugins, $failed];
    }

    /**
     * Runs a flush-type command; a failure is a warning (null message: not even that, the caller reports).
     *
     * @param  list<string>  $args
     * @param  list<string>  $log
     * @param  list<string>  $warnings
     * @return bool whether it succeeded
     *
     * @throws ConnectionFailed
     */
    private function tryCommand(SiteDriver $driver, array $args, ?string $failure, array &$log, array &$warnings): bool
    {
        try {
            $result = $this->command($driver, $args, $this->settings->commandTimeoutSeconds, $log);
        } catch (ConnectionFailed $e) {
            throw $e;
        } catch (DriverException $e) {
            if ($failure !== null) {
                $warnings[] = "{$failure}: {$e->getMessage()}";
            }

            return false;
        }
        if (! $result->successful() && $failure !== null) {
            $warnings[] = "{$failure} (codi {$result->exitCode}).";
        }

        return $result->successful();
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
        $shown = $args[0] === 'eval' ? 'eval <wc-db-state>'.(count($args) > 2 ? ' '.implode(' ', array_slice($args, 2)) : '') : implode(' ', $args);
        $log[] = '$ wp '.$shown.' (codi '.$result->exitCode.')';
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
