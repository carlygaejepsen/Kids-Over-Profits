# Anonymous Portal

The **Anonymous Portal** is a secure system designed to allow whistleblowers, survivors, and staff to submit sensitive documents and reports to the Kids Over Profits team without revealing their identity.

## Overview

This module provides a simple, secure upload interface. It prioritizes user privacy and data security, allowing for anonymous file transfers with optional contact information.

## Architecture

- **Entry Point:** WordPress Shortcode `[anonymous_doc_portal]` (typically placed on a page like `/anonymous-portal`).
- **Frontend Logic:** `js/anonymous-portal.js` - Handles the UI, file validation, and AJAX transmission.
- **Backend:** `inc/features.php` (or similar) handles the `submit_anonymous_doc` AJAX action, processing the upload and notifying admins.

## Key Features

### 1. Secure File Upload
- **Drag & Drop:** Users can drag files directly into the upload area.
- **Validation:** Client-side checks for file size and allowed file types to prevent errors before upload.
- **AJAX Submission:** Files are uploaded asynchronously without page reloads, providing a smoother experience.

### 2. Privacy Controls
- **Anonymous by Default:** Contact information is purely optional.
- **Contact Methods:** If users choose to leave info, they can specify a preferred method (Email, Phone, or Secure Portal Reference).

### 3. User Feedback
- **Status Messages:** Real-time feedback on upload progress, success, or errors.
- **File List:** Visual list of selected files with the ability to remove items before submission.

## Usage

1.  **Access:** Navigate to the Anonymous Portal page on the website.
2.  **Select Files:** Click the upload area or drag and drop files (PDFs, images, docs).
3.  **Add Info (Optional):** Enter a message or contact details if desired.
4.  **Submit:** Click "Submit Documents". The system encrypts (via SSL/TLS transit) and stores the files securely.

## Configuration

The module is configured via the `anonymous_portal_ajax` object passed from WordPress to the JavaScript:
- `ajax_url`: The WordPress AJAX endpoint.
- `nonce`: Security token.
- `max_size`: Maximum file size in bytes.
- `allowed_types`: Array of allowed file extensions.
