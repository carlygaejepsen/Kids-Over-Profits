<?php
/**
 * Database Initialization Script for Volunteer Projects
 *
 * Creates the volunteer_projects table.
 *
 * Usage:
 * - Web: /wp-content/themes/child/api/init-volunteer-db.php?init=1
 * - CLI: php api/init-volunteer-db.php
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
CREATE TABLE IF NOT EXISTS `volunteer_projects` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `title` varchar(255) NOT NULL COMMENT 'Project title',
  `project_type` enum('research','data_entry','advocacy','tech','outreach','other') NOT NULL DEFAULT 'other',
  `status` enum('open','filled','completed','archived') NOT NULL DEFAULT 'open',
  `description` text DEFAULT NULL COMMENT 'Full description of the project',
  `skills_needed` text DEFAULT NULL COMMENT 'JSON array of skill tags',
  `contact_email` varchar(255) DEFAULT NULL,
  `signup_url` varchar(1000) DEFAULT NULL COMMENT 'External signup form or link',
  `jurisdiction` varchar(100) DEFAULT NULL COMMENT 'Related state or "Federal" or "National"',
  `facilities_related` text DEFAULT NULL COMMENT 'JSON array of related facility names',
  `publication_status` enum('draft','published','archived') NOT NULL DEFAULT 'draft',
  `reviewer_notes` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `project_type` (`project_type`),
  KEY `status` (`status`),
  KEY `publication_status` (`publication_status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Volunteer project opportunities'
SQL);

    $result = ['success' => true, 'message' => 'volunteer_projects table initialized'];
    echo $is_cli ? json_encode($result, JSON_PRETTY_PRINT) . "\n" : json_encode($result);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    exit(1);
}
