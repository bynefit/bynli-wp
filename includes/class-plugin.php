<?php
if (!defined('ABSPATH')) { exit; }

class Bynli_Connect_Plugin {
    private static $instance = null;
    public static function instance() {
        if (self::$instance === null) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {
        new Bynli_Connect_Settings();
        new Bynli_Connect_Shortcodes();
        new Bynli_Connect_Updater();

        add_action('bynli_connect_daily_report', [Bynli_Connect_Reporter::class, 'send_daily']);

        if (!wp_next_scheduled('bynli_connect_daily_report')) {
            wp_schedule_event(time() + 3600, 'daily', 'bynli_connect_daily_report');
        }
    }

    public static function on_activate() {
        if (!wp_next_scheduled('bynli_connect_daily_report')) {
            wp_schedule_event(time() + 3600, 'daily', 'bynli_connect_daily_report');
        }
    }

    public static function on_deactivate() {
        $ts = wp_next_scheduled('bynli_connect_daily_report');
        if ($ts) wp_unschedule_event($ts, 'bynli_connect_daily_report');
    }
}
