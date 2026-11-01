<?php
define('ABSPATH', dirname(__DIR__, 2) . '/');
define('BYNLI_CONNECT_VERSION', '9.9.9');
define('BYNLI_CONNECT_PLUGIN_FILE', dirname(__DIR__, 2) . '/bynli-connect.php');
define('BYNLI_CONNECT_PLUGIN_DIR', dirname(__DIR__, 2) . '/');
$GLOBALS['registered'] = [];

function add_action()
{
}
function plugins_url($p = '', $f = '')
{
    return 'https://site.example/' . $p;
}
function wp_register_style()
{
}
function wp_register_script()
{
}
function register_block_type($path)
{
    $GLOBALS['registered'][] = basename($path);
}

require BYNLI_CONNECT_PLUGIN_DIR . 'includes/class-blocks.php';
(new Bynli_Connect_Blocks())->register_blocks();

$on_disk = [];
foreach (glob(BYNLI_CONNECT_PLUGIN_DIR . 'blocks/*/block.json') as $json) {
    $on_disk[] = basename(dirname($json));
}
sort($on_disk);
$registered = $GLOBALS['registered'];
sort($registered);
echo json_encode(['registered' => $registered, 'on_disk' => $on_disk]);
