<?php
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

require_once __DIR__ . '/config.php';

header('Content-Type: text/plain');

try {
    // Check if table exists
    $result = $pdo->query("SHOW TABLES LIKE 'wiki_submissions'");
    $table = $result->fetch();

    if ($table) {
        echo "✓ Table 'wiki_submissions' EXISTS\n\n";

        // Get row count
        $count = $pdo->query("SELECT COUNT(*) FROM wiki_submissions")->fetchColumn();
        echo "Total rows: $count\n\n";

        // Get counts by status
        $stmt = $pdo->query("SELECT status, COUNT(*) as count FROM wiki_submissions GROUP BY status");
        echo "By status:\n";
        while ($row = $stmt->fetch()) {
            echo "  {$row['status']}: {$row['count']}\n";
        }

        // Show sample row
        $sample = $pdo->query("SELECT id, program_name, city_state, status, created_at FROM wiki_submissions LIMIT 1")->fetch();
        if ($sample) {
            echo "\nSample row:\n";
            print_r($sample);
        }
    } else {
        echo "✗ Table 'wiki_submissions' DOES NOT EXIST\n\n";
        echo "Run: php api/init-submissions-db.php\n";
    }

    // Show database info
    echo "\n\nDatabase connection:\n";
    echo "Host: " . getenv('DB_HOST') . "\n";
    echo "Database: " . getenv('DB_NAME') . "\n";
    echo "User: " . getenv('DB_USER') . "\n";

} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
