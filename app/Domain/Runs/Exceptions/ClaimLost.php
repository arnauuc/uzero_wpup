<?php

declare(strict_types=1);

namespace App\Domain\Runs\Exceptions;

/**
 * The worker lost its claim on the run or the site lock: it must stop at once
 * without touching the run, which recovery will pick up.
 */
final class ClaimLost extends RunException {}
