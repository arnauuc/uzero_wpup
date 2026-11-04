<?php

declare(strict_types=1);

use App\Domain\Audit\AuditActor;
use App\Domain\Backups\DatabaseBackup;
use App\Domain\Drivers\Ssh\SshDriver;
use App\Domain\Drivers\Ssh\SshDriverFactory;
use Tests\Support\Fixtures;

/*
 * Database backup against the Docker test sites (UZEROWPUP-38). Never real sites.
 */

if (! Fixtures::up('wp83')) {
    it('backs up the database of the test site')->skip('Llocs de prova aturats: make fixtures-up');

    return;
}

function backupDriver(): SshDriver
{
    return app(SshDriverFactory::class)->forSite(Fixtures::site('wp83'), AuditActor::system('tests'));
}

it('makes a consistent, compressed dump with every table, 0600 in a 0700 directory', function (): void {
    $driver = backupDriver();
    $result = app(DatabaseBackup::class)->run($driver, 'it'.bin2hex(random_bytes(3)));

    try {
        $path = '$HOME/.wpup/'.$result->file->path;
        $out = Fixtures::run('wp83', "stat -c '%a' \$HOME/.wpup {$path}; gunzip -c {$path} | head -c 2000");

        expect($result->tables)->toContain('wp_options', 'wp_posts')
            ->and(array_diff($driver->databaseTables(), $driver->dumpedTables($result->file)))->toBe([])
            ->and($out)->toStartWith("700\n600\n")
            ->and($out)->toContain(' dump')
            ->and(Fixtures::run('wp83', 'ls $HOME/.wpup | grep -c "part" || true'))->toBe("0\n");
    } finally {
        $driver->remove($result->file->path);
    }
});

it('ignores views: they are not expected tables, and the backup passes with one present', function (): void {
    $driver = backupDriver();
    $view = $driver->wp(['eval', 'global $wpdb; $wpdb->query("CREATE OR REPLACE VIEW wp_wpup_test_view AS SELECT option_id FROM wp_options"); echo $wpdb->last_error;']);
    expect($view->stderr.$view->stdout)->toBe('');
    // WP-CLI's `db query` needs TLS on this fixture; wpdb does not.
    $result = null;

    try {
        $tables = $driver->databaseTables();
        $result = app(DatabaseBackup::class)->run($driver, 'it'.bin2hex(random_bytes(3)));

        expect($tables)->toContain('wp_options');
        expect($tables)->not->toContain('wp_wpup_test_view');
        expect($result->tables)->not->toContain('wp_wpup_test_view');
    } finally {
        $driver->wp(['eval', 'global $wpdb; $wpdb->query("DROP VIEW IF EXISTS wp_wpup_test_view");']);
        if ($result !== null) {
            $driver->remove($result->file->path);
        }
    }
});

it('never exposes the database password: not in the command line, not in the process list, not in logs', function (): void {
    $driver = backupDriver();
    $password = trim($driver->wp(['config', 'get', 'DB_PASSWORD'])->stdout);
    expect(strlen($password))->toBeGreaterThan(3);

    // Sample the process list (what `ps` shows to anyone) while the dump runs. The
    // bracket in the patterns keeps the sampler from matching itself.
    $log = '$HOME/.wpup/ps-sample-'.bin2hex(random_bytes(3));
    $pid = trim(Fixtures::run('wp83', "nohup sh -c 'i=0; while [ \$i -lt 3000 ]; do ps -eo args | grep -E \"[m]ysqldump|[m]ariadb-dump|[w]p-cli\" >> {$log}; i=\$((i+1)); done' >/dev/null 2>&1 & echo \$!"));
    expect($pid)->toMatch('/^\d+$/');
    $result = null;

    try {
        $result = app(DatabaseBackup::class)->run($driver, 'it'.bin2hex(random_bytes(3)));
    } finally {
        // Stop the sampler and wait until it is gone before reading and deleting its log.
        Fixtures::run('wp83', "kill {$pid} 2>/dev/null; n=0; while kill -0 {$pid} 2>/dev/null && [ \$n -lt 50 ]; do sleep 0.1; n=\$((n+1)); done");
    }

    try {
        $sampled = Fixtures::run('wp83', "cat {$log}");
        $logs = implode("\n", array_map(static fn (string $f): string => (string) file_get_contents($f), glob(storage_path('logs/*.log')) ?: []));

        // The test fails, not passes empty, if the dump process was never seen.
        expect($sampled)->toMatch('/mysqldump|mariadb-dump/');
        expect($sampled)->toContain('wp-cli.phar');
        expect($sampled)->not->toContain($password);
        expect($logs)->not->toContain($password);
    } finally {
        Fixtures::run('wp83', "rm -f {$log}");
        $driver->remove($result->file->path);
    }
});
