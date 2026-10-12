<?php
/**
 * Review inbox source: articles nominated for Essential reporting
 * (inc/featured-nominations.php). Feature picks the article with the note as
 * it reads in the card's box; Pass over leaves the site as it is. Both have
 * Undo. A nominated article that was featured some other way (the headline
 * pencil, KOP Tools > Essential Reporting) is listed under Already on file.
 */

if (!defined('ABSPATH')) {
    exit;
}

kop_rinbox_register('featured-nominations', function () {
    if (!function_exists('kop_featnom_row') || !function_exists('kop_feat_pdo') || !kop_feat_pdo()) return null;
    try {
        kop_featnom_seed(kop_feat_pdo());
    } catch (Throwable $e) {
        error_log('kop featured nominations seed: ' . $e->getMessage());
    }
    return array(
        'label'    => 'Nominated',
        'group'    => 'Essential reporting',
        'help'     => 'Stored articles put forward as especially good journalism, each with a draft note. Feature puts it on the Essential reporting page with the note as it reads in the box; Pass over leaves it off.',
        'views'    => kop_featnom_statuses(),
        'tool_url' => admin_url('admin.php?page=kop-essential-reporting'),
        'count'    => function () {
            $c = kop_featnom_counts(kop_feat_pdo());
            return (int) $c['nominated'];
        },
        'view_counts' => function (array $q = array()) {
            return kop_featnom_counts(kop_feat_pdo());
        },
        'on_file'  => function () {
            $out = array();
            foreach (array_keys(kop_featnom_already_featured(kop_feat_pdo())) as $id) {
                $out[(string) $id] = array('label' => 'Already featured', 'url' => kop_feat_url());
            }
            return $out;
        },
        'list'     => function (array $q) {
            $status = isset(kop_featnom_statuses()[$q['view']]) ? $q['view'] : 'nominated';
            $res = kop_featnom_rows(kop_feat_pdo(), $status, (string) $q['search'], (int) $q['offset'], (int) $q['limit']);
            return array('items' => array_map('kop_rinbox_featnom_item', $res['rows']), 'total' => $res['total']);
        },
        'get'      => function ($key) {
            $row = kop_featnom_row(kop_feat_pdo(), (int) $key);
            return $row ? kop_rinbox_featnom_item($row) : null;
        },
        'act'      => 'kop_rinbox_featnom_act',
    );
});

function kop_rinbox_featnom_item(array $r) {
    $statuses = kop_featnom_statuses();
    $title = trim((string) (($r['alternate_title'] ?? '') ?: ($r['article_title'] ?? '')));
    $byline = kop_feat_byline((string) ($r['author'] ?? ''), (string) ($r['publication_name'] ?? ''));
    $date = (string) ($r['publication_date'] ?? '');
    $details = array();
    if (trim((string) $r['reason']) !== '') $details[] = array('label' => 'Why it was nominated', 'value' => (string) $r['reason']);
    if (($r['article_status'] ?? '') !== 'approved' && ($r['article_status'] ?? '') !== 'published') {
        $details[] = array('label' => 'Article', 'value' => 'This article is ' . ($r['article_status'] ?: 'missing') . '; only an approved article can be featured.');
    }
    if ($r['status'] !== 'nominated') {
        $details[] = array('label' => $r['status'] === 'featured' ? 'Featured' : 'Passed over', 'value' => trim(substr((string) $r['decided_at'], 0, 10) . ' by ' . $r['decided_by']));
        if ($r['status'] === 'featured' && trim((string) $r['note']) !== '') $details[] = array('label' => 'Note', 'value' => (string) $r['note']);
    }
    $links = array();
    if (!empty($r['archive_url'])) $links[] = array('label' => 'Archived copy', 'url' => (string) $r['archive_url']);
    if ($r['status'] === 'featured') $links[] = array('label' => 'Essential reporting page', 'url' => kop_feat_url());

    $actions = array();
    if ($r['status'] === 'nominated') {
        $actions[] = array('id' => 'feature', 'label' => 'Approve: feature it', 'style' => 'approve',
            'params' => array(array('name' => 'note', 'label' => 'Note shown with it (optional)', 'type' => 'textarea', 'value' => (string) $r['note'], 'optional' => true)),
            'help' => 'Puts this article on the Essential reporting page with the note in the box, flagged on every page that lists it. Undo takes it off again.');
        $actions[] = array('id' => 'pass', 'label' => 'Reject: pass over', 'style' => 'reject',
            'help' => 'Nothing on the site changes; the card moves to Passed over.');
    } else {
        $actions[] = array('id' => 'undo', 'label' => $r['status'] === 'featured' ? 'Undo' : 'Back to nominated', 'style' => 'undo',
            'help' => $r['status'] === 'featured'
                ? 'Takes the article off Essential reporting (refused if its note was changed since) and puts the card back with the nominations.'
                : 'Nothing on the site changes; the card waits with the nominations again.');
    }

    return array(
        'key'          => (string) (int) $r['news_id'],
        'title'        => $title !== '' ? $title : 'Article #' . (int) $r['news_id'],
        'subtitle'     => implode(' · ', array_filter(array((string) ($r['publication_name'] ?? ''), $byline, $date !== '' ? substr($date, 0, 4) : '',
            (int) $r['tier'] > 1 ? 'ranked lower' : ''))),
        'url'          => (string) ($r['article_url'] ?? ''),
        'text'         => (string) ($r['summary'] ?? ''),
        'created'      => (string) $r['created_at'],
        'status'       => (string) $r['status'],
        'status_label' => $statuses[$r['status']] ?? (string) $r['status'],
        'fields'       => array(),
        'details'      => $details,
        'links'        => $links,
        'actions'      => $actions,
    );
}

function kop_rinbox_featnom_act($key, $action, array $params) {
    $pdo = kop_feat_pdo();
    $who = kop_rinbox_reviewer();
    kop_rinbox_flush_counts();
    switch ($action) {
        case 'feature':
            return array('message' => kop_featnom_feature($pdo, (int) $key, (string) ($params['note'] ?? ''), $who));
        case 'pass':
            return array('message' => kop_featnom_pass($pdo, (int) $key, $who));
        case 'undo':
            return array('message' => kop_featnom_undo($pdo, (int) $key, $who));
    }
    throw new RuntimeException('Unknown action.');
}
