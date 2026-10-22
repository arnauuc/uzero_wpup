<?php

declare(strict_types=1);

namespace App\Domain\Drivers\Data;

use App\Enums\CheckResult;

final readonly class ProbeResult
{
    /**
     * @param  list<ProbeCheck>  $checks
     */
    public function __construct(public array $checks) {}

    public function worst(): CheckResult
    {
        $results = array_map(static fn (ProbeCheck $check): CheckResult => $check->result, $this->checks);

        return match (true) {
            in_array(CheckResult::Error, $results, true) => CheckResult::Error,
            in_array(CheckResult::Warning, $results, true) => CheckResult::Warning,
            default => CheckResult::Ok,
        };
    }
}
