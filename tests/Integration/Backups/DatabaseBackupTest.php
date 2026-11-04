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

it('never exposes the database password: not in the command line, not in the process list, not in logs', function (): void {
    $driver = backupDriver();
    $password = trim($driver->wp(['config', 'get', 'DB_PASSWORD'])->stdout);
    expect(strlen($password))->toBeGreaterThan(3);

    // Sample the process list of the account (what `ps` shows to anyone) during the dump.
    $log = '$HOME/.wpup/ps-sample-'.bin2hex(random_bytes(3));
    Fixtures::run('wp83', "nohup sh -c 'i=0; while [ \$i -lt 1500 ]; do ps -eo args | grep -E \"dump|mysql|mariadb|wp-cli\" >> {$log}; i=\$((i+1)); done' >/dev/null 2>&1 &");

    $result = app(DatabaseBackup::class)->run($driver, 'it'.bin2hex(random_bytes(3)));

    try {
        Fixtures::run('wp83', 'sleep 1');
        $sampled = Fixtures::run('wp83', "cat {$log}");
        $logs = implode("\n", array_map(static fn (string $f): string => (string) file_get_contents($f), glob(storage_path('logs/*.log')) ?: []));

        expect($sampled)->toContain('dump')
            ->and($sampled)->not->toContain($password)
            ->and($logs)->not->toContain($password);
    } finally {
        Fixtures::run('wp83', "rm -f {$log}");
        $driver->remove($result->file->path);
    }
});
