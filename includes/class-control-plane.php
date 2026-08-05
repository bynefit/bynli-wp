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
    const SECRET_OPTION = 'bynli_connect_control_plane_secret';

    public function __construct() {
        add_action('rest_api_init', [$this, 'register_routes']);
    }

    public function register_routes(): void {
        register_rest_route(self::NS, '/design', [
            'methods'             => 'POST',
            'callback'            => [$this, 'apply_design'],
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
        $secret = self::secret();
        if ($secret === '') {
            return new WP_Error('control_plane_unconfigured', 'Control plane not provisioned.', ['status' => 503]);
        }

        $ts  = (int) $request->get_header('x-bynli-timestamp');
        $sig = (string) $request->get_header('x-bynli-signature');
        if ($ts <= 0 || $sig === '') {
            return new WP_Error('signature_missing', 'Missing signature headers.', ['status' => 401]);
        }
        if (!Bynli_Connect_Signer::verify($secret, $ts, (string) $request->get_body(), $sig, self::REPLAY_WINDOW)) {
            return new WP_Error('signature_invalid', 'Signature check failed.', ['status' => 401]);
        }

        // Transport authN: the Application Password must have authenticated a
        // user with theme-editing capability. Never accept an anonymous or
        // under-privileged caller even if the HMAC is valid.
        if (!is_user_logged_in() || !current_user_can(self::CAP)) {
            return new WP_Error('forbidden', 'Insufficient capability.', ['status' => 403]);
        }

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

        // Build a clean theme.json-shaped document. Only the two data keys are
        // carried through; the required marker flags are set by us, never taken
        // from the request.
        $doc = ['version' => 3, 'isGlobalStylesUserThemeJSON' => true];
        if ($settings !== null) {
            $doc['settings'] = $settings;
        }
        if ($styles !== null) {
            $doc['styles'] = $styles;
        }

        $encoded = wp_json_encode($doc);
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
}
