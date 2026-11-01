<?php
define('ABSPATH', dirname(__DIR__, 2) . '/');
define('WP_UNINSTALL_PLUGIN', 'bynli-connect/bynli-connect.php');
$GLOBALS['deleted'] = [];

function delete_option($name)
{
    $GLOBALS['deleted'][] = $name;
    return true;
}
function wp_unschedule_hook($h)
{
}
function delete_transient($k)
{
}
function delete_post_meta_by_key($k)
{
}
function get_role($r)
{
    return null;
}

require dirname(__DIR__, 2) . '/uninstall.php';
echo json_encode($GLOBALS['deleted']);
