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

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

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

    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid JSON body']);
        exit;
    }

    $action = $input['action'] ?? '';

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
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Error: ' . $e->getMessage()]);
}
