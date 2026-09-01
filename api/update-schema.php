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

    // Ongoing story arcs: curated long-running stories (a lawsuit, a closure)
    // spanning many distinct articles — separate from story_group_id's
    // same-event cross-outlet clustering.
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS news_story_arcs (
            id int(11) NOT NULL AUTO_INCREMENT,
            title varchar(255) NOT NULL,
            slug varchar(191) NOT NULL COMMENT 'URL key for the story view (?story=slug)',
            description text DEFAULT NULL,
            match_terms text DEFAULT NULL COMMENT 'Newline-separated phrases; a new article containing one is auto-attached on save',
            facility_label varchar(255) DEFAULT NULL COMMENT 'Facility name for the story cards'' learn-more button',
            facility_url varchar(500) DEFAULT NULL COMMENT 'Facility profile page URL (e.g. /hyde); empty = no button',
            status enum('active','archived') NOT NULL DEFAULT 'active',
            display_order int(11) NOT NULL DEFAULT 0,
            created_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY slug (slug)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    echo "'news_story_arcs' table ready.\n";

    // Facility learn-more button on story arc cards (label + profile URL).
    $check = $pdo->query("SHOW COLUMNS FROM news_story_arcs LIKE 'facility_label'");
    if ($check->rowCount() == 0) {
        $pdo->exec("ALTER TABLE news_story_arcs ADD COLUMN facility_label varchar(255) DEFAULT NULL COMMENT 'Facility name for the story cards'' learn-more button' AFTER match_terms");
        $pdo->exec("ALTER TABLE news_story_arcs ADD COLUMN facility_url varchar(500) DEFAULT NULL COMMENT 'Facility profile page URL (e.g. /hyde); empty = no button' AFTER facility_label");
        echo "Added 'facility_label' and 'facility_url' columns to news_story_arcs.\n";
    } else {
        echo "'facility_label'/'facility_url' columns already exist.\n";
    }

    $check = $pdo->query("SHOW COLUMNS FROM news_submissions LIKE 'story_arc_id'");
    if ($check->rowCount() == 0) {
        $pdo->exec("ALTER TABLE news_submissions ADD COLUMN story_arc_id int(11) DEFAULT NULL COMMENT 'FK -> news_story_arcs.id; the big ongoing story this article belongs to; NULL = none' AFTER story_group_id");
        $pdo->exec("ALTER TABLE news_submissions ADD KEY story_arc_id (story_arc_id)");
        echo "Added 'story_arc_id' column and index.\n";
        echo "Create arcs in api/manage-story-arcs.php (as an admin) and scan to attach existing articles.\n";
    } else {
        echo "'story_arc_id' column already exists.\n";
    }

    echo "Schema update complete.\n";

} catch (PDOException $e) {
    echo "Error updating schema: " . $e->getMessage() . "\n";
}

