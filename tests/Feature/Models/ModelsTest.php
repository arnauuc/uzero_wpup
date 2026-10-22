<?php

declare(strict_types=1);

use App\Enums\AuditChannel;
use App\Enums\AuditResult;
use App\Enums\UserRole;
use App\Exceptions\ImmutableRecordException;
use App\Models\AuditLogEntry;
use App\Models\Backup;
use App\Models\BackupTarget;
use App\Models\HostingAccount;
use App\Models\HostingServer;
use App\Models\Report;
use App\Models\Run;
use App\Models\RunChange;
use App\Models\RunStep;
use App\Models\Site;
use App\Models\SiteProfile;
use App\Models\SiteSnapshot;
use App\Models\User;
use App\Models\Verification;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

it('creates a valid row with every factory', function (Factory $factory): void {
    $created = $factory->createOne();

    expect($created->exists)->toBeTrue()
        ->and($created->newQuery()->count())->toBe(1);
})->with(fn (): array => [
    User::factory(), HostingServer::factory(), HostingAccount::factory(), Site::factory(),
    SiteProfile::factory(), SiteSnapshot::factory(), AuditLogEntry::factory(), Run::factory(),
    RunStep::factory(), RunChange::factory(), BackupTarget::factory(), Backup::factory(),
    Verification::factory(), Report::factory(),
]);

it('links site, hosting account, server and profile', function (): void {
    $site = Site::factory()->create();
    $profile = SiteProfile::factory()->create(['site_id' => $site->id]);

    expect($site->hostingAccount)->toBeInstanceOf(HostingAccount::class)
        ->and($site->hostingAccount?->hostingServer)->toBeInstanceOf(HostingServer::class)
        ->and($site->profile?->is($profile))->toBeTrue()
        ->and($site->tags)->toBe([])
        ->and($site->strategy->value)->toBe('in_place');
});

it('links a run to its steps, changes, verifications and report', function (): void {
    $run = Run::factory()->create();
    RunStep::factory()->create(['run_id' => $run->id]);
    RunChange::factory()->create(['run_id' => $run->id]);
    Verification::factory()->create(['run_id' => $run->id]);
    Report::factory()->create(['run_id' => $run->id]);

    expect($run->steps)->toHaveCount(1)
        ->and($run->changes)->toHaveCount(1)
        ->and($run->verifications)->toHaveCount(1)
        ->and($run->report)->toBeInstanceOf(Report::class)
        ->and($run->site)->toBeInstanceOf(Site::class);
});

it('links a backup to its target and site', function (): void {
    $backup = Backup::factory()->create()->refresh();

    expect($backup->backupTarget)->toBeInstanceOf(BackupTarget::class)
        ->and($backup->site)->toBeInstanceOf(Site::class)
        ->and($backup->protected)->toBeFalse();
});

it('rejects values outside the enum at the database level', function (): void {
    DB::table('users')->insert([
        'name' => 'x', 'unix_username' => 'bad_role', 'role' => 'superuser',
        'created_at' => now(), 'updated_at' => now(),
    ]);
})->throws(QueryException::class, 'users_role_check');

it('rejects an invalid run status at the database level', function (): void {
    $run = Run::factory()->create();

    DB::table('runs')->where('id', $run->id)->update(['status' => 'exploded']);
})->throws(QueryException::class, 'runs_status_check');

it('rejects deleting a site that has runs', function (): void {
    $run = Run::factory()->create();

    DB::table('sites')->where('id', $run->site_id)->delete();
})->throws(QueryException::class);

it('refuses to update an audit log entry', function (): void {
    $entry = AuditLogEntry::factory()->create();
    $entry->action = 'tampered';

    $entry->save();
})->throws(ImmutableRecordException::class);

it('refuses to delete an audit log entry', function (): void {
    AuditLogEntry::factory()->create()->delete();
})->throws(ImmutableRecordException::class);

it('lets the runtime role wpup_app insert into audit_log', function (): void {
    expect(DB::scalar('select current_user'))->toBe('wpup_app');

    $entry = AuditLogEntry::query()->create([
        'actor' => 'arnau', 'channel' => AuditChannel::Cli, 'action' => 'run.start',
        'result' => AuditResult::Success, 'ip' => '127.0.0.1', 'context' => ['a' => 1],
        'occurred_at' => now(),
    ]);

    expect($entry->refresh()->ip)->toBe('127.0.0.1')
        ->and($entry->context)->toBe(['a' => 1]);
});

it('casts the user role to the enum', function (): void {
    expect(User::factory()->create(['role' => UserRole::Admin])->refresh()->role)->toBe(UserRole::Admin);
});
