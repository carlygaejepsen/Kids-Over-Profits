<?php
/**
 * Woodbury Reports: create the record a report is about, then file the pages
 * under it, from the review screen (inc/woodbury-mentions.php).
 *
 * About 230 Woodbury articles are about something with no record yet, and
 * not all of them are programs. Four kinds can be created here, each through
 * the same store the data form writes:
 *
 *   facility    facilities_v2 (kop_facility_save), a /facility/ page
 *   company     {prefix}kop_operators (kop_v2_save_form_project), an
 *               /operator/ page whose "Documents on file" is its folder
 *   consultant  referrers_master, mirrored into the state's locations_master
 *               row as api/save-master.php does
 *   provider    providers_master, one project per provider
 *   transporter transporters_master, a youth transport company
 *
 * Every create refuses a name that is already a record, and a facility also
 * refuses a close match (kop_facdisc_near_duplicate) unless "It is a
 * different place" is ticked. The record cites the Woodbury issue as its
 * source. Undo on the screen takes the filed pages back out; it never deletes
 * the record.
 *
 * Companies, consultants and providers get a FileBird folder named after
 * them (companies at the top level, as the existing company folders are;
 * consultants under "Educational Consultants", providers under "Mental
 * Health Providers", transporters under "Transporters") and the Woodbury
 * pages go in a subfolder of it. Only the
 * company folder shows on a public page today.
 */

if (!defined('ABSPATH')) {
    exit;
}

/** The kinds this screen can create, with their labels. */
function kop_wbc_kinds() {
    return array(
        'facility'   => 'Program (TTI facility)',
        'company'    => 'Company (operator)',
        'consultant' => 'Educational consultant',
        'provider'   => 'Mental health provider',
        'transporter' => 'Transporter (youth transport)',
    );
}

/** "TELOS RESIDENTIAL TREATMENT, LLC" -> "Telos Residential Treatment, LLC". */
function kop_wbc_title_case($name) {
    $name = str_replace(array("\u{2010}", "\u{2011}", "\u{2013}"), '-', (string) $name);
    $name = trim(preg_replace('/\s+/', ' ', $name), " \t\"'");
    if ($name === '' || $name !== strtoupper($name)) {
        return $name;
    }
    $keep = array('LLC', 'LLP', 'PC', 'PLLC', 'RTC', 'USA', 'II', 'III', 'IV', 'ASAP', 'JCB');
    $small = array('of', 'the', 'and', 'for', 'at', 'in', 'on', 'a', 'an', 'to', 'by');
    $words = explode(' ', strtolower($name));
    foreach ($words as $i => $w) {
        $bare = strtoupper(rtrim($w, ',.'));
        if (in_array($bare, $keep, true)) {
            $words[$i] = strtoupper($w);
        } elseif ($i > 0 && in_array($w, $small, true)) {
            $words[$i] = $w;
        } else {
            $words[$i] = preg_replace_callback('/(^|[-\/(])([a-z])/', function ($m) { return $m[1] . strtoupper($m[2]); }, $w);
        }
    }
    return implode(' ', $words);
}

/**
 * Name, city and state the form starts from: the article's header and place
 * ("Orem, Utah"; "Durham, NC and Richmond, VA" takes the first).
 */
function kop_wbc_prefill(array $r) {
    $name = $r['header'] !== '' ? $r['header'] : ($r['facility_name'] !== '' ? $r['facility_name'] : $r['matched_name']);
    $city = '';
    $state = '';
    $country = '';
    $place = trim((string) $r['place']);
    if ($place !== '') {
        $first = trim(preg_split('/\s+and\s+|\/|;/', $place)[0]);
        $parts = array_map('trim', explode(',', $first));
        $city = $parts[0] ?? '';
        $rest = $parts[1] ?? '';
        $code = $rest !== '' && function_exists('kop_facility_state_code') ? kop_facility_state_code($rest) : null;
        if ($code) {
            $state = $code;
        } elseif (count($parts) > 1) {
            $country = trim(end($parts));
        }
    }
    return array('name' => kop_wbc_title_case($name), 'city' => $city, 'state' => $state, 'country' => $country);
}

/** One line naming the Woodbury issue, for the record's notes. */
function kop_wbc_source_note(array $r) {
    $issue_url = $r['issue_id'] ? (string) wp_get_attachment_url((int) $r['issue_id']) : '';
    return 'Added ' . gmdate('Y-m-d') . ' from Woodbury Reports, ' . $r['issue_label']
        . ($r['issue_number'] !== '' ? ' (' . $r['issue_number'] . ')' : '')
        . ', ' . kop_wb_page_label($r['pages']) . ($issue_url !== '' ? ': ' . $issue_url : '');
}

/** A folder of this name under $parent, reusing one that exists. */
function kop_wbc_folder($name, $parent) {
    return kop_wb_find_folder($name, $parent) ?: kop_wb_create_folder($name, $parent);
}

function kop_wbc_pdo() {
    $pdo = function_exists('kop_closure_pdo') ? kop_closure_pdo() : null;
    if (!$pdo) {
        throw new RuntimeException('No database connection.');
    }
    return $pdo;
}

/**
 * Create the record. Returns the filing target for kop_wb_file_target():
 * kind, id, name, folder, plus url (its page, when it has one).
 */
function kop_wbc_create(array $r, array $f) {
    $kind = (string) ($f['kind'] ?? '');
    if (!isset(kop_wbc_kinds()[$kind])) {
        throw new RuntimeException('Choose what kind of record to create.');
    }
    $f['name'] = trim(preg_replace('/\s+/', ' ', (string) ($f['name'] ?? '')));
    $f['city'] = trim((string) ($f['city'] ?? ''));
    $f['country'] = trim((string) ($f['country'] ?? ''));
    $raw_state = trim((string) ($f['state'] ?? ''));
    $f['state'] = $raw_state !== '' ? (string) kop_facility_state_code($raw_state) : '';
    if ($raw_state !== '' && $f['state'] === '') {
        throw new RuntimeException('"' . $raw_state . '" is not a US state. Leave it empty and give the country instead.');
    }
    if ($f['name'] === '') {
        throw new RuntimeException('A name is needed.');
    }
    $fn = 'kop_wbc_create_' . $kind;
    return $fn($r, $f, kop_wbc_pdo());
}

/* ---- Program --------------------------------------------------------- */

function kop_wbc_create_facility(array $r, array $f, PDO $pdo) {
    global $wpdb;
    require_once get_stylesheet_directory() . '/inc/facility-v2-writer.php';
    if (!kop_v2_writes_active($pdo, $wpdb->prefix)) {
        throw new RuntimeException('Facility saves are not on facilities_v2 yet.');
    }
    if ($f['state'] === '' && $f['country'] === '') {
        throw new RuntimeException('A state (or a country outside the US) is needed for a program.');
    }
    $opts = array('pdo' => $pdo, 'prefix' => $wpdb->prefix);
    $found = kop_facility_resolve_identity($f['name'], $f['state'] ?: null, $f['city'] ?: null, $opts);
    if ($found) {
        throw new RuntimeException('Already a record: #' . $found . '. Type ' . $found . ' in "Other facility id" and file it there.');
    }
    if (empty($f['force'])) {
        $near = kop_facdisc_near_duplicate($pdo, $f['name'], $f['state'], $f['city']);
        if ($near) {
            $n = $pdo->prepare('SELECT name, city, state FROM facilities_v2 WHERE id = ?');
            $n->execute(array($near));
            $row = $n->fetch(PDO::FETCH_ASSOC) ?: array('name' => '?', 'city' => '', 'state' => '');
            throw new RuntimeException('Close to an existing record: #' . $near . ' ' . $row['name']
                . ($row['city'] !== '' ? ', ' . $row['city'] : '') . ' ' . $row['state']
                . '. File it there, or tick "It is a different place" and create again.');
        }
    }
    $type = in_array($f['type'] ?? '', kop_facdisc_types(), true) ? $f['type'] : '';
    $country = $f['country'] !== '' ? $f['country'] : 'United States';
    $legacy = array(
        'identification'  => array('name' => $f['name'], 'currentOperator' => '', 'otherNames' => array()),
        'locationDetails' => array('city' => $f['city'], 'state' => $f['state'], 'country' => $country),
        'location'        => trim(implode(', ', array_filter(array($f['city'], $f['state'] ?: $country)))),
        'operatingPeriod' => array('startYear' => null, 'endYear' => null, 'status' => 'Unknown', 'notes' => array()),
        'facilityDetails' => array('type' => $type, 'gender' => ''),
        'notes'           => array(kop_wbc_source_note($r)),
    );
    $doc = kop_facility_normalize($legacy, array('facility_id' => null, 'unique_name' => ''));
    $doc['facility_id'] = null;
    $doc['provenance']['source'] = 'woodbury';
    $doc['provenance']['sourceCategory'] = 'woodbury';
    $doc['provenance']['sourceProject'] = '';
    $doc['provenance']['sourceProjectId'] = null;
    $doc['provenance']['sourceOperator'] = null;
    $doc['provenance']['migratedAt'] = '';
    $doc['provenance']['legacyIds'] = array();

    $fid = kop_v2_with_write_lock($pdo, function () use ($doc, $opts) {
        $again = kop_facility_resolve_identity($doc['identification']['name'], $doc['location']['state'], $doc['location']['city'], $opts);
        if ($again) {
            throw new RuntimeException('Already a record: #' . $again . '.');
        }
        $status = null;
        return (int) kop_facility_save($doc, $opts, $status);
    });
    if (function_exists('kop_facdisc_alias_index')) {
        kop_facdisc_alias_index($pdo, true);
    }
    if (function_exists('kop_facdisc_rematch_closure_reports')) {
        kop_facdisc_rematch_closure_reports($pdo);
    }
    do_action('kop_facility_status_changed', $fid);
    return array('kind' => 'facility', 'id' => $fid, 'name' => $f['name'], 'folder' => 0,
        'url' => function_exists('kop_facility_page_url') ? (string) kop_facility_page_url($fid) : '');
}

/* ---- Company --------------------------------------------------------- */

function kop_wbc_create_company(array $r, array $f, PDO $pdo) {
    global $wpdb;
    require_once get_stylesheet_directory() . '/inc/facility-v2-writer.php';
    if (!kop_v2_writes_active($pdo, $wpdb->prefix)) {
        throw new RuntimeException('Company saves are not on the new tables yet.');
    }
    if (kop_v2_name_taken($pdo, $wpdb->prefix, $f['name'])) {
        throw new RuntimeException('"' . $f['name'] . '" is already a company or program record.');
    }
    $existing = function_exists('kop_operator_page_url_for_name') ? kop_operator_page_url_for_name($f['name']) : '';
    if ($existing !== '') {
        throw new RuntimeException('A company page already has this name: ' . $existing);
    }
    $folder = kop_wbc_folder($f['name'], 0);
    $data = array(
        'operator' => array_filter(array(
            'name'              => $f['name'],
            'currentName'       => $f['name'],
            'status'            => 'unknown',
            'headquartersCity'  => $f['city'],
            'headquartersState' => $f['state'],
            'location'          => trim(implode(', ', array_filter(array($f['city'], $f['state'] ?: $f['country'])))),
            'notes'             => array(kop_wbc_source_note($r)),
        ), static function ($v) { return $v !== '' && $v !== array(); }),
        'facilities'       => array(),
        'documentFolderId' => $folder,
        '_source'          => 'woodbury',
    );
    $result = kop_v2_save_form_project($pdo, $wpdb->prefix, $f['name'], $data, 'companies',
        array('partial' => true, 'timestamp' => gmdate('c')));
    $id = (int) $result['operator_id'];
    $url = '';
    if (function_exists('kop_operator_pages_index') && function_exists('kop_operator_page_url')) {
        kop_operator_pages_index(true);
        $url = (string) kop_operator_page_url($id);
    }
    return array('kind' => 'company', 'id' => $id, 'name' => $f['name'], 'folder' => $folder, 'url' => $url);
}

/* ---- Educational consultant ------------------------------------------ */

function kop_wbc_create_consultant(array $r, array $f, PDO $pdo) {
    global $wpdb;
    require_once get_stylesheet_directory() . '/api/lib-suggested-edits.php';
    $table = kop_resolve_table_name($pdo, 'referrers_master', $wpdb->prefix);
    kop_ensure_master_table($pdo, $table);
    $taken = $pdo->prepare("SELECT unique_name FROM `{$table}` WHERE LOWER(unique_name) = LOWER(?) OR json_data LIKE ? LIMIT 1");
    $taken->execute(array($f['name'], '%"' . str_replace(array('%', '_'), array('\%', '\_'), $f['name']) . '"%'));
    if ($hit = $taken->fetchColumn()) {
        throw new RuntimeException('A consultant record already names "' . $f['name'] . '" (' . $hit . ').');
    }
    $person = ($f['who'] ?? '') === 'person';
    $note = kop_wbc_source_note($r);
    $parent = kop_wbc_folder('Educational Consultants', 0);
    $folder = kop_wbc_folder($f['name'], $parent);
    $agency = array('name' => $person ? '' : $f['name'], 'city' => $person ? '' : $f['city'], 'state' => $person ? '' : $f['state'],
        'website' => '', 'websites' => array(), 'address' => '', 'founded' => '', 'affiliations' => array(),
        'keyPersonnel' => array(), 'notes' => $person ? '' : $note, 'fieldNotes' => array());
    $consultants = array();
    if ($person) {
        $bits = explode(' ', $f['name']);
        $last = count($bits) > 1 ? array_pop($bits) : '';
        $consultants[] = array(
            'firstName' => implode(' ', $bits), 'lastName' => $last, 'fullName' => $f['name'], 'role' => 'Educational Consultant',
            'status' => '', 'education' => '', 'credentials' => '', 'city' => $f['city'], 'state' => $f['state'],
            'email' => '', 'phone' => '', 'website' => '', 'websites' => array(), 'affiliations' => array(),
            'knownReferrals' => array(), 'pastTTIJobs' => array(), 'schoolDistricts' => array(), 'lawsuits' => '',
            'notes' => $note, 'fieldNotes' => array(), 'isIndependent' => true, 'facilitiesReferred' => array(),
        );
    }
    $data = array(
        'referrerType'            => $person ? 'individual' : 'group',
        'isIndependentConsultant' => $person,
        'referrerAgency'          => $agency,
        'referrerConsultants'     => $consultants,
        'fieldNotes'              => array(),
        'documentFolderId'        => $folder,
    );
    if ($person) {
        $data['referrerIndividual'] = $consultants[0];
    }
    $payload = array('name' => $f['name'], 'data' => $data, 'category' => 'referrers', 'currentFacilityIndex' => 0,
        'timestamp' => date('c'), 'documentFolderId' => $folder, '_source' => 'woodbury');
    $pdo->prepare("INSERT INTO `{$table}` (unique_name, json_data, updated_at) VALUES (?, ?, NOW())")
        ->execute(array($f['name'], wp_json_encode($payload)));
    $id = (int) $pdo->lastInsertId();

    // State pages list consultants from their state's locations_master row,
    // as api/save-master.php keeps it.
    if ($person && $f['state'] !== '') {
        kop_wbc_mirror_consultant($pdo, $wpdb->prefix, $f['name'], $f['state'], $consultants[0]);
    }
    return array('kind' => 'consultant', 'id' => $id, 'name' => $f['name'], 'folder' => $folder, 'url' => '');
}

/** Add the consultant to the state's locations_master row, stamped with its project. */
function kop_wbc_mirror_consultant(PDO $pdo, $prefix, $project, $state_code, array $consultant) {
    $states = kop_wb_state_names();
    if (!isset($states[$state_code])) {
        return;
    }
    $key = strtoupper($states[$state_code]);
    $table = kop_resolve_table_name($pdo, 'locations_master', $prefix);
    $stmt = $pdo->prepare("SELECT json_data FROM `{$table}` WHERE unique_name = ?");
    $stmt->execute(array($key));
    $raw = $stmt->fetchColumn();
    $project_row = $raw ? json_decode($raw, true) : null;
    $data = is_array($project_row) && isset($project_row['data']) && is_array($project_row['data']) ? $project_row['data'] : array();
    if (!isset($data['facilities']) || !is_array($data['facilities'])) {
        $data['facilities'] = array();
    }
    $list = isset($data['referrerConsultants']) && is_array($data['referrerConsultants']) ? $data['referrerConsultants'] : array();
    $list = array_values(array_filter($list, function ($c) use ($project) {
        return ($c['sourceProject'] ?? '') !== $project;
    }));
    $consultant['sourceProject'] = $project;
    $consultant['sourceCategory'] = 'referrers';
    $list[] = $consultant;
    $data['referrerConsultants'] = $list;
    $json = wp_json_encode(array('name' => $key, 'data' => $data, 'category' => 'locations', 'currentFacilityIndex' => 0, 'timestamp' => date('c')));
    $pdo->prepare("INSERT INTO `{$table}` (unique_name, json_data, updated_at) VALUES (?, ?, NOW())
                   ON DUPLICATE KEY UPDATE json_data = VALUES(json_data), updated_at = NOW()")
        ->execute(array($key, $json));
}

/* ---- Mental health provider ------------------------------------------ */

function kop_wbc_create_provider(array $r, array $f, PDO $pdo) {
    global $wpdb;
    require_once get_stylesheet_directory() . '/api/lib-suggested-edits.php';
    $table = kop_resolve_table_name($pdo, 'providers_master', $wpdb->prefix);
    kop_ensure_master_table($pdo, $table);
    $taken = $pdo->prepare("SELECT unique_name FROM `{$table}` WHERE LOWER(unique_name) = LOWER(?) LIMIT 1");
    $taken->execute(array($f['name']));
    if ($hit = $taken->fetchColumn()) {
        throw new RuntimeException('Already a provider record: ' . $hit . '.');
    }
    $parent = kop_wbc_folder('Mental Health Providers', 0);
    $folder = kop_wbc_folder($f['name'], $parent);
    $data = array(
        'category'   => 'providers',
        'operator'   => array('name' => ''),
        'facilities' => array(array(
            'identification'  => array('name' => $f['name']),
            'locationDetails' => array('city' => $f['city'], 'state' => $f['state'],
                'country' => $f['country'] !== '' ? $f['country'] : ($f['state'] !== '' ? 'United States' : '')),
            'facilityDetails' => array('type' => trim((string) ($f['type'] ?? ''))),
            'providerDetails' => array('careTypes' => new stdClass(), 'otherCareTypes' => array(), 'ttiPractices' => new stdClass(),
                'otherTtiPractices' => array(), 'ttiReferrals' => array(), 'transportersUsed' => array(),
                'ttiAffiliations' => array(), 'referralNotes' => ''),
            'notes'           => array(kop_wbc_source_note($r)),
        )),
        'documentFolderId' => $folder,
    );
    $payload = kop_build_project_payload(array('data' => $data), $f['name'], 'providers');
    $payload['documentFolderId'] = $folder;
    $payload['_source'] = 'woodbury';
    $pdo->prepare("INSERT INTO `{$table}` (unique_name, json_data, updated_at) VALUES (?, ?, NOW())")
        ->execute(array($f['name'], wp_json_encode($payload)));
    return array('kind' => 'provider', 'id' => (int) $pdo->lastInsertId(), 'name' => $f['name'], 'folder' => $folder, 'url' => '');
}

/* ---- Transporter ----------------------------------------------------- */

/**
 * A youth transport company in transporters_master, in the wrapper
 * api/save-master.php stores; the data form fills the other fields' defaults
 * when it is opened (data-normalizer.js).
 */
function kop_wbc_create_transporter(array $r, array $f, PDO $pdo) {
    global $wpdb;
    require_once get_stylesheet_directory() . '/api/lib-suggested-edits.php';
    $table = kop_resolve_table_name($pdo, 'transporters_master', $wpdb->prefix);
    kop_ensure_master_table($pdo, $table);
    $taken = $pdo->prepare("SELECT unique_name FROM `{$table}` WHERE LOWER(unique_name) = LOWER(?) OR json_data LIKE ? LIMIT 1");
    $taken->execute(array($f['name'], '%"name":"' . str_replace(array('%', '_'), array('\%', '\_'), $f['name']) . '"%'));
    if ($hit = $taken->fetchColumn()) {
        throw new RuntimeException('Already a transporter record: ' . $hit . '.');
    }
    $parent = kop_wbc_folder('Transporters', 0);
    $folder = kop_wbc_folder($f['name'], $parent);
    $empty = array('otherNames', 'parentCompanies', 'websites', 'serviceAreas', 'vehicleTypes', 'pickupMethods', 'restraintPractices',
        'licensing', 'affiliations', 'keyPersonnel', 'knownFacilities', 'knownReferrers', 'lawsuits', 'sourceUrls', 'socialMedia');
    $company = array('name' => $f['name'], 'city' => $f['city'], 'state' => $f['state'], 'country' => $f['country'] !== '' ? $f['country'] : ($f['state'] !== '' ? 'United States' : ''),
        'status' => '', 'website' => '', 'notes' => kop_wbc_source_note($r), 'fieldNotes' => new stdClass());
    foreach ($empty as $k) {
        $company[$k] = array();
    }
    $data = array('transporterCompany' => $company, 'transporters' => array(), 'documentFolderId' => $folder);
    $payload = array('name' => $f['name'], 'data' => $data, 'category' => 'transporters', 'currentFacilityIndex' => 0,
        'timestamp' => date('c'), 'documentFolderId' => $folder, '_source' => 'woodbury');
    $pdo->prepare("INSERT INTO `{$table}` (unique_name, json_data, updated_at) VALUES (?, ?, NOW())")
        ->execute(array($f['name'], wp_json_encode($payload)));
    return array('kind' => 'transporter', 'id' => (int) $pdo->lastInsertId(), 'name' => $f['name'], 'folder' => $folder, 'url' => '');
}

/* ---- AJAX: create and file in one click ------------------------------- */

add_action('wp_ajax_kop_wb_create', function () {
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Not allowed.', 403);
    }
    check_ajax_referer('kop_woodbury', 'nonce');
    $ckey = preg_replace('/[^a-f0-9]/', '', (string) ($_POST['key'] ?? ''));
    $r = $ckey !== '' ? kop_wb_get($ckey) : null;
    if (!$r) {
        wp_send_json_error('Not found.');
    }
    if ($r['status'] === 'filed') {
        wp_send_json_error('Already filed.');
    }
    $fields = array();
    foreach (array('kind', 'name', 'city', 'state', 'country', 'type', 'who', 'force') as $k) {
        $fields[$k] = sanitize_text_field(wp_unslash((string) ($_POST[$k] ?? '')));
    }
    // One create per candidate at a time, as filing is (a 503'd click resent).
    global $wpdb;
    $lock = 'kop_wbc_' . $ckey;
    if (!(int) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 0)', $lock))) {
        wp_send_json_error('Another request is creating this one. Reload the page in a minute.');
    }
    try {
        $target = kop_wbc_create($r, $fields);
    } catch (Throwable $e) {
        $wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock));
        wp_send_json_error($e->getMessage());
    }
    $wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock));
    $label = kop_wbc_kinds()[$target['kind']];
    try {
        $filed = kop_wb_file_target($r, $target, wp_get_current_user()->user_login);
    } catch (Throwable $e) {
        wp_send_json_error('Created the ' . strtolower($label) . ' record "' . $target['name'] . '" (#' . $target['id']
            . '), but filing the pages failed: ' . $e->getMessage());
    }
    wp_send_json_success(array('record' => $target, 'label' => $label, 'filed' => $filed));
});

/** The "Create a record" block under a pending row's program column. */
function kop_wbc_render_form(array $r) {
    $p = kop_wbc_prefill($r);
    $states = kop_wb_state_names();
    echo '<details class="kop-wbc"><summary>Create a record</summary><div class="kop-wbc-form">';
    echo '<label>Kind <select class="kop-wbc-kind">';
    foreach (kop_wbc_kinds() as $k => $label) {
        echo '<option value="' . esc_attr($k) . '">' . esc_html($label) . '</option>';
    }
    echo '</select></label>';
    echo '<label>Name <input type="text" class="kop-wbc-name" value="' . esc_attr($p['name']) . '"></label>';
    echo '<label class="kop-wbc-only" data-kinds="consultant">Is a <select class="kop-wbc-who"><option value="firm">Firm</option><option value="person">Person</option></select></label>';
    echo '<label>City <input type="text" class="kop-wbc-city" value="' . esc_attr($p['city']) . '"></label>';
    echo '<label>State <select class="kop-wbc-state"><option value=""></option>';
    foreach ($states as $code => $state_name) {
        echo '<option value="' . esc_attr($code) . '"' . selected($p['state'], $code, false) . '>' . esc_html($code . ' ' . $state_name) . '</option>';
    }
    echo '</select></label>';
    echo '<label>Country, outside the US <input type="text" class="kop-wbc-country" value="' . esc_attr($p['country']) . '"></label>';
    echo '<label class="kop-wbc-only" data-kinds="facility">Type <select class="kop-wbc-type"><option value=""></option>';
    foreach (kop_facdisc_types() as $t) {
        echo '<option>' . esc_html($t) . '</option>';
    }
    echo '</select></label>';
    echo '<label class="kop-wbc-only" data-kinds="provider">Type <input type="text" class="kop-wbc-ptype" placeholder="psychiatric hospital, outpatient..."></label>';
    echo '<label class="kop-wbc-only kop-wbc-check" data-kinds="facility"><input type="checkbox" class="kop-wbc-force"> It is a different place from any close match</label>';
    echo '<button type="button" class="button button-primary kop-wbc-go">Create and file</button>';
    echo '<div class="kop-wbc-msg"></div></div></details>';
}

/** Styles and script for the create blocks; printed once by the review screen. */
function kop_wbc_render_assets() {
    ?>
    <style>
        .kop-wbc { margin-top: 8px; }
        .kop-wbc summary { cursor: pointer; color: #2271b1; }
        .kop-wbc-form { margin-top: 6px; padding: 8px; background: #f6f7f7; border: 1px solid #dcdcde; }
        .kop-wbc-form label { display: block; margin: 0 0 6px; font-size: 12px; color: #50575e; }
        .kop-wbc-form input[type=text], .kop-wbc-form select { display: block; width: 100%; max-width: 100%; }
        .kop-wbc-form .kop-wbc-check input { display: inline; width: auto; }
        .kop-wbc-msg { font-size: 12px; margin-top: 6px; }
        .kop-wbc-msg.ok { color: #1a7f37; }
        .kop-wbc-msg.err { color: #d63638; }
    </style>
    <script>
    (function () {
        var ajax = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;
        var nonce = <?php echo wp_json_encode(wp_create_nonce('kop_woodbury')); ?>;
        function show(form) {
            var kind = form.querySelector('.kop-wbc-kind').value;
            form.querySelectorAll('.kop-wbc-only').forEach(function (el) {
                el.style.display = el.getAttribute('data-kinds').split(' ').indexOf(kind) >= 0 ? '' : 'none';
            });
        }
        document.querySelectorAll('.kop-wbc-form').forEach(function (form) {
            var kind = form.querySelector('.kop-wbc-kind');
            kind.addEventListener('change', function () { show(form); });
            show(form);
            var go = form.querySelector('.kop-wbc-go');
            go.addEventListener('click', function () {
                var tr = form.closest('tr');
                var msg = form.querySelector('.kop-wbc-msg');
                var k = kind.value;
                var body = new FormData();
                body.append('action', 'kop_wb_create');
                body.append('nonce', nonce);
                body.append('key', tr.getAttribute('data-key'));
                body.append('kind', k);
                body.append('name', form.querySelector('.kop-wbc-name').value);
                body.append('who', form.querySelector('.kop-wbc-who').value);
                body.append('city', form.querySelector('.kop-wbc-city').value);
                body.append('state', form.querySelector('.kop-wbc-state').value);
                body.append('country', form.querySelector('.kop-wbc-country').value);
                body.append('type', k === 'provider' ? form.querySelector('.kop-wbc-ptype').value : form.querySelector('.kop-wbc-type').value);
                body.append('force', form.querySelector('.kop-wbc-force').checked ? '1' : '');
                go.disabled = true;
                msg.className = 'kop-wbc-msg';
                msg.textContent = 'Creating...';
                fetch(ajax, { method: 'POST', body: body, credentials: 'same-origin' })
                    .then(function (r) {
                        if (!r.ok) throw new Error('the server answered ' + r.status + '; it may still be finishing. Reload the page and check the Filed tab before trying again');
                        return r.json();
                    })
                    .then(function (j) {
                        if (!j || !j.success) throw new Error((j && j.data) || 'Request failed');
                        var rec = j.data.record;
                        msg.className = 'kop-wbc-msg ok';
                        msg.textContent = 'Created ' + j.data.label.toLowerCase() + ' #' + rec.id + ' and filed the pages. ';
                        if (rec.url) {
                            var a = document.createElement('a');
                            a.href = rec.url; a.target = '_blank'; a.rel = 'noopener'; a.textContent = 'Open its page';
                            msg.appendChild(a);
                        }
                        tr.classList.add('kop-wb-done');
                        tr.querySelectorAll('button').forEach(function (b) { b.disabled = true; });
                        var pick = tr.querySelector('.kop-wb-pick');
                        if (pick) { pick.checked = false; pick.disabled = true; }
                    })
                    .catch(function (e) {
                        go.disabled = false;
                        msg.className = 'kop-wbc-msg err';
                        msg.textContent = e.message;
                    });
            });
        });
    })();
    </script>
    <?php
}
