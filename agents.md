# Kids Over Profits - Project Documentation

## Project Overview

**Kids Over Profits** is a 501(c)(3) nonprofit organization dedicated to shining a light on the Troubled Teen Industry and all forms of institutional child abuse. Our priorities are transparency, justice, and dignity for kids.

This repository contains the codebase for our public database tracking inspection reports, violations, and facility information for youth residential treatment facilities across the United States.

**Contact**: dani@kidsoverprofits.org

**License**: Public Domain

**Intended Audience**: General public, with particular value for journalists, lawyers, advocates, and researchers investigating institutional child abuse.

---

## Current Features

### 1. Multi-State Facility Database
- **States Currently Covered**: California (CA), Utah (UT), Arizona (AZ), Texas (TX), Montana (MT), Connecticut (CT), Washington (WA)
- **Planned Expansion**: Oregon, Arkansas, and additional states
- **Update Frequency**: Currently monthly, with plans for more frequent automated updates

### 2. State-Specific Report Pages
Each state has a dedicated report page displaying:
- Facility inspection reports
- Regulatory non-compliance incidents
- Corrective actions and recommendations
- Visit details and personnel information
- Staff vacancies and facility capacity data

### 3. TTI Program Index
A comprehensive searchable directory of all tracked facilities with filtering capabilities.

### 4. Anonymous Document Portal
A secure, encrypted system for whistleblowers and concerned parties to submit documents anonymously:
- **Security Features**:
  - No IP logging
  - End-to-end encryption
  - Automatic metadata stripping
  - Secure file storage with .htaccess protection
  - 90-day automatic deletion
  - Integrated virus scanning and security checks via CloudIverse API
- **Supported File Types**: PDF, DOC, DOCX, TXT, JPG, PNG, ZIP (max 10MB)
- **Admin Dashboard**: WordPress admin interface for reviewing submissions

### 5. Community Data Contribution System
- **Public Submissions**: Anyone can suggest facility data corrections or additions
- **Admin Moderation**: Administrators review and approve suggestions before they're added to the master database
- **Parent Organization Management**: Facilities and reports are currently organized and managed by parent company or organization. This is important because it enables tracking of networks and chains, but also means that privately owned, unaffiliated programs are not yet fully supported.
- **Planned Feature**: Future updates will add support for browsing and managing facilities by location, including "State", "City, State", "Country", or "City, Country". This will open up the database to include privately owned programs and facilities that are not part of a larger organization.
- **Workflow**: Suggestion → Admin Review → Approval → Master Data Update

---

## Technical Architecture

### Platform
- **CMS**: WordPress (hosted on cPanel)
- **Theme**: Kadence (parent) + Custom Child Theme
- **Backend**: PHP 7.4+
- **Database**: MySQL (managed via phpMyAdmin in cPanel)
- **Deployment**: Automated via cPanel Git deployment

### Project Structure

```
Kids-Over-Profits/
├── .cpanel.yml                 # Automated deployment configuration
├── Website/
│   ├── functions.php          # Child theme functions and custom features
│   ├── api/                   # Backend API endpoints
│   │   ├── config.php         # Database configuration
│   │   ├── get-master-data.php    # Retrieve approved facility data
│   │   ├── save-suggestion.php    # Save public data suggestions
│   │   ├── get-suggestions.php    # Retrieve data for admin autocomplete dropdowns
│   │   ├── process-edit.php       # Process data edits
│   │   └── save-master.php        # Save approved data to master
│   ├── html/                  # Page templates
│   │   ├── ca-reports.html
│   │   ├── ut-reports.html
│   │   ├── az-reports.html
│   │   ├── tx-reports.html
│   │   ├── mt-reports.html
│   │   ├── ct-reports.html
│   │   ├── wa-reports.html
│   │   ├── data.html          # Data submission form
│   │   └── admin-data.html    # Admin data management
│   ├── js/                    # Frontend JavaScript
│   │   ├── ca-reports.js      # Multi-file CA report loader
│   │   ├── ut_reports.js
│   │   ├── az_reports.js
│   │   ├── tx_reports.js
│   │   ├── mt_reports.js
│   │   ├── ct_reports.js
│   │   ├── wa_reports.js
│   │   ├── facilities-display.js  # TTI program index
│   │   ├── facility-form.js   # Data submission form
│   │   ├── admin-data.js      # Admin interface
│   │   ├── anonymous-portal.js    # Anonymous document submission
│   │   └── data/              # JSON data files
│   │       ├── ccl_*.json     # California reports (batched)
│   │       ├── ut_reports.json
│   │       ├── az_reports/*.json
│   │       ├── tx_reports.json
│   │       ├── mt_reports.json
│   │       ├── ct_reports.json
│   │       ├── wa_reports.json
│   │       └── facilities_data.json
│   └── css/
│       └── anonymous-portal.css
└── Scripts/                   # Data processing utilities (optional)
    ├── CA_json/              # Raw California data batches
    ├── az_inspections/       # Raw Arizona inspection data
    ├── checklists/           # State-specific checklists
    ├── dcf_facilities_clean.json
    └── facility_input.html   # Data entry tool
```

---

## Database Setup

### Required Tables

#### 1. Anonymous Submissions Table
Created automatically by the `AnonymousDocPortal` class in `functions.php`.

```sql
CREATE TABLE wp_anonymous_submissions (
    id int(11) NOT NULL AUTO_INCREMENT,
    submission_id varchar(20) NOT NULL,
    file_count int(11) NOT NULL,
    encrypted_message longtext,
    contact_method varchar(20),
    encrypted_contact text,
    legal_confirmation tinyint(1) DEFAULT 0,
    redaction_needed tinyint(1) DEFAULT 0,
    submission_date datetime NOT NULL,
    files_data longtext,
    status varchar(20) DEFAULT 'pending',
    notes longtext,
    PRIMARY KEY (id),
    UNIQUE KEY submission_id (submission_id),
    INDEX status_idx (status),
    INDEX submission_date_idx (submission_date)
);
```

**Status Values**:
- `pending`: New submission awaiting review
- `reviewed`: Reviewed by admin
- `archived`: Archived for records
- `files_deleted`: Files have been removed

#### 2. Suggestions Database
Database: `kidsover_suggestions`

The suggestions system uses a database for community-contributed facility data that awaits admin approval.

**Configuration** (in `api/config.php`):
- Host: `localhost`
- Database: `kidsover_suggestions`
- User: `kidsover_dani`
- Charset: `utf8mb4`

**Tables** (structure to be defined based on your schema):
- Master facility data table
- Pending suggestions table
- Edit history/audit log

### Encryption
The anonymous portal uses AES-256-CBC encryption for sensitive data:
- Encryption key derived from WordPress salts (`SECURE_AUTH` + `NONCE_SALT`)
- Random IV per encryption operation
- Message and contact info encrypted separately

### Database Management via phpMyAdmin

All database operations are managed through **phpMyAdmin** in cPanel.

**Accessing phpMyAdmin**:
1. Log into cPanel
2. Navigate to "Databases" section
3. Click "phpMyAdmin"

**Common Tasks**:

**Creating the Suggestions Database**:
1. In phpMyAdmin, click "New" in the left sidebar
2. Enter database name: `kidsover_suggestions`
3. Select collation: `utf8mb4_general_ci`
4. Click "Create"

**Creating Database User**:
1. In cPanel, go to "MySQL Databases"
2. Under "Add New User", create user `kidsover_dani`
3. Generate a secure password
4. Add user to database with ALL PRIVILEGES

**Checking Anonymous Submissions Table**:
1. Select your WordPress database in phpMyAdmin
2. Look for table `wp_anonymous_submissions` (or `{prefix}_anonymous_submissions`)
3. If not present, the table will be created automatically when the anonymous portal is first accessed

**Backing Up Data**:
1. In phpMyAdmin, select the database
2. Click "Export" tab
3. Choose "Quick" method and "SQL" format
4. Click "Go" to download backup file

**Importing Data**:
1. Select database in phpMyAdmin
2. Click "Import" tab
3. Choose your SQL file
4. Click "Go"

---

## Deployment

### cPanel Git Deployment

The project uses automated deployment via cPanel's Git Version Control.

**Configuration** (`.cpanel.yml`):
```yaml
deployment:
  tasks:
    - export DEPLOYPATH=/home/kidsover/public_html/wp-content/themes/child/
    - /bin/rsync -aP --exclude '.git' --exclude '.cpanel.yml' ./ $DEPLOYPATH
```

**Deployment Path**: `/home/kidsover/public_html/wp-content/themes/child/`

**Process**:
1. Push changes to the connected Git repository
2. cPanel automatically detects the push
3. Runs rsync to sync files (excluding .git and .cpanel.yml)
4. Child theme is updated in WordPress

### Manual Deployment Steps

If deploying manually via cPanel File Manager or FTP:

1. **Upload child theme files** to:
   ```
   /home/kidsover/public_html/wp-content/themes/child/
   ```

2. **Set file permissions**:
   - Directories: 755
   - PHP files: 644
   - Anonymous submissions directory: 755 (created automatically)

3. **Verify .htaccess protection** for anonymous submissions:
   ```
   /wp-content/uploads/anonymous-submissions/.htaccess
   ```

4. **Activate theme** in WordPress admin:
   - Appearance → Themes
   - Activate the Kadence child theme

5. **Create required pages** with these slugs:
   - `ca-reports` (California Reports)
   - `ut-reports` (Utah Reports)
   - `az-reports` (Arizona Reports)
   - `tx-reports` (Texas Reports)
   - `mt-reports` (Montana Reports)
   - `ct-reports` (Connecticut Reports)
   - `wa-reports` (Washington Reports)
   - `tti-program-index` (Facility Directory)
   - `data` (Data Submission Form)

6. **Add Anonymous Portal shortcode** to a page:
   ```
   [anonymous_doc_portal title="Submit Documents Anonymously" description="Help us expose institutional child abuse by submitting documents securely."]
   ```

---

## Development Workflow

### Adding a New State

1. **Prepare JSON data** in the format:
   ```json
   {
     "facilities": [
       {
         "facility_info": {
           "facility_name": "Example Facility",
           "address": "123 Main St",
           "city_state_zip": "City, State ZIP",
           "phone": "(555) 123-4567",
           "program_category": "Group Home",
           "bed_capacity": "12",
           "executive_director": "Name",
           "license_exp_date": "MM/DD/YYYY",
           "qtly_visit_date": "MM/DD/YYYY"
         },
         "reports": [
           {
             "categories": {
               "visit_details": {},
               "areas_topics_covered": [],
               "regulatory_non_compliance": [],
               "corrective_actions": [],
               "recommendations": []
             }
           }
         ]
       }
     ]
   }
   ```

2. **Create JavaScript file** (e.g., `js/or_reports.js`) based on existing state templates

3. **Add data file** to `js/data/` (e.g., `or_reports.json`)

4. **Register script in functions.php**:
   ```php
   function load_or_reports_scripts() {
       if (is_page('or-reports')) {
           $script_path = get_stylesheet_directory() . '/js/or_reports.js';

           wp_enqueue_script(
               'or-reports-display',
               get_stylesheet_directory_uri() . '/js/or_reports.js',
               array(),
               file_exists($script_path) ? filemtime($script_path) : time(),
               true
           );

           wp_localize_script('or-reports-display', 'myThemeData', array(
               'jsonFileUrls' => array(
                   get_stylesheet_directory_uri() . '/js/data/or_reports.json'
               )
           ));
       }
   }
   add_action('wp_enqueue_scripts', 'load_or_reports_scripts');
   ```

5. **Create WordPress page** with slug `or-reports`

6. **Test** the report display and filtering

### Data Update Process

1. **Collect new inspection data** from state regulatory agencies
2. **Process/clean data** using Scripts utilities (optional)
3. **Update JSON files** in `Website/js/data/`
4. **Test locally** if possible
5. **Push to Git repository**
6. **Verify deployment** on live site

### Community Contribution Workflow

**For Public Users**:
1. Visit the data submission page (`/data`)
2. Fill out facility information form
3. Submission saved to suggestions database

**For Administrators**:
1. Log into WordPress admin
2. Access admin data interface
3. Review pending suggestions
4. Approve/reject submissions
5. Approved data merged into master database

---

## API Endpoints

All API endpoints are in the `Website/api/` directory.

### GET `/api/get-master-data.php`
Retrieves approved facility data from the master database.

**Response**:
```json
{
  "success": true,
  "data": [...]
}
```

### POST `/api/save-suggestion.php`
Saves a public suggestion for admin review.

**Parameters**: Facility data fields
**Response**:
```json
{
  "success": true,
  "message": "Suggestion saved successfully"
}
```

### GET `/api/get-suggestions.php`
Retrieves data for populating autocomplete dropdowns (e.g., facility names, cities, or other fields) in the admin interface.

**Response**:
```json
{
  "success": true,
  "suggestions": [...]
}
```

### POST `/api/process-edit.php`
Processes admin edits to facility data.

**Parameters**: Edit data
**Response**:
```json
{
  "success": true,
  "message": "Edit processed successfully"
}
```

### POST `/api/save-master.php`
Saves approved data to master database (admin only).

**Parameters**: Facility data
**Response**:
```json
{
  "success": true,
  "message": "Data saved to master"
}
```

---

## WordPress Integration

### Custom Functions

Located in `Website/functions.php`:

#### State Report Loaders
- `load_new_multi_file_report_scripts()` - California (multi-file)
- `load_ut_reports_scripts()` - Utah
- `load_az_reports_scripts()` - Arizona
- `load_tx_reports_scripts()` - Texas
- `enqueue_montana_reports_scripts()` - Montana
- `enqueue_ct_reports_scripts()` - Connecticut
- `enqueue_wa_reports_scripts()` - Washington

#### Facility Display
- `load_facilities_data()` - TTI Program Index page
- `enqueue_facilities_script()` - Facilities directory script

#### Forms
- `enqueue_facility_form_script()` - Data submission form

#### Anonymous Portal
- `AnonymousDocPortal` class - Complete anonymous submission system

### Shortcodes

#### `[anonymous_doc_portal]`
Displays the anonymous document submission portal.

**Attributes**:
- `title` - Portal heading (optional)
- `description` - Portal description text (optional)

**Example**:
```
[anonymous_doc_portal
  title="Submit Documents Anonymously"
  description="Help us expose institutional child abuse by submitting documents securely."]
```

### Admin Pages

**Anonymous Submissions** (`Tools → Anonymous Docs`):
- View all anonymous submissions
- Decrypt and read messages
- Update submission status
- Delete files for privacy compliance
- Track legal confirmations and redaction needs

---

## Security Features

### Anonymous Portal
- No IP address logging
- AES-256-CBC encryption for messages and contact info
- Automatic EXIF/metadata stripping from images
- MIME type validation beyond file extensions
- .htaccess protection on upload directory
- File size limits (10MB max)
- Restricted file types
- 90-day automatic deletion policy

### File Upload Validation
- Extension whitelist
- MIME type verification
- Size limits enforced
- Secure random filename generation
- Isolated storage directory

### Database Security
- PDO prepared statements
- Input sanitization
- WordPress nonce verification for AJAX requests
- Admin capability checks

---

## Data Format Specifications

### Facility Data Structure

```json
{
  "facility_info": {
    "facility_name": "string",
    "program_name": "string",
    "address": "string",
    "city_state_zip": "string",
    "phone": "string (###) ###-####",
    "executive_director": "string",
    "program_category": "string",
    "bed_capacity": "string (number)",
    "license_exp_date": "MM/DD/YYYY",
    "qtly_visit_date": "MM/DD/YYYY (multiple dates newline-separated)",
    "relicense_visit_date": "MM/DD/YYYY",
    "action": "string (optional)"
  },
  "reports": [
    {
      "categories": {
        "visit_details": {
          "facility_name": "string",
          "visit_date": "string",
          "personnel": ["array of roles/names"],
          "additional_info": "string (optional)"
        },
        "areas_topics_covered": [
          "array of strings describing topics"
        ],
        "regulatory_non_compliance": [
          {
            "type": "none | violation | deficiency | concern",
            "description": "string",
            "regulation": "string (optional)",
            "severity": "string (optional)"
          }
        ],
        "corrective_actions": [
          "array of strings describing actions required"
        ],
        "recommendations": [
          "array of strings with recommendations"
        ]
      }
    }
  ]
}
```

### Report Types
- **Quarterly Visits**: Regular compliance checks
- **Relicense Visits**: Comprehensive reviews for license renewal
- **Complaint Investigations**: Follow-up on specific allegations
- **Follow-up Visits**: Verification of corrective actions

---

## Scripts & Utilities

The `/Scripts` directory contains optional tools for data processing and collection. These are provided as resources for other advocates and researchers.

**Contents**:
- Raw JSON data from state agencies
- Data cleaning/transformation scripts
- State-specific inspection checklists
- Manual data entry tools (`facility_input.html`)

**Note**: These scripts are not required for website operation. They exist to share methodology and tools with the advocacy community.

---

## Troubleshooting

### Script Not Loading on Page
1. Verify page slug matches the condition in `functions.php`
2. Check browser console for 404 errors
3. Verify file path in `wp-content/themes/child/js/`
4. Clear WordPress cache
5. Check error logs in cPanel

### JSON Data Not Displaying
1. Verify JSON file syntax with a validator
2. Check file path in localized script (`myThemeData.jsonFileUrls`)
3. Open browser DevTools Network tab to see if file is loading
4. Check for JavaScript errors in console

### Anonymous Portal Issues
1. Verify upload directory exists and is writable: `/wp-content/uploads/anonymous-submissions/`
2. Check `.htaccess` file is present in upload directory
3. Verify PHP `openssl` extension is enabled
4. Check database table was created: `wp_anonymous_submissions`
5. Verify WordPress nonce is valid

### Database Connection Errors
1. Verify credentials in `api/config.php`
2. Check database exists in cPanel MySQL Databases
3. Verify user has privileges on the database
4. Test connection with a simple PHP script

### Deployment Issues
1. Check Git deployment logs in cPanel
2. Verify `.cpanel.yml` syntax
3. Check file permissions after deployment
4. Ensure deployment path is correct
5. Manually trigger deployment in cPanel Git interface

---

## Roadmap

### Planned Features
- **State Expansion**: Oregon, Arkansas, and additional states
- **Automated Data Collection**: Web scraping pipelines for state agencies
- **Advanced Search**: Full-text search across all reports
- **Data Visualization**: Charts and graphs for violation trends
- **Export Functionality**: Download data in CSV/Excel format
- **API for Researchers**: Public API for academic research
- **Mobile App**: Native mobile application for parents and advocates

### Improvements
- Automated testing for data integrity
- Enhanced admin dashboard with analytics
- Multi-language support
- Accessibility improvements (WCAG 2.1 AA compliance)
- Performance optimization for large datasets

---

## Contributing

We welcome contributions from developers, advocates, and researchers.

### How to Contribute

1. **Report Issues**: Email dani@kidsoverprofits.org with bugs or suggestions
2. **Submit Data**: Use the public submission form on the website
3. **Code Contributions**: Contact us about developer access
4. **Data Collection**: Help gather inspection reports from new states

### Data Sources
We collect data from:
- State licensing agencies
- Department of Children and Families reports
- Freedom of Information Act (FOIA) requests
- Public records requests
- Whistleblower submissions

### Coding Standards
- Follow WordPress coding standards
- Comment complex logic
- Use meaningful variable names
- Sanitize all user inputs
- Prepare SQL statements with PDO
- Test on staging before production

### File Versioning Convention

**IMPORTANT**: When creating updated versions of files, use incremental version numbers, NOT descriptive suffixes.

**Correct naming**:
- `facility-form.v2.js`
- `facility-form.v3.js`
- `facility-form.v4.js`
- `app-logic.v2.js`

**Incorrect naming** (DO NOT USE):
- `facility-form.FIXED.js`
- `facility-form.new.js`
- `facility-form.updated.js`
- `facility-form.backup.js`

**Version numbering rules**:
1. Always use `.vN` format where N is a sequential number
2. Start with `.v2` for the first updated version (original file has no version suffix)
3. Increment by 1 for each subsequent version
4. Keep version numbers separate from descriptive names
5. Document changes in commit messages or changelog files, not in filenames

---

## Support

**Email**: dani@kidsoverprofits.org

**Website**: [kidsoverprofits.org]

**Mission**: Transparency, justice, and dignity for kids affected by institutional abuse.

---

## Acknowledgments

This project exists to amplify the voices of survivors and protect children in residential care. Thank you to all the advocates, whistleblowers, and survivors who have contributed to this effort.

**For the kids. Always for the kids.**

---

*Last Updated: October 2025*
