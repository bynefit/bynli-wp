<?php
ini_set('error_log', sys_get_temp_dir() . '/bynli-connect-test-error.log');

require_once BYNLI_CONNECT_PLUGIN_DIR . 'includes/class-products.php';

$GLOBALS['ts_options'] = [];

$GLOBALS['ts_cache_cleared'] = [];
if (!function_exists('wp_cache_delete')) {
    function wp_cache_delete($key, $group = '')
    {
        $GLOBALS['ts_cache_cleared'][] = $key . '@' . $group;
        return true;
    }
}

class Ts_Fake_Wpdb
{
    public $options    = 'wp_options';
    public $last_error = '';
    public $throw      = false;
    public $null       = false;
    public $queries    = 0;
    public $race       = '';
    public $delete_fail = false;

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
        $this->queries++;
        if ($this->throw) {
            throw new RuntimeException('db down');
        }
        if ($this->null) {
            $this->last_error = 'table locked';
            return null;
        }
        $prefix = rtrim(str_replace('\\_', '_', $q['args'][0]), '%');
        $cursor = $q['args'][1];
        $out    = [];
        foreach ($GLOBALS['ts_options'] as $name => $row) {
            if (strpos($name, $prefix) === 0 && $row['id'] > $cursor) {
                $out[] = (object) ['option_id' => $row['id'], 'option_name' => $name, 'option_value' => (string) $row['value']];
            }
        }
        usort($out, function ($a, $b) {
            return $a->option_id <=> $b->option_id;
        });
        $page = array_slice($out, 0, $q['args'][2]);
        if ($this->race !== '' && isset($GLOBALS['ts_options'][$this->race])) {
            $GLOBALS['ts_options'][$this->race]['value'] = 1800000000 + 1;
        }
        return $page;
    }

    public function query($q)
    {
        if (strpos($q['sql'], 'DELETE FROM') !== 0) {
            return false;
        }
        if ($this->delete_fail) {
            return false;
        }
        foreach ($GLOBALS['ts_options'] as $name => $row) {
            if ($row['id'] === $q['args'][0] && (string) $row['value'] === $q['args'][1]) {
                unset($GLOBALS['ts_options'][$name]);
                return 1;
            }
        }
        return 0;
    }
}

function ts_load(array $values): void
{
    $GLOBALS['ts_options'] = [];
    $id = 1;
    foreach ($values as $name => $value) {
        $GLOBALS['ts_options'][$name] = ['id' => $id++, 'value' => $value];
    }
}

function ts_has(string $name): bool
{
    return isset($GLOBALS['ts_options'][$name]);
}

$GLOBALS['wpdb'] = new Ts_Fake_Wpdb();
$now = 1800000000;
$ttl = Bynli_Connect_Products::TOMB_TTL;

ts_load([
    'bynefit_product_removed_1'  => $now - $ttl - 10,
    'bynefit_product_removed_2'  => $now - 60,
    'bynefit_product_removed_3'  => $now - $ttl + 10,
    'bynefit_product_removed_x'  => 1,
    'bynefit_product_removed_44' => 0,
    'bynefit_product_removed_55' => $now + $ttl + 10,
    'bynefit_product_removed_66' => $now + 60,
    'blogname'                   => 'Site',
]);

$swept = Bynli_Connect_Products::sweep_tombstones($now);

t_eq($swept, 3, 'tombstone sweep: counts expired markers');
t_true(!ts_has('bynefit_product_removed_1'), 'tombstone sweep: expired marker removed');
t_true(ts_has('bynefit_product_removed_2'), 'tombstone sweep: fresh marker kept');
t_true(ts_has('bynefit_product_removed_3'), 'tombstone sweep: marker inside the window kept');
t_true(ts_has('bynefit_product_removed_x'), 'tombstone sweep: non-numeric suffix untouched');
t_true(!ts_has('bynefit_product_removed_44'), 'tombstone sweep: zero-stamped marker removed');
t_true(!ts_has('bynefit_product_removed_55'), 'tombstone sweep: far-future marker removed');
t_true(ts_has('bynefit_product_removed_66'), 'tombstone sweep: slightly future marker kept');
t_true(ts_has('blogname'), 'tombstone sweep: unrelated options untouched');

t_true(in_array('bynefit_product_removed_1@options', $GLOBALS['ts_cache_cleared'], true), 'tombstone sweep: a removed marker is evicted from the object cache');
t_true(!in_array('bynefit_product_removed_2@options', $GLOBALS['ts_cache_cleared'], true), 'tombstone sweep: a kept marker is not evicted');

ts_load([
    'bynefit_product_removed_1' => $now - $ttl - 10,
    'bynefit_product_removed_2' => $now - $ttl - 10,
]);
$GLOBALS['wpdb']->race = 'bynefit_product_removed_1';
$swept = Bynli_Connect_Products::sweep_tombstones($now);
$GLOBALS['wpdb']->race = '';
t_eq($swept, 1, 'tombstone sweep: a marker rewritten after it was read is not counted as swept');
t_true(ts_has('bynefit_product_removed_1'), 'tombstone sweep: a marker rewritten after it was read survives');
t_eq($GLOBALS['ts_options']['bynefit_product_removed_1']['value'], 1800000001, 'tombstone sweep: the newer marker value is intact');
t_true(!ts_has('bynefit_product_removed_2'), 'tombstone sweep: an unchanged expired marker beside it is still removed');

@unlink(sys_get_temp_dir() . '/bynli-connect-test-error.log');
ts_load(['bynefit_product_removed_1' => 1]);
$GLOBALS['wpdb']->delete_fail = true;
t_eq(Bynli_Connect_Products::sweep_tombstones($now), 0, 'tombstone sweep: a failed delete stops the run and counts nothing');
t_true(strpos((string) @file_get_contents(sys_get_temp_dir() . '/bynli-connect-test-error.log'), 'tombstone sweep delete failed') !== false, 'tombstone sweep: a failed delete is logged');
$GLOBALS['wpdb']->delete_fail = false;

$batch = Bynli_Connect_Products::TOMB_BATCH;
$many  = [];
for ($i = 1; $i <= $batch + 200; $i++) {
    $many['bynefit_product_removed_' . $i] = $i <= $batch ? $now : $now - $ttl - 5;
}
ts_load($many);
$GLOBALS['wpdb']->queries = 0;
$swept = Bynli_Connect_Products::sweep_tombstones($now);
t_eq($swept, 200, 'tombstone sweep: expired markers behind a full batch of fresh ones are reached');
t_true($GLOBALS['wpdb']->queries >= 2, 'tombstone sweep: pages through the markers');
t_eq(count($GLOBALS['ts_options']), $batch, 'tombstone sweep: only the fresh markers remain');

$all = [];
for ($i = 1; $i <= $batch * (Bynli_Connect_Products::TOMB_PASSES + 2); $i++) {
    $all['bynefit_product_removed_' . $i] = 1;
}
ts_load($all);
$swept = Bynli_Connect_Products::sweep_tombstones($now);
t_eq($swept, $batch * Bynli_Connect_Products::TOMB_PASSES, 'tombstone sweep: one run is bounded');

ts_load($all);
$swept = Bynli_Connect_Products::sweep_tombstones($now, 0);
t_eq($swept, $batch, 'tombstone sweep: a spent time budget stops the run after the first batch');
t_true(count($GLOBALS['ts_options']) > 0, 'tombstone sweep: markers beyond the budget wait for the next run');
$swept = Bynli_Connect_Products::sweep_tombstones($now, 3600);
t_eq($swept, $batch * Bynli_Connect_Products::TOMB_PASSES, 'tombstone sweep: a generous budget still stops at the pass cap');

@unlink(sys_get_temp_dir() . '/bynli-connect-test-error.log');
ts_load(['bynefit_product_removed_1' => 1]);
$GLOBALS['wpdb']->throw = true;
t_eq(Bynli_Connect_Products::sweep_tombstones($now), 0, 'tombstone sweep: a thrown database error is swallowed');
t_true(strpos((string) @file_get_contents(sys_get_temp_dir() . '/bynli-connect-test-error.log'), 'tombstone sweep failed: db down') !== false, 'tombstone sweep: a thrown database error is logged');
$GLOBALS['wpdb']->throw = false;

@unlink(sys_get_temp_dir() . '/bynli-connect-test-error.log');
$GLOBALS['wpdb']->null = true;
t_eq(Bynli_Connect_Products::sweep_tombstones($now), 0, 'tombstone sweep: a failed query sweeps nothing');
t_true(strpos((string) @file_get_contents(sys_get_temp_dir() . '/bynli-connect-test-error.log'), 'tombstone sweep query failed') !== false, 'tombstone sweep: a failed query is logged');
t_true(ts_has('bynefit_product_removed_1'), 'tombstone sweep: a failed query deletes nothing');
$GLOBALS['wpdb']->null       = false;
$GLOBALS['wpdb']->last_error = '';

ts_load(['bynefit_product_removed_9' => 1]);
t_eq(Bynli_Connect_Products::sweep_tombstones(), 1, 'tombstone sweep: defaults to the current time when the cron passes no argument');

$GLOBALS['wpdb'] = null;
t_eq(Bynli_Connect_Products::sweep_tombstones($now), 0, 'tombstone sweep: no database handle is a no-op');
unset($GLOBALS['wpdb']);
