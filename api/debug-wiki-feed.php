<?php
require_once __DIR__ . '/config.php';
header('Content-Type: text/plain');

try {
    // Check connection
    if (!$pdo) {
        die("PDO is null. Config loaded: " . (defined('DB_HOST') ? 'YES' : 'NO'));
    }

    echo "Checking wiki_submissions table...\n";
    
    // Check columns
    $cols = $pdo->query("SHOW COLUMNS FROM wiki_submissions");
    echo "Columns:\n";
    foreach ($cols as $col) {
        echo "- " . $col['Field'] . "\n";
    }
    echo "\n";

    // Check count of approved/published
    $stmt = $pdo->query("SELECT COUNT(*) FROM wiki_submissions WHERE status IN ('approved', 'published')");
    $count = $stmt->fetchColumn();
    echo "Approved/Published Count: $count\n\n";

    // Dump first 3 records
    $stmt = $pdo->query("SELECT id, program_name, status, LENGTH(generated_markdown) as markdown_len, LEFT(generated_markdown, 50) as markdown_start FROM wiki_submissions WHERE status IN ('approved', 'published') LIMIT 3");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as $row) {
        print_r($row);
    }

} catch (PDOException $e) {
    echo "Error: " . $e->getMessage();
}
?>
