<?php

declare(strict_types=1);

namespace Tests\Support\Console;

use App\Console\ChangesState;
use App\Console\Concerns\LaunchesRuns;
use App\Console\Concerns\SelectsSites;
use App\Console\WpupCommand;
use App\Domain\Identity\Ability;
use App\Enums\RunType;

/**
 * Test-only command built only from the shared CLI pieces (UZEROWPUP-24).
 */
final class DemoRunCommand extends WpupCommand implements ChangesState
{
    use LaunchesRuns;
    use SelectsSites;

    protected $signature = 'test:demo-run '.self::TARGETS.' '.self::DRY_RUN.' '.self::QUEUED.' '.self::JSON;

    protected function ability(): Ability
    {
        return Ability::RunOperations;
    }

    protected function perform(): int
    {
        $sites = $this->selectedSites();
        if ($sites === null) {
            return self::FAILURE;
        }

        return $this->launchRuns(RunType::Update, array_values($sites->all()), ['dry_run' => $this->isDryRun()]);
    }
}
