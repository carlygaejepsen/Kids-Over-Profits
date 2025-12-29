<?php
/**
 * Database Schema Inspector
 *
 * Returns all tables and their columns from the database.
 * Useful for understanding the data structure and building queries.
 *
 * Usage:
 * 1. Visit: https://your-domain/wp-content/themes/child/api/get-database-schema.php
 * 2. Or run from command line: php api/get-database-schema.php
 * 3. Optional: ?format=json or ?format=html (default: json)
 */

// Load WordPress and config
require_once __DIR__ . '/config.php';

$is_cli = php_sapi_name() === 'cli';
$format = $_GET['format'] ?? 'json';

try {
    // Get all tables in the database
    $tables_query = "SHOW TABLES";
    $stmt = $pdo->query($tables_query);
    $all_tables = $stmt->fetchAll(PDO::FETCH_COLUMN);

    $schema = [];

    foreach ($all_tables as $table_name) {
        // Get column information for each table
        $columns_query = "SHOW COLUMNS FROM `{$table_name}`";
        $stmt = $pdo->query($columns_query);
        $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $column_details = [];
        foreach ($columns as $column) {
            $column_details[] = [
                'Field' => $column['Field'],
                'Type' => $column['Type'],
                'Null' => $column['Null'],
                'Key' => $column['Key'],
                'Default' => $column['Default'],
                'Extra' => $column['Extra']
            ];
        }

        $schema[$table_name] = [
            'columns' => $column_details,
            'column_names' => array_column($column_details, 'Field'),
            'primary_keys' => array_column(array_filter($column_details, function($col) {
                return $col['Key'] === 'PRI';
            }), 'Field'),
            'unique_keys' => array_column(array_filter($column_details, function($col) {
                return $col['Key'] === 'UNI';
            }), 'Field'),
            'indexed_keys' => array_column(array_filter($column_details, function($col) {
                return $col['Key'] === 'MUL';
            }), 'Field')
        ];
    }

    // Format output based on request
    if ($format === 'html' && !$is_cli) {
        header('Content-Type: text/html; charset=utf-8');
        echo '<!DOCTYPE html><html><head><meta charset="utf-8">';
        echo '<title>Database Schema</title>';
        echo '<style>
            body { font-family: monospace; padding: 20px; background: #f5f5f5; }
            .table-section { background: white; margin: 20px 0; padding: 15px; border-radius: 5px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
            h2 { color: #000435; margin-top: 0; }
            h3 { color: #33A7B5; margin: 10px 0 5px 0; }
            table { width: 100%; border-collapse: collapse; margin: 10px 0; }
            th { background: #000435; color: white; padding: 8px; text-align: left; }
            td { padding: 8px; border-bottom: 1px solid #ddd; }
            tr:hover { background: #f9f9f9; }
            .key-badge { display: inline-block; padding: 2px 6px; margin: 0 2px; border-radius: 3px; font-size: 11px; }
            .pri { background: #EF9034; color: white; }
            .uni { background: #B2E102; color: #000; }
            .mul { background: #33A7B5; color: white; }
            .column-list { color: #666; }
            code { background: #f0f0f0; padding: 2px 6px; border-radius: 3px; }
        </style></head><body>';
        echo '<h1>Database Schema Inspector</h1>';
        echo '<p>Total tables: ' . count($schema) . '</p>';

        foreach ($schema as $table_name => $table_info) {
            echo '<div class="table-section">';
            echo '<h2>' . htmlspecialchars($table_name) . '</h2>';

            // Quick reference
            echo '<h3>Column Names (for SELECT queries):</h3>';
            echo '<p class="column-list"><code>' . implode('</code>, <code>', $table_info['column_names']) . '</code></p>';

            // Detailed table
            echo '<h3>Column Details:</h3>';
            echo '<table>';
            echo '<tr><th>Field</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th><th>Extra</th></tr>';
            foreach ($table_info['columns'] as $col) {
                echo '<tr>';
                echo '<td><strong>' . htmlspecialchars($col['Field']) . '</strong></td>';
                echo '<td>' . htmlspecialchars($col['Type']) . '</td>';
                echo '<td>' . htmlspecialchars($col['Null']) . '</td>';
                echo '<td>';
                if ($col['Key'] === 'PRI') echo '<span class="key-badge pri">PRIMARY</span>';
                if ($col['Key'] === 'UNI') echo '<span class="key-badge uni">UNIQUE</span>';
                if ($col['Key'] === 'MUL') echo '<span class="key-badge mul">INDEX</span>';
                echo '</td>';
                echo '<td>' . htmlspecialchars($col['Default'] ?? 'NULL') . '</td>';
                echo '<td>' . htmlspecialchars($col['Extra']) . '</td>';
                echo '</tr>';
            }
            echo '</table>';
            echo '</div>';
        }

        echo '</body></html>';
        exit;
    }

    // JSON output (default)
    if (!$is_cli) {
        header('Content-Type: application/json');
    }

    $result = [
        'success' => true,
        'total_tables' => count($schema),
        'tables' => $schema
    ];

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
}
