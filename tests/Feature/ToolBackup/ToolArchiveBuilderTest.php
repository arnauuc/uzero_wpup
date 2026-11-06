<?php

declare(strict_types=1);

use App\Domain\Secrets\SecretRedactor;
use App\Domain\ToolBackup\ToolArchiveBuilder;
use App\Domain\ToolBackup\ToolBackupContents;
use App\Domain\ToolBackup\ToolBackupException;
use App\Domain\ToolBackup\ToolBackupFile;
use App\Domain\ToolBackup\ToolBackupSettings;
use App\Domain\ToolBackup\ToolDatabase;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

/*
 * The tool's own backup archive (UZEROWPUP-47): a real pg_dump of the test
 * database, the configuration and reports minus anything secret, tarred and
 * encrypted with a throwaway age key generated here.
 */

/**
 * @param  list<string>  $command
 */
function toolBackupRun(array $command, ?string $cwd = null): string
{
    $process = new Process($command, $cwd, null, null, 120);
    $process->mustRun();

    return $process->getOutput();
}

/**
 * @return array{string, string} the identity file and its recipient
 */
function toolBackupAgeKey(string $dir): array
{
    toolBackupRun(['age-keygen', '-o', $dir.'/identity.txt']);

    return [$dir.'/identity.txt', trim(toolBackupRun(['age-keygen', '-y', $dir.'/identity.txt']))];
}

/**
 * @param  list<string>  $recipients
 * @param  list<string>  $paths
 */
function toolBackupBuilder(array $recipients, array $paths, string $staging, ?ToolDatabase $database = null): ToolArchiveBuilder
{
    $settings = new ToolBackupSettings($recipients, $paths, [], $staging, 600, 600, 600, 30, 12, 7200);

    return new ToolArchiveBuilder($settings, new ToolBackupContents, $database ?? app(ToolDatabase::class), app(SecretRedactor::class));
}

/**
 * A throwaway tree with configuration, reports, things that must never be
 * copied, and an age key pair. Removed after each test.
 */
final readonly class ToolBackupFixture
{
    public string $staging;

    public string $identity;

    public string $recipient;

    public function __construct(public string $dir)
    {
        $this->staging = $dir.'/staging';
        mkdir($dir.'/etc/reports/secrets', 0700, true);
        mkdir($dir.'/keyring', 0700, true);
        file_put_contents($dir.'/etc/config.env', "WPUP_APP_DIR=/opt/uzero-wpup\nDB_PASSWORD=\n");
        file_put_contents($dir.'/etc/reports/run-12.html', '<h1>Informe</h1>');
        file_put_contents($dir.'/etc/reports/.env', 'APP_KEY=base64:never');
        file_put_contents($dir.'/etc/reports/secrets/kek', 'never');
        file_put_contents($dir.'/etc/reports/id_ed25519', 'never');
        file_put_contents($dir.'/etc/reports/notes.txt', "-----BEGIN OPENSSH PRIVATE KEY-----\nnever\n-----END OPENSSH PRIVATE KEY-----\n");
        file_put_contents($dir.'/etc/reports/settings.env', "DROPBOX_TOKEN=sl.never\n");
        symlink($dir.'/etc/reports/.env', $dir.'/etc/reports/link.html');
        [$this->identity, $this->recipient] = toolBackupAgeKey($dir.'/keyring');
    }

    /**
     * @return list<string>
     */
    public function stagingFiles(): array
    {
        return array_values(array_diff(scandir($this->staging) ?: [], ['.', '..']));
    }
}

/**
 * The fixture directories created by the current test; $flush empties the list.
 *
 * @return list<string>
 */
function toolBackupDirs(?string $add = null, bool $flush = false): array
{
    /** @var list<string> $dirs */
    static $dirs = [];
    if ($add !== null) {
        $dirs[] = $add;
    }
    $current = $dirs;
    if ($flush) {
        $dirs = [];
    }

    return $current;
}

function toolBackupFixture(): ToolBackupFixture
{
    $dir = storage_path('framework/testing/tool-backup-'.bin2hex(random_bytes(6)));
    toolBackupDirs($dir);

    return new ToolBackupFixture($dir);
}

afterEach(function (): void {
    foreach (toolBackupDirs(flush: true) as $dir) {
        File::deleteDirectory($dir);
    }
});

it('dumps the database, adds the configuration and reports, and encrypts it all with age', function (): void {
    $f = toolBackupFixture();
    $archive = toolBackupBuilder([$f->recipient], [$f->dir.'/etc/config.env', $f->dir.'/etc/reports', $f->dir.'/etc/missing'], $f->staging)
        ->build(CarbonImmutable::parse('2026-11-05 03:00:00'));

    // Only the ciphertext is left in the staging directory, private.
    expect($f->stagingFiles())->toBe([basename($archive->path)])
        ->and(fileperms($f->staging) & 0777)->toBe(0700)
        ->and(fileperms($archive->path) & 0777)->toBe(0600)
        ->and(file_get_contents($archive->path, false, null, 0, 21))->toBe('age-encryption.org/v1')
        ->and($archive->sha256)->toBe(hash_file('sha256', $archive->path));

    $out = $f->dir.'/restored';
    mkdir($out);
    toolBackupRun(['age', '--decrypt', '--identity', $f->identity, '--output', $out.'/archive.tar', $archive->path]);
    toolBackupRun(['tar', '--extract', '--file=archive.tar'], $out);

    $listing = toolBackupRun(['pg_restore', '--list', $out.'/db.dump']);
    /** @var array{database: array{name: string}, files: list<array{path: string}>} $manifest */
    $manifest = json_decode((string) file_get_contents($out.'/manifest.json'), true, flags: JSON_THROW_ON_ERROR);
    $archived = array_map(static fn (SplFileInfo $file): string => substr($file->getPathname(), strlen($out) + 1), File::allFiles($out, true));
    sort($archived);

    expect($listing)->toContain('TABLE public sites', 'TABLE public audit_log', 'TABLE public secrets', 'TABLE DATA public runs')
        ->and($archived)->toBe([
            'archive.tar',
            'db.dump',
            'files'.$f->dir.'/etc/config.env',
            'files'.$f->dir.'/etc/reports/run-12.html',
            'manifest.json',
        ])
        ->and(file_get_contents($out.'/files'.$f->dir.'/etc/reports/run-12.html'))->toBe('<h1>Informe</h1>')
        ->and($manifest['database']['name'])->toBe('wpup_test')
        ->and(array_column($manifest['files'], 'path'))->toBe([$f->dir.'/etc/config.env', $f->dir.'/etc/reports/run-12.html'])
        ->and($archive->warnings)->toHaveCount(7)
        ->and(implode("\n", $archive->warnings))->toContain('/etc/missing', 'reports/.env', 'reports/secrets', 'id_ed25519', 'notes.txt', 'settings.env', 'link.html');
});

it('never includes secret-looking paths, whatever the configuration lists', function (string $path): void {
    expect(ToolBackupContents::isDenied($path))->toBeTrue();
})->with([
    '/opt/uzero-wpup/.env',
    '/opt/uzero-wpup/.env.production',
    '/opt/uzero-wpup/storage/app/secrets/kek',
    '/home/wpup/.ssh/id_ed25519',
    '/etc/credstore.encrypted/wpup-kek',
    '/run/credentials/wpup-horizon.service/kek',
    '/opt/uzero-wpup/docker/fixtures/.keys/known_hosts',
    '/srv/tls/server.KEY',
    '/srv/tls/server.pem',
    '/root/wpup.kdbx',
]);

it('refuses to run without a valid age recipient, before dumping anything', function (array $recipients, string $message): void {
    /** @var list<string> $recipients */
    $f = toolBackupFixture();

    expect(fn () => toolBackupBuilder($recipients, [], $f->staging)->build(CarbonImmutable::now()))
        ->toThrow(ToolBackupException::class, $message)
        ->and(is_dir($f->staging))->toBeFalse();
})->with([
    'none' => [[], 'cap destinatari'],
    'a typo' => [['age1notakey'], 'no és una clau pública vàlida'],
    'a private key' => [['AGE-SECRET-KEY-1QQQQQQQQQQQQQQQQQQQQQQQQQQQQQQQQQQQQQQQQQQQQQQQQQQQQQQQQQQ'], 'no és una clau pública vàlida'],
]);

it('fails without leaving plaintext or quoting the database password', function (): void {
    $f = toolBackupFixture();
    $password = 'pg-pass-'.bin2hex(random_bytes(8));
    $database = new ToolDatabase('wpup_missing_db', config()->string('database.connections.pgsql.host'), '5432', 'wpup_app', $password, null);

    expect(implode(' ', $database->arguments()))->not->toContain($password)
        ->and(fn () => toolBackupBuilder([$f->recipient], [$f->dir.'/etc/config.env'], $f->staging, $database)->build(CarbonImmutable::now()))
        ->toThrow(static function (ToolBackupException $e) use ($password): void {
            expect($e->getMessage())->toContain('pg_dump')
                ->and(str_contains($e->getMessage(), $password))->toBeFalse();
        })
        ->and($f->stagingFiles())->toBe([]);
});

it('removes what a run that died left in the staging directory, and only that', function (): void {
    $f = toolBackupFixture();
    mkdir($f->staging.'/tool-backup-dead/files', 0700, true);
    file_put_contents($f->staging.'/tool-backup-dead/db.dump', 'plaintext');
    file_put_contents($f->staging.'/tool-backup-dead.tar.age', 'old');
    mkdir($f->staging.'/tool-backup-busy', 0700);
    file_put_contents($f->staging.'/other-file', 'not ours');
    touch($f->staging.'/tool-backup-dead', time() - 7201);
    touch($f->staging.'/tool-backup-dead.tar.age', time() - 7201);
    touch($f->staging.'/other-file', time() - 7201);

    $archive = toolBackupBuilder([$f->recipient], [$f->dir.'/etc/config.env'], $f->staging)->build(CarbonImmutable::now());
    $expected = ['other-file', basename($archive->path), 'tool-backup-busy'];
    sort($expected);

    expect($f->stagingFiles())->toBe($expected)
        ->and(implode("\n", $archive->warnings))->toContain('«tool-backup-dead»', '«tool-backup-dead.tar.age»');
});

it('recognises secrets in env, INI, YAML and JSON, whatever the case', function (string $text): void {
    expect(ToolBackupContents::looksLikeSecret($text))->toBeTrue();
})->with([
    'env' => ["DB_PASSWORD=hunter2\n"],
    'lowercase env' => ["db_password=hunter2\n"],
    'export' => ["export API_KEY='abc'\n"],
    'PASS' => ["SMTP_PASS=abc\n"],
    'INI' => ["[db]\npassword = hunter2\n"],
    'YAML' => ["database:\n  db_password: hunter2\n"],
    'JSON' => ['{"user": "x", "password": "hunter2"}'],
    'JSON token' => ['{"access_token":"sl.abc"}'],
    'WordPress AUTH_KEY' => ["AUTH_KEY=abc\n"],
    'AUTHKEY' => ["AUTHKEY: abc\n"],
    'Tailscale authkey' => ["TS_AUTHKEY=tskey-auth-kAbC123CNTRL-xyz\n"],
    'bare Tailscale key' => ["tailscale up tskey-auth-kAbC123\n"],
    'secret' => ["client_secret: abc\n"],
    'private' => ["private_key: abc\n"],
    'PEM' => ["-----BEGIN RSA PRIVATE KEY-----\nabc\n"],
    'age identity' => ["age-secret-key-1qqqq\n"],
]);

it('lets non-secret configuration through', function (string $text): void {
    expect(ToolBackupContents::looksLikeSecret($text))->toBeFalse();
})->with([
    'empty password' => ["WPUP_APP_DIR=/opt/uzero-wpup\nDB_PASSWORD=\n"],
    'null password' => ["password: null\n"],
    'empty JSON' => ['{"password": ""}'],
    'plain settings' => ["WPUP_WEB_PORT=8080\nWPUP_SERVICE_USER=wpup\n"],
]);

it('skips names that are not UTF-8 and subdirectories it cannot enter, but not the rest', function (): void {
    $f = toolBackupFixture();
    mkdir($f->dir.'/etc/reports/locked', 0700);
    file_put_contents($f->dir.'/etc/reports/locked/inside.html', 'x');
    chmod($f->dir.'/etc/reports/locked', 0000);
    file_put_contents($f->dir."/etc/reports/run-\xff.html", 'x');

    try {
        [$files, $warnings] = (new ToolBackupContents)->collect([$f->dir.'/etc/reports']);
    } finally {
        chmod($f->dir.'/etc/reports/locked', 0700);
    }

    expect(array_map(static fn (ToolBackupFile $file): string => $file->source, $files))->toBe([$f->dir.'/etc/reports/run-12.html'])
        ->and(implode("\n", $warnings))->toContain('reports/locked»: no s\'hi pot entrar', 'no és UTF-8')
        ->and(mb_check_encoding(implode("\n", $warnings), 'UTF-8'))->toBeTrue();
});
