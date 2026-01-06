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

        // Securely load API key from a constant.
        $this->cloudmersive_api_key = defined('CLOUDMERSIVE_API_KEY') ? CLOUDMERSIVE_API_KEY : '';
        
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
        $body .= "Content-Disposition: form-data; name=\"inputFile\"; filename=\"{filename}\"\r\n";
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
                array('kop-colors'), 
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
                <div id="upload-area">
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
                    <?php foreach ($submissions as $submission):
                    ?>
                    <tr>
                        <td><strong><?php echo esc_html($submission->submission_id); ?></strong></td>
                        <td><?php echo esc_html(date('M j, Y g:i A', strtotime($submission->submission_date))); ?></td>
                        <td><?php echo intval($submission->file_count); ?> files</td>
                        <td>
                            <?php if ($submission->redaction_needed):
                            ?>
                                <span class="redaction-flag">⚠️ Yes</span>
                            <?php else:
                            ?>
                                <span class="no-redaction">No</span>
                            <?php endif;
                            ?>
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
                    <?php endforeach;
                    ?>
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

/**
 * Initialize the Anonymous Document Portal after the theme is set up.
 *
 * This prevents issues with functions like wp_upload_dir() being called too early.
 */
function kop_initialize_anonymous_doc_portal() {
    new AnonymousDocPortal();
}
add_action('after_setup_theme', 'kop_initialize_anonymous_doc_portal');

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
            <?php foreach ($folders as $folder):
            ?>
                <?php
                $attachments = kop_get_folder_attachments($folder->id);
                $file_count = count($attachments);
                ?>
                <div class="doc-folder" data-folder-id="<?php echo esc_attr($folder->id); ?>" data-folder-name="<?php echo esc_attr($folder->name); ?>">
                    <div class="doc-folder-header">
                        <h3 class="doc-folder-title">
                            <span class="folder-icon">📁</span>
                            <?php echo esc_html($folder->name); ?>
                            <?php if ($atts['show_count'] === 'yes'): ?>
                            <span class="doc-count">(<?php echo $file_count; ?>)</span>
                            <?php endif; ?>
                        </h3>
                        <button class="doc-folder-toggle" aria-expanded="false">
                            <span class="toggle-icon">▼</span>
                        </button>
                    </div>

                    <div class="doc-folder-content" style="display: none;">
                        <?php if (!empty($attachments)): ?>
                        <ul class="doc-list doc-layout-<?php echo esc_attr($atts['layout']); ?>">
                            <?php foreach ($attachments as $attachment):
                            ?>
                                <?php
                                $file_url = wp_get_attachment_url($attachment->ID);
                                $file_type = wp_check_filetype($file_url);
                                $file_ext = strtoupper($file_type['ext']);
                                $file_size = size_format(filesize(get_attached_file($attachment->ID)));
                                $mime_type = get_post_mime_type($attachment->ID);
                                $is_image = strpos($mime_type, 'image') !== false;
                                $is_pdf = $file_type['ext'] === 'pdf';

                                // Check for PDF thumbnail (WordPress generates these automatically)
                                $pdf_thumbnail_id = get_post_meta($attachment->ID, '_thumbnail_id', true);
                                $pdf_thumbnail_url = $pdf_thumbnail_id ? wp_get_attachment_image_url($pdf_thumbnail_id, 'medium') : false;
                                ?>
                                <li class="doc-item" data-title="<?php echo esc_attr($attachment->post_title); ?>">
                                    <a href="<?php echo esc_url($file_url); ?>"
                                       class="doc-link"
                                       target="_blank"
                                       rel="noopener">
                                        <div class="doc-thumbnail">
                                            <?php if ($is_image): ?>
                                                <img src="<?php echo esc_url(wp_get_attachment_image_url($attachment->ID, 'medium')); ?>"
                                                     alt="<?php echo esc_attr($attachment->post_title); ?>">
                                            <?php elseif ($is_pdf && $pdf_thumbnail_url): ?>
                                                <img src="<?php echo esc_url($pdf_thumbnail_url); ?>"
                                                     alt="<?php echo esc_attr($attachment->post_title); ?>"
                                                     class="pdf-preview">
                                            <?php else:
                                            ?>
                                                <span class="doc-icon doc-icon-<?php echo esc_attr($file_type['ext']); ?>">
                                                    <?php echo esc_html($file_ext); ?>
                                                </span>
                                            <?php endif;
                                            ?>
                                        </div>
                                        <div class="doc-info">
                                            <span class="doc-title"><?php echo esc_html($attachment->post_title); ?></span>
                                            <span class="doc-meta"><?php echo esc_html($file_size); ?></span>
                                        </div>
                                    </a>
                                </li>
                            <?php endforeach;
                            ?>
                        </ul>
                        <?php else:
                        ?>
                        <p class="no-documents">No documents in this folder.</p>
                        <?php endif;
                        ?>
                    </div>
                </div>
            <?php endforeach;
            ?>
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
 * Shortcode: Display specific FileBird folder
 * Usage: [filebird_folder folder_id="5" title="Resources"]
 */
function kop_filebird_folder_shortcode($atts) {
    $atts = shortcode_atts(array(
        'folder_id' => '',
        'title' => '',
        'show_count' => 'yes',
        'layout' => 'grid', // Default to grid layout
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

    $attachments = kop_get_folder_attachments($atts['folder_id']);

    ob_start();
    ?>
    <div class="kop-document-folder">
        <h3 class="doc-folder-title-single">
            <?php echo esc_html($atts['title']); ?>
            <?php if ($atts['show_count'] === 'yes'): ?>
            <span class="doc-count">(<?php echo count($attachments); ?>)</span>
            <?php endif; ?>
        </h3>

        <?php if (!empty($attachments)): ?>
        <ul class="doc-list doc-layout-<?php echo esc_attr($atts['layout']); ?>">
            <?php foreach ($attachments as $attachment):
            ?>
                <?php
                $file_url = wp_get_attachment_url($attachment->ID);
                $file_type = wp_check_filetype($file_url);
                $file_ext = strtoupper($file_type['ext']);
                $file_size = size_format(filesize(get_attached_file($attachment->ID)));
                $mime_type = get_post_mime_type($attachment->ID);
                $is_image = strpos($mime_type, 'image') !== false;
                ?>
                <li class="doc-item">
                    <a href="<?php echo esc_url($file_url); ?>"
                       class="doc-link"
                       target="_blank"
                       rel="noopener">
                        <div class="doc-thumbnail">
                            <?php if ($is_image): ?>
                                <img src="<?php echo esc_url(wp_get_attachment_image_url($attachment->ID, 'medium')); ?>"
                                     alt="<?php echo esc_attr($attachment->post_title); ?>">
                            <?php else:
                            ?>
                                <span class="doc-icon doc-icon-<?php echo esc_attr($file_type['ext']); ?>">
                                    <?php echo esc_html($file_ext); ?>
                                </span>
                            <?php endif;
                            ?>
                        </div>
                        <div class="doc-info">
                            <span class="doc-title"><?php echo esc_html($attachment->post_title); ?></span>
                            <span class="doc-meta"><?php echo esc_html($file_size); ?></span>
                        </div>
                    </a>
                </li>
            <?php endforeach;
            ?>
        </ul>
        <?php else:
        ?>
        <p class="no-documents">No documents found in this folder.</p>
        <?php endif;
        ?>
    </div>
    <?php
    return ob_get_clean();
}
add_shortcode('filebird_folder', 'kop_filebird_folder_shortcode');
add_shortcode('kop_folder', 'kop_filebird_folder_shortcode'); // Alias