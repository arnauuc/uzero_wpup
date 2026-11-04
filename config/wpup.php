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
        // Version used to seal new secrets (ADR-0011).
        'kek_version' => (int) env('WPUP_KEK_VERSION', 1),
        // systemd credential holding the X25519 private key of each version; only
        // the worker and scheduler units load it (LoadCredentialEncrypted=).
        'credential_prefix' => 'wpup-kek-v',
        // Public keys (not secret), one per version: WPUP_SECRETS_PUBLIC_KEY_V<n>.
        'public_keys' => (static function (): array {
            $keys = [];
            foreach (array_merge(getenv(), $_ENV) as $name => $value) {
                if (is_string($name) && is_string($value) && preg_match('/^WPUP_SECRETS_PUBLIC_KEY_V([1-9][0-9]*)$/', $name, $m) === 1) {
                    $keys[(int) $m[1]] = $value;
                }
            }
            ksort($keys);

            return $keys;
        })(),
        // Development and tests only: the X25519 private key (base64) of version 1.
        'dev_kek' => env('WPUP_DEV_KEK'),
    ],

    /*
     * Operations that need a secret in clear run on the workers (ADR-0011): the
     * CLI queues them and waits. Step timeouts, in seconds.
     */
    'credential_operations' => [
        'site_test_timeout_seconds' => 300,
        'key_rotation_timeout_seconds' => 300,
        'secrets_rekey_timeout_seconds' => 3600,
        // The CLI waits for these runs up to their step timeout plus this margin
        // (queue and lock waits), and warns if they are still queued after
        // queued_notice_seconds (no worker, or a busy lock).
        'wait_margin_seconds' => 600,
        'queued_notice_seconds' => 15,
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
     * Site catalogue (UZEROWPUP-25). `default_window` applies to sites without
     * their own maintenance window (ADR-0006); the other lists are the values a
     * profile may use.
     */
    'sites' => [
        'default_window' => ['days' => [1, 2, 3, 4, 5, 6, 7], 'start' => '01:00', 'end' => '06:00'],
        'notification_channels' => ['email', 'telegram', 'slack'],
        'external_backup_types' => ['plesk', 'dropbox', 'plugin', 'other', 'none'],
        // site:import refuses larger files (UZEROWPUP-26).
        'import_max_bytes' => 1048576,
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
     * Concurrency (UZEROWPUP-20, ADR-0006): the VM runs one update at a time.
     * Raise these only if the VM grows. A run that finds a level full waits in
     * the queue (released every `retry_seconds`, without spending attempts).
     */
    'concurrency' => [
        'global' => (int) env('WPUP_CONCURRENCY_GLOBAL', 1),
        'per_hosting_server' => (int) env('WPUP_CONCURRENCY_PER_SERVER', 1),
        'staging' => (int) env('WPUP_CONCURRENCY_STAGING', 1),
        'lease_seconds' => 60,
        'retry_seconds' => 30,
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
     * Version inventory (UZEROWPUP-31). Reads core, plugins, themes, drop-ins,
     * must-use plugins, PHP and the database server in a single driver call.
     */
    'inventory' => [
        'step_timeout_seconds' => 600,
        // Temporary HTTP probe that reports the web's PHP (UZEROWPUP-30): request
        // timeout, and how long the file answers if it could not be deleted.
        'http_probe' => [
            'timeout_seconds' => 20,
            'ttl_seconds' => 300,
            'verify_tls' => (bool) env('WPUP_HTTP_PROBE_VERIFY_TLS', true),
            // The answer is a short JSON: anything bigger is refused unread.
            'max_response_bytes' => 65536,
            'max_extensions' => 500,
        ],
        // Pending updates (UZEROWPUP-32): WordPress.org info API (one GET per
        // .org component with a pending update), WP-CLI timeout for the site
        // transients, lookup cap and age after which a transient is stale.
        'wordpress_org' => [
            'plugins_info_url' => 'https://api.wordpress.org/plugins/info/1.2/',
            'themes_info_url' => 'https://api.wordpress.org/themes/info/1.2/',
            'timeout_seconds' => 10,
            'command_timeout_seconds' => 60,
            'max_lookups' => 100,
            'stale_after_seconds' => 43200,
            // Requirements are cached per slug and version.
            'cache_ttl_seconds' => 86400,
        ],
        // Pre-update state (UZEROWPUP-33): WP-CLI timeout per read, and how late
        // a WP-Cron event must be (it runs on traffic) to count as overdue.
        'state' => [
            'command_timeout_seconds' => 60,
            'cron_overdue_after_seconds' => 600,
        ],
    ],

    /*
     * SSH driver (UZEROWPUP-23, ADR-0002). Commands go through phpseclib with the
     * key in memory; rsync uses a throwaway ssh-agent per transfer. The tool
     * works in `work_dir` inside the account's home and installs its own pinned
     * WP-CLI there (`make tools` downloads it on the tool server).
     */
    'ssh' => [
        // Fixed public egress IP of the tool (ADR-0003). Every installed key is
        // restricted to it with from="…" in authorized_keys.
        'egress_ip' => env('WPUP_EGRESS_IP'),
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
