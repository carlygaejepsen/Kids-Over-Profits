<?php
/**
 * Database connection and data retrieval functions.
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('kop_unwrap_project_payload')) {
    /**
     * Normalize project payloads that may be double-wrapped in `data`.
     *
     * @param mixed $payload Decoded JSON payload from the database.
     * @return mixed Normalized payload.
     */
    function kop_unwrap_project_payload($payload) {
        if (!is_array($payload)) {
            return $payload;
        }

        if (isset($payload['data']) && is_array($payload['data'])) {
            $inner = $payload['data'];
            $guard = 0;

            while (is_array($inner)
                && !isset($inner['operator'])
                && !isset($inner['facilities'])
                && isset($inner['data'])
                && is_array($inner['data'])
                && $guard < 2
            ) {
                $inner = $inner['data'];
                $guard += 1;
            }

            $payload['data'] = $inner;
        }

        return $payload;
    }
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
/**
 * Bulk-fetch linked news for projects in the given array.
 *
 * Each project that has a facilities_master id gets a `linked_news[]` field.
 * Additionally, nested facility entries inside data.facilities[] get their own
 * `facility_id` stamped (by case-insensitive name match against facilities_master)
 * and a `linked_news[]` attached - so the program-index cards can show
 * per-nested-facility article links.
 *
 * Articles with status NOT in ('approved','published') are skipped so the
 * public REST endpoint doesn't leak drafts or rejected items.
 */
function kop_attach_linked_news_to_projects($db_connection, array &$projects) {
    if (!($db_connection instanceof wpdb)) return;

    // Verify the link table exists before querying (graceful fallback for envs
    // that haven't run init-submissions-db.php yet).
    $link_table = $db_connection->get_var($db_connection->prepare('SHOW TABLES LIKE %s', 'news_facility_links'));
    if ($link_table !== 'news_facility_links') return;

    // Build a case-insensitive name -> facility_id map from EVERY facilities_master
    // row, including __facility_ref identity rows that were skipped earlier.
    // Promoted rows are what most nested facility entries match against.
    $name_to_id = array();
    $name_rows = $db_connection->get_results("SELECT id, unique_name FROM facilities_master", ARRAY_A);
    if (is_array($name_rows)) {
        foreach ($name_rows as $r) {
            $k = strtolower(trim((string)$r['unique_name']));
            if ($k !== '' && !isset($name_to_id[$k])) {
                $name_to_id[$k] = (int)$r['id'];
            }
        }
    }

    // Collect every facility_id that we want news for:
    //   - top-level project ids (facilities_master rows themselves)
    //   - nested facility entries, by name match against $name_to_id
    $facility_ids = array();

    foreach ($projects as $key => &$project) {
        if (!empty($project['id']) && (($project['source_table'] ?? '') === 'facilities_master')) {
            $facility_ids[(int)$project['id']] = true;
        }
        if (isset($project['data']['facilities']) && is_array($project['data']['facilities'])) {
            foreach ($project['data']['facilities'] as $i => $nested) {
                if (!is_array($nested)) continue;
                $fid = null;
                if (!empty($nested['facility_id'])) {
                    $fid = (int)$nested['facility_id'];
                } else {
                    $candidate_names = array();
                    if (!empty($nested['identification']['name'])) $candidate_names[] = $nested['identification']['name'];
                    if (!empty($nested['identification']['currentName'])) $candidate_names[] = $nested['identification']['currentName'];
                    if (!empty($nested['name'])) $candidate_names[] = $nested['name'];
                    foreach ($candidate_names as $cn) {
                        $cn_key = strtolower(trim((string)$cn));
                        if ($cn_key !== '' && isset($name_to_id[$cn_key])) {
                            $fid = $name_to_id[$cn_key];
                            // Stamp it back onto the nested entry for downstream consumers.
                            $project['data']['facilities'][$i]['facility_id'] = $fid;
                            break;
                        }
                    }
                }
                if ($fid !== null && $fid > 0) {
                    $facility_ids[$fid] = true;
                }
            }
        }
    }
    unset($project);

    if (empty($facility_ids)) return;

    $ids = array_keys($facility_ids);
    $placeholders = implode(',', array_fill(0, count($ids), '%d'));

    $sql = "SELECT l.facility_id, l.link_type,
                   n.id AS news_id, n.article_title, n.alternate_title,
                   n.publication_name, n.publication_date, n.article_url,
                   n.article_type, n.summary, n.status
            FROM news_facility_links l
            JOIN news_submissions n ON n.id = l.news_id
            WHERE l.facility_id IN ($placeholders)
              AND n.status IN ('approved','published')
            ORDER BY n.publication_date DESC, n.id DESC";

    $rows = $db_connection->get_results($db_connection->prepare($sql, $ids), ARRAY_A);
    if (!is_array($rows)) return;

    $by_facility = array();
    foreach ($rows as $r) {
        $fid = (int)$r['facility_id'];
        if (!isset($by_facility[$fid])) $by_facility[$fid] = array();
        $by_facility[$fid][] = array(
            'id'                => (int)$r['news_id'],
            'article_title'     => $r['article_title'],
            'display_title'     => !empty($r['alternate_title']) ? $r['alternate_title'] : $r['article_title'],
            'publication_name'  => $r['publication_name'],
            'publication_date'  => $r['publication_date'],
            'article_url'       => $r['article_url'],
            'article_type'      => $r['article_type'],
            'summary'           => $r['summary'],
            'link_type'         => $r['link_type'],
        );
    }

    // Attach to top-level projects.
    foreach ($projects as &$project) {
        if (!empty($project['id']) && (($project['source_table'] ?? '') === 'facilities_master')) {
            $project['linked_news'] = isset($by_facility[(int)$project['id']])
                ? $by_facility[(int)$project['id']]
                : array();
        }
        // Attach to nested facility entries.
        if (isset($project['data']['facilities']) && is_array($project['data']['facilities'])) {
            foreach ($project['data']['facilities'] as $i => $nested) {
                if (!is_array($nested) || empty($nested['facility_id'])) continue;
                $fid = (int)$nested['facility_id'];
                if (isset($by_facility[$fid])) {
                    $project['data']['facilities'][$i]['linked_news'] = $by_facility[$fid];
                }
            }
        }
    }
    unset($project);
}

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

    // All master tables to query
    $master_tables = array(
        'facilities_master',
        'referrers_master',
        'transporters_master',
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

        // Pull the auto-increment primary key when present so REST consumers can
        // join against news_facility_links / facility_sites by stable id.
        if (in_array('id', $available_columns, true)) {
            $select_parts[] = '`id` AS project_id';
        }

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

            $decoded = kop_unwrap_project_payload($decoded);

            // Skip hidden identity rows created by promote-facilities-to-rows.php.
            // These exist solely to give nested facilities a stable id for
            // foreign-key relationships (e.g., news_facility_links). They aren't
            // standalone projects and shouldn't appear in the directory.
            if (!empty($decoded['__facility_ref'])) {
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

            $project_id = isset($row['project_id']) && $row['project_id'] !== null ? (int)$row['project_id'] : null;

            if ($is_new_format) {
                // NEW format: data is already nested correctly, pass through as-is
                $projects[$unique_name] = array(
                    'id' => $project_id,
                    'source_table' => $table_base,
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
                    'id' => $project_id,
                    'source_table' => $table_base,
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

    // Attach linked news per project (only for facilities_master rows). One bulk
    // query joins news_facility_links + news_submissions, then we group by
    // facility_id and stamp linked_news[] onto each project.
    kop_attach_linked_news_to_projects($db_connection, $projects);

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

    // FileBird stores folders in a custom table
    $table_name = $wpdb->prefix . 'fbv';

    // Check if FileBird table exists
    if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") != $table_name) {
        return array();
    }

    $folders = $wpdb->get_results(
        "SELECT id, name, parent FROM $table_name WHERE type = 0 ORDER BY name ASC"
    );

    return $folders;
}

/**
 * Get a FileBird folder ID plus the IDs of every folder nested beneath it.
 *
 * FileBird stores the tree in the `fbv` table via a `parent` column. Documents
 * are only ever attached to the exact folder they were dropped into, so to show
 * "everything" under a folder we must walk the whole subtree.
 *
 * @param int $folder_id Root folder.
 * @return int[] Folder IDs including the root (deduped).
 */
function kop_get_folder_descendant_ids($folder_id) {
    global $wpdb;

    $folder_id = (int) $folder_id;
    if ($folder_id <= 0) {
        return array();
    }

    $folder_table = $wpdb->prefix . 'fbv';
    if ($wpdb->get_var("SHOW TABLES LIKE '$folder_table'") != $folder_table) {
        return array($folder_id);
    }

    // Pull the full (id, parent) map once, then walk it in PHP. Cheaper and
    // simpler than a recursive SQL CTE and works on older MySQL too.
    $rows = $wpdb->get_results("SELECT id, parent FROM $folder_table WHERE type = 0");

    $children = array();
    foreach ($rows as $row) {
        $children[(int) $row->parent][] = (int) $row->id;
    }

    $ids = array();
    $queue = array($folder_id);
    while (!empty($queue)) {
        $current = array_shift($queue);
        if (isset($ids[$current])) {
            continue; // guard against cycles
        }
        $ids[$current] = true;
        if (!empty($children[$current])) {
            foreach ($children[$current] as $child) {
                $queue[] = $child;
            }
        }
    }

    return array_keys($ids);
}

/**
 * Expand a set of root folders to the IDs of those roots plus every folder
 * nested beneath each of them.
 *
 * @param int[] $root_ids Root folders.
 * @return int[] Deduped folder IDs (roots + descendants).
 */
function kop_get_descendant_ids_for_roots($root_ids) {
    $all = array();
    foreach ((array) $root_ids as $root) {
        foreach (kop_get_folder_descendant_ids($root) as $id) {
            $all[(int) $id] = true;
        }
    }
    return array_keys($all);
}

/**
 * Find every folder that shares the given folder's exact name.
 *
 * FileBird allows the same facility to appear as multiple folders in different
 * organizational trees (e.g. "Spring Ridge Academy" exists at the root and again
 * under "Closed Programs" and "Investigations"). When showing a facility's
 * documents we want to merge all of them, otherwise files filed under a
 * duplicate-named sibling tree are silently missed.
 *
 * @param int $folder_id Any one of the same-named folders.
 * @return int[] All folder IDs sharing that name (including the input), deduped.
 */
function kop_get_same_name_folder_ids($folder_id) {
    global $wpdb;

    $folder_id = (int) $folder_id;
    if ($folder_id <= 0) {
        return array();
    }

    $folder_table = $wpdb->prefix . 'fbv';
    if ($wpdb->get_var("SHOW TABLES LIKE '$folder_table'") != $folder_table) {
        return array($folder_id);
    }

    $name = $wpdb->get_var(
        $wpdb->prepare("SELECT name FROM $folder_table WHERE id = %d AND type = 0", $folder_id)
    );
    if ($name === null || trim($name) === '') {
        return array($folder_id);
    }

    $ids = $wpdb->get_col(
        $wpdb->prepare(
            "SELECT id FROM $folder_table WHERE type = 0 AND LOWER(TRIM(name)) = LOWER(TRIM(%s))",
            $name
        )
    );
    $ids = array_map('intval', (array) $ids);
    if (!in_array($folder_id, $ids, true)) {
        $ids[] = $folder_id;
    }

    return array_values(array_unique($ids));
}

/**
 * Get attachments from a FileBird folder and all of its nested subfolders.
 */
function kop_get_folder_attachments($folder_id) {
    // Include the folder itself plus every subfolder beneath it.
    return kop_get_attachments_in_folder_ids(kop_get_folder_descendant_ids($folder_id));
}

/**
 * Get attachments that live directly in any of the supplied folder IDs.
 *
 * Callers are expected to have already expanded subtrees (see
 * kop_get_folder_descendant_ids / kop_get_descendant_ids_for_roots) — this
 * function does NOT walk the tree itself.
 *
 * @param int[] $folder_ids Folder IDs to pull attachments from.
 * @return WP_Post[]
 */
function kop_get_attachments_in_folder_ids($folder_ids) {
    global $wpdb;

    $attachment_table = $wpdb->prefix . 'fbv_attachment_folder';

    // Check if FileBird attachment table exists
    if ($wpdb->get_var("SHOW TABLES LIKE '$attachment_table'") != $attachment_table) {
        return array();
    }

    $folder_ids = array_values(array_unique(array_map('intval', (array) $folder_ids)));
    if (empty($folder_ids)) {
        return array();
    }

    $placeholders = implode(',', array_fill(0, count($folder_ids), '%d'));

    // Get attachment IDs from the folder and all of its descendants
    $attachment_ids = $wpdb->get_col(
        $wpdb->prepare(
            "SELECT DISTINCT attachment_id FROM $attachment_table WHERE folder_id IN ($placeholders)",
            $folder_ids
        )
    );

    if (empty($attachment_ids)) {
        return array();
    }

    // Get attachment details
    $args = array(
        'post_type' => 'attachment',
        'post_status' => 'inherit',
        'posts_per_page' => -1,
        'post__in' => $attachment_ids,
        'orderby' => 'title',
        'order' => 'ASC'
    );

    return get_posts($args);
}

/**
 * Direct child folders (one level down) of the given parent folders.
 *
 * @param int[] $parent_ids
 * @return int[] Child folder IDs.
 */
function kop_get_child_folder_ids($parent_ids) {
    global $wpdb;

    $parent_ids = array_values(array_unique(array_map('intval', (array) $parent_ids)));
    if (empty($parent_ids)) {
        return array();
    }

    $folder_table = $wpdb->prefix . 'fbv';
    if ($wpdb->get_var("SHOW TABLES LIKE '$folder_table'") != $folder_table) {
        return array();
    }

    $placeholders = implode(',', array_fill(0, count($parent_ids), '%d'));
    $ids = $wpdb->get_col(
        $wpdb->prepare(
            "SELECT id FROM $folder_table WHERE type = 0 AND parent IN ($placeholders)",
            $parent_ids
        )
    );

    return array_map('intval', (array) $ids);
}

/**
 * Recursively build a nested document tree for a set of sibling folders,
 * collapsing folders that share the same name into a single node. This keeps
 * the duplicate facility trees (see kop_get_same_name_folder_ids) from
 * producing repeated "Sweidy v. SRA" style headings.
 *
 * Each node: array(
 *   'name'        => string,
 *   'attachments' => WP_Post[]  // files filed directly in this (merged) folder
 *   'children'    => node[]      // nested subfolders
 * )
 *
 * @param int[] $folder_ids Sibling folders to merge/render at this level.
 * @param int   $depth      Recursion guard.
 * @return array node[]
 */
function kop_build_facility_doc_tree($folder_ids, $depth = 0) {
    if ($depth > 8) {
        return array();
    }

    global $wpdb;
    $folder_ids = array_values(array_unique(array_map('intval', (array) $folder_ids)));
    if (empty($folder_ids)) {
        return array();
    }

    $folder_table = $wpdb->prefix . 'fbv';
    if ($wpdb->get_var("SHOW TABLES LIKE '$folder_table'") != $folder_table) {
        return array();
    }

    $placeholders = implode(',', array_fill(0, count($folder_ids), '%d'));
    $rows = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT id, name FROM $folder_table WHERE type = 0 AND id IN ($placeholders)",
            $folder_ids
        )
    );

    // Group sibling folders by normalized name so duplicates merge into one node.
    $groups = array();
    foreach ($rows as $row) {
        $key = strtolower(trim($row->name));
        if (!isset($groups[$key])) {
            $groups[$key] = array('name' => $row->name, 'ids' => array());
        }
        $groups[$key]['ids'][] = (int) $row->id;
    }

    $nodes = array();
    foreach ($groups as $group) {
        $attachments = kop_get_attachments_in_folder_ids($group['ids']); // direct only
        $children = kop_build_facility_doc_tree(kop_get_child_folder_ids($group['ids']), $depth + 1);
        if (empty($attachments) && empty($children)) {
            continue; // prune empty branches
        }
        $nodes[] = array(
            'name' => $group['name'],
            'attachments' => $attachments,
            'children' => $children,
        );
    }

    usort($nodes, function ($a, $b) {
        return strcasecmp($a['name'], $b['name']);
    });

    return $nodes;
}

/**
 * Top-level facility document tree: the direct files of the (optionally
 * name-merged) root folders, plus their nested subfolders.
 *
 * @param int  $folder_id        A folder the facility is matched to.
 * @param bool $merge_same_name  Merge duplicate folders sharing the name.
 * @return array array with keys 'files' (WP_Post[] filed directly on the roots)
 *               and 'subfolders' (node[] from kop_build_facility_doc_tree).
 */
function kop_get_facility_doc_tree($folder_id, $merge_same_name = true) {
    $roots = ($merge_same_name && function_exists('kop_get_same_name_folder_ids'))
        ? kop_get_same_name_folder_ids($folder_id)
        : array((int) $folder_id);
    if (empty($roots)) {
        $roots = array((int) $folder_id);
    }

    return array(
        'files' => kop_get_attachments_in_folder_ids($roots), // files filed on the roots themselves
        'subfolders' => kop_build_facility_doc_tree(kop_get_child_folder_ids($roots)),
    );
}

/**
 * Count every file in a facility doc tree's subfolder node list (recursive).
 *
 * @param array $nodes node[] from kop_build_facility_doc_tree
 * @return int
 */
function kop_count_facility_doc_tree($nodes) {
    $total = 0;
    foreach ((array) $nodes as $node) {
        $total += count($node['attachments']);
        $total += kop_count_facility_doc_tree($node['children']);
    }
    return $total;
}
