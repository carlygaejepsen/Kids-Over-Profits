<?php
/**
 * An article's two links: the real one (news_submissions.article_url) and an
 * archived copy (news_submissions.archive_url: Wayback Machine, archive.today,
 * Ghostarchive, perma.cc). js/news-archive-links.js has the same rules for
 * lists drawn in the browser; scripts/test-news-archive.php checks they agree.
 *
 * A Wayback link given as the article's address is split: the address inside
 * it becomes article_url, the Wayback link archive_url. An archive.today short
 * link (archive.ph/AbCd) hides the address, so it stays the only link.
 */

if (!function_exists('kop_news_is_archive_url')) {
    /** True for a link to a web archive's copy of a page. */
    function kop_news_is_archive_url($url) {
        $host = strtolower((string) parse_url(trim((string) $url), PHP_URL_HOST));
        $host = preg_replace('/^www\./', '', $host);
        if ($host === '') return false;
        if ($host === 'web.archive.org' || $host === 'wayback.archive.org') return true;
        if ($host === 'archive.org') return (bool) preg_match('#^/web/#', (string) parse_url($url, PHP_URL_PATH));
        return in_array($host, array(
            'archive.today', 'archive.ph', 'archive.is', 'archive.li', 'archive.vn', 'archive.md', 'archive.fo',
            'ghostarchive.org', 'perma.cc', 'webcitation.org', 'archive.org.au', 'webarchive.org.uk',
        ), true) || preg_match('/(^|\.)webarchive\.(nla\.gov\.au|loc\.gov)$/', $host);
    }
}

if (!function_exists('kop_news_unwrap_archive_url')) {
    /**
     * The page a Wayback link is a copy of ('' when it names none):
     * https://web.archive.org/web/20230926152235/https://www.tampabay.com/x -> https://www.tampabay.com/x
     */
    function kop_news_unwrap_archive_url($url) {
        $url = trim((string) $url);
        if (!preg_match('#^https?://(?:www\.)?(?:web\.|wayback\.)?archive\.org/web/[0-9*]{1,16}[a-z_]*/(.+)$#i', $url, $m)) return '';
        $inner = $m[1];
        if (preg_match('#^(https?):/+(.*)$#i', $inner, $s)) {
            $inner = strtolower($s[1]) . '://' . $s[2];
        } elseif (preg_match('#^[a-z0-9-]+(\.[a-z0-9-]+)+(/|$)#i', $inner)) {
            $inner = 'http://' . $inner;
        } else {
            return '';
        }
        return filter_var($inner, FILTER_VALIDATE_URL) ? $inner : '';
    }
}

if (!function_exists('kop_news_split_urls')) {
    /**
     * What to store for an article given the address box and the archive box:
     * ['url' => article_url, 'archive' => archive_url ('' = none)].
     */
    function kop_news_split_urls($url, $archive = '') {
        $url = trim((string) $url);
        $archive = trim((string) $archive);
        if ($url !== '' && kop_news_is_archive_url($url)) {
            if ($archive === '') $archive = $url;
            $inner = kop_news_unwrap_archive_url($url);
            if ($inner !== '') $url = $inner;
        }
        // The archive box holding the original (pasted in the wrong box) adds nothing.
        if ($archive !== '' && !kop_news_is_archive_url($archive)) {
            if ($url === '') $url = $archive;
            $archive = '';
        }
        if ($url === '' && $archive !== '') {
            $url = kop_news_unwrap_archive_url($archive) ?: $archive;
        }
        return array('url' => $url, 'archive' => $archive);
    }
}

if (!function_exists('kop_news_links')) {
    /**
     * The two links a page prints for an article:
     * ['original' => the real article ('' when only an archive copy is known), 'archive' => archived copy or ''].
     * Link the title to original ?: archive, and add "archived copy" when both are set.
     */
    function kop_news_links($article_url, $archive_url = '') {
        $article_url = trim((string) $article_url);
        $archive_url = trim((string) $archive_url);
        $original = '';
        if ($article_url !== '') {
            if (kop_news_is_archive_url($article_url)) {
                if ($archive_url === '') $archive_url = $article_url;
                $original = kop_news_unwrap_archive_url($article_url);
            } else {
                $original = $article_url;
            }
        }
        if ($archive_url !== '' && !preg_match('#^https?://#i', $archive_url)) $archive_url = '';
        if ($original === '' && $archive_url !== '') $original = kop_news_unwrap_archive_url($archive_url);
        if ($original !== '' && !preg_match('#^https?://#i', $original)) $original = '';
        return array('original' => $original, 'archive' => $archive_url);
    }
}

if (!function_exists('kop_news_archive_link_html')) {
    /**
     * " <a class="kop-archived-link">archived copy</a>" after an article title, or '' when
     * the title already points at the only link.
     */
    function kop_news_archive_link_html($article_url, $archive_url = '', $label = 'archived copy') {
        $l = kop_news_links($article_url, $archive_url);
        if ($l['archive'] === '' || $l['original'] === '') return '';
        return ' <a class="kop-archived-link" href="' . htmlspecialchars($l['archive'], ENT_QUOTES) . '" target="_blank" rel="noopener noreferrer">'
            . htmlspecialchars($label, ENT_QUOTES) . '</a>';
    }
}

if (!function_exists('kop_news_archive_ensure')) {
    /**
     * news_submissions.archive_url exists (MySQL on the site, SQLite in the
     * offline tests). Cheap after the first check of a request.
     */
    function kop_news_archive_ensure(PDO $pdo) {
        static $done = array();
        $key = spl_object_hash($pdo);
        if (isset($done[$key])) return true;
        try {
            $pdo->query('SELECT archive_url FROM news_submissions WHERE 1 = 0');
            return $done[$key] = true;
        } catch (Throwable $e) {
            // missing: add it below
        }
        try {
            $sqlite = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
            $pdo->exec($sqlite
                ? 'ALTER TABLE news_submissions ADD COLUMN archive_url TEXT'
                : "ALTER TABLE news_submissions ADD COLUMN archive_url varchar(2048) DEFAULT NULL COMMENT 'Archived copy of the article (Wayback, archive.today); article_url is the real link' AFTER article_url");
            return $done[$key] = true;
        } catch (Throwable $e) {
            error_log('kop news archive: could not add archive_url: ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('kop_news_archive_split_existing')) {
    /**
     * One pass over the rows whose only link is an archive copy: archive_url
     * gets the copy, article_url the page inside a Wayback link (json_data.url
     * and json_data.archiveUrl follow). Returns [checked, changed]. $apply false = count only.
     */
    function kop_news_archive_split_existing(PDO $pdo, $apply = true) {
        kop_news_archive_ensure($pdo);
        $rows = $pdo->query("SELECT id, article_url, archive_url, json_data FROM news_submissions
            WHERE (archive_url IS NULL OR archive_url = '')
              AND (article_url LIKE '%archive.org/web/%' OR article_url LIKE '%archive.ph/%' OR article_url LIKE '%archive.is/%'
                   OR article_url LIKE '%archive.today/%' OR article_url LIKE '%archive.li/%' OR article_url LIKE '%archive.md/%'
                   OR article_url LIKE '%archive.vn/%' OR article_url LIKE '%ghostarchive.org/%' OR article_url LIKE '%perma.cc/%')")->fetchAll(PDO::FETCH_ASSOC);
        $upd = $pdo->prepare('UPDATE news_submissions SET article_url = ?, archive_url = ?, json_data = ? WHERE id = ?');
        $changed = array();
        foreach ($rows as $r) {
            if (!kop_news_is_archive_url($r['article_url'])) continue;
            $s = kop_news_split_urls($r['article_url'], '');
            $json = json_decode((string) $r['json_data'], true);
            if (is_array($json)) {
                $json['url'] = $s['url'];
                $json['archiveUrl'] = $s['archive'];
                $jsonText = json_encode($json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            } else {
                $jsonText = $r['json_data'];
            }
            $changed[] = array('id' => (int) $r['id'], 'from' => $r['article_url'], 'url' => $s['url'], 'archive' => $s['archive']);
            if ($apply) $upd->execute(array($s['url'], $s['archive'], $jsonText, (int) $r['id']));
        }
        return array('checked' => count($rows), 'changed' => $changed);
    }
}
