# Fix WordPress 6.8.0 Login Issue on Test Site

## Problem
WordPress 6.8.0 errors prevent login to test site admin. Production is unaffected.
WP_DEBUG is already false but errors still appear.

## Solution
Edit `/public_html/test/wp-config.php` via cPanel File Manager:

Add these lines **RIGHT AFTER** the opening `<?php` tag (line 2 or 3):

```php
// Suppress WP 6.8.0 wp_is_block_theme errors
error_reporting(E_ERROR | E_PARSE);
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
```

**It should look like this:**
```php
<?php
// Suppress WP 6.8.0 wp_is_block_theme errors
error_reporting(E_ERROR | E_PARSE);
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');

/**
 * The base configuration for WordPress
 * ...
```

## After Fix

Once you can log in:
1. Update all plugins (especially WooCommerce if installed)
2. Update Kadence theme if update available
3. Test the site
4. Delete this file

## Why This Works

The error occurs because something (likely Kadence parent theme) calls `wp_is_block_theme()` before WordPress registers theme directories. This is a known WordPress 6.8.0 compatibility issue affecting multiple themes. Disabling debug output suppresses the notices and allows headers to send properly.
