<?php
/**
 * Fill incomplete news submissions and bare-link lawsuits sent from KOP Tools
 * > Drive Docs (inc/drive-docs.php) or the "Send to KOP" browser extension
 * (inc/source-submissions.php). News rows are enriched once while submitted;
 * lawsuits still target imported rows with no parties. Everything else
 * (author, date, summary, tags, facilities, people; parties, court, claims)
 * is filled here the way the rest of the site fills it:
 *   - news: api/process-news-ai.php reads the article, as the News Processor
 *     page and the nightly discovery do, and api/save-news-submission.php
 *     saves it by id, which reruns the facility, story, lawsuit and journalist
 *     links;
 *   - lawsuits: the document's text goes through the complaint extractor
 *     (api/lawsuit-extraction-lib.php).
 * Only empty fields are filled, except that the extracted article headline
 * replaces the title on an incomplete news submission. The row keeps its
 * status, who sent it, its notes and other information a person already typed.
 *
 * Needs WordPress loaded (kop_seed_pdo, wp_remote_*, options). Used by
 * api/enrich-imported-records.php (CLI) and inc/record-enrich.php (hourly).
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Rows from these senders are ones that skipped the AI step: every Drive Docs
 * source ("admin (Google Docs import)", "admin (SCIAD NET import)", "admin
 * (Fornits import)", ...) and the extension.
 */
function kop_enrich_sender_where($col) {
    return "({$col} LIKE '% import)%' OR {$col} LIKE '%(browser extension)%')";
}

/** Rows tried and failed twice are left for a person: [key => attempts]. */
function kop_enrich_failed() {
    $f = get_option('kop_enrich_failed', array());
    // A rate limit says nothing about the article (older runs recorded them).
    return is_array($f) ? array_filter($f, function ($e) { return !kop_enrich_rate_limited($e['why'] ?? ''); }) : array();
}

function kop_enrich_mark_failed($key, $why) {
    $f = kop_enrich_failed();
    $f[$key] = array('n' => (int) ($f[$key]['n'] ?? 0) + 1, 'why' => mb_substr((string) $why, 0, 300), 'at' => gmdate('c'));
    update_option('kop_enrich_failed', $f, false);
}

function kop_enrich_clear_failed($key) {
    $f = kop_enrich_failed();
    if (isset($f[$key])) {
        unset($f[$key]);
        update_option('kop_enrich_failed', $f, false);
    }
}

/**
 * Is a row with this failure entry worth another try? Twice is enough for
 * most failures. A page that could not be read (archive.today's CAPTCHA, the
 * Wayback Machine rate-limiting the server) may read fine another day, so it
 * is tried once a day, up to five times.
 */
function kop_enrich_due($entry) {
    $n = (int) ($entry['n'] ?? 0);
    if ($n < 2) {
        return true;
    }
    $unreadable = preg_match('/could not fetch|no article text|readable text/i', (string) ($entry['why'] ?? ''));
    return $unreadable && $n < 5 && strtotime((string) ($entry['at'] ?? '')) < time() - DAY_IN_SECONDS;
}

function kop_enrich_rate_limited($why) {
    return (bool) preg_match('/rate limit|429|too many requests/i', (string) $why);
}

/** True for a title that is only an address or a site name ("sltrib.com", "law.justia.com: 2023 ny slip op"). */
function kop_enrich_placeholder_title($title, $url = '') {
    $t = trim((string) $title);
    return $t === '' || $t === trim((string) $url) || preg_match('#^https?://#i', $t)
        || preg_match('/^[a-z0-9.-]+\.[a-z]{2,}(?::|$)/i', $t);
}

/** True for a site name that is only a domain ("sltrib.com", "archive.ph (archived)"). */
function kop_enrich_domain_name($name) {
    return (bool) preg_match('/^[a-z0-9.-]+\.[a-z]{2,}(?: \(archived\))?$/i', trim((string) $name));
}

/** Is a submitted news row missing any of the fields the article reader can fill? */
function kop_enrich_news_needs_backfill(array $row) {
    if (kop_enrich_placeholder_title($row['article_title'] ?? '', $row['article_url'] ?? '')) {
        return true;
    }
    foreach (array('author', 'publication_name', 'publication_date', 'article_location', 'summary') as $field) {
        if (trim((string) ($row[$field] ?? '')) === '') {
            return true;
        }
    }
    if (kop_enrich_domain_name($row['publication_name'] ?? '') || ($row['article_type'] ?? 'general') === 'general') {
        return true;
    }
    foreach (array('tags', 'facilities_mentioned', 'staff_mentioned', 'survivors_mentioned', 'content_warnings') as $field) {
        $list = json_decode((string) ($row[$field] ?? ''), true);
        if (!is_array($list) || !$list) {
            return true;
        }
    }
    return false;
}

/** Has the automatic news backfill already read this row? */
function kop_enrich_news_was_backfilled($json) {
    $data = json_decode((string) $json, true);
    return is_array($data) && !empty($data['_kop_ai_backfilled_at']);
}

/** Prefer the article's extracted headline; retain the existing title if absent. */
function kop_enrich_news_title($current, $extracted) {
    $extracted = trim((string) $extracted);
    return $extracted !== '' ? $extracted : trim((string) $current);
}

/**
 * Queue rows a Fornits lead sent ('news' or 'lawsuit'): [row id => the lead's
 * summary the row was titled with]. That summary says what the forum post
 * said about the link ("Poster links a Tribune story on the 2005 death"),
 * never the article's headline, so while the row still carries it the reading
 * replaces it as it would a bare address. A title a person changed is kept.
 */
function kop_enrich_fornits_titles($target) {
    global $wpdb;
    if (!isset($wpdb) || !function_exists('kop_fornits_items_table') || !get_option('kop_fornits_db')) {
        return array();
    }
    $out = array();
    $rows = $wpdb->get_results('SELECT value, applied FROM ' . kop_fornits_items_table()
        . " WHERE kind = 'lead' AND status = 'applied' AND applied LIKE '%\"via\":\"queue\"%'", ARRAY_A);
    foreach ((array) $rows as $r) {
        $a = json_decode((string) $r['applied'], true);
        $v = json_decode((string) $r['value'], true);
        if (is_array($a) && ($a['target'] ?? '') === $target && (int) ($a['id'] ?? 0) > 0 && trim((string) ($v['summary'] ?? '')) !== '') {
            $out[(int) $a['id']] = (string) $v['summary'];
        }
    }
    return $out;
}

/** Is $title still the Fornits lead summary row $id was sent with? */
function kop_enrich_fornits_title($title, $id, array $leads) {
    $norm = function ($s) { return mb_strtolower(trim(preg_replace('/\s+/u', ' ', (string) $s))); };
    return isset($leads[(int) $id]) && $norm($title) === $norm(mb_substr($leads[(int) $id], 0, 500));
}

/**
 * Rows still titled with their Fornits lead summary, read again for their
 * headline even when the rest is filled. $col is the title column.
 */
function kop_enrich_fornits_ids(PDO $pdo, $table, $col, $target, array $ids) {
    $leads = kop_enrich_fornits_titles($target);
    if ($ids) {
        $leads = array_intersect_key($leads, array_flip(array_map('intval', $ids)));
    }
    if (!$leads) {
        return array();
    }
    $st = $pdo->prepare("SELECT id, `{$col}` FROM {$table} WHERE id IN (" . implode(',', array_fill(0, count($leads), '?')) . ')'
        . ($table === 'lawsuits' ? '' : " AND status = 'submitted'"));
    $st->execute(array_keys($leads));
    $out = array();
    foreach ($st->fetchAll(PDO::FETCH_NUM) as $r) {
        if (kop_enrich_fornits_title($r[1], $r[0], $leads)) {
            $out[] = (int) $r[0];
        }
    }
    return $out;
}

/** The ids query's rows plus the Fornits-titled ones, without those failed twice. */
function kop_enrich_pick_ids(array $found, array $fornits, $prefix, $limit, array $ids) {
    $failed = kop_enrich_failed();
    $out = array();
    foreach (array_unique(array_merge(array_map('intval', $fornits), array_map('intval', $found))) as $id) {
        if (kop_enrich_due($failed[$prefix . $id] ?? null) || $ids) {
            $out[] = $id;
        }
        if (count($out) >= $limit) {
            break;
        }
    }
    return $out;
}

/* ---- News ---------------------------------------------------------------- */

/**
 * Incomplete news rows awaiting review, plus unprocessed Fornits rows whose
 * title is still the lead summary. Explicit IDs allow a deliberate rerun.
 */
function kop_enrich_news_ids(PDO $pdo, $limit, array $ids = array()) {
    $sql = "SELECT id, article_title, article_url, author, publication_name, publication_date,
                   article_type, article_location, tags, facilities_mentioned, staff_mentioned,
                   survivors_mentioned, content_warnings, summary, json_data
            FROM news_submissions WHERE status = 'submitted'";
    $params = array();
    if ($ids) {
        $sql .= ' AND id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
        $params = array_map('intval', $ids);
    }
    // No LIMIT: rows waiting out a failure or already enriched must not starve
    // incomplete rows later in the queue.
    $st = $pdo->prepare($sql . ' ORDER BY id');
    $st->execute($params);
    $found = array();
    $backfilled = array();
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $id = (int) $row['id'];
        $backfilled[$id] = kop_enrich_news_was_backfilled($row['json_data'] ?? '');
        if ($ids || (!$backfilled[$id] && kop_enrich_news_needs_backfill($row))) {
            $found[] = $id;
        }
    }
    $fornits = kop_enrich_fornits_ids($pdo, 'news_submissions', 'article_title', 'news', $ids);
    if (!$ids) {
        $fornits = array_values(array_filter($fornits, function ($id) use ($backfilled) {
            return empty($backfilled[(int) $id]);
        }));
    }
    return kop_enrich_pick_ids($found, $fornits, 'news:', $limit, $ids);
}

function kop_enrich_post_json($url, array $body, $timeout) {
    $res = wp_remote_post($url, array(
        'timeout' => $timeout,
        'user-agent' => 'kids-over-profits-enrich/1.0 (+https://kidsoverprofits.org)',
        'headers' => array('Content-Type' => 'application/json'),
        'body'    => wp_json_encode($body),
    ));
    if (is_wp_error($res)) {
        return array('ok' => false, 'status' => 0, 'body' => array('error' => $res->get_error_message()));
    }
    $decoded = json_decode((string) wp_remote_retrieve_body($res), true);
    return array('ok' => wp_remote_retrieve_response_code($res) < 300, 'status' => wp_remote_retrieve_response_code($res),
        'body' => is_array($decoded) ? $decoded : array('error' => mb_substr((string) wp_remote_retrieve_body($res), 0, 200)));
}

/**
 * Read one news row's article and fill its empty fields. Returns
 * ['ok' => bool, 'id', 'title', 'filled' => [field...], 'error'].
 */
function kop_enrich_news_row(PDO $pdo, $id, $apply, $text = '') {
    $st = $pdo->prepare('SELECT * FROM news_submissions WHERE id = ?');
    $st->execute(array((int) $id));
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return array('ok' => false, 'id' => $id, 'error' => 'not found');
    }
    $api = get_stylesheet_directory_uri() . '/api/';
    // 'auto': Groq and Gemini take turns, and the other reads the article when one fails.
    // $text: the article as pasted by a reviewer (a Drive file or paywall the reader cannot open).
    $text = trim((string) $text);
    $ai = kop_enrich_post_json($api . 'process-news-ai.php', array('url' => $row['article_url'], 'articleText' => $text, 'provider' => 'auto', 'customInstructions' => ''), 120);
    if (!$ai['ok'] || empty($ai['body']['success']) || !is_array($ai['body']['data'] ?? null)) {
        $why = (string) ($ai['body']['error'] ?? ('HTTP ' . $ai['status']));
        if (kop_enrich_rate_limited($why)) {
            // Not the article's fault: try it again on a later run.
            return array('ok' => false, 'id' => $id, 'error' => 'AI: ' . mb_substr($why, 0, 200), 'rate_limited' => true);
        }
        if ($apply) {
            kop_enrich_mark_failed('news:' . $id, $why);
        }
        return array('ok' => false, 'id' => $id, 'error' => 'AI: ' . mb_substr($why, 0, 200));
    }
    $d = $ai['body']['data'];
    if ($apply) {
        $latestStmt = $pdo->prepare('SELECT * FROM news_submissions WHERE id = ?');
        $latestStmt->execute(array((int) $id));
        $latest = $latestStmt->fetch(PDO::FETCH_ASSOC);
        if (!$latest || $latest['status'] !== 'submitted') {
            return array('ok' => false, 'id' => $id, 'error' => 'submission is no longer awaiting review');
        }
        if ((string) $latest['article_url'] !== (string) $row['article_url']) {
            return array('ok' => false, 'id' => $id, 'error' => 'article URL changed while AI was reading it; run backfill again');
        }
        $row = $latest;
    }
    $list = function ($v) {
        if (is_string($v)) {
            $v = preg_split('/\r?\n/', $v);
        }
        return array_values(array_filter(array_map('trim', array_filter((array) $v, 'is_string')), 'strlen'));
    };
    $old_json = json_decode((string) $row['json_data'], true) ?: array();
    $old_list = function ($col) use ($row) {
        $v = json_decode((string) $row[$col], true);
        return is_array($v) ? $v : array();
    };
    $filled = array();
    $pick = function ($field, $old, $new) use (&$filled) {
        if ((is_array($old) ? !$old : trim((string) $old) === '') && !empty($new)) {
            $filled[] = $field;
            return $new;
        }
        return $old;
    };

    $oldTitle = trim((string) $row['article_title']);
    $title = kop_enrich_news_title($oldTitle, $d['title'] ?? '');
    if ($title !== $oldTitle) {
        $filled[] = 'title';
    }
    $outlet = $row['publication_name'];
    if ((trim((string) $outlet) === '' || kop_enrich_domain_name($outlet)) && trim((string) ($d['publicationName'] ?? '')) !== '') {
        $outlet = trim($d['publicationName']);
        $filled[] = 'publication';
    }
    // The facility the doc tied it to stays; the article's own mentions join it.
    $facilities = $old_list('facilities_mentioned');
    $names = array();
    foreach ($facilities as $f) {
        $names[mb_strtolower(is_array($f) ? (string) ($f['name'] ?? '') : (string) $f)] = true;
    }
    foreach ($list($d['facilities'] ?? array()) as $n) {
        if (!isset($names[mb_strtolower($n)])) {
            $facilities[] = $n;
            $names[mb_strtolower($n)] = true;
            $filled[] = 'facilities';
        }
    }
    $type = $row['article_type'] === 'general' && !empty($d['articleType']) ? $d['articleType'] : $row['article_type'];

    $payload = array_merge($old_json, is_array($d['typeSpecificData'] ?? null) ? $d['typeSpecificData'] : array(), array(
        'id'              => (int) $row['id'],
        'title'           => $title,
        'alternateTitle'  => $pick('alternate title', $row['alternate_title'], (string) ($d['alternateTitle'] ?? '')),
        'author'          => $pick('author', $row['author'], (string) ($d['author'] ?? '')),
        'publicationName' => $outlet,
        'publicationDate' => $pick('date', (string) $row['publication_date'], (string) ($d['publicationDate'] ?? '')),
        'url'             => $row['article_url'],
        'location'        => $pick('location', $row['article_location'], (string) ($d['location'] ?? '')),
        'articleType'     => $type,
        'tags'            => $pick('tags', $old_list('tags'), $list($d['tags'] ?? array())),
        'facilities'      => $facilities,
        'staff'           => $pick('staff', $old_list('staff_mentioned'), $list($d['staff'] ?? array())),
        'survivors'       => $pick('survivors', $old_list('survivors_mentioned'), $list($d['survivors'] ?? array())),
        'contentWarnings' => $pick('content warnings', $old_list('content_warnings'), (array) ($d['contentWarnings'] ?? array())),
        'summary'         => $pick('summary', $row['summary'], (string) ($d['summary'] ?? '')),
        'generatedOutput' => (string) $row['generated_output'],
        'status'          => $row['status'],
        'submittedBy'     => $row['submitted_by'],
        'submissionNotes' => $row['submission_notes'],
    ));
    if ($apply) {
        $payload['_kop_ai_backfilled_at'] = gmdate('c');
    }
    $filled = array_values(array_unique($filled));
    if (!$apply) {
        return array('ok' => true, 'id' => $id, 'title' => $title, 'filled' => $filled);
    }
    $saved = kop_enrich_post_json($api . 'save-news-submission.php', $payload, 60);
    if (!$saved['ok'] || empty($saved['body']['success'])) {
        $why = (string) ($saved['body']['error'] ?? ('HTTP ' . $saved['status']));
        kop_enrich_mark_failed('news:' . $id, 'save: ' . $why);
        return array('ok' => false, 'id' => $id, 'error' => 'save: ' . mb_substr($why, 0, 200));
    }
    kop_enrich_clear_failed('news:' . $id);
    return array('ok' => true, 'id' => $id, 'title' => $title, 'filled' => $filled);
}

/* ---- Lawsuits ------------------------------------------------------------ */

/** Pending lawsuit rows with no parties yet. */
function kop_enrich_lawsuit_ids(PDO $pdo, $limit, array $ids = array()) {
    $sql = "SELECT id FROM lawsuits WHERE publication_status = 'pending' AND plaintiffs IN ('[]', '') AND defendants IN ('[]', '')
              AND " . kop_enrich_sender_where('submitted_by');
    $params = array();
    if ($ids) {
        $sql .= ' AND id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
        $params = array_map('intval', $ids);
    }
    // No LIMIT: rows waiting out a failure would fill it and starve the rest.
    $st = $pdo->prepare($sql . ' ORDER BY id');
    $st->execute($params);
    return kop_enrich_pick_ids($st->fetchAll(PDO::FETCH_COLUMN),
        kop_enrich_fornits_ids($pdo, 'lawsuits', 'case_name', 'lawsuit', $ids), 'lawsuit:', $limit, $ids);
}

/** A page or PDF as plain text ('' when nothing readable came back). */
function kop_enrich_document_text($url) {
    $res = wp_remote_get($url, array('timeout' => 45, 'redirection' => 5,
        'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36'));
    $type = is_wp_error($res) ? '' : (string) wp_remote_retrieve_header($res, 'content-type');
    $body = is_wp_error($res) ? '' : (string) wp_remote_retrieve_body($res);
    if ($body !== '' && (stripos($type, 'pdf') !== false || strncmp($body, '%PDF', 4) === 0)) {
        $tmp = wp_tempnam('kop-enrich.pdf');
        file_put_contents($tmp, $body);
        // Ghostscript is on the host (no pdftotext); the PHP reader is the fallback.
        $text = '';
        if (function_exists('shell_exec') && @is_executable('/usr/bin/gs')) {
            $text = (string) @shell_exec('timeout 120 /usr/bin/gs -q -dNOPAUSE -dBATCH -dSAFER -sDEVICE=txtwrite '
                . '-dFirstPage=1 -dLastPage=40 -sOutputFile=- ' . escapeshellarg($tmp) . ' 2>/dev/null');
            $text = preg_replace('/[ 	]+/', ' ', $text) ?? $text;
        }
        if (mb_strlen(trim($text)) < 400) {
            $text = kop_extract_pdf_text($tmp);
        }
        @unlink($tmp);
        return trim($text);
    }
    require_once __DIR__ . '/lib-article-fetch.php';
    $text = trim((string) fetchArticleContent($url));
    if (mb_strlen($text) < 400) {
        // Case-law sites (Justia, Casemine) refuse servers: read their Wayback copy.
        $archived = trim((string) fetchFromArchiveOrg($url));
        if (mb_strlen($archived) > mb_strlen($text)) {
            $text = $archived;
        }
    }
    return $text;
}

function kop_enrich_lawsuit_row(PDO $pdo, $id, $apply, $text = '') {
    require_once __DIR__ . '/lawsuit-extraction-lib.php';
    $st = $pdo->prepare('SELECT * FROM lawsuits WHERE id = ?');
    $st->execute(array((int) $id));
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return array('ok' => false, 'id' => $id, 'error' => 'not found');
    }
    $urls = json_decode((string) $row['source_urls'], true) ?: array();
    $url = (string) ($urls[0] ?? '');
    $fail = function ($why) use ($apply, $id) {
        if ($apply) {
            kop_enrich_mark_failed('lawsuit:' . $id, $why);
        }
        return array('ok' => false, 'id' => $id, 'error' => $why);
    };
    // $text: the complaint as pasted by a reviewer, read instead of the source address.
    $text = trim((string) $text);
    if ($text === '') {
        if ($url === '') {
            return $fail('no source address');
        }
        $text = kop_enrich_document_text($url);
    }
    if (mb_strlen($text) < 400) {
        return $fail('the page gave no readable text (' . mb_strlen($text) . ' characters)');
    }
    if (!kop_ai_alternating_providers()) {
        return $fail('no Groq or Gemini key');
    }
    $parts = kop_lawsuit_chunk_text($text, 6);
    $results = array();
    foreach ($parts['chunks'] as $i => $chunk) {
        $r = kop_lawsuit_ai_call($chunk, $i + 1, count($parts['chunks']));
        if (!$r['ok'] && kop_enrich_rate_limited($r['error'])) {
            sleep(60);
            $r = kop_lawsuit_ai_call($chunk, $i + 1, count($parts['chunks']));
        }
        if (!$r['ok']) {
            return kop_enrich_rate_limited($r['error'])
                ? array('ok' => false, 'id' => $id, 'error' => $r['error'], 'rate_limited' => true)
                : $fail($r['error']);
        }
        $results[] = $r['data'];
    }
    $x = kop_normalize_lawsuit_extraction(kop_merge_chunk_extractions($results));

    $set = array();
    $filled = array();
    $from_lead = kop_enrich_fornits_title($row['case_name'], $id, kop_enrich_fornits_titles('lawsuit'));
    if ((kop_enrich_placeholder_title($row['case_name'], $url) || $from_lead) && $x['case_name'] !== '') {
        $set['case_name'] = mb_substr($x['case_name'], 0, 500);
        $filled[] = 'case name';
    }
    $headless = $from_lead && !isset($set['case_name']) ? 'the document gave no case name; retitle it in the lawsuits editor' : '';
    foreach (array('case_number', 'court', 'jurisdiction', 'summary', 'outcome', 'settlement_amount') as $f) {
        if (trim((string) $row[$f]) === '' && $x[$f] !== '') {
            $set[$f] = $x[$f];
            $filled[] = str_replace('_', ' ', $f);
        }
    }
    if (empty($row['filing_date']) && $x['filing_date'] !== '') {
        $set['filing_date'] = $x['filing_date'];
        $filled[] = 'filing date';
    }
    foreach (array('plaintiffs', 'defendants', 'staff_mentioned', 'organizations_mentioned', 'claims', 'facilities_mentioned', 'tags', 'document_urls') as $f) {
        $old = json_decode((string) $row[$f], true);
        $old = is_array($old) ? $old : array();
        $merged = array_values(array_unique(array_merge($old, $x[$f])));
        if (count($merged) > count($old)) {
            $set[$f] = wp_json_encode($merged, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $filled[] = str_replace('_', ' ', $f);
        }
    }
    if ($apply && $set) {
        $cols = implode(', ', array_map(function ($c) { return "`{$c}` = ?"; }, array_keys($set)));
        $pdo->prepare("UPDATE lawsuits SET {$cols} WHERE id = ?")->execute(array_merge(array_values($set), array((int) $id)));
        if (isset($set['facilities_mentioned']) && function_exists('kop_sync_lawsuit_facility_links')) {
            kop_sync_lawsuit_facility_links($pdo, (int) $id, $set['facilities_mentioned'], 'enrich');
        }
    }
    if ($headless !== '') {
        return $fail($headless) + array('filled' => $filled);
    }
    if ($apply) {
        kop_enrich_clear_failed('lawsuit:' . $id);
    }
    return array('ok' => true, 'id' => $id, 'title' => $set['case_name'] ?? $row['case_name'], 'filled' => $filled);
}

/**
 * Fill up to $limit rows of $type ('news', 'lawsuit') within $seconds.
 * Returns the per-row results.
 */
function kop_enrich_run(PDO $pdo, $type, $limit, $seconds, $apply, array $ids = array()) {
    $start = time();
    $rows = $type === 'lawsuit' ? kop_enrich_lawsuit_ids($pdo, $limit, $ids) : kop_enrich_news_ids($pdo, $limit, $ids);
    $out = array();
    $limited = 0;
    foreach ($rows as $i => $id) {
        if (time() - $start > $seconds) {
            break;
        }
        if ($i) {
            sleep(12); // Groq's and Gemini's per-minute limits: each article is a few thousand tokens
        }
        $r = $type === 'lawsuit' ? kop_enrich_lawsuit_row($pdo, $id, $apply) : kop_enrich_news_row($pdo, $id, $apply);
        if (!empty($r['rate_limited'])) {
            // Wait the minute out and try the same row once more.
            if (time() - $start + 75 > $seconds) {
                $out[] = $r;
                break;
            }
            sleep(65);
            $r = $type === 'lawsuit' ? kop_enrich_lawsuit_row($pdo, $id, $apply) : kop_enrich_news_row($pdo, $id, $apply);
            $limited = !empty($r['rate_limited']) ? $limited + 1 : 0;
        }
        $out[] = $r;
        if ($limited >= 3) {
            break; // both providers keep refusing: stop until the next run
        }
    }
    return $out;
}
