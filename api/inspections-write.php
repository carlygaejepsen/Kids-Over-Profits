<?php
/**
 * Inspections Write API
 * Receives scraped facility/report data from Python scrapers and upserts into MySQL.
 * Shared across all states via the `state` field.
 */
require_once __DIR__ . '/config.php';

header('Content-Type: application/json');

// --- Auth ---
$API_KEY = getenv('INSPECTIONS_API_KEY') ?: 'CHANGE_ME';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'POST required']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON body']);
    exit;
}

if (($input['api_key'] ?? '') !== $API_KEY) {
    http_response_code(403);
    echo json_encode(['error' => 'Invalid API key']);
    exit;
}

$state = strtoupper(trim($input['state'] ?? ''));
if (!$state) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing state']);
    exit;
}

$timestamp = $input['scraped_timestamp'] ?? date('c');
$facilities = $input['facilities'] ?? [];

// --- Ensure tables exist ---
$pdo->exec("
    CREATE TABLE IF NOT EXISTS inspection_facilities (
        id INT AUTO_INCREMENT PRIMARY KEY,
        state VARCHAR(10) NOT NULL,
        facility_name VARCHAR(500) NOT NULL,
        full_address TEXT,
        phone VARCHAR(50),
        program_category VARCHAR(255),
        program_name VARCHAR(500),
        executive_director VARCHAR(255),
        bed_capacity VARCHAR(50),
        license_exp_date VARCHAR(50),
        relicense_visit_date VARCHAR(50),
        action VARCHAR(255),
        scraped_timestamp VARCHAR(50),
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_facility (state, facility_name(200), program_name(200))
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

$pdo->exec("
    CREATE TABLE IF NOT EXISTS inspection_reports (
        id INT AUTO_INCREMENT PRIMARY KEY,
        facility_id INT NOT NULL,
        report_id VARCHAR(100) NOT NULL,
        report_date VARCHAR(50),
        report_url TEXT,
        raw_content LONGTEXT,
        content_length INT DEFAULT 0,
        is_structured TINYINT DEFAULT 0,
        summary TEXT,
        categories_json LONGTEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (facility_id) REFERENCES inspection_facilities(id) ON DELETE CASCADE,
        UNIQUE KEY uq_report (facility_id, report_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

// --- Upsert data ---
$facilityStmt = $pdo->prepare("
    INSERT INTO inspection_facilities
        (state, facility_name, full_address, phone, program_category,
         program_name, executive_director, bed_capacity,
         license_exp_date, relicense_visit_date, action, scraped_timestamp)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ON DUPLICATE KEY UPDATE
        full_address = VALUES(full_address),
        phone = VALUES(phone),
        program_category = VALUES(program_category),
        executive_director = VALUES(executive_director),
        bed_capacity = VALUES(bed_capacity),
        license_exp_date = VALUES(license_exp_date),
        relicense_visit_date = VALUES(relicense_visit_date),
        action = VALUES(action),
        scraped_timestamp = VALUES(scraped_timestamp)
");

$reportStmt = $pdo->prepare("
    INSERT INTO inspection_reports
        (facility_id, report_id, report_date, report_url,
         raw_content, content_length, is_structured, summary, categories_json)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
    ON DUPLICATE KEY UPDATE
        report_date = VALUES(report_date),
        report_url = VALUES(report_url),
        raw_content = VALUES(raw_content),
        content_length = VALUES(content_length),
        is_structured = VALUES(is_structured),
        summary = VALUES(summary),
        categories_json = VALUES(categories_json)
");

$lookupStmt = $pdo->prepare("
    SELECT id FROM inspection_facilities
    WHERE state = ? AND facility_name = ? AND program_name = ?
");

$facilitiesSaved = 0;
$reportsSaved = 0;

try {
    $pdo->beginTransaction();

    // Optional full replace: clear existing data for this state before reinserting
    if (!empty($input['replace'])) {
        $pdo->prepare("DELETE FROM inspection_facilities WHERE state = ?")->execute([$state]);
    }

    foreach ($facilities as $facility) {
        $info = $facility['facility_info'] ?? [];

        $facilityStmt->execute([
            $state,
            $info['facility_name'] ?? '',
            $info['full_address'] ?? '',
            $info['phone'] ?? '',
            $info['program_category'] ?? '',
            $info['program_name'] ?? '',
            $info['executive_director'] ?? '',
            $info['bed_capacity'] ?? '',
            $info['license_exp_date'] ?? '',
            $info['relicense_visit_date'] ?? '',
            $info['action'] ?? '',
            $timestamp,
        ]);

        // Get the facility ID (inserted or existing)
        $facilityId = $pdo->lastInsertId();
        if (!$facilityId) {
            $lookupStmt->execute([$state, $info['facility_name'] ?? '', $info['program_name'] ?? '']);
            $row = $lookupStmt->fetch();
            $facilityId = $row['id'] ?? null;
        }

        if (!$facilityId) continue;
        $facilitiesSaved++;

        foreach ($facility['reports'] ?? [] as $report) {
            $categories = $report['categories'] ?? [];
            $isStructured = (!empty($report['is_structured']) || !empty($categories['is_structured'])) ? 1 : 0;

            $reportStmt->execute([
                $facilityId,
                $report['report_id'] ?? '',
                $report['report_date'] ?? '',
                $report['report_url'] ?? '',
                $report['raw_content'] ?? '',
                $report['content_length'] ?? 0,
                $isStructured,
                $report['summary'] ?? '',
                json_encode($categories, JSON_UNESCAPED_UNICODE),
            ]);
            $reportsSaved++;
        }
    }

    $pdo->commit();

    echo json_encode([
        'success' => true,
        'state' => $state,
        'facilities_saved' => $facilitiesSaved,
        'reports_saved' => $reportsSaved,
    ]);

} catch (Exception $e) {
    $pdo->rollBack();
    http_response_code(500);
    error_log("inspections-write error: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'error' => 'Database error: ' . $e->getMessage(),
    ]);
}
