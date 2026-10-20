<?php
if (PHP_SAPI !== 'cli') {
    exit(1);
}
define('ABSPATH', __DIR__ . '/');
require_once __DIR__ . '/../includes/class-release-signature.php';

function usage(): void
{
    fwrite(STDERR, "usage:\n"
        . "  php tools/release-sign.php keygen\n"
        . "  php tools/release-sign.php sign <version> <zip-or-sha256> <secret-key-file>\n"
        . "  php tools/release-sign.php verify <version> <zip-or-sha256> <signature> <public-key-base64>\n");
    exit(2);
}

if (!function_exists('sodium_crypto_sign_keypair')) {
    fwrite(STDERR, "the sodium extension is required\n");
    exit(1);
}

function digest_of(string $arg): string
{
    if (preg_match('/^[a-fA-F0-9]{64}$/', $arg)) {
        return strtolower($arg);
    }
    $h = is_file($arg) ? hash_file('sha256', $arg) : false;
    if ($h === false) {
        fwrite(STDERR, "not a sha256 digest or readable file: $arg\n");
        exit(1);
    }
    return $h;
}

$cmd = $argv[1] ?? '';
if ($cmd === 'keygen') {
    $kp = sodium_crypto_sign_keypair();
    echo "public:  " . base64_encode(sodium_crypto_sign_publickey($kp)) . "\n";
    echo "secret:  " . base64_encode(sodium_crypto_sign_secretkey($kp)) . "\n";
    exit(0);
}
if ($cmd === 'sign' && count($argv) === 5) {
    $secret = base64_decode(trim((string) file_get_contents($argv[4])), true);
    if ($secret === false || strlen($secret) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
        fwrite(STDERR, "secret key file must hold the base64 secret key\n");
        exit(1);
    }
    $digest = digest_of($argv[3]);
    echo base64_encode(sodium_crypto_sign_detached(Bynli_Connect_Release_Signature::message($argv[2], $digest), $secret)) . "\n";
    exit(0);
}
if ($cmd === 'verify' && count($argv) === 6) {
    $manifest = ['version' => $argv[2], 'download_sha256' => digest_of($argv[3]), 'download_signature' => $argv[4]];
    $status = Bynli_Connect_Release_Signature::status($manifest, [$argv[5]]);
    echo $status . "\n";
    exit($status === 'ok' ? 0 : 1);
}
usage();
