<?php
/**
 * API - Fetch Bill Details
 *
 * Receives bill_number, jurisdiction, and level, and attempts to fetch
 * structured data from legislative sources.
 */

header('Content-Type: application/json');

require_once __DIR__ . '/config.php';

if (!defined('ABSPATH')) {
    $current = __DIR__;
    for ($i = 0; $i < 6; $i++) {
        $current = dirname($current);
        if (file_exists($current . '/wp-load.php')) {
            require_once $current . '/wp-load.php';
            break;
        }
    }
}

// Resolve API keys from env / WordPress constants / $_SERVER
function kop_resolve_secret($name) {
    $v = getenv($name);
    if ($v !== false && $v !== '') return $v;
    if (!empty($_ENV[$name])) return $_ENV[$name];
    if (!empty($_SERVER[$name])) return $_SERVER[$name];
    if (defined($name)) {
        $c = constant($name);
        if (!empty($c)) return $c;
    }
    return '';
}

// Build a diagnostic report (no secret values) explaining where .env was searched.
function kop_env_diagnostic($name) {
    $report = [
        'requested_key' => $name,
        'env_file_found_at' => null,
        'env_file_searched_paths' => [],
        'wp_constant_defined' => defined($name),
        'getenv_returned_false' => (getenv($name) === false),
        'in_dollar_env' => array_key_exists($name, $_ENV),
        'in_dollar_server' => array_key_exists($name, $_SERVER),
    ];
    $current = __DIR__;
    for ($i = 0; $i < 6; $i++) {
        $current = dirname($current);
        $report['env_file_searched_paths'][] = $current . '/.env';
        if ($report['env_file_found_at'] === null && file_exists($current . '/.env')) {
            $report['env_file_found_at'] = $current . '/.env';
        }
    }
    return $report;
}

$OPENSTATES_API_KEY = kop_resolve_secret('OPENSTATES_API_KEY');
$CONGRESS_API_KEY   = kop_resolve_secret('CONGRESS_API_KEY');

$bill_number = $_GET['bill_number'] ?? '';
$jurisdiction = $_GET['jurisdiction'] ?? '';
$level = $_GET['level'] ?? 'state';

if (empty($bill_number) || empty($jurisdiction)) {
    echo json_encode(['success' => false, 'error' => 'Missing required parameters.']);
    exit;
}

// Map a free-text federal action ("Became Public Law No: 119-12.") to a dropdown value.
function kop_federal_status_from_action($text) {
    $t = strtolower($text ?? '');
    if ($t === '') return 'unknown';
    if (strpos($t, 'became public law') !== false || strpos($t, 'became law') !== false) return 'enacted';
    if (strpos($t, 'signed by president') !== false) return 'signed';
    if (strpos($t, 'veto') !== false) return 'vetoed';
    if (strpos($t, 'passed/agreed to in house') !== false || strpos($t, 'passed house') !== false) return 'passed_house';
    if (strpos($t, 'passed/agreed to in senate') !== false || strpos($t, 'passed senate') !== false) return 'passed_senate';
    if (strpos($t, 'committee') !== false) return 'in_committee';
    if (strpos($t, 'introduced') !== false) return 'introduced';
    return 'unknown';
}

// Pick the most advanced status from an OpenStates latest_action_classification array.
function kop_state_status_from_classifications($classifications) {
    if (!is_array($classifications) || empty($classifications)) return 'introduced';
    $priority = [
        'became-law'                  => 'enacted',
        'executive-signature'         => 'signed',
        'executive-veto'              => 'vetoed',
        'failure'                     => 'dead',
        'passed-upper-chamber'        => 'passed_senate',
        'passed-lower-chamber'        => 'passed_house',
        'passage'                     => 'passed_house',
        'committee-passage'           => 'in_committee',
        'committee-passage-favorable' => 'in_committee',
        'referral-committee'          => 'in_committee',
        'introduction'                => 'introduced',
    ];
    foreach ($priority as $slug => $value) {
        if (in_array($slug, $classifications, true)) return $value;
    }
    return 'introduced';
}

try {
    $result_data = [];

    if (strtolower($level) === 'federal') {
        // --- CONGRESS.GOV API (Federal) ---
        // Format: https://api.congress.gov/v3/bill/{congress}/{billType}/{billNumber}
        // Each Congress spans 2 years starting Jan 3 of an odd year (1st = 1789).
        $current_year = (int) date('Y');
        $start_year   = ($current_year % 2 === 0) ? $current_year - 1 : $current_year;
        $congress     = (int) (($start_year - 1789) / 2) + 1;

        $mod100 = $congress % 100;
        $mod10  = $congress % 10;
        if (in_array($mod100, [11, 12, 13], true)) {
            $suffix = 'th';
        } else {
            $suffix = ['th', 'st', 'nd', 'rd', 'th', 'th', 'th', 'th', 'th', 'th'][$mod10];
        }
        $congress_label = $congress . $suffix;

        preg_match('/([a-zA-Z]+)\s*(\d+)/', $bill_number, $matches);
        $type = strtolower($matches[1] ?? 'hr');
        $num = $matches[2] ?? preg_replace('/\D/', '', $bill_number);

        if (empty($CONGRESS_API_KEY)) {
            echo json_encode([
                'success' => false,
                'error'   => 'Congress.gov API key is not configured in the environment.',
                'diagnostic' => kop_env_diagnostic('CONGRESS_API_KEY'),
            ]);
            exit;
        }

        $url = "https://api.congress.gov/v3/bill/{$congress}/{$type}/{$num}?api_key={$CONGRESS_API_KEY}";

        $response = wp_remote_get($url);
        if (is_wp_error($response)) throw new Exception($response->get_error_message());

        $body = json_decode(wp_remote_retrieve_body($response), true);
        $bill = $body['bill'] ?? null;

        if (!$bill) throw new Exception("Federal bill not found.");

        // Fetch bill text URL. Congress.gov returns text versions in reverse
        // chronological order; take the first and prefer the formatted-text link.
        $full_text_url = '';
        $text_url = "https://api.congress.gov/v3/bill/{$congress}/{$type}/{$num}/text?api_key={$CONGRESS_API_KEY}";
        $text_response = wp_remote_get($text_url);
        if (!is_wp_error($text_response)) {
            $text_body = json_decode(wp_remote_retrieve_body($text_response), true);
            $versions = $text_body['textVersions'] ?? [];
            if (!empty($versions)) {
                $latest = $versions[0];
                $formats = $latest['formats'] ?? [];
                foreach ($formats as $fmt) {
                    if (stripos($fmt['type'] ?? '', 'Formatted Text') !== false) {
                        $full_text_url = $fmt['url'];
                        break;
                    }
                }
                if ($full_text_url === '' && !empty($formats[0]['url'])) {
                    $full_text_url = $formats[0]['url'];
                }
            }
        }

        // Fetch summary (latest version's text, HTML stripped).
        $summary = '';
        $sum_url = "https://api.congress.gov/v3/bill/{$congress}/{$type}/{$num}/summaries?api_key={$CONGRESS_API_KEY}";
        $sum_response = wp_remote_get($sum_url);
        if (!is_wp_error($sum_response)) {
            $sum_body = json_decode(wp_remote_retrieve_body($sum_response), true);
            $summaries = $sum_body['summaries'] ?? [];
            if (!empty($summaries)) {
                usort($summaries, function($a, $b) {
                    return strcmp($b['actionDate'] ?? '', $a['actionDate'] ?? '');
                });
                $summary = trim(html_entity_decode(strip_tags($summaries[0]['text'] ?? '')));
            }
        }

        // Subject tags: policyArea (inline) + legislativeSubjects from /subjects.
        $subject_tags = [];
        if (!empty($bill['policyArea']['name'])) {
            $subject_tags[] = $bill['policyArea']['name'];
        }
        $subj_url = "https://api.congress.gov/v3/bill/{$congress}/{$type}/{$num}/subjects?api_key={$CONGRESS_API_KEY}";
        $subj_response = wp_remote_get($subj_url);
        if (!is_wp_error($subj_response)) {
            $subj_body = json_decode(wp_remote_retrieve_body($subj_response), true);
            foreach ($subj_body['subjects']['legislativeSubjects'] ?? [] as $s) {
                if (!empty($s['name'])) $subject_tags[] = $s['name'];
            }
        }
        $subject_tags = array_values(array_unique($subject_tags));

        // Sponsors (primary only is returned inline by /bill).
        $sponsors = [];
        foreach ($bill['sponsors'] ?? [] as $s) {
            $name = trim($s['fullName'] ?? '');
            if ($name !== '') $sponsors[] = $name . ' (primary)';
        }

        // Map federal chamber to the dropdown's federal_* values.
        $fed_chamber = (strpos($type, 's') === 0) ? 'federal_senate' : 'federal_house';

        $result_data = [
            'bill_number'        => $bill['type'] . $bill['number'],
            'bill_type'          => $bill['type'],
            'bill_title'         => $bill['title'] ?? '',
            'jurisdiction'       => 'Federal',
            'chamber'            => $fed_chamber,
            'session_year'       => $congress_label . ' Congress',
            'status'             => kop_federal_status_from_action($bill['latestAction']['text'] ?? ''),
            'introduced_date'    => $bill['introducedDate'] ?? '',
            'last_action_date'   => $bill['latestAction']['actionDate'] ?? '',
            'last_action_text'   => $bill['latestAction']['text'] ?? '',
            'summary'            => $summary,
            'sponsors'           => $sponsors,
            'subject_tags'       => $subject_tags,
            'full_text_url'      => $full_text_url,
            'official_url'       => "https://www.congress.gov/bill/{$congress_label}-congress/" .
                                    (($type === 'hr') ? 'house-bill' : 'senate-bill') . "/{$num}",
            'position'           => 'unknown',
            'publication_status' => 'draft',
        ];

    } else {
        // --- OPENSTATES API v3 (State) ---
        if (empty($OPENSTATES_API_KEY)) {
            echo json_encode([
                'success' => false,
                'error'   => 'OpenStates API key is not configured in the environment.',
                'diagnostic' => kop_env_diagnostic('OPENSTATES_API_KEY'),
            ]);
            exit;
        }

        // OpenStates v3 expects identifiers like "SB 1130" with a space between
        // the chamber prefix and the number. Normalize whatever the user typed.
        if (preg_match('/([a-zA-Z]+)\s*(\d+)/', $bill_number, $idMatch)) {
            $identifier = strtoupper($idMatch[1]) . ' ' . $idMatch[2];
        } else {
            $identifier = strtoupper(trim($bill_number));
        }

        $query = http_build_query([
            'jurisdiction' => $jurisdiction,
            'identifier'   => $identifier,
            'sort'         => 'updated_desc',
        ]);
        // OpenStates v3 wants `include` repeated, not comma-joined.
        $query .= '&include=abstracts&include=other_titles&include=versions&include=sources&include=sponsorships';

        $url = "https://v3.openstates.org/bills?{$query}";

        $response = wp_remote_get($url, [
            'headers' => ['X-API-KEY' => $OPENSTATES_API_KEY]
        ]);

        if (is_wp_error($response)) throw new Exception($response->get_error_message());

        $http_code = wp_remote_retrieve_response_code($response);
        $raw_body  = wp_remote_retrieve_body($response);
        $body      = json_decode($raw_body, true);
        $bill      = $body['results'][0] ?? null;

        if (!$bill) {
            echo json_encode([
                'success'    => false,
                'error'      => "State bill not found in {$jurisdiction}",
                'diagnostic' => [
                    'queried_url'        => $url,
                    'identifier_sent'    => $identifier,
                    'jurisdiction_sent'  => $jurisdiction,
                    'http_status'        => $http_code,
                    'openstates_error'   => $body['detail'] ?? null,
                    'result_count'       => isset($body['results']) ? count($body['results']) : null,
                    'pagination'         => $body['pagination'] ?? null,
                    'raw_body_excerpt'   => substr($raw_body, 0, 500),
                ],
            ]);
            exit;
        }

        // Pull the latest version's first link as the full-text URL.
        $full_text_url = '';
        if (!empty($bill['versions']) && is_array($bill['versions'])) {
            $latest_version = end($bill['versions']);
            if (!empty($latest_version['links'][0]['url'])) {
                $full_text_url = $latest_version['links'][0]['url'];
            }
        }

        // Prefer the state's own bill page (first source) for `official_url`;
        // fall back to the OpenStates page.
        $official_url = $bill['sources'][0]['url'] ?? ($bill['openstates_url'] ?? '');

        // Map sponsorships -> array of "Name (role)" strings, primaries first.
        $sponsors = [];
        if (!empty($bill['sponsorships']) && is_array($bill['sponsorships'])) {
            usort($bill['sponsorships'], function($a, $b) {
                return (int)!empty($b['primary']) - (int)!empty($a['primary']);
            });
            foreach ($bill['sponsorships'] as $s) {
                $name = trim($s['name'] ?? '');
                if ($name === '') continue;
                $role = strtolower($s['classification'] ?? '');
                if (!empty($s['primary'])) $role = 'primary';
                $sponsors[] = $role ? "{$name} ({$role})" : $name;
            }
        }

        // Extract the bill_type prefix from the identifier (e.g., "SB" from "SB 1190").
        $bill_type = '';
        if (preg_match('/^([A-Za-z]+)/', $bill['identifier'] ?? '', $tm)) {
            $bill_type = strtoupper($tm[1]);
        }

        // Chamber: prefer the chamber name (lets us distinguish Assembly from House).
        $org_class = $bill['from_organization']['classification'] ?? ($bill['org_classification'] ?? '');
        $org_name  = $bill['from_organization']['name'] ?? '';
        if ($org_class === 'upper') {
            $chamber = 'senate';
        } elseif (stripos($org_name, 'assembly') !== false) {
            $chamber = 'assembly';
        } elseif ($org_class === 'lower') {
            $chamber = 'house';
        } else {
            $chamber = 'unknown';
        }

        // Subject tags from OpenStates' `subject` array (inline, no include needed).
        $subject_tags = [];
        if (!empty($bill['subject']) && is_array($bill['subject'])) {
            foreach ($bill['subject'] as $s) {
                if (is_string($s) && $s !== '') $subject_tags[] = $s;
            }
        }

        $result_data = [
            'bill_number'        => $bill['identifier'],
            'bill_type'          => $bill_type,
            'bill_title'         => $bill['title'],
            'jurisdiction'       => $jurisdiction,
            'chamber'            => $chamber,
            'session_year'       => $bill['session'] ?? '',
            'status'             => kop_state_status_from_classifications($bill['latest_action_classification'] ?? []),
            'introduced_date'    => substr($bill['first_action_date'] ?? '', 0, 10),
            'last_action_date'   => substr($bill['latest_action_date'] ?? '', 0, 10),
            'last_action_text'   => $bill['latest_action_description'] ?? '',
            'summary'            => $bill['abstracts'][0]['abstract'] ?? '',
            'sponsors'           => $sponsors,
            'subject_tags'       => $subject_tags,
            'full_text_url'      => $full_text_url,
            'official_url'       => $official_url,
            'position'           => 'unknown',
            'publication_status' => 'draft',
        ];
    }

    echo json_encode([
        'success' => true,
        'data'    => $result_data
    ]);

} catch (Exception $e) {
    echo json_encode([
        'success' => false, 
        'error' => $e->getMessage()
    ]);
}