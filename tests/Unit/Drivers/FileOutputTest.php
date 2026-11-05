<?php

declare(strict_types=1);

use App\Domain\Drivers\Exceptions\RemoteCommandFailed;
use App\Domain\Drivers\Ssh\FileOutput;

/*
 * Parsers of the SSH driver's file commands (UZEROWPUP-40).
 */

it('parses NUL-separated listings, never splitting on newlines', function (): void {
    $out = "a/one.gz\x003\x001700000000.5\x00"
        ."evil\nfake.gz\t1\t1\x009\x001700000000.0\x00"
        .".hidden\x001\x001700000000\x00"
        ."b/two.gz\x0010\x001700000001.25\x00";

    $files = FileOutput::listing('store', $out);

    expect(array_map(static fn ($f): array => [$f->path, $f->sizeBytes, $f->modifiedAt], $files))
        ->toBe([['store/a/one.gz', 3, 1700000000], ['store/b/two.gz', 10, 1700000001]])
        ->and(FileOutput::listing('', "x.gz\x001\x001\x00")[0]->path)->toBe('x.gz')
        ->and(FileOutput::listing('store', ''))->toBe([])
        ->and(FileOutput::listing('store', "truncated\x001"))->toBe([]);
});

it('parses a stat with and without checksum, and a missing file', function (): void {
    $sha = str_repeat('ab', 32);

    expect(FileOutput::stat("12 1700000000\n{$sha}  /x\n", true, 'x'))->toBe([12, 1700000000, $sha])
        ->and(FileOutput::stat("12 1700000000\n", false, 'x'))->toBe([12, 1700000000, null])
        ->and(FileOutput::stat(FileOutput::MISSING."\n", true, 'x'))->toBeNull()
        ->and(fn () => FileOutput::stat("12 1700000000\n", true, 'x'))->toThrow(RemoteCommandFailed::class)
        ->and(fn () => FileOutput::stat('garbage', false, 'x'))->toThrow(RemoteCommandFailed::class);
});
