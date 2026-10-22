<?php

declare(strict_types=1);

namespace App\Domain\Credentials;

use App\Domain\Audit\AuditActor;
use App\Domain\Audit\AuditLogger;
use App\Domain\Secrets\EnvelopeSecretStore;
use App\Domain\Secrets\Kek\KekProvider;
use App\Enums\AuditResult;
use App\Models\StoredSecret;
use Closure;

/**
 * Moves every secret to the current KEK (ADR-0002, point 7). The new KEK is
 * created and sealed by root (docs/runbooks/rotacio-kek.md); both versions are
 * loaded while this runs. One row per transaction, so it is idempotent and an
 * interrupted run simply continues where it stopped.
 */
final readonly class SecretsRekey
{
    public function __construct(
        private EnvelopeSecretStore $store,
        private KekProvider $keks,
        private AuditLogger $audit,
    ) {}

    public function pending(): int
    {
        return StoredSecret::query()->where('kek_version', '!=', $this->keks->currentVersion())->count();
    }

    /**
     * @param  (Closure(int $done, int $total): void)|null  $progress
     * @return int secrets re-wrapped
     */
    public function run(AuditActor $actor, ?Closure $progress = null): int
    {
        $current = $this->keks->currentVersion();
        $ids = StoredSecret::query()->where('kek_version', '!=', $current)->orderBy('id')->pluck('id')->all();
        $done = 0;

        try {
            foreach ($ids as $id) {
                if (is_string($id) && $this->store->rewrap($id)) {
                    $done++;
                }
                if ($progress !== null) {
                    $progress($done, count($ids));
                }
            }
        } finally {
            $this->audit->record($actor, 'secrets.rekey', $done === count($ids) ? AuditResult::Success : AuditResult::Failure, 'kek', $current, [
                'rewrapped' => $done,
                'pending' => count($ids) - $done,
            ]);
        }

        return $done;
    }
}
