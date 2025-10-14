# WordPress Page Setup Guide

## Problem Solved
Previously, CSS and JS files were returning 404 errors because the HTML files had hardcoded `<link>` and `<script>` tags that WordPress was stripping out when pasted into the WordPress editor.

## Solution Implemented
Added proper WordPress enqueue functions in `functions.php` to load CSS and JS files automatically for specific pages.

---

## How to Update Your WordPress Pages

### Step 1: Upload Updated files.php to Server
Upload the updated `Website/functions.php` file to your server at:
```
public_html/wp-content/themes/child/functions.php
```

### Step 2: Upload Updated style.css (Optional but Recommended)
Upload the updated `Website/style.css` file to:
```
public_html/wp-content/themes/child/style.css
```

### Step 3: Clean Up Your WordPress Pages

For each WordPress page that currently has hardcoded CSS/JS references, you need to remove the `<head>` section and keep only the body content.

#### Pages That Need Updating:
- **admin-data** page
- **data-organizer** page
- **data** page
- Any other pages where you pasted full HTML

#### What to Remove:
Delete everything from your WordPress page editor that looks like this:

```html
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>...</title>
    <link rel="stylesheet" href="https://kidsoverprofits.org/wp-content/themes/child/css/common.css">
    <link rel="stylesheet" href="https://kidsoverprofits.org/wp-content/themes/child/css/layout.css">
    <link rel="stylesheet" href="https://kidsoverprofits.org/wp-content/themes/child/css/forms.css">
    <link rel="stylesheet" href="https://kidsoverprofits.org/wp-content/themes/child/css/tables.css">
    <link rel="stylesheet" href="https://kidsoverprofits.org/wp-content/themes/child/css/modals.css">
    <link rel="stylesheet" href="https://kidsoverprofits.org/wp-content/themes/child/css/admin.css">
</head>
<body>
```

Also remove at the bottom:
```html
    <script src="https://kidsoverprofits.org/wp-content/themes/child/js/app-logic.js"></script>
    <script src="https://kidsoverprofits.org/wp-content/themes/child/js/utilities.js"></script>
    <script src="https://kidsoverprofits.org/wp-content/themes/child/js/facility-form.v3.js"></script>
</body>
</html>
```

#### What to Keep:
Keep ONLY the content that was inside the `<body>` tags. For example:

```html
<div class="container">
    <div class="admin-header">
        <h1>Admin: Master Facility Data Entry</h1>
        <p>All changes auto-save to the cloud master database.</p>
    </div>
    <!-- Rest of your page content -->
</div>
```

---

## Example: Before and After

### ❌ BEFORE (Don't do this)
```html
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Admin Data</title>
    <link rel="stylesheet" href="https://kidsoverprofits.org/.../common.css">
    <link rel="stylesheet" href="https://kidsoverprofits.org/.../forms.css">
</head>
<body>
    <div class="container">
        <h1>My Content</h1>
    </div>
    <script src="https://kidsoverprofits.org/.../app-logic.js"></script>
</body>
</html>
```

### ✅ AFTER (Correct way)
```html
<div class="container">
    <h1>My Content</h1>
</div>
```

The CSS and JS files will automatically load because of the enqueue functions in `functions.php`.

---

## How It Works Now

### For admin-data Page:
When someone visits `/admin-data/`, WordPress will automatically:
1. Load all 6 CSS files (common, layout, forms, tables, modals, admin)
2. Load all 3 JS files (app-logic, utilities, facility-form.v3)

### For data-organizer Page:
When someone visits `/data-organizer/`, WordPress will automatically:
1. Load all 6 CSS files
2. No additional JS files (none needed based on the HTML)

### For data Page:
When someone visits `/data/`, WordPress will automatically:
1. Load all 6 CSS files
2. Load 3 JS files (app-logic, facility-form.v3, data-form)

---

## Benefits of This Approach

✅ **Proper WordPress integration** - Uses WordPress enqueue system
✅ **Cache busting** - Automatic versioning based on file modification time
✅ **Better performance** - Files loaded in correct order with dependencies
✅ **No 404 errors** - WordPress properly serves the files
✅ **Clean code** - No hardcoded URLs in page content

---

## Troubleshooting

### If CSS/JS still don't load:

1. **Clear browser cache** - Hard refresh (Ctrl+Shift+R or Cmd+Shift+R)
2. **Clear WordPress cache** - If using a caching plugin, clear it
3. **Verify files uploaded** - Check that functions.php was uploaded correctly
4. **Check page slug** - Make sure the page slug is exactly `admin-data`, `data-organizer`, or `data` (lowercase, with hyphens)
5. **Check file permissions** on server:
   ```bash
   chmod 644 public_html/wp-content/themes/child/css/*.css
   chmod 644 public_html/wp-content/themes/child/js/*.js
   chmod 755 public_html/wp-content/themes/child/css
   chmod 755 public_html/wp-content/themes/child/js
   ```

### If you add a new page that needs these assets:

Add a new enqueue function in `functions.php` following the same pattern:

```php
function enqueue_my_new_page_assets() {
    if (is_page('my-new-page-slug')) {
        // Enqueue CSS files
        wp_enqueue_style('common-css', get_stylesheet_directory_uri() . '/css/common.css', array(), filemtime(get_stylesheet_directory() . '/css/common.css'));
        // ... add other CSS files as needed

        // Enqueue JS files
        wp_enqueue_script('app-logic', get_stylesheet_directory_uri() . '/js/app-logic.js', array(), filemtime(get_stylesheet_directory() . '/js/app-logic.js'), true);
        // ... add other JS files as needed
    }
}
add_action('wp_enqueue_scripts', 'enqueue_my_new_page_assets');
```

---

## Need Help?

If you encounter any issues:
1. Check browser console (F12) for specific error messages
2. Verify the page slug matches the function (e.g., `is_page('admin-data')`)
3. Make sure all files exist on the server
4. Check file permissions are correct (644 for files, 755 for directories)
