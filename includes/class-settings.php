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

    // Relay console (#29): per-user theme override, persisted in user_meta.
    const THEME_META   = 'bynli_connect_theme';
    const AJAX_THEME   = 'bynli_connect_theme';
    const NONCE_THEME  = 'bynli_connect_theme';

    // Console sections. Order = rail order. Default is the first entry.
    const SECTIONS = ['overview', 'connection', 'shortcodes', 'tickets', 'activity', 'updates'];

    public function __construct() {
        add_action('admin_menu',           [$this, 'register_menu']);
        add_action('admin_init',           [$this, 'register_settings']);
        add_action('admin_enqueue_scripts',[$this, 'enqueue_assets']);
        add_action('admin_post_bynli_connect_test',       [$this, 'handle_test']);
        add_action('admin_post_bynli_connect_disconnect', [$this, 'handle_disconnect']);
        add_action('wp_ajax_' . self::AJAX_ACTION,        [$this, 'handle_ajax_heartbeat']);
        add_action('wp_ajax_' . self::AJAX_THEME,         [$this, 'handle_ajax_theme']);
    }

    public static function key(): string {
        return (string)get_option(self::OPTION_KEY, '');
    }
    public static function api_base(): string {
        $v = (string)get_option(self::OPTION_BASE, '');
        return $v !== '' ? $v : BYNLI_CONNECT_DEFAULT_API_BASE;
    }
    public static function site_slug(): string {
        return (string)get_option(self::OPTION_SLUG, '');
    }

    /**
     * Per-user theme preference: 'light' | 'dark' | 'auto' (default 'auto',
     * i.e. follow the OS/WP prefers-color-scheme). Stamped onto .bcn-wrap
     * server-side so there is no flash on load.
     */
    public static function current_theme(): string {
        $v = (string)get_user_meta(get_current_user_id(), self::THEME_META, true);
        return in_array($v, ['light', 'dark', 'auto'], true) ? $v : 'auto';
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
            'ajaxUrl'     => admin_url('admin-ajax.php'),
            'nonce'       => wp_create_nonce(self::AJAX_ACTION),
            'themeNonce'  => wp_create_nonce(self::NONCE_THEME),
            'themeAction' => self::AJAX_THEME,
            'theme'       => self::current_theme(),
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
            'sanitize_callback' => 'esc_url_raw',
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

    /**
     * Persist the per-user theme override. Nonce + capability gated; value
     * whitelisted to light|dark|auto. Returns the stored value.
     */
    public function handle_ajax_theme(): void {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Forbidden.'], 403);
        }
        if (!check_ajax_referer(self::NONCE_THEME, '_wpnonce', false)) {
            wp_send_json_error(['message' => 'Security check failed.'], 400);
        }
        $theme = isset($_POST['theme']) ? sanitize_key((string)$_POST['theme']) : 'auto';
        if (!in_array($theme, ['light', 'dark', 'auto'], true)) {
            $theme = 'auto';
        }
        update_user_meta(get_current_user_id(), self::THEME_META, $theme);
        wp_send_json_success(['theme' => $theme]);
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
        $section  = $this->current_section();
        $theme    = self::current_theme();
        $theme_at = ($theme === 'light' || $theme === 'dark') ? ' data-theme="' . esc_attr($theme) . '"' : '';
        ?>
        <div class="wrap bcn-wrap"<?php echo $theme_at; ?> dir="<?php echo is_rtl() ? 'rtl' : 'ltr'; ?>">
            <?php $this->render_topbar($ctx, $theme); ?>
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
                                 role="tabpanel"
                                 <?php echo $is_active ? '' : 'hidden'; ?>>
                            <?php $this->render_section($p, $ctx); ?>
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
            'key'              => $key,
            'slug'             => self::site_slug(),
            'base'             => self::api_base(),
            'is_configured'    => $is_configured,
            'is_connected'     => $is_connected,
            'status_state'     => !$is_configured ? 'warn' : ($is_connected ? 'on' : 'off'),
            'status_label'     => !$is_configured ? 'Not connected' : ($is_connected ? 'Connected' : 'Not verified'),
            'next_cron'        => wp_next_scheduled('bynli_connect_daily_report'),
            'update_available' => !empty($upd['version']) && version_compare($upd['version'], BYNLI_CONNECT_VERSION, '>'),
            'tested'           => isset($_GET['tested']) ? sanitize_text_field((string)$_GET['tested']) : '',
            'cleared'          => isset($_GET['cleared']) && (string)$_GET['cleared'] === 'updates',
            'discon'           => isset($_GET['disconnected']) && (string)$_GET['disconnected'] === '1',
        ];
    }

    private function render_topbar(array $ctx, string $theme): void {
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
            <span class="bcn-signal-pill" data-state="<?php echo esc_attr($ctx['status_state']); ?>">
                <span class="bcn-beacon" aria-hidden="true"></span>
                <?php echo esc_html($ctx['status_label']); ?>
            </span>
            <button type="button" class="bcn-theme-toggle" id="bcn-theme-toggle"
                    data-theme="<?php echo esc_attr($theme); ?>"
                    aria-label="Toggle light or dark theme" title="Toggle theme">
                <span class="dashicons dashicons-visibility bcn-theme-ico" aria-hidden="true"></span>
            </button>
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
        $up_to_date = !$ctx['update_available'];
        ?>
        <nav class="bcn-rail" aria-label="Bynefit Connect sections">
            <?php foreach ($items as $key => [$icon, $label]):
                $is = ($key === $active); ?>
                <a class="bcn-nav-item<?php echo $is ? ' active' : ''; ?>"
                   href="<?php echo esc_url($this->section_url($key)); ?>"
                   data-go="<?php echo esc_attr($key); ?>"
                   <?php echo $is ? 'aria-current="page"' : ''; ?>>
                    <span class="dashicons <?php echo esc_attr($icon); ?>" aria-hidden="true"></span>
                    <span class="bcn-rail-label"><?php echo esc_html($label); ?></span>
                    <?php if ($key === 'updates' && $ctx['update_available']): ?>
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
    private function render_section(string $section, array $ctx): void {
        switch ($section) {
            case 'overview':   $this->render_overview($ctx);   break;
            case 'connection': $this->render_connection($ctx); break;
            case 'shortcodes': $this->render_shortcodes($ctx); break;
            case 'tickets':    $this->render_tickets($ctx);    break;
            case 'activity':   $this->render_activity($ctx);   break;
            case 'updates':    $this->render_updates($ctx);    break;
        }
    }

    // ── Sections ─────────────────────────────────────────────────────────
    // Phase 2 wires the shell + these entry points. The hero instrument
    // (overview), the data-driven shortcode previewer, the activity log, and
    // the tickets fold are built out in later phases of #29; where a surface
    // already had content it is carried here and restyled in its own phase.

    private function render_overview(array $ctx): void {
        if (!$ctx['is_configured']) { $this->render_onboard(); return; }
        ?>
        <div class="bcn-note info">
            <span class="dashicons dashicons-chart-area" aria-hidden="true"></span>
            <span>Overview — the live signal instrument (uplink status, 7-day heartbeat sparkline, health tiles) lands next in this build.</span>
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
                    <li>Open <a href="https://bynli.com/dash/sites/host-keys" target="_blank" rel="noopener">/dash/sites/host-keys</a> signed in as a team admin.</li>
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

            <div class="bcn-card-body">
                <div class="bcn-actions">
                    <button type="button" class="bcn-btn ink" id="bcn-heartbeat-btn"
                            <?php disabled(!$is_configured); ?> aria-disabled="<?php echo $is_configured ? 'false' : 'true'; ?>">
                        <span class="dashicons dashicons-share-alt2"></span> Send test heartbeat
                    </button>
                    <span class="bcn-action-hint">A one-off ping — verifies the signature path end-to-end. No usage row is recorded.</span>
                </div>
                <div class="bcn-note" id="bcn-heartbeat-status" aria-live="polite" hidden></div>
            </div>
        </section>
        <?php
    }

    private function render_shortcodes(array $ctx): void {
        // Carried from the old Shortcodes card; the data-driven previewer
        // (list + detail + live preview) replaces this in a later phase of #29.
        $samples = [
            ['name' => 'Form',    'code' => '[bynli-form id="frm_abc123"]'],
            ['name' => 'Events',  'code' => '[bynli-events team="your-team" limit="5"]'],
            ['name' => 'Donate',  'code' => '[bynli-donate team="your-team" amounts="10,25,50,100" default_amount="25" cause="general"]'],
            ['name' => 'Modal',   'code' => '[bynli-modal label="Read more" title="Welcome" body="Thanks for stopping by."]'],
            ['name' => 'Confirm', 'code' => '[bynli-confirm label="Sign out" message="Sign out now?" href="/logout"]'],
            ['name' => 'Toast',   'code' => '[bynli-toast message="Welcome back!" kind="success"]'],
            ['name' => 'Widget',  'code' => '[bynli-widget team="your-team"]'],
        ];
        ?>
        <section class="bcn-card">
            <div class="bcn-card-head">
                <h2>Shortcodes</h2>
                <span class="bcn-card-sub">Drop into any post or page</span>
            </div>
            <div class="bcn-card-body">
                <div class="bcn-shortcodes">
                    <?php foreach ($samples as $s): ?>
                        <div class="bcn-shortcode-row">
                            <span class="bcn-sc-name"><?php echo esc_html($s['name']); ?></span>
                            <code class="bcn-sc-code"><?php echo esc_html($s['code']); ?></code>
                            <button type="button" class="bcn-sc-copy" data-text="<?php echo esc_attr($s['code']); ?>">Copy</button>
                        </div>
                    <?php endforeach; ?>
                </div>
                <p class="bcn-hint bcn-pad-top">Full reference at <a href="https://bynli.com/guides/wordpress" target="_blank" rel="noopener">/guides/wordpress</a>.</p>
            </div>
        </section>
        <?php
    }

    private function render_tickets(array $ctx): void {
        // Tickets fold into the console shell in a later phase of #29 (render
        // the list/detail here via Bynli_Connect_Tickets). For now, deep-link
        // to the existing Tickets page so nothing regresses mid-build.
        $tickets_url = add_query_arg(['page' => Bynli_Connect_Tickets::MENU_SLUG], admin_url('options-general.php'));
        ?>
        <div class="bcn-note info">
            <span class="dashicons dashicons-sos" aria-hidden="true"></span>
            <span>Support tickets — <a href="<?php echo esc_url($tickets_url); ?>">open the Tickets surface</a>. It folds into this console (with per-tab count badges) later in this redesign.</span>
        </div>
        <?php
    }

    private function render_activity(array $ctx): void {
        $last = $ctx['last']; $next_cron = $ctx['next_cron']; $is_connected = $ctx['is_connected'];
        ?>
        <section class="bcn-card">
            <div class="bcn-card-head">
                <h2>Activity</h2>
                <span class="bcn-card-sub">Reports &amp; the next scheduled run</span>
            </div>
            <div class="bcn-card-body">
                <div class="bcn-stats">
                    <div class="bcn-stat">
                        <span class="bcn-stat-label">Last report</span>
                        <span class="bcn-stat-value" data-bcn="last-report"
                              <?php if (!empty($last['at'])): ?>data-state="<?php echo $is_connected ? 'ok' : 'err'; ?>"<?php endif; ?>>
                            <?php echo !empty($last['at']) ? esc_html(human_time_diff((int)$last['at']) . ' ago') : '<span class="bcn-stat-value-em">never</span>'; ?>
                        </span>
                    </div>
                    <div class="bcn-stat">
                        <span class="bcn-stat-label">Kind</span>
                        <span class="bcn-stat-value"><?php echo !empty($last['kind']) ? esc_html($last['kind']) : '<span class="bcn-stat-value-em">—</span>'; ?></span>
                    </div>
                    <div class="bcn-stat">
                        <span class="bcn-stat-label">HTTP</span>
                        <span class="bcn-stat-value"><?php echo !empty($last['status']) ? esc_html((string)$last['status']) : '<span class="bcn-stat-value-em">—</span>'; ?></span>
                    </div>
                    <div class="bcn-stat">
                        <span class="bcn-stat-label">Next daily run</span>
                        <span class="bcn-stat-value">
                            <?php echo $next_cron ? 'in ' . esc_html(human_time_diff(time(), (int)$next_cron)) : '<span class="bcn-stat-value-em">not scheduled</span>'; ?>
                        </span>
                    </div>
                </div>
                <?php if (!empty($last['message'])): ?>
                    <p class="bcn-hint bcn-pad-top"><strong>Last message:</strong> <code><?php echo esc_html((string)$last['message']); ?></code></p>
                <?php endif; ?>
            </div>
        </section>
        <?php
    }

    private function render_updates(array $ctx): void {
        $upd = $ctx['upd']; $update_available = $ctx['update_available'];
        ?>
        <section class="bcn-card">
            <div class="bcn-card-head">
                <h2>Updates</h2>
                <span class="bcn-card-sub">Released directly from Bynefit</span>
            </div>
            <div class="bcn-card-body">
                <div class="bcn-update-row">
                    <span class="bcn-update-label">Installed</span>
                    <span class="bcn-update-value"><code><?php echo esc_html(BYNLI_CONNECT_VERSION); ?></code></span>
                </div>
                <div class="bcn-update-row">
                    <span class="bcn-update-label">Latest</span>
                    <span class="bcn-update-value">
                        <?php if (!empty($upd['version'])): ?>
                            <code><?php echo esc_html($upd['version']); ?></code>
                            <?php if ($update_available): ?>
                                <span class="bcn-chip acc">Update available</span>
                            <?php else: ?>
                                <span class="bcn-chip ok">Up to date</span>
                            <?php endif; ?>
                        <?php else: ?>
                            <span class="bcn-stat-value-em">not checked yet</span>
                        <?php endif; ?>
                    </span>
                </div>
                <?php if (!empty($upd['error'])): ?>
                    <div class="bcn-update-row">
                        <span class="bcn-update-label">Last error</span>
                        <span class="bcn-update-value"><code><?php echo esc_html($upd['error']); ?></code></span>
                    </div>
                <?php endif; ?>

                <div class="bcn-actions bcn-pad-top">
                    <?php if ($update_available): ?>
                        <a class="bcn-btn primary" href="<?php echo esc_url(admin_url('plugins.php')); ?>">
                            <span class="dashicons dashicons-update"></span> Go to Plugins → Update
                        </a>
                    <?php endif; ?>
                    <form action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="post">
                        <input type="hidden" name="action" value="bynli_connect_clear_update_cache">
                        <?php wp_nonce_field('bynli_connect_clear_update_cache'); ?>
                        <button type="submit" class="bcn-btn ink">Check for updates now</button>
                    </form>
                    <span class="bcn-action-hint">WordPress polls Bynefit every 12 hours.</span>
                </div>
            </div>
        </section>
        <?php
    }
}
