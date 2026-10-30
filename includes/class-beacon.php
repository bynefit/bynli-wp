<?php
if (!defined('ABSPATH')) { exit; }

class Bynli_Connect_Beacon {

    const TRACK_PATH = '/api/sites/track';

    public function __construct() {
        add_action('wp_enqueue_scripts', [$this, 'enqueue']);
        add_filter('wp_resource_hints', [$this, 'hints'], 10, 2);
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

    private function active(): bool {
        if (is_admin() || wp_doing_ajax() || is_feed() || is_embed() || is_customize_preview()) {
            return false;
        }
        if (!self::enabled()) {
            return false;
        }
        $slug = self::resolve_slug();
        return preg_match('/^[a-z0-9\-]{3,100}$/', $slug) === 1;
    }

    public function hints($urls, $relation) {
        if ($relation === 'dns-prefetch' && $this->active()) {
            $urls[] = Bynli_Connect_Settings::api_base();
        }
        return $urls;
    }

    public function enqueue(): void {
        if (!$this->active()) {
            return;
        }
        wp_enqueue_script(
            'bynli-connect-beacon',
            plugins_url('assets/beacon.js', BYNLI_CONNECT_PLUGIN_FILE),
            [],
            BYNLI_CONNECT_VERSION,
            true
        );
        wp_localize_script('bynli-connect-beacon', 'BynliConnectBeacon', [
            'endpoint' => rtrim(Bynli_Connect_Settings::api_base(), '/') . self::TRACK_PATH,
            'slug'     => self::resolve_slug(),
        ]);
    }
}
