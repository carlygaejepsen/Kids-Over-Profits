<?php
/**
 * "Also covered by" corrected by hand (inc/story-coverage.php) and the
 * automatic grouping leaving those rows alone (api/news-story-groups.php),
 * on a made-up in-memory news table.
 *
 *   php -d extension=pdo_sqlite -d extension=mbstring scripts/test-story-coverage.php
 */

$root = dirname(__DIR__);
require_once $root . '/inc/story-coverage.php';

$fails = 0;
$check = function ($label, $ok, $detail = '') use (&$fails) {
    echo ($ok ? 'ok   ' : 'FAIL ') . $label . ($detail !== '' ? '  (' . $detail . ')' : '') . "\n";
    if (!$ok) $fails++;
};

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE news_submissions (id INTEGER PRIMARY KEY, article_title TEXT, alternate_title TEXT, publication_name TEXT,
    publication_date TEXT, article_url TEXT, archive_url TEXT, facilities_mentioned TEXT, status TEXT, story_group_id INTEGER)');
$ins = $pdo->prepare('INSERT INTO news_submissions (id, article_title, publication_name, publication_date, article_url, facilities_mentioned, status)
    VALUES (?, ?, ?, ?, ?, ?, ?)');
$rows = array(
    // One story told by three outlets...
    array(1, 'Teen dies after restraint at Ridge Academy, family says', 'Salt Lake Tribune', '2024-03-01', 'https://www.sltrib.com/news/ridge-death', '[]', 'approved'),
    array(2, 'Family says teen died after restraint at Ridge Academy', 'KSL', '2024-03-02', 'https://ksl.com/article/ridge-death', '[]', 'approved'),
    array(3, 'Ridge Academy teen died after restraint, family says', 'Deseret News', '2024-03-03', 'https://www.deseret.com/ridge', '[]', 'approved'),
    // ...and one about something else the matcher wrongly folds in.
    array(4, 'Ridge Academy restraint lawsuit: family says teen died', 'Fox 13', '2024-03-04', 'https://fox13now.com/ridge-lawsuit', '[]', 'approved'),
    // A story of its own, and its coverage.
    array(5, 'State closes Pine Hollow Ranch over unlicensed staff', 'AP', '2024-05-10', 'https://apnews.com/pine-hollow', '[]', 'approved'),
    array(6, 'Pine Hollow Ranch closed by state over unlicensed staff', 'KUTV', '2024-05-11', 'https://kutv.com/pine-hollow', '[]', 'approved'),
    array(7, 'Unrelated story about a bill', 'Utah News Dispatch', '2024-07-01', 'https://utahnewsdispatch.com/bill', '[]', 'approved'),
);
foreach ($rows as $r) $ins->execute($r);

$check('the manual column is added on first use', kop_news_story_manual_ensure($pdo));
$res = kop_news_rebuild_story_groups($pdo);
$state = kop_story_cov_state($pdo, array(1, 2, 3, 4, 5, 6, 7));
$check('automatic grouping: 1-4 one story', $state[1][0] === 1 && $state[2][0] === 1 && $state[3][0] === 1 && $state[4][0] === 1, json_encode($state));
$check('automatic grouping: 5-6 one story, 7 alone', $state[5][0] === 5 && $state[6][0] === 5 && $state[7][0] === null);

// Take 4 off story 1.
$r = kop_story_cov_apply($pdo, 1, array(2, 3), array());
$s = kop_story_cov_state($pdo, array(1, 2, 3, 4));
$check('taken off: 4 stands alone, marked manual', $s[4] === array(null, 1), json_encode($s));
$check('kept: 1-3 still one story, marked manual', $s[1] === array(1, 1) && $s[2] === array(1, 1) && $s[3] === array(1, 1));
$check('counts', $r['removed'] === 1 && $r['added'] === 0);

// The automatic grouping (a rebuild, and a resave of 4) leaves the correction alone.
kop_news_rebuild_story_groups($pdo);
kop_news_assign_story_group($pdo, 4);
kop_news_assign_story_group($pdo, 2);
$s2 = kop_story_cov_state($pdo, array(1, 2, 3, 4, 5, 6));
$check('a rebuild and resaves keep the correction', $s2[4] === array(null, 1) && $s2[1] === array(1, 1) && $s2[2] === array(1, 1), json_encode($s2));
$check('a rebuild still groups the rest', $s2[5][0] === 5 && $s2[6][0] === 5);

// Undo puts the four rows back exactly.
kop_story_cov_undo($pdo, $r['before'], $r['after']);
$check('undo: back as before', kop_story_cov_state($pdo, array(1, 2, 3, 4)) == $r['before']);

// Undo refuses once the rows changed since.
$r = kop_story_cov_apply($pdo, 1, array(2, 3), array());
$pdo->exec('UPDATE news_submissions SET story_group_id = 1, story_group_manual = 1 WHERE id = 4');
$refused = false;
try { kop_story_cov_undo($pdo, $r['before'], $r['after']); } catch (RuntimeException $e) { $refused = true; }
$check('undo refused after another change', $refused);
$pdo->exec('UPDATE news_submissions SET story_group_id = NULL WHERE id = 4');

// Add by link: 7 joins story 5 (a Wayback copy of its link works too).
$found = kop_story_cov_resolve($pdo, "https://web.archive.org/web/20240701000000/https://utahnewsdispatch.com/bill/\n#9999\nhttps://example.com/nothing");
$check('resolve: a Wayback copy finds the article', $found['ids'] === array(7), json_encode($found));
$check('resolve: unknown id and link reported', $found['unknown'] === array('#9999', 'https://example.com/nothing'));
$r = kop_story_cov_apply($pdo, 5, array(6), array(7));
$s = kop_story_cov_state($pdo, array(5, 6, 7));
$check('added: 5, 6, 7 one story', $s[5] === array(5, 1) && $s[6] === array(5, 1) && $s[7] === array(5, 1), json_encode($s));

// Moving 1 (its old group's anchor) into story 5: 2 and 3 stay together under a new id.
$r = kop_story_cov_apply($pdo, 5, array(6, 7), array('1'));
$s = kop_story_cov_state($pdo, array(1, 2, 3, 5));
$check('moved: 1 joins story 5 (group id 1 now)', $s[1][0] === 1 && $s[5][0] === 1, json_encode($s));
$check('moved: 2 and 3 keep their story under id 2', $s[2][0] === 2 && $s[3][0] === 2);
kop_story_cov_undo($pdo, $r['before'], $r['after']);
$s = kop_story_cov_state($pdo, array(1, 2, 3, 5, 6, 7));
$check('undo the move', $s[1][0] === 1 && $s[2][0] === 1 && $s[5][0] === 5 && $s[7][0] === 5, json_encode($s));

// Nothing changed: nothing marked.
$before = kop_story_cov_state($pdo, array(5, 6, 7));
$r = kop_story_cov_apply($pdo, 5, array(6, 7), array());
$check('no change: before === after', $r['before'] === $r['after'] && kop_story_cov_state($pdo, array(5, 6, 7)) === $before);

// Untick everything: the card stands alone.
$r = kop_story_cov_apply($pdo, 5, array(), array());
$s = kop_story_cov_state($pdo, array(5, 6, 7));
$check('untick all: each stands alone', $s[5][0] === null && $s[6][0] === null && $s[7][0] === null, json_encode($s));

$check('label', kop_story_cov_label(array('id' => 2, 'article_title' => 'T', 'alternate_title' => '', 'publication_name' => 'KSL', 'publication_date' => '2024-03-02', 'article_url' => '', 'status' => 'pending')) === 'KSL, Mar 2, 2024: T (not on the feed yet)');

echo $fails ? "\n$fails failed\n" : "\nall passed\n";
exit($fails ? 1 : 0);
