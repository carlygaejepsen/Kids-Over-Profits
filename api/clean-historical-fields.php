<?php
/**
 * Clean Historical Identification Fields
 *
 * Source data frequently stuffs historical markers ("Previously X", "Formerly Y")
 * into present-tense identification fields (currentOperator / currentOwner /
 * currentOwners / currentName). Rendered verbatim these read ungrammatically
 * ("Operated by: Previously …") and duplicate the former-name copy.
 *
 * This maintenance script walks every master table, strips those markers, and
 * reclassifies the values into `pastNames`, clearing the present-tense fields.
 * Rows are updated in place in their own table, so there are no categorization
 * side-effects (unlike the projects/save REST route).
 *
 * Mirrors scripts/clean-historical-fields.js (the JS/Node transform used for the
 * import sources) and the display-layer helpers in js/tti-program-index.js.
 *
 * Usage:
 *   Dry run (default):  .../api/clean-historical-fields.php
 *   Apply changes:      .../api/clean-historical-fields.php?run=1
 *
 * A JSON backup of every changed row (original json_data) is written before any
 * write, and all updates run inside a single transaction.
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

header('Content-Type: text/html; charset=utf-8');

echo "<!DOCTYPE html><html><head><title>Clean Historical Identification Fields</title>";
echo "<style>body{font-family:monospace;padding:20px;background:#1a1a2e;color:#eee;} ";
echo ".change{color:#ffd43b;} .keep{color:#51cf66;} .info{color:#74c0fc;} .warn{color:#ff6b6b;} ";
echo "h1{color:#ffd43b;} pre{background:#2d2d44;padding:10px;border-radius:5px;overflow-x:auto;}</style></head><body>";

echo "<h1>🧹 Clean Historical Identification Fields</h1>";

try {
    require_once __DIR__ . '/config.php';
    echo "<p class='keep'>✓ Config loaded</p>";
} catch (Exception $e) {
    echo "<p class='warn'>Config error: " . htmlspecialchars($e->getMessage()) . "</p></body></html>";
    exit;
}

if (!isset($pdo) || !($pdo instanceof PDO)) {
    echo "<p class='warn'>Error: database connection (\$pdo) not available.</p></body></html>";
    exit;
}
echo "<p class='keep'>✓ Database connection established</p>";

/* ------------------------------------------------------------------ *
 * Transform helpers — kept in lockstep with the JS/Node versions.
 * ------------------------------------------------------------------ */

function kop_is_historical_text($value) {
    if (!is_string($value)) {
        return false;
    }
    return preg_match('/\b(?:previously|former(?:ly)?|prior)\b/i', $value) === 1;
}

function kop_strip_historical_prefix($value) {
    if (!is_string($value)) {
        return '';
    }
    $trimmed = trim($value);
    // \x{2013} = en dash, \x{2014} = em dash. /u so multibyte chars match.
    return trim(preg_replace('/^\s*(?:previously|formerly|former|prior(?:\s+to)?)\b[\s:;,.\x{2013}\x{2014}\-]*/iu', '', $trimmed));
}

function kop_split_names($value) {
    $stripped = kop_strip_historical_prefix($value);
    $parts = array_map('trim', explode(';', $stripped));
    return array_values(array_filter($parts, function ($p) {
        return $p !== '';
    }));
}

/**
 * Mirrors splitNameList in js/tti-program-index.js: split a comma/semicolon-
 * joined name string into individual names, re-attaching bare corporate
 * suffixes ("Foo, Inc.") to the preceding name.
 */
function kop_split_name_list($value) {
    if (!is_string($value)) {
        return array();
    }
    $parts = preg_split('/\s*[;,]\s*/', trim($value));
    $out = array();
    foreach ($parts as $part) {
        $part = trim($part);
        if ($part === '') {
            continue;
        }
        if (!empty($out) && preg_match('/^(?:inc|llc|l\.l\.c|ltd|co|corp|corporation|company|llp|lp|plc|pllc|pc|p\.c|n\.a)\.?$/i', $part)) {
            $out[count($out) - 1] .= ', ' . $part;
        } else {
            $out[] = $part;
        }
    }
    return $out;
}

/**
 * Split & de-duplicate a name-list field in place. Returns true if it changed.
 */
function kop_normalize_name_array(array &$obj, $field) {
    if (!isset($obj[$field]) || !is_array($obj[$field])) {
        return false;
    }
    $result = array();
    $seen = array();
    $changed = false;
    foreach ($obj[$field] as $entry) {
        if (!is_string($entry)) {
            $result[] = $entry;
            continue;
        }
        $parts = kop_split_name_list($entry);
        if (count($parts) !== 1 || $parts[0] !== trim($entry)) {
            $changed = true;
        }
        foreach ($parts as $name) {
            $key = mb_strtolower($name);
            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $result[] = $name;
            } else {
                $changed = true;
            }
        }
    }
    if ($changed) {
        $obj[$field] = array_values($result);
    }
    return $changed;
}

/**
 * Clean one identification-like array in place. Returns number of fields changed.
 */
function kop_clean_identification(array &$obj) {
    $changes = 0;
    $collected = array();

    foreach (array('currentOperator', 'currentOwner', 'currentName') as $field) {
        if (isset($obj[$field]) && is_string($obj[$field]) && kop_is_historical_text($obj[$field])) {
            foreach (kop_split_names($obj[$field]) as $name) {
                $collected[] = $name;
            }
            $obj[$field] = '';
            $changes++;
        }
    }

    if (isset($obj['currentOwners']) && is_array($obj['currentOwners'])) {
        $kept = array();
        $removed = false;
        foreach ($obj['currentOwners'] as $entry) {
            if (is_string($entry) && kop_is_historical_text($entry)) {
                foreach (kop_split_names($entry) as $name) {
                    $collected[] = $name;
                }
                $removed = true;
            } else {
                $kept[] = $entry;
            }
        }
        if ($removed) {
            $obj['currentOwners'] = array_values($kept);
            $changes++;
        }
    }

    if (!empty($collected)) {
        if (!isset($obj['pastNames']) || !is_array($obj['pastNames'])) {
            $obj['pastNames'] = array();
        }
        $existing = array();
        foreach ($obj['pastNames'] as $n) {
            $existing[mb_strtolower(trim((string) $n))] = true;
        }
        foreach ($collected as $name) {
            $key = mb_strtolower($name);
            if ($name !== '' && !isset($existing[$key])) {
                $obj['pastNames'][] = $name;
                $existing[$key] = true;
            }
        }
    }

    // Normalize name-list fields: split comma/semicolon-joined strings into
    // individual, de-duplicated entries so stored data matches what the card
    // renders (and the Formerly / Also-known-as lines stop overlapping).
    foreach (array('otherNames', 'pastNames', 'formerNames') as $field) {
        if (kop_normalize_name_array($obj, $field)) {
            $changes++;
        }
    }

    return $changes;
}

function kop_has_identification_fields($node) {
    return is_array($node) && (
        array_key_exists('currentOperator', $node) ||
        array_key_exists('currentOwner', $node) ||
        array_key_exists('currentOwners', $node) ||
        array_key_exists('currentName', $node) ||
        array_key_exists('otherNames', $node) ||
        array_key_exists('pastNames', $node) ||
        array_key_exists('formerNames', $node)
    );
}

/**
 * Recursively walk a decoded JSON structure, cleaning every identification-like
 * node. $acc collects ['fields' => int, 'records' => int, 'names' => []].
 */
function kop_clean_tree(&$node, array &$acc) {
    if (!is_array($node)) {
        return;
    }
    if (kop_has_identification_fields($node)) {
        $before = isset($node['pastNames']) && is_array($node['pastNames']) ? count($node['pastNames']) : 0;
        $changed = kop_clean_identification($node);
        if ($changed) {
            $acc['fields'] += $changed;
            $acc['records'] += 1;
            $after = isset($node['pastNames']) && is_array($node['pastNames']) ? array_slice($node['pastNames'], $before) : array();
            foreach ($after as $n) {
                $acc['names'][] = $n;
            }
        }
    }
    foreach ($node as &$child) {
        if (is_array($child)) {
            kop_clean_tree($child, $acc);
        }
    }
    unset($child);
}

/* ------------------------------------------------------------------ */

$dryRun = !isset($_GET['run']) || $_GET['run'] !== '1';
if ($dryRun) {
    echo "<p class='info'><strong>DRY RUN</strong> — no changes will be made. Add <code>?run=1</code> to apply.</p>";
} else {
    echo "<p class='change'><strong>APPLYING CHANGES</strong></p>";
}

$tables = array('facilities_master', 'locations_master', 'referrers_master', 'transporters_master');

$updates = array();   // [ ['table'=>, 'id'=>, 'unique_name'=>, 'json'=>, 'original'=>, 'fields'=>, 'records'=>, 'names'=>[]] ]
$totalFields = 0;
$totalRecords = 0;

foreach ($tables as $table) {
    try {
        $exists = $pdo->query("SHOW TABLES LIKE " . $pdo->quote($table))->fetchColumn();
        if ($exists !== $table) {
            echo "<p class='info'>· Skipping {$table} (not found)</p>";
            continue;
        }

        $rows = $pdo->query("SELECT id, unique_name, json_data FROM {$table}")->fetchAll(PDO::FETCH_ASSOC);
        echo "<h3>{$table}: scanning " . count($rows) . " row(s)…</h3>";

        foreach ($rows as $row) {
            if (empty($row['json_data'])) {
                continue;
            }
            $decoded = json_decode($row['json_data'], true);
            if (!is_array($decoded)) {
                continue;
            }

            $acc = array('fields' => 0, 'records' => 0, 'names' => array());
            kop_clean_tree($decoded, $acc);

            if ($acc['fields'] > 0) {
                $json = json_encode($decoded, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                $updates[] = array(
                    'table' => $table,
                    'id' => $row['id'],
                    'unique_name' => $row['unique_name'],
                    'json' => $json,
                    'original' => $row['json_data'],
                    'fields' => $acc['fields'],
                    'records' => $acc['records'],
                    'names' => $acc['names'],
                );
                $totalFields += $acc['fields'];
                $totalRecords += $acc['records'];
            }
        }
    } catch (PDOException $e) {
        echo "<p class='warn'>· Error on {$table}: " . htmlspecialchars($e->getMessage()) . "</p>";
    }
}

if (empty($updates)) {
    echo "<h2 class='keep'>✓ No historical-field issues found. Nothing to clean.</h2></body></html>";
    exit;
}

echo "<h2 class='change'>" . count($updates) . " row(s) to update — {$totalRecords} facility record(s), {$totalFields} field(s):</h2>";
echo "<pre>";
foreach ($updates as $u) {
    $names = $u['names'] ? ' → pastNames: ' . htmlspecialchars(implode(', ', $u['names'])) : '';
    echo "<span class='change'>{$u['table']} / {$u['unique_name']} (id {$u['id']}): {$u['records']} record(s), {$u['fields']} field(s)</span>{$names}\n";
}
echo "</pre>";

if ($dryRun) {
    echo "<p class='info'>👆 Add <code>?run=1</code> to the URL to apply these changes.</p></body></html>";
    exit;
}

// --- Apply ---------------------------------------------------------

// Backup originals before writing.
$backupNote = '';
try {
    $backupDir = __DIR__ . '/../tmp/historical-cleanup-backups';
    if (!is_dir($backupDir)) {
        @mkdir($backupDir, 0777, true);
    }
    $backupFile = $backupDir . '/backup-' . date('Ymd-His') . '.json';
    $backupPayload = array('createdAt' => date('c'), 'rows' => array());
    foreach ($updates as $u) {
        $backupPayload['rows'][] = array(
            'table' => $u['table'],
            'id' => $u['id'],
            'unique_name' => $u['unique_name'],
            'json_data' => $u['original'],
        );
    }
    if (@file_put_contents($backupFile, json_encode($backupPayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) !== false) {
        $backupNote = $backupFile;
        echo "<p class='keep'>✓ Backup written: " . htmlspecialchars($backupFile) . "</p>";
    } else {
        echo "<p class='warn'>⚠ Could not write backup file (continuing; updates are transactional and originals are listed above).</p>";
    }
} catch (Throwable $e) {
    echo "<p class='warn'>⚠ Backup step failed: " . htmlspecialchars($e->getMessage()) . " (continuing)</p>";
}

$applied = 0;
try {
    $pdo->beginTransaction();
    foreach ($updates as $u) {
        $stmt = $pdo->prepare("UPDATE {$u['table']} SET json_data = :json WHERE id = :id");
        $stmt->execute(array(':json' => $u['json'], ':id' => $u['id']));
        $applied++;
    }
    $pdo->commit();
    echo "<h2 class='keep'>✓ Applied {$applied} update(s).</h2>";
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo "<h2 class='warn'>✗ Update failed, rolled back: " . htmlspecialchars($e->getMessage()) . "</h2>";
}

echo "</body></html>";
