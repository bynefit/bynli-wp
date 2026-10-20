<?php
if (!defined('ABSPATH')) { exit; }

class Bynli_Connect_Release_Signature {
    const PINNED_KEYS = [];

    const MIN_VERSION = '0.25.0';

    const CONTEXT = 'bynli-connect-release/v1';

    private static $logged_bad_keys = [];

    public static function message(string $version, string $sha256): string
    {
        return self::CONTEXT . "\n" . $version . "\n" . strtolower($sha256) . "\n";
    }

    public static function keys(): array
    {
        $configured = self::PINNED_KEYS;
        if (defined('BYNLI_CONNECT_RELEASE_KEYS') && is_array(BYNLI_CONNECT_RELEASE_KEYS)) {
            $configured = array_merge($configured, BYNLI_CONNECT_RELEASE_KEYS);
        }
        $good = [];
        foreach ($configured as $key) {
            $raw = is_string($key) ? base64_decode(trim($key), true) : false;
            if ($raw === false || strlen($raw) !== 32) {
                $id = is_string($key) ? md5($key) : 'non-string';
                if (!isset(self::$logged_bad_keys[$id])) {
                    self::$logged_bad_keys[$id] = true;
                    error_log('[Bynli Connect] a configured release key is not a base64 32-byte Ed25519 public key and was ignored');
                }
                continue;
            }
            $good[] = trim($key);
        }
        return $good;
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
        $ok  = false;
        foreach ($keys as $key_b64) {
            $pk = base64_decode($key_b64, true);
            if ($pk !== false && strlen($pk) === SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
                && sodium_crypto_sign_verify_detached($sig, $msg, $pk)) {
                $ok = true;
                break;
            }
        }
        if (!$ok) {
            return 'invalid';
        }
        if (version_compare($version, self::MIN_VERSION, '<')) {
            return 'below_floor';
        }
        return 'ok';
    }
}
