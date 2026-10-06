<?php
/**
 * Offline checks for Drive Docs (inc/drive-docs.php) and the resourceLinks
 * field, against the production mirror in tmp/prod.sqlite.
 *
 *   php scripts/test-drive-docs.php [--db=tmp/prod.sqlite] [--links=tmp/gdocs/links.json]
 *       [--sciad=tmp/sciad/sciad-links.json] [--items=tmp/sciad/items.jsonl] [--python=python]
 *       [--audit=tmp/data-audit/audit-links.json]
 *
 * Every link scripts/gdocs-extract.py ties to a record, and that goes on the
 * record (resource links and program websites), is added to that record's
 * real document. The document then goes through the save's normalize step and
 * must pass the validator with every link still there, labelled and of the
 * right kind; adding one again is refused; Undo gives back the document
 * exactly as it was; and the facility page lists the links by kind, keeping
 * live addresses except for 'other', which is shown as a snapshot. The MySQL
 * writes, locks and queue inserts are not exercised here.
 *
 * SCIAD NET's rows (scripts/sciad-links.py), when the file is there, go
 * through the same record loop, and the page must credit SCIAD NET, linked,
 * under every group they fill. The file must hold no private class, no Zotero
 * user, tag, creator or abstract, and every court title must be neutral: none
 * of the words of the original case title (items.jsonl) that name a party, and
 * the fixture in `sciad-links.py --selftest`. The screen's load (by source,
 * unchanged rows left alone), filters, card paging and "Add all" selection run
 * on an in-memory copy of its table.
 */

if (PHP_SAPI !== 'cli') {
    exit("CLI only.\n");
}

$args = getopt('', array('db::', 'links::', 'sciad::', 'items::', 'python::', 'audit::'));
$db_path = $args['db'] ?? (dirname(__DIR__) . '/tmp/prod.sqlite');
$links_path = $args['links'] ?? (dirname(__DIR__) . '/tmp/gdocs/links.json');
$sciad_path = $args['sciad'] ?? (dirname(__DIR__) . '/tmp/sciad/sciad-links.json');
$audit_path = $args['audit'] ?? (dirname(__DIR__) . '/tmp/data-audit/audit-links.json');
$items_path = $args['items'] ?? (dirname(__DIR__) . '/tmp/sciad/items.jsonl');
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
$files = array('gdocs' => $links_path);
$sciad = file_exists($sciad_path) ? json_decode(file_get_contents($sciad_path), true) : null;
if (is_array($sciad)) {
    $files['sciad'] = $sciad_path;
} else {
    echo "  (no $sciad_path: run python scripts/sciad-links.py for the SCIAD NET checks)\n";
}
if (file_exists($audit_path)) {
    $files['audit'] = $audit_path; // The data audit's sources (scripts/data-audit-links.py)
}
$by = array();
$skipped = 0;
$sciad_on_record = 0;
foreach ($files as $source => $path) {
    $items = $source === 'sciad' ? $sciad : json_decode(file_get_contents($path), true);
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
            'source' => (string) ($it['source'] ?? $source),
        );
        $sciad_on_record += $source === 'sciad' ? 1 : 0;
    }
}
unset($items);
printf("  %d links on %d records (%d of them SCIAD NET's)\n", array_sum(array_map('count', $by)), count($by), $sciad_on_record);

$stmt = $pdo->prepare('SELECT json_data FROM facilities_v2 WHERE id = ?');
$added = $refused = $invalid = $lost = $twice_bad = $undo_bad = $page_bad = $credit_bad = $credit_groups = 0;
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
        // SCIAD NET's links: the group says so once, linked, with the right count.
        $want = count(array_filter($g['links'], function ($l) { return !empty($l['credit']); }));
        $credited = 0;
        foreach ($g['credits'] as $c) {
            $credited += $c['count'];
            if ($c['url'] !== 'https://web.archive.org/web/20221007171605/https://www.sciad.net/' || $c['label'] !== 'SCIAD NET') {
                $credit_bad++;
            }
        }
        $sciad_links = count(array_filter($saved['resourceLinks'], function ($l) use ($g) {
            return $l['kind'] === $g['kind'] && strpos($l['source'], 'SCIAD NET') === 0;
        }));
        if ($credited !== $want || $want !== $sciad_links) {
            $credit_bad++;
            if (count($examples) < 12) $examples[] = "#$fid {$g['kind']}: $credited credited, $sciad_links from SCIAD NET";
        }
        $credit_groups += $want ? 1 : 0;
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
if ($sciad_on_record) {
    $check('the page credits SCIAD NET, linked, under every group its links fill', $credit_bad === 0 && $credit_groups > 0,
        "$credit_groups groups, $credit_bad wrong");
}
foreach (array_slice($examples, 0, 12) as $e) {
    echo "     $e\n";
}

echo "-- Where a link came from --\n";
$credit = 'SCIAD NET';
$check('a Google Docs row names its doc', kop_gdl_source_line(array('source' => 'gdocs', 'source_doc' => 'Doc A')) === 'Google Doc: Doc A'
    && kop_gdl_source_line(array('source_doc' => 'Doc A')) === 'Google Doc: Doc A');
$check('HEAL and wiki rows name themselves', kop_gdl_source_line(array('source' => 'heal', 'source_doc' => 'HEAL archive: heal-online.org/x.pdf')) === 'HEAL archive: heal-online.org/x.pdf'
    && kop_gdl_source_line(array('source' => 'wiki', 'source_doc' => 'r/troubledteens wiki: page "X"')) === 'r/troubledteens wiki: page "X"');
$check('a data audit row names the audit', kop_gdl_source_line(array('source' => 'audit', 'source_doc' => 'KOP data audit')) === 'KOP data audit');
$check('a SCIAD NET row carries the credit, never its collection path',
    kop_gdl_source_line(array('source' => 'sciad', 'source_doc' => 'SCIAD NET: Utah / X / News')) === $credit);
$gnote = kop_gdl_queue_note(array('source' => 'gdocs', 'source_doc' => 'Doc A'), array('text' => 'words'));
$check('a Google Docs queue note is as before', $gnote === "Google Doc: Doc A\n\nWords around the link: \"words\"", $gnote);
$snote = kop_gdl_queue_note(array('source' => 'sciad', 'source_doc' => 'SCIAD NET: Utah / X / News'),
    array('text' => 'News article filed in SCIAD NET', 'archive' => 'https://web.archive.org/web/2004/http://example.com/a'));
$check('a SCIAD NET queue note credits it with its link and gives the archived copy',
    strpos($snote, 'Found in ' . $credit . ' (https://web.archive.org/web/20221007171605/https://www.sciad.net/)') === 0
    && strpos($snote, 'Archived copy: https://web.archive.org/web/2004/http://example.com/a') !== false
    && strpos($snote, 'Utah / X') === false, str_replace("\n", ' | ', $snote));
$check('the page credit is only for SCIAD NET', kop_facility_pages_resource_link_credit('SCIAD NET') !== null
    && kop_facility_pages_resource_link_credit('Google Doc: X') === null && kop_facility_pages_resource_link_credit('') === null);

if (is_array($sciad)) {
    echo "-- SCIAD NET's file --\n";
    $by_kind = array();
    $bad_source = $bad_keys = $bad_host = $bad_folder = $bad_words = $bad_drive = 0;
    $examples = array();
    $forbidden = array('abstractNote', 'abstract', 'tags', 'creators', 'createdByUser', 'username', 'creatorSummary', 'meta');
    $walk = function ($v, $path) use (&$walk, $forbidden, &$bad_keys, &$examples) {
        if (is_array($v)) {
            foreach ($v as $k => $x) {
                if (is_string($k) && in_array($k, $forbidden, true)) {
                    $bad_keys++;
                    if (count($examples) < 12) $examples[] = "key $k at $path";
                }
                $walk($x, $path . '/' . $k);
            }
        } elseif (is_string($v) && stripos($v, 'zotero.org') !== false) {
            $bad_keys++;
            if (count($examples) < 12) $examples[] = "zotero.org at $path";
        }
    };
    // The address and, inside an archive link, the original. The Fornits wiki (program pages) and a
    // site's file store (files.wordpress.com: scanned records) are not posts.
    $social = '#(^|[./])(facebook\.com|instagram\.com|tiktok\.com|twitter\.com|x\.com|reddit\.com/r/[^/]+/comments|(?<!wiki\.)fornits\.com|'
        . '(?<!files\.)wordpress\.com|blogspot\.|tumblr\.com|livejournal\.com|legacy\.com|findagrave\.com|tributes\.com|gofundme\.com)#i';
    $private_folder = '#/ (Survivor Stories|Survivor Accounts|Photos|Pictures|Photo|Police Records|Police Reports|Police Calls|Event Media|'
        . '\#breakingcodesilence Pictures|Obituaries|Support Groups|Program Records|Letters from Program Parents|Internal WWASP Emails|'
        . '2000 WWASP Hacked Parent Emails|Emails and Statements from Program Kids)\b#';
    $private_words = '#\b(yearbook|obituar|testimon|survivor|diary|diaries|intake|medical record|school record|my story|ask me anything)#i';
    foreach ($sciad as $i => $it) {
        $by_kind[$it['category']] = ($by_kind[$it['category']] ?? 0) + 1;
        if (($it['source'] ?? '') !== 'sciad') $bad_source++;
        foreach ((array) $it['seen'] as $s) {
            if (($s['credit'] ?? '') !== $credit || ($s['credit_url'] ?? '') !== 'https://web.archive.org/web/20221007171605/https://www.sciad.net/'
                || strpos((string) $s['doc'], 'SCIAD NET: ') !== 0) {
                $bad_source++;
            }
            if (preg_match($private_folder, (string) $s['doc'])) {
                $bad_folder++;
                if (count($examples) < 12) $examples[] = 'private folder: ' . $s['doc'];
            }
        }
        $walk($it, '#' . $i);
        $host = strtolower((string) parse_url($it['url'], PHP_URL_HOST)) . (string) parse_url($it['url'], PHP_URL_PATH);
        if (preg_match('#(drive|docs)\.google\.com#i', $it['url'] . ' ' . ($it['original'] ?? ''))) $bad_drive++;
        if (preg_match($social, preg_replace('#^www\.#', '', $host))) {
            $bad_host++;
            if (count($examples) < 12) $examples[] = 'social/blog host: ' . $it['url'];
        }
        if ($it['category'] !== 'court' && preg_match($private_words, $it['label'])) {
            $bad_words++;
            if (count($examples) < 12) $examples[] = 'private words: ' . $it['label'];
        }
    }
    ksort($by_kind);
    printf("  %d rows: %s\n", count($sciad), implode(', ', array_map(function ($k, $n) { return "$k $n"; }, array_keys($by_kind), $by_kind)));
    $check('every row is marked SCIAD NET and carries the credit and its link', $bad_source === 0, "$bad_source wrong");
    $check('no abstract, tag, creator or Zotero user anywhere in the file', $bad_keys === 0, "$bad_keys found");
    $check('no Drive documents (they belong to the survivor archives block)', $bad_drive === 0, "$bad_drive found");
    $check('no social, forum, personal blog or obituary site', $bad_host === 0, "$bad_host found");
    $check('nothing from a private folder (survivor stories, photos, police, letters, emails)', $bad_folder === 0, "$bad_folder found");
    $check('no private words in a title (survivor, testimony, obituary, yearbook, diary)', $bad_words === 0, "$bad_words found");
    foreach (array_slice($examples, 0, 12) as $e) {
        echo "     $e\n";
    }

    echo "-- Court titles --\n";
    // The original case titles, from the survey's item list: none of their party words may reach a label.
    $orig = array();
    if (file_exists($items_path)) {
        $fh = fopen($items_path, 'r');
        while (($line = fgets($fh)) !== false) {
            if (strpos($line, '"cat": "legal"') === false) continue;
            $r = json_decode($line, true);
            foreach (array($r['url'] ?? '', $r['orig'] ?? '') as $u) {
                if ($u) $orig[kop_gdl_url_key(preg_replace('/#.*$/', '', $u))] = $r['title'];
            }
        }
        fclose($fh);
    }
    $types = '(Criminal case(: [a-z ]+| record)|Court (record|opinion)|Amicus brief|Amended complaint|Complaint|Indictment|Deposition|Affidavit|'
        . 'Declaration|Motion to dismiss|Motion to compel|Motion for summary judgment|Motion|Discovery request|Reply|Memorandum|Order|Opinion|'
        . 'Judgment|Verdict|Settlement|Petition|Brief|Transcript|Docket|Appeal|Decision|Plea|Sentencing|Subpoena|Exhibit|Lawsuit filing)';
    $type_words = ' ' . strtolower(preg_replace('#[^A-Za-z]+#', ' ', $types)) . ' case criminal record court opinion pages parts page part ';
    $courts = array_filter($sciad, function ($it) { return $it['category'] === 'court'; });
    $shape_bad = $leaks = $matched = 0;
    $examples = array();
    foreach ($courts as $it) {
        $label = $it['label'];
        if (!preg_match('#^' . $types . '(, (page|part) \d+|, \d+ (pages|parts))?( - .+?)?( \((case no\. [\w:.-]+|\d+ [\w. ]+ \d+)\))?$#u', $label)
            || preg_match('#\bvs?\.?\s#i', $label)) {
            $shape_bad++;
            if (count($examples) < 12) $examples[] = 'not neutral: ' . $label;
        }
        $title = $orig[kop_gdl_url_key($it['url'])] ?? ($orig[kop_gdl_url_key($it['original'] ?? '')] ?? null);
        if ($title === null) continue;
        $matched++;
        $record = strtolower(implode(' ', array_filter(array($it['facility']['name'] ?? '', $it['operator']['name'] ?? '',
            preg_replace('#^SCIAD NET: #', '', $it['seen'][0]['doc'])))));
        foreach (preg_split('#\s+(?:v\.?|vs\.?)\s+|\b(?:of|by|from)\b#i', $title) as $part) {
            foreach (preg_split('#[^A-Za-z\'-]+#', $part) as $w) {
                $lw = strtolower(trim($w, "'-"));
                // The words a neutral title is made of (document types, "criminal", "case") are not names.
                if (strlen($lw) < 4 || !ctype_upper($w[0] ?? 'a') || strpos($record, $lw) !== false || strpos($type_words, " $lw ") !== false) continue;
                if (preg_match('#\b' . preg_quote($lw, '#') . '\b#i', $label)) {
                    $leaks++;
                    if (count($examples) < 12) $examples[] = "\"$lw\" from \"$title\" in \"$label\"";
                }
            }
        }
    }
    printf("  %d court rows, %d traced to their original title\n", count($courts), $matched);
    $check('every court title is neutral: document type, record, year, case number or citation', $shape_bad === 0, "$shape_bad not");
    $check('no word of the original case title that is not the record\'s own name reaches the label', $leaks === 0 && $matched > 0, "$leaks leaks");
    foreach (array_slice($examples, 0, 12) as $e) {
        echo "     $e\n";
    }
    $py = $args['python'] ?? 'python';
    $out = array();
    $code = 1;
    @exec(escapeshellarg($py) . ' ' . escapeshellarg(__DIR__ . '/sciad-links.py') . ' --selftest 2>&1', $out, $code);
    if ($out && strpos(implode("\n", $out), 'PASS') !== false) {
        $check('court titles from the made-up fixture carry no party names (sciad-links.py --selftest)', $code === 0,
            implode(' | ', array_filter($out, function ($l) { return strpos($l, 'FAIL') === 0; })));
    } else {
        echo "  SKIP the fixture: no Python ($py); run python scripts/sciad-links.py --selftest\n";
    }
}

echo "-- The screen: load, filters, paging, Add all --\n";
/** $wpdb over an in-memory table, with the writes the real one has. */
class KOP_GDL_Mem_Wpdb extends wpdb {
    private $mem;
    public function __construct(PDO $p) { parent::__construct($p); $this->mem = $p; }
    public function query($sql) {
        try { return $this->mem->exec($sql); } catch (Throwable $e) { $this->last_error = $e->getMessage(); return false; }
    }
    public function insert($table, $row) {
        $st = $this->mem->prepare("INSERT INTO {$table} (`" . implode('`,`', array_keys($row)) . '`) VALUES (' . implode(',', array_fill(0, count($row), '?')) . ')');
        try { return $st->execute(array_values($row)) ? 1 : false; } catch (Throwable $e) { return false; }
    }
    public function update($table, $data, $where) {
        $set = implode(', ', array_map(function ($k) { return "`$k` = ?"; }, array_keys($data)));
        $cond = implode(' AND ', array_map(function ($k) { return "`$k` = ?"; }, array_keys($where)));
        $st = $this->mem->prepare("UPDATE {$table} SET {$set} WHERE {$cond}");
        $st->execute(array_merge(array_values($data), array_values($where)));
        return $st->rowCount();
    }
}
$mem = new PDO('sqlite::memory:');
$mem->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$mem->exec("CREATE TABLE wpdl_kop_gdoc_links (id INTEGER PRIMARY KEY AUTOINCREMENT, pkey TEXT NOT NULL UNIQUE, url TEXT NOT NULL,
    original TEXT, domain TEXT NOT NULL DEFAULT '', kind TEXT NOT NULL DEFAULT '', label TEXT, facility_id INTEGER NOT NULL DEFAULT 0,
    facility_how TEXT NOT NULL DEFAULT '', also_named TEXT, operator_name TEXT NOT NULL DEFAULT '', source_doc TEXT NOT NULL DEFAULT '',
    source TEXT NOT NULL DEFAULT '', rhash TEXT NOT NULL DEFAULT '', seen TEXT, status TEXT NOT NULL DEFAULT 'pending', applied TEXT,
    applied_fid INTEGER NOT NULL DEFAULT 0, reviewed_by TEXT, reviewed_at TEXT, created_at TEXT NOT NULL)");
$real_wpdb = $GLOBALS['wpdb'];
$GLOBALS['wpdb'] = $wpdb = new KOP_GDL_Mem_Wpdb($mem);

$dir = sys_get_temp_dir() . '/kop-gdl-test-' . getmypid();
@mkdir($dir, 0777, true);
define('KOP_GDOCS_IMPORT_DIR', $dir);
$fixture_gdocs = array(
    array('key' => 'example.org/a', 'url' => 'https://example.org/a', 'category' => 'news', 'label' => 'A',
        'facility' => array('id' => 13624, 'name' => 'Alabama Clinical Schools', 'state' => 'AL'), 'facility_how' => 'name in text',
        'seen' => array(array('doc' => 'Doc A', 'text' => 'words'))),
    array('key' => 'example.org/b', 'url' => 'https://example.org/b', 'category' => 'reference', 'label' => 'B',
        'facility' => array('id' => 13624, 'name' => 'Alabama Clinical Schools', 'state' => 'AL'), 'facility_how' => 'folder (close name)',
        'seen' => array(array('doc' => 'Doc A'))),
    array('key' => 'example.org/c', 'url' => 'https://example.org/c', 'category' => 'other', 'label' => 'C', 'seen' => array(array('doc' => 'Doc B'))),
    array('key' => 'example.org/held', 'url' => 'https://example.org/held', 'category' => 'news', 'on_file' => array(array('type' => 'news', 'id' => 1))),
);
// A slice of the real SCIAD NET file (or a stand-in when it is not built), with its biggest facility card in full.
if (is_array($sciad)) {
    $count = array();
    foreach ($sciad as $it) {
        if (!empty($it['facility']['id'])) $count[$it['facility']['id']] = ($count[$it['facility']['id']] ?? 0) + 1;
    }
    arsort($count);
    $big = (int) key($count);
    $fixture_sciad = array_values(array_filter($sciad, function ($it) use ($big) { return (int) ($it['facility']['id'] ?? 0) === $big; }));
    foreach ($sciad as $i => $it) {
        if ($i % 40 === 0 && (int) ($it['facility']['id'] ?? 0) !== $big) $fixture_sciad[] = $it;
    }
} else {
    $big = 13624;
    $fixture_sciad = array();
    for ($i = 0; $i < 90; $i++) {
        $fixture_sciad[] = array('key' => "sciad.example/$i", 'url' => "https://sciad.example/$i", 'category' => $i % 3 ? 'news' : 'reference',
            'label' => "S$i", 'source' => 'sciad', 'facility' => $i < 60 ? array('id' => $big + ($i % 20 === 0 ? 1 : 0)) : null,
            'facility_how' => 'SCIAD collection (exact name)', 'operator' => $i >= 80 ? array('name' => 'Op') : null,
            'seen' => array(array('doc' => 'SCIAD NET: X / Y / News', 'credit' => $credit)));
    }
}
file_put_contents("$dir/links.json", json_encode($fixture_gdocs));
file_put_contents("$dir/sciad-links.json", json_encode($fixture_sciad));
$n_sciad = count(array_unique(array_map(function ($it) { return kop_gdl_pkey($it['key']); }, $fixture_sciad)));

$check('the screen reads Google Docs, HEAL, wiki, SCIAD NET and data audit files from one folder',
    array_keys(kop_gdl_sources()) === array('gdocs', 'heal', 'wiki', 'sciad', 'audit') && array_keys(kop_gdl_files()) === array('gdocs', 'sciad'));
$s1 = kop_gdl_sync(true);
$src_n = array();
foreach ($mem->query('SELECT source, COUNT(*) n FROM wpdl_kop_gdoc_links GROUP BY source')->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $src_n[$r['source']] = (int) $r['n'];
}
$check('the first load adds every row, each marked with its source (rows on file left out)',
    $s1['added'] === 3 + $n_sciad && ($src_n['gdocs'] ?? 0) === 3 && ($src_n['sciad'] ?? 0) === $n_sciad, json_encode($s1) . ' ' . json_encode($src_n));
$GLOBALS['kop_test_options']['kop_gdoc_links_md5'] = md5(implode('|', array_map('md5_file', kop_gdl_files())));
$check('the same files are not read again', kop_gdl_sync(false) === null);
$s2 = kop_gdl_sync(true);
$check('a forced reload of the same files changes nothing (unchanged rows are left alone)', $s2 === array('added' => 0, 'updated' => 0, 'gone' => 0), json_encode($s2));
$fixture_gdocs[0]['label'] = 'A, retitled';
$mem->exec("UPDATE wpdl_kop_gdoc_links SET status = 'applied', applied_fid = 13624, applied = '{\"target\":\"resource\",\"url\":\"https://example.org/c\"}' WHERE url = 'https://example.org/c'");
$fixture_gdocs[2]['label'] = 'C, retitled';
array_pop($fixture_sciad);
file_put_contents("$dir/links.json", json_encode($fixture_gdocs));
file_put_contents("$dir/sciad-links.json", json_encode($fixture_sciad));
$s3 = kop_gdl_sync(true);
$c_label = $mem->query("SELECT label FROM wpdl_kop_gdoc_links WHERE url = 'https://example.org/c'")->fetchColumn();
$check('a changed waiting row is updated, a decided one is not, one no longer offered is gone',
    $s3['added'] === 0 && $s3['updated'] === 1 && $s3['gone'] === 1 && $c_label === 'C', json_encode($s3) . " C=$c_label");

$f = kop_gdl_filters(array('gdl_src' => 'nonsense', 'gdl_kind' => 'news', 'gdl_q' => ' <b>x</b> ', 'gdl_card' => '12'));
$check('the filters keep only known sources and kinds', $f === array('kind' => 'news', 'src' => '', 'q' => 'x', 'card' => '12'), json_encode($f));
$count = function ($where) use ($mem) { return (int) $mem->query("SELECT COUNT(*) FROM wpdl_kop_gdoc_links WHERE $where")->fetchColumn(); };
$all = $count(kop_gdl_where('facility', array()));
$only_s = $count(kop_gdl_where('facility', array('src' => 'sciad')));
$only_g = $count(kop_gdl_where('facility', array('src' => 'gdocs')));
$check('the source filter splits a tab between its sources', $only_g === 2 && $only_s > 0 && $only_s + $only_g === $all, "$only_g + $only_s of $all");
$check('the words filter still works under a source', $count(kop_gdl_where('facility', array('src' => 'gdocs', 'q' => 'retitled'))) === 1);

$where = kop_gdl_where('facility', array());
list($total, $page1) = kop_gdl_card_page('facility', $where, 1, 15);
$distinct = (int) $mem->query("SELECT COUNT(DISTINCT facility_id) FROM wpdl_kop_gdoc_links WHERE $where")->fetchColumn();
$sorted = array_column($page1, 1);
$desc = $sorted;
rsort($desc);
$check('cards are counted and paged, biggest first', $total === $distinct && count($page1) === min(15, $total) && $sorted === $desc
    && (string) $page1[0][0] === (string) $big, "$total cards, first #" . $page1[0][0]);
list(, $past) = kop_gdl_card_page('facility', $where, (int) ceil($total / 15) + 1, 15);
$check('a page past the end is empty', $past === array());
list($one_total, $one) = kop_gdl_card_page('facility', $where, 1, 15, (string) $big);
$check('one card on its own', $one_total === 1 && count($one) === 1 && (string) $one[0][0] === (string) $big);
$n_big = $one[0][1];
$seen_keys = array();
$pages_ok = true;
for ($off = 0; $off < $n_big; $off += 7) {
    $rows = kop_gdl_card_rows('facility', $where, (string) $big, 7, $off);
    $pages_ok = $pages_ok && count($rows) === min(7, $n_big - $off);
    foreach ($rows as $r) {
        $seen_keys[$r['pkey']] = true;
    }
}
$check('a big card pages through every row once', $pages_ok && count($seen_keys) === $n_big, count($seen_keys) . " of $n_big");
$first_rows = kop_gdl_card_rows('facility', $where, (string) $big, KOP_GDL_CARD_ROWS);
$kinds_seen = array_column($first_rows, 'kind');
$order = array_flip(array('news', 'court', 'legislation', 'inspection', 'government', 'social', 'people', 'advertising', 'reference', 'archive', 'program_site', 'other'));
$ordered = true;
for ($i = 1; $i < count($kinds_seen); $i++) {
    $ordered = $ordered && ($order[$kinds_seen[$i - 1]] ?? 99) <= ($order[$kinds_seen[$i]] ?? 99);
}
$check('a card shows at most ' . KOP_GDL_CARD_ROWS . ' rows, news first', count($first_rows) === min(KOP_GDL_CARD_ROWS, $n_big) && $ordered);
$all_where = kop_gdl_card_all_where(13624, array());
$check('"Add all" takes a card\'s sure matches only, never a close-name match',
    $count($all_where) === $count(kop_gdl_where('facility', array()) . ' AND facility_id = 13624') - 1, $count($all_where) . ' sure');
$check('"Add all" follows the source filter', $count(kop_gdl_card_all_where($big, array('src' => 'gdocs'))) === ($big === 13624 ? 1 : 0)
    && $count(kop_gdl_card_all_where($big, array('src' => 'sciad'))) === $count(kop_gdl_where('facility', array('src' => 'sciad')) . " AND facility_id = $big"));
list($a_total) = kop_gdl_card_page('applied', kop_gdl_where('applied', array()), 1, 15);
$check('the Added tab groups by record in SQL MySQL and SQLite both read', $a_total === 1);
$pager = kop_gdl_pager(10, 40, function ($p) { return "?p=$p"; });
$check('the page links skip the middle', strpos($pager, '<strong>10</strong>') !== false && strpos($pager, '?p=1"') !== false
    && strpos($pager, '?p=40"') !== false && strpos($pager, '?p=13"') !== false && strpos($pager, '?p=20"') === false
    && substr_count($pager, '&hellip;') === 2, $pager);
$GLOBALS['wpdb'] = $real_wpdb;
@unlink("$dir/links.json");
@unlink("$dir/sciad-links.json");
@rmdir($dir);

echo $failures ? "\n$failures FAILED\n" : "\nAll passed.\n";
exit($failures ? 1 : 0);
