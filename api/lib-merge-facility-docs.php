<?php
/**
 * Folding one facilities_v2 document into another: the field rules every merge uses
 * (inc/facility-merge.php, KOP Tools > Merge Duplicates; renames and alternate names
 * through kop_mfd_merge_docs()'s $rename and $alias arguments).
 *
 * Empty scalars fill (Unknown counts as empty for the status), lists union, maps
 * merge recursively, and the dropped copy is recorded under legacy.mergedFacilities.
 * kop_mfd_candidate_groups() / kop_mfd_plan_group() pick the survivor of rows sharing
 * name key, state and city: the copy whose name has no replacement character, then
 * the one with more news and lawsuit links, then a known status, then the larger
 * document, then the lower id.
 *
 * This was api/merge-facility-duplicates.php, a one-time batch endpoint that also
 * applied the merges; Merge Duplicates replaced it (with Undo) and the endpoint was
 * removed 2026-10-13. Functions only: nothing runs on include.
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
 *
 * $rename is set when the two records are one program under two names and
 * $keep holds the later name (KOP Tools > Merge Duplicates > Renamed): each
 * name keeps its own years, status and facts (docs/PLAN.md 3.7). Keys:
 *   year       the year the earlier name became the later one
 *   earlier    {start, end, status, operators[]}: the earlier name's own
 *   later      {start, end}: the kept record's years from now on (null = as is)
 *   facts      {earlier: kind => keys, later: kind => keys}: what each record
 *              held before the merge (kop_facility_eras_item_key()), so every
 *              item stands under the name it was filed under
 * The earlier name goes to pastNames, never otherNames. Nothing in its
 * operatingPeriod fills the kept one: its years, status and notes stay on
 * its legacy.mergedFacilities entry under 'rename', which the facility page
 * (inc/facility-eras.php) and the map build read. Its operator becomes a
 * past operator.
 */
function kop_mfd_merge_docs(array $keep, array $drop, array $drop_meta, array $rename = null, $alias = false) {
    $drop_period = is_array($drop['operatingPeriod'] ?? null) ? $drop['operatingPeriod'] : array();
    $drop_operator = trim((string) ($drop['identification']['currentOperator'] ?? ''));
    if ($rename !== null) {
        // The earlier name's dates, status and notes are its own: none of them fill the later name's.
        $drop['operatingPeriod'] = array();
        if (is_array($drop['identification'] ?? null)) {
            unset($drop['identification']['currentOperator'], $drop['identification']['currentName']);
            if ($drop_operator !== '') {
                $past = is_array($drop['identification']['pastOperators'] ?? null) ? $drop['identification']['pastOperators'] : array();
                $past[] = $drop_operator;
                $drop['identification']['pastOperators'] = $past;
            }
        }
    }
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
    if ($rename !== null) {
        // The earlier name is a past name, listed once.
        $drop_key = kop_facility_name_key($drop_name);
        $merged['identification']['otherNames'] = array_values(array_filter((array) ($merged['identification']['otherNames'] ?? array()), function ($n) use ($drop_key) {
            return !is_string($n) || kop_facility_name_key($n) !== $drop_key;
        }));
        if ($drop_name !== '' && $drop_key !== kop_facility_name_key($name)) {
            $merged['identification']['pastNames'] = kop_mfd_union(array($merged['identification']['pastNames'] ?? array(), array($drop_name)));
        }
        $later = (array) ($rename['later'] ?? array());
        $period = is_array($merged['operatingPeriod'] ?? null) ? $merged['operatingPeriod'] : array();
        foreach (array('start' => 'startYear', 'end' => 'endYear') as $k => $field) {
            if (isset($later[$k]) && is_numeric($later[$k]) && (int) $later[$k] > 0) $period[$field] = (int) $later[$k];
        }
        // The text form of the years would still give the whole site's life.
        if (!empty($later['start']) || !empty($later['end'])) $period['yearsOfOperation'] = '';
        $merged['operatingPeriod'] = $period;
    } elseif ($drop_name !== '' && kop_facility_name_key($drop_name) !== kop_facility_name_key($name)) {
        // A genuinely different spelling is worth keeping as another name.
        $merged['identification']['otherNames'] = kop_mfd_union(array($merged['identification']['otherNames'] ?? array(), array($drop_name)));
    }
    if ($rename === null && $alias) {
        // $alias: the dropped name is only another name the program goes by, never an earlier one. It leaves the
        // past names and "now called" (where either record filed it), so nothing reads the pair as a rename.
        $keys = array(kop_facility_name_key($drop_name), kop_facility_name_key($name));
        $merged['identification']['pastNames'] = array_values(array_filter((array) ($merged['identification']['pastNames'] ?? array()), function ($n) use ($keys) {
            return !is_string($n) || !in_array(kop_facility_name_key($n), $keys, true);
        }));
        if (in_array(kop_facility_name_key((string) ($merged['identification']['currentName'] ?? '')), $keys, true)) $merged['identification']['currentName'] = '';
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
    $entry = array(
        'facility_id' => (int)$drop_meta['id'],
        'unique_name' => (string)$drop_meta['unique_name'],
        'name'        => $drop_name,
        'mergedAt'    => gmdate('c'),
    );
    if ($rename !== null) {
        $earlier = (array) ($rename['earlier'] ?? array());
        $int = function ($v) { return (is_numeric($v) && (int) $v > 0) ? (int) $v : null; };
        $operators = array();
        foreach (array_merge($drop_operator !== '' ? array($drop_operator) : array(), (array) ($earlier['operators'] ?? array())) as $o) {
            $o = trim((string) $o);
            if ($o !== '' && !in_array(mb_strtolower($o), array_map('mb_strtolower', $operators), true)) $operators[] = $o;
        }
        $entry['rename'] = array(
            'year'       => (int) $rename['year'],
            'startYear'  => array_key_exists('start', $earlier) ? $int($earlier['start']) : $int($drop_period['startYear'] ?? null),
            'endYear'    => $int($earlier['end'] ?? null) ?? (int) $rename['year'],
            'status'     => (string) ($earlier['status'] ?? ($drop_period['status'] ?? '')),
            'notes'      => array_values(array_filter((array) ($drop_period['notes'] ?? array()), 'is_string')),
            'operators'  => $operators,
            'facts'      => (array) ($rename['facts']['earlier'] ?? array()),
            'laterName'  => $name,
            'laterFacts' => (array) ($rename['facts']['later'] ?? array()),
        );
    }
    $legacy['mergedFacilities'][] = $entry;
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
