<?php
/**
 * Save to Master Database API
 *
 * This script handles saving facility data directly to the master database.
 * It should only be accessible to administrators.
 *
 * Expected POST data:
 * - data: The facility data object to save
 * - projectName: The name of the project
 * - action: 'save' or 'delete'
 */

// Set JSON header
header('Content-Type: application/json');

// Enable error reporting for debugging (remove in production)
error_reporting(E_ALL);
ini_set('display_errors', 0); // Don't display errors in JSON response

// Custom error handler to return JSON
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    echo json_encode([
        'success' => false,
        'error' => "PHP Error: $errstr in $errfile on line $errline"
    ]);
    exit;
});

// Security: Check if user is admin (uncomment when WordPress is available)
// if (!current_user_can('administrator')) {
//     echo json_encode([
//         'success' => false,
//         'error' => 'Unauthorized: Admin access required'
//     ]);
//     exit;
// }

// Get POST data
$input = file_get_contents('php://input');
$request = json_decode($input, true);

if (!$request) {
    echo json_encode([
        'success' => false,
        'error' => 'Invalid JSON input'
    ]);
    exit;
}

// Use PDO connection from config.php
// Load this BEFORE extracting variables to avoid WordPress variable conflicts
require_once __DIR__ . '/config.php';

// Set defaults for all expected variables to prevent undefined errors
// These are defined AFTER config.php to prevent WordPress from overwriting them
$action = isset($request['action']) ? $request['action'] : 'save';
$projectName = isset($request['projectName']) ? $request['projectName'] : null;
$newProjectName = isset($request['newProjectName']) ? $request['newProjectName'] : null;
$data = isset($request['data']) ? $request['data'] : null;
$category = isset($request['category']) ? $request['category'] : 'companies';
$currentFacilityIndex = isset($request['currentFacilityIndex']) ? intval($request['currentFacilityIndex']) : 0;
$timestamp = isset($request['timestamp']) ? $request['timestamp'] : date('c');

// US State names for location project matching (used by multiple functions)
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

// Validate project name
if (!$projectName) {
    echo json_encode([
        'success' => false,
        'error' => 'Project name is required'
    ]);
    exit;
}

if ($action === 'rename') {
    if (!$projectName || !$newProjectName) {
        echo json_encode(['success' => false, 'error' => 'Old and new project names are required for rename.']);
        exit;
    }

    try {
        // Check if new project name already exists
        $checkStmt = $pdo->prepare("SELECT COUNT(*) FROM facilities_master WHERE unique_name = :newProjectName");
        $checkStmt->execute([':newProjectName' => $newProjectName]);
        if ($checkStmt->fetchColumn() > 0) {
            echo json_encode(['success' => false, 'error' => "Project '$newProjectName' already exists."]);
            exit;
        }

        // Perform the rename
        $stmt = $pdo->prepare("UPDATE facilities_master SET unique_name = :newProjectName, updated_at = NOW() WHERE unique_name = :oldProjectName");
        $stmt->execute([':newProjectName' => $newProjectName, ':oldProjectName' => $projectName]);

        // Also update sourceProject references in all location projects
        $updatedLocations = updateSourceProjectReferences($pdo, $projectName, $newProjectName);
        
        $message = "Project '$projectName' renamed to '$newProjectName'.";
        if (!empty($updatedLocations)) {
            $message .= " Updated references in location projects: " . implode(', ', $updatedLocations);
        }

        echo json_encode([
            'success' => true, 
            'message' => $message,
            'locationProjectsUpdated' => $updatedLocations
        ]);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'error' => 'Failed to rename project: ' . $e->getMessage()]);
    }
    exit;
}

/**
 * Update sourceProject references in location projects when a project is renamed
 */
function updateSourceProjectReferences($pdo, $oldProjectName, $newProjectName) {
    global $US_STATE_NAMES;
    
    $updatedStates = [];
    
    try {
        // Find all location projects
        $placeholders = implode(',', array_fill(0, count($US_STATE_NAMES), '?'));
        $stmt = $pdo->prepare("SELECT unique_name, json_data FROM facilities_master WHERE unique_name IN ($placeholders)");
        $stmt->execute($US_STATE_NAMES);
        $locationProjects = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($locationProjects as $row) {
            $stateName = $row['unique_name'];
            $projectJson = json_decode($row['json_data'], true);
            
            if (!$projectJson || !isset($projectJson['data'])) {
                continue;
            }
            
            $locationData = $projectJson['data'];
            $modified = false;
            
            // Update sourceProject in facilities
            if (isset($locationData['facilities']) && is_array($locationData['facilities'])) {
                foreach ($locationData['facilities'] as &$facility) {
                    if (isset($facility['sourceProject']) && $facility['sourceProject'] === $oldProjectName) {
                        $facility['sourceProject'] = $newProjectName;
                        $modified = true;
                    }
                }
                unset($facility);
            }
            
            // Update sourceProject in referrers
            if (isset($locationData['referrerConsultants']) && is_array($locationData['referrerConsultants'])) {
                foreach ($locationData['referrerConsultants'] as &$referrer) {
                    if (isset($referrer['sourceProject']) && $referrer['sourceProject'] === $oldProjectName) {
                        $referrer['sourceProject'] = $newProjectName;
                        $modified = true;
                    }
                }
                unset($referrer);
            }
            
            if ($modified) {
                $projectJson['data'] = $locationData;
                $projectJson['timestamp'] = date('c');
                
                $updateStmt = $pdo->prepare("UPDATE facilities_master SET json_data = :json_data, updated_at = NOW() WHERE unique_name = :stateName");
                $updateStmt->execute([
                    ':json_data' => json_encode($projectJson),
                    ':stateName' => $stateName
                ]);
                
                $updatedStates[] = $stateName;
            }
        }
    } catch (PDOException $e) {
        error_log("Failed to update source references after renaming '$oldProjectName' to '$newProjectName': " . $e->getMessage());
    }
    
    return $updatedStates;
}

// ============================================
// LOCATION PROJECT AUTO-DUPLICATION
// ============================================

/**
 * Parse city/state from a location string like "Salt Lake City, UT" or "Denver, Colorado"
 */
function parseCityState($location) {
    if (!$location || !is_string($location)) {
        return ['city' => '', 'state' => ''];
    }
    
    // State abbreviations mapping
    $stateAbbreviations = [
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
    
    // Try splitting by comma
    $parts = array_map('trim', explode(',', $location));
    if (count($parts) >= 2) {
        $city = $parts[0];
        $statePart = strtoupper(trim($parts[count($parts) - 1]));
        
        // Check if it's an abbreviation
        if (isset($stateAbbreviations[$statePart])) {
            return ['city' => $city, 'state' => $stateAbbreviations[$statePart]];
        }
        
        // Check if it's a full state name
        $stateUpper = strtoupper($statePart);
        if (in_array($stateUpper, array_values($stateAbbreviations))) {
            return ['city' => $city, 'state' => $stateUpper];
        }
    }
    
    return ['city' => $location, 'state' => ''];
}

/**
 * Extract state from a facility record
 */
function extractFacilityState($facility) {
    global $US_STATE_NAMES;
    
    // First check explicit locationState field
    if (!empty($facility['locationState'])) {
        $state = strtoupper(trim($facility['locationState']));
        if (in_array($state, $US_STATE_NAMES)) {
            return $state;
        }
    }
    
    // Then try parsing from location string
    if (!empty($facility['location'])) {
        $parsed = parseCityState($facility['location']);
        if (!empty($parsed['state']) && in_array($parsed['state'], $US_STATE_NAMES)) {
            return $parsed['state'];
        }
    }
    
    return null;
}

/**
 * Extract state from a referrer/consultant record
 */
function extractReferrerState($referrer) {
    global $US_STATE_NAMES;
    
    if (!empty($referrer['state'])) {
        $state = strtoupper(trim($referrer['state']));
        if (in_array($state, $US_STATE_NAMES)) {
            return $state;
        }
    }
    
    return null;
}

/**
 * Update location projects based on saved company/referrer data
 * This duplicates facilities and referrers to their respective state location projects
 */
function updateLocationProjectsFromSave($pdo, $sourceProjectName, $data, $sourceCategory) {
    global $US_STATE_NAMES;
    
    $updatedStates = [];
    $stateBuckets = [];
    
    // Initialize buckets for facilities and referrers by state
    $initBucket = function($state) use (&$stateBuckets) {
        if (!isset($stateBuckets[$state])) {
            $stateBuckets[$state] = [
                'facilities' => [],
                'referrers' => []
            ];
        }
    };
    
    // Process facilities
    if (!empty($data['facilities']) && is_array($data['facilities'])) {
        foreach ($data['facilities'] as $facility) {
            $state = extractFacilityState($facility);
            if ($state) {
                $initBucket($state);
                // Clone the facility and add source project info
                $clone = $facility;
                $clone['sourceProject'] = $sourceProjectName;
                $clone['sourceCategory'] = $sourceCategory;
                $stateBuckets[$state]['facilities'][] = $clone;
            }
        }
    }
    
    // Process referrer consultants
    if (!empty($data['referrerConsultants']) && is_array($data['referrerConsultants'])) {
        foreach ($data['referrerConsultants'] as $consultant) {
            $state = extractReferrerState($consultant);
            if ($state) {
                $initBucket($state);
                $clone = $consultant;
                $clone['sourceProject'] = $sourceProjectName;
                $clone['sourceCategory'] = $sourceCategory;
                $stateBuckets[$state]['referrers'][] = $clone;
            }
        }
    }
    
    // Process individual referrer
    if (!empty($data['referrerIndividual']) && is_array($data['referrerIndividual'])) {
        $state = extractReferrerState($data['referrerIndividual']);
        if ($state) {
            $initBucket($state);
            $clone = $data['referrerIndividual'];
            $clone['sourceProject'] = $sourceProjectName;
            $clone['sourceCategory'] = $sourceCategory;
            $stateBuckets[$state]['referrers'][] = $clone;
        }
    }
    
    // Process referrer agency
    if (!empty($data['referrerAgency']) && !empty($data['referrerAgency']['state'])) {
        $state = strtoupper(trim($data['referrerAgency']['state']));
        if (in_array($state, $US_STATE_NAMES)) {
            $initBucket($state);
            $clone = $data['referrerAgency'];
            $clone['sourceProject'] = $sourceProjectName;
            $clone['sourceCategory'] = $sourceCategory;
            // Store agency separately if needed
            if (!isset($stateBuckets[$state]['agencies'])) {
                $stateBuckets[$state]['agencies'] = [];
            }
            $stateBuckets[$state]['agencies'][] = $clone;
        }
    }
    
    // Now update each affected state's location project
    foreach ($stateBuckets as $stateName => $bucket) {
        try {
            // First, load existing location project (if any)
            $existingProject = null;
            $stmt = $pdo->prepare("SELECT json_data FROM facilities_master WHERE unique_name = :stateName");
            $stmt->execute([':stateName' => $stateName]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($row && !empty($row['json_data'])) {
                $existingProject = json_decode($row['json_data'], true);
            }
            
            // Initialize or update the location project data
            $locationData = [];
            if ($existingProject && isset($existingProject['data'])) {
                $locationData = $existingProject['data'];
            }
            
            // Ensure arrays exist
            if (!isset($locationData['facilities']) || !is_array($locationData['facilities'])) {
                $locationData['facilities'] = [];
            }
            if (!isset($locationData['referrerConsultants']) || !is_array($locationData['referrerConsultants'])) {
                $locationData['referrerConsultants'] = [];
            }
            
            // Remove old entries from this source project (to avoid duplicates)
            $locationData['facilities'] = array_filter($locationData['facilities'], function($f) use ($sourceProjectName) {
                return !isset($f['sourceProject']) || $f['sourceProject'] !== $sourceProjectName;
            });
            $locationData['referrerConsultants'] = array_filter($locationData['referrerConsultants'], function($r) use ($sourceProjectName) {
                return !isset($r['sourceProject']) || $r['sourceProject'] !== $sourceProjectName;
            });
            
            // Re-index arrays after filtering
            $locationData['facilities'] = array_values($locationData['facilities']);
            $locationData['referrerConsultants'] = array_values($locationData['referrerConsultants']);
            
            // Add new entries from this source project
            foreach ($bucket['facilities'] as $facility) {
                $locationData['facilities'][] = $facility;
            }
            foreach ($bucket['referrers'] as $referrer) {
                $locationData['referrerConsultants'][] = $referrer;
            }
            
            // Create the project structure
            $locationProject = [
                'name' => $stateName,
                'data' => $locationData,
                'category' => 'locations',
                'currentFacilityIndex' => 0,
                'timestamp' => date('c')
            ];
            
            $jsonData = json_encode($locationProject);
            
            // Upsert the location project
            $sql = "INSERT INTO facilities_master (unique_name, json_data, updated_at)
                    VALUES (:unique_name, :json_data, NOW())
                    ON DUPLICATE KEY UPDATE json_data = :json_data, updated_at = NOW()";
            
            $stmt = $pdo->prepare($sql);
            $stmt->bindValue(':unique_name', $stateName);
            $stmt->bindValue(':json_data', $jsonData);
            $stmt->execute();
            
            $updatedStates[] = $stateName;
            
        } catch (PDOException $e) {
            // Log the error but continue with other states
            error_log("Failed to update location project '$stateName': " . $e->getMessage());
        }
    }
    
    return $updatedStates;
}

/**
 * Remove all entries from location projects that came from a specific source project
 * Called when a company/referrer project is deleted
 */
function removeFromLocationProjects($pdo, $sourceProjectName) {
    global $US_STATE_NAMES;
    
    $cleanedStates = [];
    
    try {
        // Find all location projects (projects whose names match US states)
        $placeholders = implode(',', array_fill(0, count($US_STATE_NAMES), '?'));
        $stmt = $pdo->prepare("SELECT unique_name, json_data FROM facilities_master WHERE unique_name IN ($placeholders)");
        $stmt->execute($US_STATE_NAMES);
        $locationProjects = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($locationProjects as $row) {
            $stateName = $row['unique_name'];
            $projectJson = json_decode($row['json_data'], true);
            
            if (!$projectJson || !isset($projectJson['data'])) {
                continue;
            }
            
            $locationData = $projectJson['data'];
            $modified = false;
            
            // Filter out facilities from this source project
            if (isset($locationData['facilities']) && is_array($locationData['facilities'])) {
                $originalCount = count($locationData['facilities']);
                $locationData['facilities'] = array_values(array_filter($locationData['facilities'], function($f) use ($sourceProjectName) {
                    return !isset($f['sourceProject']) || $f['sourceProject'] !== $sourceProjectName;
                }));
                if (count($locationData['facilities']) !== $originalCount) {
                    $modified = true;
                }
            }
            
            // Filter out referrers from this source project
            if (isset($locationData['referrerConsultants']) && is_array($locationData['referrerConsultants'])) {
                $originalCount = count($locationData['referrerConsultants']);
                $locationData['referrerConsultants'] = array_values(array_filter($locationData['referrerConsultants'], function($r) use ($sourceProjectName) {
                    return !isset($r['sourceProject']) || $r['sourceProject'] !== $sourceProjectName;
                }));
                if (count($locationData['referrerConsultants']) !== $originalCount) {
                    $modified = true;
                }
            }
            
            // Only update if we actually removed something
            if ($modified) {
                $projectJson['data'] = $locationData;
                $projectJson['timestamp'] = date('c');
                
                $updateStmt = $pdo->prepare("UPDATE facilities_master SET json_data = :json_data, updated_at = NOW() WHERE unique_name = :stateName");
                $updateStmt->execute([
                    ':json_data' => json_encode($projectJson),
                    ':stateName' => $stateName
                ]);
                
                $cleanedStates[] = $stateName;
            }
        }
    } catch (PDOException $e) {
        error_log("Failed to clean location projects after deleting '$sourceProjectName': " . $e->getMessage());
    }
    
    return $cleanedStates;
}


// Handle action
if ($action === 'delete') {
    try {
        // First, check if this is a location project (state name) - don't allow deleting those
        global $US_STATE_NAMES;
        if (in_array(strtoupper($projectName), $US_STATE_NAMES)) {
            echo json_encode([
                'success' => false,
                'error' => "Cannot delete location project '$projectName'. Location projects are auto-managed."
            ]);
            exit;
        }
        
        // Try deleting from both tables (referrers and facilities)
        $deletedFromFacilities = false;
        $deletedFromReferrers = false;

        $stmt = $pdo->prepare("DELETE FROM facilities_master WHERE unique_name = :projectName");
        $stmt->execute([':projectName' => $projectName]);
        $deletedFromFacilities = $stmt->rowCount() > 0;

        $stmt = $pdo->prepare("DELETE FROM referrers_master WHERE unique_name = :projectName");
        $stmt->execute([':projectName' => $projectName]);
        $deletedFromReferrers = $stmt->rowCount() > 0;

        if ($deletedFromFacilities || $deletedFromReferrers) {
            $fromTable = $deletedFromReferrers ? 'referrers_master' : 'facilities_master';
            
            // Also remove this project's entries from all location projects
            $cleanedLocations = removeFromLocationProjects($pdo, $projectName);
            
            $message = "Project '$projectName' deleted from {$fromTable}";
            if (!empty($cleanedLocations)) {
                $message .= ". Removed from location projects: " . implode(', ', $cleanedLocations);
            }
            
            echo json_encode([
                'success' => true,
                'message' => $message,
                'locationProjectsCleaned' => $cleanedLocations
            ]);
        } else {
            echo json_encode([
                'success' => false,
                'error' => 'Project not found in any database table'
            ]);
        }
    } catch (PDOException $e) {
        echo json_encode([
            'success' => false,
            'error' => 'Failed to delete project from database: ' . $e->getMessage()
        ]);
    }
    exit;
}


// Handle save action
if ($action === 'save') {
    if (!$data) {
        echo json_encode([
            'success' => false,
            'error' => 'No data provided to save'
        ]);
        exit;
    }

    // Create the complete project structure with metadata
    $projectStructure = [
        'name' => $projectName,
        'data' => $data,
        'category' => $category,
        'currentFacilityIndex' => $currentFacilityIndex,
        'timestamp' => $timestamp
    ];

    // Encode the complete project structure as a JSON string
    $jsonData = json_encode($projectStructure);

    // Determine which table to use based on category
    $tableName = ($category === 'referrers') ? 'referrers_master' : 'facilities_master';

    // SQL to insert or update the project data based on projectName
    // The identifier column is `unique_name` and the data column is `json_data`.
    $sql = "INSERT INTO {$tableName} (unique_name, json_data, updated_at)
            VALUES (:unique_name, :json_data, NOW())
            ON DUPLICATE KEY UPDATE json_data = :json_data, updated_at = NOW()";

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':unique_name', $projectName);
        $stmt->bindValue(':json_data', $jsonData);

        $stmt->execute();
        
        // Auto-duplicate to location projects if this is a companies/referrers project
        $locationUpdates = [];
        if ($category !== 'locations') {
            $locationUpdates = updateLocationProjectsFromSave($pdo, $projectName, $data, $category);
        }
        
        $message = "Project '$projectName' saved to {$tableName}";
        if (!empty($locationUpdates)) {
            $message .= ". Location projects updated: " . implode(', ', $locationUpdates);
        }
        
        echo json_encode([
            'success' => true,
            'message' => $message,
            'locationProjectsUpdated' => $locationUpdates
        ]);
    } catch (PDOException $e) {
        echo json_encode([
            'success' => false,
            'error' => 'Failed to save to database: ' . $e->getMessage()
        ]);
    }
    exit;
}

// Handle rebuild-locations action - rebuilds ALL location projects from existing data
if ($action === 'rebuild-locations') {
    try {
        $result = rebuildAllLocationProjects($pdo);
        echo json_encode([
            'success' => true,
            'message' => "Rebuilt {$result['projectsProcessed']} projects into {$result['statesUpdated']} location projects",
            'details' => $result
        ]);
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'error' => 'Failed to rebuild location projects: ' . $e->getMessage()
        ]);
    }
    exit;
}

/**
 * Rebuild ALL location projects from scratch by processing all existing company/referrer projects
 */
function rebuildAllLocationProjects($pdo) {
    global $US_STATE_NAMES;
    
    $stateBuckets = [];
    $projectsProcessed = 0;
    
    // Initialize buckets for all states
    foreach ($US_STATE_NAMES as $state) {
        $stateBuckets[$state] = [
            'facilities' => [],
            'referrers' => []
        ];
    }
    
    // Fetch all projects from facilities_master
    $stmt = $pdo->prepare("SELECT unique_name, json_data FROM facilities_master");
    $stmt->execute();
    $facilitiesResults = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Fetch all projects from referrers_master
    $stmt = $pdo->prepare("SELECT unique_name, json_data FROM referrers_master");
    $stmt->execute();
    $referrersResults = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Merge and process all projects
    $allProjects = array_merge($facilitiesResults, $referrersResults);
    
    foreach ($allProjects as $row) {
        $projectName = $row['unique_name'];
        
        // Skip location projects (state names) - we're rebuilding those
        if (in_array(strtoupper($projectName), $US_STATE_NAMES)) {
            continue;
        }
        
        $projectJson = json_decode($row['json_data'], true);
        if (!$projectJson) {
            continue;
        }
        
        // Get the actual data (handle both old and new format)
        $data = isset($projectJson['data']) ? $projectJson['data'] : $projectJson;
        $sourceCategory = isset($projectJson['category']) ? $projectJson['category'] : 'companies';
        
        // Skip if this is marked as a locations project
        if ($sourceCategory === 'locations') {
            continue;
        }
        
        $projectsProcessed++;
        
        // Process facilities
        if (!empty($data['facilities']) && is_array($data['facilities'])) {
            foreach ($data['facilities'] as $facility) {
                $state = extractFacilityState($facility);
                if ($state && isset($stateBuckets[$state])) {
                    $clone = $facility;
                    $clone['sourceProject'] = $projectName;
                    $clone['sourceCategory'] = $sourceCategory;
                    $stateBuckets[$state]['facilities'][] = $clone;
                }
            }
        }
        
        // Process referrer consultants
        if (!empty($data['referrerConsultants']) && is_array($data['referrerConsultants'])) {
            foreach ($data['referrerConsultants'] as $consultant) {
                $state = extractReferrerState($consultant);
                if ($state && isset($stateBuckets[$state])) {
                    $clone = $consultant;
                    $clone['sourceProject'] = $projectName;
                    $clone['sourceCategory'] = $sourceCategory;
                    $stateBuckets[$state]['referrers'][] = $clone;
                }
            }
        }
        
        // Process individual referrer
        if (!empty($data['referrerIndividual']) && is_array($data['referrerIndividual'])) {
            $state = extractReferrerState($data['referrerIndividual']);
            if ($state && isset($stateBuckets[$state])) {
                $clone = $data['referrerIndividual'];
                $clone['sourceProject'] = $projectName;
                $clone['sourceCategory'] = $sourceCategory;
                $stateBuckets[$state]['referrers'][] = $clone;
            }
        }
    }
    
    // Now save all location projects
    $statesUpdated = 0;
    $stateDetails = [];
    
    foreach ($stateBuckets as $stateName => $bucket) {
        $facilityCount = count($bucket['facilities']);
        $referrerCount = count($bucket['referrers']);
        
        // Only create/update if there's actual data
        if ($facilityCount > 0 || $referrerCount > 0) {
            $locationData = [
                'facilities' => $bucket['facilities'],
                'referrerConsultants' => $bucket['referrers'],
                'operator' => [
                    'name' => $stateName,
                    'type' => 'Location Aggregate'
                ]
            ];
            
            $locationProject = [
                'name' => $stateName,
                'data' => $locationData,
                'category' => 'locations',
                'currentFacilityIndex' => 0,
                'timestamp' => date('c')
            ];
            
            $jsonData = json_encode($locationProject);
            
            $sql = "INSERT INTO facilities_master (unique_name, json_data, updated_at)
                    VALUES (:unique_name, :json_data, NOW())
                    ON DUPLICATE KEY UPDATE json_data = :json_data, updated_at = NOW()";
            
            $stmt = $pdo->prepare($sql);
            $stmt->bindValue(':unique_name', $stateName);
            $stmt->bindValue(':json_data', $jsonData);
            $stmt->execute();
            
            $statesUpdated++;
            $stateDetails[$stateName] = [
                'facilities' => $facilityCount,
                'referrers' => $referrerCount
            ];
        }
    }
    
    return [
        'projectsProcessed' => $projectsProcessed,
        'statesUpdated' => $statesUpdated,
        'stateDetails' => $stateDetails
    ];
}

// Unknown action
echo json_encode([
    'success' => false,
    'error' => "Unknown action: $action"
]);
