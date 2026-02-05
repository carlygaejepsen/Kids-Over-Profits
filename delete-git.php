<?php
/**
 * Delete entire repository folder recursively
 * Upload this to /home/kidsover/repositories/
 * Then visit: https://kidsoverprofits.org/repositories/delete-repo.php
 * DELETE THIS FILE AFTER USE!
 */

function deleteDirectory($dir) {
    if (!file_exists($dir)) {
        return true;
    }
    
    if (!is_dir($dir)) {
        return unlink($dir);
    }
    
    foreach (scandir($dir) as $item) {
        if ($item == '.' || $item == '..') {
            continue;
        }
        
        if (!deleteDirectory($dir . DIRECTORY_SEPARATOR . $item)) {
            return false;
        }
    }
    
    return rmdir($dir);
}

// The repository folder to delete - adjust name if needed
$repoPath = __DIR__ . '/kids-over-profits';

echo "<h1>Repository Deletion</h1>";
echo "<p>Target: " . htmlspecialchars($repoPath) . "</p>";

if (!file_exists($repoPath)) {
    echo "<p style='color:orange;'>⚠ Repository folder does not exist. Check the name.</p>";
    echo "<p>Available folders in " . htmlspecialchars(__DIR__) . ":</p><ul>";
    foreach (scandir(__DIR__) as $item) {
        if ($item != '.' && $item != '..' && is_dir(__DIR__ . '/' . $item)) {
            echo "<li>" . htmlspecialchars($item) . "</li>";
        }
    }
    echo "</ul>";
} else {
    echo "<p>Deleting repository folder and all contents...</p>";
    
    if (deleteDirectory($repoPath)) {
        echo "<p style='color:green;'>✓ Successfully deleted repository folder!</p>";
    } else {
        echo "<p style='color:red;'>✗ Failed to delete. Check permissions.</p>";
    }
}

echo "<hr>";
echo "<p><strong>IMPORTANT:</strong> Delete this file now for security!</p>";
