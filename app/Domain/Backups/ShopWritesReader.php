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
 * - table names come from $wpdb's prefix and constant suffixes; the input
 *   (the stored watermark, the order types and the limits, validated here)
 *   travels base64-encoded as JSON data, and reaches SQL only through
 *   $wpdb->prepare() placeholders or as integers;
 * - a row counts as written after the watermark when its ID is higher, or a
 *   date of it is later than the stored maximum or not earlier than the read
 *   minus a safety margin (a write in the same second, or a transaction that
 *   reserved a lower ID and committed after the read); meta tables without
 *   dates count by ID (their global maximum: the primary key, no scan);
 * - users also keep an aggregate fingerprint of their rows, so that an update
 *   that changes no ID nor date (password, email, status) is still seen;
 * - the order tables are only read where WooCommerce is (or was) installed;
 * - only IDs, types, statuses, dates and roles leave the site: never names,
 *   emails or addresses.
 */
final readonly class ShopWritesReader
{
    /** Constant on purpose: see the class comment. $in is set by the line command() puts before it. */
    public const string SNIPPET = <<<'PHP'
$wpupRead = function ($in) {
    global $wpdb;
    $fail = function ($what) { return array('v' => 1, 'error' => $what); };
    if (!is_array($in) || !isset($in['mode'], $in['order_types']) || !is_array($in['order_types'])) { return $fail('input'); }
    $since = $in['mode'] === 'since';
    $p = $wpdb->prefix;
    $t = function ($name) { return '`' . str_replace('`', '', $name) . '`'; };
    $types = array();
    foreach ($in['order_types'] as $type) { if (is_string($type) && preg_match('/^[a-z0-9_]{1,40}$/', $type)) { $types[] = $type; } }
    if ($types === array()) { return $fail('order_types'); }
    $inTypes = 't.post_type IN (' . implode(',', array_fill(0, count($types), '%s')) . ')';
    $shop = get_option('woocommerce_db_version') !== false || get_option('woocommerce_version') !== false;
    $hpos = get_option('woocommerce_custom_orders_table_enabled') === 'yes';
    $plain = function ($table, $id, $entity, $kind) use ($p, $t) {
        return array('needs' => array($p . $table), 'from' => $t($p . $table) . ' t', 'where' => '1=1', 'args' => array(), 'id' => $id, 'dates' => array(), 'entity' => $entity, 'kind' => $kind);
    };
    $specs = array(
        'wc_orders' => array('dates' => array('date_created_gmt' => 't.date_created_gmt', 'date_updated_gmt' => 't.date_updated_gmt')) + $plain('wc_orders', 't.id', 't.id', 'order'),
        'wc_orders_meta' => $plain('wc_orders_meta', 't.id', 't.order_id', 'order'),
        'wc_order_addresses' => $plain('wc_order_addresses', 't.id', 't.order_id', 'order'),
        'wc_order_operational_data' => $plain('wc_order_operational_data', 't.id', 't.order_id', 'order'),
        'posts' => array('where' => $inTypes, 'args' => $types, 'dates' => array('post_date_gmt' => 't.post_date_gmt', 'post_modified_gmt' => 't.post_modified_gmt')) + $plain('posts', 't.ID', 't.ID', 'order'),
        'postmeta' => array('needs' => array($p . 'postmeta', $p . 'posts'), 'from' => $t($p . 'postmeta') . ' m JOIN ' . $t($p . 'posts') . ' t ON t.ID = m.post_id', 'where' => $inTypes, 'args' => $types, 'max_from' => $t($p . 'postmeta') . ' m', 'id' => 'm.meta_id', 'entity' => 'm.post_id') + $plain('postmeta', '', '', 'order'),
        'comments' => array('where' => "t.comment_type = 'order_note'", 'max_from' => $t($p . 'comments') . ' t') + $plain('comments', 't.comment_ID', 't.comment_post_ID', 'order'),
        'woocommerce_order_items' => $plain('woocommerce_order_items', 't.order_item_id', 't.order_id', 'order'),
        'woocommerce_order_itemmeta' => array('needs' => array($p . 'woocommerce_order_itemmeta', $p . 'woocommerce_order_items'), 'from' => $t($p . 'woocommerce_order_itemmeta') . ' t JOIN ' . $t($p . 'woocommerce_order_items') . ' i ON i.order_item_id = t.order_item_id', 'max_from' => $t($p . 'woocommerce_order_itemmeta') . ' t', 'entity' => 'i.order_id') + $plain('woocommerce_order_itemmeta', 't.meta_id', '', 'order'),
        'users' => array('needs' => array($wpdb->users), 'from' => $t($wpdb->users) . ' t', 'dates' => array('user_registered' => 't.user_registered'), 'fingerprint' => "CRC32(CONCAT_WS('|', t.ID, t.user_login, t.user_pass, t.user_email, t.user_url, t.user_status, t.user_activation_key, t.display_name))") + $plain('users', 't.ID', 't.ID', 'user'),
        'usermeta' => array('needs' => array($wpdb->usermeta), 'from' => $t($wpdb->usermeta) . ' t') + $plain('usermeta', 't.umeta_id', 't.user_id', 'user'),
        'usermeta_last_update' => array('needs' => array($wpdb->usermeta), 'from' => $t($wpdb->usermeta) . ' t', 'where' => "t.meta_key = 'last_update' AND CAST(t.meta_value AS UNSIGNED) BETWEEN 946684800 AND 4102444800", 'id' => null, 'dates' => array('last_update' => 'CAST(t.meta_value AS UNSIGNED)'), 'unix' => true) + $plain('usermeta', null, 't.user_id', 'user'),
        'wc_customer_lookup' => array('dates' => array('date_last_active' => 't.date_last_active')) + $plain('wc_customer_lookup', 't.customer_id', 't.user_id', 'user'),
    );
    $sql = function ($query, $args) use ($wpdb) { return $args === array() ? $query : $wpdb->prepare($query, $args); };
    $cap = isset($in['scan_cap']) ? max(1, min(100000, (int) $in['scan_cap'])) : 1000;
    $out = array('v' => 1, 'now' => gmdate('Y-m-d H:i:s'), 'unix' => time(), 'tables' => array());
    $found = array('order' => array(), 'user' => array());
    $truncated = false;
    foreach ($specs as $key => $s) {
        $present = $shop || $s['kind'] !== 'order';
        foreach ($s['needs'] as $name) {
            $got = $present ? $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($name))) : null;
            $present = $present && is_string($got) && strtolower($got) === strtolower($name);
        }
        $row = array('present' => $present);
        $fingerprint = isset($s['fingerprint']) ? ', COUNT(*), BIT_XOR(' . $s['fingerprint'] . ')' : '';
        if ($present && !$since) {
            $row += array('max_id' => null, 'max' => array());
            $cols = array($s['id'] === null ? 'NULL' : 'MAX(' . $s['id'] . ')');
            foreach ($s['dates'] as $expr) { $cols[] = 'MAX(' . $expr . ')'; }
            if (isset($s['max_from'])) {
                $m = $wpdb->get_row('SELECT ' . implode(', ', $cols) . ' FROM ' . $s['max_from'], ARRAY_N);
            } else {
                $m = $wpdb->get_row($sql('SELECT ' . implode(', ', $cols) . $fingerprint . ' FROM ' . $s['from'] . ' WHERE ' . $s['where'], $s['args']), ARRAY_N);
            }
            if ($wpdb->last_error !== '' || !is_array($m)) { return $fail($key); }
            $row['max_id'] = $m[0] === null ? null : (int) $m[0];
            $i = 1;
            foreach ($s['dates'] as $name => $expr) { $row['max'][$name] = $m[$i] === null ? null : (string) $m[$i]; $i++; }
            if ($fingerprint !== '') { $row['fingerprint'] = (int) $m[$i] . ':' . (int) $m[$i + 1]; }
        }
        if ($present && $since) {
            $prev = isset($in['since'][$key]) && is_array($in['since'][$key]) ? $in['since'][$key] : array();
            $conds = array();
            $args = $s['args'];
            if ($s['id'] !== null) { $conds[] = $s['id'] . ' > %d'; $args[] = isset($prev['max_id']) ? (int) $prev['max_id'] : 0; }
            foreach ($s['dates'] as $name => $expr) {
                $max = isset($prev['max'][$name]) && is_string($prev['max'][$name]) ? $prev['max'][$name] : null;
                if ($max === null) { $conds[] = $expr . ' IS NOT NULL'; continue; }
                $conds[] = '(' . $expr . ' > %s OR ' . $expr . ' >= %s)';
                $args[] = $max;
                $args[] = isset($s['unix']) ? (string) (int) $in['since_unix'] : (string) $in['since_utc'];
            }
            $where = ' FROM ' . $s['from'] . ' WHERE ' . $s['where'] . ' AND (' . implode(' OR ', $conds) . ')';
            $row['changed'] = (int) $wpdb->get_var($sql('SELECT COUNT(*)' . $where, $args));
            if ($wpdb->last_error !== '') { return $fail($key); }
            $ids = $wpdb->get_col($sql('SELECT DISTINCT ' . $s['entity'] . $where . ' AND ' . $s['entity'] . ' IS NOT NULL ORDER BY 1 DESC LIMIT ' . $cap, $args));
            if ($wpdb->last_error !== '') { return $fail($key); }
            $truncated = $truncated || count($ids) >= $cap;
            foreach ($ids as $id) { $found[$s['kind']][(int) $id] = true; }
            if ($fingerprint !== '') {
                $m = $wpdb->get_row($sql('SELECT 1' . $fingerprint . ' FROM ' . $s['from'] . ' WHERE ' . $s['where'], $s['args']), ARRAY_N);
                if ($wpdb->last_error !== '' || !is_array($m)) { return $fail($key); }
                $row['fingerprint'] = (int) $m[1] . ':' . (int) $m[2];
            }
        }
        $out['tables'][$key] = $row;
    }
    if (!$since) { return $out; }
    $limit = isset($in['limit']) ? max(0, min(1000, (int) $in['limit'])) : 0;
    $maxRoles = isset($in['max_roles']) ? max(0, min(50, (int) $in['max_roles'])) : 0;
    $orders = array_keys($found['order']);
    $users = array_keys($found['user']);
    rsort($orders);
    rsort($users);
    $out += array('orders_total' => count($orders), 'users_total' => count($users), 'truncated' => $truncated, 'orders' => array(), 'users' => array());
    $orders = array_slice($orders, 0, $limit);
    $users = array_slice($users, 0, $limit);
    if ($orders !== array()) {
        $list = implode(',', array_map('intval', $orders));
        $queries = array('SELECT ID, post_type, post_status, post_date_gmt, post_modified_gmt FROM ' . $t($p . 'posts') . ' WHERE ID IN (' . $list . ')');
        if ($out['tables']['wc_orders']['present']) {
            $hposQuery = 'SELECT id, type, status, date_created_gmt, date_updated_gmt FROM ' . $t($p . 'wc_orders') . ' WHERE id IN (' . $list . ')';
            // The authoritative storage last: its rows win.
            $queries = $hpos ? array($queries[0], $hposQuery) : array($hposQuery, $queries[0]);
        }
        $rows = array();
        foreach ($queries as $query) {
            $result = $wpdb->get_results($query, ARRAY_N);
            if ($wpdb->last_error !== '' || !is_array($result)) { return $fail('orders'); }
            foreach ($result as $r) { $rows[(int) $r[0]] = $r; }
        }
        foreach ($orders as $id) {
            $r = isset($rows[$id]) ? $rows[$id] : array(null, null, null, null, null);
            $out['orders'][] = array('id' => $id, 'type' => $r[1], 'status' => $r[2], 'created' => $r[3], 'modified' => $r[4]);
        }
    }
    if ($users !== array()) {
        $list = implode(',', array_map('intval', $users));
        $registered = array();
        $result = $wpdb->get_results('SELECT ID, user_registered FROM ' . $t($wpdb->users) . ' WHERE ID IN (' . $list . ')', ARRAY_N);
        if ($wpdb->last_error !== '' || !is_array($result)) { return $fail('users'); }
        foreach ($result as $r) { $registered[(int) $r[0]] = $r[1]; }
        $roles = array();
        $result = $wpdb->get_results($wpdb->prepare('SELECT user_id, meta_value FROM ' . $t($wpdb->usermeta) . ' WHERE meta_key = %s AND user_id IN (' . $list . ')', $wpdb->get_blog_prefix() . 'capabilities'), ARRAY_N);
        if ($wpdb->last_error !== '' || !is_array($result)) { return $fail('roles'); }
        foreach ($result as $r) {
            $caps = is_string($r[1]) ? @unserialize($r[1], array('allowed_classes' => false)) : false;
            $roles[(int) $r[0]] = is_array($caps) ? array_slice(array_values(array_filter(array_keys(array_filter($caps)), 'is_string')), 0, $maxRoles) : array();
        }
        foreach ($users as $id) {
            $out['users'][] = array('id' => $id, 'registered' => isset($registered[$id]) ? $registered[$id] : null, 'roles' => isset($roles[$id]) ? $roles[$id] : array());
        }
    }
    return $out;
};
echo "\n" . json_encode($wpupRead($in), JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR) . "\n";
PHP;

    /**
     * @param  list<string>  $orderTypes  post types of orders in the posts storage (config)
     */
    public function __construct(
        private WpCliReader $reader,
        private int $timeoutSeconds,
        private array $orderTypes,
        private int $scanCap,
        public int $maxListed,
        public int $maxRoles,
        private int $marginSeconds,
        private int $errorChars,
    ) {}

    /**
     * The WP-CLI arguments of the snippet with $input as its data.
     *
     * @param  array<string, mixed>  $input
     * @return list<string>
     */
    public static function command(array $input): array
    {
        $data = "json_decode(base64_decode('".base64_encode((string) json_encode($input, JSON_THROW_ON_ERROR))."'), true)";

        return ['eval', "\$in = {$data};\n".self::SNIPPET, '--skip-plugins', '--skip-themes'];
    }

    /**
     * @throws WatermarkUnreadable
     */
    public function watermark(SiteDriver $driver): OrderWatermark
    {
        $data = $this->run($driver, ['mode' => 'watermark']);

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
    public function since(SiteDriver $driver, OrderWatermark $watermark): array
    {
        $from = $watermark->readAtUnix - $this->marginSeconds;

        return $this->run($driver, [
            'mode' => 'since',
            'limit' => $this->maxListed,
            'max_roles' => $this->maxRoles,
            'since_utc' => gmdate('Y-m-d H:i:s', $from),
            'since_unix' => $from,
            // A table absent at the copy, or not readable then: every row it has now counts.
            'since' => array_map(static fn (WatermarkTable $t): array => $t->present && $t->readable
                ? ['max_id' => $t->maxId ?? 0, 'max' => (object) $t->max]
                : ['max_id' => 0, 'max' => (object) []], $watermark->tables),
        ]);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<mixed>
     *
     * @throws WatermarkUnreadable
     */
    private function run(SiteDriver $driver, array $input): array
    {
        $input += ['order_types' => $this->orderTypes, 'scan_cap' => $this->scanCap];

        try {
            $result = $driver->wp(self::command($input), $this->timeoutSeconds);
        } catch (DriverException $e) {
            throw new WatermarkUnreadable($this->reader->redact($e->getMessage()), previous: $e);
        }
        if (! $result->successful()) {
            throw new WatermarkUnreadable($this->reader->redact(mb_substr("WP-CLI ha acabat amb el codi {$result->exitCode}: ".trim($result->stderr), 0, $this->errorChars)));
        }

        $data = $this->reader->json($result->stdout);
        if ($data === null || ($data['v'] ?? null) !== 1) {
            throw new WatermarkUnreadable('la resposta del lloc no és JSON vàlid');
        }
        if (isset($data['error'])) {
            throw new WatermarkUnreadable('la consulta d\'una taula ha fallat ('.(is_string($data['error']) && preg_match('/^[a-z_]{1,64}$/D', $data['error']) === 1 ? $data['error'] : '?').')');
        }

        return $data;
    }
}
