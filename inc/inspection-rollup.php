<?php
/**
 * Inspection rankings: what the state inspection reports add up to, by
 * parent company, facility and state (KOP Tools > Inspection Rankings).
 *
 * Two kinds of number:
 *   - The state's own verdicts, read from each report's structured fields by
 *     kop_irl_report_counts(): violations cited, high-risk and repeat
 *     citations, complaints investigated and substantiated. Each report is
 *     counted once into inspection_report_counts by an hourly batch; a change
 *     to the rules bumps kop_irl_version() and every report is counted again.
 *   - Reviewed serious findings (inc/inspection-highlights.php): approved
 *     findings by category (deaths, staff assaults, sexual abuse, ...).
 *     Pending findings are counted apart and shown to admins only, to show
 *     which reviews would move a ranking.
 *
 * Owner rule (2026-10-05): anything public counts approved findings and the
 * states' own verdicts only, never pending findings.
 *
 * States publish very different amounts (Texas every citation, California
 * every complaint, Georgia almost nothing structured), so a measure a state
 * does not publish is shown as a dash, never 0 (kop_irl_state_measures()),
 * and facilities are best compared within one state.
 *
 * Inspection rows reach facility records by the facility pages' name rule
 * (kop_facility_pages_inspections()) plus KOP Tools > Inspection Links, and
 * records reach parent companies through {prefix}kop_operator_facilities.
 * The rollup is cached in uploads/kop-cache/inspection-rollup/ under a
 * fingerprint of everything it reads.
 *
 * No WordPress needed below the admin screen. Tested by
 * scripts/test-inspection-rollup.php.
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('kop_irl_version')) {

    /** Bump when kop_irl_report_counts() or kop_irl_report_year() changes: every report is counted again. */
    function kop_irl_version() {
        return 4;
    }

    // -----------------------------------------------------------------------
    // What is counted
    // -----------------------------------------------------------------------

    /** The state-verdict measures, in display order. */
    function kop_irl_measures() {
        return array(
            'reports'       => array('label' => 'Reports', 'help' => 'Reports on file (a copy the scraper stored twice is counted once). In Texas every row is one citation, so Texas counts run higher.'),
            'cited'         => array('label' => 'Reports citing violations', 'help' => 'Reports in which the state cited at least one violation or deficiency.'),
            'citations'     => array('label' => 'Violations cited', 'help' => 'Violations or deficiencies the state cited.'),
            'high_risk'     => array('label' => 'High-risk citations', 'help' => 'Texas only: citations of standards the state rates High risk.'),
            'repeat'        => array('label' => 'Repeat citations', 'help' => 'Violations the state cited again after an earlier citation.'),
            'complaints'    => array('label' => 'Complaints investigated', 'help' => 'Complaint investigations and complaint surveys.'),
            'substantiated' => array('label' => 'Complaints substantiated', 'help' => 'Complaints the state found true.'),
        );
    }

    /**
     * Which measures each state publishes, with where the number comes from.
     * A state not listed has reports only. Left out on purpose: WA (the
     * scraper interleaves PDF columns), CT (the non-compliance field is
     * mostly "Not at the time of this visit"), AR/NV (no verdict field).
     * Oregon's site visits have no verdict field either; its complaints are
     * the abuse reports ODHS substantiated, the only ones it publishes.
     */
    function kop_irl_state_measures() {
        return array(
            'TX' => array(
                'cited'      => 'Every Texas row is a citation',
                'citations'  => 'Every Texas row is a citation',
                'high_risk'  => 'Standard Risk Level "High"',
                'repeat'     => 'Correction evaluation "Re-cited"',
            ),
            'CA' => array(
                'cited'         => 'Deficiencies listed, or a substantiated complaint',
                'citations'     => 'Deficiencies listed on the report',
                'complaints'    => 'Complaint investigation reports (LIC 9099)',
                'substantiated' => 'Complaint status "Substantiated", as the state filed it (about 1 mixed report in 10 is filed as unsubstantiated)',
            ),
            'MI' => array(
                'cited'         => 'A violation established',
                'citations'     => 'Violations established',
                'complaints'    => 'Special investigations',
                'substantiated' => 'Special investigations with a violation established',
            ),
            'OK' => array(
                'cited'         => 'Items cited',
                'citations'     => 'Items cited on visits; substantiated items on complaints',
                'complaints'    => 'Complaints',
                'substantiated' => 'Complaint finding "Substantiated"',
            ),
            'UT' => array(
                'cited'     => 'Findings count above 0',
                'citations' => 'Findings count',
            ),
            'AZ' => array(
                'cited'      => 'Deficiencies listed',
                'citations'  => 'Deficiencies listed',
                'repeat'     => 'Deficiencies the surveyor calls a repeat',
                'complaints' => 'Complaint inspections',
            ),
            'FL' => array(
                'cited'      => 'AHCA deficiencies or DJJ indicators below satisfactory',
                'citations'  => 'AHCA deficiencies; DJJ quality-improvement indicators rated Failed or Limited compliance',
                'complaints' => 'AHCA complaint surveys',
            ),
            'NC' => array(
                'cited'      => 'Statements of deficiency citing a rule not met',
                'citations'  => 'Rules "not met as evidenced by" in the statement, as /nc-reports/ reads it',
                'complaints' => 'Statements of deficiency from a complaint survey',
            ),
            'MN' => array(
                'cited'         => 'Correction orders, orders and notices of violation',
                'citations'     => 'Numbered "Violation:" paragraphs in those documents',
                'repeat'        => '"Repeat Violation:" notes',
                'complaints'    => 'Maltreatment investigation memos',
                'substantiated' => 'Maltreatment determined',
            ),
            'GA' => array(
                'complaints' => 'Licensure complaint surveys',
            ),
            'OR' => array(
                'complaints'    => 'Abuse reports in the quarterly legislative reports (Oregon publishes only the ones it substantiated)',
                'substantiated' => 'Abuse reports substantiated by the Department of Human Services',
            ),
        );
    }

    /** Does $state publish $measure? Reports are counted everywhere. */
    function kop_irl_state_has($state, $measure) {
        if ($measure === 'reports') return true;
        $m = kop_irl_state_measures();
        return isset($m[$state][$measure]);
    }

    /**
     * Reviewed finding categories shown in the rankings, from the scanner's
     * categories (kop_ih_categories()), with labels that say who did it.
     */
    function kop_irl_finding_columns() {
        return array(
            'death'            => array('label' => 'Deaths', 'help' => 'Findings that record a death.'),
            'physical_abuse'   => array('label' => 'Staff assaults', 'help' => 'Physical abuse or assault by staff. Fights between children are not counted.'),
            'sexual_abuse'     => array('label' => 'Sexual abuse', 'help' => 'Sexual abuse by staff or another adult, or a sexual assault.'),
            'restraint_injury' => array('label' => 'Restraint injuries', 'help' => 'Restraint or seclusion that injured a child.'),
            'suicide_attempt'  => array('label' => 'Suicide attempts', 'help' => 'Findings that record a suicide attempt.'),
            'self_harm'        => array('label' => 'Self-harm', 'help' => 'Findings that record self-harm.'),
            'medical_neglect'  => array('label' => 'Medical neglect', 'help' => 'Care or medication withheld, or a pattern of medication errors.'),
            'hospitalization'  => array('label' => 'Hospitalisations', 'help' => 'Findings that record a child taken to hospital.'),
            'missing'          => array('label' => 'Missing children', 'help' => 'A child missing or run away, with serious harm recorded.'),
        );
    }

    /** Integer from a state field ("3", 3, "" -> 0). */
    function kop_irl_int($v) {
        return is_numeric($v) ? max(0, (int) $v) : 0;
    }

    /** Number of entries in a list field, 0 when it is not a list. */
    function kop_irl_list_count($v) {
        return is_array($v) ? count($v) : 0;
    }

    /**
     * The state's own verdicts on one report: measure => count, only for the
     * measures the state publishes (kop_irl_state_measures()); zeros left out.
     * $data is the report's decoded categories_json; $raw its raw_content,
     * read for Minnesota and North Carolina only.
     */
    function kop_irl_report_counts($state, array $data, $raw = '') {
        $state = strtoupper((string) $state);
        $c = array();
        switch ($state) {
            case 'TX':
                $c['citations'] = 1;
                if (trim((string) ($data['Standard Risk Level'] ?? '')) === 'High') $c['high_risk'] = 1;
                if (strcasecmp(trim((string) ($data['Correction Evaluation Result'] ?? '')), 'Re-cited') === 0) $c['repeat'] = 1;
                break;

            case 'CA':
                $c['citations'] = kop_irl_list_count($data['deficiencies'] ?? null);
                $complaint = ($data['report_type'] ?? '') === 'Complaint Investigation' || ($data['form_number'] ?? '') === 'LIC9099';
                if ($complaint) {
                    $c['complaints'] = 1;
                    if (strcasecmp(trim((string) ($data['complaint_status'] ?? '')), 'substantiated') === 0) $c['substantiated'] = 1;
                }
                if (!empty($c['substantiated'])) $c['cited'] = 1;
                break;

            case 'MI':
                $c['citations'] = kop_irl_int($data['violations_established'] ?? 0);
                if (($data['doc_type'] ?? '') === 'special_investigation') {
                    $c['complaints'] = 1;
                    if ($c['citations'] > 0) $c['substantiated'] = 1;
                }
                break;

            case 'OK':
                $n = 0;
                foreach ((array) ($data['items'] ?? array()) as $item) {
                    if (!is_array($item)) continue;
                    $finding = trim((string) ($item['finding'] ?? ''));
                    if ($finding === '' || strcasecmp($finding, 'Substantiated') === 0) $n++;
                }
                $c['citations'] = $n;
                if (($data['kind'] ?? '') === 'complaint') {
                    $c['complaints'] = 1;
                    if (strcasecmp(trim((string) ($data['finding'] ?? '')), 'Substantiated') === 0) $c['substantiated'] = 1;
                }
                break;

            case 'UT':
                $c['citations'] = kop_irl_int($data['Findings Count'] ?? 0);
                break;

            case 'AZ':
                $defs = is_array($data['deficiencies'] ?? null) ? $data['deficiencies'] : array();
                $c['citations'] = count($defs);
                $repeat = 0;
                foreach ($defs as $d) {
                    $text = is_array($d) ? ((string) ($d['evidence'] ?? '') . ' ' . (string) ($d['findings'] ?? '')) : (string) $d;
                    if (preg_match('/\brepeat(?:ed)? (?:deficiency|deficient practice|citation|violation)\b/i', $text)) $repeat++;
                }
                $c['repeat'] = $repeat;
                if (stripos((string) ($data['inspection_type'] ?? ''), 'Complaint') !== false) $c['complaints'] = 1;
                break;

            case 'FL':
                if (($data['source'] ?? '') === 'AHCA') {
                    $n = 0;
                    foreach ((array) ($data['deficiencies'] ?? array()) as $d) {
                        $code = is_array($d) ? trim((string) ($d['deficiency'] ?? '')) : '';
                        if ($code !== '' && strcasecmp($code, 'None') !== 0) $n++;
                    }
                    $c['citations'] = $n;
                    if (($data['report_type'] ?? '') === 'Complaint') $c['complaints'] = 1;
                } elseif (preg_match('/^QI\b/', (string) ($data['report_type'] ?? ''))) {
                    $n = 0;
                    foreach ((array) ($data['findings'] ?? array()) as $f) {
                        if (is_array($f) && preg_match('/\b(?:Failed|Limited)\b/i', (string) ($f['rating'] ?? ''))) $n++;
                    }
                    $c['citations'] = $n;
                }
                break;

            case 'NC':
                if (preg_match('/Statement of Defi/i', (string) ($data['document_type'] ?? ''))) {
                    // "No deficiencies were cited" statements cite nothing: count the
                    // rules not met, as /nc-reports/ does (kop_its_nc_statement()).
                    require_once dirname(__DIR__) . '/api/lib-inspection-text-signals.php';
                    $c['citations'] = kop_its_nc_statement((string) $raw)['citations'];
                    if (stripos((string) ($data['inspection_type'] ?? ''), 'Complaint') !== false) $c['complaints'] = 1;
                }
                break;

            case 'MN':
                $raw = (string) $raw;
                $doc = (string) ($data['doc_type'] ?? '');
                if ($doc === 'Maltreatment Finding' || preg_match('/MALTREATMENT INVESTIGATION MEMORANDUM/iu', $raw)) {
                    $c['complaints'] = 1;
                    // The same reading as kop_ih_extract_mn(): the disposition line decides.
                    if (preg_match('/Disposition:\s*([^\r\n]+)/u', $raw, $m)
                        && preg_match('/\b(?:Maltreatment (?:was )?determined|Substantiated)\b/iu', $m[1])
                        && !preg_match('/\bnot\s+(?:determined|substantiated)\b/iu', $m[1])) {
                        $c['substantiated'] = 1;
                    }
                } elseif (in_array($doc, array('Correction Order', 'Order', 'Notice of Violation'), true)) {
                    $c['cited'] = 1;
                    // Numbered paragraphs ("3. Violation: ..."); "Repeat Violation:" notes one cited before.
                    $c['citations'] = max(1, (int) preg_match_all('/^\s*\d+\.\s*Violation:/mu', $raw));
                    $c['repeat'] = (int) preg_match_all('/\bRepeat Violation:/u', $raw);
                }
                break;

            case 'GA':
                if (stripos((string) ($data['survey_type'] ?? ''), 'Complaint') !== false) $c['complaints'] = 1;
                break;

            case 'OR':
                if (($data['kind'] ?? '') === 'complaint') {
                    $c['complaints'] = 1;
                    if (strcasecmp(trim((string) ($data['finding'] ?? '')), 'Substantiated') === 0) $c['substantiated'] = 1;
                }
                break;
        }
        if (!isset($c['cited']) && !empty($c['citations'])) $c['cited'] = 1;
        $out = array();
        foreach ($c as $k => $v) {
            if ((int) $v > 0 && kop_irl_state_has($state, $k)) $out[$k] = (int) $v;
        }
        return $out;
    }

    /**
     * The report's year: its report_date, else a date field the state fills,
     * else Florida DJJ's fiscal year (FY23-24 -> 2024, when the report is
     * final). null when nothing parses.
     */
    function kop_irl_report_year($report_date, array $data) {
        $candidates = array($report_date);
        foreach (array('visit_date', 'survey_date', 'inspection_date', 'Citation Date', 'Inspection Date', 'intake_date', 'survey_start_date', 'date_signed', 'posted_date') as $k) {
            if (isset($data[$k]) && is_scalar($data[$k])) $candidates[] = $data[$k];
        }
        foreach ($candidates as $text) {
            $date = function_exists('kop_ih_parse_date') ? kop_ih_parse_date($text) : null;
            if ($date) return (int) substr($date, 0, 4);
        }
        if (isset($data['fiscal_year']) && preg_match('/^FY\d{2}-(\d{2})$/', trim((string) $data['fiscal_year']), $m)) {
            return 2000 + (int) $m[1];
        }
        return null;
    }

    // -----------------------------------------------------------------------
    // Counts table
    // -----------------------------------------------------------------------

    function kop_irl_is_sqlite(PDO $pdo) {
        return $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
    }

    /** Create inspection_report_counts when missing. Safe to call on every run. */
    function kop_irl_ensure_table(PDO $pdo) {
        if (kop_irl_is_sqlite($pdo)) {
            $pdo->exec("CREATE TABLE IF NOT EXISTS inspection_report_counts (
                report_id INTEGER PRIMARY KEY, facility_id INTEGER NOT NULL, state TEXT NOT NULL, year INTEGER,
                content_hash TEXT NOT NULL, counts TEXT NOT NULL, version INTEGER NOT NULL,
                counted_at TEXT DEFAULT CURRENT_TIMESTAMP)");
            return;
        }
        $pdo->exec("CREATE TABLE IF NOT EXISTS inspection_report_counts (
            report_id int(11) NOT NULL COMMENT 'inspection_reports.id',
            facility_id int(11) NOT NULL COMMENT 'inspection_facilities.id',
            state char(2) NOT NULL,
            year smallint DEFAULT NULL,
            content_hash char(16) NOT NULL COMMENT 'Hash of categories_json; a report stored twice is counted once',
            counts varchar(500) NOT NULL COMMENT 'JSON: the state verdicts, kop_irl_report_counts()',
            version int(11) NOT NULL,
            counted_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (report_id),
            KEY facility (facility_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    /**
     * Count reports not yet counted under this version, oldest id first.
     * Returns {counted, remaining}. Stops after $seconds.
     */
    function kop_irl_count_batch(PDO $pdo, $limit = 2000, $seconds = 20) {
        $started = microtime(true);
        kop_irl_ensure_table($pdo);
        $v = (int) kop_irl_version();
        $limit = max(1, (int) $limit);
        $done = 0;
        $last = 0;
        $chunk = min(500, $limit);
        $write = $pdo->prepare('REPLACE INTO inspection_report_counts (report_id, facility_id, state, year, content_hash, counts, version) VALUES (?, ?, ?, ?, ?, ?, ?)');
        while ($done < $limit && microtime(true) - $started < $seconds) {
            $rows = $pdo->query("SELECT r.id, r.facility_id, f.state, r.report_date, r.categories_json,
                    CASE WHEN f.state IN ('MN', 'NC') THEN r.raw_content ELSE NULL END AS raw
                FROM inspection_reports r
                JOIN inspection_facilities f ON f.id = r.facility_id
                LEFT JOIN inspection_report_counts c ON c.report_id = r.id
                WHERE r.id > $last AND (c.report_id IS NULL OR c.version <> $v)
                ORDER BY r.id LIMIT " . (int) min($chunk, $limit - $done))->fetchAll(PDO::FETCH_ASSOC);
            if (!$rows) break;
            $pdo->beginTransaction();
            try {
                foreach ($rows as $r) {
                    $json = (string) $r['categories_json'];
                    $data = json_decode($json, true);
                    if (!is_array($data)) $data = array();
                    $state = strtoupper(trim((string) $r['state']));
                    $counts = kop_irl_report_counts($state, $data, (string) $r['raw']);
                    $year = kop_irl_report_year($r['report_date'], $data);
                    $hash = substr(sha1($json . '|' . trim((string) $r['report_date'])), 0, 16);
                    $write->execute(array((int) $r['id'], (int) $r['facility_id'], substr($state, 0, 2), $year, $hash,
                        $counts ? json_encode($counts) : '{}', $v));
                }
                $pdo->commit();
            } catch (Throwable $e) {
                $pdo->rollBack();
                throw $e;
            }
            $done += count($rows);
            $last = (int) end($rows)['id'];
        }
        return array('counted' => $done, 'remaining' => kop_irl_remaining($pdo));
    }

    /** Reports not yet counted under this version. */
    function kop_irl_remaining(PDO $pdo) {
        $v = (int) kop_irl_version();
        return (int) $pdo->query("SELECT COUNT(*) FROM inspection_reports r
            LEFT JOIN inspection_report_counts c ON c.report_id = r.id
            WHERE c.report_id IS NULL OR c.version <> $v")->fetchColumn();
    }

    // -----------------------------------------------------------------------
    // Rollup
    // -----------------------------------------------------------------------

    function kop_irl_table_exists(PDO $pdo, $table) {
        try {
            $pdo->query('SELECT 1 FROM ' . $table . ' LIMIT 1');
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    /** Name key, the facility pages' rule when it is loaded. */
    function kop_irl_name_key($name) {
        if (function_exists('kop_facility_pages_name_key')) return kop_facility_pages_name_key($name);
        $s = strtolower(preg_replace('/\s*\([^)]*\)\s*$/u', '', trim((string) $name)));
        $s = preg_replace('/[^\w\s]/u', '', $s);
        return trim(preg_replace('/\s+/', ' ', $s));
    }

    /** Does a record's name key take an inspection row's key? The facility pages' rule. */
    function kop_irl_key_matches($record_key, $row_key) {
        if (function_exists('kop_facility_pages_key_matches')) return kop_facility_pages_key_matches($record_key, $row_key);
        if ($record_key === '' || $row_key === '') return false;
        if ($record_key === $row_key) return true;
        if (mb_strlen($record_key) < 12 || mb_strlen($row_key) < 12) return false;
        return mb_strpos($row_key, $record_key) === 0;
    }

    /** A record's name keys: current, other and past names, as its page reads them. */
    function kop_irl_record_keys(array $doc, $unique_name) {
        if (function_exists('kop_facility_pages_doc_name_keys')) return kop_facility_pages_doc_name_keys($doc, (string) $unique_name);
        $keys = array();
        $ident = is_array($doc['identification'] ?? null) ? $doc['identification'] : array();
        $names = array($ident['name'] ?? '', $ident['currentName'] ?? '', $unique_name);
        foreach (array('otherNames', 'pastNames') as $k) {
            foreach ((array) ($ident[$k] ?? array()) as $n) if (is_string($n)) $names[] = $n;
        }
        foreach ($names as $n) {
            $k = kop_irl_name_key($n);
            if ($k !== '') $keys[$k] = true;
        }
        return array_keys($keys);
    }

    /** Add $n to $bucket[$key]. */
    function kop_irl_add(array &$bucket, $key, $n = 1) {
        $bucket[$key] = ($bucket[$key] ?? 0) + $n;
    }

    /**
     * Build the rollup from the database:
     *   sites      inspection_facilities id => {id, st, name, y: {year: {measure|f.cat|p.cat|f|p: n}}, recs: [facility ids]}
     *              (year 0 = no date). f.<cat> approved findings, p.<cat> pending; f / p their totals.
     *   records    facility id => {name, st, sites: [site ids], ops: [operator ids]}
     *   operators  operator id => {name, recs: [facility ids]}
     * $prefix is the WordPress table prefix; $links the kop_inspection_links
     * option's links (facility id => [site ids]).
     */
    function kop_irl_build(PDO $pdo, $prefix = 'wpdl_', array $links = array()) {
        $v = (int) kop_irl_version();
        $sites = array();
        foreach ($pdo->query('SELECT id, state, facility_name FROM inspection_facilities')->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $sites[(int) $r['id']] = array('id' => (int) $r['id'], 'st' => strtoupper(trim((string) $r['state'])),
                'name' => trim((string) $r['facility_name']), 'y' => array(), 'recs' => array());
        }

        // The states' verdicts; a report stored twice (same facility, same content) counts once.
        if (kop_irl_table_exists($pdo, 'inspection_report_counts')) {
            $seen = array();
            $q = $pdo->query("SELECT c.facility_id, c.year, c.content_hash, c.counts FROM inspection_report_counts c
                JOIN inspection_reports r ON r.id = c.report_id WHERE c.version = $v");
            while ($r = $q->fetch(PDO::FETCH_ASSOC)) {
                $sid = (int) $r['facility_id'];
                if (!isset($sites[$sid])) continue;
                $dup = $sid . ':' . $r['content_hash'];
                if (isset($seen[$dup])) continue;
                $seen[$dup] = true;
                $y = (int) $r['year'];
                if (!isset($sites[$sid]['y'][$y])) $sites[$sid]['y'][$y] = array();
                $b = &$sites[$sid]['y'][$y];
                kop_irl_add($b, 'reports');
                $counts = json_decode((string) $r['counts'], true);
                foreach ((array) $counts as $k => $n) kop_irl_add($b, $k, (int) $n);
                unset($b);
            }
        }

        // Reviewed serious findings: approved (f.) and pending (p.). A finding counts once in each category it matched.
        if (kop_irl_table_exists($pdo, 'inspection_highlights')) {
            $q = $pdo->query("SELECT facility_id, status, category, categories, finding_date FROM inspection_highlights WHERE status IN ('approved', 'pending')");
            while ($r = $q->fetch(PDO::FETCH_ASSOC)) {
                $sid = (int) $r['facility_id'];
                if (!isset($sites[$sid])) continue;
                $p = $r['status'] === 'approved' ? 'f' : 'p';
                $y = preg_match('/^(\d{4})/', (string) $r['finding_date'], $m) ? (int) $m[1] : 0;
                if (!isset($sites[$sid]['y'][$y])) $sites[$sid]['y'][$y] = array();
                $b = &$sites[$sid]['y'][$y];
                kop_irl_add($b, $p);
                $cats = array_filter(array_map('trim', explode(',', (string) $r['categories'])));
                if (!$cats) $cats = array((string) $r['category']);
                foreach (array_unique($cats) as $cat) kop_irl_add($b, $p . '.' . $cat);
                unset($b);
            }
        }

        // Facility records and the inspection rows they take: the facility pages' name rule, in the
        // record's state (any state when it has none), plus the rows linked by hand.
        $records = array();
        $by_state = array();
        $exact = array();
        foreach ($pdo->query('SELECT id, unique_name, name, state, json_data FROM facilities_v2')->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $fid = (int) $r['id'];
            $doc = json_decode((string) $r['json_data'], true);
            $st = strtoupper(trim((string) $r['state']));
            $name = trim((string) ($r['name'] !== null && $r['name'] !== '' ? $r['name'] : $r['unique_name']));
            $records[$fid] = array('name' => $name, 'st' => $st, 'sites' => array(), 'ops' => array());
            foreach (kop_irl_record_keys(is_array($doc) ? $doc : array(), $r['unique_name']) as $k) {
                $by_state[$st][] = array($fid, $k);
                $exact[$st][$k][] = $fid;
            }
        }
        foreach ($sites as $sid => $s) {
            if ($s['name'] === '' || (function_exists('kop_facility_name_looks_junky') && kop_facility_name_looks_junky($s['name']))) continue;
            $rk = kop_irl_name_key($s['name']);
            if ($rk === '') continue;
            $hits = array();
            foreach (array($s['st'], '') as $st) {
                foreach ($exact[$st][$rk] ?? array() as $fid) $hits[$fid] = true;
                if (mb_strlen($rk) < 12) continue;
                foreach ($by_state[$st] ?? array() as $pair) {
                    if (!isset($hits[$pair[0]]) && kop_irl_key_matches($pair[1], $rk)) $hits[$pair[0]] = true;
                }
                if ($s['st'] === '') break;
            }
            foreach (array_keys($hits) as $fid) {
                $sites[$sid]['recs'][$fid] = true;
                $records[$fid]['sites'][$sid] = true;
            }
        }
        foreach ($links as $fid => $sids) {
            $fid = (int) $fid;
            if (!isset($records[$fid])) continue;
            foreach ((array) $sids as $sid) {
                $sid = (int) $sid;
                if (!isset($sites[$sid])) continue;
                $sites[$sid]['recs'][$fid] = true;
                $records[$fid]['sites'][$sid] = true;
            }
        }

        // Parent companies. A duplicate operator record folds into the canonical one, as on its page.
        $operators = array();
        $alias = array();
        if (function_exists('kop_operator_pages_index')) {
            $index = kop_operator_pages_index();
            $alias = is_array($index['alias_of'] ?? null) ? $index['alias_of'] : array();
        }
        $ot = $prefix . 'kop_operators';
        $oft = $prefix . 'kop_operator_facilities';
        if (kop_irl_table_exists($pdo, $ot) && kop_irl_table_exists($pdo, $oft)) {
            foreach ($pdo->query("SELECT id, name FROM $ot")->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $oid = (int) $r['id'];
                if (isset($alias[$oid])) continue;
                $operators[$oid] = array('name' => trim((string) $r['name']), 'recs' => array());
            }
            foreach ($pdo->query("SELECT operator_id, facility_id FROM $oft")->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $oid = (int) $r['operator_id'];
                if (isset($alias[$oid])) $oid = (int) $alias[$oid];
                $fid = (int) $r['facility_id'];
                if (!isset($operators[$oid]) || !isset($records[$fid])) continue;
                $operators[$oid]['recs'][$fid] = true;
                $records[$fid]['ops'][$oid] = true;
            }
        }

        // Keep only what holds something.
        $out_sites = array();
        foreach ($sites as $sid => $s) {
            if (!$s['y']) continue;
            $s['recs'] = array_keys($s['recs']);
            $out_sites[$sid] = $s;
        }
        $out_records = array();
        foreach ($records as $fid => $r) {
            $r['sites'] = array_values(array_filter(array_keys($r['sites']), function ($sid) use ($out_sites) { return isset($out_sites[$sid]); }));
            if (!$r['sites']) continue;
            $r['ops'] = array_keys($r['ops']);
            $out_records[$fid] = $r;
        }
        $out_ops = array();
        foreach ($operators as $oid => $o) {
            $o['recs'] = array_values(array_filter(array_keys($o['recs']), function ($fid) use ($out_records) { return isset($out_records[$fid]); }));
            if (!$o['recs']) continue;
            $out_ops[$oid] = $o;
        }
        return array('version' => $v, 'built' => time(), 'sites' => $out_sites, 'records' => $out_records, 'operators' => $out_ops);
    }

    /**
     * Rows for one view of the rollup, unsorted:
     *   by        'operator' | 'record' | 'site' | 'state'
     *   state     two-letter code to keep, '' for all
     *   since     first year to count, 0 for every year (and undated reports)
     * Each row: {key, name, kind, id, states: [...], sites: n, t: {measure|f.cat|p.cat|f|p: n}, has: {measure: true}}.
     * A site counts once in a company even when two of its records take it.
     */
    function kop_irl_rows(array $rollup, $by, $state = '', $since = 0) {
        $sites = $rollup['sites'] ?? array();
        $groups = array();
        $add_group = function ($key, $kind, $id, $name, array $sids) use (&$groups) {
            $groups[$key] = array('key' => $key, 'kind' => $kind, 'id' => $id, 'name' => $name, 'sids' => $sids);
        };
        if ($by === 'state') {
            $per = array();
            foreach ($sites as $sid => $s) $per[$s['st']][] = $sid;
            foreach ($per as $st => $sids) $add_group('state:' . $st, 'state', $st, $st, $sids);
        } elseif ($by === 'site') {
            foreach ($sites as $sid => $s) $add_group('site:' . $sid, 'site', $sid, $s['name'], array($sid));
        } elseif ($by === 'record') {
            // Each record with its rows; records taking the very same rows (one program's homes, a
            // duplicate) are one row under the first record. A row no record takes is listed under its licensed name.
            $same = array();
            foreach ($rollup['records'] ?? array() as $fid => $r) {
                $sids = $r['sites'];
                sort($sids);
                $same[implode(',', $sids)][] = (int) $fid;
            }
            foreach ($same as $fids) {
                sort($fids);
                $first = $rollup['records'][$fids[0]];
                $add_group('record:' . $fids[0], 'record', $fids[0], $first['name'], $first['sites']);
                $groups['record:' . $fids[0]]['also'] = count($fids) - 1;
            }
            foreach ($sites as $sid => $s) {
                if (!$s['recs']) $add_group('site:' . $sid, 'site', $sid, $s['name'], array($sid));
            }
        } else {
            foreach ($rollup['operators'] ?? array() as $oid => $o) {
                $sids = array();
                foreach ($o['recs'] as $fid) {
                    foreach ($rollup['records'][$fid]['sites'] ?? array() as $sid) $sids[$sid] = true;
                }
                $add_group('operator:' . $oid, 'operator', $oid, $o['name'], array_keys($sids));
            }
        }

        $rows = array();
        foreach ($groups as $g) {
            $t = array();
            $states = array();
            $has = array();
            $n_sites = 0;
            foreach ($g['sids'] as $sid) {
                if (!isset($sites[$sid])) continue;
                $s = $sites[$sid];
                if ($state !== '' && $s['st'] !== $state) continue;
                $n_sites++;
                $states[$s['st']] = true;
                foreach (array_keys(kop_irl_measures()) as $m) {
                    if (kop_irl_state_has($s['st'], $m)) $has[$m] = true;
                }
                foreach ($s['y'] as $y => $bucket) {
                    if ($since > 0 && (int) $y < $since) continue;
                    foreach ($bucket as $k => $n) $t[$k] = ($t[$k] ?? 0) + $n;
                }
            }
            if (!$n_sites || !$t) continue;
            $states = array_keys($states);
            sort($states);
            $rows[] = array('key' => $g['key'], 'kind' => $g['kind'], 'id' => $g['id'], 'name' => $g['name'],
                'states' => $states, 'sites' => $n_sites, 't' => $t, 'has' => $has, 'also' => (int) ($g['also'] ?? 0));
        }
        return $rows;
    }

    /** Sort rows by a total ($col: measure, f, f.<cat>, p, rate), worst first; ties by approved findings, then name. */
    function kop_irl_sort(array $rows, $col) {
        $val = function ($row) use ($col) {
            if ($col === 'rate') return kop_irl_rate($row);
            return (float) ($row['t'][$col] ?? 0);
        };
        usort($rows, function ($a, $b) use ($val) {
            $c = $val($b) <=> $val($a);
            if ($c) return $c;
            $c = ($b['t']['f'] ?? 0) <=> ($a['t']['f'] ?? 0);
            return $c ?: strcasecmp($a['name'], $b['name']);
        });
        return $rows;
    }

    /** Violations cited per report, where the row's states publish citations; null otherwise. */
    function kop_irl_rate(array $row) {
        if (empty($row['has']['citations']) || empty($row['t']['reports'])) return null;
        return round(($row['t']['citations'] ?? 0) / $row['t']['reports'], 2);
    }

    // -----------------------------------------------------------------------
    // Cache
    // -----------------------------------------------------------------------

    /** A hash of everything the rollup reads. */
    function kop_irl_fingerprint(PDO $pdo, $prefix = 'wpdl_', array $links = array()) {
        $parts = array('v' . kop_irl_version());
        $probes = array(
            'inspection_report_counts' => 'SELECT COUNT(*), MAX(counted_at) FROM inspection_report_counts',
            'inspection_highlights'    => 'SELECT COUNT(*), MAX(updated_at) FROM inspection_highlights',
            'inspection_facilities'    => 'SELECT COUNT(*), MAX(id) FROM inspection_facilities',
            'facilities_v2'            => 'SELECT COUNT(*), MAX(updated_at) FROM facilities_v2',
            $prefix . 'kop_operator_facilities' => "SELECT COUNT(*), SUM(operator_id * 7 + facility_id) FROM {$prefix}kop_operator_facilities",
            $prefix . 'kop_operators'  => "SELECT COUNT(*), MAX(updated_at) FROM {$prefix}kop_operators",
        );
        foreach ($probes as $table => $sql) {
            try {
                $parts[] = $table . '=' . implode(',', (array) $pdo->query($sql)->fetch(PDO::FETCH_NUM));
            } catch (Throwable $e) {
                $parts[] = $table . '=-';
            }
        }
        $parts[] = md5(json_encode($links));
        return substr(md5(implode(';', $parts)), 0, 16);
    }

    function kop_irl_cache_dir() {
        $uploads = function_exists('wp_upload_dir') ? wp_upload_dir(null, false) : array();
        $base = !empty($uploads['basedir']) ? $uploads['basedir'] : (defined('WP_CONTENT_DIR') ? WP_CONTENT_DIR . '/uploads' : sys_get_temp_dir());
        return rtrim($base, '/\\') . '/kop-cache/inspection-rollup';
    }

    /** The kop_inspection_links option's links, facility id => [site ids]. */
    function kop_irl_links() {
        if (function_exists('kop_inspection_links_get')) {
            $stored = kop_inspection_links_get();
            return is_array($stored['links'] ?? null) ? $stored['links'] : array();
        }
        return array();
    }

    /** The rollup, from the file cache while nothing it reads has changed. */
    function kop_irl_get(PDO $pdo, $prefix = 'wpdl_', $force = false) {
        $links = kop_irl_links();
        $fp = kop_irl_fingerprint($pdo, $prefix, $links);
        $dir = kop_irl_cache_dir();
        $file = $dir . '/rollup-' . $fp . '.json';
        if (!$force && is_file($file)) {
            $data = json_decode((string) file_get_contents($file), true);
            if (is_array($data) && ($data['version'] ?? 0) === kop_irl_version()) return $data;
        }
        $data = kop_irl_build($pdo, $prefix, $links);
        $data['fingerprint'] = $fp;
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        if (is_dir($dir)) {
            foreach ((array) glob($dir . '/rollup-*.json') as $old) @unlink($old);
            @file_put_contents($file, json_encode($data));
        }
        return $data;
    }
}

// ---------------------------------------------------------------------------
// WordPress: hourly counting, admin screen
// ---------------------------------------------------------------------------

if (function_exists('add_action') && !function_exists('kop_irl_cron')) {

    add_action('init', function () {
        if (function_exists('wp_next_scheduled') && !wp_next_scheduled('kop_irl_count_hourly')) {
            wp_schedule_event(time() + 900, 'hourly', 'kop_irl_count_hourly');
        }
    });

    add_action('kop_irl_count_hourly', 'kop_irl_cron');

    /** Count the next reports; once all are counted, warm the rollup cache. */
    function kop_irl_cron() {
        if (get_transient('kop_irl_count_lock')) return;
        set_transient('kop_irl_count_lock', 1, 10 * MINUTE_IN_SECONDS);
        try {
            $pdo = function_exists('kop_seed_pdo') ? kop_seed_pdo() : null;
            if (!$pdo) return;
            global $wpdb;
            $result = kop_irl_count_batch($pdo, 6000, 25);
            if ($result['remaining'] === 0) kop_irl_get($pdo, $wpdb->prefix);
        } catch (Throwable $e) {
            error_log('kop inspection rollup: ' . $e->getMessage());
        } finally {
            delete_transient('kop_irl_count_lock');
        }
    }

    add_action('admin_menu', function () {
        if (!function_exists('kop_tools_parent_slug')) return;
        add_submenu_page(kop_tools_parent_slug(), 'Inspection Rankings', 'Inspection Rankings', 'manage_options',
            'kop-inspection-rankings', 'kop_render_inspection_rankings_page');
    }, 21);

    /** Link to the row's page: facility, company, or the state's report page. */
    function kop_irl_row_link(array $row, array $rollup) {
        $name = esc_html($row['name'] !== '' ? $row['name'] : '(no name)');
        $url = '';
        if ($row['kind'] === 'record' && function_exists('kop_facility_page_url')) $url = kop_facility_page_url((int) $row['id']);
        if ($row['kind'] === 'operator' && function_exists('kop_operator_page_url')) $url = kop_operator_page_url((int) $row['id']);
        if ($row['kind'] === 'state') {
            $names = function_exists('kop_state_abbrev_to_name') ? kop_state_abbrev_to_name() : array();
            $map = function_exists('kop_state_inspection_page_map') ? kop_state_inspection_page_map() : array();
            $sname = $names[$row['id']] ?? $row['id'];
            if (isset($map[$sname])) $url = home_url('/' . $map[$sname] . '/');
            $name = esc_html($sname);
        }
        $html = $url ? '<a href="' . esc_url($url) . '" target="_blank" rel="noopener"><strong>' . $name . '</strong></a>' : '<strong>' . $name . '</strong>';
        if ($row['kind'] === 'site') $html .= ' <span class="kop-irl-muted">licensed name, no facility record</span>';
        if ($row['kind'] === 'record' && $row['sites'] > 1) $html .= ' <span class="kop-irl-muted">' . (int) $row['sites'] . ' licensed sites</span>';
        if (!empty($row['also'])) $html .= ' <span class="kop-irl-muted">and ' . (int) $row['also'] . ' other ' . ($row['also'] === 1 ? 'record' : 'records') . ' with the same licensed sites</span>';
        return $html;
    }

    function kop_render_inspection_rankings_page() {
        if (!current_user_can('manage_options')) return;
        global $wpdb;
        $pdo = function_exists('kop_seed_pdo') ? kop_seed_pdo() : null;
        if (!$pdo) {
            echo '<div class="wrap"><h1>Inspection Rankings</h1><p>No database connection.</p></div>';
            return;
        }
        $notice = '';
        $counted = false;
        if (!empty($_POST['kop_irl_count'])) {
            check_admin_referer('kop_irl');
            try {
                $r = kop_irl_count_batch($pdo, 8000, 20);
                $notice = 'Counted ' . number_format($r['counted']) . ' reports; ' . number_format($r['remaining']) . ' left.';
                $counted = true;
            } catch (Throwable $e) {
                $notice = 'Counting stopped: ' . $e->getMessage();
            }
        }
        kop_irl_render_page($pdo, $wpdb->prefix, $_GET, $notice, $counted);
    }

    /** The rankings screen for the filters in $get (by, st, since, sort, pending). */
    function kop_irl_render_page(PDO $pdo, $prefix, array $get, $notice = '', $rebuild = false) {
        echo '<div class="wrap"><h1>Inspection Rankings</h1>';
        kop_irl_ensure_table($pdo);
        $remaining = kop_irl_remaining($pdo);
        $total = (int) $pdo->query('SELECT COUNT(*) FROM inspection_reports')->fetchColumn();
        $rollup = kop_irl_get($pdo, $prefix, $rebuild);

        $by = in_array($get['by'] ?? '', array('operator', 'record', 'state'), true) ? $get['by'] : 'operator';
        $state = preg_match('/^[A-Z]{2}$/', (string) ($get['st'] ?? '')) ? $get['st'] : '';
        $since = (int) ($get['since'] ?? 0);
        $sort = preg_replace('/[^a-z_.]/', '', (string) ($get['sort'] ?? 'f'));
        $pending = ($get['pending'] ?? '1') === '1';

        $measures = kop_irl_measures();
        $findings = kop_irl_finding_columns();
        $sort_options = array('f' => 'Approved serious findings');
        foreach ($findings as $k => $c) $sort_options['f.' . $k] = $c['label'];
        foreach ($measures as $k => $c) $sort_options[$k] = $c['label'];
        $sort_options['rate'] = 'Violations per report';
        $sort_options['p'] = 'Pending findings';
        if (!isset($sort_options[$sort])) $sort = 'f';

        $rows = kop_irl_sort(kop_irl_rows($rollup, $by, $state, $since), $sort);
        $rows = array_slice($rows, 0, 150);

        ?>
        <style>
            .kop-irl-muted { color: #4A5568; font-weight: normal; font-size: 12px; }
            .kop-irl-table td.num, .kop-irl-table th.num { text-align: right; white-space: nowrap; }
            .kop-irl-table .pend { color: #4A5568; font-size: 11px; display: block; }
            .kop-irl-wrap { overflow-x: auto; }
            .kop-irl-table th { font-size: 12px; vertical-align: bottom; }
            .kop-irl-filters select { margin-right: 8px; }
        </style>
        <?php
        if ($notice !== '') echo '<div class="notice notice-info"><p>' . esc_html($notice) . '</p></div>';

        echo '<p>What the state inspection reports add up to. <strong>Approved findings</strong> are serious findings you approved at '
            . '<a href="' . esc_url(get_stylesheet_directory_uri() . '/api/review-inspection-highlights.php') . '">Inspection Highlights</a>; '
            . 'the small grey number under each is how many more are waiting for review (shown here only, never on the site). '
            . 'The other columns are the states\' own verdicts; a dash means the state does not publish that number. '
            . 'States publish very different amounts, so compare facilities within one state.</p>';

        $pct = $total ? round(100 * ($total - $remaining) / $total) : 100;
        echo '<p>Reports counted: <strong>' . number_format($total - $remaining) . '</strong> of ' . number_format($total) . ' (' . $pct . '%). ';
        if ($remaining) echo 'The rest are counted hourly, or now: ';
        echo '</p>';
        if ($remaining) {
            echo '<form method="post" style="margin-bottom:1em">';
            wp_nonce_field('kop_irl');
            echo '<button type="submit" class="button" name="kop_irl_count" value="1">Count the next reports now</button> <span class="kop-irl-muted">about 20 seconds</span></form>';
        }

        $states = array();
        foreach ($rollup['sites'] as $s) $states[$s['st']] = true;
        ksort($states);
        echo '<form method="get" class="kop-irl-filters"><input type="hidden" name="page" value="kop-inspection-rankings">';
        echo '<label>View <select name="by">';
        foreach (array('operator' => 'Parent companies', 'record' => 'Facilities', 'state' => 'States') as $k => $label) {
            echo '<option value="' . esc_attr($k) . '"' . selected($by, $k, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select></label><label>State <select name="st"><option value="">All states</option>';
        foreach (array_keys($states) as $st) echo '<option' . selected($state, $st, false) . '>' . esc_html($st) . '</option>';
        echo '</select></label><label>Years <select name="since"><option value="0">All years</option>';
        $year = (int) date('Y');
        foreach (array(1, 3, 5, 10) as $n) {
            $from = $year - $n + 1;
            echo '<option value="' . $from . '"' . selected($since, $from, false) . '>' . ($n === 1 ? 'This year' : 'Last ' . $n . ' years') . ' (' . $from . '+)</option>';
        }
        echo '</select></label><label>Worst by <select name="sort">';
        foreach ($sort_options as $k => $label) echo '<option value="' . esc_attr($k) . '"' . selected($sort, $k, false) . '>' . esc_html($label) . '</option>';
        // The hidden 0 comes first: a ticked box sends a later 1, which PHP keeps.
        echo '</select></label><input type="hidden" name="pending" value="0"><label><input type="checkbox" name="pending" value="1"' . checked($pending, true, false) . '> Show pending</label> ';
        echo '<button type="submit" class="button button-primary">Show</button></form>';
        if ($by === 'record' && $state === '') {
            echo '<p class="kop-irl-muted">Tip: pick a state. Facilities in different states are inspected under different rules.</p>';
        }

        echo '<div class="kop-irl-wrap"><table class="widefat striped kop-irl-table"><thead><tr><th>#</th><th>' . ($by === 'state' ? 'State' : 'Name') . '</th>';
        if ($by === 'operator') echo '<th>States</th><th class="num">Sites</th>';
        if ($by === 'record') echo '<th>State</th>';
        echo '<th class="num" title="Serious findings you approved, every category">Approved findings</th>';
        foreach ($findings as $k => $c) echo '<th class="num" title="' . esc_attr($c['help']) . '">' . esc_html($c['label']) . '</th>';
        foreach ($measures as $k => $c) echo '<th class="num" title="' . esc_attr($c['help']) . '">' . esc_html($c['label']) . '</th>';
        echo '<th class="num" title="Violations cited per report, where the state publishes citations">Per report</th></tr></thead><tbody>';

        if (!$rows) {
            echo '<tr><td colspan="30">Nothing counted for this choice yet.</td></tr>';
        }
        $cell = function ($n, $pend = null) use ($pending) {
            $html = $n ? number_format($n) : '<span class="kop-irl-muted">0</span>';
            if ($pending && $pend) $html .= '<span class="pend" title="Waiting for review">+' . number_format($pend) . ' pending</span>';
            return '<td class="num">' . $html . '</td>';
        };
        foreach ($rows as $i => $row) {
            $t = $row['t'];
            echo '<tr><td>' . ($i + 1) . '</td><td>' . kop_irl_row_link($row, $rollup) . '</td>';
            if ($by === 'operator') echo '<td>' . esc_html(implode(', ', $row['states'])) . '</td><td class="num">' . (int) $row['sites'] . '</td>';
            if ($by === 'record') echo '<td>' . esc_html(implode(', ', $row['states'])) . '</td>';
            echo $cell($t['f'] ?? 0, $t['p'] ?? 0);
            foreach ($findings as $k => $c) echo $cell($t['f.' . $k] ?? 0, $t['p.' . $k] ?? 0);
            foreach ($measures as $k => $c) {
                echo empty($row['has'][$k]) ? '<td class="num kop-irl-muted" title="Not published by this state">&ndash;</td>' : $cell($t[$k] ?? 0);
            }
            $rate = kop_irl_rate($row);
            echo '<td class="num">' . ($rate === null ? '<span class="kop-irl-muted">&ndash;</span>' : esc_html(number_format($rate, 2))) . '</td></tr>';
        }
        echo '</tbody></table></div>';

        echo '<h2>Where each state\'s numbers come from</h2><table class="widefat striped" style="max-width:900px"><thead><tr><th>State</th><th>Measure</th><th>Source</th></tr></thead><tbody>';
        foreach (kop_irl_state_measures() as $st => $ms) {
            foreach ($ms as $m => $src) {
                echo '<tr><td>' . esc_html($st) . '</td><td>' . esc_html($measures[$m]['label']) . '</td><td>' . esc_html($src) . '</td></tr>';
            }
        }
        echo '<tr><td colspan="3">Every other state: reports only.</td></tr></tbody></table>';
        echo '<p class="kop-irl-muted">Rollup ' . esc_html($rollup['fingerprint'] ?? '') . ', built ' . esc_html(date('Y-m-d H:i', (int) $rollup['built'])) . ' UTC.</p></div>';
    }
}
