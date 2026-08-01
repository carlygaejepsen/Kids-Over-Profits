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

require_once __DIR__ . '/facility-aliases.php';

if (!function_exists('kop_sync_news_facility_links')) {
    /**
     * Sync news_facility_links rows for a given news submission based on the
     * normalized facilities_mentioned list.
     *
     *   - Deletes any existing links whose facility_id is no longer in the list.
     *   - Inserts new links for facility_ids that aren't already linked.
     *
     * Mentions that arrive without a facility_id (free-text or AI-extracted names)
     * are resolved by name against facilities_master - matching unique_name, the
     * operating company's name, and the curated "Other Names / Former Names" aliases
     * via the shared index in api/facility-aliases.php. This is the same resolution
     * the retroactive backfill uses, so new articles link operators and renamed
     * facilities the same way. A name with no confident match stays text-only.
     */
    function kop_sync_news_facility_links(PDO $pdo, int $newsId, array $normalizedMentions, ?string $createdBy = null): void {
        $desiredIds = [];
        $unresolvedNames = [];
        foreach ($normalizedMentions as $m) {
            if (!empty($m['facility_id'])) {
                $desiredIds[(int)$m['facility_id']] = true;
            } elseif (!empty($m['name'])) {
                $unresolvedNames[$m['name']] = true;
            }
        }

        if (!empty($unresolvedNames)) {
            // Built once per request and cached; the index decodes facilities_master
            // so we avoid rebuilding it if this runs again in the same save.
            static $aliasIndex = null;
            if ($aliasIndex === null) {
                $aliasIndex = kop_build_facility_alias_index($pdo);
            }
            foreach (array_keys($unresolvedNames) as $name) {
                $fid = kop_resolve_name_to_facility((string)$name, $aliasIndex);
                if ($fid !== null) {
                    $desiredIds[$fid] = true;
                }
            }
        }

        $desiredIds = array_keys($desiredIds);

        // Only prune links this sync owns (link_type 'mentioned'). Links created
        // manually in the admin panel (primary/related) must survive a re-save
        // whose facilities_mentioned list doesn't include them.
        if (empty($desiredIds)) {
            $stmt = $pdo->prepare("DELETE FROM news_facility_links WHERE news_id = ? AND link_type = 'mentioned'");
            $stmt->execute([$newsId]);
        } else {
            $placeholders = implode(',', array_fill(0, count($desiredIds), '?'));
            $stmt = $pdo->prepare(
                "DELETE FROM news_facility_links WHERE news_id = ? AND link_type = 'mentioned' AND facility_id NOT IN ($placeholders)"
            );
            $stmt->execute(array_merge([$newsId], $desiredIds));

            $ins = $pdo->prepare(
                "INSERT IGNORE INTO news_facility_links (news_id, facility_id, link_type, created_by)
                 VALUES (?, ?, 'mentioned', ?)"
            );
            foreach ($desiredIds as $fid) {
                $ins->execute([$newsId, $fid, $createdBy]);
            }
        }
    }
}
