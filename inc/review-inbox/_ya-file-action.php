<?php
/**
 * "File under a young adult program" on every review inbox card.
 *
 * kop_rinbox_finish_items() adds the action to every card still waiting for
 * a decision (it offers an approve or a reject) in every queue except the
 * ones in kop_rinbox_ya_file_skip_sources() (queues about something else,
 * and Woodbury Facts and Fornits, which file on a young adult program their
 * own way). It puts what the card says on a program in
 * young_adult_programs (inc/young-adult-programs.php): a fact under People,
 * Incidents, History or About the program, citing the card's link, or only
 * the link under "More about this program". The program is picked from the
 * list (the card's own name is picked when it is one), or named: a name no
 * program has makes a new one, hidden until someone shows it.
 *
 * The card then leaves its queue's waiting list for "Already on file"
 * (filed items are kept in the kop_review_ya_filed option and merged into the
 * queue's 'on_file' by kop_rinbox_ya_filed_wrap()). Recently done has an
 * exact Undo: the fact or link comes off, a program the action made goes
 * when nothing else is on it, and the card is back in the waiting list.
 *
 * The actions are answered before the queue's own 'act'
 * (kop_rinbox_cross_act()), so no source has to know about them.
 */

if (!defined('ABSPATH')) {
    exit;
}

const KOP_RINBOX_YA_FILE_ACTIONS = array('ya_file', 'ya_unfile');

/** Queues that never get the action: about something else, or filing on young adult programs their own way. */
function kop_rinbox_ya_file_skip_sources() {
    return array(
        'young-adult-programs', 'woodbury-facts', 'fornits', 'conflicts', 'data-audit',
        'people-merge', 'facility-merge', 'folder-links', 'map-renames', 'map-years', 'program-homes',
        'highlight-summaries', 'bug-reports', 'glossary-feedback', 'featured-nominations', 'status_checks', 'publish-approved',
        // KOP's own wiki text and which record a wiki entry is about: nothing in them to file on a program
        'wiki-updates', 'wiki-links',
    );
}

/** The filed cards: [source => [key => {ya_id, name, group, done, by, at}]]. */
function kop_rinbox_ya_filed() {
    $all = get_option('kop_review_ya_filed', array());
    return is_array($all) ? $all : array();
}

function kop_rinbox_ya_filed_set($source, $key, $info) {
    $all = kop_rinbox_ya_filed();
    if ($info === null) {
        unset($all[$source][(string) $key]);
        if (empty($all[$source])) unset($all[$source]);
    } else {
        $all[$source][(string) $key] = $info;
    }
    update_option('kop_review_ya_filed', $all, false);
}

/** The programs, read once a request: options for the picker and [name key => id] for the card's own name. */
function kop_rinbox_ya_file_programs($reset = false) {
    static $memo = null;
    if ($memo !== null && !$reset) return $memo;
    $memo = array('options' => array('0' => 'A new program (name it below)'), 'keys' => array());
    $pdo = function_exists('kop_ya_pdo') ? kop_ya_pdo() : null;
    if (!$pdo || !kop_ya_ready($pdo)) return $memo;
    foreach (kop_ya_all($pdo, null) as $p) {
        $memo['options'][(string) (int) $p['id']] = (string) $p['name'] . ($p['review'] === 'pending' ? ' (hidden)' : '');
        foreach (array_merge(array($p['name']), kop_ya_lines($p['other_names'])) as $n) {
            $k = kop_ya_key($n);
            if ($k !== '' && !isset($memo['keys'][$k])) $memo['keys'][$k] = (int) $p['id'];
        }
    }
    return $memo;
}

/** Where a fact can go on the program, plus "only its link". */
function kop_rinbox_ya_file_groups($has_url) {
    $out = array(
        'details'  => 'About the program',
        'history'  => 'History (opened, closed, owners, names)',
        'staff'    => 'People',
        'incident' => 'Incidents',
    );
    if ($has_url) $out['link'] = 'Only its link (More about this program)';
    return $out;
}

/** The action for a card of $source, or null. */
function kop_rinbox_ya_file_action($source, array $item) {
    if (!function_exists('kop_ya_pdo') || in_array($source, kop_rinbox_ya_file_skip_sources(), true)) return null;
    $src = kop_rinbox_sources()[$source] ?? array();
    if (!empty($src['native'])) return null; // the page draws those cards itself; their "Move to" has young adult programs
    if (isset(kop_rinbox_ya_filed()[$source][(string) $item['key']])) return null; // filed already: Undo is in Recently done
    // Only while the card waits for a decision: it still offers an approve or a reject.
    $open = false;
    foreach ((array) ($item['actions'] ?? array()) as $a) {
        if (in_array($a['id'] ?? '', array('ya_file', 'young_adult'), true)) return null; // the queue's own (Facilities from News)
        if (in_array($a['style'] ?? '', array('approve', 'reject'), true)) $open = true;
    }
    if (!$open) return null;
    $progs = kop_rinbox_ya_file_programs();
    $title = trim((string) ($item['title'] ?? ''));
    $pick = '';
    foreach (array($title, (string) ($item['facility']['name'] ?? '')) as $n) {
        $k = $n !== '' ? kop_ya_key($n) : '';
        if ($k !== '' && isset($progs['keys'][$k])) { $pick = (string) $progs['keys'][$k]; break; }
    }
    $url = trim((string) ($item['url'] ?? ''));
    $has_url = (bool) preg_match('#^https?://#i', $url);
    // 'ask': the button opens a small form; "File it" runs the action.
    return array('id' => 'ya_file', 'label' => 'File under a young adult program', 'style' => 'neutral', 'ask' => true, 'submit' => 'File it',
        'help' => 'For a program for people 18 and older: puts what this card says on that program (Young Adult Programs page)'
            . ($has_url ? ', citing its link,' : '') . ' and moves the card to Already on file. A new program waits hidden until you show it. Undo is in Recently done.',
        'params' => array(
            array('name' => 'ya_id', 'label' => 'Program', 'type' => 'select', 'options' => $progs['options'], 'value' => $pick !== '' ? $pick : '0'),
            array('name' => 'ya_name', 'label' => 'or new program named', 'type' => 'text', 'value' => '', 'optional' => true),
            array('name' => 'ya_group', 'label' => 'File it under', 'type' => 'select', 'options' => kop_rinbox_ya_file_groups($has_url), 'value' => 'details'),
            array('name' => 'ya_text', 'label' => 'The line on the program (edit it to say what matters)', 'type' => 'textarea', 'value' => $title, 'optional' => true),
        ));
}

/** The program the params pick, or a new hidden one: [row, created]. */
function kop_rinbox_ya_file_program(PDO $pdo, array $params, $origin) {
    $id = (int) ($params['ya_id'] ?? 0);
    if ($id > 0) {
        $p = kop_ya_get($pdo, $id);
        if (!$p) throw new RuntimeException('That program is gone (someone may have deleted it).');
        return array($p, false);
    }
    $name = trim(sanitize_text_field((string) ($params['ya_name'] ?? '')));
    if ($name === '') throw new RuntimeException('Pick the program, or type a name for a new one.');
    if ($p = kop_ya_find_by_name($pdo, $name)) return array($p, false);
    $id = kop_ya_save($pdo, array('name' => $name, 'review' => 'pending', 'source' => 'Filed from the review inbox (' . $origin . ')'), 0, kop_rinbox_reviewer());
    return array(kop_ya_get($pdo, $id), true);
}

/**
 * Answer one of KOP_RINBOX_YA_FILE_ACTIONS for an item of $source; null when
 * $action is not one of them.
 */
function kop_rinbox_ya_file_act($source, $key, $action, array $params) {
    if (!in_array($action, KOP_RINBOX_YA_FILE_ACTIONS, true)) return null;
    $pdo = function_exists('kop_ya_pdo') ? kop_ya_pdo() : null;
    if (!$pdo) throw new RuntimeException('The young adult programs records are not available.');
    kop_ya_install($pdo);

    if ($action === 'ya_unfile') {
        $done = (array) ($params['done'] ?? array());
        if (empty($done['id'])) throw new RuntimeException('Nothing to undo.');
        if (($params['group'] ?? '') === 'link') {
            kop_rdest_take_back_young_adult($done); // also deletes a program it made that holds nothing else
        } else {
            kop_ya_remove_fact($pdo, $done);
            $p = kop_ya_get($pdo, (int) $done['id']);
            if ($p && !empty($params['created']) && $p['review'] === 'pending' && !kop_ya_facts($p) && trim((string) $p['links']) === '') {
                kop_ya_delete($pdo, (int) $p['id']);
            }
        }
        kop_rinbox_ya_filed_set($source, $key, null);
        kop_rinbox_ya_file_programs(true);
        return array('message' => 'Undone: taken off the young adult program, and the card is back in its waiting list.');
    }

    $item = kop_rinbox_get_item($source, $key);
    if (isset(kop_rinbox_ya_filed()[$source][(string) $key])) throw new RuntimeException('This card is filed already.');
    $src = kop_rinbox_source($source);
    list($p, $created) = kop_rinbox_ya_file_program($pdo, $params, (string) $src['label']);
    $url = trim((string) ($item['url'] ?? ''));
    $has_url = (bool) preg_match('#^https?://#i', $url);
    $title = trim((string) ($item['title'] ?? ''));
    $group = (string) ($params['ya_group'] ?? 'details');
    if (!isset(kop_rinbox_ya_file_groups($has_url)[$group])) {
        if ($group === 'link') throw new RuntimeException('This card has no web address to put on the program.');
        $group = 'details';
    }
    try {
        if ($group === 'link') {
            // The link alone, as "Move to" files it (kop_rdest_put_young_adult()).
            $done = kop_rdest_put_young_adult(array('url' => $url, 'title' => $title, 'ya_id' => (int) $p['id']), kop_rinbox_reviewer());
            $done['created'] = $created;
            $where = 'its link is on';
        } else {
            $text = trim(preg_replace('/\s+/u', ' ', (string) ($params['ya_text'] ?? '')));
            if ($text === '') $text = $title;
            if ($text === '') throw new RuntimeException('Write the line to put on the program.');
            $cites = array();
            if ($has_url) {
                $cite = ($title !== '' && kop_ya_key($title) !== kop_ya_key($text)) ? $title
                    : (function_exists('kop_url_label') ? kop_url_label($url) : (string) parse_url($url, PHP_URL_HOST));
                $cites[] = array('label' => '', 'number' => '', 'page' => 0, 'url' => $url, 'cite' => mb_substr($cite, 0, 200));
            }
            $fact = array('key' => 'rinbox-' . substr(md5($source . '|' . $key), 0, 16), 'group' => $group, 'label' => mb_substr($text, 0, 2000),
                'cites' => $cites, 'by' => kop_rinbox_reviewer(), 'at' => current_time('mysql', true));
            $done = kop_ya_add_fact($pdo, (int) $p['id'], $fact);
            $where = 'it is on';
        }
    } catch (Throwable $e) {
        if ($created) kop_ya_delete($pdo, (int) $p['id']);
        throw $e;
    }
    kop_rinbox_ya_filed_set($source, $key, array('ya_id' => (int) $p['id'], 'name' => (string) $p['name'], 'group' => $group,
        'by' => kop_rinbox_reviewer(), 'at' => current_time('mysql', true)));
    kop_rinbox_ya_file_programs(true);
    $groups = kop_rinbox_ya_file_groups(true);
    return array(
        'message' => 'Filed: ' . $where . ' the young adult program "' . $p['name'] . '"' . ($group !== 'link' ? ' (' . $groups[$group] . ')' : '')
            . ($created ? ', a new program kept hidden until you show it (Young adult programs queue)' : '') . '. The card is under Already on file; Undo is in Recently done.',
        'item'    => null,
        'undo'    => array('action' => 'ya_unfile', 'params' => array('done' => $done, 'group' => $group, 'created' => $created)),
    );
}

/**
 * A source with filed cards: those cards join its 'on_file' (so they leave the
 * waiting list and its count for "Already on file"). Cards its queue no longer
 * has are forgotten.
 */
function kop_rinbox_ya_filed_wrap($key, array $src) {
    if (!empty($src['native']) || !empty($src['on_file_in_list'])) return $src;
    $filed = kop_rinbox_ya_filed()[$key] ?? array();
    if (!$filed) return $src;
    $orig = $src['on_file'] ?? null;
    $get = $src['get'] ?? null;
    $src['on_file'] = function () use ($orig, $get, $key) {
        $out = $orig ? (array) call_user_func($orig) : array();
        foreach (kop_rinbox_ya_filed()[$key] ?? array() as $k => $info) {
            $there = true;
            try {
                if ($get) $there = (bool) call_user_func($get, (string) $k);
            } catch (Throwable $e) {
                $there = false;
            }
            if (!$there) {
                kop_rinbox_ya_filed_set($key, $k, null);
                continue;
            }
            $out[(string) $k] = array('label' => 'Filed under the young adult program ' . $info['name'],
                'url' => home_url('/young-adult-programs/#kop-ya-program-' . (int) $info['ya_id']));
        }
        return $out;
    };
    return $src;
}
