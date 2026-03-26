<?php
// Save facility data suggestions to the suggested_edits table
require_once __DIR__ . '/config.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

function kop_value_is_meaningful($value) {
    if (is_string($value)) {
        return trim($value) !== '';
    }

    if (is_bool($value)) {
        return $value === true;
    }

    if (is_int($value) || is_float($value)) {
        return true;
    }

    if (is_array($value)) {
        foreach ($value as $item) {
            if (kop_value_is_meaningful($item)) {
                return true;
            }
        }
        return false;
    }

    return !empty($value);
}

function kop_normalize_submission_category($value) {
    $normalized = strtolower(trim((string) $value));
    if ($normalized === 'states') {
        return 'locations';
    }
    return $normalized;
}

function kop_strip_referrer_submission_data(&$data) {
    if (!is_array($data)) {
        return;
    }

    foreach ([
        'referrer',
        'referrerAgency',
        'referrerConsultants',
        'referrerGroup',
        'referrerIndividual',
        'isIndependentConsultant',
        'referrerType',
    ] as $field) {
        unset($data[$field]);
    }
}

function kop_facility_has_meaningful_data($facility) {
    if (!is_array($facility)) {
        return false;
    }

    $identification = is_array($facility['identification'] ?? null) ? $facility['identification'] : [];
    $location_details = is_array($facility['locationDetails'] ?? null) ? $facility['locationDetails'] : [];

    return kop_value_is_meaningful([
        $identification['currentName'] ?? null,
        $identification['currentOperator'] ?? null,
        $identification['currentOwner'] ?? null,
        $identification['currentOwners'] ?? null,
        $identification['otherNames'] ?? null,
        $identification['pastNames'] ?? null,
        $identification['knownReferrers'] ?? null,
        $facility['location'] ?? null,
        $facility['address'] ?? null,
        $location_details['city'] ?? null,
        $location_details['state'] ?? null,
        $location_details['country'] ?? null,
        $location_details['additionalLocations'] ?? null,
        $facility['otherOperators'] ?? null,
        $facility['operatingPeriod'] ?? null,
        $facility['staff'] ?? null,
        $facility['profileLinks'] ?? null,
        $facility['facilityDetails'] ?? null,
        $facility['accreditations'] ?? null,
        $facility['memberships'] ?? null,
        $facility['certifications'] ?? null,
        $facility['licensing'] ?? null,
        $facility['resources'] ?? null,
        $facility['treatmentTypes'] ?? null,
        $facility['philosophy'] ?? null,
        $facility['criticalIncidents'] ?? null,
        $facility['notes'] ?? null,
        $facility['fieldNotes'] ?? null,
        $facility['isPrivatelyOwned'] ?? null,
    ]);
}

function kop_get_first_named_facility($facilities) {
    if (!is_array($facilities)) {
        return null;
    }

    foreach ($facilities as $facility) {
        if (!is_array($facility)) {
            continue;
        }

        $name = trim((string) ($facility['identification']['name'] ?? ''));
        if ($name !== '') {
            return $facility;
        }
    }

    return null;
}

try {
    // Get JSON input
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input || !isset($input['data']) || !isset($input['reason'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Missing data or reason']);
        exit;
    }
    
    $data = $input['data'];
    $reason = trim($input['reason']);
    $active_category = kop_normalize_submission_category($input['metadata']['activeCategory'] ?? '');

    if ($active_category !== '' && $active_category !== 'referrers') {
        kop_strip_referrer_submission_data($data);
    }

    $facilities = is_array($data['facilities'] ?? null) ? $data['facilities'] : [];
    
    if (empty($reason)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Reason is required']);
        exit;
    }

    $incomplete_unnamed_facilities = [];
    foreach ($facilities as $index => $facility) {
        $facility_name = trim((string) ($facility['identification']['name'] ?? ''));
        if ($facility_name === '' && kop_facility_has_meaningful_data($facility)) {
            $incomplete_unnamed_facilities[] = $index + 1;
        }
    }

    if (!empty($incomplete_unnamed_facilities)) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => 'Please add a facility name for incomplete facility entries: #' . implode(', #', $incomplete_unnamed_facilities),
        ]);
        exit;
    }
    
    // Validate that we have some meaningful data
    $hasOperator = !empty($data['operator']['name']);
    $first_named_facility = kop_get_first_named_facility($facilities);
    $hasFacility = $first_named_facility !== null;
    $hasReferrer = !empty($data['referrerAgency']['name']) || !empty($data['referrerConsultants'][0]['firstName']);

    if (!$hasOperator && !$hasFacility && !$hasReferrer) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Please provide at least an operator name, facility name, or referrer name']);
        exit;
    }
    
    // Generate a master_id for tracking - now prioritizes explicit project name
    $master_id = '';
    
    // First priority: explicit project name from our form
    if (!empty($input['projectName'])) {
        $master_id = $input['projectName'];
    }
    // Second priority: project name from metadata
    elseif (!empty($input['metadata']['actualProjectName'])) {
        $master_id = $input['metadata']['actualProjectName'];
    }
    // Third priority: project name from data object
    elseif (!empty($data['projectName'])) {
        $master_id = $data['projectName'];
    }
    elseif (!empty($data['name'])) {
        $master_id = $data['name'];
    }
    // Fallback to old logic only if no project name is provided
    else {
        // Try operator name
        if (!empty($data['operator']['name'])) {
            $master_id = $data['operator']['name'];
        }
        // Try facility name
        if ($first_named_facility !== null && !empty($first_named_facility['identification']['name'])) {
            if ($master_id) {
                $master_id .= ' - ' . $first_named_facility['identification']['name'];
            } else {
                $master_id = $first_named_facility['identification']['name'];
            }
        }
        // Try referrer agency name
        if (!empty($data['referrerAgency']['name'])) {
            $master_id = $data['referrerAgency']['name'];
        }
        // Try referrer consultant name
        if (!empty($data['referrerConsultants'][0]['firstName']) || !empty($data['referrerConsultants'][0]['lastName'])) {
            $consultantName = trim(
                ($data['referrerConsultants'][0]['firstName'] ?? '') . ' ' .
                ($data['referrerConsultants'][0]['lastName'] ?? '')
            );
            if (!empty($consultantName)) {
                if ($master_id) {
                    $master_id .= ' - ' . $consultantName;
                } else {
                    $master_id = $consultantName;
                }
            }
        }
    }
    
    // Sanitize master_id (remove special characters, limit length)
    $master_id = preg_replace('/[^a-zA-Z0-9\s\-_]/', '', $master_id);
    $master_id = substr($master_id, 0, 255);
    
    // Get submitter IP
    $submitter_ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    if (isset($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $submitter_ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
    } elseif (isset($_SERVER['HTTP_X_REAL_IP'])) {
        $submitter_ip = $_SERVER['HTTP_X_REAL_IP'];
    }
    
    // Prepare the JSON data for storage
    $json_data = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    
    // Insert into suggested_edits table
    $stmt = $pdo->prepare("
        INSERT INTO suggested_edits (
            master_id, 
            edited_json_data, 
            reason, 
            submitter_ip, 
            status,
            created_at
        ) VALUES (?, ?, ?, ?, 'pending', NOW())
    ");
    
    $stmt->execute([
        $master_id,
        $json_data,
        $reason,
        $submitter_ip
    ]);
    
    $suggestion_id = $pdo->lastInsertId();
    
    echo json_encode([
        'success' => true, 
        'message' => 'Suggestion submitted successfully',
        'suggestion_id' => $suggestion_id,
        'master_id' => $master_id
    ]);

} catch (PDOException $e) {
    error_log('save-suggestion.php PDO error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false, 
        'error' => 'Database error occurred',
        'details' => $e->getMessage()
    ]);
} catch (Exception $e) {
    error_log('save-suggestion.php error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false, 
        'error' => 'An error occurred while saving the suggestion',
        'details' => $e->getMessage()
    ]);
}
?>
