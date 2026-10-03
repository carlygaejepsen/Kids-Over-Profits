<?php
/**
 * Review inbox sources for the five types the Submissions Review page draws
 * itself (news, data, wiki, lawsuits, legislation; api/manage-submissions.php
 * approves and edits them). Registered 'native', so js/review-inbox.js adds
 * only the quick row to the page's own cards: rename, category, tags, "Move
 * to" another queue and "Fill empty fields with AI".
 *
 * AI: news through kop_enrich_news_row() (the News Processor's own reader),
 * lawsuits through kop_enrich_lawsuit_row() (the complaint extractor); both
 * fill only empty fields. Legislation and wiki use the generic filler.
 *
 * Move: a news link that is really a lawsuit or a bill (or the other way)
 * becomes a pending row in that queue through kop_ext_insert_*() (the same
 * inserts and duplicate check as the browser extension and Drive Docs); the
 * original is filed as rejected with a note. Undo takes the new row back
 * while nobody has reviewed it and restores the original's status.
 */

if (!defined('ABSPATH')) {
    exit;
}

/** Per-type columns for the quick row and the AI filler. */
function kop_rinbox_native_types() {
    return array(
        'news' => array(
            'label' => 'News', 'table' => 'news_submissions', 'status_col' => 'status', 'pending' => 'submitted', 'rejected' => 'rejected',
            'title' => 'article_title', 'tags' => 'json',
            'fields' => array(
                array('name' => 'article_title', 'label' => 'Title', 'type' => 'text'),
                array('name' => 'article_type', 'label' => 'Category', 'type' => 'select', 'category' => true,
                    'options' => kop_rinbox_options(array('general', 'expose', 'lawsuit', 'arrest', 'closure', 'event', 'corporate'))),
                array('name' => 'publication_name', 'label' => 'Outlet', 'type' => 'text'),
                array('name' => 'author', 'label' => 'Author', 'type' => 'text'),
                array('name' => 'publication_date', 'label' => 'Published (YYYY-MM-DD)', 'type' => 'text'),
                array('name' => 'summary', 'label' => 'Summary', 'type' => 'textarea'),
            ),
            'url' => 'article_url', 'moves' => array('lawsuit' => 'Lawsuits', 'legislation' => 'Legislation'),
        ),
        'lawsuit' => array(
            'label' => 'Lawsuits', 'table' => 'lawsuits', 'status_col' => 'publication_status', 'pending' => 'pending', 'rejected' => 'rejected',
            'title' => 'case_name', 'tags' => 'json',
            'fields' => array(
                array('name' => 'case_name', 'label' => 'Case name', 'type' => 'text'),
                array('name' => 'status', 'label' => 'Case status', 'type' => 'select', 'category' => true,
                    'options' => kop_rinbox_options(array('filed', 'in_progress', 'settled', 'dismissed', 'ruling', 'appeal', 'closed', 'unknown'))),
                array('name' => 'case_number', 'label' => 'Case number', 'type' => 'text'),
                array('name' => 'court', 'label' => 'Court', 'type' => 'text'),
                array('name' => 'jurisdiction', 'label' => 'Jurisdiction', 'type' => 'text'),
                array('name' => 'filing_date', 'label' => 'Filed (YYYY-MM-DD)', 'type' => 'text'),
                array('name' => 'summary', 'label' => 'Summary', 'type' => 'textarea'),
                array('name' => 'outcome', 'label' => 'Outcome', 'type' => 'textarea'),
            ),
            'url' => 'source_urls', 'moves' => array('news' => 'News'),
        ),
        'legislation' => array(
            'label' => 'Legislation', 'table' => 'legislation', 'status_col' => 'publication_status', 'pending' => 'pending', 'rejected' => 'rejected',
            'title' => 'bill_title', 'tags' => 'json',
            'fields' => array(
                array('name' => 'bill_title', 'label' => 'Bill title', 'type' => 'text'),
                array('name' => 'position', 'label' => 'Our position', 'type' => 'select', 'category' => true,
                    'options' => kop_rinbox_options(array('support', 'oppose', 'neutral', 'watch', 'unknown'))),
                array('name' => 'bill_number', 'label' => 'Bill number', 'type' => 'text'),
                array('name' => 'jurisdiction', 'label' => 'Jurisdiction', 'type' => 'text'),
                array('name' => 'chamber', 'label' => 'Chamber', 'type' => 'select',
                    'options' => kop_rinbox_options(array('house', 'senate', 'assembly', 'joint', 'federal_house', 'federal_senate', 'other', 'unknown'))),
                array('name' => 'session_year', 'label' => 'Session year', 'type' => 'text'),
                array('name' => 'status', 'label' => 'Bill status', 'type' => 'select',
                    'options' => kop_rinbox_options(array('proposed', 'introduced', 'in_committee', 'passed_house', 'passed_senate', 'signed', 'vetoed', 'dead', 'enacted', 'unknown'))),
                array('name' => 'sponsors', 'label' => 'Sponsors', 'type' => 'list'),
                array('name' => 'introduced_date', 'label' => 'Introduced (YYYY-MM-DD)', 'type' => 'text'),
                array('name' => 'last_action_text', 'label' => 'Latest action', 'type' => 'text'),
                array('name' => 'summary', 'label' => 'Summary', 'type' => 'textarea'),
            ),
            'url' => 'official_url', 'moves' => array('news' => 'News'),
        ),
        'wiki' => array(
            'label' => 'Wiki', 'table' => 'wiki_submissions', 'status_col' => 'status', 'pending' => 'submitted', 'rejected' => 'rejected',
            'title' => 'program_name', 'tags' => 'shared',
            'fields' => array(
                array('name' => 'program_name', 'label' => 'Program name', 'type' => 'text'),
                array('name' => 'program_type', 'label' => 'Program type', 'type' => 'text'),
                array('name' => 'organization', 'label' => 'Organization', 'type' => 'text'),
                array('name' => 'city_state', 'label' => 'City, state', 'type' => 'text'),
                array('name' => 'years_active', 'label' => 'Years active', 'type' => 'text'),
            ),
            'url' => '', 'moves' => array(),
        ),
        'data' => array(
            'label' => 'Data', 'table' => 'suggested_edits', 'status_col' => 'status', 'pending' => 'pending', 'rejected' => 'rejected',
            'title' => 'master_id', 'tags' => 'shared', 'fields' => array(), 'url' => '', 'moves' => array(),
        ),
    );
}

foreach (array_keys(kop_rinbox_native_types()) as $kop_rinbox_native_key) {
    kop_rinbox_register($kop_rinbox_native_key, function () use ($kop_rinbox_native_key) {
        $t = kop_rinbox_native_types()[$kop_rinbox_native_key];
        $type = $kop_rinbox_native_key;
        $spec = array(
            'label'  => $t['label'],
            'group'  => 'Submissions',
            'native' => true,
            'views'  => array('pending' => 'Pending'),
            'count'  => function () use ($t) {
                $st = kop_rinbox_pdo()->prepare("SELECT COUNT(*) FROM {$t['table']} WHERE {$t['status_col']} = ?");
                $st->execute(array($t['pending']));
                return (int) $st->fetchColumn();
            },
            'list'   => function (array $q) use ($type, $t) {
                $pdo = kop_rinbox_pdo();
                $st = $pdo->prepare("SELECT * FROM {$t['table']} WHERE {$t['status_col']} = ? ORDER BY id DESC LIMIT " . (int) $q['limit'] . ' OFFSET ' . (int) $q['offset']);
                $st->execute(array($t['pending']));
                $c = $pdo->prepare("SELECT COUNT(*) FROM {$t['table']} WHERE {$t['status_col']} = ?");
                $c->execute(array($t['pending']));
                return array('items' => array_map(function ($r) use ($type) { return kop_rinbox_native_item($type, $r); }, $st->fetchAll(PDO::FETCH_ASSOC)), 'total' => (int) $c->fetchColumn());
            },
            'get'    => function ($key) use ($type) {
                $r = kop_rinbox_native_row($type, $key);
                return $r ? kop_rinbox_native_item($type, $r) : null;
            },
            'act'    => function ($key, $action, array $params) use ($type) {
                return kop_rinbox_native_act($type, $key, $action, $params);
            },
        );
        if ($t['fields']) {
            $spec['save'] = function ($key, array $fields) use ($type) { return kop_rinbox_native_save($type, $key, $fields); };
        }
        if ($type === 'news') {
            $spec['ai_fill'] = function ($key) { return kop_rinbox_native_ai_enrich('news', $key); };
        } elseif ($type === 'lawsuit') {
            $spec['ai_fill'] = function ($key) { return kop_rinbox_native_ai_enrich('lawsuit', $key); };
        }
        if ($t['tags'] === 'json') {
            $spec['tags_get'] = function ($key) use ($type) {
                $r = kop_rinbox_native_row($type, $key);
                $v = $r ? json_decode((string) $r['tags'], true) : array();
                return is_array($v) ? $v : array();
            };
            $spec['tags_set'] = function ($key, array $tags) use ($type, $t) {
                if ($type === 'news') {
                    require_once get_stylesheet_directory() . '/api/news-tags.php';
                    $tags = kop_news_tags_normalize($tags);
                }
                kop_rinbox_pdo()->prepare("UPDATE {$t['table']} SET tags = ? WHERE id = ?")
                    ->execute(array(wp_json_encode(array_values($tags)), (int) $key));
                return $tags;
            };
        }
        return $spec;
    });
}
unset($kop_rinbox_native_key);

if (!function_exists('kop_rinbox_native_known_tags')) {
    /** Tag suggestions: the news vocabulary already in use. */
    function kop_rinbox_native_known_tags($tags) {
        try {
            foreach (kop_rinbox_pdo()->query("SELECT tags FROM news_submissions WHERE tags IS NOT NULL AND tags NOT IN ('', '[]') ORDER BY id DESC LIMIT 400") as $r) {
                foreach ((array) json_decode((string) $r['tags'], true) as $t) if (is_string($t)) $tags[] = $t;
            }
        } catch (Throwable $e) {
            // No suggestions is fine.
        }
        return $tags;
    }
    add_filter('kop_review_inbox_known_tags', 'kop_rinbox_native_known_tags');
}

function kop_rinbox_native_row($type, $key) {
    $t = kop_rinbox_native_types()[$type];
    $st = kop_rinbox_pdo()->prepare("SELECT * FROM {$t['table']} WHERE id = ?");
    $st->execute(array((int) $key));
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

/** The address a native row points to. */
function kop_rinbox_native_url($type, array $r) {
    $t = kop_rinbox_native_types()[$type];
    if ($t['url'] === '') return '';
    $v = (string) ($r[$t['url']] ?? '');
    if ($t['url'] === 'source_urls') {
        $list = json_decode($v, true);
        $v = is_array($list) ? (string) ($list[0] ?? '') : '';
    }
    if ($v === '' && $type === 'legislation') $v = (string) ($r['full_text_url'] ?? '');
    return preg_match('#^https?://#i', $v) ? $v : '';
}

function kop_rinbox_native_moves() {
    $log = get_option('kop_review_inbox_moves', array());
    return is_array($log) ? $log : array();
}

function kop_rinbox_native_item($type, array $r) {
    $t = kop_rinbox_native_types()[$type];
    $fields = array();
    foreach ($t['fields'] as $f) {
        $v = $r[$f['name']] ?? '';
        if (($f['type'] ?? '') === 'list') {
            $d = json_decode((string) $v, true);
            $v = is_array($d) ? array_values(array_filter($d, 'is_string')) : array();
        }
        $f['value'] = $v === null ? '' : $v;
        $fields[] = $f;
    }
    $moves = array();
    $pending = (string) $r[$t['status_col']] === $t['pending'];
    if ($pending) foreach ($t['moves'] as $id => $label) $moves[] = array('id' => $id, 'label' => 'Move to ' . $label);
    $actions = array();
    $log = kop_rinbox_native_moves()[$type . ':' . (int) $r['id']] ?? null;
    if ($log) {
        $actions[] = array('id' => 'unmove', 'label' => 'Undo move to ' . (kop_rinbox_native_types()[$log['to']]['label'] ?? $log['to']), 'style' => 'undo');
    }
    $title = (string) ($r[$t['title']] ?? '');
    return array(
        'key'          => (string) (int) $r['id'],
        'title'        => $title !== '' ? $title : '(untitled)',
        'url'          => kop_rinbox_native_url($type, $r),
        'text'         => $type === 'wiki' ? kop_rinbox_excerpt($r['generated_markdown'] ?? '', 3000) : '',
        'status'       => (string) $r[$t['status_col']],
        'status_label' => (string) $r[$t['status_col']],
        'fields'       => $fields,
        'moves'        => $moves,
        'actions'      => $actions,
        'moved'        => $log ? array('to' => $log['to'], 'id' => (int) $log['to_id']) : null,
    );
}

function kop_rinbox_native_save($type, $key, array $fields) {
    $t = kop_rinbox_native_types()[$type];
    $r = kop_rinbox_native_row($type, $key);
    if (!$r) throw new RuntimeException('That submission is gone.');
    $set = array();
    $vals = array();
    foreach ($t['fields'] as $f) {
        $name = $f['name'];
        if (!array_key_exists($name, $fields)) continue;
        $v = $fields[$name];
        $kind = $f['type'] ?? 'text';
        if ($kind === 'select') {
            $v = (string) $v;
            if ($v !== '' && !array_key_exists($v, $f['options'])) throw new RuntimeException($f['label'] . ': not one of the choices.');
        } elseif ($kind === 'list') {
            $v = wp_json_encode(array_values(array_filter(array_map('trim', is_array($v) ? array_map('strval', $v) : preg_split('/\n+/', (string) $v)), 'strlen')));
        } else {
            $v = trim((string) $v);
        }
        if ($name === $t['title'] && $v === '') throw new RuntimeException($f['label'] . ' cannot be empty.');
        if (preg_match('/_date$/', $name) && $v !== '' && !preg_match('/^\d{4}(-\d{2}(-\d{2})?)?$/', $v)) {
            throw new RuntimeException($f['label'] . ': write it as YYYY-MM-DD.');
        }
        if (preg_match('/_date$/', $name) && $v === '') $v = null;
        $set[] = "`$name` = ?";
        $vals[] = $v;
    }
    if (!$set) return array('message' => 'Nothing to save.');
    $vals[] = (int) $key;
    kop_rinbox_pdo()->prepare("UPDATE {$t['table']} SET " . implode(', ', $set) . ' WHERE id = ?')->execute($vals);
    return array('message' => 'Saved.');
}

function kop_rinbox_native_act($type, $key, $action, array $params) {
    if ($action === 'move') return kop_rinbox_native_move($type, $key, (string) ($params['to'] ?? ''));
    if ($action === 'unmove') return kop_rinbox_native_unmove($type, $key);
    throw new RuntimeException('Approve and reject this with the buttons on its card.');
}

function kop_rinbox_native_move($type, $key, $to) {
    $types = kop_rinbox_native_types();
    $t = $types[$type];
    if (!isset($t['moves'][$to])) throw new RuntimeException('It cannot move there.');
    $r = kop_rinbox_native_row($type, $key);
    if (!$r) throw new RuntimeException('That submission is gone.');
    if ((string) $r[$t['status_col']] !== $t['pending']) throw new RuntimeException('Only a pending item can move to another queue.');
    $url = kop_rinbox_native_url($type, $r);
    if ($url === '') throw new RuntimeException('It has no web address to move.');
    if (!function_exists('kop_ext_insert_news')) throw new RuntimeException('The queue inserts are not loaded.');
    kop_ext_load_record_libs();
    $pdo = kop_rinbox_pdo();
    $p = array(
        'url'       => $url,
        'title'     => (string) $r[$t['title']],
        'type'      => array('news' => 'article', 'lawsuit' => 'lawsuit', 'legislation' => 'legislation')[$to],
        'site_name' => (string) ($r['publication_name'] ?? (parse_url($url, PHP_URL_HOST) ?: '')),
        'facility'  => '',
    );
    if (!empty($r['publication_date'])) $p['published'] = (string) $r['publication_date'];
    $dupes = kop_ext_find_duplicates($pdo, $p);
    foreach ($dupes as $d) {
        // The item itself is a "duplicate" of its own queue; anything else is a real one.
        if (!($d['type'] === $type && (int) $d['id'] === (int) $r['id'])) {
            throw new RuntimeException('Already in the ' . $d['type'] . ' records (#' . (int) $d['id'] . ').');
        }
    }
    $reviewer = kop_rinbox_reviewer();
    $note = 'Moved from the ' . $t['label'] . ' queue (#' . (int) $r['id'] . ') by ' . $reviewer . '.';
    $quiet = function () { return false; };
    add_filter('kop_notify_admins_enabled', $quiet);
    try {
        if ($to === 'news') $new = kop_ext_insert_news($pdo, $p, $reviewer . ' (moved)', $note);
        elseif ($to === 'lawsuit') $new = kop_ext_insert_lawsuit($pdo, $p, $reviewer . ' (moved)', $note);
        else $new = kop_ext_insert_legislation($pdo, $p, $reviewer . ' (moved)', $note);
    } finally {
        remove_filter('kop_notify_admins_enabled', $quiet);
    }
    $notes = trim((string) ($r['reviewer_notes'] ?? '') . "\nMoved to " . $types[$to]['label'] . ' #' . (int) $new . '.');
    $pdo->prepare("UPDATE {$t['table']} SET {$t['status_col']} = ?, reviewer_notes = ? WHERE id = ?")
        ->execute(array($t['rejected'], $notes, (int) $r['id']));
    $log = kop_rinbox_native_moves();
    $log[$type . ':' . (int) $r['id']] = array('to' => $to, 'to_id' => (int) $new, 'prev_status' => $t['pending'],
        'prev_notes' => (string) ($r['reviewer_notes'] ?? ''), 'by' => $reviewer, 'at' => time());
    update_option('kop_review_inbox_moves', $log, false);
    return array('message' => 'Moved. It is waiting in ' . $types[$to]['label'] . ' as #' . (int) $new . ', and filed here as rejected with a note. Undo moves it back.');
}

function kop_rinbox_native_unmove($type, $key) {
    $types = kop_rinbox_native_types();
    $t = $types[$type];
    $log = kop_rinbox_native_moves();
    $k = $type . ':' . (int) $key;
    if (empty($log[$k])) throw new RuntimeException('There is no move to undo.');
    $m = $log[$k];
    $pdo = kop_rinbox_pdo();
    $to = $types[$m['to']];
    $st = $pdo->prepare("DELETE FROM {$to['table']} WHERE id = ? AND {$to['status_col']} = ?");
    $st->execute(array((int) $m['to_id'], $to['pending']));
    if (!$st->rowCount()) {
        throw new RuntimeException($to['label'] . ' #' . (int) $m['to_id'] . ' has been reviewed already, so it stays there.');
    }
    if ($m['to'] === 'lawsuit') $pdo->prepare('DELETE FROM lawsuit_facility_links WHERE lawsuit_id = ?')->execute(array((int) $m['to_id']));
    $pdo->prepare("UPDATE {$t['table']} SET {$t['status_col']} = ?, reviewer_notes = ? WHERE id = ?")
        ->execute(array($m['prev_status'], $m['prev_notes'] !== '' ? $m['prev_notes'] : null, (int) $key));
    unset($log[$k]);
    update_option('kop_review_inbox_moves', $log, false);
    return array('message' => 'Moved back. It is pending here again.');
}

/** News and lawsuits: the site's own readers, which fill only empty fields. */
function kop_rinbox_native_ai_enrich($type, $key) {
    if (!kop_rinbox_load_enrich()) throw new RuntimeException('The AI reader is not installed.');
    $pdo = kop_rinbox_pdo();
    $res = $type === 'news' ? kop_enrich_news_row($pdo, (int) $key, true) : kop_enrich_lawsuit_row($pdo, (int) $key, true);
    if (empty($res['ok'])) throw new RuntimeException('The AI could not fill it: ' . ($res['error'] ?? 'unknown error'));
    $filled = (array) ($res['filled'] ?? array());
    return array('filled' => $filled, 'message' => $filled
        ? 'Filled: ' . implode(', ', array_map(function ($f) { return str_replace('_', ' ', $f); }, $filled)) . '.'
        : 'The AI found nothing to add; every field it reads already has something in it.');
}
