<?php
/**
 * Offline test for api/lib-journalists.php against tmp/prod.sqlite.
 *
 *   php scripts/test-journalists.php [--list]
 *
 * Copies news_submissions into memory, builds the journalists tables there,
 * and runs the real extraction: byline splitting and the person filter on
 * known cases, then a full sync over every news entry, a second sync that
 * must change nothing, ignore and merge. --list prints every journalist
 * found and every skipped byline. The mirror is never written.
 */

if (PHP_SAPI !== 'cli') {
    exit("CLI only.\n");
}

$root   = dirname(__DIR__);
$mirror = $root . '/tmp/prod.sqlite';
if (!file_exists($mirror)) {
    exit("tmp/prod.sqlite not found; run scripts/sync-prod-sqlite.py first.\n");
}
require $root . '/api/lib-journalists.php';

$list = in_array('--list', $argv, true);
$failures = 0;
function check(bool $ok, string $what): void {
    global $failures;
    if (!$ok) {
        $failures++;
        echo "FAIL: $what\n";
    }
}

// --- Byline splitting and the person filter ---------------------------------
$splits = [
    'Sally Ho and Claire Galofaro'            => ['Sally Ho', 'Claire Galofaro'],
    'Priscilla Carraman, Katrina Webber'      => ['Priscilla Carraman', 'Katrina Webber'],
    'By Jane Doe'                             => ['Jane Doe'],
    'Jane Doe - Staff Writer'                 => ['Jane Doe', 'Staff Writer'],
    'Mary-Kate Olsen'                         => ['Mary-Kate Olsen'],
    'Martin Luther King, Jr.'                 => ['Martin Luther King, Jr'],
    'Jane Doe (The Salt Lake Tribune)'        => ['Jane Doe'],
    "Devin Thomas O'Shea and Samir Knox"      => ["Devin Thomas O'Shea", 'Samir Knox'],
    ''                                        => [],
];
foreach ($splits as $in => $want) {
    $got = kop_journalist_split_byline($in);
    check($got === $want, "split '$in' => " . json_encode($got));
}
$people = ['Riley Board', 'Holly Ramer', "Devin Thomas O'Shea", 'Onz Chéry', 'Martin Luther King, Jr'];
$notPeople = ['No author name available', 'Unknown', 'The Associated Press', 'WSOCTV.com News Staff',
    'WENG Newsroom', 'Network Indiana', 'The Hans India', 'Staff Writer', 'Madonna'];
foreach ($people as $n) {
    check(kop_journalist_is_person_name($n), "'$n' should be a person");
}
foreach ($notPeople as $n) {
    check(!kop_journalist_is_person_name($n), "'$n' should not be a person");
}
check(kop_journalist_name_key('Onz Chéry') === kop_journalist_name_key('Onz Chery'), 'accents fold in the key');

// --- Full sync over the mirror ----------------------------------------------
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec("ATTACH DATABASE " . $pdo->quote($mirror) . " AS m");
$pdo->exec("CREATE TABLE news_submissions AS SELECT id, author, status, publication_name, publication_date FROM m.news_submissions");
$pdo->exec("CREATE TABLE journalists (
    id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, name_key TEXT NOT NULL UNIQUE,
    aliases TEXT, outlet TEXT, email TEXT, phone TEXT, social TEXT, website TEXT, location TEXT,
    beat TEXT, outreach TEXT NOT NULL DEFAULT 'not_contacted', notes TEXT,
    status TEXT NOT NULL DEFAULT 'active', source TEXT NOT NULL DEFAULT 'manual',
    created_at TEXT DEFAULT CURRENT_TIMESTAMP, updated_at TEXT DEFAULT CURRENT_TIMESTAMP)");
$pdo->exec("CREATE TABLE journalist_articles (journalist_id INTEGER NOT NULL, news_id INTEGER NOT NULL,
    PRIMARY KEY (journalist_id, news_id))");

$first = kop_journalist_sync_all($pdo);
$count = static fn(string $sql) => (int) $pdo->query($sql)->fetchColumn();
$journalists = $count("SELECT COUNT(*) FROM journalists");
$links = $count("SELECT COUNT(*) FROM journalist_articles");
$eligible = $count("SELECT COUNT(*) FROM news_submissions WHERE status IN ('draft','submitted','approved','published')");
$withAuthor = $count("SELECT COUNT(DISTINCT news_id) FROM journalist_articles");
printf("%d news entries, %d eligible; %d journalists, %d links, %d articles with a journalist; %d distinct skipped bylines\n",
    $first['articles'], $eligible, $journalists, $links, $withAuthor, count($first['skipped']));
check($journalists > 150, 'at least 150 journalists found');
check($first['created'] === $journalists, 'created count matches rows');

$rejectedLinks = $count("SELECT COUNT(*) FROM journalist_articles ja JOIN news_submissions n ON n.id = ja.news_id
    WHERE n.status IN ('rejected','deleted')");
check($rejectedLinks === 0, 'no links to rejected or deleted entries');
foreach (['Sally Ho', 'Claire Galofaro', 'Riley Board', 'Holly Ramer'] as $n) {
    check($count("SELECT COUNT(*) FROM journalists WHERE name_key = " . $pdo->quote(kop_journalist_name_key($n))) === 1,
        "$n extracted");
}
foreach (['No author name available', 'Unknown', 'The Associated Press'] as $n) {
    check($count("SELECT COUNT(*) FROM journalists WHERE name_key = " . $pdo->quote(kop_journalist_name_key($n))) === 0,
        "$n not extracted");
}

$second = kop_journalist_sync_all($pdo);
check($second['created'] === 0, 'second sync creates nothing');
check($count("SELECT COUNT(*) FROM journalist_articles") === $links, 'second sync keeps the same links');

// Ignoring a name keeps it out of the next sync.
$rb = (int) $pdo->query("SELECT id FROM journalists WHERE name_key = 'riley board'")->fetchColumn();
$pdo->exec("UPDATE journalists SET status = 'ignored' WHERE id = $rb");
kop_journalist_sync_all($pdo);
check($count("SELECT COUNT(*) FROM journalist_articles WHERE journalist_id = $rb") === 0, 'ignored journalist loses links');
check($count("SELECT COUNT(*) FROM journalists WHERE name_key = 'riley board'") === 1, 'ignored journalist not re-created');
$pdo->exec("UPDATE journalists SET status = 'active' WHERE id = $rb");
kop_journalist_sync_all($pdo);

// Merge: articles move, the old name becomes an alias and still matches.
$a = (int) $pdo->query("SELECT id FROM journalists WHERE name_key = 'sally ho'")->fetchColumn();
$b = (int) $pdo->query("SELECT id FROM journalists WHERE name_key = 'claire galofaro'")->fetchColumn();
$pdo->exec("UPDATE journalists SET email = 'b@example.org', notes = 'from b' WHERE id = $b");
$bLinks = $count("SELECT COUNT(*) FROM journalist_articles WHERE journalist_id = $b");
kop_journalist_merge($pdo, $a, $b);
check($count("SELECT COUNT(*) FROM journalists WHERE id = $b") === 0, 'merged row deleted');
check($pdo->query("SELECT email FROM journalists WHERE id = $a")->fetchColumn() === 'b@example.org', 'merge fills empty email');
check(strpos((string) $pdo->query("SELECT aliases FROM journalists WHERE id = $a")->fetchColumn(), 'Claire Galofaro') !== false,
    'merged name kept as alias');
check($count("SELECT COUNT(*) FROM journalist_articles WHERE journalist_id = $a") >= $bLinks, 'merge moves articles');
$third = kop_journalist_sync_all($pdo);
check($third['created'] === 0, 'alias matches after merge, nothing re-created');

if ($list) {
    echo "\nJournalists (articles, outlets):\n";
    $rows = $pdo->query("SELECT j.name, COUNT(ja.news_id) n, GROUP_CONCAT(DISTINCT s.publication_name) outlets
        FROM journalists j LEFT JOIN journalist_articles ja ON ja.journalist_id = j.id
        LEFT JOIN news_submissions s ON s.id = ja.news_id GROUP BY j.id ORDER BY n DESC, j.name");
    foreach ($rows as $r) {
        printf("  %3d  %-40s %s\n", $r['n'], $r['name'], $r['outlets']);
    }
    echo "\nSkipped bylines (articles):\n";
    foreach ($first['skipped'] as $name => $n) {
        printf("  %3d  %s\n", $n, $name);
    }
}

echo $failures ? "\n$failures check(s) failed.\n" : "\nAll checks passed.\n";
exit($failures ? 1 : 0);
