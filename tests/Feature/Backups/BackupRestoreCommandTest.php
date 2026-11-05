<?php

declare(strict_types=1);

use App\Domain\Backups\RestoreRequest;
use App\Domain\Backups\SiteLocalDestination;
use App\Domain\Runs\Pipeline;
use App\Domain\Runs\PipelineResolver;
use App\Domain\Runs\StepOutcome;
use App\Enums\BackupKind;
use App\Enums\RunType;
use App\Enums\UserRole;
use App\Models\Backup;
use App\Models\Run;
use App\Models\Site;
use App\Models\SiteSnapshot;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Redis;
use Tests\Support\Runs\ScriptedStep;

/*
 * `wpup backup:restore` (UZEROWPUP-46): what it queues and when it refuses.
 * The run itself is covered in RestoreRunTest.
 */

beforeEach(function (): void {
    Redis::connection('locks')->command('flushdb');
    User::factory()->create(['unix_username' => 'oper1', 'role' => UserRole::Operator]);
    actingAsOperator('oper1');
    app(PipelineResolver::class)->register(RunType::Restore, static fn (): Pipeline => new Pipeline([
        new ScriptedStep('restore', static fn (): StepOutcome => StepOutcome::succeeded('Restaurat')),
    ]));
});

/**
 * A verified restore point "site-{id}/run{n}" of $site at the site-local destination.
 *
 * @return array{database: Backup, files: Backup}
 */
function restoreCommandPoint(Site $site, string $label = 'manual-1'): array
{
    $local = app(SiteLocalDestination::class)->record();
    $site->backupTargets()->syncWithoutDetaching([$local->id]);
    $rows = [];
    foreach ([BackupKind::Database, BackupKind::Files] as $kind) {
        $rows[$kind->value] = Backup::factory()->create([
            'site_id' => $site->id, 'backup_target_id' => $local->id, 'kind' => $kind,
            'path' => "site-{$site->id}/{$label}/".($kind === BackupKind::Database ? 'db.sql.gz' : 'files.tar.gz'), 'verified_at' => now(),
        ]);
    }

    return $rows;
}

it('queues a restore run with the chosen copies once the site name is typed', function (): void {
    $site = Site::factory()->create(['name' => 'botiga']);
    $rows = restoreCommandPoint($site);

    command('backup:restore', ['site' => 'botiga', '--point' => 'manual-1', '--files' => true, '--db' => true, '--accept-data-loss' => true])
        ->expectsOutputToContain("es restauraran els fitxers i la base de dades des del punt «site-{$site->id}/manual-1»")
        ->expectsOutputToContain('activa el mode manteniment')
        ->expectsQuestion('Això sobreescriu els fitxers i la base de dades del lloc botiga, amb les comandes, els clients i els usuaris entrats des de la còpia. Escriu el nom del lloc per confirmar-ho', 'botiga')
        ->expectsOutputToContain('encuada (restore, botiga)')
        ->assertSuccessful();

    $run = Run::query()->where('type', RunType::Restore)->sole();
    expect($run->site_id)->toBe($site->id)
        ->and($run->flags)->toMatchArray([
            'dry_run' => false,
            RestoreRequest::POINT_FLAG => "site-{$site->id}/manual-1",
            RestoreRequest::BACKUPS_FLAG => ['files' => [$rows['files']->id], 'database' => [$rows['database']->id]],
            RestoreRequest::ACCEPT_DATA_LOSS_FLAG => true,
            RestoreRequest::DATA_LOSS_CONFIRMED_FLAG => true,
            RestoreRequest::WITHOUT_PRE_BACKUP_FLAG => false,
        ]);
});

it('does nothing when the typed name is not the site', function (): void {
    $site = Site::factory()->create(['name' => 'botiga']);
    restoreCommandPoint($site);

    command('backup:restore', ['site' => 'botiga', '--point' => 'manual-1', '--files' => true])
        ->expectsQuestion('Això sobreescriu els fitxers del lloc botiga. Escriu el nom del lloc per confirmar-ho', 'sí')
        ->expectsOutputToContain("No s'ha restaurat res.")
        ->assertExitCode(1);

    expect(Run::query()->count())->toBe(0);
});

it('needs --force or --dry-run without a terminal or with --json', function (array $options): void {
    $site = Site::factory()->create(['name' => 'botiga']);
    restoreCommandPoint($site);

    $exit = Artisan::call('backup:restore', ['site' => 'botiga', '--point' => 'manual-1', '--files' => true, '--no-interaction' => true] + $options);

    expect($exit)->toBe(1)->and(Artisan::output())->toContain('cal --dry-run o --force')
        ->and(Run::query()->count())->toBe(0);
})->with([
    'no terminal' => [[]],
    'json' => [['--json' => true]],
]);

it('queues without asking with --force when no shop data is at stake, with explicit rows', function (): void {
    $site = Site::factory()->create(['name' => 'blog']);
    SiteSnapshot::factory()->create(['site_id' => $site->id, 'plugins' => [['slug' => 'classic-editor', 'type' => 'plugin', 'version' => '1.6.3', 'active' => true, 'title' => null]]]);
    $rows = restoreCommandPoint($site);

    $exit = Artisan::call('backup:restore', ['site' => 'blog', '--backup' => [(string) $rows['database']->id], '--db' => true, '--force' => true, '--wait' => true]);

    expect($exit)->toBe(0)
        ->and(Run::query()->sole()->flags)->toMatchArray([
            RestoreRequest::BACKUPS_FLAG => ['database' => [$rows['database']->id]],
            RestoreRequest::DATA_LOSS_CONFIRMED_FLAG => false,
        ]);
});

it('never lets --force restore a shop database, or restore without the point of the current state', function (array $options, string $message): void {
    $site = Site::factory()->create(['name' => 'botiga']);
    SiteSnapshot::factory()->create(['site_id' => $site->id, 'plugins' => [['slug' => 'woocommerce', 'type' => 'woocommerce', 'version' => '10.1.0', 'active' => false, 'title' => null]]]);
    restoreCommandPoint($site);

    $exit = Artisan::call('backup:restore', ['site' => 'botiga', '--point' => 'manual-1', '--force' => true, '--no-interaction' => true] + $options);

    expect($exit)->toBe(1)->and(Artisan::output())->toContain($message)
        ->and(Run::query()->count())->toBe(0);
})->with([
    'shop without --accept-data-loss' => [['--db' => true], 'Cal --accept-data-loss'],
    'shop with --accept-data-loss' => [['--db' => true, '--accept-data-loss' => true], "esborra dades d'una botiga: cal confirmar-la en un terminal interactiu, també amb --force"],
    'without the point' => [['--files' => true, '--without-pre-backup' => true], "sense punt de l'estat actual: cal confirmar-la en un terminal interactiu"],
]);

it('queues --without-pre-backup only after the site name is typed', function (): void {
    $site = Site::factory()->create(['name' => 'botiga']);
    restoreCommandPoint($site);

    command('backup:restore', ['site' => 'botiga', '--point' => 'manual-1', '--files' => true, '--without-pre-backup' => true, '--force' => true])
        ->expectsOutputToContain('restaura igualment (--without-pre-backup)')
        ->expectsQuestion("Això sobreescriu els fitxers del lloc botiga, i si no es pot fer el punt de l'estat actual no hi haurà manera de tornar enrere. Escriu el nom del lloc per confirmar-ho", 'botiga')
        ->assertSuccessful();

    expect(Run::query()->sole()->flags[RestoreRequest::WITHOUT_PRE_BACKUP_FLAG] ?? null)->toBeTrue();
});

it('queues a dry run without asking', function (): void {
    $site = Site::factory()->create(['name' => 'botiga']);
    restoreCommandPoint($site);

    $exit = Artisan::call('backup:restore', ['site' => 'botiga', '--point' => 'manual-1', '--files' => true, '--dry-run' => true, '--no-interaction' => true]);

    expect($exit)->toBe(0)->and(Artisan::output())->toContain('[dry-run]')
        ->and(Run::query()->sole()->flags['dry_run'] ?? null)->toBeTrue();
});

it('refuses before queueing anything', function (array $options, string $message, array $filesChange = []): void {
    /** @var array<string, mixed> $filesChange */
    $site = Site::factory()->create(['name' => 'botiga']);
    $rows = restoreCommandPoint($site);
    $other = Site::factory()->create(['name' => 'altre']);
    $otherRows = restoreCommandPoint($other);
    Site::factory()->create(['name' => 'antic', 'active' => false]);
    if ($filesChange !== []) {
        $rows['files']->update($filesChange);
    }
    $options = array_map(static fn (mixed $v): mixed => $v === 'OTHER' ? [(string) $otherRows['files']->id] : $v, $options);

    $exit = Artisan::call('backup:restore', $options + ['site' => 'botiga', '--force' => true]);

    expect($exit)->toBe(1)->and(Artisan::output())->toContain($message)
        ->and(Run::query()->count())->toBe(0);
})->with([
    'nothing asked' => [['--point' => 'manual-1'], '--files, --db'],
    'no point' => [['--files' => true], 'Indica el punt de restauració'],
    'unknown site' => [['site' => 'cap', '--point' => 'manual-1', '--files' => true], 'No hi ha cap lloc «cap»'],
    'inactive site' => [['site' => 'antic', '--point' => 'manual-1', '--files' => true], 'antic: inactiu'],
    'copy of another site' => [['--backup' => 'OTHER', '--files' => true], "és d'un altre lloc"],
    'not a number' => [['--backup' => ['x'], '--files' => true], "l'id numèric"],
    'unverified' => [['--point' => 'manual-1', '--files' => true], 'no està verificada', ['verified_at' => null]],
    'deleted' => [['--point' => 'manual-1', '--files' => true], 'no té cap còpia dels fitxers viva', ['deleted_at' => '2026-01-01 00:00:00']],
]);

it('is refused to a deactivated operator', function (): void {
    User::query()->where('unix_username', 'oper1')->update(['active' => false]);

    expect(Artisan::call('backup:restore', ['site' => 'botiga', '--point' => 'manual-1', '--files' => true, '--force' => true]))->toBe(1)
        ->and(Run::query()->count())->toBe(0);
});
