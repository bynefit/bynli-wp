<?php
if (!defined('ABSPATH')) { exit; }

class Bynli_Connect_Signer {
    public static function sign(string $plaintext_key, int $timestamp, string $body): string {
        $payload = $timestamp . "\n" . $body;
        return 'sha256=' . hash_hmac('sha256', $payload, $plaintext_key);
    }

    /**
     * Signer v2 (payment rail #2164) — folds an idempotency key into the HMAC
     * preimage so a replayed body with a fresh id can't pass as the original.
     * MUST match the server verifier (SiteHostKey::verifySignatureV2):
     *   preimage = timestamp + "\n" + id + "\n" + body
     */
    public static function sign_v2(string $plaintext_key, int $timestamp, string $id, string $body): string {
        return 'sha256=' . hash_hmac('sha256', $timestamp . "\n" . $id . "\n" . $body, $plaintext_key);
    }
}
