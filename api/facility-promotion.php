<?php
/**
 * Shared helpers for facility promotion - keeping nested facility entries in
 * sync with `facilities_master` rows so every facility has a stable id.
 *
 * The bulk one-shot migration lives in api/promote-facilities-to-rows.php.
 * This file is loaded by api/save-master.php so EVERY save also stamps
 * facility_ids and creates new __facility_ref rows for any nested entries
 * that don't have one yet - preventing drift back to the no-id state.
 */

if (!function_exists('kop_promote_extract_name')) {
    function kop_promote_extract_name(array $facility): string {
        if (!empty($facility['identification']) && is_array($facility['identification'])) {
            $n = trim((string)($facility['identification']['name'] ?? ''));
            if ($n !== '') return $n;
            $n = trim((string)($facility['identification']['currentName'] ?? ''));
            if ($n !== '') return $n;
        }
        return trim((string)($facility['name'] ?? ''));
    }
}

if (!function_exists('kop_promote_state_abbreviations')) {
    /** Two-letter US state code -> uppercase state name (the locations_master key). */
    function kop_promote_state_abbreviations(): array {
        return [
            'AL' => 'ALABAMA', 'AK' => 'ALASKA', 'AZ' => 'ARIZONA', 'AR' => 'ARKANSAS',
            'CA' => 'CALIFORNIA', 'CO' => 'COLORADO', 'CT' => 'CONNECTICUT', 'DE' => 'DELAWARE',
            'FL' => 'FLORIDA', 'GA' => 'GEORGIA', 'HI' => 'HAWAII', 'ID' => 'IDAHO',
            'IL' => 'ILLINOIS', 'IN' => 'INDIANA', 'IA' => 'IOWA', 'KS' => 'KANSAS',
            'KY' => 'KENTUCKY', 'LA' => 'LOUISIANA', 'ME' => 'MAINE', 'MD' => 'MARYLAND',
            'MA' => 'MASSACHUSETTS', 'MI' => 'MICHIGAN', 'MN' => 'MINNESOTA', 'MS' => 'MISSISSIPPI',
            'MO' => 'MISSOURI', 'MT' => 'MONTANA', 'NE' => 'NEBRASKA', 'NV' => 'NEVADA',
            'NH' => 'NEW HAMPSHIRE', 'NJ' => 'NEW JERSEY', 'NM' => 'NEW MEXICO', 'NY' => 'NEW YORK',
            'NC' => 'NORTH CAROLINA', 'ND' => 'NORTH DAKOTA', 'OH' => 'OHIO', 'OK' => 'OKLAHOMA',
            'OR' => 'OREGON', 'PA' => 'PENNSYLVANIA', 'RI' => 'RHODE ISLAND', 'SC' => 'SOUTH CAROLINA',
            'SD' => 'SOUTH DAKOTA', 'TN' => 'TENNESSEE', 'TX' => 'TEXAS', 'UT' => 'UTAH',
            'VT' => 'VERMONT', 'VA' => 'VIRGINIA', 'WA' => 'WASHINGTON', 'WV' => 'WEST VIRGINIA',
            'WI' => 'WISCONSIN', 'WY' => 'WYOMING', 'DC' => 'DISTRICT OF COLUMBIA',
        ];
    }
}

if (!function_exists('kop_promote_state_bucket')) {
    /**
     * Canonical locations_master key ("ALABAMA") for a state value that may be
     * an abbreviation, a full name in any case, or trailing zip noise. Null
     * when the value is not a US state.
     */
    function kop_promote_state_bucket($state): ?string {
        if (!is_string($state)) return null;
        $s = strtoupper(trim(preg_replace('/[\s,]*\d{5}(?:-\d{4})?\s*$/', '', $state)));
        $s = trim($s, " .,");
        if ($s === '') return null;
        $map = kop_promote_state_abbreviations();
        if (isset($map[$s])) return $map[$s];
        if (in_array($s, $map, true)) return $s;
        return null;
    }
}

if (!function_exists('kop_promote_split_address')) {
    /**
     * Split "street, city, ST 12345" (or "city, ST") into [city, state].
     * Either part may be null when the string does not carry it.
     */
    function kop_promote_split_address(string $address): array {
        $parts = array_values(array_filter(array_map('trim', explode(',', $address)), 'strlen'));
        $n = count($parts);
        if ($n === 0) return [null, null];
        $last = $parts[$n - 1];
        // Bare state ("MA", "Massachusetts") with no city.
        if ($n === 1) {
            return [null, kop_promote_state_bucket($last) !== null ? $last : null];
        }
        // Last segment: "ST 12345", "ST", "State Name".
        if (preg_match('/^([A-Za-z .]+?)(?:\s+\d{5}(?:-\d{4})?)?$/', $last, $m)
            && kop_promote_state_bucket($m[1]) !== null) {
            return [$parts[$n - 2], trim($m[1])];
        }
        // Last segment is not a state (country, zip only): fall back to the
        // old behaviour of first segment as city.
        return [$parts[0], null];
    }
}

if (!function_exists('kop_promote_extract_state')) {
    function kop_promote_extract_state(array $facility): ?string {
        $candidates = [];
        if (!empty($facility['identification']) && is_array($facility['identification'])) {
            $candidates[] = $facility['identification']['state'] ?? null;
            $candidates[] = $facility['identification']['locationState'] ?? null;
        }
        if (!empty($facility['locationDetails']) && is_array($facility['locationDetails'])) {
            $candidates[] = $facility['locationDetails']['state'] ?? null;
        }
        if (!empty($facility['address']) && is_array($facility['address'])) {
            $candidates[] = $facility['address']['state'] ?? null;
        }
        $candidates[] = $facility['state'] ?? null;
        $candidates[] = $facility['locationState'] ?? null;

        foreach ($candidates as $c) {
            if (is_string($c)) {
                $s = trim($c);
                if ($s !== '') return $s;
            }
        }
        foreach (['location', 'address', 'cityState', 'city_state'] as $k) {
            if (!empty($facility[$k]) && is_string($facility[$k])) {
                [, $state] = kop_promote_split_address($facility[$k]);
                if ($state !== null) return $state;
            }
        }
        return null;
    }
}

if (!function_exists('kop_promote_extract_city')) {
    function kop_promote_extract_city(array $facility): ?string {
        if (!empty($facility['identification']) && is_array($facility['identification'])) {
            $c = trim((string)($facility['identification']['city'] ?? ''));
            if ($c !== '') return $c;
        }
        if (!empty($facility['locationDetails']) && is_array($facility['locationDetails'])) {
            $c = trim((string)($facility['locationDetails']['city'] ?? ''));
            if ($c !== '') return $c;
        }
        if (!empty($facility['address']) && is_array($facility['address'])) {
            $c = trim((string)($facility['address']['city'] ?? ''));
            if ($c !== '') return $c;
        }
        if (!empty($facility['city']) && is_string($facility['city'])) {
            $c = trim($facility['city']);
            if ($c !== '') return $c;
        }
        foreach (['location', 'address', 'cityState', 'city_state'] as $k) {
            if (!empty($facility[$k]) && is_string($facility[$k])) {
                [$city] = kop_promote_split_address($facility[$k]);
                if ($city !== null && $city !== '') return $city;
            }
        }
        return null;
    }
}

if (!function_exists('kop_allocate_unique_name')) {
    /**
     * Allocate a unique_name that doesn't collide in facilities_master.
     * Tries the bare name, then "<name> (<state>)", then "<name> (<city>, <state>)",
     * then numeric suffixes.
     */
    function kop_allocate_unique_name(PDO $pdo, string $name, ?string $state, ?string $city): string {
        $tries = [$name];
        if ($state) $tries[] = $name . ' (' . $state . ')';
        if ($city && $state) $tries[] = $name . ' (' . $city . ', ' . $state . ')';

        $check = $pdo->prepare("SELECT 1 FROM facilities_master WHERE LOWER(unique_name) = ? LIMIT 1");
        foreach ($tries as $candidate) {
            $check->execute([strtolower(trim($candidate))]);
            if (!$check->fetchColumn()) return $candidate;
        }
        $n = 2;
        while ($n < 1000) {
            $candidate = $name . ' #' . $n;
            $check->execute([strtolower($candidate)]);
            if (!$check->fetchColumn()) return $candidate;
            $n++;
        }
        throw new RuntimeException('unique_name allocation runaway for ' . $name);
    }
}

if (!function_exists('kop_promote_single_nested_facility')) {
    /**
     * Stamp facility_id on a single nested facility entry. Returns the id, or
     * null if the entry has no usable name.
     *
     * Resolution order:
     *   1. Honor an existing facility_id on the entry (no-op if already stamped).
     *   2. Look up facilities_master.unique_name for a case-insensitive match.
     *   3. Insert a new __facility_ref row and use its id.
     *
     * Mutates $facility in place so the caller can write back the updated entry.
     */
    function kop_promote_single_nested_facility(PDO $pdo, array &$facility): ?int {
        $name = kop_promote_extract_name($facility);
        if ($name === '') return null;

        if (!empty($facility['facility_id'])) {
            return (int)$facility['facility_id'];
        }

        // Exact name match.
        $stmt = $pdo->prepare("SELECT id FROM facilities_master WHERE LOWER(unique_name) = ? LIMIT 1");
        $stmt->execute([strtolower($name)]);
        $existing = $stmt->fetchColumn();
        if ($existing !== false && $existing !== null) {
            $facility['facility_id'] = (int)$existing;
            return (int)$existing;
        }

        // Auto-promote: insert a hidden __facility_ref row.
        $state = kop_promote_extract_state($facility);
        $city  = kop_promote_extract_city($facility);
        $uniqueName = kop_allocate_unique_name($pdo, $name, $state, $city);

        $payload = [
            'name'           => $uniqueName,
            'displayName'    => $name,
            'state'          => $state,
            'city'           => $city,
            '__facility_ref' => true,
            'data'           => [
                'facility' => $facility,
            ],
            'timestamp'      => date('c'),
        ];

        $ins = $pdo->prepare(
            "INSERT INTO facilities_master (unique_name, json_data, created_at, updated_at)
             VALUES (?, ?, NOW(), NOW())"
        );
        $ins->execute([
            $uniqueName,
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
        $newId = (int)$pdo->lastInsertId();
        $facility['facility_id'] = $newId;
        return $newId;
    }
}

if (!function_exists('kop_ensure_facility_ids_in_data')) {
    /**
     * Walk $data['facilities'][] and ensure every nested entry has a facility_id.
     * Mutates $data in place. Returns the count of newly-stamped entries.
     */
    function kop_ensure_facility_ids_in_data(PDO $pdo, array &$data): int {
        if (empty($data['facilities']) || !is_array($data['facilities'])) return 0;
        $stamped = 0;
        foreach ($data['facilities'] as $i => $facility) {
            if (!is_array($facility)) continue;
            $hadId = !empty($facility['facility_id']);
            $id = kop_promote_single_nested_facility($pdo, $facility);
            $data['facilities'][$i] = $facility; // write the mutated entry back
            if ($id !== null && !$hadId) $stamped++;
        }
        return $stamped;
    }
}

if (!function_exists('kop_promote_name_key')) {
    /** Loose name key for matching a ref against a location's nested entries. */
    function kop_promote_name_key(string $name): string {
        return preg_replace('/[^a-z0-9]+/', '', strtolower($name));
    }
}

if (!function_exists('kop_link_refs_to_locations')) {
    /**
     * Reverse link: make sure every __facility_ref row whose state is known
     * appears in that state's locations_master profile with its facility_id.
     *
     * Forward links (location entry -> facility_id) are stamped on every save
     * by kop_ensure_facility_ids_in_data(). Refs can still be missing from the
     * location side when they were created without a usable state, or by a
     * path that never cloned them into a state bucket. This pass:
     *   - stamps facility_id on a same-named location entry that lacks one,
     *   - otherwise appends a clone of the ref's facility to the profile,
     *   - creates the state profile row if the state has none yet.
     *
     * Cheap on the steady state: only refs absent from every profile are
     * decoded in full. Called at the end of every master save and by
     * api/backfill-location-facility-ids.php.
     *
     * @return array{refs_checked:int, already_linked:int, stamped:int, appended:int,
     *               locations_updated:string[], no_state:string[]}
     */
    function kop_link_refs_to_locations(PDO $pdo, bool $dry = false): array {
        $stats = [
            'refs_checked'      => 0,
            'already_linked'    => 0,
            'stamped'           => 0,
            'appended'          => 0,
            'locations_updated' => [],
            'no_state'          => [],
        ];

        // Every facility_id referenced from any location profile.
        $linked = [];
        $rows = $pdo->query(
            "SELECT jt.fid FROM locations_master l,
             JSON_TABLE(l.json_data, '$.data.facilities[*]' COLUMNS (fid INT PATH '$.facility_id')) jt
             WHERE jt.fid IS NOT NULL"
        )->fetchAll(PDO::FETCH_COLUMN);
        foreach ($rows as $fid) $linked[(int)$fid] = true;

        // Lightweight scan of the refs: id plus the fields a state can be read from.
        $refs = $pdo->query(
            "SELECT id, unique_name,
                    JSON_UNQUOTE(JSON_EXTRACT(json_data, '$.state')) AS state,
                    JSON_UNQUOTE(JSON_EXTRACT(json_data, '$.data.facility.location')) AS location,
                    JSON_UNQUOTE(JSON_EXTRACT(json_data, '$.data.facility.address')) AS address,
                    JSON_UNQUOTE(JSON_EXTRACT(json_data, '$.data.facility.locationDetails.state')) AS ld_state,
                    JSON_UNQUOTE(JSON_EXTRACT(json_data, '$.data.facility.identification.state')) AS id_state
             FROM facilities_master
             WHERE JSON_EXTRACT(json_data, '$.__facility_ref') = true"
        )->fetchAll(PDO::FETCH_ASSOC);

        $pending = []; // bucket => [ref ids]
        foreach ($refs as $ref) {
            $stats['refs_checked']++;
            $id = (int)$ref['id'];
            if (isset($linked[$id])) {
                $stats['already_linked']++;
                continue;
            }
            $bucket = null;
            foreach (['state', 'id_state', 'ld_state'] as $k) {
                $v = $ref[$k];
                if (is_string($v) && $v !== '' && $v !== 'null') {
                    $bucket = kop_promote_state_bucket($v);
                    if ($bucket !== null) break;
                }
            }
            if ($bucket === null) {
                foreach (['address', 'location'] as $k) {
                    $v = $ref[$k];
                    if (is_string($v) && $v !== '' && $v !== 'null') {
                        [, $st] = kop_promote_split_address($v);
                        $bucket = $st !== null ? kop_promote_state_bucket($st) : null;
                        if ($bucket !== null) break;
                    }
                }
            }
            if ($bucket === null) {
                $stats['no_state'][] = $ref['unique_name'];
                continue;
            }
            $pending[$bucket][] = $id;
        }

        if (empty($pending)) return $stats;

        $selectRef = $pdo->prepare("SELECT json_data FROM facilities_master WHERE id = ?");
        $selectLoc = $pdo->prepare("SELECT json_data FROM locations_master WHERE unique_name = ?");
        $upsertLoc = $pdo->prepare(
            "INSERT INTO locations_master (unique_name, json_data, updated_at)
             VALUES (:name, :json_insert, NOW())
             ON DUPLICATE KEY UPDATE json_data = :json_update, updated_at = NOW()"
        );

        foreach ($pending as $bucket => $ids) {
            $selectLoc->execute([$bucket]);
            $existing = $selectLoc->fetchColumn();
            $project = $existing ? json_decode($existing, true) : null;
            if (!is_array($project)) {
                $project = [
                    'name'                 => $bucket,
                    'data'                 => ['facilities' => [], 'referrerConsultants' => []],
                    'category'             => 'locations',
                    'currentFacilityIndex' => 0,
                ];
            }
            if (empty($project['data']['facilities']) || !is_array($project['data']['facilities'])) {
                $project['data']['facilities'] = [];
            }

            // Name index of entries that have no facility_id yet.
            $unstamped = [];
            foreach ($project['data']['facilities'] as $idx => $entry) {
                if (!is_array($entry) || !empty($entry['facility_id'])) continue;
                $key = kop_promote_name_key(kop_promote_extract_name($entry));
                if ($key !== '' && !isset($unstamped[$key])) $unstamped[$key] = $idx;
            }

            $touched = false;
            foreach ($ids as $id) {
                $selectRef->execute([$id]);
                $payload = json_decode((string)$selectRef->fetchColumn(), true);
                $facility = $payload['data']['facility'] ?? null;
                if (!is_array($facility)) continue;

                $key = kop_promote_name_key(kop_promote_extract_name($facility));
                if ($key !== '' && isset($unstamped[$key])) {
                    $project['data']['facilities'][$unstamped[$key]]['facility_id'] = $id;
                    unset($unstamped[$key]);
                    $stats['stamped']++;
                } else {
                    $clone = $facility;
                    $clone['facility_id'] = $id;
                    $clone['sourceCategory'] = $clone['sourceCategory'] ?? 'companies';
                    $clone['linkedFromRef'] = true;
                    $project['data']['facilities'][] = $clone;
                    $stats['appended']++;
                }
                $touched = true;
            }

            if ($touched) {
                $project['timestamp'] = date('c');
                if (!$dry) {
                    $json = json_encode($project, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    $upsertLoc->execute([
                        ':name'        => $bucket,
                        ':json_insert' => $json,
                        ':json_update' => $json,
                    ]);
                }
                $stats['locations_updated'][] = $bucket;
            }
        }

        return $stats;
    }
}
