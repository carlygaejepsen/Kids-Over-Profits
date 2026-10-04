<?php
/**
 * Review inbox source: Indian boarding, residential and mission schools
 * (inc/indigenous-schools.php, screen in inc/indigenous-schools-admin.php).
 * The news scan files a school it finds as review = 'pending'; Approve lists
 * it on /indian-boarding-schools/, Delete drops it (kop_ischools_delete()),
 * edits go through kop_ischools_save(). Everything the old screen does is
 * here too:
 *
 *   key '<id>'   a school: file an article under it or take one off
 *                (kop_ischools_link_news() / kop_ischools_unlink_news())
 *   key '0'      "News about the schools in general" (school_id 0)
 *   key 'c<id>'  a name the news scan set aside that looks like one of these
 *                schools: "Add as a school" (kop_ischools_add_from_candidate())
 *   tools        add a school by hand; move a record out of the facility data
 *                (kop_ischools_move_facility())
 */

if (!defined('ABSPATH')) {
    exit;
}

kop_rinbox_register('indigenous-schools', function () {
    if (!function_exists('kop_ischools_save')) return null;
    $page = function_exists('get_page_by_path') && defined('KOP_ISCHOOLS_PAGE') ? get_page_by_path(KOP_ISCHOOLS_PAGE) : null;
    return array(
        'label'    => 'Indigenous schools',
        'group'    => 'Found by the news scans',
        'help'     => 'Indian boarding and residential schools the news scan found. Check the details, then Approve to list the school on the Indian Boarding Schools page'
            . ($page && $page->post_status !== 'publish' ? ' (the page is still a draft: only admins can see it)' : '')
            . '. These are never TTI facilities. File an article under a school by its number, title or web address.',
        'views'    => array(
            'pending'  => 'Waiting for review',
            'approved' => 'Listed on the page',
            'general'  => 'News about the schools in general',
            'names'    => 'Names the news scan set aside',
        ),
        'view_counts' => 'kop_rinbox_ischools_view_counts',
        'tool_url' => admin_url('admin.php?page=kop-indigenous-schools'),
        'tools'    => array(
            array('id' => 'add', 'label' => 'Add a school', 'style' => 'neutral',
                'help' => 'Makes a new school record, listed on the page. Fill in the rest with Edit details on its card (Listed tab).',
                'params' => array(
                    array('name' => 'name', 'label' => 'Name', 'type' => 'text', 'value' => ''),
                    array('name' => 'country', 'label' => 'Country', 'type' => 'text', 'value' => 'United States', 'optional' => true),
                    array('name' => 'region', 'label' => 'State or province', 'type' => 'text', 'value' => '', 'optional' => true),
                    array('name' => 'city', 'label' => 'Town or city', 'type' => 'text', 'value' => '', 'optional' => true),
                )),
            array('id' => 'move', 'label' => 'Move here from the facility data', 'style' => 'neutral',
                'help' => 'If one of these schools was saved as a troubled teen facility, move it: its articles come with it, and it stops being a facility record.',
                'confirm' => 'Move this record out of the facility data? It will no longer be a facility record.',
                'params' => array(array('name' => 'facility_id', 'label' => 'Facility record', 'type' => 'facility', 'value' => ''))),
        ),
        'tool'     => 'kop_rinbox_ischools_tool',
        'count'    => function () {
            $pdo = kop_ischools_pdo();
            if (!$pdo || !kop_ischools_ready($pdo)) return 0;
            return (int) $pdo->query("SELECT COUNT(*) FROM indigenous_schools WHERE review = 'pending'")->fetchColumn();
        },
        'list'     => 'kop_rinbox_ischools_list',
        'get'      => 'kop_rinbox_ischools_get_item',
        'act'      => 'kop_rinbox_ischools_act',
        'save'     => 'kop_rinbox_ischools_save',
    );
});

function kop_rinbox_ischools_pdo() {
    $pdo = kop_ischools_pdo();
    if (!$pdo) throw new RuntimeException('The records database is not reachable.');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    kop_ischools_install($pdo);
    return $pdo;
}

function kop_rinbox_ischools_get_item($key) {
    $pdo = kop_rinbox_ischools_pdo();
    $key = (string) $key;
    if ($key === '0') return kop_rinbox_ischools_general_item();
    if (preg_match('/^c(\d+)$/', $key, $m)) {
        foreach (kop_ischools_set_aside_names($pdo) as $r) {
            if ((int) $r['id'] === (int) $m[1]) return kop_rinbox_ischools_name_item($r);
        }
        return null;
    }
    $s = kop_ischools_get($pdo, (int) $key);
    return $s ? kop_rinbox_ischools_item($s) : null;
}

function kop_rinbox_ischools_view_counts(array $q = array()) {
    $pdo = kop_rinbox_ischools_pdo();
    $by = array();
    foreach ($pdo->query('SELECT review, COUNT(*) AS n FROM indigenous_schools GROUP BY review') as $r) $by[$r['review']] = (int) $r['n'];
    return array(
        'pending'  => $by['pending'] ?? 0,
        'approved' => $by['approved'] ?? 0,
        'general'  => count(kop_rinbox_ischools_articles($pdo, 0)),
        'names'    => count(kop_ischools_set_aside_names($pdo)),
    );
}

function kop_rinbox_ischools_list(array $q) {
    $pdo = kop_rinbox_ischools_pdo();
    if ($q['view'] === 'general') {
        return array('items' => array(kop_rinbox_ischools_general_item()), 'total' => 1);
    }
    if ($q['view'] === 'names') {
        $rows = array_values(array_filter(kop_ischools_set_aside_names($pdo), function ($r) use ($q) {
            return $q['search'] === '' || stripos($r['mention'] . ' ' . $r['article_title'], $q['search']) !== false;
        }));
        return array('items' => array_map('kop_rinbox_ischools_name_item', array_slice($rows, (int) $q['offset'], (int) $q['limit'])), 'total' => count($rows));
    }
    $where = 'review = ?';
    $params = array($q['view'] === 'approved' ? 'approved' : 'pending');
    if ($q['search'] !== '') {
        $where .= ' AND (name LIKE ? OR other_names LIKE ? OR region LIKE ? OR city LIKE ?)';
        $like = '%' . $q['search'] . '%';
        array_push($params, $like, $like, $like, $like);
    }
    $st = $pdo->prepare("SELECT COUNT(*) FROM indigenous_schools WHERE $where");
    $st->execute($params);
    $total = (int) $st->fetchColumn();
    // Waiting: newest first. Listed: by name, as the old screen lists them.
    $order = $params[0] === 'pending' ? 'created_at DESC, id DESC' : 'name';
    $st = $pdo->prepare("SELECT * FROM indigenous_schools WHERE $where ORDER BY $order LIMIT " . (int) $q['limit'] . ' OFFSET ' . (int) $q['offset']);
    $st->execute($params);
    return array('items' => array_map('kop_rinbox_ischools_item', $st->fetchAll(PDO::FETCH_ASSOC)), 'total' => $total);
}

/** The articles filed under one school, 0 = the schools in general (every status, as the admin screen shows them). */
function kop_rinbox_ischools_articles(PDO $pdo, $id) {
    $st = $pdo->prepare('SELECT n.id, n.article_title, n.publication_name, n.publication_date, n.article_url, n.status
                           FROM indigenous_school_news l JOIN news_submissions n ON n.id = l.news_id
                          WHERE l.school_id = ? ORDER BY n.publication_date DESC, n.id DESC');
    $st->execute(array((int) $id));
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/** Links, a details line and the file / take-off buttons for the articles of one school (0: in general). */
function kop_rinbox_ischools_article_parts(PDO $pdo, $school_id) {
    $links = array();
    $lines = array();
    $options = array();
    foreach (kop_rinbox_ischools_articles($pdo, $school_id) as $a) {
        $title = $a['article_title'] ?: 'Untitled';
        if (preg_match('#^https?://#i', (string) $a['article_url'])) $links[] = array('label' => $title, 'url' => $a['article_url']);
        $lines[] = '#' . (int) $a['id'] . ' ' . $title . ' (' . trim($a['publication_name'] . ' ' . $a['publication_date'])
            . (!in_array($a['status'], array('approved', 'published'), true) ? '; not shown: the article is ' . $a['status'] : '') . ')';
        $options[(string) (int) $a['id']] = '#' . (int) $a['id'] . ' ' . mb_substr($title, 0, 80);
    }
    $actions = array(array('id' => 'link_news', 'label' => 'File an article', 'style' => 'neutral',
        'params' => array(array('name' => 'news', 'label' => 'Article number, title or web address', 'type' => 'text', 'value' => ''))));
    if ($options) {
        $actions[] = array('id' => 'unlink_news', 'label' => 'Take off', 'style' => 'undo',
            'params' => array(array('name' => 'news_id', 'label' => 'Article', 'type' => 'select', 'options' => $options, 'value' => '')));
    }
    return array($links, $lines, $actions);
}

function kop_rinbox_ischools_item(array $s) {
    $pdo = kop_rinbox_ischools_pdo();
    $pending = $s['review'] === 'pending';
    $place = kop_ischools_meta_line($s);
    $country = kop_ischools_country_label($s['country']);
    list($links, $lines, $article_actions) = kop_rinbox_ischools_article_parts($pdo, (int) $s['id']);
    $hidden = array_values(array_filter($lines, function ($l) { return strpos($l, '; not shown:') !== false; }));
    $text = trim(implode("\n", array_filter(array(
        $s['source'],
        $s['notes'] !== null && $s['notes'] !== '' ? kop_rinbox_excerpt($s['notes'], 400) : '',
        $hidden ? count($hidden) . ' of its articles are not shown on the page until they are approved.' : '',
    ))));
    $actions = array();
    if ($pending) {
        $actions[] = array('id' => 'approve', 'label' => 'Approve', 'style' => 'approve');
    } else {
        $actions[] = array('id' => 'unapprove', 'label' => 'Take off the page', 'style' => 'undo');
    }
    $actions[] = array('id' => 'delete', 'label' => 'Delete', 'style' => 'reject',
        'confirm' => 'Delete this school? Its articles stay in the news database.');
    $actions = array_merge($actions, $article_actions);
    $nil = function ($v) { return $v === null ? '' : (string) $v; };
    $page = function_exists('get_page_by_path') ? get_page_by_path(KOP_ISCHOOLS_PAGE) : null;
    if ($page && !$pending) {
        $links[] = array('label' => 'The Indian Boarding Schools page', 'url' => $page->post_status === 'publish' ? get_permalink($page) : get_preview_post_link($page));
    }
    return array(
        'key'          => (string) $s['id'],
        'title'        => (string) $s['name'],
        'subtitle'     => trim($place . ($country !== 'Other places' ? ($place !== '' ? ' · ' : '') . $country : '')),
        'text'         => $text,
        'details'      => array(array('label' => 'Articles (' . count($lines) . ')', 'value' => $lines ? implode('; ', $lines) : 'None filed yet.')),
        'created'      => (string) $s['created_at'],
        'status'       => $s['review'],
        'status_label' => $pending ? 'Waiting for review' : 'On the page',
        'fields'       => array(
            array('name' => 'name', 'label' => 'Name', 'type' => 'text', 'value' => (string) $s['name']),
            array('name' => 'other_names', 'label' => 'Other names (earlier names, other spellings)', 'type' => 'list', 'value' => kop_ischools_lines($s['other_names'])),
            array('name' => 'country', 'label' => 'Country', 'type' => 'text', 'value' => $nil($s['country'])),
            array('name' => 'region', 'label' => 'State or province', 'type' => 'text', 'value' => $nil($s['region'])),
            array('name' => 'city', 'label' => 'Town or city', 'type' => 'text', 'value' => $nil($s['city'])),
            array('name' => 'nations', 'label' => 'Nations (whose children were sent there)', 'type' => 'text', 'value' => $nil($s['nations'])),
            array('name' => 'run_by', 'label' => 'Run by', 'type' => 'text', 'value' => $nil($s['run_by'])),
            array('name' => 'opened', 'label' => 'Opened (year)', 'type' => 'number', 'value' => $nil($s['opened'])),
            array('name' => 'closed', 'label' => 'Closed (year)', 'type' => 'number', 'value' => $nil($s['closed'])),
            array('name' => 'status', 'label' => 'Still open?', 'type' => 'select', 'options' => array('Closed' => 'Closed', 'Open' => 'Still open', 'Unknown' => 'Not known'),
                'value' => (string) ($s['status'] ?: 'Unknown'), 'category' => true),
            array('name' => 'notes', 'label' => 'Notes (a blank line starts a new paragraph; **bold**, *italic* and [link text](https://...) work)', 'type' => 'textarea', 'value' => $nil($s['notes'])),
            array('name' => 'links', 'label' => 'Other links (What it is | https://address)', 'type' => 'list', 'value' => kop_ischools_lines($s['links'])),
        ),
        'actions'      => $actions,
        'links'        => $links,
    );
}

/** "News about the schools in general": the articles filed under no one school. */
function kop_rinbox_ischools_general_item() {
    $pdo = kop_rinbox_ischools_pdo();
    list($links, $lines, $actions) = kop_rinbox_ischools_article_parts($pdo, 0);
    return array(
        'key'          => '0',
        'title'        => 'News about the schools in general',
        'subtitle'     => count($lines) . ' articles',
        'text'         => 'Articles about Indian boarding schools or residential schools that are not about one school.',
        'details'      => array(array('label' => 'Articles', 'value' => $lines ? implode('; ', $lines) : 'None filed yet.')),
        'status'       => 'general',
        'status_label' => 'On the page',
        'actions'      => $actions,
        'links'        => $links,
    );
}

/** A name the news scan set aside that looks like one of these schools. */
function kop_rinbox_ischools_name_item(array $r) {
    $decisions = function_exists('kop_facdisc_decisions') ? kop_facdisc_decisions() : array();
    return array(
        'key'          => 'c' . (int) $r['id'],
        'title'        => (string) $r['mention'],
        'subtitle'     => 'Set aside by the news scan: ' . ($decisions[$r['decision']] ?? $r['decision']),
        'url'          => (string) ($r['article_url'] ?? ''),
        'text'         => 'From: ' . ($r['article_title'] ?: 'article #' . (int) $r['news_id']) . '. If this is an Indigenous school, add it.',
        'status'       => 'name',
        'status_label' => 'Not filed',
        'actions'      => array(array('id' => 'add_school', 'label' => 'Add as a school', 'style' => 'approve')),
    );
}

function kop_rinbox_ischools_purge() {
    if (function_exists('kop_ischools_admin_purge')) kop_ischools_admin_purge();
}

/**
 * The news article an admin means: its number, its web address, or words of
 * its title or outlet (the old screen's article search). Throws, naming the
 * candidates, when that is not exactly one article.
 */
function kop_rinbox_ischools_find_article(PDO $pdo, $text) {
    $text = trim((string) $text);
    if ($text === '') throw new RuntimeException('Give the article\'s number, title or web address.');
    if (preg_match('/^#?(\d+)$/', $text, $m)) {
        $st = $pdo->prepare("SELECT id FROM news_submissions WHERE id = ? AND status <> 'deleted'");
        $st->execute(array((int) $m[1]));
        if ($id = (int) $st->fetchColumn()) return $id;
        throw new RuntimeException('There is no article #' . (int) $m[1] . ' in the news database.');
    }
    if (preg_match('#^https?://#i', $text)) {
        $st = $pdo->prepare("SELECT id FROM news_submissions WHERE article_url = ? AND status <> 'deleted' ORDER BY id LIMIT 1");
        $st->execute(array($text));
        if ($id = (int) $st->fetchColumn()) return $id;
    }
    $like = '%' . str_replace(array('\\', '%', '_'), array('\\\\', '\\%', '\\_'), $text) . '%';
    $st = $pdo->prepare("SELECT id, article_title, publication_name, publication_date FROM news_submissions
                          WHERE status NOT IN ('deleted') AND (article_title LIKE ? OR article_url LIKE ? OR publication_name LIKE ?)
                          ORDER BY publication_date DESC, id DESC LIMIT 6");
    $st->execute(array($like, $like, $like));
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    if (count($rows) === 1) return (int) $rows[0]['id'];
    if (!$rows) throw new RuntimeException('No article matches. It has to be in the news database first.');
    $names = array();
    foreach (array_slice($rows, 0, 5) as $a) $names[] = '#' . (int) $a['id'] . ' ' . ($a['article_title'] ?: 'Untitled') . ' (' . trim($a['publication_name'] . ' ' . $a['publication_date']) . ')';
    throw new RuntimeException('Several articles match. Give the number of the one you mean: ' . implode('; ', $names) . (count($rows) > 5 ? '; ...' : ''));
}

function kop_rinbox_ischools_act($key, $action, array $params) {
    $pdo = kop_rinbox_ischools_pdo();
    $key = (string) $key;
    $by = kop_rinbox_reviewer();
    if (preg_match('/^c(\d+)$/', $key, $m)) {
        if ($action !== 'add_school') throw new RuntimeException('Unknown action.');
        $sid = kop_ischools_add_from_candidate($pdo, (int) $m[1], $by);
        if (!$sid) throw new RuntimeException('That name is no longer there.');
        kop_rinbox_flush_counts();
        $s = kop_ischools_get($pdo, $sid);
        return array('message' => 'Added "' . ($s ? $s['name'] : '') . '" with its article. It is on the Waiting tab: check the details there, then approve it.');
    }
    $id = (int) $key;
    if ($key === '0') {
        $s = array('name' => 'the schools in general');
    } else {
        $s = kop_ischools_get($pdo, $id);
        if (!$s) throw new RuntimeException('That school is gone (someone may have deleted it).');
    }
    switch ($action) {
        case 'link_news':
            $nid = kop_rinbox_ischools_find_article($pdo, $params['news'] ?? '');
            $new = kop_ischools_link_news($pdo, $id, $nid, $by);
            kop_rinbox_ischools_purge();
            return array('message' => $new ? 'Article #' . $nid . ' filed under ' . $s['name'] . '.' : 'Article #' . $nid . ' was already filed there.');
        case 'unlink_news':
            $nid = (int) ($params['news_id'] ?? 0);
            if ($nid <= 0) throw new RuntimeException('Pick the article to take off.');
            kop_ischools_unlink_news($pdo, $id, $nid);
            kop_rinbox_ischools_purge();
            return array('message' => 'Article #' . $nid . ' taken off. File it again to put it back.');
    }
    if ($key === '0') throw new RuntimeException('Unknown action.');
    switch ($action) {
        case 'approve':
        case 'unapprove':
            // The admin screen's approve, and its reverse.
            $review = $action === 'approve' ? 'approved' : 'pending';
            $pdo->prepare('UPDATE indigenous_schools SET review = ?, updated_at = ? WHERE id = ?')->execute(array($review, kop_ischools_now(), $id));
            kop_rinbox_ischools_purge();
            kop_rinbox_flush_counts();
            return array('message' => $action === 'approve'
                ? 'Approved: "' . $s['name'] . '" is on the Indian Boarding Schools page now. Take it off again from the Listed tab.'
                : '"' . $s['name'] . '" is off the page and waiting for review again.');
        case 'delete':
            kop_ischools_delete($pdo, $id);
            kop_rinbox_ischools_purge();
            kop_rinbox_flush_counts();
            return array('message' => 'Deleted "' . $s['name'] . '". Its articles are still in the news database.');
    }
    throw new RuntimeException('Unknown action.');
}

/** The old screen's "Add a school" and "Move here" forms. */
function kop_rinbox_ischools_tool($id, array $params) {
    $pdo = kop_rinbox_ischools_pdo();
    $by = kop_rinbox_reviewer();
    if ($id === 'add') {
        $f = array('review' => 'approved');
        foreach (array('name', 'country', 'region', 'city') as $k) $f[$k] = (string) ($params[$k] ?? '');
        $sid = kop_ischools_save($pdo, $f, 0, $by);
        kop_rinbox_ischools_purge();
        return array('message' => 'School added and listed on the page (Listed tab). Open Edit details on its card for the rest.', 'key' => (string) $sid);
    }
    if ($id === 'move') {
        $fid = (int) ($params['facility_id'] ?? 0);
        if ($fid <= 0) throw new RuntimeException('Search for the facility record first.');
        $sid = kop_ischools_move_facility($pdo, $fid, $by);
        $s = kop_ischools_get($pdo, $sid);
        return array('message' => 'Moved out of the facility data as "' . ($s ? $s['name'] : '') . '". Check its details on the ' . ($s && $s['review'] === 'pending' ? 'Waiting' : 'Listed') . ' tab.');
    }
    throw new RuntimeException('Unknown tool.');
}

function kop_rinbox_ischools_save($key, array $fields) {
    $pdo = kop_rinbox_ischools_pdo();
    if (!ctype_digit((string) $key) || (string) $key === '0') throw new RuntimeException('This item has no details to edit.');
    $s = kop_ischools_get($pdo, (int) $key);
    if (!$s) throw new RuntimeException('That school is gone.');
    // kop_ischools_save() writes every column, so start from what is stored.
    $f = array();
    foreach (array('name', 'other_names', 'country', 'region', 'city', 'nations', 'run_by', 'opened', 'closed', 'status', 'notes', 'links') as $k) {
        $v = array_key_exists($k, $fields) ? $fields[$k] : $s[$k];
        $f[$k] = is_array($v) ? implode("\n", array_map('strval', $v)) : (string) $v;
    }
    foreach (array('opened', 'closed') as $k) {
        $y = trim($f[$k]);
        if ($y !== '' && (!ctype_digit($y) || (int) $y < 1700 || (int) $y > (int) gmdate('Y'))) {
            throw new RuntimeException(ucfirst($k) . ': write a year between 1700 and ' . gmdate('Y') . ', or leave it empty.');
        }
    }
    if (array_key_exists('status', $fields) && !in_array($fields['status'], array('Open', 'Closed', 'Unknown'), true)) {
        throw new RuntimeException('Still open?: pick Closed, Still open or Not known.');
    }
    kop_ischools_save($pdo, $f, (int) $key, kop_rinbox_reviewer());
    kop_rinbox_ischools_purge();
    return array('message' => $s['review'] === 'approved' ? 'Saved. The page shows the change now.' : 'Saved.');
}
