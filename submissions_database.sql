-- ==============================================
-- Wiki Editor & News Processor Submissions Database
-- ==============================================
-- Run this SQL to create the tables manually, or use:
-- api/init-submissions-db.php?init=1
-- ==============================================

-- ---------------------------------------------
-- Table: wiki_submissions
-- Stores wiki editor form submissions for TTI programs
-- ---------------------------------------------
CREATE TABLE IF NOT EXISTS `wiki_submissions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `program_name` varchar(255) NOT NULL COMMENT 'Name of the TTI program',
  `city_state` varchar(255) DEFAULT NULL COMMENT 'Location (City, ST)',
  `program_type` varchar(255) DEFAULT NULL COMMENT 'Type of program (RTC, Wilderness, etc.)',
  `years_active` varchar(100) DEFAULT NULL COMMENT 'Years the program operated',
  `json_data` longtext NOT NULL COMMENT 'Full form data as JSON',
  `generated_markdown` longtext COMMENT 'Generated Reddit wiki markdown output',
  `original_markdown` longtext COMMENT 'Raw markdown submitted/imported by user',
  `status` enum('draft','submitted','approved','published','rejected') NOT NULL DEFAULT 'submitted',
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
  KEY `created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Wiki editor form submissions for TTI programs';

-- ---------------------------------------------
-- Table: news_submissions
-- Stores news processor form submissions
-- ---------------------------------------------
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
  `status` enum('draft','submitted','approved','published','rejected') NOT NULL DEFAULT 'submitted',
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='News processor form submissions';

-- ---------------------------------------------
-- Table: submission_attachments
-- File attachments for submissions (future use)
-- ---------------------------------------------
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='File attachments for submissions';

-- ---------------------------------------------
-- Table: saved_form_values
-- Saved autocomplete values for form fields
-- ---------------------------------------------
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Saved autocomplete values for form fields';

-- ==============================================
-- Example Queries
-- ==============================================

-- Get all pending wiki submissions
-- SELECT * FROM wiki_submissions WHERE status = 'submitted' ORDER BY created_at DESC;

-- Get news articles by type
-- SELECT * FROM news_submissions WHERE article_type = 'lawsuit' ORDER BY publication_date DESC;

-- Get submission statistics
-- SELECT status, COUNT(*) as count FROM wiki_submissions GROUP BY status;
-- SELECT article_type, COUNT(*) as count FROM news_submissions GROUP BY article_type;

-- Search submissions
-- SELECT * FROM wiki_submissions WHERE program_name LIKE '%ranch%' OR city_state LIKE '%utah%';

-- Get saved autocomplete values for news processor
-- SELECT * FROM saved_form_values WHERE form_type = 'news' ORDER BY category, use_count DESC;
