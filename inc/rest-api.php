<?php
/**
 * REST API registration and callbacks.
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once get_stylesheet_directory() . '/api/news-mentions.php';

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

                // Facility views pass merge=name so that duplicate folders sharing
                // the facility's name (e.g. the same program filed under "Active
                // Programs" and again under "Closed Programs") are all surfaced.
                // Lawsuit/legislation views omit it and target their exact folder.
                $merge_same_name = ($request->get_param('merge') === 'name')
                    && function_exists('kop_get_same_name_folder_ids');
                $root_ids = $merge_same_name
                    ? kop_get_same_name_folder_ids($folder_id)
                    : array((int) $folder_id);
                if (empty($root_ids)) {
                    $root_ids = array((int) $folder_id);
                }

                // format=tree returns the nested subfolder structure
                // ({files, subfolders:[{name, files, subfolders}]}) instead of a
                // flat list, so the frontend can show documents grouped by folder.
                if ($request->get_param('format') === 'tree'
                    && function_exists('kop_get_facility_doc_tree')) {
                    $format_file = function ($post) {
                        return array(
                            'id' => $post->ID,
                            'title' => $post->post_title,
                            'url' => wp_get_attachment_url($post->ID),
                            'thumb_url' => function_exists('kop_get_attachment_preview_url')
                                ? kop_get_attachment_preview_url($post->ID, 'large')
                                : wp_get_attachment_image_url($post->ID, 'large'),
                            'mime_type' => $post->post_mime_type,
                            'date' => $post->post_date,
                        );
                    };
                    $nodes_to_json = function ($nodes) use (&$nodes_to_json, $format_file) {
                        return array_map(function ($node) use (&$nodes_to_json, $format_file) {
                            return array(
                                'name' => $node['name'],
                                'files' => array_map($format_file, $node['attachments']),
                                'subfolders' => $nodes_to_json($node['children']),
                            );
                        }, $nodes);
                    };

                    $tree = kop_get_facility_doc_tree($folder_id, $merge_same_name);
                    return rest_ensure_response(array(
                        'files' => array_map($format_file, $tree['files']),
                        'subfolders' => $nodes_to_json($tree['subfolders']),
                    ));
                }

                // Get API key from environment
                $api_key = getenv('FILEBIRD_API_KEY');
                if (!$api_key && defined('FILEBIRD_API_KEY')) {
                    $api_key = FILEBIRD_API_KEY;
                }

                // If API key is available, use FileBird's native API.
                // FileBird's attachment-id endpoint only returns files filed
                // directly in a folder, so walk the subtree and merge results
                // to surface documents nested in subfolders too.
                if ($api_key) {
                    $folder_ids = function_exists('kop_get_descendant_ids_for_roots')
                        ? kop_get_descendant_ids_for_roots($root_ids)
                        : (function_exists('kop_get_folder_descendant_ids')
                            ? kop_get_folder_descendant_ids($folder_id)
                            : array($folder_id));

                    $attachment_ids = array();
                    $api_ok = false;
                    foreach ($folder_ids as $fid) {
                        $response = wp_remote_get(
                            home_url("/wp-json/filebird/public/v1/attachment-id/?folder_id={$fid}"),
                            array(
                                'headers' => array(
                                    'X-Api-Key' => $api_key
                                ),
                                'timeout' => 15
                            )
                        );

                        if (is_wp_error($response)) {
                            continue;
                        }

                        $body = wp_remote_retrieve_body($response);
                        $data = json_decode($body, true);

                        // FileBird API returns {success: true, data: {attachment_ids: [...]}}
                        if (isset($data['success']) && $data['success'] && isset($data['data']['attachment_ids'])) {
                            $api_ok = true;
                            $attachment_ids = array_merge($attachment_ids, $data['data']['attachment_ids']);
                        }
                    }

                    if ($api_ok) {
                        $attachment_ids = array_values(array_unique($attachment_ids));

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
                
                // Fallback to direct database query if API fails or no key
                $attachments = function_exists('kop_get_attachments_in_folder_ids')
                    ? kop_get_attachments_in_folder_ids(
                        function_exists('kop_get_descendant_ids_for_roots')
                            ? kop_get_descendant_ids_for_roots($root_ids)
                            : kop_get_folder_descendant_ids($folder_id)
                      )
                    : kop_get_folder_attachments($folder_id);

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
                'merge' => array(
                    'required' => false,
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_key',
                ),
                'format' => array(
                    'required' => false,
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_key',
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
                
                // Facility views pass merge=name to pull in duplicate folders that
                // share the facility's name across different organizational trees.
                $merge = ($request->get_param('merge') === 'name') ? 'name' : '';

                // Call the function directly to bypass shortcode registration issues in REST context
                if (function_exists('kop_filebird_folder_shortcode')) {
                    $html = kop_filebird_folder_shortcode(array(
                        'folder_id' => intval($folder_id),
                        'show_count' => 'yes',
                        'layout' => 'grid',
                        'merge' => $merge
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
                'merge' => array(
                    'required' => false,
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_key',
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
        'transporters' => 'transporters_master',
        'transporter' => 'transporters_master',
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
        'transporters' => 'transporters_master',
        'transporter' => 'transporters_master',
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
        'transporters' => 'transporter',
        'transportcompany' => 'transporter',
        'transport_company' => 'transporter',
        'transportcompanies' => 'transporter',
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
        'operator', 'facility', 'human', 'referrer', 'transporter', 'type', 'status',
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
        'transporter' => array('transporters_master', 'facilities_master'),
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
        case 'transporter':
            kop_collect_transporter_values($data, $set);
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
 * Collect transporter values from project data.
 *
 * Pulls names from the transport company (transporterCompany / transporterAgency)
 * and individual transporters array, plus any knownTransporters references on
 * facility records.
 */
function kop_collect_transporter_values($data, &$set) {
    if (!empty($data['facilities']) && is_array($data['facilities'])) {
        foreach ($data['facilities'] as $facility) {
            if (!is_array($facility)) {
                continue;
            }
            $identification = isset($facility['identification']) ? $facility['identification'] : array();
            if (is_array($identification)) {
                kop_add_autocomplete_values($set, isset($identification['knownTransporters']) ? $identification['knownTransporters'] : array());
            }
        }
    }

    foreach (array('transporterCompany', 'transporterAgency', 'transporterGroup') as $companyKey) {
        if (!empty($data[$companyKey]) && is_array($data[$companyKey])) {
            kop_add_autocomplete_value($set, isset($data[$companyKey]['name']) ? $data[$companyKey]['name'] : null);
        }
    }

    if (!empty($data['transporters']) && is_array($data['transporters'])) {
        foreach ($data['transporters'] as $transporter) {
            if (!is_array($transporter)) {
                continue;
            }
            $full_name = isset($transporter['fullName']) ? $transporter['fullName'] : null;
            if (!$full_name) {
                $first_name = trim(isset($transporter['firstName']) ? $transporter['firstName'] : '');
                $last_name = trim(isset($transporter['lastName']) ? $transporter['lastName'] : '');
                if ($first_name || $last_name) {
                    $full_name = trim("$first_name $last_name");
                }
            }
            kop_add_autocomplete_value($set, $full_name);
        }
    }

    if (!empty($data['transporterIndividual']) && is_array($data['transporterIndividual'])) {
        $individual = $data['transporterIndividual'];
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

        // Skip per-facility reference rows produced by
        // api/promote-facilities-to-rows.php. The flag lives on the outer
        // wrapper, which kop_normalize_project_payload strips, so we have to
        // peek at the raw payload before normalizing.
        $outer = json_decode($row['json_data'], true);
        if (is_array($outer) && !empty($outer['__facility_ref'])) {
            continue;
        }

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
                'category' => 'companies',
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

function kop_search_staff($data, $query) {
    if (!$data || !$query) return null;
    $q = strtolower($query);
    $keys = array('staff', 'keyStaff', 'founders', 'executives', 'keyExecutives', 'ceo', 'director');
    $search = function($value, $depth = 0) use ($q, $keys, &$search) {
        if ($depth > 4) return null;
        if (is_string($value) && stripos($value, $q) !== false) return substr($value, 0, 60);
        if (is_array($value)) {
            foreach ($value as $k => $v) {
                if (in_array($k, $keys, true) || is_int($k)) {
                    $m = $search($v, $depth + 1);
                    if ($m) return $m;
                }
            }
        }
        return null;
    };
    return $search($data);
}

function kop_search_location($data, $query) {
    if (!$data || !$query) return null;
    $q = strtolower($query);
    $keys = array('location', 'city', 'state', 'address', 'headquarters', 'hq_location',
                  'locationCity', 'locationState', 'fullAddress', 'cityState');
    $search = function($value, $depth = 0) use ($q, $keys, &$search) {
        if ($depth > 4) return null;
        if (is_string($value) && stripos($value, $q) !== false) return substr($value, 0, 60);
        if (is_array($value)) {
            foreach ($value as $k => $v) {
                if (in_array($k, $keys, true)) {
                    $m = $search($v, $depth + 1);
                    if ($m) return $m;
                }
            }
        }
        return null;
    };
    return $search($data);
}

function kop_search_program_type($data, $query) {
    if (!$data || !$query) return null;
    $q = strtolower($query);
    $keys = array('type', 'programType', 'facilityType', 'program_type', 'facility_type', 'category');
    $search = function($value, $depth = 0) use ($q, $keys, &$search) {
        if ($depth > 4) return null;
        if (is_string($value) && stripos($value, $q) !== false) return substr($value, 0, 60);
        if (is_array($value)) {
            foreach ($value as $k => $v) {
                if (in_array($k, $keys, true)) {
                    $m = $search($v, $depth + 1);
                    if ($m) return $m;
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
 * Resolve a state string (2-letter abbrev OR full name, any case) to its
 * canonical full name ("UT" / "utah" -> "Utah"). Returns the input trimmed
 * if it isn't a recognized state.
 */
function kop_state_canonical_name($s) {
    $s = trim((string)$s);
    if ($s === '') return '';
    $abbr_to_name = kop_state_abbrev_to_name();
    $upper = strtoupper($s);
    if (isset($abbr_to_name[$upper])) return $abbr_to_name[$upper];
    foreach ($abbr_to_name as $name) {
        if (strcasecmp($s, $name) === 0) return $name;
    }
    return $s;
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
        'North Carolina' => 'nc-reports',
        'Montana'    => 'mt-reports',
        'Nevada'     => 'nv-reports',
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
        case 'Nevada':
            // NV data is served live from inspections-read.php; no static JSON files.
            $glob_groups = array();
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
 * Build the programs list for a state.
 *
 * Sources, in priority order:
 *   1. locations_master row keyed by the uppercase state name — the canonical
 *      state aggregate. Every facility there belongs to the state by definition,
 *      so no per-facility state-match is needed.
 *   2. facilities_master — operator/company projects that happen to contain a
 *      facility located in this state. Filtered by address.state /
 *      locationDetails.state / free-text location string.
 *
 * Duplicate facilities (same name appearing in both sources) are deduped by
 * normalized facility name; the locations_master copy wins because it is the
 * richer aggregate.
 */
function kop_state_collect_programs($state_name) {
    global $wpdb;

    $abbrev_map = array_flip(kop_state_abbrev_to_name());
    $abbrev = isset($abbrev_map[$state_name]) ? $abbrev_map[$state_name] : '';
    $state_lower = strtolower($state_name);
    $abbrev_lower = strtolower($abbrev);

    $append_program = static function (&$programs, &$seen_names, $project_name, $facility, $data, $state_name) {
        if (!is_array($facility)) return;

        // facility.address may be either a structured array {street,city,state,zip}
        // (from import-state-pages.php) or a raw string (from import-location-pages.js).
        // The newer schema also stores parsed pieces under facility.addressParts.
        $address = isset($facility['address']) && is_array($facility['address']) ? $facility['address'] : array();
        $raw_address = isset($facility['address']) && is_string($facility['address']) ? trim($facility['address']) : '';
        $address_parts = isset($facility['addressParts']) && is_array($facility['addressParts']) ? $facility['addressParts'] : array();
        $location_details = isset($facility['locationDetails']) && is_array($facility['locationDetails']) ? $facility['locationDetails'] : array();
        $identification = isset($facility['identification']) && is_array($facility['identification']) ? $facility['identification'] : array();

        $facility_name = $identification['name'] ?? $identification['currentName'] ?? '';
        $dedup_key = kop_normalize_facility_name($facility_name);
        if ($dedup_key !== '' && isset($seen_names[$dedup_key])) {
            return;
        }
        if ($dedup_key !== '') {
            $seen_names[$dedup_key] = true;
        }

        $facility_state = trim((string)($address['state'] ?? ''));
        $locdet_state = trim((string)($location_details['state'] ?? ''));
        $parts_state = trim((string)($address_parts['state'] ?? ''));

        // Resolution order for each piece: legacy structured `address` array,
        // then new `addressParts`, then `locationDetails`, then the state row's name.
        $resolved_street = trim((string)($address['street'] ?? ($address_parts['street'] ?? '')));
        $resolved_city   = trim((string)($address['city']   ?? ($address_parts['city']   ?? ($location_details['city'] ?? ''))));
        $resolved_state  = $facility_state ?: ($parts_state ?: ($locdet_state ?: $state_name));
        $resolved_zip    = trim((string)($address['zip']    ?? ($address_parts['zip']    ?? '')));

        // locationDetails.additionalLocations is the admin form's "Additional Locations"
        // array — each entry is {city, address, zip}. Flatten each to a single string so
        // kop_state_collect_facilities can merge them into the facility's addresses[].
        $additional_addresses = array();
        $additional_raw = isset($location_details['additionalLocations']) && is_array($location_details['additionalLocations'])
            ? $location_details['additionalLocations']
            : array();
        foreach ($additional_raw as $alt) {
            if (!is_array($alt)) continue;
            $alt_street = trim((string)($alt['address'] ?? ''));
            $alt_city   = trim((string)($alt['city']    ?? ''));
            $alt_state  = trim((string)($alt['state']   ?? ''));
            $alt_zip    = trim((string)($alt['zip']     ?? ''));
            // The "address" field in additionalLocations is typically a full street+city+state+zip
            // line. If it already contains a comma we treat it as the formatted address;
            // otherwise we glue the structured pieces together.
            if ($alt_street !== '' && strpos($alt_street, ',') !== false) {
                $formatted = $alt_street;
            } else {
                $formatted = implode(', ', array_filter(array($alt_street, $alt_city, $alt_state, $alt_zip)));
            }
            $formatted = trim($formatted);
            if ($formatted !== '') {
                $additional_addresses[] = $formatted;
            }
        }

        // --- Relocation history (locationDetails.formerLocations) ---------------
        // Each entry — {state, city, address, zip, fromYear, toYear} — is a state
        // the facility used to operate in before moving. A relocated facility is
        // surfaced in BOTH the old state's directory (with a "Relocated to <new
        // state>" badge, showing its historical in-state address) and the new
        // state's directory (with a "Relocated from <old state>" note).
        $former_raw = isset($location_details['formerLocations']) && is_array($location_details['formerLocations'])
            ? $location_details['formerLocations']
            : array();
        $former_locations = array();
        foreach ($former_raw as $fl) {
            if (!is_array($fl)) continue;
            $former_locations[] = array(
                'state'    => trim((string)($fl['state']    ?? '')),
                'city'     => trim((string)($fl['city']     ?? '')),
                'address'  => trim((string)($fl['address']  ?? '')),
                'zip'      => trim((string)($fl['zip']      ?? '')),
                'fromYear' => trim((string)($fl['fromYear'] ?? '')),
                'toYear'   => trim((string)($fl['toYear']   ?? '')),
            );
        }

        $fmt_years = static function ($from, $to) {
            $from = trim((string)$from); $to = trim((string)$to);
            if ($from !== '' && $to !== '') return $from . '–' . $to;
            if ($from !== '') return 'since ' . $from;
            if ($to !== '')   return 'until ' . $to;
            return '';
        };

        $relocation = null;
        if (!empty($former_locations)) {
            $viewed_canon  = kop_state_canonical_name($state_name);
            $current_canon = kop_state_canonical_name($resolved_state);

            // Is the viewed state one of the FORMER homes (and not the current one)?
            $matched_former = null;
            if ($viewed_canon !== '') {
                foreach ($former_locations as $fl) {
                    if (kop_state_canonical_name($fl['state']) === $viewed_canon) {
                        $matched_former = $fl;
                        break;
                    }
                }
            }

            if ($matched_former !== null && $current_canon !== '' && $current_canon !== $viewed_canon) {
                // Viewing the OLD state: badge points to the new home; show the
                // historical in-state address rather than the current one.
                $relocation = array(
                    'direction'   => 'to',
                    'other_state' => $current_canon,
                    'years'       => $fmt_years($matched_former['fromYear'], $matched_former['toYear']),
                );
                if ($matched_former['address'] !== '' || $matched_former['city'] !== '') {
                    $resolved_street = $matched_former['address'];
                    $resolved_city   = $matched_former['city'];
                    $resolved_zip    = $matched_former['zip'];
                }
                $resolved_state = $viewed_canon;
                // Don't carry the new-state campus addresses onto the old-state card.
                $additional_addresses = array();
            } elseif ($viewed_canon !== '' && $current_canon === $viewed_canon) {
                // Viewing the CURRENT state: note where it came from (most recent former).
                $origin = end($former_locations);
                $origin_canon = kop_state_canonical_name($origin['state']);
                if ($origin_canon !== '' && $origin_canon !== $viewed_canon) {
                    $relocation = array(
                        'direction'   => 'from',
                        'other_state' => $origin_canon,
                        'years'       => '',
                    );
                }
            }
        }

        // Operator resolution: prefer the per-facility `identification.currentOperator`
        // (set in the admin form's Identification section). Fall back to the project-level
        // `operator.name`, but ONLY when the project isn't a "Location Aggregate" — those
        // rows store the state name in operator.name (e.g. "TEXAS"), which we don't want
        // to render as a facility's operator. Matches the filter in kop_collect_operator_values.
        $facility_operator = trim((string)($identification['currentOperator'] ?? ''));
        $project_operator_type = trim((string)($data['operator']['type'] ?? ''));
        $project_operator_name = trim((string)($data['operator']['name'] ?? ''));
        $is_location_aggregate = strcasecmp($project_operator_type, 'Location Aggregate') === 0;
        $resolved_operator = $facility_operator !== ''
            ? $facility_operator
            : ($is_location_aggregate ? '' : $project_operator_name);

        // Capacity / census: prefer admin-form values from facilityDetails. Inspection
        // data fills these in later only if blank here.
        $facility_details = isset($facility['facilityDetails']) && is_array($facility['facilityDetails']) ? $facility['facilityDetails'] : array();
        $facility_capacity = $facility_details['capacity'] ?? '';
        $facility_capacity = ($facility_capacity === null) ? '' : trim((string)$facility_capacity);
        $facility_census = $facility_details['currentCensus'] ?? '';
        $facility_census = ($facility_census === null) ? '' : trim((string)$facility_census);

        // Operating period: prefer yearsOfOperation, fall back to startYear/endYear
        // pair so admin-form date pickers surface as "1994–2010" / "Est. 1994".
        $operating = isset($facility['operatingPeriod']) && is_array($facility['operatingPeriod']) ? $facility['operatingPeriod'] : array();
        $operating_years = trim((string)($operating['yearsOfOperation'] ?? ''));
        if ($operating_years === '') {
            $start_year = trim((string)($operating['startYear'] ?? ''));
            $end_year = trim((string)($operating['endYear'] ?? ''));
            // NOTE: concatenation, not "$start_year–$end_year". The en-dash bytes
            // (0xE2 0x80 0x93) fall in PHP's 0x80–0xFF range allowed in variable
            // names, so inside a double-quoted string "$start_year–" parses as one
            // (undefined) variable and the start year + dash vanish — yielding just
            // the end year. Concatenation keeps the dash a literal.
            if ($start_year !== '' && $end_year !== '') $operating_years = $start_year . '–' . $end_year;
            elseif ($start_year !== '')                  $operating_years = $start_year;
            elseif ($end_year !== '')                    $operating_years = '–' . $end_year;
        }

        // Normalize each scalar/array field on the facility schema into a primitive
        // that the JS card layer can render. Arrays of strings stay arrays; arrays
        // of objects pass through unchanged (the JS handles {name, role, employer}).
        $as_array = static function ($v) {
            return is_array($v) ? $v : array();
        };
        $as_string = static function ($v) {
            if ($v === null || $v === '') return '';
            return is_scalar($v) ? trim((string)$v) : '';
        };

        $age_min = $facility_details['ageRange']['min'] ?? null;
        $age_max = $facility_details['ageRange']['max'] ?? null;

        // Resources is an object: keep only the keys actually populated (true flag,
        // non-empty notes/customResources). Strip the noise of the dozen always-false flags.
        $raw_resources = is_array($facility['resources'] ?? null) ? $facility['resources'] : array();
        $resource_flags = array();
        $resource_details = array();
        foreach ($raw_resources as $rkey => $rval) {
            if (strpos($rkey, 'has') === 0 && $rval === true) {
                $resource_flags[] = $rkey;
            } elseif ($rkey === 'customResources' && is_array($rval) && !empty($rval)) {
                $resource_details['customResources'] = $rval;
            } elseif (is_string($rval) && trim($rval) !== '' && in_array($rkey, array('newsDetails', 'pressReleasesDetails', 'notes'), true)) {
                $resource_details[$rkey] = trim($rval);
            }
        }

        $programs[] = array(
            'project_name'     => $project_name,
            'facility_name'    => $facility_name,
            'operator_name'    => $resolved_operator,
            'city'             => $resolved_city,
            'state'            => $resolved_state,
            'country'          => $as_string($location_details['country'] ?? ''),
            'street'           => $resolved_street,
            'zip'              => $resolved_zip,
            'raw_address'      => $raw_address,
            'additional_addresses' => $additional_addresses,
            'relocation'       => $relocation,
            'former_locations' => $former_locations,
            'type'             => $as_string($facility_details['type'] ?? ''),
            'status'           => $as_string($operating['status'] ?? ''),
            'operating_period' => $operating_years,
            'operating_notes'  => $as_array($operating['notes'] ?? null),
            'capacity'         => $facility_capacity,
            'census'           => $facility_census,
            'age_min'          => ($age_min === null || $age_min === '') ? null : (int)$age_min,
            'age_max'          => ($age_max === null || $age_max === '') ? null : (int)$age_max,
            'gender'           => $as_string($facility_details['gender'] ?? ''),
            'current_name'     => $as_string($identification['currentName'] ?? ''),
            'current_owner'    => $as_string($identification['currentOwner'] ?? ''),
            'current_owners'   => $as_array($identification['currentOwners'] ?? null),
            'past_owners'      => $as_array($identification['pastOwners'] ?? null),
            'other_names'      => $as_array($identification['otherNames'] ?? null),
            'past_names'       => $as_array($identification['pastNames'] ?? null),
            'known_referrers'  => $as_array($identification['knownReferrers'] ?? null),
            'other_operators'  => $as_array($facility['otherOperators'] ?? null),
            'administrator'    => $as_array($facility['staff']['administrator'] ?? null),
            'notable_staff'    => $as_array($facility['staff']['notableStaff'] ?? null),
            'past_tti_jobs'    => $as_array($facility['staff']['pastTTIJobs'] ?? null),
            'profile_links'    => $as_array($facility['profileLinks'] ?? null),
            'accreditations_current' => $as_array($facility['accreditations']['current'] ?? null),
            'accreditations_past'    => $as_array($facility['accreditations']['past'] ?? null),
            'memberships'      => $as_array($facility['memberships'] ?? null),
            'certifications'   => $as_array($facility['certifications'] ?? null),
            'licensing'        => $as_array($facility['licensing'] ?? null),
            'resource_flags'   => $resource_flags,
            'resource_details' => $resource_details,
            'notes'            => $as_array($facility['notes'] ?? null),
        );
    };

    $programs = array();
    $seen_names = array();

    // Source 1: locations_master.<STATE> — canonical state aggregate. Take all
    // its facilities verbatim, no state filter required.
    $loc_table = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", 'locations_master'));
    if ($loc_table === 'locations_master') {
        $loc_row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT unique_name, json_data FROM locations_master WHERE UPPER(unique_name) = %s LIMIT 1",
                strtoupper($state_name)
            ),
            ARRAY_A
        );
        if ($loc_row && !empty($loc_row['json_data'])) {
            $loc_data = kop_normalize_project_payload($loc_row['json_data']);
            if (is_array($loc_data) && !empty($loc_data['facilities']) && is_array($loc_data['facilities'])) {
                foreach ($loc_data['facilities'] as $facility) {
                    $append_program($programs, $seen_names, $loc_row['unique_name'], $facility, $loc_data, $state_name);
                }
            }
        }
    }

    // Source 2: facilities_master — operator/company projects with facilities in this state.
    $rows = $wpdb->get_results("SELECT unique_name, json_data FROM facilities_master", ARRAY_A);
    if (is_array($rows)) {
        foreach ($rows as $row) {
            $data = kop_normalize_project_payload($row['json_data']);
            if (!is_array($data) || empty($data['facilities']) || !is_array($data['facilities'])) {
                continue;
            }

            foreach ($data['facilities'] as $facility) {
                if (!is_array($facility)) continue;

                $address = isset($facility['address']) && is_array($facility['address']) ? $facility['address'] : array();
                $facility_state_lower = strtolower(trim((string)($address['state'] ?? '')));

                $location_details = isset($facility['locationDetails']) && is_array($facility['locationDetails']) ? $facility['locationDetails'] : array();
                $locdet_state_lower = strtolower(trim((string)($location_details['state'] ?? '')));

                $location_string = strtolower((string)($facility['location'] ?? ''));

                // Match priority:
                //   1. address.state or locationDetails.state equals the state name or 2-letter abbrev
                //   2. location field contains the FULL state name (not the abbrev — "or" would
                //      match "California" / "Florida" as a substring)
                //   3. location field contains the abbrev wrapped in word boundaries (", OR ", " OR$")
                $matched = false;
                if ($state_lower !== '' && ($facility_state_lower === $state_lower || $locdet_state_lower === $state_lower)) {
                    $matched = true;
                } elseif ($abbrev_lower !== '' && ($facility_state_lower === $abbrev_lower || $locdet_state_lower === $abbrev_lower)) {
                    $matched = true;
                } elseif ($state_lower !== '' && strpos($location_string, $state_lower) !== false) {
                    $matched = true;
                } elseif ($abbrev_lower !== '' && preg_match('/\b' . preg_quote($abbrev_lower, '/') . '\b/', $location_string)) {
                    $matched = true;
                }

                // Relocation: a facility that used to operate in this state (and has
                // since moved) still belongs in this state's directory, flagged as
                // relocated. Match on any locationDetails.formerLocations[].state.
                if (!$matched && !empty($location_details['formerLocations']) && is_array($location_details['formerLocations'])) {
                    foreach ($location_details['formerLocations'] as $fl) {
                        if (!is_array($fl)) continue;
                        $fl_canon = strtolower(kop_state_canonical_name($fl['state'] ?? ''));
                        if ($fl_canon !== '' && $fl_canon === $state_lower) {
                            $matched = true;
                            break;
                        }
                    }
                }
                if (!$matched) continue;

                $append_program($programs, $seen_names, $row['unique_name'], $facility, $data, $state_name);
            }
        }
    }

    usort($programs, static function ($a, $b) {
        return strnatcasecmp($a['facility_name'] ?: $a['project_name'], $b['facility_name'] ?: $b['project_name']);
    });

    return $programs;
}

/**
 * Normalize a facility name for cross-source matching (programs, inspections, FileBird folders).
 * Strips administrative-title suffixes (Board Chairperson, Date of site visit, etc.) that
 * scrapers occasionally concatenate onto facility names.
 *
 * @param string $name
 * @return string Normalized lowercase form, e.g. "adapt deer creek".
 */
/**
 * Decide whether a facility name is junk that should be filtered from state-hub display.
 * Catches purely numeric IDs ("547207220"), CCL metadata concatenations
 * ("... Facility Number: 397005980 Administrator: ..."), placeholder names
 * ("Facility (Pomona, CA)"), and pure-address rows.
 *
 * @param string $name
 * @return bool true if the name looks like garbage, not a real facility name.
 */
function kop_facility_name_looks_junky($name) {
    $name = trim((string)$name);
    if ($name === '') return true;
    // Purely numeric (raw facility_number)
    if (preg_match('/^[0-9]+$/', $name)) return true;
    // Bare jurisdiction name/abbrev ("OREGON", "Utah", "OR") — a header or
    // placeholder row, not a facility.
    $abbr_to_name = kop_state_abbrev_to_name();
    $upper = strtoupper($name);
    if (isset($abbr_to_name[$upper])) return true;                       // 2-letter abbrev
    foreach ($abbr_to_name as $state_name) {
        if (strcasecmp($name, $state_name) === 0) return true;          // full state name
    }
    // Placeholder pattern
    if (mb_stripos($name, 'Facility (') === 0) return true;
    // CCL metadata concatenations
    foreach (array('Facility Number:', 'Administrator:', 'Facility Type:', 'License Number:') as $marker) {
        if (mb_stripos($name, $marker) !== false) return true;
    }
    // Pure address: trailing ", ST 99999" or ", ST" and starts with street number
    if (preg_match('/^[0-9]+\s/', $name) && preg_match('/,\s*[A-Z]{2}[ ,]+[0-9]{5}/', $name)) return true;
    // Multi-address (ends with ';')
    if (preg_match('/;\s*$/', $name)) return true;
    return false;
}

/**
 * Curated facility-name aliases for entries that should collapse into one
 * directory row but that the rule-based normalizer can't safely merge on its
 * own (renames, abbreviations, common misspellings, "doing business as").
 *
 * Format: 'any spelling of a variant' => 'the canonical spelling'.
 *
 * Both sides are run through kop_normalize_facility_name_rules() before they're
 * compared, so you do NOT need entries for differences the rules already
 * collapse — leading "The", "&" vs "and", dashes, punctuation, or casing. For
 * example "Academy at Sisters" and "The Academy at Sisters" already merge via
 * the rules; only add a row here when two genuinely different strings name the
 * same facility.
 *
 * Chains are resolved (A => B, B => C yields A => C), so you can point several
 * variants at an intermediate name without worrying about ordering.
 *
 * @return array<string,string>
 */
function kop_facility_name_aliases() {
    return array(
        // --- Turnbridge (CT) ---------------------------------------------
        // Scraped Connecticut listings for "CT Clinical Services dba
        // Turnbridge" arrive with OCR typos ("Clincal"/"Clnical"/"Turnbdridge"),
        // a dropped space ("ClinicalServices"), and trailing unit/address
        // fragments. They are one program; collapse them so the directory
        // accumulates the unit addresses under a single entry.
        'CT Clincal Services DBA Turnbdridge'                 => 'CT Clinical Services DBA Turnbridge',
        'CT Clnical Services DBA Turnbridge'                  => 'CT Clinical Services DBA Turnbridge',
        'CT Clinical Services dba Turnbridge (Townsend Ave.'  => 'CT Clinical Services DBA Turnbridge',
        'CT ClinicalServices dba Turnbridge/#169WoodsideCir'  => 'CT Clinical Services DBA Turnbridge',
        'CT ClinicalServices dbaTurnbridge/RT#170/Washing'    => 'CT Clinical Services DBA Turnbridge',
        'CT Clnical Services dba Turnbridge/GH#168NORTH HAV'  => 'CT Clinical Services DBA Turnbridge',

        // --- Northwest Behavioral Health Services (OR) -------------------
        // The data-form profile was filed under the abbreviation "NWBHS",
        // which the rules can't expand, so its rich details never merged onto
        // the full-name card. Collapse the abbreviation — and the "Health
        // Services" misspelling of the canonical "Healthcare Services" — into
        // the spelled-out canonical name.
        'NWBHS' => 'Northwest Behavioral Healthcare Services',
        'Northwest Behavioral Health Services' => 'Northwest Behavioral Healthcare Services',

        // --- Add curated aliases below -----------------------------------
        // 'Provo Canyon Behavioral Hospital' => 'Provo Canyon School',
        // 'CALO'                             => 'Change Academy Lake of the Ozarks',
    );
}

/**
 * Rule-based half of facility-name normalization: lowercases and strips the
 * formatting noise that should never distinguish two facilities. Kept separate
 * from kop_normalize_facility_name() so the curated alias map can be normalized
 * with the same rules without recursing back through the alias lookup.
 *
 * @param string $name
 * @return string
 */
function kop_normalize_facility_name_rules($name) {
    $s = strtolower(trim((string)$name));
    if ($s === '') return '';

    // Strip scraper metadata introduced by a colon ("Board Chairperson: Kelsey
    // Wood", "Date of site visit: 03/11/2025") — but ONLY when the colon really
    // separates a metadata label/value. Many real names use a colon as a brand
    // separator ("The Journey: Homestead", "McLean Hospital: 3East"); stripping
    // those would wrongly merge distinct programs into one directory row.
    $colon_pos = mb_strpos($s, ':');
    if ($colon_pos !== false) {
        $before = trim(mb_substr($s, 0, $colon_pos));
        $after  = trim(mb_substr($s, $colon_pos + 1));
        $label_re = '/(?:board\s+chairperson|chairperson|administrator|executive\s+director|director|owner|operator|date\s+of\s+site\s+visit|site\s+visit|visit\s+date|inspection\s+date|licensee|licensed\s+capacity)$/iu';
        $after_is_date = (bool)preg_match('#^\d{1,4}[/\-.]\d{1,2}(?:[/\-.]\d{2,4})?\b#', $after);
        if ($after_is_date || preg_match($label_re, $before)) {
            $s = $before;
        }
    }

    // Peel off trailing administrative titles / scraper field labels.
    $s = preg_replace(
        '/\s+(?:board\s+chairperson|chairperson|administrator|executive\s+director|director|owner|operator|date\s+of\s+site\s+visit|site\s+visit|visit\s+date|inspection\s+date|licensee|licensed\s+capacity).*$/iu',
        '',
        $s
    );

    // Treat "doing business as" as a plain separator. Scrapers emit
    // "Operator dba Program" where the master data uses "Operator – Program",
    // so dropping the "dba" lets the two key the same (e.g. "CERTS dba Kolob
    // Canyon RTC" ↔ "CERTS – Kolob Canyon RTC").
    $s = preg_replace('#\s+d\s*/?\s*b\s*/?\s*a\s+#u', ' ', $s);

    // Punctuation, dashes, ampersand, whitespace cleanup.
    $s = preg_replace('/\s*[\-\x{2013}\x{2014}]\s*/u', ' ', $s);
    $s = preg_replace('/\s*&\s*/', ' and ', $s);
    $s = preg_replace('/[^\w\s]/u', '', $s);
    $s = preg_replace('/\s+/', ' ', $s);
    $s = trim($s);

    // Drop a leading article so "The Academy at Sisters" keys the same as
    // "Academy at Sisters". Only "the" is stripped — dropping "a"/"an" would
    // risk merging unrelated names.
    $s = preg_replace('/^the\s+/u', '', $s);

    // Drop a trailing corporate suffix so "Normative Services Academy" and
    // "Normative Services Academy, Inc." key the same. ("l.l.c." has already
    // become "l l c" after punctuation removal above.)
    $s = preg_replace('/\s+(?:l\s*l\s*c|llc|inc|incorporated|ltd|limited|co|corp|corporation)$/u', '', $s);

    return trim($s);
}

function kop_normalize_facility_name($name) {
    // Normalized variant => normalized canonical, built once per request from
    // the curated alias map and flattened so chained aliases resolve in one hop.
    static $alias_lookup = null;

    $s = kop_normalize_facility_name_rules($name);
    if ($s === '') return '';

    if ($alias_lookup === null) {
        $alias_lookup = array();
        foreach (kop_facility_name_aliases() as $variant => $canonical) {
            $vk = kop_normalize_facility_name_rules($variant);
            $ck = kop_normalize_facility_name_rules($canonical);
            if ($vk !== '' && $ck !== '' && $vk !== $ck) {
                $alias_lookup[$vk] = $ck;
            }
        }
        // Resolve chains (A=>B, B=>C becomes A=>C); cap the walk to guard
        // against an accidental cycle in the curated map.
        foreach ($alias_lookup as $from => $to) {
            $hops = 0;
            while (isset($alias_lookup[$to]) && $alias_lookup[$to] !== $to && $hops < 10) {
                $to = $alias_lookup[$to];
                $hops++;
            }
            $alias_lookup[$from] = $to;
        }
    }

    return $alias_lookup[$s] ?? $s;
}

/**
 * Preferred display spelling for a collapsed group, keyed by the grouping key
 * that kop_normalize_facility_name() returns. Lets a merged directory row show
 * the canonical name from kop_facility_name_aliases() instead of whichever
 * (possibly misspelled) variant happened to be encountered first.
 *
 * Only the curated map defines a canonical spelling — rule-only merges (leading
 * "The", punctuation, etc.) have no preferred form here and keep their
 * first-seen label. A canonical name is one that appears on the right-hand
 * side of the alias map but never on the left (i.e. it is terminal).
 *
 * @return array<string,string> grouping key => canonical display name
 */
function kop_facility_canonical_display_map() {
    static $map = null;
    if ($map === null) {
        $map = array();
        $aliases = kop_facility_name_aliases();
        $variant_keys = array();
        foreach ($aliases as $variant => $canonical) {
            $variant_keys[kop_normalize_facility_name_rules($variant)] = true;
        }
        foreach ($aliases as $variant => $canonical) {
            $ckey = kop_normalize_facility_name_rules($canonical);
            // Skip canonicals that are themselves a variant of another entry;
            // the chain resolves to a different terminal name in that case.
            if ($ckey !== '' && !isset($variant_keys[$ckey])) {
                $map[$ckey] = $canonical;
            }
        }
    }
    return $map;
}

/**
 * Normalize an address string for cross-source matching.
 * Used to merge inspection-only rows (sometimes keyed by loose address text)
 * into named facilities from facilities_master.
 *
 * @param string $address
 * @return string
 */
function kop_normalize_address_for_match($address) {
    $s = strtolower(trim((string)$address));
    if ($s === '') return '';

    // Remove unit/suite markers that vary between sources.
    $s = preg_replace('/\b(?:apt|apartment|suite|ste|unit|#)\s*[a-z0-9-]+\b/iu', ' ', $s);

    // Standardize common street suffix variants.
    $replacements = array(
        '/\bstreet\b/iu'    => 'st',
        '/\bavenue\b/iu'    => 'ave',
        '/\bboulevard\b/iu' => 'blvd',
        '/\broad\b/iu'      => 'rd',
        '/\bdrive\b/iu'     => 'dr',
        '/\blane\b/iu'      => 'ln',
        '/\bcourt\b/iu'     => 'ct',
        '/\bcircle\b/iu'    => 'cir',
        '/\bplace\b/iu'     => 'pl',
        '/\bhighway\b/iu'   => 'hwy',
        '/\bterrace\b/iu'   => 'ter',
        '/\btrail\b/iu'     => 'trl',
    );
    foreach ($replacements as $pattern => $replacement) {
        $s = preg_replace($pattern, $replacement, $s);
    }

    // Drop punctuation noise and normalize whitespace.
    $s = preg_replace('/[^a-z0-9\s]/iu', ' ', $s);
    $s = preg_replace('/\s+/u', ' ', $s);
    return trim($s);
}

/**
 * Normalize a city name for collision detection. Folds the spelling variants
 * that should NOT split a facility into two rows: "Saint"→"St", a trailing
 * "St."→"st", and a leading directional ("S Syracuse"→"syracuse").
 *
 * @param string $city
 * @return string
 */
function kop_normalize_city($city) {
    $s = strtolower(trim((string)$city));
    if ($s === '') return '';
    $s = preg_replace('/[^\w\s]/u', '', $s);            // "st." -> "st"
    $s = preg_replace('/\s+/u', ' ', $s);
    $s = trim($s);
    $s = preg_replace('/^saint\s+/u', 'st ', $s);        // "saint paul" -> "st paul"
    $s = preg_replace('/^(?:north|south|east|west|n|s|e|w|so|no)\s+/u', '', $s); // drop directional
    return trim($s);
}

/**
 * True when two city strings should be treated as the SAME place (so a shared
 * facility name keeps one directory row). False means they are different enough
 * that same-named records are probably different facilities and should split.
 *
 * Guards against false splits: missing data, "Saint/St" spelling, a street
 * fragment leaking in front of the real city ("Dove Song Way Encinitas" vs
 * "Encinitas"), and typos ("Toquerville"/"Tocqueville").
 */
function kop_same_city($a, $b) {
    $na = kop_normalize_city($a);
    $nb = kop_normalize_city($b);
    if ($na === '' || $nb === '') return true;   // unknown -> don't split
    if ($na === $nb) return true;
    $lo = mb_strlen($na) <= mb_strlen($nb) ? $na : $nb;
    $hi = $lo === $na ? $nb : $na;
    if (mb_substr($hi, -(mb_strlen($lo) + 1)) === ' ' . $lo) return true; // street fragment in front
    similar_text($na, $nb, $pct);
    return $pct >= 85.0;                          // typo guard
}

/**
 * Pull the city out of a free-form address string ("123 Main St, Provo, UT
 * 84601" -> "Provo"). Returns '' when no "City, ST" pattern is present.
 *
 * @param string $address
 * @return string
 */
function kop_city_from_address($address) {
    if (preg_match('/([A-Za-z .\'\-]+),\s*[A-Z]{2}\b/u', (string)$address, $m)) {
        return trim($m[1]);
    }
    return '';
}

/**
 * Derive the US state ABBREVIATION an address names. Strict: only a ", XX"
 * abbreviation or a spelled-out state name counts, so a stray two-letter token
 * never produces a false state. Returns '' when undeterminable.
 *
 * @param string $address
 * @return string
 */
function kop_derive_state_from_address($address) {
    $s = (string)$address;
    if ($s === '') return '';
    $abbr_to_name = kop_state_abbrev_to_name();  // ABBR => Name
    if (preg_match_all('/,\s*([A-Za-z]{2})\b/', $s, $m)) {
        $cand = strtoupper(end($m[1]));
        if (isset($abbr_to_name[$cand])) return $cand;
    }
    foreach ($abbr_to_name as $ab => $name) {
        if (preg_match('/\b' . preg_quote($name, '/') . '\b/i', $s)) return $ab;
    }
    return '';
}

/**
 * Map every master facility NAME (normalized) to the set of state abbreviations
 * it appears in, from facilities_master. This is the authoritative per-facility
 * location source for deciding which state page an inspection row belongs on —
 * far more reliable than the inspection's scraped address, which is sometimes
 * the operator's HQ rather than the facility.
 *
 * @return array<string,string[]> normalized name => list of ABBREVs
 */
function kop_master_facility_state_map() {
    static $map = null;
    if ($map !== null) return $map;

    global $wpdb;
    $map = array();
    $abbr_to_name = kop_state_abbrev_to_name();          // ABBR => Name
    $name_to_abbr = array();
    foreach ($abbr_to_name as $ab => $name) $name_to_abbr[strtolower($name)] = $ab;

    $facility_abbr = static function ($f) use ($abbr_to_name, $name_to_abbr) {
        $addr = isset($f['address']) && is_array($f['address']) ? $f['address'] : array();
        $ld   = isset($f['locationDetails']) && is_array($f['locationDetails']) ? $f['locationDetails'] : array();
        foreach (array($addr['state'] ?? '', $ld['state'] ?? '') as $cand) {
            $cand = trim((string)$cand);
            if ($cand === '') continue;
            if (isset($abbr_to_name[strtoupper($cand)])) return strtoupper($cand);
            if (isset($name_to_abbr[strtolower($cand)])) return $name_to_abbr[strtolower($cand)];
        }
        $loc = (string)($f['location'] ?? '');
        if (preg_match_all('/,\s*([A-Za-z]{2})\b/', $loc, $m)) {
            $c = strtoupper(end($m[1]));
            if (isset($abbr_to_name[$c])) return $c;
        }
        foreach ($name_to_abbr as $lname => $ab) {
            if ($loc !== '' && preg_match('/\b' . preg_quote($lname, '/') . '\b/i', $loc)) return $ab;
        }
        return '';
    };

    $sets = array();  // name_key => [ABBR => true]
    $rows = $wpdb->get_results("SELECT json_data FROM facilities_master", ARRAY_A);
    foreach ((array)$rows as $row) {
        $data = kop_normalize_project_payload($row['json_data'] ?? '');
        if (!is_array($data) || empty($data['facilities']) || !is_array($data['facilities'])) continue;
        foreach ($data['facilities'] as $f) {
            if (!is_array($f)) continue;
            $ident = isset($f['identification']) && is_array($f['identification']) ? $f['identification'] : array();
            $name  = $ident['name'] ?? $ident['currentName'] ?? '';
            $nk    = kop_normalize_facility_name($name);
            if ($nk === '') continue;
            $ab = $facility_abbr($f);
            if ($ab === '') continue;
            $sets[$nk][$ab] = true;
        }
    }
    foreach ($sets as $nk => $abset) $map[$nk] = array_keys($abset);
    return $map;
}

/**
 * The single state a normalized facility name is pinned to by master data, or
 * '' if it's unknown or ambiguous. Prefix-aware: a chain whose more-specific
 * sub-programs span multiple states (e.g. "Newport Academy" → "… St. Cloud" MN,
 * "… Double Hill" CT) is treated as ambiguous so a bare chain name is never
 * relocated to one arbitrary state.
 *
 * @param string $name_key  Output of kop_normalize_facility_name().
 * @return string Single ABBREV, or ''.
 */
function kop_master_state_pin($name_key) {
    static $memo = array();
    if ($name_key === '') return '';
    if (isset($memo[$name_key])) return $memo[$name_key];

    $pin = kop_master_state_pin_raw($name_key);

    // Inspection names sometimes append the state ("CALO Programs Missouri" vs
    // master "CALO Programs"). Strip a trailing spelled-out state name and retry
    // — the appended state itself corroborates the relocation.
    if ($pin === '') {
        foreach (kop_state_name_suffixes() as $suffix) {
            if (substr($name_key, -strlen($suffix)) === $suffix) {
                $shorter = trim(substr($name_key, 0, -strlen($suffix)));
                if ($shorter !== '') {
                    $pin = kop_master_state_pin_raw($shorter);
                    if ($pin !== '') break;
                }
            }
        }
    }

    return $memo[$name_key] = $pin;
}

/** Lowercase " <state name>" suffixes, longest first ("north carolina" before "carolina"). */
function kop_state_name_suffixes() {
    static $suffixes = null;
    if ($suffixes === null) {
        $suffixes = array();
        foreach (kop_state_abbrev_to_name() as $name) $suffixes[] = ' ' . strtolower($name);
        usort($suffixes, static function ($a, $b) { return strlen($b) <=> strlen($a); });
    }
    return $suffixes;
}

/** Raw prefix-aware master pin (no state-suffix stripping). */
function kop_master_state_pin_raw($name_key) {
    if ($name_key === '') return '';
    $map = kop_master_facility_state_map();
    $states = array();
    foreach ($map[$name_key] ?? array() as $ab) $states[$ab] = true;
    $prefix = $name_key . ' ';
    foreach ($map as $k => $abs) {
        if ($k !== $name_key && strncmp($k, $prefix, strlen($prefix)) === 0) {
            foreach ($abs as $ab) $states[$ab] = true;
        }
    }
    return (count($states) === 1) ? array_key_first($states) : '';
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

    // California: CCL JSON files are the rich primary source — same data /ca-reports/ uses.
    // The DB inspection_facilities/_reports for CA are sparse and miss findings detail,
    // so for CA we deliberately skip the DB branch and let the JSON fallback run.
    $prefer_json_states = array('California');
    if (in_array($state_name, $prefer_json_states, true)) {
        $facilities_table_exists = null;
    }

    // -------- Primary source: DB --------
    if ($abbrev !== '' && $facilities_table_exists === 'inspection_facilities' && $reports_table_exists === 'inspection_reports') {
        // Place each facility on its HOME state page, not the inspecting
        // authority's. We pull every row and decide home state from master data
        // (authoritative per-facility location), relocating only on a confident,
        // address-corroborated single-state match. $inspected_by_for[id] records
        // the inspecting state when a row was pulled here from elsewhere.
        $all_rows = $wpdb->get_results(
            "SELECT id, facility_name, full_address, bed_capacity, program_category, state
             FROM inspection_facilities",
            ARRAY_A
        );
        $inspected_by_for = array();
        $facility_rows = array();
        foreach ((array)$all_rows as $r) {
            $stored = strtoupper(trim((string)($r['state'] ?? '')));
            $pin = kop_master_state_pin(kop_normalize_facility_name($r['facility_name'] ?? ''));
            $addr_state = kop_derive_state_from_address($r['full_address'] ?? '');
            // Relocate to the master-pinned state only when it differs from the
            // stored state AND the row's own address doesn't contradict it.
            $home = $stored;
            if ($pin !== '' && $pin !== $stored && ($addr_state === '' || $addr_state === $pin)) {
                $home = $pin;
            }
            if ($home !== $abbrev) continue;            // belongs to a different state's page
            if ($stored !== '' && $stored !== $abbrev) {
                $inspected_by_for[(int)$r['id']] = $stored;   // pulled in from another authority
            }
            $facility_rows[] = $r;
        }

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
            $inspection_by_fid_date = array();   // "$fid|$date" -> index into $inspections_by_facility[$fid] (for grouping single-finding rows)

            if (is_array($report_rows)) {
                foreach ($report_rows as $r) {
                    $fid = (int)$r['facility_id'];
                    if (!isset($stats_by_facility[$fid])) {
                        $stats_by_facility[$fid] = array('count' => 0, 'violations' => 0, 'latest' => '');
                        $inspections_by_facility[$fid] = array();
                    }

                    $categories = json_decode($r['categories_json'] ?? '', true);
                    if (!is_array($categories)) $categories = array();
                    $date_str = (string)($r['report_date'] ?? '');
                    $summary_text = (string)($r['summary'] ?? '');

                    // Update latest-date regardless of which branch we take below.
                    $update_latest = static function ($date_str) use (&$stats_by_facility, $fid) {
                        if (!$date_str) return;
                        $existing_ts = strtotime((string)$stats_by_facility[$fid]['latest']);
                        $new_ts = strtotime((string)$date_str);
                        if ($new_ts && (!$existing_ts || $new_ts > $existing_ts)) {
                            $stats_by_facility[$fid]['latest'] = $date_str;
                        }
                    };

                    // TX (and similar) write one DB row per deficiency, with the
                    // citation in `summary` and TX-specific category keys. Detect
                    // this so we can group rows by date into proper inspections
                    // instead of showing one "No findings" card per deficiency.
                    $has_findings_array = isset($categories['findings']) && is_array($categories['findings']) && !empty($categories['findings']);
                    $is_single_finding = !$has_findings_array && (
                        !empty($categories['Standard Number / Description']) ||
                        !empty($categories['Sections Violated']) ||
                        !empty($categories['Standard Risk Level']) ||
                        !empty($categories['Deficiency Narrative'])
                    );

                    if ($is_single_finding) {
                        $rule = trim((string)($categories['Sections Violated'] ?? ''));
                        $standard_desc = trim((string)($categories['Standard Number / Description'] ?? ''));
                        $description = $standard_desc;
                        if ($rule !== '' && $standard_desc !== '' && mb_strpos($standard_desc, $rule) === 0) {
                            $description = trim(ltrim(mb_substr($standard_desc, mb_strlen($rule)), " -–—"));
                        }
                        if ($description === '') $description = $summary_text;
                        $narrative = trim((string)($categories['Deficiency Narrative'] ?? ''));

                        $finding_entry = array('rule' => $rule, 'excerpt' => $description);
                        if ($narrative !== '')                              $finding_entry['evidence']   = $narrative;
                        if (!empty($categories['Standard Risk Level']))     $finding_entry['risk_level'] = trim((string)$categories['Standard Risk Level']);
                        if (!empty($categories['Corrected at Inspection'])) $finding_entry['corrected']  = trim((string)$categories['Corrected at Inspection']);
                        if (!empty($categories['Category']))                $finding_entry['category']   = trim((string)$categories['Category']);

                        $stats_by_facility[$fid]['violations']++;

                        $key = $fid . '|' . $date_str;
                        if (isset($inspection_by_fid_date[$key])) {
                            $idx = $inspection_by_fid_date[$key];
                            if (count($inspections_by_facility[$fid][$idx]['findings']) < 30) {
                                $inspections_by_facility[$fid][$idx]['findings'][] = $finding_entry;
                            }
                            $inspections_by_facility[$fid][$idx]['finding_count']++;
                        } else {
                            if (count($inspections_by_facility[$fid]) < 20) {
                                $idx = count($inspections_by_facility[$fid]);
                                $inspections_by_facility[$fid][] = array(
                                    'date'          => $date_str,
                                    'type'          => '',
                                    'finding_count' => 1,
                                    'findings'      => array($finding_entry),
                                    'pdf_url'       => '',
                                    'report_url'    => (string)($r['report_url'] ?? ''),
                                    'summary'       => '',
                                    'categories'    => array(),
                                );
                                $inspection_by_fid_date[$key] = $idx;
                            }
                            $stats_by_facility[$fid]['count']++;
                        }
                        $update_latest($date_str);
                        continue;
                    }

                    // Original path: this row represents an entire inspection.
                    $stats_by_facility[$fid]['count']++;

                    $finding_count = 0;
                    $findings = array();
                    $type = '';
                    $pdf_url = '';

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
                    $stats_by_facility[$fid]['violations'] += $finding_count;
                    $update_latest($date_str);

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
                $fname = trim((string)$f['facility_name']);
                // Filter out junk names so they don't surface on the state hub.
                // The underlying inspection_facilities row stays — /xx-reports/ pages still see it.
                if (kop_facility_name_looks_junky($fname)) continue;

                $fid = (int)$f['id'];
                $stats = $stats_by_facility[$fid] ?? array('count' => 0, 'violations' => 0, 'latest' => '');
                $inspected_by = $inspected_by_for[$fid] ?? '';
                $insp_records = $inspections_by_facility[$fid] ?? array();
                if ($inspected_by !== '') {
                    foreach ($insp_records as &$_ir) { $_ir['inspected_by'] = $inspected_by; }
                    unset($_ir);
                }
                $summaries[] = array(
                    'facility_name'          => $fname,
                    'inspection_address'     => trim((string)($f['full_address'] ?? '')),
                    'inspection_count'       => (int)$stats['count'],
                    'violation_count'        => (int)$stats['violations'],
                    'latest_inspection_date' => (string)$stats['latest'],
                    'capacity'               => trim((string)($f['bed_capacity'] ?? '')),
                    'census'                 => '',
                    'program_category'       => trim((string)($f['program_category'] ?? '')),
                    'inspected_by'           => $inspected_by,
                    'inspections'            => $insp_records,
                );
            }

            // Dedupe scraper-duplicate facility entries (e.g. "ADAPT - Deer Creek" and
            // "ADAPT - Deer Creek Board Chairperson: Kelsey Wood") that resolve to the
            // same normalized facility name. Use MAX of stats and a fingerprint-based
            // dedupe of inspection records (by report URL or date+type).
            $report_fingerprint = static function ($r) {
                if (!empty($r['report_url'])) return 'u:' . strtolower((string)$r['report_url']);
                if (!empty($r['pdf_url']))    return 'p:' . strtolower((string)$r['pdf_url']);
                return 'd:' . strtolower(((string)($r['date'] ?? '')) . '|' . ((string)($r['type'] ?? '')));
            };

            $deduped = array();
            foreach ($summaries as $s) {
                $key = kop_normalize_facility_name($s['facility_name']);
                if ($key === '') {
                    $deduped[] = $s;
                    continue;
                }
                if (!isset($deduped[$key])) {
                    $s['_seen'] = array();
                    foreach ($s['inspections'] as $r) {
                        $s['_seen'][$report_fingerprint($r)] = true;
                    }
                    $deduped[$key] = $s;
                    continue;
                }
                // Same facility — merge.
                $existing = &$deduped[$key];
                $existing['inspection_count'] = max((int)$existing['inspection_count'], (int)$s['inspection_count']);
                $existing['violation_count']  = max((int)$existing['violation_count'], (int)$s['violation_count']);
                if (!$existing['inspection_address'] && $s['inspection_address']) {
                    $existing['inspection_address'] = $s['inspection_address'];
                }
                $existing_ts = strtotime((string)$existing['latest_inspection_date']) ?: 0;
                $incoming_ts = strtotime((string)$s['latest_inspection_date']) ?: 0;
                if ($incoming_ts > $existing_ts) {
                    $existing['latest_inspection_date'] = $s['latest_inspection_date'];
                }
                foreach ($s['inspections'] as $r) {
                    $fp = $report_fingerprint($r);
                    if (!isset($existing['_seen'][$fp])) {
                        $existing['inspections'][] = $r;
                        $existing['_seen'][$fp] = true;
                    }
                }
                // Prefer the longer/more descriptive facility name when one is clearly junkier.
                if (mb_strlen($s['facility_name']) > mb_strlen($existing['facility_name']) &&
                    mb_strpos(kop_normalize_facility_name($s['facility_name']), $key) === 0) {
                    // Don't take a name that just appended "Board Chairperson..." — that's the case
                    // we already stripped in the key. Only take longer if it doesn't contain a colon.
                    if (mb_strpos($s['facility_name'], ':') === false) {
                        $existing['facility_name'] = $s['facility_name'];
                    }
                }
                unset($existing);
            }

            // Drop the bookkeeping field and return as a list.
            $clean = array();
            foreach ($deduped as $s) {
                unset($s['_seen']);
                $clean[] = $s;
            }
            return $clean;
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

        // Detect the two formats:
        //   A) Nested: array of facility objects, each with an `inspections` array
        //      (e.g. wa_reports.json, ut_reports.json — same shape as /xx-reports/).
        //   B) Flat:   array of report objects, each carrying its own facility_name
        //      (e.g. ccl_reports_batch_*.json — California). We need to group these
        //      by facility_name into the same shape as (A) before processing.
        $facilities = isset($data['facilities']) ? $data['facilities'] : $data;
        if (!is_array($facilities) || empty($facilities)) continue;

        $first = reset($facilities);
        $is_flat_reports = is_array($first) && !isset($first['inspections']) &&
            (isset($first['facility_name']) || isset($first['facility_number'])) &&
            (isset($first['report_date']) || isset($first['visit_date']) || isset($first['report_type']));

        if ($is_flat_reports) {
            // Group flat reports by facility_name into the nested shape.
            $grouped = array();
            foreach ($facilities as $report) {
                if (!is_array($report)) continue;
                $fname = trim((string)($report['facility_name'] ?? ''));
                if ($fname === '') continue;
                $key = strtolower($fname);
                if (!isset($grouped[$key])) {
                    $grouped[$key] = array(
                        'facility_name' => $fname,
                        'facility_address' => '',
                        'capacity' => '',
                        'census' => '',
                        'program_category' => '',
                        'latest_report_ts' => 0,
                        'inspections' => array(),
                    );
                }
                // Capture facility-level metadata from the MOST RECENT report.
                $rdate_str = (string)($report['report_date'] ?? $report['visit_date'] ?? '');
                $rts = $rdate_str ? (strtotime($rdate_str) ?: 0) : 0;
                if ($rts >= $grouped[$key]['latest_report_ts']) {
                    if (isset($report['capacity']) && $report['capacity'] !== '' && $report['capacity'] !== null) {
                        $grouped[$key]['capacity'] = (string)$report['capacity'];
                    }
                    if (isset($report['census']) && $report['census'] !== '' && $report['census'] !== null) {
                        $grouped[$key]['census'] = (string)$report['census'];
                    }
                    if (!empty($report['facility_type_name'])) {
                        $grouped[$key]['program_category'] = (string)$report['facility_type_name'];
                    }
                    $grouped[$key]['latest_report_ts'] = $rts;
                }
                // Convert one CCL report into one inspection record.
                $report_type = (string)($report['report_type'] ?? '');
                $date_str = (string)($report['report_date'] ?? $report['visit_date'] ?? '');
                $findings_text = (string)($report['investigation_findings'] ?? '');
                $complaint_status = strtolower((string)($report['complaint_status'] ?? ''));

                // Treat substantiated complaints / non-empty findings as findings; CCL doesn't
                // give a per-report finding_count for facility evaluations.
                $finding_count = 0;
                if ($complaint_status === 'substantiated' || $complaint_status === 'inconclusive') {
                    $finding_count = 1;
                }
                $findings_arr = array();
                if ($findings_text !== '') {
                    $findings_arr[] = array('rule_number' => '', 'description' => $findings_text);
                }

                $grouped[$key]['inspections'][] = array(
                    'inspection_date' => $date_str,
                    'inspection_type' => $report_type,
                    'inspection_findings' => $findings_arr,
                    'checklist_urls' => array_filter(array((string)($report['source_url'] ?? ''))),
                    // Embed remaining CCL fields in 'categories' so the state-page UI
                    // can render administrator/capacity/census/met_with/narrative
                    // when the toggle expands.
                    'categories' => array(
                        'report_type'      => $report_type,
                        'pdf_url'          => (string)($report['source_url'] ?? ''),
                        'licensee'         => (string)($report['administrator'] ?? ''),
                        'visit_date'       => (string)($report['visit_date'] ?? ''),
                        'capacity_age_range' => isset($report['capacity']) ? 'Capacity: ' . $report['capacity'] : '',
                        'average_daily_population_served' => isset($report['census']) ? (string)$report['census'] : '',
                        'corrective_actions' => (string)($report['complaint_status'] ?? ''),
                        'observations'     => (string)($report['investigation_findings'] ?? ''),
                        'finding_count'    => $finding_count,
                        'findings'         => $findings_arr,
                        'narrative'        => (string)($report['investigation_findings'] ?? ''),
                    ),
                );
            }
            $facilities = array_values($grouped);
        }

        foreach ($facilities as $facility) {
            if (!is_array($facility)) continue;

            $name = trim((string)($facility['facility_name'] ?? $facility['name'] ?? ''));
            $address = trim((string)($facility['facility_address'] ?? $facility['address'] ?? ''));
            if ($name === '') continue;
            // Same junk filter the DB path applies — drops placeholder names like
            // "Facility (Pomona, CA)", CCL metadata concatenations, pure addresses, etc.
            if (kop_facility_name_looks_junky($name)) continue;

            $facility_capacity = trim((string)($facility['capacity'] ?? ''));
            $facility_census   = trim((string)($facility['census'] ?? ''));
            $facility_program  = trim((string)($facility['program_category'] ?? ''));

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
                    $passthrough_categories = isset($insp['categories']) && is_array($insp['categories']) ? $insp['categories'] : null;
                    $insp_records[] = array(
                        'date'          => (string)$date_str,
                        'type'          => (string)($insp['inspection_type'] ?? $insp['inspection_types'] ?? ''),
                        'finding_count' => $passthrough_categories['finding_count'] ?? count($findings_raw),
                        'findings'      => $findings,
                        'pdf_url'       => $passthrough_categories['pdf_url'] ?? ($checklist_urls[0] ?? ''),
                        'report_url'    => $passthrough_categories['pdf_url'] ?? '',
                        'summary'       => '',
                        'categories'    => $passthrough_categories ?: array(),
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
                    'capacity'               => $facility_capacity,
                    'census'                 => $facility_census,
                    'program_category'       => $facility_program,
                    'inspections'            => array(),
                );
            } else {
                // Fill in capacity/census/program if not already set
                if (!$facilities_by_key[$key]['capacity'] && $facility_capacity) $facilities_by_key[$key]['capacity'] = $facility_capacity;
                if (!$facilities_by_key[$key]['census']   && $facility_census)   $facilities_by_key[$key]['census']   = $facility_census;
                if (!$facilities_by_key[$key]['program_category'] && $facility_program) $facilities_by_key[$key]['program_category'] = $facility_program;
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

    $by_key = array();
    $address_to_key = array();

    // Use the shared normalizer (also applied at the inspection-summary level).
    $normalize_key = 'kop_normalize_facility_name';

    // Look up an existing key that's an exact match OR a prefix match of length ≥ 12.
    // Handles "Adapt Deer Creek" ↔ "Adapt Deer Creek Adolescent Treatment Center".
    $find_matching_key = static function ($by_key_ref, $candidate) {
        if ($candidate === '') return null;
        if (isset($by_key_ref[$candidate])) return $candidate;
        if (mb_strlen($candidate) < 12) return null;
        foreach (array_keys($by_key_ref) as $existing) {
            if (mb_strlen($existing) < 12) continue;
            $shorter = mb_strlen($existing) < mb_strlen($candidate) ? $existing : $candidate;
            $longer  = $shorter === $existing ? $candidate : $existing;
            if (mb_strpos($longer, $shorter) === 0) {
                return $existing;
            }
        }
        return null;
    };

    // City-aware slotting: records that share a normalized facility name but sit
    // in genuinely different cities are kept as SEPARATE rows (e.g. two unrelated
    // "Hope Harbor" programs in Marshall vs Winona). kop_same_city() folds mere
    // spelling/typo/street-fragment differences so the same facility never splits.
    // find_matching_key() runs against $name_slots (name-level keys); the slot
    // resolver then maps (name key + city) to the actual $by_key row key.
    $name_slots = array();  // name_key => list of ['key'=>final_key, 'city'=>source city]
    $resolve_slot = static function ($name_key, $city) use (&$name_slots) {
        if ($name_key === '') return $name_key;
        if (!isset($name_slots[$name_key])) {
            $name_slots[$name_key] = array(array('key' => $name_key, 'city' => (string)$city));
            return $name_key;
        }
        foreach ($name_slots[$name_key] as $i => $slot) {
            if (kop_same_city($slot['city'], $city)) {
                if ($slot['city'] === '' && $city !== '') $name_slots[$name_key][$i]['city'] = (string)$city;
                return $slot['key'];
            }
        }
        $new_key = $name_key . '##' . (count($name_slots[$name_key]) + 1);
        $name_slots[$name_key][] = array('key' => $new_key, 'city' => (string)$city);
        return $new_key;
    };

    foreach ($programs as $p) {
        $candidate = $normalize_key($p['facility_name'] ?: $p['project_name']);
        if ($candidate === '') continue;
        $name_key = $find_matching_key($name_slots, $candidate) ?: $candidate;
        $key = $resolve_slot($name_key, $p['city'] ?? kop_city_from_address($p['raw_address'] ?? ''));

        // Prefer the raw imported address line whenever the structured pieces
        // don't carry a real street or city — otherwise the implode below
        // collapses to just the state abbreviation (e.g. "OK") on records
        // imported from comma-less source pages.
        $has_real_structured = !empty($p['street']) || !empty($p['city']);
        $address_parts = array_filter(array(
            $p['street'] ?? '',
            $p['city'] ?? '',
            $p['state'] ?? '',
            $p['zip'] ?? '',
        ));

        $extra_addresses = is_array($p['additional_addresses'] ?? null) ? $p['additional_addresses'] : array();

        if (isset($by_key[$key])) {
            // Existing record (e.g. another campus of the same facility) — fill in
            // missing scalar fields, and accumulate the campus's address into addresses[].
            $existing = &$by_key[$key];
            // Scalar fields: keep the first non-empty value.
            foreach (array('operator_name','type','status','operating_period','capacity','census',
                           'country','gender','current_name','current_owner') as $sk) {
                if (empty($existing[$sk]) && !empty($p[$sk])) $existing[$sk] = $p[$sk];
            }
            if (($existing['age_min'] ?? null) === null && ($p['age_min'] ?? null) !== null) $existing['age_min'] = $p['age_min'];
            if (($existing['age_max'] ?? null) === null && ($p['age_max'] ?? null) !== null) $existing['age_max'] = $p['age_max'];
            if (empty($existing['relocation']) && !empty($p['relocation'])) $existing['relocation'] = $p['relocation'];
            // Array fields: union (dedup of scalars; objects pass through).
            foreach (array('operating_notes','current_owners','past_owners','other_names','past_names',
                           'known_referrers','other_operators','administrator','notable_staff',
                           'past_tti_jobs','profile_links','accreditations_current','accreditations_past',
                           'memberships','certifications','licensing','resource_flags','notes') as $ak) {
                if (empty($p[$ak]) || !is_array($p[$ak])) continue;
                if (!isset($existing[$ak]) || !is_array($existing[$ak])) $existing[$ak] = array();
                foreach ($p[$ak] as $item) {
                    if (is_scalar($item)) {
                        if (!in_array($item, $existing[$ak], true)) $existing[$ak][] = $item;
                    } else {
                        $existing[$ak][] = $item;
                    }
                }
            }
            if (!empty($p['resource_details']) && is_array($p['resource_details'])) {
                if (!isset($existing['resource_details']) || !is_array($existing['resource_details'])) {
                    $existing['resource_details'] = array();
                }
                $existing['resource_details'] = array_merge($existing['resource_details'], $p['resource_details']);
            }

            $incoming_address = (!$has_real_structured && !empty($p['raw_address']))
                ? $p['raw_address']
                : implode(', ', $address_parts);
            $to_merge = array();
            if ($incoming_address !== '') $to_merge[] = $incoming_address;
            foreach ($extra_addresses as $extra) $to_merge[] = $extra;
            if (!empty($to_merge)) {
                if (!isset($existing['addresses']) || !is_array($existing['addresses'])) {
                    $existing['addresses'] = array();
                    if (!empty($existing['address'])) $existing['addresses'][] = $existing['address'];
                }
                foreach ($to_merge as $addr) {
                    // Dedup case-insensitively
                    $known = array_map('strtolower', $existing['addresses']);
                    if (!in_array(strtolower($addr), $known, true)) {
                        $existing['addresses'][] = $addr;
                    }
                    if (empty($existing['address'])) $existing['address'] = $addr;
                    $addr_key = kop_normalize_address_for_match($addr);
                    if ($addr_key !== '' && !isset($address_to_key[$addr_key])) {
                        $address_to_key[$addr_key] = $key;
                    }
                }
            }
            $existing['in_master'] = true;
            unset($existing);
        } else {
            $primary_address = (!$has_real_structured && !empty($p['raw_address']))
                ? $p['raw_address']
                : implode(', ', $address_parts);
            $addresses = $primary_address !== '' ? array($primary_address) : array();
            foreach ($extra_addresses as $extra) {
                if (!in_array(strtolower($extra), array_map('strtolower', $addresses), true)) {
                    $addresses[] = $extra;
                }
            }
            $display_map = kop_facility_canonical_display_map();
            $display_name = $display_map[$key] ?? ($p['facility_name'] ?: $p['project_name']);
            $by_key[$key] = array(
                'name'              => $display_name,
                'project_name'      => $p['project_name'],
                'address'           => $primary_address,
                'addresses'         => $addresses,
                'city'              => $p['city'] ?? '',
                'state'             => $p['state'] ?? $state_name,
                'country'           => $p['country'] ?? '',
                'operator_name'     => $p['operator_name'] ?? '',
                'type'              => $p['type'] ?? '',
                'status'            => $p['status'] ?? '',
                'operating_period'  => $p['operating_period'] ?? '',
                'operating_notes'   => $p['operating_notes'] ?? array(),
                'relocation'        => $p['relocation'] ?? null,
                'inspection_count'  => 0,
                'violation_count'   => 0,
                'latest_inspection_date' => '',
                'capacity'          => $p['capacity'] ?? '',
                'census'            => $p['census'] ?? '',
                'age_min'           => $p['age_min'] ?? null,
                'age_max'           => $p['age_max'] ?? null,
                'gender'            => $p['gender'] ?? '',
                'current_name'      => $p['current_name'] ?? '',
                'current_owner'     => $p['current_owner'] ?? '',
                'current_owners'    => $p['current_owners'] ?? array(),
                'past_owners'       => $p['past_owners'] ?? array(),
                'other_names'       => $p['other_names'] ?? array(),
                'past_names'        => $p['past_names'] ?? array(),
                'known_referrers'   => $p['known_referrers'] ?? array(),
                'other_operators'   => $p['other_operators'] ?? array(),
                'administrator'     => $p['administrator'] ?? array(),
                'notable_staff'     => $p['notable_staff'] ?? array(),
                'past_tti_jobs'     => $p['past_tti_jobs'] ?? array(),
                'profile_links'     => $p['profile_links'] ?? array(),
                'accreditations_current' => $p['accreditations_current'] ?? array(),
                'accreditations_past'    => $p['accreditations_past'] ?? array(),
                'memberships'       => $p['memberships'] ?? array(),
                'certifications'    => $p['certifications'] ?? array(),
                'licensing'         => $p['licensing'] ?? array(),
                'resource_flags'    => $p['resource_flags'] ?? array(),
                'resource_details'  => $p['resource_details'] ?? array(),
                'notes'             => $p['notes'] ?? array(),
                'inspections'       => array(),
                'in_master'         => true,
                'in_inspections'    => false,
            );

            foreach ($addresses as $a) {
                $addr_key = kop_normalize_address_for_match($a);
                if ($addr_key !== '' && !isset($address_to_key[$addr_key])) {
                    $address_to_key[$addr_key] = $key;
                }
            }
        }
    }

    foreach ($inspections as $insp) {
        $candidate = $normalize_key($insp['facility_name']);
        if ($candidate === '') continue;

        $insp_city = kop_city_from_address($insp['inspection_address'] ?? '');
        $name_key = $find_matching_key($name_slots, $candidate);

        if ($name_key !== null) {
            // Matched a known facility name — slot it by city so a same-named
            // program in a different city doesn't absorb this inspection.
            $key = $resolve_slot($name_key, $insp_city);
        } else {
            // Secondary merge path: inspection rows that carry loose address labels
            // should merge into the named program record at the same address.
            // $address_to_key holds final (already-slotted) row keys.
            $matched_key = null;
            $insp_addr_key = kop_normalize_address_for_match($insp['inspection_address'] ?? '');
            if ($insp_addr_key === '') {
                $insp_addr_key = kop_normalize_address_for_match($insp['facility_name'] ?? '');
            }

            if ($insp_addr_key !== '') {
                if (isset($address_to_key[$insp_addr_key])) {
                    $matched_key = $address_to_key[$insp_addr_key];
                } else {
                    // Relaxed containment check to handle minor formatting differences.
                    foreach ($address_to_key as $known_addr => $known_key) {
                        if (mb_strlen($known_addr) < 16 || mb_strlen($insp_addr_key) < 16) continue;
                        if (mb_strpos($known_addr, $insp_addr_key) !== false || mb_strpos($insp_addr_key, $known_addr) !== false) {
                            $matched_key = $known_key;
                            break;
                        }
                    }
                }
            }

            $key = $matched_key ?: $resolve_slot($candidate, $insp_city);
        }

        if (isset($by_key[$key])) {
            // Merge — accumulate counts; prefer the longer/cleaner inspection list and most recent date.
            $by_key[$key]['inspection_count'] += $insp['inspection_count'];
            $by_key[$key]['violation_count']  += $insp['violation_count'];
            $existing_ts = strtotime((string)$by_key[$key]['latest_inspection_date']) ?: 0;
            $incoming_ts = strtotime((string)$insp['latest_inspection_date']) ?: 0;
            if ($incoming_ts > $existing_ts) {
                $by_key[$key]['latest_inspection_date'] = $insp['latest_inspection_date'];
            }
            $existing_inspections = is_array($by_key[$key]['inspections'] ?? null) ? $by_key[$key]['inspections'] : array();
            $by_key[$key]['inspections'] = array_merge($existing_inspections, $insp['inspections'] ?? array());
            // Re-sort newest-first after merge.
            usort($by_key[$key]['inspections'], static function ($a, $b) {
                $ta = strtotime((string)($a['date'] ?? '')) ?: 0;
                $tb = strtotime((string)($b['date'] ?? '')) ?: 0;
                return $tb <=> $ta;
            });
            $by_key[$key]['in_inspections']   = true;
            if (!$by_key[$key]['address'] && !empty($insp['inspection_address'])) {
                $by_key[$key]['address'] = $insp['inspection_address'];
                $addr_key = kop_normalize_address_for_match($by_key[$key]['address']);
                if ($addr_key !== '' && !isset($address_to_key[$addr_key])) {
                    $address_to_key[$addr_key] = $key;
                }
            }
            // Inherit capacity/census/type from the inspection summary if the master row doesn't have them.
            if (empty($by_key[$key]['capacity']) && !empty($insp['capacity'])) {
                $by_key[$key]['capacity'] = $insp['capacity'];
            }
            if (empty($by_key[$key]['census']) && !empty($insp['census'])) {
                $by_key[$key]['census'] = $insp['census'];
            }
            if (empty($by_key[$key]['type']) && !empty($insp['program_category'])) {
                $by_key[$key]['type'] = $insp['program_category'];
            }
        } else {
            $insp_addr = (string)$insp['inspection_address'];
            $display_map = kop_facility_canonical_display_map();
            $by_key[$key] = array(
                'name'              => $display_map[$key] ?? $insp['facility_name'],
                'project_name'      => '',
                'address'           => $insp_addr,
                'addresses'         => $insp_addr !== '' ? array($insp_addr) : array(),
                'city'              => '',
                'state'             => $state_name,
                'country'           => '',
                'operator_name'     => '',
                'type'              => trim((string)($insp['program_category'] ?? '')),
                'status'            => '',
                'operating_period'  => '',
                'operating_notes'   => array(),
                'inspection_count'  => $insp['inspection_count'],
                'violation_count'   => $insp['violation_count'],
                'latest_inspection_date' => $insp['latest_inspection_date'],
                'capacity'          => trim((string)($insp['capacity'] ?? '')),
                'census'            => trim((string)($insp['census'] ?? '')),
                'age_min'           => null,
                'age_max'           => null,
                'gender'            => '',
                'current_name'      => '',
                'current_owner'     => '',
                'current_owners'    => array(),
                'past_owners'       => array(),
                'other_names'       => array(),
                'past_names'        => array(),
                'known_referrers'   => array(),
                'other_operators'   => array(),
                'administrator'     => array(),
                'notable_staff'     => array(),
                'past_tti_jobs'     => array(),
                'profile_links'     => array(),
                'accreditations_current' => array(),
                'accreditations_past'    => array(),
                'memberships'       => array(),
                'certifications'    => array(),
                'licensing'         => array(),
                'resource_flags'    => array(),
                'resource_details'  => array(),
                'notes'             => array(),
                'inspections'       => $insp['inspections'] ?? array(),
                'in_master'         => false,
                'in_inspections'    => true,
            );

            $addr_key = kop_normalize_address_for_match($by_key[$key]['address']);
            if ($addr_key !== '' && !isset($address_to_key[$addr_key])) {
                $address_to_key[$addr_key] = $key;
            }
        }
    }

    foreach ($by_key as $facility) {
        // Final junk-name guard, applied regardless of source (master programs
        // aren't filtered upstream the way inspection rows are) — drops bare
        // jurisdiction/placeholder rows like "OREGON".
        if (kop_facility_name_looks_junky($facility['name'] ?? '')) continue;
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
        $row['facilities_mentioned'] = kop_normalize_facility_mentions($row['facilities_mentioned'] ?? '[]');
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
                        'dataset_urls' => $inspection_datasets,
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
