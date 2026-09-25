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
 * - File type validation (extension whitelist) and size limit
 * - Encryption on arrival: each file and its notes are sealed with the
 *   portal's public key (libsodium sealed box) before anything is written
 *   to disk. The server never holds the private key, so it cannot read a
 *   submission back; the owner downloads the .sealed file from wp-admin and
 *   opens it locally with scripts/anon-portal-decrypt.php.
 *
 * The public key lives in inc/anonymous-portal-public.key (made by
 * scripts/anon-portal-keygen.php) or the KOP_ANON_PORTAL_PUBLIC_KEY
 * constant. Without a usable key the portal refuses uploads rather than
 * store anything unencrypted.
 */

class AnonymousDocPortal {

    /** Submission ids: uniqid('sub_') now, SUB-2025-XXXXXXXX from the October 2025 portal. */
    const ID_PATTERN = 'sub_[0-9a-f]+|SUB-\d{4}-[A-Z0-9]+';

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
        add_action('admin_post_kop_anon_download', array($this, 'handle_admin_download'));
        add_action('admin_post_kop_anon_encrypt_existing', array($this, 'handle_encrypt_existing'));

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
     * The portal's public key (32 raw bytes), or '' when none is configured
     * or sodium is missing. Callers must refuse to store anything then.
     */
    private function public_key() {
        if (!function_exists('sodium_crypto_box_seal')) {
            return '';
        }
        $b64 = '';
        if (defined('KOP_ANON_PORTAL_PUBLIC_KEY') && KOP_ANON_PORTAL_PUBLIC_KEY !== '') {
            $b64 = KOP_ANON_PORTAL_PUBLIC_KEY;
        } else {
            $path = __DIR__ . '/anonymous-portal-public.key';
            if (is_readable($path)) {
                $b64 = (string) file_get_contents($path);
            }
        }
        $key = base64_decode(trim($b64), true);
        return (is_string($key) && strlen($key) === SODIUM_CRYPTO_BOX_PUBLICKEYBYTES) ? $key : '';
    }

    /**
     * Does the type detected from a file's contents fit its extension?
     * Office files are ZIP (docx) or OLE (doc) containers underneath, and
     * libmagic names those several ways, so each extension lists them all.
     */
    public static function mime_matches($ext, $mime) {
        $ok = array(
            'pdf'  => array('application/pdf'),
            'jpg'  => array('image/jpeg'),
            'jpeg' => array('image/jpeg'),
            'png'  => array('image/png'),
            'txt'  => array('text/plain'),
            'zip'  => array('application/zip', 'application/x-zip-compressed'),
            'docx' => array('application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip'),
            'doc'  => array('application/msword', 'application/x-ole-storage', 'application/CDFV2', 'application/vnd.ms-office'),
        );
        $mime = strtolower((string) $mime);
        if ($ext === 'txt') {
            // libmagic calls plain text text/csv, text/x-c and so on.
            return strpos($mime, 'text/') === 0;
        }
        return isset($ok[$ext]) && in_array($mime, array_map('strtolower', $ok[$ext]), true);
    }

    /** Short fingerprint of the public key, to check which key is in use. */
    private function key_fingerprint($public_key) {
        return $public_key === '' ? '' : substr(hash('sha256', $public_key), 0, 16);
    }

    /**
     * Seal one submission: a JSON header (original name, notes, date) and the
     * file bytes, in one sealed box. Layout inside the box:
     * "KOPANON1" . uint32 big-endian header length . header JSON . file bytes.
     * scripts/anon-portal-decrypt.php reads the same layout.
     */
    private function seal_submission($public_key, $file_bytes, array $meta) {
        $header = wp_json_encode($meta);
        $plain  = 'KOPANON1' . pack('N', strlen($header)) . $header . $file_bytes;
        $sealed = sodium_crypto_box_seal($plain, $public_key);
        sodium_memzero($plain);
        return $sealed;
    }

    /**
     * Scan file with Cloudmersive API for viruses and threats
     *
     * @param string $file_path Path to the file to scan
     * @return array Result with 'clean' boolean and 'message' string
     */
    private function scan_file_cloudmersive($file_path) {
        if (empty($this->cloudmersive_api_key)) {
            // No key means no scan, and an unscanned file is not clean.
            error_log('Cloudmersive API key not configured for file scanning');
            return array('clean' => false, 'message' => 'Scanning service unavailable');
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
                    'uploading' => __('Uploading and encrypting...', 'kadence-child'),
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
                <p>Submit documents anonymously. Files are scanned for malware, then encrypted the moment they reach our server, together with your notes. Only the site owner holds the key that opens them.</p>
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
                        <span class="dashicons dashicons-shield"></span> Encrypted on arrival
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

        // 0. Refuse outright when there is no key to encrypt with; never
        // store a submission unencrypted.
        $public_key = $this->public_key();
        if ($public_key === '') {
            error_log('Anonymous portal: no usable public key (inc/anonymous-portal-public.key) or sodium missing; upload refused');
            wp_send_json_error(array('message' => 'The document drop is temporarily unavailable. Please try again later.'));
        }

        // Likewise without a malware scanner: the owner opens these files on
        // their own computer, so nothing unscanned is accepted.
        if (empty($this->cloudmersive_api_key)) {
            error_log('Anonymous portal: CLOUDMERSIVE_API_KEY not configured; upload refused');
            wp_send_json_error(array('message' => 'The document drop is temporarily unavailable. Please try again later.'));
        }

        // 1. Validate File Type: the extension must be allowed, and the
        // file's contents must match it (a renamed .exe is not a .pdf).
        $file_ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($file_ext, $this->allowed_types, true)) {
            wp_send_json_error(array('message' => 'Invalid file type.'));
        }
        $file_mime = function_exists('mime_content_type') ? (string) mime_content_type($file['tmp_name']) : '';
        if (!self::mime_matches($file_ext, $file_mime)) {
            error_log("Anonymous portal: .{$file_ext} upload rejected, contents are {$file_mime}");
            wp_send_json_error(array('message' => 'The file\'s contents do not match its type (.' . $file_ext . ').'));
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
        
        // 4. Seal the file and notes together. The original filename goes
        // inside the sealed box only; on disk the submission is just its id.
        $submission_id = uniqid('sub_');
        $file_bytes = file_get_contents($file['tmp_name']);
        if ($file_bytes === false) {
            wp_send_json_error(array('message' => 'Failed to store file.'));
        }
        $has_notes = !empty($_POST['doc_notes']);
        $sealed = $this->seal_submission($public_key, $file_bytes, array(
            'id'       => $submission_id,
            'name'     => sanitize_file_name($file['name']),
            'notes'    => $has_notes ? sanitize_textarea_field(wp_unslash($_POST['doc_notes'])) : '',
            'received' => gmdate('c'),
        ));
        sodium_memzero($file_bytes);

        // 5. Write only the sealed file; the plaintext upload is PHP's temp
        // file, which PHP removes when the request ends.
        if (file_put_contents($this->upload_dir . $submission_id . '.sealed', $sealed) !== false) {

            // 6. Internal notification (inc/submission-notify.php).
            // The portal is anonymous, so the mail carries the submission id,
            // the file type and the size and nothing else: not the original
            // filename, not the notes, not an address.
            if (function_exists('kop_notify_admins')) {
                $ext = strtolower((string) pathinfo($file['name'], PATHINFO_EXTENSION));
                kop_notify_admins('document', 'Anonymous document ' . $submission_id, admin_url('admin.php?page=anonymous-docs'), array(
                    'File type' => $ext !== '' ? $ext : 'unknown',
                    'Size'      => size_format((int) $file['size']),
                    'Notes'     => $has_notes ? 'included (encrypted with the file)' : 'none',
                ));
            }

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

    /**
     * Submissions stored before encryption existed, grouped by id:
     * id => array(original name => stored filename). Each is one document
     * plus, optionally, a sub_x_notes.txt. Two namings: sub_<hex>_<name>
     * (2026) and SUB-2025-<code>_<random>.<ext> (the October 2025 portal).
     */
    private function plaintext_submissions() {
        $groups = array();
        foreach (array_diff((array) scandir($this->upload_dir), array('.', '..', '.htaccess', 'index.php')) as $name) {
            if (substr($name, -7) === '.sealed' || !is_file($this->upload_dir . $name)) {
                continue;
            }
            if (preg_match('/^(' . self::ID_PATTERN . ')_(.+)$/', $name, $m)) {
                $groups[$m[1]][$m[2]] = $name;
            }
        }
        return $groups;
    }

    /**
     * Is $name a WordPress preview image of a PDF in the same submission
     * (report.pdf -> report-pdf.jpg, report-pdf-232x300.jpg)? Those are
     * renderings of the PDF, so migration deletes them instead of sealing
     * each one separately.
     */
    private static function is_pdf_preview($name, array $parts) {
        return preg_match('/^(.+)-pdf(?:-\d+x\d+)?\.jpg$/i', $name, $m) === 1
            && isset($parts[$m[1] . '.pdf']);
    }

    public function render_admin_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        $public_key = $this->public_key();
        $sealed = array();
        foreach ((array) scandir($this->upload_dir) as $name) {
            if (preg_match('/^(' . self::ID_PATTERN . ')\.sealed$/', $name)) {
                $sealed[] = $name;
            }
        }
        rsort($sealed);
        $plaintext = $this->plaintext_submissions();

        echo '<div class="wrap"><h1>Anonymous Submissions</h1>';

        if (isset($_GET['encrypted'])) {
            echo '<div class="notice notice-success"><p>' . esc_html((int) $_GET['encrypted']) . ' older submission(s) encrypted; their unencrypted copies were deleted.</p></div>';
        }

        if ($public_key === '') {
            echo '<div class="notice notice-error"><p><strong>No public key is configured, so the portal is refusing uploads.</strong> '
                . 'Run <code>scripts/anon-portal-keygen.php</code> and deploy <code>inc/anonymous-portal-public.key</code>.</p></div>';
        } else {
            if (empty($this->cloudmersive_api_key)) {
                echo '<div class="notice notice-error"><p><strong>Malware scanning is not configured (no CLOUDMERSIVE_API_KEY), so the portal is refusing uploads.</strong> '
                    . 'Add the key to the server&#8217;s <code>.env</code> or <code>wp-config.php</code>.</p></div>';
            }
            echo '<p>Uploads are sealed with public key <code>' . esc_html($this->key_fingerprint($public_key)) . '</code>. '
                . 'This server cannot open them. Download a file and open it on your own computer with '
                . '<code>scripts/anon-portal-decrypt.php</code> and your private key.</p>';
        }

        if (!empty($plaintext)) {
            echo '<div class="notice notice-warning"><p><strong>' . count($plaintext) . ' submission(s) from before encryption are stored unencrypted.</strong></p>';
            if ($public_key !== '') {
                echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
                echo '<input type="hidden" name="action" value="kop_anon_encrypt_existing">';
                wp_nonce_field('kop_anon_encrypt_existing');
                echo '<p><button type="submit" class="button button-primary">Encrypt them now and delete the unencrypted copies</button></p></form>';
            }
            echo '</div>';
        }

        echo '<table class="widefat fixed striped">';
        echo '<thead><tr><th>Submission</th><th>Size</th><th>Received</th><th>Actions</th></tr></thead>';
        echo '<tbody>';

        if (empty($sealed)) {
            echo '<tr><td colspan="4">No encrypted submissions yet.</td></tr>';
        } else {
            foreach ($sealed as $name) {
                $filepath = $this->upload_dir . $name;
                $url = wp_nonce_url(admin_url('admin-post.php?action=kop_anon_download&file=' . rawurlencode($name)), 'kop_anon_download_' . $name);
                echo '<tr>';
                echo '<td><code>' . esc_html($name) . '</code></td>';
                echo '<td>' . esc_html(size_format(filesize($filepath))) . '</td>';
                echo '<td>' . esc_html(gmdate('Y-m-d H:i', filemtime($filepath))) . ' UTC</td>';
                echo '<td><a class="button button-small" href="' . esc_url($url) . '">Download (encrypted)</a></td>';
                echo '</tr>';
            }
        }

        echo '</tbody></table></div>';
    }

    /** Send one sealed file to an admin. It stays encrypted in transit and on their disk. */
    public function handle_admin_download() {
        $name = isset($_GET['file']) ? basename(wp_unslash($_GET['file'])) : '';
        if (!current_user_can('manage_options') || !preg_match('/^(' . self::ID_PATTERN . ')\.sealed$/', $name)) {
            wp_die('Not allowed.', 403);
        }
        check_admin_referer('kop_anon_download_' . $name);
        $path = $this->upload_dir . $name;
        if (!is_file($path)) {
            wp_die('Not found.', 404);
        }
        nocache_headers();
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $name . '"');
        header('Content-Length: ' . filesize($path));
        readfile($path);
        exit;
    }

    /**
     * Seal the submissions stored before encryption, then delete their
     * plaintext. A submission's plaintext is deleted only once every one of
     * its sealed files has been written in full.
     */
    public function handle_encrypt_existing() {
        if (!current_user_can('manage_options')) {
            wp_die('Not allowed.', 403);
        }
        check_admin_referer('kop_anon_encrypt_existing');
        $public_key = $this->public_key();
        if ($public_key === '') {
            wp_die('No public key is configured.');
        }

        $done = 0;
        foreach ($this->plaintext_submissions() as $id => $parts) {
            // A lone "notes.txt" is the document itself, not notes.
            $notes = '';
            $docs  = $parts;
            if (isset($parts['notes.txt']) && count($parts) > 1) {
                $notes = (string) file_get_contents($this->upload_dir . $parts['notes.txt']);
                unset($docs['notes.txt']);
            }
            foreach (array_keys($docs) as $orig) {
                if (self::is_pdf_preview($orig, $parts)) {
                    unset($docs[$orig]);
                }
            }

            $ok = true;
            $i  = 0;
            foreach ($docs as $orig => $stored) {
                $path  = $this->upload_dir . $stored;
                $bytes = file_get_contents($path);
                if ($bytes === false) {
                    $ok = false;
                    break;
                }
                $out_id = $i === 0 ? $id : $id . sprintf('%02d', $i);
                $sealed = $this->seal_submission($public_key, $bytes, array(
                    'id'       => $out_id,
                    'name'     => $orig,
                    'notes'    => $notes,
                    'received' => gmdate('c', filemtime($path)),
                ));
                sodium_memzero($bytes);
                $out = $this->upload_dir . $out_id . '.sealed';
                if (file_put_contents($out, $sealed) !== strlen($sealed)) {
                    $ok = false;
                    break;
                }
                touch($out, filemtime($path));
                $i++;
            }

            if ($ok) {
                foreach ($parts as $stored) {
                    unlink($this->upload_dir . $stored);
                }
                $done++;
            }
        }

        wp_safe_redirect(admin_url('admin.php?page=anonymous-docs&encrypted=' . $done));
        exit;
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
                                       class="doc-link nofancybox"
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
    // kop_filter_available_attachments() marks records whose own file is gone:
    // kop_live_source_id points at a copy that exists, kop_file_missing means
    // there is none and only an editor is seeing this tile.
    $source_id = !empty($attachment->kop_live_source_id)
        ? (int) $attachment->kop_live_source_id
        : (int) $attachment->ID;
    $missing = !empty($attachment->kop_file_missing);

    $file_url = $missing ? '' : wp_get_attachment_url($source_id);
    $file_type = wp_check_filetype($missing ? (string) get_post_meta($attachment->ID, '_wp_attached_file', true) : $file_url);
    $file_ext = strtoupper($file_type['ext']);
    $file_path = $missing ? '' : get_attached_file($source_id);
    $file_size = ($file_path && file_exists($file_path)) ? size_format(filesize($file_path)) : '';
    $preview_url = $missing ? '' : (function_exists('kop_get_attachment_preview_url')
        ? kop_get_attachment_preview_url($source_id, 'large')
        : wp_get_attachment_image_url($source_id, 'medium'));
    $has_preview = !empty($preview_url);
    $preview_class = ($file_type['ext'] === 'pdf') ? 'pdf-preview' : '';
    $display_title = function_exists('kop_title_case')
        ? kop_title_case($attachment->post_title)
        : $attachment->post_title;

    ob_start();

    if ($missing) {
        // Nothing to link to. Shown only to users who can edit, so the record
        // can be found and its file restored.
        ?>
        <li class="doc-item doc-item-missing" data-title="<?php echo esc_attr($display_title); ?>">
            <span class="doc-link doc-link-missing">
                <div class="doc-thumbnail">
                    <span class="doc-icon doc-icon-<?php echo esc_attr($file_type['ext']); ?>"><?php echo esc_html($file_ext); ?></span>
                </div>
                <div class="doc-info">
                    <span class="doc-title"><?php echo esc_html($display_title); ?></span>
                    <span class="doc-meta doc-meta-missing">File missing from the server</span>
                </div>
            </span>
        </li>
        <?php
        return ob_get_clean();
    }
    ?>
    <li class="doc-item" data-title="<?php echo esc_attr($display_title); ?>">
        <a href="<?php echo esc_url($file_url); ?>"
           class="doc-link nofancybox"
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
        $merged_from = !empty($node['merged_from']) ? $node['merged_from'] : '';
        $html .= '<details class="doc-subfolder' . ($merged_from !== '' ? ' doc-subfolder-merged' : '') . '"'
               . ($merged_from !== '' ? ' title="' . esc_attr('Documents filed under the linked folder "' . $merged_from . '"') . '"' : '')
               . '>'
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
               class="doc-link nofancybox" 
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
/**
 * Facility learn-more link for a story arc row, or null when the arc has no
 * facility_label. A custom facility_url (a dedicated profile page like /hyde)
 * wins; without one the button goes to the facility's profile page
 * (editorial post or generated /facility/<slug>/, inc/facility-pages.php)
 * when a record of that name has one, and otherwise to the location index
 * filtered to the facility name (the program index lists operators only).
 */
function kop_news_arc_facility_link(array $arc): ?array {
    $label = trim((string) ($arc['facility_label'] ?? ''));
    if ($label === '') {
        return null;
    }
    $url = trim((string) ($arc['facility_url'] ?? ''));
    if ($url === '' && function_exists('kop_facility_page_url_for_name')) {
        $url = (string) kop_facility_page_url_for_name($label);
    }
    if ($url === '') {
        $url = '/location-index/?search=' . rawurlencode($label);
    }
    return ['label' => $label, 'url' => $url];
}

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
        "SELECT a.id, a.title, a.slug, a.description, a.facility_label, a.facility_url,
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
                    <div class="ongoing-card-actions">
                        <a class="ongoing-card-viewall" href="<?php echo esc_url($arc_url); ?>">Full story &raquo;</a>
                        <?php $facility = kop_news_arc_facility_link($arc); if ($facility): ?>
                            <a class="ongoing-card-facility-btn" href="<?php echo esc_url($facility['url']); ?>">Learn more about <?php echo esc_html($facility['label']); ?></a>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        <div class="ongoing-stories-cta">
            <p>New developments are added daily from our monitoring system.</p>
            <a class="ongoing-stories-cta-btn" href="<?php echo esc_url($news_url); ?>">Visit the TTI News Feed</a>
        </div>
    </section>
    <?php
    return ob_get_clean();
}
add_shortcode('kop_ongoing_stories', 'kop_ongoing_stories_shortcode');
