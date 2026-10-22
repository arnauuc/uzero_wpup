<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Makes audit_log insert-only at the database level (AGENTS.md rule 10, ADR-0004):
 * - the runtime role loses UPDATE, DELETE and TRUNCATE;
 * - a trigger rejects UPDATE, DELETE and TRUNCATE for every role, including the
 *   schema owner (it would have to disable the trigger explicitly, which is DDL
 *   and is logged in production).
 */
return new class extends Migration
{
    public function up(): void
    {
        $appRole = $this->appRole();

        DB::statement('REVOKE UPDATE, DELETE, TRUNCATE ON audit_log FROM PUBLIC');
        DB::statement(sprintf('REVOKE UPDATE, DELETE, TRUNCATE ON audit_log FROM %s', $appRole));
        DB::statement(sprintf('GRANT SELECT, INSERT ON audit_log TO %s', $appRole));

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION audit_log_reject_change() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'audit_log is insert-only: % is not allowed', TG_OP
                    USING ERRCODE = 'insufficient_privilege';
            END;
            $$
            SQL);

        DB::statement(<<<'SQL'
            CREATE TRIGGER audit_log_reject_update_delete
                BEFORE UPDATE OR DELETE ON audit_log
                FOR EACH ROW EXECUTE FUNCTION audit_log_reject_change()
            SQL);

        DB::statement(<<<'SQL'
            CREATE TRIGGER audit_log_reject_truncate
                BEFORE TRUNCATE ON audit_log
                FOR EACH STATEMENT EXECUTE FUNCTION audit_log_reject_change()
            SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS audit_log_reject_truncate ON audit_log');
        DB::statement('DROP TRIGGER IF EXISTS audit_log_reject_update_delete ON audit_log');
        DB::statement('DROP FUNCTION IF EXISTS audit_log_reject_change()');
        DB::statement(sprintf('GRANT UPDATE, DELETE ON audit_log TO %s', $this->appRole()));
    }

    /**
     * Quoted name of the runtime role (the default connection's user, `wpup_app`).
     */
    private function appRole(): string
    {
        $role = config('database.connections.pgsql.username');

        if (! is_string($role) || $role === '') {
            throw new RuntimeException('database.connections.pgsql.username must name the runtime role.');
        }

        return '"'.str_replace('"', '""', $role).'"';
    }
};
