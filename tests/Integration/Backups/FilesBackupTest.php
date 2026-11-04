<?php

declare(strict_types=1);

use App\Domain\Audit\AuditActor;
use App\Domain\Backups\FilesBackup;
use App\Domain\Drivers\Ssh\RsyncTransfer;
use App\Domain\Drivers\Ssh\SshDriver;
use App\Domain\Drivers\Ssh\SshDriverFactory;
use Illuminate\Support\Facades\File;
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
 * Writes $bytes bytes of $byte (or a copy of the first $bytes of $from) in 1 MB
 * steps: the archives are tens of MB and PHP runs the tests with 128 MB.
 */
function writeChunked(string $to, int $bytes, string $byte = 'x', ?string $from = null): void
{
    $out = fopen($to, 'wb');
    $in = $from === null ? null : fopen($from, 'rb');
    if ($out === false || $in === false) {
        throw new RuntimeException('Cannot open the test files.');
    }
    for ($done = 0; $done < $bytes; $done += 1_048_576) {
        $size = max(1, min(1_048_576, $bytes - $done));
        fwrite($out, $in === null ? str_repeat($byte, $size) : (string) fread($in, $size));
    }
    fclose($out);
    if ($in !== null) {
        fclose($in);
    }
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

it('keeps the work directory of the tool out of the archive when it is inside the WordPress root', function (): void {
    // The work directory is relative to the account's home; the fixture's docroot is httpdocs.
    config(['wpup.ssh.work_dir' => 'httpdocs/.wpup-in-root']);
    app()->forgetInstance(SshDriverFactory::class);
    $driver = filesDriver();
    $result = null;

    try {
        $result = app(FilesBackup::class)->run($driver, false, 'it'.bin2hex(random_bytes(3)));
        $listing = implode("\n", array_values(array_filter(explode("\n", Fixtures::run('wp83', 'tar -tzf $HOME/httpdocs/.wpup-in-root/'.$result->file->path)))));

        expect($driver->workDirExclude())->toBe('.wpup-in-root')
            ->and($result->excludes)->toContain('.wpup-in-root')
            ->and($listing)->toContain('./wp-config.php')
            ->and($listing)->not->toContain('.wpup-in-root/')
            ->and($listing)->not->toContain('wp-cli.phar')
            ->and($listing)->toContain("\n.wpup-excludes");
        // The manifest inside the archive matches what the result reports.
        $manifest = Fixtures::run('wp83', 'tar -xzOf $HOME/httpdocs/.wpup-in-root/'.$result->file->path.' .wpup-excludes');
        expect(array_values(array_filter(explode("\n", $manifest))))->toBe($result->excludes);
    } finally {
        Fixtures::run('wp83', 'rm -rf $HOME/httpdocs/.wpup-in-root');
        config(['wpup.ssh.work_dir' => '.wpup']);
        app()->forgetInstance(SshDriverFactory::class);
    }
});

it('resumes an interrupted download from the partial directory, and never shows a partial file as final', function (): void {
    $driver = filesDriver();
    $result = app(FilesBackup::class)->run($driver, false, 'it'.bin2hex(random_bytes(3)));
    $dir = sys_get_temp_dir().'/wpup-resume-'.bin2hex(random_bytes(4));
    mkdir($dir, 0700);
    $full = $dir.'/full';
    $final = $dir.'/restore-point.tar.gz';

    try {
        $driver->download($result->file, $full);
        $half = max(1, intdiv((int) filesize($full), 2));
        expect(hash_file('sha256', $full))->toBe($result->file->sha256)
            ->and(substr(sprintf('%o', (int) fileperms($full)), -4))->toBe('0600');

        // What an interrupted rsync leaves: the first half, in the hidden directory.
        mkdir($dir.'/'.RsyncTransfer::PARTIAL_DIR, 0700);
        writeChunked($dir.'/'.RsyncTransfer::PARTIAL_DIR.'/restore-point.tar.gz', $half, from: $full);
        expect(file_exists($final))->toBeFalse();

        $driver->download($result->file, $final);

        expect(hash_file('sha256', $final))->toBe($result->file->sha256)
            ->and(file_exists($dir.'/'.RsyncTransfer::PARTIAL_DIR.'/restore-point.tar.gz'))->toBeFalse();
    } finally {
        File::deleteDirectory($dir);
        $driver->remove($result->file->path);
    }
});

it('replaces an old or corrupt final file, even of the same size, and a partial with wrong bytes', function (): void {
    $driver = filesDriver();
    $result = app(FilesBackup::class)->run($driver, false, 'it'.bin2hex(random_bytes(3)));
    $dir = sys_get_temp_dir().'/wpup-replace-'.bin2hex(random_bytes(4));
    mkdir($dir, 0700);
    $same = $dir.'/same-size.tar.gz';
    $short = $dir.'/short.tar.gz';

    try {
        writeChunked($same, $result->file->sizeBytes);
        touch($same, time() + 3600);
        file_put_contents($short, 'old');
        mkdir($dir.'/'.RsyncTransfer::PARTIAL_DIR, 0700);
        file_put_contents($dir.'/'.RsyncTransfer::PARTIAL_DIR.'/short.tar.gz', str_repeat('y', 4096));

        $driver->download($result->file, $same);
        $driver->download($result->file, $short);

        expect(hash_file('sha256', $same))->toBe($result->file->sha256)
            ->and(hash_file('sha256', $short))->toBe($result->file->sha256);
    } finally {
        File::deleteDirectory($dir);
        $driver->remove($result->file->path);
    }
});
