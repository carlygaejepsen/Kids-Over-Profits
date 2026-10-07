<?php
/**
 * Pending news, lawsuits and bills already in our records
 * (inc/review-inbox/_on-file.php): each rule on made-up rows, the rows that
 * must stay in Pending, then what the check finds in tmp/prod.sqlite (read only).
 *
 *   php -d extension=pdo_sqlite -d extension=mbstring scripts/test-review-on-file.php [--list] [--db=tmp/prod.sqlite]
 */

if (PHP_SAPI !== 'cli') exit("CLI only.\n");
error_reporting(E_ALL);
set_error_handler(function ($no, $str, $file, $line) { throw new ErrorException($str, 0, $no, $file, $line); });

define('ABSPATH', dirname(__DIR__) . '/');
define('HOUR_IN_SECONDS', 3600);
function get_stylesheet_directory() { return dirname(__DIR__); }
function get_transient($k) { return $GLOBALS['t'][$k] ?? false; }
function set_transient($k, $v, $ttl = 0) { $GLOBALS['t'][$k] = $v; return true; }
function kop_submission_review_url($type) { return 'https://example.test/submissions-review/?type=' . $type; }

require dirname(__DIR__) . '/inc/review-inbox/_on-file.php';

$args = array();
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--([a-z]+)(?:=(.*))?$/', $a, $m)) $args[$m[1]] = $m[2] ?? true;
}
$fails = 0;
function check($ok, $what) {
    global $fails;
    echo ($ok ? '  ok   ' : '  FAIL ') . $what . "\n";
    if (!$ok) $fails++;
}

/* ---- Made-up rows ---------------------------------------------------------- */

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec("CREATE TABLE news_submissions (id INTEGER PRIMARY KEY, article_url TEXT, article_title TEXT, alternate_title TEXT, publication_name TEXT, status TEXT, updated_at TEXT)");
$pdo->exec("CREATE TABLE lawsuits (id INTEGER PRIMARY KEY, case_name TEXT, case_number TEXT, court TEXT, source_urls TEXT, document_urls TEXT, publication_status TEXT, updated_at TEXT)");
$pdo->exec("CREATE TABLE legislation (id INTEGER PRIMARY KEY, bill_title TEXT, bill_number TEXT, jurisdiction TEXT, session_year TEXT, official_url TEXT, full_text_url TEXT, publication_status TEXT, updated_at TEXT)");
$pdo->exec("CREATE TABLE facilities_v2 (id INTEGER PRIMARY KEY, name TEXT, json_data TEXT, updated_at TEXT)");

$news = array(
    // id, url, title, outlet, status
    array(1, 'https://www.example-news.com/story-a', 'Story A', 'Example News', 'approved'),
    array(2, 'https://example-news.com/story-a/?utm_source=x', 'Story A again', 'Example News', 'submitted'),   // same address
    array(3, 'https://other.com/rejected-one', 'Rejected', 'Other', 'rejected'),
    array(4, 'https://other.com/rejected-one', 'Rejected', 'Other', 'submitted'),                               // only a rejected row: stays
    array(5, 'https://paper.com/a', 'Teen dies at ranch', 'The Paper', 'published'),
    array(6, 'https://paper.com/amp/a', 'Teen Dies at Ranch!', 'The Paper', 'submitted'),                       // same headline + outlet
    array(7, 'https://elsewhere.com/b', 'Teen dies at ranch', 'Another Paper', 'submitted'),                     // same headline, other outlet: stays
    array(8, 'https://site.org/materials/report.pdf', 'Report', 'Site', 'submitted'),                           // facility Materials and links
    array(9, 'https://site.org/only-in-notes', 'Notes', 'Site', 'submitted'),                                   // only in notes: stays
    array(10, 'https://court.gov/doc/77.pdf', 'Filing', 'Court', 'submitted'),                                  // a kept lawsuit's document
    array(11, 'https://pending-twin.com/x', 'Twin', 'Twin', 'submitted'),
    array(12, 'https://pending-twin.com/x', 'Twin', 'Twin', 'submitted'),                                       // two pending: neither is a record
    array(13, 'https://programsite.com', 'Home', 'Program', 'submitted'),                                       // bare site
);
$st = $pdo->prepare("INSERT INTO news_submissions (id, article_url, article_title, publication_name, status, updated_at) VALUES (?, ?, ?, ?, ?, '2026-10-01')");
foreach ($news as $r) $st->execute($r);
$pdo->exec("INSERT INTO facilities_v2 VALUES (1, 'Ranch Academy', " . $pdo->quote(json_encode(array(
    'resourceLinks' => array(array('url' => 'http://site.org/materials/report.pdf', 'label' => 'Report'), array('url' => 'https://programsite.com/')),
    'notes' => array('see https://site.org/only-in-notes'),
))) . ", '2026-10-01')");

$st = $pdo->prepare("INSERT INTO lawsuits (id, case_name, case_number, source_urls, document_urls, publication_status, updated_at) VALUES (?, ?, ?, ?, ?, ?, '2026-10-01')");
$st->execute(array(1, 'Doe v. Ranch', '2:20-cv-01234', '["https://court.gov/case"]', '["https://court.gov/doc/77.pdf"]', 'published'));
$st->execute(array(2, 'Doe v. Ranch (again)', '2:20-CV-01234', '[]', '[]', 'pending'));                     // same case number
$st->execute(array(3, 'Roe v. Camp', '', '["https://court.gov/case/"]', '[]', 'pending'));                  // same address
$st->execute(array(4, 'Poe v. School', '12', '["https://news.com/poe"]', '[]', 'pending'));                 // short number, new address: stays
$st->execute(array(5, 'Draft case', '3:19-cv-55555', '[]', '[]', 'draft'));
$st->execute(array(6, 'Draft twin', '3:19-cv-55555', '[]', '[]', 'pending'));                              // a draft is a record

$st = $pdo->prepare("INSERT INTO legislation (id, bill_title, bill_number, jurisdiction, session_year, official_url, full_text_url, publication_status, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, '2026-10-01')");
$st->execute(array(1, 'Youth Safety Act', 'SB 127', 'Utah', '2021', 'https://le.utah.gov/sb127', '', 'published'));
$st->execute(array(2, '', 'S.B. 127', 'Utah', '2021', '', '', 'pending'));                                   // same bill
$st->execute(array(3, '', 'SB 127', 'Utah', '2023', '', '', 'pending'));                                      // other session: stays
$st->execute(array(4, '', '', '', '', '', 'https://le.utah.gov/sb127/', 'pending'));                          // the kept bill's address

echo "Rules on made-up rows\n";
$n = kop_on_file_pending($pdo, 'news');
foreach (array(2 => 'same address (www, utm, slash aside)', 6 => 'same headline and outlet', 8 => "a facility's Materials and links", 10 => "a kept lawsuit's document") as $id => $why) {
    check(isset($n[$id]), "news #$id on file: $why" . (isset($n[$id]) ? ' -> ' . $n[$id]['label'] : ''));
}
foreach (array(4 => 'matches only a rejected row', 7 => 'same headline from another outlet', 9 => 'only in notes', 11 => 'twin of another pending row', 12 => 'twin of another pending row', 13 => 'a bare site') as $id => $why) {
    check(!isset($n[$id]), "news #$id stays in Pending: $why");
}
check(strpos($n[8]['label'], 'Ranch Academy') !== false, 'the facility is named');
check(strpos($n[2]['label'], '#1') !== false && $n[2]['url'] !== '', 'the kept article is named by id and linked');

$l = kop_on_file_pending($pdo, 'lawsuit');
check(isset($l[2]), 'lawsuit #2 on file: same case number (case and punctuation aside)');
check(isset($l[3]), 'lawsuit #3 on file: same address as a published case');
check(isset($l[6]), 'lawsuit #6 on file: a draft counts as a record');
check(!isset($l[4]), 'lawsuit #4 stays: a two-digit "case number" never matches');

$b = kop_on_file_pending($pdo, 'legislation');
check(isset($b[2]), 'bill #2 on file: same number, jurisdiction and session');
check(!isset($b[3]), 'bill #3 stays: another session');
check(isset($b[4]), 'bill #4 on file: the kept bill\'s address');

check(kop_on_file_ids($pdo, 'news') === array(2, 6, 8, 10), 'ids in order: ' . implode(',', kop_on_file_ids($pdo, 'news')));
check(kop_on_file_pending($pdo, 'wiki') === array(), 'other types: nothing');

/* ---- The mirror ------------------------------------------------------------ */

$mirror = $args['db'] ?? (dirname(__DIR__) . '/tmp/prod.sqlite');
if (is_file($mirror)) {
    echo "\nThe mirror ($mirror, read only)\n";
    $GLOBALS['t'] = array();
    $real = new PDO('sqlite:' . $mirror, null, null, array(PDO::SQLITE_ATTR_OPEN_FLAGS => PDO::SQLITE_OPEN_READONLY));
    $real->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    // A fresh memo per database: the function keeps one per request.
    foreach (array('news' => array('news_submissions', 'status', 'submitted', 'article_title'), 'lawsuit' => array('lawsuits', 'publication_status', 'pending', 'case_name'), 'legislation' => array('legislation', 'publication_status', 'pending', 'bill_title')) as $type => $t) {
        $start = microtime(true);
        $found = kop_on_file_find($real, $type);
        $pending = (int) $real->query("SELECT COUNT(*) FROM {$t[0]} WHERE {$t[1]} = '{$t[2]}'")->fetchColumn();
        printf("  %-12s %4d pending, %3d already on file (%.2f s)\n", $type, $pending, count($found), microtime(true) - $start);
        if (!empty($args['list'])) {
            foreach ($found as $id => $f) {
                $title = $real->query("SELECT {$t[3]} FROM {$t[0]} WHERE id = " . (int) $id)->fetchColumn();
                echo "      #$id " . mb_substr((string) $title, 0, 60) . "\n        -> {$f['label']}\n";
            }
        }
    }
} else {
    echo "\n(no mirror at $mirror: made-up rows only)\n";
}

echo $fails ? "\n$fails FAILED\n" : "\nAll passed.\n";
exit($fails ? 1 : 0);
