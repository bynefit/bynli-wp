<?php
if (!defined('ABSPATH')) { exit; }

class Bynli_Connect_Reporter {
    const ENDPOINT_PATH = '/api/site-host/report';
    const OPTION_LAST   = 'bynli_connect_last_report';

    public static function send_heartbeat(): array {
        return self::post(['kind' => 'heartbeat']);
    }

    public static function send_daily(): array {
        $usage = self::collect_usage();
        $payload = [
            'kind'            => 'daily',
            'usage_date'      => gmdate('Y-m-d', time() - 86400),
            'bandwidth_bytes' => (int)$usage['bandwidth_bytes'],
            'storage_bytes'   => (int)$usage['storage_bytes'],
            'request_count'   => (int)$usage['request_count'],
            'meta'            => $usage['meta'],
        ];
        return self::post($payload);
    }

    private static function collect_usage(): array {
        $storage = self::dir_size(ABSPATH);
        return [
            'bandwidth_bytes' => 0,
            'storage_bytes'   => $storage,
            'request_count'   => 0,
            'meta' => [
                'wp_version'     => get_bloginfo('version'),
                'php_version'    => PHP_VERSION,
                'plugin_version' => BYNLI_CONNECT_VERSION,
                'home_url'       => home_url(),
                'measurement'    => 'best_effort_v1',
            ],
        ];
    }

    private static function dir_size(string $path, int $cap_bytes = 50 * 1024 * 1024 * 1024): int {
        $total = 0;
        try {
            $it = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::SELF_FIRST
            );
            foreach ($it as $f) {
                if ($f->isFile()) {
                    $total += $f->getSize();
                    if ($total > $cap_bytes) break;
                }
            }
        } catch (\Throwable $e) {
            error_log('[Bynli Connect] dir_size: ' . $e->getMessage());
        }
        return $total;
    }

    private static function post(array $payload): array {
        $api_base = Bynli_Connect_Settings::api_base();
        $key      = Bynli_Connect_Settings::key();
        if (!$key) {
            return ['ok' => false, 'error' => 'no_key', 'message' => 'No API key configured. See Settings → Bynli Connect.'];
        }

        $body  = wp_json_encode($payload);
        $ts    = time();
        $sig   = Bynli_Connect_Signer::sign($key, $ts, $body);
        $url   = rtrim($api_base, '/') . self::ENDPOINT_PATH;

        $resp = wp_remote_post($url, [
            'timeout' => 15,
            'headers' => [
                'Content-Type'        => 'application/json',
                'Accept'              => 'application/json',
                'Authorization'       => 'Bearer ' . $key,
                'X-Bynli-Timestamp'   => (string)$ts,
                'X-Bynli-Signature'   => $sig,
                'User-Agent'          => 'Bynli-Connect/' . BYNLI_CONNECT_VERSION . ' WP/' . get_bloginfo('version'),
            ],
            'body' => $body,
        ]);

        if (is_wp_error($resp)) {
            error_log('[Bynli Connect] post failed: ' . $resp->get_error_message());
            return ['ok' => false, 'error' => 'transport', 'message' => $resp->get_error_message()];
        }

        $code = wp_remote_retrieve_response_code($resp);
        $raw  = wp_remote_retrieve_body($resp);
        $json = json_decode($raw, true);

        update_option(self::OPTION_LAST, [
            'at'      => time(),
            'kind'    => $payload['kind'],
            'status'  => $code,
            'ok'      => (is_array($json) && !empty($json['ok'])),
            'message' => is_array($json) ? ($json['error'] ?? '') : substr($raw, 0, 200),
        ], false);

        if ($code >= 200 && $code < 300 && is_array($json) && !empty($json['ok'])) {
            return ['ok' => true, 'status' => $code, 'response' => $json];
        }
        return ['ok' => false, 'status' => $code, 'response' => $json ?: $raw];
    }

    public static function last_report(): array {
        $val = get_option(self::OPTION_LAST, null);
        return is_array($val) ? $val : [];
    }
}
