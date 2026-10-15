<?php
if (!defined('ABSPATH')) { exit; }

class Bynli_Connect_Badge {
    const OPTION       = 'bynli_connect_badge';
    const REFRESH_HOOK = 'bynli_connect_badge_refresh';
    const FRESH_FOR    = 6 * HOUR_IN_SECONDS;
    const KEEP_FOR     = 7 * DAY_IN_SECONDS;
    const RETRY_AFTER  = 15 * MINUTE_IN_SECONDS;
    const STYLE_PATH   = '/css/bynli.css.php?b=powered&v=20261010.1';
    const MARK_PATH    = '/assets/brand/bynefit-mark@2x.png';
    const LABEL        = 'Powered by Bynefit. Build yours free. Opens in a new tab.';
    const LIFT_MAX     = 400;

    public function __construct() {
        add_action('wp_footer', [$this, 'enqueue'], 5);
        add_action('wp_footer', [$this, 'render'], 99);
        add_action(self::REFRESH_HOOK, [__CLASS__, 'refresh']);
    }

    public static function slug_from_host($host) {
        $host = strtolower((string) $host);
        if (preg_match('/^([a-z0-9][a-z0-9-]{1,98}[a-z0-9])\.site\.(?:bynefit|bynli)\.com$/', $host, $m)) {
            return $m[1];
        }
        return '';
    }

    public static function lookup_query() {
        $home = strtolower((string) wp_parse_url(home_url(), PHP_URL_HOST));
        foreach ([defined('WP_CONTENT_URL') ? WP_CONTENT_URL : '', home_url()] as $url) {
            $slug = self::slug_from_host(wp_parse_url((string) $url, PHP_URL_HOST));
            if ($slug !== '') {
                return ['slug' => $slug];
            }
        }
        $configured = Bynli_Connect_Settings::site_slug();
        if ($configured !== '') {
            return ['slug' => $configured];
        }
        return $home !== '' ? ['host' => $home] : [];
    }

    public static function parse($body, $api_base, $host) {
        $data = json_decode((string) $body, true);
        if (!is_array($data) || empty($data['ok']) || ($data['kind'] ?? '') !== 'wp_managed') {
            return ['show' => false, 'href' => ''];
        }
        $status = isset($data['status']) && is_array($data['status']) ? $data['status'] : [];
        $show   = empty($status['skip_branding']);
        $href   = $api_base . '/sites?ref=' . rawurlencode((string) $host) . '&utm_source=powered-by';
        $partner = isset($status['badge_href']) && is_string($status['badge_href']) ? $status['badge_href'] : '';
        if ($partner !== '' && strpos($partner, $api_base . '/via/') === 0) {
            $href = $partner;
        }
        return ['show' => $show, 'href' => $href];
    }

    public static function refresh() {
        $query = self::lookup_query();
        $state = get_option(self::OPTION, []);
        $state = is_array($state) ? $state : [];
        $state['checked'] = time();

        if (!$query) {
            $state['show'] = false;
            $state['at']   = time();
            update_option(self::OPTION, $state, false);
            return;
        }

        $base = Bynli_Connect_Settings::api_base();
        $res  = wp_remote_get(add_query_arg(array_map('rawurlencode', $query), $base . '/api/sites/runtime-status'), [
            'timeout'             => 5,
            'redirection'         => 2,
            'limit_response_size' => 65536,
            'user-agent' => 'Bynli-Connect/' . BYNLI_CONNECT_VERSION . ' WP/' . get_bloginfo('version'),
            'headers'    => ['Accept' => 'application/json'],
        ]);

        if (is_wp_error($res) || (int) wp_remote_retrieve_response_code($res) !== 200) {
            error_log('[Bynli Connect] badge: status read failed — ' . (is_wp_error($res) ? $res->get_error_message() : 'HTTP ' . wp_remote_retrieve_response_code($res)));
            $state['failed_at'] = time();
            update_option(self::OPTION, $state, false);
            return;
        }

        $parsed = self::parse(wp_remote_retrieve_body($res), $base, wp_parse_url(home_url(), PHP_URL_HOST));
        update_option(self::OPTION, [
            'show'    => $parsed['show'],
            'href'    => $parsed['href'],
            'checked' => time(),
            'at'      => time(),
        ], false);
    }

    private function state() {
        $state = get_option(self::OPTION, []);
        if (!is_array($state)) {
            $state = [];
        }
        $age    = time() - (int) ($state['at'] ?? 0);
        $recent = time() - (int) ($state['checked'] ?? 0) < self::RETRY_AFTER;
        if (($age >= self::FRESH_FOR || empty($state['at'])) && !$recent && !wp_next_scheduled(self::REFRESH_HOOK)) {
            wp_schedule_single_event(time() + 5, self::REFRESH_HOOK);
        }
        if (empty($state['at']) || $age >= self::KEEP_FOR) {
            return null;
        }
        return $state;
    }

    private function visible() {
        if (is_admin() || wp_doing_ajax() || is_feed() || is_embed() || is_customize_preview()) {
            return false;
        }
        foreach (['elementor-preview', 'preview', 'bynli-preview', 'et_fb', 'fl_builder', 'ct_builder', 'brizy-edit', 'siteorigin_panels_live_editor'] as $builder) {
            if (isset($_GET[$builder])) {
                return false;
            }
        }
        if (isset($_GET['bricks']) && $_GET['bricks'] === 'run') {
            return false;
        }
        return true;
    }

    private function lift() {
        $lift = (int) apply_filters('bynli_connect_badge_lift', 0);
        return max(0, min(self::LIFT_MAX, $lift));
    }

    public function enqueue() {
        if (!$this->visible()) {
            return;
        }
        $state = $this->state();
        if ($state === null || empty($state['show'])) {
            return;
        }
        wp_enqueue_style('bynli-connect-badge', Bynli_Connect_Settings::api_base() . self::STYLE_PATH, [], null);
        wp_enqueue_script('bynli-connect-badge', plugins_url('assets/badge.js', BYNLI_CONNECT_PLUGIN_FILE), [], BYNLI_CONNECT_VERSION, true);
    }

    public function render() {
        if (!$this->visible()) {
            return;
        }
        $state = $this->state();
        if ($state === null || empty($state['show']) || empty($state['href'])) {
            return;
        }
        $base = Bynli_Connect_Settings::api_base();
        ?>
        <a id="bynli-connect-badge" class="bnf-pb bnf-pb--fixed" dir="ltr" href="<?php echo esc_url($state['href']); ?>" target="_blank" rel="noopener" aria-label="<?php echo esc_attr(self::LABEL); ?>" data-lift="<?php echo (int) $this->lift(); ?>">
            <img class="bnf-pb-mark" src="<?php echo esc_url($base . self::MARK_PATH); ?>" width="18" height="18" alt="" decoding="async">
            <span class="bnf-pb-lx bnf-pb-l1"><span><i>Powered by <b>Bynefit</b></i></span></span>
            <span class="bnf-pb-lx bnf-pb-l2"><span><i> &middot; Build yours free &rarr;</i></span></span>
        </a>
        <?php
    }
}
