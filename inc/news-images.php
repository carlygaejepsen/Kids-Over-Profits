<?php
/**
 * News images: a picture for each saved news article, for the article cards
 * on the facility pages.
 *
 * The article's own share image (og:image / twitter:image / JSON-LD image)
 * when its page gives one; otherwise the publication's logo (JSON-LD
 * publisher logo, apple-touch-icon, a large icon link, then the site's
 * /apple-touch-icon.png). A page the server cannot read (bot wall, paywall,
 * gone) is tried again from its Wayback copy.
 *
 * Images are copied into uploads/kop-news-images/ (photos cut to 16:9 at
 * 640x360, logos fitted into 160x160), never hotlinked: a news site's image
 * URL can change, block other sites, or tell it who reads our pages.
 *
 * An hourly WP-Cron job works through the approved articles, linked
 * facilities first, a batch at a time. What it found is kept in two options
 * (kop_news_images: article id => result, kop_news_logos: host => result), so
 * the pages read one option and never fetch anything. A miss is retried after
 * a week, three times at most.
 *
 * Server shell: php api/fetch-news-images.php [apply] [--limit=N] [--ids=1,2]
 * Offline test: php scripts/test-news-images.php [--live=10]
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('kop_news_images_dir')) {
    /** [path, url] of the folder the images are copied into. */
    function kop_news_images_dir() {
        $up = wp_upload_dir(null, false);
        return array(rtrim($up['basedir'], '/\\') . '/kop-news-images', rtrim($up['baseurl'], '/') . '/kop-news-images');
    }
}

if (!function_exists('kop_news_images_host')) {
    /** "www.sltrib.com" -> "sltrib.com"; '' for a URL without a host. */
    function kop_news_images_host($url) {
        $host = strtolower((string) wp_parse_url((string) $url, PHP_URL_HOST));
        return preg_replace('/^(?:www|m|amp|mobile)\./', '', $host);
    }
}

if (!function_exists('kop_news_image')) {
    /**
     * The picture for one article: array(src, kind => 'photo'|'logo') or
     * null when none has been found (yet). Reads the cached results only.
     */
    function kop_news_image($news_id, $article_url) {
        static $images = null, $logos = null, $base = null;
        if ($images === null) {
            $images = get_option('kop_news_images', array());
            $logos = get_option('kop_news_logos', array());
            $base = kop_news_images_dir()[1];
            if (!is_array($images)) $images = array();
            if (!is_array($logos)) $logos = array();
        }
        $hit = $images[(int) $news_id] ?? null;
        if (is_array($hit) && !empty($hit['f'])) {
            return array('src' => $base . '/' . $hit['f'], 'kind' => 'photo');
        }
        $host = kop_news_images_host($article_url);
        $logo = $host !== '' ? ($logos[$host] ?? null) : null;
        if (is_array($logo) && !empty($logo['f'])) {
            return array('src' => $base . '/logos/' . $logo['f'], 'kind' => 'logo');
        }
        return null;
    }
}

if (!function_exists('kop_news_images_absolute')) {
    function kop_news_images_absolute($candidate, $base_url) {
        $candidate = html_entity_decode(trim((string) $candidate), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if ($candidate === '' || stripos($candidate, 'data:') === 0) return '';
        if (strpos($candidate, '//') === 0) return 'https:' . $candidate;
        if (preg_match('#^https?://#i', $candidate)) return $candidate;
        $p = wp_parse_url($base_url);
        if (empty($p['host'])) return '';
        $root = ($p['scheme'] ?? 'https') . '://' . $p['host'];
        if (strpos($candidate, '/') === 0) return $root . $candidate;
        $dir = isset($p['path']) ? preg_replace('#/[^/]*$#', '/', $p['path']) : '/';
        return $root . $dir . $candidate;
    }
}

if (!function_exists('kop_news_images_parse')) {
    /**
     * What an article page says about its pictures: array('photo' => [urls],
     * 'logo' => [urls]), best first, absolute. Pure: no WordPress needed
     * beyond wp_parse_url.
     */
    function kop_news_images_parse($html, $base_url) {
        $photo = array();
        $logo = array();
        $html = (string) $html;
        // <meta property|name="og:image" content="..."> in either attribute order.
        if (preg_match_all('#<meta\b[^>]*>#i', $html, $metas)) {
            $want = array('og:image:secure_url' => 0, 'og:image' => 1, 'og:image:url' => 1, 'twitter:image' => 2, 'twitter:image:src' => 2, 'thumbnail' => 3, 'msapplication-tileimage' => 9);
            $found = array();
            foreach ($metas[0] as $tag) {
                if (!preg_match('#\b(?:property|name|itemprop)\s*=\s*["\']([^"\']+)["\']#i', $tag, $k)) continue;
                if (!preg_match('#\bcontent\s*=\s*["\']([^"\']*)["\']#i', $tag, $v)) continue;
                $key = strtolower(trim($k[1]));
                if (!isset($want[$key])) continue;
                $found[] = array($want[$key], $key, $v[1]);
            }
            usort($found, static function ($a, $b) { return $a[0] <=> $b[0]; });
            foreach ($found as $f) {
                $u = kop_news_images_absolute($f[2], $base_url);
                if ($u === '') continue;
                if ($f[1] === 'msapplication-tileimage') $logo[] = $u;
                else $photo[] = $u;
            }
        }
        if (preg_match('#<link\b[^>]*\brel\s*=\s*["\']image_src["\'][^>]*>#i', $html, $l) && preg_match('#\bhref\s*=\s*["\']([^"\']+)["\']#i', $l[0], $h)) {
            $photo[] = kop_news_images_absolute($h[1], $base_url);
        }
        // JSON-LD: the article's image, and its publisher's logo.
        if (preg_match_all('#<script[^>]+application/ld\+json[^>]*>(.*?)</script>#is', $html, $blocks)) {
            foreach ($blocks[1] as $json) {
                $data = json_decode(html_entity_decode(trim($json), ENT_NOQUOTES, 'UTF-8'), true);
                if (!is_array($data)) continue;
                $stack = array($data);
                $seen = 0;
                while ($stack && $seen++ < 200) {
                    $node = array_pop($stack);
                    if (!is_array($node)) continue;
                    foreach ($node as $k => $v) {
                        if ($k === 'logo') {
                            foreach (kop_news_images_ld_urls($v) as $u) $logo[] = kop_news_images_absolute($u, $base_url);
                        } elseif (($k === 'image' || $k === 'thumbnailUrl') && !isset($node['logo'])) {
                            foreach (kop_news_images_ld_urls($v) as $u) $photo[] = kop_news_images_absolute($u, $base_url);
                        } elseif (is_array($v)) {
                            $stack[] = $v;
                        }
                    }
                }
            }
        }
        // Icons: apple-touch-icon, then any icon declared 96px or larger.
        if (preg_match_all('#<link\b[^>]*>#i', $html, $links)) {
            $icons = array();
            foreach ($links[0] as $tag) {
                if (!preg_match('#\brel\s*=\s*["\']([^"\']+)["\']#i', $tag, $r)) continue;
                if (!preg_match('#\bhref\s*=\s*["\']([^"\']+)["\']#i', $tag, $h)) continue;
                $rel = strtolower($r[1]);
                $size = preg_match('#\bsizes\s*=\s*["\'](\d+)x\d+#i', $tag, $s) ? (int) $s[1] : 0;
                if (strpos($rel, 'apple-touch-icon') !== false) {
                    $icons[] = array(1000 + ($size ?: 180), $h[1]);
                } elseif (strpos($rel, 'icon') !== false && $size >= 96 && !preg_match('#\.svg(\?|$)#i', $h[1])) {
                    $icons[] = array($size, $h[1]);
                }
            }
            usort($icons, static function ($a, $b) { return $b[0] <=> $a[0]; });
            foreach ($icons as $icon) $logo[] = kop_news_images_absolute($icon[1], $base_url);
        }
        $clean = static function (array $urls) {
            $out = array();
            foreach ($urls as $u) {
                if ($u === '' || preg_match('#\.svg(\?|$)#i', $u) || in_array($u, $out, true)) continue;
                $out[] = $u;
            }
            return $out;
        };
        return array('photo' => $clean($photo), 'logo' => $clean($logo));
    }
}

if (!function_exists('kop_news_images_ld_urls')) {
    /** The URLs in a JSON-LD image/logo value: a string, an ImageObject or a list of either. */
    function kop_news_images_ld_urls($v) {
        if (is_string($v)) return array($v);
        if (!is_array($v)) return array();
        if (isset($v['url']) && is_string($v['url'])) return array($v['url']);
        if (isset($v['contentUrl']) && is_string($v['contentUrl'])) return array($v['contentUrl']);
        $out = array();
        foreach ($v as $item) {
            if (is_string($item) || is_array($item)) $out = array_merge($out, kop_news_images_ld_urls($item));
        }
        return $out;
    }
}

if (!function_exists('kop_news_images_get')) {
    /** GET with a browser's headers: array(code, body, final url). */
    function kop_news_images_get($url, $accept = 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8', $max = 3000000) {
        $res = wp_remote_get($url, array(
            'timeout'             => 15,
            'redirection'         => 5,
            'limit_response_size' => $max,
            'headers'             => array(
                'User-Agent'      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36',
                'Accept'          => $accept,
                'Accept-Language' => 'en-US,en;q=0.9',
            ),
        ));
        if (is_wp_error($res)) return array(0, '', $url);
        return array((int) wp_remote_retrieve_response_code($res), (string) wp_remote_retrieve_body($res), $url);
    }
}

if (!function_exists('kop_news_images_blocked')) {
    function kop_news_images_blocked($code, $html) {
        if ($code < 200 || $code >= 300 || trim($html) === '') return true;
        if (function_exists('kopLooksBotWalled') && kopLooksBotWalled($html, $code)) return true;
        $lower = strtolower(substr($html, 0, 6000));
        foreach (array('just a moment...', 'attention required!', 'verify you are human', 'checking your browser', 'are you a robot', 'complete the security check') as $needle) {
            if (strpos($lower, $needle) !== false) return true;
        }
        return false;
    }
}

if (!function_exists('kop_news_images_wayback')) {
    /** The page as the Wayback Machine has it (raw, id_), or ''. */
    function kop_news_images_wayback($url) {
        list($code, $body) = kop_news_images_get('https://archive.org/wayback/available?url=' . rawurlencode($url), 'application/json', 200000);
        $data = $code === 200 ? json_decode($body, true) : null;
        $snap = $data['archived_snapshots']['closest'] ?? null;
        if (!is_array($snap) || empty($snap['available']) || empty($snap['timestamp']) || empty($snap['url'])) return '';
        $raw = preg_replace('#/web/(\d{14})/#', '/web/$1id_/', (string) $snap['url'], 1);
        list($code, $html) = kop_news_images_get($raw);
        return kop_news_images_blocked($code, $html) ? '' : $html;
    }
}

if (!function_exists('kop_news_images_save')) {
    /**
     * Download an image and save a cut copy: photos 640x360 (cropped, at
     * least 300px wide to start with, so a tracking pixel or an icon is not
     * taken for the article's picture), logos fitted into 160x160 (at least
     * 48px). Returns the file name, or ''.
     */
    function kop_news_images_save($url, $dest_dir, $basename, $kind) {
        if (!preg_match('#^https?://#i', $url)) return '';
        list($code, $body) = kop_news_images_get($url, 'image/avif,image/webp,image/png,image/jpeg,image/*;q=0.8', 8000000);
        if ($code !== 200 || $body === '') return '';
        $info = @getimagesizefromstring($body);
        if (!$info || empty($info[0]) || empty($info[1])) return '';
        $min = $kind === 'photo' ? 300 : 48;
        if ($info[0] < $min || $info[1] < ($kind === 'photo' ? 150 : 48)) return '';
        if (!wp_mkdir_p($dest_dir)) return '';
        $tmp = tempnam(sys_get_temp_dir(), 'kopni');
        if (!$tmp || file_put_contents($tmp, $body) === false) return '';
        $editor = wp_get_image_editor($tmp);
        if (is_wp_error($editor)) {
            @unlink($tmp);
            return '';
        }
        if ($kind === 'photo') {
            $editor->resize(640, 360, true);
            $editor->set_quality(78);
            $file = $basename . '.jpg';
            $saved = $editor->save($dest_dir . '/' . $file, 'image/jpeg');
        } else {
            $editor->resize(160, 160, false);
            $file = $basename . '.png';
            $saved = $editor->save($dest_dir . '/' . $file, 'image/png');
        }
        @unlink($tmp);
        return is_wp_error($saved) ? '' : $file;
    }
}

if (!function_exists('kop_news_images_fetch_one')) {
    /**
     * Find and save the picture for one article. Returns what was stored:
     * array(photo file or '', logo file or '' for its host, note).
     */
    function kop_news_images_fetch_one($news_id, $article_url, array &$logos) {
        list($dir) = kop_news_images_dir();
        $host = kop_news_images_host($article_url);
        $note = '';
        list($code, $html) = kop_news_images_get($article_url);
        $base = $article_url;
        if (kop_news_images_blocked($code, $html)) {
            $note = 'live ' . $code;
            $html = kop_news_images_wayback($article_url);
            $note .= $html !== '' ? ', wayback' : ', no wayback';
        }
        $found = $html !== '' ? kop_news_images_parse($html, $base) : array('photo' => array(), 'logo' => array());
        $photo = '';
        foreach (array_slice($found['photo'], 0, 3) as $u) {
            $photo = kop_news_images_save($u, $dir, (string) (int) $news_id, 'photo');
            if ($photo !== '') break;
        }
        $logo = '';
        if ($host !== '' && empty($logos[$host]['f']) && (int) ($logos[$host]['t'] ?? 0) < time() - WEEK_IN_SECONDS) {
            $tries = array_slice($found['logo'], 0, 3);
            $tries[] = 'https://' . ($host) . '/apple-touch-icon.png';
            $tries[] = 'https://www.' . $host . '/apple-touch-icon.png';
            foreach (array_unique($tries) as $u) {
                $logo = kop_news_images_save($u, $dir . '/logos', sanitize_file_name($host), 'logo');
                if ($logo !== '') break;
            }
            $logos[$host] = array('f' => $logo, 't' => time());
        }
        return array($photo, $logo, $note);
    }
}

if (!function_exists('kop_news_images_pending')) {
    /** Approved articles still without a photo and due a (re)try, linked ones first: [[id, url], ...]. */
    function kop_news_images_pending($limit, array $only_ids = array()) {
        global $wpdb;
        $images = get_option('kop_news_images', array());
        if (!is_array($images)) $images = array();
        $where = "n.status IN ('approved','published') AND n.article_url LIKE 'http%'";
        if ($only_ids) $where .= ' AND n.id IN (' . implode(',', array_map('intval', $only_ids)) . ')';
        $suppress = $wpdb->suppress_errors(true);
        $rows = $wpdb->get_results(
            "SELECT n.id, n.article_url, (SELECT COUNT(*) FROM news_facility_links l WHERE l.news_id = n.id) AS linked
               FROM news_submissions n WHERE $where
              ORDER BY linked > 0 DESC, n.publication_date DESC, n.id DESC",
            ARRAY_A
        );
        $wpdb->suppress_errors($suppress);
        $out = array();
        foreach ((array) $rows as $r) {
            $have = $images[(int) $r['id']] ?? null;
            if (!$only_ids && is_array($have)) {
                if (!empty($have['f']) || (int) ($have['n'] ?? 0) >= 3 || (int) ($have['t'] ?? 0) > time() - WEEK_IN_SECONDS) continue;
            }
            $out[] = array((int) $r['id'], (string) $r['article_url']);
            if (count($out) >= $limit) break;
        }
        return $out;
    }
}

if (!function_exists('kop_news_images_run')) {
    /**
     * Work through up to $limit articles within $seconds. $log receives one
     * line per article. Returns array(tried, photos, logos).
     */
    function kop_news_images_run($limit = 20, $seconds = 240, $apply = true, array $only_ids = array(), $log = null) {
        $start = time();
        $images = get_option('kop_news_images', array());
        $logos = get_option('kop_news_logos', array());
        if (!is_array($images)) $images = array();
        if (!is_array($logos)) $logos = array();
        $tried = $photos = $new_logos = 0;
        foreach (kop_news_images_pending($limit, $only_ids) as $item) {
            if (time() - $start > $seconds) break;
            list($id, $url) = $item;
            list($photo, $logo, $note) = kop_news_images_fetch_one($id, $url, $logos);
            $tried++;
            if ($photo !== '') $photos++;
            if ($logo !== '') $new_logos++;
            $prev = $images[$id] ?? array();
            $images[$id] = array('f' => $photo, 't' => time(), 'n' => (int) ($prev['n'] ?? 0) + 1, 'e' => $note);
            if (is_callable($log)) {
                call_user_func($log, sprintf('#%d %s: %s%s%s', $id, kop_news_images_host($url), $photo !== '' ? 'photo' : 'no photo',
                    $logo !== '' ? ', logo' : '', $note !== '' ? ' (' . $note . ')' : ''));
            }
            if ($apply && $tried % 5 === 0) {
                update_option('kop_news_images', $images, false);
                update_option('kop_news_logos', $logos, false);
            }
        }
        if ($apply) {
            update_option('kop_news_images', $images, false);
            update_option('kop_news_logos', $logos, false);
        }
        return array($tried, $photos, $new_logos);
    }
}

if (function_exists('add_action')) {
    add_action('init', static function () {
        if (function_exists('wp_next_scheduled') && !wp_next_scheduled('kop_news_images_hourly')) {
            wp_schedule_event(time() + 900, 'hourly', 'kop_news_images_hourly');
        }
    });
    add_action('kop_news_images_hourly', static function () {
        if (get_transient('kop_news_images_lock')) return;
        set_transient('kop_news_images_lock', 1, 10 * MINUTE_IN_SECONDS);
        kop_news_images_run(25, 240, true);
        delete_transient('kop_news_images_lock');
    });
}
