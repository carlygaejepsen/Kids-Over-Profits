<?php
/**
 * KOP Tools > Document Types: what kind each document in the media library
 * is (inc/doc-types.php), from one screen.
 *
 *   Counts          every type with its number of documents (each links to
 *                   its /documents/<type>/ page), how many have none yet,
 *                   and how far the AI has got with those
 *   Run now         type by the folder and title rules; ask the AI about ten
 *                   more (both also run by themselves every hour)
 *   Tag many        find documents by title, file name or folder, tick them,
 *                   add a type, replace their types or take them off
 *   Recent changes  every change (rule runs, AI approvals, the pencil, this
 *                   screen), each with Undo
 *
 * The AI's guesses are approved in Review inbox > Document types. Only plain
 * text is posted, so the host firewall lets it through.
 */

if (!defined('ABSPATH')) {
    exit;
}

define('KOP_DT_ADMIN_PAGE', 'kop-document-types');

add_action('admin_menu', function () {
    if (!function_exists('kop_tools_parent_slug')) {
        return;
    }
    add_submenu_page(kop_tools_parent_slug(), 'Document Types', 'Document Types', 'manage_options',
        KOP_DT_ADMIN_PAGE, 'kop_render_dt_admin');
}, 21);

function kop_dt_admin_url($args = array()) {
    return add_query_arg($args, admin_url('admin.php?page=' . KOP_DT_ADMIN_PAGE));
}

function kop_dt_inbox_url() {
    $url = function_exists('kop_find_template_page_url') ? (string) kop_find_template_page_url('page-admin-submissions.php') : '';
    return add_query_arg('type', 'doc-types', $url !== '' ? $url : home_url('/admin-submissions/'));
}

/**
 * Documents whose title, file name or folder holds $q (all of them when $q
 * is blank), optionally only those without a type or with type $type.
 * [{id, title, file, path, types}], at most $limit, newest first.
 */
function kop_dt_admin_search($q, $only_untyped, $type, $limit = 200) {
    global $wpdb;
    $p = $wpdb->posts;
    $t = kop_dt_table();
    $where = array("p.post_type = 'attachment'", kop_dt_doc_mimes_sql());
    $args = array();
    $q = trim((string) $q);
    if ($q !== '') {
        $like = '%' . $wpdb->esc_like($q) . '%';
        $where[] = "(p.post_title LIKE %s OR EXISTS (SELECT 1 FROM {$wpdb->postmeta} m WHERE m.post_id = p.ID AND m.meta_key = '_wp_attached_file' AND m.meta_value LIKE %s)
            OR EXISTS (SELECT 1 FROM {$wpdb->prefix}fbv_attachment_folder af JOIN {$wpdb->prefix}fbv f ON f.id = af.folder_id WHERE af.attachment_id = p.ID AND f.name LIKE %s))";
        array_push($args, $like, $like, $like);
    }
    if ($only_untyped) $where[] = "NOT EXISTS (SELECT 1 FROM $t d WHERE d.attachment_id = p.ID)";
    if ($type !== '' && isset(kop_dt_types()[$type])) {
        $where[] = "EXISTS (SELECT 1 FROM $t d WHERE d.attachment_id = p.ID AND d.type = %s)";
        $args[] = $type;
    }
    $sql = "SELECT p.ID FROM $p p WHERE " . implode(' AND ', $where) . ' ORDER BY p.ID DESC LIMIT ' . (int) $limit;
    $ids = array_map('intval', $wpdb->get_col($args ? $wpdb->prepare($sql, $args) : $sql));
    $docs = kop_dt_load_docs($ids);
    $types = kop_dt_types_of($ids);
    $out = array();
    foreach ($ids as $id) {
        if (!isset($docs[$id])) continue;
        $out[] = array('id' => $id, 'title' => $docs[$id]['title'], 'file' => $docs[$id]['file'],
            'path' => kop_dt_folder_path($docs[$id]), 'types' => $types[$id] ?? array());
    }
    return $out;
}

/** [notice, error] after a posted action. */
function kop_dt_admin_handle() {
    check_admin_referer('kop_dt');
    $do = sanitize_key(wp_unslash($_POST['kop_dt_do'] ?? ''));
    try {
        switch ($do) {
            case 'rules':
                $r = kop_dt_run_rules(true);
                if (!$r['typed'] && !$r['left']) return array('Nothing new for the rules: every document has a type or has been looked at.', '');
                $parts = array();
                foreach (array_slice($r['by_type'], 0, 6, true) as $slug => $n) $parts[] = $n . ' ' . kop_dt_label($slug);
                return array('The rules typed ' . $r['typed'] . ' document' . ($r['typed'] === 1 ? '' : 's') . ($parts ? ' (' . implode(', ', $parts) . (count($r['by_type']) > 6 ? ', ...' : '') . ')' : '')
                    . '. ' . $r['left'] . ' they could not type wait for the AI. Undo is under Recent changes.', '');
            case 'ai':
                $r = kop_dt_ai_batch(10);
                if (!$r['asked'] && !$r['errors']) return array('No document is waiting for the AI.', '');
                return array('The AI looked at ' . $r['asked'] . ': ' . $r['pending'] . ' guesses wait in the Review inbox, ' . $r['unclear'] . ' it could not tell. '
                    . $r['left'] . ' still waiting.', $r['errors'] ? 'The AI failed on ' . $r['errors'] . ': ' . $r['error'] : '');
            case 'bulk':
                $ids = array_values(array_filter(array_map('intval', (array) ($_POST['kop_dt_ids'] ?? array()))));
                $how = sanitize_key(wp_unslash($_POST['kop_dt_how'] ?? ''));
                $type = sanitize_key(wp_unslash($_POST['kop_dt_type'] ?? ''));
                if (!$ids) return array('', 'Tick at least one document.');
                if ($how !== 'clear' && !isset(kop_dt_types()[$type])) return array('', 'Pick a type.');
                $now = kop_dt_types_of($ids);
                $changes = array();
                foreach ($ids as $id) {
                    $was = $now[$id] ?? array();
                    if ($how === 'add') $changes[$id] = array_merge($was, array($type));
                    elseif ($how === 'remove') $changes[$id] = array_diff($was, array($type));
                    elseif ($how === 'replace') $changes[$id] = array($type);
                    elseif ($how === 'clear') $changes[$id] = array();
                }
                $words = array('add' => 'Added "%s" to', 'remove' => 'Took "%s" off', 'replace' => 'Set "%s" as the only type of', 'clear' => 'Took every type off');
                if (!isset($words[$how])) return array('', 'Pick what to do.');
                $label = sprintf($words[$how], kop_dt_label($type)) . ' ' . count($ids) . ' document' . (count($ids) === 1 ? '' : 's');
                $e = kop_dt_change($changes, 'person', $label);
                if (!$e) return array('No change: they already were that way.', '');
                // Waiting AI guesses on these are settled by the person's choice.
                foreach ($e['changes'] as $c) {
                    $s = kop_dt_sugg_get($c['id']);
                    if ($s && in_array($s['status'], array('pending', 'unclear', 'norule'), true) && $c['after']) kop_dt_sugg_status($c['id'], 'approved', kop_dt_who());
                }
                return array($label . ' (' . count($e['changes']) . ' changed). Undo is under Recent changes.', '');
            case 'undo':
                $r = kop_dt_undo((string) wp_unslash($_POST['kop_dt_entry'] ?? ''));
                return array('Undone: ' . $r['restored'] . ' document' . ($r['restored'] === 1 ? '' : 's') . ' back the way they were'
                    . ($r['skipped'] ? '; ' . $r['skipped'] . ' changed again since were left as they are now' : '') . '.', '');
        }
    } catch (Throwable $e) {
        return array('', $e->getMessage());
    }
    return array('', 'Unknown action.');
}

function kop_dt_admin_form_open($do, $extra = '') {
    echo '<form method="post" action="' . esc_url(kop_dt_admin_url(array_intersect_key(wp_unslash($_GET), array_flip(array('q', 'untyped', 'has'))))) . '"' . $extra . '>';
    wp_nonce_field('kop_dt');
    echo '<input type="hidden" name="kop_dt_do" value="' . esc_attr($do) . '">';
}

function kop_dt_type_select($name, $selected = '', $blank = 'Pick a type') {
    $html = '<select name="' . esc_attr($name) . '"><option value="">' . esc_html($blank) . '</option>';
    $group = null;
    foreach (kop_dt_types() as $slug => $t) {
        if ($t['group'] !== $group) {
            if ($group !== null) $html .= '</optgroup>';
            $group = $t['group'];
            $html .= '<optgroup label="' . esc_attr($group) . '">';
        }
        $html .= '<option value="' . esc_attr($slug) . '"' . ($slug === $selected ? ' selected' : '') . '>' . esc_html($t['label']) . '</option>';
    }
    return $html . '</optgroup></select>';
}

function kop_render_dt_admin() {
    if (!current_user_can('manage_options')) {
        wp_die('Not authorized', 'Access Denied', array('response' => 403));
    }
    kop_dt_install();
    echo '<div class="wrap kop-dt-admin">';
    kop_dt_admin_styles();
    echo '<h1>Document Types</h1>';
    $notice = $error = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['kop_dt_do'])) {
        list($notice, $error) = kop_dt_admin_handle();
    }
    if ($notice !== '') echo '<div class="notice notice-success is-dismissible"><p>' . esc_html($notice) . '</p></div>';
    if ($error !== '') echo '<div class="notice notice-error"><p><strong>Not done.</strong> ' . esc_html($error) . '</p></div>';

    global $wpdb;
    $counts = kop_dt_counts();
    $s = kop_dt_sugg_table();
    $waits = array('norule' => 0, 'pending' => 0, 'unclear' => 0);
    foreach ((array) $wpdb->get_results("SELECT x.status, COUNT(*) AS n FROM $s x WHERE NOT EXISTS (SELECT 1 FROM " . kop_dt_table() . ' d WHERE d.attachment_id = x.attachment_id) GROUP BY x.status', ARRAY_A) as $r) {
        if (isset($waits[$r['status']])) $waits[$r['status']] = (int) $r['n'];
    }

    echo '<p class="kop-dt-muted">Every document (PDFs and Word files) gets a type, so all of one kind can be read side by side on the public '
        . '<a href="' . esc_url(kop_dt_url()) . '" target="_blank" rel="noopener">Documents by type</a> pages, and each document tile shows its type. '
        . 'Folder names and plain titles type most documents by themselves; the AI reads the rest and its guesses wait for you in the '
        . '<a href="' . esc_url(kop_dt_inbox_url()) . '">Review inbox (Document types)</a>. You can also set a type with the pencil on any document tile.</p>';

    // ---- Counts ---------------------------------------------------------------
    echo '<h2>How many of each</h2><div class="kop-dt-counts">';
    $group = null;
    foreach (kop_dt_types() as $slug => $t) {
        if ($t['group'] !== $group) {
            if ($group !== null) echo '</ul>';
            $group = $t['group'];
            echo '<h3>' . esc_html($group) . '</h3><ul>';
        }
        echo '<li><a href="' . esc_url(kop_dt_admin_url(array('has' => $slug))) . '">' . esc_html($t['label']) . '</a> '
            . '<span class="kop-dt-n">' . number_format_i18n($counts[$slug] ?? 0) . '</span> '
            . '<a class="kop-dt-public" href="' . esc_url(kop_dt_url($slug)) . '" target="_blank" rel="noopener">page</a></li>';
    }
    echo '</ul></div>';
    echo '<p><strong>' . number_format_i18n($counts['untyped']) . '</strong> documents have no type yet: '
        . number_format_i18n($waits['norule']) . ' wait for the AI, '
        . '<a href="' . esc_url(kop_dt_inbox_url()) . '">' . number_format_i18n($waits['pending']) . ' AI guesses wait for you</a>, '
        . number_format_i18n($waits['unclear']) . ' the AI could not tell. '
        . '<a href="' . esc_url(kop_dt_admin_url(array('untyped' => 1))) . '">List them</a>.</p>';

    echo '<div class="kop-dt-run">';
    kop_dt_admin_form_open('rules', ' style="display:inline"');
    echo '<button type="submit" class="button">Type by folder and title rules now</button></form> ';
    kop_dt_admin_form_open('ai', ' style="display:inline"');
    echo '<button type="submit" class="button">Ask the AI about 10 more now</button></form>';
    echo '<p class="kop-dt-muted">Both also run by themselves every hour (the AI about 12 documents an hour). A rule run is one entry under Recent changes, so it can be undone in one click.</p></div>';

    // ---- Tag many -----------------------------------------------------------
    $q = isset($_GET['q']) ? trim((string) wp_unslash($_GET['q'])) : '';
    $untyped = !empty($_GET['untyped']);
    $has = isset($_GET['has']) ? sanitize_key(wp_unslash($_GET['has'])) : '';
    echo '<h2>Find documents and tag many at once</h2>';
    echo '<form method="get" class="kop-dt-search"><input type="hidden" name="page" value="' . esc_attr(KOP_DT_ADMIN_PAGE) . '">'
        . '<label class="screen-reader-text" for="kop-dt-q">Title, file name or folder</label>'
        . '<input type="search" id="kop-dt-q" name="q" value="' . esc_attr($q) . '" placeholder="Title, file name or folder (Enrollment, Handbook, Lawsuits...)" class="regular-text"> '
        . '<label>' . kop_dt_type_select('has', $has, 'Any type') . '</label> '
        . '<label><input type="checkbox" name="untyped" value="1"' . ($untyped ? ' checked' : '') . '> Only documents with no type</label> '
        . '<button type="submit" class="button">Find</button></form>';
    if ($q !== '' || $untyped || $has !== '') {
        $hits = kop_dt_admin_search($q, $untyped, $has);
        if (!$hits) {
            echo '<p class="kop-dt-muted">No document matches.</p>';
        } else {
            kop_dt_admin_form_open('bulk');
            echo '<p class="kop-dt-muted">' . count($hits) . (count($hits) === 200 ? ' (the first 200; narrow the search for more)' : '') . ' documents, newest first.</p>';
            echo '<table class="widefat striped kop-dt-hits"><thead><tr><th class="check-column"><input type="checkbox" id="kop-dt-all" aria-label="Tick all"></th><th>Document</th><th>Filed in</th><th>Type</th></tr></thead><tbody>';
            foreach ($hits as $h) {
                $url = wp_get_attachment_url($h['id']);
                echo '<tr><th class="check-column"><input type="checkbox" name="kop_dt_ids[]" value="' . (int) $h['id'] . '" aria-label="Tick ' . esc_attr($h['title']) . '"></th><td>'
                    . ($url ? '<a href="' . esc_url($url) . '" target="_blank" rel="noopener">' . esc_html($h['title']) . '</a>' : esc_html($h['title']))
                    . '<br><span class="kop-dt-muted">' . esc_html($h['file']) . '</span></td><td>' . esc_html($h['path'] !== '' ? $h['path'] : '(no folder)') . '</td><td>'
                    . esc_html($h['types'] ? implode(', ', array_map('kop_dt_label', $h['types'])) : '(none)') . '</td></tr>';
            }
            echo '</tbody></table>';
            echo '<p class="kop-dt-bulk"><label>For the ticked documents: <select name="kop_dt_how">'
                . '<option value="add">Add this type</option><option value="replace">Make this their only type</option>'
                . '<option value="remove">Take this type off</option><option value="clear">Take every type off</option></select></label> '
                . kop_dt_type_select('kop_dt_type') . ' <button type="submit" class="button button-primary">Apply</button></p></form>';
            echo '<script>document.getElementById("kop-dt-all").addEventListener("change",function(e){document.querySelectorAll(".kop-dt-hits tbody input[type=checkbox]").forEach(function(b){b.checked=e.target.checked;});});</script>';
        }
    }

    // ---- Recent changes -----------------------------------------------------
    $log = kop_dt_log();
    echo '<h2>Recent changes</h2>';
    if (!$log) {
        echo '<p class="kop-dt-muted">None yet.</p>';
    } else {
        echo '<table class="widefat striped kop-dt-log"><thead><tr><th>When</th><th>Who</th><th>What</th><th></th></tr></thead><tbody>';
        foreach (array_slice($log, 0, 40) as $e) {
            $n = count($e['changes']);
            $first = array_slice($e['changes'], 0, 3);
            $what = $e['label'] . ': ' . implode('; ', array_map(function ($c) {
                return '"' . $c['title'] . '" ' . ($c['before'] ? implode(', ', array_map('kop_dt_label', $c['before'])) : 'no type') . ' → ' . ($c['after'] ? implode(', ', array_map('kop_dt_label', $c['after'])) : 'no type');
            }, $first)) . ($n > 3 ? ' and ' . ($n - 3) . ' more' : '');
            echo '<tr><td>' . esc_html(get_date_from_gmt($e['at'], 'M j, Y g:i a')) . '</td><td>' . esc_html($e['who']) . '</td><td>' . esc_html($what) . '</td><td>';
            if (!empty($e['undone'])) {
                echo '<span class="kop-dt-muted">Undone' . (!empty($e['undone_by']) ? ' by ' . esc_html($e['undone_by']) : '') . '</span>';
            } else {
                kop_dt_admin_form_open('undo');
                echo '<input type="hidden" name="kop_dt_entry" value="' . esc_attr($e['id']) . '"><button type="submit" class="button button-small">Undo' . ($n > 1 ? ' all ' . $n : '') . '</button></form>';
            }
            echo '</td></tr>';
        }
        echo '</tbody></table>';
    }
    echo '</div>';
}

function kop_dt_admin_styles() {
    echo '<style>
        .kop-dt-admin .kop-dt-muted { color: #4A5568; max-width: 75em; }
        .kop-dt-admin h2 { margin-top: 1.6em; }
        .kop-dt-admin .kop-dt-counts { display: grid; grid-template-columns: repeat(auto-fill, minmax(min(100%, 260px), 1fr)); gap: 4px 24px; max-width: 1100px; }
        .kop-dt-admin .kop-dt-counts h3 { grid-column: 1 / -1; margin: 1em 0 0; font-size: 13px; text-transform: uppercase; letter-spacing: .04em; color: #4A5568; }
        .kop-dt-admin .kop-dt-counts ul { display: contents; }
        .kop-dt-admin .kop-dt-counts li { margin: 0; display: flex; gap: 8px; align-items: baseline; }
        .kop-dt-admin .kop-dt-n { font-weight: 600; }
        .kop-dt-admin .kop-dt-public { font-size: 12px; }
        .kop-dt-admin .kop-dt-run { margin: 1em 0; }
        .kop-dt-admin .kop-dt-search { margin: 0.5em 0 1em; display: flex; flex-wrap: wrap; gap: 8px; align-items: center; }
        .kop-dt-admin .kop-dt-hits, .kop-dt-admin .kop-dt-log { max-width: 1200px; }
        .kop-dt-admin .kop-dt-hits td { overflow-wrap: anywhere; }
        .kop-dt-admin .kop-dt-bulk { position: sticky; bottom: 0; background: #f0f0f1; padding: 10px 0; display: flex; flex-wrap: wrap; gap: 8px; align-items: center; }
    </style>';
}
