ion<?php
/**
 * Clear LiteSpeed Cache for REST API endpoints
 * Access this to force cache purge: https://yourdomain.com/wp-content/themes/child/api/clear-cache.php
 */

// Output as text
header('Content-Type: text/plain');

echo "=== LiteSpeed Cache Purge Tool ===\n\n";

// Try to purge LiteSpeed Cache if available
if (class_exists('LiteSpeed_Cache_API')) {
    LiteSpeed_Cache_API::purge_all();
    echo "✓ LiteSpeed Cache purged successfully\n";
} else {
    echo "⚠ LiteSpeed Cache class not found (this is normal if running standalone)\n";
}

// Add cache-control headers to this response
header('X-LiteSpeed-Cache-Control: no-cache');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

echo "\n=== Manual Cache Clearing Instructions ===\n\n";
echo "1. In WordPress Admin, go to LiteSpeed Cache settings\n";
echo "2. Click 'Purge All' or 'Purge All - LSCache'\n";
echo "3. Also purge the following specific items:\n";
echo "   - REST API cache\n";
echo "   - Object cache\n";
echo "   - Browser cache\n\n";

echo "=== Direct Cache Purge URLs ===\n\n";
echo "You can also try accessing these with ?LSCWP_CTRL=PURGE appended:\n";
echo "- " . $_SERVER['HTTP_HOST'] . "/wp-json/kop/v1/facilities?LSCWP_CTRL=PURGE\n";
echo "- " . $_SERVER['HTTP_HOST'] . "/tti-program-index/?LSCWP_CTRL=PURGE\n\n";

echo "=== Check Your Current URL ===\n";
echo "Current server: " . $_SERVER['HTTP_HOST'] . "\n";
echo "Current time: " . date('Y-m-d H:i:s') . "\n\n";

echo "=== Test the REST API ===\n";
echo "Visit: https://" . $_SERVER['HTTP_HOST'] . "/wp-json/kop/v1/facilities\n";
echo "(This will show if your project appears in the database)\n\n";

// Try to read .htaccess to check cache rules
$htaccess_path = dirname(__DIR__) . '/.htaccess';
if (file_exists($htaccess_path)) {
    echo "=== LiteSpeed Cache Configuration Detected ===\n";
    echo "✓ .htaccess file found with LiteSpeed rules\n";
    
    $htaccess_content = file_get_contents($htaccess_path);
    if (strpos($htaccess_content, 'LSCACHE') !== false) {
        echo "✓ LiteSpeed Cache directives found in .htaccess\n";
    }
    
    // Check for REST API cache exceptions
    if (strpos($htaccess_content, 'wp-json') !== false) {
        echo "⚠ Found wp-json rules in .htaccess - REST API may be cached\n";
    }
} else {
    echo "⚠ No .htaccess file found\n";
}

echo "\n=== Done ===\n";
echo "If the problem persists after clearing cache, use validate-json.php to check the database.\n";
