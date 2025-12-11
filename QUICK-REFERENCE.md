# Wiki Editor Data - Quick Reference

## 🚀 Quick Start

### Most Common Command

```bash
# After editing any program files, regenerate components:
node scripts/aggregate-all-programs.js
```

## 📁 File Structure

```
js/data/
├── tti-program-links.json          ← Uncategorized leftovers (inbox)
└── reddit-wiki/                    ← Organized data
    ├── programs-AK.json            ← Alaska programs
    ├── programs-AL.json            ← Alabama programs
    ├── ... (52 state files)        ← All states + CORPORATE + NONUSA
    ├── programs-array.json         ← GENERATED: All programs combined
    ├── search-index.json           ← GENERATED: Search index
    ├── metadata.json               ← GENERATED: Stats
    └── index.json                  ← GENERATED: File manifest
```

## 🔄 Simple Workflow

### Adding/Editing Programs

```bash
# 1. Edit the appropriate file:
#    - Know the state? Edit programs-XX.json directly
#    - Don't know? Add to tti-program-links.json (leftovers)

vim js/data/reddit-wiki/programs-UT.json

# 2. Regenerate component files
node scripts/aggregate-all-programs.js

# 3. Done! Auto-linker automatically picks up changes
```

### Moving from Leftovers to State Files

```bash
# 1. Open leftovers file
vim js/data/tti-program-links.json

# 2. Copy a program entry

# 3. Paste into appropriate state file
vim js/data/reddit-wiki/programs-UT.json

# 4. Remove from leftovers file

# 5. Regenerate
node scripts/aggregate-all-programs.js
```

## 🎯 Architecture

### Data Sources (Equal Partners)

1. **State Files** = Categorized programs organized by state
2. **Leftovers** = Uncategorized programs (temporary holding)
3. **Component Files** = Auto-generated aggregation of both

### Auto-Linker Loading

```javascript
// Auto-linker loads from component files (PRIMARY)
programs-array.json  ← All programs
search-index.json    ← Search index

// Falls back to leftovers if components missing (FALLBACK)
tti-program-links.json
```

## 📝 Available Script

| Script | Purpose | Usage |
|--------|---------|-------|
| `aggregate-all-programs.js` | Combine all sources → components | `node scripts/aggregate-all-programs.js` |

## 💡 Tips

- **Auto-linker loading:**
  1. **Primary:** Loads from component files (programs-array.json + search-index.json)
  2. **Fallback:** Falls back to tti-program-links.json if components missing
- **Never edit component files directly:** They're auto-generated
- **Edit state files or leftovers:** Then regenerate components
- **Leftovers = inbox:** Move programs to state files when you know their state
- **Run aggregate script:** After any edits to programs

## 🔍 Data Flow

```
State Files (programs-XX.json) ──┐
                                 ├─→ Aggregate Script ─→ Component Files ─→ Auto-Linker
Leftovers (tti-program-links.json) ┘     (combines all)     (generated)      (loads)
```

## ❓ Troubleshooting

**Q: Auto-linker not finding programs?**
- Run: `node scripts/aggregate-all-programs.js`
- Check browser console for loading errors

**Q: How many programs are uncategorized?**
- Run aggregate script - it shows uncategorized count
- Check `js/data/reddit-wiki/metadata.json` → `uncategorizedCount`

**Q: Which programs are in leftovers?**
- Check `js/data/tti-program-links.json`

**Q: Program appears twice?**
- Aggregate script automatically removes duplicates
- Keeps state file version, removes leftover version

## 📊 File Sizes

- State files: 1-250 KB each (varies by state)
- programs-array.json: ~300 KB (all programs)
- search-index.json: ~600 KB (search variants)
- tti-program-links.json: Shrinks as you categorize

## 🎓 Best Practices

1. **Know the state?** → Edit state file directly
2. **Don't know?** → Add to leftovers temporarily
3. **Always regenerate** after edits: `node scripts/aggregate-all-programs.js`
4. **Check uncategorized count** periodically
5. **Move programs from leftovers to states** as you categorize them

## 🔗 Integration

The auto-linker (in `js/auto-linker.js`) is already hooked up in `functions.php` and automatically loads the latest component files. No additional setup needed!
