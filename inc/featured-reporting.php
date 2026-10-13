<?php
/**
 * Essential reporting: news articles the owner picks out as especially good
 * journalism, each with an optional short note on why.
 *
 *   store     news_featured (records DB, beside news_submissions): one row per
 *             picked article, its note, who picked it and when. Only approved
 *             or published articles are ever shown. Every pick, note change and
 *             removal is logged in the kop_featured_log option with its before
 *             state, so KOP Tools > Essential Reporting can undo it exactly
 *   picking   the pencil on any article's headline (source 'feat:<news id>',
 *             news feed, facility and company pages, the page below) or
 *             KOP Tools > Essential Reporting (inc/featured-reporting-admin.php)
 *   readers   /essential-reporting/ (a route, templates/featured-reporting.php),
 *             a strip at the top of the news feed and on the home page
 *             ([kop_featured_reporting]), a flag and the note on the article
 *             wherever facility and company pages list it (picks first), and
 *             'featured' in the app's news payloads (inc/mobile-api.php).
 *             Picks are read at render time, never baked into the cached page
 *             data, so a pick shows at once
 *   byline    the article's author field as printed, left out when it is only
 *             the outlet's name. The internal journalist list stays private
 *
 * php -d extension=pdo_sqlite -d extension=mbstring scripts/test-featured-reporting.php
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('KOP_FEATURED_REWRITE_VERSION')) {
    define('KOP_FEATURED_REWRITE_VERSION', '1');
}

// ---------------------------------------------------------------------------
// Store
// ---------------------------------------------------------------------------

if (!function_exists('kop_feat_pdo')) {
    /** The records database (news_submissions lives there), or null. */
    function kop_feat_pdo() {
        if (function_exists('kop_seed_pdo')) {
            $pdo = kop_seed_pdo();
            if ($pdo instanceof PDO) return $pdo;
        }
        return isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO ? $GLOBALS['pdo'] : null;
    }
}

if (!function_exists('kop_feat_install')) {
    function kop_feat_install(PDO $pdo) {
        static $done = array();
        $key = spl_object_hash($pdo);
        if (isset($done[$key])) return true;
        $sqlite = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
        $pdo->exec($sqlite
            ? 'CREATE TABLE IF NOT EXISTS news_featured (news_id INTEGER PRIMARY KEY, note TEXT, featured_by TEXT, featured_at TEXT NOT NULL, updated_at TEXT NOT NULL)'
            : "CREATE TABLE IF NOT EXISTS news_featured (
                news_id int(11) NOT NULL,
                note text,
                featured_by varchar(190) DEFAULT '',
                featured_at datetime NOT NULL,
                updated_at datetime NOT NULL,
                PRIMARY KEY (news_id)
              ) DEFAULT CHARSET=utf8mb4 COMMENT='News articles picked as essential reporting (inc/featured-reporting.php)'");
        return $done[$key] = true;
    }
}

if (!function_exists('kop_feat_now')) {
    function kop_feat_now() {
        return gmdate('Y-m-d H:i:s');
    }
}

if (!function_exists('kop_feat_map')) {
    /**
     * news id => {note, featured_at, featured_by} for every pick whose article
     * is approved or published. One query a request; [] before the table exists.
     */
    function kop_feat_map($reset = false) {
        static $memo = null;
        if ($reset) { $memo = null; return array(); }
        if ($memo !== null) return $memo;
        $memo = array();
        $pdo = kop_feat_pdo();
        if (!$pdo) return $memo;
        try {
            $rows = $pdo->query("SELECT f.news_id, f.note, f.featured_at, f.featured_by
                                   FROM news_featured f JOIN news_submissions n ON n.id = f.news_id
                                  WHERE n.status IN ('approved','published')")->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            return $memo; // no table yet: nothing is picked
        }
        foreach ($rows as $r) {
            $memo[(int) $r['news_id']] = array(
                'note'        => trim((string) $r['note']),
                'featured_at' => (string) $r['featured_at'],
                'featured_by' => (string) $r['featured_by'],
            );
        }
        return $memo;
    }
}

if (!function_exists('kop_feat_get')) {
    /** {note, featured_at, featured_by} when the article is picked, else null. */
    function kop_feat_get($news_id) {
        $map = kop_feat_map();
        return isset($map[(int) $news_id]) ? $map[(int) $news_id] : null;
    }
}

if (!function_exists('kop_feat_rev')) {
    /** Moves on every change; the app's ETags carry it. */
    function kop_feat_rev() {
        return function_exists('get_option') ? (string) get_option('kop_featured_rev', '0') : '0';
    }
}

if (!function_exists('kop_feat_row')) {
    /** The stored pick of one article (whatever its status), or null. */
    function kop_feat_row(PDO $pdo, $news_id) {
        kop_feat_install($pdo);
        $st = $pdo->prepare('SELECT news_id, note, featured_by, featured_at, updated_at FROM news_featured WHERE news_id = ?');
        $st->execute(array((int) $news_id));
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }
}

if (!function_exists('kop_feat_article')) {
    /** The article's headline and status, or null when there is no such article. */
    function kop_feat_article(PDO $pdo, $news_id) {
        $st = $pdo->prepare('SELECT id, article_title, alternate_title, publication_name, status FROM news_submissions WHERE id = ?');
        $st->execute(array((int) $news_id));
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) return null;
        $row['title'] = trim((string) ($row['alternate_title'] ?: $row['article_title']));
        return $row;
    }
}

if (!function_exists('kop_feat_clean_note')) {
    /** Plain text: control characters out, runs of blank lines folded, 1,200 characters at most. */
    function kop_feat_clean_note($note) {
        $note = str_replace(array("\r\n", "\r"), "\n", (string) $note);
        $note = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $note);
        $note = preg_replace("/\n{3,}/", "\n\n", trim($note));
        if (function_exists('mb_strlen') && mb_strlen($note) > 1200) {
            throw new RuntimeException('The note is ' . mb_strlen($note) . ' characters; keep it under 1,200 (a sentence or two).');
        }
        return $note;
    }
}

if (!function_exists('kop_feat_write')) {
    /**
     * Puts one article's pick in the state $after (null = not picked, else
     * {note, featured_by, featured_at}), logs the change with what was there
     * before, and clears the caches. Returns the log entry.
     */
    function kop_feat_write(PDO $pdo, $news_id, $after, $who, $action) {
        $news_id = (int) $news_id;
        $before = kop_feat_row($pdo, $news_id);
        if ($after === null) {
            $pdo->prepare('DELETE FROM news_featured WHERE news_id = ?')->execute(array($news_id));
        } elseif ($before) {
            $pdo->prepare('UPDATE news_featured SET note = ?, featured_by = ?, featured_at = ?, updated_at = ? WHERE news_id = ?')
                ->execute(array($after['note'], $after['featured_by'], $after['featured_at'], kop_feat_now(), $news_id));
        } else {
            $pdo->prepare('INSERT INTO news_featured (news_id, note, featured_by, featured_at, updated_at) VALUES (?, ?, ?, ?, ?)')
                ->execute(array($news_id, $after['note'], $after['featured_by'], $after['featured_at'], kop_feat_now()));
        }
        $article = kop_feat_article($pdo, $news_id);
        $entry = array(
            'id'      => substr(md5(uniqid((string) $news_id, true)), 0, 12),
            'at'      => kop_feat_now(),
            'who'     => (string) $who,
            'action'  => (string) $action,
            'news_id' => $news_id,
            'title'   => $article ? $article['title'] : '',
            'before'  => $before ? array('note' => (string) $before['note'], 'featured_by' => (string) $before['featured_by'], 'featured_at' => (string) $before['featured_at']) : null,
            'after'   => $after,
            'undone'  => false,
        );
        $log = function_exists('get_option') ? get_option('kop_featured_log', array()) : array();
        $log = is_array($log) ? $log : array();
        array_unshift($log, $entry);
        if (function_exists('update_option')) {
            update_option('kop_featured_log', array_slice($log, 0, 100), false);
        }
        kop_feat_changed();
        return $entry;
    }
}

if (!function_exists('kop_feat_set')) {
    /** Picks an approved or published article, or changes its note. Returns the log entry. */
    function kop_feat_set(PDO $pdo, $news_id, $note, $who) {
        $article = kop_feat_article($pdo, $news_id);
        if (!$article) throw new RuntimeException('That article is gone.');
        if (!in_array($article['status'], array('approved', 'published'), true)) {
            throw new RuntimeException('Only an approved article can be featured; this one is ' . $article['status'] . '.');
        }
        $note = kop_feat_clean_note($note);
        $before = kop_feat_row($pdo, $news_id);
        if ($before && (string) $before['note'] === $note) return null; // nothing changed
        return kop_feat_write($pdo, $news_id, array(
            'note'        => $note,
            'featured_by' => $before ? (string) $before['featured_by'] : (string) $who,
            'featured_at' => $before ? (string) $before['featured_at'] : kop_feat_now(),
        ), $who, $before ? 'note' : 'feature');
    }
}

if (!function_exists('kop_feat_remove')) {
    /** Takes an article off; null when it was not picked. */
    function kop_feat_remove(PDO $pdo, $news_id, $who) {
        if (!kop_feat_row($pdo, $news_id)) return null;
        return kop_feat_write($pdo, $news_id, null, $who, 'remove');
    }
}

if (!function_exists('kop_feat_log')) {
    function kop_feat_log() {
        $log = function_exists('get_option') ? get_option('kop_featured_log', array()) : array();
        return is_array($log) ? $log : array();
    }
}

if (!function_exists('kop_feat_undo')) {
    /**
     * Puts the article back the way it was before log entry $entry_id. Refused
     * when the pick has changed since (a later entry for the same article that
     * is not undone): undo that one first.
     */
    function kop_feat_undo(PDO $pdo, $entry_id, $who) {
        $log = kop_feat_log();
        foreach ($log as $i => $e) {
            if ($e['id'] !== $entry_id) continue;
            if (!empty($e['undone'])) throw new RuntimeException('That change was already undone.');
            for ($j = 0; $j < $i; $j++) {
                if ((int) $log[$j]['news_id'] === (int) $e['news_id'] && empty($log[$j]['undone']) && $log[$j]['action'] !== 'undo') {
                    throw new RuntimeException('This article was changed again afterwards; undo the newer change first.');
                }
            }
            kop_feat_install($pdo);
            $back = $e['before'];
            $pdo->prepare('DELETE FROM news_featured WHERE news_id = ?')->execute(array((int) $e['news_id']));
            if ($back) {
                $pdo->prepare('INSERT INTO news_featured (news_id, note, featured_by, featured_at, updated_at) VALUES (?, ?, ?, ?, ?)')
                    ->execute(array((int) $e['news_id'], $back['note'], $back['featured_by'], $back['featured_at'], kop_feat_now()));
            }
            $log[$i]['undone'] = true;
            $log[$i]['undone_by'] = (string) $who;
            if (function_exists('update_option')) update_option('kop_featured_log', $log, false);
            kop_feat_changed();
            return $e;
        }
        throw new RuntimeException('That change is no longer in the list.');
    }
}

if (!function_exists('kop_feat_changed')) {
    /** A pick changed: new revision, fresh lookups, page caches emptied (picks are rare). */
    function kop_feat_changed() {
        if (function_exists('update_option')) update_option('kop_featured_rev', (string) time() . '.' . mt_rand(100, 999), false);
        kop_feat_map(true);
        if (function_exists('do_action')) do_action('litespeed_purge_all');
    }
}

// ---------------------------------------------------------------------------
// Articles for the readers
// ---------------------------------------------------------------------------

if (!function_exists('kop_feat_byline')) {
    /** "Jane Doe" from the author field; '' when empty or only the outlet's name. */
    function kop_feat_byline($author, $outlet) {
        $a = trim(preg_replace('/^by\s+/i', '', trim((string) $author)));
        if ($a === '') return '';
        $fold = static function ($s) { return preg_replace('/[^a-z0-9]+/', '', strtolower(preg_replace('/^the\s+/i', '', (string) $s))); };
        if ($fold($a) === $fold($outlet) || preg_match('/^(staff|staff reports?|wire|associated press|ap|reuters|news staff)$/i', $a)) return '';
        return $a;
    }
}

if (!function_exists('kop_feat_items')) {
    /**
     * Every picked article, ready to print: {id, title, url, archive, outlet,
     * byline, date, date_label, type, summary, note, featured_at, image,
     * facilities: [{name, url}]}. $order 'picked' = newest pick first (the
     * strips), 'published' = newest article first (the page). $limit 0 = all.
     */
    function kop_feat_items($order = 'published', $limit = 0) {
        static $memo = array();
        $map = kop_feat_map();
        if (!$map) return array();
        $key = $order . ':' . (int) $limit . ':' . md5(serialize($map));
        if (isset($memo[$key])) return $memo[$key];
        $pdo = kop_feat_pdo();
        if (!$pdo) return array();
        $theme = function_exists('get_stylesheet_directory') ? get_stylesheet_directory() : dirname(__DIR__);
        require_once $theme . '/api/lib-news-archive.php';
        kop_news_archive_ensure($pdo);
        $ids = implode(',', array_map('intval', array_keys($map)));
        $exclude = function_exists('kop_ischools_news_exclude_sql') ? kop_ischools_news_exclude_sql('n.id') : '';
        $rows = $pdo->query("SELECT n.id, n.article_title, n.alternate_title, n.author, n.publication_name, n.publication_date,
                                    n.article_url, n.archive_url, n.article_type, n.summary
                               FROM news_submissions n
                              WHERE n.id IN ($ids) AND n.status IN ('approved','published')$exclude")->fetchAll(PDO::FETCH_ASSOC);

        $facilities = array();
        try {
            $links = $pdo->query("SELECT l.news_id, f.id, f.name FROM news_facility_links l JOIN facilities_v2 f ON f.id = l.facility_id
                                   WHERE l.news_id IN ($ids) ORDER BY f.name")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($links as $l) {
                $url = function_exists('kop_facility_page_url') ? (string) kop_facility_page_url((int) $l['id']) : '';
                if ($url === '') continue; // only programs with a page are named
                $facilities[(int) $l['news_id']][$url] = array('name' => trim((string) $l['name']), 'url' => $url);
            }
        } catch (Throwable $e) {
            // no link table: the cards name no programs
        }

        $out = array();
        foreach ($rows as $r) {
            $id = (int) $r['id'];
            $links = kop_news_links((string) $r['article_url'], (string) ($r['archive_url'] ?? ''));
            $date = (string) $r['publication_date'];
            $out[] = array(
                'id'          => $id,
                'title'       => trim((string) ($r['alternate_title'] ?: $r['article_title'])),
                'url'         => $links['original'] !== '' ? $links['original'] : ($links['archive'] !== '' ? $links['archive'] : (string) $r['article_url']),
                'archive'     => $links['original'] !== '' ? $links['archive'] : '',
                'outlet'      => trim((string) $r['publication_name']),
                'byline'      => kop_feat_byline($r['author'], $r['publication_name']),
                'date'        => $date,
                'date_label'  => kop_feat_date_label($date),
                'type'        => trim((string) $r['article_type']),
                'summary'     => trim((string) $r['summary']),
                'note'        => $map[$id]['note'],
                'featured_at' => $map[$id]['featured_at'],
                'image'       => function_exists('kop_news_image') ? kop_news_image($id, (string) $r['article_url']) : null,
                'facilities'  => array_values($facilities[$id] ?? array()),
            );
        }
        usort($out, static function ($a, $b) use ($order) {
            $k = $order === 'picked' ? 'featured_at' : 'date';
            return strcmp((string) $b[$k], (string) $a[$k]) ?: ($b['id'] <=> $a['id']);
        });
        if ($limit > 0) $out = array_slice($out, 0, (int) $limit);
        return $memo[$key] = $out;
    }
}

if (!function_exists('kop_feat_date_label')) {
    function kop_feat_date_label($date) {
        $date = (string) $date;
        if ($date === '') return '';
        if (function_exists('kop_facility_pages_date_label')) return kop_facility_pages_date_label($date);
        $t = strtotime($date);
        return $t ? date('F j, Y', $t) : $date;
    }
}

if (!function_exists('kop_feat_first')) {
    /** A list of news items (each with 'id') with the picked ones moved to the front, order otherwise kept. */
    function kop_feat_first(array $items) {
        $map = kop_feat_map();
        if (!$map) return $items;
        $on = $off = array();
        foreach ($items as $n) {
            if (isset($map[(int) ($n['id'] ?? 0)])) $on[] = $n; else $off[] = $n;
        }
        return array_merge($on, $off);
    }
}

// ---------------------------------------------------------------------------
// Printing
// ---------------------------------------------------------------------------

if (!function_exists('kop_feat_attr')) {
    /** The admin's pencil for picking an article (or changing its note). */
    function kop_feat_attr($news_id) {
        return function_exists('kop_ie_attr') && (int) $news_id > 0 ? kop_ie_attr('feat:' . (int) $news_id, 'essential reporting: pick this article, or change its note') : '';
    }
}

if (!function_exists('kop_feat_label')) {
    function kop_feat_label() {
        return 'Essential reporting';
    }
}

if (!function_exists('kop_feat_note_html')) {
    /** The note as paragraphs of plain text. */
    function kop_feat_note_html($note) {
        $out = '';
        foreach (preg_split("/\n\s*\n/", trim((string) $note)) as $para) {
            $para = trim($para);
            if ($para !== '') $out .= '<p>' . nl2br(esc_html($para)) . '</p>';
        }
        return $out;
    }
}

if (!function_exists('kop_feat_flag_html')) {
    /**
     * The flag and note printed inside an article's card wherever it is listed
     * (news feed, facility and company pages); '' when the article is not picked.
     */
    function kop_feat_flag_html($news_id) {
        $f = kop_feat_get($news_id);
        if (!$f) return '';
        kop_feat_enqueue_style();   // any page that lists a pick, whatever its template
        $icon = function_exists('kop_icon') ? kop_icon('star', array('class' => 'kop-feat-flag__icon')) : '';
        $page = kop_feat_url();
        $html = '<div class="kop-feat-mark">'
            . '<p class="kop-feat-flag">' . $icon . '<a href="' . esc_url($page) . '">' . esc_html(kop_feat_label()) . '</a></p>';
        if ($f['note'] !== '') $html .= '<div class="kop-feat-note">' . kop_feat_note_html($f['note']) . '</div>';
        return $html . '</div>';
    }
}

if (!function_exists('kop_feat_star_html')) {
    /**
     * The star beside a picked article's headline on its card (news feed); '' when
     * the article is not picked. The banner with the note is kop_feat_flag_html().
     */
    function kop_feat_star_html($news_id) {
        if (!kop_feat_get($news_id) || !function_exists('kop_icon')) return '';
        kop_feat_enqueue_style();
        return '<span class="kop-feat-star" title="' . esc_attr(kop_feat_label()) . '">'
            . kop_icon('star', array('label' => kop_feat_label())) . '</span>';
    }
}

if (!function_exists('kop_feat_card_html')) {
    /**
     * One picked article as a card: picture, outlet and date, headline, byline,
     * the note, the programs it is about. $h = the headline's tag.
     */
    function kop_feat_card_html(array $it, $h = 'h3') {
        $has_url = $it['url'] !== '' && preg_match('#^https?://#i', $it['url']);
        $img = $it['image'] ?? null;
        $kind = is_array($img) ? (string) $img['kind'] : 'none';
        $outlet = $it['outlet'] !== '' ? $it['outlet'] : ($has_url ? (string) preg_replace('/^www\./', '', (string) parse_url($it['url'], PHP_URL_HOST)) : '');
        $initial = $outlet !== '' ? strtoupper(mb_substr(preg_replace('/^(?:The|A)\s+/i', '', $outlet), 0, 1)) : '';
        $h = in_array($h, array('h2', 'h3', 'h4'), true) ? $h : 'h3';

        $html = '<article class="kop-feat-card">';
        $thumb = is_array($img)
            ? '<img src="' . esc_url($img['src']) . '" alt="" loading="lazy" decoding="async"' . ($kind === 'photo' ? ' width="640" height="360"' : ' width="160" height="160"') . '>'
            : '<span class="kop-feat-card__initial">' . esc_html($initial) . '</span>';
        $html .= $has_url
            ? '<a class="kop-feat-card__thumb kop-feat-card__thumb--' . esc_attr($kind) . '" href="' . esc_url($it['url']) . '" target="_blank" rel="noopener" tabindex="-1" aria-hidden="true">' . $thumb . '</a>'
            : '<span class="kop-feat-card__thumb kop-feat-card__thumb--' . esc_attr($kind) . '" aria-hidden="true">' . $thumb . '</span>';
        $html .= '<div class="kop-feat-card__body">';
        $meta = array();
        if (function_exists('kop_icon')) $meta[] = '<span class="kop-feat-star">' . kop_icon('star', array('label' => kop_feat_label())) . '</span>';
        if ($outlet !== '') $meta[] = '<span class="kop-feat-card__outlet">' . esc_html($outlet) . '</span>';
        if ($it['date_label'] !== '') $meta[] = '<span>' . esc_html($it['date_label']) . '</span>';
        if ($meta) $html .= '<p class="kop-feat-card__meta">' . implode('', $meta) . '</p>';
        $html .= '<' . $h . ' class="kop-feat-card__title"' . kop_feat_attr($it['id']) . '>';
        if ($has_url) {
            $html .= '<a href="' . esc_url($it['url']) . '" target="_blank" rel="noopener">' . esc_html($it['title']) . '</a>';
            if ($it['archive'] !== '') $html .= ' <a class="kop-archived-link" href="' . esc_url($it['archive']) . '" target="_blank" rel="noopener noreferrer">archived copy</a>';
        } else {
            $html .= esc_html($it['title']);
        }
        $html .= '</' . $h . '>';
        if ($it['byline'] !== '') $html .= '<p class="kop-feat-card__byline">By ' . esc_html($it['byline']) . '</p>';
        if ($it['note'] !== '') {
            $html .= '<div class="kop-feat-note">' . kop_feat_note_html($it['note']) . '</div>';
        } elseif ($it['summary'] !== '') {
            $html .= '<p class="kop-feat-card__summary">' . esc_html($it['summary']) . '</p>';
        }
        if ($it['facilities']) {
            $names = array();
            foreach ($it['facilities'] as $f) $names[] = '<a href="' . esc_url($f['url']) . '">' . esc_html($f['name']) . '</a>';
            $html .= '<p class="kop-feat-card__about">About ' . implode(', ', $names) . '</p>';
        }
        return $html . '</div></article>';
    }
}

if (!function_exists('kop_feat_strip_html')) {
    /**
     * The newest picks as a short strip (top of the news feed, home page):
     * heading, a line, up to $limit cards and a link to the whole list. '' when
     * nothing is picked.
     */
    function kop_feat_strip_html($args = array()) {
        $args = array_merge(array('limit' => 3, 'heading' => kop_feat_label(), 'text_key' => 'feat:strip'), (array) $args);
        $items = kop_feat_items('picked', max(1, min(12, (int) $args['limit'])));
        if (!$items) return '';
        kop_feat_enqueue_style();
        $total = count(kop_feat_map());
        $tk = (string) $args['text_key'];
        $heading = function_exists('kop_text') ? kop_text($tk . '-heading', (string) $args['heading'], 'Essential reporting strip: heading') : (string) $args['heading'];
        $line_default = 'Reporting on the troubled teen industry we think everyone should read.';
        $line = function_exists('kop_text') ? kop_text($tk . '-line', $line_default, 'Essential reporting strip: line under the heading') : $line_default;
        $html = '<section class="kop-feat-strip" aria-labelledby="kop-feat-strip-' . esc_attr(sanitize_title($tk)) . '" data-kop-bug-feature="essential-reporting/strip" data-kop-bug-label="Essential reporting">';
        $html .= '<div class="kop-feat-strip__head">';
        $html .= '<h2 id="kop-feat-strip-' . esc_attr(sanitize_title($tk)) . '"' . (function_exists('kop_text_attr') ? kop_text_attr($tk . '-heading', 'Essential reporting strip: heading') : '') . '>' . esc_html($heading) . '</h2>';
        $html .= '<p' . (function_exists('kop_text_attr') ? kop_text_attr($tk . '-line', 'Essential reporting strip: line under the heading') : '') . '>' . esc_html($line) . '</p>';
        $html .= '</div><div class="kop-feat-strip__grid">';
        foreach ($items as $it) $html .= kop_feat_card_html($it, 'h3');
        $html .= '</div>';
        $more = $total > count($items) ? 'All ' . $total . ' picks' : 'Why we picked these';
        $html .= '<p class="kop-feat-strip__more"><a href="' . esc_url(kop_feat_url()) . '">' . esc_html($more) . '</a></p>';
        return $html . '</section>';
    }
}

if (!function_exists('kop_feat_shortcode')) {
    /** [kop_featured_reporting limit="3" heading="Essential reporting"] */
    function kop_feat_shortcode($atts) {
        $atts = shortcode_atts(array('limit' => 3, 'heading' => kop_feat_label()), $atts);
        return kop_feat_strip_html(array('limit' => (int) $atts['limit'], 'heading' => (string) $atts['heading'], 'text_key' => 'feat:shortcode'));
    }
    if (function_exists('add_shortcode')) add_shortcode('kop_featured_reporting', 'kop_feat_shortcode');
}

if (!function_exists('kop_feat_enqueue_style')) {
    /** css/featured-reporting.css (also called late from a strip, which WordPress prints in the footer). */
    function kop_feat_enqueue_style() {
        if (!function_exists('wp_enqueue_style')) return;
        $css = get_stylesheet_directory() . '/css/featured-reporting.css';
        if (file_exists($css)) {
            wp_enqueue_style('kop-featured-reporting', get_stylesheet_directory_uri() . '/css/featured-reporting.css', array('kop-colors'), filemtime($css));
        }
    }
}

if (!function_exists('kop_feat_enqueue')) {
    /** Every page that can print a pick: this route, the news feed, the home page, facility and company pages. */
    function kop_feat_enqueue() {
        $on = kop_feat_is_page()
            || (function_exists('is_front_page') && is_front_page())
            || (function_exists('is_page_template') && is_page_template('templates/page-news-feed.php'))
            || (function_exists('kop_facility_pages_is_page') && kop_facility_pages_is_page())
            || (function_exists('kop_operator_pages_is_page') && kop_operator_pages_is_page());
        if ($on) kop_feat_enqueue_style();
        // The page sits on the site map's panel.
        $sm = get_stylesheet_directory() . '/css/site-map.css';
        if (kop_feat_is_page() && file_exists($sm)) {
            wp_enqueue_style('kop-site-map', get_stylesheet_directory_uri() . '/css/site-map.css', array('kop-colors'), filemtime($sm));
        }
    }
    if (function_exists('add_action')) add_action('wp_enqueue_scripts', 'kop_feat_enqueue', 25);
}

// ---------------------------------------------------------------------------
// The pencil: 'feat:<news id>'
// ---------------------------------------------------------------------------

if (!function_exists('kop_feat_ie_load')) {
    function kop_feat_ie_load(array $p) {
        $pdo = kop_feat_pdo();
        if (!$pdo) throw new RuntimeException('No database connection.');
        $id = (int) ($p[0] ?? 0);
        $article = kop_feat_article($pdo, $id);
        if (!$article) throw new RuntimeException('That article is gone.');
        $row = kop_feat_row($pdo, $id);
        return array(
            'title'  => kop_feat_label() . ': ' . $article['title'],
            'help'   => 'Picked articles are listed on the Essential Reporting page, at the top of the news feed and on the home page, and flagged with the note wherever the article is listed. Every pick can be undone under KOP Tools > Essential Reporting.',
            'fields' => array(
                kop_ie_field('featured', 'Feature this article as essential reporting', 'bool', (bool) $row),
                kop_ie_field('note', 'Why it matters (optional)', 'textarea', $row ? (string) $row['note'] : '', array(
                    'rows' => 4,
                    'help' => 'A sentence or two: what it uncovered, or what changed after it ran. Plain text; a blank line starts a new paragraph.',
                )),
            ),
        );
    }
}

if (!function_exists('kop_feat_ie_save')) {
    function kop_feat_ie_save(array $p, array $v) {
        $pdo = kop_feat_pdo();
        if (!$pdo) throw new RuntimeException('No database connection.');
        $id = (int) ($p[0] ?? 0);
        $who = function_exists('kop_ie_who') ? kop_ie_who() : '';
        $f = $v['featured'] ?? false;
        $on = $f === true || $f === 'true' || $f === 1 || $f === '1';
        if ($on) {
            $e = kop_feat_set($pdo, $id, (string) ($v['note'] ?? ''), $who);
            return array('message' => $e ? ($e['action'] === 'feature' ? 'Featured. It is on the Essential Reporting page now.' : 'Note saved.') : 'No change.');
        }
        $e = kop_feat_remove($pdo, $id, $who);
        return array('message' => $e ? 'Taken off the essential reporting list.' : 'No change.');
    }
}

if (function_exists('add_filter')) {
    add_filter('kop_inline_edit_sources', static function ($sources) {
        $sources['feat'] = array('load' => 'kop_feat_ie_load', 'save' => 'kop_feat_ie_save');
        return $sources;
    });
}

// ---------------------------------------------------------------------------
// The page: /essential-reporting/ (a route, like /how-to-use-this-site/)
// ---------------------------------------------------------------------------

if (!function_exists('kop_feat_base')) {
    function kop_feat_base() {
        return function_exists('apply_filters') ? apply_filters('kop_featured_base', 'essential-reporting') : 'essential-reporting';
    }
}

if (!function_exists('kop_feat_url')) {
    function kop_feat_url() {
        return home_url('/' . kop_feat_base() . '/');
    }
}

if (!function_exists('kop_feat_register_rewrite')) {
    function kop_feat_register_rewrite() {
        add_rewrite_rule('^' . preg_quote(kop_feat_base(), '#') . '/?$', 'index.php?kop_featured=1', 'top');
        if (get_option('kop_featured_rewrite') !== KOP_FEATURED_REWRITE_VERSION) {
            flush_rewrite_rules(false);
            update_option('kop_featured_rewrite', KOP_FEATURED_REWRITE_VERSION);
        }
    }
    if (function_exists('add_action')) add_action('init', 'kop_feat_register_rewrite', 30);
}

if (!function_exists('kop_feat_query_vars')) {
    function kop_feat_query_vars($vars) {
        $vars[] = 'kop_featured';
        return $vars;
    }
    if (function_exists('add_filter')) add_filter('query_vars', 'kop_feat_query_vars');
}

if (!function_exists('kop_feat_is_request')) {
    function kop_feat_is_request($query = null) {
        if ($query === null) {
            return function_exists('get_query_var') && (string) get_query_var('kop_featured') === '1';
        }
        return (string) $query->get('kop_featured') === '1';
    }
}

if (!function_exists('kop_feat_is_page')) {
    /** True while /essential-reporting/ is being rendered. */
    function kop_feat_is_page() {
        return isset($GLOBALS['kop_featured_page']) && is_array($GLOBALS['kop_featured_page']);
    }
}

if (function_exists('add_action')) {
    add_action('pre_get_posts', static function ($query) {
        if (is_admin() || !$query->is_main_query() || !kop_feat_is_request($query)) return;
        $query->is_home = false;
        $query->is_posts_page = false;
        $query->set('posts_per_page', 1);
        $query->set('no_found_rows', true);
    });
    add_filter('posts_pre_query', static function ($posts, $query) {
        if (is_admin() || !$query->is_main_query() || !kop_feat_is_request($query)) return $posts;
        return array();
    }, 10, 2);
    add_filter('pre_handle_404', static function ($preempt, $query) {
        return kop_feat_is_request($query) ? true : $preempt;
    }, 10, 2);
    add_action('template_redirect', static function () {
        if (!kop_feat_is_request()) return;
        $GLOBALS['kop_featured_page'] = kop_feat_page_data();
        status_header(200);
    }, 0);
    add_filter('template_include', static function ($template) {
        if (!kop_feat_is_page()) return $template;
        $own = get_stylesheet_directory() . '/templates/featured-reporting.php';
        return file_exists($own) ? $own : $template;
    }, 99);
    add_filter('body_class', static function ($classes) {
        if (kop_feat_is_page()) $classes[] = 'kop-featured-reporting-page';
        return $classes;
    });
    add_filter('kop_redirect_map', static function ($map) {
        foreach (array('featured-reporting', 'recommended-reading', 'best-reporting') as $alias) {
            if (!isset($map[$alias])) $map[$alias] = '/' . kop_feat_base() . '/';
        }
        return $map;
    });
}

if (!function_exists('kop_feat_summary')) {
    function kop_feat_summary() {
        return 'Reporting on the troubled teen industry that Kids Over Profits recommends: investigations and accounts that uncovered abuse, named the companies behind it or changed what happened next.';
    }
}

if (function_exists('add_filter')) {
    $kop_feat_title = static function ($title) {
        return kop_feat_is_page() ? kop_feat_label() . ' | ' . get_bloginfo('name') : $title;
    };
    add_filter('pre_get_document_title', $kop_feat_title, 20);
    add_filter('wpseo_title', $kop_feat_title, 20);
    add_filter('wpseo_opengraph_title', $kop_feat_title, 20);
    $kop_feat_desc = static function ($desc) {
        return kop_feat_is_page() ? kop_feat_summary() : $desc;
    };
    add_filter('wpseo_metadesc', $kop_feat_desc, 20);
    add_filter('wpseo_opengraph_desc', $kop_feat_desc, 20);
    $kop_feat_canonical = static function ($url) {
        return kop_feat_is_page() ? kop_feat_url() : $url;
    };
    add_filter('wpseo_canonical', $kop_feat_canonical, 20);
    add_filter('wpseo_opengraph_url', $kop_feat_canonical, 20);
    add_filter('wpseo_robots', static function ($robots) {
        return kop_feat_is_page() ? 'index, follow' : $robots;
    }, 20);
}

if (!function_exists('kop_feat_page_data')) {
    /** {items (newest article first), years: [year => count], news_url}. */
    function kop_feat_page_data() {
        $items = kop_feat_items('published');
        $years = array();
        foreach ($items as $it) {
            $y = substr((string) $it['date'], 0, 4);
            $y = preg_match('/^\d{4}$/', $y) ? $y : 'Undated';
            $years[$y] = ($years[$y] ?? 0) + 1;
        }
        $news_url = function_exists('kop_asl_page_url_by_template') ? (string) kop_asl_page_url_by_template('page-news-feed.php') : '';
        return array('items' => $items, 'years' => $years, 'news_url' => $news_url !== '' ? $news_url : home_url('/tti-news-feed/'));
    }
}
