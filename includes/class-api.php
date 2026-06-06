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
