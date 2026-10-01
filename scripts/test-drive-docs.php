<?php
/**
 * Offline checks for Drive Docs (inc/drive-docs.php) and the resourceLinks
 * field, against the production mirror in tmp/prod.sqlite.
 *
 *   php scripts/test-drive-docs.php [--db=tmp/prod.sqlite] [--links=tmp/gdocs/links.json]
 *
 * Every link scripts/gdocs-extract.py ties to a record, and that goes on the
 * record (resource links and program websites), is added to that record's
 * real document. The document then goes through the save's normalize step and
 * must pass the validator with every link still there, labelled and of the
 * right kind; adding one again is refused; Undo gives back the document
 * exactly as it was; and the facility page lists the links by kind, keeping
 * live addresses except for 'other', which is shown as a snapshot. The MySQL
 * writes, locks and queue inserts are not exercised here.
 */

if (PHP_SAPI !== 'cli') {
    exit("CLI only.\n");
}

$args = getopt('', array('db::', 'links::'));
$db_path = $args['db'] ?? (dirname(__DIR__) . '/tmp/prod.sqlite');
$links_path = $args['links'] ?? (dirname(__DIR__) . '/tmp/gdocs/links.json');
if (!file_exists($db_path) || !file_exists($links_path)) {
    fwrite(STDERR, "Need $db_path (scripts/sync-prod-sqlite.py) and $links_path (scripts/gdocs-extract.py).\n");
    exit(2);
}

require __DIR__ . '/kop-test-harness.php';
require_once dirname(__DIR__) . '/inc/drive-docs.php';

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

echo "-- The field --\n";
$list = kop_facility_resource_link_list(array(
    'https://www.reddit.com/r/troubledteens/x/',
    array('url' => 'http://reddit.com/r/troubledteens/x', 'label' => 'repeat'),
    array('url' => 'https://example.org/a', 'label' => ' A ', 'kind' => 'people', 'source' => 'Google Doc: D'),
    array('url' => 'javascript:alert(1)', 'kind' => 'news'),
    array('url' => 'https://example.org/b', 'kind' => 'nonsense'),
));
$check('repeats (scheme, www., trailing slash) and non-http entries dropped', count($list) === 3, count($list) . ' kept');
$check('a bare string becomes a link of kind other', $list[0]['kind'] === 'other' && $list[0]['label'] === '');
$check('label trimmed, kind and source kept', $list[1]['label'] === 'A' && $list[1]['kind'] === 'people' && $list[1]['source'] === 'Google Doc: D');
$check('an unknown kind is other', $list[2]['kind'] === 'other');
$empty = kop_facility_normalize(array('identification' => array('name' => 'X')), array('facility_id' => 1));
$check('every document has resourceLinks', array_key_exists('resourceLinks', $empty) && $empty['resourceLinks'] === array());

echo "-- Targets --\n";
$check('news goes to the news queue', kop_gdl_default_target('news') === 'news');
$check('court records go to lawsuits, bills to legislation', kop_gdl_default_target('court') === 'lawsuit' && kop_gdl_default_target('legislation') === 'legislation');
$check("the program's site goes to its website links", kop_gdl_default_target('program_site') === 'website');
foreach (array('inspection' => 'licensing', 'social' => 'social', 'people' => 'people', 'advertising' => 'advertising',
               'reference' => 'reference', 'government' => 'government', 'archive' => 'archive', 'other' => 'other') as $k => $rk) {
    $check("$k goes to resource links as $rk", kop_gdl_default_target($k) === 'resource' && kop_gdl_resource_kind($k) === $rk);
}
$check('a close-name match starts unticked', !kop_gdl_sure_match(array('facility_id' => 5, 'facility_how' => 'folder (close name)'))
    && kop_gdl_sure_match(array('facility_id' => 5, 'facility_how' => 'name in text')));

echo "-- Every link the build ties to a record --\n";
$items = json_decode(file_get_contents($links_path), true);
$by = array();
$skipped = 0;
foreach ($items as $it) {
    if (!empty($it['on_file']) || ($it['category'] ?? '') === 'internal' || empty($it['facility']['id'])) {
        $skipped++;
        continue;
    }
    $target = kop_gdl_default_target($it['category']);
    if (!kop_gdl_needs_facility($target)) {
        continue;
    }
    $by[(int) $it['facility']['id']][] = array(
        'pkey' => kop_gdl_pkey($it['key']), 'url' => $it['url'], 'label' => (string) ($it['label'] ?? ''),
        'kind' => $it['category'], 'source_doc' => (string) ($it['seen'][0]['doc'] ?? ''), 'target' => $target,
    );
}
printf("  %d links on %d records\n", array_sum(array_map('count', $by)), count($by));

$stmt = $pdo->prepare('SELECT json_data FROM facilities_v2 WHERE id = ?');
$added = $refused = $invalid = $lost = $twice_bad = $undo_bad = $page_bad = 0;
$examples = array();
foreach ($by as $fid => $rows) {
    $stmt->execute(array($fid));
    $doc = json_decode((string) $stmt->fetchColumn(), true);
    if (!is_array($doc)) {
        $examples[] = "#$fid missing";
        continue;
    }
    $base = $normalize($doc);
    $work = $base;
    $done = array();
    foreach ($rows as $r) {
        try {
            $d = kop_gdl_doc_add($work, $r, $r['target']);
            $done[] = array($r, $d);
            $added++;
        } catch (RuntimeException $e) {
            $refused++;
        }
    }
    $saved = $normalize($work);
    $errors = array_filter(kop_facility_validate($saved), function ($v) { return $v['severity'] === 'error'; });
    if ($errors) {
        $invalid++;
        $examples[] = "#$fid invalid: " . reset($errors)['message'];
    }
    foreach ($done as list($r, $d)) {
        $key = kop_gdl_url_key($r['url']);
        if ($d['target'] === 'website') {
            $ok = (bool) array_filter($saved['profileLinks'], function ($u) use ($key) { return kop_gdl_url_key($u) === $key; });
        } else {
            $ok = (bool) array_filter($saved['resourceLinks'], function ($l) use ($key, $r) {
                return kop_gdl_url_key($l['url']) === $key && $l['kind'] === kop_gdl_resource_kind($r['kind']) && $l['source'] !== '';
            });
        }
        if (!$ok) {
            $lost++;
            if (count($examples) < 12) $examples[] = "#$fid lost after save: " . $r['url'];
        }
        try {
            $again = $saved;
            kop_gdl_doc_add($again, $r, $r['target']);
            $twice_bad++;
        } catch (RuntimeException $e) {
            // refused, as it should be
        }
    }
    $back = $saved;
    foreach (array_reverse($done) as list($r, $d)) {
        kop_gdl_doc_remove($back, $d);
    }
    $back = $normalize($back);
    unset($back['provenance']['migratedAt'], $base['provenance']['migratedAt']);
    if ($back !== $base) {
        $undo_bad++;
        if (count($examples) < 12) $examples[] = "#$fid undo differs";
    }

    $groups = kop_facility_pages_resource_links($saved['resourceLinks']);
    $n = 0;
    foreach ($groups as $g) {
        foreach ($g['links'] as $l) {
            $n++;
            $archived = strpos($l['url'], 'https://web.archive.org/web/') === 0;
            $bad = $l['label'] === '' || ($g['kind'] !== 'other' && $l['live_url'] !== '')
                || ($g['kind'] === 'other' && !$archived && $l['live_url'] !== '');
            if ($bad) {
                $page_bad++;
                if (count($examples) < 12) $examples[] = "#$fid page link wrong: " . json_encode($l);
            }
        }
    }
    if ($n !== count($saved['resourceLinks'])) {
        $page_bad++;
        $examples[] = "#$fid page shows $n of " . count($saved['resourceLinks']);
    }
}
printf("  %d added, %d refused as already on the record\n", $added, $refused);
$check('every changed record passes the validator', $invalid === 0, "$invalid invalid");
$check('every added link survives the save, with its kind and source', $lost === 0, "$lost lost");
$check('adding a link again is refused', $twice_bad === 0, "$twice_bad accepted twice");
$check('undo gives back each record exactly', $undo_bad === 0, "$undo_bad differ");
$check('the facility page lists every link, labelled, live except "other"', $page_bad === 0, "$page_bad wrong");
foreach (array_slice($examples, 0, 12) as $e) {
    echo "     $e\n";
}

echo $failures ? "\n$failures FAILED\n" : "\nAll passed.\n";
exit($failures ? 1 : 0);
