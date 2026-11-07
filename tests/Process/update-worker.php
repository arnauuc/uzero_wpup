<?php

declare(strict_types=1);

/*
 * Test-only worker for tests/Process/UpdateCrashTest: executes one update run
 * with the real in-place pipeline over a CrashingDriver in its own process,
 * so the test can kill it for real (UZEROWPUP-62).
 *   php tests/Process/update-worker.php <run id> <driver state file> <artifacts dir>
 */

use App\Domain\Planning\PlanAction;
use App\Domain\Planning\PlannedChange;
use App\Domain\Runs\RunEngine;
use App\Enums\ComponentType;
use App\Enums\SkipReason;
use App\Models\AuditLogEntry;
use App\Models\Run;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Http;
use Tests\Support\Runs\CrashingDriver;
use Tests\Support\Runs\InPlaceTestPipeline;

require __DIR__.'/../../vendor/autoload.php';

/** @var Application $app */
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[, $runId, $stateFile, $artifacts] = $argv;

config(['wpup.runs.heartbeat_seconds' => 1, 'wpup.smoke_check.http_attempt_pause_seconds' => 0, 'wpup.artifacts.dir' => $artifacts]);
$driver = CrashingDriver::load($stateFile);
// audit_log is insert-only and the test cannot remove what it gets: only the
// engine's rows (run.*) are kept, those of the steps (backup.created,
// rollback.files…) would leak into the other suites' queries. The steps'
// audit has its own feature tests.
AuditLogEntry::creating(static fn (AuditLogEntry $entry): ?bool => str_starts_with($entry->action, 'run.') ? null : false);
// Only the smoke check reaches the site's home; nothing is downloaded.
Http::fake([
    'botiga.test/*' => static function () use ($driver) {
        $driver->crash('smoke');

        return Http::response('<html>botiga</html>', 200);
    },
    '*' => Http::response('', 500),
]);

InPlaceTestPipeline::register($driver, [
    new PlannedChange(ComponentType::Plugin, 'classic-editor', '1.6.3', '1.6.4', PlanAction::Update),
    new PlannedChange(ComponentType::Plugin, 'hello', '1.6', '2.0', PlanAction::Skip, SkipReason::MajorRequiresStaging),
    new PlannedChange(ComponentType::Theme, 'storefront', '4.6.0', '4.6.1', PlanAction::Update),
]);

try {
    $app->make(RunEngine::class)->execute(Run::query()->findOrFail((int) $runId));
} finally {
    $driver->save();
}
