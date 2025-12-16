<?php
require_once __DIR__ . '/config.php';

try {
    // Check if table exists
    $stmt = $pdo->query("SHOW TABLES LIKE 'wiki_submissions'");
    $tableExists = $stmt->fetch();

    if (!$tableExists) {
        echo json_encode(['error' => 'Table wiki_submissions does not exist']);
        exit;
    }

    // Get table structure
    $stmt = $pdo->query('DESCRIBE wiki_submissions');
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'columns' => $columns
    ], JSON_PRETTY_PRINT);

} catch (PDOException $e) {
    echo json_encode([
        'error' => $e->getMessage()
    ]);
}
