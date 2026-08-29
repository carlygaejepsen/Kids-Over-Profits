<?php
/**
 * News story identity: title-based duplicate detection and cross-outlet
 * story grouping.
 *
 * Two related problems share the normalization in this file:
 *
 * 1. DUPLICATE: the same article resubmitted — same outlet, same title, but a
 *    different URL (AMP/print/syndication-path variants of one page), which the
 *    URL guard in url-dedupe.php cannot catch. These are blocked with a 409.
 *
 * 2. SAME STORY, DIFFERENT OUTLETS: several outlets covering one event. These
 *    are welcome — they are clustered under a shared story_group_id so the feed
 *    can render one card with an "Also covered by" list instead of N near-
 *    identical cards. The group id is the id of the group's lowest-id article;
 *    every member (anchor included) carries it. NULL means standalone.
 *
 * The line between the two: identical normalized title on the SAME outlet is a
 * duplicate; a matching story on a DIFFERENT outlet is coverage.
 */

require_once __DIR__ . '/news-mentions.php';

if (!function_exists('kop_news_normalize_title')) {

    /** Lowercased, punctuation-free, whitespace-collapsed title key. */
    function kop_news_normalize_title(?string $title): string {
        $t = strtolower(trim((string) $title));
        $t = preg_replace('/[^a-z0-9]+/', ' ', $t);
        return trim(preg_replace('/\s+/', ' ', $t));
    }

    /** Outlet key: lowercased, punctuation-free, leading "the " dropped. */
    function kop_news_outlet_key(?string $outlet): string {
        $o = kop_news_normalize_title($outlet);
        return preg_replace('/^the\s+/', '', $o);
    }

    /**
     * Significant title tokens for similarity scoring: stopwords and words
     * shorter than 3 chars dropped, deduplicated, returned flipped
     * (token => true) for O(1) intersection.
     */
    function kop_news_title_tokens(?string $title): array {
        static $stop = null;
        if ($stop === null) {
            $stop = array_flip([
                'the', 'and', 'for', 'that', 'this', 'with', 'from', 'after',
                'over', 'into', 'about', 'amid', 'against', 'her', 'his', 'its',
                'their', 'was', 'are', 'were', 'has', 'have', 'had', 'been',
                'will', 'would', 'could', 'says', 'say', 'said', 'new', 'more',
                'who', 'what', 'when', 'where', 'why', 'how', 'not', 'now',
                'you', 'your', 'they', 'them', 'she', 'him', 'out', 'off',
                'former', 'report', 'reports', 'news',
            ]);
        }
        $out = [];
        foreach (explode(' ', kop_news_normalize_title($title)) as $w) {
            if (strlen($w) >= 3 && !isset($stop[$w])) {
                $out[$w] = true;
            }
        }
        return $out;
    }

    /**
     * Overlap coefficient between two flipped token sets:
     * |A ∩ B| / min(|A|, |B|). 0.0 when either side is empty.
     */
    function kop_news_token_overlap(array $a, array $b): float {
        if (empty($a) || empty($b)) {
            return 0.0;
        }
        $shared = count(array_intersect_key($a, $b));
        return $shared / min(count($a), count($b));
    }

    /** Normalized facility-name keys from a facilities_mentioned value. */
    function kop_news_facility_keys($facilitiesMentioned): array {
        $out = [];
        foreach (kop_facility_mention_names($facilitiesMentioned) as $name) {
            $k = kop_news_normalize_title($name);
            if ($k !== '') {
                $out[$k] = true;
            }
        }
        return $out;
    }

    /**
     * Precompute the comparison profile for one news row. $row needs
     * article_title, alternate_title, publication_name, publication_date,
     * facilities_mentioned.
     */
    function kop_news_story_profile(array $row): array {
        $tokenSets = [];
        foreach ([$row['article_title'] ?? '', $row['alternate_title'] ?? ''] as $t) {
            $tokens = kop_news_title_tokens($t);
            if (!empty($tokens)) {
                $tokenSets[] = $tokens;
            }
        }
        $date = null;
        if (!empty($row['publication_date'])) {
            $ts = strtotime($row['publication_date']);
            $date = $ts !== false ? $ts : null;
        }
        return [
            'tokenSets'  => $tokenSets,
            'date'       => $date,
            'facilities' => kop_news_facility_keys($row['facilities_mentioned'] ?? '[]'),
        ];
    }

    /**
     * Are two profiles the same story (as told by different articles)?
     *
     * Requires publication dates within 14 days when both are known, then
     * either strong title agreement (>= 60% of the shorter title's significant
     * tokens shared, with at least 3 tokens on each side) or a shared facility
     * plus moderate title agreement. Alternate titles participate: the best
     * pairwise score across (title|alt) x (title|alt) counts.
     */
    function kop_news_same_story(array $pa, array $pb): bool {
        if ($pa['date'] !== null && $pb['date'] !== null
            && abs($pa['date'] - $pb['date']) > 14 * 86400) {
            return false;
        }

        $best = 0.0;
        $bestShared = 0;
        $bestMinCount = 0;
        foreach ($pa['tokenSets'] as $ta) {
            foreach ($pb['tokenSets'] as $tb) {
                $overlap = kop_news_token_overlap($ta, $tb);
                if ($overlap > $best) {
                    $best = $overlap;
                    $bestShared = count(array_intersect_key($ta, $tb));
                    $bestMinCount = min(count($ta), count($tb));
                }
            }
        }

        if ($best >= 0.6 && $bestMinCount >= 3) {
            return true;
        }

        $sharedFacilities = array_intersect_key($pa['facilities'], $pb['facilities']);
        if (empty($sharedFacilities)) {
            return false;
        }

        // Facility branch: the facility's own name appearing in both titles is
        // not evidence of the same story (an arrest and a lawsuit at one
        // facility share those words). Score only the remaining tokens.
        $facilityTokens = [];
        foreach (array_keys($sharedFacilities) as $name) {
            $facilityTokens += kop_news_title_tokens($name);
        }
        $best = 0.0;
        $bestShared = 0;
        foreach ($pa['tokenSets'] as $ta) {
            $ta = array_diff_key($ta, $facilityTokens);
            foreach ($pb['tokenSets'] as $tb) {
                $tb = array_diff_key($tb, $facilityTokens);
                $overlap = kop_news_token_overlap($ta, $tb);
                if ($overlap > $best) {
                    $best = $overlap;
                    $bestShared = count(array_intersect_key($ta, $tb));
                }
            }
        }
        return $best >= 0.3 && $bestShared >= 2;
    }

    /**
     * Existing rows that are the SAME ARTICLE as the submission: identical
     * normalized title (against stored article_title or alternate_title) on
     * the same outlet. Rejected/deleted rows don't count, so resubmitting
     * something previously rejected stays possible. Returns the same
     * ['id','status','title','url'] shape as kop_find_url_duplicates so
     * kop_block_if_duplicate can consume it.
     */
    function kop_news_find_title_duplicates(
        PDO $pdo,
        ?string $title,
        ?string $outlet,
        int $excludeId = 0
    ): array {
        $titleKey = kop_news_normalize_title($title);
        if ($titleKey === '') {
            return [];
        }
        $outletKey = kop_news_outlet_key($outlet);

        // Coarse prefilter on outlet or exact raw title; precise confirmation
        // (punctuation-insensitive) happens in PHP below.
        $sql = "SELECT id, article_title, alternate_title, publication_name, article_url, status
                FROM news_submissions
                WHERE status NOT IN ('rejected', 'deleted')
                  AND (LOWER(TRIM(publication_name)) = ? OR LOWER(article_title) = ? OR LOWER(alternate_title) = ?)
                LIMIT 500";
        $stmt = $pdo->prepare($sql);
        $rawTitle = strtolower(trim((string) $title));
        $stmt->execute([strtolower(trim((string) $outlet)), $rawTitle, $rawTitle]);

        $matches = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if ($excludeId > 0 && (int) $row['id'] === $excludeId) {
                continue;
            }
            if (kop_news_outlet_key($row['publication_name']) !== $outletKey) {
                continue;
            }
            $titleMatch = kop_news_normalize_title($row['article_title']) === $titleKey
                || kop_news_normalize_title($row['alternate_title']) === $titleKey;
            if ($titleMatch) {
                $matches[] = [
                    'id'     => (int) $row['id'],
                    'status' => $row['status'],
                    'title'  => $row['article_title'],
                    'url'    => $row['article_url'],
                ];
            }
        }
        return $matches;
    }

    /**
     * Match a just-saved row against existing coverage and assign/merge its
     * story group. Returns the group id, or null when the row stays standalone.
     * Tolerates a database that predates the story_group_id migration (no-op).
     */
    function kop_news_assign_story_group(PDO $pdo, int $id): ?int {
        try {
            $stmt = $pdo->prepare(
                "SELECT id, article_title, alternate_title, publication_name,
                        publication_date, facilities_mentioned, story_group_id
                 FROM news_submissions WHERE id = ?"
            );
            $stmt->execute([$id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                return null;
            }
            $profile = kop_news_story_profile($row);

            // Candidates: live rows near this one's publication date. Undated
            // rows on either side fall back to comparing recent rows only.
            $sql = "SELECT id, article_title, alternate_title, publication_name,
                           publication_date, facilities_mentioned, story_group_id
                    FROM news_submissions
                    WHERE id != ? AND status NOT IN ('rejected', 'deleted')";
            $params = [$id];
            if ($profile['date'] !== null) {
                $sql .= " AND (publication_date IS NULL OR publication_date BETWEEN ? AND ?)";
                $params[] = date('Y-m-d', $profile['date'] - 14 * 86400);
                $params[] = date('Y-m-d', $profile['date'] + 14 * 86400);
            }
            $sql .= " ORDER BY id DESC LIMIT 400";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);

            $matchedGids = [];      // effective gids of matched rows
            $ungroupedIds = [];     // matched rows with no group yet
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $cand) {
                if (!kop_news_same_story($profile, kop_news_story_profile($cand))) {
                    continue;
                }
                if (!empty($cand['story_group_id'])) {
                    $matchedGids[(int) $cand['story_group_id']] = true;
                } else {
                    $ungroupedIds[] = (int) $cand['id'];
                    $matchedGids[(int) $cand['id']] = true;
                }
            }

            if (empty($matchedGids)) {
                // No coverage found. Clear a stale group from a prior edit.
                if (!empty($row['story_group_id'])) {
                    $pdo->prepare("UPDATE news_submissions SET story_group_id = NULL WHERE id = ?")
                        ->execute([$id]);
                }
                return null;
            }

            $gids = array_keys($matchedGids);
            $gid = min($gids);

            // Merge every matched group (and each matched ungrouped row —
            // including anchors, whose id IS their gid) onto the smallest gid.
            $ph = implode(',', array_fill(0, count($gids), '?'));
            $pdo->prepare("UPDATE news_submissions SET story_group_id = ? WHERE story_group_id IN ($ph)")
                ->execute(array_merge([$gid], $gids));
            if (!empty($ungroupedIds)) {
                $ph = implode(',', array_fill(0, count($ungroupedIds), '?'));
                $pdo->prepare("UPDATE news_submissions SET story_group_id = ? WHERE id IN ($ph)")
                    ->execute(array_merge([$gid], $ungroupedIds));
            }
            $pdo->prepare("UPDATE news_submissions SET story_group_id = ? WHERE id = ?")
                ->execute([$gid, $id]);
            return $gid;
        } catch (PDOException $e) {
            // Grouping is an enhancement; never let it fail a save (e.g. the
            // story_group_id column not migrated yet).
            error_log('kop_news_assign_story_group: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Re-cluster every live news row from scratch. Returns
     * ['articles' => n, 'groups' => n, 'grouped_articles' => n].
     */
    function kop_news_rebuild_story_groups(PDO $pdo): array {
        $rows = $pdo->query(
            "SELECT id, article_title, alternate_title, publication_name,
                    publication_date, facilities_mentioned
             FROM news_submissions
             WHERE status NOT IN ('rejected', 'deleted')
             ORDER BY id ASC"
        )->fetchAll(PDO::FETCH_ASSOC);

        $profiles = [];
        foreach ($rows as $row) {
            $profiles[(int) $row['id']] = kop_news_story_profile($row);
        }

        // Union-find over pairwise same-story matches. The date-window check
        // inside kop_news_same_story keeps false positives down; the sort by
        // date below keeps the pairwise loop from comparing across the whole
        // archive when dates are present.
        $ids = array_keys($profiles);
        usort($ids, static function ($a, $b) use ($profiles) {
            $da = $profiles[$a]['date'];
            $db = $profiles[$b]['date'];
            if ($da === $db) { return $a <=> $b; }
            if ($da === null) { return 1; }
            if ($db === null) { return -1; }
            return $da <=> $db;
        });

        $parent = [];
        $find = function ($x) use (&$parent, &$find) {
            while (($parent[$x] ?? $x) !== $x) {
                $parent[$x] = $parent[$parent[$x]] ?? $parent[$x];
                $x = $parent[$x];
            }
            return $x;
        };
        $union = function ($a, $b) use (&$parent, $find) {
            $ra = $find($a);
            $rb = $find($b);
            if ($ra !== $rb) {
                $parent[max($ra, $rb)] = min($ra, $rb);
            }
        };

        $n = count($ids);
        for ($i = 0; $i < $n; $i++) {
            $pi = $profiles[$ids[$i]];
            for ($j = $i + 1; $j < $n; $j++) {
                $pj = $profiles[$ids[$j]];
                // Sorted by date: once past the window, no later dated row matches.
                if ($pi['date'] !== null && $pj['date'] !== null
                    && $pj['date'] - $pi['date'] > 14 * 86400) {
                    break;
                }
                if (kop_news_same_story($pi, $pj)) {
                    $union($ids[$i], $ids[$j]);
                }
            }
        }

        // Members per component root (root = min id in the group).
        $components = [];
        foreach ($ids as $id) {
            $components[$find($id)][] = $id;
        }

        $pdo->exec("UPDATE news_submissions SET story_group_id = NULL");
        $set = $pdo->prepare("UPDATE news_submissions SET story_group_id = ? WHERE id = ?");
        $groups = 0;
        $groupedArticles = 0;
        foreach ($components as $root => $members) {
            if (count($members) < 2) {
                continue;
            }
            $groups++;
            foreach ($members as $memberId) {
                $set->execute([$root, $memberId]);
                $groupedArticles++;
            }
        }

        return [
            'articles'         => count($rows),
            'groups'           => $groups,
            'grouped_articles' => $groupedArticles,
        ];
    }
}
