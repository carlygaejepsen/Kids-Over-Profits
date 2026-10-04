<?php
/**
 * scripts/test-review-inbox.php checks for the 'folder-links' source
 * (inc/review-inbox/folder-links.php over inc/folder-links.php, which
 * api/link-folders.php also calls): a suggested pair shows both folders side
 * by side, "Same facility" links it and Remove takes the link off, "Alternate
 * name" keeps it apart and "Suggest it again" puts it back, the tool links any
 * two folders; the two tables end exactly as they began.
 */

require_once __DIR__ . '/_shared.php';
require_once dirname(__DIR__, 2) . '/inc/folder-links.php';

if (kop_rinbox_test_wants('folder-links')) {
    kop_rinbox_test_copy_tables(array('facilities_master', 'wpdl_kop_folder_links', 'wpdl_kop_folder_link_dismissals', 'wpdl_kop_media_folder_tags'));
}

function kop_rinbox_test_folder_links(array $src, array $item, callable $check) {
    $pdo = $GLOBALS['pdo'];
    $key = $item['key'];
    list($a, $b) = array_map('intval', explode(':', $key));
    $snap = function () use ($pdo) {
        return md5(json_encode(array($pdo->query('SELECT folder_a, folder_b, note FROM wpdl_kop_folder_links ORDER BY folder_a, folder_b')->fetchAll(PDO::FETCH_NUM),
            $pdo->query('SELECT folder_a, folder_b FROM wpdl_kop_folder_link_dismissals ORDER BY folder_a, folder_b')->fetchAll(PDO::FETCH_NUM))));
    };
    $before = $snap();
    $check('folder-links: both folders side by side', ($item['compare']['heads'] ?? array()) === array('Folder #' . $a, 'Folder #' . $b)
        && in_array('Where', array_column($item['compare']['rows'], 'label'), true));
    $check('folder-links: the facility both names belong to', (bool) array_filter($item['details'], function ($d) { return $d['label'] === 'Both are names of' && $d['value'] !== ''; }));
    $counts = call_user_func($src['view_counts'], array());
    $check('folder-links: every tab has a count', array_keys($counts) == array_keys($src['views']) && $counts['suggested'] === call_user_func($src['count']), json_encode($counts));

    $res = call_user_func($src['act'], $key, 'link', array('note' => 'inbox test'));
    $got = kop_rinbox_get_item('folder-links', $key);
    $note = (string) $pdo->query("SELECT note FROM wpdl_kop_folder_links WHERE folder_a = $a AND folder_b = $b")->fetchColumn();
    $check('folder-links: "same facility" links the two, with the note', $note === 'inbox test' && $got['status'] === 'linked'
        && !isset(kop_rinbox_flinks_suggestions()[$key]), $res['message']);
    $res = call_user_func($src['act'], $key, 'unlink', array());
    $check('folder-links: remove the link puts it back in the suggestions', kop_rinbox_get_item('folder-links', $key)['status'] === 'suggested', $res['message']);

    $res = call_user_func($src['act'], $key, 'dismiss', array());
    $got = kop_rinbox_get_item('folder-links', $key);
    $check('folder-links: "alternate name" keeps it apart', $got['status'] === 'dismissed' && $got['actions'][0]['id'] === 'restore', $res['message']);
    $res = call_user_func($src['act'], $key, 'restore', array());
    $check('folder-links: "suggest it again" puts it back', kop_rinbox_get_item('folder-links', $key)['status'] === 'suggested', $res['message']);

    $found = call_user_func($src['lookup'], 'folder', '#' . $a);
    $check('folder-links: the folder boxes find a folder by #id', in_array('#' . $a, array_column($found, 'value'), true));
    try {
        call_user_func($src['tool'], 'link_any', array('a' => '#' . $a, 'b' => ''));
        $check('folder-links: the tool needs both folders', false);
    } catch (RuntimeException $e) {
        $check('folder-links: the tool needs both folders', true, $e->getMessage());
    }
    $res = call_user_func($src['tool'], 'link_any', array('a' => '#' . $b, 'b' => (string) $a));
    $check('folder-links: the tool links any two folders', kop_rinbox_get_item('folder-links', $key)['status'] === 'linked', $res['message']);
    call_user_func($src['act'], $key, 'unlink', array());
    $check('folder-links: the tables end as they began', $snap() === $before);
}
