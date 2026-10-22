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

    /*
     * Run engine (UZEROWPUP-21). A worker holds a claim on the run while it
     * executes it and refreshes it every `heartbeat_seconds`; a run whose claim
     * expired is interrupted and `engine:recover` resumes or rolls it back.
     */
    'runs' => [
        'timeout_seconds' => (int) env('WPUP_RUN_TIMEOUT', 7200),
        'claim_ttl_seconds' => 60,
        'heartbeat_seconds' => 10,
        // A step that was interrupted this many times is not tried again: the run rolls back.
        'max_step_attempts' => 2,
        'summary_max_chars' => 500,
        'output_max_bytes' => 1_048_576,
        // How long a queued run waits before trying again when its site is locked.
        'lock_retry_seconds' => 60,
    ],

    /*
     * SSH driver (UZEROWPUP-23, ADR-0002). Commands go through phpseclib with the
     * key in memory; rsync uses a throwaway ssh-agent per transfer. The tool
     * works in `work_dir` inside the account's home and installs its own pinned
     * WP-CLI there (`make tools` downloads it on the tool server).
     */
    'ssh' => [
        'connect_timeout_seconds' => 15,
        'command_timeout_seconds' => 300,
        'transfer_timeout_seconds' => 3600,
        'work_dir' => '.wpup',
        'wp_cli' => [
            'phar' => storage_path('app/tools/wp-cli-2.12.0.phar'),
            'sha256' => 'ce34ddd838f7351d6759068d09793f26755463b4a4610a5a5c0a97b68220d85c',
        ],
    ],

];
