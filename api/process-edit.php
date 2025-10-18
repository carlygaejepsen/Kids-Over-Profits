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
header('Content-Type: application/json');

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

    $pdo->beginTransaction();

    // Get the submission (limit 1 for safety)
    $stmt = $pdo->prepare("SELECT * FROM suggested_edits WHERE id = ? AND status = 'pending' LIMIT 1");
    $stmt->execute([$id]);
    $submission = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$submission) {
        $pdo->rollBack();
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Submission not found or already processed']);
        exit;
    }

    if ($action === 'approve') {
        // Insert or update in facilities_master table
        $master_id = isset($submission['master_id']) ? $submission['master_id'] : null;
        $json_data = isset($submission['edited_json_data']) ? $submission['edited_json_data'] : null;

        if ($json_data === null || $json_data === '') {
            $pdo->rollBack();
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Submission has no edited data to publish']);
            exit;
        }

        // If master_id is empty, insert a new row with NULL unique_name
        if ($master_id) {
            // Check if record exists by unique_name
            $checkStmt = $pdo->prepare("SELECT id FROM facilities_master WHERE unique_name = ? LIMIT 1");
            $checkStmt->execute([$master_id]);
            $exists = $checkStmt->fetch(PDO::FETCH_ASSOC);

            if ($exists) {
                // Update existing record
                $updateStmt = $pdo->prepare("UPDATE facilities_master SET json_data = ?, updated_at = NOW() WHERE unique_name = ?");
                $updateStmt->execute([$json_data, $master_id]);
            } else {
                // Insert new record with provided unique_name
                $insertStmt = $pdo->prepare("INSERT INTO facilities_master (unique_name, json_data) VALUES (?, ?)");
                $insertStmt->execute([$master_id, $json_data]);
            }
        } else {
            // Insert a completely new master record (unique_name left NULL)
            $insertStmt = $pdo->prepare("INSERT INTO facilities_master (json_data) VALUES (?)");
            $insertStmt->execute([$json_data]);
        }

        // Update submission status to approved. Try to set a reviewed/processed timestamp if the column exists.
        $updated = false;
        $timestampCols = ['reviewed_at', 'reviewed_on', 'processed_at', 'reviewed_at_ts'];
        foreach ($timestampCols as $col) {
            // Check if column exists in table
            $colCheck = $pdo->prepare("SELECT COUNT(*) as c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'suggested_edits' AND COLUMN_NAME = ?");
            $colCheck->execute([$col]);
            $res = $colCheck->fetch(PDO::FETCH_ASSOC);
            if ($res && isset($res['c']) && (int)$res['c'] > 0) {
                $updateStatusStmt = $pdo->prepare("UPDATE suggested_edits SET status = 'approved', $col = NOW() WHERE id = ?");
                $updateStatusStmt->execute([$id]);
                $updated = true;
                break;
            }
        }
        if (! $updated) {
            // Fallback: update only the status
            $updateStatusStmt = $pdo->prepare("UPDATE suggested_edits SET status = 'approved' WHERE id = ?");
            $updateStatusStmt->execute([$id]);
        }

        $pdo->commit();
        echo json_encode(['success' => true, 'message' => 'Submission approved and published']);

    } else { // reject
        // Update submission status to rejected. Try to set a reviewed/processed timestamp if the column exists.
        $updated = false;
        $timestampCols = ['reviewed_at', 'reviewed_on', 'processed_at', 'reviewed_at_ts'];
        foreach ($timestampCols as $col) {
            $colCheck = $pdo->prepare("SELECT COUNT(*) as c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'suggested_edits' AND COLUMN_NAME = ?");
            $colCheck->execute([$col]);
            $res = $colCheck->fetch(PDO::FETCH_ASSOC);
            if ($res && isset($res['c']) && (int)$res['c'] > 0) {
                $updateStatusStmt = $pdo->prepare("UPDATE suggested_edits SET status = 'rejected', $col = NOW() WHERE id = ?");
                $updateStatusStmt->execute([$id]);
                $updated = true;
                break;
            }
        }
        if (! $updated) {
            $updateStatusStmt = $pdo->prepare("UPDATE suggested_edits SET status = 'rejected' WHERE id = ?");
            $updateStatusStmt->execute([$id]);
        }

        $pdo->commit();
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
