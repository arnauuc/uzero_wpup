<?php

declare(strict_types=1);

namespace App\Domain\Notifications;

use App\Models\NotificationDestination;

/**
 * What `notify:destination` would do (NotificationAdministration::plan()):
 * the destination as it is (null when new), its channel, the fields that
 * change and whether a secret has to be typed.
 */
final readonly class DestinationPlan
{
    /**
     * @param  array<string, array{from: mixed, to: mixed}>  $changes  name, address, active
     */
    public function __construct(
        public string $name,
        public string $channel,
        public ?NotificationDestination $existing,
        public array $changes,
        public bool $needsSecret,
    ) {}

    public function creates(): bool
    {
        return $this->existing === null;
    }

    public function changesNothing(): bool
    {
        return $this->existing !== null && $this->changes === [] && ! $this->needsSecret;
    }
}
