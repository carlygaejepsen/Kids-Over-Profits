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
 * words instead, or, where the document has a URL of its own, by "url": the
 * link the tracker shows as the official report (kop_ih_document_url).
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
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        // The document URL is in each report's stored fields.
        $urls = array();
        $report_ids = array_values(array_unique(array_map('intval', array_column($rows, 'report_id'))));
        // The deploy copies api/ before inc/; until the helper arrives, text matching alone.
        if ($report_ids && function_exists('kop_ih_document_url')) {
            $docs = $pdo->query('SELECT id, report_url, categories_json FROM inspection_reports WHERE id IN (' . implode(',', $report_ids) . ')');
            foreach ($docs->fetchAll(PDO::FETCH_ASSOC) as $doc) {
                $urls[(int) $doc['id']] = kop_ih_document_url($doc['report_url'], $doc['categories_json']);
            }
        }
        foreach ($rows as $row) {
            // Findings of one day are merged into one entry, a paragraph each
            // (kop_ih_merge_same_day), and may come from more than one report:
            // each paragraph gets a needle of its own, under the same id.
            $parts = preg_split('/\n\s*\n/u', trim((string) $row['excerpt']));
            foreach ($parts as $n => $part) {
                $finding = array(
                    'id'       => (int) $row['id'],
                    'facility' => (string) $row['facility_name'],
                    'date'     => (string) $row['finding_date'],
                    'label'    => $categories[$row['category']]['label'] ?? 'Severe finding',
                    'needle'   => kop_ih_flag_needle($part),
                );
                $url = $n === 0 ? ($urls[(int) $row['report_id']] ?? '') : '';
                if ($url !== '') $finding['url'] = $url;
                $findings[] = $finding;
            }
        }
    }
} catch (Exception $e) {
    // An older table, or none: the trackers simply show no flags.
    error_log('inspection-highlights-read: ' . $e->getMessage());
    $findings = array();
}

echo json_encode(array('state' => $state, 'findings' => $findings), JSON_UNESCAPED_UNICODE);
