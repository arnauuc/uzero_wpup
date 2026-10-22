<?php

declare(strict_types=1);

namespace App\Domain\Runs\Exceptions;

/**
 * Thrown from the ticker inside a step that ran out of time.
 */
final class StepTimedOut extends RunException {}
