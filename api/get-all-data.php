<?php
/**
 * Get All Data from Database Tables
 *
 * Returns all data from specified table(s) or all tables.
 * Useful for data export, debugging, or displaying complete datasets.
 *
 * Usage:
 * 1. All tables: ?show=all
 * 2. Specific table: ?table=facilities_master
 * 3. Specific table with limit: ?table=facilities_master&limit=10
 * 4. HTML format: ?table=facilities_master&format=html
 * 5. CSV format: ?table=facilities_master&format=csv
 */

// Load WordPress and config
require_once __DIR__ . '/config.php';

$is_cli = php_sapi_name() === 'cli';

// Auth check — CLI is always allowed; HTTP requests require a valid API key
if (!$is_cli) {
    $provided_key = $_GET['key'] ?? $_SERVER['HTTP_X_API_KEY'] ?? '';
    $expected_key = defined('KOP_DATA_API_KEY') ? KOP_DATA_API_KEY
                  : (getenv('KOP_DATA_API_KEY') ?: null);
    if (!$expected_key || !hash_equals($expected_key, $provided_key)) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Unauthorized']);
        exit;
    }
}
$table_name = $_GET['table'] ?? null;
$show_all = $_GET['show'] ?? null;
$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : null;
$format = $_GET['format'] ?? 'json';

try {
    $result = [
        'success' => true,
        'data' => []
    ];

    // Get list of tables to query
    $tables_to_query = [];

    if ($show_all === 'all') {
        // Get all tables
        $stmt = $pdo->query("SHOW TABLES");
        $tables_to_query = $stmt->fetchAll(PDO::FETCH_COLUMN);
    } elseif ($table_name) {
        // Validate table exists
        $stmt = $pdo->prepare("SHOW TABLES LIKE ?");
        $stmt->execute([$table_name]);
        if ($stmt->fetch()) {
            $tables_to_query = [$table_name];
        } else {
            throw new Exception("Table '{$table_name}' does not exist");
        }
    } else {
        // Show available tables
        $stmt = $pdo->query("SHOW TABLES");
        $all_tables = $stmt->fetchAll(PDO::FETCH_COLUMN);

        if (!$is_cli) {
            header('Content-Type: application/json');
        }
        echo json_encode([
            'success' => true,
            'message' => 'Specify a table to query',
            'available_tables' => $all_tables,
            'usage' => [
                'Get specific table' => '?table=facilities_master',
                'Get all tables' => '?show=all',
                'Limit results' => '?table=facilities_master&limit=10',
                'HTML format' => '?table=facilities_master&format=html',
                'CSV format' => '?table=facilities_master&format=csv'
            ]
        ], JSON_PRETTY_PRINT);
        exit;
    }

    // Query each table
    foreach ($tables_to_query as $table) {
        $sql = "SELECT * FROM `{$table}`";
        if ($limit) {
            $sql .= " LIMIT " . $limit;
        }

        $stmt = $pdo->query($sql);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $result['data'][$table] = [
            'row_count' => count($rows),
            'rows' => $rows
        ];
    }

    // Format output
    if ($format === 'csv' && count($tables_to_query) === 1 && !$is_cli) {
        // CSV export for single table
        $table = $tables_to_query[0];
        $rows = $result['data'][$table]['rows'];

        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="' . $table . '.csv"');

        if (!empty($rows)) {
            $output = fopen('php://output', 'w');
            fputcsv($output, array_keys($rows[0]));
            foreach ($rows as $row) {
                fputcsv($output, $row);
            }
            fclose($output);
        }
        exit;

    } elseif ($format === 'html' && !$is_cli) {
        // HTML table view
        header('Content-Type: text/html; charset=utf-8');
        echo '<!DOCTYPE html><html><head><meta charset="utf-8">';
        echo '<title>Database Data</title>';
        echo '<style>
            body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; padding: 20px; background: #f5f5f5; }
            .table-section { background: white; margin: 20px 0; padding: 15px; border-radius: 5px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); overflow-x: auto; }
            h2 { color: #000435; margin-top: 0; }
            .count { color: #666; font-size: 14px; }
            table { width: 100%; border-collapse: collapse; margin: 10px 0; font-size: 13px; }
            th { background: #000435; color: white; padding: 10px 8px; text-align: left; position: sticky; top: 0; }
            td { padding: 8px; border-bottom: 1px solid #ddd; vertical-align: top; max-width: 300px; overflow: hidden; text-overflow: ellipsis; }
            tr:hover { background: #f9f9f9; }
            .json-preview { color: #666; font-family: monospace; font-size: 11px; white-space: nowrap; }
            .actions { margin-bottom: 20px; }
            .btn { display: inline-block; padding: 8px 16px; background: #33A7B5; color: white; text-decoration: none; border-radius: 4px; margin-right: 10px; }
            .btn:hover { background: #2a8f9a; }
        </style></head><body>';
        echo '<h1>Database Data Viewer</h1>';

        echo '<div class="actions">';
        foreach ($tables_to_query as $table) {
            echo '<a href="?table=' . urlencode($table) . '&format=csv" class="btn">Download ' . htmlspecialchars($table) . ' CSV</a>';
        }
        echo '</div>';

        foreach ($result['data'] as $table => $table_data) {
            echo '<div class="table-section">';
            echo '<h2>' . htmlspecialchars($table) . '</h2>';
            echo '<p class="count">Total rows: ' . $table_data['row_count'] . '</p>';

            if (!empty($table_data['rows'])) {
                echo '<table>';
                echo '<tr>';
                foreach (array_keys($table_data['rows'][0]) as $column) {
                    echo '<th>' . htmlspecialchars($column) . '</th>';
                }
                echo '</tr>';

                foreach ($table_data['rows'] as $row) {
                    echo '<tr>';
                    foreach ($row as $value) {
                        echo '<td>';
                        if (is_null($value)) {
                            echo '<em style="color: #999;">NULL</em>';
                        } elseif (strlen($value) > 100) {
                            // Truncate long values
                            echo '<span title="' . htmlspecialchars($value) . '">';
                            echo htmlspecialchars(substr($value, 0, 100)) . '...';
                            echo '</span>';
                        } else {
                            echo htmlspecialchars($value);
                        }
                        echo '</td>';
                    }
                    echo '</tr>';
                }
                echo '</table>';
            } else {
                echo '<p><em>No data in this table</em></p>';
            }
            echo '</div>';
        }

        echo '</body></html>';
        exit;
    }

    // JSON output (default)
    if (!$is_cli) {
        header('Content-Type: application/json');
    }

    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($is_cli) {
        echo "\n";
    }

} catch (PDOException $e) {
    if (!$is_cli) {
        http_response_code(500);
        header('Content-Type: application/json');
    }
    echo json_encode([
        'success' => false,
        'error' => 'Database error: ' . $e->getMessage()
    ], JSON_PRETTY_PRINT);
    exit(1);
} catch (Exception $e) {
    if (!$is_cli) {
        http_response_code(500);
        header('Content-Type: application/json');
    }
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ], JSON_PRETTY_PRINT);
    exit(1);
}
