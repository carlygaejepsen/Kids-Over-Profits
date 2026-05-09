<?php
/**
 * REST API registration and callbacks.
 */

if (!defined('ABSPATH')) {
    exit;
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

    // Register search endpoint for database search (public)
    register_rest_route(
        'kop/v1',
        '/search',
        array(
            'methods'  => WP_REST_Server::READABLE,
            'callback' => 'kop_search_database_rest_callback',
            'permission_callback' => '__return_true',
            'args' => array(
                'keyword' => array(
                    'required' => false,
                    'type' => 'string',
                    'default' => '',
                    'sanitize_callback' => 'sanitize_text_field',
                ),
                'staff' => array(
                    'required' => false,
                    'type' => 'string',
                    'default' => '',
                    'sanitize_callback' => 'sanitize_text_field',
                ),
                'location' => array(
                    'required' => false,
                    'type' => 'string',
                    'default' => '',
                    'sanitize_callback' => 'sanitize_text_field',
                ),
                'programType' => array(
                    'required' => false,
                    'type' => 'string',
                    'default' => '',
                    'sanitize_callback' => 'sanitize_text_field',
                ),
                'limit' => array(
                    'required' => false,
                    'type' => 'integer',
                    'default' => 20,
                    'sanitize_callback' => 'absint',
                ),
            ),
        )
    );

    // Register FileBird folders endpoint (public) - Proxies FileBird native API
    register_rest_route(
        'kop/v1',
        '/folders',
        array(
            'methods' => WP_REST_Server::READABLE,
            'callback' => function () {
                // Get API key from environment
                $api_key = getenv('FILEBIRD_API_KEY');
                if (!$api_key && defined('FILEBIRD_API_KEY')) {
                    $api_key = FILEBIRD_API_KEY;
                }
                
                // If API key is available, use FileBird's native API
                if ($api_key) {
                    $response = wp_remote_get(home_url('/wp-json/filebird/public/v1/folders'), array(
                        'headers' => array(
                            'X-Api-Key' => $api_key
                        ),
                        'timeout' => 15
                    ));
                    
                    if (!is_wp_error($response)) {
                        $body = wp_remote_retrieve_body($response);
                        $data = json_decode($body, true);
                        
                        // FileBird API returns {success: true, data: {folders: [...]}}
                        if (isset($data['success']) && $data['success'] && isset($data['data']['folders'])) {
                            // Transform to match our expected format
                            $folders = array_map(function($folder) {
                                return array(
                                    'id' => $folder['id'],
                                    'name' => $folder['text'],
                                    'parent' => $folder['li_attr']['data-parent'] ?? '0'
                                );
                            }, $data['data']['folders']);
                            
                            return rest_ensure_response($folders);
                        }
                    }
                }
                
                // Fallback to direct database query if API fails or no key
                $folders = kop_get_filebird_folders();
                return rest_ensure_response($folders);
            },
            'permission_callback' => '__return_true',
        )
    );

    // Register FileBird folder content endpoint (public) - Proxies FileBird native API
    register_rest_route(
        'kop/v1',
        '/folder-content',
        array(
            'methods' => WP_REST_Server::READABLE,
            'callback' => function ($request) {
                $folder_id = $request->get_param('id');
                if (!$folder_id) {
                    return new WP_Error('missing_id', 'Folder ID is required', array('status' => 400));
                }
                
                // Get API key from environment
                $api_key = getenv('FILEBIRD_API_KEY');
                if (!$api_key && defined('FILEBIRD_API_KEY')) {
                    $api_key = FILEBIRD_API_KEY;
                }
                
                // If API key is available, use FileBird's native API
                if ($api_key) {
                    $response = wp_remote_get(
                        home_url("/wp-json/filebird/public/v1/attachment-id/?folder_id={$folder_id}"),
                        array(
                            'headers' => array(
                                'X-Api-Key' => $api_key
                            ),
                            'timeout' => 15
                        )
                    );
                    
                    if (!is_wp_error($response)) {
                        $body = wp_remote_retrieve_body($response);
                        $data = json_decode($body, true);
                        
                        // FileBird API returns {success: true, data: {attachment_ids: [...]}}
                        if (isset($data['success']) && $data['success'] && isset($data['data']['attachment_ids'])) {
                            $attachment_ids = $data['data']['attachment_ids'];
                            
                            // Get full attachment details
                            if (!empty($attachment_ids)) {
                                $args = array(
                                    'post_type' => 'attachment',
                                    'post_status' => 'inherit',
                                    'posts_per_page' => -1,
                                    'post__in' => $attachment_ids,
                                    'orderby' => 'title',
                                    'order' => 'ASC'
                                );
                                
                                $attachments = get_posts($args);
                                
                                $formatted = array_map(function($post) {
                                    return array(
                                        'id' => $post->ID,
                                        'title' => $post->post_title,
                                        'url' => wp_get_attachment_url($post->ID),
                                        'thumb_url' => function_exists('kop_get_attachment_preview_url')
                                            ? kop_get_attachment_preview_url($post->ID, 'large')
                                            : wp_get_attachment_image_url($post->ID, 'large'),
                                        'mime_type' => $post->post_mime_type,
                                        'date' => $post->post_date
                                    );
                                }, $attachments);
                                
                                return rest_ensure_response($formatted);
                            }
                            
                            return rest_ensure_response(array());
                        }
                    }
                }
                
                // Fallback to direct database query if API fails or no key
                $attachments = kop_get_folder_attachments($folder_id);
                
                // Format attachments for frontend
                $formatted = array_map(function($post) {
                    return array(
                        'id' => $post->ID,
                        'title' => $post->post_title,
                        'url' => wp_get_attachment_url($post->ID),
                        'thumb_url' => function_exists('kop_get_attachment_preview_url')
                            ? kop_get_attachment_preview_url($post->ID, 'large')
                            : wp_get_attachment_image_url($post->ID, 'large'),
                        'mime_type' => $post->post_mime_type,
                        'date' => $post->post_date
                    );
                }, $attachments);

                return rest_ensure_response($formatted);
            },
            'permission_callback' => '__return_true',
            'args' => array(
                'id' => array(
                    'required' => true,
                    'type' => 'integer',
                    'sanitize_callback' => 'absint',
                ),
            ),
        )
    );

    // Register FileBird Shortcode Renderer (public)
    register_rest_route(
        'kop/v1',
        '/render-folder-shortcode',
        array(
            'methods' => WP_REST_Server::READABLE,
            'callback' => function ($request) {
                $folder_id = $request->get_param('id');
                if (!$folder_id) return new WP_Error('missing_id', 'ID required', array('status' => 400));
                
                $html = '';
                
                // Call the function directly to bypass shortcode registration issues in REST context
                if (function_exists('kop_filebird_folder_shortcode')) {
                    $html = kop_filebird_folder_shortcode(array(
                        'folder_id' => intval($folder_id),
                        'show_count' => 'yes',
                        'layout' => 'grid'
                    ));
                }
                
                if (empty($html)) {
                    return rest_ensure_response(array('html' => ''));
                }

                // SANITATION: Strip repetitive TOC plugin URLs if they were injected
                // This targets the specific issue where the plugin repeats its own URL
                $html = preg_replace('/https?:\/\/kidsoverprofits\.org\/wp-content\/plugins\/easy-table-of-content[^\s<]*/i', '', $html);
                
                return rest_ensure_response(array('html' => $html));
            },
            'permission_callback' => '__return_true',
            'args' => array(
                'id' => array(
                    'required' => true,
                    'type' => 'integer',
                    'sanitize_callback' => 'absint',
                ),
            ),
        )
    );


    // Register Link Preview endpoint (public)
    register_rest_route(
        'kop/v1',
        '/link-preview',
        array(
            'methods' => WP_REST_Server::READABLE,
            'callback' => function ($request) {
                $url = $request->get_param('url');
                $url = is_string($url) ? trim($url) : '';
                if ($url === '') {
                    return new WP_Error('missing_url', 'URL is required', array('status' => 400));
                }

                $url = esc_url_raw($url);
                if (!$url || !wp_http_validate_url($url)) {
                    return new WP_Error('invalid_url', 'Invalid URL', array('status' => 400));
                }

                $cache_key = 'kop_link_preview_' . md5($url);
                $cached = get_transient($cache_key);
                if (is_array($cached)) {
                    return rest_ensure_response($cached);
                }

                $response = wp_remote_get($url, array(
                    'timeout' => 10,
                    'redirection' => 5,
                    'headers' => array(
                        'User-Agent' => 'Mozilla/5.0 (compatible; KOP-LinkPreview/1.0; +https://kidsoverprofits.org)',
                        'Accept' => 'text/html,application/xhtml+xml'
                    ),
                ));

                if (is_wp_error($response)) {
                    return new WP_Error('fetch_failed', 'Unable to fetch URL', array('status' => 502));
                }

                $body = wp_remote_retrieve_body($response);
                if (!is_string($body) || $body === '') {
                    $data = array('url' => $url);
                    set_transient($cache_key, $data, 6 * HOUR_IN_SECONDS);
                    return rest_ensure_response($data);
                }

                libxml_use_internal_errors(true);
                $doc = new DOMDocument();
                @$doc->loadHTML($body);
                $xpath = new DOMXPath($doc);

                $get_meta = function ($key, $attr = 'property') use ($xpath) {
                    $query = sprintf("//meta[@%s='%s']", $attr, $key);
                    $nodes = $xpath->query($query);
                    if ($nodes && $nodes->length > 0) {
                        $node = $nodes->item(0);
                        if ($node && $node->hasAttribute('content')) {
                            return trim($node->getAttribute('content'));
                        }
                    }
                    return '';
                };

                $title = $get_meta('og:title');
                if ($title === '') {
                    $title = $get_meta('twitter:title', 'name');
                }
                if ($title === '') {
                    $title_nodes = $xpath->query('//title');
                    if ($title_nodes && $title_nodes->length > 0) {
                        $title = trim($title_nodes->item(0)->textContent);
                    }
                }

                $description = $get_meta('og:description');
                if ($description === '') {
                    $description = $get_meta('description', 'name');
                }

                $site_name = $get_meta('og:site_name');
                if ($site_name === '') {
                    $parsed = wp_parse_url($url);
                    $site_name = isset($parsed['host']) ? $parsed['host'] : '';
                }

                $image = $get_meta('og:image');
                if ($image === '') {
                    $image = $get_meta('twitter:image', 'name');
                }

                $normalize_url = function ($candidate, $base_url) {
                    $candidate = is_string($candidate) ? trim($candidate) : '';
                    if ($candidate === '') return '';
                    if (strpos($candidate, '//') === 0) {
                        $scheme = wp_parse_url($base_url, PHP_URL_SCHEME);
                        $scheme = $scheme ? $scheme : 'https';
                        return $scheme . ':' . $candidate;
                    }
                    if (preg_match('#^https?://#i', $candidate)) {
                        return $candidate;
                    }
                    $base_parts = wp_parse_url($base_url);
                    if (!is_array($base_parts) || empty($base_parts['host'])) {
                        return $candidate;
                    }
                    $scheme = isset($base_parts['scheme']) ? $base_parts['scheme'] : 'https';
                    $host = $base_parts['host'];
                    $port = isset($base_parts['port']) ? ':' . $base_parts['port'] : '';
                    if (strpos($candidate, '/') === 0) {
                        return $scheme . '://' . $host . $port . $candidate;
                    }
                    $path = isset($base_parts['path']) ? $base_parts['path'] : '/';
                    $dir = rtrim(dirname($path), '/');
                    if ($dir === '') $dir = '';
                    return $scheme . '://' . $host . $port . $dir . '/' . $candidate;
                };

                $image = $normalize_url($image, $url);

                $data = array(
                    'url' => $url,
                    'title' => sanitize_text_field($title),
                    'description' => sanitize_text_field($description),
                    'site_name' => sanitize_text_field($site_name),
                    'image' => $image,
                );

                set_transient($cache_key, $data, 12 * HOUR_IN_SECONDS);
                return rest_ensure_response($data);
            },
            'permission_callback' => '__return_true',
            'args' => array(
                'url' => array(
                    'required' => true,
                    'type' => 'string',
                    'sanitize_callback' => 'esc_url_raw',
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

    // Normalize if a full project wrapper was sent in `data` (avoid double-wrapping).
    if (is_array($data) && isset($data['data']) && is_array($data['data'])) {
        if ($project_name === '' && !empty($data['name']) && is_string($data['name'])) {
            $project_name = sanitize_text_field($data['name']);
        }
        if (empty($params['category']) && !empty($data['category']) && is_string($data['category'])) {
            $category = sanitize_text_field($data['category']);
        }
        $data = $data['data'];
    }

    // Unwrap nested `data.data` when operator/facilities live one level deeper.
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

    // If payload contains operator/facility data, force companies unless it's clearly a location.
    if (is_array($data)) {
        $has_operator_data = isset($data['operator']) || isset($data['facilities']);
        if ($has_operator_data && $category !== 'companies' && $category !== 'company') {
            $category = 'companies';
        }
    }

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
 * REST API callback for database search.
 *
 * @param WP_REST_Request $request The request object.
 * @return WP_REST_Response|WP_Error
 */
function kop_search_database_rest_callback($request) {
    global $wpdb;

    // Get search parameters
    $keyword_query = $request->get_param('keyword');
    $staff_query = $request->get_param('staff');
    $location_query = $request->get_param('location');
    $program_type_query = $request->get_param('programType');
    $limit = $request->get_param('limit');
    $max_results = $limit > 0 ? max(1, min(100, $limit)) : 20;

    // At least one search query must be provided
    if (empty($keyword_query) && empty($staff_query) && empty($location_query) && empty($program_type_query)) {
        return rest_ensure_response(array(
            'success' => true,
            'results' => array(),
            'count' => 0,
        ));
    }

    $all_results = array();
    $table_base = 'facilities_master';

    // Check if table exists
    $table_exists = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = %s AND table_name = %s",
        DB_NAME,
        $table_base
    ));

    if (!$table_exists) {
        return rest_ensure_response(array(
            'success' => false,
            'results' => array(),
            'count' => 0,
            'error' => 'Database table not found',
        ));
    }

    // Get all rows from the table
    $rows = $wpdb->get_results("SELECT unique_name, json_data FROM {$table_base}", ARRAY_A);

    if (!is_array($rows)) {
        return rest_ensure_response(array(
            'success' => true,
            'results' => array(),
            'count' => 0,
        ));
    }

    foreach ($rows as $row) {
        if (empty($row['json_data'])) {
            continue;
        }

        $unique_name = isset($row['unique_name']) ? $row['unique_name'] : '';

        // Decode JSON to search content
        $data = kop_normalize_project_payload($row['json_data']);
        if (!$data) {
            continue;
        }

        $match_snippet = null;
        $match_type = '';

        // General keyword search - searches all content
        if (!empty($keyword_query)) {
            // Check unique_name first
            if (stripos($unique_name, $keyword_query) !== false) {
                $match_snippet = 'Name: ' . $unique_name;
                $match_type = 'keyword';
            } else {
                // Search all data recursively
                $match_snippet = kop_search_in_data($data, $keyword_query);
                if ($match_snippet) {
                    $match_type = 'keyword';
                }
            }
        }

        // Staff member search - searches staff-related fields
        if (!empty($staff_query) && !$match_snippet) {
            $match_snippet = kop_search_staff($data, $staff_query);
            if ($match_snippet) {
                $match_type = 'staff';
            }
        }

        // Location search - searches location-related fields
        if (!empty($location_query) && !$match_snippet) {
            $match_snippet = kop_search_location($data, $location_query);
            if ($match_snippet) {
                $match_type = 'location';
            }
        }

        // Program type search - searches program type fields
        if (!empty($program_type_query) && !$match_snippet) {
            $match_snippet = kop_search_program_type($data, $program_type_query);
            if ($match_snippet) {
                $match_type = 'programType';
            }
        }

        if ($match_snippet) {
            // Extract summary info
            $operator_name = '';
            $facility_count = 0;

            if (isset($data['operator']['name'])) {
                $operator_name = $data['operator']['name'];
            }
            if (isset($data['facilities']) && is_array($data['facilities'])) {
                $facility_count = count($data['facilities']);
            }

            $all_results[] = array(
                'name' => $unique_name,
                'label' => $unique_name,
                'category' => 'facilities',
                'operator' => $operator_name,
                'facilityCount' => $facility_count,
                'matchSnippet' => $match_snippet,
                'matchType' => $match_type,
                'source' => 'database',
                'data' => $data,
            );
        }
    }

    // Sort by name
    usort($all_results, function ($a, $b) {
        return strnatcasecmp($a['name'], $b['name']);
    });

    // Limit results
    if (count($all_results) > $max_results) {
        $all_results = array_slice($all_results, 0, $max_results);
    }

    return rest_ensure_response(array(
        'success' => true,
        'results' => $all_results,
        'count' => count($all_results),
    ));
}



/**
 * Search for a query string within project data.
 *
 * @param array  $data  The project data to search.
 * @param string $query The search query.
 * @return string|null Match snippet or null if no match.
 */
function kop_search_in_data($data, $query) {
    if (!$data || !$query) {
        return null;
    }

    $lowerQuery = strtolower($query);

    $search = function($value, $path = '') use ($lowerQuery, &$search) {
        if (is_string($value)) {
            if (stripos($value, $lowerQuery) !== false) {
                return substr($value, 0, 60);
            }
        } elseif (is_array($value)) {
            foreach ($value as $key => $item) {
                $match = $search($item, $path ? "{$path}.{$key}" : $key);
                if ($match) {
                    return $match;
                }
            }
        }
        return null;
    };

    return $search($data);
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
 * State page aggregation: name normalization, slug map, inspection page index.
 */
function kop_state_us_states() {
    return array(
        'Alabama','Alaska','Arizona','Arkansas','California','Colorado','Connecticut','Delaware','Florida','Georgia',
        'Hawaii','Idaho','Illinois','Indiana','Iowa','Kansas','Kentucky','Louisiana','Maine','Maryland',
        'Massachusetts','Michigan','Minnesota','Mississippi','Missouri','Montana','Nebraska','Nevada','New Hampshire','New Jersey',
        'New Mexico','New York','North Carolina','North Dakota','Ohio','Oklahoma','Oregon','Pennsylvania','Rhode Island','South Carolina',
        'South Dakota','Tennessee','Texas','Utah','Vermont','Virginia','Washington','West Virginia','Wisconsin','Wyoming',
        'District of Columbia',
    );
}

function kop_state_abbrev_to_name() {
    return array(
        'AL'=>'Alabama','AK'=>'Alaska','AZ'=>'Arizona','AR'=>'Arkansas','CA'=>'California','CO'=>'Colorado','CT'=>'Connecticut',
        'DE'=>'Delaware','FL'=>'Florida','GA'=>'Georgia','HI'=>'Hawaii','ID'=>'Idaho','IL'=>'Illinois','IN'=>'Indiana',
        'IA'=>'Iowa','KS'=>'Kansas','KY'=>'Kentucky','LA'=>'Louisiana','ME'=>'Maine','MD'=>'Maryland','MA'=>'Massachusetts',
        'MI'=>'Michigan','MN'=>'Minnesota','MS'=>'Mississippi','MO'=>'Missouri','MT'=>'Montana','NE'=>'Nebraska','NV'=>'Nevada',
        'NH'=>'New Hampshire','NJ'=>'New Jersey','NM'=>'New Mexico','NY'=>'New York','NC'=>'North Carolina','ND'=>'North Dakota',
        'OH'=>'Ohio','OK'=>'Oklahoma','OR'=>'Oregon','PA'=>'Pennsylvania','RI'=>'Rhode Island','SC'=>'South Carolina',
        'SD'=>'South Dakota','TN'=>'Tennessee','TX'=>'Texas','UT'=>'Utah','VT'=>'Vermont','VA'=>'Virginia','WA'=>'Washington',
        'WV'=>'West Virginia','WI'=>'Wisconsin','WY'=>'Wyoming','DC'=>'District of Columbia',
    );
}

/**
 * Convert "Utah" -> "utah", "New York" -> "new-york".
 */
function kop_state_slug($state_name) {
    $slug = strtolower(trim((string)$state_name));
    $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
    return trim($slug, '-');
}

/**
 * Resolve a slug ("utah", "new-york", "ut") to a canonical state name.
 *
 * @return string|null Canonical state name or null if unknown.
 */
function kop_state_slug_to_name($slug) {
    if (!is_string($slug) || $slug === '') return null;
    $slug = strtolower(trim($slug));

    $abbrev_map = kop_state_abbrev_to_name();
    $upper = strtoupper($slug);
    if (isset($abbrev_map[$upper])) {
        return $abbrev_map[$upper];
    }

    foreach (kop_state_us_states() as $state) {
        if (kop_state_slug($state) === $slug) {
            return $state;
        }
    }
    return null;
}

/**
 * State -> existing inspection page slug (if any).
 */
function kop_state_inspection_page_map() {
    return array(
        'Arkansas'   => 'ar-reports',
        'Arizona'    => 'az-reports',
        'California' => 'ca-reports',
        'Connecticut'=> 'ct-reports',
        'Minnesota'  => 'mn-reports',
        'Montana'    => 'mt-reports',
        'Oregon'     => 'or-reports',
        'Texas'      => 'tx-reports',
        'Utah'       => 'ut-reports',
        'Washington' => 'wa-reports',
    );
}

/**
 * State -> static inspection JSON URL(s) used for the on-page search bar.
 * Mirrors the globs in kop_enqueue_report_scripts.
 */
function kop_state_inspection_dataset_urls($state_name) {
    $theme_dir = get_stylesheet_directory();
    $theme_uri = get_stylesheet_directory_uri();

    $glob_groups = array();
    switch ($state_name) {
        case 'Utah':
            $glob_groups = array(
                array($theme_dir . '/js/data/ut_checklists/ut_reports*.json'),
                array($theme_dir . '/js/data/ut_reports*.json'),
            );
            break;
        case 'Arizona':
            $glob_groups = array(array($theme_dir . '/js/data/az_reports/*.json'));
            break;
        case 'California':
            $glob_groups = array(array($theme_dir . '/js/data/ccl*.json'));
            break;
        case 'Texas':
            $glob_groups = array(array($theme_dir . '/js/data/tx_reports.json'));
            break;
        case 'Montana':
            $glob_groups = array(array($theme_dir . '/js/data/mt_reports.json'));
            break;
        case 'Connecticut':
            $glob_groups = array(array($theme_dir . '/js/data/ct_reports.json'));
            break;
        case 'Washington':
            $glob_groups = array(array($theme_dir . '/js/data/wa_reports.json'));
            break;
        case 'Arkansas':
            $glob_groups = array(array($theme_dir . '/js/data/ar_reports.json'));
            break;
        case 'Minnesota':
            $glob_groups = array(array($theme_dir . '/js/data/mn_reports.json'));
            break;
        case 'Oregon':
            $glob_groups = array(array($theme_dir . '/js/data/or_reports*.json'));
            break;
    }

    $matched_files = array();
    foreach ($glob_groups as $group) {
        $group_files = array();
        foreach ($group as $pattern) {
            $files = glob($pattern);
            if (is_array($files)) {
                $group_files = array_merge($group_files, $files);
            }
        }
        if (!empty($group_files)) {
            $matched_files = $group_files;
            break;
        }
    }

    if (empty($matched_files)) {
        return array();
    }

    $matched_files = array_values(array_unique($matched_files));
    usort($matched_files, static function ($a, $b) {
        return (filemtime($b) ?: 0) <=> (filemtime($a) ?: 0);
    });

    return array_map(static function ($file) use ($theme_dir, $theme_uri) {
        $relative = str_replace($theme_dir, '', $file);
        return $theme_uri . str_replace('\\', '/', $relative);
    }, $matched_files);
}

/**
 * Build the programs list for a state by filtering facilities_master.
 */
function kop_state_collect_programs($state_name) {
    global $wpdb;

    $abbrev_map = array_flip(kop_state_abbrev_to_name());
    $abbrev = isset($abbrev_map[$state_name]) ? $abbrev_map[$state_name] : '';
    $state_lower = strtolower($state_name);
    $abbrev_lower = strtolower($abbrev);

    $rows = $wpdb->get_results("SELECT unique_name, json_data FROM facilities_master", ARRAY_A);
    if (!is_array($rows)) return array();

    $programs = array();
    foreach ($rows as $row) {
        $data = kop_normalize_project_payload($row['json_data']);
        if (!is_array($data) || empty($data['facilities']) || !is_array($data['facilities'])) {
            continue;
        }

        foreach ($data['facilities'] as $facility) {
            if (!is_array($facility)) continue;

            $address = isset($facility['address']) && is_array($facility['address']) ? $facility['address'] : array();
            $facility_state = trim((string)($address['state'] ?? ''));
            $facility_state_lower = strtolower($facility_state);

            $location_string = strtolower((string)($facility['location'] ?? ''));

            // Match priority:
            //   1. address.state field exactly equals the state name or its 2-letter abbrev
            //   2. location field contains the FULL state name (not the abbrev — "or" would
            //      match "California" / "Florida" as a substring)
            //   3. location field contains the abbrev wrapped in word boundaries (", OR ", " OR$")
            $matched = false;
            if ($state_lower !== '' && ($facility_state_lower === $state_lower)) {
                $matched = true;
            } elseif ($abbrev_lower !== '' && ($facility_state_lower === $abbrev_lower)) {
                $matched = true;
            } elseif ($state_lower !== '' && strpos($location_string, $state_lower) !== false) {
                $matched = true;
            } elseif ($abbrev_lower !== '' && preg_match('/\b' . preg_quote($abbrev_lower, '/') . '\b/', $location_string)) {
                $matched = true;
            }
            if (!$matched) continue;

            $identification = isset($facility['identification']) && is_array($facility['identification'])
                ? $facility['identification'] : array();

            $programs[] = array(
                'project_name'   => $row['unique_name'],
                'facility_name'  => $identification['name'] ?? $identification['currentName'] ?? '',
                'operator_name'  => isset($data['operator']['name']) ? $data['operator']['name'] : '',
                'city'           => $address['city'] ?? '',
                'state'          => $facility_state ?: $state_name,
                'street'         => $address['street'] ?? '',
                'zip'            => $address['zip'] ?? '',
                'type'           => isset($facility['facilityDetails']['type']) ? $facility['facilityDetails']['type'] : '',
                'status'         => isset($facility['operatingPeriod']['status']) ? $facility['operatingPeriod']['status'] : '',
                'operating_period' => isset($facility['operatingPeriod']['yearsOfOperation']) ? $facility['operatingPeriod']['yearsOfOperation'] : '',
            );
        }
    }

    usort($programs, static function ($a, $b) {
        return strnatcasecmp($a['facility_name'] ?: $a['project_name'], $b['facility_name'] ?: $b['project_name']);
    });

    return $programs;
}

/**
 * Reduce inspection data to per-facility summaries plus per-inspection details
 * needed for inline expansion on the state page.
 *
 * Primary source: inspection_facilities + inspection_reports MySQL tables (per AGENTS.md).
 * Fallback: legacy JSON files in js/data, used only when no DB rows exist for the state.
 *
 * Inspection records are capped (most recent 20 per facility, up to 12 findings each).
 *
 * @return array List of ['facility_name','inspection_address','inspection_count','violation_count','latest_inspection_date','inspections'=>[...]]
 */
function kop_state_collect_inspection_summaries($state_name) {
    global $wpdb;

    $abbrev_map = array_flip(kop_state_abbrev_to_name());
    $abbrev = isset($abbrev_map[$state_name]) ? $abbrev_map[$state_name] : '';

    $facilities_table_exists = $wpdb->get_var("SHOW TABLES LIKE 'inspection_facilities'");
    $reports_table_exists    = $wpdb->get_var("SHOW TABLES LIKE 'inspection_reports'");

    // -------- Primary source: DB --------
    if ($abbrev !== '' && $facilities_table_exists === 'inspection_facilities' && $reports_table_exists === 'inspection_reports') {
        $facility_rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id, facility_name, full_address FROM inspection_facilities WHERE state = %s",
            $abbrev
        ), ARRAY_A);

        if (is_array($facility_rows) && !empty($facility_rows)) {
            $facility_ids = array_map('intval', wp_list_pluck($facility_rows, 'id'));
            $placeholders = implode(',', array_fill(0, count($facility_ids), '%d'));

            $report_rows = $wpdb->get_results($wpdb->prepare(
                "SELECT id, facility_id, report_date, report_url, summary, categories_json
                 FROM inspection_reports
                 WHERE facility_id IN ($placeholders)",
                ...$facility_ids
            ), ARRAY_A);

            // Sort newest-first using parsed timestamps. SQL string ORDER BY is
            // unreliable for date columns stored as varchar (e.g. "Sep 24, 2024"
            // vs "May 19, 2022"), so we explicitly sort in PHP before the cap.
            if (is_array($report_rows)) {
                usort($report_rows, static function ($a, $b) {
                    $ta = strtotime((string)($a['report_date'] ?? '')) ?: 0;
                    $tb = strtotime((string)($b['report_date'] ?? '')) ?: 0;
                    return $tb <=> $ta;
                });
            }

            $stats_by_facility = array();        // counts
            $inspections_by_facility = array();  // per-record details

            if (is_array($report_rows)) {
                foreach ($report_rows as $r) {
                    $fid = (int)$r['facility_id'];
                    if (!isset($stats_by_facility[$fid])) {
                        $stats_by_facility[$fid] = array('count' => 0, 'violations' => 0, 'latest' => '');
                        $inspections_by_facility[$fid] = array();
                    }
                    $stats_by_facility[$fid]['count']++;

                    $categories = json_decode($r['categories_json'] ?? '', true);
                    $finding_count = 0;
                    $findings = array();
                    $type = '';
                    $pdf_url = '';

                    if (is_array($categories)) {
                        if (isset($categories['finding_count']) && is_numeric($categories['finding_count'])) {
                            $finding_count = (int)$categories['finding_count'];
                        }
                        // Pass findings through as-is so the client can apply
                        // state-specific key extraction (OR uses {rule, excerpt},
                        // AZ uses {rule, evidence, findings}, etc.). Cap to 12.
                        if (isset($categories['findings']) && is_array($categories['findings'])) {
                            if (!$finding_count) $finding_count = count($categories['findings']);
                            $findings = array_slice($categories['findings'], 0, 12);
                        }
                        // If still no count but corrective actions exist that aren't "none",
                        // treat as "has findings" so the inspection is visually flagged.
                        $corrective = trim((string)($categories['corrective_actions'] ?? ''));
                        if (!$finding_count && $corrective !== '' && strcasecmp($corrective, 'none') !== 0) {
                            $finding_count = max(1, $finding_count);
                        }
                        $type = (string)($categories['report_type'] ?? '');
                        $pdf_url = (string)($categories['pdf_url'] ?? '');
                    }
                    $stats_by_facility[$fid]['violations'] += $finding_count;

                    $date_str = $r['report_date'] ?? '';
                    if ($date_str) {
                        $existing_ts = strtotime((string)$stats_by_facility[$fid]['latest']);
                        $new_ts = strtotime((string)$date_str);
                        if ($new_ts && (!$existing_ts || $new_ts > $existing_ts)) {
                            $stats_by_facility[$fid]['latest'] = $date_str;
                        }
                    }

                    if (count($inspections_by_facility[$fid]) < 20) {
                        // Cherry-pick state-report category fields so JS can render
                        // the same accordion sections that /xx-reports/ pages do,
                        // without inflating the payload with every category every time.
                        $cats = is_array($categories) ? $categories : array();
                        $picked_categories = array();
                        $cat_fields = array(
                            'licensee', 'visit_date',
                            'program_compliance', 'corrective_actions',
                            'program_description', 'program_services',
                            'capacity_age_range', 'average_length_of_stay',
                            'average_daily_population_served', 'number_of_children_served_annually',
                            'use_of_seclusion_or_restraint',
                            'interviews_observations', 'interview_summary', 'observations',
                            'program_strengths', 'program_challenges',
                            'lawsuits', 'grievances_and_complaints',
                        );
                        foreach ($cat_fields as $cf) {
                            if (isset($cats[$cf]) && $cats[$cf] !== '' && $cats[$cf] !== null) {
                                // Trim very long narrative fields
                                $value = is_string($cats[$cf]) ? mb_substr($cats[$cf], 0, 4000) : $cats[$cf];
                                $picked_categories[$cf] = $value;
                            }
                        }

                        $inspections_by_facility[$fid][] = array(
                            'date'          => (string)$date_str,
                            'type'          => $type,
                            'finding_count' => $finding_count,
                            'findings'      => $findings,
                            'pdf_url'       => $pdf_url,
                            'report_url'    => (string)($r['report_url'] ?? ''),
                            'summary'       => mb_substr((string)($r['summary'] ?? ''), 0, 1200),
                            'categories'    => $picked_categories,
                        );
                    }
                }
            }

            $summaries = array();
            foreach ($facility_rows as $f) {
                $fid = (int)$f['id'];
                $stats = $stats_by_facility[$fid] ?? array('count' => 0, 'violations' => 0, 'latest' => '');
                $summaries[] = array(
                    'facility_name'          => trim((string)$f['facility_name']),
                    'inspection_address'     => trim((string)($f['full_address'] ?? '')),
                    'inspection_count'       => (int)$stats['count'],
                    'violation_count'        => (int)$stats['violations'],
                    'latest_inspection_date' => (string)$stats['latest'],
                    'inspections'            => $inspections_by_facility[$fid] ?? array(),
                );
            }
            return $summaries;
        }
    }

    // -------- Fallback: legacy JSON files --------
    $dataset_urls = kop_state_inspection_dataset_urls($state_name);
    if (empty($dataset_urls)) return array();

    $theme_dir = get_stylesheet_directory();
    $theme_uri = get_stylesheet_directory_uri();
    $facilities_by_key = array();

    foreach ($dataset_urls as $url) {
        $relative = str_replace($theme_uri, '', $url);
        $relative = str_replace('\\', '/', $relative);
        $local_path = $theme_dir . $relative;
        if (!file_exists($local_path)) continue;

        $raw = file_get_contents($local_path);
        if ($raw === false) continue;

        $data = json_decode($raw, true);
        if (!is_array($data)) continue;

        $facilities = isset($data[0]) && is_array($data) ? $data : (isset($data['facilities']) ? $data['facilities'] : array());
        if (!is_array($facilities)) continue;

        foreach ($facilities as $facility) {
            if (!is_array($facility)) continue;

            $name = trim((string)($facility['facility_name'] ?? $facility['name'] ?? ''));
            $address = trim((string)($facility['facility_address'] ?? $facility['address'] ?? ''));
            if ($name === '') continue;

            $inspection_count = 0;
            $violation_count = 0;
            $latest_date = '';
            $latest_ts = 0;
            $insp_records = array();

            $inspections = isset($facility['inspections']) && is_array($facility['inspections']) ? $facility['inspections'] : array();
            foreach ($inspections as $insp) {
                if (!is_array($insp)) continue;
                $inspection_count++;

                $findings_raw = isset($insp['inspection_findings']) && is_array($insp['inspection_findings'])
                    ? $insp['inspection_findings']
                    : (isset($insp['findings']) && is_array($insp['findings']) ? $insp['findings'] : array());
                $violation_count += count($findings_raw);

                $date_str = $insp['inspection_date'] ?? $insp['date'] ?? $insp['report_date'] ?? '';
                if ($date_str) {
                    $ts = strtotime((string)$date_str);
                    if ($ts && $ts > $latest_ts) {
                        $latest_ts = $ts;
                        $latest_date = $date_str;
                    }
                }

                if (count($insp_records) < 20) {
                    $findings = array();
                    foreach (array_slice($findings_raw, 0, 12) as $f_item) {
                        if (is_string($f_item)) {
                            $findings[] = array('rule_number' => '', 'description' => $f_item);
                        } elseif (is_array($f_item)) {
                            $findings[] = array(
                                'rule_number' => (string)($f_item['rule_number'] ?? $f_item['ruleNumber'] ?? ''),
                                'description' => (string)($f_item['description'] ?? $f_item['rule_description'] ?? $f_item['text'] ?? ''),
                            );
                        }
                    }
                    $checklist_urls = isset($insp['checklist_urls']) && is_array($insp['checklist_urls']) ? $insp['checklist_urls'] : array();
                    $insp_records[] = array(
                        'date'          => (string)$date_str,
                        'type'          => (string)($insp['inspection_type'] ?? $insp['inspection_types'] ?? ''),
                        'finding_count' => count($findings_raw),
                        'findings'      => $findings,
                        'pdf_url'       => $checklist_urls[0] ?? '',
                        'report_url'    => '',
                        'summary'       => '',
                    );
                }
            }

            $key = strtolower($name);
            if (!isset($facilities_by_key[$key])) {
                $facilities_by_key[$key] = array(
                    'facility_name'          => $name,
                    'inspection_address'     => $address,
                    'inspection_count'       => 0,
                    'violation_count'        => 0,
                    'latest_inspection_date' => '',
                    'inspections'            => array(),
                );
            }
            $facilities_by_key[$key]['inspection_count'] += $inspection_count;
            $facilities_by_key[$key]['violation_count']  += $violation_count;
            $facilities_by_key[$key]['inspections'] = array_merge($facilities_by_key[$key]['inspections'], $insp_records);
            // Sort accumulated inspections newest-first.
            usort($facilities_by_key[$key]['inspections'], static function ($a, $b) {
                $ta = strtotime((string)($a['date'] ?? '')) ?: 0;
                $tb = strtotime((string)($b['date'] ?? '')) ?: 0;
                return $tb <=> $ta;
            });
            if ($latest_date) {
                $existing_ts = strtotime((string)$facilities_by_key[$key]['latest_inspection_date']);
                $new_ts = strtotime((string)$latest_date);
                if ($new_ts && (!$existing_ts || $new_ts > $existing_ts)) {
                    $facilities_by_key[$key]['latest_inspection_date'] = $latest_date;
                }
            }
        }
    }

    return array_values($facilities_by_key);
}

/**
 * Merge programs (facilities_master) with inspection summaries.
 * Returns ['active' => [...], 'closed' => [...]] partitioned by operating status.
 */
function kop_state_collect_facilities($state_name) {
    $programs = kop_state_collect_programs($state_name);
    $inspections = kop_state_collect_inspection_summaries($state_name);

    $merged = array();
    $by_key = array();

    $normalize_key = static function ($name) {
        return strtolower(trim((string)$name));
    };

    foreach ($programs as $p) {
        $key = $normalize_key($p['facility_name'] ?: $p['project_name']);
        if ($key === '') continue;

        $address_parts = array_filter(array(
            $p['street'] ?? '',
            $p['city'] ?? '',
            $p['state'] ?? '',
            $p['zip'] ?? '',
        ));

        $by_key[$key] = array(
            'name'              => $p['facility_name'] ?: $p['project_name'],
            'project_name'      => $p['project_name'],
            'address'           => implode(', ', $address_parts),
            'city'              => $p['city'] ?? '',
            'state'             => $p['state'] ?? $state_name,
            'operator_name'     => $p['operator_name'] ?? '',
            'type'              => $p['type'] ?? '',
            'status'            => $p['status'] ?? '',
            'operating_period'  => $p['operating_period'] ?? '',
            'inspection_count'  => 0,
            'violation_count'   => 0,
            'latest_inspection_date' => '',
            'in_master'         => true,
            'in_inspections'    => false,
        );
    }

    foreach ($inspections as $insp) {
        $key = $normalize_key($insp['facility_name']);
        if ($key === '') continue;

        if (isset($by_key[$key])) {
            $by_key[$key]['inspection_count'] = $insp['inspection_count'];
            $by_key[$key]['violation_count']  = $insp['violation_count'];
            $by_key[$key]['latest_inspection_date'] = $insp['latest_inspection_date'];
            $by_key[$key]['inspections']      = $insp['inspections'] ?? array();
            $by_key[$key]['in_inspections']   = true;
            if (!$by_key[$key]['address'] && !empty($insp['inspection_address'])) {
                $by_key[$key]['address'] = $insp['inspection_address'];
            }
        } else {
            $by_key[$key] = array(
                'name'              => $insp['facility_name'],
                'project_name'      => '',
                'address'           => $insp['inspection_address'],
                'city'              => '',
                'state'             => $state_name,
                'operator_name'     => '',
                'type'              => '',
                'status'            => '',
                'operating_period'  => '',
                'inspection_count'  => $insp['inspection_count'],
                'violation_count'   => $insp['violation_count'],
                'latest_inspection_date' => $insp['latest_inspection_date'],
                'inspections'       => $insp['inspections'] ?? array(),
                'in_master'         => false,
                'in_inspections'    => true,
            );
        }
    }

    foreach ($by_key as $facility) {
        $merged[] = $facility;
    }

    usort($merged, static function ($a, $b) {
        return strnatcasecmp($a['name'], $b['name']);
    });

    $is_closed = static function ($status) {
        $s = strtolower(trim((string)$status));
        return in_array($s, array('closed', 'shut down', 'shutdown', 'defunct'), true);
    };

    $active = array();
    $closed = array();
    foreach ($merged as $f) {
        if ($is_closed($f['status'])) {
            $closed[] = $f;
        } else {
            $active[] = $f;
        }
    }

    return array(
        'active' => $active,
        'closed' => $closed,
        'total'  => count($merged),
    );
}

/**
 * Pull news_submissions tagged with this state.
 */
function kop_state_collect_news($state_name) {
    global $wpdb;

    $like_loc = '%' . $wpdb->esc_like($state_name) . '%';
    $like_tag = '%"' . $wpdb->esc_like($state_name) . '"%';

    $sql = "SELECT id, article_title, alternate_title, author, publication_name, publication_date,
                   article_url, article_type, article_location, summary, tags, facilities_mentioned, content_warnings
            FROM news_submissions
            WHERE status IN ('approved','published')
              AND (article_location LIKE %s OR tags LIKE %s)
            ORDER BY publication_date DESC, created_at DESC
            LIMIT 200";

    $rows = $wpdb->get_results($wpdb->prepare($sql, $like_loc, $like_tag), ARRAY_A);
    if (!is_array($rows)) return array();

    return array_map(static function ($row) {
        $row['tags'] = json_decode($row['tags'] ?? '[]', true) ?: array();
        $row['facilities_mentioned'] = json_decode($row['facilities_mentioned'] ?? '[]', true) ?: array();
        $row['content_warnings'] = json_decode($row['content_warnings'] ?? '[]', true) ?: array();
        $row['display_title'] = !empty($row['alternate_title']) ? $row['alternate_title'] : $row['article_title'];
        return $row;
    }, $rows);
}

function kop_state_collect_lawsuits($state_name) {
    global $wpdb;
    $table = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", 'lawsuits'));
    if ($table !== 'lawsuits') return array();

    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM lawsuits WHERE jurisdiction = %s AND publication_status = 'published'
         ORDER BY filing_date DESC, created_at DESC LIMIT 200",
        $state_name
    ), ARRAY_A);

    if (!is_array($rows)) return array();

    $json_fields = array('plaintiffs','defendants','facilities_mentioned','staff_mentioned','organizations_mentioned','claims','source_urls','document_urls','tags');
    return array_map(static function ($row) use ($json_fields) {
        foreach ($json_fields as $f) {
            $row[$f] = json_decode($row[$f] ?? '[]', true) ?: array();
        }
        return $row;
    }, $rows);
}

function kop_state_collect_legislation($state_name) {
    global $wpdb;
    $table = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", 'legislation'));
    if ($table !== 'legislation') return array();

    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM legislation WHERE jurisdiction = %s AND publication_status = 'published'
         ORDER BY introduced_date DESC, created_at DESC LIMIT 200",
        $state_name
    ), ARRAY_A);

    if (!is_array($rows)) return array();

    $json_fields = array('sponsors','subject_tags','facilities_affected','tags');
    return array_map(static function ($row) use ($json_fields) {
        foreach ($json_fields as $f) {
            $row[$f] = json_decode($row[$f] ?? '[]', true) ?: array();
        }
        return $row;
    }, $rows);
}

/**
 * Register the state aggregation REST route.
 */
function kop_register_state_rest_routes() {
    register_rest_route(
        'kop/v1',
        '/state/(?P<slug>[a-z0-9-]+)',
        array(
            'methods' => WP_REST_Server::READABLE,
            'permission_callback' => '__return_true',
            'callback' => function ($request) {
                $slug = strtolower($request['slug']);
                $state_name = kop_state_slug_to_name($slug);
                if (!$state_name) {
                    return new WP_Error('unknown_state', 'Unknown state slug', array('status' => 404));
                }

                $inspection_pages = kop_state_inspection_page_map();
                $inspection_slug = isset($inspection_pages[$state_name]) ? $inspection_pages[$state_name] : null;
                $inspection_url = $inspection_slug ? home_url('/' . $inspection_slug . '/') : null;
                $inspection_datasets = kop_state_inspection_dataset_urls($state_name);

                $facilities = kop_state_collect_facilities($state_name);
                $news = kop_state_collect_news($state_name);
                $lawsuits = kop_state_collect_lawsuits($state_name);
                $legislation = kop_state_collect_legislation($state_name);

                return rest_ensure_response(array(
                    'state' => array(
                        'name' => $state_name,
                        'slug' => kop_state_slug($state_name),
                    ),
                    'inspections' => array(
                        'has_reports' => $inspection_slug !== null,
                        'page_url' => $inspection_url,
                    ),
                    'facilities' => $facilities,
                    'news' => $news,
                    'lawsuits' => $lawsuits,
                    'legislation' => $legislation,
                    'counts' => array(
                        'facilities_active' => count($facilities['active']),
                        'facilities_closed' => count($facilities['closed']),
                        'facilities_total'  => $facilities['total'],
                        'news' => count($news),
                        'lawsuits' => count($lawsuits),
                        'legislation' => count($legislation),
                    ),
                ));
            },
            'args' => array(
                'slug' => array(
                    'required' => true,
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_title',
                ),
            ),
        )
    );
}
add_action('rest_api_init', 'kop_register_state_rest_routes');
