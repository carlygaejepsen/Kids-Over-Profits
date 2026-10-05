<?php
/**
 * Shared library for applying approved suggested_edits rows to the master
 * tables (facilities_master / referrers_master / transporters_master /
 * providers_master / locations_master).
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

if (!function_exists('kop_is_placeholder_project_name')) {
    /**
     * Names the form and approval fall back to when no project name was given.
     */
    function kop_is_placeholder_project_name($name) {
        $name = trim((string) $name);
        return $name === ''
            || strcasecmp($name, 'Unknown Project') === 0
            || strcasecmp($name, 'Unnamed Project') === 0
            || preg_match('/^Approved Suggestion \d+$/i', $name) === 1;
    }
}

if (!function_exists('kop_is_location_project_name')) {
    /**
     * True for a bare US state or country name, the names the data form
     * treats as location projects (it opens and saves them as locations
     * whatever tab is active).
     */
    function kop_is_location_project_name($name) {
        static $names = [
            'ALABAMA', 'ALASKA', 'ARIZONA', 'ARKANSAS', 'CALIFORNIA', 'COLORADO', 'CONNECTICUT',
            'DELAWARE', 'FLORIDA', 'GEORGIA', 'HAWAII', 'IDAHO', 'ILLINOIS', 'INDIANA', 'IOWA',
            'KANSAS', 'KENTUCKY', 'LOUISIANA', 'MAINE', 'MARYLAND', 'MASSACHUSETTS', 'MICHIGAN',
            'MINNESOTA', 'MISSISSIPPI', 'MISSOURI', 'MONTANA', 'NEBRASKA', 'NEVADA', 'NEW HAMPSHIRE',
            'NEW JERSEY', 'NEW MEXICO', 'NEW YORK', 'NORTH CAROLINA', 'NORTH DAKOTA', 'OHIO',
            'OKLAHOMA', 'OREGON', 'PENNSYLVANIA', 'RHODE ISLAND', 'SOUTH CAROLINA', 'SOUTH DAKOTA',
            'TENNESSEE', 'TEXAS', 'UTAH', 'VERMONT', 'VIRGINIA', 'WASHINGTON', 'WEST VIRGINIA',
            'WISCONSIN', 'WYOMING', 'DISTRICT OF COLUMBIA',
            'CANADA', 'MEXICO', 'UNITED KINGDOM', 'AUSTRALIA', 'JAMAICA', 'SAMOA', 'COSTA RICA',
            'BELIZE', 'BAHAMAS', 'DOMINICAN REPUBLIC', 'PUERTO RICO', 'FRANCE', 'GERMANY', 'ITALY',
            'SPAIN', 'NETHERLANDS', 'SWITZERLAND', 'SWEDEN', 'NORWAY', 'DENMARK', 'IRELAND',
            'NEW ZEALAND', 'SOUTH AFRICA', 'ISRAEL', 'JAPAN', 'CHINA', 'INDIA', 'BRAZIL', 'ARGENTINA'
        ];
        return in_array(strtoupper(trim((string) $name)), $names, true);
    }
}

if (!function_exists('kop_provider_location_project_name')) {
    /**
     * The provider project for a state or country: "MONTANA PROVIDERS", never
     * the bare "MONTANA", which the data form would open and save as the
     * Montana location project.
     */
    function kop_provider_location_project_name($location) {
        $location = strtoupper(trim((string) $location));
        return $location === '' ? '' : $location . ' PROVIDERS';
    }
}

if (!function_exists('kop_provider_project_name')) {
    /**
     * Project name for a mental health provider submission that arrived
     * without one: its state (or country), uppercase, plus " PROVIDERS"
     * (kop_provider_location_project_name). The operator block is usually the provider's own hospital or
     * health system, not a parent company, so it never names the project on
     * its own; parent-company projects are named by an admin in the form. The
     * operator or facility name is used only when no location is given.
     * Returns '' when nothing usable is present.
     */
    function kop_provider_project_name($project_data) {
        if (!is_array($project_data)) {
            return '';
        }

        $states = [
            'AL' => 'ALABAMA', 'AK' => 'ALASKA', 'AZ' => 'ARIZONA', 'AR' => 'ARKANSAS', 'CA' => 'CALIFORNIA',
            'CO' => 'COLORADO', 'CT' => 'CONNECTICUT', 'DE' => 'DELAWARE', 'FL' => 'FLORIDA', 'GA' => 'GEORGIA',
            'HI' => 'HAWAII', 'ID' => 'IDAHO', 'IL' => 'ILLINOIS', 'IN' => 'INDIANA', 'IA' => 'IOWA',
            'KS' => 'KANSAS', 'KY' => 'KENTUCKY', 'LA' => 'LOUISIANA', 'ME' => 'MAINE', 'MD' => 'MARYLAND',
            'MA' => 'MASSACHUSETTS', 'MI' => 'MICHIGAN', 'MN' => 'MINNESOTA', 'MS' => 'MISSISSIPPI', 'MO' => 'MISSOURI',
            'MT' => 'MONTANA', 'NE' => 'NEBRASKA', 'NV' => 'NEVADA', 'NH' => 'NEW HAMPSHIRE', 'NJ' => 'NEW JERSEY',
            'NM' => 'NEW MEXICO', 'NY' => 'NEW YORK', 'NC' => 'NORTH CAROLINA', 'ND' => 'NORTH DAKOTA', 'OH' => 'OHIO',
            'OK' => 'OKLAHOMA', 'OR' => 'OREGON', 'PA' => 'PENNSYLVANIA', 'RI' => 'RHODE ISLAND', 'SC' => 'SOUTH CAROLINA',
            'SD' => 'SOUTH DAKOTA', 'TN' => 'TENNESSEE', 'TX' => 'TEXAS', 'UT' => 'UTAH', 'VT' => 'VERMONT',
            'VA' => 'VIRGINIA', 'WA' => 'WASHINGTON', 'WV' => 'WEST VIRGINIA', 'WI' => 'WISCONSIN', 'WY' => 'WYOMING',
        ];

        foreach ((is_array($project_data['facilities'] ?? null) ? $project_data['facilities'] : []) as $facility) {
            if (!is_array($facility)) {
                continue;
            }
            $state = strtoupper(trim((string) ($facility['locationDetails']['state'] ?? $facility['addressParts']['state'] ?? '')));
            if (isset($states[$state])) {
                return kop_provider_location_project_name($states[$state]);
            }
            if (in_array($state, $states, true)) {
                return kop_provider_location_project_name($state);
            }
            $country = strtoupper(trim((string) ($facility['locationDetails']['country'] ?? '')));
            if ($country !== '' && !in_array($country, ['US', 'USA', 'UNITED STATES', 'UNITED STATES OF AMERICA'], true)) {
                return kop_provider_location_project_name($country);
            }
        }

        $operator_name = trim((string) ($project_data['operator']['name'] ?? ''));
        if ($operator_name !== '' && !kop_is_placeholder_project_name($operator_name)) {
            return $operator_name;
        }

        $first_named_facility = kop_get_first_named_facility($project_data['facilities'] ?? []);
        return $first_named_facility ? trim((string) $first_named_facility['identification']['name']) : '';
    }
}

if (!function_exists('kop_rename_placeholder_provider_suggestion')) {
    /**
     * A pending provider suggestion saved as "Unknown Project" (before
     * save-suggestion.php named providers) or as a bare state name is renamed
     * in place to the project approval would file it under, so the admin
     * list shows where it goes. Returns the new name, or '' when the row is
     * left as it is.
     */
    function kop_rename_placeholder_provider_suggestion(PDO $pdo, $table, $id, $master_id, $decoded_data) {
        $placeholder = kop_is_placeholder_project_name($master_id);
        if ((!$placeholder && !kop_is_location_project_name($master_id)) || !is_array($decoded_data)) {
            return '';
        }
        $project_data = kop_extract_project_data($decoded_data);
        $category = $project_data['category'] ?? $decoded_data['category'] ?? '';
        if (strtolower(trim((string) $category)) !== 'providers') {
            return '';
        }
        $name = kop_sanitize_project_identifier($placeholder
            ? kop_provider_project_name($project_data)
            : kop_provider_location_project_name($master_id));
        if ($name === '') {
            return '';
        }
        $stmt = $pdo->prepare("UPDATE $table SET master_id = ? WHERE id = ? AND status = 'pending'");
        $stmt->execute([$name, (int) $id]);
        return $stmt->rowCount() > 0 ? $name : '';
    }
}

if (!function_exists('kop_preserve_hidden_testimony')) {
    /**
     * The public form never sees unpublished survivor testimony (it is
     * redacted for non-admins), so a suggestion comes back without it.
     * Before the suggestion replaces a facility, put those entries back:
     * every existing entry with publish !== true whose id (or text) is not in
     * the suggestion is appended. Facilities match by facility_id, then name.
     *
     * @param array $new_facilities      facilities from the suggestion
     * @param array $existing_facilities facilities as stored now
     * @return array the suggestion's facilities with hidden testimony restored
     */
    function kop_preserve_hidden_testimony(array $new_facilities, array $existing_facilities) {
        $by_id = [];
        $by_name = [];
        foreach ($existing_facilities as $facility) {
            if (!is_array($facility) || empty($facility['survivorTestimony']) || !is_array($facility['survivorTestimony'])) continue;
            $fid = (int) ($facility['facility_id'] ?? 0);
            if ($fid > 0) $by_id[$fid] = $facility['survivorTestimony'];
            $name = strtolower(trim((string) ($facility['identification']['name'] ?? '')));
            if ($name !== '') $by_name[$name] = $facility['survivorTestimony'];
        }
        if (!$by_id && !$by_name) return $new_facilities;

        foreach ($new_facilities as $i => $facility) {
            if (!is_array($facility)) continue;
            $fid = (int) ($facility['facility_id'] ?? 0);
            $name = strtolower(trim((string) ($facility['identification']['name'] ?? '')));
            $existing = ($fid > 0 && isset($by_id[$fid])) ? $by_id[$fid] : ($by_name[$name] ?? null);
            if (!$existing) continue;

            $list = is_array($facility['survivorTestimony'] ?? null) ? $facility['survivorTestimony'] : [];
            $ids = [];
            $texts = [];
            foreach ($list as $entry) {
                if (!is_array($entry)) continue;
                if (($entry['id'] ?? '') !== '') $ids[(string) $entry['id']] = true;
                $texts[trim((string) ($entry['text'] ?? ''))] = true;
            }
            foreach ($existing as $entry) {
                if (!is_array($entry) || ($entry['publish'] ?? true) === true) continue;
                $id = (string) ($entry['id'] ?? '');
                if (($id !== '' && isset($ids[$id])) || isset($texts[trim((string) ($entry['text'] ?? ''))])) continue;
                $list[] = $entry;
            }
            $new_facilities[$i]['survivorTestimony'] = $list;
        }
        return $new_facilities;
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
        $providers_table = kop_resolve_table_name($pdo, 'providers_master', '');
        $locations_table = kop_resolve_table_name($pdo, 'locations_master', $wp_prefix);

        try {
            // CREATE TABLE commits implicitly, so create it before the
            // transaction opens rather than when a provider row is found.
            kop_ensure_master_table($pdo, $providers_table);
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

            // Mental health providers (js/data-form/provider-form.js) are tagged
            // on the data; they are facility-shaped but are not TTI facilities.
            $isProvider = strtolower(trim((string) ($project_data['category'] ?? $decoded_data['category'] ?? ''))) === 'providers';

            // A provider named after a bare state or country would land in
            // the form's location project of that name; file it as
            // "<STATE> PROVIDERS" instead.
            if ($isProvider && kop_is_location_project_name($resolved_master_id)) {
                $resolved_master_id = kop_provider_location_project_name($resolved_master_id);
                if (is_array($decoded_data)) {
                    $decoded_data['name'] = $resolved_master_id;
                    $decoded_data['projectName'] = $resolved_master_id;
                }
            }

            // A provider sent without a project name is filed under its state
            // instead of "Unknown Project".
            if ($isProvider && kop_is_placeholder_project_name($master_id)) {
                $provider_name = kop_sanitize_project_identifier(kop_provider_project_name($project_data));
                if ($provider_name !== '') {
                    $resolved_master_id = $provider_name;
                    if (is_array($decoded_data)) {
                        $decoded_data['name'] = $provider_name;
                        $decoded_data['projectName'] = $provider_name;
                    }
                }
            }

            $masterIdUpper = strtoupper(trim($resolved_master_id));
            $isLocation = in_array($masterIdUpper, $US_STATE_NAMES) || in_array($masterIdUpper, $COUNTRY_NAMES);
            if ($isLocation) {
                $resolved_master_id = $masterIdUpper;
            }
            // A provider filed under a state stays in providers_master.
            if ($isProvider) {
                $isLocation = false;
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
            } elseif ($isProvider) {
                $tableName = $providers_table;
                $category = 'providers';
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

            // Put back unpublished survivor testimony the submitter never saw.
            if (!empty($project_data['facilities']) && is_array($project_data['facilities'])) {
                $existing_facilities = [];
                if ($category === 'companies' || $category === 'locations') {
                    if (function_exists('kop_facility_load')) {
                        $load_prefix = $wp_prefix !== '' ? $wp_prefix : (isset($GLOBALS['wpdb']->prefix) ? $GLOBALS['wpdb']->prefix : '');
                        foreach ($project_data['facilities'] as $facility) {
                            $fid = is_array($facility) ? (int) ($facility['facility_id'] ?? 0) : 0;
                            if ($fid <= 0) continue;
                            $loaded = kop_facility_load($fid, ['pdo' => $pdo, 'prefix' => $load_prefix]);
                            if ($loaded && !empty($loaded['doc']['survivorTestimony'])) {
                                $existing_facilities[] = [
                                    'facility_id' => $fid,
                                    'identification' => ['name' => $loaded['doc']['identification']['name'] ?? ''],
                                    'survivorTestimony' => $loaded['doc']['survivorTestimony'],
                                ];
                            }
                        }
                    }
                } else {
                    $prior = $pdo->prepare("SELECT json_data FROM `{$tableName}` WHERE unique_name = ? LIMIT 1");
                    $prior->execute([$resolved_master_id]);
                    $prior_json = $prior->fetchColumn();
                    $prior_data = $prior_json ? kop_extract_project_data(json_decode($prior_json, true)) : [];
                    $existing_facilities = is_array($prior_data['facilities'] ?? null) ? $prior_data['facilities'] : [];
                }
                if ($existing_facilities) {
                    $restored = kop_preserve_hidden_testimony($project_data['facilities'], $existing_facilities);
                    $project_data['facilities'] = $restored;
                    // Write them into the innermost data level the payload uses.
                    $node = &$decoded_data;
                    $depth = 0;
                    while (is_array($node) && isset($node['data']) && is_array($node['data']) && $depth < 3) {
                        $node = &$node['data'];
                        $depth++;
                    }
                    if (is_array($node)) $node['facilities'] = $restored;
                    if (is_array($decoded_data) && isset($decoded_data['facilities']) && $depth > 0) {
                        $decoded_data['facilities'] = $restored;
                    }
                    unset($node);
                }
            }

            $project_payload = kop_build_project_payload($decoded_data, $resolved_master_id, $category);
            $json_data = json_encode($project_payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

            if (!$resolved_master_id) {
                $pdo->rollBack();
                return ['success' => false, 'projectName' => null, 'error' => 'Approved suggestion is missing a usable project name', 'httpCode' => 400];
            }

            // v2 facility model: once admin saves write the v2 tables
            // (inc/facility-v2-writer.php), operator and location suggestions
            // go there too. A suggestion lists only what it changes, so it is
            // saved as a partial: nothing unlisted is removed.
            if ($category === 'companies' || $category === 'locations') {
                require_once dirname(__DIR__) . '/inc/facility-v2-writer.php';
                $v2_prefix = $wp_prefix !== '' ? $wp_prefix : (isset($GLOBALS['wpdb']->prefix) ? $GLOBALS['wpdb']->prefix : '');
                if (kop_v2_writes_active($pdo, $v2_prefix)) {
                    $v2_name = $resolved_master_id;
                    $raw_name = trim((string) $master_id);
                    if ($category === 'companies' && $raw_name !== '' && $raw_name !== $v2_name) {
                        // The sanitized name drops punctuation; prefer the exact operator name.
                        $op_tables = kop_migration_tables($v2_prefix);
                        $op_check = $pdo->prepare("SELECT COUNT(*) FROM `{$op_tables['operators']}` WHERE unique_name = ?");
                        $op_check->execute([$raw_name]);
                        if ((int) $op_check->fetchColumn() > 0) {
                            $v2_name = $raw_name;
                        }
                    }
                    kop_v2_save_form_project($pdo, $v2_prefix, $v2_name, $project_data, $category, ['partial' => true, 'timestamp' => date('c')]);
                    kop_mark_suggested_edit_status($pdo, $suggested_edits_table, $id, 'approved');
                    $pdo->commit();
                    return ['success' => true, 'projectName' => $v2_name, 'error' => null, 'httpCode' => 200];
                }
            }

            $checkStmt = $pdo->prepare("SELECT id, json_data FROM `{$tableName}` WHERE unique_name = ? LIMIT 1");
            $checkStmt->execute([$resolved_master_id]);
            $exists = $checkStmt->fetch(PDO::FETCH_ASSOC);

            if ($exists) {
                // For location and provider projects, merge new facilities into
                // existing data, so a second provider filed under the same state
                // or parent company is added rather than replacing the first.
                if (($isLocation || $isProvider) && !empty($exists['json_data'])) {
                    $existingData = kop_build_project_payload(json_decode($exists['json_data'], true), $resolved_master_id, $category);
                    $newData = kop_build_project_payload(json_decode($json_data, true), $resolved_master_id, $category);

                    $existingFacilities = is_array($existingData['data']['facilities'] ?? null) ? $existingData['data']['facilities'] : [];
                    $newFacilities = is_array($newData['data']['facilities'] ?? null) ? $newData['data']['facilities'] : [];

                    $existingFacilities = kop_merge_location_facilities($existingFacilities, $newFacilities);

                    $existingData['data']['facilities'] = $existingFacilities;
                    if ($isProvider && !empty($newData['data']['operator']['name'])) {
                        $existingData['data']['operator'] = $newData['data']['operator'];
                    }
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
            if (function_exists('kop_facility_v2_request_sync')) {
                kop_facility_v2_request_sync();
            }
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
