<?php
/**
 * Country page aggregation: slug helpers, facility/news/lawsuits/legislation
 * collectors, and the kop/v1/country/{slug} REST route. Mirrors the state-page
 * pipeline in rest-api.php but matches by country fields instead of US-state
 * fields, and skips the inspection-report integration (no foreign analog).
 *
 * The country list is intentionally unbounded: any slug is accepted, humanized
 * to a canonical name (dominican-republic → "Dominican Republic"), and used to
 * match against locations_master / facilities_master / news_submissions /
 * lawsuits / legislation. Page authors create a /country-name/ page and the
 * slug drives everything.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Convert "Mexico" -> "mexico", "Dominican Republic" -> "dominican-republic".
 */
function kop_country_slug($country_name) {
    $slug = strtolower(trim((string)$country_name));
    $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
    return trim($slug, '-');
}

/**
 * Humanize a slug into a canonical country name. We trust the page author —
 * no validation list. "dominican-republic" -> "Dominican Republic".
 *
 * @return string|null Humanized name, or null for empty/invalid input.
 */
function kop_country_slug_to_name($slug) {
    if (!is_string($slug) || $slug === '') return null;
    $slug = strtolower(trim($slug));
    if ($slug === '') return null;

    $parts = explode('-', $slug);
    $small = array('and', 'of', 'the', 'de', 'la', 'le', 'du', 'des', 'el');
    $name_parts = array();
    foreach ($parts as $i => $part) {
        if ($part === '') continue;
        if ($i > 0 && in_array($part, $small, true)) {
            $name_parts[] = $part;
        } else {
            $name_parts[] = mb_convert_case($part, MB_CASE_TITLE, 'UTF-8');
        }
    }
    $name = trim(implode(' ', $name_parts));
    return $name !== '' ? $name : null;
}

/**
 * Build the programs list for a country. Mirrors kop_state_collect_programs
 * but matches against locationDetails.country / address.country / free-text
 * location, instead of the state fields.
 *
 * Sources, in priority order:
 *   1. locations_master row keyed by the uppercase country name.
 *   2. facilities_master — operator/company projects whose facilities live
 *      in this country.
 *
 * Dedup by normalized facility name; locations_master wins.
 */
function kop_country_collect_programs($country_name) {
    global $wpdb;

    $country_lower = strtolower($country_name);

    // Same record shape and same-name merge as the state directory, so the
    // country tiles expose every field the state tiles do. The country name
    // is passed as the default country; no state is being viewed.
    $append_program = static function (&$programs, &$seen_names, $project_name, $facility, $data, $country_name, $meta = array()) {
        $meta['default_country'] = $country_name;
        $built = kop_state_build_program_record($project_name, $facility, $data, '', $meta);
        if ($built === null) return;
        $program   = $built['program'];
        $dedup_key = $built['dedup_key'];

        if ($dedup_key !== '' && isset($seen_names[$dedup_key])) {
            $existing = &$programs[$seen_names[$dedup_key]];
            $addr_keys = array('street', 'city', 'state', 'zip', 'raw_address');
            $existing_has_street = ($existing['street'] !== '' || ($existing['city'] === '' && $existing['raw_address'] !== ''));
            $incoming_has_street = ($program['street'] !== '' || ($program['city'] === '' && $program['raw_address'] !== ''));
            if (!$existing_has_street && $incoming_has_street) {
                foreach ($addr_keys as $ak) $existing[$ak] = $program[$ak];
            } elseif ($existing_has_street && $incoming_has_street) {
                $fmt = static function ($p) {
                    return $p['street'] !== ''
                        ? implode(', ', array_filter(array($p['street'], $p['city'], $p['state'], $p['zip'])))
                        : $p['raw_address'];
                };
                $incoming_line = $fmt($program);
                if (kop_normalize_address_for_match($incoming_line) !== kop_normalize_address_for_match($fmt($existing))) {
                    $known = array_map('strtolower', $existing['additional_addresses']);
                    if (!in_array(strtolower($incoming_line), $known, true)) {
                        $existing['additional_addresses'][] = $incoming_line;
                    }
                }
            }
            kop_state_merge_program_fields($existing, $program);
            unset($existing);
            return;
        }
        if ($dedup_key !== '') {
            $seen_names[$dedup_key] = count($programs);
        }
        $programs[] = $program;
    };

    $programs = array();
    $seen_names = array();

    // Source 1: locations_master.<COUNTRY> — canonical country aggregate.
    $loc_table = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", 'locations_master'));
    if ($loc_table === 'locations_master') {
        $loc_row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT unique_name, json_data, updated_at FROM locations_master WHERE UPPER(unique_name) = %s LIMIT 1",
                strtoupper($country_name)
            ),
            ARRAY_A
        );
        if ($loc_row && !empty($loc_row['json_data'])) {
            $loc_data = kop_normalize_project_payload($loc_row['json_data']);
            if (is_array($loc_data) && !empty($loc_data['facilities']) && is_array($loc_data['facilities'])) {
                $loc_meta = array('updated_at' => (string)($loc_row['updated_at'] ?? ''));
                foreach ($loc_data['facilities'] as $facility) {
                    $append_program($programs, $seen_names, $loc_row['unique_name'], $facility, $loc_data, $country_name, $loc_meta);
                }
            }
        }
    }

    // Source 2: facilities_master — operator projects with facilities in this country.
    $rows = $wpdb->get_results("SELECT id, unique_name, json_data, updated_at FROM facilities_master", ARRAY_A);
    if (is_array($rows)) {
        foreach ($rows as $row) {
            $data = kop_normalize_project_payload($row['json_data']);
            if (!is_array($data) || empty($data['facilities']) || !is_array($data['facilities'])) {
                continue;
            }
            $row_meta = array(
                'master_id'      => (int)($row['id'] ?? 0),
                'facility_count' => count($data['facilities']),
                'updated_at'     => (string)($row['updated_at'] ?? ''),
            );

            foreach ($data['facilities'] as $facility) {
                if (!is_array($facility)) continue;

                $address = isset($facility['address']) && is_array($facility['address']) ? $facility['address'] : array();
                $address_parts = isset($facility['addressParts']) && is_array($facility['addressParts']) ? $facility['addressParts'] : array();
                $location_details = isset($facility['locationDetails']) && is_array($facility['locationDetails']) ? $facility['locationDetails'] : array();

                $addr_country_lower = strtolower(trim((string)($address['country'] ?? '')));
                $parts_country_lower = strtolower(trim((string)($address_parts['country'] ?? '')));
                $locdet_country_lower = strtolower(trim((string)($location_details['country'] ?? '')));
                $location_string = strtolower((string)($facility['location'] ?? ''));

                $matched = false;
                if ($country_lower !== '' && in_array($country_lower, array($addr_country_lower, $parts_country_lower, $locdet_country_lower), true)) {
                    $matched = true;
                } elseif ($country_lower !== '' && strpos($location_string, $country_lower) !== false) {
                    $matched = true;
                }
                if (!$matched) continue;

                $append_program($programs, $seen_names, $row['unique_name'], $facility, $data, $country_name, $row_meta);
            }
        }
    }

    usort($programs, static function ($a, $b) {
        return strnatcasecmp($a['facility_name'] ?: $a['project_name'], $b['facility_name'] ?: $b['project_name']);
    });

    return $programs;
}

/**
 * Bundle programs into the active/closed/total shape the state route returns
 * via the shared merge (no foreign inspection data exists yet, so the
 * inspection list is empty). Linked news, lawsuits and memorial entries are
 * attached by the shared step.
 */
function kop_country_collect_facilities($country_name) {
    $programs = kop_country_collect_programs($country_name);
    return kop_state_merge_programs_and_inspections($programs, array(), $country_name, array('include_country' => true));
}

/**
 * Pull news_submissions whose article_location or tags reference this country.
 * Mirrors kop_state_collect_news.
 */
function kop_country_collect_news($country_name) {
    global $wpdb;

    $like_loc = '%' . $wpdb->esc_like($country_name) . '%';
    $like_tag = '%"' . $wpdb->esc_like($country_name) . '"%';

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

function kop_country_collect_lawsuits($country_name) {
    global $wpdb;
    $table = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", 'lawsuits'));
    if ($table !== 'lawsuits') return array();

    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM lawsuits WHERE jurisdiction = %s AND publication_status = 'published'
         ORDER BY filing_date DESC, created_at DESC LIMIT 200",
        $country_name
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

function kop_country_collect_legislation($country_name) {
    global $wpdb;
    $table = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", 'legislation'));
    if ($table !== 'legislation') return array();

    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM legislation WHERE jurisdiction = %s AND publication_status = 'published'
         ORDER BY introduced_date DESC, created_at DESC LIMIT 200",
        $country_name
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
 * Register the country aggregation REST route.
 */
function kop_register_country_rest_routes() {
    register_rest_route(
        'kop/v1',
        '/country/(?P<slug>[a-z0-9-]+)',
        array(
            'methods' => WP_REST_Server::READABLE,
            'permission_callback' => '__return_true',
            'callback' => function ($request) {
                $slug = strtolower($request['slug']);
                $country_name = kop_country_slug_to_name($slug);
                if (!$country_name) {
                    return new WP_Error('unknown_country', 'Missing country slug', array('status' => 404));
                }

                $facilities = kop_country_collect_facilities($country_name);
                $news = kop_country_collect_news($country_name);
                $lawsuits = kop_country_collect_lawsuits($country_name);
                $legislation = kop_country_collect_legislation($country_name);

                return rest_ensure_response(array(
                    'country' => array(
                        'name' => $country_name,
                        'slug' => kop_country_slug($country_name),
                    ),
                    // Keep the same shape as the state route so country-page.js
                    // can share rendering code paths with state-page.js.
                    'inspections' => array(
                        'has_reports' => false,
                        'page_url'    => null,
                        'dataset_urls' => array(),
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
add_action('rest_api_init', 'kop_register_country_rest_routes');
