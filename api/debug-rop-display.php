<?php
/**
 * Debug ROP Project Display Issue
 * Simulates the JavaScript filtering logic to see why ROP isn't displaying
 */

header('Content-Type: text/html; charset=UTF-8');
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/config.php';

echo '<h1>ROP Project Display Debug</h1>';
echo '<style>
body { font-family: monospace; padding: 20px; }
.pass { color: green; font-weight: bold; }
.fail { color: red; font-weight: bold; }
.info { color: blue; }
pre { background: #f5f5f5; padding: 10px; overflow-x: auto; }
h2 { border-bottom: 2px solid #333; margin-top: 30px; }
</style>';

try {
    // Get ROP project
    $stmt = $pdo->prepare("SELECT json_data FROM facilities_master WHERE unique_name = :name");
    $stmt->bindValue(':name', 'ROP');
    $stmt->execute();
    
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$row) {
        echo '<p class="fail">❌ ROP project not found in database</p>';
        exit;
    }
    
    $data = json_decode($row['json_data'], true);
    
    if (!is_array($data)) {
        echo '<p class="fail">❌ Invalid JSON in database</p>';
        exit;
    }
    
    echo '<h2>1. JSON Structure Check</h2>';
    echo '<p class="pass">✅ JSON is valid</p>';
    echo '<p>Name: <strong>' . htmlspecialchars($data['name'] ?? 'N/A') . '</strong></p>';
    echo '<p>Category: <strong>' . htmlspecialchars($data['category'] ?? 'N/A') . '</strong></p>';
    
    // Check if category would be filtered
    $category = isset($data['category']) ? strtolower($data['category']) : '';
    if ($category === 'companies' || $category === 'operators' || $category === 'operator') {
        echo '<p class="pass">✅ Category is correct for TTI index display</p>';
    } else {
        echo '<p class="fail">❌ Category "' . htmlspecialchars($category) . '" won\'t display on TTI index</p>';
    }
    
    echo '<h2>2. Operator Name Extraction</h2>';
    $operator = $data['data']['operator'] ?? [];
    echo '<p>Operator data:</p>';
    echo '<pre>' . htmlspecialchars(json_encode($operator, JSON_PRETTY_PRINT)) . '</pre>';
    
    // Simulate JavaScript getValueFromKeys logic
    $keys = ['name', 'currentName', 'operatorName', 'ownerName', 'companyName'];
    $operatorName = null;
    
    foreach ($keys as $key) {
        if (isset($operator[$key]) && is_string($operator[$key]) && trim($operator[$key]) !== '') {
            $operatorName = trim($operator[$key]);
            echo '<p class="pass">✅ Found operator name in key "' . $key . '": <strong>' . htmlspecialchars($operatorName) . '</strong></p>';
            break;
        } else {
            $value = isset($operator[$key]) ? $operator[$key] : 'undefined';
            $valueDisplay = is_string($value) ? '"' . $value . '"' : json_encode($value);
            echo '<p class="info">ℹ️ Key "' . $key . '" = ' . htmlspecialchars($valueDisplay) . ' (skipped)</p>';
        }
    }
    
    if ($operatorName === null) {
        echo '<p class="fail">❌ No operator name found! This would cause "Unknown Parent Company" and skip display</p>';
    } else {
        echo '<p class="pass">✅ Operator name extracted successfully</p>';
    }
    
    echo '<h2>3. Facilities Check</h2>';
    $facilities = $data['data']['facilities'] ?? [];
    
    if (!is_array($facilities)) {
        echo '<p class="fail">❌ Facilities is not an array</p>';
    } elseif (count($facilities) === 0) {
        echo '<p class="fail">❌ No facilities in array (would not display)</p>';
    } else {
        echo '<p class="pass">✅ Found ' . count($facilities) . ' facilities</p>';
        
        // Check first few facilities
        echo '<h3>First 3 facilities:</h3>';
        for ($i = 0; $i < min(3, count($facilities)); $i++) {
            $fac = $facilities[$i];
            $facName = $fac['identification']['name'] ?? $fac['name'] ?? 'No name';
            echo '<p>' . ($i + 1) . '. ' . htmlspecialchars($facName) . '</p>';
        }
    }
    
    echo '<h2>4. Final Display Decision</h2>';
    
    $willDisplay = true;
    $reasons = [];
    
    if ($category !== 'companies' && $category !== 'operators' && $category !== 'operator') {
        $willDisplay = false;
        $reasons[] = 'Category is "' . htmlspecialchars($category) . '" instead of "companies" or "operators"';
    }
    
    if ($operatorName === null) {
        $willDisplay = false;
        $reasons[] = 'Operator name could not be extracted (would show as "Unknown Parent Company")';
    }
    
    if (empty($facilities)) {
        $willDisplay = false;
        $reasons[] = 'No facilities in the project';
    }
    
    if ($willDisplay) {
        echo '<p class="pass">✅ ROP SHOULD DISPLAY on the TTI index</p>';
        echo '<p class="info">If it\'s not appearing, try:</p>';
        echo '<ul>';
        echo '<li>Clear browser cache (Ctrl+Shift+R)</li>';
        echo '<li>Clear LiteSpeed Cache on the server</li>';
        echo '<li>Check browser console for JavaScript errors</li>';
        echo '</ul>';
    } else {
        echo '<p class="fail">❌ ROP WILL NOT DISPLAY for the following reasons:</p>';
        echo '<ul>';
        foreach ($reasons as $reason) {
            echo '<li>' . htmlspecialchars($reason) . '</li>';
        }
        echo '</ul>';
    }
    
    echo '<h2>5. REST API Check</h2>';
    echo '<p>Check if ROP appears in the REST API:</p>';
    echo '<p><a href="/wp-json/kop/v1/facilities" target="_blank">View REST API →</a></p>';
    echo '<p>Search for "ROP" in the JSON response</p>';
    
} catch (PDOException $e) {
    echo '<p class="fail">❌ Database error: ' . htmlspecialchars($e->getMessage()) . '</p>';
}
