<?php
/**
 * Offline test for the timeline band in inc/article-parts.php.
 *
 *   php scripts/test-article-timeline.php
 *
 * Nine articles are dated lists - Juvenile Justice runs to 71 entries across
 * 1660 to 2023 - and the band is built by editing their rendered HTML to add
 * an id to each dated entry. Editing somebody's forty thousand word article
 * on the way to the screen is the risk here, so most of this checks that the
 * entries are found exactly, that nothing else is touched, and that a page
 * which is not a timeline gets no band at all.
 *
 * The year shapes tested are the ones these pages actually use: "1912 -",
 * "1730s-1790s", "1179 CE", "1968:" and "c. 1400".
 */

if (PHP_SAPI !== 'cli') {
    exit("CLI only.\n");
}

define('ABSPATH', dirname(__DIR__) . '/');

// --- WordPress stubs --------------------------------------------------------

function esc_attr($text) { return htmlspecialchars((string) $text, ENT_QUOTES); }
function esc_html($text) { return htmlspecialchars((string) $text, ENT_QUOTES); }
function esc_url($url) { return (string) $url; }
function wp_strip_all_tags($text) { return strip_tags((string) $text); }
function sanitize_title($text) {
    $text = strtolower(trim((string) $text));
    return trim(preg_replace('/[^a-z0-9]+/', '-', $text), '-');
}
function apply_filters($tag, $value) { return $value; }
function add_filter() {}
function add_action() {}
function home_url($path = '/') { return 'https://example.test' . $path; }
function get_page_by_path($slug) { return null; }
function get_the_title($p) { return ''; }
function get_permalink($p) { return ''; }
function is_singular() { return false; }
function get_post_field($f, $i) { return ''; }
function get_the_ID() { return 0; }

require ABSPATH . 'inc/admin.php';
require ABSPATH . 'inc/article-parts.php';

// --- Harness ----------------------------------------------------------------

$failures = 0;

function check($label, $got, $want) {
    global $failures;
    if ($got === $want) { echo "PASS $label\n"; return; }
    $failures++;
    printf("FAIL %s\n     got:  %s\n     want: %s\n", $label, var_export($got, true), var_export($want, true));
}

function ok($label, $got) {
    global $failures;
    if ($got) { echo "PASS $label\n"; return; }
    $failures++;
    printf("FAIL %s\n", $label);
}

/** A list item in the shape these articles use. */
function entry($bold, $detail = 'Some detail.') {
    return '<li><strong>' . $bold . '</strong><ul><li>' . $detail . '</li></ul></li>';
}

echo "-- Reading a year off an entry --\n";

check('a plain year', kop_article_entry_year('1912 &#8211; Devereux Foundation founded'), 1912);
check('a decade span', kop_article_entry_year('1730s&#8211;1790s &#8211; Early Taxonomies'), 1730);
check('an era suffix', kop_article_entry_year('1179 CE &#8211; Third Lateran Council'), 1179);
check('a colon', kop_article_entry_year('1968: Synanon opens'), 1968);
check('an approximate year', kop_article_entry_year('c. 1400 &#8211; Something'), 1400);
check('a year inside a sentence is not a date', kop_article_entry_year('Founded in 1912 by Helena'), null);
check('a number that is not a year', kop_article_entry_year('12 children died'), null);
check('nothing at all', kop_article_entry_year(''), null);

echo "\n-- Finding the entries --\n";

$html = '<p>Intro.</p><ul>'
    . entry('1660 &#8211; First U.S. Workhouse Established (Boston)')
    . entry('1682 &#8211; William Penn&#8217;s Reforms')
    . entry('Not a dated entry at all')
    . entry('1899 &#8211; First Juvenile Court')
    . '</ul>';
$before = $html;
$entries = kop_article_timeline_entries($html);

check('only the dated entries are found', count($entries), 3);
check('in the order they appear',
    array_map(function ($e) { return $e['year']; }, $entries),
    array(1660, 1682, 1899));
check('the id says the year and the title', $entries[0]['id'],
    'y1660-first-u-s-workhouse-established-boston');
ok('the undated entry is left alone', strpos($html, '<li><strong>Not a dated entry') !== false);
check('the words are untouched',
    preg_replace('/\s+/', ' ', strip_tags($html)),
    preg_replace('/\s+/', ' ', strip_tags($before)));
check('every dated entry is marked', substr_count($html, 'kop-article-dated'), 3);
ok('each id is on its own list item',
    preg_match('~<li id="y1660-[^"]*" class="kop-article-dated"><strong>1660~', $html) === 1);

echo "\n-- Ids stay unique, and an editor's own id is kept --\n";

$dupes = '<ul>' . entry('1912 &#8211; Same') . entry('1912 &#8211; Same') . entry('1912 &#8211; Same') . '</ul>';
$found = kop_article_timeline_entries($dupes);
check('a repeated title does not repeat its id',
    array_map(function ($e) { return $e['id']; }, $found),
    array('y1912-same', 'y1912-same-2', 'y1912-same-3'));

$own = '<ul><li id="chosen-by-hand"><strong>1912 &#8211; Kept</strong></li></ul>';
$found = kop_article_timeline_entries($own);
check('an id already on the entry is kept', $found[0]['id'], 'chosen-by-hand');
check('and is not written twice', substr_count($own, 'id="chosen-by-hand"'), 1);

echo "\n-- When a band is drawn, and when it is not --\n";

function band($entries) {
    ob_start();
    kop_article_timeline($entries);
    return ob_get_clean();
}

function fake_entries($count, $from = 1900, $step = 10) {
    $out = array();
    for ($i = 0; $i < $count; $i++) {
        $year = $from + ($i * $step);
        $out[] = array('id' => 'y' . $year, 'year' => $year, 'text' => 'Entry ' . $year);
    }
    return $out;
}

check('a page with no dated entries gets nothing', band(array()), '');
check('and one with too few gets nothing', band(fake_entries(KOP_ARTICLE_TIMELINE_MIN - 1)), '');
ok('enough entries get a band', strpos(band(fake_entries(12)), 'kop-article-timeline__band') !== false);

/* Twelve entries all in the same couple of years is a list, not a span. */
$tight = fake_entries(12, 2020, 0);
check('entries crammed into one year get no band', band($tight), '');

echo "\n-- What the band says --\n";

$long = fake_entries(30, 1660, 12);   // 1660 to 2008
$out  = band($long);
check('one dot per entry', substr_count($out, 'kop-article-timeline__dot'), 30);
ok('the caption gives the count and the span',
    strpos($out, '30 dated entries, 1660 to 2008') !== false);
ok('it is a landmark a screen reader can find',
    strpos($out, 'aria-label="Timeline of this article"') !== false);
ok('the dots are out of the tab order',
    substr_count($out, 'tabindex="-1"') === 30 && substr_count($out, 'aria-hidden="true"') >= 30);

preg_match_all('~class="kop-article-timeline__mark[^"]*"~', $out, $marks);
ok('the labelled marks are few enough to tab through: ' . count($marks[0]),
    count($marks[0]) >= 3 && count($marks[0]) <= 13);
$mark_links = preg_match_all('~<a class="kop-article-timeline__mark[^"]*"~', $out);
ok('every mark that is a link says where it goes',
    $mark_links > 0 && substr_count($out, 'jump to Entry') === $mark_links);
ok('and every other mark is flagged for a narrow screen',
    substr_count($out, 'is-minor') === (int) floor(count($marks[0]) / 2));
ok('positions are percentages inside the band',
    preg_match_all('~left:(-?[\d.]+)%~', $out, $lefts)
    && min(array_map('floatval', $lefts[1])) >= 0
    && max(array_map('floatval', $lefts[1])) <= 100);

echo "\n-- The step chosen for a span --\n";

check('forty years gets decades', kop_article_timeline_step(40), 10);
check('a century gets decades', kop_article_timeline_step(100), 10);
check('two centuries gets quarter centuries', kop_article_timeline_step(200), 20);
check('three and a half centuries gets half centuries', kop_article_timeline_step(363), 50);
ok('and no span gets more than twelve marks',
    (1000 / kop_article_timeline_step(1000)) <= 12);

echo $failures ? "\n$failures FAILURES\n" : "\narticle timeline: PASS\n";
exit($failures ? 1 : 0);
