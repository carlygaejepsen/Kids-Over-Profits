<?php
/**
 * Admin-only save/list endpoint for volunteer_projects.
 *
 * GET  ?id=N                        -> single record
 * GET  ?status=open&type=research   -> filtered list
 * POST JSON body                    -> create or update (id present = update)
 * POST ?delete=1 + JSON {id:N}      -> delete
 *
 * Writes require manage_options; published records are readable publicly via GET.
 */

header('Content-Type: application/json');
require_once __DIR__ . '/config.php';

if (!defined('ABSPATH')) {
    $cur = __DIR__;
    for ($i = 0; $i < 6; $i++) {
        $cur = dirname($cur);
        if (file_exists($cur . '/wp-load.php')) { require_once $cur . '/wp-load.php'; break; }
    }
}

$is_admin = function_exists('current_user_can') && current_user_can('edit_posts');

$json_fields = ['skills_needed', 'facilities_related'];

function vol_decode_json_fields(array $row, array $fields): array {
    foreach ($fields as $f) {
        if (isset($row[$f]) && is_string($row[$f])) {
            $decoded = json_decode($row[$f], true);
            $row[$f] = is_array($decoded) ? $decoded : [];
        }
    }
    return $row;
}

function vol_normalize_array($v): array {
    if (is_array($v)) return array_values(array_filter(array_map('trim', $v), fn($x) => $x !== ''));
    if (is_string($v) && trim($v) !== '') return array_values(array_filter(array_map('trim', preg_split('/[\r\n,]+/', $v))));
    return [];
}

try {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        if (isset($_GET['id'])) {
            $id = (int) $_GET['id'];
            $stmt = $pdo->prepare('SELECT * FROM volunteer_projects WHERE id = ?');
            $stmt->execute([$id]);
            $row = $stmt->fetch();
            if (!$row) { http_response_code(404); echo json_encode(['success' => false, 'error' => 'Not found']); exit; }
            if (!$is_admin && $row['publication_status'] !== 'published') {
                http_response_code(403); echo json_encode(['success' => false, 'error' => 'Not published']); exit;
            }
            echo json_encode(['success' => true, 'record' => vol_decode_json_fields($row, $json_fields)]);
            exit;
        }

        $where   = $is_admin ? [] : ["publication_status = 'published'"];
        $params  = [];
        if (!empty($_GET['status'])) { $where[] = 'status = ?'; $params[] = $_GET['status']; }
        if (!empty($_GET['type']))   { $where[] = 'project_type = ?'; $params[] = $_GET['type']; }
        if (!empty($_GET['jurisdiction'])) { $where[] = 'jurisdiction = ?'; $params[] = $_GET['jurisdiction']; }
        if (!$is_admin && !empty($_GET['publication_status'])) { /* ignore */ }
        elseif (!empty($_GET['publication_status'])) { $where[] = 'publication_status = ?'; $params[] = $_GET['publication_status']; }

        $sql = 'SELECT * FROM volunteer_projects'
             . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
             . ' ORDER BY FIELD(status,"open","filled","completed","archived"), title ASC'
             . ' LIMIT 200';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = array_map(fn($r) => vol_decode_json_fields($r, $json_fields), $stmt->fetchAll());
        echo json_encode(['success' => true, 'records' => $rows, 'count' => count($rows)]);
        exit;
    }

    if (!$is_admin) { http_response_code(403); echo json_encode(['success' => false, 'error' => 'Unauthorized']); exit; }

    $body = json_decode(file_get_contents('php://input'), true) ?: [];

    // Delete
    if (!empty($_GET['delete']) || !empty($body['_delete'])) {
        $id = (int) ($body['id'] ?? 0);
        if (!$id) { http_response_code(400); echo json_encode(['success' => false, 'error' => 'id required']); exit; }
        $stmt = $pdo->prepare('DELETE FROM volunteer_projects WHERE id = ?');
        $stmt->execute([$id]);
        echo json_encode(['success' => true, 'deleted' => $id]);
        exit;
    }

    $title              = trim($body['title'] ?? '');
    $project_type       = $body['project_type'] ?? 'other';
    $status             = $body['status'] ?? 'open';
    $description        = trim($body['description'] ?? '');
    $contact_email      = trim($body['contact_email'] ?? '');
    $signup_url         = trim($body['signup_url'] ?? '');
    $jurisdiction       = trim($body['jurisdiction'] ?? '');
    $publication_status = $body['publication_status'] ?? 'draft';
    $reviewer_notes     = trim($body['reviewer_notes'] ?? '');
    $skills_needed      = json_encode(vol_normalize_array($body['skills_needed'] ?? []));
    $facilities_related = json_encode(vol_normalize_array($body['facilities_related'] ?? []));

    if (!$title) { http_response_code(400); echo json_encode(['success' => false, 'error' => 'title required']); exit; }

    $valid_types    = ['research','data_entry','advocacy','tech','outreach','other'];
    $valid_statuses = ['open','filled','completed','archived'];
    $valid_pub      = ['draft','published','archived'];
    if (!in_array($project_type, $valid_types, true))        $project_type = 'other';
    if (!in_array($status, $valid_statuses, true))           $status = 'open';
    if (!in_array($publication_status, $valid_pub, true))    $publication_status = 'draft';

    $id = (int) ($body['id'] ?? 0);

    if ($id) {
        $stmt = $pdo->prepare(
            'UPDATE volunteer_projects SET title=?, project_type=?, status=?, description=?,
             skills_needed=?, contact_email=?, signup_url=?, jurisdiction=?,
             facilities_related=?, publication_status=?, reviewer_notes=?
             WHERE id=?'
        );
        $stmt->execute([$title, $project_type, $status, $description,
                        $skills_needed, $contact_email, $signup_url, $jurisdiction,
                        $facilities_related, $publication_status, $reviewer_notes, $id]);
        echo json_encode(['success' => true, 'id' => $id, 'action' => 'updated']);
    } else {
        $stmt = $pdo->prepare(
            'INSERT INTO volunteer_projects
             (title, project_type, status, description, skills_needed, contact_email,
              signup_url, jurisdiction, facilities_related, publication_status, reviewer_notes)
             VALUES (?,?,?,?,?,?,?,?,?,?,?)'
        );
        $stmt->execute([$title, $project_type, $status, $description,
                        $skills_needed, $contact_email, $signup_url, $jurisdiction,
                        $facilities_related, $publication_status, $reviewer_notes]);
        echo json_encode(['success' => true, 'id' => (int) $pdo->lastInsertId(), 'action' => 'created']);
    }
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
