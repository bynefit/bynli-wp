<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Client mode (consolidation epic — retires the legacy "dms" white-label
 * portal + role lockdown, rebuilt native in the Relay aesthetic, no UIkit CDN).
 *
 * When an admin enables it, a limited `bynefit_client` role can manage the
 * site's content (pages, posts, media) from a branded Portal, with the rest of
 * wp-admin locked down. Off by default — dormant until turned on.
 *
 * SECURITY: every lockdown path is gated on is_client(), which requires the
 * bynefit_client role AND the absence of manage_options — so an administrator
 * is NEVER treated as a locked client and can never be locked out. The role's
 * capability set contains only content caps (no plugins/themes/users/settings),
 * so the lockdown is defense-in-depth, not the sole protection.
 */
class Bynli_Connect_Client_Mode {

    const OPTION      = 'bynli_connect_client_mode';   // '0' | '1'
    const ROLE        = 'bynefit_client';
    const PORTAL_SLUG = 'bynefit-portal';
    const NONCE       = 'bynli_connect_client_mode';
    const AJAX        = 'bynli_connect_client_mode';

    // Client roster management (assign / invite / revoke) — admin-only.
    const AJAX_ASSIGN  = 'bynli_connect_client_assign';
    const AJAX_REVOKE  = 'bynli_connect_client_revoke';
    const NONCE_MANAGE = 'bynli_connect_client_manage';

    public function __construct() {
        // The save handler must exist regardless of state so an admin can toggle.
        add_action('wp_ajax_' . self::AJAX, [$this, 'handle_ajax']);
        // Roster management must also work regardless of state (admin-only).
        add_action('wp_ajax_' . self::AJAX_ASSIGN, [$this, 'handle_assign']);
        add_action('wp_ajax_' . self::AJAX_REVOKE, [$this, 'handle_revoke']);

        if (!self::enabled()) return;   // dormant unless an admin turned it on

        add_action('init',                 [$this, 'ensure_role']);
        add_action('admin_menu',           [$this, 'register_portal'], 1);
        add_action('admin_menu',           [$this, 'lockdown_menus'], 9999);
        add_action('admin_init',           [$this, 'restrict_admin_pages']);
        add_action('admin_bar_menu',       [$this, 'trim_admin_bar'], 999);
        add_action('current_screen',       [$this, 'redirect_dashboard']);
        add_filter('login_redirect',       [$this, 'login_redirect'], 10, 3);
    }

    public static function enabled(): bool {
        return get_option(self::OPTION, '0') === '1';
    }

    /**
     * True only for a genuine locked client — has the role and is NOT an
     * administrator. The manage_options exclusion is the load-bearing guard
     * that keeps admins out of every lockdown path.
     */
    private function is_client(): bool {
        $u = wp_get_current_user();
        return $u && $u->exists()
            && in_array(self::ROLE, (array) $u->roles, true)
            && !user_can($u, 'manage_options');
    }

    public function ensure_role(): void {
        $role = get_role(self::ROLE);
        if (!$role) {
            add_role(self::ROLE, __('Client', 'bynli-connect'), [
                'read'                  => true,
                'read_bynefit_portal'   => true,   // bespoke cap so only clients (not every subscriber) see the Portal
                'upload_files'          => true,
                'edit_posts'            => true,
                'edit_published_posts'  => true,
                'delete_posts'          => true,
                'publish_posts'         => true,
                'edit_pages'            => true,
                'edit_published_pages'  => true,
                'delete_pages'          => true,
                'publish_pages'         => true,
            ]);
            return;
        }
        // Back-fill caps added in later plugin versions — add_role() is a no-op
        // on an existing role, so a role created by an earlier build won't get
        // new caps otherwise.
        if (!$role->has_cap('read_bynefit_portal')) {
            $role->add_cap('read_bynefit_portal');
        }
    }

    // ── Lockdown (clients only) ──────────────────────────────────────────

    public function lockdown_menus(): void {
        if (!$this->is_client()) return;
        // Strip everything a content client shouldn't touch; keep Pages, Posts,
        // Media, and the Portal.
        foreach (['index.php', 'edit-comments.php', 'themes.php', 'plugins.php',
                  'users.php', 'tools.php', 'options-general.php',
                  'separator1', 'separator2', 'separator-last'] as $slug) {
            remove_menu_page($slug);
        }
    }

    public function restrict_admin_pages(): void {
        if (!$this->is_client()) return;
        // $pagenow is the WP-canonical current-admin-file signal; PHP_SELF can
        // be shifted by PATH_INFO on some server configs.
        global $pagenow;
        $base = (string) $pagenow;
        $blocked = [
            'options-general.php', 'options.php', 'options-writing.php', 'options-reading.php',
            'plugins.php', 'plugin-install.php', 'plugin-editor.php',
            'themes.php', 'theme-install.php', 'theme-editor.php', 'customize.php',
            'users.php', 'user-new.php', 'user-edit.php',
            'edit-comments.php', 'comment.php',
            'tools.php', 'import.php', 'export.php', 'site-health.php', 'update-core.php',
        ];
        if (in_array($base, $blocked, true)) {
            wp_safe_redirect(admin_url('admin.php?page=' . self::PORTAL_SLUG));
            exit;
        }
    }

    public function trim_admin_bar($bar): void {
        if (!$this->is_client() || !is_object($bar)) return;
        foreach (['wp-logo', 'comments', 'new-content', 'updates', 'themes', 'customize'] as $id) {
            $bar->remove_node($id);
        }
    }

    public function redirect_dashboard($screen): void {
        if (!$this->is_client()) return;
        if (is_object($screen) && isset($screen->id) && $screen->id === 'dashboard') {
            wp_safe_redirect(admin_url('admin.php?page=' . self::PORTAL_SLUG));
            exit;
        }
    }

    public function login_redirect($redirect_to, $requested, $user) {
        if (isset($user->roles) && is_array($user->roles)
            && in_array(self::ROLE, $user->roles, true)
            && !user_can($user, 'manage_options')) {
            return admin_url('admin.php?page=' . self::PORTAL_SLUG);
        }
        return $redirect_to;
    }

    // ── Portal page ──────────────────────────────────────────────────────

    public function register_portal(): void {
        $hook = add_menu_page(
            __('Portal', 'bynli-connect'),
            __('Portal', 'bynli-connect'),
            'read_bynefit_portal',   // only the Client role holds this — keeps subscribers/admins out
            self::PORTAL_SLUG,
            [$this, 'render_portal'],
            'dashicons-admin-home',
            2
        );
        add_action('admin_print_styles-' . $hook, [$this, 'enqueue']);
    }

    public function enqueue(): void {
        $base = plugins_url('assets/', BYNLI_CONNECT_PLUGIN_FILE);
        wp_enqueue_style('dashicons');
        wp_enqueue_style('bynli-connect-admin', $base . 'admin.css', ['dashicons'], BYNLI_CONNECT_VERSION);
    }

    public function render_portal(): void {
        if (!current_user_can('read_bynefit_portal')) wp_die('Forbidden.', 403);

        $user  = wp_get_current_user();
        $name  = $user->first_name ?: $user->display_name;
        $site  = get_bloginfo('name');
        $last  = Bynli_Connect_Reporter::last_report();
        $connected = !empty($last) && !empty($last['ok']);

        $pages = wp_count_posts('page');
        $pubp  = isset($pages->publish) ? (int) $pages->publish : 0;
        $draft = isset($pages->draft)   ? (int) $pages->draft   : 0;
        $media = (int) (wp_count_posts('attachment')->inherit ?? 0);

        $new_page = admin_url('post-new.php?post_type=page');
        $upload   = admin_url('media-new.php');
        $pages_url= admin_url('edit.php?post_type=page');
        ?>
        <div class="wrap bcn-wrap" dir="<?php echo is_rtl() ? 'rtl' : 'ltr'; ?>">
            <header class="bcn-topbar">
                <div class="bcn-brand">
                    <span class="bcn-logo" aria-hidden="true">
                        <svg viewBox="0 0 24 24" width="22" height="22" fill="none">
                            <circle cx="12" cy="12" r="3.2" fill="currentColor"/>
                            <circle cx="12" cy="12" r="7" stroke="currentColor" stroke-width="1.4" opacity=".55"/>
                            <circle cx="12" cy="12" r="10.6" stroke="currentColor" stroke-width="1.2" opacity=".25"/>
                        </svg>
                    </span>
                    <span class="bcn-wordmark"><?php echo esc_html($site); ?></span>
                    <span class="bcn-tag">Portal</span>
                </div>
                <div class="bcn-topbar-spacer"></div>
                <span class="bcn-signal-pill" data-state="<?php echo $connected ? 'on' : 'warn'; ?>">
                    <span class="bcn-beacon" aria-hidden="true"></span>
                    <span class="bcn-signal-label"><?php echo $connected ? 'Connected' : 'Standalone'; ?></span>
                </span>
            </header>

            <div class="bcn-hero" data-state="<?php echo $connected ? 'on' : 'off'; ?>">
                <div class="bcn-hero-body">
                    <div class="bcn-hero-lead">
                        <span class="bcn-hero-eyebrow">Welcome</span>
                        <div class="bcn-hero-readout">
                            <span class="bcn-hero-status bcn-hero-name"><?php echo esc_html($name ?: 'there'); ?></span>
                        </div>
                        <p class="bcn-hero-sub">Manage your site's pages and media from here. Everything else is handled for you.</p>
                    </div>
                </div>
            </div>

            <div class="bcn-quick bcn-portal-quick">
                <a class="bcn-btn primary" href="<?php echo esc_url($new_page); ?>"><span class="dashicons dashicons-plus-alt2"></span> New page</a>
                <a class="bcn-btn" href="<?php echo esc_url($upload); ?>"><span class="dashicons dashicons-upload"></span> Upload media</a>
                <a class="bcn-btn" href="<?php echo esc_url($pages_url); ?>"><span class="dashicons dashicons-admin-page"></span> All pages</a>
            </div>

            <div class="bcn-tiles">
                <div class="bcn-tile" data-state="ok">
                    <span class="dashicons dashicons-admin-page" aria-hidden="true"></span>
                    <span class="bcn-tile-label">Published pages</span>
                    <span class="bcn-tile-value"><?php echo esc_html((string) $pubp); ?></span>
                </div>
                <div class="bcn-tile" data-state="<?php echo $draft > 0 ? 'warn' : 'ok'; ?>">
                    <span class="dashicons dashicons-edit" aria-hidden="true"></span>
                    <span class="bcn-tile-label">Drafts</span>
                    <span class="bcn-tile-value"><?php echo esc_html((string) $draft); ?></span>
                </div>
                <div class="bcn-tile" data-state="ok">
                    <span class="dashicons dashicons-format-image" aria-hidden="true"></span>
                    <span class="bcn-tile-label">Media</span>
                    <span class="bcn-tile-value"><?php echo esc_html((string) $media); ?></span>
                </div>
                <div class="bcn-tile" data-state="<?php echo $connected ? 'ok' : 'warn'; ?>">
                    <span class="dashicons dashicons-cloud" aria-hidden="true"></span>
                    <span class="bcn-tile-label">Hosting</span>
                    <span class="bcn-tile-value"><?php echo $connected ? 'Managed' : '—'; ?></span>
                </div>
            </div>

            <div class="bcn-note info">
                <span class="dashicons dashicons-sos" aria-hidden="true"></span>
                <span>Need help with something the portal doesn't cover? Reach out to your site manager — they handle settings, plugins, and support.</span>
            </div>
        </div>
        <?php
    }

    // ── Console toggle (admin only) ──────────────────────────────────────

    public function handle_ajax(): void {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Forbidden.'], 403);
        }
        if (!check_ajax_referer(self::NONCE, '_wpnonce', false)) {
            wp_send_json_error(['message' => 'Security check failed. Reload and try again.'], 400);
        }
        $on = isset($_POST['enabled']) && (string) $_POST['enabled'] === '1';
        update_option(self::OPTION, $on ? '1' : '0');
        if ($on) $this->ensure_role();
        wp_send_json_success(['enabled' => $on]);
    }

    public static function render_card(): void {
        $on = self::enabled();
        ?>
        <section class="bcn-card">
            <div class="bcn-card-head">
                <h2>Client mode</h2>
                <span class="bcn-card-sub">A simple, locked-down portal for the site's owner</span>
            </div>
            <div class="bcn-card-body">
                <div class="bcn-field">
                    <label class="bcn-label" for="bcn-client-mode">Managed client portal</label>
                    <select class="bcn-input sans" id="bcn-client-mode"
                            data-bcn-client-mode
                            data-nonce="<?php echo esc_attr(wp_create_nonce(self::NONCE)); ?>">
                        <option value="0" <?php selected(!$on); ?>>Off — everyone uses the full WordPress admin</option>
                        <option value="1" <?php selected($on); ?>>On — the site owner gets a simple locked portal</option>
                    </select>
                    <p class="bcn-hint" data-role="client-hint"><?php echo $on
                        ? esc_html('On — the site owner (Client) sees only the Portal (pages, posts, media); the rest of wp-admin is hidden. Your admin account is unaffected.')
                        : esc_html('Off — no role or lockdown is applied.'); ?></p>
                    <div class="bcn-note" data-role="client-status" aria-live="polite" hidden>
                        <span class="dashicons" data-role="ico" aria-hidden="true"></span>
                        <span data-role="msg"></span>
                    </div>
                    <?php if (!$on): ?>
                    <p class="bcn-hint">Turn this on to invite clients and give them a locked portal. Administrators always keep full access.</p>
                    <?php endif; ?>
                </div>
                <?php self::render_manager($on); ?>
            </div>
        </section>
        <?php
    }

    /**
     * Client roster + add/invite controls. Rendered inside the card and hidden
     * (not omitted) when client mode is off, so the toggle can reveal it with
     * no page reload. Admin-only surface — the card only renders on the
     * Connection panel, which is already manage_options-gated.
     */
    private static function render_manager(bool $visible): void {
        $clients    = self::client_rows();
        $assignable = self::assignable_rows();
        ?>
        <div class="bcn-client-manager" data-bcn-clients
             data-nonce="<?php echo esc_attr(wp_create_nonce(self::NONCE_MANAGE)); ?>"
             <?php echo $visible ? '' : 'hidden'; ?>>
            <h3 class="bcn-client-title">Clients</h3>

            <ul class="bcn-client-list" data-role="client-list">
                <?php if (empty($clients)): ?>
                    <li class="bcn-client-empty" data-role="client-empty">No clients yet — add or invite one below.</li>
                <?php else: foreach ($clients as $c): ?>
                    <li class="bcn-client-item" data-uid="<?php echo esc_attr((string) $c['id']); ?>">
                        <span class="bcn-client-meta">
                            <span class="bcn-client-name"><?php echo esc_html($c['name']); ?></span>
                            <span class="bcn-client-email"><?php echo esc_html($c['email']); ?></span>
                        </span>
                        <button type="button" class="bcn-btn danger sm" data-role="client-remove"
                                data-uid="<?php echo esc_attr((string) $c['id']); ?>">Remove</button>
                    </li>
                <?php endforeach; endif; ?>
            </ul>

            <div class="bcn-field">
                <label class="bcn-label" for="bcn-client-user">Make an existing user a client</label>
                <div class="bcn-client-row">
                    <select class="bcn-input sans" id="bcn-client-user" data-role="client-user">
                        <?php if (empty($assignable)): ?>
                            <option value="">No eligible users</option>
                        <?php else: ?>
                            <option value="">Choose a user…</option>
                            <?php foreach ($assignable as $a): ?>
                                <option value="<?php echo esc_attr((string) $a['id']); ?>"><?php echo esc_html($a['name'] . ' — ' . $a['email']); ?></option>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </select>
                    <button type="button" class="bcn-btn sm" data-role="client-add">Make client</button>
                </div>
            </div>

            <div class="bcn-field">
                <label class="bcn-label" for="bcn-client-email">Or invite a new client by email</label>
                <div class="bcn-client-row">
                    <input type="text"  class="bcn-input sans" id="bcn-client-name"  data-role="client-name"  placeholder="Name (optional)" autocomplete="off">
                    <input type="email" class="bcn-input sans" id="bcn-client-email" data-role="client-email" placeholder="client@example.com" autocomplete="off">
                    <button type="button" class="bcn-btn primary sm" data-role="client-invite">Invite</button>
                </div>
                <p class="bcn-hint">Creates a locked Client account and emails them a link to set their password.</p>
            </div>

            <div class="bcn-note" data-role="client-manage-status" aria-live="polite" hidden>
                <span class="dashicons" data-role="ico" aria-hidden="true"></span>
                <span data-role="msg"></span>
            </div>
        </div>
        <?php
    }

    // ── Client roster helpers ────────────────────────────────────────────

    public static function client_rows(): array {
        $out = [];
        foreach (get_users(['role' => self::ROLE, 'number' => 200, 'orderby' => 'display_name']) as $u) {
            $out[] = ['id' => (int) $u->ID, 'name' => $u->display_name, 'email' => $u->user_email];
        }
        return $out;
    }

    public static function assignable_rows(): array {
        $out = [];
        foreach (get_users(['number' => 200, 'orderby' => 'display_name']) as $u) {
            if (user_can($u, 'manage_options')) continue;                 // admins are never clients
            if (in_array(self::ROLE, (array) $u->roles, true)) continue;  // already a client
            $out[] = ['id' => (int) $u->ID, 'name' => $u->display_name, 'email' => $u->user_email];
        }
        return $out;
    }

    // ── Client assignment (admin only) ───────────────────────────────────

    public function handle_assign(): void {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Forbidden.'], 403);
        }
        if (!check_ajax_referer(self::NONCE_MANAGE, '_wpnonce', false)) {
            wp_send_json_error(['message' => 'Security check failed. Reload and try again.'], 400);
        }
        $this->ensure_role();   // the role must exist before we can grant it

        $uid   = isset($_POST['user_id']) ? absint($_POST['user_id']) : 0;
        $email = isset($_POST['email']) ? sanitize_email(wp_unslash($_POST['email'])) : '';

        if ($uid > 0) {
            $user = get_user_by('id', $uid);
            if (!$user) {
                wp_send_json_error(['message' => 'That user no longer exists.'], 404);
            }
            if (user_can($user, 'manage_options')) {
                wp_send_json_error(['message' => 'Administrators can’t be made clients.'], 400);
            }
            $user->set_role(self::ROLE);
        } elseif ($email !== '') {
            if (!is_email($email)) {
                wp_send_json_error(['message' => 'Enter a valid email address.'], 400);
            }
            $existing = get_user_by('email', $email);
            if ($existing) {
                if (user_can($existing, 'manage_options')) {
                    wp_send_json_error(['message' => 'That email belongs to an administrator.'], 400);
                }
                $existing->set_role(self::ROLE);
            } else {
                $name = isset($_POST['name']) ? sanitize_text_field(wp_unslash($_POST['name'])) : '';
                $base = sanitize_user(current(explode('@', $email)), true);
                if ($base === '') { $base = 'client'; }
                $username = $base; $n = 1;
                while (username_exists($username)) { $username = $base . $n; $n++; }
                $new_id = wp_insert_user([
                    'user_login'   => $username,
                    'user_email'   => $email,
                    'user_pass'    => wp_generate_password(24, true, true),
                    'display_name' => $name !== '' ? $name : $username,
                    'first_name'   => $name,
                    'role'         => self::ROLE,
                ]);
                if (is_wp_error($new_id)) {
                    error_log('[Bynli Connect] client invite failed: ' . $new_id->get_error_message());
                    wp_send_json_error(['message' => $new_id->get_error_message()], 400);
                }
                wp_send_new_user_notifications($new_id, 'user');   // set-password email to the client
            }
        } else {
            wp_send_json_error(['message' => 'Pick a user or enter an email.'], 400);
        }

        wp_send_json_success([
            'clients'    => self::client_rows(),
            'assignable' => self::assignable_rows(),
        ]);
    }

    public function handle_revoke(): void {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Forbidden.'], 403);
        }
        if (!check_ajax_referer(self::NONCE_MANAGE, '_wpnonce', false)) {
            wp_send_json_error(['message' => 'Security check failed. Reload and try again.'], 400);
        }
        $uid  = isset($_POST['user_id']) ? absint($_POST['user_id']) : 0;
        $user = $uid ? get_user_by('id', $uid) : null;
        if (!$user) {
            wp_send_json_error(['message' => 'That user no longer exists.'], 404);
        }
        if (in_array(self::ROLE, (array) $user->roles, true)) {
            $user->set_role('subscriber');   // revert to the default low-privilege role
        }
        wp_send_json_success([
            'clients'    => self::client_rows(),
            'assignable' => self::assignable_rows(),
        ]);
    }
}
