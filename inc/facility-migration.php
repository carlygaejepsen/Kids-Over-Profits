<?php
/**
 * Facility data model migration: plan, diff and apply.
 *
 * docs/DATA-MODEL-MIGRATION.md, phases 2 and 3. One implementation serves:
 *   - scripts/rehearse-migration.php   offline rehearsal against a dump
 *   - api/migrate-facility-model.php   dry run / apply on production
 *   - inc/facility-v2-sync.php         re-derive v2 after legacy saves (bake period)
 *
 * kop_migration_build_plan() is pure: rows in, plan out. Only the functions
 * below the "Apply" banner touch a database, always through PDO.
 *
 * Rules R1-R6 are described in the spec; the comments here say how.
 */

require_once __DIR__ . '/facility-store.php';

// ---------------------------------------------------------------------------
// Small helpers
// ---------------------------------------------------------------------------

if (!function_exists('kop_migration_place')) {
    /** Place of a document: US state code, 'C:<country>' outside the US, or null. */
    function kop_migration_place(array $doc) {
        if ($doc['location']['state'] !== null) return $doc['location']['state'];
        $country = $doc['location']['country'];
        if ($country !== null && $country !== 'United States') return 'C:' . $country;
        return null;
    }
}

if (!function_exists('kop_migration_state_source')) {
    /** Which input gave a copy its place (the membership `source`). */
    function kop_migration_state_source(array $raw, array $doc) {
        if ($doc['location']['state'] === null && $doc['location']['country'] === null) return null;
        $unwrapped = kop_facility_unwrap($raw);
        $f = $unwrapped['facility'];
        $state = $doc['location']['state'];

        if ($state !== null) {
            if (is_string($f['address'] ?? null) && kop_facility_parse_address($f['address'])['state'] === $state) return 'address';
            if (is_array($f['address'] ?? null) && kop_facility_state_code($f['address']['state'] ?? '') === $state) return 'address';
            if (is_array($f['addressParts'] ?? null) && kop_facility_state_code($f['addressParts']['state'] ?? '') === $state) return 'address';
            if (is_array($f['locationDetails'] ?? null) && kop_facility_state_code($f['locationDetails']['state'] ?? '') === $state) return 'location_details';
            if (is_string($f['location'] ?? null) && kop_facility_location_text_names_state($f['location'], $state)) return 'location_details';
            if (kop_facility_state_code($unwrapped['wrapper']['state'] ?? '') === $state) return 'wrapper_state';
            return 'location_details';
        }
        if (is_string($f['address'] ?? null) && kop_facility_parse_address($f['address'])['country'] !== '') return 'address';
        if (!empty($unwrapped['wrapper']['state'])) return 'wrapper_state';
        return 'location_details';
    }
}

if (!function_exists('kop_migration_value_key')) {
    function kop_migration_value_key($value) {
        if (is_array($value)) return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (is_string($value)) return mb_strtolower(preg_replace('/\s+/', ' ', trim($value)));
        return var_export($value, true);
    }
}

if (!function_exists('kop_migration_address_line_key')) {
    /** Loose key for comparing two one-line addresses. */
    function kop_migration_address_line_key($line) {
        $s = mb_strtolower((string)$line);
        $s = preg_replace('/[^\w\s]/u', ' ', $s);
        $s = preg_replace('/\b(street|st)\b/u', 'st', $s);
        $s = preg_replace('/\b(road|rd)\b/u', 'rd', $s);
        $s = preg_replace('/\b(avenue|ave)\b/u', 'ave', $s);
        $s = preg_replace('/\b(drive|dr)\b/u', 'dr', $s);
        $s = preg_replace('/\s+/', ' ', $s);
        return trim($s);
    }
}

if (!function_exists('kop_migration_text_match_fields')) {
    /** The raw fields the legacy page builders read from a facilities_master copy. */
    function kop_migration_text_match_fields(array $raw) {
        $f = kop_facility_unwrap($raw)['facility'];
        $address = isset($f['address']) && is_array($f['address']) ? $f['address'] : array();
        $parts = isset($f['addressParts']) && is_array($f['addressParts']) ? $f['addressParts'] : array();
        $details = isset($f['locationDetails']) && is_array($f['locationDetails']) ? $f['locationDetails'] : array();
        return array(
            'address_state'   => strtolower(trim((string)($address['state'] ?? ''))),
            'details_state'   => strtolower(trim((string)($details['state'] ?? ''))),
            'location'        => strtolower((string)($f['location'] ?? '')),
            'location_raw'    => (string)($f['location'] ?? ''),
            'address_country' => strtolower(trim((string)($address['country'] ?? ''))),
            'parts_country'   => strtolower(trim((string)($parts['country'] ?? ''))),
            'details_country' => strtolower(trim((string)($details['country'] ?? ''))),
        );
    }
}

if (!function_exists('kop_migration_text_match_page')) {
    /**
     * How the page builders place a copy on a page beyond its own address:
     * 'structured' (address/locationDetails state or country equals the page),
     * 'text' (the free-text location names the page as a place),
     * 'wrong_text' (only the old substring matcher matched; removed on purpose),
     * or null.
     */
    function kop_migration_text_match_page(array $m, array $page) {
        $name = $page['lower'];
        if ($page['kind'] === 'state') {
            $abbrev = $page['abbrev_lower'];
            if ($m['address_state'] === $name || $m['details_state'] === $name
                || ($abbrev !== '' && ($m['address_state'] === $abbrev || $m['details_state'] === $abbrev))) {
                return 'structured';
            }
            if (trim($m['location_raw']) === '') return null;
            if (kop_facility_location_text_names_state($m['location_raw'], strtoupper($abbrev))) return 'text';
            $old = ($name !== '' && strpos($m['location'], $name) !== false)
                || ($abbrev !== '' && preg_match('/\b' . preg_quote($abbrev, '/') . '\b/', $m['location']));
            return $old ? 'wrong_text' : null;
        }
        if (in_array($name, array($m['address_country'], $m['parts_country'], $m['details_country']), true)) {
            return 'structured';
        }
        if (trim($m['location_raw']) === '') return null;
        if (kop_facility_location_text_names_country($m['location_raw'], $page['lower'])) return 'text';
        return ($name !== '' && strpos($m['location'], $name) !== false) ? 'wrong_text' : null;
    }
}

if (!function_exists('kop_migration_same_city')) {
    /**
     * Two city keys name the same place for R1 partitioning: either empty,
     * equal, one ends with the other (street glued on), or a small typo apart.
     */
    function kop_migration_same_city($a, $b) {
        if ($a === '' || $b === '' || $a === $b) return true;
        $short = strlen($a) < strlen($b) ? $a : $b;
        $long = $short === $a ? $b : $a;
        if (substr($long, -strlen($short) - 1) === ' ' . $short) return true;
        if (strlen($short) >= 5 && levenshtein($a, $b) <= 2) return true;
        if (preg_replace('/^(saint|st) /', 'st ', $a) === preg_replace('/^(saint|st) /', 'st ', $b)) return true;
        return false;
    }
}

if (!function_exists('kop_migration_is_empty')) {
    function kop_migration_is_empty($value) {
        return $value === null || $value === '' || $value === array();
    }
}

if (!function_exists('kop_migration_get')) {
    function kop_migration_get(array $doc, $path) {
        $node = $doc;
        foreach (explode('.', $path) as $part) {
            if (!is_array($node) || !array_key_exists($part, $node)) return null;
            $node = $node[$part];
        }
        return $node;
    }
}

if (!function_exists('kop_migration_set')) {
    function kop_migration_set(array &$doc, $path, $value) {
        $node = &$doc;
        $parts = explode('.', $path);
        $last = array_pop($parts);
        foreach ($parts as $part) {
            if (!isset($node[$part]) || !is_array($node[$part])) $node[$part] = array();
            $node = &$node[$part];
        }
        $node[$last] = $value;
    }
}

if (!function_exists('kop_migration_union')) {
    /** Union of lists, newest first, de-duplicated by normalized value. */
    function kop_migration_union(array $lists) {
        $out = array();
        $seen = array();
        foreach ($lists as $list) {
            if (!is_array($list)) continue;
            foreach ($list as $item) {
                if (kop_migration_is_empty($item)) continue;
                $key = kop_migration_value_key($item);
                if (isset($seen[$key])) continue;
                $seen[$key] = true;
                $out[] = $item;
            }
        }
        return $out;
    }
}

if (!function_exists('kop_migration_merge_maps')) {
    /** Shallow map merge, newest first: scalars first-non-empty-wins, lists unioned. */
    function kop_migration_merge_maps(array $maps) {
        $out = array();
        $is_list = function ($v) {
            return is_array($v) && ($v === array() || array_keys($v) === range(0, count($v) - 1));
        };
        foreach ($maps as $map) {
            if (!is_array($map)) continue;
            foreach ($map as $key => $value) {
                if (!array_key_exists($key, $out)) {
                    $out[$key] = $value;
                } elseif ($is_list($out[$key]) && $is_list($value)) {
                    $out[$key] = kop_migration_union(array($out[$key], $value));
                } elseif ((kop_migration_is_empty($out[$key]) || $out[$key] === false) && !kop_migration_is_empty($value)) {
                    $out[$key] = $value;
                }
            }
        }
        return $out;
    }
}

if (!function_exists('kop_migration_places_in_text')) {
    /** US states (codes) and countries ('C:<name>') named in link text (news location, court). */
    function kop_migration_places_in_text($text) {
        $text = (string)$text;
        if (trim($text) === '') return array();
        $found = array();
        foreach (kop_facility_states() as $code => $name) {
            if (preg_match('/\b' . preg_quote($name, '/') . '\b/i', $text)
                || preg_match('/(?:^|[,\s(])' . $code . '(?:$|[\s,.)\d])/', $text)) {
                $found[$code] = true;
            }
        }
        foreach (kop_facility_countries() as $alias => $country) {
            if ($country === 'United States' || strlen($alias) < 4) continue;
            if (preg_match('/\b' . preg_quote($alias, '/') . '\b/i', $text)) $found['C:' . $country] = true;
        }
        return array_keys($found);
    }
}

if (!function_exists('kop_migration_project_facilities')) {
    /** The facilities array of an operator/location row, whichever wrapper it uses. */
    function kop_migration_project_facilities(array $payload) {
        if (isset($payload['data']) && is_array($payload['data'])) {
            $data = $payload['data'];
            if (isset($data['facilities']) && is_array($data['facilities'])) return $data['facilities'];
            if (isset($data['data']['facilities']) && is_array($data['data']['facilities'])) return $data['data']['facilities'];
            if (isset($data['facility']) && is_array($data['facility'])) return array($data['facility']);
        }
        if (isset($payload['facilities']) && is_array($payload['facilities'])) return $payload['facilities'];
        return array();
    }
}

// ---------------------------------------------------------------------------
// Plan
// ---------------------------------------------------------------------------

if (!function_exists('kop_migration_build_plan')) {
    /**
     * Compute the whole migration from legacy rows.
     *
     * @param array $input
     *   facility_rows  list of {id, unique_name, json (decoded array or null), updated_at}
     *                  from facilities_master, ORDER BY id
     *   location_rows  same, from locations_master, ORDER BY id
     *   links          optional list of link records (see scripts/rehearse-migration.php)
     *   identity       optional map identity_key => {facility_id, unique_name} from
     *                  earlier runs, so split and new facilities keep their ids
     *   allocate_id    callable(string $identity_key, string $unique_name): int, for
     *                  facilities without an identity entry
     *   migrated_at    ISO timestamp stamped into provenance (default now)
     * @return array plan (see the keys of $plan at the end)
     */
    function kop_migration_build_plan(array $input) {
        $identity = $input['identity'] ?? array();
        $allocate = $input['allocate_id'];
        $migrated_at = $input['migrated_at'] ?? gmdate('c');

        $copies = array();
        $ref_idx = array();
        $review = array();
        $all_unique_names = array();
        $max_id = 0;
        $json_errors = array();

        $add_review = function ($facility_id, $reason, $detail) use (&$review) {
            $review[] = array('facility_id' => $facility_id, 'reason' => $reason, 'detail' => $detail);
        };

        // -- 1. Normalize every copy -------------------------------------------
        $add_copy = function (array $raw, array $ctx) use (&$copies, &$ref_idx, $add_review) {
            // Identity evidence comes from the copy itself, never the row it sits in.
            $doc = kop_facility_normalize($raw, $ctx['opts']);
            if ($doc['identification']['name'] === '') {
                $add_review($doc['facility_id'], 'blank_entry_dropped', sprintf(
                    '%s row %s (%s) index %s: entry has no name; state pages already skip it',
                    $ctx['source'], $ctx['row_id'], $ctx['row_name'], var_export($ctx['index'], true)
                ));
                return;
            }
            $idx = count($copies);
            $copies[] = array(
                'idx'          => $idx,
                'source'       => $ctx['source'],
                'row_id'       => $ctx['row_id'],
                'row_name'     => $ctx['row_name'],
                'updated_at'   => (string)$ctx['updated_at'],
                'index'        => $ctx['index'],
                'doc'          => $doc,
                'place'        => kop_migration_place($doc),
                'state_source' => kop_migration_state_source($raw, $doc),
                'city_key'     => kop_facility_city_key($doc['location']['city']),
                'loc_row'      => $ctx['source'] === 'locations_master' ? mb_strtoupper($ctx['row_name']) : null,
                'is_ref'       => $ctx['source'] === 'facilities_master.ref',
                'text_match'   => $ctx['source'] === 'locations_master' ? null : kop_migration_text_match_fields($raw),
            );
            if ($ctx['source'] === 'facilities_master.ref') $ref_idx[$ctx['row_id']] = $idx;
        };

        $operator_payloads = array();
        foreach ($input['facility_rows'] as $row) {
            $all_unique_names[mb_strtolower($row['unique_name'])] = (int)$row['id'];
            $max_id = max($max_id, (int)$row['id']);
            $payload = $row['json'];
            if (!is_array($payload)) {
                $json_errors[] = 'facilities_master #' . $row['id'] . ' is not valid JSON';
                continue;
            }
            if (!empty($payload['__facility_ref'])) {
                $add_copy($payload, array(
                    'source' => 'facilities_master.ref', 'row_id' => (int)$row['id'], 'row_name' => $row['unique_name'],
                    'updated_at' => $row['updated_at'], 'index' => null,
                    'opts' => array('facility_id' => (int)$row['id'], 'unique_name' => $row['unique_name'], 'source' => 'facilities_master.ref'),
                ));
                continue;
            }
            $operator_payloads[] = $row;
            foreach (kop_migration_project_facilities($payload) as $i => $facility) {
                if (!is_array($facility)) continue;
                $add_copy($facility, array(
                    'source' => 'facilities_master.operator', 'row_id' => (int)$row['id'], 'row_name' => $row['unique_name'],
                    'updated_at' => $row['updated_at'], 'index' => $i,
                    'opts' => array(
                        'facility_id' => isset($facility['facility_id']) ? (int)$facility['facility_id'] : null,
                        'source' => 'facilities_master.operator:' . $row['unique_name'],
                    ),
                ));
            }
        }
        foreach ($input['location_rows'] as $row) {
            $payload = $row['json'];
            if (!is_array($payload)) {
                $json_errors[] = 'locations_master #' . $row['id'] . ' is not valid JSON';
                continue;
            }
            foreach (kop_migration_project_facilities($payload) as $i => $facility) {
                if (!is_array($facility)) continue;
                $add_copy($facility, array(
                    'source' => 'locations_master', 'row_id' => (int)$row['id'], 'row_name' => $row['unique_name'],
                    'updated_at' => $row['updated_at'], 'index' => $i,
                    'opts' => array(
                        'facility_id' => isset($facility['facility_id']) ? (int)$facility['facility_id'] : null,
                        'source' => 'locations_master:' . $row['unique_name'],
                    ),
                ));
            }
        }

        // -- 2. Group copies by facility id; resolve id-less copies ------------
        $ref_by_name = array();
        foreach ($ref_idx as $id => $idx) {
            $ref_by_name[$copies[$idx]['doc']['identification']['nameKey']][] = $id;
        }

        $groups = array();
        $dangling_copies = array();
        $resolution = array('by_id' => 0, 'resolved' => 0, 'resolved_stateless_ref' => 0, 'new' => 0, 'dangling_id' => 0);

        foreach ($copies as $idx => $copy) {
            $id = $copy['doc']['facility_id'];
            if ($id !== null && isset($ref_idx[$id])) {
                $groups['id:' . $id][] = $idx;
                $resolution['by_id']++;
                continue;
            }
            if ($id !== null) {
                // Old single-facility project rows stamped their own row id on
                // their entries; resolve like an id-less copy, map the id later.
                $resolution['dangling_id']++;
                $dangling_copies[$id][] = $idx;
            }

            // Same rule as kop_facility_resolve_identity(), plus one migration
            // allowance: a ref row with no place matches when it is the only
            // candidate by name (R1: copies that lack a state agree).
            $name_key = $copy['doc']['identification']['nameKey'];
            $candidates = $ref_by_name[$name_key] ?? array();
            $match = null;
            $stateless = false;
            $same_place = array();
            foreach ($candidates as $cid) {
                if ($copies[$ref_idx[$cid]]['place'] === $copy['place']) $same_place[] = $cid;
            }
            if ($same_place) {
                if ($copy['city_key'] !== '') {
                    foreach ($same_place as $cid) {
                        if ($copies[$ref_idx[$cid]]['city_key'] === $copy['city_key']) { $match = $cid; break; }
                    }
                    if ($match === null) {
                        foreach ($same_place as $cid) {
                            if ($copies[$ref_idx[$cid]]['city_key'] === '') { $match = $cid; break; }
                        }
                    }
                    if ($match === null && count($same_place) === 1) {
                        $match = $same_place[0];
                        $add_review($match, 'matched_despite_city', sprintf(
                            '%s row %s (%s): "%s" city "%s" vs ref city "%s"',
                            $copy['source'], $copy['row_id'], $copy['row_name'], $copy['doc']['identification']['name'],
                            $copy['doc']['location']['city'], $copies[$ref_idx[$match]]['doc']['location']['city']
                        ));
                    }
                } elseif (count($same_place) === 1) {
                    $match = $same_place[0];
                }
            } elseif (count($candidates) === 1 && ($copy['place'] === null || $copies[$ref_idx[$candidates[0]]]['place'] === null)) {
                $match = $candidates[0];
                $stateless = true;
            }

            if ($match !== null) {
                $groups['id:' . $match][] = $idx;
                $resolution[$stateless ? 'resolved_stateless_ref' : 'resolved']++;
                if ($stateless) {
                    $add_review($match, 'matched_stateless', sprintf(
                        '%s row %s (%s): "%s" matched by name only because one side has no state',
                        $copy['source'], $copy['row_id'], $copy['row_name'], $copy['doc']['identification']['name']
                    ));
                }
                continue;
            }
            $groups['new:' . $name_key . '|' . (string)$copy['place'] . '|' . $copy['city_key']][] = $idx;
            $resolution['new']++;
        }

        // -- 3. R1: split groups whose copies disagree on place -----------------
        $facilities = array();   // key (identity key or 'id:N') => info; ids assigned in step 4
        $splits = array();       // old id => list of facility keys, keeper first

        $group_keys = array_keys($groups);
        sort($group_keys, SORT_STRING);

        foreach ($group_keys as $gkey) {
            $members = $groups[$gkey];
            $old_id = strpos($gkey, 'id:') === 0 ? (int)substr($gkey, 3) : null;

            $places = array();
            foreach ($members as $idx) {
                if ($copies[$idx]['place'] !== null) $places[$copies[$idx]['place']] = true;
            }

            if (count($places) <= 1) {
                $facilities[$gkey] = array(
                    'copies' => $members, 'old_id' => $old_id,
                    'origin' => $old_id !== null ? 'existing' : 'new_from_unlinked_copies',
                );
                continue;
            }

            $partitions = array();
            $placeless = array();
            foreach ($members as $idx) {
                $c = $copies[$idx];
                if ($c['place'] === null) { $placeless[] = $idx; continue; }
                $pkey = null;
                foreach ($partitions as $k => $p) {
                    if ($p['place'] !== $c['place']) continue;
                    if (kop_migration_same_city($p['city_key'], $c['city_key'])) {
                        $pkey = $k;
                        if ($p['city_key'] === '' && $c['city_key'] !== '') $partitions[$k]['city_key'] = $c['city_key'];
                        break;
                    }
                }
                if ($pkey === null) {
                    $pkey = $c['place'] . '|' . $c['city_key'];
                    $partitions[$pkey] = array('place' => $c['place'], 'city_key' => $c['city_key'], 'copies' => array());
                }
                $partitions[$pkey]['copies'][] = $idx;
            }

            $keeper = null;
            if ($old_id !== null) {
                foreach ($partitions as $k => $p) {
                    if (in_array($ref_idx[$old_id], $p['copies'], true)) { $keeper = $k; break; }
                }
            }
            if ($keeper === null) {
                $sizes = array_map(function ($p) { return count($p['copies']); }, $partitions);
                arsort($sizes);
                $keeper = array_key_first($sizes);
                if ($old_id !== null) {
                    $add_review($old_id, 'split_keeper_guessed', 'ref row has no place; the largest partition (' . $keeper . ') keeps the id');
                }
            }

            foreach ($placeless as $idx) {
                $target = $keeper;
                if ($copies[$idx]['loc_row'] !== null) {
                    $row_code = kop_facility_state_code($copies[$idx]['loc_row']);
                    $row_place = $row_code !== null ? $row_code : 'C:' . kop_facility_country_name($copies[$idx]['loc_row']);
                    foreach ($partitions as $k => $p) {
                        if ($p['place'] === $row_place) { $target = $k; break; }
                    }
                }
                $partitions[$target]['copies'][] = $idx;
            }

            $ordered = array($keeper => $partitions[$keeper]) + $partitions;
            $keys = array();
            foreach ($ordered as $k => $p) {
                if ($k === $keeper && $old_id !== null) {
                    $fkey = $gkey;
                    $origin = 'split_keeper';
                } else {
                    // Stable identity for a split-off facility: the old id (or
                    // group) plus the place and city that set it apart.
                    $fkey = ($old_id !== null ? 'split:' . $old_id : $gkey) . ':' . $p['place'] . ':' . $p['city_key'];
                    $origin = $old_id === null ? 'new_from_unlinked_copies' : 'split_new';
                }
                $facilities[$fkey] = array('copies' => $p['copies'], 'old_id' => $old_id, 'origin' => $origin);
                $keys[] = $fkey;
            }
            if ($old_id !== null) $splits[$old_id] = $keys;
        }

        // -- 4. Assign ids: existing ids stay, the rest come from the identity map
        $key_to_id = array();
        $new_identities = array();
        $unique_names_taken = $all_unique_names;
        foreach ($identity as $entry) {
            if (!empty($entry['unique_name'])) $unique_names_taken[mb_strtolower($entry['unique_name'])] = (int)$entry['facility_id'];
        }

        $fkeys = array_keys($facilities);
        sort($fkeys, SORT_STRING);
        foreach ($fkeys as $fkey) {
            $info = $facilities[$fkey];
            if ($info['origin'] === 'existing' || $info['origin'] === 'split_keeper') {
                $key_to_id[$fkey] = $info['old_id'];
                continue;
            }
            if (isset($identity[$fkey])) {
                $key_to_id[$fkey] = (int)$identity[$fkey]['facility_id'];
                continue;
            }
            // Choose the unique_name now so the allocator can store it with the id.
            $sample = $copies[$info['copies'][0]]['doc'];
            $base = $sample['identification']['name'];
            $place = null;
            foreach ($info['copies'] as $idx) {
                if ($copies[$idx]['place'] !== null) { $place = $copies[$idx]['place']; break; }
            }
            $tries = array();
            if ($place !== null && strpos($place, 'C:') !== 0) {
                $tries[] = $base . ' (' . $place . ')';
                if ($sample['location']['city'] !== '') $tries[] = $base . ' (' . $sample['location']['city'] . ', ' . $place . ')';
            } elseif ($place !== null) {
                $tries[] = $base . ' (' . substr($place, 2) . ')';
            }
            $tries[] = $base;
            $chosen = null;
            foreach ($tries as $t) {
                if (!isset($unique_names_taken[mb_strtolower($t)])) { $chosen = $t; break; }
            }
            for ($n = 2; $chosen === null; $n++) {
                $t = $tries[0] . ' #' . $n;
                if (!isset($unique_names_taken[mb_strtolower($t)])) $chosen = $t;
            }
            $id = (int)call_user_func($allocate, $fkey, $chosen);
            $unique_names_taken[mb_strtolower($chosen)] = $id;
            $key_to_id[$fkey] = $id;
            $new_identities[$id] = array('identity_key' => $fkey, 'unique_name' => $chosen);
            $identity[$fkey] = array('facility_id' => $id, 'unique_name' => $chosen);
        }

        $copy_final = array();
        foreach ($facilities as $fkey => $info) {
            foreach ($info['copies'] as $idx) $copy_final[$idx] = $key_to_id[$fkey];
        }
        $split_ids = array();
        foreach ($splits as $old => $keys) {
            $split_ids[$old] = array_map(function ($k) use ($key_to_id) { return $key_to_id[$k]; }, $keys);
        }

        $legacy_id_map = array();
        foreach ($dangling_copies as $old => $idxs) {
            foreach ($idxs as $idx) {
                if (isset($copy_final[$idx])) $legacy_id_map[$old][$copy_final[$idx]] = true;
            }
            if (!isset($legacy_id_map[$old])) continue;
            $legacy_id_map[$old] = array_keys($legacy_id_map[$old]);
            $add_review($old, 'project_row_id_on_copies', sprintf(
                '%d copies carried facility_id %d, which is a project row, not a facility; they became facility id(s) %s',
                count($idxs), $old, implode(', ', $legacy_id_map[$old])
            ));
        }

        // -- 5. R2 field truth, R3 primary place, R4/R5 memberships -------------
        $scalar_paths = array(
            'identification.name', 'identification.currentName', 'identification.currentOperator',
            'location.text', 'location.country',
            'operatingPeriod.startYear', 'operatingPeriod.endYear', 'operatingPeriod.yearsOfOperation',
            'facilityDetails.type', 'facilityDetails.capacity', 'facilityDetails.currentCensus',
            'facilityDetails.ageRange.min', 'facilityDetails.ageRange.max', 'facilityDetails.gender',
            'facilityDetails.isPrivatelyOwned', 'documentFolderId',
            'provenance.sourceProject', 'provenance.sourceProjectId', 'provenance.sourceCategory',
            'provenance.kopProfileVersion',
        );
        $list_paths = array(
            'identification.otherNames', 'identification.pastNames', 'identification.currentOwners',
            'identification.otherOperators', 'identification.pastOperators', 'identification.knownReferrers',
            'identification.investors', 'location.additionalLocations', 'location.formerLocations',
            'operatingPeriod.notes', 'staff.administrator', 'staff.notableStaff', 'staff.pastTTIJobs',
            'accreditations.current', 'accreditations.past', 'memberships', 'certifications',
            'licensing', 'profileLinks', 'notes',
        );
        $map_paths = array('resources', 'treatmentTypes', 'philosophy', 'conditions', 'criticalIncidents', 'fieldNotes', 'legacy');

        $label = function (array $c) {
            $l = $c['source'] === 'facilities_master.ref' ? 'ref#' . $c['row_id']
                : ($c['source'] === 'locations_master' ? 'loc:' . $c['row_name'] : 'op:' . $c['row_name']);
            return $l . '@' . $c['updated_at'];
        };

        $pages = array();
        foreach (kop_facility_states() as $code => $upper) {
            if (in_array($code, array('PR', 'VI', 'GU'), true)) continue;
            $pages[] = array('kind' => 'state', 'key' => $upper, 'lower' => strtolower($upper), 'abbrev_lower' => strtolower($code));
        }
        $country_rows = array();
        foreach ($copies as $c) {
            if ($c['loc_row'] !== null && kop_facility_state_code($c['loc_row']) === null) $country_rows[$c['loc_row']] = true;
        }
        foreach (array_keys($country_rows) as $upper) {
            $pages[] = array('kind' => 'country', 'key' => $upper, 'lower' => strtolower($upper), 'abbrev_lower' => '');
        }

        $docs_out = array();
        $origins = array();
        $conflicts = array();
        $memberships = array();
        $wrong_text_pages = array();

        $by_id = array();
        foreach ($facilities as $fkey => $info) $by_id[$key_to_id[$fkey]] = $info;
        ksort($by_id, SORT_NUMERIC);

        foreach ($by_id as $fid => $info) {
            $members = $info['copies'];
            usort($members, function ($a, $b) use ($copies) {
                $cmp = strcmp($copies[$b]['updated_at'], $copies[$a]['updated_at']);
                if ($cmp !== 0) return $cmp;
                if ($copies[$a]['is_ref'] !== $copies[$b]['is_ref']) return (int)$copies[$b]['is_ref'] - (int)$copies[$a]['is_ref'];
                return $a - $b;
            });
            $docs = array();
            foreach ($members as $idx) $docs[$idx] = $copies[$idx]['doc'];

            $doc = kop_facility_blank_document();
            $doc['facility_id'] = $fid;

            foreach ($scalar_paths as $path) {
                $kept = null;
                $kept_from = null;
                foreach ($members as $idx) {
                    $value = kop_migration_get($docs[$idx], $path);
                    if (kop_migration_is_empty($value)) continue;
                    if ($kept === null) { $kept = $value; $kept_from = $idx; continue; }
                    if (kop_migration_value_key($value) !== kop_migration_value_key($kept)) {
                        $conflicts[] = array(
                            'facility_id' => $fid, 'field' => $path, 'value_kept' => $kept, 'value_dropped' => $value,
                            'sources' => $label($copies[$kept_from]) . ' > ' . $label($copies[$idx]),
                        );
                    }
                }
                if ($kept !== null) kop_migration_set($doc, $path, $kept);
            }

            // A non-Unknown status beats Unknown regardless of age.
            $status = 'Unknown';
            $status_from = null;
            foreach ($members as $idx) {
                $value = $docs[$idx]['operatingPeriod']['status'];
                if ($value === 'Unknown') continue;
                if ($status_from === null) { $status = $value; $status_from = $idx; }
                elseif ($value !== $status) {
                    $conflicts[] = array(
                        'facility_id' => $fid, 'field' => 'operatingPeriod.status', 'value_kept' => $status, 'value_dropped' => $value,
                        'sources' => $label($copies[$status_from]) . ' > ' . $label($copies[$idx]),
                    );
                }
            }
            $doc['operatingPeriod']['status'] = $status;

            // The street address travels as a unit.
            $address_from = null;
            foreach ($members as $idx) {
                if ($docs[$idx]['location']['raw'] !== '' || $docs[$idx]['location']['street'] !== '') { $address_from = $idx; break; }
            }
            if ($address_from === null) {
                foreach ($members as $idx) {
                    if ($docs[$idx]['location']['city'] !== '') { $address_from = $idx; break; }
                }
            }
            if ($address_from !== null) {
                foreach (array('raw', 'street', 'city', 'zip') as $part) {
                    $doc['location'][$part] = $docs[$address_from]['location'][$part];
                }
                foreach ($members as $idx) {
                    if ($idx === $address_from || $docs[$idx]['location']['raw'] === '') continue;
                    if (kop_migration_address_line_key($docs[$idx]['location']['raw']) !== kop_migration_address_line_key($doc['location']['raw'])) {
                        $conflicts[] = array(
                            'facility_id' => $fid, 'field' => 'location.raw',
                            'value_kept' => $doc['location']['raw'], 'value_dropped' => $docs[$idx]['location']['raw'],
                            'sources' => $label($copies[$address_from]) . ' > ' . $label($copies[$idx]),
                        );
                    }
                }
            }

            // R3: first copy with a place; the location row is the last resort.
            $place = null;
            $place_source = null;
            foreach ($members as $idx) {
                if ($copies[$idx]['place'] !== null) {
                    $place = $copies[$idx]['place'];
                    $place_source = $copies[$idx]['state_source'];
                    break;
                }
            }
            if ($place === null) {
                foreach ($members as $idx) {
                    if ($copies[$idx]['loc_row'] === null) continue;
                    $code = kop_facility_state_code($copies[$idx]['loc_row']);
                    $place = $code !== null ? $code : 'C:' . kop_facility_country_name($copies[$idx]['loc_row']);
                    $place_source = 'legacy_membership';
                    $add_review($fid, 'place_from_row_only', sprintf(
                        '"%s" has no address, state or country; placed by the %s array it sits in',
                        $doc['identification']['name'], $copies[$idx]['loc_row']
                    ));
                    break;
                }
            }
            if ($place !== null && strpos($place, 'C:') === 0) {
                $doc['location']['state'] = null;
                $doc['location']['country'] = substr($place, 2);
            } elseif ($place !== null) {
                $doc['location']['state'] = $place;
                $doc['location']['country'] = 'United States';
            }

            foreach ($list_paths as $path) {
                $lists = array();
                foreach ($members as $idx) $lists[] = kop_migration_get($docs[$idx], $path);
                kop_migration_set($doc, $path, kop_migration_union($lists));
            }
            foreach ($map_paths as $path) {
                $maps = array();
                foreach ($members as $idx) $maps[] = $docs[$idx][$path];
                $doc[$path] = kop_migration_merge_maps($maps);
            }
            foreach ($members as $idx) {
                if ($docs[$idx]['provenance']['sourceOperator'] !== null) {
                    $doc['provenance']['sourceOperator'] = $docs[$idx]['provenance']['sourceOperator'];
                    break;
                }
            }
            foreach ($members as $idx) {
                if ($docs[$idx]['provenance']['linkedFromRef']) $doc['provenance']['linkedFromRef'] = true;
            }
            $doc['provenance']['migratedAt'] = $migrated_at;

            $legacy_ids = array();
            if ($info['origin'] === 'split_new') $legacy_ids[] = $info['old_id'];
            foreach ($members as $idx) {
                $carried = $docs[$idx]['facility_id'];
                if ($carried !== null && $carried !== $fid && !isset($ref_idx[$carried])) $legacy_ids[] = $carried;
            }
            $doc['provenance']['legacyIds'] = array_values(array_unique($legacy_ids));

            if ($info['origin'] === 'existing' || $info['origin'] === 'split_keeper') {
                $doc['provenance']['uniqueName'] = $copies[$ref_idx[$fid]]['row_name'];
            } else {
                foreach ($identity as $entry) {
                    if ((int)$entry['facility_id'] === $fid) { $doc['provenance']['uniqueName'] = $entry['unique_name']; break; }
                }
            }
            $doc['identification']['nameKey'] = kop_facility_name_key($doc['identification']['name']);

            // Memberships from the merged document.
            $rows = array();
            foreach (kop_facility_derive_memberships($doc) as $m) {
                if ($m['location_key'] === 'UNKNOWN') continue;
                if ($m['role'] === 'current' && $place_source !== null) $m['source'] = $place_source;
                $rows[$m['location_key'] . '|' . $m['role']] = $m;
            }
            $present = array();
            foreach ($rows as $m) $present[$m['location_key']] = true;

            // R4: every location array a copy sits in stays a membership.
            foreach ($members as $idx) {
                $row_key = $copies[$idx]['loc_row'];
                if ($row_key === null || isset($present[$row_key])) continue;
                $rows[$row_key . '|current'] = array(
                    'location_key' => $row_key, 'role' => 'current', 'source' => 'legacy_membership',
                    'needs_review' => 1, 'review_reason' => 'array membership disagrees with address',
                );
                $present[$row_key] = true;
                $add_review($fid, 'membership_disagrees', sprintf(
                    '"%s" (%s) sits in the %s array; kept on that page, flagged',
                    $doc['identification']['name'], $doc['location']['state'] ?? ($doc['location']['country'] ?? 'no place'), $row_key
                ));
            }

            // R4 (continued): pages the legacy builders add from facilities_master
            // copies. Contradicting locationDetails: kept, flagged. A place the
            // text names: additional. Old substring match only: dropped (fixed).
            foreach ($members as $idx) {
                $fields = $copies[$idx]['text_match'];
                if ($fields === null) continue;
                foreach ($pages as $page) {
                    if (isset($present[$page['key']])) continue;
                    $evidence = kop_migration_text_match_page($fields, $page);
                    if ($evidence === null) continue;
                    if ($evidence === 'wrong_text') {
                        $wrong_text_pages[$fid][$page['key']] = $fields['location_raw'];
                        continue;
                    }
                    if ($evidence === 'text') {
                        $rows[$page['key'] . '|additional'] = array(
                            'location_key' => $page['key'], 'role' => 'additional', 'source' => 'additional_location',
                            'needs_review' => 0, 'review_reason' => '',
                        );
                        $present[$page['key']] = true;
                        continue;
                    }
                    $reason = 'locationDetails/address state disagrees with the parsed address';
                    $rows[$page['key'] . '|current'] = array(
                        'location_key' => $page['key'], 'role' => 'current', 'source' => 'legacy_membership',
                        'needs_review' => 1, 'review_reason' => $reason,
                    );
                    $present[$page['key']] = true;
                    $add_review($fid, 'membership_disagrees', sprintf(
                        '"%s" (%s, %s) on the %s page: %s',
                        $doc['identification']['name'],
                        $doc['location']['city'] !== '' ? $doc['location']['city'] : '?',
                        $doc['location']['state'] ?? ($doc['location']['country'] ?? 'no place'),
                        $page['key'], $reason
                    ));
                }
            }

            // R5: nowhere at all.
            if (!$rows) {
                $rows['UNKNOWN|unknown'] = array(
                    'location_key' => 'UNKNOWN', 'role' => 'unknown', 'source' => 'manual',
                    'needs_review' => 1, 'review_reason' => 'no location on any copy',
                );
                $add_review($fid, 'no_location', sprintf('"%s": no address, state, country or location array on any copy', $doc['identification']['name']));
            }
            foreach ($rows as $m) {
                $m['facility_id'] = $fid;
                $memberships[] = $m;
            }

            foreach (kop_facility_validate($doc) as $v) {
                if ($v['severity'] === 'error') $add_review($fid, 'validation_error', $v['path'] . ': ' . $v['message']);
            }

            $docs_out[$fid] = $doc;
            $origins[$fid] = $info['origin'];
        }

        // -- 6. Operators (kop_operators.id = the legacy row id) ----------------
        $operators = array();
        foreach ($operator_payloads as $row) {
            $payload = $row['json'];
            $data = isset($payload['data']) && is_array($payload['data']) ? $payload['data'] : array();
            if (isset($data['data']) && is_array($data['data'])) $data = array_merge($data['data'], $data);
            $operator = $payload['operator'] ?? ($data['operator'] ?? array());
            if (!is_array($operator)) $operator = array();

            $name = kop_facility_str($operator['name'] ?? '');
            if ($name === '') $name = kop_facility_str($payload['name'] ?? '');
            if ($name === '') $name = $row['unique_name'];

            // Referrer/transporter blocks are out of scope but never lost.
            $legacy_blocks = array();
            foreach (array($payload, $data) as $layer) {
                foreach ($layer as $k => $v) {
                    if (in_array($k, array('operator', 'facilities', 'data', 'facility', 'documentFolderId'), true)) continue;
                    if (!array_key_exists($k, $legacy_blocks)) $legacy_blocks[$k] = $v;
                }
            }
            $operators[(int)$row['id']] = array(
                'id'                 => (int)$row['id'],
                'legacy_master_id'   => (int)$row['id'],
                'unique_name'        => $row['unique_name'],
                'name'               => $name,
                'document_folder_id' => kop_facility_int($payload['documentFolderId'] ?? ($data['documentFolderId'] ?? null)),
                'json_data'          => array('operator' => $operator, 'legacy_blocks' => $legacy_blocks),
            );
        }

        $operator_facilities = array();
        foreach ($copies as $idx => $copy) {
            if ($copy['source'] !== 'facilities_master.operator' || !isset($copy_final[$idx])) continue;
            $key = $copy['row_id'] . '|' . $copy_final[$idx];
            if (isset($operator_facilities[$key])) continue;
            $operator_facilities[$key] = array(
                'operator_id' => $copy['row_id'], 'facility_id' => $copy_final[$idx],
                'relationship' => 'current', 'sort_order' => (int)$copy['index'],
            );
        }

        // -- 7. Links ------------------------------------------------------------
        $link_stats = array('checked' => 0, 'on_split_ids' => 0, 'repointed' => 0, 'kept' => 0, 'unresolved' => 0, 'dangling' => 0, 'to_operator' => 0);
        $operator_links = array();
        $link_repoints = array();
        $split_link_notes = array();
        $unique_to_id = array();
        foreach ($ref_idx as $id => $idx) $unique_to_id[mb_strtolower($copies[$idx]['row_name'])] = $id;
        $address_rows_seen = array();

        foreach (($input['links'] ?? array()) as $link) {
            if (!is_array($link)) continue;
            $link_stats['checked']++;
            $old_id = null;

            if ($link['kind'] === 'news' || $link['kind'] === 'lawsuit') {
                $old_id = (int)$link['facility_id'];
                if (!isset($docs_out[$old_id])) {
                    if (isset($legacy_id_map[$old_id]) && count($legacy_id_map[$old_id]) === 1) {
                        $link_stats['repointed']++;
                        $link_repoints[] = array('link_kind' => $link['kind'], 'link_id' => (int)$link['link_id'], 'from_facility_id' => $old_id, 'to_facility_id' => $legacy_id_map[$old_id][0], 'reason' => 'project row id');
                        continue;
                    }
                    if (isset($operators[$old_id])) {
                        // An article about an operator: intended, kop_operator_links.
                        $link_stats['to_operator']++;
                        $operator_links[] = array(
                            'link_kind' => $link['kind'], 'link_id' => (int)$link['link_id'], 'operator_id' => $old_id,
                            'operator_name' => $operators[$old_id]['name'], 'link_type' => $link['link_type'] ?? 'mentioned',
                            'title' => $link['title'] ?? '',
                        );
                        continue;
                    }
                    $link_stats['dangling']++;
                    $add_review($old_id, 'link_dangling', sprintf('%s #%s "%s" points at facility_id %d which has no facility row', $link['kind'], $link['link_id'], $link['title'] ?? '', $old_id));
                    continue;
                }
            } elseif ($link['kind'] === 'wiki') {
                $old_id = $unique_to_id[mb_strtolower((string)$link['facility_unique_name'])] ?? null;
            } elseif ($link['kind'] === 'address') {
                $old_id = $unique_to_id[mb_strtolower((string)$link['facility_name'])] ?? null;
            }
            if ($old_id === null || !isset($split_ids[$old_id])) continue;
            $link_stats['on_split_ids']++;

            if ($link['kind'] === 'address') {
                if (!isset($address_rows_seen[$old_id])) {
                    $address_rows_seen[$old_id] = true;
                    $add_review($old_id, 'address_rows_name_keyed', sprintf('kop_facility_addresses rows for "%s" are keyed by name and now cover %d facilities; re-seed after the split', $link['facility_name'], count($split_ids[$old_id])));
                }
                continue;
            }

            $places = kop_migration_places_in_text(($link['location'] ?? '') . ' ' . ($link['title'] ?? ''));
            $targets = array();
            foreach ($split_ids[$old_id] as $candidate) {
                $cp = kop_migration_place($docs_out[$candidate]);
                if ($cp !== null && in_array($cp, $places, true)) $targets[] = $candidate;
            }
            $text = sprintf('%s #%s "%s" (%s)', $link['kind'], $link['link_id'], $link['title'] ?? '', $link['location'] ?? '');
            if (count($targets) === 1 && $targets[0] === $old_id) {
                $link_stats['kept']++;
                $split_link_notes[$old_id][] = $text . ' stays on ' . $old_id;
            } elseif (count($targets) === 1) {
                $link_stats['repointed']++;
                $split_link_notes[$old_id][] = $text . ' -> ' . $targets[0];
                $link_repoints[] = array('link_kind' => $link['kind'], 'link_id' => $link['kind'] === 'wiki' ? (int)$link['link_id'] : (int)$link['link_id'], 'from_facility_id' => $old_id, 'to_facility_id' => $targets[0], 'reason' => 'identity split');
            } else {
                $link_stats['unresolved']++;
                $split_link_notes[$old_id][] = $text . ' unresolved, stays on ' . $old_id;
                $add_review($old_id, 'link_unresolved', $text . ': cannot tell which of ' . implode(', ', $split_ids[$old_id]) . ' it means');
            }
        }
        if (!isset($input['links'])) {
            $add_review(null, 'links_not_checked', 'no link records were supplied; link re-pointing was not planned');
        }

        return array(
            'docs'                => $docs_out,
            'origins'             => $origins,
            'memberships'         => $memberships,
            'operators'           => $operators,
            'operator_facilities' => array_values($operator_facilities),
            'operator_links'      => $operator_links,
            'link_repoints'       => $link_repoints,
            'splits'              => $split_ids,
            'split_link_notes'    => $split_link_notes,
            'legacy_id_map'       => $legacy_id_map,
            'wrong_text_pages'    => $wrong_text_pages,
            'conflicts'           => $conflicts,
            'review'              => $review,
            'new_identities'      => $new_identities,
            'json_errors'         => $json_errors,
            'stats'               => array(
                'copies'           => count($copies),
                'ref_rows'         => count($ref_idx),
                'max_legacy_id'    => $max_id,
                'resolution'       => $resolution,
                'links'            => $link_stats,
            ),
        );
    }
}

// ---------------------------------------------------------------------------
// Diff against a page baseline (the zero-loss gate)
// ---------------------------------------------------------------------------

if (!function_exists('kop_migration_diff_baseline')) {
    /**
     * Compare a plan's memberships with a baseline from
     * scripts/snapshot-location-pages.js.
     *
     * @return array {pass, missing, fixed, present, by_name, added, unknown, lines}
     */
    function kop_migration_diff_baseline(array $plan, array $baseline) {
        $docs = $plan['docs'];
        $page_ids = array();
        $page_names = array();
        foreach ($plan['memberships'] as $m) {
            $page_ids[$m['location_key']][$m['facility_id']] = true;
            $page_names[$m['location_key']][$docs[$m['facility_id']]['identification']['nameKey']][] = $m['facility_id'];
        }
        $id_map = function ($id) use ($plan, $docs) {
            if (isset($plan['splits'][$id])) return $plan['splits'][$id];
            if (isset($docs[$id])) return array($id);
            return $plan['legacy_id_map'][$id] ?? array();
        };

        $totals = array('missing' => 0, 'fixed' => 0, 'present' => 0, 'by_name' => 0, 'added' => 0);
        $summary = array();
        $details = array();
        $pages = $baseline['pages'];
        ksort($pages);

        foreach ($pages as $page_key => $page) {
            $location_key = mb_strtoupper($page['name']);
            $ids_here = $page_ids[$location_key] ?? array();
            $names_here = $page_names[$location_key] ?? array();
            $missing = array();
            $fixed = array();
            $matched = array();
            $present = 0;
            $by_name = 0;
            $tiles = 0;

            foreach ($page['facilities'] as $tile) {
                if (empty($tile['in_master'])) continue;
                $tiles++;
                $hit = false;
                foreach ($tile['facility_ids'] as $old) {
                    foreach ($id_map((int)$old) as $new) {
                        if (isset($ids_here[$new])) { $hit = true; $matched[$new] = true; }
                    }
                }
                if ($hit) { $present++; continue; }

                $wrong = null;
                foreach ($tile['facility_ids'] as $old) {
                    foreach ($id_map((int)$old) as $new) {
                        if (isset($plan['wrong_text_pages'][$new][$location_key])) $wrong = $plan['wrong_text_pages'][$new][$location_key];
                    }
                }
                if ($wrong !== null) { $fixed[] = $tile + array('location_text' => $wrong); continue; }

                $nk = kop_facility_name_key($tile['name']);
                if ($nk !== '' && isset($names_here[$nk])) {
                    foreach ($names_here[$nk] as $new) $matched[$new] = true;
                    $by_name++;
                    continue;
                }
                $missing[] = $tile;
            }
            foreach ($page['facilities'] as $tile) {
                if (empty($tile['in_master'])) continue;
                foreach ($tile['facility_ids'] as $old) {
                    foreach ($id_map((int)$old) as $new) $matched[$new] = true;
                }
            }
            $added = array();
            foreach (array_keys($ids_here) as $fid) {
                if (!isset($matched[$fid])) $added[] = $fid;
            }

            $totals['missing'] += count($missing);
            $totals['fixed'] += count($fixed);
            $totals['present'] += $present;
            $totals['by_name'] += $by_name;
            $totals['added'] += count($added);

            $summary[] = sprintf('%-32s tiles %4d  by id %4d  by name %3d  MISSING %3d  wrong-page fix %3d  added %3d',
                $page_key, $tiles, $present, $by_name, count($missing), count($fixed), count($added));

            if ($missing || $added || $fixed) {
                $details[] = '== ' . $page_key . ' (' . $location_key . ')';
                foreach ($missing as $tile) {
                    $details[] = sprintf('  MISSING  ids=%s  "%s"  %s  %s', implode(',', $tile['facility_ids']), $tile['name'], $tile['city'], $tile['status']);
                }
                foreach ($fixed as $tile) {
                    $details[] = sprintf('  removed  ids=%s  "%s"  location "%s" does not name this page (old matcher bug)', implode(',', $tile['facility_ids']), $tile['name'], $tile['location_text']);
                }
                foreach ($added as $fid) {
                    $d = $docs[$fid];
                    $details[] = sprintf('  added    id=%d  "%s"  %s  %s  (%s)', $fid, $d['identification']['name'], $d['location']['city'], $d['operatingPeriod']['status'], $plan['origins'][$fid]);
                }
            }
        }

        $unknown = array();
        foreach ($plan['memberships'] as $m) {
            if ($m['location_key'] === 'UNKNOWN') $unknown[] = $m['facility_id'];
        }

        $lines = array();
        $lines[] = 'Diff vs baseline (taken ' . ($baseline['meta']['taken_at'] ?? '?') . ')';
        $lines[] = 'Inspection-only tiles are excluded: the migration does not touch inspection data.';
        $lines[] = '';
        $lines[] = 'GATE: ' . ($totals['missing'] === 0 ? 'PASS' : 'FAIL') . ' - ' . $totals['missing'] . ' facilities missing from a page they are on today';
        $lines[] = sprintf('Tiles present by id %d, by name %d; facilities added to pages %d', $totals['present'], $totals['by_name'], $totals['added']);
        $lines[] = sprintf('Removed on purpose (wrong page from the old free-text matcher, fixed): %d', $totals['fixed']);
        $lines[] = '';
        $lines = array_merge($lines, $summary);
        $lines[] = '';
        $lines[] = 'Unknown location group (' . count($unknown) . '):';
        foreach ($unknown as $fid) {
            $lines[] = sprintf('  id=%d  "%s"  (%s)', $fid, $docs[$fid]['identification']['name'], $plan['origins'][$fid]);
        }
        $lines[] = '';
        $lines = array_merge($lines, $details);

        return $totals + array('pass' => $totals['missing'] === 0, 'unknown' => count($unknown), 'lines' => $lines);
    }
}

// ---------------------------------------------------------------------------
// Apply (PDO)
// ---------------------------------------------------------------------------

if (!function_exists('kop_migration_tables')) {
    /** Table names. facilities_v2 has no prefix: phase 5 renames it to facilities_master. */
    function kop_migration_tables($prefix) {
        return array(
            'facilities'          => 'facilities_v2',
            'facility_locations'  => $prefix . 'kop_facility_locations',
            'operators'           => $prefix . 'kop_operators',
            'operator_facilities' => $prefix . 'kop_operator_facilities',
            'operator_links'      => $prefix . 'kop_operator_links',
            'identity'            => $prefix . 'kop_facility_identity',
            'state'               => $prefix . 'kop_migration_state',
        );
    }
}

if (!function_exists('kop_migration_canonical')) {
    /**
     * Order-independent comparison form of a document. MySQL's JSON column
     * reorders object keys and an empty {} reads back as an empty array, so
     * a stored document never string-matches the one that was written.
     */
    function kop_migration_canonical($value) {
        if (!is_array($value)) return $value;
        if ($value === array()) return array();
        $is_list = array_keys($value) === range(0, count($value) - 1);
        if (!$is_list) ksort($value, SORT_STRING);
        foreach ($value as $k => $v) $value[$k] = kop_migration_canonical($v);
        return $value;
    }
}

if (!function_exists('kop_migration_first_new_id')) {
    /**
     * New v2 facility ids start here, far above legacy ids, so legacy ref rows
     * created during the bake period can never collide with them.
     */
    function kop_migration_first_new_id() {
        return 100000;
    }
}

if (!function_exists('kop_migration_create_tables')) {
    /** Create the v2 tables if missing. Additive only. */
    function kop_migration_create_tables(PDO $pdo, $prefix) {
        $t = kop_migration_tables($prefix);
        $pdo->exec("CREATE TABLE IF NOT EXISTS `{$t['facilities']}` (
            id INT UNSIGNED NOT NULL PRIMARY KEY,
            unique_name VARCHAR(255) NOT NULL,
            json_data JSON NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            schema_version TINYINT GENERATED ALWAYS AS (JSON_VALUE(json_data, '$.schema_version' RETURNING SIGNED)) STORED,
            name VARCHAR(255) GENERATED ALWAYS AS (LEFT(JSON_VALUE(json_data, '$.identification.name'), 255)) STORED,
            name_key VARCHAR(255) GENERATED ALWAYS AS (LEFT(JSON_VALUE(json_data, '$.identification.nameKey'), 255)) STORED,
            state CHAR(2) GENERATED ALWAYS AS (JSON_VALUE(json_data, '$.location.state')) STORED,
            city VARCHAR(120) GENERATED ALWAYS AS (LEFT(JSON_VALUE(json_data, '$.location.city'), 120)) STORED,
            country VARCHAR(80) GENERATED ALWAYS AS (LEFT(JSON_VALUE(json_data, '$.location.country'), 80)) STORED,
            status VARCHAR(20) GENERATED ALWAYS AS (JSON_VALUE(json_data, '$.operatingPeriod.status')) STORED,
            facility_type VARCHAR(120) GENERATED ALWAYS AS (LEFT(JSON_VALUE(json_data, '$.facilityDetails.type'), 120)) STORED,
            start_year SMALLINT GENERATED ALWAYS AS (JSON_VALUE(json_data, '$.operatingPeriod.startYear' RETURNING SIGNED)) STORED,
            end_year SMALLINT GENERATED ALWAYS AS (JSON_VALUE(json_data, '$.operatingPeriod.endYear' RETURNING SIGNED)) STORED,
            UNIQUE KEY uq_unique_name (unique_name),
            KEY idx_state_status (state, status),
            KEY idx_name_key (name_key)
        ) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $pdo->exec("CREATE TABLE IF NOT EXISTS `{$t['facility_locations']}` (
            facility_id INT UNSIGNED NOT NULL,
            location_key VARCHAR(80) NOT NULL,
            role ENUM('current','former','additional','unknown') NOT NULL DEFAULT 'current',
            source ENUM('address','location_details','wrapper_state','former_location','additional_location','legacy_membership','manual') NOT NULL,
            needs_review TINYINT(1) NOT NULL DEFAULT 0,
            review_reason VARCHAR(255) NULL,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (facility_id, location_key, role),
            KEY by_location (location_key, role)
        ) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $pdo->exec("CREATE TABLE IF NOT EXISTS `{$t['operators']}` (
            id INT UNSIGNED NOT NULL PRIMARY KEY,
            unique_name VARCHAR(255) NOT NULL,
            name VARCHAR(255) NOT NULL,
            json_data JSON NOT NULL,
            document_folder_id INT NULL,
            legacy_master_id INT NULL,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_unique_name (unique_name)
        ) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $pdo->exec("CREATE TABLE IF NOT EXISTS `{$t['operator_facilities']}` (
            operator_id INT UNSIGNED NOT NULL,
            facility_id INT UNSIGNED NOT NULL,
            relationship ENUM('current','past','other') NOT NULL DEFAULT 'current',
            sort_order INT NOT NULL DEFAULT 0,
            PRIMARY KEY (operator_id, facility_id),
            KEY by_facility (facility_id)
        ) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $pdo->exec("CREATE TABLE IF NOT EXISTS `{$t['operator_links']}` (
            operator_id INT UNSIGNED NOT NULL,
            link_kind ENUM('news','lawsuit') NOT NULL,
            link_id INT UNSIGNED NOT NULL,
            link_type ENUM('mentioned','primary','related') NOT NULL DEFAULT 'mentioned',
            created_by VARCHAR(255) NULL,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (operator_id, link_kind, link_id),
            KEY by_link (link_kind, link_id)
        ) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $pdo->exec("CREATE TABLE IF NOT EXISTS `{$t['identity']}` (
            identity_key VARCHAR(255) NOT NULL PRIMARY KEY,
            facility_id INT UNSIGNED NOT NULL,
            unique_name VARCHAR(255) NOT NULL,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_facility (facility_id)
        ) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $pdo->exec("CREATE TABLE IF NOT EXISTS `{$t['state']}` (
            state_key VARCHAR(64) NOT NULL PRIMARY KEY,
            state_value LONGTEXT NULL,
            updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
}

if (!function_exists('kop_migration_tables_exist')) {
    function kop_migration_tables_exist(PDO $pdo, $prefix) {
        $t = kop_migration_tables($prefix);
        $stmt = $pdo->prepare('SHOW TABLES LIKE ?');
        foreach (array('facilities', 'facility_locations', 'identity', 'state') as $k) {
            $stmt->execute(array($t[$k]));
            if (!$stmt->fetchColumn()) return false;
        }
        return true;
    }
}

if (!function_exists('kop_migration_state_get')) {
    function kop_migration_state_get(PDO $pdo, $prefix, $key, $default = null) {
        $t = kop_migration_tables($prefix);
        try {
            $stmt = $pdo->prepare("SELECT state_value FROM `{$t['state']}` WHERE state_key = ?");
            $stmt->execute(array($key));
            $value = $stmt->fetchColumn();
        } catch (Throwable $e) {
            return $default;
        }
        if ($value === false || $value === null) return $default;
        $decoded = json_decode($value, true);
        return json_last_error() === JSON_ERROR_NONE ? $decoded : $value;
    }
}

if (!function_exists('kop_migration_state_set')) {
    function kop_migration_state_set(PDO $pdo, $prefix, $key, $value) {
        $t = kop_migration_tables($prefix);
        $stmt = $pdo->prepare("INSERT INTO `{$t['state']}` (state_key, state_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE state_value = VALUES(state_value)");
        $stmt->execute(array($key, json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)));
    }
}

if (!function_exists('kop_migration_writes_to_v2')) {
    /** True once the write switch sends admin saves to the v2 tables (inc/facility-v2-writer.php). */
    function kop_migration_writes_to_v2(PDO $pdo, $prefix) {
        $state = kop_migration_state_get($pdo, $prefix, 'writes');
        return is_array($state) && ($state['mode'] ?? '') === 'v2';
    }
}

if (!function_exists('kop_migration_fingerprint')) {
    /** Cheap change detector for the legacy tables. */
    function kop_migration_fingerprint(PDO $pdo) {
        $out = array();
        foreach (array('facilities_master', 'locations_master') as $table) {
            $row = $pdo->query("SELECT COUNT(*) AS n, MAX(updated_at) AS u, SUM(CRC32(json_data)) AS c FROM `{$table}`")->fetch(PDO::FETCH_ASSOC);
            $out[$table] = array((int)$row['n'], (string)$row['u'], (string)$row['c']);
        }
        return md5(json_encode($out));
    }
}

if (!function_exists('kop_migration_load_rows')) {
    /** Legacy rows in the shape kop_migration_build_plan() expects. */
    function kop_migration_load_rows(PDO $pdo, $table) {
        $rows = array();
        $stmt = $pdo->query("SELECT id, unique_name, json_data, updated_at FROM `{$table}` ORDER BY id");
        while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $decoded = json_decode((string)$r['json_data'], true);
            $rows[] = array(
                'id' => (int)$r['id'], 'unique_name' => (string)$r['unique_name'],
                'json' => is_array($decoded) ? $decoded : null, 'updated_at' => (string)$r['updated_at'],
            );
        }
        return $rows;
    }
}

if (!function_exists('kop_migration_load_links')) {
    /** News, lawsuit, wiki and address link records, for link planning. */
    function kop_migration_load_links(PDO $pdo, $prefix) {
        $links = array();
        $queries = array(
            "SELECT 'news' AS kind, l.news_id AS link_id, l.facility_id, l.link_type, n.article_title AS title, n.article_location AS location
               FROM news_facility_links l LEFT JOIN news_submissions n ON n.id = l.news_id",
            "SELECT 'lawsuit' AS kind, l.lawsuit_id AS link_id, l.facility_id, l.link_type, s.case_name AS title, CONCAT_WS(' | ', s.jurisdiction, s.court) AS location
               FROM lawsuit_facility_links l LEFT JOIN lawsuits s ON s.id = l.lawsuit_id",
            "SELECT 'wiki' AS kind, w.id AS link_id, w.facility_unique_name, w.facility_link_status AS link_type, w.program_name AS title, w.city_state AS location
               FROM wiki_submissions w WHERE w.facility_unique_name IS NOT NULL AND w.facility_unique_name <> ''",
            "SELECT 'address' AS kind, fa.address_id AS link_id, fa.facility AS facility_name, fa.role AS link_type
               FROM `{$prefix}kop_facility_addresses` fa",
        );
        foreach ($queries as $sql) {
            try {
                foreach ($pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) as $row) $links[] = $row;
            } catch (Throwable $e) {
                // A table that does not exist on this install has no links.
            }
        }
        return $links;
    }
}

if (!function_exists('kop_migration_plan_from_db')) {
    /**
     * Build the plan from live tables.
     *
     * @param bool $allocate true: new ids are reserved in the identity table
     *                       (apply); false: provisional ids only (dry run).
     */
    function kop_migration_plan_from_db(PDO $pdo, $prefix, $allocate) {
        $t = kop_migration_tables($prefix);
        $identity = array();
        $next = kop_migration_first_new_id();
        if (kop_migration_tables_exist($pdo, $prefix)) {
            foreach ($pdo->query("SELECT identity_key, facility_id, unique_name FROM `{$t['identity']}`")->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $identity[$r['identity_key']] = array('facility_id' => (int)$r['facility_id'], 'unique_name' => $r['unique_name']);
                $next = max($next, (int)$r['facility_id'] + 1);
            }
        }
        $insert = $allocate
            ? $pdo->prepare("INSERT INTO `{$t['identity']}` (identity_key, facility_id, unique_name) VALUES (?, ?, ?)")
            : null;
        $allocator = function ($key, $unique_name) use (&$next, $insert) {
            $id = $next++;
            if ($insert) $insert->execute(array($key, $id, $unique_name));
            return $id;
        };

        return kop_migration_build_plan(array(
            'facility_rows' => kop_migration_load_rows($pdo, 'facilities_master'),
            'location_rows' => kop_migration_load_rows($pdo, 'locations_master'),
            'links'         => kop_migration_load_links($pdo, $prefix),
            'identity'      => $identity,
            'allocate_id'   => $allocator,
        ));
    }
}

if (!function_exists('kop_migration_apply_batch')) {
    /**
     * Write one batch of the plan. Idempotent: an unchanged document is not
     * rewritten, memberships for the batch are replaced as a set.
     *
     * Stages: 'facilities' (offset/batch over facility ids, ascending), then
     * 'finish' (operators, operator links, orphan removal, fingerprint).
     *
     * @return array {stage, offset, next_offset, done, written, unchanged, total}
     */
    function kop_migration_apply_batch(PDO $pdo, $prefix, array $plan, $stage, $offset, $batch) {
        $t = kop_migration_tables($prefix);
        $ids = array_keys($plan['docs']);
        sort($ids, SORT_NUMERIC);
        $total = count($ids);
        $result = array('stage' => $stage, 'offset' => $offset, 'total' => $total, 'written' => 0, 'unchanged' => 0, 'done' => false);

        if ($stage === 'facilities') {
            $slice = array_slice($ids, $offset, $batch);
            $by_facility = array();
            foreach ($plan['memberships'] as $m) {
                $by_facility[$m['facility_id']][] = $m;
            }

            $select = $pdo->prepare("SELECT json_data FROM `{$t['facilities']}` WHERE id = ?");
            $upsert = $pdo->prepare("INSERT INTO `{$t['facilities']}` (id, unique_name, json_data) VALUES (?, ?, ?)
                ON DUPLICATE KEY UPDATE unique_name = VALUES(unique_name), json_data = VALUES(json_data)");
            $delete_members = $pdo->prepare("DELETE FROM `{$t['facility_locations']}` WHERE facility_id = ?");
            $insert_member = $pdo->prepare("INSERT INTO `{$t['facility_locations']}` (facility_id, location_key, role, source, needs_review, review_reason)
                VALUES (?, ?, ?, ?, ?, ?)");

            $pdo->beginTransaction();
            try {
                foreach ($slice as $fid) {
                    $doc = $plan['docs'][$fid];
                    $select->execute(array($fid));
                    $existing = $select->fetchColumn();
                    if ($existing !== false) {
                        $old = json_decode($existing, true);
                        // Keep the first migration timestamp; compare without it.
                        if (is_array($old) && !empty($old['provenance']['migratedAt'])) {
                            $doc['provenance']['migratedAt'] = $old['provenance']['migratedAt'];
                        }
                        $same = is_array($old)
                            && json_encode(kop_migration_canonical($old)) === json_encode(kop_migration_canonical($doc));
                        if ($same) {
                            $result['unchanged']++;
                        } else {
                            $upsert->execute(array($fid, $doc['provenance']['uniqueName'], kop_facility_json_encode($doc)));
                            $result['written']++;
                        }
                    } else {
                        $upsert->execute(array($fid, $doc['provenance']['uniqueName'], kop_facility_json_encode($doc)));
                        $result['written']++;
                    }

                    $delete_members->execute(array($fid));
                    foreach ($by_facility[$fid] ?? array() as $m) {
                        $insert_member->execute(array($fid, $m['location_key'], $m['role'], $m['source'], (int)$m['needs_review'], $m['review_reason'] !== '' ? $m['review_reason'] : null));
                    }
                }
                $pdo->commit();
            } catch (Throwable $e) {
                $pdo->rollBack();
                throw $e;
            }

            $next = $offset + count($slice);
            $result['next_offset'] = $next;
            if ($next >= $total) {
                $result['next_stage'] = 'finish';
                $result['next_offset'] = 0;
            } else {
                $result['next_stage'] = 'facilities';
            }
            return $result;
        }

        if ($stage === 'finish') {
            $pdo->beginTransaction();
            try {
                $upsert_op = $pdo->prepare("INSERT INTO `{$t['operators']}` (id, unique_name, name, json_data, document_folder_id, legacy_master_id)
                    VALUES (?, ?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE unique_name = VALUES(unique_name), name = VALUES(name), json_data = VALUES(json_data),
                                            document_folder_id = VALUES(document_folder_id)");
                foreach ($plan['operators'] as $op) {
                    $upsert_op->execute(array(
                        $op['id'], $op['unique_name'], $op['name'],
                        json_encode($op['json_data'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                        $op['document_folder_id'], $op['legacy_master_id'],
                    ));
                }
                $pdo->exec("DELETE FROM `{$t['operator_facilities']}`");
                $insert_of = $pdo->prepare("INSERT INTO `{$t['operator_facilities']}` (operator_id, facility_id, relationship, sort_order) VALUES (?, ?, ?, ?)");
                foreach ($plan['operator_facilities'] as $of) {
                    $insert_of->execute(array($of['operator_id'], $of['facility_id'], $of['relationship'], $of['sort_order']));
                }
                // Operator links are only ever added here; the news and lawsuit
                // linkers own them after cutover.
                $insert_ol = $pdo->prepare("INSERT IGNORE INTO `{$t['operator_links']}` (operator_id, link_kind, link_id, link_type, created_by) VALUES (?, ?, ?, ?, 'migration')");
                foreach ($plan['operator_links'] as $ol) {
                    $insert_ol->execute(array($ol['operator_id'], $ol['link_kind'], $ol['link_id'], $ol['link_type']));
                }

                // Facilities that no longer exist in the legacy data.
                $keep = array_flip($ids);
                $orphans = array();
                foreach ($pdo->query("SELECT id FROM `{$t['facilities']}`")->fetchAll(PDO::FETCH_COLUMN) as $id) {
                    if (!isset($keep[(int)$id])) $orphans[] = (int)$id;
                }
                if ($orphans) {
                    $in = implode(',', array_map('intval', $orphans));
                    $pdo->exec("DELETE FROM `{$t['facilities']}` WHERE id IN ($in)");
                    $pdo->exec("DELETE FROM `{$t['facility_locations']}` WHERE facility_id IN ($in)");
                }
                $ops_keep = array_flip(array_keys($plan['operators']));
                foreach ($pdo->query("SELECT id FROM `{$t['operators']}`")->fetchAll(PDO::FETCH_COLUMN) as $id) {
                    if (!isset($ops_keep[(int)$id])) {
                        $pdo->prepare("DELETE FROM `{$t['operators']}` WHERE id = ?")->execute(array((int)$id));
                    }
                }
                $pdo->commit();
            } catch (Throwable $e) {
                $pdo->rollBack();
                throw $e;
            }

            kop_migration_state_set($pdo, $prefix, 'link_repoints', $plan['link_repoints']);
            kop_migration_state_set($pdo, $prefix, 'last_sync', array(
                'at'          => gmdate('c'),
                'fingerprint' => kop_migration_fingerprint($pdo),
                'facilities'  => $total,
                'memberships' => count($plan['memberships']),
                'removed'     => count($orphans),
            ));
            kop_migration_state_set($pdo, $prefix, 'applied', true);

            $result['removed'] = count($orphans);
            $result['done'] = true;
            return $result;
        }

        throw new InvalidArgumentException('Unknown stage ' . $stage);
    }
}

if (!function_exists('kop_migration_sync')) {
    /**
     * Full apply in one call: build the plan from live tables and write every
     * batch. Used by the bake-period sync and by the CLI tests. Skips the work
     * when the legacy tables have not changed since the last sync, unless
     * $force. Serialized with a MySQL named lock.
     *
     * @return array {skipped, reason?, facilities, written, unchanged, removed}
     */
    function kop_migration_sync(PDO $pdo, $prefix, $force = false) {
        $locked = (int)$pdo->query("SELECT GET_LOCK('kop_facility_v2_sync', 0)")->fetchColumn();
        if ($locked !== 1) return array('skipped' => true, 'reason' => 'another sync is running');
        try {
            kop_migration_create_tables($pdo, $prefix);
            // After the write switch the v2 tables are the source of truth;
            // re-deriving from the frozen legacy tables would undo every edit.
            if (kop_migration_writes_to_v2($pdo, $prefix)) {
                return array('skipped' => true, 'reason' => 'admin saves write the v2 tables');
            }
            if (!$force) {
                $last = kop_migration_state_get($pdo, $prefix, 'last_sync');
                if (is_array($last) && ($last['fingerprint'] ?? '') === kop_migration_fingerprint($pdo)) {
                    return array('skipped' => true, 'reason' => 'legacy tables unchanged');
                }
            }
            $plan = kop_migration_plan_from_db($pdo, $prefix, true);
            $totals = array('skipped' => false, 'facilities' => count($plan['docs']), 'written' => 0, 'unchanged' => 0, 'removed' => 0);
            $stage = 'facilities';
            $offset = 0;
            while (true) {
                $r = kop_migration_apply_batch($pdo, $prefix, $plan, $stage, $offset, 500);
                $totals['written'] += $r['written'];
                $totals['unchanged'] += $r['unchanged'];
                if (!empty($r['done'])) {
                    $totals['removed'] = $r['removed'];
                    break;
                }
                $stage = $r['next_stage'];
                $offset = $r['next_offset'];
            }
            return $totals;
        } finally {
            $pdo->query("SELECT RELEASE_LOCK('kop_facility_v2_sync')");
        }
    }
}
