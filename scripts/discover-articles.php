<?php
/**
 * Article Discovery — PHP port of discover-articles.js.
 *
 * Written for the NixiHost cPanel cron, where no Node runtime exists. It began
 * as a 1:1 port of discover-articles.js (same flags, same state files, same
 * scoring); the facility sweep has since moved on (below) and the JS is the
 * manual-only fallback. Pulls candidate articles from r/troubledteens (new
 * posts) and Google News RSS (topic queries, then the facility sweep),
 * pre-filters them with a state-aware scoring pass, then feeds surviving URLs
 * through the AI processor into the news_submissions review queue.
 *
 * The facility sweep (2026-10) queries EVERY record and every name it has
 * had (current, past, other; closed programs too: that is where lawsuits and
 * survivor stories come from). Distinctive names (three words or more) go six
 * to a request, OR'd: Google News matches the article body, so one request
 * covers six programs. Short names (one or two words: "Grafton", "Day One")
 * go one to a request with the state name, as before, because a short name
 * in a body is a street or a surname as often as the program. A cursor in the
 * state file walks the sorted request list, half per run, so the two daily
 * runs cover everything once a day and a run that dies resumes where it was.
 *
 * A batched hit whose headline names none of the request's programs is not
 * thrown away (the name was in the body, or Google would not have returned
 * it): it is queued as 'attribution pending', and the AI step that reads the
 * article confirms which program it is about before anything is submitted.
 * Unconfirmed ones are logged as batch-unconfirmed for the Scraper finds screen.
 *
 * Order matters: the Reddit + topic tier is filtered and submitted BEFORE the
 * sweep, and the sweep's own finds are resolved and submitted every
 * GN_SUBMIT_EVERY requests. Many RSS requests get the server IP rate-limited
 * on the article-page step of the Google News link resolver, so any link
 * resolved late comes back HTTP 429. Candidates a run cannot finish
 * (unresolved link, topic cap, confirm cap, provider quota, time budget) are
 * carried in the state file (`pending`) and submitted first thing next run,
 * before a single Google News request is made.
 *
 * Search terms live in scripts/discovery-queries.json (topic queries, the
 * sweep's keyword OR-group and recency windows, generic facility names to
 * skip). Edit that file to tune what gets searched; no code change needed.
 *
 * Facility data comes live from the WP REST API — NOT from any local JSON
 * snapshot, since the JSON snapshots are out of date.
 *
 * Usage:
 *   php scripts/discover-articles.php              # full run
 *   php scripts/discover-articles.php --dry-run    # discover + score, skip submission
 *   php scripts/discover-articles.php --limit 5    # cap candidates submitted this run
 *   php scripts/discover-articles.php --max-facilities 3   # smoke test: only 3 sweep requests
 *   php scripts/discover-articles.php --no-topics  # skip the topic-query tier
 *   php scripts/discover-articles.php --no-facilities  # topics + Reddit only, no sweep
 *   php scripts/discover-articles.php --sweep-share 1  # the whole sweep in this run (default: half)
 *   php scripts/discover-articles.php --recency 30d    # sweep window for this run (default: facilityBatchRecency)
 *
 * Environment:
 *   NEWS_API_BASE          (default: https://kidsoverprofits.org)
 *   AI_PROVIDER            (default: auto - Groq and Gemini take turns)
 *   RSS_REQUEST_DELAY_MS   (default: 1500 — pause between Google News requests)
 *   AI_REQUEST_DELAY_MS    (default: 6000 — pause between AI submissions)
 *   RUN_TIME_BUDGET_MS / GN_TIME_BUDGET_MS  (default: 75 min / 45 min)
 *   REDDIT_CLIENT_ID / REDDIT_CLIENT_SECRET  (optional OAuth path)
 *
 * Cron note: call /opt/cpanel/ea-php82/root/usr/bin/php explicitly. Bare
 * `php` under cPanel cron is the php-cgi wrapper and the CLI guard exits.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit("CLI only.\n");
}
set_time_limit(0);

// ============================================================
// Paths & config
// ============================================================

define('STATE_FILE',     __DIR__ . '/.discovery-state.json');
// Dot-prefixed + gitignored: the rejected log is runtime state written by the
// server. A tracked copy would be overwritten by every deploy.
define('REJECTED_FILE',  __DIR__ . '/.discovery-rejected.json');
define('BLACKLIST_FILE', __DIR__ . '/discovery-blacklist.json');
define('QUERIES_FILE',   __DIR__ . '/discovery-queries.json');

$args = array_slice($argv, 1);
define('DRY_RUN', in_array('--dry-run', $args, true));
define('NO_TOPICS', in_array('--no-topics', $args, true));
// Topics + Reddit only. The sweep's cursor is left where it is.
define('NO_FACILITIES', in_array('--no-facilities', $args, true));
$limitArg = array_search('--limit', $args, true);
define('SUBMIT_LIMIT', $limitArg !== false ? (int)($args[$limitArg + 1] ?? 0) : PHP_INT_MAX);
// Caps the sweep at N requests this run (a smoke test). The cursor still advances.
$maxFacArg = array_search('--max-facilities', $args, true);
define('MAX_FACILITIES', $maxFacArg !== false ? (int)($args[$maxFacArg + 1] ?? 0) : null);
// How many runs share one pass over every name: 2 = the 01:30 and 13:30 crons
// each take half. --sweep-share 1 does the whole list in one run.
$shareArg = array_search('--sweep-share', $args, true);
define('SWEEP_RUNS_PER_DAY', max(1, $shareArg !== false ? (int)($args[$shareArg + 1] ?? 0) : (int)(getenv('SWEEP_RUNS_PER_DAY') ?: 2)));
// --recency 30d: the sweep's when: window for this run only (the first run
// after a deploy picks up the backlog with 30d; the nightly default is in
// discovery-queries.json as facilityBatchRecency).
$recencyArg = array_search('--recency', $args, true);
define('RECENCY_OVERRIDE', $recencyArg !== false && preg_match('/^\d+[dhm]$/', (string)($args[$recencyArg + 1] ?? ''))
    ? 'when:' . $args[$recencyArg + 1] : '');

define('API_BASE', rtrim(getenv('NEWS_API_BASE') ?: 'https://kidsoverprofits.org', '/'));
define('FACILITIES_URL', API_BASE . '/wp-json/kop/v1/facilities');
define('AI_ENDPOINT', API_BASE . '/wp-content/themes/child/api/process-news-ai.php');
define('SUBMIT_ENDPOINT', API_BASE . '/wp-content/themes/child/api/save-news-submission.php');

// The AI endpoint answers only admins and the site's own jobs: on the server this
// script proves it is one by the key from wp-config's salts (api/lib-internal-token.php).
require_once dirname(__DIR__) . '/api/lib-internal-token.php';
kop_internal_token_load_salts(__DIR__);
define('KOP_INTERNAL_AI_TOKEN', getenv('KOP_INTERNAL_AI_TOKEN') ?: kop_internal_token('news-ai'));

define('AI_PROVIDER', getenv('AI_PROVIDER') ?: 'auto');
// The sweep: distinctive names go several to one Google News request
// (tested 2026-10-13: six quoted names OR'd returned stories for all six).
// Google reads about 32 words of a query and silently drops the rest, so a
// batch holds at most GN_BATCH_SIZE names and GN_BATCH_WORDS words of names
// (plus the 9 batch keywords and the when: window, which goes FIRST: at the
// end it was the part dropped, and years-old stories came back). A batch
// keeps GN_BATCH_CAP items. Short names go one to a request.
define('GN_BATCH_SIZE', 6);
define('GN_BATCH_WORDS', 16);
define('GN_BATCH_CAP', 25);
// A batched hit that names no program in its headline is confirmed by the AI
// step (which reads the article) before it is submitted: at most this many
// confirmations a run, the rest carried to the next run.
define('AI_CONFIRM_PER_RUN', (int)(getenv('AI_CONFIRM_PER_RUN') ?: 30));
// The sweep's finds are resolved and submitted every this many requests,
// while Google still answers the link resolver (see the header).
define('GN_SUBMIT_EVERY', 50);
// When each name was last queried is kept this long in the state file.
define('SWEPT_AT_DAYS', 30);

define('SCORE_THRESHOLD', 3);
// Both delays are env-tunable: the cron runs from a permanent IP whose
// standing with Google News and Groq matters more than a throwaway CI runner's.
define('RSS_REQUEST_DELAY_MS', (int)(getenv('RSS_REQUEST_DELAY_MS') ?: 1500));
define('AI_REQUEST_DELAY_MS', (int)(getenv('AI_REQUEST_DELAY_MS') ?: 6000));
// Groq free-tier rate limits are per-minute; one 30s wait was often not enough.
define('AI_RATE_LIMIT_RETRIES', 2);
define('AI_RATE_LIMIT_WAIT_MS', 45000);
define('AI_RATE_LIMIT_STOP_AFTER', 3);   // consecutive rate-limited candidates before the submit phase stops
define('MAX_SEEN_URLS', 50000);
define('MAX_REJECTED_ENTRIES', 2000);   // rejected log cap (deduped by link)
define('REJECTED_URL_TTL_DAYS', 7);      // reject suppression window for noisy repeats
define('PER_FACILITY_CAP', 15);
define('REQUEST_TIMEOUT_MS', 30000);
define('AI_TIMEOUT_MS', 90000);
define('MAX_ARTICLE_AGE_DAYS', 30);
// Headlines already submitted are remembered this long, so a story syndicated
// under new URLs night after night (one mistrial story went in 7 times) is
// submitted once.
define('SEEN_HEADLINE_DAYS', 60);
// Google News link resolution comes back 429 in bursts. After this many
// failures in a row, the remaining Google News candidates are left for the
// next run instead of being thrown away.
define('GN_RESOLVE_FAIL_STOP_AFTER', 3);
// Accepted candidates a run could not finish (resolver 429, topic cap, provider
// quota, time budget) are carried in the state file and submitted first thing
// next run, before any Google News request. Given up after this many carries
// or days, whichever comes first.
define('PENDING_MAX_ATTEMPTS', 4);
define('PENDING_MAX_AGE_DAYS', 7);
define('PENDING_MAX_ENTRIES', 300);
// Wall-clock budgets (same as the JS). Cap the Google News phase and the
// overall run so the filter/submit/save-state phases always run with whatever
// was collected, instead of the job being killed mid-fetch.
// Half the sweep (about 900 requests at 2.5 s each) needs about 40 minutes
// of Google time; the two cron runs are 12 hours apart and the midday one
// holds a lock, so a long run cannot overlap the next.
define('RUN_TIME_BUDGET_MS', (int)(getenv('RUN_TIME_BUDGET_MS') ?: 75 * 60 * 1000));
define('GN_TIME_BUDGET_MS', (int)(getenv('GN_TIME_BUDGET_MS') ?: 45 * 60 * 1000));
// Once Google News starts returning 503s it usually keeps 503ing that IP for
// the rest of the run: after this many consecutive failures, try one
// cool-down, then give up on Google News for the day.
define('GN_MAX_CONSECUTIVE_FAILURES', 12);
define('GN_COOLDOWN_MS', 120000);
define('GENERIC_ALIAS_MIN_FACILITIES', 3);
define('USER_AGENT', 'kids-over-profits-discovery/1.0 (+https://kidsoverprofits.org)');
define('BROWSER_USER_AGENT', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36');

$ABUSE_KEYWORDS = [
    'abuse', 'abused', 'abusing', 'arrest', 'arrested', 'indict', 'indicted', 'indictment',
    'charged', 'charges', 'lawsuit', 'sued', 'suing', 'investigation', 'investigates',
    'closed', 'closes', 'closure', 'shut down', 'shutdown', 'raided', 'raid',
    'pleads guilty', 'guilty plea', 'convicted', 'conviction', 'sentenced', 'sentencing',
    'allegation', 'allegations', 'alleged', 'misconduct', 'death', 'died', 'killed',
    'restraint', 'seclusion', 'neglect', 'assault', 'molest', 'molestation',
    'survivor', 'whistleblower', 'class action', 'settlement', 'fined',
    'license revoked', 'license suspended', 'shuttered', 'felony', 'felonies',
    // Added 2026-09 from hand-submitted headlines the old list missed.
    'trafficking', 'forced labor', 'torture', 'hellhole', 'wrongful death',
    'sexual', 'kidnap', 'revoked', 'escaped', 'escape attempt', 'riot',
    'strip search', 'solitary', 'suicide', 'overdose', 'shut it down'
];

// Weaker signals: real TTI news uses these, but so does routine coverage
// ("licensed therapist", "complaints about traffic"). A title hit scores +2
// instead of +3, so it needs a facility match, topic boost, or reddit share to
// clear the threshold on its own.
$WEAK_ABUSE_KEYWORDS = [
    'license', 'licensing', 'cited', 'citation', 'complaint', 'complaints',
    'violation', 'violations', 'escape', 'runaway', 'ran away', 'missing teen',
    'closing', 'winding down', 'layoffs', 'laid off', 'probe', 'suspended',
    'isolation', 'lockdown', 'hospitalized', 'mistreatment', 'maltreatment',
    'trauma', 'traumatized', 'unlicensed', 'report finds', 'testif',
    'hearing', 'legislation', 'bill would', 'bill to', 'banned', 'ban on', 'regulat'
];

$STATE_NAMES = [
    'AL' => 'Alabama', 'AK' => 'Alaska', 'AZ' => 'Arizona', 'AR' => 'Arkansas', 'CA' => 'California',
    'CO' => 'Colorado', 'CT' => 'Connecticut', 'DE' => 'Delaware', 'FL' => 'Florida', 'GA' => 'Georgia',
    'HI' => 'Hawaii', 'ID' => 'Idaho', 'IL' => 'Illinois', 'IN' => 'Indiana', 'IA' => 'Iowa',
    'KS' => 'Kansas', 'KY' => 'Kentucky', 'LA' => 'Louisiana', 'ME' => 'Maine', 'MD' => 'Maryland',
    'MA' => 'Massachusetts', 'MI' => 'Michigan', 'MN' => 'Minnesota', 'MS' => 'Mississippi', 'MO' => 'Missouri',
    'MT' => 'Montana', 'NE' => 'Nebraska', 'NV' => 'Nevada', 'NH' => 'New Hampshire', 'NJ' => 'New Jersey',
    'NM' => 'New Mexico', 'NY' => 'New York', 'NC' => 'North Carolina', 'ND' => 'North Dakota', 'OH' => 'Ohio',
    'OK' => 'Oklahoma', 'OR' => 'Oregon', 'PA' => 'Pennsylvania', 'RI' => 'Rhode Island', 'SC' => 'South Carolina',
    'SD' => 'South Dakota', 'TN' => 'Tennessee', 'TX' => 'Texas', 'UT' => 'Utah', 'VT' => 'Vermont',
    'VA' => 'Virginia', 'WA' => 'Washington', 'WV' => 'West Virginia', 'WI' => 'Wisconsin', 'WY' => 'Wyoming',
    'DC' => 'District of Columbia'
];

$HARD_BLOCKED_HOSTS = [
    'twitter.com', 'x.com', 'facebook.com', 'instagram.com',
    'tiktok.com', 'youtube.com', 'youtu.be', 'pinterest.com',
    'quora.com', 'reddit.com', 'redd.it', 'i.redd.it', 'v.redd.it',
    'preview.redd.it', 'i.imgur.com', 'imgur.com',
    'discord.gg', 'discord.com', 't.me', 'telegram.me',
    'bit.ly', 'tinyurl.com', 'ow.ly', 'buff.ly', 'goo.gl',
    'gofundme.com'
];

// ============================================================
// Tiny helpers
// ============================================================

function kop_log(string $msg): void { echo $msg . "\n"; }
function kop_warn(string $msg): void { fwrite(STDERR, $msg . "\n"); }
function sleep_ms(int $ms): void { usleep($ms * 1000); }

function normalize_name($s): string {
    $n = mb_strtolower((string)($s ?? ''));
    $n = preg_replace('/[^a-z0-9]+/', ' ', $n);
    return trim(preg_replace('/\s+/', ' ', $n));
}

$TRAILING_DROP_WORDS = [
    'children', 'child', 'youth', 'youths', 'teens', 'teen', 'adolescents',
    'adolescent', 'boys', 'girls', 'kids', 'minors', 'juveniles', 'inc',
    'llc', 'lp', 'corp', 'corporation', 'company', 'co'
];
$TRAILING_CONNECTORS = ['for', 'of', 'the', 'and', 'a', 'an'];

/**
 * Generate shorter-prefix variants of a facility name so the matcher catches
 * common abbreviated forms in news headlines.
 */
function generate_alias_variants($name): array {
    global $TRAILING_DROP_WORDS, $TRAILING_CONNECTORS;
    $out = [];
    $words = preg_split('/\s+/', trim((string)($name ?? '')), -1, PREG_SPLIT_NO_EMPTY);
    if (count($words) < 3) return $out;

    $changed = true;
    while ($changed && count($words) > 2) {
        $changed = false;
        $last = preg_replace('/[^a-z0-9]+$/', '', mb_strtolower($words[count($words) - 1]));
        if (in_array($last, $TRAILING_DROP_WORDS, true) || in_array($last, $TRAILING_CONNECTORS, true)) {
            array_pop($words);
            $changed = true;
        }
    }
    $variant = implode(' ', $words);
    if ($variant !== '' && count(preg_split('/\s+/', $variant)) >= 2 && mb_strlen($variant) >= 6 && $variant !== $name) {
        $out[] = $variant;
    }
    return $out;
}

/**
 * curl-based fetch. Returns ['status'=>int, 'body'=>string, 'contentType'=>string]
 * or throws RuntimeException on transport error/timeout.
 */
function http_request(string $url, array $opts = []): array {
    $ch = curl_init($url);
    $headers = [];
    $hasUa = false;
    foreach (($opts['headers'] ?? []) as $k => $v) {
        $headers[] = "{$k}: {$v}";
        if (strcasecmp($k, 'User-Agent') === 0) $hasUa = true;
    }
    if (!$hasUa) $headers[] = 'User-Agent: ' . USER_AGENT;

    $timeoutMs = (int)($opts['timeoutMs'] ?? REQUEST_TIMEOUT_MS);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 10,
        CURLOPT_TIMEOUT_MS     => $timeoutMs,
        CURLOPT_CONNECTTIMEOUT_MS => min($timeoutMs, 15000),
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_ENCODING       => '',   // accept gzip
    ]);
    if (isset($opts['method']) && strtoupper($opts['method']) === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $opts['body'] ?? '');
    }
    $body = curl_exec($ch);
    if ($body === false) {
        $err = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException("curl: {$err}");
    }
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $ctype = (string)(curl_getinfo($ch, CURLINFO_CONTENT_TYPE) ?: '');
    curl_close($ch);
    return ['status' => $status, 'body' => $body, 'contentType' => $ctype];
}

/**
 * GET a JSON endpoint, retrying with a browser UA if the response isn't
 * parseable JSON. Logs status/content-type and the start of the body on
 * failure so cron logs show WHAT came back.
 */
function fetch_json(string $url, array $opts = []): array {
    $attemptHeaders = [
        ['User-Agent' => USER_AGENT],
        ['User-Agent' => BROWSER_USER_AGENT, 'Accept' => 'application/json'],
        ['User-Agent' => BROWSER_USER_AGENT, 'Accept' => 'application/json'],
    ];
    $lastErr = null;
    for ($i = 0; $i < count($attemptHeaders); $i++) {
        if ($i > 0) sleep_ms(5000 * $i);
        $res = null;
        try {
            $res = http_request($url, array_merge($opts, [
                'headers' => array_merge($attemptHeaders[$i], $opts['headers'] ?? [])
            ]));
            if ($res['status'] < 200 || $res['status'] >= 300) {
                throw new RuntimeException("HTTP {$res['status']}");
            }
            $parsed = json_decode($res['body'], true);
            if (!is_array($parsed)) {
                throw new RuntimeException('response is not valid JSON');
            }
            return $parsed;
        } catch (Throwable $err) {
            $lastErr = $err;
            $n = $i + 1;
            kop_warn("  ! fetchJson attempt {$n}/" . count($attemptHeaders) . " failed for {$url}: {$err->getMessage()}");
            if ($res !== null) {
                kop_warn("    status={$res['status']} content-type=" . ($res['contentType'] ?: '(none)'));
                if ($res['body'] !== '') {
                    kop_warn('    body starts: ' . preg_replace('/\s+/', ' ', substr($res['body'], 0, 300)));
                }
            }
        }
    }
    throw $lastErr;
}

function fetch_text(string $url, array $opts = []): string {
    $res = http_request($url, $opts);
    if ($res['status'] < 200 || $res['status'] >= 300) {
        throw new RuntimeException("HTTP {$res['status']} for {$url}");
    }
    return $res['body'];
}

/** POST JSON; returns ['status'=>, 'ok'=>, 'body'=>array] (body has rawText on parse failure). */
function post_json(string $url, array $body, array $opts = []): array {
    $headers = ['Content-Type' => 'application/json'];
    if ($url === AI_ENDPOINT && KOP_INTERNAL_AI_TOKEN !== '') $headers['X-KOP-Internal'] = KOP_INTERNAL_AI_TOKEN;
    try {
        $res = http_request($url, [
            'method' => 'POST',
            'headers' => $headers,
            'body' => json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'timeoutMs' => $opts['timeoutMs'] ?? REQUEST_TIMEOUT_MS,
        ]);
    } catch (Throwable $e) {
        return ['status' => 0, 'ok' => false, 'body' => ['rawText' => $e->getMessage()]];
    }
    $parsed = json_decode($res['body'], true);
    if (!is_array($parsed)) $parsed = ['rawText' => $res['body']];
    return [
        'status' => $res['status'],
        'ok' => $res['status'] >= 200 && $res['status'] < 300,
        'body' => $parsed
    ];
}

function load_json_file(string $file, $fallback) {
    if (!is_file($file)) return $fallback;
    $parsed = json_decode((string)file_get_contents($file), true);
    return is_array($parsed) ? $parsed : $fallback;
}

function save_json_file(string $file, array $data): void {
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    // PHP pretty-prints with 4-space indent; the JS wrote 2-space. Halve the
    // leading whitespace so diffs against JS-era files stay clean.
    $json = preg_replace_callback('/^ +/m', static fn($m) => str_repeat(' ', intdiv(strlen($m[0]), 2)), $json);
    file_put_contents($file, $json . "\n");
}

// ============================================================
// URL helpers
// ============================================================

function normalize_url(string $url): string {
    $p = parse_url($url);
    if ($p === false || empty($p['host'])) {
        return strtolower($url);
    }
    $query = '';
    if (!empty($p['query'])) {
        parse_str($p['query'], $params);
        foreach (['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term',
                  'fbclid', 'gclid', 'mc_cid', 'mc_eid'] as $t) {
            unset($params[$t]);
        }
        if ($params) $query = '?' . http_build_query($params);
    }
    $host = preg_replace('/^www\./', '', $p['host']);
    $path = $p['path'] ?? '';
    return rtrim(strtolower($host . $path . $query), '/');
}

function hash_url(string $url): string {
    return substr(hash('sha256', normalize_url($url)), 0, 16);
}

/**
 * Key for collapsing syndicated copies of one story within a run. Google News
 * titles end in " - Publisher"; a wire story shows up under a dozen outlets.
 * Returns '' for short titles so generic headlines are not merged.
 */
function headline_key(string $title): string {
    // Strip up to two trailing publisher segments ("... - ABC News - Breaking
    // News"); a segment may hold a bare hyphen ("News-Press NOW").
    $stripped = $title;
    for ($i = 0; $i < 2; $i++) {
        $next = preg_replace('/\s+[-|\x{2013}\x{2014}]\s+(?:(?!\s[-|\x{2013}\x{2014}]\s).){2,60}$/u', '', $stripped);
        if ($next === null || $next === $stripped || mb_strlen(normalize_name($next)) < 25) break;
        $stripped = $next;
    }
    $key = normalize_name($stripped);
    return mb_strlen($key) >= 25 ? $key : '';
}

function host_of(string $url): string {
    $h = parse_url($url, PHP_URL_HOST);
    if (!is_string($h) || $h === '') return '';
    return strtolower(preg_replace('/^www\./', '', $h));
}

/**
 * Extract the underlying article host — unwraps web.archive.org Wayback URLs
 * to the original host so it can match the facility-owned-website set.
 */
function article_host_of(string $url): string {
    $h = host_of($url);
    if ($h !== 'web.archive.org') return $h;
    $path = parse_url($url, PHP_URL_PATH);
    if (!is_string($path)) return $h;
    $inner = preg_replace('#^/web/[^/]+/#', '', $path);
    if ($inner === $path) return $h;
    return host_of(str_starts_with($inner, 'http') ? $inner : 'http://' . $inner);
}

// ============================================================
// State
// ============================================================

function load_state(): array {
    $s = load_json_file(STATE_FILE, null);
    if (is_array($s)) {
        if (!isset($s['seenUrls']) || !is_array($s['seenUrls'])) $s['seenUrls'] = [];
        if (!isset($s['seenHeadlines']) || !is_array($s['seenHeadlines'])) $s['seenHeadlines'] = [];
        if (!isset($s['rejectedUrls']) || !is_array($s['rejectedUrls'])) $s['rejectedUrls'] = [];
        if (!isset($s['pending']) || !is_array($s['pending'])) $s['pending'] = [];
        if (!isset($s['stats']) || !is_array($s['stats'])) {
            $s['stats'] = ['discovered' => 0, 'submitted' => 0, 'rejected' => 0];
        }
        // Version 3 (2026-10): the sweep walks a cursor over the sorted request
        // list instead of a date-picked shard, and remembers when each name was
        // last queried. A version-2 file simply starts the walk at 0.
        if (!isset($s['sweepCursor']) || !is_int($s['sweepCursor']) || $s['sweepCursor'] < 0) $s['sweepCursor'] = 0;
        if (!isset($s['sweptAt']) || !is_array($s['sweptAt'])) $s['sweptAt'] = [];
        $s['version'] = 3;
        return $s;
    }
    return ['version' => 3, 'lastRun' => null, 'seenUrls' => [], 'seenHeadlines' => [], 'rejectedUrls' => [], 'pending' => [],
        'sweepCursor' => 0, 'sweptAt' => [], 'stats' => ['discovered' => 0, 'submitted' => 0, 'rejected' => 0]];
}

function save_state(array $state): void {
    $sweptCutoff = gmdate('Y-m-d', time() - SWEPT_AT_DAYS * 86400);
    $state['sweptAt'] = array_filter(
        is_array($state['sweptAt'] ?? null) ? $state['sweptAt'] : [],
        static fn($d) => is_string($d) && $d >= $sweptCutoff
    );
    if (count($state['seenUrls']) > MAX_SEEN_URLS) {
        $state['seenUrls'] = array_slice($state['seenUrls'], -MAX_SEEN_URLS);
    }
    $cutoff = gmdate('Y-m-d', time() - SEEN_HEADLINE_DAYS * 86400);
    $state['seenHeadlines'] = array_filter(
        is_array($state['seenHeadlines'] ?? null) ? $state['seenHeadlines'] : [],
        static fn($d) => is_string($d) && $d >= $cutoff
    );
    $rejectedCutoff = gmdate('Y-m-d', time() - REJECTED_URL_TTL_DAYS * 86400);
    $state['rejectedUrls'] = array_filter(
        is_array($state['rejectedUrls'] ?? null) ? $state['rejectedUrls'] : [],
        static fn($d) => is_string($d) && $d >= $rejectedCutoff
    );
    // Carried candidates expire; keep the newest when the queue is over the cap.
    $pendingCutoff = gmdate('Y-m-d', time() - PENDING_MAX_AGE_DAYS * 86400);
    $pending = array_values(array_filter(
        is_array($state['pending'] ?? null) ? $state['pending'] : [],
        static fn($p) => is_array($p) && is_string($p['addedAt'] ?? null) && $p['addedAt'] >= $pendingCutoff
    ));
    if (count($pending) > PENDING_MAX_ENTRIES) $pending = array_slice($pending, -PENDING_MAX_ENTRIES);
    $state['pending'] = $pending;
    save_json_file(STATE_FILE, $state);
}

function persist_rejected(array $newEntries): void {
    $existing = load_json_file(REJECTED_FILE, ['entries' => []]);
    $existingEntries = is_array($existing['entries'] ?? null) ? $existing['entries'] : [];
    $ts = gmdate('Y-m-d\TH:i:s\Z');
    // Rejected candidates are never marked "seen" (so a keyword-list change can
    // rescue them later), which means the same article is re-rejected on every
    // run it stays in the feed. Log each link once so the file stays useful
    // for filter tuning instead of filling up with repeats.
    $known = [];
    foreach ($existingEntries as $e) {
        if (!empty($e['link'])) $known[$e['link']] = true;
    }
    $fresh = [];
    foreach ($newEntries as $r) {
        $link = $r['link'] ?? '';
        if ($link !== '' && isset($known[$link])) continue;
        if ($link !== '') $known[$link] = true;
        $fresh[] = array_merge(['ts' => $ts], $r);
    }
    if (!$fresh) return;
    $merged = array_slice(array_merge($fresh, $existingEntries), 0, MAX_REJECTED_ENTRIES);
    save_json_file(REJECTED_FILE, ['lastUpdated' => $ts, 'entries' => $merged]);
}

// ============================================================
// Blacklist
// ============================================================

/** Returns ['hostBlocked' => callable, 'pathBlocked' => callable]. */
function is_pdf_url(string $url): bool {
    $path = parse_url($url, PHP_URL_PATH);
    return is_string($path) && str_ends_with(strtolower($path), '.pdf');
}

function build_blacklist_matcher(): array {
    global $HARD_BLOCKED_HOSTS;
    $bl = load_json_file(BLACKLIST_FILE, []);
    $allDomains = array_merge(
        is_array($bl['selfDomains'] ?? null) ? $bl['selfDomains'] : [],
        is_array($bl['spamDomains'] ?? null) ? $bl['spamDomains'] : [],
        is_array($bl['pressReleaseWires'] ?? null) ? $bl['pressReleaseWires'] : [],
        is_array($bl['industryPromoDomains'] ?? null) ? $bl['industryPromoDomains'] : [],
        is_array($bl['financeDomains'] ?? null) ? $bl['financeDomains'] : [],
        is_array($bl['nonArticleDomains'] ?? null) ? $bl['nonArticleDomains'] : []
    );
    $allowOverrides = array_map(
        static fn($d) => strtolower(trim((string)$d)),
        is_array($bl['allowlistOverrides'] ?? null) ? $bl['allowlistOverrides'] : []
    );
    $pathPatterns = array_values(array_filter(array_map(
        static fn($p) => strtolower(trim((string)$p)),
        is_array($bl['urlPathPatterns'] ?? null) ? $bl['urlPathPatterns'] : []
    )));

    $exact = [];
    $suffixes = [];
    foreach ($allDomains as $raw) {
        $d = strtolower(trim((string)$raw));
        if ($d === '') continue;
        if (str_starts_with($d, '*.')) $suffixes[] = substr($d, 2);
        else $exact[$d] = true;
    }

    $hostBlocked = static function (string $host) use ($allowOverrides, $exact, $suffixes, $HARD_BLOCKED_HOSTS) {
        if ($host === '') return false;
        $h = preg_replace('/^www\./', '', strtolower($host));
        if (in_array($h, $allowOverrides, true)) return false;
        if (in_array($h, $HARD_BLOCKED_HOSTS, true)) return true;
        if (isset($exact[$h])) return true;
        foreach ($suffixes as $sfx) {
            if ($h === $sfx || str_ends_with($h, '.' . $sfx)) return true;
        }
        return false;
    };

    $pathBlocked = static function (string $url) use ($pathPatterns) {
        if ($url === '' || !$pathPatterns) return false;
        $p = parse_url($url, PHP_URL_PATH);
        if (!is_string($p)) return false;
        $p = strtolower($p);
        foreach ($pathPatterns as $pat) {
            if (str_contains($p, $pat)) return $pat;
        }
        return false;
    };

    return ['hostBlocked' => $hostBlocked, 'pathBlocked' => $pathBlocked];
}

// ============================================================
// Search-term config (discovery-queries.json)
// ============================================================

const DEFAULT_FACILITY_KEYWORDS = [
    'abuse', 'lawsuit', 'arrested', 'indicted', 'investigation',
    'closure', 'raid', 'allegations', 'survivor'
];

/**
 * Load the editable search-term config. Every field has a fallback so a
 * missing or half-edited file degrades to the pre-2026-09 behavior (facility
 * queries only) instead of aborting the run.
 */
function load_discovery_queries(): array {
    $raw = load_json_file(QUERIES_FILE, []);
    if (!is_array($raw)) $raw = [];
    $strList = static function ($v): array {
        if (!is_array($v)) return [];
        $out = [];
        foreach ($v as $s) {
            $s = trim((string)($s ?? ''));
            if ($s !== '') $out[] = $s;
        }
        return $out;
    };

    $facilityKeywords = $strList($raw['facilityKeywords'] ?? null);
    $batchKeywords = $strList($raw['facilityBatchKeywords'] ?? null);
    $topicQueries = [];
    foreach ((is_array($raw['topicQueries'] ?? null) ? $raw['topicQueries'] : []) as $i => $t) {
        if (!is_array($t) || !is_string($t['q'] ?? null) || trim($t['q']) === '') continue;
        $topicQueries[] = [
            'label' => trim((string)($t['label'] ?? ('topic-' . ($i + 1)))),
            'q' => trim($t['q']),
            'boost' => is_numeric($t['boost'] ?? null) ? (int)$t['boost'] : 2,
            // Phrases (lower-cased) that must appear in the headline/blurb for
            // the boost to apply. Google News relevance matching is loose.
            'match' => array_map('mb_strtolower', $strList($t['match'] ?? null)),
            'when' => is_string($t['when'] ?? null) ? trim($t['when']) : null,
            // The topic's own phrase plus a weak word ("ban on", "legislation")
            // is not enough: foreign politics passes that ("conversion therapy").
            'strongOnly' => !empty($t['strongKeywordOnly']),
            // Words (lower-cased, matched at the start of a word) of which one
            // must also appear for the topic phrase to count: "boot camp"
            // alone matched a Korean reality show, "Divorce Re-Boot Camp".
            'requireAny' => array_map('mb_strtolower', $strList($t['requireAny'] ?? null))
        ];
    }

    // Headline patterns (case-insensitive regex, written so PHP and JS read
    // them alike) that reject a Google News item outright.
    $rejectHeadlines = [];
    foreach ($strList($raw['rejectHeadlines'] ?? null) as $re) {
        $pcre = '~' . str_replace('~', '\\~', $re) . '~iu';
        if (@preg_match($pcre, '') === false) {
            kop_warn("  ! rejectHeadlines: bad pattern skipped: {$re}");
            continue;
        }
        $rejectHeadlines[] = ['source' => $re, 'pcre' => $pcre];
    }

    $generic = [];
    foreach ($strList($raw['genericQueryNames'] ?? null) as $n) {
        $k = normalize_name($n);
        if ($k !== '') $generic[$k] = true;
    }

    $ignoreAliases = [];
    foreach ($strList($raw['ignoreAliases'] ?? null) as $n) {
        $k = normalize_name($n);
        if ($k !== '') $ignoreAliases[$k] = true;
    }
    $genericAliasWords = [];
    foreach ($strList($raw['genericAliasWords'] ?? null) as $n) {
        $k = normalize_name($n);
        if ($k !== '') $genericAliasWords[$k] = true;
    }
    $maxPerTopic = is_numeric($raw['maxItemsPerTopic'] ?? null) && (int)$raw['maxItemsPerTopic'] > 0
        ? (int)$raw['maxItemsPerTopic'] : 25;
    $maxTopicSubmissions = is_numeric($raw['maxTopicSubmissionsPerRun'] ?? null) && (int)$raw['maxTopicSubmissionsPerRun'] >= 0
        ? (int)$raw['maxTopicSubmissionsPerRun'] : 20;

    return [
        'facilityKeywords' => $facilityKeywords ?: DEFAULT_FACILITY_KEYWORDS,
        // Fewer words for a batched request, which spends most of Google's
        // 32 words on the names.
        'facilityBatchKeywords' => $batchKeywords ?: DEFAULT_FACILITY_KEYWORDS,
        'facilityRecency' => is_string($raw['facilityRecency'] ?? null) ? trim($raw['facilityRecency']) : '',
        // The batched sweep covers every name daily, so its window is short;
        // --recency overrides it for one run.
        'facilityBatchRecency' => RECENCY_OVERRIDE !== '' ? RECENCY_OVERRIDE
            : (is_string($raw['facilityBatchRecency'] ?? null) ? trim($raw['facilityBatchRecency']) : 'when:3d'),
        'topicRecency' => is_string($raw['topicRecency'] ?? null) ? trim($raw['topicRecency']) : '',
        'maxItemsPerTopic' => $maxPerTopic,
        'maxTopicSubmissionsPerRun' => $maxTopicSubmissions,
        'topicQueries' => $topicQueries,
        'genericQueryNames' => $generic,
        'ignoreAliases' => $ignoreAliases,
        'genericAliasWords' => $genericAliasWords,
        'rejectHeadlines' => $rejectHeadlines
    ];
}

// ============================================================
// Facility index (built fresh from API each run)
// ============================================================

function parse_location($loc): array {
    global $STATE_NAMES;
    if (!is_string($loc)) return ['city' => '', 'state' => ''];
    $trimmed = trim($loc);
    if ($trimmed === '') return ['city' => '', 'state' => ''];

    $parts = array_values(array_filter(array_map('trim', explode(',', $trimmed)), static fn($p) => $p !== ''));
    if (count($parts) < 2) {
        // 700+ records send only their state ("KY"). Read as a city, it left
        // the state empty: no state in the sweep query, no state check, and
        // "OK" counted as a city named in any text containing "book".
        if (preg_match('/^[A-Z]{2}$/', $trimmed) && isset($STATE_NAMES[$trimmed])) {
            return ['city' => '', 'state' => $trimmed];
        }
        foreach ($STATE_NAMES as $abbr => $full) {
            if (strcasecmp($trimmed, $full) === 0) return ['city' => '', 'state' => $abbr];
        }
        return ['city' => $trimmed, 'state' => ''];
    }

    $city = $parts[0];
    $tail = $parts[count($parts) - 1];

    if (preg_match('/^[A-Z]{2}$/', $tail) && isset($STATE_NAMES[$tail])) {
        return ['city' => $city, 'state' => $tail];
    }
    foreach ($STATE_NAMES as $abbr => $full) {
        if (strcasecmp($tail, $full) === 0) {
            return ['city' => $city, 'state' => $abbr];
        }
    }
    return ['city' => $city, 'state' => ''];
}

/**
 * Walk every project and build the deduplicated facility/operator index, the
 * facility-owned host set, and the generic-alias set. Mirrors the JS shape:
 * ['facilities' => [...], 'ownHosts' => [host => true], 'genericAliases' => [norm => true]]
 */
function build_facility_index(array $apiResponse, array $ignoreAliases = [], array $genericAliasWords = []): array {
    global $STATE_NAMES;

    // Names that are never a facility in a headline: bad records ("Kansas",
    // "Behavioral Health") from ignoreAliases, plus every bare state name.
    $ignore = $ignoreAliases;
    foreach ($STATE_NAMES as $full) $ignore[normalize_name($full)] = true;

    $projects = $apiResponse['projects'] ?? [];
    $byKey = [];
    $ownHosts = [];

    $collectHosts = static function ($urls) use (&$ownHosts) {
        if (!is_array($urls)) return;
        foreach ($urls as $u) {
            if (!is_string($u) || trim($u) === '') continue;
            $h = article_host_of(trim($u));
            if ($h !== '') $ownHosts[$h] = true;
        }
    };

    $addEntry = static function (array $entry) use (&$byKey, $ignore) {
        if ($entry['queryName'] === '' || mb_strlen($entry['queryName']) < 4) return;
        if (isset($ignore[normalize_name($entry['queryName'])])) return;
        $entry['aliases'] = array_values(array_filter(
            $entry['aliases'],
            static fn($a) => !isset($ignore[normalize_name($a)])
        ));
        // The names the sweep queries: every name the record has had, as
        // written (no shortened variants), minus the ignored ones.
        $entry['queryNames'] = array_values(array_filter(
            $entry['queryNames'],
            static fn($a) => !isset($ignore[normalize_name($a)])
        ));
        $key = normalize_name($entry['queryName']) . '|' . ($entry['state'] ?? '');
        if (isset($byKey[$key])) {
            $existing = &$byKey[$key];
            foreach (['aliases', 'queryNames'] as $field) {
                $seen = [];
                foreach ($existing[$field] as $a) $seen[normalize_name($a)] = true;
                foreach ($entry[$field] as $alias) {
                    $k = normalize_name($alias);
                    if ($k !== '' && !isset($seen[$k])) {
                        $existing[$field][] = $alias;
                        $seen[$k] = true;
                    }
                }
            }
            if ($existing['city'] === '' && $entry['city'] !== '') $existing['city'] = $entry['city'];
            unset($existing);
            return;
        }
        $byKey[$key] = $entry;
    };

    // Every record and every name it has had, whatever its status: a closed
    // program's lawsuits, settlements and survivors' stories are news too.
    foreach ($projects as $project) {
        $data = $project['data'] ?? [];
        $operator = $data['operator'] ?? [];
        $operatorName = (string)($operator['name'] ?? ($project['name'] ?? ''));

        $collectHosts($operator['websites'] ?? null);

        // --- Per-facility entries ---
        $facilities = is_array($data['facilities'] ?? null) ? $data['facilities'] : [];
        foreach ($facilities as $f) {
            $ident = $f['identification'] ?? [];
            $period = $f['operatingPeriod'] ?? [];

            $collectHosts($f['profileLinks'] ?? null);
            if (!empty($f['sourceOperator'])) $collectHosts($f['sourceOperator']['websites'] ?? null);

            $primaryName = trim((string)($ident['currentName'] ?? ''));
            if ($primaryName === '') $primaryName = (string)($ident['name'] ?? '');
            if ($primaryName === '') continue;

            $names = [$primaryName];
            if (!empty($ident['name']) && $ident['name'] !== $primaryName) $names[] = $ident['name'];
            foreach ([($ident['otherNames'] ?? null), ($ident['pastNames'] ?? null)] as $list) {
                if (is_array($list)) {
                    foreach ($list as $n) {
                        if (is_string($n) && trim($n) !== '') $names[] = trim($n);
                    }
                }
            }
            $aliases = $names;
            foreach ($names as $a) {
                foreach (generate_alias_variants($a) as $v) $aliases[] = $v;
            }

            $cs = parse_location($f['location'] ?? null);

            $addEntry([
                'queryName' => $primaryName,
                'queryNames' => $names,
                'aliases' => $aliases,
                'city' => $cs['city'],
                'state' => $cs['state'],
                'bucket' => 'facility',
                'operator' => $operatorName !== '' ? $operatorName : (string)($f['sourceOperator']['name'] ?? ''),
                'status' => (string)($period['status'] ?? '')
            ]);
        }

        // --- Operator-level entry (companies category only) ---
        if (($project['category'] ?? '') === 'companies' && $operatorName !== '') {
            $names = [$operatorName];
            foreach ([($operator['otherNames'] ?? null), ($operator['parentCompanies'] ?? null)] as $list) {
                if (is_array($list)) {
                    foreach ($list as $n) {
                        if (is_string($n) && trim($n) !== '') $names[] = trim($n);
                    }
                }
            }
            $aliases = $names;
            foreach ($names as $a) {
                foreach (generate_alias_variants($a) as $v) $aliases[] = $v;
            }
            $addEntry([
                'queryName' => $operatorName,
                'queryNames' => $names,
                'aliases' => $aliases,
                'city' => '',
                'state' => '',
                'bucket' => 'operator',
                'operator' => $operatorName,
                'status' => (string)($operator['status'] ?? '')
            ]);
        }
    }

    $facilities = array_values($byKey);

    // Generic aliases -- names shared across N+ deduped entries, and one- or
    // two-word names made only of common words ("Youth Reach", "Cornerstone",
    // "Turning Point"), which turn up in ordinary prose. These get stricter
    // state-validation in evaluate_candidate and are not searched on their
    // own. Longer common-word names ("New Hope Youth Center") stay distinctive
    // enough to match on their own.
    $aliasCounts = [];
    foreach ($facilities as $fac) {
        $seen = [];
        foreach ($fac['aliases'] as $alias) {
            $k = normalize_name($alias);
            if ($k === '' || mb_strlen($k) < 5 || isset($seen[$k])) continue;
            $seen[$k] = true;
            $aliasCounts[$k] = ($aliasCounts[$k] ?? 0) + 1;
        }
    }
    $genericAliases = [];
    foreach ($aliasCounts as $k => $n) {
        if ($n >= GENERIC_ALIAS_MIN_FACILITIES) {
            $genericAliases[$k] = true;
            continue;
        }
        $words = explode(' ', $k);
        $longWords = array_filter($words, static fn($w) => strlen($w) > 1);
        if ($genericAliasWords && count($longWords) <= 2) {
            $allCommon = true;
            foreach ($words as $w) {
                if (!isset($genericAliasWords[$w])) { $allCommon = false; break; }
            }
            if ($allCommon) $genericAliases[$k] = true;
        }
    }

    return ['facilities' => $facilities, 'ownHosts' => $ownHosts, 'genericAliases' => $genericAliases];
}

/**
 * The sweep's request list: one line per distinct name across every record,
 * sorted by name key so the cursor walks the same order run after run.
 * Each line: ['name', 'key', 'entries' => [index into $facilityIndex, ...],
 * 'state', 'city', 'bucket', 'mode' => 'batch'|'single'].
 *
 * Generic names (is_generic_query_name: the queries file's list, names shared
 * by 3+ records, one- or two-word names of common words) are not searched.
 * Short names, one or two words ("Grafton", "Day One", "Cypress Creek"), are
 * 'single': one request each with the state name, as the sweep always did,
 * because such a name in an article body is a street or a surname as often
 * as the program; so is a three-word name that is an everyday phrase ("A
 * Mother's Love", "A New Leaf": fewer than two words outside
 * genericAliasWords), which Google finds in any prose; the first dry run
 * matched "A Mother's Love" to a footballer's bereavement. Four words or more
 * ("New Hope Youth Center", "Pathway of Madison County") is a phrase prose
 * does not produce by chance. A short name with no state to check is dropped.
 * Everything else is 'batch': several names to one request, no state.
 */
function build_sweep_names(array $facilityIndex, array $queries, array $genericAliases): array {
    $common = $queries['genericAliasWords'];
    $distinctive = static function (string $key) use ($common): bool {
        $words = array_filter(explode(' ', $key), static fn($w) => strlen($w) > 1);
        $sig = count(array_filter($words, static fn($w) => !isset($common[$w])));
        return count($words) >= 4 || $sig >= 2;
    };
    $byKey = [];
    foreach ($facilityIndex as $i => $fac) {
        foreach ($fac['queryNames'] as $name) {
            $key = normalize_name($name);
            if ($key === '' || mb_strlen($key) < 4) continue;
            // The queries file's generic names are never searched; a name of
            // common words ("Day One") is searched single with its state (below),
            // and one shared by several records is dropped once counted.
            if (isset($queries['genericQueryNames'][$key])) continue;
            if (!isset($byKey[$key])) {
                $byKey[$key] = [
                    'name' => $name, 'key' => $key, 'entries' => [],
                    'state' => $fac['state'], 'city' => $fac['city'], 'bucket' => $fac['bucket'],
                ];
            }
            $line = &$byKey[$key];
            $line['entries'][] = $i;
            if ($line['state'] === '' && $fac['state'] !== '') { $line['state'] = $fac['state']; $line['city'] = $fac['city']; }
            unset($line);
        }
    }
    ksort($byKey, SORT_STRING);
    $out = [];
    foreach ($byKey as $line) {
        if (count($line['entries']) >= GENERIC_ALIAS_MIN_FACILITIES) continue;   // several records' name: whose?
        $words = count(explode(' ', $line['key']));
        if ($line['bucket'] === 'operator') {
            // A company has no state to check; one word of it is not searchable.
            if ($words < 2) continue;
            $line['mode'] = 'batch';
        } elseif ($words <= 2 || isset($genericAliases[$line['key']]) || !$distinctive($line['key'])) {
            if ($line['state'] === '') continue;
            $line['mode'] = 'single';
        } else {
            $line['mode'] = 'batch';
        }
        $out[] = $line;
    }
    return $out;
}

/**
 * Group the sweep names into requests: batch lines up to GN_BATCH_SIZE names
 * and GN_BATCH_WORDS words of names at a time (in key order, so the same
 * names share a request every day), single lines one each. Returns
 * [['mode', 'lines' => [line, ...]], ...], batches first.
 */
function build_sweep_requests(array $lines): array {
    $requests = [];
    $batch = [];
    $words = 0;
    foreach ($lines as $line) {
        if ($line['mode'] !== 'batch') continue;
        $w = count(explode(' ', $line['key']));
        if ($batch && ($words + $w > GN_BATCH_WORDS || count($batch) === GN_BATCH_SIZE)) {
            $requests[] = ['mode' => 'batch', 'lines' => $batch];
            $batch = [];
            $words = 0;
        }
        $batch[] = $line;
        $words += $w;
    }
    if ($batch) $requests[] = ['mode' => 'batch', 'lines' => $batch];
    foreach ($lines as $line) {
        if ($line['mode'] === 'single') $requests[] = ['mode' => 'single', 'lines' => [$line]];
    }
    return $requests;
}

/**
 * The requests this run makes: SWEEP_RUNS_PER_DAY runs share one pass, so a
 * run takes ceil(total / runs) requests from the cursor, wrapping at the end.
 * Returns [requests, nextCursor].
 */
function sweep_slice(array $requests, int $cursor, ?int $cap = null): array {
    $total = count($requests);
    if ($total === 0) return [[], 0];
    $cursor %= $total;
    $take = (int)ceil($total / SWEEP_RUNS_PER_DAY);
    if ($cap !== null) $take = min($take, max(0, $cap));
    $slice = [];
    for ($i = 0; $i < $take; $i++) $slice[] = $requests[($cursor + $i) % $total];
    return [$slice, ($cursor + $take) % $total];
}

// ============================================================
// RSS / Reddit fetchers
// ============================================================

function decode_entities($s): string {
    $s = (string)($s ?? '');
    $s = str_replace(
        ['&amp;', '&lt;', '&gt;', '&quot;', '&apos;'],
        ['&', '<', '>', '"', "'"],
        $s
    );
    $s = preg_replace_callback('/&#(\d+);/', static fn($m) => mb_chr((int)$m[1], 'UTF-8'), $s);
    $s = preg_replace_callback('/&#x([0-9a-f]+);/i', static fn($m) => mb_chr((int)hexdec($m[1]), 'UTF-8'), $s);
    return $s;
}

function strip_cdata($s): string {
    return preg_replace('/<!\[CDATA\[(.*?)\]\]>/s', '$1', (string)($s ?? ''));
}

function tag_content(string $xml, string $tag): string {
    if (preg_match('/<' . $tag . '\b[^>]*>(.*?)<\/' . $tag . '>/is', $xml, $m)) {
        return trim(decode_entities(strip_cdata($m[1])));
    }
    return '';
}

function parse_rss_items(string $xml): array {
    $items = [];
    if (!preg_match_all('/<item\b[^>]*>(.*?)<\/item>/is', $xml, $matches)) {
        return $items;
    }
    foreach ($matches[1] as $block) {
        $sourceUrl = '';
        $sourceName = '';
        if (preg_match('/<source\b([^>]*)>(.*?)<\/source>/is', $block, $sMatch)) {
            if (preg_match('/\burl="([^"]+)"/i', $sMatch[1], $ua)) $sourceUrl = trim($ua[1]);
            $sourceName = trim(decode_entities(strip_cdata($sMatch[2])));
        }
        $items[] = [
            'title' => tag_content($block, 'title'),
            'link' => tag_content($block, 'link'),
            'pubDate' => tag_content($block, 'pubDate'),
            'description' => trim(preg_replace('/\s+/', ' ', preg_replace('/<[^>]+>/', ' ', tag_content($block, 'description')))),
            'sourceUrl' => $sourceUrl,
            'sourceName' => $sourceName
        ];
    }
    return $items;
}

function google_news_search_url(string $query): string {
    return 'https://news.google.com/rss/search?' . http_build_query([
        'q' => $query, 'hl' => 'en-US', 'gl' => 'US', 'ceid' => 'US:en'
    ]);
}

/**
 * A single-name sweep request: when:30d "<facility>" "<State>" (kw OR kw ...).
 * The window goes first: Google reads about 32 words and drops the rest.
 */
function facility_news_query(array $facility, array $queries): string {
    global $STATE_NAMES;
    $nameToken = '"' . $facility['queryName'] . '"';
    $stateToken = ($facility['state'] !== '' && isset($STATE_NAMES[$facility['state']]))
        ? '"' . $STATE_NAMES[$facility['state']] . '"' : '';
    $kwToken = '(' . implode(' OR ', $queries['facilityKeywords']) . ')';
    return implode(' ', array_filter(
        [$queries['facilityRecency'], $nameToken, $stateToken, $kwToken],
        static fn($t) => $t !== ''
    ));
}

/**
 * A batched sweep request: when:3d ("A" OR "B" OR ...) (kw OR kw ...). No
 * state: the names are distinctive, and the keyword group is what makes
 * Google match the body (that is how the Desert Willow closure was found).
 */
function batch_news_query(array $names, array $queries): string {
    $nameToken = '(' . implode(' OR ', array_map(static fn($n) => '"' . str_replace('"', '', $n) . '"', $names)) . ')';
    $kwToken = '(' . implode(' OR ', $queries['facilityBatchKeywords']) . ')';
    return implode(' ', array_filter([$queries['facilityBatchRecency'], $nameToken, $kwToken], static fn($t) => $t !== ''));
}

/** Topic query text plus its recency window (per-query override, else global). */
function topic_news_query(array $topic, array $queries): string {
    $when = $topic['when'] !== null ? $topic['when'] : $queries['topicRecency'];
    return implode(' ', array_filter([$topic['q'], $when], static fn($t) => $t !== ''));
}

/**
 * Fetch one Google News RSS search and drop items older than the age cutoff.
 * Returns ['items' => [...], 'failed' => bool] so the caller can drive the
 * throttling breaker.
 */
function fetch_google_news_items(string $query, int $cap, string $label): array {
    try {
        $xml = fetch_text(google_news_search_url($query));
        $cutoff = time() - MAX_ARTICLE_AGE_DAYS * 86400;
        $fresh = array_values(array_filter(parse_rss_items($xml), static function ($item) use ($cutoff) {
            if ($item['pubDate'] === '') return true;   // keep if unparseable
            $t = strtotime($item['pubDate']);
            return $t === false || $t >= $cutoff;
        }));
        return ['items' => array_slice($fresh, 0, $cap), 'failed' => false];
    } catch (Throwable $err) {
        kop_warn("  ! Google News failed for \"{$label}\": {$err->getMessage()}");
        return ['items' => [], 'failed' => true];
    }
}

/**
 * One sweep request (see build_sweep_requests). Every item carries the names
 * it was searched for ('batchNames', their keys in 'batchKeys') and the mode,
 * so the filter knows which programs to attribute it to. 'facilityQuery'
 * stays the readable form for the log and the Scraper finds screen.
 */
function fetch_google_news_for_request(array $request, array $queries): array {
    $lines = $request['lines'];
    $names = array_map(static fn($l) => $l['name'], $lines);
    if ($request['mode'] === 'single') {
        $line = $lines[0];
        $r = fetch_google_news_items(
            facility_news_query(['queryName' => $line['name'], 'state' => $line['state']], $queries),
            PER_FACILITY_CAP, $line['name']);
    } else {
        $r = fetch_google_news_items(batch_news_query($names, $queries), GN_BATCH_CAP, implode(' | ', $names));
    }
    $r['items'] = array_map(static fn($item) => array_merge($item, [
        'origin' => 'google-news',
        'facilityQuery' => implode(' | ', $names),
        'sweepMode' => $request['mode'],
        'batchNames' => $names,
        'batchKeys' => array_map(static fn($l) => $l['key'], $lines),
        'facilityState' => $lines[0]['state'],
        'facilityCity' => $lines[0]['city']
    ]), $r['items']);
    return $r;
}

function fetch_google_news_for_topic(array $topic, array $queries): array {
    $r = fetch_google_news_items(topic_news_query($topic, $queries), $queries['maxItemsPerTopic'], 'topic:' . $topic['label']);
    $r['items'] = array_map(static fn($item) => array_merge($item, [
        'origin' => 'google-news-topic',
        'topicQuery' => $topic['label'],
        'topicBoost' => $topic['boost'],
        'topicMatch' => $topic['match'],
        'topicStrongOnly' => $topic['strongOnly'],
        'topicRequireAny' => $topic['requireAny']
    ]), $r['items']);
    return $r;
}

/**
 * Should Google News be queried for this facility at all? Names like "The
 * Children's Home" or "Juvenile Detention Center" return nothing but noise
 * and burn a throttled request. The facility still participates in alias
 * matching against Reddit and topic candidates.
 */
function is_generic_query_name(array $facility, array $queries, array $genericAliases): bool {
    $norm = normalize_name($facility['queryName']);
    if ($norm === '') return true;
    if (isset($queries['genericQueryNames'][$norm])) return true;
    if (isset($genericAliases[$norm])) return true;
    return false;
}

// Reddit hard-blocks unauthenticated .json API requests, but the Atom feeds
// remain open under a tight per-IP rate limit. Preferred path: OAuth via a
// free reddit "script" app — set REDDIT_CLIENT_ID + REDDIT_CLIENT_SECRET.
define('REDDIT_FEED_URL', 'https://www.reddit.com/r/troubledteens/new.rss?limit=100');
define('REDDIT_CLIENT_ID', getenv('REDDIT_CLIENT_ID') ?: '');
define('REDDIT_CLIENT_SECRET', getenv('REDDIT_CLIENT_SECRET') ?: '');

/**
 * Undo reddit's markdown escaping in selftext links: an escaped link renders
 * as href="URL%5C%5D(URL)", and underscores come through as %5C_.
 */
function clean_reddit_url(string $u): string {
    $u = str_replace('&amp;', '&', $u);
    $u = preg_replace('/(?:%5C)*(?:%5D|\])\(.*$/i', '', $u);
    return preg_replace('/(?:%5C)+/i', '', $u);
}

function fetch_reddit_oauth_listing(): array {
    $tokenRes = http_request('https://www.reddit.com/api/v1/access_token', [
        'method' => 'POST',
        'timeoutMs' => 30000,
        'headers' => [
            'Authorization' => 'Basic ' . base64_encode(REDDIT_CLIENT_ID . ':' . REDDIT_CLIENT_SECRET),
            'Content-Type' => 'application/x-www-form-urlencoded'
        ],
        'body' => 'grant_type=client_credentials'
    ]);
    $tokenBody = json_decode($tokenRes['body'], true) ?: [];
    if ($tokenRes['status'] < 200 || $tokenRes['status'] >= 300 || empty($tokenBody['access_token'])) {
        throw new RuntimeException("OAuth token request failed: HTTP {$tokenRes['status']}");
    }
    $res = http_request('https://oauth.reddit.com/r/troubledteens/new?limit=100', [
        'timeoutMs' => 30000,
        'headers' => ['Authorization' => 'Bearer ' . $tokenBody['access_token']]
    ]);
    if ($res['status'] < 200 || $res['status'] >= 300) {
        throw new RuntimeException("OAuth listing failed: HTTP {$res['status']}");
    }
    $json = json_decode($res['body'], true);
    if (!is_array($json)) throw new RuntimeException('OAuth listing: invalid JSON');
    return $json;
}

function candidates_from_listing(array $json): array {
    global $HARD_BLOCKED_HOSTS;
    $posts = $json['data']['children'] ?? [];
    $out = [];
    foreach ($posts as $p) {
        $post = $p['data'] ?? [];
        $title = (string)($post['title'] ?? '');
        $permalink = !empty($post['permalink']) ? 'https://www.reddit.com' . $post['permalink'] : '';
        $created = !empty($post['created_utc']) ? gmdate('D, d M Y H:i:s \G\M\T', (int)$post['created_utc']) : '';

        if (!empty($post['url']) && empty($post['is_self'])) {
            $h = host_of((string)$post['url']);
            if ($h !== '' && !in_array($h, $HARD_BLOCKED_HOSTS, true)) {
                $out[] = [
                    'title' => $title, 'link' => (string)$post['url'], 'pubDate' => $created,
                    'description' => substr((string)($post['selftext'] ?? ''), 0, 500),
                    'sourceUrl' => '', 'sourceName' => '',
                    'origin' => 'reddit-link',
                    'facilityQuery' => null, 'facilityState' => '', 'facilityCity' => '',
                    'redditPermalink' => $permalink
                ];
            }
        }

        if (!empty($post['selftext'])) {
            preg_match_all('/https?:\/\/[^\s()<>"\'\[\]*_`]+/', (string)$post['selftext'], $m);
            $dedup = [];
            foreach ($m[0] as $raw) {
                $u = clean_reddit_url(preg_replace('/[.,;:!?*_`)\]]+$/', '', $raw));
                $h = host_of($u);
                if ($h === '' || in_array($h, $HARD_BLOCKED_HOSTS, true) || isset($dedup[$u])) continue;
                $dedup[$u] = true;
                $out[] = [
                    'title' => $title, 'link' => $u, 'pubDate' => $created,
                    'description' => substr((string)$post['selftext'], 0, 500),
                    'sourceUrl' => '', 'sourceName' => '',
                    'origin' => 'reddit-selftext',
                    'facilityQuery' => null, 'facilityState' => '', 'facilityCity' => '',
                    'redditPermalink' => $permalink
                ];
            }
        }
    }
    return $out;
}

function decode_xml_entities($s): string {
    return str_replace(
        ['&lt;', '&gt;', '&quot;', '&#039;', '&#39;', '&#x27;', '&#X27;', '&#32;', '&amp;'],
        ['<', '>', '"', "'", "'", "'", "'", ' ', '&'],
        (string)$s
    );
}

function fetch_reddit_feed(): string {
    $lastErr = null;
    for ($i = 0; $i < 3; $i++) {
        if ($i > 0) sleep_ms(35000);   // unauthenticated rate window is ~1/min
        try {
            $res = http_request(REDDIT_FEED_URL, ['timeoutMs' => 30000, 'headers' => ['Accept' => '*/*']]);
            if ($res['status'] < 200 || $res['status'] >= 300) throw new RuntimeException("HTTP {$res['status']}");
            if (!str_contains($res['body'], '<entry>')) throw new RuntimeException('feed empty or challenge page');
            return $res['body'];
        } catch (Throwable $err) {
            $lastErr = $err;
            $n = $i + 1;
            kop_warn("  ! Reddit feed attempt {$n}/3: {$err->getMessage()}");
        }
    }
    throw $lastErr;
}

function fetch_reddit_candidates(): array {
    global $HARD_BLOCKED_HOSTS;
    if (REDDIT_CLIENT_ID !== '' && REDDIT_CLIENT_SECRET !== '') {
        try {
            return candidates_from_listing(fetch_reddit_oauth_listing());
        } catch (Throwable $err) {
            kop_warn("  ! Reddit OAuth path failed ({$err->getMessage()}); falling back to Atom feed");
        }
    }
    try {
        $xml = fetch_reddit_feed();
        $out = [];

        foreach (array_slice(explode('<entry>', $xml), 1) as $block) {
            $title = decode_xml_entities(preg_match('/<title>(.*?)<\/title>/s', $block, $m) ? $m[1] : '');
            $permalink = decode_xml_entities(preg_match('/<link href="([^"]+)"/', $block, $m) ? $m[1] : '');
            $published = preg_match('/<(?:published|updated)>([^<]+)</', $block, $m) ? $m[1] : '';
            $publishedTs = $published !== '' ? strtotime($published) : false;
            $created = $publishedTs !== false ? gmdate('D, d M Y H:i:s \G\M\T', $publishedTs) : '';
            $html = decode_xml_entities(preg_match('/<content type="html">(.*?)<\/content>/s', $block, $m) ? $m[1] : '');
            $bodyText = trim(preg_replace('/\s+/', ' ', preg_replace('/<[^>]+>/', ' ', $html)));

            $dedup = [];
            $pushCandidate = function (string $u, string $origin) use (&$out, &$dedup, $title, $created, $bodyText, $permalink, $HARD_BLOCKED_HOSTS) {
                $u = clean_reddit_url($u);
                $h = host_of($u);
                if ($h === '' || in_array($h, $HARD_BLOCKED_HOSTS, true) || isset($dedup[$u])) return;
                $dedup[$u] = true;
                $out[] = [
                    'title' => $title, 'link' => $u, 'pubDate' => $created,
                    'description' => substr($bodyText, 0, 500),
                    'sourceUrl' => '', 'sourceName' => '',
                    'origin' => $origin,
                    'facilityQuery' => null, 'facilityState' => '', 'facilityCity' => '',
                    'redditPermalink' => $permalink
                ];
            };

            // The "[link]" anchor is the post target: an external URL for link
            // posts, the permalink itself for self posts (blocked host → no-op).
            $linkAnchor = preg_match('/<a href="([^"]+)">\s*\[link\]/', $html, $m) ? $m[1] : '';
            if ($linkAnchor !== '') $pushCandidate($linkAnchor, 'reddit-link');

            if (preg_match_all('/<a href="([^"]+)"/', $html, $anchors)) {
                foreach ($anchors[1] as $href) {
                    if ($href !== $linkAnchor) $pushCandidate($href, 'reddit-selftext');
                }
            }
        }
        return $out;
    } catch (Throwable $err) {
        kop_warn("  ! Reddit fetch failed: {$err->getMessage()}");
        return [];
    }
}

// ============================================================
// Scoring + state-match validation
// ============================================================

/**
 * Match candidate text against the facility index. Returns the longest
 * matched alias (favors specificity) or null.
 */
function match_facility(string $text, array $facilityIndex): ?array {
    // Whole-word match on a punctuation-free haystack: quotes, dashes and
    // possessives around a name ("'Youth Reach'", "Hyde School's") no
    // longer hide it, and a name never matches inside another word.
    $hay = ' ' . normalize_name($text) . ' ';
    $best = null;

    foreach ($facilityIndex as $fac) {
        foreach ($fac['aliases'] as $alias) {
            $needle = normalize_name($alias);
            if (mb_strlen($needle) < 5) continue;
            if (str_contains($hay, " {$needle} ")) {
                if ($best === null || mb_strlen((string)$alias) > mb_strlen($best['matchedAlias'])) {
                    $best = ['facility' => $fac, 'matchedAlias' => (string)$alias];
                }
            }
        }
    }
    return $best;
}

/** Returns [abbr => true] for every state signal found in the text. */
/**
 * First keyword of the list that starts a word in the lower-cased text, else
 * null. A keyword may run on ("abuse" matches "abused") but it cannot start
 * inside a word: "killed" no longer matches "Skilled", "sued" "issued",
 * "fined" "confined", "raid" "afraid", "closed" "disclosed".
 */
function find_keyword(array $list, string $hay): ?string {
    foreach ($list as $kw) {
        $at = strpos($hay, $kw);
        while ($at !== false) {
            if ($at === 0 || !ctype_alnum($hay[$at - 1])) return $kw;
            $at = strpos($hay, $kw, $at + 1);
        }
    }
    return null;
}

function extract_state_signals(string $text): array {
    global $STATE_NAMES;
    $found = [];
    if ($text === '') return $found;
    $padded = ' ' . $text . ' ';
    foreach ($STATE_NAMES as $abbr => $full) {
        if (preg_match('/\b' . preg_quote($full, '/') . '\b/i', $padded)) $found[$abbr] = true;
    }
    // Two-letter abbreviations as standalone tokens (case-sensitive, like the JS)
    foreach (array_keys($STATE_NAMES) as $abbr) {
        if (preg_match('/[\s,(]' . $abbr . '[\s,.)]/', $padded)) $found[$abbr] = true;
    }
    return $found;
}

/**
 * Decide whether a candidate clears the filter. Same additive scoring and
 * rejection reasons as the JS version.
 */
function is_homepage_url(string $url): bool {
    $path = parse_url($url, PHP_URL_PATH);
    $query = parse_url($url, PHP_URL_QUERY);
    return ($path === null || $path === false || $path === '' || $path === '/') && ($query === null || $query === false || $query === '');
}

/**
 * Is the facility's city named in the text? Whole words, like match_facility():
 * "Bath" is not in "bathroom". Two letters is never a city.
 */
function text_names_city(string $text, string $city): bool {
    $c = normalize_name($city);
    if (mb_strlen($c) < 3) return false;
    return str_contains(' ' . normalize_name($text) . ' ', " {$c} ");
}

/**
 * Does the text really refer to the matched facility? Call it when the
 * facility's city is not named in the text. Returns null when the match
 * stands, else ['conflict' => reason, 'meta' => [...]]:
 *   state-mismatch            a state is named and it is not the facility's
 *   generic-alias-unconfirmed the alias is generic (shared by N+ records, or
 *                             made only of common words such as "Youth Reach")
 *                             and nothing confirms it: no state in the text,
 *                             or the record has no state, or it is an operator
 *   one-word-name-unconfirmed a facility's one-word name ("Grafton", "Parkside",
 *                             "Outwood") and no state named: one word is a town,
 *                             a surname or an ordinary word far more often than
 *                             the program
 */
function facility_match_conflict(array $match, string $text, array $genericAliases): ?array {
    $fac = $match['facility'];
    $norm = normalize_name($match['matchedAlias']);
    $isGeneric = isset($genericAliases[$norm]);
    $isOneWord = $fac['bucket'] !== 'operator' && !str_contains($norm, ' ');
    $canValidate = $fac['state'] !== '' && $fac['bucket'] !== 'operator';
    if (!$isGeneric && !$isOneWord && !$canValidate) return null;
    $signals = extract_state_signals($text);
    $conflict = null;
    if ($canValidate && count($signals) > 0 && !isset($signals[$fac['state']])) {
        $conflict = 'state-mismatch';
    } elseif ($isGeneric && !($canValidate && isset($signals[$fac['state']]))) {
        $conflict = 'generic-alias-unconfirmed';
    } elseif ($isOneWord && !($canValidate && isset($signals[$fac['state']]))) {
        $conflict = 'one-word-name-unconfirmed';
    }
    if ($conflict === null) return null;
    return [
        'conflict' => $conflict,
        'meta' => [
            'matchedAlias' => $match['matchedAlias'],
            'facility' => $fac['queryName'],
            'expectedState' => $fac['state'],
            'detectedStates' => array_keys($signals)
        ]
    ];
}

function evaluate_candidate(array $candidate, array $facilityIndex, array $blacklist, array $facilityOwnHosts, array $genericAliases, array $opts = []): array {
    global $ABUSE_KEYWORDS, $WEAK_ABUSE_KEYWORDS;
    $text = $candidate['title'] . ' ' . $candidate['description'];
    $reasons = [];
    $score = 0;

    $candHost = host_of($candidate['sourceUrl'] !== '' ? $candidate['sourceUrl'] : $candidate['link']);
    if ($candHost === '') {
        return ['accept' => false, 'reason' => 'invalid-url', 'meta' => ['link' => $candidate['link']]];
    }
    // Everything r/troubledteens links to goes to the review queue: the
    // subreddit is the filter, so no keyword score, blacklist or state check
    // applies. Reddit-internal, social and video hosts (nothing to extract)
    // are skipped in fetch_reddit_candidates; a PDF cannot be extracted
    // either. The facility match is kept for the submission's metadata.
    if (str_starts_with($candidate['origin'], 'reddit')) {
        if (is_pdf_url($candidate['link'])) {
            return ['accept' => false, 'reason' => 'pdf-document', 'meta' => ['link' => $candidate['link']]];
        }
        $match = match_facility($text, $facilityIndex);
        // Tag the facility only when the text really refers to it, so the
        // queue is not labelled with a namesake from another state.
        if ($match !== null &&
            !text_names_city($text, $match['facility']['city']) &&
            facility_match_conflict($match, $text, $genericAliases) !== null) {
            $match = null;
        }
        $reasons = ['r/troubledteens'];
        if ($match !== null) $reasons[] = 'facility:' . $match['matchedAlias'];
        return [
            'accept' => true, 'score' => SCORE_THRESHOLD, 'reasons' => $reasons,
            'match' => $match !== null ? [
                'alias' => $match['matchedAlias'],
                'facility' => $match['facility']['queryName'],
                'state' => $match['facility']['state'],
                'city' => $match['facility']['city'],
                'bucket' => $match['facility']['bucket']
            ] : null
        ];
    }
    if ($blacklist['hostBlocked']($candHost)) {
        return ['accept' => false, 'reason' => 'blacklist-host', 'meta' => ['host' => $candHost]];
    }
    $articleHost = article_host_of($candidate['link']);
    if ($articleHost !== '' && isset($facilityOwnHosts[$articleHost])) {
        return ['accept' => false, 'reason' => 'facility-own-website', 'meta' => ['host' => $articleHost]];
    }
    $pathHit = $blacklist['pathBlocked']($candidate['link']);
    if ($pathHit) {
        return ['accept' => false, 'reason' => 'blacklist-path', 'meta' => ['pattern' => $pathHit, 'link' => $candidate['link']]];
    }
    // PDFs (court filings, uploaded documents) have no extractable HTML body:
    // the AI stage fails on them every time, and AI-stage failures are retried
    // on every run. Reject them up front.
    if (is_pdf_url($candidate['link'])) {
        return ['accept' => false, 'reason' => 'pdf-document', 'meta' => ['link' => $candidate['link']]];
    }
    // A bare homepage (a program's own site, a law firm, a resource list) is
    // not an article.
    if (is_homepage_url($candidate['link'])) {
        return ['accept' => false, 'reason' => 'homepage-url', 'meta' => ['link' => $candidate['link']]];
    }

    // A sweep item is matched against the programs it was searched for first
    // (the request's names; $opts['sweepIndex'] maps a name key to its records),
    // then against every record as before.
    $match = null;
    if ($candidate['origin'] === 'google-news' && !empty($candidate['batchKeys'])) {
        $own = [];
        foreach ($candidate['batchKeys'] as $k) {
            foreach ($opts['sweepIndex'][$k] ?? [] as $i) {
                if (isset($facilityIndex[$i])) $own[$i] = $facilityIndex[$i];
            }
        }
        if ($own) $match = match_facility($text, $own);
    }
    if ($match === null) $match = match_facility($text, $facilityIndex);
    $cityMatched = false;
    // A program's name in the text keeps a topic story even when the tag is
    // dropped below ("Newport Academy Lays Off 68 Workers" has no abuse word).
    $facilityNamed = $match !== null;

    if ($match !== null) {
        $fac = $match['facility'];

        // Inferred facility-own-website check: candidate host SLD vs facility name.
        $candArticleHost = article_host_of($candidate['link']);
        if ($candArticleHost !== '') {
            $sldParts = explode('.', $candArticleHost);
            array_pop($sldParts);
            $sld = preg_replace('/[^a-z0-9]/', '', implode('.', $sldParts));
            $normName = str_replace(' ', '', normalize_name($match['matchedAlias']));
            if ($sld !== '' && $normName !== '' && strlen($sld) >= 6 &&
                (str_contains($normName, $sld) || str_contains($sld, $normName))) {
                return [
                    'accept' => false,
                    'reason' => 'facility-own-website-inferred',
                    'meta' => ['host' => $candArticleHost, 'matchedAlias' => $match['matchedAlias'], 'facility' => $fac['queryName']]
                ];
            }
        }

        // A program named after its town ("Grafton" in Grafton, WI): the town
        // in the text is the name itself, not a second confirmation.
        $cityMatched = text_names_city($text, $fac['city']) && normalize_name($fac['city']) !== normalize_name($match['matchedAlias']);

        // State-match validation. A generic alias needs POSITIVE state
        // confirmation, not just absence of a mismatch: without it, "Youth
        // Reach" matched "Skilled Youth reach China" and "Juvenile Detention
        // Center" matches every county's. An unconfirmed generic or one-word
        // name only loses the facility tag: a topic story that says "people"
        // stands on its own, a sweep hit is then rejected as
        // facility-unmatched below.
        if (!$cityMatched) {
            $bad = facility_match_conflict($match, $text, $genericAliases);
            if ($bad !== null && str_ends_with($bad['conflict'], '-unconfirmed')) {
                $match = null;
            } elseif ($bad !== null) {
                return ['accept' => false, 'reason' => $bad['conflict'], 'meta' => $bad['meta']];
            }
        }

        if ($match !== null) {
            $score += 2;
            $reasons[] = 'facility:' . $match['matchedAlias'];
            if ($cityMatched) {
                $score += 2;
                $reasons[] = 'city:' . $fac['city'];
            }
        }
    }

    // Headlines the owner keeps declining when no program of ours is named:
    // staff as the victim of a youth's attack at a county detention center,
    // "boarding (a) school bus" (rejectHeadlines in the queries file). The
    // same story at a program in the records ("students at Mingus Mountain
    // Academy attack staff") is wanted.
    if ($match === null) {
        foreach ($opts['rejectHeadlines'] ?? [] as $rh) {
            if (preg_match($rh['pcre'], $candidate['title'])) {
                return ['accept' => false, 'reason' => 'headline-excluded', 'meta' => ['pattern' => $rh['source'], 'title' => $candidate['title']]];
            }
        }
    }

    // Abuse keyword scoring (title weighted higher than description; strong
    // keywords outrank weak ones)
    $firstHit = static function (array $list, string $hay): ?string {
        foreach ($list as $k) {
            if (str_contains($hay, $k)) return $k;
        }
        return null;
    };
    $titleLower = mb_strtolower($candidate['title']);
    $descLower = mb_strtolower($candidate['description']);
    $titleHit = find_keyword($ABUSE_KEYWORDS, $titleLower);
    $weakTitleHit = $titleHit !== null ? null : find_keyword($WEAK_ABUSE_KEYWORDS, $titleLower);
    $descHit = null;
    if ($titleHit !== null) {
        $score += 3;
        $reasons[] = 'title-kw:' . $titleHit;
    } elseif ($weakTitleHit !== null) {
        $score += 2;
        $reasons[] = 'title-kw-weak:' . $weakTitleHit;
    } else {
        $descHit = find_keyword($ABUSE_KEYWORDS, $descLower) ?? find_keyword($WEAK_ABUSE_KEYWORDS, $descLower);
        if ($descHit !== null) {
            $score += 1;
            $reasons[] = 'desc-kw:' . $descHit;
        }
    }

    // A sweep item whose headline names no program: Google matched the name
    // in the article body (the name was the query), so a BATCHED hit (a
    // distinctive name) is kept with its attribution pending; the AI step,
    // which reads the article, confirms the program before it is submitted
    // (the Desert Willow closure stories named the hospital only in the body).
    // A SINGLE-name hit (a short name: "Grafton", "Cypress Creek") is rejected
    // as before: a short name in a body is a street or a surname as often as
    // the program ("Teenage suspect charged in hit-and-run" for Linn County
    // Juvenile Detention).
    $attributionPending = false;
    if ($candidate['origin'] === 'google-news' && $match === null) {
        if (($candidate['sweepMode'] ?? 'single') === 'batch') {
            $attributionPending = true;
            $reasons[] = 'batch:' . implode('|', $candidate['batchNames'] ?? []);
        } else {
            return [
                'accept' => false,
                'reason' => 'facility-unmatched',
                'meta' => ['query' => $candidate['facilityQuery'] ?? '', 'score' => $score, 'reasons' => $reasons, 'host' => $candHost]
            ];
        }
    }

    // Topic-query boost: the query itself carried the topical constraint
    // ("troubled teen industry", "wilderness therapy"), so the article is on
    // topic even when no facility in the database is named.
    // The boost only applies when the topic's own phrase actually appears in
    // the headline/blurb: Google News relevance matching is loose enough to
    // return obituaries and theater reviews for "troubled teen industry".
    if ($candidate['origin'] === 'google-news-topic' && (int)($candidate['topicBoost'] ?? 0) > 0) {
        $phrases = is_array($candidate['topicMatch'] ?? null) ? $candidate['topicMatch'] : [];
        // No phrases (a hand-edit typo in the queries file) means no boost,
        // not an unconditional one.
        $phraseHit = $phrases ? $firstHit($phrases, mb_strtolower($text)) : null;
        $requireAny = is_array($candidate['topicRequireAny'] ?? null) ? $candidate['topicRequireAny'] : [];
        if ($phraseHit !== null && $requireAny && find_keyword($requireAny, mb_strtolower($text)) === null) {
            $phraseHit = null;
        }
        if ($phraseHit !== null && !$facilityNamed && $titleHit === null && $weakTitleHit === null && $descHit === null) {
            // The topic phrase alone ("troubled teen" in soap spoilers, a
            // teen's own arson case) with no abuse word and no program named.
            return [
                'accept' => false,
                'reason' => 'topic-no-keyword',
                'meta' => ['topic' => $candidate['topicQuery'] ?? '', 'score' => $score, 'reasons' => $reasons, 'host' => $candHost]
            ];
        } elseif ($phraseHit !== null && !$facilityNamed && $titleHit === null && !empty($candidate['topicStrongOnly'])) {
            return [
                'accept' => false,
                'reason' => 'topic-weak-keyword',
                'meta' => ['topic' => $candidate['topicQuery'] ?? '', 'score' => $score, 'reasons' => $reasons, 'host' => $candHost]
            ];
        } elseif ($phraseHit !== null) {
            $score += (int)$candidate['topicBoost'];
            $reasons[] = 'topic:' . ($candidate['topicQuery'] ?? '');
        } elseif ($match === null) {
            // Off-topic result of a broad query, and no facility named: a bare
            // keyword ("lawsuit", "survivor") is not enough to accept it.
            return [
                'accept' => false,
                'reason' => 'topic-unmatched',
                'meta' => ['topic' => $candidate['topicQuery'] ?? '', 'score' => $score, 'reasons' => $reasons, 'host' => $candHost]
            ];
        } else {
            $reasons[] = 'topic-unmatched:' . ($candidate['topicQuery'] ?? '');
        }
    }

    if ($attributionPending) {
        return ['accept' => true, 'score' => $score, 'reasons' => $reasons, 'match' => null, 'attribution' => 'pending'];
    }
    if ($score >= SCORE_THRESHOLD) {
        return [
            'accept' => true, 'score' => $score, 'reasons' => $reasons,
            'match' => $match !== null ? [
                'alias' => $match['matchedAlias'],
                'facility' => $match['facility']['queryName'],
                'state' => $match['facility']['state'],
                'city' => $match['facility']['city'],
                'bucket' => $match['facility']['bucket']
            ] : null
        ];
    }
    return [
        'accept' => false,
        'reason' => 'low-score',
        'meta' => ['score' => $score, 'reasons' => $reasons, 'threshold' => SCORE_THRESHOLD, 'host' => $candHost]
    ];
}

// ============================================================
// Google News URL resolution (batchexecute decoder)
// ============================================================

/**
 * Resolve a news.google.com redirect URL to its canonical publisher URL via
 * Google's undocumented `garturlreq` endpoint. Returns the resolved URL, or
 * the original GN URL if any step fails — callers MUST check whether the
 * result still starts with news.google.com/ and reject the candidate if so.
 */
function resolve_google_news_url(string $url): string {
    if ($url === '' || !str_starts_with($url, 'https://news.google.com/')) return $url;

    if (!preg_match('#/(?:rss/)?articles/([^/?\#]+)#', $url, $tm)) return $url;
    $token = $tm[1];

    try {
        // Step 1 — fetch article page to harvest signature
        $pageRes = http_request("https://news.google.com/articles/{$token}", ['timeoutMs' => 20000]);
        if ($pageRes['status'] < 200 || $pageRes['status'] >= 300) {
            kop_warn('    GN resolve: page HTTP ' . $pageRes['status'] . ' for ' . substr($token, 0, 30) . '…');
            return $url;
        }
        $html = $pageRes['body'];
        $sg = preg_match('/data-n-a-sg="([^"]+)"/', $html, $m) ? $m[1] : '';
        $ts = preg_match('/data-n-a-ts="([^"]+)"/', $html, $m) ? $m[1] : '';
        if ($sg === '' || $ts === '') {
            kop_warn('    GN resolve: missing signature for ' . substr($token, 0, 30) . '…');
            return $url;
        }

        // Step 2 — POST to batchexecute (f.req payload reverse-engineered from
        // GN's own client; the inner array is a `garturlreq` request type).
        $tsNum = is_numeric($ts) ? $ts + 0 : $ts;
        $inner = json_encode(['garturlreq', [
            ['X', 'X', ['X', 'X'], null, null, 1, 1, 'US:en', null, 1, null, null, null, null, null, 0, 1],
            'X', 'X', 1, [1, 1, 1], 1, 1, null, 0, 0, null, 0
        ], $token, $tsNum, $sg], JSON_UNESCAPED_SLASHES);
        $fReq = json_encode([[['Fbv4je', $inner, null, '1']]], JSON_UNESCAPED_SLASHES);
        $body = 'f.req=' . rawurlencode($fReq);

        $beRes = http_request(
            'https://news.google.com/_/DotsSplashUi/data/batchexecute?rpcids=Fbv4je&rt=c',
            [
                'method' => 'POST',
                'headers' => ['Content-Type' => 'application/x-www-form-urlencoded;charset=utf-8'],
                'body' => $body,
                'timeoutMs' => 20000
            ]
        );
        if ($beRes['status'] < 200 || $beRes['status'] >= 300) {
            kop_warn('    GN resolve: batchexecute HTTP ' . $beRes['status']);
            return $url;
        }
        $text = $beRes['body'];

        // Step 3 — extract URL out of the escaped-JSON blob:
        //   "[\"garturlres\",\"https://example.com/article\",1]"
        if (!preg_match('/garturlres\\\\",\\\\"(https?:\/\/(?:[^\\\\"]|\\\\\\\\)+)\\\\",/', $text, $um)) {
            kop_warn('    GN resolve: no URL in batchexecute response (' . substr($text, 0, 100) . '…)');
            return $url;
        }
        $resolved = str_replace(['\\\\', '\\"'], ['\\', '"'], $um[1]);
        if (str_starts_with($resolved, 'https://news.google.com/')) {
            return $url;   // resolution returned another GN URL — give up
        }
        return $resolved;
    } catch (Throwable $err) {
        kop_warn('    GN resolve failed for ' . substr($url, 0, 80) . ': ' . $err->getMessage());
        return $url;
    }
}

// ============================================================
// Submission
// ============================================================

/**
 * The AI step: api/process-news-ai.php reads the article and returns its
 * fields. ['ok' => true, 'data' => [...]] or ['ok' => false, 'stage' => 'ai', 'error' => ...].
 */
function ai_process_candidate(array $candidate): array {
    kop_log('  → AI processing: ' . substr($candidate['link'], 0, 90));
    $aiRes = post_json(AI_ENDPOINT, [
        'url' => $candidate['link'],
        'provider' => AI_PROVIDER,
        'customInstructions' => ''
    ], ['timeoutMs' => AI_TIMEOUT_MS]);

    if (!$aiRes['ok'] || empty($aiRes['body']['success'])) {
        $err = $aiRes['body']['error'] ?? ($aiRes['body']['rawText'] ?? "HTTP {$aiRes['status']}");
        kop_warn('    AI failed: ' . substr((string)$err, 0, 200));
        return ['ok' => false, 'stage' => 'ai', 'error' => substr((string)$err, 0, 500)];
    }
    return ['ok' => true, 'data' => is_array($aiRes['body']['data'] ?? null) ? $aiRes['body']['data'] : []];
}

/**
 * Which of the programs a batched sweep item was searched for does the
 * article name? Matched over what the AI read: the facilities it listed, the
 * title, summary and location. The longest alias wins, as in match_facility.
 * Null when none of them is named: the article only mentioned the name in
 * passing, or Google's match was loose.
 */
function confirm_batch_attribution(array $candidate, array $data, array $facilityIndex, array $sweepIndex): ?array {
    $own = [];
    foreach ($candidate['batchKeys'] ?? [] as $k) {
        foreach ($sweepIndex[$k] ?? [] as $i) {
            if (isset($facilityIndex[$i])) $own[$i] = $facilityIndex[$i];
        }
    }
    if (!$own) return null;
    $join = static fn($v) => is_array($v) ? implode(' ', array_map('strval', $v)) : (string)($v ?? '');
    $text = implode(' ', [
        $join($data['facilities'] ?? ''), (string)($data['title'] ?? ''), (string)($data['alternateTitle'] ?? ''),
        (string)($data['summary'] ?? ''), (string)($data['location'] ?? ''), $candidate['title'] ?? ''
    ]);
    return match_facility($text, $own);
}

/**
 * Submit one candidate: the AI step (unless $data was read already), then
 * api/save-news-submission.php.
 */
function submit_candidate(array $candidate, array $evalResult, array $options = [], ?array $data = null): array {
    $submittedBy = $options['submittedBy'] ?? 'auto-discovery';
    $noteLabel   = $options['noteLabel'] ?? 'auto-discovery';

    if ($data === null) {
        $ai = ai_process_candidate($candidate);
        if (!$ai['ok']) return $ai;
        $data = $ai['data'];
    }

    $joinList = static fn($v) => is_array($v) ? implode("\n", $v) : (string)($v ?? '');

    $discoveryNote = implode(' | ', array_filter([
        "{$noteLabel} via {$candidate['origin']}",
        'score=' . $evalResult['score'],
        'reasons=' . implode(',', $evalResult['reasons']),
        $evalResult['match'] !== null ? 'match=' . json_encode($evalResult['match'], JSON_UNESCAPED_SLASHES) : '',
        !empty($candidate['facilityQuery']) ? 'query=' . $candidate['facilityQuery'] : '',
        !empty($candidate['topicQuery']) ? 'topic=' . $candidate['topicQuery'] : '',
        !empty($candidate['redditPermalink']) ? 'reddit=' . $candidate['redditPermalink'] : ''
    ], static fn($s) => $s !== ''));

    $submission = [
        'title' => ($data['title'] ?? '') !== '' ? $data['title'] : ($candidate['title'] !== '' ? $candidate['title'] : '(untitled)'),
        'alternateTitle' => $data['alternateTitle'] ?? '',
        'author' => $data['author'] ?? '',
        'publicationName' => ($data['publicationName'] ?? '') !== '' ? $data['publicationName'] : $candidate['sourceName'],
        'publicationDate' => $data['publicationDate'] ?? '',
        'url' => $candidate['link'],
        'location' => $data['location'] ?? '',
        'tags' => $joinList($data['tags'] ?? ''),
        'articleType' => ($data['articleType'] ?? '') !== '' ? $data['articleType'] : 'general',
        'facilities' => $joinList($data['facilities'] ?? ''),
        'staff' => $joinList($data['staff'] ?? ''),
        'survivors' => $joinList($data['survivors'] ?? ''),
        'contentWarnings' => $data['contentWarnings'] ?? [],
        'summary' => $data['summary'] ?? '',
        'needsAlternateTitle' => !empty($data['alternateTitle']),
    ];
    if (is_array($data['typeSpecificData'] ?? null)) {
        $submission = array_merge($submission, $data['typeSpecificData']);
    }
    $submission = array_merge($submission, [
        'status' => 'submitted',
        'submittedBy' => $submittedBy,
        'submissionNotes' => $discoveryNote
    ]);

    $subRes = post_json(SUBMIT_ENDPOINT, $submission);
    if (!$subRes['ok'] || empty($subRes['body']['success'])) {
        $err = $subRes['body']['error'] ?? ($subRes['body']['rawText'] ?? "HTTP {$subRes['status']}");
        kop_warn('    Submit failed: ' . substr((string)$err, 0, 200));
        return ['ok' => false, 'stage' => 'submit', 'error' => substr((string)$err, 0, 500)];
    }
    kop_log('    ✓ submission id=' . ($subRes['body']['id'] ?? '?'));
    return ['ok' => true, 'id' => $subRes['body']['id'] ?? null];
}

// ============================================================
// Main
// ============================================================

function main(): void {
    kop_log('Discovery starting (' . gmdate('Y-m-d\TH:i:s\Z') . ') [php]');
    kop_log('  API base:  ' . API_BASE);
    kop_log('  Dry run:   ' . (DRY_RUN ? 'true' : 'false'));
    if (SUBMIT_LIMIT !== PHP_INT_MAX) kop_log('  Submit limit: ' . SUBMIT_LIMIT);
    if (MAX_FACILITIES) kop_log('  Max facilities: ' . MAX_FACILITIES);

    $runDeadline = microtime(true) + RUN_TIME_BUDGET_MS / 1000;
    $state = load_state();
    $seen = array_fill_keys($state['seenUrls'], true);
    $blacklist = build_blacklist_matcher();
    $queries = load_discovery_queries();
    kop_log('  Search terms: ' . count($queries['topicQueries']) . ' topic queries, ' .
        count($queries['facilityKeywords']) . ' facility keywords, ' .
        count($queries['genericQueryNames']) . ' generic names skipped' .
        (NO_TOPICS ? ' (topics disabled by --no-topics)' : '') .
        (NO_FACILITIES ? ' (per-facility queries disabled by --no-facilities)' : ''));

    // -- Fetch facility data live from API --
    kop_log("\nFetching facilities from API...");
    $facJson = fetch_json(FACILITIES_URL, ['timeoutMs' => 60000]);
    $index = build_facility_index($facJson, $queries['ignoreAliases'], $queries['genericAliasWords']);
    $facilityIndex = $index['facilities'];
    $facilityOwnHosts = $index['ownHosts'];
    $genericAliases = $index['genericAliases'];
    kop_log('  built facility index: ' . count($facilityIndex) . ' unique entries (every status)');
    kop_log('  facility-owned hosts: ' . count($facilityOwnHosts) . ' (skipped as candidates)');
    kop_log('  generic aliases:      ' . count($genericAliases) . ' (require positive state-signal match)');

    // -- The sweep's share for this run (see the header) --
    $sweepLines = build_sweep_names($facilityIndex, $queries, $genericAliases);
    $sweepIndex = [];                                   // name key => indices into $facilityIndex
    $sweepCounts = ['batch' => 0, 'single' => 0];
    foreach ($sweepLines as $l) { $sweepIndex[$l['key']] = $l['entries']; $sweepCounts[$l['mode']]++; }
    $sweepRequests = build_sweep_requests($sweepLines);
    $cursorStart = (int)$state['sweepCursor'];
    $slice = NO_FACILITIES ? [] : sweep_slice($sweepRequests, $cursorStart, MAX_FACILITIES)[0];
    $cursorStart = count($sweepRequests) ? $cursorStart % count($sweepRequests) : 0;
    kop_log('  sweep: ' . count($sweepLines) . " names ({$sweepCounts['batch']} batched " . GN_BATCH_SIZE . " to a request, {$sweepCounts['single']} single) in "
        . count($sweepRequests) . ' requests; this run: ' . count($slice) . " from request {$cursorStart}"
        . (NO_FACILITIES ? ' (--no-facilities)' : (MAX_FACILITIES ? ' (--max-facilities)' : ' (1/' . SWEEP_RUNS_PER_DAY . ' of the list)'))
        . ', window ' . ($queries['facilityBatchRecency'] ?: 'none'));

    // Counters accumulated across every submit batch in this run.
    $totals = [
        'candidates' => 0, 'accepted' => 0, 'rejected' => 0,
        'submitted' => 0, 'submitErrors' => 0, 'unreadable' => 0, 'postResolveRejected' => 0,
        'topicSubmitted' => 0, 'topicAttempted' => 0, 'topicDeferred' => 0,
        'gnResolveDeferred' => 0, 'carriedIn' => 0, 'droppedPending' => 0,
        'pendingAttribution' => 0, 'aiConfirms' => 0, 'confirmed' => 0, 'unconfirmed' => 0, 'confirmDeferred' => 0
    ];
    $stopRun = false;         // provider quota spent or time budget gone: carry what is left
    $dedupeSeen = [];         // URL hashes already queued or evaluated this run
    $dedupeHeadlines = [];    // headline keys claimed by an accepted copy this run
    $pending = [];            // candidates carried to the next run

    // Queue a candidate for the next run instead of dropping it. Carried
    // entries keep their first-seen date and count attempts, so a link that
    // never resolves or never extracts is given up after PENDING_MAX_ATTEMPTS.
    $defer = static function (array $q, string $why) use (&$pending): void {
        $pending[] = [
            'candidate' => $q['candidate'],
            'evalResult' => $q['evalResult'],
            'urlHash' => $q['urlHash'],
            'headlineKey' => $q['headlineKey'],
            'addedAt' => $q['addedAt'] ?? gmdate('Y-m-d'),
            'attempts' => (int)($q['attempts'] ?? 0) + 1,
            'why' => $why
        ];
    };

    // -- Filter one tier's raw items into a submit queue --
    $filterBatch = function (array $candidates, string $label) use (
        &$state, &$seen, &$dedupeSeen, &$dedupeHeadlines, &$totals,
        $facilityIndex, $blacklist, $facilityOwnHosts, $genericAliases, $queries, $sweepIndex
    ): array {
        $totals['candidates'] += count($candidates);
        $state['stats']['discovered'] += count($candidates);
        $queue = [];
        $rejected = [];
        $duplicateHeadlines = 0;
        $previouslySubmitted = 0;

        foreach ($candidates as $c) {
            if (empty($c['link']) || !str_starts_with($c['link'], 'http')) continue;
            $h = hash_url($c['link']);
            if (isset($state['rejectedUrls'][$h])) continue;
            if (isset($seen[$h]) || isset($dedupeSeen[$h])) continue;
            $dedupeSeen[$h] = true;
            // Collapse syndicated copies (same wire headline, different outlet).
            // Google News origins only: a Reddit post's title is the poster's,
            // and every r/troubledteens link is wanted regardless. Only an
            // accepted copy claims the key, so a rejected first copy (wire
            // host, state mismatch) does not hide a good one from another outlet.
            $hk = in_array($c['origin'], ['google-news', 'google-news-topic'], true) ? headline_key($c['title']) : '';
            if ($hk !== '' && isset($dedupeHeadlines[$hk])) { $duplicateHeadlines++; continue; }
            // Same story already submitted on an earlier night under another URL.
            if ($hk !== '' && isset($state['seenHeadlines'][$hk])) { $previouslySubmitted++; continue; }

            $result = evaluate_candidate($c, $facilityIndex, $blacklist, $facilityOwnHosts, $genericAliases,
                ['rejectHeadlines' => $queries['rejectHeadlines'], 'sweepIndex' => $sweepIndex]);
            if ($result['accept']) {
                if ($hk !== '') $dedupeHeadlines[$hk] = true;
                if (($result['attribution'] ?? '') === 'pending') $totals['pendingAttribution']++;
                $queue[] = ['candidate' => $c, 'evalResult' => $result, 'urlHash' => $h, 'headlineKey' => $hk];
            } else {
                $state['stats']['rejected'] += 1;
                $state['rejectedUrls'][$h] = gmdate('Y-m-d');
                $rejected[] = [
                    'link' => $c['link'],
                    'title' => $c['title'],
                    'origin' => $c['origin'],
                    'host' => host_of($c['sourceUrl'] !== '' ? $c['sourceUrl'] : $c['link']),
                    'facilityQuery' => $c['facilityQuery'] ?? null,
                    'topicQuery' => $c['topicQuery'] ?? null,
                    'reason' => $result['reason'],
                    'meta' => $result['meta'] ?? null
                ];
            }
        }
        $pendingHere = count(array_filter($queue, static fn($q) => ($q['evalResult']['attribution'] ?? '') === 'pending'));
        kop_log("After filter [{$label}]: " . count($queue) . ' accepted' . ($pendingHere ? " ({$pendingHere} awaiting AI confirmation)" : '')
            . ', ' . count($rejected) . ' rejected (threshold ' . SCORE_THRESHOLD . ')');
        $byOrigin = [];
        foreach ($queue as $q) {
            $o = $q['candidate']['origin'];
            $byOrigin[$o] = ($byOrigin[$o] ?? 0) + 1;
        }
        $parts = [];
        foreach ($byOrigin as $k => $v) $parts[] = "{$k}={$v}";
        kop_log('  accepted by origin: ' . ($parts ? implode(', ', $parts) : 'none'));
        if ($duplicateHeadlines) kop_log("  syndicated duplicates collapsed: {$duplicateHeadlines}");
        if ($previouslySubmitted) kop_log("  headlines already submitted on an earlier run: {$previouslySubmitted}");
        $totals['accepted'] += count($queue);
        $totals['rejected'] += count($rejected);

        // Persist the rejected log right away (useful even if the submit phase aborts)
        if ($rejected) persist_rejected($rejected);

        // Reddit candidates go ahead of topic candidates, so a stalled topic
        // tier (rate limits, extraction failures) cannot starve them; sweep
        // hits awaiting AI confirmation go last, highest score first, so the
        // confirm cap never starves an attributed one.
        $isPending = static fn($q) => ($q['evalResult']['attribution'] ?? '') === 'pending';
        $pendingQ = array_values(array_filter($queue, $isPending));
        usort($pendingQ, static fn($a, $b) => $b['evalResult']['score'] <=> $a['evalResult']['score']);
        return array_merge(
            array_values(array_filter($queue, static fn($q) => $q['candidate']['origin'] !== 'google-news-topic' && !$isPending($q))),
            array_values(array_filter($queue, static fn($q) => $q['candidate']['origin'] === 'google-news-topic')),
            $pendingQ
        );
    };

    // -- Resolve, AI-process and submit one queue --
    // Each accepted candidate: resolve GN redirect → re-check dedup/blacklists
    // on the canonical URL → AI process → submit. Anything this run cannot
    // finish is carried to the next run instead of being dropped.
    $submitBatch = function (array $queue, string $label) use (
        &$state, &$seen, &$totals, &$stopRun, &$pending, $defer,
        $runDeadline, $blacklist, $facilityOwnHosts, $queries, $facilityIndex, $sweepIndex
    ): void {
        if (!$queue) return;

        if (DRY_RUN) {
            kop_log("\n--- DRY RUN [{$label}] — resolving + would-submit (showing up to 30 of " . count($queue) . ') ---');
            $resolveUnresolved = 0;
            foreach (array_slice($queue, 0, 30) as $q) {
                $orig = $q['candidate']['link'];
                $resolved = resolve_google_news_url($orig);
                $unresolved = str_starts_with($resolved, 'https://news.google.com/');
                if ($unresolved) $resolveUnresolved++;
                kop_log("  [score {$q['evalResult']['score']}" . (($q['evalResult']['attribution'] ?? '') === 'pending' ? ', AI confirms' : '') . '] '
                    . ($unresolved ? '✗ UNRESOLVED' : '✓') . " {$resolved}");
                if ($resolved !== $orig && !$unresolved) kop_log('     was:     ' . substr($orig, 0, 80) . '…');
                kop_log('     title:   ' . substr($q['candidate']['title'], 0, 100));
                kop_log('     reasons: ' . implode(', ', $q['evalResult']['reasons']));
                if ($q['evalResult']['match'] !== null) kop_log('     match:   ' . json_encode($q['evalResult']['match'], JSON_UNESCAPED_SLASHES));
            }
            if ($resolveUnresolved > 0) kop_log("  {$resolveUnresolved} Google News URL(s) could not be resolved — would be carried to the next run.");
            return;
        }

        kop_log("\nSubmitting [{$label}]: " . count($queue) . ' candidates');
        $topicCap = $queries['maxTopicSubmissionsPerRun'];
        $gnResolveFailStreak = 0;
        $consecutiveRateLimited = 0;
        $postResolveLog = [];

        foreach ($queue as $q) {
            if ($totals['submitted'] >= SUBMIT_LIMIT) break;   // --limit smoke tests: the rest is rediscovered
            if ($stopRun) { $defer($q, 'run stopped'); continue; }
            $isTopic = $q['candidate']['origin'] === 'google-news-topic';
            $isPending = ($q['evalResult']['attribution'] ?? '') === 'pending';
            // Per-run cap on topic-query submissions (Groq rate limit).
            if ($isTopic && $topicCap > 0 && $totals['topicAttempted'] >= $topicCap) {
                $totals['topicDeferred']++;
                $defer($q, 'topic cap');
                continue;
            }
            // Per-run cap on AI confirmations of batched sweep hits.
            if ($isPending && $totals['aiConfirms'] >= AI_CONFIRM_PER_RUN) {
                $totals['confirmDeferred']++;
                $defer($q, 'confirm cap');
                continue;
            }
            if (microtime(true) >= $runDeadline) {
                kop_warn('  ! run time budget exhausted — carrying the remaining candidates to the next run');
                $stopRun = true;
                $defer($q, 'time budget');
                continue;
            }

            $originalLink = $q['candidate']['link'];
            $isGnLink = str_starts_with($originalLink, 'https://news.google.com/');
            // Google is rate-limiting the resolver: stop asking for this run.
            if ($isGnLink && $gnResolveFailStreak >= GN_RESOLVE_FAIL_STOP_AFTER) {
                $totals['gnResolveDeferred']++;
                $defer($q, 'resolver rate-limited');
                continue;
            }
            $resolvedLink = resolve_google_news_url($originalLink);

            // Never submit an unresolved GN URL: the AI stage gets the consent
            // page, which has no article body. The failure is almost always a
            // 429 burst, so the candidate is carried to the next run, which
            // resolves it before touching Google News.
            if (str_starts_with($resolvedLink, 'https://news.google.com/')) {
                $gnResolveFailStreak++;
                $totals['gnResolveDeferred']++;
                $defer($q, 'unresolved');
                continue;
            }
            if ($isGnLink) $gnResolveFailStreak = 0;

            if ($resolvedLink !== $originalLink) {
                // Re-dedup against the canonical URL — same article may appear
                // under multiple Google News redirect tokens.
                $newHash = hash_url($resolvedLink);
                if (isset($seen[$newHash])) {
                    $seen[$q['urlHash']] = true;
                    $totals['postResolveRejected']++;
                    $postResolveLog[] = ['link' => $originalLink, 'resolvedTo' => $resolvedLink, 'reason' => 'duplicate-after-resolution'];
                    continue;
                }

                $newHost = article_host_of($resolvedLink);
                if ($newHost !== '' && $blacklist['hostBlocked']($newHost)) {
                    $seen[$q['urlHash']] = true;
                    $totals['postResolveRejected']++;
                    $postResolveLog[] = ['link' => $originalLink, 'resolvedTo' => $resolvedLink, 'reason' => 'blacklist-host-post-resolve', 'host' => $newHost];
                    continue;
                }
                if ($newHost !== '' && isset($facilityOwnHosts[$newHost])) {
                    $seen[$q['urlHash']] = true;
                    $totals['postResolveRejected']++;
                    $postResolveLog[] = ['link' => $originalLink, 'resolvedTo' => $resolvedLink, 'reason' => 'facility-own-website-post-resolve', 'host' => $newHost];
                    continue;
                }
                $pathHit = $blacklist['pathBlocked']($resolvedLink);
                if ($pathHit) {
                    $seen[$q['urlHash']] = true;
                    $totals['postResolveRejected']++;
                    $postResolveLog[] = ['link' => $originalLink, 'resolvedTo' => $resolvedLink, 'reason' => 'blacklist-path-post-resolve', 'pattern' => $pathHit];
                    continue;
                }
                if (is_pdf_url($resolvedLink)) {
                    $seen[$q['urlHash']] = true;
                    $seen[$newHash] = true;
                    $totals['postResolveRejected']++;
                    $postResolveLog[] = ['link' => $originalLink, 'resolvedTo' => $resolvedLink, 'reason' => 'pdf-post-resolve'];
                    continue;
                }

                $q['candidate']['link'] = $resolvedLink;
                $seen[$newHash] = true;
            }

            // Mark seen before attempting submission so a flaky URL isn't retried
            // every run.
            $seen[$q['urlHash']] = true;

            if ($isTopic) $totals['topicAttempted']++;
            if ($isPending) $totals['aiConfirms']++;
            sleep_ms(AI_REQUEST_DELAY_MS);
            $r = ai_process_candidate($q['candidate']);

            // Provider rate limits are transient — wait a cool-down and retry a
            // couple of times within the run before carrying the candidate to
            // the next run.
            for ($attempt = 1; $attempt <= AI_RATE_LIMIT_RETRIES &&
                 !$r['ok'] && $r['stage'] === 'ai' && preg_match('/rate limit/i', $r['error'] ?? ''); $attempt++) {
                kop_log("    rate-limited; retry {$attempt}/" . AI_RATE_LIMIT_RETRIES . ' after ' . (AI_RATE_LIMIT_WAIT_MS / 1000) . 's cool-down…');
                sleep_ms(AI_RATE_LIMIT_WAIT_MS);
                $r = ai_process_candidate($q['candidate']);
            }

            // A batched sweep hit: the article has been read, so now settle
            // which of the request's programs it names. None: not submitted,
            // logged for the Scraper finds screen, never retried.
            if ($r['ok'] && $isPending) {
                $m = confirm_batch_attribution($q['candidate'], $r['data'], $facilityIndex, $sweepIndex);
                if ($m === null) {
                    $totals['unconfirmed']++;
                    $named = is_array($r['data']['facilities'] ?? null) ? implode(', ', array_map('strval', $r['data']['facilities'])) : (string)($r['data']['facilities'] ?? '');
                    kop_log('    not about ' . implode(' / ', $q['candidate']['batchNames'] ?? []) . ($named !== '' ? " (names: {$named})" : ''));
                    $postResolveLog[] = [
                        'link' => $q['candidate']['link'], 'title' => $q['candidate']['title'], 'origin' => 'google-news',
                        'host' => host_of($q['candidate']['link']), 'facilityQuery' => $q['candidate']['facilityQuery'] ?? null,
                        'reason' => 'batch-unconfirmed',
                        'meta' => ['score' => $q['evalResult']['score'], 'reasons' => $q['evalResult']['reasons'], 'aiFacilities' => $named]
                    ];
                    continue;
                }
                $totals['confirmed']++;
                $fac = $m['facility'];
                $q['evalResult']['match'] = ['alias' => $m['matchedAlias'], 'facility' => $fac['queryName'], 'state' => $fac['state'], 'city' => $fac['city'], 'bucket' => $fac['bucket']];
                $q['evalResult']['reasons'][] = 'facility:' . $m['matchedAlias'];
                $q['evalResult']['reasons'][] = 'confirmed-by:ai';
                $q['evalResult']['score'] += 2;
                kop_log("    confirmed: {$m['matchedAlias']}");
            }
            if ($r['ok']) $r = submit_candidate($q['candidate'], $q['evalResult'], [], $r['data']);

            if ($r['ok']) {
                $totals['submitted']++;
                $state['stats']['submitted'] += 1;
                if ($q['headlineKey'] !== '') $state['seenHeadlines'][$q['headlineKey']] = gmdate('Y-m-d');
                if ($isTopic) $totals['topicSubmitted']++;
            } else {
                // A page the server cannot read (403 to our IP, no archive.org
                // copy, a form instead of an article) is the page's problem, not
                // the run's: counted apart so it never fails the cron job.
                if ($r['stage'] === 'ai' && preg_match('/Could not fetch article content|has no article text/i', $r['error'] ?? '')) {
                    $totals['unreadable']++;
                } else {
                    $totals['submitErrors']++;
                }
                // AI-stage failure says nothing bad about the URL (rate limit,
                // provider hiccup, timeout) — un-mark and carry it to the next
                // run. Submit-stage failures (e.g. duplicates) stay marked.
                if ($r['stage'] === 'ai') {
                    unset($seen[$q['urlHash']], $seen[hash_url($q['candidate']['link'])]);
                    $defer($q, 'AI stage failed');
                }
            }

            // Still rate-limited after the in-run retries: the provider quota is
            // spent for the night. Stop instead of burning the time budget; the
            // rest of the queue is carried to the next run.
            $rateLimited = !$r['ok'] && $r['stage'] === 'ai' && preg_match('/rate limit/i', $r['error'] ?? '');
            $consecutiveRateLimited = $rateLimited ? $consecutiveRateLimited + 1 : 0;
            if ($consecutiveRateLimited >= AI_RATE_LIMIT_STOP_AFTER) {
                kop_warn('  ! AI provider still rate-limited after ' . AI_RATE_LIMIT_STOP_AFTER . ' candidates in a row — carrying the rest to the next run');
                $stopRun = true;
            }
        }

        if ($postResolveLog) {
            $state['stats']['rejected'] += count($postResolveLog);
            persist_rejected($postResolveLog);
        }

        // Save after every batch so a crash in a later phase keeps this one's marks.
        $state['seenUrls'] = array_keys($seen);
        $state['pending'] = $pending;
        save_state($state);
    };

    // -- Carried-over queue: submit before this run touches Google News --
    // The per-facility sweep is what gets the server IP rate-limited on the
    // article-page step of the link resolver, so anything resolved after it
    // 429s. Candidates an earlier run could not finish go first, while the
    // resolver still works.
    $carried = [];
    foreach ($state['pending'] as $p) {
        if (!is_array($p) || empty($p['candidate']['link']) || !is_array($p['evalResult'] ?? null)) continue;
        if ((int)($p['attempts'] ?? 0) >= PENDING_MAX_ATTEMPTS) { $totals['droppedPending']++; continue; }
        $hk = (string)($p['headlineKey'] ?? '');
        if ($hk !== '' && isset($state['seenHeadlines'][$hk])) { $totals['droppedPending']++; continue; }
        $h = (string)($p['urlHash'] ?? hash_url($p['candidate']['link']));
        if (isset($seen[$h]) || isset($dedupeSeen[$h])) continue;
        $dedupeSeen[$h] = true;
        if ($hk !== '') $dedupeHeadlines[$hk] = true;
        $p['urlHash'] = $h;
        $p['headlineKey'] = $hk;
        $carried[] = $p;
    }
    $state['pending'] = [];
    $totals['carriedIn'] = count($carried);
    if ($carried || $totals['droppedPending']) {
        kop_log("\nCarried over from earlier runs: " . count($carried) . ' candidates' .
            ($totals['droppedPending'] ? " ({$totals['droppedPending']} dropped: too many attempts or story already submitted)" : ''));
    }
    $submitBatch($carried, 'carried over');

    $candidates = [];

    // -- Reddit pass --
    kop_log("\nPolling Reddit...");
    $redditItems = fetch_reddit_candidates();
    kop_log('  ' . count($redditItems) . ' reddit items');
    array_push($candidates, ...($redditItems ?: []));

    // -- Google News: shared throttling breaker for the topic + facility tiers --
    // Once Google News starts 503ing this IP it usually keeps doing so for the
    // rest of the run: after N consecutive failures take one cool-down, then
    // abandon Google News for the day rather than burn the budget on doomed
    // requests. The counter is shared so topic failures count toward it.
    $gnDeadline = min(microtime(true) + GN_TIME_BUDGET_MS / 1000, $runDeadline);
    $gn = ['consecutiveFailures' => 0, 'cooldownsLeft' => 1, 'abandoned' => false];
    $gnGate = static function (string $where) use (&$gn, &$gnDeadline): bool {
        if ($gn['abandoned']) return false;
        if (microtime(true) >= $gnDeadline) {
            kop_warn("  ! Google News time budget exhausted at {$where} — continuing to filter/submit with partial results");
            $gn['abandoned'] = true;
            return false;
        }
        if ($gn['consecutiveFailures'] >= GN_MAX_CONSECUTIVE_FAILURES) {
            if ($gn['cooldownsLeft'] > 0) {
                $gn['cooldownsLeft']--;
                $gn['consecutiveFailures'] = 0;
                kop_warn('  ! ' . GN_MAX_CONSECUTIVE_FAILURES . ' consecutive Google News failures (rate-limited?) — cooling down ' . (GN_COOLDOWN_MS / 1000) . 's');
                sleep_ms(GN_COOLDOWN_MS);
            } else {
                kop_warn("  ! Google News still failing after cool-down — abandoning Google News at {$where} for this run");
                $gn['abandoned'] = true;
                return false;
            }
        }
        return true;
    };

    // -- Google News topic tier (runs first: these replace the old Google
    //    Alerts and are the only Google News path that can surface a facility
    //    not yet in the database, so they get the un-throttled requests) --
    $topics = NO_TOPICS ? [] : $queries['topicQueries'];
    $topicCandidates = 0;
    if ($topics) {
        kop_log("\nQuerying Google News topic queries (" . count($topics) . ')...');
        foreach ($topics as $i => $topic) {
            if (!$gnGate("topic {$i}/" . count($topics))) break;
            sleep_ms(RSS_REQUEST_DELAY_MS);
            $r = fetch_google_news_for_topic($topic, $queries);
            $gn['consecutiveFailures'] = $r['failed'] ? $gn['consecutiveFailures'] + 1 : 0;
            if (count($r['items']) > 0) kop_log("  [topic:{$topic['label']}] " . count($r['items']) . ' items');
            array_push($candidates, ...($r['items'] ?: []));
            $topicCandidates += count($r['items']);
        }
        kop_log("  {$topicCandidates} topic items");
    }

    // Reddit + topic candidates are filtered and submitted now, before the
    // per-facility sweep: after ~550 RSS requests the link resolver gets
    // HTTP 429 for the rest of the run, and this tier is where the real
    // stories come from.
    kop_log("\nRaw candidates (Reddit + topics): " . count($candidates));
    $submitBatch($filterBatch($candidates, 'reddit + topics'), 'reddit + topics');

    // -- The facility sweep (with politeness delay) --
    // Runs last on purpose (see above). Its finds are filtered, resolved and
    // submitted every GN_SUBMIT_EVERY requests, while the resolver still
    // answers; whatever cannot be resolved is carried to the next run, which
    // resolves it first. The cursor moves past each request that answered, so
    // a run that dies resumes where it was.
    $gnDeadline = min(microtime(true) + GN_TIME_BUDGET_MS / 1000, $runDeadline);   // fresh budget for the sweep
    $facilityCandidates = [];
    $sweepItems = 0;
    $requestsDone = ['batch' => 0, 'single' => 0];
    $namesSwept = 0;
    $total = count($sweepRequests);
    if ($slice) {
        kop_log("\nSweeping Google News (" . count($slice) . ' requests)...');
        $today = gmdate('Y-m-d');
        foreach ($slice as $i => $req) {
            if (!$gnGate("{$i}/" . count($slice) . ' sweep requests')) break;
            sleep_ms(RSS_REQUEST_DELAY_MS);
            $r = fetch_google_news_for_request($req, $queries);
            $gn['consecutiveFailures'] = $r['failed'] ? $gn['consecutiveFailures'] + 1 : 0;
            if (!$r['failed']) {
                $requestsDone[$req['mode']]++;
                foreach ($req['lines'] as $l) { $state['sweptAt'][$l['key']] = $today; $namesSwept++; }
                $state['sweepCursor'] = ($cursorStart + $i + 1) % $total;
            }
            if (count($r['items']) > 0) {
                kop_log('  [' . $r['items'][0]['facilityQuery'] . ($req['mode'] === 'single' && $req['lines'][0]['state'] !== '' ? ' / ' . $req['lines'][0]['state'] : '') . '] ' . count($r['items']) . ' items');
            }
            array_push($facilityCandidates, ...($r['items'] ?: []));
            $sweepItems += count($r['items']);
            $last = $i + 1 === count($slice);
            if (($i + 1) % 25 === 0 || $last) kop_log('  ...' . ($i + 1) . '/' . count($slice) . " (items so far: {$sweepItems})");
            if ($facilityCandidates && (($i + 1) % GN_SUBMIT_EVERY === 0 || $last)) {
                $paused = microtime(true);
                $submitBatch($filterBatch($facilityCandidates, 'sweep'), 'sweep');
                $facilityCandidates = [];
                if (!DRY_RUN) { $state['seenUrls'] = array_keys($seen); $state['pending'] = $pending; save_state($state); }
                $gnDeadline += microtime(true) - $paused;   // submitting is not Google's time
            }
        }
        kop_log("\nRaw candidates (sweep): {$sweepItems}");
    }

    if (DRY_RUN) {
        kop_log("\nDry run done. Would attempt " . min($totals['accepted'] + $totals['carriedIn'], SUBMIT_LIMIT) . ' submissions.');
        return;
    }

    $state['seenUrls'] = array_keys($seen);
    $state['pending'] = $pending;
    $state['lastRun'] = gmdate('Y-m-d\TH:i:s\Z');
    save_state($state);

    kop_log("\n--- Done ---");
    kop_log('  sweep requests:      ' . ($requestsDone['batch'] + $requestsDone['single']) . '/' . count($slice)
        . " ({$requestsDone['batch']} batched, {$requestsDone['single']} single), {$namesSwept} of " . count($sweepLines)
        . " names; cursor {$cursorStart} -> {$state['sweepCursor']} of {$total}");
    kop_log("  topic items:         {$topicCandidates}");
    kop_log("  candidates found:    {$totals['candidates']}");
    kop_log("  accepted by filter:  {$totals['accepted']}" . ($totals['pendingAttribution'] ? " ({$totals['pendingAttribution']} awaiting AI confirmation)" : ''));
    if ($totals['aiConfirms']) kop_log("  AI confirmations:    {$totals['aiConfirms']} ({$totals['confirmed']} confirmed, {$totals['unconfirmed']} not about the program)");
    if ($totals['confirmDeferred']) kop_log("  confirm deferred (cap): {$totals['confirmDeferred']}");
    if ($totals['carriedIn']) kop_log("  carried in:          {$totals['carriedIn']}");
    kop_log("  submitted (ok):      {$totals['submitted']}" . ($totals['topicSubmitted'] ? " ({$totals['topicSubmitted']} from topic queries)" : ''));
    kop_log("  submitted (errors):  {$totals['submitErrors']}");
    if ($totals['unreadable']) kop_log("  pages unreadable:    {$totals['unreadable']} (blocked or no article text; retried next run)");
    if ($totals['topicDeferred']) kop_log("  topic deferred (cap): {$totals['topicDeferred']}");
    kop_log("  rejected (pre-fetch):  {$totals['rejected']}");
    kop_log("  rejected (post-resolve): {$totals['postResolveRejected']}");
    if ($totals['gnResolveDeferred']) kop_log("  deferred (GN link unresolved): {$totals['gnResolveDeferred']}");
    if ($pending) kop_log('  carried to next run: ' . count($pending));
    kop_log('  cumulative stats:    ' . json_encode($state['stats'], JSON_UNESCAPED_SLASHES));

    if ($totals['submitErrors'] > 0 && $totals['submitted'] === 0) {
        exit(1);
    }
}

// scripts/test-discovery-sweep.php loads the functions without running a sweep.
if (defined('KOP_DISCOVERY_NO_MAIN')) return;

try {
    main();
} catch (Throwable $err) {
    fwrite(STDERR, 'Discovery failed: ' . $err->getMessage() . "\n" . $err->getTraceAsString() . "\n");
    exit(1);
}
