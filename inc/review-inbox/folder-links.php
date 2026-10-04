<?php
/**
 * Review inbox source: pairs of FileBird folders whose names are two known
 * names of one facility, which may be one facility filed under two names (the
 * Link Folders tool, api/link-folders.php). "Same facility" links them
 * (kop_folder_links_link()): every facility document feed then shows both
 * folders' files. "Alternate name" keeps them apart and off the list
 * (kop_folder_links_dismiss()). Both can be taken back from their tabs
 * (kop_folder_links_unlink() / kop_folder_links_restore()).
 *
 * The suggestions read every facility record's names, so they are worked out
 * once an hour (transient kop_rinbox_folder_links); every action here clears it.
 *
 * Keys: "a:b" (folder ids, a < b), on every tab.
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once dirname(__DIR__) . '/folder-links.php';

kop_rinbox_register('folder-links', function () {
    if (!function_exists('kop_folder_links_suggestions')) return null;
    return array(
        'label'    => 'Folder links',
        'group'    => 'Suggestions to check',
        'help'     => 'Two document folders whose names are both names of one facility (a rename, a rebrand, a past name). "Same facility" links them: the facility\'s documents then show the files of both folders. "Alternate name" keeps them apart and takes the pair off the list. Both can be taken back on their tabs.',
        'views'    => array('suggested' => 'Suggested', 'linked' => 'Linked', 'dismissed' => 'Alternate names'),
        'tool_url' => get_stylesheet_directory_uri() . '/api/link-folders.php',
        'count'    => function () {
            return count(kop_rinbox_flinks_suggestions());
        },
        'view_counts' => function () {
            return array('suggested' => count(kop_rinbox_flinks_suggestions()), 'linked' => count(kop_folder_links_rows('links')),
                'dismissed' => count(kop_folder_links_rows('dismissals')));
        },
        'list'     => 'kop_rinbox_flinks_list',
        'get'      => 'kop_rinbox_flinks_get',
        'act'      => 'kop_rinbox_flinks_act',
        'tools'    => array(array('id' => 'link_any', 'label' => 'Link these two folders', 'style' => 'approve',
            'help' => 'Link any two folders as one facility, also ones not suggested here. Type part of a folder name and pick it. To link three or more at once, use the full Link Folders screen.',
            'confirm' => 'Link these two folders as the same facility? Its documents then show the files of both.',
            'params' => array(
                array('name' => 'a', 'label' => 'Folder', 'type' => 'text', 'value' => '', 'lookup' => 'folder', 'placeholder' => 'Folder name or #id'),
                array('name' => 'b', 'label' => 'and folder', 'type' => 'text', 'value' => '', 'lookup' => 'folder', 'placeholder' => 'Folder name or #id'),
                array('name' => 'note', 'label' => 'Note', 'type' => 'text', 'value' => '', 'optional' => true),
            ))),
        'tool'     => 'kop_rinbox_flinks_tool',
        'lookup'   => 'kop_rinbox_flinks_lookup',
        // Nothing for AI to look up: the folders and names come from the library.
        'ai_fill'  => function ($key) {
            return array('filled' => array(), 'message' => 'Nothing here for AI to fill.');
        },
    );
});

/** Every suggested pair, worked out once an hour. */
function kop_rinbox_flinks_suggestions() {
    $cached = get_transient('kop_rinbox_folder_links');
    if (is_array($cached)) return $cached;
    $out = array();
    foreach (kop_folder_links_suggestions(kop_folder_links_folders(), 0) as $s) $out[$s['a'] . ':' . $s['b']] = $s;
    set_transient('kop_rinbox_folder_links', $out, HOUR_IN_SECONDS);
    return $out;
}

/** Pairs of a decided tab: 'links' or 'dismissals', key => row. */
function kop_rinbox_flinks_decided($which) {
    $out = array();
    foreach (kop_folder_links_rows($which) as $r) $out[min((int) $r->folder_a, (int) $r->folder_b) . ':' . max((int) $r->folder_a, (int) $r->folder_b)] = $r;
    return $out;
}

function kop_rinbox_flinks_list(array $q) {
    $by_id = kop_folder_links_folders();
    $counts = kop_folder_links_file_counts();
    if ($q['view'] === 'linked') $rows = kop_rinbox_flinks_decided('links');
    elseif ($q['view'] === 'dismissed') $rows = kop_rinbox_flinks_decided('dismissals');
    else $rows = kop_rinbox_flinks_suggestions();
    $items = array();
    foreach ($rows as $key => $r) {
        $it = kop_rinbox_flinks_item((string) $key, $q['view'], $r, $by_id, $counts);
        if ($q['search'] !== '' && mb_strpos(mb_strtolower($it['title'] . ' ' . $it['subtitle'] . ' ' . $it['search_text']), mb_strtolower($q['search'])) === false) continue;
        $items[] = $it;
    }
    return array('items' => array_slice($items, (int) $q['offset'], (int) $q['limit']), 'total' => count($items));
}

function kop_rinbox_flinks_get($key) {
    $key = (string) $key;
    foreach (array('linked' => kop_rinbox_flinks_decided('links'), 'dismissed' => kop_rinbox_flinks_decided('dismissals'), 'suggested' => kop_rinbox_flinks_suggestions()) as $view => $rows) {
        if (isset($rows[$key])) return kop_rinbox_flinks_item($key, $view, $rows[$key], kop_folder_links_folders(), kop_folder_links_file_counts());
    }
    return null;
}

function kop_rinbox_flinks_item($key, $view, $r, array $by_id, array $counts) {
    list($a, $b) = array_map('intval', explode(':', $key));
    $name = function ($id) use ($by_id) { return isset($by_id[$id]) ? (string) $by_id[$id]->name : 'folder #' . $id . ' (deleted)'; };
    $compare = kop_rinbox_compare_rows(array('Folder #' . $a, 'Folder #' . $b), array(
        'Name'     => array($name($a), $name($b)),
        'Where'    => array(kop_folder_links_path($a, $by_id), kop_folder_links_path($b, $by_id)),
        'Files'    => array((string) (int) ($counts[$a] ?? 0), (string) (int) ($counts[$b] ?? 0)),
    ));
    $details = array();
    if ($view === 'suggested') {
        $details[] = array('label' => 'Both are names of', 'value' => (string) $r['basis']);
        $details[] = array('label' => 'Known as', 'value' => implode(' / ', (array) $r['matched_names']));
        $actions = array(
            array('id' => 'link', 'label' => 'Same facility: link them', 'style' => 'approve',
                'params' => array(array('name' => 'note', 'label' => 'Note (e.g. renamed 2014)', 'type' => 'text', 'value' => '', 'optional' => true))),
            array('id' => 'dismiss', 'label' => 'Alternate name: keep apart', 'style' => 'reject'),
        );
        $text = 'If these are one facility under two names, link them: its documents then show the files of both folders. If the names are only related, or are separate programs, keep them apart.';
        $label = 'Suggested';
    } elseif ($view === 'linked') {
        if (!empty($r->note)) $details[] = array('label' => 'Note', 'value' => (string) $r->note);
        if (function_exists('kop_get_equivalent_folder_ids')) {
            $group = array();
            $files = 0;
            foreach ((array) kop_get_equivalent_folder_ids($a) as $gid) {
                $group[] = kop_folder_links_path($gid, $by_id) . ' (' . (int) ($counts[(int) $gid] ?? 0) . ')';
                $files += (int) ($counts[(int) $gid] ?? 0);
            }
            if ($group) $details[] = array('label' => 'Shown together', 'value' => implode('; ', $group) . ': ' . $files . ' files');
        }
        $actions = array(array('id' => 'unlink', 'label' => 'Remove the link', 'style' => 'undo',
            'confirm' => 'Remove this link? The folders keep their own files; the documents stop showing them together.'));
        $text = 'Linked: the facility\'s documents show the files of both folders.';
        $label = 'Linked';
    } else {
        $actions = array(array('id' => 'restore', 'label' => 'Suggest it again', 'style' => 'undo'));
        $text = 'Marked as alternate names: kept apart and not suggested.';
        $label = 'Alternate names';
    }
    return array(
        'key'          => $key,
        'title'        => $name($a) . '  /  ' . $name($b),
        'subtitle'     => $view === 'suggested' ? 'Both names of ' . $r['basis'] : (!empty($r->created_at) ? 'since ' . substr((string) $r->created_at, 0, 10) : ''),
        'text'         => $text,
        'created'      => is_object($r) ? (string) ($r->created_at ?? '') : '',
        'compare'      => $compare,
        'details'      => $details,
        'search_text'  => kop_folder_links_path($a, $by_id) . ' ' . kop_folder_links_path($b, $by_id) . (is_array($r) ? ' ' . implode(' ', (array) $r['matched_names']) : ''),
        'status'       => $view,
        'status_label' => $label,
        'actions'      => $actions,
    );
}

function kop_rinbox_flinks_act($key, $action, array $params) {
    if (!preg_match('/^(\d+):(\d+)$/', (string) $key, $m)) throw new RuntimeException('That is not a pair of folders.');
    $a = (int) $m[1];
    $b = (int) $m[2];
    try {
        switch ($action) {
            case 'link':
                $res = kop_folder_links_link(array($a, $b), (string) ($params['note'] ?? ''), kop_folder_links_folders());
                if (!$res['ok']) throw new RuntimeException(implode(' ', $res['messages']));
                return array('message' => 'Linked. The facility\'s documents show the files of both folders now. "Remove the link" is on the Linked tab.');
            case 'dismiss':
                $res = kop_folder_links_dismiss($a, $b, kop_folder_links_folders());
                if (!$res['ok']) throw new RuntimeException($res['message']);
                return array('message' => 'Kept apart as alternate names. "Suggest it again" is on the Alternate names tab.');
            case 'unlink':
                $res = kop_folder_links_unlink($a, $b);
                if (!$res['ok']) throw new RuntimeException($res['message']);
                return array('message' => 'Link removed. The folders keep their own files.');
            case 'restore':
                $res = kop_folder_links_restore($a, $b);
                if (!$res['ok']) throw new RuntimeException($res['message']);
                return array('message' => 'Back in the suggestions.');
        }
        throw new RuntimeException('Unknown action.');
    } finally {
        delete_transient('kop_rinbox_folder_links');
    }
}

/** Folders whose name has $q in it: [{value: "#id", label: path}]. */
function kop_rinbox_flinks_lookup($name, $q) {
    if ($name !== 'folder') return array();
    $by_id = kop_folder_links_folders();
    $q = mb_strtolower(trim(ltrim(trim($q), '#')));
    $out = array();
    foreach ($by_id as $id => $f) {
        if ((string) $id !== $q && mb_strpos(mb_strtolower((string) $f->name), $q) === false) continue;
        $out[] = array('value' => '#' . $id, 'label' => kop_folder_links_path($id, $by_id) . ' (#' . $id . ')');
        if (count($out) >= 20) break;
    }
    return $out;
}

/** "#12", "12" or an exact folder name (only one folder of that name) -> its id. */
function kop_rinbox_flinks_find($text, array $by_id) {
    $text = trim(ltrim(trim((string) $text), '#'));
    if ($text === '') throw new RuntimeException('Pick both folders.');
    if (ctype_digit($text)) {
        if (!isset($by_id[(int) $text])) throw new RuntimeException('No folder #' . $text . '.');
        return (int) $text;
    }
    $ids = array();
    foreach ($by_id as $id => $f) if (strcasecmp(trim((string) $f->name), $text) === 0) $ids[] = (int) $id;
    if (count($ids) !== 1) throw new RuntimeException($ids ? 'More than one folder is called "' . $text . '": pick it from the list (#id).' : 'No folder is called "' . $text . '".');
    return $ids[0];
}

function kop_rinbox_flinks_tool($id, array $params) {
    if ($id !== 'link_any') throw new RuntimeException('Unknown tool.');
    $by_id = kop_folder_links_folders();
    $a = kop_rinbox_flinks_find($params['a'] ?? '', $by_id);
    $b = kop_rinbox_flinks_find($params['b'] ?? '', $by_id);
    try {
        $res = kop_folder_links_link(array($a, $b), (string) ($params['note'] ?? ''), $by_id);
    } finally {
        delete_transient('kop_rinbox_folder_links');
    }
    if (!$res['ok']) throw new RuntimeException(implode(' ', $res['messages']));
    return array('message' => implode(' ', $res['messages']));
}
