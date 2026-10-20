<?php
ini_set('error_log', sys_get_temp_dir() . '/bynli-connect-test-error.log');

define('HOUR_IN_SECONDS', 3600);
define('BYNLI_CONNECT_PLUGIN_FILE', BYNLI_CONNECT_PLUGIN_DIR . 'bynli-connect.php');

if (!class_exists('WP_Error')) {
    class WP_Error
    {
        public $code;
        public function __construct($code = '', $message = '')
        {
            $this->code = $code;
        }
        public function get_error_code()
        {
            return $this->code;
        }
    }
}
foreach (['add_filter', 'add_action'] as $fn) {
    if (!function_exists($fn)) {
        eval('function ' . $fn . '() { return true; }');
    }
}
if (!function_exists('is_wp_error')) {
    function is_wp_error($v)
    {
        return $v instanceof WP_Error;
    }
}
if (!function_exists('__')) {
    function __($s)
    {
        return $s;
    }
}
if (!function_exists('plugin_basename')) {
    function plugin_basename($f)
    {
        return 'bynli-connect/bynli-connect.php';
    }
}
if (!function_exists('get_transient')) {
    function get_transient($k)
    {
        return $GLOBALS['t_transients'][$k] ?? false;
    }
    function set_transient($k, $v)
    {
        $GLOBALS['t_transients'][$k] = $v;
        return true;
    }
    function get_option($k, $d = false)
    {
        return $d;
    }
    function get_current_blog_id()
    {
        return 1;
    }
    function wp_parse_url($u, $c = -1)
    {
        return parse_url($u, $c);
    }
    function trailingslashit($s)
    {
        return rtrim($s, '/') . '/';
    }
    function add_query_arg($a, $u)
    {
        return $u;
    }
    function wp_remote_get()
    {
        return new WP_Error('offline');
    }
    function download_url($u)
    {
        $GLOBALS['t_downloads']++;
        return $GLOBALS['t_download_file'];
    }
}
require_once BYNLI_CONNECT_PLUGIN_DIR . 'includes/class-settings.php';
require_once BYNLI_CONNECT_PLUGIN_DIR . 'includes/class-api.php';
require_once BYNLI_CONNECT_PLUGIN_DIR . 'includes/class-release-signature.php';
require_once BYNLI_CONNECT_PLUGIN_DIR . 'includes/class-updater.php';

$pkg_url = 'https://bynefit.com/releases/wp/bynli-connect-9.9.10.zip';
$bytes   = 'PK-fake-archive-bytes';
$file    = tempnam(sys_get_temp_dir(), 'bcn');
file_put_contents($file, $bytes);
$GLOBALS['t_download_file'] = $file;
$GLOBALS['t_downloads'] = 0;
$extra = ['plugin' => 'bynli-connect/bynli-connect.php'];

$run = function (array $manifest) use ($pkg_url, $file, $bytes, $extra) {
    file_put_contents($file, $bytes);
    $GLOBALS['t_transients'] = ['bynli_connect_update_check' => $manifest];
    $u = new Bynli_Connect_Updater();
    return $u->verify_download(false, $pkg_url, null, $extra);
};
$base = ['version' => '9.9.10', 'download_url' => $pkg_url];
$good = hash('sha256', $bytes);

t_eq($run($base + ['download_sha256' => $good]), $file, 'matching hash proceeds with the verified file');
$r = $run($base + ['download_sha256' => str_repeat('0', 64)]);
t_true($r instanceof WP_Error && $r->code === 'bynli_connect_checksum_mismatch', 'present mismatching hash is refused');
t_true(!file_exists($file), 'mismatching download is deleted');
t_eq($run($base), false, 'absent hash proceeds');
t_eq($run($base + ['download_sha256' => null]), false, 'null hash proceeds');
t_eq($run($base + ['download_sha256' => '']), false, 'empty hash proceeds');
t_eq($run($base + ['download_sha256' => 'nothex']), false, 'unusable hash proceeds');
t_eq((new Bynli_Connect_Updater())->verify_download('/tmp/other-filter.zip', $pkg_url, null, $extra), '/tmp/other-filter.zip', 'unenforced: filter-supplied package passes through');
t_eq((new Bynli_Connect_Updater())->verify_download(false, '', null, $extra), false, 'unenforced: empty package passes through');
t_eq($run($base + ['download_sha256' => strtoupper($good)]), $file, 'uppercase hash matches');
$evil = 'https://evil.example/x.zip';
$GLOBALS['t_transients'] = ['bynli_connect_update_check' => ['version' => '9.9.10', 'download_url' => $evil, 'download_sha256' => $good]];
$r = (new Bynli_Connect_Updater())->verify_download(false, $evil, null, $extra);
t_true($r instanceof WP_Error && $r->code === 'bynli_connect_foreign_package', 'foreign host is refused');

if (!function_exists('sodium_crypto_sign_keypair')) {
    echo "SKIP signature tests: sodium unavailable\n";
    return;
}

$kp  = sodium_crypto_sign_keypair();
$sk  = sodium_crypto_sign_secretkey($kp);
$pk  = base64_encode(sodium_crypto_sign_publickey($kp));
$sign = function (string $version, string $sha) use ($sk) {
    return base64_encode(sodium_crypto_sign_detached(Bynli_Connect_Release_Signature::message($version, $sha), $sk));
};
$m = $base + ['download_sha256' => $good];

t_eq(Bynli_Connect_Release_Signature::status($m, []), 'not_enforced', 'no pinned key means not enforced');
t_eq(Bynli_Connect_Release_Signature::status($m, [$pk]), 'missing', 'unsigned manifest under a pinned key');
t_eq(Bynli_Connect_Release_Signature::status($m + ['download_signature' => $sign('9.9.10', $good)], [$pk]), 'ok', 'valid signature');
t_eq(Bynli_Connect_Release_Signature::status($m + ['download_signature' => $sign('9.9.9', $good)], [$pk]), 'invalid', 'signature for another version');
t_eq(Bynli_Connect_Release_Signature::status($m + ['download_signature' => $sign('9.9.10', str_repeat('a', 64))], [$pk]), 'invalid', 'signature for another hash');
t_eq(Bynli_Connect_Release_Signature::status($m + ['download_signature' => '!!!'], [$pk]), 'invalid', 'garbage signature');
t_eq(Bynli_Connect_Release_Signature::status($m + ['download_signature' => base64_encode('short')], [$pk]), 'invalid', 'short signature');
$other = base64_encode(sodium_crypto_sign_publickey(sodium_crypto_sign_keypair()));
t_eq(Bynli_Connect_Release_Signature::status($m + ['download_signature' => $sign('9.9.10', $good)], [$other]), 'invalid', 'wrong pinned key');
t_eq(Bynli_Connect_Release_Signature::status($m + ['download_signature' => $sign('9.9.10', $good)], [$other, $pk]), 'ok', 'rotation: any pinned key may verify');
t_eq(Bynli_Connect_Release_Signature::status(['version' => '9.9.10', 'download_signature' => $sign('9.9.10', $good)], [$pk]), 'missing', 'signature without a hash');

define('BYNLI_CONNECT_RELEASE_KEYS', [$pk, 'garbage', base64_encode('short'), 7]);
t_true(Bynli_Connect_Release_Signature::enforced(), 'constant pins a key');
t_eq(Bynli_Connect_Release_Signature::keys(), [$pk], 'malformed keys are not counted');
t_eq(Bynli_Connect_Release_Signature::status(['version' => '0.1.0', 'download_sha256' => $good, 'download_signature' => $sign('0.1.0', $good)], [$pk]), 'below_floor', 'validly signed version under the floor is refused');

$r = $run($m);
t_true($r instanceof WP_Error && $r->code === 'bynli_connect_signature_failed', 'enforced: unsigned manifest refused even with a matching hash');
$r = $run($m + ['download_signature' => $sign('9.9.10', str_repeat('b', 64))]);
t_true($r instanceof WP_Error && $r->code === 'bynli_connect_signature_failed', 'enforced: bad signature refused');
t_eq($run($m + ['download_signature' => $sign('9.9.10', $good)]), $file, 'enforced: valid signature and hash proceeds');
$forged = ['version' => '9.9.10', 'download_url' => $pkg_url, 'download_sha256' => hash('sha256', 'evil')];
$r = $run($forged + ['download_signature' => $sign('9.9.10', $good)]);
t_true($r instanceof WP_Error, 'enforced: attacker hash with copied signature refused');

$GLOBALS['t_transients'] = ['bynli_connect_update_check' => $m];
$u = new Bynli_Connect_Updater();
$t = $u->inject_update(new stdClass());
t_true(empty($t->response), 'enforced: unsigned manifest offers no update');
$GLOBALS['t_transients'] = ['bynli_connect_update_check' => $m + ['download_signature' => $sign('9.9.10', $good)]];
$t = (new Bynli_Connect_Updater())->inject_update(new stdClass());
t_true(!empty($t->response['bynli-connect/bynli-connect.php']), 'enforced: signed manifest offers the update');

$GLOBALS['t_transients'] = ['bynli_connect_update_check' => $m + ['download_signature' => $sign('9.9.10', $good)]];
$u = new Bynli_Connect_Updater();
$wpe = function ($v, $name) {
    t_true($v instanceof WP_Error && $v->code === 'bynli_connect_signature_failed', $name);
};
$wpe($u->verify_download('/tmp/other-filter.zip', $pkg_url, null, $extra), 'enforced: package supplied by another filter is refused');
$wpe($u->verify_download(false, '', null, $extra), 'enforced: empty package for our plugin is refused');
$wpe($u->verify_download(false, 'https://bynefit.com/releases/wp/other.zip', null, []), 'enforced: our-host package the manifest does not describe is refused');
file_put_contents($file, $bytes);
$GLOBALS['t_download_file'] = new WP_Error('http_request_failed');
$wpe($u->verify_download(false, $pkg_url, null, $extra), 'enforced: failed download is not handed back to WordPress');
$GLOBALS['t_download_file'] = $file;
t_eq($u->verify_download(false, 'https://elsewhere.example/x.zip', null, []), false, 'enforced: unrelated third-party package is left alone');
t_eq($u->verify_download(false, 'https://elsewhere.example/x.zip', null, ['plugin' => 'other/other.php']), false, 'enforced: other plugin is left alone');
