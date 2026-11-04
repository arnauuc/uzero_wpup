<?php

declare(strict_types=1);

namespace App\Domain\Credentials;

use App\Domain\Audit\AuditActor;
use App\Domain\Audit\AuditLogger;
use App\Domain\Secrets\EnvelopeSecretStore;
use App\Domain\Secrets\Kek\PublicKeyRing;
use App\Enums\AuditResult;
use App\Models\StoredSecret;
use Closure;

/**
 * Moves every secret to the current KEK version (ADR-0011, point 3). The new key
 * pair is created and sealed by root (docs/runbooks/rotacio-kek.md); both private
 * keys are loaded into the workers while this runs, so it runs there. One row per transaction, so it is idempotent and an
 * interrupted run simply continues where it stopped.
 */
final readonly class SecretsRekey
{
    public function __construct(
        private EnvelopeSecretStore $store,
        private PublicKeyRing $keks,
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
