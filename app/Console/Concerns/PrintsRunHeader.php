<?php

declare(strict_types=1);

namespace App\Console\Concerns;

use App\Enums\RunStatus;

/**
 * The first lines of a site in `inventory` and `plan`: who it is, the run, and why the run
 * did not deliver what was asked (skipped, failed, or finished without a usable result).
 */
trait PrintsRunHeader
{
    /**
     * @param  string  $subject  what did not finish well, e.g. "L'inventari"
     */
    protected function printRunHeader(string $site, string $url, int $runId, RunStatus $status, ?string $failure, string $subject): void
    {
        $this->line('');
        $this->line("<options=bold>{$site}</> ({$url}) — execució #{$runId}");

        if ($status === RunStatus::Skipped) {
            $this->line("omès: {$failure}");
        } elseif ($status !== RunStatus::Succeeded) {
            $this->error("{$subject} no ha acabat bé ({$status->value}): {$failure}");
        } elseif ($failure !== null) {
            $this->error($failure);
        }
    }
}
