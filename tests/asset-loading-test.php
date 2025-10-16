<?php
/**
 * Automated Asset Loading Tests
 * Verifies CSS, JS, and API endpoints are accessible
 */

if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(__DIR__) . '/');
}

if (!function_exists('add_action')) {
    function add_action($hook, $callback, $priority = 10, $accepted_args = 1) {
        // no-op in test environment
    }
}

if (!function_exists('untrailingslashit')) {
    function untrailingslashit($string) {
        return rtrim($string, "/\\");
    }
}

if (!function_exists('trailingslashit')) {
    function trailingslashit($string) {
        return rtrim($string, "/\\") . '/';
    }
}

if (!function_exists('esc_attr')) {
    function esc_attr($text) {
        return $text;
    }
}

if (!function_exists('esc_url')) {
    function esc_url($url) {
        return $url;
    }
}

if (!function_exists('esc_url_raw')) {
    function esc_url_raw($url) {
        return $url;
    }
}

if (!function_exists('wp_json_encode')) {
    function wp_json_encode($data) {
        return json_encode($data);
    }
}

if (!function_exists('get_stylesheet_directory_uri')) {
    function get_stylesheet_directory_uri() {
        return 'https://example.com/wp-content/themes/child';
    }
}

if (!function_exists('get_template_directory_uri')) {
    function get_template_directory_uri() {
        return 'https://example.com/wp-content/themes/kadence';
    }
}

if (!function_exists('get_stylesheet_uri')) {
    function get_stylesheet_uri() {
        return 'https://example.com/wp-content/themes/child/style.css';
    }
}

if (!function_exists('add_shortcode')) {
    function add_shortcode($tag, $callback) {
        // no-op
    }
}

if (!function_exists('wp_upload_dir')) {
    function wp_upload_dir() {
        return array(
            'basedir' => sys_get_temp_dir(),
        );
    }
}

if (!function_exists('wp_mkdir_p')) {
    function wp_mkdir_p($target) {
        if (!is_dir($target)) {
            return @mkdir($target, 0777, true);
        }
        return true;
    }
}

require_once dirname(__DIR__) . '/functions.php';

class AssetLoadingTest {
    private $errors = [];
    private $warnings = [];
    private $passes = 0;

    public function runAll() {
        echo "Kids Over Profits - Asset Loading Test Suite\n";
        echo str_repeat('=', 60) . "\n\n";

        $this->testCssFiles();
        $this->testJsFiles();
        $this->testApiEndpoints();
        $this->testThemeBaseResolution();

        $this->printResults();

        return empty($this->errors);
    }

    private function testCssFiles() {
        echo "Testing CSS Files...\n";

        $root = dirname(__DIR__);
        $css_files = glob($root . '/css/*.css');

        foreach ($css_files as $file) {
            $relative = str_replace($root . DIRECTORY_SEPARATOR, '', $file);
            $relative = str_replace('\\', '/', $relative);

            if (!file_exists($file)) {
                $this->addError("CSS file not found: $relative");
                continue;
            }

            if (filesize($file) === 0) {
                $this->addWarning("CSS file is empty: $relative");
                continue;
            }

            $content = file_get_contents($file);
            $open_braces = substr_count($content, '{');
            $close_braces = substr_count($content, '}');

            if ($open_braces !== $close_braces) {
                $this->addError("CSS syntax mismatch in $relative");
                continue;
            }

            $this->addPass("CSS file OK: $relative");
        }
    }

    private function testJsFiles() {
        echo "\nTesting JavaScript Files...\n";

        $root = dirname(__DIR__);
        $required_js = [
            'js/theme-base-bootstrap.js',
            'js/facility-form.v3.js',
            'js/app-logic.js',
            'js/utilities.js'
        ];

        foreach ($required_js as $relative) {
            $file = $root . '/' . str_replace('/', DIRECTORY_SEPARATOR, $relative);

            if (!file_exists($file)) {
                $this->addError("Required JS file not found: $relative");
                continue;
            }

            if (filesize($file) === 0) {
                $this->addError("JS file is empty: $relative");
                continue;
            }

            $this->addPass("JS file OK: $relative");
        }
    }

    private function testApiEndpoints() {
        echo "\nTesting API Endpoints...\n";

        $root = dirname(__DIR__);
        $endpoints = [
            'api/data_form/get-master-data.php',
            'api/data_form/get-autocomplete.php',
            'api/data_form/save-master.php',
            'api/data_form/save-suggestion.php'
        ];

        foreach ($endpoints as $relative) {
            $file = $root . '/' . str_replace('/', DIRECTORY_SEPARATOR, $relative);

            if (!file_exists($file)) {
                $this->addError("API endpoint not found: $relative");
                continue;
            }

            exec('php -l ' . escapeshellarg($file) . ' 2>&1', $output, $return_code);
            if ($return_code !== 0) {
                $this->addError("PHP syntax error in $relative: " . implode('\n', $output));
                continue;
            }

            $this->addPass("API endpoint OK: $relative");
        }
    }

    private function testThemeBaseResolution() {
        echo "\nTesting Theme Base Resolution...\n";

        if (!function_exists('get_stylesheet_directory_uri')) {
            $this->addWarning('WordPress not loaded, skipping theme base test');
            return;
        }

        $theme_uri = get_stylesheet_directory_uri();
        $normalized = kidsoverprofits_normalize_theme_base_uri($theme_uri);

        if (empty($normalized)) {
            $this->addError('Theme base normalization failed');
            return;
        }

        if (strpos($normalized, '/themes/') === false && strpos($normalized, '/wp-content/themes/') === false) {
            $this->addError("Invalid theme base: $normalized");
            return;
        }

        $this->addPass("Theme base resolved: $normalized");

        $aliases = kidsoverprofits_get_theme_base_aliases($theme_uri);
        if (count($aliases) < 1) {
            $this->addError('No theme base aliases generated');
            return;
        }

        $this->addPass('Theme base aliases generated: ' . count($aliases));
    }

    private function addError($message) {
        $this->errors[] = $message;
        echo "  ✗ FAIL: $message\n";
    }

    private function addWarning($message) {
        $this->warnings[] = $message;
        echo "  ⚠ WARN: $message\n";
    }

    private function addPass($message) {
        $this->passes++;
        echo "  ✓ PASS: $message\n";
    }

    private function printResults() {
        echo "\n" . str_repeat('=', 60) . "\n";
        echo "Test Results:\n";
        echo "  Passed:   {$this->passes}\n";
        echo "  Warnings: " . count($this->warnings) . "\n";
        echo "  Errors:   " . count($this->errors) . "\n";

        if (empty($this->errors)) {
            echo "\n✅ ALL TESTS PASSED\n";
        } else {
            echo "\n❌ TESTS FAILED\n";
            echo "\nErrors:\n";
            foreach ($this->errors as $error) {
                echo "  - $error\n";
            }
        }

        echo str_repeat('=', 60) . "\n";
    }
}

if (php_sapi_name() === 'cli') {
    $test = new AssetLoadingTest();
    $success = $test->runAll();
    exit($success ? 0 : 1);
}
