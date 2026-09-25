<?php
/**
 * Offline test for api/lib-news-post-import.php against tmp/prod.sqlite.
 *
 *   php scripts/test-news-post-import.php [--list]
 *
 * Copies news_submissions into memory, runs the import over every published
 * post in the four news categories, and checks what reached the feed: every
 * post imported or accounted for, each row with a title, a date and a source
 * URL, the ACF fields preferred over the body, and a second run adding
 * nothing. --list prints every imported row. The mirror is never written.
 */

if (PHP_SAPI !== 'cli') {
    exit("CLI only.\n");
}

$root   = dirname(__DIR__);
$mirror = $root . '/tmp/prod.sqlite';
if (!file_exists($mirror)) {
    exit("tmp/prod.sqlite not found; run scripts/sync-prod-sqlite.py first.\n");
}
require $root . '/api/lib-news-post-import.php';

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec("ATTACH DATABASE " . $pdo->quote($mirror) . " AS m");
$pdo->exec("CREATE TABLE news_submissions AS SELECT * FROM m.news_submissions");
$before = (int) $pdo->query("SELECT COUNT(*) FROM news_submissions")->fetchColumn();

// The posts, shaped the way kop_news_post_import_sources() shapes them.
$ids = $pdo->query(
    "SELECT DISTINCT p.ID FROM m.wpdl_posts p
     JOIN m.wpdl_term_relationships tr ON tr.object_id = p.ID
     JOIN m.wpdl_term_taxonomy tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = 'category'
     JOIN m.wpdl_terms t ON t.term_id = tt.term_id
     WHERE t.slug IN ('" . implode("','", kop_news_post_import_categories()) . "')
       AND p.post_type = 'post' AND p.post_status = 'publish'
     ORDER BY p.post_date"
)->fetchAll(PDO::FETCH_COLUMN);
$posts = [];
$metaQ = $pdo->prepare("SELECT meta_key, meta_value FROM m.wpdl_postmeta WHERE post_id = ?");
$catQ  = $pdo->prepare(
    "SELECT t.name FROM m.wpdl_term_relationships tr
     JOIN m.wpdl_term_taxonomy tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = 'category'
     JOIN m.wpdl_terms t ON t.term_id = tt.term_id WHERE tr.object_id = ?"
);
foreach ($ids as $id) {
    $p = $pdo->query("SELECT ID, post_title, post_date, post_content, post_name FROM m.wpdl_posts WHERE ID = " . (int) $id)->fetch(PDO::FETCH_ASSOC);
    $metaQ->execute([$id]);
    $meta = [];
    foreach ($metaQ->fetchAll(PDO::FETCH_KEY_PAIR) as $k => $v) $meta[$k] = (string) $v;
    $catQ->execute([$id]);
    $posts[] = [
        'id' => (int) $p['ID'], 'title' => $p['post_title'], 'date' => $p['post_date'], 'content' => $p['post_content'],
        'permalink' => 'https://kidsoverprofits.org/' . $p['post_name'] . '/',
        'categories' => html_entity_decode_all($catQ->fetchAll(PDO::FETCH_COLUMN)), 'meta' => $meta,
    ];
}
$indexHtml = (string) $pdo->query("SELECT post_content FROM m.wpdl_posts WHERE post_name = 'news-2' AND post_type = 'page'")->fetchColumn();
$entries = kop_news_page_entries($indexHtml, 'https://kidsoverprofits.org/news-2/');
$posts = array_merge($posts, $entries);
function html_entity_decode_all(array $a) { return array_map(function ($s) { return html_entity_decode((string) $s, ENT_QUOTES); }, $a); }

$failures = 0;
function check($label, $ok, $detail = '') {
    global $failures;
    if ($ok) { echo "PASS $label\n"; return; }
    $failures++;
    echo "FAIL $label" . ($detail !== '' ? "\n     $detail" : '') . "\n";
}

$dry = kop_news_post_import($pdo, $posts, ['dry' => true, 'link_facilities' => false]);
check('a dry run writes nothing', (int) $pdo->query("SELECT COUNT(*) FROM news_submissions")->fetchColumn() === $before);

$stats = kop_news_post_import($pdo, $posts, ['link_facilities' => false]);
printf("scanned %d, imported %d, duplicate url %d, not an article %d\n",
    $stats['scanned'], $stats['imported'], $stats['duplicate_url'], $stats['not_article']);
check('the dry run predicted the real one', $dry['imported'] === $stats['imported']);
check('every news post is accounted for', $stats['scanned'] === count($posts)
    && $stats['imported'] + $stats['duplicate_url'] + $stats['not_article'] + $stats['already_imported'] === count($posts));
check('at least 70 of the posts reach the feed', $stats['imported'] >= 70, 'imported ' . $stats['imported']);
check('the 2024 index yields its dated entries', count($entries) >= 35, count($entries) . ' entries');
$indexUrls = array_map(function ($e) { return kop_npi_url_key($e['meta']['source_url']); }, $entries);
$feedUrls  = array_map('kop_npi_url_key', $pdo->query("SELECT article_url FROM news_submissions")->fetchAll(PDO::FETCH_COLUMN));
$feedTitles = array_map(function ($t) { return substr(preg_replace('/[^a-z0-9]/', '', strtolower((string) $t)), 0, 60); },
    $pdo->query("SELECT article_title FROM news_submissions")->fetchAll(PDO::FETCH_COLUMN));
$lost = [];
foreach ($entries as $e) {
    $tk = substr(preg_replace('/[^a-z0-9]/', '', strtolower($e['meta']['article_title'])), 0, 60);
    if (!in_array(kop_npi_url_key($e['meta']['source_url']), $feedUrls, true) && !in_array($tk, $feedTitles, true)) $lost[] = $e['meta']['source_url'];
}
check('every entry of the index is in the feed afterwards, by URL or headline', !$lost, implode(', ', $lost));

$rows = $pdo->query("SELECT * FROM news_submissions WHERE submitted_by = 'wp-news-import'")->fetchAll(PDO::FETCH_ASSOC);
check('the table holds what the stats say', count($rows) === $stats['imported']);
$noUrl = $noDate = $noSummary = $labelled = $badJson = [];
foreach ($rows as $r) {
    $jd = json_decode($r['json_data'], true);
    $pid = !empty($jd['source_post_id']) ? $jd['source_post_id'] : 'index:' . $r['article_url'];
    if (!preg_match('#^https?://#', (string) $r['article_url'])) $noUrl[] = $pid;
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $r['publication_date'])) $noDate[] = $pid;
    // An entry from the 2024 index can be a bare headline with no notes.
    if (trim((string) $r['summary']) === '' && !empty($jd['source_post_id'])) $noSummary[] = $pid;
    if (preg_match('/^(Source|Author|Summary|Trigger)/i', $r['publication_name'] . '|' . $r['author'] . '|' . $r['summary'])) $labelled[] = $pid;
    foreach (['tags', 'facilities_mentioned', 'content_warnings', 'json_data'] as $c) {
        if (!is_array(json_decode((string) $r[$c], true))) $badJson[] = "$pid.$c";
    }
}
check('every row has a source URL', !$noUrl, 'posts ' . implode(', ', $noUrl));
check('every row has a publication date', !$noDate, 'posts ' . implode(', ', $noDate));
check('every imported post has a summary', !$noSummary, 'posts ' . implode(', ', $noSummary));
check('no label is left on a value', !$labelled, 'posts ' . implode(', ', $labelled));
check('the JSON columns decode', !$badJson, implode(', ', $badJson));

// Post 2489 has no link in its body; its URL, date and source are fields only.
$r = $pdo->query("SELECT * FROM news_submissions WHERE json_data LIKE '%\"source_post_id\":2489,%'")->fetch(PDO::FETCH_ASSOC);
check('a URL held only in source_url is used', $r && strpos($r['article_url'], 'pressherald.com') !== false);
check('the publication comes from article_source', $r && $r['publication_name'] === 'Kennebec Journal');
check('the date comes from the date field', $r && $r['publication_date'] === '2019-09-23');
check('facilities come through as objects', $r && json_decode($r['facilities_mentioned'], true) === [['name' => 'Good Will-Hinckley', 'facility_id' => null]]);

$again = kop_news_post_import($pdo, $posts, ['link_facilities' => false]);
check('a second run adds nothing', $again['imported'] === 0);

if (in_array('--list', $argv, true)) {
    foreach ($stats['rows'] as $row) {
        printf("%6s  %s  %-28s %s\n", $row['post_id'], $row['date'], mb_substr($row['publication'], 0, 28), $row['title']);
    }
}

echo $failures ? "\nnews post import: $failures FAILURES\n" : "\nnews post import: PASS\n";
exit($failures ? 1 : 0);
