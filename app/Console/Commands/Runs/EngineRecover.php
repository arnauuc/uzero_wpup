<?php

declare(strict_types=1);

namespace App\Console\Commands\Runs;

use App\Domain\Runs\RunRecovery;
use App\Jobs\ExecuteRun;
use Illuminate\Console\Command;

/**
 * Scheduler-only watchdog (not reachable through the `wpup` binary).
 */
final class EngineRecover extends Command
{
    protected $signature = 'engine:recover';

    protected $description = 'Detecta execucions interrompudes i les torna a encuar per reprendre-les o revertir-les';

    public function handle(RunRecovery $recovery): int
    {
        $ids = $recovery->sweep();

        foreach ($ids as $id) {
            ExecuteRun::dispatch($id);
        }

        $this->line(sprintf('Execucions interrompudes encuades: %d', count($ids)));

        return self::SUCCESS;
    }
}
