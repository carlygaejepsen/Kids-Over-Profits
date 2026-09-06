<?php
/**
 * Shared helpers for connecting lawsuits to the news articles that cover them.
 *
 * Mirrors lawsuit-facility-links.php:
 *   - lawsuit_news_links join table (lawsuit_id, news_id, link_type, match_reason)
 *   - links are derived automatically from signals both records already carry.
 *     The sync owns only link_type 'auto'; a 'manual' row inserted by an admin
 *     survives every re-sync.
 *
 * Match signals, strongest first (the first one that fires is recorded as
 * match_reason so the backfill report explains every link):
 *
 *   source_url   the article's URL is one of the lawsuit's source_urls
 *   case_number  the docket number appears in the article text
 *   case_name    the case caption appears in the article text
 *   plaintiff    the article shares a linked facility with the lawsuit AND
 *                names a distinctive plaintiff (Doe placeholders are ignored)
 *   filing_news  the article shares a linked facility, is typed 'lawsuit', and
 *                ran inside the filing window (30 days before to 120 days
 *                after filing_date)
 *
 * "Shares a linked facility" means lawsuit_facility_links and
 * news_facility_links point at the same facilities_master id, so the facility
 * sync must run before this one on either side.
 *
 * Both directions are covered so the join stays current no matter which
 * record changes:
 *   kop_sync_lawsuit_news_links()  one lawsuit  vs. every live article
 *   kop_sync_news_lawsuit_links()  one article  vs. every lawsuit
 *
 * PDO-only. Needs url-dedupe.php (kop_normalize_url) and facility-aliases.php
 * (kop_normalize_name_key), both pulled in below.
 */

require_once __DIR__ . '/url-dedupe.php';
require_once __DIR__ . '/facility-aliases.php';

if (!function_exists('kop_lawsuit_news_links_ensure_table')) {
    /** Create the join table on first use. */
    function kop_lawsuit_news_links_ensure_table(PDO $pdo): void {
        static $done = false;
        if ($done) return;
        $pdo->exec("CREATE TABLE IF NOT EXISTS `lawsuit_news_links` (
          `lawsuit_id` int(11) NOT NULL COMMENT 'FK -> lawsuits.id',
          `news_id` int(11) NOT NULL COMMENT 'FK -> news_submissions.id',
          `link_type` enum('auto','manual') NOT NULL DEFAULT 'auto' COMMENT 'auto rows are owned by the sync; manual rows survive it',
          `match_reason` varchar(40) DEFAULT NULL COMMENT 'source_url | case_number | case_name | plaintiff | filing_news',
          `created_by` varchar(255) DEFAULT NULL,
          `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (`lawsuit_id`,`news_id`),
          KEY `by_news` (`news_id`,`lawsuit_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='News articles that cover a lawsuit'");
        $done = true;
    }
}

if (!function_exists('kop_lawsuit_news_text_key')) {
    /**
     * Normalize free text for substring matching: lowercase, punctuation to
     * spaces, single spaces, padded with one space on each side so callers can
     * match whole tokens with strpos(" needle ").
     */
    function kop_lawsuit_news_text_key(string $s): string {
        $k = kop_normalize_name_key($s);
        return $k === '' ? '' : ' ' . $k . ' ';
    }
}

if (!function_exists('kop_lawsuit_news_json_list')) {
    /** Decode a JSON-array column to a list of trimmed strings (names or {name}). */
    function kop_lawsuit_news_json_list($value): array {
        if (is_array($value)) {
            $decoded = $value;
        } else {
            $decoded = json_decode((string)$value, true);
        }
        if (!is_array($decoded)) return [];
        $out = [];
        foreach ($decoded as $item) {
            if (is_array($item)) $item = $item['name'] ?? '';
            if (!is_string($item)) continue;
            $item = trim($item);
            if ($item !== '') $out[] = $item;
        }
        return $out;
    }
}

if (!function_exists('kop_lawsuit_news_needles')) {
    /**
     * Precompute everything the matcher needs from one lawsuit row.
     *
     * @param array $lawsuit     lawsuits row (JSON columns as stored strings or arrays)
     * @param int[] $facilityIds facilities_master ids from lawsuit_facility_links
     */
    function kop_lawsuit_news_needles(array $lawsuit, array $facilityIds): array {
        $urls = [];
        foreach (kop_normalize_urls(kop_lawsuit_news_json_list($lawsuit['source_urls'] ?? '[]')) as $u) {
            $urls[$u] = true;
        }

        // Docket numbers: need a few digits to be distinctive ("2:21-cv-00123"
        // keys to "2 21 cv 00123"); a bare "123" would match everywhere.
        $caseNumberKey = '';
        $rawNumber = trim((string)($lawsuit['case_number'] ?? ''));
        if ($rawNumber !== '' && preg_match_all('/\d/', $rawNumber) >= 4) {
            $caseNumberKey = kop_lawsuit_news_text_key($rawNumber);
            if (strlen(trim($caseNumberKey)) < 5) $caseNumberKey = '';
        }

        $caseNameKey = kop_lawsuit_news_text_key((string)($lawsuit['case_name'] ?? ''));
        if (strlen(trim($caseNameKey)) < 10) $caseNameKey = '';

        // Distinctive plaintiffs: at least two tokens and no anonymity
        // placeholder, so "Jane Doe", "J.D.", "et al" and "Estate of John Doe"
        // never link on their own.
        $plaintiffKeys = [];
        foreach (kop_lawsuit_news_json_list($lawsuit['plaintiffs'] ?? '[]') as $name) {
            $name = preg_replace('/\b(et\s+al\.?|estate\s+of|on\s+behalf\s+of|individually|as\s+(?:parent|guardian|next\s+friend)s?\s+of)\b/i', ' ', $name);
            $key = kop_lawsuit_news_text_key((string)$name);
            $tokens = preg_split('/\s+/', trim($key), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            if (count($tokens) < 2) continue;
            if (preg_match('/\b(doe|roe|does|roes|minor|minors|unknown|anonymous|plaintiff|plaintiffs|class|parents?|guardians?|students?|residents?)\b/', $key)) continue;
            $longest = max(array_map('strlen', $tokens));
            if ($longest < 4) continue;
            $plaintiffKeys[$key] = true;
        }

        $filingTs = null;
        if (!empty($lawsuit['filing_date']) && $lawsuit['filing_date'] !== '0000-00-00') {
            $ts = strtotime((string)$lawsuit['filing_date']);
            if ($ts) $filingTs = $ts;
        }

        return [
            'urls'            => $urls,
            'case_number_key' => $caseNumberKey,
            'case_name_key'   => $caseNameKey,
            'plaintiff_keys'  => array_keys($plaintiffKeys),
            'facility_ids'    => array_fill_keys(array_map('intval', $facilityIds), true),
            'filing_ts'       => $filingTs,
        ];
    }
}

if (!function_exists('kop_lawsuit_news_haystack')) {
    /** Searchable text for one news row (title, alternate title, summary, tags, people). */
    function kop_lawsuit_news_haystack(array $news): string {
        $parts = [
            (string)($news['article_title'] ?? ''),
            (string)($news['alternate_title'] ?? ''),
            (string)($news['summary'] ?? ''),
        ];
        foreach (['tags', 'staff_mentioned', 'survivors_mentioned'] as $col) {
            if (!empty($news[$col])) {
                $parts = array_merge($parts, kop_lawsuit_news_json_list($news[$col]));
            }
        }
        return kop_lawsuit_news_text_key(implode(' ', $parts));
    }
}

if (!function_exists('kop_lawsuit_news_match_reason')) {
    /**
     * Decide whether one article covers one lawsuit.
     *
     * @param array $needles         from kop_lawsuit_news_needles()
     * @param array $news            news_submissions row
     * @param int[] $newsFacilityIds facilities_master ids from news_facility_links
     * @return string|null match reason, or null for no link
     */
    function kop_lawsuit_news_match_reason(array $needles, array $news, array $newsFacilityIds): ?string {
        if (!empty($needles['urls'])) {
            $u = kop_normalize_url((string)($news['article_url'] ?? ''));
            if ($u !== null && isset($needles['urls'][$u])) return 'source_url';
        }

        $hay = $news['__haystack'] ?? kop_lawsuit_news_haystack($news);
        if ($hay === '') return null;

        if ($needles['case_number_key'] !== '' && strpos($hay, $needles['case_number_key']) !== false) {
            return 'case_number';
        }
        if ($needles['case_name_key'] !== '' && strpos($hay, $needles['case_name_key']) !== false) {
            return 'case_name';
        }

        $sharesFacility = false;
        if (!empty($needles['facility_ids'])) {
            foreach ($newsFacilityIds as $fid) {
                if (isset($needles['facility_ids'][(int)$fid])) { $sharesFacility = true; break; }
            }
        }
        if (!$sharesFacility) return null;

        foreach ($needles['plaintiff_keys'] as $pk) {
            if (strpos($hay, $pk) !== false) return 'plaintiff';
        }

        if ($needles['filing_ts'] !== null
            && (string)($news['article_type'] ?? '') === 'lawsuit'
            && !empty($news['publication_date']) && $news['publication_date'] !== '0000-00-00') {
            $pubTs = strtotime((string)$news['publication_date']);
            if ($pubTs) {
                $delta = $pubTs - $needles['filing_ts'];
                if ($delta >= -30 * 86400 && $delta <= 120 * 86400) return 'filing_news';
            }
        }

        return null;
    }
}

if (!function_exists('kop_lawsuit_news_facility_map')) {
    /**
     * news_id => [facility_id, ...] for the given news ids (or all rows when
     * $newsIds is null). Empty when news_facility_links doesn't exist.
     */
    function kop_lawsuit_news_facility_map(PDO $pdo, ?array $newsIds = null): array {
        $map = [];
        try {
            if ($newsIds === null) {
                $stmt = $pdo->query("SELECT news_id, facility_id FROM news_facility_links");
            } else {
                if (empty($newsIds)) return [];
                $ph = implode(',', array_fill(0, count($newsIds), '?'));
                $stmt = $pdo->prepare("SELECT news_id, facility_id FROM news_facility_links WHERE news_id IN ($ph)");
                $stmt->execute(array_map('intval', $newsIds));
            }
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $map[(int)$r['news_id']][] = (int)$r['facility_id'];
            }
        } catch (PDOException $e) {
            // Table absent on this environment: facility-overlap signals just won't fire.
        }
        return $map;
    }
}

if (!function_exists('kop_lawsuit_facility_map')) {
    /** lawsuit_id => [facility_id, ...] from lawsuit_facility_links (empty when the table is absent). */
    function kop_lawsuit_facility_map(PDO $pdo, ?array $lawsuitIds = null): array {
        $map = [];
        try {
            if ($lawsuitIds === null) {
                $stmt = $pdo->query("SELECT lawsuit_id, facility_id FROM lawsuit_facility_links");
            } else {
                if (empty($lawsuitIds)) return [];
                $ph = implode(',', array_fill(0, count($lawsuitIds), '?'));
                $stmt = $pdo->prepare("SELECT lawsuit_id, facility_id FROM lawsuit_facility_links WHERE lawsuit_id IN ($ph)");
                $stmt->execute(array_map('intval', $lawsuitIds));
            }
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $map[(int)$r['lawsuit_id']][] = (int)$r['facility_id'];
            }
        } catch (PDOException $e) {
            // Table absent: no facility overlap available.
        }
        return $map;
    }
}

if (!function_exists('kop_lawsuit_news_live_news')) {
    /**
     * Every approved/published article with the columns the matcher reads,
     * haystack precomputed. Cached per request so a backfill over many
     * lawsuits loads the news table once.
     */
    function kop_lawsuit_news_live_news(PDO $pdo, bool $refresh = false): array {
        static $cache = null;
        if ($cache !== null && !$refresh) return $cache;

        $rows = $pdo->query(
            "SELECT id, article_title, alternate_title, summary, tags, staff_mentioned, survivors_mentioned,
                    article_url, article_type, publication_date
             FROM news_submissions
             WHERE status IN ('approved','published')"
        )->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as &$r) {
            $r['__haystack'] = kop_lawsuit_news_haystack($r);
        }
        unset($r);

        $cache = [
            'rows'       => $rows,
            'facilities' => kop_lawsuit_news_facility_map($pdo),
        ];
        return $cache;
    }
}

if (!function_exists('kop_lawsuit_news_write_links')) {
    /**
     * Make the 'auto' rows for one side of the join equal $desired.
     *
     * @param string $ownCol   column identifying the record being synced ('lawsuit_id' or 'news_id')
     * @param string $otherCol the other side ('news_id' or 'lawsuit_id')
     * @param array  $desired  other_id => match_reason
     * @return int[] ids on the other side now linked (all link types)
     */
    function kop_lawsuit_news_write_links(PDO $pdo, string $ownCol, int $ownId, string $otherCol, array $desired, ?string $createdBy): array {
        kop_lawsuit_news_links_ensure_table($pdo);

        if (empty($desired)) {
            $pdo->prepare("DELETE FROM lawsuit_news_links WHERE `$ownCol` = ? AND link_type = 'auto'")->execute([$ownId]);
        } else {
            $ids = array_map('intval', array_keys($desired));
            $ph = implode(',', array_fill(0, count($ids), '?'));
            $pdo->prepare("DELETE FROM lawsuit_news_links WHERE `$ownCol` = ? AND link_type = 'auto' AND `$otherCol` NOT IN ($ph)")
                ->execute(array_merge([$ownId], $ids));

            // Keep the reason current on auto rows; never touch a manual row.
            $ins = $pdo->prepare(
                "INSERT INTO lawsuit_news_links (`$ownCol`, `$otherCol`, link_type, match_reason, created_by)
                 VALUES (?, ?, 'auto', ?, ?)
                 ON DUPLICATE KEY UPDATE match_reason = IF(link_type = 'auto', VALUES(match_reason), match_reason)"
            );
            foreach ($desired as $otherId => $reason) {
                $ins->execute([$ownId, (int)$otherId, $reason, $createdBy]);
            }
        }

        $all = $pdo->prepare("SELECT `$otherCol` FROM lawsuit_news_links WHERE `$ownCol` = ?");
        $all->execute([$ownId]);
        return array_map('intval', $all->fetchAll(PDO::FETCH_COLUMN));
    }
}

if (!function_exists('kop_lawsuit_news_matches_for_lawsuit')) {
    /**
     * news_id => reason for every live article that covers this lawsuit.
     * Pure lookup, no writes; the backfill dry run uses it directly.
     */
    function kop_lawsuit_news_matches_for_lawsuit(PDO $pdo, array $lawsuit, array $facilityIds): array {
        $needles = kop_lawsuit_news_needles($lawsuit, $facilityIds);
        $live = kop_lawsuit_news_live_news($pdo);
        $out = [];
        foreach ($live['rows'] as $news) {
            $nid = (int)$news['id'];
            $reason = kop_lawsuit_news_match_reason($needles, $news, $live['facilities'][$nid] ?? []);
            if ($reason !== null) $out[$nid] = $reason;
        }
        return $out;
    }
}

if (!function_exists('kop_sync_lawsuit_news_links')) {
    /**
     * Recompute this lawsuit's auto links against every live article. Call
     * after kop_sync_lawsuit_facility_links so facility overlap is current.
     *
     * @return int[] news ids now linked to this lawsuit (all link types)
     */
    function kop_sync_lawsuit_news_links(PDO $pdo, int $lawsuitId, ?string $createdBy = null): array {
        $stmt = $pdo->prepare("SELECT id, case_name, case_number, filing_date, plaintiffs, source_urls FROM lawsuits WHERE id = ?");
        $stmt->execute([$lawsuitId]);
        $lawsuit = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$lawsuit) return [];

        $facilityIds = kop_lawsuit_facility_map($pdo, [$lawsuitId])[$lawsuitId] ?? [];
        $desired = kop_lawsuit_news_matches_for_lawsuit($pdo, $lawsuit, $facilityIds);
        return kop_lawsuit_news_write_links($pdo, 'lawsuit_id', $lawsuitId, 'news_id', $desired, $createdBy);
    }
}

if (!function_exists('kop_sync_news_lawsuit_links')) {
    /**
     * Recompute one article's auto links against every lawsuit. An article
     * that isn't approved/published loses its auto links (rejected or
     * withdrawn coverage must not stay on a case card). Call after
     * kop_sync_news_facility_links.
     *
     * @return int[] lawsuit ids now linked to this article (all link types)
     */
    function kop_sync_news_lawsuit_links(PDO $pdo, int $newsId, ?string $createdBy = null): array {
        $stmt = $pdo->prepare(
            "SELECT id, article_title, alternate_title, summary, tags, staff_mentioned, survivors_mentioned,
                    article_url, article_type, publication_date, status
             FROM news_submissions WHERE id = ?"
        );
        $stmt->execute([$newsId]);
        $news = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$news) return [];

        if (!in_array((string)$news['status'], ['approved', 'published'], true)) {
            return kop_lawsuit_news_write_links($pdo, 'news_id', $newsId, 'lawsuit_id', [], $createdBy);
        }

        $news['__haystack'] = kop_lawsuit_news_haystack($news);
        $newsFacilityIds = kop_lawsuit_news_facility_map($pdo, [$newsId])[$newsId] ?? [];

        $lawsuits = $pdo->query("SELECT id, case_name, case_number, filing_date, plaintiffs, source_urls FROM lawsuits")
                        ->fetchAll(PDO::FETCH_ASSOC);
        $lawsuitFacilities = kop_lawsuit_facility_map($pdo);

        $desired = [];
        foreach ($lawsuits as $lawsuit) {
            $lid = (int)$lawsuit['id'];
            $needles = kop_lawsuit_news_needles($lawsuit, $lawsuitFacilities[$lid] ?? []);
            $reason = kop_lawsuit_news_match_reason($needles, $news, $newsFacilityIds);
            if ($reason !== null) $desired[$lid] = $reason;
        }
        return kop_lawsuit_news_write_links($pdo, 'news_id', $newsId, 'lawsuit_id', $desired, $createdBy);
    }
}
