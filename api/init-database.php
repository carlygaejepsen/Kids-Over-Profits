<?php
/**
 * Database Initialization Script
 * 
 * This script creates the necessary tables for the Kids Over Profits facility database.
 * Run this once when setting up a new environment (staging, production).
 * 
 * Usage:
 * 1. Visit: https://your-domain/wp-content/themes/child/api/init-database.php?init=1
 * 2. Or run from command line: php api/init-database.php
 */

// Load WordPress and config
require_once __DIR__ . '/config.php';

header('Content-Type: application/json');

// Check if init parameter is present (security: prevent accidental runs)
$is_cli = php_sapi_name() === 'cli';
$init_param = $_GET['init'] ?? null;

if (!$is_cli && $init_param !== '1') {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'error' => 'Database initialization requires ?init=1 parameter or CLI execution',
        'hint' => 'This is a safety measure to prevent accidental database modifications'
    ]);
    exit;
}

try {
    echo json_encode([
        'success' => true,
        'message' => 'Creating database tables...',
        'tables' => []
    ]);
    
    // ============================================
    // Create facilities_master table
    // ============================================
    $createTableSQL = <<<SQL
CREATE TABLE IF NOT EXISTS `facilities_master` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `unique_name` varchar(255) NOT NULL UNIQUE COMMENT 'Project identifier (company/location/referrer name)',
  `json_data` longtext NOT NULL COMMENT 'JSON-encoded project data including operator, facilities, consultants',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_name` (`unique_name`),
  KEY `updated_at` (`updated_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Master facility database - stores all projects as JSON'
SQL;

    $pdo->exec($createTableSQL);
    
    // ============================================
    // Create suggested_edits table (for public suggestions)
    // ============================================
    $createSuggestionsTableSQL = <<<SQL
CREATE TABLE IF NOT EXISTS `suggested_edits` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `unique_name` varchar(255) NOT NULL COMMENT 'Project identifier',
  `json_data` longtext NOT NULL COMMENT 'Suggested changes as JSON',
  `status` enum('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  `submitted_by` varchar(255) COMMENT 'Email or identifier of submitter',
  `submission_reason` text COMMENT 'Reason for suggestion',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `status` (`status`),
  KEY `unique_name` (`unique_name`),
  KEY `created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Public suggestions for facility data edits'
SQL;

    $pdo->exec($createSuggestionsTableSQL);

    // ============================================
    // Create referrers_master table (separate from facilities)
    // ============================================
    $createReferrersTableSQL = <<<SQL
CREATE TABLE IF NOT EXISTS `referrers_master` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `unique_name` varchar(255) NOT NULL COMMENT 'Referrer project identifier (agency/consultant name)',
  `json_data` longtext NOT NULL COMMENT 'JSON-encoded referrer data including agency and consultants',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_name` (`unique_name`),
  KEY `updated_at` (`updated_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Referrer database - stores education consultants and agencies'
SQL;

    $pdo->exec($createReferrersTableSQL);

    $result = [
        'success' => true,
        'message' => 'Database initialization complete',
        'tables_created' => [
            'facilities_master' => 'Master facility data storage',
            'referrers_master' => 'Referrer/consultant data storage',
            'suggested_edits' => 'Public suggestion queue'
        ],
        'next_steps' => [
            'Import existing data if available',
            'Verify tables exist: SHOW TABLES LIKE "facilities_%"',
            'Check table schema: DESCRIBE facilities_master'
        ]
    ];
    
    if ($is_cli) {
        echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    } else {
        echo json_encode($result);
    }
    
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Database error: ' . $e->getMessage(),
        'code' => $e->getCode()
    ]);
    exit(1);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Error: ' . $e->getMessage()
    ]);
    exit(1);
}
?>
