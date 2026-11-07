<?php

declare(strict_types=1);

namespace App\Console\Concerns;

use App\Domain\Identity\Ability;
use App\Domain\Planning\ComponentName;
use App\Domain\Planning\PlanOptions;
use App\Enums\AuditResult;
use App\Models\Site;
use App\Models\SiteProfile;

/**
 * The per-run overrides that `plan` and `update` share, for an admin only and
 * audited: --allow-major and --skip-quarantine (ADR-0005) and --include
 * (UZEROWPUP-51: plan a frozen or excluded component anyway). The signature of
 * the command declares them (self::PLAN_OPTIONS).
 */
trait ReadsPlanOptions
{
    protected const string PLAN_OPTIONS = '{--allow-major : Només admin: aplica in-place els canvis majors que cal provar abans a staging (ADR-0005)}
        {--skip-quarantine : Només admin: aplica les versions que encara són en quarantena}
        {--include=* : Només admin: planifica igualment aquest component congelat o exclòs del perfil (slug o tipus:slug; repetible)}';

    /**
     * The options given; null when they are not valid or the operator may not use them (printed, and audited when denied).
     */
    protected function planOptions(): ?PlanOptions
    {
        $include = array_values(array_unique(array_filter(array_map(trim(...), array_filter((array) $this->option('include'), is_string(...))), static fn (string $c): bool => $c !== '')));
        foreach ($include as $component) {
            if (! ComponentName::isValid($component)) {
                $this->error("El component «{$component}» de --include no és vàlid (en minúscules: slug o tipus:slug, sense versió).");

                return null;
            }
        }
        $options = new PlanOptions($this->option('allow-major') === true, $this->option('skip-quarantine') === true, $include);
        if ($options->any() && ! $this->requireAbility(Ability::OverrideVersionPolicy, ['flags' => $options->toFlags()], 'Només un admin pot fer servir --allow-major, --skip-quarantine i --include.')) {
            return null;
        }

        return $options;
    }

    /**
     * @param  list<Site>  $active
     */
    protected function auditPlanOverrides(PlanOptions $options, array $active): void
    {
        $sites = array_map(static fn (Site $site): string => $site->name, $active);
        if ($options->versionPolicyFlags() !== []) {
            $this->audit->record($this->actor(), 'plan.version_policy_overridden', AuditResult::Success, 'command', (string) $this->getName(), [
                'flags' => $options->versionPolicyFlags(),
                'sites' => $sites,
            ]);
        }
        if ($options->include !== []) {
            $matched = self::matchedIncludes($options->include, $active);
            foreach (array_diff($options->include, $matched) as $entry) {
                $this->warn("--include={$entry} no coincideix amb cap component congelat o exclòs.");
            }
            $this->audit->record($this->actor(), 'plan.profile_overridden', AuditResult::Success, 'command', (string) $this->getName(), [
                'include' => $options->include,
                'matched' => $matched,
                'sites' => $sites,
            ]);
        }
    }

    /**
     * The --include entries that name a frozen or excluded component of at least one of the sites.
     *
     * @param  list<string>  $include
     * @param  list<Site>  $sites
     * @return list<string>
     */
    private static function matchedIncludes(array $include, array $sites): array
    {
        $listed = [];
        foreach ($sites as $site) {
            /** @var SiteProfile|null $profile */
            $profile = $site->profile;
            foreach ($profile === null ? [] : [...$profile->frozen_components, ...$profile->excluded_components] as $entry) {
                if (is_string($entry)) {
                    $listed[] = $entry;
                }
            }
        }

        return array_values(array_filter($include, static function (string $entry) use ($listed): bool {
            foreach ($listed as $candidate) {
                if (ComponentName::overlaps($entry, $candidate)) {
                    return true;
                }
            }

            return false;
        }));
    }
}
