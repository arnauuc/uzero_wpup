<?php

declare(strict_types=1);

use App\Console\ChangesState;
use App\Domain\Runs\Exceptions\RunException;
use App\Domain\Runs\Pipeline;
use App\Domain\Runs\PipelineResolver;
use App\Domain\Runs\RunEngine;
use App\Domain\Runs\RunFollower;
use App\Domain\Runs\StepOutcome;
use App\Domain\Sites\SiteSelectionException;
use App\Domain\Sites\SiteSelector;
use App\Enums\RunStatus;
use App\Enums\RunType;
use App\Enums\UserRole;
use App\Models\Run;
use App\Models\Site;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Redis;
use Tests\Support\Console\DemoRunCommand;
use Tests\Support\Runs\ScriptedCompensableStep;
use Tests\Support\Runs\ScriptedStep;

beforeEach(function (): void {
    Redis::connection('locks')->command('flushdb');
    User::factory()->create(['unix_username' => 'oper1', 'role' => UserRole::Operator]);
    actingAsOperator('oper1');

    Artisan::registerCommand(app(DemoRunCommand::class));

    Site::factory()->create(['name' => 'botiga-a', 'tags' => ['woo', 'nit']]);
    Site::factory()->create(['name' => 'botiga-b', 'tags' => ['woo']]);
    Site::factory()->create(['name' => 'blog', 'tags' => ['nit']]);
    Site::factory()->create(['name' => 'antic', 'tags' => ['woo'], 'active' => false]);
});

/**
 * @param  list<string>  $names
 * @param  list<string>  $tags
 * @return list<string>
 */
function selectNames(array $names = [], array $tags = [], bool $all = false): array
{
    return array_values(app(SiteSelector::class)->select($names, $tags, $all)->map(static fn (Site $site): string => $site->name)->all());
}

it('selects sites by name, by any of the tags or all active ones', function (): void {
    expect(selectNames(['blog', 'antic']))->toBe(['antic', 'blog'])
        ->and(selectNames(tags: ['woo']))->toBe(['botiga-a', 'botiga-b'])
        ->and(selectNames(tags: ['woo', 'nit']))->toBe(['blog', 'botiga-a', 'botiga-b'])
        ->and(selectNames(all: true))->toBe(['blog', 'botiga-a', 'botiga-b']);
});

it('rejects an empty, mixed or unknown selection', function (): void {
    expect(fn () => selectNames())->toThrow(SiteSelectionException::class, '--all')
        ->and(fn () => selectNames(['blog'], all: true))->toThrow(SiteSelectionException::class, 'només una forma')
        ->and(fn () => selectNames(['blog', 'fantasma']))->toThrow(SiteSelectionException::class, 'fantasma')
        ->and(fn () => selectNames(tags: ['cap']))->toThrow(SiteSelectionException::class, 'cap');
});

it('requires --dry-run on every command that changes something', function (): void {
    $changing = array_filter(Artisan::all(), static fn ($command): bool => $command instanceof ChangesState);

    expect($changing)->not->toBeEmpty();
    foreach ($changing as $name => $command) {
        expect($command->getDefinition()->hasOption('dry-run'))->toBeTrue("{$name} has no --dry-run");
    }
});

it('queues one run per site and returns the ids at once', function (): void {
    app(PipelineResolver::class)->register(RunType::Update, static fn (): Pipeline => new Pipeline([
        new ScriptedStep('a', static fn (): StepOutcome => StepOutcome::succeeded('ok')),
    ]));

    command('test:demo-run', ['--tag' => ['woo']])
        ->expectsOutputToContain('encuada (update, botiga-a)')
        ->expectsOutputToContain('encuada (update, botiga-b)')
        ->assertSuccessful();

    expect(Run::query()->count())->toBe(2)
        ->and(Run::query()->value('actor'))->toBe('oper1');
});

it('waits and exits with the worst result', function (): void {
    app(PipelineResolver::class)->register(RunType::Update, static fn (Run $run): Pipeline => new Pipeline([
        new ScriptedCompensableStep('backup', static fn (): StepOutcome => StepOutcome::succeeded('fet'), static fn (): StepOutcome => StepOutcome::succeeded('desfet')),
        new ScriptedStep('update', static fn (): StepOutcome => $run->site?->name === 'blog'
            ? StepOutcome::failed('fatal nou')
            : StepOutcome::succeeded('actualitzat')),
    ]));

    command('test:demo-run', ['sites' => ['blog', 'botiga-a'], '--wait' => true])
        ->expectsOutputToContain('| rolled_back | fatal nou')
        ->expectsOutputToContain('| succeeded   |')
        ->assertExitCode(2);
});

it('follows the steps and prints JSON for scripts', function (): void {
    app(PipelineResolver::class)->register(RunType::Update, static fn (): Pipeline => new Pipeline([
        new ScriptedStep('inventory', static fn (): StepOutcome => StepOutcome::succeeded('3 components')),
    ]));

    command('test:demo-run', ['sites' => ['blog'], '--follow' => true])
        ->expectsOutputToContain('blog] inventory: succeeded — 3 components')
        ->assertSuccessful();

    Artisan::call('test:demo-run', ['sites' => ['blog'], '--wait' => true, '--json' => true]);
    $json = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($json)->toMatchArray(['runs' => [['id' => Run::query()->max('id'), 'site' => 'blog', 'type' => 'update', 'status' => 'succeeded', 'result' => Run::query()->latest('id')->firstOrFail()->result_summary]]]);
});

it('fails a run whose type has no pipeline instead of leaving it queued', function (): void {
    $run = Run::factory()->create();

    expect(fn () => app(RunEngine::class)->execute($run))->toThrow(RunException::class)
        ->and($run->fresh()?->status)->toBe(RunStatus::Failed);
});

it('maps final states to exit codes', function (): void {
    /** @return list<Run> */
    $runs = static fn (RunStatus ...$statuses): array => array_values(array_map(static fn (RunStatus $s): Run => new Run(['status' => $s]), $statuses));

    expect(RunFollower::exitCode($runs(RunStatus::Succeeded, RunStatus::Skipped)))->toBe(0)
        ->and(RunFollower::exitCode($runs(RunStatus::Succeeded, RunStatus::RolledBack)))->toBe(2)
        ->and(RunFollower::exitCode($runs(RunStatus::RolledBack, RunStatus::Failed, RunStatus::Cancelled)))->toBe(1)
        ->and(RunFollower::exitCode($runs(RunStatus::Cancelled)))->toBe(3);
});

it('lists the latest runs', function (): void {
    Run::factory()->create(['site_id' => Site::query()->where('name', 'blog')->value('id'), 'status' => RunStatus::Succeeded]);

    command('runs', ['--site' => 'blog'])->expectsOutputToContain('succeeded')->assertSuccessful();
});
