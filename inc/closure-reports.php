<?php
/**
 * Facility closures reported in the news.
 *
 * Every saved article (nightly discovery, the news processor, imports) is
 * scanned once by an hourly WP-Cron job: articles whose title, summary or type
 * talk about a closure go to the AI (Groq and Gemini in turn) with the article text and the facilities the
 * article is already linked to, and each program the article says closed, is
 * closing, was ordered closed or was suspended becomes a closure report in
 * facility_closure_reports (records DB, beside news_submissions).
 *
 * Nothing changes on the site until an admin confirms a report under KOP Data
 * Tools > Closure Reports. Confirming sets the facility's operatingPeriod
 * status in facilities_v2 (so its /facility/ page changes at once), fills the
 * end year from the closure date when the record has none, records the article
 * as the source in operatingPeriod.notes and links the article to the
 * facility. The network map follows facilities_v2 at serve time
 * (kop_network_map_status_overrides() in inc/network-map.php), so the same
 * confirm recolours the map. Undo puts the old status, end year and notes back.
 *
 * Server CLI: php api/scan-closure-reports.php (dry run unless "apply").
 */

if (!defined('ABSPATH')) {
    exit;
}

define('KOP_CLOSURE_REPORTS_DB_VERSION', '1');

/** Report statuses, as the review screen names them. */
function kop_closure_report_statuses() {
    return array(
        'pending'   => 'To review',
        'unmatched' => 'No facility match',
        'applied'   => 'Confirmed',
        'dismissed' => 'Dismissed',
        'already'   => 'Already recorded',
        'duplicate' => 'Duplicate',
    );
}

/** What the article says happened, and the facility status each one sets. */
function kop_closure_report_stages() {
    return array(
        'closed'         => array('label' => 'Closed',                'status' => 'Closed'),
        'closing'        => array('label' => 'Announced closing',     'status' => 'Closed'),
        'ordered_closed' => array('label' => 'Ordered closed',        'status' => 'Closed'),
        'suspended'      => array('label' => 'Suspended',             'status' => 'Suspended'),
    );
}

/** The records DB (news_submissions lives there, not in $wpdb on prod). */
function kop_closure_pdo() {
    $pdo = function_exists('kop_seed_pdo') ? kop_seed_pdo() : null;
    if ($pdo) {
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }
    return $pdo;
}

function kop_closure_ensure_tables(PDO $pdo) {
    if (get_option('kop_closure_reports_db') === KOP_CLOSURE_REPORTS_DB_VERSION) {
        return;
    }
    $pdo->exec("CREATE TABLE IF NOT EXISTS news_closure_scans (
        news_id INT NOT NULL,
        content_hash CHAR(32) NOT NULL,
        outcome VARCHAR(20) NOT NULL,
        attempts INT NOT NULL DEFAULT 1,
        detail TEXT NULL,
        scanned_at DATETIME NOT NULL,
        PRIMARY KEY (news_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS facility_closure_reports (
        id INT NOT NULL AUTO_INCREMENT,
        news_id INT NOT NULL,
        facility_id INT NULL,
        program_name VARCHAR(255) NOT NULL,
        location VARCHAR(255) NULL,
        stage VARCHAR(20) NOT NULL,
        target_status VARCHAR(20) NOT NULL,
        closure_date VARCHAR(10) NULL,
        quote TEXT NULL,
        quote_found TINYINT(1) NOT NULL DEFAULT 0,
        status VARCHAR(20) NOT NULL DEFAULT 'pending',
        previous_status VARCHAR(20) NULL,
        previous_end_year INT NULL,
        applied_note TEXT NULL,
        reviewed_by VARCHAR(100) NULL,
        reviewed_at DATETIME NULL,
        created_at DATETIME NOT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY news_program (news_id, program_name(150)),
        KEY status (status),
        KEY facility (facility_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    update_option('kop_closure_reports_db', KOP_CLOSURE_REPORTS_DB_VERSION);
}

/* ---- Which articles to scan ----------------------------------------- */

/**
 * Does the article talk about a closure at all? Cheap gate before Groq: most
 * articles are lawsuits and arrests and never need the call.
 */
function kop_closure_article_mentions_closure(array $news) {
    if (($news['article_type'] ?? '') === 'closure') {
        return true;
    }
    $text = ($news['article_title'] ?? '') . ' ' . ($news['summary'] ?? '');
    return (bool) preg_match(
        '/\b(clos(e|ed|es|ing|ure|ures)|shut(s|ting)?\s+(down|its doors)|shut\s*down|shutter(ed|s|ing)?|ceas(e|ed|es|ing)\s+operat|wind(s|ing)?\s+down|wound\s+down|revok(e|ed|es|ing)|revocation|surrender(ed|s|ing)?\s+(its|their|the)?\s*licen[cs]e|no longer (operat|accept|serv)|suspend(ed|s|ing)?|suspension)\b/i',
        $text
    );
}

/** Changes when anything the scan reads from the row changes. */
function kop_closure_news_hash(array $news) {
    return md5(implode("\x1f", array(
        $news['article_title'] ?? '', $news['summary'] ?? '', $news['article_type'] ?? '', $news['article_url'] ?? '', 'v1',
    )));
}

/**
 * Articles not yet scanned in their current form. Rejected, deleted and
 * promotional rows are never scanned. A row that failed is retried on later
 * runs, three times at most.
 */
function kop_closure_articles_to_scan(PDO $pdo, $limit, array $only_ids = array()) {
    $where = "n.status NOT IN ('rejected','deleted','promotional')";
    $params = array();
    if ($only_ids) {
        $where .= ' AND n.id IN (' . implode(',', array_fill(0, count($only_ids), '?')) . ')';
        $params = array_map('intval', $only_ids);
    }
    // A dry run on a site that has never scanned has no scans table yet.
    $scans = $pdo->query("SHOW TABLES LIKE 'news_closure_scans'")->fetchColumn()
        ? 's.content_hash, s.outcome, s.attempts FROM news_submissions n LEFT JOIN news_closure_scans s ON s.news_id = n.id'
        : 'NULL AS content_hash, NULL AS outcome, 0 AS attempts FROM news_submissions n';
    $stmt = $pdo->prepare("SELECT n.id, n.article_title, n.publication_name, n.publication_date, n.article_url,
                                  n.article_type, n.summary, n.status, n.json_data, {$scans}
                            WHERE {$where}
                         ORDER BY n.id DESC");
    $stmt->execute($params);
    $out = array();
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $hash = kop_closure_news_hash($row);
        $fresh = $row['content_hash'] === $hash;
        if (!$only_ids && $fresh && ($row['outcome'] !== 'error' || (int) $row['attempts'] >= 3)) {
            continue;
        }
        $row['hash'] = $hash;
        $out[] = $row;
        if (count($out) >= $limit) {
            break;
        }
    }
    return $out;
}

function kop_closure_record_scan(PDO $pdo, $news_id, $hash, $outcome, $detail = '') {
    $pdo->prepare("INSERT INTO news_closure_scans (news_id, content_hash, outcome, attempts, detail, scanned_at)
                   VALUES (?, ?, ?, 1, ?, UTC_TIMESTAMP())
                   ON DUPLICATE KEY UPDATE
                       attempts = IF(content_hash = VALUES(content_hash) AND outcome = 'error', attempts + 1, 1),
                       content_hash = VALUES(content_hash), outcome = VALUES(outcome),
                       detail = VALUES(detail), scanned_at = VALUES(scanned_at)")
        ->execute(array((int) $news_id, $hash, $outcome, mb_substr((string) $detail, 0, 2000)));
}

/* ---- Asking the AI ------------------------------------------------- */

/** Facilities the article is already linked to, for the model to pick from. */
function kop_closure_linked_facilities(PDO $pdo, $news_id) {
    $stmt = $pdo->prepare("SELECT f.id, f.unique_name, f.city, f.state, f.status
                             FROM news_facility_links l
                             JOIN facilities_v2 f ON f.id = l.facility_id
                            WHERE l.news_id = ?
                            LIMIT 40");
    $stmt->execute(array((int) $news_id));
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function kop_closure_build_prompt(array $news, array $linked, $text) {
    $p  = "You read news articles about youth residential programs (troubled teen industry: residential treatment centers, therapeutic boarding schools, wilderness programs, group homes, juvenile facilities) and report which programs the article says have closed.\n\n";
    $p .= 'Title: ' . $news['article_title'] . "\n";
    $p .= 'Publication: ' . ($news['publication_name'] ?: 'unknown') . "\n";
    $p .= 'Published: ' . ($news['publication_date'] ?: 'unknown') . "\n";
    $p .= 'URL: ' . $news['article_url'] . "\n";
    if (trim((string) $news['summary']) !== '') {
        $p .= 'Summary: ' . trim($news['summary']) . "\n";
    }
    if ($linked) {
        $p .= "\nPrograms in our database that this article is linked to (id | name | place | status we have):\n";
        foreach ($linked as $f) {
            $place = trim(implode(', ', array_filter(array($f['city'], $f['state']))));
            $p .= $f['id'] . ' | ' . $f['unique_name'] . ' | ' . ($place ?: '-') . ' | ' . ($f['status'] ?: 'Unknown') . "\n";
        }
    }
    $p .= "\nArticle text:\n" . ($text !== '' ? $text : '(could not be fetched; use the title and summary)') . "\n\n";
    $p .= "Return ONLY a JSON object:\n";
    $p .= '{"closures":[{"program":"name as the article gives it","facilityId":null,"location":"City, State","stage":"closed|closing|ordered_closed|suspended","date":"YYYY-MM-DD, YYYY-MM or YYYY, or empty","quote":"one sentence copied word for word from the article that says it"}]}' . "\n\n";
    $p .= "Stages:\n";
    $p .= "- closed: the article says the program has closed, shut down, stopped operating or no longer houses youth. Includes closures in the past that the article states as fact.\n";
    $p .= "- closing: the article says the program will close (announced, date set, winding down).\n";
    $p .= "- ordered_closed: a regulator revoked its license or ordered it to close, and the article does not say it has stopped operating (for example it is appealing).\n";
    $p .= "- suspended: its license or admissions were suspended, or it paused operating.\n";
    $p .= "Leave out:\n";
    $p .= "- attempts, threats, calls, petitions or proposals to close that did not happen, and closures that were reversed;\n";
    $p .= "- one unit, wing, campus building or service closing while the program stays open;\n";
    $p .= "- a program renamed or sold that kept operating under a new name;\n";
    $p .= "- companies, agencies, bank branches and anything that is not a youth program. When a company closed its programs, list each program the article names.\n";
    $p .= "facilityId: the id from the list above when the program is one of them, otherwise null. Never guess an id.\n";
    $p .= "If the article reports no closure, return {\"closures\":[]}.\n";
    return $p;
}

/** Lowercase letters and digits only, single-spaced: for matching quotes. */
function kop_closure_norm($s) {
    $s = html_entity_decode((string) $s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    return trim(preg_replace('/[^a-z0-9]+/', ' ', strtolower($s)));
}

/**
 * Is the quote really in the article? The model is told to copy it word for
 * word; one it made up is the clearest sign the whole report is wrong. Checks
 * the first twelve words, so a trimmed or re-punctuated ending still counts.
 */
function kop_closure_quote_found($quote, $haystack) {
    $words = explode(' ', kop_closure_norm($quote));
    if (count($words) < 4) {
        return false;
    }
    $needle = implode(' ', array_slice($words, 0, 12));
    return strpos(' ' . kop_closure_norm($haystack) . ' ', ' ' . $needle . ' ') !== false;
}

/** 'YYYY-MM-DD', 'YYYY-MM', 'YYYY' or '' from whatever the model wrote. */
function kop_closure_clean_date($raw) {
    $raw = trim((string) $raw);
    if (preg_match('/^(\d{4})(-(0[1-9]|1[0-2]))?(-(0[1-9]|[12]\d|3[01]))?$/', $raw, $m)) {
        $year = (int) $m[1];
        if ($year >= 1950 && $year <= (int) gmdate('Y') + 2) {
            return $raw;
        }
    }
    return '';
}

/**
 * Turn the model's answer into clean report rows. Unknown stages, empty names
 * and ids that were not in the list it was given are dropped or cleared.
 */
function kop_closure_parse_reply($reply, array $linked) {
    require_once get_stylesheet_directory() . '/api/ai-providers.php';
    $data = is_array($reply) ? $reply : kop_ai_extract_json((string) $reply);
    if (!is_array($data) || !isset($data['closures']) || !is_array($data['closures'])) {
        return null;
    }
    $stages = kop_closure_report_stages();
    $allowed_ids = array();
    foreach ($linked as $f) {
        $allowed_ids[(int) $f['id']] = true;
    }
    $out = array();
    $seen = array();
    foreach ($data['closures'] as $c) {
        if (!is_array($c)) {
            continue;
        }
        $name = trim(preg_replace('/\s+/', ' ', (string) ($c['program'] ?? '')));
        $stage = strtolower(trim((string) ($c['stage'] ?? '')));
        if ($name === '' || !isset($stages[$stage])) {
            continue;
        }
        $key = strtolower($name);
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $fid = isset($c['facilityId']) && is_numeric($c['facilityId']) ? (int) $c['facilityId'] : 0;
        $out[] = array(
            'program'     => mb_substr($name, 0, 255),
            'facility_id' => isset($allowed_ids[$fid]) ? $fid : null,
            'location'    => mb_substr(trim((string) ($c['location'] ?? '')), 0, 255),
            'stage'       => $stage,
            'date'        => kop_closure_clean_date($c['date'] ?? ''),
            'quote'       => mb_substr(trim((string) ($c['quote'] ?? '')), 0, 1000),
        );
    }
    return $out;
}

/** A facility id from the article's own links, then the name/alias index. */
function kop_closure_resolve_facility(PDO $pdo, array $report, &$alias_index) {
    if (!empty($report['facility_id'])) {
        return (int) $report['facility_id'];
    }
    if ($alias_index === null) {
        require_once get_stylesheet_directory() . '/api/facility-aliases.php';
        $alias_index = kop_build_facility_alias_index($pdo);
    }
    $fid = kop_resolve_mention_to_facility($report['program'], $alias_index);
    if ($fid === null) {
        return null;
    }
    // The index also holds operator projects; only a facility can close here.
    $stmt = $pdo->prepare('SELECT 1 FROM facilities_v2 WHERE id = ?');
    $stmt->execute(array($fid));
    return $stmt->fetchColumn() ? (int) $fid : null;
}

/**
 * An article's text for a Groq prompt, or '' when it cannot be fetched.
 * Groq's free tier counts tokens per minute, and what the scans look for is
 * nearly always in the opening paragraphs, so it is cut at $max bytes.
 */
function kop_closure_article_text($url, $max = 9000) {
    if (!$url || !preg_match('#^https?://#i', $url)) {
        return '';
    }
    require_once get_stylesheet_directory() . '/api/lib-article-fetch.php';
    $text = (string) fetchArticleContent($url);
    if (strlen($text) > $max) {
        $text = mb_strcut($text, 0, $max, 'UTF-8') . ' [truncated]';
    }
    if (!mb_check_encoding($text, 'UTF-8')) {
        $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
    }
    return $text;
}

/**
 * Ask the AI: Groq and Gemini take turns (api/ai-providers.php), each with
 * its own fallbacks, and the other answers when one fails. Throws the joined
 * errors, whose message says "rate limit" when a provider was out of calls.
 */
function kop_closure_ai($prompt, $max_tokens = 2048) {
    require_once get_stylesheet_directory() . '/api/ai-providers.php';
    return kop_ai_generate_alternating($prompt, array('maxTokens' => $max_tokens));
}

/**
 * Scan one article. Returns array(outcome, reports[], detail). With $write
 * false nothing is stored (the CLI dry run).
 */
function kop_closure_scan_article(PDO $pdo, array $news, $write, &$alias_index) {
    if (!kop_closure_article_mentions_closure($news)) {
        if ($write) {
            kop_closure_record_scan($pdo, $news['id'], $news['hash'], 'skipped');
        }
        return array('skipped', array(), '');
    }

    $text = kop_closure_article_text($news['article_url'] ?? '');
    $linked = kop_closure_linked_facilities($pdo, $news['id']);

    require_once get_stylesheet_directory() . '/api/ai-providers.php';
    try {
        $raw = kop_closure_ai(kop_closure_build_prompt($news, $linked, $text));
    } catch (Throwable $e) {
        // A rate limit says nothing about the article: leave it unscanned so
        // the next run tries again without spending one of its three tries.
        if ($write && stripos($e->getMessage(), 'rate limit') === false) {
            kop_closure_record_scan($pdo, $news['id'], $news['hash'], 'error', $e->getMessage());
        }
        return array('error', array(), $e->getMessage());
    }
    $reports = kop_closure_parse_reply($raw, $linked);
    if ($reports === null) {
        if ($write) {
            kop_closure_record_scan($pdo, $news['id'], $news['hash'], 'error', 'Unreadable reply: ' . mb_substr((string) $raw, 0, 300));
        }
        return array('error', array(), 'Unreadable reply');
    }

    $stages = kop_closure_report_stages();
    $haystack = $text . ' ' . $news['article_title'] . ' ' . $news['summary'];
    foreach ($reports as &$r) {
        $r['facility_id'] = kop_closure_resolve_facility($pdo, $r, $alias_index);
        $r['target_status'] = $stages[$r['stage']]['status'];
        $r['quote_found'] = kop_closure_quote_found($r['quote'], $haystack);
        $r['current_status'] = null;
        if ($r['facility_id']) {
            $stmt = $pdo->prepare('SELECT status FROM facilities_v2 WHERE id = ?');
            $stmt->execute(array($r['facility_id']));
            $r['current_status'] = $stmt->fetchColumn() ?: null;
        }
        $r['report_status'] = !$r['facility_id'] ? 'unmatched'
            : ($r['current_status'] === $r['target_status'] ? 'already' : 'pending');
    }
    unset($r);

    if ($write) {
        $ins = $pdo->prepare("INSERT IGNORE INTO facility_closure_reports
            (news_id, facility_id, program_name, location, stage, target_status, closure_date, quote, quote_found, status, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())");
        foreach ($reports as $r) {
            $ins->execute(array(
                (int) $news['id'], $r['facility_id'], $r['program'], $r['location'], $r['stage'], $r['target_status'],
                $r['date'] !== '' ? $r['date'] : null, $r['quote'], $r['quote_found'] ? 1 : 0, $r['report_status'],
            ));
        }
        kop_closure_record_scan($pdo, $news['id'], $news['hash'], $reports ? 'found' : 'none', $text === '' ? 'no article text' : '');
    }
    return array($reports ? 'found' : 'none', $reports, $text === '' ? 'no article text' : '');
}

/**
 * Scan up to $limit articles. Stops early on an AI rate limit or when
 * $seconds run out. Returns counts and the new reports that need a person.
 */
function kop_closure_scan_batch(PDO $pdo, $limit, $seconds, $write = true, array $only_ids = array(), $log = null) {
    if ($write) {
        kop_closure_ensure_tables($pdo);
    }
    $started = time();
    $alias_index = null;
    $counts = array('scanned' => 0, 'skipped' => 0, 'none' => 0, 'found' => 0, 'error' => 0);
    $new = array();
    foreach (kop_closure_articles_to_scan($pdo, $limit, $only_ids) as $news) {
        list($outcome, $reports, $detail) = kop_closure_scan_article($pdo, $news, $write, $alias_index);
        $counts['scanned']++;
        $counts[$outcome]++;
        if ($log) {
            $log($news, $outcome, $reports, $detail);
        }
        foreach ($reports as $r) {
            if ($r['report_status'] === 'pending' || $r['report_status'] === 'unmatched') {
                $new[] = array('news' => $news, 'report' => $r);
            }
        }
        if ($outcome === 'error' && stripos($detail, 'rate limit') !== false) {
            break;
        }
        if (time() - $started > $seconds) {
            break;
        }
    }
    return array('counts' => $counts, 'new' => $new);
}

/* ---- Confirming, dismissing, undoing -------------------------------- */

function kop_closure_get_report(PDO $pdo, $id) {
    $stmt = $pdo->prepare("SELECT r.*, n.article_title, n.publication_name, n.publication_date, n.article_url, n.status AS news_status
                             FROM facility_closure_reports r
                        LEFT JOIN news_submissions n ON n.id = r.news_id
                            WHERE r.id = ?");
    $stmt->execute(array((int) $id));
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

/** The line written into operatingPeriod.notes, naming the article. */
function kop_closure_source_note(array $report) {
    $stages = kop_closure_report_stages();
    $what = $stages[$report['stage']]['label'] ?? $report['target_status'];
    $bits = array_filter(array(
        $report['publication_name'] ?: '',
        $report['article_title'] ? '"' . $report['article_title'] . '"' : '',
        $report['publication_date'] ?: '',
    ));
    $note = $what . ($report['closure_date'] ? ' (' . $report['closure_date'] . ')' : '')
        . ' per news report: ' . implode(', ', $bits);
    if ($report['article_url']) {
        $note .= ' ' . $report['article_url'];
    }
    return $note;
}

/**
 * The document rebuilt with $change applied to its operatingPeriod, the way
 * the deploy seeds write (kop_v2_apply_facility_record_seed()): through the
 * legacy shape and kop_facility_normalize(), so a document saved under an
 * older schema version comes out current and passes kop_facility_save().
 */
function kop_closure_rewrite_period(array $doc, callable $change) {
    $legacy = kop_facility_to_legacy($doc);
    $legacy['operatingPeriod'] = $change(is_array($legacy['operatingPeriod'] ?? null) ? $legacy['operatingPeriod'] : array());
    $out = kop_facility_normalize($legacy, array(
        'facility_id' => $doc['facility_id'] ?? null,
        'unique_name' => $doc['provenance']['uniqueName'] ?? '',
    ));
    $out['provenance'] = $doc['provenance'] ?? $out['provenance'];
    return $out;
}

/**
 * A facility document with the report's status set. Returns array(doc,
 * previous status, previous end year). The end year is only filled when the
 * record has none: a year someone entered by hand wins over the article.
 */
function kop_closure_doc_apply(array $doc, $target_status, $end_year, $note) {
    $period = is_array($doc['operatingPeriod'] ?? null) ? $doc['operatingPeriod'] : array();
    $previous_status = $period['status'] ?? 'Unknown';
    $previous_end = isset($period['endYear']) && $period['endYear'] !== null ? (int) $period['endYear'] : null;
    $out = kop_closure_rewrite_period($doc, static function (array $op) use ($target_status, $end_year, $note, $previous_end) {
        $op['status'] = $target_status;
        if ($end_year && $previous_end === null) {
            $op['endYear'] = (int) $end_year;
        }
        $notes = is_array($op['notes'] ?? null) ? $op['notes'] : array();
        if (!in_array($note, $notes, true)) {
            $notes[] = $note;
        }
        $op['notes'] = $notes;
        return $op;
    });
    return array($out, $previous_status, $previous_end);
}

/** The document with a confirmed report taken back out. */
function kop_closure_doc_undo(array $doc, array $report) {
    return kop_closure_rewrite_period($doc, static function (array $op) use ($report) {
        // Only undo what is still ours: a status someone has since changed stays.
        if (($op['status'] ?? '') === $report['target_status']) {
            $op['status'] = $report['previous_status'] ?: 'Unknown';
        }
        // A confirm only fills an empty end year, so an empty one before
        // means the year there now came from the confirm.
        if ($report['previous_end_year'] === null) {
            $op['endYear'] = null;
        }
        $op['notes'] = array_values(array_filter((array) ($op['notes'] ?? array()), static function ($n) use ($report) {
            return $n !== $report['applied_note'];
        }));
        return $op;
    });
}

function kop_closure_v2_opts(PDO $pdo) {
    global $wpdb;
    require_once get_stylesheet_directory() . '/inc/facility-v2-writer.php';
    if (!kop_v2_writes_active($pdo, $wpdb->prefix)) {
        throw new RuntimeException('Facility saves are not on facilities_v2 yet; change the status in the data form.');
    }
    return array('pdo' => $pdo, 'prefix' => $wpdb->prefix);
}

/**
 * Set the facility's status from a report. $facility_id overrides the match
 * (for an unmatched report, or a wrong one); $end_year overrides the year
 * taken from the closure date.
 */
function kop_closure_apply(PDO $pdo, $id, $reviewer, $facility_id = 0, $end_year = null) {
    $report = kop_closure_get_report($pdo, $id);
    if (!$report) {
        throw new RuntimeException('No such report.');
    }
    if ($report['status'] === 'applied') {
        throw new RuntimeException('Already confirmed.');
    }
    $fid = (int) $facility_id ?: (int) $report['facility_id'];
    if ($fid <= 0) {
        throw new RuntimeException('Pick the facility first (its id from the facility page or the data form).');
    }
    $opts = kop_closure_v2_opts($pdo);
    $stored = kop_facility_load($fid, $opts);
    if (!$stored) {
        throw new RuntimeException("Facility #{$fid} does not exist.");
    }
    if ($end_year === null && $report['target_status'] === 'Closed' && $report['closure_date']) {
        $end_year = (int) substr($report['closure_date'], 0, 4);
    }
    $note = kop_closure_source_note($report);

    kop_v2_with_write_lock($pdo, function () use ($pdo, $opts, $stored, $report, $end_year, $note, $reviewer, $fid, $id) {
        list($doc, $previous_status, $previous_end) = kop_closure_doc_apply($stored['doc'], $report['target_status'], $end_year, $note);
        kop_facility_save($doc, $opts);

        $pdo->prepare("UPDATE facility_closure_reports
                          SET status = 'applied', facility_id = ?, previous_status = ?, previous_end_year = ?,
                              applied_note = ?, reviewed_by = ?, reviewed_at = UTC_TIMESTAMP()
                        WHERE id = ?")
            ->execute(array($fid, $previous_status, $previous_end, $note, $reviewer, (int) $id));
        // Other open reports of the same change to the same facility are done too.
        $pdo->prepare("UPDATE facility_closure_reports
                          SET status = 'duplicate', reviewed_by = ?, reviewed_at = UTC_TIMESTAMP()
                        WHERE facility_id = ? AND target_status = ? AND status IN ('pending','unmatched') AND id <> ?")
            ->execute(array($reviewer, $fid, $report['target_status'], (int) $id));
    });

    // The article belongs on the facility's page as its source.
    $pdo->prepare("INSERT IGNORE INTO news_facility_links (news_id, facility_id, link_type, created_by) VALUES (?, ?, 'primary', ?)")
        ->execute(array((int) $report['news_id'], $fid, $reviewer));
    do_action('kop_facility_status_changed', $fid);
    return $fid;
}

/** Put back what a confirm changed, and reopen the report. */
function kop_closure_undo(PDO $pdo, $id, $reviewer) {
    $report = kop_closure_get_report($pdo, $id);
    if (!$report || $report['status'] !== 'applied') {
        throw new RuntimeException('Only a confirmed report can be undone.');
    }
    $fid = (int) $report['facility_id'];
    $opts = kop_closure_v2_opts($pdo);
    $stored = kop_facility_load($fid, $opts);
    if (!$stored) {
        throw new RuntimeException("Facility #{$fid} does not exist.");
    }
    kop_v2_with_write_lock($pdo, function () use ($pdo, $opts, $stored, $report, $id) {
        kop_facility_save(kop_closure_doc_undo($stored['doc'], $report), $opts);
        $pdo->prepare("UPDATE facility_closure_reports
                          SET status = 'pending', previous_status = NULL, previous_end_year = NULL, applied_note = NULL,
                              reviewed_by = NULL, reviewed_at = NULL
                        WHERE id = ?")
            ->execute(array((int) $id));
    });
    do_action('kop_facility_status_changed', $fid);
    return $fid;
}

function kop_closure_set_status(PDO $pdo, $id, $status, $reviewer) {
    $pdo->prepare("UPDATE facility_closure_reports SET status = ?, reviewed_by = ?, reviewed_at = UTC_TIMESTAMP()
                    WHERE id = ? AND status <> 'applied'")
        ->execute(array($status, $reviewer, (int) $id));
}

/* ---- Hourly scan ---------------------------------------------------- */

add_action('init', function () {
    if (!wp_next_scheduled('kop_closure_scan_hourly')) {
        wp_schedule_event(time() + 600, 'hourly', 'kop_closure_scan_hourly');
    }
});

add_action('kop_closure_scan_hourly', 'kop_closure_scan_cron');

function kop_closure_scan_cron() {
    if (get_transient('kop_closure_scan_lock')) {
        return;
    }
    set_transient('kop_closure_scan_lock', 1, 10 * MINUTE_IN_SECONDS);
    try {
        $pdo = kop_closure_pdo();
        if (!$pdo) {
            return;
        }
        // Past the keyword gate most rows cost nothing, so a run can look at
        // many; Groq calls are what the time budget is for.
        $result = kop_closure_scan_batch($pdo, 40, 150);
        if ($result['new'] && function_exists('kop_notify_admins')) {
            $fields = array();
            foreach (array_slice($result['new'], 0, 20) as $i => $item) {
                $stages = kop_closure_report_stages();
                $fields['Report ' . ($i + 1)] = $item['report']['program'] . ' - '
                    . $stages[$item['report']['stage']]['label'] . ' - ' . $item['news']['article_title'];
            }
            $n = count($result['new']);
            kop_notify_admins('closure_report', $n . ' facility closure ' . ($n === 1 ? 'report' : 'reports') . ' to review', '', $fields);
        }
    } catch (Throwable $e) {
        error_log('kop closure scan: ' . $e->getMessage());
    } finally {
        delete_transient('kop_closure_scan_lock');
    }
}

/* ---- Review screen -------------------------------------------------- */

add_action('admin_menu', function () {
    if (!function_exists('kop_tools_parent_slug')) {
        return;
    }
    add_submenu_page(
        kop_tools_parent_slug(),
        'Closure Reports',
        'Closure Reports',
        'manage_options',
        'kop-closure-reports',
        'kop_render_closure_reports_page'
    );
}, 21);

function kop_closure_facility_cell(PDO $pdo, $fid) {
    static $cache = array();
    if (!$fid) {
        return '<em>No match</em>';
    }
    if (!isset($cache[$fid])) {
        $stmt = $pdo->prepare('SELECT unique_name, city, state, status, end_year FROM facilities_v2 WHERE id = ?');
        $stmt->execute(array((int) $fid));
        $cache[$fid] = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    $f = $cache[$fid];
    if (!$f) {
        return '#' . (int) $fid . ' <em>(gone)</em>';
    }
    $url = function_exists('kop_facility_page_url') ? kop_facility_page_url((int) $fid) : '';
    $name = esc_html($f['unique_name']);
    $html = $url ? '<a href="' . esc_url($url) . '" target="_blank" rel="noopener"><strong>' . $name . '</strong></a>' : '<strong>' . $name . '</strong>';
    $place = trim(implode(', ', array_filter(array($f['city'], $f['state']))));
    return $html . ' <span style="color:#666">#' . (int) $fid . ($place ? ' &middot; ' . esc_html($place) : '') . '</span>'
        . '<br>Now: <strong>' . esc_html($f['status'] ?: 'Unknown') . '</strong>' . ($f['end_year'] ? ' (ended ' . (int) $f['end_year'] . ')' : '');
}

function kop_render_closure_reports_page() {
    if (!current_user_can('manage_options')) {
        wp_die('Not authorized', 'Access Denied', array('response' => 403));
    }
    $pdo = kop_closure_pdo();
    if (!$pdo) {
        echo '<div class="wrap"><h1>Closure Reports</h1><p>The records database is not reachable.</p></div>';
        return;
    }
    kop_closure_ensure_tables($pdo);
    $statuses = kop_closure_report_statuses();
    $stages = kop_closure_report_stages();
    $user = wp_get_current_user()->user_login;
    $base = admin_url('admin.php?page=kop-closure-reports');

    echo '<div class="wrap"><h1>Closure Reports</h1>';

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['kop_cr_action'])) {
        check_admin_referer('kop_closure_reports');
        $action = sanitize_key($_POST['kop_cr_action']);
        $id = (int) ($_POST['kop_cr_id'] ?? 0);
        $report = $id ? kop_closure_get_report($pdo, $id) : null;
        $about = $report ? '&ldquo;' . esc_html($report['program_name']) . '&rdquo;' : 'the report';
        $status_now = static function ($fid) use ($pdo) {
            $stmt = $pdo->prepare('SELECT status, end_year FROM facilities_v2 WHERE id = ?');
            $stmt->execute(array((int) $fid));
            $f = $stmt->fetch(PDO::FETCH_ASSOC) ?: array('status' => '', 'end_year' => null);
            return '<strong>' . esc_html($f['status'] ?: 'Unknown') . '</strong>' . ($f['end_year'] ? ', ended ' . (int) $f['end_year'] : '');
        };
        try {
            // Messages are built from escaped parts, so they are printed as HTML.
            if ($action === 'apply') {
                // A cleared box is 0: set no end year. No box at all (a
                // Suspended report) leaves it to the closure date, if any.
                $end = isset($_POST['kop_cr_end_year']) ? (int) $_POST['kop_cr_end_year'] : null;
                $fid = kop_closure_apply($pdo, $id, $user, (int) ($_POST['kop_cr_facility'] ?? 0), $end);
                $msg = 'Confirmed. ' . kop_facility_finder_label($pdo, $fid) . ' is now marked ' . $status_now($fid)
                    . '. Its facility page and the network map already show this. The report moved to the <em>Confirmed</em> tab, where Undo puts it back.';
            } elseif ($action === 'undo') {
                $fid = kop_closure_undo($pdo, $id, $user);
                $msg = 'Undone. ' . kop_facility_finder_label($pdo, $fid) . ' is back to ' . $status_now($fid) . ', and the report is waiting again.';
            } elseif ($action === 'dismiss') {
                kop_closure_set_status($pdo, $id, 'dismissed', $user);
                $msg = 'Dismissed the report about ' . $about . '. No facility was changed.';
            } elseif ($action === 'reopen') {
                kop_closure_set_status($pdo, $id, 'pending', $user);
                $msg = 'The report about ' . $about . ' is waiting for review again.';
            } elseif ($action === 'scan') {
                $ids = array_filter(array_map('intval', preg_split('/[\s,]+/', (string) ($_POST['kop_cr_news'] ?? ''))));
                $result = kop_closure_scan_batch($pdo, $ids ? count($ids) : 10, 90, true, $ids);
                $c = $result['counts'];
                $msg = esc_html(sprintf('Scanned %d articles: %d with a closure, %d without, %d not about closures, %d failed.',
                    $c['scanned'], $c['found'], $c['none'], $c['skipped'], $c['error']));
            } else {
                $msg = '';
            }
            if ($msg !== '') {
                echo '<div class="notice notice-success is-dismissible"><p>' . $msg . '</p></div>';
            }
        } catch (Throwable $e) {
            echo '<div class="notice notice-error"><p>' . esc_html($e->getMessage()) . '</p></div>';
        }
    }

    $filter = isset($_GET['cr_status']) ? sanitize_key($_GET['cr_status']) : 'pending';
    if ($filter !== 'all' && !isset($statuses[$filter])) {
        $filter = 'pending';
    }
    $counts = array();
    foreach ($pdo->query('SELECT status, COUNT(*) AS n FROM facility_closure_reports GROUP BY status') as $row) {
        $counts[$row['status']] = (int) $row['n'];
    }
    $waiting = (int) $pdo->query("SELECT COUNT(*) FROM news_submissions n LEFT JOIN news_closure_scans s ON s.news_id = n.id
                                   WHERE s.news_id IS NULL AND n.status NOT IN ('rejected','deleted','promotional')")->fetchColumn();

    echo '<p>Articles that say a facility closed, found by the hourly scan of saved news. Nothing changes on the site until you confirm a report: '
        . 'confirming sets the facility\'s status, fills its end year from the closure date if it has none, cites the article in its operating notes '
        . 'and links the article to it. The facility page and the network map follow at once. Undo puts everything back.</p>';
    echo '<p style="color:#666">' . (int) $waiting . ' saved articles not scanned yet. Check the quote against the article before confirming: '
        . 'a quote marked <strong>not found</strong> could not be matched to the article text.</p>';

    echo '<ul class="subsubsub">';
    $tabs = array_merge($statuses, array('all' => 'All'));
    $i = 0;
    foreach ($tabs as $key => $label) {
        $n = $key === 'all' ? array_sum($counts) : ($counts[$key] ?? 0);
        echo '<li><a href="' . esc_url(add_query_arg('cr_status', $key, $base)) . '"' . ($filter === $key ? ' class="current"' : '') . '>'
            . esc_html($label) . ' (' . (int) $n . ')</a>' . (++$i < count($tabs) ? ' | ' : '') . '</li>';
    }
    echo '</ul><div style="clear:both"></div>';

    echo '<form method="post" style="margin:8px 0 16px">';
    wp_nonce_field('kop_closure_reports');
    echo '<input type="hidden" name="kop_cr_action" value="scan">'
        . '<button type="submit" class="button">Scan the next 10 articles now</button> '
        . '<label style="color:#666">or only these article numbers (optional) <input type="text" name="kop_cr_news" style="width:160px"></label></form>';

    $sql = "SELECT r.*, n.article_title, n.publication_name, n.publication_date, n.article_url, n.status AS news_status
              FROM facility_closure_reports r
         LEFT JOIN news_submissions n ON n.id = r.news_id";
    if ($filter === 'all') {
        $rows = $pdo->query($sql . ' ORDER BY r.id DESC LIMIT 300')->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $stmt = $pdo->prepare($sql . ' WHERE r.status = ? ORDER BY r.id DESC LIMIT 300');
        $stmt->execute(array($filter));
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    if (!$rows) {
        echo '<p>Nothing here.</p></div>';
        return;
    }

    echo '<table class="widefat striped"><thead><tr>'
        . '<th style="width:40px">#</th><th style="width:230px">Facility</th><th style="width:150px">Reported</th>'
        . '<th>Article and quote</th><th style="width:250px">Action</th>'
        . '</tr></thead><tbody>';
    foreach ($rows as $r) {
        $stage = $stages[$r['stage']]['label'] ?? $r['stage'];
        echo '<tr>';
        echo '<td>' . (int) $r['id'] . '</td>';
        echo '<td>' . kop_closure_facility_cell($pdo, (int) $r['facility_id'])
            . '<br><span style="color:#666">Article names: ' . esc_html($r['program_name']) . ($r['location'] ? ' (' . esc_html($r['location']) . ')' : '') . '</span></td>';
        echo '<td><strong>' . esc_html($stage) . '</strong>' . ($r['closure_date'] ? '<br>' . esc_html($r['closure_date']) : '')
            . '<br>Sets: ' . esc_html($r['target_status'])
            . '<br><span style="color:#666">' . esc_html($statuses[$r['status']] ?? $r['status'])
            . ($r['reviewed_by'] ? ' by ' . esc_html($r['reviewed_by']) : '') . '</span></td>';
        echo '<td><a href="' . esc_url($r['article_url']) . '" target="_blank" rel="noopener">' . esc_html($r['article_title'] ?: '(article #' . (int) $r['news_id'] . ')') . '</a>'
            . '<br><span style="color:#666">' . esc_html(trim($r['publication_name'] . ' ' . $r['publication_date'])) . ' &middot; article #' . (int) $r['news_id']
            . ' &middot; ' . esc_html($r['news_status'] ?: 'missing') . '</span>';
        if ($r['news_status'] && !in_array($r['news_status'], array('approved', 'published'), true)) {
            echo '<br><em>This article is not approved yet, so it will not show on the facility page until it is.</em>';
        }
        if ($r['quote']) {
            echo '<blockquote style="margin:6px 0;padding-left:8px;border-left:3px solid ' . ($r['quote_found'] ? '#33A7B5' : '#d63638') . '">'
                . esc_html($r['quote']) . '</blockquote>';
            if (!$r['quote_found']) {
                echo '<strong style="color:#d63638">Quote not found in the article text.</strong>';
            }
        }
        echo '</td><td>';
        $form = static function ($action, $label, $extra = '', $primary = false) use ($r) {
            echo '<form method="post" style="margin:0 0 6px">';
            wp_nonce_field('kop_closure_reports');
            echo '<input type="hidden" name="kop_cr_action" value="' . esc_attr($action) . '">'
                . '<input type="hidden" name="kop_cr_id" value="' . (int) $r['id'] . '">' . $extra
                . '<button type="submit" class="button' . ($primary ? ' button-primary' : ' button-small') . '">' . esc_html($label) . '</button></form>';
        };
        if (in_array($r['status'], array('pending', 'unmatched'), true)) {
            $year = $r['closure_date'] && $r['target_status'] === 'Closed' ? substr($r['closure_date'], 0, 4) : '';
            $form('apply', 'Confirm: mark ' . $r['target_status'],
                '<div style="margin-bottom:6px"><strong>Which facility closed?</strong><br>' . kop_facility_finder_field('kop_cr_facility', $r['facility_id'])
                . '<br><span style="color:#666;font-size:12px">' . ($r['facility_id'] ? 'Filled in with the scan\'s match (left column). Search to change it.' : 'The scan found no match: search for it by name.') . '</span></div>'
                . ($r['target_status'] === 'Closed' ? '<label style="display:block;margin-bottom:4px">End year <input type="number" name="kop_cr_end_year" value="' . esc_attr($year) . '" style="width:70px"></label>' : ''),
                true);
            $form('dismiss', 'Dismiss: not a closure');
        } elseif ($r['status'] === 'applied') {
            $form('undo', 'Undo: restore the old status');
        } else {
            $form('reopen', 'Put back to review');
        }
        echo '</td></tr>';
    }
    echo '</tbody></table></div>';
}
