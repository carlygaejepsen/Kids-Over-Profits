<?php
/**
 * Review inbox source: lawsuits and legislation that were approved but never
 * published (approved before "approve also publishes"), the rows the old
 * api/publish-approved-records.php page publishes all at once. Here each can
 * be published on its own (or several with the bulk buttons), or all of them
 * with the tool; both go through kop_publish_approved_records()
 * (api/lib-publish-approved.php). Undo puts one back to approved with its
 * old publish date, kept in the kop_rinbox_published option.
 */

if (!defined('ABSPATH')) {
    exit;
}

kop_rinbox_register('publish-approved', function () {
    $lib = get_stylesheet_directory() . '/api/lib-publish-approved.php';
    if (!file_exists($lib)) return null;
    require_once $lib;
    return array(
        'label'    => 'Approved, not published',
        'group'    => 'Submissions',
        'help'     => 'Lawsuits and bills that were approved before approving also published them, so they are still off the site. Publish puts one on the site; Publish all does every one.',
        'views'    => array('approved' => 'Waiting to publish'),
        'tool_url' => get_stylesheet_directory_uri() . '/api/publish-approved-records.php',
        'tools'    => array(array('id' => 'publish_all', 'label' => 'Publish all of them', 'style' => 'approve',
            'confirm' => 'Publish every approved lawsuit and bill now?')),
        'tool'     => function ($id, array $params) {
            if ($id !== 'publish_all') throw new RuntimeException('Unknown tool.');
            $done = kop_publish_approved_records(kop_rinbox_pdo());
            return array('message' => 'Published ' . $done['total'] . ' record(s): ' . (int) $done['updated']['lawsuits'] . ' lawsuits, ' . (int) $done['updated']['legislation'] . ' bills.');
        },
        'count'    => function () {
            return array_sum(kop_publish_approved_counts(kop_rinbox_pdo()));
        },
        'list'     => 'kop_rinbox_pubapp_list',
        'get'      => function ($key) {
            $r = kop_rinbox_pubapp_row((string) $key);
            return $r ? kop_rinbox_pubapp_item($r) : null;
        },
        'act'      => 'kop_rinbox_pubapp_act',
    );
});

/** [table, id] from a key like "lawsuits:12". */
function kop_rinbox_pubapp_key($key) {
    if (!preg_match('/^(lawsuits|legislation):(\d+)$/', (string) $key, $m)) throw new RuntimeException('Unknown record.');
    return array($m[1], (int) $m[2]);
}

function kop_rinbox_pubapp_row($key) {
    list($table, $id) = kop_rinbox_pubapp_key($key);
    $st = kop_rinbox_pdo()->prepare("SELECT * FROM `$table` WHERE id = ?");
    $st->execute(array($id));
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if (!$r) return null;
    $r['_table'] = $table;
    return $r;
}

function kop_rinbox_pubapp_list(array $q) {
    $pdo = kop_rinbox_pdo();
    $rows = array();
    foreach (kop_publish_approved_tables() as $table => $title) {
        $where = "publication_status = 'approved'";
        $params = array();
        if ($q['search'] !== '') {
            $where .= " AND $title LIKE ?";
            $params[] = '%' . $q['search'] . '%';
        }
        $st = $pdo->prepare("SELECT * FROM `$table` WHERE $where ORDER BY id DESC");
        $st->execute($params);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $r['_table'] = $table;
            $rows[] = $r;
        }
    }
    return array('items' => array_map('kop_rinbox_pubapp_item', array_slice($rows, (int) $q['offset'], (int) $q['limit'])), 'total' => count($rows));
}

function kop_rinbox_pubapp_item(array $r) {
    $table = $r['_table'];
    $law = $table === 'lawsuits';
    $key = $table . ':' . (int) $r['id'];
    $url = '';
    if ($law) {
        $list = json_decode((string) ($r['source_urls'] ?? ''), true);
        $url = is_array($list) ? (string) ($list[0] ?? '') : '';
    } else {
        $url = (string) (($r['official_url'] ?? '') ?: ($r['full_text_url'] ?? ''));
    }
    $status = (string) $r['publication_status'];
    $what = $law ? 'lawsuit' : 'bill';
    $actions = array();
    if ($status === 'approved') {
        $actions[] = array('id' => 'publish', 'label' => 'Publish', 'style' => 'approve',
            'help' => 'Puts this ' . $what . ' on the public site, where its ' . ($law ? 'lawsuit' : 'legislation') . ' listings show it.');
    } elseif ($status === 'published' && array_key_exists($key, kop_rinbox_pubapp_log())) {
        $actions[] = array('id' => 'unpublish', 'label' => 'Undo: back to approved', 'style' => 'undo',
            'help' => 'Takes this ' . $what . ' off the public site again; it stays approved.');
    }
    $title = (string) ($law ? $r['case_name'] : $r['bill_title']);
    return array(
        'key'          => $key,
        'title'        => $title !== '' ? $title : '(untitled)',
        'subtitle'     => $law ? trim('Lawsuit · ' . ($r['court'] ?? '') . ' ' . ($r['case_number'] ?? ''))
            : trim('Bill · ' . ($r['jurisdiction'] ?? '') . ' ' . ($r['bill_number'] ?? '')),
        'url'          => preg_match('#^https?://#i', $url) ? $url : '',
        'text'         => kop_rinbox_excerpt((string) ($r['summary'] ?? ''), 400),
        'created'      => (string) ($r['created_at'] ?? ''),
        'status'       => $status,
        'status_label' => $status === 'approved' ? 'Approved, not on the site' : ucfirst($status),
        'actions'      => $actions,
    );
}

function kop_rinbox_pubapp_log() {
    $log = get_option('kop_rinbox_published', array());
    return is_array($log) ? $log : array();
}

function kop_rinbox_pubapp_act($key, $action, array $params) {
    $r = kop_rinbox_pubapp_row($key);
    if (!$r) throw new RuntimeException('That record is gone.');
    list($table, $id) = kop_rinbox_pubapp_key($key);
    $pdo = kop_rinbox_pdo();
    $log = kop_rinbox_pubapp_log();
    if ($action === 'publish') {
        if ($r['publication_status'] !== 'approved') throw new RuntimeException('It is ' . $r['publication_status'] . ', not waiting to be published.');
        kop_publish_approved_records($pdo, array($table => array($id)));
        $log[$table . ':' . $id] = $r['published_at'];
        update_option('kop_rinbox_published', array_slice($log, -500, null, true), false);
        kop_rinbox_flush_counts();
        return array('message' => 'Published: it is on the site now. Undo puts it back to approved.');
    }
    if ($action === 'unpublish') {
        if (!array_key_exists($table . ':' . $id, $log) || $r['publication_status'] !== 'published') throw new RuntimeException('There is no publish to undo.');
        $pdo->prepare("UPDATE `$table` SET publication_status = 'approved', published_at = ? WHERE id = ?")->execute(array($log[$table . ':' . $id], $id));
        unset($log[$table . ':' . $id]);
        update_option('kop_rinbox_published', $log, false);
        kop_rinbox_flush_counts();
        return array('message' => 'Back to approved: it is off the site again.');
    }
    throw new RuntimeException('Unknown action.');
}
