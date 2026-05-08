<?php
/**
 * Database Initialization Script for State-Scoped Content
 *
 * Creates tables for:
 * - lawsuits: court cases related to TTI facilities/operators
 * - legislation: bills and laws affecting the TTI industry
 *
 * Usage:
 * - Web: /wp-content/themes/child/api/init-state-content-db.php?init=1
 * - CLI: php api/init-state-content-db.php
 */

require_once __DIR__ . '/config.php';

header('Content-Type: application/json');

$is_cli = php_sapi_name() === 'cli';
$init_param = $_GET['init'] ?? null;

if (!$is_cli && $init_param !== '1') {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'error' => 'Database initialization requires ?init=1 parameter or CLI execution',
    ]);
    exit;
}

try {
    $tables_created = [];

    // ============================================
    // lawsuits
    // ============================================
    $createLawsuitsSQL = <<<SQL
CREATE TABLE IF NOT EXISTS `lawsuits` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `case_name` varchar(500) NOT NULL COMMENT 'Case caption / display name',
  `case_number` varchar(255) DEFAULT NULL COMMENT 'Court docket / case number',
  `court` varchar(255) DEFAULT NULL COMMENT 'Court name (e.g. US District Court, D. Utah)',
  `jurisdiction` varchar(100) DEFAULT NULL COMMENT 'State name or "Federal"',
  `filing_date` date DEFAULT NULL,
  `status` enum('filed','in_progress','settled','dismissed','ruling','appeal','closed','unknown') NOT NULL DEFAULT 'unknown',
  `plaintiffs` text DEFAULT NULL COMMENT 'JSON array of plaintiff names',
  `defendants` text DEFAULT NULL COMMENT 'JSON array of defendant names',
  `facilities_mentioned` text DEFAULT NULL COMMENT 'JSON array of facility names',
  `staff_mentioned` text DEFAULT NULL COMMENT 'JSON array of staff/owners',
  `organizations_mentioned` text DEFAULT NULL COMMENT 'JSON array of parent orgs / companies',
  `claims` text DEFAULT NULL COMMENT 'JSON array of claim categories (physical_abuse, wrongful_death, fraud, etc.)',
  `outcome` text DEFAULT NULL COMMENT 'Free-text outcome / disposition',
  `settlement_amount` varchar(100) DEFAULT NULL COMMENT 'Settlement amount as displayed (currency string)',
  `summary` text DEFAULT NULL COMMENT 'Trauma-sensitive summary',
  `source_urls` text DEFAULT NULL COMMENT 'JSON array of source URLs',
  `document_urls` text DEFAULT NULL COMMENT 'JSON array of court document URLs',
  `tags` text DEFAULT NULL COMMENT 'JSON array of free-form tags',
  `filebird_folder_id` int(11) DEFAULT NULL COMMENT 'FileBird folder containing case documents',
  `publication_status` enum('draft','pending','approved','published','rejected') NOT NULL DEFAULT 'draft' COMMENT 'Editorial workflow status',
  `submitted_by` varchar(255) DEFAULT NULL,
  `reviewer_notes` text DEFAULT NULL,
  `published_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `case_name` (`case_name`(255)),
  KEY `jurisdiction` (`jurisdiction`),
  KEY `status` (`status`),
  KEY `publication_status` (`publication_status`),
  KEY `filing_date` (`filing_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='TTI-related lawsuits and court cases'
SQL;

    $pdo->exec($createLawsuitsSQL);
    $tables_created['lawsuits'] = 'TTI-related lawsuits and court cases';

    // ============================================
    // legislation
    // ============================================
    $createLegislationSQL = <<<SQL
CREATE TABLE IF NOT EXISTS `legislation` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `bill_number` varchar(100) DEFAULT NULL COMMENT 'e.g. HB123, SB456, S.123',
  `bill_title` varchar(500) NOT NULL COMMENT 'Official or short title',
  `jurisdiction` varchar(100) DEFAULT NULL COMMENT 'State name or "Federal"',
  `chamber` enum('house','senate','assembly','joint','federal_house','federal_senate','other','unknown') NOT NULL DEFAULT 'unknown',
  `session_year` varchar(20) DEFAULT NULL COMMENT 'Legislative session / year (e.g. 2025, 2025-2026)',
  `bill_type` varchar(50) DEFAULT NULL COMMENT 'HB, SB, AB, HR, SR, etc.',
  `sponsors` text DEFAULT NULL COMMENT 'JSON array of sponsor names',
  `status` enum('introduced','in_committee','passed_house','passed_senate','signed','vetoed','dead','enacted','unknown') NOT NULL DEFAULT 'unknown',
  `introduced_date` date DEFAULT NULL,
  `last_action_date` date DEFAULT NULL,
  `last_action_text` varchar(500) DEFAULT NULL,
  `subject_tags` text DEFAULT NULL COMMENT 'JSON array (transparency, restraints, oversight, etc.)',
  `summary` text DEFAULT NULL,
  `full_text_url` varchar(1000) DEFAULT NULL,
  `official_url` varchar(1000) DEFAULT NULL COMMENT 'Legislature tracker page',
  `position` enum('support','oppose','neutral','watch','unknown') NOT NULL DEFAULT 'unknown' COMMENT 'KOP editorial stance',
  `facilities_affected` text DEFAULT NULL COMMENT 'JSON array',
  `tags` text DEFAULT NULL COMMENT 'JSON array of free-form tags',
  `filebird_folder_id` int(11) DEFAULT NULL COMMENT 'FileBird folder containing supporting documents',
  `publication_status` enum('draft','pending','approved','published','rejected') NOT NULL DEFAULT 'draft',
  `submitted_by` varchar(255) DEFAULT NULL,
  `reviewer_notes` text DEFAULT NULL,
  `published_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `bill_title` (`bill_title`(255)),
  KEY `bill_number` (`bill_number`),
  KEY `jurisdiction` (`jurisdiction`),
  KEY `status` (`status`),
  KEY `publication_status` (`publication_status`),
  KEY `introduced_date` (`introduced_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Legislation tracking for TTI-related bills'
SQL;

    $pdo->exec($createLegislationSQL);
    $tables_created['legislation'] = 'Legislation tracking for TTI-related bills';

    // ============================================
    // Idempotent column additions for previously-created tables
    // ============================================
    $columnAdditions = [];
    foreach (['lawsuits', 'legislation'] as $tableName) {
        $check = $pdo->query("SHOW COLUMNS FROM `$tableName` LIKE 'filebird_folder_id'");
        if ($check && $check->rowCount() === 0) {
            $pdo->exec("ALTER TABLE `$tableName` ADD COLUMN `filebird_folder_id` int(11) DEFAULT NULL COMMENT 'FileBird folder containing related documents'");
            $columnAdditions[$tableName][] = 'filebird_folder_id';
        }
    }

    $result = [
        'success' => true,
        'message' => 'State content tables initialized',
        'tables_created' => $tables_created,
        'columns_added' => $columnAdditions,
    ];

    echo $is_cli ? json_encode($result, JSON_PRETTY_PRINT) . "\n" : json_encode($result);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Database error: ' . $e->getMessage(),
    ]);
    exit(1);
}
