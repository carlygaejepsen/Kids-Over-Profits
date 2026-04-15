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

    // Group reports by facility_id
    $reportsByFacility = [];
    foreach ($reportRows as $row) {
        $reportsByFacility[$row['facility_id']][] = $row;
    }

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
