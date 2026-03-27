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

// Prefer the WordPress table prefix when available, but fall back to unprefixed tables.
$prefix = '';
if (isset($table_prefix) && is_string($table_prefix)) {
    $prefix = $table_prefix;
}

if (!function_exists('kop_resolve_table_name')) {
    function kop_resolve_table_name(PDO $pdo, $base, $prefix = '') {
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
}

$facilities_table = kop_resolve_table_name($pdo, 'facilities_master', $prefix);
$referrers_table = kop_resolve_table_name($pdo, 'referrers_master', $prefix);
$locations_table = kop_resolve_table_name($pdo, 'locations_master', $prefix);
$wiki_table = kop_resolve_table_name($pdo, 'wiki_master', $prefix);

// Set defaults for all expected variables to prevent undefined errors
// These are defined AFTER config.php to prevent WordPress from overwriting them
$action = isset($request['action']) ? $request['action'] : 'save';
$projectName = isset($request['projectName']) ? $request['projectName'] : null;
$newProjectName = isset($request['newProjectName']) ? $request['newProjectName'] : null;
$data = isset($request['data']) ? $request['data'] : null;
$requestedCategory = isset($request['category']) ? $request['category'] : 'companies';
$currentFacilityIndex = isset($request['currentFacilityIndex']) ? intval($request['currentFacilityIndex']) : 0;
$timestamp = isset($request['timestamp']) ? $request['timestamp'] : date('c');

// Normalize incoming payloads that may include a full project wrapper or double-wrapped data.
if (is_array($data) && isset($data['data']) && is_array($data['data'])) {
    // If a full project object was passed inside `data`, unwrap it.
    if (empty($projectName) && !empty($data['name']) && is_string($data['name'])) {
        $projectName = $data['name'];
    }
    if ((!isset($request['category']) || $request['category'] === '') && !empty($data['category']) && is_string($data['category'])) {
        $requestedCategory = $data['category'];
    }
    if ($currentFacilityIndex === 0 && isset($data['currentFacilityIndex'])) {
        $currentFacilityIndex = intval($data['currentFacilityIndex']);
    }
    if ((!isset($request['timestamp']) || $request['timestamp'] === '') && !empty($data['timestamp']) && is_string($data['timestamp'])) {
        $timestamp = $data['timestamp'];
    }
    $data = $data['data'];
}

$unwrap_guard = 0;
while (is_array($data)
    && !isset($data['operator'])
    && !isset($data['facilities'])
    && isset($data['data'])
    && is_array($data['data'])
    && $unwrap_guard < 2
) {
    $data = $data['data'];
    $unwrap_guard += 1;
}

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

// SERVER-SIDE CATEGORY VALIDATION: Override category for state/country names
// This ensures location projects ALWAYS go to locations_master, regardless of what frontend sends
$category = $requestedCategory; // Start with requested category
if ($projectName) {
    $projectNameUpper = strtoupper(trim($projectName));
    if (in_array($projectNameUpper, $US_STATE_NAMES) || in_array($projectNameUpper, $COUNTRY_NAMES)) {
        $category = 'locations';
        $projectName = $projectNameUpper;
        // Log if frontend sent wrong category (helps debug issues)
        if ($requestedCategory !== 'locations') {
            error_log("Category override: '$projectName' is a state/country name - forcing category from '$requestedCategory' to 'locations'");
        }
    }
}

// If the payload looks like an operator project, force it to companies unless it's a location.
if ($category !== 'locations' && is_array($data)) {
    $has_operator_data = isset($data['operator']) || isset($data['facilities']);
    if ($has_operator_data && $category !== 'companies' && $category !== 'company') {
        $category = 'companies';
    }
}

// Validate project name (skip for rebuild-locations which processes all projects)
if (!$projectName && $action !== 'rebuild-locations') {
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
    
    // Get the source project's operator info to attach to cloned facilities
    $sourceOperator = isset($data['operator']) ? $data['operator'] : null;
    
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
                // Include source operator info so facility knows its parent company
                if ($sourceOperator) {
                    $clone['sourceOperator'] = $sourceOperator;
                    // Also set identification.currentOperator if not already set
                    if (!isset($clone['identification'])) {
                        $clone['identification'] = [];
                    }
                    if (empty($clone['identification']['currentOperator']) && !empty($sourceOperator['name'])) {
                        $clone['identification']['currentOperator'] = $sourceOperator['name'];
                    }
                }
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
            // First, load existing location project from locations_master (if any)
            $existingProject = null;
            $stmt = $pdo->prepare("SELECT json_data FROM locations_master WHERE unique_name = :locationName");
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
            
            // Upsert the location project to locations_master table
            $sql = "INSERT INTO locations_master (unique_name, json_data, updated_at)
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
        // Get all location projects from the locations_master table
        $stmt = $pdo->prepare("SELECT unique_name, json_data FROM locations_master");
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
                
                $updateStmt = $pdo->prepare("UPDATE locations_master SET json_data = :json_data, updated_at = NOW() WHERE unique_name = :locationName");
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
            // For location projects, check the locations_master table
            $stmt = $pdo->prepare("SELECT json_data, unique_name FROM locations_master WHERE UPPER(unique_name) = :projectName");
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
                // If empty, allow deletion from locations_master
                $deleteStmt = $pdo->prepare("DELETE FROM locations_master WHERE unique_name = :projectName");
                $deleteStmt->execute([':projectName' => $row['unique_name']]);
                
                echo json_encode([
                    'success' => true,
                    'message' => "Empty location project '{$row['unique_name']}' deleted from locations_master",
                    'wasLocationProject' => true
                ]);
                exit;
            }
            // If not found in locations_master, check facilities_master for legacy entries
        }
        
        // Try deleting from all three tables (facilities, referrers, and locations)
        $deletedFromFacilities = false;
        $deletedFromReferrers = false;
        $deletedFromLocations = false;

        $stmt = $pdo->prepare("DELETE FROM facilities_master WHERE unique_name = :projectName");
        $stmt->execute([':projectName' => $projectName]);
        $deletedFromFacilities = $stmt->rowCount() > 0;

        $stmt = $pdo->prepare("DELETE FROM referrers_master WHERE unique_name = :projectName");
        $stmt->execute([':projectName' => $projectName]);
        $deletedFromReferrers = $stmt->rowCount() > 0;

        $stmt = $pdo->prepare("DELETE FROM locations_master WHERE unique_name = :projectName");
        $stmt->execute([':projectName' => $projectName]);
        $deletedFromLocations = $stmt->rowCount() > 0;

        if ($deletedFromFacilities || $deletedFromReferrers || $deletedFromLocations) {
            $fromTable = $deletedFromReferrers ? 'referrers_master' : ($deletedFromLocations ? 'locations_master' : 'facilities_master');
            
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

    if ($category === 'locations') {
        $projectStructure['name'] = strtoupper(trim((string) $projectName));
    }

    // Encode the complete project structure as a JSON string
    $jsonData = json_encode($projectStructure);

    // Determine which table to use based on category
    if ($category === 'locations') {
        $tableName = $locations_table;
    } elseif ($category === 'referrers') {
        $tableName = $referrers_table;
    } elseif ($category === 'wiki') {
        $tableName = $wiki_table;
    } else {
        $tableName = $facilities_table;
    }

    try {
        if ($category === 'wiki') {
            // Specialized logic for wiki_master
            $wikiData = is_string($data) ? json_decode($data, true) : $data;
            $slug = $wikiData['slug'] ?? strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $projectName)));
            
            $sql = "INSERT INTO wiki_master 
                        (slug, program_name, city_state, organization, program_type, years_active, json_data, markdown, last_updated_by, updated_at)
                    VALUES (:slug, :program_name, :city_state, :organization, :program_type, :years_active, :json_data, :markdown, :last_updated_by, NOW())
                    ON DUPLICATE KEY UPDATE 
                        program_name = VALUES(program_name),
                        city_state = VALUES(city_state),
                        organization = VALUES(organization),
                        program_type = VALUES(program_type),
                        years_active = VALUES(years_active),
                        json_data = VALUES(json_data),
                        markdown = VALUES(markdown),
                        last_updated_by = VALUES(last_updated_by),
                        updated_at = NOW()";
            
            $stmt = $pdo->prepare($sql);
            $stmt->bindValue(':slug', $slug);
            $stmt->bindValue(':program_name', $projectName);
            $stmt->bindValue(':city_state', $wikiData['cityState'] ?? null);
            $stmt->bindValue(':organization', $wikiData['ownerName'] ?? $wikiData['organization'] ?? null);
            $stmt->bindValue(':program_type', $wikiData['programType'] ?? null);
            $stmt->bindValue(':years_active', $wikiData['yearsActive'] ?? null);
            $stmt->bindValue(':json_data', json_encode($wikiData));
            $stmt->bindValue(':markdown', $wikiData['markdown'] ?? null);
            $stmt->bindValue(':last_updated_by', 'admin');
            $stmt->execute();
        } else {
            // Standard logic for facilities, referrers, locations
            $sql = "INSERT INTO {$tableName} (unique_name, json_data, updated_at)
                    VALUES (:unique_name, :json_data_insert, NOW())
                    ON DUPLICATE KEY UPDATE json_data = :json_data_update, updated_at = NOW()";

            $stmt = $pdo->prepare($sql);
            $stmt->bindValue(':unique_name', $projectName);
            $stmt->bindValue(':json_data_insert', $jsonData);
            $stmt->bindValue(':json_data_update', $jsonData);
            $stmt->execute();
        }
        
        // Auto-duplicate to location projects if this is a companies/referrers project
        $locationUpdates = [];
        if ($category === 'companies' || $category === 'referrers') {
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

// Handle inspect-project action - returns raw database contents for debugging
if ($action === 'inspect-project') {
    try {
        if (!$projectName) {
            echo json_encode(['success' => false, 'error' => 'Project name required']);
            exit;
        }
        
        // Try case-insensitive match
        $stmt = $pdo->prepare("SELECT unique_name, json_data, updated_at FROM facilities_master WHERE UPPER(unique_name) = UPPER(:projectName)");
        $stmt->execute([':projectName' => $projectName]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$row) {
            // Try referrers table
            $stmt = $pdo->prepare("SELECT unique_name, json_data, updated_at FROM referrers_master WHERE UPPER(unique_name) = UPPER(:projectName)");
            $stmt->execute([':projectName' => $projectName]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
        }
        
        if ($row) {
            $jsonData = json_decode($row['json_data'], true);
            echo json_encode([
                'success' => true,
                'storedName' => $row['unique_name'],
                'updatedAt' => $row['updated_at'],
                'jsonDataKeys' => $jsonData ? array_keys($jsonData) : null,
                'hasDataKey' => isset($jsonData['data']),
                'dataKeys' => isset($jsonData['data']) ? array_keys($jsonData['data']) : null,
                'facilitiesCount' => isset($jsonData['data']['facilities']) ? count($jsonData['data']['facilities']) : (isset($jsonData['facilities']) ? count($jsonData['facilities']) : 0),
                'referrersCount' => isset($jsonData['data']['referrerConsultants']) ? count($jsonData['data']['referrerConsultants']) : 0,
                'category' => $jsonData['category'] ?? 'unknown',
                'rawJson' => $jsonData
            ]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Project not found']);
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// Handle cleanup-locations action - deletes all empty location projects (or all lowercase ones with force=true)
if ($action === 'cleanup-locations') {
    try {
        global $US_STATE_NAMES, $COUNTRY_NAMES;
        $allLocationNames = array_merge($US_STATE_NAMES, $COUNTRY_NAMES);
        
        // Check if force mode is requested (delete ALL lowercase location projects)
        // Handle boolean true, string 'true', and integer 1 from AJAX
        $forceMode = isset($request['force']) && ($request['force'] === true || $request['force'] === 'true' || $request['force'] == 1);
        
        // Code version for deployment verification
        $codeVersion = '2025-11-26-v3-locations-table';
        
        $deleted = [];
        $deletedFromFacilities = [];
        $skipped = [];
        $errors = [];
        $found = [];
        
        // Query the locations_master table (the proper place for location projects)
        $stmt = $pdo->prepare("SELECT unique_name, json_data FROM locations_master");
        $stmt->execute();
        $locationProjects = $stmt->fetchAll();
        
        foreach ($locationProjects as $row) {
            $projectName = $row['unique_name'];
            $upperName = strtoupper($projectName);
            
            $found[] = $projectName;
            
            $isLowercase = $projectName !== $upperName; // Has any lowercase letters
            
            $projectData = json_decode($row['json_data'], true);
            $data = $projectData['data'] ?? $projectData;
            $facilityCount = count($data['facilities'] ?? []);
            $referrerCount = count($data['referrerConsultants'] ?? []);
            
            // Delete if: empty OR (force mode AND not all uppercase)
            $shouldDelete = ($facilityCount === 0 && $referrerCount === 0) || ($forceMode && $isLowercase);
            
            if ($shouldDelete) {
                try {
                    $deleteStmt = $pdo->prepare("DELETE FROM locations_master WHERE unique_name = :projectName");
                    $deleteStmt->execute([':projectName' => $projectName]);
                    if ($deleteStmt->rowCount() > 0) {
                        $deleted[] = $projectName;
                    }
                } catch (PDOException $e) {
                    $errors[] = "Failed to delete '$projectName' from locations_master: " . $e->getMessage();
                }
            } else {
                $skipped[] = [
                    'name' => $projectName,
                    'facilities' => $facilityCount,
                    'referrers' => $referrerCount,
                    'isLowercase' => $isLowercase
                ];
            }
        }
        
        // Also clean up any stale location projects that were incorrectly stored in facilities_master
        $staleFromFacilities = [];
        $stmtFac = $pdo->prepare("SELECT unique_name FROM facilities_master WHERE UPPER(unique_name) IN (" . implode(',', array_fill(0, count($allLocationNames), '?')) . ")");
        $stmtFac->execute($allLocationNames);
        $staleLocations = $stmtFac->fetchAll(PDO::FETCH_COLUMN);
        
        foreach ($staleLocations as $staleName) {
            try {
                $deleteStale = $pdo->prepare("DELETE FROM facilities_master WHERE unique_name = :name");
                $deleteStale->execute([':name' => $staleName]);
                if ($deleteStale->rowCount() > 0) {
                    $staleFromFacilities[] = $staleName;
                }
            } catch (PDOException $e) {
                $errors[] = "Failed to clean stale '$staleName' from facilities_master: " . $e->getMessage();
            }
        }
        
        echo json_encode([
            'success' => true,
            'message' => "Deleted " . count($deleted) . " from locations_master, cleaned " . count($staleFromFacilities) . " stale from facilities_master",
            'codeVersion' => $codeVersion,
            'forceMode' => $forceMode,
            'found' => $found,
            'deleted' => $deleted,
            'deletedFromFacilitiesMaster' => $staleFromFacilities,
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

// Handle merge-location-duplicates action - merges misnamed location projects into canonical location rows
if ($action === 'merge-location-duplicates') {
    try {
        $dryRun = !isset($request['dryRun']) ? true : filter_var($request['dryRun'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($dryRun === null) {
            $dryRun = true;
        }

        $result = mergeDuplicateLocationProjects($pdo, [
            'dryRun' => $dryRun,
            'facilitiesTable' => $facilities_table,
            'locationsTable' => $locations_table
        ]);

        $message = $dryRun
            ? "Previewed {$result['mergeCount']} duplicate location project merge(s)"
            : "Merged {$result['mergeCount']} duplicate location project(s) into {$result['targetCount']} canonical location project(s)";

        echo json_encode([
            'success' => true,
            'message' => $message,
            'dryRun' => $dryRun,
            'details' => $result
        ]);
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'error' => 'Failed to merge duplicate location projects: ' . $e->getMessage()
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
        
        // Get the source project's operator info to attach to cloned facilities
        $sourceOperator = isset($data['operator']) ? $data['operator'] : null;
        
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
                    // Include source operator info so facility knows its parent company
                    if ($sourceOperator) {
                        $clone['sourceOperator'] = $sourceOperator;
                        // Also set identification.currentOperator if not already set
                        if (!isset($clone['identification'])) {
                            $clone['identification'] = [];
                        }
                        if (empty($clone['identification']['currentOperator']) && !empty($sourceOperator['name'])) {
                            $clone['identification']['currentOperator'] = $sourceOperator['name'];
                        }
                    }
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
                // Save to locations_master table (separate from facilities_master)
                $sql = "INSERT INTO locations_master (unique_name, json_data, updated_at)
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

function kop_merge_location_facilities($existingFacilities, $newFacilities) {
    $existingFacilities = is_array($existingFacilities) ? array_values($existingFacilities) : [];
    $newFacilities = is_array($newFacilities) ? array_values($newFacilities) : [];
    $indexByKey = [];

    foreach ($existingFacilities as $index => $facility) {
        $key = kop_build_location_facility_key($facility);
        if ($key !== '') {
            $indexByKey[$key] = $index;
        }
    }

    foreach ($newFacilities as $facility) {
        $key = kop_build_location_facility_key($facility);
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

function kop_build_location_facility_key($facility) {
    if (!is_array($facility)) {
        return '';
    }

    $identification = isset($facility['identification']) && is_array($facility['identification'])
        ? $facility['identification']
        : [];
    $locationDetails = isset($facility['locationDetails']) && is_array($facility['locationDetails'])
        ? $facility['locationDetails']
        : [];

    $name = trim((string) ($identification['name'] ?? $facility['name'] ?? ''));
    $address = trim((string) ($facility['address'] ?? ''));
    $location = trim((string) ($facility['location'] ?? ''));
    $city = trim((string) ($locationDetails['city'] ?? $facility['locationCity'] ?? ''));
    $state = trim((string) ($locationDetails['state'] ?? $facility['locationState'] ?? ''));

    return strtolower($name . '|' . $address . '|' . $location . '|' . $city . '|' . $state);
}

function kop_build_location_referrer_key($referrer) {
    if (!is_array($referrer)) {
        return '';
    }

    $name = trim((string) ($referrer['name'] ?? $referrer['fullName'] ?? $referrer['consultantName'] ?? ''));
    $location = trim((string) ($referrer['location'] ?? ''));
    $city = trim((string) ($referrer['city'] ?? ''));
    $state = trim((string) ($referrer['state'] ?? ''));

    return strtolower($name . '|' . $location . '|' . $city . '|' . $state);
}

function kop_merge_location_referrers($existingReferrers, $newReferrers) {
    $existingReferrers = is_array($existingReferrers) ? array_values($existingReferrers) : [];
    $newReferrers = is_array($newReferrers) ? array_values($newReferrers) : [];
    $indexByKey = [];

    foreach ($existingReferrers as $index => $referrer) {
        $key = kop_build_location_referrer_key($referrer);
        if ($key !== '') {
            $indexByKey[$key] = $index;
        }
    }

    foreach ($newReferrers as $referrer) {
        $key = kop_build_location_referrer_key($referrer);
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

function kop_is_known_country_name($value) {
    global $COUNTRY_NAMES;

    if (!is_string($value) || trim($value) === '') {
        return null;
    }

    $normalized = strtoupper(trim($value));
    return in_array($normalized, $COUNTRY_NAMES, true) ? $normalized : null;
}

function kop_try_normalize_location_name($value) {
    if (!is_string($value) || trim($value) === '') {
        return null;
    }

    $state = normalizeStateName($value);
    if ($state) {
        return $state;
    }

    return kop_is_known_country_name($value);
}

function kop_find_location_name_in_text($value) {
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

function kop_format_location_project_name($locationName) {
    $formatted = ucwords(strtolower(trim((string) $locationName)));
    return preg_replace_callback('/\\b([A-Za-z])([A-Za-z]*)\\b/u', function($matches) {
        return strtoupper($matches[1]) . strtolower($matches[2]);
    }, $formatted);
}

function kop_get_project_payload_data($projectJson) {
    if (!is_array($projectJson)) {
        return [];
    }

    if (isset($projectJson['data']) && is_array($projectJson['data'])) {
        return $projectJson['data'];
    }

    return $projectJson;
}

function kop_create_empty_location_project($projectName) {
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

function kop_prepare_location_project_for_merge($projectJson, $projectName) {
    $projectJson = is_array($projectJson) ? $projectJson : [];
    $prepared = kop_create_empty_location_project($projectName);

    if (!empty($projectJson)) {
        $prepared = array_merge($prepared, $projectJson);
    }

    $prepared['name'] = $projectName;
    $prepared['category'] = 'locations';
    $prepared['currentFacilityIndex'] = isset($prepared['currentFacilityIndex']) ? intval($prepared['currentFacilityIndex']) : 0;
    $prepared['timestamp'] = date('c');

    $prepared['data'] = kop_get_project_payload_data($prepared);
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

function kop_count_project_locations($projectJson) {
    global $US_STATE_NAMES, $COUNTRY_NAMES;

    $knownLocations = array_fill_keys(array_merge($US_STATE_NAMES, $COUNTRY_NAMES), true);
    $counts = [];
    $data = kop_get_project_payload_data($projectJson);

    if (!empty($data['facilities']) && is_array($data['facilities'])) {
        foreach ($data['facilities'] as $facility) {
            $location = extractFacilityState($facility);
            if ($location && isset($knownLocations[$location])) {
                $counts[$location] = ($counts[$location] ?? 0) + 1;
            }
        }
    }

    if (!empty($data['referrerConsultants']) && is_array($data['referrerConsultants'])) {
        foreach ($data['referrerConsultants'] as $referrer) {
            $location = extractReferrerState($referrer);
            if ($location && isset($knownLocations[$location])) {
                $counts[$location] = ($counts[$location] ?? 0) + 1;
            }
        }
    }

    return $counts;
}

function kop_resolve_location_duplicate_target($projectName, $projectJson) {
    $directCandidates = [
        kop_try_normalize_location_name($projectName),
        kop_try_normalize_location_name($projectJson['name'] ?? '')
    ];

    foreach ($directCandidates as $candidate) {
        if ($candidate) {
            return $candidate;
        }
    }

    $embeddedCandidates = [
        kop_find_location_name_in_text($projectName),
        kop_find_location_name_in_text($projectJson['name'] ?? '')
    ];

    foreach ($embeddedCandidates as $candidate) {
        if ($candidate) {
            return $candidate;
        }
    }

    $data = kop_get_project_payload_data($projectJson);
    if (!empty($data['operator']) && is_array($data['operator'])) {
        foreach (['locationState', 'state', 'locationCountry', 'country'] as $fieldName) {
            $candidate = kop_try_normalize_location_name($data['operator'][$fieldName] ?? '');
            if ($candidate) {
                return $candidate;
            }
        }
    }

    $locationCounts = kop_count_project_locations($projectJson);
    if (count($locationCounts) === 1) {
        $locations = array_keys($locationCounts);
        return $locations[0];
    }

    return null;
}

function mergeDuplicateLocationProjects($pdo, $options = []) {
    $dryRun = !empty($options['dryRun']);
    $facilitiesTable = $options['facilitiesTable'] ?? 'facilities_master';
    $locationsTable = $options['locationsTable'] ?? 'locations_master';

    $canonicalTargets = [];
    $mergePlan = [];
    $skipped = [];
    $targetProjects = [];
    $targetNames = [];
    $sourcesToDelete = [];

    $rowsByTable = [];
    foreach ([$locationsTable, $facilitiesTable] as $tableName) {
        $stmt = $pdo->prepare("SELECT unique_name, json_data, updated_at FROM {$tableName} ORDER BY updated_at ASC, unique_name ASC");
        $stmt->execute();
        $rowsByTable[$tableName] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    foreach ($rowsByTable[$locationsTable] as $row) {
        $canonicalName = kop_try_normalize_location_name($row['unique_name']);
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
            if ($tableName !== $locationsTable && $category !== 'locations') {
                continue;
            }

            $targetLocation = kop_resolve_location_duplicate_target($row['unique_name'], $projectJson);
            if (!$targetLocation) {
                $skipped[] = [
                    'table' => $tableName,
                    'projectName' => $row['unique_name'],
                    'reason' => 'Could not resolve canonical state/country target'
                ];
                continue;
            }

            if (!isset($canonicalTargets[$targetLocation])) {
                $exactMatch = kop_try_normalize_location_name($row['unique_name']) === $targetLocation;
                $canonicalTargets[$targetLocation] = $exactMatch
                    ? $row['unique_name']
                    : kop_format_location_project_name($targetLocation);
            }

            $targetProjectName = $canonicalTargets[$targetLocation];
            $sourceCanonicalName = kop_try_normalize_location_name($row['unique_name']);
            $isCanonicalName = $sourceCanonicalName && $sourceCanonicalName === $targetLocation;
            $isCanonicalRow = ($tableName === $locationsTable && $isCanonicalName && strcasecmp($row['unique_name'], $targetProjectName) === 0);

            if ($isCanonicalRow) {
                if (!isset($targetProjects[$targetLocation])) {
                    $targetProjects[$targetLocation] = kop_prepare_location_project_for_merge($projectJson, $targetProjectName);
                    $targetNames[$targetLocation] = $targetProjectName;
                }
                continue;
            }

            if (!isset($targetProjects[$targetLocation])) {
                $targetProjects[$targetLocation] = kop_prepare_location_project_for_merge([], $targetProjectName);
                $targetNames[$targetLocation] = $targetProjectName;
            }

            $targetProjects[$targetLocation]['data']['facilities'] = kop_merge_location_facilities(
                $targetProjects[$targetLocation]['data']['facilities'],
                $projectJson['data']['facilities'] ?? $projectJson['facilities'] ?? []
            );
            $targetProjects[$targetLocation]['data']['referrerConsultants'] = kop_merge_location_referrers(
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
                'projectName' => $row['unique_name']
            ];
        }
    }

    if (!$dryRun && !empty($mergePlan)) {
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

            foreach ($sourcesToDelete as $source) {
                $stmt = $pdo->prepare("DELETE FROM {$source['table']} WHERE unique_name = :projectName");
                $stmt->execute([':projectName' => $source['projectName']]);
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
        'mergeCount' => count($mergePlan),
        'targetCount' => count(array_unique(array_column($mergePlan, 'targetProjectName'))),
        'targets' => array_values(array_unique(array_column($mergePlan, 'targetProjectName'))),
        'mergePlan' => $mergePlan,
        'skipped' => $skipped
    ];
}

// Unknown action
echo json_encode([
    'success' => false,
    'error' => "Unknown action: $action"
]);
