<?php

declare(strict_types=1);

namespace App\Console\Commands\Runs;

use App\Console\WpupCommand;
use App\Domain\Identity\Ability;
use App\Domain\Runs\RunInterruption;
use App\Models\Run;

/**
 * Latest runs, newest first. A run that was interrupted says so, with the
 * step and the decision of each interruption (UZEROWPUP-62), and so do the
 * warnings added to it (result_summary.warnings, UZEROWPUP-129).
 */
final class RunsCommand extends WpupCommand
{
    protected $signature = 'runs
        {--site= : Només les execucions d\'aquest lloc}
        {--limit=20 : Quantes execucions mostrar}
        '.self::JSON;

    protected $description = 'Llista les últimes execucions';

    protected function ability(): Ability
    {
        return Ability::ViewReports;
    }

    protected function perform(): int
    {
        $limit = $this->stringOption('limit') ?? '20';
        if (! ctype_digit($limit) || (int) $limit < 1) {
            $this->error('--limit ha de ser un enter positiu.');

            return self::FAILURE;
        }

        $site = $this->stringOption('site');
        $runs = Run::query()
            ->with('site')
            ->when($site !== null, static fn ($q) => $q->whereHas('site', static fn ($s) => $s->where('name', $site)))
            ->orderByDesc('id')
            ->limit((int) $limit)
            ->get();

        if ($this->wantsJson()) {
            $this->emitJson(['runs' => $runs->map(static fn (Run $run): array => [
                'id' => $run->id,
                'site' => $run->site?->name,
                'hosting_account_id' => $run->hosting_account_id,
                'type' => $run->type->value,
                'status' => $run->status->value,
                'actor' => $run->actor,
                'queued_at' => $run->queued_at->toIso8601String(),
                'finished_at' => $run->finished_at?->toIso8601String(),
                'interruptions' => array_map(static fn (RunInterruption $i): array => $i->toArray(), RunInterruption::of($run)),
                'warnings' => self::warnings($run),
            ])->all()]);

            return self::SUCCESS;
        }

        $interruptions = [];
        foreach ($runs as $run) {
            $interruptions[$run->id] = RunInterruption::of($run);
        }
        $this->table(['#', 'Lloc', 'Tipus', 'Estat', 'Qui', 'Encuada', 'Interrupcions'], $runs->map(static fn (Run $run): array => [
            $run->id,
            $run->targetLabel(),
            $run->type->value,
            $run->status->value,
            $run->actor,
            $run->queued_at->format('Y-m-d H:i'),
            count($interruptions[$run->id] ?? []) ?: '—',
        ])->all());

        foreach ($interruptions as $id => $list) {
            foreach ($list as $interruption) {
                $this->warn("#{$id}: {$interruption->describe()}");
            }
        }
        foreach ($runs as $run) {
            foreach (self::warnings($run) as $warning) {
                $this->warn("#{$run->id}: {$warning}");
            }
        }

        return self::SUCCESS;
    }

    /**
     * Warnings added to a finished run (the scheduler's block after failed windows, UZEROWPUP-129).
     *
     * @return list<string>
     */
    private static function warnings(Run $run): array
    {
        $warnings = $run->result_summary['warnings'] ?? null;

        return is_array($warnings) ? array_values(array_filter($warnings, is_string(...))) : [];
    }
}
