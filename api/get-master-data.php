<?php
/**
 * Master Data API - Robust Version
 * Optimized for Referrers and reliably returns all tables.
 */

ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/config.php';

header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

try {
    // Get WordPress table prefix from wp-config.php
    if (!defined('ABSPATH')) {
        define('ABSPATH', dirname(dirname(dirname(dirname(__DIR__)))) . '/');
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
    
    $projects = [];

    /**
     * Internal helper to fetch and process rows from a table
     */
    $processTable = function($tableName, $sourceLabel) use ($pdo, &$projects) {
        try {
            $stmt = $pdo->query("SELECT * FROM `$tableName` LIMIT 1000");
            if (!$stmt) return;
            
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $uniqueName = $row['unique_name'] ?? $row['slug'] ?? 'unknown_' . ($row['id'] ?? uniqid());
                
                // Extract JSON payload
                $rawJson = $row['json_data'] ?? $row['data'] ?? $row['json'] ?? $row['project_data'] ?? null;
                if (!$rawJson) continue;
                
                $data = json_decode($rawJson, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    error_log("JSON Parse Error for $uniqueName: " . json_last_error_msg());
                    continue;
                }

                // Standardize the project object (normalize old-format records)
                $has_data_wrapper = isset($data['data']) && is_array($data['data']);
                if ($has_data_wrapper) {
                    $project = $data;
                } else {
                    // Old format stores operator/facilities at the root.
                    // Wrap it so consumers can rely on project.data.
                    $project = array(
                        'data' => $data,
                    );

                    if (isset($data['name']) && is_string($data['name']) && $data['name'] !== '') {
                        $project['name'] = $data['name'];
                    }
                    if (isset($data['category']) && is_string($data['category']) && $data['category'] !== '') {
                        $project['category'] = $data['category'];
                    }
                }

                // Ensure name and category are set
                $project['name'] = $project['name'] ?? $uniqueName;
                $default_category = null;
                if ($sourceLabel === 'referrers') {
                    $default_category = 'referrers';
                } elseif ($sourceLabel === 'locations') {
                    $default_category = 'locations';
                } elseif ($sourceLabel === 'facilities') {
                    $default_category = 'companies';
                } elseif ($sourceLabel === 'wiki') {
                    $default_category = 'companies';
                }
                if ($default_category && (!isset($project['category']) || !is_string($project['category']) || $project['category'] === '')) {
                    $project['category'] = $default_category;
                }
                
                // Add source metadata
                $project['_sourceTable'] = $sourceLabel;
                $project['_dbId'] = $row['id'] ?? null;
                $project['_uniqueName'] = $uniqueName;

                // Use a unique key to prevent any overwriting
                $projects[$sourceLabel . '_' . $uniqueName] = $project;
            }
        } catch (Exception $e) {
            // Silently skip if table doesn't exist
        }
    };

    // 1. Process Referrers (High Priority)
    $processTable('referrers_master', 'referrers');
    if (!empty($prefix)) {
        $processTable($prefix . 'referrers_master', 'referrers');
    }

    // 2. Process Facilities
    $processTable('facilities_master', 'facilities');
    if (!empty($prefix)) {
        $processTable($prefix . 'facilities_master', 'facilities');
    }

    // 3. Process Locations
    $processTable('locations_master', 'locations');
    if (!empty($prefix)) {
        $processTable($prefix . 'locations_master', 'locations');
    }

    // 4. Process Wiki Master
    $processTable('wiki_master', 'wiki');

    // 5. Add Wiki Submissions (Published)
    try {
        $stmt = $pdo->query("SELECT * FROM wiki_submissions WHERE status = 'published'");
        if ($stmt) {
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $data = json_decode($row['json_data'], true);
                if (!$data) continue;
                
                $projects['wiki_sub_' . $row['id']] = [
                    'name' => $data['programName'] ?? $row['program_name'],
                    'category' => 'companies',
                    'data' => $data,
                    '_sourceTable' => 'wiki_submission',
                    '_dbId' => $row['id']
                ];
            }
        }
    } catch (Exception $e) {}

    echo json_encode([
        'success' => true, 
        'projects' => $projects,
        'count' => count($projects)
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false, 
        'error' => 'Server Error: ' . $e->getMessage()
    ]);
}
