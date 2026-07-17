<?php
// Robustly locate and load WordPress (matches other admin scripts)
function locate_wp_load() {
    $dir = __DIR__;
    for ($i = 0; $i < 7; $i++) {
        $candidate = realpath($dir . str_repeat('/..', $i) . '/wp-load.php');
        if ($candidate && is_file($candidate)) return $candidate;
    }

    $candidate = realpath(__DIR__ . '/../../../../wp-load.php');
    if ($candidate && is_file($candidate)) return $candidate;

    if (!empty($_SERVER['DOCUMENT_ROOT'])) {
        $candidate = realpath(rtrim($_SERVER['DOCUMENT_ROOT'], '/') . '/wp-load.php');
        if ($candidate && is_file($candidate)) return $candidate;
    }

    return false;
}

$wp_load = locate_wp_load();
if (!$wp_load) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Could not locate wp-load.php. Adjust include path.']);
    exit;
}

require_once $wp_load;

// Check if user is logged in and is an administrator
if (!is_user_logged_in() || !current_user_can('administrator')) {
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib-suggested-edits.php';
header('Content-Type: application/json');

function kop_find_facility_index_by_name($facilities, $facility_name) {
    $facility_name = strtolower(trim((string) $facility_name));
    if ($facility_name === '' || !is_array($facilities)) {
        return -1;
    }

    foreach ($facilities as $index => $facility) {
        $candidate = strtolower(trim((string) ($facility['identification']['name'] ?? '')));
        if ($candidate !== '' && $candidate === $facility_name) {
            return $index;
        }
    }

    return -1;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// Parse JSON input
$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid JSON payload']);
    exit;
}

$id = isset($input['id']) ? (int)$input['id'] : 0;
$action = isset($input['action']) ? strtolower(trim($input['action'])) : '';

if ($id <= 0 || ($action !== 'approve' && $action !== 'reject')) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'ID (positive integer) and valid action (approve|reject) are required']);
    exit;
}

try {
    if (!isset($pdo) || !$pdo) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Database connection not available']);
        exit;
    }

    $wp_prefix = isset($table_prefix) && is_string($table_prefix) ? $table_prefix : '';

    if ($action === 'approve') {
        $result = kop_apply_suggested_edit($pdo, $id, $wp_prefix);

        if (!$result['success']) {
            http_response_code($result['httpCode']);
            echo json_encode(['success' => false, 'error' => $result['error']]);
            exit;
        }

        echo json_encode([
            'success' => true,
            'message' => 'Submission approved and published',
            'projectName' => $result['projectName']
        ]);

    } else { // reject
        $suggested_edits_table = kop_resolve_table_name($pdo, 'suggested_edits', $wp_prefix);

        $stmt = $pdo->prepare("SELECT id FROM `{$suggested_edits_table}` WHERE id = ? AND status = 'pending' LIMIT 1");
        $stmt->execute([$id]);
        if (!$stmt->fetch(PDO::FETCH_ASSOC)) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Submission not found or already processed']);
            exit;
        }

        kop_mark_suggested_edit_status($pdo, $suggested_edits_table, $id, 'rejected');

        echo json_encode(['success' => true, 'message' => 'Submission rejected']);
    }

} catch (Exception $e) {
    // Try to rollback if possible
    if (isset($pdo) && $pdo && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('process-edit.php error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error: ' . $e->getMessage()]);
}
?>
