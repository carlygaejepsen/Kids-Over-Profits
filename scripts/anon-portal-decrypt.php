<?php
/**
 * Open anonymous portal submissions downloaded from wp-admin > Anonymous Docs.
 *
 *   php -d extension=sodium scripts/anon-portal-decrypt.php <private-key-file> <out-dir> <file.sealed>...
 *
 * For each sealed file, writes <id>_<original name> and, when the submitter
 * left notes, <id>_notes.txt into <out-dir>. The layout read here is the one
 * AnonymousDocPortal::seal_submission() in inc/features.php writes.
 */

if (PHP_SAPI !== 'cli') {
    exit("CLI only.\n");
}
if (!function_exists('sodium_crypto_box_seal_open')) {
    fwrite(STDERR, "The sodium extension is not loaded. Run with: php -d extension=sodium ...\n");
    exit(1);
}
if ($argc < 4) {
    fwrite(STDERR, "Usage: php -d extension=sodium scripts/anon-portal-decrypt.php <private-key-file> <out-dir> <file.sealed>...\n");
    exit(1);
}

$pair = base64_decode(trim((string) @file_get_contents($argv[1])), true);
if (!is_string($pair) || strlen($pair) !== SODIUM_CRYPTO_BOX_KEYPAIRBYTES) {
    fwrite(STDERR, "Not a portal private key: {$argv[1]}\n");
    exit(1);
}
$out_dir = rtrim($argv[2], '/\\');
if (!is_dir($out_dir) && !mkdir($out_dir, 0700, true)) {
    fwrite(STDERR, "Cannot create $out_dir\n");
    exit(1);
}
echo "Key fingerprint: " . substr(hash('sha256', sodium_crypto_box_publickey($pair)), 0, 16) . "\n";

// Keep only characters that are safe in a filename on Windows and Unix.
$safe = static function ($name) {
    $name = preg_replace('/[^A-Za-z0-9._ -]+/', '_', basename((string) $name));
    return trim($name, '. ') === '' ? 'document' : $name;
};

$failed = 0;
foreach (array_slice($argv, 3) as $path) {
    $plain = sodium_crypto_box_seal_open((string) @file_get_contents($path), $pair);
    if ($plain === false || substr($plain, 0, 8) !== 'KOPANON1' || strlen($plain) < 12) {
        fwrite(STDERR, "Could not open $path (wrong key, or not a portal file)\n");
        $failed++;
        continue;
    }
    $len  = unpack('N', substr($plain, 8, 4))[1];
    $meta = json_decode(substr($plain, 12, $len), true);
    if (!is_array($meta)) {
        fwrite(STDERR, "Damaged header in $path\n");
        $failed++;
        continue;
    }
    $id  = $safe($meta['id'] ?? basename($path, '.sealed'));
    $doc = $out_dir . DIRECTORY_SEPARATOR . $id . '_' . $safe($meta['name'] ?? 'document');
    file_put_contents($doc, substr($plain, 12 + $len));
    echo "$path -> $doc\n";
    if (($meta['notes'] ?? '') !== '') {
        $notes = $out_dir . DIRECTORY_SEPARATOR . $id . '_notes.txt';
        file_put_contents($notes, $meta['notes'] . "\n");
        echo "  notes -> $notes\n";
    }
    echo '  received ' . ($meta['received'] ?? 'unknown') . "\n";
    sodium_memzero($plain);
}
sodium_memzero($pair);
exit($failed ? 1 : 0);
