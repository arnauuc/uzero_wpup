<?php

declare(strict_types=1);

namespace App\Domain\Runs;

/**
 * A step that changes nothing, so it also runs under --dry-run (inventory,
 * checks…). Any other step is only described in a dry run.
 */
interface ReadOnlyStep extends Step {}
