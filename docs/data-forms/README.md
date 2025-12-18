# Data Forms

The **Data Forms** module provides the interface for users (both public and administrative) to input detailed information about the Troubled Teen Industry ecosystem. It supports collecting data on Companies (Operators), Locations (Facilities), and Referrers.

## Overview

This module is a complex, multi-step form application designed to capture granular data. It features a "Project" system that allows users to save their work locally in the browser before submitting, enabling them to build comprehensive datasets over time without losing progress.

## Architecture

- **Entry Point:** `page-data.php` - Wrapper for the public form.
- **Template:** `templates/data-form-public.php` - Contains the HTML structure for the form.
- **Frontend Logic:**
    - `edcons-display.js` (likely shared logic for display).
    - `autocomplete.js` (implied) - Handles the "search-as-you-type" functionality for fields like "Owner", "Location", etc.
- **Backend:** Submissions are sent to `api/save-suggestion.php` (or similar, based on `submitSuggestion()` calls).

## Key Features

### 1. Multi-Category Support
The form is divided into three main contexts:
- **Companies / Operators:** For corporate entities and ownership groups.
- **Locations / Facilities:** For specific program sites (e.g., a campus in Utah).
- **Referrers:** For educational consultants and school districts who funnel children into these programs.

### 2. Project Management (Local Storage)
- **Save Drafts:** Users can "Save Draft Locally", preserving their current form state in the browser's `localStorage`.
- **Project Lists:** A sidebar/panel allows users to switch between different "Projects" (drafts) they are working on.
- **Export/Import:** Users can export their projects to JSON files and import them back, facilitating data sharing or backup.

### 3. Detailed Data Collection
The form captures extensive details, including:
- **Identification:** Names, aliases, past names.
- **Operations:** Opening/closing dates, capacity, census.
- **Staff:** Key personnel, past employment history.
- **Treatment:** Standard and custom treatment types (e.g., ABA, Wilderness).
- **Resources:** Availability of documents (lawsuits, inspections, brochures).
- **Philosophy:** Core beliefs/methods (e.g., 12-step, Synanon-based).

### 4. Advanced Tools
- **Data Organizer:** A search tool to find existing facilities by keyword, staff name, or operator.
- **Clone Facility:** Allows users to duplicate an existing facility record to speed up data entry for chains or similar programs.
- **JSON Editor:** "Advanced User Mode" allows direct pasting/editing of the raw JSON payload.

### 5. User Tutorial
- **Interactive Overlay:** A built-in tutorial guides new users through the interface, explaining the project system, categories, and submission process.
- **Toggleable:** Users can restart the tutorial at any time via the "?" floating button.

## Usage

1.  **Select Category:** Choose "Companies", "Locations", or "Referrers".
2.  **Start Project:** Click "New Project" or select an existing one.
3.  **Enter Data:** Fill out the various sections (Identification, Location, Staff, etc.).
4.  **Save:** Periodically click "Save Draft Locally".
5.  **Submit:** When finished, click "Submit for Review" to send the data to the master database.

## Integration

- **Autocomplete:** Fields like "Operator Name" and "City" fetch suggestions from the backend to ensure data consistency.
- **Master Data:** Submissions are reviewed via the Admin Dashboard and eventually merged into the master database used by the [TTI Program Index](../tti-program-index/README.md).
