<?php
define('ABSPATH', __DIR__ . '/');
define('BYNLI_CONNECT_VERSION', '9.9.9');
define('BYNLI_CONNECT_DEFAULT_API_BASE', 'https://bynefit.com');
define('BYNLI_CONNECT_PLUGIN_DIR', dirname(__DIR__) . '/');

$GLOBALS['t_pass'] = 0;
$GLOBALS['t_fail'] = 0;

function t_eq($actual, $expected, string $name): void
{
    if ($actual === $expected) {
        $GLOBALS['t_pass']++;
        return;
    }
    $GLOBALS['t_fail']++;
    echo 'FAIL ' . $name . ' expected ' . var_export($expected, true) . ' got ' . var_export($actual, true) . "\n";
}

function t_true($cond, string $name): void
{
    t_eq((bool) $cond, true, $name);
}

if (!function_exists('get_bloginfo')) {
    function get_bloginfo($k = '')
    {
        return '6.6';
    }
}

$only = $argv[1] ?? '';
foreach (glob(__DIR__ . '/test-*.php') as $file) {
    if ($only !== '' && strpos($file, $only) === false) {
        continue;
    }
    require $file;
}

echo $GLOBALS['t_pass'] . ' passed, ' . $GLOBALS['t_fail'] . " failed\n";
exit($GLOBALS['t_fail'] === 0 ? 0 : 1);
