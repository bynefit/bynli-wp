<?php
ini_set('error_log', sys_get_temp_dir() . '/bynli-connect-test-error.log');

$GLOBALS['un_options'] = [
    'bynefit_product_removed_1' => 5,
    'bynefit_product_removed_2' => 6,
    'blogname'                  => 'Site',
];

foreach (['delete_option', 'wp_unschedule_hook', 'delete_transient', 'delete_post_meta_by_key', 'get_role'] as $fn) {
    if (!function_exists($fn)) {
        eval('function ' . $fn . '() { return null; }');
    }
}

class Un_Fake_Wpdb
{
    public $options = 'wp_options';

    public function esc_like($s)
    {
        return addcslashes($s, '_%\\');
    }

    public function prepare($sql, ...$args)
    {
        return ['sql' => $sql, 'like' => $args[0]];
    }

    public function query($q)
    {
        if (strpos($q['sql'], 'DELETE FROM') !== 0) {
            return false;
        }
        $prefix = rtrim(str_replace('\\_', '_', $q['like']), '%');
        foreach (array_keys($GLOBALS['un_options']) as $name) {
            if (strpos($name, $prefix) === 0) {
                unset($GLOBALS['un_options'][$name]);
            }
        }
        return true;
    }
}

$GLOBALS['wpdb'] = new Un_Fake_Wpdb();
define('WP_UNINSTALL_PLUGIN', 'bynli-connect/bynli-connect.php');
require dirname(__DIR__) . '/uninstall.php';

t_true(!isset($GLOBALS['un_options']['bynefit_product_removed_1']) && !isset($GLOBALS['un_options']['bynefit_product_removed_2']), 'uninstall: product removal markers are deleted');
t_true(isset($GLOBALS['un_options']['blogname']), 'uninstall: unrelated options are left alone');
unset($GLOBALS['wpdb']);
