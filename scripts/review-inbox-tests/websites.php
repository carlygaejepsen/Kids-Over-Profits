<?php
/**
 * scripts/test-review-inbox.php checks for the 'websites' source
 * (inc/review-inbox/websites.php over inc/source-submissions.php). The
 * WordPress post functions it calls are stood in for over the scratch copy's
 * wpdl_posts / wpdl_postmeta; one waiting website is added there.
 */

require_once dirname(__DIR__, 2) . '/inc/source-submissions.php';

if (!function_exists('sanitize_textarea_field')) {
    function sanitize_textarea_field($s) { return trim(strip_tags((string) $s)); }
}
if (!function_exists('update_post_meta')) {
    function update_post_meta($id, $key, $value) {
        $pdo = $GLOBALS['pdo'];
        $pdo->prepare('DELETE FROM wpdl_postmeta WHERE post_id = ? AND meta_key = ?')->execute(array((int) $id, $key));
        $pdo->prepare('INSERT INTO wpdl_postmeta (post_id, meta_key, meta_value) VALUES (?, ?, ?)')->execute(array((int) $id, $key, (string) $value));
        return true;
    }
}
if (!function_exists('wp_update_post')) {
    function wp_update_post($post, $wp_error = false) {
        $id = (int) $post['ID'];
        unset($post['ID']);
        foreach ($post as $col => $v) {
            $GLOBALS['pdo']->prepare("UPDATE wpdl_posts SET `$col` = ? WHERE ID = ?")->execute(array($v, $id));
        }
        return $id;
    }
}
if (!function_exists('wp_trash_post')) {
    function wp_trash_post($id) {
        $status = $GLOBALS['pdo']->query('SELECT post_status FROM wpdl_posts WHERE ID = ' . (int) $id)->fetchColumn();
        update_post_meta($id, '_wp_trash_meta_status', $status);
        wp_update_post(array('ID' => $id, 'post_status' => 'trash'));
        return (object) array('ID' => $id);
    }
}
if (!function_exists('wp_untrash_post')) {
    /** As WordPress does with wp_untrash_post_set_previous_status() hooked. */
    function wp_untrash_post($id) {
        wp_update_post(array('ID' => $id, 'post_status' => (string) get_post_meta($id, '_wp_trash_meta_status', true) ?: 'draft'));
        return (object) array('ID' => $id);
    }
}

(function () {
    $pdo = $GLOBALS['pdo'];
    $pdo->exec('CREATE TABLE IF NOT EXISTS wpdl_posts (ID INTEGER PRIMARY KEY, post_author INTEGER, post_date TEXT, post_content TEXT, post_title TEXT,
        post_excerpt TEXT, post_status TEXT, post_name TEXT, post_modified TEXT, post_type TEXT)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS wpdl_postmeta (meta_id INTEGER PRIMARY KEY, post_id INTEGER, meta_key TEXT, meta_value TEXT)');
    $pdo->prepare("INSERT INTO wpdl_posts (post_author, post_date, post_content, post_title, post_excerpt, post_status, post_name, post_modified, post_type)
                   VALUES (1, ?, 'Found while reading about the ranch.', 'Inbox Test Ranch alumni page', '', 'pending', 'inbox-test-ranch', ?, ?)")
        ->execute(array(gmdate('Y-m-d H:i:s', time() + 60), gmdate('Y-m-d H:i:s'), KOP_EXT_SOURCE_CPT));
    $id = (int) $pdo->lastInsertId();
    foreach (array('url' => 'https://example.test/alumni', 'site_name' => 'Example Alumni', 'facility' => 'Inbox Test Ranch',
                   'tags' => 'Alumni, Staff list', 'selection' => 'Staff in 2004 included the director.', 'submitted_by' => 'tester') as $k => $v) {
        $pdo->prepare('INSERT INTO wpdl_postmeta (post_id, meta_key, meta_value) VALUES (?, ?, ?)')->execute(array($id, '_kop_' . $k, $v));
    }
})();

function kop_rinbox_test_websites(array $src, array $item, callable $check) {
    $id = (int) $item['key'];
    $status = function () use ($id) {
        return $GLOBALS['pdo']->query('SELECT post_status FROM wpdl_posts WHERE ID = ' . $id)->fetchColumn();
    };
    $check('websites: the waiting item is the test website', $item['title'] === 'Inbox Test Ranch alumni page' && $item['url'] === 'https://example.test/alumni'
        && strpos($item['text'], 'Staff in 2004') !== false);
    $check('websites: the tags are the entry\'s own field', strtolower((string) get_post_meta($id, '_kop_tags', true)) === 'needs source, follow up', (string) get_post_meta($id, '_kop_tags', true));

    call_user_func($src['save'], $item['key'], array('title' => 'Inbox Test Ranch staff list', 'facility' => 'Inbox Ranch', 'url' => 'https://example.test/alumni?x=1', 'submitted_by' => 'someone else'));
    $check('websites: save writes the title and fields, never who sent it',
        get_post($id)->post_title === 'Inbox Test Ranch staff list' && get_post_meta($id, '_kop_facility', true) === 'Inbox Ranch'
        && get_post_meta($id, '_kop_submitted_by', true) === 'tester' && get_post_meta($id, '_kop_url_norm', true) !== '');
    foreach (array(array('title' => ' '), array('url' => 'example dot test')) as $bad) {
        try {
            call_user_func($src['save'], $item['key'], $bad);
            $check('websites: bad ' . key($bad) . ' is refused', false);
        } catch (RuntimeException $e) {
            $check('websites: bad ' . key($bad) . ' is refused', true, $e->getMessage());
        }
    }

    $res = call_user_func($src['act'], $item['key'], 'publish', array());
    $check('websites: keep publishes it', $status() === 'publish', $res['message']);
    call_user_func($src['act'], $item['key'], 'unpublish', array());
    $check('websites: back to waiting', $status() === 'pending');
    $res = call_user_func($src['act'], $item['key'], 'trash', array());
    $check('websites: trash', $status() === 'trash', $res['message']);
    $trashed = kop_rinbox_get_item('websites', $item['key']);
    $check('websites: a trashed website offers Restore', array_column($trashed['actions'], 'id') === array('untrash'));
    $res = call_user_func($src['act'], $item['key'], 'untrash', array());
    $check('websites: restore puts it back where it was', $status() === 'pending', $res['message']);
}
