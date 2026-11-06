<?php

declare(strict_types=1);

namespace App\Domain\Compatibility;

use App\Domain\Artifacts\ArtifactException;
use App\Domain\Drivers\Exceptions\DriverException;
use App\Domain\Drivers\SiteDriver;
use Closure;

/**
 * The driver of a plan run, made once: one authorization check, one audit row
 * and one credential reveal for the whole run. A failure is remembered too and
 * thrown again, so a broken credential is not retried for every package.
 */
final class MemoizedDriver
{
    private ?SiteDriver $driver = null;

    private ArtifactException|DriverException|null $failure = null;

    /**
     * @param  Closure(): SiteDriver  $make
     */
    public function __construct(private readonly Closure $make) {}

    /**
     * @throws ArtifactException
     * @throws DriverException
     */
    public function __invoke(): SiteDriver
    {
        if ($this->driver !== null) {
            return $this->driver;
        }
        if ($this->failure !== null) {
            throw $this->failure;
        }
        try {
            return $this->driver = ($this->make)();
        } catch (ArtifactException|DriverException $e) {
            $this->failure = $e;
            throw $e;
        }
    }
}
