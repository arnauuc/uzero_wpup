<?php

declare(strict_types=1);

namespace App\Domain\Secrets;

use App\Domain\Audit\AuditActor;

/**
 * The only way to persist or read credentials (ADR-0002, AGENTS.md rule 2).
 * Kept as an interface so it can move to Vault/OpenBao without touching callers.
 */
interface SecretStore
{
    /**
     * Encrypts and stores a secret. Returns its id (the `credential_ref`).
     */
    public function put(string $purpose, Secret $value, AuditActor $actor): string;

    /**
     * Replaces the value of an existing secret (e.g. after a key rotation).
     */
    public function replace(string $id, Secret $value, AuditActor $actor): void;

    /**
     * Decrypts a secret. Every call is written to the audit log with the reason.
     */
    public function reveal(string $id, AuditActor $actor, string $reason): Secret;
}
