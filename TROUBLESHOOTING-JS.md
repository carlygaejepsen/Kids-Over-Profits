# JavaScript Not Loading - Troubleshooting Guide

## Issue
JavaScript files are not loading on the Public Data Form page (data-form-public.php).

## Files Verified ✅
All required JavaScript files exist:
- `/js/data-form/utilities.js` - EXISTS
- `/js/data-form/db-form-loader.js` - EXISTS
- `/js/data-form/facility-form.v3.js` - EXISTS
- `/js/data-form/data-page.js` - EXISTS
- No syntax errors found in any files

## Root Cause
The `enqueue_facility_form_script()` function in `functions.php` (lines 1800-1976) only loads scripts when WordPress recognizes specific page templates or slugs.

## Quick Diagnostics

### 1. Check Browser Console
Open your browser's Developer Tools (F12) and check the Console tab for:
- JavaScript errors (red messages)
- 404 errors for missing script files
- Any warnings about scripts not loading

### 2. Check Page Source
View page source (Ctrl+U) and search for:
- `utilities.js` - should appear in a `<script>` tag
- `facility-form.v3.js` - should appear in a `<script>` tag
- If these don't appear, the scripts aren't being enqueued

### 3. Check WordPress Page Settings
In WordPress admin:
1. Go to Pages → Find your data form page
2. In the Page Attributes box (right sidebar), check "Template"
3. It should show "Public Data Form"
4. If it says "Default Template", select "Public Data Form" and save

### 4. Verify Page Slug
The scripts will load if the page slug is exactly "data" or "tti-data-submission". Check:
1. Pages → Your data form page
2. Look at the permalink/URL slug
3. Recommended slugs: `data`, `tti-data-submission`

## Solutions

### Solution 1: Ensure Correct Template is Selected
1. WordPress Admin → Pages → Your Data Form Page
2. Page Attributes → Template: Select "Public Data Form"
3. Click "Update"
4. Clear any caching (browser cache, WordPress cache plugins)
5. Refresh the page

### Solution 2: Force Script Loading (Temporary Debug)
Add this to `functions.php` around line 1821 (before the return statement):

```php
// DEBUG: Force load scripts on this page for testing
if (is_page('your-page-slug-here')) {
    $is_data_form_page = true;
}
```

Replace `your-page-slug-here` with your actual page slug.

### Solution 3: Check for Theme/Plugin Conflicts
1. Temporarily switch to a default WordPress theme (Twenty Twenty-Four)
2. If scripts load → theme conflict
3. Deactivate plugins one by one to find conflicts

### Solution 4: Clear All Caches
- Browser cache (Ctrl+Shift+Delete)
- WordPress object cache (if using Redis/Memcached)
- Plugin caches (WP Rocket, W3 Total Cache, etc.)
- CDN cache (Cloudflare, etc.)

## Debug Code Added
Debug logging has been added to `page-data.php`. To view logs:
1. Enable WP_DEBUG in `wp-config.php`:
   ```php
   define('WP_DEBUG', true);
   define('WP_DEBUG_LOG', true);
   ```
2. Check `/wp-content/debug.log` for template detection messages

## Next Steps if Still Not Working
1. Check the debug.log file for template detection
2. Verify the page actually uses the page-data.php template
3. Check for JavaScript console errors
4. Ensure jQuery is loading (required dependency)
