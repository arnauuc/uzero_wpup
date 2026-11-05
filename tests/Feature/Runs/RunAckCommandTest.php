<?php

declare(strict_types=1);

use App\Enums\BackupKind;
use App\Enums\RunStatus;
use App\Enums\RunType;
use App\Enums\UserRole;
use App\Models\AuditLogEntry;
use App\Models\Backup;
use App\Models\Run;
use App\Models\Site;
use App\Models\User;

/*
 * `wpup run:ack` (UZEROWPUP-44, ADR-0009): an operator marks a finished run as
 * reviewed and its restore point stops being protected.
 */

const RUN_ACK_QUESTION = "Marcar-la com a revisada? El seu punt de restauració deixarà d'estar protegit i s'esborrarà segons la retenció.";

beforeEach(function (): void {
    User::factory()->create(['unix_username' => 'oper1', 'role' => UserRole::Operator]);
    actingAsOperator('oper1');
});

function ackRun(RunStatus $status = RunStatus::RolledBack): Run
{
    $site = Site::factory()->create(['name' => 'botiga-ack']);
    $run = Run::factory()->create(['site_id' => $site->id, 'type' => RunType::Update, 'status' => $status]);
    foreach (BackupKind::cases() as $kind) {
        Backup::factory()->create(['site_id' => $site->id, 'run_id' => $run->id, 'kind' => $kind, 'path' => "site-{$site->id}/run{$run->id}/{$kind->value}", 'protected' => true]);
    }

    return $run;
}

function protectedRows(Run $run): int
{
    return Backup::query()->where('run_id', $run->id)->where('protected', true)->count();
}

it('marks the run as reviewed and lifts the protection of its restore point, after confirming', function (): void {
    $run = ackRun();

    command('run:ack', ['run' => (string) $run->id])
        ->expectsOutputToContain('2 fitxers protegits')
        ->expectsConfirmation(RUN_ACK_QUESTION, 'yes')
        ->expectsOutputToContain('2 fitxers desprotegits')
        ->assertSuccessful();

    $run->refresh();
    $audit = AuditLogEntry::query()->where('action', 'run.acknowledged')->sole();
    expect($run->reviewed_at)->not->toBeNull()
        ->and($run->reviewed_by)->toBe(User::query()->where('unix_username', 'oper1')->value('id'))
        ->and(protectedRows($run))->toBe(0)
        ->and($audit->actor)->toBe('oper1')
        ->and($audit->object_id)->toBe((string) $run->id)
        ->and($audit->context)->toBe(['status' => 'rolled_back', 'unprotected_backups' => 2]);
});

it('changes nothing with --dry-run', function (): void {
    $run = ackRun();

    command('run:ack', ['run' => (string) $run->id, '--dry-run' => true])
        ->expectsOutputToContain("[dry-run] Es marcaria com a revisada i se'n desprotegirien 2 fitxers.")
        ->assertSuccessful();

    expect($run->refresh()->reviewed_at)->toBeNull()
        ->and(protectedRows($run))->toBe(2)
        ->and(AuditLogEntry::query()->where('action', 'run.acknowledged')->exists())->toBeFalse();
});

it('changes nothing when the operator does not confirm', function (): void {
    $run = ackRun();

    command('run:ack', ['run' => (string) $run->id])->expectsConfirmation(RUN_ACK_QUESTION, 'no')->assertFailed();

    expect($run->refresh()->reviewed_at)->toBeNull()->and(protectedRows($run))->toBe(2);
});

it('refuses a run that has not finished', function (RunStatus $status): void {
    $run = ackRun($status);

    command('run:ack', ['run' => (string) $run->id])->expectsOutputToContain('encara no ha acabat')->assertFailed();

    expect($run->refresh()->reviewed_at)->toBeNull()->and(protectedRows($run))->toBe(2);
})->with([RunStatus::Queued, RunStatus::Running, RunStatus::RollingBack, RunStatus::Interrupted]);

it('says so when the run was already reviewed, without auditing again', function (): void {
    $run = ackRun();
    $run->update(['reviewed_at' => now()->subDay()]);

    command('run:ack', ['run' => (string) $run->id])->expectsOutputToContain('ja està revisada')->assertSuccessful();

    expect(AuditLogEntry::query()->where('action', 'run.acknowledged')->exists())->toBeFalse();
});

it('fails for an unknown run', function (): void {
    command('run:ack', ['run' => '999999'])->expectsOutputToContain('No hi ha cap execució')->assertFailed();
    command('run:ack', ['run' => 'abc'])->assertFailed();
});
