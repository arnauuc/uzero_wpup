<?php

declare(strict_types=1);

namespace App\Domain\Audit;

use App\Enums\AuditChannel;
use App\Models\User;

/**
 * Who performed an audited action and through which channel.
 */
final readonly class AuditActor
{
    public function __construct(
        public ?int $userId,
        public string $name,
        public AuditChannel $channel,
        public ?string $ip = null,
    ) {}

    public static function user(User $user, AuditChannel $channel, ?string $ip = null): self
    {
        return new self($user->id, $user->unix_username, $channel, $ip);
    }

    /**
     * Unattended actions (scheduler, workers). The name ends up in reports as is.
     */
    public static function system(string $name = 'sistema'): self
    {
        return new self(null, $name, AuditChannel::System);
    }
}
