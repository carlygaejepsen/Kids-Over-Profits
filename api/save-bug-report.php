<?php
/**
 * Bug Report API
 *
 * POST (public)  — save a bug report from the front-end widget.
 * GET  (admin)   — list bug reports, filterable by status.
 * POST action=update_status (admin) — triage a report.
 *
 * Same-origin only: no CORS headers on purpose. Reports store a salted IP
 * hash (never the raw IP) for rate limiting.
 */

header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');

require_once __DIR__ . '/config.php';

// config.php boots WordPress via wp-config.php, so current_user_can/wp_mail
// are available. Guard anyway so a CLI/partial load fails closed.
$kop_is_admin = function_exists('current_user_can') && current_user_can('manage_options');

const KOP_BUG_STATUSES = ['new', 'in_progress', 'resolved', 'dismissed'];

// One-time, idempotent table creation.
$pdo->exec("CREATE TABLE IF NOT EXISTS bug_reports (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    category VARCHAR(60) NOT NULL DEFAULT 'other',
    description TEXT NOT NULL,
    steps TEXT NULL,
    contact VARCHAR(190) NULL,
    page_url VARCHAR(500) NULL,
    page_title VARCHAR(255) NULL,
    user_agent VARCHAR(500) NULL,
    viewport VARCHAR(40) NULL,
    console_errors MEDIUMTEXT NULL,
    context_json MEDIUMTEXT NULL,
    ip_hash CHAR(64) NULL,
    status ENUM('new','in_progress','resolved','dismissed') NOT NULL DEFAULT 'new',
    admin_note TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_status_created (status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

/**
 * Salted hash of the caller's IP. Uses WP auth salt when available so the
 * hash can't be reversed from a DB dump alone.
 */
function kop_bug_ip_hash() {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $salt = defined('AUTH_SALT') ? AUTH_SALT : 'kop-bug-report';
    return hash('sha256', $salt . '|' . $ip);
}

function kop_bug_json_exit($payload, $code = 200) {
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    // ---------- Admin: list reports ----------
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        if (!$kop_is_admin) {
            kop_bug_json_exit(['success' => false, 'error' => 'Not authorized'], 403);
        }

        $status = $_GET['status'] ?? null;
        $limit  = min(max((int)($_GET['limit'] ?? 100), 1), 500);
        $offset = max((int)($_GET['offset'] ?? 0), 0);

        $where  = '';
        $params = [];
        if ($status !== null && in_array($status, KOP_BUG_STATUSES, true)) {
            $where = 'WHERE status = ?';
            $params[] = $status;
        }

        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM bug_reports $where");
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();

        $stmt = $pdo->prepare("SELECT * FROM bug_reports $where ORDER BY created_at DESC LIMIT $limit OFFSET $offset");
        $stmt->execute($params);
        $rows = $stmt->fetchAll();
        foreach ($rows as &$row) {
            unset($row['ip_hash']);
            $row['console_errors'] = json_decode($row['console_errors'] ?? 'null', true);
            $row['context_json'] = json_decode($row['context_json'] ?? 'null', true);
        }
        unset($row);

        kop_bug_json_exit(['success' => true, 'data' => $rows, 'total' => $total]);
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        kop_bug_json_exit(['success' => false, 'error' => 'Method not allowed'], 405);
    }

    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        kop_bug_json_exit(['success' => false, 'error' => 'Invalid JSON input'], 400);
    }

    // ---------- Admin: triage ----------
    if (($input['action'] ?? '') === 'update_status') {
        if (!$kop_is_admin) {
            kop_bug_json_exit(['success' => false, 'error' => 'Not authorized'], 403);
        }
        $id = (int)($input['id'] ?? 0);
        $newStatus = $input['status'] ?? '';
        if ($id <= 0 || !in_array($newStatus, KOP_BUG_STATUSES, true)) {
            kop_bug_json_exit(['success' => false, 'error' => 'Invalid id or status'], 400);
        }
        $note = mb_substr(trim((string)($input['adminNote'] ?? '')), 0, 5000);
        $stmt = $pdo->prepare("UPDATE bug_reports SET status = ?, admin_note = COALESCE(NULLIF(?, ''), admin_note) WHERE id = ?");
        $stmt->execute([$newStatus, $note, $id]);
        kop_bug_json_exit(['success' => true, 'id' => $id, 'status' => $newStatus]);
    }

    // ---------- Public: submit a report ----------

    // Honeypot: real users never fill this hidden field. Pretend success so
    // bots don't learn anything.
    if (!empty($input['website'])) {
        kop_bug_json_exit(['success' => true, 'id' => 0]);
    }

    // Description is optional — the auto-captured technical details usually
    // identify the problem on their own. Store a readable placeholder so the
    // NOT NULL column and admin email stay legible.
    $description = trim((string)($input['description'] ?? ''));
    if ($description === '') {
        $description = '(no description provided)';
    }

    // Rate limit: max 5 reports per IP per 10 minutes.
    $ipHash = kop_bug_ip_hash();
    $rl = $pdo->prepare("SELECT COUNT(*) FROM bug_reports WHERE ip_hash = ? AND created_at > (NOW() - INTERVAL 10 MINUTE)");
    $rl->execute([$ipHash]);
    if ((int)$rl->fetchColumn() >= 5) {
        kop_bug_json_exit(['success' => false, 'error' => 'Too many reports in a short time — please wait a few minutes and try again.'], 429);
    }

    $allowedCategories = ['save-failed', 'load-failed', 'broken-ui', 'wrong-data', 'other'];
    $category = (string)($input['category'] ?? 'other');
    if (!in_array($category, $allowedCategories, true)) {
        $category = 'other';
    }

    $consoleErrors = null;
    if (isset($input['consoleErrors']) && is_array($input['consoleErrors'])) {
        // Cap at 20 entries / ~40KB so nobody can stuff megabytes in.
        $consoleErrors = json_encode(array_slice($input['consoleErrors'], 0, 20), JSON_UNESCAPED_UNICODE);
        if (strlen($consoleErrors) > 40000) {
            $consoleErrors = mb_substr($consoleErrors, 0, 40000);
        }
    }

    $context = null;
    if (isset($input['context']) && is_array($input['context'])) {
        $context = json_encode($input['context'], JSON_UNESCAPED_UNICODE);
        if (strlen($context) > 40000) {
            $context = mb_substr($context, 0, 40000);
        }
    }

    $stmt = $pdo->prepare("INSERT INTO bug_reports
        (category, description, steps, contact, page_url, page_title, user_agent, viewport, console_errors, context_json, ip_hash)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([
        $category,
        mb_substr($description, 0, 10000),
        mb_substr(trim((string)($input['steps'] ?? '')), 0, 10000) ?: null,
        mb_substr(trim((string)($input['contact'] ?? '')), 0, 190) ?: null,
        mb_substr((string)($input['pageUrl'] ?? ''), 0, 500) ?: null,
        mb_substr((string)($input['pageTitle'] ?? ''), 0, 255) ?: null,
        mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500) ?: null,
        mb_substr((string)($input['viewport'] ?? ''), 0, 40) ?: null,
        $consoleErrors,
        $context,
        $ipHash,
    ]);
    $newId = (int)$pdo->lastInsertId();

    // Email notification — best effort, never blocks the response.
    if (function_exists('wp_mail') && function_exists('get_option')) {
        $adminEmail = get_option('admin_email');
        if ($adminEmail) {
            $subject = '[KOP] Bug report #' . $newId . ' — ' . $category;
            $body = "A new bug report was submitted on kidsoverprofits.org.\n\n"
                . "Category: $category\n"
                . "Page: " . (string)($input['pageUrl'] ?? '(unknown)') . "\n\n"
                . "Description:\n$description\n\n"
                . (trim((string)($input['steps'] ?? '')) !== '' ? "Steps to reproduce:\n" . trim((string)$input['steps']) . "\n\n" : '')
                . (trim((string)($input['contact'] ?? '')) !== '' ? "Contact: " . trim((string)$input['contact']) . "\n\n" : '')
                . "Review it in wp-admin → KOP Data Tools → Bug Reports.";
            @wp_mail($adminEmail, $subject, $body);
        }
    }

    kop_bug_json_exit([
        'success' => true,
        'id' => $newId,
        'message' => 'Thank you — your report was received.'
    ]);

} catch (PDOException $e) {
    error_log('Bug report error: ' . $e->getMessage());
    kop_bug_json_exit(['success' => false, 'error' => 'A database error occurred. Please try again later.'], 500);
} catch (Exception $e) {
    error_log('Bug report error: ' . $e->getMessage());
    kop_bug_json_exit(['success' => false, 'error' => 'An unexpected error occurred. Please try again later.'], 500);
}
