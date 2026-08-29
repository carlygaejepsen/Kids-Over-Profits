<?php
/**
 * Update Database Schema
 * Adds 'article_location' and 'tags' to news_submissions table.
 */

// Load config
require_once __DIR__ . '/config.php';

try {
    echo "Updating database schema...\n";

    // Add article_location column if it doesn't exist
    $check = $pdo->query("SHOW COLUMNS FROM news_submissions LIKE 'article_location'");
    if ($check->rowCount() == 0) {
        $pdo->exec("ALTER TABLE news_submissions ADD COLUMN article_location varchar(255) DEFAULT NULL COMMENT 'Location of event/story' AFTER article_type");
        echo "Added 'article_location' column.\n";
    } else {
        echo "'article_location' column already exists.\n";
    }

    // Add tags column if it doesn't exist
    $check = $pdo->query("SHOW COLUMNS FROM news_submissions LIKE 'tags'");
    if ($check->rowCount() == 0) {
        $pdo->exec("ALTER TABLE news_submissions ADD COLUMN tags text DEFAULT NULL COMMENT 'JSON array of organization tags' AFTER content_warnings");
        echo "Added 'tags' column.\n";
    } else {
        echo "'tags' column already exists.\n";
    }

    // Add story_group_id column (cross-outlet story clustering) if it doesn't exist
    $check = $pdo->query("SHOW COLUMNS FROM news_submissions LIKE 'story_group_id'");
    if ($check->rowCount() == 0) {
        $pdo->exec("ALTER TABLE news_submissions ADD COLUMN story_group_id int(11) DEFAULT NULL COMMENT 'Cross-outlet story cluster: id of the lowest-id article in the group; NULL = standalone' AFTER generated_output");
        $pdo->exec("ALTER TABLE news_submissions ADD KEY story_group_id (story_group_id)");
        echo "Added 'story_group_id' column and index.\n";
        echo "Run api/rebuild-news-story-groups.php (as an admin) to cluster existing articles.\n";
    } else {
        echo "'story_group_id' column already exists.\n";
    }

    echo "Schema update complete.\n";

} catch (PDOException $e) {
    echo "Error updating schema: " . $e->getMessage() . "\n";
}

