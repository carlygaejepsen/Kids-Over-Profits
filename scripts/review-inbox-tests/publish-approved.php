<?php
/**
 * scripts/test-review-inbox.php checks for the 'publish-approved' source
 * (inc/review-inbox/publish-approved.php over api/lib-publish-approved.php,
 * which api/publish-approved-records.php also calls). One lawsuit and one
 * bill in the scratch copy are set back to approved, as if approved before
 * "approve also publishes".
 */

(function () {
    $pdo = $GLOBALS['pdo'];
    foreach (array('lawsuits', 'legislation') as $t) {
        $id = (int) $pdo->query("SELECT id FROM `$t` ORDER BY id DESC LIMIT 1")->fetchColumn();
        if ($id) $pdo->exec("UPDATE `$t` SET publication_status = 'approved', published_at = NULL WHERE id = $id");
        $GLOBALS['kop_rinbox_test_pubapp'][$t] = $id;
    }
})();

function kop_rinbox_test_publish_approved(array $src, array $item, callable $check) {
    $pdo = kop_rinbox_pdo();
    $status = function ($key) use ($pdo) {
        list($t, $id) = explode(':', $key);
        return $pdo->query("SELECT publication_status, published_at FROM `$t` WHERE id = " . (int) $id)->fetch(PDO::FETCH_ASSOC);
    };
    $all = call_user_func($src['list'], array('view' => 'approved', 'search' => '', 'offset' => 0, 'limit' => 100));
    $keys = array_column($all['items'], 'key');
    $want = array();
    foreach ($GLOBALS['kop_rinbox_test_pubapp'] as $t => $id) if ($id) $want[] = $t . ':' . $id;
    $check('publish-approved: approved lawsuits and bills both wait', !array_diff($want, $keys) && $all['total'] === call_user_func($src['count']), implode(', ', $want));

    $res = call_user_func($src['act'], $item['key'], 'publish', array());
    $after = $status($item['key']);
    $check('publish-approved: Publish puts it on the site with a date', $after['publication_status'] === 'published' && $after['published_at'] !== null, $res['message']);
    $again = kop_rinbox_get_item('publish-approved', $item['key']);
    $check('publish-approved: a published one offers Undo', array_column($again['actions'], 'id') === array('unpublish'));
    call_user_func($src['act'], $item['key'], 'unpublish', array());
    $back = $status($item['key']);
    $check('publish-approved: Undo puts it back to approved with no date', $back['publication_status'] === 'approved' && $back['published_at'] === null);

    $res = call_user_func($src['tool'], 'publish_all', array());
    $check('publish-approved: Publish all empties the queue', call_user_func($src['count']) === 0, $res['message']);
    try {
        call_user_func($src['act'], 'news:1', 'publish', array());
        $check('publish-approved: a key that is not a lawsuit or bill is refused', false);
    } catch (RuntimeException $e) {
        $check('publish-approved: a key that is not a lawsuit or bill is refused', true, $e->getMessage());
    }
}
