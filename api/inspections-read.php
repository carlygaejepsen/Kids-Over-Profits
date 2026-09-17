<?php
/**
 * Inspections Read API
 * Returns facility + report data from MySQL in the same JSON format
 * the frontend JS already expects.
 *
 * Usage: inspections-read.php?state=CT
 */
require_once __DIR__ . '/config.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

$state = strtoupper(trim($_GET['state'] ?? ''));
if (!$state) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing ?state= parameter']);
    exit;
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
            $reports[] = [
                'report_id'      => $rep['report_id'],
                'report_date'    => $rep['report_date'],
                'report_url'     => $rep['report_url'],
                'raw_content'    => $rep['raw_content'],
                'content_length' => (int) $rep['content_length'],
                'is_structured'  => (bool) $rep['is_structured'],
                'summary'        => $rep['summary'],
                'categories'     => $categories,
            ];
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

    echo json_encode([
        'total_facilities' => count($facilities),
        'source_state'     => $state,
        'scraped_timestamp' => $latestTimestamp,
        'scraping_notes'   => [
            'total_reports' => $totalReports,
        ],
        'facilities' => $facilities,
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    http_response_code(500);
    error_log("inspections-read error: " . $e->getMessage());
    echo json_encode(['error' => 'Database error']);
}
