<?php
/**
 * Bynefit Connect — uninstall cleanup. Runs only when the plugin is DELETED
 * (not on deactivate). Removes plugin options + the Client role so nothing is
 * left standing after removal.
 */
if (!defined('WP_UNINSTALL_PLUGIN')) { exit; }

$bynli_connect_options = [
    'bynli_connect_api_key',
    'bynli_connect_api_base',
    'bynli_connect_site_slug',
    'bynli_connect_last_report',
    'bynli_connect_report_history',
    'bynli_connect_visibility',
    'bynli_connect_client_mode',
];
foreach ($bynli_connect_options as $bynli_connect_opt) {
    delete_option($bynli_connect_opt);
}
delete_transient('bynli_connect_update_check');

// Remove the custom Client role. Any users still holding it fall back to no
// role, so a site admin should reassign them before deleting the plugin.
if (function_exists('remove_role') && get_role('bynefit_client')) {
    remove_role('bynefit_client');
}
