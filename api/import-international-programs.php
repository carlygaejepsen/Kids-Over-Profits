<?php
/**
 * Import the international programs listed on the /international page into
 * locations_master, grouped into one location project per country (mirroring how
 * the 50 US states are stored). Each program becomes a nested facility and is
 * promoted to a facilities_master row (via kop_ensure_facility_ids_in_data) so it
 * gets a stable facility_id and can be linked to news.
 *
 * Parses each <p class="wp-block-paragraph"> entry shaped:
 *   <strong>Name (CLOSED) (WWASP) (fka Old Name)<br></strong>(2000-2003)<br>City, Country
 *
 *   - "(CLOSED)"           -> operatingPeriod.status = "Closed"
 *   - "(YYYY-YYYY|Present)"/"(est. YYYY)" -> operatingPeriod start/end
 *   - "(fka X / Y)"        -> identification.pastNames
 *   - "(aka X)"            -> identification.otherNames
 *   - trailing address     -> facility address; country drives the grouping
 *
 * Idempotent: re-running merges by facility name (no duplicates). Existing
 * country location rows are merged into, not overwritten.
 *
 * Safety: requires ?run=1 from a logged-in admin, or CLI. ?dry=1 previews.
 *   Preview: /api/import-international-programs.php?run=1&dry=1
 *   Import:  /api/import-international-programs.php?run=1
 *   Source override: &url=<page url>   (defaults to home_url('/international/'))
 */

header('Content-Type: application/json');

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/facility-promotion.php';

$is_cli  = php_sapi_name() === 'cli';
$dry_run = $is_cli ? in_array('dry', $argv ?? [], true) : !empty($_GET['dry']);
$run_ok  = $is_cli || (($_GET['run'] ?? null) === '1');

if (!defined('ABSPATH')) {
    $current = __DIR__;
    for ($i = 0; $i < 6; $i++) {
        $current = dirname($current);
        if (file_exists($current . '/wp-load.php')) { require_once $current . '/wp-load.php'; break; }
    }
}

if (!$is_cli) {
    $is_admin = function_exists('current_user_can') && current_user_can('manage_options');
    if (!$run_ok || !$is_admin) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Requires ?run=1 and an admin session, or CLI execution.']);
        exit;
    }
}

/** Country detection: variants -> canonical name. Most specific first. */
function kop_detect_country(string $address): ?string {
    $a = ' ' . strtolower($address) . ' ';
    $map = [
        'new south wales'   => 'Australia',
        'dominican republic'=> 'Dominican Republic',
        'costa rica'        => 'Costa Rica',
        'czech republic'    => 'Czech Republic',
        'western samoa'     => 'Samoa',
        'united arab emirates' => 'United Arab Emirates',
        'new zealand'       => 'New Zealand',
        'united kingdom'    => 'United Kingdom',
        'united kingdon'    => 'United Kingdom', // page typo
        'england'           => 'United Kingdom',
        'scotland'          => 'United Kingdom',
        'jersey'            => 'Jersey',
        'jamaica'           => 'Jamaica',
        'canada'            => 'Canada',
        'mexico'            => 'Mexico',
        'méxico'            => 'Mexico',
        'israel'            => 'Israel',
        'jerusalem'         => 'Israel',
        'beer yaakov'       => 'Israel',
        'italy'             => 'Italy',
        'samoa'             => 'Samoa',
        'argentina'         => 'Argentina',
        'fiji'              => 'Fiji',
        'spain'             => 'Spain',
        'mallorca'          => 'Spain',
        'switzerland'       => 'Switzerland',
        'zurich'            => 'Switzerland',
        'netherlands'       => 'Netherlands',
        'malaysia'          => 'Malaysia',
    ];
    foreach ($map as $needle => $canon) {
        if (strpos($a, $needle) !== false) return $canon;
    }
    return null;
}

/** Parse the /international HTML into program records. */
function kop_parse_international(string $html): array {
    $out = [];
    if (!preg_match_all('/<p[^>]*class="[^"]*wp-block-paragraph[^"]*"[^>]*>(.*?)<\/p>/is', $html, $blocks)) {
        return $out;
    }
    foreach ($blocks[1] as $block) {
        if (stripos($block, '<strong>') === false) continue;
        if (!preg_match('/<strong>(.*?)<\/strong>/is', $block, $sm)) continue;

        $clean = static function ($s) {
            $s = preg_replace('/<br\s*\/?>/i', "\n", $s);
            $s = html_entity_decode(strip_tags($s), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            return trim($s);
        };

        $nameLine = trim(preg_split('/\n+/', $clean($sm[1]))[0] ?? '');
        if ($nameLine === '') continue;

        $rest = preg_replace('/.*<\/strong>/is', '', $block);
        $restLines = array_values(array_filter(array_map('trim', preg_split('/\n+/', $clean($rest)))));

        // Pull all "(...)" annotations off the name.
        $status = 'Unknown';
        $startYear = '';
        $endYear = '';
        $pastNames = [];
        $otherNames = [];
        if (preg_match_all('/\(([^)]*)\)/', $nameLine, $pm)) {
            foreach ($pm[1] as $ann) {
                $a = trim($ann);
                if (preg_match('/closed/i', $a)) $status = 'Closed';
                elseif (preg_match('/^wwasp/i', $a)) { /* operator hint, recorded via name only */ }
                elseif (preg_match('/^(?:fka|formerly)\s+(.+)/i', $a, $m)) {
                    foreach (preg_split('#\s*/\s*#', $m[1]) as $n) { $n = trim($n); if ($n !== '') $pastNames[] = $n; }
                } elseif (preg_match('/^aka\s+(.+)/i', $a, $m)) {
                    foreach (preg_split('#\s*/\s*#', $m[1]) as $n) { $n = trim($n); if ($n !== '') $otherNames[] = $n; }
                } elseif (preg_match('/est\.?\s*(\d{4})/i', $a, $m)) {
                    $startYear = $m[1];
                } elseif (preg_match('/(\d{4})\s*[-\x{2013}]\s*(\d{4}|present)/iu', $a, $m)) {
                    $startYear = $m[1]; $endYear = ucfirst(strtolower($m[2]));
                }
            }
        }
        $name = trim(preg_replace('/\s*\([^)]*\)/', '', $nameLine));
        $name = trim(preg_replace('/\s+/', ' ', $name));
        if ($name === '') continue;

        // Rest lines: a parenthetical/year line is the operating period; the rest is address.
        $addressLines = [];
        foreach ($restLines as $line) {
            if (preg_match('/^\(?\s*(\d{4})\s*[-\x{2013}]\s*(\d{4}|present)\s*\)?$/iu', $line, $m)) {
                if ($startYear === '') $startYear = $m[1];
                if ($endYear === '') $endYear = ucfirst(strtolower($m[2]));
            } elseif (preg_match('/^\(?\s*est\.?\s*(\d{4})\s*\)?$/i', $line, $m)) {
                if ($startYear === '') $startYear = $m[1];
            } else {
                $addressLines[] = $line;
            }
        }
        $address = trim(implode('; ', $addressLines));
        $country = kop_detect_country($address);

        $out[] = [
            'name'       => $name,
            'pastNames'  => $pastNames,
            'otherNames' => $otherNames,
            'status'     => $status,
            'startYear'  => $startYear,
            'endYear'    => $endYear,
            'address'    => $address,
            'country'    => $country,
        ];
    }
    return $out;
}

/** Build a location-project facility entry from a parsed program. */
function kop_build_intl_facility(array $p): array {
    return [
        'identification' => [
            'name'           => $p['name'],
            'currentName'    => '',
            'currentOperator'=> '',
            'otherNames'     => $p['otherNames'],
            'pastNames'      => $p['pastNames'],
            'currentOwners'  => [],
            'knownReferrers' => [],
        ],
        'location' => $p['address'],
        'address'  => $p['address'],
        'operatingPeriod' => [
            'startYear' => $p['startYear'],
            'endYear'   => $p['endYear'] ?: ($p['status'] === 'Closed' ? '' : 'Present'),
            'status'    => $p['status'],
        ],
    ];
}

try {
    $url = $_GET['url'] ?? (function_exists('home_url') ? home_url('/international/') : 'https://kidsoverprofits.org/international/');

    $html = '';
    if (function_exists('wp_remote_get')) {
        $resp = wp_remote_get($url, ['timeout' => 30]);
        if (!is_wp_error($resp)) $html = (string) wp_remote_retrieve_body($resp);
    }
    if ($html === '') $html = (string) @file_get_contents($url);
    if ($html === '') throw new Exception('Could not fetch ' . $url);

    $programs = kop_parse_international($html);

    // Group by country.
    $byCountry = [];
    $noCountry = [];
    foreach ($programs as $p) {
        if (!$p['country']) { $noCountry[] = $p['name']; continue; }
        $byCountry[$p['country']][] = $p;
    }
    ksort($byCountry);

    $findLoc   = $pdo->prepare("SELECT json_data FROM locations_master WHERE unique_name = ? LIMIT 1");
    $upsertLoc = $pdo->prepare(
        "INSERT INTO locations_master (unique_name, json_data, updated_at) VALUES (?, ?, NOW())
         ON DUPLICATE KEY UPDATE json_data = VALUES(json_data), updated_at = NOW()"
    );

    $stats = [
        'programs_parsed'    => count($programs),
        'countries'          => [],
        'facilities_added'   => 0,
        'facilities_existing'=> 0,
        'promoted'           => 0,
        'no_country'         => $noCountry,
    ];

    foreach ($byCountry as $country => $list) {
        $uniqueName = strtoupper($country);

        $findLoc->execute([$uniqueName]);
        $rawExisting = $findLoc->fetchColumn();
        $project = $rawExisting ? json_decode((string)$rawExisting, true) : null;
        if (!is_array($project)) {
            $project = [
                'name'     => $uniqueName,
                'data'     => ['operator' => ['name' => $uniqueName, 'type' => 'Location Aggregate'], 'facilities' => []],
                'category' => 'locations',
            ];
        }
        if (!isset($project['data']) || !is_array($project['data'])) $project['data'] = [];
        if (!isset($project['data']['facilities']) || !is_array($project['data']['facilities'])) $project['data']['facilities'] = [];

        // Index existing facility names for de-dupe.
        $have = [];
        foreach ($project['data']['facilities'] as $f) {
            $n = strtolower(trim((string)($f['identification']['name'] ?? $f['name'] ?? '')));
            if ($n !== '') $have[$n] = true;
        }

        $added = 0; $existing = 0;
        foreach ($list as $p) {
            $key = strtolower(trim($p['name']));
            if (isset($have[$key])) { $existing++; continue; }
            $project['data']['facilities'][] = kop_build_intl_facility($p);
            $have[$key] = true;
            $added++;
        }
        $stats['facilities_added']    += $added;
        $stats['facilities_existing'] += $existing;

        if (!$dry_run && $added > 0) {
            $stats['promoted'] += kop_ensure_facility_ids_in_data($pdo, $project['data']);
            $project['timestamp'] = date('c');
            $upsertLoc->execute([$uniqueName, json_encode($project, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
        }

        $stats['countries'][$country] = ['parsed' => count($list), 'added' => $added, 'already_present' => $existing];
    }

    echo json_encode(['success' => true, 'dry_run' => (bool)$dry_run, 'source' => $url, 'stats' => $stats],
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Error: ' . $e->getMessage()]);
}
