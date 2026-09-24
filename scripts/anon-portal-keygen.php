<?php
/**
 * Make the anonymous document portal's key pair.
 *
 *   php -d extension=sodium scripts/anon-portal-keygen.php <private-key-file>
 *
 * Writes the private key (the whole key pair, base64) to <private-key-file>
 * and the public key to inc/anonymous-portal-public.key, which is committed
 * and deployed. The private key must never be committed or uploaded: keep it
 * in a password manager. Lose it and every submission sealed with the
 * matching public key is unreadable for good.
 *
 * Refuses to overwrite an existing key file, and refuses a private key path
 * inside this repository.
 */

if (PHP_SAPI !== 'cli') {
    exit("CLI only.\n");
}
if (!function_exists('sodium_crypto_box_keypair')) {
    fwrite(STDERR, "The sodium extension is not loaded. Run with: php -d extension=sodium ...\n");
    exit(1);
}
if ($argc !== 2) {
    fwrite(STDERR, "Usage: php -d extension=sodium scripts/anon-portal-keygen.php <private-key-file>\n");
    exit(1);
}

$repo        = realpath(__DIR__ . '/..');
$public_path = $repo . '/inc/anonymous-portal-public.key';
$private     = $argv[1];

$private_dir = realpath(dirname($private));
if ($private_dir === false) {
    fwrite(STDERR, "Folder does not exist: " . dirname($private) . "\n");
    exit(1);
}
$norm = static function ($p) {
    return rtrim(strtolower(str_replace('\\', '/', $p)), '/') . '/';
};
if (strpos($norm($private_dir), $norm($repo)) === 0) {
    fwrite(STDERR, "Refusing to write the private key inside the repository.\n");
    exit(1);
}
$private_path = $private_dir . DIRECTORY_SEPARATOR . basename($private);
foreach (array($private_path, $public_path) as $p) {
    if (file_exists($p)) {
        fwrite(STDERR, "Already exists, not overwriting: $p\n");
        exit(1);
    }
}

$pair   = sodium_crypto_box_keypair();
$public = sodium_crypto_box_publickey($pair);

if (file_put_contents($private_path, base64_encode($pair) . "\n") === false
    || file_put_contents($public_path, base64_encode($public) . "\n") === false) {
    fwrite(STDERR, "Could not write the key files.\n");
    exit(1);
}
sodium_memzero($pair);

echo "Private key: $private_path\n";
echo "Public key:  $public_path\n";
echo "Fingerprint: " . substr(hash('sha256', $public), 0, 16) . " (wp-admin > Anonymous Docs shows the same)\n";
