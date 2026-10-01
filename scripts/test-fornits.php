<?php
/**
 * Offline checks for KOP Tools > Fornits (inc/fornits.php), against the
 * production mirror in tmp/prod.sqlite and a batch from
 * scripts/fornits-process.py (--no-upload makes one without uploading).
 *
 *   php scripts/test-fornits.php [--db=tmp/prod.sqlite] [--batch=tmp/fornits/upload/batch-*.jsonl.gz]
 *
 * Every discussion link in the batch goes on its record's real document; a
 * staff entry, an incident line, a survivor account and a lead (closure and
 * note) go on a sample of them. Each document then goes through the save's
 * normalize step and must pass the validator with the addition still there;
 * adding it again is refused; Undo gives back the document exactly as it was;
 * and a survivor account never reaches a public payload. Also the quote check,
 * the reading chunks and the prompt. The MySQL tables, locks, queues and Groq
 * are not exercised here.
 */

if (PHP_SAPI !== 'cli') {
    exit("CLI only.\n");
}

$args = getopt('', array('db::', 'batch::'));
$db_path = $args['db'] ?? (dirname(__DIR__) . '/tmp/prod.sqlite');
$batches = glob(dirname(__DIR__) . '/tmp/fornits/upload/batch-*.jsonl.gz') ?: array();
sort($batches);
$batch = $args['batch'] ?? end($batches);
if (!file_exists($db_path) || !$batch || !file_exists($batch)) {
    fwrite(STDERR, "Need $db_path (scripts/sync-prod-sqlite.py) and a batch (python scripts/fornits-process.py --no-upload).\n");
    exit(2);
}

require __DIR__ . '/kop-test-harness.php';
require_once dirname(__DIR__) . '/inc/facility-finder.php';
require_once dirname(__DIR__) . '/inc/woodbury-facts.php';
require_once dirname(__DIR__) . '/inc/drive-docs.php';
require_once dirname(__DIR__) . '/inc/fornits.php';

$failures = 0;
$check = function ($label, $ok, $detail = '') use (&$failures) {
    if (!$ok) $failures++;
    echo ($ok ? 'PASS ' : 'FAIL ') . $label . ($detail !== '' ? "  ($detail)" : '') . "\n";
};
$normalize = function (array $doc) {
    $out = kop_facility_normalize(kop_facility_to_legacy($doc), array(
        'facility_id' => $doc['facility_id'] ?? null,
        'unique_name' => $doc['provenance']['uniqueName'] ?? '',
    ));
    $out['provenance'] = $doc['provenance'] ?? $out['provenance'];
    return $out;
};

echo "-- Quote check --\n";
$post = "I was sent to Thayer in 2004. The staff made us stand against the wall for hours, and Mr. Smith would kick us if we moved.";
$check('a word-for-word quote is found', kop_fornits_quote_found('The staff made us stand against the wall for hours', $post));
$check('punctuation and case do not matter', kop_fornits_quote_found('the STAFF made us stand, against the wall', $post));
$check('a trimmed start still counts by its last words', kop_fornits_quote_found('xx yy, and Mr. Smith would kick us if we moved', $post));
$check('a made-up quote is not found', !kop_fornits_quote_found('The staff were kind and fed us well every day', $post));
$check('three words are too few to check', !kop_fornits_quote_found('I was sent', $post));

echo "-- Reading in parts --\n";
$posts = array();
for ($i = 0; $i < 60; $i++) {
    $posts[] = array('n' => $i, 'author' => 'A' . $i, 'date' => '2005-01-01T00:00:00', 'text' => $i % 5 === 0 ? 'lol' : str_repeat("word{$i} ", 300));
}
$chunks = kop_fornits_chunks($posts);
$check('a long topic is read in parts of about 22,000 characters', count($chunks) > 1 && max(array_map('mb_strlen', $chunks)) <= 22000 + 9100);
$check('at most eight parts', count(kop_fornits_chunks(array_merge($posts, $posts, $posts, $posts))) <= 8);
$check('a post too short to say anything is left out', strpos(implode('', $chunks), '[#5]') === false && strpos($chunks[0], '[#1]') !== false);
$prompt = kop_fornits_prompt(array('title' => 'T', 'board_name' => 'B', 'facilities' => array(array('id' => 11449, 'name' => 'Thayer Learning Center', 'state' => 'MO')),
    'mentions' => array(array('id' => 12539, 'name' => 'Hidden Lake Academy', 'state' => 'GA'))), $chunks[0]);
$check('the prompt lists the facilities by id', strpos($prompt, 'id 11449: Thayer Learning Center (MO)') !== false && strpos($prompt, 'id 12539') !== false);

echo "-- Lead targets --\n";
$check('a news story with its link goes to the news queue', kop_fornits_lead_target(array('type' => 'news', 'url' => 'https://x.org/a')) === 'news');
$check('a news story without a link is a note', kop_fornits_lead_target(array('type' => 'news', 'url' => '')) === 'note');
$check('a closure marks the facility closed', kop_fornits_lead_target(array('type' => 'closure', 'url' => '')) === 'closed');
$check('a post address starts the topic at that post', kop_fornits_post_url(6571, 4) === 'https://www.fornits.com/phpbb/index.php?topic=6571.4');

echo "-- Every discussion link in the batch --\n";
$by = array();
$fh = gzopen($batch, 'rb');
while (($line = gzgets($fh)) !== false) {
    $t = json_decode($line, true);
    foreach ((array) $t['facilities'] as $f) {
        $first = $t['posts'][0];
        $by[(int) $f['id']][] = array(
            'pkey' => 'x', 'kind' => 'link', 'topic_id' => $t['topic'], 'post_n' => 0, 'author' => $first['author'], 'post_date' => $first['date'],
            'label' => 'Fornits: ' . $t['title'], 'quote' => '', 'value' => json_encode(array('url' => $t['url'], 'board' => $t['board_name'])),
        );
    }
}
gzclose($fh);
printf("  %d links on %d records (%s)\n", array_sum(array_map('count', $by)), count($by), basename($batch));

$stmt = $pdo->prepare('SELECT json_data FROM facilities_v2 WHERE id = ?');
$added = $invalid = $lost = $twice_bad = $undo_bad = $missing = 0;
$examples = array();
$sample = array();
foreach ($by as $fid => $rows) {
    $stmt->execute(array($fid));
    $doc = json_decode((string) $stmt->fetchColumn(), true);
    if (!is_array($doc)) {
        $missing++;
        $examples[] = "#$fid missing";
        continue;
    }
    if (count($sample) < 40) {
        $sample[$fid] = $doc;
    }
    $base = $normalize($doc);
    $work = $base;
    $done = array();
    foreach ($rows as $r) {
        try {
            $done[] = array($r, kop_fornits_doc_apply($work, $r, ''));
            $added++;
        } catch (RuntimeException $e) {
            // the same topic twice for one record: refused, as it should be
        }
    }
    $saved = $normalize($work);
    $errors = array_filter(kop_facility_validate($saved), function ($v) { return $v['severity'] === 'error'; });
    if ($errors) {
        $invalid++;
        $examples[] = "#$fid invalid: " . reset($errors)['message'];
    }
    foreach ($done as list($r, $d)) {
        $url = json_decode($r['value'], true)['url'];
        $ok = (bool) array_filter($saved['resourceLinks'], function ($l) use ($url) {
            return kop_gdl_url_key($l['url']) === kop_gdl_url_key($url) && $l['kind'] === 'social' && strpos($l['source'], 'Fornits forum') === 0;
        });
        if (!$ok) {
            $lost++;
        }
        try {
            $again = $saved;
            kop_fornits_doc_apply($again, $r, '');
            $twice_bad++;
        } catch (RuntimeException $e) {
        }
    }
    $undone = $saved;
    foreach ($done as list($r, $d)) {
        kop_fornits_doc_undo($undone, $d);
    }
    if ($normalize($undone) != $base) {
        $undo_bad++;
        if (count($examples) < 12) $examples[] = "#$fid undo differs";
    }
}
$check("every link added ($added)", $added > 0 && !$missing, $missing ? "$missing records missing" : '');
$check('every document still valid', !$invalid, $invalid . ' invalid');
$check('every link kept by the save, as a survivor discussion citing Fornits', !$lost, $lost . ' lost');
$check('adding one again is refused', !$twice_bad, $twice_bad . ' added twice');
$check('Undo gives the document back exactly', !$undo_bad, $undo_bad . ' differ');

echo "-- Staff, incidents, survivor accounts and leads on " . count($sample) . " records --\n";
$kinds = array(
    'staff'     => array('person' => 'Jane Q. Example', 'role' => 'Therapist', 'years' => '2004'),
    'incident'  => array('category' => 'restraint', 'year' => '2004', 'summary' => 'A student was restrained face down by three staff'),
    'testimony' => array('who' => 'survivor', 'years' => '2003-2004', 'summary' => 'Held for eleven months'),
    'closed'    => array('type' => 'closure', 'year' => '2009', 'summary' => 'The program closed after a state investigation', 'url' => ''),
    'note'      => array('type' => 'investigation', 'year' => '', 'summary' => 'Posters say the state looked into a death', 'url' => ''),
);
$bad = array_fill_keys(array_keys($kinds), 0);
$testimony_public = 0;
foreach ($sample as $fid => $doc) {
    foreach ($kinds as $k => $v) {
        $r = array('pkey' => 'x', 'kind' => in_array($k, array('closed', 'note'), true) ? 'lead' : $k, 'topic_id' => 999, 'post_n' => 3,
            'author' => 'SomePoster', 'post_date' => '2005-03-12T10:00:00', 'label' => 'L', 'value' => json_encode($v),
            'quote' => 'They held me face down for an hour while I screamed that I could not breathe.');
        $base = $normalize($doc);
        $work = $base;
        try {
            $d = kop_fornits_doc_apply($work, $r, $k);
        } catch (RuntimeException $e) {
            if ($k === 'closed' && ($base['operatingPeriod']['status'] ?? '') === 'Closed') {
                continue;
            }
            $bad[$k]++;
            $examples[] = "#$fid $k refused: " . $e->getMessage();
            continue;
        }
        $saved = $normalize($work);
        $errors = array_filter(kop_facility_validate($saved), function ($x) { return $x['severity'] === 'error'; });
        $there = $k === 'staff' ? (bool) array_filter(array_merge((array) $saved['staff']['administrator'], (array) $saved['staff']['notableStaff']),
                function ($s) { return is_array($s) && ($s['name'] ?? '') === 'Jane Q. Example'; })
            : ($k === 'incident' ? (bool) preg_grep('/^2004: Restraint: A student was restrained.*Fornits forum, post by SomePoster, March 2005/', (array) ($saved['criticalIncidents']['customIncidents'] ?? array()))
            : ($k === 'testimony' ? (bool) array_filter($saved['survivorTestimony'], function ($t) { return $t['id'] === 'fornits-999-3' && $t['publish'] === false && $t['date'] === '2005-03-12'; })
            : ($k === 'closed' ? $saved['operatingPeriod']['status'] === 'Closed'
            : (bool) preg_grep('/^Fornits lead \(investigation\)/', (array) $saved['notes']))));
        if ($errors || !$there) {
            $bad[$k]++;
            if (count($examples) < 20) $examples[] = "#$fid $k " . ($errors ? 'invalid: ' . reset($errors)['message'] : 'not kept by the save');
        }
        if ($k === 'testimony') {
            $public = kop_facility_testimony_redact($saved);
            foreach ((array) $public['survivorTestimony'] as $t) {
                if (($t['id'] ?? '') === 'fornits-999-3') $testimony_public++;
            }
        }
        $undone = $saved;
        kop_fornits_doc_undo($undone, $d);
        if ($normalize($undone) != $base) {
            $bad[$k]++;
            if (count($examples) < 20) $examples[] = "#$fid $k undo differs";
        }
    }
}
foreach ($bad as $k => $n) {
    $check("$k: added, valid, kept by the save, undone exactly", !$n, $n . ' bad');
}
$check('a survivor account stays out of public payloads until published', !$testimony_public);

foreach (array_slice($examples, 0, 20) as $e) {
    echo "  $e\n";
}
echo $failures ? "\n$failures FAILED\n" : "\nAll passed\n";
exit($failures ? 1 : 0);
