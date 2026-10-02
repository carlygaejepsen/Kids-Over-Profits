<?php
/**
 * The facility directory's feed in two steps (/tti-program-index/, both tabs).
 *
 * kop/v1/facilities?view=index   every company and place with each facility cut
 *                                to what the lists show and search (names, place,
 *                                years, status, report counts): ~1/10 the size.
 * kop/v1/facilities?view=detail&key[]=<project key>
 *                                those projects in full, read when a company or
 *                                place is opened.
 *
 * The full feed takes the server ~2 s to assemble, so the build is split into
 * files under uploads/kop-cache/directory/<fingerprint>/ and served from there
 * until the fingerprint moves. Plain kop/v1/facilities is unchanged.
 */

if (!defined('ABSPATH')) {
    exit;
}

/** Categories the directory reads: companies (company tab), locations (location tab). */
function kop_directory_feed_wanted($project) {
    if (!is_array($project)) return false;
    $category = isset($project['category']) && is_string($project['category']) ? strtolower($project['category']) : '';
    return in_array($category, array('', 'companies', 'company', 'operators', 'operator', 'locations', 'location'), true);
}

/**
 * Moves when anything the feed shows changes: the state feed's fingerprint
 * (facilities, links, news, lawsuits, memorials, inspections, theme files)
 * plus the operator records and the legacy tables the feed still reads.
 * A build older than KOP_DIRECTORY_FEED_MAX_AGE is made again anyway, for
 * anything it misses.
 */
function kop_directory_feed_fingerprint() {
    static $key = null;
    if ($key !== null) return $key;
    global $wpdb;
    $parts = array(function_exists('kop_state_feed_fingerprint') ? kop_state_feed_fingerprint() : '-');
    foreach (array($wpdb->prefix . 'kop_operators' => 'COUNT(*), MAX(updated_at)', 'referrers_master' => 'COUNT(*)', 'transporters_master' => 'COUNT(*)') as $table => $select) {
        $row = $wpdb->get_row("SELECT {$select} FROM `{$table}`", ARRAY_N);
        $parts[] = $table . ':' . (is_array($row) ? implode('|', array_map('strval', $row)) : '-');
    }
    $parts[] = 'v2:' . ((function_exists('kop_v2_active') && kop_v2_active('program_index')) ? '1' : '0');
    $parts[] = 'v:1';
    return $key = substr(md5(implode(';', $parts)), 0, 16);
}

function kop_directory_feed_cache_dir() {
    $uploads = function_exists('wp_upload_dir') ? wp_upload_dir(null, false) : array();
    $base = !empty($uploads['basedir']) ? $uploads['basedir'] : (WP_CONTENT_DIR . '/uploads');
    return rtrim($base, '/\\') . '/kop-cache/directory';
}

if (!defined('KOP_DIRECTORY_FEED_MAX_AGE')) {
    define('KOP_DIRECTORY_FEED_MAX_AGE', 6 * 3600);
}

/** File name for one project's full record. */
function kop_directory_feed_project_file($key) {
    return 'p-' . md5((string) $key) . '.json';
}

/** Copy only $keys (dot paths) of $src that hold something. */
function kop_directory_feed_pick($src, array $keys) {
    $out = array();
    if (!is_array($src)) return $out;
    foreach ($keys as $key) {
        if (array_key_exists($key, $src) && $src[$key] !== null && $src[$key] !== '' && $src[$key] !== array()) {
            $out[$key] = $src[$key];
        }
    }
    return $out;
}

/**
 * One facility cut to what the lists read: the name fields (getFacilityDisplayName,
 * the Formerly / Also known as / owner lines the search and the old-name rows use),
 * the place (getMergedLocation), years and status, the inspection counts behind the
 * sort and the violations filter, and the ids the tabs merge copies by.
 */
function kop_directory_feed_slim_facility($facility) {
    if (!is_array($facility)) return $facility;
    $place = array('street', 'city', 'state', 'zip', 'zipcode', 'country');
    $out = kop_directory_feed_pick($facility, array(
        'facility_id', 'facilityId', 'name', 'programName', 'facilityName', 'title', 'program_name', 'facility_name',
        'pastNames', 'formerNames', 'otherNames', 'currentOperator', 'current_operator',
        'currentOwners', 'current_owners', 'currentOwner', 'current_owner',
        'previousOwners', 'previous_owners', 'previousOwner', 'previous_owner', 'formerOwners', 'former_owners', 'formerOwner', 'former_owner',
        'fullAddress', 'full_address', 'address', 'location', 'cityState', 'city_state', 'hq_location', 'headquarters',
        'streetAddress', 'street_address', 'street', 'city', 'locationCity', 'location_city', 'headquartersCity', 'hq_city',
        'state', 'locationState', 'location_state', 'headquartersState', 'hq_state', 'province',
        'postalCode', 'postal_code', 'zip', 'zipcode', 'country',
        'yearsOfOperation', 'yearsActive', 'years_active', 'operating_period_text',
        'founded', 'yearFounded', 'opened', 'startYear', 'year_founded', 'start_year',
        'isPrivatelyOwned',
    ));
    if (isset($facility['identification']) && is_array($facility['identification'])) {
        $ident = kop_directory_feed_pick($facility['identification'], array(
            'name', 'currentName', 'facility_id', 'facilityId', 'pastNames', 'formerNames', 'otherNames', 'currentOperator', 'current_operator',
            'currentOwners', 'current_owners', 'currentOwner', 'current_owner',
            'previousOwners', 'previous_owners', 'previousOwner', 'previous_owner', 'formerOwners', 'former_owners', 'formerOwner', 'former_owner',
        ));
        if ($ident) $out['identification'] = $ident;
    }
    foreach (array('addressParts', 'address_parts', 'locationDetails', 'location_details') as $k) {
        if (isset($facility[$k]) && is_array($facility[$k])) {
            $picked = kop_directory_feed_pick($facility[$k], $place);
            if ($picked) $out[$k] = $picked;
        }
    }
    if (isset($facility['operatingPeriod']) && is_array($facility['operatingPeriod'])) {
        $out['operatingPeriod'] = kop_directory_feed_pick($facility['operatingPeriod'], array('status', 'yearsOfOperation', 'startYear', 'start_year', 'endYear', 'end_year'));
    }
    if (isset($facility['inspection_stats']) && is_array($facility['inspection_stats'])) {
        $out['inspection_stats'] = kop_directory_feed_pick($facility['inspection_stats'], array('report_count', 'licensing_action'));
    }
    if (!empty($facility['resources']['hasViolations'])) {
        $out['resources'] = array('hasViolations' => true);
    }
    return $out;
}

/** A project for the index: its keys, the operator record, slim facilities. */
function kop_directory_feed_slim_project($project) {
    $out = kop_directory_feed_pick($project, array('id', 'name', 'label', 'category'));
    $data = (isset($project['data']) && is_array($project['data'])) ? $project['data'] : array();
    $slim = array();
    if (isset($data['operator'])) $slim['operator'] = $data['operator'];
    if (isset($data['documentFolderId'])) $slim['documentFolderId'] = $data['documentFolderId'];
    if (isset($data['category'])) $slim['category'] = $data['category'];
    $slim['facilities'] = array_map('kop_directory_feed_slim_facility', isset($data['facilities']) && is_array($data['facilities']) ? array_values($data['facilities']) : array());
    $out['data'] = $slim;
    return $out;
}

/** The full feed the directory reads, before it is split. */
function kop_directory_feed_build_full() {
    return (function_exists('kop_v2_active') && kop_v2_active('program_index'))
        ? kop_v2_get_facilities_projects()
        : kop_get_facilities_projects_from_database();
}

/**
 * Build (or read) the current split. Returns the directory holding index.json
 * and one p-<md5 key>.json per project, or a WP_Error.
 */
function kop_directory_feed_ensure() {
    $root = kop_directory_feed_cache_dir();
    $dir = $root . '/' . kop_directory_feed_fingerprint();
    if (is_readable($dir . '/index.json') && (int) @filemtime($dir . '/index.json') > time() - KOP_DIRECTORY_FEED_MAX_AGE) {
        return $dir;
    }

    $full = kop_directory_feed_build_full();
    if (is_wp_error($full)) return $full;
    $projects = (is_array($full) && isset($full['projects']) && is_array($full['projects'])) ? $full['projects'] : array();

    if (!is_dir($dir) && !wp_mkdir_p($dir)) {
        return new WP_Error('kop_directory_cache', 'The directory cache folder could not be created.', array('status' => 500));
    }
    $write = function ($name, $data) use ($dir) {
        $target = $dir . '/' . $name;
        $tmp = $target . '.' . getmypid() . '.tmp';
        if (@file_put_contents($tmp, wp_json_encode($data)) !== false) {
            @rename($tmp, $target);
        } else {
            @unlink($tmp);
        }
    };
    $index = array();
    foreach ($projects as $key => $project) {
        if (!kop_directory_feed_wanted($project)) continue;
        $write(kop_directory_feed_project_file($key), $project);
        $index[$key] = kop_directory_feed_slim_project($project);
    }
    // index.json last: its presence means the set is complete.
    $write('index.json', array('source' => isset($full['source']) ? $full['source'] : 'database', 'view' => 'index', 'projects' => (object) $index));

    // Earlier builds: kept an hour for pages still open on them, then removed.
    foreach ((array) glob($root . '/*', GLOB_ONLYDIR) as $old) {
        if ($old === $dir || (int) @filemtime($old) > time() - HOUR_IN_SECONDS) continue;
        foreach ((array) glob($old . '/*') as $file) @unlink($file);
        @rmdir($old);
    }
    return $dir;
}

/**
 * The response for ?view=index or ?view=detail. ?model=v2 previews are built
 * fresh and never cached (they read the other data model).
 */
function kop_directory_feed_response($view, array $keys) {
    $fresh = function_exists('kop_state_feed_requested_fresh') && kop_state_feed_requested_fresh();
    if ($fresh) {
        $full = kop_directory_feed_build_full();
        if (is_wp_error($full)) return $full;
        $out = array();
        foreach ((array) $full['projects'] as $key => $project) {
            if (!kop_directory_feed_wanted($project)) continue;
            if ($view === 'index') $out[$key] = kop_directory_feed_slim_project($project);
            elseif (in_array((string) $key, $keys, true)) $out[$key] = $project;
        }
        return array('source' => $full['source'] ?? 'database', 'view' => $view, 'projects' => (object) $out);
    }

    $dir = kop_directory_feed_ensure();
    if (is_wp_error($dir)) return $dir;
    if ($view === 'index') {
        $data = json_decode((string) @file_get_contents($dir . '/index.json'), true);
        return is_array($data) ? $data : new WP_Error('kop_directory_cache', 'The directory index could not be read.', array('status' => 500));
    }
    $out = array();
    foreach (array_slice($keys, 0, 20) as $key) {
        $file = $dir . '/' . kop_directory_feed_project_file($key);
        if (!is_readable($file)) continue;
        $project = json_decode((string) file_get_contents($file), true);
        if (is_array($project)) $out[$key] = $project;
    }
    return array('view' => 'detail', 'projects' => (object) $out);
}

// Built hourly by WP-Cron, so a visitor rarely waits the ~7 s a build takes
// after the records change.
if (function_exists('add_action')) {
    add_action('init', static function () {
        if (function_exists('wp_next_scheduled') && !wp_next_scheduled('kop_directory_feed_hourly')) {
            wp_schedule_event(time() + 600, 'hourly', 'kop_directory_feed_hourly');
        }
    });
    add_action('kop_directory_feed_hourly', static function () {
        if (get_transient('kop_directory_feed_lock')) return;
        set_transient('kop_directory_feed_lock', 1, 10 * MINUTE_IN_SECONDS);
        kop_directory_feed_ensure();
        delete_transient('kop_directory_feed_lock');
    });
}
