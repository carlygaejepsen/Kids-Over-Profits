<?php
/**
 * Saved Form Values API
 * 
 * Endpoint to manage saved autocomplete values for form fields.
 * These are shared values like author names, publication names, facilities, etc.
 * 
 * GET /api/saved-values.php - Get saved values
 * POST /api/saved-values.php - Save new value
 * DELETE /api/saved-values.php - Remove value
 */

header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');

// Handle preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/config.php';

// config.php boots WordPress, so capability and nonce checks are available.
// Reads are public. Writes need the news-processor page nonce (sent by
// js/news-processor.js as X-KOP-Nonce) or an administrator; deletes are
// admin-only.
$kop_is_admin = function_exists('current_user_can') && current_user_can('manage_options');
$kop_nonce_ok = function_exists('wp_verify_nonce')
    && wp_verify_nonce((string) ($_SERVER['HTTP_X_KOP_NONCE'] ?? ''), 'news_processor_nonce');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$kop_is_admin && !$kop_nonce_ok) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Not authorized']);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'DELETE' && !$kop_is_admin) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Not authorized']);
    exit;
}

try {
    switch ($_SERVER['REQUEST_METHOD']) {
        case 'GET':
            // Get saved values
            $formType = $_GET['form'] ?? null; // 'wiki' or 'news'
            $category = $_GET['category'] ?? null;
            
            $where = [];
            $params = [];
            
            if ($formType && in_array($formType, ['wiki', 'news'])) {
                $where[] = "form_type = ?";
                $params[] = $formType;
            }
            
            if ($category) {
                $where[] = "category = ?";
                $params[] = $category;
            }
            
            $whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';
            
            $sql = "SELECT form_type, category, value, use_count 
                    FROM saved_form_values 
                    $whereClause 
                    ORDER BY form_type, category, use_count DESC, value ASC";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $values = $stmt->fetchAll();
            
            // Group by form_type and category
            $grouped = [];
            foreach ($values as $row) {
                $ft = $row['form_type'];
                $cat = $row['category'];
                if (!isset($grouped[$ft])) {
                    $grouped[$ft] = [];
                }
                if (!isset($grouped[$ft][$cat])) {
                    $grouped[$ft][$cat] = [];
                }
                $grouped[$ft][$cat][] = [
                    'value' => $row['value'],
                    'use_count' => (int)$row['use_count']
                ];
            }
            
            echo json_encode([
                'success' => true,
                'data' => $grouped,
                'flat' => $values
            ]);
            break;
            
        case 'POST':
            // Save new value or increment use count
            $input = file_get_contents('php://input');
            $data = json_decode($input, true);
            
            if (!$data) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Invalid JSON input']);
                exit;
            }
            
            $formType = $data['form'] ?? $data['form_type'] ?? '';
            $category = trim((string) ($data['category'] ?? ''));
            $value = trim((string) ($data['value'] ?? ''));

            // Validate
            if (!in_array($formType, ['wiki', 'news'])) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Invalid form type']);
                exit;
            }

            if ($category === '' || $value === '') {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Category and value are required']);
                exit;
            }

            // Column widths: category varchar(100), value varchar(255).
            if (mb_strlen($category) > 100 || mb_strlen($value) > 255) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Category or value too long']);
                exit;
            }
            
            // Use INSERT ... ON DUPLICATE KEY UPDATE to handle both new and existing values
            $sql = "INSERT INTO saved_form_values (form_type, category, value, use_count)
                    VALUES (?, ?, ?, 1)
                    ON DUPLICATE KEY UPDATE use_count = use_count + 1, updated_at = CURRENT_TIMESTAMP";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$formType, $category, $value]);
            
            echo json_encode([
                'success' => true,
                'message' => 'Value saved'
            ]);
            break;
            
        case 'DELETE':
            // Delete a saved value
            $input = file_get_contents('php://input');
            $data = json_decode($input, true);
            
            if (!$data) {
                // Try query params
                $data = [
                    'form' => $_GET['form'] ?? '',
                    'category' => $_GET['category'] ?? '',
                    'value' => $_GET['value'] ?? ''
                ];
            }
            
            $formType = $data['form'] ?? $data['form_type'] ?? '';
            $category = $data['category'] ?? '';
            $value = $data['value'] ?? '';
            
            if (empty($formType) || empty($category) || empty($value)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Form type, category, and value are required']);
                exit;
            }
            
            $sql = "DELETE FROM saved_form_values WHERE form_type = ? AND category = ? AND value = ?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$formType, $category, $value]);
            
            echo json_encode([
                'success' => true,
                'message' => 'Value deleted',
                'deleted' => $stmt->rowCount() > 0
            ]);
            break;
            
        default:
            http_response_code(405);
            echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    }
    
} catch (PDOException $e) {
    error_log("Saved values error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Database error occurred'
    ]);
} catch (Exception $e) {
    error_log("Saved values error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'An error occurred'
    ]);
}
