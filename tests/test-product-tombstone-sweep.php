<?php
ini_set('error_log', sys_get_temp_dir() . '/bynli-connect-test-error.log');

require_once BYNLI_CONNECT_PLUGIN_DIR . 'includes/class-products.php';

$GLOBALS['ts_options'] = [];

if (!function_exists('delete_option')) {
    function delete_option($name)
    {
        unset($GLOBALS['ts_options'][$name]);
        return true;
    }
}

class Ts_Fake_Wpdb
{
    public $options = 'wp_options';
    public $throw   = false;

    public function esc_like($s)
    {
        return addcslashes($s, '_%\\');
    }

    public function prepare($sql, ...$args)
    {
        return ['sql' => $sql, 'args' => $args];
    }

    public function get_results($q)
    {
        if ($this->throw) {
            throw new RuntimeException('db down');
        }
        $prefix = rtrim(str_replace('\\_', '_', $q['args'][0]), '%');
        $out    = [];
        foreach ($GLOBALS['ts_options'] as $name => $value) {
            if (strpos($name, $prefix) === 0) {
                $out[] = (object) ['option_name' => $name, 'option_value' => (string) $value];
            }
        }
        return array_slice($out, 0, $q['args'][1]);
    }
}

$GLOBALS['wpdb'] = new Ts_Fake_Wpdb();
$now = 1800000000;
$ttl = Bynli_Connect_Products::TOMB_TTL;

$GLOBALS['ts_options'] = [
    'bynefit_product_removed_1'  => $now - $ttl - 10,
    'bynefit_product_removed_2'  => $now - 60,
    'bynefit_product_removed_3'  => $now - $ttl + 10,
    'bynefit_product_removed_x'  => 1,
    'bynefit_product_removed_44' => 0,
    'blogname'                   => 'Site',
];

$swept = Bynli_Connect_Products::sweep_tombstones($now);

t_eq($swept, 2, 'tombstone sweep: counts old markers');
t_true(!isset($GLOBALS['ts_options']['bynefit_product_removed_1']), 'tombstone sweep: expired marker removed');
t_true(isset($GLOBALS['ts_options']['bynefit_product_removed_2']), 'tombstone sweep: fresh marker kept');
t_true(isset($GLOBALS['ts_options']['bynefit_product_removed_3']), 'tombstone sweep: marker inside the window kept');
t_true(isset($GLOBALS['ts_options']['bynefit_product_removed_x']), 'tombstone sweep: non-numeric suffix untouched');
t_true(!isset($GLOBALS['ts_options']['bynefit_product_removed_44']), 'tombstone sweep: zero-stamped marker removed');
t_true(isset($GLOBALS['ts_options']['blogname']), 'tombstone sweep: unrelated options untouched');

$GLOBALS['wpdb']->throw = true;
t_eq(Bynli_Connect_Products::sweep_tombstones($now), 0, 'tombstone sweep: database failure is swallowed and logged');
$GLOBALS['wpdb']->throw = false;

$GLOBALS['wpdb'] = null;
t_eq(Bynli_Connect_Products::sweep_tombstones($now), 0, 'tombstone sweep: no database handle is a no-op');
