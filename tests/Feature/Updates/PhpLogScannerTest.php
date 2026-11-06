<?php

declare(strict_types=1);

use App\Domain\Secrets\SecretRedactor;
use App\Domain\Updates\PhpLogScanner;
use App\Domain\Updates\SmokeCheckSettings;

/**
 * @param  list<string>|null  $patterns
 */
function smokeSettings(?array $patterns = null, int $reportedLines = 5, int $lineMaxChars = 300): SmokeCheckSettings
{
    return new SmokeCheckSettings(2700, 30, 5, true, 200, 1024, $reportedLines, $lineMaxChars, $patterns ?? app(SmokeCheckSettings::class)->fatalPatterns);
}

function scanner(): PhpLogScanner
{
    return new PhpLogScanner(new SecretRedactor, smokeSettings());
}

it('recognises the fatal formats of the PHP log', function (string $line): void {
    expect(scanner()->isFatal($line))->toBeTrue();
})->with([
    'log file' => '[06-Nov-2026 10:00:00 UTC] PHP Fatal error:  Uncaught Error: Call to undefined function foo() in /a/b.php:3',
    'parse error' => '[06-Nov-2026 10:00:00 UTC] PHP Parse error:  syntax error, unexpected token "}" in /a/b.php on line 9',
    'apache' => '[Fri Nov 06 10:00:00.123456 2026] [php:error] [pid 12] [client 1.2.3.4:5] PHP Fatal error:  Allowed memory size exhausted in /a/b.php on line 4',
    'fastcgi stderr' => "[Fri Nov 06 10:00:00 2026] [proxy_fcgi:error] [pid 1] AH01071: Got error 'PHP message: PHP Fatal error:  Uncaught TypeError: x in /a.php:1'",
    'no timestamp' => 'PHP Fatal error:  Uncaught Exception: boom in /a.php:2',
    'uncaught is a fatal line' => '[06-Nov-2026 10:00:00 UTC] PHP Fatal error:  Uncaught Automattic\WooCommerce\Exception: boom',
]);

it('ignores warnings and application messages that only mention a fatal error', function (string $line): void {
    expect(scanner()->isFatal($line))->toBeFalse();
})->with([
    'warning' => '[06-Nov-2026 10:00:00 UTC] PHP Warning:  Undefined variable $x in /a/b.php on line 2',
    'notice' => '[06-Nov-2026 10:00:00 UTC] PHP Notice:  Undefined index: a in /a/b.php on line 2',
    'deprecated' => '[06-Nov-2026 10:00:00 UTC] PHP Deprecated:  Return type of Foo::bar() should be compatible in /a/b.php on line 2',
    'stripe' => '[06-Nov-2026 10:00:00 UTC] Stripe: Fatal error: could not capture the payment, card declined',
    'plugin message' => '[06-Nov-2026 10:00:00 UTC] WooCommerce Stripe Gateway: PHP Fatal error: reported by the API (not a PHP error)',
    'uncaught in a warning' => '[06-Nov-2026 10:00:00 UTC] PHP Warning:  Uncaught exception handler returned',
    'plain text' => 'The fatal attitude of a plugin called Parse errors',
    'trace line' => '#0 /var/www/wp-content/plugins/x/x.php(3): PHP Fatal error: nothing',
]);

it('counts every new fatal but reports only the first ones, redacted and truncated', function (): void {
    $redactor = new SecretRedactor;
    $redactor->register('s3cr3t-token-value');
    $scanner = new PhpLogScanner($redactor, smokeSettings(null, 2, 80));

    $result = $scanner->fatals([
        '[06-Nov-2026 10:00:00 UTC] PHP Warning:  nothing',
        '[06-Nov-2026 10:00:00 UTC] PHP Fatal error:  one with s3cr3t-token-value in it and then a very long tail of text that goes on',
        '[06-Nov-2026 10:00:01 UTC] PHP Fatal error:  two',
        '[06-Nov-2026 10:00:02 UTC] PHP Parse error:  three',
    ]);

    expect($result['count'])->toBe(3)
        ->and($result['repeated'])->toBe(0)
        ->and($result['lines'])->toHaveCount(2)
        ->and(implode('', $result['lines']))->not->toContain('s3cr3t-token-value')
        ->and(mb_strlen($result['lines'][0]))->toBeLessThanOrEqual(80);
});

it('gives the same signature to the same fatal at another time and another server prefix', function (): void {
    $a = '[06-Nov-2026 10:00:00 UTC] PHP Fatal error:  Uncaught Error: boom in /a/b.php:3';
    $b = '[Sat Nov 07 11:11:11.000 2026] [php:error] [pid 9] PHP Fatal error:   Uncaught  Error: boom in /a/b.php:3';
    $c = '[06-Nov-2026 10:00:00 UTC] PHP Fatal error:  Uncaught Error: boom in /a/b.php:4';

    expect(scanner()->signature($a))->toBe(scanner()->signature($b))
        ->and(scanner()->signature($a))->not->toBe(scanner()->signature($c));
});

it('does not count a fatal that was already in the log before the baseline', function (): void {
    $old = '[05-Nov-2026 09:00:00 UTC] PHP Fatal error:  Uncaught Error: old problem in /a/old.php:7';
    $scanner = scanner();
    $known = $scanner->signatures([$old, '[05-Nov-2026 09:00:00 UTC] PHP Warning:  x']);

    $result = $scanner->fatals([
        '[06-Nov-2026 10:00:00 UTC] PHP Fatal error:  Uncaught Error: old problem in /a/old.php:7',
        '[06-Nov-2026 10:00:01 UTC] PHP Fatal error:  Uncaught Error: new problem in /a/new.php:1',
    ], $known);

    expect($known)->toHaveCount(1)
        ->and($result['count'])->toBe(1)
        ->and($result['repeated'])->toBe(1)
        ->and($result['lines'][0])->toContain('new problem');
});

it('finds nothing in an empty or clean log', function (): void {
    expect(scanner()->fatals([]))->toBe(['count' => 0, 'repeated' => 0, 'lines' => []])
        ->and(scanner()->fatals(['[x] PHP Notice:  x'])['count'])->toBe(0);
});

it('refuses an invalid or empty fatal pattern when the settings are built', function (array $patterns): void {
    /** @var list<string> $patterns */
    expect(fn () => smokeSettings($patterns))->toThrow(InvalidArgumentException::class, 'fatal_patterns');
})->with([
    'broken regex' => [['/(unclosed/']],
    'not a regex' => [['PHP Fatal error']],
    'none' => [[]],
]);

it('boots with the configured patterns', function (): void {
    expect(app(SmokeCheckSettings::class)->fatalPatterns)->not->toBe([]);
});

it('covers the worst case of the step with the configured timeout', function (): void {
    $settings = app(SmokeCheckSettings::class);

    expect($settings->worstCaseSeconds(15, 300))->toBe(15 + 8 * 300 + 6 * 30)
        ->and($settings->stepTimeoutSeconds)->toBeGreaterThanOrEqual($settings->worstCaseSeconds(
            config()->integer('wpup.ssh.connect_timeout_seconds'),
            config()->integer('wpup.ssh.command_timeout_seconds'),
        ));
});
