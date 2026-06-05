<?php
if (!defined('ABSPATH')) { exit; }

class Bynli_Connect_Settings {
    const OPTION_GROUP = 'bynli_connect';
    const OPTION_KEY   = 'bynli_connect_api_key';
    const OPTION_BASE  = 'bynli_connect_api_base';
    const OPTION_SLUG  = 'bynli_connect_site_slug';
    const MENU_SLUG    = 'bynli-connect';
    const NONCE_ACTION = 'bynli_connect_test';

    public function __construct() {
        add_action('admin_menu',       [$this, 'register_menu']);
        add_action('admin_init',       [$this, 'register_settings']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
        add_action('admin_post_bynli_connect_test',     [$this, 'handle_test']);
    }

    public function enqueue_assets(string $hook): void {
        if ($hook !== 'settings_page_' . self::MENU_SLUG) return;
        $base = plugins_url('assets/', BYNLI_CONNECT_PLUGIN_FILE);
        wp_enqueue_style('bynli-connect-admin', $base . 'admin.css', [], BYNLI_CONNECT_VERSION);
        wp_enqueue_script('bynli-connect-admin', $base . 'admin.js', [], BYNLI_CONNECT_VERSION, true);
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
        $msg = $res['ok'] ? 'ok' : 'fail';
        wp_safe_redirect(add_query_arg(['page' => self::MENU_SLUG, 'tested' => $msg], admin_url('options-general.php')));
        exit;
    }

    public function render_page(): void {
        if (!current_user_can('manage_options')) wp_die('Forbidden.', 403);

        $last     = Bynli_Connect_Reporter::last_report();
        $upd      = Bynli_Connect_Updater::last_check_meta();
        $tested   = isset($_GET['tested']) ? sanitize_text_field((string)$_GET['tested']) : '';
        $cleared  = isset($_GET['cleared']) && (string)$_GET['cleared'] === 'updates';

        $key      = self::key();
        $slug     = self::site_slug();
        $base     = self::api_base();

        $is_configured = ($key !== '');
        $is_connected  = $is_configured && !empty($last) && !empty($last['ok']);

        $status_class = !$is_configured ? 'bcn-st-warn' : ($is_connected ? 'bcn-st-on' : 'bcn-st-off');
        $status_label = !$is_configured ? 'Not configured' : ($is_connected ? 'Connected' : 'Not verified');

        $next_cron = wp_next_scheduled('bynli_connect_daily_report');
        $update_available = !empty($upd['version']) && version_compare($upd['version'], BYNLI_CONNECT_VERSION, '>');
        ?>
        <div class="wrap bcn-wrap">

            <div class="bcn-header">
                <div class="bcn-brand">
                    <div class="bcn-brand-mark" aria-hidden="true">B</div>
                    <div class="bcn-brand-text">
                        <div class="bcn-brand-name">Bynli Connect</div>
                        <div class="bcn-brand-sub">Connects this WordPress site to your Bynli team.</div>
                    </div>
                </div>
                <span class="bcn-status <?php echo esc_attr($status_class); ?>">
                    <?php echo esc_html($status_label); ?>
                </span>
            </div>

            <?php settings_errors(); ?>

            <?php if ($tested === 'ok'): ?>
                <div class="bcn-notice bcn-notice-ok">
                    <span class="dashicons dashicons-yes-alt"></span> Heartbeat sent successfully — Bynli received the ping.
                </div>
            <?php elseif ($tested === 'fail'): ?>
                <div class="bcn-notice bcn-notice-err">
                    <span class="dashicons dashicons-warning"></span> Heartbeat failed. Check the key + API base, then try again.
                </div>
            <?php endif; ?>

            <?php if ($cleared): ?>
                <div class="bcn-notice bcn-notice-ok">
                    <span class="dashicons dashicons-update"></span> Update cache cleared. WordPress will re-check on the next page load.
                </div>
            <?php endif; ?>

            <?php if (!$is_configured): ?>
                <div class="bcn-onboard">
                    <h2>Get started</h2>
                    <p>You're one key away. Generate a site-host key in Bynli and paste it below — Bynli Connect will start reporting daily usage immediately and unlock all of the shortcodes.</p>
                    <ol>
                        <li>Sign in to <a href="https://bynli.com/dash/sites/host-keys" target="_blank" rel="noopener">bynli.com/dash/sites/host-keys</a>.</li>
                        <li>Pick this site, click <strong>Generate key</strong>, and copy the plaintext value once — it isn't shown again.</li>
                        <li>Paste it into the API key field below and save.</li>
                    </ol>
                    <a class="button button-primary" href="https://bynli.com/dash/sites/host-keys" target="_blank" rel="noopener">Open Bynli host-keys page</a>
                </div>
            <?php endif; ?>

            <div class="bcn-grid">

                <!-- ── Connection card ──────────────────────────────────────── -->
                <section class="bcn-card">
                    <div class="bcn-card-head">
                        <h2>Connection</h2>
                        <span class="bcn-card-sub">Per-site key + signing base</span>
                    </div>
                    <div class="bcn-card-body">
                        <form action="options.php" method="post">
                            <?php settings_fields(self::OPTION_GROUP); ?>

                            <div class="bcn-row">
                                <label class="bcn-label" for="bcn_key">API key</label>
                                <div class="bcn-input-wrap">
                                    <input id="bcn_key" name="<?php echo esc_attr(self::OPTION_KEY); ?>"
                                        type="password" autocomplete="off" spellcheck="false"
                                        value="<?php echo esc_attr($key); ?>"
                                        placeholder="bynli_sh_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx">
                                    <button type="button" class="bcn-icon-btn bcn-toggle-reveal"
                                        data-target="bcn_key" aria-label="Show key">
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
                                <div class="bcn-hint">Generate at <code>/dash/sites/host-keys</code> on Bynli. Format: <code>bynli_sh_</code> + 32 hex characters.</div>
                            </div>

                            <div class="bcn-row">
                                <label class="bcn-label" for="bcn_slug">Site slug</label>
                                <div class="bcn-input-wrap">
                                    <input id="bcn_slug" name="<?php echo esc_attr(self::OPTION_SLUG); ?>"
                                        type="text" value="<?php echo esc_attr($slug); ?>"
                                        placeholder="my-team-site">
                                </div>
                                <div class="bcn-hint">Optional. The Bynli team-site slug this WP install represents — used only for telemetry.</div>
                            </div>

                            <div class="bcn-row">
                                <label class="bcn-label" for="bcn_base">Bynli API base</label>
                                <div class="bcn-input-wrap">
                                    <input id="bcn_base" name="<?php echo esc_attr(self::OPTION_BASE); ?>"
                                        type="url" value="<?php echo esc_attr($base); ?>">
                                </div>
                                <div class="bcn-hint">Leave as <code><?php echo esc_html(BYNLI_CONNECT_DEFAULT_API_BASE); ?></code> unless you're testing against staging.</div>
                            </div>

                            <?php submit_button('Save settings', 'primary', 'submit', false); ?>
                        </form>
                    </div>

                    <div class="bcn-card-body">
                        <div class="bcn-actions">
                            <form action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="post">
                                <input type="hidden" name="action" value="bynli_connect_test">
                                <?php wp_nonce_field(self::NONCE_ACTION); ?>
                                <button type="submit" class="button button-secondary bcn-btn-icon" <?php disabled(!$is_configured); ?>>
                                    <span class="dashicons dashicons-controls-play"></span>
                                    Send heartbeat
                                </button>
                            </form>
                            <span class="bcn-action-hint">Sends a one-off ping. No usage row is recorded — verifies the key + signature path.</span>
                        </div>
                    </div>
                </section>

                <!-- ── Activity card ────────────────────────────────────────── -->
                <section class="bcn-card">
                    <div class="bcn-card-head">
                        <h2>Activity</h2>
                        <span class="bcn-card-sub">Reports + next scheduled run</span>
                    </div>
                    <div class="bcn-card-body">
                        <div class="bcn-stats">
                            <div class="bcn-stat">
                                <span class="bcn-stat-label">Last report</span>
                                <span class="bcn-stat-value <?php echo $is_connected ? 'bcn-good' : ($is_configured ? 'bcn-bad' : ''); ?>">
                                    <?php echo !empty($last['at']) ? esc_html(human_time_diff((int)$last['at']) . ' ago') : '—'; ?>
                                </span>
                            </div>
                            <div class="bcn-stat">
                                <span class="bcn-stat-label">Kind</span>
                                <span class="bcn-stat-value"><?php echo !empty($last['kind']) ? esc_html($last['kind']) : '—'; ?></span>
                            </div>
                            <div class="bcn-stat">
                                <span class="bcn-stat-label">HTTP</span>
                                <span class="bcn-stat-value"><?php echo !empty($last['status']) ? esc_html((string)$last['status']) : '—'; ?></span>
                            </div>
                            <div class="bcn-stat">
                                <span class="bcn-stat-label">Next daily run</span>
                                <span class="bcn-stat-value">
                                    <?php echo $next_cron ? esc_html(human_time_diff(time(), (int)$next_cron)) : 'not scheduled'; ?>
                                </span>
                            </div>
                        </div>

                        <?php if (!empty($last['message'])): ?>
                            <div class="bcn-row bcn-row-spaced">
                                <span class="bcn-hint"><strong>Last message:</strong> <code><?php echo esc_html((string)$last['message']); ?></code></span>
                            </div>
                        <?php endif; ?>
                    </div>
                </section>

                <!-- ── Shortcodes card ─────────────────────────────────────── -->
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
                                    <span class="bcn-sc-code"><?php echo esc_html($s['code']); ?></span>
                                    <button type="button" class="bcn-sc-copy bcn-copy" data-text="<?php echo esc_attr($s['code']); ?>">Copy</button>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <div class="bcn-hint bcn-hint-spaced">
                            Full shortcode reference at <a href="https://bynli.com/guides/wordpress" target="_blank" rel="noopener">bynli.com/guides/wordpress</a>.
                        </div>
                    </div>
                </section>

                <!-- ── Updates card ────────────────────────────────────────── -->
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
                                        <span class="bcn-pill-new">Update available</span>
                                    <?php else: ?>
                                        <span style="color:#1d8a4e">✓ up to date</span>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <em>not checked yet</em>
                                <?php endif; ?>
                            </span>
                        </div>
                        <?php if (!empty($upd['error'])): ?>
                            <div class="bcn-update-row">
                                <span class="bcn-update-label">Last error</span>
                                <span class="bcn-update-value"><code><?php echo esc_html($upd['error']); ?></code></span>
                            </div>
                        <?php endif; ?>

                        <div class="bcn-actions bcn-actions-spaced">
                            <?php if ($update_available): ?>
                                <a class="button button-primary" href="<?php echo esc_url(admin_url('plugins.php')); ?>">
                                    Go to Plugins → Update
                                </a>
                            <?php endif; ?>
                            <form action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="post">
                                <input type="hidden" name="action" value="bynli_connect_clear_update_cache">
                                <?php wp_nonce_field('bynli_connect_clear_update_cache'); ?>
                                <button type="submit" class="button button-secondary">Check for updates now</button>
                            </form>
                            <span class="bcn-action-hint">WordPress polls Bynli every 12 hours.</span>
                        </div>
                    </div>
                </section>

            </div>
        </div>
        <?php
    }
}
