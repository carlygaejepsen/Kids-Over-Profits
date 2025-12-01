<?php
/**
 * Quick database check for submissions tables
 */
header('Content-Type: application/json');
require_once __DIR__ . '/config.php';

try {
    $result = [];
    
    // Check if tables exist
    $tables = ['wiki_submissions', 'news_submissions', 'submission_attachments', 'saved_form_values'];
    
    foreach ($tables as $table) {
        $stmt = $pdo->query("SHOW TABLES LIKE '$table'");
        $exists = $stmt->rowCount() > 0;
        $result[$table] = $exists ? 'EXISTS' : 'MISSING';
    }
    
    // Try to describe news_submissions if it exists
    if ($result['news_submissions'] === 'EXISTS') {
        $stmt = $pdo->query("DESCRIBE news_submissions");
        $result['news_submissions_columns'] = $stmt->fetchAll(PDO::FETCH_COLUMN);
    }
    
    echo json_encode([
        'success' => true,
        'tables' => $result,
        'database' => $pdo->query("SELECT DATABASE()")->fetchColumn()
    ], JSON_PRETTY_PRINT);
    
} catch (PDOException $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'code' => $e->getCode()
    ]);
}
