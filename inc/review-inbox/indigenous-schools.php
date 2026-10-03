<?php
/**
 * Review inbox source: Indian boarding, residential and mission schools
 * (inc/indigenous-schools.php, screen in inc/indigenous-schools-admin.php).
 * The news scan files a school it finds as review = 'pending'; Approve lists
 * it on /indian-boarding-schools/, Delete drops it (kop_ischools_delete()),
 * edits go through kop_ischools_save().
 */

if (!defined('ABSPATH')) {
    exit;
}

kop_rinbox_register('indigenous-schools', function () {
    if (!function_exists('kop_ischools_save')) return null;
    return array(
        'label'    => 'Indigenous schools',
        'group'    => 'Found by the news scans',
        'help'     => 'Indian boarding and residential schools the news scan found. Check the details, then Approve to list the school on the Indian Boarding Schools page. These are never TTI facilities.',
        'views'    => array('pending' => 'Waiting for review', 'approved' => 'Listed on the page'),
        'tool_url' => admin_url('admin.php?page=kop-indigenous-schools'),
        'count'    => function () {
            $pdo = kop_ischools_pdo();
            if (!$pdo || !kop_ischools_ready($pdo)) return 0;
            return (int) $pdo->query("SELECT COUNT(*) FROM indigenous_schools WHERE review = 'pending'")->fetchColumn();
        },
        'list'     => 'kop_rinbox_ischools_list',
        'get'      => function ($key) {
            $s = kop_ischools_get(kop_rinbox_ischools_pdo(), (int) $key);
            return $s ? kop_rinbox_ischools_item($s) : null;
        },
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

function kop_rinbox_ischools_list(array $q) {
    $pdo = kop_rinbox_ischools_pdo();
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

/** The articles filed under one school (every status, as the admin screen shows them). */
function kop_rinbox_ischools_articles(PDO $pdo, $id) {
    $st = $pdo->prepare('SELECT n.id, n.article_title, n.publication_name, n.publication_date, n.article_url, n.status
                           FROM indigenous_school_news l JOIN news_submissions n ON n.id = l.news_id
                          WHERE l.school_id = ? ORDER BY n.publication_date DESC, n.id DESC');
    $st->execute(array((int) $id));
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

function kop_rinbox_ischools_item(array $s) {
    $pdo = kop_rinbox_ischools_pdo();
    $pending = $s['review'] === 'pending';
    $place = kop_ischools_meta_line($s);
    $country = kop_ischools_country_label($s['country']);
    $links = array();
    $titles = array();
    foreach (kop_rinbox_ischools_articles($pdo, $s['id']) as $a) {
        if (preg_match('#^https?://#i', (string) $a['article_url'])) {
            $links[] = array('label' => $a['article_title'] ?: 'Article', 'url' => $a['article_url']);
        }
        if (!in_array($a['status'], array('approved', 'published'), true)) {
            $titles[] = '"' . ($a['article_title'] ?: 'Untitled') . '" is not shown on the page: the article is ' . $a['status'] . '.';
        }
    }
    $text = trim(implode("\n", array_filter(array(
        $s['source'],
        $s['notes'] !== null && $s['notes'] !== '' ? kop_rinbox_excerpt($s['notes'], 400) : '',
        implode(' ', $titles),
    ))));
    $actions = array();
    if ($pending) {
        $actions[] = array('id' => 'approve', 'label' => 'Approve', 'style' => 'approve');
    } else {
        $actions[] = array('id' => 'unapprove', 'label' => 'Take off the page', 'style' => 'undo');
    }
    $actions[] = array('id' => 'delete', 'label' => 'Delete', 'style' => 'reject',
        'confirm' => 'Delete this school? Its articles stay in the news database.');
    $nil = function ($v) { return $v === null ? '' : (string) $v; };
    return array(
        'key'          => (string) $s['id'],
        'title'        => (string) $s['name'],
        'subtitle'     => trim($place . ($country !== 'Other places' ? ($place !== '' ? ' · ' : '') . $country : '')),
        'text'         => $text,
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
            array('name' => 'notes', 'label' => 'Notes', 'type' => 'textarea', 'value' => $nil($s['notes'])),
            array('name' => 'links', 'label' => 'Other links (What it is | https://address)', 'type' => 'list', 'value' => kop_ischools_lines($s['links'])),
        ),
        'actions'      => $actions,
        'links'        => $links,
    );
}

function kop_rinbox_ischools_purge() {
    if (function_exists('kop_ischools_admin_purge')) kop_ischools_admin_purge();
}

function kop_rinbox_ischools_act($key, $action, array $params) {
    $pdo = kop_rinbox_ischools_pdo();
    $id = (int) $key;
    $s = kop_ischools_get($pdo, $id);
    if (!$s) throw new RuntimeException('That school is gone (someone may have deleted it).');
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

function kop_rinbox_ischools_save($key, array $fields) {
    $pdo = kop_rinbox_ischools_pdo();
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
