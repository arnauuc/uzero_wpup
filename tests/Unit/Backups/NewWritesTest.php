<?php

declare(strict_types=1);

use App\Domain\Backups\NewWritesDetector;
use App\Domain\Backups\OrderWatermark;
use App\Enums\NewWritesStatus;

/*
 * Order and user watermark (UZEROWPUP-58): validation of the stored shape and
 * comparison with the site's answer, without a site.
 */

/**
 * Snippet tables: wc_orders with 10 orders, users with 3; the rest absent.
 *
 * @param  array<string, array<string, mixed>>  $override
 * @return array<string, array<string, mixed>>
 */
function watermarkTables(array $override = []): array
{
    $tables = array_fill_keys(OrderWatermark::TABLES, ['present' => false, 'max_id' => null, 'max' => []]);
    $tables['wc_orders'] = ['present' => true, 'max_id' => 10, 'max' => ['date_created_gmt' => '2026-11-01 10:00:00', 'date_updated_gmt' => '2026-11-01 11:00:00']];
    $tables['users'] = ['present' => true, 'max_id' => 3, 'max' => ['user_registered' => '2026-10-01 09:00:00']];
    $tables['usermeta_last_update'] = ['present' => true, 'max_id' => null, 'max' => ['last_update' => '1761987600']];

    return array_replace($tables, $override);
}

function storedWatermark(): OrderWatermark
{
    return OrderWatermark::parse('2026-11-01 12:00:00', 1762000000, watermarkTables()) ?? throw new LogicException('invalid');
}

/**
 * What the snippet answers when nothing changed, with $override applied.
 *
 * @param  array<string, mixed>  $override
 * @param  array<string, array<string, mixed>>  $tables
 * @return array<string, mixed>
 */
function siteAnswer(array $override = [], array $tables = []): array
{
    $all = array_map(static fn (array $t): array => $t['present'] ? $t + ['changed' => 0] : $t, watermarkTables($tables));

    return array_replace(['v' => 1, 'now' => '2026-11-02 08:00:00', 'unix' => 1762070400, 'tables' => $all, 'orders_total' => 0, 'users_total' => 0, 'truncated' => false, 'orders' => [], 'users' => []], $override);
}

it('round-trips a watermark and rejects any other shape or version', function (): void {
    $watermark = storedWatermark();

    expect(OrderWatermark::fromArray($watermark->toArray()))->toEqual($watermark)
        ->and(OrderWatermark::fromArray(null))->toBeNull()
        ->and(OrderWatermark::fromArray(['version' => 2] + $watermark->toArray()))->toBeNull()
        ->and(OrderWatermark::parse('2026-11-01 12:00:00', 1762000000, array_diff_key(watermarkTables(), ['users' => true])))->toBeNull()
        ->and(OrderWatermark::parse('ahir', 1762000000, watermarkTables()))->toBeNull()
        ->and(OrderWatermark::parse('2026-11-01 12:00:00', 1762000000, watermarkTables(['users' => ['present' => true, 'max_id' => -1, 'max' => []]])))->toBeNull()
        ->and(OrderWatermark::parse('2026-11-01 12:00:00', 1762000000, watermarkTables(['users' => ['present' => true, 'max_id' => 1, 'max' => ['user_registered' => "1' OR 1=1"]]])))->toBeNull();
});

it('finds no new writes when every table is as at the copy', function (): void {
    $writes = NewWritesDetector::compare(storedWatermark(), siteAnswer(), 50);

    expect($writes->status)->toBe(NewWritesStatus::None)
        ->and($writes->mayLoseData())->toBeFalse()
        ->and($writes->describe())->toContain('No hi ha escriptures noves');
});

it('lists new and modified orders and users, and counts what is not listed', function (): void {
    $answer = siteAnswer([
        'orders_total' => 3, 'users_total' => 1,
        'orders' => [
            ['id' => 12, 'type' => 'shop_order', 'status' => 'wc-processing', 'created' => '2026-11-02 07:00:00', 'modified' => '2026-11-02 07:00:00'],
            ['id' => 9, 'type' => 'shop_order_refund', 'status' => 'wc-completed', 'created' => '2026-10-30 10:00:00', 'modified' => '2026-11-02 07:30:00'],
        ],
        'users' => [['id' => 4, 'registered' => '2026-11-02 06:00:00', 'roles' => ['customer']]],
    ], [
        'wc_orders' => ['present' => true, 'max_id' => 12, 'max' => ['date_created_gmt' => '2026-11-02 07:00:00', 'date_updated_gmt' => '2026-11-02 07:30:00'], 'changed' => 3],
        'users' => ['present' => true, 'max_id' => 4, 'max' => ['user_registered' => '2026-11-02 06:00:00'], 'changed' => 1],
    ]);

    $writes = NewWritesDetector::compare(storedWatermark(), $answer, 2);

    expect($writes->status)->toBe(NewWritesStatus::Found)
        ->and($writes->mayLoseData())->toBeTrue()
        ->and(array_map(static fn ($r): array => [$r->id, $r->new, $r->label], $writes->orders))->toBe([[12, true, 'comanda, processing'], [9, false, 'reemborsament, completed']])
        ->and($writes->users[0]->toArray())->toBe(['id' => 4, 'new' => true, 'date' => '2026-11-02 06:00:00', 'label' => 'customer'])
        ->and($writes->tables)->toBe(['wc_orders' => 3, 'users' => 1])
        ->and($writes->describe())->toBe('Des de la còpia hi ha escriptures noves: 3 comandes: #12 (nova, comanda, processing, 2026-11-02 07:00:00), '
            .'#9 (modificada, reemborsament, completed, 2026-11-02 07:30:00) i 1 més; 1 usuari: #4 (nou, customer, 2026-11-02 06:00:00).');
});

it('counts a maximum that went up as a write even when the site counted no row', function (string $table, array $state): void {
    /** @var array<string, array<string, mixed>> $tables */
    $tables = [$table => $state + ['changed' => 0]];
    $writes = NewWritesDetector::compare(storedWatermark(), siteAnswer(tables: $tables), 50);

    expect($writes->status)->toBe(NewWritesStatus::Found)
        ->and($writes->tables)->toBe([$table => 1])
        ->and($writes->describe())->toContain("files canviades a {$table} (1)");
})->with([
    'higher ID' => ['wc_orders', ['present' => true, 'max_id' => 11, 'max' => ['date_created_gmt' => '2026-11-01 10:00:00', 'date_updated_gmt' => '2026-11-01 11:00:00']]],
    'later modification' => ['wc_orders', ['present' => true, 'max_id' => 10, 'max' => ['date_created_gmt' => '2026-11-01 10:00:00', 'date_updated_gmt' => '2026-11-01 11:00:01']]],
    'later customer update (unix time)' => ['usermeta_last_update', ['present' => true, 'max_id' => null, 'max' => ['last_update' => '1762070000']]],
    'table created since the copy' => ['wc_customer_lookup', ['present' => true, 'max_id' => 1, 'max' => ['date_last_active' => null]]],
]);

it('answers unknown, which counts as new writes, when it cannot compare', function (array $answer, string $reason): void {
    $writes = NewWritesDetector::compare(storedWatermark(), $answer, 50);

    expect($writes->status)->toBe(NewWritesStatus::Unknown)
        ->and($writes->mayLoseData())->toBeTrue()
        ->and($writes->describe())->toContain('No es pot saber')->toContain($reason)->toContain("es tracta com si n'hi hagués");
})->with([
    'table gone' => [siteAnswer(tables: ['users' => ['present' => false, 'max_id' => null, 'max' => []]]), 'la taula users ja no hi és'],
    'no count' => [(static function (): array {
        $tables = array_map(static fn (array $t): array => $t['present'] ? $t + ['changed' => 0] : $t, watermarkTables());
        unset($tables['wc_orders']['changed']);

        return siteAnswer(['tables' => $tables]);
    })(), 'no és vàlida'],
    'more listed than asked' => [siteAnswer(['orders_total' => 60, 'orders' => array_fill(0, 51, ['id' => 20, 'type' => 'shop_order', 'status' => 'wc-pending', 'created' => null, 'modified' => null])]), 'no és vàlida'],
    'personal data in a label' => [siteAnswer(['users_total' => 1, 'users' => [['id' => 4, 'registered' => null, 'roles' => ['anna@example.test']]]]), 'no és vàlida'],
    'no totals' => [array_diff_key(siteAnswer(), ['orders_total' => true]), 'no és vàlida'],
]);
