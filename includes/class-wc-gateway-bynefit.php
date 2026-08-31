<?php
if (!defined('ABSPATH')) { exit; }

/**
 * WC_Gateway_Bynefit — off-site (redirect) WooCommerce payment gateway (#2164).
 * Holds NO PayPal credentials: process_payment() calls the signed
 * /api/site-host/woo/checkout with the site-host key the plugin already carries,
 * then redirects the buyer to the returned Bynefit-hosted pay page. The order
 * sits on-hold until the signed poll (via the nudge / thank-you reconcile in
 * class-woo.php) confirms it paid. PCI SAQ-A — the site never touches card data.
 *
 * This class is loaded lazily (Bynli_Connect_Woo::load_gateway_class) because it
 * extends WC_Payment_Gateway, which only exists when WooCommerce is active.
 */
class WC_Gateway_Bynefit extends WC_Payment_Gateway {

    public function __construct() {
        $this->id                 = 'bynefit';
        $this->method_title       = __('Bynefit', 'bynli-connect');
        $this->method_description = __('Route payments through your connected Bynefit account. Buyers pay on a secure Bynefit-hosted page — no card data touches your site.', 'bynli-connect');
        $this->has_fields         = false;
        $this->supports           = ['products'];

        $this->init_form_fields();
        $this->init_settings();

        $this->title       = $this->get_option('title', __('Pay with Bynefit', 'bynli-connect'));
        $this->description  = $this->get_option('description', __('You’ll be redirected to a secure Bynefit page to complete your payment.', 'bynli-connect'));
        $this->enabled     = $this->get_option('enabled', 'no');

        add_action('woocommerce_update_options_payment_gateways_' . $this->id, [$this, 'process_admin_options']);
    }

    public function init_form_fields(): void {
        $connected = (bool) Bynli_Connect_Settings::key();
        $this->form_fields = [
            'enabled' => [
                'title'       => __('Enable/Disable', 'bynli-connect'),
                'type'        => 'checkbox',
                'label'       => __('Enable Bynefit payments', 'bynli-connect'),
                'default'     => 'no',
                'description' => $connected ? '' : __('Add your Bynefit site-host key in Settings → Bynefit Connect first.', 'bynli-connect'),
            ],
            'title' => [
                'title'       => __('Title', 'bynli-connect'),
                'type'        => 'text',
                'default'     => __('Pay with Bynefit', 'bynli-connect'),
                'desc_tip'    => true,
                'description' => __('Payment method name the customer sees at checkout.', 'bynli-connect'),
            ],
            'description' => [
                'title'   => __('Description', 'bynli-connect'),
                'type'    => 'textarea',
                'default' => __('You’ll be redirected to a secure Bynefit page to complete your payment.', 'bynli-connect'),
            ],
        ];
    }

    public function is_available(): bool {
        if ('yes' !== $this->enabled) return false;
        if (!Bynli_Connect_Settings::key()) return false;
        return parent::is_available();
    }

    public function process_payment($order_id) {
        $order = wc_get_order($order_id);
        if (!$order instanceof \WC_Order) {
            return ['result' => 'failure'];
        }

        $items = [];
        foreach ($order->get_items() as $item) {
            $line = [
                'name'  => $item->get_name(),
                'qty'   => (int) $item->get_quantity(),
                'total' => self::cents($item->get_total()),
            ];
            // SKU lets the merchant reconcile a Bynefit order against their own
            // catalogue. get_product() is null for a deleted product — don't fatal.
            if (method_exists($item, 'get_product')) {
                $product = $item->get_product();
                if ($product && method_exists($product, 'get_sku')) {
                    $sku = (string) $product->get_sku();
                    if ($sku !== '') { $line['sku'] = $sku; }
                }
            }
            $items[] = $line;
        }
        $ship = (float) $order->get_shipping_total() + (float) $order->get_shipping_tax();
        if ($ship > 0) {
            $items[] = ['name' => __('Shipping', 'bynli-connect'), 'qty' => 1, 'total' => self::cents($ship)];
        }

        $payload = [
            'site_order_id' => (int) $order_id,
            'order_key'     => $order->get_order_key(),
            'currency'      => $order->get_currency(),
            'amount_total'  => self::cents($order->get_total()),
            'line_items'    => $items,
            'return_url'    => $this->get_return_url($order),
            'cancel_url'    => $order->get_cancel_order_url_raw(),
        ];

        // Verified buyer + addresses. This travels over the signed server-to-server
        // channel, so Bynefit can trust it: it personalises the hosted pay page
        // (the shopper sees this store's name and their own order, not a bare
        // total on an unfamiliar domain), prefills PayPal so they aren't retyping
        // what this store already has, and gives the merchant portal the context
        // refunds and support need. Only sent for the Bynefit gateway, only for
        // this order, and only the fields Bynefit stores.
        $buyer = self::compact_fields([
            'first_name' => $order->get_billing_first_name(),
            'last_name'  => $order->get_billing_last_name(),
            'email'      => $order->get_billing_email(),
            'phone'      => $order->get_billing_phone(),
        ]);
        if ($buyer) { $payload['buyer'] = $buyer; }

        $billing = self::address_fields($order, 'billing');
        if ($billing) { $payload['billing'] = $billing; }

        // Only present on orders that actually ship. For countries WooCommerce ships
        // a states list for, `state` is the 2-letter subdivision code PayPal wants;
        // elsewhere it's free text, which PayPal also accepts.
        $shipping = self::address_fields($order, 'shipping');
        if ($shipping) { $payload['shipping'] = $shipping; }

        $idempotency_key = function_exists('wp_generate_uuid4') ? wp_generate_uuid4() : md5(uniqid((string) $order_id, true));
        $res = Bynli_Connect_Api::post_v2('/api/site-host/woo/checkout', $payload, $idempotency_key);

        if (empty($res['ok']) || empty($res['data']['checkout_url'])) {
            $err = (string) ($res['error'] ?? '');
            $msg = $err !== ''
                ? sprintf(__('Payment could not be started (%s).', 'bynli-connect'), $err)
                : __('Could not start the payment. Please try again.', 'bynli-connect');
            wc_add_notice($msg, 'error');
            $order->add_order_note('Bynefit checkout failed to start: ' . $msg);
            return ['result' => 'failure'];
        }

        $ref = (string) ($res['data']['checkout_ref'] ?? '');
        if ($ref !== '') {
            $order->update_meta_data(Bynli_Connect_Woo::REF_META, $ref);
        }
        // On-hold = awaiting payment. It flips to paid only via the signed poll
        // (nudge / thank-you reconcile), never from the browser return alone.
        $order->update_status('on-hold', __('Awaiting payment on Bynefit.', 'bynli-connect'));
        $order->save();

        return [
            'result'   => 'success',
            'redirect' => (string) $res['data']['checkout_url'],
        ];
    }

    private static function cents($amount): int {
        return (int) round(((float) $amount) * 100);
    }

    /** Trim, drop empties, and return null when nothing survives. */
    private static function compact_fields(array $fields): ?array {
        $out = [];
        foreach ($fields as $k => $v) {
            $v = trim((string) $v);
            if ($v !== '') { $out[$k] = $v; }
        }
        return $out ?: null;
    }

    /**
     * A billing or shipping address in Bynefit's shape. Returns null when the
     * address is empty — a virtual/downloadable order has no shipping address, and
     * sending an empty one would make Bynefit hand PayPal a partial address.
     *
     * @param string $type 'billing' | 'shipping'
     */
    private static function address_fields(\WC_Order $order, string $type): ?array {
        $get = function (string $field) use ($order, $type) {
            $method = 'get_' . $type . '_' . $field;
            return method_exists($order, $method) ? (string) $order->{$method}() : '';
        };
        $addr = self::compact_fields([
            // Recipient name — a "ship to a different address" order (a gift) has a
            // different name here than the billing name, and without it the payment
            // page and PayPal would show the buyer's own name against someone
            // else's street.
            'first_name' => $get('first_name'),
            'last_name'  => $get('last_name'),
            'line1'      => $get('address_1'),
            'line2'      => $get('address_2'),
            'city'       => $get('city'),
            'state'      => $get('state'),
            'postcode'   => $get('postcode'),
            'country'    => $get('country'),
            'company'    => $get('company'),
        ]);

        // A stray country with nothing else isn't an address — it would just render
        // as a junk one-line ship-to in the merchant's portal. Require enough to be
        // meaningful before sending anything.
        if (!$addr || empty($addr['city']) || empty($addr['country'])) {
            return null;
        }
        return $addr;
    }
}
