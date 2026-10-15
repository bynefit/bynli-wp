<?php
if (!defined('ABSPATH')) { exit; }

class Bynli_Connect_Asset_Origin {
    private static $from = null;
    private static $to = null;

    public static function register() {
        $pair = self::resolve();
        if ($pair === null) {
            return;
        }
        self::$from = $pair[0];
        self::$to   = $pair[1];
        add_filter('content_url', [__CLASS__, 'swap'], 1);
        add_filter('plugins_url', [__CLASS__, 'swap'], 1);
        add_filter('upload_dir', [__CLASS__, 'swap_upload_dir'], 1);
    }

    public static function resolve() {
        if (!defined('WP_CONTENT_URL')) {
            return null;
        }
        $from = self::origin(WP_CONTENT_URL);
        $to   = self::origin((string) get_option('home'));
        if ($from === '' || $to === '' || $from === $to) {
            return null;
        }
        $host = (string) wp_parse_url($from, PHP_URL_HOST);
        if (!preg_match('/\.site\.(?:bynefit|bynli)\.com$/i', $host)) {
            return null;
        }
        return [$from, $to];
    }

    public static function swap($url) {
        if (!is_string($url) || self::$from === null) {
            return $url;
        }
        if (stripos($url, self::$from) !== 0) {
            return $url;
        }
        $next = substr($url, strlen(self::$from), 1);
        if ($next !== '' && $next !== false && !in_array($next, ['/', '?', '#'], true)) {
            return $url;
        }
        return self::$to . substr($url, strlen(self::$from));
    }

    public static function swap_upload_dir($dirs) {
        if (!is_array($dirs)) {
            return $dirs;
        }
        foreach (['url', 'baseurl'] as $key) {
            if (isset($dirs[$key])) {
                $dirs[$key] = self::swap($dirs[$key]);
            }
        }
        return $dirs;
    }

    private static function origin($url) {
        $parts = wp_parse_url($url);
        if (empty($parts['scheme']) || empty($parts['host'])) {
            return '';
        }
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';
        return strtolower($parts['scheme'] . '://' . $parts['host'] . $port);
    }
}
