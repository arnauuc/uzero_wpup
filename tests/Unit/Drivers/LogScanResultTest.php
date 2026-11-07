<?php

declare(strict_types=1);

use App\Domain\Drivers\Exceptions\RemoteCommandFailed;
use App\Domain\Drivers\Ssh\LogScanResult;

const SCAN_OUT = "F:PHP Fatal error:  a\nF:PHP Parse error:  b\nW:PHP Warning:  w\n#F:2\n#W:1\n#N:4\n";

it('reads the fatals, the warnings and the notice count when tail and awk ended well', function (string $tail): void {
    $scan = LogScanResult::parse(SCAN_OUT, "wpup-tail={$tail}\nwpup-awk=0\n");

    expect($scan->lines)->toBe(['PHP Fatal error:  a', 'PHP Parse error:  b'])
        ->and($scan->warnings)->toBe(['PHP Warning:  w'])
        ->and($scan->notices)->toBe(4)
        ->and($scan->capped)->toBeFalse()
        ->and($scan->warningsCapped)->toBeFalse();
})->with(['0', '141']);

it('reports which of the two kinds was cut by its cap', function (): void {
    $scan = LogScanResult::parse("F:x\n#F:5\n#W:0\n#N:0\n", "wpup-tail=0\nwpup-awk=0\n");

    expect($scan->capped)->toBeTrue()->and($scan->warningsCapped)->toBeFalse();
});

it('never turns a failed tail or awk into an empty log', function (string $stdout, string $stderr): void {
    expect(fn () => LogScanResult::parse($stdout, $stderr))->toThrow(RemoteCommandFailed::class, 'La lectura del log de PHP ha fallat');
})->with([
    'unreadable log' => ['', "tail: cannot open 'x' for reading: Permission denied\nwpup-tail=1\nwpup-awk=0\n"],
    'awk error' => ['', "wpup-tail=0\nwpup-awk=2\n"],
    'no markers at all' => ['', ''],
    'tail marker missing' => ['', "wpup-awk=0\n"],
    'totals missing (awk died halfway)' => ["F:a\n", "wpup-tail=0\nwpup-awk=0\n"],
]);

it('builds a command that escapes its arguments, keeps both statuses and runs awk once', function (): void {
    $command = LogScanResult::command('tail -c +1 '.escapeshellarg("/x/it's.log"), 5, 7, 100, 1000);

    expect($command)->toContain("'/x/it'\\''s.log'")
        ->toContain('echo "wpup-tail=$?" >&2')
        ->toContain('echo "wpup-awk=$?" >&2')
        ->toContain('head -c 1000')
        ->toContain('-v mf=5 -v mw=7 -v lb=100 -v newest=0')
        ->and(substr_count($command, 'awk -v'))->toBe(1);
});

it('asks for the newest matches when told to', function (): void {
    expect(LogScanResult::command('tail -c +1 /x', 3, 3, 100, null, true))->toContain('-v newest=1');
});
