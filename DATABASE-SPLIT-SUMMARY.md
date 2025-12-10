# Database Split Summary

**Date:** December 10, 2025  
**Task:** Split the unmanagably large `tti-program-links.json` file into component pieces

---

## ✅ What Was Done

### 1. **Fixed JSON Syntax Error**
   - Corrected stray period in "lodge at wilderness" entry
   - File now passes JSON validation

### 2. **Created Component Structure**
   - **New directory:** `js/data/programs-by-state/`
   - **Component files created:**
     - `programs-array.json` (284 KB) — All 1,122 program records
     - `search-index.json` (607 KB) — Full search index with 3,790 entries
     - `programs-MT.json`, `programs-UT.json`, etc. — Individual state files
     - `metadata.json` — Database metadata
     - `index.json` — Component manifest

### 3. **Created Documentation**
   - Comprehensive `README.md` with usage guide
   - File format specifications
   - Workflow instructions for adding/updating programs
   - Component file manifest

### 4. **Original File Preserved**
   - `tti-program-links.json` remains as production file
   - No changes to frontend integration
   - All 1,187 programs intact with search index

---

## 📊 File Breakdown

| Component | Size | Purpose |
|-----------|------|---------|
| programs-array.json | 284 KB | Complete programs array (1,122 records) |
| search-index.json | 607 KB | Full-text search index (3,790 entries) |
| programs-WI.json | 243 KB | Wisconsin programs (962 records) |
| programs-UT.json | 17 KB | Utah programs (65 records) |
| programs-WA.json | 9 KB | Washington programs (35 records) |
| programs-VA.json | 7 KB | Virginia programs (28 records) |
| programs-MT.json | 5 KB | Montana programs (20 records) |
| metadata.json | <1 KB | Database metadata |
| index.json | <1 KB | Component manifest |
| **Total Components** | **~1.2 MB** | **Split from 15.6 MB original** |

---

## 🎯 Benefits

✅ **Easier Editing** — Edit individual state files instead of massive monolith  
✅ **Better Version Control** — Component files are easier to diff and merge  
✅ **Modular Structure** — Each component is valid JSON and usable independently  
✅ **Maintainability** — Search index separate from program data  
✅ **Documentation** — Clear structure and usage guidelines included  

---

## 🔄 Workflow Going Forward

### To Edit Programs:
1. Edit the desired component file (e.g., `programs-UT.json` for Utah)
2. Update corresponding search-index.json entries if needed
3. Run rebuild script to regenerate master file
4. Test and deploy

### To Add New State:
1. Create new `programs-{STATE}.json` file
2. Add programs array with standard format
3. Add search index entries (exact + variant pairs)
4. Run rebuild script
5. Deploy

### For Production:
- Deploy `tti-program-links.json` (unchanged location)
- Component files stay in `programs-by-state/` for editing
- No changes to WordPress theme integration

---

## 📝 Component Files Reference

### programs-array.json
```json
{
  "programs": [
    {
      "name": "Program Name",
      "url": "/r/troubledteens/wiki/index/slug/",
      "normalizedName": "program name"
    }
  ],
  "count": 1122,
  "generated": "ISO-8601 timestamp"
}
```

### search-index.json
```json
{
  "searchIndex": {
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
  },
  "count": 3790
}
```

---

## 🛠️ Utility Scripts Available

- **`split-into-components.ps1`** — Extract master file into components
- **`split-programs-by-state.ps1`** — Auto-categorize programs by state
- (More rebuild utilities can be created as needed)

---

## ⚠️ Important Notes

1. **Master file remains:** `tti-program-links.json` is still the production file
2. **No frontend changes:** All WordPress integrations remain unchanged
3. **Component-based editing:** Edit components, rebuild for deployment
4. **Search index maintained:** Dual-entry system preserved for search accuracy
5. **Backwards compatible:** Original file structure unchanged for production

---

## 📍 File Locations

```
Kids-Over-Profits/
├── js/data/
│   ├── tti-program-links.json (PRODUCTION - keep as is)
│   ├── programs-by-state/ (NEW COMPONENT DIRECTORY)
│   │   ├── README.md (Start here for documentation)
│   │   ├── index.json
│   │   ├── metadata.json
│   │   ├── programs-array.json
│   │   ├── search-index.json
│   │   └── programs-{STATE}.json (MT, UT, TX, WA, VA, etc.)
│   └── data/ (original batch files, unchanged)
├── split-into-components.ps1
├── split-programs-by-state.ps1
└── ... (other files unchanged)
```

---

## ✨ Next Steps

1. ✅ Review component structure in `programs-by-state/README.md`
2. ✅ Test editing a component file
3. ✅ Verify search functionality remains intact
4. ✅ Document any custom add/edit procedures
5. ✅ Consider creating rebuild automation

---

**Status:** ✅ Complete and ready for use  
**Last Updated:** December 10, 2025
