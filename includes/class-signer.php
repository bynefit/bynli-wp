<?php
if (!defined('ABSPATH')) { exit; }

class Bynli_Connect_Signer {
    public static function sign(string $plaintext_key, int $timestamp, string $body): string {
        $payload = $timestamp . "\n" . $body;
        return 'sha256=' . hash_hmac('sha256', $payload, $plaintext_key);
    }
}
