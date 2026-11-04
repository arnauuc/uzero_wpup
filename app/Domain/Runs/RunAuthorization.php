<?php

declare(strict_types=1);

namespace App\Domain\Runs;

use App\Domain\Audit\AuditActor;
use App\Domain\Audit\AuditLogger;
use App\Domain\Identity\Ability;
use App\Enums\AuditChannel;
use App\Enums\AuditResult;
use App\Models\Run;
use App\Models\User;

/**
 * The worker checks the policy again with the operator who queued the run
 * (ADR-0011): the CLI checked it before queueing, but the operator may have been
 * deactivated or demoted since, and the worker is the one holding the private key.
 */
final readonly class RunAuthorization
{
    public function __construct(private AuditLogger $audit) {}

    /**
     * The actor the run acts for, or null (audited) when it may no longer do it.
     */
    public function actorFor(Run $run, Ability $ability): ?AuditActor
    {
        $user = $run->user_id === null ? null : User::query()->find($run->user_id);
        $actor = new AuditActor($run->user_id, $run->actor, $run->channel);

        if ($user instanceof User && $ability->allows($user)) {
            return $actor;
        }

        // Runs the scheduler queues itself (weekly inventory, nightly updates)
        // have no operator. Only routine operations; never credential management.
        if ($run->user_id === null && $run->channel === AuditChannel::System && $ability === Ability::RunOperations) {
            return $actor;
        }

        $this->audit->record($actor, 'run.denied', AuditResult::Failure, 'run', $run->id, [
            'type' => $run->type->value,
            'ability' => $ability->value,
        ]);

        return null;
    }

    public static function deniedMessage(Run $run): string
    {
        return "L'operador {$run->actor} ja no té permís per a aquesta operació: no s'ha executat.";
    }
}
