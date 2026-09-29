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

define('KOP_WOODBURY_DB_VERSION', '1');
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

    $parent = kop_wb_facility_folder($fid);
    $folder = kop_wb_find_folder(KOP_WOODBURY_SUBFOLDER, $parent) ?: kop_wb_create_folder(KOP_WOODBURY_SUBFOLDER, $parent);

    $title = kop_wb_title($r, $fac['name']);
    $issue_url = $r['issue_id'] ? (string) wp_get_attachment_url((int) $r['issue_id']) : '';
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
    wp_update_attachment_metadata($att, wp_generate_attachment_metadata($att, $upload['file']));
    update_post_meta($att, '_kop_import_md5', md5_file($path));
    update_post_meta($att, '_kop_woodbury_key', $r['ckey']);
    update_post_meta($att, '_kop_woodbury_issue', (int) $r['issue_id']);
    update_post_meta($att, '_kop_woodbury_pages', $r['pages']);
    if ($issue_url !== '') {
        update_post_meta($att, '_kop_source_url', esc_url_raw($issue_url));
    }
    $wpdb->insert($wpdb->prefix . 'fbv_attachment_folder', array('folder_id' => $folder, 'attachment_id' => $att), array('%d', '%d'));

    $wpdb->update(kop_wb_table(), array(
        'status'        => 'filed',
        'facility_id'   => $fid,
        'facility_name' => $fac['name'],
        'attachment_id' => (int) $att,
        'folder_id'     => $folder,
        'reviewed_by'   => $reviewer,
        'reviewed_at'   => current_time('mysql', true),
    ), array('ckey' => $r['ckey']));
    delete_transient('kop_hidden_preview_ids');
    return array('attachment_id' => (int) $att, 'folder_id' => $folder, 'url' => (string) wp_get_attachment_url($att), 'facility' => $fac['name']);
}

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
    if (!is_readable(kop_wb_pending_dir() . '/candidates.json')) {
        echo '<div class="notice notice-warning"><p>No scan uploaded yet. Run <code>python scripts/woodbury-scan.py</code> and copy its '
            . '<code>pending</code> folder to <code>' . esc_html(kop_wb_pending_dir()) . '</code>.</p></div>';
    }
    echo '<p>Pages of the Woodbury Reports newsletter that write about a program, found by scanning every issue in the media library. '
        . '<strong>File it</strong> puts those pages, as their own small PDF, in the program\'s document library (a "Woodbury Reports Mentions" '
        . 'folder on its facility page), linked to the full issue. Nothing is filed until you click. <strong>Undo</strong> takes it back out.</p>'
        . '<p style="color:#555">Tick rows and use the buttons at the top to do many at once. Check the program when a row says '
        . '<em>close name</em> or offers other matches: click the right one before filing. <strong>View pages</strong> opens the extract itself.</p>';

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
    }
    if ($pending) {
        echo '<label class="kop-wb-choice kop-wb-other">Other facility id <input type="number" class="kop-wb-fid" style="width:90px"'
            . ($r['facility_id'] ? '' : ' placeholder="id"') . '></label>';
    }
    $said = $r['header'] !== '' ? $r['header'] . ($r['place'] !== '' ? ', ' . $r['place'] : '') : $r['matched_name'];
    echo '<div class="kop-wb-muted">' . esc_html($kinds[$r['kind']] ?? $r['kind']) . ($said !== '' ? ': &ldquo;' . esc_html($said) . '&rdquo;' : '') . '</div>';
    if (trim((string) $r['note']) !== '') {
        echo '<div class="kop-wb-note">' . esc_html($r['note']) . '</div>';
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
                .then(function (r) { return r.json(); })
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

        function run(act, rows) {
            var queue = rows.slice(), done = 0, total = rows.length, size = act === 'file' ? 3 : 10;
            function next() {
                if (!queue.length) { progress.textContent = labels[act] + ' ' + done + ' of ' + total + '.'; return; }
                var batch = queue.splice(0, size);
                progress.textContent = 'Working... ' + done + ' of ' + total;
                return send(act, batch).then(function () { done += batch.length; return next(); })
                    .catch(function (e) { progress.textContent = 'Stopped after ' + done + ' of ' + total + ': ' + e.message; });
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
