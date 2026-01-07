# Admin Submissions

The **Admin Submissions** module is a dashboard for reviewing, approving, and managing user-submitted content (primarily Wiki entries, News articles, and Data Form suggestions). It acts as the gatekeeper for the Kids Over Profits database.

## Overview

This tool allows administrators to see a high-level view of all submissions, filter them by status, and dive into individual records to review data. It provides diff tools to compare uploaded markdown against generated versions and allows for direct database actions.

## Architecture

- **Entry Point:** `page-admin-submissions.php` - The main dashboard template.
- **Frontend Logic:** `js/admin-submissions.js` - Handles UI interactions, modal rendering, and API communication.
- **Backend API:** `api/manage-submissions.php` - The workhorse endpoint. It handles:
    - **Listing:** Fetching paginated lists of submissions with filters.
    - **Stats:** Aggregating counts (Pending, Approved, etc.).
    - **Actions:** Approving, Rejecting, Publishing, and Deleting records.

## Key Features

### 1. Dashboard Statistics
Real-time counters for:
- Pending Submissions
- Approved Entries
- Published Pages
- Rejected/Spam Items

### 2. Submission Management
- **List View:** Sortable list of submissions with key metadata (Program Name, Date, Submitter).
- **Filtering:** Filter by status (Submitted, Approved, Published, Rejected) and search by keyword.
- **Types:** Support for Wiki Entries, News Articles, and Data Form Suggestions.

### 3. Review Modal
Clicking "View Details" opens a comprehensive modal featuring:
- **Metadata:** Submitter info, dates, status.
- **Markdown Diff:** A side-by-side comparison of the "Original/Uploaded" markdown vs. the "System Generated" markdown, highlighting changes.
- **Form Data:** Collapsible view of the raw JSON data associated with the submission.
- **Reviewer Notes:** Field for admins to add internal notes or feedback.

### 4. Workflow Actions
- **Approve:** Marks the submission as approved (ready for publication).
- **Reject:** Marks the submission as rejected.
- **Mark as Published:** Indicates the content is live on the site/wiki.
- **Delete:** Permanently removes the submission and its attachments.

## Usage

1.  **Access:** Log in as an administrator and navigate to the Admin Submissions page.
2.  **Filter:** Select "Pending Review" to see new items.
3.  **Review:** Click "View Details" on a submission.
4.  **Analyze:** Check the Markdown Diff and JSON data for accuracy.
5.  **Decide:** Add notes if necessary and click "Approve", "Reject", or "Delete".

## API Reference

**Endpoint:** `/api/manage-submissions.php`

- **GET `?action=list&type=wiki`**: List wiki submissions.
- **GET `?action=list&type=news`**: List news submissions.
- **GET `?action=list&type=data`**: List data form suggestions.
- **GET `?action=get&id=123`**: Get single submission details.
- **POST `{ "action": "approve", "ids": [123] }`**: Approve a submission.
