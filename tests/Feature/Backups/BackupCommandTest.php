<?php

declare(strict_types=1);

use App\Domain\Backups\BackupReport;
use App\Domain\Backups\RestorePoint;
use App\Domain\Runs\Pipeline;
use App\Domain\Runs\PipelineResolver;
use App\Domain\Runs\StepOutcome;
use App\Enums\BackupKind;
use App\Enums\RunType;
use App\Enums\UserRole;
use App\Models\Backup;
use App\Models\BackupTarget;
use App\Models\Run;
use App\Models\Site;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Redis;
use Tests\Support\Runs\ScriptedReadOnlyStep;
use Tests\Support\Runs\ScriptedStep;

beforeEach(function (): void {
    Redis::connection('locks')->command('flushdb');
    User::factory()->create(['unix_username' => 'oper1', 'role' => UserRole::Operator]);
    actingAsOperator('oper1');

    Site::factory()->create(['name' => 'botiga-a', 'url' => 'https://a.test', 'tags' => ['woo']]);
    Site::factory()->create(['name' => 'blog', 'url' => 'https://blog.test']);
    Site::factory()->create(['name' => 'antic', 'tags' => ['woo'], 'active' => false]);

    $target = BackupTarget::factory()->create(['name' => 'local-site']);

    app(PipelineResolver::class)->register(RunType::Backup, static function (Run $run) use ($target): Pipeline {
        return new Pipeline([
            new ScriptedReadOnlyStep('backup', static function () use ($run, $target): StepOutcome {
                if ($run->site?->name === 'blog') {
                    return StepOutcome::failed('disc ple');
                }
                $files = [];
                foreach ([BackupKind::Database, BackupKind::Files] as $kind) {
                    $files[] = Backup::factory()->create([
                        'site_id' => $run->site_id, 'run_id' => $run->id, 'backup_target_id' => $target->id, 'kind' => $kind,
                        'path' => "k/{$kind->value}", 'size_bytes' => 2_097_152, 'sha256' => str_repeat('ab', 32), 'verified_at' => now(),
                    ]);
                }
                $point = new RestorePoint((int) $run->site_id, $run->id, $target->id, 'k', $files, ['Dropbox no verificat']);

                return StepOutcome::succeeded('ok', '', $point->summary());
            }),
        ]);
    });
});

it('queues one backup run per selected site and only prints the ids', function (): void {
    command('backup', ['--tag' => ['woo']])
        ->expectsOutputToContain('encuada (backup, botiga-a)')
        ->assertSuccessful();

    expect(Run::query()->where('type', RunType::Backup)->count())->toBe(1);
});

it('skips an inactive site named explicitly', function (): void {
    command('backup', ['sites' => ['antic']])
        ->expectsOutputToContain('antic: inactiu')
        ->assertExitCode(1);

    expect(Run::query()->count())->toBe(0);
});

it('does not exit 0 when a named site was skipped, even if the others went well', function (): void {
    $exit = Artisan::call('backup', ['sites' => ['antic', 'botiga-a'], '--wait' => true]);

    expect($exit)->toBe(1)->and(Artisan::output())->toContain('antic: inactiu')->toContain('local-site');
});

it('passes --dry-run to the run as a flag', function (): void {
    command('backup', ['sites' => ['botiga-a'], '--dry-run' => true])->assertSuccessful();

    expect(Run::query()->firstOrFail()->flags['dry_run'] ?? null)->toBeTrue();
});

it('prints the restore point of each site with --wait', function (): void {
    $exit = Artisan::call('backup', ['sites' => ['botiga-a'], '--wait' => true]);
    $output = Artisan::output();

    expect($exit)->toBe(0)
        ->and($output)->toContain('botiga-a')
        ->toContain('local-site')
        ->toContain('2.0 MB')
        ->toContain('abababababab')
        ->toContain('Dropbox no verificat')
        ->and($output)->not->toContain(str_repeat('ab', 32));
});

it('shows a failed run with its reason and exits with an error', function (): void {
    command('backup', ['sites' => ['blog'], '--wait' => true])
        ->expectsOutputToContain('disc ple')
        ->assertExitCode(1);
});

it('prints a versioned JSON document with the full checksums', function (): void {
    Artisan::call('backup', ['sites' => ['botiga-a', 'blog'], '--wait' => true, '--json' => true]);
    /** @var array{version: int, sites: list<array{site: string, directory: string, failure: ?string, files: list<array<string, mixed>>}>} $json */
    $json = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($json['version'])->toBe(1)
        ->and($json['sites'])->toHaveCount(2)
        ->and($json['sites'][1]['site'])->toBe('botiga-a')
        ->and($json['sites'][1]['directory'])->toBe('k')
        ->and($json['sites'][1]['files'])->toHaveCount(2)
        ->and($json['sites'][1]['files'][0])->toMatchArray(['kind' => 'database', 'target' => 'local-site', 'sha256' => str_repeat('ab', 32), 'verified' => true])
        ->and($json['sites'][0]['failure'])->toBe('disc ple')
        ->and($json['sites'][0]['files'])->toBe([]);
});

it('treats a dry run as a success and shows what it would do', function (): void {
    app(PipelineResolver::class)->register(RunType::Backup, static fn (): Pipeline => new Pipeline([
        new ScriptedStep('backup', static fn (): StepOutcome => StepOutcome::succeeded('ok')),
    ]));

    $exit = Artisan::call('backup', ['sites' => ['botiga-a'], '--wait' => true, '--dry-run' => true]);

    expect($exit)->toBe(0)->and(Artisan::output())->toContain('[dry-run] faria «backup»');
    expect(Backup::query()->count())->toBe(0);
});

it('does not count a succeeded run without a restore point as a success', function (): void {
    app(PipelineResolver::class)->register(RunType::Backup, static fn (): Pipeline => new Pipeline([
        new ScriptedReadOnlyStep('inventory', static fn (): StepOutcome => StepOutcome::succeeded('ok')),
    ]));

    $exit = Artisan::call('backup', ['sites' => ['botiga-a'], '--wait' => true]);

    expect($exit)->toBe(1)->and(Artisan::output())->toContain("L'execució no ha deixat cap punt de restauració.");
});

it('fails a succeeded run that left no files', function (): void {
    app(PipelineResolver::class)->register(RunType::Backup, static fn (): Pipeline => new Pipeline([
        new ScriptedReadOnlyStep('backup', static fn (): StepOutcome => StepOutcome::succeeded('ok', '', ['directory' => 'k', 'reused' => false, 'backups' => [], 'warnings' => []])),
    ]));

    $exit = Artisan::call('backup', ['sites' => ['botiga-a'], '--wait' => true]);

    expect($exit)->toBe(1)->and(Artisan::output())->toContain("L'execució no ha deixat cap fitxer de còpia.");
});

it('falls back to the destination id in terminal and JSON when the destination is gone', function (): void {
    $file = ['id' => 1, 'backup_target_id' => 999_999, 'kind' => 'database', 'key' => 'k/db', 'size_bytes' => 10, 'sha256' => str_repeat('ef', 32), 'verified' => true, 'expires_at' => '2026-03-27T08:00:00+00:00'];
    app(PipelineResolver::class)->register(RunType::Backup, static fn (): Pipeline => new Pipeline([
        new ScriptedReadOnlyStep('backup', static fn (): StepOutcome => StepOutcome::succeeded('ok', '', ['directory' => 'k', 'reused' => false, 'backups' => [$file], 'warnings' => []])),
    ]));

    Artisan::call('backup', ['sites' => ['botiga-a'], '--wait' => true]);
    expect(Artisan::output())->toContain('#999999')->toContain('2026-03-27 08:00');

    Artisan::call('backup', ['sites' => ['botiga-a'], '--wait' => true, '--json' => true]);
    /** @var array{sites: list<array{files: list<array{target: string}>}>} $json */
    $json = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    expect($json['sites'][0]['files'][0]['target'])->toBe('#999999');
});

it('refuses a report version that is not an integer', function (): void {
    config(['wpup.backups.report_version' => '1']);

    expect(fn () => app(BackupReport::class)->document([]))->toThrow(UnexpectedValueException::class);
});
