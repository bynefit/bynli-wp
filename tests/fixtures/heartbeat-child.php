<?php
define('ABSPATH', dirname(__DIR__) . '/');
define('BYNLI_CONNECT_VERSION', '9.9.9');
define('BYNLI_CONNECT_DEFAULT_API_BASE', 'https://bynefit.com');
define('BYNLI_CONNECT_PLUGIN_DIR', dirname(__DIR__, 2) . '/');
define('DAY_IN_SECONDS', 86400);
ini_set('error_log', sys_get_temp_dir() . '/bynli-connect-test-error.log');

$scenario = $argv[1] ?? '';
$opts = [
    'bynli_connect_api_key' => 'bynli_sh_' . str_repeat('a', 32),
];
if ($scenario === 'after-success') {
    $opts['bynli_connect_report_history'] = [
        ['at' => 1, 'kind' => 'heartbeat', 'ok' => false, 'status' => 401],
        ['at' => 2, 'kind' => 'daily', 'ok' => true, 'status' => 200],
    ];
} elseif ($scenario === 'long-failing') {
    $opts['bynli_connect_verified_at'] = 1;
    $opts['bynli_connect_report_history'] = array_fill(0, 14, ['at' => 5, 'kind' => 'daily', 'ok' => false, 'status' => 401]);
} elseif ($scenario === 'after-failures') {
    $opts['bynli_connect_report_history'] = [
        ['at' => 1, 'kind' => 'heartbeat', 'ok' => false, 'status' => 401],
    ];
}
$GLOBALS['opts'] = $opts;
$GLOBALS['server_ok'] = $scenario !== 'server-down';
$GLOBALS['can'] = $scenario !== 'forbidden';
$GLOBALS['nonce_ok'] = $scenario !== 'bad-nonce';

function get_option($k, $d = false)
{
    return array_key_exists($k, $GLOBALS['opts']) ? $GLOBALS['opts'][$k] : $d;
}
function update_option($k, $v, $autoload = null)
{
    $GLOBALS['opts'][$k] = $v;
    return true;
}
function add_action()
{
}
function current_user_can($cap)
{
    return $GLOBALS['can'];
}
function check_ajax_referer($a, $b = false, $c = true)
{
    return $GLOBALS['nonce_ok'];
}
function get_bloginfo($k = '')
{
    return '6.6';
}
function is_wp_error($v)
{
    return false;
}
function wp_json_encode($v)
{
    return json_encode($v);
}
function human_time_diff($a, $b = 0)
{
    return '1 min';
}
function wp_remote_post($url, $args)
{
    $GLOBALS['posted'][] = $url;
    return ['code' => $GLOBALS['server_ok'] ? 200 : 401, 'body' => json_encode($GLOBALS['server_ok'] ? ['ok' => true] : ['ok' => false, 'error' => 'unauthorized'])];
}
function wp_remote_retrieve_response_code($r)
{
    return $r['code'];
}
function wp_remote_retrieve_body($r)
{
    return $r['body'];
}
function wp_send_json_success($data = null, $status = null)
{
    $payload = ['success' => true, 'data' => $data];
    if (!empty($GLOBALS['multi'])) {
        throw new RuntimeException(json_encode($payload));
    }
    echo json_encode($payload);
    exit(0);
}
function wp_send_json_error($data = null, $status = null)
{
    $payload = ['success' => false, 'data' => $data, 'http' => $status];
    if (!empty($GLOBALS['multi'])) {
        throw new RuntimeException(json_encode($payload));
    }
    echo json_encode($payload);
    exit(0);
}

require BYNLI_CONNECT_PLUGIN_DIR . 'includes/class-signer.php';
require BYNLI_CONNECT_PLUGIN_DIR . 'includes/class-settings.php';
require BYNLI_CONNECT_PLUGIN_DIR . 'includes/class-api.php';
require BYNLI_CONNECT_PLUGIN_DIR . 'includes/class-reporter.php';

function check_admin_referer($a)
{
    return true;
}
function wp_die($m = '', $c = 0)
{
    echo json_encode(['died' => $c]);
    exit(0);
}
function admin_url($p = '')
{
    return 'https://site.example/wp-admin/' . $p;
}
function add_query_arg($args, $url)
{
    return $url . '?' . http_build_query($args);
}
function wp_safe_redirect($url)
{
    echo json_encode(['redirect' => $url]);
}

$handler = new Bynli_Connect_Settings();
if (($argv[2] ?? '') === 'redirect') {
    $handler->handle_test();
} elseif (($argv[2] ?? '') === 'twice') {
    $GLOBALS['multi'] = true;
    $runs = [];
    for ($i = 0; $i < 2; $i++) {
        try {
            $handler->handle_ajax_heartbeat();
        } catch (RuntimeException $e) {
            $runs[] = json_decode($e->getMessage(), true);
        }
        unset($GLOBALS['opts']['bynli_connect_report_history'], $GLOBALS['opts']['bynli_connect_last_report']);
    }
    echo json_encode(['runs' => $runs, 'verified' => isset($GLOBALS['opts']['bynli_connect_verified_at'])]);
} else {
    $handler->handle_ajax_heartbeat();
}
