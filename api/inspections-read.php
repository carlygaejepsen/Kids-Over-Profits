<?php
/**
 * Inspections Read API
 * Returns facility + report data from MySQL in the same JSON format
 * the frontend JS already expects.
 *
 * Usage: inspections-read.php?state=CT
 *
 * ?lite=1 leaves each report's raw_content out and sends instead row_id,
 * has_text and, for the states whose page reads the text at load
 * (kop_its_states()), text_signals: what the page would have read from it
 * (api/lib-inspection-text-signals.php). The FL and NC lists drop from about
 * 100 MB to about 1 MB. The lite response is cached on disk until the
 * state's reports change.
 * ?state=FL&text=<row_id> returns one report's raw_content, for a report
 * opened on a lite page.
 */
require_once __DIR__ . '/config.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

$state = strtoupper(trim($_GET['state'] ?? ''));
if (!$state || !preg_match('/^[A-Z]{2}$/', $state)) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing ?state= parameter']);
    exit;
}
$lite = !empty($_GET['lite']);
$textId = isset($_GET['text']) ? (int) $_GET['text'] : 0;

// One report's text, for a report opened on a lite page.
if ($textId > 0) {
    try {
        $stmt = $pdo->prepare("
            SELECT r.raw_content FROM inspection_reports r
            JOIN inspection_facilities f ON f.id = r.facility_id
            WHERE r.id = ? AND f.state = ?
        ");
        $stmt->execute([$textId, $state]);
        $raw = $stmt->fetchColumn();
        if ($raw === false) {
            http_response_code(404);
            echo json_encode(['error' => 'Report not found']);
            exit;
        }
        header('Cache-Control: public, max-age=86400');
        echo json_encode(['raw_content' => (string) $raw], JSON_UNESCAPED_UNICODE);
    } catch (Exception $e) {
        http_response_code(500);
        error_log("inspections-read text error: " . $e->getMessage());
        echo json_encode(['error' => 'Database error']);
    }
    exit;
}

$liteCacheFile = '';
if ($lite) {
    require_once __DIR__ . '/lib-inspection-text-signals.php';
    try {
        // The cache key changes whenever a report or facility of the state is
        // added or updated, or the signal rules change.
        $stampStmt = $pdo->prepare("
            SELECT COUNT(*), MAX(r.id), MAX(r.updated_at), MAX(f.updated_at)
            FROM inspection_facilities f
            LEFT JOIN inspection_reports r ON r.facility_id = f.id
            WHERE f.state = ?
        ");
        $stampStmt->execute([$state]);
        $stamp = $stampStmt->fetch(PDO::FETCH_NUM);
        $cacheDir = dirname(__DIR__, 3) . '/uploads/kop-cache';
        if (is_dir($cacheDir) || @mkdir($cacheDir, 0755, true)) {
            $liteCacheFile = $cacheDir . '/inspections-' . $state . '-lite-'
                . md5(json_encode([$stamp, kop_its_version(), 1])) . '.json';
            if (is_readable($liteCacheFile)) {
                header('Cache-Control: public, max-age=600');
                readfile($liteCacheFile);
                exit;
            }
        }
    } catch (Exception $e) {
        error_log("inspections-read lite cache check failed: " . $e->getMessage());
        $liteCacheFile = '';
    }
}

try {
    // Fetch facilities for the requested state
    $facStmt = $pdo->prepare("
        SELECT * FROM inspection_facilities
        WHERE state = ?
        ORDER BY facility_name
    ");
    $facStmt->execute([$state]);
    $facilityRows = $facStmt->fetchAll();

    if (empty($facilityRows)) {
        echo json_encode([
            'total_facilities' => 0,
            'source_state' => $state,
            'facilities' => [],
        ]);
        exit;
    }

    // Fetch all reports for these facilities in one query
    $facilityIds = array_column($facilityRows, 'id');
    $placeholders = implode(',', array_fill(0, count($facilityIds), '?'));
    $repStmt = $pdo->prepare("
        SELECT * FROM inspection_reports
        WHERE facility_id IN ($placeholders)
        ORDER BY facility_id, report_date DESC
    ");
    $repStmt->execute($facilityIds);
    $reportRows = $repStmt->fetchAll();

    // Group reports by facility_id, dropping re-listings of one document.
    // State listings publish the same PDF more than once (NC under two upload
    // dates, FL DJJ under two download ids, OR under two SharePoint ids: 51,
    // 5 and 7 rows on 2026-09-17), and each copy arrives as its own row. A
    // row whose date, summary, extracted text and categories match a row
    // already kept for the facility is the same document, once the keys that
    // only name the copy (its URL or file id) are set aside. Keys that name a
    // distinct official record (inspection_number, event_id, a case number)
    // still count, so two records that happen to share text both stay, and
    // rows with no text are never merged.
    $copyOnlyKeys = [
        'id' => 1, 'report_id' => 1, 'document_id' => 1, 'pdf_url' => 1,
        'report_url' => 1, 'source_url' => 1, 'sod_url' => 1, 'doc_page_url' => 1,
        'sharepoint_unique_id' => 1, 'file_name' => 1, 'filename' => 1,
    ];
    $reportsByFacility = [];
    $seenReports = [];
    foreach ($reportRows as $row) {
        $text = (string) $row['raw_content'];
        if (trim($text) !== '') {
            $categories = json_decode((string) $row['categories_json'], true);
            $categories = is_array($categories) ? array_diff_key($categories, $copyOnlyKeys) : [];
            ksort($categories);
            $fingerprint = $row['facility_id'] . "\0" . md5(
                (string) $row['report_date'] . "\0"
                . (string) $row['summary'] . "\0"
                . json_encode($categories) . "\0"
                . $text
            );
            if (isset($seenReports[$fingerprint])) {
                continue;
            }
            $seenReports[$fingerprint] = true;
        }
        $reportsByFacility[$row['facility_id']][] = $row;
    }
    unset($seenReports);

    // Build the output in the same shape the frontend expects
    $facilities = [];
    $totalReports = 0;

    foreach ($facilityRows as $fac) {
        $facilityInfo = [
            'facility_name'       => $fac['facility_name'],
            'full_address'        => $fac['full_address'],
            'phone'               => $fac['phone'],
            'program_category'    => $fac['program_category'],
            'program_name'        => $fac['program_name'],
            'executive_director'  => $fac['executive_director'],
            'bed_capacity'        => $fac['bed_capacity'],
            'license_exp_date'    => $fac['license_exp_date'],
            'relicense_visit_date'=> $fac['relicense_visit_date'],
            'action'              => $fac['action'],
        ];

        $reports = [];
        foreach ($reportsByFacility[$fac['id']] ?? [] as $rep) {
            $categories = json_decode($rep['categories_json'], true) ?: [];
            $out = [
                'report_id'      => $rep['report_id'],
                'report_date'    => $rep['report_date'],
                'report_url'     => $rep['report_url'],
                'raw_content'    => $rep['raw_content'],
                'content_length' => (int) $rep['content_length'],
                'is_structured'  => (bool) $rep['is_structured'],
                'summary'        => $rep['summary'],
                'categories'     => $categories,
            ];
            if ($lite) {
                $raw = (string) $rep['raw_content'];
                unset($out['raw_content']);
                $out['row_id'] = (int) $rep['id'];
                $out['has_text'] = kop_its_trim($raw) !== '';
                $out['text_signals'] = in_array($state, kop_its_states(), true)
                    ? kop_inspection_text_signals($state, $raw)
                    : null;
            }
            $reports[] = $out;
            $totalReports++;
        }

        $facilities[] = [
            'facility_info' => $facilityInfo,
            'reports'       => $reports,
        ];
    }

    // Get the most recent scraped timestamp
    $latestTimestamp = '';
    if (!empty($facilityRows)) {
        $timestamps = array_filter(array_column($facilityRows, 'scraped_timestamp'));
        if (!empty($timestamps)) {
            $latestTimestamp = max($timestamps);
        } else {
            // Fall back to the most recent updated_at from the DB
            $updatedAts = array_filter(array_column($facilityRows, 'updated_at'));
            if (!empty($updatedAts)) {
                $latestTimestamp = max($updatedAts);
            }
        }
    }

    $json = json_encode([
        'total_facilities' => count($facilities),
        'source_state'     => $state,
        'scraped_timestamp' => $latestTimestamp,
        'scraping_notes'   => [
            'total_reports' => $totalReports,
        ],
        'facilities' => $facilities,
    ], JSON_UNESCAPED_UNICODE);

    if ($lite && $liteCacheFile !== '' && $json !== false) {
        // Write beside, then rename, so a reader never sees half a file; drop
        // the state's older lite files.
        $tmp = $liteCacheFile . '.' . getmypid() . '.tmp';
        if (@file_put_contents($tmp, $json) !== false && @rename($tmp, $liteCacheFile)) {
            foreach (glob(dirname($liteCacheFile) . '/inspections-' . $state . '-lite-*.json') ?: [] as $old) {
                if ($old !== $liteCacheFile) @unlink($old);
            }
        } else {
            @unlink($tmp);
        }
    }
    if ($lite) {
        header('Cache-Control: public, max-age=600');
    }
    echo $json;

} catch (Exception $e) {
    http_response_code(500);
    error_log("inspections-read error: " . $e->getMessage());
    echo json_encode(['error' => 'Database error']);
}
