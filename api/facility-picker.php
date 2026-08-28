<?php
/**
 * Facility Picker support API
 *
 * Backs the wiki editor's "select / create program" popup so that every wiki
 * entry can be tied to a facilities_master record by its unique_name (the
 * program's unique ID). Also stores the FileBird document-library folder ID
 * number on the program record so the program index can surface the right
 * document library by ID instead of fuzzy name matching.
 *
 * Search lives in api/facility-search.php. This endpoint handles the writes:
 *
 *   POST {action:"create_stub", name, organization?, city_state?,
 *         program_type?, years_active?, document_folder_id?}
 *       Create a minimal facilities_master row and return {unique_name, id}.
 *
 *   POST {action:"set_doc_folder", unique_name, document_folder_id}
 *       Store/replace the document library folder ID on an existing program.
 *
 *   GET  ?action=get&unique_name=...
 *       Return {unique_name, id, document_folder_id} for one program.
 */

// Same-origin API: no CORS headers on purpose (used only by this site's
// editor pages).
header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/config.php';

/**
 * Slug-free unique_name generator: keep the human-readable name but guarantee
 * uniqueness by suffixing " (2)", " (3)", ... when a collision exists.
 */
function kop_picker_unique_name(PDO $pdo, string $name): string {
    $base = trim($name);
    if ($base === '') {
        $base = 'Untitled Program';
    }

    $check = $pdo->prepare("SELECT 1 FROM facilities_master WHERE LOWER(unique_name) = LOWER(?) LIMIT 1");

    $candidate = $base;
    $n = 1;
    while (true) {
        $check->execute([$candidate]);
        if (!$check->fetchColumn()) {
            return $candidate;
        }
        $n++;
        $candidate = $base . ' (' . $n . ')';
        if ($n > 500) {
            // Safety valve; extremely unlikely.
            return $base . ' (' . time() . ')';
        }
    }
}

/**
 * Normalize a program name for duplicate comparison: lowercase, strip
 * punctuation, collapse whitespace, drop a leading "the ".
 */
function kop_picker_normalize_name(string $name): string {
    $n = mb_strtolower(trim($name));
    $n = preg_replace('/[^a-z0-9\s]/u', ' ', $n);
    $n = preg_replace('/\s+/', ' ', trim($n));
    if (strpos($n, 'the ') === 0) {
        $n = substr($n, 4);
    }
    return $n;
}

/**
 * Generic TTI-industry descriptors and acronyms that describe a KIND of
 * program rather than identify one — "Anytown RTC" and "Somewhere RTC" share
 * only the descriptor. These must never drive a match on their own.
 * (function_exists guard: link-wiki-facility.php defines the same helpers.)
 */
if (!function_exists('kop_picker_generic_terms')) {
    function kop_picker_generic_terms(): array {
        // Type acronyms only (RTC/RTF/TBS = kinds of facility). Identity
        // acronyms that name a specific program (WWASP, CEDU, JRC…) stay
        // distinctive on purpose.
        return ['the', 'a', 'an', 'of', 'and', 'for', 'at', 'in', 'inc', 'llc', 'co',
                'rtc', 'rtf', 'tbs',
                'academy', 'academies', 'school', 'schools', 'ranch', 'ranches',
                'center', 'centre', 'centers', 'centres', 'program', 'programs',
                'camp', 'camps', 'institute', 'institution', 'institutions',
                'residential', 'treatment', 'therapeutic', 'therapy', 'wilderness',
                'behavioral', 'behavioural', 'health', 'healthcare', 'recovery',
                'boarding', 'christian', 'youth', 'teen', 'teens', 'boy', 'boys',
                'girl', 'girls', 'kids', 'children', 'childrens', 'child',
                'adolescent', 'adolescents', 'juvenile', 'home', 'homes', 'house',
                'group', 'ministries', 'ministry', 'hospital', 'services',
                'solutions', 'care', 'facility', 'facilities', 'international',
                'education', 'educational', 'emotional', 'growth'];
    }
}

/**
 * The tokens that actually identify a program: what's left after removing
 * generic descriptors and numbers. "Provo Canyon RTC" → ["provo", "canyon"].
 */
if (!function_exists('kop_picker_distinctive_tokens')) {
    function kop_picker_distinctive_tokens(string $normalized): array {
        $generic = kop_picker_generic_terms();
        $tokens = array_filter(explode(' ', $normalized), static function ($t) use ($generic) {
            return strlen($t) >= 3 && !ctype_digit($t) && !in_array($t, $generic, true);
        });
        return array_values($tokens);
    }
}

/**
 * Collect every name a program is known by from its decoded json_data:
 * current names, alternate names, and past/former names — for the operator
 * and each facility. Walks the structure looking for name-bearing keys so
 * legacy field spellings are covered too.
 */
function kop_picker_collect_known_names($node, array &$out, int $depth = 0): void {
    if ($depth > 6 || !is_array($node)) {
        return;
    }
    static $scalarKeys = ['name', 'currentname'];
    static $listKeys = ['othernames', 'other_names', 'pastnames', 'past_names',
                        'formernames', 'former_names', 'aliases', 'alternatenames'];

    foreach ($node as $key => $value) {
        $lkey = is_string($key) ? strtolower($key) : '';
        if (in_array($lkey, $scalarKeys, true) && is_string($value) && trim($value) !== '') {
            $out[] = trim($value);
        } elseif (in_array($lkey, $listKeys, true) && is_array($value)) {
            foreach ($value as $alias) {
                if (is_string($alias) && trim($alias) !== '') {
                    $out[] = trim($alias);
                }
            }
        } elseif (is_array($value)) {
            kop_picker_collect_known_names($value, $out, $depth + 1);
        }
    }
}

/**
 * Find existing facilities_master entries that look like the proposed name —
 * matching against the index unique_name AND every current/alternate/past
 * name stored in the record's json_data.
 *
 * Returns ['exact' => bool,
 *          'candidates' => [{unique_name, id, location, matched_name, exact, similarity}]].
 * Candidates are gathered with LIKE probes (on unique_name and json_data),
 * then scored in PHP (similar_text on normalized strings / containment).
 */
function kop_picker_find_duplicates(PDO $pdo, string $name): array {
    $normalized = kop_picker_normalize_name($name);
    if ($normalized === '') {
        return ['exact' => false, 'candidates' => []];
    }

    // Probe terms: the full name plus its longest DISTINCTIVE words. Probing
    // on generic words ("academy", "wilderness", "RTC"…) would pull half the
    // index into the candidate pool.
    $proposedTokens = kop_picker_distinctive_tokens($normalized);
    $words = $proposedTokens;
    usort($words, static fn($a, $b) => strlen($b) - strlen($a));
    $terms = array_unique(array_merge([$normalized], array_slice($words, 0, 3)));

    // Over-match guard: a proposed name that is very short or made of nothing
    // but generic descriptors/acronyms ("RTC", "Wilderness Academy") may only
    // match EXACTLY — containment and fuzzy scoring on it would flag
    // essentially unrelated programs.
    $exactOnly = strlen($normalized) < 5 || count($proposedTokens) === 0;

    $rows = [];
    // json_data LIKE catches records whose CURRENT index name is entirely
    // different but that list the proposed name as a former/alternate name.
    $stmt = $pdo->prepare(
        "SELECT id, unique_name, json_data FROM facilities_master
         WHERE JSON_SEARCH(json_data,'one','__facility_ref') IS NULL
           AND (unique_name LIKE :t1 OR json_data LIKE :t2) LIMIT 25"
    );
    foreach ($terms as $term) {
        $stmt->execute([':t1' => '%' . $term . '%', ':t2' => '%' . $term . '%']);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $rows[$row['unique_name']] = $row;
        }
        if (count($rows) >= 80) {
            break;
        }
    }

    $exact = false;
    $candidates = [];
    foreach ($rows as $row) {
        $jd = json_decode($row['json_data'] ?: '{}', true);

        // Every name this record is known by: the index name first, then all
        // current/alternate/past names found in the JSON.
        $knownNames = [$row['unique_name']];
        if (is_array($jd)) {
            kop_picker_collect_known_names($jd, $knownNames);
        }

        // Score the proposed name against each known name; keep the best.
        $best = null;
        foreach (array_unique($knownNames) as $known) {
            $candNorm = kop_picker_normalize_name($known);
            if ($candNorm === '') {
                continue;
            }
            $isExact = ($candNorm === $normalized);

            if (!$isExact) {
                // Short/generic proposed names match exactly or not at all.
                if ($exactOnly) {
                    continue;
                }
                // Same guard on the candidate side: a bare acronym or generic
                // descriptor stored as an alias ("RTC") must not fuzzy-match.
                $candTokens = kop_picker_distinctive_tokens($candNorm);
                if (strlen($candNorm) < 5 || count($candTokens) === 0) {
                    continue;
                }

                // Non-exact matches must agree on at least one distinctive
                // token — sharing "academy" or "wilderness" is meaningless.
                $sharesDistinctive = (bool)array_intersect($proposedTokens, $candTokens);

                // Containment only counts when the contained name is
                // substantial: 8+ chars AND multi-word AND distinctive-token
                // overlap. Stops "Canyon" from matching every "… Canyon …".
                $shorter = strlen($candNorm) <= strlen($normalized) ? $candNorm : $normalized;
                $contains = $sharesDistinctive
                    && strlen($shorter) >= 8
                    && strpos($shorter, ' ') !== false
                    && (strpos($candNorm, $normalized) !== false
                        || strpos($normalized, $candNorm) !== false);

                similar_text($normalized, $candNorm, $pct);
                // Fuzzy needs a shared distinctive token; ≥90% overall
                // similarity rescues typos INSIDE the distinctive token
                // ("Elevatons" → "Elevations").
                $fuzzy = ($pct >= 72 && $sharesDistinctive) || $pct >= 90;
                if (!$contains && !$fuzzy) {
                    continue;
                }
            } else {
                $pct = 100;
            }

            $score = $isExact ? 100 : (int)round($pct);
            if ($best === null || $score > $best['similarity']) {
                $best = [
                    'matched_name' => $known,
                    'exact'        => $isExact,
                    'similarity'   => $score,
                ];
            }
            if ($isExact) {
                break;
            }
        }
        if ($best === null) {
            continue;
        }

        if ($best['exact']) {
            $exact = true;
        }

        // Pull a human-friendly location out of json_data when available.
        $location = '';
        if (is_array($jd)) {
            $location = $jd['data']['operator']['location']
                ?? $jd['operator']['location']
                ?? '';
        }

        $candidates[] = [
            'unique_name'  => $row['unique_name'],
            'id'           => (int)$row['id'],
            'location'     => is_string($location) ? $location : '',
            // Which of the record's names matched — may be a former or
            // alternate name rather than the current index name.
            'matched_name' => $best['matched_name'],
            'exact'        => $best['exact'],
            'similarity'   => $best['similarity'],
        ];
    }

    usort($candidates, static fn($a, $b) => $b['similarity'] - $a['similarity']);
    return ['exact' => $exact, 'candidates' => array_slice($candidates, 0, 8)];
}

/**
 * Set documentFolderId on a program's json_data, preserving everything else.
 * Returns the normalized folder id (int) or null when cleared.
 */
function kop_picker_set_doc_folder(PDO $pdo, string $uniqueName, $folderIdRaw) {
    $stmt = $pdo->prepare("SELECT json_data FROM facilities_master WHERE unique_name = ? LIMIT 1");
    $stmt->execute([$uniqueName]);
    $jsonText = $stmt->fetchColumn();
    if ($jsonText === false) {
        throw new RuntimeException("Program '$uniqueName' not found");
    }

    $project = json_decode($jsonText ?: '{}', true);
    if (!is_array($project)) {
        $project = [];
    }

    // Store inside `data` (which the REST layer passes through verbatim) and also
    // at the root for any direct json_data consumers.
    if (!isset($project['data']) || !is_array($project['data'])) {
        $project['data'] = [];
    }

    $folderId = null;
    if ($folderIdRaw !== null && $folderIdRaw !== '' && (int)$folderIdRaw > 0) {
        $folderId = (int)$folderIdRaw;
        $project['documentFolderId'] = $folderId;
        $project['data']['documentFolderId'] = $folderId;
    } else {
        unset($project['documentFolderId'], $project['data']['documentFolderId']);
    }

    $upd = $pdo->prepare(
        "UPDATE facilities_master
         SET json_data = ?, updated_at = CURRENT_TIMESTAMP
         WHERE unique_name = ?"
    );
    $upd->execute([json_encode($project, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $uniqueName]);

    return $folderId;
}

try {
    // ---- GET: read one program's link/doc info ----
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $action = $_GET['action'] ?? 'get';
        if ($action !== 'get') {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => "Unknown GET action '$action'"]);
            exit;
        }
        $uniqueName = trim((string)($_GET['unique_name'] ?? ''));
        if ($uniqueName === '') {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'unique_name is required']);
            exit;
        }
        $stmt = $pdo->prepare("SELECT id, json_data FROM facilities_master WHERE unique_name = ? LIMIT 1");
        $stmt->execute([$uniqueName]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Program not found']);
            exit;
        }
        $project = json_decode($row['json_data'] ?: '{}', true);
        $folderId = null;
        if (is_array($project)) {
            if (!empty($project['data']['documentFolderId'])) {
                $folderId = (int)$project['data']['documentFolderId'];
            } elseif (!empty($project['documentFolderId'])) {
                $folderId = (int)$project['documentFolderId'];
            }
        }
        echo json_encode([
            'success'           => true,
            'unique_name'       => $uniqueName,
            'id'                => (int)$row['id'],
            'document_folder_id' => $folderId,
        ]);
        exit;
    }

    // ---- POST ----
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'Method not allowed']);
        exit;
    }

    $kop_is_admin = function_exists('current_user_can') && current_user_can('manage_options');

    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid JSON body']);
        exit;
    }

    $action = $input['action'] ?? '';

    // set_doc_folder modifies an EXISTING official record → admin-only.
    // create_stub is open to public contributors, but guarded below by
    // duplicate detection and a flood cap.
    if ($action === 'set_doc_folder' && !$kop_is_admin) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'error' => 'Changing the document folder on a program requires an admin login.'
        ]);
        exit;
    }

    // ---- set_doc_folder ----
    if ($action === 'set_doc_folder') {
        $uniqueName = trim((string)($input['unique_name'] ?? ''));
        if ($uniqueName === '') {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'unique_name is required']);
            exit;
        }
        $folderId = kop_picker_set_doc_folder($pdo, $uniqueName, $input['document_folder_id'] ?? null);
        echo json_encode([
            'success'            => true,
            'unique_name'        => $uniqueName,
            'document_folder_id' => $folderId,
        ]);
        exit;
    }

    // ---- create_stub ----
    if ($action === 'create_stub') {
        $name = trim((string)($input['name'] ?? ''));
        if ($name === '') {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'name is required']);
            exit;
        }

        // Duplicate gate: if similar programs already exist, stop and return
        // them so the picker can offer "select this instead". A near-miss can
        // be overridden with create_anyway:true; an exact-name match can only
        // be overridden by an admin (for the public it's almost certainly the
        // same program).
        $createAnyway = !empty($input['create_anyway']);
        $dupes = kop_picker_find_duplicates($pdo, $name);
        if (!empty($dupes['candidates'])) {
            $blockedExact = $dupes['exact'] && !$kop_is_admin;
            if ($blockedExact || !$createAnyway) {
                http_response_code(409);
                echo json_encode([
                    'success'     => false,
                    'code'        => 'possible_duplicate',
                    'error'       => $dupes['exact']
                        ? 'A program with this name already exists in the index.'
                        : 'Similar programs already exist in the index.',
                    'exact'       => $dupes['exact'],
                    'canOverride' => !$blockedExact,
                    'duplicates'  => $dupes['candidates'],
                ]);
                exit;
            }
        }

        // Flood cap for anonymous creates: at most 15 public stubs per hour
        // site-wide. Admin creates are uncapped.
        if (!$kop_is_admin) {
            $capStmt = $pdo->prepare(
                "SELECT COUNT(*) FROM facilities_master
                 WHERE created_at > (NOW() - INTERVAL 1 HOUR)
                   AND json_data LIKE '%\"_source\":\"wiki-picker%'"
            );
            $capStmt->execute();
            if ((int)$capStmt->fetchColumn() >= 15) {
                http_response_code(429);
                echo json_encode([
                    'success' => false,
                    'error' => 'Too many new programs have been created recently. Please try again later, or save your entry as a draft.'
                ]);
                exit;
            }
        }

        $organization = trim((string)($input['organization'] ?? ''));
        $cityState    = trim((string)($input['city_state'] ?? ''));
        $programType  = trim((string)($input['program_type'] ?? ''));
        $yearsActive  = trim((string)($input['years_active'] ?? ''));
        $folderIdRaw  = $input['document_folder_id'] ?? null;

        $uniqueName = kop_picker_unique_name($pdo, $name);

        // Minimal program shape compatible with the admin data tool and the
        // program index (data.operator / data.facilities).
        $project = [
            'name'     => $uniqueName,
            'category' => 'companies',
            'data'     => [
                'operator' => array_filter([
                    'name'        => $name,
                    'currentName' => $name,
                    'status'      => 'unknown',
                    'location'    => $cityState,
                    'type'        => $organization ? 'Standard' : null,
                ], static fn($v) => $v !== null && $v !== ''),
                'facilities' => [],
            ],
            '_stub'   => true,
            '_source' => 'wiki-picker',
            // Lets admins review which stubs came from public contributors.
            '_created_by' => $kop_is_admin ? 'admin' : 'public',
        ];
        if ($organization !== '') {
            $project['data']['operator']['otherNames'] = [$organization];
        }
        if ($programType !== '') {
            $project['data']['operator']['programType'] = $programType;
        }
        if ($yearsActive !== '') {
            $project['data']['operator']['yearsOfOperation'] = $yearsActive;
        }
        if ($folderIdRaw !== null && $folderIdRaw !== '' && (int)$folderIdRaw > 0) {
            $project['documentFolderId'] = (int)$folderIdRaw;
            $project['data']['documentFolderId'] = (int)$folderIdRaw;
        }

        $ins = $pdo->prepare(
            "INSERT INTO facilities_master (unique_name, json_data, created_at, updated_at)
             VALUES (?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)"
        );
        $ins->execute([$uniqueName, json_encode($project, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);

        echo json_encode([
            'success'            => true,
            'created'            => true,
            'unique_name'        => $uniqueName,
            'id'                 => (int)$pdo->lastInsertId(),
            'document_folder_id' => $project['documentFolderId'] ?? null,
        ]);
        exit;
    }

    http_response_code(400);
    echo json_encode(['success' => false,
        'error' => "Unknown action '$action'. Use: create_stub, set_doc_folder"]);

} catch (RuntimeException $e) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
} catch (PDOException $e) {
    error_log('facility-picker error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'A database error occurred.']);
} catch (Exception $e) {
    error_log('facility-picker error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'An unexpected error occurred.']);
}
