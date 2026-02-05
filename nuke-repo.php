<?php
/**
 * Aggressive delete with permission fixing
 * Upload to /home/kidsover/public_html/
 * Visit: https://kidsoverprofits.org/nuke-repo.php
 */

set_time_limit(300);

function forceDelete($path) {
    if (!file_exists($path)) return true;
    
    @chmod($path, 0777);
    
    if (is_file($path) || is_link($path)) {
        return @unlink($path);
    }
    
    if (is_dir($path)) {
        $items = @scandir($path);
        if ($items === false) {
            @chmod($path, 0777);
            $items = @scandir($path);
        }
        
        foreach ($items as $item) {
            if ($item == '.' || $item == '..') continue;
            if (!forceDelete($path . '/' . $item)) {
                echo "Failed: $path/$item<br>";
            }
        }
        return @rmdir($path);
    }
    return false;
}

$target = '/home/kidsover/repositories/kids-over-profits';

echo "Nuking: $target<br><br>";
flush();

if (forceDelete($target)) {
    echo "<br><strong style='color:green'>SUCCESS - Repository deleted</strong>";
} else {
    echo "<br><strong style='color:red'>FAILED - Check errors above</strong>";
}

echo "<br><br>DELETE THIS FILE NOW!";
