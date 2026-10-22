<?php

declare(strict_types=1);

namespace App\Console;

use App\Domain\Audit\AuditActor;
use App\Domain\Audit\AuditLogger;
use App\Domain\Identity\Ability;
use App\Domain\Identity\IdentityException;
use App\Domain\Identity\OperatorResolver;
use App\Domain\Identity\ProcessIdentity;
use App\Enums\AuditChannel;
use App\Enums\AuditResult;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * Base class of every operator-facing command: identifies the operator, checks
 * the ability and audits denials before perform() runs. Commands stay thin and
 * delegate to domain services (AGENTS.md).
 */
abstract class WpupCommand extends Command
{
    /** Signature fragments shared by every command (UZEROWPUP-24, docs/cli.md). */
    protected const TARGETS = '{sites?* : Noms dels llocs} {--tag=* : Llocs actius amb aquesta etiqueta} {--all : Tots els llocs actius}';

    protected const DRY_RUN = '{--dry-run : Mostra què es faria sense fer-ho}';

    protected const JSON = '{--json : Sortida en JSON, per a scripts}';

    protected const QUEUED = '{--follow : Mostra el progrés en directe} {--wait : Espera que acabi; el codi de sortida en reflecteix el resultat}';

    protected User $operator;

    protected AuditLogger $audit;

    abstract protected function ability(): Ability;

    abstract protected function perform(): int;

    final public function handle(OperatorResolver $resolver, ProcessIdentity $identity, AuditLogger $audit): int
    {
        $this->audit = $audit;

        try {
            $this->operator = $resolver->resolve($identity);
        } catch (IdentityException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if (! $this->ability()->allows($this->operator)) {
            $audit->record($this->actor(), 'command.denied', AuditResult::Denied, 'command', (string) $this->getName(), [
                'ability' => $this->ability()->value,
            ]);
            $this->error("No tens permís per a aquesta acció (rol actual: {$this->operator->role->value}).");

            return self::FAILURE;
        }

        return $this->perform();
    }

    protected function actor(): AuditActor
    {
        return AuditActor::user($this->operator, AuditChannel::Cli);
    }

    protected function isDryRun(): bool
    {
        return $this->hasOption('dry-run') && $this->option('dry-run') === true;
    }

    protected function wantsJson(): bool
    {
        return $this->hasOption('json') && $this->option('json') === true;
    }

    /**
     * Machine-readable output for --json: one JSON document on stdout.
     *
     * @param  array<mixed>  $data
     */
    protected function emitJson(array $data): void
    {
        $this->line((string) json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    protected function stringArgument(string $name): string
    {
        $value = $this->argument($name);

        return is_string($value) ? $value : '';
    }

    protected function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
