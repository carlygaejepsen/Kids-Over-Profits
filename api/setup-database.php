<?php
/**
 * Database Setup Script
 * Run this once to create the wiki_submissions table
 */

require_once __DIR__ . '/config.php';

try {
    // Read the SQL file
    $sql = file_get_contents(__DIR__ . '/create-wiki-submissions-table.sql');

    // Execute the SQL
    $pdo->exec($sql);

    echo json_encode([
        'success' => true,
        'message' => 'Database table created successfully!'
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Failed to create table',
        'details' => $e->getMessage()
    ]);
}
