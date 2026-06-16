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
 * GET /api/wiki-stubs.php  ->  { emptySlugs: [...], completedNames: [...], completedSlugs: [...], manualCompletedSlugs: [...], manualStubSlugs: [...], generated }
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$overrideFile = __DIR__ . '/../markdown_output/wiki_stub_overrides.json';

function kop_wiki_stub_default_overrides() {
    return [
        'updated' => gmdate('c'),
        'manualCompletedSlugs' => [],
        'manualStubSlugs' => [],
    ];
}

function kop_wiki_stub_load_overrides($path) {
    if (!is_readable($path)) {
        return kop_wiki_stub_default_overrides();
    }
    $decoded = json_decode((string) file_get_contents($path), true);
    if (!is_array($decoded)) {
        return kop_wiki_stub_default_overrides();
    }
    return [
        'updated' => isset($decoded['updated']) ? (string) $decoded['updated'] : gmdate('c'),
        'manualCompletedSlugs' => array_values(array_filter(array_map('strval', $decoded['manualCompletedSlugs'] ?? []))),
        'manualStubSlugs' => array_values(array_filter(array_map('strval', $decoded['manualStubSlugs'] ?? []))),
    ];
}

function kop_wiki_stub_write_overrides($path, array $data) {
    $dir = dirname($path);
    if (!is_dir($dir) || (!is_writable($dir) && !file_exists($path))) {
        return false;
    }
    $payload = json_encode([
        'updated' => gmdate('c'),
        'manualCompletedSlugs' => array_values(array_unique(array_map('strtolower', $data['manualCompletedSlugs'] ?? []))),
        'manualStubSlugs' => array_values(array_unique(array_map('strtolower', $data['manualStubSlugs'] ?? []))),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($payload === false) {
        return false;
    }
    return file_put_contents($path, $payload . "\n") !== false;
}

function kop_wiki_stub_normalize_slug($slug) {
    $slug = strtolower(trim((string) $slug));
    return preg_match('/^[a-z0-9][a-z0-9\-]*$/', $slug) ? $slug : '';
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
        if (preg_match('/^\|/u', $t)) continue;                       // table row (program-listing table)
        if (preg_match('/^last revised by/iu', $t)) continue;         // wiki footer
        if (preg_match('/^\[[^\]]*\]$/u', $t)) continue;              // bracketed placeholder, e.g. [To be completed]
        $plain = preg_replace('/[*_]/u', '', $t);
        if (preg_match('/^(no information( is)?( known| available)|nothing is known|unknown|n\/a|tbd|to be completed|page title)\.?$/iu', $plain)) continue;
        if (preg_match('/^below is a list of .*programs?\.?$/iu', $plain)) continue; // operator-template table intro
        $s = preg_replace('/\[([^\]]*)\]\([^)]*\)/u', '$1', $t);      // links -> text
        $s = trim(preg_replace('/\s+/u', ' ', preg_replace('/[*_`>#|]/u', '', $s)));
        if ($s === '') continue;
        if (preg_match('#^https?://\S+$#u', $s)) continue;            // bare-link-only line
        $kept[] = $s;
    }
    return strlen(implode(' ', $kept));
}

// --- 2. Completed entries = real editorial work, not the initial scan import ---
// completedNames: program names (fallback match for legacy submissions).
// completedSlugs: the source slug each submission recorded (precise match). The
// slug is stored inside json_data.sourceSlug by the wiki editor when the entry
// was loaded from a known index slug.
//
// A submission only counts as "completed" when it represents a page someone
// actually wrote/edited. Excluded:
//   - deleted/rejected submissions: a rejected page still needs to be (re)done.
//   - bulk-import rows: the whole wiki was seed-imported from the Reddit scan
//     (submitted_by 'bulk-upload' / 'batch-import-script' / etc.). Those are a
//     STARTING POINT, not finished work, so they must not show as completed.
//   - stub content: rows whose saved markdown is only headers / placeholders.
$COMPLETED_MIN_CHARS = 40;
// submitted_by values that mark a row as scan/seed data rather than human work.
$IMPORT_SUBMITTERS = ['bulk-upload', 'batch-import-script', 'reimport-regenerated', 'import', 'system'];
$completedNames = [];
$completedSlugs = [];
$overrides = kop_wiki_stub_load_overrides($overrideFile);
try {
    require_once __DIR__ . '/config.php';
    if (isset($pdo) && $pdo instanceof PDO) {
        $stmt = $pdo->query(
            "SELECT program_name, json_data, generated_markdown, original_markdown, submitted_by, submission_notes FROM wiki_submissions " .
            "WHERE status NOT IN ('deleted', 'rejected') AND program_name IS NOT NULL AND program_name != ''"
        );
        if ($stmt) {
            $seenName = [];
            $seenSlug = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                // Skip the initial scan import — it is not completed work. A row is
                // import/seed data when it has no real submitter or carries an
                // import marker (submitter or "Batch imported from file:" note).
                $submitter = strtolower(trim((string) ($row['submitted_by'] ?? '')));
                $notes = (string) ($row['submission_notes'] ?? '');
                $isImport = $submitter === ''
                    || in_array($submitter, $IMPORT_SUBMITTERS, true)
                    || stripos($notes, 'batch imported') === 0
                    || stripos($notes, 'bulk') === 0;
                if ($isImport) {
                    continue;
                }

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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!defined('ABSPATH')) {
        $current = __DIR__;
        for ($i = 0; $i < 6; $i++) {
            $current = dirname($current);
            if (file_exists($current . '/wp-load.php')) { require_once $current . '/wp-load.php'; break; }
        }
    }
    $is_admin = function_exists('current_user_can') && current_user_can('manage_options');
    if (!$is_admin) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Admin privileges required']);
        exit;
    }

    $raw = file_get_contents('php://input');
    $payload = json_decode((string) $raw, true);
    $slug = kop_wiki_stub_normalize_slug($payload['slug'] ?? '');
    $mode = strtolower(trim((string) ($payload['mode'] ?? '')));
    if ($slug === '' || !in_array($mode, ['completed', 'stub'], true)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Expected slug and mode=completed|stub']);
        exit;
    }

    $manualCompleted = array_values(array_filter(array_map('strtolower', $overrides['manualCompletedSlugs'] ?? [])));
    $manualStub = array_values(array_filter(array_map('strtolower', $overrides['manualStubSlugs'] ?? [])));

    $manualCompleted = array_values(array_diff($manualCompleted, [$slug]));
    $manualStub = array_values(array_diff($manualStub, [$slug]));
    if ($mode === 'completed') {
        $manualCompleted[] = $slug;
    } else {
        $manualStub[] = $slug;
    }

    $writeOk = kop_wiki_stub_write_overrides($overrideFile, [
        'manualCompletedSlugs' => $manualCompleted,
        'manualStubSlugs' => $manualStub,
    ]);
    if (!$writeOk) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Unable to save overrides']);
        exit;
    }

    $overrides = kop_wiki_stub_load_overrides($overrideFile);

    echo json_encode([
        'success' => true,
        'slug' => $slug,
        'mode' => $mode,
        'manualCompletedSlugs' => $overrides['manualCompletedSlugs'],
        'manualStubSlugs' => $overrides['manualStubSlugs'],
        'generated' => gmdate('c'),
    ]);
    exit;
}

echo json_encode([
    'emptySlugs' => $emptySlugs,
    'completedNames' => array_values($completedNames),
    'completedSlugs' => array_values($completedSlugs),
    'manualCompletedSlugs' => array_values($overrides['manualCompletedSlugs'] ?? []),
    'manualStubSlugs' => array_values($overrides['manualStubSlugs'] ?? []),
    'generated' => gmdate('c'),
]);
