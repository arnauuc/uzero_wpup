<?php

declare(strict_types=1);

namespace App\Domain\Artifacts;

use App\Enums\ComponentType;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * Downloads a package to the run's artifacts (UZEROWPUP-49) from the tool
 * server, never from the site: HTTPS only, to public addresses on allowed
 * ports, pinned to the address that was checked (PackageUrlGuard::pin);
 * redirects are followed by hand so every hop is checked the same way and
 * its tokens registered before any request. Bounded in time and size,
 * hashed while it streams to disk, and accepted only if it is a sane ZIP.
 * Messages and logs name the host, never the URL; an address that is
 * refused and one that does not answer get the same message.
 *
 * Idempotent: a package the run already holds is verified and reused, never
 * downloaded again (staging and production get the same bytes).
 */
final readonly class ArtifactFetcher
{
    public function __construct(
        private ClientInterface $http,
        private ArtifactStore $store,
        private PackageUrlGuard $guard,
        private ArtifactSettings $settings,
    ) {}

    /**
     * @throws ArtifactException
     */
    public function fetch(int $runId, PackageSource $source): Artifact
    {
        $existing = $this->store->find($runId, $source->type, $source->slug, $source->version);
        if ($existing !== null) {
            return $this->store->verify($existing);
        }

        $temporary = $this->store->temporaryFile($runId);
        try {
            [$sha256, $size] = $this->download($source, $temporary);
            $this->checkZip($source, $temporary);

            return $this->store->commit($runId, $source, $temporary, $sha256, $size);
        } finally {
            if (file_exists($temporary)) {
                @unlink($temporary);
            }
        }
    }

    /**
     * @return array{0: string, 1: int} SHA-256 and size of what was written
     */
    private function download(PackageSource $source, string $temporary): array
    {
        $url = $source->url->reveal();
        for ($hops = 0; ; $hops++) {
            $response = $this->request($source, $url);
            $status = $response->getStatusCode();
            if ($status < 300 || $status >= 400 || ! $response->hasHeader('Location')) {
                break;
            }
            $response->getBody()->close();
            if ($hops >= $this->settings->maxRedirects) {
                throw ArtifactException::download($source, 'massa redireccions.');
            }
            try {
                $url = (string) UriResolver::resolve(new Uri($url), new Uri($response->getHeaderLine('Location')));
            } catch (InvalidArgumentException) {
                // Its message quotes the URI.
                throw ArtifactException::download($source, 'redirecció no vàlida.');
            }
            $this->guard->register($url, $source->slug, $source->version);
        }

        if ($status !== 200) {
            throw ArtifactException::download($source, "el servidor ha respost HTTP {$status}.");
        }
        $length = $response->getHeaderLine('Content-Length');
        if (ctype_digit($length) && (int) $length > $this->settings->maxBytes) {
            throw ArtifactException::download($source, $this->tooBig());
        }

        return $this->stream($source, $response, $temporary);
    }

    private function request(PackageSource $source, #[\SensitiveParameter] string $url): ResponseInterface
    {
        try {
            $pinned = $this->guard->pin($url);

            return $this->http->request('GET', $url, [
                'allow_redirects' => false,
                'http_errors' => false,
                'stream' => true,
                'connect_timeout' => $this->settings->connectTimeoutSeconds,
                'timeout' => $this->settings->timeoutSeconds,
                'read_timeout' => $this->settings->readTimeoutSeconds,
                'headers' => ['Accept' => 'application/zip, application/octet-stream'],
                'curl' => [CURLOPT_RESOLVE => [$pinned], CURLOPT_PROTOCOLS => CURLPROTO_HTTPS],
            ]);
        } catch (DownloadBlocked $e) {
            Log::warning("Artifact download of {$source->slug} {$source->version} refused: {$e->getMessage()}");
            throw ArtifactException::unreachable($source);
        } catch (GuzzleException|InvalidArgumentException $e) {
            // Never the message nor the exception itself: they carry the URL.
            $this->logFailure($source, $e);
            throw ArtifactException::unreachable($source);
        }
    }

    /**
     * @return array{0: string, 1: int}
     */
    private function stream(PackageSource $source, ResponseInterface $response, string $temporary): array
    {
        $out = FileOperation::run(static fn () => fopen($temporary, 'wb'), "no s'ha pogut obrir el fitxer temporal.");
        $hash = hash_init('sha256');
        $size = 0;
        try {
            $body = $response->getBody();
            while (! $body->eof()) {
                $chunk = $body->read($this->settings->chunkBytes);
                $size += strlen($chunk);
                if ($size > $this->settings->maxBytes) {
                    throw ArtifactException::download($source, $this->tooBig());
                }
                hash_update($hash, $chunk);
                $written = FileOperation::run(static fn (): int|false => fwrite($out, $chunk), "no s'ha pogut escriure el paquet (disc ple?).");
                if ($written !== strlen($chunk)) {
                    throw ArtifactException::storage("no s'ha pogut escriure el paquet (disc ple?).");
                }
            }
        } catch (ArtifactException $e) {
            throw $e;
        } catch (RuntimeException $e) {
            $this->logFailure($source, $e);
            throw ArtifactException::download($source, "la transferència s'ha tallat.");
        } finally {
            @fclose($out);
        }

        return [hash_final($hash), $size];
    }

    /**
     * A ZIP (local file header magic, consistent central directory), with no
     * absolute or ".." entry, at most maxUnpackedBytes once unpacked, and one
     * top-level folder ("wordpress" for core), as WordPress packages are.
     */
    private function checkZip(PackageSource $source, string $path): void
    {
        $magic = FileOperation::run(static fn (): string|false => file_get_contents($path, false, null, 0, 4), "no s'ha pogut llegir el paquet baixat.");
        $zip = new ZipArchive;
        if ($magic !== "PK\x03\x04" || $zip->open($path, ZipArchive::CHECKCONS | ZipArchive::RDONLY) !== true) {
            throw ArtifactException::invalidPackage($source, 'no és un fitxer ZIP.');
        }

        try {
            $roots = [];
            $unpacked = 0;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                if ($stat === false) {
                    throw ArtifactException::invalidPackage($source, "no se'n poden llegir les entrades.");
                }
                $name = $stat['name'];
                $segments = explode('/', $name);
                if ($name === '' || $name[0] === '/' || str_contains($name, '\\') || in_array('..', $segments, true)) {
                    throw ArtifactException::invalidPackage($source, 'conté rutes insegures.');
                }
                $unpacked += $stat['size'];
                if ($unpacked > $this->settings->maxUnpackedBytes) {
                    throw ArtifactException::invalidPackage($source, 'descomprimit supera el límit de mida.');
                }
                $roots[$segments[0]] = ($roots[$segments[0]] ?? false) || count($segments) > 1;
            }
        } finally {
            $zip->close();
        }

        $root = array_key_first($roots);
        if (count($roots) !== 1 || $roots[$root] !== true) {
            throw ArtifactException::invalidPackage($source, 'ha de tenir una sola carpeta arrel.');
        }
        if ($source->type === ComponentType::Core && $root !== 'wordpress') {
            throw ArtifactException::invalidPackage($source, 'la carpeta arrel del core ha de ser «wordpress».');
        }
    }

    private function tooBig(): string
    {
        return 'supera la mida màxima de '.intdiv($this->settings->maxBytes, 1_048_576).' MiB.';
    }

    private function logFailure(PackageSource $source, Throwable $e): void
    {
        Log::warning("Artifact download of {$source->slug} {$source->version} from {$source->host()} failed: ".$e::class);
    }
}
