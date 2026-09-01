<?php
/**
 * Article Discovery — PHP port of discover-articles.js.
 *
 * Written for the NixiHost cPanel cron, where no Node runtime exists. Behavior
 * mirrors the JS original 1:1 (same flags, same state files, same scoring):
 * pulls candidate articles from Google News RSS (per active facility) and
 * r/troubledteens (new posts), pre-filters them with a state-aware scoring
 * pass, then feeds surviving URLs through the AI processor into the
 * news_submissions review queue.
 *
 * Facility data comes live from the WP REST API — NOT from any local JSON
 * snapshot, since the JSON snapshots are out of date.
 *
 * Usage:
 *   php scripts/discover-articles.php              # full run
 *   php scripts/discover-articles.php --dry-run    # discover + score, skip submission
 *   php scripts/discover-articles.php --limit 5    # cap candidates submitted this run
 *   php scripts/discover-articles.php --max-facilities 3   # smoke test
 *
 * Environment:
 *   NEWS_API_BASE          (default: https://kidsoverprofits.org)
 *   AI_PROVIDER            (default: groq)
 *   SHARD_COUNT            (default: 7 — facilities split into N daily shards)
 *   REDDIT_CLIENT_ID / REDDIT_CLIENT_SECRET  (optional OAuth path)
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
define('REJECTED_FILE',  __DIR__ . '/discovery-rejected.json');
define('BLACKLIST_FILE', __DIR__ . '/discovery-blacklist.json');

$args = array_slice($argv, 1);
define('DRY_RUN', in_array('--dry-run', $args, true));
$limitArg = array_search('--limit', $args, true);
define('SUBMIT_LIMIT', $limitArg !== false ? (int)($args[$limitArg + 1] ?? 0) : PHP_INT_MAX);
$maxFacArg = array_search('--max-facilities', $args, true);
define('MAX_FACILITIES', $maxFacArg !== false ? (int)($args[$maxFacArg + 1] ?? 0) : null);

define('API_BASE', rtrim(getenv('NEWS_API_BASE') ?: 'https://kidsoverprofits.org', '/'));
define('FACILITIES_URL', API_BASE . '/wp-json/kop/v1/facilities');
define('AI_ENDPOINT', API_BASE . '/wp-content/themes/child/api/process-news-ai.php');
define('SUBMIT_ENDPOINT', API_BASE . '/wp-content/themes/child/api/save-news-submission.php');

define('AI_PROVIDER', getenv('AI_PROVIDER') ?: 'groq');
define('SHARD_COUNT', (int)(getenv('SHARD_COUNT') ?: 7));

define('SCORE_THRESHOLD', 3);
define('RSS_REQUEST_DELAY_MS', 1500);
define('AI_REQUEST_DELAY_MS', 4000);
define('MAX_SEEN_URLS', 50000);
define('PER_FACILITY_CAP', 15);
define('REQUEST_TIMEOUT_MS', 30000);
define('AI_TIMEOUT_MS', 90000);
define('MAX_ARTICLE_AGE_DAYS', 30);
define('GENERIC_ALIAS_MIN_FACILITIES', 3);
define('USER_AGENT', 'kids-over-profits-discovery/1.0 (+https://kidsoverprofits.org)');
define('BROWSER_USER_AGENT', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36');

$EXCLUDED_FACILITY_STATUSES = ['closed', 'transferred', 'adults only'];
$EXCLUDED_OPERATOR_STATUSES = ['defunct'];

$ABUSE_KEYWORDS = [
    'abuse', 'abused', 'abusing', 'arrest', 'arrested', 'indict', 'indicted', 'indictment',
    'charged', 'charges', 'lawsuit', 'sued', 'suing', 'investigation', 'investigates',
    'closed', 'closes', 'closure', 'shut down', 'shutdown', 'raided', 'raid',
    'pleads guilty', 'guilty plea', 'convicted', 'conviction', 'sentenced', 'sentencing',
    'allegation', 'allegations', 'alleged', 'misconduct', 'death', 'died', 'killed',
    'restraint', 'seclusion', 'neglect', 'assault', 'molest', 'molestation',
    'survivor', 'whistleblower', 'class action', 'settlement', 'fined',
    'license revoked', 'license suspended', 'shuttered', 'felony', 'felonies'
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

function day_of_year_utc(): int {
    return (int)gmdate('z') + 1;
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
    try {
        $res = http_request($url, [
            'method' => 'POST',
            'headers' => ['Content-Type' => 'application/json'],
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
        if (!isset($s['stats']) || !is_array($s['stats'])) {
            $s['stats'] = ['discovered' => 0, 'submitted' => 0, 'rejected' => 0];
        }
        return $s;
    }
    return ['version' => 2, 'lastRun' => null, 'seenUrls' => [], 'stats' => ['discovered' => 0, 'submitted' => 0, 'rejected' => 0]];
}

function save_state(array $state): void {
    if (count($state['seenUrls']) > MAX_SEEN_URLS) {
        $state['seenUrls'] = array_slice($state['seenUrls'], -MAX_SEEN_URLS);
    }
    save_json_file(STATE_FILE, $state);
}

function persist_rejected(array $newEntries): void {
    $existing = load_json_file(REJECTED_FILE, ['entries' => []]);
    $ts = gmdate('Y-m-d\TH:i:s\Z');
    $stamped = array_map(static fn($r) => array_merge(['ts' => $ts], $r), $newEntries);
    $merged = array_slice(array_merge($stamped, $existing['entries'] ?? []), 0, 500);
    save_json_file(REJECTED_FILE, ['lastUpdated' => $ts, 'entries' => $merged]);
}

// ============================================================
// Blacklist
// ============================================================

/** Returns ['hostBlocked' => callable, 'pathBlocked' => callable]. */
function build_blacklist_matcher(): array {
    global $HARD_BLOCKED_HOSTS;
    $bl = load_json_file(BLACKLIST_FILE, []);
    $allDomains = array_merge(
        is_array($bl['spamDomains'] ?? null) ? $bl['spamDomains'] : [],
        is_array($bl['pressReleaseWires'] ?? null) ? $bl['pressReleaseWires'] : [],
        is_array($bl['industryPromoDomains'] ?? null) ? $bl['industryPromoDomains'] : []
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
// Facility index (built fresh from API each run)
// ============================================================

function parse_location($loc): array {
    global $STATE_NAMES;
    if (!is_string($loc)) return ['city' => '', 'state' => ''];
    $trimmed = trim($loc);
    if ($trimmed === '') return ['city' => '', 'state' => ''];

    $parts = array_values(array_filter(array_map('trim', explode(',', $trimmed)), static fn($p) => $p !== ''));
    if (count($parts) < 2) return ['city' => $trimmed, 'state' => ''];

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
function build_facility_index(array $apiResponse): array {
    global $EXCLUDED_FACILITY_STATUSES, $EXCLUDED_OPERATOR_STATUSES;

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

    $addEntry = static function (array $entry) use (&$byKey) {
        if ($entry['queryName'] === '' || mb_strlen($entry['queryName']) < 4) return;
        $key = normalize_name($entry['queryName']) . '|' . ($entry['state'] ?? '');
        if (isset($byKey[$key])) {
            $existing = &$byKey[$key];
            $seen = [];
            foreach ($existing['aliases'] as $a) $seen[normalize_name($a)] = true;
            foreach ($entry['aliases'] as $alias) {
                $k = normalize_name($alias);
                if ($k !== '' && !isset($seen[$k])) {
                    $existing['aliases'][] = $alias;
                    $seen[$k] = true;
                }
            }
            if ($existing['city'] === '' && $entry['city'] !== '') $existing['city'] = $entry['city'];
            unset($existing);
            return;
        }
        $byKey[$key] = $entry;
    };

    foreach ($projects as $project) {
        $data = $project['data'] ?? [];
        $operator = $data['operator'] ?? [];
        $operatorStatus = strtolower(trim((string)($operator['status'] ?? '')));
        $operatorIsDefunct = in_array($operatorStatus, $EXCLUDED_OPERATOR_STATUSES, true);
        $operatorName = (string)($operator['name'] ?? ($project['name'] ?? ''));

        $collectHosts($operator['websites'] ?? null);

        // --- Per-facility entries ---
        $facilities = is_array($data['facilities'] ?? null) ? $data['facilities'] : [];
        foreach ($facilities as $f) {
            $ident = $f['identification'] ?? [];
            $period = $f['operatingPeriod'] ?? [];
            $status = strtolower(trim((string)($period['status'] ?? '')));

            $collectHosts($f['profileLinks'] ?? null);
            if (!empty($f['sourceOperator'])) $collectHosts($f['sourceOperator']['websites'] ?? null);

            if (in_array($status, $EXCLUDED_FACILITY_STATUSES, true)) continue;
            if ($operatorIsDefunct) continue;

            $primaryName = trim((string)($ident['currentName'] ?? ''));
            if ($primaryName === '') $primaryName = (string)($ident['name'] ?? '');
            if ($primaryName === '') continue;

            $aliases = [$primaryName];
            if (!empty($ident['name']) && $ident['name'] !== $primaryName) $aliases[] = $ident['name'];
            foreach ([($ident['otherNames'] ?? null), ($ident['pastNames'] ?? null)] as $list) {
                if (is_array($list)) {
                    foreach ($list as $n) {
                        if (is_string($n) && trim($n) !== '') $aliases[] = trim($n);
                    }
                }
            }
            foreach (array_slice($aliases, 0) as $a) {
                foreach (generate_alias_variants($a) as $v) $aliases[] = $v;
            }

            $cs = parse_location($f['location'] ?? null);

            $addEntry([
                'queryName' => $primaryName,
                'aliases' => $aliases,
                'city' => $cs['city'],
                'state' => $cs['state'],
                'bucket' => 'facility',
                'operator' => $operatorName !== '' ? $operatorName : (string)($f['sourceOperator']['name'] ?? ''),
                'status' => (string)($period['status'] ?? '')
            ]);
        }

        // --- Operator-level entry (companies category only) ---
        if (($project['category'] ?? '') === 'companies' && $operatorName !== '' && !$operatorIsDefunct) {
            $aliases = [$operatorName];
            foreach ([($operator['otherNames'] ?? null), ($operator['parentCompanies'] ?? null)] as $list) {
                if (is_array($list)) {
                    foreach ($list as $n) {
                        if (is_string($n) && trim($n) !== '') $aliases[] = trim($n);
                    }
                }
            }
            foreach (array_slice($aliases, 0) as $a) {
                foreach (generate_alias_variants($a) as $v) $aliases[] = $v;
            }
            $addEntry([
                'queryName' => $operatorName,
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

    // Generic aliases — names shared across N+ deduped entries.
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
        if ($n >= GENERIC_ALIAS_MIN_FACILITIES) $genericAliases[$k] = true;
    }

    return ['facilities' => $facilities, 'ownHosts' => $ownHosts, 'genericAliases' => $genericAliases];
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

function google_news_url(array $facility): string {
    global $STATE_NAMES;
    $nameToken = '"' . $facility['queryName'] . '"';
    $stateToken = ($facility['state'] !== '' && isset($STATE_NAMES[$facility['state']]))
        ? '"' . $STATE_NAMES[$facility['state']] . '"' : '';
    $query = implode(' ', array_filter([
        $nameToken, $stateToken,
        '(abuse OR lawsuit OR arrested OR indicted OR investigation OR closure OR raid OR allegations OR survivor)'
    ], static fn($t) => $t !== ''));
    return 'https://news.google.com/rss/search?' . http_build_query([
        'q' => $query, 'hl' => 'en-US', 'gl' => 'US', 'ceid' => 'US:en'
    ]);
}

function fetch_google_news_for_facility(array $facility): array {
    try {
        $xml = fetch_text(google_news_url($facility));
        $cutoff = time() - MAX_ARTICLE_AGE_DAYS * 86400;
        $fresh = array_values(array_filter(parse_rss_items($xml), static function ($item) use ($cutoff) {
            if ($item['pubDate'] === '') return true;   // keep if unparseable
            $t = strtotime($item['pubDate']);
            return $t === false || $t >= $cutoff;
        }));
        return array_map(static fn($item) => array_merge($item, [
            'origin' => 'google-news',
            'facilityQuery' => $facility['queryName'],
            'facilityState' => $facility['state'],
            'facilityCity' => $facility['city']
        ]), array_slice($fresh, 0, PER_FACILITY_CAP));
    } catch (Throwable $err) {
        kop_warn("  ! Google News failed for \"{$facility['queryName']}\": {$err->getMessage()}");
        return [];
    }
}

// Reddit hard-blocks unauthenticated .json API requests, but the Atom feeds
// remain open under a tight per-IP rate limit. Preferred path: OAuth via a
// free reddit "script" app — set REDDIT_CLIENT_ID + REDDIT_CLIENT_SECRET.
define('REDDIT_FEED_URL', 'https://www.reddit.com/r/troubledteens/new.rss?limit=100');
define('REDDIT_CLIENT_ID', getenv('REDDIT_CLIENT_ID') ?: '');
define('REDDIT_CLIENT_SECRET', getenv('REDDIT_CLIENT_SECRET') ?: '');

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
                $u = preg_replace('/[.,;:!?*_`)\]]+$/', '', $raw);
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
    $hay = ' ' . mb_strtolower($text) . ' ';
    $best = null;

    foreach ($facilityIndex as $fac) {
        foreach ($fac['aliases'] as $alias) {
            $needle = mb_strtolower(trim((string)$alias));
            if (mb_strlen($needle) < 5) continue;
            if (str_contains($hay, " {$needle} ") ||
                str_contains($hay, " {$needle},") ||
                str_contains($hay, " {$needle}.") ||
                str_contains($hay, " {$needle}'") ||
                str_contains($hay, " {$needle}:")) {
                if ($best === null || mb_strlen((string)$alias) > mb_strlen($best['matchedAlias'])) {
                    $best = ['facility' => $fac, 'matchedAlias' => (string)$alias];
                }
            }
        }
    }
    return $best;
}

/** Returns [abbr => true] for every state signal found in the text. */
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
function evaluate_candidate(array $candidate, array $facilityIndex, array $blacklist, array $facilityOwnHosts, array $genericAliases): array {
    global $ABUSE_KEYWORDS;
    $text = $candidate['title'] . ' ' . $candidate['description'];
    $reasons = [];
    $score = 0;

    $candHost = host_of($candidate['sourceUrl'] !== '' ? $candidate['sourceUrl'] : $candidate['link']);
    if ($candHost === '') {
        return ['accept' => false, 'reason' => 'invalid-url', 'meta' => ['link' => $candidate['link']]];
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

    $match = match_facility($text, $facilityIndex);
    $cityMatched = false;

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

        $score += 2;
        $reasons[] = 'facility:' . $match['matchedAlias'];

        if ($fac['city'] !== '' && str_contains(mb_strtolower($text), mb_strtolower($fac['city']))) {
            $score += 2;
            $cityMatched = true;
            $reasons[] = 'city:' . $fac['city'];
        }

        if (!$cityMatched && $fac['state'] !== '' && $fac['bucket'] !== 'operator') {
            $signals = extract_state_signals($text);
            if (count($signals) > 0 && !isset($signals[$fac['state']])) {
                return [
                    'accept' => false,
                    'reason' => 'state-mismatch',
                    'meta' => [
                        'matchedAlias' => $match['matchedAlias'],
                        'facility' => $fac['queryName'],
                        'expectedState' => $fac['state'],
                        'detectedStates' => array_keys($signals)
                    ]
                ];
            }
            if ($genericAliases && isset($genericAliases[normalize_name($match['matchedAlias'])]) && !isset($signals[$fac['state']])) {
                return [
                    'accept' => false,
                    'reason' => 'generic-alias-unconfirmed',
                    'meta' => [
                        'matchedAlias' => $match['matchedAlias'],
                        'facility' => $fac['queryName'],
                        'expectedState' => $fac['state'],
                        'detectedStates' => array_keys($signals)
                    ]
                ];
            }
        }
    }

    // Abuse keyword scoring (title weighted higher than description)
    $titleLower = mb_strtolower($candidate['title']);
    $titleHit = null;
    foreach ($ABUSE_KEYWORDS as $k) {
        if (str_contains($titleLower, $k)) { $titleHit = $k; break; }
    }
    if ($titleHit !== null) {
        $score += 3;
        $reasons[] = 'title-kw:' . $titleHit;
    } else {
        $descLower = mb_strtolower($candidate['description']);
        foreach ($ABUSE_KEYWORDS as $k) {
            if (str_contains($descLower, $k)) {
                $score += 1;
                $reasons[] = 'desc-kw:' . $k;
                break;
            }
        }
    }

    if ($candidate['origin'] === 'reddit-link') {
        $score += 1;
        $reasons[] = 'reddit-link-post';
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

function submit_candidate(array $candidate, array $evalResult, array $options = []): array {
    $submittedBy = $options['submittedBy'] ?? 'auto-discovery';
    $noteLabel   = $options['noteLabel'] ?? 'auto-discovery';

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

    $data = is_array($aiRes['body']['data'] ?? null) ? $aiRes['body']['data'] : [];

    $joinList = static fn($v) => is_array($v) ? implode("\n", $v) : (string)($v ?? '');

    $discoveryNote = implode(' | ', array_filter([
        "{$noteLabel} via {$candidate['origin']}",
        'score=' . $evalResult['score'],
        'reasons=' . implode(',', $evalResult['reasons']),
        $evalResult['match'] !== null ? 'match=' . json_encode($evalResult['match'], JSON_UNESCAPED_SLASHES) : '',
        !empty($candidate['facilityQuery']) ? 'query=' . $candidate['facilityQuery'] : '',
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

    $state = load_state();
    $seen = array_fill_keys($state['seenUrls'], true);
    $blacklist = build_blacklist_matcher();

    // -- Fetch facility data live from API --
    kop_log("\nFetching facilities from API...");
    $facJson = fetch_json(FACILITIES_URL, ['timeoutMs' => 60000]);
    $index = build_facility_index($facJson);
    $facilityIndex = $index['facilities'];
    $facilityOwnHosts = $index['ownHosts'];
    $genericAliases = $index['genericAliases'];
    kop_log('  built facility index: ' . count($facilityIndex) . ' unique active entries');
    kop_log('  facility-owned hosts: ' . count($facilityOwnHosts) . ' (skipped as candidates)');
    kop_log('  generic aliases:      ' . count($genericAliases) . ' (require positive state-signal match)');

    // -- Today's shard (1/N of the active list) --
    $today = day_of_year_utc();
    $shardIndex = $today % SHARD_COUNT;
    $todaysShard = [];
    foreach ($facilityIndex as $i => $fac) {
        if ($i % SHARD_COUNT === $shardIndex) $todaysShard[] = $fac;
    }
    $slice = MAX_FACILITIES ? array_slice($todaysShard, 0, MAX_FACILITIES) : $todaysShard;
    kop_log("  today = shard {$shardIndex}/" . SHARD_COUNT . ' → ' . count($slice) . ' facilities to query' .
        (MAX_FACILITIES ? ' (capped from ' . count($todaysShard) . ')' : ''));

    $candidates = [];

    // -- Reddit pass --
    kop_log("\nPolling Reddit...");
    $redditItems = fetch_reddit_candidates();
    kop_log('  ' . count($redditItems) . ' reddit items');
    array_push($candidates, ...($redditItems ?: []));

    // -- Google News per facility (with politeness delay) --
    kop_log("\nQuerying Google News per facility...");
    foreach ($slice as $i => $fac) {
        sleep_ms(RSS_REQUEST_DELAY_MS);
        $items = fetch_google_news_for_facility($fac);
        if (count($items) > 0) {
            kop_log("  [{$fac['queryName']}" . ($fac['state'] !== '' ? ' / ' . $fac['state'] : '') . '] ' . count($items) . ' items');
        }
        array_push($candidates, ...($items ?: []));
        if (($i + 1) % 25 === 0) kop_log('  ...' . ($i + 1) . '/' . count($slice) . ' (running total: ' . count($candidates) . ')');
    }

    kop_log("\nTotal raw candidates: " . count($candidates));
    $state['stats']['discovered'] += count($candidates);

    // -- Dedupe + filter --
    $queue = [];
    $rejected = [];
    $dedupeSeen = [];

    foreach ($candidates as $c) {
        if (empty($c['link']) || !str_starts_with($c['link'], 'http')) continue;
        $h = hash_url($c['link']);
        if (isset($seen[$h]) || isset($dedupeSeen[$h])) continue;
        $dedupeSeen[$h] = true;

        $result = evaluate_candidate($c, $facilityIndex, $blacklist, $facilityOwnHosts, $genericAliases);
        if ($result['accept']) {
            $queue[] = ['candidate' => $c, 'evalResult' => $result, 'urlHash' => $h];
        } else {
            $state['stats']['rejected'] += 1;
            $rejected[] = [
                'link' => $c['link'],
                'title' => $c['title'],
                'origin' => $c['origin'],
                'host' => host_of($c['sourceUrl'] !== '' ? $c['sourceUrl'] : $c['link']),
                'facilityQuery' => $c['facilityQuery'] ?? null,
                'reason' => $result['reason'],
                'meta' => $result['meta'] ?? null
            ];
        }
    }
    kop_log('After filter: ' . count($queue) . ' accepted, ' . count($rejected) . ' rejected (threshold ' . SCORE_THRESHOLD . ')');

    // -- Persist rejected log right away (useful even if submit phase aborts) --
    if ($rejected) persist_rejected($rejected);

    if (DRY_RUN) {
        kop_log("\n--- DRY RUN — resolving + would-submit (showing up to 30) ---");
        $resolveUnresolved = 0;
        foreach (array_slice($queue, 0, 30) as $q) {
            $orig = $q['candidate']['link'];
            $resolved = resolve_google_news_url($orig);
            $unresolved = str_starts_with($resolved, 'https://news.google.com/');
            if ($unresolved) $resolveUnresolved++;
            kop_log("  [score {$q['evalResult']['score']}] " . ($unresolved ? '✗ UNRESOLVED' : '✓') . " {$resolved}");
            if ($resolved !== $orig && !$unresolved) kop_log('     was:     ' . substr($orig, 0, 80) . '…');
            kop_log('     title:   ' . substr($q['candidate']['title'], 0, 100));
            kop_log('     reasons: ' . implode(', ', $q['evalResult']['reasons']));
            if ($q['evalResult']['match'] !== null) kop_log('     match:   ' . json_encode($q['evalResult']['match'], JSON_UNESCAPED_SLASHES));
        }
        kop_log("\nDry run done. Would attempt " . min(count($queue), SUBMIT_LIMIT) . ' submissions.');
        if ($resolveUnresolved > 0) kop_log("  {$resolveUnresolved} Google News URL(s) could not be resolved — would be rejected.");
        return;
    }

    // -- Submit --
    // Each accepted candidate: resolve GN redirect → re-check dedup/blacklists
    // on the canonical URL → AI process → submit.
    $submitted = 0;
    $submitErrors = 0;
    $postResolveRejected = 0;
    $postResolveLog = [];

    foreach ($queue as $q) {
        if ($submitted >= SUBMIT_LIMIT) break;

        $originalLink = $q['candidate']['link'];
        $resolvedLink = resolve_google_news_url($originalLink);

        // Hard reject unresolved GN URLs — submitting them produces empty AI
        // extractions (the consent page has no article body).
        if (str_starts_with($resolvedLink, 'https://news.google.com/')) {
            $seen[$q['urlHash']] = true;
            $postResolveRejected++;
            $postResolveLog[] = [
                'link' => $originalLink,
                'reason' => 'gn-resolution-failed',
                'facilityQuery' => $q['candidate']['facilityQuery'] ?? null
            ];
            continue;
        }

        if ($resolvedLink !== $originalLink) {
            // Re-dedup against the canonical URL — same article may appear
            // under multiple Google News redirect tokens.
            $newHash = hash_url($resolvedLink);
            if (isset($seen[$newHash])) {
                $seen[$q['urlHash']] = true;
                $postResolveRejected++;
                $postResolveLog[] = ['link' => $originalLink, 'resolvedTo' => $resolvedLink, 'reason' => 'duplicate-after-resolution'];
                continue;
            }

            $newHost = article_host_of($resolvedLink);
            if ($newHost !== '' && $blacklist['hostBlocked']($newHost)) {
                $seen[$q['urlHash']] = true;
                $postResolveRejected++;
                $postResolveLog[] = ['link' => $originalLink, 'resolvedTo' => $resolvedLink, 'reason' => 'blacklist-host-post-resolve', 'host' => $newHost];
                continue;
            }
            if ($newHost !== '' && isset($facilityOwnHosts[$newHost])) {
                $seen[$q['urlHash']] = true;
                $postResolveRejected++;
                $postResolveLog[] = ['link' => $originalLink, 'resolvedTo' => $resolvedLink, 'reason' => 'facility-own-website-post-resolve', 'host' => $newHost];
                continue;
            }
            $pathHit = $blacklist['pathBlocked']($resolvedLink);
            if ($pathHit) {
                $seen[$q['urlHash']] = true;
                $postResolveRejected++;
                $postResolveLog[] = ['link' => $originalLink, 'resolvedTo' => $resolvedLink, 'reason' => 'blacklist-path-post-resolve', 'pattern' => $pathHit];
                continue;
            }

            $q['candidate']['link'] = $resolvedLink;
            $seen[$newHash] = true;
        }

        // Mark seen before attempting submission so a flaky URL isn't retried
        // every run.
        $seen[$q['urlHash']] = true;

        sleep_ms(AI_REQUEST_DELAY_MS);
        $r = submit_candidate($q['candidate'], $q['evalResult']);

        // Provider rate limits are transient — wait one cool-down and retry
        // once within the run.
        if (!$r['ok'] && $r['stage'] === 'ai' && preg_match('/rate limit/i', $r['error'] ?? '')) {
            kop_log('    rate-limited; retrying once after cool-down…');
            sleep_ms(30000);
            $r = submit_candidate($q['candidate'], $q['evalResult']);
        }

        if ($r['ok']) {
            $submitted++;
            $state['stats']['submitted'] += 1;
        } else {
            $submitErrors++;
            // AI-stage failure says nothing bad about the URL (rate limit,
            // provider hiccup, timeout) — un-mark so the next daily run
            // retries. Submit-stage failures (e.g. duplicates) stay marked.
            if ($r['stage'] === 'ai') {
                unset($seen[$q['urlHash']], $seen[hash_url($q['candidate']['link'])]);
            }
        }
    }

    if ($postResolveLog) {
        $state['stats']['rejected'] += count($postResolveLog);
        persist_rejected($postResolveLog);
    }

    $state['seenUrls'] = array_keys($seen);
    $state['lastRun'] = gmdate('Y-m-d\TH:i:s\Z');
    save_state($state);

    kop_log("\n--- Done ---");
    kop_log('  facilities queried:  ' . count($slice));
    kop_log('  candidates found:    ' . count($candidates));
    kop_log('  accepted by filter:  ' . count($queue));
    kop_log('  submitted (ok):      ' . $submitted);
    kop_log('  submitted (errors):  ' . $submitErrors);
    kop_log('  rejected (pre-fetch):  ' . count($rejected));
    kop_log('  rejected (post-resolve): ' . $postResolveRejected);
    kop_log('  cumulative stats:    ' . json_encode($state['stats'], JSON_UNESCAPED_SLASHES));

    if ($submitErrors > 0 && $submitted === 0) {
        exit(1);
    }
}

try {
    main();
} catch (Throwable $err) {
    fwrite(STDERR, 'Discovery failed: ' . $err->getMessage() . "\n" . $err->getTraceAsString() . "\n");
    exit(1);
}
