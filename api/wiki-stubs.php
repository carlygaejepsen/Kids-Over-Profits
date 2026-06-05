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

// --- 2. Completed entries = active (non-deleted, non-rejected) submissions ---
// completedNames: program names (fallback match for legacy submissions).
// completedSlugs: the source slug each submission recorded (precise match). The
// slug is stored inside json_data.sourceSlug by the wiki editor when the entry
// was loaded from a known index slug.
// Rejected submissions are excluded: a rejected page still needs to be (re)done,
// so it must not show as "completed" in the index browser.
$completedNames = [];
$completedSlugs = [];
try {
    require_once __DIR__ . '/config.php';
    if (isset($pdo) && $pdo instanceof PDO) {
        $stmt = $pdo->query(
            "SELECT program_name, json_data FROM wiki_submissions " .
            "WHERE status NOT IN ('deleted', 'rejected') AND program_name IS NOT NULL AND program_name != ''"
        );
        if ($stmt) {
            $seenName = [];
            $seenSlug = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $name = isset($row['program_name']) ? trim((string) $row['program_name']) : '';
                if ($name !== '' && !isset($seenName[$name])) {
                    $seenName[$name] = true;
                    $completedNames[] = $name;
                }
                $json = isset($row['json_data']) ? $row['json_data'] : '';
                if (is_string($json) && $json !== '') {
                    $decoded = json_decode($json, true);
                    if (is_array($decoded) && !empty($decoded['sourceSlug'])) {
                        $slug = strtolower(trim((string) $decoded['sourceSlug']));
                        if ($slug !== '' && !isset($seenSlug[$slug])) {
                            $seenSlug[$slug] = true;
                            $completedSlugs[] = $slug;
                        }
                    }
                }
            }
        }
    }
} catch (Throwable $e) {
    // DB unavailable — return the empty-slug list only; completion is best-effort.
    $completedNames = [];
    $completedSlugs = [];
}

echo json_encode([
    'emptySlugs' => $emptySlugs,
    'completedNames' => array_values($completedNames),
    'completedSlugs' => array_values($completedSlugs),
    'generated' => gmdate('c'),
]);
