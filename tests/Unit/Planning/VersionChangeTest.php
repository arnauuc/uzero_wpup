<?php

declare(strict_types=1);

use App\Domain\Planning\VersionChange;
use App\Enums\ComponentType;

// ADR-0005: what is major for each component type, with real release histories.
it('classifies real releases as ADR-0005 says', function (string $type, string $from, string $to, bool $major): void {
    expect(VersionChange::isMajor(ComponentType::from($type), $from, $to))->toBe($major);
})->with([
    // Core: every x.y is major, x.y.z is maintenance and security.
    'core 6.7.2 → 6.7.3' => ['core', '6.7.2', '6.7.3', false],
    'core 6.7.2 → 6.8' => ['core', '6.7.2', '6.8', true],
    'core 6.8.3 → 6.9' => ['core', '6.8.3', '6.9', true],
    'core 6.9.4 → 7.0' => ['core', '6.9.4', '7.0', true],
    'core 7.0 → 7.0.1' => ['core', '7.0', '7.0.1', false],
    'core 6.9 → 6.9.0.1' => ['core', '6.9', '6.9.0.1', false],
    // WooCommerce: monthly x.y are not major; X.0 is.
    'wc 9.8.5 → 9.9.0' => ['woocommerce', '9.8.5', '9.9.0', false],
    'wc 9.9.5 → 10.0.0' => ['woocommerce', '9.9.5', '10.0.0', true],
    'wc 10.0.4 → 10.1.0' => ['woocommerce', '10.0.4', '10.1.0', false],
    'wc 10.9.2 → 10.10.0' => ['woocommerce', '10.9.2', '10.10.0', false],
    'wc 10.4.3 → 11.0.0' => ['woocommerce', '10.4.3', '11.0.0', true],
    // Plugins and themes: the first number.
    'yoast 25.9 → 26.0' => ['plugin', '25.9', '26.0', true],
    'elementor 3.31.2 → 3.32.0' => ['plugin', '3.31.2', '3.32.0', false],
    'jetpack 14.9.1 → 15.0' => ['plugin', '14.9.1', '15.0', true],
    'cf7 6.1.1 → 6.1.2' => ['plugin', '6.1.1', '6.1.2', false],
    'storefront 4.6.1 → 4.6.2' => ['theme', '4.6.1', '4.6.2', false],
    'twentytwentyfive 1.3 → 2.0' => ['theme', '1.3', '2.0', true],
    // Not semver: date versions, leading zeros, pre-releases, prefixes, unreadable.
    'date 2024.05.01 → 2024.06.01' => ['plugin', '2024.05.01', '2024.06.01', false],
    'date 2024.12.1 → 2025.1.0' => ['plugin', '2024.12.1', '2025.1.0', true],
    'leading zero 01.2 → 1.3' => ['plugin', '01.2', '1.3', false],
    'beta to release 3.0.0-beta1 → 3.0.0' => ['plugin', '3.0.0-beta1', '3.0.0', false],
    'release candidate 2.9 → 3.0.0-RC1' => ['plugin', '2.9', '3.0.0-RC1', true],
    'prefix v1.4 → v2.0' => ['theme', 'v1.4', 'v2.0', true],
    'single number 7 → 8' => ['plugin', '7', '8', true],
    'single number 7 → 7.1' => ['plugin', '7', '7.1', false],
    'unreadable target' => ['plugin', '1.2', 'trunk', true],
    'unreadable installed' => ['plugin', 'dev-main', '1.2', true],
]);
