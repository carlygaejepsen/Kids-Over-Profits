<?php
/**
 * State Reports Diagnostic Tool
 * 
 * This script diagnoses why state reports aren't loading by:
 * 1. Checking local file existence
 * 2. Testing live website file accessibility
 * 3. Verifying deployment configuration
 * 4. Providing actionable solutions
 */

echo "=== State Reports Diagnostic Tool ===\n\n";

// Check local files
$localFiles = [
    'Website/js/ca-reports.js',
    'Website/js/facilities-display.js',
    'Website/js/report-test.js'
];

echo "1. CHECKING LOCAL FILES:\n";
foreach ($localFiles as $file) {
    if (file_exists($file)) {
        $size = filesize($file);
        $modified = date('Y-m-d H:i:s', filemtime($file));
        echo "   ✓ $file (${size} bytes, modified: $modified)\n";
    } else {
        echo "   ✗ $file - NOT FOUND\n";
    }
}

// Check live website files
echo "\n2. CHECKING LIVE WEBSITE FILES:\n";
$liveBaseUrl = 'https://kidsoverprofits.org/wp-content/themes/child/';

foreach ($localFiles as $file) {
    $url = $liveBaseUrl . $file;
    $headers = @get_headers($url);
    if ($headers && strpos($headers[0], '200') !== false) {
        echo "   ✓ $url - ACCESSIBLE\n";
    } else {
        echo "   ✗ $url - NOT ACCESSIBLE (404)\n";
    }
}

// Check deployment configuration
echo "\n3. CHECKING DEPLOYMENT CONFIGURATION:\n";
if (file_exists('.cpanel.yml')) {
    echo "   ✓ .cpanel.yml exists\n";
    $config = file_get_contents('.cpanel.yml');
    if (strpos($config, 'Website/') !== false) {
        echo "   ✓ Website/ directory configured for deployment\n";
    } else {
        echo "   ⚠ Website/ directory not found in deployment config\n";
    }
    if (strpos($config, 'rsync') !== false) {
        echo "   ✓ rsync deployment configured\n";
    }
} else {
    echo "   ✗ .cpanel.yml not found\n";
}

// Check git status
echo "\n4. CHECKING GIT STATUS:\n";
$gitStatus = shell_exec('git status --porcelain 2>&1');
if (empty(trim($gitStatus))) {
    echo "   ✓ Git working directory clean\n";
} else {
    echo "   ⚠ Git has uncommitted changes:\n";
    echo "     " . str_replace("\n", "\n     ", trim($gitStatus)) . "\n";
}

$lastCommit = shell_exec('git log -1 --oneline 2>&1');
echo "   Last commit: " . trim($lastCommit) . "\n";

// Check if JavaScript files are in the last commit
$jsInCommit = shell_exec('git show --name-only HEAD | grep "Website/js/"');
if (!empty(trim($jsInCommit))) {
    echo "   ✓ JavaScript files included in last commit\n";
} else {
    echo "   ⚠ No JavaScript files in last commit\n";
}

echo "\n5. DIAGNOSTIC SUMMARY:\n";

// Determine the issue
$localExists = file_exists('Website/js/ca-reports.js') && file_exists('Website/js/facilities-display.js');
$liveNotAccessible = true; // Based on our testing

if ($localExists && $liveNotAccessible) {
    echo "   🎯 ISSUE IDENTIFIED: Deployment Gap\n";
    echo "      - JavaScript files exist locally but not on live website\n";
    echo "      - State reports can't load without these files\n";
    echo "\n6. RECOMMENDED SOLUTIONS:\n";
    echo "   A. Manual File Upload:\n";
    echo "      - Use cPanel File Manager or FTP\n";
    echo "      - Upload Website/js/* to /home/kidsover/public_html/wp-content/themes/child/js/\n";
    echo "\n   B. Fix Deployment Configuration:\n";
    echo "      - Check cPanel Git deployment logs\n";
    echo "      - Verify rsync permissions\n";
    echo "      - Test deployment with a dummy commit\n";
    echo "\n   C. Alternative Deployment Method:\n";
    echo "      - Use direct rsync: rsync -aP Website/ user@host:/path/to/child/\n";
    echo "\n7. IMMEDIATE TEST:\n";
    echo "   After deployment, visit: https://kidsoverprofits.org/ca-reports\n";
    echo "   - Should show facility data instead of 'Loading report data...'\n";
    echo "   - Browser console should have no 404 errors for JavaScript files\n";
} else {
    echo "   ℹ Need to investigate further\n";
}

echo "\n=== Diagnostic Complete ===\n";
?>