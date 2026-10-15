<?php
if (!defined('ABSPATH')) { exit; }

class Bynli_Connect_Product_Busy extends Exception {}

class Bynli_Connect_Products {
    const API_VERSION   = 1;
    const META_ID       = '_bynefit_product_id';
    const META_HASH     = '_bynefit_product_hash';
    const META_IMAGES   = '_bynefit_image_map';
    const MAX_IMAGES    = 10;
    const MAX_VARIANTS  = 50;
    const MAX_NAME      = 160;
    const MAX_DESC      = 5000;
    const MAX_PRICE     = 1000000.0;
    const MAX_STOCK     = 1000000;
    const MAX_IMG_BYTES = 8388608;
    const ATTR_NAME     = 'Option';
    const IMG_BUDGET_SECONDS = 40;

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
        try {
            $out = self::upsert($clean['product']);
        } catch (WC_Data_Exception $e) {
            $code = $e->getErrorCode();
            if (strpos((string) $code, 'sku') !== false) {
                return self::refused([['field' => 'sku', 'message' => 'Another product on this site already uses that SKU.']]);
            }
            error_log('[Bynli Connect] product upsert refused: ' . $e->getMessage());
            return self::refused([['field' => 'product', 'message' => 'WooCommerce refused this product.']]);
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
        $id = isset($body['id']) ? (int) $body['id'] : 0;
        if ($id <= 0) {
            return self::refused([['field' => 'id', 'message' => 'Provide the Bynefit product id.']]);
        }
        if (!self::woo_ready()) {
            return new WP_Error('woocommerce_missing', 'WooCommerce is not active on this site.', ['status' => 409]);
        }
        try {
            $product = self::find($id);
            if (!$product) {
                return new WP_REST_Response(['ok' => true, 'removed' => false], 200);
            }
            $product->set_status('draft');
            $product->save();
            return new WP_REST_Response(['ok' => true, 'removed' => true, 'id' => $product->get_id()], 200);
        } catch (Throwable $e) {
            error_log('[Bynli Connect] product remove failed: ' . $e->getMessage());
            return new WP_Error('product_failed', 'The product could not be removed.', ['status' => 500]);
        }
    }

    public static function validate($in) {
        $v = [];
        $p = [];

        $id = isset($in['id']) ? (int) $in['id'] : 0;
        if ($id <= 0) {
            $v[] = ['field' => 'id', 'message' => 'Provide the Bynefit product id.'];
        }
        $p['id'] = $id;

        $name = trim(sanitize_text_field((string) ($in['name'] ?? '')));
        if ($name === '' || self::strlen($name) > self::MAX_NAME) {
            $v[] = ['field' => 'name', 'message' => 'The name must be 1 to ' . self::MAX_NAME . ' characters.'];
        }
        $p['name'] = $name;

        $desc = (string) ($in['description'] ?? '');
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

        $sku = trim(sanitize_text_field((string) ($in['sku'] ?? '')));
        if (self::strlen($sku) > 64) {
            $v[] = ['field' => 'sku', 'message' => 'The SKU must be 64 characters or fewer.'];
        }
        $p['sku'] = $sku;

        $category = trim(sanitize_text_field((string) ($in['category'] ?? '')));
        if (self::strlen($category) > 80) {
            $v[] = ['field' => 'category', 'message' => 'The category name is too long.'];
        }
        $p['category'] = $category;

        $status = (string) ($in['status'] ?? 'publish');
        if (!in_array($status, ['publish', 'draft'], true)) {
            $v[] = ['field' => 'status', 'message' => 'The status must be publish or draft.'];
            $status = 'draft';
        }
        $p['status'] = $status;

        $stock = isset($in['stock_quantity']) ? (int) $in['stock_quantity'] : -1;
        if ($stock < -1 || $stock > self::MAX_STOCK) {
            $v[] = ['field' => 'stock_quantity', 'message' => 'The stock quantity is out of range.'];
            $stock = -1;
        }
        $p['stock_quantity'] = $stock;

        $low = null;
        if (isset($in['low_stock_threshold']) && $in['low_stock_threshold'] !== null && $in['low_stock_threshold'] !== '') {
            $low = (int) $in['low_stock_threshold'];
            if ($low < 0 || $low > self::MAX_STOCK) {
                $v[] = ['field' => 'low_stock_threshold', 'message' => 'The low-stock threshold is out of range.'];
                $low = null;
            }
        }
        $p['low_stock_threshold'] = $low;

        $images = [];
        foreach ((array) ($in['images'] ?? []) as $url) {
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
        $skus     = $sku !== '' ? [strtolower($sku) => true] : [];
        foreach ((array) ($in['variants'] ?? []) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $label = trim(wp_strip_all_tags((string) ($row['label'] ?? '')));
            if ($label === '' || self::strlen($label) > 80 || strpos($label, '|') !== false || isset($seen[strtolower($label)])) {
                $v[] = ['field' => 'variants', 'message' => 'Each option needs a short, different name without the | character.'];
                continue;
            }
            $seen[strtolower($label)] = true;
            $vp = isset($row['price']) && $row['price'] !== null && $row['price'] !== '' ? self::money($row['price']) : null;
            if (isset($row['price']) && $row['price'] !== null && $row['price'] !== '' && $vp === null) {
                $v[] = ['field' => 'variants', 'message' => 'An option price is not a valid number.'];
            }
            $vs = isset($row['stock']) && $row['stock'] !== null && $row['stock'] !== '' ? (int) $row['stock'] : null;
            if ($vs !== null && ($vs < 0 || $vs > self::MAX_STOCK)) {
                $v[] = ['field' => 'variants', 'message' => 'An option stock count is out of range.'];
                $vs = null;
            }
            $vsku = trim(sanitize_text_field((string) ($row['sku'] ?? '')));
            if (self::strlen($vsku) > 64) {
                $v[] = ['field' => 'variants', 'message' => 'An option SKU must be 64 characters or fewer.'];
                $vsku = '';
            }
            if ($vsku !== '') {
                if (isset($skus[strtolower($vsku)])) {
                    $v[] = ['field' => 'variants', 'message' => 'Each option SKU must be different from the others and from the product SKU.'];
                    $vsku = '';
                } else {
                    $skus[strtolower($vsku)] = true;
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

    public static function find($bynefit_id) {
        $ids = get_posts([
            'post_type'        => 'product',
            'post_status'      => 'any',
            'numberposts'      => 1,
            'fields'           => 'ids',
            'meta_key'         => self::META_ID,
            'meta_value'       => (string) (int) $bynefit_id,
            'suppress_filters' => false,
        ]);
        return $ids ? wc_get_product((int) $ids[0]) : null;
    }

    public static function upsert(array $p) {
        global $wpdb;
        $lock   = 'bynli_product_' . substr(sha1(DB_NAME . '|' . $wpdb->prefix), 0, 16) . '_' . (int) $p['id'];
        $locked = (int) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 3)', $lock)) === 1;
        if (!$locked) {
            throw new Bynli_Connect_Product_Busy();
        }
        @set_time_limit(120);
        try {
            $variable = count($p['variants']) > 0;
            $product  = self::find($p['id']);
            $created  = !$product;

            if ($product && $variable !== $product->is_type('variable')) {
                if (!$variable) {
                    foreach ($product->get_children() as $child_id) {
                        wp_delete_post((int) $child_id, true);
                    }
                }
                wp_set_object_terms($product->get_id(), $variable ? 'variable' : 'simple', 'product_type');
                clean_post_cache($product->get_id());
                $product = wc_get_product($product->get_id());
            }
            if (!$product) {
                $product = $variable ? new WC_Product_Variable() : new WC_Product_Simple();
            }

            $product->set_name($p['name']);
            $product->set_description(wp_kses_post($p['description']));
            $product->set_status($p['status']);
            $product->set_catalog_visibility('visible');
            $product->set_sku($p['sku']);
            $product->set_category_ids($p['category'] !== '' ? [self::category_id($p['category'])] : []);

            if ($variable) {
                $product->set_attributes([self::attribute(array_column($p['variants'], 'label'))]);
                $product->set_manage_stock(false);
                $product->set_regular_price('');
                $product->set_sale_price('');
            } else {
                $regular = $p['compare_at_price'] !== null ? $p['compare_at_price'] : $p['price'];
                $product->set_regular_price(self::price_string($regular));
                $product->set_sale_price($p['compare_at_price'] !== null ? self::price_string($p['price']) : '');
                self::apply_stock($product, $p['stock_quantity'], $p['low_stock_threshold']);
            }

            $images = self::apply_images($product, $p['images']);

            $product->update_meta_data(self::META_ID, (string) (int) $p['id']);
            $product->update_meta_data(self::META_HASH, sha1((string) wp_json_encode($p)));
            $id = $product->save();

            if ($variable) {
                self::sync_variations($id, $p);
            }

            return [
                'ok'      => true,
                'id'      => (int) $id,
                'created' => $created,
                'type'    => $variable ? 'variable' : 'simple',
                'status'  => get_post_status($id),
                'url'     => get_permalink($id),
                'hash'    => sha1((string) wp_json_encode($p)),
                'images_skipped' => $images['skipped'],
                'images_pending' => $images['pending'],
            ];
        } finally {
            $wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock));
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
            $wanted[strtolower($row['label'])] = $row;
        }
        $have = [];
        foreach ($parent->get_children() as $child_id) {
            $child = wc_get_product($child_id);
            if (!$child) {
                continue;
            }
            $label = strtolower((string) $child->get_attribute(self::ATTR_NAME));
            if (isset($wanted[$label]) && !isset($have[$label])) {
                $have[$label] = $child;
            } else {
                $child->delete(true);
            }
        }
        foreach ($wanted as $key => $row) {
            $variation = isset($have[$key]) ? $have[$key] : new WC_Product_Variation();
            $variation->set_parent_id($parent_id);
            $variation->set_attributes([sanitize_title(self::ATTR_NAME) => $row['label']]);
            $variation->set_status('publish');
            $price = $row['price'] !== null ? $row['price'] : $p['price'];
            $regular = $p['compare_at_price'] !== null && $row['price'] === null ? $p['compare_at_price'] : $price;
            $variation->set_regular_price(self::price_string($regular));
            $variation->set_sale_price($regular !== $price ? self::price_string($price) : '');
            $sku = $row['sku'];
            $variation->set_sku($sku);
            if ($row['stock'] !== null) {
                $variation->set_manage_stock(true);
                $variation->set_stock_quantity($row['stock']);
            } else {
                $variation->set_manage_stock(false);
                $variation->set_stock_status('instock');
            }
            $variation->save();
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
        $existing = term_exists($name, 'product_cat');
        if (is_array($existing)) {
            return (int) $existing['term_id'];
        }
        if ($existing) {
            return (int) $existing;
        }
        $made = wp_insert_term($name, 'product_cat');
        return is_wp_error($made) ? 0 : (int) $made['term_id'];
    }

    private static function apply_images($product, array $urls) {
        $map = [];
        if ($product->get_id()) {
            $stored = get_post_meta($product->get_id(), self::META_IMAGES, true);
            $map    = is_array($stored) ? $stored : [];
        }
        $ids = [];
        $next = [];
        $skipped = [];
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
            } else {
                $skipped[] = $url;
            }
        }
        if (!$pending) {
            foreach ($map as $url => $att_id) {
                if (!isset($next[$url]) && get_post_type((int) $att_id) === 'attachment') {
                    wp_delete_attachment((int) $att_id, true);
                }
            }
        } else {
            foreach ($map as $url => $att_id) {
                if (!isset($next[$url]) && get_post_type((int) $att_id) === 'attachment') {
                    $next[$url] = (int) $att_id;
                }
            }
        }
        $product->set_image_id($ids ? (int) $ids[0] : 0);
        $product->set_gallery_image_ids(array_slice($ids, 1));
        $product->update_meta_data(self::META_IMAGES, $next);
        return ['skipped' => $skipped, 'pending' => $pending];
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
        $tmp = download_url($url, 20);
        remove_filter('http_request_args', [__CLASS__, 'cap_download_size']);
        if (is_wp_error($tmp)) {
            error_log('[Bynli Connect] product image download failed: ' . $tmp->get_error_message());
            return 0;
        }
        $size = @filesize($tmp);
        $name = basename((string) wp_parse_url($url, PHP_URL_PATH));
        $type = wp_check_filetype_and_ext($tmp, $name, ['jpg|jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp']);
        if ($size === false || $size > self::MAX_IMG_BYTES || empty($type['type'])) {
            @unlink($tmp);
            error_log('[Bynli Connect] product image rejected (type or size): ' . $name);
            return 0;
        }
        $att = media_handle_sideload(['name' => sanitize_file_name($name), 'tmp_name' => $tmp], 0);
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
        return is_array($parts) && ($parts['scheme'] ?? '') === 'https' && !empty($parts['host']);
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
}
