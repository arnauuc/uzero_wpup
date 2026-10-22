<?php

declare(strict_types=1);

namespace Tests\Support\Runs;

use App\Domain\Runs\Pipeline;
use App\Domain\Runs\StepOutcome;

/**
 * Pipeline for the crash tests (tests/Process). Three compensable steps a, b, c
 * write to a journal file shared with the test. The worker kills itself with
 * SIGKILL the first time it reaches $crashAt ("run:b", "compensate:a"…).
 * Effects are idempotent: an "effect" line is written only once per action.
 */
final class CrashPipeline
{
    public static function build(string $journal, string $crashAt, ?string $failAt = null): Pipeline
    {
        $action = static function (string $action) use ($journal, $crashAt): StepOutcome {
            $lines = self::read($journal);
            file_put_contents($journal, "attempt {$action}\n", FILE_APPEND);

            if ($action === $crashAt && ! in_array("crash {$action}", $lines, true)) {
                file_put_contents($journal, "crash {$action}\n", FILE_APPEND);
                posix_kill((int) getmypid(), SIGKILL);
            }

            if (! in_array("effect {$action}", $lines, true)) {
                file_put_contents($journal, "effect {$action}\n", FILE_APPEND);
            }

            return StepOutcome::succeeded($action);
        };

        $steps = [];
        foreach (['a', 'b', 'c'] as $name) {
            $steps[] = new ScriptedCompensableStep(
                $name,
                static fn (): StepOutcome => $failAt === $name ? StepOutcome::failed("{$name} falla") : $action("run:{$name}"),
                static fn (): StepOutcome => $action("compensate:{$name}"),
            );
        }

        return new Pipeline($steps);
    }

    /**
     * @return list<string>
     */
    public static function read(string $journal): array
    {
        $content = is_file($journal) ? (string) file_get_contents($journal) : '';

        return array_values(array_filter(explode("\n", $content), static fn (string $line): bool => $line !== ''));
    }
}
