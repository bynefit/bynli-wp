<?php
if (!defined('ABSPATH')) { exit; }

/**
 * WooCommerce payment rail — plugin side (#2164). Registers the off-site
 * Bynefit gateway and wires the two confirmation paths that flip a WC order to
 * paid:
 *
 *   1. Nudge — Bynefit POSTs to /?wc-api=bynefit_connect when an order changes.
 *      The nudge is UNTRUSTED (the server can't HMAC-sign it — it holds only
 *      sha256(key)); on receipt we confirm via the SIGNED poll before completing.
 *   2. Thank-you page — the buyer lands back on the WC return_url; we reconcile
 *      the same way in case the nudge was dropped.
 *
 * Both call reconcile_order(), which polls GET /api/site-host/woo/checkout/{ref}
 * (signed with our own key) and only completes the order on status=paid.
 * payment_complete() is idempotent, so multiple paths are safe.
 *
 * The gateway class extends WC_Payment_Gateway, which only exists once
 * WooCommerce is loaded — so it's required lazily on plugins_loaded:11.
 */
class Bynli_Connect_Woo {

    const REF_META = '_bynefit_checkout_ref';

    public function __construct() {
        add_action('plugins_loaded', [$this, 'load_gateway_class'], 11);
        add_filter('woocommerce_payment_gateways', [$this, 'register_gateway']);
        add_action('woocommerce_api_bynefit_connect', [$this, 'handle_nudge']);
        add_action('woocommerce_thankyou', [$this, 'reconcile_on_thankyou'], 10, 1);
    }

    public function load_gateway_class(): void {
        if (!class_exists('WC_Payment_Gateway')) return; // WooCommerce not active
        require_once BYNLI_CONNECT_PLUGIN_DIR . 'includes/class-wc-gateway-bynefit.php';
    }

    public function register_gateway(array $gateways): array {
        if (class_exists('WC_Gateway_Bynefit')) {
            $gateways[] = 'WC_Gateway_Bynefit';
        }
        return $gateways;
    }

    /** Inbound nudge from Bynefit — a hint to go verify, never trusted on its own. */
    public function handle_nudge(): void {
        $raw  = file_get_contents('php://input');
        $data = json_decode((string)$raw, true);
        $ref  = is_array($data) ? (string)($data['checkout_ref'] ?? '') : '';
        $key  = is_array($data) ? (string)($data['order_key'] ?? '')   : '';

        if (!preg_match('/^wco_[a-f0-9]{32}$/', $ref)) {
            status_header(400);
            echo wp_json_encode(['ok' => false]);
            exit;
        }
        $order = $this->find_order($ref, $key);
        if ($order) {
            $this->reconcile_order($order, $ref);
        }
        // Always 200 — an unknown ref is not an error (could be another site).
        status_header(200);
        echo wp_json_encode(['ok' => true]);
        exit;
    }

    public function reconcile_on_thankyou($order_id): void {
        $order = wc_get_order($order_id);
        if (!$order || $order->get_payment_method() !== 'bynefit' || $order->is_paid()) return;
        $ref = (string)$order->get_meta(self::REF_META);
        if (preg_match('/^wco_[a-f0-9]{32}$/', $ref)) {
            $this->reconcile_order($order, $ref);
        }
    }

    /**
     * Confirm payment via the signed poll and complete the WC order. The poll is
     * authenticated with our own site-host key, so its verdict is trustworthy
     * (unlike the nudge). payment_complete() is idempotent.
     */
    public function reconcile_order(\WC_Order $order, string $ref): void {
        $res = Bynli_Connect_Api::get('/api/site-host/woo/checkout/' . $ref);
        if (empty($res['ok']) || !isset($res['data']['status'])) return;

        $status = (string)$res['data']['status'];
        if ($status === 'paid') {
            if (!$order->is_paid()) {
                $order->payment_complete((string)($res['data']['capture_id'] ?? ''));
                $order->add_order_note(__('Payment confirmed by Bynefit.', 'bynli-connect'));
            }
        } elseif (in_array($status, ['refunded', 'partially_refunded'], true)) {
            // Full refund handling arrives in a later phase; note it for now.
            $order->add_order_note(sprintf(__('Bynefit reports this order as %s.', 'bynli-connect'), $status));
        }
    }

    private function find_order(string $ref, string $order_key): ?\WC_Order
    {
        if ($order_key !== '' && function_exists('wc_get_order_id_by_order_key')) {
            $oid = wc_get_order_id_by_order_key($order_key);
            if ($oid) {
                $o = wc_get_order($oid);
                if ($o instanceof \WC_Order && (string)$o->get_meta(self::REF_META) === $ref) {
                    return $o;
                }
            }
        }
        // Fallback: look the ref up by meta.
        $orders = wc_get_orders([
            'limit'      => 1,
            'meta_key'   => self::REF_META,
            'meta_value' => $ref,
        ]);
        return (is_array($orders) && $orders && $orders[0] instanceof \WC_Order) ? $orders[0] : null;
    }
}
