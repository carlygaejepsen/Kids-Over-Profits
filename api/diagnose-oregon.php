<?php
/**
 * One-time diagnostic: dump the RAW SQL the state page reads for Oregon, and what
 * the live collector produces for Mount Bachelor. Admin only. Delete after use.
 *
 * Visit: https://kidsoverprofits.org/wp-content/themes/child/api/diagnose-oregon.php
 */

require_once __DIR__ . '/config.php';
header('Content-Type: text/plain');

if (!function_exists('current_user_can') || !current_user_can('administrator')) {
    http_response_code(403);
    echo "Administrator login required.\n";
    exit;
}

global $wpdb;
$needle = 'mount bachelor';

function dump_facility_ops($json_data, $needle) {
    $data = json_decode($json_data, true);
    if (!is_array($data)) { echo "    (json_data did not decode)\n"; return; }
    $facs = array();
    if (isset($data['facilities']) && is_array($data['facilities'])) $facs = $data['facilities'];
    elseif (isset($data['data']['facilities']) && is_array($data['data']['facilities'])) $facs = $data['data']['facilities'];
    echo "    facilities in row: " . count($facs) . "\n";
    foreach ($facs as $i => $f) {
        if (!is_array($f)) continue;
        $nm = $f['identification']['name'] ?? ($f['identification']['currentName'] ?? ($f['name'] ?? ''));
        if (stripos($nm, $needle) !== false) {
            echo "    [$i] \"$nm\"  operatingPeriod=" . json_encode($f['operatingPeriod'] ?? null) . "\n";
        }
    }
}

// --- 1. Every locations_master row named OREGON ---
echo "=== locations_master rows WHERE UPPER(unique_name)='OREGON' ===\n";
$rows = $wpdb->get_results("SELECT id, unique_name, updated_at, LENGTH(json_data) AS len FROM locations_master WHERE UPPER(unique_name)='OREGON'", ARRAY_A);
echo "count: " . count($rows) . "\n";
foreach ($rows as $r) {
    echo "  id={$r['id']} unique_name={$r['unique_name']} updated_at={$r['updated_at']} json_len={$r['len']}\n";
}
echo "\n";

// --- 2. The EXACT row the collector reads (same query, LIMIT 1) ---
echo "=== row the state collector actually reads (LIMIT 1, no ORDER BY) ===\n";
$row = $wpdb->get_row($wpdb->prepare("SELECT id, json_data FROM locations_master WHERE UPPER(unique_name) = %s LIMIT 1", 'OREGON'), ARRAY_A);
if ($row) {
    echo "  -> picked id={$row['id']}\n";
    dump_facility_ops($row['json_data'], $needle);
} else {
    echo "  (no OREGON row)\n";
}
echo "\n";

// --- 3. Any facilities_master row that also has Mount Bachelor ---
echo "=== facilities_master rows containing Mount Bachelor ===\n";
$frows = $wpdb->get_results("SELECT id, unique_name, json_data FROM facilities_master WHERE json_data LIKE '%Mount Bachelor%'", ARRAY_A);
echo "count: " . count($frows) . "\n";
foreach ($frows as $r) {
    echo "  facilities_master id={$r['id']} unique_name={$r['unique_name']}\n";
    dump_facility_ops($r['json_data'], $needle);
}
echo "\n";

// --- 4. What the live functions produce ---
echo "=== live function output for Oregon / Mount Bachelor ===\n";
if (function_exists('kop_state_collect_programs')) {
    $progs = kop_state_collect_programs('Oregon');
    foreach ($progs as $p) {
        $nm = $p['facility_name'] ?: $p['project_name'];
        if (stripos($nm, $needle) !== false) {
            echo "  collect_programs: name=\"$nm\" project={$p['project_name']} operating_period=" . json_encode($p['operating_period']) . "\n";
        }
    }
}
if (function_exists('kop_state_collect_facilities')) {
    $res = $wpdb->flush(); // no-op safety
    $facs = kop_state_collect_facilities('Oregon');
    $all = array_merge($facs['active'] ?? array(), $facs['closed'] ?? array());
    foreach ($all as $f) {
        if (stripos($f['name'] ?? '', $needle) !== false) {
            echo "  collect_facilities (FINAL): name=\"{$f['name']}\" status={$f['status']} operating_period=" . json_encode($f['operating_period']) . "\n";
        }
    }
}
echo "\nDONE.\n";
