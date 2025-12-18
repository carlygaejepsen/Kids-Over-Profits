# TTI Program Index

The **TTI Program Index** is a public-facing, searchable directory of facilities and operators within the Troubled Teen Industry. It serves as a central database for parents, researchers, and survivors to find information about specific programs.

## Overview

The index displays a comprehensive list of facilities, grouped by their parent "Operator" or "Company". It provides key details like location, status (Open/Closed), operational history, and available resources (lawsuits, survivor stories, etc.).

## Architecture

- **Entry Point:** `page-tti-program-index.php` - The WordPress page template.
- **Frontend Logic:** `js/tti-program-index.js` - A "drop-in" JavaScript module that handles fetching, parsing, and rendering the data.
- **Data Source:** `api/get-master-data.php` - Returns the master list of facilities and operators in JSON format.
- **Styling:** `css/tti-program-index.css`.

## Key Features

### 1. Hierarchical Display
Data is organized by **Operator** (e.g., "Aspen Education Group") -> **Facilities** (e.g., "Turn-About Ranch", "Island View"). This highlights the corporate connections between seemingly independent programs.

### 2. Search & Filtering
- **Keyword Search:** Real-time filtering by facility name or operator name.
- **Status Filter:** Filter by "Open", "Closed", or "Transferred".
- **Sorting:** Sort alphabetically, by violation count, or by recent inspections.
- **Alphabet Filter:** Quick-jump buttons (A-Z) to filter operators.

### 3. Detailed Facility Cards
Each facility card displays:
- **Status Badge:** Visual indicator of operating status (Open/Closed).
- **Location & Years:** City/State and years of operation.
- **Expandable Details:** A "Learn more" toggle revealing:
    - Capacity, Gender, Age Range.
    - Accreditations & Licensing.
    - Staff (Administrators).
    - **Resources:** Indicators for available News, Lawsuits, Survivor Stories, etc.

### 4. Dynamic Data Loading
The module is designed to load data asynchronously from a JSON endpoint, allowing the directory to scale without requiring server-side page regeneration for every view.

## Usage

1.  **Access:** Navigate to the Program Index page.
2.  **Search:** Type a name in the search bar to find a specific program.
3.  **Browse:** Use the status dropdown or alphabet buttons to browse categories.
4.  **View Details:** Click on a facility card (or the "Learn more" text) to see full details.

## Configuration

The data source is configured via a global `facilitiesConfig` object in the PHP template:

```javascript
window.facilitiesConfig = {
    jsonDataUrl: '.../api/get-master-data.php',
    jsonFileUrls: [...]
};
```
