<?php
/**
 * Reader for the base64 TSV dumps used by the offline migration rehearsal
 * (docs/DATA-MODEL-MIGRATION.md section 1).
 *
 * Produce a dump with, per table:
 *
 *   SELECT CONCAT(id, '\t', REPLACE(TO_BASE64(unique_name), '\n', ''), '\t',
 *                 REPLACE(TO_BASE64(json_data), '\n', ''), '\t', updated_at)
 *   FROM facilities_master;
 *
 * Base64 sidesteps the mysql batch-mode escaping of tabs and newlines inside
 * json_data. The separator itself arrives as a literal backslash-t, which is
 * what kop_dump_rows() splits on.
 *
 * Shared by scripts/normalize-dump.php and scripts/rehearse-migration.php.
 */

if (!function_exists('kop_dump_rows')) {
    /**
     * Stream one dump file. Each row is passed to $each as
     * {id, unique_name, json (decoded array or null), raw_json, updated_at}.
     *
     * @param string   $path  path to <table>.tsv
     * @param callable $each  fn(array $row): void
     * @param callable|null $on_error fn(string $message): void
     * @return int rows read
     */
    function kop_dump_rows($path, callable $each, callable $on_error = null) {
        $handle = fopen($path, 'r');
        if (!$handle) {
            throw new RuntimeException('Cannot open dump file: ' . $path);
        }
        $separator = chr(92) . 't';   // literal backslash-t, not a tab
        $count = 0;

        while (($line = fgets($handle)) !== false) {
            $line = rtrim($line, "\r\n");
            if ($line === '') continue;

            $parts = explode($separator, $line);
            if (count($parts) < 3) {
                if ($on_error) $on_error('malformed line ' . ($count + 1) . ' in ' . basename($path));
                continue;
            }

            $raw_json = base64_decode($parts[2], true);
            $decoded = null;
            if ($raw_json === false) {
                if ($on_error) $on_error('row ' . $parts[0] . ': json_data is not valid base64');
            } else {
                $decoded = json_decode($raw_json, true);
                if ($decoded === null && trim($raw_json) !== 'null') {
                    if ($on_error) $on_error('row ' . $parts[0] . ': ' . json_last_error_msg());
                }
            }

            $each(array(
                'id'          => (int)$parts[0],
                'unique_name' => (string)base64_decode($parts[1], true),
                'json'        => is_array($decoded) ? $decoded : null,
                'raw_json'    => $raw_json === false ? '' : $raw_json,
                'updated_at'  => isset($parts[3]) ? $parts[3] : '',
            ));
            $count++;
        }

        fclose($handle);
        return $count;
    }
}

if (!function_exists('kop_dump_unwrap_project')) {
    /**
     * The facilities array of an operator/location row, whichever wrapper it
     * uses: data.facilities (new), facilities at the root (old), or a
     * double-wrapped data.data.facilities.
     */
    function kop_dump_unwrap_project(array $payload) {
        if (isset($payload['data']) && is_array($payload['data'])) {
            $data = $payload['data'];
            if (isset($data['facilities']) && is_array($data['facilities'])) {
                return $data['facilities'];
            }
            if (isset($data['data']) && is_array($data['data']) && isset($data['data']['facilities'])
                && is_array($data['data']['facilities'])) {
                return $data['data']['facilities'];
            }
            if (isset($data['facility']) && is_array($data['facility'])) {
                return array($data['facility']);
            }
        }
        if (isset($payload['facilities']) && is_array($payload['facilities'])) {
            return $payload['facilities'];
        }
        return array();
    }
}

if (!function_exists('kop_dump_walk_facilities')) {
    /**
     * Walk every facility copy in a dump directory, in one pass.
     *
     * $each receives (array $facility, array $context) where the context is:
     *   source      'facilities_master.ref' | 'facilities_master.operator' | 'locations_master'
     *   row_id      the row the copy sits in
     *   row_name    that row's unique_name
     *   updated_at  that row's updated_at (the freshness R2 compares)
     *   index       position in the row's facilities array (null for ref rows)
     *   opts        ready-made options for kop_facility_normalize()
     *
     * @return array counts per source
     */
    function kop_dump_walk_facilities($dir, callable $each, callable $on_error = null) {
        $counts = array();
        $bump = function ($key) use (&$counts) {
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        };

        $facilities_path = rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . 'facilities_master.tsv';
        if (is_file($facilities_path)) {
            kop_dump_rows($facilities_path, function (array $row) use ($each, $bump) {
                $payload = $row['json'];
                if (!is_array($payload)) return;

                if (!empty($payload['__facility_ref'])) {
                    $bump('facilities_master.ref');
                    $each($payload, array(
                        'source'     => 'facilities_master.ref',
                        'row_id'     => $row['id'],
                        'row_name'   => $row['unique_name'],
                        'updated_at' => $row['updated_at'],
                        'index'      => null,
                        'opts'       => array(
                            'facility_id' => $row['id'],
                            'unique_name' => $row['unique_name'],
                            'source'      => 'facilities_master.ref',
                        ),
                    ));
                    return;
                }

                foreach (kop_dump_unwrap_project($payload) as $i => $facility) {
                    if (!is_array($facility)) continue;
                    $bump('facilities_master.operator');
                    $each($facility, array(
                        'source'     => 'facilities_master.operator',
                        'row_id'     => $row['id'],
                        'row_name'   => $row['unique_name'],
                        'updated_at' => $row['updated_at'],
                        'index'      => $i,
                        'opts'       => array(
                            'facility_id' => isset($facility['facility_id']) ? (int)$facility['facility_id'] : null,
                            'source'      => 'facilities_master.operator:' . $row['unique_name'],
                        ),
                    ));
                }
            }, $on_error);
        } elseif ($on_error) {
            $on_error('missing ' . $facilities_path);
        }

        $locations_path = rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . 'locations_master.tsv';
        if (is_file($locations_path)) {
            kop_dump_rows($locations_path, function (array $row) use ($each, $bump) {
                $payload = $row['json'];
                if (!is_array($payload)) return;
                foreach (kop_dump_unwrap_project($payload) as $i => $facility) {
                    if (!is_array($facility)) continue;
                    $bump('locations_master');
                    $each($facility, array(
                        'source'     => 'locations_master',
                        'row_id'     => $row['id'],
                        'row_name'   => $row['unique_name'],
                        'updated_at' => $row['updated_at'],
                        'index'      => $i,
                        'opts'       => array(
                            'facility_id'  => isset($facility['facility_id']) ? (int)$facility['facility_id'] : null,
                            'location_key' => $row['unique_name'],
                            'source'       => 'locations_master:' . $row['unique_name'],
                        ),
                    ));
                }
            }, $on_error);
        } elseif ($on_error) {
            $on_error('missing ' . $locations_path);
        }

        return $counts;
    }
}
