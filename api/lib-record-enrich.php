<?php
/**
 * Fill in queue rows that arrived as a bare link: news and lawsuits sent from
 * KOP Tools > Drive Docs (inc/drive-docs.php) or the "Send to KOP" browser
 * extension (inc/source-submissions.php) carry only a title, the address and
 * the site. Everything else (author, date, summary, tags, facilities, people;
 * parties, court, claims) is filled here the way the rest of the site fills
 * it:
 *   - news: api/process-news-ai.php reads the article, as the News Processor
 *     page and the nightly discovery do, and api/save-news-submission.php
 *     saves it by id, which reruns the facility, story, lawsuit and journalist
 *     links;
 *   - lawsuits: the document's text goes through the complaint extractor
 *     (api/lawsuit-extraction-lib.php).
 * Only empty fields are filled: the row keeps its status (an approved article
 * stays approved), who sent it, its notes and anything a person already typed.
 *
 * Needs WordPress loaded (kop_seed_pdo, wp_remote_*, options). Used by
 * api/enrich-imported-records.php (CLI) and inc/record-enrich.php (hourly).
 */

if (!defined('ABSPATH')) {
    exit;
}

/** Rows from these senders are ones that skipped the AI step. */
function kop_enrich_sender_where($col) {
    return "({$col} LIKE '%(Google Docs import)%' OR {$col} LIKE '%(browser extension)%')";
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

function kop_enrich_rate_limited($why) {
    return (bool) preg_match('/rate limit|429|too many requests/i', (string) $why);
}

/** True for a title that is only an address or a site name ("sltrib.com", "law.justia.com: 2023 ny slip op"). */
function kop_enrich_placeholder_title($title, $url = '') {
    $t = trim((string) $title);
    return $t === '' || $t === trim((string) $url) || preg_match('#^https?://#i', $t)
        || preg_match('/^[a-z0-9.-]+\.[a-z]{2,}(?::|$)/i', $t);
}

/** True for a site name that is only a domain ("sltrib.com"). */
function kop_enrich_domain_name($name) {
    return (bool) preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/i', trim((string) $name));
}

/* ---- News ---------------------------------------------------------------- */

/** News rows with nothing but a title and a link. */
function kop_enrich_news_ids(PDO $pdo, $limit, array $ids = array()) {
    $sql = "SELECT id FROM news_submissions WHERE status <> 'deleted' AND summary = ''
              AND (author = '' OR author IS NULL) AND publication_date IS NULL AND " . kop_enrich_sender_where('submitted_by');
    $params = array();
    if ($ids) {
        $sql .= ' AND id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
        $params = array_map('intval', $ids);
    }
    $st = $pdo->prepare($sql . ' ORDER BY id LIMIT ' . (int) $limit * 4);
    $st->execute($params);
    $failed = kop_enrich_failed();
    $out = array();
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $id) {
        if (($failed['news:' . $id]['n'] ?? 0) < 2 || $ids) {
            $out[] = (int) $id;
        }
        if (count($out) >= $limit) {
            break;
        }
    }
    return $out;
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
function kop_enrich_news_row(PDO $pdo, $id, $apply) {
    $st = $pdo->prepare('SELECT * FROM news_submissions WHERE id = ?');
    $st->execute(array((int) $id));
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return array('ok' => false, 'id' => $id, 'error' => 'not found');
    }
    $api = get_stylesheet_directory_uri() . '/api/';
    // 'auto': Groq and Gemini take turns, and the other reads the article when one fails.
    $ai = kop_enrich_post_json($api . 'process-news-ai.php', array('url' => $row['article_url'], 'provider' => 'auto', 'customInstructions' => ''), 120);
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

    $title = $row['article_title'];
    if (kop_enrich_placeholder_title($title, $row['article_url']) && trim((string) ($d['title'] ?? '')) !== '') {
        $title = trim($d['title']);
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
    $st = $pdo->prepare($sql . ' ORDER BY id LIMIT ' . (int) $limit * 4);
    $st->execute($params);
    $failed = kop_enrich_failed();
    $out = array();
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $id) {
        if (($failed['lawsuit:' . $id]['n'] ?? 0) < 2 || $ids) {
            $out[] = (int) $id;
        }
        if (count($out) >= $limit) {
            break;
        }
    }
    return $out;
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
        $text = kop_extract_pdf_text($tmp);
        @unlink($tmp);
        return trim($text);
    }
    require_once __DIR__ . '/lib-article-fetch.php';
    return trim((string) fetchArticleContent($url));
}

function kop_enrich_lawsuit_row(PDO $pdo, $id, $apply) {
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
    if ($url === '') {
        return $fail('no source address');
    }
    $text = kop_enrich_document_text($url);
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
    if (kop_enrich_placeholder_title($row['case_name'], $url) && $x['case_name'] !== '') {
        $set['case_name'] = mb_substr($x['case_name'], 0, 500);
        $filled[] = 'case name';
    }
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
