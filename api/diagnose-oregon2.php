<?php
/**
 * Deep trace: dump the REAL append_program operating-period source from the server,
 * and replicate the Source-1 dedup+build loop for "Mount Bachelor" with every
 * intermediate value, so we can see exactly where "2009" comes from. Admin only.
 *
 * Visit: https://kidsoverprofits.org/wp-content/themes/child/api/diagnose-oregon2.php
 */

require_once __DIR__ . '/config.php';
header('Content-Type: text/plain');

if (!function_exists('current_user_can') || !current_user_can('administrator')) {
    http_response_code(403);
    echo "Administrator login required.\n";
    exit;
}

global $wpdb;

// --- 1. The REAL server source for the operating-period + $programs[] build ---
$file = get_stylesheet_directory() . '/inc/rest-api.php';
$lines = file($file);
echo "=== server rest-api.php lines 2040-2099 ===\n";
for ($i = 2039; $i < 2099 && $i < count($lines); $i++) {
    echo ($i + 1) . ": " . $lines[$i];
}
echo "\n";

// --- 2. Replicate the Source-1 loop exactly, logging each Bachelor entry ---
echo "=== replicate Source-1 dedup + operating build for 'bachelor' ===\n";
$row = $wpdb->get_row($wpdb->prepare("SELECT json_data FROM locations_master WHERE UPPER(unique_name) = %s LIMIT 1", 'OREGON'), ARRAY_A);
$data = kop_normalize_project_payload($row['json_data']);
$facs = (is_array($data) && isset($data['facilities']) && is_array($data['facilities'])) ? $data['facilities'] : array();
echo "facilities after normalize: " . count($facs) . "\n";

$seen = array();
foreach ($facs as $i => $f) {
    if (!is_array($f)) continue;
    $ident = isset($f['identification']) && is_array($f['identification']) ? $f['identification'] : array();
    $name = $ident['name'] ?? ($ident['currentName'] ?? '');
    if (stripos($name, 'bachelor') === false) continue;

    $key = function_exists('kop_normalize_facility_name') ? kop_normalize_facility_name($name) : strtolower(trim($name));
    $would_skip = ($key !== '' && isset($seen[$key]));

    $op = (isset($f['operatingPeriod']) && is_array($f['operatingPeriod'])) ? $f['operatingPeriod'] : array();
    $yoo = trim((string)($op['yearsOfOperation'] ?? ''));
    $sy  = trim((string)($op['startYear'] ?? ''));
    $ey  = trim((string)($op['endYear'] ?? ''));

    // Exact replication of lines 2043-2050
    $r = $yoo;
    if ($r === '') {
        if ($sy !== '' && $ey !== '') $r = "$sy–$ey";
        elseif ($sy !== '')           $r = $sy;
        elseif ($ey !== '')           $r = "–$ey";
    }

    echo sprintf("[%d] name=%s | key=%s | would_skip=%s | yoo='%s' sy='%s' ey='%s' => '%s'\n",
        $i, $name, $key, $would_skip ? 'YES' : 'no', $yoo, $sy, $ey, $r);

    if (!$would_skip && $key !== '') $seen[$key] = true;
}

echo "\n=== real collect_programs output (for comparison) ===\n";
if (function_exists('kop_state_collect_programs')) {
    foreach (kop_state_collect_programs('Oregon') as $p) {
        $nm = $p['facility_name'] ?: $p['project_name'];
        if (stripos($nm, 'bachelor') !== false) {
            echo "  name=$nm operating_period=" . json_encode($p['operating_period']) . "\n";
        }
    }
}
echo "\nDONE.\n";
