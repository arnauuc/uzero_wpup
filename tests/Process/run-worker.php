<?php

declare(strict_types=1);

/*
 * Test-only worker for tests/Process: executes one run with the crash-test
 * pipeline in its own process, so the test can kill it for real.
 *   php tests/Process/run-worker.php <run id> <journal> <crash at> [fail at]
 */

use App\Domain\Runs\PipelineResolver;
use App\Domain\Runs\RunEngine;
use App\Enums\RunType;
use App\Models\Run;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Tests\Support\Runs\CrashPipeline;

require __DIR__.'/../../vendor/autoload.php';

/** @var Application $app */
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[, $runId, $journal, $crashAt] = $argv;
$failAt = $argv[4] ?? null;

$app->make(PipelineResolver::class)->register(RunType::Update, static fn () => CrashPipeline::build($journal, $crashAt, $failAt));
$app->make(RunEngine::class)->execute(Run::query()->findOrFail((int) $runId));
