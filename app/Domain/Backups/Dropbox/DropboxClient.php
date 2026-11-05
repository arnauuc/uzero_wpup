<?php

declare(strict_types=1);

namespace App\Domain\Backups\Dropbox;

use App\Domain\Secrets\SecretRedactor;
use Generator;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Support\Sleep;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;

/**
 * The few Dropbox API v2 calls a backup destination needs. Paths are Dropbox
 * paths inside the App folder ("/a/b", or "" for its root).
 *
 * - Uploads go through an upload session in chunks of chunkBytes, each copied
 *   to a disk-backed buffer first, so memory stays bounded and a chunk can be
 *   resent; a file smaller than one chunk uses a single files/upload.
 * - Downloads are written to a disk-backed temporary stream.
 * - 429 and 5xx answers and transport errors are retried up to maxRetries
 *   times, honouring Retry-After capped at maxRetryAfterSeconds; an expired
 *   access token is refreshed once per call.
 */
final readonly class DropboxClient
{
    private const int MAX_ERROR_BYTES = 65_536;

    public function __construct(
        private ClientInterface $http,
        private DropboxTokenProvider $tokens,
        private SecretRedactor $redactor,
        private DropboxSettings $settings,
    ) {}

    /**
     * @return array<mixed>|null the metadata, or null when nothing is there
     */
    public function metadata(string $path): ?array
    {
        try {
            return $this->rpc('files/get_metadata', ['path' => $path]);
        } catch (DropboxException $e) {
            return $e->isNotFound() ? null : throw $e;
        }
    }

    /**
     * @return Generator<int, array<mixed>> file and folder entries
     */
    public function listFolder(string $path, bool $recursive): Generator
    {
        $page = $this->rpc('files/list_folder', ['path' => $path, 'recursive' => $recursive]);
        while (true) {
            foreach (is_array($page['entries'] ?? null) ? $page['entries'] : [] as $entry) {
                if (is_array($entry)) {
                    yield $entry;
                }
            }
            if (($page['has_more'] ?? false) !== true || ! is_string($page['cursor'] ?? null)) {
                return;
            }
            $page = $this->rpc('files/list_folder/continue', ['cursor' => $page['cursor']]);
        }
    }

    public function move(string $from, string $to): void
    {
        $this->rpc('files/move_v2', ['from_path' => $from, 'to_path' => $to, 'autorename' => false]);
    }

    public function copy(string $from, string $to): void
    {
        $this->rpc('files/copy_v2', ['from_path' => $from, 'to_path' => $to, 'autorename' => false]);
    }

    public function delete(string $path): void
    {
        $this->rpc('files/delete_v2', ['path' => $path]);
    }

    public function createFolder(string $path): void
    {
        $this->rpc('files/create_folder_v2', ['path' => $path, 'autorename' => false]);
    }

    /**
     * Stores the rest of $stream at $path, replacing what is there.
     *
     * @param  resource  $stream
     * @return array<mixed> the metadata of the stored file
     */
    public function upload(string $path, $stream): array
    {
        $commit = ['path' => $path, 'mode' => 'overwrite', 'autorename' => false, 'mute' => true];
        $chunk = $this->chunk($stream);
        $size = (int) $chunk->getSize();
        if ($size < $this->settings->chunkBytes) {
            return $this->content('files/upload', $commit, $chunk);
        }

        $session = $this->content('files/upload_session/start', ['close' => false], $chunk);
        $id = is_string($session['session_id'] ?? null) ? $session['session_id'] : throw DropboxException::response('files/upload_session/start', 200, 'sense session_id');
        $offset = $size;
        while (true) {
            $chunk = $this->chunk($stream);
            $size = (int) $chunk->getSize();
            $cursor = ['session_id' => $id, 'offset' => $offset];
            if ($size < $this->settings->chunkBytes) {
                return $this->content('files/upload_session/finish', ['cursor' => $cursor, 'commit' => $commit], $chunk);
            }
            $this->content('files/upload_session/append_v2', ['cursor' => $cursor, 'close' => false], $chunk);
            $offset += $size;
        }
    }

    /**
     * @return resource a disk-backed stream with the file's bytes, at its start
     */
    public function download(string $path)
    {
        $sink = $this->buffer();
        $this->send('files/download', ['path' => $path], true, null, $sink);
        rewind($sink);

        return $sink;
    }

    /**
     * @param  array<string, mixed>  $arg
     * @return array<mixed>
     */
    private function rpc(string $endpoint, array $arg): array
    {
        return self::json($this->send($endpoint, $arg, false));
    }

    /**
     * @param  array<string, mixed>  $arg
     * @return array<mixed>
     */
    private function content(string $endpoint, array $arg, StreamInterface $body): array
    {
        try {
            return self::json($this->send($endpoint, $arg, true, $body));
        } finally {
            $body->close();
        }
    }

    /**
     * @param  array<string, mixed>  $arg
     * @param  resource|null  $sink
     *
     * @throws DropboxException
     */
    private function send(string $endpoint, array $arg, bool $content, ?StreamInterface $body = null, $sink = null): ResponseInterface
    {
        $retries = 0;
        $refreshed = false;
        while (true) {
            $options = ['http_errors' => false, 'headers' => ['Authorization' => 'Bearer '.$this->tokens->token()]];
            if ($content) {
                $options['headers']['Dropbox-API-Arg'] = self::headerJson($arg);
                $options['headers']['Content-Type'] = 'application/octet-stream';
                if ($body !== null) {
                    $body->rewind();
                    $options['body'] = $body;
                }
                if ($sink !== null) {
                    ftruncate($sink, 0);
                    rewind($sink);
                    $options['sink'] = $sink;
                }
            } else {
                $options['json'] = $arg;
            }

            try {
                $response = $this->http->request('POST', ($content ? $this->settings->contentUrl : $this->settings->apiUrl).$endpoint, $options);
            } catch (GuzzleException $e) {
                if ($retries++ < $this->settings->maxRetries) {
                    $this->pause($retries, null);

                    continue;
                }

                throw DropboxException::transport($endpoint, $this->redactor->redact($e->getMessage()));
            }

            $status = $response->getStatusCode();
            if ($status === 200) {
                return $response;
            }
            $summary = $this->errorSummary($response, $sink);
            if ($status === 401 && str_starts_with($summary, 'expired_access_token') && ! $refreshed) {
                $refreshed = true;
                $this->tokens->refresh();

                continue;
            }
            if (($status === 429 || $status >= 500) && $retries++ < $this->settings->maxRetries) {
                $this->pause($retries, $response->getHeaderLine('Retry-After'));

                continue;
            }

            throw DropboxException::response($endpoint, $status, $this->redactor->redact($summary));
        }
    }

    private function pause(int $retry, ?string $retryAfter): void
    {
        $seconds = $retryAfter !== null && ctype_digit($retryAfter) ? (int) $retryAfter : 2 ** ($retry - 1);
        Sleep::sleep(min($seconds, $this->settings->maxRetryAfterSeconds));
    }

    /**
     * @param  resource|null  $sink
     */
    private function errorSummary(ResponseInterface $response, $sink): string
    {
        if ($sink !== null) {
            rewind($sink);
            $text = (string) fread($sink, self::MAX_ERROR_BYTES);
        } else {
            $text = Utils::copyToString($response->getBody(), self::MAX_ERROR_BYTES);
        }
        $data = json_decode($text, true);
        if (is_array($data) && is_string($data['error_summary'] ?? null)) {
            return $data['error_summary'];
        }

        return mb_substr(trim($text), 0, 200);
    }

    /**
     * Up to chunkBytes of $source in a disk-backed buffer.
     *
     * @param  resource  $source
     */
    private function chunk($source): StreamInterface
    {
        $buffer = $this->buffer();
        if (stream_copy_to_stream($source, $buffer, $this->settings->chunkBytes) === false) {
            fclose($buffer);

            throw DropboxException::transport('upload', 'no es pot llegir el flux de dades');
        }

        return Utils::streamFor($buffer);
    }

    /**
     * @return resource
     */
    private function buffer()
    {
        $buffer = fopen('php://temp/maxmemory:'.$this->settings->bufferMemoryBytes, 'w+b');

        return $buffer !== false ? $buffer : throw DropboxException::transport('upload', "no s'ha pogut obrir un fitxer temporal");
    }

    /**
     * Dropbox-API-Arg is an HTTP header: JSON with every non-ASCII character
     * (and DEL) \u-escaped.
     *
     * @param  array<string, mixed>  $arg
     */
    private static function headerJson(array $arg): string
    {
        return str_replace("\x7f", '\u007f', json_encode($arg, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<mixed>
     */
    private static function json(ResponseInterface $response): array
    {
        $data = json_decode((string) $response->getBody(), true);

        return is_array($data) ? $data : [];
    }
}
