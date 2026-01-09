<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/config.php';

// This script fetches all records from the master data table
header('Content-Type: application/json');

try {
    // Get WordPress table prefix from wp-config.php
    if (!defined('ABSPATH')) {
        define('ABSPATH', dirname(dirname(dirname(__DIR__))) . '/');
    }
    
    $wp_config_path = ABSPATH . 'wp-config.php';
    $prefix = 'wp_'; // Default fallback

    if (file_exists($wp_config_path)) {
        require_once $wp_config_path;
        if (isset($table_prefix)) {
            $prefix = $table_prefix;
        }
    } else {
        // Try one level up if we are in a theme folder in a different structure
        $alt_path = dirname(dirname(dirname(dirname(__DIR__)))) . '/wp-config.php';
        if (file_exists($alt_path)) {
             require_once $alt_path;
             if (isset($table_prefix)) {
                $prefix = $table_prefix;
            }
        }
    }
    
    // Query all three master tables: facilities, referrers, and locations
    $facilitiesResults = [];
    try {
        $stmt1 = $pdo->prepare("SELECT unique_name, json_data FROM {$prefix}facilities_master");
        $stmt1->execute();
        $facilitiesResults = $stmt1->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        // Table might not exist, ignore and try fallback
    }
    
    // Fallback: If prefixed table is empty or failed, try non-prefixed table
    if (empty($facilitiesResults)) {
        try {
            $stmt1b = $pdo->prepare("SELECT unique_name, json_data FROM facilities_master");
            $stmt1b->execute();
            $facilitiesResults = $stmt1b->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            // Ignore error if fallback table doesn't exist
        }
    }

    $referrersResults = [];
    try {
        $stmt2 = $pdo->prepare("SELECT unique_name, json_data FROM referrers_master");
        $stmt2->execute();
        $referrersResults = $stmt2->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        // Table might not exist
    }

    // locations_master is optional - may not exist yet
    $locationsResults = [];
    try {
        $stmt3 = $pdo->prepare("SELECT unique_name, json_data FROM {$prefix}locations_master");
        $stmt3->execute();
        $locationsResults = $stmt3->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        // Table doesn't exist yet
    }
    
    // Fallback for locations
    if (empty($locationsResults)) {
        try {
            $stmt3b = $pdo->prepare("SELECT unique_name, json_data FROM locations_master");
            $stmt3b->execute();
            $locationsResults = $stmt3b->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            // Ignore error
        }
    }

    // NEW: wiki_master canonical table
    $wikiMasterResults = [];
    try {
        $stmt4 = $pdo->prepare("SELECT slug, program_name, json_data, updated_at FROM wiki_master");
        $stmt4->execute();
        $wikiMasterResults = $stmt4->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        // Table doesn't exist yet
    }
    
    // Mark source tables for proper categorization
    foreach ($locationsResults as &$row) {
        $row['_source_table'] = 'locations';
    }
    foreach ($facilitiesResults as &$row) {
        $row['_source_table'] = 'facilities';
    }
    foreach ($referrersResults as &$row) {
        $row['_source_table'] = 'referrers';
    }
    foreach ($wikiMasterResults as &$row) {
        $row['_source_table'] = 'wiki';
        $row['unique_name'] = 'wiki_master_' . $row['slug'];
    }

    // Merge all result sets
    $results = array_merge($facilitiesResults, $referrersResults, $locationsResults, $wikiMasterResults);

    $projects = [];
    foreach ($results as $row) {
        // Decode the stored JSON
        $stored = json_decode($row['json_data'], true);

        // Detect format by checking where facilities/operator live:
        // NEW format: { name, data: { operator, facilities }, category?, timestamp? }
        // OLD format: { operator, facilities, ... } (at root level)
        
        $hasNestedFacilities = isset($stored['data']['facilities']) && is_array($stored['data']['facilities']);
        $hasNestedOperator = isset($stored['data']['operator']) && is_array($stored['data']['operator']);
        $hasRootFacilities = isset($stored['facilities']) && is_array($stored['facilities']);
        $hasRootOperator = isset($stored['operator']) && is_array($stored['operator']);
        
        // It's NEW format if data contains facilities OR operator
        $isNewFormat = $hasNestedFacilities || $hasNestedOperator;
        
        // Determine category based on source table if not explicitly set
        $defaultCategory = 'companies';
        if (isset($row['_source_table'])) {
            if ($row['_source_table'] === 'locations') {
                $defaultCategory = 'locations';
            } elseif ($row['_source_table'] === 'referrers') {
                $defaultCategory = 'referrers';
            }
        }

        if ($isNewFormat) {
            // New format: data is already nested correctly, just pass through
            $stored['name'] = $stored['name'] ?? $row['unique_name'];
            $stored['category'] = $stored['category'] ?? $defaultCategory;
            $stored['currentFacilityIndex'] = $stored['currentFacilityIndex'] ?? 0;
            $stored['timestamp'] = $stored['timestamp'] ?? date('c');
            $stored['_sourceTable'] = $row['_source_table'] ?? 'facilities';
            $projects[$row['unique_name']] = $stored;
        } else {
            // Old format: facilities/operator at root, need to wrap in 'data'
            $projects[$row['unique_name']] = [
                'name' => $row['unique_name'],
                'data' => $stored,
                'timestamp' => $stored['timestamp'] ?? date('c'),
                'currentFacilityIndex' => $stored['currentFacilityIndex'] ?? 0,
                'category' => $stored['category'] ?? $defaultCategory,
                '_sourceTable' => $row['_source_table'] ?? 'facilities'
            ];
        }
    }

    // Fetch published wiki submissions
    try {
        // wiki_submissions table (no prefix based on check-tables.php)
        $stmtWiki = $pdo->prepare("SELECT id, program_name, json_data, updated_at FROM wiki_submissions WHERE status = 'published'");
        $stmtWiki->execute();
        $wikiResults = $stmtWiki->fetchAll(PDO::FETCH_ASSOC);

        foreach ($wikiResults as $wRow) {
            $wData = json_decode($wRow['json_data'], true);
            if (!$wData) continue;

            $opName = !empty($wData['ownerName']) ? $wData['ownerName'] : (!empty($wData['programName']) ? $wData['programName'] : 'Unknown Operator');
            $progName = !empty($wData['programName']) ? $wData['programName'] : 'Unnamed Facility';

            $facList = [];

            // Derive status from yearsActive
            $yearsActive = $wData['yearsActive'] ?? '';
            $status = 'Unknown';
            if (stripos($yearsActive, 'present') !== false || stripos($yearsActive, 'current') !== false || substr(trim($yearsActive), -1) === '-') {
                $status = 'Open';
            } elseif (preg_match('/[0-9]{4}/', $yearsActive)) {
                // If it has a year and isn't "present", assume closed if it looks like a range ending
                // Simple heuristic: if it has 2 years "1990-2000", likely closed.
                // If just "2000", unclear.
                // Wiki format is often "1990-2005"
                if (preg_match('/[0-9]{4}\s*-\s*[0-9]{4}/', $yearsActive)) {
                    $status = 'Closed';
                }
            }

            // Primary facility (the one described in the main form)
            $facList[] = [
                'identification' => [
                    'name' => $progName,
                    'currentOperator' => $opName
                ],
                'location' => $wData['cityState'] ?? '',
                'address' => $wData['mainAddress'] ?? '',
                'facilityDetails' => [
                    'type' => $wData['programType'] ?? '',
                    'capacity' => $wData['capacity'] ?? '',
                    'ageRange' => [
                        'text' => $wData['ageRange'] ?? ''
                    ]
                ],
                'operatingPeriod' => [
                    'startYear' => $wData['yearFounded'] ?? '',
                    'text' => $yearsActive,
                    'status' => $status
                ]
            ];

            // Additional campuses
            if (!empty($wData['campuses']) && is_array($wData['campuses'])) {
                foreach ($wData['campuses'] as $campus) {
                    if (empty($campus['campusName'])) continue;
                    $facList[] = [
                        'identification' => [
                            'name' => $campus['campusName'],
                            'currentOperator' => $opName
                        ],
                        'location' => $campus['campusLocation'] ?? ''
                    ];
                }
            }

            $project = [
                'name' => $opName, // Group by Operator Name
                'category' => 'companies',
                'timestamp' => $wRow['updated_at'] ?? date('c'),
                'data' => [
                    'operator' => [
                        'name' => $opName,
                        'location' => '', 
                    ],
                    'facilities' => $facList
                ]
            ];

            // Use a unique key for the project to avoid collision with master data
            // Prefixing with 'wiki_'
            $projects['wiki_' . $wRow['id']] = $project;
        }
    } catch (PDOException $e) {
        // If wiki_submissions table doesn't exist or errors, just ignore and continue with master data
        error_log("Wiki submissions fetch error: " . $e->getMessage());
    }

    echo json_encode(['success' => true, 'projects' => $projects]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
}
?>