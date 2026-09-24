<?php
/**
 * Offline test of the anonymous portal's encryption (inc/features.php).
 *
 *   php -d extension=sodium scripts/test-anon-portal.php
 *
 * Uses a throwaway key pair and a temp upload folder. Checks that a sealed
 * submission opens with scripts/anon-portal-decrypt.php, that the portal
 * refuses to store anything without a key, and that the migration of older
 * plaintext submissions seals them and only then deletes the plaintext.
 */

if (!function_exists('sodium_crypto_box_keypair')) {
    fwrite(STDERR, "Run with: php -d extension=sodium scripts/test-anon-portal.php\n");
    exit(1);
}

define('ABSPATH', __DIR__ . '/');
$tmp = sys_get_temp_dir() . '/kop-anon-test-' . bin2hex(random_bytes(4));
mkdir($tmp . '/uploads', 0700, true);

class KopTestStop extends Exception {}

function add_action() {}
function add_shortcode() {}
function wp_upload_dir() { global $tmp; return array('basedir' => $tmp . '/uploads'); }
function wp_mkdir_p($d) { return is_dir($d) || mkdir($d, 0700, true); }
function wp_json_encode($v) { return json_encode($v); }
function current_user_can() { return true; }
function check_admin_referer() { return true; }
function admin_url($p = '') { return 'https://example.test/wp-admin/' . $p; }
function wp_safe_redirect($u) { throw new KopTestStop($u); }
function wp_die($m) { throw new KopTestStop('die: ' . $m); }
function wp_send_json_error($d) { throw new KopTestStop('error: ' . $d['message']); }

require __DIR__ . '/../inc/features.php';

$fails = 0;
function check($ok, $label) {
    global $fails;
    echo ($ok ? 'ok   ' : 'FAIL ') . $label . "\n";
    if (!$ok) $fails++;
}

$pair = sodium_crypto_box_keypair();
$key_file = $tmp . '/private.key';
file_put_contents($key_file, base64_encode($pair));

$portal = new AnonymousDocPortal();
$ref = new ReflectionClass($portal);
$call = static function ($method, ...$args) use ($portal, $ref) {
    $m = $ref->getMethod($method);
    $m->setAccessible(true);
    return $m->invoke($portal, ...$args);
};
$up = $tmp . '/uploads/anonymous-submissions/';

// 1. No key file and no constant: no key, so uploads must be refused.
if (!is_file(__DIR__ . '/../inc/anonymous-portal-public.key')) {
    check($call('public_key') === '', 'no key configured -> public_key() is empty');
}

// 2. Seal and open with the decrypt script.
$doc = random_bytes(5000) . "end";
$sealed = $call('seal_submission', sodium_crypto_box_publickey($pair), $doc, array(
    'id' => 'sub_abc123', 'name' => 'report "draft".pdf', 'notes' => "line one\nline two", 'received' => '2026-09-24T00:00:00+00:00',
));
check(strpos($sealed, "draft") === false, 'sealed bytes do not contain the filename');
file_put_contents($up . 'sub_abc123.sealed', $sealed);

$php = escapeshellarg(PHP_BINARY);
$ext = "-d extension=sodium";
$cmd = "$php -n -d extension_dir=" . escapeshellarg(ini_get('extension_dir')) . " $ext "
     . escapeshellarg(__DIR__ . '/anon-portal-decrypt.php') . ' ' . escapeshellarg($key_file) . ' '
     . escapeshellarg($tmp . '/out') . ' ' . escapeshellarg($up . 'sub_abc123.sealed') . ' 2>&1';
exec($cmd, $out, $code);
$opened = glob($tmp . '/out/sub_abc123_*');
check($code === 0, 'decrypt script exits 0' . ($code ? ': ' . implode(' | ', $out) : ''));
$docs = array_values(array_filter($opened, static function ($f) { return substr($f, -10) !== '_notes.txt'; }));
check(count($docs) === 1 && file_get_contents($docs[0]) === $doc, 'document round-trips byte for byte');
check(is_file($tmp . '/out/sub_abc123_notes.txt') && trim(file_get_contents($tmp . '/out/sub_abc123_notes.txt')) === "line one\nline two", 'notes round-trip');

// 3. A wrong key cannot open it.
file_put_contents($tmp . '/wrong.key', base64_encode(sodium_crypto_box_keypair()));
exec(str_replace(escapeshellarg($key_file), escapeshellarg($tmp . '/wrong.key'), $cmd), $out2, $code2);
check($code2 !== 0, 'wrong key is rejected');

// 4. Migration of plaintext submissions, with the key in the constant.
define('KOP_ANON_PORTAL_PUBLIC_KEY', base64_encode(sodium_crypto_box_publickey($pair)));
check($call('public_key') === sodium_crypto_box_publickey($pair), 'constant key is read');
file_put_contents($up . 'sub_aaaa1111_leak.pdf', 'PDF-ONE');
file_put_contents($up . 'sub_aaaa1111_notes.txt', 'the notes');
file_put_contents($up . 'sub_bbbb2222_notes.txt', 'a document that happens to be named notes.txt');
try {
    $portal->handle_encrypt_existing();
} catch (KopTestStop $e) {
    check(strpos($e->getMessage(), 'encrypted=2') !== false, 'migration reports 2 submissions: ' . $e->getMessage());
}
$left = array_values(array_diff(scandir($up), array('.', '..', '.htaccess', 'index.php')));
sort($left);
check($left === array('sub_aaaa1111.sealed', 'sub_abc123.sealed', 'sub_bbbb2222.sealed'), 'only sealed files remain: ' . implode(', ', $left));

exec(str_replace(escapeshellarg($up . 'sub_abc123.sealed'), escapeshellarg($up . 'sub_aaaa1111.sealed') . ' ' . escapeshellarg($up . 'sub_bbbb2222.sealed'), $cmd), $out3, $code3);
check($code3 === 0 && @file_get_contents($tmp . '/out/sub_aaaa1111_leak.pdf') === 'PDF-ONE', 'migrated document opens');
check(trim((string) @file_get_contents($tmp . '/out/sub_aaaa1111_notes.txt')) === 'the notes', 'migrated notes open');
check(@file_get_contents($tmp . '/out/sub_bbbb2222_notes.txt') === 'a document that happens to be named notes.txt', 'a lone notes.txt is kept as the document');

// Clean up the temp folder.
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tmp, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
foreach ($it as $f) { $f->isDir() ? rmdir($f) : unlink($f); }
rmdir($tmp);

echo $fails ? "\n$fails failed\n" : "\nall passed\n";
exit($fails ? 1 : 0);
