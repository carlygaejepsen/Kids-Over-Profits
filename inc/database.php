<?php
/**
 * Database connection and data retrieval functions.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Locate available facility projects export datasets.
 *
 * @return string[] Sanitized URLs to the available datasets ordered by priority.
 */
function kop_get_facility_projects_dataset_urls() {
    $data_directory = get_stylesheet_directory() . '/js/data/';
    $data_directory_uri = get_stylesheet_directory_uri() . '/js/data/';

    if (!is_dir($data_directory)) {
        return array();
    }

    $dataset_candidates = glob($data_directory . 'facility-projects-export*.json');

    if (empty($dataset_candidates)) {
        return array();
    }

    usort($dataset_candidates, function ($a, $b) {
        return filemtime($b) <=> filemtime($a);
    });

    return array_map(function ($candidate) use ($data_directory_uri) {
        return esc_url($data_directory_uri . basename($candidate));
    }, $dataset_candidates);
}

/**
 * Build a database connection that can access the facilities table.
 *
 * This helper keeps the facilities loader tied to the WordPress database connection by default
 * while still allowing developers to filter the connection details when needed.
 *
 * @return array|WP_Error Array containing the connection (`db`) and table prefix (`prefix`)
 *                        on success, or WP_Error on failure.
 */
function kop_get_facilities_database_connection() {
    global $wpdb;

    if (!isset($wpdb)) {
        return new WP_Error('kop_facilities_wpdb_missing', __('Database connection is not available.', 'kadence-child'));
    }

    // Flywheel Local: Simplified to always use the default WordPress database.
    // The original code attempted to connect to a separate database, which is complex to manage
    // across different environments. Using the main WP database is more portable.
    $connection = array(
        'db'     => $wpdb,
        'prefix' => $wpdb->prefix,
    );

    /**
     * Filter the facilities database connection details.
     *
     * Returning a different wpdb instance or prefix allows the facilities loader to target
     * an alternate database/table structure without editing this helper directly.
     *
     * @since 1.0.0
     *
     * @param array $connection {
     *     Connection details used by the facilities loader.
     *
     *     @type wpdb   $db     WordPress database connection instance.
     *     @type string $prefix Table prefix that should be tried when looking for the facilities table.
     * }
     */
    $connection = apply_filters('kop_facilities_database_connection', $connection);

    if (!is_array($connection) || !isset($connection['db']) || !($connection['db'] instanceof wpdb)) {
        return new WP_Error('kop_facilities_invalid_connection', __('Facilities database connection is invalid.', 'kadence-child'));
    }

    $prefix = '';

    if (isset($connection['prefix'])) {
        $prefix = is_string($connection['prefix']) ? $connection['prefix'] : '';
    }

    return array(
        'db'     => $connection['db'],
        'prefix' => $prefix,
    );
}

/**
 * Discover the facilities master table that stores JSON exports.
 *
 * @param wpdb  $connection Database connection that should be inspected.
 * @param string $prefix    Optional table prefix to try during discovery.
 *
 * @return string|null Fully qualified table name when found, otherwise null.
 */
function kop_discover_facilities_master_table($connection, $prefix = '') {
    if (!($connection instanceof wpdb)) {
        return null;
    }

    $candidate_tables = array(
        'facilities_master',
        'kop_facilities_master',
        'facility_projects',
        'kop_facility_projects',
        'facility_json_exports',
        'kop_facility_json_exports',
    );

    $prefixes_to_try = array('');

    if (is_string($prefix) && $prefix !== '') {
        $prefixes_to_try[] = $prefix;
    }

    if ($connection->prefix !== null && $connection->prefix !== '') {
        $prefixes_to_try[] = $connection->prefix;
    }

    foreach ($prefixes_to_try as $prefix_candidate) {
        foreach ($candidate_tables as $table) {
            $table_name = $prefix_candidate . $table;

            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Trusted table name from candidate list above.
            $table_exists = $connection->get_var($connection->prepare(
                'SHOW TABLES LIKE %s',
                $table_name
            ));

            if ($table_exists === $table_name) {
                return $table_name;
            }
        }
    }

    return null;
}

/**
 * Fetch all projects from the database (facilities, referrers, and locations).
 *
 * @return array|WP_Error Array of projects on success, WP_Error on failure.
 */
function kop_get_facilities_projects_from_database() {
    $connection = kop_get_facilities_database_connection();

    if (is_wp_error($connection)) {
        return $connection;
    }

    $db_connection = isset($connection['db']) ? $connection['db'] : null;
    $prefix = isset($connection['prefix']) ? $connection['prefix'] : '';

    if (!($db_connection instanceof wpdb)) {
        return new WP_Error('kop_facilities_invalid_connection', __('Facilities database connection is invalid.', 'kadence-child'));
    }

    // All three master tables to query
    $master_tables = array(
        'facilities_master',
        'referrers_master',
        'locations_master',
    );

    $projects = array();

    foreach ($master_tables as $table_base) {
        // Try table with and without prefix
        $table_name = null;
        $prefixes_to_try = array('');
        if (is_string($prefix) && $prefix !== '') {
            $prefixes_to_try[] = $prefix;
        }
        if ($db_connection->prefix !== null && $db_connection->prefix !== '') {
            $prefixes_to_try[] = $db_connection->prefix;
        }

        foreach ($prefixes_to_try as $prefix_candidate) {
            $candidate_table = $prefix_candidate . $table_base;
            $table_exists = $db_connection->get_var($db_connection->prepare(
                'SHOW TABLES LIKE %s',
                $candidate_table
            ));
            if ($table_exists === $candidate_table) {
                $table_name = $candidate_table;
                break;
            }
        }

        // Skip if table doesn't exist
        if ($table_name === null) {
            continue;
        }

        $available_columns = $db_connection->get_col(sprintf('SHOW COLUMNS FROM %s', $table_name));

        if (!is_array($available_columns)) {
            continue;
        }

        $select_column = function ($candidates) use ($available_columns) {
            foreach ($candidates as $candidate) {
                if (in_array($candidate, $available_columns, true)) {
                    return $candidate;
                }
            }
            return null;
        };

        $sanitize_identifier = function ($identifier) {
            if (!is_string($identifier)) {
                return '';
            }
            return preg_match('/^[A-Za-z0-9_]+$/', $identifier) === 1 ? $identifier : '';
        };

        $identifier_column = $select_column(array('unique_name', 'project_unique_name', 'slug', 'project_slug', 'project_name', 'name', 'id'));
        $json_column = $select_column(array('json_data', 'project_json', 'json', 'project_data', 'data'));
        $updated_column = $select_column(array('updated_at', 'modified_at', 'last_updated', 'timestamp', 'created_at'));

        if ($identifier_column === null || $json_column === null) {
            continue;
        }

        $identifier_column = $sanitize_identifier($identifier_column);
        $json_column = $sanitize_identifier($json_column);
        $updated_column = $updated_column !== null ? $sanitize_identifier($updated_column) : null;

        if ($identifier_column === '' || $json_column === '') {
            continue;
        }

        $select_parts = array();
        $select_parts[] = '`' . $identifier_column . '` AS project_identifier';
        $select_parts[] = '`' . $json_column . '` AS project_payload';

        if ($updated_column !== null && $updated_column !== '') {
            $select_parts[] = '`' . $updated_column . '` AS project_updated';
        }

        $select_sql = implode(', ', $select_parts);

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Trusted table name from discovery loop.
        $rows = $db_connection->get_results("SELECT {$select_sql} FROM {$table_name}", ARRAY_A);

        if (!is_array($rows)) {
            continue;
        }

        foreach ($rows as $row) {
            $unique_name_raw = isset($row['project_identifier']) ? $row['project_identifier'] : '';
            $unique_name = sanitize_text_field($unique_name_raw);

            if ($unique_name === '') {
                continue;
            }

            $payload_raw = isset($row['project_payload']) ? $row['project_payload'] : '';

            if (!is_string($payload_raw) || $payload_raw === '') {
                error_log(sprintf('KOP: empty JSON payload for project "%s" in %s', $unique_name, $table_name));
                continue;
            }

            $decoded = json_decode($payload_raw, true);

            if (!is_array($decoded)) {
                error_log(sprintf('KOP: invalid JSON for project "%s" in %s', $unique_name, $table_name));
                continue;
            }

            // Detect format: NEW format has data.facilities/data.operator, OLD format has facilities/operator at root
            $is_new_format = (isset($decoded['data']['facilities']) && is_array($decoded['data']['facilities'])) ||
                             (isset($decoded['data']['operator']) && is_array($decoded['data']['operator']));

            // Determine default category based on table
            $default_category = 'companies';
            if (strpos($table_base, 'referrers') !== false) {
                $default_category = 'referrers';
            } elseif (strpos($table_base, 'locations') !== false) {
                $default_category = 'locations';
            }

            if ($is_new_format) {
                // NEW format: data is already nested correctly, pass through as-is
                $projects[$unique_name] = array(
                    'name' => isset($decoded['name']) ? sanitize_text_field($decoded['name']) : $unique_name,
                    'label' => sanitize_text_field($unique_name_raw),
                    'data' => $decoded['data'],
                    'category' => isset($decoded['category']) ? sanitize_text_field($decoded['category']) : $default_category,
                    'timestamp' => isset($decoded['timestamp']) ? sanitize_text_field($decoded['timestamp']) : ($updated_column !== null && isset($row['project_updated']) ? sanitize_text_field($row['project_updated']) : current_time('mysql')),
                    'currentFacilityIndex' => isset($decoded['currentFacilityIndex']) ? intval($decoded['currentFacilityIndex']) : 0,
                );
            } else {
                // OLD format: facilities/operator at root, wrap in 'data'
                $projects[$unique_name] = array(
                    'name' => $unique_name,
                    'label' => sanitize_text_field($unique_name_raw),
                    'data' => $decoded,
                    'category' => isset($decoded['category']) ? sanitize_text_field($decoded['category']) : $default_category,
                    'timestamp' => isset($decoded['timestamp']) ? sanitize_text_field($decoded['timestamp']) : ($updated_column !== null && isset($row['project_updated']) ? sanitize_text_field($row['project_updated']) : current_time('mysql')),
                    'currentFacilityIndex' => isset($decoded['currentFacilityIndex']) ? intval($decoded['currentFacilityIndex']) : 0,
                );
            }
        }
    }

    if (empty($projects)) {
        return new WP_Error('kop_no_projects_found', __('No projects found in any master table.', 'kadence-child'));
    }

    return array(
        'source' => 'database',
        'projects' => $projects,
    );
}

/**
 * Get FileBird folders for dropdown
 * @return array
 */
function kop_get_filebird_folders() {
    global $wpdb;
    
    // FileBird stores folders in its own table
    $table_name = $wpdb->prefix . 'fbv';
    
    // Check if table exists
    $table_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table_name));
    
    if (!$table_exists) {
        return array();
    }
    
    $folders = $wpdb->get_results("SELECT id, name, parent FROM $table_name ORDER BY name ASC", ARRAY_A);
    
    // Build hierarchy (simple flat list with indentation for now)
    // For a robust implementation, you'd want a recursive function to build the tree
    // But for a dropdown, a flat sorted list is often sufficient if names are unique
    
    return $folders;
}

/**
 * Get attachment IDs for a specific FileBird folder
 * @param int $folder_id
 * @return array
 */
function kop_get_folder_attachments($folder_id) {
    global $wpdb;
    
    $table_name = $wpdb->prefix . 'fbv_attachment_folder';
    
    // Check if table exists
    $table_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table_name));
    
    if (!$table_exists) {
        return array();
    }
    
    $attachment_ids = $wpdb->get_col($wpdb->prepare(
        "SELECT attachment_id FROM $table_name WHERE folder_id = %d",
        $folder_id
    ));
    
    return $attachment_ids;
}
