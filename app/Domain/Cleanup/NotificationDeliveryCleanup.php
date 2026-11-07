<?php

declare(strict_types=1);

namespace App\Domain\Cleanup;

use App\Domain\Audit\AuditActor;
use App\Domain\Audit\AuditLogger;
use App\Enums\AuditResult;
use App\Models\NotificationDelivery;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Delivery records of the notifications (UZEROWPUP-79) older than
 * wpup.notifications.deliveries_retention_days.
 */
final readonly class NotificationDeliveryCleanup implements CleanupTask
{
    public const string TASK = 'notification_deliveries';

    public function __construct(private AuditLogger $audit, private int $days) {}

    public function name(): string
    {
        return self::TASK;
    }

    public function run(AuditActor $actor, CarbonImmutable $now, bool $dryRun): CleanupResult
    {
        $failures = [];
        $count = 0;
        try {
            if ($this->days < 1) {
                throw new DomainException('wpup.notifications.deliveries_retention_days must be positive.');
            }
            $expired = NotificationDelivery::query()->where('created_at', '<', $now->subDays($this->days))->toBase();
            $count = $dryRun ? $expired->count() : $expired->delete();
        } catch (Throwable $e) {
            Log::warning('Notification deliveries cleanup failed: '.$e::class);
            $failures[] = "No s'han pogut netejar els registres d'enviament d'avisos.";
        }
        if (! $dryRun && ($count > 0 || $failures !== [])) {
            $this->audit->record($actor, 'cleanup.notification_deliveries', $failures === [] ? AuditResult::Success : AuditResult::Failure, null, null, [
                'deliveries' => $count,
                'failures' => $failures,
            ]);
        }

        return new CleanupResult(self::TASK, $count, 0, $dryRun, null, $failures);
    }
}
