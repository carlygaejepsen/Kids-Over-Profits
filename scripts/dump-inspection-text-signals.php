<?php
/**
 * Writes one JSON line per FL and NC report in tmp/prod.sqlite: its id, state,
 * text and the PHP text_signals (api/lib-inspection-text-signals.php). Read by
 * scripts/test-inspection-text-signals.js, which runs the JavaScript readers
 * on the same text and compares.
 *
 *   php.exe -n -d extension_dir=<php>/ext -d extension=mbstring -d extension=pdo_sqlite \
 *       scripts/dump-inspection-text-signals.php <out.jsonl> [--db=tmp/prod.sqlite]
 */

if (PHP_SAPI !== 'cli') exit("CLI only.\n");

require dirname(__DIR__) . '/api/lib-inspection-text-signals.php';

$out = $argv[1] ?? '';
$db = dirname(__DIR__) . '/tmp/prod.sqlite';
foreach ($argv as $a) {
    if (strpos($a, '--db=') === 0) $db = substr($a, 5);
}
if ($out === '' || !is_file($db)) {
    fwrite(STDERR, "Usage: dump-inspection-text-signals.php <out.jsonl> [--db=tmp/prod.sqlite] (no mirror at $db?)\n");
    exit(1);
}

$pdo = new PDO('sqlite:' . $db);
$fh = fopen($out, 'wb');
$n = 0;
foreach (kop_its_states() as $state) {
    $stmt = $pdo->prepare("SELECT r.id, r.raw_content FROM inspection_reports r
        JOIN inspection_facilities f ON f.id = r.facility_id WHERE f.state = ? ORDER BY r.id");
    $stmt->execute(array($state));
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $text = (string) $row['raw_content'];
        fwrite($fh, json_encode(array(
            'id'    => (int) $row['id'],
            'state' => $state,
            'text'  => $text,
            'php'   => kop_inspection_text_signals($state, $text),
            'has_text' => kop_its_trim($text) !== '',
        ), JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) . "\n");
        $n++;
    }
}
fclose($fh);
fwrite(STDERR, "$n reports written to $out\n");
