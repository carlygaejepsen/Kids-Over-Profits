/**
 * Anonymous Document Portal JavaScript
 * Save as: /wp-content/themes/your-kadence-child/js/anonymous-portal.js
 */

jQuery(document).ready(function($) {
    let selectedFiles = [];
    
    const $uploadArea = $('#upload-area');
    const $fileInput = $('#file-input');
    const $fileList = $('#file-list');
    const $submitBtn = $('#submit-docs');
    const $statusMessages = $('#status-messages');
    const $contactMethod = $('#contact-method');
    const $contactDetails = $('#contact-details');
    const $contactInfo = $('#contact-info');
    
    // File upload area interactions
    $uploadArea.on('click', function(e) {
        if (e.target === this || $(e.target).hasClass('upload-content') || $(e.target).hasClass('upload-icon')) {
            $fileInput.click();
        }
    });
    
    // Drag and drop functionality
    $uploadArea.on('dragover dragenter', function(e) {
        e.preventDefault();
        e.stopPropagation();
        $(this).addClass('drag-over');
    });
    
    $uploadArea.on('dragleave dragend', function(e) {
        e.preventDefault();
        e.stopPropagation();
        $(this).removeClass('drag-over');
    });
    
    $uploadArea.on('drop', function(e) {
        e.preventDefault();
        e.stopPropagation();
        $(this).removeClass('drag-over');
        
        const files = e.originalEvent.dataTransfer.files;
        handleFiles(files);
    });
    
    // File input change
    $fileInput.on('change', function() {
        handleFiles(this.files);
    });
    
    // Contact method change
    $contactMethod.on('change', function() {
        const method = $(this).val();
        if (method) {
            $contactDetails.show();
            $contactInfo.attr('placeholder', getPlaceholderText(method));
        } else {
            $contactDetails.hide();
        }
    });
    
    // Submit button
    $submitBtn.on('click', function() {
        if (selectedFiles.length === 0) {
            showMessage('Please select at least one file to upload.', 'error');
            return;
        }
        
        submitDocuments();
    });
    
    function handleFiles(files) {
        const maxSize = anonymous_portal_ajax.max_size;
        const allowedTypes = anonymous_portal_ajax.allowed_types;
        
        Array.from(files).forEach(file => {
            // Check file size
            if (file.size > maxSize) {
                showMessage(`File "${file.name}" is too large. Maximum size is ${formatFileSize(maxSize)}.`, 'error');
                return;
            }
            
            // Check file type
            const fileExt = file.name.split('.').pop().toLowerCase();
            if (!allowedTypes.includes(fileExt)) {
                showMessage(`File type "${fileExt}" is not allowed.`, 'error');
                return;
            }
            
            // Check if file already selected
            if (selectedFiles.find(f => f.name === file.name && f.size === file.size)) {
                showMessage(`File "${file.name}" is already selected.`, 'warning');
                return;
            }
            
            selectedFiles.push(file);
        });
        
        updateFileList();
        updateSubmitButton();
    }
    
    function updateFileList() {
        $fileList.empty();
        
        if (selectedFiles.length === 0) {
            return;
        }
        
        const $listContainer = $('<div class="selected-files"></div>');
        $listContainer.append('<h4>Selected Files:</h4>');
        
        selectedFiles.forEach((file, index) => {
            const $fileItem = $(`
                <div class="file-item" data-index="${index}">
                    <div class="file-info">
                        <span class="file-name">${escapeHtml(file.name)}</span>
                        <span class="file-size">(${formatFileSize(file.size)})</span>
                    </div>
                    <button type="button" class="remove-file" data-index="${index}">✕</button>
                </div>
            `);
            
            $listContainer.append($fileItem);
        });
        
        $fileList.append($listContainer);
        
        // Remove file functionality
        $('.remove-file').on('click', function() {
            const index = parseInt($(this).data('index'));
            selectedFiles.splice(index, 1);
            updateFileList();
            updateSubmitButton();
        });
    }
    
    function updateSubmitButton() {
        if (selectedFiles.length > 0) {
            $submitBtn.prop('disabled', false).removeClass('disabled');
        } else {
            $submitBtn.prop('disabled', true).addClass('disabled');
        }
    }
    
    function submitDocuments() {
        const formData = new FormData();
        
        // Add files
        selectedFiles.forEach(file => {
            formData.append('files[]', file);
        });
        
        // Add other data
        formData.append('action', 'submit_anonymous_doc');
        formData.append('nonce', anonymous_portal_ajax.nonce);
        formData.append('message', $('#submission-message').val());
        formData.append('contact_method', $contactMethod.val());
        formData.append('contact_info', $contactInfo.val());
        
        // Update UI
        $submitBtn.prop('disabled', true);
        $('.btn-text').hide();
        $('.btn-loading').show();
        showMessage('Uploading and encrypting documents...', 'info');
        
        // Submit via AJAX
        $.ajax({
            url: anonymous_portal_ajax.ajax_url,
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            timeout: 300000, // 5 minutes
            success: function(response) {
                if (response.success) {
                    showMessage(response.message, 'success');
                    resetForm();
                } else {
                    showMessage('Error: ' + response.message, 'error');
                    resetSubmitButton();
                }
            },
            error: function(xhr, status, error) {
                let errorMsg = 'Upload failed. ';
                if (status === 'timeout') {
                    errorMsg += 'The upload took too long. Please try with smaller files.';
                } else {
                    errorMsg += 'Please try again or contact support.';
                }
                showMessage(errorMsg, 'error');
                resetSubmitButton();
            }
        });
    }
    
    function resetForm() {
        selectedFiles = [];
        $('#submission-message').val('');
        $contactMethod.val('');
        $contactInfo.val('');
        $contactDetails.hide();
        updateFileList();
        updateSubmitButton();
        resetSubmitButton();
    }
    
    function resetSubmitButton() {
        $submitBtn.prop('disabled', false);
        $('.btn-text').show();
        $('.btn-loading').hide();
    }
    
    function showMessage(message, type) {
        const $message = $(`
            <div class="status-message ${type}">
                <span class="message-icon">${getMessageIcon(type)}</span>
                <span class="message-text">${escapeHtml(message)}</span>
                <button type="button" class="close-message">✕</button>
            </div>
        `);
        
        $statusMessages.prepend($message);
        
        // Auto-remove success/info messages after 10 seconds
        if (type === 'success' || type === 'info') {
            setTimeout(() => {
                $message.fadeOut(() => $message.remove());
            }, 10000);
        }
        
        // Close button functionality
        $message.find('.close-message').on('click', function() {
            $message.fadeOut(() => $message.remove());
        });
        
        // Scroll to message
        $message[0].scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }
    
    function getMessageIcon(type) {
        switch(type) {
            case 'success': return '✅';
            case 'error': return '❌';
            case 'warning': return '⚠️';
            case 'info': return 'ℹ️';
            default: return 'ℹ️';
        }
    }
    
    function getPlaceholderText(method) {
        switch(method) {
            case 'email': return 'your.secure@email.com';
            case 'phone': return 'Your phone number';
            case 'portal': return 'Check back reference (optional)';
            default: return '';
        }
    }
    
    function formatFileSize(bytes) {
        if (bytes === 0) return '0 Bytes';
        const k = 1024;
        const sizes = ['Bytes', 'KB', 'MB', 'GB'];
        const i = Math.floor(Math.log(bytes) / Math.log(k));
        return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
    }
    
    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
    
    // Prevent form submission on Enter key
    $(document).on('keypress', 'input, textarea', function(e) {
        if (e.which === 13 && !$(this).is('textarea')) {
            e.preventDefault();
        }
    });
    
    // Clear messages when new files are selected
    $fileInput.on('change', function() {
        $statusMessages.find('.status-message.error, .status-message.warning').fadeOut(() => {
            $(this).remove();
        });
    });
});