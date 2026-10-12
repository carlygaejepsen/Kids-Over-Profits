<?php
/**
 * scripts/test-review-inbox.php checks for the 'featured-nominations' source
 * (inc/review-inbox/featured-nominations.php over inc/featured-nominations.php).
 * The nominations file is loaded into the scratch copy; feature, pass over and
 * Undo run there against the real articles.
 */

if (!function_exists('kop_ie_field')) {
    function kop_ie_field($name, $label, $type, $value, $extra = array()) { return array('name' => $name, 'label' => $label, 'type' => $type, 'value' => $value) + $extra; }
}
if (!function_exists('kop_ie_who')) {
    function kop_ie_who() { return 'Inbox Test'; }
}
if (!function_exists('shortcode_atts')) {
    function shortcode_atts($defaults, $atts) { return array_merge($defaults, array_intersect_key((array) $atts, $defaults)); }
}
require_once dirname(__DIR__, 2) . '/inc/featured-reporting.php';
require_once dirname(__DIR__, 2) . '/inc/featured-nominations.php';

function kop_rinbox_test_featured_nominations(array $src, array $item, callable $check) {
    $pdo = kop_feat_pdo();
    $file = json_decode(file_get_contents(kop_featnom_seed_file()), true);
    $in_file = count($file['nominations']);
    $rows = (int) $pdo->query('SELECT COUNT(*) FROM news_featured_nominations')->fetchColumn();
    $check('featured-nominations: the file is loaded', $rows > 0 && $rows <= $in_file, "$rows of $in_file");
    $check('featured-nominations: the same version is not read twice', kop_featnom_seed($pdo) === 0);
    $check('featured-nominations: a forced reload adds nothing already there', kop_featnom_seed($pdo, null, true) === 0);
    $ids = array_map(function ($n) { return (int) $n['news_id']; }, $file['nominations']);
    $check('featured-nominations: no article is nominated twice in the file', count($ids) === count(array_unique($ids)));
    $missing = array_values(array_diff($ids, array_map('intval', $pdo->query('SELECT news_id FROM news_featured_nominations')->fetchAll(PDO::FETCH_COLUMN))));
    $check('featured-nominations: every nominated article is on the records', !$missing, implode(', ', $missing));
    $bad = $pdo->query("SELECT m.news_id FROM news_featured_nominations m JOIN news_submissions n ON n.id = m.news_id WHERE n.status NOT IN ('approved','published')")->fetchAll(PDO::FETCH_COLUMN);
    $check('featured-nominations: every nominated article is approved', !$bad, implode(', ', $bad));

    $check('featured-nominations: tier 1 comes first', strpos($item['subtitle'], 'ranked lower') === false, $item['subtitle']);
    $feature = $item['actions'][0];
    $check('featured-nominations: Feature carries the draft note to edit', $feature['id'] === 'feature' && $feature['style'] === 'approve'
        && $feature['params'][0]['name'] === 'note' && $feature['params'][0]['value'] !== '', json_encode($feature['params'][0]));
    $check('featured-nominations: the reason is on the card', ($item['details'][0]['label'] ?? '') === 'Why it was nominated');

    $id = (int) $item['key'];
    $res = call_user_func($src['act'], $item['key'], 'feature', array('note' => 'Edited on the card.'));
    $pick = kop_feat_get($id);
    $check('featured-nominations: Feature picks it with the note from the box', $pick && $pick['note'] === 'Edited on the card.', $res['message']);
    $after = call_user_func($src['get'], $item['key']);
    $check('featured-nominations: a featured card offers Undo', $after['status'] === 'featured' && $after['actions'][0]['id'] === 'undo');
    try {
        call_user_func($src['act'], $item['key'], 'feature', array('note' => 'again'));
        $check('featured-nominations: a decided one cannot be featured twice', false);
    } catch (RuntimeException $e) {
        $check('featured-nominations: a decided one cannot be featured twice', true, $e->getMessage());
    }
    $res = call_user_func($src['act'], $item['key'], 'undo', array());
    $check('featured-nominations: Undo takes the pick off and waits again', kop_feat_get($id) === null && kop_featnom_row($pdo, $id)['status'] === 'nominated', $res['message']);

    call_user_func($src['act'], $item['key'], 'pass', array());
    $check('featured-nominations: Pass over changes nothing on the site', kop_feat_get($id) === null && kop_featnom_row($pdo, $id)['status'] === 'passed');
    call_user_func($src['act'], $item['key'], 'undo', array());
    $check('featured-nominations: Back to nominated', kop_featnom_row($pdo, $id)['status'] === 'nominated');

    // Featured another way (the pencil): listed as already on file; Undo from here never takes that pick away.
    kop_feat_set($pdo, $id, 'Picked with the pencil.', 'Inbox Test');
    $on = call_user_func($src['on_file']);
    $check('featured-nominations: featured elsewhere is already on file', isset($on[(string) $id]) && $on[(string) $id]['label'] === 'Already featured');
    call_user_func($src['act'], $item['key'], 'feature', array('note' => 'Picked with the pencil.'));
    call_user_func($src['act'], $item['key'], 'undo', array());
    $check('featured-nominations: Undo keeps a pick made elsewhere', ($p = kop_feat_get($id)) && $p['note'] === 'Picked with the pencil.');
    kop_feat_remove($pdo, $id, 'Inbox Test');

    // A changed note blocks the Undo rather than throwing the change away.
    call_user_func($src['act'], $item['key'], 'feature', array('note' => 'First.'));
    kop_feat_set($pdo, $id, 'Changed at KOP Tools.', 'Inbox Test');
    try {
        call_user_func($src['act'], $item['key'], 'undo', array());
        $check('featured-nominations: Undo is refused after the note changed', false);
    } catch (RuntimeException $e) {
        $check('featured-nominations: Undo is refused after the note changed', kop_feat_get($id)['note'] === 'Changed at KOP Tools.', $e->getMessage());
    }

    $counts = call_user_func($src['view_counts'], array());
    $check('featured-nominations: every tab has a count', array_keys($counts) === array_keys(kop_featnom_statuses()), json_encode($counts));
    $found = call_user_func($src['list'], array('view' => 'nominated', 'search' => 'Missoulian', 'offset' => 0, 'limit' => 5));
    $check('featured-nominations: search finds by outlet', $found['total'] >= 1, (string) $found['total']);
}
