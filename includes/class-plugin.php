<?php
if (!defined('ABSPATH')) { exit; }

class Bynli_Connect_Plugin {
    const FLUSH_OPTION = 'bynli_connect_flush_rewrites';

    private static $instance = null;
    public static function instance() {
        if (self::$instance === null) self::$instance = new self();
        return self::$instance;
    }

    public static function request_rewrite_flush(): void {
        update_option(self::FLUSH_OPTION, '1', false);
    }

    public function maybe_flush_rewrites(): void {
        if (get_option(self::FLUSH_OPTION)) {
            flush_rewrite_rules(false);
            delete_option(self::FLUSH_OPTION);
        }
    }

    private function __construct() {
        add_action('init', [$this, 'maybe_flush_rewrites'], 99);
        new Bynli_Connect_Settings();
        new Bynli_Connect_Control_Plane();
        new Bynli_Connect_Shortcodes();
        new Bynli_Connect_Updater();
        new Bynli_Connect_Tickets();
        new Bynli_Connect_Visibility();
        new Bynli_Connect_Client_Mode();
        new Bynli_Connect_Woo();
        new Bynli_Connect_Inbound();
        new Bynli_Connect_Blocks();
        new Bynli_Connect_Badge();
        new Bynli_Connect_Beacon();

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
        wp_clear_scheduled_hook(Bynli_Connect_Badge::REFRESH_HOOK);
        wp_clear_scheduled_hook(Bynli_Connect_Beacon::REFRESH_HOOK);
        Bynli_Connect_Inbound::clear_schedules();
    }
}
