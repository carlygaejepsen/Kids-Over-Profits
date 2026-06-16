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

/**
 * Length of the meaningful prose in a markdown body, ignoring structure and
 * placeholders. A header-only stub (title + empty "## Section" headings, or
 * "No information is known.") returns ~0. Mirrors scripts/scan-wiki-stubs.js so
 * the API and the file scan agree on what counts as a stub.
 */
function kop_wiki_meaningful_len($markdown) {
    if (!is_string($markdown) || $markdown === '') {
        return 0;
    }
    $kept = [];
    foreach (preg_split('/\r\n|\r|\n/', $markdown) as $line) {
        $t = trim($line);
        if ($t === '') continue;
        if (preg_match('/^#{1,6}\s/u', $t)) continue;                 // markdown header
        if (preg_match('/^[-*_]{3,}$/u', $t)) continue;               // horizontal rule
        if (preg_match('/^last revised by/iu', $t)) continue;         // wiki footer
        if (preg_match('/^(no information( is)?( known| available)|nothing is known|unknown|n\/a|tbd|page title)\.?$/iu', $t)) continue;
        $s = preg_replace('/\[([^\]]*)\]\([^)]*\)/u', '$1', $t);      // links -> text
        $s = trim(preg_replace('/\s+/u', ' ', preg_replace('/[*_`>#|]/u', '', $s)));
        if ($s !== '') $kept[] = $s;
    }
    return strlen(implode(' ', $kept));
}

// --- 2. Completed entries = active submissions that actually have content ---
// completedNames: program names (fallback match for legacy submissions).
// completedSlugs: the source slug each submission recorded (precise match). The
// slug is stored inside json_data.sourceSlug by the wiki editor when the entry
// was loaded from a known index slug.
// Excluded:
//   - deleted/rejected submissions: a rejected page still needs to be (re)done.
//   - stub submissions: rows whose saved markdown is only headers / placeholders
//     (e.g. a page opened from a stub and saved without content). These must NOT
//     count as completed, or empty pages show as done in the index browser.
$COMPLETED_MIN_CHARS = 40;
$completedNames = [];
$completedSlugs = [];
try {
    require_once __DIR__ . '/config.php';
    if (isset($pdo) && $pdo instanceof PDO) {
        $stmt = $pdo->query(
            "SELECT program_name, json_data, generated_markdown, original_markdown FROM wiki_submissions " .
            "WHERE status NOT IN ('deleted', 'rejected') AND program_name IS NOT NULL AND program_name != ''"
        );
        if ($stmt) {
            $seenName = [];
            $seenSlug = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                // A submission counts only if its saved content is real. Check the
                // rendered markdown first, then the source it was loaded from.
                $contentLen = max(
                    kop_wiki_meaningful_len($row['generated_markdown'] ?? ''),
                    kop_wiki_meaningful_len($row['original_markdown'] ?? '')
                );
                if ($contentLen < $COMPLETED_MIN_CHARS) {
                    continue; // header-only stub — still needs creation
                }

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
