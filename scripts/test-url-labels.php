<?php
/**
 * Links read as words (inc/url-labels.php, js/url-labels.js): the labels,
 * PHP and JS giving the same label for every address, and the content
 * filter relabelling address-text links and bare addresses while leaving
 * code, images and data-kop-keep-url alone. Needs node on the PATH.
 *
 *   php -d extension=mbstring scripts/test-url-labels.php
 */

if (PHP_SAPI !== 'cli') {
    exit("CLI only.\n");
}
define('ABSPATH', __DIR__ . '/');
function add_filter() {}
function add_action() {}
function home_url($p = '') { return 'https://kidsoverprofits.org' . $p; }
function esc_html($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
function esc_attr($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
function esc_url($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
require dirname(__DIR__) . '/inc/url-labels.php';

$failures = 0;
$check = function ($label, $ok, $detail = '') use (&$failures) {
    if (!$ok) $failures++;
    echo ($ok ? 'PASS ' : 'FAIL ') . $label . ($detail !== '' ? "  ($detail)" : '') . "\n";
};

// Addresses seen as text on the live site (2026-10-03 scan), and edge cases.
$expect = array(
    'https://undark.org/2023/06/08/opinion-i-was-a-wilderness-therapy-success/' => 'Opinion i was a wilderness therapy success (undark.org)',
    'https://web.archive.org/web/20230204161830/https://www.heal-online.org' => 'heal-online.org (archived 2023)',
    'https://web.archive.org/web/19971007202315/http://www.greatlakesacademy.com/staff.html' => 'Staff (greatlakesacademy.com, archived 1997)',
    'https://www.reddit.com/r/troubledteens/wiki/index/famhelp/' => 'Famhelp (r/troubledteens wiki)',
    'reddit.com/r/troubledteens/wiki/index/discoveryranch' => 'Discoveryranch (r/troubledteens wiki)',
    'https://www.reddit.com/r/troubledteens/comments/abc123/my_time_at_provo_canyon_school/' => 'My time at provo canyon school (r/troubledteens)',
    'https://kidsoverprofits.org/wp-content/uploads/2024/12/woodbury-1208.pdf' => 'Woodbury 1208 (PDF)',
    'https://kidsoverprofits.org/wp-content/uploads/2024/12/woodbury-1208.pdf#page=18' => 'Woodbury 1208 (PDF, p. 18)',
    'https://example.gov/files/report.pdf#zoom=50&page=3' => 'Report (example.gov, PDF, p. 3)',
    'https://kidsoverprofits.org/common-survivor-experiences/' => 'Common survivor experiences',
    'https://kidsoverprofits.org/' => 'Kids Over Profits',
    'https://azcarecheck.azdhs.gov/' => 'azcarecheck.azdhs.gov',
    'https://www.azdhs.gov/licensing/index.php#azcarecheck' => 'Licensing (azdhs.gov)',
    'https://www.ccld.dss.ca.gov/carefacilitysearch/' => 'Carefacilitysearch (ccld.dss.ca.gov)',
    'https://www.cbsnews.com/news/unsafe-haven-faq/' => 'Unsafe haven faq (cbsnews.com)',
    'https://leg.mt.gov/content/Committees/Interim/2005-2006/Economic-Affairs/Minutes/EAIC-Minutes.pdf' => 'EAIC Minutes (leg.mt.gov, PDF)',
    'http://example.com/a/b/1234567' => 'example.com',
    'http://example.com/reports/annual-review/1234567' => 'Annual review (example.com)',
    'www.example.org/some_page.html' => 'Some page (example.org)',
    'https://web.archive.org/web/https://web.centralmarylandchamber.org/Consultants/Upwell-Advisors,-LLC-4153' => 'Upwell Advisors, LLC 4153 (web.centralmarylandchamber.org, archived)',
);
foreach ($expect as $url => $want) {
    $got = kop_url_label($url);
    $check("label: $url", $got === $want, $got);
}

$long = kop_url_label('https://example.com/' . str_repeat('word-', 30) . 'end');
$check('a long label is cut on a word with an ellipsis', mb_strlen($long) <= KOP_URL_LABEL_MAX + 20 && strpos($long, '…') !== false, $long);

echo "-- PHP and JS agree --\n";
$urls = array_merge(array_keys($expect), array(
    'https://example.com/' . str_repeat('word-', 30) . 'end',
    'https://www.nytimes.com/2019/02/22/us/troubled-teens-residential-treatment.html',
    'https://docs.google.com/document/d/1AbCdEf0123456789abcdef/edit',
    'https://www.courtlistener.com/docket/12345678/doe-v-trails/',
    'https://archive.org/details/woodbury',
    'https://web.archive.org/web/2005id_/http://www.wwasps.com/',
));
$tmp = sys_get_temp_dir() . '/kop-url-labels-' . getmypid() . '.json';
file_put_contents($tmp, json_encode($urls));
$js = dirname(__DIR__) . '/js/url-labels.js';
$node = "global.window={location:{href:'https://kidsoverprofits.org/'}};global.document=undefined;"
    . "eval(require('fs').readFileSync(" . json_encode($js) . ",'utf8'));"
    . "const u=JSON.parse(require('fs').readFileSync(" . json_encode($tmp) . ",'utf8'));"
    . "process.stdout.write(JSON.stringify(u.map(x=>window.kopUrlLabel(x))));";
$script = sys_get_temp_dir() . '/kop-url-labels-' . getmypid() . '.js';
file_put_contents($script, $node);
$out = shell_exec('node ' . escapeshellarg($script) . ' 2>&1');
@unlink($tmp);
@unlink($script);
$js_labels = json_decode((string) $out, true);
if (!is_array($js_labels)) {
    $check('node ran js/url-labels.js', false, substr((string) $out, 0, 300));
} else {
    $diff = array();
    foreach ($urls as $i => $u) {
        if (kop_url_label($u) !== $js_labels[$i]) $diff[] = $u . ': PHP "' . kop_url_label($u) . '" JS "' . $js_labels[$i] . '"';
    }
    $check('every address gets the same label in PHP and JS', !$diff, implode(' | ', $diff));
}

echo "-- Content filter --\n";
$f = 'kop_url_labels_filter_html';
$out = $f('<p>See <a href="https://www.cbsnews.com/news/unsafe-haven-faq/">https://www.cbsnews.com/news/unsafe-haven-faq/</a> for more.</p>');
$check('a link whose text is its address gets a label', strpos($out, '>Unsafe haven faq (cbsnews.com)</a>') !== false, $out);
$check('...and the address as its title', strpos($out, 'title="https://www.cbsnews.com/news/unsafe-haven-faq/"') !== false);

$out = $f('<p>Source: https://www.reddit.com/r/troubledteens/wiki/index/famhelp/.</p>');
$check('a bare address becomes a labelled link', strpos($out, '<a href="https://www.reddit.com/r/troubledteens/wiki/index/famhelp/"') !== false && strpos($out, '>Famhelp (r/troubledteens wiki)</a>.') !== false, $out);

$out = $f('<p>(see https://example.com/news/big-story)</p>');
$check('a closing bracket after an address stays outside it', strpos($out, '>Big story (example.com)</a>)') !== false, $out);

$in = '<p><a href="https://example.com/x">Read the report</a></p>';
$check('a link with words is unchanged', $f($in) === $in);
$in = '<a href="https://example.com/big.jpg"><img src="https://example.com/big.jpg" alt=""></a>';
$check('a linked picture is unchanged', $f($in) === $in);
$in = '<pre>curl https://example.com/api/thing</pre><code>https://example.com/x/y/z</code>';
$check('code and pre are unchanged', $f($in) === $in);
$in = '<div data-kop-keep-url><p>https://example.com/keep/this/one</p></div>';
$check('data-kop-keep-url is unchanged', $f($in) === $in);
$in = '<p>Email someone@example.com/path or read file.txt</p>';
$check('an email-like word is not linked', strpos($f($in), '<a ') === false, $f($in));
$out = $f('<p>Two: https://a.example.com/one-thing and www.example.org/two-thing</p>');
$check('two bare addresses in one paragraph both become links', substr_count($out, '<a ') === 2, $out);
$in = '<a href="https://example.com/short"><strong>https://example.com/short</strong></a>';
$check('a link with markup inside is unchanged', $f($in) === $in);
$out = $f('<a>https://www.govinfo.gov/content/pkg/CHRG-110hhrg41839/pdf/CHRG-110hhrg41839.pdf</a>');
$check('a link with no href is labelled from its own text', strpos($out, '<a>CHRG 110hhrg41839 (govinfo.gov, PDF)</a>') !== false, $out);
$in = '<img src="https://example.com/a/b/c.jpg" alt="https://example.com/a/b/c.jpg">';
$check('addresses inside attributes are unchanged', $f($in) === $in);

echo $failures ? "\n$failures FAILED\n" : "\nAll passed\n";
exit($failures ? 1 : 0);
