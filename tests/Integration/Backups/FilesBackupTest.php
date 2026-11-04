<?php

declare(strict_types=1);

use App\Domain\Audit\AuditActor;
use App\Domain\Backups\FilesBackup;
use App\Domain\Drivers\Ssh\SshDriver;
use App\Domain\Drivers\Ssh\SshDriverFactory;
use Tests\Support\Fixtures;

/*
 * Code archive against the Docker test sites (UZEROWPUP-39). Never real sites.
 */

if (! Fixtures::up('wp83')) {
    it('backs up the files of the test site')->skip('Llocs de prova aturats: make fixtures-up');

    return;
}

function filesDriver(): SshDriver
{
    return app(SshDriverFactory::class)->forSite(Fixtures::site('wp83'), AuditActor::system('tests'));
}

/**
 * Plants marker files that must or must not end up in the archive; returns the
 * WordPress root.
 */
function plantMarkers(SshDriver $driver): string
{
    $root = trim($driver->wp(['eval', 'echo rtrim(ABSPATH, "/");'])->stdout);
    Fixtures::run('wp83', "cd {$root}/wp-content && mkdir -p uploads/2026 cache updraft uploads/backwpup-x && echo a > uploads/2026/wpup-marker.txt && echo b > cache/wpup-marker.txt && echo c > updraft/wpup-marker.txt && echo d > uploads/backwpup-x/wpup-marker.txt");

    return $root;
}

function removeMarkers(string $root): void
{
    Fixtures::run('wp83', "cd {$root}/wp-content && rm -rf uploads/2026/wpup-marker.txt cache/wpup-marker.txt updraft uploads/backwpup-x");
}

/**
 * @return list<string>
 */
function archiveListing(string $name): array
{
    return array_values(array_filter(explode("\n", Fixtures::run('wp83', 'tar -tzf $HOME/.wpup/'.$name))));
}

it('archives the code with wp-config.php, without uploads, caches or other backups, 0600', function (): void {
    $driver = filesDriver();
    $root = plantMarkers($driver);
    $result = app(FilesBackup::class)->run($driver, false, 'it'.bin2hex(random_bytes(3)));

    try {
        $listing = implode("\n", archiveListing($result->file->path));

        expect($listing)->toContain('./wp-config.php')->toContain('./wp-includes/')->toContain('./wp-content/plugins/')->toContain('./wp-content/themes/')
            ->and($listing)->toContain("\n.wpup-excludes")
            ->and($listing)->not->toContain('wpup-marker.txt')
            ->and($result->warnings)->toBe([])
            ->and(Fixtures::run('wp83', 'stat -c "%a" $HOME/.wpup/'.$result->file->path))->toBe("600\n")
            ->and(Fixtures::run('wp83', 'ls $HOME/.wpup | grep -c "part" || true'))->toBe("0\n");
    } finally {
        $driver->remove($result->file->path);
        removeMarkers($root);
    }
});

it('includes uploads when the profile says so, still without the backups of other plugins', function (): void {
    $driver = filesDriver();
    $root = plantMarkers($driver);
    $result = app(FilesBackup::class)->run($driver, true, 'it'.bin2hex(random_bytes(3)));

    try {
        $listing = implode("\n", archiveListing($result->file->path));

        expect($listing)->toContain('./wp-content/uploads/2026/wpup-marker.txt')
            ->and($listing)->not->toContain('./wp-content/cache/wpup-marker.txt')
            ->and($listing)->not->toContain('updraft/wpup-marker.txt')
            ->and($listing)->not->toContain('backwpup-x');
    } finally {
        $driver->remove($result->file->path);
        removeMarkers($root);
    }
});

it('does not put the work directory of the tool in the archive', function (): void {
    $driver = filesDriver();
    $result = app(FilesBackup::class)->run($driver, false, 'it'.bin2hex(random_bytes(3)));

    try {
        expect(implode("\n", archiveListing($result->file->path)))->not->toContain('.wpup/');
    } finally {
        $driver->remove($result->file->path);
    }
});

it('resumes an interrupted download and detects a corrupt partial file', function (): void {
    $driver = filesDriver();
    $result = app(FilesBackup::class)->run($driver, false, 'it'.bin2hex(random_bytes(3)));
    $full = tempnam(sys_get_temp_dir(), 'wpup-full-');
    $partial = tempnam(sys_get_temp_dir(), 'wpup-part-');
    $corrupt = tempnam(sys_get_temp_dir(), 'wpup-bad-');

    try {
        $driver->download($result->file, $full);
        $half = max(1, intdiv((int) filesize($full), 2));
        expect(hash_file('sha256', $full))->toBe($result->file->sha256)
            ->and(substr(sprintf('%o', (int) fileperms($full)), -4))->toBe('0600');

        // An interrupted transfer: only the first half is there (streamed: archives are big).
        file_put_contents($partial, (string) file_get_contents($full, false, null, 0, $half));
        $driver->download($result->file, $partial);

        // A partial file whose bytes are wrong: rsync verifies and sends it again.
        file_put_contents($corrupt, str_repeat('x', $half));
        $driver->download($result->file, $corrupt);

        expect(hash_file('sha256', $partial))->toBe($result->file->sha256)
            ->and(hash_file('sha256', $corrupt))->toBe($result->file->sha256);
    } finally {
        array_map(unlink(...), [$full, $partial, $corrupt]);
        $driver->remove($result->file->path);
    }
});
