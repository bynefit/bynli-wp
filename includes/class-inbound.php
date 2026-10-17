<?php
if (!defined('ABSPATH')) { exit; }

class Bynli_Connect_Inbound {
    const OPT_ENABLED     = 'bynli_connect_inbound_sync';
    const OPT_BACKFILL    = 'bynli_connect_inbound_backfill';
    const EV_PRODUCT      = 'bynli_connect_inbound_product';
    const EV_ORDER        = 'bynli_connect_inbound_order';
    const EV_BACKFILL     = 'bynli_connect_inbound_backfill_step';
    const AJAX_TOGGLE     = 'bynli_connect_inbound_toggle';
    const AJAX_SYNC       = 'bynli_connect_inbound_sync_all';
    const NONCE           = 'bynli_connect_inbound';
    const META_HASH       = '_bynefit_inbound_hash';
    const OPT_NOTICE      = 'bynli_connect_inbound_notice';
    const OPT_PROBLEM     = 'bynli_connect_inbound_problem';
    const PRODUCTS_PATH   = '/api/site-host/shop/products';
    const ORDERS_PATH     = '/api/site-host/shop/orders';
    const PRODUCT_DEBOUNCE = 15;
    const ORDER_DEBOUNCE  = 10;
    const BACKFILL_PAUSE  = 30;
    const BATCH           = 20;
    const MAX_ORDERS      = 250;
    const MAX_ATTEMPTS    = 5;
    const MAX_IMAGES      = 10;
    const MAX_VARIANTS    = 50;
    const MAX_LINES       = 100;
    const BACKOFF         = [60, 300, 900, 3600, 10800];
    const PRODUCT_STATUSES = ['publish', 'draft', 'pending', 'private', 'future', 'trash'];
    const SKIP_ORDER_STATUSES = ['auto-draft', 'checkout-draft', 'trash'];
    const PRODUCT_OK      = ['created', 'applied', 'unchanged', 'stale', 'archived', 'ignored'];
    const ORDER_OK        = ['created', 'applied', 'unchanged', 'stale'];

    private static $muted = 0;
    private static $gate = null;

    public function __construct() {
        add_action('woocommerce_new_product', [$this, 'on_product'], 20, 1);
        add_action('woocommerce_update_product', [$this, 'on_product'], 20, 1);
        add_action('woocommerce_new_product_variation', [$this, 'on_variation'], 20, 1);
        add_action('woocommerce_update_product_variation', [$this, 'on_variation'], 20, 1);
        add_action('woocommerce_product_set_stock', [$this, 'on_stock'], 20, 1);
        add_action('woocommerce_variation_set_stock', [$this, 'on_stock'], 20, 1);
        add_action('wp_trash_post', [$this, 'on_post'], 20, 1);
        add_action('untrashed_post', [$this, 'on_post'], 20, 1);
        add_action('before_delete_post', [$this, 'on_post'], 20, 1);

        add_action('woocommerce_new_order', [$this, 'on_order'], 20, 1);
        add_action('woocommerce_update_order', [$this, 'on_order'], 20, 1);
        add_action('woocommerce_order_status_changed', [$this, 'on_order'], 20, 1);
        add_action('woocommerce_order_refunded', [$this, 'on_order'], 20, 1);

        add_action(self::EV_PRODUCT, [$this, 'run_product'], 10, 2);
        add_action(self::EV_ORDER, [$this, 'run_order'], 10, 2);
        add_action(self::EV_BACKFILL, [$this, 'run_backfill'], 10, 3);

        add_action('admin_init', [$this, 'ensure_notice'], 5);
        add_action('admin_init', [$this, 'maybe_autostart']);
        add_action('admin_notices', [$this, 'render_notice']);
        add_action('wp_ajax_' . self::AJAX_TOGGLE, [$this, 'handle_toggle']);
        add_action('wp_ajax_' . self::AJAX_SYNC, [$this, 'handle_sync']);
        add_filter('woocommerce_duplicate_product_exclude_meta', [__CLASS__, 'exclude_duplicate_meta']);
    }

    public static function exclude_duplicate_meta($meta) {
        return array_merge((array) $meta, [self::META_HASH]);
    }

    public static function mute() {
        self::$muted++;
    }

    public static function unmute() {
        self::$muted = max(0, self::$muted - 1);
    }

    public static function is_managed() {
        return defined('BYNLI_CONTROL_PLANE_SECRET') && BYNLI_CONTROL_PLANE_SECRET;
    }

    public static function enabled() {
        $v = get_option(self::OPT_ENABLED, null);
        if ($v === null || $v === false || $v === '') {
            return self::is_managed() && in_array(get_option(self::OPT_NOTICE, ''), ['shown', 'seen'], true);
        }
        return (string) $v === '1';
    }

    public static function active() {
        if (self::$gate === null) {
            self::$gate = self::enabled()
                && Bynli_Connect_Settings::key() !== ''
                && function_exists('wc_get_product')
                && class_exists('WooCommerce');
        }
        return self::$gate;
    }

    public static function reset_gate() {
        self::$gate = null;
    }

    private static function problem($code, $scope) {
        update_option(self::OPT_PROBLEM, ['code' => (string) $code, 'scope' => (string) $scope, 'at' => time()], false);
    }

    private static function clear_problem() {
        if (get_option(self::OPT_PROBLEM, '') !== '') {
            delete_option(self::OPT_PROBLEM);
        }
    }

    public function on_product($id) {
        $this->queue_product((int) $id);
    }

    public function on_variation($id) {
        $parent = (int) wp_get_post_parent_id((int) $id);
        if ($parent > 0) {
            $this->queue_product($parent);
        }
    }

    public function on_stock($product) {
        if (!is_object($product) || !method_exists($product, 'get_id')) {
            return;
        }
        $parent = method_exists($product, 'get_parent_id') ? (int) $product->get_parent_id() : 0;
        $this->queue_product($parent > 0 ? $parent : (int) $product->get_id());
    }

    public function on_post($post_id) {
        if (get_post_type((int) $post_id) === 'product') {
            $this->queue_product((int) $post_id);
        }
    }

    public function on_order($order_id) {
        $order_id = (int) $order_id;
        if ($order_id <= 0 || self::$muted > 0 || !self::active()) {
            return;
        }
        if (!wp_next_scheduled(self::EV_ORDER, [$order_id, 0])) {
            wp_schedule_single_event(time() + self::ORDER_DEBOUNCE, self::EV_ORDER, [$order_id, 0]);
        }
    }

    private function queue_product($id) {
        if ($id <= 0 || self::$muted > 0 || !self::active()) {
            return;
        }
        if (!wp_next_scheduled(self::EV_PRODUCT, [$id, 0])) {
            wp_schedule_single_event(time() + self::PRODUCT_DEBOUNCE, self::EV_PRODUCT, [$id, 0]);
        }
    }

    public function run_product($id, $attempt = 0) {
        if (!self::active()) {
            return;
        }
        $id      = (int) $id;
        $attempt = (int) $attempt;
        $event   = self::product_event($id);
        if ($event === null) {
            return;
        }
        if (!empty($event['_hash']) && get_post_meta($id, self::META_HASH, true) === $event['_hash']) {
            return;
        }
        if (($event['product']['status'] ?? '') === 'trash' && empty($event['bynefit_product_id']) && get_post_meta($id, self::META_HASH, true) === '') {
            return;
        }
        $res = self::deliver(self::PRODUCTS_PATH, 'events', [$event], false, self::PRODUCT_OK);
        if ($res['retry']) {
            self::retry(self::EV_PRODUCT, [$id], $attempt);
        }
    }

    public function run_order($id, $attempt = 0) {
        if (!self::active()) {
            return;
        }
        $id      = (int) $id;
        $attempt = (int) $attempt;
        $event   = self::order_event($id);
        if ($event === null) {
            return;
        }
        $res = self::deliver(self::ORDERS_PATH, 'orders', [$event], false, self::ORDER_OK);
        if ($res['retry']) {
            self::retry(self::EV_ORDER, [$id], $attempt);
        }
    }

    private static function retry($hook, array $args, $attempt) {
        $next = $attempt + 1;
        if ($next >= self::MAX_ATTEMPTS) {
            error_log('[Bynli Connect] inbound sync gave up after ' . self::MAX_ATTEMPTS . ' attempts (' . $hook . ' ' . implode(',', $args) . ')');
            $held = get_option(self::OPT_PROBLEM, '');
            if (!is_array($held) || ($held['code'] ?? '') !== 'conflict') {
                self::problem('gave_up', $hook === self::EV_ORDER ? 'orders' : 'products');
            }
            return;
        }
        $delay = self::BACKOFF[min($attempt, count(self::BACKOFF) - 1)];
        wp_schedule_single_event(time() + $delay, $hook, array_merge($args, [$next]));
    }

    private static function now_ms() {
        return (int) floor(microtime(true) * 1000);
    }

    private static function modified_ms($object) {
        $d = is_object($object) && method_exists($object, 'get_date_modified') ? $object->get_date_modified('edit') : null;
        if ($d && is_object($d) && method_exists($d, 'getTimestamp') && (int) $d->getTimestamp() > 0) {
            return ((int) $d->getTimestamp()) * 1000;
        }
        return self::now_ms();
    }

    private static function cents($v) {
        return is_numeric($v) ? (int) round(((float) $v) * 100) : 0;
    }

    private static function money($v) {
        return is_numeric($v) ? round((float) $v, 2) : 0.0;
    }

    private static function stamp($v) {
        if (!$v || !is_object($v) || !method_exists($v, 'getTimestamp')) {
            return null;
        }
        return gmdate('Y-m-d\TH:i:s\Z', (int) $v->getTimestamp());
    }

    private static function variation_label($v) {
        $parts = [];
        foreach ((array) $v->get_attributes() as $name => $value) {
            $value = (string) $value;
            if ($value === '') {
                $parts[] = 'Any';
                continue;
            }
            if (taxonomy_exists($name)) {
                $term = get_term_by('slug', $value, $name);
                if ($term && !is_wp_error($term)) {
                    $value = $term->name;
                }
            }
            $parts[] = $value;
        }
        return implode(' / ', $parts);
    }

    private static function stock_of($p, $variation) {
        if ($p->get_manage_stock('edit') === true) {
            return max(0, (int) $p->get_stock_quantity('edit'));
        }
        if ($p->get_stock_status('edit') === 'outofstock') {
            return 0;
        }
        return $variation ? null : -1;
    }

    private static function image_urls($p, $id) {
        $ids = [];
        if ($p->get_image_id('edit')) {
            $ids[] = (int) $p->get_image_id('edit');
        }
        foreach ((array) $p->get_gallery_image_ids('edit') as $gid) {
            $ids[] = (int) $gid;
        }
        $reverse = [];
        $stored  = get_post_meta($id, Bynli_Connect_Products::META_IMAGES, true);
        if (is_array($stored) && (int) get_post_meta($id, Bynli_Connect_Products::META_OWNER, true) === $id) {
            foreach ($stored as $url => $att) {
                $reverse[(int) $att] = (string) $url;
            }
        }
        $urls = [];
        foreach ($ids as $att) {
            $url = isset($reverse[$att]) ? $reverse[$att] : (string) wp_get_attachment_url($att);
            if ($url !== '' && strpos($url, 'https://') === 0 && !in_array($url, $urls, true)) {
                $urls[] = $url;
            }
            if (count($urls) >= self::MAX_IMAGES) {
                break;
            }
        }
        return ['urls' => $urls, 'had' => count($ids) > 0];
    }

    public static function product_event($id) {
        $id = (int) $id;
        $p  = wc_get_product($id);
        if (!$p) {
            return ['_id' => $id, '_hash' => null, 'op' => 'delete', 'woo_id' => $id, 'version' => self::now_ms()];
        }
        if ($p->is_type('variation')) {
            return null;
        }
        $status = (string) get_post_status($id);
        if ($status === '' || $status === 'auto-draft' || $status === 'inherit') {
            return null;
        }
        if (!in_array($status, self::PRODUCT_STATUSES, true)) {
            $status = 'draft';
        }

        $variants = [];
        if ($p->is_type('variable')) {
            foreach ((array) $p->get_children() as $child_id) {
                $v = wc_get_product($child_id);
                if (!$v || !$v->is_type('variation') || $v->get_status('edit') === 'private') {
                    continue;
                }
                $label = self::variation_label($v);
                if ($label === '') {
                    continue;
                }
                $variants[] = [
                    'label' => $label,
                    'sku'   => (string) $v->get_sku('edit'),
                    'price' => self::money($v->get_price('edit')),
                    'stock' => self::stock_of($v, true),
                ];
                if (count($variants) >= self::MAX_VARIANTS) {
                    break;
                }
            }
        }

        if ($variants) {
            $price   = min(array_column($variants, 'price'));
            $compare = null;
        } else {
            $price   = self::money($p->get_price('edit'));
            $regular = self::money($p->get_regular_price('edit'));
            $compare = ($price > 0 && $regular > $price) ? $regular : null;
        }

        $category = '';
        $default  = (int) get_option('default_product_cat');
        foreach ((array) $p->get_category_ids() as $cat_id) {
            if ((int) $cat_id === $default) {
                continue;
            }
            $term = get_term((int) $cat_id, 'product_cat');
            if ($term && !is_wp_error($term)) {
                $category = (string) $term->name;
                break;
            }
        }

        $description = (string) $p->get_description('edit');
        if (trim(wp_strip_all_tags($description)) === '') {
            $description = (string) $p->get_short_description('edit');
        }

        $low = $p->get_low_stock_amount('edit');
        $img = self::image_urls($p, $id);

        $product = [
            'name'                => (string) $p->get_name('edit'),
            'description'         => mb_substr($description, 0, 6000),
            'status'              => $status,
            'price'               => $price,
            'compare_at_price'    => $compare,
            'sku'                 => (string) $p->get_sku('edit'),
            'category'            => $category,
            'stock_quantity'      => self::stock_of($p, false),
            'low_stock_threshold' => is_numeric($low) ? (int) $low : null,
            'variants'            => $variants,
        ];
        if ($img['urls'] || !$img['had']) {
            $product['images'] = $img['urls'];
        }

        $marker = (int) $p->get_meta(Bynli_Connect_Products::META_ID);
        $event  = ['_id' => $id, 'op' => 'upsert', 'woo_id' => $id, 'version' => self::modified_ms($p), 'product' => $product];
        if ($marker > 0) {
            $event['bynefit_product_id'] = $marker;
        }
        $event['_hash'] = sha1((string) wp_json_encode([$product, $marker]));
        return $event;
    }

    private static function address($order, $type) {
        $a = (array) $order->get_address($type);
        $out = [];
        foreach (['first_name', 'last_name', 'company', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country', 'phone', 'email'] as $f) {
            if (isset($a[$f]) && is_scalar($a[$f]) && (string) $a[$f] !== '') {
                $out[$f] = (string) $a[$f];
            }
        }
        return $out;
    }

    public static function order_event($id) {
        $id    = (int) $id;
        $order = wc_get_order($id);
        if (!$order instanceof WC_Order || $order->get_type() !== 'shop_order') {
            return null;
        }
        $status = (string) $order->get_status();
        if ($status === '' || in_array($status, self::SKIP_ORDER_STATUSES, true)) {
            return null;
        }

        $billing  = self::address($order, 'billing');
        $shipping = self::address($order, 'shipping');
        $name     = trim(($billing['first_name'] ?? '') . ' ' . ($billing['last_name'] ?? ''));
        if ($name === '') {
            $name = trim(($shipping['first_name'] ?? '') . ' ' . ($shipping['last_name'] ?? ''));
        }
        if ($name === '') {
            $name = (string) ($billing['company'] ?? '');
        }

        $lines = [];
        foreach ($order->get_items('line_item') as $item_id => $item) {
            if (!$item instanceof WC_Order_Item_Product) {
                continue;
            }
            $qty     = max(1, (int) $item->get_quantity());
            $total   = self::cents($item->get_total());
            $product = $item->get_product();
            $lines[] = [
                'woo_item_id'      => (int) $item_id,
                'woo_product_id'   => (int) $item->get_product_id(),
                'woo_variation_id' => (int) $item->get_variation_id(),
                'name'             => (string) $item->get_name(),
                'sku'              => $product ? (string) $product->get_sku() : '',
                'variation'        => ($product && $item->get_variation_id()) ? self::variation_label($product) : '',
                'quantity'         => $qty,
                'unit_cents'       => (int) round($total / $qty),
                'total_cents'      => $total,
            ];
            if (count($lines) >= self::MAX_LINES) {
                break;
            }
        }

        $ref = (string) $order->get_meta(Bynli_Connect_Woo::REF_META);
        $event = [
            '_id'             => $id,
            '_hash'           => null,
            'woo_id'          => $id,
            'version'         => self::modified_ms($order),
            'number'          => (string) $order->get_order_number(),
            'status'          => $status,
            'currency'        => (string) $order->get_currency(),
            'placed_at'       => self::stamp($order->get_date_created()),
            'paid_at'         => self::stamp($order->get_date_paid()),
            'modified_at'     => self::stamp($order->get_date_modified()),
            'totals'          => [
                'total'    => self::cents($order->get_total()),
                'subtotal' => self::cents($order->get_subtotal()),
                'shipping' => self::cents($order->get_shipping_total()),
                'tax'      => self::cents($order->get_total_tax()),
                'discount' => self::cents($order->get_total_discount()),
                'refunded' => self::cents($order->get_total_refunded()),
            ],
            'payment'         => [
                'method'         => (string) $order->get_payment_method(),
                'title'          => (string) $order->get_payment_method_title(),
                'transaction_id' => (string) $order->get_transaction_id(),
            ],
            'customer'        => [
                'name'  => $name,
                'email' => (string) $order->get_billing_email(),
                'phone' => (string) $order->get_billing_phone(),
                'note'  => (string) $order->get_customer_note(),
            ],
            'billing'         => $billing,
            'shipping'        => $shipping,
            'shipping_method' => (string) $order->get_shipping_method(),
            'lines'           => $lines,
        ];
        if (preg_match('/^wco_[a-f0-9]{32}$/', $ref)) {
            $event['checkout_ref'] = $ref;
        }
        return $event;
    }

    public static function deliver($path, $field, array $events, $resync, array $ok_statuses) {
        $out = ['synced' => 0, 'failed' => 0, 'retry' => false, 'fatal' => false, 'code' => ''];
        if (!$events) {
            return $out;
        }
        $scope   = $field === 'orders' ? 'orders' : 'products';
        $payload = [];
        foreach ($events as $e) {
            unset($e['_id'], $e['_hash']);
            $payload[] = $e;
        }
        $idem = function_exists('wp_generate_uuid4') ? wp_generate_uuid4() : md5(uniqid((string) mt_rand(), true));
        $res  = Bynli_Connect_Api::post_v2($path, [$field => $payload, 'resync' => (bool) $resync], $idem);
        if (empty($res['ok'])) {
            $status = (int) ($res['status'] ?? 0);
            $code   = (string) ($res['error'] ?? 'unknown');
            if (($status === 413 || $code === 'body_too_large') && count($events) > 1) {
                $half = (int) ceil(count($events) / 2);
                $a    = self::deliver($path, $field, array_slice($events, 0, $half), $resync, $ok_statuses);
                $b    = self::deliver($path, $field, array_slice($events, $half), $resync, $ok_statuses);
                return [
                    'synced' => $a['synced'] + $b['synced'],
                    'failed' => $a['failed'] + $b['failed'],
                    'retry'  => $a['retry'] || $b['retry'],
                    'fatal'  => $a['fatal'] || $b['fatal'],
                    'code'   => $a['code'] !== '' ? $a['code'] : $b['code'],
                ];
            }
            $out['retry']  = $code === 'transport' || $status === 0 || $status === 429 || $status >= 500;
            $out['fatal']  = !$out['retry'];
            $out['code']   = $code;
            $out['failed'] = count($events);
            error_log('[Bynli Connect] inbound sync request failed: ' . $code);
            if ($out['fatal']) {
                self::problem($code, $scope);
            }
            return $out;
        }
        $results = isset($res['data']['results']) && is_array($res['data']['results']) ? array_values($res['data']['results']) : [];
        foreach ($events as $i => $event) {
            $r      = isset($results[$i]) && is_array($results[$i]) ? $results[$i] : [];
            $status = (string) ($r['status'] ?? 'error');
            if (in_array($status, $ok_statuses, true)) {
                $out['synced']++;
                if ($field === 'events' && in_array($status, ['created', 'applied', 'unchanged'], true)) {
                    $id = (int) $event['_id'];
                    if (!empty($event['_hash'])) {
                        update_post_meta($id, self::META_HASH, (string) $event['_hash']);
                    }
                    $bynefit_id = (int) ($r['product_id'] ?? 0);
                    if ($bynefit_id > 0 && get_post_meta($id, Bynli_Connect_Products::META_ID, true) === '') {
                        update_post_meta($id, Bynli_Connect_Products::META_ID, (string) $bynefit_id);
                    }
                }
                continue;
            }
            $out['failed']++;
            if ($status === 'conflict') {
                $out['retry'] = true;
                $out['code']  = 'conflict';
                self::problem('conflict', $scope);
            } elseif ($status === 'error') {
                $out['retry'] = true;
            } else {
                $out['code'] = (string) ($r['error'] ?? $status);
                self::problem($out['code'], $scope);
            }
            error_log('[Bynli Connect] inbound sync item ' . (int) $event['_id'] . ' not applied: ' . $status . ' ' . (string) ($r['error'] ?? ''));
        }
        if ($out['failed'] === 0) {
            self::clear_problem();
        }
        return $out;
    }
    public static function backfill_step($phase, $offset) {
        $out = ['phase' => $phase, 'offset' => (int) $offset, 'done' => false, 'synced' => 0, 'failed' => 0, 'retry' => false, 'fatal' => false, 'code' => ''];
        $offset = max(0, (int) $offset);
        if ($phase === 'products') {
            $q = wc_get_products([
                'status'   => ['publish', 'draft', 'pending', 'private', 'future'],
                'limit'    => self::BATCH,
                'offset'   => $offset,
                'orderby'  => 'ID',
                'order'    => 'ASC',
                'paginate' => true,
                'return'   => 'ids',
            ]);
            $events = [];
            foreach ((array) $q->products as $pid) {
                $e = self::product_event((int) $pid);
                if ($e !== null) {
                    $events[] = $e;
                }
            }
            $res = self::deliver(self::PRODUCTS_PATH, 'events', $events, true, self::PRODUCT_OK);
            $out = array_merge($out, $res);
            if ($res['retry'] || $res['fatal']) {
                return $out;
            }
            $next = $offset + self::BATCH;
            if ($next >= (int) $q->total) {
                $out['phase']  = 'orders';
                $out['offset'] = 0;
            } else {
                $out['offset'] = $next;
            }
            return $out;
        }

        $limit = min(self::BATCH, self::MAX_ORDERS - $offset);
        if ($limit <= 0) {
            $out['done'] = true;
            return $out;
        }
        $q = wc_get_orders([
            'type'     => 'shop_order',
            'limit'    => $limit,
            'offset'   => $offset,
            'orderby'  => 'date',
            'order'    => 'DESC',
            'paginate' => true,
            'return'   => 'ids',
        ]);
        $events = [];
        foreach ((array) $q->orders as $oid) {
            $e = self::order_event((int) $oid);
            if ($e !== null) {
                $events[] = $e;
            }
        }
        $res = self::deliver(self::ORDERS_PATH, 'orders', $events, true, self::ORDER_OK);
        $out = array_merge($out, $res);
        if ($res['retry'] || $res['fatal']) {
            return $out;
        }
        $next = $offset + $limit;
        if ($next >= min((int) $q->total, self::MAX_ORDERS)) {
            $out['done'] = true;
        } else {
            $out['offset'] = $next;
        }
        return $out;
    }

    public function ensure_notice() {
        if (!self::is_managed() || get_option(self::OPT_NOTICE, '') !== '') {
            return;
        }
        if (!is_admin() || wp_doing_ajax() || wp_doing_cron() || !current_user_can('manage_options')) {
            return;
        }
        $v = get_option(self::OPT_ENABLED, null);
        if ($v === null || $v === false || $v === '') {
            update_option(self::OPT_NOTICE, 'pending', false);
            self::reset_gate();
        }
    }

    public function render_notice() {
        $state = get_option(self::OPT_NOTICE, '');
        if (!current_user_can('manage_options') || !in_array($state, ['pending', 'shown'], true)) {
            return;
        }
        $url = add_query_arg(['page' => Bynli_Connect_Settings::MENU_SLUG, 'section' => 'connection'], admin_url('options-general.php'));
        echo '<div class="notice notice-info"><p><strong>Bynefit Connect</strong> is now sending your WooCommerce products and orders to your Bynefit Space. Orders include each customer\'s name, email, phone number, billing and shipping addresses, order note and the payment transaction id. <a href="' . esc_url($url) . '">Review or turn this off</a>.</p></div>';
        if ($state === 'pending') {
            update_option(self::OPT_NOTICE, 'shown', false);
        }
    }

    public function maybe_autostart() {
        if (get_option(self::OPT_BACKFILL, '') !== '' || !self::active()) {
            return;
        }
        update_option(self::OPT_BACKFILL, 'queued', false);
        wp_schedule_single_event(time() + 60, self::EV_BACKFILL, ['products', 0, 0]);
    }

    public function run_backfill($phase = 'products', $offset = 0, $attempt = 0) {
        if (!self::active() || !in_array($phase, ['products', 'orders'], true)) {
            return;
        }
        $attempt = (int) $attempt;
        $step    = self::backfill_step($phase, (int) $offset);
        if ($step['fatal']) {
            update_option(self::OPT_BACKFILL, 'failed', false);
            return;
        }
        if ($step['retry']) {
            $next = $attempt + 1;
            if ($next >= self::MAX_ATTEMPTS) {
                update_option(self::OPT_BACKFILL, 'failed', false);
                self::problem('gave_up', 'products');
                return;
            }
            wp_schedule_single_event(time() + self::BACKOFF[min($attempt, count(self::BACKOFF) - 1)], self::EV_BACKFILL, [$phase, (int) $offset, $next]);
            return;
        }
        if ($step['done']) {
            update_option(self::OPT_BACKFILL, 'done', false);
            return;
        }
        wp_schedule_single_event(time() + self::BACKFILL_PAUSE, self::EV_BACKFILL, [$step['phase'], (int) $step['offset'], 0]);
    }

    public function handle_toggle() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Forbidden.'], 403);
        }
        if (!check_ajax_referer(self::NONCE, '_wpnonce', false)) {
            wp_send_json_error(['message' => 'Security check failed. Reload and try again.'], 403);
        }
        $on = isset($_POST['enabled']) && (string) wp_unslash($_POST['enabled']) === '1';
        update_option(self::OPT_ENABLED, $on ? '1' : '0');
        update_option(self::OPT_NOTICE, 'seen', false);
        self::reset_gate();
        if ($on && get_option(self::OPT_BACKFILL, '') === '' && self::active()) {
            update_option(self::OPT_BACKFILL, 'queued', false);
            wp_schedule_single_event(time() + 60, self::EV_BACKFILL, ['products', 0, 0]);
        }
        wp_send_json_success(['enabled' => $on]);
    }

    public function handle_sync() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Forbidden.'], 403);
        }
        if (!check_ajax_referer(self::NONCE, '_wpnonce', false)) {
            wp_send_json_error(['message' => 'Security check failed. Reload and try again.'], 403);
        }
        if (Bynli_Connect_Settings::key() === '') {
            wp_send_json_error(['message' => 'Add your site-host key in Connection first.'], 400);
        }
        if (!function_exists('wc_get_products') || !class_exists('WooCommerce')) {
            wp_send_json_error(['message' => 'WooCommerce is not active on this site.'], 409);
        }
        $phase  = isset($_POST['phase']) ? sanitize_key(wp_unslash($_POST['phase'])) : 'products';
        $offset = isset($_POST['offset']) ? absint(wp_unslash($_POST['offset'])) : 0;
        if (!in_array($phase, ['products', 'orders'], true)) {
            wp_send_json_error(['message' => 'Unknown step.'], 400);
        }
        @set_time_limit(60);
        $step = self::backfill_step($phase, $offset);
        if ($step['fatal']) {
            update_option(self::OPT_BACKFILL, 'failed', false);
            wp_send_json_error(['message' => 'Bynefit did not accept this batch (' . $step['code'] . '). Check Bynefit Connect and try again, or contact support.'], 502);
        }
        if ($step['retry'] && $step['code'] === 'conflict') {
            wp_send_json_error(['message' => 'A product was changed in Bynefit at the same time. Bynefit\'s newer edit goes to your store first. Run the sync again in a few minutes.'], 409);
        }
        if ($step['retry']) {
            wp_send_json_error(['message' => 'Bynefit could not be reached. Try again shortly.'], 502);
        }
        if ($step['done']) {
            update_option(self::OPT_BACKFILL, 'done', false);
        }
        wp_send_json_success([
            'phase'  => $step['phase'],
            'offset' => $step['offset'],
            'done'   => $step['done'],
            'synced' => $step['synced'],
            'failed' => $step['failed'],
        ]);
    }

    public static function clear_schedules() {
        $cron = _get_cron_array();
        if (!is_array($cron)) {
            return;
        }
        foreach ($cron as $ts => $hooks) {
            foreach ([self::EV_PRODUCT, self::EV_ORDER, self::EV_BACKFILL] as $hook) {
                if (empty($hooks[$hook])) {
                    continue;
                }
                foreach ($hooks[$hook] as $event) {
                    wp_unschedule_event($ts, $hook, $event['args']);
                }
            }
        }
    }

    public static function render_card() {
        $woo     = class_exists('WooCommerce');
        $on      = self::enabled();
        $nonce   = wp_create_nonce(self::NONCE);
        $state   = (string) get_option(self::OPT_BACKFILL, '');
        $notice  = in_array(get_option(self::OPT_NOTICE, ''), ['pending', 'shown'], true);
        $problem = get_option(self::OPT_PROBLEM, '');
        if ($notice) {
            update_option(self::OPT_NOTICE, 'seen', false);
        }
        ?>
        <section class="bcn-card">
            <div class="bcn-card-head">
                <h2>Store sync</h2>
                <span class="bcn-card-sub">WooCommerce products and orders to Bynefit</span>
            </div>
            <div class="bcn-card-body">
                <?php if (!$woo): ?>
                    <div class="bcn-note warn">
                        <span class="dashicons dashicons-warning" aria-hidden="true"></span>
                        <span>WooCommerce is not active on this site, so there is nothing to sync yet.</span>
                    </div>
                <?php endif; ?>
                <?php if ($notice): ?>
                    <div class="bcn-note warn">
                        <span class="dashicons dashicons-warning" aria-hidden="true"></span>
                        <span>Store sync was switched on for this Bynefit-hosted site and has started sending your products and orders, including customer names, emails, phone numbers, addresses, order notes and payment transaction ids, to your Bynefit Space. You can turn it off below.</span>
                    </div>
                <?php endif; ?>
                <?php if (is_array($problem)): ?>
                    <div class="bcn-note down">
                        <span class="dashicons dashicons-warning" aria-hidden="true"></span>
                        <?php if (($problem['code'] ?? '') === 'conflict'): ?>
                            <span>A product was changed in Bynefit and in your store at the same time. Bynefit's newer edit is sent to your store first, then this store change is sent again automatically. If it keeps happening, use Sync all products now.</span>
                        <?php else: ?>
                        <span>The last <?php echo esc_html((string) ($problem['scope'] ?? 'sync')); ?> sync did not finish (<?php echo esc_html((string) ($problem['code'] ?? 'error')); ?>, <?php echo esc_html(wp_date('M j, g:i a', (int) ($problem['at'] ?? time()))); ?>). Use Sync all products now to try again.</span>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
                <div class="bcn-field">
                    <label class="bcn-label" for="bcn-inbound">Send store changes to Bynefit</label>
                    <select class="bcn-input sans" id="bcn-inbound" data-bcn-inbound data-nonce="<?php echo esc_attr($nonce); ?>">
                        <option value="1" <?php selected($on, true); ?>>On</option>
                        <option value="0" <?php selected($on, false); ?>>Off</option>
                    </select>
                    <p class="bcn-hint">When on, products you add, edit, trash or restock here, and orders customers place, appear in your Bynefit Space. Orders include the customer's name, email, phone number, billing and shipping addresses, order note and payment transaction id. Products removed here are archived in Bynefit, not deleted.</p>
                </div>
                <?php if ($woo && Bynli_Connect_Settings::key() !== ''): ?>
                    <div class="bcn-field">
                        <button type="button" class="bcn-btn sm" data-bcn-inbound-sync data-nonce="<?php echo esc_attr($nonce); ?>">
                            <span class="dashicons dashicons-update" aria-hidden="true"></span> Sync all products now
                        </button>
                        <p class="bcn-hint">Sends every product and your most recent orders to Bynefit. Safe to run again.<?php echo $state === 'failed' ? ' The automatic first sync could not finish, so run it here.' : ''; ?></p>
                    </div>
                <?php endif; ?>
                <div class="bcn-note" data-role="inbound-status" aria-live="polite" hidden>
                    <span class="dashicons" data-role="ico" aria-hidden="true"></span>
                    <span data-role="msg"></span>
                </div>
            </div>
        </section>
        <?php
    }
}
