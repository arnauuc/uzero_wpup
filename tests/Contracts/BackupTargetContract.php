<?php

declare(strict_types=1);

namespace Tests\Contracts;

use App\Domain\Backups\BackupTarget;
use App\Domain\Backups\BackupTargetException;
use Closure;

/**
 * Behaviour every BackupTarget must have (UZEROWPUP-37). Usage from a test file:
 *
 *     BackupTargetContract::register(fn (): BackupTarget => new SomeTarget(...), 'some target');
 *
 * The factory must return an empty destination each time (a fresh directory,
 * bucket prefix or container).
 */
final class BackupTargetContract
{
    /** Big enough to show that nothing is held in memory whole. */
    public const int LARGE_BYTES = 24 * 1024 * 1024;

    /**
     * @param  Closure(): BackupTarget  $factory
     */
    public static function register(Closure $factory, string $label): void
    {
        describe("BackupTarget contract: {$label}", function () use ($factory): void {
            it('stores a stream and gives the same bytes back', function () use ($factory): void {
                $target = $factory();
                $stored = $target->put('site-1/run-7/db.sql.gz', BackupTargetContract::stream('dump contents'));

                $read = $target->readStream('site-1/run-7/db.sql.gz');
                $contents = stream_get_contents($read);
                fclose($read);

                expect($stored->key)->toBe('site-1/run-7/db.sql.gz')
                    ->and($stored->sizeBytes)->toBe(13)
                    ->and($contents)->toBe('dump contents')
                    ->and($target->exists('site-1/run-7/db.sql.gz'))->toBeTrue()
                    ->and($target->exists('site-1/run-7/other.gz'))->toBeFalse();
            });

            it('reports the SHA-256 of what it holds', function () use ($factory): void {
                $target = $factory();
                $target->put('a/b.tar.gz', BackupTargetContract::stream('archive bytes'));

                expect($target->checksum('a/b.tar.gz'))->toBe(hash('sha256', 'archive bytes'));
            });

            it('streams a large archive in and out with bounded memory', function () use ($factory): void {
                $target = $factory();
                [$source, $sha] = BackupTargetContract::largeStream(BackupTargetContract::LARGE_BYTES);

                memory_reset_peak_usage();
                $before = memory_get_usage();
                $stored = $target->put('big/files.tar.gz', $source);
                $checksum = $target->checksum('big/files.tar.gz');
                $read = $target->readStream('big/files.tar.gz');
                $hash = hash_init('sha256');
                while (! feof($read)) {
                    hash_update($hash, (string) fread($read, 1_048_576));
                }
                fclose($read);
                $peak = memory_get_peak_usage() - $before;

                expect($stored->sizeBytes)->toBe(BackupTargetContract::LARGE_BYTES)
                    ->and($checksum)->toBe($sha)
                    ->and(hash_final($hash))->toBe($sha)
                    ->and($peak)->toBeLessThan(BackupTargetContract::LARGE_BYTES / 3);
            });

            it('never overwrites a stored object', function () use ($factory): void {
                $target = $factory();
                $target->put('x/one.gz', BackupTargetContract::stream('first'));

                expect(fn () => $target->put('x/one.gz', BackupTargetContract::stream('second')))->toThrow(BackupTargetException::class, 'ja existeix')
                    ->and($target->checksum('x/one.gz'))->toBe(hash('sha256', 'first'));
            });

            it('refuses keys outside its area', function (string $key) use ($factory): void {
                $target = $factory();

                expect(fn () => $target->put($key, BackupTargetContract::stream('x')))->toThrow(BackupTargetException::class, 'no vàlida')
                    ->and(fn () => $target->readStream($key))->toThrow(BackupTargetException::class, 'no vàlida')
                    ->and(fn () => $target->delete($key))->toThrow(BackupTargetException::class, 'no vàlida');
            })->with(['../escape.gz', '/etc/passwd', 'a/../../b', 'a//b', '', '.hidden', "a\0b", 'a/b/', "a/b.gz\n"]);

            it('lists by prefix, ordered by key, without partial uploads', function () use ($factory): void {
                $target = $factory();
                $target->put('site-2/r1/files.tar.gz', BackupTargetContract::stream('f'));
                $target->put('site-2/r1/db.sql.gz', BackupTargetContract::stream('dd'));
                $target->put('site-3/r1/db.sql.gz', BackupTargetContract::stream('ddd'));

                $keys = array_map(static fn ($o): string => $o->key, $target->list('site-2/'));

                $target->put('site-20/r1/db.sql.gz', BackupTargetContract::stream('other site'));

                expect($keys)->toBe(['site-2/r1/db.sql.gz', 'site-2/r1/files.tar.gz'])
                    ->and(count($target->list('site-2/')))->toBe(2)
                    ->and(count($target->list()))->toBe(4)
                    ->and($target->list('site-9/'))->toBe([])
                    ->and(fn () => $target->list('site-2'))->toThrow(BackupTargetException::class)
                    ->and(fn () => $target->list('site-2//'))->toThrow(BackupTargetException::class);
            });

            it('treats keys that look like temporary names as ordinary objects', function () use ($factory): void {
                $target = $factory();
                $target->put('x/y.gz.part', BackupTargetContract::stream('a real object'));
                $target->put('x/y.gz', BackupTargetContract::stream('another'));

                expect($target->checksum('x/y.gz.part'))->toBe(hash('sha256', 'a real object'))
                    ->and(array_map(static fn ($o): string => $o->key, $target->list('x/')))->toBe(['x/y.gz', 'x/y.gz.part']);
            });

            it('deletes idempotently and fails clearly on missing objects', function () use ($factory): void {
                $target = $factory();
                $target->put('d/x.gz', BackupTargetContract::stream('x'));
                $target->delete('d/x.gz');
                $target->delete('d/x.gz');

                expect($target->exists('d/x.gz'))->toBeFalse()
                    ->and(fn () => $target->readStream('d/x.gz'))->toThrow(BackupTargetException::class, 'no existeix')
                    ->and(fn () => $target->checksum('d/x.gz'))->toThrow(BackupTargetException::class, 'no existeix');
            });
        });
    }

    /**
     * @return resource
     */
    public static function stream(string $contents)
    {
        $stream = fopen('php://temp', 'r+b');
        assert(is_resource($stream));
        fwrite($stream, $contents);
        rewind($stream);

        return $stream;
    }

    /**
     * A stream of $bytes pseudo-random bytes backed by a temporary file, and its SHA-256.
     *
     * @return array{0: resource, 1: string}
     */
    public static function largeStream(int $bytes): array
    {
        $stream = fopen('php://temp/maxmemory:1048576', 'r+b');
        assert(is_resource($stream));
        $hash = hash_init('sha256');
        $block = random_bytes(1_048_576);
        for ($written = 0; $written < $bytes; $written += strlen($block)) {
            fwrite($stream, $block);
            hash_update($hash, $block);
        }
        rewind($stream);

        return [$stream, hash_final($hash)];
    }
}
