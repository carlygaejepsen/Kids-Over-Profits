<?php
/**
 * Dedupe media — admin tool that clears the duplicate tiles the May–June 2026
 * media restore (Media Sync import of the uploads directory) left in every
 * document library.
 *
 * What it finds (each tab previews first; nothing changes until Apply):
 *
 *  1. previews  PDF preview images (<name>-pdf.jpg) that were imported as
 *               their own media items, so each PDF shows twice. The record is
 *               removed; the JPG stays on disk when it is the PDF's own
 *               generated preview (1,900+ of them share the exact path).
 *  2. dupes     Byte-identical attachments (same md5 per Media Deduper's
 *               mdd_hash, re-verified at apply time). One copy is kept; the
 *               others are deleted after their folder memberships are
 *               transferred to the keeper (FileBird move or theme tag) and
 *               any page / wiki / lawsuit link is rewritten to the keeper.
 *  3. junk      Plugin and template files (popularfx-templates, fonts,
 *               wpforms, Drive thumbnails...) imported as media. Records are
 *               removed, files are left on disk for the plugins that own them.
 *  4. regen     PDFs with no generated preview sizes: regenerate with
 *               Imagick/Ghostscript so the tile has a thumbnail again.
 *  5. orphans   FileBird / tag relation rows pointing at deleted attachments.
 *  6. near      Read-only: same title in the same folder but different bytes.
 *
 * Admin-only. Loads WordPress via config.php.
 */

require_once __DIR__ . '/config.php';

if (!function_exists('current_user_can') || !current_user_can('manage_options')) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Not authorized. Log in to WordPress as an administrator first.';
    exit;
}

require_once ABSPATH . 'wp-admin/includes/image.php';
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/media.php';

header('Content-Type: text/html; charset=utf-8');
@set_time_limit(600);

global $wpdb;
$T_FBV = $wpdb->prefix . 'fbv';
$T_REL = $wpdb->prefix . 'fbv_attachment_folder';
$T_TAG = $wpdb->prefix . 'kop_media_folder_tags';

$SHOW = 400;                 // rows per pass
$REGEN_BATCH = 25;           // PDFs regenerated per apply (Ghostscript is slow)
$HOLDING_FOLDERS = array(2121 /* File Later */, 30 /* Blog */);

$SECTIONS = array(
    'previews' => 'PDF preview images',
    'dupes'    => 'Byte-identical duplicates',
    'junk'     => 'Plugin / template files',
    'regen'    => 'PDFs missing previews',
    'orphans'  => 'Orphan relations',
    'near'     => 'Near-duplicates (review)',
);
$section = isset($_GET['s']) && isset($SECTIONS[$_GET['s']]) ? $_GET['s'] : 'previews';

$SIDECAR_RE = '/-(pdf|docx?)(-\d+)?(-scaled)?\.(jpe?g|png|webp)$/i';
$SIDECAR_SQL = '-(pdf|docx?)(-[0-9]+)?(-scaled)?\\.(jpe?g|png|webp)$';
$JUNK_RE = '#^(popularfx-templates|ast-block-templates-json|fonts|wpforms|wp-rollback|uag-plugin|integrate-google-drive-thumbnails|wp-file-manager-pro|dlm_uploads|anonymous-submissions|elementor|kadence|cache)/#';
$JUNK_SQL = '^(popularfx-templates|ast-block-templates-json|fonts|wpforms|wp-rollback|uag-plugin|integrate-google-drive-thumbnails|wp-file-manager-pro|dlm_uploads|anonymous-submissions|elementor|kadence|cache)/';

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function kop_dm_uploads_base() {
    static $base = null;
    if ($base === null) {
        $u = wp_upload_dir();
        $base = rtrim(wp_normalize_path($u['basedir']), '/');
    }
    return $base;
}

function kop_dm_table_exists($table) {
    global $wpdb;
    static $cache = array();
    if (!isset($cache[$table])) {
        $cache[$table] = ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table);
    }
    return $cache[$table];
}

/** id => row(name, parent, type) for every FileBird folder. */
function kop_dm_folders() {
    global $wpdb, $T_FBV;
    static $map = null;
    if ($map === null) {
        $map = array();
        if (kop_dm_table_exists($T_FBV)) {
            foreach ($wpdb->get_results("SELECT id, name, parent, type FROM {$T_FBV}") as $r) {
                $map[(int) $r->id] = $r;
            }
        }
    }
    return $map;
}

function kop_dm_folder_path($fid) {
    $map = kop_dm_folders();
    $fid = (int) $fid;
    $parts = array();
    $seen = array();
    while ($fid > 0 && isset($map[$fid]) && !isset($seen[$fid]) && count($parts) < 20) {
        $seen[$fid] = true;
        $parts[] = html_entity_decode($map[$fid]->name, ENT_QUOTES);
        $fid = (int) $map[$fid]->parent;
    }
    return $parts ? implode(' / ', array_reverse($parts)) : '';
}

/** True when $anc is a strict ancestor of $fid. */
function kop_dm_is_ancestor($anc, $fid) {
    $map = kop_dm_folders();
    $anc = (int) $anc;
    $fid = (int) $fid;
    if ($anc <= 0 || $fid <= 0) {
        return false;
    }
    $seen = array();
    while ($fid > 0 && isset($map[$fid]) && !isset($seen[$fid])) {
        $seen[$fid] = true;
        $fid = (int) $map[$fid]->parent;
        if ($fid === $anc) {
            return true;
        }
    }
    return false;
}

/** Folder (FileBird relation) and tag folders of one attachment. */
function kop_dm_membership($att_id) {
    global $wpdb, $T_REL, $T_TAG;
    $folder = kop_dm_table_exists($T_REL)
        ? (int) $wpdb->get_var($wpdb->prepare("SELECT folder_id FROM {$T_REL} WHERE attachment_id = %d LIMIT 1", $att_id))
        : 0;
    $tags = kop_dm_table_exists($T_TAG)
        ? array_map('intval', (array) $wpdb->get_col($wpdb->prepare("SELECT folder_id FROM {$T_TAG} WHERE attachment_id = %d", $att_id)))
        : array();
    return array('folder' => $folder, 'tags' => $tags);
}

function kop_dm_equivalent_folders($fid) {
    $fid = (int) $fid;
    if ($fid <= 0) {
        return array();
    }
    $ids = function_exists('kop_get_equivalent_folder_ids') ? kop_get_equivalent_folder_ids($fid) : array($fid);
    return array_map('intval', (array) $ids);
}

/**
 * Every upload-relative path each attachment owns: its file, generated sizes,
 * original image and backup sizes. Built once per request from postmeta.
 * Returns array('by_id' => id => [path => true], 'by_path' => path => [id...],
 *               'files' => id => attached file).
 */
function kop_dm_owned($forget_id = 0) {
    global $wpdb;
    static $idx = null;
    if ($idx !== null) {
        if ($forget_id > 0 && isset($idx['by_id'][$forget_id])) {
            foreach ($idx['by_id'][$forget_id] as $path => $_) {
                unset($idx['by_path'][$path][$forget_id]);
            }
            unset($idx['by_id'][$forget_id], $idx['files'][$forget_id]);
        }
        return $idx;
    }
    $by_id = array();
    $by_path = array();
    $files = array();
    $rows = $wpdb->get_results(
        "SELECT pm.post_id, pm.meta_key, pm.meta_value
         FROM {$wpdb->postmeta} pm
         INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id AND p.post_type = 'attachment'
         WHERE pm.meta_key IN ('_wp_attached_file', '_wp_attachment_metadata', '_wp_attachment_backup_sizes')"
    );
    foreach ($rows as $r) {
        if ($r->meta_key === '_wp_attached_file') {
            $files[(int) $r->post_id] = ltrim((string) $r->meta_value, '/');
        }
    }
    $add = function ($id, $path) use (&$by_id, &$by_path) {
        $path = ltrim((string) $path, '/');
        if ($path === '') {
            return;
        }
        $by_id[$id][$path] = true;
        $by_path[$path][$id] = true;
    };
    foreach ($files as $id => $f) {
        $add($id, $f);
    }
    foreach ($rows as $r) {
        if ($r->meta_key === '_wp_attached_file') {
            continue;
        }
        $id = (int) $r->post_id;
        $meta = maybe_unserialize($r->meta_value);
        if (!is_array($meta)) {
            continue;
        }
        $dir = isset($files[$id]) ? dirname($files[$id]) : '';
        $dir = ($dir === '.' || $dir === '') ? '' : $dir . '/';
        $sizes = ($r->meta_key === '_wp_attachment_metadata')
            ? (isset($meta['sizes']) && is_array($meta['sizes']) ? $meta['sizes'] : array())
            : $meta;
        foreach ($sizes as $s) {
            if (is_array($s) && !empty($s['file'])) {
                $add($id, $dir . $s['file']);
            }
        }
        if ($r->meta_key === '_wp_attachment_metadata' && !empty($meta['original_image'])) {
            $add($id, $dir . $meta['original_image']);
        }
    }
    $idx = array('by_id' => $by_id, 'by_path' => $by_path, 'files' => $files);
    return $idx;
}

function kop_dm_file_of($att_id) {
    $o = kop_dm_owned();
    return isset($o['files'][(int) $att_id]) ? $o['files'][(int) $att_id] : '';
}

function kop_dm_has_sizes($att_id) {
    $o = kop_dm_owned();
    return isset($o['by_id'][(int) $att_id]) && count($o['by_id'][(int) $att_id]) > 1;
}

/** Other attachments that own the given upload-relative path. */
function kop_dm_other_owners($path, $except_id) {
    $o = kop_dm_owned();
    $path = ltrim((string) $path, '/');
    $out = array();
    if (isset($o['by_path'][$path])) {
        foreach ($o['by_path'][$path] as $id => $_) {
            if ((int) $id !== (int) $except_id) {
                $out[] = (int) $id;
            }
        }
    }
    return $out;
}

function kop_dm_is_slug_title($title) {
    $t = trim((string) $title);
    if (preg_match('/-(pdf|jpe?g|png|gif|webp|docx?|xlsx?|pptx?|txt|csv|zip)$/i', $t)) {
        return true;
    }
    return (bool) preg_match('/^[a-z0-9_.-]+$/', $t) && strpos($t, '-') !== false;
}

/**
 * Delete one attachment record. Files are unlinked only when no other
 * attachment references the same path (a sidecar JPG that IS the PDF's own
 * preview stays on disk). $keep_files = true never unlinks anything.
 */
function kop_dm_delete_record($att_id, $keep_files = false) {
    global $wpdb, $T_REL, $T_TAG;
    $att_id = (int) $att_id;
    if ($att_id <= 0 || get_post_type($att_id) !== 'attachment') {
        return false;
    }
    $o = kop_dm_owned();
    $protected = array();
    if (isset($o['by_id'][$att_id])) {
        foreach ($o['by_id'][$att_id] as $path => $_) {
            if (kop_dm_other_owners($path, $att_id)) {
                $protected[$path] = true;
            }
        }
    }
    $base = kop_dm_uploads_base();
    $filter = function ($file) use ($protected, $keep_files, $base) {
        if ($keep_files) {
            return '';
        }
        $rel = ltrim(substr(wp_normalize_path($file), strlen($base)), '/');
        return isset($protected[$rel]) ? '' : $file;
    };
    add_filter('wp_delete_file', $filter, 1);
    $ok = wp_delete_attachment($att_id, true);
    remove_filter('wp_delete_file', $filter, 1);
    if (!$ok) {
        return false;
    }
    kop_dm_owned($att_id); // drop its paths from the ownership index
    if (kop_dm_table_exists($T_REL)) {
        $wpdb->delete($T_REL, array('attachment_id' => $att_id), array('%d'));
    }
    if (kop_dm_table_exists($T_TAG)) {
        $wpdb->delete($T_TAG, array('attachment_id' => $att_id), array('%d'));
    }
    return true;
}

// --- Reference index: which upload paths are linked from site content ------

function kop_dm_ref_sources() {
    global $wpdb;
    return array(
        array('table' => $wpdb->posts, 'pk' => 'ID', 'cols' => array('post_content'),
              'where' => "post_type NOT IN ('attachment', 'revision')", 'rewrite' => true),
        array('table' => 'wiki_submissions', 'pk' => 'id', 'cols' => array('json_data', 'generated_markdown', 'original_markdown'), 'rewrite' => true),
        array('table' => 'lawsuits', 'pk' => 'id', 'cols' => array('summary', 'document_urls', 'source_urls'), 'rewrite' => true),
        array('table' => 'legislation', 'pk' => 'id', 'cols' => array('summary', 'full_text_url', 'official_url'), 'rewrite' => true),
        array('table' => 'news_submissions', 'pk' => 'id', 'cols' => array('json_data', 'generated_output'), 'rewrite' => true),
        array('table' => 'memorial_victims', 'pk' => 'id', 'cols' => array('source_url', 'kop_url', 'notes'), 'rewrite' => true),
        // Serialized: counted as a reference, never rewritten.
        array('table' => $wpdb->options, 'pk' => 'option_name', 'cols' => array('option_value'),
              'where' => "option_name NOT LIKE '\\_transient%' AND option_name NOT LIKE '\\_site\\_transient%'", 'rewrite' => false),
    );
}

function kop_dm_build_ref_index() {
    global $wpdb;
    $refs = array();
    foreach (kop_dm_ref_sources() as $src) {
        if (!kop_dm_table_exists($src['table'])) {
            continue;
        }
        $like = array();
        foreach ($src['cols'] as $c) {
            $like[] = "`{$c}` LIKE '%wp-content/uploads%'";
        }
        $where = '(' . implode(' OR ', $like) . ')' . (empty($src['where']) ? '' : ' AND ' . $src['where']);
        $cols = '`' . implode('`, `', $src['cols']) . '`';
        $rows = $wpdb->get_results("SELECT `{$src['pk']}` AS pk, {$cols} FROM `{$src['table']}` WHERE {$where}", ARRAY_A);
        foreach ((array) $rows as $row) {
            $tag = $src['table'] . '#' . $row['pk'];
            foreach ($src['cols'] as $c) {
                if (!preg_match_all('#wp-content/uploads/((?:[^\s"\'<>)\\\\]|\\\\/)+)#', (string) $row[$c], $m)) {
                    continue;
                }
                foreach ($m[1] as $p) {
                    $p = str_replace('\\/', '/', $p);
                    $p = preg_replace('/[?#].*$/', '', $p);
                    $p = rawurldecode(rtrim($p, '.,;:'));
                    $refs[$p][$tag] = true;
                }
            }
        }
    }
    $out = array();
    foreach ($refs as $p => $tags) {
        $out[$p] = array_keys($tags);
    }
    update_option('kop_dm_ref_index', array('built' => time(), 'refs' => $out), false);
    return $out;
}

function kop_dm_ref_index($force = false) {
    static $idx = null;
    if ($force) {
        $idx = kop_dm_build_ref_index();
    }
    if ($idx === null) {
        $opt = get_option('kop_dm_ref_index');
        $idx = (is_array($opt) && isset($opt['refs'])) ? $opt['refs'] : kop_dm_build_ref_index();
    }
    return $idx;
}

function kop_dm_ref_built() {
    $opt = get_option('kop_dm_ref_index');
    return (is_array($opt) && !empty($opt['built'])) ? (int) $opt['built'] : 0;
}

/** Content sources linking this upload-relative path (or one of its size variants). */
function kop_dm_refs_for($rel_path) {
    $idx = kop_dm_ref_index();
    $rel_path = ltrim((string) $rel_path, '/');
    if ($rel_path === '') {
        return array();
    }
    $hits = array();
    if (isset($idx[$rel_path])) {
        $hits = $idx[$rel_path];
    }
    $stem = preg_replace('/\.[a-z0-9]+$/i', '', $rel_path);
    foreach ($idx as $p => $tags) {
        if ($p !== $rel_path && strpos($p, $stem . '-') === 0) {
            $hits = array_merge($hits, $tags);
        }
    }
    return array_values(array_unique($hits));
}

/** Rewrite links to $from_rel so they point at $to_rel. Returns rows changed. */
function kop_dm_rewrite_refs($from_rel, $to_rel) {
    global $wpdb;
    $from_rel = ltrim((string) $from_rel, '/');
    $to_rel = ltrim((string) $to_rel, '/');
    if ($from_rel === '' || $to_rel === '' || $from_rel === $to_rel) {
        return 0;
    }
    $pairs = array(
        array('wp-content/uploads/' . $from_rel, 'wp-content/uploads/' . $to_rel),
        array(str_replace('/', '\\/', 'wp-content/uploads/' . $from_rel), str_replace('/', '\\/', 'wp-content/uploads/' . $to_rel)),
    );
    $changed = 0;
    foreach (kop_dm_ref_sources() as $src) {
        if (empty($src['rewrite']) || !kop_dm_table_exists($src['table'])) {
            continue;
        }
        foreach ($src['cols'] as $c) {
            foreach ($pairs as $pair) {
                $changed += (int) $wpdb->query($wpdb->prepare(
                    "UPDATE `{$src['table']}` SET `{$c}` = REPLACE(`{$c}`, %s, %s) WHERE `{$c}` LIKE %s",
                    $pair[0], $pair[1], '%' . $wpdb->esc_like($pair[0]) . '%'
                ));
            }
        }
    }
    return $changed;
}

// --- Section data ------------------------------------------------------------

/** PDFs keyed by lowercase basename without extension. */
function kop_dm_pdfs_by_base() {
    global $wpdb, $T_REL;
    static $map = null;
    if ($map !== null) {
        return $map;
    }
    $map = array();
    $rel_join = kop_dm_table_exists($T_REL) ? "LEFT JOIN {$T_REL} r ON r.attachment_id = p.ID" : '';
    $rel_col = kop_dm_table_exists($T_REL) ? 'IFNULL(r.folder_id, 0)' : '0';
    $rows = $wpdb->get_results(
        "SELECT p.ID, p.post_title, pm.meta_value AS file, {$rel_col} AS folder_id
         FROM {$wpdb->posts} p
         INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_wp_attached_file'
         {$rel_join}
         WHERE p.post_type = 'attachment' AND p.post_mime_type = 'application/pdf'"
    );
    foreach ($rows as $r) {
        $base = strtolower(preg_replace('/\.pdf$/i', '', basename((string) $r->file)));
        $map[$base][] = array('id' => (int) $r->ID, 'title' => $r->post_title, 'file' => $r->file, 'folder' => (int) $r->folder_id);
    }
    return $map;
}

function kop_dm_sidecar_rows() {
    global $wpdb, $T_REL, $SIDECAR_SQL;
    $rel_join = kop_dm_table_exists($T_REL) ? "LEFT JOIN {$T_REL} r ON r.attachment_id = p.ID" : '';
    $rel_col = kop_dm_table_exists($T_REL) ? 'IFNULL(r.folder_id, 0)' : '0';
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT p.ID, p.post_title, p.post_mime_type, pm.meta_value AS file, {$rel_col} AS folder_id
         FROM {$wpdb->posts} p
         INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_wp_attached_file'
         {$rel_join}
         WHERE p.post_type = 'attachment' AND p.post_mime_type <> 'application/pdf'
           AND pm.meta_value REGEXP %s
         ORDER BY p.ID",
        $SIDECAR_SQL
    ));
    $pdfs = kop_dm_pdfs_by_base();
    $out = array();
    foreach ($rows as $r) {
        $base = strtolower(preg_replace('/-(pdf|docx?)(-\d+)?(-scaled)?\.(jpe?g|png|webp)$/i', '', basename((string) $r->file)));
        $cands = isset($pdfs[$base]) ? $pdfs[$base] : array();
        if (!$cands && preg_match('/^(.*)-\d+$/', $base, $m) && isset($pdfs[$m[1]])) {
            $cands = $pdfs[$m[1]];
        }
        $pdf = null;
        foreach ($cands as $c) {
            if ($c['folder'] === (int) $r->folder_id) {
                $pdf = $c;
                break;
            }
        }
        if (!$pdf && $cands) {
            $pdf = $cands[0];
        }
        if ($pdf) {
            $owners = kop_dm_other_owners((string) $r->file, (int) $r->ID);
            if ($owners) {
                $status = 'pdf-owns-preview';
            } else {
                $status = kop_dm_has_sizes($pdf['id']) ? 'pdf-has-preview' : 'pdf-no-preview';
            }
        } else {
            $status = 'no-pdf';
        }
        $out[] = array(
            'id' => (int) $r->ID, 'title' => $r->post_title, 'file' => $r->file, 'mime' => $r->post_mime_type,
            'folder' => (int) $r->folder_id, 'pdf' => $pdf, 'status' => $status,
        );
    }
    return $out;
}

function kop_dm_junk_rows() {
    global $wpdb, $T_REL, $JUNK_SQL;
    $rel_join = kop_dm_table_exists($T_REL) ? "LEFT JOIN {$T_REL} r ON r.attachment_id = p.ID" : '';
    $rel_col = kop_dm_table_exists($T_REL) ? 'IFNULL(r.folder_id, 0)' : '0';
    return $wpdb->get_results($wpdb->prepare(
        "SELECT p.ID, p.post_title, p.post_mime_type, pm.meta_value AS file, {$rel_col} AS folder_id
         FROM {$wpdb->posts} p
         INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_wp_attached_file'
         {$rel_join}
         WHERE p.post_type = 'attachment' AND pm.meta_value REGEXP %s
         ORDER BY pm.meta_value",
        $JUNK_SQL
    ));
}

/** Attachment members eligible for byte-identical grouping (no sidecars, no junk). */
function kop_dm_hash_members() {
    global $wpdb, $T_REL, $SIDECAR_SQL, $JUNK_SQL;
    $rel_join = kop_dm_table_exists($T_REL) ? "LEFT JOIN {$T_REL} r ON r.attachment_id = p.ID" : '';
    $rel_col = kop_dm_table_exists($T_REL) ? 'IFNULL(r.folder_id, 0)' : '0';
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT p.ID, p.post_title, p.post_mime_type, p.post_date, f.meta_value AS file, h.meta_value AS hash,
                CAST(IFNULL(s.meta_value, 0) AS UNSIGNED) AS size, {$rel_col} AS folder_id
         FROM {$wpdb->posts} p
         INNER JOIN {$wpdb->postmeta} f ON f.post_id = p.ID AND f.meta_key = '_wp_attached_file'
         INNER JOIN {$wpdb->postmeta} h ON h.post_id = p.ID AND h.meta_key = 'mdd_hash'
         LEFT JOIN {$wpdb->postmeta} s ON s.post_id = p.ID AND s.meta_key = 'mdd_size'
         {$rel_join}
         WHERE p.post_type = 'attachment'
           AND h.meta_value REGEXP '^[0-9a-f]{32}$'
           AND f.meta_value NOT REGEXP %s
           AND f.meta_value NOT REGEXP %s
         ORDER BY p.ID",
        $SIDECAR_SQL, $JUNK_SQL
    ));
    $members = array();
    foreach ($rows as $r) {
        if ((int) $r->size <= 0) {
            continue;
        }
        $members[$r->hash][] = array(
            'id' => (int) $r->ID, 'title' => $r->post_title, 'mime' => $r->post_mime_type, 'date' => $r->post_date,
            'file' => ltrim((string) $r->file, '/'), 'size' => (int) $r->size, 'folder' => (int) $r->folder_id,
        );
    }
    return $members;
}

function kop_dm_score($m) {
    global $HOLDING_FOLDERS;
    $score = 0;
    $score += 1000 * count(kop_dm_refs_for($m['file']));
    if ($m['folder'] > 0 && !in_array($m['folder'], $HOLDING_FOLDERS, true)) {
        $score += 100;
    }
    if (kop_dm_has_sizes($m['id'])) {
        $score += 10;
    }
    if (!kop_dm_is_slug_title($m['title'])) {
        $score += 5;
    }
    if (preg_match('#^\d{4}/\d{2}/#', $m['file'])) {
        $score += 1;
    }
    return $score;
}

/** Choose the keeper and describe what happens to each other copy. */
function kop_dm_plan_group($members) {
    $scored = array();
    foreach ($members as $m) {
        $m['refs'] = kop_dm_refs_for($m['file']);
        $m['score'] = kop_dm_score($m);
        $scored[] = $m;
    }
    usort($scored, function ($a, $b) {
        if ($a['score'] !== $b['score']) {
            return $b['score'] - $a['score'];
        }
        return $a['id'] - $b['id'];
    });
    $keeper = array_shift($scored);
    $keeper_equiv = kop_dm_equivalent_folders($keeper['folder']);
    $losers = array();
    foreach ($scored as $m) {
        $plan = 'delete';
        $folder_action = 'none';
        if ($m['folder'] > 0 && $m['folder'] !== $keeper['folder']) {
            if (in_array($m['folder'], $keeper_equiv, true)) {
                $plan = 'delete (folder is the same facility)';
            } elseif (kop_dm_is_ancestor($keeper['folder'], $m['folder'])) {
                $plan = 'delete; move keeper into ' . kop_dm_folder_path($m['folder']);
                $folder_action = 'move';
            } elseif (kop_dm_is_ancestor($m['folder'], $keeper['folder'])) {
                $plan = 'delete (keeper already sits below this folder)';
            } else {
                $plan = 'delete; tag keeper into ' . kop_dm_folder_path($m['folder']);
                $folder_action = 'tag';
            }
        }
        if ($m['refs']) {
            $plan .= '; rewrite ' . count($m['refs']) . ' link(s)';
        }
        $m['plan'] = $plan;
        $m['folder_action'] = $folder_action;
        $losers[] = $m;
    }
    return array('keeper' => $keeper, 'losers' => $losers);
}

function kop_dm_pdfs_missing_previews() {
    global $wpdb;
    $ids = $wpdb->get_col(
        "SELECT p.ID FROM {$wpdb->posts} p
         WHERE p.post_type = 'attachment' AND p.post_mime_type = 'application/pdf' ORDER BY p.ID"
    );
    $out = array();
    foreach ($ids as $id) {
        if (!kop_dm_has_sizes($id)) {
            $file = kop_dm_file_of($id);
            $out[] = array('id' => (int) $id, 'title' => get_the_title($id), 'file' => $file,
                           'exists' => $file !== '' && file_exists(kop_dm_uploads_base() . '/' . $file));
        }
    }
    return $out;
}

function kop_dm_orphan_counts() {
    global $wpdb, $T_REL, $T_TAG;
    $rel = kop_dm_table_exists($T_REL)
        ? (int) $wpdb->get_var("SELECT COUNT(*) FROM {$T_REL} r LEFT JOIN {$wpdb->posts} p ON p.ID = r.attachment_id WHERE p.ID IS NULL")
        : 0;
    $tag = kop_dm_table_exists($T_TAG)
        ? (int) $wpdb->get_var("SELECT COUNT(*) FROM {$T_TAG} r LEFT JOIN {$wpdb->posts} p ON p.ID = r.attachment_id WHERE p.ID IS NULL")
        : 0;
    return array('rel' => $rel, 'tag' => $tag);
}

function kop_dm_norm_title($t) {
    $t = mb_strtolower(html_entity_decode((string) $t, ENT_QUOTES));
    $t = preg_replace('/[^a-z0-9]+/u', ' ', $t);
    $t = trim($t);
    $t = preg_replace('/\s(pdf|jpe?g|png|webp)$/', '', $t);
    $t = preg_replace('/\s\d$/', '', $t);
    return trim($t);
}

function kop_dm_ticked_ids($key) {
    if (!isset($_POST[$key]) || !is_array($_POST[$key])) {
        return array();
    }
    $out = array();
    foreach ($_POST[$key] as $id => $req) {
        if (!empty($req['go'])) {
            $out[] = $id;
        }
    }
    return $out;
}

// ---------------------------------------------------------------------------
// Apply
// ---------------------------------------------------------------------------
$apply_log = array();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && check_admin_referer('kop_dm_apply')) {
    $action = isset($_POST['do']) ? (string) $_POST['do'] : '';

    if ($action === 'rebuild_refs') {
        $idx = kop_dm_ref_index(true);
        $apply_log[] = 'Reference index rebuilt: ' . count($idx) . ' linked upload path(s).';
    }

    if ($action === 'previews') {
        $done = 0;
        $skipped = 0;
        foreach (array_map('intval', kop_dm_ticked_ids('row')) as $id) {
            $file = kop_dm_file_of($id);
            if ($file === '' || !preg_match($SIDECAR_RE, $file) || get_post_mime_type($id) === 'application/pdf') {
                $skipped++;
                continue;
            }
            if (kop_dm_delete_record($id, false)) {
                $done++;
            } else {
                $skipped++;
            }
        }
        $apply_log[] = "Removed {$done} preview-image record(s)." . ($skipped ? " Skipped {$skipped}." : '');
    }

    if ($action === 'junk') {
        $done = 0;
        $skipped = 0;
        foreach (array_map('intval', kop_dm_ticked_ids('row')) as $id) {
            $file = kop_dm_file_of($id);
            if ($file === '' || !preg_match($JUNK_RE, $file)) {
                $skipped++;
                continue;
            }
            if (kop_dm_delete_record($id, true)) {
                $done++;
            } else {
                $skipped++;
            }
        }
        $apply_log[] = "Removed {$done} plugin/template media record(s); files left on disk." . ($skipped ? " Skipped {$skipped}." : '');
    }

    if ($action === 'dupes') {
        $all = kop_dm_hash_members();
        $groups_done = 0;
        $deleted = 0;
        $skipped = 0;
        foreach (kop_dm_ticked_ids('grp') as $hash) {
            $hash = preg_replace('/[^0-9a-f]/', '', (string) $hash);
            if (!isset($all[$hash]) || count($all[$hash]) < 2) {
                $skipped++;
                continue;
            }
            // Re-verify on disk: every member must exist and still hash the same.
            $members = array();
            foreach ($all[$hash] as $m) {
                $abs = kop_dm_uploads_base() . '/' . $m['file'];
                if (!file_exists($abs) || md5_file($abs) !== $hash) {
                    continue;
                }
                $members[] = $m;
            }
            if (count($members) < 2) {
                $apply_log[] = "Group {$hash}: skipped, copies no longer identical on disk.";
                $skipped++;
                continue;
            }
            $plan = kop_dm_plan_group($members);
            $keeper = $plan['keeper'];
            foreach ($plan['losers'] as $loser) {
                $mem = kop_dm_membership($loser['id']);
                // Folder membership: move keeper, or tag it, so no library loses the document.
                if ($loser['folder_action'] === 'move' && kop_dm_table_exists($T_REL)) {
                    $wpdb->delete($T_REL, array('attachment_id' => $keeper['id']), array('%d'));
                    $wpdb->query($wpdb->prepare("INSERT IGNORE INTO {$T_REL} (folder_id, attachment_id) VALUES (%d, %d)", $loser['folder'], $keeper['id']));
                    $keeper['folder'] = $loser['folder'];
                } elseif ($loser['folder_action'] === 'tag' && kop_dm_table_exists($T_TAG)) {
                    $wpdb->query($wpdb->prepare("INSERT IGNORE INTO {$T_TAG} (folder_id, attachment_id) VALUES (%d, %d)", $loser['folder'], $keeper['id']));
                }
                foreach ($mem['tags'] as $tf) {
                    if ($tf > 0 && $tf !== $keeper['folder'] && kop_dm_table_exists($T_TAG)) {
                        $wpdb->query($wpdb->prepare("INSERT IGNORE INTO {$T_TAG} (folder_id, attachment_id) VALUES (%d, %d)", $tf, $keeper['id']));
                    }
                }
                // Keep the better title.
                if (kop_dm_is_slug_title($keeper['title']) && !kop_dm_is_slug_title($loser['title'])) {
                    wp_update_post(array('ID' => $keeper['id'], 'post_title' => $loser['title']));
                    $keeper['title'] = $loser['title'];
                }
                if ($loser['refs']) {
                    kop_dm_rewrite_refs($loser['file'], $keeper['file']);
                }
                if (kop_dm_delete_record($loser['id'], false)) {
                    $deleted++;
                }
            }
            $groups_done++;
        }
        if ($deleted) {
            kop_dm_ref_index(true);
        }
        $apply_log[] = "Merged {$groups_done} group(s): deleted {$deleted} duplicate copy/copies." . ($skipped ? " Skipped {$skipped} group(s)." : '');
    }

    if ($action === 'regen') {
        $done = 0;
        $failed = 0;
        $left = 0;
        foreach (array_map('intval', kop_dm_ticked_ids('row')) as $id) {
            if ($done + $failed >= $REGEN_BATCH) {
                $left++;
                continue;
            }
            $abs = get_attached_file($id);
            if (!$abs || !file_exists($abs) || get_post_mime_type($id) !== 'application/pdf') {
                $failed++;
                continue;
            }
            $meta = wp_generate_attachment_metadata($id, $abs);
            if (is_array($meta) && !empty($meta['sizes'])) {
                wp_update_attachment_metadata($id, $meta);
                $done++;
            } else {
                $failed++;
            }
        }
        $apply_log[] = "Regenerated previews for {$done} PDF(s)." . ($failed ? " {$failed} failed (missing file or Ghostscript could not render it)." : '') . ($left ? " {$left} left for the next pass." : '');
    }

    if ($action === 'orphans') {
        $n1 = kop_dm_table_exists($T_REL)
            ? (int) $wpdb->query("DELETE r FROM {$T_REL} r LEFT JOIN {$wpdb->posts} p ON p.ID = r.attachment_id WHERE p.ID IS NULL")
            : 0;
        $n2 = kop_dm_table_exists($T_TAG)
            ? (int) $wpdb->query("DELETE r FROM {$T_TAG} r LEFT JOIN {$wpdb->posts} p ON p.ID = r.attachment_id WHERE p.ID IS NULL")
            : 0;
        $apply_log[] = "Removed {$n1} FileBird relation row(s) and {$n2} tag row(s) pointing at deleted attachments.";
    }
}

// ---------------------------------------------------------------------------
// Preview data for the current tab
// ---------------------------------------------------------------------------
$q = trim((string) ($_GET['q'] ?? ''));
$self = strtok($_SERVER['REQUEST_URI'], '?');
$counts = array();

$sidecars = kop_dm_sidecar_rows();
$counts['previews'] = count($sidecars);
$junk = kop_dm_junk_rows();
$counts['junk'] = count($junk);
$hash_members = kop_dm_hash_members();
$dup_groups = array_filter($hash_members, function ($v) { return count($v) > 1; });
$counts['dupes'] = count($dup_groups);
$missing_previews = kop_dm_pdfs_missing_previews();
$counts['regen'] = count($missing_previews);
$orphans = kop_dm_orphan_counts();
$counts['orphans'] = $orphans['rel'] + $orphans['tag'];

$near = array();
if ($section === 'near') {
    $by_key = array();
    foreach ($hash_members as $hash => $ms) {
        foreach ($ms as $m) {
            $m['hash'] = $hash;
            $by_key[kop_dm_norm_title($m['title']) . '|' . $m['folder']][] = $m;
        }
    }
    foreach ($by_key as $k => $ms) {
        $hashes = array_unique(array_map(function ($m) { return $m['hash']; }, $ms));
        if (count($ms) > 1 && count($hashes) > 1) {
            $near[$k] = $ms;
        }
    }
    ksort($near);
}
$counts['near'] = $section === 'near' ? count($near) : null;

$fmt_bytes = function ($b) {
    return $b >= 1048576 ? sprintf('%.1f MB', $b / 1048576) : sprintf('%d KB', $b / 1024);
};
$att_link = function ($id) {
    return '<a href="' . esc_url(get_edit_post_link($id)) . '" target="_blank" rel="noopener">#' . (int) $id . '</a>';
};
$matches_q = function ($hay) use ($q) {
    return $q === '' || mb_stripos((string) $hay, $q) !== false;
};
?><!DOCTYPE html>
<html><head><meta charset="utf-8"><title>Dedupe Media</title>
<style>
body { font-family: system-ui, sans-serif; margin: 24px; color: #000435; background: #F2EEDF; }
h1 { font-size: 1.15rem; margin: 0 0 8px; }
h2 { font-size: 1rem; margin: 14px 0 6px; }
table { border-collapse: collapse; background: #fff; font-size: 0.8rem; width: 100%; }
th, td { border: 1px solid #ccc; padding: 3px 7px; text-align: left; vertical-align: top; }
th { background: #000080; color: #fff; }
tbody tr { cursor: pointer; }
tbody tr:hover { background: #FFF5CB; }
tbody tr.kop-ticked { background: #B6E3D4; }
input[name$="[go]"] { transform: scale(1.4); margin: 3px; }
.ok { color: #1b7e3c; } .warn { color: #b8860b; } .bad { color: #a00; }
button { background: #33A7B5; color: #fff; border: none; border-radius: 6px; padding: 7px 12px; font-weight: 700; font-size: 0.85rem; cursor: pointer; }
.log { background: #fff; border: 1px solid #ccc; padding: 10px 14px; font-family: monospace; font-size: 0.8rem; margin-bottom: 12px; }
.kop-toolbar { position: sticky; top: 0; z-index: 60; background: #F2EEDF; padding: 8px 0 6px; border-bottom: 2px solid #33A7B5; margin-bottom: 8px; box-shadow: 0 4px 8px rgba(0,4,53,0.08); }
.kop-toolbar .bar { display: flex; flex-wrap: wrap; gap: 6px; align-items: center; margin-bottom: 6px; }
input[type=search] { padding: 6px 9px; border: 1px solid #000080; border-radius: 6px; }
td a { color: #000080; }
.tabs { display: flex; flex-wrap: wrap; gap: 6px; margin: 10px 0; }
.tabs a { padding: 6px 10px; border-radius: 6px; background: #fff; border: 1px solid #000080; color: #000080; text-decoration: none; font-weight: 600; font-size: 0.85rem; }
.tabs a.on { background: #000080; color: #fff; }
.keeper { font-weight: 700; }
.loser { color: #7a1f1f; margin-bottom: 4px; }
.muted { color: #666; font-weight: 400; }
.path { font-family: monospace; font-size: 0.75rem; font-weight: 400; }
</style></head><body>
<h1>Dedupe Media <small style="font-weight:400">&mdash; clear the duplicate tiles the May&ndash;June 2026 media restore left in the document libraries</small></h1>

<div class="tabs">
<?php foreach ($SECTIONS as $key => $label): ?>
    <a class="<?php echo $key === $section ? 'on' : ''; ?>" href="<?php echo esc_url($self . '?s=' . $key); ?>"><?php echo esc_html($label); ?><?php if (isset($counts[$key]) && $counts[$key] !== null): ?> (<?php echo (int) $counts[$key]; ?>)<?php endif; ?></a>
<?php endforeach; ?>
</div>

<?php if ($apply_log): ?>
    <div class="log ok"><?php echo implode('<br>', array_map('esc_html', $apply_log)); ?> <a href="<?php echo esc_url($self . '?s=' . $section); ?>">Reload for the next batch.</a></div>
<?php endif; ?>

<div class="log">
    Take an UpdraftPlus backup before the first Apply. Nothing changes until you click Apply on a tab.
    Do not run Media Sync's import again afterwards: it is what turned generated PDF previews and plugin files into media items.
    Linked-content index built <?php echo kop_dm_ref_built() ? esc_html(human_time_diff(kop_dm_ref_built())) . ' ago' : 'never'; ?>
    (<?php echo count(kop_dm_ref_index()); ?> linked upload paths across pages, wiki, lawsuits, legislation, news, memorial, options).
    <form method="post" style="display:inline"><?php wp_nonce_field('kop_dm_apply'); ?><button type="submit" name="do" value="rebuild_refs" style="background:#7a7a7a;padding:3px 8px">Rebuild index</button></form>
</div>

<form method="get" style="margin-bottom:10px">
    <input type="hidden" name="s" value="<?php echo esc_attr($section); ?>">
    <input type="search" name="q" value="<?php echo esc_attr($q); ?>" placeholder="Filter by title, file, or folder..." style="width:280px">
    <button type="submit" style="background:#000080">Filter</button>
</form>

<?php
// ------------------------------------------------------------------ previews
if ($section === 'previews'):
    $rows = array_values(array_filter($sidecars, function ($r) use ($matches_q) {
        return $matches_q($r['title'] . ' ' . $r['file'] . ' ' . kop_dm_folder_path($r['folder']) . ' ' . $r['status']);
    }));
    $shown = array_slice($rows, 0, $SHOW);
    $labels = array(
        'pdf-owns-preview' => array('ok', 'PDF owns this preview file: record removed, file stays'),
        'pdf-has-preview' => array('ok', 'PDF has its own preview: record and file removed'),
        'pdf-no-preview' => array('warn', 'PDF lacks a preview: removed; regenerate on the "PDFs missing previews" tab'),
        'no-pdf' => array('bad', 'No matching PDF found: left unticked for review'),
    );
?>
<h2>Preview images imported as media (<?php echo count($rows); ?><?php echo $q !== '' ? ' matching' : ''; ?>)</h2>
<p class="muted">Each of these is a first-page JPG WordPress generated for a PDF, later imported as a separate media item. Removing the record takes the extra tile out of the library; the PDF keeps its thumbnail.</p>
<?php if ($shown): ?>
<form method="post" action="<?php echo esc_url($_SERVER['REQUEST_URI']); ?>">
<?php wp_nonce_field('kop_dm_apply'); ?>
<div class="kop-toolbar"><div class="bar">
    <span>Showing <?php echo count($shown); ?> of <?php echo count($rows); ?> &middot; <span id="kop-count"></span></span>
    <button type="button" id="kop-tick-all" style="background:#000080">Tick all</button>
    <button type="button" id="kop-untick-all" style="background:#7a7a7a">Untick all</button>
    <button type="submit" name="do" value="previews" style="background:#1b7e3c"
        onclick="var n=document.querySelectorAll('tbody input:checked').length; if(!n){alert('Tick rows first.');return false;} return window.confirm('Remove '+n+' preview-image media record(s)?');">Remove ticked</button>
</div></div>
<table><thead><tr><th></th><th>Preview image</th><th>Folder</th><th>Matching PDF</th><th>Outcome</th></tr></thead><tbody>
<?php foreach ($shown as $r): $lab = $labels[$r['status']]; ?>
    <tr>
        <td><input type="checkbox" name="row[<?php echo $r['id']; ?>][go]" value="1" <?php echo $r['status'] === 'no-pdf' ? '' : 'checked'; ?>></td>
        <td><?php echo $att_link($r['id']); ?> <?php echo esc_html($r['title']); ?><br><span class="path"><?php echo esc_html($r['file']); ?></span></td>
        <td><?php echo esc_html(kop_dm_folder_path($r['folder']) ?: '(unfiled)'); ?></td>
        <td><?php if ($r['pdf']): ?><?php echo $att_link($r['pdf']['id']); ?> <?php echo esc_html($r['pdf']['title']); ?><br><span class="muted"><?php echo esc_html(kop_dm_folder_path($r['pdf']['folder']) ?: '(unfiled)'); ?></span><?php else: ?><span class="muted">none</span><?php endif; ?></td>
        <td class="<?php echo $lab[0]; ?>"><?php echo esc_html($lab[1]); ?></td>
    </tr>
<?php endforeach; ?>
</tbody></table>
</form>
<?php else: ?>
<p class="ok">No preview-image media items<?php echo $q !== '' ? ' match this filter' : ' left'; ?>.</p>
<?php endif; ?>

<?php
// --------------------------------------------------------------------- dupes
elseif ($section === 'dupes'):
    $plans = array();
    foreach ($dup_groups as $hash => $members) {
        $p = kop_dm_plan_group($members);
        $hay = $hash;
        foreach ($members as $m) {
            $hay .= ' ' . $m['title'] . ' ' . $m['file'] . ' ' . kop_dm_folder_path($m['folder']);
        }
        if ($matches_q($hay)) {
            $plans[$hash] = $p;
        }
    }
    uasort($plans, function ($a, $b) { return strcasecmp($a['keeper']['title'], $b['keeper']['title']); });
    $shown = array_slice($plans, 0, $SHOW, true);
    $redundant = 0;
    $bytes = 0;
    foreach ($plans as $p) {
        $redundant += count($p['losers']);
        $bytes += count($p['losers']) * $p['keeper']['size'];
    }
?>
<h2>Byte-identical groups (<?php echo count($plans); ?><?php echo $q !== '' ? ' matching' : ''; ?>; <?php echo $redundant; ?> redundant copies, <?php echo $fmt_bytes($bytes); ?>)</h2>
<p class="muted">The keeper is the copy with the most incoming links, then a real facility folder, a preview, a readable title, and the oldest ID. Every other copy is deleted after its folder is transferred to the keeper (a FileBird move when the copy sits deeper in the same tree, otherwise a theme tag) and any page, wiki, lawsuit, or news link to it is rewritten. Hashes are re-verified on disk when applied.</p>
<?php if ($shown): ?>
<form method="post" action="<?php echo esc_url($_SERVER['REQUEST_URI']); ?>">
<?php wp_nonce_field('kop_dm_apply'); ?>
<div class="kop-toolbar"><div class="bar">
    <span>Showing <?php echo count($shown); ?> of <?php echo count($plans); ?> group(s) &middot; <span id="kop-count"></span></span>
    <button type="button" id="kop-tick-all" style="background:#000080">Tick all</button>
    <button type="button" id="kop-untick-all" style="background:#7a7a7a">Untick all</button>
    <button type="submit" name="do" value="dupes" style="background:#1b7e3c"
        onclick="var n=document.querySelectorAll('tbody input:checked').length; if(!n){alert('Tick groups first.');return false;} return window.confirm('Merge '+n+' duplicate group(s)? Extra copies are deleted from the media library and disk.');">Merge ticked</button>
</div></div>
<table><thead><tr><th></th><th>Type / size</th><th>Keep</th><th>Delete</th></tr></thead><tbody>
<?php foreach ($shown as $hash => $p): $k = $p['keeper']; ?>
    <tr>
        <td><input type="checkbox" name="grp[<?php echo esc_attr($hash); ?>][go]" value="1" checked></td>
        <td><?php echo esc_html(str_replace('application/', '', $k['mime'])); ?><br><span class="muted"><?php echo esc_html($fmt_bytes($k['size'])); ?></span></td>
        <td class="keeper"><?php echo $att_link($k['id']); ?> <?php echo esc_html($k['title']); ?><br><span class="path"><?php echo esc_html($k['file']); ?></span><br><span class="muted"><?php echo esc_html(kop_dm_folder_path($k['folder']) ?: '(unfiled)'); ?><?php if ($k['refs']): ?> &middot; linked from <?php echo esc_html(implode(', ', $k['refs'])); ?><?php endif; ?></span></td>
        <td>
        <?php foreach ($p['losers'] as $l): ?>
            <div class="loser"><?php echo $att_link($l['id']); ?> <?php echo esc_html($l['title']); ?><br><span class="path"><?php echo esc_html($l['file']); ?></span><br><span class="muted"><?php echo esc_html(kop_dm_folder_path($l['folder']) ?: '(unfiled)'); ?> &rarr; <?php echo esc_html($l['plan']); ?><?php if ($l['refs']): ?> (<?php echo esc_html(implode(', ', $l['refs'])); ?>)<?php endif; ?></span></div>
        <?php endforeach; ?>
        </td>
    </tr>
<?php endforeach; ?>
</tbody></table>
</form>
<?php else: ?>
<p class="ok">No byte-identical duplicate groups<?php echo $q !== '' ? ' match this filter' : ' left'; ?>.</p>
<?php endif; ?>

<?php
// ---------------------------------------------------------------------- junk
elseif ($section === 'junk'):
    $rows = array_values(array_filter($junk, function ($r) use ($matches_q) {
        return $matches_q($r->post_title . ' ' . $r->file . ' ' . kop_dm_folder_path($r->folder_id));
    }));
    $shown = array_slice($rows, 0, $SHOW);
?>
<h2>Plugin and template files imported as media (<?php echo count($rows); ?><?php echo $q !== '' ? ' matching' : ''; ?>)</h2>
<p class="muted">Files from plugin directories under uploads (template caches, fonts, form uploads, Google Drive thumbnails). Only the media record is removed; the file stays for the plugin that owns it. Rows filed into a real facility folder are left unticked so you can check them first.</p>
<?php if ($shown): ?>
<form method="post" action="<?php echo esc_url($_SERVER['REQUEST_URI']); ?>">
<?php wp_nonce_field('kop_dm_apply'); ?>
<div class="kop-toolbar"><div class="bar">
    <span>Showing <?php echo count($shown); ?> of <?php echo count($rows); ?> &middot; <span id="kop-count"></span></span>
    <button type="button" id="kop-tick-all" style="background:#000080">Tick all</button>
    <button type="button" id="kop-untick-all" style="background:#7a7a7a">Untick all</button>
    <button type="submit" name="do" value="junk" style="background:#1b7e3c"
        onclick="var n=document.querySelectorAll('tbody input:checked').length; if(!n){alert('Tick rows first.');return false;} return window.confirm('Remove '+n+' media record(s)? Files stay on disk.');">Remove ticked</button>
</div></div>
<table><thead><tr><th></th><th>Media item</th><th>Type</th><th>Folder</th></tr></thead><tbody>
<?php foreach ($shown as $r): $fid = (int) $r->folder_id; $safe = ($fid === 0 || in_array($fid, $HOLDING_FOLDERS, true)); ?>
    <tr>
        <td><input type="checkbox" name="row[<?php echo (int) $r->ID; ?>][go]" value="1" <?php echo $safe ? 'checked' : ''; ?>></td>
        <td><?php echo $att_link($r->ID); ?> <?php echo esc_html($r->post_title); ?><br><span class="path"><?php echo esc_html($r->file); ?></span></td>
        <td><?php echo esc_html($r->post_mime_type); ?></td>
        <td class="<?php echo $safe ? '' : 'warn'; ?>"><?php echo esc_html(kop_dm_folder_path($fid) ?: '(unfiled)'); ?></td>
    </tr>
<?php endforeach; ?>
</tbody></table>
</form>
<?php else: ?>
<p class="ok">No plugin or template files are registered as media<?php echo $q !== '' ? ' matching this filter' : ''; ?>.</p>
<?php endif; ?>

<?php
// --------------------------------------------------------------------- regen
elseif ($section === 'regen'):
    $rows = array_values(array_filter($missing_previews, function ($r) use ($matches_q) {
        return $matches_q($r['title'] . ' ' . $r['file']);
    }));
    $shown = array_slice($rows, 0, $SHOW);
?>
<h2>PDFs with no generated preview (<?php echo count($rows); ?><?php echo $q !== '' ? ' matching' : ''; ?>)</h2>
<p class="muted">These tiles show no thumbnail. Regeneration renders page one with Imagick/Ghostscript; <?php echo (int) $REGEN_BATCH; ?> PDFs per pass to stay inside the request limit. Run this after removing preview images whose PDF lacked its own.</p>
<?php if ($shown): ?>
<form method="post" action="<?php echo esc_url($_SERVER['REQUEST_URI']); ?>">
<?php wp_nonce_field('kop_dm_apply'); ?>
<div class="kop-toolbar"><div class="bar">
    <span>Showing <?php echo count($shown); ?> of <?php echo count($rows); ?> &middot; <span id="kop-count"></span></span>
    <button type="button" id="kop-tick-all" style="background:#000080">Tick all</button>
    <button type="button" id="kop-untick-all" style="background:#7a7a7a">Untick all</button>
    <button type="submit" name="do" value="regen" style="background:#1b7e3c"
        onclick="var n=document.querySelectorAll('tbody input:checked').length; if(!n){alert('Tick rows first.');return false;} return window.confirm('Regenerate previews for up to <?php echo (int) $REGEN_BATCH; ?> of the '+n+' ticked PDF(s)?');">Regenerate</button>
</div></div>
<table><thead><tr><th></th><th>PDF</th><th>File</th><th>On disk</th></tr></thead><tbody>
<?php foreach ($shown as $r): ?>
    <tr>
        <td><input type="checkbox" name="row[<?php echo $r['id']; ?>][go]" value="1" <?php echo $r['exists'] ? 'checked' : ''; ?>></td>
        <td><?php echo $att_link($r['id']); ?> <?php echo esc_html($r['title']); ?></td>
        <td class="path"><?php echo esc_html($r['file']); ?></td>
        <td class="<?php echo $r['exists'] ? 'ok' : 'bad'; ?>"><?php echo $r['exists'] ? 'yes' : 'missing'; ?></td>
    </tr>
<?php endforeach; ?>
</tbody></table>
</form>
<?php else: ?>
<p class="ok">Every PDF has a preview<?php echo $q !== '' ? ' (matching this filter)' : ''; ?>.</p>
<?php endif; ?>

<?php
// ------------------------------------------------------------------- orphans
elseif ($section === 'orphans'):
?>
<h2>Relation rows pointing at deleted attachments</h2>
<p><?php echo (int) $orphans['rel']; ?> FileBird folder relation(s) and <?php echo (int) $orphans['tag']; ?> theme tag(s) reference attachment IDs that no longer exist. They inflate folder counts and nothing else.</p>
<?php if ($counts['orphans']): ?>
<form method="post" action="<?php echo esc_url($_SERVER['REQUEST_URI']); ?>">
<?php wp_nonce_field('kop_dm_apply'); ?>
<button type="submit" name="do" value="orphans" style="background:#1b7e3c" onclick="return window.confirm('Delete <?php echo (int) $counts['orphans']; ?> orphan relation row(s)?');">Delete orphan rows</button>
</form>
<?php else: ?>
<p class="ok">No orphan rows.</p>
<?php endif; ?>

<?php
// ---------------------------------------------------------------------- near
elseif ($section === 'near'):
    $rows = array();
    foreach ($near as $k => $ms) {
        $hay = $k;
        foreach ($ms as $m) {
            $hay .= ' ' . $m['title'] . ' ' . $m['file'] . ' ' . kop_dm_folder_path($m['folder']);
        }
        if ($matches_q($hay)) {
            $rows[$k] = $ms;
        }
    }
    $shown = array_slice($rows, 0, $SHOW, true);
?>
<h2>Same title, same folder, different bytes (<?php echo count($rows); ?><?php echo $q !== '' ? ' matching' : ''; ?>)</h2>
<p class="muted">Read-only. These look like duplicates in a library but the files differ (different scans, versions, or a wrong file under the right name). Open each pair and delete or retitle by hand in the media library.</p>
<?php if ($shown): ?>
<table><thead><tr><th>Folder</th><th>Copies</th></tr></thead><tbody>
<?php foreach ($shown as $k => $ms): ?>
    <tr>
        <td><?php echo esc_html(kop_dm_folder_path($ms[0]['folder']) ?: '(unfiled)'); ?></td>
        <td>
        <?php foreach ($ms as $m): ?>
            <div><?php echo $att_link($m['id']); ?> <?php echo esc_html($m['title']); ?> <span class="muted">&middot; <?php echo esc_html($fmt_bytes($m['size'])); ?> &middot; <?php echo esc_html(substr($m['date'], 0, 10)); ?></span><br><span class="path"><?php echo esc_html($m['file']); ?></span></div>
        <?php endforeach; ?>
        </td>
    </tr>
<?php endforeach; ?>
</tbody></table>
<?php else: ?>
<p class="ok">No near-duplicate titles<?php echo $q !== '' ? ' match this filter' : ''; ?>.</p>
<?php endif; ?>
<?php endif; ?>

<script>
(function () {
    var rows = Array.prototype.slice.call(document.querySelectorAll('tbody tr'));
    function check(row) { return row.querySelector('input[type=checkbox]'); }
    function paint(row) { var c = check(row); if (c) row.classList.toggle('kop-ticked', c.checked); }
    function refresh() {
        var el = document.getElementById('kop-count');
        if (!el) return;
        var n = rows.filter(function (r) { var c = check(r); return c && c.checked; }).length;
        el.textContent = n + ' ticked.';
    }
    rows.forEach(function (row) {
        paint(row);
        row.addEventListener('click', function (e) {
            if (e.target.closest('a, button, input')) {
                if (e.target === check(row)) { paint(row); refresh(); }
                return;
            }
            var c = check(row);
            if (!c) return;
            c.checked = !c.checked;
            paint(row);
            refresh();
        });
    });
    refresh();
    var ta = document.getElementById('kop-tick-all');
    var ua = document.getElementById('kop-untick-all');
    if (ta) ta.addEventListener('click', function () { rows.forEach(function (r) { var c = check(r); if (c) { c.checked = true; paint(r); } }); refresh(); });
    if (ua) ua.addEventListener('click', function () { rows.forEach(function (r) { var c = check(r); if (c) { c.checked = false; paint(r); } }); refresh(); });
})();
</script>
</body></html>
