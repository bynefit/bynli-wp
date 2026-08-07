<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Bynli_Connect_Control_Plane — the bynli/v1 REST namespace.
 *
 * The authenticated control plane bynli.com drives to build/update this site
 * without ever touching raw WP or the owner's login. Every route carries
 * DUAL auth (Glaze's design, Terry 2026-08-05):
 *
 *   1. Transport authN — a WordPress Application Password authenticates the
 *      request as a capable service user (WP core sets the current user from
 *      Basic auth). We require the theme-editing capability.
 *   2. Integrity + replay — an HMAC bridge over a DEDICATED per-site
 *      control-plane secret (NOT the site-host key), same scheme as the
 *      server verifier: sig = sha256(hmac(secret, ts + "\n" + rawBody)),
 *      timestamps outside a 300s window rejected, constant-time compare.
 *
 * BOTH must pass. The secret is provisioned server-side and baked as the
 * BYNLI_CONTROL_PLANE_SECRET constant (like BYNLI_CONNECT_KEY); until it
 * exists the namespace is fail-closed — every route rejects.
 */
class Bynli_Connect_Control_Plane {

    const NS            = 'bynli/v1';
    const CAP           = 'edit_theme_options';
    const REPLAY_WINDOW = 300;
    const MAX_BODY      = 262144; // 256KB — a design doc is small; cap before any work.
    const SECRET_OPTION = 'bynli_connect_control_plane_secret';
    const PAGE_SLUG_META = '_bynli_site_slug';

    public function __construct() {
        add_action('rest_api_init', [$this, 'register_routes']);
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

    /**
     * Dual-auth gate. Returns true, or a WP_Error (401/403) that WP renders as
     * the REST response — never leaks which layer failed beyond a coarse code.
     */
    public function authorize(WP_REST_Request $request) {
        // Uniform 401 for both "unprovisioned" and "bad signature" so an
        // unauthenticated probe can't distinguish a provisioned site from an
        // unprovisioned one. Still fail-closed either way.
        $unauthorized = new WP_Error('unauthorized', 'Unauthorized.', ['status' => 401]);

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
            self::log_reject('signature');
            return $unauthorized;
        }

        // Transport authN: the request must be authenticated (Application
        // Password) as a user with theme-editing capability. Never accept an
        // anonymous or under-privileged caller even if the HMAC is valid.
        if (!is_user_logged_in() || !current_user_can(self::CAP)) {
            self::log_reject('capability');
            return new WP_Error('forbidden', 'Insufficient capability.', ['status' => 403]);
        }

        return true;
    }

    /** Record an auth rejection (coarse reason only — never ts/sig/secret). */
    private static function log_reject(string $reason): void {
        error_log('[Bynli Connect] control-plane auth reject: ' . $reason);
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
            return new WP_Error('empty_page', 'Provide a page node.', ['status' => 422]);
        }

        $check = Bynli_Connect_Publish_Contract::validate($page, $media);
        if (!$check['ok']) {
            return new WP_REST_Response(['ok' => false, 'violations' => $check['violations']], 422);
        }

        $markup = Bynli_Connect_Emitter::emit_page($page, $media);
        if (trim($markup) === '') {
            return new WP_Error('empty_output', 'The page emitted no content.', ['status' => 422]);
        }

        $scene_slug = (string) ($page['slug'] ?? '');
        $wp_slug    = sanitize_title(trim($scene_slug, '/')) ?: 'home';
        $title      = (string) ($page['title'] ?? 'Untitled');
        $seo_desc   = (string) (self::deep($page, ['seo', 'description']) ?? '');

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

        if (!empty($existing)) {
            $postarr['ID'] = (int) $existing[0];
            $post_id = wp_update_post($postarr, true);
        } else {
            $post_id = wp_insert_post($postarr, true);
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

        if (!empty($page['isHome'])) {
            update_option('show_on_front', 'page');
            update_option('page_on_front', $post_id);
        }

        return new WP_REST_Response([
            'ok'      => true,
            'page_id' => $post_id,
            'url'     => get_permalink($post_id) ?: '',
        ], 200);
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
