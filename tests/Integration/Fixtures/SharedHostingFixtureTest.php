<?php

declare(strict_types=1);

use App\Domain\Audit\AuditActor;
use App\Domain\Drivers\Ssh\SshDriverFactory;
use Illuminate\Support\Facades\Http;
use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Net\SFTP;
use phpseclib3\Net\SSH2;
use Tests\Support\Fixtures;

/*
 * Shared-hosting test sites (UZEROWPUP-135): wp80 is SFTP-only with low web
 * limits, wp82 runs on MySQL 8.0. Skipped, with a visible reason, when the
 * fixtures are not running.
 */

function fixtureSftp(string $name): SFTP
{
    $sftp = new SFTP($name, 22, 5);
    $key = PublicKeyLoader::loadPrivateKey((string) file_get_contents(base_path('docker/fixtures/.keys/id_ed25519')));

    expect($sftp->login("sub_{$name}", $key))->toBeTrue();

    return $sftp;
}

if (! Fixtures::up('wp80') || ! Fixtures::up('wp82')) {
    it('works against the shared-hosting test sites')->skip('Llocs de prova aturats: make fixtures-up');

    return;
}

describe('wp80 (SFTP only, shared-hosting limits)', function (): void {
    it('refuses to run shell commands over SSH', function (): void {
        $ssh = new SSH2('wp80', 22, 5);
        $key = PublicKeyLoader::loadPrivateKey((string) file_get_contents(base_path('docker/fixtures/.keys/id_ed25519')));
        expect($ssh->login('sub_wp80', $key))->toBeTrue();

        // internal-sftp waits for an SFTP handshake instead of running the command, so the read times out.
        // The marker differs from the command text: only a real shell could print it.
        $ssh->setTimeout(3);
        $output = $ssh->exec('printf "%s" "wpup-$((40+2))"');
        $ssh->disconnect();

        expect(is_string($output) ? $output : '')->not->toContain('wpup-42');
    });

    it('allows SFTP login and lists the docroot', function (): void {
        $sftp = fixtureSftp('wp80');

        expect($sftp->nlist('/var/www/vhosts/wp80.fixtures.test/httpdocs'))->toContain('wp-config.php');
    });

    it('applies the web memory and execution limits, not the CLI ones', function (): void {
        $sftp = fixtureSftp('wp80');
        $name = 'wpup-limits-'.bin2hex(random_bytes(6)).'.php';
        $path = "/var/www/vhosts/wp80.fixtures.test/httpdocs/{$name}";

        try {
            expect($sftp->put($path, '<?php echo ini_get("memory_limit"), "|", ini_get("max_execution_time");'))->toBeTrue();

            expect(Http::timeout(30)->get("http://wp80:8085/{$name}")->body())->toBe('128M|20');
        } finally {
            $sftp->delete($path);
        }
    });
});

describe('wp82 (MySQL 8.0)', function (): void {
    it('runs on MySQL 8.0', function (): void {
        $driver = app(SshDriverFactory::class)->forSite(Fixtures::site('wp82'), AuditActor::system('tests'));

        expect($driver->installedComponents()->dbServerVersion)->toStartWith('8.0')
            // A real query through $wpdb (not `wp db query`, which shells out to the mariadb client).
            ->and(trim(Fixtures::run('wp82', 'wp --path=/var/www/vhosts/wp82.fixtures.test/httpdocs eval \'echo $GLOBALS["wpdb"]->get_var("SELECT VERSION()");\'')))->toStartWith('8.0');
    });
});
