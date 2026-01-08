-- ---------------------------------------------
-- Table: wiki_master
-- Stores the canonical (approved/latest) data for TTI programs.
-- This is separate from wiki_submissions which tracks the history of edits.
-- ---------------------------------------------
CREATE TABLE IF NOT EXISTS `wiki_master` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `slug` varchar(255) NOT NULL COMMENT 'Unique URL-friendly identifier',
  `program_name` varchar(255) NOT NULL COMMENT 'Name of the TTI program',
  `city_state` varchar(255) DEFAULT NULL COMMENT 'Location (City, ST)',
  `organization` varchar(255) DEFAULT NULL COMMENT 'Parent organization or operator',
  `program_type` varchar(255) DEFAULT NULL COMMENT 'Type of program (RTC, Wilderness, etc.)',
  `years_active` varchar(100) DEFAULT NULL COMMENT 'Years the program operated',
  `json_data` longtext NOT NULL COMMENT 'Full canonical data as JSON',
  `markdown` longtext COMMENT 'Latest approved Reddit wiki markdown',
  `last_updated_by` varchar(255) DEFAULT NULL COMMENT 'User who last modified this record',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `slug` (`slug`),
  KEY `program_name` (`program_name`),
  KEY `city_state` (`city_state`),
  KEY `updated_at` (`updated_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Canonical wiki records for TTI programs';
