<?php

declare(strict_types=1);

namespace App\Domain\Backups;

use App\Domain\Drivers\Exceptions\DriverException;
use App\Domain\Drivers\SiteDriver;
use App\Domain\Inventory\WpCliReader;

/**
 * Reads, through the driver, the order and user tables of a site
 * (UZEROWPUP-58): their watermark (OrderWatermark), and what was written above
 * a stored one. One constant `wp eval` snippet with $wpdb, without plugins and
 * themes (a broken site still answers), PHP 7.4 compatible:
 *
 * - table names come from $wpdb's prefix and constant suffixes; the only
 *   input, the stored watermark and the list limit, travels base64-encoded as
 *   JSON data and reaches SQL only through $wpdb->prepare() placeholders;
 * - a row counts as written after the watermark when its ID is higher, or a
 *   date of it is later than the stored maximum or not earlier than the read
 *   itself (a write within the same second); meta tables without dates count
 *   by ID;
 * - only IDs, types, statuses, dates and roles leave the site: never names,
 *   emails or addresses.
 */
final readonly class ShopWritesReader
{
    /** Constant on purpose: see the class comment. $in is set by the line command() puts before it. */
    public const string SNIPPET = <<<'PHP'
$wpupRead = function ($in) {
    global $wpdb;
    $p = $wpdb->prefix;
    $t = function ($name) { return '`' . str_replace('`', '', $name) . '`'; };
    $types = "t.post_type IN ('shop_order','shop_order_refund','shop_order_placehold')";
    $byOrder = function ($table, $id, $entity) use ($p, $t) {
        return array('needs' => array($p . $table), 'from' => $t($p . $table) . ' t', 'where' => '1=1', 'id' => $id, 'dates' => array(), 'entity' => $entity, 'kind' => 'order');
    };
    $specs = array(
        'wc_orders' => array('needs' => array($p . 'wc_orders'), 'from' => $t($p . 'wc_orders') . ' t', 'where' => '1=1', 'id' => 't.id', 'dates' => array('date_created_gmt' => 't.date_created_gmt', 'date_updated_gmt' => 't.date_updated_gmt'), 'entity' => 't.id', 'kind' => 'order'),
        'wc_orders_meta' => $byOrder('wc_orders_meta', 't.id', 't.order_id'),
        'wc_order_addresses' => $byOrder('wc_order_addresses', 't.id', 't.order_id'),
        'wc_order_operational_data' => $byOrder('wc_order_operational_data', 't.id', 't.order_id'),
        'posts' => array('needs' => array($p . 'posts'), 'from' => $t($p . 'posts') . ' t', 'where' => $types, 'id' => 't.ID', 'dates' => array('post_date_gmt' => 't.post_date_gmt', 'post_modified_gmt' => 't.post_modified_gmt'), 'entity' => 't.ID', 'kind' => 'order'),
        'postmeta' => array('needs' => array($p . 'postmeta', $p . 'posts'), 'from' => $t($p . 'postmeta') . ' m JOIN ' . $t($p . 'posts') . ' t ON t.ID = m.post_id', 'where' => $types, 'id' => 'm.meta_id', 'dates' => array(), 'entity' => 'm.post_id', 'kind' => 'order'),
        'comments' => array('needs' => array($p . 'comments'), 'from' => $t($p . 'comments') . ' t', 'where' => "t.comment_type = 'order_note'", 'id' => 't.comment_ID', 'dates' => array('comment_date_gmt' => 't.comment_date_gmt'), 'entity' => 't.comment_post_ID', 'kind' => 'order'),
        'woocommerce_order_items' => $byOrder('woocommerce_order_items', 't.order_item_id', 't.order_id'),
        'woocommerce_order_itemmeta' => array('needs' => array($p . 'woocommerce_order_itemmeta', $p . 'woocommerce_order_items'), 'from' => $t($p . 'woocommerce_order_itemmeta') . ' t JOIN ' . $t($p . 'woocommerce_order_items') . ' i ON i.order_item_id = t.order_item_id', 'where' => '1=1', 'id' => 't.meta_id', 'dates' => array(), 'entity' => 'i.order_id', 'kind' => 'order'),
        'users' => array('needs' => array($wpdb->users), 'from' => $t($wpdb->users) . ' t', 'where' => '1=1', 'id' => 't.ID', 'dates' => array('user_registered' => 't.user_registered'), 'entity' => 't.ID', 'kind' => 'user'),
        'usermeta' => array('needs' => array($wpdb->usermeta), 'from' => $t($wpdb->usermeta) . ' t', 'where' => '1=1', 'id' => 't.umeta_id', 'dates' => array(), 'entity' => 't.user_id', 'kind' => 'user'),
        'usermeta_last_update' => array('needs' => array($wpdb->usermeta), 'from' => $t($wpdb->usermeta) . ' t', 'where' => "t.meta_key = 'last_update'", 'id' => null, 'dates' => array('last_update' => 'CAST(t.meta_value AS UNSIGNED)'), 'unix' => true, 'entity' => 't.user_id', 'kind' => 'user'),
        'wc_customer_lookup' => array('needs' => array($p . 'wc_customer_lookup'), 'from' => $t($p . 'wc_customer_lookup') . ' t', 'where' => '1=1', 'id' => 't.customer_id', 'dates' => array('date_last_active' => 't.date_last_active'), 'entity' => 't.user_id', 'kind' => 'user'),
    );
    $sql = function ($query, $args) use ($wpdb) { return $args === array() ? $query : $wpdb->prepare($query, $args); };
    $out = array('v' => 1, 'now' => gmdate('Y-m-d H:i:s'), 'unix' => time(), 'tables' => array());
    $found = array('order' => array(), 'user' => array());
    $cap = 1000;
    $truncated = false;
    foreach ($specs as $key => $s) {
        $present = true;
        foreach ($s['needs'] as $name) {
            $got = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($name)));
            $present = $present && is_string($got) && strtolower($got) === strtolower($name);
        }
        $row = array('present' => $present, 'max_id' => null, 'max' => array());
        if ($present) {
            $cols = array($s['id'] === null ? 'NULL' : 'MAX(' . $s['id'] . ')');
            foreach ($s['dates'] as $expr) { $cols[] = 'MAX(' . $expr . ')'; }
            $m = $wpdb->get_row('SELECT ' . implode(', ', $cols) . ' FROM ' . $s['from'] . ' WHERE ' . $s['where'], ARRAY_N);
            if ($wpdb->last_error !== '' || !is_array($m)) { return array('v' => 1, 'error' => $key); }
            $row['max_id'] = $m[0] === null ? null : (int) $m[0];
            $i = 1;
            foreach ($s['dates'] as $name => $expr) { $row['max'][$name] = $m[$i] === null ? null : (string) $m[$i]; $i++; }
            if (is_array($in) && isset($in['since'][$key]) && is_array($in['since'][$key])) {
                $since = $in['since'][$key];
                $conds = array();
                $args = array();
                if ($s['id'] !== null) { $conds[] = $s['id'] . ' > %d'; $args[] = isset($since['max_id']) ? (int) $since['max_id'] : 0; }
                foreach ($s['dates'] as $name => $expr) {
                    $prev = isset($since['max'][$name]) && is_string($since['max'][$name]) ? $since['max'][$name] : null;
                    if ($prev === null) { $conds[] = $expr . ' IS NOT NULL'; continue; }
                    $conds[] = '(' . $expr . ' > %s OR ' . $expr . ' >= %s)';
                    $args[] = $prev;
                    $args[] = (string) (isset($s['unix']) ? $in['unix'] : $in['now']);
                }
                $where = ' FROM ' . $s['from'] . ' WHERE ' . $s['where'] . ' AND (' . implode(' OR ', $conds) . ')';
                $row['changed'] = (int) $wpdb->get_var($sql('SELECT COUNT(*)' . $where, $args));
                if ($wpdb->last_error !== '') { return array('v' => 1, 'error' => $key); }
                $ids = $wpdb->get_col($sql('SELECT DISTINCT ' . $s['entity'] . $where . ' AND ' . $s['entity'] . ' IS NOT NULL ORDER BY 1 DESC LIMIT ' . $cap, $args));
                if ($wpdb->last_error !== '') { return array('v' => 1, 'error' => $key); }
                $truncated = $truncated || count($ids) >= $cap;
                foreach ($ids as $id) { $found[$s['kind']][(int) $id] = true; }
            }
        }
        $out['tables'][$key] = $row;
    }
    if (!is_array($in)) { return $out; }
    $limit = isset($in['limit']) ? max(0, min(500, (int) $in['limit'])) : 0;
    $orders = array_keys($found['order']);
    $users = array_keys($found['user']);
    rsort($orders);
    rsort($users);
    $out += array('orders_total' => count($orders), 'users_total' => count($users), 'truncated' => $truncated, 'orders' => array(), 'users' => array());
    $orders = array_slice($orders, 0, $limit);
    $users = array_slice($users, 0, $limit);
    if ($orders !== array()) {
        $list = implode(',', array_map('intval', $orders));
        $rows = array();
        foreach ((array) $wpdb->get_results('SELECT ID, post_type, post_status, post_date_gmt, post_modified_gmt FROM ' . $t($p . 'posts') . ' WHERE ID IN (' . $list . ')', ARRAY_N) as $r) { $rows[(int) $r[0]] = $r; }
        if ($out['tables']['wc_orders']['present']) {
            foreach ((array) $wpdb->get_results('SELECT id, type, status, date_created_gmt, date_updated_gmt FROM ' . $t($p . 'wc_orders') . ' WHERE id IN (' . $list . ')', ARRAY_N) as $r) { $rows[(int) $r[0]] = $r; }
        }
        foreach ($orders as $id) {
            $r = isset($rows[$id]) ? $rows[$id] : array(null, null, null, null, null);
            $out['orders'][] = array('id' => $id, 'type' => $r[1], 'status' => $r[2], 'created' => $r[3], 'modified' => $r[4]);
        }
    }
    if ($users !== array()) {
        $list = implode(',', array_map('intval', $users));
        $registered = array();
        foreach ((array) $wpdb->get_results('SELECT ID, user_registered FROM ' . $t($wpdb->users) . ' WHERE ID IN (' . $list . ')', ARRAY_N) as $r) { $registered[(int) $r[0]] = $r[1]; }
        $roles = array();
        foreach ((array) $wpdb->get_results($wpdb->prepare('SELECT user_id, meta_value FROM ' . $t($wpdb->usermeta) . ' WHERE meta_key = %s AND user_id IN (' . $list . ')', $wpdb->get_blog_prefix() . 'capabilities'), ARRAY_N) as $r) {
            $caps = is_string($r[1]) ? @unserialize($r[1], array('allowed_classes' => false)) : false;
            $roles[(int) $r[0]] = is_array($caps) ? array_slice(array_values(array_filter(array_keys(array_filter($caps)), 'is_string')), 0, 5) : array();
        }
        foreach ($users as $id) {
            $out['users'][] = array('id' => $id, 'registered' => isset($registered[$id]) ? $registered[$id] : null, 'roles' => isset($roles[$id]) ? $roles[$id] : array());
        }
    }
    return $out;
};
echo "\n" . json_encode($wpupRead($in), JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR) . "\n";
PHP;

    public function __construct(
        private WpCliReader $reader,
        private int $timeoutSeconds,
    ) {}

    /**
     * The WP-CLI arguments of the snippet with $input as its data.
     *
     * @param  array<string, mixed>|null  $input
     * @return list<string>
     */
    public static function command(?array $input): array
    {
        $data = $input === null ? 'null' : "json_decode(base64_decode('".base64_encode((string) json_encode($input, JSON_THROW_ON_ERROR))."'), true)";

        return ['eval', "\$in = {$data};\n".self::SNIPPET, '--skip-plugins', '--skip-themes'];
    }

    /**
     * @throws WatermarkUnreadable
     */
    public function watermark(SiteDriver $driver): OrderWatermark
    {
        $data = $this->run($driver, null);

        return OrderWatermark::parse($data['now'] ?? null, $data['unix'] ?? null, $data['tables'] ?? null)
            ?? throw new WatermarkUnreadable('la resposta del lloc no és vàlida');
    }

    /**
     * What was written above $watermark (NewWritesDetector::compare() validates it).
     *
     * @return array<mixed>
     *
     * @throws WatermarkUnreadable
     */
    public function since(SiteDriver $driver, OrderWatermark $watermark, int $limit): array
    {
        return $this->run($driver, [
            'limit' => $limit,
            'now' => $watermark->readAt,
            'unix' => $watermark->readAtUnix,
            // A table absent at the copy: every row it has now is new.
            'since' => array_map(static fn (WatermarkTable $t): array => $t->present
                ? ['max_id' => $t->maxId ?? 0, 'max' => (object) $t->max]
                : ['max_id' => 0, 'max' => (object) []], $watermark->tables),
        ]);
    }

    /**
     * @param  array<string, mixed>|null  $input
     * @return array<mixed>
     *
     * @throws WatermarkUnreadable
     */
    private function run(SiteDriver $driver, ?array $input): array
    {
        try {
            $result = $driver->wp(self::command($input), $this->timeoutSeconds);
        } catch (DriverException $e) {
            throw new WatermarkUnreadable($this->reader->redact($e->getMessage()), previous: $e);
        }
        if (! $result->successful()) {
            throw new WatermarkUnreadable($this->reader->redact(mb_substr("WP-CLI ha acabat amb el codi {$result->exitCode}: ".trim($result->stderr), 0, 300)));
        }

        $data = $this->reader->json($result->stdout);
        if ($data === null || ($data['v'] ?? null) !== 1) {
            throw new WatermarkUnreadable('la resposta del lloc no és JSON vàlid');
        }
        if (isset($data['error'])) {
            throw new WatermarkUnreadable('la consulta d\'una taula ha fallat ('.(is_string($data['error']) ? mb_substr($data['error'], 0, 64) : '?').')');
        }

        return $data;
    }
}
