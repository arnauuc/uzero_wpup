<?php

declare(strict_types=1);

namespace App\Domain\Backups;

use App\Domain\Drivers\SiteDriver;
use App\Enums\NewWritesStatus;

/**
 * Whether the orders or users of a site were written since a database copy
 * (UZEROWPUP-58, AGENTS.md rule 4): compares the copy's stored watermark with
 * the site now. Conservative: no watermark, one of another version, a table
 * that existed and is gone, or an answer that cannot be read or validated is
 * "unknown", which counts as written.
 */
final readonly class NewWritesDetector
{
    private const string NAME = '/^[a-z0-9_-]{1,40}$/D';

    public function __construct(
        private ShopWritesReader $reader,
        private int $maxListed,
    ) {}

    /**
     * @param  mixed  $stored  backups.order_watermark of the copy
     */
    public function detect(SiteDriver $driver, mixed $stored): NewWrites
    {
        $watermark = OrderWatermark::fromArray($stored);
        if ($watermark === null) {
            return NewWrites::unknown($stored === null ? "la còpia no té marca d'aigua de comandes i usuaris" : "la marca d'aigua de la còpia no és vàlida");
        }

        try {
            $data = $this->reader->since($driver, $watermark, $this->maxListed);
        } catch (WatermarkUnreadable $e) {
            return NewWrites::unknown("no s'ha pogut llegir el lloc: {$e->getMessage()}");
        }

        return self::compare($watermark, $data, $this->maxListed);
    }

    /**
     * Pure: the stored watermark against what ShopWritesReader::since() returned.
     * Besides the rows the site counted, a maximum that went up is a write.
     *
     * @param  array<mixed>  $data
     */
    public static function compare(OrderWatermark $before, array $data, int $maxListed): NewWrites
    {
        $now = OrderWatermark::parse($data['now'] ?? null, $data['unix'] ?? null, $data['tables'] ?? null);
        $tables = is_array($data['tables'] ?? null) ? $data['tables'] : [];
        if ($now === null || ! is_int($data['orders_total'] ?? null) || ! is_int($data['users_total'] ?? null) || ! is_array($data['orders'] ?? null) || ! is_array($data['users'] ?? null)) {
            return NewWrites::unknown('la resposta del lloc no és vàlida');
        }

        $changed = [];
        foreach (OrderWatermark::TABLES as $key) {
            [$old, $new] = [$before->tables[$key], $now->tables[$key]];
            if ($old->present && ! $new->present) {
                return NewWrites::unknown("la taula {$key} ja no hi és");
            }
            if (! $new->present) {
                continue;
            }
            $count = is_array($tables[$key]) ? ($tables[$key]['changed'] ?? null) : null;
            if (! is_int($count) || $count < 0) {
                return NewWrites::unknown('la resposta del lloc no és vàlida');
            }
            $raised = ($new->maxId ?? 0) > ($old->present ? ($old->maxId ?? 0) : 0);
            foreach ($new->max as $column => $value) {
                $raised = $raised || WatermarkTable::later($value, $old->present ? ($old->max[$column] ?? null) : null);
            }
            if ($count > 0 || $raised) {
                $changed[$key] = max($count, 1);
            }
        }

        $orderMax = max($before->tables['wc_orders']->maxId ?? 0, $before->tables['posts']->maxId ?? 0);
        $orders = self::records($data['orders'], $maxListed, static fn (array $r): ?array => self::name($r['type'] ?? null) && self::name($r['status'] ?? null)
            && self::date($r['created'] ?? null) && self::date($r['modified'] ?? null)
            ? [$orderMax, $r['modified'] ?? $r['created'], self::orderLabel($r['type'], $r['status'])] : null);
        $users = self::records($data['users'], $maxListed, static function (array $r) use ($before): ?array {
            $roles = is_array($r['roles'] ?? null) ? array_values(array_filter($r['roles'], static fn (mixed $role): bool => is_string($role) && self::name($role))) : null;
            if (! self::date($r['registered'] ?? null) || $roles === null || count($roles) !== count($r['roles']) || count($roles) > 5) {
                return null;
            }

            return [$before->tables['users']->maxId ?? 0, $r['registered'], $roles === [] ? 'sense rol' : implode(', ', $roles)];
        });
        if ($orders === null || $users === null) {
            return NewWrites::unknown('la resposta del lloc no és vàlida');
        }

        $found = $changed !== [] || $orders !== [] || $users !== [];

        return new NewWrites(
            $found ? NewWritesStatus::Found : NewWritesStatus::None,
            $orders,
            $users,
            max($data['orders_total'], count($orders)),
            max($data['users_total'], count($users)),
            ($data['truncated'] ?? false) === true,
            $changed,
        );
    }

    /**
     * @param  array<mixed>  $rows
     * @param  callable(array<mixed>): (array{int, mixed, string}|null)  $describe  [highest ID at the copy, date, label], null if invalid
     * @return list<AffectedRecord>|null
     */
    private static function records(array $rows, int $maxListed, callable $describe): ?array
    {
        if (count($rows) > $maxListed) {
            return null;
        }
        $records = [];
        foreach ($rows as $row) {
            if (! is_array($row) || ! is_int($row['id'] ?? null) || $row['id'] <= 0) {
                return null;
            }
            $about = $describe($row);
            if ($about === null) {
                return null;
            }
            [$maxId, $date, $label] = $about;
            $records[] = new AffectedRecord($row['id'], $row['id'] > $maxId, is_string($date) ? $date : null, $label);
        }

        return $records;
    }

    private static function orderLabel(mixed $type, mixed $status): string
    {
        $type = match ($type) {
            null => 'esborrada',
            'shop_order', 'shop_order_placehold' => 'comanda',
            'shop_order_refund' => 'reemborsament',
            default => is_string($type) ? $type : '?',
        };

        return is_string($status) ? $type.', '.preg_replace('/^wc-/', '', $status) : $type;
    }

    private static function name(mixed $value): bool
    {
        return $value === null || (is_string($value) && preg_match(self::NAME, $value) === 1);
    }

    private static function date(mixed $value): bool
    {
        return $value === null || (WatermarkTable::validValue($value) && ! ctype_digit($value));
    }
}
