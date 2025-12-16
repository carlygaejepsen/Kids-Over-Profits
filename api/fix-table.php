<?php
require_once __DIR__ . '/config.php';

try {
    // Check if original_markdown column exists
    $stmt = $pdo->query("SHOW COLUMNS FROM wiki_submissions LIKE 'original_markdown'");
    $columnExists = $stmt->fetch();

    if (!$columnExists) {
        // Add original_markdown column
        $pdo->exec("ALTER TABLE wiki_submissions ADD COLUMN original_markdown LONGTEXT DEFAULT NULL AFTER generated_markdown");
    }

    $orgColumnStmt = $pdo->query("SHOW COLUMNS FROM wiki_submissions LIKE 'organization'");
    $orgColumnExists = $orgColumnStmt->fetch();

    if (!$orgColumnExists) {
        $pdo->exec("ALTER TABLE wiki_submissions ADD COLUMN organization VARCHAR(255) DEFAULT NULL AFTER city_state");
    }

    $orgIndexStmt = $pdo->query("SHOW INDEX FROM wiki_submissions WHERE Key_name = 'idx_organization'");
    $orgIndexExists = $orgIndexStmt->fetch();

    if (!$orgIndexExists) {
        $pdo->exec("CREATE INDEX idx_organization ON wiki_submissions (organization)");
    }

    // Modify status ENUM to include 'deleted'
    $pdo->exec("ALTER TABLE wiki_submissions MODIFY COLUMN status ENUM('draft','submitted','approved','published','rejected','deleted') NOT NULL DEFAULT 'submitted'");

    // Make json_data nullable
    $pdo->exec("ALTER TABLE wiki_submissions MODIFY COLUMN json_data LONGTEXT DEFAULT NULL");

    echo json_encode([
        'success' => true,
        'message' => 'Table structure updated successfully!'
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
