<?php
/**
 * The glossary in SQL: sections and groups, entries, their other names and
 * program tags, and a log of every entry change (for Undo).
 *
 *   {prefix}kop_glossary_nodes    a section (parent_id 0) or a program group
 *                                 under one; anchor is the page's #id
 *                                 (groups print as #g-<anchor>), notes a JSON
 *                                 list of paragraphs, sources one line
 *   {prefix}kop_glossary_entries  one term: anchor (#id, kept when the entry
 *                                 is edited unless the term changes), term,
 *                                 qualifier, definition as written
 *   {prefix}kop_glossary_aliases  "Also called" names, in order
 *   {prefix}kop_glossary_tags     kind 'used' | 'reported', program, note
 *   {prefix}kop_glossary_log      each add/edit/delete: the entry before and
 *                                 after, who, when; Undo restores "before"
 *   option kop_glossary_meta      page title, intro paragraphs, updated date
 *
 * Every write is checked first: the tables are read into the tree the build
 * uses, the change is applied to it, and kop_glossary_finish() must find no
 * error (a **cross-reference** naming no entry or two, a duplicate id).
 *
 * The first time the tables are found empty, js/data/glossary/glossary.md is
 * imported, with the edits the old editor had saved over it
 * (kop_glossary_edits) applied first, so nothing live is lost.
 *
 * Entry points:
 *   kop_glossary_store_data()                 the page data, cached by revision
 *   kop_glossary_store_load()                 nodes + entry snapshots
 *   kop_glossary_store_save_entry($id, $snap) add (id 0) or edit
 *   kop_glossary_store_delete_entry($id)
 *   kop_glossary_store_undo($log_id)
 *   kop_glossary_store_save_node(...), kop_glossary_store_delete_node($id),
 *   kop_glossary_store_move_node($id, $dir), kop_glossary_store_save_meta($meta)
 *
 * A snapshot (one entry, as the log keeps it):
 *   array('node_id', 'anchor', 'term', 'note', 'aka' => list,
 *         'definition', 'used' => list of {program, note?}, 'reported' => ...)
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/glossary-build.php';

define('KOP_GLOSSARY_DB_VERSION', '1');

function kop_glossary_table($name) {
    global $wpdb;
    return $wpdb->prefix . 'kop_glossary_' . $name;
}

/** Creates the tables once per KOP_GLOSSARY_DB_VERSION. */
function kop_glossary_store_install() {
    if (get_option('kop_glossary_db') === KOP_GLOSSARY_DB_VERSION) {
        return;
    }
    global $wpdb;
    $tail = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    $wpdb->query('CREATE TABLE IF NOT EXISTS ' . kop_glossary_table('nodes') . " (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        parent_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        title VARCHAR(255) NOT NULL,
        anchor VARCHAR(191) NOT NULL,
        sources TEXT NULL,
        notes LONGTEXT NULL,
        position INT NOT NULL DEFAULT 0,
        updated_at DATETIME NULL,
        PRIMARY KEY (id),
        KEY parent_id (parent_id)
    ) $tail");
    $wpdb->query('CREATE TABLE IF NOT EXISTS ' . kop_glossary_table('entries') . " (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        node_id BIGINT UNSIGNED NOT NULL,
        anchor VARCHAR(191) NOT NULL,
        term VARCHAR(255) NOT NULL,
        qualifier VARCHAR(255) NOT NULL DEFAULT '',
        definition TEXT NOT NULL,
        created_at DATETIME NULL,
        updated_at DATETIME NULL,
        updated_by VARCHAR(60) NOT NULL DEFAULT '',
        PRIMARY KEY (id),
        UNIQUE KEY anchor (anchor),
        KEY node_id (node_id)
    ) $tail");
    $wpdb->query('CREATE TABLE IF NOT EXISTS ' . kop_glossary_table('aliases') . " (
        entry_id BIGINT UNSIGNED NOT NULL,
        position SMALLINT UNSIGNED NOT NULL,
        alias VARCHAR(255) NOT NULL,
        PRIMARY KEY (entry_id, position)
    ) $tail");
    $wpdb->query('CREATE TABLE IF NOT EXISTS ' . kop_glossary_table('tags') . " (
        entry_id BIGINT UNSIGNED NOT NULL,
        kind VARCHAR(10) NOT NULL,
        position SMALLINT UNSIGNED NOT NULL,
        program VARCHAR(255) NOT NULL,
        note VARCHAR(500) NOT NULL DEFAULT '',
        PRIMARY KEY (entry_id, kind, position),
        KEY program (program(191))
    ) $tail");
    $wpdb->query('CREATE TABLE IF NOT EXISTS ' . kop_glossary_table('log') . " (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        entry_id BIGINT UNSIGNED NOT NULL,
        action VARCHAR(10) NOT NULL,
        term VARCHAR(255) NOT NULL DEFAULT '',
        before_json LONGTEXT NULL,
        after_json LONGTEXT NULL,
        user VARCHAR(60) NOT NULL DEFAULT '',
        created_at DATETIME NULL,
        undone_at DATETIME NULL,
        PRIMARY KEY (id),
        KEY entry_id (entry_id)
    ) $tail");
    update_option('kop_glossary_db', KOP_GLOSSARY_DB_VERSION);
}

/** Tables there and filled (importing glossary.md the first time). */
function kop_glossary_store_ready() {
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }
    kop_glossary_store_install();
    global $wpdb;
    $count = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . kop_glossary_table('entries'));
    if ($count === 0 && !get_option('kop_glossary_imported')) {
        kop_glossary_store_import();
        $count = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . kop_glossary_table('entries'));
    }
    $ready = $count > 0;
    return $ready;
}

/** "Now" for the tables, and who is making the change. */
function kop_glossary_store_now() {
    return function_exists('current_time') ? current_time('mysql') : gmdate('Y-m-d H:i:s');
}

function kop_glossary_store_user() {
    $user = function_exists('wp_get_current_user') ? wp_get_current_user() : null;
    return $user && !empty($user->user_login) ? (string) $user->user_login : '';
}

/** Data changed: the next read rebuilds, and cached copies of /glossary/ go. */
function kop_glossary_store_bump() {
    update_option('kop_glossary_rev', (int) get_option('kop_glossary_rev', 0) + 1, false);
    delete_option('kop_glossary_cache');
    if (function_exists('get_page_by_path') && function_exists('home_url')) {
        $page = get_page_by_path(defined('KOP_GLOSSARY_SLUG') ? KOP_GLOSSARY_SLUG : 'glossary');
        if ($page && function_exists('clean_post_cache')) {
            clean_post_cache($page->ID);
            do_action('litespeed_purge_post', $page->ID);
        }
        do_action('litespeed_purge_url', home_url('/' . (defined('KOP_GLOSSARY_SLUG') ? KOP_GLOSSARY_SLUG : 'glossary') . '/'));
    }
}

/* ---- Import ------------------------------------------------------------- */

/**
 * The old editor's overlay applied to glossary.md: each change names the
 * paragraph it replaces (target, null = new) and its new text (markdown,
 * null = delete), optionally a container (titles path) to move into.
 * Changes whose paragraph is gone are skipped.
 */
function kop_glossary_import_apply_edits($markdown, $ops) {
    $paras = kop_glossary_paragraphs($markdown);
    $container_end = function ($paras, $container) {
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
            $path = array_slice($path, 0, $level - 2);
            $path[] = kop_glossary_trim(substr($para, $level + 1));
            if ($path === array_values($container)) {
                $found = $i;
            }
        }
        return $found >= 0 ? count($paras) : -1;
    };
    $latest = '';
    foreach ((array) $ops as $op) {
        $target = isset($op['target']) ? $op['target'] : null;
        $new = isset($op['markdown']) ? $op['markdown'] : null;
        $container = !empty($op['container']) ? $op['container'] : null;
        $at = $target !== null ? array_search($target, $paras, true) : false;
        if ($target !== null && $at === false) {
            continue;                                   // already in the file, or out of date
        }
        if ($container !== null && $container_end($paras, $container) < 0) {
            continue;
        }
        if ($target === null && $container === null) {
            continue;
        }
        if ($at !== false) {
            array_splice($paras, $at, 1);
        }
        if ($new !== null) {
            $insert = $container !== null ? $container_end($paras, $container) : $at;
            array_splice($paras, $insert, 0, array($new));
        }
        if (!empty($op['date']) && $op['date'] > $latest) {
            $latest = $op['date'];
        }
    }
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
    return implode("\n\n", $paras) . "\n";
}

/**
 * Fill the empty tables from glossary.md (plus the old editor's saved
 * changes). Only one request does it; the rest see the lock and wait for the
 * next page load.
 *
 * @return array errors (empty on success)
 */
function kop_glossary_store_import($markdown = null) {
    global $wpdb;
    $path = function_exists('get_stylesheet_directory') ? get_stylesheet_directory() . '/js/data/glossary/glossary.md' : '';
    if ($markdown === null) {
        if ($path === '' || !is_readable($path)) {
            return array('js/data/glossary/glossary.md is missing, so there is nothing to import.');
        }
        $markdown = (string) file_get_contents($path);
        $edits = get_option('kop_glossary_edits');
        if (is_array($edits) && !empty($edits['ops'])) {
            $markdown = kop_glossary_import_apply_edits($markdown, $edits['ops']);
        }
    }
    if (!add_option('kop_glossary_import_lock', time(), '', 'no')) {
        $at = (int) get_option('kop_glossary_import_lock');
        if ($at > time() - 300) {
            return array('Another request is importing the glossary.');
        }
        update_option('kop_glossary_import_lock', time(), false);
    }
    try {
        $built = kop_glossary_build($markdown, true);
        if (!$built['data']) {
            return $built['errors'];
        }
        $data = $built['data'];
        $now = kop_glossary_store_now();
        $nodes = 0;
        $entries = 0;
        $wpdb->query('START TRANSACTION');
        $insert_node = function ($node, $parent, $position) use (&$insert_node, $wpdb, $now, &$nodes, &$entries) {
            $wpdb->insert(kop_glossary_table('nodes'), array(
                'parent_id'  => (int) $parent,
                'title'      => $node['title'],
                'anchor'     => $node['id'],
                'sources'    => isset($node['sources']) ? (string) $node['sources'] : '',
                'notes'      => wp_json_encode(array_values($node['notes']), JSON_UNESCAPED_UNICODE),
                'position'   => $position,
                'updated_at' => $now,
            ));
            $node_id = (int) $wpdb->insert_id;
            $nodes++;
            foreach ($node['entries'] as $entry) {
                $raw = kop_glossary_parse_entry($entry['source'], true);
                kop_glossary_store_write_entry(0, array(
                    'node_id'    => $node_id,
                    'anchor'     => $entry['id'],
                    'term'       => $entry['term'],
                    'note'       => $entry['note'],
                    'aka'        => $entry['aka'],
                    'definition' => $raw ? $raw['text_raw'] : $entry['text'],
                    'used'       => $entry['used'],
                    'reported'   => $entry['reported'],
                ), $now, 'import');
                $entries++;
            }
            foreach ($node['groups'] as $i => $group) {
                $insert_node($group, $node_id, $i + 1);
            }
        };
        foreach ($data['sections'] as $i => $section) {
            $insert_node($section, 0, $i + 1);
        }
        $wpdb->query('COMMIT');
        update_option('kop_glossary_meta', array(
            'title'   => $data['title'],
            'updated' => $data['updated'],
            'intro'   => $data['intro'],
        ), false);
        update_option('kop_glossary_imported', array(
            'time'    => $now,
            'entries' => $entries,
            'nodes'   => $nodes,
            'md5'     => md5($markdown),
        ), false);
        /* The old overlay is in the tables now; keep a copy, stop using it. */
        $edits = get_option('kop_glossary_edits');
        if ($edits !== false) {
            update_option('kop_glossary_edits_imported', $edits, false);
            delete_option('kop_glossary_edits');
        }
        delete_option('kop_glossary_live');
        kop_glossary_store_bump();
        return array();
    } finally {
        delete_option('kop_glossary_import_lock');
    }
}

/* ---- Reading ------------------------------------------------------------ */

/** The glossary's page title, intro and updated date. */
function kop_glossary_store_meta() {
    $meta = get_option('kop_glossary_meta');
    $meta = is_array($meta) ? $meta : array();
    return array(
        'title'   => isset($meta['title']) ? (string) $meta['title'] : 'TTI Glossary',
        'updated' => isset($meta['updated']) ? (string) $meta['updated'] : '',
        'intro'   => isset($meta['intro']) ? array_values((array) $meta['intro']) : array(),
    );
}

/**
 * Everything in the tables: nodes (id => row with notes decoded, in page
 * order) and entries (id => snapshot).
 */
function kop_glossary_store_load() {
    global $wpdb;
    $nodes = array();
    foreach ((array) $wpdb->get_results('SELECT * FROM ' . kop_glossary_table('nodes') . ' ORDER BY parent_id, position, id', ARRAY_A) as $row) {
        $notes = json_decode((string) $row['notes'], true);
        $nodes[(int) $row['id']] = array(
            'id'        => (int) $row['id'],
            'parent_id' => (int) $row['parent_id'],
            'title'     => (string) $row['title'],
            'anchor'    => (string) $row['anchor'],
            'sources'   => (string) $row['sources'],
            'notes'     => is_array($notes) ? array_values($notes) : array(),
            'position'  => (int) $row['position'],
        );
    }
    $entries = array();
    foreach ((array) $wpdb->get_results('SELECT * FROM ' . kop_glossary_table('entries') . ' ORDER BY id', ARRAY_A) as $row) {
        $entries[(int) $row['id']] = array(
            'node_id'    => (int) $row['node_id'],
            'anchor'     => (string) $row['anchor'],
            'term'       => (string) $row['term'],
            'note'       => (string) $row['qualifier'],
            'aka'        => array(),
            'definition' => (string) $row['definition'],
            'used'       => array(),
            'reported'   => array(),
            'updated_at' => (string) $row['updated_at'],
            'updated_by' => (string) $row['updated_by'],
        );
    }
    foreach ((array) $wpdb->get_results('SELECT entry_id, alias FROM ' . kop_glossary_table('aliases') . ' ORDER BY entry_id, position', ARRAY_A) as $row) {
        if (isset($entries[(int) $row['entry_id']])) {
            $entries[(int) $row['entry_id']]['aka'][] = (string) $row['alias'];
        }
    }
    foreach ((array) $wpdb->get_results('SELECT entry_id, kind, program, note FROM ' . kop_glossary_table('tags') . ' ORDER BY entry_id, kind, position', ARRAY_A) as $row) {
        $id = (int) $row['entry_id'];
        $kind = $row['kind'] === 'reported' ? 'reported' : 'used';
        if (isset($entries[$id])) {
            $tag = array('program' => (string) $row['program']);
            if ((string) $row['note'] !== '') {
                $tag['note'] = (string) $row['note'];
            }
            $entries[$id][$kind][] = $tag;
        }
    }
    return array('nodes' => $nodes, 'entries' => $entries);
}

/** The section/group titles from the top down to a node. */
function kop_glossary_store_node_path($nodes, $node_id) {
    $path = array();
    $seen = array();
    while (isset($nodes[$node_id]) && !isset($seen[$node_id])) {
        $seen[$node_id] = true;
        array_unshift($path, $nodes[$node_id]['title']);
        $node_id = $nodes[$node_id]['parent_id'];
    }
    return $path;
}

/**
 * The tree kop_glossary_finish() reads, from loaded (and maybe changed)
 * tables. Each entry also carries 'key' (its row id) and 'container' (titles
 * path); each node 'key' (its row id).
 */
function kop_glossary_store_doc($state, $meta = null) {
    $meta = $meta === null ? kop_glossary_store_meta() : $meta;
    $doc = (object) array('title' => $meta['title'], 'updated' => $meta['updated'], 'intro' => $meta['intro'], 'sections' => array());
    $objects = array();
    foreach ($state['nodes'] as $id => $n) {
        $objects[$id] = (object) array(
            'title'   => $n['title'],
            'id'      => $n['anchor'],
            'key'     => $id,
            'sources' => $n['sources'],
            'notes'   => $n['notes'],
            'entries' => array(),
            'groups'  => array(),
        );
        if ($n['parent_id'] === 0) {
            unset($objects[$id]->sources);
        }
    }
    /* Nodes come ordered by parent, position: children land in order. */
    foreach ($state['nodes'] as $id => $n) {
        if ($n['parent_id'] === 0) {
            $doc->sections[] = $objects[$id];
        } elseif (isset($objects[$n['parent_id']])) {
            $objects[$n['parent_id']]->groups[] = $objects[$id];
        }
    }
    foreach ($state['entries'] as $id => $e) {
        if (!isset($objects[$e['node_id']])) {
            continue;
        }
        $objects[$e['node_id']]->entries[] = (object) array(
            'term'      => $e['term'],
            'note'      => $e['note'],
            'aka'       => $e['aka'],
            /* Dictionary-style lower-case starts are capitalised on the page. */
            'text'      => preg_replace_callback('/^[a-z]/', function ($m) {
                return strtoupper($m[0]);
            }, $e['definition']),
            'used'      => $e['used'],
            'reported'  => $e['reported'],
            'id'        => $e['anchor'],
            'key'       => $id,
            'container' => kop_glossary_store_node_path($state['nodes'], $e['node_id']),
        );
    }
    return $doc;
}

/** The page data built from (maybe changed) tables: array('data', 'errors'). */
function kop_glossary_store_build($state, $meta = null) {
    $out = kop_glossary_finish(kop_glossary_store_doc($state, $meta));
    if (is_array($out['data'])) {
        /* Notes and section ids are checked here too: the tree has no stored
         * id check of its own. */
        $seen = array();
        foreach ($state['nodes'] as $n) {
            $anchor = ($n['parent_id'] === 0 ? '' : 'g-') . $n['anchor'];
            if (isset($seen[$anchor])) {
                $out['errors'][] = 'Two sections or groups share the id "' . $anchor . '"; rename one.';
            }
            $seen[$anchor] = true;
        }
    }
    return $out;
}

/** The page data, cached until the next change. null when there is none. */
function kop_glossary_store_data() {
    if (!kop_glossary_store_ready()) {
        return null;
    }
    $rev = (int) get_option('kop_glossary_rev', 0);
    $cache = get_option('kop_glossary_cache');
    if (is_array($cache) && isset($cache['rev']) && (int) $cache['rev'] === $rev && is_array($cache['data'])) {
        return $cache['data'];
    }
    $built = kop_glossary_store_build(kop_glossary_store_load());
    $data = $built['data'];
    if (is_array($data) && !empty($data['sections'])) {
        update_option('kop_glossary_cache', array('rev' => $rev, 'data' => $data, 'errors' => $built['errors']), false);
        return $data;
    }
    return null;
}

/* ---- Writing ------------------------------------------------------------ */

/** Write one entry's rows ($id 0 inserts). Returns the entry id. Unchecked. */
function kop_glossary_store_write_entry($id, $snap, $now = null, $user = null) {
    global $wpdb;
    $now = $now === null ? kop_glossary_store_now() : $now;
    $user = $user === null ? kop_glossary_store_user() : $user;
    $row = array(
        'node_id'    => (int) $snap['node_id'],
        'anchor'     => (string) $snap['anchor'],
        'term'       => (string) $snap['term'],
        'qualifier'  => (string) $snap['note'],
        'definition' => (string) $snap['definition'],
        'updated_at' => $now,
        'updated_by' => (string) $user,
    );
    $exists = $id > 0 && (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . kop_glossary_table('entries') . ' WHERE id = %d', $id));
    if ($exists) {
        $wpdb->update(kop_glossary_table('entries'), $row, array('id' => (int) $id));
    } else {
        if ($id > 0) {
            $row = array('id' => (int) $id) + $row;          // Undo of a delete keeps the id
        }
        $row['created_at'] = $now;
        $wpdb->insert(kop_glossary_table('entries'), $row);
        $id = $id > 0 ? (int) $id : (int) $wpdb->insert_id;
    }
    $wpdb->delete(kop_glossary_table('aliases'), array('entry_id' => $id));
    $wpdb->delete(kop_glossary_table('tags'), array('entry_id' => $id));
    foreach (array_values((array) $snap['aka']) as $i => $alias) {
        $wpdb->insert(kop_glossary_table('aliases'), array('entry_id' => $id, 'position' => $i, 'alias' => (string) $alias));
    }
    foreach (array('used', 'reported') as $kind) {
        foreach (array_values((array) $snap[$kind]) as $i => $tag) {
            $wpdb->insert(kop_glossary_table('tags'), array(
                'entry_id' => $id,
                'kind'     => $kind,
                'position' => $i,
                'program'  => (string) $tag['program'],
                'note'     => isset($tag['note']) ? (string) $tag['note'] : '',
            ));
        }
    }
    return $id;
}

function kop_glossary_store_remove_entry($id) {
    global $wpdb;
    $wpdb->delete(kop_glossary_table('entries'), array('id' => (int) $id));
    $wpdb->delete(kop_glossary_table('aliases'), array('entry_id' => (int) $id));
    $wpdb->delete(kop_glossary_table('tags'), array('entry_id' => (int) $id));
}

/** Only the fields a snapshot is made of, in one order, for logs and comparing. */
function kop_glossary_snapshot($e) {
    $tags = function ($list) {
        $out = array();
        foreach ((array) $list as $t) {
            $tag = array('program' => (string) $t['program']);
            if (isset($t['note']) && (string) $t['note'] !== '') {
                $tag['note'] = (string) $t['note'];
            }
            $out[] = $tag;
        }
        return $out;
    };
    return array(
        'node_id'    => (int) $e['node_id'],
        'anchor'     => (string) $e['anchor'],
        'term'       => (string) $e['term'],
        'note'       => (string) $e['note'],
        'aka'        => array_values(array_map('strval', (array) $e['aka'])),
        'definition' => (string) $e['definition'],
        'used'       => $tags($e['used']),
        'reported'   => $tags($e['reported']),
    );
}

function kop_glossary_store_log($entry_id, $action, $term, $before, $after) {
    global $wpdb;
    $wpdb->insert(kop_glossary_table('log'), array(
        'entry_id'    => (int) $entry_id,
        'action'      => $action,
        'term'        => (string) $term,
        'before_json' => $before === null ? null : wp_json_encode($before, JSON_UNESCAPED_UNICODE),
        'after_json'  => $after === null ? null : wp_json_encode($after, JSON_UNESCAPED_UNICODE),
        'user'        => kop_glossary_store_user(),
        'created_at'  => kop_glossary_store_now(),
    ));
}

/** The "Updated" date on the page follows the newest change. */
function kop_glossary_store_touch_updated() {
    $meta = kop_glossary_store_meta();
    $today = function_exists('current_time') ? current_time('Y-m-d') : gmdate('Y-m-d');
    if ($meta['updated'] < $today) {
        $meta['updated'] = $today;
        update_option('kop_glossary_meta', $meta, false);
    }
}

/**
 * The anchor (#id) for an entry: its old one while the term and qualifier
 * stay, else the term, else the term plus the qualifier (or the group's
 * title) when another entry has the term already.
 */
function kop_glossary_store_anchor($state, $id, $snap) {
    $old = $id > 0 && isset($state['entries'][$id]) ? $state['entries'][$id] : null;
    if ($old && $old['term'] === $snap['term'] && $old['note'] === $snap['note'] && $old['anchor'] !== '') {
        return $old['anchor'];
    }
    $taken = array();
    foreach ($state['entries'] as $other_id => $e) {
        if ($other_id !== $id) {
            $taken[$e['anchor']] = true;
        }
    }
    $plain = kop_glossary_slugify($snap['term']);
    if ($plain !== '' && !isset($taken[$plain])) {
        return $plain;
    }
    $node = isset($state['nodes'][$snap['node_id']]) ? $state['nodes'][$snap['node_id']]['title'] : '';
    $qualified = kop_glossary_slugify($snap['term'] . ' ' . ($snap['note'] !== '' ? $snap['note'] : $node));
    if ($qualified !== '' && !isset($taken[$qualified])) {
        return $qualified;
    }
    for ($n = 2; $n < 100; $n++) {
        if (!isset($taken[$plain . '-' . $n])) {
            return $plain . '-' . $n;
        }
    }
    return '';
}

/**
 * Add ($id 0) or edit an entry. The snapshot's anchor is worked out here.
 *
 * @return array('id' => entry id, 'anchor' => its #id, 'errors' => list)
 */
function kop_glossary_store_save_entry($id, $snap) {
    if (!kop_glossary_store_ready() && $id > 0) {
        return array('id' => 0, 'anchor' => '', 'errors' => array('The glossary tables are empty.'));
    }
    $id = (int) $id;
    $state = kop_glossary_store_load();
    if ($id > 0 && !isset($state['entries'][$id])) {
        return array('id' => 0, 'anchor' => '', 'errors' => array('That entry is no longer in the glossary.'));
    }
    if (!isset($state['nodes'][(int) $snap['node_id']])) {
        return array('id' => 0, 'anchor' => '', 'errors' => array('Choose where the entry goes.'));
    }
    $snap['anchor'] = kop_glossary_store_anchor($state, $id, $snap);
    if ($snap['anchor'] === '') {
        return array('id' => 0, 'anchor' => '', 'errors' => array('The term has no letters or numbers to make a link from.'));
    }
    $snap = kop_glossary_snapshot($snap);
    $before = $id > 0 ? kop_glossary_snapshot($state['entries'][$id]) : null;
    if ($before === $snap) {
        return array('id' => $id, 'anchor' => $snap['anchor'], 'errors' => array(), 'unchanged' => true);
    }
    $state['entries'][$id > 0 ? $id : -1] = $snap;
    $built = kop_glossary_store_build($state);
    if ($built['errors']) {
        return array('id' => 0, 'anchor' => '', 'errors' => $built['errors']);
    }
    $new_id = kop_glossary_store_write_entry($id, $snap);
    kop_glossary_store_log($new_id, $id > 0 ? 'edit' : 'add', $snap['term'], $before, $snap);
    kop_glossary_store_touch_updated();
    kop_glossary_store_bump();
    return array('id' => $new_id, 'anchor' => $snap['anchor'], 'errors' => array());
}

/** @return array errors (empty when deleted) */
function kop_glossary_store_delete_entry($id) {
    $id = (int) $id;
    $state = kop_glossary_store_load();
    if (!isset($state['entries'][$id])) {
        return array('That entry is no longer in the glossary.');
    }
    $before = kop_glossary_snapshot($state['entries'][$id]);
    unset($state['entries'][$id]);
    $built = kop_glossary_store_build($state);
    if ($built['errors']) {
        return $built['errors'];
    }
    kop_glossary_store_remove_entry($id);
    kop_glossary_store_log($id, 'delete', $before['term'], $before, null);
    kop_glossary_store_touch_updated();
    kop_glossary_store_bump();
    return array();
}

/** The newest changes, newest first. */
function kop_glossary_store_recent($limit = 30) {
    global $wpdb;
    return (array) $wpdb->get_results($wpdb->prepare(
        'SELECT * FROM ' . kop_glossary_table('log') . " WHERE action <> 'import' ORDER BY id DESC LIMIT %d",
        (int) $limit
    ), ARRAY_A);
}

/**
 * Put an entry back as it was before a logged change, if it has not changed
 * since. Undoing an add deletes the entry, a delete brings it back with its
 * old id.
 *
 * @return array('errors' => list, 'term' => string)
 */
function kop_glossary_store_undo($log_id) {
    global $wpdb;
    $log = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . kop_glossary_table('log') . ' WHERE id = %d', (int) $log_id), ARRAY_A);
    if (!$log) {
        return array('errors' => array('That change is not in the log.'), 'term' => '');
    }
    if (!empty($log['undone_at'])) {
        return array('errors' => array('That change was undone already.'), 'term' => $log['term']);
    }
    $id = (int) $log['entry_id'];
    $before = $log['before_json'] !== null && $log['before_json'] !== '' ? json_decode($log['before_json'], true) : null;
    $after = $log['after_json'] !== null && $log['after_json'] !== '' ? json_decode($log['after_json'], true) : null;
    $state = kop_glossary_store_load();
    $now = isset($state['entries'][$id]) ? kop_glossary_snapshot($state['entries'][$id]) : null;
    if ($now !== ($after === null ? null : kop_glossary_snapshot($after))) {
        return array('errors' => array('"' . $log['term'] . '" has changed since. Undo the later change first, or edit it by hand.'), 'term' => $log['term']);
    }
    if ($before === null) {
        unset($state['entries'][$id]);
    } else {
        if (!isset($state['nodes'][(int) $before['node_id']])) {
            return array('errors' => array('The section "' . $log['term'] . '" was in is gone. Add the entry again by hand.'), 'term' => $log['term']);
        }
        foreach ($state['entries'] as $other => $e) {
            if ($other !== $id && $e['anchor'] === $before['anchor']) {
                return array('errors' => array('Another entry has taken the link #' . $before['anchor'] . ' since.'), 'term' => $log['term']);
            }
        }
        $state['entries'][$id] = kop_glossary_snapshot($before);
    }
    $built = kop_glossary_store_build($state);
    if ($built['errors']) {
        return array('errors' => $built['errors'], 'term' => $log['term']);
    }
    if ($before === null) {
        kop_glossary_store_remove_entry($id);
    } else {
        kop_glossary_store_write_entry($id, kop_glossary_snapshot($before));
    }
    $wpdb->update(kop_glossary_table('log'), array('undone_at' => kop_glossary_store_now()), array('id' => (int) $log_id));
    kop_glossary_store_bump();
    return array('errors' => array(), 'term' => $log['term']);
}

/* ---- Sections and groups ---------------------------------------------- */

/**
 * Add ($id 0) or change a section or group. $fields: parent_id (new ones
 * only), title, sources, notes (list of paragraphs).
 *
 * @return array('id' => node id, 'errors' => list)
 */
function kop_glossary_store_save_node($id, $fields) {
    global $wpdb;
    $id = (int) $id;
    $state = kop_glossary_store_load();
    if ($id > 0 && !isset($state['nodes'][$id])) {
        return array('id' => 0, 'errors' => array('That section is gone.'));
    }
    $title = kop_glossary_trim(preg_replace('/\s+/u', ' ', (string) $fields['title']));
    if ($title === '') {
        return array('id' => 0, 'errors' => array('The title is required.'));
    }
    $parent = $id > 0 ? $state['nodes'][$id]['parent_id'] : (int) ($fields['parent_id'] ?? 0);
    if ($parent > 0) {
        if (!isset($state['nodes'][$parent])) {
            return array('id' => 0, 'errors' => array('Choose the section the group goes under.'));
        }
        $depth = count(kop_glossary_store_node_path($state['nodes'], $parent));
        if ($depth >= 3) {
            return array('id' => 0, 'errors' => array('Groups go at most two levels under a section.'));
        }
    }
    $node = $id > 0 ? $state['nodes'][$id] : array('id' => -1, 'parent_id' => $parent, 'anchor' => '', 'position' => 0);
    if ($node['anchor'] === '' || ($id > 0 && $state['nodes'][$id]['title'] !== $title)) {
        $node['anchor'] = kop_glossary_slugify($title);
    }
    if ($id === 0) {
        $max = 0;
        foreach ($state['nodes'] as $n) {
            if ($n['parent_id'] === $parent) {
                $max = max($max, $n['position']);
            }
        }
        $node['position'] = $max + 1;
    }
    $node['title'] = $title;
    $node['sources'] = $parent > 0 ? kop_glossary_trim((string) ($fields['sources'] ?? '')) : '';
    $node['notes'] = array_values(array_filter(array_map('kop_glossary_trim', (array) ($fields['notes'] ?? array())), 'strlen'));
    $state['nodes'][$id > 0 ? $id : -1] = $node;
    $built = kop_glossary_store_build($state);
    if ($built['errors']) {
        return array('id' => 0, 'errors' => $built['errors']);
    }
    $row = array(
        'parent_id'  => $parent,
        'title'      => $title,
        'anchor'     => $node['anchor'],
        'sources'    => $node['sources'],
        'notes'      => wp_json_encode($node['notes'], JSON_UNESCAPED_UNICODE),
        'position'   => $node['position'],
        'updated_at' => kop_glossary_store_now(),
    );
    if ($id > 0) {
        $wpdb->update(kop_glossary_table('nodes'), $row, array('id' => $id));
    } else {
        $wpdb->insert(kop_glossary_table('nodes'), $row);
        $id = (int) $wpdb->insert_id;
    }
    kop_glossary_store_bump();
    return array('id' => $id, 'errors' => array());
}

/** Only an empty section or group can go. @return array errors */
function kop_glossary_store_delete_node($id) {
    global $wpdb;
    $state = kop_glossary_store_load();
    if (!isset($state['nodes'][(int) $id])) {
        return array('That section is gone.');
    }
    foreach ($state['nodes'] as $n) {
        if ($n['parent_id'] === (int) $id) {
            return array('Move or delete its groups first.');
        }
    }
    foreach ($state['entries'] as $e) {
        if ($e['node_id'] === (int) $id) {
            return array('Move or delete its entries first.');
        }
    }
    unset($state['nodes'][(int) $id]);
    $built = kop_glossary_store_build($state);
    if ($built['errors']) {
        return $built['errors'];
    }
    $wpdb->delete(kop_glossary_table('nodes'), array('id' => (int) $id));
    kop_glossary_store_bump();
    return array();
}

/** Swap a node with its neighbour above (-1) or below (1). */
function kop_glossary_store_move_node($id, $dir) {
    global $wpdb;
    $state = kop_glossary_store_load();
    if (!isset($state['nodes'][(int) $id])) {
        return array('That section is gone.');
    }
    $parent = $state['nodes'][(int) $id]['parent_id'];
    $siblings = array_values(array_filter($state['nodes'], function ($n) use ($parent) {
        return $n['parent_id'] === $parent;
    }));
    $at = null;
    foreach ($siblings as $i => $n) {
        if ($n['id'] === (int) $id) {
            $at = $i;
        }
    }
    $to = $at + ($dir < 0 ? -1 : 1);
    if ($at === null || !isset($siblings[$to])) {
        return array();
    }
    $tmp = $siblings[$at];
    $siblings[$at] = $siblings[$to];
    $siblings[$to] = $tmp;
    foreach ($siblings as $i => $n) {
        $wpdb->update(kop_glossary_table('nodes'), array('position' => $i + 1), array('id' => $n['id']));
    }
    kop_glossary_store_bump();
    return array();
}

/** The page title and intro paragraphs. @return array errors */
function kop_glossary_store_save_meta($fields) {
    $meta = kop_glossary_store_meta();
    $title = kop_glossary_trim((string) ($fields['title'] ?? $meta['title']));
    if ($title === '') {
        return array('The title is required.');
    }
    $meta['title'] = $title;
    $meta['intro'] = array_values(array_filter(array_map('kop_glossary_trim', (array) ($fields['intro'] ?? array())), 'strlen'));
    update_option('kop_glossary_meta', $meta, false);
    kop_glossary_store_bump();
    return array();
}

/**
 * The page data as a JSON file, for the open data downloads: the shape
 * glossary.json had (no row keys or container paths).
 */
function kop_glossary_store_export_file($path) {
    $data = kop_glossary_store_data();
    if (!$data) {
        return false;
    }
    $strip = function ($node) use (&$strip) {
        unset($node['key']);
        foreach ($node['entries'] as $i => $e) {
            unset($node['entries'][$i]['key'], $node['entries'][$i]['container']);
        }
        foreach ($node['groups'] as $i => $g) {
            $node['groups'][$i] = $strip($g);
        }
        return $node;
    };
    foreach ($data['sections'] as $i => $s) {
        $data['sections'][$i] = $strip($s);
    }
    return file_put_contents($path, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)) !== false;
}
