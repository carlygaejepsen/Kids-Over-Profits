# Facility Form Template Setup

## Completed: Proper WordPress Template Structure for facility-form.v3.js

### Problem
The WordPress templates `page-data.php` and `page-admin-data.php` only contained minimal HTML containers, but `facility-form.v3.js` expected the full form structure to be present on page load.

### Solution
Created a proper template system that includes the complete HTML structure required by the JavaScript.

## Files Created

### 1. Template Fragments
- **[templates/facility-form-public.php](templates/facility-form-public.php)** (75KB)
  - Contains complete HTML structure for public facility data submission form
  - Extracted from `data.html` (lines 11-1183)
  - Includes all required elements:
    - Category navigation tabs
    - Project management sections
    - Facility forms for Companies, Locations, and Referrers
    - All form sections (Identification, Location, Operations, Staff, etc.)
    - Modal dialogs for cloning and data organization
    - Submission section for suggestions

- **[templates/facility-form-admin.php](templates/facility-form-admin.php)** (68KB)
  - Contains complete HTML structure for admin facility data management
  - Extracted from `admin-data.html` (lines 11-1131)
  - Same structure as public form but with admin-specific text and saving to master database

### 2. Updated WordPress Templates

#### [page-data.php](page-data.php)
```php
<?php
/**
 * Template Name: Facility Data Submission Form
 *
 * Public-facing form for submitting facility data suggestions.
 */

get_header();

// Load the complete HTML structure that facility-form.v3.js expects
require get_stylesheet_directory() . '/templates/facility-form-public.php';

get_footer();
```

#### [page-admin-data.php](page-admin-data.php)
```php
<?php
/**
 * Template Name: Admin Facility Data Form
 *
 * Administrator form for direct facility data entry and management.
 */

get_header();

// Load the complete HTML structure that facility-form.v3.js expects
require get_stylesheet_directory() . '/templates/facility-form-admin.php';

get_footer();
```

## Enqueue System

The enqueue system in [functions.php](functions.php) is properly configured:

### CSS Files
- **colors.css** - Loaded globally (line 24)
- **data-form.css** - Loaded for data form pages with dependency on `kop-colors` (lines 712-717)

### JavaScript Files
Loaded in this order for data form templates:
1. **jQuery** (WordPress core)
2. **db-form-loader.js** (lines 732-738) - Handles data loading
3. **facility-form.v3.js** (lines 741-751) - Main form functionality
4. **data-page.js** OR **admin-data-page.js** (lines 773-799) - Page-specific logic

### Configuration
JavaScript receives configuration via `KOP_FACILITY_FORM_CONFIG`:
```javascript
{
    fallbackProjectsUrl: '...',
    fallbackProjectsUrls: [...],
    apiBase: 'https://kidsoverprofits.org'
}
```

## HTML Elements Required by facility-form.v3.js

The template files now include all required elements:

### Category Navigation
- `.category-tab` - Category switching buttons
- `.category-content` - Content areas for each category
- `#category-navigation` - Main navigation container

### Project Management
- `#project-status` - Project status display
- `#company-saved-projects-list` - Saved companies projects
- `#location-saved-projects-list` - Saved location projects
- `#referrer-saved-projects-list` - Saved referrer projects

### Main Wrappers
- `#facility-main-wrapper` - Facility form sections
- `#referrer-main-wrapper` - Referrer-specific sections

### Form Sections
- `#operator-section` - Operator information
- `#identification-section` - Facility identification
- `#location-section` - Location and address
- `#operations-section` - Operating period
- `#staff-section` - Staff information
- `#facility-section` - Facility details
- `#accreditations-section` - Accreditations
- `#resources-section` - Resources available
- `#treatment-section` - Treatment types
- `#philosophy-section` - Philosophy
- `#incidents-section` - Critical incidents
- `#notes-section` - General notes
- `#submission-section` - Submission controls

### Form Fields
- `#facility-name` - Main facility name input
- `#operator-name` - Operator name input
- `#project-name` - Project name for saving
- `#referrer-project-name` - Referrer project name
- `.facility-field` - Auto-binding facility fields
- `.array-container` - Dynamic array field containers
- `.autocomplete-wrapper` - Autocomplete inputs

### Modals
- `#clone-facility-modal` - Clone facility dialog
- `#data-organizer-modal` - Data search/organization
- `#suggestion-reason-modal` - Submission reason input

## Template Detection

The `kop_is_headerless_layout()` function in [functions.php](functions.php:40-42) detects these templates:

```php
function kop_is_headerless_layout() {
    return is_page_template('page-data.php') || is_page_template('page-admin-data.php');
}
```

## Color System Integration

All form templates use the centralized color system from [css/colors.css](css/colors.css):
- `--kop-teal` (#33A7B5) - Primary buttons and accents
- `--kop-mint-green` (#B6E3D4) - Borders and success colors
- `--kop-soft-pastel-yellow` (#FFF5CB) - Background accents
- `--kop-midnight-blue` (#000435) - Primary text color
- `--kop-sand` (#F2EEDF) - Page backgrounds
- Full color palette documented in [COLOR_SYSTEM_SUMMARY.md](COLOR_SYSTEM_SUMMARY.md)

## Testing

To verify the setup works:
1. Create a new page in WordPress
2. Select "Facility Data Submission Form" or "Admin Facility Data Form" as the page template
3. View the page - you should see the complete form with all sections
4. The JavaScript should initialize and bind to all form elements
5. Project management, data loading, and form submission should all function correctly

## Architecture Benefits

This architecture provides:
- **Clean separation** - PHP templates handle structure, JavaScript handles functionality
- **Maintainability** - HTML structure is in template files, not scattered in JavaScript
- **Performance** - Full DOM structure available on page load, no JavaScript-based rendering delays
- **Flexibility** - Can modify HTML structure without touching JavaScript
- **Reusability** - Template fragments can be included in other contexts if needed
