<?php
/**
 * Plugin Name:       Bynli Connect
 * Plugin URI:        https://bynli.com/guides/wordpress
 * Description:       Connect a WordPress site to Bynli — reports daily usage and exposes Bynli shortcodes for forms, modals, toasts, confirms, and the floating widget.
 * Version:           0.3.1
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Bynefit
 * Author URI:        https://bynefit.org
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       bynli-connect
 * Update URI:        https://bynli.com/api/site-host/version
 */

if (!defined('ABSPATH')) {
    exit;
}

define('BYNLI_CONNECT_VERSION', '0.3.1');
define('BYNLI_CONNECT_PLUGIN_FILE', __FILE__);
define('BYNLI_CONNECT_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('BYNLI_CONNECT_DEFAULT_API_BASE', 'https://bynli.com');

require_once BYNLI_CONNECT_PLUGIN_DIR . 'includes/class-settings.php';
require_once BYNLI_CONNECT_PLUGIN_DIR . 'includes/class-signer.php';
require_once BYNLI_CONNECT_PLUGIN_DIR . 'includes/class-reporter.php';
require_once BYNLI_CONNECT_PLUGIN_DIR . 'includes/class-shortcodes.php';
require_once BYNLI_CONNECT_PLUGIN_DIR . 'includes/class-updater.php';
require_once BYNLI_CONNECT_PLUGIN_DIR . 'includes/class-plugin.php';

add_action('plugins_loaded', ['Bynli_Connect_Plugin', 'instance']);

register_activation_hook(__FILE__,  ['Bynli_Connect_Plugin', 'on_activate']);
register_deactivation_hook(__FILE__, ['Bynli_Connect_Plugin', 'on_deactivate']);
