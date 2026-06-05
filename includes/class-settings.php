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

    public function __construct() {
        add_action('admin_menu',           [$this, 'register_menu']);
        add_action('admin_init',           [$this, 'register_settings']);
        add_action('admin_enqueue_scripts',[$this, 'enqueue_assets']);
        add_action('admin_post_bynli_connect_test',       [$this, 'handle_test']);
        add_action('admin_post_bynli_connect_disconnect', [$this, 'handle_disconnect']);
        add_action('wp_ajax_' . self::AJAX_ACTION,        [$this, 'handle_ajax_heartbeat']);
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

    public function enqueue_assets(string $hook): void {
        if ($hook !== 'settings_page_' . self::MENU_SLUG) return;
        $base = plugins_url('assets/', BYNLI_CONNECT_PLUGIN_FILE);
        wp_enqueue_style('dashicons');
        wp_enqueue_style('bynli-connect-fonts',
            'https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:wght@500;700;800&display=swap',
            [], null);
        wp_enqueue_style('bynli-connect-admin', $base . 'admin.css', ['dashicons'], BYNLI_CONNECT_VERSION);
        wp_enqueue_script('bynli-connect-admin', $base . 'admin.js', [], BYNLI_CONNECT_VERSION, true);
        wp_localize_script('bynli-connect-admin', 'BynliConnect', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce(self::AJAX_ACTION),
        ]);
    }

    public function register_menu(): void {
        add_options_page(
            'Bynli Connect',
            'Bynli Connect',
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
            ['page' => self::MENU_SLUG, 'tested' => $msg],
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
                'message'       => 'Heartbeat OK. Bynli received the ping.',
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
            ['page' => self::MENU_SLUG, 'disconnected' => '1'],
            admin_url('options-general.php')
        ));
        exit;
    }

    public function render_page(): void {
        if (!current_user_can('manage_options')) wp_die('Forbidden.', 403);

        $last     = Bynli_Connect_Reporter::last_report();
        $upd      = Bynli_Connect_Updater::last_check_meta();
        $tested   = isset($_GET['tested']) ? sanitize_text_field((string)$_GET['tested']) : '';
        $cleared  = isset($_GET['cleared']) && (string)$_GET['cleared'] === 'updates';
        $discon   = isset($_GET['disconnected']) && (string)$_GET['disconnected'] === '1';

        $key  = self::key();
        $slug = self::site_slug();
        $base = self::api_base();

        $is_configured = ($key !== '');
        $is_connected  = $is_configured && !empty($last) && !empty($last['ok']);

        $status_state = !$is_configured ? 'warn' : ($is_connected ? 'on' : 'off');
        $status_label = !$is_configured ? 'Not configured' : ($is_connected ? 'Connected' : 'Not verified');

        $next_cron        = wp_next_scheduled('bynli_connect_daily_report');
        $update_available = !empty($upd['version']) && version_compare($upd['version'], BYNLI_CONNECT_VERSION, '>');
        ?>
        <div class="wrap bcn-wrap" dir="<?php echo is_rtl() ? 'rtl' : 'ltr'; ?>">

            <header class="bcn-header">
                <div class="bcn-brand">
                    <span class="bcn-wordmark">Bynli<span class="bcn-wordmark-dot">.</span></span>
                    <span class="bcn-divider-v" aria-hidden="true"></span>
                    <span class="bcn-product">Connect for WordPress</span>
                </div>
                <span class="bcn-status" data-bcn="status-pill" data-state="<?php echo esc_attr($status_state); ?>">
                    <span class="bcn-status-dot" aria-hidden="true"></span>
                    <span class="bcn-status-label"><?php echo esc_html($status_label); ?></span>
                </span>
            </header>

            <?php settings_errors(); ?>

            <?php if ($tested === 'ok'): ?>
                <div class="bcn-notice bcn-notice-ok"><span class="dashicons dashicons-yes-alt"></span>
                    <span>Heartbeat sent successfully — Bynli received the ping.</span></div>
            <?php elseif ($tested === 'fail'): ?>
                <div class="bcn-notice bcn-notice-err"><span class="dashicons dashicons-warning"></span>
                    <span>Heartbeat failed. Check the key + API base, then try again.</span></div>
            <?php endif; ?>
            <?php if ($cleared): ?>
                <div class="bcn-notice bcn-notice-ok"><span class="dashicons dashicons-update"></span>
                    <span>Update cache cleared. WordPress will re-check on the next page load.</span></div>
            <?php endif; ?>
            <?php if ($discon): ?>
                <div class="bcn-notice bcn-notice-warn"><span class="dashicons dashicons-info-outline"></span>
                    <span>Site disconnected. The API key was cleared from this WordPress install. Revoke it on Bynli at <code>/dash/sites/host-keys</code> if you also want to invalidate it server-side.</span></div>
            <?php endif; ?>

            <?php if (!$is_configured): ?>
                <section class="bcn-onboard">
                    <div class="bcn-onboard-inner">
                        <div class="bcn-onboard-eyebrow">Getting started</div>
                        <h2>One key, then this site reports to Bynli.</h2>
                        <p>Generate a site-host key on Bynli, paste it below, and this WordPress install starts reporting daily usage and unlocks every shortcode.</p>
                        <ol>
                            <li>Open <a href="https://bynli.com/dash/sites/host-keys" target="_blank" rel="noopener">bynli.com/dash/sites/host-keys</a> while signed in as a team admin.</li>
                            <li>Pick this site, click <strong>Generate key</strong>, copy the plaintext value — it is only shown once.</li>
                            <li>Paste it into the API key field and save.</li>
                        </ol>
                        <a class="bcn-btn bcn-btn-primary" href="https://bynli.com/dash/sites/host-keys" target="_blank" rel="noopener">
                            Open host-keys
                            <span class="dashicons dashicons-external"></span>
                        </a>
                    </div>
                </section>
            <?php endif; ?>

            <div class="bcn-grid">

                <!-- ── Connection card ──────────────────────────────────── -->
                <section class="bcn-card">
                    <div class="bcn-card-head">
                        <h2>Connection</h2>
                        <span class="bcn-card-sub">Per-site key &amp; signing base</span>
                    </div>
                    <div class="bcn-card-body">
                        <form action="options.php" method="post">
                            <?php settings_fields(self::OPTION_GROUP); ?>

                            <div class="bcn-row">
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
                                <p class="bcn-hint">Generate at <code>/dash/sites/host-keys</code> on Bynli. Format: <code>bynli_sh_</code> + 32 hex.</p>
                            </div>

                            <div class="bcn-row">
                                <label class="bcn-label" for="bcn_slug">Site slug</label>
                                <div class="bcn-input-wrap">
                                    <input class="bcn-input" id="bcn_slug" name="<?php echo esc_attr(self::OPTION_SLUG); ?>"
                                           type="text" value="<?php echo esc_attr($slug); ?>" placeholder="my-team-site">
                                </div>
                                <p class="bcn-hint">Optional. The Bynli team-site slug this install represents — used only for telemetry.</p>
                            </div>

                            <div class="bcn-row">
                                <label class="bcn-label" for="bcn_base">API base</label>
                                <div class="bcn-input-wrap">
                                    <input class="bcn-input" id="bcn_base" name="<?php echo esc_attr(self::OPTION_BASE); ?>"
                                           type="url" value="<?php echo esc_attr($base); ?>">
                                </div>
                                <p class="bcn-hint">Leave as <code><?php echo esc_html(BYNLI_CONNECT_DEFAULT_API_BASE); ?></code> unless testing against staging.</p>
                            </div>

                            <div class="bcn-actions">
                                <button type="submit" class="bcn-btn bcn-btn-primary">
                                    <span class="dashicons dashicons-yes"></span>
                                    Save settings
                                </button>
                                <?php if ($is_configured): ?>
                                    <button type="button" class="bcn-btn bcn-btn-danger" id="bcn-disconnect-btn"
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
                            <button type="button" class="bcn-btn bcn-btn-ghost" id="bcn-heartbeat-btn"
                                    <?php disabled(!$is_configured); ?> aria-disabled="<?php echo $is_configured ? 'false' : 'true'; ?>">
                                <span class="dashicons dashicons-share-alt2"></span>
                                Send heartbeat
                            </button>
                            <span class="bcn-action-hint">A one-off ping. No usage row is recorded — verifies the signature path end-to-end.</span>
                            <span class="bcn-action-status" id="bcn-heartbeat-status" aria-live="polite"></span>
                        </div>
                    </div>
                </section>

                <!-- ── Activity card ───────────────────────────────────── -->
                <section class="bcn-card">
                    <div class="bcn-card-head">
                        <h2>Activity</h2>
                        <span class="bcn-card-sub">Reports &amp; the next scheduled run</span>
                    </div>
                    <div class="bcn-card-body">
                        <div class="bcn-stats">
                            <div class="bcn-stat">
                                <span class="bcn-stat-label">Last report</span>
                                <span class="bcn-stat-value <?php echo (!empty($last['at']) && !$is_connected) ? '' : ''; ?>"
                                      data-bcn="last-report"
                                      <?php if (!empty($last['at'])): ?>data-state="<?php echo $is_connected ? 'ok' : 'err'; ?>"<?php endif; ?>>
                                    <?php if (!empty($last['at'])): ?>
                                        <?php echo esc_html(human_time_diff((int)$last['at']) . ' ago'); ?>
                                    <?php else: ?>
                                        <span class="bcn-stat-value-em">never</span>
                                    <?php endif; ?>
                                </span>
                            </div>
                            <div class="bcn-stat">
                                <span class="bcn-stat-label">Kind</span>
                                <span class="bcn-stat-value">
                                    <?php if (!empty($last['kind'])): ?>
                                        <?php echo esc_html($last['kind']); ?>
                                    <?php else: ?>
                                        <span class="bcn-stat-value-em">—</span>
                                    <?php endif; ?>
                                </span>
                            </div>
                            <div class="bcn-stat">
                                <span class="bcn-stat-label">HTTP</span>
                                <span class="bcn-stat-value">
                                    <?php if (!empty($last['status'])): ?>
                                        <?php echo esc_html((string)$last['status']); ?>
                                    <?php else: ?>
                                        <span class="bcn-stat-value-em">—</span>
                                    <?php endif; ?>
                                </span>
                            </div>
                            <div class="bcn-stat">
                                <span class="bcn-stat-label">Next daily run</span>
                                <span class="bcn-stat-value">
                                    <?php if ($next_cron): ?>
                                        in <?php echo esc_html(human_time_diff(time(), (int)$next_cron)); ?>
                                    <?php else: ?>
                                        <span class="bcn-stat-value-em">not scheduled</span>
                                    <?php endif; ?>
                                </span>
                            </div>
                        </div>

                        <?php if (!empty($last['message'])): ?>
                            <p class="bcn-hint bcn-pad-top"><strong>Last message:</strong> <code><?php echo esc_html((string)$last['message']); ?></code></p>
                        <?php endif; ?>
                    </div>
                </section>

                <!-- ── Shortcodes card ─────────────────────────────────── -->
                <section class="bcn-card">
                    <div class="bcn-card-head">
                        <h2>Shortcodes</h2>
                        <span class="bcn-card-sub">Drop into any post or page</span>
                    </div>
                    <div class="bcn-card-body">
                        <div class="bcn-shortcodes">
                            <?php
                            $samples = [
                                ['name' => 'Form',    'code' => '[bynli-form id="frm_abc123"]'],
                                ['name' => 'Modal',   'code' => '[bynli-modal label="Read more" title="Welcome" body="Thanks for stopping by."]'],
                                ['name' => 'Confirm', 'code' => '[bynli-confirm label="Sign out" message="Sign out now?" href="/logout"]'],
                                ['name' => 'Toast',   'code' => '[bynli-toast message="Welcome back!" kind="success"]'],
                                ['name' => 'Widget',  'code' => '[bynli-widget team="your-team"]'],
                            ];
                            foreach ($samples as $s):
                            ?>
                                <div class="bcn-shortcode-row">
                                    <span class="bcn-sc-name"><?php echo esc_html($s['name']); ?></span>
                                    <code class="bcn-sc-code"><?php echo esc_html($s['code']); ?></code>
                                    <button type="button" class="bcn-sc-copy" data-text="<?php echo esc_attr($s['code']); ?>">Copy</button>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <p class="bcn-hint bcn-pad-top">
                            Full reference at <a href="https://bynli.com/guides/wordpress" target="_blank" rel="noopener">bynli.com/guides/wordpress</a>.
                        </p>
                    </div>
                </section>

                <!-- ── Updates card ────────────────────────────────────── -->
                <section class="bcn-card">
                    <div class="bcn-card-head">
                        <h2>Updates</h2>
                        <span class="bcn-card-sub">Released directly from Bynli</span>
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
                                        <span class="bcn-pill bcn-pill-new">Update available</span>
                                    <?php else: ?>
                                        <span class="bcn-pill bcn-pill-ok">Up to date</span>
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
                                <a class="bcn-btn bcn-btn-primary" href="<?php echo esc_url(admin_url('plugins.php')); ?>">
                                    <span class="dashicons dashicons-update"></span>
                                    Go to Plugins → Update
                                </a>
                            <?php endif; ?>
                            <form action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="post">
                                <input type="hidden" name="action" value="bynli_connect_clear_update_cache">
                                <?php wp_nonce_field('bynli_connect_clear_update_cache'); ?>
                                <button type="submit" class="bcn-btn bcn-btn-ghost">Check for updates now</button>
                            </form>
                            <span class="bcn-action-hint">WordPress polls Bynli every 12 hours.</span>
                        </div>
                    </div>
                </section>

            </div>

            <footer class="bcn-footer">
                Made for Bynli teams — <a href="https://bynli.com" target="_blank" rel="noopener">bynli.com</a>.
            </footer>
        </div>
        <?php
    }
}
