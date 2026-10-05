<?php
/**
 * Drive Docs: links from the owner's Google Docs and Sheets, added after review
 * (docs/PLAN.md 3.9).
 *
 * scripts/gdocs-extract.py reads the HTML/CSV export of the facility docs,
 * keeps every link with the words around it, ties it to a facility (or a
 * company) and drops what is already on file. Its links.json is copied to
 * ~/kop-import/gdocs/ (outside the web root: it quotes private docs).
 *
 * KOP Tools > Drive Docs shows one card per facility with its links. Each
 * link goes where its kind belongs, and the reviewer can send it elsewhere:
 *   - news articles: the news queue (status 'submitted'), as the browser
 *     extension sends them, naming the facility;
 *   - court records and bills: the lawsuits and legislation queues (pending);
 *   - the program's own website: the record's profileLinks;
 *   - everything else (licensing reports, survivor posts, staff profiles,
 *     reference, government pages, archive copies): the record's
 *     resourceLinks, shown on the facility page under "Materials and links".
 * Queue inserts send no admin emails. Undo takes back exactly what was added:
 * the link comes off the record, or a queue row nobody has reviewed yet is
 * removed (news is soft-deleted).
 *
 * Decisions live in {prefix}kop_gdoc_links, keyed by the link's address, so a
 * rebuild adds new links and never undoes a decision.
 *
 * Three files feed it (kop_gdl_sources()): links.json (Google Docs),
 * heal-links.json (scripts/heal-docs.py) and sciad-links.json
 * (scripts/sciad-links.py: SCIAD NET, about
 * 13,000 links). A source with a credit puts it, not a doc name, on every
 * record and queue row it fills; the facility page shows it linked. At that
 * volume the screen filters by source, caps each card at KOP_GDL_CARD_ROWS
 * rows (the rest a click away), and a facility card can add all its sure
 * matches at once in batches.
 */

if (!defined('ABSPATH')) {
    exit;
}

define('KOP_GDOC_LINKS_DB_VERSION', '2');
if (!defined('KOP_GDL_CARD_ROWS')) {
    define('KOP_GDL_CARD_ROWS', 40);
}

function kop_gdl_table() {
    global $wpdb;
    return $wpdb->prefix . 'kop_gdoc_links';
}

function kop_gdl_ensure_table() {
    if (get_option('kop_gdoc_links_db') === KOP_GDOC_LINKS_DB_VERSION) {
        return;
    }
    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    $table = kop_gdl_table();
    $charset = $wpdb->get_charset_collate();
    dbDelta("CREATE TABLE {$table} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        pkey VARCHAR(32) NOT NULL,
        url TEXT NOT NULL,
        original TEXT NULL,
        domain VARCHAR(190) NOT NULL DEFAULT '',
        kind VARCHAR(16) NOT NULL DEFAULT '',
        label TEXT NULL,
        facility_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        facility_how VARCHAR(40) NOT NULL DEFAULT '',
        also_named TEXT NULL,
        operator_name VARCHAR(255) NOT NULL DEFAULT '',
        source_doc VARCHAR(255) NOT NULL DEFAULT '',
        source VARCHAR(12) NOT NULL DEFAULT '',
        rhash VARCHAR(32) NOT NULL DEFAULT '',
        seen LONGTEXT NULL,
        status VARCHAR(12) NOT NULL DEFAULT 'pending',
        applied LONGTEXT NULL,
        applied_fid BIGINT UNSIGNED NOT NULL DEFAULT 0,
        reviewed_by VARCHAR(60) NULL,
        reviewed_at DATETIME NULL,
        created_at DATETIME NOT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY pkey (pkey),
        KEY status_fac (status, facility_id),
        KEY operator_name (operator_name(80)),
        KEY source_doc (source_doc(80)),
        KEY source_status (source, status, facility_id)
    ) {$charset};");
    // Rows from before version 2 name their source only in source_doc.
    $wpdb->query("UPDATE {$table} SET source = CASE WHEN source_doc LIKE 'HEAL archive%' THEN 'heal' "
        . "WHEN source_doc LIKE 'r/troubledteens wiki%' THEN 'wiki' ELSE 'gdocs' END WHERE source = ''");
    update_option('kop_gdoc_links_db', KOP_GDOC_LINKS_DB_VERSION);
}

function kop_gdl_path() {
    $dir = defined('KOP_GDOCS_IMPORT_DIR') ? rtrim(KOP_GDOCS_IMPORT_DIR, '/') : dirname(rtrim(ABSPATH, '/')) . '/kop-import/gdocs';
    return $dir . '/links.json';
}

/**
 * The files the screen reads, all in ~/kop-import/gdocs/: the Google Docs
 * pass, the documents from HEAL's archived site (scripts/heal-docs.py), the
 * links on the r/troubledteens wiki's pages (scripts/wiki-links.py) and SCIAD
 * NET's links (scripts/sciad-links.py). A source with a credit is named,
 * linked, on whatever it fills (owner decision 18 for SCIAD NET); the others
 * name the doc or page they came from.
 */
function kop_gdl_sources() {
    return array(
        'gdocs' => array('label' => 'Google Docs', 'file' => 'links.json'),
        'heal'  => array('label' => 'HEAL archive', 'file' => 'heal-links.json'),
        'wiki'  => array('label' => 'r/troubledteens wiki', 'file' => 'wiki-links.json'),
        'sciad' => array(
            'label'      => 'SCIAD NET',
            'file'       => 'sciad-links.json',
            'credit'     => 'SCIAD NET',
            'credit_url' => 'https://web.archive.org/web/20221007171605/https://www.sciad.net/',
            'submitter'  => 'SCIAD NET import',
        ),
    );
}

/** source => readable path, for the files that are there. */
function kop_gdl_files() {
    $dir = dirname(kop_gdl_path());
    $out = array();
    foreach (kop_gdl_sources() as $key => $s) {
        $p = $dir . '/' . $s['file'];
        if (is_readable($p)) {
            $out[$key] = $p;
        }
    }
    return $out;
}

/** Every links file the screen reads (kop_gdl_sources()). */
function kop_gdl_paths() {
    return array_values(kop_gdl_files());
}

/** The build's kinds, as the review screen names them. */
function kop_gdl_kinds() {
    return array(
        'news'         => 'News article',
        'court'        => 'Court record',
        'legislation'  => 'Legislation',
        'inspection'   => 'Licensing/inspection report',
        'government'   => 'Government page',
        'social'       => 'Survivor/social post',
        'people'       => 'Person (profile, obituary, company)',
        'advertising'  => 'Advertising/marketing listing',
        'reference'    => 'Reference',
        'archive'      => 'Archive copy',
        'program_site' => "Program's own site",
        'other'        => 'Other',
    );
}

/** Where a link can go. */
function kop_gdl_targets() {
    return array(
        'resource'    => 'Resource link on the facility page',
        'news'        => 'News queue',
        'website'     => "Program's website (profile links)",
        'lawsuit'     => 'Lawsuits queue',
        'legislation' => 'Legislation queue',
    );
}

function kop_gdl_default_target($kind) {
    $map = array('news' => 'news', 'court' => 'lawsuit', 'legislation' => 'legislation', 'program_site' => 'website');
    return $map[$kind] ?? 'resource';
}

/** A build kind as a resourceLinks kind (kop_facility_resource_link_kinds()). */
function kop_gdl_resource_kind($kind) {
    $map = array('inspection' => 'licensing', 'court' => 'court', 'government' => 'government', 'news' => 'news',
        'social' => 'social', 'people' => 'people', 'advertising' => 'advertising', 'reference' => 'reference',
        'archive' => 'archive');
    return $map[$kind] ?? 'other';
}

/** Facility links that need a record (the queues do not). */
function kop_gdl_needs_facility($target) {
    return in_array($target, array('resource', 'website'), true);
}

function kop_gdl_pkey($key) {
    return substr(md5((string) $key), 0, 16);
}

/**
 * Load a new links.json. New links are added, waiting ones take the build's
 * latest match, decided ones are left alone, and waiting ones the build no
 * longer offers (now on file, or gone from the docs) are marked 'gone'.
 */
function kop_gdl_sync($force = false) {
    $files = kop_gdl_files();
    if (!$files) {
        return null;
    }
    $md5 = md5(implode('|', array_map('md5_file', $files)));
    if (!$force && get_option('kop_gdoc_links_md5') === $md5) {
        return null;
    }
    if (function_exists('set_time_limit')) {
        @set_time_limit(300); // The first SCIAD NET load is about 13,000 rows.
    }
    $items = array();
    foreach ($files as $source => $path) {
        $list = json_decode((string) file_get_contents($path), true);
        if (!is_array($list)) {
            return null; // A half-copied file: nothing is marked gone until it reads.
        }
        foreach ($list as $it) {
            if (is_array($it)) {
                $it['_source'] = $source;
                $items[] = $it;
            }
        }
        unset($list);
    }
    global $wpdb;
    $table = kop_gdl_table();
    $existing = $hashes = array();
    foreach ((array) $wpdb->get_results("SELECT pkey, status, rhash FROM {$table}", ARRAY_A) as $r) {
        $existing[$r['pkey']] = $r['status'];
        $hashes[$r['pkey']] = (string) $r['rhash'];
    }
    $kinds = kop_gdl_kinds();
    $sources = kop_gdl_sources();
    $now = current_time('mysql', true);
    $seen = array();
    $added = $updated = 0;
    $batch = array();
    foreach ($items as $it) {
        if (!empty($it['on_file']) || ($it['category'] ?? '') === 'internal') {
            continue;
        }
        $url = (string) ($it['url'] ?? '');
        if (!preg_match('#^https?://#i', $url)) {
            continue;
        }
        $pkey = kop_gdl_pkey($it['key'] ?? $url);
        if (isset($seen[$pkey])) {
            continue; // The same address in two files: the first file's row stands.
        }
        $seen[$pkey] = true;
        $places = array_values(array_filter((array) ($it['seen'] ?? array()), 'is_array'));
        $source = (string) ($it['source'] ?? '');
        $row = array(
            'url'           => $url,
            'original'      => (string) ($it['original'] ?? ''),
            'domain'        => mb_substr((string) ($it['domain'] ?? ''), 0, 190),
            'kind'          => isset($kinds[$it['category'] ?? '']) ? $it['category'] : 'other',
            'label'         => (string) ($it['label'] ?? ''),
            'facility_id'   => (int) ($it['facility']['id'] ?? 0),
            'facility_how'  => mb_substr((string) ($it['facility_how'] ?? ''), 0, 40),
            'also_named'    => wp_json_encode(array_values((array) ($it['also_named'] ?? array()))),
            'operator_name' => mb_substr((string) ($it['operator']['name'] ?? ''), 0, 255),
            'source_doc'    => mb_substr((string) ($places[0]['doc'] ?? ''), 0, 255),
            'seen'          => wp_json_encode(array_slice($places, 0, 12)),
            'source'        => isset($sources[$source]) ? $source : $it['_source'],
        );
        $row['rhash'] = md5(wp_json_encode($row));
        if (!isset($existing[$pkey])) {
            $row['pkey'] = $pkey;
            $row['status'] = 'pending';
            $row['created_at'] = $now;
            $batch[] = $row;
            if (count($batch) >= 200) {
                $added += kop_gdl_insert_rows($batch);
                $batch = array();
            }
        } elseif ($existing[$pkey] === 'gone' || ($existing[$pkey] === 'pending' && $hashes[$pkey] !== $row['rhash'])) {
            // A waiting row takes the build's latest match; an unchanged one is left alone.
            $row['status'] = 'pending';
            $wpdb->update($table, $row, array('pkey' => $pkey));
            $updated++;
        }
    }
    if ($batch) {
        $added += kop_gdl_insert_rows($batch);
    }
    $gone = 0;
    foreach ($existing as $pkey => $status) {
        if ($status === 'pending' && !isset($seen[$pkey])) {
            $gone += (int) $wpdb->update($table, array('status' => 'gone'), array('pkey' => $pkey));
        }
    }
    update_option('kop_gdoc_links_md5', $md5, false);
    return array('added' => $added, 'updated' => $updated, 'gone' => $gone);
}

/** Insert new rows a batch at a time (one row at a time when a batch is refused). Returns how many went in. */
function kop_gdl_insert_rows(array $rows) {
    global $wpdb;
    if (!$rows) {
        return 0;
    }
    $table = kop_gdl_table();
    $cols = array_keys($rows[0]);
    $one = '(' . implode(',', array_fill(0, count($cols), '%s')) . ')';
    $values = array();
    foreach ($rows as $r) {
        foreach ($cols as $c) {
            $values[] = (string) $r[$c];
        }
    }
    $sql = $wpdb->prepare("INSERT INTO {$table} (`" . implode('`,`', $cols) . '`) VALUES ' . implode(',', array_fill(0, count($rows), $one)), $values);
    if ($wpdb->query($sql) !== false) {
        return count($rows);
    }
    $n = 0;
    foreach ($rows as $r) {
        if ($wpdb->insert($table, $r) !== false) {
            $n++;
        }
    }
    return $n;
}

function kop_gdl_rows(array $pkeys) {
    global $wpdb;
    $pkeys = array_values(array_filter(array_map(function ($k) { return preg_replace('/[^a-f0-9]/', '', (string) $k); }, $pkeys)));
    if (!$pkeys) {
        return array();
    }
    $in = implode(',', array_fill(0, count($pkeys), '%s'));
    return (array) $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . kop_gdl_table() . " WHERE pkey IN ({$in})", $pkeys), ARRAY_A);
}

/* ---- Facility records ---------------------------------------------------- */

function kop_gdl_opts() {
    global $wpdb;
    $pdo = function_exists('kop_closure_pdo') ? kop_closure_pdo() : null;
    if (!$pdo) {
        throw new RuntimeException('No database connection.');
    }
    require_once get_stylesheet_directory() . '/inc/facility-v2-writer.php';
    if (!kop_v2_writes_active($pdo, $wpdb->prefix)) {
        throw new RuntimeException('Facility saves are not on facilities_v2 yet.');
    }
    return array('pdo' => $pdo, 'prefix' => $wpdb->prefix);
}

/** Save a changed document through the legacy shape, as Woodbury Facts does, so it comes out current. */
function kop_gdl_save(array $doc, array $opts) {
    $out = kop_facility_normalize(kop_facility_to_legacy($doc), array(
        'facility_id' => $doc['facility_id'] ?? null,
        'unique_name' => $doc['provenance']['uniqueName'] ?? '',
    ));
    $out['provenance'] = $doc['provenance'] ?? $out['provenance'];
    kop_facility_save($out, $opts);
}

/** Address key that ignores the scheme, www. and a trailing slash (kop_facility_resource_link_list()). */
function kop_gdl_url_key($url) {
    return strtolower(rtrim(preg_replace('#^https?://(www\.)?#i', '', trim((string) $url)), '/'));
}

/** The words that say where a link came from, for the record and the queues. */
function kop_gdl_source_line(array $r) {
    $src = kop_gdl_sources()[$r['source'] ?? ''] ?? array();
    if (!empty($src['credit'])) {
        // SCIAD NET: the credit, never the collection path (which names programs' folders, not KOP's words).
        return $src['credit'];
    }
    $doc = $r['source_doc'] !== '' ? $r['source_doc'] : 'a Google Doc';
    // HEAL's documents and the wiki's links name their own source ("HEAL archive: heal-online.org/x.pdf, saved 2009",
    // "r/troubledteens wiki: page "X" (as of 2025-12-18)").
    return (strpos($doc, 'HEAL archive') === 0 || strpos($doc, 'r/troubledteens wiki') === 0) ? $doc : 'Google Doc: ' . $doc;
}

/**
 * Add one link to a document. Returns what was done, for Undo; throws when
 * the record already has it.
 */
function kop_gdl_doc_add(array &$doc, array $r, $target) {
    $key = kop_gdl_url_key($r['url']);
    foreach (array_merge((array) ($doc['profileLinks'] ?? array()), array_column((array) ($doc['resourceLinks'] ?? array()), 'url')) as $have) {
        if (is_string($have) && kop_gdl_url_key($have) === $key) {
            throw new RuntimeException('Already on the record.');
        }
    }
    if ($target === 'website') {
        $doc['profileLinks'][] = $r['url'];
        return array('target' => 'website', 'url' => $r['url']);
    }
    $doc['resourceLinks'][] = array(
        'url'    => $r['url'],
        'label'  => (string) $r['label'],
        'kind'   => kop_gdl_resource_kind($r['kind']),
        'source' => kop_gdl_source_line($r),
    );
    return array('target' => 'resource', 'url' => $r['url']);
}

function kop_gdl_doc_remove(array &$doc, array $done) {
    $key = kop_gdl_url_key($done['url'] ?? '');
    if ($key === '') {
        return;
    }
    if (($done['target'] ?? '') === 'website') {
        $doc['profileLinks'] = array_values(array_filter((array) ($doc['profileLinks'] ?? array()), function ($u) use ($key) {
            return !is_string($u) || kop_gdl_url_key($u) !== $key;
        }));
    } else {
        $doc['resourceLinks'] = array_values(array_filter((array) ($doc['resourceLinks'] ?? array()), function ($l) use ($key) {
            return !is_array($l) || kop_gdl_url_key($l['url'] ?? '') !== $key;
        }));
    }
}

/* ---- The queues ---------------------------------------------------------- */

function kop_gdl_queue_pdo() {
    if (!function_exists('kop_ext_load_record_libs')) {
        throw new RuntimeException('The records helpers are not loaded.');
    }
    kop_ext_load_record_libs();
    $pdo = function_exists('kop_seed_pdo') ? kop_seed_pdo() : null;
    if (!$pdo) {
        throw new RuntimeException('The records database is not reachable.');
    }
    return $pdo;
}

/** The submission note a queue row carries: where it came from, with the credit's link and the archived copy. */
function kop_gdl_queue_note(array $r, array $first) {
    $src = kop_gdl_sources()[$r['source'] ?? ''] ?? array();
    $note = kop_gdl_source_line($r);
    if (!empty($src['credit_url'])) {
        $note = 'Found in ' . $note . ' (' . $src['credit_url'] . ')';
    }
    if (!empty($first['archive']) && preg_match('#^https?://#i', (string) $first['archive'])) {
        $note .= "\n\nArchived copy: " . $first['archive'];
    }
    if (!empty($first['text'])) {
        $note .= "\n\n" . (!empty($src['credit']) ? 'Entry: ' : 'Words around the link: ')
            . '"' . mb_substr((string) $first['text'], 0, 600) . '"';
    }
    return $note;
}

/** Send one link to a queue. Returns what was done; throws when it is already there. */
function kop_gdl_queue_add(PDO $pdo, array $r, $target, $facility_name, $reviewer) {
    $type = array('news' => 'article', 'lawsuit' => 'lawsuit', 'legislation' => 'legislation')[$target];
    $seen = json_decode((string) $r['seen'], true) ?: array();
    $first = $seen[0] ?? array();
    $src = kop_gdl_sources()[$r['source'] ?? ''] ?? array();
    $p = array(
        'url'       => $r['url'],
        'title'     => $r['label'] !== '' ? $r['label'] : $r['url'],
        'type'      => $type,
        // SCIAD NET rows name the outlet and the day; the others only have the address.
        'site_name' => !empty($first['outlet']) ? (string) $first['outlet'] : $r['domain'],
        'facility'  => (string) $facility_name,
    );
    if (!empty($first['published'])) {
        $p['published'] = (string) $first['published'];
    }
    $archive = !empty($first['archive']) && preg_match('#^https?://#i', (string) $first['archive']) ? (string) $first['archive'] : '';
    $dupes = kop_ext_find_duplicates($pdo, $p);
    if (!$dupes && $archive !== '') {
        $dupes = kop_ext_find_duplicates($pdo, array('url' => $archive, 'type' => $type));
    }
    if ($dupes) {
        throw new RuntimeException('Already in the ' . $dupes[0]['type'] . ' records (#' . (int) $dupes[0]['id'] . ').');
    }
    $note = kop_gdl_queue_note($r, $first);
    $submitter = mb_substr($reviewer . ' (' . ($src['submitter'] ?? 'Google Docs import') . ')', 0, 255);
    $quiet = function () { return false; };
    add_filter('kop_notify_admins_enabled', $quiet);
    try {
        if ($target === 'news') {
            $id = kop_ext_insert_news($pdo, $p, $submitter, $note);
        } elseif ($target === 'lawsuit') {
            $id = kop_ext_insert_lawsuit($pdo, $p, $submitter, $note);
        } else {
            $id = kop_ext_insert_legislation($pdo, $p, $submitter, $note);
        }
    } finally {
        remove_filter('kop_notify_admins_enabled', $quiet);
    }
    return array('target' => $target, 'id' => (int) $id);
}

/** Take a queue row back while nobody has reviewed it. */
function kop_gdl_queue_remove(PDO $pdo, array $done) {
    $id = (int) ($done['id'] ?? 0);
    if ($done['target'] === 'news') {
        $st = $pdo->prepare("UPDATE news_submissions SET status = 'deleted' WHERE id = ? AND status = 'submitted'");
        $st->execute(array($id));
        if (!$st->rowCount()) {
            throw new RuntimeException('News item #' . $id . ' has been reviewed in the news queue already; remove it there.');
        }
        return;
    }
    $table = $done['target'] === 'lawsuit' ? 'lawsuits' : 'legislation';
    $st = $pdo->prepare("DELETE FROM {$table} WHERE id = ? AND publication_status = 'pending'");
    $st->execute(array($id));
    if (!$st->rowCount()) {
        throw new RuntimeException(ucfirst($done['target']) . ' #' . $id . ' has been reviewed already; remove it there.');
    }
    if ($table === 'lawsuits') {
        $pdo->prepare('DELETE FROM lawsuit_facility_links WHERE lawsuit_id = ?')->execute(array($id));
    }
}

/* ---- Add, skip, undo ----------------------------------------------------- */

/**
 * Add links. $targets maps pkey => target (else the kind's default); $fid
 * is the record the facility links go on (0: only queue links can go).
 * Returns [pkey => result].
 */
function kop_gdl_apply(array $rows, array $targets, $fid, $reviewer) {
    global $wpdb;
    $fid = (int) $fid;
    $valid = kop_gdl_targets();
    $results = array();
    $now = current_time('mysql', true);
    $mark = function ($r, $status, $applied, $applied_fid) use ($wpdb, $reviewer, $now) {
        $wpdb->update(kop_gdl_table(), array('status' => $status, 'applied' => wp_json_encode($applied),
            'applied_fid' => (int) $applied_fid, 'reviewed_by' => $reviewer, 'reviewed_at' => $now), array('pkey' => $r['pkey']));
    };

    $facility_name = '';
    if ($fid > 0) {
        $pdo = kop_closure_pdo();
        $name = $pdo ? $pdo->prepare('SELECT name FROM facilities_v2 WHERE id = ?') : null;
        if ($name) {
            $name->execute(array($fid));
            $facility_name = (string) $name->fetchColumn();
        }
        if ($facility_name === '') {
            throw new RuntimeException("Facility #{$fid} does not exist.");
        }
    }

    $on_record = array();
    $queue_pdo = null;
    foreach ($rows as $r) {
        if ($r['status'] !== 'pending') {
            $results[$r['pkey']] = array('ok' => false, 'error' => 'Already ' . $r['status'] . '.');
            continue;
        }
        $t = (string) ($targets[$r['pkey']] ?? '');
        $t = isset($valid[$t]) ? $t : kop_gdl_default_target($r['kind']);
        if (kop_gdl_needs_facility($t)) {
            if ($fid <= 0) {
                $results[$r['pkey']] = array('ok' => false, 'error' => 'Pick the facility first.', 'keep' => true);
            } else {
                $on_record[] = array($r, $t);
            }
            continue;
        }
        try {
            $queue_pdo = $queue_pdo ?: kop_gdl_queue_pdo();
            $done = kop_gdl_queue_add($queue_pdo, $r, $t, $facility_name, $reviewer);
            $mark($r, 'applied', $done, $fid);
            $results[$r['pkey']] = array('ok' => true, 'target' => $t);
        } catch (RuntimeException $e) {
            $mark($r, 'rejected', array('reason' => $e->getMessage()), 0);
            $results[$r['pkey']] = array('ok' => false, 'error' => $e->getMessage());
        }
    }

    if ($on_record) {
        $opts = kop_gdl_opts();
        kop_v2_with_write_lock($opts['pdo'], function () use ($on_record, $fid, $opts, $mark, &$results) {
            $stored = kop_facility_load($fid, $opts);
            if (!$stored) {
                throw new RuntimeException("Facility #{$fid} does not exist.");
            }
            $doc = $stored['doc'];
            $applied = array();
            foreach ($on_record as list($r, $t)) {
                try {
                    $applied[$r['pkey']] = array($r, kop_gdl_doc_add($doc, $r, $t));
                    $results[$r['pkey']] = array('ok' => true, 'target' => $t);
                } catch (RuntimeException $e) {
                    $mark($r, 'rejected', array('reason' => $e->getMessage()), 0);
                    $results[$r['pkey']] = array('ok' => false, 'error' => $e->getMessage());
                }
            }
            if ($applied) {
                kop_gdl_save($doc, $opts);
                foreach ($applied as list($r, $done)) {
                    $mark($r, 'applied', $done, $fid);
                }
            }
        });
    }
    return $results;
}

function kop_gdl_undo(array $rows, $reviewer) {
    global $wpdb;
    $results = array();
    $back = function ($r) use ($wpdb, $reviewer) {
        $wpdb->update(kop_gdl_table(), array('status' => 'pending', 'applied' => null, 'applied_fid' => 0,
            'reviewed_by' => $reviewer, 'reviewed_at' => current_time('mysql', true)), array('pkey' => $r['pkey']));
    };
    $by_fid = array();
    $queue_pdo = null;
    foreach ($rows as $r) {
        $done = json_decode((string) $r['applied'], true) ?: array();
        if ($r['status'] === 'rejected' || $r['status'] === 'gone') {
            $back($r);
            $results[$r['pkey']] = array('ok' => true);
        } elseif ($r['status'] !== 'applied') {
            $results[$r['pkey']] = array('ok' => false, 'error' => 'Nothing to undo.');
        } elseif (in_array($done['target'] ?? '', array('resource', 'website'), true)) {
            $by_fid[(int) $r['applied_fid']][] = array($r, $done);
        } else {
            try {
                $queue_pdo = $queue_pdo ?: kop_gdl_queue_pdo();
                kop_gdl_queue_remove($queue_pdo, $done);
                $back($r);
                $results[$r['pkey']] = array('ok' => true);
            } catch (RuntimeException $e) {
                $results[$r['pkey']] = array('ok' => false, 'error' => $e->getMessage());
            }
        }
    }
    if ($by_fid) {
        $opts = kop_gdl_opts();
        foreach ($by_fid as $fid => $list) {
            kop_v2_with_write_lock($opts['pdo'], function () use ($fid, $list, $opts, $back, &$results) {
                $stored = kop_facility_load($fid, $opts);
                if (!$stored) {
                    throw new RuntimeException("Facility #{$fid} does not exist.");
                }
                $doc = $stored['doc'];
                foreach ($list as list($r, $done)) {
                    kop_gdl_doc_remove($doc, $done);
                }
                kop_gdl_save($doc, $opts);
                foreach ($list as list($r, $done)) {
                    $back($r);
                    $results[$r['pkey']] = array('ok' => true);
                }
            });
        }
    }
    return $results;
}

add_action('wp_ajax_kop_gdl_act', function () {
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Not allowed.', 403);
    }
    check_ajax_referer('kop_drive_docs', 'nonce');
    $act = sanitize_key($_POST['act'] ?? '');
    $keys = isset($_POST['keys']) && is_array($_POST['keys']) ? array_slice($_POST['keys'], 0, 400) : array();
    $rows = kop_gdl_rows($keys);
    $user = wp_get_current_user()->user_login;
    try {
        if ($act === 'apply_card') {
            // "Add all" on a facility card: its sure matches under the screen's filters, a batch per request.
            $fid = (int) ($_POST['fid'] ?? 0);
            if ($fid <= 0) {
                throw new RuntimeException('No facility on this card.');
            }
            global $wpdb;
            $where = kop_gdl_card_all_where($fid, kop_gdl_filters($_POST));
            $batch = (array) $wpdb->get_results('SELECT * FROM ' . kop_gdl_table() . " WHERE {$where} ORDER BY id LIMIT 40", ARRAY_A);
            $results = $batch ? kop_gdl_apply($batch, array(), $fid, $user) : array();
            $left = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . kop_gdl_table() . " WHERE {$where}");
            wp_send_json_success(array('results' => $results, 'left' => $left));
        }
        if (!$rows) {
            throw new RuntimeException('Nothing selected.');
        }
        if ($act === 'apply') {
            $targets = array();
            foreach ((array) ($_POST['targets'] ?? array()) as $k => $v) {
                $targets[preg_replace('/[^a-f0-9]/', '', (string) $k)] = sanitize_key($v);
            }
            $fid = (int) ($_POST['fid'] ?? 0);
            $results = kop_gdl_apply($rows, $targets, $fid, $user);
            $url = $fid && function_exists('kop_facility_page_url') ? kop_facility_page_url($fid) : '';
            $label = $fid && function_exists('kop_facility_finder_label') ? wp_strip_all_tags(kop_facility_finder_label(kop_closure_pdo(), $fid)) : '';
            wp_send_json_success(array('results' => $results, 'url' => $url, 'label' => $label));
        }
        if ($act === 'reject') {
            $now = current_time('mysql', true);
            foreach ($rows as $r) {
                if ($r['status'] === 'pending') {
                    $GLOBALS['wpdb']->update(kop_gdl_table(), array('status' => 'rejected', 'applied' => wp_json_encode(array('reason' => 'Skipped')),
                        'reviewed_by' => $user, 'reviewed_at' => $now), array('pkey' => $r['pkey']));
                }
            }
            wp_send_json_success(array('results' => array_fill_keys(array_column($rows, 'pkey'), array('ok' => true))));
        }
        if ($act === 'undo') {
            wp_send_json_success(array('results' => kop_gdl_undo($rows, $user)));
        }
        throw new RuntimeException('Unknown action.');
    } catch (Throwable $e) {
        wp_send_json_error($e->getMessage());
    }
});

/* ---- Review screen ------------------------------------------------------ */

add_action('admin_menu', function () {
    if (!function_exists('kop_tools_parent_slug')) {
        return;
    }
    add_submenu_page(kop_tools_parent_slug(), 'Drive Docs', 'Drive Docs', 'manage_options',
        'kop-drive-docs', 'kop_render_drive_docs_page');
}, 21);

function kop_gdl_tabs() {
    return array(
        'facility' => array('label' => 'For a facility', 'where' => "status = 'pending' AND facility_id > 0", 'card' => 'facility_id'),
        'company'  => array('label' => 'Company only', 'where' => "status = 'pending' AND facility_id = 0 AND operator_name <> ''", 'card' => 'operator_name'),
        'none'     => array('label' => 'No facility', 'where' => "status = 'pending' AND facility_id = 0 AND operator_name = ''", 'card' => 'source_doc'),
        'applied'  => array('label' => 'Added', 'where' => "status = 'applied'", 'card' => "CASE WHEN applied_fid > 0 THEN CAST(applied_fid AS CHAR) ELSE 'q' END"),
        'rejected' => array('label' => 'Skipped or already there', 'where' => "status = 'rejected'", 'card' => 'source_doc'),
    );
}

/** A match the screen trusts enough to tick the link to start with. */
function kop_gdl_sure_match(array $r) {
    return $r['facility_id'] > 0 && strpos($r['facility_how'], 'close') === false;
}

/** The screen's filters from a request ($_GET, or the POST of an "add all"): kind, words, source, one card. */
function kop_gdl_filters(array $in) {
    $kinds = kop_gdl_kinds();
    $sources = kop_gdl_sources();
    $text = function ($v) {
        $v = is_string($v) ? $v : '';
        return trim(sanitize_text_field(function_exists('wp_unslash') ? wp_unslash($v) : $v));
    };
    $kind = (string) ($in['gdl_kind'] ?? '');
    $src = (string) ($in['gdl_src'] ?? '');
    return array(
        'kind' => isset($kinds[$kind]) ? $kind : '',
        'src'  => isset($sources[$src]) ? $src : '',
        'q'    => $text($in['gdl_q'] ?? ''),
        'card' => $text($in['gdl_card'] ?? ''),
    );
}

/** The SQL condition for a tab under the kind, source and words filters (not the card). */
function kop_gdl_where($tab, array $f) {
    global $wpdb;
    $tabs = kop_gdl_tabs();
    $where = $tabs[$tab]['where'];
    if (($f['kind'] ?? '') !== '') {
        $where .= $wpdb->prepare(' AND kind = %s', $f['kind']);
    }
    if (($f['src'] ?? '') !== '') {
        $where .= $wpdb->prepare(' AND source = %s', $f['src']);
    }
    if (($f['q'] ?? '') !== '') {
        $like = '%' . $wpdb->esc_like($f['q']) . '%';
        $where .= $wpdb->prepare(' AND (label LIKE %s OR url LIKE %s OR source_doc LIKE %s OR operator_name LIKE %s)', $like, $like, $like, $like);
    }
    return $where;
}

/** Rows within a card in the order the screen reads them: news first. */
function kop_gdl_kind_order_sql() {
    $order = array('news', 'court', 'legislation', 'inspection', 'government', 'social', 'people', 'advertising',
        'reference', 'archive', 'program_site', 'other');
    $sql = 'CASE kind';
    foreach ($order as $i => $k) {
        $sql .= " WHEN '{$k}' THEN {$i}";
    }
    return $sql . ' ELSE 99 END';
}

/**
 * One page of cards, biggest first: array(total cards, array of array(card value, rows)).
 * With $card set, just that card.
 */
function kop_gdl_card_page($tab, $where, $paged, $per, $card = '') {
    global $wpdb;
    $table = kop_gdl_table();
    $col = kop_gdl_tabs()[$tab]['card'];
    if ($card !== '') {
        $n = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE {$where}" . $wpdb->prepare(" AND {$col} = %s", $card));
        return array($n ? 1 : 0, $n ? array(array($card, $n)) : array());
    }
    $total = (int) $wpdb->get_var("SELECT COUNT(DISTINCT {$col}) FROM {$table} WHERE {$where}");
    $list = (array) $wpdb->get_results("SELECT {$col} AS ck, COUNT(*) AS n FROM {$table} WHERE {$where} GROUP BY {$col} "
        . 'ORDER BY COUNT(*) DESC, MIN(label) LIMIT ' . (max(1, (int) $paged) - 1) * (int) $per . ', ' . (int) $per, ARRAY_A);
    return array($total, array_map(function ($r) { return array((string) $r['ck'], (int) $r['n']); }, $list));
}

/** A card's rows, $limit at a time. */
function kop_gdl_card_rows($tab, $where, $card, $limit, $offset = 0) {
    global $wpdb;
    $col = kop_gdl_tabs()[$tab]['card'];
    return (array) $wpdb->get_results('SELECT * FROM ' . kop_gdl_table() . " WHERE {$where}" . $wpdb->prepare(" AND {$col} = %s", $card)
        . ' ORDER BY ' . kop_gdl_kind_order_sql() . ', label, id LIMIT ' . (int) $offset . ', ' . (int) $limit, ARRAY_A);
}

/** What "Add all" takes for a facility card: its sure matches under the filters. */
function kop_gdl_card_all_where($fid, array $f) {
    global $wpdb;
    return kop_gdl_where('facility', $f) . $wpdb->prepare(' AND facility_id = %d AND facility_how NOT LIKE %s', (int) $fid, '%close%');
}

/** Page links: the first, the last and three either side of this one. */
function kop_gdl_pager($paged, $pages, callable $url) {
    $out = array();
    $last = 0;
    for ($n = 1; $n <= $pages; $n++) {
        if ($n !== 1 && $n !== $pages && abs($n - $paged) > 3) {
            continue;
        }
        if ($last && $n > $last + 1) {
            $out[] = '&hellip;';
        }
        $out[] = $n === $paged ? '<strong>' . $n . '</strong>' : '<a href="' . esc_url($url($n)) . '">' . $n . '</a>';
        $last = $n;
    }
    return implode(' ', $out);
}

function kop_render_drive_docs_page() {
    if (!current_user_can('manage_options')) {
        wp_die('Not authorized', 'Access Denied', array('response' => 403));
    }
    global $wpdb;
    kop_gdl_ensure_table();
    $sync = kop_gdl_sync(isset($_GET['gdl_resync']));
    $table = kop_gdl_table();
    $tabs = kop_gdl_tabs();
    $kinds = kop_gdl_kinds();
    $sources = kop_gdl_sources();
    $tab = isset($_GET['gdl_tab'], $tabs[$_GET['gdl_tab']]) ? $_GET['gdl_tab'] : 'facility';
    $f = kop_gdl_filters($_GET);
    $paged = max(1, (int) ($_GET['gdl_page'] ?? 1));
    $rpage = max(1, (int) ($_GET['gdl_rpage'] ?? 1));
    $per = 15;
    $card_rows = $f['card'] !== '' ? 200 : KOP_GDL_CARD_ROWS;
    $base = admin_url('admin.php?page=kop-drive-docs');
    $link = function (array $args) use ($base, $tab, $f) {
        $q = array_merge(array('gdl_tab' => $tab, 'gdl_kind' => $f['kind'], 'gdl_src' => $f['src'], 'gdl_q' => $f['q']), $args);
        return add_query_arg(array_map(function ($v) { return $v === '' || $v === null ? null : $v; }, $q), $base);
    };

    echo '<div class="wrap kop-gdl"><h1>Drive Docs</h1>';
    if ($sync) {
        echo '<div class="notice notice-info"><p>Loaded a new build: ' . (int) $sync['added'] . ' new, ' . (int) $sync['updated']
            . ' updated, ' . (int) $sync['gone'] . ' no longer offered.</p></div>';
    }
    if (!kop_gdl_paths()) {
        echo '<div class="notice notice-warning"><p>No links uploaded yet. Run <code>python scripts/gdocs-extract.py</code> and copy '
            . '<code>tmp/gdocs/links.json</code> to <code>' . esc_html(dirname(kop_gdl_path())) . '</code>.</p></div>';
    }
    echo '<p>Links from your Google Docs and Sheets, documents saved on HEAL\'s old site (heal-online.org, through the Wayback Machine), '
        . 'links on the r/troubledteens wiki\'s pages, and the news, court records, program pages and media in '
        . '<a href="' . esc_url($sources['sciad']['credit_url']) . '" target="_blank" rel="noopener">SCIAD NET</a>, '
        . 'that the database does not have yet, one card per facility. '
        . 'Each shows where it came from and the words around it. Pick a source to work through one at a time.</p>'
        . '<ol class="kop-gdl-how"><li><strong>Read down a card.</strong> Links whose facility is a sure match start ticked; untick anything that is not about this place.</li>'
        . '<li><strong>Check <em>Goes to</em>.</strong> News goes to the news queue, court records and bills to their queues, the program\'s own site to its website links, '
        . 'and everything else (licensing reports, survivor posts, staff profiles, reference) to the resource links on the facility page. Change it on any row.</li>'
        . '<li><strong>Click <em>Add checked</em></strong>, or <em>Add everything ticked on this page</em> at the top. Facility links show on the page at once; '
        . 'queue items wait in their queue as if sent from the browser extension, with no emails. A card with many links has <em>Add all</em>, '
        . 'which adds every sure match the card holds under the filters you picked, in batches.</li>'
        . '<li><strong>Wrong place?</strong> Pick another record in the box under the card and click <em>Add checked to that record</em>. '
        . 'On <em>Company only</em> and <em>No facility</em>, pick the record first; news can go to the queue without one.</li>'
        . '<li><strong>Changed your mind?</strong> The <em>Added</em> tab has Undo: the link comes off the record, or leaves its queue if nobody has reviewed it yet.</li></ol>';

    $where = kop_gdl_where($tab, $f);
    $src_where = $f['src'] !== '' ? $wpdb->prepare(' AND source = %s', $f['src']) : '';

    echo '<ul class="subsubsub">';
    $i = 0;
    foreach ($tabs as $k => $t) {
        $n = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE " . $t['where'] . $src_where);
        echo '<li><a href="' . esc_url(add_query_arg(array('gdl_tab' => $k, 'gdl_src' => $f['src'] !== '' ? $f['src'] : null), $base)) . '"'
            . ($tab === $k ? ' class="current"' : '') . '>'
            . esc_html($t['label']) . ' <span class="count">(' . $n . ')</span></a>' . (++$i < count($tabs) ? ' | ' : '') . '</li>';
    }
    echo '</ul><div style="clear:both"></div>';

    $by_src = array();
    foreach ((array) $wpdb->get_results("SELECT source, COUNT(*) AS n FROM {$table} WHERE " . $tabs[$tab]['where'] . ' GROUP BY source', ARRAY_A) as $r) {
        $by_src[(string) $r['source']] = (int) $r['n'];
    }
    echo '<form method="get" class="kop-gdl-filter"><input type="hidden" name="page" value="kop-drive-docs">'
        . '<input type="hidden" name="gdl_tab" value="' . esc_attr($tab) . '">'
        . '<select name="gdl_src" aria-label="Source"><option value="">Every source</option>';
    foreach ($sources as $k => $s) {
        echo '<option value="' . esc_attr($k) . '"' . selected($f['src'], $k, false) . '>' . esc_html($s['label'] . ' (' . ($by_src[$k] ?? 0) . ')') . '</option>';
    }
    echo '</select> <input type="search" name="gdl_q" value="' . esc_attr($f['q']) . '" placeholder="Words, address or doc" style="width:240px" aria-label="Search"> '
        . '<select name="gdl_kind" aria-label="Kind"><option value="">Every kind</option>';
    foreach ($kinds as $k => $label) {
        echo '<option value="' . esc_attr($k) . '"' . selected($f['kind'], $k, false) . '>' . esc_html($label) . '</option>';
    }
    echo '</select> <button class="button">Show</button>'
        . ($f['q'] !== '' || $f['kind'] !== '' || $f['src'] !== '' || $f['card'] !== '' ? ' <a href="' . esc_url(add_query_arg('gdl_tab', $tab, $base)) . '">Clear</a>' : '') . '</form>';
    if ($f['card'] !== '') {
        echo '<p><a href="' . esc_url($link(array())) . '">&larr; Every card</a></p>';
    }

    list($total, $cards) = kop_gdl_card_page($tab, $where, $paged, $per, $f['card']);
    if (!$cards) {
        echo '<p>Nothing here.</p></div>';
        kop_gdl_render_assets();
        return;
    }

    echo '<div class="kop-gdl-bar">';
    if ($tab === 'facility') {
        echo '<button type="button" class="button button-primary kop-gdl-all">Add everything ticked on this page</button> ';
    }
    echo '<span class="kop-gdl-progress" aria-live="polite"></span></div>';

    $offset = $f['card'] !== '' ? ($rpage - 1) * $card_rows : 0;
    foreach ($cards as list($c, $n)) {
        $rows = kop_gdl_card_rows($tab, $where, $c, $card_rows, $offset);
        if (!$rows) {
            continue;
        }
        $meta = array('total' => $n, 'shown' => count($rows), 'offset' => $offset, 'filters' => $f, 'all' => 0,
            'more' => $n > count($rows) + $offset && $f['card'] === '' ? $link(array('gdl_card' => $c)) : '');
        if ($tab === 'facility' && $n > 1) {
            $meta['all'] = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE " . kop_gdl_card_all_where((int) $c, $f));
        }
        kop_gdl_render_card($rows, $tab, $meta);
        if ($f['card'] !== '' && $n > $card_rows) {
            echo '<p class="kop-gdl-pager">Links ' . ($offset + 1) . '-' . ($offset + count($rows)) . ' of ' . $n . ' &middot; '
                . kop_gdl_pager($rpage, (int) ceil($n / $card_rows), function ($p) use ($link, $c) { return $link(array('gdl_card' => $c, 'gdl_rpage' => $p)); })
                . '</p>';
        }
    }

    $pages = (int) ceil($total / $per);
    if ($pages > 1 && $f['card'] === '') {
        echo '<p class="kop-gdl-pager">Page ' . $paged . ' of ' . $pages . ' (' . $total . ' cards) &middot; '
            . kop_gdl_pager($paged, $pages, function ($p) use ($link) { return $link(array('gdl_page' => $p)); }) . '</p>';
    }
    kop_gdl_render_assets();
    echo '</div>';
}

/**
 * One card. $meta (from the screen): total rows the card holds under the
 * filters, how many are shown, the "show them all" link, how many sure
 * matches "Add all" would take, and the filters it takes them under.
 */
function kop_gdl_render_card(array $rows, $tab, array $meta = array()) {
    $first = $rows[0];
    $pending = in_array($tab, array('facility', 'company', 'none'), true);
    $fid = $tab === 'applied' ? (int) $first['applied_fid'] : (int) $first['facility_id'];
    $f = $meta['filters'] ?? array();
    echo '<div class="kop-gdl-card" data-fid="' . $fid . '" data-kind="' . esc_attr($f['kind'] ?? '') . '" data-src="'
        . esc_attr($f['src'] ?? '') . '" data-q="' . esc_attr($f['q'] ?? '') . '">';
    echo '<div class="kop-gdl-head">';
    if ($tab === 'facility' || ($tab === 'applied' && $fid)) {
        $label = function_exists('kop_facility_finder_label') && kop_closure_pdo()
            ? kop_facility_finder_label(kop_closure_pdo(), $fid) : '#' . $fid;
        $page = function_exists('kop_facility_page_url') ? kop_facility_page_url($fid) : '';
        echo '<h2>' . $label . ($page ? ' <a class="kop-gdl-small" href="' . esc_url($page) . '" target="_blank" rel="noopener">facility page</a>' : '') . '</h2>';
    } elseif ($tab === 'company') {
        echo '<h2>' . esc_html($first['operator_name']) . ' <span class="kop-gdl-muted">company: pick the facility for these links</span></h2>';
    } elseif ($tab === 'applied') {
        echo '<h2>Sent to the queues</h2>';
    } else {
        echo '<h2>' . esc_html($first['source_doc'] !== '' ? $first['source_doc'] : 'Other links') . ' <span class="kop-gdl-muted">doc</span></h2>';
    }
    $total = (int) ($meta['total'] ?? count($rows));
    if ($total > count($rows)) {
        echo '<p class="kop-gdl-muted">Showing ' . ((int) ($meta['offset'] ?? 0) + 1) . '-' . ((int) ($meta['offset'] ?? 0) + count($rows))
            . ' of ' . $total . ' links.' . (!empty($meta['more']) ? ' <a href="' . esc_url($meta['more']) . '">Show them all</a>, 200 a page.' : '') . '</p>';
    }
    if ($tab === 'facility' && !empty($meta['all'])) {
        echo '<p><button type="button" class="button kop-gdl-addall" data-left="' . (int) $meta['all'] . '">Add all '
            . (int) $meta['all'] . ' sure match' . ((int) $meta['all'] === 1 ? '' : 'es') . ' on this card</button> '
            . '<span class="kop-gdl-muted">each to where its kind goes, under the filters above, including links not shown</span></p>';
    }
    echo '</div>';

    echo '<table class="widefat kop-gdl-table"><tbody>';
    foreach ($rows as $r) {
        kop_gdl_render_row($r, $tab);
    }
    echo '</tbody></table>';

    echo '<div class="kop-gdl-actions">';
    if ($pending) {
        if ($tab === 'facility') {
            echo '<button type="button" class="button button-primary" data-act="apply">Add checked</button> ';
        } else {
            echo '<button type="button" class="button button-primary" data-act="apply">Add checked (news only, until a record is picked)</button> ';
        }
        echo '<button type="button" class="button" data-act="reject">Skip checked</button>'
            . '<div class="kop-gdl-other"><strong>' . ($tab === 'facility' ? 'Another record:' : 'Which record is it?') . '</strong> '
            . kop_facility_finder_field('', '', ' class="kop-gdl-fid"')
            . ' <button type="button" class="button" data-act="apply" data-other="1">Add checked to that record</button></div>';
    } else {
        echo '<button type="button" class="button" data-act="undo">' . ($tab === 'applied' ? 'Undo checked' : 'Put checked back to review') . '</button>';
    }
    echo ' <span class="kop-gdl-result" aria-live="polite"></span></div>';
    echo '</div>';
}

function kop_gdl_render_row(array $r, $tab) {
    $pending = $r['status'] === 'pending';
    $kinds = kop_gdl_kinds();
    $checked = $pending && $tab === 'facility' && kop_gdl_sure_match($r);
    $seen = json_decode((string) $r['seen'], true) ?: array();
    $first = $seen[0] ?? array();
    echo '<tr data-key="' . esc_attr($r['pkey']) . '">';
    echo '<td class="kop-gdl-check"><input type="checkbox" class="kop-gdl-pick"' . ($checked ? ' checked' : '') . ' aria-label="Select"></td>';
    echo '<td class="kop-gdl-what"><a href="' . esc_url($r['url']) . '" target="_blank" rel="noopener noreferrer nofollow"><strong>'
        . esc_html($r['label'] !== '' ? $r['label'] : $r['url']) . '</strong></a>'
        . '<div class="kop-gdl-muted">' . esc_html($kinds[$r['kind']] ?? $r['kind']) . ' &middot; ' . esc_html($r['domain']) . '</div>';
    if ($pending) {
        $default = kop_gdl_default_target($r['kind']);
        echo '<label class="kop-gdl-muted">Goes to <select class="kop-gdl-target">';
        foreach (kop_gdl_targets() as $k => $label) {
            echo '<option value="' . esc_attr($k) . '"' . selected($default, $k, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select></label>';
        if ($r['facility_id'] > 0 && !kop_gdl_sure_match($r)) {
            echo '<div class="kop-gdl-warn">Matched by a close name (' . esc_html($r['facility_how']) . '): check it is this facility. Starts unticked.</div>';
        }
    } else {
        $done = json_decode((string) $r['applied'], true) ?: array();
        if (!empty($done['reason'])) {
            echo '<div class="kop-gdl-muted">' . esc_html($done['reason']) . '</div>';
        } elseif (!empty($done['target'])) {
            $targets = kop_gdl_targets();
            echo '<div class="kop-gdl-muted">Went to: ' . esc_html($targets[$done['target']] ?? $done['target'])
                . (!empty($done['id']) ? ' #' . (int) $done['id'] : '') . '</div>';
        }
    }
    echo '</td><td class="kop-gdl-ev">';
    $where = trim(($first['doc'] ?? '') . (!empty($first['tab']) ? ' > ' . $first['tab'] : '') . (!empty($first['heading']) ? ' > ' . $first['heading'] : ''));
    echo '<div class="kop-gdl-muted">From ' . esc_html($where) . (count($seen) > 1 ? ' (and ' . (count($seen) - 1) . ' more place' . (count($seen) > 2 ? 's' : '') . ')' : '') . '</div>';
    if (!empty($first['text'])) {
        echo '<blockquote>' . esc_html(mb_substr(preg_replace('#https?://\S+#', '[link]', (string) $first['text']), 0, 420)) . '</blockquote>';
    }
    if (!empty($first['archive']) && preg_match('#^https?://#i', (string) $first['archive'])) {
        echo '<div class="kop-gdl-muted"><a href="' . esc_url($first['archive']) . '" target="_blank" rel="noopener noreferrer nofollow">Archived copy</a>'
            . ' (the link above is the original address)</div>';
    }
    $src = kop_gdl_sources()[$r['source'] ?? ''] ?? array();
    if (!empty($src['credit'])) {
        echo '<div class="kop-gdl-muted">Credit on the record: <a href="' . esc_url($src['credit_url']) . '" target="_blank" rel="noopener">'
            . esc_html($src['credit']) . '</a></div>';
    }
    $also = json_decode((string) $r['also_named'], true) ?: array();
    if ($also) {
        // A SCIAD NET collection whose name is two or more KOP records lists them all and ties none.
        echo '<div class="kop-gdl-muted">' . (($r['source'] ?? '') === 'sciad' && (int) $r['facility_id'] === 0 ? 'Same name as these records, pick one: ' : 'Also names: ')
            . esc_html(implode(', ', array_map(function ($a) {
            return $a['name'] . (!empty($a['state']) ? ' (' . $a['state'] . ')' : '');
        }, array_slice($also, 0, 5)))) . '</div>';
    }
    echo '</td></tr>';
}

function kop_gdl_render_assets() {
    $nonce = wp_create_nonce('kop_drive_docs');
    ?>
    <style>
        .kop-gdl-how { margin-left: 20px; max-width: 900px; }
        .kop-gdl-filter { margin: 8px 0 12px; }
        .kop-gdl-bar { position: sticky; top: 32px; z-index: 5; background: #f0f0f1; padding: 8px 0; }
        .kop-gdl-card { background: #fff; border: 1px solid #c3c4c7; border-left: 4px solid #33A7B5; margin: 0 0 18px; padding: 10px 14px; max-width: 1400px; }
        .kop-gdl-card.kop-gdl-done { border-left-color: #B2E102; opacity: .75; }
        .kop-gdl-head h2 { margin: 4px 0 6px; font-size: 1.25em; }
        .kop-gdl-small { font-size: 12px; font-weight: 400; margin-left: 8px; }
        .kop-gdl-table td { vertical-align: top; }
        .kop-gdl-check { width: 28px; }
        .kop-gdl-what { width: 40%; }
        .kop-gdl-what a { word-break: break-word; }
        .kop-gdl-ev blockquote { margin: 2px 0 6px; padding-left: 8px; border-left: 3px solid #33A7B5; color: #1d2327; }
        .kop-gdl-muted { color: #4A5568; font-size: 12px; }
        .kop-gdl-warn { color: #9a4a00; font-size: 12px; margin-top: 2px; }
        .kop-gdl-target { font-size: 12px; margin-left: 4px; }
        .kop-gdl-actions { margin-top: 8px; }
        .kop-gdl-other { margin: 8px 0 4px; padding: 8px; background: #f6f7f7; border: 1px solid #dcdcde; }
        .kop-gdl-result.ok { color: #007017; }
        .kop-gdl-result.err { color: #d63638; }
        tr.kop-gdl-gone td { opacity: .45; }
    </style>
    <script>
    (function () {
        var ajax = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;
        var nonce = <?php echo wp_json_encode($nonce); ?>;

        function post(data) {
            var body = new URLSearchParams();
            body.append('action', 'kop_gdl_act');
            body.append('nonce', nonce);
            Object.keys(data).forEach(function (k) {
                var v = data[k];
                if (Array.isArray(v)) v.forEach(function (x) { body.append(k + '[]', x); });
                else if (v && typeof v === 'object') Object.keys(v).forEach(function (kk) { body.append(k + '[' + kk + ']', v[kk]); });
                else if (v !== undefined && v !== null) body.append(k, v);
            });
            return fetch(ajax, { method: 'POST', credentials: 'same-origin', body: body }).then(function (r) { return r.json(); });
        }

        function run(card, act, btn) {
            var out = card.querySelector('.kop-gdl-result');
            var keys = [], targets = {};
            card.querySelectorAll('tr[data-key]').forEach(function (tr) {
                if (tr.classList.contains('kop-gdl-gone') || !tr.querySelector('.kop-gdl-pick').checked) return;
                keys.push(tr.dataset.key);
                var sel = tr.querySelector('.kop-gdl-target');
                if (sel) targets[tr.dataset.key] = sel.value;
            });
            if (!keys.length) { out.className = 'kop-gdl-result err'; out.textContent = 'Tick at least one link.'; return Promise.resolve(); }
            var data = { act: act, keys: keys, targets: targets, fid: card.dataset.fid || '' };
            if (btn && btn.dataset.other) {
                var box = card.querySelector('.kop-gdl-fid');
                data.fid = box && box.value ? box.value : '';
                if (!(+data.fid)) { out.className = 'kop-gdl-result err'; out.textContent = 'Pick the record first.'; return Promise.resolve(); }
            }
            out.className = 'kop-gdl-result'; out.textContent = 'Working...';
            return post(data).then(function (res) {
                if (!res.success) { out.className = 'kop-gdl-result err'; out.textContent = res.data || 'Failed.'; return; }
                var ok = 0, problems = [];
                Object.keys(res.data.results || {}).forEach(function (k) {
                    var r = res.data.results[k];
                    var tr = card.querySelector('tr[data-key="' + k + '"]');
                    if (r.ok) { ok++; if (tr) tr.classList.add('kop-gdl-gone'); }
                    else { problems.push(r.error); if (tr && !r.keep) tr.classList.add('kop-gdl-gone'); }
                });
                var words = { apply: 'Added ' + ok + (res.data.label ? ' (' + res.data.label + ')' : '') + '.', reject: 'Skipped ' + ok + '.', undo: 'Undone: ' + ok + '.' }[act];
                var uniq = problems.filter(function (p, i) { return problems.indexOf(p) === i; });
                out.className = 'kop-gdl-result ' + (uniq.length && !ok ? 'err' : 'ok');
                out.textContent = words + (uniq.length ? ' Not done (' + problems.length + '): ' + uniq.join(' ') : '');
                if (res.data.url && ok) {
                    var a = document.createElement('a'); a.href = res.data.url; a.target = '_blank'; a.rel = 'noopener'; a.textContent = ' Open its page';
                    out.appendChild(a);
                }
                if (!card.querySelector('tr[data-key]:not(.kop-gdl-gone)')) card.classList.add('kop-gdl-done');
            }).catch(function () { out.className = 'kop-gdl-result err'; out.textContent = 'Network error; try again.'; });
        }

        // "Add all" on a facility card: the server takes the card's sure matches a batch at a time until none wait.
        function addAll(card, btn) {
            var out = card.querySelector('.kop-gdl-result');
            var added = 0, problems = 0, last = -1;
            btn.disabled = true;
            (function next() {
                post({ act: 'apply_card', fid: card.dataset.fid, gdl_kind: card.dataset.kind, gdl_src: card.dataset.src, gdl_q: card.dataset.q }).then(function (res) {
                    if (!res.success) { out.className = 'kop-gdl-result err'; out.textContent = res.data || 'Failed.'; btn.disabled = false; return; }
                    Object.keys(res.data.results || {}).forEach(function (k) {
                        var tr = card.querySelector('tr[data-key="' + k + '"]');
                        if (res.data.results[k].ok) added++; else problems++;
                        if (tr) tr.classList.add('kop-gdl-gone');
                    });
                    var left = res.data.left || 0;
                    out.className = 'kop-gdl-result ok';
                    out.textContent = 'Added ' + added + (problems ? ', ' + problems + ' already on file or refused' : '') + (left ? '; ' + left + ' to go...' : '.');
                    if (left && left !== last) { last = left; next(); return; }
                    btn.textContent = 'Done';
                    if (!card.querySelector('tr[data-key]:not(.kop-gdl-gone)')) card.classList.add('kop-gdl-done');
                }).catch(function () { out.className = 'kop-gdl-result err'; out.textContent = 'Network error after ' + added + ' added; click again to carry on.'; btn.disabled = false; });
            })();
        }

        document.addEventListener('click', function (e) {
            var many = e.target.closest('.kop-gdl-addall');
            if (many) { addAll(many.closest('.kop-gdl-card'), many); return; }
            var btn = e.target.closest('.kop-gdl-card [data-act]');
            if (btn) { run(btn.closest('.kop-gdl-card'), btn.dataset.act, btn); return; }
            var all = e.target.closest('.kop-gdl-all');
            if (!all) return;
            var cards = Array.prototype.slice.call(document.querySelectorAll('.kop-gdl-card:not(.kop-gdl-done)'));
            var progress = document.querySelector('.kop-gdl-progress');
            all.disabled = true;
            var i = 0;
            (function next() {
                if (i >= cards.length) { all.disabled = false; progress.textContent = 'Done.'; return; }
                progress.textContent = 'Card ' + (i + 1) + ' of ' + cards.length + '...';
                run(cards[i++], 'apply', null).then(next);
            })();
        });
    })();
    </script>
    <?php
}
