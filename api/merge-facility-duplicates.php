<?php
/**
 * Merge v2 facilities that are the same place under two spellings.
 *
 * The migration keyed facilities on the raw name and city, while the store's
 * resolver (kop_facility_resolve_identity) keys on the normalized name key.
 * So facilities_v2 still holds pairs such as "Refuge Girls Academy" /
 * "The Refuge Girls Academy" and "Eagle Springs Teen Boy's Center" with and
 * without the apostrophe: one copy synced from an operator project (status
 * Unknown, linked to the operator), one imported from the state page (status
 * Open, with the address). Any form save of either would already resolve to
 * whichever row the resolver finds first.
 *
 * Candidates: rows sharing name_key, state and city key. Rows that differ by
 * city (the multi-site licensees such as New Hope of Arizona) never qualify.
 * Pass ?pairs=keep:drop,keep:drop to merge explicit ids instead.
 *
 * For each group the survivor is the copy whose name has no replacement
 * character, then the one with more news and lawsuit links, then a known
 * status over Unknown, then the larger document, then the lower id. The other
 * copies are folded in: empty scalars fill (Unknown counts as empty for the
 * status), lists union, maps merge recursively, and the dropped copies are
 * recorded under legacy.mergedFacilities. The merged document goes through
 * kop_facility_normalize and kop_facility_validate; a group with an error
 * severity violation is reported and skipped. Operator memberships, location
 * memberships, news and lawsuit links, identity rows and wiki submission links
 * move to the survivor, then the dropped rows are deleted.
 *
 * Runs only once the v2 write switch is on: before that the ten-minute sync
 * rebuilds facilities_v2 from the legacy tables and would put the rows back.
 *
 * Safety: requires ?run=1 from a logged-in admin, or CLI. ?dry=1 (CLI "dry")
 * previews without writing. Writes happen under the v2 write lock in one
 * transaction.
 *
 *   Preview: /api/merge-facility-duplicates.php?run=1&dry=1
 *   Apply:   /api/merge-facility-duplicates.php?run=1
 *   Explicit: /api/merge-facility-duplicates.php?run=1&pairs=13358:13287
 *   CLI:     php api/merge-facility-duplicates.php [dry] [keep:drop ...]
 */

require_once dirname(__DIR__) . '/inc/facility-store.php';

function kop_mfd_is_empty($value) {
    return $value === null || $value === '' || $value === array() || $value === false;
}

function kop_mfd_is_list($value) {
    if (!is_array($value)) return false;
    if ($value === array()) return true;
    return array_keys($value) === range(0, count($value) - 1);
}

/** Order-independent key for list de-duplication. */
function kop_mfd_value_key($value) {
    if (is_array($value)) {
        $canon = kop_facility_canonical($value);
        return 'j:' . mb_strtolower((string)json_encode($canon, JSON_UNESCAPED_UNICODE));
    }
    if (is_string($value)) return 's:' . mb_strtolower(preg_replace('/\s+/u', ' ', trim($value)));
    return 'v:' . json_encode($value);
}

function kop_mfd_union(array $lists) {
    $out = array();
    $seen = array();
    foreach ($lists as $list) {
        if (!is_array($list)) continue;
        foreach ($list as $item) {
            if (kop_mfd_is_empty($item)) continue;
            $key = kop_mfd_value_key($item);
            if (isset($seen[$key])) continue;
            $seen[$key] = true;
            $out[] = $item;
        }
    }
    return $out;
}

/**
 * Recursive merge, $a first: scalars keep $a unless empty, lists union, maps
 * merge key by key. A status of Unknown counts as empty.
 */
function kop_mfd_deep_merge($a, $b, $path = '') {
    if (kop_mfd_is_list($a) && kop_mfd_is_list($b)) return kop_mfd_union(array($a, $b));
    if (is_array($a) && is_array($b) && !kop_mfd_is_list($a) && !kop_mfd_is_list($b)) {
        $out = $a;
        foreach ($b as $key => $value) {
            $child = $path === '' ? (string)$key : $path . '.' . $key;
            $out[$key] = array_key_exists($key, $a) ? kop_mfd_deep_merge($a[$key], $value, $child) : $value;
        }
        return $out;
    }
    $a_empty = kop_mfd_is_empty($a) || ($path === 'operatingPeriod.status' && $a === 'Unknown');
    return $a_empty && !kop_mfd_is_empty($b) ? $b : $a;
}

/** Paths where the merged document differs from the survivor's original. */
function kop_mfd_changed_paths($before, $after, $path = '', array &$out = array()) {
    if (is_array($before) && is_array($after) && !kop_mfd_is_list($before) && !kop_mfd_is_list($after)) {
        foreach (array_unique(array_merge(array_keys($before), array_keys($after))) as $key) {
            $child = $path === '' ? (string)$key : $path . '.' . $key;
            kop_mfd_changed_paths($before[$key] ?? null, $after[$key] ?? null, $child, $out);
        }
        return $out;
    }
    if (json_encode(kop_facility_canonical($before)) !== json_encode(kop_facility_canonical($after))) $out[] = $path;
    return $out;
}

function kop_mfd_has_replacement_char($text) {
    return strpos((string)$text, "\xEF\xBF\xBD") !== false;
}

/**
 * Sort key: survivors first. $row is {id, unique_name, doc, links}.
 */
function kop_mfd_rank(array $row) {
    $doc = $row['doc'];
    return array(
        kop_mfd_has_replacement_char($doc['identification']['name'] ?? '') ? 1 : 0,
        -(int)$row['links'],
        (($doc['operatingPeriod']['status'] ?? 'Unknown') === 'Unknown') ? 1 : 0,
        -strlen((string)json_encode($doc)),
        (int)$row['id'],
    );
}

/**
 * Fold $drop's document into $keep's. Both are v2 documents.
 */
function kop_mfd_merge_docs(array $keep, array $drop, array $drop_meta) {
    $merged = kop_mfd_deep_merge($keep, $drop);
    $merged['schema_version'] = $keep['schema_version'] ?? KOP_FACILITY_SCHEMA_VERSION;
    $merged['facility_id'] = $keep['facility_id'];

    // The survivor's name stays unless it carries a replacement character and
    // the dropped copy's does not.
    $keep_name = (string)($keep['identification']['name'] ?? '');
    $drop_name = (string)($drop['identification']['name'] ?? '');
    $name = $keep_name;
    if (kop_mfd_has_replacement_char($keep_name) && $drop_name !== '' && !kop_mfd_has_replacement_char($drop_name)) {
        $name = $drop_name;
    }
    $merged['identification']['name'] = $name;
    $merged['identification']['nameKey'] = kop_facility_name_key($name);
    $merged['identification']['currentName'] = (string)($keep['identification']['currentName'] ?? '');
    if ($merged['identification']['currentName'] === '') {
        $merged['identification']['currentName'] = (string)($drop['identification']['currentName'] ?? '');
    }
    // A genuinely different spelling is worth keeping as another name.
    if ($drop_name !== '' && kop_facility_name_key($drop_name) !== kop_facility_name_key($name)) {
        $merged['identification']['otherNames'] = kop_mfd_union(array($merged['identification']['otherNames'] ?? array(), array($drop_name)));
    }

    // Provenance: the survivor's, with the dropped copy's legacy ids kept.
    $merged['provenance'] = $keep['provenance'] ?? array();
    $merged['provenance']['legacyIds'] = kop_mfd_union(array(
        $keep['provenance']['legacyIds'] ?? array(),
        $drop['provenance']['legacyIds'] ?? array()
    ));
    foreach (array('sourceProject', 'sourceProjectId', 'sourceCategory', 'sourceOperator') as $key) {
        if (kop_mfd_is_empty($merged['provenance'][$key] ?? null) && !kop_mfd_is_empty($drop['provenance'][$key] ?? null)) {
            $merged['provenance'][$key] = $drop['provenance'][$key];
        }
    }

    $legacy = is_array($merged['legacy'] ?? null) ? $merged['legacy'] : array();
    $legacy['mergedFacilities'] = kop_mfd_is_list($legacy['mergedFacilities'] ?? null) ? $legacy['mergedFacilities'] : array();
    $legacy['mergedFacilities'][] = array(
        'facility_id' => (int)$drop_meta['id'],
        'unique_name' => (string)$drop_meta['unique_name'],
        'name'        => $drop_name,
        'mergedAt'    => gmdate('c'),
    );
    $merged['legacy'] = $legacy;

    return $merged;
}

/**
 * Group rows into merge candidates by name key, state and city key.
 * $rows: list of {id, unique_name, doc, links}. Returns list of groups, each a
 * list of rows sorted survivor first.
 */
function kop_mfd_candidate_groups(array $rows) {
    $groups = array();
    foreach ($rows as $row) {
        $doc = $row['doc'];
        $name_key = kop_facility_name_key($doc['identification']['name'] ?? '');
        if ($name_key === '') continue;
        $state = kop_facility_state_code($doc['location']['state'] ?? null);
        $country = $state === null ? (string)($doc['location']['country'] ?? '') : '';
        $key = $name_key . '|' . ($state ?? 'C:' . mb_strtolower($country)) . '|' . kop_facility_city_key($doc['location']['city'] ?? '');
        $groups[$key][] = $row;
    }
    $out = array();
    foreach ($groups as $key => $members) {
        if (count($members) < 2) continue;
        usort($members, function ($a, $b) { return kop_mfd_rank($a) <=> kop_mfd_rank($b); });
        $out[] = array('key' => $key, 'rows' => $members);
    }
    usort($out, function ($a, $b) { return strcmp($a['key'], $b['key']); });
    return $out;
}

/**
 * Plan one group: merged document plus what moves. Pure; no database.
 * Returns {keep, drops, doc, changed, violations, errors}.
 */
function kop_mfd_plan_group(array $group) {
    $rows = $group['rows'];
    $keep = array_shift($rows);
    $doc = $keep['doc'];
    $drops = array();
    foreach ($rows as $drop) {
        $doc = kop_mfd_merge_docs($doc, $drop['doc'], $drop);
        $drops[] = $drop;
    }
    $doc = kop_facility_normalize($doc, array('facility_id' => $keep['id'], 'unique_name' => $keep['unique_name']));
    $violations = kop_facility_validate($doc);
    $errors = array_values(array_filter($violations, function ($v) { return $v['severity'] === 'error'; }));
    return array(
        'keep'       => $keep,
        'drops'      => $drops,
        'doc'        => $doc,
        'changed'    => kop_mfd_changed_paths($keep['doc'], $doc),
        'violations' => $violations,
        'errors'     => $errors,
    );
}

function kop_mfd_row_summary(array $row) {
    return array(
        'id'          => (int)$row['id'],
        'unique_name' => (string)$row['unique_name'],
        'name'        => (string)($row['doc']['identification']['name'] ?? ''),
        'status'      => (string)($row['doc']['operatingPeriod']['status'] ?? ''),
        'links'       => (int)$row['links'],
    );
}

// Library mode for offline tests: define this before including the file.
if (defined('KOP_MERGE_FACILITY_DUPLICATES_LIB') && KOP_MERGE_FACILITY_DUPLICATES_LIB) return;

header('Content-Type: application/json');

require_once __DIR__ . '/config.php';
require_once dirname(__DIR__) . '/inc/facility-v2-writer.php';

$is_cli  = php_sapi_name() === 'cli';
$args    = $is_cli ? array_slice($argv ?? array(), 1) : array();
$dry_run = $is_cli ? in_array('dry', $args, true) : !empty($_GET['dry']);
$run_ok  = $is_cli || (($_GET['run'] ?? null) === '1');
$pairs_raw = $is_cli
    ? implode(',', array_filter($args, function ($a) { return $a !== 'dry'; }))
    : (string)($_GET['pairs'] ?? '');

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

try {
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $prefix = kop_v2_detect_prefix($pdo);
    if (!kop_v2_writes_active($pdo, $prefix)) {
        throw new RuntimeException(
            'The v2 write switch is off. Until it is on, the ten-minute sync rebuilds facilities_v2 from the legacy '
            . 'tables and would restore any merged row. Turn it on at api/migrate-facility-model.php?action=writes first.'
        );
    }
    $t = kop_migration_tables($prefix);
    $opts = array('pdo' => $pdo, 'prefix' => $prefix);

    $link_counts = array();
    foreach (array('news_facility_links', 'lawsuit_facility_links') as $table) {
        foreach ($pdo->query("SELECT facility_id, COUNT(*) AS n FROM `{$table}` GROUP BY facility_id")->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $link_counts[(int)$r['facility_id']] = ($link_counts[(int)$r['facility_id']] ?? 0) + (int)$r['n'];
        }
    }
    $load_row = function ($r) use ($link_counts) {
        $doc = json_decode((string)$r['json_data'], true);
        if (!is_array($doc) || !isset($doc['identification'], $doc['location'])) return null;
        return array('id' => (int)$r['id'], 'unique_name' => (string)$r['unique_name'], 'doc' => $doc, 'links' => $link_counts[(int)$r['id']] ?? 0);
    };

    $groups = array();
    if (trim($pairs_raw) !== '') {
        $by_id = $pdo->prepare("SELECT id, unique_name, json_data FROM `{$t['facilities']}` WHERE id = ?");
        foreach (array_filter(array_map('trim', explode(',', $pairs_raw))) as $pair) {
            $ids = array_map('intval', explode(':', $pair));
            if (count($ids) < 2 || in_array(0, $ids, true)) throw new RuntimeException("Bad pair '{$pair}': expected keep:drop ids.");
            $rows = array();
            foreach ($ids as $id) {
                $by_id->execute(array($id));
                $r = $by_id->fetch(PDO::FETCH_ASSOC);
                $row = $r ? $load_row($r) : null;
                if ($row === null) throw new RuntimeException("Facility {$id} not found in {$t['facilities']}.");
                $rows[] = $row;
            }
            $groups[] = array('key' => implode(':', $ids), 'rows' => $rows); // explicit order: first id survives
        }
    } else {
        $rows = array();
        foreach ($pdo->query("SELECT id, unique_name, json_data FROM `{$t['facilities']}` ORDER BY id")->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $row = $load_row($r);
            if ($row !== null) $rows[] = $row;
        }
        $groups = kop_mfd_candidate_groups($rows);
    }

    $plans = array();
    foreach ($groups as $group) $plans[] = kop_mfd_plan_group($group);

    $out = array(
        'success' => true,
        'dry_run' => $dry_run,
        'mode'    => trim($pairs_raw) !== '' ? 'explicit' : 'auto',
        'groups'  => array(),
        'totals'  => array('groups' => count($plans), 'merged' => 0, 'skipped' => 0, 'rows_dropped' => 0),
    );

    $apply = function () use ($pdo, $t, $opts, $plans, &$out, $dry_run) {
        $move = array(
            'operator_facilities' => array($t['operator_facilities'], 'operator_id, facility_id, relationship, sort_order', 'operator_id, ?, relationship, sort_order'),
            'facility_locations'  => array($t['facility_locations'], 'facility_id, location_key, role, source, needs_review, review_reason', '?, location_key, role, source, needs_review, review_reason'),
            'news_links'          => array('news_facility_links', 'news_id, facility_id, link_type, created_at, created_by', 'news_id, ?, link_type, created_at, created_by'),
            'lawsuit_links'       => array('lawsuit_facility_links', 'lawsuit_id, facility_id, link_type, created_by, created_at', 'lawsuit_id, ?, link_type, created_by, created_at'),
        );
        foreach ($plans as $plan) {
            $entry = array(
                'keep'    => kop_mfd_row_summary($plan['keep']),
                'drops'   => array_map('kop_mfd_row_summary', $plan['drops']),
                'merged_name'   => $plan['doc']['identification']['name'],
                'merged_status' => $plan['doc']['operatingPeriod']['status'],
                'changed_paths' => $plan['changed'],
                'warnings'      => count($plan['violations']) - count($plan['errors']),
                'errors'        => array_map(function ($v) { return $v['path'] . ' - ' . $v['message']; }, $plan['errors']),
                'moved'         => array(),
                'doc_status'    => null,
            );
            if ($plan['errors']) {
                $entry['result'] = 'skipped: merged document fails validation';
                $out['groups'][] = $entry;
                $out['totals']['skipped']++;
                continue;
            }
            if (!$dry_run) {
                $keep_id = (int)$plan['keep']['id'];
                foreach ($plan['drops'] as $drop) {
                    $drop_id = (int)$drop['id'];
                    foreach ($move as $label => $m) {
                        list($table, $cols, $select) = $m;
                        $ins = $pdo->prepare("INSERT IGNORE INTO `{$table}` ({$cols}) SELECT {$select} FROM `{$table}` WHERE facility_id = ?");
                        $ins->execute(array($keep_id, $drop_id));
                        $del = $pdo->prepare("DELETE FROM `{$table}` WHERE facility_id = ?");
                        $del->execute(array($drop_id));
                        $entry['moved'][$label] = ($entry['moved'][$label] ?? 0) + $del->rowCount();
                    }
                    // Identity rows: the survivor may already own one (facility_id is unique).
                    $has = $pdo->prepare("SELECT COUNT(*) FROM `{$t['identity']}` WHERE facility_id = ?");
                    $has->execute(array($keep_id));
                    if ((int)$has->fetchColumn() > 0) {
                        $pdo->prepare("DELETE FROM `{$t['identity']}` WHERE facility_id = ?")->execute(array($drop_id));
                    } else {
                        $pdo->prepare("UPDATE `{$t['identity']}` SET facility_id = ?, unique_name = ? WHERE facility_id = ?")
                            ->execute(array($keep_id, $plan['keep']['unique_name'], $drop_id));
                    }
                    try {
                        $wiki = $pdo->prepare("UPDATE wiki_submissions SET facility_unique_name = ? WHERE facility_unique_name = ?");
                        $wiki->execute(array($plan['keep']['unique_name'], $drop['unique_name']));
                        $entry['moved']['wiki_submissions'] = ($entry['moved']['wiki_submissions'] ?? 0) + $wiki->rowCount();
                    } catch (PDOException $e) {
                        // wiki_submissions may not exist on this install.
                    }
                    $pdo->prepare("DELETE FROM `{$t['facilities']}` WHERE id = ?")->execute(array($drop_id));
                    $out['totals']['rows_dropped']++;
                }
                $status = null;
                kop_facility_save($plan['doc'], $opts, $status);
                $entry['doc_status'] = $status;
            }
            $entry['result'] = $dry_run ? 'would merge' : 'merged';
            $out['groups'][] = $entry;
            $out['totals']['merged']++;
        }
    };

    if ($dry_run) {
        $apply();
    } else {
        kop_v2_with_write_lock($pdo, $apply);
    }

    echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(array('success' => false, 'error' => $e->getMessage()));
}
