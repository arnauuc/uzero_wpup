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
 * Watermark tables: wc_orders with 10 orders, users with 3; the rest absent.
 *
 * @param  array<string, mixed>  $override
 * @return array<string, mixed>
 */
function watermarkTables(array $override = []): array
{
    $tables = array_fill_keys(OrderWatermark::TABLES, ['present' => false]);
    $tables['wc_orders'] = ['present' => true, 'max_id' => 10, 'max' => ['date_created_gmt' => '2026-11-01 10:00:00', 'date_updated_gmt' => '2026-11-01 11:00:00']];
    $tables['users'] = ['present' => true, 'max_id' => 3, 'max' => ['user_registered' => '2026-10-01 09:00:00'], 'fingerprint' => '3:123456'];
    $tables['usermeta_last_update'] = ['present' => true, 'max_id' => null, 'max' => ['last_update' => '1761987600']];

    return array_replace($tables, $override);
}

/**
 * @param  array<string, mixed>  $override
 */
function storedWatermark(array $override = []): OrderWatermark
{
    return OrderWatermark::parse('2026-11-01 12:00:00', 1762000000, watermarkTables($override)) ?? throw new LogicException('invalid');
}

/**
 * What the snippet answers (since mode) when nothing changed, with $override applied.
 *
 * @param  array<string, mixed>  $override
 * @param  array<string, mixed>  $tables
 * @return array<string, mixed>
 */
function siteAnswer(array $override = [], array $tables = []): array
{
    $all = array_fill_keys(OrderWatermark::TABLES, ['present' => false]);
    $all['wc_orders'] = ['present' => true, 'changed' => 0];
    $all['users'] = ['present' => true, 'changed' => 0, 'fingerprint' => '3:123456'];
    $all['usermeta_last_update'] = ['present' => true, 'changed' => 0];

    return array_replace(['v' => 1, 'now' => '2026-11-02 08:00:00', 'unix' => 1762070400, 'tables' => array_replace($all, $tables),
        'orders_total' => 0, 'users_total' => 0, 'truncated' => false, 'orders' => [], 'users' => []], $override);
}

it('round-trips a watermark and rejects only another version or read time', function (): void {
    $watermark = storedWatermark();

    expect(OrderWatermark::fromArray($watermark->toArray()))->toEqual($watermark)
        ->and(OrderWatermark::fromArray(null))->toBeNull()
        ->and(OrderWatermark::fromArray(['version' => 2] + $watermark->toArray()))->toBeNull()
        ->and(OrderWatermark::parse('ahir', 1762000000, watermarkTables()))->toBeNull();
});

it('keeps the rest of the watermark when one table does not validate', function (mixed $users): void {
    $watermark = OrderWatermark::parse('2026-11-01 12:00:00', 1762000000, watermarkTables(['users' => $users]));

    expect($watermark?->tables['users']->readable)->toBeFalse()
        ->and($watermark?->tables['wc_orders']->readable)->toBeTrue()
        ->and($watermark?->tables['wc_orders']->maxId)->toBe(10)
        ->and(OrderWatermark::fromArray($watermark?->toArray())?->tables['users']->readable)->toBeFalse();
})->with([
    'negative ID' => [['present' => true, 'max_id' => -1, 'max' => []]],
    'SQL in a date' => [['present' => true, 'max_id' => 1, 'max' => ['user_registered' => "1' OR 1=1"]]],
    'a weird timestamp' => [['present' => true, 'max_id' => 1, 'max' => ['last_update' => '99999999999999']]],
    'bad fingerprint' => [['present' => true, 'max_id' => 1, 'max' => [], 'fingerprint' => 'x']],
    'missing' => [null],
]);

it('finds no new writes when every table is as at the copy', function (): void {
    $writes = NewWritesDetector::compare(storedWatermark(), siteAnswer(), 50, 5);

    expect($writes->status)->toBe(NewWritesStatus::None)
        ->and($writes->mayLoseData())->toBeFalse()
        ->and($writes->describe())->toContain('No hi ha escriptures noves');
});

it('lists new and modified orders and users, and counts what is not listed', function (): void {
    $answer = siteAnswer([
        'orders_total' => 3, 'users_total' => 1,
        'orders' => [
            ['id' => 12, 'type' => 'shop_subscription', 'status' => 'wc-active', 'created' => '2026-11-02 07:00:00', 'modified' => '2026-11-02 07:00:00'],
            ['id' => 9, 'type' => 'shop_order_refund', 'status' => 'wc-completed', 'created' => '2026-10-30 10:00:00', 'modified' => '2026-11-02 07:30:00'],
        ],
        'users' => [['id' => 4, 'registered' => '2026-11-02 06:00:00', 'roles' => ['customer']]],
    ], [
        'wc_orders' => ['present' => true, 'changed' => 3],
        'users' => ['present' => true, 'changed' => 1, 'fingerprint' => '4:99'],
    ]);

    $writes = NewWritesDetector::compare(storedWatermark(), $answer, 2, 5);

    expect($writes->status)->toBe(NewWritesStatus::Found)
        ->and($writes->mayLoseData())->toBeTrue()
        ->and(array_map(static fn ($r): array => [$r->id, $r->new, $r->label], $writes->orders))->toBe([[12, true, 'subscripció, active'], [9, false, 'reemborsament, completed']])
        ->and($writes->users[0]->toArray())->toBe(['id' => 4, 'new' => true, 'date' => '2026-11-02 06:00:00', 'label' => 'customer'])
        ->and($writes->tables)->toBe(['wc_orders' => 3, 'users' => 1])
        ->and($writes->unexplained)->toBe([])
        ->and($writes->describe())->toBe('Des de la còpia hi ha escriptures noves: 3 comandes: #12 (nova, subscripció, active, 2026-11-02 07:00:00), '
            .'#9 (modificada, reemborsament, completed, 2026-11-02 07:30:00) i 1 més; 1 usuari: #4 (nou, customer, 2026-11-02 06:00:00).');
});

it('counts a user updated in place (fingerprint changed, no row explains it) as written', function (): void {
    $writes = NewWritesDetector::compare(storedWatermark(), siteAnswer(tables: ['users' => ['present' => true, 'changed' => 0, 'fingerprint' => '3:654321']]), 50, 5);

    expect($writes->status)->toBe(NewWritesStatus::Found)
        ->and($writes->mayLoseData())->toBeTrue()
        ->and($writes->unexplained)->toBe(['users'])
        ->and($writes->describe())->toBe('Des de la còpia hi ha escriptures noves: usuaris modificats: no es pot saber quins.');
});

it('counts every row of a table not readable at the copy, and is unknown if none', function (): void {
    $before = storedWatermark(['users' => ['present' => true, 'max_id' => 1, 'max' => ['user_registered' => 'ahir']]]);

    $none = NewWritesDetector::compare($before, siteAnswer(), 50, 5);
    $some = NewWritesDetector::compare($before, siteAnswer(['users_total' => 1, 'users' => [['id' => 1, 'registered' => null, 'roles' => []]]], ['users' => ['present' => true, 'changed' => 3]]), 50, 5);

    expect($none->status)->toBe(NewWritesStatus::Unknown)
        ->and($none->describe())->toContain('no es va poder llegir a users')
        ->and($some->status)->toBe(NewWritesStatus::Found)
        ->and($some->unreadable)->toBe(['users'])
        ->and($some->describe())->toContain("sense marca d'aigua llegible a users");
});

it('a table created since the copy counts its rows', function (): void {
    $writes = NewWritesDetector::compare(storedWatermark(), siteAnswer(tables: ['wc_customer_lookup' => ['present' => true, 'changed' => 2]]), 50, 5);

    expect($writes->status)->toBe(NewWritesStatus::Found)
        ->and($writes->describe())->toContain('files canviades a wc_customer_lookup (2)');
});

it('answers unknown, which counts as new writes, when it cannot compare', function (array $answer, string $reason): void {
    $writes = NewWritesDetector::compare(storedWatermark(), $answer, 50, 5);

    expect($writes->status)->toBe(NewWritesStatus::Unknown)
        ->and($writes->mayLoseData())->toBeTrue()
        ->and($writes->describe())->toContain('No es pot saber')->toContain($reason)->toContain("es tracta com si n'hi hagués");
})->with([
    'table gone' => [siteAnswer(tables: ['users' => ['present' => false]]), 'la taula users ja no hi és'],
    'no count' => [siteAnswer(tables: ['wc_orders' => ['present' => true]]), 'no és vàlida'],
    'more listed than asked' => [siteAnswer(['orders_total' => 60, 'orders' => array_fill(0, 51, ['id' => 20, 'type' => 'shop_order', 'status' => 'wc-pending', 'created' => null, 'modified' => null])]), 'no és vàlida'],
    'personal data in a label' => [siteAnswer(['users_total' => 1, 'users' => [['id' => 4, 'registered' => null, 'roles' => ['anna@example.test']]]]), 'no és vàlida'],
    'too many roles' => [siteAnswer(['users_total' => 1, 'users' => [['id' => 4, 'registered' => null, 'roles' => ['a', 'b', 'c', 'd', 'e', 'f']]]]), 'no és vàlida'],
    'no totals' => [array_diff_key(siteAnswer(), ['orders_total' => true]), 'no és vàlida'],
]);
