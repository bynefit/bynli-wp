<?php
ini_set('error_log', sys_get_temp_dir() . '/bynli-connect-test-error.log');

if (!class_exists('WP_Error')) {
    class WP_Error
    {
        public $code;
        public $data;
        public function __construct($code = '', $message = '', $data = null)
        {
            $this->code = $code;
            $this->data = $data;
        }
        public function get_error_message()
        {
            return $this->code;
        }
        public function get_error_code()
        {
            return $this->code;
        }
    }
}
if (!class_exists('WP_REST_Request')) {
    class WP_REST_Request
    {
        private $body;
        public function __construct($body = '')
        {
            $this->body = $body;
        }
        public function get_body()
        {
            return $this->body;
        }
    }
}
if (!class_exists('WP_REST_Response')) {
    class WP_REST_Response
    {
        public $data;
        public $status;
        public function __construct($data = null, $status = 200)
        {
            $this->data   = $data;
            $this->status = $status;
        }
    }
}
if (!function_exists('is_wp_error')) {
    function is_wp_error($v)
    {
        return $v instanceof WP_Error;
    }
}
foreach (['add_filter', 'add_action'] as $fn) {
    if (!function_exists($fn)) {
        eval('function ' . $fn . '() { return true; }');
    }
}

$GLOBALS['pr_pages']   = [];
$GLOBALS['pr_updates'] = [];
$GLOBALS['pr_actions'] = [];
$GLOBALS['pr_fail']    = false;

if (!function_exists('get_posts')) {
    function get_posts($args)
    {
        $out = [];
        foreach ($GLOBALS['pr_pages'] as $id => $p) {
            if (($p['meta'] ?? null) === ($args['meta_value'] ?? null) && in_array($p['status'], (array) $args['post_status'], true)) {
                $out[] = $id;
            }
        }
        return $out;
    }
}
if (!function_exists('wp_update_post')) {
    function wp_update_post($arr, $wp_error = false)
    {
        if ($GLOBALS['pr_fail']) {
            return new WP_Error('db_update_error', 'boom');
        }
        $GLOBALS['pr_updates'][] = $arr;
        $GLOBALS['pr_pages'][$arr['ID']]['status'] = $arr['post_status'];
        return $arr['ID'];
    }
}
if (!function_exists('do_action')) {
    function do_action($name, ...$args)
    {
        $GLOBALS['pr_actions'][] = [$name, $args];
    }
}

require_once BYNLI_CONNECT_PLUGIN_DIR . 'includes/class-control-plane.php';

$cp = new Bynli_Connect_Control_Plane();
$call = function ($body) use ($cp) {
    return $cp->remove_page(new WP_REST_Request(is_string($body) ? $body : json_encode($body)));
};

$GLOBALS['pr_pages'] = [
    10 => ['meta' => 'shop-12', 'status' => 'publish'],
    11 => ['meta' => 'shop-13', 'status' => 'publish'],
    12 => ['meta' => 'shop-products', 'status' => 'publish'],
    13 => ['meta' => 'home', 'status' => 'publish'],
    14 => ['meta' => 'shop-99', 'status' => 'draft'],
];

$bad = $call('not json');
t_true($bad instanceof WP_Error && $bad->code === 'invalid_json' && $bad->data['status'] === 400, 'page_remove: a body that is not JSON is a 400');

foreach (['home', 'shop-products', 'shop-', 'shop-12a', "shop-12
", 'shop-12/../x', '../shop-12', 'SHOP-12', ' shop-12', ''] as $i => $slug) {
    $r = $call(['slug' => $slug]);
    t_true($r instanceof WP_Error && $r->code === 'invalid_slug' && $r->data['status'] === 400, 'page_remove: slug #' . $i . ' "' . $slug . '" is refused');
}
foreach ([['slug' => 12], ['slug' => ['shop-12']], ['slug' => null], []] as $i => $body) {
    $r = $call($body);
    t_true($r instanceof WP_Error && $r->code === 'invalid_slug', 'page_remove: non-string slug #' . $i . ' is refused');
}
t_eq($GLOBALS['pr_updates'], [], 'page_remove: a refused slug changes nothing');

$r = $call(['slug' => 'shop-12']);
t_true($r instanceof WP_REST_Response && $r->status === 200, 'page_remove: a product page is removed with a 200');
t_eq($r->data, ['ok' => true, 'removed' => 1], 'page_remove: it reports one page removed');
t_eq($GLOBALS['pr_pages'][10]['status'], 'draft', 'page_remove: the page goes to draft, not deleted');
t_eq($GLOBALS['pr_pages'][11]['status'], 'publish', 'page_remove: another product page is untouched');
t_eq($GLOBALS['pr_pages'][12]['status'], 'publish', 'page_remove: the products index page is untouched');
t_eq($GLOBALS['pr_pages'][13]['status'], 'publish', 'page_remove: the home page is untouched');
t_eq($GLOBALS['pr_updates'], [['ID' => 10, 'post_status' => 'draft']], 'page_remove: exactly one update, status only');
t_eq(count($GLOBALS['pr_actions']), 1, 'page_remove: the removal is announced once');
t_eq($GLOBALS['pr_actions'][0][1], ['shop-12', 1], 'page_remove: the announcement names the slug and count');

$again = $call(['slug' => 'shop-12']);
t_eq($again->data, ['ok' => true, 'removed' => 0], 'page_remove: removing again is a no-op that still succeeds');
t_eq(count($GLOBALS['pr_updates']), 1, 'page_remove: the repeat wrote nothing');
t_eq(count($GLOBALS['pr_actions']), 1, 'page_remove: the repeat announced nothing');

$offline = $call(['slug' => 'shop-99']);
t_eq($offline->data, ['ok' => true, 'removed' => 0], 'page_remove: a page already offline is a no-op');

$none = $call(['slug' => 'shop-404']);
t_eq($none->data, ['ok' => true, 'removed' => 0], 'page_remove: a slug with no page is a no-op');

$GLOBALS['pr_pages'][20] = ['meta' => 'shop-7', 'status' => 'publish'];
$GLOBALS['pr_pages'][21] = ['meta' => 'shop-7', 'status' => 'private'];
$dup = $call(['slug' => 'shop-7']);
t_eq($dup->data, ['ok' => true, 'removed' => 2], 'page_remove: a duplicated page for the slug is taken offline too');

$GLOBALS['pr_pages'][30] = ['meta' => 'shop-8', 'status' => 'publish'];
$GLOBALS['pr_fail'] = true;
$failed = $call(['slug' => 'shop-8']);
t_true($failed instanceof WP_Error && $failed->code === 'write_failed' && $failed->data['status'] === 500, 'page_remove: a failed write is a 500, not a false success');
t_eq($GLOBALS['pr_pages'][30]['status'], 'publish', 'page_remove: a failed write leaves the page as it was');
$GLOBALS['pr_fail'] = false;
