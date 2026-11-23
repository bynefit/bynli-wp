<?php
$GLOBALS['dh_scheduled'] = [];
$GLOBALS['dh_next']      = false;

if (!function_exists('wp_next_scheduled')) {
    function wp_next_scheduled($hook)
    {
        return $GLOBALS['dh_next'];
    }
}
if (!function_exists('wp_schedule_event')) {
    function wp_schedule_event($when, $recurrence, $hook)
    {
        $GLOBALS['dh_scheduled'][] = [$recurrence, $hook];
        return true;
    }
}

require_once BYNLI_CONNECT_PLUGIN_DIR . 'includes/class-products.php';
require_once BYNLI_CONNECT_PLUGIN_DIR . 'includes/class-plugin.php';

$GLOBALS['t_actions'] = [];
Bynli_Connect_Plugin::register_daily_hooks();

$daily = array_values(array_filter($GLOBALS['t_actions'], function ($a) {
    return $a['hook'] === 'bynli_connect_daily_report';
}));
$callbacks = array_map(function ($a) {
    return $a['callback'];
}, $daily);

t_true(in_array([Bynli_Connect_Products::class, 'sweep_tombstones'], $callbacks, true), 'daily hooks: the marker sweep is registered on the daily report');
t_true(in_array([Bynli_Connect_Reporter::class, 'send_daily'], $callbacks, true), 'daily hooks: the daily report sender is registered');
foreach ($daily as $a) {
    if ($a['callback'] === [Bynli_Connect_Products::class, 'sweep_tombstones']) {
        t_eq($a['priority'], 5, 'daily hooks: the sweep runs before the report');
        t_eq($a['args'], 0, 'daily hooks: the sweep is handed no arguments');
    }
}
t_true(is_callable([Bynli_Connect_Products::class, 'sweep_tombstones']), 'daily hooks: the registered sweep callback exists');
t_eq($GLOBALS['dh_scheduled'], [['daily', 'bynli_connect_daily_report']], 'daily hooks: an unscheduled daily event is scheduled');

$GLOBALS['dh_scheduled'] = [];
$GLOBALS['dh_next']      = time() + 60;
Bynli_Connect_Plugin::register_daily_hooks();
t_eq($GLOBALS['dh_scheduled'], [], 'daily hooks: an event already scheduled is not scheduled twice');
