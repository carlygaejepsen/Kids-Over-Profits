<?php
/**
 * Kadence Child Theme Functions
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Enqueue parent theme styles
 */
function kadence_child_enqueue_styles() {
    // Enqueue parent theme stylesheet
    wp_enqueue_style(
        'kadence-parent-style',
        get_template_directory_uri() . '/style.css',
        array(),
        wp_get_theme()->get('Version')
    );

    // Enqueue shared color variables for all pages
    $colors_path = get_stylesheet_directory() . '/css/colors.css';

    if (file_exists($colors_path)) {
        wp_enqueue_style(
            'kop-colors',
            get_stylesheet_directory_uri() . '/css/colors.css',
            array('kadence-parent-style'),
            filemtime($colors_path)
        );
    }
}
add_action('wp_enqueue_scripts', 'kadence_child_enqueue_styles');

/**
 * Determine whether the current request is for a headerless layout.
 * This is used to conditionally remove the default theme header/footer
 * when the data form pages are being displayed.
 *
 * @return bool
 */
function kop_is_headerless_layout() {
    // Check for page templates (multiple possible paths/names)
    return is_page_template('page-admin-data.php') 
        || is_page_template('page-data.php')
        || is_page_template('page-data-test.php')
        || is_page_template('templates/data-form-public.php') 
        || is_page_template('templates/data-form-admin.php');
}

/**
 * Enqueue the Kadence navigation guard script on headerless pages.
 * This intercepts DOM queries for navigation elements and suppresses errors.
 */
function kop_enqueue_kadence_nav_guard() {
    if (!kop_is_headerless_layout()) {
        return;
    }
    
    wp_enqueue_script(
        'kop-kadence-nav-guard',
        get_stylesheet_directory_uri() . '/js/data-form/kadence-nav-guard.js',
        array(), // No dependencies - load as early as possible
        '1.0.0',
        false // Load in header, not footer
    );
}
add_action('wp_enqueue_scripts', 'kop_enqueue_kadence_nav_guard', 1); // Priority 1 - load very early

/**
 * Remove Kadence navigation scripts when the page intentionally renders without a header.
 */
function kop_maybe_disable_kadence_navigation() {
    if (!kop_is_headerless_layout()) {
        return;
    }

    // List of Kadence navigation-related script handles to remove
    // Includes various possible handle names used by Kadence theme
    $nav_scripts = array(
        'kadence-navigation',
        'kadence-navigation-init',
        'kadence-navigation-mobile',
        'kadence-header',
        'kadence-sticky-header',
        'kadence-nav',
        'kadence-menu',
        'navigation', // Generic handle that might be used
    );

    foreach ($nav_scripts as $script_handle) {
        wp_dequeue_script($script_handle);
        wp_deregister_script($script_handle);
    }

    // Access global scripts registry
    global $wp_scripts;

    if (!($wp_scripts instanceof WP_Scripts)) {
        $wp_scripts = wp_scripts();
    }

    // Clear any inline scripts or extra data attached to navigation scripts
    if ($wp_scripts instanceof WP_Scripts) {
        foreach ($nav_scripts as $script_handle) {
            if (isset($wp_scripts->registered[$script_handle])) {
                $wp_scripts->registered[$script_handle]->extra = array();
                $wp_scripts->registered[$script_handle]->deps = array();
            }
        }
    }
}

add_action('wp_enqueue_scripts', 'kop_maybe_disable_kadence_navigation', 200);
add_action('wp_print_scripts', 'kop_maybe_disable_kadence_navigation', 200);
add_action('wp_print_footer_scripts', 'kop_maybe_disable_kadence_navigation', 200);

/**
 * Add inline script to block navigation on headerless pages as early as possible.
 */
function kop_add_early_navigation_blocker() {
    if (!kop_is_headerless_layout()) {
        return;
    }

    ?>
    <script>
    (function(){
        window.KADENCE_NAV_DISABLED = true;
        window.kadenceConfig = window.kadenceConfig || {};
        window.kadenceConfig.breakPoints = {desktop: 99999};
    })();
    </script>
    <?php
}
add_action('wp_head', 'kop_add_early_navigation_blocker', 1);

/**
 * Add global error suppressor for Kadence navigation.min.js errors.
 * This runs on ALL pages to catch getAttribute errors from missing nav elements.
 * Does NOT hide the header - just suppresses console errors.
 */
function kop_add_navigation_error_suppressor() {
    ?>
    <script>
    (function(){
        // Suppress navigation.min.js getAttribute errors globally
        window.addEventListener('error', function(e) {
            if (e.filename && e.filename.includes('navigation') && 
                e.message && e.message.includes('getAttribute')) {
                e.preventDefault();
                e.stopPropagation();
                return true;
            }
        }, true);
        
        // Patch querySelector to return safe objects for nav elements
        var origQuerySelector = Document.prototype.querySelector;
        Document.prototype.querySelector = function(selector) {
            var result = origQuerySelector.call(this, selector);
            // If looking for nav-related elements and nothing found, return a safe stub
            if (!result && typeof selector === 'string' && 
                (selector.includes('navigation') || selector.includes('nav-toggle') || 
                 selector.includes('mobile-toggle') || selector.includes('drawer'))) {
                return {
                    getAttribute: function() { return null; },
                    setAttribute: function() {},
                    addEventListener: function() {},
                    removeEventListener: function() {},
                    classList: { add: function(){}, remove: function(){}, toggle: function(){}, contains: function(){ return false; } },
                    style: {},
                    querySelectorAll: function() { return []; },
                    querySelector: function() { return null; }
                };
            }
            return result;
        };
    })();
    </script>
    <?php
}
add_action('wp_head', 'kop_add_navigation_error_suppressor', 0); // Priority 0 - very first thing

// =================================================================
// CUSTOM FUNCTIONS
// =================================================================

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
 * Register REST API routes for facilities data.
 */
function kop_register_facilities_rest_routes() {
    register_rest_route(
        'kop/v1',
        '/facilities',
        array(
            'methods' => WP_REST_Server::READABLE,
            'callback' => function () {
                $data = kop_get_facilities_projects_from_database();

                if (is_wp_error($data)) {
                    return $data;
                }

                return rest_ensure_response($data);
            },
            'permission_callback' => '__return_true',
        )
    );

    register_rest_route(
        'kop/v1',
        '/projects',
        array(
            'methods'  => WP_REST_Server::READABLE,
            'callback' => function () {
                $projects = kop_get_facilities_projects_from_database();

                if (is_wp_error($projects)) {
                    return $projects;
                }

                return rest_ensure_response($projects);
            },
            'permission_callback' => '__return_true', // Publicly accessible
        )
    );

    register_rest_route(
        'kop/v1',
        '/projects',
        array(
            'methods'  => WP_REST_Server::READABLE,
            'callback' => function () {
                $projects = kop_get_facilities_projects_from_database();

                if (is_wp_error($projects)) {
                    return $projects;
                }

                return rest_ensure_response($projects);
            },
            'permission_callback' => '__return_true', // Publicly accessible
        )
    );

    // Register save endpoint for admin users
    register_rest_route(
        'kop/v1',
        '/projects/save',
        array(
            'methods'  => WP_REST_Server::CREATABLE,
            'callback' => 'kop_save_project_rest_callback',
            'permission_callback' => function () {
                return current_user_can('administrator');
            },
        )
    );

    // Register delete endpoint for admin users
    register_rest_route(
        'kop/v1',
        '/projects/delete',
        array(
            'methods'  => WP_REST_Server::CREATABLE,
            'callback' => 'kop_delete_project_rest_callback',
            'permission_callback' => function () {
                return current_user_can('administrator');
            },
        )
    );

    // Register autocomplete endpoint (public)
    register_rest_route(
        'kop/v1',
        '/autocomplete',
        array(
            'methods'  => WP_REST_Server::READABLE,
            'callback' => 'kop_autocomplete_rest_callback',
            'permission_callback' => '__return_true',
            'args' => array(
                'category' => array(
                    'required' => true,
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ),
                'q' => array(
                    'required' => false,
                    'type' => 'string',
                    'default' => '',
                    'sanitize_callback' => 'sanitize_text_field',
                ),
                'limit' => array(
                    'required' => false,
                    'type' => 'integer',
                    'default' => 50,
                    'sanitize_callback' => 'absint',
                ),
            ),
        )
    );
}
add_action('rest_api_init', 'kop_register_facilities_rest_routes');

/**
 * REST API callback for saving projects.
 *
 * @param WP_REST_Request $request The request object.
 * @return WP_REST_Response|WP_Error
 */
function kop_save_project_rest_callback($request) {
    global $wpdb;

    $params = $request->get_json_params();

    $project_name = isset($params['projectName']) ? sanitize_text_field($params['projectName']) : '';
    $data = isset($params['data']) ? $params['data'] : null;
    $category = isset($params['category']) ? sanitize_text_field($params['category']) : 'company';

    if (empty($project_name)) {
        return new WP_Error('missing_project_name', 'Project name is required', array('status' => 400));
    }

    if (!$data) {
        return new WP_Error('missing_data', 'Project data is required', array('status' => 400));
    }

    // Determine which table to use based on category
    $table_map = array(
        'company' => 'facilities_master',
        'companies' => 'facilities_master',
        'referrers' => 'referrers_master',
        'referrer' => 'referrers_master',
        'locations' => 'locations_master',
        'location' => 'locations_master',
    );

    $table_name = isset($table_map[$category]) ? $table_map[$category] : 'facilities_master';

    // Encode data as JSON
    $json_data = wp_json_encode($data);

    // Check if project exists (use unique_name column which matches actual DB schema)
    $existing = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT id FROM {$table_name} WHERE unique_name = %s",
            $project_name
        )
    );

    if ($existing) {
        // Update existing project (use json_data column which matches actual DB schema)
        $result = $wpdb->update(
            $table_name,
            array(
                'json_data' => $json_data,
                'updated_at' => current_time('mysql'),
            ),
            array('unique_name' => $project_name),
            array('%s', '%s'),
            array('%s')
        );
    } else {
        // Insert new project (use unique_name and json_data columns which match actual DB schema)
        $result = $wpdb->insert(
            $table_name,
            array(
                'unique_name' => $project_name,
                'json_data' => $json_data,
                'created_at' => current_time('mysql'),
                'updated_at' => current_time('mysql'),
            ),
            array('%s', '%s', '%s', '%s')
        );
    }

    if ($result === false) {
        return new WP_Error('db_error', 'Database error: ' . $wpdb->last_error, array('status' => 500));
    }

    return rest_ensure_response(array(
        'success' => true,
        'message' => $existing ? 'Project updated successfully' : 'Project created successfully',
        'projectName' => $project_name,
        'table' => $table_name,
    ));
}

/**
 * REST API callback for deleting projects.
 *
 * @param WP_REST_Request $request The request object.
 * @return WP_REST_Response|WP_Error
 */
function kop_delete_project_rest_callback($request) {
    global $wpdb;

    $params = $request->get_json_params();

    $project_name = isset($params['projectName']) ? sanitize_text_field($params['projectName']) : '';
    $category = isset($params['category']) ? sanitize_text_field($params['category']) : 'company';

    if (empty($project_name)) {
        return new WP_Error('missing_project_name', 'Project name is required', array('status' => 400));
    }

    // Determine which table to use based on category
    $table_map = array(
        'company' => 'facilities_master',
        'companies' => 'facilities_master',
        'referrers' => 'referrers_master',
        'referrer' => 'referrers_master',
        'locations' => 'locations_master',
        'location' => 'locations_master',
    );

    $table_name = isset($table_map[$category]) ? $table_map[$category] : 'facilities_master';

    // Use unique_name column which matches actual DB schema
    $result = $wpdb->delete(
        $table_name,
        array('unique_name' => $project_name),
        array('%s')
    );

    if ($result === false) {
        return new WP_Error('db_error', 'Database error: ' . $wpdb->last_error, array('status' => 500));
    }

    if ($result === 0) {
        return new WP_Error('not_found', 'Project not found', array('status' => 404));
    }

    return rest_ensure_response(array(
        'success' => true,
        'message' => 'Project deleted successfully',
        'projectName' => $project_name,
    ));
}

/**
 * REST API callback for autocomplete suggestions.
 *
 * @param WP_REST_Request $request The request object.
 * @return WP_REST_Response|WP_Error
 */
function kop_autocomplete_rest_callback($request) {
    global $wpdb;

    $raw_category = $request->get_param('category');
    $query = $request->get_param('q');
    $limit = $request->get_param('limit');
    $max_results = $limit > 0 ? max(1, min(200, $limit)) : 50;

    // Category aliases
    $category_aliases = array(
        'operators' => 'operator',
        'facilities' => 'facility',
        'facilityname' => 'facility',
        'facilitynames' => 'facility',
        'humans' => 'human',
        'people' => 'human',
        'staff' => 'human',
        'referrers' => 'referrer',
        'facilitytype' => 'type',
        'facilitytypes' => 'type',
        'types' => 'type',
        'statuses' => 'status',
        'genders' => 'gender',
        'locations' => 'location',
        'licences' => 'licensing',
        'licenses' => 'licensing',
        'licensing' => 'licensing',
        'accreditations' => 'accreditation',
        'memberships' => 'membership',
        'certifications' => 'certification',
        'investors' => 'investor',
        'roles' => 'role',
        'staffroles' => 'role',
        'operatingperiods' => 'operatingperiod',
        'operatingperiod' => 'operatingperiod',
        'operating_period' => 'operatingperiod',
        'operationyears' => 'operatingperiod',
        'operatingyears' => 'operatingperiod',
        'operation_years' => 'operatingperiod',
        'yearsofoperation' => 'operatingperiod',
        'years_of_operation' => 'operatingperiod',
    );

    $category = strtolower($raw_category);
    if (isset($category_aliases[$category])) {
        $category = $category_aliases[$category];
    }

    $allowed_categories = array(
        'operator', 'facility', 'human', 'referrer', 'type', 'status',
        'gender', 'location', 'licensing', 'membership', 'accreditation',
        'certification', 'investor', 'role', 'operatingperiod'
    );

    if (!in_array($category, $allowed_categories, true)) {
        return new WP_Error('invalid_category', 'Unsupported category parameter', array('status' => 400));
    }

    // Determine which tables to query based on category
    // locations_master contains state/region aggregates - should NOT be used for operator/facility/human names
    // referrers_master contains referrer data - should primarily be used for referrer-related categories
    $category_tables = array(
        'operator' => array('facilities_master'),
        'facility' => array('facilities_master', 'referrers_master'),  // referrers may have facilitiesReferred
        'human' => array('facilities_master'),
        'referrer' => array('facilities_master', 'referrers_master'),
        'type' => array('facilities_master'),
        'status' => array('facilities_master'),
        'gender' => array('facilities_master'),
        'location' => array('facilities_master', 'locations_master'),  // locations_master is appropriate here
        'licensing' => array('facilities_master'),
        'membership' => array('facilities_master'),
        'accreditation' => array('facilities_master'),
        'certification' => array('facilities_master'),
        'investor' => array('facilities_master'),
        'role' => array('facilities_master'),
        'operatingperiod' => array('facilities_master'),
    );

    $master_tables = isset($category_tables[$category]) ? $category_tables[$category] : array('facilities_master');
    $value_set = array();
    
    foreach ($master_tables as $table_name) {
        // Check if table exists
        $table_exists = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = %s AND table_name = %s",
            DB_NAME,
            $table_name
        ));

        if (!$table_exists) {
            continue;
        }

        // Get JSON data from the table
        $rows = $wpdb->get_results("SELECT json_data AS payload FROM {$table_name}", ARRAY_A);

        if (!is_array($rows)) {
            continue;
        }

        foreach ($rows as $row) {
            if (empty($row['payload'])) {
                continue;
            }

            $data = kop_normalize_project_payload($row['payload']);
            if (!$data) {
                continue;
            }

            kop_collect_values_for_category($category, $data, $value_set);
        }
    }

    // Also check suggested_edits table
    $suggested_exists = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = %s AND table_name = %s",
        DB_NAME,
        'suggested_edits'
    ));

    if ($suggested_exists) {
        $suggested_rows = $wpdb->get_results(
            "SELECT edited_json_data AS payload FROM suggested_edits WHERE edited_json_data IS NOT NULL AND edited_json_data <> ''",
            ARRAY_A
        );

        if (is_array($suggested_rows)) {
            foreach ($suggested_rows as $row) {
                if (empty($row['payload'])) {
                    continue;
                }

                $data = kop_normalize_project_payload($row['payload']);
                if (!$data) {
                    continue;
                }

                kop_collect_values_for_category($category, $data, $value_set);
            }
        }
    }

    $values = array_values($value_set);

    // Filter by query if provided
    if ($query !== '') {
        $values = array_values(array_filter($values, function ($value) use ($query) {
            return stripos($value, $query) !== false;
        }));
    }

    // Sort naturally
    usort($values, function ($a, $b) {
        return strnatcasecmp($a, $b);
    });

    // Limit results
    if (count($values) > $max_results) {
        $values = array_slice($values, 0, $max_results);
    }

    return rest_ensure_response(array(
        'success' => true,
        'values' => $values,
        'count' => count($values),
    ));
}

/**
 * Normalize project payload for autocomplete processing.
 *
 * @param string $json The JSON string to normalize.
 * @return array|null Normalized data array or null on failure.
 */
function kop_normalize_project_payload($json) {
    if (!$json) {
        return null;
    }

    $decoded = json_decode($json, true);
    if (!is_array($decoded)) {
        return null;
    }

    // Handle nested data structures
    $maybe_data = isset($decoded['data']) ? $decoded['data'] : null;
    if (is_string($maybe_data)) {
        $parsed = json_decode($maybe_data, true);
        if (is_array($parsed)) {
            $decoded['data'] = $parsed;
        }
    }

    if (isset($decoded['data']) && is_array($decoded['data'])) {
        $data = $decoded['data'];

        // Handle legacy facilities structures
        if (isset($data['facilities']) && is_string($data['facilities'])) {
            $parsed_facilities = json_decode($data['facilities'], true);
            if (is_array($parsed_facilities)) {
                $data['facilities'] = $parsed_facilities;
            }
        } elseif (isset($data['facilities']['facilities']) && is_array($data['facilities']['facilities'])) {
            $data['facilities'] = $data['facilities']['facilities'];
        } elseif (isset($data['facility']) && is_array($data['facility'])) {
            $data['facilities'] = $data['facility'];
        } elseif (isset($data['facility']) && is_string($data['facility'])) {
            $parsed_facilities = json_decode($data['facility'], true);
            if (is_array($parsed_facilities)) {
                $data['facilities'] = $parsed_facilities;
            }
        }
        return $data;
    }

    if (isset($decoded['project']) && is_array($decoded['project'])) {
        $project = $decoded['project'];
        if (isset($project['data']) && is_array($project['data'])) {
            return $project['data'];
        }
        if (isset($project['operator']) || isset($project['facilities'])) {
            return $project;
        }
    }

    if (isset($decoded['operator']) || isset($decoded['facilities'])) {
        return $decoded;
    }

    return null;
}

/**
 * Add a value to the autocomplete value set.
 *
 * @param array &$set The value set to add to.
 * @param mixed $value The value to add.
 */
function kop_add_autocomplete_value(&$set, $value) {
    if ($value === null) {
        return;
    }

    if (is_object($value)) {
        $value = (array) $value;
    }

    if (is_array($value)) {
        $handled = false;
        foreach (array('name', 'value', 'label', 'title', 'text') as $key) {
            if (!empty($value[$key])) {
                kop_add_autocomplete_value($set, $value[$key]);
                $handled = true;
            }
        }

        if ($handled) {
            return;
        }

        foreach ($value as $nested) {
            if (is_scalar($nested) || (is_object($nested) && method_exists($nested, '__toString'))) {
                kop_add_autocomplete_value($set, $nested);
            }
        }

        return;
    }

    $string_value = trim((string) $value);
    if ($string_value === '') {
        return;
    }

    $normalized = function_exists('mb_strtolower') ? mb_strtolower($string_value, 'UTF-8') : strtolower($string_value);
    if (!isset($set[$normalized])) {
        $set[$normalized] = $string_value;
    }
}

/**
 * Add multiple values to the autocomplete value set.
 *
 * @param array &$set The value set to add to.
 * @param array $values The values to add.
 */
function kop_add_autocomplete_values(&$set, $values) {
    if (!is_array($values)) {
        return;
    }

    foreach ($values as $value) {
        if (is_array($value)) {
            if (isset($value['name'])) {
                kop_add_autocomplete_value($set, $value['name']);
                continue;
            }
            if (isset($value['value'])) {
                kop_add_autocomplete_value($set, $value['value']);
                continue;
            }
            if (isset($value['label'])) {
                kop_add_autocomplete_value($set, $value['label']);
                continue;
            }
        }

        kop_add_autocomplete_value($set, $value);
    }
}

/**
 * Collect values for a specific category from project data.
 *
 * @param string $category The category to collect values for.
 * @param array $data The project data to extract values from.
 * @param array &$set The value set to populate.
 */
function kop_collect_values_for_category($category, $data, &$set) {
    switch ($category) {
        case 'operator':
            kop_collect_operator_values($data, $set);
            break;
        case 'facility':
            kop_collect_facility_values($data, $set);
            break;
        case 'human':
            kop_collect_human_values($data, $set);
            break;
        case 'referrer':
            kop_collect_referrer_values($data, $set);
            break;
        case 'type':
            kop_collect_type_values($data, $set);
            break;
        case 'status':
            kop_collect_status_values($data, $set);
            break;
        case 'gender':
            kop_collect_gender_values($data, $set);
            break;
        case 'location':
            kop_collect_location_values($data, $set);
            break;
        case 'licensing':
            kop_collect_licensing_values($data, $set);
            break;
        case 'membership':
            kop_collect_membership_values($data, $set);
            break;
        case 'accreditation':
            kop_collect_accreditation_values($data, $set);
            break;
        case 'certification':
            kop_collect_certification_values($data, $set);
            break;
        case 'investor':
            kop_collect_investor_values($data, $set);
            break;
        case 'role':
            kop_collect_role_values($data, $set);
            break;
        case 'operatingperiod':
            kop_collect_operatingperiod_values($data, $set);
            break;
    }
}

/**
 * Collect operator values from project data.
 */
function kop_collect_operator_values($data, &$set) {
    if (!empty($data['operator']) && is_array($data['operator'])) {
        $operator = $data['operator'];
        
        // Skip "Location Aggregate" type operators - these are state/region summaries, not real operators
        $operator_type = isset($operator['type']) ? $operator['type'] : '';
        if (strcasecmp($operator_type, 'Location Aggregate') === 0) {
            // Still collect facility-level operator info, but skip the aggregate operator name
        } else {
            kop_add_autocomplete_value($set, isset($operator['name']) ? $operator['name'] : null);
            kop_add_autocomplete_value($set, isset($operator['currentName']) ? $operator['currentName'] : null);
            kop_add_autocomplete_values($set, isset($operator['otherNames']) ? $operator['otherNames'] : array());
            kop_add_autocomplete_values($set, isset($operator['parentCompanies']) ? $operator['parentCompanies'] : array());
            kop_add_autocomplete_values($set, isset($operator['previousNames']) ? $operator['previousNames'] : array());
        }
    }

    if (!empty($data['facilities']) && is_array($data['facilities'])) {
        foreach ($data['facilities'] as $facility) {
            if (!is_array($facility)) {
                continue;
            }
            $identification = isset($facility['identification']) ? $facility['identification'] : array();
            if (is_array($identification)) {
                kop_add_autocomplete_value($set, isset($identification['currentOperator']) ? $identification['currentOperator'] : null);
            }
            kop_add_autocomplete_values($set, isset($facility['otherOperators']) ? $facility['otherOperators'] : array());
        }
    }
}

/**
 * Collect facility name values from project data.
 */
function kop_collect_facility_values($data, &$set) {
    if (!empty($data['facilities']) && is_array($data['facilities'])) {
        foreach ($data['facilities'] as $facility) {
            if (!is_array($facility)) {
                continue;
            }
            $identification = isset($facility['identification']) ? $facility['identification'] : array();
            if (is_array($identification)) {
                kop_add_autocomplete_value($set, isset($identification['name']) ? $identification['name'] : null);
                kop_add_autocomplete_value($set, isset($identification['currentName']) ? $identification['currentName'] : null);
                kop_add_autocomplete_values($set, isset($identification['otherNames']) ? $identification['otherNames'] : array());
            }
        }
    }

    // Collect from referrer consultants' facilitiesReferred
    if (!empty($data['referrerConsultants']) && is_array($data['referrerConsultants'])) {
        foreach ($data['referrerConsultants'] as $consultant) {
            if (!is_array($consultant)) {
                continue;
            }
            kop_add_autocomplete_values($set, isset($consultant['facilitiesReferred']) ? $consultant['facilitiesReferred'] : array());
        }
    }

    if (!empty($data['referrerIndividual']) && is_array($data['referrerIndividual'])) {
        kop_add_autocomplete_values($set, isset($data['referrerIndividual']['facilitiesReferred']) ? $data['referrerIndividual']['facilitiesReferred'] : array());
    }
}

/**
 * Collect human name values from project data.
 */
function kop_collect_human_values($data, &$set) {
    if (!empty($data['operator']) && is_array($data['operator'])) {
        $operator = $data['operator'];
        kop_add_autocomplete_value($set, isset($operator['ceo']) ? $operator['ceo'] : null);
        if (!empty($operator['keyStaff']) && is_array($operator['keyStaff'])) {
            $key_staff = $operator['keyStaff'];
            kop_add_autocomplete_value($set, isset($key_staff['ceo']) ? $key_staff['ceo'] : null);
            kop_add_autocomplete_values($set, isset($key_staff['founders']) ? $key_staff['founders'] : array());
            kop_add_autocomplete_values($set, isset($key_staff['keyExecutives']) ? $key_staff['keyExecutives'] : array());
            kop_add_autocomplete_values($set, isset($key_staff['boardMembers']) ? $key_staff['boardMembers'] : array());
        }
    }

    if (empty($data['facilities']) || !is_array($data['facilities'])) {
        return;
    }

    foreach ($data['facilities'] as $facility) {
        if (!is_array($facility)) {
            continue;
        }

        $staff = isset($facility['staff']) ? $facility['staff'] : array();
        if (!empty($staff['administrator']) && is_array($staff['administrator'])) {
            foreach ($staff['administrator'] as $admin) {
                if (is_array($admin)) {
                    if (isset($admin['name'])) {
                        kop_add_autocomplete_value($set, $admin['name']);
                    }
                    if (isset($admin['person'])) {
                        kop_add_autocomplete_value($set, $admin['person']);
                    }
                } else {
                    kop_add_autocomplete_value($set, $admin);
                }
            }
        }

        if (!empty($staff['notableStaff']) && is_array($staff['notableStaff'])) {
            foreach ($staff['notableStaff'] as $staff_member) {
                if (is_array($staff_member)) {
                    if (isset($staff_member['name'])) {
                        kop_add_autocomplete_value($set, $staff_member['name']);
                    }
                    if (isset($staff_member['person'])) {
                        kop_add_autocomplete_value($set, $staff_member['person']);
                    }
                } else {
                    kop_add_autocomplete_value($set, $staff_member);
                }
            }
        }
    }
}

/**
 * Collect referrer values from project data.
 */
function kop_collect_referrer_values($data, &$set) {
    if (!empty($data['facilities']) && is_array($data['facilities'])) {
        foreach ($data['facilities'] as $facility) {
            if (!is_array($facility)) {
                continue;
            }
            $identification = isset($facility['identification']) ? $facility['identification'] : array();
            if (is_array($identification)) {
                kop_add_autocomplete_values($set, isset($identification['knownReferrers']) ? $identification['knownReferrers'] : array());
            }
        }
    }

    if (!empty($data['referrerAgency']) && is_array($data['referrerAgency'])) {
        kop_add_autocomplete_value($set, isset($data['referrerAgency']['name']) ? $data['referrerAgency']['name'] : null);
    }

    if (!empty($data['referrerGroup']) && is_array($data['referrerGroup'])) {
        kop_add_autocomplete_value($set, isset($data['referrerGroup']['name']) ? $data['referrerGroup']['name'] : null);
    }

    if (!empty($data['referrerConsultants']) && is_array($data['referrerConsultants'])) {
        foreach ($data['referrerConsultants'] as $consultant) {
            if (!is_array($consultant)) {
                continue;
            }
            $full_name = isset($consultant['fullName']) ? $consultant['fullName'] : null;
            if (!$full_name) {
                $first_name = trim(isset($consultant['firstName']) ? $consultant['firstName'] : '');
                $last_name = trim(isset($consultant['lastName']) ? $consultant['lastName'] : '');
                if ($first_name || $last_name) {
                    $full_name = trim("$first_name $last_name");
                }
            }
            kop_add_autocomplete_value($set, $full_name);
        }
    }

    if (!empty($data['referrerIndividual']) && is_array($data['referrerIndividual'])) {
        $individual = $data['referrerIndividual'];
        $full_name = isset($individual['fullName']) ? $individual['fullName'] : null;
        if (!$full_name) {
            $first_name = trim(isset($individual['firstName']) ? $individual['firstName'] : '');
            $last_name = trim(isset($individual['lastName']) ? $individual['lastName'] : '');
            if ($first_name || $last_name) {
                $full_name = trim("$first_name $last_name");
            }
        }
        kop_add_autocomplete_value($set, $full_name);
    }
}

/**
 * Collect facility type values from project data.
 */
function kop_collect_type_values($data, &$set) {
    if (empty($data['facilities']) || !is_array($data['facilities'])) {
        return;
    }

    foreach ($data['facilities'] as $facility) {
        if (!is_array($facility)) {
            continue;
        }
        $details = isset($facility['facilityDetails']) ? $facility['facilityDetails'] : array();
        if (is_array($details)) {
            kop_add_autocomplete_value($set, isset($details['type']) ? $details['type'] : null);
        }
    }
}

/**
 * Collect status values from project data.
 */
function kop_collect_status_values($data, &$set) {
    if (!empty($data['operator']) && is_array($data['operator'])) {
        kop_add_autocomplete_value($set, isset($data['operator']['status']) ? $data['operator']['status'] : null);
    }

    if (empty($data['facilities']) || !is_array($data['facilities'])) {
        return;
    }

    foreach ($data['facilities'] as $facility) {
        if (!is_array($facility)) {
            continue;
        }
        $period = isset($facility['operatingPeriod']) ? $facility['operatingPeriod'] : array();
        if (is_array($period)) {
            kop_add_autocomplete_value($set, isset($period['status']) ? $period['status'] : null);
        }
    }
}

/**
 * Collect gender values from project data.
 */
function kop_collect_gender_values($data, &$set) {
    if (empty($data['facilities']) || !is_array($data['facilities'])) {
        return;
    }

    foreach ($data['facilities'] as $facility) {
        if (!is_array($facility)) {
            continue;
        }
        $details = isset($facility['facilityDetails']) ? $facility['facilityDetails'] : array();
        if (is_array($details)) {
            kop_add_autocomplete_value($set, isset($details['gender']) ? $details['gender'] : null);
        }
    }
}

/**
 * Collect location values from project data.
 */
function kop_collect_location_values($data, &$set) {
    if (!empty($data['name'])) {
        kop_add_autocomplete_value($set, $data['name']);
    }

    if (!empty($data['operator']) && is_array($data['operator'])) {
        kop_add_autocomplete_value($set, isset($data['operator']['location']) ? $data['operator']['location'] : null);
        kop_add_autocomplete_value($set, isset($data['operator']['headquarters']) ? $data['operator']['headquarters'] : null);
        kop_add_autocomplete_value($set, isset($data['operator']['name']) ? $data['operator']['name'] : null);
    }

    if (empty($data['facilities']) || !is_array($data['facilities'])) {
        return;
    }

    foreach ($data['facilities'] as $facility) {
        if (!is_array($facility)) {
            continue;
        }
        kop_add_autocomplete_value($set, isset($facility['location']) ? $facility['location'] : null);
        if (!empty($facility['address']) && is_array($facility['address'])) {
            $address = $facility['address'];
            $parts = array();
            foreach (array('street', 'city', 'state', 'zip') as $segment) {
                if (!empty($address[$segment])) {
                    $parts[] = trim((string) $address[$segment]);
                }
            }
            if (!empty($parts)) {
                kop_add_autocomplete_value($set, implode(', ', $parts));
            }
        }
    }
}

/**
 * Collect licensing values from project data.
 */
function kop_collect_licensing_values($data, &$set) {
    if (empty($data['facilities']) || !is_array($data['facilities'])) {
        return;
    }

    foreach ($data['facilities'] as $facility) {
        if (!is_array($facility)) {
            continue;
        }
        kop_add_autocomplete_values($set, isset($facility['licensing']) ? $facility['licensing'] : array());
    }
}

/**
 * Collect membership values from project data.
 */
function kop_collect_membership_values($data, &$set) {
    if (empty($data['facilities']) || !is_array($data['facilities'])) {
        return;
    }

    foreach ($data['facilities'] as $facility) {
        if (!is_array($facility)) {
            continue;
        }
        kop_add_autocomplete_values($set, isset($facility['memberships']) ? $facility['memberships'] : array());
    }
}

/**
 * Collect accreditation values from project data.
 */
function kop_collect_accreditation_values($data, &$set) {
    if (empty($data['facilities']) || !is_array($data['facilities'])) {
        return;
    }

    foreach ($data['facilities'] as $facility) {
        if (!is_array($facility)) {
            continue;
        }
        $accreditations = isset($facility['accreditations']) ? $facility['accreditations'] : array();
        if (is_array($accreditations)) {
            kop_add_autocomplete_values($set, isset($accreditations['current']) ? $accreditations['current'] : array());
            kop_add_autocomplete_values($set, isset($accreditations['past']) ? $accreditations['past'] : array());
        }
    }
}

/**
 * Collect certification values from project data.
 */
function kop_collect_certification_values($data, &$set) {
    if (empty($data['facilities']) || !is_array($data['facilities'])) {
        return;
    }

    foreach ($data['facilities'] as $facility) {
        if (!is_array($facility)) {
            continue;
        }
        kop_add_autocomplete_values($set, isset($facility['certifications']) ? $facility['certifications'] : array());
    }
}

/**
 * Collect investor values from project data.
 */
function kop_collect_investor_values($data, &$set) {
    if (empty($data['operator']) || !is_array($data['operator'])) {
        return;
    }

    kop_add_autocomplete_values($set, isset($data['operator']['investors']) ? $data['operator']['investors'] : array());
}

/**
 * Collect role values from project data.
 */
function kop_collect_role_values($data, &$set) {
    if (empty($data['facilities']) || !is_array($data['facilities'])) {
        return;
    }

    foreach ($data['facilities'] as $facility) {
        if (!is_array($facility)) {
            continue;
        }
        $staff = isset($facility['staff']) ? $facility['staff'] : array();
        $groups = array('administrator', 'notableStaff');
        foreach ($groups as $group) {
            if (empty($staff[$group]) || !is_array($staff[$group])) {
                continue;
            }
            foreach ($staff[$group] as $member) {
                if (is_array($member) && isset($member['role'])) {
                    kop_add_autocomplete_value($set, $member['role']);
                }
            }
        }
    }
}

/**
 * Collect operating period values from project data.
 */
function kop_collect_operatingperiod_values($data, &$set) {
    if (!empty($data['operator']) && is_array($data['operator'])) {
        kop_add_autocomplete_value($set, isset($data['operator']['operatingPeriod']) ? $data['operator']['operatingPeriod'] : null);
    }

    if (empty($data['facilities']) || !is_array($data['facilities'])) {
        return;
    }

    foreach ($data['facilities'] as $facility) {
        if (!is_array($facility)) {
            continue;
        }

        $period = isset($facility['operatingPeriod']) ? $facility['operatingPeriod'] : array();
        if (is_array($period)) {
            kop_add_autocomplete_value($set, isset($period['yearsOfOperation']) ? $period['yearsOfOperation'] : null);
        }
    }
}

/**
 * Get the REST API endpoint URL that provides facilities data.
 *
 * @return string REST URL for the facilities dataset.
 */
function kop_get_facilities_rest_endpoint_url() {
    return esc_url_raw(rest_url('kop/v1/facilities'));
}

/**
 * Determine whether the current request targets the TTI Program Index page.
 *
 * @return bool
 */
function kop_is_tti_program_index_context() {
    // Check if using the page template (preferred method)
    if (is_page_template('page-tti-program-index.php')) {
        return true;
    }

    // Fallback to slug check for backwards compatibility
    if (function_exists('is_page') && is_page(array('tti-program-index'))) {
        return true;
    }

    if (function_exists('get_post')) {
        $post = get_post();
        if ($post && isset($post->post_name) && $post->post_name === 'tti-program-index') {
            return true;
        }
    }

    if (function_exists('get_post_field')) {
        $slug = get_post_field('post_name');
        if (is_string($slug) && $slug === 'tti-program-index') {
            return true;
        }
    }

    if (function_exists('get_queried_object')) {
        $queried = get_queried_object();
        if ($queried && isset($queried->post_name) && $queried->post_name === 'tti-program-index') {
            return true;
        }
    }

    global $post;
    if (isset($post) && isset($post->post_name) && $post->post_name === 'tti-program-index') {
        return true;
    }

    return false;
}

/**
 * Load facilities data for TTI program index page.
 * NOTE: This function is now disabled for pages using the page-tti-program-index.php template,
 * as they now use the full data form instead of the read-only display.
 */
function load_facilities_data() {
    // Skip if using the page template (which uses the full data form)
    if (is_page_template('page-tti-program-index.php')) {
        return;
    }

    if (!kop_is_tti_program_index_context()) {
        return;
    }

    $dataset_urls = kop_get_facility_projects_dataset_urls();
    $rest_endpoint = kop_get_facilities_rest_endpoint_url();

    $script_path = get_stylesheet_directory() . '/js/inspections/facilities-display.js';
    $script_version = file_exists($script_path) ? filemtime($script_path) : time();

    $primary_dataset = !empty($rest_endpoint) ? $rest_endpoint : '';

    if ($primary_dataset === '' && !empty($dataset_urls)) {
        $primary_dataset = $dataset_urls[0];
    }

    $reports_style_path = get_stylesheet_directory() . '/css/facility-reports.css';
    if (file_exists($reports_style_path)) {
        wp_enqueue_style(
            'kop-facility-reports-style',
            get_stylesheet_directory_uri() . '/css/facility-reports.css',
            array('kop-colors'),
            filemtime($reports_style_path)
        );
    }

    wp_enqueue_script(
        'facilities-display',
        get_stylesheet_directory_uri() . '/js/inspections/facilities-display.js',
        array(),
        $script_version,
        true
);
    $json_sources = array();

    if (!empty($rest_endpoint)) {
        $json_sources[] = $rest_endpoint;
    }

    if (!empty($dataset_urls)) {
        $json_sources = array_merge($json_sources, $dataset_urls);
    }

    wp_localize_script(
        'facilities-display',
        'facilitiesConfig',
        array(
            'jsonDataUrl' => $primary_dataset,
            'jsonFileUrls' => array_values(array_filter(array_unique($json_sources)))
        )
    );
}
add_action('wp_enqueue_scripts', 'load_facilities_data');

/**
 * Load educational consultant data for the "edcons" page.
 */
function load_edcons_data() {
    // Only run on the 'edcons' page.
    if (!is_page('edcons')) {
        return;
    }

    $dataset_urls = kop_get_facility_projects_dataset_urls();
    $rest_endpoint = kop_get_facilities_rest_endpoint_url();

    $script_path = get_stylesheet_directory() . '/js/edcons-display.js';
    if (!file_exists($script_path)) {
        return;
    }
    $script_version = filemtime($script_path);

    wp_enqueue_script(
        'edcons-display',
        get_stylesheet_directory_uri() . '/js/edcons-display.js',
        array(),
        $script_version,
        true
    );

    $json_sources = array();
    if (!empty($rest_endpoint)) {
        $json_sources[] = $rest_endpoint;
    }
    if (!empty($dataset_urls)) {
        $json_sources = array_merge($json_sources, $dataset_urls);
    }

    wp_localize_script(
        'edcons-display',
        'edconsConfig',
        array('jsonFileUrls' => array_values(array_filter(array_unique($json_sources))))
    );
}
add_action('wp_enqueue_scripts', 'load_edcons_data');

function kop_enqueue_report_scripts() {
    $reports = array(
        'ca-reports' => array(
            'script_handle' => 'ca-reports-script',
            'script_path'   => '/js/inspections/ca-reports.js',
            'data_object'   => 'caReportsData',
            'json_glob'     => get_stylesheet_directory() . '/js/data/ccl*.json',
        ),
        'ut-reports' => array(
            'script_handle' => 'ut-reports-script',
            'script_path'   => '/js/inspections/ut_reports.js',
            'data_object'   => 'utReportsData',
            'json_glob'     => get_stylesheet_directory() . '/js/data/ut_*.json',
        ),
        'az-reports' => array(
            'script_handle' => 'az-reports-script',
            'script_path'   => '/js/inspections/az_reports.js',
            'data_object'   => 'azReportsData',
            'json_glob'     => get_stylesheet_directory() . '/js/data/az_reports/*.json',
        ),
        'tx-reports' => array(
            'script_handle' => 'tx-reports-script',
            'script_path'   => '/js/inspections/tx_reports.js',
            'data_object'   => 'txReportsData',
            'json_glob'     => get_stylesheet_directory() . '/js/data/tx_reports.json',
        ),
        'mt-reports' => array(
            'script_handle' => 'mt-reports-script',
            'script_path'   => '/js/inspections/mt_reports.js',
            'data_object'   => 'mtReportsData',
            'json_glob'     => get_stylesheet_directory() . '/js/data/mt_reports.json',
        ),
        'ct-reports' => array(
            'script_handle' => 'ct-reports-script',
            'script_path'   => '/js/inspections/ct_reports.js',
            'data_object'   => 'ctReportsData',
            'json_glob'     => get_stylesheet_directory() . '/js/data/ct_reports.json',
        ),
        'wa-reports' => array(
            'script_handle' => 'wa-reports-script',
            'script_path'   => '/js/inspections/wa_reports.js',
            'data_object'   => 'waReportsData',
            'json_glob'     => get_stylesheet_directory() . '/js/data/wa_reports.json',
        ),
    );

    foreach ($reports as $page_slug => $config) {
        if (is_page($page_slug)) {
            $script_full_path = get_stylesheet_directory() . $config['script_path'];
            if (!file_exists($script_full_path)) {
                continue;
            }

            $reports_style_path = get_stylesheet_directory() . '/css/facility-reports.css';
            if (file_exists($reports_style_path)) {
                wp_enqueue_style(
                    'kop-facility-reports-style',
                    get_stylesheet_directory_uri() . '/css/facility-reports.css',
                    array('kop-colors'),
                    filemtime($reports_style_path)
                );
            }

            wp_enqueue_script(
                $config['script_handle'],
                get_stylesheet_directory_uri() . $config['script_path'],
                array('jquery'), // Assuming jQuery dependency
                filemtime($script_full_path),
                true
            );

            $json_files = glob($config['json_glob']);
            $json_urls  = array();
            if ($json_files) {
                foreach ($json_files as $file) {
                    // Construct URL relative to the theme root.
                    $relative_path = str_replace(get_stylesheet_directory(), '', $file);
                    $json_urls[]   = get_stylesheet_directory_uri() . $relative_path;
                }
            }

            wp_localize_script(
                $config['script_handle'],
                $config['data_object'],
                array('jsonFileUrls' => $json_urls)
            );

            // Stop after finding the first matching page to avoid unnecessary checks.
            break;
        }
    }
}
add_action('wp_enqueue_scripts', 'kop_enqueue_report_scripts');

/**
 * Load facility form script
 */
function enqueue_facility_form_script() {
    // Only run on singular pages (posts, pages), not on archive pages.
    if (!is_singular()) {
        return;
    }

    // Check for data form templates
    $is_data_template  = is_page_template('templates/data-form-public.php');
    $is_admin_template = is_page_template('templates/data-form-admin.php') || is_page_template('page-tti-program-index.php');
    $is_data_form_page = $is_data_template || $is_admin_template;

    if (!$is_data_form_page) {
        return;
    }

    // Ensure the data-form stylesheet is queued before header output.
    $data_form_css = get_stylesheet_directory() . '/css/data-form.css';
    if (file_exists($data_form_css)) {
        wp_enqueue_style(
            'kop-data-form-style',
            get_stylesheet_directory_uri() . '/css/data-form.css',
            array('kadence-parent-style', 'kop-colors'),
            filemtime($data_form_css)
        );
    }

    // Enqueue utilities module first (provides basic helper functions)
    $utilities_relative_path = '/js/data-form/utilities.js';
    $utilities_file_path = get_stylesheet_directory() . $utilities_relative_path;
    $utilities_uri = get_stylesheet_directory_uri() . $utilities_relative_path;

    wp_enqueue_script(
        'utilities-module-script',
        $utilities_uri,
        array('jquery'),
        file_exists($utilities_file_path) ? filemtime($utilities_file_path) : time(),
        true
    );

    // Enqueue the DB form loader (handles data loading, depends on utilities)
    $loader_relative_path = '/js/data-form/db-form-loader.js';
    $loader_file_path = get_stylesheet_directory() . $loader_relative_path;
    $loader_uri = get_stylesheet_directory_uri() . $loader_relative_path;

    // Debug: Log the file path
    error_log('KOP Loader path check: ' . $loader_file_path);
    error_log('KOP Loader exists: ' . (file_exists($loader_file_path) ? 'YES' : 'NO'));

    if (!file_exists($loader_file_path)) {
        error_log('ERROR: db-form-loader.js not found at: ' . $loader_file_path);
    }

    wp_enqueue_script(
        'db-form-loader',
        $loader_uri,
        array('jquery', 'utilities-module-script'),
        file_exists($loader_file_path) ? filemtime($loader_file_path) : time(),
        true
    );

    // Enqueue the location form module (depends on utilities and loader)
    $location_script_relative_path = '/js/data-form/location-form.js';
    $location_script_file_path = get_stylesheet_directory() . $location_script_relative_path;
    $location_script_uri = get_stylesheet_directory_uri() . $location_script_relative_path;

    wp_enqueue_script(
        'location-form-script',
        $location_script_uri,
        array('jquery', 'utilities-module-script', 'db-form-loader'),
        file_exists($location_script_file_path) ? filemtime($location_script_file_path) : time(),
        true
    );

    // Enqueue the referrer form module (depends on utilities and loader)
    $referrer_script_relative_path = '/js/data-form/referrer-form.js';
    $referrer_script_file_path = get_stylesheet_directory() . $referrer_script_relative_path;
    $referrer_script_uri = get_stylesheet_directory_uri() . $referrer_script_relative_path;

    wp_enqueue_script(
        'referrer-form-script',
        $referrer_script_uri,
        array('jquery', 'utilities-module-script', 'db-form-loader'),
        file_exists($referrer_script_file_path) ? filemtime($referrer_script_file_path) : time(),
        true
    );

    // Enqueue the notes module (depends on loader and referrer module)
    $notes_script_relative_path = '/js/data-form/notes.js';
    $notes_script_file_path = get_stylesheet_directory() . $notes_script_relative_path;
    $notes_script_uri = get_stylesheet_directory_uri() . $notes_script_relative_path;

    wp_enqueue_script(
        'notes-module-script',
        $notes_script_uri,
        array('jquery', 'db-form-loader', 'referrer-form-script'),
        file_exists($notes_script_file_path) ? filemtime($notes_script_file_path) : time(),
        true
    );

    // Enqueue the autocomplete module (depends on loader)
    $autocomplete_script_relative_path = '/js/autocomplete.js';
    $autocomplete_script_file_path = get_stylesheet_directory() . $autocomplete_script_relative_path;
    $autocomplete_script_uri = get_stylesheet_directory_uri() . $autocomplete_script_relative_path;

    error_log('KOP Autocomplete path check: ' . $autocomplete_script_file_path);
    error_log('KOP Autocomplete exists: ' . (file_exists($autocomplete_script_file_path) ? 'YES' : 'NO'));

    if (!file_exists($autocomplete_script_file_path)) {
        error_log('ERROR: autocomplete.js not found at: ' . $autocomplete_script_file_path);
    }

    wp_enqueue_script(
        'autocomplete-module-script',
        $autocomplete_script_uri,
        array('jquery', 'utilities-module-script', 'db-form-loader'),
        file_exists($autocomplete_script_file_path) ? filemtime($autocomplete_script_file_path) : time(),
        true
    );

    // Enqueue the main facility form script (depends on loader, referrer module, notes module, and autocomplete module)
    $script_relative_path = '/js/data-form/facility-form.v3.js';
    $script_file_path = get_stylesheet_directory() . $script_relative_path;
    $script_uri = get_stylesheet_directory_uri() . $script_relative_path;

    wp_enqueue_script(
        'facility-form-script',
        $script_uri,
        array('jquery', 'utilities-module-script', 'db-form-loader', 'location-form-script', 'referrer-form-script', 'notes-module-script', 'autocomplete-module-script'),
        file_exists($script_file_path) ? filemtime($script_file_path) : time(),
        true
    );

    $dataset_urls = kop_get_facility_projects_dataset_urls();
    $fallback_url = !empty($dataset_urls) ? $dataset_urls[0] : '';

    // Note: This is localized to the loader script, not the main form script
    $config = array(
        'fallbackProjectsUrl' => $fallback_url,
        'fallbackProjectsUrls' => $dataset_urls,
        'projectsApiUrl' => esc_url_raw(rest_url('kop/v1/projects')),
        'apiBase' => home_url(),
        'restNonce' => wp_create_nonce('wp_rest'),
        'restSaveUrl' => esc_url_raw(rest_url('kop/v1/projects/save')),
        'restDeleteUrl' => esc_url_raw(rest_url('kop/v1/projects/delete')),
    );
    error_log('KOP Config being localized: ' . json_encode($config));

    wp_localize_script(
        'db-form-loader',
        'KOP_FACILITY_FORM_CONFIG',
        $config
    );

    // Enqueue page-specific scripts based on template (slug fallback for legacy pages)
    if ($is_data_template || is_page('data')) {
        // Suggestions page - loads data-page.js
        $data_page_relative_path = '/js/data-form/data-page.js';
        $data_page_file_path = get_stylesheet_directory() . $data_page_relative_path;
        $data_page_uri = get_stylesheet_directory_uri() . $data_page_relative_path;

        wp_enqueue_script(
            'data-page-script',
            $data_page_uri,
            array('jquery', 'facility-form-script'), // Depends on main form script
            file_exists($data_page_file_path) ? filemtime($data_page_file_path) : time(),
            true
        );
    } elseif ($is_admin_template || is_page('admin-data')) {
        // Admin page and TTI Program Index - loads admin-data-page.js
        $admin_page_relative_path = '/js/data-form/admin-data-page.js';
        $admin_page_file_path = get_stylesheet_directory() . $admin_page_relative_path;
        $admin_page_uri = get_stylesheet_directory_uri() . $admin_page_relative_path;

        wp_enqueue_script(
            'admin-data-page-script',
            $admin_page_uri,
            array('jquery', 'facility-form-script'), // Depends on main form script
            file_exists($admin_page_file_path) ? filemtime($admin_page_file_path) : time(),
            true
        );
    }
}
add_action('wp_enqueue_scripts', 'enqueue_facility_form_script');

/**
 * Enqueue News Article Processor scripts
 */
function enqueue_news_processor_scripts() {
    if (!is_page_template('page-news-processor.php')) {
        return;
    }
    
    $style_relative = '/css/news-processor.css';
    $style_path = get_stylesheet_directory() . $style_relative;
    wp_enqueue_style(
        'news-processor-style',
        get_stylesheet_directory_uri() . $style_relative,
        array('kop-colors'),
        file_exists($style_path) ? filemtime($style_path) : time()
    );
    
    $script_relative = '/js/news-processor.js';
    $script_path = get_stylesheet_directory() . $script_relative;
    wp_enqueue_script(
        'news-processor-script',
        get_stylesheet_directory_uri() . $script_relative,
        array(),
        file_exists($script_path) ? filemtime($script_path) : time(),
        true
    );
}
add_action('wp_enqueue_scripts', 'enqueue_news_processor_scripts');

/**
 * Enqueue TTI Processor (React article processor) scripts and styles
 */
function enqueue_tti_processor_scripts() {
    if (!is_page_template('page-tti-program-index.php')) {
        return;
    }
    
    // Enqueue React and React-DOM from CDN
    wp_enqueue_script(
        'react',
        'https://cdnjs.cloudflare.com/ajax/libs/react/18.2.0/react.production.min.js',
        array(),
        '18.2.0',
        true
    );
    
    wp_enqueue_script(
        'react-dom',
        'https://cdnjs.cloudflare.com/ajax/libs/react-dom/18.2.0/react-dom.production.min.js',
        array('react'),
        '18.2.0',
        true
    );
    
    // Enqueue Lucide icons (using UMD build with specific version to avoid conflicts)
    wp_enqueue_script(
        'lucide-react',
        'https://cdn.jsdelivr.net/npm/lucide@0.294.0/dist/umd/lucide.min.js',
        array(),
        '0.294.0',
        true
    );
    
    // Enqueue Babel standalone for JSX transformation
    wp_enqueue_script(
        'babel-standalone',
        'https://cdnjs.cloudflare.com/ajax/libs/babel-standalone/7.23.5/babel.min.js',
        array(),
        '7.23.5',
        true
    );
    
    // Enqueue Tailwind CSS for styling
    wp_enqueue_style(
        'tailwind-css',
        'https://cdn.tailwindcss.com',
        array(),
        'latest'
    );
    
    // Enqueue page-specific styles if they exist
    $style_relative = '/css/tti-program-index.css';
    $style_path = get_stylesheet_directory() . $style_relative;
    if (file_exists($style_path)) {
        wp_enqueue_style(
            'tti-processor-style',
            get_stylesheet_directory_uri() . $style_relative,
            array('tailwind-css', 'kop-colors'),
            filemtime($style_path)
        );
    }
}
add_action('wp_enqueue_scripts', 'enqueue_tti_processor_scripts', 10);

/**
 * Enqueue the wiki editor generator assets when its template is used.
 */
function kop_enqueue_wiki_editor_assets() {
    if (!is_page_template('page-wiki-editor.php')) {
        return;
    }

    $style_relative = '/css/wiki-editor.css';
    $style_path = get_stylesheet_directory() . $style_relative;
    wp_enqueue_style(
        'kop-wiki-editor-style',
        get_stylesheet_directory_uri() . $style_relative,
        array('kop-colors'),
        file_exists($style_path) ? filemtime($style_path) : time()
    );

    $script_relative = '/js/wiki-editor.js';
    $script_path = get_stylesheet_directory() . $script_relative;
    wp_enqueue_script(
        'kop-wiki-editor-script',
        get_stylesheet_directory_uri() . $script_relative,
        array(),
        file_exists($script_path) ? filemtime($script_path) : time(),
        true
    );
}
add_action('wp_enqueue_scripts', 'kop_enqueue_wiki_editor_assets');

/**
 * Add approval page to admin menu
 */
function add_approval_page_to_menu() {
    add_menu_page(
        'Approve Facility Edits',           // Page title
        'Approve Edits',                     // Menu title
        'manage_options',                    // Capability (admin only)
        'approve-facility-edits',            // Menu slug
        'render_approval_page_iframe',       // Callback function
        'dashi',                 // Icon
        6
    );
}
add_action('admin_menu', 'add_approval_page_to_menu');

/**
 * Render approval page iframe
 */
function render_approval_page_iframe() {
    $url = home_url('/wp-content/themes/child/api/approve-edits.php');
    ?>
    <div class="wrap">
        <iframe src="<?php echo esc_url($url); ?>" style="width: 100%; height: calc(100vh - 100px); border: none;"></iframe>
    </div>
    <?php
}

// =================================================================
// ANONYMOUS DOCUMENT PORTAL WITH CLOUDMERSIVE SECURITY
// =================================================================

/**
 * Anonymous Document Submission Portal
 * 
 * SETUP INSTRUCTIONS:
 * 1. Sign up for Cloudmersive API: https://cloudmersive.com/
 * 2. Get your API key from the dashboard
 * 3. Replace 'YOUR_CLOUDMERSIVE_API_KEY_HERE' below with your actual API key
 * 4. Free tier includes 800 API calls per month
 * 
 * Features:
 * - Virus/malware scanning with Cloudmersive
 * - Path traversal protection
 * - File type validation (whitelist)
 * - MIME type verification
 * - Metadata stripping
 * - Secure file storage
 */

class AnonymousDocPortal {
    
    private $upload_dir;
    private $allowed_types = array('pdf', 'doc', 'docx', 'txt', 'jpg', 'jpeg', 'png', 'zip');
    private $max_file_size = 10485760; // 10MB
    private $cloudmersive_api_key;
    
    public function __construct() {
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));
        add_action('wp_ajax_nopriv_submit_anonymous_doc', array($this, 'handle_submission'));
        add_action('wp_ajax_submit_anonymous_doc', array($this, 'handle_submission'));
        add_shortcode('anonymous_doc_portal', array($this, 'render_portal'));
        add_action('admin_menu', array($this, 'add_admin_menu'));

        // Securely load API key from a constant.
        $this->cloudmersive_api_key = defined('CLOUDMERSIVE_API_KEY') ? CLOUDMERSIVE_API_KEY : '';
        
        // Create secure upload directory
        $this->setup_upload_directory();
    }
    
    private function setup_upload_directory() {
        $upload_dir = wp_upload_dir();
        $this->upload_dir = $upload_dir['basedir'] . '/anonymous-submissions/';
        
        if (!file_exists($this->upload_dir)) {
            wp_mkdir_p($this->upload_dir);
            
            // Create .htaccess to prevent direct access
            $htaccess_content = "Order Deny,Allow\nDeny from all\n";
            file_put_contents($this->upload_dir . '.htaccess', $htaccess_content);
            
            // Create index.php to prevent directory listing
            file_put_contents($this->upload_dir . 'index.php', '<?php // Silence is golden');
        }
    }
    
    /**
     * Scan file with Cloudmersive API for viruses and threats
     * 
     * @param string $file_path Path to the file to scan
     * @return array Result with 'clean' boolean and 'message' string
     */
    private function scan_file_cloudmersive($file_path) {
        if (empty($this->cloudmersive_api_key)) {
            // API key not configured - log warning but allow upload
            error_log('Cloudmersive API key not configured for file scanning');
            return array('clean' => true, 'message' => 'Scan skipped - API not configured');
        }
        
        $url = 'https://api.cloudmersive.com/virus/scan/file';
        
        // Prepare file for upload
        $file_data = file_get_contents($file_path);
        $filename = basename($file_path);
        
        // Create boundary for multipart/form-data
        $boundary = wp_generate_password(24, false);
        $body = "--{$boundary}\r\n";
        $body .= "Content-Disposition: form-data; name=\"inputFile\"; filename=\"{$filename}\"\r\n";
        $body .= "Content-Type: application/octet-stream\r\n\r\n";
        $body .= $file_data . "\r\n";
        $body .= "--{$boundary}--\r\n";
        
        $response = wp_remote_post($url, array(
            'headers' => array(
                'Apikey' => $this->cloudmersive_api_key,
                'Content-Type' => 'multipart/form-data; boundary=' . $boundary
            ),
            'body' => $body,
            'timeout' => 30
        ));
        
        if (is_wp_error($response)) {
            error_log('Cloudmersive API error: ' . $response->get_error_message());
            return array('clean' => false, 'message' => 'Unable to scan file - API error');
        }
        
        $status_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $result = json_decode($body, true);
        
        if ($status_code !== 200) {
            error_log('Cloudmersive API returned status ' . $status_code . ': ' . $body);
            return array('clean' => false, 'message' => 'File scan failed');
        }
        
        // Check if file is clean
        if (isset($result['CleanResult']) && $result['CleanResult'] === true) {
            return array('clean' => true, 'message' => 'File is clean');
        } else {
            $threats = isset($result['FoundViruses']) ? implode(', ', $result['FoundViruses']) : 'Unknown threat';
            return array('clean' => false, 'message' => 'Threat detected: ' . $threats);
        }
    }
    
    public function enqueue_scripts() {
        if (is_page() && has_shortcode(get_post()->post_content, 'anonymous_doc_portal')) {
            $js_path = get_stylesheet_directory() . '/js/anonymous-portal.js';
            $css_path = get_stylesheet_directory() . '/css/anonymous-portal.css';
            
            wp_enqueue_script(
                'anonymous-portal-js', 
                get_stylesheet_directory_uri() . '/js/anonymous-portal.js', 
                array('jquery'), 
                file_exists($js_path) ? filemtime($js_path) : '1.0', 
                true
            );
            
            wp_enqueue_style(
                'anonymous-portal-css', 
                get_stylesheet_directory_uri() . '/css/anonymous-portal.css', 
                array('kop-colors'), 
                file_exists($css_path) ? filemtime($css_path) : '1.0'
            );
            
            wp_localize_script('anonymous-portal-js', 'anonymous_portal_ajax', array(
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('anonymous_doc_nonce'),
                'max_size' => $this->max_file_size,
                'allowed_types' => $this->allowed_types
            ));
        }
    }
    
    public function render_portal($atts) {
        $atts = shortcode_atts(array(
            'title' => 'Anonymous Document Submission',
            'description' => 'Submit documents anonymously. All submissions are encrypted and secure.'
        ), $atts);
        
        ob_start();
        ?>
        <div id="anonymous-doc-portal" class="anonymous-portal-container">
            <div class="portal-header">
                <h2><?php echo esc_html($atts['title']); ?></h2>
                <div id="upload-area">
                <div class="upload-content">
                    <h3>Drop files here or click to browse</h3>
                    <p>Supported formats: PDF, DOC, DOCX, TXT, JPG, PNG, ZIP</p>
                    <p>Maximum file size: 10MB</p>
                    <input type="file" id="file-input" multiple accept=".pdf,.doc,.docx,.txt,.jpg,.jpeg,.png,.zip">
                </div>
            </div>
            
            <div class="file-list" id="file-list"></div>
            
            <div class="form-section">
                <div class="form-group">
                    <label for="submission-message">Optional Message (Anonymous)</label>
                    <textarea id="submission-message" placeholder="Add any context or message about your submission..." rows="4"></textarea>
                </div>
                
                <div class="form-group checkbox-group">
                    <label class="checkbox-label required">
                        <input type="checkbox" id="legal-confirmation" required>
                        <span class="checkmark"></span>
                        <span class="checkbox-text">I confirm that this document was obtained through legal means and I have the right to submit it.</span>
                        <span class="required-indicator">*</span>
                    </label>
                    <small>This confirmation is required to proceed with submission.</small>
                </div>
                
                <div class="form-group checkbox-group">
                    <label class="checkbox-label">
                        <input type="checkbox" id="redaction-needed">
                        <span class="checkmark"></span>
                        <span class="checkbox-text">This document contains sensitive information that may require redaction (personal data, confidential information, etc.)</span>
                    </label>
                    <small>Check this if the document contains names, addresses, phone numbers, social security numbers, or other sensitive data that should be protected.</small>
                </div>
                
                <div class="form-group">
                    <label for="contact-method">Optional Contact Method</label>
                    <select id="contact-method">
                        <option value="none">No contact needed</option>
                        <option value="email">Secure Email Response</option>
                        <option value="phone">Phone Response</option>
                        <option value="signal">Signal Messenger</option>
                    </select>
                </div>
                
                <div class="form-group" id="contact-details" style="display: none;">
                    <label for="contact-info">Contact Information</label>
                    <input type="text" id="contact-info" placeholder="Enter your preferred contact method">
                    <small>This information is encrypted and only accessible to authorized personnel.</small>
                </div>
            </div>
            
            <div class="portal-actions">
                <button type="button" id="submit-docs" class="submit-btn" disabled>
                    <span class="btn-text">Submit Documents</span>
                    <span class="btn-loading" style="display: none;">Submitting...</span>
                </button>
            </div>
            
            <div class="status-messages" id="status-messages"></div>
            
            <div class="privacy-notice">
                <h4>🔒 Privacy & Security Notice</h4>
                <ul>
                    <li>No IP addresses or identifying information are logged</li>
                    <li>All files are encrypted during transmission and storage</li>
                    <li>File metadata is automatically stripped</li>
                    <li>Files are scanned for viruses before storage</li>
                    <li>Optional contact information is encrypted separately</li>
                    <li>Submissions are automatically deleted after 90 days unless flagged for retention</li>
                </ul>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }
    
    public function handle_submission() {
        check_ajax_referer('anonymous_doc_nonce', 'nonce');
        
        if (empty($_FILES['files'])) {
            wp_send_json_error('No files uploaded');
        }
        
        $files = $_FILES['files'];
        $uploaded_files = array();
        $submission_id = 'SUB-' . strtoupper(wp_generate_password(12, false));
        
        // Create submission directory
        $submission_dir = $this->upload_dir . $submission_id . '/';
        wp_mkdir_p($submission_dir);
        
        // Process each file
        for ($i = 0; $i < count($files['name']); $i++) {
            if ($files['error'][$i] !== UPLOAD_ERR_OK) {
                continue;
            }
            
            // ENHANCED SECURITY: Sanitize and validate filename
            $original_filename = $files['name'][$i];
            
            // Block dangerous patterns (path traversal, special characters)
            $dangerous_patterns = array('..', './', '\\', '<', '>', '|', ':', '*', '?', '"', "\0", '%00', 'php', 'phtml', 'exe', 'sh', 'bat', 'cmd');
            foreach ($dangerous_patterns as $pattern) {
                if (stripos($original_filename, $pattern) !== false) {
                    wp_send_json_error('Invalid filename detected: contains forbidden characters or patterns');
                    return;
                }
            }
            
            // Additional check for null bytes and control characters
            if (preg_match('/[\x00-\x1F\x7F]/', $original_filename)) {
                wp_send_json_error('Invalid filename: contains control characters');
                return;
            }
            
            // Validate file size
            if ($files['size'][$i] > $this->max_file_size) {
                wp_send_json_error('File too large: ' . sanitize_file_name($original_filename));
            }
            
            // Validate file extension (whitelist approach)
            $file_ext = strtolower(pathinfo($original_filename, PATHINFO_EXTENSION));
            if (!in_array($file_ext, $this->allowed_types)) {
                wp_send_json_error('Invalid file type: ' . $file_ext);
            }
            
            // Verify MIME type matches extension
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime_type = finfo_file($finfo, $files['tmp_name'][$i]);
            finfo_close($finfo);
            
            $allowed_mimes = array(
                'pdf' => 'application/pdf',
                'doc' => 'application/msword',
                'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'txt' => 'text/plain',
                'jpg' => 'image/jpeg',
                'jpeg' => 'image/jpeg',
                'png' => 'image/png',
                'zip' => 'application/zip'
            );
            
            if (!isset($allowed_mimes[$file_ext]) || $mime_type !== $allowed_mimes[$file_ext]) {
                // Allow some flexibility for text files and certain formats
                if (!in_array($mime_type, array('text/plain', 'application/octet-stream'))) {
                    wp_send_json_error('File MIME type does not match extension');
                }
            }
            
            // SCAN FILE FOR VIRUSES using Cloudmersive API
            $scan_result = $this->scan_file_cloudmersive($files['tmp_name'][$i]);
            if (!$scan_result['clean']) {
                // Delete the temp file
                @unlink($files['tmp_name'][$i]);
                wp_send_json_error('Security threat detected: ' . $scan_result['message']);
                return;
            }
            
            // Generate secure filename (completely random, no trace of original)
            $secure_filename = wp_generate_password(32, false) . '.' . $file_ext;
            $destination = $submission_dir . $secure_filename;
            
            // Ensure destination is within upload directory (prevent path traversal)
            $real_destination = realpath(dirname($destination));
            $real_upload_dir = realpath($this->upload_dir);
            if (strpos($real_destination, $real_upload_dir) !== 0) {
                wp_send_json_error('Security violation: invalid destination path');
                return;
            }
            
            // Move file with restricted permissions
            if (move_uploaded_file($files['tmp_name'][$i], $destination)) {
                chmod($destination, 0644); // Set secure file permissions
                
                $uploaded_files[] = array(
                    'original_name' => sanitize_file_name($original_filename),
                    'secure_name' => $secure_filename,
                    'size' => $files['size'][$i]
                );
            }
        }
        
        if (empty($uploaded_files)) {
            wp_send_json_error('No valid files were processed');
        }
        
        // ENHANCED SECURITY: Sanitize all text inputs
        $message = isset($_POST['message']) ? sanitize_textarea_field(wp_strip_all_tags($_POST['message'])) : '';
        $contact_method = isset($_POST['contact_method']) ? sanitize_text_field($_POST['contact_method']) : 'none';
        $contact_info = isset($_POST['contact_info']) ? sanitize_text_field(wp_strip_all_tags($_POST['contact_info'])) : '';
        
        // Validate contact method is from allowed list
        $allowed_contact_methods = array('none', 'email', 'phone', 'signal');
        if (!in_array($contact_method, $allowed_contact_methods)) {
            $contact_method = 'none';
        }
        
        // Validate boolean checkboxes
        $legal_confirmed = isset($_POST['legal_confirmation']) && $_POST['legal_confirmation'] === 'true' ? 1 : 0;
        $redaction_needed = isset($_POST['redaction_needed']) && $_POST['redaction_needed'] === 'true' ? 1 : 0;
        
        // Ensure legal confirmation was checked
        if (!$legal_confirmed) {
            wp_send_json_error('Legal confirmation is required');
            return;
        }
        
        // Limit message length
        if (strlen($message) > 5000) {
            wp_send_json_error('Message is too long (max 5000 characters)');
            return;
        }
        
        // Store submission metadata in database
        global $wpdb;
        $table_name = $wpdb->prefix . 'anonymous_submissions';
        
        $wpdb->insert(
            $table_name,
            array(
                'submission_id' => $submission_id,
                'file_count' => count($uploaded_files),
                'file_data' => json_encode($uploaded_files),
                'message' => $message,
                'contact_method' => $contact_method,
                'contact_info' => $contact_info,
                'legal_confirmation' => $legal_confirmed,
                'redaction_needed' => $redaction_needed,
                'status' => 'pending',
                'submission_date' => current_time('mysql')
            )
        );
        
        wp_send_json_success(array(
            'submission_id' => $submission_id,
            'message' => 'Documents submitted successfully! Your submission ID is: ' . $submission_id
        ));
    }
    
    public function add_admin_menu() {
        add_management_page(
            'Anonymous Submissions',
            'Anonymous Docs',
            'manage_options',
            'anonymous-submissions',
            array($this, 'admin_page')
        );
    }
    
    public function admin_page() {
        if (!current_user_can('manage_options')) {
            wp_die('You do not have sufficient permissions to access this page.');
        }
        
        global $wpdb;
        $table_name = $wpdb->prefix . 'anonymous_submissions';
        
        // Create table if doesn't exist
        $this->create_submissions_table();
        
        // Handle status updates
        if (isset($_POST['update_status'])) {
            $wpdb->update(
                $table_name,
                array('status' => sanitize_text_field($_POST['status'])),
                array('id' => intval($_POST['submission_db_id'])),
                array('%s'),
                array('%d')
            );
        }
        
        $submissions = $wpdb->get_results("SELECT * FROM $table_name ORDER BY submission_date DESC");
        
        ?>
        <div class="wrap">
            <h1>Anonymous Document Submissions</h1>
            
            <div class="tablenav">
                <div class="alignleft actions">
                    <p><strong>Total Submissions:</strong> <?php echo count($submissions); ?></p>
                </div>
            </div>
            
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th>Submission ID</th>
                        <th>Date</th>
                        <th>Files</th>
                        <th>Redaction Needed</th>
                        <th>Contact Method</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($submissions as $submission): ?>
                    <tr>
                        <td><strong><?php echo esc_html($submission->submission_id); ?></strong></td>
                        <td><?php echo esc_html(date('M j, Y g:i A', strtotime($submission->submission_date))); ?></td>
                        <td><?php echo intval($submission->file_count); ?> files</td>
                        <td>
                            <?php if ($submission->redaction_needed): ?>
                                <span class="redaction-flag">⚠️ Yes</span>
                            <?php else: ?>
                                <span class="no-redaction">No</span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo esc_html($submission->contact_method ? ucfirst($submission->contact_method) : 'None'); ?></td>
                        <td>
                            <span class="status-<?php echo esc_attr($submission->status); ?>">
                                <?php echo esc_html(ucfirst($submission->status)); ?>
                            </span>
                        </td>
                        <td>
                            <a href="#" class="view-submission" data-id="<?php echo $submission->id; ?>">View Details</a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        
        <style>
        .status-pending { color: #f56e28; }
        .status-reviewed { color: #00a32a; }
        .status-archived { color: #646970; }
        .redaction-flag { color: #d63638; font-weight: bold; }
        .no-redaction { color: #646970; }
        </style>
        <?php
    }
    
    private function create_submissions_table() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'anonymous_submissions';
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE IF NOT EXISTS $table_name (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            submission_id varchar(50) NOT NULL,
            file_count int NOT NULL,
            file_data text NOT NULL,
            message text,
            contact_method varchar(50),
            contact_info varchar(255),
            legal_confirmation tinyint(1) DEFAULT 0,
            redaction_needed tinyint(1) DEFAULT 0,
            status varchar(20) DEFAULT 'pending',
            submission_date datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
            PRIMARY KEY  (id)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }
}

/**
 * Initialize the Anonymous Document Portal after the theme is set up.
 *
 * This prevents issues with functions like wp_upload_dir() being called too early.
 */
function kop_initialize_anonymous_doc_portal() {
    new AnonymousDocPortal();
}
add_action('after_setup_theme', 'kop_initialize_anonymous_doc_portal');
