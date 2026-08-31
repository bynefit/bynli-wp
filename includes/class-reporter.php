<?php
if (!defined('ABSPATH')) { exit; }

class Bynli_Connect_Reporter {
    const ENDPOINT_PATH  = '/api/site-host/report';
    const OPTION_LAST    = 'bynli_connect_last_report';
    // Rolling history of recent posts (heartbeat + daily), newest last. Drives
    // the Overview 7-day heartbeat sparkline (#29) — deterministic, no external
    // fetch. Capped so the option stays small.
    const OPTION_HISTORY = 'bynli_connect_report_history';
    const HISTORY_MAX    = 14;

    public static function send_heartbeat(): array {
        return self::post(['kind' => 'heartbeat']);
    }

    public static function send_daily(): array {
        $usage = self::collect_usage();
        $payload = [
            'kind'            => 'daily',
            'usage_date'      => gmdate('Y-m-d', time() - 86400),
            'bandwidth_bytes' => (int)$usage['bandwidth_bytes'],
            'storage_bytes'   => (int)$usage['storage_bytes'],
            'request_count'   => (int)$usage['request_count'],
            'meta'            => $usage['meta'],
            'insights'        => self::collect_insights(),
        ];
        return self::post($payload);
    }

    private static function collect_usage(): array {
        $storage = self::dir_size(ABSPATH);
        return [
            'bandwidth_bytes' => 0,
            'storage_bytes'   => $storage,
            'request_count'   => 0,
            'meta' => [
                'wp_version'     => get_bloginfo('version'),
                'php_version'    => PHP_VERSION,
                'plugin_version' => BYNLI_CONNECT_VERSION,
                'home_url'       => home_url(),
                'measurement'    => 'best_effort_v1',
            ],
        ];
    }

    /**
     * Cheap, cached WP site insights for the daily report (bynli#2265 Phase 1).
     * All reads are in-process or hit already-cached update transients — no forced
     * network refresh, no extra filesystem walk. Every branch is guarded so a
     * missing function / odd site never fatals the cron report.
     */
    private static function collect_insights(): array {
        $out = [];
        try {
            $posts = wp_count_posts('post');
            $pages = wp_count_posts('page');
            $atts  = wp_count_posts('attachment');
            $out['posts'] = (int)($posts->publish ?? 0);
            $out['pages'] = (int)($pages->publish ?? 0);
            $out['media'] = (int)($atts->inherit ?? 0);

            $comments = wp_count_comments();
            $out['comments_pending'] = (int)($comments->moderated ?? 0);
            $out['comments_spam']    = (int)($comments->spam ?? 0);

            $last = get_lastpostmodified('gmt');
            $out['last_modified'] = $last ? gmdate('c', strtotime($last . ' UTC')) : null;

            $users = count_users();
            $out['users_total'] = (int)($users['total_users'] ?? 0);
            $out['admins']      = (int)($users['avail_roles']['administrator'] ?? 0);

            // Available updates — read the cached update_* transients DIRECTLY.
            // wp_get_update_data() gates its counts behind current_user_can(), so in
            // WP-Cron context (no logged-in user) it returns 0 for everything; the
            // transients carry the real pending-update set with no capability gate
            // and no network refresh.
            $plugin_t = get_site_transient('update_plugins');
            $theme_t  = get_site_transient('update_themes');
            $core_t   = get_site_transient('update_core');
            $plugins_n = ($plugin_t && !empty($plugin_t->response) && is_array($plugin_t->response)) ? count($plugin_t->response) : 0;
            $themes_n  = ($theme_t  && !empty($theme_t->response)  && is_array($theme_t->response))  ? count($theme_t->response)  : 0;
            $core_n = 0;
            if ($core_t && !empty($core_t->updates) && is_array($core_t->updates)) {
                foreach ($core_t->updates as $u) {
                    if (isset($u->response) && $u->response === 'upgrade') { $core_n++; }
                }
            }
            $out['updates'] = [
                'core'    => $core_n,
                'plugins' => $plugins_n,
                'themes'  => $themes_n,
                'total'   => $core_n + $plugins_n + $themes_n,
            ];

            $theme = wp_get_theme();
            $out['theme'] = [
                'name'    => $theme ? (string)$theme->get('Name') : null,
                'version' => $theme ? (string)$theme->get('Version') : null,
            ];

            // Active-plugin COUNT only (not the itemized list) — the itemized list
            // is a site's attack surface and is privacy-gated to a future opt-in.
            $active = get_option('active_plugins');
            $out['plugins_active'] = is_array($active) ? count($active) : 0;

            $out['https']        = function_exists('wp_is_using_https') ? (bool)wp_is_using_https() : is_ssl();
            $out['debug']        = defined('WP_DEBUG') && WP_DEBUG;
            $out['search_index'] = ((int)get_option('blog_public', 1) === 1);
            $out['multisite']    = is_multisite();

            $out['db_version'] = null;
            global $wpdb;
            if (isset($wpdb) && method_exists($wpdb, 'db_version')) {
                $out['db_version'] = (string)$wpdb->db_version();
            }

            // WooCommerce block (bynli#2265 Phase 2) — only when Woo is active.
            // Cheap status COUNTs (wc_orders_count) + product count; the "needs
            // action" queue (processing + on-hold) is the highest-value signal.
            // Counts only — no order/customer money data or PII is sent.
            if (class_exists('WooCommerce') && function_exists('wc_orders_count')) {
                $woo = [
                    'products'          => (int)(wp_count_posts('product')->publish ?? 0),
                    'orders_processing' => (int)wc_orders_count('processing'),
                    'orders_on_hold'    => (int)wc_orders_count('on-hold'),
                    'orders_completed'  => (int)wc_orders_count('completed'),
                ];
                if (function_exists('wc_get_products')) {
                    $oos = wc_get_products(['stock_status' => 'outofstock', 'return' => 'ids', 'limit' => -1]);
                    $woo['out_of_stock'] = is_array($oos) ? count($oos) : 0;
                }
                $out['woo'] = $woo;
            }
        } catch (\Throwable $e) {
            error_log('[Bynli Connect] collect_insights: ' . $e->getMessage());
        }
        return $out;
    }

    private static function dir_size(string $path, int $cap_bytes = 50 * 1024 * 1024 * 1024): int {
        $total = 0;
        try {
            $it = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::SELF_FIRST
            );
            foreach ($it as $f) {
                if ($f->isFile()) {
                    $total += $f->getSize();
                    if ($total > $cap_bytes) break;
                }
            }
        } catch (\Throwable $e) {
            error_log('[Bynli Connect] dir_size: ' . $e->getMessage());
        }
        return $total;
    }

    private static function post(array $payload): array {
        $api_base = Bynli_Connect_Settings::api_base();
        $key      = Bynli_Connect_Settings::key();
        if (!$key) {
            return ['ok' => false, 'error' => 'no_key', 'message' => 'No API key configured. See Settings → Bynefit Connect.'];
        }

        $body  = wp_json_encode($payload);
        $ts    = time();
        $sig   = Bynli_Connect_Signer::sign($key, $ts, $body);
        $url   = rtrim($api_base, '/') . self::ENDPOINT_PATH;

        $resp = wp_remote_post($url, [
            'timeout' => 15,
            'headers' => [
                'Content-Type'        => 'application/json',
                'Accept'              => 'application/json',
                'Authorization'       => 'Bearer ' . $key,
                'X-Bynli-Timestamp'   => (string)$ts,
                'X-Bynli-Signature'   => $sig,
                'User-Agent'          => 'Bynli-Connect/' . BYNLI_CONNECT_VERSION . ' WP/' . get_bloginfo('version'),
            ],
            'body' => $body,
        ]);

        if (is_wp_error($resp)) {
            error_log('[Bynli Connect] post failed: ' . $resp->get_error_message());
            return ['ok' => false, 'error' => 'transport', 'message' => $resp->get_error_message()];
        }

        $code = wp_remote_retrieve_response_code($resp);
        $raw  = wp_remote_retrieve_body($resp);
        $json = json_decode($raw, true);
        $ok   = (is_array($json) && !empty($json['ok']));

        // Storage is only measured on daily posts; carry the last known value
        // forward on a heartbeat so the Overview metric doesn't blank out
        // between daily runs (and we never re-walk the filesystem on load).
        $prev    = self::last_report();
        $storage = isset($payload['storage_bytes'])
            ? (int)$payload['storage_bytes']
            : (isset($prev['storage_bytes']) ? (int)$prev['storage_bytes'] : null);

        update_option(self::OPTION_LAST, [
            'at'            => time(),
            'kind'          => $payload['kind'],
            'status'        => $code,
            'ok'            => $ok,
            'message'       => is_array($json) ? ($json['error'] ?? '') : substr($raw, 0, 200),
            'storage_bytes' => $storage,
        ], false);

        $hist   = self::history();
        $hist[] = [
            'at'            => time(),
            'kind'          => $payload['kind'],
            'ok'            => $ok,
            'status'        => $code,
            'storage_bytes' => isset($payload['storage_bytes']) ? (int)$payload['storage_bytes'] : null,
        ];
        update_option(self::OPTION_HISTORY, array_slice($hist, -self::HISTORY_MAX), false);

        if ($code >= 200 && $code < 300 && $ok) {
            return ['ok' => true, 'status' => $code, 'response' => $json];
        }
        return ['ok' => false, 'status' => $code, 'response' => $json ?: $raw];
    }

    public static function last_report(): array {
        $val = get_option(self::OPTION_LAST, null);
        return is_array($val) ? $val : [];
    }

    /** Rolling post history (oldest → newest), each: at, kind, ok, status, storage_bytes. */
    public static function history(): array {
        $val = get_option(self::OPTION_HISTORY, null);
        return is_array($val) ? $val : [];
    }
}
