# TTI Programs Database - Split Component Structure

## 📁 Directory Structure

This folder contains the **TTI Programs Database** split into manageable components for easier maintenance and version control.

### Component Files

| File | Size | Purpose |
|------|------|---------|
| **programs-array.json** | 284 KB | Complete array of 1,122 programs with normalized data |
| **search-index.json** | 607 KB | Full text search index with 3,790 entries (dual-entry system) |
| **programs-*.json** | Various | Individual state files (MT, UT, TX, WA, VA, VT, SD, TN, WV, WI) |
| **metadata.json** | <1 KB | Database metadata and generation timestamps |
| **index.json** | <1 KB | Component index and manifest |
| **README.md** | This file | Documentation |

### Related Files

- **`../tti-program-links.json`** - Original monolithic file (15.6 MB) - PRODUCTION FILE
- **`../split-into-components.ps1`** - Script to regenerate split files
- **`../rebuild-from-components.ps1`** - Script to reassemble the full database

---

## 🚀 Quick Start

### For Developers

**Editing Individual Programs:**
```powershell
# 1. Edit the desired state file (e.g., programs-UT.json)
# 2. Make changes to program records
# 3. Run rebuild script to reassemble the full database
& '..\..\split-into-components.ps1'
```

**Finding a Program:**
```powershell
$programs = Get-Content programs-array.json | ConvertFrom-Json
$programs.programs | Where-Object { $_.name -match "Provo" }
```

**Searching by State:**
```powershell
$utah = Get-Content programs-UT.json | ConvertFrom-Json
$utah.programs | ForEach-Object { $_.name }
```

### For Deployment

**On Production Server:**
1. Copy `tti-program-links.json` to the production theme directory
2. No rebuild needed — the original file is the source of truth

---

## 📊 Database Statistics

**Master Metadata:**
- Total Programs: **1,187** (including 296 from recent 11-state batch)
- Total Search Index Entries: **3,790**
- States Represented: **20+**

**State Breakdown:**
| State | Programs | File Size |
|-------|----------|-----------|
| Wisconsin (WI) | 962 | 243 KB |
| Utah (UT) | 65 | 17 KB |
| Washington (WA) | 35 | 9 KB |
| Virginia (VA) | 28 | 7 KB |
| Montana (MT) | 20 | 5 KB |
| Tennessee (TN) | 4 | 1 KB |
| South Dakota (SD) | 4 | 1 KB |
| Texas (TX) | 2 | <1 KB |
| West Virginia (WV) | 1 | <1 KB |
| Vermont (VT) | 1 | <1 KB |

---

## 🔄 Workflow

### Adding a New Program

1. **Identify state** of the new program
2. **Edit the appropriate state file** (e.g., `programs-WA.json` for Washington)
3. **Add to programs array:**
   ```json
   {
     "name": "Program Name",
     "url": "/r/troubledteens/wiki/index/slug/",
     "normalizedName": "program name"
   }
   ```
4. **Add search index entries** to `search-index.json`:
   ```json
   {
     "program name": {
       "name": "Program Name",
       "url": "/r/troubledteens/wiki/index/slug/",
       "matchType": "exact"
     },
     "program": {
       "name": "Program Name",
       "url": "/r/troubledteens/wiki/index/slug/",
       "matchType": "variant"
     }
   }
   ```
5. **Rebuild the master file:**
   ```powershell
   & '..\..\rebuild-from-components.ps1'
   ```

### Batch Updates

For large updates (adding multiple states):

1. Extract programs and search data (provided separately)
2. Create new state files in this directory
3. Update the search-index.json
4. Run the rebuild script
5. Verify tti-program-links.json is updated

---

## 🔍 Search Index Design

The search index uses a **dual-entry system** to improve discoverability:

- **Exact Match**: Full program name (e.g., "Provo Canyon School")
- **Variant Match**: Shortened or alternative names (e.g., "provo canyon", "Provo School")

Each program generates 2-3 index entries for flexible searching.

**Example Entry:**
```json
{
  "provo canyon school": {
    "name": "Provo Canyon School",
    "url": "/r/troubledteens/wiki/index/provocanyonschool/",
    "matchType": "exact"
  },
  "provo canyon": {
    "name": "Provo Canyon School",
    "url": "/r/troubledteens/wiki/index/provocanyonschool/",
    "matchType": "variant"
  }
}
```

---

## 📝 File Formats

### programs-array.json

```json
{
  "programs": [
    {
      "name": "Program Name",
      "url": "/r/troubledteens/wiki/index/slug/",
      "normalizedName": "program name"
    },
    ...
  ],
  "count": 1122,
  "generated": "2025-12-10T20:03:09.284Z"
}
```

### search-index.json

```json
{
  "searchIndex": {
    "program name": {
      "name": "Program Name",
      "url": "/r/troubledteens/wiki/index/slug/",
      "matchType": "exact|variant"
    },
    ...
  },
  "count": 3790,
  "generated": "2025-12-10T20:03:09.284Z"
}
```

### metadata.json

```json
{
  "generated": "2025-12-10T20:03:09.284Z",
  "source": "Reddit r/troubledteens wiki (expanded database)",
  "programCount": 1187,
  "searchIndexSize": 3790,
  "components": { ... }
}
```

---

## 🛠️ Utilities

### split-into-components.ps1
Extracts the master `tti-program-links.json` into individual component files.
```powershell
& '..\split-into-components.ps1'
```

### rebuild-from-components.ps1
Reassembles all component files back into `tti-program-links.json`.
```powershell
& '..\rebuild-from-components.ps1'
```

---

## ✅ Data Integrity

Each component file is **valid JSON** and can be used independently:
- Programs array: Can be parsed and used for display
- Search index: Can be used for search operations
- State files: Can be edited and re-split

**Before deployment:** Always run the rebuild script to ensure the master file is current.

---

## 🚨 Important Notes

1. **Do not directly edit** `tti-program-links.json` — edit components instead
2. **Always rebuild** after component changes before deploying
3. **Keep state files organized** by geographic region
4. **Validate JSON** after manual edits (use JSON linters)
5. **Test search functionality** after updating search-index.json

---

## 📞 Support

For questions about the database structure:
- Check AGENTS.md for architecture overview
- Review the main README in the repository
- See environment-summary.md for deployment details

---

**Last Updated:** December 10, 2025  
**Version:** 2.0  
**Status:** Production Ready ✓
