<?php
/**
 * Render the reporting directory offline.
 *
 * The page is plain server-rendered PHP over a JSON file, so it can be proved
 * correct without WordPress: this stubs the handful of WP functions
 * inc/reporting-directory.php touches, renders the page and the embedded state
 * block, and checks the output for the mistakes that actually happen here -
 * an unescaped field reaching the HTML, a channel whose contact block came out
 * empty, a phone number that did not become a dialable link.
 *
 * Usage:
 *   php scripts/test-reporting-directory.php
 *   php scripts/test-reporting-directory.php --state ut
 *   php scripts/test-reporting-directory.php --state ut --dump tmp/report-abuse.html
 */

if (PHP_SAPI !== 'cli') {
    exit("CLI only.\n");
}

define('ABSPATH', true);

$ROOT = dirname(__DIR__);

/* ---- The WordPress surface this file uses, and nothing more ------------- */

function get_stylesheet_directory() {
    return dirname(__DIR__);
}
function get_stylesheet_directory_uri() {
    return 'https://kidsoverprofits.org/wp-content/themes/child';
}
function esc_html($text) {
    return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
}
function esc_attr($text) {
    return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
}
function esc_url($url) {
    return htmlspecialchars((string) $url, ENT_QUOTES, 'UTF-8');
}
function selected($a, $b) {
    if ((string) $a === (string) $b) {
        echo ' selected="selected"';
    }
}
function date_i18n($format, $timestamp) {
    return date($format, $timestamp);
}
function add_query_arg($key, $value, $url) {
    return $url . (strpos($url, '?') === false ? '?' : '&') . rawurlencode($key) . '=' . rawurlencode($value);
}
/* Every internal page is assumed to exist, so the harness exercises the
 * branches that link to one. */
function get_page_by_path($slug) {
    return (object) array('ID' => 1, 'post_name' => $slug);
}
function get_permalink($page = null) {
    $slug = is_object($page) && isset($page->post_name) ? $page->post_name : 'report-abuse';
    return 'https://kidsoverprofits.org/' . $slug . '/';
}
function is_singular($type = null) {
    return true;
}
function get_queried_object_id() {
    return 1;
}
function get_post_meta($id, $key, $single = false) {
    return '';
}
function get_post_field($field, $id) {
    return '';
}
function kop_state_slug_to_name($slug) {
    return null;
}
function wp_enqueue_style() {}
function add_action() {}
function file_exists_stub() {}

require_once $ROOT . '/inc/reporting-directory.php';

/* ---- Arguments --------------------------------------------------------- */

$state = '';
$dump  = '';
for ($i = 1; $i < $argc; $i++) {
    if ($argv[$i] === '--state' && isset($argv[$i + 1])) {
        $state = $argv[++$i];
    } elseif ($argv[$i] === '--dump' && isset($argv[$i + 1])) {
        $dump = $argv[++$i];
    }
}

$failures = array();
$checks   = 0;

function check($condition, $message) {
    global $failures, $checks;
    $checks++;
    if (!$condition) {
        $failures[] = $message;
    }
}

/* ---- The data loads ---------------------------------------------------- */

$directory = kop_reporting_directory();
if (!$directory) {
    fwrite(STDERR, "directory.json is missing or unreadable. Run: node scripts/build-reporting-directory.js\n");
    exit(1);
}

printf("Directory: %d national channels, %d/%d states covered\n",
    count($directory['national']['channels']),
    $directory['coverage']['covered'],
    $directory['coverage']['total']);

/* ---- Every channel renders, and renders something usable --------------- */

$all = $directory['national']['channels'];
foreach ($directory['states'] as $record) {
    $all = array_merge($all, $record['channels']);
}

foreach ($all as $channel) {
    ob_start();
    kop_reporting_render_channel($channel);
    $html = ob_get_clean();

    $id = $channel['id'];
    check(strpos($html, 'kop-rep-card') !== false, "$id: card did not render");
    check(strpos($html, esc_html($channel['name'])) !== false, "$id: name missing from output");

    /* A card with no contact line is a dead end for the reader. */
    $has_contact = strpos($html, 'kop-rep-phone') !== false
        || strpos($html, 'kop-rep-action') !== false
        || strpos($html, 'mailto:') !== false
        || strpos($html, 'kop-rep-mail') !== false
        || strpos($html, 'More about this body') !== false;
    check($has_contact, "$id: rendered with no way to contact it");

    if (!empty($channel['phone'])) {
        $href = kop_reporting_tel_href($channel['phone']);
        check($href !== '' && strpos($html, 'href="' . esc_url($href) . '"') !== false,
            "$id: phone {$channel['phone']} did not become a tel: link");
    }

    /* Nothing is printed raw. A quote or an ampersand in an agency name is
     * the realistic way an escaping bug would show up here. */
    foreach (array('name', 'what_it_can_do', 'who_to_report', 'how', 'note') as $field) {
        if (empty($channel[$field]) || !is_string($channel[$field])) {
            continue;
        }
        if (preg_match('/["\'&<>]/', $channel[$field])) {
            check(strpos($html, $channel[$field]) === false || $channel[$field] === esc_html($channel[$field]),
                "$id: $field reached the HTML unescaped");
        }
    }

    check(strpos($html, 'Checked ') !== false, "$id: no verification date shown");
}

printf("Rendered %d channel cards\n", count($all));

/* ---- The whole page, with and without a state -------------------------- */

foreach (array('', 'utah', 'not-a-state') as $slug) {
    ob_start();
    kop_reporting_render_page($slug);
    $html = ob_get_clean();
    $label = $slug === '' ? '(no state)' : $slug;

    check(strpos($html, 'kop-rep-picker') !== false, "$label: state picker missing");
    check(strpos($html, 'kop-rep-national') !== false, "$label: national section missing");
    check(substr_count($html, '<option') === count($directory['states']) + 1,
        "$label: picker does not list every covered state");

    if ($slug === 'utah') {
        check(strpos($html, 'kop-rep-state') !== false, "$label: state section missing");
        check(strpos($html, 'Utah') !== false, "$label: state name missing");
    }
    if ($slug === 'not-a-state') {
        check(strpos($html, 'kop-rep-missing') !== false, "$label: unknown state should say so");
        check(strpos($html, 'not-a-state') === false, "$label: unknown slug was echoed back into the page");
    }
}

/* ---- The embedded block ------------------------------------------------ */

foreach ($directory['states'] as $record) {
    ob_start();
    kop_reporting_render_state_block($record['state']);
    $html = ob_get_clean();
    check(strpos($html, 'kop-rep-embed') !== false, "{$record['abbr']}: embed block did not render");
    check(substr_count($html, 'kop-rep-card') >= count($record['channels']),
        "{$record['abbr']}: embed dropped channels");
    /* Embedded a level deeper, so headings must not jump back to h3. */
    check(strpos($html, '<h3 class="kop-rep-card-name"') === false,
        "{$record['abbr']}: embed used h3 for a card name, breaking heading order");
}

ob_start();
kop_reporting_render_state_block('Narnia');
$html = ob_get_clean();
check($html === '', 'an unknown state should render nothing at all');

/* ---- Dump for eyeballing ----------------------------------------------- */

if ($dump !== '') {
    ob_start();
    kop_reporting_render_page($state);
    $body = ob_get_clean();
    $path = $dump[0] === '/' || preg_match('/^[A-Za-z]:/', $dump) ? $dump : $ROOT . '/' . $dump;
    @mkdir(dirname($path), 0777, true);
    file_put_contents($path, "<!doctype html><meta charset=\"utf-8\"><title>Report Abuse</title>\n"
        . "<link rel=\"stylesheet\" href=\"../css/colors.css\">\n"
        . "<link rel=\"stylesheet\" href=\"../css/report-abuse.css\">\n"
        . "<div class=\"kop-rep-page\">" . $body . "</div>\n");
    echo "Wrote $path\n";
}

/* ---- Result ------------------------------------------------------------ */

echo "\n";
if ($failures) {
    printf("%d of %d checks failed:\n", count($failures), $checks);
    foreach ($failures as $failure) {
        echo "  - $failure\n";
    }
    exit(1);
}
printf("All %d checks passed.\n", $checks);
