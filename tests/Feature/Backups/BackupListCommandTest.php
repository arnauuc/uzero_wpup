<?php

declare(strict_types=1);

use App\Domain\Backups\BackupListingFilter;
use App\Enums\BackupKind;
use App\Enums\UserRole;
use App\Models\Backup;
use App\Models\BackupTarget;
use App\Models\Run;
use App\Models\Site;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;

/**
 * @param  array<string, mixed>  $parameters
 * @return array{version: int, truncated: bool, backups: list<array<string, mixed>>}
 */
function backupListJson(array $parameters = []): array
{
    Artisan::call('backup:list', [...$parameters, '--json' => true]);

    /** @var array{version: int, truncated: bool, backups: list<array<string, mixed>>} */
    return json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
}

/**
 * @param  array<string, mixed>  $parameters
 * @return list<int>
 */
function backupListIds(array $parameters = []): array
{
    /** @var list<int> */
    return array_column(backupListJson($parameters)['backups'], 'id');
}

/**
 * Ids of the three backups of the current test (set once in beforeEach).
 *
 * @return array{old: int, recent: int, deleted: int}
 */
function backupIds(?int $old = null, ?int $recent = null, ?int $deleted = null): array
{
    /** @var array{old: int, recent: int, deleted: int} $ids */
    static $ids = ['old' => 0, 'recent' => 0, 'deleted' => 0];
    if ($old !== null && $recent !== null && $deleted !== null) {
        $ids = ['old' => $old, 'recent' => $recent, 'deleted' => $deleted];
    }

    return $ids;
}

beforeEach(function (): void {
    User::factory()->create(['unix_username' => 'oper1', 'role' => UserRole::Operator]);
    actingAsOperator('oper1');

    $a = Site::factory()->create(['name' => 'botiga-a']);
    $b = Site::factory()->create(['name' => 'blog']);
    $local = BackupTarget::factory()->create(['name' => 'local-site']);
    $dropbox = BackupTarget::factory()->create(['name' => 'dropbox']);

    $old = Backup::factory()->create(['site_id' => $a->id, 'backup_target_id' => $local->id, 'kind' => BackupKind::Database, 'created_at' => '2026-01-10 12:00:00', 'sha256' => str_repeat('cd', 32), 'size_bytes' => 3_145_728, 'path' => 'old/db']);
    $recent = Backup::factory()->create(['site_id' => $b->id, 'backup_target_id' => $dropbox->id, 'kind' => BackupKind::Files, 'created_at' => '2026-03-20 08:00:00', 'verified_at' => now(), 'expires_at' => '2026-03-27 08:00:00', 'protected' => true, 'run_id' => Run::factory()->create()->id]);
    $deleted = Backup::factory()->create(['site_id' => $a->id, 'backup_target_id' => $local->id, 'created_at' => '2026-02-01 00:00:00', 'deleted_at' => '2026-02-09 00:00:00']);
    backupIds($old->id, $recent->id, $deleted->id);
});

it('lists the backups that are not deleted, newest first', function (): void {
    expect(backupListIds())->toBe([backupIds()['recent'], backupIds()['old']]);
});

it('includes the deleted ones with --all', function (): void {
    expect(backupListIds(['--all' => true]))->toBe([backupIds()['recent'], backupIds()['deleted'], backupIds()['old']]);
});

it('filters by site, destination and kind', function (): void {
    expect(backupListIds(['--site' => ['botiga-a']]))->toBe([backupIds()['old']])
        ->and(backupListIds(['--site' => ['botiga-a', 'blog']]))->toHaveCount(2)
        ->and(backupListIds(['--target' => ['dropbox']]))->toBe([backupIds()['recent']])
        ->and(backupListIds(['--kind' => 'files']))->toBe([backupIds()['recent']]);
});

it('filters by date, with the until day included', function (): void {
    expect(backupListIds(['--since' => '2026-03-01']))->toBe([backupIds()['recent']])
        ->and(backupListIds(['--until' => '2026-01-10']))->toBe([backupIds()['old']])
        ->and(backupListIds(['--since' => '2026-01-11', '--until' => '2026-03-19', '--all' => true]))->toBe([backupIds()['deleted']]);
});

it('orders by creation date, then id', function (): void {
    $newer = Backup::factory()->create(['site_id' => Site::query()->firstOrFail()->id, 'backup_target_id' => BackupTarget::query()->firstOrFail()->id, 'created_at' => '2025-01-01 00:00:00']);

    expect(backupListIds())->toBe([backupIds()['recent'], backupIds()['old'], $newer->id]);
});

it('includes the whole until day and nothing after it', function (): void {
    $edge = Backup::factory()->create(['site_id' => Site::query()->firstOrFail()->id, 'backup_target_id' => BackupTarget::query()->firstOrFail()->id, 'created_at' => '2026-03-20 23:59:59']);
    $after = Backup::factory()->create(['site_id' => Site::query()->firstOrFail()->id, 'backup_target_id' => BackupTarget::query()->firstOrFail()->id, 'created_at' => '2026-03-21 00:00:00']);

    $ids = backupListIds(['--until' => '2026-03-20']);

    expect($ids)->toContain($edge->id)
        ->and(in_array($after->id, $ids, true))->toBeFalse();
});

it('limits the listing and warns when it cuts', function (): void {
    $json = backupListJson(['--limit' => '1']);
    expect($json['backups'])->toHaveCount(1)->and($json['truncated'])->toBeTrue()
        ->and(backupListJson()['truncated'])->toBeFalse();

    command('backup:list', ['--limit' => '1'])->expectsOutputToContain('afina els filtres o puja --limit')->assertSuccessful();

    config(['wpup.backups.list_limit' => 1]);
    expect(backupListJson()['backups'])->toHaveCount(1);
});

it('rejects a limit that is not a positive integer', function (): void {
    command('backup:list', ['--limit' => '0'])->expectsOutputToContain('--limit ha de ser un enter positiu.')->assertExitCode(1);
});

it('prints a table with size, short checksum and expiry', function (): void {
    Artisan::call('backup:list');
    $output = Artisan::output();

    expect($output)->toContain('botiga-a')->toContain('3.0 MB')->toContain('cdcdcdcdcdcd')
        ->toContain('2026-03-27 08:00')->toContain('local-site')
        ->and($output)->not->toContain(str_repeat('cd', 32));
});

it('prints the full checksum and the shape in JSON', function (): void {
    $json = backupListJson(['--site' => ['botiga-a']]);

    expect($json['version'])->toBe(1)
        ->and($json['backups'][0])->toMatchArray([
            'id' => backupIds()['old'], 'site' => 'botiga-a', 'kind' => 'database', 'target' => 'local-site', 'key' => 'old/db',
            'size_bytes' => 3_145_728, 'sha256' => str_repeat('cd', 32), 'verified' => false, 'expires_at' => null, 'protected' => false, 'deleted' => false,
        ]);
});

it('says so when nothing matches', function (): void {
    command('backup:list', ['--site' => ['blog'], '--kind' => 'database'])
        ->expectsOutputToContain('No hi ha cap còpia')
        ->assertSuccessful();
});

it('rejects invalid filters with a clear error', function (string $option, string $value, string $message): void {
    command('backup:list', [$option => $option === '--site' || $option === '--target' ? [$value] : $value])
        ->expectsOutputToContain($message)
        ->assertExitCode(1);
})->with([
    'bad date' => ['--since', '2026-02-30', 'Data no vàlida a --since'],
    'bad format' => ['--until', '10/01/2026', 'Data no vàlida a --until'],
    'bad kind' => ['--kind', 'logs', 'Tipus de fitxer no vàlid'],
    'unknown site' => ['--site', 'cap', 'Lloc desconegut: cap'],
    'unknown target' => ['--target', 'cap', 'Destinació desconeguda: cap'],
]);

it('rejects a since after the until', function (): void {
    expect(fn () => BackupListingFilter::fromInput([], [], '2026-03-02', '2026-03-01', null, false))
        ->toThrow(InvalidArgumentException::class, '--since no pot ser posterior');
});

it('is read only and open to anyone who can view reports', function (): void {
    expect(Artisan::all()['backup:list']->getDefinition()->hasOption('dry-run'))->toBeFalse();
});
