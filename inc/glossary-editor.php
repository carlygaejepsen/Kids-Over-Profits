<?php
/**
 * Glossary Editor: add, edit, move and delete glossary entries, and manage
 * the sections and groups they sit in (KOP Tools > Glossary Editor).
 *
 * The glossary is in SQL tables (inc/glossary-store.php); a save goes
 * straight to them and is live at once. Every save is checked first: a
 * **cross-reference** in any text must still name exactly one entry. Each
 * entry change is logged and can be undone from "Recent changes".
 *
 * The pencil on a glossary entry (inc/inline-edit.php, gl:<anchor>) saves
 * through kop_glossary_editor_handle_post(), so both behave the same.
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/glossary-store.php';

define('KOP_GLOSSARY_EDITOR_PAGE', 'kop-glossary-editor');

/* ---- Helpers ------------------------------------------------------------ */

/** One tag line per program: "Program" or "Program (note)". */
function kop_glossary_tag_lines($tags) {
    $lines = array();
    foreach ((array) $tags as $t) {
        $lines[] = $t['program'] . (!empty($t['note']) ? ' (' . $t['note'] . ')' : '');
    }
    return implode("\n", $lines);
}

function kop_glossary_lines($text) {
    $out = array();
    foreach (preg_split('/\r\n|\r|\n/', (string) $text) as $line) {
        $line = kop_glossary_trim($line);
        if ($line !== '') {
            $out[] = $line;
        }
    }
    return $out;
}

/** Every section and group: node id => its titles path, in page order. */
function kop_glossary_node_paths($nodes) {
    $out = array();
    $walk = function ($parent) use (&$walk, &$out, $nodes) {
        foreach ($nodes as $id => $n) {
            if ($n['parent_id'] === $parent) {
                $out[$id] = kop_glossary_store_node_path($nodes, $id);
                $walk($id);
            }
        }
    };
    $walk(0);
    return $out;
}

function kop_glossary_container_label($path) {
    return implode(' › ', (array) $path);
}

/** Entry row id for an anchor (#id), or 0. */
function kop_glossary_entry_id_for_anchor($state, $anchor) {
    foreach ($state['entries'] as $id => $e) {
        if ($e['anchor'] === (string) $anchor) {
            return $id;
        }
    }
    return 0;
}

/**
 * A snapshot from the form's fields.
 *
 * @return array('snap' => array|null, 'errors' => list)
 */
function kop_glossary_fields_to_snapshot($fields) {
    $errors = array();
    $term = kop_glossary_trim(preg_replace('/\s+/u', ' ', (string) $fields['term']));
    $note = kop_glossary_trim(preg_replace('/\s+/u', ' ', (string) $fields['note']));
    $text = kop_glossary_trim(preg_replace('/\s*\n\s*/u', ' ', str_replace("\r", '', (string) $fields['text'])));
    if ($term === '') {
        $errors[] = 'The term is required.';
    }
    if (strpos($term, '*') !== false || strpos($note, '*') !== false) {
        $errors[] = 'The term and the qualifier cannot contain asterisks.';
    }
    if (preg_match('/^aka /i', $note)) {
        $errors[] = 'Put other names under "Also called", not in the qualifier.';
    }
    if ($text === '') {
        $errors[] = 'The definition is required.';
    }
    if ((int) $fields['node_id'] <= 0) {
        $errors[] = 'Choose where the entry goes.';
    }
    if ($errors) {
        return array('snap' => null, 'errors' => $errors);
    }
    $aka = array();
    foreach (kop_glossary_lines($fields['aka_list']) as $a) {
        $aka[] = trim($a, " \"\u{201C}\u{201D}");
    }
    return array('snap' => array(
        'node_id'    => (int) $fields['node_id'],
        'anchor'     => '',
        'term'       => $term,
        'note'       => $note,
        'aka'        => array_values(array_filter($aka, 'strlen')),
        'definition' => $text,
        'used'       => array_map('kop_glossary_parse_tag', kop_glossary_lines($fields['used'])),
        'reported'   => array_map('kop_glossary_parse_tag', kop_glossary_lines($fields['reported'])),
    ), 'errors' => array());
}

/** Form fields for an entry snapshot (or an empty one). */
function kop_glossary_snapshot_fields($snap, $node_id = 0) {
    return array(
        'term'     => $snap ? $snap['term'] : '',
        'note'     => $snap ? $snap['note'] : '',
        'aka_list' => $snap ? implode("\n", $snap['aka']) : '',
        'text'     => $snap ? $snap['definition'] : '',
        'used'     => $snap ? kop_glossary_tag_lines($snap['used']) : '',
        'reported' => $snap ? kop_glossary_tag_lines($snap['reported']) : '',
        'node_id'  => $snap ? (int) $snap['node_id'] : (int) $node_id,
    );
}

function kop_glossary_entry_url($anchor) {
    return home_url('/' . KOP_GLOSSARY_SLUG . '/#' . $anchor);
}

/* ---- Admin menu ---------------------------------------------------------- */

function kop_register_glossary_editor_menu() {
    if (!function_exists('kop_tools_parent_slug')) {
        return;
    }
    add_submenu_page(
        kop_tools_parent_slug(),
        'Glossary Editor',
        'Glossary Editor',
        'manage_options',
        KOP_GLOSSARY_EDITOR_PAGE,
        'kop_render_glossary_editor_page'
    );
}
add_action('admin_menu', 'kop_register_glossary_editor_menu', 21);

function kop_glossary_editor_url($args = array()) {
    return add_query_arg($args, admin_url('admin.php?page=' . KOP_GLOSSARY_EDITOR_PAGE));
}

/* ---- Saving -------------------------------------------------------------- */

/** Mark a reader's feedback note as added to the glossary. */
function kop_glossary_editor_close_feedback($feedback_id) {
    if ($feedback_id > 0 && function_exists('kop_glossary_feedback_table')) {
        global $wpdb;
        $wpdb->update(kop_glossary_feedback_table(), array('status' => 'added'), array('id' => $feedback_id));
    }
}

/**
 * Handle a POST from the editor.
 *
 * @return array('notice' => string, 'errors' => list, 'view' => 'list'|'edit'|'sections', 'fields' => form values to redisplay)
 */
function kop_glossary_editor_handle_post() {
    check_admin_referer('kop_glossary_editor');
    $do = sanitize_key($_POST['kop_ge_do'] ?? '');
    $post = wp_unslash($_POST);
    if (!kop_glossary_store_ready()) {
        return array('errors' => array('The glossary tables are empty and js/data/glossary/glossary.md could not be imported.'), 'view' => 'list');
    }

    if ($do === 'undo') {
        $r = kop_glossary_store_undo((int) ($post['kop_ge_log'] ?? 0));
        return $r['errors']
            ? array('errors' => array_merge(array('That change cannot be undone:'), $r['errors']), 'view' => 'list')
            : array('notice' => 'Undone: "' . $r['term'] . '" is back as it was.', 'view' => 'list');
    }

    /* Sections and groups. */
    if (in_array($do, array('node_save', 'node_delete', 'node_up', 'node_down', 'meta_save'), true)) {
        $node_id = (int) ($post['kop_ge_node'] ?? 0);
        if ($do === 'meta_save') {
            $errors = kop_glossary_store_save_meta(array(
                'title' => (string) ($post['kop_ge_title'] ?? ''),
                'intro' => preg_split('/\n\s*\n/', str_replace("\r", '', (string) ($post['kop_ge_intro'] ?? ''))),
            ));
            return $errors ? array('errors' => $errors, 'view' => 'sections') : array('notice' => 'Title and introduction saved.', 'view' => 'sections');
        }
        if ($do === 'node_delete') {
            $errors = kop_glossary_store_delete_node($node_id);
            return $errors ? array('errors' => $errors, 'view' => 'sections') : array('notice' => 'Deleted.', 'view' => 'sections');
        }
        if ($do === 'node_up' || $do === 'node_down') {
            kop_glossary_store_move_node($node_id, $do === 'node_up' ? -1 : 1);
            return array('notice' => 'Moved.', 'view' => 'sections');
        }
        $r = kop_glossary_store_save_node($node_id, array(
            'parent_id' => (int) ($post['kop_ge_parent'] ?? 0),
            'title'     => (string) ($post['kop_ge_title'] ?? ''),
            'sources'   => (string) ($post['kop_ge_sources'] ?? ''),
            'notes'     => preg_split('/\n\s*\n/', str_replace("\r", '', (string) ($post['kop_ge_notes'] ?? ''))),
        ));
        return $r['errors']
            ? array('errors' => $r['errors'], 'view' => 'sections')
            : array('notice' => '"' . kop_glossary_trim((string) $post['kop_ge_title']) . '" saved.', 'view' => 'sections');
    }

    $state = kop_glossary_store_load();
    $anchor = (string) ($post['kop_ge_entry'] ?? '');
    $id = $anchor !== '' ? kop_glossary_entry_id_for_anchor($state, $anchor) : 0;
    if ($anchor !== '' && !$id) {
        return array('errors' => array('That entry is no longer in the glossary. It may have been renamed or deleted meanwhile.'), 'view' => 'list');
    }
    $feedback_id = (int) ($post['kop_ge_feedback'] ?? 0);
    $close_feedback = $feedback_id > 0 && !empty($post['kop_ge_close_feedback']);

    if ($do === 'delete') {
        if (!$id) {
            return array('errors' => array('Nothing to delete.'), 'view' => 'list');
        }
        $term = $state['entries'][$id]['term'];
        $errors = kop_glossary_store_delete_entry($id);
        if ($errors) {
            return array('errors' => array_merge(array('"' . $term . '" cannot be deleted while other text links to it. Edit these first:'), $errors), 'view' => 'list');
        }
        if ($close_feedback) {
            kop_glossary_editor_close_feedback($feedback_id);
        }
        return array('notice' => '"' . $term . '" deleted. Undo is under Recent changes.', 'view' => 'list');
    }

    if ($do !== 'save') {
        return array('errors' => array('Unknown action.'), 'view' => 'list');
    }

    $fields = array(
        'term'     => (string) ($post['kop_ge_term'] ?? ''),
        'note'     => (string) ($post['kop_ge_note'] ?? ''),
        'aka_list' => (string) ($post['kop_ge_aka'] ?? ''),
        'text'     => (string) ($post['kop_ge_text'] ?? ''),
        'used'     => (string) ($post['kop_ge_used'] ?? ''),
        'reported' => (string) ($post['kop_ge_reported'] ?? ''),
        'node_id'  => (int) ($post['kop_ge_node'] ?? 0),
    );
    $fail = function ($errors) use ($fields) {
        return array('errors' => $errors, 'view' => 'edit', 'fields' => $fields);
    };
    $made = kop_glossary_fields_to_snapshot($fields);
    if ($made['errors']) {
        return $fail($made['errors']);
    }
    $r = kop_glossary_store_save_entry($id, $made['snap']);
    if ($r['errors']) {
        return $fail($r['errors']);
    }
    if ($close_feedback) {
        kop_glossary_editor_close_feedback($feedback_id);
    }
    $term = $made['snap']['term'];
    if (!empty($r['unchanged'])) {
        return array('notice' => 'No changes to "' . $term . '".', 'view' => 'list');
    }
    $link = ' <a href="' . esc_url(kop_glossary_entry_url($r['anchor'])) . '" target="_blank" rel="noopener">View it on the glossary</a>.';
    return array('notice' => esc_html('"' . $term . '" saved and live.') . $link, 'notice_html' => true, 'view' => 'list', 'anchor' => $r['anchor']);
}

/* ---- Screens ------------------------------------------------------------- */

function kop_render_glossary_editor_page() {
    if (!current_user_can('manage_options')) {
        wp_die('Not authorized', 'Access Denied', array('response' => 403));
    }
    $result = array();
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['kop_ge_do'])) {
        $result = kop_glossary_editor_handle_post();
    }

    echo '<div class="wrap kop-ge">';
    kop_glossary_editor_styles();
    if (!kop_glossary_store_ready()) {
        echo '<h1>Glossary Editor</h1><div class="notice notice-error"><p>The glossary tables are empty and <code>js/data/glossary/glossary.md</code> could not be imported.</p></div></div>';
        return;
    }
    if (!empty($result['notice'])) {
        echo '<div class="notice notice-success is-dismissible"><p>' . (!empty($result['notice_html']) ? $result['notice'] : esc_html($result['notice'])) . '</p></div>';
    }
    if (!empty($result['errors'])) {
        echo '<div class="notice notice-error"><p><strong>Not saved.</strong></p><ul>';
        foreach ($result['errors'] as $e) {
            echo '<li>' . esc_html($e) . '</li>';
        }
        echo '</ul></div>';
    }

    $state = kop_glossary_store_load();
    $view = $result['view'] ?? sanitize_key($_GET['view'] ?? 'list');
    if ($view === 'edit' || $view === 'new') {
        kop_glossary_editor_form($state, $result['fields'] ?? null);
    } elseif ($view === 'sections') {
        kop_glossary_editor_sections($state);
    } else {
        kop_glossary_editor_list($state);
    }
    echo '</div>';
}

function kop_glossary_editor_styles() {
    ?>
    <style>
        .kop-ge .kop-ge-box { background: #fff; border: 1px solid #c3c4c7; padding: 12px 16px; margin: 16px 0; }
        .kop-ge .kop-ge-box h2 { margin-top: 4px; }
        .kop-ge .kop-ge-badge { display: inline-block; padding: 1px 7px; border-radius: 10px; font-size: 11px; background: #dcdcde; color: #1d2327; }
        .kop-ge .kop-ge-badge--add { background: #d1e7dd; }
        .kop-ge .kop-ge-badge--delete { background: #f8d7da; }
        .kop-ge .kop-ge-muted { color: #50575e; }
        .kop-ge .kop-ge-form th { width: 180px; }
        .kop-ge .kop-ge-form textarea, .kop-ge .kop-ge-form input[type=text], .kop-ge .kop-ge-form select { width: 100%; max-width: 820px; }
        .kop-ge .kop-ge-filter { margin: 12px 0; display: flex; gap: 8px; flex-wrap: wrap; align-items: center; }
        .kop-ge .kop-ge-add { display: flex; gap: 6px; margin-top: 6px; max-width: 820px; }
        .kop-ge .kop-ge-add input { flex: 1; }
        .kop-ge .kop-ge-node { border-left: 3px solid #c3c4c7; padding: 4px 0 4px 12px; margin: 10px 0; }
        .kop-ge .kop-ge-node details > summary { cursor: pointer; font-weight: 600; }
        .kop-ge .kop-ge-node form { display: inline; }
        .kop-ge .kop-ge-node .kop-ge-node { margin-left: 18px; }
        .kop-ge .kop-ge-node textarea, .kop-ge .kop-ge-node input[type=text] { width: 100%; max-width: 760px; }
    </style>
    <?php
}

function kop_glossary_editor_nav($current) {
    $tabs = array('list' => 'Entries', 'sections' => 'Sections and introduction');
    echo '<h1 class="wp-heading-inline">Glossary Editor</h1> ';
    echo '<a class="page-title-action" href="' . esc_url(kop_glossary_editor_url(array('view' => 'new'))) . '">Add a term</a>';
    echo '<hr class="wp-header-end"><nav class="nav-tab-wrapper">';
    foreach ($tabs as $key => $label) {
        echo '<a class="nav-tab' . ($key === $current ? ' nav-tab-active' : '') . '" href="' . esc_url(kop_glossary_editor_url($key === 'list' ? array() : array('view' => $key))) . '">' . esc_html($label) . '</a>';
    }
    echo '</nav>';
    echo '<p>Changes save straight to <a href="' . esc_url(home_url('/' . KOP_GLOSSARY_SLUG . '/')) . '" target="_blank" rel="noopener">the glossary</a>. '
        . 'Reader suggestions are under <a href="' . esc_url(admin_url('admin.php?page=kop-glossary-feedback')) . '">Glossary Feedback</a>.</p>';
}

function kop_glossary_editor_list($state) {
    kop_glossary_editor_nav('list');
    $paths = kop_glossary_node_paths($state['nodes']);

    /* Recent changes, each with Undo. */
    $recent = kop_glossary_store_recent(15);
    if ($recent) {
        $by_id = array();
        foreach ($state['entries'] as $id => $e) {
            $by_id[$id] = $e;
        }
        $kinds = array('add' => 'Added', 'edit' => 'Edited', 'delete' => 'Deleted');
        echo '<details class="kop-ge-box"' . (isset($_GET['changes']) ? ' open' : '') . '><summary><strong>Recent changes</strong></summary>';
        echo '<table class="widefat striped" style="margin-top:10px"><thead><tr><th>Entry</th><th style="width:90px">Change</th><th style="width:200px">By</th><th style="width:120px"></th></tr></thead><tbody>';
        foreach ($recent as $log) {
            $live = isset($by_id[(int) $log['entry_id']]) ? $by_id[(int) $log['entry_id']] : null;
            echo '<tr><td>';
            echo $live
                ? '<a href="' . esc_url(kop_glossary_editor_url(array('view' => 'edit', 'entry' => $live['anchor']))) . '"><strong>' . esc_html($log['term']) . '</strong></a>'
                : '<strong>' . esc_html($log['term']) . '</strong>';
            echo '</td><td><span class="kop-ge-badge kop-ge-badge--' . esc_attr($log['action']) . '">' . esc_html($kinds[$log['action']] ?? $log['action']) . '</span></td>';
            echo '<td>' . esc_html($log['user']) . '<br><span class="kop-ge-muted">' . esc_html($log['created_at']) . '</span></td><td>';
            if (!empty($log['undone_at'])) {
                echo '<span class="kop-ge-muted">Undone ' . esc_html($log['undone_at']) . '</span>';
            } else {
                echo '<form method="post" onsubmit="return confirm(\'Undo this change?\');">';
                wp_nonce_field('kop_glossary_editor');
                echo '<input type="hidden" name="kop_ge_do" value="undo"><input type="hidden" name="kop_ge_log" value="' . (int) $log['id'] . '">'
                    . '<button class="button button-small">Undo</button></form>';
            }
            echo '</td></tr>';
        }
        echo '</tbody></table></details>';
    }

    /* The entries. */
    $q = trim((string) wp_unslash($_GET['q'] ?? ''));
    $section = (int) ($_GET['section'] ?? 0);
    echo '<form class="kop-ge-filter" method="get"><input type="hidden" name="page" value="' . esc_attr(KOP_GLOSSARY_EDITOR_PAGE) . '">'
        . '<input type="search" name="q" value="' . esc_attr($q) . '" placeholder="Term, word or program" style="min-width:260px">'
        . '<select name="section"><option value="0">All sections</option>';
    foreach ($state['nodes'] as $id => $n) {
        if ($n['parent_id'] === 0) {
            echo '<option value="' . (int) $id . '"' . selected($section, $id, false) . '>' . esc_html($n['title']) . '</option>';
        }
    }
    echo '</select><button class="button">Filter</button>';
    if ($q !== '' || $section) {
        echo ' <a href="' . esc_url(kop_glossary_editor_url()) . '">Clear</a>';
    }
    echo '</form>';

    $words = $q === '' ? array() : explode(' ', kop_glossary_lower(preg_replace('/\s+/', ' ', $q)));
    $rows = array();
    foreach ($state['entries'] as $id => $e) {
        $path_ids = array();
        for ($n = $e['node_id']; isset($state['nodes'][$n]); $n = $state['nodes'][$n]['parent_id']) {
            $path_ids[] = $n;
        }
        if ($section && !in_array($section, $path_ids, true)) {
            continue;
        }
        $match = array('term' => $e['term'], 'note' => $e['note'], 'text' => $e['definition'], 'aka' => $e['aka'],
            'used' => kop_glossary_editor_slug_tags($e['used']), 'reported' => kop_glossary_editor_slug_tags($e['reported']));
        if (!kop_glossary_entry_matches($match, '', $words)) {
            continue;
        }
        $rows[] = $e;
    }
    usort($rows, function ($a, $b) {
        return strcmp(kop_glossary_sort_key($a['term']), kop_glossary_sort_key($b['term']));
    });
    echo '<p class="kop-ge-muted">' . count($rows) . ' of ' . count($state['entries']) . ' entries</p>';
    echo '<table class="widefat striped"><thead><tr><th>Term</th><th>Where</th><th style="width:90px">Programs</th><th style="width:170px">Last changed</th><th style="width:110px"></th></tr></thead><tbody>';
    foreach ($rows as $e) {
        $edit = kop_glossary_editor_url(array('view' => 'edit', 'entry' => $e['anchor']));
        echo '<tr><td><a href="' . esc_url($edit) . '"><strong>' . esc_html($e['term']) . '</strong></a>'
            . ($e['note'] !== '' ? ' <span class="kop-ge-muted">(' . esc_html($e['note']) . ')</span>' : '') . '</td>'
            . '<td>' . esc_html(kop_glossary_container_label($paths[$e['node_id']] ?? array())) . '</td>'
            . '<td>' . (int) (count($e['used']) + count($e['reported'])) . '</td>'
            . '<td>' . ($e['updated_by'] !== 'import' ? esc_html($e['updated_by']) . '<br>' : '') . '<span class="kop-ge-muted">' . esc_html($e['updated_at']) . '</span></td>'
            . '<td><a href="' . esc_url($edit) . '">Edit</a> | '
            . '<a href="' . esc_url(kop_glossary_entry_url($e['anchor'])) . '" target="_blank" rel="noopener">View</a></td></tr>';
    }
    echo '</tbody></table>';
}

/** Tags with the slug the page's filter reads. */
function kop_glossary_editor_slug_tags($tags) {
    foreach ($tags as $i => $t) {
        $tags[$i]['slug'] = kop_glossary_slugify($t['program']);
    }
    return $tags;
}

/** The add / edit form. $fields: values to show again after a failed save. */
function kop_glossary_editor_form($state, $fields) {
    /* A reader's note this edit answers. */
    $feedback_id = (int) ($_REQUEST['kop_ge_feedback'] ?? ($_GET['feedback'] ?? 0));
    $feedback = null;
    if ($feedback_id > 0 && function_exists('kop_glossary_feedback_table')) {
        global $wpdb;
        $feedback = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . kop_glossary_feedback_table() . ' WHERE id = %d', $feedback_id));
    }

    $anchor = (string) wp_unslash($_REQUEST['kop_ge_entry'] ?? ($_GET['entry'] ?? ''));
    $id = $anchor !== '' ? kop_glossary_entry_id_for_anchor($state, $anchor) : 0;
    /* A note's own record of its entry beats a stale id in the link. */
    if ($feedback && !$id && function_exists('kop_glossary_feedback_entry_id')) {
        $anchor = kop_glossary_feedback_entry_id($feedback);
        $id = $anchor !== '' ? kop_glossary_entry_id_for_anchor($state, $anchor) : 0;
    }
    if ($anchor !== '' && !$id) {
        echo '<h1>Glossary Editor</h1><div class="notice notice-error"><p>No entry "' . esc_html($anchor) . '". It may have been renamed.</p></div>';
        echo '<p><a href="' . esc_url(kop_glossary_editor_url()) . '">Back to the list</a></p>';
        return;
    }
    $entry = $id ? $state['entries'][$id] : null;
    $paths = kop_glossary_node_paths($state['nodes']);
    if ($fields === null) {
        $default_node = (int) ($_GET['in'] ?? 0);
        if (!$default_node) {
            foreach ($paths as $nid => $path) {
                if (count($path) === 1 && stripos($path[0], 'Shared Terms') === 0) {
                    $default_node = $nid;
                }
            }
        }
        $fields = kop_glossary_snapshot_fields($entry, $default_node);
    }

    echo '<h1>' . ($entry ? 'Edit "' . esc_html($entry['term']) . '"' : 'Add a term') . '</h1>';
    echo '<p><a href="' . esc_url(kop_glossary_editor_url()) . '">&larr; All entries</a>';
    if ($entry) {
        echo ' &middot; <a href="' . esc_url(kop_glossary_entry_url($entry['anchor'])) . '" target="_blank" rel="noopener">View on the glossary</a>';
    }
    echo '</p>';

    if ($feedback) {
        $kinds = function_exists('kop_glossary_feedback_kinds') ? kop_glossary_feedback_kinds() : array();
        echo '<div class="kop-ge-box"><h2>Reader note #' . (int) $feedback->id . ': ' . esc_html($kinds[$feedback->kind] ?? $feedback->kind) . '</h2>';
        if ($feedback->program) {
            echo '<p><em>Program:</em> <strong>' . esc_html($feedback->program) . '</strong></p>';
        }
        if ($feedback->details) {
            echo '<p>' . nl2br(esc_html($feedback->details)) . '</p>';
        }
        if ($feedback->source) {
            echo '<p><em>Source:</em> ' . esc_html($feedback->source) . '</p>';
        }
        echo '</div>';
    }

    $programs = array();
    foreach ($state['entries'] as $e) {
        foreach (array_merge($e['used'], $e['reported']) as $t) {
            $programs[$t['program']] = true;
        }
    }
    $programs = array_keys($programs);
    usort($programs, function ($a, $b) {
        return strcmp(kop_glossary_sort_key($a), kop_glossary_sort_key($b));
    });
    ?>
    <form method="post" class="kop-ge-form" action="<?php echo esc_url(kop_glossary_editor_url()); ?>">
        <?php wp_nonce_field('kop_glossary_editor'); ?>
        <input type="hidden" name="kop_ge_do" value="save">
        <input type="hidden" name="kop_ge_entry" value="<?php echo esc_attr($entry ? $entry['anchor'] : ''); ?>">
        <input type="hidden" name="kop_ge_feedback" value="<?php echo (int) $feedback_id; ?>">
        <table class="form-table" role="presentation">
            <tr>
                <th><label for="kop-ge-term">Term</label></th>
                <td><input type="text" id="kop-ge-term" name="kop_ge_term" value="<?php echo esc_attr($fields['term']); ?>" required></td>
            </tr>
            <tr>
                <th><label for="kop-ge-note">Qualifier</label></th>
                <td><input type="text" id="kop-ge-note" name="kop_ge_note" value="<?php echo esc_attr($fields['note']); ?>">
                    <p class="description">Shown in parentheses after the term, e.g. the full name of an abbreviation, or the program when two entries share a term.</p></td>
            </tr>
            <tr>
                <th><label for="kop-ge-aka">Also called</label></th>
                <td><textarea id="kop-ge-aka" name="kop_ge_aka" rows="3"><?php echo esc_textarea($fields['aka_list']); ?></textarea>
                    <p class="description">Other names for the term, one per line. Links written as **Other name** will find this entry.</p></td>
            </tr>
            <tr>
                <th><label for="kop-ge-text">Definition</label></th>
                <td><textarea id="kop-ge-text" name="kop_ge_text" rows="9" required><?php echo esc_textarea($fields['text']); ?></textarea>
                    <p class="description">One paragraph. <code>**Other Term**</code> links to another entry (it must name one, or the save is refused). <code>*words*</code> are italic. Program lists go below, not here.</p></td>
            </tr>
            <tr>
                <th><label for="kop-ge-used">Used at</label></th>
                <td><textarea id="kop-ge-used" name="kop_ge_used" rows="4"><?php echo esc_textarea($fields['used']); ?></textarea>
                    <div class="kop-ge-add"><input type="text" list="kop-ge-programs" placeholder="Add a program"><button type="button" class="button" data-add="kop-ge-used">Add</button></div>
                    <p class="description">Programs whose own documents use the term, one per line. A note on how that program used it goes in parentheses: <code>Spring Ridge Academy (also as "vicinity visit")</code>.</p></td>
            </tr>
            <tr>
                <th><label for="kop-ge-reported">Reportedly used at</label></th>
                <td><textarea id="kop-ge-reported" name="kop_ge_reported" rows="4"><?php echo esc_textarea($fields['reported']); ?></textarea>
                    <div class="kop-ge-add"><input type="text" list="kop-ge-programs" placeholder="Add a program"><button type="button" class="button" data-add="kop-ge-reported">Add</button></div>
                    <p class="description">Programs where survivor accounts report the term, one per line.</p></td>
            </tr>
            <tr>
                <th><label for="kop-ge-node">Section</label></th>
                <td><select id="kop-ge-node" name="kop_ge_node">
                    <?php foreach ($paths as $nid => $path) : ?>
                        <option value="<?php echo (int) $nid; ?>"<?php selected((int) $fields['node_id'], $nid); ?>><?php echo esc_html(str_repeat('— ', count($path) - 1) . end($path)); ?></option>
                    <?php endforeach; ?>
                </select>
                    <p class="description">Shared Terms A–Z for a term documented at more than one program; a program's own group for one documented at a single program. Entries sort alphabetically within it. New groups are made under <a href="<?php echo esc_url(kop_glossary_editor_url(array('view' => 'sections'))); ?>">Sections</a>.</p></td>
            </tr>
            <?php if ($feedback) : ?>
            <tr>
                <th>Reader note</th>
                <td><label><input type="checkbox" name="kop_ge_close_feedback" value="1" checked> Mark note #<?php echo (int) $feedback_id; ?> as added to the glossary</label></td>
            </tr>
            <?php endif; ?>
        </table>
        <datalist id="kop-ge-programs">
            <?php foreach ($programs as $name) : ?>
                <option value="<?php echo esc_attr($name); ?>"></option>
            <?php endforeach; ?>
        </datalist>
        <p class="submit"><button type="submit" class="button button-primary"><?php echo $entry ? 'Save changes' : 'Add term'; ?></button></p>
    </form>

    <?php if ($entry) : ?>
        <form method="post" action="<?php echo esc_url(kop_glossary_editor_url()); ?>" onsubmit="return confirm('Delete this entry from the glossary? Undo is under Recent changes.');">
            <?php wp_nonce_field('kop_glossary_editor'); ?>
            <input type="hidden" name="kop_ge_do" value="delete">
            <input type="hidden" name="kop_ge_entry" value="<?php echo esc_attr($entry['anchor']); ?>">
            <input type="hidden" name="kop_ge_feedback" value="<?php echo (int) $feedback_id; ?>">
            <button type="submit" class="button button-link-delete">Delete this entry</button>
        </form>
    <?php endif; ?>
    <script>
    document.querySelectorAll('.kop-ge [data-add]').forEach(function (button) {
        var area = document.getElementById(button.getAttribute('data-add'));
        var input = button.previousElementSibling;
        function add() {
            var name = input.value.trim();
            if (!name) { return; }
            area.value = area.value.replace(/\s+$/, '') + (area.value.trim() ? '\n' : '') + name;
            input.value = '';
            input.focus();
        }
        button.addEventListener('click', add);
        input.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') { e.preventDefault(); add(); }
        });
    });
    </script>
    <?php
}

/** One small POST button for a node action. */
function kop_glossary_editor_node_button($do, $node_id, $label, $confirm = '') {
    echo '<form method="post"' . ($confirm !== '' ? ' onsubmit="return confirm(' . esc_attr(wp_json_encode($confirm)) . ');"' : '') . '>';
    wp_nonce_field('kop_glossary_editor');
    echo '<input type="hidden" name="kop_ge_do" value="' . esc_attr($do) . '"><input type="hidden" name="kop_ge_node" value="' . (int) $node_id . '">'
        . '<button class="button button-small">' . esc_html($label) . '</button></form> ';
}

/** Sections, groups and the introduction. */
function kop_glossary_editor_sections($state) {
    kop_glossary_editor_nav('sections');
    $meta = kop_glossary_store_meta();
    $counts = array();
    foreach ($state['entries'] as $e) {
        $counts[$e['node_id']] = ($counts[$e['node_id']] ?? 0) + 1;
    }
    ?>
    <div class="kop-ge-box">
        <h2>Title and introduction</h2>
        <form method="post">
            <?php wp_nonce_field('kop_glossary_editor'); ?>
            <input type="hidden" name="kop_ge_do" value="meta_save">
            <p><label>Title<br><input type="text" name="kop_ge_title" value="<?php echo esc_attr($meta['title']); ?>" style="width:100%;max-width:760px"></label></p>
            <p><label>Introduction<br><textarea name="kop_ge_intro" rows="8" style="width:100%;max-width:760px"><?php echo esc_textarea(implode("\n\n", $meta['intro'])); ?></textarea></label></p>
            <p class="description">A blank line between paragraphs. <code>**Section name**</code> links to that section.</p>
            <p><button class="button button-primary">Save</button></p>
        </form>
    </div>
    <h2>Sections and groups</h2>
    <p class="kop-ge-muted">Entries sort alphabetically inside each one; sections and groups keep the order set here. Only an empty one can be deleted.</p>
    <?php
    $render = function ($parent, $depth) use (&$render, $state, $counts) {
        foreach ($state['nodes'] as $id => $n) {
            if ($n['parent_id'] !== $parent) {
                continue;
            }
            $has_children = false;
            foreach ($state['nodes'] as $c) {
                if ($c['parent_id'] === $id) {
                    $has_children = true;
                }
            }
            echo '<div class="kop-ge-node"><details><summary>' . esc_html($n['title'])
                . ' <span class="kop-ge-muted">(' . (int) ($counts[$id] ?? 0) . ' entries, #' . esc_html(($parent ? 'g-' : '') . $n['anchor']) . ')</span></summary>';
            echo '<form method="post" style="display:block;margin:8px 0">';
            wp_nonce_field('kop_glossary_editor');
            echo '<input type="hidden" name="kop_ge_do" value="node_save"><input type="hidden" name="kop_ge_node" value="' . (int) $id . '">'
                . '<p><label>Title<br><input type="text" name="kop_ge_title" value="' . esc_attr($n['title']) . '"></label></p>';
            if ($parent) {
                echo '<p><label>Sources<br><input type="text" name="kop_ge_sources" value="' . esc_attr($n['sources']) . '"></label></p>';
            }
            echo '<p><label>Notes (shown above the entries; a blank line between paragraphs)<br><textarea name="kop_ge_notes" rows="4">' . esc_textarea(implode("\n\n", $n['notes'])) . '</textarea></label></p>'
                . '<p><button class="button button-primary">Save</button></p></form>';
            kop_glossary_editor_node_button('node_up', $id, 'Move up');
            kop_glossary_editor_node_button('node_down', $id, 'Move down');
            if (empty($counts[$id]) && !$has_children) {
                kop_glossary_editor_node_button('node_delete', $id, 'Delete', 'Delete "' . $n['title'] . '"?');
            }
            echo ' <a href="' . esc_url(kop_glossary_editor_url(array('view' => 'new', 'in' => $id))) . '">Add a term here</a>';
            if ($depth < 3) {
                echo '<form method="post" style="display:block;margin:8px 0">';
                wp_nonce_field('kop_glossary_editor');
                echo '<input type="hidden" name="kop_ge_do" value="node_save"><input type="hidden" name="kop_ge_parent" value="' . (int) $id . '">'
                    . '<input type="text" name="kop_ge_title" placeholder="New group under ' . esc_attr($n['title']) . '" style="max-width:360px"> '
                    . '<button class="button">Add group</button></form>';
            }
            echo '</details>';
            $render($id, $depth + 1);
            echo '</div>';
        }
    };
    $render(0, 1);
    ?>
    <form method="post" class="kop-ge-box">
        <?php wp_nonce_field('kop_glossary_editor'); ?>
        <input type="hidden" name="kop_ge_do" value="node_save">
        <input type="hidden" name="kop_ge_parent" value="0">
        <label>New section <input type="text" name="kop_ge_title" style="min-width:300px"></label>
        <button class="button">Add section</button>
    </form>
    <?php
}
