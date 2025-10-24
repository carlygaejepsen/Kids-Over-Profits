<?php
// Minimal test harness for load_facilities_data().
define('ABSPATH', true);

class WP_Error {
    protected $code;
    protected $message;

    public function __construct($code = '', $message = '', $data = '') {
        $this->code = $code;
        $this->message = $message;
    }

    public function get_error_message() {
        return $this->message;
    }

    public function get_error_code() {
        return $this->code;
    }
}

class wpdb {
    public $prefix = '';

    public function __construct(...$args) {}

    public function get_var($query) {
        return null;
    }

    public function prepare($query, ...$args) {
        return $args ? vsprintf($query, $args) : $query;
    }

    public function get_col($query) {
        return array();
    }

    public function get_results($query, $output = ARRAY_A) {
        return array();
    }
}

function apply_filters($hook, $value) {
    return $value;
}

function __($text, $domain = null) {
    return $text;
}

function esc_url($url) {
    return $url;
}

function esc_url_raw($url) {
    return $url;
}

function sanitize_text_field($text) {
    if (is_scalar($text)) {
        return trim((string) $text);
    }

    return '';
}

function current_time($type) {
    return '2025-10-24 00:00:00';
}

function is_wp_error($thing) {
    return $thing instanceof WP_Error;
}

function rest_ensure_response($data) {
    return $data;
}

function register_rest_route(...$args) {
    $GLOBALS['registered_routes'][] = $args;
}

$GLOBALS['actions'] = array();

function add_action($hook, $callback, $priority = 10, $accepted_args = 1) {
    $GLOBALS['actions'][] = compact('hook', 'callback', 'priority', 'accepted_args');
}

$GLOBALS['shortcodes'] = array();

function add_shortcode($tag, $callback) {
    $GLOBALS['shortcodes'][$tag] = $callback;
}

$GLOBALS['current_page_slug'] = 'tti-program-index';
$GLOBALS['mock_post'] = (object) array('post_name' => 'tti-program-index');
$GLOBALS['mock_queried'] = (object) array('post_name' => 'tti-program-index');

function is_page($pages = null) {
    $slug = $GLOBALS['current_page_slug'];

    if ($pages === null) {
        return !empty($slug);
    }

    if (is_array($pages)) {
        return in_array($slug, $pages, true);
    }

    return $slug === $pages;
}

function get_post() {
    return $GLOBALS['mock_post'];
}

function get_post_field($field, $post = null) {
    if ($field !== 'post_name') {
        return null;
    }

    if ($post && is_object($post) && isset($post->post_name)) {
        return $post->post_name;
    }

    return $GLOBALS['current_page_slug'];
}

function get_queried_object() {
    return $GLOBALS['mock_queried'];
}

function get_stylesheet_directory() {
    return __DIR__ . '/..';
}

function get_stylesheet_directory_uri() {
    return 'https://example.test/wp-content/themes/kop-child';
}

$GLOBALS['enqueued_scripts'] = array();
$GLOBALS['localized_scripts'] = array();

function wp_enqueue_script($handle, $src, $deps = array(), $ver = false, $in_footer = false) {
    $GLOBALS['enqueued_scripts'][$handle] = compact('src', 'deps', 'ver', 'in_footer');
}

function wp_localize_script($handle, $name, $data) {
    $GLOBALS['localized_scripts'][$handle] = array('name' => $name, 'data' => $data);
}

function rest_url($path = '') {
    return 'https://example.test/wp-json/' . ltrim($path, '/');
}

function wp_upload_dir() {
    $base = sys_get_temp_dir() . '/kop-facilities-test-uploads';

    if (!file_exists($base)) {
        @mkdir($base, 0777, true);
    }

    return array(
        'basedir' => $base,
        'baseurl' => 'https://example.test/wp-content/uploads'
    );
}

function wp_mkdir_p($dir) {
    if (file_exists($dir)) {
        return true;
    }

    return @mkdir($dir, 0777, true);
}

require_once __DIR__ . '/../functions.php';

function reset_globals_for_page($slug) {
    $GLOBALS['current_page_slug'] = $slug;
    $GLOBALS['mock_post']->post_name = $slug;
    $GLOBALS['mock_queried']->post_name = $slug;
    $GLOBALS['enqueued_scripts'] = array();
    $GLOBALS['localized_scripts'] = array();
}

// Test: script enqueues on the correct page.
reset_globals_for_page('tti-program-index');
load_facilities_data();

$enqueued = isset($GLOBALS['enqueued_scripts']['facilities-display']);
$localized = $GLOBALS['localized_scripts']['facilities-display']['data'] ?? array();

// Test: script does not enqueue on other pages.
reset_globals_for_page('about');
load_facilities_data();
$skipped = empty($GLOBALS['enqueued_scripts']);

$result = array(
    'enqueued_on_target' => $enqueued,
    'localized_config' => $localized,
    'skipped_on_other_page' => $skipped,
);

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
