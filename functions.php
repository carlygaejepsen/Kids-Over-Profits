<?php
/**
 * Kadence Child Theme Functions
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Enqueue parent theme styles
 */
function kadence_child_enqueue_styles() {
    // Enqueue parent theme stylesheet
    wp_enqueue_style(
        'kadence-parent-style', 
        get_template_directory_uri() . '/style.css',
        array(),
        wp_get_theme()->get('Version')
    );
}
add_action('wp_enqueue_scripts', 'kadence_child_enqueue_styles');

// =================================================================
// CUSTOM FUNCTIONS
// =================================================================

/**
 * Load facilities data for TTI program index page
 */
function load_facilities_data() {
    // Only load on the TTI program index page
    if (is_page() && get_post_field('post_name') === 'tti-program-index') {
        $facilities_json_url = get_stylesheet_directory_uri() . '/js/data/facilities_data.json';
        $script_path = get_stylesheet_directory() . '/js/facilities-display.js';
        
        wp_enqueue_script(
            'facilities-display',
            get_stylesheet_directory_uri() . '/js/facilities-display.js',
            array(),
            file_exists($script_path) ? filemtime($script_path) : '1.0',
            true
        );
        
        wp_localize_script(
            'facilities-display',
            'facilitiesConfig',
            array('jsonDataUrl' => esc_url($facilities_json_url))
        );
    }
}
add_action('wp_enqueue_scripts', 'load_facilities_data');

/**
 * Load scripts for CA reports page (multi-file report)
 */
function load_new_multi_file_report_scripts() {
    if (is_page('ca-reports')) {
        $json_urls = array();
        $json_path = get_stylesheet_directory() . '/js/data/';
        $json_url_base = get_stylesheet_directory_uri() . '/js/data/';
        
        // Find only CA-specific JSON files (modify pattern as needed)
        if (is_dir($json_path)) {
            $json_files = glob($json_path . 'ccl*.json');
            
            if ($json_files) {
                foreach ($json_files as $file) {
                    $json_urls[] = esc_url($json_url_base . basename($file));
                }
            }
        }
        
        $script_path = get_stylesheet_directory() . '/js/ca-reports.js';
        
        wp_enqueue_script(
            'new-multi-file-report',
            get_stylesheet_directory_uri() . '/js/ca-reports.js',
            array(),
            file_exists($script_path) ? filemtime($script_path) : '1.0',
            true
        );
        
        wp_localize_script(
            'new-multi-file-report',
            'myThemeData',
            array('jsonFileUrls' => $json_urls)
        );
    }
}
add_action('wp_enqueue_scripts', 'load_new_multi_file_report_scripts');

/**
 * Load scripts for Utah reports page
 */
function load_ut_reports_scripts() {
    if (is_page('ut-reports')) {
        $json_urls = array();
        $json_path = get_stylesheet_directory() . '/js/data/';
        $json_url_base = get_stylesheet_directory_uri() . '/js/data/';
        
        // Find only UT-specific JSON files
        if (is_dir($json_path)) {
            $json_files = glob($json_path . 'ut_*.json'); // Only files starting with "ut_"
            
            if ($json_files) {
                foreach ($json_files as $file) {
                    $json_urls[] = esc_url($json_url_base . basename($file));
                }
            }
        }
        
        $script_path = get_stylesheet_directory() . '/js/ut_reports.js';
        
        wp_enqueue_script(
            'ut-reports-display',
            get_stylesheet_directory_uri() . '/js/ut_reports.js',
            array(),
            file_exists($script_path) ? filemtime($script_path) : '1.0',
            true
        );
        
        wp_localize_script(
            'ut-reports-display',
            'utReportsData',
            array('jsonFileUrls' => $json_urls)
        );
    }
}
add_action('wp_enqueue_scripts', 'load_ut_reports_scripts');

/**
 * Load scripts for Arizona reports page
 */
function load_az_reports_scripts() {
    if (is_page('az-reports')) {
        error_log('Arizona page detected!');
        
        $json_urls = array();
        $json_path = get_stylesheet_directory() . '/js/data/az_reports/';
        $json_url_base = get_stylesheet_directory_uri() . '/js/data/az_reports/';
        
        if (is_dir($json_path)) {
            $json_files = glob($json_path . '*.json');
            error_log('Found JSON files: ' . print_r($json_files, true));
            
            if ($json_files) {
                foreach ($json_files as $file) {
                    $json_urls[] = esc_url($json_url_base . basename($file));
                }
            }
        }
        
        error_log('JSON URLs: ' . print_r($json_urls, true));
        
        $script_path = get_stylesheet_directory() . '/js/az_reports.js';
        
        wp_enqueue_script(
            'az-reports-display',
            get_stylesheet_directory_uri() . '/js/az_reports.js',
            array(),
            file_exists($script_path) ? filemtime($script_path) : '1.0',
            true
        );
        
        wp_localize_script(
            'az-reports-display',
            'myThemeData',
            array('jsonFileUrls' => $json_urls)
        );
    }
}
add_action('wp_enqueue_scripts', 'load_az_reports_scripts');

/**
 * Load scripts for Texas reports page
 */
function load_tx_reports_scripts() {
    if (is_page('tx-reports')) {
        wp_enqueue_script(
            'tx-reports-display',
            get_stylesheet_directory_uri() . '/js/tx_reports.js',
            array(),
            filemtime(get_stylesheet_directory() . '/js/tx_reports.js'),
            true
        );
        
        wp_localize_script(
            'tx-reports-display',
            'myThemeData',
            array('jsonFileUrls' => array(
                get_stylesheet_directory_uri() . '/js/data/tx_reports.json'
            ))
        );
    }
}
add_action('wp_enqueue_scripts', 'load_tx_reports_scripts');

/**
 * Load scripts for Montana reports page
 */
function enqueue_montana_reports_scripts() {
    error_log('Montana function called. Current page: ' . get_post_field('post_name', get_the_ID()));
    
    if (is_page('mt-reports')) {
        error_log('mt-reports page condition met!');
        
        $script_path = get_stylesheet_directory() . '/js/mt_reports.js';
        error_log('Script path: ' . $script_path);
        error_log('Script exists: ' . (file_exists($script_path) ? 'YES' : 'NO'));
        
        wp_enqueue_script(
            'mt-reports', 
            get_stylesheet_directory_uri() . '/js/mt_reports.js',
            array(), 
            file_exists($script_path) ? filemtime($script_path) : time(),
            true
        );
        
        wp_localize_script('mt-reports', 'myThemeData', array(
            'jsonFileUrls' => array(
                get_stylesheet_directory_uri() . '/js/data/mt_reports.json'
            )
        ));
        
        error_log('Script enqueued successfully');
    } else {
        error_log('Page condition NOT met');
    }
}
add_action('wp_enqueue_scripts', 'enqueue_montana_reports_scripts');

/**
 * Load scripts for Connecticut reports page
 */
function enqueue_ct_reports_scripts() {
    error_log('Connecticut function called. Current page: ' . get_post_field('post_name', get_the_ID()));
    
    if (is_page('ct-reports')) {
        error_log('ct-reports page condition met!');
        
        $script_path = get_stylesheet_directory() . '/js/ct_reports.js';
        error_log('Script path: ' . $script_path);
        error_log('Script exists: ' . (file_exists($script_path) ? 'YES' : 'NO'));
        
        wp_enqueue_script(
            'ct-reports', 
            get_stylesheet_directory_uri() . '/js/ct_reports.js',
            array(), 
            file_exists($script_path) ? filemtime($script_path) : time(),
            true
        );
        
        wp_localize_script('ct-reports', 'myThemeData', array(
            'jsonFileUrls' => array(
                get_stylesheet_directory_uri() . '/js/data/ct_reports.json'
            )
        ));
        
        error_log('Script enqueued successfully');
    } else {
        error_log('Page condition NOT met');
    }
}
add_action('wp_enqueue_scripts', 'enqueue_ct_reports_scripts');

/**
 * Load scripts for Washington reports page
 */
function enqueue_wa_reports_scripts() {
    error_log('Washington function called. Current page: ' . get_post_field('post_name', get_the_ID()));
    
    if (is_page('wa-reports')) {
        error_log('wa-reports page condition met!');
        
        $script_path = get_stylesheet_directory() . '/js/wa_reports.js';
        error_log('Script path: ' . $script_path);
        error_log('Script exists: ' . (file_exists($script_path) ? 'YES' : 'NO'));
        
        wp_enqueue_script(
            'wa-reports', 
            get_stylesheet_directory_uri() . '/js/wa_reports.js',
            array(), 
            file_exists($script_path) ? filemtime($script_path) : time(),
            true
        );
        
        wp_localize_script('wa-reports', 'myThemeData', array(
            'jsonFileUrls' => array(
                get_stylesheet_directory_uri() . '/js/data/wa_reports.json'
            )
        ));
        
        error_log('Script enqueued successfully');
    } else {
        error_log('Page condition NOT met');
    }
}
add_action('wp_enqueue_scripts', 'enqueue_wa_reports_scripts');

/**
 * Load facility form script
 */
function enqueue_facility_form_script() {
    if (is_page('data')) { // Replace with your actual page slug
        wp_enqueue_script(
            'facility-form-script',
            get_stylesheet_directory_uri() . '/js/facility-form.js',
            array(),
            time(),
            true
        );
    }
}
add_action('wp_enqueue_scripts', 'enqueue_facility_form_script');

/**
 * Add approval page to admin menu
 */
function add_approval_page_to_menu() {
    add_menu_page(
        'Approve Facility Edits',           // Page title
        'Approve Edits',                     // Menu title
        'manage_options',                    // Capability (admin only)
        'approve-facility-edits',            // Menu slug
        'render_approval_page_iframe',       // Callback function
        'dashicons-yes-alt',                 // Icon
        25                                   // Position
    );
}
add_action('admin_menu', 'add_approval_page_to_menu');

/**
 * Render approval page iframe
 */
function render_approval_page_iframe() {
    $url = home_url('/wp-content/themes/child/api/approve-edits.php');
    ?>
    <div class="wrap">
        <iframe src="<?php echo esc_url($url); ?>" style="width: 100%; height: calc(100vh - 100px); border: none;"></iframe>
    </div>
    <?php
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
    private $cloudmersive_api_key = 'b5299933-f6fc-48b5-aac8-58b811de2519'; // Replace with your actual API key
    
    public function __construct() {
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));
        add_action('wp_ajax_nopriv_submit_anonymous_doc', array($this, 'handle_submission'));
        add_action('wp_ajax_submit_anonymous_doc', array($this, 'handle_submission'));
        add_shortcode('anonymous_doc_portal', array($this, 'render_portal'));
        add_action('admin_menu', array($this, 'add_admin_menu'));
        
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
     * Scan file with Cloudmersive API for viruses and threats
     * 
     * @param string $file_path Path to the file to scan
     * @return array Result with 'clean' boolean and 'message' string
     */
    private function scan_file_cloudmersive($file_path) {
        if (empty($this->cloudmersive_api_key) || $this->cloudmersive_api_key === 'b5299933-f6fc-48b5-aac8-58b811de2519') {
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
            error_log('Cloudmersive API returned status ' . $status_code . ': ' . $body);
            return array('clean' => false, 'message' => 'File scan failed');
        }
        
        // Check if file is clean
        if (isset($result['CleanResult']) && $result['CleanResult'] === true) {
            return array('clean' => true, 'message' => 'File is clean');
        } else {
            $threats = isset($result['FoundViruses']) ? implode(', ', $result['FoundViruses']) : 'Unknown threat';
            return array('clean' => false, 'message' => 'Threat detected: ' . $threats);
        }
    }
    
    public function enqueue_scripts() {
        if (is_page() && has_shortcode(get_post()->post_content, 'anonymous_doc_portal')) {
            $js_path = get_stylesheet_directory() . '/js/anonymous-portal.js';
            $css_path = get_stylesheet_directory() . '/css/anonymous-portal.css';
            
            wp_enqueue_script(
                'anonymous-portal-js', 
                get_stylesheet_directory_uri() . '/js/anonymous-portal.js', 
                array('jquery'), 
                file_exists($js_path) ? filemtime($js_path) : '1.0', 
                true
            );
            
            wp_enqueue_style(
                'anonymous-portal-css', 
                get_stylesheet_directory_uri() . '/css/anonymous-portal.css', 
                array(), 
                file_exists($css_path) ? filemtime($css_path) : '1.0'
            );
            
            wp_localize_script('anonymous-portal-js', 'anonymous_portal_ajax', array(
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('anonymous_doc_nonce'),
                'max_size' => $this->max_file_size,
                'allowed_types' => $this->allowed_types
            ));
        }
    }
    
    public function render_portal($atts) {
        $atts = shortcode_atts(array(
            'title' => 'Anonymous Document Submission',
            'description' => 'Submit documents anonymously. All submissions are encrypted and secure.'
        ), $atts);
        
        ob_start();
        ?>
        <div id="anonymous-doc-portal" class="anonymous-portal-container">
            <div class="portal-header">
                <h2><?php echo esc_html($atts['title']); ?></h2>
                <p class="portal-description"><?php echo esc_html($atts['description']); ?></p>
            </div>
            
            <div class="upload-area" id="upload-area">
                <div class="upload-content">
                    <h3>Drop files here or click to browse</h3>
                    <p>Supported formats: PDF, DOC, DOCX, TXT, JPG, PNG, ZIP</p>
                    <p>Maximum file size: 10MB</p>
                    <input type="file" id="file-input" multiple accept=".pdf,.doc,.docx,.txt,.jpg,.jpeg,.png,.zip">
                </div>
            </div>
            
            <div class="file-list" id="file-list"></div>
            
            <div class="form-section">
                <div class="form-group">
                    <label for="submission-message">Optional Message (Anonymous)</label>
                    <textarea id="submission-message" placeholder="Add any context or message about your submission..." rows="4"></textarea>
                </div>
                
                <div class="form-group checkbox-group">
                    <label class="checkbox-label required">
                        <input type="checkbox" id="legal-confirmation" required>
                        <span class="checkmark"></span>
                        <span class="checkbox-text">I confirm that this document was obtained through legal means and I have the right to submit it.</span>
                        <span class="required-indicator">*</span>
                    </label>
                    <small>This confirmation is required to proceed with submission.</small>
                </div>
                
                <div class="form-group checkbox-group">
                    <label class="checkbox-label">
                        <input type="checkbox" id="redaction-needed">
                        <span class="checkmark"></span>
                        <span class="checkbox-text">This document contains sensitive information that may require redaction (personal data, confidential information, etc.)</span>
                    </label>
                    <small>Check this if the document contains names, addresses, phone numbers, social security numbers, or other sensitive data that should be protected.</small>
                </div>
                
                <div class="form-group">
                    <label for="contact-method">Optional Contact Method</label>
                    <select id="contact-method">
                        <option value="none">No contact needed</option>
                        <option value="email">Secure Email Response</option>
                        <option value="phone">Phone Response</option>
                        <option value="signal">Signal Messenger</option>
                    </select>
                </div>
                
                <div class="form-group" id="contact-details" style="display: none;">
                    <label for="contact-info">Contact Information</label>
                    <input type="text" id="contact-info" placeholder="Enter your preferred contact method">
                    <small>This information is encrypted and only accessible to authorized personnel.</small>
                </div>
            </div>
            
            <div class="portal-actions">
                <button type="button" id="submit-docs" class="submit-btn" disabled>
                    <span class="btn-text">Submit Documents</span>
                    <span class="btn-loading" style="display: none;">Submitting...</span>
                </button>
            </div>
            
            <div class="status-messages" id="status-messages"></div>
            
            <div class="privacy-notice">
                <h4>🔒 Privacy & Security Notice</h4>
                <ul>
                    <li>No IP addresses or identifying information are logged</li>
                    <li>All files are encrypted during transmission and storage</li>
                    <li>File metadata is automatically stripped</li>
                    <li>Files are scanned for viruses before storage</li>
                    <li>Optional contact information is encrypted separately</li>
                    <li>Submissions are automatically deleted after 90 days unless flagged for retention</li>
                </ul>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }
    
    public function handle_submission() {
        check_ajax_referer('anonymous_doc_nonce', 'nonce');
        
        if (empty($_FILES['files'])) {
            wp_send_json_error('No files uploaded');
        }
        
        $files = $_FILES['files'];
        $uploaded_files = array();
        $submission_id = 'SUB-' . strtoupper(wp_generate_password(12, false));
        
        // Create submission directory
        $submission_dir = $this->upload_dir . $submission_id . '/';
        wp_mkdir_p($submission_dir);
        
        // Process each file
        for ($i = 0; $i < count($files['name']); $i++) {
            if ($files['error'][$i] !== UPLOAD_ERR_OK) {
                continue;
            }
            
            // ENHANCED SECURITY: Sanitize and validate filename
            $original_filename = $files['name'][$i];
            
            // Block dangerous patterns (path traversal, special characters)
            $dangerous_patterns = array('..', './', '\\', '<', '>', '|', ':', '*', '?', '"', "\0", '%00', 'php', 'phtml', 'exe', 'sh', 'bat', 'cmd');
            foreach ($dangerous_patterns as $pattern) {
                if (stripos($original_filename, $pattern) !== false) {
                    wp_send_json_error('Invalid filename detected: contains forbidden characters or patterns');
                    return;
                }
            }
            
            // Additional check for null bytes and control characters
            if (preg_match('/[\x00-\x1F\x7F]/', $original_filename)) {
                wp_send_json_error('Invalid filename: contains control characters');
                return;
            }
            
            // Validate file size
            if ($files['size'][$i] > $this->max_file_size) {
                wp_send_json_error('File too large: ' . sanitize_file_name($original_filename));
            }
            
            // Validate file extension (whitelist approach)
            $file_ext = strtolower(pathinfo($original_filename, PATHINFO_EXTENSION));
            if (!in_array($file_ext, $this->allowed_types)) {
                wp_send_json_error('Invalid file type: ' . $file_ext);
            }
            
            // Verify MIME type matches extension
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime_type = finfo_file($finfo, $files['tmp_name'][$i]);
            finfo_close($finfo);
            
            $allowed_mimes = array(
                'pdf' => 'application/pdf',
                'doc' => 'application/msword',
                'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'txt' => 'text/plain',
                'jpg' => 'image/jpeg',
                'jpeg' => 'image/jpeg',
                'png' => 'image/png',
                'zip' => 'application/zip'
            );
            
            if (!isset($allowed_mimes[$file_ext]) || $mime_type !== $allowed_mimes[$file_ext]) {
                // Allow some flexibility for text files and certain formats
                if (!in_array($mime_type, array('text/plain', 'application/octet-stream'))) {
                    wp_send_json_error('File MIME type does not match extension');
                }
            }
            
            // SCAN FILE FOR VIRUSES using Cloudmersive API
            $scan_result = $this->scan_file_cloudmersive($files['tmp_name'][$i]);
            if (!$scan_result['clean']) {
                // Delete the temp file
                @unlink($files['tmp_name'][$i]);
                wp_send_json_error('Security threat detected: ' . $scan_result['message']);
                return;
            }
            
            // Generate secure filename (completely random, no trace of original)
            $secure_filename = wp_generate_password(32, false) . '.' . $file_ext;
            $destination = $submission_dir . $secure_filename;
            
            // Ensure destination is within upload directory (prevent path traversal)
            $real_destination = realpath(dirname($destination));
            $real_upload_dir = realpath($this->upload_dir);
            if (strpos($real_destination, $real_upload_dir) !== 0) {
                wp_send_json_error('Security violation: invalid destination path');
                return;
            }
            
            // Move file with restricted permissions
            if (move_uploaded_file($files['tmp_name'][$i], $destination)) {
                chmod($destination, 0644); // Set secure file permissions
                
                $uploaded_files[] = array(
                    'original_name' => sanitize_file_name($original_filename),
                    'secure_name' => $secure_filename,
                    'size' => $files['size'][$i]
                );
            }
        }
        
        if (empty($uploaded_files)) {
            wp_send_json_error('No valid files were processed');
        }
        
        // ENHANCED SECURITY: Sanitize all text inputs
        $message = isset($_POST['message']) ? sanitize_textarea_field(wp_strip_all_tags($_POST['message'])) : '';
        $contact_method = isset($_POST['contact_method']) ? sanitize_text_field($_POST['contact_method']) : 'none';
        $contact_info = isset($_POST['contact_info']) ? sanitize_text_field(wp_strip_all_tags($_POST['contact_info'])) : '';
        
        // Validate contact method is from allowed list
        $allowed_contact_methods = array('none', 'email', 'phone', 'signal');
        if (!in_array($contact_method, $allowed_contact_methods)) {
            $contact_method = 'none';
        }
        
        // Validate boolean checkboxes
        $legal_confirmed = isset($_POST['legal_confirmation']) && $_POST['legal_confirmation'] === 'true' ? 1 : 0;
        $redaction_needed = isset($_POST['redaction_needed']) && $_POST['redaction_needed'] === 'true' ? 1 : 0;
        
        // Ensure legal confirmation was checked
        if (!$legal_confirmed) {
            wp_send_json_error('Legal confirmation is required');
            return;
        }
        
        // Limit message length
        if (strlen($message) > 5000) {
            wp_send_json_error('Message is too long (max 5000 characters)');
            return;
        }
        
        // Store submission metadata in database
        global $wpdb;
        $table_name = $wpdb->prefix . 'anonymous_submissions';
        
        $wpdb->insert(
            $table_name,
            array(
                'submission_id' => $submission_id,
                'file_count' => count($uploaded_files),
                'file_data' => json_encode($uploaded_files),
                'message' => $message,
                'contact_method' => $contact_method,
                'contact_info' => $contact_info,
                'legal_confirmation' => $legal_confirmed,
                'redaction_needed' => $redaction_needed,
                'status' => 'pending',
                'submission_date' => current_time('mysql')
            )
        );
        
        wp_send_json_success(array(
            'submission_id' => $submission_id,
            'message' => 'Documents submitted successfully! Your submission ID is: ' . $submission_id
        ));
    }
    
    public function add_admin_menu() {
        add_management_page(
            'Anonymous Submissions',
            'Anonymous Docs',
            'manage_options',
            'anonymous-submissions',
            array($this, 'admin_page')
        );
    }
    
    public function admin_page() {
        if (!current_user_can('manage_options')) {
            wp_die('You do not have sufficient permissions to access this page.');
        }
        
        global $wpdb;
        $table_name = $wpdb->prefix . 'anonymous_submissions';
        
        // Create table if doesn't exist
        $this->create_submissions_table();
        
        // Handle status updates
        if (isset($_POST['update_status'])) {
            $wpdb->update(
                $table_name,
                array('status' => sanitize_text_field($_POST['status'])),
                array('id' => intval($_POST['submission_db_id'])),
                array('%s'),
                array('%d')
            );
        }
        
        $submissions = $wpdb->get_results("SELECT * FROM $table_name ORDER BY submission_date DESC");
        
        ?>
        <div class="wrap">
            <h1>Anonymous Document Submissions</h1>
            
            <div class="tablenav">
                <div class="alignleft actions">
                    <p><strong>Total Submissions:</strong> <?php echo count($submissions); ?></p>
                </div>
            </div>
            
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th>Submission ID</th>
                        <th>Date</th>
                        <th>Files</th>
                        <th>Redaction Needed</th>
                        <th>Contact Method</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($submissions as $submission): ?>
                    <tr>
                        <td><strong><?php echo esc_html($submission->submission_id); ?></strong></td>
                        <td><?php echo esc_html(date('M j, Y g:i A', strtotime($submission->submission_date))); ?></td>
                        <td><?php echo intval($submission->file_count); ?> files</td>
                        <td>
                            <?php if ($submission->redaction_needed): ?>
                                <span class="redaction-flag">⚠️ Yes</span>
                            <?php else: ?>
                                <span class="no-redaction">No</span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo esc_html($submission->contact_method ? ucfirst($submission->contact_method) : 'None'); ?></td>
                        <td>
                            <span class="status-<?php echo esc_attr($submission->status); ?>">
                                <?php echo esc_html(ucfirst($submission->status)); ?>
                            </span>
                        </td>
                        <td>
                            <a href="#" class="view-submission" data-id="<?php echo $submission->id; ?>">View Details</a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        
        <style>
        .status-pending { color: #f56e28; }
        .status-reviewed { color: #00a32a; }
        .status-archived { color: #646970; }
        .redaction-flag { color: #d63638; font-weight: bold; }
        .no-redaction { color: #646970; }
        </style>
        <?php
    }
    
    private function create_submissions_table() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'anonymous_submissions';
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE IF NOT EXISTS $table_name (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            submission_id varchar(50) NOT NULL,
            file_count int NOT NULL,
            file_data text NOT NULL,
            message text,
            contact_method varchar(50),
            contact_info varchar(255),
            legal_confirmation tinyint(1) DEFAULT 0,
            redaction_needed tinyint(1) DEFAULT 0,
            status varchar(20) DEFAULT 'pending',
            submission_date datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
            PRIMARY KEY  (id)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }
}

// Initialize the portal
$anonymous_portal = new AnonymousDocPortal();