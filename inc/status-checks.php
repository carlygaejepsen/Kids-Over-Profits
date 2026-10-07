<?php
/**
 * Nightly status checks for bills (legislation) and lawsuits.
 *
 * Every published bill and lawsuit that is not finished yet is checked about
 * once a day against its source:
 *   - federal bills: govinfo's BILLSTATUS XML (no AI; public laws, vetoes,
 *     floor passage and the latest action are read straight from it);
 *   - state bills: the bill's official page (California through CalMatters'
 *     Digital Democracy, since leginfo blocks the server), read by the AI;
 *   - lawsuits: the CourtListener docket (federal cases found by docket
 *     number + first party + the court's state; its feed lists the newest
 *     entries), else a docket link on the record, read by the AI.
 * A difference becomes a proposal (record_status_proposals) that waits at
 * Review inbox > Bill and lawsuit updates: nothing changes a record until a
 * person applies it, and Undo puts back exactly what was there. A dismissed
 * proposal is never made again; a newer one replaces a waiting older one.
 *
 * Runs on WP-Cron once a night and keeps going in short runs until every due
 * record is checked. CLI: api/check-record-statuses.php. Test:
 * scripts/test-status-checks.php (fixtures, a made-up AI, a copy of the DB).
 */

if (!defined('ABSPATH')) {
    exit;
}

const KOP_STATUS_CHECKS_DB_VERSION = '1';

/** Bills in these statuses are done; vetoed ones are watched 120 days for an override. */
function kop_sc_final_bill_statuses() {
    return array('enacted', 'dead');
}
function kop_sc_final_lawsuit_statuses() {
    return array('settled', 'closed');
}
function kop_sc_lawsuit_statuses() {
    return array('filed', 'in_progress', 'settled', 'dismissed', 'ruling', 'appeal', 'closed', 'unknown');
}
function kop_sc_bill_statuses() {
    return function_exists('kop_legislation_statuses') ? kop_legislation_statuses()
        : array('proposed', 'introduced', 'in_committee', 'passed_house', 'passed_senate', 'signed', 'vetoed', 'dead', 'enacted', 'unknown');
}

/** Which table and which fields a proposal may change, per kind. */
function kop_sc_kinds() {
    return array(
        'bill'    => array('table' => 'legislation', 'label' => 'Bill', 'fields' => array('status', 'last_action_date', 'last_action_text')),
        'lawsuit' => array('table' => 'lawsuits', 'label' => 'Lawsuit', 'fields' => array('status', 'outcome', 'settlement_amount')),
    );
}

function kop_sc_pdo() {
    $pdo = function_exists('kop_seed_pdo') ? kop_seed_pdo() : null;
    if ($pdo) {
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }
    return $pdo;
}

function kop_sc_now() {
    return gmdate('Y-m-d H:i:s');
}

function kop_sc_ensure_tables(PDO $pdo) {
    if (get_option('kop_status_checks_db') === KOP_STATUS_CHECKS_DB_VERSION) {
        return;
    }
    $pdo->exec("CREATE TABLE IF NOT EXISTS record_status_sources (
        kind VARCHAR(10) NOT NULL,
        record_id INT NOT NULL,
        source_url VARCHAR(1000) NULL,
        source_ref VARCHAR(255) NULL,
        last_checked DATETIME NULL,
        last_outcome VARCHAR(20) NULL,
        last_detail TEXT NULL,
        last_hash CHAR(32) NULL,
        PRIMARY KEY (kind, record_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS record_status_proposals (
        id INT NOT NULL AUTO_INCREMENT,
        kind VARCHAR(10) NOT NULL,
        record_id INT NOT NULL,
        changes TEXT NOT NULL,
        change_hash CHAR(32) NOT NULL,
        quote TEXT NULL,
        quote_found TINYINT(1) NOT NULL DEFAULT 0,
        source_url VARCHAR(1000) NULL,
        source_label VARCHAR(100) NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'pending',
        previous TEXT NULL,
        reviewed_by VARCHAR(100) NULL,
        reviewed_at DATETIME NULL,
        created_at DATETIME NOT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY one_change (kind, record_id, change_hash),
        KEY status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    update_option('kop_status_checks_db', KOP_STATUS_CHECKS_DB_VERSION);
}

/* ---- Fetching ------------------------------------------------------- */

/** Raw body of a URL (GET), or '' on failure. Tests set $GLOBALS['kop_sc_fetch_stub']. */
function kop_sc_fetch($url, $timeout = 30) {
    if (!empty($GLOBALS['kop_sc_fetch_stub']) && is_callable($GLOBALS['kop_sc_fetch_stub'])) {
        return (string) call_user_func($GLOBALS['kop_sc_fetch_stub'], $url);
    }
    if (!function_exists('curl_init')) {
        return '';
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
        CURLOPT_HTTPHEADER => array('Accept-Language: en-US,en;q=0.9'),
    ));
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ($body !== false && $code >= 200 && $code < 300) ? (string) $body : '';
}

/** HTML to readable text: scripts and styles dropped, rows and blocks on their own lines. */
function kop_sc_html_text($html, $max = 14000) {
    $html = preg_replace('#<(script|style|noscript|svg|head)\b.*?</\1>#is', ' ', (string) $html);
    $html = preg_replace('#<(br|/p|/div|/tr|/li|/h\d|/td|/th|/entry|/title)\b[^>]*>#i', "\n", $html);
    $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = preg_replace("/[ \t\x{00A0}]+/u", ' ', $text);
    $text = trim(preg_replace("/\s*\n\s*/", "\n", $text));
    if (strlen($text) > $max) {
        $text = mb_strcut($text, 0, $max, 'UTF-8') . ' [truncated]';
    }
    return $text;
}

/** Ask the AI (Groq and Gemini in turn). Tests set $GLOBALS['kop_sc_ai_stub']. */
function kop_sc_ai($prompt) {
    if (!empty($GLOBALS['kop_sc_ai_stub']) && is_callable($GLOBALS['kop_sc_ai_stub'])) {
        return (string) call_user_func($GLOBALS['kop_sc_ai_stub'], $prompt);
    }
    require_once get_stylesheet_directory() . '/api/ai-providers.php';
    return kop_ai_generate_alternating($prompt, array('maxTokens' => 1024));
}

/** The first JSON object in an AI reply, or null. */
function kop_sc_parse_json($reply) {
    $reply = preg_replace('/^```(?:json)?|```$/m', '', trim((string) $reply));
    $start = strpos($reply, '{');
    $end = strrpos($reply, '}');
    if ($start === false || $end === false || $end < $start) {
        return null;
    }
    $data = json_decode(substr($reply, $start, $end - $start + 1), true);
    return is_array($data) ? $data : null;
}

function kop_sc_norm($s) {
    $s = mb_strtolower(html_entity_decode((string) $s, ENT_QUOTES | ENT_HTML5, 'UTF-8'), 'UTF-8');
    $s = str_replace(array("\u{2018}", "\u{2019}", "\u{201C}", "\u{201D}", "\u{2013}", "\u{2014}"), array("'", "'", '"', '"', '-', '-'), $s);
    return trim(preg_replace('/\s+/u', ' ', $s));
}

/** Does the quote (or its first 80 characters) appear in the source text? */
function kop_sc_quote_found($quote, $text) {
    $q = kop_sc_norm($quote);
    if ($q === '') {
        return false;
    }
    $t = kop_sc_norm($text);
    return strpos($t, $q) !== false || (strlen($q) > 80 && strpos($t, mb_substr($q, 0, 80)) !== false);
}

function kop_sc_clean_date($raw) {
    $raw = trim((string) $raw);
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $raw, $m) && checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
        return $m[1] . '-' . $m[2] . '-' . $m[3];
    }
    $ts = $raw !== '' ? strtotime($raw) : false;
    return $ts ? gmdate('Y-m-d', $ts) : '';
}

/* ---- Federal bills: govinfo BILLSTATUS ------------------------------ */

/** [congress, type, number] for a federal bill, or null. Type is govinfo's: hr, s, hjres, sjres, hres, sres, hconres, sconres. */
function kop_sc_federal_bill_ref(array $bill) {
    $num = strtolower(preg_replace('/[\s.]+/', '', (string) $bill['bill_number']));
    if (!preg_match('/^(hr|s|hjres|sjres|hres|sres|hconres|sconres)(\d+)$/', $num, $m)) {
        return null;
    }
    $congress = 0;
    if (preg_match('/(\d{2,3})(st|nd|rd|th)\s+Congress/i', (string) $bill['session_year'] . ' ' . (string) $bill['bill_title'], $c)) {
        $congress = (int) $c[1];
    } elseif (preg_match('/\b(19[7-9]\d|20\d\d)\b/', (string) $bill['session_year'], $y)) {
        $congress = (int) floor(((int) $y[1] - 1789) / 2) + 1;
    }
    return $congress >= 93 ? array($congress, $m[1], (int) $m[2]) : null;
}

function kop_sc_current_congress() {
    return (int) floor(((int) gmdate('Y') - 1789) / 2) + 1;
}

/**
 * What a BILLSTATUS document says now: status, last_action_date,
 * last_action_text, quote. Null when the XML is unreadable.
 */
function kop_sc_billstatus_facts($xml, $congress) {
    if (trim((string) $xml) === '') {
        return null;
    }
    $prev = libxml_use_internal_errors(true);
    $doc = simplexml_load_string((string) $xml);
    libxml_use_internal_errors($prev);
    if (!$doc || !isset($doc->bill)) {
        return null;
    }
    $b = $doc->bill;
    $origin = strtolower((string) $b->originChamber);
    $actions = array();
    foreach ($b->actions->item ?? array() as $it) {
        $actions[] = array('date' => (string) $it->actionDate, 'text' => trim((string) $it->text), 'type' => (string) $it->type);
    }
    $laws = array();
    foreach ($b->laws->item ?? array() as $it) {
        $laws[] = trim((string) $it->type . ' ' . (string) $it->number);
    }
    $all = implode("\n", array_column($actions, 'text'));
    $passedHouse = (bool) preg_match('#Passed/agreed to in House|On passage Passed#i', $all);
    $passedSenate = (bool) preg_match('#Passed/agreed to in Senate|Passed Senate#i', $all);
    if ($laws) {
        $status = 'enacted';
    } elseif (preg_match('/Vetoed by President|Pocket Vetoed/i', $all)) {
        $status = 'vetoed';
    } elseif ((int) $congress < kop_sc_current_congress()) {
        $status = 'dead';
    } elseif ($passedHouse && $passedSenate) {
        $status = $origin === 'senate' ? 'passed_house' : 'passed_senate';
    } elseif ($passedHouse) {
        $status = 'passed_house';
    } elseif ($passedSenate) {
        $status = 'passed_senate';
    } elseif (preg_match('/Referred to|Committee/i', $all)) {
        $status = 'in_committee';
    } else {
        $status = 'introduced';
    }
    $date = kop_sc_clean_date((string) $b->latestAction->actionDate);
    $text = trim((string) $b->latestAction->text);
    if ($status === 'dead' && $text !== '' && stripos($text, 'Congress') === false) {
        $text .= ' (No further action; the ' . (int) $congress . 'th Congress ended.)';
    }
    if ($laws && stripos($text, 'Public Law') === false) {
        $text .= ' (' . implode(', ', $laws) . ')';
    }
    return array('status' => $status, 'last_action_date' => $date, 'last_action_text' => $text,
                 'quote' => trim((string) $b->latestAction->text), 'source_text' => $all);
}

/* ---- State bills: the official page, read by the AI ----------------- */

/**
 * The page to read for a state bill, where the official one blocks the server: California's leginfo through
 * CalMatters, New York's Senate site (Cloudflare) through the Assembly's bill page, Ohio's site (no answer)
 * through the legislature's own data service. Else the official page.
 */
function kop_sc_bill_source_url(array $bill) {
    foreach (array((string) $bill['official_url'], (string) $bill['full_text_url']) as $url) {
        if (preg_match('#leginfo\.legislature\.ca\.gov/.*bill_id=(\d{9}[A-Z]+\d+)#i', $url, $m)) {
            return 'https://calmatters.digitaldemocracy.org/bills/ca_' . strtolower($m[1]);
        }
        if (preg_match('#nysenate\.gov/legislation/bills/(\d{4})/([A-Z])0*(\d+)#i', $url, $m)) {
            return 'https://nyassembly.gov/leg/?default_fld=&leg_video=&bn=' . strtoupper($m[2]) . str_pad($m[3], 5, '0', STR_PAD_LEFT)
                . '&term=' . $m[1] . '&Summary=Y&Actions=Y';
        }
        if (preg_match('#legislature\.ohio\.gov/legislation/(\d{3})/([a-z]+\d+)#i', $url, $m)) {
            return 'https://search-prod.lis.state.oh.us/api/v2/general_assembly_' . $m[1] . '/legislation/' . strtolower($m[2]) . '/actions/';
        }
    }
    $url = trim((string) $bill['official_url']);
    return preg_match('#^https?://#i', $url) ? $url : '';
}

/** Ohio's actions list (JSON) as lines the AI reads, newest first, with the signing and effective dates from the bill itself. */
function kop_sc_ohio_text($json, $url) {
    $actions = json_decode((string) $json, true);
    if (!is_array($actions)) {
        return '';
    }
    $lines = array();
    foreach ($actions as $a) {
        if (!is_array($a)) continue;
        $lines[] = substr((string) ($a['occurred'] ?? ''), 0, 10) . ' ' . ($a['chamber'] ?? '') . ': ' . ($a['description'] ?? $a['action'] ?? '')
            . (!empty($a['cmte_lpid']) ? ' (committee ' . preg_replace('/^cmte_[hs]_|_\d+$/', '', (string) $a['cmte_lpid']) . ')' : '');
    }
    rsort($lines);
    $bill = json_decode(kop_sc_fetch(preg_replace('#actions/?$#', '', $url)), true);
    $b = is_array($bill) && isset($bill[0]) ? $bill[0] : array();
    $head = 'Ohio ' . ($b['name'] ?? '') . ': ' . ($b['short_title'] ?? '') . "
Version: " . ($b['version'] ?? '')
        . "
Governor signed: " . ($b['governor_signed_date'] ?: 'no') . "
Effective date: " . ($b['effective_date'] ?: 'none');
    return $lines ? $head . "
Actions, newest first:
" . implode("
", $lines) . "
" . str_repeat(' ', 120) : '';
}

function kop_sc_bill_prompt(array $bill, $text) {
    $statuses = implode(', ', array_diff(kop_sc_bill_statuses(), array('unknown', 'proposed')));
    return "You check whether a bill's status has changed. Below is what our record says, then the text of the bill's page on the legislature's (or a bill tracker's) website.\n\n"
        . "OUR RECORD\nBill: {$bill['jurisdiction']} {$bill['bill_number']} ({$bill['session_year']}): {$bill['bill_title']}\n"
        . "Status: {$bill['status']}\nLast action date: " . ($bill['last_action_date'] ?: 'none') . "\nLast action: {$bill['last_action_text']}\n\n"
        . "PAGE TEXT\n" . $text . "\n\n"
        . "Answer with JSON only, no other text:\n"
        . "{\"same_bill\": true or false (is the page about this bill and session?),\n"
        . " \"status\": one of: $statuses (enacted = it became law: signed, chaptered, act or public law number, or law without signature; dead = failed, died in committee, or the session ended without passage; passed_house / passed_senate = the latest chamber that passed it; assembly counts as house),\n"
        . " \"last_action_date\": \"YYYY-MM-DD\" of the most recent action on the page,\n"
        . " \"last_action_text\": \"one plain sentence describing that action, with the chapter or act number if it became law\",\n"
        . " \"quote\": \"the exact words from the page text that show that most recent action, copied character for character\"}\n"
        . "Use only the page text. If the page does not show the bill's history, answer {\"same_bill\": false}.";
}

/* ---- Lawsuits: CourtListener ---------------------------------------- */

function kop_sc_state_names() {
    return array('Alabama', 'Alaska', 'Arizona', 'Arkansas', 'California', 'Colorado', 'Connecticut', 'Delaware', 'District of Columbia', 'Florida',
        'Georgia', 'Hawaii', 'Idaho', 'Illinois', 'Indiana', 'Iowa', 'Kansas', 'Kentucky', 'Louisiana', 'Maine', 'Maryland', 'Massachusetts',
        'Michigan', 'Minnesota', 'Mississippi', 'Missouri', 'Montana', 'Nebraska', 'Nevada', 'New Hampshire', 'New Jersey', 'New Mexico',
        'New York', 'North Carolina', 'North Dakota', 'Ohio', 'Oklahoma', 'Oregon', 'Pennsylvania', 'Rhode Island', 'South Carolina',
        'South Dakota', 'Tennessee', 'Texas', 'Utah', 'Vermont', 'Virginia', 'Washington', 'West Virginia', 'Wisconsin', 'Wyoming');
}

/** The state a court name sits in ("District of Utah" -> Utah), longest name first so West Virginia is not Virginia. */
function kop_sc_court_state($court) {
    $names = kop_sc_state_names();
    usort($names, function ($a, $b) { return strlen($b) - strlen($a); });
    foreach ($names as $n) {
        if (stripos((string) $court, $n) !== false) {
            return $n;
        }
    }
    return '';
}

/** "2:18-cv-35-TC-DAO" -> [office "2", "18-cv-00035"]; null when it is not a federal docket number. */
function kop_sc_docket_parts($number) {
    if (!preg_match('/(?:(\d{1,2}):)?(\d{2})[-\s]?(cv|cr|mc|md|bk|ap|mj)[-\s]?(\d{1,6})/i', (string) $number, $m)) {
        return null;
    }
    return array($m[1], $m[2] . '-' . strtolower($m[3]) . '-' . str_pad(ltrim($m[4], '0') ?: '0', 5, '0', STR_PAD_LEFT));
}

/** The first party's surname or name word to narrow a CourtListener search ("Sherman, et al. v. ..." -> Sherman). */
function kop_sc_first_party_word($case_name) {
    $first = preg_split('/\s+v\.?\s+|\s+vs\.?\s+/i', preg_replace('/^Case:\s*/i', '', (string) $case_name))[0];
    $skip = array('the', 'of', 'and', 'et', 'al', 'inc', 'llc', 'in', 're', 'estate', 'doe', 'john', 'jane', 'a', 'minor', 'by', 'usa', 'united', 'states', 'america');
    foreach (preg_split('/[^A-Za-z\'-]+/', $first) as $w) {
        if (strlen($w) >= 3 && !in_array(strtolower($w), $skip, true)) {
            return $w;
        }
    }
    return '';
}

/** Is this a federal court case CourtListener can have? */
function kop_sc_is_federal_case(array $suit) {
    return trim((string) $suit['case_number']) !== '' && kop_sc_docket_parts($suit['case_number'])
        && (stripos((string) $suit['court'], 'District Court') !== false || stripos((string) $suit['court'], 'Court of Appeals') !== false
            || strcasecmp((string) $suit['jurisdiction'], 'Federal') === 0);
}

/**
 * The CourtListener docket for a federal lawsuit: [docket_id, absolute_url, dateTerminated, caseName, court] or null.
 * Searched by docket number + the first party, kept only when the court's state matches.
 */
function kop_sc_courtlistener_docket(array $suit) {
    $parts = kop_sc_docket_parts($suit['case_number']);
    if (!$parts) {
        return null;
    }
    $q = 'docketNumber:"' . $parts[1] . '"';
    $word = kop_sc_first_party_word($suit['case_name']);
    if ($word !== '') {
        $q .= ' AND caseName:"' . $word . '"';
    }
    $json = json_decode(kop_sc_fetch('https://www.courtlistener.com/api/rest/v4/search/?type=r&q=' . rawurlencode($q)), true);
    $state = kop_sc_court_state($suit['court']);
    $best = null;
    foreach ((array) ($json['results'] ?? array()) as $r) {
        if ($state !== '' && stripos((string) ($r['court'] ?? ''), $state) === false) {
            continue;
        }
        if ($parts[0] !== '' && strpos((string) ($r['docketNumber'] ?? ''), $parts[0] . ':') !== 0) {
            continue;
        }
        if ($best !== null) {
            return null;   // two dockets fit: never guess
        }
        $best = $r;
    }
    if (!$best || empty($best['docket_id'])) {
        return null;
    }
    return array(
        'docket_id' => (int) $best['docket_id'],
        'url' => 'https://www.courtlistener.com' . (string) ($best['docket_absolute_url'] ?? '/docket/' . (int) $best['docket_id'] . '/'),
        'terminated' => (string) ($best['dateTerminated'] ?? ''),
        'case_name' => (string) ($best['caseName'] ?? ''),
        'court' => (string) ($best['court'] ?? ''),
    );
}

/** A docket link already on the record (CourtListener, Justia dockets), for cases CourtListener's search did not find. */
function kop_sc_lawsuit_docket_link(array $suit) {
    foreach (array('source_urls', 'document_urls') as $col) {
        foreach ((array) json_decode((string) ($suit[$col] ?? ''), true) as $u) {
            if (is_string($u) && preg_match('#^https?://(www\.)?(courtlistener\.com/docket/|dockets\.justia\.com/docket/)#i', $u)) {
                return $u;
            }
        }
    }
    return '';
}

function kop_sc_lawsuit_prompt(array $suit, $text, $terminated) {
    $statuses = implode(', ', array_diff(kop_sc_lawsuit_statuses(), array('unknown')));
    return "You check whether a lawsuit's status has changed. Below is what our record says, then the newest entries of the case's court docket.\n\n"
        . "OUR RECORD\nCase: {$suit['case_name']}\nCourt: {$suit['court']}\nCase number: {$suit['case_number']}\n"
        . "Status: {$suit['status']}\nOutcome: " . ($suit['outcome'] !== '' ? $suit['outcome'] : 'none recorded') . "\n"
        . "Settlement amount: " . ($suit['settlement_amount'] !== '' ? $suit['settlement_amount'] : 'none recorded') . "\n\n"
        . ($terminated !== '' ? "The court's records say the case was terminated on $terminated.\n\n" : '')
        . "DOCKET\n" . $text . "\n\n"
        . "Answer with JSON only, no other text:\n"
        . "{\"same_case\": true or false,\n"
        . " \"status\": one of: $statuses (filed = filed, little has happened; in_progress = motions, discovery, trial pending; ruling = a judge decided a significant motion or the case; dismissed = the court dismissed the case; settled = the parties settled; appeal = on appeal; closed = ended for another reason),\n"
        . " \"event_date\": \"YYYY-MM-DD\" of the docket entry that shows this status,\n"
        . " \"outcome\": \"one or two plain sentences on how the case stands or ended, only from the docket; empty if nothing has been decided\",\n"
        . " \"settlement_amount\": \"the amount if the docket states one, else empty\",\n"
        . " \"quote\": \"the exact words of the docket entry that shows this, copied character for character\"}\n"
        . "Use only the docket. Do not guess a settlement amount. If the docket shows nothing new beyond our record, repeat our record's status.";
}

/* ---- Checking one record -------------------------------------------- */

/**
 * The page or docket read last time, unchanged? Then the AI is not asked
 * again (the free tiers are shared with the hourly jobs): the record is "same".
 */
function kop_sc_unchanged(array $row, $text) {
    return !empty($row['_sc_hash']) && $row['_sc_hash'] === md5($text) && in_array($row['_sc_outcome'] ?? '', array('same', 'changed'), true);
}

/**
 * Check one bill. Returns [outcome, found (field => value) or null, source_url, quote, source_text, detail, source_ref].
 * Outcome: checked, no_source, error.
 */
function kop_sc_check_bill(array $bill) {
    if (strcasecmp((string) $bill['jurisdiction'], 'Federal') === 0) {
        $ref = kop_sc_federal_bill_ref($bill);
        if (!$ref) {
            return array('no_source', null, '', '', '', 'No bill number Congress tracks (a draft or unnumbered bill).', '');
        }
        list($congress, $type, $num) = $ref;
        $url = "https://www.govinfo.gov/bulkdata/BILLSTATUS/$congress/$type/BILLSTATUS-$congress$type$num.xml";
        $facts = kop_sc_billstatus_facts(kop_sc_fetch($url), $congress);
        if (!$facts) {
            return array('error', null, $url, '', '', 'govinfo had no readable BILLSTATUS file.', '');
        }
        $found = array('status' => $facts['status'], 'last_action_date' => $facts['last_action_date'], 'last_action_text' => $facts['last_action_text']);
        return array('checked', $found, $url, $facts['quote'], $facts['source_text'], '', "$congress-$type-$num");
    }
    $url = kop_sc_bill_source_url($bill);
    if ($url === '') {
        return array('no_source', null, '', '', '', 'The record has no official bill page link.', '');
    }
    $raw = kop_sc_fetch($url);
    $text = strpos($url, 'search-prod.lis.state.oh.us') !== false ? kop_sc_ohio_text($raw, $url) : kop_sc_html_text($raw);
    if (strlen($text) < 200) {
        return array('error', null, $url, '', '', 'The bill page could not be read (blocked or empty).', '');
    }
    if (kop_sc_unchanged($bill, $text)) {
        return array('unchanged', null, $url, '', $text, 'The page has not changed since the last check.', '');
    }
    try {
        $reply = kop_sc_parse_json(kop_sc_ai(kop_sc_bill_prompt($bill, $text)));
    } catch (Throwable $e) {
        return array('error', null, $url, '', '', 'AI: ' . $e->getMessage(), '');
    }
    if (!$reply) {
        return array('error', null, $url, '', '', 'The AI reply was not readable.', '');
    }
    if (empty($reply['same_bill'])) {
        return array('error', null, $url, '', '', 'The page did not show this bill\'s history.', '');
    }
    $found = array(
        'status' => in_array($reply['status'] ?? '', kop_sc_bill_statuses(), true) ? $reply['status'] : '',
        'last_action_date' => kop_sc_clean_date($reply['last_action_date'] ?? ''),
        'last_action_text' => mb_substr(trim((string) ($reply['last_action_text'] ?? '')), 0, 480),
    );
    return array('checked', $found, $url, trim((string) ($reply['quote'] ?? '')), $text, '', '');
}

/** Check one lawsuit; same return shape as kop_sc_check_bill(). */
function kop_sc_check_lawsuit(array $suit) {
    $url = '';
    $terminated = '';
    $ref = '';
    $text = '';
    if (kop_sc_is_federal_case($suit)) {
        $docket = kop_sc_courtlistener_docket($suit);
        if ($docket) {
            $url = $docket['url'];
            $terminated = kop_sc_clean_date($docket['terminated']);
            $ref = 'cl:' . $docket['docket_id'];
            // The docket's feed lists its newest entries; the page itself starts at the oldest.
            $text = kop_sc_html_text(kop_sc_fetch('https://www.courtlistener.com/docket/' . $docket['docket_id'] . '/feed/'), 9000);
            if (strlen($text) < 100) {
                $text = kop_sc_html_text(kop_sc_fetch($url . (strpos($url, '?') === false ? '?' : '&') . 'order_by=desc'), 9000);
            }
            $text = 'Case: ' . $docket['case_name'] . ' (' . $docket['court'] . ')' . "\n" . $text;
        }
    }
    if ($url === '') {
        $url = kop_sc_lawsuit_docket_link($suit);
        if ($url !== '') {
            $text = kop_sc_html_text(kop_sc_fetch($url), 9000);
        }
    }
    if ($url === '') {
        return array('no_source', null, '', '', '', kop_sc_is_federal_case($suit) ? 'CourtListener has no single matching docket.' : 'No court docket to check (state court case, or no case number).', '');
    }
    if (strlen($text) < 150) {
        return array('error', null, $url, '', '', 'The docket could not be read.', $ref);
    }
    if (kop_sc_unchanged($suit, $text)) {
        return array('unchanged', null, $url, '', $text, 'The docket has not changed since the last check.', $ref);
    }
    try {
        $reply = kop_sc_parse_json(kop_sc_ai(kop_sc_lawsuit_prompt($suit, $text, $terminated)));
    } catch (Throwable $e) {
        return array('error', null, $url, '', '', 'AI: ' . $e->getMessage(), $ref);
    }
    if (!$reply || empty($reply['same_case'])) {
        return array('error', null, $url, '', '', $reply ? 'The docket did not match this case.' : 'The AI reply was not readable.', $ref);
    }
    $found = array(
        'status' => in_array($reply['status'] ?? '', kop_sc_lawsuit_statuses(), true) ? $reply['status'] : '',
        'outcome' => trim((string) ($reply['outcome'] ?? '')),
        'settlement_amount' => mb_substr(trim((string) ($reply['settlement_amount'] ?? '')), 0, 100),
    );
    return array('checked', $found, $url, trim((string) ($reply['quote'] ?? '')), $text, '', $ref);
}

/**
 * The fields that differ: [field => [from, to]]. A bill's action only moves
 * forward (a newer date); a found status that is no newer than the record's
 * last action is not a change. A lawsuit's outcome / amount are proposed only
 * when the status changes or the record has none, so rewording alone never
 * makes a proposal.
 */
function kop_sc_diff($kind, array $row, array $found) {
    $changes = array();
    if ($kind === 'bill') {
        $oldDate = (string) ($row['last_action_date'] ?? '');
        $newDate = (string) ($found['last_action_date'] ?? '');
        $newer = $newDate !== '' && ($oldDate === '' || strcmp($newDate, $oldDate) > 0);
        $notOlder = $newDate === '' || $oldDate === '' || strcmp($newDate, $oldDate) >= 0;
        if ($found['status'] !== '' && $found['status'] !== $row['status'] && $notOlder) {
            $changes['status'] = array($row['status'], $found['status']);
        }
        if ($newer) {
            $changes['last_action_date'] = array($oldDate, $newDate);
            if (($found['last_action_text'] ?? '') !== '') {
                $changes['last_action_text'] = array((string) $row['last_action_text'], $found['last_action_text']);
            }
        }
        return $changes;
    }
    $statusChanged = $found['status'] !== '' && $found['status'] !== 'unknown' && $found['status'] !== $row['status'];
    if ($statusChanged) {
        $changes['status'] = array($row['status'], $found['status']);
    }
    foreach (array('outcome', 'settlement_amount') as $f) {
        $new = (string) ($found[$f] ?? '');
        $old = (string) ($row[$f] ?? '');
        if ($new !== '' && kop_sc_norm($new) !== kop_sc_norm($old) && ($statusChanged || $old === '')) {
            $changes[$f] = array($old, $new);
        }
    }
    return $changes;
}

/** Store a proposal unless the same change was made before (any status); a waiting older one for the record goes stale. */
function kop_sc_propose(PDO $pdo, $kind, $id, array $changes, $quote, $quoteFound, $url, $label) {
    $to = array();
    foreach ($changes as $f => $c) {
        $to[$f] = $c[1];
    }
    ksort($to);
    $hash = md5($kind . '|' . (int) $id . '|' . json_encode($to));
    $st = $pdo->prepare('SELECT id FROM record_status_proposals WHERE kind = ? AND record_id = ? AND change_hash = ?');
    $st->execute(array($kind, (int) $id, $hash));
    if ($st->fetchColumn()) {
        return 0;
    }
    $pdo->prepare("UPDATE record_status_proposals SET status = 'stale' WHERE kind = ? AND record_id = ? AND status = 'pending'")
        ->execute(array($kind, (int) $id));
    $pdo->prepare('INSERT INTO record_status_proposals (kind, record_id, changes, change_hash, quote, quote_found, source_url, source_label, status, created_at)
                   VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute(array($kind, (int) $id, json_encode($changes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $hash,
            mb_substr((string) $quote, 0, 2000), $quoteFound ? 1 : 0, mb_substr((string) $url, 0, 1000), $label, 'pending', kop_sc_now()));
    return (int) $pdo->lastInsertId();
}

function kop_sc_source_label($url) {
    $host = strtolower((string) parse_url((string) $url, PHP_URL_HOST));
    $names = array('govinfo.gov' => 'govinfo (Congress)', 'courtlistener.com' => 'CourtListener', 'digitaldemocracy.org' => 'CalMatters Digital Democracy',
                   'justia.com' => 'Justia Dockets');
    foreach ($names as $k => $v) {
        if (substr($host, -strlen($k)) === $k) {
            return $v;
        }
    }
    return preg_replace('/^www\./', '', $host);
}

/** Records due for a check: published, not finished, not checked in the last 20 hours. Oldest check first. */
function kop_sc_due(PDO $pdo, $kind, $limit, array $only_ids = array()) {
    $since = gmdate('Y-m-d H:i:s', time() - 20 * HOUR_IN_SECONDS);
    if ($kind === 'bill') {
        $final = "'" . implode("','", kop_sc_final_bill_statuses()) . "'";
        $where = "l.publication_status = 'published' AND l.status NOT IN ($final)
                  AND NOT (l.status = 'vetoed' AND l.last_action_date IS NOT NULL AND l.last_action_date < ?)";
        $args = array(gmdate('Y-m-d', time() - 120 * DAY_IN_SECONDS));
        $table = 'legislation';
    } else {
        $final = "'" . implode("','", kop_sc_final_lawsuit_statuses()) . "'";
        $where = "l.publication_status = 'published' AND l.status NOT IN ($final)";
        $args = array();
        $table = 'lawsuits';
    }
    if ($only_ids) {
        $where = 'l.id IN (' . implode(',', array_map('intval', $only_ids)) . ')';
        $args = array();
    } else {
        $where .= ' AND (s.last_checked IS NULL OR s.last_checked < ?)';
        $args[] = $since;
    }
    $st = $pdo->prepare("SELECT l.*, s.last_hash AS _sc_hash, s.last_outcome AS _sc_outcome FROM $table l LEFT JOIN record_status_sources s ON s.kind = '$kind' AND s.record_id = l.id
                          WHERE $where ORDER BY s.last_checked IS NOT NULL, s.last_checked, l.id LIMIT " . (int) $limit);
    $st->execute($args);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

function kop_sc_record_check(PDO $pdo, $kind, $id, $outcome, $url, $ref, $detail, $hash = '') {
    $pdo->prepare('DELETE FROM record_status_sources WHERE kind = ? AND record_id = ?')->execute(array($kind, (int) $id));
    $pdo->prepare('INSERT INTO record_status_sources (kind, record_id, source_url, source_ref, last_checked, last_outcome, last_detail, last_hash) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute(array($kind, (int) $id, mb_substr((string) $url, 0, 1000), (string) $ref, kop_sc_now(), $outcome, mb_substr((string) $detail, 0, 1000), $hash !== '' ? $hash : null));
}

/**
 * Check one record. $write false: nothing stored (CLI dry run).
 * Returns [outcome (same, changed, no_source, error), changes, detail, proposal id].
 */
function kop_sc_check_one(PDO $pdo, $kind, array $row, $write = true) {
    list($outcome, $found, $url, $quote, $text, $detail, $ref) = $kind === 'bill' ? kop_sc_check_bill($row) : kop_sc_check_lawsuit($row);
    $changes = array();
    $pid = 0;
    $hash = $text !== '' ? md5($text) : '';
    if ($outcome === 'unchanged') {
        $outcome = 'same';
    } elseif ($outcome === 'checked') {
        $changes = kop_sc_diff($kind, $row, $found);
        $outcome = $changes ? 'changed' : 'same';
        if ($changes && $write) {
            $pid = kop_sc_propose($pdo, $kind, (int) $row['id'], $changes, $quote, kop_sc_quote_found($quote, $text), $url, kop_sc_source_label($url));
        }
    }
    // A rate limit says nothing about the record: leave it due so the next run tries again.
    if ($write && !($outcome === 'error' && stripos($detail, 'rate limit') !== false)) {
        kop_sc_record_check($pdo, $kind, (int) $row['id'], $outcome, $url, $ref, $detail, $hash);
    }
    return array($outcome, $changes, $detail, $pid);
}

/**
 * Check due bills, then due lawsuits, until $limit records or $seconds pass.
 * Returns counts, the new proposals and whether records remain due.
 */
function kop_sc_run(PDO $pdo, $limit, $seconds, $write = true, $kinds = array('bill', 'lawsuit'), array $only_ids = array(), $log = null) {
    kop_sc_ensure_tables($pdo);
    $started = time();
    $counts = array('checked' => 0, 'same' => 0, 'changed' => 0, 'no_source' => 0, 'error' => 0);
    $new = array();
    $stopped = false;
    foreach ((array) $kinds as $kind) {
        foreach (kop_sc_due($pdo, $kind, $limit - $counts['checked'], $only_ids) as $row) {
            list($outcome, $changes, $detail, $pid) = kop_sc_check_one($pdo, $kind, $row, $write);
            $counts['checked']++;
            $counts[$outcome]++;
            if ($pid) {
                $new[] = array('kind' => $kind, 'row' => $row, 'changes' => $changes, 'id' => $pid);
            }
            if ($log) {
                $log($kind, $row, $outcome, $changes, $detail);
            }
            if (($outcome === 'error' && stripos($detail, 'rate limit') !== false) || time() - $started > $seconds || $counts['checked'] >= $limit) {
                $stopped = true;
                break 2;
            }
            if (empty($GLOBALS['kop_sc_fetch_stub'])) {
                sleep(1);   // one request a second to the courts' and legislatures' sites
            }
        }
    }
    $remaining = 0;
    if ($write && !$only_ids) {
        foreach ((array) $kinds as $kind) {
            $remaining += count(kop_sc_due($pdo, $kind, 500));
        }
    }
    return array('counts' => $counts, 'new' => $new, 'remaining' => $remaining, 'stopped' => $stopped);
}

/* ---- Applying, dismissing, undoing ---------------------------------- */

function kop_sc_get(PDO $pdo, $id) {
    $st = $pdo->prepare('SELECT * FROM record_status_proposals WHERE id = ?');
    $st->execute(array((int) $id));
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

function kop_sc_record(PDO $pdo, $kind, $id) {
    $kinds = kop_sc_kinds();
    if (!isset($kinds[$kind])) {
        return null;
    }
    $st = $pdo->prepare('SELECT * FROM ' . $kinds[$kind]['table'] . ' WHERE id = ?');
    $st->execute(array((int) $id));
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

/**
 * Write a proposal's changes (with $override values from the card, if any)
 * into the record. The record's own values before are kept for Undo, with
 * its reviewer notes, so Undo restores it exactly.
 */
function kop_sc_apply(PDO $pdo, $id, $reviewer, array $override = array()) {
    $p = kop_sc_get($pdo, $id);
    if (!$p) throw new RuntimeException('That update is gone.');
    if ($p['status'] !== 'pending') throw new RuntimeException('This update is not waiting (it is ' . $p['status'] . ').');
    $kinds = kop_sc_kinds();
    $kind = $p['kind'];
    $row = kop_sc_record($pdo, $kind, $p['record_id']);
    if (!$row) throw new RuntimeException('The ' . strtolower($kinds[$kind]['label']) . ' record is gone.');
    $changes = (array) json_decode($p['changes'], true);
    $set = array();
    $vals = array();
    $prev = array();
    $applied = array();
    foreach ($changes as $f => $c) {
        if (!in_array($f, $kinds[$kind]['fields'], true)) continue;
        $to = array_key_exists($f, $override) ? trim((string) $override[$f]) : (string) $c[1];
        if ($f === 'status' && !in_array($to, $kind === 'bill' ? kop_sc_bill_statuses() : kop_sc_lawsuit_statuses(), true)) {
            throw new RuntimeException('Status must be one of: ' . implode(', ', $kind === 'bill' ? kop_sc_bill_statuses() : kop_sc_lawsuit_statuses()) . '.');
        }
        if ($f === 'last_action_date' && $to !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
            throw new RuntimeException('Last action date: write it as YYYY-MM-DD.');
        }
        $prev[$f] = $row[$f];
        $applied[$f] = ($to === '' && $f === 'last_action_date') ? null : $to;
        $set[] = "$f = ?";
        $vals[] = $applied[$f];
    }
    if (!$set) throw new RuntimeException('Nothing to change.');
    $prev['reviewer_notes'] = $row['reviewer_notes'];
    $set[] = 'reviewer_notes = ?';
    $vals[] = trim((string) $row['reviewer_notes'] . "\n[status check " . gmdate('Y-m-d') . '] ' . implode(', ', array_keys($changes))
        . ' updated from ' . $p['source_url'] . ' (approved by ' . $reviewer . ')');
    $vals[] = (int) $p['record_id'];
    $pdo->prepare('UPDATE ' . $kinds[$kind]['table'] . ' SET ' . implode(', ', $set) . ' WHERE id = ?')->execute($vals);
    $pdo->prepare("UPDATE record_status_proposals SET status = 'applied', previous = ?, reviewed_by = ?, reviewed_at = ? WHERE id = ?")
        ->execute(array(json_encode(array('before' => $prev, 'applied' => $applied), JSON_UNESCAPED_UNICODE), $reviewer, kop_sc_now(), (int) $id));
    return $row;
}

/** Put the record back as it was, field by field, unless someone changed that field since. */
function kop_sc_undo(PDO $pdo, $id, $reviewer) {
    $p = kop_sc_get($pdo, $id);
    if (!$p || $p['status'] !== 'applied') throw new RuntimeException('This update was not applied.');
    $kinds = kop_sc_kinds();
    $saved = (array) json_decode((string) $p['previous'], true);
    $row = kop_sc_record($pdo, $p['kind'], $p['record_id']);
    if (!$row) throw new RuntimeException('The record is gone.');
    $set = array();
    $vals = array();
    $kept = array();
    foreach ((array) ($saved['applied'] ?? array()) as $f => $applied) {
        if ((string) $row[$f] !== (string) $applied) {
            $kept[] = $f;   // edited by hand since: leave it
            continue;
        }
        $set[] = "$f = ?";
        $vals[] = $saved['before'][$f];
    }
    if (array_key_exists('reviewer_notes', (array) ($saved['before'] ?? array()))) {
        $set[] = 'reviewer_notes = ?';
        $vals[] = $saved['before']['reviewer_notes'];
    }
    if ($set) {
        $vals[] = (int) $p['record_id'];
        $pdo->prepare('UPDATE ' . $kinds[$p['kind']]['table'] . ' SET ' . implode(', ', $set) . ' WHERE id = ?')->execute($vals);
    }
    $pdo->prepare("UPDATE record_status_proposals SET status = 'pending', previous = NULL, reviewed_by = ?, reviewed_at = ? WHERE id = ?")
        ->execute(array($reviewer, kop_sc_now(), (int) $id));
    return $kept;
}

function kop_sc_set_status(PDO $pdo, $id, $status, $reviewer) {
    $pdo->prepare("UPDATE record_status_proposals SET status = ?, reviewed_by = ?, reviewed_at = ? WHERE id = ? AND status <> 'applied'")
        ->execute(array($status, $reviewer, kop_sc_now(), (int) $id));
}

/* ---- Nightly run ---------------------------------------------------- */

add_action('init', function () {
    if (!wp_next_scheduled('kop_status_checks_nightly')) {
        // 09:00 UTC = 1-2 a.m. Pacific, when the site is quiet.
        $next = strtotime(gmdate('Y-m-d') . ' 09:00:00 UTC');
        wp_schedule_event($next > time() ? $next : $next + DAY_IN_SECONDS, 'daily', 'kop_status_checks_nightly');
    }
});
add_action('kop_status_checks_nightly', 'kop_sc_cron');
add_action('kop_status_checks_continue', 'kop_sc_cron');

/** One run of about four minutes; while records remain due it books itself again ten minutes later. */
function kop_sc_cron() {
    if (get_transient('kop_status_checks_lock')) {
        return;
    }
    set_transient('kop_status_checks_lock', 1, 10 * MINUTE_IN_SECONDS);
    try {
        $pdo = kop_sc_pdo();
        if (!$pdo) {
            return;
        }
        $result = kop_sc_run($pdo, 60, 240);
        $last = get_option('kop_status_checks_last_run');
        $night = gmdate('Y-m-d');
        if (!is_array($last) || ($last['night'] ?? '') !== $night) {
            $last = array('night' => $night, 'checked' => 0, 'same' => 0, 'changed' => 0, 'no_source' => 0, 'error' => 0);
        }
        foreach ($result['counts'] as $k => $v) {
            $last[$k] = ($last[$k] ?? 0) + $v;
        }
        $last['finished'] = $result['remaining'] === 0 ? kop_sc_now() : '';
        $last['remaining'] = $result['remaining'];
        update_option('kop_status_checks_last_run', $last, false);
        $last['runs'] = ($last['runs'] ?? 0) + 1;
        update_option('kop_status_checks_last_run', $last, false);
        // While records remain due, again in ten minutes; at most 30 runs a night (an AI out of calls all day).
        if ($result['remaining'] > 0 && $last['runs'] < 30 && !wp_next_scheduled('kop_status_checks_continue')) {
            wp_schedule_single_event(time() + 10 * MINUTE_IN_SECONDS, 'kop_status_checks_continue');
        }
        if ($result['new'] && function_exists('kop_notify_admins')) {
            $fields = array();
            foreach (array_slice($result['new'], 0, 20) as $i => $n) {
                $fields['Update ' . ($i + 1)] = kop_sc_record_title($n['kind'], $n['row']) . ': ' . kop_sc_changes_line($n['changes']);
            }
            $c = count($result['new']);
            kop_notify_admins('status_update', $c . ' bill / lawsuit ' . ($c === 1 ? 'update' : 'updates') . ' to review', '', $fields);
        }
    } catch (Throwable $e) {
        error_log('kop status checks: ' . $e->getMessage());
    } finally {
        delete_transient('kop_status_checks_lock');
    }
}

function kop_sc_record_title($kind, array $row) {
    return $kind === 'bill'
        ? trim($row['jurisdiction'] . ' ' . $row['bill_number']) . ($row['session_year'] ? ' (' . $row['session_year'] . ')' : '')
        : (string) $row['case_name'];
}

/** "status: in_committee -> enacted; last action date: 2026-05-13 -> 2026-10-05" */
function kop_sc_changes_line(array $changes) {
    $out = array();
    foreach ($changes as $f => $c) {
        if ($f === 'last_action_text' || $f === 'outcome') continue;
        $out[] = str_replace('_', ' ', $f) . ': ' . ($c[0] !== '' && $c[0] !== null ? $c[0] : 'none') . ' -> ' . $c[1];
    }
    return $out ? implode('; ', $out) : 'new ' . implode(', ', array_map(function ($f) { return str_replace('_', ' ', $f); }, array_keys($changes)));
}
