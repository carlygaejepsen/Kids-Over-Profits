<?php
/**
 * scripts/test-review-inbox.php checks for the page's own five types
 * (inc/review-inbox/native.php): rename and recategorise, tags in the row's
 * own column, and "Move to" another queue with its Undo.
 */

// WordPress functions the queue inserts use that the harness does not have.
if (!function_exists('sanitize_textarea_field')) { function sanitize_textarea_field($s) { return trim(strip_tags((string) $s)); } }
if (!function_exists('sanitize_text_field')) { function sanitize_text_field($s) { return trim(preg_replace('/\s+/', ' ', strip_tags((string) $s))); } }
if (!function_exists('esc_url_raw')) { function esc_url_raw($u) { return filter_var((string) $u, FILTER_SANITIZE_URL); } }
if (!function_exists('remove_filter')) { function remove_filter() { return true; } }
if (!function_exists('get_post_status')) { function get_post_status() { return false; } }
if (!function_exists('get_the_title')) { function get_the_title() { return ''; } }
if (!function_exists('get_edit_post_link')) { function get_edit_post_link() { return ''; } }
require_once dirname(__DIR__, 2) . '/inc/source-submissions.php';

// Nothing may be waiting in the mirror: make the newest rows of each kind pending (scratch copy only).
foreach (array('news_submissions' => array('status', 'submitted'), 'lawsuits' => array('publication_status', 'pending'),
    'legislation' => array('publication_status', 'pending'), 'wiki_submissions' => array('status', 'submitted'),
    'suggested_edits' => array('status', 'pending')) as $kop_t => $kop_s) {
    $GLOBALS['pdo']->exec("UPDATE $kop_t SET {$kop_s[0]} = '{$kop_s[1]}' WHERE id IN (SELECT id FROM $kop_t ORDER BY id DESC LIMIT 3)");
}
unset($kop_t, $kop_s);

function kop_rinbox_test_news(array $src, array $item, callable $check) {
    $pdo = kop_rinbox_pdo();
    $before = kop_rinbox_native_row('news', $item['key']);

    call_user_func($src['save'], $item['key'], array('article_title' => 'Retitled In Test', 'article_type' => 'closure'));
    $row = kop_rinbox_native_row('news', $item['key']);
    $check('news: rename and recategorise', $row['article_title'] === 'Retitled In Test' && $row['article_type'] === 'closure');
    try {
        call_user_func($src['save'], $item['key'], array('article_type' => 'gossip'));
        $check('news: a category that is not a choice is refused', false);
    } catch (RuntimeException $e) {
        $check('news: a category that is not a choice is refused', true);
    }

    $tags = kop_rinbox_set_tags('news', $item['key'], array('juvenile justice', 'Lawsuit'));
    $stored = json_decode((string) kop_rinbox_native_row('news', $item['key'])['tags'], true);
    $check('news: tags go in the article\'s own tags column, in the news vocabulary', is_array($stored) && in_array('Lawsuit', $stored, true), json_encode($stored));

    if (kop_rinbox_native_url('news', $row) !== '') {
        try {
            $res = call_user_func($src['act'], $item['key'], 'move', array('to' => 'lawsuit'));
            $log = kop_rinbox_native_moves()['news:' . (int) $item['key']] ?? null;
            $moved = kop_rinbox_native_row('news', $item['key']);
            $new = $log ? kop_rinbox_native_row('lawsuit', $log['to_id']) : null;
            $check('news: move to Lawsuits makes a pending lawsuit and files the article as rejected',
                $new && $new['publication_status'] === 'pending' && $moved['status'] === 'rejected', $res['message'] ?? '');
            $item_now = kop_rinbox_native_item('news', $moved);
            $check('news: the moved article offers Undo', (bool) array_filter($item_now['actions'], function ($a) { return $a['id'] === 'unmove'; }));
            call_user_func($src['act'], $item['key'], 'unmove', array());
            $check('news: Undo takes the lawsuit back and the article is pending again',
                !kop_rinbox_native_row('lawsuit', $log['to_id']) && kop_rinbox_native_row('news', $item['key'])['status'] === 'submitted');
        } catch (RuntimeException $e) {
            // A link already in the lawsuit records cannot move; that refusal is the right answer.
            $check('news: a move that would duplicate is refused with a reason', stripos($e->getMessage(), 'Already in') === 0, $e->getMessage());
        }
    }
    $pdo->prepare('UPDATE news_submissions SET article_title = ?, article_type = ?, tags = ?, status = ? WHERE id = ?')
        ->execute(array($before['article_title'], $before['article_type'], $before['tags'], $before['status'], (int) $item['key']));
}

function kop_rinbox_test_legislation(array $src, array $item, callable $check) {
    $before = kop_rinbox_native_row('legislation', $item['key']);
    call_user_func($src['save'], $item['key'], array('position' => 'watch', 'sponsors' => array('Rep. A', ' ', 'Sen. B')));
    $row = kop_rinbox_native_row('legislation', $item['key']);
    $check('legislation: position and a sponsor list save', $row['position'] === 'watch' && json_decode($row['sponsors'], true) === array('Rep. A', 'Sen. B'), (string) $row['sponsors']);
    kop_rinbox_pdo()->prepare('UPDATE legislation SET position = ?, sponsors = ? WHERE id = ?')
        ->execute(array($before['position'], $before['sponsors'], (int) $item['key']));
}
