<?php
error_reporting(E_ALL & ~E_DEPRECATED);
$root = dirname(__DIR__, 2) . '/';
define('ABSPATH', $root);
define('BYNLI_CONNECT_PLUGIN_FILE', $root . 'bynli-connect.php');
define('BYNLI_CONNECT_PLUGIN_DIR', $root);
define('BYNLI_CONNECT_VERSION', '9.9.9');
define('BYNLI_CONNECT_DEFAULT_API_BASE', 'https://bynefit.com');
define('DAY_IN_SECONDS', 86400);
define('HOUR_IN_SECONDS', 3600);
ini_set('error_log', sys_get_temp_dir() . '/bynli-connect-test-error.log');

$scenario = json_decode($argv[1] ?? '{}', true) ?: [];
$GLOBALS['opts'] = $scenario['opts'] ?? [];
$_GET = $scenario['get'] ?? [];
function get_option($k, $d = false) { return array_key_exists($k, $GLOBALS['opts']) ? $GLOBALS['opts'][$k] : $d; }
function update_option($k, $v, $a = null) { $GLOBALS['opts'][$k] = $v; return true; }
function esc_html($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
function esc_attr($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
function esc_url($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
function esc_js($s) { return addslashes((string) $s); }
function esc_textarea($s) { return htmlspecialchars((string) $s); }
function wp_kses_post($s) { return $s; }
function sanitize_text_field($s) { return trim(strip_tags((string) $s)); }
function sanitize_key($s) { return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $s)); }
function wp_json_encode($v) { return json_encode($v); }
function size_format($b, $d = 0) { return round($b / 1048576, $d) . ' MB'; }
function get_bloginfo($k = '') { return '6.6'; }
function human_time_diff($a, $b = 0) { $b = $b ?: time(); $d = abs($b - $a); return $d < 3600 ? max(1, (int) ($d / 60)) . ' mins' : (int) ($d / 3600) . ' hours'; }
function add_query_arg($args, $url = '') { return $url . '?' . http_build_query($args); }
function admin_url($p = '') { return 'admin/' . $p; }
function home_url($p = '') { return 'https://site.example' . $p; }
function plugins_url($p = '', $f = '') { return 'assets-root/' . $p; }
function wp_parse_url($u, $c = -1) { return parse_url($u, $c); }
function current_user_can($c) { return true; }
function is_rtl() { return false; }
function settings_errors() {}
function wp_create_nonce($a = '') { return 'nonce'; }
function wp_nonce_field() {}
function wp_nonce_url($u) { return $u; }
function disabled($a, $b = true, $e = true) { if ($a == $b) { echo $e ? ' disabled="disabled"' : ''; } }
function checked($a, $b = true, $e = true) { if ($a == $b) { echo $e ? " checked='checked'" : ''; } }
function selected($a, $b = true, $e = true) { if ($a == $b) { echo $e ? " selected='selected'" : ''; } }
function submit_button() {}
function settings_fields() {}
function wp_get_current_user() { return (object) ['ID' => 1, 'user_email' => 'a@b.c', 'display_name' => 'A']; }
function get_current_blog_id() { return 1; }
function get_transient($k) { return false; }
function set_transient($k, $v, $t = 0) { return true; }
function wp_next_scheduled($h) { return time() + 3600; }
function wp_unslash($v) { return $v; }
function add_action() {}
function add_filter() {}
function is_wp_error($v) { return false; }
function __($s, $d = '') { return $s; }
function esc_html__($s, $d = '') { return htmlspecialchars($s); }
function _e($s) { echo $s; }
function wp_get_theme() { return new class { function get($k) { return 'x'; } }; }
function is_multisite() { return false; }
function get_users() { return []; }
function wp_roles() { return new class { public $role_names = []; }; }
function get_role($r) { return null; }
function wp_get_environment_type() { return 'production'; }
function wp_date($f, $t = null) { return date($f, $t ?: time()); }
function number_format_i18n($n) { return number_format($n); }

foreach (['class-signer', 'class-settings', 'class-api', 'class-reporter', 'class-updater', 'class-release-signature', 'class-visibility', 'class-client-mode', 'class-control-plane', 'class-inbound', 'class-products', 'class-blocks', 'class-emitter', 'class-publish-contract', 'class-tickets', 'class-woo', 'class-badge', 'class-beacon'] as $c) {
    if (is_file($root . "includes/$c.php")) {
        require_once $root . "includes/$c.php";
    }
}


(new Bynli_Connect_Settings())->render_page();