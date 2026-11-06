<?php

declare(strict_types=1);

namespace App\Domain\Artifacts;

use Closure;

/**
 * Runs a filesystem call with its PHP warnings captured instead of turned
 * into an ErrorException by the framework, so every failure of the artifact
 * store becomes an ArtifactException the caller can handle.
 */
final class FileOperation
{
    /**
     * @template T
     *
     * @param  Closure(): (T|false)  $operation
     * @return T
     *
     * @throws ArtifactException when it returns false or warns
     */
    public static function run(Closure $operation, string $failure): mixed
    {
        $warning = null;
        set_error_handler(static function (int $level, string $message) use (&$warning): bool {
            $warning = $message;

            return true;
        });
        try {
            $result = $operation();
        } finally {
            restore_error_handler();
        }
        if ($result === false || $warning !== null) {
            throw ArtifactException::storage($failure.($warning === null ? '' : " ({$warning})"));
        }

        return $result;
    }
}
