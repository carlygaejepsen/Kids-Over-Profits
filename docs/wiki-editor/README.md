# Wiki Editor

The **Wiki Editor** is a powerful tool designed to help users create, format, and manage Reddit wiki entries for TTI programs. It provides a user-friendly interface that generates standardized Markdown code, ensuring consistency across the "Troubled Teen Industry" subreddit wiki.

## Overview

This module functions as a comprehensive form-to-markdown generator. It abstracts away the complexity of Reddit's markdown syntax, allowing researchers and survivors to focus on content.

## Architecture

- **Entry Point:** `page-wiki-editor.php` - The WordPress page template.
- **Frontend Logic:** `js/wiki-editor.js` - Handles form interactions, state management, and API calls.
- **Markdown Generation:** `js/wiki-generation.js` (implied dependency) - Converts form data into Markdown.
- **Markdown Parsing:** `js/wiki-parser.js` (implied dependency) - Converts Markdown back into form data (for importing/editing).
- **Backend API:** `api/save-wiki-submission.php` - Handles saving drafts/submissions to the database.

## Key Features

### 1. Form-Based Entry
Users can fill out structured fields for:
- **Program Details:** Name, location, years active, owner, tuition, etc.
- **Staff:** Current and former staff, with support for tracking previous roles.
- **Structure:** Level systems, education type, therapy types (e.g., CBT, Attack Therapy).
- **Rules & Punishments:** Specific rules and consequences (e.g., "Silence", "Work Projects").
- **Allegations:** Checkboxes for common allegations (abuse, neglect) and custom entries.
- **Lawsuits:** Structured entry for lawsuit history.
- **Media:** News articles, survivor testimonies, and related links.

### 2. Reddit Markdown Import/Export
- **Generate:** Converts the form state into a formatted Markdown string ready for Reddit.
- **Import:** Users can paste existing Reddit wiki markdown to populate the form fields automatically. This is crucial for updating existing entries.
- **Bulk Upload:** Supports uploading multiple `.md` files at once for batch processing.

### 3. Entry Browser
- Users can browse existing entries saved in the database.
- Supports loading entries directly from the local `markdown_output/` directory (if configured).

### 4. Database Integration
- **Save Drafts:** Users can save their work to the `wiki_submissions` database table.
- **Review Workflow:** Submissions enter a review queue (managed via the [Admin Submissions](../admin-submissions/README.md) module).

## Usage

1.  **Navigate** to the Wiki Editor page.
2.  **Input Data:** Fill in the known information about a program.
3.  **Add List Items:** Use the "Add" buttons to add multiple items for staff, lawsuits, news, etc.
4.  **Generate:** Click "Generate Wiki Code" to preview the Markdown.
5.  **Copy/Submit:** Copy the code for Reddit or click "Submit to Database" to save it to the system.

## Dependencies

- **`wiki-parser.js`**: Required for the import functionality.
- **`wiki-generation.js`**: Required for the generate functionality.
- **`auto-linker.js`**: Optional dependency for automatically linking mentioned programs.
