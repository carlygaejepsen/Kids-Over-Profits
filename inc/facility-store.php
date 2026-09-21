<?php
/**
 * Canonical facility store - one normalizer, one validator, one identity
 * resolver, one write path.
 *
 * Part of the data model migration (docs/DATA-MODEL-MIGRATION.md section 5.5).
 * The v2 document shape is documented in docs/FACILITY-SCHEMA.md.
 *
 * Layering rule: everything above the "Database layer" banner is pure PHP with
 * no WordPress dependency, so scripts/normalize-dump.php and
 * scripts/rehearse-migration.php can run the same code from the CLI against a
 * database dump. Only the functions below that banner touch the database.
 *
 * Legacy shapes accepted by kop_facility_normalize():
 *   - a `__facility_ref` wrapper row  {__facility_ref, name, displayName, city, state, data:{facility}}
 *   - a nested facility entry from locations_master / an operator project
 *   - a v2 document (normalizing a v2 document is a no-op)
 */

if (!defined('KOP_FACILITY_SCHEMA_VERSION')) {
    define('KOP_FACILITY_SCHEMA_VERSION', 2);
}

// ---------------------------------------------------------------------------
// Vocabularies
// ---------------------------------------------------------------------------

if (!function_exists('kop_facility_states')) {
    /** Two-letter code => uppercase state name (the locations_master key). */
    function kop_facility_states() {
        return array(
            'AL' => 'ALABAMA', 'AK' => 'ALASKA', 'AZ' => 'ARIZONA', 'AR' => 'ARKANSAS',
            'CA' => 'CALIFORNIA', 'CO' => 'COLORADO', 'CT' => 'CONNECTICUT', 'DE' => 'DELAWARE',
            'DC' => 'DISTRICT OF COLUMBIA', 'FL' => 'FLORIDA', 'GA' => 'GEORGIA', 'HI' => 'HAWAII',
            'ID' => 'IDAHO', 'IL' => 'ILLINOIS', 'IN' => 'INDIANA', 'IA' => 'IOWA',
            'KS' => 'KANSAS', 'KY' => 'KENTUCKY', 'LA' => 'LOUISIANA', 'ME' => 'MAINE',
            'MD' => 'MARYLAND', 'MA' => 'MASSACHUSETTS', 'MI' => 'MICHIGAN', 'MN' => 'MINNESOTA',
            'MS' => 'MISSISSIPPI', 'MO' => 'MISSOURI', 'MT' => 'MONTANA', 'NE' => 'NEBRASKA',
            'NV' => 'NEVADA', 'NH' => 'NEW HAMPSHIRE', 'NJ' => 'NEW JERSEY', 'NM' => 'NEW MEXICO',
            'NY' => 'NEW YORK', 'NC' => 'NORTH CAROLINA', 'ND' => 'NORTH DAKOTA', 'OH' => 'OHIO',
            'OK' => 'OKLAHOMA', 'OR' => 'OREGON', 'PA' => 'PENNSYLVANIA', 'RI' => 'RHODE ISLAND',
            'SC' => 'SOUTH CAROLINA', 'SD' => 'SOUTH DAKOTA', 'TN' => 'TENNESSEE', 'TX' => 'TEXAS',
            'UT' => 'UTAH', 'VT' => 'VERMONT', 'VA' => 'VIRGINIA', 'WA' => 'WASHINGTON',
            'WV' => 'WEST VIRGINIA', 'WI' => 'WISCONSIN', 'WY' => 'WYOMING',
            // Territories appear in scraped addresses even though they have no
            // locations_master row today.
            'PR' => 'PUERTO RICO', 'VI' => 'VIRGIN ISLANDS', 'GU' => 'GUAM',
        );
    }
}

if (!function_exists('kop_facility_status_vocabulary')) {
    /** The only statuses a v2 document may carry. */
    function kop_facility_status_vocabulary() {
        return array('Open', 'Closed', 'Suspended', 'Transferred', 'Unknown');
    }
}

if (!function_exists('kop_facility_countries')) {
    /**
     * Country aliases => canonical English name. Every country that appears in
     * the data today plus the aliases called out in the migration spec.
     */
    function kop_facility_countries() {
        return array(
            'us' => 'United States', 'usa' => 'United States', 'u s' => 'United States',
            'u s a' => 'United States', 'united states' => 'United States',
            'united states of america' => 'United States', 'america' => 'United States',
            'uk' => 'United Kingdom', 'u k' => 'United Kingdom', 'england' => 'United Kingdom',
            'scotland' => 'United Kingdom', 'wales' => 'United Kingdom',
            'northern ireland' => 'United Kingdom', 'great britain' => 'United Kingdom',
            'jersey' => 'United Kingdom', 'united kingdom' => 'United Kingdom',
            'jerusalem' => 'Israel', 'israel' => 'Israel',
            'the netherlands' => 'Netherlands', 'netherlands' => 'Netherlands', 'holland' => 'Netherlands',
            'argentina' => 'Argentina', 'australia' => 'Australia', 'canada' => 'Canada',
            'costa rica' => 'Costa Rica', 'czech republic' => 'Czech Republic',
            'czechia' => 'Czech Republic', 'dominican republic' => 'Dominican Republic',
            'fiji' => 'Fiji', 'italy' => 'Italy', 'jamaica' => 'Jamaica', 'mexico' => 'Mexico',
            'new zealand' => 'New Zealand', 'samoa' => 'Samoa', 'western samoa' => 'Samoa',
            'united arab emirates' => 'United Arab Emirates', 'uae' => 'United Arab Emirates',
            'germany' => 'Germany', 'ireland' => 'Ireland', 'spain' => 'Spain',
            'portugal' => 'Portugal', 'south africa' => 'South Africa', 'kenya' => 'Kenya',
            'india' => 'India', 'philippines' => 'Philippines', 'thailand' => 'Thailand',
            'brazil' => 'Brazil', 'peru' => 'Peru', 'guatemala' => 'Guatemala',
            'honduras' => 'Honduras', 'belize' => 'Belize', 'panama' => 'Panama',
            'puerto rico' => 'United States',
        );
    }
}

// ---------------------------------------------------------------------------
// Scalar helpers
// ---------------------------------------------------------------------------

if (!function_exists('kop_facility_str')) {
    /** Any scalar to a trimmed string; arrays and objects become ''. */
    function kop_facility_str($value) {
        if (is_string($value)) return trim($value);
        if (is_int($value) || is_float($value)) return trim((string)$value);
        if (is_bool($value)) return $value ? 'true' : 'false';
        return '';
    }
}

if (!function_exists('kop_facility_list')) {
    /**
     * Any value to a list. Strings are trimmed and empties dropped; scalars are
     * de-duplicated while preserving order; nested arrays/objects are kept as
     * they are (profileLinks and formerLocations carry structured entries).
     */
    function kop_facility_list($value) {
        if ($value === null || $value === '') return array();
        if (!is_array($value)) return array(kop_facility_str($value));

        $out = array();
        $seen = array();
        foreach ($value as $item) {
            if ($item === null) continue;
            if (is_array($item)) {
                $out[] = $item;
                continue;
            }
            $s = kop_facility_str($item);
            if ($s === '') continue;
            $key = mb_strtolower($s);
            if (isset($seen[$key])) continue;
            $seen[$key] = true;
            $out[] = $s;
        }
        return array_values($out);
    }
}

if (!function_exists('kop_facility_map')) {
    /** Any value to a key => value map. A list becomes {_legacy: [...]}. */
    function kop_facility_map($value) {
        if (!is_array($value) || $value === array()) return array();
        $is_list = array_keys($value) === range(0, count($value) - 1);
        return $is_list ? array('_legacy' => array_values($value)) : $value;
    }
}

if (!function_exists('kop_facility_int')) {
    /**
     * Integer or null. A non-numeric non-empty value is dropped and reported
     * through $rejected so the caller can keep the original in notes.
     */
    function kop_facility_int($value, &$rejected = null, $label = '') {
        if ($value === null || $value === '' || is_array($value)) return null;
        if (is_int($value)) return $value;
        if (is_float($value)) return (int)$value;

        $s = trim((string)$value);
        if ($s === '') return null;
        if (preg_match('/^-?\d+$/', $s)) return (int)$s;
        // "1994-2005", "circa 1994", "1994?" - take the first 4-digit run for
        // years, the first integer otherwise.
        if (preg_match('/\d{4}/', $s, $m) && (int)$m[0] > 1500 && (int)$m[0] < 2200) {
            if ($rejected !== null && $label !== '') $rejected[] = $label . ': ' . $s;
            return (int)$m[0];
        }
        if (preg_match('/\d+/', $s, $m)) {
            if ($rejected !== null && $label !== '') $rejected[] = $label . ': ' . $s;
            return (int)$m[0];
        }
        if ($rejected !== null && $label !== '') $rejected[] = $label . ': ' . $s;
        return null;
    }
}

if (!function_exists('kop_facility_bool')) {
    /** Boolean or null (null when the field was simply absent). */
    function kop_facility_bool($value) {
        if ($value === null || $value === '') return null;
        if (is_bool($value)) return $value;
        $s = strtolower(trim((string)$value));
        if (in_array($s, array('1', 'true', 'yes', 'y'), true)) return true;
        if (in_array($s, array('0', 'false', 'no', 'n'), true)) return false;
        return null;
    }
}

// ---------------------------------------------------------------------------
// Identity helpers
// ---------------------------------------------------------------------------

if (!function_exists('kop_facility_name_key')) {
    /**
     * Grouping key for a facility name.
     *
     * Same rules as kop_normalize_facility_name_rules() in inc/rest-api.php, on
     * purpose: search resolves its curated alias map on top of this key, so the
     * two keep agreeing. The alias map is deliberately NOT applied here - two
     * facilities that are aliases of one another would then collide on the
     * (name_key, state, city) unique index.
     */
    function kop_facility_name_key($name) {
        $s = mb_strtolower(trim((string)$name));
        if ($s === '') return '';

        // Scraper metadata after a colon ("Administrator: Jane Doe") only -
        // a colon used as a brand separator ("The Journey: Homestead") stays.
        $colon = mb_strpos($s, ':');
        if ($colon !== false) {
            $before = trim(mb_substr($s, 0, $colon));
            $after  = trim(mb_substr($s, $colon + 1));
            $label_re = '/(?:board\s+chairperson|chairperson|administrator|executive\s+director|director|owner|operator|date\s+of\s+site\s+visit|site\s+visit|visit\s+date|inspection\s+date|licensee|licensed\s+capacity)$/iu';
            $after_is_date = (bool)preg_match('#^\d{1,4}[/\-.]\d{1,2}(?:[/\-.]\d{2,4})?\b#', $after);
            if ($after_is_date || preg_match($label_re, $before)) {
                $s = $before;
            }
        }

        $s = preg_replace(
            '/\s+(?:board\s+chairperson|chairperson|administrator|executive\s+director|director|owner|operator|date\s+of\s+site\s+visit|site\s+visit|visit\s+date|inspection\s+date|licensee|licensed\s+capacity).*$/iu',
            '',
            $s
        );
        $s = preg_replace('#\s+d\s*/?\s*b\s*/?\s*a\s+#u', ' ', $s);
        $s = preg_replace('/\s*[\-\x{2013}\x{2014}]\s*/u', ' ', $s);
        $s = preg_replace('/\s*&\s*/', ' and ', $s);
        $s = preg_replace('/[^\w\s]/u', '', $s);
        $s = preg_replace('/\s+/', ' ', $s);
        $s = trim($s);
        $s = preg_replace('/^the\s+/u', '', $s);
        $s = preg_replace('/\s+(?:l\s*l\s*c|llc|inc|incorporated|ltd|limited|co|corp|corporation)$/u', '', $s);

        return trim($s);
    }
}

if (!function_exists('kop_facility_city_key')) {
    /** Loose city key for identity matching ("St. George" == "st george"). */
    function kop_facility_city_key($city) {
        $s = mb_strtolower(trim((string)$city));
        $s = preg_replace('/[^\w\s]/u', '', $s);
        $s = preg_replace('/\s+/', ' ', $s);
        return trim($s);
    }
}

if (!function_exists('kop_facility_state_code')) {
    /**
     * Any state spelling to its two-letter code, or null when the value is not
     * a US state. Tolerates trailing zips ("UT 84601") and punctuation.
     */
    function kop_facility_state_code($value) {
        if (!is_string($value) && !is_numeric($value)) return null;
        $s = strtoupper(trim((string)$value));
        if ($s === '') return null;
        $s = preg_replace('/[\s,]*\d{5}(?:-\d{4})?\s*$/', '', $s);
        $s = trim($s, " \t.,");
        if ($s === '') return null;

        $states = kop_facility_states();
        if (isset($states[$s])) return $s;
        $flipped = array_flip($states);
        if (isset($flipped[$s])) return $flipped[$s];
        // "Utah." / "N. Carolina" style noise.
        $collapsed = preg_replace('/\s+/', ' ', str_replace('.', '', $s));
        if (isset($states[$collapsed])) return $collapsed;
        if (isset($flipped[$collapsed])) return $flipped[$collapsed];
        return null;
    }
}

if (!function_exists('kop_facility_country_name')) {
    /**
     * Any country spelling to its canonical English name. Unknown non-empty
     * values are returned trimmed (never dropped); the validator flags them.
     */
    function kop_facility_country_name($value) {
        $s = kop_facility_str($value);
        if ($s === '') return null;
        $key = preg_replace('/\s+/', ' ', trim(preg_replace('/[^\w\s]/u', ' ', mb_strtolower($s))));
        $map = kop_facility_countries();
        if (isset($map[$key])) return $map[$key];
        return $s;
    }
}

// ---------------------------------------------------------------------------
// Address parsing
// ---------------------------------------------------------------------------

if (!function_exists('kop_facility_split_street_city')) {
    /**
     * Split "616 High St. Bath" at the last street-type word. Returns
     * [street, city]; when no street-type word is found the whole segment is
     * treated as the street and the city is empty.
     */
    function kop_facility_split_street_city($segment) {
        $segment = trim((string)$segment);
        $suffix = '(?:st|street|ave|avenue|rd|road|dr|drive|blvd|boulevard|ln|lane|way|hwy|highway|ct|court|pkwy|parkway|pl|place|cir|circle|trl|trail|loop|rte|route|pike|ter|terrace|sq|square)';
        if (preg_match('/^(.*\b' . $suffix . '\.?)\s+([A-Za-z][A-Za-z .\'-]*)$/i', $segment, $m)) {
            // Directional tails ("N", "NW") belong to the street, not the city.
            $city = trim($m[2]);
            if (!preg_match('/^(?:n|s|e|w|ne|nw|se|sw)\.?$/i', $city)) {
                return array(trim($m[1]), $city);
            }
        }
        return array($segment, '');
    }
}

if (!function_exists('kop_facility_parse_address')) {
    /**
     * Split a one-line address into parts. Grown from
     * kop_promote_split_address() in api/facility-promotion.php, which only
     * returned [city, state].
     *
     * @return array {street, city, state (2-letter or ''), zip, country}
     */
    function kop_facility_parse_address($raw) {
        $out = array('street' => '', 'city' => '', 'state' => '', 'zip' => '', 'country' => '');
        $raw = kop_facility_str($raw);
        // A trailing bracketed note ("..., UT 84721 (girls campus; separate
        // record)") is not part of the address.
        $raw = trim(preg_replace('/\s*\([^()]*\)\s*$/u', '', $raw));
        if ($raw === '') return $out;

        $parts = array_values(array_filter(array_map('trim', explode(',', $raw)), 'strlen'));
        if (!$parts) return $out;

        // Trailing country ("..., Mexico"): peel it off and parse the rest.
        $last = $parts[count($parts) - 1];
        if (count($parts) > 1 && kop_facility_state_code($last) === null) {
            $country_key = preg_replace('/\s+/', ' ', trim(preg_replace('/[^\w\s]/u', ' ', mb_strtolower($last))));
            $countries = kop_facility_countries();
            if (isset($countries[$country_key])) {
                $out['country'] = $countries[$country_key];
                array_pop($parts);
                if (!$parts) return $out;
            }
        }

        $last = $parts[count($parts) - 1];

        // Trailing zip, on its own segment or glued to the state ("UT 84601").
        if (preg_match('/^(\d{5}(?:-\d{4})?)$/', $last, $m)) {
            $out['zip'] = $m[1];
            array_pop($parts);
            if (!$parts) return $out;
            $last = $parts[count($parts) - 1];
        } elseif (preg_match('/^(.*?)[\s]+(\d{5}(?:-\d{4})?)$/', $last, $m)) {
            $out['zip'] = $m[2];
            $last = trim($m[1]);
            $parts[count($parts) - 1] = $last;
        }

        $state = kop_facility_state_code($last);
        // "10503 Metric Dr Dallas TX 75243": no comma before the state. Only
        // read when a zip follows, so a street ending "Rd NE" is not Nebraska.
        if ($state === null && $out['zip'] !== '' && preg_match('/^(.*\S)\s+([A-Z]{2})\.?$/', $last, $gm)
            && kop_facility_state_code($gm[2]) !== null) {
            $state = kop_facility_state_code($gm[2]);
            array_splice($parts, count($parts) - 1, 1, array(trim($gm[1]), $gm[2]));
        }
        if ($state !== null) {
            $out['state'] = $state;
            if ($out['country'] === '') $out['country'] = 'United States';
            array_pop($parts);
            if ($parts) {
                $out['city'] = array_pop($parts);
                $out['street'] = implode(', ', $parts);
                // "616 High St. Bath, ME": no comma between street and city.
                if ($out['street'] === '' && preg_match('/^\d/', $out['city'])) {
                    [$out['street'], $out['city']] = kop_facility_split_street_city($out['city']);
                }
            }
            return $out;
        }

        // No state in the line. One segment: a street when it starts with a
        // house number, a city otherwise. Several segments: last is the city.
        if (count($parts) === 1) {
            if (preg_match('/^\d/', $parts[0])) {
                $out['street'] = $parts[0];
            } else {
                $out['city'] = $parts[0];
            }
            return $out;
        }
        $out['city'] = array_pop($parts);
        $out['street'] = implode(', ', $parts);
        return $out;
    }
}

if (!function_exists('kop_facility_location_text_segments')) {
    /**
     * Split a free-text location into one segment per place:
     * "Viera, FL / Rutland, MA" => ["Viera, FL", "Rutland, MA"].
     */
    function kop_facility_location_text_segments($text) {
        $text = kop_facility_str($text);
        if ($text === '') return array();
        $parts = preg_split('#\s*[/;|\n]\s*#u', $text);
        return array_values(array_filter(array_map('trim', $parts), 'strlen'));
    }
}

if (!function_exists('kop_facility_location_text_places')) {
    /**
     * The places a free-text location actually names, one entry per segment
     * that names one: {raw, city, state (code or null), country (canonical or
     * null), country_raw (as written or null)}.
     *
     * Replaces the substring matching the state and country pages used to do,
     * which put "La Verne, CA" on Louisiana (the word LA), "Mt. Pleasant" on
     * Montana, "Kansas City, MO" on Kansas and "Mexico, MO" on Mexico. A
     * segment is read in this order:
     *   1. as an address ("city, ST 12345", "..., Mexico"),
     *   2. ending in a capitalized state code with no comma ("Provo UT"),
     *   3. as a bare country name ("Mexico"),
     *   4. containing a full state or country name as a whole phrase
     *      ("Southern Utah"), except inside a longer place name: "Kansas City",
     *      "Nevada City", "Baja California", "New Mexico" for Mexico, "West
     *      Virginia" for Virginia. Two-letter codes are never matched this way.
     */
    function kop_facility_location_text_places($text) {
        $places = array();
        $states = kop_facility_states();
        $countries = kop_facility_countries();

        foreach (kop_facility_location_text_segments($text) as $segment) {
            $place = array('raw' => $segment, 'city' => '', 'state' => null, 'country' => null, 'country_raw' => null);
            $parsed = kop_facility_parse_address($segment);

            if ($parsed['state'] !== '') {
                $place['state'] = $parsed['state'];
                $place['country'] = 'United States';
                $place['city'] = $parsed['city'];
                $places[] = $place;
                continue;
            }
            if ($parsed['country'] !== '') {
                $pieces = array_map('trim', explode(',', $segment));
                $place['country'] = $parsed['country'];
                $place['country_raw'] = end($pieces);
                $place['city'] = $parsed['city'];
                $places[] = $place;
                continue;
            }
            if (preg_match('/(?:^|[\s,])([A-Z]{2})(?:\s+\d{5}(?:-\d{4})?)?\s*$/', $segment, $m)
                && kop_facility_state_code($m[1]) !== null) {
                $place['state'] = $m[1];
                $place['country'] = 'United States';
                $place['city'] = trim(preg_replace('/[\s,]*[A-Z]{2}(?:\s+\d{5}(?:-\d{4})?)?\s*$/', '', $segment));
                $places[] = $place;
                continue;
            }
            $segment_key = preg_replace('/\s+/', ' ', trim(preg_replace('/[^\w\s]/u', ' ', mb_strtolower($segment))));
            if (isset($countries[$segment_key])) {
                $place['country'] = $countries[$segment_key];
                $place['country_raw'] = $segment;
                $places[] = $place;
                continue;
            }

            // Whole-phrase names. Longest first, and each hit is blanked out so
            // "west virginia" is not read again as "virginia".
            $haystack = ' ' . $segment_key . ' ';
            $names = array();
            foreach ($states as $code => $upper) $names[] = array(strtolower($upper), $code, null);
            foreach ($countries as $alias => $country) {
                if (strlen($alias) >= 4 && $country !== 'United States') $names[] = array($alias, null, $country);
            }
            usort($names, function ($a, $b) { return strlen($b[0]) - strlen($a[0]); });
            foreach ($names as $entry) {
                list($name, $code, $country) = $entry;
                $pattern = '/(?<!new |west |baja )(?<= )' . preg_quote($name, '/') . '(?= )(?! city )/u';
                if (!preg_match($pattern, $haystack)) continue;
                $haystack = preg_replace('/(?<= )' . preg_quote($name, '/') . '(?= )/u', str_repeat('_', strlen($name)), $haystack, 1);
                $hit = $place;
                if ($code !== null) {
                    $hit['state'] = $code;
                    $hit['country'] = 'United States';
                } else {
                    $hit['country'] = $country;
                    $hit['country_raw'] = $name;
                }
                $places[] = $hit;
            }
        }

        return $places;
    }
}

if (!function_exists('kop_facility_location_text_names_state')) {
    /** Does the free-text location name this US state (code or name)? */
    function kop_facility_location_text_names_state($text, $state) {
        $code = kop_facility_state_code($state);
        if ($code === null) return false;
        foreach (kop_facility_location_text_places($text) as $place) {
            if ($place['state'] === $code) return true;
        }
        return false;
    }
}

if (!function_exists('kop_facility_location_text_names_country')) {
    /**
     * Does the free-text location name this country? Compared by the name as
     * written and by canonical name, so the Jersey page matches "St Helier,
     * Jersey" without every United Kingdom address landing on it.
     */
    function kop_facility_location_text_names_country($text, $country) {
        $wanted = mb_strtolower(trim((string)$country));
        if ($wanted === '') return false;
        foreach (kop_facility_location_text_places($text) as $place) {
            if ($place['country'] === null || $place['country'] === 'United States') continue;
            if ($place['country_raw'] !== null && mb_strtolower(trim($place['country_raw'])) === $wanted) return true;
            if (mb_strtolower($place['country']) === $wanted) return true;
        }
        return false;
    }
}

// ---------------------------------------------------------------------------
// Normalizer
// ---------------------------------------------------------------------------

if (!function_exists('kop_facility_blank_document')) {
    /** The v2 skeleton. Every section is always present, so readers stop guarding. */
    function kop_facility_blank_document() {
        return array(
            'schema_version' => KOP_FACILITY_SCHEMA_VERSION,
            'facility_id'    => null,
            'identification' => array(
                'name' => '', 'nameKey' => '', 'currentName' => '',
                'otherNames' => array(), 'pastNames' => array(),
                'currentOperator' => '', 'currentOwners' => array(),
                'otherOperators' => array(), 'pastOperators' => array(),
                'knownReferrers' => array(), 'investors' => array(),
            ),
            'location' => array(
                'raw' => '', 'text' => '',
                'street' => '', 'city' => '', 'state' => null, 'zip' => '', 'country' => null,
                'additionalLocations' => array(), 'formerLocations' => array(),
            ),
            'operatingPeriod' => array(
                'startYear' => null, 'endYear' => null, 'status' => 'Unknown',
                'yearsOfOperation' => '', 'notes' => array(),
            ),
            'facilityDetails' => array(
                'type' => '', 'capacity' => null, 'currentCensus' => null,
                'ageRange' => array('min' => null, 'max' => null),
                'gender' => '', 'isPrivatelyOwned' => null,
            ),
            'staff' => array('administrator' => array(), 'notableStaff' => array(), 'pastTTIJobs' => array()),
            'accreditations' => array('current' => array(), 'past' => array()),
            'memberships'   => array(),
            'certifications' => array(),
            'licensing'     => array(),
            'profileLinks'  => array(),
            'resources'     => array(),
            'treatmentTypes' => array(),
            'philosophy'    => array(),
            'conditions'    => array(),
            'criticalIncidents' => array(),
            'notes'         => array(),
            'fieldNotes'    => array(),
            'documentFolderId' => null,
            'provenance'    => array(
                'sourceProject' => '', 'sourceProjectId' => null, 'sourceCategory' => '',
                'sourceOperator' => null, 'legacyIds' => array(), 'linkedFromRef' => false,
                'kopProfileVersion' => null, 'migratedAt' => '',
                'uniqueName' => '', 'source' => '',
            ),
            'legacy'        => array(),
        );
    }
}

if (!function_exists('kop_facility_top_level_keys')) {
    /** The exact top-level key set of a v2 document. */
    function kop_facility_top_level_keys() {
        return array_keys(kop_facility_blank_document());
    }
}

if (!function_exists('kop_facility_unwrap')) {
    /**
     * Peel a stored row down to the facility object plus its wrapper hints.
     *
     * @return array {facility, wrapper}
     */
    function kop_facility_unwrap(array $raw) {
        $wrapper = array();

        // __facility_ref row: the wrapper carries name/displayName/city/state.
        if (!empty($raw['__facility_ref']) && isset($raw['data']) && is_array($raw['data'])) {
            $wrapper = array(
                'unique_name' => kop_facility_str($raw['name'] ?? ''),
                'displayName' => kop_facility_str($raw['displayName'] ?? ''),
                'city'        => kop_facility_str($raw['city'] ?? ''),
                'state'       => is_string($raw['state'] ?? null) ? trim($raw['state']) : '',
                'timestamp'   => kop_facility_str($raw['timestamp'] ?? ''),
            );
            $inner = $raw['data'];
            // Double-wrapped rows (data.data) exist; peel one more level.
            if (isset($inner['data']) && is_array($inner['data']) && !isset($inner['facility'])) {
                $inner = $inner['data'];
            }
            // Keys that sit beside the facility, not in it: curated
            // matchAliases (api/apply-match-aliases.php) live there and are
            // authoritative for news and inspection matching, so they travel
            // with the facility instead of being dropped with the wrapper.
            foreach ($inner as $key => $value) {
                if ($key === 'facility' || $key === 'facilities' || $key === 'data') continue;
                $wrapper['row_extras'][$key] = $value;
            }
            if (isset($inner['facility']) && is_array($inner['facility'])) {
                return array('facility' => $inner['facility'], 'wrapper' => $wrapper);
            }
            if (isset($inner['facilities'][0]) && is_array($inner['facilities'][0])) {
                return array('facility' => $inner['facilities'][0], 'wrapper' => $wrapper);
            }
            return array('facility' => array(), 'wrapper' => $wrapper);
        }

        // Already a facility object (nested entry or v2 document).
        return array('facility' => $raw, 'wrapper' => $wrapper);
    }
}

if (!function_exists('kop_facility_normalize')) {
    /**
     * Any legacy shape to a v2 document.
     *
     * @param array $raw  ref row, nested entry, or v2 document.
     * @param array $opts facility_id, unique_name, location_key (the
     *                    locations_master row the copy sat in), updated_at,
     *                    source (a label kept in provenance).
     * @return array v2 document
     */
    function kop_facility_normalize(array $raw, array $opts = array()) {
        $unwrapped = kop_facility_unwrap($raw);
        $f = $unwrapped['facility'];
        $wrapper = $unwrapped['wrapper'];
        $doc = kop_facility_blank_document();
        $is_v2 = isset($f['schema_version']) && (int)$f['schema_version'] >= 2;

        $legacy_notes = array();   // values that could not be typed, kept in notes

        // --- identity ------------------------------------------------------
        $ident = isset($f['identification']) && is_array($f['identification']) ? $f['identification'] : array();
        $name = kop_facility_str($ident['name'] ?? '');
        if ($name === '') $name = kop_facility_str($ident['currentName'] ?? '');
        if ($name === '') $name = kop_facility_str($f['name'] ?? '');
        if ($name === '') $name = kop_facility_str($wrapper['displayName'] ?? '');
        if ($name === '') $name = kop_facility_str($wrapper['unique_name'] ?? '');

        $doc['identification']['name'] = $name;
        $doc['identification']['nameKey'] = kop_facility_name_key($name);
        $doc['identification']['currentName'] = kop_facility_str($ident['currentName'] ?? '');
        // currentName is the name a facility trades under now; one that only
        // repeats the name says nothing.
        if (mb_strtolower($doc['identification']['currentName']) === mb_strtolower($name)) $doc['identification']['currentName'] = '';
        $doc['identification']['otherNames'] = kop_facility_list($ident['otherNames'] ?? array());

        // previousNames (52 rows) is the same thing as pastNames.
        $past_names = array_merge(
            kop_facility_list($ident['pastNames'] ?? array()),
            kop_facility_list($ident['previousNames'] ?? array())
        );
        $doc['identification']['pastNames'] = kop_facility_list($past_names);

        $doc['identification']['currentOperator'] = kop_facility_str($ident['currentOperator'] ?? '');

        // currentOwner (string) and currentOwners (array) are the same fact.
        $owners = kop_facility_list($ident['currentOwners'] ?? array());
        $single_owner = kop_facility_str($ident['currentOwner'] ?? '');
        if ($single_owner !== '') $owners[] = $single_owner;
        $doc['identification']['currentOwners'] = kop_facility_list($owners);

        $doc['identification']['knownReferrers'] = kop_facility_list($ident['knownReferrers'] ?? array());
        $doc['identification']['otherOperators'] = kop_facility_list(
            $f['otherOperators'] ?? ($ident['otherOperators'] ?? array())
        );
        $doc['identification']['pastOperators'] = kop_facility_list(
            $f['pastOperators'] ?? ($ident['pastOperators'] ?? array())
        );
        $doc['identification']['investors'] = kop_facility_list(
            $f['investors'] ?? ($ident['investors'] ?? array())
        );

        // --- location ------------------------------------------------------
        $loc_details = isset($f['locationDetails']) && is_array($f['locationDetails']) ? $f['locationDetails'] : array();
        $addr_parts  = isset($f['addressParts']) && is_array($f['addressParts']) ? $f['addressParts'] : array();
        $addr_obj    = isset($f['address']) && is_array($f['address']) ? $f['address'] : array();
        $addr_raw    = is_string($f['address'] ?? null) ? trim($f['address']) : '';
        $v2_location = $is_v2 && isset($f['location']) && is_array($f['location']) ? $f['location'] : array();
        $loc_text    = is_string($f['location'] ?? null) ? trim($f['location']) : kop_facility_str($v2_location['text'] ?? '');

        if ($addr_raw === '' && $v2_location) $addr_raw = kop_facility_str($v2_location['raw'] ?? '');
        // Without an address, the first place the free text names is the
        // primary location; the others become additional locations below.
        $text_places = kop_facility_location_text_places($loc_text);
        $text_segments = kop_facility_location_text_segments($loc_text);
        $parsed = kop_facility_parse_address($addr_raw !== '' ? $addr_raw : ($text_segments[0] ?? ''));
        if ($addr_raw === '' && $parsed['state'] === '' && $parsed['country'] === '' && $text_places) {
            $parsed['state'] = (string)$text_places[0]['state'];
            $parsed['country'] = (string)$text_places[0]['country'];
            $parsed['city'] = $text_places[0]['city'];
        }

        $street = kop_facility_str($addr_obj['street'] ?? '');
        if ($street === '') $street = kop_facility_str($addr_parts['street'] ?? '');
        if ($street === '') $street = kop_facility_str($v2_location['street'] ?? '');
        if ($street === '') $street = $parsed['street'];

        $city = kop_facility_str($addr_obj['city'] ?? '');
        if ($city === '') $city = kop_facility_str($addr_parts['city'] ?? '');
        if ($city === '') $city = kop_facility_str($loc_details['city'] ?? '');
        if ($city === '') $city = kop_facility_str($v2_location['city'] ?? '');
        if ($city === '') $city = $parsed['city'];
        if ($city === '') $city = kop_facility_str($wrapper['city'] ?? '');

        // Address string wins over locationDetails: the 23 Kansas facilities
        // filed in the WISCONSIN row carry the right state in their address.
        $state = kop_facility_state_code($parsed['state']);
        if ($state === null) $state = kop_facility_state_code($addr_obj['state'] ?? '');
        if ($state === null) $state = kop_facility_state_code($addr_parts['state'] ?? '');
        if ($state === null) $state = kop_facility_state_code($loc_details['state'] ?? '');
        if ($state === null && $v2_location) $state = kop_facility_state_code($v2_location['state'] ?? '');
        if ($state === null) $state = kop_facility_state_code($wrapper['state'] ?? '');
        if ($state === null && !empty($opts['location_key'])) $state = kop_facility_state_code($opts['location_key']);

        $zip = kop_facility_str($addr_obj['zip'] ?? '');
        if ($zip === '') $zip = kop_facility_str($addr_parts['zip'] ?? '');
        if ($zip === '') $zip = kop_facility_str($loc_details['zip'] ?? '');
        if ($zip === '') $zip = kop_facility_str($v2_location['zip'] ?? '');
        if ($zip === '') $zip = $parsed['zip'];

        $country = kop_facility_country_name($loc_details['country'] ?? '');
        if ($country === null) $country = kop_facility_country_name($addr_obj['country'] ?? '');
        if ($country === null && $v2_location) $country = kop_facility_country_name($v2_location['country'] ?? '');
        if ($country === null && $parsed['country'] !== '') $country = $parsed['country'];
        if ($country === null && $state !== null) $country = 'United States';
        if ($country === null) {
            // A country row the copy sat in ("MEXICO") names the country.
            $from_key = kop_facility_country_name($opts['location_key'] ?? '');
            if ($from_key !== null && kop_facility_state_code($opts['location_key'] ?? '') === null) {
                $country = $from_key;
            }
        }
        // The wrapper state is a country name on international ref rows.
        if ($country === null && !empty($wrapper['state']) && kop_facility_state_code($wrapper['state']) === null) {
            $country = kop_facility_country_name($wrapper['state']);
        }

        $doc['location']['raw'] = $addr_raw;
        $doc['location']['text'] = $loc_text;
        $doc['location']['street'] = $street;
        $doc['location']['city'] = $city;
        $doc['location']['state'] = $state;
        $doc['location']['zip'] = $zip;
        $doc['location']['country'] = $country;

        $additional = $loc_details['additionalLocations'] ?? ($v2_location['additionalLocations'] ?? array());
        if (is_array($additional)) {
            foreach ($additional as $alt) {
                if (!is_array($alt)) {
                    $alt_raw = kop_facility_str($alt);
                    if ($alt_raw === '') continue;
                    $alt = array('address' => $alt_raw);
                }
                $alt_raw = kop_facility_str($alt['raw'] ?? ($alt['address'] ?? ''));
                $alt_parsed = kop_facility_parse_address($alt_raw);
                $entry = array(
                    'raw'    => $alt_raw,
                    // The form never writes this street; re-derive it from raw when there is one.
                    'street' => $alt_raw !== '' ? $alt_parsed['street'] : kop_facility_str($alt['street'] ?? ''),
                    'city'   => kop_facility_str($alt['city'] ?? '') ?: $alt_parsed['city'],
                    'state'  => kop_facility_state_code($alt['state'] ?? '') ?: kop_facility_state_code($alt_parsed['state']),
                    'zip'    => kop_facility_str($alt['zip'] ?? '') ?: $alt_parsed['zip'],
                    'country' => null,
                );
                $entry['country'] = kop_facility_country_name($alt['country'] ?? '')
                    ?? ($alt_parsed['country'] !== '' ? $alt_parsed['country'] : ($entry['state'] !== null ? 'United States' : null));
                if ($entry['raw'] === '' && $entry['street'] === '' && $entry['city'] === '') continue;
                $doc['location']['additionalLocations'][] = $entry;
            }
        }

        // Further places named in the free-text location ("Viera, FL /
        // Rutland, MA") are additional locations, so the facility is listed on
        // each of those pages and on no page it merely resembles.
        $listed = array(kop_facility_location_key($state, $country) => true);
        foreach ($doc['location']['additionalLocations'] as $alt) {
            $listed[kop_facility_location_key($alt['state'], $alt['country'])] = true;
        }
        foreach ($text_places as $place) {
            $key = kop_facility_location_key($place['state'], $place['country']);
            if ($key === null || isset($listed[$key])) continue;
            $listed[$key] = true;
            $place_parsed = kop_facility_parse_address($place['raw']);
            $doc['location']['additionalLocations'][] = array(
                'raw'     => $place['raw'],
                'street'  => $place_parsed['street'],
                'city'    => $place_parsed['city'],
                'state'   => $place['state'],
                'zip'     => $place_parsed['zip'],
                'country' => $place['country'],
            );
        }

        $former = $loc_details['formerLocations'] ?? ($v2_location['formerLocations'] ?? array());
        if (is_array($former)) {
            foreach ($former as $fl) {
                if (!is_array($fl)) continue;
                $fl_raw = kop_facility_str($fl['raw'] ?? ($fl['address'] ?? ''));
                $fl_parsed = kop_facility_parse_address($fl_raw);
                $entry = array(
                    'raw'      => $fl_raw,
                    'city'     => kop_facility_str($fl['city'] ?? '') ?: $fl_parsed['city'],
                    'state'    => kop_facility_state_code($fl['state'] ?? '') ?: kop_facility_state_code($fl_parsed['state']),
                    'country'  => kop_facility_country_name($fl['country'] ?? ''),
                    'fromYear' => kop_facility_int($fl['fromYear'] ?? null),
                    'toYear'   => kop_facility_int($fl['toYear'] ?? null),
                );
                if ($entry['state'] === null && $entry['country'] === null && $entry['raw'] === '' && $entry['city'] === '') continue;
                $doc['location']['formerLocations'][] = $entry;
            }
        }

        // --- operating period ----------------------------------------------
        $op = isset($f['operatingPeriod']) && is_array($f['operatingPeriod']) ? $f['operatingPeriod'] : array();
        $doc['operatingPeriod']['startYear'] = kop_facility_int($op['startYear'] ?? null, $legacy_notes, 'startYear');
        $doc['operatingPeriod']['endYear']   = kop_facility_int($op['endYear'] ?? null, $legacy_notes, 'endYear');
        $doc['operatingPeriod']['yearsOfOperation'] = kop_facility_str($op['yearsOfOperation'] ?? '');
        $doc['operatingPeriod']['notes'] = kop_facility_list($op['notes'] ?? array());

        $status_raw = kop_facility_str($op['status'] ?? '');
        $status = kop_facility_normalize_status($status_raw, $status_note);
        $doc['operatingPeriod']['status'] = $status;
        if ($status_note !== '') $doc['operatingPeriod']['notes'][] = $status_note;

        // Years only the free text holds fill the integer fields. The text
        // often covers one operator's tenure, not the facility's life, so an
        // end year is taken only for a closed facility whose start agrees.
        [$text_start, $text_end] = kop_facility_years_from_text($doc['operatingPeriod']['yearsOfOperation']);
        if ($doc['operatingPeriod']['startYear'] === null && $text_start !== null) $doc['operatingPeriod']['startYear'] = $text_start;
        if ($doc['operatingPeriod']['endYear'] === null && $text_end !== null && $status === 'Closed'
            && $doc['operatingPeriod']['startYear'] === $text_start) {
            $doc['operatingPeriod']['endYear'] = $text_end;
        }

        // --- facility details ------------------------------------------------
        $fd = isset($f['facilityDetails']) && is_array($f['facilityDetails']) ? $f['facilityDetails'] : array();
        $age = isset($fd['ageRange']) && is_array($fd['ageRange']) ? $fd['ageRange'] : array();
        $doc['facilityDetails']['type'] = kop_facility_type($fd['type'] ?? '');
        $doc['facilityDetails']['capacity'] = kop_facility_int($fd['capacity'] ?? null, $legacy_notes, 'capacity');
        $doc['facilityDetails']['currentCensus'] = kop_facility_int($fd['currentCensus'] ?? null, $legacy_notes, 'currentCensus');
        $doc['facilityDetails']['ageRange']['min'] = kop_facility_int($age['min'] ?? null, $legacy_notes, 'ageRange.min');
        $doc['facilityDetails']['ageRange']['max'] = kop_facility_int($age['max'] ?? null, $legacy_notes, 'ageRange.max');
        $doc['facilityDetails']['gender'] = kop_facility_gender($fd['gender'] ?? '', $gender_note);
        $doc['facilityDetails']['isPrivatelyOwned'] = kop_facility_bool(
            $f['isPrivatelyOwned'] ?? ($fd['isPrivatelyOwned'] ?? null)
        );

        // --- staff, lists, open maps -----------------------------------------
        $staff = isset($f['staff']) && is_array($f['staff']) ? $f['staff'] : array();
        $doc['staff']['administrator'] = kop_facility_person_list($staff['administrator'] ?? array());
        $doc['staff']['notableStaff']  = kop_facility_person_list($staff['notableStaff'] ?? array());
        $doc['staff']['pastTTIJobs']   = kop_facility_job_list($staff['pastTTIJobs'] ?? array());

        $acc = isset($f['accreditations']) && is_array($f['accreditations']) ? $f['accreditations'] : array();
        $doc['accreditations']['current'] = kop_facility_list($acc['current'] ?? array());
        $doc['accreditations']['past']    = kop_facility_list($acc['past'] ?? array());

        $doc['memberships']    = kop_facility_list($f['memberships'] ?? array());
        $doc['certifications'] = kop_facility_list($f['certifications'] ?? array());
        $doc['licensing']      = kop_facility_list($f['licensing'] ?? array());
        $doc['profileLinks']   = kop_facility_link_list($f['profileLinks'] ?? array());
        $doc['notes']          = kop_facility_list($f['notes'] ?? array());
        if ($gender_note !== '') $doc['notes'] = kop_facility_list(array_merge($doc['notes'], array($gender_note)));

        $doc['resources'] = kop_facility_resources($f['resources'] ?? array());

        // Open-ended checklists: the admin form invents keys at runtime, so
        // they pass through as maps rather than being enumerated here.
        $doc['treatmentTypes']    = kop_facility_map($f['treatmentTypes'] ?? array());
        $doc['philosophy']        = kop_facility_map($f['philosophy'] ?? array());
        $doc['conditions']        = kop_facility_map($f['conditions'] ?? array());
        $doc['criticalIncidents'] = kop_facility_map($f['criticalIncidents'] ?? array());
        $doc['fieldNotes']        = kop_facility_map($f['fieldNotes'] ?? array());

        $doc['documentFolderId'] = kop_facility_int($f['documentFolderId'] ?? null);

        // --- provenance ------------------------------------------------------
        $prov = $is_v2 && isset($f['provenance']) && is_array($f['provenance']) ? $f['provenance'] : array();
        $doc['provenance']['sourceProject']  = kop_facility_str($f['sourceProject'] ?? ($prov['sourceProject'] ?? ''));
        $doc['provenance']['sourceProjectId'] = kop_facility_int($f['sourceProjectId'] ?? ($prov['sourceProjectId'] ?? null));
        $doc['provenance']['sourceCategory'] = kop_facility_str($f['sourceCategory'] ?? ($prov['sourceCategory'] ?? ''));
        $source_operator = $f['sourceOperator'] ?? ($prov['sourceOperator'] ?? null);
        $doc['provenance']['sourceOperator'] = kop_facility_operator_block($source_operator);
        $doc['provenance']['linkedFromRef'] = (bool)($f['linkedFromRef'] ?? ($prov['linkedFromRef'] ?? false));
        $doc['provenance']['kopProfileVersion'] = kop_facility_int($f['kopProfileVersion'] ?? ($prov['kopProfileVersion'] ?? null));
        $doc['provenance']['legacyIds'] = array_values(array_unique(array_map(
            'intval',
            array_filter(kop_facility_list($prov['legacyIds'] ?? array()), 'is_numeric')
        )));
        $doc['provenance']['migratedAt'] = kop_facility_str($prov['migratedAt'] ?? '') ?: gmdate('c');
        if (!empty($opts['source'])) $doc['provenance']['source'] = kop_facility_str($opts['source']);

        // --- row identity ----------------------------------------------------
        $facility_id = kop_facility_int($opts['facility_id'] ?? ($f['facility_id'] ?? null));
        $doc['facility_id'] = $facility_id;
        if (!empty($opts['unique_name'])) {
            $doc['provenance']['uniqueName'] = kop_facility_str($opts['unique_name']);
        } elseif (!empty($wrapper['unique_name'])) {
            $doc['provenance']['uniqueName'] = $wrapper['unique_name'];
        }

        // --- anything this normalizer does not know about ---------------------
        $known = array(
            'identification', 'locationDetails', 'addressParts', 'address', 'location',
            'operatingPeriod', 'facilityDetails', 'staff', 'accreditations', 'memberships',
            'certifications', 'licensing', 'profileLinks', 'resources', 'treatmentTypes',
            'philosophy', 'conditions', 'criticalIncidents', 'notes', 'fieldNotes',
            'documentFolderId', 'otherOperators', 'pastOperators', 'investors',
            'isPrivatelyOwned', 'sourceProject', 'sourceProjectId', 'sourceCategory',
            'sourceOperator', 'linkedFromRef', 'kopProfileVersion', 'facility_id',
            'name', 'displayName', 'city', 'state', 'timestamp',
            'schema_version', 'provenance', 'legacy', '__facility_ref', 'data',
        );
        foreach ($f as $key => $value) {
            if (in_array($key, $known, true)) continue;
            $doc['legacy'][$key] = $value;
        }
        if ($is_v2 && isset($f['legacy']) && is_array($f['legacy'])) {
            $doc['legacy'] = array_merge($f['legacy'], $doc['legacy']);
        }
        foreach ((array)($wrapper['row_extras'] ?? array()) as $key => $value) {
            if (!array_key_exists($key, $doc['legacy']) && !in_array($key, $known, true)) {
                $doc['legacy'][$key] = $value;
            }
        }

        // Values that could not be typed are never silently dropped.
        foreach ($legacy_notes as $note) {
            $doc['operatingPeriod']['notes'][] = 'migration: unparsed ' . $note;
        }
        $doc['operatingPeriod']['notes'] = kop_facility_list($doc['operatingPeriod']['notes']);

        return $doc;
    }
}

// ---------------------------------------------------------------------------
// Field standards (docs/FACILITY-SCHEMA.md, "Standard shapes")
// ---------------------------------------------------------------------------

if (!function_exists('kop_facility_person_list')) {
    /**
     * Staff and operator people: every entry {name, role, pastJobs}. A bare
     * string is a name; an entry with nothing in it is dropped.
     */
    function kop_facility_person_list($value) {
        $out = array();
        foreach (kop_facility_list($value) as $item) {
            if (is_array($item)) {
                $jobs = $item['pastJobs'] ?? '';
                $entry = array(
                    'name'     => kop_facility_str($item['name'] ?? ($item['label'] ?? '')),
                    'role'     => kop_facility_str($item['role'] ?? ($item['title'] ?? '')),
                    'pastJobs' => is_array($jobs) ? implode('; ', kop_facility_list($jobs)) : kop_facility_str($jobs),
                );
            } else {
                $entry = array('name' => kop_facility_str($item), 'role' => '', 'pastJobs' => '');
            }
            if ($entry['name'] === '' && $entry['role'] === '' && $entry['pastJobs'] === '') continue;
            $out[] = $entry;
        }
        return $out;
    }
}

if (!function_exists('kop_facility_job_list')) {
    /**
     * staff.pastTTIJobs: every entry {role, organization, employer}, the shape
     * the admin form writes (employer mirrors organization). A bare string is
     * the organization.
     */
    function kop_facility_job_list($value) {
        $out = array();
        foreach (kop_facility_list($value) as $item) {
            if (is_array($item)) {
                $org = kop_facility_str($item['organization'] ?? '');
                if ($org === '') $org = kop_facility_str($item['employer'] ?? '');
                if ($org === '') $org = kop_facility_str($item['name'] ?? '');
                $role = kop_facility_str($item['role'] ?? '');
            } else {
                $org = kop_facility_str($item);
                $role = '';
            }
            if ($org === '' && $role === '') continue;
            $out[] = array('role' => $role, 'organization' => $org, 'employer' => $org);
        }
        return $out;
    }
}

if (!function_exists('kop_facility_link_list')) {
    /** profileLinks and operator websites: plain URL strings. */
    function kop_facility_link_list($value) {
        $urls = array();
        foreach (kop_facility_list($value) as $item) {
            $urls[] = is_array($item)
                ? kop_facility_str($item['url'] ?? ($item['href'] ?? ($item['link'] ?? '')))
                : $item;
        }
        return kop_facility_list($urls);
    }
}

if (!function_exists('kop_facility_resource_keys')) {
    /** Every key of the resources checklist, with its empty value. */
    function kop_facility_resource_keys() {
        return array(
            'hasNews' => false, 'newsDetails' => '', 'hasPressReleases' => false, 'pressReleasesDetails' => '',
            'hasInspections' => false, 'hasStateReports' => false, 'hasRegulatoryFilings' => false,
            'hasViolations' => false, 'hasSettlements' => false, 'hasLawsuits' => false,
            'hasPoliceReports' => false, 'hasArticlesOfOrganization' => false, 'hasPropertyRecords' => false,
            'hasPromotionalMaterials' => false, 'hasEnrollmentDocuments' => false, 'hasResearch' => false,
            'hasFinancial' => false, 'hasStudent' => false, 'studentDetails' => '', 'hasStaff' => false,
            'hasParent' => false, 'hasWebsite' => false, 'hasSocialMedia' => false, 'hasAudio' => false,
            'hasVideo' => false, 'hasNATSAP' => false, 'hasSurvivorStories' => false, 'hasOther' => false,
            'customResources' => array(), 'notes' => array(),
        );
    }
}

if (!function_exists('kop_facility_resources')) {
    /**
     * The resources checklist with every standard key present: hasX booleans,
     * xDetails strings, customResources[] and notes[]. Keys the form adds
     * later are kept and typed by the same naming rule.
     */
    function kop_facility_resources($value) {
        $in = kop_facility_map($value);
        $out = array();
        foreach (kop_facility_resource_keys() + $in as $key => $default) {
            $v = array_key_exists($key, $in) ? $in[$key] : $default;
            if (in_array($key, array('customResources', 'notes', '_legacy'), true)) {
                $out[$key] = kop_facility_list($v);
            } elseif (strpos($key, 'has') === 0) {
                $out[$key] = (bool)kop_facility_bool($v);
            } elseif (substr($key, -7) === 'Details') {
                $out[$key] = kop_facility_str($v);
            } else {
                $out[$key] = $v;
            }
        }
        return $out;
    }
}

if (!function_exists('kop_facility_gender')) {
    /**
     * Gender served: Male, Female, Co-ed or ''. The first gender word decides
     * ("boys (Provo campus; girls at ...)" is Male). A value that says more
     * than the canonical word is returned through $note so the caller keeps it.
     */
    function kop_facility_gender($raw, &$note = '') {
        $note = '';
        $s = kop_facility_str($raw);
        if ($s === '') return '';
        $plain = array(
            'male' => 'Male', 'males' => 'Male', 'boy' => 'Male', 'boys' => 'Male', 'men' => 'Male',
            'female' => 'Female', 'females' => 'Female', 'girl' => 'Female', 'girls' => 'Female', 'women' => 'Female',
            'co-ed' => 'Co-ed', 'coed' => 'Co-ed', 'co ed' => 'Co-ed', 'all' => 'Co-ed', 'both' => 'Co-ed', 'mixed' => 'Co-ed',
        );
        $key = mb_strtolower($s);
        if (isset($plain[$key])) return $plain[$key];
        $out = '';
        if (preg_match('/\b(co-?ed|co ed|all genders|both|mixed|female|females|girls?|women|male|males|boys?|men)\b/i', $s, $m)) {
            $out = $plain[mb_strtolower($m[1])] ?? (preg_match('/^co/i', $m[1]) || in_array(mb_strtolower($m[1]), array('all genders', 'both', 'mixed'), true) ? 'Co-ed' : '');
        }
        $note = 'Gender as recorded: ' . $s;
        return $out;
    }
}

if (!function_exists('kop_facility_type')) {
    /** Facility type: known synonyms to one spelling, anything else as entered. */
    function kop_facility_type($raw) {
        $s = kop_facility_str($raw);
        $map = array(
            'rtc' => 'Residential Treatment Center',
            'residential treatment center (rtc)' => 'Residential Treatment Center',
            'residential treatment facility' => 'Residential Treatment Center',
            'prtf' => 'Psychiatric Residential Treatment Facility',
            'psychiatric residential treatment facility (prtf)' => 'Psychiatric Residential Treatment Facility',
            'wilderness' => 'Wilderness Therapy',
            'wilderness therapy program' => 'Wilderness Therapy',
            'wilderness program' => 'Wilderness Therapy',
            'juvenile justice residential treatment center' => 'Juvenile Justice RTC',
            'therapeutic residential school' => 'Therapeutic Boarding School',
            'tbs' => 'Therapeutic Boarding School',
        );
        $key = mb_strtolower(preg_replace('/\s+/u', ' ', $s));
        return $map[$key] ?? $s;
    }
}

if (!function_exists('kop_facility_operator_block')) {
    /**
     * An operator block in one shape: the row in kop_operators and the copy
     * a facility keeps in provenance.sourceOperator. Unknown keys are kept.
     */
    function kop_facility_operator_block($value) {
        if (!is_array($value) || $value === array()) return null;
        $notes = $value['notes'] ?? array();
        $staff = isset($value['keyStaff']) && is_array($value['keyStaff']) ? $value['keyStaff'] : array();
        $out = array(
            'name'              => kop_facility_str($value['name'] ?? ''),
            'currentName'       => kop_facility_str($value['currentName'] ?? ''),
            'otherNames'        => kop_facility_list($value['otherNames'] ?? array()),
            'founded'           => kop_facility_str($value['founded'] ?? ''),
            'headquarters'      => kop_facility_str($value['headquarters'] ?? ''),
            'headquartersCity'  => kop_facility_str($value['headquartersCity'] ?? ''),
            'headquartersState' => kop_facility_str($value['headquartersState'] ?? ''),
            'location'          => kop_facility_str($value['location'] ?? ''),
            'locationCity'      => kop_facility_str($value['locationCity'] ?? ''),
            'locationState'     => kop_facility_str($value['locationState'] ?? ''),
            'operatingPeriod'   => kop_facility_str($value['operatingPeriod'] ?? ''),
            'status'            => kop_facility_str($value['status'] ?? ''),
            'websites'          => kop_facility_link_list($value['websites'] ?? array()),
            'parentCompanies'   => kop_facility_list($value['parentCompanies'] ?? array()),
            'owners'            => kop_facility_list($value['owners'] ?? array()),
            'investors'         => kop_facility_list($value['investors'] ?? array()),
            'keyStaff'          => array(
                'ceo'           => kop_facility_str($staff['ceo'] ?? ''),
                'founders'      => kop_facility_person_list($staff['founders'] ?? array()),
                'keyExecutives' => kop_facility_person_list($staff['keyExecutives'] ?? array()),
            ),
            'notes'             => kop_facility_list($notes),
            // A list, not a map: PHP cannot carry an empty map inside a
            // nested structure, and these are all empty.
            'fieldNotes'        => kop_facility_list($value['fieldNotes'] ?? array()),
        );
        foreach ($staff as $k => $v) {
            if (!array_key_exists($k, $out['keyStaff'])) $out['keyStaff'][$k] = $v;
        }
        foreach ($value as $k => $v) {
            if (!array_key_exists($k, $out)) $out[$k] = $v;
        }
        return $out;
    }
}

if (!function_exists('kop_facility_years_from_text')) {
    /** [start, end] from a yearsOfOperation string such as "1994-2005" or "2014-Present". */
    function kop_facility_years_from_text($text) {
        $s = kop_facility_str($text);
        if (!preg_match('/^(\d{4})(?:\s*[-\x{2013}\x{2014}]\s*(\d{4}|present|current|now)?)?$/iu', $s, $m)) return array(null, null);
        $start = (int)$m[1];
        $end = (isset($m[2]) && ctype_digit($m[2])) ? (int)$m[2] : null;
        return array($start, $end);
    }
}

if (!function_exists('kop_facility_normalize_status')) {
    /**
     * Status vocabulary. Anything outside the five allowed values becomes
     * Unknown and the original is returned through $note for the caller to
     * append to operatingPeriod.notes.
     */
    function kop_facility_normalize_status($raw, &$note = '') {
        $note = '';
        $s = trim((string)$raw);
        if ($s === '') return 'Unknown';

        $lower = strtolower($s);
        $direct = array(
            'open' => 'Open', 'closed' => 'Closed', 'suspended' => 'Suspended',
            'transferred' => 'Transferred', 'unknown' => 'Unknown',
            'operating' => 'Open', 'active' => 'Open',
            'defunct' => 'Closed', 'shut down' => 'Closed', 'shutdown' => 'Closed',
        );
        if (isset($direct[$lower])) return $direct[$lower];

        $note = 'migration: original status "' . $s . '"';
        return 'Unknown';
    }
}

if (!function_exists('kop_facility_json_encode')) {
    /**
     * Encode a v2 document for storage. The open-ended maps must serialize as
     * JSON objects even when empty, or every reader has to handle [] as well.
     */
    function kop_facility_json_encode(array $doc) {
        $object_fields = array('resources', 'treatmentTypes', 'philosophy', 'conditions', 'criticalIncidents', 'fieldNotes', 'legacy');
        foreach ($object_fields as $field) {
            if (isset($doc[$field]) && is_array($doc[$field]) && $doc[$field] === array()) {
                $doc[$field] = new stdClass();
            }
        }
        return json_encode($doc, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}

// ---------------------------------------------------------------------------
// Validator
// ---------------------------------------------------------------------------

if (!function_exists('kop_facility_validate')) {
    /**
     * Rule violations for a v2 document; empty when the document is clean.
     * Each violation is {rule, severity, path, message}. A document is valid
     * for saving when it has no 'error' violations - warnings describe data
     * that needs a human but must not block the migration.
     */
    function kop_facility_validate(array $doc) {
        $v = array();
        $add = function ($rule, $severity, $path, $message) use (&$v) {
            $v[] = array('rule' => $rule, 'severity' => $severity, 'path' => $path, 'message' => $message);
        };

        if ((int)($doc['schema_version'] ?? 0) !== KOP_FACILITY_SCHEMA_VERSION) {
            $add('schema.version', 'error', 'schema_version', 'expected ' . KOP_FACILITY_SCHEMA_VERSION);
        }

        $expected = kop_facility_top_level_keys();
        foreach (array_keys($doc) as $key) {
            if (!in_array($key, $expected, true)) {
                $add('schema.keys', 'error', $key, 'unexpected top-level key');
            }
        }
        foreach ($expected as $key) {
            if (!array_key_exists($key, $doc)) {
                $add('schema.keys', 'error', $key, 'missing section');
            }
        }

        $ident = isset($doc['identification']) && is_array($doc['identification']) ? $doc['identification'] : array();
        $name = kop_facility_str($ident['name'] ?? '');
        if ($name === '') {
            $add('identity.name', 'error', 'identification.name', 'facility has no name');
        } else {
            $expected_key = kop_facility_name_key($name);
            if (kop_facility_str($ident['nameKey'] ?? '') !== $expected_key) {
                $add('identity.nameKey', 'error', 'identification.nameKey', 'nameKey does not match name');
            }
            if ($expected_key === '') {
                $add('identity.nameKey', 'error', 'identification.nameKey', 'name normalizes to an empty key');
            }
        }

        $loc = isset($doc['location']) && is_array($doc['location']) ? $doc['location'] : array();
        $state = $loc['state'] ?? null;
        if ($state !== null && kop_facility_state_code($state) !== $state) {
            $add('location.state', 'error', 'location.state', 'not a canonical 2-letter state code: ' . json_encode($state));
        }
        $country = $loc['country'] ?? null;
        if ($country !== null) {
            $canonical = kop_facility_country_name($country);
            if ($canonical !== $country) {
                $add('location.country', 'error', 'location.country', 'not canonical: ' . json_encode($country));
            } elseif (!in_array($country, kop_facility_countries(), true)) {
                $add('location.country', 'warning', 'location.country', 'country not in the known list: ' . $country);
            }
        }
        if ($state !== null && $country !== null && $country !== 'United States') {
            $add('location.mixed', 'warning', 'location', 'US state set together with country ' . $country);
        }
        if ($state === null && $country === null) {
            $add('location.missing', 'warning', 'location', 'no state and no country - lands in the Unknown location group');
        }

        $op = isset($doc['operatingPeriod']) && is_array($doc['operatingPeriod']) ? $doc['operatingPeriod'] : array();
        if (!in_array($op['status'] ?? '', kop_facility_status_vocabulary(), true)) {
            $add('status.vocabulary', 'error', 'operatingPeriod.status', 'not in the vocabulary: ' . json_encode($op['status'] ?? null));
        }

        $ints = array(
            'operatingPeriod.startYear'      => $op['startYear'] ?? null,
            'operatingPeriod.endYear'        => $op['endYear'] ?? null,
            'facilityDetails.capacity'       => $doc['facilityDetails']['capacity'] ?? null,
            'facilityDetails.currentCensus'  => $doc['facilityDetails']['currentCensus'] ?? null,
            'facilityDetails.ageRange.min'   => $doc['facilityDetails']['ageRange']['min'] ?? null,
            'facilityDetails.ageRange.max'   => $doc['facilityDetails']['ageRange']['max'] ?? null,
        );
        foreach ($ints as $path => $value) {
            if ($value !== null && !is_int($value)) {
                $add('types.int', 'error', $path, 'expected integer or null, got ' . gettype($value));
            }
        }
        $start = $op['startYear'] ?? null;
        $end = $op['endYear'] ?? null;
        if (is_int($start) && is_int($end) && $end < $start) {
            $add('years.order', 'warning', 'operatingPeriod', 'endYear ' . $end . ' precedes startYear ' . $start);
        }

        $lists = array(
            'identification.otherNames'   => $ident['otherNames'] ?? null,
            'identification.pastNames'    => $ident['pastNames'] ?? null,
            'identification.currentOwners' => $ident['currentOwners'] ?? null,
            'operatingPeriod.notes'       => $op['notes'] ?? null,
            'memberships'                 => $doc['memberships'] ?? null,
            'certifications'              => $doc['certifications'] ?? null,
            'licensing'                   => $doc['licensing'] ?? null,
            'profileLinks'                => $doc['profileLinks'] ?? null,
            'notes'                       => $doc['notes'] ?? null,
            'location.additionalLocations' => $loc['additionalLocations'] ?? null,
            'location.formerLocations'    => $loc['formerLocations'] ?? null,
            'staff.administrator'         => $doc['staff']['administrator'] ?? null,
            'staff.notableStaff'          => $doc['staff']['notableStaff'] ?? null,
            'staff.pastTTIJobs'           => $doc['staff']['pastTTIJobs'] ?? null,
            'accreditations.current'      => $doc['accreditations']['current'] ?? null,
            'accreditations.past'         => $doc['accreditations']['past'] ?? null,
        );
        foreach ($lists as $path => $value) {
            if (!is_array($value)) {
                $add('types.list', 'error', $path, 'expected an array, got ' . gettype($value));
                continue;
            }
            if ($value !== array() && array_keys($value) !== range(0, count($value) - 1)) {
                $add('types.list', 'error', $path, 'expected a list, got a map');
            }
        }

        $maps = array('resources', 'treatmentTypes', 'philosophy', 'conditions', 'criticalIncidents', 'fieldNotes', 'legacy');
        foreach ($maps as $path) {
            $value = $doc[$path] ?? null;
            if (!is_array($value)) {
                $add('types.map', 'error', $path, 'expected a map, got ' . gettype($value));
                continue;
            }
            if ($value !== array() && array_keys($value) === range(0, count($value) - 1)) {
                $add('types.map', 'error', $path, 'expected a map, got a list');
            }
        }

        return $v;
    }
}

if (!function_exists('kop_facility_is_valid')) {
    /** True when kop_facility_validate() found no error-severity violations. */
    function kop_facility_is_valid(array $doc) {
        foreach (kop_facility_validate($doc) as $violation) {
            if ($violation['severity'] === 'error') return false;
        }
        return true;
    }
}

// ---------------------------------------------------------------------------
// Membership derivation
// ---------------------------------------------------------------------------

if (!function_exists('kop_facility_location_key')) {
    /**
     * The locations_master-style key for a state code or country name:
     * 'UT' => 'UTAH', 'Mexico' => 'MEXICO'.
     */
    function kop_facility_location_key($state, $country = null) {
        $code = kop_facility_state_code($state);
        if ($code !== null) {
            $states = kop_facility_states();
            return $states[$code];
        }
        $name = kop_facility_country_name($country !== null ? $country : $state);
        if ($name !== null && $name !== '' && $name !== 'United States') {
            return mb_strtoupper($name);
        }
        return null;
    }
}

if (!function_exists('kop_facility_derive_memberships')) {
    /**
     * Location memberships implied by one document (rule R3, plus former and
     * additional locations). The migration adds legacy-array memberships (R4)
     * on top of these; they are not derivable from the document alone.
     *
     * @return array list of {location_key, role, source, needs_review, review_reason}
     */
    function kop_facility_derive_memberships(array $doc) {
        $rows = array();
        $seen = array();
        $push = function ($key, $role, $source, $needs_review = 0, $reason = '') use (&$rows, &$seen) {
            if ($key === null || $key === '') return;
            $dedup = $key . '|' . $role;
            if (isset($seen[$dedup])) return;
            $seen[$dedup] = true;
            $rows[] = array(
                'location_key'  => $key,
                'role'          => $role,
                'source'        => $source,
                'needs_review'  => $needs_review,
                'review_reason' => $reason,
            );
        };

        $loc = isset($doc['location']) && is_array($doc['location']) ? $doc['location'] : array();
        $primary = kop_facility_location_key($loc['state'] ?? null, $loc['country'] ?? null);
        if ($primary !== null) {
            $source = ($loc['raw'] ?? '') !== '' ? 'address' : 'location_details';
            $push($primary, 'current', $source);
        }

        foreach ((array)($loc['additionalLocations'] ?? array()) as $alt) {
            if (!is_array($alt)) continue;
            $key = kop_facility_location_key($alt['state'] ?? null, $alt['country'] ?? null);
            if ($key !== null && $key !== $primary) {
                $push($key, 'additional', 'additional_location');
            }
        }

        foreach ((array)($loc['formerLocations'] ?? array()) as $fl) {
            if (!is_array($fl)) continue;
            $key = kop_facility_location_key($fl['state'] ?? null, $fl['country'] ?? null);
            if ($key !== null && $key !== $primary) {
                $push($key, 'former', 'former_location');
            }
        }

        if (!$rows) {
            $push('UNKNOWN', 'unknown', 'manual', 1, 'no state, country or location text on the record');
        }

        return $rows;
    }
}

// ---------------------------------------------------------------------------
// Legacy projection (dual write during phases 3 and 4)
// ---------------------------------------------------------------------------

if (!function_exists('kop_facility_to_legacy')) {
    /**
     * Project a v2 document back to the legacy nested facility shape so the
     * pre-cutover readers keep working while both models are live. Dropped in
     * phase 5 together with the dual write.
     */
    function kop_facility_to_legacy(array $doc) {
        $loc = $doc['location'];
        $ident = $doc['identification'];
        $states = kop_facility_states();
        $state_name = ($loc['state'] !== null && isset($states[$loc['state']])) ? $loc['state'] : '';

        $additional = array();
        foreach ($loc['additionalLocations'] as $alt) {
            $additional[] = array(
                'address' => $alt['raw'],
                'city'    => $alt['city'],
                'state'   => (string)$alt['state'],
                'zip'     => $alt['zip'],
                'country' => (string)($alt['country'] ?? ''),
            );
        }
        $former = array();
        foreach ($loc['formerLocations'] as $fl) {
            $former[] = array(
                'state'    => (string)$fl['state'],
                'city'     => $fl['city'],
                'address'  => $fl['raw'],
                'zip'      => '',
                'fromYear' => $fl['fromYear'] === null ? '' : (string)$fl['fromYear'],
                'toYear'   => $fl['toYear'] === null ? '' : (string)$fl['toYear'],
            );
        }

        $legacy = array(
            'identification' => array(
                'name'            => $ident['name'],
                'currentName'     => $ident['currentName'],
                'otherNames'      => $ident['otherNames'],
                'pastNames'       => $ident['pastNames'],
                'currentOperator' => $ident['currentOperator'],
                'currentOwner'    => $ident['currentOwners'] ? $ident['currentOwners'][0] : '',
                'currentOwners'   => $ident['currentOwners'],
                'knownReferrers'  => $ident['knownReferrers'],
            ),
            'otherOperators' => $ident['otherOperators'],
            'pastOperators'  => $ident['pastOperators'],
            'investors'      => $ident['investors'],
            'address'        => $loc['raw'],
            'addressParts'   => array(
                'street' => $loc['street'],
                'city'   => $loc['city'],
                'state'  => $state_name,
                'zip'    => $loc['zip'],
            ),
            'location'       => $loc['text'],
            'locationDetails' => array(
                'city'    => $loc['city'],
                'state'   => $state_name,
                'country' => (string)($loc['country'] ?? ''),
                'zip'     => $loc['zip'],
                'additionalLocations' => $additional,
                'formerLocations'     => $former,
            ),
            'operatingPeriod' => array(
                'startYear'        => $doc['operatingPeriod']['startYear'],
                'endYear'          => $doc['operatingPeriod']['endYear'],
                'status'           => $doc['operatingPeriod']['status'],
                'yearsOfOperation' => $doc['operatingPeriod']['yearsOfOperation'],
                'notes'            => $doc['operatingPeriod']['notes'],
            ),
            'facilityDetails' => array(
                'type'          => $doc['facilityDetails']['type'],
                'capacity'      => $doc['facilityDetails']['capacity'],
                'currentCensus' => $doc['facilityDetails']['currentCensus'],
                'ageRange'      => $doc['facilityDetails']['ageRange'],
                'gender'        => $doc['facilityDetails']['gender'],
            ),
            'staff'          => $doc['staff'],
            'accreditations' => $doc['accreditations'],
            'memberships'    => $doc['memberships'],
            'certifications' => $doc['certifications'],
            'licensing'      => $doc['licensing'],
            'profileLinks'   => $doc['profileLinks'],
            'resources'      => $doc['resources'],
            'treatmentTypes' => $doc['treatmentTypes'],
            'philosophy'     => $doc['philosophy'],
            'conditions'     => $doc['conditions'],
            'criticalIncidents' => $doc['criticalIncidents'],
            'notes'          => $doc['notes'],
            'fieldNotes'     => $doc['fieldNotes'],
        );

        if ($doc['facility_id'] !== null) $legacy['facility_id'] = $doc['facility_id'];
        if ($doc['documentFolderId'] !== null) $legacy['documentFolderId'] = $doc['documentFolderId'];
        if ($doc['facilityDetails']['isPrivatelyOwned'] !== null) $legacy['isPrivatelyOwned'] = $doc['facilityDetails']['isPrivatelyOwned'];
        if ($doc['provenance']['sourceProject'] !== '') $legacy['sourceProject'] = $doc['provenance']['sourceProject'];
        if ($doc['provenance']['sourceCategory'] !== '') $legacy['sourceCategory'] = $doc['provenance']['sourceCategory'];
        if ($doc['provenance']['sourceOperator'] !== null) $legacy['sourceOperator'] = $doc['provenance']['sourceOperator'];
        foreach ($doc['legacy'] as $k => $v) {
            if (!array_key_exists($k, $legacy)) $legacy[$k] = $v;
        }

        return $legacy;
    }
}

// ---------------------------------------------------------------------------
// Database layer
// ---------------------------------------------------------------------------
// Everything below needs a live connection. Callers pass either nothing (the
// global $wpdb is used) or a PDO handle in $opts['pdo'], so the same code
// serves the WordPress side (inc/admin.php) and the procedural endpoints under
// api/ that only have PDO.

if (!function_exists('kop_facility_table')) {
    /** Table names, honoring the prefix rule: kop_* prefixed, masters not. */
    function kop_facility_table($base, array $opts = array()) {
        $prefix = '';
        if (isset($opts['prefix'])) {
            $prefix = (string)$opts['prefix'];
        } elseif (isset($GLOBALS['wpdb']) && is_object($GLOBALS['wpdb'])) {
            $prefix = $GLOBALS['wpdb']->prefix;
        } elseif (isset($GLOBALS['table_prefix']) && is_string($GLOBALS['table_prefix'])) {
            $prefix = $GLOBALS['table_prefix'];
        }

        switch ($base) {
            case 'facilities':
                // The v2 documents. Phase 5 renames the table to facilities_master.
                return 'facilities_v2';
            case 'identity':
                return $prefix . 'kop_facility_identity';
            case 'locations':
                return 'locations_master';
            case 'facility_locations':
                return $prefix . 'kop_facility_locations';
            case 'operators':
                return $prefix . 'kop_operators';
            case 'operator_facilities':
                return $prefix . 'kop_operator_facilities';
            case 'facility_addresses':
                return $prefix . 'kop_facility_addresses';
            case 'addresses':
                return $prefix . 'kop_addresses';
        }
        return $prefix . $base;
    }
}

if (!function_exists('kop_facility_db_rows')) {
    /** SELECT returning rows as associative arrays, via wpdb or PDO. */
    function kop_facility_db_rows($sql, array $params = array(), array $opts = array()) {
        if (!empty($opts['pdo']) && $opts['pdo'] instanceof PDO) {
            $stmt = $opts['pdo']->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        global $wpdb;
        if (!isset($wpdb)) {
            throw new RuntimeException('kop_facility_db_rows: no database handle');
        }
        $prepared = $params ? $wpdb->prepare(str_replace('?', '%s', $sql), $params) : $sql;
        $rows = $wpdb->get_results($prepared, ARRAY_A);
        return is_array($rows) ? $rows : array();
    }
}

if (!function_exists('kop_facility_db_exec')) {
    /** INSERT/UPDATE/DELETE. Returns the affected row count. */
    function kop_facility_db_exec($sql, array $params = array(), array $opts = array()) {
        if (!empty($opts['pdo']) && $opts['pdo'] instanceof PDO) {
            $stmt = $opts['pdo']->prepare($sql);
            $stmt->execute($params);
            return $stmt->rowCount();
        }
        global $wpdb;
        if (!isset($wpdb)) {
            throw new RuntimeException('kop_facility_db_exec: no database handle');
        }
        $prepared = $params ? $wpdb->prepare(str_replace('?', '%s', $sql), $params) : $sql;
        return $wpdb->query($prepared);
    }
}

if (!function_exists('kop_facility_db_insert_id')) {
    function kop_facility_db_insert_id(array $opts = array()) {
        if (!empty($opts['pdo']) && $opts['pdo'] instanceof PDO) {
            return (int)$opts['pdo']->lastInsertId();
        }
        global $wpdb;
        return (int)$wpdb->insert_id;
    }
}

if (!function_exists('kop_facility_has_generated_columns')) {
    /**
     * True when the facilities table has the generated columns (facilities_v2
     * always does). Cached per request; identity lookups fall back to JSON
     * paths otherwise.
     */
    function kop_facility_has_generated_columns(array $opts = array()) {
        static $cache = null;
        if ($cache !== null && empty($opts['refresh'])) return $cache;
        try {
            $rows = kop_facility_db_rows(
                "SHOW COLUMNS FROM " . kop_facility_table('facilities', $opts) . " LIKE 'name_key'",
                array(),
                $opts
            );
            $cache = !empty($rows);
        } catch (Throwable $e) {
            $cache = false;
        }
        return $cache;
    }
}

if (!function_exists('kop_facility_resolve_identity')) {
    /**
     * The ONLY way to look up an existing facility.
     *
     * Matches on (name_key, state, city). Falls back to (name_key, state) when
     * the city is empty on both sides, and to name_key alone ONLY when neither
     * side has a state - never name alone for a record that knows where it is.
     * This replaces the name-only lookup in kop_promote_single_nested_facility()
     * that gave different facilities one id (root cause 1).
     *
     * @return int|null facilities_master.id
     */
    function kop_facility_resolve_identity($name, $state = null, $city = null, array $opts = array()) {
        $name_key = kop_facility_name_key($name);
        if ($name_key === '') return null;

        $state_code = kop_facility_state_code($state);
        $city_key = kop_facility_city_key($city);
        $table = kop_facility_table('facilities', $opts);

        if (kop_facility_has_generated_columns($opts)) {
            $rows = kop_facility_db_rows(
                "SELECT id, state, city FROM {$table} WHERE name_key = ?",
                array($name_key),
                $opts
            );
        } else {
            $rows = kop_facility_db_rows(
                "SELECT id,
                        JSON_UNQUOTE(JSON_EXTRACT(json_data, '$.location.state')) AS state,
                        JSON_UNQUOTE(JSON_EXTRACT(json_data, '$.location.city'))  AS city
                   FROM {$table}
                  WHERE JSON_UNQUOTE(JSON_EXTRACT(json_data, '$.identification.nameKey')) = ?",
                array($name_key),
                $opts
            );
        }
        if (!$rows) return null;

        $with_state = array();
        foreach ($rows as $row) {
            $row_state = kop_facility_state_code($row['state'] ?? null);
            if ($state_code !== null && $row_state === $state_code) {
                $with_state[] = $row;
            } elseif ($state_code === null && $row_state === null) {
                $with_state[] = $row;
            }
        }
        if (!$with_state) return null;

        if ($city_key !== '') {
            foreach ($with_state as $row) {
                if (kop_facility_city_key($row['city'] ?? '') === $city_key) {
                    return (int)$row['id'];
                }
            }
            // A stored row with no city is the same facility as one that
            // learned its city later.
            foreach ($with_state as $row) {
                if (kop_facility_city_key($row['city'] ?? '') === '') {
                    return (int)$row['id'];
                }
            }
            return null;
        }

        return count($with_state) === 1 ? (int)$with_state[0]['id'] : null;
    }
}

if (!function_exists('kop_facility_canonical')) {
    /**
     * Order-independent comparison form of a document. MySQL's JSON column
     * reorders object keys and reads {} back as [], so a stored document never
     * string-matches the one that was written.
     */
    function kop_facility_canonical($value) {
        if (!is_array($value)) return $value;
        if ($value === array()) return array();
        $is_list = array_keys($value) === range(0, count($value) - 1);
        if (!$is_list) ksort($value, SORT_STRING);
        foreach ($value as $k => $v) $value[$k] = kop_facility_canonical($v);
        return $value;
    }
}

if (!function_exists('kop_facility_same_document')) {
    function kop_facility_same_document($a, $b) {
        return is_array($a) && is_array($b)
            && json_encode(kop_facility_canonical($a)) === json_encode(kop_facility_canonical($b));
    }
}

if (!function_exists('kop_facility_load')) {
    /**
     * A stored v2 document by id, or null.
     *
     * @return array|null {id, unique_name, doc}
     */
    function kop_facility_load($facility_id, array $opts = array()) {
        $rows = kop_facility_db_rows(
            "SELECT id, unique_name, json_data FROM " . kop_facility_table('facilities', $opts) . " WHERE id = ?",
            array((int)$facility_id),
            $opts
        );
        if (!$rows) return null;
        $doc = json_decode((string)$rows[0]['json_data'], true);
        if (!is_array($doc)) return null;
        return array('id' => (int)$rows[0]['id'], 'unique_name' => (string)$rows[0]['unique_name'], 'doc' => $doc);
    }
}

if (!function_exists('kop_facility_allocate_id')) {
    /**
     * Next free facility id. New facilities are numbered from 100000 up (the
     * range the migration uses for split-off facilities), above every legacy
     * id, and each allocation is recorded in kop_facility_identity so an id is
     * never handed out twice.
     */
    function kop_facility_allocate_id($unique_name, array $opts = array()) {
        $facilities = kop_facility_table('facilities', $opts);
        $identity = kop_facility_table('identity', $opts);
        $max = 99999;
        foreach (array("SELECT MAX(id) AS m FROM {$facilities}", "SELECT MAX(facility_id) AS m FROM {$identity}") as $sql) {
            $rows = kop_facility_db_rows($sql, array(), $opts);
            if ($rows && $rows[0]['m'] !== null) $max = max($max, (int)$rows[0]['m']);
        }
        $id = $max + 1;
        kop_facility_db_exec(
            "INSERT INTO {$identity} (identity_key, facility_id, unique_name) VALUES (?, ?, ?)",
            array('save:' . $id, $id, $unique_name),
            $opts
        );
        return $id;
    }
}

if (!function_exists('kop_facility_allocate_unique_name')) {
    /**
     * A unique_name nobody has: "Name", then "Name (ST)", "Name (City, ST)",
     * "Name #2" and up.
     */
    function kop_facility_allocate_unique_name(array $doc, array $opts = array()) {
        $table = kop_facility_table('facilities', $opts);
        $name = mb_substr($doc['identification']['name'], 0, 230);
        $place = $doc['location']['state'] !== null ? $doc['location']['state'] : (string)$doc['location']['country'];
        $candidates = array($name);
        if ($place !== '') {
            $candidates[] = $name . ' (' . $place . ')';
            if ($doc['location']['city'] !== '') $candidates[] = $name . ' (' . $doc['location']['city'] . ', ' . $place . ')';
        }
        for ($n = 2; $n < 100; $n++) $candidates[] = $name . ' #' . $n;
        foreach ($candidates as $candidate) {
            $taken = kop_facility_db_rows("SELECT id FROM {$table} WHERE unique_name = ? LIMIT 1", array($candidate), $opts);
            if (!$taken) return $candidate;
        }
        return $name . ' #' . uniqid();
    }
}

if (!function_exists('kop_facility_save')) {
    /**
     * Validate and write one facility, then rebuild its derived rows.
     *
     * The document's facility_id picks the row. Without one, the identity
     * resolver looks for the same facility (name + place) before a new row is
     * created. An unchanged document is not rewritten.
     *
     * @param array $doc    a v2 document (run kop_facility_normalize first).
     * @param array $opts   pdo, prefix, unique_name (new rows only), force
     *                      (write despite validation errors), skip_memberships.
     * @param string|null $status set to 'created', 'updated' or 'unchanged'.
     * @return int facility id
     * @throws RuntimeException when the document has error-severity violations.
     */
    function kop_facility_save(array $doc, array $opts = array(), &$status = null) {
        $violations = kop_facility_validate($doc);
        $errors = array_values(array_filter($violations, function ($v) {
            return $v['severity'] === 'error';
        }));
        if ($errors && empty($opts['force'])) {
            $first = $errors[0];
            throw new RuntimeException(
                'kop_facility_save: ' . count($errors) . ' validation error(s), first: '
                . $first['path'] . ' - ' . $first['message']
            );
        }

        $table = kop_facility_table('facilities', $opts);
        $id = $doc['facility_id'] !== null ? (int)$doc['facility_id'] : 0;
        $existing = $id > 0 ? kop_facility_load($id, $opts) : null;

        if ($existing === null) {
            $resolved = (int)kop_facility_resolve_identity(
                $doc['identification']['name'],
                $doc['location']['state'],
                $doc['location']['city'],
                $opts
            );
            if ($resolved > 0) {
                $existing = kop_facility_load($resolved, $opts);
            }
        }

        if ($existing !== null) {
            $id = $existing['id'];
            $doc['facility_id'] = $id;
            $doc['identification']['nameKey'] = kop_facility_name_key($doc['identification']['name']);
            if (kop_facility_same_document($existing['doc'], $doc)) {
                $status = 'unchanged';
                return $id;
            }
            kop_facility_db_exec(
                "UPDATE {$table} SET json_data = ? WHERE id = ?",
                array(kop_facility_json_encode($doc), $id),
                $opts
            );
            $status = 'updated';
            if (empty($opts['skip_memberships'])
                && !kop_facility_same_document($existing['doc']['location'] ?? null, $doc['location'])) {
                kop_facility_rebuild_memberships($id, $doc, $opts);
            }
            return $id;
        }

        $unique_name = !empty($opts['unique_name']) ? (string)$opts['unique_name'] : kop_facility_allocate_unique_name($doc, $opts);
        $id = kop_facility_allocate_id($unique_name, $opts);
        $doc['facility_id'] = $id;
        $doc['identification']['nameKey'] = kop_facility_name_key($doc['identification']['name']);
        $doc['provenance']['uniqueName'] = $unique_name;
        kop_facility_db_exec(
            "INSERT INTO {$table} (id, unique_name, json_data) VALUES (?, ?, ?)",
            array($id, $unique_name, kop_facility_json_encode($doc)),
            $opts
        );
        $status = 'created';
        if (empty($opts['skip_memberships'])) {
            kop_facility_rebuild_memberships($id, $doc, $opts);
        }
        return $id;
    }
}

if (!function_exists('kop_facility_rebuild_memberships')) {
    /**
     * Replace this facility's rows in kop_facility_locations with the ones its
     * document implies. Called when the location block changes.
     *
     * Kept regardless: rows the migration added from legacy evidence
     * (source = legacy_membership) and hand-placed rows (manual), because they
     * record a page the facility appears on that the document alone cannot
     * justify. The Unknown row goes as soon as the facility has a real place.
     * A place the free-text location names becomes an additional membership,
     * as in the migration.
     */
    function kop_facility_rebuild_memberships($facility_id, array $doc, array $opts = array()) {
        $facility_id = (int)$facility_id;
        if ($facility_id <= 0) return 0;
        $table = kop_facility_table('facility_locations', $opts);

        $rows = array();
        foreach (kop_facility_derive_memberships($doc) as $m) {
            $rows[$m['location_key'] . '|' . $m['role']] = $m;
        }
        $places = array();
        foreach ($rows as $m) $places[$m['location_key']] = true;
        foreach (kop_facility_location_text_places($doc['location']['text'] ?? '') as $place) {
            $key = kop_facility_location_key($place['state'], $place['country']);
            if ($key === null || isset($places[$key])) continue;
            $rows[$key . '|additional'] = array(
                'location_key' => $key, 'role' => 'additional', 'source' => 'additional_location',
                'needs_review' => 0, 'review_reason' => '',
            );
            $places[$key] = true;
        }
        $has_place = false;
        foreach ($rows as $m) {
            if ($m['location_key'] !== 'UNKNOWN') $has_place = true;
        }
        if ($has_place) unset($rows['UNKNOWN|unknown']);

        kop_facility_db_exec(
            "DELETE FROM {$table} WHERE facility_id = ? AND source NOT IN ('legacy_membership', 'manual')",
            array($facility_id),
            $opts
        );
        if ($has_place) {
            kop_facility_db_exec(
                "DELETE FROM {$table} WHERE facility_id = ? AND location_key = 'UNKNOWN'",
                array($facility_id),
                $opts
            );
        } else {
            $kept = kop_facility_db_rows("SELECT location_key FROM {$table} WHERE facility_id = ?", array($facility_id), $opts);
            if ($kept) unset($rows['UNKNOWN|unknown']);   // a kept page is a place
        }

        $written = 0;
        foreach ($rows as $row) {
            kop_facility_db_exec(
                "INSERT INTO {$table} (facility_id, location_key, role, source, needs_review, review_reason)
                 VALUES (?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE source = IF(source IN ('legacy_membership', 'manual'), source, VALUES(source)),
                                         needs_review = IF(source IN ('legacy_membership', 'manual'), needs_review, VALUES(needs_review)),
                                         review_reason = IF(source IN ('legacy_membership', 'manual'), review_reason, VALUES(review_reason))",
                array(
                    $facility_id,
                    $row['location_key'],
                    $row['role'],
                    $row['source'],
                    (int)$row['needs_review'],
                    $row['review_reason'] !== '' ? $row['review_reason'] : null,
                ),
                $opts
            );
            $written++;
        }
        return $written;
    }
}

if (!function_exists('kop_facility_rebuild_operator_links')) {
    /**
     * Replace this facility's rows in kop_operator_facilities.
     *
     * @param array $links list of {operator_id, relationship, sort_order}
     */
    function kop_facility_rebuild_operator_links($facility_id, array $links, array $opts = array()) {
        $facility_id = (int)$facility_id;
        if ($facility_id <= 0) return 0;
        $table = kop_facility_table('operator_facilities', $opts);

        kop_facility_db_exec("DELETE FROM {$table} WHERE facility_id = ?", array($facility_id), $opts);

        $written = 0;
        foreach ($links as $link) {
            $operator_id = (int)($link['operator_id'] ?? 0);
            if ($operator_id <= 0) continue;
            $relationship = in_array($link['relationship'] ?? '', array('current', 'past', 'other'), true)
                ? $link['relationship']
                : 'current';
            kop_facility_db_exec(
                "INSERT INTO {$table} (operator_id, facility_id, relationship, sort_order)
                 VALUES (?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE relationship = VALUES(relationship), sort_order = VALUES(sort_order)",
                array($operator_id, $facility_id, $relationship, (int)($link['sort_order'] ?? 0)),
                $opts
            );
            $written++;
        }
        return $written;
    }
}
