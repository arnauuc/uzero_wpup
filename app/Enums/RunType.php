<?php

declare(strict_types=1);

namespace App\Enums;

enum RunType: string
{
    case Inventory = 'inventory';
    case Plan = 'plan';
    case Update = 'update';
    case Backup = 'backup';
    case Restore = 'restore';
    case Rollback = 'rollback';
    case Verify = 'verify';
    case Cleanup = 'cleanup';

    /*
     * Operations that need a secret in clear (ADR-0011): the CLI and the web have
     * no private key, so they queue these for the workers and wait.
     */
    case SiteTest = 'site_test';
    case KeyRotation = 'key_rotation';
    case SecretsRekey = 'secrets_rekey';

    /**
     * Whether the run takes the concurrency slots (global, per server, staging;
     * ADR-0006) and the site lock. Credential operations do not: they are short,
     * and a `site:test` must not wait hours behind a nightly update. Operations
     * that change keys take the hosting account lock themselves.
     */
    public function usesConcurrencyLimits(): bool
    {
        return match ($this) {
            self::SiteTest, self::KeyRotation, self::SecretsRekey => false,
            default => true,
        };
    }

    /**
     * Whether the run takes the lock of its hosting account (runs.hosting_account_id)
     * without a site: rotating a key must not overlap an update on that account.
     */
    public function locksHostingAccount(): bool
    {
        return $this === self::KeyRotation;
    }
}
