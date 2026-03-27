#!/usr/bin/env php
<?php

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "This script must be run from the command line.\n");
    exit(1);
}

function print_usage() {
    $usage = <<<TEXT
Usage:
  php scripts/import-location-projects.php <json-file> [--dry-run] [--apply] [--backup-dir <dir>] [--json]

Options:
  --dry-run           Preview only. Default.
  --apply             Write changes to locations_master.
  --backup-dir <dir>  Write a JSON backup of existing rows before apply.
  --json              Print full JSON output.
  --help              Show help.

Examples:
  php scripts/import-location-projects.php tmp/location-projects-import.json --dry-run
  php scripts/import-location-projects.php tmp/location-projects-import.json --apply
TEXT;

    fwrite(STDOUT, $usage . PHP_EOL);
}

function parse_args($argv) {
    $options = [
        'dryRun' => true,
        'json' => false,
        'backupDir' => '',
        'inputFile' => ''
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
        if ($arg === '--json') {
            $options['json'] = true;
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
        if (strpos($arg, '--') === 0) {
            throw new InvalidArgumentException("Unknown argument: {$arg}");
        }
        if ($options['inputFile'] === '') {
            $options['inputFile'] = $arg;
            continue;
        }

        throw new InvalidArgumentException("Unexpected argument: {$arg}");
    }

    return $options;
}

function normalize_backup_dir($backupDir) {
    $backupDir = trim((string) $backupDir);
    if ($backupDir === '') {
        $backupDir = dirname(__DIR__) . '/tmp/location-import-backups/' . date('Ymd-His');
    }

    if (!preg_match('~^(?:[A-Za-z]:[\\/]|/)~', $backupDir)) {
        $backupDir = dirname(__DIR__) . '/' . ltrim(str_replace('\\', '/', $backupDir), '/');
    }

    return str_replace('\\', '/', $backupDir);
}

function write_backup($backupDir, array $payload) {
    if (!is_dir($backupDir) && !mkdir($backupDir, 0777, true) && !is_dir($backupDir)) {
        throw new RuntimeException("Failed to create backup directory: {$backupDir}");
    }

    $backupFile = rtrim($backupDir, '/\\') . '/location-import-backup.json';
    $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        throw new RuntimeException('Failed to encode backup payload');
    }
    if (file_put_contents($backupFile, $json) === false) {
        throw new RuntimeException("Failed to write backup file: {$backupFile}");
    }
    return $backupFile;
}

function resolve_table_name(PDO $pdo, $base, $prefix = '') {
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

function canonical_location_name($name) {
    global $US_STATE_NAMES, $COUNTRY_NAMES;

    $trimmed = trim((string) $name);
    if ($trimmed === '') {
        return '';
    }

    $upper = strtoupper($trimmed);
    if (in_array($upper, $US_STATE_NAMES, true) || in_array($upper, $COUNTRY_NAMES, true)) {
        return $upper;
    }

    return $trimmed;
}

function build_facility_key($facility) {
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

function build_referrer_key($referrer) {
    if (!is_array($referrer)) {
        return '';
    }

    $name = trim((string) ($referrer['name'] ?? $referrer['fullName'] ?? $referrer['consultantName'] ?? ''));
    $location = trim((string) ($referrer['location'] ?? ''));
    $city = trim((string) ($referrer['city'] ?? ''));
    $state = trim((string) ($referrer['state'] ?? ''));
    return strtolower($name . '|' . $location . '|' . $city . '|' . $state);
}

function merge_facilities($existingFacilities, $incomingFacilities) {
    $existingFacilities = is_array($existingFacilities) ? array_values($existingFacilities) : [];
    $incomingFacilities = is_array($incomingFacilities) ? array_values($incomingFacilities) : [];
    $indexByKey = [];

    foreach ($existingFacilities as $index => $facility) {
        $key = build_facility_key($facility);
        if ($key !== '') {
            $indexByKey[$key] = $index;
        }
    }

    foreach ($incomingFacilities as $facility) {
        $key = build_facility_key($facility);
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

function merge_referrers($existingReferrers, $incomingReferrers) {
    $existingReferrers = is_array($existingReferrers) ? array_values($existingReferrers) : [];
    $incomingReferrers = is_array($incomingReferrers) ? array_values($incomingReferrers) : [];
    $indexByKey = [];

    foreach ($existingReferrers as $index => $referrer) {
        $key = build_referrer_key($referrer);
        if ($key !== '') {
            $indexByKey[$key] = $index;
        }
    }

    foreach ($incomingReferrers as $referrer) {
        $key = build_referrer_key($referrer);
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

function merge_location_project($canonicalName, ?array $existingProject, array $incomingProject) {
    $base = [
        'name' => $canonicalName,
        'category' => 'locations',
        'currentFacilityIndex' => 0,
        'timestamp' => date('c'),
        'data' => [
            'operator' => [
                'name' => $canonicalName,
                'type' => 'Location Aggregate'
            ],
            'facilities' => [],
            'referrerConsultants' => []
        ]
    ];

    if ($existingProject) {
        $base = array_merge($base, $existingProject);
    }
    $base = array_merge($base, $incomingProject);

    $existingData = isset($existingProject['data']) && is_array($existingProject['data']) ? $existingProject['data'] : [];
    $incomingData = isset($incomingProject['data']) && is_array($incomingProject['data']) ? $incomingProject['data'] : [];
    $base['data'] = array_merge($existingData, $incomingData);
    $base['name'] = $canonicalName;
    $base['category'] = 'locations';
    $base['timestamp'] = date('c');

    if (!isset($base['data']['operator']) || !is_array($base['data']['operator'])) {
        $base['data']['operator'] = [];
    }
    if (empty($base['data']['operator']['name'])) {
        $base['data']['operator']['name'] = $canonicalName;
    }

    $base['data']['facilities'] = merge_facilities($existingData['facilities'] ?? [], $incomingData['facilities'] ?? []);
    $base['data']['referrerConsultants'] = merge_referrers($existingData['referrerConsultants'] ?? [], $incomingData['referrerConsultants'] ?? []);

    return $base;
}

try {
    $options = parse_args($argv);
    if (!empty($options['help'])) {
        print_usage();
        exit(0);
    }

    if ($options['inputFile'] === '') {
        throw new InvalidArgumentException('Input JSON file is required');
    }

    require_once dirname(__DIR__) . '/api/config.php';

    $prefix = '';
    if (isset($table_prefix) && is_string($table_prefix)) {
        $prefix = $table_prefix;
    }
    $locationsTable = resolve_table_name($pdo, 'locations_master', $prefix);

    $inputFile = $options['inputFile'];
    if (!preg_match('~^(?:[A-Za-z]:[\\/]|/)~', $inputFile)) {
        $inputFile = dirname(__DIR__) . '/' . ltrim(str_replace('\\', '/', $inputFile), '/');
    }
    if (!file_exists($inputFile)) {
        throw new RuntimeException("Input file not found: {$inputFile}");
    }

    $payload = json_decode(file_get_contents($inputFile), true);
    if (!is_array($payload) || empty($payload['projects']) || !is_array($payload['projects'])) {
        throw new RuntimeException('Input JSON must contain a top-level projects object');
    }

    $plan = [];
    $backups = [];
    $targetProjects = [];

    foreach ($payload['projects'] as $rawName => $project) {
        if (!is_array($project)) {
            continue;
        }

        $projectName = canonical_location_name($project['name'] ?? $rawName);
        if ($projectName === '') {
            continue;
        }

        $stmt = $pdo->prepare("SELECT unique_name, json_data, updated_at FROM {$locationsTable} WHERE UPPER(unique_name) = UPPER(:name) LIMIT 1");
        $stmt->execute([':name' => $projectName]);
        $existingRow = $stmt->fetch(PDO::FETCH_ASSOC);
        $existingProject = $existingRow && !empty($existingRow['json_data']) ? json_decode($existingRow['json_data'], true) : null;

        $incomingProject = $project;
        $incomingProject['name'] = $projectName;
        $incomingProject['category'] = 'locations';

        $mergedProject = merge_location_project($projectName, is_array($existingProject) ? $existingProject : null, $incomingProject);
        $targetProjects[$projectName] = $mergedProject;

        $existingFacilities = is_array($existingProject) && !empty($existingProject['data']['facilities']) && is_array($existingProject['data']['facilities'])
            ? count($existingProject['data']['facilities'])
            : 0;
        $incomingFacilities = !empty($incomingProject['data']['facilities']) && is_array($incomingProject['data']['facilities'])
            ? count($incomingProject['data']['facilities'])
            : 0;
        $mergedFacilities = !empty($mergedProject['data']['facilities']) && is_array($mergedProject['data']['facilities'])
            ? count($mergedProject['data']['facilities'])
            : 0;

        $plan[] = [
            'projectName' => $projectName,
            'existingFacilities' => $existingFacilities,
            'incomingFacilities' => $incomingFacilities,
            'mergedFacilities' => $mergedFacilities,
            'existingRowFound' => (bool) $existingRow
        ];

        if ($existingRow) {
            $backups[] = [
                'unique_name' => $existingRow['unique_name'],
                'updated_at' => $existingRow['updated_at'] ?? null,
                'json_data' => $existingProject
            ];
        }
    }

    $backupFile = null;
    if (!$options['dryRun']) {
        $backupDir = normalize_backup_dir($options['backupDir']);
        $backupFile = write_backup($backupDir, [
            'createdAt' => date('c'),
            'inputFile' => $inputFile,
            'rows' => $backups,
            'plan' => $plan
        ]);

        $pdo->beginTransaction();
        try {
            foreach ($targetProjects as $projectName => $project) {
                $jsonData = json_encode($project);
                $stmt = $pdo->prepare("INSERT INTO {$locationsTable} (unique_name, json_data, updated_at) VALUES (:unique_name, :json_data_insert, NOW()) ON DUPLICATE KEY UPDATE json_data = :json_data_update, updated_at = NOW()");
                $stmt->execute([
                    ':unique_name' => $projectName,
                    ':json_data_insert' => $jsonData,
                    ':json_data_update' => $jsonData
                ]);
            }
            $pdo->commit();
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $error;
        }
    }

    $result = [
        'dryRun' => $options['dryRun'],
        'inputFile' => $inputFile,
        'projectCount' => count($plan),
        'backupFile' => $backupFile,
        'plan' => $plan
    ];

    if ($options['json']) {
        fwrite(STDOUT, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
        exit(0);
    }

    fwrite(STDOUT, ($options['dryRun'] ? 'Dry run' : 'Applied') . ': ' . count($plan) . " location project(s)." . PHP_EOL);
    if ($backupFile) {
        fwrite(STDOUT, 'Backup: ' . $backupFile . PHP_EOL);
    }
    foreach ($plan as $item) {
        fwrite(STDOUT, sprintf("- %s (existing: %d, incoming: %d, merged: %d)%s", $item['projectName'], $item['existingFacilities'], $item['incomingFacilities'], $item['mergedFacilities'], PHP_EOL));
    }
} catch (Throwable $error) {
    fwrite(STDERR, 'Error: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}