<?php
/**
 * Update Database Schema
 * Adds 'article_location' and 'tags' to news_submissions table.
 */

// --- Admin authentication ---
// Ensure WordPress is loaded, then require the capability (same pattern as
// backfill-referrers.php / manage-submissions.php).
if (!function_exists('current_user_can')) {
    $kop_wp = __DIR__;
    for ($i = 0; $i < 6; $i++) {
        $kop_wp = dirname($kop_wp);
        if (file_exists($kop_wp . '/wp-load.php')) {
            require_once $kop_wp . '/wp-load.php';
            break;
        }
    }
}
if (!function_exists('current_user_can') || !(current_user_can('manage_options'))) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Admin access required.']);
    exit;
}

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

    // Journalists who cover the TTI: internal-only contact list, extracted
    // from news bylines (api/lib-journalists.php) and curated in
    // api/manage-journalists.php. Never shown publicly.
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS journalists (
            id int(11) NOT NULL AUTO_INCREMENT,
            name varchar(255) NOT NULL,
            name_key varchar(191) NOT NULL COMMENT 'Lowercase, accent-folded match key for bylines',
            aliases text DEFAULT NULL COMMENT 'Other spellings, one per line; matched like name',
            outlet varchar(255) DEFAULT NULL COMMENT 'Current outlet; empty = latest from their articles',
            email varchar(255) DEFAULT NULL,
            phone varchar(64) DEFAULT NULL,
            social text DEFAULT NULL COMMENT 'Social profile URLs or handles, one per line',
            website varchar(500) DEFAULT NULL,
            location varchar(255) DEFAULT NULL,
            beat text DEFAULT NULL COMMENT 'What they cover',
            outreach enum('not_contacted','contacted','responded','ongoing','do_not_contact') NOT NULL DEFAULT 'not_contacted',
            notes text DEFAULT NULL,
            status enum('active','ignored') NOT NULL DEFAULT 'active' COMMENT 'ignored = not a journalist; kept so rescans skip the name',
            source enum('extracted','manual') NOT NULL DEFAULT 'manual',
            created_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY name_key (name_key),
            KEY status (status),
            KEY outreach (outreach)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Internal: journalists covering the TTI'"
    );
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS journalist_articles (
            journalist_id int(11) NOT NULL COMMENT 'FK -> journalists.id',
            news_id int(11) NOT NULL COMMENT 'FK -> news_submissions.id',
            PRIMARY KEY (journalist_id, news_id),
            KEY news_id (news_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Internal: which journalist wrote which news entry'"
    );
    echo "'journalists' and 'journalist_articles' tables ready.\n";
    echo "Open api/manage-journalists.php (as an admin) and scan the news entries to fill them.\n";

    // Featured ("egregious") inspection reports for the home page block.
    // Curated in api/manage-featured-inspections.php. Guarded: the inspection
    // tables only exist once a scraper has pushed data.
    $tbl = $pdo->query("SHOW TABLES LIKE 'inspection_reports'");
    if ($tbl->rowCount() > 0) {
        $check = $pdo->query("SHOW COLUMNS FROM inspection_reports LIKE 'featured'");
        if ($check->rowCount() == 0) {
            $pdo->exec("ALTER TABLE inspection_reports ADD COLUMN featured TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Show on the home page featured-inspections block'");
            $pdo->exec("ALTER TABLE inspection_reports ADD COLUMN featured_note VARCHAR(500) DEFAULT NULL COMMENT 'One-line why-this-matters shown on the home page card'");
            $pdo->exec("ALTER TABLE inspection_reports ADD KEY featured (featured)");
            echo "Added 'featured'/'featured_note' to inspection_reports.\n";
            echo "Feature reports in api/manage-featured-inspections.php (as an admin).\n";
        } else {
            echo "'featured' column already exists on inspection_reports.\n";
        }
    } else {
        echo "inspection_reports table not present - skipped the featured-inspections migration.\n";
    }

    echo "Schema update complete.\n";

} catch (PDOException $e) {
    echo "Error updating schema: " . $e->getMessage() . "\n";
}

