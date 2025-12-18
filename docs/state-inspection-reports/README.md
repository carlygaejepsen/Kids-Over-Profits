# State-Level Inspection Reports

The **State-Level Inspection Reports** module provides a specialized interface for browsing detailed regulatory inspection data for specific states (e.g., Texas, Utah, Montana). Unlike the general [TTI Program Index](../tti-program-index/README.md), this tool focuses on granular compliance data, including specific citations, narratives, and risk levels.

## Overview

This feature visualizes large datasets of government inspection records. It allows users to search for facilities within a state and view a timeline of their regulatory history, including specific violations and corrective actions.

## Architecture

- **Frontend Logic:** `js/inspections/*.js` (e.g., `tx_reports.js`, `ut_reports.js`). Each state typically has its own script to handle unique data schema variations.
- **Data Storage:** `js/data/*.json` (e.g., `tx_reports.json`). Large JSON files serve as the static database for these reports.
- **Styling:** `css/facility-reports.css` provides the visual structure for the report containers, accordion dropdowns, and risk level badges.

## Key Features

### 1. State-Specific Data Loading
Each report page loads a dedicated JSON dataset tailored to that state's reporting format. The scripts normalize this data into a standard facility object with fields like:
- Provider Number
- Capacity & Demographics
- Citation History

### 2. Search & Filtering
- **Alphabetical Index:** Quick navigation bar (A-Z) to browse facilities by name.
- **Live Search:** Filters facilities by name, city, county, or type.
- **Sorting:** Options to sort by Name, Violation Count, or Recent Activity.

### 3. Detailed Inspection Histories
The core feature is the **Inspection Timeline**. Users can expand a facility to see:
- **Citations:** Dated list of violations.
- **Risk Levels:** Color-coded badges (High, Medium, Low) indicating the severity of the violation.
- **Narratives:** Detailed text describing the deficiency and the facility's corrective action plan.

### 4. Risk Visualization
Citations are visually distinguished:
- **Red/Orange Borders:** Indicate violations or high-risk findings.
- **Green/Blue Borders:** Indicate standard inspections or corrected issues.

## Usage

1.  **Navigate** to a specific state report page (e.g., `/texas-reports`).
2.  **Browse** using the A-Z filter or **Search** for a specific facility name.
3.  **Expand** a facility card to view its details.
4.  **Click** on individual inspection rows to read the full deficiency narrative.

## Adding a New State

1.  **Acquire Data:** Obtain inspection data (often via FOIA or scraping state portals).
2.  **Convert to JSON:** Format the data into a JSON structure compatible with the viewer (array of facility objects).
3.  **Upload:** Save the JSON file to `js/data/`.
4.  **Create Script:** Copy an existing script (e.g., `js/inspections/tx_reports.js`), rename it (e.g., `ny_reports.js`), and adjust the `convertDataToFacilities` function to map the new JSON fields to the standard UI fields.
5.  **Create Page:** Create a WordPress page and ensure the new script and `facility-reports.css` are enqueued for it.
