/**
 * Anonymous Document Portal JavaScript
 *
 * Pairs with the [anonymous_doc_portal] shortcode markup in inc/features.php:
 *   form#anonymous-doc-form, label#file-drop-zone wrapping input#doc-file
 *   (single file), textarea#doc-notes, button#submit-doc, div#upload-status.
 *
 * Submits to admin-ajax action 'submit_anonymous_doc'; the nonce travels in
 * the 'security' field (check_ajax_referer('anonymous_doc_portal_nonce',
 * 'security')). Config is localized as window.anonymousPortal.
 */

jQuery(document).ready(function($) {
    const config = window.anonymousPortal || {};
    const $form = $('#anonymous-doc-form');
    if (!$form.length) return;

    const $dropZone = $('#file-drop-zone');
    const $fileInput = $('#doc-file');
    const $preview = $('#file-preview');
    const $notes = $('#doc-notes');
    const $submitBtn = $('#submit-doc');
    const $status = $('#upload-status');

    const maxSize = parseInt(config.max_file_size, 10) || 10485760; // 10MB
    // Mirror of the server-side whitelist (AnonymousDocPortal::$allowed_types).
    const allowedTypes = ['pdf', 'doc', 'docx', 'txt', 'jpg', 'jpeg', 'png', 'zip'];
    const i18n = config.i18n || {};

    function formatFileSize(bytes) {
        if (!bytes) return '0 Bytes';
        const k = 1024;
        const sizes = ['Bytes', 'KB', 'MB', 'GB'];
        const i = Math.floor(Math.log(bytes) / Math.log(k));
        return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
    }

    function showStatus(message, type) {
        $status.attr('class', 'upload-status ' + type).text(message);
    }

    function clearStatus() {
        $status.attr('class', 'upload-status').empty();
    }

    function validateFile(file) {
        const ext = (file.name.split('.').pop() || '').toLowerCase();
        if (allowedTypes.indexOf(ext) === -1) {
            return i18n.invalid_type || 'Invalid file type.';
        }
        if (file.size > maxSize) {
            return i18n.file_too_large || ('File is too large. Maximum size is ' + formatFileSize(maxSize) + '.');
        }
        return null;
    }

    function updatePreview() {
        const file = $fileInput[0].files[0];
        if (!file) {
            $preview.empty();
            return;
        }
        const error = validateFile(file);
        if (error) {
            showStatus(error, 'error');
            $fileInput.val('');
            $preview.empty();
            return;
        }
        clearStatus();
        $preview.text(file.name + ' (' + formatFileSize(file.size) + ')');
    }

    // Drag & drop onto the drop zone assigns the file to the input so the
    // form state matches a normal click-to-browse selection.
    if ($dropZone.length) {
        const dropZoneEl = $dropZone[0];

        ['dragover', 'dragenter'].forEach(function(eventName) {
            dropZoneEl.addEventListener(eventName, function(event) {
                event.preventDefault();
                event.stopPropagation();
                $dropZone.addClass('drag-over');
            }, { passive: false });
        });

        ['dragleave', 'dragend'].forEach(function(eventName) {
            dropZoneEl.addEventListener(eventName, function(event) {
                event.preventDefault();
                event.stopPropagation();
                $dropZone.removeClass('drag-over');
            }, { passive: false });
        });

        dropZoneEl.addEventListener('drop', function(event) {
            event.preventDefault();
            event.stopPropagation();
            $dropZone.removeClass('drag-over');

            const files = (event.dataTransfer && event.dataTransfer.files) || [];
            if (files.length) {
                try {
                    const dt = new DataTransfer();
                    dt.items.add(files[0]);
                    $fileInput[0].files = dt.files;
                } catch (e) {
                    // Older browsers without DataTransfer construction: fall
                    // back to asking the user to click-select instead.
                    showStatus('Drag & drop is not supported in this browser — please click to browse instead.', 'warning');
                    return;
                }
                updatePreview();
            }
        }, { passive: false });
    }

    $fileInput.on('change', updatePreview);

    $form.on('submit', function(event) {
        event.preventDefault();

        const file = $fileInput[0].files[0];
        if (!file) {
            showStatus('Please select a file to upload.', 'error');
            return;
        }
        const error = validateFile(file);
        if (error) {
            showStatus(error, 'error');
            return;
        }

        const formData = new FormData();
        formData.append('action', 'submit_anonymous_doc');
        formData.append('security', config.nonce || '');
        formData.append('doc_file', file);
        formData.append('doc_notes', $notes.val() || '');

        $form.addClass('uploading');
        $submitBtn.prop('disabled', true);
        showStatus(i18n.uploading || 'Encrypting and uploading...', 'info');

        $.ajax({
            url: config.ajax_url || '/wp-admin/admin-ajax.php',
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            timeout: 300000, // 5 minutes
            success: function(response) {
                // wp_send_json_success/error wrap the payload in `data`.
                const payload = (response && response.data) || {};
                if (response && response.success) {
                    showStatus(payload.message || i18n.success || 'Document submitted securely. Thank you.', 'success');
                    $form[0].reset();
                    $preview.empty();
                } else {
                    showStatus(payload.message || i18n.error || 'Upload failed. Please try again.', 'error');
                }
            },
            error: function(xhr, status) {
                let message = i18n.error || 'Upload failed. Please try again.';
                if (status === 'timeout') {
                    message = 'The upload took too long. Please try a smaller file.';
                }
                showStatus(message, 'error');
            },
            complete: function() {
                $form.removeClass('uploading');
                $submitBtn.prop('disabled', false);
            }
        });
    });
});
