<?php

declare(strict_types=1);

namespace App\Domain\Rollback;

use App\Domain\Audit\AuditActor;
use App\Domain\Audit\AuditLogger;
use App\Domain\Backups\NewWritesDetector;
use App\Domain\Backups\RestoreSelection;
use App\Domain\Backups\SiteRestore;
use App\Domain\Drivers\SiteDriver;
use App\Domain\Runs\RunAuthorization;
use App\Domain\Runs\Step;
use App\Domain\Secrets\SecretRedactor;
use App\Models\Site;
use Closure;

/**
 * The steps that undo an update run (UZEROWPUP-57), in order, for the update
 * flow's own rollback (UZEROWPUP-60) and the pipeline of a `rollback` run
 * (`wpup rollback <run>`, UZEROWPUP-61) to compose. Both act on the run that
 * RollbackSource resolves: the files, then the database, only when nothing
 * was written to the orders or users since the copy (UZEROWPUP-59).
 *
 * No pipeline is registered here. UZEROWPUP-61 registers RunType::Rollback
 * and must put before these steps: a fresh inventory (advisory: a broken site
 * must still be rolled back), the maintenance mode, and a verified restore
 * point of the current state (AGENTS.md rule 3), as `backup:restore` does.
 */
final readonly class RollbackSteps
{
    /**
     * @param  Closure(Site, AuditActor): SiteDriver  $driverFor
     */
    public function __construct(
        private RestoreSelection $selection,
        private SiteRestore $restore,
        private RevertedChanges $changes,
        private NewWritesDetector $newWrites,
        private Closure $driverFor,
        private RunAuthorization $authorization,
        private AuditLogger $audit,
        private SecretRedactor $redactor,
        private int $filesTimeoutSeconds,
        private int $databaseTimeoutSeconds,
    ) {}

    /**
     * @return list<Step>
     */
    public function steps(): array
    {
        return [$this->files(), $this->database()];
    }

    public function files(): FilesRollbackStep
    {
        return new FilesRollbackStep($this->selection, $this->restore, $this->changes, $this->driverFor, $this->authorization, $this->audit, $this->redactor, $this->filesTimeoutSeconds);
    }

    public function database(): DatabaseRollbackStep
    {
        return new DatabaseRollbackStep($this->selection, $this->restore, $this->newWrites, $this->driverFor, $this->authorization, $this->audit, $this->redactor, $this->databaseTimeoutSeconds);
    }
}
