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
                if (preg_match('/,\s*([A-Za-z .]+?)\s*$/', trim($facility[$k]), $m)) {
                    return trim($m[1]);
                }
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
        if (!empty($facility['city']) && is_string($facility['city'])) {
            $c = trim($facility['city']);
            if ($c !== '') return $c;
        }
        foreach (['location', 'address', 'cityState', 'city_state'] as $k) {
            if (!empty($facility[$k]) && is_string($facility[$k])) {
                if (preg_match('/^\s*([^,]+),/', trim($facility[$k]), $m)) {
                    return trim($m[1]);
                }
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
