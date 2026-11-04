<?php

declare(strict_types=1);

namespace App\Domain\Credentials;

use App\Domain\Identity\Ability;
use App\Domain\Runs\RunAuthorization;
use App\Domain\Runs\Step;
use App\Domain\Runs\StepContext;
use App\Domain\Runs\StepOutcome;

/**
 * `ssh-key:rotate` on the worker (ADR-0011): it reveals the current and the new
 * key. The engine holds the hosting account lock meanwhile, so no update runs on
 * the account. Resumable: SshKeyManager picks up an interrupted rotation.
 */
final readonly class KeyRotationStep implements Step
{
    public const string NAME = 'key_rotation';

    public function __construct(
        private SshKeyManager $keys,
        private RunAuthorization $authorization,
        private int $timeoutSeconds,
    ) {}

    public function name(): string
    {
        return self::NAME;
    }

    public function timeoutSeconds(): int
    {
        return $this->timeoutSeconds;
    }

    public function describe(StepContext $context): string
    {
        return "Generarà una clau nova, l'instal·larà amb l'actual, la provarà i retirarà l'antiga.";
    }

    public function run(StepContext $context): StepOutcome
    {
        $actor = $this->authorization->actorFor($context->run, Ability::ManageCredentials);
        if ($actor === null) {
            return StepOutcome::failed(RunAuthorization::deniedMessage($context->run));
        }

        try {
            $notes = $this->keys->rotate($context->run->hostingAccount()->firstOrFail(), $actor);
        } catch (CredentialException $e) {
            return StepOutcome::failed($e->getMessage());
        }

        return StepOutcome::succeeded("Clau rotada: la nova funciona i l'antiga s'ha retirat.", '', ['notes' => $notes]);
    }
}
