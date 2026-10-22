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

    /*
     * Operator identity (ADR-0002). The tool always runs as the service user and
     * operators call it through `sudo -u wpup wpup ...`.
     *
     * - "sudo": production. The process must run as `service_user` and the
     *   operator is SUDO_USER, mapped to an active row in `users`.
     * - "posix": local development only. The operator is the process user.
     *   Refused when APP_ENV=production.
     */
    'identity' => [
        'mode' => env('WPUP_IDENTITY_MODE', 'sudo'),
        'service_user' => env('WPUP_SERVICE_USER', 'wpup'),
    ],

    /*
     * Commands reachable through the `wpup` binary. Anything else (tinker,
     * migrate, key:generate, user:bootstrap...) is refused there and stays
     * available only to whoever can run `artisan` as the service user.
     * Entries ending in ":" allow a whole namespace.
     */
    'cli' => [
        'allowed_commands' => [
            'list', 'help',
            'site:', 'user:add', 'user:list', 'user:role', 'user:deactivate',
            'lock:', 'backup:', 'agent:', 'client:', 'billing:', 'ssh-key:', 'secrets:', 'staging:', 'run:',
            'inventory', 'plan', 'update', 'verify', 'rollback', 'runs', 'report', 'cleanup', 'doctor', 'stats',
        ],
    ],

    /*
     * Locks (UZEROWPUP-19, UZEROWPUP-124): one run per site and per hosting
     * account at a time. The lease expires after `ttl_seconds` unless the run
     * refreshes it, so a dead worker never keeps a site locked forever.
     */
    'locks' => [
        'connection' => 'locks',
        'ttl_seconds' => (int) env('WPUP_LOCK_TTL', 120),
    ],

];
