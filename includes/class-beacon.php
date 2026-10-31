<?php
if (!defined('ABSPATH')) { exit; }

class Bynli_Connect_Beacon {

    const TRACK_PATH   = '/api/sites/track';
    const EVENT_PATH   = '/api/sites/track-event';
    const ENABLE_PATH  = '/api/site-host/beacon-enable';
    const KEY_OPTION   = 'bynli_connect_beacon_key';
    const REFRESH_HOOK = 'bynli_connect_beacon_refresh';
    const KEY_FRESH_FOR = 12 * HOUR_IN_SECONDS;
    const KEY_KEEP_FOR  = 14 * DAY_IN_SECONDS;
    const RETRY_AFTER   = 30 * MINUTE_IN_SECONDS;

    private static $partner = null;

    public function __construct() {
        add_action('wp_enqueue_scripts', [$this, 'enqueue']);
        add_action('wp_footer', [$this, 'print_partner_tag'], 20);
        add_filter('wp_resource_hints', [$this, 'hints'], 10, 2);
        add_action(self::REFRESH_HOOK, [__CLASS__, 'refresh_key']);
    }

    public static function enabled(): bool {
        return Bynli_Connect_Settings::beacons_enabled();
    }

    public static function resolve_slug(): string {
        $configured = Bynli_Connect_Settings::site_slug();
        if ($configured !== '') {
            return $configured;
        }
        return Bynli_Connect_Badge::slug_from_host(wp_parse_url(home_url(), PHP_URL_HOST));
    }

    private function context_ok(): bool {
        if (is_admin() || wp_doing_ajax() || is_feed() || is_embed() || is_customize_preview() || Bynli_Connect_Badge::is_builder_preview()) {
            return false;
        }
        return self::enabled();
    }

    public static function partner_config(): ?array {
        $s = get_option(self::KEY_OPTION, []);
        $s = is_array($s) ? $s : [];
        $age  = time() - (int) ($s['at'] ?? 0);
        $wait = min(self::KEY_FRESH_FOR, self::RETRY_AFTER * (2 ** max(0, (int) ($s['fails'] ?? 0) - 1)));
        $checked_recent = time() - (int) ($s['checked'] ?? 0) < $wait;
        if (self::enabled() && Bynli_Connect_Settings::key() !== ''
            && (empty($s['at']) || $age >= self::KEY_FRESH_FOR) && !$checked_recent
            && !wp_next_scheduled(self::REFRESH_HOOK)) {
            wp_schedule_single_event(time() + 5, self::REFRESH_HOOK);
        }
        if (empty($s['public_key']) || $age >= self::KEY_KEEP_FOR
            || !preg_match('/^pbk_[a-f0-9]{32}$/', (string) $s['public_key'])) {
            return null;
        }
        return $s;
    }

    public static function refresh_key(): void {
        if (!self::enabled() || Bynli_Connect_Settings::key() === '') {
            return;
        }
        $s = get_option(self::KEY_OPTION, []);
        $s = is_array($s) ? $s : [];
        $s['checked'] = time();
        update_option(self::KEY_OPTION, $s, true);

        $res = Bynli_Connect_Api::post(self::ENABLE_PATH, ['origin' => home_url()]);
        if (empty($res['ok']) || empty($res['data']['public_key'])) {
            $s['failed_at'] = time();
            $s['fails']     = (int) ($s['fails'] ?? 0) + 1;
            update_option(self::KEY_OPTION, $s, true);
            error_log('[Bynli Connect] beacon key refresh: ' . (string) ($res['message'] ?? ($res['error'] ?? 'failed')));
            return;
        }
        $d = $res['data'];
        update_option(self::KEY_OPTION, [
            'public_key'     => (string) $d['public_key'],
            'collect_path'   => isset($d['collect_path']) ? (string) $d['collect_path'] : '/dash/api/v1/beacons/collect',
            'script_path'    => isset($d['script_path']) ? (string) $d['script_path'] : '/dash/assets/js/beacon.js',
            'script_version' => isset($d['script_version']) ? (int) $d['script_version'] : 3,
            'at'             => time(),
            'checked'        => time(),
            'fails'          => 0,
        ], true);
    }

    public function hints($urls, $relation) {
        if ($relation === 'dns-prefetch' && $this->context_ok()) {
            $urls[] = Bynli_Connect_Settings::api_base();
        }
        return $urls;
    }

    public function enqueue(): void {
        if (!$this->context_ok()) {
            return;
        }
        $cfg = self::partner_config();
        if ($cfg !== null) {
            self::$partner = $cfg;
            return;
        }
        $slug = self::resolve_slug();
        if (preg_match('/^[a-z0-9\-]{3,100}$/', $slug) !== 1) {
            return;
        }
        wp_enqueue_script(
            'bynli-connect-beacon',
            plugins_url('assets/beacon.js', BYNLI_CONNECT_PLUGIN_FILE),
            [],
            BYNLI_CONNECT_VERSION,
            true
        );
        $base = rtrim(Bynli_Connect_Settings::api_base(), '/');
        wp_localize_script('bynli-connect-beacon', 'BynliConnectBeacon', [
            'endpoint'      => $base . self::TRACK_PATH,
            'eventEndpoint' => $base . self::EVENT_PATH,
            'slug'          => $slug,
        ]);
    }

    public function print_partner_tag(): void {
        if (self::$partner === null) {
            return;
        }
        $cfg  = self::$partner;
        $base = rtrim(Bynli_Connect_Settings::api_base(), '/');
        printf(
            '<script src="%s" data-key="%s" data-endpoint="%s" data-surface="public" defer></script>' . "\n",
            esc_url($base . $cfg['script_path'] . '?v=' . (int) $cfg['script_version']),
            esc_attr($cfg['public_key']),
            esc_url($base . $cfg['collect_path'])
        );
    }
}
