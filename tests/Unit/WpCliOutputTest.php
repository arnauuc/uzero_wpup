<?php

declare(strict_types=1);

use App\Domain\Drivers\Exceptions\RemoteCommandFailed;
use App\Domain\Drivers\Ssh\WpCliOutput;

it('reads the JSON list even with PHP notices around it', function (): void {
    $out = "PHP Deprecated:  Function foo() is deprecated in /x/y.php on line 3 [at 12:00]\nDeprecated: Function foo() is deprecated in /x/y.php on line 3\n[\"wp_options\",\"wp_posts\"]\nNotice: late [\"noise\"]\n";

    expect(WpCliOutput::stringList($out))->toBe(['wp_options', 'wp_posts']);
});

it('reads an empty list', function (): void {
    expect(WpCliOutput::stringList("[]\n"))->toBe([]);
});

it('fails when WP-CLI printed no list', function (string $out): void {
    expect(fn () => WpCliOutput::stringList($out))->toThrow(RemoteCommandFailed::class);
})->with(['', "Warning: nothing\n", "[broken\n"]);
