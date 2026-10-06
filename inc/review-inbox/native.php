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
            'url' => 'article_url', 'moves' => true,
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
            'url' => 'source_urls', 'moves' => true,
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
            'url' => 'official_url', 'moves' => true,
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
            'url' => '', 'moves' => false,
        ),
        'data' => array(
            'label' => 'Data', 'table' => 'suggested_edits', 'status_col' => 'status', 'pending' => 'pending', 'rejected' => 'rejected',
            'title' => 'master_id', 'tags' => 'shared', 'fields' => array(), 'url' => '', 'moves' => false,
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
        $spec['origins'] = function (array $q) use ($type) { return kop_rinbox_native_origin_counts($type, $q['status']); };
        if ($t['fields']) {
            $spec['save'] = function ($key, array $fields) use ($type) { return kop_rinbox_native_save($type, $key, $fields); };
        }
        if ($type === 'news') {
            $spec['ai_fill'] = function ($key, $text = '') { return kop_rinbox_native_ai_enrich('news', $key, $text); };
        } elseif ($type === 'lawsuit') {
            $spec['ai_fill'] = function ($key, $text = '') { return kop_rinbox_native_ai_enrich('lawsuit', $key, $text); };
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
    if ($pending && $t['moves'] && kop_rinbox_native_url($type, $r) !== '') {
        // Every other destination: News, Lawsuits, Legislation, Industry PR, facility website or resource.
        $moves = kop_rdest_moves(array($type));
    }
    $actions = array();
    $log = kop_rinbox_native_moves()[$type . ':' . (int) $r['id']] ?? null;
    if ($log) {
        $targets = kop_rdest_targets();
        $actions[] = array('id' => 'unmove', 'label' => 'Undo move to ' . ($targets[$log['to']]['label'] ?? $log['to']), 'style' => 'undo');
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
        'moved'        => $log ? array('to' => $log['to']) : null,
        'approve_help' => kop_rinbox_native_help($type)[0],
        'reject_help'  => kop_rinbox_native_help($type)[1],
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

/**
 * What Approve and Reject on the page's own cards do, in a sentence each,
 * shown on the card so the reviewer knows what they are approving.
 */
function kop_rinbox_native_help($type) {
    $reject = 'Nothing on the site changes; it moves to Rejected (Undo in Recently done).';
    $help = array(
        'news'        => array('Puts this article on the site: the news list and the pages of the facilities it names.', $reject),
        'lawsuit'     => array('Publishes this case at once: the lawsuits list and the pages of the facilities it involves.', $reject),
        'legislation' => array('Publishes this bill at once on the legislation pages.', $reject),
        'wiki'        => array("Puts this write-up on the program's facility page.", $reject),
        'data'        => array('Writes these changes into the facility record. Recently done cannot take this one back.', 'Nothing in the record changes; the suggestion moves to Rejected.'),
    );
    return $help[$type] ?? array('', $reject);
}

function kop_rinbox_native_act($type, $key, $action, array $params) {
    if ($action === 'move') return kop_rinbox_native_move($type, $key, (string) ($params['to'] ?? ''), $params);
    if ($action === 'unmove') return kop_rinbox_native_unmove($type, $key);
    if ($action === 'restore') return kop_rinbox_native_restore($type, $key, (string) ($params['status'] ?? ''));
    throw new RuntimeException('Approve and reject this with the buttons on its card.');
}

/** Undo from Recently done: put the status back to what it was before approve / reject. */
function kop_rinbox_native_restore($type, $key, $status) {
    $t = kop_rinbox_native_types()[$type];
    $allowed = array('submitted', 'pending', 'approved', 'published', 'rejected', 'promotional', 'draft');
    if (!in_array($status, $allowed, true)) throw new RuntimeException('Unknown status to go back to.');
    if (!kop_rinbox_native_row($type, $key)) throw new RuntimeException('That submission is gone.');
    kop_rinbox_pdo()->prepare("UPDATE {$t['table']} SET {$t['status_col']} = ? WHERE id = ?")->execute(array($status, (int) $key));
    return array('message' => 'Back to ' . $status . '.');
}

function kop_rinbox_native_move($type, $key, $to, array $params = array()) {
    $types = kop_rinbox_native_types();
    $t = $types[$type];
    $targets = kop_rdest_targets();
    if ($type === 'news' && $to === 'indigenous') {
        return kop_rinbox_native_move_to_school($key, (int) ($params['school_id'] ?? 0));
    }
    if (!$t['moves'] || !isset($targets[$to]) || $to === $type) throw new RuntimeException('It cannot move there.');
    $r = kop_rinbox_native_row($type, $key);
    if (!$r) throw new RuntimeException('That submission is gone.');
    if ((string) $r[$t['status_col']] !== $t['pending']) throw new RuntimeException('Only a pending item can move to another queue.');
    $url = kop_rinbox_native_url($type, $r);
    $pdo = kop_rinbox_pdo();
    $reviewer = kop_rinbox_reviewer();
    $prev_notes = (string) ($r['reviewer_notes'] ?? '');

    if ($type === 'news' && $to === 'promo') {
        // An article filed as Industry PR stays this row, in the internal index.
        kop_rdest_promo_enum_ensure($pdo);
        $pdo->prepare("UPDATE news_submissions SET status = 'promotional', reviewed_by = ?, reviewed_at = CURRENT_TIMESTAMP WHERE id = ?")
            ->execute(array($reviewer, (int) $r['id']));
        $done = array('to' => 'promo', 'self' => true);
        $message = 'Filed as Industry PR (internal index, never public).';
    } else {
        $done = kop_rdest_put($to, array(
            'url' => $url, 'title' => (string) $r[$t['title']],
            'site_name' => (string) ($r['publication_name'] ?? (parse_url($url, PHP_URL_HOST) ?: '')),
            'published' => (string) ($r['publication_date'] ?? ''),
            'facility_id' => (int) ($params['facility_id'] ?? 0), 'kind' => (string) ($params['kind'] ?? ''),
            'school_id' => (int) ($params['school_id'] ?? 0),
            'source_note' => 'Sent in as ' . strtolower($t['label']) . ' (#' . (int) $r['id'] . ')',
            'note' => 'Moved from the ' . $t['label'] . ' queue (#' . (int) $r['id'] . ') by ' . $reviewer . '.',
            'self' => array($type, (int) $r['id']), 'via' => 'moved',
        ), $reviewer);
        $message = $done['message'];
        $pdo->prepare("UPDATE {$t['table']} SET {$t['status_col']} = ?, reviewer_notes = ? WHERE id = ?")
            ->execute(array($t['rejected'], trim($prev_notes . "\n" . $message), (int) $r['id']));
        if ($type === 'news' && in_array($to, array('website', 'resource'), true)) {
            // The note the page's own "Move to facility record" button leaves, so its card shows the move too.
            $json = json_decode((string) $r['json_data'], true);
            $json = is_array($json) ? $json : array();
            $json['movedTo'] = array('target' => $done['target'], 'facility_id' => $done['facility_id'], 'url' => $done['url'],
                'kind' => $done['kind'], 'facility_name' => $done['facility_name']);
            $pdo->prepare('UPDATE news_submissions SET json_data = ? WHERE id = ?')->execute(array(wp_json_encode($json), (int) $r['id']));
        }
        $message .= ' Filed here as rejected with a note.';
        if (function_exists('kop_followup_mark_moved')) {
            kop_followup_mark_moved(kop_followup_kind_for_native($type), (int) $r['id']);
        }
    }
    $log = kop_rinbox_native_moves();
    $log[$type . ':' . (int) $r['id']] = array('to' => $to, 'done' => $done, 'prev_status' => $t['pending'],
        'prev_notes' => $prev_notes, 'by' => $reviewer, 'at' => time());
    update_option('kop_review_inbox_moves', $log, false);
    return array('message' => $message . ' Undo moves it back.');
}

function kop_rinbox_native_move_to_school($key, $school_id) {
    $r = kop_rinbox_native_row('news', $key);
    if (!$r) throw new RuntimeException('That submission is gone.');
    if ((string) $r['status'] !== 'submitted') throw new RuntimeException('Only a pending item can move to another queue.');
    $pdo = kop_ischools_pdo();
    if (!$pdo) throw new RuntimeException('The Indian boarding schools records are not available.');
    kop_ischools_install($pdo);
    $name = 'the schools in general';
    if ($school_id > 0) {
        $school = kop_ischools_get($pdo, $school_id);
        if (!$school) throw new RuntimeException('That school is gone.');
        $name = (string) $school['name'];
    }
    $reviewer = kop_rinbox_reviewer();
    $prev_notes = (string) ($r['reviewer_notes'] ?? '');
    kop_ischools_link_news($pdo, $school_id, (int) $r['id'], $reviewer);
    $message = 'Filed on the Indian boarding schools page under ' . $name . '; it stays off the news list.';
    $pdo->prepare("UPDATE news_submissions SET status = 'approved', reviewed_by = ?, reviewed_at = CURRENT_TIMESTAMP, reviewer_notes = ? WHERE id = ?")
        ->execute(array($reviewer, trim($prev_notes . "
" . $message), (int) $r['id']));
    if (function_exists('kop_followup_mark_moved')) {
        kop_followup_mark_moved(kop_followup_kind_for_native('news'), (int) $r['id']);
    }
    $log = kop_rinbox_native_moves();
    $log['news:' . (int) $r['id']] = array('to' => 'indigenous', 'done' => array('to' => 'indigenous', 'self' => true, 'school_id' => $school_id),
        'prev_status' => 'submitted', 'prev_notes' => $prev_notes, 'by' => $reviewer, 'at' => time());
    update_option('kop_review_inbox_moves', $log, false);
    return array('message' => $message . ' Undo moves it back.');
}

function kop_rinbox_native_unmove($type, $key) {
    $types = kop_rinbox_native_types();
    $t = $types[$type];
    $log = kop_rinbox_native_moves();
    $k = $type . ':' . (int) $key;
    if (empty($log[$k])) throw new RuntimeException('There is no move to undo.');
    $m = $log[$k];
    $done = $m['done'] ?? array('to' => $m['to'], 'id' => (int) ($m['to_id'] ?? 0));
    if (empty($done['self'])) kop_rdest_take_back($done);
    if (!empty($done['self']) && ($done['to'] ?? '') === 'indigenous' && ($pdo_s = kop_ischools_pdo())) {
        kop_ischools_unlink_news($pdo_s, (int) ($done['school_id'] ?? 0), (int) $key);
    }
    $pdo = kop_rinbox_pdo();
    $pdo->prepare("UPDATE {$t['table']} SET {$t['status_col']} = ?, reviewer_notes = ? WHERE id = ?")
        ->execute(array($m['prev_status'], $m['prev_notes'] !== '' ? $m['prev_notes'] : null, (int) $key));
    if ($type === 'news') {
        $r = kop_rinbox_native_row('news', $key);
        $json = $r ? json_decode((string) $r['json_data'], true) : null;
        if (is_array($json) && isset($json['movedTo'])) {
            unset($json['movedTo']);
            $pdo->prepare('UPDATE news_submissions SET json_data = ? WHERE id = ?')->execute(array(wp_json_encode($json), (int) $key));
        }
    }
    unset($log[$k]);
    update_option('kop_review_inbox_moves', $log, false);
    return array('message' => 'Moved back. It is pending here again.');
}

/** News and lawsuits: the site's own readers, which fill only empty fields. */
function kop_rinbox_native_ai_enrich($type, $key, $text = '') {
    if (!kop_rinbox_load_enrich()) throw new RuntimeException('The AI reader is not installed.');
    $pdo = kop_rinbox_pdo();
    $res = $type === 'news' ? kop_enrich_news_row($pdo, (int) $key, true, $text) : kop_enrich_lawsuit_row($pdo, (int) $key, true, $text);
    if (empty($res['ok'])) throw new RuntimeException('The AI could not fill it: ' . ($res['error'] ?? 'unknown error'));
    $filled = (array) ($res['filled'] ?? array());
    return array('filled' => $filled, 'message' => $filled
        ? 'Filled: ' . implode(', ', array_map(function ($f) { return str_replace('_', ' ', $f); }, $filled)) . '.'
        : 'The AI found nothing to add; every field it reads already has something in it.');
}

/* ---- Where an item came from ("Came from" filter) -------------------------- */

/**
 * Origins of the page's own types: [key => [label, SQL condition]], read from
 * who sent it (submitted_by) and the note the scraper or import left
 * (submission_notes). 'people' is everything else: the public forms and what
 * an admin typed in. Shared by api/manage-submissions.php (the list) and the
 * kop/v1/review-inbox/origins route (the counts).
 */
function kop_rinbox_native_origins($type) {
    $by = 'IFNULL(submitted_by, \'\')';
    $has_notes = in_array($type, array('news', 'wiki'), true);
    $notes = $has_notes ? 'IFNULL(submission_notes, \'\')' : "''";
    $all = array(
        'scraper-google' => array('News scraper: Google News', "$notes LIKE 'auto-discovery via google-news%'"),
        'scraper-reddit' => array('News scraper: Reddit', "$notes LIKE 'auto-discovery via reddit%'"),
        'scraper-rescued' => array('News scraper: sent by hand from Scraper finds', "$by LIKE '%(Scraper finds import)%'"),
        'gdocs'          => array('Your Google Docs', "$by LIKE '%(Google Docs import)%' AND $notes NOT LIKE 'HEAL archive%' AND $notes NOT LIKE 'r/troubledteens wiki%'"),
        'heal'           => array('HEAL archive', "$by LIKE '%import)%' AND $notes LIKE 'HEAL archive%'"),
        'wiki'           => array('r/troubledteens wiki', "$by LIKE '%import)%' AND $notes LIKE 'r/troubledteens wiki%'"),
        'sciad'          => array('SCIAD NET', "$by LIKE '%(SCIAD NET import)%'"),
        'fornits'        => array('Fornits', "$by LIKE '%(Fornits import)%'"),
        'extension'      => array('Browser extension', "$by LIKE '%(browser extension)%'"),
        'moved'          => array('Moved from another queue', "($by LIKE '%(moved)%' OR $by LIKE '%(review inbox)%')"),
        'claude'         => array('Imported by Claude', "$by LIKE '%import via Claude%'"),
        'oldsite'        => array('Old site posts', "$by IN ('wp-news-import', 'bulk-upload', 'reimport-regenerated')"),
    );
    if (!$has_notes) {
        unset($all['scraper-google'], $all['scraper-reddit'], $all['heal'], $all['wiki']);
        $all['gdocs'][1] = "$by LIKE '%(Google Docs import)%'";
    }
    if ($type === 'data') return array();
    $others = array();
    foreach ($all as $o) $others[] = '(' . $o[1] . ')';
    $all['people'] = array('People and admins', 'NOT (' . implode(' OR ', $others) . ')');
    return $all;
}

/** The SQL condition for one origin, or '' for none / unknown. */
function kop_rinbox_native_origin_where($type, $origin) {
    $all = kop_rinbox_native_origins($type);
    return isset($all[$origin]) ? '(' . $all[$origin][1] . ')' : '';
}

/** [{key, label, count}] for the origins with something in $status ('' = every status). */
function kop_rinbox_native_origin_counts($type, $status) {
    $t = kop_rinbox_native_types()[$type];
    $all = kop_rinbox_native_origins($type);
    if (!$all) return array();
    $cols = array();
    foreach ($all as $key => $o) $cols[] = 'SUM(CASE WHEN ' . $o[1] . ' THEN 1 ELSE 0 END) AS `' . $key . '`';
    $where = '';
    $params = array();
    if ($status !== '') {
        if ($status === 'submitted') $status = $t['pending'];
        $where = "WHERE {$t['status_col']} = ?";
        $params[] = $status;
    }
    $st = kop_rinbox_pdo()->prepare('SELECT ' . implode(', ', $cols) . " FROM {$t['table']} $where");
    $st->execute($params);
    $row = $st->fetch(PDO::FETCH_ASSOC) ?: array();
    $out = array();
    foreach ($all as $key => $o) {
        $n = (int) ($row[$key] ?? 0);
        if ($n > 0) $out[] = array('key' => $key, 'label' => $o[0], 'count' => $n);
    }
    return $out;
}

/* ---- What the old Lawsuit, Legislation and News Processor pages did -------- */
/* api/manage-submissions.php calls these, so the Submissions Review page can do  */
/* everything those pages did: filter by place, sort by date, edit every field.   */

/**
 * Jurisdiction and level filters for the lawsuit and legislation lists, as the
 * old admin pages had them: array(SQL conditions, named params). Level is not
 * stored; "federal" is jurisdiction 'Federal' (as in api/save-legislation.php).
 */
function kop_rinbox_native_list_filters($type, array $q) {
    $where = array();
    $params = array();
    if (!in_array($type, array('lawsuit', 'legislation'), true)) return array($where, $params);
    $j = trim((string) ($q['jurisdiction'] ?? ''));
    if ($j !== '') {
        $where[] = 'jurisdiction = :jurisdiction';
        $params[':jurisdiction'] = $j;
    }
    $level = strtolower(trim((string) ($q['level'] ?? '')));
    if ($type === 'legislation' && $level === 'federal') {
        $where[] = "jurisdiction = 'Federal'";
    } elseif ($type === 'legislation' && $level === 'state') {
        $where[] = "(jurisdiction IS NULL OR jurisdiction <> 'Federal')";
    }
    return array($where, $params);
}

/** ORDER BY for the list: newest added (default) or by the record's own date, as the old pages sorted. */
function kop_rinbox_native_list_order($type, $sort) {
    $dates = array('lawsuit' => 'filing_date', 'legislation' => 'introduced_date', 'news' => 'publication_date');
    if ($sort === 'date' && isset($dates[$type])) {
        return "ORDER BY {$dates[$type]} DESC, created_at DESC";
    }
    return 'ORDER BY created_at DESC';
}

/**
 * The News Processor's "Article type and details" fields, kept in the
 * article's json_data (api/save-news-submission.php stores them there).
 */
function kop_rinbox_native_news_detail_keys() {
    return array(
        'plaintiffs', 'defendants', 'legalRep', 'dateFiled', 'jurisdiction', 'pressReleases',
        'relatedCoverage',
        'staffMemberName', 'arrestFacilityName', 'misconductDates', 'charges', 'caseStatus',
        'closureFacilityName', 'closureLocation', 'closureDate', 'closureContext',
        'corporateFacilityNames', 'corporateLocation', 'keyPersonnel', 'ownership',
        'needsAlternateTitle',
    );
}

/** Put edited detail fields into a news row's json_data (text kept as typed, trimmed). */
function kop_rinbox_native_news_details_merge(array $json, array $fields) {
    foreach (kop_rinbox_native_news_detail_keys() as $k) {
        if (!array_key_exists($k, $fields)) continue;
        $v = $fields[$k];
        if ($k === 'needsAlternateTitle') {
            $json[$k] = (bool) $v && $v !== 'false';
            continue;
        }
        $json[$k] = is_array($v) ? implode("\n", array_map('strval', $v)) : trim((string) $v);
    }
    return $json;
}

/**
 * A typed date the way the old save endpoints read it: '' is no date,
 * YYYY-MM-DD stays, anything else strtotime() can read becomes YYYY-MM-DD,
 * and what it cannot read is no date.
 */
function kop_rinbox_native_date($v) {
    $v = trim((string) $v);
    if ($v === '') return null;
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) return $v;
    $ts = strtotime($v);
    return $ts ? date('Y-m-d', $ts) : null;
}

/**
 * After a lawsuit is edited: the step api/save-lawsuit.php runs on every save.
 * Find a FileBird folder from the linked facilities when none is set, and file
 * the case documents there. Pending and rejected rows are skipped: unreviewed
 * uploads stay out of the media library until the case is approved.
 * Returns array('folder' => id or 0, 'filed' => count).
 */
function kop_rinbox_native_lawsuit_file_docs(PDO $pdo, $id) {
    $st = $pdo->prepare('SELECT case_name, document_urls, filebird_folder_id, publication_status FROM lawsuits WHERE id = ?');
    $st->execute(array((int) $id));
    $law = $st->fetch(PDO::FETCH_ASSOC);
    if (!$law || in_array((string) $law['publication_status'], array('pending', 'rejected'), true)) {
        return array('folder' => 0, 'filed' => 0);
    }
    $dir = get_stylesheet_directory() . '/api/';
    require_once $dir . 'news-mentions.php';
    require_once $dir . 'lawsuit-facility-links.php';
    $folder = (int) ($law['filebird_folder_id'] ?? 0);
    if (!$folder) {
        $ids = $pdo->prepare('SELECT facility_id FROM lawsuit_facility_links WHERE lawsuit_id = ?');
        $ids->execute(array((int) $id));
        $facility_ids = array_map('intval', $ids->fetchAll(PDO::FETCH_COLUMN));
        if ($facility_ids) {
            $folder = (int) kop_lawsuit_resolve_facility_folder($pdo, $facility_ids);
            if ($folder) {
                $pdo->prepare('UPDATE lawsuits SET filebird_folder_id = ? WHERE id = ?')->execute(array($folder, (int) $id));
            }
        }
    }
    $filed = 0;
    if ($folder) {
        $docs = json_decode((string) $law['document_urls'], true);
        $filed = kop_lawsuit_file_documents(is_array($docs) ? $docs : array(), $folder, (string) $law['case_name']);
    }
    return array('folder' => $folder, 'filed' => $filed);
}
