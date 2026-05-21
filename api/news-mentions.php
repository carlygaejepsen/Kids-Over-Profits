<?php
/**
 * Shared helper for normalizing news_submissions.facilities_mentioned values.
 *
 * As of the news-linking migration, facilities_mentioned is stored as an array
 * of objects: [{name, facility_id}, ...]
 *
 * Older rows may still contain plain strings. This helper accepts either shape
 * and always returns a uniform list of objects so callers don't have to branch.
 */

if (!function_exists('kop_normalize_facility_mentions')) {
    /**
     * Normalize a facilities_mentioned value (string or already-decoded array)
     * into a list of ['name' => string, 'facility_id' => int|null] entries.
     *
     * @param mixed $value JSON string OR decoded array OR null.
     * @return array<int, array{name:string, facility_id:?int}>
     */
    function kop_normalize_facility_mentions($value): array {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            $value = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($value)) return [];

        $out = [];
        $seen = []; // dedupe by lowercase name
        foreach ($value as $item) {
            $name = '';
            $fid = null;

            if (is_string($item)) {
                $name = trim($item);
            } elseif (is_array($item)) {
                $name = trim((string)($item['name'] ?? ''));
                if (isset($item['facility_id']) && $item['facility_id'] !== '' && $item['facility_id'] !== null) {
                    $fid = (int)$item['facility_id'];
                    if ($fid <= 0) $fid = null;
                }
            }

            if ($name === '') continue;
            $key = strtolower($name);
            if (isset($seen[$key])) {
                // Prefer an entry that has a facility_id over one that doesn't.
                if ($fid !== null && $out[$seen[$key]]['facility_id'] === null) {
                    $out[$seen[$key]]['facility_id'] = $fid;
                }
                continue;
            }
            $seen[$key] = count($out);
            $out[] = ['name' => $name, 'facility_id' => $fid];
        }
        return $out;
    }
}

if (!function_exists('kop_facility_mention_names')) {
    /**
     * Pull just the name strings out of a facilities_mentioned value, for legacy
     * consumers that only care about the display names.
     *
     * @param mixed $value
     * @return string[]
     */
    function kop_facility_mention_names($value): array {
        $items = kop_normalize_facility_mentions($value);
        return array_map(static function ($m) { return $m['name']; }, $items);
    }
}
