<?php

declare(strict_types=1);

use App\Domain\Planning\PlanAction;
use App\Domain\Planning\PlannedChange;
use App\Domain\Planning\PlanReport;
use App\Domain\Planning\UpdatePlan;
use App\Domain\Runs\Pipeline;
use App\Domain\Runs\PipelineResolver;
use App\Domain\Runs\StepOutcome;
use App\Enums\ComponentType;
use App\Enums\PhpVersionSource;
use App\Enums\RunType;
use App\Enums\SkipReason;
use App\Enums\UserRole;
use App\Models\Run;
use App\Models\Site;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Redis;
use Tests\Support\Runs\ScriptedReadOnlyStep;

beforeEach(function (): void {
    Redis::connection('locks')->command('flushdb');
    User::factory()->create(['unix_username' => 'oper1', 'role' => UserRole::Operator]);
    actingAsOperator('oper1');

    Site::factory()->create(['name' => 'botiga-a', 'url' => 'https://a.test', 'tags' => ['woo']]);
    Site::factory()->create(['name' => 'blog', 'url' => 'https://blog.test']);
    Site::factory()->create(['name' => 'antic', 'tags' => ['woo'], 'active' => false]);

    app(PipelineResolver::class)->register(RunType::Plan, static fn (Run $run): Pipeline => new Pipeline([
        new ScriptedReadOnlyStep('plan', static function () use ($run): StepOutcome {
            if ($run->site?->name === 'blog') {
                return StepOutcome::failed('connexió caiguda');
            }
            if ($run->site?->name === 'trencat') {
                return StepOutcome::succeeded('ok', '', ['php' => ['version' => '8.2.20', 'source' => 'inventat', 'verified' => true], 'changes' => []]);
            }
            $plan = new UpdatePlan('8.2.20', PhpVersionSource::Cli, false, null, [
                new PlannedChange(ComponentType::Core, 'wordpress', '6.8.1', '6.8.2', PlanAction::Update),
                new PlannedChange(ComponentType::Plugin, 'akismet', '5.3', '6.0', PlanAction::Skip, SkipReason::MajorRequiresStaging, 'Canvi major'),
            ]);

            return StepOutcome::succeeded($plan->summary(), '', $plan->toArray());
        }),
    ]));
});

it('queues one plan run per selected site and only prints the ids', function (): void {
    command('plan', ['--tag' => ['woo']])
        ->expectsOutputToContain('encuada (plan, botiga-a)')
        ->assertSuccessful();

    expect(Run::query()->where('type', RunType::Plan)->count())->toBe(1);
});

it('selects by name and by --all, skipping inactive sites', function (): void {
    command('plan', ['sites' => ['blog']])->assertSuccessful();
    command('plan', ['--all' => true])->assertSuccessful();

    expect(Run::query()->count())->toBe(3);
});

it('has no --dry-run: a plan never changes anything', function (): void {
    expect(Artisan::all()['plan']->getDefinition()->hasOption('dry-run'))->toBeFalse();
});

it('prints the plan table per site with the PHP used and the block', function (): void {
    $exit = Artisan::call('plan', ['sites' => ['botiga-a'], '--wait' => true]);

    expect($exit)->toBe(0)
        ->and(Artisan::output())
        ->toContain('PHP 8.2.20 (cli): no verificat pel web, és el del CLI')
        ->toContain('Bloquejat: no')
        ->toContain('| core:wordpress | 6.8.1  | 6.8.2    | actualitzar |')
        ->toContain('| plugin:akismet | 5.3    | 6.0      | ometre      | major pendent: cal staging (F7) — Canvi major |')
        ->toContain("1 s'actualitzarien, 1 s'ometrien.");
});

it('shows a failed plan run with its reason', function (): void {
    command('plan', ['sites' => ['blog'], '--wait' => true])
        ->expectsOutputToContain('connexió caiguda')
        ->assertExitCode(1);
});

it('prints a versioned JSON document', function (): void {
    Artisan::call('plan', ['sites' => ['botiga-a', 'blog'], '--wait' => true, '--json' => true]);
    /** @var array{version: int, sites: list<array{site: string, status: string, plan: array{changes: list<array<string, mixed>>}|null}>} $json */
    $json = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($json['version'])->toBe(1)
        ->and($json['sites'])->toHaveCount(2)
        ->and($json['sites'][1]['site'])->toBe('botiga-a')
        ->and($json['sites'][1]['plan'])->toMatchArray(['updates' => 1, 'skips' => 1, 'php' => ['version' => '8.2.20', 'source' => 'cli', 'verified' => false]])
        ->and(($json['sites'][1]['plan']['changes'] ?? [])[1])->toMatchArray(['slug' => 'akismet', 'action' => 'skip', 'reason' => 'major_requires_staging'])
        ->and($json['sites'][0]['status'])->toBe('failed')
        ->and($json['sites'][0]['plan'])->toBeNull();
});

it('refuses a report version that is not an integer', function (): void {
    config(['wpup.planning.report_version' => '1']);

    expect(fn () => app(PlanReport::class)->document([]))->toThrow(UnexpectedValueException::class);
});

it('shows a stored plan that is not valid as a failed row and still shows the other sites', function (): void {
    Site::factory()->create(['name' => 'trencat', 'url' => 'https://trencat.test']);

    $exit = Artisan::call('plan', ['sites' => ['botiga-a', 'trencat'], '--wait' => true]);
    $output = Artisan::output();

    expect($exit)->toBe(1)
        ->and($output)->toContain('El pla desat no és vàlid.')
        ->and($output)->toContain('plugin:akismet');
});

it('does not count a succeeded run without a usable plan as a success', function (): void {
    app(PipelineResolver::class)->register(RunType::Plan, static fn (): Pipeline => new Pipeline([
        new ScriptedReadOnlyStep('inventory', static fn (): StepOutcome => StepOutcome::succeeded('ok')),
    ]));

    $exit = Artisan::call('plan', ['sites' => ['botiga-a'], '--wait' => true]);

    expect($exit)->toBe(1)->and(Artisan::output())->toContain("L'execució no ha deixat cap pla.");
});

it('reports blocked from the site row, not from the plan, and null without a site', function (): void {
    Site::query()->where('name', 'blog')->update(['blocked_reason' => 'PHP antic']);
    Artisan::call('plan', ['sites' => ['botiga-a', 'blog'], '--wait' => true, '--json' => true]);
    /** @var array{sites: list<array{site: string, blocked: bool|null}>} $json */
    $json = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect(array_column($json['sites'], 'blocked', 'site'))->toBe(['blog' => true, 'botiga-a' => false]);

    $run = Run::factory()->create(['type' => RunType::Plan, 'site_id' => null]);
    $rows = app(PlanReport::class)->rows([$run]);
    expect($rows[0]->blocked)->toBeNull();
});

it('does not plan inactive sites named explicitly and says so', function (): void {
    $exit = Artisan::call('plan', ['sites' => ['antic', 'botiga-a']]);

    expect($exit)->toBe(0)
        ->and(Artisan::output())->toContain('antic: inactiu, no es planifica.')->toContain('(plan, botiga-a)')
        ->and(Run::query()->count())->toBe(1);
});

it('fails when every named site is inactive', function (): void {
    $exit = Artisan::call('plan', ['sites' => ['antic']]);

    expect($exit)->toBe(1)->and(Artisan::output())->toContain('antic: inactiu, no es planifica.')->and(Run::query()->count())->toBe(0);
});
