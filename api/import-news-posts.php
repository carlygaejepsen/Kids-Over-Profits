<?php
/**
 * Export the curated WordPress "News" posts (the /news page, page 829) into the
 * structured news_submissions feed (the "Troubled Teen Industry News" feed
 * rendered by templates/page-news-feed.php).
 *
 * Each /news post follows a fixed shape we parse into submission fields:
 *
 *   Author: <name>
 *   Trigger warnings: <comma list>            (optional)  -> content_warnings
 *   <a href="EXTERNAL_URL">Read the full article here.</a> -> article_url
 *   Summary: <text...>                                     -> summary
 *
 *   post title  -> article_title          post date     -> publication_date
 *   url host    -> publication_name        categories    -> article_location + tags
 *
 * Posts that don't carry an external link AND a summary (e.g. utility posts like
 * "Data Organizer") are skipped as non-news.
 *
 * Idempotent: the source WP post id is stored in json_data.source_post_id; a
 * re-run skips posts already imported (also de-duped by article_url).
 *
 * Safety: requires ?run=1 from a logged-in admin, or CLI. Options:
 *   ?dry=1                 preview without writing
 *   ?status=published      submission status to write (default: published)
 *   ?limit=N               cap how many posts to process
 *
 *   Preview: /api/import-news-posts.php?run=1&dry=1
 *   Import:  /api/import-news-posts.php?run=1
 */

header('Content-Type: application/json');

require_once __DIR__ . '/config.php';

$is_cli  = php_sapi_name() === 'cli';
$dry_run = $is_cli ? in_array('dry', $argv ?? [], true) : !empty($_GET['dry']);
$run_ok  = $is_cli || (($_GET['run'] ?? null) === '1');
$status  = isset($_GET['status']) ? preg_replace('/[^a-z]/', '', strtolower($_GET['status'])) : 'published';
if ($status === '') $status = 'published';
$limit   = isset($_GET['limit']) ? max(0, (int)$_GET['limit']) : 0;

// Load WordPress (needed for WP_Query / categories).
if (!defined('ABSPATH')) {
    $current = __DIR__;
    for ($i = 0; $i < 6; $i++) {
        $current = dirname($current);
        if (file_exists($current . '/wp-load.php')) { require_once $current . '/wp-load.php'; break; }
    }
}

if (!$is_cli) {
    $is_admin = function_exists('current_user_can') && current_user_can('manage_options');
    if (!$run_ok || !$is_admin) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Requires ?run=1 and an admin session, or CLI execution.']);
        exit;
    }
}

if (!function_exists('get_posts')) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'WordPress not loaded; cannot read posts.']);
    exit;
}

// ---- helpers --------------------------------------------------------------

$US_STATES = ['Alabama','Alaska','Arizona','Arkansas','California','Colorado','Connecticut','Delaware','Florida','Georgia','Hawaii','Idaho','Illinois','Indiana','Iowa','Kansas','Kentucky','Louisiana','Maine','Maryland','Massachusetts','Michigan','Minnesota','Mississippi','Missouri','Montana','Nebraska','Nevada','New Hampshire','New Jersey','New Mexico','New York','North Carolina','North Dakota','Ohio','Oklahoma','Oregon','Pennsylvania','Rhode Island','South Carolina','South Dakota','Tennessee','Texas','Utah','Vermont','Virginia','Washington','West Virginia','Wisconsin','Wyoming'];
$COUNTRIES = ['Australia','Canada','Ireland','United Kingdom','UK','New Zealand','Italy','India','Mexico','Scotland','England'];
// Structural buckets that shouldn't become tags/locations.
$STRUCTURAL_CATS = ['News','Local News','US News','International News','Uncategorized'];

// Prettify a publication name from a URL host.
$PUB_MAP = [
    'theguardian.com' => 'The Guardian', 'nytimes.com' => 'The New York Times',
    'washingtonpost.com' => 'The Washington Post', 'ottawacitizen.com' => 'Ottawa Citizen',
    'apnews.com' => 'Associated Press', 'bbc.com' => 'BBC', 'bbc.co.uk' => 'BBC',
    'cbc.ca' => 'CBC', 'abc.net.au' => 'ABC News (Australia)', 'reuters.com' => 'Reuters',
    'propublica.org' => 'ProPublica', 'npr.org' => 'NPR',
];
function kop_pub_from_url(string $url, array $map): string {
    $host = strtolower((string)parse_url($url, PHP_URL_HOST));
    $host = preg_replace('/^www\./', '', $host);
    if ($host === '') return '';
    if (isset($map[$host])) return $map[$host];
    return $host; // fallback: bare domain
}

/** Infer a news_submissions article_type from the title/summary text. */
function kop_infer_article_type(string $text): string {
    $t = strtolower($text);
    $patterns = [
        'lawsuit'   => '/\b(lawsuit|sued?|suing|class action|litigation|settlement|files? suit|wrongful death)\b/',
        'arrest'    => '/\b(arrest|convicted|charged|sentenced|pleads? guilty|indict|guilty|felony|prosecut)\b/',
        'closure'   => '/\b(shut down|shuts down|closed|closure|to close|loses license|license revoked)\b/',
        'corporate' => '/\b(acquir|merger|rebrand|parent company|sold to|buys|acquisition)\b/',
        'expose'    => '/\b(investigation|investigat|expos|reveals|uncover|whistleblow)\b/',
        'event'     => '/\b(hearing|rally|protest|vigil|conference|testif|legislat|bill\b)/',
    ];
    foreach ($patterns as $type => $re) {
        if (preg_match($re, $t)) return $type;
    }
    return 'general';
}

/** Parse a post's raw HTML content into submission fields. */
function kop_parse_news_post(string $html): array {
    $out = ['author' => '', 'url' => '', 'warnings' => [], 'summary' => ''];

    // External source link: first <a href> whose host isn't this site.
    if (preg_match_all('/<a[^>]+href=["\']([^"\']+)["\']/i', $html, $m)) {
        foreach ($m[1] as $href) {
            $host = strtolower((string)parse_url($href, PHP_URL_HOST));
            if ($host && strpos($host, 'kidsoverprofits.org') === false && preg_match('#^https?://#i', $href)) {
                $out['url'] = html_entity_decode($href, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                break;
            }
        }
    }

    // Strip tags to plain text (keep line breaks) for the labelled fields.
    $text = preg_replace('/<br\s*\/?>/i', "\n", $html);
    $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');

    if (preg_match('/Author:\s*(.+)/i', $text, $a)) {
        $out['author'] = trim(preg_split('/\R/', trim($a[1]))[0]);
    }
    if (preg_match('/Trigger warnings?:\s*(.+)/i', $text, $w)) {
        $line = trim(preg_split('/\R/', trim($w[1]))[0]);
        $out['warnings'] = array_values(array_filter(array_map('trim', explode(',', $line))));
    }
    // Summary: everything from the "Summary:" marker onward.
    if (preg_match('/Summary:\s*(.+)/is', $text, $s)) {
        $out['summary'] = trim($s[1]);
    }
    return $out;
}

// ---- main -----------------------------------------------------------------

try {
    // Pre-load existing imports for idempotency.
    $existingPostIds = [];
    $existingUrls = [];
    $pre = $pdo->query("SELECT article_url, json_data FROM news_submissions");
    foreach ($pre->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if (!empty($row['article_url'])) $existingUrls[strtolower(trim($row['article_url']))] = true;
        $jd = json_decode((string)$row['json_data'], true);
        if (is_array($jd) && !empty($jd['source_post_id'])) $existingPostIds[(int)$jd['source_post_id']] = true;
    }

    $args = [
        'post_type'      => 'post',
        'post_status'    => 'publish',
        'posts_per_page' => $limit > 0 ? $limit : -1,
        'orderby'        => 'date',
        'order'          => 'DESC',
    ];
    $posts = get_posts($args);

    $insert = $pdo->prepare(
        "INSERT INTO news_submissions
            (article_title, alternate_title, author, publication_name, publication_date,
             article_url, article_type, article_location, tags, facilities_mentioned, staff_mentioned,
             survivors_mentioned, content_warnings, summary, json_data,
             generated_output, status, submitted_by, submission_notes)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );

    $stats = [
        'posts_scanned'      => 0,
        'imported'           => 0,
        'skipped_existing'   => 0,
        'skipped_non_news'   => 0,
        'samples'            => [], // first few imported, for review
    ];

    foreach ($posts as $post) {
        $stats['posts_scanned']++;
        $pid = (int)$post->ID;

        if (isset($existingPostIds[$pid])) { $stats['skipped_existing']++; continue; }

        $parsed = kop_parse_news_post((string)$post->post_content);

        // Must look like a curated news entry.
        if ($parsed['url'] === '' || $parsed['summary'] === '') { $stats['skipped_non_news']++; continue; }
        if (isset($existingUrls[strtolower(trim($parsed['url']))])) { $stats['skipped_existing']++; continue; }

        $title = html_entity_decode(get_the_title($post), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $date  = mysql2date('Y-m-d', $post->post_date);

        // Categories -> location + tags.
        $cats = array_map(function ($c) { return $c->name; }, get_the_category($pid) ?: []);
        $location = '';
        foreach ($cats as $c) {
            if (in_array($c, $US_STATES, true)) { $location = $c; break; }
        }
        if ($location === '') {
            foreach ($cats as $c) { if (in_array($c, $COUNTRIES, true)) { $location = $c; break; } }
        }
        $tags = array_values(array_diff($cats, $STRUCTURAL_CATS));

        $publication = kop_pub_from_url($parsed['url'], $PUB_MAP);
        $articleType = kop_infer_article_type($title . ' ' . $parsed['summary']);

        $jsonData = [
            'source'          => 'wp-news-import',
            'source_post_id'  => $pid,
            'source_post_url' => get_permalink($pid),
            'categories'      => $cats,
        ];

        $stats['imported']++;
        if (count($stats['samples']) < 8) {
            $stats['samples'][] = [
                'post_id' => $pid, 'title' => $title, 'date' => $date,
                'author' => $parsed['author'], 'publication' => $publication,
                'url' => $parsed['url'], 'type' => $articleType, 'location' => $location,
                'warnings' => $parsed['warnings'], 'tags' => $tags,
            ];
        }

        if (!$dry_run) {
            $insert->execute([
                $title,
                '',                       // alternate_title
                $parsed['author'],
                $publication,
                $date ?: null,
                $parsed['url'],
                $articleType,
                $location,
                json_encode($tags, JSON_UNESCAPED_UNICODE),
                json_encode([], JSON_UNESCAPED_UNICODE),   // facilities_mentioned (none from posts)
                json_encode([], JSON_UNESCAPED_UNICODE),   // staff_mentioned
                json_encode([], JSON_UNESCAPED_UNICODE),   // survivors_mentioned
                json_encode($parsed['warnings'], JSON_UNESCAPED_UNICODE),
                $parsed['summary'],
                json_encode($jsonData, JSON_UNESCAPED_UNICODE),
                '',                       // generated_output
                $status,
                'wp-news-import',
                'Imported from WP post #' . $pid,
            ]);
            // Keep in-memory sets fresh in case of dup URLs within this run.
            $existingPostIds[$pid] = true;
            $existingUrls[strtolower(trim($parsed['url']))] = true;
        }
    }

    echo json_encode([
        'success'      => true,
        'dry_run'      => (bool)$dry_run,
        'write_status' => $status,
        'stats'        => $stats,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Error: ' . $e->getMessage()]);
}
