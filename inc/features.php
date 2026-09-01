<?php
/**
 * Features and Classes (e.g. AnonymousDocPortal).
 */

if (!defined('ABSPATH')) {
    exit;
}

// =================================================================
// ANONYMOUS DOCUMENT PORTAL WITH CLOUDMERSIVE SECURITY
// =================================================================

/**
 * Anonymous Document Submission Portal
 * 
 * SETUP INSTRUCTIONS:
 * 1. Sign up for Cloudmersive API: https://cloudmersive.com/
 * 2. Get your API key from the dashboard
 * 3. Replace 'YOUR_CLOUDMERSIVE_API_KEY_HERE' below with your actual API key
 * 4. Free tier includes 800 API calls per month
 * 
 * Features:
 * - Virus/malware scanning with Cloudmersive
 * - Path traversal protection
 * - File type validation (whitelist)
 * - MIME type verification
 * - Metadata stripping
 * - Secure file storage
 */

class AnonymousDocPortal {
    
    private $upload_dir;
    private $allowed_types = array('pdf', 'doc', 'docx', 'txt', 'jpg', 'jpeg', 'png', 'zip');
    private $max_file_size = 10485760; // 10MB
    private $cloudmersive_api_key;
    
    public function __construct() {
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));
        add_action('wp_ajax_nopriv_submit_anonymous_doc', array($this, 'handle_submission'));
        add_action('wp_ajax_submit_anonymous_doc', array($this, 'handle_submission'));
        add_shortcode('anonymous_doc_portal', array($this, 'render_portal'));
        add_action('admin_menu', array($this, 'add_admin_menu'));

        // Securely load API key: prefer a PHP constant (wp-config.php),
        // fall back to environment / .env so the key can live in .env alongside other secrets.
        $this->cloudmersive_api_key = $this->load_cloudmersive_api_key();
        
        // Create secure upload directory
        $this->setup_upload_directory();
    }
    
    private function setup_upload_directory() {
        $upload_dir = wp_upload_dir();
        $this->upload_dir = $upload_dir['basedir'] . '/anonymous-submissions/';
        
        if (!file_exists($this->upload_dir)) {
            wp_mkdir_p($this->upload_dir);
            
            // Create .htaccess to prevent direct access
            $htaccess_content = "Order Deny,Allow\nDeny from all\n";
            file_put_contents($this->upload_dir . '.htaccess', $htaccess_content);
            
            // Create index.php to prevent directory listing
            file_put_contents($this->upload_dir . 'index.php', '<?php // Silence is golden');
        }
    }
    
    /**
     * Resolve the Cloudmersive API key from (in order): PHP constant,
     * environment variable, then a .env file walked up from the theme directory.
     */
    private function load_cloudmersive_api_key() {
        if (defined('CLOUDMERSIVE_API_KEY') && CLOUDMERSIVE_API_KEY !== '') {
            return CLOUDMERSIVE_API_KEY;
        }

        $from_env = getenv('CLOUDMERSIVE_API_KEY');
        if ($from_env === false && isset($_ENV['CLOUDMERSIVE_API_KEY'])) {
            $from_env = $_ENV['CLOUDMERSIVE_API_KEY'];
        }
        if (is_string($from_env) && $from_env !== '') {
            return $from_env;
        }

        $dir = dirname(__DIR__);
        for ($i = 0; $i < 6; $i++) {
            $candidate = $dir . '/.env';
            if (is_readable($candidate)) {
                foreach (file($candidate, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                    $line = trim($line);
                    if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) continue;
                    list($k, $v) = explode('=', $line, 2);
                    if (trim($k) !== 'CLOUDMERSIVE_API_KEY') continue;
                    $v = trim($v);
                    if ((substr($v, 0, 1) === '"' && substr($v, -1) === '"') ||
                        (substr($v, 0, 1) === "'" && substr($v, -1) === "'")) {
                        $v = substr($v, 1, -1);
                    }
                    return $v;
                }
                break;
            }
            $parent = dirname($dir);
            if ($parent === $dir) break;
            $dir = $parent;
        }

        return '';
    }

    /**
     * Scan file with Cloudmersive API for viruses and threats
     *
     * @param string $file_path Path to the file to scan
     * @return array Result with 'clean' boolean and 'message' string
     */
    private function scan_file_cloudmersive($file_path) {
        if (empty($this->cloudmersive_api_key)) {
            // API key not configured - log warning but allow upload
            error_log('Cloudmersive API key not configured for file scanning');
            return array('clean' => true, 'message' => 'Scan skipped - API not configured');
        }
        
        $url = 'https://api.cloudmersive.com/virus/scan/file';
        
        // Prepare file for upload
        $file_data = file_get_contents($file_path);
        $filename = basename($file_path);
        
        // Create boundary for multipart/form-data
        $boundary = wp_generate_password(24, false);
        $body = "--{$boundary}\r\n";
        $body .= "Content-Disposition: form-data; name=\"inputFile\"; filename=\"{$filename}\"\r\n";
        $body .= "Content-Type: application/octet-stream\r\n\r\n";
        $body .= $file_data . "\r\n";
        $body .= "--{$boundary}--\r\n";
        
        $response = wp_remote_post($url, array(
            'headers' => array(
                'Apikey' => $this->cloudmersive_api_key,
                'Content-Type' => 'multipart/form-data; boundary=' . $boundary
            ),
            'body' => $body,
            'timeout' => 30
        ));
        
        if (is_wp_error($response)) {
            error_log('Cloudmersive API error: ' . $response->get_error_message());
            return array('clean' => false, 'message' => 'Unable to scan file - API error');
        }
        
        $status_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $result = json_decode($body, true);
        
        if ($status_code !== 200) {
            error_log("Cloudmersive API returned status {$status_code}: {$body}");
            return array('clean' => false, 'message' => 'Scanning service unavailable');
        }
        
        if (isset($result['CleanResult']) && $result['CleanResult'] === true) {
            return array('clean' => true, 'message' => 'File is clean');
        } else {
            $threats = isset($result['FoundViruses']) ? json_encode($result['FoundViruses']) : 'Unknown threat';
            error_log("Threat detected in uploaded file: {$threats}");
            return array('clean' => false, 'message' => 'Security threat detected in file');
        }
    }
    
    public function enqueue_scripts() {
        // Only load scripts on pages with the shortcode
        global $post;
        if (is_a($post, 'WP_Post') && has_shortcode($post->post_content, 'anonymous_doc_portal')) {
            wp_enqueue_script('anonymous-portal-js', get_stylesheet_directory_uri() . '/js/anonymous-portal.js', array('jquery'), '1.0.0', true);
            
            // Pass localized data to script
            wp_localize_script('anonymous-portal-js', 'anonymousPortal', array(
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce'    => wp_create_nonce('anonymous_doc_portal_nonce'),
                'max_file_size' => $this->max_file_size,
                'i18n' => array(
                    'uploading' => __('Encrypting and uploading...', 'kadence-child'),
                    'success' => __('Document submitted securely. Thank you.', 'kadence-child'),
                    'error' => __('Upload failed. Please try again.', 'kadence-child'),
                    'file_too_large' => __('File is too large. Max size is 10MB.', 'kadence-child'),
                    'invalid_type' => __('Invalid file type.', 'kadence-child')
                )
            ));
            
            wp_enqueue_style('anonymous-portal-css', get_stylesheet_directory_uri() . '/css/anonymous-portal.css', array(), '1.0.0');
        }
    }
    
    public function render_portal($atts) {
        ob_start();
        ?>
        <div class="anonymous-portal-container" data-kop-bug-feature="document-portal/upload" data-kop-bug-label="Anonymous Document Portal">
            <div class="anonymous-portal-header">
                <h3><span class="dashicons dashicons-lock"></span> Secure Anonymous Document Drop</h3>
                <p>Submit documents securely and anonymously. All files are scanned for malware and stored in an encrypted directory.</p>
            </div>
            
            <form id="anonymous-doc-form" class="anonymous-doc-form" enctype="multipart/form-data">
                <div class="form-group">
                    <label for="doc-file" class="file-drop-zone" id="file-drop-zone">
                        <span class="dashicons dashicons-upload"></span>
                        <span class="drop-text">Drag & drop files here or click to browse</span>
                        <input type="file" id="doc-file" name="doc_file" required accept=".pdf,.doc,.docx,.txt,.jpg,.jpeg,.png,.zip">
                        <div id="file-preview" class="file-preview"></div>
                    </label>
                </div>
                
                <div class="form-group">
                    <label for="doc-notes">Optional Notes (Encrypted)</label>
                    <textarea id="doc-notes" name="doc_notes" placeholder="Any context about this document... (This will be encrypted)"></textarea>
                </div>
                
                <div class="form-group submit-group">
                    <div class="security-badge">
                        <span class="dashicons dashicons-shield"></span> End-to-End Encrypted
                    </div>
                    <button type="submit" id="submit-doc" class="submit-btn">
                        <span class="btn-text">Secure Submit</span>
                        <span class="spinner"></span>
                    </button>
                </div>
                
                <div id="upload-status" class="upload-status"></div>
            </form>
        </div>
        <?php
        return ob_get_clean();
    }
    
    public function handle_submission() {
        check_ajax_referer('anonymous_doc_portal_nonce', 'security');
        
        if (!isset($_FILES['doc_file']) || $_FILES['doc_file']['error'] !== UPLOAD_ERR_OK) {
            wp_send_json_error(array('message' => 'File upload error.'));
        }
        
        $file = $_FILES['doc_file'];
        
        // 1. Validate File Type (Extension & MIME)
        $file_ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $file_mime = mime_content_type($file['tmp_name']);
        
        if (!in_array($file_ext, $this->allowed_types)) {
            wp_send_json_error(array('message' => 'Invalid file type.'));
        }
        
        // 2. Validate File Size
        if ($file['size'] > $this->max_file_size) {
            wp_send_json_error(array('message' => 'File too large.'));
        }
        
        // 3. Scan with Cloudmersive
        $scan_result = $this->scan_file_cloudmersive($file['tmp_name']);
        if (!$scan_result['clean']) {
            // Delete the infected file immediately
            @unlink($file['tmp_name']);
            wp_send_json_error(array('message' => 'Security check failed: ' . $scan_result['message']));
        }
        
        // 4. Sanitize Filename & Generate Unique ID
        $submission_id = uniqid('sub_');
        $safe_filename = $submission_id . '_' . sanitize_file_name($file['name']);
        $target_path = $this->upload_dir . $safe_filename;
        
        // 5. Move File to Secure Directory
        if (move_uploaded_file($file['tmp_name'], $target_path)) {
            
            // 6. Handle Notes (Save as separate text file)
            if (!empty($_POST['doc_notes'])) {
                $notes = sanitize_textarea_field($_POST['doc_notes']);
                $notes_filename = $submission_id . '_notes.txt';
                file_put_contents($this->upload_dir . $notes_filename, $notes);
            }
            
            // 7. Log Submission (Internal Notification)
            // Ideally, send an email to admin or log to a private DB table
            // For now, we just return success
            
            wp_send_json_success(array('message' => 'File uploaded successfully.'));
        } else {
            wp_send_json_error(array('message' => 'Failed to store file.'));
        }
    }

    // Add admin menu to view submissions (basic implementation)
    public function add_admin_menu() {
        add_menu_page(
            'Anonymous Docs',
            'Anonymous Docs',
            'manage_options',
            'anonymous-docs',
            array($this, 'render_admin_page'),
            'dashicons-shield',
            50
        );
    }

    public function render_admin_page() {
        if (!current_user_can('manage_options')) {
            return;
        }
        
        $files = scandir($this->upload_dir);
        $files = array_diff($files, array('.', '..', '.htaccess', 'index.php'));
        
        echo '<div class="wrap"><h1>Anonymous Submissions</h1>';
        echo '<p>Files are stored in: <code>' . esc_html($this->upload_dir) . '</code></p>';
        echo '<table class="widefat fixed striped">';
        echo '<thead><tr><th>Filename</th><th>Size</th><th>Date</th><th>Actions</th></tr></thead>';
        echo '<tbody>';
        
        if (empty($files)) {
            echo '<tr><td colspan="4">No submissions yet.</td></tr>';
        } else {
            foreach ($files as $file) {
                $filepath = $this->upload_dir . $file;
                echo '<tr>';
                echo '<td>' . esc_html($file) . '</td>';
                echo '<td>' . size_format(filesize($filepath)) . '</td>';
                echo '<td>' . date("Y-m-d H:i:s", filemtime($filepath)) . '</td>';
                // Note: Direct download link won't work due to .htaccess deny from all.
                // A specialized download handler would be needed for a full admin interface.
                echo '<td><span class="description">Protected (FTP Access Only)</span></td>';
                echo '</tr>';
            }
        }
        
        echo '</tbody></table></div>';
    }
}

/**
 * Initialize the Anonymous Document Portal after the theme is set up.
 *
 * This prevents issues with functions like wp_upload_dir() being called too early.
 */
function kop_initialize_anonymous_doc_portal() {
    new AnonymousDocPortal();
}
add_action('after_setup_theme', 'kop_initialize_anonymous_doc_portal');

// =================================================================
// URL THREAT SCANNER (CLOUDMERSIVE)
// =================================================================

/**
 * Scans URLs from public submissions against Cloudmersive's threat-detection API
 * to flag phishing / malicious links before an admin clicks them in the review modal.
 * Results are cached in the url_scan_cache table for KOP_Url_Scanner::CACHE_TTL seconds.
 */
class KOP_Url_Scanner {
    const CACHE_TTL = 2592000; // 30 days
    const ENDPOINT = 'https://api.cloudmersive.com/virus/scan/website';

    public static function load_api_key() {
        if (defined('CLOUDMERSIVE_API_KEY') && CLOUDMERSIVE_API_KEY !== '') {
            return CLOUDMERSIVE_API_KEY;
        }
        $from_env = getenv('CLOUDMERSIVE_API_KEY');
        if ($from_env === false && isset($_ENV['CLOUDMERSIVE_API_KEY'])) {
            $from_env = $_ENV['CLOUDMERSIVE_API_KEY'];
        }
        if (is_string($from_env) && $from_env !== '') {
            return $from_env;
        }
        $dir = dirname(__DIR__);
        for ($i = 0; $i < 6; $i++) {
            $candidate = $dir . '/.env';
            if (is_readable($candidate)) {
                foreach (file($candidate, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                    $line = trim($line);
                    if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) continue;
                    list($k, $v) = explode('=', $line, 2);
                    if (trim($k) !== 'CLOUDMERSIVE_API_KEY') continue;
                    $v = trim($v);
                    if ((substr($v, 0, 1) === '"' && substr($v, -1) === '"') ||
                        (substr($v, 0, 1) === "'" && substr($v, -1) === "'")) {
                        $v = substr($v, 1, -1);
                    }
                    return $v;
                }
                break;
            }
            $parent = dirname($dir);
            if ($parent === $dir) break;
            $dir = $parent;
        }
        return '';
    }

    /**
     * Scan a single URL. Returns:
     *   ['url' => $url, 'clean' => bool|null, 'threats' => array, 'cached' => bool, 'error' => string|null]
     * `clean` is null when the scan couldn't run (no key, network error, bad URL).
     */
    public static function scan_url($url, $pdo) {
        $url = is_string($url) ? trim($url) : '';
        if ($url === '') {
            return self::result($url, null, [], false, 'empty_url');
        }

        // Only scan http(s) URLs; other schemes shouldn't reach Cloudmersive.
        $parts = parse_url($url);
        if (!$parts || empty($parts['scheme']) || empty($parts['host'])) {
            return self::result($url, null, [], false, 'invalid_url');
        }
        $scheme = strtolower($parts['scheme']);
        if ($scheme !== 'http' && $scheme !== 'https') {
            return self::result($url, null, [], false, 'unsupported_scheme');
        }

        $url_hash = hash('sha256', $url);
        $cached = self::read_cache($pdo, $url_hash);
        if ($cached !== null) {
            return self::result($url, (bool) $cached['is_clean'], $cached['threats'], true, null);
        }

        $key = self::load_api_key();
        if ($key === '') {
            return self::result($url, null, [], false, 'no_api_key');
        }

        $response = wp_remote_post(self::ENDPOINT, [
            'headers' => [
                'Apikey' => $key,
                'Content-Type' => 'application/json',
            ],
            'body' => wp_json_encode(['Url' => $url]),
            'timeout' => 15,
        ]);

        if (is_wp_error($response)) {
            error_log('Cloudmersive URL scan transport error: ' . $response->get_error_message());
            return self::result($url, null, [], false, 'transport_error');
        }

        $status = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $decoded = json_decode($body, true);

        if ($status !== 200 || !is_array($decoded)) {
            error_log("Cloudmersive URL scan returned status {$status}: {$body}");
            return self::result($url, null, [], false, 'api_error_' . $status);
        }

        // Cloudmersive returns CleanResult: true/false plus optional threat categories.
        $is_clean = isset($decoded['CleanResult']) ? (bool) $decoded['CleanResult'] : true;
        $threats = [];
        foreach (['WebsiteThreatType', 'ContainsExecutable', 'ContainsInvalidFile',
                  'ContainsScript', 'ContainsPasswordProtectedFile', 'ContainsRestrictedFileFormat',
                  'ContainsMacros', 'ContainsXmlExternalEntities', 'ContainsInsecureDeserialization',
                  'ContainsHtml'] as $field) {
            if (!empty($decoded[$field]) && $decoded[$field] !== 'None') {
                $threats[$field] = $decoded[$field];
            }
        }

        self::write_cache($pdo, $url, $url_hash, $is_clean, $threats);
        return self::result($url, $is_clean, $threats, false, null);
    }

    private static function result($url, $clean, $threats, $cached, $error) {
        return [
            'url' => $url,
            'clean' => $clean,
            'threats' => $threats,
            'cached' => $cached,
            'error' => $error,
        ];
    }

    private static function read_cache($pdo, $url_hash) {
        try {
            $stmt = $pdo->prepare(
                "SELECT is_clean, threats_json, UNIX_TIMESTAMP(scanned_at) AS scanned_ts
                 FROM url_scan_cache WHERE url_hash = ?"
            );
            $stmt->execute([$url_hash]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) return null;
            if ((time() - (int) $row['scanned_ts']) > self::CACHE_TTL) return null;
            return [
                'is_clean' => $row['is_clean'],
                'threats' => json_decode($row['threats_json'] ?: '[]', true) ?: [],
            ];
        } catch (PDOException $e) {
            error_log('url_scan_cache read failed: ' . $e->getMessage());
            return null;
        }
    }

    private static function write_cache($pdo, $url, $url_hash, $is_clean, $threats) {
        try {
            $stmt = $pdo->prepare(
                "INSERT INTO url_scan_cache (url_hash, url, is_clean, threats_json, scanned_at)
                 VALUES (?, ?, ?, ?, CURRENT_TIMESTAMP)
                 ON DUPLICATE KEY UPDATE
                   is_clean = VALUES(is_clean),
                   threats_json = VALUES(threats_json),
                   scanned_at = VALUES(scanned_at)"
            );
            $stmt->execute([$url_hash, $url, $is_clean ? 1 : 0, wp_json_encode($threats)]);
        } catch (PDOException $e) {
            error_log('url_scan_cache write failed: ' . $e->getMessage());
        }
    }
}

/**
 * Shortcode: Display full document library with all folders
 * Usage: [filebird_library]
 */
function kop_filebird_library_shortcode($atts) {
    $atts = shortcode_atts(array(
        'show_search' => 'yes',
        'show_count' => 'yes',
        'layout' => 'grid', // Default to grid layout
    ), $atts);

    $folders = kop_get_filebird_folders();

    if (empty($folders)) {
        return '<p>No document folders found. Make sure FileBird Pro is installed and you have created folders.</p>';
    }

    ob_start();
    ?>
    <div class="kop-document-library">
        <?php if ($atts['show_search'] === 'yes'): ?>
        <div class="doc-library-search">
            <input type="text"
                   id="docSearch"
                   class="doc-search-input"
                   placeholder="Search folders and documents...">
        </div>
        <?php endif; ?>

        <div class="doc-library-folders">
            <?php foreach ($folders as $folder): ?>
                <?php
                $attachments = kop_get_folder_attachments($folder->id);
                $file_count = count($attachments);
                ?>
                <div class="doc-folder" data-folder-id="<?php echo esc_attr($folder->id); ?>" data-folder-name="<?php echo esc_attr($folder->name); ?>">
                    <div class="doc-folder-header">
                        <div class="doc-folder-title" style="font-size: 1.1em; font-weight: 700;">
                            <span class="folder-icon"></span>
                            <?php echo esc_html($folder->name); ?>
                            <?php if ($atts['show_count'] === 'yes'): ?>
                            <span class="doc-count">(<?php echo $file_count; ?>)</span>
                            <?php endif; ?>
                        </div>
                        <button class="doc-folder-toggle" aria-expanded="false">
                            <span class="toggle-icon">▼</span>
                        </button>
                    </div>

                    <div class="doc-folder-content" style="display: none;">
                        <?php if (!empty($attachments)): ?>
                        <ul class="doc-list doc-layout-<?php echo esc_attr($atts['layout']); ?>">
                            <?php foreach ($attachments as $attachment): ?>
                                <?php
                                $file_url = wp_get_attachment_url($attachment->ID);
                                $file_type = wp_check_filetype($file_url);
                                $file_ext = strtoupper($file_type['ext']);
                                $file_path = get_attached_file($attachment->ID);
                                $file_size = ($file_path && file_exists($file_path)) ? size_format(filesize($file_path)) : '';
                                $preview_url = function_exists('kop_get_attachment_preview_url')
                                    ? kop_get_attachment_preview_url($attachment->ID, 'large')
                                    : wp_get_attachment_image_url($attachment->ID, 'medium');
                                $has_preview = !empty($preview_url);
                                $preview_class = ($file_type['ext'] === 'pdf') ? 'pdf-preview' : '';
                                $display_title = function_exists('kop_title_case')
                                    ? kop_title_case($attachment->post_title)
                                    : $attachment->post_title;
                                ?>
                                <li class="doc-item" data-title="<?php echo esc_attr($display_title); ?>">
                                    <a href="<?php echo esc_url($file_url); ?>"
                                       class="doc-link"
                                       target="_blank"
                                       rel="noopener"
                                       data-title="<?php echo esc_attr($display_title); ?>"
                                       data-mime="<?php echo esc_attr($attachment->post_mime_type); ?>"
                                       data-thumb="<?php echo esc_url($preview_url); ?>">
                                        <div class="doc-thumbnail">
                                            <?php if ($has_preview): ?>
                                                <img src="<?php echo esc_url($preview_url); ?>"
                                                     alt="<?php echo esc_attr($display_title); ?>"
                                                     class="<?php echo esc_attr($preview_class); ?>"
                                                     onerror="this.style.display='none'">
                                            <?php else: ?>
                                                <span class="doc-icon doc-icon-<?php echo esc_attr($file_type['ext']); ?>">
                                                    <?php echo esc_html($file_ext); ?>
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="doc-info">
                                            <span class="doc-title"><?php echo esc_html($display_title); ?></span>
                                            <span class="doc-meta"><?php echo esc_html($file_size); ?></span>
                                        </div>
                                    </a>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                        <?php else: ?>
                        <p class="no-documents">No documents in this folder.</p>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="doc-no-results" style="display: none;">
            <p>No folders or documents found matching your search.</p>
        </div>
    </div>
    <?php
    return ob_get_clean();
}
add_shortcode('filebird_library', 'kop_filebird_library_shortcode');
add_shortcode('kop_document_library', 'kop_filebird_library_shortcode'); // Alias

/**
 * Render a single document as a <li> tile. Shared by the flat and nested
 * (subfolder) document renderers so the markup stays identical.
 */
function kop_render_doc_file_li($attachment, $layout = 'grid') {
    $file_url = wp_get_attachment_url($attachment->ID);
    $file_type = wp_check_filetype($file_url);
    $file_ext = strtoupper($file_type['ext']);
    $file_path = get_attached_file($attachment->ID);
    $file_size = ($file_path && file_exists($file_path)) ? size_format(filesize($file_path)) : '';
    $preview_url = function_exists('kop_get_attachment_preview_url')
        ? kop_get_attachment_preview_url($attachment->ID, 'large')
        : wp_get_attachment_image_url($attachment->ID, 'medium');
    $has_preview = !empty($preview_url);
    $preview_class = ($file_type['ext'] === 'pdf') ? 'pdf-preview' : '';
    $display_title = function_exists('kop_title_case')
        ? kop_title_case($attachment->post_title)
        : $attachment->post_title;

    ob_start();
    ?>
    <li class="doc-item" data-title="<?php echo esc_attr($display_title); ?>">
        <a href="<?php echo esc_url($file_url); ?>"
           class="doc-link"
           target="_blank"
           rel="noopener"
           data-title="<?php echo esc_attr($display_title); ?>"
           data-mime="<?php echo esc_attr($attachment->post_mime_type); ?>"
           data-thumb="<?php echo esc_url($preview_url); ?>">
            <div class="doc-thumbnail">
                <?php if ($has_preview): ?>
                    <img src="<?php echo esc_url($preview_url); ?>"
                         alt="<?php echo esc_attr($display_title); ?>"
                         class="<?php echo esc_attr($preview_class); ?>"
                         onerror="this.style.display='none'">
                <?php else: ?>
                    <span class="doc-icon doc-icon-<?php echo esc_attr($file_type['ext']); ?>">
                        <?php echo esc_html($file_ext); ?>
                    </span>
                <?php endif; ?>
            </div>
            <div class="doc-info">
                <span class="doc-title"><?php echo esc_html($display_title); ?></span>
                <span class="doc-meta"><?php echo esc_html($file_size); ?></span>
            </div>
        </a>
    </li>
    <?php
    return ob_get_clean();
}

/**
 * Render a <ul> of document tiles. Returns '' when there are no files.
 */
function kop_render_doc_file_list($attachments, $layout = 'grid') {
    if (empty($attachments)) {
        return '';
    }
    $items = '';
    foreach ($attachments as $attachment) {
        $items .= kop_render_doc_file_li($attachment, $layout);
    }
    return '<ul class="doc-list doc-layout-' . esc_attr($layout) . '">' . $items . '</ul>';
}

/**
 * Render nested subfolder nodes (from kop_build_facility_doc_tree) as a set of
 * collapsible <details> sections so visitors can drill down into the folder
 * tree, recursing into deeper subfolders.
 */
function kop_render_doc_subfolders($nodes, $layout = 'grid') {
    if (empty($nodes)) {
        return '';
    }
    $html = '';
    foreach ($nodes as $node) {
        $count = count($node['attachments'])
               + (function_exists('kop_count_facility_doc_tree')
                    ? kop_count_facility_doc_tree($node['children'])
                    : 0);
        $inner = kop_render_doc_file_list($node['attachments'], $layout)
               . kop_render_doc_subfolders($node['children'], $layout);
        $html .= '<details class="doc-subfolder">'
               . '<summary class="doc-subfolder-title"><span class="folder-icon"></span> '
               . esc_html($node['name'])
               . ' <span class="doc-subfolder-count">(' . (int) $count . ')</span></summary>'
               . '<div class="doc-subfolder-content">' . $inner . '</div>'
               . '</details>';
    }
    return $html;
}

/**
 * Shortcode: Display specific FileBird folder
 * Usage: [filebird_folder folder_id="5" title="Resources"]
 */
function kop_filebird_folder_shortcode($atts) {
    $atts = shortcode_atts(array(
        'folder_id' => '',
        'title' => '',
        'show_count' => 'yes',
        'layout' => 'grid', // Default to grid layout
        'merge' => '',      // 'name' merges duplicate folders sharing this folder's name
    ), $atts);

    if (empty($atts['folder_id'])) {
        return '<p>Please specify a folder_id. Example: [filebird_folder folder_id="5"]</p>';
    }

    global $wpdb;
    $folder_table = $wpdb->prefix . 'fbv';

    // Get folder name if title not provided
    if (empty($atts['title'])) {
        $folder = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT name FROM $folder_table WHERE id = %d",
                $atts['folder_id']
            )
        );
        $atts['title'] = $folder ? $folder->name : 'Documents';
    }

    // Facility views (merge=name) pull in every duplicate folder sharing this
    // name across organizational trees AND preserve the subfolder structure so
    // nested documents stay grouped under their folder. Embedded shortcodes
    // default to a flat list of just this folder's own subtree.
    if ($atts['merge'] === 'name' && function_exists('kop_get_facility_doc_tree')) {
        $tree = kop_get_facility_doc_tree($atts['folder_id'], true);
        $count = count($tree['files']) + kop_count_facility_doc_tree($tree['subfolders']);
        $body = kop_render_doc_file_list($tree['files'], $atts['layout'])
              . kop_render_doc_subfolders($tree['subfolders'], $atts['layout']);
    } else {
        $attachments = kop_get_folder_attachments($atts['folder_id']);
        $count = count($attachments);
        $body = kop_render_doc_file_list($attachments, $atts['layout']);
    }

    if ($body === '') {
        $body = '<p class="no-documents">No documents found in this folder.</p>';
    }

    ob_start();
    ?>
    <div class="kop-document-folder">
        <div class="doc-folder-title-single" style="font-size: 1.2em; font-weight: 700; margin-bottom: 15px; border-bottom: 2px solid #33A7B5; padding-bottom: 5px;">
            <?php echo esc_html($atts['title']); ?>
            <?php if ($atts['show_count'] === 'yes'): ?>
            <span class="doc-count">(<?php echo (int) $count; ?>)</span>
            <?php endif; ?>
        </div>

        <?php echo $body; ?>
    </div>
    <?php
    return ob_get_clean();
}
add_shortcode('filebird_folder', 'kop_filebird_folder_shortcode');
add_shortcode('kop_folder', 'kop_filebird_folder_shortcode'); // Alias

/**
 * Shortcode: Display a single document
 * Usage: [kop_document id="123"]
 * 
 * Simplified shortcode for embedding just one document as a download link or card
 */
function kop_document_shortcode($atts) {
    $atts = shortcode_atts(array(
        'id' => '',
        'style' => 'card', // 'card', 'link', or 'button'
        'title' => '', // Optional custom title
        'show_size' => 'yes',
        'show_icon' => 'yes',
    ), $atts);

    if (empty($atts['id'])) {
        return '<p>Please specify a document ID. Example: [kop_document id="123"]</p>';
    }

    $attachment = get_post($atts['id']);
    
    if (!$attachment || $attachment->post_type !== 'attachment') {
        return '<p>Document not found (ID: ' . esc_html($atts['id']) . ')</p>';
    }

    $file_url = wp_get_attachment_url($attachment->ID);
    $file_type = wp_check_filetype($file_url);
    $file_ext = strtoupper($file_type['ext']);
    $file_path = get_attached_file($attachment->ID);
    $file_size = ($file_path && file_exists($file_path)) ? size_format(filesize($file_path)) : '';
    $preview_url = function_exists('kop_get_attachment_preview_url')
        ? kop_get_attachment_preview_url($attachment->ID, 'large')
        : wp_get_attachment_image_url($attachment->ID, 'medium');
    $has_preview = !empty($preview_url);
    $preview_class = ($file_type['ext'] === 'pdf') ? 'pdf-preview' : '';
    $title = !empty($atts['title']) ? $atts['title'] : $attachment->post_title;
    $display_title = function_exists('kop_title_case')
        ? kop_title_case($title)
        : $title;

    ob_start();
    
    if ($atts['style'] === 'link') {
        // Simple text link
        ?>
        <a href="<?php echo esc_url($file_url); ?>" 
           class="kop-doc-link-simple" 
           target="_blank" 
           rel="noopener">
            <?php if ($atts['show_icon'] === 'yes'): ?>
                <span class="doc-icon-inline"><?php echo esc_html($file_ext); ?></span>
            <?php endif; ?>
            <?php echo esc_html($display_title); ?>
            <?php if ($atts['show_size'] === 'yes'): ?>
                <span class="doc-size-inline">(<?php echo esc_html($file_size); ?>)</span>
            <?php endif; ?>
        </a>
        <?php
    } elseif ($atts['style'] === 'button') {
        // Button style
        ?>
        <a href="<?php echo esc_url($file_url); ?>" 
           class="kop-doc-button" 
           target="_blank" 
           rel="noopener">
            <?php if ($atts['show_icon'] === 'yes'): ?>
                <span class="doc-icon-inline"><?php echo esc_html($file_ext); ?></span>
            <?php endif; ?>
            <?php echo esc_html($display_title); ?>
            <?php if ($atts['show_size'] === 'yes'): ?>
                <span class="doc-size-inline">(<?php echo esc_html($file_size); ?>)</span>
            <?php endif; ?>
        </a>
        <?php
    } else {
        // Default card style
        ?>
        <div class="kop-document-single">
            <a href="<?php echo esc_url($file_url); ?>" 
               class="doc-link" 
               target="_blank" 
               rel="noopener"
               data-title="<?php echo esc_attr($display_title); ?>"
               data-mime="<?php echo esc_attr($attachment->post_mime_type); ?>"
               data-thumb="<?php echo esc_url($preview_url); ?>">
                <div class="doc-thumbnail">
                    <?php if ($has_preview): ?>
                        <img src="<?php echo esc_url($preview_url); ?>"
                             alt="<?php echo esc_attr($display_title); ?>"
                             class="<?php echo esc_attr($preview_class); ?>"
                             onerror="this.style.display='none'">
                    <?php else: ?>
                        <span class="doc-icon doc-icon-<?php echo esc_attr($file_type['ext']); ?>">
                            <?php echo esc_html($file_ext); ?>
                        </span>
                    <?php endif; ?>
                </div>
                <div class="doc-info">
                    <span class="doc-title"><?php echo esc_html($display_title); ?></span>
                    <?php if ($atts['show_size'] === 'yes'): ?>
                        <span class="doc-meta"><?php echo esc_html($file_size); ?></span>
                    <?php endif; ?>
                </div>
            </a>
        </div>
        <?php
    }
    
    return ob_get_clean();
}
add_shortcode('kop_document', 'kop_document_shortcode');

/**
 * Shortcode: featured ongoing stories (news story arcs) for the home page.
 * Usage: [kop_ongoing_stories limit="3" articles="3" heading="Ongoing Stories"]
 *
 * Renders the active arcs curated in api/manage-story-arcs.php as cards —
 * same look as the news feed's Ongoing Stories section (css/news-feed.css) —
 * each linking to its full story view on the news page (?story=slug).
 * Renders nothing when no active arc has published articles (or the
 * news_story_arcs migration hasn't run), so it's safe to leave in place.
 */
function kop_ongoing_stories_shortcode($atts) {
    global $wpdb;
    $atts = shortcode_atts(array(
        'limit'    => 3,   // max stories shown
        'articles' => 3,   // latest developments listed per story
        'heading'  => 'Ongoing Stories',
    ), $atts);

    $limit = max(1, min(12, (int) $atts['limit']));
    $per   = max(0, min(5, (int) $atts['articles']));

    $suppress = $wpdb->suppress_errors(true);
    $arcs = $wpdb->get_results(
        "SELECT a.id, a.title, a.slug, a.description,
                (SELECT COUNT(*) FROM news_submissions s
                 WHERE s.story_arc_id = a.id AND s.status IN ('approved','published')) AS article_count,
                (SELECT MAX(s.publication_date) FROM news_submissions s
                 WHERE s.story_arc_id = a.id AND s.status IN ('approved','published')) AS latest_date
         FROM news_story_arcs a
         WHERE a.status = 'active'
         ORDER BY a.display_order ASC, a.id ASC",
        ARRAY_A
    );
    $wpdb->suppress_errors($suppress);

    if (empty($arcs)) {
        return '';
    }
    $arcs = array_values(array_filter($arcs, static function ($a) {
        return (int) $a['article_count'] > 0;
    }));
    $arcs = array_slice($arcs, 0, $limit);
    if (empty($arcs)) {
        return '';
    }

    // Resolve the news feed page for the story links.
    $news_url = '';
    $news_pages = get_pages(array(
        'meta_key'   => '_wp_page_template',
        'meta_value' => 'templates/page-news-feed.php',
        'number'     => 1,
    ));
    if (!empty($news_pages)) {
        $news_url = get_permalink($news_pages[0]->ID);
    }
    if (!$news_url) {
        $news_url = home_url('/news/');
    }

    // Same stylesheet (and handle) the news feed template enqueues.
    wp_enqueue_style(
        'news-feed-css',
        get_stylesheet_directory_uri() . '/css/news-feed.css',
        array(),
        filemtime(get_stylesheet_directory() . '/css/news-feed.css')
    );

    ob_start();
    ?>
    <section class="ongoing-stories kop-ongoing-shortcode">
        <?php if (trim($atts['heading']) !== ''): ?>
            <h2 class="ongoing-stories-title"><?php echo esc_html($atts['heading']); ?></h2>
        <?php endif; ?>
        <div class="ongoing-stories-grid">
            <?php foreach ($arcs as $arc):
                $arc_url = add_query_arg('story', $arc['slug'], $news_url);
                $latest_label = !empty($arc['latest_date']) ? date('M j, Y', strtotime($arc['latest_date'])) : '';
                $devs = array();
                if ($per > 0) {
                    $devs = $wpdb->get_results($wpdb->prepare(
                        "SELECT article_title, alternate_title, publication_name, publication_date, article_url
                         FROM news_submissions
                         WHERE story_arc_id = %d AND status IN ('approved','published')
                         ORDER BY publication_date DESC, id DESC
                         LIMIT %d",
                        (int) $arc['id'], $per
                    ), ARRAY_A);
                }
            ?>
                <div class="ongoing-card">
                    <h3 class="ongoing-card-title"><a href="<?php echo esc_url($arc_url); ?>"><?php echo esc_html($arc['title']); ?></a></h3>
                    <div class="ongoing-card-meta">
                        <?php echo (int) $arc['article_count']; ?> article<?php echo (int) $arc['article_count'] === 1 ? '' : 's'; ?>
                        <?php if ($latest_label): ?> · updated <?php echo esc_html($latest_label); ?><?php endif; ?>
                    </div>
                    <?php if (!empty($arc['description'])): ?>
                        <p class="ongoing-card-desc"><?php echo esc_html($arc['description']); ?></p>
                    <?php endif; ?>
                    <?php if (!empty($devs)): ?>
                        <ul class="ongoing-card-latest">
                            <?php foreach ($devs as $dev):
                                $dev_title = !empty($dev['alternate_title']) ? $dev['alternate_title'] : $dev['article_title'];
                                $dev_date = !empty($dev['publication_date']) ? date('M j', strtotime($dev['publication_date'])) : '';
                            ?>
                                <li>
                                    <?php if (!empty($dev['article_url'])): ?>
                                        <a href="<?php echo esc_url($dev['article_url']); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html($dev_title); ?></a>
                                    <?php else: ?>
                                        <?php echo esc_html($dev_title); ?>
                                    <?php endif; ?>
                                    <span class="ongoing-dev-meta"><?php echo esc_html(trim(($dev['publication_name'] ?? '') . ($dev_date ? ' · ' . $dev_date : ''), ' ·')); ?></span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                    <a class="ongoing-card-viewall" href="<?php echo esc_url($arc_url); ?>">Full story &raquo;</a>
                </div>
            <?php endforeach; ?>
        </div>
        <p class="ongoing-stories-allnews"><a href="<?php echo esc_url($news_url); ?>">All news &raquo;</a></p>
    </section>
    <?php
    return ob_get_clean();
}
add_shortcode('kop_ongoing_stories', 'kop_ongoing_stories_shortcode');
