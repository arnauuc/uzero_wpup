<?php

declare(strict_types=1);

namespace Tests\Support\Runs;

use App\Domain\Runs\ReadOnlyStep;

final class ScriptedReadOnlyStep extends ScriptedStep implements ReadOnlyStep {}
