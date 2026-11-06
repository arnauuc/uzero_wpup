<?php

declare(strict_types=1);

use App\Domain\Drivers\Exceptions\RemoteCommandFailed;
use App\Domain\Drivers\Ssh\LogGrepResult;

it('reads the candidate lines when tail and grep ended well', function (string $tail, string $grep): void {
    $result = LogGrepResult::parse("PHP Fatal error:  a\n\nPHP Parse error:  b\n", "wpup-tail={$tail}\nwpup-grep={$grep}\n");

    expect($result->lines)->toBe(['PHP Fatal error:  a', 'PHP Parse error:  b']);
})->with([
    'matches' => ['0', '0'],
    'no match' => ['0', '1'],
    'tail stopped by SIGPIPE after the cap' => ['141', '0'],
]);

it('never turns a failed tail or grep into "no fatal lines"', function (string $stdout, string $stderr): void {
    expect(fn () => LogGrepResult::parse($stdout, $stderr))->toThrow(RemoteCommandFailed::class, 'La lectura del log de PHP ha fallat');
})->with([
    'unreadable log' => ['', "tail: cannot open 'x' for reading: Permission denied\nwpup-tail=1\nwpup-grep=1\n"],
    'grep error' => ['', "wpup-tail=0\nwpup-grep=2\n"],
    'no markers at all' => ['', ''],
    'tail marker missing' => ['', "wpup-grep=1\n"],
]);

it('builds a command that escapes its arguments and keeps both statuses', function (): void {
    $command = LogGrepResult::command('tail -c +1 '.escapeshellarg("/x/it's.log"), 'PHP (Fatal error|Parse error):', 5, 100, 1000);

    expect($command)->toContain("'/x/it'\\''s.log'")
        ->toContain('echo "wpup-tail=$?" >&2')
        ->toContain('echo "wpup-grep=$?" >&2')
        ->toContain('head -c 1000')
        ->toContain('grep -a -E -m 5');
});
