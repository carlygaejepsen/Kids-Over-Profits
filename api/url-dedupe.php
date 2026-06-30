<?php
/**
 * Shared URL-based duplicate detection for public submissions.
 *
 * Public submitters (and bots) frequently re-post the same article, bill, or
 * case. Before inserting a new row, an endpoint normalizes the URL(s) attached
 * to the submission and asks whether any existing (non-rejected) row already
 * references the same URL. If so, the endpoint blocks the insert with a 409 so
 * we don't accumulate duplicate entries — this matters most for news, where the
 * article URL is a strong identity key.
 *
 * Matching is URL-only and tolerant of scheme/www/trailing-slash/query/fragment
 * differences (see kop_normalize_url).
 */

if (!function_exists('kop_normalize_url')) {

    /**
     * Normalize a URL for comparison. Strips scheme, leading "www.", trailing
     * slash, and #fragment; lowercases host+path; keeps the query string (it can
     * be meaningful, e.g. ?id=123). Returns null for empty/non-URL input.
     */
    function kop_normalize_url($url): ?string {
        if (!is_string($url)) {
            return null;
        }
        $url = trim($url);
        if ($url === '') {
            return null;
        }
        $u = preg_replace('#^[a-z][a-z0-9+.\-]*://#i', '', $url); // strip scheme
        $u = preg_replace('#^www\.#i', '', $u);                   // strip www.
        $u = preg_replace('/#.*$/', '', $u);                      // strip fragment
        $u = rtrim($u, '/');
        $u = strtolower(trim($u));
        return $u !== '' ? $u : null;
    }

    /**
     * Collect a de-duplicated list of normalized URLs from any number of raw
     * values, each of which may be a string or an array of strings.
     */
    function kop_collect_urls(...$values): array {
        $out = [];
        foreach ($values as $v) {
            $candidates = is_array($v) ? $v : [$v];
            foreach ($candidates as $item) {
                $n = kop_normalize_url(is_string($item) ? $item : '');
                if ($n !== null) {
                    $out[$n] = true;
                }
            }
        }
        return array_keys($out);
    }

    /**
     * Find existing rows in $table that reference any of $normalizedUrls.
     *
     * @param array         $urlColumns      Columns that may hold a URL — a scalar
     *                                        varchar or a JSON array of URLs.
     * @param string        $statusCol       Lifecycle status column.
     * @param array         $ignoreStatuses  Statuses that should NOT block (e.g.
     *                                        'rejected'), so a previously-rejected
     *                                        item can be re-submitted.
     * @param callable|null $extractor        function(array $row, array $cols): string[]
     *                                        Custom URL extractor (used for wiki,
     *                                        whose URLs live inside json_data).
     * @return array  List of ['id','status','title','url'] for confirmed matches.
     */
    function kop_find_url_duplicates(
        PDO $pdo,
        string $table,
        array $urlColumns,
        array $normalizedUrls,
        string $statusCol,
        array $ignoreStatuses,
        string $titleCol,
        ?callable $extractor = null
    ): array {
        if (empty($normalizedUrls) || empty($urlColumns)) {
            return [];
        }

        // Coarse SQL prefilter: any URL column text LIKE any normalized needle.
        // Precise confirmation happens in PHP below, so LIKE false-positives are
        // harmless (they just get filtered out).
        $likeClauses = [];
        $params = [];
        foreach ($urlColumns as $col) {
            foreach ($normalizedUrls as $nu) {
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

        // Default extractor: scalar value, or every string inside a JSON array.
        if ($extractor === null) {
            $extractor = static function (array $row, array $cols): array {
                $urls = [];
                foreach ($cols as $c) {
                    $val = $row[$c] ?? '';
                    $decoded = is_string($val) ? json_decode($val, true) : null;
                    if (is_array($decoded)) {
                        array_walk_recursive($decoded, static function ($v) use (&$urls) {
                            if (is_string($v)) { $urls[] = $v; }
                        });
                    } elseif (is_string($val) && $val !== '') {
                        $urls[] = $val;
                    }
                }
                return $urls;
            };
        }

        $needle = array_flip($normalizedUrls);
        $matches = [];
        foreach ($rows as $row) {
            $matchedUrl = null;
            foreach ($extractor($row, $urlColumns) as $candidate) {
                $n = kop_normalize_url($candidate);
                if ($n !== null && isset($needle[$n])) {
                    $matchedUrl = $candidate;
                    break;
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
     * Single source of truth for which columns/table identify a duplicate per
     * submission type. Shared by the submit endpoints and the pre-submit check
     * endpoint so they can never drift. Returns null for unsupported types.
     */
    function kop_url_dedupe_config(string $type): ?array {
        switch ($type) {
            case 'news':
                return ['table' => 'news_submissions', 'urlColumns' => ['article_url'],
                        'statusCol' => 'status', 'ignoreStatuses' => ['rejected', 'deleted'], 'titleCol' => 'article_title'];
            case 'legislation':
                return ['table' => 'legislation', 'urlColumns' => ['full_text_url', 'official_url'],
                        'statusCol' => 'publication_status', 'ignoreStatuses' => ['rejected'], 'titleCol' => 'bill_title'];
            case 'lawsuit':
                return ['table' => 'lawsuits', 'urlColumns' => ['source_urls', 'document_urls'],
                        'statusCol' => 'publication_status', 'ignoreStatuses' => ['rejected'], 'titleCol' => 'case_name'];
            default:
                return null;
        }
    }

    /**
     * Convenience wrapper: look up the per-type config and return any existing
     * rows matching $normalizedUrls. Empty array for unknown types / no URLs.
     */
    function kop_check_url_duplicates(PDO $pdo, string $type, array $normalizedUrls): array {
        $cfg = kop_url_dedupe_config($type);
        if (!$cfg || empty($normalizedUrls)) {
            return [];
        }
        return kop_find_url_duplicates(
            $pdo, $cfg['table'], $cfg['urlColumns'], $normalizedUrls,
            $cfg['statusCol'], $cfg['ignoreStatuses'], $cfg['titleCol']
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
