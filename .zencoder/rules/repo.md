---
description: Repository Information Overview
alwaysApply: true
---

# Kids Over Profits Information

## Summary
Kids Over Profits is a 501(c)(3) nonprofit organization dedicated to tracking inspection reports, violations, and facility information for youth residential treatment facilities across the United States. The project consists of a WordPress-based website with custom PHP backend and JavaScript frontend components for displaying and managing facility data.

## Structure
- **Website/**: Main WordPress child theme containing all website code
  - **api/**: PHP API endpoints for data operations
  - **css/**: Stylesheet files for the website
  - **html/**: HTML templates for various pages
  - **inc/**: PHP includes and helper functions
  - **js/**: JavaScript files for frontend functionality
  - **php/**: Additional PHP components
  - **template-parts/**: WordPress template components
- **Scripts/**: Python scripts for data scraping and processing
  - **CA_json/**: California facility data
  - **AZ_Reports/**: Arizona facility reports
  - **checklists/**: Utah facility inspection checklists

## Language & Runtime
**Language**: PHP 7.4+, JavaScript, Python 3.x
**CMS**: WordPress (Kadence parent theme with custom child theme)
**Database**: MySQL (managed via phpMyAdmin in cPanel)
**Build System**: None (direct deployment)
**Package Manager**: None (direct file management)

## Dependencies
**PHP Dependencies**:
- WordPress core
- Kadence theme (parent)

**JavaScript Dependencies**:
- No external libraries required (vanilla JavaScript)

**Python Dependencies**:
- requests
- BeautifulSoup4
- re (regex)
- json
- time
- typing

## Build & Installation
```bash
# Deploy via cPanel Git deployment
git push origin main

# Manual deployment
rsync -aP --exclude '.git' --exclude '.cpanel.yml' ./Website/ /home/kidsover/public_html/wp-content/themes/child/
```

## Deployment
**Deployment Method**: cPanel Git deployment
**Configuration File**: .cpanel.yml
**Deployment Path**: /home/kidsover/public_html/wp-content/themes/child/
**Process**:
1. Push changes to Git repository
2. cPanel automatically syncs files to the deployment path
3. WordPress loads the updated child theme

## Database Configuration
**Host**: localhost
**Database**: kidsover_suggestions
**User**: kidsover_dani
**Charset**: utf8mb4
**Connection**: PDO with prepared statements

## Main Files & Resources
**Entry Points**:
- WordPress page templates (page-*.php)
- API endpoints in Website/api/
- Anonymous document portal (shortcode-based)

**Configuration Files**:
- Website/api/config.php: Database connection settings
- .cpanel.yml: Deployment configuration

## State Report Pages
**States Covered**:
- California (CA)
- Utah (UT)
- Arizona (AZ)
- Texas (TX)
- Montana (MT)
- Connecticut (CT)
- Washington (WA)

**Implementation**:
- Each state has a dedicated page template (page-{state}-reports.php)
- JavaScript handlers load and display state-specific data
- Data stored in JSON format in js/data/ directory

## Anonymous Document Portal
**Features**:
- Secure, encrypted document submission
- End-to-end encryption
- Automatic metadata stripping
- 90-day automatic deletion
- Admin review interface

**Implementation**:
- PHP class (AnonymousDocPortal) in functions.php
- Custom database table for submissions
- WordPress shortcode for embedding

## Data Management
**Data Sources**:
- State licensing agencies
- Department of Children and Families reports
- FOIA requests
- Public records requests
- Whistleblower submissions

**Data Processing**:
- Python scripts for scraping and processing data
- JSON data storage for frontend display
- MySQL database for user submissions and admin data

## Testing Framework
**Primary Testing Framework**: Custom PHP/JavaScript Unit Testing Suite
**Additional Tools**: Integration tests, End-to-End tests, Visual tests, Report diagnostics
**Test Runner**: `run-tests.php` (CLI) and WordPress page template (browser-based)

**Test Coverage**:
- PHP Unit Tests (56 tests, 92.9% pass rate)
- JavaScript Unit Tests (40+ browser-based tests)
- Python Unit Tests (30+ scraper tests)
- Integration Tests (28 tests, 100% pass rate)
- End-to-End Tests (Live website functionality validation)

**Test Execution**:
- CLI: `php run-tests.php [php|js|python|integration|e2e|all]`
- Browser: Visit `/run-tests` page or add `?runtests=js` to any URL
- Individual: Direct execution of test files

**E2E Testing**: 
- Tests live website functionality including JavaScript file accessibility
- Detects deployment gaps that unit tests miss
- Validates state report pages load correctly
- Identifies "Loading..." stuck states caused by missing JS files

## Security Features
- PDO prepared statements for database queries
- Input sanitization
- WordPress nonce verification
- AES-256-CBC encryption for sensitive data
- .htaccess protection for upload directories
- File type and size validation