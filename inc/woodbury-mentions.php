<?php
/**
 * Woodbury Reports mentions: the pages of each Woodbury Reports issue that
 * write about a program, filed in that program's document library.
 *
 * scripts/woodbury-scan.py reads every issue in the media library, finds the
 * articles (a "MONTANA ACADEMY" / "Kalispell, MT" header), the "Seen N' Heard"
 * news items and other mentions of each program, and cuts those pages into
 * small PDFs. It writes them with a candidates.json to a local folder, which is
 * copied to ~/kop-import/woodbury/ on the server (outside the web root).
 *
 * KOP Data Tools > Woodbury Reports lists the candidates. "File it" imports the
 * PDF into the media library and files it in a "Woodbury Reports Mentions"
 * folder under the program's own FileBird folder, the one its /facility/ page
 * shows, so it appears there at once. One excerpt can be filed under several
 * facilities: the PDF sits in the first one's folder and the others get it as
 * a tag (kop_media_folder_tags) in their own Woodbury subfolder; the extras are
 * kept in also_facilities. Nothing is filed without a click; Undo deletes the
 * imported copy and its tags and puts the candidate back in the queue.
 *
 * Candidates live in {prefix}kop_woodbury_mentions, keyed by the scanner's
 * candidate key, so a rescan adds new ones and never undoes a decision.
 */

if (!defined('ABSPATH')) {
    exit;
}

define('KOP_WOODBURY_DB_VERSION', '3');
define('KOP_WOODBURY_SUBFOLDER', 'Woodbury Reports Mentions');

function kop_wb_table() {
    global $wpdb;
    return $wpdb->prefix . 'kop_woodbury_mentions';
}

function kop_wb_tags_table() {
    global $wpdb;
    return $wpdb->prefix . 'kop_media_folder_tags';
}

/** Where the scanner's PDFs and candidates.json wait: ~/kop-import/woodbury. */
function kop_wb_pending_dir() {
    if (defined('KOP_WOODBURY_PENDING_DIR')) {
        return rtrim(KOP_WOODBURY_PENDING_DIR, '/');
    }
    return dirname(rtrim(ABSPATH, '/')) . '/kop-import/woodbury';
}

function kop_wb_ensure_table() {
    if (get_option('kop_woodbury_mentions_db') === KOP_WOODBURY_DB_VERSION) {
        return;
    }
    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    $table = kop_wb_table();
    $charset = $wpdb->get_charset_collate();
    dbDelta("CREATE TABLE {$table} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        ckey VARCHAR(32) NOT NULL,
        kind VARCHAR(12) NOT NULL,
        status VARCHAR(12) NOT NULL DEFAULT 'pending',
        issue_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        issue_label VARCHAR(40) NOT NULL DEFAULT '',
        issue_number VARCHAR(12) NOT NULL DEFAULT '',
        issue_date CHAR(7) NOT NULL DEFAULT '',
        pages VARCHAR(60) NOT NULL DEFAULT '',
        header VARCHAR(255) NOT NULL DEFAULT '',
        place VARCHAR(120) NOT NULL DEFAULT '',
        matched_name VARCHAR(255) NOT NULL DEFAULT '',
        facility_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        facility_name VARCHAR(255) NOT NULL DEFAULT '',
        facility_state VARCHAR(8) NOT NULL DEFAULT '',
        target_kind VARCHAR(12) NOT NULL DEFAULT 'facility',
        target_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        alternatives TEXT NULL,
        also_facilities TEXT NULL,
        note TEXT NULL,
        snippet MEDIUMTEXT NULL,
        file VARCHAR(255) NOT NULL DEFAULT '',
        md5 CHAR(32) NOT NULL DEFAULT '',
        attachment_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        folder_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        reviewed_by VARCHAR(60) NOT NULL DEFAULT '',
        reviewed_at DATETIME NULL,
        created_at DATETIME NOT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY ckey (ckey),
        KEY status_kind (status, kind),
        KEY facility_id (facility_id)
    ) {$charset};");
    // Extra facilities an excerpt is filed under are tags in the theme's
    // multi-folder table, which every folder feed reads (inc/database.php).
    $wpdb->query('CREATE TABLE IF NOT EXISTS ' . kop_wb_tags_table() . ' (
        folder_id BIGINT UNSIGNED NOT NULL,
        attachment_id BIGINT UNSIGNED NOT NULL,
        PRIMARY KEY (folder_id, attachment_id),
        KEY idx_attachment (attachment_id)
    ) ' . $charset);
    update_option('kop_woodbury_mentions_db', KOP_WOODBURY_DB_VERSION);
}

/**
 * Load a new candidates.json into the table. New candidates are added; ones
 * still waiting get the scanner's latest match; decided ones are left alone.
 * Waiting ones the new scan no longer produces are marked 'gone'.
 *
 * @return array{added:int, updated:int, gone:int}|null  null when nothing new
 */
function kop_wb_sync($force = false) {
    $path = kop_wb_pending_dir() . '/candidates.json';
    if (!is_readable($path)) {
        return null;
    }
    $md5 = md5_file($path);
    if (!$force && get_option('kop_woodbury_candidates_md5') === $md5) {
        return null;
    }
    $data = json_decode((string) file_get_contents($path), true);
    if (!is_array($data) || !isset($data['candidates']) || !is_array($data['candidates'])) {
        return null;
    }
    global $wpdb;
    $table = kop_wb_table();
    $existing = array();
    foreach ((array) $wpdb->get_results("SELECT ckey, status FROM {$table}", ARRAY_A) as $r) {
        $existing[$r['ckey']] = $r['status'];
    }
    $now = current_time('mysql', true);
    $seen = array();
    $added = $updated = 0;
    foreach ($data['candidates'] as $c) {
        $ckey = preg_replace('/[^a-f0-9]/', '', (string) ($c['key'] ?? ''));
        if ($ckey === '') {
            continue;
        }
        $seen[$ckey] = true;
        $row = array(
            'kind'           => substr(sanitize_key($c['kind'] ?? ''), 0, 12),
            'issue_id'       => (int) ($c['issue_id'] ?? 0),
            'issue_label'    => substr((string) ($c['issue_label'] ?? ''), 0, 40),
            'issue_number'   => substr((string) ($c['issue_number'] ?? ''), 0, 12),
            'issue_date'     => substr((string) ($c['issue_date'] ?? ''), 0, 7),
            'pages'          => implode(',', array_map('intval', (array) ($c['pages'] ?? array()))),
            'header'         => substr((string) ($c['header'] ?? ''), 0, 255),
            'place'          => substr((string) ($c['place'] ?? ''), 0, 120),
            'matched_name'   => substr((string) ($c['matched_name'] ?? ''), 0, 255),
            'facility_id'    => (int) ($c['facility_id'] ?? 0),
            'facility_name'  => substr((string) ($c['facility_name'] ?? ''), 0, 255),
            'facility_state' => substr((string) ($c['facility_state'] ?? ''), 0, 8),
            'alternatives'   => wp_json_encode(array_values((array) ($c['alternatives'] ?? array()))),
            'note'           => (string) ($c['note'] ?? ''),
            'snippet'        => (string) ($c['snippet'] ?? ''),
            'file'           => basename((string) ($c['file'] ?? '')),
            'md5'            => preg_replace('/[^a-f0-9]/', '', (string) ($c['md5'] ?? '')),
        );
        if (!isset($existing[$ckey])) {
            $row['ckey'] = $ckey;
            $row['status'] = 'pending';
            $row['created_at'] = $now;
            if ($wpdb->insert($table, $row) !== false) {
                $added++;
            }
        } elseif (in_array($existing[$ckey], array('pending', 'gone'), true)) {
            $row['status'] = 'pending';
            $wpdb->update($table, $row, array('ckey' => $ckey));
            $updated++;
        }
    }
    $gone = 0;
    foreach ($existing as $ckey => $status) {
        if ($status === 'pending' && !isset($seen[$ckey])) {
            $gone += (int) $wpdb->update($table, array('status' => 'gone'), array('ckey' => $ckey));
        }
    }
    update_option('kop_woodbury_candidates_md5', $md5, false);
    return array('added' => $added, 'updated' => $updated, 'gone' => $gone);
}

/* ---- Filing ---------------------------------------------------------- */

function kop_wb_state_names() {
    return array(
        'AL' => 'Alabama', 'AK' => 'Alaska', 'AZ' => 'Arizona', 'AR' => 'Arkansas', 'CA' => 'California',
        'CO' => 'Colorado', 'CT' => 'Connecticut', 'DE' => 'Delaware', 'FL' => 'Florida', 'GA' => 'Georgia',
        'HI' => 'Hawaii', 'ID' => 'Idaho', 'IL' => 'Illinois', 'IN' => 'Indiana', 'IA' => 'Iowa',
        'KS' => 'Kansas', 'KY' => 'Kentucky', 'LA' => 'Louisiana', 'ME' => 'Maine', 'MD' => 'Maryland',
        'MA' => 'Massachusetts', 'MI' => 'Michigan', 'MN' => 'Minnesota', 'MS' => 'Mississippi',
        'MO' => 'Missouri', 'MT' => 'Montana', 'NE' => 'Nebraska', 'NV' => 'Nevada', 'NH' => 'New Hampshire',
        'NJ' => 'New Jersey', 'NM' => 'New Mexico', 'NY' => 'New York', 'NC' => 'North Carolina',
        'ND' => 'North Dakota', 'OH' => 'Ohio', 'OK' => 'Oklahoma', 'OR' => 'Oregon', 'PA' => 'Pennsylvania',
        'RI' => 'Rhode Island', 'SC' => 'South Carolina', 'SD' => 'South Dakota', 'TN' => 'Tennessee',
        'TX' => 'Texas', 'UT' => 'Utah', 'VT' => 'Vermont', 'VA' => 'Virginia', 'WA' => 'Washington',
        'WV' => 'West Virginia', 'WI' => 'Wisconsin', 'WY' => 'Wyoming',
    );
}

/** Folder of this name under this parent, the copy holding the most files first. 0 when none. */
function kop_wb_find_folder($name, $parent) {
    global $wpdb;
    $fbv = $wpdb->prefix . 'fbv';
    $rel = $wpdb->prefix . 'fbv_attachment_folder';
    return (int) $wpdb->get_var($wpdb->prepare(
        "SELECT f.id FROM {$fbv} f
         LEFT JOIN {$rel} r ON r.folder_id = f.id
         WHERE f.type = 0 AND f.parent = %d AND LOWER(f.name) = LOWER(%s)
         GROUP BY f.id ORDER BY COUNT(r.attachment_id) DESC, f.id ASC LIMIT 1",
        $parent, $name
    ));
}

function kop_wb_create_folder($name, $parent) {
    global $wpdb;
    $fbv = $wpdb->prefix . 'fbv';
    $columns = $wpdb->get_col("SHOW COLUMNS FROM {$fbv}");
    $row = array('name' => $name, 'parent' => (int) $parent, 'type' => 0);
    if (in_array('created_by', $columns, true)) $row['created_by'] = 0;
    if (in_array('ord', $columns, true)) $row['ord'] = 0;
    if ($wpdb->insert($fbv, $row) === false) {
        throw new RuntimeException('Could not create folder "' . $name . '": ' . $wpdb->last_error);
    }
    return (int) $wpdb->insert_id;
}

/**
 * The facility's document folder: the one its /facility/ page shows, else its
 * documentFolderId or a folder named after it, else a new folder named after
 * it (under its state's folder when there is one).
 */
function kop_wb_facility_folder($fid) {
    global $wpdb;
    if (function_exists('kop_facility_pages_index')) {
        $index = kop_facility_pages_index();
        if (!empty($index['ids'][$fid]['folder'])) {
            return (int) $index['ids'][$fid]['folder'];
        }
    }
    $row = $wpdb->get_row($wpdb->prepare('SELECT name, unique_name, state, json_data FROM facilities_v2 WHERE id = %d', $fid), ARRAY_A);
    if (!$row) {
        throw new RuntimeException('Facility #' . $fid . ' not found.');
    }
    $doc = json_decode((string) $row['json_data'], true);
    $doc = is_array($doc) ? $doc : array();
    if (!empty($doc['documentFolderId'])) {
        return (int) $doc['documentFolderId'];
    }
    if (function_exists('kop_facility_pages_folder_map') && function_exists('kop_facility_pages_doc_name_keys')) {
        $map = kop_facility_pages_folder_map();
        foreach (kop_facility_pages_doc_name_keys($doc, (string) $row['unique_name']) as $k) {
            if (isset($map[$k])) {
                return (int) $map[$k];
            }
        }
    }
    $name = trim((string) $row['name']);
    $states = kop_wb_state_names();
    $parent = 0;
    $st = strtoupper((string) $row['state']);
    if (isset($states[$st])) {
        $parent = kop_wb_find_folder($states[$st], 0);
    }
    $id = kop_wb_find_folder($name, $parent);
    return $id ?: kop_wb_create_folder($name, $parent);
}

/** "pp. 3-4", "p. 12" or "pp. 3, 5" from "3,4". */
function kop_wb_page_label($pages) {
    $p = array_values(array_filter(array_map('intval', explode(',', (string) $pages))));
    if (!$p) return '';
    if (count($p) === 1) return 'p. ' . $p[0];
    if ($p === range($p[0], $p[count($p) - 1])) return 'pp. ' . $p[0] . '-' . $p[count($p) - 1];
    return 'pp. ' . implode(', ', $p);
}

function kop_wb_title(array $r, $facility_name) {
    return 'Woodbury Reports, ' . $r['issue_label'] . ($r['issue_number'] !== '' ? ' (' . $r['issue_number'] . ')' : '')
        . ', ' . kop_wb_page_label($r['pages']) . ': ' . $facility_name;
}

function kop_wb_get($ckey) {
    global $wpdb;
    return $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . kop_wb_table() . ' WHERE ckey = %s', $ckey), ARRAY_A);
}

/**
 * Import the candidate's PDF and file it under the facility.
 *
 * @return array{attachment_id:int, folder_id:int, url:string, facility:string}
 */
function kop_wb_file(array $r, $fid, $reviewer) {
    global $wpdb;
    $fid = (int) $fid;
    if ($fid <= 0) {
        throw new RuntimeException('Choose the program first (facility id).');
    }
    $fac = $wpdb->get_row($wpdb->prepare('SELECT id, name FROM facilities_v2 WHERE id = %d', $fid), ARRAY_A);
    if (!$fac) {
        throw new RuntimeException('Facility #' . $fid . ' not found.');
    }
    return kop_wb_file_target($r, array('kind' => 'facility', 'id' => $fid, 'name' => $fac['name']), $reviewer);
}

/**
 * File the candidate under any record: a facility, or a company, consultant
 * or provider created from this screen (inc/woodbury-create.php). $target is
 * kind, id, name and, for anything but a facility, folder: the record's own
 * document folder, which the Woodbury subfolder goes under.
 */
function kop_wb_file_target(array $r, array $target, $reviewer) {
    global $wpdb;
    // A request the host answers with a 503 keeps running and files the
    // candidate anyway, so the page can send the same one again while the
    // first is still at work. One request per candidate at a time; MySQL
    // drops the lock itself if the process is killed.
    $lock = 'kop_wb_' . $r['ckey'];
    if (!(int) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 0)', $lock))) {
        throw new RuntimeException('Another request is filing this one. Reload the page in a minute.');
    }
    try {
        return kop_wb_file_locked(kop_wb_get($r['ckey']) ?: $r, $target, $reviewer);
    } finally {
        $wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock));
    }
}

function kop_wb_file_locked(array $r, array $target, $reviewer) {
    global $wpdb;
    $kind = $target['kind'];
    $fid = $kind === 'facility' ? (int) $target['id'] : 0;
    $fac = array('name' => $target['name']);
    if ($r['status'] === 'filed' && $r['attachment_id']) {
        throw new RuntimeException('Already filed.');
    }
    $path = kop_wb_pending_dir() . '/' . basename($r['file']);
    if ($r['file'] === '' || !is_readable($path)) {
        throw new RuntimeException('The extract PDF is not on the server (' . basename($r['file']) . '). Upload the scanner output again.');
    }

    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/media.php';
    require_once ABSPATH . 'wp-admin/includes/image.php';

    $parent = $kind === 'facility' ? kop_wb_facility_folder($fid) : (int) $target['folder'];
    if ($parent <= 0) {
        throw new RuntimeException('No document folder for ' . $target['name'] . '.');
    }
    $folder = kop_wb_find_folder(KOP_WOODBURY_SUBFOLDER, $parent) ?: kop_wb_create_folder(KOP_WOODBURY_SUBFOLDER, $parent);

    $title = kop_wb_title($r, $fac['name']);
    $issue_url = $r['issue_id'] ? (string) wp_get_attachment_url((int) $r['issue_id']) : '';

    // A run the host cut off may have imported the PDF without marking the
    // candidate filed: finish with that copy instead of importing another.
    $att = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT pm.post_id FROM {$wpdb->postmeta} pm JOIN {$wpdb->posts} p ON p.ID = pm.post_id
         WHERE pm.meta_key = '_kop_woodbury_key' AND pm.meta_value = %s AND p.post_type = 'attachment'
         ORDER BY pm.post_id LIMIT 1",
        $r['ckey']
    ));
    if (!$att) {
        $upload = wp_upload_bits(basename($r['file']), null, (string) file_get_contents($path));
        if (!empty($upload['error'])) {
            throw new RuntimeException('Upload failed: ' . $upload['error']);
        }
        $att = wp_insert_attachment(array(
            'post_mime_type' => 'application/pdf',
            'post_title'     => $title,
            'post_content'   => 'Pages ' . str_replace(',', ', ', $r['pages']) . ' of Woodbury Reports, ' . $r['issue_label']
                                . ($issue_url !== '' ? '. Full issue: ' . $issue_url : '.'),
            'post_status'    => 'inherit',
        ), $upload['file']);
        if (is_wp_error($att) || !$att) {
            @unlink($upload['file']);
            throw new RuntimeException('Could not register the attachment.');
        }
        // Tagged before the slow cover render, so a killed run is found above.
        update_post_meta($att, '_kop_woodbury_key', $r['ckey']);
    }
    // No cover render here: the host kills a request that renders a heavy
    // PDF, and a retry rendered the same one again, so filing never got past
    // it. The cover is drawn in the background by kop_wb_render_covers().
    if (!wp_get_attachment_metadata($att)) {
        $file = get_attached_file($att);
        wp_update_attachment_metadata($att, array('filesize' => $file && file_exists($file) ? (int) filesize($file) : 0));
    }
    kop_wb_schedule_covers();
    update_post_meta($att, '_kop_import_md5', md5_file($path));
    update_post_meta($att, '_kop_woodbury_issue', (int) $r['issue_id']);
    update_post_meta($att, '_kop_woodbury_pages', $r['pages']);
    if ($issue_url !== '') {
        update_post_meta($att, '_kop_source_url', esc_url_raw($issue_url));
    }
    $wpdb->query($wpdb->prepare(
        'DELETE FROM ' . $wpdb->prefix . 'fbv_attachment_folder WHERE attachment_id = %d AND folder_id = %d', $att, $folder
    ));
    $wpdb->insert($wpdb->prefix . 'fbv_attachment_folder', array('folder_id' => $folder, 'attachment_id' => $att), array('%d', '%d'));

    $wpdb->update(kop_wb_table(), array(
        'status'        => 'filed',
        'facility_id'   => $fid,
        'facility_name' => $fac['name'],
        'target_kind'   => $kind,
        'target_id'     => (int) $target['id'],
        'attachment_id' => (int) $att,
        'folder_id'     => $folder,
        'reviewed_by'   => $reviewer,
        'reviewed_at'   => current_time('mysql', true),
    ), array('ckey' => $r['ckey']));
    delete_transient('kop_hidden_preview_ids');
    return array('attachment_id' => (int) $att, 'folder_id' => $folder, 'url' => (string) wp_get_attachment_url($att), 'facility' => $fac['name'],
        'places' => array(kop_wb_place($fid, $fac['name'], $folder, true)));
}

/** "Utah › Wellspring Academies › Woodbury Reports Mentions": the folder's path in the media library. */
function kop_wb_folder_path($folder_id) {
    global $wpdb;
    $names = array();
    $id = (int) $folder_id;
    for ($depth = 0; $id > 0 && $depth < 10; $depth++) {
        $row = $wpdb->get_row($wpdb->prepare('SELECT name, parent FROM ' . $wpdb->prefix . 'fbv WHERE id = %d', $id), ARRAY_A);
        if (!$row) {
            break;
        }
        array_unshift($names, $row['name']);
        $id = (int) $row['parent'];
    }
    return implode(' › ', $names);
}

/** One place a filed excerpt shows: the facility (0 for a non-facility record), its folder path and public page. */
function kop_wb_place($fid, $name, $folder, $primary) {
    return array(
        'id'      => (int) $fid,
        'name'    => (string) $name,
        'folder'  => (int) $folder,
        'where'   => kop_wb_folder_path($folder),
        'page'    => $fid && function_exists('kop_facility_page_url') ? (string) kop_facility_page_url((int) $fid) : '',
        'primary' => (bool) $primary,
    );
}

/** The extra facilities a filed excerpt is tagged under: [{id, name, folder}]. */
function kop_wb_also(array $r) {
    $also = json_decode((string) ($r['also_facilities'] ?? ''), true);
    return is_array($also) ? array_values($also) : array();
}

/** Every place a filed excerpt shows, the facility holding the PDF first. */
function kop_wb_places(array $r) {
    $places = array();
    if ($r['status'] === 'filed') {
        $places[] = kop_wb_place($r['target_kind'] === 'facility' ? (int) $r['facility_id'] : 0, $r['facility_name'], (int) $r['folder_id'], true);
        foreach (kop_wb_also($r) as $a) {
            $places[] = kop_wb_place((int) $a['id'], $a['name'], (int) $a['folder'], false);
        }
    }
    return $places;
}

/** Title the filed PDF after every facility it is filed under. */
function kop_wb_retitle(array $r) {
    $names = array($r['facility_name']);
    foreach (kop_wb_also($r) as $a) {
        $names[] = $a['name'];
    }
    wp_update_post(array('ID' => (int) $r['attachment_id'], 'post_title' => kop_wb_title($r, implode('; ', $names))));
}

/**
 * File the excerpt under several facilities: the PDF goes in the first one's
 * folder, the others get it as a tag in their own Woodbury subfolder.
 */
function kop_wb_file_many(array $r, array $fids, $reviewer) {
    $fids = array_values(array_unique(array_filter(array_map('intval', $fids))));
    if (!$fids) {
        throw new RuntimeException('Tick at least one facility to file it under.');
    }
    $res = kop_wb_file($r, array_shift($fids), $reviewer);
    if ($fids) {
        $added = kop_wb_add_facilities(kop_wb_get($r['ckey']), $fids);
        $res['places'] = array_merge($res['places'], $added);
    }
    return $res;
}

/** Tag an already filed excerpt into more facilities' folders. Returns the new places. */
function kop_wb_add_facilities(array $r, array $fids) {
    global $wpdb;
    if ($r['status'] !== 'filed' || !$r['attachment_id']) {
        throw new RuntimeException('File it first.');
    }
    $also = kop_wb_also($r);
    $have = array_map('intval', array_column($also, 'id'));
    if ($r['target_kind'] === 'facility') {
        $have[] = (int) $r['facility_id'];
    }
    $places = array();
    foreach (array_unique(array_map('intval', $fids)) as $fid) {
        if ($fid <= 0 || in_array($fid, $have, true)) {
            continue;
        }
        $fac = $wpdb->get_row($wpdb->prepare('SELECT id, name FROM facilities_v2 WHERE id = %d', $fid), ARRAY_A);
        if (!$fac) {
            throw new RuntimeException('Facility #' . $fid . ' not found.');
        }
        $parent = kop_wb_facility_folder($fid);
        $folder = kop_wb_find_folder(KOP_WOODBURY_SUBFOLDER, $parent) ?: kop_wb_create_folder(KOP_WOODBURY_SUBFOLDER, $parent);
        $wpdb->query($wpdb->prepare('INSERT IGNORE INTO ' . kop_wb_tags_table() . ' (folder_id, attachment_id) VALUES (%d, %d)',
            $folder, (int) $r['attachment_id']));
        $also[] = array('id' => $fid, 'name' => $fac['name'], 'folder' => $folder);
        $have[] = $fid;
        $places[] = kop_wb_place($fid, $fac['name'], $folder, false);
    }
    if ($places) {
        $wpdb->update(kop_wb_table(), array('also_facilities' => wp_json_encode($also)), array('ckey' => $r['ckey']));
        $r['also_facilities'] = wp_json_encode($also);
        kop_wb_retitle($r);
        delete_transient('kop_hidden_preview_ids');
    }
    return $places;
}

/** Take one extra facility off a filed excerpt (the PDF stays with the others). */
function kop_wb_remove_facility(array $r, $fid) {
    global $wpdb;
    $keep = array();
    $removed = null;
    foreach (kop_wb_also($r) as $a) {
        if ((int) $a['id'] === (int) $fid && $removed === null) {
            $removed = $a;
        } else {
            $keep[] = $a;
        }
    }
    if (!$removed) {
        throw new RuntimeException('That facility is not an extra one on this excerpt. Use Undo to take the whole filing back.');
    }
    $wpdb->delete(kop_wb_tags_table(), array('folder_id' => (int) $removed['folder'], 'attachment_id' => (int) $r['attachment_id']), array('%d', '%d'));
    $wpdb->update(kop_wb_table(), array('also_facilities' => $keep ? wp_json_encode($keep) : null), array('ckey' => $r['ckey']));
    $r['also_facilities'] = $keep ? wp_json_encode($keep) : null;
    kop_wb_retitle($r);
    delete_transient('kop_hidden_preview_ids');
    return $removed;
}

/* ---- Covers for filed extracts, drawn in the background ------------- */

function kop_wb_schedule_covers($delay = 30) {
    if (!wp_next_scheduled('kop_wb_render_covers')) {
        wp_schedule_single_event(time() + $delay, 'kop_wb_render_covers');
    }
}

/**
 * Render the first-page cover of up to two filed extracts that have none.
 * Each is marked kop_pdf_preview_failed before rendering (as in
 * api/regenerate-pdf-previews.php), so one the host kills is tried once and
 * then left for that tool's ?retry=1.
 */
add_action('kop_wb_render_covers', function () {
    global $wpdb;
    $ids = $wpdb->get_col(
        "SELECT DISTINCT pm.post_id FROM {$wpdb->postmeta} pm
         JOIN {$wpdb->posts} p ON p.ID = pm.post_id AND p.post_type = 'attachment'
         LEFT JOIN {$wpdb->postmeta} f ON f.post_id = pm.post_id AND f.meta_key = 'kop_pdf_preview_failed'
         WHERE pm.meta_key = '_kop_woodbury_key' AND f.meta_id IS NULL
         ORDER BY pm.post_id"
    );
    $todo = array();
    foreach ($ids as $id) {
        $meta = wp_get_attachment_metadata((int) $id);
        if (empty($meta['sizes'])) {
            $todo[] = (int) $id;
        }
    }
    if (!$todo) {
        return;
    }
    require_once ABSPATH . 'wp-admin/includes/image.php';
    @set_time_limit(120);
    foreach (array_slice($todo, 0, 2) as $id) {
        $file = get_attached_file($id);
        update_post_meta($id, 'kop_pdf_preview_failed', 'killed ' . gmdate('c'));
        if (!$file || !file_exists($file)) {
            continue;
        }
        $meta = wp_generate_attachment_metadata($id, $file);
        if (is_array($meta) && !empty($meta['sizes'])) {
            delete_post_meta($id, 'kop_pdf_preview_failed');
            wp_update_attachment_metadata($id, $meta);
        } else {
            update_post_meta($id, 'kop_pdf_preview_failed', gmdate('c'));
        }
    }
    delete_transient('kop_hidden_preview_ids');
    if (count($todo) > 2) {
        kop_wb_schedule_covers(60);
    }
});

/* ---- Extra copies left by retried filings ----------------------------- */

/** Every file an attachment owns on disk: the PDF and its preview images. */
function kop_wb_attachment_files($id) {
    $file = get_attached_file($id);
    if (!$file) {
        return array();
    }
    $out = array(wp_normalize_path($file));
    $meta = wp_get_attachment_metadata($id);
    $dir = trailingslashit(dirname($file));
    foreach ((is_array($meta) && !empty($meta['sizes']) ? $meta['sizes'] : array()) as $size) {
        if (!empty($size['file'])) {
            $out[] = wp_normalize_path($dir . $size['file']);
        }
    }
    return $out;
}

/**
 * Candidates imported more than once (a 503'd batch sent again while the
 * first was still running). Keeps the copy the candidate row points at, or
 * the oldest; an extra that shares a file with the kept copy is left alone.
 *
 * @return array<int, array{ckey:string, keep:int, extras:int[], title:string}>
 */
function kop_wb_duplicate_copies() {
    global $wpdb;
    $rows = $wpdb->get_results(
        "SELECT pm.meta_value AS ckey, GROUP_CONCAT(pm.post_id ORDER BY pm.post_id) AS ids
         FROM {$wpdb->postmeta} pm JOIN {$wpdb->posts} p ON p.ID = pm.post_id AND p.post_type = 'attachment'
         WHERE pm.meta_key = '_kop_woodbury_key'
         GROUP BY pm.meta_value HAVING COUNT(*) > 1",
        ARRAY_A
    );
    $out = array();
    foreach ((array) $rows as $row) {
        $ids = array_map('intval', explode(',', $row['ids']));
        $cand = kop_wb_get($row['ckey']);
        $keep = $cand && in_array((int) $cand['attachment_id'], $ids, true) ? (int) $cand['attachment_id'] : $ids[0];
        $kept_files = kop_wb_attachment_files($keep);
        $extras = array();
        foreach ($ids as $id) {
            if ($id !== $keep && !array_intersect(kop_wb_attachment_files($id), $kept_files)) {
                $extras[] = $id;
            }
        }
        if ($extras) {
            $out[] = array('ckey' => $row['ckey'], 'keep' => $keep, 'extras' => $extras, 'title' => get_the_title($keep));
        }
    }
    return $out;
}

add_action('admin_post_kop_wb_dedupe', function () {
    if (!current_user_can('manage_options')) {
        wp_die('Not allowed.', 403);
    }
    check_admin_referer('kop_wb_dedupe');
    global $wpdb;
    $removed = 0;
    foreach (kop_wb_duplicate_copies() as $dup) {
        foreach ($dup['extras'] as $id) {
            $wpdb->delete($wpdb->prefix . 'fbv_attachment_folder', array('attachment_id' => $id), array('%d'));
            if (wp_delete_attachment($id, true)) {
                $removed++;
            }
        }
    }
    delete_transient('kop_hidden_preview_ids');
    wp_safe_redirect(add_query_arg('wb_deduped', $removed, wp_get_referer() ?: admin_url('admin.php?page=kop-woodbury-reports')));
    exit;
});

/** Delete the copy this tool imported and put the candidate back in the queue. */
function kop_wb_undo(array $r, $reviewer) {
    global $wpdb;
    $att = (int) $r['attachment_id'];
    foreach (kop_wb_also($r) as $a) {
        $wpdb->delete(kop_wb_tags_table(), array('folder_id' => (int) $a['folder'], 'attachment_id' => $att), array('%d', '%d'));
    }
    if ($att && get_post_meta($att, '_kop_woodbury_key', true) === $r['ckey']) {
        wp_delete_attachment($att, true);
        $wpdb->delete($wpdb->prefix . 'fbv_attachment_folder', array('attachment_id' => $att), array('%d'));
        $wpdb->delete(kop_wb_tags_table(), array('attachment_id' => $att), array('%d'));
    }
    $wpdb->update(kop_wb_table(), array(
        'status' => 'pending', 'attachment_id' => 0, 'folder_id' => 0, 'also_facilities' => null,
        'reviewed_by' => $reviewer, 'reviewed_at' => current_time('mysql', true),
    ), array('ckey' => $r['ckey']));
}

function kop_wb_set_status(array $r, $status, $reviewer) {
    global $wpdb;
    $wpdb->update(kop_wb_table(), array(
        'status' => $status, 'reviewed_by' => $reviewer, 'reviewed_at' => current_time('mysql', true),
    ), array('ckey' => $r['ckey']));
}

/* ---- AJAX: file / skip / undo / reopen, and viewing an extract -------- */

add_action('wp_ajax_kop_wb_act', function () {
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Not allowed.', 403);
    }
    check_ajax_referer('kop_woodbury', 'nonce');
    $act = sanitize_key($_POST['act'] ?? '');
    $items = isset($_POST['items']) && is_array($_POST['items']) ? $_POST['items'] : array();
    $user = wp_get_current_user()->user_login;
    $out = array();
    foreach (array_slice($items, 0, 10) as $item) {
        $ckey = preg_replace('/[^a-f0-9]/', '', (string) ($item['key'] ?? ''));
        $res = array('key' => $ckey);
        try {
            $r = $ckey !== '' ? kop_wb_get($ckey) : null;
            if (!$r) {
                throw new RuntimeException('Not found.');
            }
            $fids = isset($item['fids']) && is_array($item['fids']) ? array_map('intval', $item['fids']) : array();
            if ($act === 'file') {
                $res += kop_wb_file_many($r, $fids, $user);
            } elseif ($act === 'add') {
                $res['places'] = kop_wb_add_facilities($r, $fids);
                if (!$res['places']) {
                    throw new RuntimeException('Already filed under that facility.');
                }
            } elseif ($act === 'untag') {
                $res['removed'] = kop_wb_remove_facility($r, (int) ($item['fid'] ?? 0));
            } elseif ($act === 'skip') {
                kop_wb_set_status($r, 'skipped', $user);
            } elseif ($act === 'undo') {
                kop_wb_undo($r, $user);
            } elseif ($act === 'reopen') {
                kop_wb_set_status($r, 'pending', $user);
            } else {
                throw new RuntimeException('Unknown action.');
            }
            $res['ok'] = true;
        } catch (Throwable $e) {
            $res['ok'] = false;
            $res['error'] = $e->getMessage();
        }
        $out[] = $res;
    }
    wp_send_json_success($out);
});

add_action('wp_ajax_kop_wb_view', function () {
    if (!current_user_can('manage_options')) {
        wp_die('Not allowed.', 403);
    }
    check_ajax_referer('kop_woodbury', 'nonce');
    $r = kop_wb_get(preg_replace('/[^a-f0-9]/', '', (string) ($_GET['key'] ?? '')));
    $path = $r ? kop_wb_pending_dir() . '/' . basename($r['file']) : '';
    if (!$r || $r['file'] === '' || !is_readable($path)) {
        wp_die('The extract PDF is not on the server.', 404);
    }
    nocache_headers();
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="' . str_replace('"', '', basename($r['file'])) . '"');
    header('Content-Length: ' . filesize($path));
    readfile($path);
    exit;
});

/* ---- Review screen --------------------------------------------------- */

add_action('admin_menu', function () {
    if (!function_exists('kop_tools_parent_slug')) {
        return;
    }
    add_submenu_page(kop_tools_parent_slug(), 'Woodbury Reports', 'Woodbury Reports', 'manage_options',
        'kop-woodbury-reports', 'kop_render_woodbury_page');
}, 21);

/** The review tabs: label, status, kinds. */
function kop_wb_tabs() {
    return array(
        'articles' => array('label' => 'Articles',        'status' => 'pending', 'kinds' => array('section', 'fuzzy')),
        'news'     => array('label' => 'News items',      'status' => 'pending', 'kinds' => array('news')),
        'mentions' => array('label' => 'Other mentions',  'status' => 'pending', 'kinds' => array('mention')),
        'norecord' => array('label' => 'No record yet',   'status' => 'pending', 'kinds' => array('unmatched')),
        'filed'    => array('label' => 'Filed',           'status' => 'filed',   'kinds' => array()),
        'skipped'  => array('label' => 'Skipped',         'status' => 'skipped', 'kinds' => array()),
    );
}

function kop_wb_tab_where($tab) {
    global $wpdb;
    $t = kop_wb_tabs()[$tab];
    $where = $wpdb->prepare('status = %s', $t['status']);
    if ($t['kinds']) {
        $where .= " AND kind IN ('" . implode("','", array_map('esc_sql', $t['kinds'])) . "')";
    }
    return $where;
}

function kop_render_woodbury_page() {
    if (!current_user_can('manage_options')) {
        wp_die('Not authorized', 'Access Denied', array('response' => 403));
    }
    global $wpdb;
    kop_wb_ensure_table();
    $sync = kop_wb_sync(isset($_GET['wb_resync']));
    $table = kop_wb_table();
    $tabs = kop_wb_tabs();
    $tab = isset($_GET['wb_tab'], $tabs[$_GET['wb_tab']]) ? $_GET['wb_tab'] : 'articles';
    $q = isset($_GET['wb_q']) ? trim(sanitize_text_field(wp_unslash($_GET['wb_q']))) : '';
    $paged = max(1, (int) ($_GET['wb_page'] ?? 1));
    $per = 60;
    $base = admin_url('admin.php?page=kop-woodbury-reports');

    echo '<div class="wrap kop-wb"><h1>Woodbury Reports</h1>';
    if ($sync) {
        echo '<div class="notice notice-info"><p>Loaded a new scan: ' . (int) $sync['added'] . ' new, '
            . (int) $sync['updated'] . ' updated, ' . (int) $sync['gone'] . ' no longer found.</p></div>';
    }
    if (isset($_GET['wb_deduped'])) {
        echo '<div class="notice notice-success"><p>Removed ' . (int) $_GET['wb_deduped'] . ' extra ' . ((int) $_GET['wb_deduped'] === 1 ? 'copy' : 'copies') . '.</p></div>';
    }
    $dups = kop_wb_duplicate_copies();
    if ($dups) {
        $n = array_sum(array_map(function ($d) { return count($d['extras']); }, $dups));
        echo '<div class="notice notice-warning"><p><strong>' . count($dups) . ' ' . (count($dups) === 1 ? 'report was' : 'reports were')
            . ' imported more than once</strong> (a filing retried while the first try was still running). '
            . 'Removing the extras keeps one copy of each, the one filed in the program folder.</p><ul style="list-style:disc;margin-left:20px">';
        foreach ($dups as $d) {
            echo '<li><a href="' . esc_url(get_edit_post_link($d['keep'])) . '">' . esc_html($d['title'] !== '' ? $d['title'] : '#' . $d['keep']) . '</a>: '
                . count($d['extras']) . ' extra</li>';
        }
        echo '</ul><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin-bottom:10px">'
            . '<input type="hidden" name="action" value="kop_wb_dedupe">' . wp_nonce_field('kop_wb_dedupe', '_wpnonce', true, false)
            . '<button class="button button-primary">Remove the ' . (int) $n . ' extra ' . ($n === 1 ? 'copy' : 'copies') . '</button></form></div>';
    }
    if (!is_readable(kop_wb_pending_dir() . '/candidates.json')) {
        echo '<div class="notice notice-warning"><p>No scan uploaded yet. Run <code>python scripts/woodbury-scan.py</code> and copy its '
            . '<code>pending</code> folder to <code>' . esc_html(kop_wb_pending_dir()) . '</code>.</p></div>';
    }
    echo '<p>Pages of the Woodbury Reports newsletter that write about a program, found by scanning every issue in the media library. '
        . 'Filing one puts those pages, as their own small PDF, in a "Woodbury Reports Mentions" folder on each facility\'s page, '
        . 'linked to the full issue. Nothing is filed until you click, and <strong>Undo</strong> on the Filed tab takes it back out.</p>'
        . '<ol class="kop-wb-how">'
        . '<li><strong>Check who it is about.</strong> Under <em>File under</em>, tick every facility the pages discuss (one excerpt can go to several). '
        . 'The scanner\'s best match is ticked for you; untick it if it is wrong. <em>Add a facility</em> finds any other by name. '
        . '<strong>View pages</strong> opens the excerpt.</li>'
        . '<li><strong>File it.</strong> Click <em>File it</em> on the row, or tick <em>Select</em> on several rows and use <em>File selected</em> at the top. '
        . 'Each row then lists the folders it went into, and a summary of everything filed builds up at the top of the page.</li>'
        . '<li><strong>About an educational consultant, company, provider or transporter?</strong> Search for it under '
        . '<em>Or a consultant, company, provider or transporter</em> and click <em>File here</em>.</li>'
        . '<li><strong>Not useful?</strong> <em>Skip</em> it. About something with no record yet (a program, company, educational consultant or provider)? '
        . 'Open <em>Create a record</em> under it to make the record and file the pages in one click.</li></ol>';

    $counts = array();
    foreach ($tabs as $k => $t) {
        $counts[$k] = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE " . kop_wb_tab_where($k));
    }
    echo '<ul class="subsubsub">';
    $i = 0;
    foreach ($tabs as $k => $t) {
        echo '<li><a href="' . esc_url(add_query_arg(array('wb_tab' => $k, 'wb_q' => $q !== '' ? $q : null), $base)) . '"'
            . ($tab === $k ? ' class="current"' : '') . '>' . esc_html($t['label']) . ' <span class="count">(' . $counts[$k] . ')</span></a>'
            . (++$i < count($tabs) ? ' | ' : '') . '</li>';
    }
    echo '</ul><div style="clear:both"></div>';

    echo '<form method="get" style="margin:8px 0"><input type="hidden" name="page" value="kop-woodbury-reports">'
        . '<input type="hidden" name="wb_tab" value="' . esc_attr($tab) . '">'
        . '<input type="search" name="wb_q" value="' . esc_attr($q) . '" placeholder="Program name" style="width:260px"> '
        . '<button class="button">Filter</button>'
        . ($q !== '' ? ' <a href="' . esc_url(add_query_arg('wb_tab', $tab, $base)) . '">Clear</a>' : '') . '</form>';

    $where = kop_wb_tab_where($tab);
    if ($q !== '') {
        $like = '%' . $wpdb->esc_like($q) . '%';
        $where .= $wpdb->prepare(' AND (facility_name LIKE %s OR header LIKE %s OR matched_name LIKE %s)', $like, $like, $like);
    }
    $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE {$where}");
    $order = in_array($tab, array('filed', 'skipped'), true) ? 'reviewed_at DESC' : "facility_name = '', facility_name, header, issue_date";
    $rows = $wpdb->get_results("SELECT * FROM {$table} WHERE {$where} ORDER BY {$order} LIMIT " . (($paged - 1) * $per) . ", {$per}", ARRAY_A);

    if (!$rows) {
        echo '<p>Nothing here.</p></div>';
        return;
    }

    $pending = !in_array($tab, array('filed', 'skipped'), true);
    echo '<div class="notice notice-success kop-wb-log" hidden><p><strong>Done on this page</strong></p><ul></ul></div>';
    echo '<div class="kop-wb-bar"><label class="kop-wb-allbox"><input type="checkbox" class="kop-wb-all"> Select all on this page</label> '
        . '<span class="kop-wb-count"></span> ';
    if ($pending) {
        echo '<button type="button" class="button button-primary" data-bulk="file">File selected</button> '
            . '<button type="button" class="button" data-bulk="skip">Skip selected</button>';
    } elseif ($tab === 'filed') {
        echo '<button type="button" class="button" data-bulk="undo">Undo selected</button>';
    } else {
        echo '<button type="button" class="button" data-bulk="reopen">Put selected back</button>';
    }
    echo ' <span class="kop-wb-progress" aria-live="polite"></span>'
        . ($tab === 'articles' ? '<div class="kop-wb-muted">Articles with a confident match start selected.</div>' : '') . '</div>';

    echo '<table class="widefat kop-wb-table"><thead><tr><th class="kop-wb-selcol">Select</th>'
        . '<th style="width:30%">' . ($tab === 'filed' ? 'Filed under' : 'File under') . '</th>'
        . '<th>Woodbury Reports pages</th><th style="width:150px">Action</th></tr></thead><tbody>';
    foreach ($rows as $r) {
        kop_wb_render_row($r, $tab);
    }
    echo '</tbody></table>';

    $pages = (int) ceil($total / $per);
    if ($pages > 1) {
        echo '<p class="kop-wb-pager">Page ' . $paged . ' of ' . $pages . ' &middot; ';
        for ($n = 1; $n <= $pages; $n++) {
            $url = add_query_arg(array('wb_tab' => $tab, 'wb_page' => $n, 'wb_q' => $q !== '' ? $q : null), $base);
            echo $n === $paged ? '<strong>' . $n . '</strong> ' : '<a href="' . esc_url($url) . '">' . $n . '</a> ';
        }
        echo '</p>';
    }
    kop_wb_render_assets();
    if (function_exists('kop_wbc_render_assets')) {
        kop_wbc_render_assets();
    }
    echo '</div>';
}

function kop_wb_facility_link($fid, $name, $state = '') {
    $url = function_exists('kop_facility_page_url') ? kop_facility_page_url((int) $fid) : '';
    $label = esc_html($name) . ($state !== '' ? ' <span class="kop-wb-muted">' . esc_html($state) . '</span>' : '');
    return $url ? '<a href="' . esc_url($url) . '" target="_blank" rel="noopener">' . $label . '</a>' : $label;
}

/** One "Filed under" line: the record, the folder it is in, and Remove for an extra facility. */
function kop_wb_place_html(array $p) {
    $name = $p['page'] !== ''
        ? '<a href="' . esc_url($p['page']) . '" target="_blank" rel="noopener"><strong>' . esc_html($p['name']) . '</strong></a>'
        : '<strong>' . esc_html($p['name']) . '</strong>' . ($p['id'] ? ' <span class="kop-wb-muted">(no public page yet)</span>' : '');
    return '<li data-fid="' . (int) $p['id'] . '">' . $name
        . ($p['where'] !== '' ? '<span class="kop-wb-where">Folder: ' . esc_html($p['where']) . '</span>' : '')
        . (!$p['primary'] ? ' <button type="button" class="button-link kop-wb-untag" data-fid="' . (int) $p['id'] . '">Remove</button>' : '')
        . '</li>';
}

/** A "File under" checkbox for one facility. */
function kop_wb_fac_box($fid, $name, $state, $checked, $note = '') {
    return '<label class="kop-wb-fac-row"><input type="checkbox" class="kop-wb-fac" value="' . (int) $fid . '"' . ($checked ? ' checked' : '') . '> '
        . kop_wb_facility_link($fid, $name, $state) . ($note !== '' ? ' <span class="kop-wb-muted">' . esc_html($note) . '</span>' : '') . '</label>';
}

function kop_wb_render_row(array $r, $tab) {
    $view = add_query_arg(array('action' => 'kop_wb_view', 'key' => $r['ckey'], 'nonce' => wp_create_nonce('kop_woodbury')), admin_url('admin-ajax.php'));
    $issue_url = $r['issue_id'] ? wp_get_attachment_url((int) $r['issue_id']) : '';
    $alts = json_decode((string) $r['alternatives'], true);
    $alts = is_array($alts) ? $alts : array();
    $pending = $r['status'] === 'pending';
    $filed = $r['status'] === 'filed';
    $kinds = array('section' => 'Article', 'fuzzy' => 'Article, close name', 'news' => 'News item', 'mention' => 'Mentioned', 'unmatched' => 'Article, no record');
    $label = 'Woodbury Reports, ' . $r['issue_label'] . ', ' . kop_wb_page_label($r['pages']);
    $selected = $pending && $r['kind'] === 'section';

    echo '<tr data-key="' . esc_attr($r['ckey']) . '" data-label="' . esc_attr($label) . '"' . ($selected ? ' class="kop-wb-selected"' : '') . '>';
    echo '<td class="kop-wb-selcol"><label class="kop-wb-sel"><input type="checkbox" class="kop-wb-pick"' . ($selected ? ' checked' : '') . '><span>Select</span></label></td>';

    // Who it is filed under.
    echo '<td>';
    if ($pending) {
        echo '<div class="kop-wb-facs">';
        $shown = array();
        if ($r['facility_id']) {
            echo kop_wb_fac_box($r['facility_id'], $r['facility_name'], $r['facility_state'], true, $r['kind'] === 'fuzzy' ? 'close name, check it' : 'best match');
            $shown[(int) $r['facility_id']] = true;
        }
        foreach (array_slice($alts, 0, 5) as $a) {
            if (!isset($shown[(int) $a['id']])) {
                echo kop_wb_fac_box($a['id'], $a['name'], $a['state'] ?? '', false, 'other possible match');
                $shown[(int) $a['id']] = true;
            }
        }
        echo '</div>';
        if (!$shown) {
            echo '<p class="kop-wb-none">No facility matched this name. Add one below, or create a record.</p>';
        }
        echo '<div class="kop-wb-add">Add a facility: ' . kop_facility_finder_field('', '', ' class="kop-wb-fid"', true) . '</div>';
        if (function_exists('kop_wbc_render_finder')) {
            kop_wbc_render_finder();
        }
    } elseif ($filed) {
        echo '<ul class="kop-wb-places">';
        foreach (kop_wb_places($r) as $p) {
            echo kop_wb_place_html($p);
        }
        echo '</ul>';
        echo '<div class="kop-wb-add">Also file under: ' . kop_facility_finder_field('', '', ' class="kop-wb-fid"', true) . '</div>';
    } elseif ($r['facility_name'] !== '') {
        echo '<strong>' . esc_html($r['facility_name']) . '</strong>';
    }
    $said = $r['header'] !== '' ? $r['header'] . ($r['place'] !== '' ? ', ' . $r['place'] : '') : $r['matched_name'];
    echo '<div class="kop-wb-muted">' . esc_html($kinds[$r['kind']] ?? $r['kind']) . ($said !== '' ? ': the pages say &ldquo;' . esc_html($said) . '&rdquo;' : '') . '</div>';
    if (trim((string) $r['note']) !== '') {
        echo '<div class="kop-wb-note">' . esc_html($r['note']) . '</div>';
    }
    if ($pending && function_exists('kop_wbc_render_form')) {
        kop_wbc_render_form($r);
    }
    echo '</td>';

    // Evidence column.
    echo '<td><strong>' . esc_html($r['issue_label']) . ($r['issue_number'] !== '' ? ' (' . esc_html($r['issue_number']) . ')' : '')
        . ', ' . esc_html(kop_wb_page_label($r['pages'])) . '</strong> &middot; ';
    if ($filed && $r['attachment_id']) {
        echo '<a href="' . esc_url(wp_get_attachment_url((int) $r['attachment_id'])) . '" target="_blank" rel="noopener">Filed PDF</a>';
    } else {
        echo '<a href="' . esc_url($view) . '" target="_blank" rel="noopener">View pages</a>';
    }
    if ($issue_url) {
        echo ' &middot; <a href="' . esc_url($issue_url) . '#page=' . (int) strtok($r['pages'], ',') . '" target="_blank" rel="noopener">Full issue</a>';
    }
    $snippet = trim((string) $r['snippet']);
    if ($snippet !== '') {
        $short = mb_strlen($snippet) > 320 ? mb_substr($snippet, 0, 320) . '...' : $snippet;
        echo '<blockquote class="kop-wb-quote">' . esc_html($short) . '</blockquote>';
        if ($short !== $snippet) {
            echo '<details><summary>More</summary><blockquote class="kop-wb-quote">' . esc_html($snippet) . '</blockquote></details>';
        }
    }
    echo '</td>';

    // Actions.
    echo '<td class="kop-wb-actions">';
    if ($pending) {
        echo '<button type="button" class="button button-primary" data-act="file">File it</button> '
            . '<button type="button" class="button" data-act="skip">Skip</button>';
    } elseif ($filed) {
        echo '<span class="kop-wb-muted">Filed' . ($r['reviewed_by'] ? ' by ' . esc_html($r['reviewed_by']) : '') . '</span><br>'
            . '<button type="button" class="button button-small" data-act="undo">Undo filing</button>';
    } else {
        echo '<button type="button" class="button button-small" data-act="reopen">Put back</button>';
    }
    echo '<div class="kop-wb-result" aria-live="polite"></div></td>';
    echo '</tr>';
}

function kop_wb_render_assets() {
    $nonce = wp_create_nonce('kop_woodbury');
    ?>
    <style>
        .kop-wb-how { margin: 0 0 12px 20px; max-width: 960px; }
        .kop-wb-how li { margin-bottom: 4px; }
        .kop-wb-bar { position: sticky; top: 32px; z-index: 5; background: #f0f0f1; padding: 8px 0; border-bottom: 1px solid #dcdcde; }
        .kop-wb-allbox { font-weight: 600; margin-right: 6px; }
        .kop-wb-count { display: inline-block; min-width: 90px; margin-right: 6px; color: #1d2327; }
        .kop-wb-table td { vertical-align: top; }
        .kop-wb-table tbody tr { background: #fff; }
        .kop-wb-table tbody tr:nth-child(even) { background: #f9f9f9; }
        .kop-wb-table tbody tr.kop-wb-selected { background: #e8f4f6; box-shadow: inset 4px 0 0 #33A7B5; }
        .kop-wb-selcol { width: 64px; text-align: center; }
        .kop-wb-sel { display: inline-flex; flex-direction: column; align-items: center; gap: 2px; font-size: 11px; color: #50575e; cursor: pointer; }
        .kop-wb-sel input { margin: 0; transform: scale(1.3); }
        .kop-wb-facs { margin-bottom: 4px; }
        .kop-wb-fac-row { display: block; margin: 3px 0; }
        .kop-wb-none { margin: 0 0 4px; font-style: italic; }
        .kop-wb-add { margin-top: 6px; color: #50575e; font-size: 12px; }
        .kop-wb-places { margin: 0; }
        .kop-wb-places li { margin: 0 0 6px; }
        .kop-wb-where { display: block; color: #50575e; font-size: 12px; }
        .kop-wb-untag { color: #b32d2e !important; font-size: 12px; }
        .kop-wb-muted { color: #666; font-size: 12px; margin-top: 4px; }
        .kop-wb-note { color: #8a4b00; font-size: 12px; margin-top: 4px; }
        .kop-wb-quote { margin: 6px 0; padding-left: 8px; border-left: 3px solid #33A7B5; color: #333; }
        .kop-wb-actions .button { margin-bottom: 4px; }
        .kop-wb-result { font-size: 12px; margin-top: 4px; }
        .kop-wb-result ul { margin: 4px 0 0; }
        .kop-wb-result.ok { color: #1a7f37; }
        .kop-wb-result.err { color: #d63638; }
        tr.kop-wb-done td:not(.kop-wb-actions) { opacity: .6; }
        .kop-wb-log ul { margin: 0 0 8px 18px; list-style: disc; }
        .kop-wb-log li span { color: #50575e; }
    </style>
    <script>
    (function () {
        var ajax = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;
        var nonce = <?php echo wp_json_encode($nonce); ?>;
        var progress = document.querySelector('.kop-wb-progress');
        var count = document.querySelector('.kop-wb-count');
        var log = document.querySelector('.kop-wb-log');
        var labels = { file: 'Filed', skip: 'Skipped', undo: 'Filing undone', reopen: 'Back in the queue', add: 'Also filed', untag: 'Removed' };

        function el(tag, cls, text) {
            var e = document.createElement(tag);
            if (cls) e.className = cls;
            if (text) e.textContent = text;
            return e;
        }
        function link(href, text) {
            var a = el('a', '', text);
            a.href = href; a.target = '_blank'; a.rel = 'noopener';
            return a;
        }
        // "Wellspring Academies (Folder: Vermont › Wellspring Academies › Woodbury Reports Mentions)"
        function placeLine(p) {
            var li = el('li');
            li.appendChild(p.page ? link(p.page, p.name) : el('strong', '', p.name));
            if (p.where) li.appendChild(el('span', 'kop-wb-where', 'Folder: ' + p.where));
            return li;
        }
        function addToLog(tr, verb, places, pdf) {
            if (!log) return;
            log.hidden = false;
            var li = el('li');
            li.appendChild(el('strong', '', tr.getAttribute('data-label')));
            li.appendChild(document.createTextNode(' ' + verb + ' '));
            places.forEach(function (p, i) {
                if (i) li.appendChild(document.createTextNode('; '));
                li.appendChild(p.page ? link(p.page, p.name) : el('strong', '', p.name));
                if (p.where) li.appendChild(el('span', '', ' (' + p.where + ')'));
            });
            if (pdf) { li.appendChild(document.createTextNode(' · ')); li.appendChild(link(pdf, 'PDF')); }
            log.querySelector('ul').appendChild(li);
        }
        function markDone(tr) {
            tr.classList.add('kop-wb-done');
            tr.classList.remove('kop-wb-selected');
            tr.querySelectorAll('.kop-wb-actions button, .kop-wbc-go').forEach(function (b) { b.disabled = true; });
            var pick = tr.querySelector('.kop-wb-pick');
            if (pick) { pick.checked = false; pick.disabled = true; }
            updateCount();
        }
        // Shown on the row and in the summary once pages are filed (also used by "Create a record").
        window.kopWbFiled = function (tr, filed, verb) {
            var out = tr.querySelector('.kop-wb-result');
            out.className = 'kop-wb-result ok';
            out.textContent = (verb || 'Filed') + ' under:';
            var ul = el('ul');
            (filed.places || []).forEach(function (p) { ul.appendChild(placeLine(p)); });
            out.appendChild(ul);
            if (filed.url) out.appendChild(link(filed.url, 'Open the filed PDF'));
            addToLog(tr, (verb || 'filed').toLowerCase() + ' under', filed.places || [], filed.url);
            markDone(tr);
        };

        function item(tr, act) {
            var it = { key: tr.getAttribute('data-key'), fids: [] };
            if (act === 'file') {
                tr.querySelectorAll('.kop-wb-fac:checked').forEach(function (c) { it.fids.push(c.value); });
            } else if (act === 'add') {
                it.fids = tr._kopAdd || [];
            } else if (act === 'untag') {
                it.fid = tr._kopUntag;
            }
            return it;
        }

        function send(act, rows) {
            var body = new FormData();
            body.append('action', 'kop_wb_act');
            body.append('nonce', nonce);
            body.append('act', act);
            rows.forEach(function (tr, i) {
                var it = item(tr, act);
                body.append('items[' + i + '][key]', it.key);
                it.fids.forEach(function (f) { body.append('items[' + i + '][fids][]', f); });
                if (it.fid) body.append('items[' + i + '][fid]', it.fid);
            });
            return fetch(ajax, { method: 'POST', body: body, credentials: 'same-origin' })
                .then(function (r) {
                    // The host's 503 page is not JSON, and the server may still be finishing the batch.
                    if (!r.ok) throw new Error('the server answered ' + r.status + '; it may still be finishing. Reload the page before trying these again');
                    return r.json();
                })
                .then(function (j) {
                    if (!j || !j.success) throw new Error((j && j.data) || 'Request failed');
                    j.data.forEach(function (res) {
                        var tr = document.querySelector('tr[data-key="' + res.key + '"]');
                        if (!tr) return;
                        var out = tr.querySelector('.kop-wb-result');
                        if (!res.ok) {
                            out.className = 'kop-wb-result err';
                            out.textContent = res.error || 'Failed';
                            return;
                        }
                        if (act === 'file') {
                            window.kopWbFiled(tr, res);
                        } else if (act === 'add') {
                            var list = tr.querySelector('.kop-wb-places');
                            res.places.forEach(function (p) {
                                var li = placeLine(p);
                                li.setAttribute('data-fid', p.id);
                                li.appendChild(document.createTextNode(' '));
                                li.appendChild(untagButton(p.id));
                                list.appendChild(li);
                            });
                            out.className = 'kop-wb-result ok';
                            out.textContent = 'Also filed under ' + res.places.map(function (p) { return p.name; }).join(', ') + '.';
                            addToLog(tr, 'also filed under', res.places, '');
                        } else if (act === 'untag') {
                            var gone = tr.querySelector('.kop-wb-places li[data-fid="' + res.removed.id + '"]');
                            if (gone) gone.remove();
                            out.className = 'kop-wb-result ok';
                            out.textContent = 'Removed from ' + res.removed.name + '.';
                            addToLog(tr, 'removed from', [{ name: res.removed.name }], '');
                        } else {
                            out.className = 'kop-wb-result ok';
                            out.textContent = labels[act] + '.';
                            addToLog(tr, labels[act].toLowerCase(), [], '');
                            markDone(tr);
                        }
                    });
                });
        }

        var running = false;
        function run(act, rows) {
            if (running) { progress.textContent = 'Still working on the last request; wait for it to finish.'; return; }
            running = true;
            var queue = rows.slice(), done = 0, total = rows.length, size = act === 'file' ? 3 : 10;
            function next() {
                if (!queue.length) {
                    running = false;
                    progress.textContent = total > 1 ? labels[act] + ' ' + done + ' of ' + total + '. Each row says where it went; the summary is at the top.' : '';
                    return;
                }
                var batch = queue.splice(0, size);
                progress.textContent = 'Working... ' + done + ' of ' + total;
                return send(act, batch).then(function () { done += batch.length; return next(); })
                    .catch(function (e) { running = false; progress.textContent = 'Stopped after ' + done + ' of ' + total + ': ' + e.message; });
            }
            return next();
        }

        function untagButton(fid) {
            var b = el('button', 'button-link kop-wb-untag', 'Remove');
            b.type = 'button';
            b.setAttribute('data-fid', fid);
            return b;
        }
        document.addEventListener('click', function (e) {
            var b = e.target.closest && e.target.closest('.kop-wb-untag');
            if (!b) return;
            var tr = b.closest('tr');
            tr._kopUntag = b.getAttribute('data-fid');
            run('untag', [tr]);
        });

        // A facility picked in "Add a facility": a ticked box on a waiting row, filed at once on a filed row.
        document.addEventListener('kop-facility-picked', function (e) {
            var tr = e.target.closest('tr');
            if (!tr) return;
            var f = e.detail;
            var facs = tr.querySelector('.kop-wb-facs');
            if (!facs) {
                tr._kopAdd = [f.id];
                run('add', [tr]);
                return;
            }
            var box = facs.querySelector('.kop-wb-fac[value="' + f.id + '"]');
            if (!box) {
                var lab = el('label', 'kop-wb-fac-row');
                box = el('input', 'kop-wb-fac');
                box.type = 'checkbox';
                box.value = f.id;
                lab.appendChild(box);
                lab.appendChild(document.createTextNode(' '));
                lab.appendChild(f.url ? link(f.url, f.name) : el('strong', '', f.name));
                var place = [f.city, f.state || f.country].filter(Boolean).join(', ');
                if (place) lab.appendChild(el('span', 'kop-wb-muted', ' ' + place));
                facs.appendChild(lab);
            }
            box.checked = true;
            var none = tr.querySelector('.kop-wb-none');
            if (none) none.hidden = true;
            select(tr, true);
        });

        function select(tr, on) {
            var pick = tr.querySelector('.kop-wb-pick');
            if (!pick || pick.disabled) return;
            pick.checked = on;
            tr.classList.toggle('kop-wb-selected', on);
            updateCount();
        }
        function updateCount() {
            if (!count) return;
            var n = document.querySelectorAll('.kop-wb-pick:checked').length;
            count.textContent = n === 1 ? '1 row selected' : n + ' rows selected';
        }
        document.querySelectorAll('.kop-wb-pick').forEach(function (c) {
            c.addEventListener('change', function () { select(c.closest('tr'), c.checked); });
        });
        document.addEventListener('change', function (e) {
            if (e.target.classList && e.target.classList.contains('kop-wb-fac') && e.target.checked) select(e.target.closest('tr'), true);
        });

        document.querySelectorAll('.kop-wb-table button[data-act]').forEach(function (b) {
            b.addEventListener('click', function () {
                var tr = b.closest('tr'), act = b.getAttribute('data-act');
                if (act === 'file' && !tr.querySelector('.kop-wb-fac:checked')) {
                    var out = tr.querySelector('.kop-wb-result');
                    out.className = 'kop-wb-result err';
                    out.textContent = 'Tick at least one facility under File under first.';
                    return;
                }
                run(act, [tr]);
            });
        });
        document.querySelectorAll('.kop-wb-bar button[data-bulk]').forEach(function (b) {
            b.addEventListener('click', function () {
                var rows = Array.prototype.map.call(document.querySelectorAll('.kop-wb-pick:checked'), function (c) { return c.closest('tr'); });
                if (!rows.length) { progress.textContent = 'Tick Select on some rows first.'; return; }
                run(b.getAttribute('data-bulk'), rows);
            });
        });
        var all = document.querySelector('.kop-wb-all');
        if (all) all.addEventListener('change', function () {
            document.querySelectorAll('.kop-wb-pick:not(:disabled)').forEach(function (c) { select(c.closest('tr'), all.checked); });
        });
        updateCount();
    })();
    </script>
    <?php
}
