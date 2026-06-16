<?php
/**
 * Link Wiki Entry ↔ Facility API
 *
 * Links a wiki_submissions or wiki_master row to a specific facilities_master
 * record (identified by unique_name) with a two-step review flow:
 *
 *   status NULL        = unlinked
 *   status 'suggested' = candidate chosen but not yet admin-confirmed
 *   status 'confirmed' = admin explicitly confirmed the link
 *
 * Endpoints
 * ---------
 * GET  ?action=get&type=submission|master&wiki_id=N
 *      Return the current link info for one wiki row.
 *
 * GET  ?action=suggest&type=submission|master&wiki_id=N
 *      Return ranked facility candidates from facilities_master.
 *
 * GET  ?action=list_unlinked&type=submission|master&limit=N&offset=N
 *      List wiki rows that have no confirmed facility link.
 *
 * POST {action:"link",    type, wiki_id, facility_unique_name}
 *      Save a suggested link (status → 'suggested'). Does not overwrite a
 *      confirmed link unless force:true is passed.
 *
 * POST {action:"confirm", type, wiki_id}
 *      Promote a suggested link to confirmed. Admin-only step.
 *
 * POST {action:"unlink",  type, wiki_id}
 *      Remove the link entirely (sets both columns to NULL).
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/config.php';

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * Resolve the correct table name for a given wiki entry type.
 */
function kop_wiki_table(string $type): string {
    if ($type === 'master') return 'wiki_master';
    if ($type === 'submission') return 'wiki_submissions';
    throw new InvalidArgumentException("type must be 'master' or 'submission'");
}

/**
 * Validate the type parameter and send a 400 on failure.
 */
function kop_require_type(string $type): void {
    if (!in_array($type, ['master', 'submission'], true)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => "type must be 'master' or 'submission'"]);
        exit;
    }
}

/**
 * Return ranked facility candidates for a wiki entry row.
 *
 * Ranking:
 *   100 – unique_name exact-matches the wiki's organization field
 *    80 – unique_name exact-matches the wiki's program_name
 *    60 – unique_name LIKE-matches the organization (partial)
 *    40 – unique_name LIKE-matches the program_name (partial)
 *
 * Location bonus: +10 if the facility json_data mentions the wiki's city_state.
 * Results capped at 20.
 */
function kop_suggest_facilities(PDO $pdo, array $wiki): array {
    $org      = trim($wiki['organization'] ?? '');
    $progName = trim($wiki['program_name'] ?? '');
    $location = trim($wiki['city_state'] ?? '');

    // Build a deduplicated result set keyed by unique_name
    $candidates = [];

    $add = function(array $row, int $score) use (&$candidates, $location) {
        $un = $row['unique_name'];
        if (!isset($candidates[$un])) {
            // Location bonus: check if city_state string appears in json_data text
            $bonus = 0;
            if ($location && $row['json_data']) {
                // Simple substring check; avoid full JSON decode for performance
                if (stripos($row['json_data'], $location) !== false) {
                    $bonus = 10;
                }
            }
            $candidates[$un] = [
                'unique_name'  => $un,
                'display_name' => $row['display_name'] ?? $un,
                'score'        => $score + $bonus,
                'match_reason' => $row['match_reason'] ?? '',
            ];
        } else {
            // Keep best score
            $existing = $candidates[$un]['score'];
            $newScore = $score + ($candidates[$un]['score'] - $existing); // preserve bonus
            if ($score > $existing) {
                $candidates[$un]['score'] = $score;
                $candidates[$un]['match_reason'] = $row['match_reason'] ?? $candidates[$un]['match_reason'];
            }
        }
    };

    // Helper: fetch rows and add them
    $fetch = function(string $sql, array $params, int $score, string $reason) use ($pdo, $add) {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $row['match_reason'] = $reason;
            // Try to extract a human-friendly name from json_data
            $jd = json_decode($row['json_data'] ?? '', true);
            $row['display_name'] = $jd['name'] ?? $jd['data']['operator']['name'] ?? $row['unique_name'];
            $add($row, $score);
        }
    };

    $baseSql = "SELECT unique_name, json_data FROM facilities_master
                WHERE JSON_SEARCH(json_data,'one','__facility_ref') IS NULL";

    if ($org) {
        // Exact org match
        $fetch("$baseSql AND LOWER(unique_name) = LOWER(:v) LIMIT 5",
            [':v' => $org], 100, "organization exact");
        // Partial org match
        $fetch("$baseSql AND unique_name LIKE :v LIMIT 10",
            [':v' => '%' . $org . '%'], 60, "organization partial");
    }

    if ($progName) {
        // Exact program name match
        $fetch("$baseSql AND LOWER(unique_name) = LOWER(:v) LIMIT 5",
            [':v' => $progName], 80, "program name exact");
        // Partial program name match
        $fetch("$baseSql AND unique_name LIKE :v LIMIT 10",
            [':v' => '%' . $progName . '%'], 40, "program name partial");
    }

    // Sort by score descending
    usort($candidates, fn($a, $b) => $b['score'] - $a['score']);

    return array_values(array_slice($candidates, 0, 20));
}

// ---------------------------------------------------------------------------
// GET
// ---------------------------------------------------------------------------

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $action  = $_GET['action'] ?? 'get';
    $type    = $_GET['type'] ?? 'submission';
    $wikiId  = (int)($_GET['wiki_id'] ?? 0);

    try {
        kop_require_type($type);
        $table = kop_wiki_table($type);

        // -- get --
        if ($action === 'get') {
            if (!$wikiId) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'wiki_id is required']);
                exit;
            }
            $stmt = $pdo->prepare(
                "SELECT id, program_name, organization, city_state,
                        facility_unique_name, facility_link_status
                 FROM $table WHERE id = ? LIMIT 1"
            );
            $stmt->execute([$wikiId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                http_response_code(404);
                echo json_encode(['success' => false, 'error' => 'Wiki entry not found']);
                exit;
            }
            echo json_encode(['success' => true, 'data' => $row]);
            exit;
        }

        // -- suggest --
        if ($action === 'suggest') {
            if (!$wikiId) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'wiki_id is required']);
                exit;
            }
            $stmt = $pdo->prepare(
                "SELECT program_name, organization, city_state
                 FROM $table WHERE id = ? LIMIT 1"
            );
            $stmt->execute([$wikiId]);
            $wiki = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$wiki) {
                http_response_code(404);
                echo json_encode(['success' => false, 'error' => 'Wiki entry not found']);
                exit;
            }
            $candidates = kop_suggest_facilities($pdo, $wiki);
            echo json_encode(['success' => true, 'candidates' => $candidates]);
            exit;
        }

        // -- list_unlinked --
        if ($action === 'list_unlinked') {
            $limit  = min((int)($_GET['limit'] ?? 50), 200);
            $offset = max((int)($_GET['offset'] ?? 0), 0);

            $countStmt = $pdo->query(
                "SELECT COUNT(*) FROM $table
                 WHERE facility_link_status IS NULL
                    OR facility_link_status != 'confirmed'"
            );
            $total = (int)$countStmt->fetchColumn();

            $stmt = $pdo->prepare(
                "SELECT id, program_name, city_state, organization, program_type,
                        years_active, facility_unique_name, facility_link_status,
                        created_at
                 FROM $table
                 WHERE facility_link_status IS NULL
                    OR facility_link_status != 'confirmed'
                 ORDER BY updated_at DESC
                 LIMIT $limit OFFSET $offset"
            );
            $stmt->execute();
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                'success' => true,
                'data'    => $rows,
                'total'   => $total,
                'limit'   => $limit,
                'offset'  => $offset,
            ]);
            exit;
        }

        http_response_code(400);
        echo json_encode(['success' => false,
            'error' => "Unknown action '$action'. Use: get, suggest, list_unlinked"]);

    } catch (InvalidArgumentException $e) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ---------------------------------------------------------------------------
// POST
// ---------------------------------------------------------------------------

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid JSON body']);
    exit;
}

$action = $input['action'] ?? '';
$type   = $input['type'] ?? 'submission';
$wikiId = (int)($input['wiki_id'] ?? 0);

try {
    kop_require_type($type);
    $table = kop_wiki_table($type);

    if (!$wikiId) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'wiki_id is required']);
        exit;
    }

    // -- link --
    if ($action === 'link') {
        $facilityUniqueName = trim($input['facility_unique_name'] ?? '');
        $force              = !empty($input['force']);

        if (!$facilityUniqueName) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'facility_unique_name is required']);
            exit;
        }

        // Verify the facility exists
        $facCheck = $pdo->prepare(
            "SELECT unique_name FROM facilities_master WHERE unique_name = ? LIMIT 1"
        );
        $facCheck->execute([$facilityUniqueName]);
        if (!$facCheck->fetchColumn()) {
            http_response_code(404);
            echo json_encode(['success' => false,
                'error' => "Facility '$facilityUniqueName' not found in facilities_master"]);
            exit;
        }

        // Guard: don't overwrite a confirmed link unless forced
        if (!$force) {
            $existCheck = $pdo->prepare(
                "SELECT facility_link_status FROM $table WHERE id = ? LIMIT 1"
            );
            $existCheck->execute([$wikiId]);
            $existing = $existCheck->fetchColumn();
            if ($existing === 'confirmed') {
                http_response_code(409);
                echo json_encode(['success' => false,
                    'error' => 'This entry already has a confirmed facility link. Pass force:true to override.']);
                exit;
            }
        }

        $stmt = $pdo->prepare(
            "UPDATE $table
             SET facility_unique_name = ?,
                 facility_link_status = 'suggested',
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = ?"
        );
        $stmt->execute([$facilityUniqueName, $wikiId]);

        echo json_encode([
            'success'              => true,
            'facility_unique_name' => $facilityUniqueName,
            'facility_link_status' => 'suggested',
            'message'              => "Link saved as 'suggested'. An admin must confirm it.",
        ]);
        exit;
    }

    // -- confirm --
    if ($action === 'confirm') {
        $stmt = $pdo->prepare(
            "SELECT facility_unique_name, facility_link_status FROM $table WHERE id = ? LIMIT 1"
        );
        $stmt->execute([$wikiId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Wiki entry not found']);
            exit;
        }
        if (!$row['facility_unique_name']) {
            http_response_code(409);
            echo json_encode(['success' => false, 'error' => 'No facility linked yet. Call link first.']);
            exit;
        }

        $stmt = $pdo->prepare(
            "UPDATE $table
             SET facility_link_status = 'confirmed',
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = ?"
        );
        $stmt->execute([$wikiId]);

        echo json_encode([
            'success'              => true,
            'facility_unique_name' => $row['facility_unique_name'],
            'facility_link_status' => 'confirmed',
            'message'              => 'Facility link confirmed.',
        ]);
        exit;
    }

    // -- unlink --
    if ($action === 'unlink') {
        $stmt = $pdo->prepare(
            "UPDATE $table
             SET facility_unique_name = NULL,
                 facility_link_status = NULL,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = ?"
        );
        $stmt->execute([$wikiId]);

        echo json_encode(['success' => true, 'message' => 'Facility link removed.']);
        exit;
    }

    http_response_code(400);
    echo json_encode(['success' => false,
        'error' => "Unknown action '$action'. Use: link, confirm, unlink"]);

} catch (InvalidArgumentException $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
