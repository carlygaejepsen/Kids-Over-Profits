<?php
/**
 * Temporary file to clear PHP OpCache
 * Delete this file after use
 */

if (function_exists('opcache_reset')) {
    opcache_reset();
    echo "OpCache cleared successfully!<br>";
} else {
    echo "OpCache not available<br>";
}

echo "Timestamp: " . date('Y-m-d H:i:s');
?>
