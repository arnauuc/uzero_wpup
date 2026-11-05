<?php

declare(strict_types=1);

use App\Domain\Secrets\Kek\KekConfirmation;
use App\Domain\ToolBackup\ToolBackupLauncher;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Run engine watchdog (UZEROWPUP-21).
Schedule::command('engine:recover')->everyMinute()->withoutOverlapping();

// The scheduler holds the KEK private key: it confirms the public key the CLI
// and the web seal with (ADR-0011, KekConfirmation).
Schedule::call(static fn (): bool => app(KekConfirmation::class)->confirm())
    ->name('kek-confirmation')
    ->everyMinute()
    ->withoutOverlapping();

// Retention of restore points (ADR-0009, UZEROWPUP-44): expired and surplus points.
Schedule::command('engine:prune-restore-points')
    ->dailyAt(config()->string('wpup.backups.prune_time'))
    ->withoutOverlapping();

// The tool's own backup (ADR-0009 §3, UZEROWPUP-47): queued for the workers,
// which hold the KEK private key the Dropbox credential needs; not awaited.
Schedule::call(static fn (): bool => app(ToolBackupLauncher::class)->scheduled() !== null)
    ->name('tool-backup')
    ->dailyAt(config()->string('wpup.tool_backup.time'))
    ->withoutOverlapping();
