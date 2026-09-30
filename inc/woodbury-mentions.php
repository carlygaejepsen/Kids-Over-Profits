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
 * shows, so it appears there at once. Nothing is filed without a click; Undo
 * deletes the imported copy and puts the candidate back in the queue.
 *
 * Candidates live in {prefix}kop_woodbury_mentions, keyed by the scanner's
 * candidate key, so a rescan adds new ones and never undoes a decision.
 */

if (!defined('ABSPATH')) {
    exit;
}

define('KOP_WOODBURY_DB_VERSION', '2');
define('KOP_WOODBURY_SUBFOLDER', 'Woodbury Reports Mentions');

function kop_wb_table() {
    global $wpdb;
    return $wpdb->prefix . 'kop_woodbury_mentions';
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
    return array('attachment_id' => (int) $att, 'folder_id' => $folder, 'url' => (string) wp_get_attachment_url($att), 'facility' => $fac['name']);
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
    if ($att && get_post_meta($att, '_kop_woodbury_key', true) === $r['ckey']) {
        wp_delete_attachment($att, true);
        $wpdb->delete($wpdb->prefix . 'fbv_attachment_folder', array('attachment_id' => $att), array('%d'));
    }
    $wpdb->update(kop_wb_table(), array(
        'status' => 'pending', 'attachment_id' => 0, 'folder_id' => 0,
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
            if ($act === 'file') {
                $fid = (int) ($item['fid'] ?? 0) ?: (int) $r['facility_id'];
                $res += kop_wb_file($r, $fid, $user);
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
        . '<strong>File it</strong> puts those pages, as their own small PDF, in the program\'s document library (a "Woodbury Reports Mentions" '
        . 'folder on its facility page), linked to the full issue. Nothing is filed until you click. <strong>Undo</strong> takes it back out.</p>'
        . '<p style="color:#555">Tick rows and use the buttons at the top to do many at once. Check the program when a row says '
        . '<em>close name</em> or offers other matches: click the right one before filing. <strong>View pages</strong> opens the extract itself. '
        . 'When the report is about something with no record yet (a program, company, educational consultant or mental health provider), '
        . 'open <strong>Create a record</strong> under it: that makes the record and files the pages under it in one click.</p>';

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
    echo '<div class="kop-wb-bar">';
    if ($pending) {
        echo '<label><input type="checkbox" class="kop-wb-all"> Select all on this page</label> '
            . '<button type="button" class="button button-primary" data-bulk="file">File selected</button> '
            . '<button type="button" class="button" data-bulk="skip">Skip selected</button>';
    } elseif ($tab === 'filed') {
        echo '<label><input type="checkbox" class="kop-wb-all"> Select all on this page</label> '
            . '<button type="button" class="button" data-bulk="undo">Undo selected</button>';
    } else {
        echo '<label><input type="checkbox" class="kop-wb-all"> Select all on this page</label> '
            . '<button type="button" class="button" data-bulk="reopen">Put selected back</button>';
    }
    echo ' <span class="kop-wb-progress" aria-live="polite"></span></div>';

    echo '<table class="widefat striped kop-wb-table"><thead><tr><th style="width:24px"></th>'
        . '<th style="width:28%">Program</th><th>Woodbury Reports pages</th><th style="width:150px"></th></tr></thead><tbody>';
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

function kop_wb_render_row(array $r, $tab) {
    $view = add_query_arg(array('action' => 'kop_wb_view', 'key' => $r['ckey'], 'nonce' => wp_create_nonce('kop_woodbury')), admin_url('admin-ajax.php'));
    $issue_url = $r['issue_id'] ? wp_get_attachment_url((int) $r['issue_id']) : '';
    $alts = json_decode((string) $r['alternatives'], true);
    $alts = is_array($alts) ? $alts : array();
    $pending = $r['status'] === 'pending';
    $kinds = array('section' => 'Article', 'fuzzy' => 'Article, close name', 'news' => 'News item', 'mention' => 'Mentioned', 'unmatched' => 'Article, no record');

    echo '<tr data-key="' . esc_attr($r['ckey']) . '">';
    echo '<td><input type="checkbox" class="kop-wb-pick"' . ($pending && $r['kind'] === 'section' ? ' checked' : '') . '></td>';

    // Program column.
    echo '<td>';
    $name = 'fid_' . $r['ckey'];
    if ($r['facility_id']) {
        echo '<label class="kop-wb-choice"><input type="radio" name="' . esc_attr($name) . '" value="' . (int) $r['facility_id'] . '" checked> <strong>'
            . kop_wb_facility_link($r['facility_id'], $r['facility_name'], $r['facility_state']) . '</strong></label>';
        if ($pending) {
            foreach (array_slice($alts, 0, 5) as $a) {
                echo '<label class="kop-wb-choice"><input type="radio" name="' . esc_attr($name) . '" value="' . (int) $a['id'] . '"> '
                    . kop_wb_facility_link($a['id'], $a['name'], $a['state'] ?? '') . '</label>';
            }
        }
    } elseif ($pending) {
        echo '<em>No program record matches this name.</em>';
    } elseif ($r['facility_name'] !== '') {
        $kinds_made = function_exists('kop_wbc_kinds') ? kop_wbc_kinds() : array();
        echo '<strong>' . esc_html($r['facility_name']) . '</strong>'
            . (isset($kinds_made[$r['target_kind']]) ? ' <span class="kop-wb-muted">' . esc_html($kinds_made[$r['target_kind']]) . '</span>' : '');
    }
    if ($pending) {
        echo '<div class="kop-wb-choice kop-wb-other">Other facility ' . kop_facility_finder_field('', '', ' class="kop-wb-fid"') . '</div>';
    }
    $said = $r['header'] !== '' ? $r['header'] . ($r['place'] !== '' ? ', ' . $r['place'] : '') : $r['matched_name'];
    echo '<div class="kop-wb-muted">' . esc_html($kinds[$r['kind']] ?? $r['kind']) . ($said !== '' ? ': &ldquo;' . esc_html($said) . '&rdquo;' : '') . '</div>';
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
    if ($r['status'] === 'filed' && $r['attachment_id']) {
        echo '<a href="' . esc_url(wp_get_attachment_url((int) $r['attachment_id'])) . '" target="_blank" rel="noopener">Filed copy</a>';
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
    } elseif ($r['status'] === 'filed') {
        echo '<span class="kop-wb-muted">Filed' . ($r['reviewed_by'] ? ' by ' . esc_html($r['reviewed_by']) : '') . '</span><br>'
            . '<button type="button" class="button button-small" data-act="undo">Undo</button>';
    } else {
        echo '<button type="button" class="button button-small" data-act="reopen">Put back</button>';
    }
    echo '<div class="kop-wb-result"></div></td>';
    echo '</tr>';
}

function kop_wb_render_assets() {
    $nonce = wp_create_nonce('kop_woodbury');
    ?>
    <style>
        .kop-wb-bar { position: sticky; top: 32px; z-index: 5; background: #f0f0f1; padding: 8px 0; }
        .kop-wb-table td { vertical-align: top; }
        .kop-wb-choice { display: block; margin: 2px 0; }
        .kop-wb-other { margin-top: 6px; color: #555; }
        .kop-wb-muted { color: #666; font-size: 12px; margin-top: 4px; }
        .kop-wb-note { color: #8a4b00; font-size: 12px; margin-top: 4px; }
        .kop-wb-quote { margin: 6px 0; padding-left: 8px; border-left: 3px solid #33A7B5; color: #333; }
        .kop-wb-actions .button { margin-bottom: 4px; }
        .kop-wb-result { font-size: 12px; margin-top: 4px; }
        .kop-wb-result.ok { color: #1a7f37; }
        .kop-wb-result.err { color: #d63638; }
        tr.kop-wb-done { opacity: .55; }
    </style>
    <script>
    (function () {
        var ajax = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;
        var nonce = <?php echo wp_json_encode($nonce); ?>;
        var progress = document.querySelector('.kop-wb-progress');
        var labels = { file: 'Filed', skip: 'Skipped', undo: 'Undone', reopen: 'Back in the queue' };

        function item(tr) {
            var key = tr.getAttribute('data-key');
            var typed = tr.querySelector('.kop-wb-fid');
            var picked = tr.querySelector('input[type=radio]:checked');
            var fid = typed && typed.value ? typed.value : (picked ? picked.value : '');
            return { key: key, fid: fid };
        }

        function send(act, rows) {
            var body = new FormData();
            body.append('action', 'kop_wb_act');
            body.append('nonce', nonce);
            body.append('act', act);
            rows.forEach(function (tr, i) {
                var it = item(tr);
                body.append('items[' + i + '][key]', it.key);
                body.append('items[' + i + '][fid]', it.fid);
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
                        if (res.ok) {
                            tr.classList.add('kop-wb-done');
                            tr.querySelectorAll('button').forEach(function (b) { b.disabled = true; });
                            var pick = tr.querySelector('.kop-wb-pick');
                            if (pick) { pick.checked = false; pick.disabled = true; }
                            out.className = 'kop-wb-result ok';
                            out.textContent = labels[act] + (res.url ? ': ' : '');
                            if (res.url) {
                                var a = document.createElement('a');
                                a.href = res.url; a.target = '_blank'; a.rel = 'noopener';
                                a.textContent = res.facility || 'open';
                                out.appendChild(a);
                            }
                        } else {
                            out.className = 'kop-wb-result err';
                            out.textContent = res.error || 'Failed';
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
                if (!queue.length) { running = false; progress.textContent = labels[act] + ' ' + done + ' of ' + total + '.'; return; }
                var batch = queue.splice(0, size);
                progress.textContent = 'Working... ' + done + ' of ' + total;
                return send(act, batch).then(function () { done += batch.length; return next(); })
                    .catch(function (e) { running = false; progress.textContent = 'Stopped after ' + done + ' of ' + total + ': ' + e.message; });
            }
            return next();
        }

        document.querySelectorAll('.kop-wb-table button[data-act]').forEach(function (b) {
            b.addEventListener('click', function () { run(b.getAttribute('data-act'), [b.closest('tr')]); });
        });
        document.querySelectorAll('.kop-wb-bar button[data-bulk]').forEach(function (b) {
            b.addEventListener('click', function () {
                var rows = Array.prototype.map.call(document.querySelectorAll('.kop-wb-pick:checked'), function (c) { return c.closest('tr'); });
                if (!rows.length) { progress.textContent = 'Tick some rows first.'; return; }
                run(b.getAttribute('data-bulk'), rows);
            });
        });
        var all = document.querySelector('.kop-wb-all');
        if (all) all.addEventListener('change', function () {
            document.querySelectorAll('.kop-wb-pick:not(:disabled)').forEach(function (c) { c.checked = all.checked; });
        });
        // Typing an id or picking another match ticks the row.
        document.querySelectorAll('.kop-wb-fid, .kop-wb-table input[type=radio]').forEach(function (el) {
            el.addEventListener('change', function () {
                var pick = el.closest('tr').querySelector('.kop-wb-pick');
                if (pick && !pick.disabled) pick.checked = true;
            });
        });
    })();
    </script>
    <?php
}
