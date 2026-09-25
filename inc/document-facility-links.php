<?php
/**
 * Library-wide links between documents and facilities.
 *
 * The attachment remains the canonical document. Facility IDs are indexed in
 * the existing kop_research_facilities postmeta rows; the context for each
 * relationship (a short note and page reference) lives beside those rows.
 * Editors use the Document Links screen to review every FileBird attachment,
 * including documents with no facility matches.
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('KOP_DOCUMENT_FACILITY_CONTEXTS_META')) define('KOP_DOCUMENT_FACILITY_CONTEXTS_META', 'kop_document_facility_contexts');
if (!defined('KOP_DOCUMENT_FACILITY_REVIEWED_META')) define('KOP_DOCUMENT_FACILITY_REVIEWED_META', 'kop_document_facility_reviewed_at');
if (!defined('KOP_DOCUMENT_FACILITY_CONTEXT_STATUS_META')) define('KOP_DOCUMENT_FACILITY_CONTEXT_STATUS_META', 'kop_document_facility_context_status');
if (!defined('KOP_DOCUMENT_FACILITY_SCREEN')) define('KOP_DOCUMENT_FACILITY_SCREEN', 'kop-document-facility-links');

/** Return cleaned, facility-id keyed note and page data for an attachment. */
function kop_document_facility_contexts($attachment_id) {
    $raw = get_post_meta((int) $attachment_id, KOP_DOCUMENT_FACILITY_CONTEXTS_META, true);
    if (!is_array($raw)) {
        return array();
    }

    $out = array();
    foreach ($raw as $facility_id => $context) {
        $facility_id = (int) $facility_id;
        if ($facility_id <= 0 || !is_array($context)) {
            continue;
        }
        $out[$facility_id] = array(
            'note'  => trim((string) ($context['note'] ?? '')),
            'pages' => trim((string) ($context['pages'] ?? '')),
        );
    }
    return $out;
}

/** Sanitize and bound editor-entered relationship context. */
function kop_document_clean_link_context($value, $limit, $textarea = false) {
    $clean = $textarea ? sanitize_textarea_field((string) $value) : sanitize_text_field((string) $value);
    if (preg_match('/^.{0,' . (int) $limit . '}/us', $clean, $match)) {
        return $match[0];
    }
    return substr($clean, 0, (int) $limit);
}

/**
 * Save a complete set of facility relationships on one attachment.
 * The `reviewed` flag means an editor checked the document, including when
 * the checked result is an empty facility list.
 */
function kop_document_save_facility_associations($attachment_id, $associations, $reviewed = false) {
    $attachment_id = (int) $attachment_id;
    if ($attachment_id <= 0 || get_post_type($attachment_id) !== 'attachment') {
        return new WP_Error('kop_document_missing', 'No such document.', array('status' => 404));
    }

    $rows = array();
    foreach ((array) $associations as $association) {
        if (!is_array($association)) {
            continue;
        }
        $facility_id = (int) ($association['facility_id'] ?? $association['id'] ?? 0);
        if ($facility_id <= 0) {
            continue;
        }
        $rows[$facility_id] = array(
            'facility_id' => $facility_id,
            'note' => kop_document_clean_link_context($association['note'] ?? '', 500, true),
            'pages' => kop_document_clean_link_context($association['pages'] ?? '', 120),
        );
    }

    $valid_ids = function_exists('kop_research_valid_facility_ids')
        ? kop_research_valid_facility_ids(array_keys($rows))
        : array();
    $valid = array_fill_keys(array_map('intval', $valid_ids), true);
    $rows = array_intersect_key($rows, $valid);

    delete_post_meta($attachment_id, KOP_RESEARCH_FACILITY_META);
    $contexts = array();
    foreach ($rows as $facility_id => $row) {
        add_post_meta($attachment_id, KOP_RESEARCH_FACILITY_META, (int) $facility_id);
        if ($row['note'] !== '' || $row['pages'] !== '') {
            $contexts[(int) $facility_id] = array('note' => $row['note'], 'pages' => $row['pages']);
        }
    }

    if ($contexts) {
        update_post_meta($attachment_id, KOP_DOCUMENT_FACILITY_CONTEXTS_META, $contexts);
    } else {
        delete_post_meta($attachment_id, KOP_DOCUMENT_FACILITY_CONTEXTS_META);
    }

    if ($rows) {
        $complete = true;
        foreach ($rows as $row) {
            if ($row['note'] === '' || $row['pages'] === '') {
                $complete = false;
                break;
            }
        }
        update_post_meta(
            $attachment_id,
            KOP_DOCUMENT_FACILITY_CONTEXT_STATUS_META,
            $complete ? 'complete' : 'needs_context'
        );
    } else {
        update_post_meta($attachment_id, KOP_DOCUMENT_FACILITY_CONTEXT_STATUS_META, 'none');
    }

    if ($reviewed) {
        update_post_meta($attachment_id, KOP_DOCUMENT_FACILITY_REVIEWED_META, current_time('mysql'));
    }

    return true;
}

/** Preserve context fields while the older Research & Reports editor changes tags. */
function kop_document_sync_facility_ids($attachment_id, $facility_ids) {
    $existing_contexts = kop_document_facility_contexts($attachment_id);
    $associations = array();
    foreach (array_values(array_unique(array_map('intval', (array) $facility_ids))) as $facility_id) {
        $context = $existing_contexts[$facility_id] ?? array();
        $associations[] = array(
            'facility_id' => $facility_id,
            'note' => $context['note'] ?? '',
            'pages' => $context['pages'] ?? '',
        );
    }
    return kop_document_save_facility_associations($attachment_id, $associations, false);
}

/** Native FileBird filings plus theme-level multi-folder tags, as one SQL source. */
function kop_document_library_relation_table() {
    global $wpdb;
    $tables = array(
        $wpdb->prefix . 'fbv_attachment_folder',
        $wpdb->prefix . 'kop_media_folder_tags',
    );
    $sources = array();
    foreach ($tables as $table) {
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table) {
            $sources[] = "SELECT attachment_id, folder_id FROM $table";
        }
    }
    if (!$sources) return '';
    return count($sources) === 1 ? '(' . $sources[0] . ')' : '(' . implode(' UNION ', $sources) . ')';
}

/** Facility records are searchable by editors on the association screen. */
function kop_document_library_editor_permission() {
    return current_user_can('manage_options');
}

/** Register the library-wide review screen under the theme or KOP Tools menu. */
function kop_document_library_admin_menu() {
    $parent = function_exists('kop_tools_parent_slug') ? kop_tools_parent_slug() : 'tools.php';
    add_submenu_page(
        $parent,
        'Document Facility Links',
        'Document Facility Links',
        'manage_options',
        KOP_DOCUMENT_FACILITY_SCREEN,
        'kop_document_library_render_admin_page'
    );
}
add_action('admin_menu', 'kop_document_library_admin_menu', 30);

/** Render the searchable review queue; all document data is loaded through REST. */
function kop_document_library_render_admin_page() {
    if (!current_user_can('manage_options')) {
        wp_die('Not authorized', 'Access Denied', array('response' => 403));
    }
    ?>
    <div class="wrap kop-document-links-admin">
        <h1>Document Facility Links</h1>
        <p>Review documents from every FileBird folder. Add each facility the document discusses, then record the page reference and a short note for that facility. Saving an empty list marks a document as reviewed with no facility matches.</p>
        <div class="kop-dfl-coverage" id="kop-dfl-coverage" aria-live="polite">Loading review totals…</div>
        <div class="kop-dfl-toolbar">
            <label><span class="screen-reader-text">Search library documents</span><input type="search" id="kop-dfl-search" placeholder="Search title or filename"></label>
            <label><span class="screen-reader-text">Filter documents</span>
                <select id="kop-dfl-filter">
                    <option value="all">All documents</option>
                    <option value="unreviewed">Needs review</option>
                    <option value="reviewed">Reviewed</option>
                    <option value="context">Needs note or page reference</option>
                </select>
            </label>
            <button type="button" class="button" id="kop-dfl-refresh">Refresh</button>
        </div>
        <div id="kop-dfl-message" class="kop-dfl-message" role="status" aria-live="polite"></div>
        <div id="kop-dfl-results" class="kop-dfl-results"><p>Loading documents…</p></div>
        <nav id="kop-dfl-pagination" class="kop-dfl-pagination" aria-label="Document pages"></nav>

        <dialog id="kop-dfl-dialog" class="kop-dfl-dialog" aria-labelledby="kop-dfl-dialog-title">
            <form method="dialog" class="kop-dfl-form">
                <h2 id="kop-dfl-dialog-title">Edit facility links</h2>
                <p class="kop-dfl-doc-title"></p>
                <div class="kop-dfl-associations" aria-live="polite"></div>
                <div class="kop-dfl-facility-search">
                    <label for="kop-dfl-facility-query">Add a facility</label>
                    <input type="search" id="kop-dfl-facility-query" autocomplete="off" placeholder="Search facilities by name">
                    <ul id="kop-dfl-facility-results" hidden></ul>
                </div>
                <p class="description">Use the page numbers printed in the document when they differ from the PDF viewer’s page count. The note should say what the document says about this specific program.</p>
                <p class="kop-dfl-dialog-message" role="status" aria-live="polite"></p>
                <div class="kop-dfl-dialog-actions">
                    <button type="button" class="button" id="kop-dfl-cancel">Cancel</button>
                    <button type="button" class="button button-primary" id="kop-dfl-save">Save and mark reviewed</button>
                </div>
            </form>
        </dialog>
    </div>
    <?php
}

/** Load the browser script and style on this admin screen only. */
function kop_document_library_admin_assets($hook) {
    if (empty($_GET['page']) || sanitize_key(wp_unslash($_GET['page'])) !== KOP_DOCUMENT_FACILITY_SCREEN) {
        return;
    }
    $dir = get_stylesheet_directory();
    $uri = get_stylesheet_directory_uri();
    $css = '/css/document-facility-links.css';
    $js = '/js/document-facility-links.js';
    if (file_exists($dir . $css)) {
        wp_enqueue_style('kop-document-facility-links', $uri . $css, array(), filemtime($dir . $css));
    }
    if (file_exists($dir . $js)) {
        wp_enqueue_script('kop-document-facility-links', $uri . $js, array(), filemtime($dir . $js), true);
        wp_localize_script('kop-document-facility-links', 'KOP_DOCUMENT_LINKS', array(
            'list' => esc_url_raw(rest_url('kop/v1/document-library')),
            'save' => esc_url_raw(rest_url('kop/v1/document-facility-links')),
            'facilitySearch' => esc_url_raw(rest_url('kop/v1/research-facilities')),
            'nonce' => wp_create_nonce('wp_rest'),
        ));
    }
}
add_action('admin_enqueue_scripts', 'kop_document_library_admin_assets', 10, 1);

/** REST routes for the review queue and relationship editor. */
function kop_document_library_register_rest() {
    register_rest_route('kop/v1', '/document-library', array(
        'methods' => WP_REST_Server::READABLE,
        'callback' => 'kop_document_library_rest_list',
        'permission_callback' => 'kop_document_library_editor_permission',
        'args' => array(
            'q' => array('type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_text_field'),
            'status' => array('type' => 'string', 'default' => 'all', 'sanitize_callback' => 'sanitize_key'),
            'page' => array('type' => 'integer', 'default' => 1, 'sanitize_callback' => 'absint'),
            'per_page' => array('type' => 'integer', 'default' => 25, 'sanitize_callback' => 'absint'),
        ),
    ));

    register_rest_route('kop/v1', '/document-facility-links', array(
        'methods' => WP_REST_Server::CREATABLE,
        'callback' => 'kop_document_library_rest_save',
        'permission_callback' => 'kop_document_library_editor_permission',
    ));
}
add_action('rest_api_init', 'kop_document_library_register_rest');

/** Build a text-search and review-status clause for an attachment query. */
function kop_document_library_where($q, $status, &$params) {
    global $wpdb;
    $clauses = array("p.post_type = 'attachment'", "p.post_status NOT IN ('trash','auto-draft')");
    $params = array();
    $hidden = function_exists('kop_get_hidden_preview_ids') ? kop_get_hidden_preview_ids() : array();
    if ($hidden) {
        $clauses[] = 'p.ID NOT IN (' . implode(',', array_map('intval', $hidden)) . ')';
    }
    $q = trim((string) $q);
    if ($q !== '') {
        $like = '%' . $wpdb->esc_like($q) . '%';
        $clauses[] = "(p.post_title LIKE %s OR p.post_content LIKE %s OR EXISTS (
            SELECT 1 FROM {$wpdb->postmeta} fm
            WHERE fm.post_id = p.ID AND fm.meta_key = '_wp_attached_file' AND fm.meta_value LIKE %s
        ))";
        array_push($params, $like, $like, $like);
    }
    if ($status === 'reviewed') {
        $clauses[] = "EXISTS (SELECT 1 FROM {$wpdb->postmeta} rv WHERE rv.post_id = p.ID AND rv.meta_key = %s AND rv.meta_value <> '')";
        $params[] = KOP_DOCUMENT_FACILITY_REVIEWED_META;
    } elseif ($status === 'unreviewed') {
        $clauses[] = "NOT EXISTS (SELECT 1 FROM {$wpdb->postmeta} rv WHERE rv.post_id = p.ID AND rv.meta_key = %s AND rv.meta_value <> '')";
        $params[] = KOP_DOCUMENT_FACILITY_REVIEWED_META;
    } elseif ($status === 'context') {
        $clauses[] = "EXISTS (SELECT 1 FROM {$wpdb->postmeta} ft WHERE ft.post_id = p.ID AND ft.meta_key = %s)";
        $clauses[] = "NOT EXISTS (SELECT 1 FROM {$wpdb->postmeta} cs WHERE cs.post_id = p.ID AND cs.meta_key = %s AND cs.meta_value = 'complete')";
        $params[] = KOP_RESEARCH_FACILITY_META;
        $params[] = KOP_DOCUMENT_FACILITY_CONTEXT_STATUS_META;
    }
    return implode(' AND ', $clauses);
}

/** All currently assigned folder names for a batch of attachment IDs. */
function kop_document_library_folder_names($attachment_ids) {
    global $wpdb;
    $attachment_ids = array_values(array_unique(array_filter(array_map('intval', (array) $attachment_ids))));
    if (!$attachment_ids || !($table = kop_document_library_relation_table())) {
        return array();
    }
    $folders = array();
    foreach ((array) kop_get_filebird_folders() as $folder) {
        $folders[(int) $folder->id] = (string) $folder->name;
    }
    $in = implode(',', $attachment_ids);
    $rows = $wpdb->get_results("SELECT DISTINCT rel.attachment_id, rel.folder_id FROM $table rel WHERE rel.attachment_id IN ($in)", ARRAY_A);
    $out = array();
    foreach ((array) $rows as $row) {
        $id = (int) $row['attachment_id'];
        $folder_id = (int) $row['folder_id'];
        if (isset($folders[$folder_id])) {
            $out[$id][] = $folders[$folder_id];
        }
    }
    foreach ($out as &$names) {
        $names = array_values(array_unique($names));
    }
    unset($names);
    return $out;
}

/** Existing Research & Reports entries that link to a publisher page. */
function kop_document_library_external_items($q = '', $status = 'all') {
    if (!function_exists('kop_research_library_external')) {
        return array();
    }
    $edits = function_exists('kop_research_external_edits') ? kop_research_external_edits() : array();
    $q = strtolower(trim((string) $q));
    $items = array();
    foreach (kop_research_library_external() as $entry) {
        $edit = isset($edits[$entry['id']]) && is_array($edits[$entry['id']]) ? $edits[$entry['id']] : array();
        $title = (string) ($edit['title'] ?? $entry['title']);
        $reviewed = trim((string) ($edit['reviewed_at'] ?? ''));
        $facility_ids = array_map('intval', (array) ($edit['facilities'] ?? array()));
        $contexts = isset($edit['facility_contexts']) && is_array($edit['facility_contexts']) ? $edit['facility_contexts'] : array();
        if ($q !== '' && stripos($title, $q) === false && stripos((string) ($entry['byline'] ?? ''), $q) === false) {
            continue;
        }
        if ($status === 'reviewed' && $reviewed === '') continue;
        if ($status === 'unreviewed' && $reviewed !== '') continue;
        if ($status === 'context') {
            if (!$facility_ids) continue;
            $complete = true;
            foreach ($facility_ids as $facility_id) {
                $context = $contexts[$facility_id] ?? $contexts[(string) $facility_id] ?? array();
                if (empty($context['note']) || empty($context['pages'])) { $complete = false; break; }
            }
            if ($complete) continue;
        }
        $facility_chips = function_exists('kop_research_facility_chips') ? kop_research_facility_chips($facility_ids) : array();
        $associations = array();
        foreach ($facility_chips as $chip) {
            $context = $contexts[$chip['id']] ?? $contexts[(string) $chip['id']] ?? array();
            $associations[] = array_merge($chip, array(
                'note' => (string) ($context['note'] ?? ''),
                'pages' => (string) ($context['pages'] ?? ''),
            ));
        }
        $context_status = 'none';
        if ($facility_ids) {
            $context_status = 'complete';
            foreach ($associations as $association) {
                if ($association['note'] === '' || $association['pages'] === '') {
                    $context_status = 'needs_context';
                    break;
                }
            }
        }
        $items[] = array(
            'key' => 'ext:' . $entry['id'],
            'id' => 0,
            'title' => $title,
            'mime' => 'external',
            'url' => (string) $entry['url'],
            'date' => '',
            'folders' => array('Research & Reports · Publisher'),
            'reviewed_at' => $reviewed,
            'context_status' => $context_status,
            'associations' => $associations,
        );
    }
    return $items;
}

/** Return one searchable page of all FileBird attachments plus external research links. */
function kop_document_library_rest_list($request) {
    global $wpdb;
    $table = kop_document_library_relation_table();
    if ($table === '') {
        return new WP_Error('kop_document_library_unavailable', 'FileBird folders are not available.', array('status' => 503));
    }

    $q = trim((string) $request->get_param('q'));
    $status = (string) $request->get_param('status');
    if (!in_array($status, array('all', 'reviewed', 'unreviewed', 'context'), true)) $status = 'all';
    $page = max(1, (int) $request->get_param('page'));
    $per_page = min(50, max(10, (int) $request->get_param('per_page')));
    $external = kop_document_library_external_items($q, $status);

    $params = array();
    $where = kop_document_library_where($q, $status, $params);
    $from = "$table af INNER JOIN {$wpdb->posts} p ON p.ID = af.attachment_id";
    $count_sql = "SELECT COUNT(DISTINCT p.ID) FROM $from WHERE $where";
    $attachment_total = (int) $wpdb->get_var($params ? $wpdb->prepare($count_sql, $params) : $count_sql);

    $all_params = array();
    $all_where = kop_document_library_where('', 'all', $all_params);
    $coverage_sql = "SELECT COUNT(DISTINCT p.ID) AS total,
        COUNT(DISTINCT CASE WHEN EXISTS (
            SELECT 1 FROM {$wpdb->postmeta} rv WHERE rv.post_id = p.ID AND rv.meta_key = %s AND rv.meta_value <> ''
        ) THEN p.ID ELSE NULL END) AS reviewed
        FROM $from WHERE $all_where";
    $coverage_args = array_merge(array(KOP_DOCUMENT_FACILITY_REVIEWED_META), $all_params);
    $coverage = $wpdb->get_row($wpdb->prepare($coverage_sql, $coverage_args), ARRAY_A);
    $library_total = (int) ($coverage['total'] ?? 0) + count(kop_document_library_external_items('', 'all'));
    $library_reviewed = (int) ($coverage['reviewed'] ?? 0);
    foreach (kop_document_library_external_items('', 'all') as $external_item) {
        if ($external_item['reviewed_at'] !== '') $library_reviewed++;
    }

    // Publisher links are a small curated set and appear before file records.
    // The offset adjustment keeps pagination stable across both kinds.
    $external_count = count($external);
    $global_offset = ($page - 1) * $per_page;
    $external_page = array();
    if ($global_offset < $external_count) {
        $external_page = array_slice($external, $global_offset, $per_page);
    }
    $attachment_offset = max(0, $global_offset - $external_count);
    $attachment_limit = $per_page - count($external_page);
    if ($page > 1 && $global_offset >= $external_count) $attachment_limit = $per_page;

    $rows = array();
    if ($attachment_limit > 0) {
        $list_sql = "SELECT p.ID, p.post_title, p.post_mime_type, p.post_date,
            MAX(rv.meta_value) AS reviewed_at
            FROM $table af
            INNER JOIN {$wpdb->posts} p ON p.ID = af.attachment_id
            LEFT JOIN {$wpdb->postmeta} rv ON rv.post_id = p.ID AND rv.meta_key = %s
            WHERE $where
            GROUP BY p.ID
            ORDER BY p.post_date DESC, p.ID DESC
            LIMIT %d OFFSET %d";
        $list_params = array_merge(array(KOP_DOCUMENT_FACILITY_REVIEWED_META), $params, array($attachment_limit, $attachment_offset));
        $rows = (array) $wpdb->get_results($wpdb->prepare($list_sql, $list_params), ARRAY_A);
    }

    $ids = array_map(static function ($row) { return (int) $row['ID']; }, $rows);
    $folder_names = kop_document_library_folder_names($ids);
    $items = $external_page;
    foreach ($rows as $row) {
        $id = (int) $row['ID'];
        $chips = function_exists('kop_research_facility_chips')
            ? kop_research_facility_chips(kop_research_facility_ids('att:' . $id))
            : array();
        $contexts = kop_document_facility_contexts($id);
        $associations = array();
        foreach ($chips as $chip) {
            $context = $contexts[$chip['id']] ?? array();
            $associations[] = array_merge($chip, array(
                'note' => (string) ($context['note'] ?? ''),
                'pages' => (string) ($context['pages'] ?? ''),
            ));
        }
        $items[] = array(
            'key' => 'att:' . $id,
            'id' => $id,
            'title' => (string) $row['post_title'],
            'mime' => (string) $row['post_mime_type'],
            'url' => (string) wp_get_attachment_url($id),
            'date' => (string) $row['post_date'],
            'folders' => $folder_names[$id] ?? array(),
            'reviewed_at' => trim((string) $row['reviewed_at']),
            'context_status' => (string) get_post_meta($id, KOP_DOCUMENT_FACILITY_CONTEXT_STATUS_META, true),
            'associations' => $associations,
        );
    }

    $total = $attachment_total + $external_count;
    return rest_ensure_response(array(
        'results' => $items,
        'page' => $page,
        'per_page' => $per_page,
        'total' => $total,
        'pages' => max(1, (int) ceil($total / $per_page)),
        'coverage' => array(
            'total' => $library_total,
            'reviewed' => $library_reviewed,
            'unreviewed' => max(0, $library_total - $library_reviewed),
        ),
    ));
}

/** Save a reviewed attachment or publisher entry and all its relationship context. */
function kop_document_library_rest_save($request) {
    $key = sanitize_text_field((string) $request->get_param('key'));
    $associations = $request->get_param('associations');
    if (!is_array($associations)) $associations = array();

    if (strpos($key, 'att:') === 0) {
        $id = (int) substr($key, 4);
        if (get_post_type($id) !== 'attachment') {
            return new WP_Error('kop_document_missing', 'No such document.', array('status' => 404));
        }
        if (!current_user_can('edit_post', $id)) {
            return new WP_Error('kop_document_permission', 'You cannot edit that document.', array('status' => 403));
        }
        global $wpdb;
        $table = kop_document_library_relation_table();
        $filed = $table ? (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table rel WHERE rel.attachment_id = %d", $id)) : 0;
        if (!$filed) {
            return new WP_Error('kop_document_not_in_library', 'This attachment is no longer in a document folder.', array('status' => 404));
        }
        $saved = kop_document_save_facility_associations($id, $associations, true);
        if (is_wp_error($saved)) return $saved;
        $facilities = kop_research_facility_chips(kop_research_facility_ids('att:' . $id));
        $contexts = kop_document_facility_contexts($id);
        foreach ($facilities as &$facility) {
            $context = $contexts[$facility['id']] ?? array();
            $facility['note'] = $context['note'] ?? '';
            $facility['pages'] = $context['pages'] ?? '';
        }
        unset($facility);
        return rest_ensure_response(array(
            'ok' => true,
            'key' => $key,
            'reviewed_at' => (string) get_post_meta($id, KOP_DOCUMENT_FACILITY_REVIEWED_META, true),
            'context_status' => (string) get_post_meta($id, KOP_DOCUMENT_FACILITY_CONTEXT_STATUS_META, true),
            'associations' => $facilities,
        ));
    }

    if (strpos($key, 'ext:') === 0) {
        $id = substr($key, 4);
        $known = function_exists('kop_research_library_external') ? wp_list_pluck(kop_research_library_external(), 'id') : array();
        if (!in_array($id, $known, true)) {
            return new WP_Error('kop_document_missing', 'No such publisher entry.', array('status' => 404));
        }
        $valid_ids = function_exists('kop_research_valid_facility_ids')
            ? kop_research_valid_facility_ids(array_map(static function ($row) { return (int) ($row['facility_id'] ?? 0); }, $associations))
            : array();
        $valid = array_fill_keys(array_map('intval', $valid_ids), true);
        $ids = array();
        $contexts = array();
        foreach ($associations as $row) {
            if (!is_array($row)) continue;
            $facility_id = (int) ($row['facility_id'] ?? 0);
            if ($facility_id <= 0 || !isset($valid[$facility_id])) continue;
            $ids[] = $facility_id;
            $note = kop_document_clean_link_context($row['note'] ?? '', 500, true);
            $pages = kop_document_clean_link_context($row['pages'] ?? '', 120);
            if ($note !== '' || $pages !== '') $contexts[$facility_id] = array('note' => $note, 'pages' => $pages);
        }
        $edits = kop_research_external_edits();
        $entry = isset($edits[$id]) && is_array($edits[$id]) ? $edits[$id] : array();
        $entry['facilities'] = array_values(array_unique($ids));
        $entry['facility_contexts'] = $contexts;
        $entry['reviewed_at'] = current_time('mysql');
        $edits[$id] = $entry;
        update_option(KOP_RESEARCH_EXTERNAL_OPTION, $edits, false);
        $complete = (bool) $ids;
        foreach ($ids as $facility_id) {
            if (empty($contexts[$facility_id]['note']) || empty($contexts[$facility_id]['pages'])) {
                $complete = false;
                break;
            }
        }
        return rest_ensure_response(array(
            'ok' => true,
            'key' => $key,
            'reviewed_at' => $entry['reviewed_at'],
            'context_status' => !$ids ? 'none' : ($complete ? 'complete' : 'needs_context'),
        ));
    }

    return new WP_Error('kop_document_key', 'Unrecognised document key.', array('status' => 400));
}
