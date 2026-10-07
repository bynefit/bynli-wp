<?php
if (!defined('ABSPATH')) { exit; }

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

/**
 * WC_Blocks_Bynefit — Cart/Checkout **Blocks** integration for the Bynefit
 * gateway.
 *
 * WooCommerce's block-based Checkout does NOT render classic WC_Payment_Gateway
 * classes: it builds its payment list from methods registered through the Blocks
 * registry. A gateway with only the classic integration is therefore invisible
 * on a block checkout — the shopper sees "no payment methods available" even
 * though the gateway is enabled and available. This class is that missing half.
 *
 * Loaded lazily (Bynli_Connect_Woo::register_blocks_support) because
 * AbstractPaymentMethodType only exists when WooCommerce Blocks is present.
 */
final class WC_Blocks_Bynefit extends AbstractPaymentMethodType {

    protected $name = 'bynefit';

    /** Gateway settings, read from the same option the classic gateway writes. */
    public function initialize() {
        $this->settings = get_option('woocommerce_bynefit_settings', []);
    }

    /** The classic gateway instance backing this method, or null. */
    private function gateway(): ?WC_Payment_Gateway {
        if (!function_exists('WC')) {
            return null;
        }
        $gateways = WC()->payment_gateways()->payment_gateways();
        return (isset($gateways[$this->name]) && $gateways[$this->name] instanceof WC_Payment_Gateway)
            ? $gateways[$this->name]
            : null;
    }

    /**
     * Read the stored enabled flag + key directly. WooCommerce evaluates block
     * payment methods early, before WC()->payment_gateways() is populated, so
     * resolving the live gateway instance here returned null and hid Bynefit
     * from the block checkout. This mirrors the same enabled+key gate the
     * classic gateway's is_available() applies, without the registry lookup.
     */
    public function is_active() {
        $enabled = !empty($this->settings['enabled']) && 'yes' === $this->settings['enabled'];
        return $enabled && (bool) Bynli_Connect_Settings::key();
    }

    /** Register (no build step — plain browser JS) and hand WC the handle. */
    public function get_payment_method_script_handles() {
        $handle = 'bynefit-woo-blocks';
        wp_register_script(
            $handle,
            plugins_url('assets/woo-blocks.js', BYNLI_CONNECT_PLUGIN_FILE),
            ['wc-blocks-registry', 'wc-settings', 'wp-element', 'wp-html-entities', 'wp-i18n'],
            BYNLI_CONNECT_VERSION,
            true
        );
        if (function_exists('wp_set_script_translations')) {
            wp_set_script_translations($handle, 'bynli-connect');
        }
        return [$handle];
    }

    /** Payload the JS reads via wc.wcSettings.getSetting('bynefit_data'). */
    public function get_payment_method_data() {
        return [
            'title'       => $this->get_setting('title', __('Pay with Bynefit', 'bynli-connect')),
            'description' => $this->get_setting(
                'description',
                __('You’ll be redirected to a secure Bynefit page to complete your payment.', 'bynli-connect')
            ),
            'supports'    => $this->get_supported_features(),
        ];
    }

    /**
     * Feature list the block checkout advertises for this method — the gateway's
     * own $supports IS that list by definition, so return it directly. (Filtering
     * it through a callback here is how this fatals: the gateway REGISTRY has no
     * supports() method, and array_filter passes only one argument anyway.)
     */
    public function get_supported_features() {
        $gateway = $this->gateway();
        return $gateway ? $gateway->supports : ['products'];
    }
}
