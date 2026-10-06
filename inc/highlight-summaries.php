<?php
/**
 * Plain-language summaries for the serious findings that are hard to read.
 *
 * An approved finding (inc/inspection-highlights.php) prints the state's own
 * words. Some are clear; some are a wall of codes, numbered people, fragments
 * joined with "[...]" and run-on sentences. kop_hs_confusing() picks those
 * (and only those); an hourly job asks the AI for two or three plain
 * sentences about each (Groq and Gemini take turns, api/ai-providers.php);
 * the draft waits in KOP Tools > Review inbox > Plain summaries and goes on
 * the site only when a person approves it. The state's wording always stays
 * under it. A summary belongs to the exact excerpt it was written from
 * (excerpt_hash): when the excerpt changes (a rescan, a same-day merge) the
 * summary stops showing and a new draft is made.
 *
 * Table inspection_highlight_summaries, one row per finding:
 *   highlight_id, excerpt_hash (md5 of the excerpt it summarises), summary,
 *   status (pending, approved, rejected), reasons (why it was picked),
 *   provider, reviewed_by/at, created_at.
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('KOP_HS_DB_VERSION')) {
    define('KOP_HS_DB_VERSION', '1');
}

/** How many words a summary may run to. */
function kop_hs_max_words() {
    return 70;
}

/**
 * Why a finding's text is hard to read: [reason => points] (empty: it reads
 * fine). Works on the text as the site prints it, person labels
 * already spelled out (kop_ih_reader_labels). A finding is picked when its
 * reasons add up to kop_hs_threshold().
 */
function kop_hs_reasons($excerpt) {
    $text = function_exists('kop_ih_reader_labels') ? kop_ih_reader_labels((string) $excerpt) : (string) $excerpt;
    $text = trim(preg_replace('/\s+/u', ' ', strip_tags($text)));
    if ($text === '') return array();
    $points = array();

    // Fragments stitched together: the reader loses the thread at every jump.
    $gaps = substr_count($text, ' [...] ');
    if ($gaps >= 2) $points['pieced together from ' . ($gaps + 1) . ' separate passages'] = 1 + ($gaps >= 4 ? 1 : 0);

    // Abbreviations a reader cannot be expected to know.
    $plain = array('CPS', 'DSS', 'DHS', 'DCF', 'ER', 'ICU', 'CPR', 'MD', 'RN', 'LPN', 'LVN', 'CNA', 'EMS', 'EMT', 'ID', 'TV', 'AM', 'PM', 'US', 'USA',
        'DOB', 'ADHD', 'PTSD', 'HIV', 'STD', 'IV', 'OK', 'FBI', 'ATV', 'GPS', 'DNA', 'ADD', 'OCD', 'CEO', 'II', 'III', 'IV');
    $short = array();
    // Shouted boilerplate ("CONTINUED ON THE NEXT PAGE") is not an abbreviation.
    $quiet = preg_replace('/\b[A-Z]{2,}(?: [A-Z]{2,}){2,}\b/u', ' ', $text);
    if (preg_match_all('/(?<![\w\-])([A-Z]{2,6})s?(?![\w\-])/u', $quiet, $m)) {
        foreach ($m[1] as $a) if (!in_array($a, $plain, true)) $short[$a] = true;
    }
    if (count($short) >= 2) $points['unexplained abbreviations (' . implode(', ', array_slice(array_keys($short), 0, 4)) . ')'] = 1 + (count($short) >= 4 ? 1 : 0);

    // Many people told apart only by number.
    $people = array();
    if (preg_match_all('/\b(?:staff|client|child|youth|resident|foster child|former client|former staff|deceased client|qualified professional|mental health technician|nurse|participant|student)s? ?#?\d{1,2}\b|\[(?:Staff|Child|Youth|Resident|Former client|Former staff|Deceased client|Qualified professional|Mental health technician) \d{1,2}\]/iu', $text, $m)) {
        foreach ($m[0] as $p) $people[mb_strtolower(preg_replace('/[\[\]\s#]+/u', '', $p))] = true;
    }
    if (count($people) >= 3) $points['several people told apart by number'] = 1;

    // Sentences too long to follow, or sentences run together with no full stop.
    $long = 0;
    foreach (preg_split('/(?<=[.!?])\s+|\s\[\.\.\.\]\s/u', $text) as $s) {
        if (str_word_count(preg_replace('/[^\p{L}\p{N}\s\'\-]/u', ' ', $s)) > 55) $long++;
    }
    if ($long) $points['a sentence too long to follow'] = 3;
    $starters = 'The|On|In|At|During|When|After|Staff|Client|Child|Youth|Resident|Interviews?|Review|Reviews|Observations?|Per|According|Documentation|She|He|They|Record|Facility';
    if (preg_match('/\b[a-z]{3,}(?<!\bof)(?<!\bthe)(?<!\band)(?<!\bin)(?<!\bfor)(?<!\bby)(?<!\bwith)(?<!\bto)(?<!\bat)(?<!\bon) (?:' . $starters . ') (?:on |of |with |from |revealed |showed |that |at |in |was |were |staff |the |a )/u', preg_replace('/\[[^\]]*\]/u', '', $text))) {
        $points['sentences run together'] = 2;
    }

    // Legal and form citations.
    $legal = preg_match_all('/\b(?:Section|Title|Division|Chapter|Article) \d|\bHSC\b|\bCFR\b|\bPenal Code\b|\bHealth and Safety Code\b|\bLIC ?\d|\bcivil penalty\b|\bstatute\b|\bregulation\b|\bCCR\b|\bcited\b/iu', $text);
    if ($legal >= 2) $points['legal and form citations'] = 1;

    // Scanner and encoding damage.
    if (preg_match('/\x{FFFD}/u', $text) || preg_match('/(?:\b[A-Za-z]{1,2}\b ){6,}/', $text)) $points['garbled text'] = 3;

    // Length.
    if (mb_strlen($text) > 900) $points['very long'] = 1;

    return $points;
}

/** Points a finding needs to be picked. */
function kop_hs_threshold() {
    return 3;
}

/** True when the text is hard enough to read that a summary is worth writing. */
function kop_hs_confusing($excerpt) {
    return array_sum(kop_hs_reasons($excerpt)) >= kop_hs_threshold();
}

/* ---- Storage ---------------------------------------------------------- */

function kop_hs_pdo() {
    $pdo = function_exists('kop_seed_pdo') ? kop_seed_pdo() : null;
    if ($pdo) $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    return $pdo;
}

function kop_hs_ensure_table(PDO $pdo) {
    if (get_option('kop_hs_db') === KOP_HS_DB_VERSION) return;
    $mysql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
    $pdo->exec("CREATE TABLE IF NOT EXISTS inspection_highlight_summaries (
        highlight_id INT NOT NULL,
        excerpt_hash CHAR(32) NOT NULL,
        summary TEXT NOT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'pending',
        reasons VARCHAR(500) NULL,
        provider VARCHAR(20) NULL,
        reviewed_by VARCHAR(100) NULL,
        reviewed_at DATETIME NULL,
        created_at DATETIME NOT NULL,
        PRIMARY KEY (highlight_id)
    )" . ($mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : ''));
    update_option('kop_hs_db', KOP_HS_DB_VERSION);
}

add_action('admin_init', function () {
    try {
        $pdo = kop_hs_pdo();
        if ($pdo) kop_hs_ensure_table($pdo);
    } catch (Throwable $e) {
        // The next visit tries again.
    }
});

/** What a summary is tied to: the excerpt exactly as stored. */
function kop_hs_hash($excerpt) {
    return md5(trim((string) $excerpt));
}

/**
 * Approved summaries for findings about to be printed. $rows: any rows with
 * 'id' and the stored 'excerpt'. Returns [finding id => summary] for those
 * whose summary was approved and was written from this very excerpt.
 */
function kop_hs_public_summaries(array $rows) {
    global $wpdb;
    $want = array();
    foreach ($rows as $r) {
        if (isset($r['id'], $r['excerpt'])) $want[(int) $r['id']] = kop_hs_hash($r['excerpt']);
    }
    if (!$want || !isset($wpdb) || !is_object($wpdb)) return array();
    $suppress = $wpdb->suppress_errors(true);
    $found = $wpdb->get_results("SELECT highlight_id, excerpt_hash, summary FROM inspection_highlight_summaries
        WHERE status = 'approved' AND highlight_id IN (" . implode(',', array_map('intval', array_keys($want))) . ')', ARRAY_A);
    $wpdb->suppress_errors($suppress);
    $out = array();
    foreach ((array) $found as $f) {
        $id = (int) $f['highlight_id'];
        if (isset($want[$id]) && $want[$id] === $f['excerpt_hash'] && trim((string) $f['summary']) !== '') $out[$id] = trim((string) $f['summary']);
    }
    return $out;
}

/** An approved summary as it prints above the state's words. */
function kop_hs_plain_html($summary, $class = 'kop-plain-summary') {
    $summary = trim((string) $summary);
    if ($summary === '') return '';
    return '<p class="' . esc_attr($class) . '"><strong>In plain words:</strong> ' . esc_html($summary)
        . ' <span class="kop-plain-note">Our summary of the state\'s report. The state\'s own wording follows.</span></p>';
}

/* ---- Picking and writing ----------------------------------------------- */

/**
 * Approved findings that are hard to read and have no current summary (one
 * the AI could not write, or a reviewer rejected, is not written again),
 * most serious and most recent first. Each: id, excerpt, score, state,
 * facility_name, reasons. With $only_ids the confusing test is skipped.
 */
function kop_hs_candidates(PDO $pdo, $limit = 10, array $only_ids = array()) {
    $where = "h.status = 'approved' AND h.score >= " . (int) kop_ih_min_score();
    if ($only_ids) $where .= ' AND h.id IN (' . implode(',', array_map('intval', $only_ids)) . ')';
    $st = $pdo->query("SELECT h.id, h.excerpt, h.score, h.state, f.facility_name, s.excerpt_hash AS have
        FROM inspection_highlights h
        LEFT JOIN inspection_facilities f ON f.id = h.facility_id
        LEFT JOIN inspection_highlight_summaries s ON s.highlight_id = h.id
        WHERE $where
        ORDER BY (h.score >= " . (int) kop_ih_severe_score() . ') DESC, (h.finding_date IS NULL) ASC, h.finding_date DESC, h.id DESC');
    $out = array();
    foreach ($st as $r) {
        if ($r['have'] !== null && $r['have'] === kop_hs_hash($r['excerpt'])) continue;
        $reasons = kop_hs_reasons($r['excerpt']);
        if (!$only_ids && array_sum($reasons) < kop_hs_threshold()) continue;
        $r['reasons'] = $reasons;
        $out[] = $r;
        if (count($out) >= $limit) break;
    }
    return $out;
}

/** The instruction the AI gets. The text is the excerpt with person labels spelled out. */
function kop_hs_prompt($excerpt) {
    $text = kop_ih_reader_labels(trim(preg_replace('/[ \t]+/u', ' ', strip_tags((string) $excerpt))));
    return "You write plain-language summaries for a public website about abuse and neglect in residential programs for young people. "
        . "Below is text copied from a state inspection or investigation report. It is hard to read. Write a summary an ordinary reader can follow.\n\n"
        . "Rules:\n"
        . "- One to three short sentences, at most " . kop_hs_max_words() . " words, in everyday words (about an 8th grade reading level).\n"
        . "- Say who did what to whom, and what the state decided, but only what the text says. Keep \"alleged\" or \"reported\" where the report only says someone claimed it. Give the state's decision (for example that it found the allegation substantiated) only if the text gives it.\n"
        . "- Use only what the text says. Do not add causes, results, reasons or guesses. If the text does not say, leave it out.\n"
        . "- Never use a person's name. Say \"a staff member\", \"a child in the program\", \"the director\". If two people of one kind matter, say \"one staff member\" and \"another staff member\". Words in square brackets such as [Staff 1] or [Child 2] stand for numbered people, not names; do not copy the numbers or the brackets.\n"
        . "- Spell out abbreviations or leave them out. No codes such as S1, C1, FC #3, SP or AV.\n"
        . "- No quotation marks, no introduction, no list, no heading, and do not mention that you are an AI.\n"
        . "- No square brackets in the summary.\n"
        . "- If the text is too garbled or incomplete to tell what happened, use the single word UNCLEAR as the summary.\n\n"
        . "Answer with a JSON object only, in this form: {\"summary\": \"your summary here\"}\n\n"
        . "Text:\n<<<\n" . $text . "\n>>>";
}

/**
 * The AI's answer, made safe to store: one paragraph of plain sentences.
 * Throws RuntimeException when it is not one (empty, UNCLEAR, too long,
 * still has codes or brackets, talks about itself).
 */
function kop_hs_clean_summary($raw) {
    $s = trim((string) $raw);
    // Some answers come wrapped like data: a code fence, or { summary: "..." } / {"summary": "..."}.
    $s = trim(preg_replace('/^```\w*\s*|\s*```$/', '', $s));
    if ($s !== '' && $s[0] === '{') {
        $json = json_decode($s, true);
        if (is_array($json) && isset($json['summary']) && is_string($json['summary'])) {
            $s = $json['summary'];
        } elseif (preg_match('/^\{\s*"?summary"?\s*:\s*(.*?)\s*\}$/su', $s, $m)) {
            $s = $m[1];
        }
    }
    // Numbered people the AI copied from the text become plain words; two of one kind stay "a staff member", which a reviewer reads against the text.
    $s = preg_replace(array('/\[(?:the )?staff(?: person| member)?(?: \d{1,2})?\]/iu', '/\[(?:the )?alleged victim(?: \d{1,2})?\]/iu', '/\[(?:Child|Minor|Student)(?: \d{1,2})?\]/u',
        '/\[Youth(?: \d{1,2})?\]/u', '/\[Resident(?: \d{1,2})?\]/u', '/\[Former client(?: \d{1,2})?\]/u', '/\[Former staff(?: \d{1,2})?\]/u',
        '/\[Deceased client(?: \d{1,2})?\]/u', '/\[Qualified professional(?: \d{1,2})?\]/u', '/\[Mental health technician(?: \d{1,2})?\]/u'),
        array('a staff member', 'the child', 'a child in the program', 'a young person', 'a resident', 'a former client', 'a former staff member',
        'a client who died', 'a qualified professional', 'a mental health technician'), $s);
    $s = preg_replace('/^(?:summary|plain[- ]language summary|in plain words)\s*:\s*/iu', '', $s);
    $s = trim(preg_replace('/\s+/u', ' ', str_replace(array('**', '__', "\u{201C}", "\u{201D}", '"'), '', $s)));
    if ($s === '' || preg_match('/^UNCLEAR\b/i', $s)) throw new RuntimeException('The AI could not tell what happened.');
    if (preg_match('/[\[\]{}<>]|https?:|@/u', $s)) throw new RuntimeException('The summary still has brackets, markup or a link.');
    if (preg_match('/\b(?:[SECYR]\d{1,2}|(?:FC|FS|DC|QP|MHT) ?#?\d|SP|AV)\b/u', $s)) throw new RuntimeException('The summary still has the state\'s codes.');
    if (preg_match('/\b(?:as an AI|language model|I cannot|I can\'t|I am unable|here is|here\'s)\b/iu', $s)) throw new RuntimeException('The AI answered about itself.');
    $words = str_word_count(preg_replace('/[^\p{L}\p{N}\s\'\-]/u', ' ', $s));
    if ($words < 6) throw new RuntimeException('The summary is too short.');
    if ($words > (int) (kop_hs_max_words() * 1.4)) throw new RuntimeException('The summary is too long (' . $words . ' words).');
    return $s;
}

/** One AI call; a test can stand in with $GLOBALS['kop_hs_ai']. */
function kop_hs_ai($prompt) {
    if (!empty($GLOBALS['kop_hs_ai']) && is_callable($GLOBALS['kop_hs_ai'])) return (string) call_user_func($GLOBALS['kop_hs_ai'], $prompt);
    require_once get_stylesheet_directory() . '/api/ai-providers.php';
    return kop_ai_generate_alternating($prompt, array('maxTokens' => 400, 'temperature' => 0.2));
}

/**
 * One summary from the AI. An answer that is refused for its form (codes,
 * brackets, length) gets one more try with the reason; "could not tell what
 * happened" does not.
 */
function kop_hs_draft($excerpt) {
    $prompt = kop_hs_prompt($excerpt);
    try {
        return kop_hs_clean_summary(kop_hs_ai($prompt));
    } catch (RuntimeException $e) {
        if (strpos($e->getMessage(), 'could not tell') !== false) throw $e;
        return kop_hs_clean_summary(kop_hs_ai($prompt . ' ' . "\n\nYour last answer was refused: " . $e->getMessage() . ' Answer again with plain sentences only.'));
    }
}

/** Write or replace the draft for a finding. It waits as 'pending'. */
function kop_hs_store(PDO $pdo, $id, $hash, $summary, array $reasons, $provider) {
    $why = mb_substr(implode('; ', array_keys($reasons)), 0, 500);
    $exists = $pdo->prepare('SELECT status FROM inspection_highlight_summaries WHERE highlight_id = ?');
    $exists->execute(array((int) $id));
    if ($exists->fetchColumn() !== false) {
        $pdo->prepare("UPDATE inspection_highlight_summaries SET excerpt_hash = ?, summary = ?, status = 'pending', reasons = ?, provider = ?,
            reviewed_by = NULL, reviewed_at = NULL, created_at = ? WHERE highlight_id = ?")
            ->execute(array($hash, $summary, $why, (string) $provider, gmdate('Y-m-d H:i:s'), (int) $id));
    } else {
        $pdo->prepare("INSERT INTO inspection_highlight_summaries (highlight_id, excerpt_hash, summary, status, reasons, provider, created_at)
            VALUES (?, ?, ?, 'pending', ?, ?, ?)")
            ->execute(array((int) $id, $hash, $summary, $why, (string) $provider, gmdate('Y-m-d H:i:s')));
    }
}

/** Ask the AI about one finding (a row with id and excerpt) and store the draft. Returns the summary. */
function kop_hs_write(PDO $pdo, array $row) {
    $summary = kop_hs_draft($row['excerpt']);
    kop_hs_store($pdo, (int) $row['id'], kop_hs_hash($row['excerpt']), $summary, kop_hs_reasons($row['excerpt']), (string) ($GLOBALS['kop_ai_last_provider'] ?? ''));
    return $summary;
}

/**
 * Write drafts for up to $limit findings. One the AI cannot summarise is not
 * retried every hour: it is stored as 'rejected' with the reason, and an
 * admin can ask again from its card. Stops early on repeated provider errors
 * (rate limits). Returns counts: written, unclear, errors, left, items.
 */
function kop_hs_run_batch(PDO $pdo, $limit = 6, array $only_ids = array(), $apply = true) {
    kop_hs_ensure_table($pdo);
    $counts = array('written' => 0, 'unclear' => 0, 'errors' => 0, 'left' => 0, 'items' => array());
    $queue = kop_hs_candidates($pdo, $limit + 1000, $only_ids);
    $counts['left'] = max(0, count($queue) - $limit);
    $failures = 0;
    foreach (array_slice($queue, 0, $limit) as $r) {
        try {
            $summary = $apply ? kop_hs_write($pdo, $r) : kop_hs_draft($r['excerpt']);
            $counts['written']++;
            $counts['items'][] = array('id' => (int) $r['id'], 'summary' => $summary);
            $failures = 0;
        } catch (RuntimeException $e) {
            // The AI answered but the answer is no use: park it so the hour's quota is not spent on it again.
            $counts['unclear']++;
            $counts['items'][] = array('id' => (int) $r['id'], 'error' => $e->getMessage());
            if ($apply) {
                kop_hs_store($pdo, (int) $r['id'], kop_hs_hash($r['excerpt']), 'No summary: ' . $e->getMessage(), $r['reasons'], '');
                $pdo->prepare("UPDATE inspection_highlight_summaries SET status = 'rejected', reviewed_by = 'system', reviewed_at = ? WHERE highlight_id = ?")
                    ->execute(array(gmdate('Y-m-d H:i:s'), (int) $r['id']));
            }
        } catch (Throwable $e) {
            $counts['errors']++;
            $counts['items'][] = array('id' => (int) $r['id'], 'error' => $e->getMessage());
            if (++$failures >= 3) break;
        }
    }
    return $counts;
}

/* ---- Hourly job -------------------------------------------------------- */

add_action('init', function () {
    if (!wp_next_scheduled('kop_hs_hourly')) wp_schedule_event(time() + 900, 'hourly', 'kop_hs_hourly');
});
add_action('kop_hs_hourly', 'kop_hs_cron');

function kop_hs_cron() {
    if (get_transient('kop_hs_lock')) return;
    set_transient('kop_hs_lock', 1, 10 * MINUTE_IN_SECONDS);
    try {
        $pdo = kop_hs_pdo();
        if ($pdo) kop_hs_run_batch($pdo, 6);
    } catch (Throwable $e) {
        error_log('kop_hs_cron: ' . $e->getMessage());
    }
    delete_transient('kop_hs_lock');
}
