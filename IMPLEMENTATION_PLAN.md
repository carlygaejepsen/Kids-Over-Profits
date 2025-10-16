# Kids Over Profits - Recurring Error Resolution Plan
## ChatGPT Codex Implementation Guide

**Date:** 2025-10-15
**Project:** Kids Over Profits WordPress Child Theme
**Repo:** https://github.com/carlygaejepsen/Kids-Over-Profits
**Environment:** Windows (MSYS_NT), cPanel deployment

---

## Executive Summary

This plan resolves **5 critical recurring error patterns** identified through git history analysis and code review:

1. **CSS Loading Failures** - Pages stuck showing "Loading..." indefinitely
2. **API Endpoint 404 Errors** - Data form submissions fail with path conflicts
3. **Theme Base URI Issues** - Assets load from incorrect paths on live server
4. **Deployment File Sync Gaps** - JS/CSS files missing after git push
5. **Security Vulnerability** - Database credentials hardcoded in `api/config.php`

**Root Cause:** Dual path system (`/wp-content/themes/child/` vs `/themes/child/`) combined with unreliable runtime asset resolution.

---

## Pre-Implementation Requirements

### Required Information
- [ ] Database password to use in `.env` file (regenerate after fix)
- [ ] WordPress salt keys for encryption (already in use)
- [ ] cPanel access credentials (for deployment verification)
- [ ] Test admin login credentials

### Environment Setup
```bash
# Verify you're in the correct directory
cd c:\Users\daniu\OneDrive\Documents\GitHub\Kids-Over-Profits

# Check current branch
git status

# Ensure you're on main branch
git checkout main

# Pull latest changes
git pull origin main
```

---

## TASK 1: Fix Critical Security Vulnerability (PRIORITY 1)

### Issue
Database credentials are hardcoded in `api/config.php` and committed to version control at line 6:
```php
$db_pass = 'Xk4&z9!pT#vR7bN@';
```

### Implementation Steps

#### Step 1.1: Create `.env` file template
**File:** `.env.example` (NEW FILE)
```env
# Database Configuration
DB_HOST=localhost
DB_NAME=kidsover_suggestions
DB_USER=kidsover_dani
DB_PASS=your_database_password_here
```

#### Step 1.2: Update .gitignore to exclude .env
**File:** `.gitignore`
**Action:** Add to the file (if not already present)
```
# Environment configuration
.env
.env.local
.env.production
```

#### Step 1.3: Create config loader
**File:** `api/config-loader.php` (NEW FILE)
```php
<?php
/**
 * Secure configuration loader for Kids Over Profits
 * Reads database credentials from .env file or WordPress constants
 */

// Prevent direct access
if (!defined('ABSPATH') && !defined('KOP_CONFIG_LOADER')) {
    define('KOP_CONFIG_LOADER', true);
}

/**
 * Load environment variables from .env file
 */
function kop_load_env_file($file_path) {
    if (!file_exists($file_path)) {
        return false;
    }

    $lines = file($file_path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        // Skip comments
        if (strpos(trim($line), '#') === 0) {
            continue;
        }

        // Parse KEY=VALUE
        if (strpos($line, '=') !== false) {
            list($key, $value) = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);

            // Remove quotes if present
            $value = trim($value, '"\'');

            // Set as environment variable if not already set
            if (!getenv($key)) {
                putenv("$key=$value");
            }
        }
    }

    return true;
}

// Try to load .env file from multiple locations
$possible_paths = [
    __DIR__ . '/.env',
    __DIR__ . '/../.env',
    dirname(__DIR__) . '/.env',
];

foreach ($possible_paths as $path) {
    if (kop_load_env_file($path)) {
        break;
    }
}

// Get database configuration with fallback priority:
// 1. WordPress constants (if available)
// 2. Environment variables from .env
// 3. Server environment variables
// 4. Fail with error

function kop_get_config($key, $default = null) {
    // Try WordPress constants first
    $wp_constant = 'KOP_' . $key;
    if (defined($wp_constant)) {
        return constant($wp_constant);
    }

    // Try environment variables
    $env_value = getenv($key);
    if ($env_value !== false) {
        return $env_value;
    }

    // Try $_ENV superglobal
    if (isset($_ENV[$key])) {
        return $_ENV[$key];
    }

    // Return default or throw error
    if ($default !== null) {
        return $default;
    }

    throw new Exception("Configuration key '$key' not found");
}

// Set database configuration variables
try {
    $db_host = kop_get_config('DB_HOST', 'localhost');
    $db_name = kop_get_config('DB_NAME');
    $db_user = kop_get_config('DB_USER');
    $db_pass = kop_get_config('DB_PASS');

    // Backwards-compatible aliases
    $host = $db_host;
    $dbname = $db_name;
    $username = $db_user;
    $password = $db_pass;

    // Create PDO connection
    $pdo = new PDO(
        "mysql:host=$db_host;dbname=$db_name;charset=utf8mb4",
        $db_user,
        $db_pass,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );

} catch (Exception $e) {
    http_response_code(500);

    // Don't expose configuration details in production
    $error_message = 'Database configuration error';

    // Log detailed error server-side
    error_log('[Kids Over Profits] Config error: ' . $e->getMessage());

    echo json_encode([
        'error' => $error_message,
        'details' => defined('WP_DEBUG') && WP_DEBUG ? $e->getMessage() : 'Check server logs'
    ]);
    exit;
}
?>
```

#### Step 1.4: Update all API files to use new config loader
**Files to update:**
- `api/data_form/get-autocomplete.php` (line 2)
- `api/data_form/get-master-data.php` (line 2)
- `api/data_form/save-master.php` (line 2)
- `api/data_form/save-suggestion.php` (line 2)
- `api/data_form/process-edit.php` (line 2)

**Action:** Replace this line:
```php
require_once __DIR__ . '/../config.php';
```

**With:**
```php
require_once __DIR__ . '/../config-loader.php';
```

#### Step 1.5: Create .env file on server
**Manual Step (Document for deployment):**
1. SSH or cPanel File Manager access to `/home/kidsover/public_html/wp-content/themes/child/api/`
2. Create `.env` file with actual credentials:
```env
DB_HOST=localhost
DB_NAME=kidsover_suggestions
DB_USER=kidsover_dani
DB_PASS=NEW_SECURE_PASSWORD_HERE
```
3. Set file permissions: `chmod 600 .env` (read/write for owner only)

#### Step 1.6: Deprecate old config.php
**File:** `api/config.php`
**Action:** Replace contents with deprecation notice
```php
<?php
/**
 * DEPRECATED: This file is deprecated as of 2025-10-15
 *
 * Database credentials should no longer be hardcoded.
 * Use config-loader.php instead, which reads from .env file.
 *
 * This file is kept for backwards compatibility during transition.
 */

trigger_error(
    'api/config.php is deprecated. Use config-loader.php instead.',
    E_USER_DEPRECATED
);

// Load the new config system
require_once __DIR__ . '/config-loader.php';
?>
```

#### Step 1.7: Update deployment instructions
**File:** `agent_instructions/repo.md`
**Action:** Add deployment security checklist

#### Step 1.8: Security cleanup
**After deployment verification:**
```bash
# Remove sensitive data from git history (CAUTION: rewrites history)
# Only do this if repo is not widely forked
git filter-branch --force --index-filter \
  "git rm --cached --ignore-unmatch api/config.php" \
  --prune-empty --tag-name-filter cat -- --all

# Force push (coordinate with team first!)
git push origin --force --all
```

**Verification:**
```bash
# Test that API endpoints still work
curl https://kidsoverprofits.org/wp-content/themes/child/api/data_form/get-master-data.php
```

---

## TASK 2: Fix CSS Asset Loading Failures

### Issue
CSS files fail to load on certain pages, leaving them stuck in "Loading..." state. Git history shows recurring PRs #24, #25, #26 addressing this issue.

### Implementation Steps

#### Step 2.1: Add inline critical CSS delivery
**File:** `functions.php`
**Location:** After line 135 (after `wp_enqueue_style`)
**Action:** Add new function

```php
/**
 * Enqueue CSS with inline fallback for critical styles
 *
 * @param string $handle Script handle
 * @param string $relative_path Path relative to theme directory
 * @param array $dependencies Style dependencies
 * @param string $media Media type
 * @param bool $inline_fallback Whether to inline CSS if file is small
 */
function kidsoverprofits_enqueue_style_with_fallback($handle, $relative_path, $dependencies = array(), $media = 'all', $inline_fallback = true) {
    $asset = kidsoverprofits_get_theme_asset_details($relative_path);

    if (!$asset) {
        error_log(sprintf('[Kids Over Profits] CSS not found: %s', $relative_path));
        return false;
    }

    // For small CSS files (<50KB) and critical styles, inline them
    $file_path = get_theme_file_path($relative_path);
    $file_size = file_exists($file_path) ? filesize($file_path) : 0;
    $is_critical = in_array($handle, ['kidsoverprofits-common', 'kidsoverprofits-layout'], true);

    if ($inline_fallback && $file_size > 0 && $file_size < 51200 && $is_critical) {
        // Inline the CSS
        $css_content = file_get_contents($file_path);
        if ($css_content !== false) {
            add_action('wp_head', function() use ($css_content, $handle) {
                echo sprintf(
                    '<!-- Inlined CSS: %s --><style id="%s-inline">%s</style>',
                    esc_attr($handle),
                    esc_attr($handle),
                    $css_content
                );
            }, 5);

            error_log(sprintf('[Kids Over Profits] Inlined critical CSS: %s (%d bytes)', $relative_path, $file_size));
            return true;
        }
    }

    // Standard enqueue with multiple path fallbacks
    wp_enqueue_style($handle, $asset['uri'], $dependencies, $asset['version'], $media);

    // Add alternate URLs as data attribute for JavaScript fallback
    $theme_bases = kidsoverprofits_get_theme_base_aliases(get_stylesheet_directory_uri());
    $alternate_urls = array();

    foreach ($theme_bases as $base) {
        $alternate_urls[] = $base . '/' . $relative_path;
    }

    if (!empty($alternate_urls)) {
        wp_add_inline_script('wp-polyfill', sprintf(
            'if(window.KOP_CSS_FALLBACKS===undefined)window.KOP_CSS_FALLBACKS={};window.KOP_CSS_FALLBACKS[%s]=%s;',
            wp_json_encode($handle),
            wp_json_encode($alternate_urls)
        ));
    }

    return true;
}
```

#### Step 2.2: Create CSS fallback loader
**File:** `js/css-fallback-loader.js` (NEW FILE)
```javascript
/**
 * CSS Fallback Loader for Kids Over Profits
 * Detects failed CSS loads and retries with alternate paths
 */
(function initCssFallbackLoader() {
    'use strict';

    const LOAD_TIMEOUT = 5000; // 5 seconds
    const RETRY_DELAY = 1000;
    const MAX_RETRIES = 3;

    /**
     * Check if a stylesheet has loaded successfully
     */
    function isStylesheetLoaded(linkElement) {
        try {
            // Method 1: Check if stylesheet has rules
            if (linkElement.sheet && linkElement.sheet.cssRules) {
                return linkElement.sheet.cssRules.length > 0;
            }

            // Method 2: Check disabled property (some browsers)
            if (linkElement.sheet && !linkElement.sheet.disabled) {
                return true;
            }
        } catch (e) {
            // Cross-origin stylesheets throw errors - assume loaded
            if (e.name === 'SecurityError') {
                return true;
            }
        }

        return false;
    }

    /**
     * Try loading CSS from alternate URL
     */
    function tryAlternateUrl(handle, urls, attemptIndex) {
        if (attemptIndex >= urls.length) {
            console.error(`[KOP] All CSS fallbacks failed for: ${handle}`);
            return;
        }

        const url = urls[attemptIndex];
        const link = document.createElement('link');
        link.rel = 'stylesheet';
        link.href = url;
        link.id = `${handle}-fallback-${attemptIndex}`;

        let loaded = false;

        link.onload = function() {
            loaded = true;
            console.log(`[KOP] CSS loaded via fallback ${attemptIndex + 1}: ${handle}`);
        };

        link.onerror = function() {
            console.warn(`[KOP] CSS fallback ${attemptIndex + 1} failed for ${handle}: ${url}`);
            setTimeout(function() {
                tryAlternateUrl(handle, urls, attemptIndex + 1);
            }, RETRY_DELAY);
        };

        document.head.appendChild(link);

        // Timeout check
        setTimeout(function() {
            if (!loaded && !isStylesheetLoaded(link)) {
                console.warn(`[KOP] CSS fallback ${attemptIndex + 1} timeout for ${handle}`);
                tryAlternateUrl(handle, urls, attemptIndex + 1);
            }
        }, LOAD_TIMEOUT);
    }

    /**
     * Monitor all stylesheets for load failures
     */
    function monitorStylesheets() {
        const stylesheets = document.querySelectorAll('link[rel="stylesheet"]');
        const fallbacks = window.KOP_CSS_FALLBACKS || {};

        stylesheets.forEach(function(link) {
            const handle = link.id.replace(/-css$/, '');

            setTimeout(function() {
                if (!isStylesheetLoaded(link)) {
                    console.warn(`[KOP] CSS failed to load: ${handle} (${link.href})`);

                    // Try fallback URLs if available
                    if (fallbacks[handle] && fallbacks[handle].length > 0) {
                        console.log(`[KOP] Attempting CSS fallback for: ${handle}`);
                        tryAlternateUrl(handle, fallbacks[handle], 0);
                    }
                }
            }, LOAD_TIMEOUT);
        });
    }

    // Run on DOM ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', monitorStylesheets);
    } else {
        monitorStylesheets();
    }

    // Export for debugging
    window.KOP_CSS_MONITOR = {
        check: monitorStylesheets,
        isLoaded: isStylesheetLoaded
    };
})();
```

#### Step 2.3: Update functions.php to enqueue CSS fallback loader
**File:** `functions.php`
**Location:** In the `kadence_child_enqueue_styles` function (around line 339)
**Action:** Add before the closing brace

```php
// Enqueue CSS fallback loader
$fallback_script = kidsoverprofits_get_theme_asset_details('js/css-fallback-loader.js');
if ($fallback_script) {
    wp_enqueue_script(
        'kidsoverprofits-css-fallback',
        $fallback_script['uri'],
        array(),
        $fallback_script['version'],
        false // Load in head, before CSS
    );
}
```

#### Step 2.4: Update CSS enqueue calls to use new fallback system
**File:** `functions.php`
**Locations:** Lines 321-337 (kadence_child_enqueue_styles function)
**Action:** Replace `kidsoverprofits_enqueue_theme_style` calls with `kidsoverprofits_enqueue_style_with_fallback`

Before:
```php
kidsoverprofits_enqueue_theme_style('kidsoverprofits-common', 'css/common.css');
```

After:
```php
kidsoverprofits_enqueue_style_with_fallback('kidsoverprofits-common', 'css/common.css', array(), 'all', true);
```

Apply to all CSS enqueues in the function.

---

## TASK 3: Fix API Endpoint Path Conflicts

### Issue
Data form submissions fail with 404 errors due to inconsistent API endpoint paths. Forms sometimes call `/api/data_form/` and sometimes `/wp-content/themes/child/api/data_form/`.

### Implementation Steps

#### Step 3.1: Create centralized API endpoint resolver
**File:** `js/api-endpoint-resolver.js` (NEW FILE)
```javascript
/**
 * API Endpoint Resolver for Kids Over Profits
 * Ensures consistent API endpoint URLs across the application
 */
(function initKopApiEndpoints() {
    'use strict';

    // API base paths to try in priority order
    const API_PATH_VARIANTS = [
        '/wp-content/themes/child/api/data_form',
        '/themes/child/api/data_form',
        'api/data_form', // Relative path
    ];

    /**
     * Test if an endpoint is accessible
     */
    function testEndpoint(baseUrl, endpoint) {
        return new Promise(function(resolve) {
            const url = baseUrl + '/' + endpoint;
            const xhr = new XMLHttpRequest();

            xhr.open('HEAD', url, true);
            xhr.timeout = 3000;

            xhr.onload = function() {
                resolve(xhr.status >= 200 && xhr.status < 400);
            };

            xhr.onerror = function() {
                resolve(false);
            };

            xhr.ontimeout = function() {
                resolve(false);
            };

            xhr.send();
        });
    }

    /**
     * Detect working API base URL
     */
    async function detectApiBase() {
        const testEndpoint = 'get-master-data.php';

        // Try with current domain
        const origin = window.location.origin;

        for (let i = 0; i < API_PATH_VARIANTS.length; i++) {
            const basePath = API_PATH_VARIANTS[i];
            const fullBase = basePath.startsWith('/') ? origin + basePath : basePath;

            const works = await testEndpoint(fullBase, testEndpoint);

            if (works) {
                console.log('[KOP] API base detected:', fullBase);
                return fullBase;
            }
        }

        // Fallback to first variant
        console.warn('[KOP] Could not detect API base, using default');
        return origin + API_PATH_VARIANTS[0];
    }

    /**
     * Get API endpoint URL
     */
    function getApiEndpoint(filename) {
        if (!window.KOP_API_BASE) {
            console.error('[KOP] API base not initialized. Call KOP_API.init() first.');
            // Return best guess
            return window.location.origin + API_PATH_VARIANTS[0] + '/' + filename;
        }

        return window.KOP_API_BASE + '/' + filename;
    }

    /**
     * Initialize API endpoint resolver
     */
    async function init() {
        if (window.KOP_API_BASE) {
            console.log('[KOP] API already initialized:', window.KOP_API_BASE);
            return window.KOP_API_BASE;
        }

        const base = await detectApiBase();
        window.KOP_API_BASE = base;

        // Trigger ready event
        const event = new CustomEvent('kop-api-ready', { detail: { base: base } });
        document.dispatchEvent(event);

        return base;
    }

    // Export API interface
    window.KOP_API = {
        init: init,
        getEndpoint: getApiEndpoint,
        getBase: function() { return window.KOP_API_BASE; }
    };

    // Auto-initialize on load
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    console.log('[KOP] API endpoint resolver loaded');
})();
```

#### Step 3.2: Update facility-form.v3.js to use endpoint resolver
**File:** `js/facility-form.v3.js`
**Location:** Search for hardcoded API paths (look for 'api/data_form')
**Action:** Replace hardcoded paths with `KOP_API.getEndpoint()`

Example - Before:
```javascript
const url = '/wp-content/themes/child/api/data_form/get-autocomplete.php';
```

After:
```javascript
const url = KOP_API.getEndpoint('get-autocomplete.php');
```

#### Step 3.3: Update data-form.js to use endpoint resolver
**File:** `js/data-form.js`
**Action:** Same as 3.2 - replace all hardcoded API paths

#### Step 3.4: Enqueue API endpoint resolver before other scripts
**File:** `functions.php`
**Location:** In `kidsoverprofits_enqueue_data_tool_assets` function (around line 786)
**Action:** Add at the beginning of the function

```php
// Enqueue API endpoint resolver first
$api_resolver = kidsoverprofits_get_theme_asset_details('js/api-endpoint-resolver.js');
if ($api_resolver) {
    wp_enqueue_script(
        'kidsoverprofits-api-resolver',
        $api_resolver['uri'],
        array(),
        $api_resolver['version'],
        false
    );
}
```

#### Step 3.5: Add API endpoint to page meta tags
**File:** `functions.php`
**Location:** After line 250 (in the inline script section)
**Action:** Add API base to meta tags

```php
/**
 * Add API endpoint information to page head
 */
function kidsoverprofits_add_api_meta() {
    $theme_base = kidsoverprofits_normalize_theme_base_uri(get_stylesheet_directory_uri());
    $api_base = $theme_base . '/api/data_form';

    echo sprintf(
        '<meta name="kids-over-profits-api-base" content="%s">' . "\n",
        esc_url($api_base)
    );
}
add_action('wp_head', 'kidsoverprofits_add_api_meta', 1);
```

---

## TASK 4: Strengthen Theme Base URI Detection

### Issue
Theme base URI sometimes resolves incorrectly, causing assets to load from wrong paths.

### Implementation Steps

#### Step 4.1: Add data-kop-theme-base to all page templates
**Files:** All `page-*.php` files (11 files total)
**Action:** After the `get_header()` call, add:

```php
<?php
// Add theme base data attribute for JavaScript
$theme_base = kidsoverprofits_normalize_theme_base_uri(get_stylesheet_directory_uri());
echo sprintf('<div data-kop-theme-base="%s" style="display:none;"></div>', esc_attr($theme_base));
?>
```

**Files to update:**
- page-admin-data.php
- page-az-reports.php
- page-ca-reports.php
- page-ct-reports.php
- page-data-organizer.php
- page-data.php
- page-facility-analysis.php
- page-mt-reports.php
- page-tx-reports.php
- page-ut-reports.php
- page-wa-reports.php

#### Step 4.2: Update theme-base-bootstrap.js to prioritize data attribute
**File:** `js/theme-base-bootstrap.js`
**Location:** Line 135-140
**Action:** Already implemented correctly, but verify the detection order:

```javascript
const detectedThemeBase = cleanBase(
    window.KOP_THEME_BASE ||
    detectThemeBaseFromDataAttribute() || // Should be first
    detectThemeBaseFromMeta() ||
    detectThemeBaseFromDomAssets()
);
```

This is already correct. No change needed.

#### Step 4.3: Add theme base verification function
**File:** `functions.php`
**Location:** After line 80 (after kidsoverprofits_get_theme_base_aliases)
**Action:** Add verification function

```php
/**
 * Verify that theme base URI is accessible
 *
 * @param string $uri Theme base URI to test
 * @return bool True if accessible
 */
function kidsoverprofits_verify_theme_base_accessible($uri) {
    if (empty($uri)) {
        return false;
    }

    // Check if a known asset exists at this base
    $test_file = $uri . '/style.css';

    $response = wp_remote_head($test_file, array(
        'timeout' => 5,
        'sslverify' => false,
    ));

    if (is_wp_error($response)) {
        error_log(sprintf('[Kids Over Profits] Theme base verification failed for %s: %s', $uri, $response->get_error_message()));
        return false;
    }

    $status_code = wp_remote_retrieve_response_code($response);
    return ($status_code >= 200 && $status_code < 400);
}
```

---

## TASK 5: Improve Deployment Synchronization

### Issue
rsync deployment sometimes fails to transfer all files, causing missing assets on live server.

### Implementation Steps

#### Step 5.1: Update .cpanel.yml with verification
**File:** `.cpanel.yml`
**Action:** Replace contents with enhanced deployment script

```yaml
---
deployment:
  tasks:
    # Set deployment path
    - export DEPLOYPATH=/home/kidsover/public_html/wp-content/themes/child/

    # Sync files with rsync
    - /bin/rsync -aP --exclude '.git' --exclude '.cpanel.yml' --exclude '.env' --exclude 'node_modules' --exclude '.vscode' ./ $DEPLOYPATH

    # Verify critical files were deployed
    - test -f $DEPLOYPATH/functions.php || echo "ERROR: functions.php not deployed"
    - test -f $DEPLOYPATH/style.css || echo "ERROR: style.css not deployed"
    - test -d $DEPLOYPATH/css || echo "ERROR: css directory not deployed"
    - test -d $DEPLOYPATH/js || echo "ERROR: js directory not deployed"
    - test -d $DEPLOYPATH/api || echo "ERROR: api directory not deployed"

    # Set proper permissions
    - chmod 755 $DEPLOYPATH
    - chmod 644 $DEPLOYPATH/*.php || true
    - chmod 644 $DEPLOYPATH/css/*.css || true
    - chmod 644 $DEPLOYPATH/js/*.js || true
    - chmod 755 $DEPLOYPATH/api || true
    - chmod 600 $DEPLOYPATH/api/.env || true

    # Log deployment
    - echo "Deployment completed at $(date)" >> $DEPLOYPATH/deployment.log
    - echo "Git commit: $(git rev-parse --short HEAD)" >> $DEPLOYPATH/deployment.log
```

#### Step 5.2: Create deployment health check script
**File:** `tests/deployment-health-check.php` (NEW FILE)
```php
<?php
/**
 * Deployment Health Check
 * Verifies all critical files are present after deployment
 */

$root = dirname(__DIR__);

// Critical files that must exist
$critical_files = [
    'functions.php',
    'style.css',
    '.cpanel.yml',
    'css/common.css',
    'css/layout.css',
    'css/forms.css',
    'js/theme-base-bootstrap.js',
    'js/facility-form.v3.js',
    'api/config-loader.php',
];

// Critical directories that must exist
$critical_dirs = [
    'css',
    'js',
    'api',
    'api/data_form',
    'tests',
    'html',
];

$missing_files = [];
$missing_dirs = [];

// Check files
foreach ($critical_files as $file) {
    $path = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $file);
    if (!file_exists($path)) {
        $missing_files[] = $file;
    }
}

// Check directories
foreach ($critical_dirs as $dir) {
    $path = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $dir);
    if (!is_dir($path)) {
        $missing_dirs[] = $dir;
    }
}

// Report results
$has_errors = !empty($missing_files) || !empty($missing_dirs);

if ($has_errors) {
    echo "DEPLOYMENT HEALTH CHECK FAILED\n";
    echo str_repeat('=', 50) . "\n\n";

    if (!empty($missing_files)) {
        echo "Missing Files:\n";
        foreach ($missing_files as $file) {
            echo "  ✗ $file\n";
        }
        echo "\n";
    }

    if (!empty($missing_dirs)) {
        echo "Missing Directories:\n";
        foreach ($missing_dirs as $dir) {
            echo "  ✗ $dir\n";
        }
        echo "\n";
    }

    exit(1);
} else {
    echo "DEPLOYMENT HEALTH CHECK PASSED\n";
    echo str_repeat('=', 50) . "\n";
    echo "All critical files and directories are present.\n\n";

    // Additional checks
    $css_count = count(glob($root . '/css/*.css'));
    $js_count = count(glob($root . '/js/*.js'));
    $php_count = count(glob($root . '/*.php'));

    echo "File counts:\n";
    echo "  CSS files: $css_count\n";
    echo "  JS files: $js_count\n";
    echo "  PHP files: $php_count\n";

    exit(0);
}
```

#### Step 5.3: Add pre-commit hook to run health check
**File:** `.git/hooks/pre-commit` (create if doesn't exist)
```bash
#!/bin/sh
# Pre-commit hook to verify deployment readiness

echo "Running deployment health check..."

php tests/deployment-health-check.php

if [ $? -ne 0 ]; then
    echo ""
    echo "❌ Deployment health check failed. Please fix the issues before committing."
    exit 1
fi

echo "✅ Deployment health check passed"
exit 0
```

Make it executable:
```bash
chmod +x .git/hooks/pre-commit
```

---

## TASK 6: Add Asset Loading Diagnostics

### Issue
Difficult to debug asset loading failures without proper diagnostics.

### Implementation Steps

#### Step 6.1: Create comprehensive asset health checker
**File:** `js/asset-health-check.js` (NEW FILE)
```javascript
/**
 * Asset Health Check for Kids Over Profits
 * Comprehensive diagnostics for CSS, JS, and API endpoint loading
 */
(function initAssetHealthCheck() {
    'use strict';

    const HEALTH_CHECK_DELAY = 8000; // Run after 8 seconds
    const CSS_LOAD_TIMEOUT = 5000;

    const healthReport = {
        timestamp: new Date().toISOString(),
        themeBase: {
            detected: null,
            resolved: null,
            variants: [],
        },
        css: {
            expected: [],
            loaded: [],
            failed: [],
        },
        js: {
            expected: [],
            loaded: [],
            failed: [],
        },
        api: {
            base: null,
            endpoints: {},
        },
        errors: [],
    };

    /**
     * Check if stylesheet loaded successfully
     */
    function checkStylesheet(link) {
        try {
            if (link.sheet && link.sheet.cssRules) {
                return link.sheet.cssRules.length > 0;
            }
            if (link.sheet && !link.sheet.disabled) {
                return true;
            }
        } catch (e) {
            if (e.name === 'SecurityError') {
                return true; // Cross-origin, assume loaded
            }
            return false;
        }
        return false;
    }

    /**
     * Check CSS health
     */
    function checkCssHealth() {
        const stylesheets = document.querySelectorAll('link[rel="stylesheet"]');

        stylesheets.forEach(function(link) {
            const id = link.id || link.href;
            healthReport.css.expected.push(id);

            if (checkStylesheet(link)) {
                healthReport.css.loaded.push({
                    id: id,
                    href: link.href,
                });
            } else {
                healthReport.css.failed.push({
                    id: id,
                    href: link.href,
                    error: 'Failed to load or no CSS rules found',
                });
            }
        });
    }

    /**
     * Check JavaScript health
     */
    function checkJsHealth() {
        const scripts = document.querySelectorAll('script[src]');

        scripts.forEach(function(script) {
            const id = script.id || script.src;
            healthReport.js.expected.push(id);

            // Check if script executed (has error attribute or loaded)
            if (script.hasAttribute('data-error')) {
                healthReport.js.failed.push({
                    id: id,
                    src: script.src,
                    error: script.getAttribute('data-error'),
                });
            } else {
                healthReport.js.loaded.push({
                    id: id,
                    src: script.src,
                });
            }
        });
    }

    /**
     * Check theme base configuration
     */
    function checkThemeBase() {
        healthReport.themeBase.detected = window.KOP_DETECTED_THEME_BASE || null;
        healthReport.themeBase.resolved = window.KOP_RESOLVED_THEME_BASE || null;
        healthReport.themeBase.variants = window.KOP_THEME_BASES || [];

        if (!healthReport.themeBase.resolved) {
            healthReport.errors.push('Theme base not resolved');
        }
    }

    /**
     * Check API endpoints
     */
    async function checkApiHealth() {
        healthReport.api.base = window.KOP_API_BASE || null;

        if (!healthReport.api.base) {
            healthReport.errors.push('API base not initialized');
            return;
        }

        // Test critical endpoints
        const endpoints = [
            'get-master-data.php',
            'get-autocomplete.php',
        ];

        for (let i = 0; i < endpoints.length; i++) {
            const endpoint = endpoints[i];
            const url = healthReport.api.base + '/' + endpoint;

            try {
                const response = await fetch(url, {
                    method: 'HEAD',
                    cache: 'no-cache',
                });

                healthReport.api.endpoints[endpoint] = {
                    url: url,
                    status: response.status,
                    ok: response.ok,
                };
            } catch (error) {
                healthReport.api.endpoints[endpoint] = {
                    url: url,
                    error: error.message,
                    ok: false,
                };
            }
        }
    }

    /**
     * Generate health report
     */
    async function generateReport() {
        console.log('[KOP Health Check] Starting diagnostic scan...');

        checkThemeBase();
        checkCssHealth();
        checkJsHealth();
        await checkApiHealth();

        // Calculate summary
        const summary = {
            cssLoadRate: healthReport.css.expected.length > 0
                ? (healthReport.css.loaded.length / healthReport.css.expected.length * 100).toFixed(1) + '%'
                : 'N/A',
            jsLoadRate: healthReport.js.expected.length > 0
                ? (healthReport.js.loaded.length / healthReport.js.expected.length * 100).toFixed(1) + '%'
                : 'N/A',
            hasErrors: healthReport.errors.length > 0 || healthReport.css.failed.length > 0 || healthReport.js.failed.length > 0,
        };

        healthReport.summary = summary;

        // Store globally
        window.KOP_HEALTH_REPORT = healthReport;

        // Log to console
        console.log('[KOP Health Check] Report generated:', healthReport);

        if (summary.hasErrors) {
            console.warn('[KOP Health Check] Issues detected!');
            console.warn('CSS failures:', healthReport.css.failed);
            console.warn('JS failures:', healthReport.js.failed);
            console.warn('Errors:', healthReport.errors);
        } else {
            console.log('[KOP Health Check] All systems operational ✓');
        }

        // Trigger event
        const event = new CustomEvent('kop-health-check-complete', {
            detail: healthReport,
        });
        document.dispatchEvent(event);

        return healthReport;
    }

    /**
     * Display visual health indicator
     */
    function displayHealthIndicator(report) {
        // Only show if in debug mode
        const urlParams = new URLSearchParams(window.location.search);
        if (!urlParams.has('debug')) {
            return;
        }

        const indicator = document.createElement('div');
        indicator.id = 'kop-health-indicator';
        indicator.style.cssText = `
            position: fixed;
            bottom: 10px;
            right: 10px;
            background: ${report.summary.hasErrors ? '#ff4444' : '#44ff44'};
            color: white;
            padding: 10px 15px;
            border-radius: 5px;
            font-family: monospace;
            font-size: 12px;
            z-index: 999999;
            cursor: pointer;
            box-shadow: 0 2px 10px rgba(0,0,0,0.3);
        `;
        indicator.textContent = report.summary.hasErrors ? '⚠ Assets: Issues Detected' : '✓ Assets: OK';

        indicator.onclick = function() {
            console.table(report.css.failed);
            console.table(report.js.failed);
            console.log('Full report:', report);
        };

        document.body.appendChild(indicator);
    }

    /**
     * Run health check
     */
    async function runHealthCheck() {
        const report = await generateReport();
        displayHealthIndicator(report);
        return report;
    }

    // Export API
    window.KOP_HEALTH_CHECK = {
        run: runHealthCheck,
        getReport: function() { return window.KOP_HEALTH_REPORT; },
    };

    // Auto-run after delay
    setTimeout(runHealthCheck, HEALTH_CHECK_DELAY);

    console.log('[KOP Health Check] Diagnostic system loaded');
})();
```

#### Step 6.2: Enqueue asset health check in debug mode
**File:** `functions.php`
**Location:** In `kidsoverprofits_enqueue_test_harness_assets` function (around line 867)
**Action:** Add health check script

```php
// Enqueue asset health check in debug mode
if (isset($_GET['debug'])) {
    $health_check = kidsoverprofits_get_theme_asset_details('js/asset-health-check.js');
    if ($health_check) {
        wp_enqueue_script(
            'kidsoverprofits-health-check',
            $health_check['uri'],
            array('kidsoverprofits-theme-base-bootstrap'),
            $health_check['version'],
            true
        );
    }
}
```

---

## TASK 7: Consolidate Error Logging

### Issue
Error logging is scattered across multiple files with inconsistent formats.

### Implementation Steps

#### Step 7.1: Create centralized error logger
**File:** `functions.php`
**Location:** After line 40 (before first function)
**Action:** Add logging infrastructure

```php
/**
 * Centralized error logging for Kids Over Profits
 *
 * @param string $message Error message
 * @param string $level Error level: 'ERROR', 'WARNING', 'INFO', 'DEBUG'
 * @param array $context Additional context data
 */
function kidsoverprofits_log($message, $level = 'INFO', $context = array()) {
    // Only log if WP_DEBUG is enabled
    if (!defined('WP_DEBUG') || !WP_DEBUG) {
        return;
    }

    $timestamp = current_time('Y-m-d H:i:s');
    $formatted_context = !empty($context) ? ' | Context: ' . json_encode($context) : '';

    $log_message = sprintf(
        '[%s] [Kids Over Profits] [%s] %s%s',
        $timestamp,
        $level,
        $message,
        $formatted_context
    );

    error_log($log_message);

    // Store critical errors for display in admin
    if (in_array($level, ['ERROR', 'WARNING'], true)) {
        $stored_errors = get_transient('kop_recent_errors') ?: array();
        $stored_errors[] = array(
            'timestamp' => $timestamp,
            'level' => $level,
            'message' => $message,
            'context' => $context,
        );

        // Keep only last 50 errors
        $stored_errors = array_slice($stored_errors, -50);
        set_transient('kop_recent_errors', $stored_errors, DAY_IN_SECONDS);
    }
}

/**
 * Get recent errors for display in admin
 *
 * @return array Recent errors
 */
function kidsoverprofits_get_recent_errors() {
    return get_transient('kop_recent_errors') ?: array();
}

/**
 * Clear stored errors
 */
function kidsoverprofits_clear_errors() {
    delete_transient('kop_recent_errors');
}
```

#### Step 7.2: Replace all error_log calls with centralized logger
**File:** `functions.php`
**Action:** Find and replace all instances

Before:
```php
error_log(sprintf('[Kids Over Profits] Asset not found: %s', $relative_path));
```

After:
```php
kidsoverprofits_log(
    sprintf('Asset not found: %s', $relative_path),
    'ERROR',
    array('path' => $relative_path, 'function' => __FUNCTION__)
);
```

Repeat for all error_log calls in the file (lines 97, 171, 178, 273, 1291, 1477, 1501).

---

## TASK 8: Create Automated Asset Loading Tests

### Issue
No automated tests verify that assets load correctly on all pages.

### Implementation Steps

#### Step 8.1: Create comprehensive asset loading test
**File:** `tests/asset-loading-test.php` (NEW FILE)
```php
<?php
/**
 * Automated Asset Loading Tests
 * Verifies CSS, JS, and API endpoints are accessible
 */

require_once dirname(__DIR__) . '/functions.php';

class AssetLoadingTest {
    private $errors = [];
    private $warnings = [];
    private $passes = 0;

    /**
     * Run all tests
     */
    public function runAll() {
        echo "Kids Over Profits - Asset Loading Test Suite\n";
        echo str_repeat('=', 60) . "\n\n";

        $this->testCssFiles();
        $this->testJsFiles();
        $this->testApiEndpoints();
        $this->testThemeBaseResolution();

        $this->printResults();

        return empty($this->errors);
    }

    /**
     * Test CSS files are accessible
     */
    private function testCssFiles() {
        echo "Testing CSS Files...\n";

        $root = dirname(__DIR__);
        $css_files = glob($root . '/css/*.css');

        foreach ($css_files as $file) {
            $relative = str_replace($root . DIRECTORY_SEPARATOR, '', $file);
            $relative = str_replace('\\', '/', $relative);

            if (!file_exists($file)) {
                $this->addError("CSS file not found: $relative");
                continue;
            }

            if (filesize($file) === 0) {
                $this->addWarning("CSS file is empty: $relative");
                continue;
            }

            // Check for syntax errors (basic check)
            $content = file_get_contents($file);
            $open_braces = substr_count($content, '{');
            $close_braces = substr_count($content, '}');

            if ($open_braces !== $close_braces) {
                $this->addError("CSS syntax error in $relative: Mismatched braces");
                continue;
            }

            $this->addPass("CSS file OK: $relative");
        }
    }

    /**
     * Test JavaScript files are accessible
     */
    private function testJsFiles() {
        echo "\nTesting JavaScript Files...\n";

        $root = dirname(__DIR__);
        $required_js = [
            'js/theme-base-bootstrap.js',
            'js/facility-form.v3.js',
            'js/app-logic.js',
            'js/utilities.js',
        ];

        foreach ($required_js as $relative) {
            $file = $root . '/' . str_replace('/', DIRECTORY_SEPARATOR, $relative);

            if (!file_exists($file)) {
                $this->addError("Required JS file not found: $relative");
                continue;
            }

            if (filesize($file) === 0) {
                $this->addError("JS file is empty: $relative");
                continue;
            }

            $this->addPass("JS file OK: $relative");
        }
    }

    /**
     * Test API endpoints
     */
    private function testApiEndpoints() {
        echo "\nTesting API Endpoints...\n";

        $root = dirname(__DIR__);
        $endpoints = [
            'api/data_form/get-master-data.php',
            'api/data_form/get-autocomplete.php',
            'api/data_form/save-master.php',
            'api/data_form/save-suggestion.php',
        ];

        foreach ($endpoints as $relative) {
            $file = $root . '/' . str_replace('/', DIRECTORY_SEPARATOR, $relative);

            if (!file_exists($file)) {
                $this->addError("API endpoint not found: $relative");
                continue;
            }

            // Check for PHP syntax errors
            exec("php -l " . escapeshellarg($file) . " 2>&1", $output, $return_code);
            if ($return_code !== 0) {
                $this->addError("PHP syntax error in $relative: " . implode("\n", $output));
                continue;
            }

            $this->addPass("API endpoint OK: $relative");
        }
    }

    /**
     * Test theme base URI resolution
     */
    private function testThemeBaseResolution() {
        echo "\nTesting Theme Base Resolution...\n";

        // Simulate WordPress environment
        if (!function_exists('get_stylesheet_directory_uri')) {
            $this->addWarning("WordPress not loaded, skipping theme base test");
            return;
        }

        $theme_uri = get_stylesheet_directory_uri();
        $normalized = kidsoverprofits_normalize_theme_base_uri($theme_uri);

        if (empty($normalized)) {
            $this->addError("Theme base normalization failed");
            return;
        }

        if (strpos($normalized, '/themes/') === false && strpos($normalized, '/wp-content/themes/') === false) {
            $this->addError("Invalid theme base: $normalized");
            return;
        }

        $this->addPass("Theme base resolved: $normalized");

        // Test aliases
        $aliases = kidsoverprofits_get_theme_base_aliases($theme_uri);
        if (count($aliases) < 1) {
            $this->addError("No theme base aliases generated");
            return;
        }

        $this->addPass("Theme base aliases: " . count($aliases) . " variants");
    }

    /**
     * Add error
     */
    private function addError($message) {
        $this->errors[] = $message;
        echo "  ✗ FAIL: $message\n";
    }

    /**
     * Add warning
     */
    private function addWarning($message) {
        $this->warnings[] = $message;
        echo "  ⚠ WARN: $message\n";
    }

    /**
     * Add pass
     */
    private function addPass($message) {
        $this->passes++;
        echo "  ✓ PASS: $message\n";
    }

    /**
     * Print results summary
     */
    private function printResults() {
        echo "\n" . str_repeat('=', 60) . "\n";
        echo "Test Results:\n";
        echo "  Passed:   {$this->passes}\n";
        echo "  Warnings: " . count($this->warnings) . "\n";
        echo "  Errors:   " . count($this->errors) . "\n";

        if (empty($this->errors)) {
            echo "\n✅ ALL TESTS PASSED\n";
        } else {
            echo "\n❌ TESTS FAILED\n";
            echo "\nErrors:\n";
            foreach ($this->errors as $error) {
                echo "  - $error\n";
            }
        }

        echo str_repeat('=', 60) . "\n";
    }
}

// Run tests if executed directly
if (php_sapi_name() === 'cli') {
    $test = new AssetLoadingTest();
    $success = $test->runAll();
    exit($success ? 0 : 1);
}
```

#### Step 8.2: Add test to deployment pipeline
**File:** `.cpanel.yml`
**Action:** Add test step before deployment

```yaml
---
deployment:
  tasks:
    # Run pre-deployment tests
    - php tests/asset-loading-test.php || echo "WARNING: Asset tests failed"

    # Set deployment path
    - export DEPLOYPATH=/home/kidsover/public_html/wp-content/themes/child/

    # ... rest of deployment tasks
```

#### Step 8.3: Create test runner page
**File:** `tests/page-asset-tests.php` (NEW FILE)
```php
<?php
/**
 * Template Name: Asset Tests
 * Browser-based asset loading test runner
 */

get_header();
?>

<div class="container" style="padding: 40px 20px;">
    <h1>Asset Loading Tests</h1>
    <button id="run-tests-btn" style="padding: 10px 20px; font-size: 16px; cursor: pointer;">
        Run Tests
    </button>
    <div id="test-results" style="margin-top: 20px;"></div>
</div>

<script>
(function() {
    'use strict';

    const resultsDiv = document.getElementById('test-results');
    const runButton = document.getElementById('run-tests-btn');

    runButton.onclick = async function() {
        resultsDiv.innerHTML = '<p>Running tests...</p>';

        try {
            // Use health check API
            if (typeof window.KOP_HEALTH_CHECK !== 'undefined') {
                const report = await window.KOP_HEALTH_CHECK.run();
                displayResults(report);
            } else {
                resultsDiv.innerHTML = '<p style="color: red;">Health check system not loaded</p>';
            }
        } catch (error) {
            resultsDiv.innerHTML = '<p style="color: red;">Error: ' + error.message + '</p>';
        }
    };

    function displayResults(report) {
        let html = '<div style="font-family: monospace;">';

        html += '<h2>Test Results</h2>';
        html += '<p><strong>Timestamp:</strong> ' + report.timestamp + '</p>';

        // CSS Results
        html += '<h3>CSS Files</h3>';
        html += '<p>Loaded: ' + report.css.loaded.length + ' / ' + report.css.expected.length + '</p>';
        if (report.css.failed.length > 0) {
            html += '<ul style="color: red;">';
            report.css.failed.forEach(function(item) {
                html += '<li>✗ ' + item.id + ': ' + item.error + '</li>';
            });
            html += '</ul>';
        } else {
            html += '<p style="color: green;">✓ All CSS files loaded successfully</p>';
        }

        // JS Results
        html += '<h3>JavaScript Files</h3>';
        html += '<p>Loaded: ' + report.js.loaded.length + ' / ' + report.js.expected.length + '</p>';
        if (report.js.failed.length > 0) {
            html += '<ul style="color: red;">';
            report.js.failed.forEach(function(item) {
                html += '<li>✗ ' + item.id + ': ' + item.error + '</li>';
            });
            html += '</ul>';
        } else {
            html += '<p style="color: green;">✓ All JavaScript files loaded successfully</p>';
        }

        // API Results
        html += '<h3>API Endpoints</h3>';
        html += '<p>Base: ' + (report.api.base || 'Not initialized') + '</p>';
        if (report.api.endpoints) {
            html += '<ul>';
            for (let endpoint in report.api.endpoints) {
                const status = report.api.endpoints[endpoint];
                const color = status.ok ? 'green' : 'red';
                const icon = status.ok ? '✓' : '✗';
                html += '<li style="color: ' + color + ';">' + icon + ' ' + endpoint + ' (' + (status.status || status.error) + ')</li>';
            }
            html += '</ul>';
        }

        // Overall Status
        html += '<h2 style="color: ' + (report.summary.hasErrors ? 'red' : 'green') + ';">';
        html += report.summary.hasErrors ? '❌ Tests Failed' : '✅ All Tests Passed';
        html += '</h2>';

        html += '</div>';

        resultsDiv.innerHTML = html;
    }
})();
</script>

<?php
get_footer();
?>
```

---

## Post-Implementation Verification

### Verification Checklist

After implementing all tasks, verify:

- [ ] **Security:** Database credentials moved to `.env` file
- [ ] **CSS Loading:** No pages stuck in "Loading..." state
- [ ] **API Endpoints:** Data forms submit successfully without 404 errors
- [ ] **Theme Base:** Assets load correctly on all pages
- [ ] **Deployment:** All files sync correctly via rsync
- [ ] **Error Logging:** Centralized logging working
- [ ] **Tests:** All automated tests passing

### Testing Commands

```bash
# Local testing
php tests/asset-loading-test.php
php tests/deployment-health-check.php
php tests/check-css-assets.php

# Browser testing
# Visit: https://kidsoverprofits.org/any-page?debug=all

# API testing
curl https://kidsoverprofits.org/wp-content/themes/child/api/data_form/get-master-data.php
```

### Rollback Plan

If issues occur after deployment:

1. **Immediate rollback:**
```bash
git revert HEAD
git push origin main
```

2. **Restore database config:**
- Temporarily restore `api/config.php` with hardcoded credentials
- Remove `config-loader.php` include from API files

3. **Disable new features:**
- Set `define('KOP_LEGACY_MODE', true);` in `functions.php`
- Skip new asset loaders in conditional blocks

---

## Timeline Estimate

| Task | Estimated Time | Priority |
|------|----------------|----------|
| Task 1: Security fix | 45 minutes | CRITICAL |
| Task 2: CSS loading | 90 minutes | HIGH |
| Task 3: API paths | 60 minutes | HIGH |
| Task 4: Theme base | 45 minutes | MEDIUM |
| Task 5: Deployment | 30 minutes | MEDIUM |
| Task 6: Diagnostics | 60 minutes | LOW |
| Task 7: Logging | 30 minutes | LOW |
| Task 8: Testing | 45 minutes | MEDIUM |
| **Total** | **6 hours 45 minutes** | |

---

## Notes for ChatGPT Codex

1. **Execute tasks sequentially in order** (1 through 8)
2. **Test after each task** before proceeding to the next
3. **Commit after each task** with descriptive message
4. **Do NOT push** until all tasks are complete and tested
5. **Ask for clarification** if any file paths don't match
6. **Preserve existing functionality** - add, don't replace
7. **Run PHP syntax check** after editing PHP files: `php -l filename.php`
8. **Verify file line numbers** before editing (files may have changed)

---

## Success Criteria

✅ **Zero CSS loading errors** in browser console
✅ **Zero 404 errors** for API endpoints
✅ **All automated tests passing**
✅ **Database credentials secured** in `.env` file
✅ **No deployment failures** on push
✅ **Error logging centralized** and working
✅ **Health check system** operational

---

## Support Contacts

- **Developer:** dani@kidsoverprofits.org
- **Repository:** https://github.com/carlygaejepsen/Kids-Over-Profits
- **Deployment:** cPanel Git Deploy

---

**End of Implementation Plan**
