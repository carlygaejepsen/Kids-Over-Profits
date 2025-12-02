# Console Debug Output Guide

## What to Look For

After refreshing your data form page, open Browser Console (F12 → Console tab) and look for these messages:

### ✅ If Everything Works, You'll See:
```
🔍 DEBUG: enqueue_facility_form_script() was CALLED
✅ DEBUG: Passed is_singular() check
📄 Template: page-data.php
📝 Page slug: [your-page-slug]
🔍 is_data_template: TRUE
🔍 is_admin_template: FALSE
🔍 is_data_form_page: TRUE
✅ DEBUG: Template check PASSED - proceeding to enqueue scripts
```

### ❌ If Template Check Fails, You'll See:
```
🔍 DEBUG: enqueue_facility_form_script() was CALLED
✅ DEBUG: Passed is_singular() check
📄 Template: [some-other-template]
📝 Page slug: [your-page-slug]
🔍 is_data_template: FALSE
🔍 is_admin_template: FALSE
🔍 is_data_form_page: FALSE
❌ DEBUG: Template check FAILED - returning early, scripts will NOT be enqueued
```

### ❌ If Not a Singular Page:
```
🔍 DEBUG: enqueue_facility_form_script() was CALLED
❌ DEBUG: FAILED is_singular() check
```

### ❌ If Function Never Runs:
```
(no messages at all)
```

## What Each Scenario Means

### Scenario 1: Function Not Called
**No debug messages appear**
- The `wp_enqueue_scripts` hook isn't firing
- The function might be commented out or removed
- Check line 2008 in functions.php for: `add_action('wp_enqueue_scripts', 'enqueue_facility_form_script');`

### Scenario 2: is_singular() Fails
**Shows "FAILED is_singular()" error**
- Page is not recognized as a single page/post
- Might be on homepage, archive, or category page
- Solution: Make sure you're viewing an actual WordPress Page

### Scenario 3: Template Check Fails
**Shows "is_data_form_page: FALSE"**
- WordPress doesn't recognize the template
- Look at what "📄 Template:" and "📝 Page slug:" show
- The template or slug doesn't match expected values

### Scenario 4: Everything Passes
**Shows "Template check PASSED"**
- Scripts SHOULD be loading
- If they're still not loading, there's a different issue (file paths, permissions, etc.)

## Copy the Output

**Please copy ALL the console messages you see** (especially the Template and Page slug lines) and share them so I can see exactly what's happening!
