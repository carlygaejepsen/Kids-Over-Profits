<?php
/**
 * Glossary Editor: add, edit, move and delete glossary entries from wp-admin
 * (KOP Data Tools > Glossary Editor).
 *
 * js/data/glossary/glossary.md in git stays the source of truth, and the
 * server has no Node to rebuild glossary.json. So an edit made here is kept
 * as a small overlay in the kop_glossary_edits option: one change per entry,
 * each naming the paragraph of glossary.md it replaces. The site applies the
 * overlay to the deployed glossary.md, rebuilds with the PHP port of the
 * build (inc/glossary-build.php, same cross-reference checks), and caches the
 * result, so a saved edit is live at once.
 *
 * To make the edits permanent, download the merged glossary.md from the
 * editor, put it in the repo, run node scripts/build-glossary.js and commit.
 * Once that deploys, each change finds its own text already in glossary.md
 * and clears itself. A change whose paragraph was edited in the repo
 * meanwhile is not applied; the editor lists it as out of date.
 *
 * A change: array(
 *   'id'        => unique id,
 *   'target'    => the glossary.md paragraph it replaces (null: a new entry),
 *   'markdown'  => the new paragraph (null: delete the entry),
 *   'container' => section/group titles to move it into (null: stay put),
 *   'term', 'user', 'time', 'date' => for the list of pending changes,
 * )
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/glossary-build.php';

define('KOP_GLOSSARY_EDITOR_PAGE', 'kop-glossary-editor');

function kop_glossary_source_path() {
    return get_stylesheet_directory() . '/js/data/glossary/glossary.md';
}

/** The deployed glossary.md, or null. */
function kop_glossary_repo_markdown() {
    $path = kop_glossary_source_path();
    return is_readable($path) ? (string) file_get_contents($path) : null;
}

function kop_glossary_edits() {
    $edits = get_option('kop_glossary_edits');
    return is_array($edits) && isset($edits['ops']) && is_array($edits['ops']) ? $edits['ops'] : array();
}

function kop_glossary_save_edits($ops) {
    update_option('kop_glossary_edits', array('ops' => array_values($ops)), false);
}

/**
 * Index just past the last paragraph of a container (the paragraph before
 * the next heading of any level), or -1 when the container is not there.
 */
function kop_glossary_container_end($paras, $container) {
    $path = array();
    $found = -1;
    foreach ($paras as $i => $para) {
        $level = kop_glossary_heading_level($para);
        if ($level < 2) {
            continue;
        }
        if ($found >= 0) {
            return $i;
        }
        $title = kop_glossary_trim(substr($para, $level + 1));
        $path = array_slice($path, 0, $level - 2);
        $path[] = $title;
        if ($path === array_values($container)) {
            $found = $i;
        }
    }
    return $found >= 0 ? count($paras) : -1;
}

/** Term and qualifier of an entry paragraph, to tell entries apart. */
function kop_glossary_entry_key($para) {
    $e = kop_glossary_parse_entry((string) $para);
    return $e ? kop_glossary_lower($e['term'] . '|' . $e['note']) : '';
}

/**
 * Apply the changes to glossary.md.
 *
 * @return array('markdown' => string, 'status' => op id => 'applied' | 'stale' | 'in_repo')
 */
function kop_glossary_apply_edits($markdown, $ops) {
    $paras = kop_glossary_paragraphs($markdown);
    $repo = $paras;
    $status = array();
    $latest = '';

    foreach ($ops as $op) {
        $target = isset($op['target']) ? $op['target'] : null;
        $new = isset($op['markdown']) ? $op['markdown'] : null;
        $container = !empty($op['container']) ? $op['container'] : null;
        $at = $target !== null ? array_search($target, $paras, true) : false;

        /* Already in glossary.md: the repo caught up with this change. */
        if ($at === false) {
            if ($new !== null && in_array($new, $repo, true)) {
                $status[$op['id']] = 'in_repo';
                continue;
            }
            if ($new === null) {
                $key = kop_glossary_entry_key($target);
                $still = false;
                foreach ($repo as $p) {
                    if ($key !== '' && kop_glossary_entry_key($p) === $key) {
                        $still = true;
                        break;
                    }
                }
                $status[$op['id']] = $still ? 'stale' : 'in_repo';
                continue;
            }
            if ($target !== null) {
                $status[$op['id']] = 'stale';
                continue;
            }
        }
        if (($target === null || $container !== null) && ($container === null || kop_glossary_container_end($paras, $container) < 0)) {
            $status[$op['id']] = 'stale';
            continue;
        }

        if ($at !== false) {
            array_splice($paras, $at, 1);
        }
        if ($new !== null) {
            $insert = $container !== null ? kop_glossary_container_end($paras, $container) : $at;
            array_splice($paras, $insert, 0, array($new));
        }
        $status[$op['id']] = 'applied';
        if (!empty($op['date']) && $op['date'] > $latest) {
            $latest = $op['date'];
        }
    }

    /* The page's "Updated" date follows the newest applied change. */
    if ($latest !== '') {
        foreach ($paras as $i => $para) {
            if (preg_match('/^updated: (\d{4}-\d{2}-\d{2})$/D', $para, $m)) {
                if ($latest > $m[1]) {
                    $paras[$i] = 'updated: ' . $latest;
                }
                break;
            }
        }
    }
    return array('markdown' => implode("\n\n", $paras) . "\n", 'status' => $status);
}

/**
 * The glossary with the saved changes applied, for kop_glossary_data(), or
 * null when there are none (or they no longer build), so the page reads the
 * committed glossary.json as before.
 */
function kop_glossary_live_data() {
    $ops = kop_glossary_edits();
    if (!$ops) {
        return null;
    }
    $path = kop_glossary_source_path();
    if (!is_readable($path)) {
        return null;
    }
    $key = md5(filemtime($path) . '|' . filesize($path) . '|' . serialize($ops));
    $cache = get_option('kop_glossary_live');
    if (is_array($cache) && isset($cache['key']) && $cache['key'] === $key) {
        return $cache['data'];
    }
    $applied = kop_glossary_apply_edits(kop_glossary_repo_markdown(), $ops);
    $built = kop_glossary_build($applied['markdown']);
    update_option('kop_glossary_live', array('key' => $key, 'data' => $built['data'], 'errors' => $built['errors']), false);
    return $built['data'];
}

/** Let cached copies of /glossary/ go after a change. */
function kop_glossary_purge_page_cache() {
    delete_option('kop_glossary_live');
    $page = get_page_by_path(KOP_GLOSSARY_SLUG);
    if ($page) {
        clean_post_cache($page->ID);
        do_action('litespeed_purge_post', $page->ID);
    }
    do_action('litespeed_purge_url', home_url('/' . KOP_GLOSSARY_SLUG . '/'));
}

/* ---- Entry form <-> markdown ----------------------------------------- */

/** One tag line per program: "Program" or "Program (note)". */
function kop_glossary_tag_lines($tags) {
    $lines = array();
    foreach ($tags as $t) {
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

/** Strip tag, program key: what matters when comparing tag lists. */
function kop_glossary_tags_equal($a, $b) {
    $norm = function ($tags) {
        return array_map(function ($t) {
            return array($t['program'], isset($t['note']) ? $t['note'] : '');
        }, $tags);
    };
    return $norm($a) === $norm($b);
}

/**
 * Build an entry paragraph from the form. Parts the form did not change keep
 * their original wording ($orig: kop_glossary_parse_entry($para, true)), so a
 * one-word fix is a one-word diff.
 *
 * @return array('markdown' => string, 'errors' => list)
 */
function kop_glossary_entry_markdown($fields, $orig) {
    $errors = array();
    $term = kop_glossary_trim(preg_replace('/\s+/u', ' ', $fields['term']));
    $note = kop_glossary_trim(preg_replace('/\s+/u', ' ', $fields['note']));
    /* One name per line, so a name may hold a comma. */
    $aka = kop_glossary_lines($fields['aka_list']);
    if (strpos($fields['aka_list'], '"') !== false) {
        $errors[] = 'Also called: leave out quotation marks.';
    }
    $text = kop_glossary_trim(preg_replace('/\s*\n\s*/u', ' ', str_replace("\r", '', $fields['text'])));
    $used = array_map('kop_glossary_parse_tag', kop_glossary_lines($fields['used']));
    $reported = array_map('kop_glossary_parse_tag', kop_glossary_lines($fields['reported']));

    if ($term === '') {
        $errors[] = 'The term is required.';
    }
    if (strpos($term, '*') !== false || strpos($note, '*') !== false) {
        $errors[] = 'The term and the qualifier cannot contain asterisks.';
    }
    if ($note !== '' && $aka) {
        $errors[] = 'An entry can have a qualifier or other names ("also called"), not both. Put the other names in the definition, or drop the qualifier.';
    }
    if (preg_match('/^aka /i', $note)) {
        $errors[] = 'Put other names under "Also called", not in the qualifier.';
    }
    if ($text === '') {
        $errors[] = 'The definition is required.';
    }
    if ($errors) {
        return array('markdown' => '', 'errors' => $errors);
    }

    /* Head: **Term** *(qualifier or aka ...)*: */
    if ($orig && $orig['term'] === $term && $orig['note'] === $note && $orig['aka'] === $aka) {
        $head = rtrim($orig['head']) . ' ';
    } else {
        $paren = '';
        if ($note !== '') {
            $paren = $note;
        } elseif ($aka) {
            $quote = false;
            foreach ($aka as $a) {
                if (strpos($a, ',') !== false) {
                    $quote = true;
                }
            }
            if ($quote) {
                $last = count($aka) - 1;
                $paren = 'aka ' . implode(' ', array_map(function ($a, $i) use ($last) {
                    return '"' . $a . ($i < $last ? ',' : '') . '"';
                }, $aka, array_keys($aka)));
            } else {
                $paren = 'aka ' . implode(', ', $aka);
            }
        }
        $head = '**' . $term . '**' . ($paren !== '' ? ' *(' . $paren . ')*' : '') . ': ';
    }

    /* Definition: the original wording when only whitespace differs. */
    if ($orig && preg_replace('/\s+/u', ' ', $orig['text_raw']) === $text) {
        $text = $orig['text_raw'];
    }

    /* Tags: Used at: *A, B*; reportedly used at: *C* */
    if ($orig && kop_glossary_tags_equal($orig['used'], $used) && kop_glossary_tags_equal($orig['reported'], $reported)) {
        $tags = $orig['tags_raw'];
    } else {
        $parts = '';
        if ($used) {
            $parts .= 'Used at: *' . implode(', ', array_map(function ($t) {
                return $t['program'] . (!empty($t['note']) ? ' (' . $t['note'] . ')' : '');
            }, $used)) . '*';
        }
        if ($reported) {
            $parts .= ($used ? '; reportedly used at: *' : 'Reportedly used at: *') . implode(', ', array_map(function ($t) {
                return $t['program'] . (!empty($t['note']) ? ' (' . $t['note'] . ')' : '');
            }, $reported)) . '*';
        }
        $tags = $parts !== '' ? ' ' . $parts : '';
    }
    $markdown = $head . $text . $tags;

    /* Read it back: anything the format cannot carry shows up here. */
    $back = kop_glossary_parse_entry($markdown, true);
    if (!$back) {
        return array('markdown' => '', 'errors' => array('The entry could not be read back. Check the term for stray characters.'));
    }
    if ($back['term'] !== $term) {
        $errors[] = 'The term did not survive: it would read as "' . $back['term'] . '".';
    }
    if ($back['note'] !== $note || $back['aka'] !== $aka) {
        $errors[] = 'The qualifier or other names did not survive (unbalanced parentheses?). They would read as: '
            . ($back['note'] !== '' ? '(' . $back['note'] . ')' : 'aka ' . implode(' | ', $back['aka']));
    }
    if (preg_replace('/\s+/u', ' ', $back['text_raw']) !== preg_replace('/\s+/u', ' ', $text)) {
        $errors[] = 'The definition did not survive: its end looks like a "Used at:" list. Reword the last sentence.';
    }
    if (!kop_glossary_tags_equal($back['used'], $used) || !kop_glossary_tags_equal($back['reported'], $reported)) {
        $errors[] = 'A program list did not survive. A program name with a comma or an asterisk in it cannot be tagged '
            . '(names with a comma must be added to COMMA_NAMES in scripts/build-glossary.js and inc/glossary-build.php).';
    }
    if (strpos($markdown, "\n") !== false) {
        $errors[] = 'The entry must be one paragraph.';
    }
    return array('markdown' => $markdown, 'errors' => $errors);
}

/* ---- Working state --------------------------------------------------- */

/**
 * Everything the screens need: the changes, what became of each, the
 * merged markdown, its build (with each entry's source paragraph), and any
 * build errors.
 */
function kop_glossary_editor_state($ops = null) {
    $ops = $ops === null ? kop_glossary_edits() : $ops;
    $repo = kop_glossary_repo_markdown();
    if ($repo === null) {
        return null;
    }
    $applied = kop_glossary_apply_edits($repo, $ops);
    $built = kop_glossary_build($applied['markdown'], true);
    return array(
        'ops'      => $ops,
        'status'   => $applied['status'],
        'markdown' => $applied['markdown'],
        'data'     => $built['data'],
        'errors'   => $built['errors'],
    );
}

/** id => entry (with source and container) of a built glossary. */
function kop_glossary_entries_by_id($data) {
    $out = array();
    if ($data) {
        foreach (kop_glossary_all_entries($data) as $entry) {
            $out[$entry['id']] = $entry;
        }
    }
    return $out;
}

/** Every section and group, as title paths. */
function kop_glossary_containers($data) {
    $out = array();
    $walk = function ($node, $path) use (&$walk, &$out) {
        $path[] = $node['title'];
        $out[] = $path;
        foreach ($node['groups'] as $g) {
            $walk($g, $path);
        }
    };
    foreach (($data['sections'] ?? array()) as $s) {
        $walk($s, array());
    }
    return $out;
}

function kop_glossary_container_label($path) {
    return implode(' › ', (array) $path);
}

/** The change that produced this paragraph, as an index into $ops, or -1. */
function kop_glossary_op_for($ops, $status, $para) {
    foreach ($ops as $i => $op) {
        if (($status[$op['id']] ?? '') === 'applied' && $op['markdown'] === $para) {
            return $i;
        }
    }
    return -1;
}

/* ---- Admin menu and download ----------------------------------------- */

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

/** The merged glossary.md, as a download. */
add_action('admin_post_kop_glossary_download', function () {
    if (!current_user_can('manage_options')) {
        wp_die('Not authorized', 'Access Denied', array('response' => 403));
    }
    check_admin_referer('kop_glossary_download');
    $state = kop_glossary_editor_state();
    if (!$state) {
        wp_die('js/data/glossary/glossary.md is missing on the server.');
    }
    nocache_headers();
    header('Content-Type: text/markdown; charset=utf-8');
    header('Content-Disposition: attachment; filename="glossary.md"');
    echo $state['markdown'];
    exit;
});

/* ---- Saving ------------------------------------------------------------ */

/**
 * Check a new set of changes builds; save it if so.
 *
 * @return array errors (empty on success)
 */
function kop_glossary_commit_ops($ops) {
    $state = kop_glossary_editor_state($ops);
    if (!$state) {
        return array('js/data/glossary/glossary.md is missing on the server.');
    }
    if ($state['errors']) {
        return $state['errors'];
    }
    kop_glossary_save_edits($ops);
    kop_glossary_purge_page_cache();
    return array();
}

function kop_glossary_new_op($target, $markdown, $container, $term) {
    $user = wp_get_current_user();
    return array(
        'id'        => wp_generate_password(12, false),
        'target'    => $target,
        'markdown'  => $markdown,
        'container' => $container,
        'term'      => $term,
        'user'      => $user ? $user->user_login : '',
        'time'      => current_time('mysql'),
        'date'      => current_time('Y-m-d'),
    );
}

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
 * @return array('notice' => string, 'errors' => list, 'view' => 'list'|'edit', 'fields' => form values to redisplay)
 */
function kop_glossary_editor_handle_post() {
    check_admin_referer('kop_glossary_editor');
    $do = sanitize_key($_POST['kop_ge_do'] ?? '');
    $state = kop_glossary_editor_state();
    if (!$state) {
        return array('errors' => array('js/data/glossary/glossary.md is missing on the server.'), 'view' => 'list');
    }
    $ops = $state['ops'];
    $entries = kop_glossary_entries_by_id($state['data']);
    $post = wp_unslash($_POST);

    if ($do === 'discard') {
        $id = (string) ($post['kop_ge_op'] ?? '');
        $kept = array_values(array_filter($ops, function ($op) use ($id) {
            return $op['id'] !== $id;
        }));
        if (count($kept) === count($ops)) {
            return array('errors' => array('That change was already gone.'), 'view' => 'list');
        }
        $errors = kop_glossary_commit_ops($kept);
        return $errors
            ? array('errors' => array_merge(array('That change cannot be undone on its own; other entries depend on it:'), $errors), 'view' => 'list')
            : array('notice' => 'Change undone.', 'view' => 'list');
    }

    $entry_id = (string) ($post['kop_ge_entry'] ?? '');
    $entry = $entry_id !== '' ? ($entries[$entry_id] ?? null) : null;
    if ($entry_id !== '' && !$entry) {
        return array('errors' => array('That entry is no longer in the glossary. It may have been renamed or deleted meanwhile.'), 'view' => 'list');
    }
    $feedback_id = (int) ($post['kop_ge_feedback'] ?? 0);
    $close_feedback = $feedback_id > 0 && !empty($post['kop_ge_close_feedback']);
    $index = $entry ? kop_glossary_op_for($ops, $state['status'], $entry['source']) : -1;

    if ($do === 'delete') {
        if (!$entry) {
            return array('errors' => array('Nothing to delete.'), 'view' => 'list');
        }
        if ($index >= 0 && $ops[$index]['target'] === null) {
            array_splice($ops, $index, 1);             // an entry added here: just drop it
        } elseif ($index >= 0) {
            $ops[$index]['markdown'] = null;
            $ops[$index]['container'] = null;
            $ops[$index]['time'] = current_time('mysql');
            $ops[$index]['date'] = current_time('Y-m-d');
        } else {
            $ops[] = kop_glossary_new_op($entry['source'], null, null, $entry['term']);
        }
        $errors = kop_glossary_commit_ops($ops);
        if ($errors) {
            return array('errors' => array_merge(array('"' . $entry['term'] . '" cannot be deleted while other text links to it. Edit these first:'), $errors), 'view' => 'list');
        }
        if ($close_feedback) {
            kop_glossary_editor_close_feedback($feedback_id);
        }
        return array('notice' => '"' . $entry['term'] . '" deleted.', 'view' => 'list');
    }

    if ($do !== 'save') {
        return array('errors' => array('Unknown action.'), 'view' => 'list');
    }

    $fields = array(
        'term'      => (string) ($post['kop_ge_term'] ?? ''),
        'note'      => (string) ($post['kop_ge_note'] ?? ''),
        'aka_list'  => (string) ($post['kop_ge_aka'] ?? ''),
        'text'      => (string) ($post['kop_ge_text'] ?? ''),
        'used'      => (string) ($post['kop_ge_used'] ?? ''),
        'reported'  => (string) ($post['kop_ge_reported'] ?? ''),
        'container' => (string) ($post['kop_ge_container'] ?? ''),
    );
    $fail = function ($errors) use ($fields) {
        return array('errors' => $errors, 'view' => 'edit', 'fields' => $fields);
    };

    $container = null;
    foreach (kop_glossary_containers($state['data']) as $path) {
        if (kop_glossary_container_label($path) === $fields['container']) {
            $container = $path;
        }
    }
    if (!$container) {
        return $fail(array('Choose where the entry goes.'));
    }

    $orig = $entry ? kop_glossary_parse_entry($entry['source'], true) : null;
    $built = kop_glossary_entry_markdown($fields, $orig);
    if ($built['errors']) {
        return $fail($built['errors']);
    }
    $markdown = $built['markdown'];
    $moved = $entry && $container !== $entry['container'];

    if ($entry && !$moved && $markdown === $entry['source']) {
        if ($close_feedback) {
            kop_glossary_editor_close_feedback($feedback_id);
        }
        return array('notice' => 'No changes to "' . $entry['term'] . '".', 'view' => 'list');
    }

    if (!$entry) {
        $ops[] = kop_glossary_new_op(null, $markdown, $container, $fields['term']);
    } elseif ($index >= 0) {
        $op = $ops[$index];
        $op['markdown'] = $markdown;
        if ($moved || $op['target'] === null) {
            $op['container'] = $container;
        }
        $op['term'] = kop_glossary_trim($fields['term']);
        $op['time'] = current_time('mysql');
        $op['date'] = current_time('Y-m-d');
        if ($op['target'] !== null && $op['target'] === $markdown && empty($op['container'])) {
            array_splice($ops, $index, 1);             // edited back to the original
        } else {
            $ops[$index] = $op;
        }
    } else {
        $ops[] = kop_glossary_new_op($entry['source'], $markdown, $moved ? $container : null, $fields['term']);
    }

    $errors = kop_glossary_commit_ops($ops);
    if ($errors) {
        return $fail($errors);
    }
    if ($close_feedback) {
        kop_glossary_editor_close_feedback($feedback_id);
    }
    /* Find the saved entry for its link. */
    $after = kop_glossary_editor_state();
    $link = '';
    foreach (kop_glossary_entries_by_id($after['data']) as $e) {
        if ($e['source'] === $markdown) {
            $link = ' <a href="' . esc_url(home_url('/' . KOP_GLOSSARY_SLUG . '/#' . $e['id'])) . '" target="_blank" rel="noopener">View it on the glossary</a>.';
        }
    }
    return array('notice' => esc_html('"' . kop_glossary_trim($fields['term']) . '" saved and live.') . $link, 'notice_html' => true, 'view' => 'list');
}

/* ---- Screens ------------------------------------------------------------ */

function kop_render_glossary_editor_page() {
    if (!current_user_can('manage_options')) {
        wp_die('Not authorized', 'Access Denied', array('response' => 403));
    }
    $result = array();
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['kop_ge_do'])) {
        $result = kop_glossary_editor_handle_post();
    }

    $state = kop_glossary_editor_state();
    echo '<div class="wrap kop-ge">';
    kop_glossary_editor_styles();
    if (!$state) {
        echo '<h1>Glossary Editor</h1><div class="notice notice-error"><p><code>js/data/glossary/glossary.md</code> is missing on the server.</p></div></div>';
        return;
    }

    /* Changes the repo has caught up with clear themselves. */
    $cleared = array_filter($state['ops'], function ($op) use ($state) {
        return ($state['status'][$op['id']] ?? '') === 'in_repo';
    });
    if ($cleared) {
        $keep = array_values(array_filter($state['ops'], function ($op) use ($state) {
            return ($state['status'][$op['id']] ?? '') !== 'in_repo';
        }));
        kop_glossary_save_edits($keep);
        kop_glossary_purge_page_cache();
        $state = kop_glossary_editor_state();
        echo '<div class="notice notice-info is-dismissible"><p>' . count($cleared) . ' saved change' . (count($cleared) === 1 ? ' is' : 's are')
            . ' now in the repository copy of glossary.md and ' . (count($cleared) === 1 ? 'was' : 'were') . ' cleared.</p></div>';
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

    $view = $result['view'] ?? sanitize_key($_GET['view'] ?? 'list');
    if ($view === 'edit' || $view === 'new') {
        kop_glossary_editor_form($state, $result['fields'] ?? null);
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
        .kop-ge .kop-ge-badge { display: inline-block; padding: 1px 7px; border-radius: 10px; font-size: 11px; background: #dcdcde; }
        .kop-ge .kop-ge-badge--applied { background: #d1e7dd; }
        .kop-ge .kop-ge-badge--stale { background: #f8d7da; }
        .kop-ge .kop-ge-muted { color: #646970; }
        .kop-ge .kop-ge-form th { width: 180px; }
        .kop-ge .kop-ge-form textarea, .kop-ge .kop-ge-form input[type=text], .kop-ge .kop-ge-form select { width: 100%; max-width: 820px; }
        .kop-ge .kop-ge-md { white-space: pre-wrap; background: #f6f7f7; padding: 8px 10px; max-width: 820px; font-size: 12px; }
        .kop-ge .kop-ge-filter { margin: 12px 0; display: flex; gap: 8px; flex-wrap: wrap; align-items: center; }
        .kop-ge .kop-ge-add { display: flex; gap: 6px; margin-top: 6px; max-width: 820px; }
        .kop-ge .kop-ge-add input { flex: 1; }
    </style>
    <?php
}

function kop_glossary_editor_list($state) {
    $ops = $state['ops'];
    $status = $state['status'];
    $entries = kop_glossary_entries_by_id($state['data']);

    echo '<h1 class="wp-heading-inline">Glossary Editor</h1> ';
    echo '<a class="page-title-action" href="' . esc_url(kop_glossary_editor_url(array('view' => 'new'))) . '">Add a term</a>';
    echo '<hr class="wp-header-end">';
    echo '<p>Edits save straight to <a href="' . esc_url(home_url('/' . KOP_GLOSSARY_SLUG . '/')) . '" target="_blank" rel="noopener">the glossary</a>. '
        . 'Reader suggestions are under <a href="' . esc_url(admin_url('admin.php?page=kop-glossary-feedback')) . '">Glossary Feedback</a>.</p>';

    if ($state['errors']) {
        echo '<div class="notice notice-error"><p><strong>The glossary with the saved changes does not build, so the site is showing the committed version.</strong> Undo or fix the changes below:</p><ul>';
        foreach ($state['errors'] as $e) {
            echo '<li>' . esc_html($e) . '</li>';
        }
        echo '</ul></div>';
    }

    /* Pending changes. */
    if ($ops) {
        $by_source = array();
        foreach ($entries as $e) {
            $by_source[$e['source']] = $e;
        }
        echo '<div class="kop-ge-box"><h2>Saved changes not yet in the repository (' . count($ops) . ')</h2>';
        echo '<p class="kop-ge-muted">These are live on the site. To make them permanent, download glossary.md, replace <code>js/data/glossary/glossary.md</code> with it, run <code>node scripts/build-glossary.js</code>, and commit both files. After that deploys, these clear themselves.</p>';
        echo '<p><a class="button button-primary" href="' . esc_url(wp_nonce_url(admin_url('admin-post.php?action=kop_glossary_download'), 'kop_glossary_download')) . '">Download glossary.md</a></p>';
        echo '<table class="widefat striped"><thead><tr><th>Entry</th><th style="width:110px">Change</th><th style="width:170px">By</th><th style="width:120px">Status</th><th style="width:170px"></th></tr></thead><tbody>';
        foreach ($ops as $op) {
            $st = $status[$op['id']] ?? 'stale';
            $kind = $op['markdown'] === null ? 'Deleted' : ($op['target'] === null ? 'Added' : (!empty($op['container']) ? 'Edited, moved' : 'Edited'));
            $live = $op['markdown'] !== null && isset($by_source[$op['markdown']]) ? $by_source[$op['markdown']] : null;
            echo '<tr><td><strong>' . esc_html($op['term']) . '</strong>';
            if (!empty($op['container']) && $op['markdown'] !== null) {
                echo '<br><span class="kop-ge-muted">to ' . esc_html(kop_glossary_container_label($op['container'])) . '</span>';
            }
            if ($st === 'stale') {
                echo '<p class="kop-ge-muted">This entry was changed in the repository after this edit, so the edit is not applied. Your version:</p>'
                    . '<div class="kop-ge-md">' . esc_html($op['markdown'] ?? '(delete)') . '</div>';
            }
            echo '</td><td>' . esc_html($kind) . '</td>';
            echo '<td>' . esc_html($op['user']) . '<br><span class="kop-ge-muted">' . esc_html($op['time']) . '</span></td>';
            echo '<td><span class="kop-ge-badge kop-ge-badge--' . esc_attr($st) . '">' . esc_html($st === 'applied' ? 'Live' : 'Out of date') . '</span></td><td>';
            if ($live) {
                echo '<a class="button button-small" href="' . esc_url(kop_glossary_editor_url(array('view' => 'edit', 'entry' => $live['id']))) . '">Edit</a> ';
            }
            echo '<form method="post" style="display:inline" onsubmit="return confirm(\'Undo this change?\');">';
            wp_nonce_field('kop_glossary_editor');
            echo '<input type="hidden" name="kop_ge_do" value="discard"><input type="hidden" name="kop_ge_op" value="' . esc_attr($op['id']) . '">'
                . '<button class="button button-small">Undo</button></form></td></tr>';
        }
        echo '</tbody></table></div>';
    }

    /* The entries. */
    $q = trim((string) wp_unslash($_GET['q'] ?? ''));
    $section = (string) wp_unslash($_GET['section'] ?? '');
    $edited = array();
    foreach ($ops as $op) {
        if (($status[$op['id']] ?? '') === 'applied' && $op['markdown'] !== null) {
            $edited[$op['markdown']] = $op['target'] === null ? 'Added' : 'Edited';
        }
    }
    echo '<form class="kop-ge-filter" method="get"><input type="hidden" name="page" value="' . esc_attr(KOP_GLOSSARY_EDITOR_PAGE) . '">'
        . '<input type="search" name="q" value="' . esc_attr($q) . '" placeholder="Term, word or program" style="min-width:260px">'
        . '<select name="section"><option value="">All sections</option>';
    foreach (($state['data']['sections'] ?? array()) as $s) {
        echo '<option value="' . esc_attr($s['title']) . '"' . selected($section, $s['title'], false) . '>' . esc_html($s['title']) . '</option>';
    }
    echo '</select><button class="button">Filter</button>';
    if ($q !== '' || $section !== '') {
        echo ' <a href="' . esc_url(kop_glossary_editor_url()) . '">Clear</a>';
    }
    echo '</form>';

    $words = $q === '' ? array() : explode(' ', kop_glossary_lower(preg_replace('/\s+/', ' ', $q)));
    $rows = array();
    foreach ($entries as $e) {
        if ($section !== '' && $e['container'][0] !== $section) {
            continue;
        }
        if (!kop_glossary_entry_matches($e, '', $words)) {
            continue;
        }
        $rows[] = $e;
    }
    echo '<p class="kop-ge-muted">' . count($rows) . ' of ' . count($entries) . ' entries</p>';
    echo '<table class="widefat striped"><thead><tr><th>Term</th><th>Where</th><th style="width:90px">Programs</th><th style="width:90px"></th><th style="width:130px"></th></tr></thead><tbody>';
    foreach ($rows as $e) {
        $n = count($e['used']) + count($e['reported']);
        echo '<tr><td><a href="' . esc_url(kop_glossary_editor_url(array('view' => 'edit', 'entry' => $e['id']))) . '"><strong>' . esc_html($e['term']) . '</strong></a>'
            . ($e['note'] !== '' ? ' <span class="kop-ge-muted">(' . esc_html($e['note']) . ')</span>' : '') . '</td>'
            . '<td>' . esc_html(kop_glossary_container_label($e['container'])) . '</td>'
            . '<td>' . (int) $n . '</td>'
            . '<td>' . (isset($edited[$e['source']]) ? '<span class="kop-ge-badge kop-ge-badge--applied">' . esc_html($edited[$e['source']]) . '</span>' : '') . '</td>'
            . '<td><a href="' . esc_url(kop_glossary_editor_url(array('view' => 'edit', 'entry' => $e['id']))) . '">Edit</a> | '
            . '<a href="' . esc_url(home_url('/' . KOP_GLOSSARY_SLUG . '/#' . $e['id'])) . '" target="_blank" rel="noopener">View</a></td></tr>';
    }
    echo '</tbody></table>';
}

/** The add / edit form. $fields: values to show again after a failed save. */
function kop_glossary_editor_form($state, $fields) {
    $entries = kop_glossary_entries_by_id($state['data']);
    $entry_id = (string) wp_unslash($_REQUEST['kop_ge_entry'] ?? ($_GET['entry'] ?? ''));
    $entry = $entry_id !== '' ? ($entries[$entry_id] ?? null) : null;
    if ($entry_id !== '' && !$entry) {
        echo '<h1>Glossary Editor</h1><div class="notice notice-error"><p>No entry "' . esc_html($entry_id) . '". It may have been renamed.</p></div>';
        echo '<p><a href="' . esc_url(kop_glossary_editor_url()) . '">Back to the list</a></p>';
        return;
    }
    $section_default = (string) wp_unslash($_GET['in'] ?? '');
    if ($fields === null) {
        $orig = $entry ? kop_glossary_parse_entry($entry['source'], true) : null;
        $fields = array(
            'term'      => $orig ? $orig['term'] : '',
            'note'      => $orig ? $orig['note'] : '',
            'aka_list'  => $orig ? implode("\n", $orig['aka']) : '',
            'text'      => $orig ? $orig['text_raw'] : '',
            'used'      => $orig ? kop_glossary_tag_lines($orig['used']) : '',
            'reported'  => $orig ? kop_glossary_tag_lines($orig['reported']) : '',
            'container' => $entry ? kop_glossary_container_label($entry['container']) : ($section_default !== '' ? $section_default : 'Shared Terms A–Z'),
        );
    }

    /* A reader's note this edit answers. */
    $feedback_id = (int) ($_REQUEST['kop_ge_feedback'] ?? ($_GET['feedback'] ?? 0));
    $feedback = null;
    if ($feedback_id > 0 && function_exists('kop_glossary_feedback_table')) {
        global $wpdb;
        $feedback = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . kop_glossary_feedback_table() . ' WHERE id = %d', $feedback_id));
    }

    echo '<h1>' . ($entry ? 'Edit "' . esc_html($entry['term']) . '"' : 'Add a term') . '</h1>';
    echo '<p><a href="' . esc_url(kop_glossary_editor_url()) . '">&larr; All entries</a>';
    if ($entry) {
        echo ' &middot; <a href="' . esc_url(home_url('/' . KOP_GLOSSARY_SLUG . '/#' . $entry['id'])) . '" target="_blank" rel="noopener">View on the glossary</a>';
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
    foreach (($state['data']['programs'] ?? array()) as $p) {
        $programs[] = $p['name'];
    }
    ?>
    <form method="post" class="kop-ge-form" action="<?php echo esc_url(kop_glossary_editor_url()); ?>">
        <?php wp_nonce_field('kop_glossary_editor'); ?>
        <input type="hidden" name="kop_ge_do" value="save">
        <input type="hidden" name="kop_ge_entry" value="<?php echo esc_attr($entry ? $entry['id'] : ''); ?>">
        <input type="hidden" name="kop_ge_feedback" value="<?php echo (int) $feedback_id; ?>">
        <table class="form-table" role="presentation">
            <tr>
                <th><label for="kop-ge-term">Term</label></th>
                <td><input type="text" id="kop-ge-term" name="kop_ge_term" value="<?php echo esc_attr($fields['term']); ?>" required></td>
            </tr>
            <tr>
                <th><label for="kop-ge-note">Qualifier</label></th>
                <td><input type="text" id="kop-ge-note" name="kop_ge_note" value="<?php echo esc_attr($fields['note']); ?>">
                    <p class="description">Shown in parentheses after the term, e.g. the full name of an abbreviation, or the program when two entries share a term. Leave blank if the entry has other names below.</p></td>
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
                    <div class="kop-ge-add"><input type="text" list="kop-ge-programs" data-for="kop-ge-used" placeholder="Add a program"><button type="button" class="button" data-add="kop-ge-used">Add</button></div>
                    <p class="description">Programs whose own documents use the term, one per line. A note on how that program used it goes in parentheses: <code>Spring Ridge Academy (also as "vicinity visit")</code>.</p></td>
            </tr>
            <tr>
                <th><label for="kop-ge-reported">Reportedly used at</label></th>
                <td><textarea id="kop-ge-reported" name="kop_ge_reported" rows="4"><?php echo esc_textarea($fields['reported']); ?></textarea>
                    <div class="kop-ge-add"><input type="text" list="kop-ge-programs" data-for="kop-ge-reported" placeholder="Add a program"><button type="button" class="button" data-add="kop-ge-reported">Add</button></div>
                    <p class="description">Programs where survivor accounts report the term, one per line.</p></td>
            </tr>
            <tr>
                <th><label for="kop-ge-container">Section</label></th>
                <td><select id="kop-ge-container" name="kop_ge_container">
                    <?php foreach (kop_glossary_containers($state['data']) as $path) :
                        $label = kop_glossary_container_label($path); ?>
                        <option value="<?php echo esc_attr($label); ?>"<?php selected($fields['container'], $label); ?>><?php echo esc_html(str_repeat('— ', count($path) - 1) . end($path)); ?></option>
                    <?php endforeach; ?>
                </select>
                    <p class="description">Shared Terms A–Z for a term documented at more than one program; a program's own group for one documented at a single program. Entries sort alphabetically within it.</p></td>
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
        <details style="margin:8px 0 16px"><summary>Markdown for this entry</summary><div class="kop-ge-md"><?php echo esc_html($entry['source']); ?></div></details>
        <form method="post" action="<?php echo esc_url(kop_glossary_editor_url()); ?>" onsubmit="return confirm('Delete this entry from the glossary?');">
            <?php wp_nonce_field('kop_glossary_editor'); ?>
            <input type="hidden" name="kop_ge_do" value="delete">
            <input type="hidden" name="kop_ge_entry" value="<?php echo esc_attr($entry['id']); ?>">
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
