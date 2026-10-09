<?php
/**
 * Indigenous residential schools: records of the Indian boarding schools,
 * residential schools and Indian mission schools that news articles and
 * documents keep bringing to Kids Over Profits, kept apart from the troubled
 * teen industry's facility data.
 *
 * They are not TTI facilities, so they are not in facilities_v2 and appear on
 * no facility page, directory, hub, map, search or open-data download. They
 * are listed on /indian-boarding-schools/ (templates/page-indian-boarding-schools.php),
 * each with the articles and links filed under it, and managed at
 * KOP Data Tools > Indigenous Schools (inc/indigenous-schools-admin.php).
 *
 * Tables, in the records database (kop_seed_pdo(), where news_submissions is):
 *   indigenous_schools      one row per school; review = 'approved' (listed)
 *                           or 'pending' (made by the news scan, waiting)
 *   indigenous_school_news  which articles are about which school;
 *                           school_id 0 = about the schools in general
 *
 * The hourly news scan (inc/facility-discovery.php) files a school name it
 * finds as a pending record here instead of creating a facility, and links
 * the article. kop_ischools_move_facility() moves a record that was filed as
 * a facility, with its article links, out of the facility tables; the admin
 * screen does it only when an admin presses "Move here".
 *
 * Notes are written in the page-text format (inc/page-text.php): plain text,
 * escaped on output, with **bold**, *italic* and [links](https://...).
 */

if (!defined('ABSPATH')) {
    exit;
}

define('KOP_ISCHOOLS_DB_VERSION', '1');
define('KOP_ISCHOOLS_PAGE', 'indian-boarding-schools');

function kop_ischools_pdo() {
    return function_exists('kop_seed_pdo') ? kop_seed_pdo() : null;
}

/** The two tables, created once per version (MySQL). */
function kop_ischools_install(PDO $pdo) {
    if (get_option('kop_ischools_db') === KOP_ISCHOOLS_DB_VERSION) {
        return;
    }
    $pdo->exec("CREATE TABLE IF NOT EXISTS indigenous_schools (
        id INT NOT NULL AUTO_INCREMENT,
        name VARCHAR(255) NOT NULL,
        name_key VARCHAR(191) NOT NULL,
        other_names TEXT NULL,
        country VARCHAR(100) NOT NULL DEFAULT '',
        region VARCHAR(100) NOT NULL DEFAULT '',
        city VARCHAR(100) NOT NULL DEFAULT '',
        nations TEXT NULL,
        run_by VARCHAR(255) NOT NULL DEFAULT '',
        opened SMALLINT NULL,
        closed SMALLINT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'Unknown',
        notes TEXT NULL,
        links TEXT NULL,
        review VARCHAR(20) NOT NULL DEFAULT 'approved',
        source VARCHAR(255) NOT NULL DEFAULT '',
        created_by VARCHAR(100) NOT NULL DEFAULT '',
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY name_key (name_key),
        KEY review (review)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS indigenous_school_news (
        school_id INT NOT NULL,
        news_id INT NOT NULL,
        created_by VARCHAR(100) NOT NULL DEFAULT '',
        created_at DATETIME NOT NULL,
        PRIMARY KEY (school_id, news_id),
        KEY news (news_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    update_option('kop_ischools_db', KOP_ISCHOOLS_DB_VERSION);
}

/** Whether the tables are there (the page renders nothing before they are). */
function kop_ischools_ready(PDO $pdo) {
    try {
        $pdo->query('SELECT 1 FROM indigenous_schools LIMIT 1');
        return true;
    } catch (PDOException $e) {
        return false;
    }
}

function kop_ischools_now() {
    return gmdate('Y-m-d H:i:s');
}

/** A name as stored: one line, curly and broken apostrophes made plain. */
function kop_ischools_clean_name($name) {
    $name = str_replace(array("\u{2019}", "\u{2018}", "\u{FFFD}"), "'", (string) $name);
    return trim(preg_replace('/\s+/', ' ', $name));
}

/** The comparison form of a name: lower case, letters and digits only. */
function kop_ischools_key($name) {
    $name = strtolower(str_replace("'", '', kop_ischools_clean_name($name)));
    return trim(preg_replace('/[^a-z0-9]+/', ' ', $name));
}

/** Lines of a text field, trimmed, blanks dropped. */
function kop_ischools_lines($text) {
    $out = array();
    foreach (preg_split('/\r\n|\r|\n/', (string) $text) as $line) {
        $line = trim($line);
        if ($line !== '') {
            $out[] = $line;
        }
    }
    return $out;
}

/** "Label | https://..." lines as array(label, url), bad addresses dropped. */
function kop_ischools_parse_links($text) {
    $out = array();
    foreach (kop_ischools_lines($text) as $line) {
        $parts = array_map('trim', explode('|', $line, 2));
        $url = count($parts) === 2 ? $parts[1] : $parts[0];
        $label = count($parts) === 2 ? $parts[0] : '';
        if (!preg_match('#^https?://[^\s<>"\']+$#i', $url)) {
            continue;
        }
        $out[] = array($label !== '' ? $label : preg_replace('#^https?://(www\.)?#i', '', $url), $url);
    }
    return $out;
}

/* ---- Reading --------------------------------------------------------- */

/** Every school, by name. $review: 'approved', 'pending' or null for all. */
function kop_ischools_all(PDO $pdo, $review = 'approved') {
    if (!kop_ischools_ready($pdo)) {
        return array();
    }
    if ($review === null) {
        $stmt = $pdo->query('SELECT * FROM indigenous_schools ORDER BY name');
    } else {
        $stmt = $pdo->prepare('SELECT * FROM indigenous_schools WHERE review = ? ORDER BY name');
        $stmt->execute(array($review));
    }
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function kop_ischools_get(PDO $pdo, $id) {
    $stmt = $pdo->prepare('SELECT * FROM indigenous_schools WHERE id = ?');
    $stmt->execute(array((int) $id));
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/** The school a name belongs to (its name or one of its other names), or null. */
function kop_ischools_find_by_name(PDO $pdo, $name) {
    $key = kop_ischools_key($name);
    if ($key === '' || !kop_ischools_ready($pdo)) {
        return null;
    }
    foreach (kop_ischools_all($pdo, null) as $s) {
        if ($s['name_key'] === $key) {
            return $s;
        }
        foreach (kop_ischools_lines($s['other_names']) as $other) {
            if (kop_ischools_key($other) === $key) {
                return $s;
            }
        }
    }
    return null;
}

/**
 * The articles filed under each school, newest first:
 * array(school_id => array(article, ...)). Only approved or published
 * articles unless $all (the admin screen), so a rejected one never shows.
 */
function kop_ischools_news(PDO $pdo, $all = false) {
    if (!kop_ischools_ready($pdo)) {
        return array();
    }
    $sql = 'SELECT l.school_id, n.id, n.article_title, n.publication_name, n.publication_date, n.article_url, n.archive_url, n.status
            FROM indigenous_school_news l JOIN news_submissions n ON n.id = l.news_id'
        . ($all ? '' : " WHERE n.status IN ('approved', 'published')")
        . ' ORDER BY n.publication_date DESC, n.id DESC';
    $out = array();
    foreach ($pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $out[(int) $r['school_id']][] = $r;
    }
    return $out;
}

/**
 * Ids of every article filed under a school (or the schools in general).
 * Those articles belong on /indian-boarding-schools/ only, so the news feed,
 * the Latest News widget and the state/country hub news leave them out.
 * Read once per request; empty when the tables are not there yet.
 */
function kop_ischools_news_ids() {
    static $ids = null;
    if ($ids !== null) {
        return $ids;
    }
    $ids = array();
    $pdo = kop_ischools_pdo();
    if (!$pdo || !kop_ischools_ready($pdo)) {
        return $ids;
    }
    try {
        $ids = array_map('intval', $pdo->query('SELECT DISTINCT news_id FROM indigenous_school_news')->fetchAll(PDO::FETCH_COLUMN));
    } catch (PDOException $e) {
        $ids = array();
    }
    return $ids;
}

/** " AND <col> NOT IN (...)" for a news_submissions query, or '' when there are none. */
function kop_ischools_news_exclude_sql($col = 'id') {
    $ids = kop_ischools_news_ids();
    return $ids ? " AND $col NOT IN (" . implode(',', $ids) . ')' : '';
}

/* ---- Writing --------------------------------------------------------- */

/**
 * Save a school from fields (name, other_names, country, region, city,
 * nations, run_by, opened, closed, status, notes, links, review, source).
 * Returns the id. Throws when the name is empty or another school has it.
 */
function kop_ischools_save(PDO $pdo, array $f, $id = 0, $by = '') {
    $name = kop_ischools_clean_name($f['name'] ?? '');
    if ($name === '') {
        throw new RuntimeException('A school needs a name.');
    }
    $key = kop_ischools_key($name);
    $same = $pdo->prepare('SELECT id FROM indigenous_schools WHERE name_key = ? AND id <> ?');
    $same->execute(array($key, (int) $id));
    if ($same->fetchColumn()) {
        throw new RuntimeException('There is already a school called "' . $name . '".');
    }
    $year = static function ($v) {
        $y = is_numeric($v) ? (int) $v : 0;
        return ($y >= 1700 && $y <= (int) gmdate('Y')) ? $y : null;
    };
    $str = static function ($v, $max) {
        return mb_substr(trim(preg_replace('/[ \t]+/', ' ', (string) $v)), 0, $max);
    };
    $row = array(
        'name'        => mb_substr($name, 0, 255),
        'name_key'    => mb_substr($key, 0, 191),
        'other_names' => implode("\n", array_map('kop_ischools_clean_name', kop_ischools_lines($f['other_names'] ?? ''))),
        'country'     => $str($f['country'] ?? '', 100),
        'region'      => $str($f['region'] ?? '', 100),
        'city'        => $str($f['city'] ?? '', 100),
        'nations'     => $str($f['nations'] ?? '', 2000),
        'run_by'      => $str($f['run_by'] ?? '', 255),
        'opened'      => $year($f['opened'] ?? null),
        'closed'      => $year($f['closed'] ?? null),
        'status'      => in_array($f['status'] ?? '', array('Open', 'Closed'), true) ? $f['status'] : 'Unknown',
        'notes'       => function_exists('kop_page_text_clean') ? kop_page_text_clean($f['notes'] ?? '') : trim((string) ($f['notes'] ?? '')),
        'links'       => implode("\n", kop_ischools_lines($f['links'] ?? '')),
        'updated_at'  => kop_ischools_now(),
    );
    if (isset($f['review'])) {
        $row['review'] = $f['review'] === 'pending' ? 'pending' : 'approved';
    }
    if ($id) {
        $sets = implode(', ', array_map(function ($c) { return "$c = ?"; }, array_keys($row)));
        $pdo->prepare("UPDATE indigenous_schools SET $sets WHERE id = ?")->execute(array_merge(array_values($row), array((int) $id)));
        return (int) $id;
    }
    $row['review'] = $row['review'] ?? 'approved';
    $row['source'] = mb_substr((string) ($f['source'] ?? ''), 0, 255);
    $row['created_by'] = mb_substr((string) $by, 0, 100);
    $row['created_at'] = $row['updated_at'];
    $cols = array_keys($row);
    $pdo->prepare('INSERT INTO indigenous_schools (' . implode(', ', $cols) . ') VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ')')
        ->execute(array_values($row));
    return (int) $pdo->lastInsertId();
}

/** File an article under a school (0: about the schools in general). */
function kop_ischools_link_news(PDO $pdo, $school_id, $news_id, $by = '') {
    $have = $pdo->prepare('SELECT 1 FROM indigenous_school_news WHERE school_id = ? AND news_id = ?');
    $have->execute(array((int) $school_id, (int) $news_id));
    if ($have->fetchColumn()) {
        return false;
    }
    $pdo->prepare('INSERT INTO indigenous_school_news (school_id, news_id, created_by, created_at) VALUES (?, ?, ?, ?)')
        ->execute(array((int) $school_id, (int) $news_id, mb_substr((string) $by, 0, 100), kop_ischools_now()));
    return true;
}

function kop_ischools_unlink_news(PDO $pdo, $school_id, $news_id) {
    $pdo->prepare('DELETE FROM indigenous_school_news WHERE school_id = ? AND news_id = ?')
        ->execute(array((int) $school_id, (int) $news_id));
}

/** Delete a school and its article links (the articles themselves stay). */
function kop_ischools_delete(PDO $pdo, $id) {
    $pdo->prepare('DELETE FROM indigenous_school_news WHERE school_id = ?')->execute(array((int) $id));
    $pdo->prepare('DELETE FROM indigenous_schools WHERE id = ?')->execute(array((int) $id));
}

/**
 * Move a record filed as a TTI facility here: a school made from its name,
 * place, years and notes (or the existing school of that name), its article
 * links moved over, and the facility row, hub placements, identity, address
 * rows and article links taken out of the facility tables. A news scan
 * decision that pointed at it is re-filed as a school, so the scan does not
 * link later articles to a record that is gone. $extra fields override what
 * the record held. Returns the school id.
 */
function kop_ischools_move_facility(PDO $pdo, $facility_id, $by = '', array $extra = array()) {
    global $wpdb;
    require_once get_stylesheet_directory() . '/inc/facility-v2-writer.php';
    $fid = (int) $facility_id;
    $prefix = isset($wpdb->prefix) ? $wpdb->prefix : 'wpdl_';
    $stored = kop_facility_load($fid, array('pdo' => $pdo, 'prefix' => $prefix));
    if (!$stored) {
        throw new RuntimeException('There is no facility record ' . $fid . '.');
    }
    $lawsuits = 0;
    try {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM lawsuit_facility_links WHERE facility_id = ?');
        $stmt->execute(array($fid));
        $lawsuits = (int) $stmt->fetchColumn();
    } catch (PDOException $e) {
        // No lawsuit links table on this install.
    }
    if ($lawsuits > 0) {
        throw new RuntimeException('A lawsuit is linked to this record; unlink it in the lawsuit first.');
    }
    $doc = $stored['doc'];
    $id = $doc['identification'] ?? array();
    $loc = $doc['location'] ?? array();
    $period = $doc['operatingPeriod'] ?? array();
    $name = kop_ischools_clean_name(($id['name'] ?? '') ?: ($stored['unique_name'] ?? ''));
    $others = array();
    foreach (array_merge((array) ($id['pastNames'] ?? array()), (array) ($id['otherNames'] ?? array())) as $n) {
        $n = is_array($n) ? ($n['name'] ?? '') : $n;
        if (is_string($n) && trim($n) !== '') {
            $others[] = kop_ischools_clean_name($n);
        }
    }
    $notes = array();
    foreach ((array) ($doc['notes'] ?? array()) as $n) {
        $n = is_array($n) ? ($n['text'] ?? '') : $n;
        // The news scan's own "Added ... by the news scan from ..." note is
        // bookkeeping, not something to publish; the article is linked anyway.
        if (is_string($n) && trim($n) !== '' && !preg_match('/^Added \S+ by the news scan/i', trim($n))) {
            $notes[] = trim($n);
        }
    }
    $status = $period['status'] ?? '';
    $fields = array_merge(array(
        'name'        => $name,
        'other_names' => implode("\n", $others),
        'country'     => $loc['country'] ?? '',
        'region'      => $loc['state'] ?? '',
        'city'        => $loc['city'] ?? '',
        'run_by'      => $id['currentOperator'] ?? '',
        'opened'      => $period['startYear'] ?? null,
        'closed'      => $period['endYear'] ?? null,
        'status'      => in_array($status, array('Open', 'Closed'), true) ? $status : 'Unknown',
        'notes'       => implode("\n\n", $notes),
        'links'       => '',
        'review'      => 'approved',
        'source'      => 'Moved from facility record ' . $fid,
    ), $extra);

    kop_ischools_install($pdo);
    $t = kop_migration_tables($prefix);
    $sid = kop_v2_with_write_lock($pdo, function () use ($pdo, $t, $fid, $fields, $name, $by, $prefix) {
        $school = kop_ischools_find_by_name($pdo, $name);
        $sid = $school ? (int) $school['id'] : kop_ischools_save($pdo, $fields, 0, $by);

        $links = $pdo->prepare('SELECT news_id FROM news_facility_links WHERE facility_id = ?');
        $links->execute(array($fid));
        foreach ($links->fetchAll(PDO::FETCH_COLUMN) as $nid) {
            kop_ischools_link_news($pdo, $sid, (int) $nid, $by);
        }
        kop_ischools_forget_facility_mentions($pdo, $fid);

        foreach (array($t['facility_locations'], $t['operator_facilities'], $t['identity']) as $table) {
            $pdo->prepare("DELETE FROM `{$table}` WHERE facility_id = ?")->execute(array($fid));
        }
        try {
            $pdo->prepare('DELETE FROM `' . $prefix . 'kop_facility_addresses` WHERE facility = ?')->execute(array($name));
        } catch (PDOException $e) {
            // No address table on this install.
        }
        $pdo->prepare('DELETE FROM news_facility_links WHERE facility_id = ?')->execute(array($fid));
        try {
            $pdo->prepare("UPDATE facility_closure_reports SET facility_id = NULL, status = 'unmatched' WHERE facility_id = ? AND status IN ('pending','already')")
                ->execute(array($fid));
        } catch (PDOException $e) {
            // No closure reports table on this install.
        }
        try {
            $pdo->prepare("UPDATE news_facility_candidates SET decision = 'indigenous_school', facility_id = NULL, updated_at = ? WHERE facility_id = ?")
                ->execute(array(kop_ischools_now(), $fid));
        } catch (PDOException $e) {
            // No news scan tables on this install.
        }
        $pdo->prepare("DELETE FROM `{$t['facilities']}` WHERE id = ?")->execute(array($fid));
        return $sid;
    });
    do_action('kop_facility_status_changed', $fid);
    if (function_exists('kop_page_text_purge')) {
        kop_page_text_purge(KOP_ISCHOOLS_PAGE);
    }
    return $sid;
}

/**
 * Articles whose list of facilities mentioned points at a facility id:
 * the id is dropped (the name stays), so saving the article again does not
 * link it back to a record that is gone.
 */
function kop_ischools_forget_facility_mentions(PDO $pdo, $fid) {
    $stmt = $pdo->prepare('SELECT id, facilities_mentioned FROM news_submissions WHERE facilities_mentioned LIKE ?');
    $stmt->execute(array('%' . (int) $fid . '%'));
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $list = json_decode((string) $r['facilities_mentioned'], true);
        if (!is_array($list)) {
            continue;
        }
        $changed = false;
        foreach ($list as $i => $m) {
            if (is_array($m) && isset($m['facility_id']) && (int) $m['facility_id'] === (int) $fid) {
                $list[$i]['facility_id'] = null;
                $changed = true;
            }
        }
        if ($changed) {
            $pdo->prepare('UPDATE news_submissions SET facilities_mentioned = ? WHERE id = ?')
                ->execute(array(json_encode(array_values($list), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), (int) $r['id']));
        }
    }
}

/**
 * A school the news scan found: the existing one of that name, or a new
 * record waiting for review, with the article linked. Returns the id.
 */
function kop_ischools_from_news(PDO $pdo, array $entry, array $news) {
    kop_ischools_install($pdo);
    $name = kop_ischools_clean_name(($entry['officialName'] ?? '') ?: ($entry['name'] ?? ''));
    $school = kop_ischools_find_by_name($pdo, $name) ?: kop_ischools_find_by_name($pdo, $entry['name'] ?? '');
    if ($school) {
        $sid = (int) $school['id'];
    } else {
        $cite = trim(implode(', ', array_filter(array(
            $news['publication_name'] ?? '',
            !empty($news['article_title']) ? '"' . $news['article_title'] . '"' : '',
            $news['publication_date'] ?? '',
        ))));
        $others = array();
        foreach (array_merge(array($entry['name'] ?? ''), (array) ($entry['otherNames'] ?? array())) as $n) {
            if (kop_ischools_key($n) !== '' && kop_ischools_key($n) !== kop_ischools_key($name)) {
                $others[] = $n;
            }
        }
        $sid = kop_ischools_save($pdo, array(
            'name'        => $name,
            'other_names' => implode("\n", array_unique($others)),
            'country'     => ($entry['country'] ?? '') ?: (($entry['state'] ?? '') !== '' ? 'United States' : ''),
            'region'      => $entry['state'] ?? '',
            'city'        => $entry['city'] ?? '',
            'run_by'      => $entry['operator'] ?? '',
            'opened'      => $entry['startYear'] ?? null,
            'closed'      => $entry['endYear'] ?? null,
            'status'      => $entry['status'] ?? 'Unknown',
            'review'      => 'pending',
            'source'      => 'Found by the news scan in: ' . $cite,
        ), 0, 'news-discovery');
    }
    if (!empty($news['id'])) {
        kop_ischools_link_news($pdo, $sid, (int) $news['id'], 'news-discovery');
    }
    return $sid;
}

/* ---- The page -------------------------------------------------------- */

function kop_ischools_country_label($country) {
    $c = strtolower(trim((string) $country));
    if ($c === '') {
        return 'Other places';
    }
    if (in_array($c, array('us', 'usa', 'u.s.', 'united states', 'united states of america'), true)) {
        return 'United States';
    }
    return $c === 'canada' ? 'Canada' : trim((string) $country);
}

/** "Carlisle, PA · 1879 to 1918" and so on. */
function kop_ischools_meta_line(array $s) {
    $bits = array();
    $place = trim(implode(', ', array_filter(array($s['city'], $s['region']))));
    if ($place !== '') {
        $bits[] = $place;
    }
    if ($s['opened'] && $s['closed']) {
        $bits[] = (int) $s['opened'] . ' to ' . (int) $s['closed'];
    } elseif ($s['opened']) {
        $bits[] = 'Opened ' . (int) $s['opened'] . ($s['status'] === 'Open' ? ', still open' : '');
    } elseif ($s['closed']) {
        $bits[] = 'Closed ' . (int) $s['closed'];
    } elseif ($s['status'] === 'Open') {
        $bits[] = 'Still open';
    }
    return implode(' · ', $bits);
}

function kop_ischools_article_item(array $a) {
    $e = 'kop_page_text_esc';
    $date = $a['publication_date'] ? strtotime($a['publication_date']) : false;
    $meta = trim(implode(', ', array_filter(array($a['publication_name'], $date ? date('F j, Y', $date) : ''))));
    // The title opens the real article; the archived copy is linked beside it.
    require_once dirname(__DIR__) . '/api/lib-news-archive.php';
    $links = kop_news_links((string) $a['article_url'], (string) ($a['archive_url'] ?? ''));
    $url = $links['original'] !== '' ? $links['original'] : $links['archive'];
    $title = $e($a['article_title'] ?: 'Untitled article');
    return '<li>' . ($url !== '' ? '<a href="' . $e($url) . '">' . $title . '</a>' . kop_news_archive_link_html((string) $a['article_url'], (string) ($a['archive_url'] ?? '')) : $title)
        . ($meta !== '' ? ' <span class="kop-ibs-school-src">' . $e($meta) . '</span>' : '') . '</li>';
}

/** The records on the page: approved schools by country, then general articles. */
function kop_ischools_render_public() {
    $pdo = kop_ischools_pdo();
    if (!$pdo || !kop_ischools_ready($pdo) || !function_exists('kop_page_text_esc')) {
        return;
    }
    $e = 'kop_page_text_esc';
    $schools = kop_ischools_all($pdo, 'approved');
    $news = kop_ischools_news($pdo);
    if (!$schools && empty($news[0])) {
        return;
    }
    $by_country = array();
    foreach ($schools as $s) {
        $by_country[kop_ischools_country_label($s['country'])][] = $s;
    }
    $order = array('United States' => 0, 'Canada' => 1, 'Other places' => 99);
    uksort($by_country, function ($a, $b) use ($order) {
        $x = $order[$a] ?? 50;
        $y = $order[$b] ?? 50;
        return $x === $y ? strcmp($a, $b) : $x - $y;
    });

    echo '<div class="kop-ibs-records">' . "\n";
    foreach ($by_country as $country => $list) {
        echo '<h3>' . $e($country) . "</h3>\n";
        foreach ($list as $s) {
            echo '<article class="kop-ibs-school" id="kop-ibs-school-' . (int) $s['id'] . '"'
                . (function_exists('kop_ie_attr') ? kop_ie_attr('school:' . (int) $s['id'], $s['name']) : '') . '>';
            echo '<h4 class="kop-ibs-school-name">' . $e($s['name']) . '</h4>';
            $meta = kop_ischools_meta_line($s);
            if ($meta !== '') {
                echo '<p class="kop-ibs-school-meta">' . $e($meta) . '</p>';
            }
            $others = kop_ischools_lines($s['other_names']);
            if ($others) {
                echo '<p class="kop-ibs-school-fact"><strong>Also known as:</strong> ' . $e(implode('; ', $others)) . '</p>';
            }
            if (trim((string) $s['nations']) !== '') {
                echo '<p class="kop-ibs-school-fact"><strong>Nations:</strong> ' . $e($s['nations']) . '</p>';
            }
            if (trim((string) $s['run_by']) !== '') {
                echo '<p class="kop-ibs-school-fact"><strong>Run by:</strong> ' . $e($s['run_by']) . '</p>';
            }
            if (trim((string) $s['notes']) !== '') {
                echo kop_page_text_body_html($s['notes'], 'plain', 'kop-ibs');
            }
            if (!empty($news[(int) $s['id']])) {
                echo '<p class="kop-ibs-school-list-title">In the news</p><ul class="kop-ibs-school-list">';
                foreach ($news[(int) $s['id']] as $a) {
                    echo kop_ischools_article_item($a);
                }
                echo '</ul>';
            }
            $links = kop_ischools_parse_links($s['links']);
            if ($links) {
                echo '<p class="kop-ibs-school-list-title">More about this school</p><ul class="kop-ibs-school-list">';
                foreach ($links as $l) {
                    echo '<li><a href="' . $e($l[1]) . '">' . $e($l[0]) . '</a></li>';
                }
                echo '</ul>';
            }
            echo "</article>\n";
        }
    }
    if (!empty($news[0])) {
        echo '<h3>News about the schools</h3><ul class="kop-ibs-school-list">';
        foreach ($news[0] as $a) {
            echo kop_ischools_article_item($a);
        }
        echo "</ul>\n";
    }
    echo "</div>\n";
}

/* ---- The first move, once, on deploy ---------------------------------- */

/**
 * The records the owner asked to move on 2026-09-30, each moved only if it
 * is still in the facility data under this name. Ekalavya Model
 * Residential School (100238, India) stays, by the owner's decision.
 */
function kop_ischools_first_moves() {
    return array(
        11605 => array('Carlisle Indian Industrial School', array(
            'opened' => 1879,
            'closed' => 1918,
            'status' => 'Closed',
            'run_by' => 'United States federal government',
            'links'  => "National Park Service: the Carlisle Indian Industrial School | https://www.nps.gov/articles/the-carlisle-indian-industrial-school-assimilation-with-education-after-the-indian-wars-teaching-with-historic-places.htm\n"
                      . "Carlisle Federal Indian Boarding School National Monument (2024 proclamation) | https://www.federalregister.gov/documents/2024/12/12/2024-29459/establishment-of-the-carlisle-federal-indian-boarding-school-national-monument",
        )),
        100211 => array("St. Paul's Indian Mission School", array('country' => 'United States')),
        13578  => array('Oaks Indian Mission', array()),
        13576  => array("Murrow Indian Children's Home", array()),
    );
}

/**
 * Version 1: create the tables, make the moves above, and file the two
 * general articles about Indian boarding schools. What happened is kept in
 * kop_ischools_migration_log for the admin screen.
 */
function kop_ischools_maybe_migrate() {
    $version = '1';
    if (get_option('kop_ischools_migrated') === $version) {
        return;
    }
    $pdo = kop_ischools_pdo();
    if (!$pdo) {
        return;
    }
    update_option('kop_ischools_migrated', $version);
    $log = array();
    try {
        kop_ischools_install($pdo);
        require_once get_stylesheet_directory() . '/inc/facility-v2-writer.php';
        global $wpdb;
        foreach (kop_ischools_first_moves() as $fid => $spec) {
            $stored = kop_facility_load($fid, array('pdo' => $pdo, 'prefix' => $wpdb->prefix));
            $name = $stored ? kop_ischools_clean_name(($stored['doc']['identification']['name'] ?? '') ?: $stored['unique_name']) : '';
            if (!$stored || kop_ischools_key($name) !== kop_ischools_key($spec[0])) {
                $log[] = "Facility record $fid was not there as " . $spec[0] . '; left alone.';
                continue;
            }
            $sid = kop_ischools_move_facility($pdo, $fid, 'migration', $spec[1]);
            $log[] = "Moved facility record $fid ($name) to school $sid.";
        }
        foreach (array(518, 519) as $nid) {
            $have = $pdo->prepare('SELECT 1 FROM news_submissions WHERE id = ?');
            $have->execute(array($nid));
            if ($have->fetchColumn() && kop_ischools_link_news($pdo, 0, $nid, 'migration')) {
                $log[] = "Filed article $nid under the schools in general.";
            }
        }
    } catch (Throwable $e) {
        $log[] = 'Stopped: ' . $e->getMessage();
        update_option('kop_ischools_migrated', '');
    }
    update_option('kop_ischools_migration_log', array('time' => time(), 'lines' => $log), false);
}
add_action('admin_init', 'kop_ischools_maybe_migrate');
add_action('init', 'kop_ischools_maybe_migrate', 30);
