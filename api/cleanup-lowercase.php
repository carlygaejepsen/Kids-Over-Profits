<?php
/**
 * Cleanup Lowercase Location Projects
 * 
 * This script deletes all lowercase state/country projects from facilities_master.
 * Run with: ?run=1 to execute the cleanup
 * Run without parameters to see what would be deleted (dry run)
 */

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

header('Content-Type: text/html; charset=utf-8');

echo "<!DOCTYPE html><html><head><title>Cleanup Lowercase Location Projects</title>";
echo "<style>body{font-family:monospace;padding:20px;background:#1a1a2e;color:#eee;} ";
echo ".delete{color:#ff6b6b;} .keep{color:#51cf66;} .info{color:#74c0fc;} ";
echo "h1{color:#ffd43b;} pre{background:#2d2d44;padding:10px;border-radius:5px;overflow-x:auto;}</style></head><body>";

echo "<h1>🧹 Cleanup Lowercase Location Projects</h1>";

// Try to load config
try {
    require_once __DIR__ . '/config.php';
    echo "<p class='keep'>✓ Config loaded successfully</p>";
} catch (Exception $e) {
    echo "<p class='delete'>Config error: " . htmlspecialchars($e->getMessage()) . "</p>";
    echo "</body></html>";
    exit;
}

if (!isset($pdo)) {
    echo "<p class='delete'>Error: \$pdo not defined after loading config</p>";
    echo "</body></html>";
    exit;
}

echo "<p class='keep'>✓ Database connection established</p>";

// US States (all uppercase for matching)
$US_STATE_NAMES = [
    'ALABAMA', 'ALASKA', 'ARIZONA', 'ARKANSAS', 'CALIFORNIA', 'COLORADO', 'CONNECTICUT',
    'DELAWARE', 'FLORIDA', 'GEORGIA', 'HAWAII', 'IDAHO', 'ILLINOIS', 'INDIANA', 'IOWA',
    'KANSAS', 'KENTUCKY', 'LOUISIANA', 'MAINE', 'MARYLAND', 'MASSACHUSETTS', 'MICHIGAN',
    'MINNESOTA', 'MISSISSIPPI', 'MISSOURI', 'MONTANA', 'NEBRASKA', 'NEVADA', 'NEW HAMPSHIRE',
    'NEW JERSEY', 'NEW MEXICO', 'NEW YORK', 'NORTH CAROLINA', 'NORTH DAKOTA', 'OHIO',
    'OKLAHOMA', 'OREGON', 'PENNSYLVANIA', 'RHODE ISLAND', 'SOUTH CAROLINA', 'SOUTH DAKOTA',
    'TENNESSEE', 'TEXAS', 'UTAH', 'VERMONT', 'VIRGINIA', 'WASHINGTON', 'WEST VIRGINIA',
    'WISCONSIN', 'WYOMING', 'DISTRICT OF COLUMBIA', 'PUERTO RICO', 'GUAM', 'VIRGIN ISLANDS',
    'AMERICAN SAMOA', 'NORTHERN MARIANA ISLANDS'
];

$COUNTRY_NAMES = [
    'UNITED STATES', 'CANADA', 'MEXICO', 'UNITED KINGDOM', 'AUSTRALIA', 'JAMAICA',
    'SAMOA', 'COSTA RICA', 'CZECH REPUBLIC', 'DOMINICAN REPUBLIC', 'FRANCE', 'ITALY',
    'SPAIN', 'GERMANY', 'NETHERLANDS', 'SWITZERLAND', 'BRAZIL', 'ARGENTINA', 'CHILE',
    'CHINA', 'JAPAN', 'SOUTH KOREA', 'INDIA', 'THAILAND', 'VIETNAM', 'PHILIPPINES',
    'INDONESIA', 'MALAYSIA', 'SINGAPORE', 'NEW ZEALAND', 'SOUTH AFRICA', 'EGYPT',
    'ISRAEL', 'UNITED ARAB EMIRATES', 'RUSSIA', 'POLAND', 'SWEDEN', 'NORWAY', 'DENMARK',
    'FINLAND', 'IRELAND', 'SCOTLAND', 'WALES', 'PORTUGAL', 'GREECE', 'TURKEY', 'HUNGARY',
    'AUSTRIA', 'BELGIUM', 'CROATIA', 'ROMANIA', 'UKRAINE', 'BELIZE', 'HONDURAS', 'GUATEMALA',
    'EL SALVADOR', 'NICARAGUA', 'PANAMA', 'COLOMBIA', 'VENEZUELA', 'PERU', 'ECUADOR',
    'BOLIVIA', 'PARAGUAY', 'URUGUAY'
];

$ALL_LOCATIONS = array_merge($US_STATE_NAMES, $COUNTRY_NAMES);

function isLocationProject($name, $allLocations) {
    return in_array(strtoupper(trim($name)), $allLocations);
}

function isLowercase($name) {
    // Check if any letter is lowercase
    return $name !== strtoupper($name);
}

$dryRun = !isset($_GET['run']) || $_GET['run'] !== '1';

if ($dryRun) {
    echo "<p class='info'><strong>DRY RUN MODE</strong> - No changes will be made. Add <code>?run=1</code> to URL to execute.</p>";
} else {
    echo "<p class='delete'><strong>EXECUTING CLEANUP</strong> - Deleting lowercase location projects...</p>";
}

try {
    // Get all projects from facilities_master
    $stmt = $pdo->prepare("SELECT id, unique_name FROM facilities_master");
    $stmt->execute();
    $allProjects = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<h2>Scanning " . count($allProjects) . " projects in facilities_master...</h2>";
    
    $toDelete = [];
    $locationProjects = [];
    
    foreach ($allProjects as $project) {
        $name = $project['unique_name'];
        
        if (isLocationProject($name, $ALL_LOCATIONS)) {
            $locationProjects[] = $name;
            
            // Delete if it has any lowercase letters
            if (isLowercase($name)) {
                $toDelete[] = $project;
            }
        }
    }
    
    echo "<h3>Found " . count($locationProjects) . " location projects total:</h3>";
    echo "<pre>";
    foreach ($locationProjects as $loc) {
        if (isLowercase($loc)) {
            echo "<span class='delete'>❌ $loc (WILL DELETE - has lowercase)</span>\n";
        } else {
            echo "<span class='keep'>✓ $loc (keeping - all uppercase)</span>\n";
        }
    }
    echo "</pre>";
    
    echo "<h3>" . count($toDelete) . " projects to delete:</h3>";
    
    if (count($toDelete) === 0) {
        echo "<p class='keep'>✓ No lowercase location projects found! Nothing to delete.</p>";
    } else {
        echo "<pre>";
        foreach ($toDelete as $proj) {
            echo "<span class='delete'>DELETE: {$proj['unique_name']} (id: {$proj['id']})</span>\n";
        }
        echo "</pre>";
        
        if (!$dryRun) {
            // Actually delete them
            $deleteStmt = $pdo->prepare("DELETE FROM facilities_master WHERE id = :id");
            $deleted = 0;
            
            foreach ($toDelete as $proj) {
                $deleteStmt->execute([':id' => $proj['id']]);
                $deleted++;
                echo "<p class='delete'>Deleted: {$proj['unique_name']}</p>";
            }
            
            echo "<h2 class='keep'>✓ Deleted $deleted lowercase location projects!</h2>";
        } else {
            echo "<p class='info'>👆 Add <code>?run=1</code> to URL to delete these projects.</p>";
        }
    }
    
    // Also show any non-location projects that look suspicious
    echo "<h3>Other projects in facilities_master (not location names):</h3>";
    echo "<pre>";
    $otherCount = 0;
    foreach ($allProjects as $project) {
        if (!isLocationProject($project['unique_name'], $ALL_LOCATIONS)) {
            echo "<span class='keep'>{$project['unique_name']}</span>\n";
            $otherCount++;
        }
    }
    if ($otherCount === 0) {
        echo "<span class='info'>(none)</span>";
    }
    echo "</pre>";
    
} catch (PDOException $e) {
    echo "<p class='delete'>Database error: " . htmlspecialchars($e->getMessage()) . "</p>";
}

echo "</body></html>";
?>
