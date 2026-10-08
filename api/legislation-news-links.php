<?php
/**
 * News articles that cover a bill: legislation_news_links (legislation_id,
 * news_id). Only links an admin made (Data Manager: an article's Reclassify,
 * or a bill's "Coverage and documents"); there is no automatic matching.
 * The /legislation/ page lists them on each bill card as "News coverage",
 * like the lawsuits page does with lawsuit_news_links.
 *
 * Also: the 'excluded' link type on lawsuit_news_links, for an automatic
 * case/article match an admin took off. The lawsuit sync only deletes and
 * rewrites 'auto' rows, so an excluded row keeps the match from coming back;
 * every reader skips it.
 *
 * PDO-only (the records database, api/config.php).
 */

if (!function_exists('kop_legislation_news_links_ensure_table')) {
    function kop_legislation_news_links_ensure_table(PDO $pdo): void {
        static $done = false;
        if ($done) return;
        $pdo->exec("CREATE TABLE IF NOT EXISTS `legislation_news_links` (
          `legislation_id` int(11) NOT NULL COMMENT 'FK -> legislation.id',
          `news_id` int(11) NOT NULL COMMENT 'FK -> news_submissions.id',
          `created_by` varchar(255) DEFAULT NULL,
          `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (`legislation_id`,`news_id`),
          KEY `by_news` (`news_id`,`legislation_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='News articles that cover a bill'");
        $done = true;
    }
}

if (!function_exists('kop_lawsuit_news_links_allow_excluded')) {
    /** Adds 'excluded' to lawsuit_news_links.link_type once. */
    function kop_lawsuit_news_links_allow_excluded(PDO $pdo): void {
        static $done = false;
        if ($done) return;
        if (function_exists('kop_lawsuit_news_links_ensure_table')) kop_lawsuit_news_links_ensure_table($pdo);
        $col = $pdo->query("SHOW COLUMNS FROM `lawsuit_news_links` LIKE 'link_type'")->fetch(PDO::FETCH_ASSOC);
        if ($col && strpos((string)$col['Type'], "'excluded'") === false) {
            $pdo->exec("ALTER TABLE `lawsuit_news_links` MODIFY `link_type` enum('auto','manual','excluded') NOT NULL DEFAULT 'auto'
                COMMENT 'auto rows are owned by the sync; manual rows survive it; excluded = an auto match an admin took off'");
        }
        $done = true;
    }
}

if (!function_exists('kop_legislation_news_for_bills')) {
    /**
     * bill id => [article rows] (approved/published articles with a link),
     * newest first. Empty when the table does not exist yet.
     */
    function kop_legislation_news_for_bills(PDO $pdo, array $billIds): array {
        $billIds = array_values(array_filter(array_map('intval', $billIds)));
        if (!$billIds) return [];
        $out = [];
        try {
            $ph = implode(',', array_fill(0, count($billIds), '?'));
            $stmt = $pdo->prepare(
                "SELECT ln.legislation_id, n.id, n.article_title, n.alternate_title,
                        n.publication_name, n.publication_date, n.article_url
                 FROM legislation_news_links ln
                 JOIN news_submissions n ON n.id = ln.news_id
                 WHERE ln.legislation_id IN ($ph)
                   AND n.status IN ('approved','published')
                   AND n.article_url IS NOT NULL AND n.article_url <> ''
                 ORDER BY n.publication_date DESC, n.id DESC"
            );
            $stmt->execute($billIds);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) $out[(int)$r['legislation_id']][] = $r;
        } catch (Throwable $e) {
            return [];
        }
        return $out;
    }
}
