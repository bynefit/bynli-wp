<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Bynli_Connect_Control_Plane — the bynli/v1 REST namespace.
 *
 * The authenticated control plane bynefit.com drives to build/update this site
 * without ever touching raw WP or the owner's login.
 *
 * Auth is HMAC-authoritative: an HMAC over a DEDICATED per-site control-plane
 * secret (NOT the site-host key) — sig = sha256(hmac(secret, ts + "\n" +
 * rawBody)), timestamps outside a 300s window rejected, constant-time compare.
 * The secret is baked into THIS install's mu-plugin loader as
 * BYNLI_CONTROL_PLANE_SECRET and held only by bynefit.com, so a valid signature
 * proves the caller is bynefit.com. On a valid signature the request acts as the
 * site administrator (unless an optional Application Password already
 * established a capable identity) so theme/page writes have the capability they
 * need. Until the secret is provisioned the namespace is fail-closed — every
 * route rejects. An unsigned request is never authorized.
 */
class Bynli_Connect_Control_Plane {

    const NS            = 'bynli/v1';
    const CAP           = 'edit_theme_options';
    const REPLAY_WINDOW = 300;
    const MAX_BODY      = 262144; // 256KB — a design doc is small; cap before any work.
    const SECRET_OPTION = 'bynli_connect_control_plane_secret';
    const PAGE_SLUG_META = '_bynli_site_slug';
    const NAV_MENU_META  = '_bynli_nav';
    const MAX_NAV_ITEMS  = 50;

    const RL_WINDOW = 300; // seconds
    const RL_MAX    = 240; // requests per window per IP — generous for a full multi-page publish

    const NONCE_PAIR = 'bynli_connect_cp_pair';

    public function __construct() {
        add_action('rest_api_init', [$this, 'register_routes']);
        add_action('wp_ajax_bynli_connect_cp_pair',   [$this, 'handle_pair']);
        add_action('wp_ajax_bynli_connect_cp_unpair', [$this, 'handle_unpair']);
        // #63 regression tripwire — the control-plane secret must never become a
        // registered setting (settings UI / REST exposure = admin-level takeover).
        add_action('admin_init',    [$this, 'guard_secret_option'], 999);
        add_action('rest_api_init', [$this, 'guard_secret_option'], 999);
    }

    /**
     * #63 — if any future code register_setting()'s the control-plane secret
     * (which would surface it in the settings UI and, with show_in_rest, over
     * /wp-json/wp/v2/settings), unregister it immediately and log loudly. The
     * option is a deliberate non-setting: no write path, no REST, no UI.
     */
    public function guard_secret_option(): void {
        if (!function_exists('get_registered_settings')) {
            return;
        }
        $registered = get_registered_settings();
        if (isset($registered[self::SECRET_OPTION])) {
            unregister_setting(
                $registered[self::SECRET_OPTION]['group'] ?? 'general',
                self::SECRET_OPTION
            );
            error_log('[Bynli Connect] SECURITY: ' . self::SECRET_OPTION
                . ' was register_setting()\'d — unregistered. This option must never reach the settings UI or REST.');
        }
    }

    public function register_routes(): void {
        register_rest_route(self::NS, '/design', [
            'methods'             => 'POST',
            'callback'            => [$this, 'apply_design'],
            'permission_callback' => [$this, 'authorize'],
        ]);

        register_rest_route(self::NS, '/page', [
            'methods'             => 'POST',
            'callback'            => [$this, 'upsert_page'],
            'permission_callback' => [$this, 'authorize'],
        ]);

        register_rest_route(self::NS, '/navigation', [
            'methods'             => 'POST',
            'callback'            => [$this, 'set_navigation'],
            'permission_callback' => [$this, 'authorize'],
        ]);
    }

    /**
     * The dedicated control-plane secret. Baked as a constant on managed
     * sites; option fallback for bring-your-own. Empty => fail closed.
     */
    public static function secret(): string {
        if (defined('BYNLI_CONTROL_PLANE_SECRET') && BYNLI_CONTROL_PLANE_SECRET) {
            return (string) BYNLI_CONTROL_PLANE_SECRET;
        }
        return (string) get_option(self::SECRET_OPTION, '');
    }

    /** Is app editing currently granted? (Managed installs are always paired.) */
    public static function is_paired(): bool {
        return self::secret() !== '';
    }

    /**
     * Grant Bynefit app editing.
     *
     * We generate the secret here and hand it to bynefit.com over the existing
     * signed site-host channel — bynefit.com cannot push one in, because until a
     * secret exists this namespace is fail-closed and there is nothing to
     * authenticate an inbound push with.
     *
     * Order is deliberate: store locally FIRST, then report. A local write that
     * lands while the network call fails leaves an orphan secret that authorizes
     * nobody (the server holds no matching credential) and is cleaned up below.
     * The reverse order would leave the server holding a secret this site never
     * stored, and every subsequent control-plane call would 401 with no way for
     * the admin to see why.
     */
    public function handle_pair(): void {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Forbidden.'], 403);
        }
        if (!check_ajax_referer(self::NONCE_PAIR, '_ajax_nonce', false)) {
            wp_send_json_error(['message' => 'Security check failed. Reload and try again.'], 403);
        }
        if (defined('BYNLI_CONTROL_PLANE_SECRET') && BYNLI_CONTROL_PLANE_SECRET) {
            wp_send_json_error(['message' => 'This is a Bynefit-managed site — app editing is already enabled.'], 409);
        }

        $home = home_url();
        if (stripos($home, 'https://') !== 0) {
            wp_send_json_error(['message' => 'Your site must be served over HTTPS before app editing can be enabled.'], 400);
        }

        try {
            $secret = bin2hex(random_bytes(32));
        } catch (\Throwable $e) {
            error_log('[Bynli Connect] control-plane pair: no secure randomness — ' . $e->getMessage());
            wp_send_json_error(['message' => 'Could not generate a secure key on this server.'], 500);
        }

        $previous = (string) get_option(self::SECRET_OPTION, '');
        update_option(self::SECRET_OPTION, $secret, false);

        $resp = Bynli_Connect_Api::post_v2(
            '/api/site-host/control-plane/pair',
            ['site_url' => $home, 'secret' => $secret],
            wp_generate_uuid4()
        );

        if (empty($resp['ok'])) {
            $status = (int) ($resp['status'] ?? 0);
            // Only roll back on a DEFINITE refusal (a 4xx we actually received).
            // On a timeout, transport error or 5xx we cannot know whether the
            // server committed — and if it did, deleting our copy would leave
            // bynefit.com holding a credential this site no longer has: it would
            // show the site as app-editable while every control-plane call fails
            // closed, and the admin would have no reason to retry because we
            // told them it failed. Keeping the secret is the recoverable side of
            // that uncertainty; re-pairing rotates in place, so a retry heals it.
            $definite_refusal = $status >= 400 && $status < 500;
            if ($definite_refusal) {
                if ($previous === '') {
                    delete_option(self::SECRET_OPTION);
                } else {
                    update_option(self::SECRET_OPTION, $previous, false);
                }
            }
            error_log('[Bynli Connect] control-plane pair failed (status ' . $status . ', rolled back: '
                . ($definite_refusal ? 'yes' : 'no') . '): ' . (string) ($resp['error'] ?? 'unknown'));
            wp_send_json_error([
                'message' => $definite_refusal
                    ? self::pair_error_message((string) ($resp['error'] ?? ''))
                    : 'We could not confirm this with Bynefit. Please try again in a moment.',
            ], 400);
        }

        wp_send_json_success(['message' => 'App editing is on. You can now design this site from the Bynefit app.']);
    }

    /** Revoke app editing — clears the local secret and tells bynefit.com to drop it. */
    public function handle_unpair(): void {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Forbidden.'], 403);
        }
        if (!check_ajax_referer(self::NONCE_PAIR, '_ajax_nonce', false)) {
            wp_send_json_error(['message' => 'Security check failed. Reload and try again.'], 403);
        }
        if (defined('BYNLI_CONTROL_PLANE_SECRET') && BYNLI_CONTROL_PLANE_SECRET) {
            wp_send_json_error(['message' => 'This is a Bynefit-managed site — app editing cannot be turned off here.'], 409);
        }

        // Local first: revocation must take effect on THIS site even if bynefit.com
        // is unreachable. Once the option is gone every route fails closed, so a
        // failed server call can only leave a stale credential that authorizes
        // nothing.
        delete_option(self::SECRET_OPTION);

        $resp = Bynli_Connect_Api::post_v2('/api/site-host/control-plane/pair', [], wp_generate_uuid4(), 'DELETE');
        if (empty($resp['ok'])) {
            error_log('[Bynli Connect] control-plane unpair: local revoke done, server not notified — '
                . (string) ($resp['error'] ?? 'unknown'));
            // Say so rather than claiming a clean revoke: access IS off (the
            // namespace fails closed without the local secret), but Bynefit may
            // still list this site as editable until it retries.
            wp_send_json_success([
                'message' => 'App editing is off on this site. We could not reach Bynefit to confirm — it may still show as connected there for a short while.',
            ]);
        }

        wp_send_json_success(['message' => 'App editing is off. Bynefit can no longer change this site.']);
    }

    /**
     * Connection-panel card. Rendered only on the manage_options-gated settings
     * screen. Managed installs show state without controls — their secret is
     * baked into the mu-loader and isn't ours to revoke from here.
     */
    public static function render_card(): void {
        $managed    = defined('BYNLI_CONTROL_PLANE_SECRET') && BYNLI_CONTROL_PLANE_SECRET;
        $paired     = self::is_paired();
        $has_key    = (bool) Bynli_Connect_Settings::key();
        $is_https   = stripos(home_url(), 'https://') === 0;
        $nonce      = wp_create_nonce(self::NONCE_PAIR);
        ?>
        <section class="bcn-card">
            <div class="bcn-card-head">
                <h2>App editing</h2>
                <span class="bcn-card-sub">Design this site from the Bynefit app</span>
            </div>
            <div class="bcn-card-body">
                <?php if ($managed): ?>
                    <p class="bcn-hint">This site is hosted by Bynefit, so app editing is always on.</p>
                <?php elseif (!$has_key): ?>
                    <p class="bcn-hint">Add your Bynefit key above first — app editing uses the same secure connection.</p>
                <?php elseif (!$is_https): ?>
                    <div class="bcn-notice bcn-notice-warn">
                        Your site needs HTTPS before app editing can be turned on. Bynefit will not send
                        changes over an unencrypted connection.
                    </div>
                <?php elseif ($paired): ?>
                    <div class="bcn-notice bcn-notice-ok">App editing is on. This site appears in the Bynefit app's designer.</div>
                    <p class="bcn-hint">
                        Bynefit can update pages it created and your site's design. Pages you made in
                        WordPress are left alone.
                    </p>
                    <form class="bcn-ajax-form" data-bcn-action="bynli_connect_cp_unpair"
                          data-bcn-on-success="cp_state"
                          data-bcn-confirm="Turn off app editing? Bynefit will no longer be able to update this site's design or the pages it created."
                          novalidate>
                        <input type="hidden" name="_ajax_nonce" value="<?php echo esc_attr($nonce); ?>">
                        <div class="bcn-form-feedback" data-role="feedback" hidden></div>
                        <button type="submit" class="bcn-btn danger" data-role="submit">Turn off app editing</button>
                    </form>
                <?php else: ?>
                    <p class="bcn-hint">
                        Turn this on to design this site from the Bynefit app. Bynefit will be able to
                        update your site's design and the pages it creates. You can turn it off any time.
                    </p>
                    <form class="bcn-ajax-form" data-bcn-action="bynli_connect_cp_pair"
                          data-bcn-on-success="cp_state" novalidate>
                        <input type="hidden" name="_ajax_nonce" value="<?php echo esc_attr($nonce); ?>">
                        <div class="bcn-form-feedback" data-role="feedback" hidden></div>
                        <button type="submit" class="bcn-btn primary" data-role="submit">Turn on app editing</button>
                    </form>
                <?php endif; ?>
            </div>
        </section>
        <?php
    }

    /** Map the server's coarse error codes to something an admin can act on. */
    private static function pair_error_message(string $code): string {
        switch ($code) {
            case 'managed_site':
                return 'This site is Bynefit-managed — app editing is already enabled.';
            case 'site_url_mismatch':
                return 'This site address does not match the one registered with Bynefit. Update the site in Bynefit first.';
            case 'insecure_site_url':
                return 'Your site must be served over HTTPS before app editing can be enabled.';
            case 'unauthorized':
            case 'signature_invalid':
                return 'Your Bynefit key was rejected. Re-check the key on this page and try again.';
            case 'site_not_found':
            case 'site_url_unregistered':
                return 'Bynefit does not have this site address registered. Add the site in Bynefit first, then try again.';
            case 'site_url_not_public':
                return 'Bynefit could not reach this site address from the internet.';
            case 'no_key':
                return 'Add your Bynefit key on this page first.';
            case 'transport':
                return 'Could not reach Bynefit — check your server\'s outbound connection and try again.';
            default:
                return 'Bynefit could not enable app editing right now. Please try again.';
        }
    }

    /**
     * Dual-auth gate. Returns true, or a WP_Error (401/403) that WP renders as
     * the REST response — never leaks which layer failed beyond a coarse code.
     */
    public function authorize(WP_REST_Request $request) {
        // Uniform 401 for both "unprovisioned" and "bad signature" so an
        // unauthenticated probe can't distinguish a provisioned site from an
        // unprovisioned one. Still fail-closed either way.
        $unauthorized = new WP_Error('unauthorized', 'Unauthorized.', ['status' => 401]);

        // #53 — per-IP throttle on the namespace. The cap bounds UNAUTHENTICATED
        // attempts only: a request that carries a valid signature is admitted
        // even over the cap (checked below), so a burst of junk from a shared
        // egress IP — e.g. a CDN-fronted bring-your-own site where REMOTE_ADDR
        // is the edge, not the caller — can never lock bynefit.com out of its own
        // control plane. Transient-backed; works without an object cache.
        $rate_ok = self::rate_limit_ok();

        $secret = self::secret();
        if ($secret === '') {
            self::log_reject('unconfigured');
            return $unauthorized;
        }

        // Bound the body before any parsing/HMAC work — memory-DoS guard.
        $body = (string) $request->get_body();
        if (strlen($body) > self::MAX_BODY) {
            self::log_reject('body_too_large');
            return new WP_Error('body_too_large', 'Request body too large.', ['status' => 413]);
        }

        $ts  = (int) $request->get_header('x-bynli-timestamp');
        $sig = (string) $request->get_header('x-bynli-signature');
        if ($ts <= 0 || $sig === '' || !Bynli_Connect_Signer::verify($secret, $ts, $body, $sig, self::REPLAY_WINDOW)) {
            // Unsigned/invalid AND over the cap: this is the abuse case the
            // throttle exists for. Report the throttle rather than the coarse
            // 401 so a legitimate client backs off instead of retrying blind.
            if (!$rate_ok) {
                self::log_reject('rate_limited');
                return new WP_Error('rate_limited', 'Too many requests.', ['status' => 429]);
            }
            self::log_reject('signature');
            return $unauthorized;
        }

        // The HMAC over the dedicated per-site secret authenticates bynefit.com as
        // the caller: the secret is baked only into THIS install's mu-plugin
        // loader and held only by bynefit.com, so a valid signature is proof of
        // origin. If a request already carries a capable identity (an optional
        // Application Password), keep it; otherwise act as the site's
        // administrator so the theme/page writes have the required capability.
        // The signature is the gate — never authorize an unsigned request.
        if (!is_user_logged_in() || !current_user_can(self::CAP)) {
            $service = self::service_user_id();
            if ($service <= 0) {
                self::log_reject('no_service_user');
                return new WP_Error('no_service_user', 'No capable user available.', ['status' => 500]);
            }
            wp_set_current_user($service);
        }
        if (!current_user_can(self::CAP)) {
            self::log_reject('capability');
            return new WP_Error('forbidden', 'Insufficient capability.', ['status' => 403]);
        }

        return true;
    }

    /** Lowest-id administrator to act as after a valid control-plane signature. */
    private static function service_user_id(): int {
        $admins = get_users([
            'role'    => 'administrator',
            'number'  => 1,
            'orderby' => 'ID',
            'order'   => 'ASC',
            'fields'  => 'ID',
        ]);
        return $admins ? (int) $admins[0] : 0;
    }

    /** Record an auth rejection (coarse reason only — never ts/sig/secret). */
    private static function log_reject(string $reason): void {
        error_log('[Bynli Connect] control-plane auth reject: ' . $reason);
    }

    /**
     * #53 — FIXED per-IP window: returns false once the IP exceeds RL_MAX within
     * RL_WINDOW, and the bucket resets when its window elapses. Best-effort by
     * design: transients can be evicted early under memory pressure, which only
     * ever loosens the limit. Auth (HMAC) remains the actual gate — a request
     * bearing a valid signature is admitted even over the cap, so this bounds
     * unauthenticated ATTEMPTS rather than pre-auth CPU.
     */
    private static function rate_limit_ok(): bool {
        $ip = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '';
        if ($ip === '') {
            return true; // CLI/unusual SAPI — nothing meaningful to key on
        }
        $key    = 'bynli_cp_rl_' . md5($ip);
        $now    = time();
        $bucket = get_transient($key);

        // FIXED window keyed on its own start time. A naive counter that re-set
        // the TTL on every hit would never reset for a caller whose gaps stay
        // under the window, so a long publishing session would eventually 429
        // despite never exceeding the intended rate.
        if (!is_array($bucket) || !isset($bucket['start'], $bucket['count'])
            || ($now - (int) $bucket['start']) > self::RL_WINDOW) {
            $bucket = ['start' => $now, 'count' => 0];
        }
        if ((int) $bucket['count'] >= self::RL_MAX) {
            return false;
        }
        $bucket['count'] = (int) $bucket['count'] + 1;
        $ttl = max(1, self::RL_WINDOW - ($now - (int) $bucket['start']));
        set_transient($key, $bucket, $ttl);
        return true;
    }

    /**
     * apply_design — write the site's user Global Styles (theme.json-shaped
     * overrides) so a token set / resolved variation re-skins the whole site.
     * The emitter sends the already-resolved { settings, styles } (token values
     * resolved server-side); we persist it to the active theme's user global
     * styles CPT post, which the block theme reads natively.
     */
    public function apply_design(WP_REST_Request $request) {
        $body = json_decode((string) $request->get_body(), true);
        if (!is_array($body)) {
            return new WP_Error('invalid_json', 'Body is not valid JSON.', ['status' => 400]);
        }

        $settings = isset($body['settings']) && is_array($body['settings']) ? $body['settings'] : null;
        $styles   = isset($body['styles']) && is_array($body['styles']) ? $body['styles'] : null;
        if ($settings === null && $styles === null) {
            return new WP_Error('empty_design', 'Provide settings and/or styles.', ['status' => 422]);
        }

        if (!class_exists('WP_Theme_JSON_Resolver')) {
            return new WP_Error('unsupported', 'Global styles unavailable on this WordPress version.', ['status' => 501]);
        }

        $post_id = WP_Theme_JSON_Resolver::get_user_global_styles_post_id();
        if (!$post_id) {
            return new WP_Error('no_global_styles', 'Could not resolve the user global styles record.', ['status' => 500]);
        }

        // Schema version tracks the running WP (v3 is WP 6.6+); never hardcode
        // a version the site can't understand.
        $version = defined('WP_Theme_JSON::LATEST_SCHEMA') ? WP_Theme_JSON::LATEST_SCHEMA : 2;

        // Build the theme.json-shaped document. Only the two data keys are
        // carried through; run it through WP_Theme_JSON so unknown/insecure
        // properties are stripped to the known theme.json paths before we
        // persist — never trust the payload shape even from the control plane.
        $doc = ['version' => $version];
        if ($settings !== null) {
            $doc['settings'] = $settings;
        }
        if ($styles !== null) {
            $doc['styles'] = $styles;
        }

        if (!class_exists('WP_Theme_JSON')) {
            return new WP_Error('unsupported', 'Global styles unavailable on this WordPress version.', ['status' => 501]);
        }
        $sanitized = ( new WP_Theme_JSON($doc, 'custom') )->get_raw_data();
        // The marker flag is required for WP to treat this post as user global
        // styles; set it ourselves, after sanitization, never from the request.
        $sanitized['isGlobalStylesUserThemeJSON'] = true;

        $encoded = wp_json_encode($sanitized);
        if ($encoded === false) {
            return new WP_Error('encode_failed', 'Could not encode the design document.', ['status' => 500]);
        }

        $result = wp_update_post([
            'ID'           => $post_id,
            'post_content' => wp_slash($encoded),
        ], true);

        if (is_wp_error($result)) {
            error_log('[Bynli Connect] apply_design: ' . $result->get_error_message());
            return new WP_Error('write_failed', 'Could not persist the design.', ['status' => 500]);
        }

        // Drop cached resolved theme.json so the new styles take effect at once.
        if (method_exists('WP_Theme_JSON_Resolver', 'clean_cached_data')) {
            WP_Theme_JSON_Resolver::clean_cached_data();
        }

        return new WP_REST_Response(['ok' => true, 'global_styles_id' => (int) $post_id], 200);
    }

    /**
     * upsert_page — publish one scene-graph page to a WP page, idempotently.
     *
     * The publish-contract gate runs first: a page that fails is refused with
     * every violation so the app can surface the exact fix — nothing is written
     * on a failing document. On pass, the scene graph is emitted to registered
     * block markup and written to the page identified by its scene-graph slug
     * (stored in meta so re-publishing updates the same post rather than forking
     * a new one).
     */
    public function upsert_page(WP_REST_Request $request) {
        $body = json_decode((string) $request->get_body(), true);
        if (!is_array($body)) {
            return new WP_Error('invalid_json', 'Body is not valid JSON.', ['status' => 400]);
        }

        $page  = is_array($body['page'] ?? null) ? $body['page'] : null;
        $media = is_array($body['media'] ?? null) ? $body['media'] : [];
        if ($page === null) {
            return self::unpublishable([['code' => 'empty_page', 'path' => 'page', 'message' => 'Provide a page node.']]);
        }

        $check = Bynli_Connect_Publish_Contract::validate($page, $media);
        if (!$check['ok']) {
            return self::unpublishable($check['violations']);
        }

        $markup = Bynli_Connect_Emitter::emit_page($page, $media);
        if (trim($markup) === '') {
            return self::unpublishable([['code' => 'empty_output', 'path' => 'sections', 'message' => 'The page emitted no content.']]);
        }

        $scene_slug = (string) ($page['slug'] ?? '');
        $wp_slug    = sanitize_title(trim($scene_slug, '/')) ?: 'home';
        $title      = (string) ($page['title'] ?? 'Untitled');
        $seo_desc   = (string) (self::deep($page, ['seo', 'description']) ?? '');

        global $wpdb;
        // Serialize concurrent publishes of the same scene slug so a retry can't
        // fork a duplicate page between the lookup and the insert. Best-effort:
        // if the lock can't be taken we still proceed rather than fail the write.
        $lock = 'bynli_upsert_' . md5($scene_slug);
        $locked = (int) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 10)', $lock)) === 1;

        try {
            $existing = get_posts([
                'post_type'        => 'page',
                'post_status'      => 'any',
                'numberposts'      => 1,
                'fields'           => 'ids',
                'meta_key'         => self::PAGE_SLUG_META,
                'meta_value'       => $scene_slug,
                'suppress_filters' => false,
            ]);

            $postarr = [
                'post_type'    => 'page',
                'post_status'  => 'publish',
                'post_title'   => $title,
                'post_name'    => $wp_slug,
                'post_content' => wp_slash($markup),
                'post_excerpt' => wp_slash($seo_desc),
            ];

            $created = empty($existing);
            if (!$created) {
                $postarr['ID'] = (int) $existing[0];
                $post_id = wp_update_post($postarr, true);
            } else {
                $post_id = wp_insert_post($postarr, true);
            }
        } finally {
            if ($locked) {
                $wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock));
            }
        }

        if (is_wp_error($post_id) || !$post_id) {
            $msg = is_wp_error($post_id) ? $post_id->get_error_message() : 'unknown';
            error_log('[Bynli Connect] upsert_page: ' . $msg);
            return new WP_Error('write_failed', 'Could not persist the page.', ['status' => 500]);
        }

        $post_id = (int) $post_id;
        update_post_meta($post_id, self::PAGE_SLUG_META, $scene_slug);
        update_post_meta($post_id, '_wp_page_template', 'page-wide');
        if ($seo_desc !== '') {
            update_post_meta($post_id, '_bynli_seo_description', $seo_desc);
        }

        // Front-page assignment is authoritative per publish: claim it when
        // isHome is set, and release it when this page previously held it but no
        // longer claims home, so home never silently sticks to a stale page.
        if (!empty($page['isHome'])) {
            update_option('show_on_front', 'page');
            update_option('page_on_front', $post_id);
        } elseif ((int) get_option('page_on_front') === $post_id) {
            update_option('page_on_front', 0);
            update_option('show_on_front', 'posts');
        }

        do_action('bynli_connect_page_upserted', $post_id, $scene_slug, $created);

        return new WP_REST_Response([
            'ok'      => true,
            'page_id' => $post_id,
            'url'     => get_permalink($post_id) ?: '',
        ], 200);
    }

    /**
     * set_navigation — write the site's primary navigation as a single managed
     * wp_navigation post (the block-theme Navigation source). Idempotent: reuses
     * the one post we own (marked with NAV_MENU_META) so re-publishing updates
     * the same menu rather than forking a new one. A ref-less core/navigation
     * block in a block theme resolves to the most-recently-published
     * wp_navigation post, so the theme picks this menu up without a template-part
     * edit.
     *
     * Each item resolves to a URL: an item carrying a scene-graph `slug` links to
     * that published page's permalink (so nav stays in sync with pages);
     * otherwise an explicit http(s) `url` is used. Items with neither a
     * resolvable slug nor a valid url are dropped.
     */
    public function set_navigation(WP_REST_Request $request) {
        $body = json_decode((string) $request->get_body(), true);
        if (!is_array($body)) {
            return new WP_Error('invalid_json', 'Body is not valid JSON.', ['status' => 400]);
        }

        $items = is_array($body['items'] ?? null) ? $body['items'] : [];
        if (!$items) {
            return new WP_Error('empty_navigation', 'Provide navigation items.', ['status' => 422]);
        }
        if (count($items) > self::MAX_NAV_ITEMS) {
            $items = array_slice($items, 0, self::MAX_NAV_ITEMS);
        }

        $label = sanitize_text_field((string) ($body['label'] ?? 'Primary'));
        if ($label === '') {
            $label = 'Primary';
        }

        $blocks = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $text = sanitize_text_field((string) ($item['label'] ?? ''));
            if ($text === '') {
                continue;
            }

            $url  = '';
            $slug = isset($item['slug']) ? (string) $item['slug'] : '';
            if ($slug !== '') {
                $url = self::permalink_for_slug($slug);
            }
            if ($url === '' && isset($item['url'])) {
                $candidate = esc_url_raw((string) $item['url']);
                if ($candidate !== '' && preg_match('#^https?://#i', $candidate)) {
                    $url = $candidate;
                }
            }
            if ($url === '') {
                continue;
            }

            $blocks[] = [
                'blockName'    => 'core/navigation-link',
                'attrs'        => ['label' => $text, 'url' => $url, 'kind' => 'custom', 'type' => 'custom'],
                'innerBlocks'  => [],
                'innerHTML'    => '',
                'innerContent' => [],
            ];
        }

        if (!$blocks) {
            return new WP_Error('no_resolvable_items', 'No navigation items resolved to a URL.', ['status' => 422]);
        }

        // serialize_blocks() JSON-encodes attributes through the same escaping WP
        // uses for block delimiters (-->, <, >, &), so the label/url are safe in
        // the block comment even though they originate off-site.
        $content = serialize_blocks($blocks);

        // Write into the post the active theme actually renders. If the block
        // theme's header binds a specific wp_navigation post (a `ref` on its
        // core/navigation block), update THAT post so the menu is really visible
        // — otherwise a managed post is an orphan the theme never shows. Only
        // when the header nav is ref-less do we own a managed post (marked with
        // NAV_MENU_META); a ref-less navigation block resolves to the
        // most-recently-published wp_navigation post, so we also keep its date
        // current to stay selected.
        // get_post() also returns TRASHED posts, so the status check matters: a
        // trashed bound menu must NOT count as bound — otherwise publishing would
        // silently resurrect it from the trash (post_status => publish below).
        $bound_ref = self::header_navigation_ref();
        $is_bound  = $bound_ref > 0
            && ($p = get_post($bound_ref)) instanceof WP_Post
            && $p->post_type === 'wp_navigation'
            && $p->post_status !== 'trash';
        // #66 — a header ref that no longer resolves (menu trashed, deleted, or
        // pointing at a non-navigation post): we fall back to the managed post,
        // but the theme's header still renders the dead ref, so the customer's
        // menu won't reflect this publish. Surface it distinctly (additive key —
        // `bound` stays boolean for existing decoders) so the app can prompt the
        // owner to re-pick a menu.
        $stale_ref = ($bound_ref > 0 && !$is_bound);

        $target_id = 0;
        if ($is_bound) {
            $target_id = $bound_ref;
        } else {
            $existing = get_posts([
                'post_type'        => 'wp_navigation',
                'post_status'      => 'any',
                'numberposts'      => 1,
                'fields'           => 'ids',
                'meta_key'         => self::NAV_MENU_META,
                'meta_value'       => '1',
                'suppress_filters' => false,
            ]);
            $target_id = $existing ? (int) $existing[0] : 0;
        }

        $postarr = [
            'post_type'    => 'wp_navigation',
            'post_status'  => 'publish',
            'post_content' => wp_slash($content),
        ];
        if ($target_id > 0) {
            $postarr['ID'] = $target_id;
            // Don't rename a menu the theme owns; only title the managed post.
            // Keep the managed post most-recent so the ref-less fallback picks it.
            if (!$is_bound) {
                $postarr['post_title']    = $label;
                $postarr['post_date']     = current_time('mysql');
                $postarr['post_date_gmt'] = current_time('mysql', true);
            }
            $nav_id = wp_update_post($postarr, true);
        } else {
            $postarr['post_title'] = $label;
            $nav_id = wp_insert_post($postarr, true);
        }

        if (is_wp_error($nav_id) || !$nav_id) {
            $msg = is_wp_error($nav_id) ? $nav_id->get_error_message() : 'unknown';
            error_log('[Bynli Connect] set_navigation: ' . $msg);
            return new WP_Error('write_failed', 'Could not persist the navigation.', ['status' => 500]);
        }

        $nav_id = (int) $nav_id;
        if (!$is_bound) {
            update_post_meta($nav_id, self::NAV_MENU_META, '1');
        }

        if ($stale_ref) {
            error_log('[Bynli Connect] set_navigation: header nav ref ' . $bound_ref
                . ' no longer resolves — wrote the managed post, but the theme header renders the dead ref.');
        }

        return new WP_REST_Response([
            'ok'            => true,
            'navigation_id' => $nav_id,
            'items'         => count($blocks),
            'bound'         => $is_bound,
            'stale_ref'     => $stale_ref,
        ], 200);
    }

    /**
     * The wp_navigation post id the active block theme's header actually renders
     * (the `ref` on its header core/navigation block), or 0 if the header nav is
     * ref-less or there's no block header. Lets set_navigation write into the
     * post the theme really shows instead of an orphaned managed post.
     */
    private static function header_navigation_ref(): int {
        if (!function_exists('get_block_template') || !function_exists('parse_blocks')) {
            return 0;
        }
        $tpl = get_block_template(get_stylesheet() . '//header', 'wp_template_part');
        if (!$tpl || empty($tpl->content)) {
            return 0;
        }
        return self::find_navigation_ref(parse_blocks($tpl->content));
    }

    /** First non-zero core/navigation `ref` in a parsed block tree, else 0. */
    private static function find_navigation_ref(array $blocks): int {
        foreach ($blocks as $block) {
            if (($block['blockName'] ?? '') === 'core/navigation') {
                $ref = (int) ($block['attrs']['ref'] ?? 0);
                if ($ref > 0) {
                    return $ref;
                }
            }
            if (!empty($block['innerBlocks'])) {
                $nested = self::find_navigation_ref($block['innerBlocks']);
                if ($nested > 0) {
                    return $nested;
                }
            }
        }
        return 0;
    }

    /** Permalink of the published page carrying this scene-graph slug, or '' if none. */
    private static function permalink_for_slug(string $sceneSlug): string {
        if ($sceneSlug === '') {
            return '';
        }
        $ids = get_posts([
            'post_type'        => 'page',
            'post_status'      => 'publish',
            'numberposts'      => 1,
            'fields'           => 'ids',
            'meta_key'         => self::PAGE_SLUG_META,
            'meta_value'       => $sceneSlug,
            'suppress_filters' => false,
        ]);
        if (!$ids) {
            return '';
        }
        $url = get_permalink((int) $ids[0]);
        return $url ?: '';
    }

    private static function unpublishable(array $violations): WP_REST_Response {
        return new WP_REST_Response(['ok' => false, 'violations' => $violations], 422);
    }

    private static function deep(array $node, array $keys) {
        $cur = $node;
        foreach ($keys as $k) {
            if (!is_array($cur) || !array_key_exists($k, $cur)) {
                return null;
            }
            $cur = $cur[$k];
        }
        return $cur;
    }
}
