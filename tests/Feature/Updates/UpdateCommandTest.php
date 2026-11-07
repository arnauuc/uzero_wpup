<?php

declare(strict_types=1);

use App\Domain\Planning\PlanAction;
use App\Domain\Planning\PlannedChange;
use App\Domain\Planning\PlanStep;
use App\Domain\Planning\UpdatePlan;
use App\Domain\Rollback\DatabaseRollbackStep;
use App\Domain\Rollback\FilesRollbackStep;
use App\Domain\Runs\OnFailure;
use App\Domain\Runs\Pipeline;
use App\Domain\Runs\PipelineResolver;
use App\Domain\Runs\StepContext;
use App\Domain\Runs\StepOutcome;
use App\Domain\Updates\UpdateChanges;
use App\Enums\ChangeResult;
use App\Enums\ComponentType;
use App\Enums\PhpVersionSource;
use App\Enums\RunStatus;
use App\Enums\RunType;
use App\Enums\UpdateStrategy;
use App\Enums\UserRole;
use App\Models\AuditLogEntry;
use App\Models\Run;
use App\Models\Site;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Redis;
use Tests\Support\Runs\ScriptedReadOnlyStep;
use Tests\Support\Runs\ScriptedStep;

/*
 * `wpup update` (UZEROWPUP-60): queued update runs, the strategy, --now,
 * --dry-run as the plan, and what is printed once they are done. The pipeline
 * itself has its own tests (InPlaceUpdateRunTest).
 */

beforeEach(function (): void {
    Redis::connection('locks')->command('flushdb');
    User::factory()->create(['unix_username' => 'oper1', 'role' => UserRole::Operator]);
    User::factory()->create(['unix_username' => 'admin1', 'role' => UserRole::Admin]);
    actingAsOperator('oper1');

    Site::factory()->create(['name' => 'botiga', 'url' => 'https://botiga.test', 'tags' => ['woo']]);
    Site::factory()->create(['name' => 'blog', 'url' => 'https://blog.test']);
    Site::factory()->create(['name' => 'gran', 'strategy' => UpdateStrategy::StagingFirst]);

    $update = new PlannedChange(ComponentType::Plugin, 'classic-editor', '1.6.3', '1.6.4', PlanAction::Update);
    $plan = static fn (): StepOutcome => StepOutcome::succeeded('pla', '', (new UpdatePlan('8.3.14', PhpVersionSource::PleskHandler, true, null, [$update]))->toArray());
    $planStep = new ScriptedReadOnlyStep(PlanStep::NAME, static fn (): StepOutcome => $plan());
    // `blog` updates and fails, and rolls back without its database (new orders).
    app(PipelineResolver::class)->register(RunType::Update, static fn (Run $run): Pipeline => $run->site?->name === 'blog'
        ? new Pipeline(
            [$planStep, new ScriptedStep('update:plugin:classic-editor', static function (StepContext $c): StepOutcome {
                $c->run->changes()->create(['step' => UpdateChanges::STEP, 'component_type' => ComponentType::Plugin, 'slug' => 'classic-editor',
                    'version_before' => '1.6.3', 'version_after' => '1.6.4', 'result' => ChangeResult::Updated]);

                return StepOutcome::failed('fatal nou');
            })],
            [
                new ScriptedStep(FilesRollbackStep::NAME, static fn (): StepOutcome => StepOutcome::succeeded('fitxers')),
                new ScriptedStep(DatabaseRollbackStep::NAME, static fn (): StepOutcome => StepOutcome::succeeded('BD no restaurada', '', [
                    'restored' => false, 'warning' => 'Hi ha 1 comanda nova des de la còpia (#77).', 'force_command' => 'wpup backup:restore blog --db --accept-data-loss',
                ])),
            ],
            ['update:plugin:classic-editor' => OnFailure::Recover],
        )
        : new Pipeline([$planStep, new ScriptedStep('update:plugin:classic-editor', static function (StepContext $c): StepOutcome {
            $c->run->changes()->create(['step' => UpdateChanges::STEP, 'component_type' => ComponentType::Plugin, 'slug' => 'classic-editor',
                'version_before' => '1.6.3', 'version_after' => '1.6.4', 'result' => ChangeResult::Updated]);

            return StepOutcome::succeeded('actualitzat');
        })]));
    app(PipelineResolver::class)->register(RunType::Plan, static fn (): Pipeline => new Pipeline([
        new ScriptedReadOnlyStep(PlanStep::NAME, static fn (): StepOutcome => $plan()),
    ]));
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('queues one in-place update run per selected site and only prints the ids', function (): void {
    command('update', ['--tag' => ['woo']])
        ->expectsOutputToContain('encuada (update, botiga)')
        ->assertSuccessful();

    $run = Run::query()->sole();
    expect($run->type)->toBe(RunType::Update)
        ->and($run->strategy)->toBe(UpdateStrategy::InPlace)
        ->and($run->flags)->toBe([]);
});

it('prints what each update did once it is finished', function (): void {
    $exit = Artisan::call('update', ['sites' => ['botiga', 'blog'], '--wait' => true]);
    $output = Artisan::output();

    expect($exit)->not->toBe(0)
        ->and($output)->toContain('botiga — execució')
        ->and($output)->toContain('Actualitzats: 1.')
        ->and($output)->toContain('plugin:classic-editor')
        ->and($output)->toContain('fatal nou')
        ->and($output)->toContain('Rollback: fitxers restaurats; base de dades NO restaurada.')
        ->and($output)->toContain('Hi ha 1 comanda nova des de la còpia (#77).')
        ->and($output)->toContain('wpup backup:restore blog --db --accept-data-loss')
        ->and(Run::query()->where('status', RunStatus::RolledBack->value)->count())->toBe(1);
});

it('prints a JSON document per run', function (): void {
    Artisan::call('update', ['sites' => ['blog'], '--wait' => true, '--json' => true]);
    /** @var array{runs: list<array{rollback: array<string, mixed>, changes: list<array{slug: string}>}>} $document */
    $document = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($document['runs'][0])->toMatchArray(['site' => 'blog', 'status' => 'rolled_back', 'strategy' => 'in_place'])
        ->and($document['runs'][0]['rollback'])->toBe([
            'files_restored' => true,
            'database' => 'not_restored',
            'database_warning' => 'Hi ha 1 comanda nova des de la còpia (#77).',
            'force_command' => 'wpup backup:restore blog --db --accept-data-loss',
        ])
        ->and($document['runs'][0]['changes'][0]['slug'])->toBe('classic-editor');
});

it('refuses the staging strategy until F7, and unknown ones', function (string $strategy, string $message): void {
    $exit = Artisan::call('update', ['sites' => ['botiga'], '--strategy' => $strategy]);

    expect($exit)->toBe(1)
        ->and(Artisan::output())->toContain($message)
        ->and(Run::query()->count())->toBe(0);
})->with([
    ['staging', 'arriba a F7'],
    ['staging-first', 'arriba a F7'],
    ['rapida', "L'estratègia «rapida» no existeix"],
]);

it('leaves a site whose own strategy is staging unless --strategy=in-place says so', function (): void {
    $exit = Artisan::call('update', ['sites' => ['gran', 'botiga']]);

    expect($exit)->toBe(1)
        ->and(Artisan::output())->toContain('gran: la seva estratègia és staging')
        ->and(Run::query()->pluck('site_id')->all())->toBe([Site::query()->where('name', 'botiga')->value('id')]);

    command('update', ['sites' => ['gran'], '--strategy' => 'in-place'])->assertSuccessful();

    expect(Run::query()->where('site_id', Site::query()->where('name', 'gran')->value('id'))->sole()->strategy)->toBe(UpdateStrategy::InPlace);
});

it('is the plan with --dry-run: it queues a plan run and no update', function (): void {
    $exit = Artisan::call('update', ['sites' => ['botiga'], '--dry-run' => true, '--wait' => true]);
    $output = Artisan::output();

    expect($exit)->toBe(0)
        ->and($output)->toContain("[dry-run] L'actualització faria primer aquest pla")
        ->and($output)->toContain('plugin:classic-editor | 1.6.3  | 1.6.4')
        ->and(Run::query()->sole()->type)->toBe(RunType::Plan);
});

it('plans with --dry-run only the sites the update would update, and checks --now first', function (): void {
    $exit = Artisan::call('update', ['sites' => ['gran', 'botiga'], '--dry-run' => true, '--wait' => true]);
    $output = Artisan::output();

    expect($exit)->toBe(1)
        ->and($output)->toContain('gran: la seva estratègia és staging')
        ->and(Run::query()->pluck('site_id')->all())->toBe([Site::query()->where('name', 'botiga')->value('id')]);

    expect(Artisan::call('update', ['sites' => ['botiga'], '--dry-run' => true, '--now' => true]))->toBe(1)
        ->and(Run::query()->count())->toBe(1)
        ->and(AuditLogEntry::query()->where('action', 'command.denied')->sole()->context)->toMatchArray(['ability' => 'skip-queue']);
});

it('refuses --now and the version policy overrides to an operator, audited', function (string $flag, string $ability): void {
    $exit = Artisan::call('update', ['sites' => ['botiga'], $flag => true]);

    expect($exit)->toBe(1)
        ->and(Run::query()->count())->toBe(0)
        ->and(AuditLogEntry::query()->where('action', 'command.denied')->sole()->context)->toMatchArray(['ability' => $ability]);
})->with([
    ['--now', 'skip-queue'],
    ['--allow-major', 'override-version-policy'],
]);

it('lets an admin start now, audited and stored on the run', function (): void {
    actingAsOperator('admin1');

    command('update', ['sites' => ['botiga'], '--now' => true, '--allow-major' => true])->assertSuccessful();

    expect(Run::query()->sole()->flags)->toEqual(['allow_major' => true, 'now' => true])
        ->and(AuditLogEntry::query()->where('action', 'update.now')->sole()->context)->toBe(['sites' => ['botiga']])
        ->and(AuditLogEntry::query()->where('action', 'plan.version_policy_overridden')->sole()->context)->toBe(['flags' => ['allow_major' => true], 'sites' => ['botiga']]);
});

it('passes --include to the plan of the update run, for an admin, audited', function (): void {
    actingAsOperator('admin1');

    command('update', ['sites' => ['botiga'], '--include' => ['akismet']])
        ->expectsOutputToContain('--include=akismet no coincideix amb cap component congelat o exclòs.')
        ->assertSuccessful();

    expect(Run::query()->sole()->flags)->toEqual(['include' => ['akismet']])
        ->and(AuditLogEntry::query()->where('action', 'plan.profile_overridden')->sole()->context)->toMatchArray(['include' => ['akismet'], 'sites' => ['botiga']]);
});

it('says when an update outside its window will start, and leaves it queued', function (): void {
    config(['wpup.updates.window.enforced' => true]);
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-02 12:00', 'Europe/Madrid'));

    $exit = Artisan::call('update', ['sites' => ['botiga']]);

    expect($exit)->toBe(0)
        ->and(Artisan::output())->toContain('botiga: fora de la finestra de manteniment (cada dia 01:00–06:00); l\'actualització començarà a partir de les 01:00 del 03/11/2026.')
        ->and(Run::query()->sole()->status)->toBe(RunStatus::Queued);
});

it('offers --dry-run, as every command that changes something', function (): void {
    expect(Artisan::all()['update']->getDefinition()->hasOption('dry-run'))->toBeTrue();
});
