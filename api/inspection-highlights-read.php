<?php
/**
 * Approved severe findings for one state, for the flags in the state trackers
 * (js/inspections/severe-flags.js). Public and read-only: everything here is
 * already on the Severe Reports page. Pending and rejected candidates, scores
 * below the severe line and review notes never leave the database.
 *
 * Usage: inspection-highlights-read.php?state=TX
 *
 * Each finding carries a "needle": the opening of the quoted text, lower-cased
 * with the spaces removed. The trackers read static JSON whose report ids do
 * not match the database's, so a report is recognised by the state's own
 * words instead.
 */
require_once __DIR__ . '/config.php';
require_once dirname(__DIR__) . '/inc/inspection-highlights.php';

header('Content-Type: application/json');
header('Cache-Control: public, max-age=300');

$state = strtoupper(preg_replace('/[^A-Za-z]/', '', (string) ($_GET['state'] ?? '')));
if ($state === '') {
    http_response_code(400);
    echo json_encode(array('error' => 'Missing ?state= parameter'));
    exit;
}

$findings = array();
try {
    if ($pdo && kop_ih_table_exists($pdo, 'inspection_highlights')) {
        $categories = kop_ih_categories();
        list($sql, $params) = kop_ih_severe_query($state);
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $findings[] = array(
                'id'       => (int) $row['id'],
                'facility' => (string) $row['facility_name'],
                'date'     => (string) $row['finding_date'],
                'label'    => $categories[$row['category']]['label'] ?? 'Severe finding',
                'needle'   => kop_ih_flag_needle($row['excerpt']),
            );
        }
    }
} catch (Exception $e) {
    // An older table, or none: the trackers simply show no flags.
    error_log('inspection-highlights-read: ' . $e->getMessage());
    $findings = array();
}

echo json_encode(array('state' => $state, 'findings' => $findings), JSON_UNESCAPED_UNICODE);
