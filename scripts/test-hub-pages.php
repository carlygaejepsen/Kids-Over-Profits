<?php
/**
 * Offline render of every hub page through templates/page-hub.php and
 * inc/hub-shell.php, against tmp/prod.sqlite.
 *
 *   php scripts/test-hub-pages.php
 *   php scripts/test-hub-pages.php --live   # HEAD-check action/contribute URLs
 *
 * Each hub's <article> is written to tmp/hub-pages/<slug>.html. Checks that
 * every hub renders with its title and footer; that no hub prints the table
 * of contents or AddToAny's in-content share row; and that Law & Policy, the
 * first hub with settings, leaves its editor text out, opens with the two
 * directory columns (each record linking to its own card), lists the
 * articles filed under it and says where to contribute.
 *
 * Law & Policy, Where Are the Kids, and category-post modules are loaded.
 * Research library and Resources use their own tests. Nothing is written to
 * the database; --live performs read-only HEAD requests over SSH.
 */

if (PHP_SAPI !== 'cli') {
    exit("CLI only.\n");
}

define('ABSPATH', dirname(__DIR__) . '/');

$mirror = ABSPATH . 'tmp/prod.sqlite';
if (!file_exists($mirror) || !class_exists('PDO') || !in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    exit("Needs tmp/prod.sqlite and pdo_sqlite (scripts/sync-prod-sqlite.py).\n");
}
$GLOBALS['kop_db'] = new PDO('sqlite:' . $mirror);
$GLOBALS['kop_db']->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// --- WordPress stubs --------------------------------------------------------

$GLOBALS['kop_filters'] = array();
function add_action() {}
function add_filter($tag, $cb, $prio = 10, $args = 1) { $GLOBALS['kop_filters'][$tag][$prio][] = $cb; return true; }
function has_filter($tag, $cb) {
    foreach ($GLOBALS['kop_filters'][$tag] ?? array() as $prio => $cbs) {
        if (in_array($cb, $cbs, true)) {
            return $prio;
        }
    }
    return false;
}
function remove_filter($tag, $cb, $prio = 10) {
    $GLOBALS['kop_filters'][$tag][$prio] = array_values(array_filter(
        $GLOBALS['kop_filters'][$tag][$prio] ?? array(),
        function ($c) use ($cb) { return $c !== $cb; }
    ));
    return true;
}
function apply_filters($tag, $value, ...$rest) {
    $by_prio = $GLOBALS['kop_filters'][$tag] ?? array();
    ksort($by_prio);
    foreach ($by_prio as $cbs) {
        foreach ($cbs as $cb) {
            $value = $cb($value, ...$rest);
        }
    }
    return $value;
}
function esc_html($t) { return htmlspecialchars((string) $t, ENT_QUOTES); }
function esc_attr($t) { return htmlspecialchars((string) $t, ENT_QUOTES); }
function esc_url($u) { return htmlspecialchars((string) $u, ENT_QUOTES); }
function home_url($p = '/') { return 'https://kidsoverprofits.org' . $p; }
function get_stylesheet_directory() { return rtrim(ABSPATH, '/'); }
function get_stylesheet_directory_uri() { return 'https://kidsoverprofits.org/wp-content/themes/child'; }
function wp_enqueue_style() {}
function get_header() {}
function get_footer() {}
function date_i18n($fmt, $ts) { return date($fmt, $ts); }
function shortcode_exists($tag) { return $tag === 'addtoany'; }
function do_shortcode($s) { return $s === '[addtoany]' ? '<div class="a2a_kit addtoany_list">[share buttons]</div>' : $s; }
function wp_link_pages() {}
function comments_open() { return false; }
function get_comments_number() { return 0; }
function has_post_thumbnail() { return false; }
function is_singular($type = '') { return true; }
function get_page_template_slug() { return 'templates/page-hub.php'; }

/** Pages by slug from the mirror. */
function kop_test_page($slug) {
    static $cache = array();
    if (!array_key_exists($slug, $cache)) {
        $stmt = $GLOBALS['kop_db']->prepare(
            "SELECT ID, post_name, post_title, post_excerpt, post_content, post_status, post_modified
             FROM wpdl_posts WHERE post_name = ? AND post_type = 'page' AND post_status = 'publish' LIMIT 1"
        );
        $stmt->execute(array($slug));
        $row = $stmt->fetch(PDO::FETCH_OBJ);
        $cache[$slug] = $row ?: null;
    }
    return $cache[$slug];
}
function get_page_by_path($slug) { return kop_test_page($slug); }
function get_permalink($p) { return home_url('/' . (is_object($p) ? $p->post_name : $p) . '/'); }
function get_the_title($p = null) {
    $p = $p ?: $GLOBALS['kop_test_post'];
    return html_entity_decode($p->post_title, ENT_QUOTES, 'UTF-8');
}
function has_excerpt($p = null) { $p = $p ?: $GLOBALS['kop_test_post']; return trim($p->post_excerpt) !== ''; }
function get_the_excerpt($p = null) { $p = $p ?: $GLOBALS['kop_test_post']; return trim($p->post_excerpt); }
function get_the_date($format, $p = null) {
    $p = $p ?: $GLOBALS['kop_test_post'];
    return date($format === 'c' ? 'c' : 'F j, Y', strtotime($p->post_date));
}
function get_category_by_slug($slug) {
    $stmt = $GLOBALS['kop_db']->prepare(
        "SELECT t.term_id, t.name, t.slug FROM wpdl_terms t
         JOIN wpdl_term_taxonomy tt ON tt.term_id = t.term_id
         WHERE tt.taxonomy = 'category' AND t.slug = ? LIMIT 1"
    );
    $stmt->execute(array($slug));
    return $stmt->fetch(PDO::FETCH_OBJ) ?: false;
}
function get_the_post_thumbnail($p, $size = 'post-thumbnail', $attr = array()) {
    $stmt = $GLOBALS['kop_db']->prepare(
        "SELECT a.guid FROM wpdl_postmeta m
         JOIN wpdl_posts a ON a.ID = CAST(m.meta_value AS INTEGER)
         WHERE m.post_id = ? AND m.meta_key = '_thumbnail_id' LIMIT 1"
    );
    $stmt->execute(array((int) $p->ID));
    $src = $stmt->fetchColumn();
    return $src ? '<img src="' . esc_url($src) . '" alt="' . esc_attr($attr['alt'] ?? '') . '" loading="lazy">' : '';
}
function strip_shortcodes($text) { return preg_replace('/\[[^\]]+\]/', '', (string) $text); }
function excerpt_remove_blocks($text) { return preg_replace('/<!--.*?-->/s', '', (string) $text); }
function wp_strip_all_tags($text) { return trim(strip_tags((string) $text)); }
function wp_trim_words($text, $number, $more = '…') {
    $words = preg_split('/\s+/', trim((string) $text), -1, PREG_SPLIT_NO_EMPTY);
    return count($words) > $number ? implode(' ', array_slice($words, 0, $number)) . $more : implode(' ', $words);
}
function kop_asl_page_url_by_template($template) {
    $stmt = $GLOBALS['kop_db']->prepare(
        "SELECT p.post_name FROM wpdl_posts p JOIN wpdl_postmeta m ON m.post_id = p.ID
         WHERE m.meta_key = '_wp_page_template' AND m.meta_value = ? AND p.post_status = 'publish' LIMIT 1"
    );
    $stmt->execute(array('templates/' . $template));
    $slug = $stmt->fetchColumn();
    return $slug ? home_url('/' . $slug . '/') : '';
}
function get_posts($args) {
    if (isset($args['cat'])) {
        $limit = isset($args['posts_per_page']) ? (int) $args['posts_per_page'] : 100;
        $stmt = $GLOBALS['kop_db']->prepare(
            "SELECT p.* FROM wpdl_posts p
             JOIN wpdl_term_relationships tr ON tr.object_id = p.ID
             JOIN wpdl_term_taxonomy tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
             WHERE tt.term_id = ? AND p.post_status = 'publish' AND p.post_type = 'post'
             ORDER BY p.post_date DESC LIMIT " . max(1, $limit)
        );
        $stmt->execute(array((int) $args['cat']));
        return $stmt->fetchAll(PDO::FETCH_OBJ);
    }
    // Only the locations module asks: pages by template, by title.
    $stmt = $GLOBALS['kop_db']->prepare(
        "SELECT p.* FROM wpdl_posts p JOIN wpdl_postmeta m ON m.post_id = p.ID
         WHERE m.meta_key = '_wp_page_template' AND m.meta_value = ? AND p.post_status = 'publish' AND p.post_type = 'page'
         ORDER BY p.post_title"
    );
    $stmt->execute(array($args['meta_value']));
    return $stmt->fetchAll(PDO::FETCH_OBJ);
}
function kop_hub_pdo() { return $GLOBALS['kop_db']; }

// The loop, one post.
function have_posts() { return !$GLOBALS['kop_test_done']; }
function the_post() { $GLOBALS['kop_test_done'] = true; }
function get_the_ID() { return (int) $GLOBALS['kop_test_post']->ID; }
function the_ID() { echo get_the_ID(); }
function get_post_field($f, $id) { return $GLOBALS['kop_test_post']->post_name; }
function post_class($c) { echo 'class="' . esc_attr($c) . '"'; }
function the_title() { echo esc_html(get_the_title()); }
function get_the_modified_date($fmt = '') { return date($fmt === 'c' ? 'c' : 'F j, Y', strtotime($GLOBALS['kop_test_post']->post_modified)); }
function edit_post_link($text, $before, $after) { echo $before . '<a href="#edit">' . esc_html($text) . '</a>' . $after; }

/**
 * The plugins' content filters, reduced to a marker each, so a test can see
 * whether the hub let them run.
 */
function A2A_SHARE_SAVE_add_to_content($c) { return $c . '<div class="addtoany_content">[in-content share]</div>'; }
add_filter('the_content', 'A2A_SHARE_SAVE_add_to_content', 98);
function kop_test_ez_toc($c) {
    // As the plugin does it: the legacy hook runs, its answer is dropped,
    // and the new hook's answer decides.
    apply_filters('ez_toc_maybe_apply_the_content_filter', true);
    return apply_filters('eztoc_maybe_apply_the_content_filter', true)
        ? '<div id="ez-toc-container">[table of contents]</div>' . $c : $c;
}
add_filter('the_content', 'kop_test_ez_toc', 100);
function the_content() {
    $html = preg_replace('/<!--.*?-->/s', '', $GLOBALS['kop_test_post']->post_content);
    echo apply_filters('the_content', $html);
}

require ABSPATH . 'inc/admin.php';
require ABSPATH . 'inc/article-parts.php';
require ABSPATH . 'inc/hub-shell.php';
require ABSPATH . 'inc/hub-posts.php';

// --- Checks -----------------------------------------------------------------

$failures = 0;
$live_urls = array();
$check_live_links = in_array('--live', $argv, true);
function check($label, $ok, $detail = '') {
    global $failures;
    if (!$ok) {
        $failures++;
    }
    echo ($ok ? '  ok   ' : '  FAIL ') . $label . ($detail !== '' ? " ($detail)" : '') . "\n";
}

/** Opt-in production link check: one remote HEAD request per unique URL. */
function kop_test_live_head($url) {
    if (!preg_match('#^https?://#i', $url)) {
        return array(false, 'not an HTTP URL');
    }
    $home = getenv('USERPROFILE');
    $key  = rtrim((string) $home, '\\/') . DIRECTORY_SEPARATOR . '.ssh' . DIRECTORY_SEPARATOR . 'kop_nixihost';
    if (!is_file($key)) {
        return array(false, 'SSH key not found: ' . $key);
    }
    $system_root = getenv('SystemRoot') ?: getenv('WINDIR');
    $windows_ssh = rtrim((string) $system_root, '\\/') . DIRECTORY_SEPARATOR . 'Sysnative' . DIRECTORY_SEPARATOR . 'OpenSSH' . DIRECTORY_SEPARATOR . 'ssh.exe';
    if (!is_file($windows_ssh)) {
        $windows_ssh = rtrim((string) $system_root, '\\/') . DIRECTORY_SEPARATOR . 'System32' . DIRECTORY_SEPARATOR . 'OpenSSH' . DIRECTORY_SEPARATOR . 'ssh.exe';
    }
    if (!is_file($windows_ssh)) {
        $windows_ssh = 'ssh';
    }
    $agent = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/128 Safari/537.36';
    $shell_quote = function ($value) {
        return "'" . str_replace("'", "'\\''", (string) $value) . "'";
    };
    $remote = 'curl -sS -I -L --max-redirs 5 --max-time 30 -A ' . $shell_quote($agent)
        . ' -o /dev/null -w ' . $shell_quote('%{http_code}') . ' ' . $shell_quote($url);
    $command = array(
        $windows_ssh, '-o', 'BatchMode=yes', '-o', 'ConnectTimeout=20', '-i', $key,
        '-p', '1157', 'kidsover@dfw-s07.nixihost.com', 'bash', '-s',
    );
    $pipes = array();
    $process = proc_open($command, array(
        0 => array('pipe', 'r'),
        1 => array('pipe', 'w'),
        2 => array('pipe', 'w'),
    ), $pipes, null, null, array('bypass_shell' => true));
    if (!is_resource($process)) {
        return array(false, 'could not start OpenSSH');
    }
    fwrite($pipes[0], $remote . "\n");
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    $status = proc_close($process);
    $result = trim($stdout . "\n" . $stderr);
    if ($status !== 0) {
        return array(false, $result !== '' ? $result : 'SSH/curl failed');
    }
    $http = trim($stdout);
    return array($http === '200', $http !== '' ? 'HTTP ' . $http : $result);
}

function render_hub($slug) {
    $GLOBALS['kop_test_post'] = kop_test_page($slug);
    $GLOBALS['kop_test_done'] = false;
    if (!$GLOBALS['kop_test_post']) {
        return null;
    }
    ob_start();
    include ABSPATH . 'templates/page-hub.php';
    return ob_get_clean();
}

$out_dir = ABSPATH . 'tmp/hub-pages';
if (!is_dir($out_dir)) {
    mkdir($out_dir, 0777, true);
}

$hubs = array_keys(kop_article_hub_slugs());
echo count($hubs) . " hubs\n";
$rendered = array();
foreach ($hubs as $slug) {
    try {
        $html = render_hub($slug);
    } catch (Throwable $e) {
        check("$slug renders", false, $e->getMessage());
        continue;
    }
    if ($html === null) {
        echo "  --   $slug has no published page in the mirror\n";
        continue;
    }
    $rendered[$slug] = $html;
    file_put_contents("$out_dir/$slug.html", $html);
    echo "$slug\n";
    check('title in an h1', (bool) preg_match('#<h1 class="entry-title">[^<]+</h1>#', $html));
    check('footer with share buttons', strpos($html, 'kop-hub-footer') !== false && strpos($html, 'kop-hub-share') !== false);
    check('no table of contents', strpos($html, 'ez-toc-container') === false);
    check('no in-content share row', strpos($html, 'addtoany_content') === false);
}

echo "law-policy settings\n";
$law = $rendered['law-policy'] ?? '';
check('rendered', $law !== '');
check('standfirst', strpos($law, 'kop-hub-standfirst') !== false);
check('editor text left out', strpos($law, 'Read more here') === false && strpos($law, 'single-content') === false);
check('module leads the page', strpos($law, 'kop-hub-modules kop-hub-modules--lead') !== false);
preg_match_all('#<section class="kop-hub-col kop-hub-col--(\w+)"#', $law, $m);
check('two directory columns', $m[1] === array('lawsuits', 'legislation'), implode(',', $m[1]));
preg_match_all('#href="[^"]+/\#(lawsuit|bill)-(\d+)"#', $law, $m);
check('newest records link to their cards', count($m[0]) === 10, count($m[0]) . ' links');
$ok = true;
foreach ($m[1] as $i => $kind) {
    $table = $kind === 'lawsuit' ? 'lawsuits' : 'legislation';
    $stmt  = $GLOBALS['kop_db']->prepare("SELECT publication_status FROM $table WHERE id = ?");
    $stmt->execute(array((int) $m[2][$i]));
    $ok = $ok && in_array($stmt->fetchColumn(), array('approved', 'published'), true);
}
check('every linked record is published', $ok);
check('counts shown', (bool) preg_match('#\d+ tracked#', $law) && (bool) preg_match('#\d+ bills? tracked#', $law));
preg_match_all('#<ul class="kop-hub-reading-list">(.*?)</ul>#s', $law, $r);
$reading = isset($r[1][0]) ? substr_count($r[1][0], '<li>') : 0;
$expect  = count(array_filter(kop_article_children('law-policy'), 'kop_test_page'));
check('reading lists every published article under the hub', $reading === $expect, "$reading of $expect");
check('every reading item has a line under it', isset($r[1][0]) && substr_count($r[1][0], '<p>') === $reading);
preg_match('#<section class="kop-hub-contribute".*?</section>#s', $law, $c);
check('contribute has three links', isset($c[0]) && substr_count($c[0], '<a href=') === 3);

$latest_public_update = $GLOBALS['kop_db']->query(
    "SELECT MAX(updated_at) FROM (
        SELECT updated_at FROM lawsuits WHERE publication_status IN ('approved','published')
        UNION ALL
        SELECT updated_at FROM legislation WHERE publication_status IN ('approved','published')
    ) AS public_records"
)->fetchColumn();
check('Law & Policy date uses the newest public record update',
    $latest_public_update && strpos($law, date_i18n('F j, Y', strtotime($latest_public_update))) !== false,
    (string) $latest_public_update);

echo "every hub's settings\n";
foreach ($rendered as $slug => $html) {
    $config = kop_hub_config($slug);
    $keeps  = !isset($config['content']) || $config['content'] !== false;
    check("$slug " . ($keeps ? 'prints' : 'leaves out') . ' its editor content',
        (strpos($html, 'entry-content single-content') !== false) === $keeps);
    if (!empty($config['standfirst']) || in_array($slug, array('editorials', 'investigatory-spotlight'), true)) {
        check("$slug has a standfirst", strpos($html, 'kop-hub-standfirst') !== false);
    }
    if (in_array($slug, array('editorials', 'investigatory-spotlight'), true)) {
        check("$slug has its category post module", strpos($html, 'kop-hub-post-list') !== false);
    }
    // A link whose page is missing is dropped without a word, so a typo in a
    // slug would only show as a button that is not there.
    foreach (array('actions' => $config['actions'] ?? array(), 'contribute' => $config['contribute']['links'] ?? array()) as $kind => $links) {
        if (!$links) {
            continue;
        }
        $resolved = kop_hub_links($links);
        $dropped  = array_diff(array_column($links, 'label'), array_column($resolved, 'label'));
        check("$slug $kind all resolve", !$dropped, implode(', ', $dropped));
        if ($check_live_links) {
            foreach ($resolved as $link) {
                $live_urls[] = preg_replace('/#.*$/', '', $link['url']);
            }
        }
    }
    if (!empty($config['reading'])) {
        $children = array_filter(kop_article_children($slug), 'kop_test_page');
        $notes    = $config['reading_notes'] ?? array();
        $bare     = array_filter($children, function ($c) use ($notes) { return !has_excerpt(kop_test_page($c)) && empty($notes[$c]); });
        check("$slug reading has a line for every article", !$bare, implode(', ', $bare));
    }
}

if ($check_live_links) {
    $live_urls = array_values(array_unique($live_urls));
    echo "\nlive action and contribute URLs\n";
    foreach ($live_urls as $url) {
        list($ok, $detail) = kop_test_live_head($url);
        check($url, $ok, $detail);
    }
}

echo $failures ? "\n$failures failed\n" : "\nall passed\n";
exit($failures ? 1 : 0);
