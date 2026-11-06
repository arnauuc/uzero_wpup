<?php

declare(strict_types=1);

use App\Domain\Planning\FutureMinimums;
use Carbon\CarbonImmutable;

function futureMinimums(): FutureMinimums
{
    return FutureMinimums::fromConfig([
        ['component' => 'wordpress', 'label' => 'WordPress', 'version' => '7.0', 'min_php' => '7.4', 'min_wp' => null, 'effective' => null, 'note' => 'x'],
        ['component' => 'woocommerce', 'label' => 'WooCommerce', 'version' => '11.6', 'min_php' => '8.1', 'min_wp' => '6.9', 'effective' => '2027-02', 'note' => 'x'],
    ]);
}

/** @return list<string> */
function futureWarnings(?string $php, ?string $wp, ?string $wc, bool $verified = true, string $now = '2026-11-06'): array
{
    return futureMinimums()->warnings($php, $verified, $wp, $wc, CarbonImmutable::parse($now, 'UTC'));
}

it('warns that WooCommerce will be frozen when the PHP is too old for the next release', function (): void {
    expect(futureWarnings('7.4.33', '6.9', '11.5.2'))->toBe([
        'Quedarà congelat abans de WooCommerce 11.6 (febrer 2027): exigeix PHP 8.1 i el lloc té PHP 7.4.33.',
    ]);
});

it('does not warn when the PHP meets the minimum, even at the exact boundary', function (): void {
    expect(futureWarnings('8.1.0', '6.9', '11.5'))->toBe([])
        ->and(futureWarnings('8.3', '6.9', '11.5'))->toBe([]);
});

it('does not warn when the component already has the version or is not installed', function (): void {
    expect(futureWarnings('7.4', '6.9', '11.6'))->toBe([])
        ->and(futureWarnings('7.4', '6.9', '12.0'))->toBe([])
        ->and(futureWarnings('7.4', '6.9', null))->toBe([]);
});

it('treats a release candidate of the target as older', function (): void {
    expect(futureWarnings('7.4', '6.9', '11.6-RC1'))->toHaveCount(1);
});

it('says the site is already frozen once the date has passed', function (): void {
    expect(futureWarnings('8.0', '6.9', '11.5', now: '2027-02-01')[0])->toStartWith('Congelat abans de WooCommerce 11.6');
});

it('also warns about the WordPress the next release requires', function (): void {
    expect(futureWarnings('8.2', '6.8', '11.5'))->toBe(['Quedarà congelat abans de WooCommerce 11.6 (febrer 2027): exigeix WordPress 6.9 i el lloc té WordPress 6.8.']);
});

it('says when the PHP is the CLI one and not the web one', function (): void {
    expect(futureWarnings('7.4', '6.9', '11.5', verified: false)[0])->toContain('(no verificat pel web: és el del CLI)');
});

it('warns about WordPress without a date when it is unknown', function (): void {
    expect(futureWarnings('7.3', '6.9', null))->toBe(['Quedarà congelat abans de WordPress 7.0: exigeix PHP 7.4 i el lloc té PHP 7.3.']);
});

it('stays silent when a version cannot be read', function (): void {
    expect(futureWarnings(null, '6.9', '11.5'))->toBe([])
        ->and(futureWarnings('unknown', '6.9', '11.5'))->toBe([])
        ->and(futureWarnings('7.4', null, 'dev-main'))->toBe([]);
});

it('rejects a malformed configuration row', function (): void {
    expect(fn () => FutureMinimums::fromConfig([['component' => 'woocommerce']]))->toThrow(UnexpectedValueException::class)
        ->and(fn () => FutureMinimums::fromConfig([['component' => 'woocommerce', 'label' => 'W', 'version' => '11.6', 'min_php' => '8.1', 'min_wp' => null, 'effective' => '2027-13', 'note' => 'x']]))->toThrow(UnexpectedValueException::class);
});
