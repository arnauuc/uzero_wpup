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
     * Future minimums (UZEROWPUP-52, ADR-0007): releases that will require more than
     * a site may have, so it will be frozen on the branch before. Editable table,
     * read by `wpup inventory` and `wpup plan`; a site is warned when its component
     * is older than `version` and its PHP is below `min_php` (or, for WooCommerce,
     * its WordPress below `min_wp`). `effective` is `YYYY-MM` or null when unknown; `released` marks a release already
     * out (the warning then says «Congelat», not «Quedarà congelat»);
     * `note` says where the value comes from. TODO: verify each row against the
     * vendor's announcement before relying on it, and add new ones as they appear.
     */
    'future_minimums' => [
        ['component' => 'wordpress', 'label' => 'WordPress', 'version' => '7.0', 'min_php' => '7.4', 'min_wp' => null, 'effective' => null, 'released' => true, 'note' => 'ADR-0007: WordPress 7.0 (already released) requires PHP 7.4.'],
        ['component' => 'woocommerce', 'label' => 'WooCommerce', 'version' => '11.6', 'min_php' => '8.1', 'min_wp' => null, 'effective' => '2027-02', 'released' => false, 'note' => 'ADR-0007: WooCommerce 11.6 (February 2027) requires PHP 8.1.'],
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
            'lock:', 'backup:', 'backup-target:', 'agent:', 'client:', 'billing:', 'ssh-key:', 'secrets:', 'staging:', 'run:', 'tool:backup',
            'inventory', 'plan', 'backup', 'update', 'verify', 'rollback', 'runs', 'summary', 'report', 'cleanup', 'doctor', 'stats',
        ],
    ],

    /*
     * Update plan (UZEROWPUP-36). A plan never probes the web: it trusts the latest
     * probe-verified PHP of the site only if it is at most this old and the CLI
     * reports the same minor version. `report_version` is the `wpup plan --json`
     * document version. `quarantine_days` (ADR-0005, UZEROWPUP-127): how long a new
     * version waits from when it is known to exist (0 turns the quarantine off).
     * `package_versions_retention_days`: first sightings of versions no inventory
     * has offered for this long are purged (PackageVersions::purge, UZEROWPUP-134).
     */
    'planning' => [
        // Includes downloading and analysing the new packages (static_analysis):
        // keep it above static_analysis.step_budget_seconds.
        'step_timeout_seconds' => 3600,
        'report_version' => 1,
        'verified_php_max_age_seconds' => 604800,
        'quarantine_days' => 7,
        'package_versions_retention_days' => 365,
    ],

    /*
     * Applying the updates (UZEROWPUP-54): one step per component, installed
     * from the run's analysed artifact. Step timeout in seconds; it must be at
     * least SshSettings::applyUpdateBudgetSeconds() (connect + 3 ×
     * ssh.package_timeout_seconds + 6 × ssh.command_timeout_seconds, 3615 s
     * by default), checked when the steps are built.
     */
    'updates' => [
        'component_timeout_seconds' => 3900,
        // Maintenance mode around the update (UZEROWPUP-53): the default for the profiles that
        // do not set `maintenance_mode`, the timeout of its steps, and the timeout of the WP-CLI
        // calls that read the state and refresh the `.maintenance` file (switching the mode on or
        // off goes through the driver, within its own budget). Counted in the component and
        // post-update step budgets, checked when the steps are built.
        'maintenance' => [
            'default' => true,
            'step_timeout_seconds' => 300,
            'command_timeout_seconds' => 60,
        ],
        // After the updates (UZEROWPUP-55): database migrations (core, WooCommerce) and cache
        // flushes. The step timeout must cover 2 × update_db + (4 + 2 × page caches) ×
        // command + overhead (connecting, listing components, driver work), checked at build time.
        'post_update' => [
            'step_timeout_seconds' => 6600,
            'update_db_timeout_seconds' => 1800,
            // Every short command: flushes, has-command checks, the WooCommerce migration check.
            'command_timeout_seconds' => 120,
            'overhead_seconds' => 900,
            // Page cache purge of the common caching plugins: slug => command name (as
            // `wp cli has-command` takes it) + arguments. It only runs when the plugin is active
            // AND the site has the command (checked with has-command; otherwise a warning and
            // page_cache_purged=false in the step data). A failure is never an error.
            // Checked against the plugins' source on WordPress.org: w3-total-cache (also
            // registers total-cache and w3tc), litespeed-cache, sg-cachepress and
            // wp-fastest-cache register their own commands. WP Super Cache registers none in
            // its WordPress.org source (its CLI comes from a separate package) and WP Rocket is
            // premium (source not checkable): both are kept here but only run if has-command
            // finds the command.
            'page_caches' => [
                'wp-rocket' => ['command' => 'rocket clean', 'arguments' => ['--confirm']],
                'w3-total-cache' => ['command' => 'w3-total-cache flush', 'arguments' => ['all']],
                'litespeed-cache' => ['command' => 'litespeed-purge all', 'arguments' => []],
                'wp-super-cache' => ['command' => 'super-cache flush', 'arguments' => []],
                'sg-cachepress' => ['command' => 'sg purge', 'arguments' => []],
                'wp-fastest-cache' => ['command' => 'fastest-cache clear', 'arguments' => ['all']],
            ],
        ],
        // When an update run may start (ADR-0006, UZEROWPUP-60): only inside the site's
        // maintenance window (sites.default_window without one of its own), read in
        // app.timezone, and with at least minimum_minutes of it left (an in-place site takes
        // 5–10 min; the scheduler, UZEROWPUP-129, will estimate it from the history). A
        // window shorter than that is refused by site:add/edit/import. A waiting run is
        // checked again at least every recheck_seconds.
        'window' => [
            // Off only in the test suite (phpunit.xml), where runs start at any hour.
            'enforced' => (bool) env('WPUP_UPDATE_WINDOW_ENFORCED', true),
            'minimum_minutes' => 15,
            'recheck_seconds' => 900,
        ],
        // The check that the run's own restore point is verified, right before the
        // first change to the site (AGENTS.md rule 3).
        'restore_point_gate_timeout_seconds' => 60,
    ],

    /*
     * Compatibility, layer 3 minimal (UZEROWPUP-56): after the updates, the home
     * of the site and the new lines of the PHP error log (from the position
     * saved before). A new fatal, a 5xx or no answer at all fails the step and
     * so triggers the rollback.
     * - step_timeout_seconds must cover connect + 8 SSH commands + every home
     *   request with all its redirects and the pauses (ssh.* and the values below); checked when the steps
     *   are built.
     * - http_attempts: how many requests of the home (each with its own cache
     *   buster, http_attempt_pause_seconds apart); ANY 5xx or connection failure
     *   fails. A worker still serving old code right after an update may answer
     *   differently from the next one. The log is read once, after all of them.
     * - fatal_patterns are PCRE, anchored to the PHP log format (after the
     *   [date] prefix): application messages that merely mention "Fatal error"
     *   do not count. Uncaught exceptions are PHP Fatal error lines.
     * - max_candidate_lines: the site greps its whole new log region for fatal
     *   lines and returns at most this many (more ⇒ a warning).
     * - signature_window_bytes: how far before the baseline to look for fatals
     *   that were already there (a repeat of one is only a warning).
     * Reported lines are redacted and cut to line_max_chars.
     */
    'smoke_check' => [
        'step_timeout_seconds' => 3000,
        'http_timeout_seconds' => 30,
        'max_redirects' => 5,
        'http_attempts' => 3,
        'http_attempt_pause_seconds' => 2,
        'verify_tls' => (bool) env('WPUP_SMOKE_CHECK_VERIFY_TLS', true),
        'max_candidate_lines' => 200,
        'signature_window_bytes' => 1048576,
        'reported_lines' => 5,
        'line_max_chars' => 300,
        'fatal_patterns' => [
            '/^(?:\[[^\]]*\]\s*)*(?:AH\d+:\s*Got error \')?(?:PHP message:\s*)?PHP (?:Fatal error|Parse error):\s/',
        ],
        // UZEROWPUP-71: same anchoring as the fatals. A new warning only warns; notices and deprecations are counted.
        'warning_patterns' => [
            '/^(?:\[[^\]]*\]\s*)*(?:AH\d+:\s*Got error \')?(?:PHP message:\s*)?PHP Warning:\s/',
        ],
        'notice_patterns' => [
            '/^(?:\[[^\]]*\]\s*)*(?:AH\d+:\s*Got error \')?(?:PHP message:\s*)?PHP (?:Notice|Deprecated|Strict Standards):\s/',
        ],
        // Most distinct messages kept (with their counts) in the step data.
        'max_groups' => 20,
    ],

    /*
     * Verifier (UZEROWPUP-64, docs/verificador.md): a Node + Playwright process
     * started by the run step, with a hard timeout, the JSON input on stdin and
     * a clean environment (only `path` and `browsers_path`: no credentials, no
     * database, no key). Its output stays in a private directory per run.
     */
    'verifier' => [
        // Run as `setsid <node_binary> <script>`: its own process group, stopped as a whole.
        'node_binary' => env('WPUP_NODE_BINARY', 'node'),
        'script' => base_path('verifier/dist/bin.js'),
        'setsid_binary' => '/usr/bin/setsid',
        'work_dir' => env('WPUP_VERIFIER_DIR', storage_path('app/verifier')),
        'path' => '/usr/local/bin:/usr/bin:/bin',
        'browsers_path' => env('PLAYWRIGHT_BROWSERS_PATH'),
        // Nested: navigation and screenshot are cut to what is left of total_ms, and
        // total_ms + navigation + screenshot must fit in timeout_seconds (the hard stop:
        // SIGTERM to the group, stop_grace_seconds, SIGKILL), which with the grace must
        // fit in step_timeout_seconds.
        'total_ms' => 1_080_000,
        'navigation_timeout_ms' => 60_000,
        'screenshot_timeout_ms' => 30_000,
        'timeout_seconds' => 1200,
        'stop_grace_seconds' => 10,
        'step_timeout_seconds' => 1500,
        // More than this is a verifier failure: result.json, and stdout/stderr (the
        // process is stopped when one exceeds its cap; stderr is never stored).
        'max_result_bytes' => 5 * 1024 * 1024,
        'max_stdout_bytes' => 64 * 1024,
        'max_stderr_bytes' => 1024 * 1024,
        'max_diff_ratio' => 0.01,
        'viewports' => ['desktop' => 1440, 'mobile' => 390],
        // Until the site profiles list their URLs (spec, Verificació): path => profile.
        'urls' => ['/' => 'home'],
        'masks' => [],
    ],

    /*
     * Compatibility, layer 2 (UZEROWPUP-50): every new plugin or theme package
     * goes through PHP_CodeSniffer + PHPCompatibility with testVersion equal to
     * the site's PHP minor. An error blocks that component's update; warnings go
     * to the report. The package is extracted (only *.php) into a private
     * directory that is always removed afterwards.
     */
    'static_analysis' => [
        'work_dir' => env('WPUP_STATIC_ANALYSIS_DIR', storage_path('app/static-analysis')),
        'phpcs_binary' => base_path('vendor/bin/phpcs'),
        'standard_path' => base_path('vendor/phpcompatibility/php-compatibility'),
        // One scan. Each package also has its own download timeout (artifacts.timeout_seconds).
        'timeout_seconds' => 300,
        // The step's budget for all the packages, and the least that must be left to
        // start another one (a download plus a scan). When it is not enough the
        // remaining packages are skipped, so the plan step ends instead of timing out.
        'step_budget_seconds' => 3000,
        'min_remaining_seconds' => 900,
        'memory_limit' => '512M',
        // phpcs JSON report; more than this is a failure of the analysis.
        'max_output_bytes' => 20 * 1024 * 1024,
        'max_files' => 20000,
        // phpcs --ignore patterns (matched against the path; * is a wildcard, no commas):
        // vendored libraries and known polyfills, whose code is guarded by version
        // checks and gives false positives.
        'ignore' => [
            '*/vendor/*',
            '*/node_modules/*',
            '*/symfony/polyfill-*/*',
            '*/polyfill-*/*',
            '*/random_compat/*',
            '*/paragonie/*',
            '*/ralouphie/getallheaders/*',
        ],
        // What is kept of a scan (cache and plan): findings, path and message sizes.
        'summary_max_findings' => 20,
        'summary_max_path_length' => 160,
        'summary_max_message_length' => 200,
        // Findings quoted in a skip detail and in the warnings of the plan.
        'detail_max_findings' => 3,
        'warnings_max' => 5,
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
        'external_backup_notes_max' => 500,
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
     * Scheduled updates (ADR-0006, UZEROWPUP-129). `engine:schedule-updates` runs
     * every minute: it queues the sites with automatic updates whose window is
     * open and that were not handled in that occurrence of it, and starts them one
     * at a time, as `actor` (never with --allow-major or --skip-quarantine). Manual and
     * scheduled runs share one queue in the order they were queued (GlobalQueue); only
     * an admin's --now goes ahead. Among the scheduled sites, which one is queued next
     * follows ADR-0006 §3 (security, deferred, longest without updating, name).
     * - estimate: a site's duration is the median of its last `history_runs`
     *   update runs that went past the plan, with the same strategy; without any,
     *   `default_minutes` of its strategy. A site starts only if that fits in
     *   what is left of its window (and never with less than
     *   updates.window.minimum_minutes); otherwise it waits for its next window,
     *   with priority.
     * - outlook: a site is skipped without spending any time when the plan of its
     *   latest inventory (at most `snapshot_max_age_hours` old), recomputed now
     *   and offline (quarantine and known vulnerabilities as of now, no download),
     *   has nothing to apply. With an older inventory or none, the run decides
     *   (its inventory and plan end it at once when there is nothing to do).
     */
    'scheduler' => [
        'actor' => 'sistema (scheduler)',
        // A tick holds this lock so a manual tick never overlaps the scheduler's.
        'tick_lock_seconds' => 300,
        // A run queued longer than this (a lost job) no longer holds the single queue.
        'queue' => [
            'stale_queued_hours' => 24,
        ],
        'estimate' => [
            'history_runs' => 5,
            'default_minutes' => ['in_place' => 10, 'staging_first' => 30],
        ],
        'outlook' => [
            'snapshot_max_age_hours' => 24,
        ],
        // After this many consecutive failed windows (succeeded ones reset the count) the
        // site is blocked for automatic updates until an operator turns them on again.
        'failures' => [
            'block_after' => 2,
        ],
        // Free space (on `disk_path`, the tool's storage) and available memory of the tool's
        // server checked before each site: below either, nothing starts and the operators are
        // warned (logged and audited at most every warn_every_minutes).
        'resources' => [
            'disk_path' => env('WPUP_SCHEDULER_DISK_PATH', storage_path()),
            'min_free_disk_mb' => (int) env('WPUP_SCHEDULER_MIN_FREE_DISK_MB', 10240),
            'min_free_memory_mb' => (int) env('WPUP_SCHEDULER_MIN_FREE_MEMORY_MB', 2048),
            'warn_every_minutes' => 60,
        ],
        // The daily summary of the last `hours` (engine:daily-summary; `wpup summary` on demand).
        'daily_summary' => [
            'time' => '07:00',
            'hours' => 24,
        ],
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
        // Order and user watermark of a database copy (UZEROWPUP-58, AGENTS.md rule 4):
        // read right before the dump and compared before restoring it.
        'watermark' => [
            'timeout_seconds' => 120,
            // Post types of orders in the classic posts storage (HPOS keeps every type in wc_orders).
            'order_types' => ['shop_order', 'shop_order_refund', 'shop_order_placehold', 'shop_subscription'],
            // A row created or modified this long before the read also counts as written:
            // a transaction that reserved a lower ID may commit after the read.
            'margin_seconds' => 300,
            // At most this many distinct orders (and users) are collected per table;
            // more makes the count "at least".
            'scan_cap' => 1000,
            // Orders and users listed to the operator (newest first; the rest are counted),
            // and roles per user.
            'max_listed' => 50,
            'max_roles' => 5,
            // Characters of WP-CLI's error kept in the reason.
            'error_chars' => 300,
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
     * Rollback of an update from its own restore point (UZEROWPUP-57): the
     * code archive mirrored back onto the site and the versions checked.
     */
    'rollback' => [
        'files_timeout_seconds' => 3600,
        // The database of the run's point, put back only when nothing was written to
        // the orders or users since the copy (UZEROWPUP-59).
        'database_timeout_seconds' => 3600,
        // `wpup rollback <run>` (UZEROWPUP-61): the check of the point's copies at their
        // destination (SHA-256 of their bytes, also external ones) and the maintenance steps.
        'check_timeout_seconds' => 1800,
        'maintenance_timeout_seconds' => 120,
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
     * Daily cleanup (ADR-0009 §4, UZEROWPUP-134): `wpup cleanup` and the scheduled
     * `engine:cleanup`. The ADR values are floors (90, 365, 30 and 180 days):
     * a lower value is refused. Artifacts (wpup.artifacts.min_retention_days),
     * restore points (wpup.backups) and package versions (wpup.planning) keep
     * their own settings. audit_log is never purged.
     */
    'cleanup' => [
        // After the purge of restore points (backups.prune_time): artifacts depend on it.
        'time' => env('WPUP_CLEANUP_TIME', '06:45'),
        // Full step output (run_steps.output) of correct runs, and of failed or reverted ones.
        'run_output_days' => 90,
        'run_output_failed_days' => 365,
        // Screenshots (F4, ScreenshotCleanup): without differences, and with a warning or an error.
        // Those of each site's last run are always kept.
        'screenshots_days' => 30,
        'screenshots_flagged_days' => 180,
        // Rows handled per query when emptying step output.
        'chunk_size' => 500,
        // Lock shared by `wpup cleanup` and `engine:cleanup` (seconds, longer than any cleanup).
        'lock_seconds' => 3600,
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
        // sprintf(): WordPress.org slug, version; core: (locale,) version. The core
        // package is never taken from the site.
        'wordpress_org' => [
            'core_url' => 'https://downloads.wordpress.org/release/wordpress-%s.zip',
            'localized_core_url' => 'https://downloads.wordpress.org/release/%s/wordpress-%s.zip',
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
        // External backups (UZEROWPUP-133, ADR-0009): backup plugins recognised by
        // their slug. `last_backup` says where the plugin keeps its last backup (a WP option
        // holding an array: read with `wp option pluck` its unix time and whether it succeeded), or
        // null when it is not exposed in a format we can trust. A site is warned when
        // nothing is known or the latest detected backup is older than `max_age_days`.
        'external_backups' => [
            'command_timeout_seconds' => 30,
            'max_age_days' => 7,
            // A last-backup time further in the future than this is ignored (bad clock or garbage).
            'max_future_seconds' => 86400,
            // Longer answers from the site are not even parsed.
            'max_output_bytes' => 4096,
            'plugins' => [
                'updraftplus' => ['name' => 'UpdraftPlus', 'last_backup' => ['option' => 'updraft_last_backup', 'time_key' => 'backup_time', 'success_key' => 'success']],
                'backwpup' => ['name' => 'BackWPup', 'last_backup' => null],
                'backwpup-pro' => ['name' => 'BackWPup Pro', 'last_backup' => null],
                'all-in-one-wp-migration' => ['name' => 'All-in-One WP Migration', 'last_backup' => null],
                'duplicator' => ['name' => 'Duplicator', 'last_backup' => null],
                'duplicator-pro' => ['name' => 'Duplicator Pro', 'last_backup' => null],
                'blogvault-real-time-backup' => ['name' => 'BlogVault', 'last_backup' => null],
            ],
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
        // Each of the upload, the on-site checksum and the install of a package (UZEROWPUP-54).
        'package_timeout_seconds' => 600,
        'work_dir' => '.wpup',
        'wp_cli' => [
            'phar' => storage_path('app/tools/wp-cli-2.12.0.phar'),
            'sha256' => 'ce34ddd838f7351d6759068d09793f26755463b4a4610a5a5c0a97b68220d85c',
        ],
    ],

];
