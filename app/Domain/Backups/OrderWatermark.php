<?php

declare(strict_types=1);

namespace App\Domain\Backups;

/**
 * The order and user watermark of a database copy (UZEROWPUP-58, ADR-0009),
 * read on the site right before the dump and stored in
 * backups.order_watermark: for each table that holds orders or users, its
 * highest ID and the highest of its creation and modification dates. Anything
 * above it was written after the read, so possibly after the dump
 * (NewWritesDetector). Versioned: a stored shape of another version, or with
 * a table missing, is not a watermark (null), and the detector answers
 * "unknown".
 */
final readonly class OrderWatermark
{
    public const int VERSION = 1;

    /**
     * Keys of ShopWritesReader::SNIPPET (table names without the prefix).
     * HPOS and the classic posts storage of orders (with their meta, items,
     * notes, addresses and operational data), users, their meta and WooCommerce's
     * last update of a customer, and the customer lookup table.
     */
    public const array TABLES = [
        'wc_orders', 'wc_orders_meta', 'wc_order_addresses', 'wc_order_operational_data',
        'posts', 'postmeta', 'comments', 'woocommerce_order_items', 'woocommerce_order_itemmeta',
        'users', 'usermeta', 'usermeta_last_update', 'wc_customer_lookup',
    ];

    /**
     * @param  string  $readAt  the site's GMT time of the read ("Y-m-d H:i:s")
     * @param  array<string, WatermarkTable>  $tables  by key of TABLES, all of them
     */
    public function __construct(
        public string $readAt,
        public int $readAtUnix,
        public array $tables,
    ) {}

    /**
     * The stored shape (toArray()), or null when it is not a valid watermark.
     */
    public static function fromArray(mixed $data): ?self
    {
        if (! is_array($data) || ($data['version'] ?? null) !== self::VERSION) {
            return null;
        }

        return self::parse($data['read_at'] ?? null, $data['read_at_unix'] ?? null, $data['tables'] ?? null);
    }

    /**
     * Validates what the snippet printed (sizes, types and formats).
     */
    public static function parse(mixed $readAt, mixed $readAtUnix, mixed $tables): ?self
    {
        if (! is_string($readAt) || preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $readAt) !== 1 || ! is_int($readAtUnix) || $readAtUnix <= 0 || ! is_array($tables)) {
            return null;
        }
        $parsed = [];
        foreach (self::TABLES as $key) {
            $table = WatermarkTable::fromArray($tables[$key] ?? null);
            if ($table === null) {
                return null;
            }
            $parsed[$key] = $table;
        }

        return new self($readAt, $readAtUnix, $parsed);
    }

    /**
     * @return array{version: int, read_at: string, read_at_unix: int, tables: array<string, array{present: bool, max_id: int|null, max: array<string, string|null>}>}
     */
    public function toArray(): array
    {
        return [
            'version' => self::VERSION,
            'read_at' => $this->readAt,
            'read_at_unix' => $this->readAtUnix,
            'tables' => array_map(static fn (WatermarkTable $t): array => $t->toArray(), $this->tables),
        ];
    }
}
