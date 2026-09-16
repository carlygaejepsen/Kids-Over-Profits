<?php
/**
 * Readers for the v2 facility model (docs/DATA-MODEL-MIGRATION.md, phases 3-4).
 *
 * A state or country page is a join of facilities_v2 to
 * {prefix}kop_facility_locations on location_key. Records are built with the
 * same kop_state_build_program_record() the legacy collectors use, from the
 * legacy projection of each document, so the tile shape is unchanged.
 *
 * Used when a request asks for ?model=v2 (phase 3 step 4: snapshot the v2
 * model with scripts/snapshot-location-pages.js --model v2 and diff) or when
 * the kop_data_model option is 'v2' (phase 4 cutover).
 */

if (!function_exists('kop_v2_model_requested')) {
    /** True when this request should read the v2 model and the v2 tables exist. */
    function kop_v2_model_requested() {
        static $answer = null;
        if ($answer !== null) return $answer;

        $wanted = (isset($_GET['model']) && $_GET['model'] === 'v2')
            || (function_exists('get_option') && get_option('kop_data_model') === 'v2');
        if (!$wanted) return $answer = false;

        global $wpdb;
        return $answer = ($wpdb->get_var("SHOW TABLES LIKE 'facilities_v2'") === 'facilities_v2');
    }
}

if (!function_exists('kop_v2_collect_programs')) {
    /**
     * Program records for one state or country page from the v2 tables.
     * One record per facility id: no name-based merging (spec phase 4.1).
     *
     * @param string $page_name 'Utah' or 'Mexico'
     * @param string $kind      'state' or 'country'
     */
    function kop_v2_collect_programs($page_name, $kind) {
        global $wpdb;
        $locations = $wpdb->prefix . 'kop_facility_locations';
        $operator_facilities = $wpdb->prefix . 'kop_operator_facilities';
        $operators = $wpdb->prefix . 'kop_operators';

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT f.id, f.unique_name, f.json_data, f.updated_at
               FROM facilities_v2 f
              WHERE f.id IN (SELECT l.facility_id FROM {$locations} l WHERE l.location_key = %s)
              ORDER BY f.id",
            mb_strtoupper($page_name)
        ), ARRAY_A);
        if (!is_array($rows) || !$rows) return array();

        // The operator each facility belongs to, for the tile's operator name.
        $ids = array_map('intval', wp_list_pluck($rows, 'id'));
        $operator_by_facility = array();
        $op_rows = $wpdb->get_results(
            "SELECT ofc.facility_id, o.json_data
               FROM {$operator_facilities} ofc
               JOIN {$operators} o ON o.id = ofc.operator_id
              WHERE ofc.facility_id IN (" . implode(',', $ids) . ")
              ORDER BY ofc.sort_order",
            ARRAY_A
        );
        foreach ((array)$op_rows as $op) {
            $fid = (int)$op['facility_id'];
            if (isset($operator_by_facility[$fid])) continue;
            $decoded = json_decode($op['json_data'], true);
            if (is_array($decoded) && isset($decoded['operator']) && is_array($decoded['operator'])) {
                $operator_by_facility[$fid] = $decoded['operator'];
            }
        }

        $programs = array();
        foreach ($rows as $row) {
            $doc = json_decode($row['json_data'], true);
            if (!is_array($doc) || !isset($doc['location'])) continue;
            $legacy = kop_facility_to_legacy($doc);
            $data = array('facilities' => array($legacy));
            $fid = (int)$row['id'];
            if (isset($operator_by_facility[$fid])) $data['operator'] = $operator_by_facility[$fid];

            $meta = array(
                'master_id'      => $fid,
                'facility_count' => 1,
                'updated_at'     => (string)$row['updated_at'],
            );
            if ($kind === 'country') {
                $meta['default_country'] = $page_name;
                $built = kop_state_build_program_record($row['unique_name'], $legacy, $data, '', $meta);
            } else {
                $built = kop_state_build_program_record($row['unique_name'], $legacy, $data, $page_name, $meta);
            }
            if ($built !== null) $programs[] = $built['program'];
        }

        usort($programs, static function ($a, $b) {
            return strnatcasecmp($a['facility_name'] ?: $a['project_name'], $b['facility_name'] ?: $b['project_name']);
        });
        return $programs;
    }
}
