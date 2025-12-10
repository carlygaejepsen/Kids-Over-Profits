<?php
/**
 * Database Migration: Add original_markdown column to wiki_submissions
 *
 * Run this once to add the original_markdown column to existing database
 * Access via: /wp-content/themes/child/api/add-original-markdown-column.php
 *
 * DELETE THIS FILE after running successfully
 */

require_once __DIR__ . '/config.php';

try {
    // Check if column already exists
    $checkSql = "SHOW COLUMNS FROM wiki_submissions LIKE 'original_markdown'";
    $result = $pdo->query($checkSql);

    if ($result->rowCount() > 0) {
        echo "<h2>✅ Column already exists!</h2>";
        echo "<p>The 'original_markdown' column is already in the wiki_submissions table.</p>";
        echo "<p><strong>You can safely delete this file now.</strong></p>";
        exit;
    }

    // Add the column
    $alterSql = "ALTER TABLE wiki_submissions
                 ADD COLUMN original_markdown LONGTEXT DEFAULT ''
                 COMMENT 'Raw markdown submitted/imported by user'
                 AFTER generated_markdown";

    $pdo->exec($alterSql);

    echo "<h2>✅ Migration Successful!</h2>";
    echo "<p>The 'original_markdown' column has been added to the wiki_submissions table.</p>";
    echo "<p><strong>Please delete this file now for security.</strong></p>";

    // Show updated table structure
    echo "<h3>Updated Table Structure:</h3>";
    echo "<pre>";
    $columns = $pdo->query("SHOW COLUMNS FROM wiki_submissions")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($columns as $column) {
        echo "{$column['Field']}: {$column['Type']}\n";
    }
    echo "</pre>";

} catch (PDOException $e) {
    echo "<h2>❌ Migration Failed</h2>";
    echo "<p>Error: " . htmlspecialchars($e->getMessage()) . "</p>";
    echo "<p>You may need to run this SQL manually:</p>";
    echo "<pre>";
    echo "ALTER TABLE wiki_submissions \n";
    echo "ADD COLUMN original_markdown LONGTEXT DEFAULT '' \n";
    echo "COMMENT 'Raw markdown submitted/imported by user' \n";
    echo "AFTER generated_markdown;";
    echo "</pre>";
}
?>
