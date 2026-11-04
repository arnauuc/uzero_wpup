<?php

declare(strict_types=1);

namespace App\Domain\Credentials;

use App\Domain\Identity\Ability;
use App\Domain\Runs\RunAuthorization;
use App\Domain\Runs\Step;
use App\Domain\Runs\StepContext;
use App\Domain\Runs\StepOutcome;
use App\Domain\Secrets\SecretStoreException;

/**
 * `secrets:rekey` on the worker (ADR-0011, point 3): opening each data key needs
 * the old private key, which only the workers have. Idempotent: an interrupted
 * run is simply launched again.
 */
final readonly class SecretsRekeyStep implements Step
{
    public const string NAME = 'secrets_rekey';

    public function __construct(
        private SecretsRekey $rekey,
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
        return 'Tornarà a tancar totes les claus de dades amb la versió vigent de la KEK.';
    }

    public function run(StepContext $context): StepOutcome
    {
        $actor = $this->authorization->actorFor($context->run, Ability::ManageCredentials);
        if ($actor === null) {
            return StepOutcome::failed(RunAuthorization::deniedMessage($context->run));
        }

        try {
            $done = $this->rekey->run($actor);
        } catch (SecretStoreException $e) {
            return StepOutcome::failed("S'ha aturat: {$e->getMessage()} Torna-ho a executar per continuar.");
        }

        return StepOutcome::succeeded("{$done} claus de dades tornades a tancar.", '', ['rewrapped' => $done]);
    }
}
