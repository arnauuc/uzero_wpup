<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Backups\Dropbox\DropboxCredential;
use App\Domain\Backups\Dropbox\DropboxSettings;
use App\Domain\Secrets\Secret;
use GuzzleHttp\Exception\TransferException;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\LimitStream;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Utils;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Throwable;

/**
 * An in-process Dropbox API v2 for tests, used as a Guzzle handler: the
 * endpoints the tool calls, with bytes stored on disk (never whole in memory),
 * Dropbox-shaped errors, bearer tokens that can be expired, and failures that
 * can be queued per endpoint. Nothing leaves the process.
 */
final class FakeDropboxApi
{
    public const string BASE = 'https://dropbox.test/';

    public string $appKey = 'fake-app-key';

    public string $appSecret;

    public string $refreshToken;

    /** @var list<string> endpoints called, in order */
    public array $calls = [];

    /** @var list<string> Dropbox-API-Arg headers received */
    public array $headerArgs = [];

    /** @var list<int> body sizes of the upload-session calls */
    public array $chunkSizes = [];

    public int $pageSize = 2;

    public ?string $lastToken = null;

    /** @var list<string> Range headers received */
    public array $ranges = [];

    /** Answer Range requests with the whole file (200), as a server may. */
    public bool $ignoreRange = false;

    /** Answer Range requests with a 206 that starts at 0, as a broken proxy may. */
    public bool $wrongRangeStart = false;

    /** Leave out Content-Length, so the client cannot know the size. */
    public bool $omitLength = false;

    /** @var array<string, list<int>> endpoint => bytes sent before each next transfer is cut */
    private array $cut = [];

    /** @var array<string, bool> token => still valid */
    private array $tokens = [];

    /** @var array<string, list<ResponseInterface|Throwable>> */
    private array $failures = [];

    /** @var array<string, int> endpoint => calls to process and then answer 500 (a lost response) */
    private array $lose = [];

    private readonly string $files;

    public function __construct(public readonly string $dir)
    {
        $this->files = $dir.'/files';
        mkdir($this->files, 0700, true);
        mkdir($dir.'/sessions', 0700);
        $this->appSecret = 'fake-app-secret-'.bin2hex(random_bytes(8));
        $this->refreshToken = 'fake-refresh-'.bin2hex(random_bytes(8));
    }

    public static function inTemp(): self
    {
        return new self(sys_get_temp_dir().'/wpup-dropbox-'.bin2hex(random_bytes(6)));
    }

    public static function settings(int $chunkBytes = DropboxSettings::CHUNK_UNIT_BYTES, int $maxRetries = 2, int $maxRetryAfter = 0): DropboxSettings
    {
        return new DropboxSettings(self::BASE.'2/', self::BASE.'content/2/', self::BASE.'oauth2/token', $chunkBytes, $maxRetries, $maxRetryAfter, 1_048_576, 1, 5, 65_536, 200, 65_536, 5, self::stagingDir(), 50);
    }

    /** Where the client keeps its buffers in tests. */
    public static function stagingDir(): string
    {
        return storage_path('app/testing-dropbox-staging');
    }

    public function credential(): DropboxCredential
    {
        return new DropboxCredential($this->appKey, new Secret($this->appSecret), new Secret($this->refreshToken));
    }

    public function expireTokens(): void
    {
        $this->tokens = array_map(static fn (): bool => false, $this->tokens);
    }

    public function issuedTokens(): int
    {
        return count($this->tokens);
    }

    /** The next call to $endpoint gets $failure instead of being processed. */
    public function failNext(string $endpoint, ResponseInterface|Throwable $failure): void
    {
        $this->failures[$endpoint][] = $failure;
    }

    /** The next response of $endpoint is cut off after $bytes bytes reach the sink. */
    public function cutNext(string $endpoint, int $bytes): void
    {
        $this->cut[$endpoint][] = $bytes;
    }

    /** The next call to $endpoint is processed, but its response is lost (500). */
    public function loseNext(string $endpoint): void
    {
        $this->lose[$endpoint] = ($this->lose[$endpoint] ?? 0) + 1;
    }

    /**
     * @param  array<mixed>  $options
     */
    public function __invoke(RequestInterface $request, array $options): PromiseInterface
    {
        $path = $request->getUri()->getPath();
        $endpoint = (string) preg_replace('#^/((content/)?2/)?#', '', $path);
        $this->calls[] = $endpoint;
        if (($this->failures[$endpoint] ?? []) !== []) {
            $failure = array_shift($this->failures[$endpoint]);
            if ($failure instanceof Throwable) {
                return Create::rejectionFor($failure);
            }
            $response = $failure;
        } else {
            $response = $endpoint === 'oauth2/token' ? $this->token($request) : $this->api($endpoint, $request);
        }
        if (($this->lose[$endpoint] ?? 0) > 0) {
            $this->lose[$endpoint]--;
            $response = self::json(500, ['error_summary' => 'internal_error/']);
        }
        $sink = $options['sink'] ?? null;
        if (is_resource($sink)) {
            if (is_callable($options['on_headers'] ?? null)) {
                try {
                    ($options['on_headers'])($response);
                } catch (Throwable $e) {
                    return Create::rejectionFor(new TransferException('An error was encountered during the on_headers event', $request, 0, $e));
                }
            }
            $body = $response->getBody();
            $limit = ($this->cut[$endpoint] ?? []) !== [] ? array_shift($this->cut[$endpoint]) : null;
            for ($sent = 0; ! $body->eof() && ($limit === null || $sent < $limit); $sent += strlen($data)) {
                $data = $body->read($limit === null ? 1_048_576 : min(1_048_576, $limit - $sent));
                fwrite($sink, $data);
            }
            if ($limit !== null) {
                return Create::rejectionFor(new TransferException('Connection reset by peer', $request));
            }

            return Create::promiseFor($response->withBody(Utils::streamFor('')));
        }

        return Create::promiseFor($response);
    }

    private function token(RequestInterface $request): ResponseInterface
    {
        parse_str((string) $request->getBody(), $form);
        if (($form['grant_type'] ?? null) !== 'refresh_token' || ($form['refresh_token'] ?? null) !== $this->refreshToken
            || ($form['client_id'] ?? null) !== $this->appKey || ($form['client_secret'] ?? null) !== $this->appSecret) {
            return self::json(400, ['error' => 'invalid_grant', 'error_description' => 'refresh token is malformed']);
        }
        $token = 'sl.fake-'.bin2hex(random_bytes(16));
        $this->tokens[$token] = true;
        $this->lastToken = $token;

        return self::json(200, ['access_token' => $token, 'token_type' => 'bearer', 'expires_in' => 14_400]);
    }

    private function api(string $endpoint, RequestInterface $request): ResponseInterface
    {
        $token = substr($request->getHeaderLine('Authorization'), strlen('Bearer '));
        if (($this->tokens[$token] ?? null) !== true) {
            $tag = isset($this->tokens[$token]) ? 'expired_access_token' : 'invalid_access_token';

            return self::json(401, ['error_summary' => $tag.'/', 'error' => ['.tag' => $tag]]);
        }

        $header = $request->getHeaderLine('Dropbox-API-Arg');
        if ($header !== '') {
            $this->headerArgs[] = $header;
            if (preg_match('/[^\x20-\x7e]/', $header) === 1) {
                return self::json(400, ['error_summary' => 'non-ascii header']);
            }
        }
        $arg = json_decode($header !== '' ? $header : (string) $request->getBody(), true);
        $arg = is_array($arg) ? $arg : [];
        $p = static fn (string $key): string => is_string($arg[$key] ?? null) ? $arg[$key] : '';

        return match ($endpoint) {
            'files/upload' => $this->store($request, $this->local($p('path'))),
            'files/upload_session/start' => $this->startSession($request),
            'files/upload_session/append_v2' => $this->append($request, $arg),
            'files/upload_session/finish' => $this->finish($request, $arg),
            'files/download' => is_file($this->local($p('path'))) ? $this->download($this->local($p('path')), $request->getHeaderLine('Range')) : self::error('path/not_found/'),
            'files/get_metadata' => file_exists($this->local($p('path'))) ? self::json(200, $this->meta($this->local($p('path')))) : self::error('path/not_found/'),
            'files/list_folder' => is_dir($this->local($p('path'))) ? $this->page($p('path'), ($arg['recursive'] ?? false) === true, 0) : self::error('path/not_found/'),
            'files/list_folder/continue' => $this->continue($p('cursor')),
            'files/move_v2', 'files/copy_v2' => $this->relocate($endpoint === 'files/move_v2', $this->local($p('from_path')), $this->local($p('to_path'))),
            'files/delete_v2' => $this->remove($this->local($p('path'))),
            'files/create_folder_v2' => file_exists($this->local($p('path'))) ? self::error(is_dir($this->local($p('path'))) ? 'path/conflict/folder/' : 'path/conflict/file/')
                : (mkdir($this->local($p('path')), 0700, true) ? self::json(200, ['metadata' => $this->meta($this->local($p('path')))]) : self::error('other')),
            default => self::json(400, ['error_summary' => "unknown endpoint {$endpoint}"]),
        };
    }

    private function download(string $file, string $range): ResponseInterface
    {
        clearstatcache();
        $size = (int) filesize($file);
        $body = Utils::streamFor(Utils::tryFopen($file, 'rb'));
        $length = fn (int $bytes): array => $this->omitLength ? [] : ['Content-Length' => (string) $bytes];
        if ($range === '' || $this->ignoreRange) {
            return new Response(200, $length($size), $body);
        }
        $this->ranges[] = $range;
        $from = preg_match('/^bytes=(\d+)-$/', $range, $m) === 1 && ! $this->wrongRangeStart ? (int) $m[1] : 0;
        if ($from >= $size) {
            return self::json(416, ['error_summary' => 'range_not_satisfiable/']);
        }

        return new Response(206, $length($size - $from) + ['Content-Range' => "bytes {$from}-".($size - 1)."/{$size}"], new LimitStream($body, -1, $from));
    }

    private function store(RequestInterface $request, string $file): ResponseInterface
    {
        is_dir(dirname($file)) || mkdir(dirname($file), 0700, true);
        $this->write($request, $file, 'wb');

        return self::json(200, $this->meta($file));
    }

    private function startSession(RequestInterface $request): ResponseInterface
    {
        $id = bin2hex(random_bytes(8));
        $this->chunkSizes[] = $this->write($request, $this->dir.'/sessions/'.$id, 'wb');

        return self::json(200, ['session_id' => $id]);
    }

    /**
     * @param  array<mixed>  $arg
     */
    private function append(RequestInterface $request, array $arg): ResponseInterface
    {
        $session = $this->session($arg);
        if (is_string($session)) {
            $this->chunkSizes[] = $this->write($request, $session, 'ab');

            return self::json(200, null);
        }

        return $session;
    }

    /**
     * @param  array<mixed>  $arg
     */
    private function finish(RequestInterface $request, array $arg): ResponseInterface
    {
        $session = $this->session($arg);
        $commit = is_array($arg['commit'] ?? null) && is_string($arg['commit']['path'] ?? null) ? $arg['commit']['path'] : '';
        if (! is_string($session)) {
            return $session;
        }
        $this->chunkSizes[] = $this->write($request, $session, 'ab');
        $file = $this->local($commit);
        is_dir(dirname($file)) || mkdir(dirname($file), 0700, true);
        rename($session, $file);

        return self::json(200, $this->meta($file));
    }

    /**
     * @param  array<mixed>  $arg
     */
    private function session(array $arg): string|ResponseInterface
    {
        $cursor = is_array($arg['cursor'] ?? null) ? $arg['cursor'] : [];
        $file = $this->dir.'/sessions/'.(is_string($cursor['session_id'] ?? null) ? basename($cursor['session_id']) : 'none');
        if (! is_file($file)) {
            return self::error('lookup_failed/not_found/');
        }
        clearstatcache();

        $size = filesize($file);

        return ($cursor['offset'] ?? null) === $size ? $file
            : self::json(409, ['error_summary' => 'lookup_failed/incorrect_offset/', 'error' => ['.tag' => 'lookup_failed', 'lookup_failed' => ['.tag' => 'incorrect_offset', 'correct_offset' => $size]]]);
    }

    private function page(string $path, bool $recursive, int $offset): ResponseInterface
    {
        $root = $this->local($path);
        $items = $recursive
            ? iterator_to_array(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST), false)
            : iterator_to_array(new \FilesystemIterator($root), false);
        $paths = array_map(static fn (mixed $f): string => $f instanceof SplFileInfo ? $f->getPathname() : '', $items);
        sort($paths);
        $entries = array_map(fn (string $f): array => $this->meta($f), $paths);
        $more = $offset + $this->pageSize < count($entries);
        $cursor = base64_encode((string) json_encode([$path, $recursive, $offset + $this->pageSize]));

        return self::json(200, ['entries' => array_slice($entries, $offset, $this->pageSize), 'has_more' => $more, 'cursor' => $cursor]);
    }

    private function continue(string $cursor): ResponseInterface
    {
        $state = json_decode((string) base64_decode($cursor, true), true);
        if (! is_array($state) || ! is_string($state[0] ?? null) || ! is_int($state[2] ?? null)) {
            return self::error('reset/');
        }

        return $this->page($state[0], $state[1] === true, $state[2]);
    }

    private function relocate(bool $move, string $from, string $to): ResponseInterface
    {
        if (! file_exists($from)) {
            return self::error('from_lookup/not_found/');
        }
        if (file_exists($to)) {
            return self::error('to/conflict/file/');
        }
        is_dir(dirname($to)) || mkdir(dirname($to), 0700, true);
        $move ? rename($from, $to) : copy($from, $to);

        return self::json(200, ['metadata' => $this->meta($to)]);
    }

    private function remove(string $file): ResponseInterface
    {
        if (! file_exists($file)) {
            return self::error('path_lookup/not_found/');
        }
        $meta = $this->meta($file);
        if (is_dir($file)) {
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($file, RecursiveDirectoryIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $item) {
                assert($item instanceof SplFileInfo);
                $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
            }
            rmdir($file);
        } else {
            unlink($file);
        }

        return self::json(200, ['metadata' => $meta]);
    }

    /** Streams the request body to $file; returns the bytes written. */
    private function write(RequestInterface $request, string $file, string $mode): int
    {
        $out = fopen($file, $mode);
        assert(is_resource($out));
        $body = $request->getBody();
        $written = 0;
        while (! $body->eof()) {
            $written += (int) fwrite($out, $body->read(1_048_576));
        }
        fclose($out);

        return $written;
    }

    private function local(string $path): string
    {
        if (str_contains($path, '..') || ($path !== '' && ! str_starts_with($path, '/'))) {
            throw new \InvalidArgumentException("Bad Dropbox path {$path}");
        }

        return rtrim($this->files.$path, '/');
    }

    /**
     * @return array<string, mixed>
     */
    private function meta(string $file): array
    {
        clearstatcache();
        $path = substr($file, strlen($this->files));
        $meta = ['.tag' => is_dir($file) ? 'folder' : 'file', 'name' => basename($file), 'path_display' => $path, 'path_lower' => strtolower($path), 'id' => 'id:'.md5($path)];

        return is_dir($file) ? $meta : $meta + ['size' => filesize($file), 'server_modified' => gmdate('Y-m-d\TH:i:s\Z', (int) filemtime($file)), 'content_hash' => self::contentHash($file)];
    }

    /** Dropbox's content_hash: SHA-256 of the SHA-256 of each 4 MiB block. */
    public static function contentHash(string $file): string
    {
        $in = fopen($file, 'rb');
        assert(is_resource($in));
        $blocks = '';
        while (($block = fread($in, DropboxSettings::CHUNK_UNIT_BYTES)) !== false && $block !== '') {
            $blocks .= hash('sha256', $block, true);
        }
        fclose($in);

        return hash('sha256', $blocks);
    }

    private static function error(string $summary): ResponseInterface
    {
        return self::json(409, ['error_summary' => $summary, 'error' => ['.tag' => explode('/', $summary)[0]]]);
    }

    private static function json(int $status, mixed $data): ResponseInterface
    {
        return new Response($status, ['Content-Type' => 'application/json'], (string) json_encode($data));
    }
}
