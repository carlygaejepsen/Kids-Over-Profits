<?php
/**
 * Re-save every facilities_v2 document through kop_facility_normalize, and
 * every kop_operators block through kop_facility_operator_block, so
 * each one takes the standard shapes in docs/FACILITY-SCHEMA.md ("Standard
 * shapes"): staff entries {name, role, pastJobs}, past TTI jobs {role,
 * organization, employer}, profile links as URLs, the full resources
 * checklist, gender and type vocabularies, the operator block's fixed keys,
 * and additional locations parsed from their address.
 *
 * Saving goes through kop_facility_save, which validates the document, skips
 * an unchanged one and rebuilds location memberships when the location
 * changed. Runs only with the v2 write switch on, under the v2 write lock.
 *
 * Safety: requires ?run=1 from a logged-in admin, or CLI. A dry run is the
 * default; ?apply=1 (CLI "apply") writes.
 *
 *   Preview: /api/standardize-facilities.php?run=1
 *   Apply:   /api/standardize-facilities.php?run=1&apply=1
 *   CLI:     php api/standardize-facilities.php [apply]
 */

header('Content-Type: application/json');

require_once __DIR__ . '/config.php';
require_once dirname(__DIR__) . '/inc/facility-v2-writer.php';

$is_cli = php_sapi_name() === 'cli';
$args   = $is_cli ? array_slice($argv ?? array(), 1) : array();
$apply  = $is_cli ? in_array('apply', $args, true) : !empty($_GET['apply']);
$run_ok = $is_cli || (($_GET['run'] ?? null) === '1');

if (!$is_cli) {
    if (!defined('ABSPATH')) {
        $current = __DIR__;
        for ($i = 0; $i < 6; $i++) {
            $current = dirname($current);
            if (file_exists($current . '/wp-load.php')) { require_once $current . '/wp-load.php'; break; }
        }
    }
    $is_admin = function_exists('current_user_can') && current_user_can('manage_options');
    if (!$run_ok || !$is_admin) {
        http_response_code(403);
        echo json_encode(array('success' => false, 'error' => 'Requires ?run=1 and an admin session, or CLI execution.'));
        exit;
    }
}

if (!isset($pdo) || !($pdo instanceof PDO)) {
    http_response_code(500);
    echo json_encode(array('success' => false, 'error' => 'Database connection unavailable.'));
    exit;
}

/** Changed paths between two documents, list indices folded to []. */
function kop_sf_changed_paths($a, $b, $path = '', &$out = array()) {
    if (is_array($a) && is_array($b)) {
        foreach (array_unique(array_merge(array_keys($a), array_keys($b))) as $k) {
            $p = is_int($k) ? $path . '[]' : ($path === '' ? (string)$k : $path . '.' . $k);
            kop_sf_changed_paths($a[$k] ?? null, $b[$k] ?? null, $p, $out);
        }
        return $out;
    }
    if ($a !== $b) $out[$path] = true;
    return $out;
}

try {
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $prefix = kop_v2_detect_prefix($pdo);
    if (!kop_v2_writes_active($pdo, $prefix)) {
        throw new RuntimeException('The v2 write switch is off; the ten-minute sync would undo this. Turn it on first.');
    }
    $t = kop_migration_tables($prefix);
    $opts = array('pdo' => $pdo, 'prefix' => $prefix);

    $out = array(
        'success' => true, 'applied' => $apply,
        'totals'  => array('facilities' => 0, 'would_change' => 0, 'updated' => 0, 'unchanged' => 0, 'errors' => 0,
                           'operators' => 0, 'operators_changed' => 0, 'operators_updated' => 0),
        'paths'   => array(), 'samples' => array(), 'errors' => array(),
    );

    $run = function () use ($pdo, $t, $opts, $apply, &$out) {
        $rows = $pdo->query("SELECT id, unique_name, json_data FROM `{$t['facilities']}` ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            $out['totals']['facilities']++;
            $stored = json_decode((string)$r['json_data'], true);
            if (!is_array($stored) || !isset($stored['identification'], $stored['location'])) continue;
            $doc = kop_facility_normalize($stored, array('facility_id' => (int)$r['id'], 'unique_name' => $r['unique_name']));
            $doc['provenance'] = array_merge($stored['provenance'] ?? array(), array('sourceOperator' => $doc['provenance']['sourceOperator']));
            $doc['identification']['nameKey'] = kop_facility_name_key($doc['identification']['name']);
            if (kop_facility_same_document($stored, $doc)) {
                $out['totals']['unchanged']++;
                continue;
            }
            $out['totals']['would_change']++;
            foreach (array_keys(kop_sf_changed_paths($stored, $doc)) as $p) {
                $out['paths'][$p] = ($out['paths'][$p] ?? 0) + 1;
                if (count($out['samples'][$p] ?? array()) < 2) $out['samples'][$p][] = (int)$r['id'];
            }
            $errors = array_filter(kop_facility_validate($doc), function ($v) { return $v['severity'] === 'error'; });
            if ($errors) {
                $out['totals']['errors']++;
                $out['errors'][] = array('id' => (int)$r['id'], 'errors' => array_values($errors));
                continue;
            }
            if ($apply) {
                kop_facility_save($doc, $opts, $status);
                if ($status === 'updated') $out['totals']['updated']++;
            }
        }
        // Operator rows: the block only; legacy_blocks and the join rows are
        // the form's own bookkeeping.
        foreach ($pdo->query("SELECT id, unique_name, name, json_data FROM `{$t['operators']}` ORDER BY id")->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out['totals']['operators']++;
            $stored = json_decode((string)$r['json_data'], true);
            if (!is_array($stored)) continue;
            $block = isset($stored['operator']) && is_array($stored['operator']) ? $stored['operator'] : array();
            // An empty block still gets the keys, with the name the row carries.
            $with_name = $block;
            if (kop_facility_str($with_name['name'] ?? '') === '') {
                $with_name['name'] = (string)($r['name'] !== '' ? $r['name'] : $r['unique_name']);
            }
            $new = kop_facility_operator_block($with_name) ?? array();
            if (kop_facility_same_document(array('o' => $block), array('o' => $new))) continue;
            $out['totals']['operators_changed']++;
            foreach (array_keys(kop_sf_changed_paths($block, $new)) as $p) {
                $key = 'operator.' . $p;
                $out['paths'][$key] = ($out['paths'][$key] ?? 0) + 1;
                if (count($out['samples'][$key] ?? array()) < 2) $out['samples'][$key][] = (int)$r['id'];
            }
            if ($apply) {
                $stored['operator'] = $new;
                $pdo->prepare("UPDATE `{$t['operators']}` SET json_data = ? WHERE id = ?")
                    ->execute(array(json_encode($stored, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), (int)$r['id']));
                $out['totals']['operators_updated']++;
            }
        }
        arsort($out['paths']);
    };

    if ($apply) {
        kop_v2_with_write_lock($pdo, $run);
    } else {
        $run();
    }
    echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(array('success' => false, 'error' => $e->getMessage()));
}
