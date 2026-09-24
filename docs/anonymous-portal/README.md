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

## Encryption

Every submission is encrypted the moment it reaches the server, before anything is written to disk. The file and the notes are sealed together with the portal's **public key** (a libsodium sealed box, `sodium_crypto_box_seal`). The server never holds the matching **private key**, so it cannot open a submission once it is stored, and neither can anyone who breaks into the server or the hosting account.

- **Public key:** `inc/anonymous-portal-public.key` (committed and deployed), or the `KOP_ANON_PORTAL_PUBLIC_KEY` constant in `wp-config.php`, which takes precedence. The Anonymous Docs screen shows its fingerprint.
- **Private key:** kept by the site owner, off the server and out of the repository (a password manager). If it is lost, every submission sealed with the matching public key is unreadable for good.
- **No usable key, or no sodium extension:** the portal refuses uploads ("temporarily unavailable") rather than store anything unencrypted, and logs why.
- **What is sealed:** `"KOPANON1"`, a 4-byte big-endian header length, a JSON header (`id`, original `name`, `notes`, `received`), then the file bytes. `AnonymousDocPortal::seal_submission()` writes it and `scripts/anon-portal-decrypt.php` reads it.
- **What is not protected by this:** the file travels to the server over HTTPS and is decrypted there only in memory, for the Cloudmersive scan and for sealing. The browser does not encrypt it, so this is encryption on arrival, not end-to-end. Nothing strips document metadata; a submitter who is worried about it should remove it first.

## Storage

- Files go to `wp-content/uploads/anonymous-submissions/` as `<submission id>.sealed`, where the id is `uniqid('sub_')`. The original filename is inside the sealed box only.
- When the directory is first created it gets an `.htaccess` (`Deny from all`) and an `index.php` so the files cannot be fetched or listed over the web.
- Submissions stored before encryption (September 2026 and earlier: `<id>_<original name>` plus `<id>_notes.txt`) show as a warning on the admin screen, with a button that seals them and then deletes the unencrypted copies. A submission's plaintext is deleted only after all of its sealed files are written in full. Server backups taken before that still hold the plaintext.

## Notification

After a submission is sealed and stored, `kop_notify_admins('document', ...)` (`inc/submission-notify.php`) mails the submission notification list. To keep the sender anonymous the mail carries only the submission id, the file type, the size and whether notes were included: not the original filename, not the notes (which are sealed with the file). It links to the Anonymous Docs admin screen.

Recipients come from the `kop_submission_notify_emails` option (comma or newline separated), falling back to the site admin email, and can be changed with the `kop_submission_notify_recipients` filter. Documents are mailed immediately, not digested.

## Reviewing submissions

1. WordPress admin > **Anonymous Docs** (`admin.php?page=anonymous-docs`, `manage_options` only) lists the sealed submissions, newest first, with a **Download (encrypted)** button for each (`admin-post.php?action=kop_anon_download`, nonce-checked).
2. Open the downloaded files on your own computer with the private key. With the PHP bundled in Flywheel Local:

   ```
   php -n -d extension_dir=<php>/ext -d extension=sodium scripts/anon-portal-decrypt.php <private-key-file> <out-folder> <file.sealed>...
   ```

   Each file comes out as `<id>_<original name>`, with `<id>_notes.txt` beside it when the submitter left notes.

## Keys and tests

- `scripts/anon-portal-keygen.php <private-key-file>` makes a new key pair: the private key to the path given (refused inside the repository), the public key to `inc/anonymous-portal-public.key`. It will not overwrite either. To replace the key, move the old public key file away first, keep the old private key for the submissions it sealed, then commit and deploy the new public key.
- `scripts/test-anon-portal.php` runs offline with a throwaway key: seal and open, wrong key rejected, the no-key refusal, and the migration of older plaintext submissions.

## Configuration passed to the JavaScript

`wp_localize_script` exposes `window.anonymousPortal`:

- `ajax_url`: the `admin-ajax.php` URL.
- `nonce`: for the `anonymous_doc_portal_nonce` action.
- `max_file_size`: maximum size in bytes (10485760).
- `i18n`: status messages (uploading, success, error, file too large, invalid type).

The allowed extensions are not passed; they are hard-coded in the script.
