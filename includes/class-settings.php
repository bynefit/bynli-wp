<?php
if (!defined('ABSPATH')) { exit; }

class Bynli_Connect_Settings {
    const OPTION_GROUP = 'bynli_connect';
    const OPTION_KEY   = 'bynli_connect_api_key';
    const OPTION_BASE  = 'bynli_connect_api_base';
    const OPTION_SLUG  = 'bynli_connect_site_slug';
    const MENU_SLUG    = 'bynli-connect';
    const NONCE_ACTION = 'bynli_connect_test';
    const NONCE_DISC   = 'bynli_connect_disconnect';
    const AJAX_ACTION  = 'bynli_connect_heartbeat';

    // Live shortcode picker: signed proxy to GET /api/site-host/forms.
    const AJAX_FORMS   = 'bynli_connect_forms';
    const NONCE_FORMS  = 'bynli_connect_forms';

    // Console sections. Order = rail order. Default is the first entry.
    const SECTIONS = ['overview', 'connection', 'shortcodes', 'tickets', 'activity', 'updates'];

    public function __construct() {
        add_action('admin_menu',           [$this, 'register_menu']);
        add_action('admin_init',           [$this, 'register_settings']);
        add_action('admin_enqueue_scripts',[$this, 'enqueue_assets']);
        add_action('admin_post_bynli_connect_test',       [$this, 'handle_test']);
        add_action('admin_post_bynli_connect_disconnect', [$this, 'handle_disconnect']);
        add_action('wp_ajax_' . self::AJAX_ACTION,        [$this, 'handle_ajax_heartbeat']);
        add_action('wp_ajax_' . self::AJAX_FORMS,         [$this, 'handle_ajax_forms']);
    }

    /**
     * Signed proxy to GET /api/site-host/forms for the shortcode picker — the
     * host key lives server-side, so the browser can't call the Bynefit API
     * directly. Cap + nonce gated; passes through id/title/slug only.
     */
    public function handle_ajax_forms(): void {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Forbidden.'], 403);
        }
        if (!check_ajax_referer(self::NONCE_FORMS, '_wpnonce', false)) {
            wp_send_json_error(['message' => 'Security check failed. Reload and try again.'], 403);
        }
        if (self::key() === '') {
            wp_send_json_error(['message' => 'Add your site-host key in Connection first.']);
        }
        $res = Bynli_Connect_Api::get('/api/site-host/forms');
        if (empty($res['ok'])) {
            $status = (is_int($res['status'] ?? null) && $res['status'] >= 400 && $res['status'] < 600)
                ? (int) $res['status'] : 502;
            wp_send_json_error([
                'message' => (string) ($res['message'] ?? 'Could not load your forms.'),
            ], $status);
        }
        $forms = isset($res['data']['forms']) && is_array($res['data']['forms']) ? $res['data']['forms'] : [];
        $out = [];
        foreach ($forms as $f) {
            $id = is_string($f['id'] ?? null) ? $f['id'] : '';
            if (!preg_match('/^frm_[A-Za-z0-9_\-]{6,40}$/', $id)) continue; // only valid form ids
            $out[] = [
                'id'    => $id,
                'title' => sanitize_text_field(is_string($f['title'] ?? null) ? $f['title'] : '(untitled form)'),
                'slug'  => sanitize_text_field(is_string($f['slug'] ?? null) ? $f['slug'] : ''),
            ];
        }
        wp_send_json_success(['forms' => $out]);
    }

    public static function key(): string {
        // Auto-provisioned managed sites are installed as an mu-plugin with the
        // key baked into a loader constant (no DB write needed) — that wins over
        // any saved option so the site is connected out of the box and can't be
        // disconnected by clearing the setting.
        if (defined('BYNLI_CONNECT_KEY') && BYNLI_CONNECT_KEY) {
            return (string) BYNLI_CONNECT_KEY;
        }
        return (string)get_option(self::OPTION_KEY, '');
    }
    /**
     * Constrain an API base to an absolute https origin, or reject it.
     *
     * esc_url_raw alone is not enough here. It preserves a scheme-relative '//host'
     * and it accepts 'http://', and this value now reaches two front-end <script src>
     * tags on public pages, so a typo or a hostile option write becomes third-party
     * script execution for every visitor. Applied on the way IN and again on the way
     * OUT, because an option saved before this existed is still in the database.
     */
    /** True only while the Settings API is running this as a save-path callback. */
    private static $sanitising_save = false;

    public static function sanitize_api_base_on_save($value): string
    {
        self::$sanitising_save = true;
        try {
            return self::sanitize_api_base($value);
        } finally {
            self::$sanitising_save = false;
        }
    }

    public static function sanitize_api_base($value, bool $allow_http = false): string
    {
        $raw = trim((string) $value);
        if ($raw === '') {
            return '';
        }
        $schemes = $allow_http ? ['https', 'http'] : ['https'];
        $url     = esc_url_raw($raw, $schemes);
        $parts   = $url === '' ? [] : (array) wp_parse_url($url);
        if (!empty($parts['host'])) {
            $parts['host'] = rtrim($parts['host'], '.');
        }
        if (empty($parts['scheme']) || !in_array($parts['scheme'], $schemes, true) || empty($parts['host'])) {
            self::reject_api_base('The API base must be an absolute https:// URL, for example '
                . BYNLI_CONNECT_DEFAULT_API_BASE . '.');
            return '';
        }
        if (!empty($parts['user']) || !empty($parts['pass'])) {
            self::reject_api_base('The API base must not carry a username or password.');
            return '';
        }
        $out = $parts['scheme'] . '://' . $parts['host'];
        if (!empty($parts['port'])) {
            $out .= ':' . (int) $parts['port'];
        }
        if (!empty($parts['path'])) {
            $out .= rtrim($parts['path'], '/');
        }
        return $out;
    }

    /**
     * Hosts a saved option may still name from before the rename, which must not be
     * honoured. A site connected under the old brand has the old host persisted in
     * the option, and the option beats the default — so changing the default alone
     * left every already-connected site signing requests to the old origin, and now
     * would point its front-end <script src> there too.
     */
    private const LEGACY_API_HOSTS = ['bynli.com', 'www.bynli.com'];

    /**
     * Surface a rejection where the admin will see it, and only when they are the one
     * who typed it. The same sanitiser runs on READ to clean a value saved before it
     * existed, and a settings error raised there would be attributed to whatever page
     * happened to load.
     */
    private static function reject_api_base(string $message): void
    {
        if (!self::$sanitising_save || !function_exists('add_settings_error')) {
            return;
        }
        add_settings_error(self::OPTION_BASE, 'bynli_connect_api_base_invalid', $message, 'error');
    }

    public static function api_base(): string {
        static $memo = [];
        $blog = function_exists('get_current_blog_id') ? (int) get_current_blog_id() : 0;
        if (isset($memo[$blog])) {
            return $memo[$blog];
        }
        $from_constant = defined('BYNLI_CONNECT_API_BASE') && BYNLI_CONNECT_API_BASE;
        $raw = $from_constant
            ? (string) BYNLI_CONNECT_API_BASE
            : (string) get_option(self::OPTION_BASE, '');
        $v = self::sanitize_api_base($raw, $from_constant);
        if ($v !== '') {
            $host = strtolower((string) (wp_parse_url($v, PHP_URL_HOST) ?: ''));
            if (in_array($host, self::LEGACY_API_HOSTS, true)) {
                $v = '';
            }
        }
        return $memo[$blog] = ($v !== '' ? $v : BYNLI_CONNECT_DEFAULT_API_BASE);
    }
    public static function site_slug(): string {
        return (string)get_option(self::OPTION_SLUG, '');
    }

    /** Active console section from ?section=, whitelisted, default 'overview'. */
    private function current_section(): string {
        $s = isset($_GET['section']) ? sanitize_key((string)$_GET['section']) : '';
        return in_array($s, self::SECTIONS, true) ? $s : self::SECTIONS[0];
    }

    public function enqueue_assets(string $hook): void {
        // Console page + Tickets page share the same admin styles (bcn-* CSS in
        // assets/admin.css). Both are submenus of options-general, so their hook
        // is 'settings_page_<slug>'. The Bricolage Google-Fonts request was
        // dropped in the Relay redesign (#29) — the new voice is system-sans +
        // mono-as-instrument and needs no external font.
        if (
            $hook !== 'settings_page_' . self::MENU_SLUG
            && $hook !== 'settings_page_' . Bynli_Connect_Tickets::MENU_SLUG
        ) return;
        $base = plugins_url('assets/', BYNLI_CONNECT_PLUGIN_FILE);
        wp_enqueue_style('dashicons');
        wp_enqueue_style('bynli-connect-admin', $base . 'admin.css', ['dashicons'], BYNLI_CONNECT_VERSION);
        wp_enqueue_script('bynli-connect-admin', $base . 'admin.js', [], BYNLI_CONNECT_VERSION, true);
        wp_localize_script('bynli-connect-admin', 'BynliConnect', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce(self::AJAX_ACTION),
        ]);
    }

    public function register_menu(): void {
        add_options_page(
            'Bynefit Connect',
            'Bynefit Connect',
            'manage_options',
            self::MENU_SLUG,
            [$this, 'render_page']
        );
    }

    public function register_settings(): void {
        register_setting(self::OPTION_GROUP, self::OPTION_KEY, [
            'type'              => 'string',
            'sanitize_callback' => [$this, 'sanitize_key'],
            'default'           => '',
        ]);
        register_setting(self::OPTION_GROUP, self::OPTION_BASE, [
            'type'              => 'string',
            'sanitize_callback' => [__CLASS__, 'sanitize_api_base_on_save'],
            'default'           => BYNLI_CONNECT_DEFAULT_API_BASE,
        ]);
        register_setting(self::OPTION_GROUP, self::OPTION_SLUG, [
            'type'              => 'string',
            'sanitize_callback' => [$this, 'sanitize_slug'],
            'default'           => '',
        ]);
    }

    public function sanitize_key($v): string {
        $v = trim((string)$v);
        if ($v === '') return '';
        if (!preg_match('/^bynli_sh_[0-9a-f]{32}$/', $v)) {
            add_settings_error(self::OPTION_KEY, 'bad_key', 'API key must look like bynli_sh_ followed by 32 hex characters.');
            return self::key();
        }
        return $v;
    }
    public function sanitize_slug($v): string {
        $v = trim((string)$v);
        return preg_replace('/[^a-z0-9\-]/', '', strtolower($v));
    }

    public function handle_test(): void {
        if (!current_user_can('manage_options')) wp_die('Forbidden.', 403);
        check_admin_referer(self::NONCE_ACTION);
        $res = Bynli_Connect_Reporter::send_heartbeat();
        $msg = !empty($res['ok']) ? 'ok' : 'fail';
        wp_safe_redirect(add_query_arg(
            ['page' => self::MENU_SLUG, 'section' => 'connection', 'tested' => $msg],
            admin_url('options-general.php')
        ));
        exit;
    }

    public function handle_ajax_heartbeat(): void {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Forbidden.'], 403);
        }
        if (!check_ajax_referer(self::AJAX_ACTION, '_wpnonce', false)) {
            wp_send_json_error(['message' => 'Security check failed. Refresh and try again.'], 400);
        }
        if (self::key() === '') {
            wp_send_json_error(['message' => 'Save an API key before testing.']);
        }
        $res = Bynli_Connect_Reporter::send_heartbeat();
        $last = Bynli_Connect_Reporter::last_report();
        if (!empty($res['ok'])) {
            wp_send_json_success([
                'message'       => 'Heartbeat OK. Bynefit received the ping.',
                'status'        => isset($res['status']) ? (int)$res['status'] : 200,
                'last_at_human' => !empty($last['at']) ? human_time_diff((int)$last['at']) . ' ago' : 'just now',
            ]);
        }
        $msg = isset($res['response']['error'])
            ? (string)$res['response']['error']
            : ((isset($res['message']) ? (string)$res['message'] : 'Heartbeat failed.'));
        wp_send_json_error([
            'message' => 'Heartbeat failed: ' . $msg,
            'status'  => isset($res['status']) ? (int)$res['status'] : 0,
        ]);
    }

    public function handle_disconnect(): void {
        if (!current_user_can('manage_options')) wp_die('Forbidden.', 403);
        check_admin_referer(self::NONCE_DISC);
        delete_option(self::OPTION_KEY);
        wp_safe_redirect(add_query_arg(
            ['page' => self::MENU_SLUG, 'section' => 'connection', 'disconnected' => '1'],
            admin_url('options-general.php')
        ));
        exit;
    }

    /** Deep-link to a console section (server-side, no-JS friendly). */
    private function section_url(string $section): string {
        return add_query_arg(
            ['page' => self::MENU_SLUG, 'section' => $section],
            admin_url('options-general.php')
        );
    }

    // ── Render ───────────────────────────────────────────────────────────

    public function render_page(): void {
        if (!current_user_can('manage_options')) wp_die('Forbidden.', 403);

        $ctx     = $this->build_context();
        $section = $this->current_section();
        ?>
        <div class="wrap bcn-wrap" dir="<?php echo is_rtl() ? 'rtl' : 'ltr'; ?>">
            <?php $this->render_topbar($ctx); ?>
            <?php settings_errors(); ?>
            <?php $this->render_flashes($ctx); ?>

            <div class="bcn-shell">
                <?php $this->render_rail($section, $ctx); ?>
                <main class="bcn-main" id="bcn-main">
                    <?php foreach (self::SECTIONS as $p):
                        $is_active = ($p === $section); ?>
                        <section class="bcn-panel<?php echo $is_active ? ' active' : ''; ?>"
                                 id="panel-<?php echo esc_attr($p); ?>"
                                 data-panel="<?php echo esc_attr($p); ?>"
                                 aria-label="<?php echo esc_attr(ucfirst($p)); ?>"
                                 <?php echo $is_active ? '' : 'hidden'; ?>>
                            <?php $this->render_section($p, $ctx, $is_active); ?>
                        </section>
                    <?php endforeach; ?>
                </main>
            </div>
        </div>
        <?php
    }

    /** One place to compute everything the panels read. */
    private function build_context(): array {
        $last = Bynli_Connect_Reporter::last_report();
        $upd  = Bynli_Connect_Updater::last_check_meta();
        $key  = self::key();

        $is_configured = ($key !== '');
        $is_connected  = $is_configured && !empty($last) && !empty($last['ok']);

        return [
            'last'             => $last,
            'upd'              => $upd,
            'history'          => Bynli_Connect_Reporter::history(), // read once; used by overview + activity
            'key'              => $key,
            'slug'             => self::site_slug(),
            'base'             => self::api_base(),
            'is_configured'    => $is_configured,
            'is_connected'     => $is_connected,
            'status_state'     => !$is_configured ? 'warn' : ($is_connected ? 'on' : 'off'),
            'status_label'     => !$is_configured ? 'Not connected' : ($is_connected ? 'Connected' : 'Not verified'),
            'next_cron'        => wp_next_scheduled('bynli_connect_daily_report'),
            'update_available' => !empty($upd['version']) && version_compare($upd['version'], BYNLI_CONNECT_VERSION, '>'),
            // Is that update something THIS ADMIN can act on? On a Bynefit-managed
            // site the plugin is an mu-plugin, WordPress cannot apply a plugin update
            // at all, and Bynefit pushes it on the next call-home — so the answer is
            // no, and every affordance that invites action must say so.
            //
            // Separate from update_available rather than replacing it: "a newer
            // version exists" is still true and the Updates panel still reports it.
            // What changes is whether the rail badge, the rail dot, the overview tile
            // and the activity entry present it as something to do.
            'update_actionable' => (!empty($upd['version'])
                && version_compare($upd['version'], BYNLI_CONNECT_VERSION, '>')
                && !Bynli_Connect_Updater::is_mu_install()),
            'tested'           => isset($_GET['tested']) ? sanitize_text_field((string)$_GET['tested']) : '',
            'cleared'          => isset($_GET['cleared']) && (string)$_GET['cleared'] === 'updates',
            'discon'           => isset($_GET['disconnected']) && (string)$_GET['disconnected'] === '1',
        ];
    }

    private function render_topbar(array $ctx): void {
        $host = wp_parse_url(home_url(), PHP_URL_HOST) ?: home_url();
        ?>
        <header class="bcn-topbar">
            <div class="bcn-brand">
                <span class="bcn-logo" aria-hidden="true">
                    <svg viewBox="0 0 24 24" width="22" height="22" fill="none">
                        <circle cx="12" cy="12" r="3.2" fill="currentColor"/>
                        <circle cx="12" cy="12" r="7" stroke="currentColor" stroke-width="1.4" opacity=".55"/>
                        <circle cx="12" cy="12" r="10.6" stroke="currentColor" stroke-width="1.2" opacity=".25"/>
                    </svg>
                </span>
                <span class="bcn-wordmark">Bynefit</span>
                <span class="bcn-tag">Connect</span>
            </div>
            <div class="bcn-topbar-spacer"></div>
            <span class="bcn-site-chip" title="<?php echo esc_attr(home_url()); ?>">
                <span class="dashicons dashicons-admin-site-alt3" aria-hidden="true"></span>
                <?php echo esc_html($host); ?>
            </span>
            <span class="bcn-signal-pill" data-bcn="signal" data-state="<?php echo esc_attr($ctx['status_state']); ?>">
                <span class="bcn-beacon" aria-hidden="true"></span>
                <span class="bcn-signal-label"><?php echo esc_html($ctx['status_label']); ?></span>
            </span>
        </header>
        <?php
    }

    private function render_flashes(array $ctx): void {
        if ($ctx['tested'] === 'ok'): ?>
            <div class="bcn-notice bcn-notice-ok"><span class="dashicons dashicons-yes-alt"></span>
                <span>Heartbeat sent — Bynefit received the ping.</span></div>
        <?php elseif ($ctx['tested'] === 'fail'): ?>
            <div class="bcn-notice bcn-notice-err"><span class="dashicons dashicons-warning"></span>
                <span>Heartbeat failed. Check the key + API base, then try again.</span></div>
        <?php endif;
        if ($ctx['cleared']): ?>
            <div class="bcn-notice bcn-notice-ok"><span class="dashicons dashicons-update"></span>
                <span>Update cache cleared. WordPress will re-check on the next page load.</span></div>
        <?php endif;
        if ($ctx['discon']): ?>
            <div class="bcn-notice bcn-notice-warn"><span class="dashicons dashicons-info-outline"></span>
                <span>Site disconnected. The API key was cleared from this WordPress install. Revoke it on Bynefit at <code>/dash/sites/host-keys</code> to invalidate it server-side too.</span></div>
        <?php endif;
    }

    private function render_rail(string $active, array $ctx): void {
        $items = [
            'overview'   => ['dashicons-chart-area',   'Overview'],
            'connection' => ['dashicons-admin-network', 'Connection'],
            'shortcodes' => ['dashicons-shortcode',    'Shortcodes'],
            'tickets'    => ['dashicons-sos',           'Tickets'],
            'activity'   => ['dashicons-backup',        'Activity'],
            'updates'    => ['dashicons-update',        'Updates'],
        ];
        // "Nothing for you to do" rather than "nothing pending": on a managed site an
        // available update is real but not the admin's to apply, so the rail reads calm
        // and the Updates panel explains the queue.
        $up_to_date = !$ctx['update_actionable'];
        ?>
        <nav class="bcn-rail" aria-label="Bynefit Connect sections">
            <?php foreach ($items as $key => [$icon, $label]):
                $is = ($key === $active); ?>
                <a class="bcn-nav-item<?php echo $is ? ' active' : ''; ?>"
                   href="<?php echo esc_url($this->section_url($key)); ?>"
                   data-go="<?php echo esc_attr($key); ?>"
                   <?php echo $key === 'tickets' ? 'data-server="1"' : ''; ?>
                   <?php echo $is ? 'aria-current="page"' : ''; ?>>
                    <span class="dashicons <?php echo esc_attr($icon); ?>" aria-hidden="true"></span>
                    <span class="bcn-rail-label"><?php echo esc_html($label); ?></span>
                    <?php if ($key === 'updates' && $ctx['update_actionable']): ?>
                        <span class="bcn-nav-count bcn-chip acc" aria-label="Update available">1</span>
                    <?php endif; ?>
                </a>
            <?php endforeach; ?>
            <div class="bcn-rail-foot">
                <span class="bcn-rail-ver">v<?php echo esc_html(BYNLI_CONNECT_VERSION); ?></span>
                <span class="bcn-dot <?php echo $up_to_date ? 'ok' : 'acc'; ?>" aria-hidden="true"></span>
                <span class="bcn-rail-ver-label"><?php echo $up_to_date ? 'Up to date' : 'Update ready'; ?></span>
            </div>
        </nav>
        <?php
    }

    /** Dispatch to a per-section renderer (whitelisted section names). */
    private function render_section(string $section, array $ctx, bool $active = false): void {
        switch ($section) {
            case 'overview':   $this->render_overview($ctx);        break;
            case 'connection': $this->render_connection($ctx);      break;
            case 'shortcodes': $this->render_shortcodes($ctx);      break;
            case 'tickets':    $this->render_tickets($ctx, $active); break;
            case 'activity':   $this->render_activity($ctx);        break;
            case 'updates':    $this->render_updates($ctx);         break;
        }
    }

    // ── Sections ─────────────────────────────────────────────────────────
    // Phase 2 wires the shell + these entry points. The hero instrument
    // (overview), the data-driven shortcode previewer, the activity log, and
    // the tickets fold are built out in later phases of #29; where a surface
    // already had content it is carried here and restyled in its own phase.

    /** Normalized 0..1 heartbeat series (one point per recent post, oldest→newest). */
    private function spark_series(array $history): array {
        $pts = [];
        foreach ($history as $h) {
            $pts[] = !empty($h['ok']) ? 1.0 : 0.2;
        }
        return $pts;
    }

    private function render_overview(array $ctx): void {
        if (!$ctx['is_configured']) { $this->render_onboard(); return; }

        $is_connected = $ctx['is_connected'];
        $last         = $ctx['last'];
        $history      = $ctx['history'];
        $series       = $this->spark_series($history);
        $last_ok      = empty($history) ? true : !empty($history[count($history) - 1]['ok']);

        $hero_state = $is_connected ? 'on' : 'off';
        $readout    = $is_connected ? 'CONNECTED' : 'NOT VERIFIED';
        $sub        = $is_connected
            ? 'Reporting to Bynefit' . (!empty($last['at']) ? ' · last ping ' . esc_html(human_time_diff((int)$last['at'])) . ' ago' : '')
            : 'Key saved — send a test heartbeat in Connection to verify the signature path.';

        $storage   = isset($last['storage_bytes']) && $last['storage_bytes'] !== null ? (int)$last['storage_bytes'] : null;
        $next_cron = $ctx['next_cron'];

        // Health tiles.
        $update_available  = $ctx['update_available'];
        $update_actionable = $ctx['update_actionable'];
        $daily_recent = !empty($last['at']) && (time() - (int)$last['at']) < 2 * DAY_IN_SECONDS;
        ?>
        <div class="bcn-hero" data-state="<?php echo esc_attr($hero_state); ?>">
            <div class="bcn-hero-body">
                <div class="bcn-hero-lead">
                    <span class="bcn-hero-eyebrow">Uplink</span>
                    <div class="bcn-hero-readout">
                        <span class="bcn-hero-dot" aria-hidden="true"></span>
                        <span class="bcn-hero-status"><?php echo esc_html($readout); ?></span>
                    </div>
                    <p class="bcn-hero-sub"><?php echo wp_kses_post($sub); ?></p>
                </div>
                <figure class="bcn-hero-spark">
                    <div class="bcn-spark"
                         data-series="<?php echo esc_attr(wp_json_encode($series)); ?>"
                         data-ok="<?php echo $last_ok ? '1' : '0'; ?>"
                         role="img"
                         aria-label="<?php echo esc_attr(sprintf('Heartbeat over the last %d reports', count($series))); ?>"></div>
                    <figcaption class="bcn-spark-cap">7-day heartbeat</figcaption>
                </figure>
            </div>
            <div class="bcn-metric-strip">
                <div class="bcn-metric">
                    <span class="bcn-metric-label">Storage</span>
                    <span class="bcn-metric-value"><?php echo $storage !== null ? esc_html(size_format($storage, 1)) : '—'; ?></span>
                </div>
                <div class="bcn-metric">
                    <span class="bcn-metric-label">WordPress</span>
                    <span class="bcn-metric-value"><?php echo esc_html(get_bloginfo('version')); ?></span>
                </div>
                <div class="bcn-metric">
                    <span class="bcn-metric-label">PHP</span>
                    <span class="bcn-metric-value"><?php echo esc_html(PHP_VERSION); ?></span>
                </div>
                <div class="bcn-metric">
                    <span class="bcn-metric-label">Next report</span>
                    <span class="bcn-metric-value"><?php echo $next_cron ? esc_html(human_time_diff(time(), (int)$next_cron)) : 'off'; ?></span>
                </div>
            </div>
        </div>

        <div class="bcn-tiles">
            <div class="bcn-tile" data-state="<?php echo $is_connected ? 'ok' : 'warn'; ?>">
                <span class="dashicons <?php echo $is_connected ? 'dashicons-yes-alt' : 'dashicons-warning'; ?>" aria-hidden="true"></span>
                <span class="bcn-tile-label">Connection</span>
                <span class="bcn-tile-value"><?php echo $is_connected ? 'Verified' : 'Unverified'; ?></span>
            </div>
            <div class="bcn-tile" data-state="<?php echo $daily_recent ? 'ok' : 'warn'; ?>">
                <span class="dashicons dashicons-backup" aria-hidden="true"></span>
                <span class="bcn-tile-label">Daily report</span>
                <span class="bcn-tile-value"><?php echo !empty($last['at']) ? esc_html(human_time_diff((int)$last['at']) . ' ago') : 'never'; ?></span>
            </div>
            <div class="bcn-tile" data-state="<?php echo $update_actionable ? 'acc' : 'ok'; ?>">
                <span class="dashicons dashicons-update" aria-hidden="true"></span>
                <span class="bcn-tile-label">Plugin</span>
                <span class="bcn-tile-value"><?php echo $update_actionable ? 'Update ready' : 'v' . esc_html(BYNLI_CONNECT_VERSION); ?></span>
            </div>
            <div class="bcn-tile" data-state="ok">
                <span class="dashicons dashicons-lock" aria-hidden="true"></span>
                <span class="bcn-tile-label">Signing</span>
                <span class="bcn-tile-value">HMAC-SHA256</span>
            </div>
        </div>

        <div class="bcn-quick">
            <a class="bcn-btn primary sm" href="<?php echo esc_url($this->section_url('connection')); ?>">
                <span class="dashicons dashicons-admin-network"></span> Connection
            </a>
            <a class="bcn-btn sm" href="<?php echo esc_url($this->section_url('shortcodes')); ?>">
                <span class="dashicons dashicons-shortcode"></span> Shortcodes
            </a>
            <a class="bcn-btn sm" href="<?php echo esc_url($this->section_url('tickets')); ?>">
                <span class="dashicons dashicons-sos"></span> Support
            </a>
        </div>
        <?php
    }

    private function render_onboard(): void {
        ?>
        <section class="bcn-card">
            <div class="bcn-card-head"><h2>Getting started</h2></div>
            <div class="bcn-card-body">
                <p>Generate a site-host key on Bynefit, paste it in <strong>Connection</strong>, and this WordPress install starts reporting and unlocks every shortcode.</p>
                <ol>
                    <li>Open <a href="https://bynefit.com/dash/sites/host-keys" target="_blank" rel="noopener">/dash/sites/host-keys</a> signed in as a team admin.</li>
                    <li>Pick this site, <strong>Generate key</strong>, copy the plaintext value — shown once.</li>
                    <li>Paste it into the API key field in Connection and save.</li>
                </ol>
                <a class="bcn-btn primary" href="<?php echo esc_url($this->section_url('connection')); ?>">Go to Connection</a>
            </div>
        </section>
        <?php
    }

    private function render_connection(array $ctx): void {
        $key = $ctx['key']; $slug = $ctx['slug']; $base = $ctx['base'];
        $is_configured = $ctx['is_configured'];
        // Masked signature readout — never render the full key. bynli_sh_ + 4
        // leading hex, then the last 3, is enough to identify without exposing.
        $masked = ($key !== '' && strlen($key) >= 16)
            ? substr($key, 0, 13) . '•••' . substr($key, -3)
            : '';
        ?>
        <section class="bcn-card">
            <div class="bcn-card-head">
                <h2>Connection</h2>
                <span class="bcn-card-sub">Per-site key &amp; signing base</span>
            </div>
            <div class="bcn-card-body">
                <form action="options.php" method="post">
                    <?php settings_fields(self::OPTION_GROUP); ?>
                    <input type="hidden" name="_wp_http_referer" value="<?php echo esc_attr(add_query_arg(['page' => self::MENU_SLUG, 'section' => 'connection'], admin_url('options-general.php'))); ?>">

                    <div class="bcn-field">
                        <label class="bcn-label" for="bcn_key">API key</label>
                        <div class="bcn-input-wrap">
                            <input class="bcn-input" id="bcn_key" name="<?php echo esc_attr(self::OPTION_KEY); ?>"
                                   type="password" autocomplete="off" spellcheck="false"
                                   value="<?php echo esc_attr($key); ?>"
                                   placeholder="bynli_sh_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx">
                            <button type="button" class="bcn-icon-btn bcn-toggle-reveal"
                                    data-target="bcn_key" aria-label="Show key" aria-pressed="false">
                                <span class="dashicons dashicons-visibility"></span>
                            </button>
                            <?php if ($key !== ''): ?>
                                <button type="button" class="bcn-icon-btn bcn-copy"
                                        data-target="bcn_key" aria-label="Copy key">
                                    <span class="dashicons dashicons-admin-page"></span>
                                </button>
                            <?php endif; ?>
                        </div>
                        <div class="bcn-validity" id="bcn-key-validity" aria-live="polite"></div>
                        <p class="bcn-hint">Generate at <code>/dash/sites/host-keys</code> on Bynefit. Format: <code>bynli_sh_</code> + 32 hex. This technical identifier is unchanged by the rebrand.</p>
                    </div>

                    <div class="bcn-field">
                        <label class="bcn-label" for="bcn_slug">Site slug</label>
                        <div class="bcn-input-wrap">
                            <input class="bcn-input sans" id="bcn_slug" name="<?php echo esc_attr(self::OPTION_SLUG); ?>"
                                   type="text" value="<?php echo esc_attr($slug); ?>" placeholder="my-team-site">
                        </div>
                        <p class="bcn-hint">Optional. The Bynefit team-site slug this install represents — used only for telemetry.</p>
                    </div>

                    <div class="bcn-field">
                        <label class="bcn-label" for="bcn_base">API base</label>
                        <div class="bcn-input-wrap">
                            <input class="bcn-input" id="bcn_base" name="<?php echo esc_attr(self::OPTION_BASE); ?>"
                                   type="url" value="<?php echo esc_attr($base); ?>">
                        </div>
                        <p class="bcn-hint">Leave as <code><?php echo esc_html(BYNLI_CONNECT_DEFAULT_API_BASE); ?></code> unless testing against staging.</p>
                    </div>

                    <div class="bcn-actions">
                        <button type="submit" class="bcn-btn primary">
                            <span class="dashicons dashicons-yes"></span> Save settings
                        </button>
                        <?php if ($is_configured): ?>
                            <button type="button" class="bcn-btn danger" id="bcn-disconnect-btn"
                                    onclick="document.getElementById('bcn-disconnect-form').submit()">
                                Disconnect
                            </button>
                        <?php endif; ?>
                    </div>
                </form>

                <?php if ($is_configured): ?>
                    <form id="bcn-disconnect-form" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="post" hidden>
                        <input type="hidden" name="action" value="bynli_connect_disconnect">
                        <?php wp_nonce_field(self::NONCE_DISC); ?>
                    </form>
                <?php endif; ?>
            </div>

            <?php if ($is_configured): ?>
                <div class="bcn-card-body">
                    <div class="bcn-readout" aria-label="Active signature">
                        <div class="bcn-readout-row">
                            <span class="bcn-readout-k">key</span>
                            <span class="bcn-readout-v"><?php echo esc_html($masked); ?></span>
                        </div>
                        <div class="bcn-readout-row">
                            <span class="bcn-readout-k">sign</span>
                            <span class="bcn-readout-v">HMAC-SHA256 · X-Bynli-Signature</span>
                        </div>
                        <div class="bcn-readout-row">
                            <span class="bcn-readout-k">base</span>
                            <span class="bcn-readout-v"><?php echo esc_html($base); ?></span>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <div class="bcn-card-body">
                <div class="bcn-actions">
                    <button type="button" class="bcn-btn ink" id="bcn-heartbeat-btn"
                            <?php disabled(!$is_configured); ?> aria-disabled="<?php echo $is_configured ? 'false' : 'true'; ?>">
                        <span class="dashicons dashicons-share-alt2"></span> Send test heartbeat
                    </button>
                    <span class="bcn-action-hint">A one-off ping — verifies the signature path end-to-end. No usage row is recorded.</span>
                </div>
                <div class="bcn-note" id="bcn-heartbeat-status" aria-live="polite" hidden>
                    <span class="dashicons" data-role="ico" aria-hidden="true"></span>
                    <span data-role="msg"></span>
                </div>
            </div>
        </section>
        <?php
        Bynli_Connect_Control_Plane::render_card();
        Bynli_Connect_Visibility::render_card();
        Bynli_Connect_Client_Mode::render_card();
    }

    /**
     * The 7 shortcodes, mirroring Bynli_Connect_Shortcodes. Attribute rows are
     * [name, accepts, default]; the single source of truth for the attrs is
     * class-shortcodes.php — keep these in step with it.
     */
    private function shortcode_catalog(): array {
        return [
            'bynli-form' => [
                'label' => 'Form', 'preview' => 'form',
                'desc'  => 'Embed a Bynefit form. Submissions land in the team’s Bynefit inbox + email.',
                'code'  => '[bynli-form id="frm_abc123"]',
                'attrs' => [
                    ['id', 'Form id from Bynefit (frm_…) — required', '—'],
                    ['style', 'default · bootstrap · bare', 'default'],
                    ['success', 'Message shown after submit', '—'],
                    ['success_mode', 'toast · replace · hide', 'toast'],
                ],
            ],
            'bynli-events' => [
                'label' => 'Events', 'preview' => 'events',
                'desc'  => 'Read-only list of a team’s events.',
                'code'  => '[bynli-events team="your-team" limit="5" style="cards"]',
                'attrs' => [
                    ['team', 'Team slug — required', '—'],
                    ['limit', '1–50', '5'],
                    ['style', 'cards · list · bare', 'cards'],
                    ['scope', 'upcoming · past', 'upcoming'],
                ],
            ],
            'bynli-donate' => [
                'label' => 'Donate', 'preview' => 'donate',
                'desc'  => 'Donation card with preset + custom amounts.',
                'code'  => '[bynli-donate team="your-team" amounts="10,25,50,100" default_amount="25" cause="general"]',
                'attrs' => [
                    ['team', 'Team slug — required', '—'],
                    ['amounts', 'Comma-separated presets', '—'],
                    ['default_amount', 'Pre-selected amount', '—'],
                    ['cause', 'Cause key', '—'],
                    ['style', 'card · button', 'card'],
                ],
            ],
            'bynli-modal' => [
                'label' => 'Modal', 'preview' => 'modal',
                'desc'  => 'A button that opens a Bynefit modal.',
                'code'  => '[bynli-modal label="Read more" title="Welcome" body="Thanks for stopping by."]',
                'attrs' => [
                    ['label', 'Button text', 'Open'],
                    ['title', 'Modal heading', '—'],
                    ['body', 'Modal body text', '—'],
                    ['href', 'Optional link the confirm follows', '—'],
                ],
            ],
            'bynli-confirm' => [
                'label' => 'Confirm', 'preview' => 'confirm',
                'desc'  => 'Confirm prompt before navigation.',
                'code'  => '[bynli-confirm label="Sign out" message="Sign out now?" href="/logout"]',
                'attrs' => [
                    ['label', 'Button text', 'Continue'],
                    ['message', 'Prompt shown', 'Are you sure?'],
                    ['href', 'Where “yes” goes', '—'],
                    ['danger', '1 for a destructive style', '—'],
                ],
            ],
            'bynli-toast' => [
                'label' => 'Toast', 'preview' => 'toast',
                'desc'  => 'A toast on load, or on a button press.',
                'code'  => '[bynli-toast message="Welcome back!" kind="success"]',
                'attrs' => [
                    ['message', 'Toast text — required', '—'],
                    ['kind', 'info · success · error · warning', 'info'],
                    ['on', 'load · click', 'load'],
                    ['label', 'Button text when on="click"', 'Show'],
                ],
            ],
            'bynli-widget' => [
                'label' => 'Widget', 'preview' => 'widget',
                'desc'  => 'Floating Bynefit bubble (loads its own script).',
                'code'  => '[bynli-widget team="your-team"]',
                'attrs' => [
                    ['team', 'Team slug — required', '—'],
                    ['position', 'Corner placement', '—'],
                    ['label', 'Bubble label', '—'],
                ],
            ],
        ];
    }

    /** Token-color a shortcode string into safe span HTML for the code block. */
    private function sc_code_html(string $code): string {
        $inner = preg_replace('/^\[|\]$/', '', trim($code));
        if (!preg_match('/^([a-z0-9\-]+)\s*(.*)$/s', (string) $inner, $m)) {
            return esc_html($code);
        }
        $html = '<span class="bcn-cb-b">[</span><span class="bcn-cb-t">' . esc_html($m[1]) . '</span>';
        if (preg_match_all('/([a-z0-9_\-]+)="([^"]*)"/i', $m[2], $pairs, PREG_SET_ORDER)) {
            foreach ($pairs as $p) {
                $html .= ' <span class="bcn-cb-a">' . esc_html($p[1]) . '</span>'
                       . '<span class="bcn-cb-p">=</span>'
                       . '<span class="bcn-cb-v">"' . esc_html($p[2]) . '"</span>';
            }
        }
        return $html . '<span class="bcn-cb-b">]</span>';
    }

    /** Illustrative (non-functional) mock of each component for the preview frame. */
    private function sc_preview(string $kind): void {
        switch ($kind) {
            case 'form':
                echo '<div class="bcn-pv-form"><span class="bcn-pv-input">you@example.com</span>'
                   . '<span class="bcn-btn primary sm">Submit</span></div>';
                break;
            case 'events':
                echo '<div class="bcn-pv-events">'
                   . '<div class="bcn-pv-ev"><span class="bcn-pv-ev-d">SAT 12</span><span>Volunteer morning</span></div>'
                   . '<div class="bcn-pv-ev"><span class="bcn-pv-ev-d">TUE 15</span><span>Community dinner</span></div></div>';
                break;
            case 'donate':
                echo '<div class="bcn-pv-donate"><span class="bcn-pv-amt">$10</span>'
                   . '<span class="bcn-pv-amt is-on">$25</span><span class="bcn-pv-amt">$50</span>'
                   . '<span class="bcn-btn primary sm">Donate</span></div>';
                break;
            case 'modal':
                echo '<button type="button" class="bcn-btn sm" disabled>Read more</button>';
                break;
            case 'confirm':
                echo '<button type="button" class="bcn-btn danger sm" disabled>Sign out</button>';
                break;
            case 'toast':
                echo '<span class="bcn-pv-toast"><span class="dashicons dashicons-yes-alt"></span> Welcome back!</span>';
                break;
            case 'widget':
                echo '<span class="bcn-pv-bubble"><span class="dashicons dashicons-format-chat"></span></span>';
                break;
        }
    }

    private function render_sc_detail(string $tag, array $e): void {
        ?>
        <div class="bcn-sc-head">
            <h3 class="bcn-sc-title"><?php echo esc_html($e['label']); ?></h3>
            <p class="bcn-sc-desc"><?php echo esc_html($e['desc']); ?></p>
        </div>
        <div class="bcn-code-row">
            <pre class="bcn-code-block"><?php echo $this->sc_code_html($e['code']); // token spans, all values esc_html'd ?></pre>
            <button type="button" class="bcn-sc-copy" data-text="<?php echo esc_attr($e['code']); ?>">Copy</button>
        </div>
        <?php if ($tag === 'bynli-form' && self::key() !== ''): ?>
            <div class="bcn-sc-picker">
                <button type="button" class="bcn-btn sm" data-bcn-load-forms
                        data-nonce="<?php echo esc_attr(wp_create_nonce(self::NONCE_FORMS)); ?>">
                    <span class="dashicons dashicons-download"></span> Load my forms
                </button>
                <span class="bcn-hint">Pick one of your Bynefit forms to drop its real id straight into the shortcode — no trip to Bynefit.</span>
                <div class="bcn-form-list" data-role="forms-list"></div>
            </div>
        <?php endif; ?>
        <div class="bcn-preview-frame" data-label="Preview">
            <?php $this->sc_preview($e['preview']); ?>
        </div>
        <div class="bcn-attr-wrap">
            <table class="bcn-attr-table">
                <thead><tr><th>Attribute</th><th>Accepts</th><th>Default</th></tr></thead>
                <tbody>
                    <?php foreach ($e['attrs'] as $row): ?>
                        <tr>
                            <td><code><?php echo esc_html($row[0]); ?></code></td>
                            <td><?php echo esc_html($row[1]); ?></td>
                            <td><?php echo esc_html($row[2]); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    private function render_shortcodes(array $ctx): void {
        $cat   = $this->shortcode_catalog();
        $tags  = array_keys($cat);
        $first = $tags[0];
        ?>
        <section class="bcn-card">
            <div class="bcn-card-head">
                <h2>Shortcodes</h2>
                <span class="bcn-card-sub">Drop any Bynefit component into a post or page</span>
            </div>
            <div class="bcn-card-body">
                <div class="bcn-sc-layout">
                    <div class="bcn-sc-list" role="group" aria-label="Shortcodes">
                        <?php foreach ($cat as $tag => $e): $on = ($tag === $first); ?>
                            <button type="button" class="bcn-sc-item<?php echo $on ? ' active' : ''; ?>"
                                    data-sc="<?php echo esc_attr($tag); ?>"
                                    aria-pressed="<?php echo $on ? 'true' : 'false'; ?>">
                                <span class="bcn-sc-item-name"><?php echo esc_html($e['label']); ?></span>
                                <code class="bcn-sc-item-tag">[<?php echo esc_html($tag); ?>]</code>
                            </button>
                        <?php endforeach; ?>
                    </div>
                    <div class="bcn-sc-detail-wrap">
                        <?php foreach ($cat as $tag => $e): $on = ($tag === $first); ?>
                            <div class="bcn-sc-detail<?php echo $on ? ' active' : ''; ?>"
                                 data-sc-detail="<?php echo esc_attr($tag); ?>" <?php echo $on ? '' : 'hidden'; ?>>
                                <?php $this->render_sc_detail($tag, $e); ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <p class="bcn-hint bcn-pad-top">Full reference at <a href="https://bynefit.com/help/wordpress" target="_blank" rel="noopener">/help/wordpress</a>.</p>
            </div>
        </section>
        <?php
    }

    private function render_tickets(array $ctx, bool $active): void {
        // Tickets is a SERVER-rendered surface — render_panel() makes a remote
        // API call, so we only invoke it when tickets is the active section.
        // Its rail item carries data-server so clicking it does a full
        // navigation (reload) rather than a client-side panel swap, which is
        // what makes the server render the fetched tickets.
        if (!$active) {
            ?>
            <div class="bcn-note info">
                <span class="dashicons dashicons-sos" aria-hidden="true"></span>
                <span><a href="<?php echo esc_url(Bynli_Connect_Tickets::console_url()); ?>">Open the Tickets section</a> to load your team’s support threads.</span>
            </div>
            <?php
            return;
        }
        Bynli_Connect_Tickets::render_panel();
    }

    private function render_activity(array $ctx): void {
        $history = array_reverse($ctx['history']); // newest first
        $upd     = $ctx['upd'];
        $next    = $ctx['next_cron'];

        // Update-check event as the newest log entry (it has no per-check
        // timestamp; last_updated is the release date, shown when present).
        $update_event = null;
        if (!empty($upd['has'])) {
            if (!empty($upd['error'])) {
                $update_event = ['state' => 'warn', 'ico' => 'dashicons-warning', 'title' => 'Update check failed', 'detail' => (string)$upd['error']];
            } elseif ($ctx['update_actionable']) {
                $update_event = ['state' => 'acc', 'ico' => 'dashicons-update', 'title' => 'Update available', 'detail' => 'v' . (string)$upd['version']];
            } else {
                $update_event = ['state' => 'ok', 'ico' => 'dashicons-yes-alt', 'title' => 'Up to date', 'detail' => 'v' . BYNLI_CONNECT_VERSION];
            }
        }
        ?>
        <section class="bcn-card">
            <div class="bcn-card-head">
                <h2>Activity</h2>
                <span class="bcn-card-sub">Recent uplink signals &amp; checks<?php echo $next ? ' · next report in ' . esc_html(human_time_diff(time(), (int)$next)) : ''; ?></span>
            </div>
            <div class="bcn-card-body">
                <?php if (empty($history) && $update_event === null): ?>
                    <div class="bcn-empty" role="status">
                        <span class="dashicons dashicons-backup bcn-empty-icon" aria-hidden="true"></span>
                        <p class="bcn-empty-title">No activity yet.</p>
                        <p class="bcn-empty-cta">The first daily report lands within 24 hours — or send a test heartbeat in Connection.</p>
                    </div>
                <?php else: ?>
                    <ul class="bcn-log">
                        <?php if ($update_event !== null): ?>
                            <li class="bcn-log-item">
                                <span class="bcn-log-ico <?php echo esc_attr($update_event['state']); ?>" aria-hidden="true"><span class="dashicons <?php echo esc_attr($update_event['ico']); ?>"></span></span>
                                <div class="bcn-log-main">
                                    <span class="bcn-log-title"><?php echo esc_html($update_event['title']); ?></span>
                                    <span class="bcn-log-detail"><?php echo esc_html($update_event['detail']); ?></span>
                                </div>
                                <span class="bcn-log-time">update check</span>
                            </li>
                        <?php endif; ?>
                        <?php foreach ($history as $h):
                            $ok     = !empty($h['ok']);
                            $kind   = (string)($h['kind'] ?? 'report');
                            $at     = (int)($h['at'] ?? 0);
                            $status = (int)($h['status'] ?? 0);
                            $title  = ($kind === 'heartbeat' ? 'Heartbeat' : 'Daily report') . ($ok ? ' delivered' : ' failed'); ?>
                            <li class="bcn-log-item">
                                <span class="bcn-log-ico <?php echo $ok ? 'ok' : 'down'; ?>" aria-hidden="true"><span class="dashicons <?php echo $ok ? 'dashicons-yes' : 'dashicons-no-alt'; ?>"></span></span>
                                <div class="bcn-log-main">
                                    <span class="bcn-log-title"><?php echo esc_html($title); ?></span>
                                    <span class="bcn-log-detail"><code><?php echo esc_html($kind); ?></code> · HTTP <?php echo esc_html($status ? (string)$status : '—'); ?></span>
                                </div>
                                <span class="bcn-log-time"><?php echo $at ? esc_html(human_time_diff($at) . ' ago') : ''; ?></span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </section>
        <?php
    }

    private function render_updates(array $ctx): void {
        $upd = $ctx['upd']; $update_available = $ctx['update_available'];
        $last = $ctx['last'];
        // On a Bynefit-managed site this plugin is an mu-plugin, and WordPress cannot
        // apply a plugin update there at all. Offering the same buttons as a self-hosted
        // site meant an admin clicked "Check for updates now", was told a newer version
        // existed, and had nothing they could do with that.
        $managed = Bynli_Connect_Updater::is_mu_install();
        ?>
        <section class="bcn-card">
            <div class="bcn-card-head">
                <h2>Updates</h2>
                <span class="bcn-card-sub"><?php echo $managed
                    ? 'Applied for you by Bynefit'
                    : 'Released directly from Bynefit'; ?></span>
            </div>
            <div class="bcn-card-body">
                <div class="bcn-up-row">
                    <span class="bcn-up-label">Installed</span>
                    <span class="bcn-up-value"><code>v<?php echo esc_html(BYNLI_CONNECT_VERSION); ?></code></span>
                </div>
                <div class="bcn-up-row">
                    <span class="bcn-up-label">Latest</span>
                    <span class="bcn-up-value">
                        <?php if (!empty($upd['version'])): ?>
                            <code>v<?php echo esc_html($upd['version']); ?></code>
                            <?php if ($update_available && $managed): ?>
                                <?php /* On a managed site the update is real but is not the
                                     admin's to apply, so an accent "Update available" chip
                                     contradicted the notice directly below it — which says
                                     there is nothing for them to do. Four affordances were
                                     routed through update_actionable for this reason and this
                                     fifth one, inside the panel itself, kept reading the raw
                                     version comparison. */ ?>
                                <span class="bcn-chip ok">Update queued</span>
                            <?php elseif ($update_available): ?>
                                <span class="bcn-chip acc">Update available</span>
                            <?php else: ?>
                                <span class="bcn-chip ok">Up to date</span>
                            <?php endif; ?>
                        <?php else: ?>
                            <span class="bcn-stat-value-em">not checked yet</span>
                        <?php endif; ?>
                    </span>
                </div>
                <?php if (!empty($upd['last_updated'])): ?>
                    <div class="bcn-up-row">
                        <span class="bcn-up-label">Released</span>
                        <span class="bcn-up-value"><?php echo esc_html($upd['last_updated']); ?></span>
                    </div>
                <?php endif; ?>
                <?php if (!empty($upd['error']) && !$managed): ?>
                    <div class="bcn-up-row">
                        <span class="bcn-up-label">Last error</span>
                        <span class="bcn-up-value"><code><?php echo esc_html($upd['error']); ?></code></span>
                    </div>
                <?php endif; ?>

                <?php if ($managed): ?>
                    <?php
                    $checkin_at    = !empty($last['at']) ? (int) $last['at'] : 0;
                    $checkin_stale = $checkin_at === 0 || (time() - $checkin_at) > DAY_IN_SECONDS;
                    ?>
                    <?php $readout_failed = !empty($upd['error']); ?>
                    <div class="bcn-notice <?php echo ($checkin_stale || $readout_failed) ? 'bcn-notice-warn' : 'bcn-notice-ok'; ?> bcn-pad-top">
                        <span class="dashicons <?php
                            echo ($checkin_stale || $readout_failed) ? 'dashicons-warning'
                                : ($update_available ? 'dashicons-update' : 'dashicons-yes-alt');
                        ?>" aria-hidden="true"></span>
                        <?php if ($update_available): ?>
                            <strong>Update queued.</strong> Bynefit keeps this site&rsquo;s plugin up to
                            date for you, and will apply v<?php echo esc_html((string) ($upd['version'] ?? '')); ?>
                            on this site&rsquo;s next check-in.
                        <?php elseif ($readout_failed): ?>
                            <strong>Version check failed.</strong> The last attempt to read the release
                            manifest returned <code><?php echo esc_html($upd['error']); ?></code>, so the
                            version shown above may be out of date. Bynefit still applies updates for
                            you; this affects what this panel can tell you, not whether the site is
                            kept current.
                        <?php else: ?>
                            <strong>Up to date.</strong> Bynefit keeps this site&rsquo;s plugin up to date
                            for you &mdash; updates arrive automatically, with no action from you.
                        <?php endif; ?>
                        <?php if ($checkin_at === 0): ?>
                            This site has never checked in, so that may not be happening &mdash; contact
                            Bynefit if it stays this way.
                        <?php elseif ($checkin_stale): ?>
                            Last check-in was <?php echo esc_html(human_time_diff($checkin_at)); ?> ago,
                            longer than the daily window, so the next one may be overdue.
                        <?php else: ?>
                            Last check-in: <?php echo esc_html(human_time_diff($checkin_at)); ?> ago.
                        <?php endif; ?>
                    </div>
                    <div class="bcn-actions bcn-pad-top">
                        <form action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="post">
                            <input type="hidden" name="action" value="bynli_connect_clear_update_cache">
                            <?php wp_nonce_field('bynli_connect_clear_update_cache'); ?>
                            <button type="submit" class="bcn-btn ink">Refresh this readout</button>
                        </form>
                        <span class="bcn-action-hint">This site runs the plugin from WordPress&rsquo;s
                            must-use directory, which has no update button &mdash; that is why it is not
                            on your Plugins screen and why Bynefit applies the update instead.
                            Refreshing re-reads the version above; it installs nothing.</span>
                    </div>
                <?php else: ?>
                    <div class="bcn-actions bcn-pad-top">
                        <?php if ($update_available): ?>
                            <a class="bcn-btn primary" href="<?php echo esc_url(admin_url('plugins.php')); ?>">
                                <span class="dashicons dashicons-update"></span> Go to Plugins &rarr; Update
                            </a>
                        <?php endif; ?>
                        <form action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="post">
                            <input type="hidden" name="action" value="bynli_connect_clear_update_cache">
                            <?php wp_nonce_field('bynli_connect_clear_update_cache'); ?>
                            <button type="submit" class="bcn-btn ink">Check for updates now</button>
                        </form>
                        <span class="bcn-action-hint">WordPress polls Bynefit every 12 hours.</span>
                    </div>
                <?php endif; ?>

                <?php if (!empty($upd['changelog'])): ?>
                    <div class="bcn-changelog">
                        <h3 class="bcn-changelog-title">Changelog</h3>
                        <div class="bcn-cl-body"><?php echo wp_kses_post($upd['changelog']); ?></div>
                    </div>
                <?php endif; ?>
            </div>
        </section>
        <?php
    }
}
