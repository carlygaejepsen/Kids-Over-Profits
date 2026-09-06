<?php
/**
 * Database Initialization Script for the In Loving Memory page
 *
 * Creates the memorial_victims table and, when the table is empty, seeds it
 * from api/memorial-seed.json (the entries migrated from the original
 * hand-maintained HTML table on /in-loving-memory/).
 *
 * Usage:
 * - Web: /wp-content/themes/child/api/init-memorial-db.php?init=1
 * - CLI: php api/init-memorial-db.php
 *
 * Safe to run repeatedly: the table is CREATE IF NOT EXISTS and the seed only
 * inserts when the table has no rows.
 */

require_once __DIR__ . '/config.php';

header('Content-Type: application/json');

$is_cli = php_sapi_name() === 'cli';

if (!$is_cli && ($_GET['init'] ?? null) !== '1') {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Requires ?init=1 or CLI execution']);
    exit;
}

try {
    $pdo->exec(<<<SQL
CREATE TABLE IF NOT EXISTS `memorial_victims` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL COMMENT 'Name as published; "Unknown" when not released',
  `age` tinyint(3) unsigned DEFAULT NULL COMMENT 'Age at death; NULL when unknown',
  `program` varchar(500) DEFAULT NULL COMMENT 'Facility / program where the death occurred',
  `date_of_death` date DEFAULT NULL,
  `date_precision` enum('day','month','year','unknown') NOT NULL DEFAULT 'day' COMMENT 'How much of date_of_death is known',
  `cause_of_death` varchar(500) DEFAULT NULL COMMENT 'Cause as reported (free text)',
  `cause_category` varchar(50) DEFAULT NULL COMMENT 'Normalized bucket for filtering (suicide, restraint, medical_neglect, ...)',
  `location` varchar(100) DEFAULT NULL COMMENT 'State, or region for non-US deaths',
  `source_name` varchar(255) DEFAULT NULL COMMENT 'Publication / document name for the source link',
  `source_url` varchar(1000) DEFAULT NULL,
  `kop_url` varchar(1000) DEFAULT NULL COMMENT 'Related page on kidsoverprofits.org (document library, lawsuits, etc.)',
  `notes` text DEFAULT NULL COMMENT 'Internal notes, not displayed',
  `publication_status` enum('draft','published','archived') NOT NULL DEFAULT 'published',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `date_of_death` (`date_of_death`),
  KEY `location` (`location`),
  KEY `cause_category` (`cause_category`),
  KEY `publication_status` (`publication_status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Young people who died in TTI programs, juvenile detention, and treatment facilities'
SQL);

    $result = ['success' => true, 'message' => 'memorial_victims table initialized', 'seeded' => 0];

    $count = (int) $pdo->query('SELECT COUNT(*) FROM memorial_victims')->fetchColumn();
    $seed_path = __DIR__ . '/memorial-seed.json';

    if ($count === 0 && file_exists($seed_path)) {
        $rows = json_decode(file_get_contents($seed_path), true);
        if (!is_array($rows)) {
            throw new RuntimeException('memorial-seed.json is not valid JSON');
        }

        $insert = $pdo->prepare(
            'INSERT INTO memorial_victims
                (name, age, program, date_of_death, date_precision, cause_of_death, cause_category,
                 location, source_name, source_url, kop_url, publication_status)
             VALUES
                (:name, :age, :program, :date_of_death, :date_precision, :cause_of_death, :cause_category,
                 :location, :source_name, :source_url, :kop_url, \'published\')'
        );

        $pdo->beginTransaction();
        foreach ($rows as $row) {
            $insert->execute([
                ':name'           => $row['name'] ?: 'Unknown',
                ':age'            => isset($row['age']) && $row['age'] !== null ? (int) $row['age'] : null,
                ':program'        => $row['program'] ?: null,
                ':date_of_death'  => $row['date_of_death'] ?: null,
                ':date_precision' => in_array($row['date_precision'] ?? '', ['day', 'month', 'year', 'unknown'], true)
                                        ? $row['date_precision'] : ($row['date_of_death'] ? 'day' : 'unknown'),
                ':cause_of_death' => $row['cause_of_death'] ?: null,
                ':cause_category' => $row['cause_category'] ?: null,
                ':location'       => $row['location'] ?: null,
                ':source_name'    => $row['source_name'] ?: null,
                ':source_url'     => $row['source_url'] ?: null,
                ':kop_url'        => $row['kop_url'] ?: null,
            ]);
        }
        $pdo->commit();

        $result['seeded'] = count($rows);
        $result['message'] .= ' and seeded from memorial-seed.json';
    } else {
        $result['existing_rows'] = $count;
    }

    echo $is_cli ? json_encode($result, JSON_PRETTY_PRINT) . "\n" : json_encode($result);
} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    exit(1);
}
