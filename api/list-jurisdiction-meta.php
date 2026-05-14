<?php
/**
 * API - List Jurisdiction Meta
 *
 * Returns the legislative sessions and allowed bill-type prefixes for a
 * jurisdiction so the admin form can build per-state dropdowns.
 *
 * GET ?jurisdiction=California&level=state
 * GET ?jurisdiction=Federal&level=federal
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

$OPENSTATES_API_KEY = getenv('OPENSTATES_API_KEY')
    ?: ($_ENV['OPENSTATES_API_KEY'] ?? '')
    ?: (defined('OPENSTATES_API_KEY') ? OPENSTATES_API_KEY : '');

$jurisdiction = trim($_GET['jurisdiction'] ?? '');
$level        = strtolower(trim($_GET['level'] ?? 'state'));

if ($jurisdiction === '') {
    echo json_encode(['success' => false, 'error' => 'Missing jurisdiction.']);
    exit;
}

// US state name -> 2-letter postal code, for building OpenStates OCD IDs.
$state_to_code = [
    'Alabama' => 'al', 'Alaska' => 'ak', 'Arizona' => 'az', 'Arkansas' => 'ar',
    'California' => 'ca', 'Colorado' => 'co', 'Connecticut' => 'ct', 'Delaware' => 'de',
    'Florida' => 'fl', 'Georgia' => 'ga', 'Hawaii' => 'hi', 'Idaho' => 'id',
    'Illinois' => 'il', 'Indiana' => 'in', 'Iowa' => 'ia', 'Kansas' => 'ks',
    'Kentucky' => 'ky', 'Louisiana' => 'la', 'Maine' => 'me', 'Maryland' => 'md',
    'Massachusetts' => 'ma', 'Michigan' => 'mi', 'Minnesota' => 'mn', 'Mississippi' => 'ms',
    'Missouri' => 'mo', 'Montana' => 'mt', 'Nebraska' => 'ne', 'Nevada' => 'nv',
    'New Hampshire' => 'nh', 'New Jersey' => 'nj', 'New Mexico' => 'nm', 'New York' => 'ny',
    'North Carolina' => 'nc', 'North Dakota' => 'nd', 'Ohio' => 'oh', 'Oklahoma' => 'ok',
    'Oregon' => 'or', 'Pennsylvania' => 'pa', 'Rhode Island' => 'ri', 'South Carolina' => 'sc',
    'South Dakota' => 'sd', 'Tennessee' => 'tn', 'Texas' => 'tx', 'Utah' => 'ut',
    'Vermont' => 'vt', 'Virginia' => 'va', 'Washington' => 'wa', 'West Virginia' => 'wv',
    'Wisconsin' => 'wi', 'Wyoming' => 'wy',
];

// Bill-type prefixes per state. Default covers most states; overrides for states
// that use distinctive naming (CA's AB, NE's unicameral LB, NY/MA letter-only, etc.).
$default_bill_types = ['HB', 'SB', 'HCR', 'SCR', 'HJR', 'SJR', 'HR', 'SR'];
$bill_types_by_state = [
    'California'    => ['AB', 'SB', 'ACA', 'SCA', 'ACR', 'SCR', 'AJR', 'SJR', 'HR', 'SR'],
    'Wisconsin'     => ['AB', 'SB', 'AJR', 'SJR', 'AR', 'SR'],
    'Nebraska'      => ['LB', 'LR', 'LRCA'],
    'Massachusetts' => ['H', 'S', 'HD', 'SD'],
    'New York'      => ['A', 'S'],
    'New Jersey'    => ['A', 'S', 'AR', 'SR', 'AJR', 'SJR', 'ACR', 'SCR'],
    'Maryland'      => ['HB', 'SB', 'HJ', 'SJ'],
    'Florida'       => ['HB', 'SB', 'HM', 'SM', 'HJR', 'SJR', 'HCR', 'SCR'],
    'Texas'         => ['HB', 'SB', 'HCR', 'SCR', 'HJR', 'SJR', 'HR', 'SR'],
];
$federal_bill_types = ['HR', 'S', 'HJRes', 'SJRes', 'HConRes', 'SConRes', 'HRes', 'SRes'];

try {
    if ($level === 'federal') {
        // Build a list of the last 5 Congresses (current first).
        $year     = (int) date('Y');
        $start    = ($year % 2 === 0) ? $year - 1 : $year;
        $current  = (int)(($start - 1789) / 2) + 1;

        $sessions = [];
        for ($i = 0; $i < 5; $i++) {
            $cn   = $current - $i;
            $from = 1789 + 2 * ($cn - 1);
            $to   = $from + 1;
            $sessions[] = [
                'identifier' => (string) $cn,
                'name'       => "{$cn}th Congress ({$from}-{$to})",
            ];
        }

        echo json_encode([
            'success'    => true,
            'sessions'   => $sessions,
            'bill_types' => $federal_bill_types,
        ]);
        exit;
    }

    // --- State ---
    if (empty($OPENSTATES_API_KEY)) {
        throw new Exception('OpenStates API key is not configured.');
    }
    if (empty($state_to_code[$jurisdiction])) {
        throw new Exception("Unknown jurisdiction: {$jurisdiction}");
    }

    $code   = $state_to_code[$jurisdiction];
    $ocd_id = "ocd-jurisdiction/country:us/state:{$code}/government";
    $url    = "https://v3.openstates.org/jurisdictions/" . rawurlencode($ocd_id)
              . "?include=legislative_sessions";

    $response = wp_remote_get($url, [
        'headers' => ['X-API-KEY' => $OPENSTATES_API_KEY],
    ]);
    if (is_wp_error($response)) throw new Exception($response->get_error_message());

    $http_code = wp_remote_retrieve_response_code($response);
    $body      = json_decode(wp_remote_retrieve_body($response), true);

    if ($http_code !== 200 || empty($body['legislative_sessions'])) {
        echo json_encode([
            'success'    => false,
            'error'      => 'Could not load sessions for ' . $jurisdiction,
            'http_status' => $http_code,
            'bill_types' => $bill_types_by_state[$jurisdiction] ?? $default_bill_types,
        ]);
        exit;
    }

    // OpenStates returns sessions oldest-first; reverse so the current session is on top.
    $sessions = [];
    foreach (array_reverse($body['legislative_sessions']) as $s) {
        $sessions[] = [
            'identifier' => $s['identifier'] ?? '',
            'name'       => $s['name'] ?? ($s['identifier'] ?? ''),
            'start_date' => $s['start_date'] ?? null,
            'end_date'   => $s['end_date'] ?? null,
        ];
    }

    echo json_encode([
        'success'    => true,
        'sessions'   => $sessions,
        'bill_types' => $bill_types_by_state[$jurisdiction] ?? $default_bill_types,
    ]);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error'   => $e->getMessage(),
    ]);
}
