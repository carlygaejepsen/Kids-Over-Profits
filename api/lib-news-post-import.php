<?php
/**
 * Move the legacy WordPress news posts (the four news categories the old
 * /news/ page listed) into news_submissions, the table the news feed reads.
 *
 * Each post carries its facts twice: in ACF fields (article_title, date,
 * source_url, article_source, author, facilities_mentioned,
 * trigger_warnings), each value usually prefixed with its own label
 * ("Source: Kennebec Journal"), and in the body as "Author: ..." lines, a
 * "Read the full article here." link and a summary. The fields win; the body
 * fills whatever a field leaves empty. Some posts have no link in the body
 * at all, only source_url.
 *
 * The import itself (kop_news_post_import) takes plain arrays and a PDO, so
 * scripts/test-news-post-import.php runs it against tmp/prod.sqlite. The
 * WordPress side (kop_news_post_import_sources) only gathers the posts.
 *
 * Idempotent: json_data.source_post_id marks an imported post, and a post
 * whose article URL is already in the feed is skipped as a duplicate.
 */

require_once __DIR__ . '/news-tags.php';

if (!function_exists('kop_news_post_import_categories')) {
    /** The categories the old /news/ page listed. */
    function kop_news_post_import_categories(): array {
        return ['local-news', 'us-news', 'international-news', 'independent-journalism'];
    }
}

if (!function_exists('kop_news_post_import_sources')) {
    /**
     * Every published post in the news categories, as the arrays
     * kop_news_post_import() takes. Needs WordPress.
     */
    function kop_news_post_import_sources(): array {
        $ids = [];
        foreach (kop_news_post_import_categories() as $slug) {
            $term = get_category_by_slug($slug);
            if ($term) $ids[] = (int) $term->term_id;
        }
        if (!$ids) return [];
        $posts = get_posts([
            'post_type'        => 'post',
            'post_status'      => 'publish',
            'category__in'     => $ids,
            'posts_per_page'   => -1,
            'orderby'          => 'date',
            'order'            => 'ASC',
            'suppress_filters' => true,
        ]);
        $keys = ['article_title', 'date', 'source_url', 'article_source', 'author', 'facilities_mentioned', 'trigger_warnings'];
        $out = [];
        foreach ($posts as $post) {
            $meta = [];
            foreach ($keys as $k) {
                $meta[$k] = (string) get_post_meta($post->ID, $k, true);
            }
            $out[] = [
                'id'         => (int) $post->ID,
                'title'      => (string) $post->post_title,
                'date'       => (string) $post->post_date,
                'content'    => (string) $post->post_content,
                'permalink'  => (string) get_permalink($post),
                'categories' => array_map(function ($c) { return $c->name; }, get_the_category($post->ID) ?: []),
                'meta'       => $meta,
            ];
        }
        $page = get_page_by_path('news-2');
        if ($page) {
            $out = array_merge($out, kop_news_page_entries((string) $page->post_content, (string) get_permalink($page)));
        }
        return $out;
    }
}

if (!function_exists('kop_news_post_import_url_fixes')) {
    /**
     * Source URLs for the posts that carry none, in a field or in the body,
     * found by searching for the headline (2026-09-25). A post not listed
     * here and still without a URL links to its own permalink, which stays
     * published and holds the notes.
     */
    function kop_news_post_import_url_fixes(): array {
        return [
            798  => 'https://kentuckylantern.com/2024/08/04/former-ky-gov-matt-bevins-adopted-son-reportedly-removed-from-abusive-facility-in-jamaica/',
            2847 => 'https://www.prnewswire.com/news-releases/levy-konigsberg-attorneys-madeleine-skaller-and-zoe-ferguson-have-filed-lawsuits-on-behalf-of-eleven-survivors-who-were-sexually-abused-by-adult-staff-members-when-they-were-confined-as-children-at-camden-county-youth-detention-ce-302482932.html',
            2974 => 'https://imprintnews.org/top-stories/hilda-finds-her-voice/262329',
            // From the hand-written 2024 index (/news-2/), which links it.
            810  => 'https://www.latimes.com/california/story/2024-08-28/legislature-sends-troubled-teen-industry-bill-supported-by-paris-hilton-to-governors-desk',
        ];
    }
}

if (!function_exists('kop_news_page_entries')) {
    /**
     * The hand-written 2024 press index (page news-2) as post-shaped entries.
     * Each entry is a link reading "August 4, 2024 Outlet: Headline", either
     * alone in a paragraph or as the summary of a details block whose body
     * holds the notes (programs mentioned, author, triggers, key points).
     * source_post_id stays 0; the article URL is what keeps a re-run from
     * importing an entry twice.
     */
    function kop_news_page_entries(string $html, string $permalink = ''): array {
        $re = '~<a\s[^>]*href=["\'](https?://[^"\']+)["\'][^>]*>\s*([A-Z][a-z]+ \d{1,2}, \d{4})\s+(.+?)</a>~s';
        if (!preg_match_all($re, $html, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) return [];
        $out = [];
        foreach ($m as $i => $hit) {
            $url  = html_entity_decode($hit[1][0], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $when = strtotime($hit[2][0]);
            $label = trim(html_entity_decode(strip_tags($hit[3][0]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if (strpos($url, 'kidsoverprofits.org') !== false || !$when || $label === '') continue;
            // The index was typed by hand; a date in the URL itself wins.
            if (preg_match('~/(20\d{2})/(\d{2})/(\d{2})/~', $url, $ud) && checkdate((int) $ud[2], (int) $ud[3], (int) $ud[1])) {
                $when = mktime(0, 0, 0, (int) $ud[2], (int) $ud[3], (int) $ud[1]);
            }
            // Notes run from the end of this link to the close of its details
            // block, or to the next entry, whichever comes first.
            $start = $hit[0][1] + strlen($hit[0][0]);
            $end   = isset($m[$i + 1]) ? $m[$i + 1][0][1] : strlen($html);
            $body  = substr($html, $start, $end - $start);
            $close = strpos($body, '</details>');
            $body  = $close !== false ? substr($body, 0, $close) : '';
            $outlet = '';
            $title  = $label;
            if (preg_match('/^([^:]{2,60}):\s+(.{10,})$/u', $label, $lm)) {
                $outlet = trim($lm[1]);
                $title  = trim($lm[2]);
            }
            $programs = '';
            if (preg_match('/Programs? mentioned\s*:\s*([^<\n]+)/i', $body, $pm)) {
                $programs = trim(html_entity_decode($pm[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            }
            $out[] = [
                'id'         => 0,
                'title'      => $title,
                'date'       => date('Y-m-d', $when),
                'content'    => $body,
                'permalink'  => $permalink,
                'categories' => [],
                'meta'       => [
                    'article_title'        => $title,
                    'date'                 => date('Ymd', $when),
                    'source_url'           => $url,
                    'article_source'       => $outlet,
                    'facilities_mentioned' => $programs,
                ],
            ];
        }
        return $out;
    }
}

if (!function_exists('kop_npi_unlabel')) {
    /** "Source: Kennebec Journal" -> "Kennebec Journal"; a bare label -> "". */
    function kop_npi_unlabel(string $value): string {
        $value = trim(html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $value = preg_replace('/^(?:Authors?|Source|Facilities mentioned|Trigger warnings?|Triggers?)\s*:?\s*/i', '', $value);
        return trim((string) $value);
    }
}

if (!function_exists('kop_npi_list')) {
    /** A comma list, trimmed, de-duplicated, empty items dropped. */
    function kop_npi_list(string $value): array {
        $out = [];
        foreach (preg_split('/\s*[,;]\s*/', kop_npi_unlabel($value)) ?: [] as $item) {
            $item = trim($item, " \t.");
            if ($item !== '' && !in_array(strtolower($item), array_map('strtolower', $out), true)) $out[] = $item;
        }
        return $out;
    }
}

if (!function_exists('kop_npi_url_key')) {
    /** Compare key for an article URL: no scheme, www, fragment or trailing slash. */
    function kop_npi_url_key(string $url): string {
        $url = strtolower(trim($url));
        $url = preg_replace('~^https?://(www\.)?~', '', $url);
        $url = preg_replace('~#.*$~', '', $url);
        return rtrim((string) $url, '/');
    }
}

if (!function_exists('kop_npi_publication_from_url')) {
    function kop_npi_publication_from_url(string $url): string {
        $map = [
            'theguardian.com' => 'The Guardian', 'nytimes.com' => 'The New York Times',
            'washingtonpost.com' => 'The Washington Post', 'apnews.com' => 'Associated Press',
            'bbc.com' => 'BBC', 'bbc.co.uk' => 'BBC', 'cbc.ca' => 'CBC', 'reuters.com' => 'Reuters',
            'propublica.org' => 'ProPublica', 'npr.org' => 'NPR', 'nbcnews.com' => 'NBC News',
        ];
        // An archived copy names the site it archived, not the archive.
        if (preg_match('~^https?://web\.archive\.org/web/[^/]+/(https?://.+)$~i', $url, $m)) {
            $url = $m[1];
        }
        $host = preg_replace('/^www\./', '', strtolower((string) parse_url($url, PHP_URL_HOST)));
        return $map[$host] ?? (string) $host;
    }
}

if (!function_exists('kop_npi_article_type')) {
    /** Same buckets the feed's other writers use. */
    function kop_npi_article_type(string $text): string {
        $t = strtolower($text);
        $patterns = [
            'lawsuit'   => '/\b(lawsuit|sued?|suing|class action|litigation|settlement|files? suit|wrongful death)\b/',
            'arrest'    => '/\b(arrest|arrested|convicted|charged|sentenced|pleads? guilty|indict|guilty|felony|prosecut)/',
            'closure'   => '/\b(shut down|shuts down|closed|closure|to close|loses license|license revoked)\b/',
            'corporate' => '/\b(acquir|merger|rebrand|parent company|sold to|buys|acquisition)/',
            'expose'    => '/\b(investigation|investigat|expos|reveals|uncover|whistleblow)/',
            'event'     => '/\b(hearing|rally|protest|vigil|conference|testif|legislat|bill\b)/',
        ];
        foreach ($patterns as $type => $re) {
            if (preg_match($re, $t)) return $type;
        }
        return 'general';
    }
}

if (!function_exists('kop_npi_parse_body')) {
    /**
     * The body's own facts: external links (first is the article), author,
     * warnings, and the summary text with labels and link lines removed.
     */
    function kop_npi_parse_body(string $html): array {
        $out = ['urls' => [], 'author' => '', 'warnings' => [], 'summary' => ''];
        if (preg_match_all('/<a[^>]+href=["\']([^"\']+)["\']/i', $html, $m)) {
            foreach ($m[1] as $href) {
                $href = html_entity_decode($href, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $host = strtolower((string) parse_url($href, PHP_URL_HOST));
                if ($host && strpos($host, 'kidsoverprofits.org') === false && preg_match('#^https?://#i', $href)
                    && !in_array($href, $out['urls'], true)) {
                    $out['urls'][] = $href;
                }
            }
        }
        $text_html = preg_replace('/<!--.*?-->/s', '', $html);
        $text_html = preg_replace('/<a\b[^>]*>.*?<\/a>/is', ' ', $text_html);
        $text_html = preg_replace('/<br\s*\/?>|<\/(?:p|div|li|h[1-6])\s*>/i', "\n", $text_html);
        $text = html_entity_decode(strip_tags($text_html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/[ \t\x{00A0}]+/u', ' ', str_replace("\r", '', $text));

        $lines = [];
        foreach (preg_split('/\n/', $text) as $line) {
            $line = trim($line);
            if ($line === '') continue;
            if (preg_match('/^Authors?\s*:\s*(.+)$/i', $line, $a)) { $out['author'] = trim($a[1]); continue; }
            if (preg_match('/^(?:Trigger warnings?|Triggers?|TW)\s*:\s*(.+)$/i', $line, $w)) { $out['warnings'] = kop_npi_list($w[1]); continue; }
            if (preg_match('/^(?:Facilities mentioned|Programs? mentioned|Source|Publication|Date)\s*:/i', $line)) continue;
            if (preg_match('/^(?:Read (?:the|more)|Additional coverage|Related coverage|Key Points?\s*:?$)/i', $line)) continue;
            if (preg_match('/^(?:Post Tags?|Previous|Next)\b/i', $line)) break;
            $lines[] = preg_replace('/^Summary\s*:\s*/i', '', $line);
        }
        $out['summary'] = trim(implode("\n\n", $lines));
        return $out;
    }
}

if (!function_exists('kop_npi_build_row')) {
    /** One source post -> the news_submissions columns, or null when it is not an article. */
    function kop_npi_build_row(array $post): ?array {
        $meta = $post['meta'] ?? [];
        $body = kop_npi_parse_body((string) ($post['content'] ?? ''));

        $fixes = kop_news_post_import_url_fixes();
        $url = trim((string) ($meta['source_url'] ?? ''));
        if (!preg_match('#^https?://#i', $url)) $url = $body['urls'][0] ?? '';
        if ($url === '' && isset($fixes[(int) $post['id']])) $url = $fixes[(int) $post['id']];
        if ($url === '' && $body['summary'] === '') return null;
        if ($url === '') $url = (string) ($post['permalink'] ?? '');

        // A few posts hold the whole summary in the article_title field.
        $title = kop_npi_unlabel((string) ($meta['article_title'] ?? ''));
        $title_from_post = false;
        if ($title === '' || mb_strlen($title) > 200) {
            $title = html_entity_decode((string) $post['title'], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $title_from_post = true;
        }

        $date = '';
        if (preg_match('/^(\d{4})(\d{2})(\d{2})$/', trim((string) ($meta['date'] ?? '')), $d)) {
            $date = "$d[1]-$d[2]-$d[3]";
        } elseif (!empty($post['date'])) {
            $date = substr((string) $post['date'], 0, 10);
        }

        $author = kop_npi_unlabel((string) ($meta['author'] ?? ''));
        if ($author === '') $author = $body['author'];

        $publication = kop_npi_unlabel((string) ($meta['article_source'] ?? ''));

        // The 2024 posts were titled "Publication: Headline". Split that when
        // the prefix reads as the outlet: short, and either no source field
        // or a source field that names the same outlet.
        if ($title_from_post && preg_match('/^([^:]{2,60}):\s+(.{10,})$/u', $title, $tm) && str_word_count($tm[1]) <= 8) {
            $norm = function ($s) {
                $k = preg_replace('/[^a-z0-9]/', '', strtolower($s));
                return strtr($k, ['latimes' => 'losangelestimes']);
            };
            $prefix = $norm($tm[1]);
            $pub    = $norm($publication);
            if ($pub === '' || strpos($prefix, $pub) !== false || strpos($pub, $prefix) !== false) {
                if ($publication === '') $publication = trim($tm[1]);
                $title = trim($tm[2]);
            }
        }
        if ($publication === '' && $url !== '') $publication = kop_npi_publication_from_url($url);

        // A first line that repeats the headline ("August 4, 2024 Kentucky
        // Lantern: Headline") is not summary.
        $summary = $body['summary'];
        $paras = preg_split('/

/', $summary);
        if ($paras && mb_stripos($paras[0], $title) !== false && mb_strlen($paras[0]) < mb_strlen($title) + 80) {
            array_shift($paras);
            $summary = trim(implode("

", $paras));
        }

        $warnings = kop_npi_list((string) ($meta['trigger_warnings'] ?? ''));
        if (!$warnings) $warnings = $body['warnings'];

        $facilities = [];
        foreach (kop_npi_list((string) ($meta['facilities_mentioned'] ?? '')) as $name) {
            $facilities[] = ['name' => $name, 'facility_id' => null];
        }

        $states = ['Alabama','Alaska','Arizona','Arkansas','California','Colorado','Connecticut','Delaware','Florida','Georgia','Hawaii','Idaho','Illinois','Indiana','Iowa','Kansas','Kentucky','Louisiana','Maine','Maryland','Massachusetts','Michigan','Minnesota','Mississippi','Missouri','Montana','Nebraska','Nevada','New Hampshire','New Jersey','New Mexico','New York','North Carolina','North Dakota','Ohio','Oklahoma','Oregon','Pennsylvania','Rhode Island','South Carolina','South Dakota','Tennessee','Texas','Utah','Vermont','Virginia','Washington','West Virginia','Wisconsin','Wyoming'];
        $countries = ['Australia','Canada','Ireland','United Kingdom','New Zealand','Italy','India','Mexico','Jamaica','Samoa','Costa Rica','Dominican Republic'];
        $cats = array_values(array_map('strval', $post['categories'] ?? []));
        $location = '';
        foreach (array_merge($states, $countries) as $place) {
            if (in_array($place, $cats, true)) { $location = $place; break; }
        }
        $structural = ['News', 'Local News', 'US News', 'International News', 'Independent Journalism', 'Uncategorized'];
        $tags = kop_news_tags_normalize(array_values(array_diff($cats, $structural)));

        return [
            'article_title'        => $title,
            'author'               => $author,
            'publication_name'     => $publication,
            'publication_date'     => $date !== '' ? $date : null,
            'article_url'          => $url,
            'article_type'         => kop_npi_article_type($title . ' ' . $summary),
            'article_location'     => $location,
            'tags'                 => $tags,
            'facilities_mentioned' => $facilities,
            'content_warnings'     => $warnings,
            'summary'              => $summary,
            'json_data'            => [
                'source'              => (int) $post['id'] > 0 ? 'wp-news-import' : 'wp-news-page-import',
                'source_post_id'      => (int) $post['id'],
                'source_post_url'     => (string) ($post['permalink'] ?? ''),
                'categories'          => $cats,
                'additional_coverage' => array_values(array_filter($body['urls'], function ($u) use ($url) {
                    return kop_npi_url_key($u) !== kop_npi_url_key($url);
                })),
            ],
        ];
    }
}

if (!function_exists('kop_news_post_import')) {
    /**
     * Insert the posts that are not in the feed yet.
     *
     * Options: dry (bool), status (string, default 'approved'),
     * link_facilities (bool, default true; resolves facilities_mentioned
     * through api/news-mentions.php like every other feed writer).
     */
    function kop_news_post_import(PDO $pdo, array $posts, array $opts = []): array {
        $dry    = !empty($opts['dry']);
        $status = (string) ($opts['status'] ?? 'approved');
        $link   = array_key_exists('link_facilities', $opts) ? (bool) $opts['link_facilities'] : true;

        $seenPosts = [];
        $seenUrls  = [];
        foreach ($pdo->query("SELECT article_url, json_data FROM news_submissions WHERE status <> 'deleted'")->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (!empty($row['article_url'])) $seenUrls[kop_npi_url_key((string) $row['article_url'])] = true;
            $jd = json_decode((string) $row['json_data'], true);
            if (is_array($jd) && !empty($jd['source_post_id'])) $seenPosts[(int) $jd['source_post_id']] = true;
        }

        $insert = $pdo->prepare(
            "INSERT INTO news_submissions
                (article_title, alternate_title, author, publication_name, publication_date,
                 article_url, article_type, article_location, tags, facilities_mentioned, staff_mentioned,
                 survivors_mentioned, content_warnings, summary, json_data,
                 generated_output, status, submitted_by, submission_notes)
             VALUES (?, '', ?, ?, ?, ?, ?, ?, ?, ?, '[]', '[]', ?, ?, ?, '', ?, 'wp-news-import', ?)"
        );
        if ($link && !$dry) require_once __DIR__ . '/news-mentions.php';

        // Index entries are also matched by headline: the index and a post
        // sometimes link the same story under different URLs.
        $titleKey = function ($t) { return substr(preg_replace('/[^a-z0-9]/', '', strtolower((string) $t)), 0, 60); };
        $seenTitles = [];
        foreach ($pdo->query("SELECT article_title FROM news_submissions WHERE status <> 'deleted'")->fetchAll(PDO::FETCH_COLUMN) as $t) {
            $seenTitles[$titleKey($t)] = true;
        }

        $stats = ['scanned' => 0, 'imported' => 0, 'already_imported' => 0, 'duplicate_url' => 0, 'not_article' => 0, 'rows' => []];
        foreach ($posts as $post) {
            $stats['scanned']++;
            $pid = (int) $post['id'];
            if ($pid > 0 && isset($seenPosts[$pid])) { $stats['already_imported']++; continue; }
            $row = kop_npi_build_row($post);
            if ($row === null) { $stats['not_article']++; continue; }
            $key = $row['article_url'] !== '' ? kop_npi_url_key($row['article_url']) : '';
            if ($pid === 0 && isset($seenTitles[$titleKey($row['article_title'])])) { $stats['already_imported']++; continue; }
            if ($key !== '' && isset($seenUrls[$key])) {
                // An index entry already in the feed is the expected case on a re-run.
                $stats[$pid > 0 ? 'duplicate_url' : 'already_imported']++;
                continue;
            }

            $stats['imported']++;
            $stats['rows'][] = ['post_id' => $pid, 'title' => $row['article_title'], 'date' => $row['publication_date'],
                'publication' => $row['publication_name'], 'url' => $row['article_url'], 'type' => $row['article_type']];
            if ($pid > 0) $seenPosts[$pid] = true;
            $seenTitles[$titleKey($row['article_title'])] = true;
            if ($key !== '') $seenUrls[$key] = true;
            if ($dry) continue;

            $j = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
            $insert->execute([
                $row['article_title'], $row['author'], $row['publication_name'], $row['publication_date'],
                $row['article_url'], $row['article_type'], $row['article_location'],
                json_encode($row['tags'], $j), json_encode($row['facilities_mentioned'], $j),
                json_encode($row['content_warnings'], $j), $row['summary'], json_encode($row['json_data'], $j),
                $status, 'Imported from WP post #' . $pid,
            ]);
            if ($link && $row['facilities_mentioned'] && function_exists('kop_sync_news_facility_links')) {
                try {
                    kop_sync_news_facility_links($pdo, (int) $pdo->lastInsertId(), $row['facilities_mentioned'], 'wp-news-import');
                } catch (Throwable $e) {
                    // The article is in the feed either way; links can be redone
                    // with api/migrate-news-facilities-to-objects.php.
                }
            }
        }
        return $stats;
    }
}
