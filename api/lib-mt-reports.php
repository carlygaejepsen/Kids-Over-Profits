<?php
/**
 * Montana's licensing surveys into inspection_facilities / inspection_reports.
 *
 * Montana has no scraper that posts to api/inspections-write.php: mt_dl.py
 * only downloads the DPHHS statements of deficiencies, and their text,
 * extracted into js/data/mt_reports.json, is what /mt-reports/ reads. The
 * serious findings scan (inc/inspection-highlights.php) and the facility
 * pages read the database, so this copies each survey there, one facility
 * per program name and one report per survey file, the record unchanged as
 * categories_json. Fields of the header are found by the shape of their
 * values, as js/inspections/states/mt.js does: the labels are shifted on
 * about 58 surveys.
 *
 * Re-running is safe: a survey already copied and unchanged is left alone;
 * one whose record changed is updated and its scan mark removed, so the
 * next scan reads it again. Works on MySQL and on the SQLite test mirror.
 *
 * Callers: api/scan-inspection-highlights.php (before an applied scan that
 * includes Montana) and scripts/test-inspection-highlights.php.
 */

if (!function_exists('kop_mt_reports_path')) {

    function kop_mt_reports_path() {
        return dirname(__DIR__) . '/js/data/mt_reports.json';
    }

    /** One survey's header, read by the shape of each value. */
    function kop_mt_reports_header(array $header) {
        $values = array();
        foreach ($header as $key => $value) {
            if (in_array($key, array('Facility', 'Administrator', 'Description'), true) || !is_scalar($value)) continue;
            $values[] = trim((string) $value);
        }
        $find = static function ($re) use ($values) {
            foreach ($values as $v) if (preg_match($re, $v)) return $v;
            return '';
        };
        // The survey date is the earlier of the survey and response-due dates.
        $dates = array();
        foreach ($values as $v) {
            if (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})$#', $v, $m) && checkdate((int) $m[1], (int) $m[2], (int) $m[3])) {
                $dates[sprintf('%04d-%02d-%02d', $m[3], $m[1], $m[2])] = $v;
            }
        }
        ksort($dates);
        $city = trim((string) ($header['City'] ?? ''));
        if (!preg_match('/^[A-Za-z][A-Za-z .]{1,30}$/', $city) || preg_match('/Inspection$/i', $city)) $city = '';
        return array(
            'facility' => rtrim(trim((string) ($header['Facility'] ?? '')), ', '),
            'administrator' => trim((string) ($header['Administrator'] ?? '')),
            'license' => $find('/^(?:\d{4,5}-\d{2}|0{3,}\d+)$/'),
            'type' => $find('/^(?:Renewal|Complaint|Follow[\s-]?Up|Provisional Status|Initial|Annual)\b.*Inspection$/i'),
            'date' => $dates ? reset($dates) : '',
            'street' => $find('/^\d+\s+\S+.*\b(?:St|Street|Rd|Road|Dr|Drive|Ave|Avenue|Blvd|Way|Ln|Lane|Ct|Hwy|Pl|Place|Loop|Trail)\.?$|^\d+\s+[NSEW]\.?\s+\S+/i'),
            'city' => $city,
            'zip' => $find('/^\d{5}(?:-\d{4})?$/'),
            'phone' => $find('/^\(?\d{3}\)?[\s.-]?\d{3}-\d{4}$/'),
        );
    }

    /**
     * The surveys as facilities with reports, in the shape the write API
     * takes: array(facility_name => array('info' => ..., 'reports' => [...])).
     */
    function kop_mt_reports_read($path = '') {
        $path = $path !== '' ? $path : kop_mt_reports_path();
        $surveys = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
        if (!is_array($surveys)) return array();
        $out = array();
        foreach ($surveys as $survey) {
            if (!is_array($survey) || !is_array($survey['Header'] ?? null)) continue;
            $h = kop_mt_reports_header($survey['Header']);
            $file = trim((string) ($survey['source_file'] ?? ''));
            if ($h['facility'] === '' || $file === '') continue;
            if (!isset($out[$h['facility']])) {
                $out[$h['facility']] = array('info' => array(
                    'facility_name' => $h['facility'],
                    'full_address' => trim(implode(', ', array_filter(array($h['street'], $h['city'], trim('MT ' . $h['zip']))))),
                    'phone' => $h['phone'], 'executive_director' => $h['administrator'],
                ), 'reports' => array());
            }
            $text = array();
            foreach (array_merge(array($survey['Header']['Description'] ?? ''), array_map(static function ($i) {
                return is_array($i) ? (($i['Rule'] ?? '') . "\n" . ($i['Findings'] ?? '')) : '';
            }, (array) ($survey['Issues'] ?? array()))) as $part) {
                if (trim((string) $part) !== '') $text[] = trim((string) $part);
            }
            $raw = implode("\n\n", $text);
            $out[$h['facility']]['reports'][] = array(
                'report_id' => mb_substr('mt-' . $file, 0, 100),
                'report_date' => $h['date'],
                'raw_content' => $raw,
                'summary' => trim(($h['type'] !== '' ? $h['type'] : 'Survey') . ($h['license'] !== '' ? ', license ' . $h['license'] : '')),
                'categories' => $survey,
            );
        }
        return $out;
    }

    /**
     * Copy the surveys into the database. With $apply false nothing is
     * written and the counts say what would be. Returns array(facilities,
     * added, updated, unchanged).
     */
    function kop_mt_reports_import(PDO $pdo, $apply = false, $path = '') {
        $counts = array('facilities' => 0, 'added' => 0, 'updated' => 0, 'unchanged' => 0);
        $facilities = kop_mt_reports_read($path);
        if (!$facilities) return $counts;
        $stamp = gmdate('c');
        $find_fac = $pdo->prepare("SELECT id FROM inspection_facilities WHERE state = 'MT' AND facility_name = ? AND program_name = ''");
        $find_rep = $pdo->prepare('SELECT id, categories_json, report_date FROM inspection_reports WHERE facility_id = ? AND report_id = ?');
        $has_scans = false;
        if ($apply) {
            try { $pdo->query('SELECT 1 FROM inspection_highlight_scans LIMIT 1'); $has_scans = true; } catch (Exception $e) { $has_scans = false; }
            $pdo->beginTransaction();
        }
        try {
            foreach ($facilities as $name => $fac) {
                $counts['facilities']++;
                $find_fac->execute(array($name));
                $fid = (int) $find_fac->fetchColumn();
                $info = $fac['info'];
                if ($apply && !$fid) {
                    $pdo->prepare("INSERT INTO inspection_facilities (state, facility_name, full_address, phone, program_category, program_name, executive_director, scraped_timestamp)
                        VALUES ('MT', ?, ?, ?, 'DPHHS licensing survey', '', ?, ?)")
                        ->execute(array($name, $info['full_address'], $info['phone'], $info['executive_director'], $stamp));
                    $fid = (int) $pdo->lastInsertId();
                }
                foreach ($fac['reports'] as $r) {
                    $json = json_encode($r['categories'], JSON_UNESCAPED_UNICODE);
                    $existing = false;
                    if ($fid) {
                        $find_rep->execute(array($fid, $r['report_id']));
                        $existing = $find_rep->fetch(PDO::FETCH_ASSOC);
                    }
                    if ($existing && $existing['categories_json'] === $json && (string) $existing['report_date'] === $r['report_date']) {
                        $counts['unchanged']++;
                        continue;
                    }
                    $counts[$existing ? 'updated' : 'added']++;
                    if (!$apply) continue;
                    $values = array($r['report_date'], $r['raw_content'], strlen($r['raw_content']), $r['summary'], $json);
                    if ($existing) {
                        $pdo->prepare('UPDATE inspection_reports SET report_date = ?, raw_content = ?, content_length = ?, is_structured = 1, summary = ?, categories_json = ? WHERE id = ?')
                            ->execute(array_merge($values, array((int) $existing['id'])));
                        if ($has_scans) $pdo->prepare('DELETE FROM inspection_highlight_scans WHERE report_id = ?')->execute(array((int) $existing['id']));
                    } else {
                        $pdo->prepare("INSERT INTO inspection_reports (facility_id, report_id, report_date, report_url, raw_content, content_length, is_structured, summary, categories_json)
                            VALUES (?, ?, ?, '', ?, ?, 1, ?, ?)")
                            ->execute(array_merge(array($fid, $r['report_id']), $values));
                    }
                }
            }
            if ($apply) $pdo->commit();
        } catch (Exception $e) {
            if ($apply && $pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        return $counts;
    }
}
