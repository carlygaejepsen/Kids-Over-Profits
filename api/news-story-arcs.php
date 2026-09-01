<?php
/**
 * Ongoing story arcs: big, long-running stories that span many distinct
 * articles over months (a lawsuit's filings and reactions, a facility's
 * closure saga).
 *
 * Distinct from story_group_id (news-story-groups.php), which clusters the
 * SAME event covered by different outlets inside a 14-day window. An arc is
 * a curated narrative: created by an admin in api/manage-story-arcs.php,
 * membership assigned manually there and automatically here via each arc's
 * match_terms (newline-separated phrases; an article whose title/summary/
 * facilities contain one is attached on save).
 *
 * Tables: news_story_arcs + news_submissions.story_arc_id (see
 * api/update-schema.php). All helpers tolerate a database that predates the
 * migration — arc assignment is an enhancement and must never fail a save.
 */

require_once __DIR__ . '/news-mentions.php';

if (!function_exists('kop_news_arc_slug')) {

    /** URL slug for an arc title: lowercase, hyphenated, trimmed. */
    function kop_news_arc_slug(?string $title): string {
        $s = strtolower(trim((string) $title));
        $s = preg_replace('/[^a-z0-9]+/', '-', $s);
        return trim(substr($s, 0, 191), '-');
    }

    /** Parsed match terms for an arc row: lowercased phrases, empties dropped. */
    function kop_news_arc_terms(?string $matchTerms): array {
        $out = [];
        foreach (preg_split('/\r\n|\r|\n/', (string) $matchTerms) as $line) {
            $t = strtolower(trim($line));
            if ($t !== '') {
                $out[] = $t;
            }
        }
        return $out;
    }

    /**
     * The searchable haystack for one news row: title, alternate title,
     * summary, and facility names, lowercased.
     */
    function kop_news_arc_haystack(array $row): string {
        $parts = [
            $row['article_title'] ?? '',
            $row['alternate_title'] ?? '',
            $row['summary'] ?? '',
        ];
        foreach (kop_facility_mention_names($row['facilities_mentioned'] ?? '[]') as $name) {
            $parts[] = $name;
        }
        return strtolower(implode(' | ', array_filter($parts, static fn($p) => trim((string) $p) !== '')));
    }

    /** Does this row match any of the arc's terms? Returns the matched term or null. */
    function kop_news_arc_match(array $arc, string $haystack): ?string {
        foreach (kop_news_arc_terms($arc['match_terms'] ?? '') as $term) {
            if (str_contains($haystack, $term)) {
                return $term;
            }
        }
        return null;
    }

    /**
     * Auto-attach a just-saved row to the first matching ACTIVE arc (by
     * display_order, then id). Manual curation wins: a row that already has a
     * story_arc_id is left alone. Returns the arc id, or null.
     */
    function kop_news_assign_story_arc(PDO $pdo, int $id): ?int {
        try {
            $stmt = $pdo->prepare(
                "SELECT article_title, alternate_title, summary, facilities_mentioned, story_arc_id
                 FROM news_submissions WHERE id = ?"
            );
            $stmt->execute([$id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row || !empty($row['story_arc_id'])) {
                return $row ? ($row['story_arc_id'] !== null ? (int) $row['story_arc_id'] : null) : null;
            }

            $arcs = $pdo->query(
                "SELECT id, match_terms FROM news_story_arcs
                 WHERE status = 'active' AND match_terms IS NOT NULL AND match_terms != ''
                 ORDER BY display_order ASC, id ASC"
            )->fetchAll(PDO::FETCH_ASSOC);
            if (!$arcs) {
                return null;
            }

            $haystack = kop_news_arc_haystack($row);
            foreach ($arcs as $arc) {
                if (kop_news_arc_match($arc, $haystack) !== null) {
                    $pdo->prepare("UPDATE news_submissions SET story_arc_id = ? WHERE id = ?")
                        ->execute([(int) $arc['id'], $id]);
                    return (int) $arc['id'];
                }
            }
            return null;
        } catch (PDOException $e) {
            // Arc assignment is an enhancement; never let it fail a save
            // (e.g. the news_story_arcs migration not run yet).
            error_log('kop_news_assign_story_arc: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Attach every live, unassigned article matching the arc's terms.
     * Used by the admin tool when an arc is created or its terms change.
     * Returns the number of newly attached articles.
     */
    function kop_news_arc_scan(PDO $pdo, int $arcId): int {
        $stmt = $pdo->prepare("SELECT id, match_terms FROM news_story_arcs WHERE id = ?");
        $stmt->execute([$arcId]);
        $arc = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$arc || !kop_news_arc_terms($arc['match_terms'] ?? '')) {
            return 0;
        }

        $rows = $pdo->query(
            "SELECT id, article_title, alternate_title, summary, facilities_mentioned
             FROM news_submissions
             WHERE story_arc_id IS NULL AND status NOT IN ('rejected', 'deleted')"
        )->fetchAll(PDO::FETCH_ASSOC);

        $attach = $pdo->prepare("UPDATE news_submissions SET story_arc_id = ? WHERE id = ?");
        $attached = 0;
        foreach ($rows as $row) {
            if (kop_news_arc_match($arc, kop_news_arc_haystack($row)) !== null) {
                $attach->execute([$arcId, (int) $row['id']]);
                $attached++;
            }
        }
        return $attached;
    }
}
