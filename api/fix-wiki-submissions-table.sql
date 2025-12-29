-- Fix wiki_submissions table: Add missing original_markdown column
-- Run this SQL to fix the existing table schema

ALTER TABLE `wiki_submissions`
ADD COLUMN `original_markdown` longtext COMMENT 'Raw markdown submitted/imported by user'
AFTER `generated_markdown`;
