<?php
/**
 * Shared URL-based duplicate detection for public submissions.
 *
 * Public submitters (and bots) frequently re-post the same article, bill, or
 * case. Before inserting a new row, an endpoint normalizes the URL(s) attached
 * to the submission and asks whether any existing (non-rejected) row already
 * references the same URL. If so, the endpoint blocks the insert with a 409 so
 * we don't accumulate duplicate entries.
 *
 * Matching is exact after normalization (see kop_normalize_url) and PER FIELD:
 * a submission's full_text_url is compared only against other rows' full_text_url,
 * official_url only against official_url, and so on. URLs are never cross-pooled
 * across fields, so two different bills that merely share a tracker/landing page
 * do not collide. A legacy flat-list form is still accepted for single-URL types
 * like news, where it matches against every configured column.
 */

if (!function_exists('kop_normalize_url')) {

    /**
     * Normalize a URL for comparison. Strips scheme, leading "www.", and trailing
     * slash; lowercases host+path; keeps the query string (it can be meaningful,
     * e.g. ?id=123). The #fragment is stripped only when $keepFragment is false —
     * legislative/court trackers that hash-route (e.g. .../bills#/bill/AB123) carry
     * the record's identity in the fragment, so those types keep it. Returns null
     * for empty/non-URL input.
     */
    function kop_normalize_url($url, bool $keepFragment = false): ?string {
        if (!is_string($url)) {
            return null;
        }
        $url = trim($url);
        if ($url === '') {
            return null;
        }
        $u = preg_replace('#^[a-z][a-z0-9+.\-]*://#i', '', $url); // strip scheme
        $u = preg_replace('#^www\.#i', '', $u);                   // strip www.
        if (!$keepFragment) {
            $u = preg_replace('/#.*$/', '', $u);                  // strip fragment
        }
        $u = rtrim($u, '/');
        $u = strtolower(trim($u));
        return $u !== '' ? $u : null;
    }

    /**
     * Normalize a single raw value (a string, or an array of strings) into a
     * de-duplicated list of normalized URLs.
     */
    function kop_normalize_urls($value, bool $keepFragment = false): array {
        $out = [];
        $candidates = is_array($value) ? $value : [$value];
        foreach ($candidates as $item) {
            $n = kop_normalize_url(is_string($item) ? $item : '', $keepFragment);
            if ($n !== null) {
                $out[$n] = true;
            }
        }
        return array_keys($out);
    }

    /**
     * Collect a de-duplicated list of normalized URLs from any number of raw
     * values. Uses fragment-stripping (the single-URL caller default, e.g. news).
     */
    function kop_collect_urls(...$values): array {
        $out = [];
        foreach ($values as $v) {
            foreach (kop_normalize_urls($v, false) as $n) {
                $out[$n] = true;
            }
        }
        return array_keys($out);
    }

    /** True for a non-empty associative array (i.e. keys are not 0..n-1). */
    function kop_is_assoc(array $a): bool {
        if ($a === []) {
            return false;
        }
        return array_keys($a) !== range(0, count($a) - 1);
    }

    /**
     * Find existing rows in $table that reference any of the per-column needles.
     *
     * @param array $needlesByColumn Map of url column => list of normalized URLs the
     *                               submission supplied for THAT column. Each stored
     *                               column value is confirmed only against its own
     *                               needle list, so fields never cross-match.
     * @return array List of ['id','status','title','url'] for confirmed matches.
     */
    function kop_find_url_duplicates(
        PDO $pdo,
        string $table,
        array $urlColumns,
        array $needlesByColumn,
        string $statusCol,
        array $ignoreStatuses,
        string $titleCol,
        bool $keepFragment = false
    ): array {
        // Flatten needles for the coarse SQL prefilter. Precise per-column
        // confirmation happens in PHP below, so LIKE over-matching is harmless.
        $allNeedles = [];
        foreach ($needlesByColumn as $list) {
            foreach ($list as $n) {
                $allNeedles[$n] = true;
            }
        }
        $allNeedles = array_keys($allNeedles);
        if (empty($allNeedles) || empty($urlColumns)) {
            return [];
        }

        $likeClauses = [];
        $params = [];
        foreach ($urlColumns as $col) {
            foreach ($allNeedles as $nu) {
                $likeClauses[] = "LOWER(`$col`) LIKE ?";
                $params[] = '%' . $nu . '%';
            }
        }
        $where = '(' . implode(' OR ', $likeClauses) . ')';

        if (!empty($ignoreStatuses)) {
            $ph = implode(',', array_fill(0, count($ignoreStatuses), '?'));
            $where .= " AND `$statusCol` NOT IN ($ph)";
            $params = array_merge($params, $ignoreStatuses);
        }

        $selectCols = array_map(static function ($c) { return "`$c`"; }, $urlColumns);
        $sql = "SELECT `id`, `$statusCol` AS _status, `$titleCol` AS _title, "
             . implode(', ', $selectCols)
             . " FROM `$table` WHERE $where LIMIT 50";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Extract URL strings from a single column: a scalar value, or every
        // string inside a JSON array (e.g. lawsuit source_urls/document_urls).
        $extractColumn = static function (array $row, string $col): array {
            $val = $row[$col] ?? '';
            $decoded = is_string($val) ? json_decode($val, true) : null;
            if (is_array($decoded)) {
                $urls = [];
                array_walk_recursive($decoded, static function ($v) use (&$urls) {
                    if (is_string($v)) { $urls[] = $v; }
                });
                return $urls;
            }
            if (is_string($val) && $val !== '') {
                return [$val];
            }
            return [];
        };

        // Pre-flip each column's needle list for O(1) membership tests.
        $needleFlips = [];
        foreach ($needlesByColumn as $col => $list) {
            if (!empty($list)) {
                $needleFlips[$col] = array_flip($list);
            }
        }

        $matches = [];
        foreach ($rows as $row) {
            $matchedUrl = null;
            foreach ($urlColumns as $col) {
                if (empty($needleFlips[$col])) {
                    continue; // submission supplied no URL for this field
                }
                foreach ($extractColumn($row, $col) as $candidate) {
                    $n = kop_normalize_url($candidate, $keepFragment);
                    if ($n !== null && isset($needleFlips[$col][$n])) {
                        $matchedUrl = $candidate;
                        break 2;
                    }
                }
            }
            if ($matchedUrl !== null) {
                $matches[] = [
                    'id'     => (int) $row['id'],
                    'status' => $row['_status'],
                    'title'  => $row['_title'],
                    'url'    => $matchedUrl,
                ];
            }
        }
        return $matches;
    }

    /**
     * Single source of truth for which table/columns identify a duplicate per
     * submission type. `keepFragment` is true for types whose trackers hash-route
     * (the fragment carries record identity). Shared by the submit endpoints and
     * the pre-submit check endpoint so they can never drift. Null for unknown types.
     */
    function kop_url_dedupe_config(string $type): ?array {
        switch ($type) {
            case 'news':
                return ['table' => 'news_submissions', 'urlColumns' => ['article_url'],
                        'statusCol' => 'status', 'ignoreStatuses' => ['rejected', 'deleted'],
                        'titleCol' => 'article_title', 'keepFragment' => false];
            case 'legislation':
                // A bill's official full-text URL is a genuinely unique per-bill
                // identity, so it is the ONLY dedupe key. official_url is deliberately
                // excluded: LegiScan/OpenStates/session-tracker pages are shared across
                // many bills and keying on them produced false-positive blocks. A
                // full-text link isn't hash-routed, so fragments are stripped.
                return ['table' => 'legislation', 'urlColumns' => ['full_text_url'],
                        'statusCol' => 'publication_status', 'ignoreStatuses' => ['rejected'],
                        'titleCol' => 'bill_title', 'keepFragment' => false];
            case 'lawsuit':
                return ['table' => 'lawsuits', 'urlColumns' => ['source_urls', 'document_urls'],
                        'statusCol' => 'publication_status', 'ignoreStatuses' => ['rejected'],
                        'titleCol' => 'case_name', 'keepFragment' => true];
            default:
                return null;
        }
    }

    /**
     * Look up the per-type config and return existing rows that duplicate the
     * submission's URLs. $input may be either:
     *   - a per-field map, e.g. ['full_text_url' => '…', 'official_url' => '…'],
     *     giving exact per-field matching (the preferred form); or
     *   - a flat list of URLs, matched against every configured column (legacy;
     *     fine for single-column types like news).
     * Empty array for unknown types / no URLs.
     */
    function kop_check_url_duplicates(PDO $pdo, string $type, array $input): array {
        $cfg = kop_url_dedupe_config($type);
        if (!$cfg) {
            return [];
        }
        $keepFragment = !empty($cfg['keepFragment']);

        $needlesByColumn = [];
        if (kop_is_assoc($input)) {
            // Per-field: normalize each field's raw value under this type's rules.
            foreach ($cfg['urlColumns'] as $col) {
                $needlesByColumn[$col] = array_key_exists($col, $input)
                    ? kop_normalize_urls($input[$col], $keepFragment)
                    : [];
            }
        } else {
            // Legacy flat list: applies to every configured column. Re-normalizing
            // is idempotent and lets keepFragment apply even to pre-normalized input.
            $flat = kop_normalize_urls($input, $keepFragment);
            foreach ($cfg['urlColumns'] as $col) {
                $needlesByColumn[$col] = $flat;
            }
        }

        $hasNeedles = false;
        foreach ($needlesByColumn as $list) {
            if (!empty($list)) { $hasNeedles = true; break; }
        }
        if (!$hasNeedles) {
            return [];
        }

        return kop_find_url_duplicates(
            $pdo, $cfg['table'], $cfg['urlColumns'], $needlesByColumn,
            $cfg['statusCol'], $cfg['ignoreStatuses'], $cfg['titleCol'], $keepFragment
        );
    }

    /**
     * If duplicates were found, emit a 409 response and exit. No-op otherwise.
     */
    function kop_block_if_duplicate(array $matches): void {
        if (empty($matches)) {
            return;
        }
        http_response_code(409);
        echo json_encode([
            'success'    => false,
            'error'      => 'duplicate',
            'message'    => 'This already appears to be in our records, so it was not submitted again. Thank you for helping!',
            'duplicates' => $matches,
        ]);
        exit;
    }
}
