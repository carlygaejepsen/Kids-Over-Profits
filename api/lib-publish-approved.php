<?php
/**
 * Publish approved legislation and lawsuits: move rows sitting in
 * publication_status = 'approved' to 'published', stamping published_at
 * (an existing timestamp is kept). Used by api/publish-approved-records.php
 * (all of them, dry run first) and the review inbox's "Approved, not
 * published" queue (inc/review-inbox/publish-approved.php, one or all).
 */

/** The tables, with their title columns. */
function kop_publish_approved_tables() {
    return array('legislation' => 'bill_title', 'lawsuits' => 'case_name');
}

/** Approved rows waiting per table: [table => n]. */
function kop_publish_approved_counts(PDO $pdo) {
    $counts = array();
    foreach (array_keys(kop_publish_approved_tables()) as $table) {
        $counts[$table] = (int) $pdo->query("SELECT COUNT(*) FROM `$table` WHERE publication_status = 'approved'")->fetchColumn();
    }
    return $counts;
}

/**
 * Publish every approved row, or only the ids in $only ([table => [id, ...]]),
 * in one transaction. Returns ['updated' => [table => n], 'total' => n];
 * throws (after rolling back) when the update fails.
 */
function kop_publish_approved_records(PDO $pdo, array $only = null) {
    $tables = array_keys(kop_publish_approved_tables());
    try {
        $pdo->beginTransaction();
        $updated = array();
        foreach ($tables as $table) {
            $where = "publication_status = 'approved'";
            $params = array();
            if ($only !== null) {
                $ids = array_values(array_filter(array_map('intval', (array) ($only[$table] ?? array()))));
                if (!$ids) {
                    $updated[$table] = 0;
                    continue;
                }
                $where .= ' AND id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
                $params = $ids;
            }
            // COALESCE keeps any existing timestamp; approved rows have none.
            $stmt = $pdo->prepare("UPDATE `$table` SET publication_status = 'published', published_at = COALESCE(published_at, NOW()) WHERE $where");
            $stmt->execute($params);
            $updated[$table] = $stmt->rowCount();
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
    return array('updated' => $updated, 'total' => array_sum($updated));
}
