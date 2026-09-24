# Anonymous Portal

The **Anonymous Portal** lets whistleblowers, survivors and staff send a document to the Kids Over Profits team without identifying themselves. The form asks for no name, email or account.

## Where it lives

- **Page:** `/anon-submit/` on the live site (linked from the home page's "Encrypted Upload" card, the inspection reports page, and the Get Involved header menu built by `api/rebuild-header-menu.php`). The page content is just the shortcode `[anonymous_doc_portal]`.
- **Backend:** the `AnonymousDocPortal` class in `inc/features.php`, created on `after_setup_theme` by `kop_initialize_anonymous_doc_portal()`. It registers the shortcode, the AJAX action `submit_anonymous_doc` (both `wp_ajax_` and `wp_ajax_nopriv_`, so logged-out visitors can submit), the front-end assets and the admin screen.
- **Frontend:** `js/anonymous-portal.js` (jQuery) and `css/anonymous-portal.css`. `AnonymousDocPortal::enqueue_scripts()` loads both only on a post whose content carries the shortcode.
- **Guided tour:** `js/tool-tutorials.js` has a tour for the portal; `kop_enqueue_tool_tutorials()` in `inc/enqueue.php` loads it on any page carrying the shortcode.

## Submitting

- One file per submission. Drag and drop onto the drop zone or click to browse.
- Allowed extensions: `pdf`, `doc`, `docx`, `txt`, `jpg`, `jpeg`, `png`, `zip`. Maximum size 10 MB. The browser checks both before upload; the server checks them again (`$allowed_types`, `$max_file_size`). The client-side list is a copy of the server one, so change both together.
- An optional notes field. There are no contact fields.
- The form posts `action`, `security` (the nonce), `doc_file` and `doc_notes` to `admin-ajax.php`. The server verifies the nonce with `check_ajax_referer('anonymous_doc_portal_nonce', 'security')`.

## Malware scanning

Each file is sent to Cloudmersive's `virus/scan/file` endpoint before it is stored.

- The API key `CLOUDMERSIVE_API_KEY` is read from a PHP constant, then the environment, then a `.env` file found by walking up from the theme directory. See `.env.example`. Never commit the key.
- **No key configured:** the scan is skipped and the upload is accepted (a warning goes to the PHP error log).
- **API error or non-200 response:** the upload is rejected.
- **Threat found:** the upload is rejected and the temporary file deleted.

The same key also drives the URL threat scanner further down `inc/features.php` (used by `api/scan-submission-urls.php` for other submissions); that is separate from this portal.

## Storage

- Files go to `wp-content/uploads/anonymous-submissions/`, named `<submission id>_<sanitized original name>`, where the id is `uniqid('sub_')`.
- Notes are saved beside the file as `<submission id>_notes.txt`.
- When the directory is first created it gets an `.htaccess` (`Deny from all`) and an `index.php` so the files cannot be fetched or listed over the web.
- Files are stored as uploaded. Nothing encrypts them at rest and nothing strips metadata; protection in transit is the site's HTTPS. The portal text and tour say "encrypted", which describes the transport only.

## Notification

After a file is stored, `kop_notify_admins('document', ...)` (`inc/submission-notify.php`) mails the submission notification list. To keep the sender anonymous the mail carries only the submission id, the file type, the size and whether notes were included: not the original filename, not the notes. It links to the Anonymous Docs admin screen.

Recipients come from the `kop_submission_notify_emails` option (comma or newline separated), falling back to the site admin email, and can be changed with the `kop_submission_notify_recipients` filter. Documents are mailed immediately, not digested.

## Reviewing submissions

WordPress admin > **Anonymous Docs** (`admin.php?page=anonymous-docs`, `manage_options` only) lists every file in the directory with its size and date. There is no download link, because the directory denies web access: open the files over SFTP or cPanel File Manager.

## Configuration passed to the JavaScript

`wp_localize_script` exposes `window.anonymousPortal`:

- `ajax_url`: the `admin-ajax.php` URL.
- `nonce`: for the `anonymous_doc_portal_nonce` action.
- `max_file_size`: maximum size in bytes (10485760).
- `i18n`: status messages (uploading, success, error, file too large, invalid type).

The allowed extensions are not passed; they are hard-coded in the script.
