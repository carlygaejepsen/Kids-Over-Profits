# State-Level Inspection Reports

The **State-Level Inspection Reports** module provides a specialized interface for browsing detailed regulatory inspection data for specific states (e.g., Texas, Utah, Montana). Unlike the general [TTI Program Index](../tti-program-index/README.md), this tool focuses on granular compliance data, including specific citations, narratives, and risk levels.

## Overview

This feature visualizes large datasets of government inspection records. It allows users to search for facilities within a state and view a timeline of their regulatory history, including specific violations and corrective actions.

**Supported states:** Arizona (AZ), California (CA), Connecticut (CT), Montana (MT), Texas (TX), Utah (UT), and Washington (WA).

## Architecture

- **Frontend Logic:** `js/inspections/*.js` (e.g., `tx_reports.js`, `ut_reports.js`, `az_reports.js`, `ct_reports.js`, `mt_reports.js`, `wa_reports.js`, `ca-reports.js`). Each state has its own script to handle unique data schema variations. Shared rendering helpers live in `js/inspections/facilities-display.js`.
- **Backend API:** A MySQL-backed inspections API (`api/inspections-read.php`, `api/inspections-write.php`) now serves report data for all states. Frontend scripts fetch live data from this API; legacy static JSON files in `js/data/` remain as fallback / historical snapshots.
- **Data Storage:** MySQL tables (primary) plus `js/data/*.json` (e.g., `tx_reports.json`, `ut_reports.json`, `mt_reports.json`, `ct_reports.json`, `wa_reports.json`) for static fallback.
- **Styling:** `css/facility-reports.css` provides the visual structure for the report containers, accordion dropdowns, and risk level badges. Styling has been unified across all state report pages for visual consistency.

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

### 5. Last Updated Timestamp
Each state report page displays a "last updated" timestamp sourced from the inspections API, so visitors can see how current the underlying dataset is.

## Usage

1.  **Navigate** to a specific state report page (e.g., `/texas-reports`).
2.  **Browse** using the A-Z filter or **Search** for a specific facility name.
3.  **Expand** a facility card to view its details.
4.  **Click** on individual inspection rows to read the full deficiency narrative.

## Adding a New State

1.  **Acquire Data:** Obtain inspection data (often via FOIA or scraping state portals). Scrapers live in the main Tools repo (e.g., `wa_doh_rtf_scraper`, `ct_scraper`, `az_scraper`).
2.  **Load into MySQL:** Insert records into the inspections database via `api/inspections-write.php` so they are served by the shared API. For static-only deployments, save a JSON file to `js/data/` as fallback.
3.  **Create Script:** Copy an existing script (e.g., `js/inspections/tx_reports.js`), rename it (e.g., `ny_reports.js`), and adjust the data-normalization logic to map the new fields to the standard UI fields. Prefer fetching from `inspections-read.php` over loading static JSON.
4.  **Create Page:** Create a WordPress page and ensure the new script and `facility-reports.css` are enqueued for it. Include the shared "last updated" timestamp block for consistency.
