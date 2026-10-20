<?php
if (!defined('ABSPATH')) { exit; }

class Bynli_Connect_Release_Signature {
    const PINNED_KEYS = [];

    const CONTEXT = 'bynli-connect-release/v1';

    public static function message(string $version, string $sha256): string
    {
        return self::CONTEXT . "\n" . $version . "\n" . strtolower($sha256) . "\n";
    }

    public static function keys(): array
    {
        $keys = self::PINNED_KEYS;
        if (defined('BYNLI_CONNECT_RELEASE_KEYS') && is_array(BYNLI_CONNECT_RELEASE_KEYS)) {
            $keys = array_merge($keys, BYNLI_CONNECT_RELEASE_KEYS);
        }
        return array_values(array_filter($keys, 'is_string'));
    }

    public static function enforced(?array $keys = null): bool
    {
        $keys = $keys ?? self::keys();
        return count($keys) > 0;
    }

    public static function status(array $manifest, ?array $keys = null): string
    {
        $keys = $keys ?? self::keys();
        if (!$keys) {
            return 'not_enforced';
        }
        if (!function_exists('sodium_crypto_sign_verify_detached')) {
            return 'unsupported';
        }
        $version = isset($manifest['version']) ? (string) $manifest['version'] : '';
        $sha     = isset($manifest['download_sha256']) ? strtolower(trim((string) $manifest['download_sha256'])) : '';
        $sig_b64 = isset($manifest['download_signature']) ? trim((string) $manifest['download_signature']) : '';
        if ($version === '' || !preg_match('/^[a-f0-9]{64}$/', $sha) || $sig_b64 === '') {
            return 'missing';
        }
        $sig = base64_decode($sig_b64, true);
        if ($sig === false || strlen($sig) !== SODIUM_CRYPTO_SIGN_BYTES) {
            return 'invalid';
        }
        $msg = self::message($version, $sha);
        foreach ($keys as $key_b64) {
            $pk = base64_decode((string) $key_b64, true);
            if ($pk === false || strlen($pk) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
                continue;
            }
            if (sodium_crypto_sign_verify_detached($sig, $msg, $pk)) {
                return 'ok';
            }
        }
        return 'invalid';
    }
}
