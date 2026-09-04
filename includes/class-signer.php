<?php
if (!defined('ABSPATH')) { exit; }

class Bynli_Connect_Signer {
    public static function sign(string $plaintext_key, int $timestamp, string $body): string {
        $payload = $timestamp . "\n" . $body;
        return 'sha256=' . hash_hmac('sha256', $payload, $plaintext_key);
    }

    /**
     * Signer v2 — folds an idempotency key into the HMAC
     * preimage so a replayed body with a fresh id can't pass as the original.
     * MUST match the server verifier (SiteHostKey::verifySignatureV2):
     *   preimage = timestamp + "\n" + id + "\n" + body
     */
    public static function sign_v2(string $plaintext_key, int $timestamp, string $id, string $body): string {
        return 'sha256=' . hash_hmac('sha256', $timestamp . "\n" . $id . "\n" . $body, $plaintext_key);
    }

    /**
     * Inbound verify — the reverse direction of sign(), for requests bynefit.com
     * makes INTO this site's bynli/v1 control plane. Same scheme as the server
     * verifier (SiteHostKey::verifySignature): reject timestamps outside the
     * replay window, constant-time compare. Returns false on any failure.
     */
    public static function verify(
        string $plaintext_key,
        int $timestamp,
        string $body,
        string $provided_sig,
        int $replay_window = 300
    ): bool {
        if ($plaintext_key === '' || $provided_sig === '') {
            return false;
        }
        if (abs(time() - $timestamp) > $replay_window) {
            return false;
        }
        return hash_equals(self::sign($plaintext_key, $timestamp, $body), $provided_sig);
    }
}
