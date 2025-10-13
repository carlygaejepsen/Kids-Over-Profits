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

/**
 * Force any lingering Kadence parent CSS handles to load from the child theme.
 * Some plugins or cached settings still enqueue assets with the parent path,
 * which results in 404s after renaming the theme directory. This filter rewrites
 * those URLs on the fly so they resolve correctly.
 */
function kop_rewrite_kadence_asset_urls($src) {
    if (empty($src)) {
        return $src;
    }

    $needles = array(
        '/wp-content/themes/kadence/css/' => 'css/',
        '/wp-content/themes/kadence/js/' => 'js/',
        '/wp-content/themes/kadence-child/css/' => 'css/',
        '/wp-content/themes/kadence-child/js/' => 'js/'
    );

    foreach ($needles as $needle => $subdir) {
        $pos = strpos($src, $needle);
        if ($pos !== false) {
            $relative = substr($src, $pos + strlen($needle));
            $src = trailingslashit(get_stylesheet_directory_uri()) . $subdir . ltrim($relative, '/');
            break;
        }
    }

    return $src;
}
add_filter('style_loader_src', 'kop_rewrite_kadence_asset_urls', 20, 1);
add_filter('script_loader_src', 'kop_rewrite_kadence_asset_urls', 20, 1);

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
            'myThemeData',
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

// =================================================================
// ANONYMOUS DOCUMENT PORTAL
// =================================================================

class AnonymousDocPortal {
    
    private $upload_dir;
    private $allowed_types = array('pdf', 'doc', 'docx', 'txt', 'jpg', 'jpeg', 'png', 'zip');
    private $max_file_size = 10485760; // 10MB
    
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
            'title' => '',
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
                        <option value="">No contact needed</option>
                        <option value="email">Secure Email Response</option>
                        <option value="phone">Phone Response</option>
                        <option value="portal">Check back on this portal</option>
                    </select>
                </div>
                
                <div class="form-group" id="contact-details" style="display: none;">
                    <label for="contact-info">Contact Information</label>
                    <input type="text" id="contact-info" placeholder="Enter your preferred contact method">
                    <small>This information is encrypted and only accessible to authorized personnel.</small>
                </div>
            </div>
            
            <div class="file-list" id="file-list"></div>
            
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
                    <li>Optional contact information is encrypted separately</li>
                    <li>Submissions are automatically deleted after 90 days unless flagged for retention</li>
                </ul>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }
    
    public function handle_submission() {
        // Verify nonce for security
        if (!wp_verify_nonce($_POST['nonce'], 'anonymous_doc_nonce')) {
            wp_send_json_error('Security check failed');
        }
        
        $response = array('success' => false, 'message' => '');
        
        try {
            // Generate unique submission ID
            $submission_id = $this->generate_submission_id();
            
            // Process uploaded files
            $uploaded_files = $this->process_uploaded_files($submission_id);
            
            if (empty($uploaded_files)) {
                throw new Exception('No valid files were uploaded');
            }
            
            // Save submission metadata (encrypted)
            $this->save_submission_metadata($submission_id, $uploaded_files, $_POST);
            
            // Log submission (minimal, no personal info)
            $this->log_submission($submission_id, count($uploaded_files));
            
            $response['success'] = true;
            $response['message'] = 'Documents submitted successfully. Your submission ID is: ' . $submission_id;
            $response['submission_id'] = $submission_id;
            
        } catch (Exception $e) {
            $response['message'] = $e->getMessage();
            error_log('Anonymous portal error: ' . $e->getMessage());
        }
        
        wp_send_json($response);
    }
    
    private function generate_submission_id() {
        return 'SUB-' . date('Y') . '-' . strtoupper(wp_generate_password(8, false));
    }
    
    private function process_uploaded_files($submission_id) {
        if (!isset($_FILES['files'])) {
            return array();
        }
        
        $uploaded_files = array();
        $files = $_FILES['files'];
        
        // Handle multiple file upload
        for ($i = 0; $i < count($files['name']); $i++) {
            if ($files['error'][$i] !== UPLOAD_ERR_OK) {
                continue;
            }
            
            $file_data = array(
                'name' => $files['name'][$i],
                'type' => $files['type'][$i],
                'tmp_name' => $files['tmp_name'][$i],
                'size' => $files['size'][$i]
            );
            
            $processed_file = $this->process_single_file($file_data, $submission_id);
            if ($processed_file) {
                $uploaded_files[] = $processed_file;
            }
        }
        
        return $uploaded_files;
    }
    
    private function process_single_file($file_data, $submission_id) {
        // Validate file type
        $file_ext = strtolower(pathinfo($file_data['name'], PATHINFO_EXTENSION));
        if (!in_array($file_ext, $this->allowed_types)) {
            throw new Exception('File type not allowed: ' . $file_ext);
        }
        
        // Validate file size
        if ($file_data['size'] > $this->max_file_size) {
            throw new Exception('File too large: ' . $file_data['name']);
        }
        
        // Additional MIME type validation
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
        
        if (function_exists('mime_content_type')) {
            $actual_mime = mime_content_type($file_data['tmp_name']);
            if (!in_array($actual_mime, $allowed_mimes)) {
                throw new Exception('Invalid file type detected: ' . $file_data['name']);
            }
        }
        
        // Generate secure filename
        $secure_filename = $submission_id . '_' . wp_generate_password(12, false) . '.' . $file_ext;
        $file_path = $this->upload_dir . $secure_filename;
        
        // Move and process file
        if (move_uploaded_file($file_data['tmp_name'], $file_path)) {
            // Strip metadata for security
            $this->strip_file_metadata($file_path, $file_ext);
            
            return array(
                'original_name' => sanitize_file_name($file_data['name']),
                'secure_name' => $secure_filename,
                'file_path' => $file_path,
                'file_size' => $file_data['size'],
                'file_type' => $file_ext
            );
        }
        
        return false;
    }
    
    private function strip_file_metadata($file_path, $file_ext) {
        // For images, strip EXIF data
        if (in_array($file_ext, array('jpg', 'jpeg'))) {
            if (function_exists('imagecreatefromjpeg')) {
                $image = imagecreatefromjpeg($file_path);
                if ($image) {
                    imagejpeg($image, $file_path, 90);
                    imagedestroy($image);
                }
            }
        } elseif ($file_ext === 'png') {
            if (function_exists('imagecreatefrompng')) {
                $image = imagecreatefrompng($file_path);
                if ($image) {
                    imagepng($image, $file_path);
                    imagedestroy($image);
                }
            }
        }
    }
    
    private function save_submission_metadata($submission_id, $files, $post_data) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'anonymous_submissions';
        
        // Create table if it doesn't exist
        $this->create_submissions_table();
        
        // Encrypt sensitive data with improved security
        $encrypted_message = $this->encrypt_data(sanitize_textarea_field($post_data['message'] ?? ''));
        $encrypted_contact = $this->encrypt_data(sanitize_text_field($post_data['contact_info'] ?? ''));
        
        $result = $wpdb->insert(
            $table_name,
            array(
                'submission_id' => $submission_id,
                'file_count' => count($files),
                'encrypted_message' => $encrypted_message,
                'contact_method' => sanitize_text_field($post_data['contact_method'] ?? ''),
                'encrypted_contact' => $encrypted_contact,
                'legal_confirmation' => isset($post_data['legal_confirmation']) ? 1 : 0,
                'redaction_needed' => isset($post_data['redaction_needed']) ? 1 : 0,
                'submission_date' => current_time('mysql'),
                'files_data' => wp_json_encode($files),
                'status' => 'pending'
            ),
            array('%s', '%d', '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s')
        );
        
        if ($result === false) {
            throw new Exception('Failed to save submission metadata');
        }
    }
    
    private function create_submissions_table() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'anonymous_submissions';
        
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE IF NOT EXISTS $table_name (
            id int(11) NOT NULL AUTO_INCREMENT,
            submission_id varchar(20) NOT NULL,
            file_count int(11) NOT NULL,
            encrypted_message longtext,
            contact_method varchar(20),
            encrypted_contact text,
            legal_confirmation tinyint(1) DEFAULT 0,
            redaction_needed tinyint(1) DEFAULT 0,
            submission_date datetime NOT NULL,
            files_data longtext,
            status varchar(20) DEFAULT 'pending',
            notes longtext,
            PRIMARY KEY (id),
            UNIQUE KEY submission_id (submission_id),
            INDEX status_idx (status),
            INDEX submission_date_idx (submission_date)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }
    
    private function encrypt_data($data) {
        if (empty($data)) return '';
        
        // Improved encryption with random IV
        $key = hash('sha256', wp_salt('SECURE_AUTH') . wp_salt('NONCE_SALT'));
        $iv = openssl_random_pseudo_bytes(16);
        $encrypted = openssl_encrypt($data, 'AES-256-CBC', $key, 0, $iv);
        
        if ($encrypted === false) {
            error_log('Encryption failed');
            return '';
        }
        
        return base64_encode($iv . $encrypted);
    }
    
    private function decrypt_data($encrypted_data) {
        if (empty($encrypted_data)) return '';
        
        $data = base64_decode($encrypted_data);
        if ($data === false) return '';
        
        $key = hash('sha256', wp_salt('SECURE_AUTH') . wp_salt('NONCE_SALT'));
        $iv = substr($data, 0, 16);
        $encrypted = substr($data, 16);
        
        $decrypted = openssl_decrypt($encrypted, 'AES-256-CBC', $key, 0, $iv);
        
        return $decrypted !== false ? $decrypted : '';
    }
    
    private function log_submission($submission_id, $file_count) {
        // Minimal logging - no personal information
        error_log("Anonymous submission: ID={$submission_id}, Files={$file_count}, Time=" . current_time('mysql'));
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
        
        // Handle status updates
        if (isset($_POST['update_status']) && wp_verify_nonce($_POST['_wpnonce'], 'update_submission_status')) {
            $wpdb->update(
                $table_name,
                array('status' => sanitize_text_field($_POST['status'])),
                array('id' => intval($_POST['submission_db_id'])),
                array('%s'),
                array('%d')
            );
            echo '<div class="notice notice-success"><p>Status updated successfully.</p></div>';
        }
        
        // Handle file deletion for cleanup
        if (isset($_POST['delete_files']) && wp_verify_nonce($_POST['_wpnonce'], 'delete_submission_files')) {
            $submission = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_name WHERE id = %d", intval($_POST['submission_db_id'])));
            if ($submission) {
                $files_data = json_decode($submission->files_data, true);
                if ($files_data) {
                    foreach ($files_data as $file) {
                        if (isset($file['file_path']) && file_exists($file['file_path'])) {
                            unlink($file['file_path']);
                        }
                    }
                }
                $wpdb->update(
                    $table_name,
                    array('status' => 'files_deleted'),
                    array('id' => intval($_POST['submission_db_id'])),
                    array('%s'),
                    array('%d')
                );
                echo '<div class="notice notice-success"><p>Files deleted successfully.</p></div>';
            }
        }
        
        $submissions = $wpdb->get_results("SELECT * FROM $table_name ORDER BY submission_date DESC LIMIT 100");
        
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
                        <th>Legal Status</th>
                        <th>Redaction</th>
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
                            <?php if ($submission->legal_confirmation): ?>
                                <span class="status-confirmed">✅ Confirmed</span>
                            <?php else: ?>
                                <span class="status-unconfirmed">❌ Not Confirmed</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($submission->redaction_needed): ?>
                                <span class="redaction-needed">⚠️ Required</span>
                            <?php else: ?>
                                <span class="redaction-none">✓ None needed</span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo esc_html($submission->contact_method ?: 'None'); ?></td>
                        <td>
                            <span class="status-<?php echo esc_attr($submission->status); ?>">
                                <?php echo esc_html(ucfirst(str_replace('_', ' ', $submission->status))); ?>
                            </span>
                        </td>
                        <td>
                            <details>
                                <summary>Actions</summary>
                                <div class="submission-actions">
                                    <form method="post" style="display: inline;">
                                        <?php wp_nonce_field('update_submission_status'); ?>
                                        <input type="hidden" name="submission_db_id" value="<?php echo $submission->id; ?>">
                                        <select name="status">
                                            <option value="pending" <?php selected($submission->status, 'pending'); ?>>Pending</option>
                                            <option value="reviewed" <?php selected($submission->status, 'reviewed'); ?>>Reviewed</option>
                                            <option value="archived" <?php selected($submission->status, 'archived'); ?>>Archived</option>
                                        </select>
                                        <input type="submit" name="update_status" value="Update" class="button button-small">
                                    </form>
                                    
                                    <?php if ($submission->encrypted_message): ?>
                                        <button type="button" class="button button-small view-message" data-message="<?php echo esc_attr($this->decrypt_data($submission->encrypted_message)); ?>">View Message</button>
                                    <?php endif; ?>
                                    
                                    <?php if ($submission->status !== 'files_deleted'): ?>
                                        <form method="post" style="display: inline;" onsubmit="return confirm('Are you sure you want to delete all files for this submission?');">
                                            <?php wp_nonce_field('delete_submission_files'); ?>
                                            <input type="hidden" name="submission_db_id" value="<?php echo $submission->id; ?>">
                                            <input type="submit" name="delete_files" value="Delete Files" class="button button-small button-link-delete">
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </details>
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
        .status-files_deleted { color: #dc3232; }
        .status-confirmed { color: #00a32a; font-weight: 600; }
        .status-unconfirmed { color: #dc3232; font-weight: 600; }
        .redaction-needed { color: #f56e28; font-weight: 600; }
        .redaction-none { color: #00a32a; }
        .submission-actions { padding: 10px; }
        .submission-actions form { margin: 5px 0; }
        .view-message { margin-left: 5px; }
        </style>
        
        <script>
        document.addEventListener('DOMContentLoaded', function() {
            document.querySelectorAll('.view-message').forEach(function(button) {
                button.addEventListener('click', function() {
                    var message = this.getAttribute('data-message');
                    alert('Submission Message:\n\n' + message);
                });
            });
        });
        </script>
        <?php
    }
}

// Initialize the portal
$anonymous_portal = new AnonymousDocPortal();

//TEXAS REPORTS
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
function enqueue_facilities_script() {
    // Enqueue the facilities JavaScript
    wp_enqueue_script(
        'tti-program-index', 
        get_stylesheet_directory_uri() . '/js/facilities-display.js', 
        array(), 
        '1.0.0', 
        true
    );
}
add_action('wp_enqueue_scripts', 'enqueue_facilities_script');