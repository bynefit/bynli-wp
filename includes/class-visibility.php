<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Site visibility + content gating (consolidation epic — retires the legacy
 * "cms" coming-soon plugin and the "dms" login-wall shortcodes).
 *
 * Three site modes (bynli_connect_visibility option):
 *   live          — normal (default).
 *   coming_soon   — logged-out visitors get a branded 503 holding page.
 *   members_only  — logged-out visitors are sent to wp-login and returned.
 *
 * Plus [bynli-gate]…[/bynli-gate] for per-content gating: logged-out readers
 * see a login prompt instead of the wrapped content.
 *
 * The front-end holding page is a self-contained document served in place of
 * the theme (like the plugin it replaces), so it carries its own <style> — the
 * "all CSS in admin.css" rule is scoped to the wp-admin console, not a
 * standalone front page that renders before the theme loads.
 */
class Bynli_Connect_Visibility {

    const OPTION = 'bynli_connect_visibility';
    const NONCE  = 'bynli_connect_visibility';
    const AJAX   = 'bynli_connect_visibility';
    const MODES  = ['live', 'coming_soon', 'members_only'];

    public function __construct() {
        add_action('template_redirect',        [$this, 'gate'], 1);
        add_action('wp_ajax_' . self::AJAX,     [$this, 'handle_ajax']);
        add_shortcode('bynli-gate',             [$this, 'shortcode_gate']);
    }

    /** Current site mode, whitelisted. */
    public static function mode(): string {
        $v = (string) get_option(self::OPTION, 'live');
        return in_array($v, self::MODES, true) ? $v : 'live';
    }

    private function sanitize($v): string {
        $v = (string) $v;
        return in_array($v, self::MODES, true) ? $v : 'live';
    }

    // ── Front-end site gate ──────────────────────────────────────────────

    public function gate(): void {
        $mode = self::mode();
        if ($mode === 'live')          return;
        if (is_user_logged_in())       return;               // any authed user passes
        if (is_admin())                return;               // wp-admin has its own auth
        if (defined('DOING_AJAX')  && DOING_AJAX)  return;
        if (defined('DOING_CRON')  && DOING_CRON)  return;
        if (defined('REST_REQUEST') && REST_REQUEST) return;
        global $pagenow;
        if ($pagenow === 'wp-login.php') return;             // never lock out the login

        if ($mode === 'members_only') {
            wp_safe_redirect(wp_login_url($this->current_url()));
            exit;
        }

        // coming_soon — branded holding page + 503 so crawlers come back.
        if (!headers_sent()) {
            status_header(503);
            header('Retry-After: 3600');
        }
        $this->render_holding_page();
        exit;
    }

    private function current_url(): string {
        $host = $_SERVER['HTTP_HOST'] ?? wp_parse_url(home_url(), PHP_URL_HOST);
        $uri  = $_SERVER['REQUEST_URI'] ?? '/';
        return esc_url_raw((is_ssl() ? 'https://' : 'http://') . $host . $uri);
    }

    private function render_holding_page(): void {
        $name = esc_html(get_bloginfo('name'));
        $tag  = esc_html(get_bloginfo('description'));
        $login = esc_url(wp_login_url());
        header('Content-Type: text/html; charset=utf-8');
        echo <<<HTML
<!doctype html>
<html lang="en"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex">
<title>{$name} — Coming soon</title>
<style>
:root{--bg:#080c12;--panel:#111823;--ink:#e9edf2;--muted:#8a97a6;--accent:#2dd4c2;--line:#1d2734}
*{box-sizing:border-box}html,body{height:100%}
body{margin:0;display:flex;align-items:center;justify-content:center;min-height:100vh;
background:radial-gradient(120% 120% at 100% 0%,rgba(45,212,194,.14),transparent 60%),var(--bg);
color:var(--ink);font-family:system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;padding:24px}
.card{max-width:460px;text-align:center}
.node{width:56px;height:56px;margin:0 auto 22px;color:var(--accent)}
h1{font-size:22px;font-weight:700;letter-spacing:-.02em;margin:0 0 8px}
.tag{color:var(--muted);font-size:15px;line-height:1.5;margin:0 0 26px}
.pill{display:inline-flex;align-items:center;gap:8px;font:600 12px/1 ui-monospace,SFMono-Regular,Menlo,monospace;
text-transform:uppercase;letter-spacing:.12em;color:var(--accent);background:rgba(45,212,194,.1);
border:1px solid var(--line);border-radius:999px;padding:7px 14px}
.beacon{width:7px;height:7px;border-radius:50%;background:var(--accent)}
.login{display:inline-block;margin-top:26px;color:var(--muted);font-size:13px;text-decoration:none;border-bottom:1px solid var(--line)}
.login:hover{color:var(--ink)}
</style></head>
<body><main class="card">
<svg class="node" viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle cx="12" cy="12" r="3.2" fill="currentColor"/><circle cx="12" cy="12" r="7" stroke="currentColor" stroke-width="1.4" opacity=".55"/><circle cx="12" cy="12" r="10.6" stroke="currentColor" stroke-width="1.2" opacity=".25"/></svg>
<span class="pill"><span class="beacon"></span>Coming soon</span>
<h1>{$name}</h1>
<p class="tag">{$tag}</p>
<a class="login" href="{$login}">Team sign in</a>
</main></body></html>
HTML;
    }

    // ── [bynli-gate] — per-content login gate (retires dms login walls) ──

    private static $gate_styled = false;

    public function shortcode_gate($atts, $content = null): string {
        if (is_user_logged_in()) {
            return do_shortcode((string) $content);
        }
        $a = shortcode_atts([
            'message' => 'This content is for members. Please sign in to continue.',
            'label'   => 'Sign in',
        ], (array) $atts, 'bynli-gate');

        // One-time self-contained style for the front-end block (front output,
        // not the admin console — theme-agnostic so it looks intentional on any
        // theme; a theme can override via the .bynli-gate classes).
        $style = '';
        if (!self::$gate_styled) {
            self::$gate_styled = true;
            $style = '<style>.bynli-gate{max-width:520px;margin:24px auto;padding:26px 24px;text-align:center;'
                   . 'border:1px solid #e1e6ec;border-radius:14px;background:#f5f7fa}'
                   . '.bynli-gate-msg{margin:0 0 16px;color:#253044;font-size:15px;line-height:1.5}'
                   . '.bynli-gate-btn{display:inline-block;padding:10px 20px;border-radius:8px;'
                   . 'background:#0b7a70;color:#fff;text-decoration:none;font-weight:600}'
                   . '.bynli-gate-btn:hover{background:#095f57}</style>';
        }

        $login = esc_url(wp_login_url(get_permalink() ?: home_url()));
        return $style . sprintf(
            '<div class="bynli-gate" role="note">'
            . '<p class="bynli-gate-msg">%1$s</p>'
            . '<a class="bynli-gate-btn" href="%2$s">%3$s</a>'
            . '</div>',
            esc_html($a['message']),
            $login,
            esc_html($a['label'])
        );
    }

    // ── Console save (AJAX, mirrors the theme-toggle pattern) ────────────

    public function handle_ajax(): void {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Forbidden.'], 403);
        }
        if (!check_ajax_referer(self::NONCE, '_wpnonce', false)) {
            wp_send_json_error(['message' => 'Security check failed. Reload and try again.'], 400);
        }
        $mode = isset($_POST['mode']) ? $this->sanitize((string) $_POST['mode']) : 'live';
        update_option(self::OPTION, $mode);
        wp_send_json_success(['mode' => $mode]);
    }

    // ── Console card (rendered inside the Connection panel) ──────────────

    public static function render_card(): void {
        $mode = self::mode();
        $opts = [
            'live'         => ['Live',          'Your site is public — normal behavior.'],
            'coming_soon'  => ['Coming soon',   'Logged-out visitors see a branded holding page (503).'],
            'members_only' => ['Members only',  'Logged-out visitors are sent to sign in first.'],
        ];
        ?>
        <section class="bcn-card">
            <div class="bcn-card-head">
                <h2>Site visibility</h2>
                <span class="bcn-card-sub">Who can see the front of this site</span>
            </div>
            <div class="bcn-card-body">
                <div class="bcn-field">
                    <label class="bcn-label" for="bcn-visibility">Visibility</label>
                    <select class="bcn-input sans" id="bcn-visibility"
                            data-bcn-visibility
                            data-nonce="<?php echo esc_attr(wp_create_nonce(self::NONCE)); ?>">
                        <?php foreach ($opts as $val => [$label, $hint]): ?>
                            <option value="<?php echo esc_attr($val); ?>" <?php selected($mode, $val); ?>>
                                <?php echo esc_html($label); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <p class="bcn-hint" data-role="vis-hint"><?php echo esc_html($opts[$mode][1]); ?></p>
                    <div class="bcn-note" data-role="vis-status" aria-live="polite" hidden>
                        <span class="dashicons" data-role="ico" aria-hidden="true"></span>
                        <span data-role="msg"></span>
                    </div>
                </div>
                <p class="bcn-hint">Signed-in users always see the full site. Gate individual posts with <code>[bynli-gate]…[/bynli-gate]</code>.</p>
            </div>
        </section>
        <?php
    }
}
