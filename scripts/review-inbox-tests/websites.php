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
if (!function_exists('delete_post_meta')) {
    function delete_post_meta($id, $key) {
        $GLOBALS['pdo']->prepare('DELETE FROM wpdl_postmeta WHERE post_id = ? AND meta_key = ?')->execute(array((int) $id, $key));
        return true;
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

if (!function_exists('wp_insert_post')) {
    function wp_insert_post($post, $wp_error = false) {
        $GLOBALS['pdo']->prepare('INSERT INTO wpdl_posts (post_author, post_date, post_content, post_title, post_excerpt, post_status, post_name, post_modified, post_type)
                                  VALUES (?, ?, ?, ?, \'\', ?, \'\', ?, ?)')
            ->execute(array((int) ($post['post_author'] ?? 0), gmdate('Y-m-d H:i:s'), (string) ($post['post_content'] ?? ''), (string) $post['post_title'],
                (string) $post['post_status'], gmdate('Y-m-d H:i:s'), (string) $post['post_type']));
        return (int) $GLOBALS['pdo']->lastInsertId();
    }
}
if (!function_exists('get_current_user_id')) {
    function get_current_user_id() { return 1; }
}
if (!function_exists('wp_delete_post')) {
    function wp_delete_post($id, $force = false) {
        $GLOBALS['pdo']->prepare('DELETE FROM wpdl_postmeta WHERE post_id = ?')->execute(array((int) $id));
        $GLOBALS['pdo']->prepare('DELETE FROM wpdl_posts WHERE ID = ?')->execute(array((int) $id));
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
    // Already on file: a waiting link a facility already lists (its websites or Materials and links).
    $pdo = $GLOBALS['pdo'];
    $site = '';
    $site_of = '';
    foreach ($pdo->query("SELECT name, json_data FROM facilities_v2 WHERE json_data LIKE '%profileLinks%' LIMIT 200")->fetchAll(PDO::FETCH_ASSOC) as $f) {
        $links = (array) ((json_decode((string) $f['json_data'], true) ?: array())['profileLinks'] ?? array());
        if (!empty($links[0]) && is_string($links[0]) && preg_match('#^https?://#', $links[0])) { $site = $links[0]; $site_of = $f['name']; break; }
    }
    $pdo->prepare("INSERT INTO wpdl_posts (post_author, post_date, post_content, post_title, post_excerpt, post_status, post_name, post_modified, post_type)
                   VALUES (1, ?, '', 'On file test', '', 'pending', '', ?, ?)")->execute(array(gmdate('Y-m-d H:i:s'), gmdate('Y-m-d H:i:s'), KOP_EXT_SOURCE_CPT));
    $dup = (string) $pdo->lastInsertId();
    // The same address written another way (www, trailing slash).
    $pdo->prepare("INSERT INTO wpdl_postmeta (post_id, meta_key, meta_value) VALUES (?, '_kop_url', ?)")
        ->execute(array((int) $dup, preg_replace('#^https?://(www\.)?#i', 'https://www.', rtrim($site, '/')) . '/'));
    $found = call_user_func($src['on_file']);
    $check('websites: a waiting link a facility already lists is on file, naming the facility; a new one is not', $site !== '' && isset($found[$dup])
        && strpos($found[$dup]['label'], $site_of) !== false && !isset($found[(string) $id]), $site . ' ' . json_encode($found));
    $pdo->exec('DELETE FROM wpdl_postmeta WHERE post_id = ' . (int) $dup);
    $pdo->exec('DELETE FROM wpdl_posts WHERE ID = ' . (int) $dup);

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
    $check('websites: a trashed website offers Restore and Delete permanently', array_column($trashed['actions'], 'id') === array('untrash', 'delete'));
    $res = call_user_func($src['act'], $item['key'], 'untrash', array());
    $check('websites: restore puts it back where it was', $status() === 'pending', $res['message']);

    // Reclassify: to Industry PR (a promotional news row), then Undo.
    $waiting = kop_rinbox_get_item('websites', $item['key']);
    $check('websites: a waiting website offers every destination', count($waiting['moves']) === count(kop_rdest_targets()), implode(', ', array_column($waiting['moves'], 'id')));
    $res = call_user_func($src['act'], $item['key'], 'move', array('to' => 'promo'));
    $done = json_decode((string) get_post_meta((int) $item['key'], '_kop_moved', true), true);
    $row = $done ? $GLOBALS['pdo']->query('SELECT status FROM news_submissions WHERE id = ' . (int) $done['id'])->fetchColumn() : '';
    $check('websites: move to Industry PR files a promotional news row and takes it off the list', $row === 'promotional' && $status() === 'trash', $res['message']);
    $moved = kop_rinbox_get_item('websites', $item['key']);
    $check('websites: a moved website offers Undo move', array_column($moved['actions'], 'id') === array('unmove'));
    $res = call_user_func($src['act'], $item['key'], 'unmove', array());
    $row = $GLOBALS['pdo']->query('SELECT status FROM news_submissions WHERE id = ' . (int) $done['id'])->fetchColumn();
    $check('websites: Undo move takes the news row back and the website waits again', $row === 'deleted' && $status() === 'pending', $res['message']);
    // The edit screen's text box, WordPress's Add and Delete Permanently, the status counts.
    call_user_func($src['save'], $item['key'], array('note' => 'Checked: the staff list is from 2004.'));
    $check('websites: the note (the entry\'s text) is saved', get_post($id)->post_content === 'Checked: the staff list is from 2004.');
    try {
        call_user_func($src['act'], $item['key'], 'delete', array());
        $check('websites: only a trashed website can be deleted for good', false);
    } catch (RuntimeException $e) {
        $check('websites: only a trashed website can be deleted for good', $status() === 'pending', $e->getMessage());
    }
    $res = call_user_func($src['tool'], 'add', array('url' => 'https://example.test/added-by-hand', 'title' => 'Added by hand', 'facility' => 'Inbox Ranch', 'notes' => 'From a search.'));
    $new = (int) $res['key'];
    $check('websites: Add a website puts it on the waiting list', $new > 0 && get_post_meta($new, '_kop_url', true) === 'https://example.test/added-by-hand'
        && $GLOBALS['pdo']->query('SELECT post_status FROM wpdl_posts WHERE ID = ' . $new)->fetchColumn() === 'pending', $res['message']);
    try {
        call_user_func($src['tool'], 'add', array('url' => 'not a link'));
        $check('websites: Add refuses a link that is not one', false);
    } catch (RuntimeException $e) {
        $check('websites: Add refuses a link that is not one', true, $e->getMessage());
    }
    $counts = call_user_func($src['view_counts'], array());
    $check('websites: every tab has a count', array_keys($counts) === array_values(array_diff(array_keys($src['views']), array('on_file'))) && $counts['pending'] >= 2, json_encode($counts));
    call_user_func($src['act'], (string) $new, 'trash', array());
    $res = call_user_func($src['act'], (string) $new, 'delete', array());
    $check('websites: Delete permanently removes a trashed website', !get_post($new), $res['message']);

    try {
        call_user_func($src['act'], $item['key'], 'move', array('to' => 'website'));
        $check('websites: a facility destination with no facility is refused', false);
    } catch (RuntimeException $e) {
        $check('websites: a facility destination with no facility is refused', $status() === 'pending', $e->getMessage());
    }
}
