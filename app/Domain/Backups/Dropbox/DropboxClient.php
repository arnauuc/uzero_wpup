<?php

declare(strict_types=1);

namespace App\Domain\Backups\Dropbox;

use App\Domain\Secrets\SecretRedactor;
use App\Support\PrivateDirectory;
use Generator;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\TransferException;
use GuzzleHttp\Psr7\LimitStream;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Stream;
use GuzzleHttp\Psr7\Utils;
use JsonException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use RuntimeException;

/**
 * The few Dropbox API v2 calls a backup destination needs. Paths are Dropbox
 * paths inside the App folder ("/a/b", or "" for its root).
 *
 * - Uploads go through an upload session in chunks of chunkBytes: windows
 *   over a seekable source, or copies to a disk-backed buffer otherwise, so
 *   memory stays bounded and a chunk can be resent. A file smaller than one
 *   chunk uses a single files/upload. When a response was lost and Dropbox
 *   already holds more (incorrect_offset), the upload resumes from there.
 * - Buffers (downloads, chunks of non-seekable sources) are unlinked files
 *   of the private staging directory, never /tmp. A cut download resumes
 *   with a Range request from what already arrived.
 * - 429/5xx answers and transport errors are retried (DropboxRetryPolicy);
 *   an expired access token is refreshed once per call. A move or copy that
 *   turns out to have succeeded before a lost response counts as done.
 */
final class DropboxClient
{
    /** @var array<string, string> lowercase path => content_hash of what this client uploaded */
    private array $uploaded = [];

    public function __construct(
        private readonly ClientInterface $http,
        private readonly DropboxTokenProvider $tokens,
        private readonly SecretRedactor $redactor,
        private readonly DropboxSettings $settings,
        private readonly DropboxRetryPolicy $retry,
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

    /**
     * @return array<mixed>|null the metadata of the moved file (null when a lost response was recovered)
     */
    public function move(string $from, string $to): ?array
    {
        $moved = null;
        try {
            $result = $this->rpc('files/move_v2', ['from_path' => $from, 'to_path' => $to, 'autorename' => false]);
            $moved = is_array($result['metadata'] ?? null) ? $result['metadata'] : null;
        } catch (DropboxException $e) {
            // Done before a lost response: the source is gone and the destination
            // holds what this client uploaded at the source.
            $expected = $this->uploaded[mb_strtolower($from)] ?? null;
            if (! ($e->afterRetry && $e->isNotFound() && $expected !== null && $this->metadata($from) === null && $this->holds($to, $expected))) {
                throw $e;
            }
            $this->retry->recovered('files/move_v2', 'already moved');
        }
        if (isset($this->uploaded[mb_strtolower($from)])) {
            $this->uploaded[mb_strtolower($to)] = $this->uploaded[mb_strtolower($from)];
            unset($this->uploaded[mb_strtolower($from)]);
        }

        return $moved;
    }

    public function copy(string $from, string $to): void
    {
        try {
            $this->rpc('files/copy_v2', ['from_path' => $from, 'to_path' => $to, 'autorename' => false]);
        } catch (DropboxException $e) {
            $source = $e->afterRetry && $e->isDestinationConflict() ? $this->metadata($from) : null;
            if ($source === null || ($source['.tag'] ?? null) !== 'file' || ! is_string($source['content_hash'] ?? null) || ! $this->holds($to, $source['content_hash'])) {
                throw $e;
            }
            $this->retry->recovered('files/copy_v2', 'already copied');
        }
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
     * Stores the rest of $stream at $path, replacing what is there. The
     * caller keeps ownership of $stream.
     *
     * @param  resource  $stream
     * @return array<mixed> the metadata of the stored file
     */
    public function upload(string $path, $stream): array
    {
        $meta = $this->store($path, $stream);
        if (is_string($meta['content_hash'] ?? null)) {
            $this->uploaded[mb_strtolower($path)] = $meta['content_hash'];
        }

        return $meta;
    }

    /**
     * @param  resource  $stream
     * @return array<mixed>
     */
    private function store(string $path, $stream): array
    {
        $commit = ['path' => $path, 'mode' => 'overwrite', 'autorename' => false, 'mute' => true];
        $source = new Stream($stream);
        $start = $source->isSeekable() ? $source->tell() : 0;
        $seekable = $source->getSize() !== null && self::seeks($source, $start);
        $offset = 0;
        $session = null;
        $resumes = 0;

        try {
            while (true) {
                // A non-seekable source is read once, in order: $offset is always what was read.
                $chunk = $seekable ? new LimitStream($source, $this->settings->chunkBytes, $start + $offset) : $this->buffered($stream);
                $size = (int) $chunk->getSize();
                $last = $size < $this->settings->chunkBytes;
                $cursor = ['session_id' => $session, 'offset' => $offset];

                try {
                    if ($session === null && $last) {
                        return $this->content('files/upload', $commit, $chunk);
                    }
                    if ($session === null) {
                        $started = $this->content('files/upload_session/start', ['close' => false], $chunk);
                        $session = is_string($started['session_id'] ?? null) ? $started['session_id'] : throw DropboxException::response('files/upload_session/start', 200, 'sense session_id');
                    } elseif ($last) {
                        return $this->finish($path, $offset + $size, ['cursor' => $cursor, 'commit' => $commit], $chunk);
                    } else {
                        $this->content('files/upload_session/append_v2', ['cursor' => $cursor, 'close' => false], $chunk, true);
                    }
                    $offset += $size;
                } catch (DropboxException $e) {
                    $resume = $e->correctOffset;
                    if ($resume === null || $resume < 0 || ++$resumes > $this->settings->maxRetries || (! $seekable && $resume !== $offset + $size)) {
                        throw $e;
                    }
                    $this->retry->recovered('files/upload_session', "resumed at offset {$resume}");
                    $offset = $resume;
                } finally {
                    if (! $seekable) {
                        $chunk->close();
                    }
                }
            }
        } finally {
            // The caller closes its stream.
            $source->detach();
        }
    }

    /**
     * @return resource a disk-backed stream with the file's bytes, at its start
     */
    public function download(string $path)
    {
        $sink = $this->buffer();
        try {
            $this->send('files/download', ['path' => $path], true, null, $sink);
        } catch (DropboxException $e) {
            fclose($sink);

            throw $e;
        }
        rewind($sink);

        return $sink;
    }

    /**
     * finish, when its response may have been lost: the session is then gone,
     * and the file is there with every byte uploaded.
     *
     * @param  array<string, mixed>  $arg
     * @return array<mixed>
     */
    private function finish(string $path, int $total, array $arg, StreamInterface $chunk): array
    {
        try {
            return $this->content('files/upload_session/finish', $arg, $chunk);
        } catch (DropboxException $e) {
            $meta = $e->afterRetry && $e->isSessionGone() ? $this->metadata($path) : null;
            if ($meta === null || ($meta['size'] ?? null) !== $total) {
                throw $e;
            }
            $this->retry->recovered('files/upload_session/finish', 'already committed');

            return $meta;
        }
    }

    /** A file with exactly this Dropbox content_hash is at $path. */
    private function holds(string $path, string $contentHash): bool
    {
        $meta = $this->metadata($path);

        return $meta !== null && ($meta['.tag'] ?? null) === 'file' && ($meta['content_hash'] ?? null) === $contentHash;
    }

    /**
     * @param  array<string, mixed>  $arg
     * @return array<mixed>
     */
    private function rpc(string $endpoint, array $arg): array
    {
        return self::json($endpoint, $this->send($endpoint, $arg, false));
    }

    /**
     * @param  array<string, mixed>  $arg
     * @return array<mixed>
     */
    private function content(string $endpoint, array $arg, StreamInterface $body, bool $nullable = false): array
    {
        return self::json($endpoint, $this->send($endpoint, $arg, true, $body), $nullable);
    }

    /**
     * @param  array<string, mixed>  $arg
     * @param  resource|null  $sink  downloads: what arrived is kept, and a retry asks for the rest (Range)
     *
     * @throws DropboxException
     */
    private function send(string $endpoint, array $arg, bool $content, ?StreamInterface $body = null, $sink = null): ResponseInterface
    {
        try {
            // Non-ASCII is \u-escaped (json_encode's default), as an HTTP header needs; DEL too.
            $json = str_replace("\x7f", '\u007f', json_encode($arg, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        } catch (JsonException) {
            throw DropboxException::unencodable($endpoint);
        }

        $retry = 0;
        $refreshed = false;
        $known = null;
        while (true) {
            $have = 0;
            $options = $this->settings->timeouts($sink !== null) + ['http_errors' => false, 'headers' => ['Authorization' => 'Bearer '.$this->tokens->token()]];
            if ($content) {
                $options['headers'] += ['Dropbox-API-Arg' => $json, 'Content-Type' => 'application/octet-stream'];
                if ($body !== null) {
                    $body->rewind();
                    $options['body'] = $body;
                }
                if ($sink !== null) {
                    $have = (int) (fstat($sink)['size'] ?? 0);
                    if ($have > 0 && $known !== null && $have >= $known) {
                        // Every byte had arrived before the transfer was cut.
                        return new Response(206);
                    }
                    fseek($sink, 0, SEEK_END);
                    if ($have > 0) {
                        $options['headers']['Range'] = "bytes={$have}-";
                    }
                    $options['sink'] = $sink;
                    $options['on_headers'] = static function (ResponseInterface $response) use ($sink, &$have, &$known): void {
                        self::onDownloadHeaders($response, $sink, $have, $known);
                    };
                }
            } else {
                $options['headers']['Content-Type'] = 'application/json';
                $options['body'] = $json;
            }

            try {
                $response = $this->http->request('POST', ($content ? $this->settings->contentUrl : $this->settings->apiUrl).$endpoint, $options);
            } catch (TransferException $e) {
                if ($this->retry->allows(++$retry, $endpoint, $e->getMessage())) {
                    continue;
                }

                throw DropboxException::transport($endpoint, $this->redactor->redact($e->getMessage()));
            }

            $status = $response->getStatusCode();
            if ($status === 200 || ($status === 206 && $have > 0)) {
                return $response;
            }
            [$summary, $correctOffset] = $this->error($response, $sink, $have);
            if ($sink !== null) {
                ftruncate($sink, max(0, $have));
                // Asked from the end: complete if that is the file's size.
                if ($status === 416 && $have > 0 && is_string($arg['path'] ?? null) && ($this->metadata($arg['path'])['size'] ?? null) === $have) {
                    return $response;
                }
            }
            if ($status === 401 && str_starts_with($summary, 'expired_access_token') && ! $refreshed) {
                $refreshed = true;
                $this->tokens->refresh();

                continue;
            }
            if (DropboxRetryPolicy::retryableStatus($status) && $this->retry->allows($retry + 1, $endpoint, "HTTP {$status}", $response->getHeaderLine('Retry-After'))) {
                $retry++;

                continue;
            }

            throw DropboxException::response($endpoint, $status, $this->redactor->redact($summary), $retry > 0, $correctOffset);
        }
    }

    /**
     * @param  resource|null  $sink
     * @return array{0: string, 1: int|null} error_summary and the correct_offset of upload sessions
     */
    private function error(ResponseInterface $response, $sink, int $from): array
    {
        if ($sink !== null) {
            fseek($sink, $from);
            $text = (string) fread($sink, max(1, $this->settings->maxErrorBytes));
        } else {
            $text = Utils::copyToString($response->getBody(), $this->settings->maxErrorBytes);
        }
        $data = json_decode($text, true);
        if (is_array($data) && is_string($data['error_summary'] ?? null)) {
            return [$data['error_summary'], self::correctOffset($data['error'] ?? null)];
        }

        return [mb_substr(trim($text), 0, $this->settings->errorSummaryChars), null];
    }

    private static function correctOffset(mixed $error): ?int
    {
        if (! is_array($error)) {
            return null;
        }
        if (is_int($error['correct_offset'] ?? null)) {
            return $error['correct_offset'];
        }
        foreach ($error as $value) {
            if (($found = self::correctOffset($value)) !== null) {
                return $found;
            }
        }

        return null;
    }

    /** Whether $stream really seeks (some report seekable and are not). */
    private static function seeks(StreamInterface $stream, int $position): bool
    {
        try {
            $stream->seek($position);

            return $stream->isSeekable();
        } catch (RuntimeException) {
            return false;
        }
    }

    /**
     * The next chunkBytes of a non-seekable source, in a disk-backed buffer.
     *
     * @param  resource  $source
     */
    private function buffered($source): StreamInterface
    {
        $buffer = $this->buffer();
        // A short read is not the end: only EOF makes a chunk the last one.
        $copied = 0;
        $idle = 0;
        while ($copied < $this->settings->chunkBytes && ! feof($source)) {
            $data = fread($source, max(1, min($this->settings->readChunkBytes, $this->settings->chunkBytes - $copied)));
            // A read that times out returns nothing: a stuck stream ends here.
            $idle = $data === '' ? $idle + 1 : 0;
            if ($data === false || $idle > $this->settings->maxIdleReads || fwrite($buffer, $data) !== strlen($data)) {
                fclose($buffer);

                throw DropboxException::transport('upload', $data === '' ? 'el flux ha deixat de donar dades' : 'no es pot llegir el flux de dades');
            }
            $copied += strlen($data);
        }

        return Utils::streamFor($buffer);
    }

    /**
     * Before a download body is written: a 200 is the whole file (the Range
     * was ignored, or there was none), so the sink starts again; a 206 must
     * continue exactly where the sink ends, else the sink starts again and
     * the request is retried.
     *
     * @param  resource  $sink
     */
    private static function onDownloadHeaders(ResponseInterface $response, $sink, int &$have, ?int &$known): void
    {
        $status = $response->getStatusCode();
        if ($status === 200) {
            ftruncate($sink, 0);
            rewind($sink);
            $have = 0;
            $length = $response->getHeaderLine('Content-Length');
            $known = ctype_digit($length) ? (int) $length : null;
        } elseif ($status === 206) {
            if (preg_match('#^bytes (\d+)-\d+/(\d+)$#', $response->getHeaderLine('Content-Range'), $range) !== 1 || (int) $range[1] !== $have) {
                ftruncate($sink, 0);
                rewind($sink);
                $have = 0;
                $known = null;

                throw new RuntimeException('The Content-Range does not continue the partial download.');
            }
            $known = (int) $range[2];
        }
    }

    /**
     * @return resource
     */
    private function buffer()
    {
        try {
            $path = PrivateDirectory::ensure($this->settings->stagingDir).'/dropbox-'.bin2hex(random_bytes(16));
        } catch (RuntimeException $e) {
            throw new DropboxException("No s'ha pogut preparar el fitxer temporal de Dropbox: {$e->getMessage()}");
        }
        $buffer = @fopen($path, 'x+b');
        if ($buffer === false) {
            throw new DropboxException("No s'ha pogut crear el fitxer temporal de Dropbox a {$this->settings->stagingDir}.");
        }
        chmod($path, 0600);
        // Unlinked at once: it disappears when closed, whatever happens.
        unlink($path);

        return $buffer;
    }

    /**
     * @return array<mixed>
     */
    private static function json(string $endpoint, ResponseInterface $response, bool $nullable = false): array
    {
        $text = (string) $response->getBody();
        $data = json_decode($text, true);
        if (is_array($data)) {
            return $data;
        }
        if ($nullable && trim($text) === 'null') {
            return [];
        }

        // The body is not quoted: it could be anything.
        throw DropboxException::response($endpoint, $response->getStatusCode(), 'la resposta no és un objecte JSON');
    }
}
