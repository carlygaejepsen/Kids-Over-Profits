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
 */

if (!defined('ABSPATH')) {
    exit;
}

define('KOP_GDOC_LINKS_DB_VERSION', '1');

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
        KEY source_doc (source_doc(80))
    ) {$charset};");
    update_option('kop_gdoc_links_db', KOP_GDOC_LINKS_DB_VERSION);
}

function kop_gdl_path() {
    $dir = defined('KOP_GDOCS_IMPORT_DIR') ? rtrim(KOP_GDOCS_IMPORT_DIR, '/') : dirname(rtrim(ABSPATH, '/')) . '/kop-import/gdocs';
    return $dir . '/links.json';
}

/**
 * Every links file the screen reads: the Google Docs pass, the documents
 * from HEAL's archived site (scripts/heal-docs.py) and the links on the
 * r/troubledteens wiki's pages (scripts/wiki-links.py), which sit beside it.
 */
function kop_gdl_paths() {
    $paths = array();
    foreach (array(kop_gdl_path(), dirname(kop_gdl_path()) . '/heal-links.json', dirname(kop_gdl_path()) . '/wiki-links.json') as $p) {
        if (is_readable($p)) {
            $paths[] = $p;
        }
    }
    return $paths;
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
    $paths = kop_gdl_paths();
    if (!$paths) {
        return null;
    }
    $md5 = md5(implode('|', array_map('md5_file', $paths)));
    if (!$force && get_option('kop_gdoc_links_md5') === $md5) {
        return null;
    }
    $items = array();
    foreach ($paths as $path) {
        $list = json_decode((string) file_get_contents($path), true);
        if (!is_array($list)) {
            return null; // A half-copied file: nothing is marked gone until it reads.
        }
        $items = array_merge($items, $list);
    }
    global $wpdb;
    $table = kop_gdl_table();
    $existing = array();
    foreach ((array) $wpdb->get_results("SELECT pkey, status FROM {$table}", ARRAY_A) as $r) {
        $existing[$r['pkey']] = $r['status'];
    }
    $kinds = kop_gdl_kinds();
    $now = current_time('mysql', true);
    $seen = array();
    $added = $updated = 0;
    foreach ($items as $it) {
        if (!is_array($it) || !empty($it['on_file']) || ($it['category'] ?? '') === 'internal') {
            continue;
        }
        $url = (string) ($it['url'] ?? '');
        if (!preg_match('#^https?://#i', $url)) {
            continue;
        }
        $pkey = kop_gdl_pkey($it['key'] ?? $url);
        $seen[$pkey] = true;
        $places = array_values(array_filter((array) ($it['seen'] ?? array()), 'is_array'));
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
        );
        if (!isset($existing[$pkey])) {
            $row['pkey'] = $pkey;
            $row['status'] = 'pending';
            $row['created_at'] = $now;
            if ($wpdb->insert($table, $row) !== false) {
                $added++;
            }
        } elseif (in_array($existing[$pkey], array('pending', 'gone'), true)) {
            $row['status'] = 'pending';
            $wpdb->update($table, $row, array('pkey' => $pkey));
            $updated++;
        }
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

/** Send one link to a queue. Returns what was done; throws when it is already there. */
function kop_gdl_queue_add(PDO $pdo, array $r, $target, $facility_name, $reviewer) {
    $type = array('news' => 'article', 'lawsuit' => 'lawsuit', 'legislation' => 'legislation')[$target];
    $seen = json_decode((string) $r['seen'], true) ?: array();
    $first = $seen[0] ?? array();
    $p = array(
        'url'       => $r['url'],
        'title'     => $r['label'] !== '' ? $r['label'] : $r['url'],
        'type'      => $type,
        'site_name' => $r['domain'],
        'facility'  => (string) $facility_name,
    );
    $dupes = kop_ext_find_duplicates($pdo, $p);
    if ($dupes) {
        throw new RuntimeException('Already in the ' . $dupes[0]['type'] . ' records (#' . (int) $dupes[0]['id'] . ').');
    }
    $note = kop_gdl_source_line($r) . (!empty($first['text']) ? "\n\nWords around the link: \"" . mb_substr((string) $first['text'], 0, 600) . '"' : '');
    $submitter = mb_substr($reviewer . ' (Google Docs import)', 0, 255);
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
        'applied'  => array('label' => 'Added', 'where' => "status = 'applied'", 'card' => "IF(applied_fid > 0, CAST(applied_fid AS CHAR), 'q')"),
        'rejected' => array('label' => 'Skipped or already there', 'where' => "status = 'rejected'", 'card' => 'source_doc'),
    );
}

/** A match the screen trusts enough to tick the link to start with. */
function kop_gdl_sure_match(array $r) {
    return $r['facility_id'] > 0 && strpos($r['facility_how'], 'close') === false;
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
    $tab = isset($_GET['gdl_tab'], $tabs[$_GET['gdl_tab']]) ? $_GET['gdl_tab'] : 'facility';
    $kind = isset($_GET['gdl_kind'], $kinds[$_GET['gdl_kind']]) ? $_GET['gdl_kind'] : '';
    $q = isset($_GET['gdl_q']) ? trim(sanitize_text_field(wp_unslash($_GET['gdl_q']))) : '';
    $paged = max(1, (int) ($_GET['gdl_page'] ?? 1));
    $per = 15;
    $base = admin_url('admin.php?page=kop-drive-docs');

    echo '<div class="wrap kop-gdl"><h1>Drive Docs</h1>';
    if ($sync) {
        echo '<div class="notice notice-info"><p>Loaded a new build: ' . (int) $sync['added'] . ' new, ' . (int) $sync['updated']
            . ' updated, ' . (int) $sync['gone'] . ' no longer offered.</p></div>';
    }
    if (!kop_gdl_paths()) {
        echo '<div class="notice notice-warning"><p>No links uploaded yet. Run <code>python scripts/gdocs-extract.py</code> and copy '
            . '<code>tmp/gdocs/links.json</code> to <code>' . esc_html(dirname(kop_gdl_path())) . '</code>.</p></div>';
    }
    echo '<p>Links from your Google Docs and Sheets, documents saved on HEAL\'s old site (heal-online.org, through the Wayback Machine) '
        . 'and links on the r/troubledteens wiki\'s pages, '
        . 'that the database does not have yet, one card per facility. '
        . 'Each shows the doc it came from and the words around it.</p>'
        . '<ol class="kop-gdl-how"><li><strong>Read down a card.</strong> Links whose facility is a sure match start ticked; untick anything that is not about this place.</li>'
        . '<li><strong>Check <em>Goes to</em>.</strong> News goes to the news queue, court records and bills to their queues, the program\'s own site to its website links, '
        . 'and everything else (licensing reports, survivor posts, staff profiles, reference) to the resource links on the facility page. Change it on any row.</li>'
        . '<li><strong>Click <em>Add checked</em></strong>, or <em>Add everything ticked on this page</em> at the top. Facility links show on the page at once; '
        . 'queue items wait in their queue as if sent from the browser extension, with no emails.</li>'
        . '<li><strong>Wrong place?</strong> Pick another record in the box under the card and click <em>Add checked to that record</em>. '
        . 'On <em>Company only</em> and <em>No facility</em>, pick the record first; news can go to the queue without one.</li>'
        . '<li><strong>Changed your mind?</strong> The <em>Added</em> tab has Undo: the link comes off the record, or leaves its queue if nobody has reviewed it yet.</li></ol>';

    $where = $tabs[$tab]['where'];
    if ($kind !== '') {
        $where .= $wpdb->prepare(' AND kind = %s', $kind);
    }
    if ($q !== '') {
        $like = '%' . $wpdb->esc_like($q) . '%';
        $where .= $wpdb->prepare(' AND (label LIKE %s OR url LIKE %s OR source_doc LIKE %s OR operator_name LIKE %s)', $like, $like, $like, $like);
    }

    echo '<ul class="subsubsub">';
    $i = 0;
    foreach ($tabs as $k => $t) {
        $n = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE " . $t['where']);
        echo '<li><a href="' . esc_url(add_query_arg(array('gdl_tab' => $k), $base)) . '"' . ($tab === $k ? ' class="current"' : '') . '>'
            . esc_html($t['label']) . ' <span class="count">(' . $n . ')</span></a>' . (++$i < count($tabs) ? ' | ' : '') . '</li>';
    }
    echo '</ul><div style="clear:both"></div>';

    echo '<form method="get" class="kop-gdl-filter"><input type="hidden" name="page" value="kop-drive-docs">'
        . '<input type="hidden" name="gdl_tab" value="' . esc_attr($tab) . '">'
        . '<input type="search" name="gdl_q" value="' . esc_attr($q) . '" placeholder="Words, address or doc" style="width:240px" aria-label="Search"> '
        . '<select name="gdl_kind" aria-label="Kind"><option value="">Every kind</option>';
    foreach ($kinds as $k => $label) {
        echo '<option value="' . esc_attr($k) . '"' . selected($kind, $k, false) . '>' . esc_html($label) . '</option>';
    }
    echo '</select> <button class="button">Show</button>'
        . ($q !== '' || $kind !== '' ? ' <a href="' . esc_url(add_query_arg('gdl_tab', $tab, $base)) . '">Clear</a>' : '') . '</form>';

    $card_col = $tabs[$tab]['card'];
    $total = (int) $wpdb->get_var("SELECT COUNT(DISTINCT {$card_col}) FROM {$table} WHERE {$where}");
    $cards = $wpdb->get_col("SELECT {$card_col} FROM {$table} WHERE {$where} GROUP BY {$card_col} ORDER BY COUNT(*) DESC, MIN(label) LIMIT "
        . (($paged - 1) * $per) . ", {$per}");
    if (!$cards) {
        echo '<p>Nothing here.</p></div>';
        kop_gdl_render_assets();
        return;
    }
    $in = implode(',', array_fill(0, count($cards), '%s'));
    $rows = $wpdb->get_results($wpdb->prepare("SELECT *, {$card_col} AS ck FROM {$table} WHERE {$where} AND {$card_col} IN ({$in}) "
        . "ORDER BY FIELD(kind,'news','court','legislation','inspection','government','social','people','advertising','reference','archive','program_site','other'), label", $cards), ARRAY_A);
    $by = array();
    foreach ($rows as $r) {
        $by[(string) $r['ck']][] = $r;
    }

    echo '<div class="kop-gdl-bar">';
    if ($tab === 'facility') {
        echo '<button type="button" class="button button-primary kop-gdl-all">Add everything ticked on this page</button> ';
    }
    echo '<span class="kop-gdl-progress" aria-live="polite"></span></div>';

    foreach ($cards as $c) {
        if (!empty($by[(string) $c])) {
            kop_gdl_render_card($by[(string) $c], $tab);
        }
    }

    $pages = (int) ceil($total / $per);
    if ($pages > 1) {
        echo '<p class="kop-gdl-pager">Page ' . $paged . ' of ' . $pages . ' (' . $total . ' cards) &middot; ';
        for ($n = 1; $n <= $pages; $n++) {
            $url = add_query_arg(array('gdl_tab' => $tab, 'gdl_page' => $n, 'gdl_q' => $q !== '' ? $q : null, 'gdl_kind' => $kind !== '' ? $kind : null), $base);
            echo $n === $paged ? '<strong>' . $n . '</strong> ' : '<a href="' . esc_url($url) . '">' . $n . '</a> ';
        }
        echo '</p>';
    }
    kop_gdl_render_assets();
    echo '</div>';
}

function kop_gdl_render_card(array $rows, $tab) {
    $first = $rows[0];
    $pending = in_array($tab, array('facility', 'company', 'none'), true);
    $fid = $tab === 'applied' ? (int) $first['applied_fid'] : (int) $first['facility_id'];
    echo '<div class="kop-gdl-card" data-fid="' . $fid . '">';
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
    $also = json_decode((string) $r['also_named'], true) ?: array();
    if ($also) {
        echo '<div class="kop-gdl-muted">Also names: ' . esc_html(implode(', ', array_map(function ($a) {
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

        document.addEventListener('click', function (e) {
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
