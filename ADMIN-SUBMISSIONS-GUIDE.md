# Admin Wiki Submissions Review Guide

## Overview

The Admin Wiki Submissions Review interface allows you to review, approve, and manage wiki submissions from users.

## Accessing the Admin Interface

1. Create a new WordPress page
2. Set the template to **"Admin - Wiki Submissions Review"**
3. Publish the page
4. Visit the page URL (e.g., `/admin-submissions/`)

## Dashboard Features

### Stats Cards
At the top of the page, you'll see 4 stat cards showing:
- **Pending**: Submissions waiting for review (status: submitted)
- **Approved**: Submissions that have been approved
- **Published**: Submissions that have been manually posted to Reddit
- **Rejected**: Submissions that were rejected

### Filters
- **Status Filter**: View submissions by status (All, Pending Review, Approved, Published, Rejected)
- **Search**: Search by program name or location
- **Refresh Button**: Reload the latest submissions and stats

## Submission Cards

Each submission is displayed as a card showing:
- Program name
- Status badge (color-coded)
- Location (city/state)
- Years active
- Program type
- Submission date
- **View Details** button

Click any submission card or the "View Details" button to open the full review modal.

## Review Modal

When you open a submission, you'll see:

### Submission Information
- Current status
- Submission date and time
- Submitter email (if provided)
- Program location, type, and years active

### Submitter Notes
Any notes or context provided by the person who submitted the entry.

### Generated Wiki Markdown
- Full Reddit-formatted markdown ready to copy and paste
- **📋 Copy Markdown** button for easy clipboard copying

### Form Data (JSON)
Expandable section showing the complete form data as JSON (for reference or debugging).

### Review Actions Section

#### Reviewer Notes
Add your own notes about this submission (optional but recommended):
- Why you approved/rejected it
- Any changes needed
- Follow-up items

#### Your Email/Name
Enter your admin email or name. This is saved locally for convenience.

#### Previous Review Info
If the submission was already reviewed, you'll see:
- Who reviewed it
- When it was reviewed
- Their notes

## Action Buttons

### ✓ Approve
Mark the submission as approved. Use this when:
- The content looks accurate and well-formatted
- You've verified the information
- It's ready to be posted to Reddit (but you haven't posted it yet)

### ✗ Reject
Mark the submission as rejected. Use this when:
- The submission contains inaccurate information
- It's spam or inappropriate
- It needs significant revision

### 📤 Mark as Published
Mark the submission as published. Use this when:
- You've approved it AND
- You've manually copied and pasted it to the Reddit wiki
- You want to track that it's been posted

### 🗑️ Delete
Permanently delete the submission from the database. This cannot be undone.
- Confirmation dialog will appear
- Use sparingly (usually better to reject than delete)

## Typical Workflow

1. **Review Pending Submissions**
   - Set filter to "Pending Review"
   - Click on a submission to view details

2. **Read Submission**
   - Review the generated markdown
   - Check submitter notes for context
   - Verify information accuracy

3. **Take Action**
   - Add reviewer notes explaining your decision
   - Enter your email/name
   - Click Approve or Reject

4. **Copy to Reddit** (for approved submissions)
   - Click "📋 Copy Markdown"
   - Navigate to Reddit wiki editor
   - Paste the markdown
   - Save the wiki page on Reddit

5. **Mark as Published**
   - Return to the submission in the admin interface
   - Click "📤 Mark as Published"
   - This helps track what's been posted

## Status Workflow

```
User Submits → submitted (Pending)
                   ↓
Admin Reviews → approved or rejected
                   ↓
Admin Posts to Reddit → published (tracking only)
```

## Tips

- **Use Reviewer Notes**: Always add notes explaining your decision. This creates an audit trail.
- **Search Feature**: Use the search box to find specific programs quickly.
- **Refresh Often**: Click refresh to see new submissions.
- **Save Your Email**: Your email/name is saved in browser localStorage for convenience.
- **Copy Button**: The copy markdown button uses the clipboard API - much faster than manual selection.

## Technical Details

### Files
- Page template: `page-admin-submissions.php`
- JavaScript: `js/admin-submissions.js`
- CSS: `css/admin-submissions.css`

### APIs Used
- `GET /api/save-wiki-submission.php` - Fetch submissions
- `POST /api/manage-submissions.php` - Perform actions (approve, reject, publish, delete)

### Database Table
All submissions are stored in the `wiki_submissions` table with fields for tracking status, reviewer info, and timestamps.

## Security Notes

- Uncomment the permission check in `page-admin-submissions.php` to restrict access:
  ```php
  if (!current_user_can('manage_options')) {
      wp_die('Access denied');
  }
  ```
- Only WordPress administrators should access this page
- Consider adding nonce verification for extra security

## Troubleshooting

**Submissions not loading?**
- Check that the database tables are initialized
- Verify API endpoints are accessible
- Check browser console for errors

**Can't copy markdown?**
- Ensure your browser supports clipboard API (all modern browsers do)
- Check that the page is served over HTTPS (clipboard API requires secure context)

**Actions failing?**
- Verify your email/name is entered
- Check API permissions
- Look for PHP errors in server logs

## Support

For issues or questions, refer to:
- Main documentation: `AGENTS.md`
- API documentation: `api/` folder
- Database schema: `api/init-submissions-db.php`
