<?php
/**
 * Move or remove a document from the page it shows on. The document tile's
 * pencil (inc/inline-edit.php, ref doc:<id>[:h<folder>]) opens a dialog with
 * a "Where it is filed" section underneath the title fields.
 *
 * A document shows on a facility page in one of two ways:
 *   - it is filed (FileBird's fbv_attachment_folder, one folder per file) in
 *     the page's folder group, or in a subfolder of it
 *   - it is tagged (kop_media_folder_tags) into one of those folders
 * The page's folder group is the shortcode's folder plus every folder of the
 * same name and every folder linked to it (kop_get_equivalent_folder_ids()),
 * with all their subfolders. :h<folder> on the ref is that shortcode folder,
 * set while kop_filebird_folder_shortcode() renders (merge=name).
 *
 * The dialog lists every filing and tag the document has, ticks the ones that
 * put it on this page, and offers:
 *   move    file it under another facility (its page's folder, found or made
 *           the way Woodbury filing does: kop_wb_facility_folder()), or under
 *           any folder picked by name (folder lookup). A filing stays a filing
 *           and a tag stays a tag. Moving to a facility keeps a subfolder such
 *           as "Woodbury Reports Mentions" (found or made under the target).
 *   remove  unfile / untag it here; it stays in the media library
 *   delete  delete the document for good (files another record still uses
 *           are kept on disk)
 * Byte-identical copies on the same page (the page shows one of them) can be
 * taken along, or they would surface in its place.
 *
 * Move and remove can be undone: the response carries an undo ref
 * (docundo:<token>, the rows taken out and put in, kept 30 days) that
 * js/inline-edit.js offers after the page reloads.
 *
 * Search for the target: GET kop/v1/doc-place?q= -> facilities and folders.
 */

if (!defined('ABSPATH')) {
    exit;
}

/** The folder the merged document shortcode is rendering, for tile refs. */
function kop_dp_set_home($folder_id) {
    $GLOBALS['kop_dp_home'] = (int) $folder_id;
}

function kop_dp_home() {
    return (int) ($GLOBALS['kop_dp_home'] ?? 0);
}

function kop_dp_tag_table() {
    global $wpdb;
    static $table = null;
    if ($table === null) {
        $t = $wpdb->prefix . 'kop_media_folder_tags';
        $table = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $t)) === $t ? $t : '';
    }
    return $table;
}

/** id => [parent, name] for every FileBird folder. $reset after making one. */
function kop_dp_folders($reset = false) {
    global $wpdb;
    static $map = null;
    if ($map === null || $reset) {
        $map = array();
        foreach ((array) $wpdb->get_results("SELECT id, parent, name FROM {$wpdb->prefix}fbv WHERE type = 0") as $r) {
            $map[(int) $r->id] = array((int) $r->parent, (string) $r->name);
        }
    }
    return $map;
}

/** "Utah > Closed Programs > Island View". */
function kop_dp_folder_path($folder_id) {
    $map = kop_dp_folders();
    $names = array();
    $id = (int) $folder_id;
    for ($i = 0; $id > 0 && isset($map[$id]) && $i < 20; $i++) {
        array_unshift($names, $map[$id][1]);
        $id = $map[$id][0];
    }
    return $names ? implode(' > ', $names) : 'folder #' . (int) $folder_id;
}

/**
 * The page's folders: root ids (the folder group) and every folder in it or
 * beneath it.
 */
function kop_dp_page_folders($home) {
    $home = (int) $home;
    if ($home <= 0) {
        return array('roots' => array(), 'all' => array());
    }
    $roots = function_exists('kop_get_equivalent_folder_ids') ? kop_get_equivalent_folder_ids($home) : array($home);
    $all = function_exists('kop_get_descendant_ids_for_roots') ? kop_get_descendant_ids_for_roots($roots) : $roots;
    return array('roots' => array_map('intval', $roots), 'all' => array_map('intval', $all));
}

/** The names of the folders from a root down to $folder_id, root excluded. [] when it is a root or not under one. */
function kop_dp_relative_names($folder_id, array $roots) {
    $map = kop_dp_folders();
    $names = array();
    $id = (int) $folder_id;
    for ($i = 0; $id > 0 && isset($map[$id]) && $i < 20; $i++) {
        if (in_array($id, $roots, true)) {
            return $names;
        }
        array_unshift($names, $map[$id][1]);
        $id = $map[$id][0];
    }
    return array();
}

/** Every filing and tag of a document: [{kind: 'r'|'t', folder}]. */
function kop_dp_memberships($att_id) {
    global $wpdb;
    $out = array();
    foreach ((array) $wpdb->get_col($wpdb->prepare(
        "SELECT folder_id FROM {$wpdb->prefix}fbv_attachment_folder WHERE attachment_id = %d", $att_id
    )) as $f) {
        $out[] = array('kind' => 'r', 'folder' => (int) $f);
    }
    if ($tags = kop_dp_tag_table()) {
        foreach ((array) $wpdb->get_col($wpdb->prepare("SELECT folder_id FROM {$tags} WHERE attachment_id = %d", $att_id)) as $f) {
            $out[] = array('kind' => 't', 'folder' => (int) $f);
        }
    }
    return $out;
}

/** Other copies of the same file (kop_get_attachment_signatures) filed or tagged on this page. */
function kop_dp_copies_here($att_id, array $folders) {
    global $wpdb;
    if (!$folders || !function_exists('kop_get_attachment_signatures')) {
        return array();
    }
    $in = implode(',', array_map('intval', $folders));
    $ids = array_map('intval', (array) $wpdb->get_col(
        "SELECT attachment_id FROM {$wpdb->prefix}fbv_attachment_folder WHERE folder_id IN ($in)"
    ));
    if ($tags = kop_dp_tag_table()) {
        $ids = array_merge($ids, array_map('intval', (array) $wpdb->get_col("SELECT attachment_id FROM {$tags} WHERE folder_id IN ($in)")));
    }
    $ids = array_values(array_unique(array_merge(array((int) $att_id), $ids)));
    $sigs = kop_get_attachment_signatures($ids);
    $mine = $sigs[(int) $att_id] ?? '';
    if ($mine === '' || strpos($mine, 'id:') === 0) {
        return array();
    }
    $out = array();
    foreach ($sigs as $id => $sig) {
        if ($id !== (int) $att_id && $sig === $mine && get_post_type($id) === 'attachment') {
            $out[] = (int) $id;
        }
    }
    return $out;
}

/* ---- Dialog fields ---------------------------------------------------------- */

/** Fields appended to the doc: dialog. */
function kop_dp_fields($att_id, $home) {
    $page = kop_dp_page_folders($home);
    $rows = kop_dp_memberships($att_id);
    $options = array();
    $ticked = array();
    foreach ($rows as $r) {
        $here = in_array($r['folder'], $page['all'], true);
        $value = $r['kind'] . ':' . $r['folder'];
        $options[] = array(
            'value' => $value,
            'label' => ($r['kind'] === 'r' ? 'Filed in ' : 'Also listed in ') . kop_dp_folder_path($r['folder'])
                . ($here ? ' (puts it on this page)' : ''),
        );
        if ($here || !$page['all']) {
            $ticked[] = $value;
        }
    }
    $section = 'Wrong page? Move or remove it';
    $fields = array();
    if (!$options) {
        $fields[] = kop_ie_field('dp_none', 'Where it is filed', 'note', array('Not filed in any folder.'), array('section' => $section));
    } else {
        $fields[] = kop_ie_field('dp_rows', 'Where it is filed (ticked ones change)', 'checks', $ticked, array(
            'section' => $section,
            'options' => $options,
            'wide'    => true,
            'help'    => '"Filed in" is the document\'s one folder; "Also listed in" shows it in another folder as well.',
        ));
    }
    $fields[] = kop_ie_field('dp_action', 'What to do', 'select', '', array(
        'section' => $section,
        'options' => array(
            array('value' => '', 'label' => 'Leave it where it is'),
            array('value' => 'move', 'label' => 'Move it to another facility or folder'),
            array('value' => 'remove', 'label' => 'Take it off this page (it stays in the media library)'),
            array('value' => 'delete', 'label' => 'Delete the document for good'),
        ),
        'confirm' => array('delete' => 'Delete this document for good? This cannot be undone.'),
    ));
    $fields[] = kop_ie_field('dp_target', 'Move to', 'place', '', array(
        'section' => $section,
        'show_if' => array('dp_action' => 'move'),
        'help'    => 'Type a facility name (past names work) or a folder name.',
    ));
    $fields[] = kop_ie_field('dp_keep_sub', 'Keep its subfolder (e.g. Woodbury Reports Mentions) when moving to a facility', 'bool', true, array(
        'section' => $section,
        'show_if' => array('dp_action' => 'move'),
    ));
    $copies = kop_dp_copies_here($att_id, $page['all']);
    if ($copies) {
        $fields[] = kop_ie_field('dp_copies', 'Also do it to the ' . count($copies) . ' identical ' . (count($copies) === 1 ? 'copy' : 'copies')
            . ' on this page (otherwise ' . (count($copies) === 1 ? 'it shows' : 'one shows') . ' in its place)', 'bool', true, array(
            'section' => $section,
            'show_if' => array('dp_action' => array('move', 'remove', 'delete')),
        ));
    }
    return $fields;
}

/* ---- Applying --------------------------------------------------------------- */

/** A folder named $name under $parent, the fullest copy; made when there is none. */
function kop_dp_child_folder($name, $parent) {
    if (function_exists('kop_wb_find_folder')) {
        $id = kop_wb_find_folder($name, $parent);
        if (!$id) {
            $id = kop_wb_create_folder($name, $parent);
            kop_dp_folders(true);
        }
        return $id;
    }
    throw new RuntimeException('Folder helpers are not loaded.');
}

/** "f:12" -> the facility's folder, "d:34" -> that folder. [folder id, label, is facility]. */
function kop_dp_target($value) {
    global $wpdb;
    if (!preg_match('/^([fd]):(\d+)$/', (string) $value, $m)) {
        throw new RuntimeException('Pick where to move it from the list under "Move to".');
    }
    $id = (int) $m[2];
    if ($m[1] === 'd') {
        if (!isset(kop_dp_folders()[$id])) {
            throw new RuntimeException('That folder no longer exists.');
        }
        return array($id, kop_dp_folder_path($id), false);
    }
    if (!function_exists('kop_wb_facility_folder')) {
        throw new RuntimeException('Facility folders cannot be found here.');
    }
    $name = (string) $wpdb->get_var($wpdb->prepare('SELECT name FROM facilities_v2 WHERE id = %d', $id));
    if ($name === '') {
        throw new RuntimeException('Facility #' . $id . ' not found.');
    }
    $folder = kop_wb_facility_folder($id);
    return array((int) $folder, $name !== '' ? $name : 'facility #' . $id, true);
}

/** Apply the placement part of a doc: save. '' when nothing was asked. */
function kop_dp_apply($att_id, $home, array $v) {
    global $wpdb;
    $action = (string) ($v['dp_action'] ?? '');
    if ($action === '') {
        return array();
    }
    $att_id = (int) $att_id;
    $page = kop_dp_page_folders($home);
    $ids = array($att_id);
    if (!empty($v['dp_copies'])) {
        $ids = array_merge($ids, kop_dp_copies_here($att_id, $page['all']));
    }
    $title = get_the_title($att_id);

    if ($action === 'delete') {
        $done = 0;
        foreach ($ids as $id) {
            if (kop_dp_delete($id)) {
                $done++;
            }
        }
        if (!$done) {
            throw new RuntimeException('The document could not be deleted.');
        }
        kop_dp_after();
        return array('message' => 'Deleted "' . $title . '"' . ($done > 1 ? ' and ' . ($done - 1) . ' identical ' . ($done === 2 ? 'copy' : 'copies') : '') . '.');
    }

    // Which folders to change. For the shown document, the ticked rows; a
    // copy changes wherever it sits on this page.
    $picked = array();
    foreach ((array) ($v['dp_rows'] ?? array()) as $val) {
        if (preg_match('/^([rt]):(\d+)$/', (string) $val, $m)) {
            $picked[$m[1] . ':' . $m[2]] = true;
        }
    }
    if (!$picked) {
        throw new RuntimeException('Tick at least one of the folders it is filed in.');
    }

    $target = null;
    if ($action === 'move') {
        $target = kop_dp_target($v['dp_target'] ?? '');
    } elseif ($action !== 'remove') {
        throw new RuntimeException('Unknown action.');
    }

    $rel = $wpdb->prefix . 'fbv_attachment_folder';
    $tags = kop_dp_tag_table();
    $removed = array();
    $added = array();
    foreach ($ids as $id) {
        foreach (kop_dp_memberships($id) as $r) {
            $key = $r['kind'] . ':' . $r['folder'];
            $take = $id === $att_id ? isset($picked[$key]) : in_array($r['folder'], $page['all'], true);
            if (!$take) {
                continue;
            }
            if ($r['kind'] === 't' && !$tags) {
                continue;
            }
            $table = $r['kind'] === 'r' ? $rel : $tags;
            $wpdb->delete($table, array('attachment_id' => $id, 'folder_id' => $r['folder']), array('%d', '%d'));
            $removed[] = array($r['kind'], $id, $r['folder']);
            if (!$target) {
                continue;
            }
            $dest = $target[0];
            if ($target[2] && !empty($v['dp_keep_sub'])) {
                foreach (kop_dp_relative_names($r['folder'], $page['roots']) as $name) {
                    $dest = kop_dp_child_folder($name, $dest);
                }
            }
            if ($r['kind'] === 'r') {
                // FileBird holds one folder per file.
                $wpdb->delete($rel, array('attachment_id' => $id, 'folder_id' => $dest), array('%d', '%d'));
                $wpdb->insert($rel, array('folder_id' => $dest, 'attachment_id' => $id), array('%d', '%d'));
                $added[] = array('r', $id, $dest);
                if ($tags && $wpdb->delete($tags, array('attachment_id' => $id, 'folder_id' => $dest), array('%d', '%d'))) {
                    $removed[] = array('t', $id, $dest);
                }
            } else {
                $filed = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$rel} WHERE attachment_id = %d AND folder_id = %d", $id, $dest));
                $tagged = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$tags} WHERE attachment_id = %d AND folder_id = %d", $id, $dest));
                if (!$filed && !$tagged) {
                    $wpdb->insert($tags, array('folder_id' => $dest, 'attachment_id' => $id), array('%d', '%d'));
                    $added[] = array('t', $id, $dest);
                }
            }
        }
    }
    if (!$removed) {
        throw new RuntimeException('Nothing changed: it is no longer filed in the ticked folders. Reload the page.');
    }

    $token = wp_generate_password(16, false);
    set_transient('kop_dp_undo_' . $token, array('removed' => $removed, 'added' => $added, 'title' => $title, 'user' => get_current_user_id()), 30 * DAY_IN_SECONDS);
    kop_dp_after();

    $copies = count($ids) > 1 ? ' (and ' . (count($ids) - 1) . ' identical ' . (count($ids) === 2 ? 'copy' : 'copies') . ')' : '';
    if ($target) {
        $msg = 'Moved "' . $title . '"' . $copies . ' to ' . $target[1] . '.';
        $url = '';
        if (preg_match('/^f:(\d+)$/', (string) $v['dp_target'], $m) && function_exists('kop_facility_page_url')) {
            $url = (string) kop_facility_page_url((int) $m[1]);
        }
        return array('message' => $msg, 'undo' => 'docundo:' . $token, 'link' => $url);
    }
    $left = kop_dp_memberships($att_id);
    return array(
        'message' => 'Took "' . $title . '"' . $copies . ' off this page.' . ($left ? '' : ' It is now unfiled in the media library.'),
        'undo'    => 'docundo:' . $token,
    );
}

/** Caches that hold which folder a page shows, and the page cache. */
function kop_dp_after() {
    delete_transient('kop_hidden_preview_ids');
    if (function_exists('kop_facility_pages_flush_index')) {
        kop_facility_pages_flush_index();
    }
    do_action('litespeed_purge_all');
}

/**
 * Delete an attachment for good. A file another attachment also points at
 * (a PDF's preview JPG registered as its own record, a restored duplicate
 * sharing the path) stays on disk.
 */
function kop_dp_delete($att_id) {
    global $wpdb;
    $att_id = (int) $att_id;
    if ($att_id <= 0 || get_post_type($att_id) !== 'attachment' || !current_user_can('delete_post', $att_id)) {
        return false;
    }
    $keep = kop_dp_shared_files($att_id);
    $filter = function ($path) use ($keep) {
        return isset($keep[wp_basename((string) $path)]) ? '' : $path;
    };
    add_filter('wp_delete_file', $filter, 1);
    $ok = wp_delete_attachment($att_id, true);
    remove_filter('wp_delete_file', $filter, 1);
    if (!$ok) {
        return false;
    }
    $wpdb->delete($wpdb->prefix . 'fbv_attachment_folder', array('attachment_id' => $att_id), array('%d'));
    if ($tags = kop_dp_tag_table()) {
        $wpdb->delete($tags, array('attachment_id' => $att_id), array('%d'));
    }
    return true;
}

/** basename => true for each of the attachment's files that another attachment also points at. */
function kop_dp_shared_files($att_id) {
    global $wpdb;
    $file = (string) get_post_meta($att_id, '_wp_attached_file', true);
    $meta = wp_get_attachment_metadata($att_id);
    $dir = $file !== '' ? ltrim(dirname($file), './') : '';
    $paths = $file !== '' ? array($file) : array();
    if (is_array($meta) && !empty($meta['sizes'])) {
        foreach ($meta['sizes'] as $s) {
            if (!empty($s['file'])) {
                $paths[] = ($dir !== '' ? $dir . '/' : '') . $s['file'];
            }
        }
    }
    $keep = array();
    foreach (array_unique($paths) as $p) {
        $shared = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->postmeta}
             WHERE post_id <> %d AND (
                   (meta_key = '_wp_attached_file' AND meta_value = %s)
                OR (meta_key = '_wp_attachment_metadata' AND meta_value LIKE %s))",
            $att_id, $p, '%' . $wpdb->esc_like('"' . basename($p) . '"') . '%'
        ));
        if ($shared) {
            $keep[wp_basename($p)] = true;
        }
    }
    return $keep;
}

/* ---- Undo ---------------------------------------------------------------------- */

function kop_ie_docundo_load(array $p) {
    $u = get_transient('kop_dp_undo_' . preg_replace('/[^A-Za-z0-9]/', '', (string) ($p[0] ?? '')));
    if (!is_array($u)) {
        throw new RuntimeException('That change can no longer be undone.');
    }
    return array('title' => 'Undo the move of "' . $u['title'] . '"', 'fields' => array());
}

function kop_ie_docundo_save(array $p, array $v) {
    global $wpdb;
    $key = 'kop_dp_undo_' . preg_replace('/[^A-Za-z0-9]/', '', (string) ($p[0] ?? ''));
    $u = get_transient($key);
    if (!is_array($u)) {
        throw new RuntimeException('That change can no longer be undone.');
    }
    $rel = $wpdb->prefix . 'fbv_attachment_folder';
    $tags = kop_dp_tag_table();
    foreach (array_reverse((array) $u['added']) as $a) {
        $table = $a[0] === 'r' ? $rel : $tags;
        if ($table) {
            $wpdb->delete($table, array('attachment_id' => (int) $a[1], 'folder_id' => (int) $a[2]), array('%d', '%d'));
        }
    }
    foreach ((array) $u['removed'] as $r) {
        $table = $r[0] === 'r' ? $rel : $tags;
        if (!$table) {
            continue;
        }
        if ($r[0] === 'r') {
            $wpdb->delete($rel, array('attachment_id' => (int) $r[1]), array('%d'));
        }
        $exists = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE attachment_id = %d AND folder_id = %d", (int) $r[1], (int) $r[2]));
        if (!$exists) {
            $wpdb->insert($table, array('folder_id' => (int) $r[2], 'attachment_id' => (int) $r[1]), array('%d', '%d'));
        }
    }
    delete_transient($key);
    kop_dp_after();
    return array('message' => 'Undone: "' . $u['title'] . '" is back where it was.');
}

/* ---- Search: facilities and folders ------------------------------------------- */

/** Folders whose name contains $q, the fullest first: [{id, path, files}]. */
function kop_dp_folder_search($q, $limit = 10) {
    global $wpdb;
    $q = trim((string) $q);
    if (mb_strlen($q) < 2) {
        return array();
    }
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT f.id, COUNT(r.attachment_id) AS files
         FROM {$wpdb->prefix}fbv f
         LEFT JOIN {$wpdb->prefix}fbv_attachment_folder r ON r.folder_id = f.id
         WHERE f.type = 0 AND f.name LIKE %s
         GROUP BY f.id
         ORDER BY (LOWER(f.name) = LOWER(%s)) DESC, files DESC, f.id ASC
         LIMIT %d",
        '%' . $wpdb->esc_like($q) . '%', $q, (int) $limit
    ));
    $out = array();
    foreach ((array) $rows as $r) {
        $out[] = array('id' => (int) $r->id, 'path' => kop_dp_folder_path((int) $r->id), 'files' => (int) $r->files);
    }
    return $out;
}

add_action('rest_api_init', function () {
    register_rest_route('kop/v1', '/doc-place', array(
        'methods'             => 'GET',
        'permission_callback' => function () {
            return current_user_can('manage_options');
        },
        'callback'            => function (WP_REST_Request $r) {
            $q = (string) $r->get_param('q');
            $out = array();
            $pdo = function_exists('kop_seed_pdo') ? kop_seed_pdo() : null;
            if ($pdo && function_exists('kop_facility_finder_search')) {
                foreach (kop_facility_finder_search($pdo, $q, 8) as $f) {
                    $place = trim(implode(', ', array_filter(array($f['city'], $f['state'] ?: $f['country']))));
                    $meta = array_filter(array(
                        $f['matched'] !== '' ? 'Was: ' . $f['matched'] : '',
                        $place,
                        $f['status'] && $f['status'] !== 'Unknown' ? $f['status'] : '',
                        '#' . $f['id'],
                    ));
                    $out[] = array('value' => 'f:' . (int) $f['id'], 'kind' => 'Facility', 'label' => (string) $f['name'], 'meta' => implode(' · ', $meta));
                }
            }
            foreach (kop_dp_folder_search($q) as $f) {
                $out[] = array('value' => 'd:' . $f['id'], 'kind' => 'Folder', 'label' => $f['path'], 'meta' => $f['files'] . ' file' . ($f['files'] === 1 ? '' : 's') . ' · #' . $f['id']);
            }
            return rest_ensure_response($out);
        },
    ));
});
