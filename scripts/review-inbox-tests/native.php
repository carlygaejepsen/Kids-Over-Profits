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
    kop_rinbox_test_news_details($check);

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
            $new = $log ? kop_rinbox_native_row('lawsuit', $log['done']['id']) : null;
            $check('news: move to Lawsuits makes a pending lawsuit and files the article as rejected',
                $new && $new['publication_status'] === 'pending' && $moved['status'] === 'rejected', $res['message'] ?? '');
            $item_now = kop_rinbox_native_item('news', $moved);
            $check('news: the moved article offers Undo', (bool) array_filter($item_now['actions'], function ($a) { return $a['id'] === 'unmove'; }));
            $check('news: a pending article offers every other destination', count(kop_rinbox_native_item('news', $before)['moves']) === count(kop_rdest_targets()) - 1);
            call_user_func($src['act'], $item['key'], 'unmove', array());
            $check('news: Undo takes the lawsuit back and the article is pending again',
                !kop_rinbox_native_row('lawsuit', $log['done']['id']) && kop_rinbox_native_row('news', $item['key'])['status'] === 'submitted');
        } catch (RuntimeException $e) {
            // A link already in the lawsuit records cannot move; that refusal is the right answer.
            $check('news: a move that would duplicate is refused with a reason', stripos($e->getMessage(), 'Already in') === 0, $e->getMessage());
        }
    }
    $pdo->prepare("UPDATE news_submissions SET status = 'submitted' WHERE id = ?")->execute(array((int) $item['key']));
    call_user_func($src['act'], $item['key'], 'move', array('to' => 'promo'));
    $check('news: Industry PR files the article itself as promotional', kop_rinbox_native_row('news', $item['key'])['status'] === 'promotional');
    call_user_func($src['act'], $item['key'], 'unmove', array());
    $check('news: Undo puts it back in the queue', kop_rinbox_native_row('news', $item['key'])['status'] === 'submitted');

    // An article already on the site can still be filed under a school or listed on a young adult program.
    $pdo->prepare("UPDATE news_submissions SET status = 'approved' WHERE id = ?")->execute(array((int) $item['key']));
    $approved = kop_rinbox_native_row('news', $item['key']);
    if (kop_rinbox_native_url('news', $approved) !== '') {
        $ids = array_map(function ($m) { return $m['id']; }, kop_rinbox_native_item('news', $approved)['moves']);
        $check('news: an approved article offers Indian boarding schools and young adult programs only', $ids === array('indigenous', 'young_adult'), json_encode($ids));
        call_user_func($src['act'], $item['key'], 'move', array('to' => 'indigenous', 'school_id' => 0));
        $linked = (int) kop_ischools_pdo()->query('SELECT COUNT(*) FROM indigenous_school_news WHERE school_id = 0 AND news_id = ' . (int) $item['key'])->fetchColumn();
        $check('news: filing an approved article under the schools links it and keeps it approved',
            $linked === 1 && kop_rinbox_native_row('news', $item['key'])['status'] === 'approved');
        call_user_func($src['act'], $item['key'], 'unmove', array());
        $linked = (int) kop_ischools_pdo()->query('SELECT COUNT(*) FROM indigenous_school_news WHERE school_id = 0 AND news_id = ' . (int) $item['key'])->fetchColumn();
        $check('news: Undo unlinks it and it stays approved', $linked === 0 && kop_rinbox_native_row('news', $item['key'])['status'] === 'approved');
        $res = call_user_func($src['act'], $item['key'], 'move', array('to' => 'young_adult', 'ya_id' => 0, 'ya_name' => 'Test YA Program From News'));
        $prog = kop_ya_find_by_name(kop_ya_pdo(), 'Test YA Program From News');
        $check('news: an approved article listed on a new young adult program puts the link there and stays approved',
            $prog && strpos((string) $prog['links'], kop_rinbox_native_url('news', $approved)) !== false && kop_rinbox_native_row('news', $item['key'])['status'] === 'approved', $res['message'] ?? '');
        call_user_func($src['act'], $item['key'], 'unmove', array());
        $check('news: Undo takes the program it made back off', !kop_ya_find_by_name(kop_ya_pdo(), 'Test YA Program From News'));
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

    // The old Legislation page's list filters, as api/manage-submissions.php applies them.
    $pdo = kop_rinbox_pdo();
    $count = function (array $q, $sort = '') use ($pdo) {
        list($where, $params) = kop_rinbox_native_list_filters('legislation', $q);
        $st = $pdo->prepare('SELECT id FROM legislation ' . ($where ? 'WHERE ' . implode(' AND ', $where) : '') . ' '
            . kop_rinbox_native_list_order('legislation', $sort));
        $st->execute($params);
        return count($st->fetchAll(PDO::FETCH_COLUMN));
    };
    $all = (int) $pdo->query('SELECT COUNT(*) FROM legislation')->fetchColumn();
    $fed = (int) $pdo->query("SELECT COUNT(*) FROM legislation WHERE jurisdiction = 'Federal'")->fetchColumn();
    $check('legislation: level filter splits federal from state bills', $count(array('level' => 'federal')) === $fed
        && $count(array('level' => 'state'), 'date') + $fed === $all, "federal $fed of $all");
    $place = (string) $pdo->query("SELECT jurisdiction FROM legislation WHERE jurisdiction <> '' LIMIT 1")->fetchColumn();
    if ($place !== '') {
        $n = $pdo->prepare('SELECT COUNT(*) FROM legislation WHERE jurisdiction = ?');
        $n->execute(array($place));
        $check('legislation: place filter', $count(array('jurisdiction' => $place)) === (int) $n->fetchColumn(), $place);
    }
    $check('legislation: "by date" sorts on the introduced date', strpos(kop_rinbox_native_list_order('legislation', 'date'), 'introduced_date DESC') !== false
        && kop_rinbox_native_list_order('data', 'date') === 'ORDER BY created_at DESC');
}

function kop_rinbox_test_lawsuit(array $src, array $item, callable $check) {
    $pdo = kop_rinbox_pdo();
    $check('lawsuit: typed dates read like the old save endpoint',
        kop_rinbox_native_date('March 3, 2021') === '2021-03-03' && kop_rinbox_native_date('2020-01-02') === '2020-01-02'
        && kop_rinbox_native_date('') === null && kop_rinbox_native_date('not a date') === null);
    list($where) = kop_rinbox_native_list_filters('lawsuit', array('level' => 'federal'));
    $check('lawsuit: no level filter (the old page had none)', $where === array());
    $check('lawsuit: "by date" sorts on the filing date', strpos(kop_rinbox_native_list_order('lawsuit', 'date'), 'filing_date DESC') !== false);

    // A pending case never files its documents (unreviewed uploads stay out of the library).
    $before = kop_rinbox_native_row('lawsuit', $item['key']);
    $pdo->prepare("UPDATE lawsuits SET publication_status = 'pending' WHERE id = ?")->execute(array((int) $item['key']));
    $res = kop_rinbox_native_lawsuit_file_docs($pdo, $item['key']);
    $check('lawsuit: a pending case files nothing', $res === array('folder' => 0, 'filed' => 0), json_encode($res));
    try {
        $pdo->prepare("UPDATE lawsuits SET publication_status = 'published', filebird_folder_id = NULL, document_urls = '[]' WHERE id = ?")->execute(array((int) $item['key']));
        $res = kop_rinbox_native_lawsuit_file_docs($pdo, $item['key']);
        $check('lawsuit: a published case runs the save endpoint\'s folder step', is_array($res) && $res['filed'] === 0, json_encode($res));
    } catch (Throwable $e) {
        $check('lawsuit: a published case runs the save endpoint\'s folder step', false, $e->getMessage());
    }
    $pdo->prepare('UPDATE lawsuits SET publication_status = ?, filebird_folder_id = ?, document_urls = ? WHERE id = ?')
        ->execute(array($before['publication_status'], $before['filebird_folder_id'], $before['document_urls'], (int) $item['key']));
}

/** The News Processor's per-type details go into json_data next to what is there. */
function kop_rinbox_test_news_details(callable $check) {
    $json = kop_rinbox_native_news_details_merge(array('organizationLogoName' => 'Keep'), array(
        'closureFacilityName' => '  Example Ranch ', 'closureDate' => '2020-05-01', 'charges' => array('One', 'Two'),
        'needsAlternateTitle' => 'false', 'article_title' => 'not a detail'));
    $check('news: details merge into json_data, other keys kept', $json['organizationLogoName'] === 'Keep'
        && $json['closureFacilityName'] === 'Example Ranch' && $json['charges'] === "One\nTwo"
        && $json['needsAlternateTitle'] === false && !isset($json['article_title']), json_encode($json));
    $check('news: every News Processor detail key is editable', count(array_intersect(kop_rinbox_native_news_detail_keys(),
        array('plaintiffs', 'legalRep', 'pressReleases', 'relatedCoverage', 'staffMemberName', 'caseStatus', 'closureContext', 'ownership'))) === 8);
    $check('news: "by date" sorts on the publication date', strpos(kop_rinbox_native_list_order('news', 'date'), 'publication_date DESC') !== false);
}
