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
        add_action('admin_post_bynli_connect_test',     [$this, 'handle_test']);
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
        $last   = Bynli_Connect_Reporter::last_report();
        $tested = isset($_GET['tested']) ? sanitize_text_field((string)$_GET['tested']) : '';
        ?>
        <div class="wrap">
            <h1>Bynli Connect</h1>
            <p>Connect this WordPress site to your team's Bynli account. Daily usage is reported to Bynli; future versions will add shortcodes for forms, events, and donations.</p>

            <?php settings_errors(); ?>

            <?php if ($tested === 'ok'): ?>
                <div class="notice notice-success is-dismissible"><p>Heartbeat sent successfully. Bynli received the ping.</p></div>
            <?php elseif ($tested === 'fail'): ?>
                <div class="notice notice-error is-dismissible"><p>Heartbeat failed. Check the key + API base, then try again.</p></div>
            <?php endif; ?>

            <form action="options.php" method="post">
                <?php settings_fields(self::OPTION_GROUP); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="bcn_key">API key</label></th>
                        <td>
                            <input id="bcn_key" name="<?php echo esc_attr(self::OPTION_KEY); ?>" type="password"
                                   value="<?php echo esc_attr(self::key()); ?>" class="regular-text" autocomplete="off">
                            <p class="description">Generate at <code>/dash/sites/host-keys</code> on Bynli. Format: <code>bynli_sh_</code> + 32 hex.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="bcn_slug">Site slug</label></th>
                        <td>
                            <input id="bcn_slug" name="<?php echo esc_attr(self::OPTION_SLUG); ?>" type="text"
                                   value="<?php echo esc_attr(self::site_slug()); ?>" class="regular-text">
                            <p class="description">Optional. The Bynli team-site slug this WP install represents.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="bcn_base">Bynli API base</label></th>
                        <td>
                            <input id="bcn_base" name="<?php echo esc_attr(self::OPTION_BASE); ?>" type="url"
                                   value="<?php echo esc_attr(self::api_base()); ?>" class="regular-text">
                            <p class="description">Leave as <code><?php echo esc_html(BYNLI_CONNECT_DEFAULT_API_BASE); ?></code> unless you're testing against staging.</p>
                        </td>
                    </tr>
                </table>
                <?php submit_button('Save settings'); ?>
            </form>

            <h2>Test connection</h2>
            <p>Sends a one-off heartbeat to Bynli. No usage row is recorded — this just proves the key + signature work end-to-end.</p>
            <form action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="post">
                <input type="hidden" name="action" value="bynli_connect_test">
                <?php wp_nonce_field(self::NONCE_ACTION); ?>
                <?php submit_button('Send heartbeat', 'secondary', 'submit', false); ?>
            </form>

            <?php if (!empty($last)): ?>
                <h2>Last report</h2>
                <table class="form-table" role="presentation">
                    <tr><th>When</th>    <td><?php echo esc_html(date_i18n('Y-m-d H:i:s', (int)$last['at'])); ?> (server time)</td></tr>
                    <tr><th>Kind</th>    <td><?php echo esc_html($last['kind']); ?></td></tr>
                    <tr><th>HTTP</th>    <td><?php echo esc_html((string)$last['status']); ?></td></tr>
                    <tr><th>Result</th>  <td><?php echo $last['ok'] ? '<strong style="color:#1d8a4e">OK</strong>' : '<strong style="color:#b32d2e">FAIL</strong>'; ?></td></tr>
                    <?php if (!empty($last['message'])): ?>
                        <tr><th>Message</th><td><code><?php echo esc_html((string)$last['message']); ?></code></td></tr>
                    <?php endif; ?>
                </table>
            <?php endif; ?>
        </div>
        <?php
    }
}
