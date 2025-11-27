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

// Common countries for international facilities
$COUNTRY_NAMES = [
    'CANADA', 'MEXICO', 'UNITED KINGDOM', 'AUSTRALIA', 'JAMAICA', 'SAMOA', 'COSTA RICA',
    'BELIZE', 'BAHAMAS', 'DOMINICAN REPUBLIC', 'PUERTO RICO', 'FRANCE', 'GERMANY', 'ITALY',
    'SPAIN', 'NETHERLANDS', 'SWITZERLAND', 'SWEDEN', 'NORWAY', 'DENMARK', 'IRELAND',
    'NEW ZEALAND', 'SOUTH AFRICA', 'ISRAEL', 'JAPAN', 'CHINA', 'INDIA', 'BRAZIL', 'ARGENTINA'
];

// State abbreviation to full name mapping
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
 * Normalize a state value - convert abbreviation to full name if needed
 */
function normalizeStateName($state) {
    global $US_STATE_NAMES, $STATE_ABBREVIATIONS;
    
    if (!$state || !is_string($state)) {
        return null;
    }
    
    $state = strtoupper(trim($state));
    
    // Check if it's already a full state name
    if (in_array($state, $US_STATE_NAMES)) {
        return $state;
    }
    
    // Check if it's an abbreviation
    if (isset($STATE_ABBREVIATIONS[$state])) {
        return $STATE_ABBREVIATIONS[$state];
    }
    
    return null;
}

/**
 * Normalize a country value
 */
function normalizeCountryName($country) {
    global $COUNTRY_NAMES;
    
    if (!$country || !is_string($country)) {
        return null;
    }
    
    $country = strtoupper(trim($country));
    
    // Check if it's a known country
    if (in_array($country, $COUNTRY_NAMES)) {
        return $country;
    }
    
    // For unknown countries, still return the uppercased value
    // This allows new countries to be added dynamically
    if (strlen($country) > 1) {
        return $country;
    }
    
    return null;
}

/**
 * Extract state or country from a facility record for location project grouping
 * Returns the state/country name that will be used as the location project name
 * This function checks ALL possible field locations where state/country might be stored
 */
function extractFacilityState($facility) {
    global $US_STATE_NAMES, $COUNTRY_NAMES, $STATE_ABBREVIATIONS;
    
    if (!is_array($facility)) {
        return null;
    }
    
    // Check if facility is marked as international
    $isInternational = !empty($facility['isInternational']);
    
    // Helper to try normalizing a value as state first, then country
    $tryNormalize = function($value) use ($isInternational) {
        if (empty($value) || !is_string($value)) {
            return null;
        }
        
        // Try as US state first
        $state = normalizeStateName($value);
        if ($state) {
            return $state;
        }
        
        // If international or state not found, try as country
        $country = normalizeCountryName($value);
        if ($country) {
            return $country;
        }
        
        return null;
    };
    
    // 1. Check the nested locationDetails structure (new form format)
    if (!empty($facility['locationDetails']) && is_array($facility['locationDetails'])) {
        $details = $facility['locationDetails'];
        
        // Check state in locationDetails
        if (!empty($details['state'])) {
            $result = $tryNormalize($details['state']);
            if ($result) return $result;
        }
        
        // Check country in locationDetails (for international)
        if (!empty($details['country'])) {
            $result = $tryNormalize($details['country']);
            if ($result) return $result;
        }
    }
    
    // 2. Check flat locationState field
    if (!empty($facility['locationState'])) {
        $result = $tryNormalize($facility['locationState']);
        if ($result) return $result;
    }
    
    // 3. Check flat state field
    if (!empty($facility['state'])) {
        $result = $tryNormalize($facility['state']);
        if ($result) return $result;
    }
    
    // 4. Check flat locationCountry field
    if (!empty($facility['locationCountry'])) {
        $result = $tryNormalize($facility['locationCountry']);
        if ($result) return $result;
    }
    
    // 5. Check flat country field
    if (!empty($facility['country'])) {
        $result = $tryNormalize($facility['country']);
        if ($result) return $result;
    }
    
    // 6. Try parsing from "location" string field (e.g., "Salt Lake City, UT")
    if (!empty($facility['location']) && is_string($facility['location'])) {
        $parsed = parseCityState($facility['location']);
        if (!empty($parsed['state'])) {
            $result = $tryNormalize($parsed['state']);
            if ($result) return $result;
        }
    }
    
    // 7. Check identification section for location hints
    if (!empty($facility['identification']) && is_array($facility['identification'])) {
        if (!empty($facility['identification']['state'])) {
            $result = $tryNormalize($facility['identification']['state']);
            if ($result) return $result;
        }
        if (!empty($facility['identification']['locationState'])) {
            $result = $tryNormalize($facility['identification']['locationState']);
            if ($result) return $result;
        }
        if (!empty($facility['identification']['country'])) {
            $result = $tryNormalize($facility['identification']['country']);
            if ($result) return $result;
        }
    }
    
    // 8. Check address field if it contains state info
    if (!empty($facility['address']) && is_string($facility['address'])) {
        // Try to extract state from end of address
        $parsed = parseCityState($facility['address']);
        if (!empty($parsed['state'])) {
            $result = $tryNormalize($parsed['state']);
            if ($result) return $result;
        }
    }
    
    return null;
}

/**
 * Extract state from a referrer/consultant record
 */
function extractReferrerState($referrer) {
    if (!is_array($referrer)) {
        return null;
    }
    
    // Check state field
    if (!empty($referrer['state'])) {
        $state = normalizeStateName($referrer['state']);
        if ($state) {
            return $state;
        }
        // Try as country
        $country = normalizeCountryName($referrer['state']);
        if ($country) {
            return $country;
        }
    }
    
    // Check location field (City, State format)
    if (!empty($referrer['location']) && is_string($referrer['location'])) {
        $parsed = parseCityState($referrer['location']);
        if (!empty($parsed['state'])) {
            $state = normalizeStateName($parsed['state']);
            if ($state) {
                return $state;
            }
        }
    }
    
    // Check city field - sometimes state is stored incorrectly
    if (!empty($referrer['city']) && is_string($referrer['city'])) {
        // Some records have "City, State" in city field
        $parsed = parseCityState($referrer['city']);
        if (!empty($parsed['state'])) {
            $state = normalizeStateName($parsed['state']);
            if ($state) {
                return $state;
            }
        }
    }
    
    return null;
}

/**
 * Extract state from operator record  
 */
function extractOperatorState($operator) {
    if (!is_array($operator)) {
        return null;
    }
    
    // Check state field
    if (!empty($operator['state'])) {
        $state = normalizeStateName($operator['state']);
        if ($state) {
            return $state;
        }
    }
    
    // Check locationState field
    if (!empty($operator['locationState'])) {
        $state = normalizeStateName($operator['locationState']);
        if ($state) {
            return $state;
        }
    }
    
    // Check headquarters location
    if (!empty($operator['location']) && is_string($operator['location'])) {
        $parsed = parseCityState($operator['location']);
        if (!empty($parsed['state'])) {
            $state = normalizeStateName($parsed['state']);
            if ($state) {
                return $state;
            }
        }
    }
    
    // Check hqState
    if (!empty($operator['hqState'])) {
        $state = normalizeStateName($operator['hqState']);
        if ($state) {
            return $state;
        }
    }
    
    return null;
}

/**
 * Update location projects based on saved company/referrer data
 * This duplicates facilities and referrers to their respective state/country location projects
 */
function updateLocationProjectsFromSave($pdo, $sourceProjectName, $data, $sourceCategory) {
    global $US_STATE_NAMES, $COUNTRY_NAMES;
    
    $updatedLocations = [];
    $locationBuckets = [];
    
    // Initialize buckets for facilities and referrers by location (state or country)
    $initBucket = function($location) use (&$locationBuckets) {
        if (!isset($locationBuckets[$location])) {
            $locationBuckets[$location] = [
                'facilities' => [],
                'referrers' => []
            ];
        }
    };
    
    // Process facilities
    if (!empty($data['facilities']) && is_array($data['facilities'])) {
        foreach ($data['facilities'] as $facility) {
            $location = extractFacilityState($facility);
            if ($location) {
                $initBucket($location);
                // Clone the facility and add source project info
                $clone = $facility;
                $clone['sourceProject'] = $sourceProjectName;
                $clone['sourceCategory'] = $sourceCategory;
                $locationBuckets[$location]['facilities'][] = $clone;
            }
        }
    }
    
    // Process referrer consultants
    if (!empty($data['referrerConsultants']) && is_array($data['referrerConsultants'])) {
        foreach ($data['referrerConsultants'] as $consultant) {
            $location = extractReferrerState($consultant);
            if ($location) {
                $initBucket($location);
                $clone = $consultant;
                $clone['sourceProject'] = $sourceProjectName;
                $clone['sourceCategory'] = $sourceCategory;
                $locationBuckets[$location]['referrers'][] = $clone;
            }
        }
    }
    
    // Process individual referrer
    if (!empty($data['referrerIndividual']) && is_array($data['referrerIndividual'])) {
        $location = extractReferrerState($data['referrerIndividual']);
        if ($location) {
            $initBucket($location);
            $clone = $data['referrerIndividual'];
            $clone['sourceProject'] = $sourceProjectName;
            $clone['sourceCategory'] = $sourceCategory;
            $locationBuckets[$location]['referrers'][] = $clone;
        }
    }
    
    // Process referrer agency
    if (!empty($data['referrerAgency']) && !empty($data['referrerAgency']['state'])) {
        $location = normalizeStateName($data['referrerAgency']['state']);
        if ($location) {
            $initBucket($location);
            $clone = $data['referrerAgency'];
            $clone['sourceProject'] = $sourceProjectName;
            $clone['sourceCategory'] = $sourceCategory;
            // Store agency separately if needed
            if (!isset($locationBuckets[$location]['agencies'])) {
                $locationBuckets[$location]['agencies'] = [];
            }
            $locationBuckets[$location]['agencies'][] = $clone;
        }
    }
    
    // Now update each affected location's project
    foreach ($locationBuckets as $locationName => $bucket) {
        try {
            // First, load existing location project (if any)
            $existingProject = null;
            $stmt = $pdo->prepare("SELECT json_data FROM facilities_master WHERE unique_name = :locationName");
            $stmt->execute([':locationName' => $locationName]);
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
                'name' => $locationName,
                'data' => $locationData,
                'category' => 'locations',
                'currentFacilityIndex' => 0,
                'timestamp' => date('c')
            ];
            
            $jsonData = json_encode($locationProject);
            
            // Upsert the location project (use separate placeholders for INSERT and UPDATE)
            $sql = "INSERT INTO facilities_master (unique_name, json_data, updated_at)
                    VALUES (:unique_name, :json_data_insert, NOW())
                    ON DUPLICATE KEY UPDATE json_data = :json_data_update, updated_at = NOW()";
            
            $stmt = $pdo->prepare($sql);
            $stmt->bindValue(':unique_name', $locationName);
            $stmt->bindValue(':json_data_insert', $jsonData);
            $stmt->bindValue(':json_data_update', $jsonData);
            $stmt->execute();
            
            $updatedLocations[] = $locationName;
            
        } catch (PDOException $e) {
            // Log the error but continue with other locations
            error_log("Failed to update location project '$locationName': " . $e->getMessage());
        }
    }
    
    return $updatedLocations;
}

/**
 * Remove all entries from location projects that came from a specific source project
 * Called when a company/referrer project is deleted
 */
function removeFromLocationProjects($pdo, $sourceProjectName) {
    global $US_STATE_NAMES, $COUNTRY_NAMES;
    
    $cleanedLocations = [];
    
    try {
        // Get all location projects (category = 'locations')
        // This will find both state and country location projects
        $stmt = $pdo->prepare("SELECT unique_name, json_data FROM facilities_master WHERE json_data LIKE '%\"category\":\"locations\"%'");
        $stmt->execute();
        $locationProjects = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($locationProjects as $row) {
            $locationName = $row['unique_name'];
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
                
                $updateStmt = $pdo->prepare("UPDATE facilities_master SET json_data = :json_data, updated_at = NOW() WHERE unique_name = :locationName");
                $updateStmt->execute([
                    ':json_data' => json_encode($projectJson),
                    ':locationName' => $locationName
                ]);
                
                $cleanedLocations[] = $locationName;
            }
        }
    } catch (PDOException $e) {
        error_log("Failed to clean location projects after deleting '$sourceProjectName': " . $e->getMessage());
    }
    
    return $cleanedLocations;
}


// Handle action
if ($action === 'delete') {
    try {
        // Check if this is a location project (state or country name)
        global $US_STATE_NAMES, $COUNTRY_NAMES;
        $allLocationNames = array_merge($US_STATE_NAMES, $COUNTRY_NAMES);
        $isLocationProject = in_array(strtoupper($projectName), $allLocationNames);
        
        if ($isLocationProject) {
            // For location projects, check if it has any data before blocking deletion
            // Try both the original case and uppercase (old projects may be lowercase)
            $stmt = $pdo->prepare("SELECT json_data, unique_name FROM facilities_master WHERE UPPER(unique_name) = :projectName");
            $stmt->execute([':projectName' => strtoupper($projectName)]);
            $row = $stmt->fetch();
            
            if ($row) {
                $projectData = json_decode($row['json_data'], true);
                $data = $projectData['data'] ?? $projectData;
                $facilityCount = count($data['facilities'] ?? []);
                $referrerCount = count($data['referrerConsultants'] ?? []);
                
                // If the location project has data, don't allow deletion
                if ($facilityCount > 0 || $referrerCount > 0) {
                    echo json_encode([
                        'success' => false,
                        'error' => "Cannot delete location project '$projectName'. It contains $facilityCount facilities and $referrerCount referrers. Location projects are auto-managed - data will be removed when source projects are deleted."
                    ]);
                    exit;
                }
                // If empty, allow deletion to proceed - use the actual stored name
                $projectName = $row['unique_name'];
            }
            // If not found, allow deletion attempt (will report not found below)
        }
        
        // Try deleting from both tables (referrers and facilities)
        $deletedFromFacilities = false;
        $deletedFromReferrers = false;

        // Use the project name (may have been updated to actual stored name for location projects)
        $stmt = $pdo->prepare("DELETE FROM facilities_master WHERE unique_name = :projectName");
        $stmt->execute([':projectName' => $projectName]);
        $deletedFromFacilities = $stmt->rowCount() > 0;

        $stmt = $pdo->prepare("DELETE FROM referrers_master WHERE unique_name = :projectName");
        $stmt->execute([':projectName' => $projectName]);
        $deletedFromReferrers = $stmt->rowCount() > 0;

        if ($deletedFromFacilities || $deletedFromReferrers) {
            $fromTable = $deletedFromReferrers ? 'referrers_master' : 'facilities_master';
            
            // For non-location projects, also remove this project's entries from all location projects
            $cleanedLocations = [];
            if (!$isLocationProject) {
                $cleanedLocations = removeFromLocationProjects($pdo, $projectName);
            }
            
            $message = $isLocationProject 
                ? "Empty location project '$projectName' deleted"
                : "Project '$projectName' deleted from {$fromTable}";
            if (!empty($cleanedLocations)) {
                $message .= ". Removed from location projects: " . implode(', ', $cleanedLocations);
            }
            
            echo json_encode([
                'success' => true,
                'message' => $message,
                'locationProjectsCleaned' => $cleanedLocations,
                'wasLocationProject' => $isLocationProject
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
    // Use separate placeholders for INSERT and UPDATE values
    $sql = "INSERT INTO {$tableName} (unique_name, json_data, updated_at)
            VALUES (:unique_name, :json_data_insert, NOW())
            ON DUPLICATE KEY UPDATE json_data = :json_data_update, updated_at = NOW()";

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':unique_name', $projectName);
        $stmt->bindValue(':json_data_insert', $jsonData);
        $stmt->bindValue(':json_data_update', $jsonData);

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

// Handle cleanup-locations action - deletes all empty location projects
if ($action === 'cleanup-locations') {
    try {
        global $US_STATE_NAMES, $COUNTRY_NAMES;
        $allLocationNames = array_merge($US_STATE_NAMES, $COUNTRY_NAMES);
        
        $deleted = [];
        $skipped = [];
        $errors = [];
        
        // Find all projects that match location names (case-insensitive)
        $placeholders = implode(',', array_fill(0, count($allLocationNames), '?'));
        $stmt = $pdo->prepare("SELECT unique_name, json_data FROM facilities_master WHERE UPPER(unique_name) IN ($placeholders)");
        $stmt->execute($allLocationNames);
        $locationProjects = $stmt->fetchAll();
        
        foreach ($locationProjects as $row) {
            $projectName = $row['unique_name'];
            $projectData = json_decode($row['json_data'], true);
            $data = $projectData['data'] ?? $projectData;
            $facilityCount = count($data['facilities'] ?? []);
            $referrerCount = count($data['referrerConsultants'] ?? []);
            
            // Only delete if empty
            if ($facilityCount === 0 && $referrerCount === 0) {
                try {
                    $deleteStmt = $pdo->prepare("DELETE FROM facilities_master WHERE unique_name = :projectName");
                    $deleteStmt->execute([':projectName' => $projectName]);
                    if ($deleteStmt->rowCount() > 0) {
                        $deleted[] = $projectName;
                    }
                } catch (PDOException $e) {
                    $errors[] = "Failed to delete '$projectName': " . $e->getMessage();
                }
            } else {
                $skipped[] = [
                    'name' => $projectName,
                    'facilities' => $facilityCount,
                    'referrers' => $referrerCount
                ];
            }
        }
        
        echo json_encode([
            'success' => true,
            'message' => "Deleted " . count($deleted) . " empty location projects, skipped " . count($skipped) . " with data",
            'deleted' => $deleted,
            'skipped' => $skipped,
            'errors' => $errors
        ]);
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'error' => 'Failed to cleanup location projects: ' . $e->getMessage()
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
            'details' => $result,
            'debug' => $result['debug'] ?? []
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
    global $US_STATE_NAMES, $COUNTRY_NAMES;
    
    $locationBuckets = [];
    $projectsProcessed = 0;
    $debugInfo = [
        'facilitiesScanned' => 0,
        'facilitiesWithLocation' => 0,
        'facilitiesWithoutLocation' => [],
        'sampleFacility' => null
    ];
    
    // Helper to initialize a bucket for a location (state or country)
    $initBucket = function($locationName) use (&$locationBuckets) {
        if (!isset($locationBuckets[$locationName])) {
            $locationBuckets[$locationName] = [
                'facilities' => [],
                'referrers' => []
            ];
        }
    };
    
    // Fetch all projects from facilities_master
    $stmt = $pdo->prepare("SELECT unique_name, json_data FROM facilities_master");
    $stmt->execute();
    $facilitiesResults = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Fetch all projects from referrers_master
    $stmt = $pdo->prepare("SELECT unique_name, json_data FROM referrers_master");
    $stmt->execute();
    $referrersResults = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Combine all known location names for skip detection
    $allLocationNames = array_merge($US_STATE_NAMES, $COUNTRY_NAMES);
    
    // Merge and process all projects
    $allProjects = array_merge($facilitiesResults, $referrersResults);
    
    foreach ($allProjects as $row) {
        $projectName = $row['unique_name'];
        
        // Skip location projects (state/country names) - we're rebuilding those
        if (in_array(strtoupper($projectName), $allLocationNames)) {
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
                $debugInfo['facilitiesScanned']++;
                
                // Capture first facility for debugging
                if ($debugInfo['sampleFacility'] === null) {
                    $debugInfo['sampleFacility'] = [
                        'projectName' => $projectName,
                        'hasLocation' => !empty($facility['location']),
                        'location' => $facility['location'] ?? null,
                        'hasLocationDetails' => !empty($facility['locationDetails']),
                        'locationDetails' => $facility['locationDetails'] ?? null,
                        'hasState' => !empty($facility['state']),
                        'state' => $facility['state'] ?? null,
                        'keys' => array_keys($facility)
                    ];
                }
                
                $location = extractFacilityState($facility);
                if ($location) {
                    $debugInfo['facilitiesWithLocation']++;
                    $initBucket($location);
                    $clone = $facility;
                    $clone['sourceProject'] = $projectName;
                    $clone['sourceCategory'] = $sourceCategory;
                    $locationBuckets[$location]['facilities'][] = $clone;
                } else {
                    // Track facilities without location for debugging
                    if (count($debugInfo['facilitiesWithoutLocation']) < 5) {
                        $debugInfo['facilitiesWithoutLocation'][] = [
                            'project' => $projectName,
                            'facilityName' => $facility['identification']['name'] ?? $facility['name'] ?? 'Unknown',
                            'location' => $facility['location'] ?? null,
                            'locationDetails' => $facility['locationDetails'] ?? null
                        ];
                    }
                }
            }
        }
        
        // Process referrer consultants
        if (!empty($data['referrerConsultants']) && is_array($data['referrerConsultants'])) {
            foreach ($data['referrerConsultants'] as $consultant) {
                $location = extractReferrerState($consultant);
                if ($location) {
                    $initBucket($location);
                    $clone = $consultant;
                    $clone['sourceProject'] = $projectName;
                    $clone['sourceCategory'] = $sourceCategory;
                    $locationBuckets[$location]['referrers'][] = $clone;
                }
            }
        }
        
        // Process individual referrer
        if (!empty($data['referrerIndividual']) && is_array($data['referrerIndividual'])) {
            $location = extractReferrerState($data['referrerIndividual']);
            if ($location) {
                $initBucket($location);
                $clone = $data['referrerIndividual'];
                $clone['sourceProject'] = $projectName;
                $clone['sourceCategory'] = $sourceCategory;
                $locationBuckets[$location]['referrers'][] = $clone;
            }
        }
    }
    
    // Now save all location projects
    $locationsUpdated = 0;
    $locationDetails = [];
    $saveErrors = [];
    
    // Debug: track bucket details
    $debugInfo['bucketNames'] = array_keys($locationBuckets);
    $debugInfo['bucketSummary'] = [];
    foreach ($locationBuckets as $name => $b) {
        $debugInfo['bucketSummary'][$name] = [
            'facilities' => count($b['facilities']),
            'referrers' => count($b['referrers'])
        ];
    }
    
    foreach ($locationBuckets as $locationName => $bucket) {
        $facilityCount = count($bucket['facilities']);
        $referrerCount = count($bucket['referrers']);
        
        // Only create/update if there's actual data
        if ($facilityCount > 0 || $referrerCount > 0) {
            $locationData = [
                'facilities' => $bucket['facilities'],
                'referrerConsultants' => $bucket['referrers'],
                'operator' => [
                    'name' => $locationName,
                    'type' => 'Location Aggregate'
                ]
            ];
            
            $locationProject = [
                'name' => $locationName,
                'data' => $locationData,
                'category' => 'locations',
                'currentFacilityIndex' => 0,
                'timestamp' => date('c')
            ];
            
            $jsonData = json_encode($locationProject);
            
            // Debug: log first location project being created
            if (!isset($debugInfo['sampleLocationProject'])) {
                $debugInfo['sampleLocationProject'] = [
                    'locationName' => $locationName,
                    'facilities' => $facilityCount,
                    'referrers' => $referrerCount,
                    'jsonLength' => strlen($jsonData),
                    'jsonSample' => substr($jsonData, 0, 500)
                ];
            }
            
            try {
                // Use separate placeholders for INSERT and UPDATE to avoid PDO binding issues
                $sql = "INSERT INTO facilities_master (unique_name, json_data, updated_at)
                        VALUES (:unique_name, :json_data_insert, NOW())
                        ON DUPLICATE KEY UPDATE json_data = :json_data_update, updated_at = NOW()";
                
                $stmt = $pdo->prepare($sql);
                $stmt->bindValue(':unique_name', $locationName);
                $stmt->bindValue(':json_data_insert', $jsonData);
                $stmt->bindValue(':json_data_update', $jsonData);
                
                // Debug: track execution attempt
                $debugInfo['lastAttemptedLocation'] = $locationName;
                
                $result = $stmt->execute();
                $rowCount = $stmt->rowCount();
                
                // Debug: record result
                if (!isset($debugInfo['saveAttempts'])) {
                    $debugInfo['saveAttempts'] = [];
                }
                $debugInfo['saveAttempts'][] = [
                    'location' => $locationName,
                    'result' => $result,
                    'rowCount' => $rowCount
                ];
                
                if ($result) {
                    $locationsUpdated++;
                    $locationDetails[$locationName] = [
                        'facilities' => $facilityCount,
                        'referrers' => $referrerCount,
                        'rowCount' => $rowCount
                    ];
                } else {
                    $saveErrors[] = "Failed to save $locationName: execute returned false";
                }
            } catch (PDOException $e) {
                $saveErrors[] = "Error saving $locationName: " . $e->getMessage();
                $debugInfo['lastPDOError'] = [
                    'location' => $locationName,
                    'message' => $e->getMessage(),
                    'code' => $e->getCode()
                ];
            }
        }
    }
    
    return [
        'projectsProcessed' => $projectsProcessed,
        'statesUpdated' => $locationsUpdated,
        'stateDetails' => $locationDetails,
        'debug' => $debugInfo,
        'bucketCount' => count($locationBuckets),
        'saveErrors' => $saveErrors
    ];
}

// Unknown action
echo json_encode([
    'success' => false,
    'error' => "Unknown action: $action"
]);
