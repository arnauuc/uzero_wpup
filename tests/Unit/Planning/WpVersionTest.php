<?php

declare(strict_types=1);

use App\Domain\Planning\WpVersion;

it('compares WordPress-style versions tolerantly', function (string $a, string $b, int $expected): void {
    expect(WpVersion::compareStrings($a, $b) <=> 0)->toBe($expected)
        ->and(WpVersion::compareStrings($b, $a) <=> 0)->toBe(-$expected);
})->with([
    'missing components are zero' => ['6.5', '6.5.0', 0],
    'four components' => ['6.5.0.0', '6.5', 0],
    'fourth component counts' => ['1.2.3.4', '1.2.3', 1],
    'numbers, not strings' => ['6.10', '6.9', 1],
    'more components than four' => ['1.2.3.4.5', '1.2.3.4.4', 1],
    'leading v' => ['v2.1', '2.1.0', 0],
    'leading zeros' => ['6.05', '6.5', 0],
    'rc below release' => ['6.5-RC1', '6.5', -1],
    'rc number' => ['6.5-RC1', '6.5-RC2', -1],
    'beta below rc' => ['6.5-beta3', '6.5-RC1', -1],
    'alpha below beta' => ['6.5-alpha', '6.5-beta', -1],
    'dev below alpha' => ['6.5-dev', '6.5-alpha', -1],
    'no separator' => ['6.5beta1', '6.5-beta1', 0],
    'dotted rc' => ['6.5-rc.2', '6.5-RC2', 0],
    'short alpha' => ['2.0a', '2.0-alpha', 0],
    'wordpress trunk' => ['6.9-alpha-60000-src', '6.9', -1],
    'pre-release above previous release' => ['6.5-RC1', '6.4.9', 1],
    'patch level above release' => ['5.6pl1', '5.6', 1],
    'build metadata ignored' => ['7.4+build.5', '7.4', 0],
    'distribution suffix ignored' => ['8.1.2-1ubuntu2.14', '8.1.2', 0],
    'unreadable sorts below readable' => ['dev-main', '0.1', -1],
    'not a beta: b2b' => ['2.0.0-b2b', '2.0.0', 0],
    'not an alpha: a1b2c3' => ['1.0.0-a1b2c3', '1.0.0', 0],
    'underscore patch suffix' => ['1.2.3_patch1', '1.2.3', 0],
    'pre is a pre-release' => ['1.0-pre2', '1.0', -1],
    'uppercase rc' => ['6.5RC3', '6.5-rc3', 0],
    'very long components' => ['1.123456789012345678901234567890', '1.123456789012345678901234567891', -1],
    'long component above short' => ['1.10000000000000000000', '1.9', 1],
]);

it('refuses what is not a version', function (?string $value): void {
    expect(WpVersion::parse($value))->toBeNull()
        ->and(WpVersion::isValid($value))->toBeFalse();
})->with([null, '', '   ', 'dev-main', '6.x', '>= 7.4', '6.5 or higher', 'WordPress 6.0', '6.5-', '1.', 'latest', '6.5c']);

it('accepts surrounding spaces, a trailing plus and a single number', function (string $value): void {
    expect(WpVersion::isValid($value))->toBeTrue();
})->with([' 7.4 ', '7.4+', '7', '10.3.0.1', '1.2.3_patch1', '2.0.0-b2b', '99999999999999999999.1']);

it('checks a minimum and never takes an unreadable value as met', function (?string $have, ?string $need, ?bool $met): void {
    expect(WpVersion::satisfies($have, $need))->toBe($met);
})->with([
    ['6.5', '6.5.0', true],
    ['6.5.0', '6.5', true],
    ['8.2.20', '8.3', false],
    ['6.5-RC1', '6.5', false],
    ['6.10.1', '6.9', true],
    ['8.1.2-1ubuntu2.14', '8.1', true],
    ['6.5', '6.x', null],
    ['nope', '6.5', null],
    [null, '6.5', null],
]);

it('gives the first components zero-padded', function (): void {
    expect(WpVersion::parse('7')?->head(2))->toBe(['7', '0'])
        ->and(WpVersion::parse('6.09.1')?->head(2))->toBe(['6', '9']);
});
