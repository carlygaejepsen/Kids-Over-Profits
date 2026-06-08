<?php
/**
 * Database Initialization Script for Wiki Editor & News Processor Submissions
 * 
 * This script creates the necessary tables for storing:
 * - Wiki editor form submissions (TTI program wiki entries)
 * - News processor form submissions (processed news articles)
 * 
 * Usage:
 * 1. Visit: https://your-domain/wp-content/themes/child/api/init-submissions-db.php?init=1
 * 2. Or run from command line: php api/init-submissions-db.php
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
    $tables_created = [];
    
    // ============================================
    // Create wiki_submissions table
    // ============================================
    $createWikiTableSQL = <<<SQL
CREATE TABLE IF NOT EXISTS `wiki_submissions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `program_name` varchar(255) NOT NULL COMMENT 'Name of the TTI program',
  `city_state` varchar(255) DEFAULT NULL COMMENT 'Location (City, ST)',
  `organization` varchar(255) DEFAULT NULL COMMENT 'Parent organization or operator',
  `program_type` varchar(255) DEFAULT NULL COMMENT 'Type of program (RTC, Wilderness, etc.)',
  `years_active` varchar(100) DEFAULT NULL COMMENT 'Years the program operated',
  `json_data` longtext NOT NULL COMMENT 'Full form data as JSON',
  `generated_markdown` longtext COMMENT 'Generated Reddit wiki markdown output',
  `original_markdown` longtext COMMENT 'Raw markdown submitted/imported by user',
  `status` enum('draft','submitted','approved','published','rejected','deleted') NOT NULL DEFAULT 'submitted',
  `submitted_by` varchar(255) DEFAULT NULL COMMENT 'User identifier or email',
  `submission_notes` text COMMENT 'Notes from submitter',
  `reviewer_notes` text COMMENT 'Notes from reviewer/admin',
  `reviewed_by` varchar(255) DEFAULT NULL COMMENT 'Admin who reviewed',
  `reviewed_at` timestamp NULL DEFAULT NULL COMMENT 'When submission was reviewed',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `program_name` (`program_name`),
  KEY `status` (`status`),
  KEY `city_state` (`city_state`),
  KEY `organization` (`organization`),
  KEY `created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Wiki editor form submissions for TTI programs'
SQL;

    $pdo->exec($createWikiTableSQL);
    $tables_created['wiki_submissions'] = 'Wiki editor form submissions for TTI programs';

    // ============================================
    // Create news_submissions table
    // ============================================
    $createNewsTableSQL = <<<SQL
CREATE TABLE IF NOT EXISTS `news_submissions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `article_title` varchar(500) NOT NULL COMMENT 'Original article title',
  `alternate_title` varchar(500) DEFAULT NULL COMMENT 'Trauma-sensitive alternate title',
  `author` varchar(255) DEFAULT NULL COMMENT 'Article author',
  `publication_name` varchar(255) DEFAULT NULL COMMENT 'Publication source',
  `publication_date` date DEFAULT NULL COMMENT 'Article publication date',
  `article_url` varchar(1000) DEFAULT NULL COMMENT 'URL to original article',
  `article_type` enum('lawsuit','event','expose','arrest','closure','corporate','general') DEFAULT 'general' COMMENT 'Type of news article',
  `facilities_mentioned` text COMMENT 'Facilities/companies mentioned (JSON array)',
  `staff_mentioned` text COMMENT 'Staff/owners mentioned (JSON array)',
  `survivors_mentioned` text COMMENT 'Survivors mentioned (JSON array)',
  `content_warnings` text COMMENT 'Content warning tags (JSON array)',
  `summary` text COMMENT 'Trauma-sensitive summary',
  `json_data` longtext NOT NULL COMMENT 'Full form data as JSON',
  `generated_output` longtext COMMENT 'Generated formatted output',
  `status` enum('draft','submitted','approved','published','rejected','deleted') NOT NULL DEFAULT 'submitted',
  `submitted_by` varchar(255) DEFAULT NULL COMMENT 'User identifier or email',
  `submission_notes` text COMMENT 'Notes from submitter',
  `reviewer_notes` text COMMENT 'Notes from reviewer/admin',
  `reviewed_by` varchar(255) DEFAULT NULL COMMENT 'Admin who reviewed',
  `reviewed_at` timestamp NULL DEFAULT NULL COMMENT 'When submission was reviewed',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `article_title` (`article_title`(255)),
  KEY `publication_name` (`publication_name`),
  KEY `article_type` (`article_type`),
  KEY `status` (`status`),
  KEY `publication_date` (`publication_date`),
  KEY `created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='News processor form submissions'
SQL;

    $pdo->exec($createNewsTableSQL);
    $tables_created['news_submissions'] = 'News processor form submissions';

    // ============================================
    // Create submission_attachments table (for future file uploads)
    // ============================================
    $createAttachmentsTableSQL = <<<SQL
CREATE TABLE IF NOT EXISTS `submission_attachments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `submission_type` enum('wiki','news') NOT NULL COMMENT 'Type of parent submission',
  `submission_id` int(11) NOT NULL COMMENT 'ID of parent submission',
  `file_name` varchar(255) NOT NULL COMMENT 'Original file name',
  `file_path` varchar(500) NOT NULL COMMENT 'Server file path',
  `file_type` varchar(100) DEFAULT NULL COMMENT 'MIME type',
  `file_size` int(11) DEFAULT NULL COMMENT 'File size in bytes',
  `description` text COMMENT 'File description',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `submission_type` (`submission_type`),
  KEY `submission_id` (`submission_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='File attachments for submissions'
SQL;

    $pdo->exec($createAttachmentsTableSQL);
    $tables_created['submission_attachments'] = 'File attachments for submissions';

    // ============================================
    // Create saved_form_values table (for autocomplete/saved values)
    // ============================================
    $createSavedValuesTableSQL = <<<SQL
CREATE TABLE IF NOT EXISTS `saved_form_values` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `form_type` enum('wiki','news') NOT NULL COMMENT 'Which form this value belongs to',
  `category` varchar(100) NOT NULL COMMENT 'Category (authors, publications, facilities, etc.)',
  `value` varchar(500) NOT NULL COMMENT 'The saved value',
  `use_count` int(11) DEFAULT 1 COMMENT 'How many times this value has been used',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_form_category_value` (`form_type`, `category`, `value`(255)),
  KEY `form_type` (`form_type`),
  KEY `category` (`category`),
  KEY `use_count` (`use_count`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Saved autocomplete values for form fields'
SQL;

    $pdo->exec($createSavedValuesTableSQL);
    $tables_created['saved_form_values'] = 'Saved autocomplete values for form fields';

    // ============================================
    // Create url_scan_cache table (Cloudmersive URL threat scan results)
    // ============================================
    $createUrlScanCacheSQL = <<<SQL
CREATE TABLE IF NOT EXISTS `url_scan_cache` (
  `url_hash` char(64) NOT NULL COMMENT 'SHA-256 of the URL',
  `url` varchar(2048) NOT NULL COMMENT 'The URL that was scanned',
  `is_clean` tinyint(1) NOT NULL DEFAULT 1 COMMENT '1 if Cloudmersive returned CleanResult=true',
  `threats_json` text COMMENT 'JSON map of threat categories returned by Cloudmersive',
  `scanned_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`url_hash`),
  KEY `is_clean` (`is_clean`),
  KEY `scanned_at` (`scanned_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Cached Cloudmersive URL threat scan results'
SQL;

    $pdo->exec($createUrlScanCacheSQL);
    $tables_created['url_scan_cache'] = 'Cloudmersive URL threat scan cache';

    // ============================================
    // Create news_facility_links table (news <-> facilities join)
    // ============================================
    $createNewsFacilityLinksSQL = <<<SQL
CREATE TABLE IF NOT EXISTS `news_facility_links` (
  `news_id` int(11) NOT NULL COMMENT 'FK -> news_submissions.id',
  `facility_id` int(11) NOT NULL COMMENT 'FK -> facilities_master.id',
  `link_type` enum('mentioned','primary','related') NOT NULL DEFAULT 'mentioned' COMMENT 'How the article relates to the facility',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `created_by` varchar(255) DEFAULT NULL COMMENT 'User who created the link',
  PRIMARY KEY (`news_id`, `facility_id`),
  KEY `by_facility` (`facility_id`, `news_id`),
  KEY `link_type` (`link_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Structured links between news articles and facilities'
SQL;

    $pdo->exec($createNewsFacilityLinksSQL);
    $tables_created['news_facility_links'] = 'Structured links between news articles and facilities';

    $result = [
        'success' => true,
        'message' => 'Submissions database initialization complete',
        'tables_created' => $tables_created,
        'next_steps' => [
            'Wiki submissions API available at: api/save-wiki-submission.php',
            'News submissions API available at: api/save-news-submission.php',
            'Verify tables: SHOW TABLES LIKE "%submissions%"'
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
