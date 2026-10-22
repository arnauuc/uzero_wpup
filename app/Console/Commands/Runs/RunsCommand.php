<?php

declare(strict_types=1);

namespace App\Console\Commands\Runs;

use App\Console\WpupCommand;
use App\Domain\Identity\Ability;
use App\Models\Run;

/**
 * Latest runs, newest first.
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
                'type' => $run->type->value,
                'status' => $run->status->value,
                'actor' => $run->actor,
                'queued_at' => $run->queued_at->toIso8601String(),
                'finished_at' => $run->finished_at?->toIso8601String(),
            ])->all()]);

            return self::SUCCESS;
        }

        $this->table(['#', 'Lloc', 'Tipus', 'Estat', 'Qui', 'Encuada'], $runs->map(static fn (Run $run): array => [
            $run->id,
            $run->site->name ?? 'flota',
            $run->type->value,
            $run->status->value,
            $run->actor,
            $run->queued_at->format('Y-m-d H:i'),
        ])->all());

        return self::SUCCESS;
    }
}
