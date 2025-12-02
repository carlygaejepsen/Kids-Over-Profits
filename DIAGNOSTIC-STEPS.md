# JavaScript Loading Diagnostic Steps

## ✅ What I've Done
1. Added debug logging to `functions.php` (lines 1825-1835)
2. Added debug logging to `page-data.php` (lines 7-11)
3. Verified all JavaScript files exist and have no syntax errors
4. Confirmed template IS being recognized

## 🔍 **NEXT: Check Browser Console**

The scripts have built-in console logging. Follow these steps:

### Step 1: Open Browser Developer Tools
1. Load your data form page
2. Press **F12** (or right-click → Inspect)
3. Click the **Console** tab

### Step 2: Look for These Messages

#### ✅ If Scripts ARE Loading, you'll see:
```
DEBUG: enqueue_facility_form_script() was called and scripts should be enqueued
[Facility Form] DOM still loading, waiting for DOMContentLoaded
```
OR
```
[Facility Form] DOM already loaded, calling initializeForm immediately
```

#### ❌ If Scripts are NOT Loading, you'll see:
- ONLY the DEBUG message (or nothing at all)
- NO "[Facility Form]" messages
- Possibly 404 errors for .js files

### Step 3: Check Network Tab
1. In Developer Tools, click **Network** tab
2. Reload the page (Ctrl+R or F5)
3. Filter by "JS" or search for "facility-form"
4. Look for these files:
   - `utilities.js` - should show **200 OK**
   - `db-form-loader.js` - should show **200 OK**
   - `facility-form.v3.js` - should show **200 OK**
   - `data-page.js` - should show **200 OK**

#### ❌ If you see **404 Not Found**:
The file paths are wrong. The scripts are being enqueued but can't be found.

#### ✅ If you see **200 OK** but no console messages:
There's a JavaScript error preventing execution.

### Step 4: Check for JavaScript Errors
In the Console tab, look for **red error messages** like:
- `Uncaught ReferenceError`
- `Uncaught TypeError`
- `Uncaught SyntaxError`

## 📊 Common Issues & Solutions

### Issue 1: Scripts Not in HTML at All
**Symptom:** No script tags in page source, no console messages
**Cause:** WP_DEBUG is off or template check failing
**Solution:**
```php
// In wp-config.php, enable:
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
```

### Issue 2: 404 Errors on Script Files
**Symptom:** Script tags exist but files return 404
**Cause:** Wrong file paths or theme directory mismatch
**Check:** View page source, find script tag URL, try accessing directly in browser

### Issue 3: Scripts Load But Don't Execute
**Symptom:** 200 OK in Network tab, but no console messages from scripts
**Cause:** JavaScript error in earlier script preventing later scripts from running
**Solution:** Look for the FIRST red error in console and fix that

### Issue 4: jQuery Not Loaded
**Symptom:** Error like "$ is not defined" or "jQuery is not defined"
**Cause:** jQuery dependency not loading before scripts
**Check:** In Console, type `jQuery` and press Enter. Should show a function, not "undefined"

## 🎯 What to Report Back

Please check the above and report:

1. **Do you see the "[Facility Form]" console messages?** (Yes/No)
2. **Are there any RED error messages in console?** (If yes, copy the first one)
3. **Do the .js files show 200 OK in Network tab?** (Yes/No)
4. **What does typing `jQuery` in console return?** (function or undefined)

This will tell me exactly where the problem is!
