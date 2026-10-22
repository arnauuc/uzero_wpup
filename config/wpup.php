<?php

declare(strict_types=1);

/*
 * Uzero wpup settings. Every threshold, limit and timeout lives here, never as a
 * magic value in the code (AGENTS.md).
 */
return [

    /*
     * Secret storage (ADR-0002). Secrets are envelope-encrypted: one random DEK per
     * secret, wrapped with the key-encryption key (KEK).
     *
     * - "systemd": production. The KEK is a systemd credential sealed with the VM's
     *   TPM (`systemd-creds encrypt --with-key=tpm2+host`) and handed to the service
     *   in $CREDENTIALS_DIRECTORY as "<credential_prefix><version>".
     * - "env": local development and tests only. The KEK comes from WPUP_DEV_KEK
     *   (base64, 32 bytes). Refused when APP_ENV=production.
     */
    'secrets' => [
        'kek_provider' => env('WPUP_KEK_PROVIDER', 'systemd'),
        'kek_version' => (int) env('WPUP_KEK_VERSION', 1),
        'credential_prefix' => 'wpup-kek-v',
        'dev_kek' => env('WPUP_DEV_KEK'),
    ],

];
