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

function kop_resolve_table_name(PDO $pdo, $base, $prefix = '') {
    $candidates = [];
    if (is_string($prefix) && $prefix !== '') {
        $candidates[] = $prefix . $base;
    }
    $candidates[] = $base;

    foreach ($candidates as $candidate) {
        $stmt = $pdo->prepare('SHOW TABLES LIKE ?');
        $stmt->execute([$candidate]);
        if ($stmt->fetchColumn()) {
            return $candidate;
        }
    }

    return $candidates[0];
}

function kop_extract_project_data($payload) {
    if (!is_array($payload)) {
        return [];
    }

    $data = $payload;
    $guard = 0;

    while (
        is_array($data)
        && isset($data['data'])
        && is_array($data['data'])
        && $guard < 3
    ) {
        $inner = $data['data'];
        $data = $inner;
        $guard += 1;
    }

    return is_array($data) ? $data : [];
}

function kop_build_project_payload($payload, $project_name, $category) {
    $project_name = trim((string) $project_name);
    $wrapped = is_array($payload) ? $payload : [];
    $wrapped['data'] = kop_extract_project_data($payload);

    if (!isset($wrapped['name']) || trim((string) $wrapped['name']) === '') {
        $wrapped['name'] = $project_name !== '' ? $project_name : 'Unnamed Project';
    }

    if (!isset($wrapped['category']) || trim((string) $wrapped['category']) === '') {
        $wrapped['category'] = $category;
    }

    if (!isset($wrapped['currentFacilityIndex']) || !is_numeric($wrapped['currentFacilityIndex'])) {
        $wrapped['currentFacilityIndex'] = 0;
    }

    $wrapped['timestamp'] = date('c');

    return $wrapped;
}

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

function kop_merge_location_facilities($existing_facilities, $new_facilities) {
    $existing_facilities = is_array($existing_facilities) ? array_values($existing_facilities) : [];
    $new_facilities = is_array($new_facilities) ? $new_facilities : [];

    foreach ($new_facilities as $new_facility) {
        $facility_name = trim((string) ($new_facility['identification']['name'] ?? ''));
        $existing_index = kop_find_facility_index_by_name($existing_facilities, $facility_name);

        if ($existing_index >= 0) {
            $existing_facilities[$existing_index] = $new_facility;
        } else {
            $existing_facilities[] = $new_facility;
        }
    }

    return array_values($existing_facilities);
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
    $suggested_edits_table = kop_resolve_table_name($pdo, 'suggested_edits', $wp_prefix);
    $facilities_table = kop_resolve_table_name($pdo, 'facilities_master', $wp_prefix);
    $referrers_table = kop_resolve_table_name($pdo, 'referrers_master', $wp_prefix);
    $locations_table = kop_resolve_table_name($pdo, 'locations_master', $wp_prefix);

    $pdo->beginTransaction();

    // Get the submission (limit 1 for safety)
    $stmt = $pdo->prepare("SELECT * FROM `{$suggested_edits_table}` WHERE id = ? AND status = 'pending' LIMIT 1");
    $stmt->execute([$id]);
    $submission = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$submission) {
        $pdo->rollBack();
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Submission not found or already processed']);
        exit;
    }

    if ($action === 'approve') {
        // Insert or update in facilities_master or referrers_master table
        $master_id = isset($submission['master_id']) ? $submission['master_id'] : null;
        $json_data = isset($submission['edited_json_data']) ? $submission['edited_json_data'] : null;

        if ($json_data === null || $json_data === '') {
            $pdo->rollBack();
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Submission has no edited data to publish']);
            exit;
        }

        // Determine which table to use based on data structure and project name
        $decoded_data = json_decode($json_data, true);
        $project_data = kop_extract_project_data($decoded_data);
        
        // US State names for location project detection
        $US_STATE_NAMES = [
            'ALABAMA', 'ALASKA', 'ARIZONA', 'ARKANSAS', 'CALIFORNIA', 'COLORADO', 'CONNECTICUT',
            'DELAWARE', 'FLORIDA', 'GEORGIA', 'HAWAII', 'IDAHO', 'ILLINOIS', 'INDIANA', 'IOWA',
            'KANSAS', 'KENTUCKY', 'LOUISIANA', 'MAINE', 'MARYLAND', 'MASSACHUSETTS', 'MICHIGAN',
            'MINNESOTA', 'MISSISSIPPI', 'MISSOURI', 'MONTANA', 'NEBRASKA', 'NEVADA', 'NEW HAMPSHIRE',
            'NEW JERSEY', 'NEW MEXICO', 'NEW YORK', 'NORTH CAROLINA', 'NORTH DAKOTA', 'OHIO',
            'OKLAHOMA', 'OREGON', 'PENNSYLVANIA', 'RHODE ISLAND', 'SOUTH CAROLINA', 'SOUTH DAKOTA',
            'TENNESSEE', 'TEXAS', 'UTAH', 'VERMONT', 'VIRGINIA', 'WASHINGTON', 'WEST VIRGINIA',
            'WISCONSIN', 'WYOMING'
        ];
        
        // Common countries for international facilities
        $COUNTRY_NAMES = [
            'CANADA', 'MEXICO', 'UNITED KINGDOM', 'AUSTRALIA', 'JAMAICA', 'SAMOA', 'COSTA RICA',
            'BELIZE', 'BAHAMAS', 'DOMINICAN REPUBLIC', 'PUERTO RICO', 'FRANCE', 'GERMANY', 'ITALY',
            'SPAIN', 'NETHERLANDS', 'SWITZERLAND', 'SWEDEN', 'NORWAY', 'DENMARK', 'IRELAND',
            'NEW ZEALAND', 'SOUTH AFRICA', 'ISRAEL', 'JAPAN', 'CHINA', 'INDIA', 'BRAZIL', 'ARGENTINA'
        ];
        
        // Check if master_id is a state or country name (location project)
        $masterIdUpper = strtoupper(trim($master_id ?? ''));
        $isLocation = in_array($masterIdUpper, $US_STATE_NAMES) || in_array($masterIdUpper, $COUNTRY_NAMES);
        
        // Check if it's a referrer project
        $isReferrer = !empty($project_data['referrerAgency']['name']) ||
                      !empty($project_data['referrerConsultants'][0]['firstName']) ||
                      !empty($project_data['referrerConsultants'][0]['lastName']);
        
        // Determine table: locations first, then referrers, then facilities
        if ($isLocation) {
            $tableName = $locations_table;
            $category = 'locations';
        } elseif ($isReferrer) {
            $tableName = $referrers_table;
            $category = 'referrers';
        } else {
            $tableName = $facilities_table;
            $category = 'companies';
        }

        $project_payload = kop_build_project_payload($decoded_data, $master_id, $category);
        $json_data = json_encode($project_payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        // If master_id is empty, insert a new row with NULL unique_name
        if ($master_id) {
            // Check if record exists by unique_name in the appropriate table
            $checkStmt = $pdo->prepare("SELECT id, json_data FROM `{$tableName}` WHERE unique_name = ? LIMIT 1");
            $checkStmt->execute([$master_id]);
            $exists = $checkStmt->fetch(PDO::FETCH_ASSOC);

            if ($exists) {
                // For location projects, merge new facilities into existing data
                if ($isLocation && !empty($exists['json_data'])) {
                    $existingData = kop_build_project_payload(json_decode($exists['json_data'], true), $master_id, $category);
                    $newData = kop_build_project_payload(json_decode($json_data, true), $master_id, $category);
                    
                    // Get the actual data arrays
                    $existingFacilities = is_array($existingData['data']['facilities'] ?? null) ? $existingData['data']['facilities'] : [];
                    $newFacilities = is_array($newData['data']['facilities'] ?? null) ? $newData['data']['facilities'] : [];
                    
                    $existingFacilities = kop_merge_location_facilities($existingFacilities, $newFacilities);
                    
                    // Update the merged data
                    $existingData['data']['facilities'] = $existingFacilities;
                    $existingData['timestamp'] = date('c');
                    $json_data = json_encode($existingData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
                }
                
                // Update existing record
                $updateStmt = $pdo->prepare("UPDATE `{$tableName}` SET json_data = ?, updated_at = NOW() WHERE unique_name = ?");
                $updateStmt->execute([$json_data, $master_id]);
            } else {
                // Insert new record with provided unique_name
                $insertStmt = $pdo->prepare("INSERT INTO `{$tableName}` (unique_name, json_data) VALUES (?, ?)");
                $insertStmt->execute([$master_id, $json_data]);
            }
        } else {
            // Insert a completely new master record (unique_name left NULL)
            $insertStmt = $pdo->prepare("INSERT INTO `{$tableName}` (json_data) VALUES (?)");
            $insertStmt->execute([$json_data]);
        }

        // Update submission status to approved. Try to set a reviewed/processed timestamp if the column exists.
        $updated = false;
        $timestampCols = ['reviewed_at', 'reviewed_on', 'processed_at', 'reviewed_at_ts'];
        foreach ($timestampCols as $col) {
            // Check if column exists in table
            $colCheck = $pdo->prepare("SELECT COUNT(*) as c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
            $colCheck->execute([$suggested_edits_table, $col]);
            $res = $colCheck->fetch(PDO::FETCH_ASSOC);
            if ($res && isset($res['c']) && (int)$res['c'] > 0) {
                $updateStatusStmt = $pdo->prepare("UPDATE `{$suggested_edits_table}` SET status = 'approved', $col = NOW() WHERE id = ?");
                $updateStatusStmt->execute([$id]);
                $updated = true;
                break;
            }
        }
        if (! $updated) {
            // Fallback: update only the status
            $updateStatusStmt = $pdo->prepare("UPDATE `{$suggested_edits_table}` SET status = 'approved' WHERE id = ?");
            $updateStatusStmt->execute([$id]);
        }

        $pdo->commit();
        echo json_encode(['success' => true, 'message' => 'Submission approved and published']);

    } else { // reject
        // Update submission status to rejected. Try to set a reviewed/processed timestamp if the column exists.
        $updated = false;
        $timestampCols = ['reviewed_at', 'reviewed_on', 'processed_at', 'reviewed_at_ts'];
        foreach ($timestampCols as $col) {
            $colCheck = $pdo->prepare("SELECT COUNT(*) as c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
            $colCheck->execute([$suggested_edits_table, $col]);
            $res = $colCheck->fetch(PDO::FETCH_ASSOC);
            if ($res && isset($res['c']) && (int)$res['c'] > 0) {
                $updateStatusStmt = $pdo->prepare("UPDATE `{$suggested_edits_table}` SET status = 'rejected', $col = NOW() WHERE id = ?");
                $updateStatusStmt->execute([$id]);
                $updated = true;
                break;
            }
        }
        if (! $updated) {
            $updateStatusStmt = $pdo->prepare("UPDATE `{$suggested_edits_table}` SET status = 'rejected' WHERE id = ?");
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
