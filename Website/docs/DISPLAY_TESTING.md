# Display Testing Tools

This document explains how to use the built-in testing tools to troubleshoot display issues on the Kids Over Profits website.

## Available Testing Tools

The following testing tools are available:

1. **CSS Loading Test**: Verifies that all CSS files are properly loaded
2. **Report Data Test**: Checks if state report data is loading correctly
3. **Visual Regression Test**: Helps identify visual display issues with grid overlay, element highlighting, and measurement tools

## How to Use the Testing Tools

### Enabling Test Mode

To enable any test, add a `debug` parameter to the page URL:

- `?debug=css` - Run CSS loading test
- `?debug=report` - Run report data loading test
- `?debug=visual` - Run visual regression test
- `?debug=all` - Run all tests

Examples:
- `https://kidsoverprofits.org/ca-reports/?debug=css`
- `https://kidsoverprofits.org/ut-reports/?debug=report`
- `https://kidsoverprofits.org/tti-program-index/?debug=visual`
- `https://kidsoverprofits.org/data/?debug=all`

### CSS Loading Test

The CSS loading test verifies that all required CSS files are properly loaded on the page. When enabled, it now displays a diagnostic card in the top-right corner that includes:

- Page context (title, URL, timestamp)
- A pass/fail summary covering every stylesheet that should be active on the current page
- Detailed results for each handle including the selectors and CSS properties that were validated

Legend used in the panel:

- ✅ **Pass** – All selector checks for the stylesheet matched the expected values
- ❌ **Issue** – The stylesheet was expected but not enqueued, or one of the selector checks failed
- ℹ️ **Info** – The stylesheet loaded even though it is optional for the current page (helpful when debugging unexpected overrides)
- ⏭ **Skipped** – The stylesheet was neither expected nor loaded; no selector checks were executed

If a stylesheet fails, the panel shows which selector/property mismatch occurred so you can inspect the relevant CSS quickly. Common fixes include:
1. File paths in `functions.php`
2. File permissions on the server
3. WordPress caching issues

### Report Data Test

The report data test checks whether the JSON bundles required by state report pages are loading correctly. The updated panel highlights:

- Page context and a list of WordPress script handles that should be enqueued for the page, with ✅/❌ status indicators
- Every localized data object detected in `functions.php`, including the keys exposed to JavaScript
- The active data object on the page (`myThemeData`, `facilitiesConfig`, etc.) and every JSON URL discovered from that object or its localizations
- Live fetch tests for each JSON URL with structure validation and a trimmed data preview so you can confirm the payload contents

Result legend for JSON fetches:

- ✅ **Loaded** – The request succeeded and the script detected the expected structure (facilities, reports, or projects)
- ⚠️ **Unexpected** – The request succeeded but the structure was unfamiliar (often caused by malformed exports)
- ❌ **Error** – The request failed (404, 403, network issue, etc.)

If you see errors or warnings, check:
1. JSON file paths in `functions.php`
2. JSON file existence on the server
3. JSON file syntax (validate with a JSON validator)
4. Network issues or CORS restrictions

### Visual Regression Test

The visual regression test provides tools to identify display issues. When enabled, it displays a control panel at the bottom-right with three main tools:

#### 1. Toggle Grid
Shows a grid overlay to check alignment and spacing issues.

#### 2. Highlight Issues
Automatically identifies and highlights elements with potential display problems:
- Red outlines: Overflow issues (content exceeding container)
- Blue outlines: Positioning issues (absolute/fixed positioning problems)
- Orange outlines: Dimension issues (unusually small or large elements)

#### 3. Measure Tool
Allows you to inspect elements by hovering over them:
- Shows element dimensions
- Shows position information
- Shows CSS properties (margin, padding, display, etc.)
- Click on an element to see detailed information

## Common Display Issues and Solutions

### 1. CSS Not Loading

**Symptoms:**
- Missing styles
- Unstyled elements
- CSS test shows files not loading

**Solutions:**
- Check file paths in `functions.php`
- Verify file permissions (644 for files, 755 for directories)
- Clear WordPress cache and browser cache
- Check for JavaScript errors in browser console
- Verify page slug matches the condition in `functions.php`

### 2. Report Data Not Loading

**Symptoms:**
- Empty report pages
- Missing facility information
- Report test shows JSON loading errors

**Solutions:**
- Check JSON file paths in `functions.php`
- Verify JSON file syntax
- Check browser console for AJAX errors
- Verify `myThemeData` object is properly defined
- Check network tab in browser dev tools for 404 errors

### 3. Layout and Alignment Issues

**Symptoms:**
- Elements not aligned properly
- Overflow issues
- Inconsistent spacing

**Solutions:**
- Use the grid overlay to check alignment
- Use the highlight tool to identify problematic elements
- Check responsive behavior at different screen sizes
- Verify CSS specificity and rule order
- Check for conflicting styles from parent theme

### 4. Responsive Design Issues

**Symptoms:**
- Layout breaks at certain screen sizes
- Elements overlap on mobile
- Horizontal scrolling appears

**Solutions:**
- Test at various viewport sizes
- Use the measure tool to check element dimensions
- Verify media queries are working correctly
- Check for fixed-width elements causing overflow
- Ensure images have max-width: 100%

## Adding Tests to New Pages

If you create new pages that need these testing tools, they will automatically work as long as:

1. The page is using WordPress templates (not hardcoded HTML)
2. You add the `?debug=` parameter to the URL

No additional configuration is needed as the test scripts are loaded globally based on the URL parameter.

## Troubleshooting the Test Tools

If the test tools themselves aren't working:

1. Check browser console for JavaScript errors
2. Verify the test script files exist on the server:
   - `/js/css-test.js`
   - `/js/report-test.js`
   - `/js/visual-test.js`
3. Make sure jQuery is loaded before the test scripts
4. Check if WordPress is stripping query parameters (some caching plugins do this)

## Best Practices for Fixing Display Issues

1. **Isolate the problem**: Use the testing tools to identify exactly which elements are causing issues
2. **Check the CSS**: Look for conflicting styles or specificity issues
3. **Verify HTML structure**: Make sure the HTML structure is correct and semantic
4. **Test incrementally**: Make small changes and test after each change
5. **Document fixes**: Note what was changed and why for future reference
6. **Test across browsers**: Verify fixes work in Chrome, Firefox, Safari, and Edge
7. **Test responsively**: Check at multiple screen sizes (desktop, tablet, mobile)

## Getting Help

If you're still experiencing display issues after using these tools, collect the following information:

1. Screenshots of the issue
2. Browser and device information
3. URL where the issue occurs
4. Results from the testing tools
5. Any relevant browser console errors

Then contact the development team with this information for further assistance.