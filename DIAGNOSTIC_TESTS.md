# Form Loading Diagnostic Guide

## Quick Diagnostics

Run these commands in the browser console (F12) to identify loading issues:

### 1. **Full Diagnostic Suite**
```javascript
window.runDiagnostics()
```
This runs 8 comprehensive tests:
1. **API Endpoints** - Verifies all endpoint URLs are correctly resolved
2. **Projects Loaded** - Checks if projects were fetched from cloud
3. **Form Data Structure** - Validates core formData initialization
4. **Consultant Data** - Inspects referrerConsultants array and structure
5. **Form Field Mapping** - Confirms all form inputs exist in DOM
6. **Autocomplete Initialization** - Verifies autocomplete fields are ready
7. **Category Tabs** - Checks UI tabs for active state
8. **Current State** - Shows current project/facility/consultant indices

**Expected output:** ✅ ALL TESTS PASSED

### 2. **List All Projects**
```javascript
window.listAllProjects()
```
Shows all loaded projects grouped by category (companies, referrers, etc.)

### 3. **Test Specific Consultant Loading**
```javascript
window.testConsultantLoad('ProjectName', 0)
```
Replaces `ProjectName` with an actual project name. Tests:
- Project exists
- Consultant data present
- Form fields populated
- UI updated

**Example:**
```javascript
window.testConsultantLoad('Acme Corp Referrer', 0)
```

---

## Common Issues & Fixes

### ❌ Issue: "NO PROJECTS LOADED"
**Symptoms:** `Total projects: 0` in diagnostics

**Tests:**
- Check API endpoint is correct:
  ```javascript
  console.log(API_ENDPOINTS.LOAD_PROJECTS)
  ```
- Try manually fetching:
  ```javascript
  fetch(API_ENDPOINTS.LOAD_PROJECTS).then(r => r.json()).then(d => console.log(d))
  ```

**Fixes:**
1. Verify `get-master-data.php` returns valid JSON
2. Check database connection in `api/config.php`
3. Review network tab in DevTools for 404/500 errors

---

### ❌ Issue: "formData is NULL"
**Symptoms:** Form shows no data, all fields blank

**Cause:** `createNewProjectData()` not called or failed

**Fix:**
```javascript
// Reinitialize form data
window.formData = createNewProjectData()
window.updateAllUI()
```

---

### ❌ Issue: "Consultants Missing Keys"
**Symptoms:** ⚠️ "Consultant 0 missing keys: firstName, lastName, etc."

**Cause:** Data from cloud not using full `createDefaultReferrerIndividual()` structure

**Fix:** The code now merges incoming data with defaults automatically in `ensureReferrerDataStructures()`. Run diagnostics again after refresh.

---

### ❌ Issue: "Field Not Found in DOM"
**Symptoms:** `❌ Field not found in DOM: #consultant-firstname`

**Cause:** Form HTML doesn't have all required input fields

**Fix:** Check data.html or admin-data.html has these inputs:
```html
<input id="consultant-firstname" class="form-control">
<input id="consultant-lastname" class="form-control">
<input id="consultant-email" class="form-control">
<input id="consultant-city" class="form-control">
<input id="consultant-state" class="form-control">
<!-- etc -->
```

---

### ❌ Issue: "Autocomplete Not Initialized"
**Symptoms:** Autocomplete fields don't show suggestions

**Check:**
```javascript
// List all autocomplete fields
document.querySelectorAll('[data-autocomplete-category]').forEach(f => {
    console.log(f.id, 'initialized:', f.dataset.autocompleteInit)
})
```

**Fix:** Re-initialize autocomplete:
```javascript
initializeAutocompleteFields()
```

---

## Data Structure Validation

### Expected Consultant Object
```javascript
{
    firstName: "",
    lastName: "",
    fullName: "",
    role: "",
    status: "",
    education: "",
    credentials: "",
    city: "",
    state: "",
    email: "",
    phone: "",
    website: "",
    affiliations: [],
    facilitiesReferred: [],
    knownReferrals: [],
    pastTTIJobs: [],
    schoolDistricts: [],
    lawsuits: "",
    notes: "",
    fieldNotes: {}
}
```

**Check current structure:**
```javascript
console.log(window.formData.referrerConsultants[0])
```

---

## Debug Logging

Enable verbose debug logging:
```javascript
// Already enabled if DEBUG_LOGGING_ENABLED = true
// Or set manually:
window.KOP_FACILITY_FORM_DEBUG = true
localStorage.setItem('kop-form-debug', 'true')
```

Then reload and check console for detailed logs.

---

## Testing Workflow

1. **Load page, open console**
2. **Run:** `window.runDiagnostics()`
3. **Check for any ❌ issues**
4. **If consultant not loading:**
   ```javascript
   window.listAllProjects()  // Find a referrer project
   window.testConsultantLoad('ProjectName', 0)
   ```
5. **Check form fields updated** - Look for names/emails in form inputs

---

## Quick Reference

| Command | Purpose |
|---------|---------|
| `window.runDiagnostics()` | Full 8-test diagnostic |
| `window.listAllProjects()` | List all loaded projects |
| `window.testConsultantLoad('Name', 0)` | Load specific consultant |
| `window.formData` | Current form data object |
| `window.projects` | All loaded projects |
| `API_ENDPOINTS` | Current API endpoint URLs |
| `window.updateAllUI()` | Force UI refresh |
| `loadConsultantData()` | Reload current consultant |
| `updateConsultantsUI()` | Update consultant UI |

---

## Contact

If diagnostics still show issues, provide:
1. Output of `window.runDiagnostics()`
2. Project name from `window.listAllProjects()`
3. Network tab screenshot showing API calls
