<?php
/**
 * One-time migration: backfill operatingPeriod.startYear / endYear from a legacy
 * free-text operatingPeriod.yearsOfOperation, for facilities where the two year
 * pickers are empty. Makes the Opened/Closed pickers the source of truth so they
 * line up with the now-locked "Years of Operation" field in the data form.
 *
 * Safety:
 *   - Requires an authenticated WordPress administrator.
 *   - DRY RUN by default — returns a JSON report of what WOULD change.
 *   - Pass ?apply=1 to actually write.
 *
 * Usage:
 *   Dry run: https://kidsoverprofits.org/wp-content/themes/kadence-child/api/migrate-operating-years.php
 *   Apply:   https://kidsoverprofits.org/wp-content/themes/kadence-child/api/migrate-operating-years.php?apply=1
 *
 * Delete this file after the migration is done.
 */

require_once __DIR__ . '/config.php'; // boots WordPress (gives us $wpdb + auth)

header('Content-Type: application/json');

if (!function_exists('current_user_can') || !current_user_can('administrator')) {
    http_response_code(403);
    echo json_encode(array('error' => 'Administrator login required.'));
    exit;
}

global $wpdb;

/**
 * Parse a legacy "Years of Operation" string into startYear/endYear.
 * Mirror of window.kopParseYearsOfOperation in js/data-form-modules/ui-events.js.
 * Returns null when it can't parse confidently.
 */
function migrate_parse_years($text, $status) {
    $t = trim((string) $text);
    if ($t === '') return null;
    $norm = str_replace(array('–', '—'), '-', $t); // en/em dash -> hyphen
    preg_match_all('/\d{4}/', $norm, $m);
    $years = array_map('intval', $m[0]);
    if (count($years) > 2) return null; // multi-stint / complex -> leave for manual
    $open_ended = preg_match('/(present|current|now|ongoing|open)\s*$/i', $norm)
        || preg_match('/\d{4}\s*-\s*$/', $norm);
    if (count($years) === 2 && preg_match('/\d{4}\s*-\s*\d{4}/', $norm)) {
        return array('startYear' => $years[0], 'endYear' => $years[1]);
    }
    if (count($years) === 1) {
        if (preg_match('/^\s*-\s*\d{4}/', $norm)) return array('startYear' => null, 'endYear' => $years[0]);
        if ($open_ended) return array('startYear' => $years[0], 'endYear' => null);
        $closed = in_array(strtolower(trim((string) $status)), array('closed', 'shut down', 'shutdown', 'defunct'), true);
        return $closed
            ? array('startYear' => null, 'endYear' => $years[0])
            : array('startYear' => $years[0], 'endYear' => null);
    }
    return null;
}

$is_empty = function ($v) {
    return $v === null || trim((string) $v) === '';
};

$apply  = isset($_GET['apply']) && $_GET['apply'] === '1';
$tables = array('facilities_master', 'locations_master', 'referrers_master', 'transporters_master');

$report        = array();
$skipped       = array();
$rows_changed  = 0;
$facs_changed  = 0;

foreach ($tables as $table) {
    $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
    if ($exists !== $table) continue;

    $rows = $wpdb->get_results("SELECT id, unique_name, json_data FROM {$table}", ARRAY_A);
    if (!is_array($rows)) continue;

    foreach ($rows as $row) {
        $decoded = json_decode($row['json_data'], true);
        if (!is_array($decoded)) continue;

        // Locate the facilities array in place (handle the two common shapes).
        if (isset($decoded['facilities']) && is_array($decoded['facilities'])) {
            $facs = &$decoded['facilities'];
        } elseif (isset($decoded['data']['facilities']) && is_array($decoded['data']['facilities'])) {
            $facs = &$decoded['data']['facilities'];
        } else {
            continue;
        }

        $row_changed = false;

        foreach ($facs as &$f) {
            if (!is_array($f) || !isset($f['operatingPeriod']) || !is_array($f['operatingPeriod'])) continue;
            $op = &$f['operatingPeriod'];

            $yoo = trim((string) ($op['yearsOfOperation'] ?? ''));
            if ($yoo === '' || !$is_empty($op['startYear'] ?? null) || !$is_empty($op['endYear'] ?? null)) {
                unset($op);
                continue;
            }

            $name = $f['identification']['name'] ?? ($f['identification']['currentName'] ?? '');
            $parsed = migrate_parse_years($yoo, $op['status'] ?? '');
            if ($parsed === null) {
                $skipped[] = array('table' => $table, 'project' => $row['unique_name'], 'facility' => $name, 'yearsOfOperation' => $yoo);
                unset($op);
                continue;
            }

            if ($parsed['startYear'] !== null) $op['startYear'] = $parsed['startYear'];
            if ($parsed['endYear'] !== null)   $op['endYear']   = $parsed['endYear'];

            $report[] = array(
                'table'            => $table,
                'project'          => $row['unique_name'],
                'facility'         => $name,
                'yearsOfOperation' => $yoo,
                'startYear'        => $op['startYear'] ?? null,
                'endYear'          => $op['endYear'] ?? null,
            );
            $row_changed = true;
            $facs_changed++;
            unset($op);
        }
        unset($f);

        if ($row_changed) {
            $rows_changed++;
            if ($apply) {
                $wpdb->update(
                    $table,
                    array('json_data' => wp_json_encode($decoded)),
                    array('id' => $row['id']),
                    array('%s'),
                    array('%d')
                );
            }
        }

        unset($facs);
    }
}

echo json_encode(array(
    'mode'              => $apply ? 'APPLIED' : 'DRY RUN (no changes written — add ?apply=1 to write)',
    'rows_changed'      => $rows_changed,
    'facilities_changed'=> $facs_changed,
    'skipped_count'     => count($skipped),
    'changes'           => $report,
    'skipped'           => $skipped,
), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
