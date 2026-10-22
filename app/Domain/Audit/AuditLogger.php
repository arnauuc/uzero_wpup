<?php

declare(strict_types=1);

namespace App\Domain\Audit;

use App\Enums\AuditResult;
use App\Models\AuditLogEntry;

/**
 * Single entry point to write the audit trail. Every command or web action that
 * changes something must go through here (AGENTS.md rule 9).
 *
 * The context must never contain secrets; once the secret redactor exists
 * (UZEROWPUP-18) it is applied here as well.
 */
final class AuditLogger
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function record(
        AuditActor $actor,
        string $action,
        AuditResult $result,
        ?string $objectType = null,
        int|string|null $objectId = null,
        array $context = [],
    ): AuditLogEntry {
        return AuditLogEntry::query()->create([
            'user_id' => $actor->userId,
            'actor' => $actor->name,
            'channel' => $actor->channel,
            'ip' => $actor->ip,
            'action' => $action,
            'result' => $result,
            'object_type' => $objectType,
            'object_id' => $objectId === null ? null : (string) $objectId,
            'context' => $context,
        ]);
    }
}
