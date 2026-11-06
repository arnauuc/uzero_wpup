<?php

declare(strict_types=1);

use App\Domain\Secrets\SecretRedactor;
use App\Domain\Updates\PhpLogScanner;
use App\Domain\Updates\SmokeCheckSettings;

function scanner(): PhpLogScanner
{
    $settings = new SmokeCheckSettings(60, 10, 3, true, 1024, 5, 300, app(SmokeCheckSettings::class)->fatalPatterns);

    return new PhpLogScanner(new SecretRedactor, $settings);
}

it('recognises the fatal formats of the PHP log', function (string $line): void {
    expect(scanner()->isFatal($line))->toBeTrue();
})->with([
    'log file' => '[06-Nov-2026 10:00:00 UTC] PHP Fatal error:  Uncaught Error: Call to undefined function foo() in /a/b.php:3',
    'parse error' => '[06-Nov-2026 10:00:00 UTC] PHP Parse error:  syntax error, unexpected token "}" in /a/b.php on line 9',
    'display format' => 'Fatal error: Allowed memory size of 134217728 bytes exhausted in /a/b.php on line 4',
    'uncaught only' => 'PHP message: Uncaught TypeError: foo(): Argument #1 must be of type int',
    'namespaced exception' => 'Uncaught Automattic\WooCommerce\Exception: boom',
    'lowercase' => 'php fatal error:  something',
]);

it('ignores warnings, notices and deprecations', function (string $line): void {
    expect(scanner()->isFatal($line))->toBeFalse();
})->with([
    'warning' => 'PHP Warning:  Undefined variable $x in /a/b.php on line 2',
    'notice' => 'PHP Notice:  Undefined index: a in /a/b.php on line 2',
    'deprecated' => 'PHP Deprecated:  Return type of Foo::bar() should be compatible in /a/b.php on line 2',
    'plain text' => 'The fatal attitude of a plugin called Parse errors',
]);

it('counts every fatal but reports only the first ones, redacted and truncated', function (): void {
    $redactor = new SecretRedactor;
    $redactor->register('s3cr3t-token-value');
    $settings = new SmokeCheckSettings(60, 10, 3, true, 1024, 2, 60, app(SmokeCheckSettings::class)->fatalPatterns);
    $scanner = new PhpLogScanner($redactor, $settings);

    $result = $scanner->fatals([
        'PHP Warning:  nothing',
        'PHP Fatal error:  one with s3cr3t-token-value in it and then a very long tail of text that goes on',
        'PHP Fatal error:  two',
        'PHP Parse error:  three',
    ]);

    expect($result['count'])->toBe(3)
        ->and($result['lines'])->toHaveCount(2)
        ->and(implode('', $result['lines']))->not->toContain('s3cr3t-token-value')
        ->and(mb_strlen($result['lines'][0]))->toBeLessThanOrEqual(60);
});

it('finds nothing in an empty or clean log', function (): void {
    expect(scanner()->fatals([]))->toBe(['count' => 0, 'lines' => []])
        ->and(scanner()->fatals(['PHP Notice:  x'])['count'])->toBe(0);
});
