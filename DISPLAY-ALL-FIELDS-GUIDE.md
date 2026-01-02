# Display All Database Fields - User Guide

## Overview

The TTI Program Index page now supports two viewing modes:


2. **Show All Fields View** - Displays **every available field** from the database dynamically

## How to Use

### Access the Views

#### Standard View (Default)
```
https://kidsoverprofits.org/tti-program-index/
```
or
```
https://kidsoverprofits.org/tti-program-index/?view=standard
```

#### Show All Fields View
```
https://kidsoverprofits.org/tti-program-index/?view=all
```

### Toggle Between Views

Click the buttons at the top of the page:
- **Standard View** - Shows curated fields
- **Show All Fields** - Displays all database fields

## What Fields Are Displayed

### Standard View Shows:

**Parent Company Fields:**
- Status
- Founded
- Parent Companies
- Past Names
- Founders
- Key Executives
- CEO
- Notes
- Websites

**Facility Fields:**
- Type
- Capacity
- Age Range
- Gender
- Current Parent Company
- Past Names
- Administrator
- Current/Past Accreditations
- Memberships
- Licensing
- Full Address
- Archived Website
- Resources Available

### Show All Fields View Displays:

**Everything in the JSON data**, including:
- All standard fields (above)
- Any custom fields added to the database
- Nested objects and arrays
- Field notes and metadata
- Operating periods
- Contact information
- Regulatory information
- Historical data
- And any other data stored in the `json_data` column

## How It Works

### Unified View ([tti-program-index.js](js/tti-program-index.js))
- Dynamically renders ALL fields in the JSON data
- Automatically formats field names (camelCase → Title Case)
- Handles nested objects and arrays
- Converts URLs to clickable links
- Shows complex data structures
- Uses a responsive, robust layout logic

## Technical Details

### Files Modified/Created:

1. **[page-tti-program-index.php](page-tti-program-index.php)**
   - Template for the page

2. **[js/tti-program-index.js](js/tti-program-index.js)**
   - Unified display function: `displayFacilities()`
   - Robust field rendering and empty value checking
   - Clean layout logic using CSS Grid

3. **[css/tti-program-index.css](css/tti-program-index.css)**
   - Updated grid layouts for better space usage

### Data Source

The view pulls data from:
- API: `api/get-master-data.php`
- Fallback: `js/data/facilities_master.json`
- Database table: `facilities_master` (column: `json_data`)

## Advanced Features

### Smart Field Rendering

The unified view automatically:

1. **Formats field names**: `facilityDetails.ageRange.min` → "Facility Details Age Range Min"
2. **Detects URLs**: Automatically converts to clickable links
3. **Handles arrays**: Displays as comma-separated lists or nested views
4. **Shows nested objects**: Displays with indentation and borders
5. **Skips empty values**: Only shows fields with data
6. **Boolean display**: Shows "Yes/No" instead of "true/false"

### Search & Filter

Both views support:
- Text search (searches through ALL text, including all fields)
- Status filtering (Open/Closed/Transferred)
- Alphabetical sorting
- Alphabet quick-filter

## Use Cases

### When to Use Standard View:
- Public-facing website
- General browsing
- Quick facility lookups
- Clean, professional presentation

### When to Use Show All Fields View:
- Data verification
- Finding specific metadata
- Reviewing complete facility records
- Identifying missing data
- Quality assurance
- Administrative work
- Understanding full data structure

## Database Schema Reference

For complete database column information, see:
- [DATABASE-COLUMNS.md](DATABASE-COLUMNS.md) - All database tables and columns
- [api/get-database-schema.php](api/get-database-schema.php) - Live schema inspector

## Example Data Structure

The JSON data in `facilities_master.json_data` typically contains:

```json
{
  "name": "Example TTI Company",
  "category": "companies",
  "data": {
    "operator": {
      "name": "Parent Company Name",
      "status": "Active",
      "founded": "1995",
      "headquarters": "City, State",
      "websites": ["https://example.com"],
      "keyStaff": {
        "founders": ["Founder Name"],
        "ceo": "CEO Name",
        "keyExecutives": ["Executive 1", "Executive 2"]
      }
    },
    "facilities": [
      {
        "identification": {
          "name": "Facility Name",
          "currentName": "Current Facility Name",
          "pastNames": ["Former Name"]
        },
        "location": "City, State",
        "address": "Full Address",
        "facilityDetails": {
          "type": "RTC",
          "capacity": 50,
          "ageRange": {"min": 13, "max": 18},
          "gender": "Coed"
        },
        "operatingPeriod": {
          "status": "Open",
          "startYear": 2000,
          "endYear": null
        },
        "resources": {
          "hasInspections": true,
          "hasViolations": true,
          "hasNews": true
        }
      }
    ]
  }
}
```

**Show All Fields View** displays every single field in this structure, no matter how deeply nested or custom.

## Future Enhancements

Potential improvements:
- Export to CSV/Excel
- Field-level search (search specific columns)
- Custom field visibility toggles
- Save preferred view in user settings
- Print-friendly all-fields report
- Data comparison between records
- Data comparison between records