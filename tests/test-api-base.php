<?php
require_once BYNLI_CONNECT_PLUGIN_DIR . 'includes/class-settings.php';
require_once BYNLI_CONNECT_PLUGIN_DIR . 'includes/class-api.php';

$S = 'Bynli_Connect_Settings';
$default = BYNLI_CONNECT_DEFAULT_API_BASE;

foreach ([
    ['https://bynefit.com', false, 'https://bynefit.com'],
    ['https://bynefit.com/', false, 'https://bynefit.com'],
    ['HTTPS://Bynefit.COM.', false, 'https://bynefit.com'],
    ['https://staging.example.org:8443/base/', false, 'https://staging.example.org:8443/base'],
    ['', false, ''],
    ['http://bynefit.com', false, ''],
    ['http://bynefit.com', true, ''],
    ['http://evil.test.example.com', true, ''],
    ['bynefit.com', false, ''],
    ['bynefit.com', true, ''],
    ['//bynefit.com', true, ''],
    ['localhost:8080', true, ''],
    ['javascript:alert(1)', true, ''],
    ['https://user:pw@bynefit.com', false, ''],
    ['https://bynefit.com?x=1', false, ''],
    ['https://bynefit.com#x', false, ''],
    ['https://by nefit.com', false, ''],
    ['https://bynefit.com\\@evil.com', false, ''],
    ['https://', false, ''],
    ['https://bynefit.com:99999', false, ''],
    ['http://localhost:8080', false, ''],
    ['http://localhost:8080', true, 'http://localhost:8080'],
    ['http://127.0.0.1:8000', true, 'http://127.0.0.1:8000'],
    ['http://[::1]:8000', true, 'http://[::1]:8000'],
    ['http://dev.bynefit.test', true, 'http://dev.bynefit.test'],
    ['http://site.localhost', true, 'http://site.localhost'],
    ['http://evil.test', true, 'http://evil.test'],
    ['http://notlocalhost', true, ''],
    ['http://127.0.0.1.evil.com', true, ''],
    ['https://localhost', false, 'https://localhost'],
] as $i => [$in, $dev, $want]) {
    t_eq($S::parse_api_base($in, $dev)['url'], $want, 'parse_api_base #' . $i . ' ' . $in);
}

t_eq($S::parse_api_base('bynefit.com', true)['error'] !== '', true, 'schemeless reports an error');
t_eq($S::parse_api_base('', true)['error'], '', 'empty is not an error');

t_eq($S::resolve_api_base('', false), $default, 'empty falls to default');
t_eq($S::resolve_api_base('bynefit.com', true), $default, 'schemeless constant does not pin to plaintext');
t_eq($S::resolve_api_base('http://bynefit.com', true), $default, 'http public host from constant falls back');
t_eq($S::resolve_api_base('http://localhost:8080', true), 'http://localhost:8080', 'dev constant honoured');
t_eq($S::resolve_api_base('http://localhost:8080', false), $default, 'dev http not honoured from option');
t_eq($S::resolve_api_base('https://bynli.com.', false), $default, 'legacy host with trailing dot blocked');
t_eq($S::resolve_api_base('https://www.bynli.com', true), $default, 'legacy www host blocked');

t_eq(Bynli_Connect_Api::user_agent(), 'Bynli-Connect/9.9.9 WP/6.6', 'single user agent token');
$ua_sites = [];
foreach (glob(BYNLI_CONNECT_PLUGIN_DIR . 'includes/*.php') as $f) {
    $src = file_get_contents($f);
    if (preg_match("/Bynli-?Connect\\/['\"]/", $src) && basename($f) !== 'class-api.php') {
        $ua_sites[] = basename($f);
    }
}
t_eq($ua_sites, [], 'no second User-Agent literal outside Bynli_Connect_Api');
