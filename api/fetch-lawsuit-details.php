<?php
/**
 * API - Fetch Lawsuit Details
 *
 * Looks up a docket on CourtListener (federal RECAP coverage) and returns a
 * payload shaped like the lawsuit form so the admin UI can auto-populate.
 *
 * Federal only. State cases are not supported via this endpoint; CourtListener's
 * state-court coverage is patchy, and parties/docket data are largely federal.
 * For state cases the admin should enter manually or use the complaint-upload
 * extractor.
 *
 * Query params:
 *   case_number  - required, e.g. "2:21-cv-00001"
 *   jurisdiction - required ("Federal" returns data; other values return a
 *                  "manual entry" message)
 *   court        - optional, free-text; used only to disambiguate when
 *                  CourtListener returns multiple matches
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

if (!function_exists('kop_resolve_secret')) {
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
}

$case_number  = trim((string)($_GET['case_number'] ?? ''));
$jurisdiction = trim((string)($_GET['jurisdiction'] ?? ''));
$court_hint   = trim((string)($_GET['court'] ?? ''));

if ($case_number === '' || $jurisdiction === '') {
    echo json_encode(['success' => false, 'error' => 'Missing required parameters (case_number, jurisdiction).']);
    exit;
}

if (strcasecmp($jurisdiction, 'Federal') !== 0) {
    echo json_encode([
        'success' => false,
        'error'   => "Auto-lookup currently supports Federal cases only. For {$jurisdiction} state-court cases, enter details manually or upload the complaint PDF for automatic extraction.",
    ]);
    exit;
}

$token = kop_resolve_secret('COURTLISTENER_API_KEY');
if ($token === '') {
    echo json_encode([
        'success' => false,
        'error'   => 'CourtListener API token is not configured. Add COURTLISTENER_API_KEY to your .env file. Free signup at https://www.courtlistener.com/help/api/rest/',
    ]);
    exit;
}

/**
 * Run an authenticated GET against the CourtListener v4 REST API and return the
 * decoded JSON body, or null on transport / HTTP failure (with the error
 * stashed in $err_out for the caller to surface).
 */
function kop_cl_get($url, $token, &$err_out = null) {
    $resp = wp_remote_get($url, [
        'headers' => ['Authorization' => 'Token ' . $token],
        'timeout' => 20,
    ]);
    if (is_wp_error($resp)) {
        $err_out = $resp->get_error_message();
        return null;
    }
    $code = wp_remote_retrieve_response_code($resp);
    $body = wp_remote_retrieve_body($resp);
    if ($code !== 200) {
        $err_out = "CourtListener returned HTTP {$code}: " . substr($body, 0, 300);
        return null;
    }
    $data = json_decode($body, true);
    if (!is_array($data)) {
        $err_out = 'CourtListener returned non-JSON response.';
        return null;
    }
    return $data;
}

/**
 * Map a CourtListener party_types entry to one of our two buckets.
 * Plaintiff-side and defense-side terms vary across criminal, civil, appellate,
 * and bankruptcy dockets, so we normalize aggressively.
 */
function kop_cl_classify_party($party_type_name) {
    $t = strtolower($party_type_name);
    $plaintiff_terms = ['plaintiff', 'petitioner', 'appellant', 'movant', 'claimant', 'complainant', 'debtor'];
    $defendant_terms = ['defendant', 'respondent', 'appellee', 'creditor'];
    foreach ($plaintiff_terms as $term) {
        if (strpos($t, $term) !== false) return 'plaintiff';
    }
    foreach ($defendant_terms as $term) {
        if (strpos($t, $term) !== false) return 'defendant';
    }
    return 'other';
}

try {
    // --- Step 1: find the docket -------------------------------------------
    // CourtListener accepts `docket_number` as a substring filter. We don't try
    // to translate the user's free-text court name into a CL court_id slug
    // because the mapping is brittle (90+ federal courts, several aliases each);
    // the docket_number alone is usually selective enough.
    $search_url = 'https://www.courtlistener.com/api/rest/v4/dockets/?' . http_build_query([
        'docket_number' => $case_number,
        'page_size'     => 20,
    ]);

    $err = null;
    $search = kop_cl_get($search_url, $token, $err);
    if ($search === null) {
        echo json_encode(['success' => false, 'error' => $err, 'diagnostic' => ['queried_url' => $search_url]]);
        exit;
    }

    $results = $search['results'] ?? [];
    if (empty($results)) {
        echo json_encode([
            'success' => false,
            'error'   => "No federal docket found matching case number '{$case_number}'. CourtListener mainly indexes federal RECAP dockets — district court bankruptcy or some sealed cases may be missing.",
            'diagnostic' => ['queried_url' => $search_url, 'result_count' => 0],
        ]);
        exit;
    }

    // If the user provided a court hint, prefer a result whose `court_id` slug
    // or court name substring matches. Otherwise take the first result.
    $docket = $results[0];
    if ($court_hint !== '' && count($results) > 1) {
        $hint_lower = strtolower($court_hint);
        foreach ($results as $candidate) {
            $candidate_court_id = strtolower((string)($candidate['court_id'] ?? ''));
            if ($candidate_court_id !== '' && strpos($hint_lower, $candidate_court_id) !== false) {
                $docket = $candidate;
                break;
            }
        }
    }

    // --- Step 2: resolve the court full name -------------------------------
    // CourtListener returns `court` as a URL ("…/courts/utd/") and `court_id`
    // as the slug. Hit /courts/<id>/ to get the full name, falling back to
    // whatever the user typed if the lookup fails.
    $court_full_name = $court_hint;
    if (!empty($docket['court_id'])) {
        $court_url = 'https://www.courtlistener.com/api/rest/v4/courts/' . urlencode($docket['court_id']) . '/';
        $court_data = kop_cl_get($court_url, $token, $err);
        if (is_array($court_data) && !empty($court_data['full_name'])) {
            $court_full_name = $court_data['full_name'];
        }
    }

    // --- Step 3: fetch parties ---------------------------------------------
    // The parties endpoint is paginated; for the typical case (< 50 parties)
    // a single page is enough. We don't follow `next` here to keep the
    // round-trip count down; very large MDL cases will be truncated.
    $plaintiffs = [];
    $defendants = [];
    if (!empty($docket['id'])) {
        $parties_url = 'https://www.courtlistener.com/api/rest/v4/parties/?' . http_build_query([
            'docket'    => $docket['id'],
            'page_size' => 100,
        ]);
        $parties_data = kop_cl_get($parties_url, $token, $err);
        if (is_array($parties_data) && !empty($parties_data['results'])) {
            foreach ($parties_data['results'] as $party) {
                $name = trim((string)($party['name'] ?? ''));
                if ($name === '') continue;
                $bucket = 'other';
                foreach ($party['party_types'] ?? [] as $pt) {
                    $candidate = kop_cl_classify_party($pt['name'] ?? '');
                    if ($candidate !== 'other') { $bucket = $candidate; break; }
                }
                if ($bucket === 'plaintiff') {
                    $plaintiffs[] = $name;
                } elseif ($bucket === 'defendant') {
                    $defendants[] = $name;
                }
            }
            $plaintiffs = array_values(array_unique($plaintiffs));
            $defendants = array_values(array_unique($defendants));
        }
    }

    // --- Step 4: assemble the response -------------------------------------
    $absolute_url = '';
    if (!empty($docket['absolute_url'])) {
        $absolute_url = 'https://www.courtlistener.com' . $docket['absolute_url'];
    }

    $result_data = [
        'case_name'          => $docket['case_name'] ?? ($docket['case_name_short'] ?? ''),
        'case_number'        => $docket['docket_number'] ?? $case_number,
        'court'              => $court_full_name,
        'jurisdiction'       => 'Federal',
        'filing_date'        => $docket['date_filed'] ?? '',
        'status'             => !empty($docket['date_terminated']) ? 'closed' : 'in_progress',
        'plaintiffs'         => $plaintiffs,
        'defendants'         => $defendants,
        'summary'            => trim((string)($docket['cause'] ?? '')),  // "cause" is the IRS-style cause-of-action string CL exposes
        'source_urls'        => $absolute_url ? [$absolute_url] : [],
        'document_urls'      => [],
        'publication_status' => 'draft',
    ];

    echo json_encode(['success' => true, 'data' => $result_data]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
