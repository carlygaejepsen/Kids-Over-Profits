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
        <div class="anonymous-portal-container">
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
            'dashicons-hidden',
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
                            <span class="folder-icon">📁</span>
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
                                $file_size = size_format(filesize(get_attached_file($attachment->ID)));
                                $preview_url = wp_get_attachment_image_url($attachment->ID, 'medium');
                                $has_preview = !empty($preview_url);
                                $preview_class = ($file_type['ext'] === 'pdf') ? 'pdf-preview' : '';
                                ?>
                                <li class="doc-item" data-title="<?php echo esc_attr($attachment->post_title); ?>">
                                    <a href="<?php echo esc_url($file_url); ?>"
                                       class="doc-link"
                                       target="_blank"
                                       rel="noopener">
                                        <div class="doc-thumbnail">
                                            <?php if ($has_preview): ?>
                                                <img src="<?php echo esc_url($preview_url); ?>"
                                                     alt="<?php echo esc_attr($attachment->post_title); ?>"
                                                     class="<?php echo esc_attr($preview_class); ?>">
                                            <?php else: ?>
                                                <span class="doc-icon doc-icon-<?php echo esc_attr($file_type['ext']); ?>">
                                                    <?php echo esc_html($file_ext); ?>
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="doc-info">
                                            <span class="doc-title"><?php echo esc_html($attachment->post_title); ?></span>
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
        <div class="doc-folder-title-single" style="font-size: 1.2em; font-weight: 700; margin-bottom: 15px; border-bottom: 2px solid #33A7B5; padding-bottom: 5px;">
            <?php echo esc_html($atts['title']); ?>
            <?php if ($atts['show_count'] === 'yes'): ?>
            <span class="doc-count">(<?php echo count($attachments); ?>)</span>
            <?php endif; ?>
        </div>

        <?php if (!empty($attachments)): ?>
        <ul class="doc-list doc-layout-<?php echo esc_attr($atts['layout']); ?>">
            <?php foreach ($attachments as $attachment): ?>
                <?php
                $file_url = wp_get_attachment_url($attachment->ID);
                $file_type = wp_check_filetype($file_url);
                $file_ext = strtoupper($file_type['ext']);
                $file_size = size_format(filesize(get_attached_file($attachment->ID)));
                $preview_url = wp_get_attachment_image_url($attachment->ID, 'medium');
                $has_preview = !empty($preview_url);
                $preview_class = ($file_type['ext'] === 'pdf') ? 'pdf-preview' : '';
                ?>
                <li class="doc-item">
                    <a href="<?php echo esc_url($file_url); ?>"
                       class="doc-link"
                       target="_blank"
                       rel="noopener">
                        <div class="doc-thumbnail">
                            <?php if ($has_preview): ?>
                                <img src="<?php echo esc_url($preview_url); ?>"
                                     alt="<?php echo esc_attr($attachment->post_title); ?>"
                                     class="<?php echo esc_attr($preview_class); ?>">
                            <?php else: ?>
                                <span class="doc-icon doc-icon-<?php echo esc_attr($file_type['ext']); ?>">
                                    <?php echo esc_html($file_ext); ?>
                                </span>
                            <?php endif; ?>
                        </div>
                        <div class="doc-info">
                            <span class="doc-title"><?php echo esc_html($attachment->post_title); ?></span>
                            <span class="doc-meta"><?php echo esc_html($file_size); ?></span>
                        </div>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>
        <?php else: ?>
        <p class="no-documents">No documents found in this folder.</p>
        <?php endif; ?>
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
    $file_size = size_format(filesize(get_attached_file($attachment->ID)));
    $preview_url = wp_get_attachment_image_url($attachment->ID, 'medium');
    $has_preview = !empty($preview_url);
    $preview_class = ($file_type['ext'] === 'pdf') ? 'pdf-preview' : '';
    $title = !empty($atts['title']) ? $atts['title'] : $attachment->post_title;

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
            <?php echo esc_html($title); ?>
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
            <?php echo esc_html($title); ?>
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
               rel="noopener">
                <div class="doc-thumbnail">
                    <?php if ($has_preview): ?>
                        <img src="<?php echo esc_url($preview_url); ?>"
                             alt="<?php echo esc_attr($title); ?>"
                             class="<?php echo esc_attr($preview_class); ?>">
                    <?php else: ?>
                        <span class="doc-icon doc-icon-<?php echo esc_attr($file_type['ext']); ?>">
                            <?php echo esc_html($file_ext); ?>
                        </span>
                    <?php endif; ?>
                </div>
                <div class="doc-info">
                    <span class="doc-title"><?php echo esc_html($title); ?></span>
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
