<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Bynli_Connect_Api — signed-GET helper for site-host endpoints.
 *
 * Reporter::post() already handles signed POSTs for daily usage reports.
 * As of v0.5 the plugin also reads from /api/site-host/tickets, so we
 * factor the auth + signing into a shared helper any future GET surface
 * (tickets, future widget config, etc.) can reuse without duplicating
 * the Bearer + HMAC dance.
 *
 * Signs an empty body the same way the server expects (`ts + "\n" + ""`)
 * so the bynli-side SiteHostKey::verifySignature pass is identical to
 * POST.
 */
class Bynli_Connect_Api {

    /**
     * GET a JSON endpoint at $path (e.g. "/api/site-host/tickets"). Query
     * arguments are appended to the URL but NOT part of the signed payload
     * — the server's verifier signs body only, never URL.
     *
     * @return array {
     *   ok:       bool,
     *   status?:  int,
     *   data?:    array,     // decoded JSON when ok
     *   error?:   string,    // short machine code when !ok
     *   message?: string,    // human-readable detail
     * }
     */
    /**
     * POST a JSON body to $path. Same signed-header pattern as get(),
     * but the HMAC is over the actual JSON body bytes (not empty).
     * Mirrors the bynli-side site_host_ticket_reply / _resolve handlers.
     *
     * @param array $body  Decoded payload; encoded with wp_json_encode.
     * @return array       Same shape as get() — { ok, status?, data?, error?, message? }
     */
    public static function post(string $path, array $body = []): array {
        $key = Bynli_Connect_Settings::key();
        if (!$key) {
            return ['ok' => false, 'error' => 'no_key', 'message' => 'No API key configured. See Settings → Bynli Connect.'];
        }

        $api_base = Bynli_Connect_Settings::api_base();
        $url      = rtrim($api_base, '/') . $path;

        // wp_json_encode returns '' on failure (e.g. a recursive object).
        // Empty body is also legal — site_host_ticket_resolve accepts that —
        // so we only abort when encode actually failed against a non-empty
        // input.
        $body_raw = $body ? wp_json_encode($body) : '';
        if ($body && $body_raw === false) {
            return ['ok' => false, 'error' => 'encode_failed', 'message' => 'Could not encode request body.'];
        }

        $ts  = time();
        $sig = Bynli_Connect_Signer::sign($key, $ts, (string)$body_raw);

        $resp = wp_remote_post($url, [
            'timeout' => 15,
            'headers' => [
                'Content-Type'      => 'application/json',
                'Accept'            => 'application/json',
                'Authorization'     => 'Bearer ' . $key,
                'X-Bynli-Timestamp' => (string)$ts,
                'X-Bynli-Signature' => $sig,
                'User-Agent'        => 'Bynli-Connect/' . BYNLI_CONNECT_VERSION . ' WP/' . get_bloginfo('version'),
            ],
            'body' => (string)$body_raw,
        ]);

        if (is_wp_error($resp)) {
            return ['ok' => false, 'error' => 'transport', 'message' => $resp->get_error_message()];
        }

        $code = wp_remote_retrieve_response_code($resp);
        $raw  = wp_remote_retrieve_body($resp);
        $json = json_decode($raw, true);

        if ($code >= 200 && $code < 300 && is_array($json) && !empty($json['ok'])) {
            return ['ok' => true, 'status' => $code, 'data' => $json];
        }

        $err   = is_array($json) ? (string)($json['error'] ?? 'http_' . $code) : 'http_' . $code;
        $human = [
            'no_key'               => 'No API key configured.',
            'unauthorized'         => 'Site key was rejected. Reissue it at /dash/sites/host-keys on Bynli.',
            'signature_invalid'    => 'Signature check failed. The site clock may be off, or the key is wrong.',
            'bad_timestamp'        => 'Bad timestamp header. Check the server clock.',
            'bad_signature_format' => 'Malformed signature header — please report.',
            'not_found'            => 'Ticket not found.',
            'method_not_allowed'   => 'Endpoint does not accept this method.',
            'empty_body'           => 'Reply cannot be empty.',
            'body_too_large'       => 'Reply is too long — keep it under 5000 characters.',
            'invalid_json'         => 'The request body could not be parsed.',
            'server_error'         => 'Bynli returned a server error. Please retry shortly.',
        ];
        return [
            'ok'      => false,
            'status'  => $code,
            'error'   => $err,
            'message' => $human[$err] ?? ('Request failed (' . $err . ').'),
        ];
    }

    public static function get(string $path, array $query = []): array {
        $key = Bynli_Connect_Settings::key();
        if (!$key) {
            return ['ok' => false, 'error' => 'no_key', 'message' => 'No API key configured. See Settings → Bynli Connect.'];
        }

        $api_base = Bynli_Connect_Settings::api_base();
        $url      = rtrim($api_base, '/') . $path;
        if (!empty($query)) {
            $url .= (strpos($path, '?') === false ? '?' : '&') . http_build_query($query);
        }

        $ts  = time();
        $sig = Bynli_Connect_Signer::sign($key, $ts, '');

        $resp = wp_remote_get($url, [
            'timeout' => 15,
            'headers' => [
                'Accept'            => 'application/json',
                'Authorization'     => 'Bearer ' . $key,
                'X-Bynli-Timestamp' => (string)$ts,
                'X-Bynli-Signature' => $sig,
                'User-Agent'        => 'Bynli-Connect/' . BYNLI_CONNECT_VERSION . ' WP/' . get_bloginfo('version'),
            ],
        ]);

        if (is_wp_error($resp)) {
            return [
                'ok'      => false,
                'error'   => 'transport',
                'message' => $resp->get_error_message(),
            ];
        }

        $code = wp_remote_retrieve_response_code($resp);
        $raw  = wp_remote_retrieve_body($resp);
        $json = json_decode($raw, true);

        if ($code >= 200 && $code < 300 && is_array($json) && !empty($json['ok'])) {
            return ['ok' => true, 'status' => $code, 'data' => $json];
        }

        $err = is_array($json) ? (string)($json['error'] ?? 'http_' . $code) : 'http_' . $code;
        // Translate the more common error codes the bynli verifier returns
        // so the WP-admin UI can show something more useful than "http_401".
        $human = [
            'no_key'               => 'No API key configured.',
            'unauthorized'         => 'Site key was rejected. Reissue it at /dash/sites/host-keys on Bynli.',
            'signature_invalid'    => 'Signature check failed. The site clock may be off, or the key is wrong.',
            'bad_timestamp'        => 'Bad timestamp header. Check the server clock.',
            'bad_signature_format' => 'Malformed signature header — please report.',
            'not_found'            => 'Not found.',
            'method_not_allowed'   => 'Endpoint does not accept this method.',
            'server_error'         => 'Bynli returned a server error. Please retry shortly.',
        ];
        return [
            'ok'      => false,
            'status'  => $code,
            'error'   => $err,
            'message' => $human[$err] ?? ('Request failed (' . $err . ').'),
        ];
    }
}
