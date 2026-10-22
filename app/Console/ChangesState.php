<?php

declare(strict_types=1);

namespace App\Console;

/**
 * Marker for commands that change something. They must offer --dry-run
 * (AGENTS.md rule 9); a test checks every registered command.
 */
interface ChangesState {}
