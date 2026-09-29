<?php
/**
 * Put the curated match aliases back on the v2 facility records.
 *
 * matchAliases ("Camp SAYLA" and "SAYLA" for Southeast Alabama Youth
 * Leadership Academy, "Sununu" for the John H. Sununu Youth Services Center)
 * are the names articles use that should resolve to a record. On a legacy row
 * they sat beside the facility, and the v2 migration synced its last copy
 * (2026-09-17 19:13 UTC) before the code that carries them over landed
 * (631a4a7a, 19:31 UTC), so no v2 document has them and the news linker,
 * the closure reports and the new-facility scan stopped recognising those
 * names.
 *
 * Sources, all read-only: the frozen facilities_master and locations_master
 * rows (data.matchAliases beside a __facility_ref facility, and
 * matchAliases / identification.matchAliases on nested facilities, by
 * facility_id), and api/match-aliases-seed.json. Each alias goes into the
 * v2 document's legacy.matchAliases, where kop_facility_to_legacy() hands it
 * to the name index (api/facility-aliases.php). Only records whose id still
 * exists in facilities_v2 are touched, and an alias already present is not
 * added twice, so running it again changes nothing.
 *
 * CLI only:
 *   php api/restore-match-aliases.php          dry run: list what would be added
 *   php api/restore-match-aliases.php apply
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}
$apply = in_array('apply', $argv, true);

$current = __DIR__;
for ($i = 0; $i < 6; $i++) {
    $current = dirname($current);
    if (file_exists($current . '/wp-load.php')) {
        require_once $current . '/wp-load.php';
        break;
    }
}
if (!defined('ABSPATH')) {
    fwrite(STDERR, "wp-load.php not found\n");
    exit(2);
}
require_once get_stylesheet_directory() . '/inc/facility-v2-writer.php';
require_once __DIR__ . '/facility-aliases.php';
global $wpdb;
$pdo = kop_seed_pdo();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
if (!kop_v2_writes_active($pdo, $wpdb->prefix)) {
    fwrite(STDERR, "Saves are not on facilities_v2; the legacy rows still hold the aliases.\n");
    exit(2);
}
$opts = array('pdo' => $pdo, 'prefix' => $wpdb->prefix);

/**
 * The document with its matchAliases set, rebuilt through the legacy shape
 * the way the deploy seeds write: most stored documents are schema 2, which
 * kop_facility_save() refuses as they are. A top-level key normalize does not
 * know lands in legacy, where kop_facility_to_legacy() gives it back.
 */
function kop_restore_aliases_doc(array $stored, array $aliases) {
    $legacy = kop_facility_to_legacy($stored['doc']);
    $legacy['matchAliases'] = $aliases;
    $out = kop_facility_normalize($legacy, array('facility_id' => $stored['id'], 'unique_name' => $stored['unique_name']));
    $out['provenance'] = $stored['doc']['provenance'];
    return $out;
}

// facility id => [alias, ...]
$found = array();
$add = static function ($id, $list) use (&$found) {
    $id = (int) $id;
    if ($id <= 0 || !is_array($list)) return;
    foreach ($list as $name) {
        if (is_string($name) && trim($name) !== '') $found[$id][] = trim($name);
    }
};
$nested = static function (array $record) use ($add) {
    $list = $record['facilities'] ?? ($record['data']['facilities'] ?? null);
    foreach (is_array($list) ? $list : array() as $f) {
        if (!is_array($f) || empty($f['facility_id'])) continue;
        $add($f['facility_id'], $f['matchAliases'] ?? null);
        $add($f['facility_id'], $f['identification']['matchAliases'] ?? null);
    }
};
foreach ($pdo->query("SELECT id, json_data FROM facilities_master WHERE json_data LIKE '%matchAliases%'") as $r) {
    $d = json_decode((string) $r['json_data'], true);
    if (!is_array($d)) continue;
    if (!empty($d['__facility_ref']) || isset($d['data']['facility'])) {
        $add($r['id'], $d['data']['matchAliases'] ?? ($d['matchAliases'] ?? null));
        $add($r['id'], $d['data']['facility']['identification']['matchAliases'] ?? null);
    }
    $nested($d);
}
foreach ($pdo->query("SELECT id, json_data FROM locations_master WHERE json_data LIKE '%matchAliases%'") as $r) {
    $d = json_decode((string) $r['json_data'], true);
    if (is_array($d)) $nested($d);
}
$seed = json_decode((string) @file_get_contents(__DIR__ . '/match-aliases-seed.json'), true);
foreach ((array) ($seed['aliases'] ?? array()) as $e) {
    $add($e['id'] ?? 0, $e['names'] ?? null);
}

$added_total = 0;
$records = 0;
foreach ($found as $id => $aliases) {
    $stored = kop_facility_load($id, $opts);
    if (!$stored) {
        echo "  #$id: not in facilities_v2 (an operator project or a merged record), skipped: " . implode(', ', array_unique($aliases)) . "\n";
        continue;
    }
    $doc = $stored['doc'];
    $have = is_array($doc['legacy']['matchAliases'] ?? null) ? $doc['legacy']['matchAliases'] : array();
    $keys = array(kop_normalize_name_key($stored['unique_name']) => true, kop_normalize_name_key($doc['identification']['name'] ?? '') => true);
    foreach ($have as $h) $keys[kop_normalize_name_key($h)] = true;
    $new = array();
    foreach ($aliases as $a) {
        $k = kop_normalize_name_key($a);
        if ($k === '' || isset($keys[$k])) continue;
        $keys[$k] = true;
        $new[] = $a;
    }
    if (!$new) continue;
    $records++;
    $added_total += count($new);
    echo "  #$id {$stored['unique_name']}: + " . implode(', ', $new) . "\n";
    if ($apply) {
        kop_v2_with_write_lock($pdo, function () use ($opts, $stored, $have, $new) {
            kop_facility_save(kop_restore_aliases_doc($stored, array_values(array_merge($have, $new))), $opts);
        });
    }
}
printf("\n%s: %d aliases on %d records.\n", $apply ? 'Restored' : 'Dry run, would restore', $added_total, $records);
