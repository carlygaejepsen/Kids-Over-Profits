<?php
/**
 * Wiki Stubs API
 *
 * Returns the list of empty wiki slugs (entries that still need to be created)
 * plus the set of program names that already have a saved submission (entries
 * that have been completed).
 *
 * The empty-slug list is read from markdown_output/empty_files_updated.md on the
 * server's disk, so it stays in sync automatically and does not rely on .md
 * files being web-served (they often are not in production).
 *
 * GET /api/wiki-stubs.php  ->  { emptySlugs: [...], completedNames: [...], generated }
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// --- 1. Empty slugs from the scan file (read server-side) ---
$emptySlugs = [];
$scanFile = __DIR__ . '/../markdown_output/empty_files_updated.md';
if (is_readable($scanFile)) {
    $lines = preg_split('/\r\n|\r|\n/', (string) file_get_contents($scanFile));
    $seen = [];
    foreach ($lines as $line) {
        // Strip a leading "- ", surrounding whitespace and a possible BOM.
        $name = trim(preg_replace('/^[\s\x{FEFF}\-]+/u', '', $line));
        if ($name === '') {
            continue;
        }
        $name = basename($name);
        $slug = null;
        if (strpos($name, 'index_') === 0) {
            $slug = preg_replace('/_+$/', '', preg_replace('/\.md$/i', '', substr($name, strlen('index_'))));
        } elseif (preg_match('/^[a-z0-9\-]+\.md$/i', $name)) {
            $slug = preg_replace('/\.md$/i', '', $name);
        }
        if ($slug) {
            $slug = strtolower($slug);
            if (!isset($seen[$slug])) {
                $seen[$slug] = true;
                $emptySlugs[] = $slug;
            }
        }
    }
}

// --- 2. Completed entries = program names with a non-deleted submission ---
$completedNames = [];
try {
    require_once __DIR__ . '/config.php';
    if (isset($pdo) && $pdo instanceof PDO) {
        $stmt = $pdo->query(
            "SELECT DISTINCT program_name FROM wiki_submissions " .
            "WHERE status != 'deleted' AND program_name IS NOT NULL AND program_name != ''"
        );
        if ($stmt) {
            $completedNames = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);
        }
    }
} catch (Throwable $e) {
    // DB unavailable — return the empty-slug list only; completion is best-effort.
    $completedNames = [];
}

echo json_encode([
    'emptySlugs' => $emptySlugs,
    'completedNames' => array_values($completedNames),
    'generated' => gmdate('c'),
]);
