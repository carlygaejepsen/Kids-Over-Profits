<?php
/**
 * Offline test for the reader's site map (inc/site-map.php,
 * templates/site-map.php) and the 404 page's suggestions (404.php).
 *
 *   php scripts/test-site-map.php [--out=tmp/site-map.html]
 *
 * WordPress is stubbed: a fixed set of pages, posts and categories, a small
 * facility and operator index. Checks where each page lands (hubs with their
 * articles in reading order, admin tools / redirected / front / password /
 * merged-profile pages left out), the A to Z grouping, the quick links, the
 * filter's folding (PHP == js/site-map.js, needs node) and the 404 helpers,
 * then renders the template and checks every expected link is on it.
 */

if (PHP_SAPI !== 'cli') exit("CLI only.\n");
define('ABSPATH', dirname(__DIR__) . '/');
define('DAY_IN_SECONDS', 86400);
define('HOUR_IN_SECONDS', 3600);
define('ARRAY_A', 'ARRAY_A');

$opts = getopt('', array('out::'));
$fails = 0;
$checks = 0;
function check($label, $cond) {
    global $fails, $checks;
    $checks++;
    if (!$cond) { $fails++; echo "FAIL: $label\n"; }
}

// ---- WordPress stubs --------------------------------------------------------

$GLOBALS['t_filters'] = array();
$GLOBALS['t_transients'] = array();
function add_filter($tag, $cb, $prio = 10, $args = 1) { $GLOBALS['t_filters'][$tag][$prio][] = $cb; return true; }
function add_action($tag, $cb, $prio = 10, $args = 1) { return add_filter($tag, $cb, $prio, $args); }
function apply_filters($tag, $value, ...$args) {
    $f = $GLOBALS['t_filters'][$tag] ?? array();
    ksort($f);
    foreach ($f as $cbs) foreach ($cbs as $cb) $value = $cb($value, ...$args);
    return $value;
}
function home_url($path = '/') { return 'https://kidsoverprofits.org' . $path; }
function add_query_arg($args, $url) { return $url . (strpos($url, '?') === false ? '?' : '&') . http_build_query($args); }
function esc_html($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); }
function esc_attr($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); }
function esc_url($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); }
function wp_strip_all_tags($s) { return trim(strip_tags((string) $s)); }
function wp_parse_url($url, $c = -1) { return parse_url($url, $c); }
function sanitize_title($s) { $s = strtolower(remove_accents(trim((string) $s))); $s = preg_replace('/[^a-z0-9]+/', '-', $s); return trim($s, '-'); }
// WordPress's remove_accents() maps every Latin accented letter to its base letter.
function remove_accents($s) { return preg_replace('/\p{Mn}+/u', '', Normalizer::normalize((string) $s, Normalizer::FORM_D)); }
function number_format_i18n($n) { return number_format((float) $n); }
function get_transient($k) { return $GLOBALS['t_transients'][$k] ?? false; }
function set_transient($k, $v, $ttl = 0) { $GLOBALS['t_transients'][$k] = $v; return true; }
function delete_transient($k) { unset($GLOBALS['t_transients'][$k]); return true; }
function get_option($k, $d = false) { return $k === 'page_on_front' ? 1 : $d; }
function get_bloginfo($k) { return 'Kids Over Profits'; }
function get_header() {}
function get_footer() {}
function is_404() { return false; }

class T_Post {
    public $ID, $post_name, $post_title, $post_type, $post_password = '', $menu_order = 0, $template = '', $cats = array(), $year = '2025';
    function __construct(array $a) { foreach ($a as $k => $v) $this->$k = $v; }
}
$GLOBALS['t_pages'] = array();
$GLOBALS['t_posts'] = array();
$id = 1;
$page = function ($slug, $title, $template = '', array $extra = array()) use (&$id) {
    $GLOBALS['t_pages'][] = new T_Post(array_merge(array('ID' => $id++, 'post_name' => $slug, 'post_title' => $title, 'post_type' => 'page', 'template' => $template), $extra));
};
$page('home', 'Home', 'templates/page-home.php');                 // the front page (ID 1)
$page('history', 'History', 'templates/page-hub.php');
$page('survivors', 'Survivors', 'templates/page-hub.php');
$page('law-policy', 'Law & Policy', 'templates/page-hub.php');
$page('tti-history-part-one', 'TTI History, Part One', 'templates/page-article.php');
$page('early-child-control', 'Early Systems of Child Control', 'templates/page-article.php');
$page('antiquity', 'Precursors in Antiquity', 'templates/page-article.php');
$page('common-survivor-experiences', 'Common Survivor Experiences', 'templates/page-article.php');
$page('stray-article', 'An Article Nobody Placed', 'templates/page-article.php');
$page('tti-program-index', 'TTI Program Index', 'templates/page-tti-program-index.php');
$page('network-map', 'Network Map', 'templates/page-network-map.php');
$page('inspection-reports', 'Inspection Reports', 'templates/page-inspection-reports.php');
$page('ut-reports', 'Utah Inspection Reports', 'templates/page-state-reports.php');
$page('wyoming', 'Wyoming', 'templates/page-state.php');
$page('mexico', 'Mexico', 'templates/page-country.php');
$page('indian-boarding-schools', 'Indian Boarding Schools and Residential Schools', 'templates/page-indian-boarding-schools.php');
$page('young-adult-programs', 'Young Adult Programs', 'templates/page-young-adult-programs.php');
$page('tti-news-feed', 'TTI News Feed', 'templates/page-news-feed.php');
$page('lawsuits', 'Lawsuits', 'templates/page-lawsuits.php');
$page('report-abuse', 'Report Abuse', 'templates/page-report-abuse.php');
$page('glossary', 'TTI Glossary', 'templates/page-glossary.php');
$page('links', 'Links', 'templates/page-utility.php');
$page('donate', 'Donate', 'templates/page-utility.php');
$page('contact', 'Contact', 'templates/page-utility.php');
$page('no-access', 'No Access', 'templates/page-utility.php');
$page('privacy-policy', 'Privacy Policy', 'templates/page-privacy-policy.php');
$page('wiki-editor', 'Wiki Editor', 'templates/page-wiki-editor.php');
$page('admin-lawsuits', 'Lawsuit Admin', 'templates/page-admin-lawsuits.php');
$page('news', 'Old News', '');                                    // behind a redirect
$page('secret', 'Password Page', '', array('post_password' => 'x'));
$page('hyde', 'Hyde School', 'templates/single-facility-profile.php');       // merged into its /facility/ page
$page('elan', 'Elan School', 'templates/single-facility-profile.php');
$page('our-story', 'Our Story', '');
$hyde_post_id = 0;
foreach ($GLOBALS['t_pages'] as $p) if ($p->post_name === 'hyde') $hyde_post_id = $p->ID;

$GLOBALS['t_posts'][] = new T_Post(array('ID' => 500, 'post_name' => 'an-editorial', 'post_title' => 'Why We Track Deaths', 'post_type' => 'post', 'cats' => array(7), 'year' => '2026'));
$GLOBALS['t_posts'][] = new T_Post(array('ID' => 501, 'post_name' => 'spotlight', 'post_title' => 'Spotlight: Trails Carolina', 'post_type' => 'post', 'cats' => array(8)));

function get_posts($args) {
    $type = $args['post_type'] ?? 'post';
    $types = (array) $type;
    $out = array();
    foreach (array_merge($GLOBALS['t_pages'], $GLOBALS['t_posts']) as $p) {
        if (!in_array($p->post_type, $types, true)) continue;
        if (isset($args['has_password']) && $args['has_password'] === false && $p->post_password !== '') continue;
        if (isset($args['cat']) && !in_array((int) $args['cat'], $p->cats, true)) continue;
        if (isset($args['s']) && stripos($p->post_title, (string) $args['s']) === false) continue;
        $out[] = $p;
    }
    return $out;
}
function get_the_title($p) { return $p->post_title; }
function get_permalink($p) { return 'https://kidsoverprofits.org/' . $p->post_name . '/'; }
function get_page_template_slug($p) { return $p->template; }
function get_the_date($f, $p) { return $p->year; }
function get_categories($args) {
    return array(
        (object) array('term_id' => 7, 'name' => 'Editorials', 'count' => 1),
        (object) array('term_id' => 8, 'name' => 'Investigatory Spotlight', 'count' => 1),
    );
}
function get_category_link($id) { return 'https://kidsoverprofits.org/category/c' . $id . '/'; }

class T_DB {
    public $posts = 'wp_posts';
    function get_row($sql, $mode = null) { return array('n' => count($GLOBALS['t_pages']), 'm' => '2026-10-01 00:00:00'); }
}
$GLOBALS['wpdb'] = new T_DB();

// ---- The theme's own pieces the site map reads ------------------------------

function kop_redirect_map() { return apply_filters('kop_redirect_map', array('news' => '/tti-news-feed/')); }
function kop_template_assignments() {
    return array('history' => 'page-hub.php', 'survivors' => 'page-hub.php', 'researchreports' => 'page-hub.php', 'law-policy' => 'page-hub.php');
}
function kop_article_parents() {
    return array(
        'tti-history-part-one'        => 'history',
        'early-child-control'         => 'history',
        'antiquity'                   => 'early-child-control',
        'gone-article'                => 'history',      // no page: skipped
        'common-survivor-experiences' => 'survivors',
    );
}
function kop_facility_pages_merged_profile_posts() { return array(42 => $GLOBALS['hyde_post_id']); }
$GLOBALS['hyde_post_id'] = $hyde_post_id;
function kop_facility_pages_index() {
    return array('fingerprint' => 'f1', 'built' => 0, 'slugs' => array(), 'ids' => array(
        1 => array('slug' => 'hyde-school-ct', 'name' => 'Hyde School'),
        2 => array('slug' => 'hyde-school-me', 'name' => 'Hyde School'),
        3 => array('slug' => 'the-ridge-me', 'name' => 'The Ridge'),
        4 => array('slug' => 'acadia-montana-mt', 'name' => 'Acadia Montana'),
        5 => array('slug' => '3-springs-id', 'name' => '3 Springs'),
        6 => array('slug' => 'elan-school-me-poland-6', 'name' => 'Élan School'),
        7 => array('slug' => 'casa-by-the-sea-mexico', 'name' => 'Casa by the Sea'),
    ));
}
function kop_facility_pages_url_for_slug($slug) { return home_url('/facility/' . rawurlencode($slug) . '/'); }
function kop_operator_pages_index() {
    return array('fingerprint' => 'o1', 'ids' => array(
        10 => array('slug' => 'universal-health-services', 'name' => 'Universal Health Services (UHS)', 'display' => 'Universal Health Services'),
        11 => array('slug' => 'acadia-healthcare', 'name' => 'Acadia Healthcare', 'display' => 'Acadia Healthcare'),
    ));
}
function kop_operator_pages_base() { return 'operator'; }
function kop_operator_pages_url_for_slug($slug) { return home_url('/operator/' . $slug . '/'); }
function kop_state_canonical_name($s) {
    $m = array('CT' => 'Connecticut', 'ME' => 'Maine', 'MT' => 'Montana', 'ID' => 'Idaho');
    return $m[strtoupper($s)] ?? $s;
}
function kop_v2_search($phrase, $f = 10, $o = 5, $p = 3) {
    $out = array('facilities' => array(), 'operators' => array(), 'places' => array());
    if (stripos('hyde school', $phrase) !== false || stripos($phrase, 'hyde school') === 0 && strlen($phrase) <= 11) {
        $out['facilities'][] = array('kind' => 'facility', 'display' => 'Hyde School', 'location' => 'Bath, ME', 'profile_url' => home_url('/facility/hyde-school-me/'));
    }
    return $out;
}
function kop_search_v2_result_url($r) { return $r['profile_url'] ?? ''; }

require ABSPATH . 'inc/site-map.php';
require ABSPATH . 'inc/how-to-use.php';

// ---- Placement --------------------------------------------------------------

$data = kop_site_map_build();
$s = $data['sections'];
$titles_in = function ($items) {
    $out = array();
    foreach ($items as $i) $out[] = $i['title'];
    return $out;
};

check('hubs in kop_template_assignments() order', $titles_in($s['hubs']['items']) === array('History', 'Survivors', 'Law & Policy'));
$history = $s['hubs']['items'][0];
check('History holds its articles in reading order', $titles_in($history['children']) === array('TTI History, Part One', 'Early Systems of Child Control'));
check('an index article holds its own children', $titles_in($history['children'][1]['children']) === array('Precursors in Antiquity'));
check('Survivors holds its article', $titles_in($s['hubs']['items'][1]['children']) === array('Common Survivor Experiences'));
check('an article no hub holds is under More articles', $titles_in($s['reading']['items']) === array('An Article Nobody Placed'));
check('placed articles are not listed twice', !in_array('Precursors in Antiquity', $titles_in($s['reading']['items']), true));
check('directories: parent companies route + directory + map + 18+ programs (part of the industry)', $titles_in($s['records']['items']) === array('Network Map', 'Parent companies', 'TTI Program Index', 'Young Adult Programs'));
check('inspections', $titles_in($s['inspections']['items']) === array('Inspection Reports', 'Utah Inspection Reports'));
check('places', $titles_in($s['places']['items']) === array('Mexico', 'Wyoming'));
check('sources', $titles_in($s['sources']['items']) === array('Lawsuits', 'TTI News Feed'));
check('reference (links by slug)', $titles_in($s['reference']['items']) === array('How to use this site', 'Links', 'Report Abuse', 'TTI Glossary'));
check('boarding schools are set apart, outside the TTI', $titles_in($s['outside']['items']) === array('Indian Boarding Schools and Residential Schools')
    && stripos($s['outside']['title'], 'outside the troubled teen industry') !== false);
check('take part', $titles_in($s['take-part']['items']) === array('Donate'));
check('about (contact by slug)', $titles_in($s['about']['items']) === array('Contact', 'Privacy Policy'));
check('other pages', $titles_in($s['other']['items']) === array('Our Story'));
check('a merged profile is left out, a written one listed', $titles_in($s['profiles']['items']) === array('Elan School'));

$all = json_encode($data);
foreach (array('Home', 'No Access', 'Wiki Editor', 'Lawsuit Admin', 'Old News', 'Password Page') as $hidden) {
    check("'$hidden' is not listed", strpos($all, '"title":"' . $hidden . '"') === false);
}

check('posts under each category', $titles_in($s['posts']['items']) === array('Editorials', 'Investigatory Spotlight')
    && $titles_in($s['posts']['items'][0]['children']) === array('Why We Track Deaths'));

// ---- A to Z -----------------------------------------------------------------

$fac = $s['facilities']['letters'];
check('letters A to Z, # last', array_keys($fac) === array('A', 'C', 'E', 'H', 'R', '#'));
check('"The Ridge" sorts under R', $titles_in($fac['R']) === array('The Ridge'));
check('an accented name sorts under its letter', $titles_in($fac['E']) === array('Élan School'));
check('two programs of one name say which state', array_column($fac['H'], 'note') === array('Connecticut', 'Maine'));
check('a city after the state still finds the state', $fac['E'][0]['note'] === 'Maine');
check('a country slug gives no state', $fac['C'][0]['note'] === '');
check('company pages A to Z', array_keys($s['operators']['letters']) === array('A', 'U'));
check('counts', $data['counts']['facilities'] === 7 && $data['counts']['operators'] === 2);

// ---- Quick links ------------------------------------------------------------

$quick = array_column($data['quick'], 'label');
check('quick links only for published pages, in order', $quick === array('Facility directory', 'Parent companies', 'Network map', 'Inspection reports', 'News', 'Lawsuits', 'Report abuse', 'Glossary'));
check('quick links are cached on their own', kop_site_map_quick_links() === $data['quick'] && isset($GLOBALS['t_transients']['kop_site_map_quick_links']));

// ---- Cache ------------------------------------------------------------------

$first = kop_site_map_data();
$GLOBALS['t_transients']['kop_site_map_data']['sections'] = array('marker' => true);
check('a matching cache key is served from the transient', kop_site_map_data()['sections'] === array('marker' => true));
$GLOBALS['t_pages'][] = new T_Post(array('ID' => 900, 'post_name' => 'new-page', 'post_title' => 'A New Page', 'post_type' => 'page'));
check('a new page rebuilds it', in_array('A New Page', $titles_in(kop_site_map_data()['sections']['other']['items']), true));

// ---- Redirect aliases -------------------------------------------------------

$map = kop_redirect_map();
check('/sitemap/ and /site-index/ go to /site-map/', ($map['sitemap'] ?? '') === '/site-map/' && ($map['site-index'] ?? '') === '/site-map/');

// ---- 404 helpers ------------------------------------------------------------

check('404 phrase from a facility address', kop_site_map_404_phrase('/facility/hyde-school-me/') === 'hyde school me');
check('404 phrase drops .html', kop_site_map_404_phrase('/old/elan_school.html?x=1') === 'elan school');
check('404 phrase skips a missing image', kop_site_map_404_phrase('/2024/08/photo.jpg') === '');
check('404 phrase skips wp-content', kop_site_map_404_phrase('/wp-content/uploads/x/report') === '');
check('404 phrase skips a bare number', kop_site_map_404_phrase('/page/2/') === '');
$sugg = kop_site_map_404_suggestions('hyde school me');
check('404 suggestion: the program, after dropping the state code', ($sugg[0]['url'] ?? '') === home_url('/facility/hyde-school-me/'));
$sugg = kop_site_map_404_suggestions('glossary');
check('404 suggestion: a page by title', ($sugg[0]['title'] ?? '') === 'TTI Glossary');
$sugg = kop_site_map_404_suggestions('wiki editor');
check('404 suggestion never offers a hidden page', !$sugg);

// ---- Folding: PHP == JS -----------------------------------------------------

$samples = array('Élan School', 'Law &amp; Policy', "  Two   spaces\tTab ", 'ÉCOLE Ñandú');
check('fold', kop_site_map_fold('Élan  School') === 'elan school' && kop_site_map_fold('Law &amp; Policy') === 'law & policy');
$node = trim((string) shell_exec('command -v node 2>/dev/null'));
if ($node !== '') {
    $js = file_get_contents(ABSPATH . 'js/site-map.js');
    preg_match('/function fold\(text\) \{.*?\n    \}/s', $js, $m);
    $script = $m[0] . "\nconsole.log(JSON.stringify(" . json_encode(array_map(function ($x) { return html_entity_decode($x, ENT_QUOTES, 'UTF-8'); }, $samples)) . ".map(fold)));";
    $tmp = tempnam(sys_get_temp_dir(), 'kopsm');
    file_put_contents($tmp, $script);
    $js_out = json_decode((string) shell_exec(escapeshellarg($node) . ' ' . escapeshellarg($tmp)), true);
    unlink($tmp);
    check('fold: PHP == js/site-map.js', $js_out === array_map('kop_site_map_fold', $samples));
} else {
    echo "(node not found: PHP/JS fold parity skipped)\n";
}

// ---- The page ---------------------------------------------------------------

$GLOBALS['kop_site_map'] = kop_site_map_data(true);
ob_start();
include ABSPATH . 'templates/site-map.php';
$html = ob_get_clean();

check('page has the filter form', strpos($html, 'data-kop-sm-filter') !== false && strpos($html, 'name="s"') !== false);
foreach (kop_facility_pages_index()['ids'] as $e) {
    check('facility page linked: ' . $e['slug'], strpos($html, 'href="https://kidsoverprofits.org/facility/' . $e['slug'] . '/"') !== false);
}
check('company pages linked', strpos($html, '/operator/universal-health-services/') !== false && strpos($html, '/operator/acadia-healthcare/') !== false);
check('every link carries filter words', substr_count($html, '<li data-sm=') >= 30);
check('the A to Z jump points at its group', strpos($html, 'href="#kop-sm-facilities-h"') !== false && strpos($html, 'id="kop-sm-facilities-h"') !== false);
check('# has an id the address bar keeps', strpos($html, 'id="kop-sm-facilities-num"') !== false);
check('the contents list each section', substr_count($html, 'href="#kop-sm-') >= count($GLOBALS['kop_site_map']['sections']));
check('no hidden page on the page', strpos($html, 'Lawsuit Admin') === false && strpos($html, '/no-access/') === false);
check('quick links on the page', strpos($html, 'kop-sm-quick__link') !== false);
check('nothing escaped twice', strpos($html, '&amp;amp;') === false);
check('the site map points new readers to how to use this site', strpos($html, 'href="https://kidsoverprofits.org/how-to-use-this-site/"') !== false);

// ---- How to use this site ----------------------------------------------------

$htu = kop_how_to_use_build(kop_site_map_public_pages());
$htu_groups = array();
foreach ($htu['groups'] as $g) $htu_groups[$g['title']] = $titles_in($g['items']);
check('how to use: groups by what a reader came to do', array_keys($htu_groups) === array('Look up a program', 'Records of harm', 'Get help or take action', 'Understand the industry', 'Written for you'));
check('how to use: unpublished pages dropped, routes kept', $htu_groups['Look up a program'] === array('Facility directory', 'Programs by state or country', 'Parent companies', 'Network map', 'Young adult programs')
    && $htu_groups['Records of harm'] === array('Inspection reports', 'Lawsuits', 'News feed'));
check('how to use: the location tab carries its query', $htu['groups'][0]['items'][1]['url'] === 'https://kidsoverprofits.org/tti-program-index/?view=location');
$GLOBALS['kop_how_to_use'] = $htu;
ob_start();
include ABSPATH . 'templates/how-to-use.php';
$htu_html = ob_get_clean();
check('how to use: page renders its groups, search tips and the site map link', substr_count($htu_html, 'class="kop-htu-group"') === 5
    && strpos($htu_html, 'data-kop-open-search') !== false && strpos($htu_html, 'href="https://kidsoverprofits.org/site-map/"') !== false);
check('how to use: listed in the site map', in_array('How to use this site', $titles_in($s['reference']['items']), true));

if (!empty($opts['out'])) {
    $out = $opts['out'];
    @mkdir(dirname($out), 0777, true);
    file_put_contents($out, '<!doctype html><meta charset="utf-8"><link rel="stylesheet" href="file://' . ABSPATH . 'css/colors.css"><link rel="stylesheet" href="file://' . ABSPATH . 'css/site-map.css"><body style="background:#eee">' . $html . '<script src="file://' . ABSPATH . 'js/site-map.js"></script>');
    echo "Wrote $out\n";
    $htu_out = dirname($out) . '/how-to-use.html';
    file_put_contents($htu_out, '<!doctype html><meta charset="utf-8"><link rel="stylesheet" href="file://' . ABSPATH . 'css/colors.css"><link rel="stylesheet" href="file://' . ABSPATH . 'css/site-map.css"><link rel="stylesheet" href="file://' . ABSPATH . 'css/how-to-use.css"><body style="background:#eee">' . $htu_html);
    echo "Wrote $htu_out\n";
}

echo ($fails ? "$fails of $checks checks FAILED\n" : "All $checks checks passed\n");
exit($fails ? 1 : 0);
