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

    // Register FileBird folders endpoint (public)
    register_rest_route(
        'kop/v1',
        '/folders',
        array(
            'methods' => WP_REST_Server::READABLE,
            'callback' => function () {
                $folders = kop_get_filebird_folders();
                return rest_ensure_response($folders);
            },
            'permission_callback' => '__return_true',
        )
    );

    // Register FileBird folder content endpoint (public)
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
                
                $attachments = kop_get_folder_attachments($folder_id);
                
                // Format attachments for frontend
                $formatted = array_map(function($post) {
                    return array(
                        'id' => $post->ID,
                        'title' => $post->post_title,
                        'url' => wp_get_attachment_url($post->ID),
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
                
                // Render the FileBird shortcode using the project's custom wrapper
                $shortcode = '[filebird_folder folder_id="' . intval($folder_id) . '"]';
                $html = do_shortcode($shortcode);
                
                // If the shortcode wasn't processed, it returns the original string.
                if ($html === $shortcode) {
                    return rest_ensure_response(array('html' => ''));
                }
                
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
