<?php
if (!defined('ABSPATH')) { exit; }

class Bynli_Connect_Product_Busy extends Exception {}

class Bynli_Connect_Product_Trashed extends Exception {}

class Bynli_Connect_Product_Refused extends Exception {
    public $violations;

    public function __construct(array $violations) {
        parent::__construct('refused');
        $this->violations = $violations;
    }
}

class Bynli_Connect_Products {
    const API_VERSION   = 1;
    const META_ID       = '_bynefit_product_id';
    const META_HASH     = '_bynefit_product_hash';
    const META_IMAGES   = '_bynefit_image_map';
    const META_OWNER    = '_bynefit_image_owner';
    const META_SEQ      = '_bynefit_sent_at';
    const TOMB          = 'bynefit_product_removed_';
    const MAX_IMAGES    = 10;
    const MAX_VARIANTS  = 50;
    const MAX_NAME      = 160;
    const MAX_DESC      = 5000;
    const MAX_PRICE     = 1000000.0;
    const MAX_STOCK     = 1000000;
    const MAX_IMG_BYTES = 8388608;
    const MAX_IMG_SIDE  = 6000;
    const MAX_IMG_AREA  = 12000000;
    const ATTR_NAME     = 'Option';
    const IMG_BUDGET_SECONDS = 15;

    public static function capabilities() {
        return [
            'ok'                  => true,
            'product_api'         => self::API_VERSION,
            'woocommerce'         => self::woo_ready(),
            'woocommerce_version' => defined('WC_VERSION') ? (string) WC_VERSION : null,
            'plugin_version'      => BYNLI_CONNECT_VERSION,
        ];
    }

    public static function woo_ready() {
        return class_exists('WooCommerce') && function_exists('wc_get_product') && class_exists('WC_Product_Simple');
    }

    public static function handle_capabilities(WP_REST_Request $request) {
        return new WP_REST_Response(self::capabilities(), 200);
    }

    public static function handle_product(WP_REST_Request $request) {
        $body = json_decode((string) $request->get_body(), true);
        if (!is_array($body)) {
            return new WP_Error('invalid_json', 'Body is not valid JSON.', ['status' => 400]);
        }
        if (!self::woo_ready()) {
            return new WP_Error('woocommerce_missing', 'WooCommerce is not active on this site.', ['status' => 409]);
        }
        $clean = self::validate(is_array($body['product'] ?? null) ? $body['product'] : []);
        if ($clean['violations']) {
            return self::refused($clean['violations']);
        }
        $sent_at = self::whole($body['sent_at'] ?? null);
        if ($sent_at === null || $sent_at <= 0) {
            return self::refused([['field' => 'sent_at', 'message' => 'Provide the time this request was sent.']]);
        }
        try {
            $out = self::upsert($clean['product'], $sent_at);
        } catch (WC_Data_Exception $e) {
            $code = $e->getErrorCode();
            if (strpos((string) $code, 'sku') !== false) {
                return self::refused([['field' => 'sku', 'message' => 'Another product on this site already uses that SKU.']]);
            }
            error_log('[Bynli Connect] product upsert refused: ' . $e->getMessage());
            return self::refused([['field' => 'product', 'message' => 'WooCommerce refused this product.']]);
        } catch (Bynli_Connect_Product_Refused $e) {
            return self::refused($e->violations);
        } catch (Bynli_Connect_Product_Trashed $e) {
            return new WP_Error('product_trashed', 'This product is in the store trash. Restore it there to keep it in sync.', ['status' => 409]);
        } catch (Bynli_Connect_Product_Busy $e) {
            return new WP_Error('busy', 'This product is being saved right now. Try again shortly.', ['status' => 503]);
        } catch (Throwable $e) {
            error_log('[Bynli Connect] product upsert failed: ' . $e->getMessage());
            return new WP_Error('product_failed', 'The product could not be saved.', ['status' => 500]);
        }
        return new WP_REST_Response($out, 200);
    }

    public static function handle_remove(WP_REST_Request $request) {
        $body = json_decode((string) $request->get_body(), true);
        if (!is_array($body)) {
            return new WP_Error('invalid_json', 'Body is not valid JSON.', ['status' => 400]);
        }
        $id = self::whole($body['id'] ?? null);
        if ($id === null || $id <= 0) {
            return self::refused([['field' => 'id', 'message' => 'Provide the Bynefit product id.']]);
        }
        if (!self::woo_ready()) {
            return new WP_Error('woocommerce_missing', 'WooCommerce is not active on this site.', ['status' => 409]);
        }
        $sent_at = self::whole($body['sent_at'] ?? null);
        if ($sent_at === null || $sent_at <= 0) {
            return self::refused([['field' => 'sent_at', 'message' => 'Provide the time this request was sent.']]);
        }
        try {
            return self::with_lock($id, function () use ($id, $sent_at) {
                $product = self::find($id);
                if (!$product) {
                    if ((int) get_option(self::TOMB . $id, 0) < $sent_at) {
                        update_option(self::TOMB . $id, $sent_at, false);
                    }
                    return new WP_REST_Response(['ok' => true, 'removed' => false], 200);
                }
                if (get_post_status($product->get_id()) === 'trash') {
                    return new WP_REST_Response(['ok' => true, 'removed' => false], 200);
                }
                if (self::is_stale($product->get_id(), $sent_at)) {
                    return new WP_REST_Response(['ok' => true, 'removed' => false, 'stale' => true], 200);
                }
                $product->set_status('draft');
                $product->save();
                self::stamp($product->get_id(), $sent_at);
                return new WP_REST_Response(['ok' => true, 'removed' => true, 'id' => $product->get_id()], 200);
            });
        } catch (Bynli_Connect_Product_Busy $e) {
            return new WP_Error('busy', 'This product is being saved right now. Try again shortly.', ['status' => 503]);
        } catch (Throwable $e) {
            error_log('[Bynli Connect] product remove failed: ' . $e->getMessage());
            return new WP_Error('product_failed', 'The product could not be removed.', ['status' => 500]);
        }
    }

    public static function validate($in) {
        $v = [];
        $p = [];

        $id = self::whole($in['id'] ?? null);
        if ($id === null || $id <= 0) {
            $v[] = ['field' => 'id', 'message' => 'Provide the Bynefit product id.'];
            $id = 0;
        }
        $p['id'] = $id;

        $name = self::clean_text($in['name'] ?? '');
        if ($name === '' || self::strlen($name) > self::MAX_NAME) {
            $v[] = ['field' => 'name', 'message' => 'The name must be 1 to ' . self::MAX_NAME . ' characters.'];
        }
        $p['name'] = $name;

        $desc = self::s($in['description'] ?? '');
        if (self::strlen($desc) > self::MAX_DESC) {
            $v[] = ['field' => 'description', 'message' => 'The description is too long.'];
        }
        $p['description'] = $desc;

        $price = self::money($in['price'] ?? null);
        if ($price === null) {
            $v[] = ['field' => 'price', 'message' => 'The price must be a number from 0 to ' . (int) self::MAX_PRICE . '.'];
        }
        $p['price'] = $price;

        $compare = null;
        if (isset($in['compare_at_price']) && $in['compare_at_price'] !== null && $in['compare_at_price'] !== '') {
            $compare = self::money($in['compare_at_price']);
            if ($compare === null || ($price !== null && $compare <= $price)) {
                $v[] = ['field' => 'compare_at_price', 'message' => 'The compare-at price must be higher than the price.'];
                $compare = null;
            }
        }
        $p['compare_at_price'] = $compare;

        $sku = self::clean_text($in['sku'] ?? '');
        if (self::strlen($sku) > 64) {
            $v[] = ['field' => 'sku', 'message' => 'The SKU must be 64 characters or fewer.'];
        }
        $p['sku'] = $sku;

        $category = self::clean_text($in['category'] ?? '');
        if (self::strlen($category) > 80) {
            $v[] = ['field' => 'category', 'message' => 'The category name is too long.'];
        }
        $p['category'] = $category;

        $status = self::s($in['status'] ?? 'publish');
        if (!in_array($status, ['publish', 'draft'], true)) {
            $v[] = ['field' => 'status', 'message' => 'The status must be publish or draft.'];
            $status = 'draft';
        }
        $p['status'] = $status;

        $stock = -1;
        if (isset($in['stock_quantity']) && $in['stock_quantity'] !== null && $in['stock_quantity'] !== '') {
            $stock = self::whole($in['stock_quantity']);
            if ($stock === null || $stock < -1 || $stock > self::MAX_STOCK) {
                $v[] = ['field' => 'stock_quantity', 'message' => 'The stock quantity must be a whole number, or -1 for unlimited.'];
                $stock = -1;
            }
        }
        $p['stock_quantity'] = $stock;

        $low = null;
        if (isset($in['low_stock_threshold']) && $in['low_stock_threshold'] !== null && $in['low_stock_threshold'] !== '') {
            $low = self::whole($in['low_stock_threshold']);
            if ($low === null || $low < 0 || $low > self::MAX_STOCK) {
                $v[] = ['field' => 'low_stock_threshold', 'message' => 'The low-stock threshold must be a whole number.'];
                $low = null;
            }
        }
        $p['low_stock_threshold'] = $low;

        $images = [];
        foreach ((array) ($in['images'] ?? []) as $url) {
            if (!is_scalar($url)) {
                $v[] = ['field' => 'images', 'message' => 'One of the image addresses cannot be used.'];
                continue;
            }
            $url = trim((string) $url);
            if ($url === '') {
                continue;
            }
            if (!self::image_url_ok($url)) {
                $v[] = ['field' => 'images', 'message' => 'One of the image addresses cannot be used.'];
                continue;
            }
            if (!in_array($url, $images, true)) {
                $images[] = $url;
            }
        }
        if (count($images) > self::MAX_IMAGES) {
            $v[] = ['field' => 'images', 'message' => 'Up to ' . self::MAX_IMAGES . ' images per product.'];
            $images = array_slice($images, 0, self::MAX_IMAGES);
        }
        $p['images'] = $images;

        $variants = [];
        $seen     = [];
        $skus     = $sku !== '' ? [self::lc($sku) => true] : [];
        foreach ((array) ($in['variants'] ?? []) as $row) {
            if (!is_array($row)) {
                $v[] = ['field' => 'variants', 'message' => 'Each option must have a name.'];
                continue;
            }
            $label = self::clean_text($row['label'] ?? '');
            if ($label === '' || self::strlen($label) > 80 || strpos($label, '|') !== false || isset($seen[self::lc($label)])) {
                $v[] = ['field' => 'variants', 'message' => 'Each option needs a short, different name without the | character.'];
                continue;
            }
            $seen[self::lc($label)] = true;
            $vp = null;
            if (isset($row['price']) && $row['price'] !== null && $row['price'] !== '') {
                $vp = self::money($row['price']);
                if ($vp === null) {
                    $v[] = ['field' => 'variants', 'message' => 'An option price is not a valid number.'];
                }
            }
            $vs = null;
            if (isset($row['stock']) && $row['stock'] !== null && $row['stock'] !== '') {
                $vs = self::whole($row['stock']);
                if ($vs === null || $vs < 0 || $vs > self::MAX_STOCK) {
                    $v[] = ['field' => 'variants', 'message' => 'An option stock count must be a whole number.'];
                    $vs = null;
                }
            }
            $vsku = self::clean_text($row['sku'] ?? '');
            if (self::strlen($vsku) > 64) {
                $v[] = ['field' => 'variants', 'message' => 'An option SKU must be 64 characters or fewer.'];
                $vsku = '';
            }
            if ($vsku !== '') {
                if (isset($skus[self::lc($vsku)])) {
                    $v[] = ['field' => 'variants', 'message' => 'Each option SKU must be different from the others and from the product SKU.'];
                    $vsku = '';
                } else {
                    $skus[self::lc($vsku)] = true;
                }
            }
            $variants[] = ['label' => $label, 'price' => $vp, 'stock' => $vs, 'sku' => $vsku];
        }
        if (count($variants) > self::MAX_VARIANTS) {
            $v[] = ['field' => 'variants', 'message' => 'Up to ' . self::MAX_VARIANTS . ' options per product.'];
            $variants = array_slice($variants, 0, self::MAX_VARIANTS);
        }
        $p['variants'] = $variants;

        return ['violations' => $v, 'product' => $p];
    }

    private static function s($v) {
        return is_scalar($v) ? (string) $v : '';
    }

    private static function clean_text($v) {
        return trim((string) preg_replace('/\s+/u', ' ', wp_strip_all_tags(self::s($v))));
    }

    private static function lc($s) {
        return function_exists('mb_strtolower') ? mb_strtolower((string) $s, 'UTF-8') : strtolower((string) $s);
    }

    private static function whole($v) {
        if (is_int($v)) {
            return $v;
        }
        if (is_float($v)) {
            return ($v == floor($v) && abs($v) <= 9007199254740991) ? (int) $v : null;
        }
        if (is_string($v) && preg_match('/^\s*-?\d{1,15}\s*$/', $v)) {
            return (int) trim($v);
        }
        return null;
    }

    public static function find($bynefit_id) {
        foreach ([['publish', 'draft', 'pending', 'private', 'future'], ['trash']] as $statuses) {
            $ids = get_posts([
                'post_type'        => 'product',
                'post_status'      => $statuses,
                'numberposts'      => 1,
                'orderby'          => 'ID',
                'order'            => 'ASC',
                'fields'           => 'ids',
                'meta_key'         => self::META_ID,
                'meta_value'       => (string) (int) $bynefit_id,
                'suppress_filters' => true,
            ]);
            if ($ids) {
                return wc_get_product((int) $ids[0]);
            }
        }
        return null;
    }

    private static function is_stale($post_id, $sent_at) {
        $last = (int) get_post_meta($post_id, self::META_SEQ, true);
        return $sent_at > 0 && $last > $sent_at;
    }

    private static function stamp($post_id, $sent_at) {
        if ($sent_at > 0) {
            update_post_meta($post_id, self::META_SEQ, (string) $sent_at);
        }
    }

    private static function with_lock($bynefit_id, callable $work) {
        global $wpdb;
        $lock   = 'bynli_product_' . substr(sha1(DB_NAME . '|' . $wpdb->prefix), 0, 16) . '_' . (int) $bynefit_id;
        $locked = (int) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 3)', $lock)) === 1;
        if (!$locked) {
            throw new Bynli_Connect_Product_Busy();
        }
        Bynli_Connect_Inbound::mute();
        try {
            return $work();
        } finally {
            Bynli_Connect_Inbound::unmute();
            $wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock));
        }
    }

    public static function upsert(array $p, $sent_at = 0) {
        return self::with_lock($p['id'], function () use ($p, $sent_at) {
            return self::upsert_locked($p, (int) $sent_at);
        });
    }

    private static function upsert_locked(array $p, $sent_at) {
        @set_time_limit(120);
        $variable = count($p['variants']) > 0;
        $product  = self::find($p['id']);
        $created  = !$product;

        if (!$product && (int) get_option(self::TOMB . $p['id'], 0) > $sent_at) {
            return ['ok' => true, 'stale' => true, 'id' => 0, 'created' => false, 'type' => $variable ? 'variable' : 'simple', 'status' => '', 'url' => '', 'images_skipped' => [], 'images_pending' => 0];
        }

        if ($product) {
            $post_id = (int) $product->get_id();
            if (get_post_status($post_id) === 'trash') {
                throw new Bynli_Connect_Product_Trashed();
            }
            if (self::is_stale($post_id, $sent_at)) {
                return self::state($product, false, ['skipped' => [], 'pending' => 0]) + ['stale' => true];
            }
            if (!$product->is_type('simple') && !$product->is_type('variable')) {
                throw new Bynli_Connect_Product_Refused([['field' => 'product', 'message' => 'This product was changed to a type Bynefit cannot manage. Change it back to a simple or variable product.']]);
            }
        }

        self::assert_skus_free($p, $product);

        if ($product && ($variable ? !$product->is_type('variable') : !$product->is_type('simple'))) {
            $switched = true;
            wp_set_object_terms($product->get_id(), $variable ? 'variable' : 'simple', 'product_type');
            clean_post_cache($product->get_id());
            wc_delete_product_transients($product->get_id());
            wp_cache_delete($product->get_id(), 'post_meta');
            $product = $variable ? new WC_Product_Variable($product->get_id()) : new WC_Product_Simple($product->get_id());
        }
        if (!$product) {
            $product = $variable ? new WC_Product_Variable() : new WC_Product_Simple();
            $product->set_catalog_visibility('visible');
        }
        if ($product->get_id() && $p['sku'] !== '') {
            foreach ($product->get_children() as $child_id) {
                $child = wc_get_product($child_id);
                if ($child && strcasecmp((string) $child->get_sku(), $p['sku']) === 0) {
                    $child->set_sku('');
                    $child->save();
                }
            }
        }

        $product->set_name($p['name']);
        $product->set_description(wp_kses_post($p['description']));
        $product->set_status($variable && ($created || !empty($switched)) ? 'draft' : $p['status']);
        $product->set_sku($p['sku']);
        if ($p['category'] !== '') {
            $cats = self::category_ids($p['category']);
            if ($cats) {
                $product->set_category_ids($cats);
            }
        }

        $kept = [];
        foreach ($product->get_attributes() as $attr) {
            if (is_object($attr) && method_exists($attr, 'get_name') && strcasecmp($attr->get_name(), self::ATTR_NAME) !== 0) {
                $attr->set_variation(false);
                $kept[] = $attr;
            }
        }
        if ($variable) {
            $kept[] = self::attribute(array_column($p['variants'], 'label'));
            $product->set_attributes($kept);
            $product->set_manage_stock(false);
            $product->set_regular_price('');
            $product->set_sale_price('');
        } else {
            $product->set_attributes($kept);
            $regular = $p['compare_at_price'] !== null ? $p['compare_at_price'] : $p['price'];
            $product->set_regular_price(self::price_string($regular));
            $product->set_sale_price($p['compare_at_price'] !== null ? self::price_string($p['price']) : '');
            $product->set_date_on_sale_from(null);
            $product->set_date_on_sale_to(null);
            self::apply_stock($product, $p['stock_quantity'], $p['low_stock_threshold']);
        }

        $images = ['skipped' => [], 'pending' => 0, 'fresh' => [], 'stale' => []];
        try {
            $images = self::apply_images($product, $p['images']);
            $product->update_meta_data(self::META_ID, (string) (int) $p['id']);
            $product->update_meta_data(self::META_HASH, sha1((string) wp_json_encode($p)));
            $id = $product->save();
        } catch (Throwable $e) {
            foreach ($images['fresh'] as $att_id) {
                wp_delete_attachment((int) $att_id, true);
            }
            throw $e;
        }
        update_post_meta($id, self::META_OWNER, (string) $id);
        self::stamp($id, $sent_at);
        foreach ($images['stale'] as $att_id) {
            wp_delete_attachment((int) $att_id, true);
        }
        if (!$variable) {
            foreach (get_posts(['post_type' => 'product_variation', 'post_parent' => $id, 'post_status' => ['publish', 'draft', 'pending', 'private', 'trash'], 'numberposts' => -1, 'fields' => 'ids', 'suppress_filters' => true]) as $child_id) {
                wp_delete_post((int) $child_id, true);
            }
        }
        delete_option(self::TOMB . $p['id']);

        if ($variable) {
            self::sync_variations($id, $p);
            if (($created || !empty($switched)) && $p['status'] === 'publish') {
                $fresh = wc_get_product($id);
                $fresh->set_status('publish');
                $fresh->save();
            }
        }

        return self::state(wc_get_product($id), $created, $images) + ['hash' => sha1((string) wp_json_encode($p))];
    }

    private static function state($product, $created, array $images) {
        $id = (int) $product->get_id();
        return [
            'ok'             => true,
            'id'             => $id,
            'created'        => (bool) $created,
            'type'           => $product->is_type('variable') ? 'variable' : 'simple',
            'status'         => get_post_status($id),
            'url'            => get_permalink($id),
            'images_skipped' => $images['skipped'],
            'images_pending' => $images['pending'],
        ];
    }

    private static function assert_skus_free(array $p, $product) {
        $own = [];
        if ($product && $product->get_id()) {
            $own[] = (int) $product->get_id();
            foreach ($product->get_children() as $child_id) {
                $own[] = (int) $child_id;
            }
        }
        $skus = [$p['sku']];
        foreach ($p['variants'] as $row) {
            $skus[] = $row['sku'];
        }
        foreach ($skus as $sku) {
            if ($sku === '') {
                continue;
            }
            $holder = (int) wc_get_product_id_by_sku($sku);
            if ($holder > 0 && !in_array($holder, $own, true)) {
                throw new Bynli_Connect_Product_Refused([['field' => 'sku', 'message' => 'Another product on this site already uses the SKU ' . $sku . '.']]);
            }
        }
    }

    private static function attribute(array $labels) {
        $attr = new WC_Product_Attribute();
        $attr->set_id(0);
        $attr->set_name(self::ATTR_NAME);
        $attr->set_options(array_values($labels));
        $attr->set_position(0);
        $attr->set_visible(true);
        $attr->set_variation(true);
        return $attr;
    }

    private static function sync_variations($parent_id, array $p) {
        $parent = wc_get_product($parent_id);
        $wanted = [];
        foreach ($p['variants'] as $row) {
            $wanted[self::lc($row['label'])] = $row;
        }
        $have  = [];
        $stale = [];
        foreach ($parent->get_children() as $child_id) {
            $child = wc_get_product($child_id);
            if (!$child) {
                continue;
            }
            $label = self::lc(html_entity_decode((string) $child->get_attribute(self::ATTR_NAME), ENT_QUOTES, 'UTF-8'));
            if (isset($wanted[$label]) && !isset($have[$label])) {
                $have[$label] = $child;
            } else {
                $stale[] = $child;
            }
        }
        foreach ($stale as $child) {
            if ((string) $child->get_sku() !== '') {
                $child->set_sku('');
                $child->save();
            }
        }
        foreach ($have as $key => $child) {
            if ((string) $child->get_sku() !== (string) $wanted[$key]['sku']) {
                $child->set_sku('');
                $child->save();
            }
        }
        $order = 0;
        foreach ($wanted as $key => $row) {
            $order++;
            $variation = isset($have[$key]) ? $have[$key] : new WC_Product_Variation();
            $variation->set_parent_id($parent_id);
            $variation->set_attributes([sanitize_title(self::ATTR_NAME) => $row['label']]);
            $variation->set_status('publish');
            $variation->set_menu_order($order);
            $price = $row['price'] !== null ? $row['price'] : $p['price'];
            $regular = $p['compare_at_price'] !== null && $row['price'] === null ? $p['compare_at_price'] : $price;
            $variation->set_regular_price(self::price_string($regular));
            $variation->set_sale_price($regular !== $price ? self::price_string($price) : '');
            $variation->set_sku($row['sku']);
            if ($row['stock'] !== null) {
                $variation->set_manage_stock(true);
                $variation->set_stock_quantity($row['stock']);
            } else {
                $variation->set_manage_stock(false);
                $variation->set_stock_status('instock');
            }
            $variation->save();
        }
        foreach ($stale as $child) {
            $child->delete(true);
        }
        WC_Product_Variable::sync($parent_id);
        wc_delete_product_transients($parent_id);
    }

    private static function apply_stock($product, $quantity, $low) {
        if ($quantity === -1) {
            $product->set_manage_stock(false);
            $product->set_stock_status('instock');
            $product->set_low_stock_amount('');
            return;
        }
        $product->set_manage_stock(true);
        $product->set_stock_quantity($quantity);
        $product->set_backorders('no');
        $product->set_low_stock_amount($low === null ? '' : $low);
    }

    private static function category_id($name) {
        $existing = get_term_by('slug', sanitize_title($name), 'product_cat');
        if (!$existing || is_wp_error($existing)) {
            $existing = get_term_by('name', $name, 'product_cat');
        }
        if ($existing && !is_wp_error($existing)) {
            return (int) $existing->term_id;
        }
        $made = wp_insert_term($name, 'product_cat');
        if (is_wp_error($made)) {
            $dupe = $made->get_error_data('term_exists');
            return $dupe ? (int) $dupe : 0;
        }
        return (int) $made['term_id'];
    }

    private static function category_ids($name) {
        $id = self::category_id($name);
        return $id > 0 ? [$id] : [];
    }

    private static function apply_images($product, array $urls) {
        $map = [];
        if ($product->get_id()) {
            $stored = get_post_meta($product->get_id(), self::META_IMAGES, true);
            $owner  = (int) get_post_meta($product->get_id(), self::META_OWNER, true);
            $map    = is_array($stored) && $owner === (int) $product->get_id() ? $stored : [];
        }
        $ids = [];
        $next = [];
        $skipped = [];
        $fresh = [];
        $pending = 0;
        $started = time();
        foreach ($urls as $url) {
            $known = isset($map[$url]) ? (int) $map[$url] : 0;
            if ($known > 0 && get_post_type($known) === 'attachment') {
                $ids[] = $known;
                $next[$url] = $known;
                continue;
            }
            if (time() - $started > self::IMG_BUDGET_SECONDS) {
                $pending++;
                continue;
            }
            $att = self::sideload($url);
            if ($att > 0) {
                $ids[] = $att;
                $next[$url] = $att;
                $fresh[] = $att;
            } else {
                $skipped[] = $url;
            }
        }
        $stale = [];
        foreach ($map as $url => $att_id) {
            if (isset($next[$url]) || get_post_type((int) $att_id) !== 'attachment') {
                continue;
            }
            if ($pending || ($urls && !$ids)) {
                $next[$url] = (int) $att_id;
            } else {
                $stale[] = (int) $att_id;
            }
        }
        if ($ids || (!$urls && $map)) {
            $product->set_image_id($ids ? (int) $ids[0] : 0);
            $product->set_gallery_image_ids(array_slice($ids, 1));
        }
        if ($next || $map) {
            $product->update_meta_data(self::META_IMAGES, $next);
        }
        return ['skipped' => $skipped, 'pending' => $pending, 'fresh' => $fresh, 'stale' => $stale];
    }

    public static function cap_download_size($args) {
        $args['limit_response_size'] = self::MAX_IMG_BYTES + 1;
        return $args;
    }

    private static function sideload($url) {
        if (!function_exists('download_url')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }
        if (!function_exists('media_handle_sideload')) {
            require_once ABSPATH . 'wp-admin/includes/media.php';
            require_once ABSPATH . 'wp-admin/includes/image.php';
        }
        add_filter('http_request_args', [__CLASS__, 'cap_download_size']);
        try {
            $tmp = download_url($url, 10);
        } finally {
            remove_filter('http_request_args', [__CLASS__, 'cap_download_size']);
        }
        if (is_wp_error($tmp)) {
            error_log('[Bynli Connect] product image download failed: ' . $tmp->get_error_message());
            return 0;
        }
        $size = @filesize($tmp);
        $mime = function_exists('wp_get_image_mime') ? wp_get_image_mime($tmp) : false;
        $ext  = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][(string) $mime] ?? '';
        $dims = $ext !== '' ? @getimagesize($tmp) : false;
        $fits = is_array($dims) && $dims[0] > 0 && $dims[1] > 0 && $dims[0] <= self::MAX_IMG_SIDE && $dims[1] <= self::MAX_IMG_SIDE && $dims[0] * $dims[1] <= self::MAX_IMG_AREA;
        if ($size === false || $size > self::MAX_IMG_BYTES || $ext === '' || !$fits) {
            @unlink($tmp);
            error_log('[Bynli Connect] product image rejected (type, size or dimensions): ' . basename((string) wp_parse_url($url, PHP_URL_PATH)));
            return 0;
        }
        $base = sanitize_file_name(pathinfo((string) wp_parse_url($url, PHP_URL_PATH), PATHINFO_FILENAME));
        $base = $base !== '' ? $base : 'product-image';
        $att  = media_handle_sideload(['name' => $base . '.' . $ext, 'tmp_name' => $tmp], 0);
        if (is_wp_error($att)) {
            @unlink($tmp);
            error_log('[Bynli Connect] product image sideload failed: ' . $att->get_error_message());
            return 0;
        }
        return (int) $att;
    }

    private static function image_url_ok($url) {
        if (strlen($url) > 500 || preg_match('/[\s"\'<>\\\\]/', $url)) {
            return false;
        }
        $parts = wp_parse_url($url);
        return is_array($parts) && ($parts['scheme'] ?? '') === 'https' && !empty($parts['host'])
            && !isset($parts['user']) && !isset($parts['pass']);
    }

    private static function money($value) {
        if ($value === null || $value === '' || !is_numeric($value)) {
            return null;
        }
        $n = round((float) $value, 2);
        return ($n < 0 || $n > self::MAX_PRICE) ? null : $n;
    }

    private static function price_string($n) {
        return wc_format_decimal((string) $n, 2);
    }

    private static function strlen($s) {
        return function_exists('mb_strlen') ? mb_strlen((string) $s) : strlen((string) $s);
    }

    private static function refused(array $violations) {
        return new WP_REST_Response(['code' => 'unpublishable', 'violations' => $violations], 422);
    }

    public static function exclude_duplicate_meta($meta) {
        return array_merge((array) $meta, [self::META_ID, self::META_HASH, self::META_IMAGES, self::META_OWNER, self::META_SEQ]);
    }
}

add_filter('woocommerce_duplicate_product_exclude_meta', ['Bynli_Connect_Products', 'exclude_duplicate_meta']);
