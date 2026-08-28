<?php
/**
 * Fix wiki_submissions Table - Add Missing Column
 *
 * Adds the missing original_markdown column to existing wiki_submissions table.
 *
 * Usage:
 * Visit: https://your-domain/wp-content/themes/child/api/fix-wiki-submissions-table.php?fix=1
 */

require_once __DIR__ . '/config.php';

header('Content-Type: application/json');

// Schema changes are admin-only (CLI allowed). The ?fix=1 param alone is not
// security — it only prevents accidental runs.
$is_cli = php_sapi_name() === 'cli';
if (!$is_cli && (!function_exists('current_user_can') || !current_user_can('manage_options'))) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Not authorized']);
    exit;
}
$fix_param = $_GET['fix'] ?? null;

if (!$is_cli && $fix_param !== '1') {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'error' => 'Table fix requires ?fix=1 parameter or CLI execution',
        'hint' => 'This is a safety measure to prevent accidental database modifications'
    ]);
    exit;
}

try {
    // Check if column already exists
    $checkSQL = "SHOW COLUMNS FROM wiki_submissions LIKE 'original_markdown'";
    $stmt = $pdo->query($checkSQL);
    $columnExists = $stmt->rowCount() > 0;

    if ($columnExists) {
        echo json_encode([
            'success' => true,
            'message' => 'Column original_markdown already exists in wiki_submissions table',
            'action' => 'none'
        ]);
        exit;
    }

    // Add the missing column
    $alterSQL = "ALTER TABLE `wiki_submissions`
                 ADD COLUMN `original_markdown` longtext COMMENT 'Raw markdown submitted/imported by user'
                 AFTER `generated_markdown`";

    $pdo->exec($alterSQL);

    echo json_encode([
        'success' => true,
        'message' => 'Successfully added original_markdown column to wiki_submissions table',
        'action' => 'column_added',
        'next_steps' => [
            'Wiki editor should now work correctly',
            'Try saving a wiki submission again'
        ]
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Database error: ' . $e->getMessage(),
        'code' => $e->getCode()
    ]);
}
?>
