<?php
/**
 * Deployment Health Check
 * Verifies all critical files are present after deployment
 */

$root = dirname(__DIR__);

$critical_files = [
    'functions.php',
    'style.css',
    '.cpanel.yml',
    'css/common.css',
    'css/layout.css',
    'css/forms.css',
    'js/theme-base-bootstrap.js',
    'js/facility-form.v3.js',
    'js/css-fallback-loader.js',
    'js/api-endpoint-resolver.js',
    'js/asset-health-check.js',
    'api/config-loader.php',
    'tests/asset-loading-test.php',
];

$critical_dirs = [
    'css',
    'js',
    'api',
    'api/data_form',
    'tests',
    'html',
];

$missing_files = [];
$missing_dirs = [];

foreach ($critical_files as $file) {
    $path = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $file);
    if (!file_exists($path)) {
        $missing_files[] = $file;
    }
}

foreach ($critical_dirs as $dir) {
    $path = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $dir);
    if (!is_dir($path)) {
        $missing_dirs[] = $dir;
    }
}

$has_errors = !empty($missing_files) || !empty($missing_dirs);

if ($has_errors) {
    echo "DEPLOYMENT HEALTH CHECK FAILED\n";
    echo str_repeat('=', 50) . "\n\n";

    if (!empty($missing_files)) {
        echo "Missing Files:\n";
        foreach ($missing_files as $file) {
            echo "  ✗ $file\n";
        }
        echo "\n";
    }

    if (!empty($missing_dirs)) {
        echo "Missing Directories:\n";
        foreach ($missing_dirs as $dir) {
            echo "  ✗ $dir\n";
        }
        echo "\n";
    }

    exit(1);
}

echo "DEPLOYMENT HEALTH CHECK PASSED\n";
echo str_repeat('=', 50) . "\n";

echo "All critical files and directories are present.\n\n";

$css_count = count(glob($root . '/css/*.css'));
$js_count  = count(glob($root . '/js/*.js'));
$php_count = count(glob($root . '/*.php'));

echo "File counts:\n";

echo "  CSS files: $css_count\n";

echo "  JS files: $js_count\n";

echo "  PHP files: $php_count\n";

echo "\n";
exit(0);
