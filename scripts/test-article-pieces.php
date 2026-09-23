<?php
/**
 * Offline test for the four reusable article pieces in inc/article-pieces.php.
 *
 *   php scripts/test-article-pieces.php
 *
 * Three things are worth protecting here. A piece must never quietly drop the
 * meaning it carries: an era prints its name whether or not the stylesheet
 * knows the era, and a collapsible section is open whatever it is asked for,
 * because a closed one hides its text from find-in-page and from the contents
 * list. Ids must stay unique, because the contents list and the timeline band
 * both point at them. And a piece handed an editor's prose must give it back
 * exactly as it found it.
 *
 * The file is loaded with WordPress stubbed out, the way the other article
 * tests do it.
 */

if (PHP_SAPI !== 'cli') {
    exit("CLI only.\n");
}

define('ABSPATH', dirname(__DIR__) . '/');

// --- WordPress stubs --------------------------------------------------------

function esc_attr($text) { return htmlspecialchars((string) $text, ENT_QUOTES); }
function esc_html($text) { return htmlspecialchars((string) $text, ENT_QUOTES); }
function esc_url($url) { return str_replace(array('"', "'", ' '), array('%22', '%27', '%20'), (string) $url); }
function wp_strip_all_tags($text) { return strip_tags((string) $text); }
/* The real one runs an allowlist over the HTML; nothing here depends on what
 * it removes, only on what it keeps. */
function wp_kses_post($text) { return (string) $text; }
function apply_filters($hook, $value) { return $value; }
function sanitize_title($text) {
    $text = strtolower(trim((string) $text));
    $text = preg_replace('/[^a-z0-9]+/', '-', $text);
    return trim($text, '-');
}

$GLOBALS['kop_shortcodes'] = array();
function add_shortcode($tag, $callback) { $GLOBALS['kop_shortcodes'][$tag] = $callback; }
function do_shortcode($content) { return (string) $content; }
function shortcode_atts($pairs, $atts, $shortcode = '') {
    $out = $pairs;
    foreach ((array) $atts as $key => $value) {
        if (array_key_exists($key, $pairs)) {
            $out[$key] = $value;
        }
    }
    return $out;
}

require ABSPATH . 'inc/article-pieces.php';

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

function has($label, $haystack, $needle) {
    ok($label, strpos($haystack, $needle) !== false);
}

function hasnt($label, $haystack, $needle) {
    ok($label, strpos($haystack, $needle) === false);
}

/** Text with the tags taken out, for comparing what went in with what came back. */
function words($html) {
    return preg_replace('/\s+/', ' ', trim(strip_tags((string) $html)));
}

// --- 1. Era header ----------------------------------------------------------

echo "\n-- Era header --\n";

$era = kop_article_era_header(array(
    'title'   => 'The for-profit turn',
    'era'     => 'for-profit',
    'summary' => 'Chains buy the programmes their founders ran.',
));

has('era: the era name is printed, not just its colour', $era, '>For-profit<');
has('era: the era is a class, so the colour comes from CSS', $era, 'kop-article-era--for-profit');
has('era: the title is a real heading', $era, '<h2 class="kop-article-era__title">The for-profit turn</h2>');
has('era: the id is made from the title', $era, 'id="the-for-profit-turn"');
has('era: the summary is kept', $era, 'Chains buy the programmes their founders ran.');

$era3 = kop_article_era_header(array('title' => 'A sub era', 'era' => 'nonprofit', 'level' => 3));
has('era: level 3 gives an h3', $era3, '<h3 class="kop-article-era__title">');

/* An era nobody has written CSS for still has to say what it is. */
$odd = kop_article_era_header(array('title' => 'Something else', 'era' => 'Trust Era'));
has('era: an unknown era is written out rather than dropped', $odd, '>Trust era<');
hasnt('era: an unknown era takes no colour class on its tag', $odd, 'is-trust-era');

check('era: nothing to say, nothing printed', kop_article_era_header(array()), '');

$icon = kop_article_era_header(array('title' => 'Iconed', 'era' => 'nonprofit', 'icon' => 'dashicons-groups'));
has('era: an icon is a class hook', $icon, 'class="kop-article-era__icon dashicons-groups" aria-hidden="true"');
has('era: an icon never replaces the name', $icon, '>Nonprofit<');

// --- 2. Collapsible section -------------------------------------------------

echo "\n-- Collapsible section --\n";

$body = '<p>Straight Inc ran <strong>from 1976</strong>, and the state closed it.</p>';
$section = kop_article_collapsible(array(
    'title'   => 'Straight Inc',
    'summary' => 'Twelve years, five states.',
    'body'    => $body,
));

has('section: open by default', $section, '<details class="kop-article-section" id="straight-inc" open>');
has('section: the heading sits inside the summary, where the contents list finds it', $section, '<summary class="kop-article-section__summary"><h2 class="kop-article-section__title">Straight Inc</h2>');
has('section: the one-line summary is kept', $section, 'Twelve years, five states.');
check('section: the prose is handed back exactly as it came', words($body), 'Straight Inc ran from 1976, and the state closed it.');
has('section: the body is the editor\'s own markup, untouched', $section, $body);

/* There is no closed state to ask for: a closed <details> loses find-in-page
 * and swallows an anchor jump, and nothing here opens one on a hash change. */
$closed = kop_article_collapsible(array('title' => 'Still open', 'body' => '<p>x</p>', 'open' => false));
has('section: asking for closed still gives open', $closed, ' open>');

$withsources = kop_article_collapsible(array(
    'title'   => 'WWASP',
    'body'    => '<p>x</p>',
    'sources' => 'A report | The Salt Lake Tribune | 2003 | https://example.test/a',
));
has('section: sources given as text become a sources list', $withsources, '<section class="kop-article-sources"');
has('section: a section\'s sources sit a level down', $withsources, '<h3 class="kop-article-sources__title"');

check('section: nothing to say, nothing printed', kop_article_collapsible(array()), '');

// --- 3. Sources -------------------------------------------------------------

echo "\n-- Sources --\n";

$sources = kop_article_sources(
    "A report on Straight Inc | The Salt Lake Tribune | 12 March 2003 | https://example.test/a\n"
    . "Hearing transcript | US Senate | 2007\n"
    . "https://example.test/c A third thing"
);

has('sources: auto-numbered by the list itself', $sources, '<ol class="kop-article-sources__list">');
has('sources: the first item carries an id to link to', $sources, 'id="source-1"');
has('sources: the second too', $sources, 'id="source-2"');
has('sources: the third too', $sources, 'id="source-3"');
has('sources: a title with a URL is linked', $sources, '<a href="https://example.test/a" rel="nofollow noopener">A report on Straight Inc</a>');
has('sources: publisher and date follow the title in one format', $sources, '<span class="kop-article-sources__publisher">, The Salt Lake Tribune</span><span class="kop-article-sources__date">, 12 March 2003</span>');
has('sources: an item with no URL is printed plain', $sources, '<cite class="kop-article-sources__cite">Hearing transcript</cite>');
has('sources: a URL anywhere in the line is lifted out', $sources, '>A third thing</a>');

$fromlist = kop_article_sources('<ul><li>One | Somewhere | 1999</li><li>Two | Elsewhere | 2001</li></ul>');
has('sources: a list an editor built is read as citations', $fromlist, '>One</cite>');
has('sources: and every item of it', $fromlist, '>Two</cite>');
hasnt('sources: the editor\'s own list markup is not passed through', $fromlist, '<ul>');

$bare = kop_article_sources('https://www.example.test/reports/2019/final.pdf');
has('sources: a bare link is named after its host', $bare, '>example.test</a>');

$arrayed = kop_article_sources(array(
    array('title' => 'Given as data', 'publisher' => 'KOP', 'date' => '2026', 'note' => 'Held in the library.'),
));
has('sources: citation arrays work as well as typed lines', $arrayed, '>Given as data</cite>');
has('sources: a note is kept', $arrayed, 'Held in the library.');

check('sources: nothing to cite, nothing printed', kop_article_sources(''), '');
check('sources: blank lines alone print nothing', kop_article_sources("\n\n  \n"), '');

// --- 4. Why this matters ----------------------------------------------------

echo "\n-- Why this matters --\n";

$why = kop_article_why_this_matters('Public money pays for most of these beds.');
has('why: the block is labelled by its own heading', $why, '<aside class="kop-article-why" aria-labelledby="why-this-matters">');
has('why: the heading carries that id', $why, '<h2 class="kop-article-why__title" id="why-this-matters">Why this matters</h2>');
has('why: bare text is given a paragraph', $why, '<p>Public money pays for most of these beds.</p>');

$why2 = kop_article_why_this_matters(array(
    'title' => 'Why this matters to a parent',
    'body'  => '<p>Read the inspection before you sign.</p>',
    'level' => 3,
));
has('why: the title can be rewritten for its audience', $why2, '>Why this matters to a parent</h3>');
has('why: level 3 gives an h3', $why2, '<h3 class="kop-article-why__title"');
has('why: an id is not reused when the block appears twice', $why2, 'id="why-this-matters-to-a-parent"');

check('why: no text, no block', kop_article_why_this_matters(''), '');

// --- 5. Ids stay unique -----------------------------------------------------

echo "\n-- Ids --\n";

$first  = kop_article_collapsible(array('title' => 'Repeated title', 'body' => '<p>a</p>'));
$second = kop_article_collapsible(array('title' => 'Repeated title', 'body' => '<p>b</p>'));
has('ids: the first use takes the plain id', $first, 'id="repeated-title"');
has('ids: the second is given its own', $second, 'id="repeated-title-2"');

$given = kop_article_era_header(array('title' => 'Anything', 'era' => 'nonprofit', 'id' => 'my-own-id'));
has('ids: an explicit id is kept', $given, 'id="my-own-id"');

$untitled = kop_article_sources('A thing', array('title' => ''));
has('ids: a sources list with no heading still prints its items', $untitled, 'id="source-1"');
hasnt('ids: and carries no empty heading', $untitled, '<h2');

// --- 6. The shortcodes ------------------------------------------------------

echo "\n-- Shortcodes --\n";

$registered = array_keys($GLOBALS['kop_shortcodes']);
sort($registered);
check('shortcodes: five registered', $registered, array('kop_era', 'kop_section', 'kop_sources', 'kop_summary', 'kop_why'));

$sc = call_user_func($GLOBALS['kop_shortcodes']['kop_section'], array('title' => 'From a shortcode'), '<p>Body text.</p>');
has('shortcodes: a section from the editor is the same piece', $sc, '<details class="kop-article-section" id="from-a-shortcode" open>');
has('shortcodes: with the editor\'s body inside it', $sc, '<p>Body text.</p>');

$scEra = call_user_func($GLOBALS['kop_shortcodes']['kop_era'], array('era' => 'private-equity'), 'The money arrives.');
has('shortcodes: an enclosed era reads as its summary', $scEra, 'The money arrives.');
has('shortcodes: and still prints the era name', $scEra, '>Private equity<');

$scSources = call_user_func($GLOBALS['kop_shortcodes']['kop_sources'], array(), "One | Two | 2020\nThree");
has('shortcodes: sources come from the enclosed lines', $scSources, '>One</cite>');
has('shortcodes: every line of them', $scSources, '>Three</cite>');

$scWhy = call_user_func($GLOBALS['kop_shortcodes']['kop_why'], array('title' => 'Why this matters here'), 'Because.');
has('shortcodes: why this matters takes its own title', $scWhy, '>Why this matters here</h2>');

// --- 7. The micro-summary (15C) ---------------------------------------------

echo "\n-- Micro-summary --\n";

check(
    'summary: one sentence, one marked paragraph',
    kop_article_summary('Twelve years, five states.'),
    '<p class="kop-article-summary">Twelve years, five states.</p>'
);

/* wpautop runs before shortcodes, so what arrives here is often already
 * wrapped; the class has to end up on the paragraph, not around it. */
check(
    'summary: a paragraph it arrives wrapped in is not doubled',
    kop_article_summary('<p>Already a paragraph.</p>'),
    '<p class="kop-article-summary">Already a paragraph.</p>'
);

has(
    'summary: a link in the sentence survives',
    kop_article_summary('See <a href="https://example.test/">the filing</a>.'),
    '<a href="https://example.test/">the filing</a>'
);

check('summary: nothing to say, nothing printed', kop_article_summary(''), '');
check('summary: whitespace alone prints nothing', kop_article_summary("  \n "), '');
check('summary: an empty paragraph prints nothing', kop_article_summary('<p> </p>'), '');

$scSummary = call_user_func($GLOBALS['kop_shortcodes']['kop_summary'], array(), 'From the editor.');
check(
    'summary: the shortcode is the same piece',
    $scSummary,
    '<p class="kop-article-summary">From the editor.</p>'
);

// --- 8. Nothing escapes unescaped -------------------------------------------

echo "\n-- Escaping --\n";

$nasty = kop_article_era_header(array(
    'title' => 'A "quoted" <script>alert(1)</script> title',
    'era'   => 'nonprofit',
));
hasnt('escaping: a script tag in a title never reaches the page', $nasty, '<script>');
/* A title is stripped of its tags before it is escaped, so the tag is gone
 * rather than printed as text; what was written between the tags stays, the
 * way it would in any other heading. */
hasnt('escaping: the tag is stripped, not printed back as text', $nasty, '&lt;script&gt;');
has('escaping: the words around it survive', $nasty, 'alert(1) title');
has('escaping: a quote in a title cannot close the attribute it sits in', $nasty, 'A &quot;quoted&quot;');

$nastyUrl = kop_article_sources(array(array('title' => 'Link', 'url' => 'https://example.test/a"onmouseover="x')));
hasnt('escaping: a quote cannot break out of an href', $nastyUrl, '"onmouseover="');

echo "\n";
if ($failures) {
    echo "article pieces tests: $failures FAILED\n";
    exit(1);
}
echo "article pieces tests: PASS\n";
