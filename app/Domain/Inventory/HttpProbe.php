<?php

declare(strict_types=1);

namespace App\Domain\Inventory;

use App\Domain\Drivers\Exceptions\DriverException;
use App\Domain\Drivers\SiteDriver;
use App\Domain\Secrets\SecretRedactor;
use App\Models\Site;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Cross-checks the PHP the web really runs (UZEROWPUP-30, AGENTS.md: "PHP del
 * lloc: sempre el que serveix el web"). Uploads resources/probe/probe.php with a
 * random name and a single-use token whose SHA-256 is all the file holds, calls
 * it once and always deletes it, also when anything fails. The file deletes
 * itself after answering and refuses to answer after its deadline, so a failed
 * deletion leaves nothing usable behind.
 */
final readonly class HttpProbe
{
    private const string TEMPLATE = 'resources/probe/probe.php';

    private const int MAX_EXTENSIONS = 500;

    public function __construct(
        private HttpFactory $http,
        private SecretRedactor $redactor,
        private int $timeoutSeconds,
        private int $ttlSeconds,
        private bool $verifyTls,
    ) {}

    public function run(Site $site, SiteDriver $driver): HttpProbeResult
    {
        $name = 'wpup-probe-'.bin2hex(random_bytes(12)).'.php';
        $token = bin2hex(random_bytes(32));
        // Never logged or stored, but mask it anyway in anything this process prints.
        $this->redactor->register($token);

        try {
            $driver->putWebFile($name, $this->script($token));
        } catch (DriverException $e) {
            return new HttpProbeResult($name, null, error: "no s'ha pogut pujar el probe: {$e->getMessage()}");
        }

        try {
            $result = $this->call(rtrim($site->url, '/').'/'.$name, $token, $name);
        } finally {
            $removed = $this->remove($driver, $name, $site);
        }

        return new HttpProbeResult($result->fileName, $result->version, $result->sapi, $result->extensions, $result->error, $removed);
    }

    private function call(string $url, string $token, string $name): HttpProbeResult
    {
        try {
            $response = $this->http
                ->timeout($this->timeoutSeconds)
                ->withOptions(['allow_redirects' => false, 'verify' => $this->verifyTls])
                ->withHeaders(['X-Wpup-Probe' => $token, 'Accept' => 'application/json'])
                ->get($url);
        } catch (ConnectionException $e) {
            return new HttpProbeResult($name, null, error: "el lloc no respon per HTTP ({$this->redactor->redact($e->getMessage())})");
        }

        if (! $response->successful()) {
            return new HttpProbeResult($name, null, error: "el probe ha respost HTTP {$response->status()}".($response->redirect() ? ' (redirecció: revisa la URL del lloc)' : ''));
        }

        $data = $response->json();
        $version = is_array($data) ? ($data['php_version'] ?? null) : null;

        if (! is_string($version) || preg_match('/^\d+\.\d+\.\d+/', $version) !== 1) {
            return new HttpProbeResult($name, null, error: 'la resposta del probe no és vàlida');
        }

        $extensions = is_array($data['extensions'] ?? null) ? $data['extensions'] : [];
        $extensions = array_values(array_filter(
            array_slice($extensions, 0, self::MAX_EXTENSIONS),
            static fn (mixed $e): bool => is_string($e) && preg_match('/^[A-Za-z0-9_ .+-]{1,64}$/', $e) === 1,
        ));
        $sapi = is_string($data['sapi'] ?? null) ? mb_substr($data['sapi'], 0, 32) : null;

        return new HttpProbeResult($name, $version, $sapi, $extensions);
    }

    private function remove(SiteDriver $driver, string $name, Site $site): bool
    {
        try {
            $driver->removeWebFile($name);

            return true;
        } catch (DriverException $e) {
            Log::warning('The HTTP probe could not be removed; it expires on its own.', ['site' => $site->name, 'file' => $name, 'error' => $e->getMessage()]);

            return false;
        }
    }

    private function script(string $token): string
    {
        $template = file_get_contents(base_path(self::TEMPLATE));

        if ($template === false) {
            throw new RuntimeException('The HTTP probe template is missing: '.self::TEMPLATE);
        }

        return strtr($template, [
            '__WPUP_TOKEN_SHA256__' => hash('sha256', $token),
            '__WPUP_EXPIRES_AT__' => (string) (time() + $this->ttlSeconds),
        ]);
    }
}
