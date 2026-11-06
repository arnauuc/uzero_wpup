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
     * Oldest PHP a site may run and still be managed (ADR-0007). It may be raised
     * but never lowered below 7.4: the agent and the tool refuse to start.
     */
    'min_site_php' => '7.4',

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
            'lock:', 'backup:', 'backup-target:', 'agent:', 'client:', 'billing:', 'ssh-key:', 'secrets:', 'staging:', 'run:', 'tool:backup',
            'inventory', 'plan', 'backup', 'update', 'verify', 'rollback', 'runs', 'report', 'cleanup', 'doctor', 'stats',
        ],
    ],

    /*
     * Update plan (UZEROWPUP-36). A plan never probes the web: it trusts the latest
     * probe-verified PHP of the site only if it is at most this old and the CLI
     * reports the same minor version. `report_version` is the `wpup plan --json`
     * document version.
     */
    'planning' => [
        'step_timeout_seconds' => 60,
        'report_version' => 1,
        'verified_php_max_age_seconds' => 604800,
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
    // Restore points and their destinations (ADR-0009).
    'backups' => [
        // A restore point is kept this long after a correct update (ADR-0009);
        // UZEROWPUP-44 enforces it, with the cap per site and the protection
        // (RestorePointRetention).
        'retention_days' => 7,
        // At most this many restore points per site: making one more purges the
        // oldest that nothing holds (protected, needed by a run...).
        'max_per_site' => 2,
        // Daily purge of expired and surplus points (engine:prune-restore-points),
        // after the default update window (ADR-0006).
        'prune_time' => '06:30',
        // The backup step of a run: dump, archive, and copies to every destination.
        'step_timeout_seconds' => 3600,
        // `wpup backup:restore` (UZEROWPUP-46): checking the copies (SHA-256 at
        // their destination) before anything is written, and the restore itself.
        'restore' => [
            'check_timeout_seconds' => 1800,
            'maintenance_timeout_seconds' => 120,
            'step_timeout_seconds' => 3600,
        ],
        // `wpup backup --json`, `backup:list --json` (UZEROWPUP-45) and `backup:prune --json` document version.
        'report_version' => 1,
        // `wpup backup:list` shows this many files unless --limit says otherwise, and the
        // date format of the expiry in the terminal output of both backup commands.
        'list_limit' => 200,
        'date_format' => 'Y-m-d H:i',
        // Read size when hashing an object at its destination.
        'hash_chunk_bytes' => 1_048_576,
        // Temporary copies of restore points on the tool server (0700). Not /tmp:
        // on Debian 13 it is a tmpfs, too small for a big archive.
        'staging_dir' => env('WPUP_BACKUP_STAGING_DIR', storage_path('app/backup-staging')),
        // Database dump (UZEROWPUP-38). sprintf(): run label, random suffix.
        'database' => [
            'file_name' => 'db-%s-%s.sql.gz',
        ],
        // Code archive (UZEROWPUP-39, ADR-0009): core, plugins, themes, mu-plugins,
        // drop-ins and wp-config.php. Paths relative to the WordPress root; tar and
        // rsync patterns ("*") are allowed.
        'files' => [
            'file_name' => 'files-%s-%s.tar.gz',
            'uploads_path' => 'wp-content/uploads',
            // Always left out: caches (rebuilt by the site) and the archives of
            // other backup plugins (large, and not code). Drop-ins such as
            // object-cache.php are NOT here: they are code.
            'exclude' => [
                'wp-content/cache',
                'wp-content/updraft',
                'wp-content/ai1wm-backups',
                'wp-content/backups-dup-lite',
                'wp-content/backups-dup-pro',
                'wp-content/backup-db',
                'wp-content/backupbuddy_backups',
                'wp-content/wpvividbackups',
                'wp-content/wflogs',
                'wp-content/debug.log',
            ],
            // Only matter when the profile includes uploads: other backup plugins
            // keep their archives there.
            'exclude_in_uploads' => [
                'wp-content/uploads/backwpup-*',
                'wp-content/uploads/wp-clone',
                'wp-content/uploads/wpvividbackups',
                'wp-content/uploads/backup-*',
                'wp-content/uploads/duplicator*',
                'wp-content/uploads/snapshots',
            ],
        ],
        // Destination on the site's own server (UZEROWPUP-40): a directory of the
        // tool's work directory (outside the docroot, 0700), shared by every site
        // through one backup_targets row of type site_local, linked to each site
        // when it is created (SiteLocalDestination). If `name` is taken by a
        // destination of another type, the row is named "{name}-site-local".
        'site_local' => [
            'name' => 'local',
            'root' => 'restore-points',
            // Read size when copying a stream to the temporary file it uploads.
            'copy_chunk_bytes' => 1_048_576,
            // Check that the web does not serve the store (a random canary).
            'canary_timeout_seconds' => 10,
            // Temporary files of the store older than this are removed on the next write.
            'stale_after_seconds' => 86_400,
        ],
        // Dropbox destinations (UZEROWPUP-107): a Dropbox app with "App folder"
        // access only; config.root of the backup_targets row is a folder inside
        // it and credential_ref the SecretStore JSON {app_key, app_secret,
        // refresh_token}.
        'dropbox' => [
            'api_url' => 'https://api.dropboxapi.com/2/',
            'content_url' => 'https://content.dropboxapi.com/2/',
            'token_url' => 'https://api.dropboxapi.com/oauth2/token',
            // Upload-session chunk: a multiple of 4 MiB, at most 150 MiB.
            'chunk_bytes' => 8 * 1024 * 1024,
            // Retries of a call answered 429/5xx or cut off, and the longest pause.
            'max_retries' => 3,
            'max_retry_after_seconds' => 60,
            // Downloads and chunks of non-seekable sources are buffered in
            // unlinked files of staging_dir (never /tmp), read this much at a
            // time; a source that gives nothing this many reads in a row is stuck.
            'read_chunk_bytes' => 1_048_576,
            'max_idle_reads' => 1000,
            // Per request (an upload chunk, an API call). A download is one
            // request however big: it only has the idle timeout and resumes
            // with Range when cut.
            'connect_timeout_seconds' => 10,
            'timeout_seconds' => 600,
            'read_timeout_seconds' => 120,
            // What is read of an error or token response, and how much of a
            // non-JSON error is quoted in the message.
            'max_error_bytes' => 65_536,
            'error_summary_chars' => 200,
            'max_token_response_bytes' => 65_536,
        ],
    ],

    /*
     * The tool's own daily backup (ADR-0009 §3, UZEROWPUP-47,
     * docs/runbooks/copia-eina.md): pg_dump of the tool database, the
     * non-secret configuration and the reports, encrypted with age on the VM
     * before it is uploaded. Never the package artifacts nor the screenshots.
     */
    'tool_backup' => [
        // age recipients (public keys, "age1…"), comma-separated in the env. The
        // private key is in Arnau's KeePassXC, never on the VM. Without at
        // least one valid recipient the backup refuses to run.
        'age_recipients' => array_values(array_filter(array_map(trim(...), explode(',', (string) env('WPUP_TOOL_BACKUP_AGE_RECIPIENTS', ''))))),
        // Non-secret configuration files or directories (absolute paths,
        // comma-separated in the env). Missing paths are skipped with a warning;
        // anything matching ToolBackupContents' denylist (.env*, keys, secrets…)
        // is never included, whatever is listed here.
        'config_paths' => array_values(array_filter(array_map(trim(...), explode(',', (string) env('WPUP_TOOL_BACKUP_CONFIG_PATHS', base_path('deploy/config.env')))))),
        // Report files, if any (the run reports themselves live in the database).
        'report_paths' => array_values(array_filter(array_map(trim(...), explode(',', (string) env('WPUP_TOOL_BACKUP_REPORT_PATHS', storage_path('app/reports')))))),
        // The backup_targets row (type dropbox, not linked to any site) the
        // copies go to, under tool/ of its config.root.
        'target' => env('WPUP_TOOL_BACKUP_TARGET', 'tool'),
        // Daily, after the default update window (ADR-0006) and the purge of
        // restore points, so the copy holds the night's runs.
        'time' => env('WPUP_TOOL_BACKUP_TIME', '07:00'),
        // The run's steps: the read-only check (also under --dry-run) and the
        // backup (dump, encryption, upload, SHA-256 check and retention).
        'check_timeout_seconds' => 300,
        'step_timeout_seconds' => 3600,
        // Timeouts of the local commands (seconds).
        'dump_timeout_seconds' => 900,
        'archive_timeout_seconds' => 300,
        'encrypt_timeout_seconds' => 900,
        // Work directories (plaintext) and archives of the staging directory
        // older than this are left by a run that died: the next run removes
        // them. Longer than any run (wpup.runs.timeout_seconds).
        'stale_after_seconds' => 7200,
        // Retention (ADR-0009): the latest copy of each of the most recent
        // `daily` days, plus the first copy of each of the most recent `monthly`
        // months (ToolBackupRetention).
        'retention' => [
            'daily' => 30,
            'monthly' => 12,
        ],
    ],

    /*
     * Package artifacts (UZEROWPUP-49): every package is downloaded by the tool
     * server, kept per run with its SHA-256 and installed from there, so staging
     * and production get the same bytes. Private directory (0700, files 0600).
     */
    'artifacts' => [
        'dir' => env('WPUP_ARTIFACTS_DIR', storage_path('app/artifacts')),
        // Kept while the run's restore point exists, and at least this long (ADR-0009).
        'min_retention_days' => 30,
        // Download (HTTPS only; redirects are followed by hand, HTTPS only too).
        'connect_timeout_seconds' => 15,
        'timeout_seconds' => 600,
        'read_timeout_seconds' => 120,
        'max_redirects' => 5,
        // Only public addresses are ever reached (PublicAddress); and only on these ports.
        'allowed_ports' => [443],
        // What of a package URL is registered with the secret redactor
        // (PackageUrlGuard): values of query keys containing one of these words,
        // and query values / path segments at least this long that look like
        // a token (letters and digits). Never the slug, the version or a URL.
        'url_secrets' => [
            'query_keys' => ['key', 'license', 'licence', 'token', 'password', 'pass', 'secret', 'sig', 'signature', 'auth', 'hash', 'nonce', 'access_token', 'credential'],
            'min_value_length' => 20,
            'min_segment_length' => 16,
        ],
        'max_bytes' => 200 * 1024 * 1024,
        // Sum of the uncompressed sizes of the ZIP entries (zip bombs).
        'max_unpacked_bytes' => 1024 * 1024 * 1024,
        'chunk_bytes' => 1_048_576,
        // WP-CLI reads of the site's update data (transients, core check-update).
        'command_timeout_seconds' => 60,
        // sprintf(): WordPress.org slug, version.
        'wordpress_org' => [
            'plugin_url' => 'https://downloads.wordpress.org/plugin/%s.%s.zip',
            'theme_url' => 'https://downloads.wordpress.org/theme/%s.%s.zip',
        ],
    ],

    'inventory' => [
        'step_timeout_seconds' => 600,
        // `wpup inventory --json` document version, bumped on incompatible changes.
        'report_version' => 1,
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
        // a WP-Cron event must be (it runs on traffic) to count as overdue, and
        // how long a .maintenance file keeps the site in maintenance mode.
        'state' => [
            'command_timeout_seconds' => 60,
            'cron_overdue_after_seconds' => 600,
            // A .maintenance file older than this is ignored by WordPress.
            'maintenance_ttl_seconds' => 600,
        ],
        // Prerequisites (UZEROWPUP-34): the backup needs (database + files) x
        // factor free bytes; WP-CLI timeout for the database size.
        'prerequisites' => [
            'backup_space_factor' => 1.5,
            'command_timeout_seconds' => 120,
            // du over a big uploads folder takes long.
            'du_timeout_seconds' => 180,
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
