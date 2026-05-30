<?php
/**
 * One-time diagnostic: tells us, from the server's own perspective, exactly what
 * inc/rest-api.php contains, whether OPcache is serving a stale compiled copy,
 * and then forces a recompile. Admin only. Delete after use.
 *
 * Visit: https://kidsoverprofits.org/wp-content/themes/kadence-child/api/diagnose-rest.php
 */

require_once __DIR__ . '/config.php'; // boots WordPress

header('Content-Type: text/plain');

if (!function_exists('current_user_can') || !current_user_can('administrator')) {
    http_response_code(403);
    echo "Administrator login required.\n";
    exit;
}

$file = get_stylesheet_directory() . '/inc/rest-api.php';

echo "FILE: $file\n";
echo "exists: " . (file_exists($file) ? 'yes' : 'NO') . "\n";
if (!file_exists($file)) { exit; }
echo "mtime:  " . date('Y-m-d H:i:s', filemtime($file)) . "\n";
echo "size:   " . filesize($file) . " bytes\n\n";

$content = file_get_contents($file);
$has_fix = strpos($content, 'start_year') !== false
        && strpos($content, '$end_year') !== false;
echo "Server file contains the start/end-year code: " . ($has_fix ? 'YES' : 'NO') . "\n\n";

$lines = file($file);
$idx = null;
foreach ($lines as $n => $l) {
    if (strpos($l, 'start_year !==') !== false) { $idx = $n; break; }
}
if ($idx !== null) {
    echo "--- context around line " . ($idx + 1) . " ---\n";
    for ($i = max(0, $idx - 4); $i < min(count($lines), $idx + 4); $i++) {
        echo ($i + 1) . ": " . $lines[$i];
    }
    echo "\n";
} else {
    echo "!! The range-building line was NOT found — the deployed file is OLDER than your repo.\n\n";
}

if (function_exists('opcache_get_status')) {
    $cfg = opcache_get_configuration();
    echo "opcache.enable:              " . var_export($cfg['directives']['opcache.enable'] ?? null, true) . "\n";
    echo "opcache.validate_timestamps: " . var_export($cfg['directives']['opcache.validate_timestamps'] ?? null, true) . "\n";
    echo "opcache.revalidate_freq:     " . var_export($cfg['directives']['opcache.revalidate_freq'] ?? null, true) . "\n";

    $st = @opcache_get_status(true);
    if (is_array($st) && !empty($st['scripts']) && isset($st['scripts'][$file])) {
        $s = $st['scripts'][$file];
        echo "rest-api.php IN opcache: YES\n";
        echo "  compiled/timestamp: " . date('Y-m-d H:i:s', $s['timestamp'] ?? 0) . "\n";
    } else {
        echo "rest-api.php IN opcache: not listed\n";
    }

    if (function_exists('opcache_invalidate')) {
        echo "opcache_invalidate(rest-api.php): " . var_export(opcache_invalidate($file, true), true) . "\n";
    }
    echo "opcache_reset(): " . var_export(opcache_reset(), true) . "\n";
} else {
    echo "OPcache functions not available in this SAPI.\n";
}

echo "\nDONE. Now reload …/wp-json/kop/v1/state/oregon and check Mount Bachelor.\n";
