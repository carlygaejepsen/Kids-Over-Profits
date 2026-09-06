<?php
/**
 * Manage addresses — physical-campus identity for facilities.
 *
 * A TTI campus outlives the names painted on its sign: "Viewpoint Center" and
 * "Aspen Institute for Behavioral Assessment" are one building in Syracuse UT.
 * This tool gives each physical address a stable ID so facilities that shared
 * a campus can be linked systematically instead of by tribal knowledge.
 *
 * Two tables:
 *   {prefix}kop_addresses          — CURATED: one row per physical address,
 *                                    stable id, unique normalized key.
 *   {prefix}kop_facility_addresses — DERIVED: which facility stood at which
 *                                    address (role: current/additional/former,
 *                                    with years). Rebuilt from
 *                                    facilities_master json_data on every
 *                                    seed run — never edited by hand, so
 *                                    admins keep editing addresses in the
 *                                    facility form as usual and re-run the
 *                                    scan here.
 *   {prefix}kop_address_dismissals  — CURATED: normalized scan candidates
 *                                    that are locations, regions, or other
 *                                    non-address values and should stay out
 *                                    of the scan and seed results.
 *
 * Views:
 *   (default)      Scan report — every street address found in
 *                  facilities_master (main + additionalLocations +
 *                  formerLocations), grouped by normalized key, matched to
 *                  existing address rows. "Seed / refresh" writes new address
 *                  rows and rebuilds the join table.
 *   ?view=shared   Addresses with 2+ distinct facilities — successor
 *                  detection. Where the facilities' folders are not already
 *                  merged, offers a one-click "Link folders" that writes
 *                  kop_folder_links (same table api/link-folders.php curates).
 *
 * Address identity and NAME aliases are deliberately separate systems: a
 * shared address only SUGGESTS a folder link — some operators ran distinct
 * sister programs on one campus, and some reused a name at a new campus.
 *
 * Admin-only. Loads WordPress via config.php.
 */

require_once __DIR__ . '/config.php';

if (!function_exists('current_user_can') || !current_user_can('manage_options')) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Not authorized. Log in to WordPress as an administrator first.';
    exit;
}

if (!function_exists('kop_get_equivalent_folder_ids') && file_exists(__DIR__ . '/../inc/database.php')) {
    require_once __DIR__ . '/../inc/database.php';
}

global $wpdb;
$fbv = $wpdb->prefix . 'fbv';
$addr_tbl = $wpdb->prefix . 'kop_addresses';
$fa_tbl = $wpdb->prefix . 'kop_facility_addresses';
$links_tbl = $wpdb->prefix . 'kop_folder_links';
$dismissals_tbl = $wpdb->prefix . 'kop_address_dismissals';

header('Content-Type: text/html; charset=utf-8');

// Idempotent schema. kop_facility_addresses has no surrogate id: it is a
// derived table, fully rebuilt on each seed run.
$wpdb->query("CREATE TABLE IF NOT EXISTS {$addr_tbl} (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    street VARCHAR(255) NOT NULL,
    city VARCHAR(120) NOT NULL DEFAULT '',
    state VARCHAR(60) NOT NULL DEFAULT '',
    zip VARCHAR(20) NOT NULL DEFAULT '',
    country VARCHAR(60) NOT NULL DEFAULT '',
    norm_key VARCHAR(191) NOT NULL,
    notes TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_norm (norm_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$wpdb->query("CREATE TABLE IF NOT EXISTS {$fa_tbl} (
    address_id BIGINT UNSIGNED NOT NULL,
    facility VARCHAR(191) NOT NULL,
    role VARCHAR(20) NOT NULL DEFAULT 'current',
    from_year VARCHAR(10) NOT NULL DEFAULT '',
    to_year VARCHAR(10) NOT NULL DEFAULT '',
    source_street VARCHAR(255) NOT NULL DEFAULT '',
    PRIMARY KEY (address_id, facility, role, from_year),
    KEY idx_facility (facility)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$wpdb->query("CREATE TABLE IF NOT EXISTS {$dismissals_tbl} (
    norm_key VARCHAR(191) NOT NULL,
    label VARCHAR(255) NOT NULL DEFAULT '',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (norm_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Same shape api/link-folders.php creates — made here too so the shared view's
// "Link folders" button works even if that tool was never opened.
$wpdb->query("CREATE TABLE IF NOT EXISTS {$links_tbl} (
    folder_a BIGINT UNSIGNED NOT NULL,
    folder_b BIGINT UNSIGNED NOT NULL,
    note VARCHAR(255) NOT NULL DEFAULT '',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (folder_a, folder_b),
    KEY idx_b (folder_b)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// ---------------------------------------------------------------------------
// Normalization
// ---------------------------------------------------------------------------

/** Canonical short forms so "123 North Main Street" == "123 N Main St". */
function kop_ma_street_token_map() {
    return [
        'street' => 'st', 'avenue' => 'ave', 'road' => 'rd', 'drive' => 'dr',
        'lane' => 'ln', 'boulevard' => 'blvd', 'highway' => 'hwy',
        'court' => 'ct', 'circle' => 'cir', 'parkway' => 'pkwy',
        'place' => 'pl', 'trail' => 'trl', 'terrace' => 'ter',
        'route' => 'rte', 'north' => 'n', 'south' => 's', 'east' => 'e',
        'west' => 'w', 'suite' => 'ste', 'apartment' => 'apt',
    ];
}

/** Normalize a street line for identity matching. */
function kop_ma_norm_street($street) {
    $t = mb_strtolower(trim((string)$street));
    $t = html_entity_decode($t, ENT_QUOTES);
    $t = preg_replace('/[^a-z0-9]+/u', ' ', $t);
    $map = kop_ma_street_token_map();
    $tokens = array_map(static function ($tok) use ($map) {
        return $map[$tok] ?? $tok;
    }, array_filter(explode(' ', $t), 'strlen'));
    return implode(' ', $tokens);
}

/** US state / territory full names to postal codes. */
function kop_ma_state_map() {
    return [
        'alabama' => 'AL', 'alaska' => 'AK', 'arizona' => 'AZ', 'arkansas' => 'AR',
        'california' => 'CA', 'colorado' => 'CO', 'connecticut' => 'CT',
        'delaware' => 'DE', 'district of columbia' => 'DC', 'florida' => 'FL',
        'georgia' => 'GA', 'hawaii' => 'HI', 'idaho' => 'ID', 'illinois' => 'IL',
        'indiana' => 'IN', 'iowa' => 'IA', 'kansas' => 'KS', 'kentucky' => 'KY',
        'louisiana' => 'LA', 'maine' => 'ME', 'maryland' => 'MD',
        'massachusetts' => 'MA', 'michigan' => 'MI', 'minnesota' => 'MN',
        'mississippi' => 'MS', 'missouri' => 'MO', 'montana' => 'MT',
        'nebraska' => 'NE', 'nevada' => 'NV', 'new hampshire' => 'NH',
        'new jersey' => 'NJ', 'new mexico' => 'NM', 'new york' => 'NY',
        'north carolina' => 'NC', 'north dakota' => 'ND', 'ohio' => 'OH',
        'oklahoma' => 'OK', 'oregon' => 'OR', 'pennsylvania' => 'PA',
        'puerto rico' => 'PR', 'rhode island' => 'RI', 'south carolina' => 'SC',
        'south dakota' => 'SD', 'tennessee' => 'TN', 'texas' => 'TX',
        'utah' => 'UT', 'vermont' => 'VT', 'virginia' => 'VA',
        'washington' => 'WA', 'west virginia' => 'WV', 'wisconsin' => 'WI',
        'wyoming' => 'WY',
    ];
}

/** Normalize a state to its postal code where possible. */
function kop_ma_norm_state($state) {
    $s = trim((string)$state);
    if ($s === '') {
        return '';
    }
    if (preg_match('/^[A-Za-z]{2}$/', $s)) {
        return strtoupper($s);
    }
    $map = kop_ma_state_map();
    $key = strtolower(preg_replace('/\s+/', ' ', $s));
    return $map[$key] ?? $s;
}

/** First 5-digit run of a zip, or ''. */
function kop_ma_zip5($zip) {
    return preg_match('/\d{5}/', (string)$zip, $m) ? $m[0] : '';
}

/**
 * Identity key for a physical address. Zip beats city when present (city
 * spellings drift; zip5 pins the locale), so entries missing a zip can still
 * split from ones that have it — the scan report surfaces those for curation.
 */
function kop_ma_norm_key($street, $city, $state, $zip) {
    $street_n = kop_ma_norm_street($street);
    if ($street_n === '') {
        return '';
    }
    $state_n = strtolower(kop_ma_norm_state($state));
    $zip5 = kop_ma_zip5($zip);
    $locale = $zip5 !== '' ? $zip5 : kop_ma_norm_street($city);
    return mb_substr($street_n . '|' . $state_n . '|' . $locale, 0, 191);
}

/** Scan candidates explicitly marked as non-address values. */
function kop_ma_dismissed_keys() {
    global $wpdb, $dismissals_tbl;
    $keys = [];
    foreach ((array)$wpdb->get_col("SELECT norm_key FROM {$dismissals_tbl}") as $key) {
        $keys[(string)$key] = true;
    }
    return $keys;
}

// ---------------------------------------------------------------------------
// Extraction: every street address in facilities_master & locations_master
// ---------------------------------------------------------------------------

/**
 * Resolve table name with optional WordPress prefix.
 */
function kop_ma_resolve_table_name($base) {
    global $wpdb;
    $candidates = [];
    if (!empty($wpdb->prefix)) {
        $candidates[] = $wpdb->prefix . $base;
    }
    $candidates[] = $base;
    foreach ($candidates as $cand) {
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $cand)) === $cand) {
            return $cand;
        }
    }
    return $candidates[0];
}

/**
 * Parse a free-form address string into street, city, state, zip.
 * Respects fallback values from structured fields if already present.
 */
function kop_ma_parse_address_string($raw_addr, $fallback_city = '', $fallback_state = '', $fallback_zip = '') {
    $street = trim((string)$raw_addr);
    $city   = trim((string)$fallback_city);
    $state  = trim((string)$fallback_state);
    $zip    = trim((string)$fallback_zip);

    // Strip trailing newlines, periods, commas, spaces
    $street = trim($street, " \t\n\r\0\x0B.,");

    if ($street === '') {
        return ['street' => '', 'city' => $city, 'state' => $state, 'zip' => $zip];
    }

    // 1. Extract 5-digit zip (or ZIP+4) at the end: e.g. "83847" or "32083-2519"
    if (preg_match('/\b(\d{5}(?:-\d{4})?)\.?\s*$/', $street, $zm)) {
        if ($zip === '') {
            $zip = $zm[1];
        }
        $street = trim(substr($street, 0, -strlen($zm[0])));
        $street = rtrim($street, " \t\n\r\0\x0B,.");
    }

    // 2. Extract US state abbreviation or full name at the end
    $us_states = 'AL|AK|AZ|AR|CA|CO|CT|DE|FL|GA|HI|ID|IL|IN|IA|KS|KY|LA|ME|MD|MA|MI|MN|MS|MO|MT|NE|NV|NH|NJ|NM|NY|NC|ND|OH|OK|OR|PA|RI|SC|SD|TN|TX|UT|VT|VA|WA|WV|WI|WY|PR|DC|Alabama|Alaska|Arizona|Arkansas|California|Colorado|Connecticut|Delaware|Florida|Georgia|Hawaii|Idaho|Illinois|Indiana|Iowa|Kansas|Kentucky|Louisiana|Maine|Maryland|Massachusetts|Michigan|Minnesota|Mississippi|Missouri|Montana|Nebraska|Nevada|New\s+Hampshire|New\s+Jersey|New\s+Mexico|New\s+York|North\s+Carolina|North\s+Dakota|Ohio|Oklahoma|Oregon|Pennsylvania|Rhode\s+Island|South\s+Carolina|South\s+Dakota|Tennessee|Texas|Utah|Vermont|Virginia|Washington|West\s+Virginia|Wisconsin|Wyoming';

    if (preg_match('/(?:,\s*|\s+)(' . $us_states . ')\s*$/i', $street, $sm, PREG_OFFSET_CAPTURE)) {
        if ($state === '') {
            $state = $sm[1][0];
        }
        $street = trim(substr($street, 0, $sm[0][1]));
        $street = rtrim($street, " \t\n\r\0\x0B,.");

        // 3. Now the end of the remaining street string might be ", City":
        if (preg_match('/,\s*([^,]+)$/', $street, $cm, PREG_OFFSET_CAPTURE)) {
            if ($city === '') {
                $city = trim($cm[1][0]);
            }
            $street = trim(substr($street, 0, $cm[0][1]));
        }
    } elseif ($state === '') {
        // Fallback: 2-letter state preceded by comma e.g. ", XX"
        if (preg_match('/,\s*([A-Za-z]{2})\s*$/', $street, $sm, PREG_OFFSET_CAPTURE)) {
            $state = $sm[1][0];
            $street = trim(substr($street, 0, $sm[0][1]));
            $street = rtrim($street, " \t\n\r\0\x0B,.");
            if (preg_match('/,\s*([^,]+)$/', $street, $cm, PREG_OFFSET_CAPTURE)) {
                if ($city === '') {
                    $city = trim($cm[1][0]);
                }
                $street = trim(substr($street, 0, $cm[0][1]));
            }
        }
    }

    return [
        'street' => trim($street, " \t\n\r\0\x0B,."),
        'city'   => trim($city, " \t\n\r\0\x0B,."),
        'state'  => trim($state, " \t\n\r\0\x0B,."),
        'zip'    => trim($zip, " \t\n\r\0\x0B,."),
    ];
}

/**
 * Extract facility entries from a project row.
 * Handles operator projects with nested data.facilities[], standalone facilities,
 * and skips promoted identity stubs (__facility_ref).
 *
 * @param array|null $decoded
 * @param string $row_unique_name
 * @return array[] each: ['name' => string, 'facility' => array]
 */
function kop_ma_get_facilities_from_project($decoded, $row_unique_name) {
    if (!is_array($decoded)) {
        return [];
    }

    // Skip promoted identity stubs
    if (!empty($decoded['__facility_ref']) || !empty($decoded['data']['__facility_ref'])) {
        return [];
    }

    $d = isset($decoded['data']) && is_array($decoded['data']) ? $decoded['data'] : $decoded;
    if (isset($decoded['project']['data']) && is_array($decoded['project']['data'])) {
        $d = $decoded['project']['data'];
    } elseif (isset($decoded['project']) && is_array($decoded['project'])) {
        $d = $decoded['project'];
    }

    $fac_list = [];
    if (isset($d['facilities']) && is_array($d['facilities'])) {
        $fac_list = $d['facilities'];
    } elseif (isset($decoded['facilities']) && is_array($decoded['facilities'])) {
        $fac_list = $decoded['facilities'];
    } elseif (isset($d['facility']) && is_array($d['facility'])) {
        $fac_list = $d['facility'];
    } elseif (is_string($d['facilities'] ?? null)) {
        $parsed = json_decode($d['facilities'], true);
        if (is_array($parsed)) {
            $fac_list = $parsed;
        }
    }

    $results = [];
    if (!empty($fac_list)) {
        foreach ($fac_list as $f) {
            if (!is_array($f)) continue;
            $name = '';
            if (isset($f['identification']) && is_array($f['identification'])) {
                $name = trim((string)($f['identification']['name'] ?? ($f['identification']['currentName'] ?? '')));
            }
            if ($name === '' && isset($f['name']) && is_string($f['name'])) {
                $name = trim($f['name']);
            }
            if ($name === '') {
                $name = (string)$row_unique_name;
            }
            $results[] = [
                'name'     => $name,
                'facility' => $f,
            ];
        }
    } else {
        // Single facility / standalone record
        $name = '';
        if (isset($d['identification']) && is_array($d['identification'])) {
            $name = trim((string)($d['identification']['name'] ?? ($d['identification']['currentName'] ?? '')));
        }
        if ($name === '' && isset($d['name']) && is_string($d['name'])) {
            $name = trim($d['name']);
        }
        if ($name === '') {
            $name = (string)$row_unique_name;
        }
        $results[] = [
            'name'     => $name,
            'facility' => $d,
        ];
    }

    return $results;
}

/**
 * Pull address entries out of one facility record array.
 * @param array $f Facility data dictionary
 * @param string $facility_name The resolved facility name
 * @return array[] each: [street, city, state, zip, country, role, from, to, facility]
 */
function kop_ma_extract_entries($f, $facility_name) {
    if (!is_array($f)) {
        return [];
    }

    $ld = isset($f['locationDetails']) && is_array($f['locationDetails']) ? $f['locationDetails'] : [];
    $parts = isset($f['addressParts']) && is_array($f['addressParts']) ? $f['addressParts'] : [];

    $entries = [];
    $push = static function ($street, $city, $state, $zip, $country, $role, $from = '', $to = '') use (&$entries, $facility_name) {
        $street = trim((string)$street);
        if ($street === '') {
            return;
        }
        $entries[] = [
            'street'   => $street,
            'city'     => trim((string)$city),
            'state'    => trim((string)$state),
            'zip'      => trim((string)$zip),
            'country'  => trim((string)$country),
            'role'     => $role,
            'from'     => trim((string)$from),
            'to'       => trim((string)$to),
            'facility' => $facility_name,
        ];
    };

    // --- 1. Current / Primary address ---
    $city = '';
    $state = '';
    $zip = '';
    $country = '';
    $street = '';

    if (!empty($ld['city'])) $city = trim((string)$ld['city']);
    if (!empty($ld['state'])) $state = trim((string)$ld['state']);
    if (!empty($ld['zip'])) $zip = trim((string)$ld['zip']);
    if (!empty($ld['country'])) $country = trim((string)$ld['country']);

    if (!empty($parts['street'])) $street = trim((string)$parts['street']);
    if ($city === '' && !empty($parts['city'])) $city = trim((string)$parts['city']);
    if ($state === '' && !empty($parts['state'])) $state = trim((string)$parts['state']);
    if ($zip === '' && !empty($parts['zip'])) $zip = trim((string)$parts['zip']);

    $raw_addr = $f['address'] ?? ($ld['address'] ?? ($ld['street'] ?? ''));
    if (is_array($raw_addr)) {
        if ($street === '' && !empty($raw_addr['street'])) $street = trim((string)$raw_addr['street']);
        if ($city === '' && !empty($raw_addr['city'])) $city = trim((string)$raw_addr['city']);
        if ($state === '' && !empty($raw_addr['state'])) $state = trim((string)$raw_addr['state']);
        if ($zip === '' && !empty($raw_addr['zip'])) $zip = trim((string)$raw_addr['zip']);
        if ($country === '' && !empty($raw_addr['country'])) $country = trim((string)$raw_addr['country']);
    } elseif (is_string($raw_addr) && trim($raw_addr) !== '') {
        $parsed = kop_ma_parse_address_string($raw_addr, $city, $state, $zip);
        if ($street === '') $street = $parsed['street'];
        if ($city === '') $city = $parsed['city'];
        if ($state === '') $state = $parsed['state'];
        if ($zip === '') $zip = $parsed['zip'];
    }

    if (($city === '' || $state === '') && !empty($f['location']) && is_string($f['location'])) {
        if (preg_match('/^\s*([^,]+?)\s*,\s*([A-Za-z .]{2,})\s*$/u', trim($f['location']), $lm)) {
            if ($city === '') $city = trim($lm[1]);
            if ($state === '') $state = trim($lm[2]);
        }
    }

    if ($street !== '') {
        $push($street, $city, $state, $zip, $country, 'current');
    }

    // --- 2. Additional Locations ---
    foreach ((array)($ld['additionalLocations'] ?? []) as $loc) {
        if (is_array($loc)) {
            $a_street = $loc['address'] ?? ($loc['street'] ?? '');
            $parsed = kop_ma_parse_address_string(
                $a_street,
                $loc['city'] ?? '',
                $loc['state'] ?? '',
                $loc['zip'] ?? ''
            );
            $push($parsed['street'] ?: $a_street, $parsed['city'], $parsed['state'], $parsed['zip'], '', 'additional');
        } elseif (is_string($loc) && trim($loc) !== '') {
            $parsed = kop_ma_parse_address_string($loc);
            $push($parsed['street'], $parsed['city'], $parsed['state'], $parsed['zip'], '', 'additional');
        }
    }

    // --- 3. Former Locations ---
    foreach ((array)($ld['formerLocations'] ?? []) as $loc) {
        if (is_array($loc)) {
            $f_street = $loc['address'] ?? ($loc['street'] ?? '');
            $parsed = kop_ma_parse_address_string(
                $f_street,
                $loc['city'] ?? '',
                $loc['state'] ?? '',
                $loc['zip'] ?? ''
            );
            $push(
                $parsed['street'] ?: $f_street,
                $parsed['city'],
                $parsed['state'],
                $parsed['zip'],
                '',
                'former',
                $loc['fromYear'] ?? '',
                $loc['toYear'] ?? ''
            );
        }
    }

    return $entries;
}

/**
 * Scan facilities_master and locations_master, group every address entry by normalized key.
 * @return array norm_key => ['display' => entry, 'members' => [facility-entry...]]
 */
function kop_ma_scan() {
    global $wpdb;

    $groups = [];
    $dismissed_keys = kop_ma_dismissed_keys();
    $tables = [];
    foreach (['facilities_master', 'locations_master'] as $base) {
        $tbl = kop_ma_resolve_table_name($base);
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $tbl)) === $tbl) {
            $tables[] = $tbl;
        }
    }

    foreach ($tables as $tbl) {
        $rows = $wpdb->get_results("SELECT unique_name, json_data FROM {$tbl}");
        foreach ((array)$rows as $row) {
            $decoded = json_decode($row->json_data ?: '', true);
            $facilities = kop_ma_get_facilities_from_project($decoded, (string)$row->unique_name);
            foreach ($facilities as $item) {
                foreach (kop_ma_extract_entries($item['facility'], $item['name']) as $e) {
                    $key = kop_ma_norm_key($e['street'], $e['city'], $e['state'], $e['zip']);
                    if ($key === '' || isset($dismissed_keys[$key])) {
                        continue;
                    }
                    if (!isset($groups[$key])) {
                        $groups[$key] = ['display' => $e, 'members' => []];
                    } else {
                        // Prefer the most complete display entry for the group.
                        $cur = $groups[$key]['display'];
                        $score = static function ($x) {
                            return strlen($x['street']) + ($x['city'] !== '' ? 20 : 0)
                                + ($x['zip'] !== '' ? 20 : 0) + ($x['state'] !== '' ? 10 : 0);
                        };
                        if ($score($e) > $score($cur)) {
                            $groups[$key]['display'] = $e;
                        }
                    }

                    // Deduplicate identical stint across tables (same facility, role, from, to)
                    $m_key = $e['facility'] . '|' . $e['role'] . '|' . $e['from'] . '|' . $e['to'];
                    $groups[$key]['members'][$m_key] = $e;
                }
            }
        }
    }

    // Convert members from keyed map to indexed array
    foreach ($groups as $k => &$g) {
        $g['members'] = array_values($g['members']);
    }
    unset($g);

    uasort($groups, static function ($a, $b) {
        $sa = kop_ma_norm_state($a['display']['state']);
        $sb = kop_ma_norm_state($b['display']['state']);
        return $sa === $sb
            ? strcasecmp($a['display']['street'], $b['display']['street'])
            : strcasecmp($sa, $sb);
    });
    return $groups;
}

// ---------------------------------------------------------------------------
// Actions
// ---------------------------------------------------------------------------
$log = [];
$log_ok = false;

// Remove a scan candidate that is a city, state, country, or other location
// label rather than a physical street address. This does not alter source
// facility data; it only suppresses the normalized candidate from this tool.
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['do_dismiss_address'])
    && check_admin_referer('kop_ma_apply')) {

    $key = sanitize_text_field((string)($_POST['norm_key'] ?? ''));
    $label = sanitize_text_field((string)($_POST['label'] ?? ''));
    if ($key === '') {
        $log[] = 'Could not dismiss the address candidate: missing normalized key.';
    } else {
        $ins = $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO {$dismissals_tbl} (norm_key, label) VALUES (%s, %s)",
            $key, mb_substr($label, 0, 255)
        ));
        $log_ok = (bool)$ins;
        $log[] = $ins
            ? 'Dismissed "' . ($label !== '' ? $label : $key) . '" from the address scan.'
            : 'That scan candidate is already dismissed.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['do_restore_address_dismissal'])
    && check_admin_referer('kop_ma_apply')) {

    $key = sanitize_text_field((string)($_POST['norm_key'] ?? ''));
    $deleted = $wpdb->delete($dismissals_tbl, ['norm_key' => $key], ['%s']);
    $log_ok = (bool)$deleted;
    $log[] = $deleted
        ? 'Address candidate restored to the scan list.'
        : 'That address dismissal no longer exists.';
}

// Seed / refresh: new curated address rows for unseen keys, then rebuild the
// derived facility-address join from scratch.
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['do_seed'])
    && check_admin_referer('kop_ma_apply')) {

    $groups = kop_ma_scan();
    $existing = [];
    foreach ($wpdb->get_results("SELECT id, norm_key FROM {$addr_tbl}") as $r) {
        $existing[$r->norm_key] = (int)$r->id;
    }

    $wpdb->query('START TRANSACTION');
    try {
        $new_rows = 0;
        foreach ($groups as $key => $g) {
            if (isset($existing[$key])) {
                continue;
            }
            $d = $g['display'];
            $wpdb->insert($addr_tbl, [
                'street' => mb_substr($d['street'], 0, 255),
                'city' => mb_substr($d['city'], 0, 120),
                'state' => mb_substr(kop_ma_norm_state($d['state']), 0, 60),
                'zip' => mb_substr(kop_ma_zip5($d['zip']) ?: $d['zip'], 0, 20),
                'country' => mb_substr($d['country'], 0, 60),
                'norm_key' => $key,
            ], ['%s', '%s', '%s', '%s', '%s', '%s']);
            $existing[$key] = (int)$wpdb->insert_id;
            $new_rows++;
        }

        $wpdb->query("DELETE FROM {$fa_tbl}");
        $memberships = 0;
        foreach ($groups as $key => $g) {
            $aid = $existing[$key] ?? 0;
            if (!$aid) {
                continue;
            }
            foreach ($g['members'] as $m) {
                $ins = $wpdb->query($wpdb->prepare(
                    "INSERT IGNORE INTO {$fa_tbl}
                        (address_id, facility, role, from_year, to_year, source_street)
                     VALUES (%d, %s, %s, %s, %s, %s)",
                    $aid, mb_substr($m['facility'], 0, 191), $m['role'],
                    mb_substr($m['from'], 0, 10), mb_substr($m['to'], 0, 10),
                    mb_substr($m['street'], 0, 255)
                ));
                if ($ins) {
                    $memberships++;
                }
            }
        }
        $wpdb->query('COMMIT');
        $log_ok = true;
        $log[] = "Seed complete: {$new_rows} new address(es), " . count($groups)
            . " total, {$memberships} facility-address membership(s) rebuilt.";
    } catch (Throwable $e) {
        $wpdb->query('ROLLBACK');
        $log[] = 'FAILED — rolled back: ' . $e->getMessage();
        error_log('manage-addresses seed failed: ' . $e->getMessage());
    }
}

// One-click folder link from the shared-address report.
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['do_linkfolders'])
    && check_admin_referer('kop_ma_apply')) {

    $a = (int)($_POST['folder_a'] ?? 0);
    $b = (int)($_POST['folder_b'] ?? 0);
    $note = sanitize_text_field((string)($_POST['note'] ?? ''));
    $names = $wpdb->get_results($wpdb->prepare(
        "SELECT id, name FROM {$fbv} WHERE type = 0 AND id IN (%d, %d)", $a, $b
    ));
    if ($a <= 0 || $b <= 0 || $a === $b || count($names) !== 2) {
        $log[] = 'Could not link: one of the folders no longer exists.';
    } else {
        $ins = $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO {$links_tbl} (folder_a, folder_b, note) VALUES (%d, %d, %s)",
            min($a, $b), max($a, $b), $note
        ));
        $log_ok = (bool)$ins;
        $log[] = $ins
            ? 'Folders linked — facility document feeds now merge them. Manage links in link-folders.php.'
            : 'Those folders were already linked.';
    }
}

// ---------------------------------------------------------------------------
// Views
// ---------------------------------------------------------------------------
$view = ($_GET['view'] ?? '') === 'shared' ? 'shared' : 'scan';
$q = trim((string)($_GET['q'] ?? ''));

$addr_count = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$addr_tbl}");
$membership_count = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$fa_tbl}");
$dismissals = $wpdb->get_results("SELECT norm_key, label, created_at FROM {$dismissals_tbl} ORDER BY created_at DESC");

/** Folder-name normalization for facility -> folder resolution. */
function kop_ma_name_norm($text) {
    $t = mb_strtolower((string)$text);
    $t = html_entity_decode($t, ENT_QUOTES);
    $t = preg_replace('/[^a-z0-9\s]/u', ' ', $t);
    return preg_replace('/\s+/', ' ', trim($t));
}

/** Is the folder's ancestry broken (ghost-tree orphan)? */
function kop_ma_is_orphan($fid, $by_id) {
    $cur = (int)$fid;
    for ($i = 0; $i < 15; $i++) {
        if (!isset($by_id[$cur])) {
            return true;
        }
        $parent = (int)$by_id[$cur]->parent;
        if ($parent === 0) {
            return false;
        }
        $cur = $parent;
    }
    return false;
}

/** Full "Parent / Child" path for a folder id. */
function kop_ma_path($fid, $by_id, $depth = 0) {
    if ($depth > 10 || !isset($by_id[(int)$fid])) {
        return '(deleted folder #' . (int)$fid . ')';
    }
    $f = $by_id[(int)$fid];
    $prefix = ((int)$f->parent !== 0) ? kop_ma_path($f->parent, $by_id, $depth + 1) . ' / ' : '';
    return $prefix . $f->name;
}

$scan_groups = [];
$shared = [];
$fb_by_id = [];

if ($view === 'scan') {
    $scan_groups = kop_ma_scan();
    if ($q !== '') {
        $scan_groups = array_filter($scan_groups, static function ($g) use ($q) {
            $hay = $g['display']['street'] . ' ' . $g['display']['city'] . ' ' . $g['display']['state'];
            foreach ($g['members'] as $m) {
                $hay .= ' ' . $m['facility'];
            }
            return mb_stripos($hay, $q) !== false;
        });
    }
    $existing_keys = [];
    foreach ($wpdb->get_results("SELECT id, norm_key FROM {$addr_tbl}") as $r) {
        $existing_keys[$r->norm_key] = (int)$r->id;
    }
} else {
    // Shared view: addresses hosting 2+ distinct facilities.
    $rows = $wpdb->get_results(
        "SELECT fa.address_id, fa.facility, fa.role, fa.from_year, fa.to_year,
                a.street, a.city, a.state, a.zip
         FROM {$fa_tbl} fa
         JOIN {$addr_tbl} a ON a.id = fa.address_id
         WHERE fa.address_id IN (
             SELECT address_id FROM {$fa_tbl}
             GROUP BY address_id HAVING COUNT(DISTINCT facility) >= 2
         )
         ORDER BY a.state, a.street, fa.facility"
    );
    foreach ($rows as $r) {
        $aid = (int)$r->address_id;
        if (!isset($shared[$aid])) {
            $shared[$aid] = [
                'street' => $r->street, 'city' => $r->city,
                'state' => $r->state, 'zip' => $r->zip,
                'facilities' => [],
            ];
        }
        $shared[$aid]['facilities'][$r->facility][] = [
            'role' => $r->role, 'from' => $r->from_year, 'to' => $r->to_year,
        ];
    }
    if ($q !== '') {
        $shared = array_filter($shared, static function ($s) use ($q) {
            $hay = $s['street'] . ' ' . $s['city'] . ' ' . $s['state'] . ' '
                . implode(' ', array_keys($s['facilities']));
            return mb_stripos($hay, $q) !== false;
        });
    }

    // Facility -> folder resolution (exact normalized-name match, healthy
    // folders preferred) so we can tell which shared addresses still have
    // unmerged folders.
    $fb_folders = $wpdb->get_results("SELECT id, name, parent FROM {$fbv} WHERE type = 0");
    foreach ($fb_folders as $f) {
        $fb_by_id[(int)$f->id] = $f;
    }
    $fb_by_norm = [];
    foreach ($fb_folders as $f) {
        $n = kop_ma_name_norm($f->name);
        if ($n !== '') {
            $fb_by_norm[$n][] = (int)$f->id;
        }
    }
    $resolve_folder = static function ($facility) use ($fb_by_norm, $fb_by_id) {
        $n = kop_ma_name_norm($facility);
        $ids = $fb_by_norm[$n] ?? [];
        $healthy = array_values(array_filter($ids, static function ($fid) use ($fb_by_id) {
            return !kop_ma_is_orphan($fid, $fb_by_id);
        }));
        $pick = $healthy ?: $ids;
        return $pick ? $pick[0] : null;
    };

    foreach ($shared as $aid => &$s) {
        // One representative folder per facility, then connected components
        // under the existing equivalence (same-name + links).
        $folder_of = [];
        foreach (array_keys($s['facilities']) as $fac) {
            $folder_of[$fac] = $resolve_folder($fac);
        }
        $s['folder_of'] = $folder_of;

        $components = []; // rep folder id => [member folder ids...]
        foreach ($folder_of as $fid) {
            if ($fid === null) {
                continue;
            }
            $group = function_exists('kop_get_equivalent_folder_ids')
                ? kop_get_equivalent_folder_ids($fid)
                : [$fid];
            $found = null;
            foreach ($components as $rep => $members) {
                if (array_intersect($group, $members)) {
                    $found = $rep;
                    break;
                }
            }
            if ($found === null) {
                $components[$fid] = $group;
            }
        }
        $s['suggestions'] = [];
        $reps = array_keys($components);
        for ($i = 1; $i < count($reps); $i++) {
            $s['suggestions'][] = [$reps[0], $reps[$i]];
        }
    }
    unset($s);
}
?><!DOCTYPE html>
<html><head><meta charset="utf-8"><title>Manage Addresses</title>
<style>
body { font-family: system-ui, sans-serif; margin: 24px; color: #000435; background: #F2EEDF; max-width: 1200px; }
h1 { font-size: 1.15rem; margin: 0 0 4px; }
p.help { font-size: 0.85rem; color: #333; max-width: 900px; }
table { border-collapse: collapse; background: #fff; font-size: 0.82rem; width: 100%; }
th, td { border: 1px solid #ccc; padding: 4px 8px; text-align: left; vertical-align: top; }
th { background: #000080; color: #fff; }
.ok { color: #1b7e3c; } .warn { color: #b8860b; } .bad { color: #c0392b; }
button { background: #33A7B5; color: #fff; border: none; border-radius: 6px; padding: 7px 12px; font-weight: 700; font-size: 0.85rem; cursor: pointer; }
button.small { padding: 4px 9px; font-size: 0.78rem; }
a.tab { display: inline-block; padding: 7px 12px; border-radius: 6px 6px 0 0; background: #ddd; color: #000435; text-decoration: none; font-weight: 700; font-size: 0.85rem; }
a.tab.active { background: #000080; color: #fff; }
.log { background: #fff; border: 1px solid #ccc; padding: 10px 14px; font-family: monospace; font-size: 0.8rem; margin: 10px 0; }
.role { color: #555; font-size: 0.76rem; }
.aid { color: #888; font-size: 0.76rem; }
input[type=search] { padding: 6px 9px; border: 1px solid #000080; border-radius: 6px; }
.bar { margin: 10px 0; display: flex; flex-wrap: wrap; gap: 8px; align-items: center; }
a { color: #000080; }
.pathsm { font-size: 0.76rem; color: #555; }
</style></head><body>
<h1>Manage Addresses <small style="font-weight:400">&mdash; physical-campus identity for facilities</small></h1>
<p class="help">Every street address in facilities_master and locations_master (main, additional, and former locations)
gets a stable <strong>address ID</strong>. Facilities that occupied the same campus under different
names then link together by address instead of tribal knowledge. Address identity is separate from
name aliases on purpose &mdash; a shared address only <em>suggests</em> that folders belong together;
you decide. Memberships are rebuilt from the facility data on every seed run, so keep editing
addresses in the facility form as usual.</p>

<div>
    <a class="tab <?php echo $view === 'scan' ? 'active' : ''; ?>" href="?">Scan &amp; seed</a>
    <a class="tab <?php echo $view === 'shared' ? 'active' : ''; ?>" href="?view=shared">Shared addresses (<?php
        echo (int)$wpdb->get_var("SELECT COUNT(*) FROM (SELECT address_id FROM {$fa_tbl} GROUP BY address_id HAVING COUNT(DISTINCT facility) >= 2) x");
    ?>)</a>
</div>

<?php if ($log): ?>
    <div class="log <?php echo $log_ok ? 'ok' : 'warn'; ?>"><?php echo implode('<br>', array_map('esc_html', $log)); ?></div>
<?php endif; ?>

<div class="bar">
    <form method="get" style="display:flex;gap:6px;align-items:center">
        <?php if ($view === 'shared'): ?><input type="hidden" name="view" value="shared"><?php endif; ?>
        <input type="search" name="q" value="<?php echo esc_attr($q); ?>" placeholder="Filter by street, city, state, facility" style="width:280px">
        <button type="submit" style="background:#000080">Filter</button>
    </form>
    <form method="post" style="margin-left:auto">
        <?php wp_nonce_field('kop_ma_apply'); ?>
        <button type="submit" name="do_seed" value="1" style="background:#EF9034"
            onclick="return window.confirm('Seed / refresh the address tables?\n\nNew addresses get IDs (existing IDs are kept), and facility-address memberships are rebuilt from the current facility data.');">
            Seed / refresh from facility data</button>
    </form>
    <small><?php echo $addr_count; ?> address rows &middot; <?php echo $membership_count; ?> memberships</small>
</div>

<?php if ($view === 'scan'): ?>

<p class="help"><?php echo count($scan_groups); ?> distinct address(es) found in the facility data
(grouped by normalized street + state + zip/city). Rows marked <span class="warn">new</span> get an
ID on the next seed run. Near-duplicates that failed to group (typos, missing zips) appear as
separate rows &mdash; fix the address in the facility form and re-scan. Values that are cities,
states, countries, or other non-address labels can be dismissed here.</p>

<table><thead><tr><th>ID</th><th>Address</th><th>Facilities at this address</th></tr></thead><tbody>
<?php foreach ($scan_groups as $key => $g):
    $d = $g['display'];
    $aid = $existing_keys[$key] ?? null;
    $addr_label = $d['street']
        . ($d['city'] !== '' ? ', ' . $d['city'] : '')
        . ($d['state'] !== '' ? ', ' . kop_ma_norm_state($d['state']) : '')
        . ($d['zip'] !== '' ? ' ' . $d['zip'] : '');
?>
    <tr>
        <td><?php echo $aid !== null ? '<span class="aid">#' . $aid . '</span>' : '<span class="warn">new</span>'; ?></td>
        <td>
            <?php echo esc_html($addr_label); ?>
            <form method="post" style="margin-top:6px">
                <?php wp_nonce_field('kop_ma_apply'); ?>
                <input type="hidden" name="norm_key" value="<?php echo esc_attr($key); ?>">
                <input type="hidden" name="label" value="<?php echo esc_attr($addr_label); ?>">
                <button type="submit" name="do_dismiss_address" value="1" class="small"
                    onclick="return window.confirm('Dismiss this scan candidate as not a physical address? It will be removed from the scan and seed results.');">
                    Not an address</button>
            </form>
        </td>
        <td>
        <?php
            $facs = [];
            foreach ($g['members'] as $m) {
                $extra = $m['role'] === 'current' ? '' : ' <span class="role">(' . esc_html($m['role'])
                    . ($m['from'] !== '' || $m['to'] !== '' ? ' ' . esc_html($m['from']) . '-' . esc_html($m['to']) : '')
                    . ')</span>';
                $facs[$m['facility'] . '|' . $m['role'] . '|' . $m['from']] = esc_html($m['facility']) . $extra;
            }
            echo implode('<br>', $facs);
        ?>
        </td>
    </tr>
<?php endforeach; ?>
</tbody></table>

<h2>Dismissed non-address candidates (<?php echo count($dismissals); ?>)</h2>
<?php if (!$dismissals): ?>
<p class="warn">No dismissed candidates.</p>
<?php else: ?>
<table><thead><tr><th>Candidate</th><th>Dismissed</th><th></th></tr></thead><tbody>
<?php foreach ($dismissals as $dismissal): ?>
    <tr>
        <td><?php echo esc_html($dismissal->label ?: $dismissal->norm_key); ?></td>
        <td><?php echo esc_html($dismissal->created_at); ?></td>
        <td>
            <form method="post" style="margin:0">
                <?php wp_nonce_field('kop_ma_apply'); ?>
                <input type="hidden" name="norm_key" value="<?php echo esc_attr($dismissal->norm_key); ?>">
                <button type="submit" name="do_restore_address_dismissal" value="1" class="small">Restore</button>
            </form>
        </td>
    </tr>
<?php endforeach; ?>
</tbody></table>
<?php endif; ?>

<?php else: ?>

<p class="help">Addresses where two or more distinct facility records stood &mdash; renames,
successors, or sister programs on one campus. Where their folders are not already merged, the
button writes a folder link (curated further in <a href="link-folders.php">link-folders.php</a>).
Run a seed first if this list looks stale.</p>

<?php if (!$shared): ?>
<p class="warn">No shared addresses<?php echo $membership_count ? '' : ' — run a seed first'; ?>.</p>
<?php else: ?>
<table><thead><tr><th>Address</th><th>Facilities</th><th>Folder status</th></tr></thead><tbody>
<?php foreach ($shared as $aid => $s):
    $addr_label = $s['street']
        . ($s['city'] !== '' ? ', ' . $s['city'] : '')
        . ($s['state'] !== '' ? ', ' . $s['state'] : '')
        . ($s['zip'] !== '' ? ' ' . $s['zip'] : '');
?>
    <tr>
        <td><span class="aid">#<?php echo $aid; ?></span> <?php echo esc_html($addr_label); ?></td>
        <td>
        <?php
            $lines = [];
            foreach ($s['facilities'] as $fac => $stints) {
                $bits = [];
                foreach ($stints as $st) {
                    $bits[] = $st['role'] . ($st['from'] !== '' || $st['to'] !== '' ? ' ' . $st['from'] . '-' . $st['to'] : '');
                }
                $folder_note = $s['folder_of'][$fac] === null ? ' <span class="bad">(no folder found)</span>' : '';
                $lines[] = esc_html($fac) . ' <span class="role">(' . esc_html(implode('; ', array_unique($bits))) . ')</span>' . $folder_note;
            }
            echo implode('<br>', $lines);
        ?>
        </td>
        <td>
        <?php if (!$s['suggestions']): ?>
            <?php
                $resolved = count(array_filter($s['folder_of'], static function ($v) { return $v !== null; }));
                echo $resolved >= 2
                    ? '<span class="ok">folders already merged</span>'
                    : '<span class="warn">fewer than two folders resolved</span>';
            ?>
        <?php else: ?>
            <?php foreach ($s['suggestions'] as $pair): ?>
                <form method="post" style="margin:0 0 6px">
                    <?php wp_nonce_field('kop_ma_apply'); ?>
                    <input type="hidden" name="folder_a" value="<?php echo (int)$pair[0]; ?>">
                    <input type="hidden" name="folder_b" value="<?php echo (int)$pair[1]; ?>">
                    <input type="hidden" name="note" value="<?php echo esc_attr('shared address #' . $aid . ': ' . mb_substr($addr_label, 0, 200)); ?>">
                    <div class="pathsm"><?php echo esc_html(kop_ma_path($pair[0], $fb_by_id)); ?><br>+ <?php echo esc_html(kop_ma_path($pair[1], $fb_by_id)); ?></div>
                    <button type="submit" name="do_linkfolders" value="1" class="small"
                        onclick="return window.confirm('Link these folders as the same facility?\n\nOnly do this for a rename/successor at this campus — NOT for distinct sister programs that merely shared it.');">
                        Link folders</button>
                </form>
            <?php endforeach; ?>
        <?php endif; ?>
        </td>
    </tr>
<?php endforeach; ?>
</tbody></table>
<?php endif; ?>

<?php endif; ?>
</body></html>
