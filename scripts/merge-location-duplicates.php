#!/usr/bin/env php
<?php

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "This script must be run from the command line.\n");
    exit(1);
}

function print_usage() {
    $usage = <<<TEXT
Usage:
    php scripts/merge-location-duplicates.php [--dry-run] [--apply] [--delete-sources] [--backup-dir <dir>] [--json]

Options:
  --dry-run  Preview changes only. This is the default.
    --apply    Write merged rows to locations_master but keep duplicate source rows in place.
    --delete-sources  Delete duplicate source rows after writing merged target rows. Use only with --apply.
    --backup-dir <dir>  Write a JSON snapshot of affected rows before any write. Defaults to tmp/location-merge-backups/<timestamp>.
  --json     Print the full result as JSON.
  --help     Show this help.

Examples:
  php scripts/merge-location-duplicates.php --dry-run
  php scripts/merge-location-duplicates.php --apply
    php scripts/merge-location-duplicates.php --apply --delete-sources
    php scripts/merge-location-duplicates.php --apply --backup-dir tmp/location-merge-backups/manual-run --json
TEXT;

    fwrite(STDOUT, $usage . PHP_EOL);
}

function parse_args($argv) {
    $options = [
        'dryRun' => true,
        'json' => false,
        'deleteSources' => false,
        'backupDir' => '',
    ];

    $args = array_slice($argv, 1);
    for ($index = 0; $index < count($args); $index += 1) {
        $arg = $args[$index];

        if ($arg === '--help' || $arg === '-h') {
            $options['help'] = true;
            continue;
        }

        if ($arg === '--dry-run') {
            $options['dryRun'] = true;
            continue;
        }

        if ($arg === '--apply') {
            $options['dryRun'] = false;
            continue;
        }

        if ($arg === '--delete-sources') {
            $options['deleteSources'] = true;
            continue;
        }

        if ($arg === '--backup-dir') {
            $index += 1;
            if (!isset($args[$index]) || trim($args[$index]) === '') {
                throw new InvalidArgumentException('--backup-dir requires a directory path');
            }

            $options['backupDir'] = $args[$index];
            continue;
        }

        if ($arg === '--json') {
            $options['json'] = true;
            continue;
        }

        throw new InvalidArgumentException("Unknown argument: {$arg}");
    }

    return $options;
}

function kop_normalize_backup_dir_cli($backupDir) {
    $backupDir = trim((string) $backupDir);
    if ($backupDir === '') {
        $backupDir = dirname(__DIR__) . '/tmp/location-merge-backups/' . date('Ymd-His');
    }

    if (!preg_match('~^(?:[A-Za-z]:[\\/]|/)~', $backupDir)) {
        $backupDir = dirname(__DIR__) . '/' . ltrim(str_replace('\\', '/', $backupDir), '/');
    }

    return str_replace('\\', '/', $backupDir);
}

function kop_write_merge_backup_cli($backupDir, array $payload) {
    if (!is_dir($backupDir) && !mkdir($backupDir, 0777, true) && !is_dir($backupDir)) {
        throw new RuntimeException("Failed to create backup directory: {$backupDir}");
    }

    $backupFile = rtrim($backupDir, '/\\') . '/merge-backup.json';
    $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        throw new RuntimeException('Failed to encode backup payload as JSON');
    }

    if (file_put_contents($backupFile, $json) === false) {
        throw new RuntimeException("Failed to write backup file: {$backupFile}");
    }

    return $backupFile;
}

function kop_resolve_table_name_cli(PDO $pdo, $base, $prefix = '') {
    $candidates = [];
    if ($prefix) {
        $candidates[] = $prefix . $base;
    }
    $candidates[] = $base;

    foreach ($candidates as $candidate) {
        $stmt = $pdo->prepare('SHOW TABLES LIKE :table');
        $stmt->execute([':table' => $candidate]);
        if ($stmt->fetchColumn()) {
            return $candidate;
        }
    }

    return $candidates[0];
}

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

$COUNTRY_NAMES = [
    'CANADA', 'MEXICO', 'UNITED KINGDOM', 'AUSTRALIA', 'JAMAICA', 'SAMOA', 'COSTA RICA',
    'BELIZE', 'BAHAMAS', 'DOMINICAN REPUBLIC', 'PUERTO RICO', 'FRANCE', 'GERMANY', 'ITALY',
    'SPAIN', 'NETHERLANDS', 'SWITZERLAND', 'SWEDEN', 'NORWAY', 'DENMARK', 'IRELAND',
    'NEW ZEALAND', 'SOUTH AFRICA', 'ISRAEL', 'JAPAN', 'CHINA', 'INDIA', 'BRAZIL', 'ARGENTINA'
];

$STATE_ABBREVIATIONS = [
    'AL' => 'ALABAMA', 'AK' => 'ALASKA', 'AZ' => 'ARIZONA', 'AR' => 'ARKANSAS',
    'CA' => 'CALIFORNIA', 'CO' => 'COLORADO', 'CT' => 'CONNECTICUT', 'DE' => 'DELAWARE',
    'FL' => 'FLORIDA', 'GA' => 'GEORGIA', 'HI' => 'HAWAII', 'ID' => 'IDAHO',
    'IL' => 'ILLINOIS', 'IN' => 'INDIANA', 'IA' => 'IOWA', 'KS' => 'KANSAS',
    'KY' => 'KENTUCKY', 'LA' => 'LOUISIANA', 'ME' => 'MAINE', 'MD' => 'MARYLAND',
    'MA' => 'MASSACHUSETTS', 'MI' => 'MICHIGAN', 'MN' => 'MINNESOTA', 'MS' => 'MISSISSIPPI',
    'MO' => 'MISSOURI', 'MT' => 'MONTANA', 'NE' => 'NEBRASKA', 'NV' => 'NEVADA',
    'NH' => 'NEW HAMPSHIRE', 'NJ' => 'NEW JERSEY', 'NM' => 'NEW MEXICO', 'NY' => 'NEW YORK',
    'NC' => 'NORTH CAROLINA', 'ND' => 'NORTH DAKOTA', 'OH' => 'OHIO', 'OK' => 'OKLAHOMA',
    'OR' => 'OREGON', 'PA' => 'PENNSYLVANIA', 'RI' => 'RHODE ISLAND', 'SC' => 'SOUTH CAROLINA',
    'SD' => 'SOUTH DAKOTA', 'TN' => 'TENNESSEE', 'TX' => 'TEXAS', 'UT' => 'UTAH',
    'VT' => 'VERMONT', 'VA' => 'VIRGINIA', 'WA' => 'WASHINGTON', 'WV' => 'WEST VIRGINIA',
    'WI' => 'WISCONSIN', 'WY' => 'WYOMING'
];

function parseCityStateCli($location) {
    if (!$location || !is_string($location)) {
        return ['city' => '', 'state' => ''];
    }

    global $STATE_ABBREVIATIONS;

    $parts = array_map('trim', explode(',', $location));
    if (count($parts) >= 2) {
        $city = $parts[0];
        $statePart = strtoupper(trim($parts[count($parts) - 1]));

        if (isset($STATE_ABBREVIATIONS[$statePart])) {
            return ['city' => $city, 'state' => $STATE_ABBREVIATIONS[$statePart]];
        }

        if (in_array($statePart, array_values($STATE_ABBREVIATIONS), true)) {
            return ['city' => $city, 'state' => $statePart];
        }
    }

    return ['city' => $location, 'state' => ''];
}

function normalizeStateNameCli($state) {
    global $US_STATE_NAMES, $STATE_ABBREVIATIONS;

    if (!$state || !is_string($state)) {
        return null;
    }

    $state = strtoupper(trim($state));
    if (in_array($state, $US_STATE_NAMES, true)) {
        return $state;
    }

    if (isset($STATE_ABBREVIATIONS[$state])) {
        return $STATE_ABBREVIATIONS[$state];
    }

    return null;
}

function normalizeCountryNameCli($country) {
    global $COUNTRY_NAMES;

    if (!$country || !is_string($country)) {
        return null;
    }

    $country = strtoupper(trim($country));
    if (in_array($country, $COUNTRY_NAMES, true)) {
        return $country;
    }

    if (strlen($country) > 1) {
        return $country;
    }

    return null;
}

function extractFacilityStateCli($facility) {
    if (!is_array($facility)) {
        return null;
    }

    $tryNormalize = function($value) {
        if (empty($value) || !is_string($value)) {
            return null;
        }

        $state = normalizeStateNameCli($value);
        if ($state) {
            return $state;
        }

        return normalizeCountryNameCli($value);
    };

    if (!empty($facility['locationDetails']) && is_array($facility['locationDetails'])) {
        $details = $facility['locationDetails'];
        foreach (['state', 'country'] as $fieldName) {
            if (!empty($details[$fieldName])) {
                $result = $tryNormalize($details[$fieldName]);
                if ($result) {
                    return $result;
                }
            }
        }
    }

    foreach (['locationState', 'state', 'locationCountry', 'country'] as $fieldName) {
        if (!empty($facility[$fieldName])) {
            $result = $tryNormalize($facility[$fieldName]);
            if ($result) {
                return $result;
            }
        }
    }

    if (!empty($facility['location']) && is_string($facility['location'])) {
        $parsed = parseCityStateCli($facility['location']);
        if (!empty($parsed['state'])) {
            $result = $tryNormalize($parsed['state']);
            if ($result) {
                return $result;
            }
        }
    }

    if (!empty($facility['identification']) && is_array($facility['identification'])) {
        foreach (['state', 'locationState', 'country'] as $fieldName) {
            if (!empty($facility['identification'][$fieldName])) {
                $result = $tryNormalize($facility['identification'][$fieldName]);
                if ($result) {
                    return $result;
                }
            }
        }
    }

    if (!empty($facility['address']) && is_string($facility['address'])) {
        $parsed = parseCityStateCli($facility['address']);
        if (!empty($parsed['state'])) {
            $result = $tryNormalize($parsed['state']);
            if ($result) {
                return $result;
            }
        }
    }

    return null;
}

function extractReferrerStateCli($referrer) {
    if (!is_array($referrer)) {
        return null;
    }

    if (!empty($referrer['state'])) {
        $state = normalizeStateNameCli($referrer['state']);
        if ($state) {
            return $state;
        }

        $country = normalizeCountryNameCli($referrer['state']);
        if ($country) {
            return $country;
        }
    }

    foreach (['location', 'city'] as $fieldName) {
        if (!empty($referrer[$fieldName]) && is_string($referrer[$fieldName])) {
            $parsed = parseCityStateCli($referrer[$fieldName]);
            if (!empty($parsed['state'])) {
                $state = normalizeStateNameCli($parsed['state']);
                if ($state) {
                    return $state;
                }
            }
        }
    }

    return null;
}

function kop_merge_location_facilities_cli($existingFacilities, $newFacilities) {
    $existingFacilities = is_array($existingFacilities) ? array_values($existingFacilities) : [];
    $newFacilities = is_array($newFacilities) ? array_values($newFacilities) : [];
    $indexByKey = [];

    foreach ($existingFacilities as $index => $facility) {
        $key = kop_build_location_facility_key_cli($facility);
        if ($key !== '') {
            $indexByKey[$key] = $index;
        }
    }

    foreach ($newFacilities as $facility) {
        $key = kop_build_location_facility_key_cli($facility);
        if ($key !== '' && array_key_exists($key, $indexByKey)) {
            $existingFacilities[$indexByKey[$key]] = $facility;
            continue;
        }

        $existingFacilities[] = $facility;
        if ($key !== '') {
            $indexByKey[$key] = count($existingFacilities) - 1;
        }
    }

    return array_values($existingFacilities);
}

function kop_build_location_facility_key_cli($facility) {
    if (!is_array($facility)) {
        return '';
    }

    $identification = !empty($facility['identification']) && is_array($facility['identification'])
        ? $facility['identification']
        : [];
    $locationDetails = !empty($facility['locationDetails']) && is_array($facility['locationDetails'])
        ? $facility['locationDetails']
        : [];

    $name = trim((string) ($identification['name'] ?? $facility['name'] ?? ''));
    $address = trim((string) ($facility['address'] ?? ''));
    $location = trim((string) ($facility['location'] ?? ''));
    $city = trim((string) ($locationDetails['city'] ?? $facility['locationCity'] ?? ''));
    $state = trim((string) ($locationDetails['state'] ?? $facility['locationState'] ?? ''));

    return strtolower($name . '|' . $address . '|' . $location . '|' . $city . '|' . $state);
}

function kop_build_location_referrer_key_cli($referrer) {
    if (!is_array($referrer)) {
        return '';
    }

    $name = trim((string) ($referrer['name'] ?? $referrer['fullName'] ?? $referrer['consultantName'] ?? ''));
    $location = trim((string) ($referrer['location'] ?? ''));
    $city = trim((string) ($referrer['city'] ?? ''));
    $state = trim((string) ($referrer['state'] ?? ''));

    return strtolower($name . '|' . $location . '|' . $city . '|' . $state);
}

function kop_merge_location_referrers_cli($existingReferrers, $newReferrers) {
    $existingReferrers = is_array($existingReferrers) ? array_values($existingReferrers) : [];
    $newReferrers = is_array($newReferrers) ? array_values($newReferrers) : [];
    $indexByKey = [];

    foreach ($existingReferrers as $index => $referrer) {
        $key = kop_build_location_referrer_key_cli($referrer);
        if ($key !== '') {
            $indexByKey[$key] = $index;
        }
    }

    foreach ($newReferrers as $referrer) {
        $key = kop_build_location_referrer_key_cli($referrer);
        if ($key !== '' && array_key_exists($key, $indexByKey)) {
            $existingReferrers[$indexByKey[$key]] = $referrer;
            continue;
        }

        $existingReferrers[] = $referrer;
        if ($key !== '') {
            $indexByKey[$key] = count($existingReferrers) - 1;
        }
    }

    return array_values($existingReferrers);
}

function kop_is_known_country_name_cli($value) {
    global $COUNTRY_NAMES;

    if (!is_string($value) || trim($value) === '') {
        return null;
    }

    $normalized = strtoupper(trim($value));
    return in_array($normalized, $COUNTRY_NAMES, true) ? $normalized : null;
}

function kop_try_normalize_location_name_cli($value) {
    if (!is_string($value) || trim($value) === '') {
        return null;
    }

    $state = normalizeStateNameCli($value);
    if ($state) {
        return $state;
    }

    return kop_is_known_country_name_cli($value);
}

function kop_find_location_name_in_text_cli($value) {
    global $US_STATE_NAMES, $COUNTRY_NAMES;

    if (!is_string($value) || trim($value) === '') {
        return null;
    }

    $normalized = strtoupper(trim($value));
    $matches = [];

    foreach (array_merge($US_STATE_NAMES, $COUNTRY_NAMES) as $locationName) {
        if (preg_match('/\\b' . preg_quote($locationName, '/') . '\\b/i', $normalized)) {
            $matches[$locationName] = true;
        }
    }

    $matchNames = array_keys($matches);
    return count($matchNames) === 1 ? $matchNames[0] : null;
}

function kop_format_location_project_name_cli($locationName) {
    $formatted = ucwords(strtolower(trim((string) $locationName)));
    return preg_replace_callback('/\\b([A-Za-z])([A-Za-z]*)\\b/u', function($matches) {
        return strtoupper($matches[1]) . strtolower($matches[2]);
    }, $formatted);
}

function kop_get_project_payload_data_cli($projectJson) {
    if (!is_array($projectJson)) {
        return [];
    }

    if (isset($projectJson['data']) && is_array($projectJson['data'])) {
        return $projectJson['data'];
    }

    return $projectJson;
}

function kop_create_empty_location_project_cli($projectName) {
    return [
        'name' => $projectName,
        'data' => [
            'facilities' => [],
            'referrerConsultants' => [],
            'operator' => [
                'name' => $projectName,
                'type' => 'Location Aggregate'
            ]
        ],
        'category' => 'locations',
        'currentFacilityIndex' => 0,
        'timestamp' => date('c')
    ];
}

function kop_prepare_location_project_for_merge_cli($projectJson, $projectName) {
    $projectJson = is_array($projectJson) ? $projectJson : [];
    $prepared = kop_create_empty_location_project_cli($projectName);

    if (!empty($projectJson)) {
        $prepared = array_merge($prepared, $projectJson);
    }

    $prepared['name'] = $projectName;
    $prepared['category'] = 'locations';
    $prepared['currentFacilityIndex'] = isset($prepared['currentFacilityIndex']) ? intval($prepared['currentFacilityIndex']) : 0;
    $prepared['timestamp'] = date('c');

    $prepared['data'] = kop_get_project_payload_data_cli($prepared);
    if (!isset($prepared['data']['facilities']) || !is_array($prepared['data']['facilities'])) {
        $prepared['data']['facilities'] = [];
    }
    if (!isset($prepared['data']['referrerConsultants']) || !is_array($prepared['data']['referrerConsultants'])) {
        $prepared['data']['referrerConsultants'] = [];
    }
    if (!isset($prepared['data']['operator']) || !is_array($prepared['data']['operator'])) {
        $prepared['data']['operator'] = [];
    }
    if (empty($prepared['data']['operator']['name'])) {
        $prepared['data']['operator']['name'] = $projectName;
    }
    if (empty($prepared['data']['operator']['type'])) {
        $prepared['data']['operator']['type'] = 'Location Aggregate';
    }

    return $prepared;
}

function kop_count_project_locations_cli($projectJson) {
    global $US_STATE_NAMES, $COUNTRY_NAMES;

    $knownLocations = array_fill_keys(array_merge($US_STATE_NAMES, $COUNTRY_NAMES), true);
    $counts = [];
    $data = kop_get_project_payload_data_cli($projectJson);

    if (!empty($data['facilities']) && is_array($data['facilities'])) {
        foreach ($data['facilities'] as $facility) {
            $location = extractFacilityStateCli($facility);
            if ($location && isset($knownLocations[$location])) {
                $counts[$location] = ($counts[$location] ?? 0) + 1;
            }
        }
    }

    if (!empty($data['referrerConsultants']) && is_array($data['referrerConsultants'])) {
        foreach ($data['referrerConsultants'] as $referrer) {
            $location = extractReferrerStateCli($referrer);
            if ($location && isset($knownLocations[$location])) {
                $counts[$location] = ($counts[$location] ?? 0) + 1;
            }
        }
    }

    return $counts;
}

function kop_resolve_location_duplicate_target_cli($projectName, $projectJson) {
    $directCandidates = [
        kop_try_normalize_location_name_cli($projectName),
        kop_try_normalize_location_name_cli($projectJson['name'] ?? '')
    ];

    foreach ($directCandidates as $candidate) {
        if ($candidate) {
            return $candidate;
        }
    }

    $embeddedCandidates = [
        kop_find_location_name_in_text_cli($projectName),
        kop_find_location_name_in_text_cli($projectJson['name'] ?? '')
    ];

    foreach ($embeddedCandidates as $candidate) {
        if ($candidate) {
            return $candidate;
        }
    }

    $data = kop_get_project_payload_data_cli($projectJson);
    if (!empty($data['operator']) && is_array($data['operator'])) {
        foreach (['locationState', 'state', 'locationCountry', 'country'] as $fieldName) {
            $candidate = kop_try_normalize_location_name_cli($data['operator'][$fieldName] ?? '');
            if ($candidate) {
                return $candidate;
            }
        }
    }

    $locationCounts = kop_count_project_locations_cli($projectJson);
    if (count($locationCounts) === 1) {
        $locations = array_keys($locationCounts);
        return $locations[0];
    }

    return null;
}

function kop_has_named_location_identity_cli($projectName, $projectJson) {
    $projectJsonName = is_array($projectJson) ? ($projectJson['name'] ?? '') : '';

    if (kop_try_normalize_location_name_cli($projectName) || kop_try_normalize_location_name_cli($projectJsonName)) {
        return true;
    }

    if (kop_find_location_name_in_text_cli($projectName) || kop_find_location_name_in_text_cli($projectJsonName)) {
        return true;
    }

    return false;
}

function mergeDuplicateLocationProjectsCli(PDO $pdo, $options = []) {
    $dryRun = !empty($options['dryRun']);
    $deleteSources = !$dryRun && !empty($options['deleteSources']);
    $backupDir = $dryRun ? '' : kop_normalize_backup_dir_cli($options['backupDir'] ?? '');
    $facilitiesTable = $options['facilitiesTable'] ?? 'facilities_master';
    $locationsTable = $options['locationsTable'] ?? 'locations_master';

    $canonicalTargets = [];
    $mergePlan = [];
    $skipped = [];
    $targetProjects = [];
    $targetNames = [];
    $sourcesToDelete = [];
    $backupRows = [];
    $backupFile = '';

    $rowsByTable = [];
    foreach ([$locationsTable, $facilitiesTable] as $tableName) {
        $stmt = $pdo->prepare("SELECT unique_name, json_data, updated_at FROM {$tableName} ORDER BY updated_at ASC, unique_name ASC");
        $stmt->execute();
        $rowsByTable[$tableName] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    foreach ($rowsByTable[$locationsTable] as $row) {
        $canonicalName = kop_try_normalize_location_name_cli($row['unique_name']);
        if ($canonicalName) {
            $canonicalTargets[$canonicalName] = $row['unique_name'];
        }
    }

    foreach ($rowsByTable as $tableName => $rows) {
        foreach ($rows as $row) {
            $projectJson = json_decode($row['json_data'], true);
            if (!is_array($projectJson)) {
                $skipped[] = [
                    'table' => $tableName,
                    'projectName' => $row['unique_name'],
                    'reason' => 'Invalid JSON payload'
                ];
                continue;
            }

            $category = strtolower(trim((string) ($projectJson['category'] ?? '')));
            $targetLocation = kop_resolve_location_duplicate_target_cli($row['unique_name'], $projectJson);

            if ($tableName !== $locationsTable && $category !== 'locations') {
                // Some imported location aggregates were saved without category metadata.
                // Only treat those as merge candidates when the project's own name
                // identifies it as a location aggregate. This avoids sweeping normal
                // company projects into state rows just because they operate in one state.
                if ($category !== '' || !$targetLocation || !kop_has_named_location_identity_cli($row['unique_name'], $projectJson)) {
                    continue;
                }
            }

            if (!$targetLocation) {
                $skipped[] = [
                    'table' => $tableName,
                    'projectName' => $row['unique_name'],
                    'reason' => 'Could not resolve canonical state/country target'
                ];
                continue;
            }

            if (!isset($canonicalTargets[$targetLocation])) {
                $exactMatch = kop_try_normalize_location_name_cli($row['unique_name']) === $targetLocation;
                $canonicalTargets[$targetLocation] = $exactMatch
                    ? $row['unique_name']
                    : kop_format_location_project_name_cli($targetLocation);
            }

            $targetProjectName = $canonicalTargets[$targetLocation];
            $sourceCanonicalName = kop_try_normalize_location_name_cli($row['unique_name']);
            $isCanonicalName = $sourceCanonicalName && $sourceCanonicalName === $targetLocation;
            $isCanonicalRow = ($tableName === $locationsTable && $isCanonicalName && strcasecmp($row['unique_name'], $targetProjectName) === 0);

            if ($isCanonicalRow) {
                if (!isset($targetProjects[$targetLocation])) {
                    $targetProjects[$targetLocation] = kop_prepare_location_project_for_merge_cli($projectJson, $targetProjectName);
                    $targetNames[$targetLocation] = $targetProjectName;
                    $backupRows[] = [
                        'table' => $tableName,
                        'unique_name' => $row['unique_name'],
                        'updated_at' => $row['updated_at'] ?? null,
                        'json_data' => $projectJson,
                        'role' => 'existing-target'
                    ];
                }
                continue;
            }

            if (!isset($targetProjects[$targetLocation])) {
                $targetProjects[$targetLocation] = kop_prepare_location_project_for_merge_cli([], $targetProjectName);
                $targetNames[$targetLocation] = $targetProjectName;
            }

            $targetProjects[$targetLocation]['data']['facilities'] = kop_merge_location_facilities_cli(
                $targetProjects[$targetLocation]['data']['facilities'],
                $projectJson['data']['facilities'] ?? $projectJson['facilities'] ?? []
            );
            $targetProjects[$targetLocation]['data']['referrerConsultants'] = kop_merge_location_referrers_cli(
                $targetProjects[$targetLocation]['data']['referrerConsultants'],
                $projectJson['data']['referrerConsultants'] ?? $projectJson['referrerConsultants'] ?? []
            );

            $mergePlan[] = [
                'sourceTable' => $tableName,
                'sourceProjectName' => $row['unique_name'],
                'targetProjectName' => $targetProjectName,
                'targetLocation' => $targetLocation,
                'facilities' => count($projectJson['data']['facilities'] ?? $projectJson['facilities'] ?? []),
                'referrers' => count($projectJson['data']['referrerConsultants'] ?? $projectJson['referrerConsultants'] ?? []),
                'updatedAt' => $row['updated_at'] ?? null
            ];

            $sourcesToDelete[] = [
                'table' => $tableName,
                'projectName' => $row['unique_name'],
                'updatedAt' => $row['updated_at'] ?? null,
                'jsonData' => $projectJson
            ];

            $backupRows[] = [
                'table' => $tableName,
                'unique_name' => $row['unique_name'],
                'updated_at' => $row['updated_at'] ?? null,
                'json_data' => $projectJson,
                'role' => 'source-duplicate'
            ];
        }
    }

    if (!$dryRun && !empty($mergePlan)) {
        $backupFile = kop_write_merge_backup_cli($backupDir, [
            'createdAt' => date('c'),
            'mode' => $deleteSources ? 'apply-and-delete' : 'stage-only',
            'facilitiesTable' => $facilitiesTable,
            'locationsTable' => $locationsTable,
            'mergePlan' => $mergePlan,
            'rows' => $backupRows
        ]);

        $pdo->beginTransaction();
        try {
            foreach ($targetProjects as $targetLocation => $projectJson) {
                $targetProjectName = $targetNames[$targetLocation];
                $jsonData = json_encode($projectJson);

                $stmt = $pdo->prepare("INSERT INTO {$locationsTable} (unique_name, json_data, updated_at) VALUES (:unique_name, :json_data_insert, NOW()) ON DUPLICATE KEY UPDATE json_data = :json_data_update, updated_at = NOW()");
                $stmt->execute([
                    ':unique_name' => $targetProjectName,
                    ':json_data_insert' => $jsonData,
                    ':json_data_update' => $jsonData
                ]);
            }

            if ($deleteSources) {
                foreach ($sourcesToDelete as $source) {
                    $stmt = $pdo->prepare("DELETE FROM {$source['table']} WHERE unique_name = :projectName");
                    $stmt->execute([':projectName' => $source['projectName']]);
                }
            }

            $pdo->commit();
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    return [
        'dryRun' => $dryRun,
        'deleteSources' => $deleteSources,
        'backupDir' => $backupDir ?: null,
        'backupFile' => $backupFile ?: null,
        'mergeCount' => count($mergePlan),
        'targetCount' => count(array_unique(array_column($mergePlan, 'targetProjectName'))),
        'targets' => array_values(array_unique(array_column($mergePlan, 'targetProjectName'))),
        'mergePlan' => $mergePlan,
        'skipped' => $skipped,
        'sourceDeleteCount' => $deleteSources ? count($sourcesToDelete) : 0,
        'sourceRetainedCount' => $deleteSources ? 0 : count($sourcesToDelete)
    ];
}

try {
    $options = parse_args($argv);
    if (!empty($options['help'])) {
        print_usage();
        exit(0);
    }

    require_once dirname(__DIR__) . '/api/config.php';

    $prefix = '';
    if (isset($table_prefix) && is_string($table_prefix)) {
        $prefix = $table_prefix;
    }

    $facilitiesTable = kop_resolve_table_name_cli($pdo, 'facilities_master', $prefix);
    $locationsTable = kop_resolve_table_name_cli($pdo, 'locations_master', $prefix);

    $result = mergeDuplicateLocationProjectsCli($pdo, [
        'dryRun' => $options['dryRun'],
        'facilitiesTable' => $facilitiesTable,
        'locationsTable' => $locationsTable,
    ]);

    if ($options['json']) {
        fwrite(STDOUT, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
        exit(0);
    }

    $modeLabel = $options['dryRun'] ? 'Dry run' : ($result['deleteSources'] ? 'Applied and deleted sources' : 'Applied staged merge');
    fwrite(STDOUT, "{$modeLabel}: {$result['mergeCount']} duplicate project(s) targeting {$result['targetCount']} canonical location project(s)." . PHP_EOL);

    if (!$options['dryRun'] && !empty($result['backupFile'])) {
        fwrite(STDOUT, 'Backup: ' . $result['backupFile'] . PHP_EOL);
    }

    if (!$options['dryRun']) {
        $retentionLine = $result['deleteSources']
            ? 'Deleted source duplicates: ' . $result['sourceDeleteCount']
            : 'Source duplicates retained for review: ' . $result['sourceRetainedCount'];
        fwrite(STDOUT, $retentionLine . PHP_EOL);
    }

    if (!empty($result['targets'])) {
        fwrite(STDOUT, 'Targets: ' . implode(', ', $result['targets']) . PHP_EOL);
    }

    if (!empty($result['mergePlan'])) {
        fwrite(STDOUT, PHP_EOL . "Merge plan:" . PHP_EOL);
        foreach ($result['mergePlan'] as $item) {
            fwrite(
                STDOUT,
                sprintf(
                    "- %s.%s -> %s (%d facilities, %d referrers)%s",
                    $item['sourceTable'],
                    $item['sourceProjectName'],
                    $item['targetProjectName'],
                    $item['facilities'],
                    $item['referrers'],
                    PHP_EOL
                )
            );
        }
    }

    if (!empty($result['skipped'])) {
        fwrite(STDOUT, PHP_EOL . "Skipped:" . PHP_EOL);
        foreach ($result['skipped'] as $item) {
            fwrite(
                STDOUT,
                sprintf("- %s.%s: %s%s", $item['table'], $item['projectName'], $item['reason'], PHP_EOL)
            );
        }
    }
} catch (Throwable $error) {
    fwrite(STDERR, 'Error: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}