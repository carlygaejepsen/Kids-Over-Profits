<?php
/**
 * Shared library for applying approved suggested_edits rows to the master
 * tables (facilities_master / referrers_master / transporters_master /
 * locations_master).
 *
 * Used by process-edit.php (legacy single-submission endpoint) and
 * manage-submissions.php (Submissions Review approve action) so that
 * approving a data submission always performs the actual merge.
 *
 * Every definition is guarded with function_exists so this file can be
 * required alongside endpoints that define the same helpers inline.
 */

if (!function_exists('kop_ensure_master_table')) {
    /**
     * Auto-create a master table on demand if it doesn't exist yet.
     */
    function kop_ensure_master_table(PDO $pdo, $tableName) {
        $stmt = $pdo->prepare('SHOW TABLES LIKE :table');
        $stmt->execute([':table' => $tableName]);
        if ($stmt->fetchColumn()) {
            return;
        }
        $quoted = '`' . str_replace('`', '``', $tableName) . '`';
        $pdo->exec("CREATE TABLE IF NOT EXISTS {$quoted} (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            unique_name VARCHAR(255) NOT NULL,
            json_data LONGTEXT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY unique_name (unique_name),
            KEY updated_at (updated_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
}

if (!function_exists('kop_resolve_table_name')) {
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
}

if (!function_exists('kop_extract_project_data')) {
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
}

if (!function_exists('kop_build_project_payload')) {
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
}

if (!function_exists('kop_get_first_named_facility')) {
    function kop_get_first_named_facility($facilities) {
        if (!is_array($facilities)) {
            return null;
        }

        foreach ($facilities as $facility) {
            if (!is_array($facility)) {
                continue;
            }

            $facility_name = trim((string) ($facility['identification']['name'] ?? ''));
            if ($facility_name !== '') {
                return $facility;
            }
        }

        return null;
    }
}

if (!function_exists('kop_consultant_display_name')) {
    function kop_consultant_display_name($consultant) {
        if (!is_array($consultant)) {
            return '';
        }

        $full_name = trim(
            ((string) ($consultant['firstName'] ?? '')) . ' ' .
            ((string) ($consultant['lastName'] ?? ''))
        );

        if ($full_name !== '') {
            return $full_name;
        }

        return trim((string) ($consultant['fullName'] ?? ''));
    }
}

if (!function_exists('kop_sanitize_project_identifier')) {
    function kop_sanitize_project_identifier($value) {
        $value = trim((string) $value);
        if ($value === '') {
            return '';
        }

        $value = preg_replace('/[^a-zA-Z0-9\s\-_]/', '', $value);
        $value = preg_replace('/\s+/', ' ', $value);

        return substr(trim((string) $value), 0, 255);
    }
}

if (!function_exists('kop_resolve_submission_project_name')) {
    function kop_resolve_submission_project_name($master_id, $project_data, $submission_id) {
        $resolved = kop_sanitize_project_identifier($master_id);
        if ($resolved !== '') {
            return $resolved;
        }

        if (!is_array($project_data)) {
            return 'Approved Suggestion ' . (int) $submission_id;
        }

        $candidates = [];

        if (!empty($project_data['projectName'])) {
            $candidates[] = $project_data['projectName'];
        }

        if (!empty($project_data['name'])) {
            $candidates[] = $project_data['name'];
        }

        if (!empty($project_data['operator']['name'])) {
            $candidates[] = $project_data['operator']['name'];
        }

        $first_named_facility = kop_get_first_named_facility($project_data['facilities'] ?? []);
        if ($first_named_facility && !empty($first_named_facility['identification']['name'])) {
            $facility_name = $first_named_facility['identification']['name'];
            if (!empty($project_data['operator']['name'])) {
                $candidates[] = $project_data['operator']['name'] . ' - ' . $facility_name;
            }
            $candidates[] = $facility_name;
        }

        if (!empty($project_data['referrerAgency']['name'])) {
            $candidates[] = $project_data['referrerAgency']['name'];
        }

        if (!empty($project_data['referrerConsultants'][0])) {
            $consultant_name = kop_consultant_display_name($project_data['referrerConsultants'][0]);
            if ($consultant_name !== '') {
                if (!empty($project_data['referrerAgency']['name'])) {
                    $candidates[] = $project_data['referrerAgency']['name'] . ' - ' . $consultant_name;
                }
                $candidates[] = $consultant_name;
            }
        }

        $transporter_company_name = $project_data['transporterCompany']['name']
            ?? $project_data['transporterAgency']['name']
            ?? '';
        if (!empty($transporter_company_name)) {
            $candidates[] = $transporter_company_name;
        }

        if (!empty($project_data['transporters'][0])) {
            $transporter_name = kop_consultant_display_name($project_data['transporters'][0]);
            if ($transporter_name !== '') {
                if (!empty($transporter_company_name)) {
                    $candidates[] = $transporter_company_name . ' - ' . $transporter_name;
                }
                $candidates[] = $transporter_name;
            }
        }

        foreach ($candidates as $candidate) {
            $sanitized = kop_sanitize_project_identifier($candidate);
            if ($sanitized !== '') {
                return $sanitized;
            }
        }

        return 'Approved Suggestion ' . (int) $submission_id;
    }
}

if (!function_exists('kop_build_location_facility_key')) {
    function kop_build_location_facility_key($facility) {
        if (!is_array($facility)) {
            return '';
        }

        $identification = isset($facility['identification']) && is_array($facility['identification'])
            ? $facility['identification']
            : array();
        $location_details = isset($facility['locationDetails']) && is_array($facility['locationDetails'])
            ? $facility['locationDetails']
            : array();

        $name = trim((string) ($identification['name'] ?? $facility['name'] ?? ''));
        $address = trim((string) ($facility['address'] ?? ''));
        $location = trim((string) ($facility['location'] ?? ''));
        $city = trim((string) ($location_details['city'] ?? $facility['locationCity'] ?? ''));
        $state = trim((string) ($location_details['state'] ?? $facility['locationState'] ?? ''));

        return strtolower($name . '|' . $address . '|' . $location . '|' . $city . '|' . $state);
    }
}

if (!function_exists('kop_merge_location_facilities')) {
    function kop_merge_location_facilities($existing_facilities, $new_facilities) {
        $existing_facilities = is_array($existing_facilities) ? array_values($existing_facilities) : [];
        $new_facilities = is_array($new_facilities) ? $new_facilities : [];

        $index_by_key = array();

        foreach ($existing_facilities as $index => $facility) {
            $key = kop_build_location_facility_key($facility);
            if ($key !== '') {
                $index_by_key[$key] = $index;
            }
        }

        foreach ($new_facilities as $new_facility) {
            $facility_key = kop_build_location_facility_key($new_facility);
            $existing_index = ($facility_key !== '' && array_key_exists($facility_key, $index_by_key))
                ? $index_by_key[$facility_key]
                : -1;

            if ($existing_index >= 0) {
                $existing_facilities[$existing_index] = $new_facility;
            } else {
                $existing_facilities[] = $new_facility;
                if ($facility_key !== '') {
                    $index_by_key[$facility_key] = count($existing_facilities) - 1;
                }
            }
        }

        return array_values($existing_facilities);
    }
}

if (!function_exists('kop_mark_suggested_edit_status')) {
    /**
     * Set a suggested_edits row's status, stamping a review timestamp when the
     * table has a suitable column.
     */
    function kop_mark_suggested_edit_status(PDO $pdo, $suggested_edits_table, $id, $status) {
        $timestampCols = ['reviewed_at', 'reviewed_on', 'processed_at', 'reviewed_at_ts'];
        foreach ($timestampCols as $col) {
            $colCheck = $pdo->prepare("SELECT COUNT(*) as c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
            $colCheck->execute([$suggested_edits_table, $col]);
            $res = $colCheck->fetch(PDO::FETCH_ASSOC);
            if ($res && isset($res['c']) && (int)$res['c'] > 0) {
                $stmt = $pdo->prepare("UPDATE `{$suggested_edits_table}` SET status = ?, $col = NOW() WHERE id = ?");
                $stmt->execute([$status, $id]);
                return;
            }
        }
        $stmt = $pdo->prepare("UPDATE `{$suggested_edits_table}` SET status = ? WHERE id = ?");
        $stmt->execute([$status, $id]);
    }
}

if (!function_exists('kop_apply_suggested_edit')) {
    /**
     * Apply one pending suggested_edits row to the appropriate master table and
     * mark it approved, inside its own transaction.
     *
     * Returns ['success' => bool, 'projectName' => string|null,
     *          'error' => string|null, 'httpCode' => int].
     */
    function kop_apply_suggested_edit(PDO $pdo, $id, $wp_prefix = '') {
        $id = (int) $id;
        if ($id <= 0) {
            return ['success' => false, 'projectName' => null, 'error' => 'Invalid submission ID', 'httpCode' => 400];
        }

        $suggested_edits_table = kop_resolve_table_name($pdo, 'suggested_edits', $wp_prefix);
        $facilities_table = kop_resolve_table_name($pdo, 'facilities_master', $wp_prefix);
        $referrers_table = kop_resolve_table_name($pdo, 'referrers_master', $wp_prefix);
        $transporters_table = kop_resolve_table_name($pdo, 'transporters_master', $wp_prefix);
        $locations_table = kop_resolve_table_name($pdo, 'locations_master', $wp_prefix);

        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare("SELECT * FROM `{$suggested_edits_table}` WHERE id = ? AND status = 'pending' LIMIT 1");
            $stmt->execute([$id]);
            $submission = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$submission) {
                $pdo->rollBack();
                return ['success' => false, 'projectName' => null, 'error' => 'Submission not found or already processed', 'httpCode' => 404];
            }

            $master_id = isset($submission['master_id']) ? $submission['master_id'] : null;
            $json_data = isset($submission['edited_json_data']) ? $submission['edited_json_data'] : null;

            if ($json_data === null || $json_data === '') {
                $pdo->rollBack();
                return ['success' => false, 'projectName' => null, 'error' => 'Submission has no edited data to publish', 'httpCode' => 400];
            }

            $decoded_data = json_decode($json_data, true);
            $project_data = kop_extract_project_data($decoded_data);
            $resolved_master_id = kop_resolve_submission_project_name($master_id, $project_data, $id);

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

            $masterIdUpper = strtoupper(trim($resolved_master_id));
            $isLocation = in_array($masterIdUpper, $US_STATE_NAMES) || in_array($masterIdUpper, $COUNTRY_NAMES);
            if ($isLocation) {
                $resolved_master_id = $masterIdUpper;
            }

            $isReferrer = !empty($project_data['referrerAgency']['name']) ||
                          !empty($project_data['referrerConsultants'][0]['firstName']) ||
                          !empty($project_data['referrerConsultants'][0]['lastName']);

            $isTransporter = !empty($project_data['transporterCompany']['name']) ||
                             !empty($project_data['transporterAgency']['name']) ||
                             !empty($project_data['transporters'][0]['firstName']) ||
                             !empty($project_data['transporters'][0]['lastName']);

            if ($isLocation) {
                $tableName = $locations_table;
                $category = 'locations';
            } elseif ($isReferrer) {
                $tableName = $referrers_table;
                $category = 'referrers';
            } elseif ($isTransporter) {
                $tableName = $transporters_table;
                $category = 'transporters';
                kop_ensure_master_table($pdo, $tableName);
            } else {
                $tableName = $facilities_table;
                $category = 'companies';
            }

            $project_payload = kop_build_project_payload($decoded_data, $resolved_master_id, $category);
            $json_data = json_encode($project_payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

            if (!$resolved_master_id) {
                $pdo->rollBack();
                return ['success' => false, 'projectName' => null, 'error' => 'Approved suggestion is missing a usable project name', 'httpCode' => 400];
            }

            $checkStmt = $pdo->prepare("SELECT id, json_data FROM `{$tableName}` WHERE unique_name = ? LIMIT 1");
            $checkStmt->execute([$resolved_master_id]);
            $exists = $checkStmt->fetch(PDO::FETCH_ASSOC);

            if ($exists) {
                // For location projects, merge new facilities into existing data
                if ($isLocation && !empty($exists['json_data'])) {
                    $existingData = kop_build_project_payload(json_decode($exists['json_data'], true), $resolved_master_id, $category);
                    $newData = kop_build_project_payload(json_decode($json_data, true), $resolved_master_id, $category);

                    $existingFacilities = is_array($existingData['data']['facilities'] ?? null) ? $existingData['data']['facilities'] : [];
                    $newFacilities = is_array($newData['data']['facilities'] ?? null) ? $newData['data']['facilities'] : [];

                    $existingFacilities = kop_merge_location_facilities($existingFacilities, $newFacilities);

                    $existingData['data']['facilities'] = $existingFacilities;
                    $existingData['timestamp'] = date('c');
                    $json_data = json_encode($existingData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
                }

                $updateStmt = $pdo->prepare("UPDATE `{$tableName}` SET json_data = ?, updated_at = NOW() WHERE unique_name = ?");
                $updateStmt->execute([$json_data, $resolved_master_id]);
            } else {
                $insertStmt = $pdo->prepare("INSERT INTO `{$tableName}` (unique_name, json_data) VALUES (?, ?)");
                $insertStmt->execute([$resolved_master_id, $json_data]);
            }

            kop_mark_suggested_edit_status($pdo, $suggested_edits_table, $id, 'approved');

            $pdo->commit();
            return ['success' => true, 'projectName' => $resolved_master_id, 'error' => null, 'httpCode' => 200];
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('kop_apply_suggested_edit error for ID ' . $id . ': ' . $e->getMessage());
            return ['success' => false, 'projectName' => null, 'error' => 'Server error: ' . $e->getMessage(), 'httpCode' => 500];
        }
    }
}
