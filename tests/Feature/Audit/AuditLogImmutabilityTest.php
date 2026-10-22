<?php

declare(strict_types=1);

use App\Domain\Audit\AuditActor;
use App\Domain\Audit\AuditLogger;
use App\Enums\AuditChannel;
use App\Enums\AuditResult;
use App\Models\AuditLogEntry;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

// Each test ends with the statement expected to fail: after a database error the
// surrounding transaction is aborted, so nothing else can run in it.

it('records an entry through the audit logger', function (): void {
    $user = User::factory()->create();

    $entry = app(AuditLogger::class)->record(
        AuditActor::user($user, AuditChannel::Cli),
        'site.add',
        AuditResult::Success,
        'site',
        42,
        ['name' => 'botiga'],
    );

    $stored = AuditLogEntry::query()->findOrFail($entry->id);

    expect($stored->user_id)->toBe($user->id)
        ->and($stored->actor)->toBe($user->unix_username)
        ->and($stored->channel)->toBe(AuditChannel::Cli)
        ->and($stored->action)->toBe('site.add')
        ->and($stored->result)->toBe(AuditResult::Success)
        ->and($stored->object_type)->toBe('site')
        ->and($stored->object_id)->toBe('42')
        ->and($stored->context)->toBe(['name' => 'botiga']);
});

it('records unattended actions as the system actor', function (): void {
    $entry = app(AuditLogger::class)->record(AuditActor::system('sistema (scheduler)'), 'cleanup.run', AuditResult::Success);

    expect($entry->user_id)->toBeNull()
        ->and($entry->actor)->toBe('sistema (scheduler)')
        ->and($entry->channel)->toBe(AuditChannel::System);
});

it('denies UPDATE to the runtime role', function (): void {
    $entry = AuditLogEntry::factory()->create();

    DB::table('audit_log')->where('id', $entry->id)->update(['action' => 'tampered']);
})->throws(QueryException::class, 'permission denied');

it('denies DELETE to the runtime role', function (): void {
    $entry = AuditLogEntry::factory()->create();

    DB::table('audit_log')->where('id', $entry->id)->delete();
})->throws(QueryException::class, 'permission denied');

it('denies TRUNCATE to the runtime role', function (): void {
    DB::statement('TRUNCATE audit_log');
})->throws(QueryException::class, 'permission denied');

/**
 * Runs $attempt as the schema owner inside its own transaction, which is always
 * rolled back so the test database keeps no rows.
 */
function asOwnerInRolledBackTransaction(Closure $attempt): void
{
    $owner = DB::connection('pgsql_owner');
    $owner->beginTransaction();

    try {
        $id = $owner->table('audit_log')->insertGetId([
            'actor' => 'test',
            'channel' => AuditChannel::System->value,
            'action' => 'test.owner',
            'result' => AuditResult::Success->value,
            'context' => '{}',
        ]);

        $attempt($owner, $id);
    } finally {
        $owner->rollBack();
    }
}

it('rejects UPDATE even for the schema owner', function (): void {
    asOwnerInRolledBackTransaction(function (Illuminate\Database\Connection $owner, int $id): void {
        $owner->table('audit_log')->where('id', $id)->update(['action' => 'tampered']);
    });
})->throws(QueryException::class, 'audit_log is insert-only: UPDATE is not allowed');

it('rejects DELETE even for the schema owner', function (): void {
    asOwnerInRolledBackTransaction(function (Illuminate\Database\Connection $owner, int $id): void {
        $owner->table('audit_log')->where('id', $id)->delete();
    });
})->throws(QueryException::class, 'audit_log is insert-only: DELETE is not allowed');

it('rejects TRUNCATE even for the schema owner', function (): void {
    asOwnerInRolledBackTransaction(function (Illuminate\Database\Connection $owner): void {
        $owner->statement('TRUNCATE audit_log');
    });
})->throws(QueryException::class, 'audit_log is insert-only: TRUNCATE is not allowed');
