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
 * reviewed: the retention stops holding its restore point, which then lasts
 * the retention from the review.
 */

const RUN_ACK_QUESTION = "Marcar-la com a revisada? El seu punt de restauració deixarà d'estar retingut: caducarà d'aquí a 7 dies i seguirà la retenció normal.";

beforeEach(function (): void {
    User::factory()->create(['unix_username' => 'oper1', 'role' => UserRole::Operator]);
    actingAsOperator('oper1');
});

function ackRun(RunStatus $status = RunStatus::RolledBack): Run
{
    $site = Site::factory()->create(['name' => 'botiga-ack']);
    $run = Run::factory()->create(['site_id' => $site->id, 'type' => RunType::Update, 'status' => $status]);
    foreach (BackupKind::cases() as $kind) {
        Backup::factory()->create(['site_id' => $site->id, 'run_id' => $run->id, 'kind' => $kind, 'path' => "site-{$site->id}/run{$run->id}/{$kind->value}", 'expires_at' => now()->subDay()]);
    }

    return $run;
}

/** Rows of the run that expire in the future. */
function liveExpiry(Run $run): int
{
    return Backup::query()->where('run_id', $run->id)->where('expires_at', '>', now())->count();
}

it('marks the run as reviewed and keeps its restore point for the retention from now, after confirming', function (): void {
    $run = ackRun();

    command('run:ack', ['run' => (string) $run->id])
        ->expectsOutputToContain('2 fitxers al punt de restauració')
        ->expectsConfirmation(RUN_ACK_QUESTION, 'yes')
        ->expectsOutputToContain("caduca d'aquí a 7 dies")
        ->assertSuccessful();

    $run->refresh();
    $audit = AuditLogEntry::query()->where('action', 'run.acknowledged')->sole();
    expect($run->reviewed_at)->not->toBeNull()
        ->and($run->reviewed_by)->toBe(User::query()->where('unix_username', 'oper1')->value('id'))
        ->and(liveExpiry($run))->toBe(2)
        ->and(Backup::query()->where('run_id', $run->id)->get()->every(static fn (Backup $b): bool => abs(($b->expires_at?->diffInSeconds(now()->addDays(7))) ?? 999) < 60))->toBeTrue()
        ->and($audit->actor)->toBe('oper1')
        ->and($audit->object_id)->toBe((string) $run->id)
        ->and($audit->context)->toBe(['status' => 'rolled_back', 'extended_backups' => 2]);
});

it('changes nothing with --dry-run', function (): void {
    $run = ackRun();

    command('run:ack', ['run' => (string) $run->id, '--dry-run' => true])
        ->expectsOutputToContain('[dry-run] Es marcaria com a revisada')
        ->assertSuccessful();

    expect($run->refresh()->reviewed_at)->toBeNull()
        ->and(liveExpiry($run))->toBe(0)
        ->and(AuditLogEntry::query()->where('action', 'run.acknowledged')->exists())->toBeFalse();
});

it('changes nothing when the operator does not confirm', function (): void {
    $run = ackRun();

    command('run:ack', ['run' => (string) $run->id])->expectsConfirmation(RUN_ACK_QUESTION, 'no')->assertFailed();

    expect($run->refresh()->reviewed_at)->toBeNull()->and(liveExpiry($run))->toBe(0);
});

it('refuses a run that has not finished', function (RunStatus $status): void {
    $run = ackRun($status);

    command('run:ack', ['run' => (string) $run->id])->expectsOutputToContain('encara no ha acabat')->assertFailed();

    expect($run->refresh()->reviewed_at)->toBeNull()->and(liveExpiry($run))->toBe(0);
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

it('never shortens a longer expiry, and leaves a hand protection as it is', function (): void {
    $run = ackRun();
    $later = now()->addDays(30)->startOfSecond();
    Backup::query()->where('run_id', $run->id)->update(['expires_at' => $later, 'protected' => true]);

    command('run:ack', ['run' => (string) $run->id])->expectsConfirmation(RUN_ACK_QUESTION, 'yes')->assertSuccessful();

    expect(Backup::query()->where('run_id', $run->id)->get()->every(static fn (Backup $b): bool => $b->expires_at?->equalTo($later) === true && $b->protected))->toBeTrue();
});
