<?php

declare(strict_types=1);

namespace App\Domain\Inventory;

use App\Domain\Drivers\Data\PhpRuntime;
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

    public function __construct(
        private HttpFactory $http,
        private SecretRedactor $redactor,
        private int $timeoutSeconds,
        private int $ttlSeconds,
        private bool $verifyTls,
        private int $maxResponseBytes,
        private int $maxExtensions,
    ) {}

    public function run(Site $site, SiteDriver $driver): HttpProbeResult
    {
        $name = 'wpup-probe-'.bin2hex(random_bytes(12)).'.php';
        $token = bin2hex(random_bytes(32));
        // Never logged or stored, but mask it anyway in anything this process prints.
        $this->redactor->register($token);

        try {
            $driver->putWebFile($name, self::script($token, $this->ttlSeconds));
            $result = $this->call($this->baseUrl($site, $driver).'/'.$name, $token, $name);
        } catch (DriverException $e) {
            // A failed upload may still have left a partial file: remove it too.
            $result = new HttpProbeResult($name, null, error: "no s'ha pogut pujar el probe: {$e->getMessage()}");
        } finally {
            $removed = $this->remove($driver, $name, $site);
        }

        return new HttpProbeResult($result->fileName, $result->version, $result->sapi, $result->extensions, $result->error, $removed, $result->errorLog);
    }

    private function call(string $url, string $token, string $name): HttpProbeResult
    {
        try {
            $response = $this->http
                ->timeout($this->timeoutSeconds)
                ->withOptions(['allow_redirects' => false, 'verify' => $this->verifyTls, 'stream' => true])
                ->withHeaders(['X-Wpup-Probe' => $token, 'Accept' => 'application/json'])
                ->get($url);
        } catch (ConnectionException $e) {
            return new HttpProbeResult($name, null, error: "el lloc no respon per HTTP ({$this->redactor->redact($e->getMessage())})");
        }

        if (! $response->successful()) {
            return new HttpProbeResult($name, null, error: "el probe ha respost HTTP {$response->status()}".($response->redirect() ? ' (redirecció: revisa la URL del lloc)' : ''));
        }

        // Read at most max_response_bytes: a compromised site could answer anything.
        $body = $response->toPsrResponse()->getBody()->read($this->maxResponseBytes + 1);
        $data = strlen($body) > $this->maxResponseBytes ? null : json_decode($body, true);
        $version = is_array($data) ? ($data['php_version'] ?? null) : null;

        if (! is_string($version) || ! PhpRuntime::isVersion($version)) {
            return new HttpProbeResult($name, null, error: 'la resposta del probe no és vàlida');
        }

        $extensions = is_array($data['extensions'] ?? null) ? $data['extensions'] : [];
        $extensions = array_values(array_filter(
            array_slice($extensions, 0, $this->maxExtensions),
            static fn (mixed $e): bool => is_string($e) && preg_match('/^[A-Za-z0-9_ .+-]{1,64}$/', $e) === 1,
        ));
        $sapi = is_string($data['sapi'] ?? null) && preg_match('/^[a-z0-9-]{1,32}$/', $data['sapi']) === 1 ? $data['sapi'] : null;

        // The web's error_log, only when it is an absolute path of a sane length (it can also be "syslog" or empty).
        $errorLog = $data['error_log'] ?? null;
        $errorLog = is_string($errorLog) && $errorLog !== '' && $errorLog[0] === '/' && strlen($errorLog) <= 1024 && ! str_contains($errorLog, "\n") ? $errorLog : null;

        return new HttpProbeResult($name, $version, $sapi, $extensions, errorLog: $errorLog);
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

    /**
     * The probe's address: the site's scheme and host with the path WordPress
     * itself is installed under (siteurl), since the file lands in its root.
     */
    private function baseUrl(Site $site, SiteDriver $driver): string
    {
        $base = rtrim($site->url, '/');
        $siteurl = $driver->wp(['option', 'get', 'siteurl']);
        $path = $siteurl->successful() ? parse_url(trim($siteurl->stdout), PHP_URL_PATH) : null;

        if (! is_string($path) || trim($path, '/') === '') {
            return $base;
        }

        $parts = parse_url($base);
        $origin = ($parts['scheme'] ?? 'https').'://'.($parts['host'] ?? '').(isset($parts['port']) ? ':'.$parts['port'] : '');

        return $origin.'/'.trim($path, '/');
    }

    /**
     * The probe file for $token, alive for $ttlSeconds after it is written.
     */
    public static function script(string $token, int $ttlSeconds): string
    {
        $template = file_get_contents(base_path(self::TEMPLATE));

        if ($template === false) {
            throw new RuntimeException('The HTTP probe template is missing: '.self::TEMPLATE);
        }

        return strtr($template, [
            '__WPUP_TOKEN_SHA256__' => hash('sha256', $token),
            '__WPUP_TTL_SECONDS__' => (string) $ttlSeconds,
        ]);
    }
}
