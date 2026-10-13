# Program Name Lists (Wiki Editor Auto-Linker) - Quick Reference

These files feed the wiki editor's auto-linker (`js/auto-linker.js`), which finds program names in wiki text and links them. They are not the facility database: facility records live in `facilities_v2` (see `DATABASE-COLUMNS.md`), and the wider command list is in `CLAUDE.md`.

## Most Common Command

```bash
# After editing any program file, regenerate the aggregate files:
node scripts/aggregate-all-programs.js
```

## File Structure

```
js/data/
├── tti-program-links.json          <- Uncategorized leftovers (inbox)
└── reddit-wiki/
    ├── programs-AK.json ... programs-WY.json   <- one file per state
    ├── programs-CORPORATE.json     <- companies
    ├── programs-NONUSA.json        <- outside the US
    ├── programs-array.json         <- GENERATED: all programs combined
    ├── search-index.json           <- GENERATED: search index
    ├── metadata.json               <- GENERATED: stats (incl. uncategorizedCount)
    └── index.json                  <- GENERATED: file manifest
```

The same folder also holds files other tools use (`live-compare.json`, `update-drafts.json`, `page-urls.json`, ...); the aggregate script leaves them alone.

## Workflow

1. Know the state? Edit `js/data/reddit-wiki/programs-XX.json`. Don't know? Add the entry to `js/data/tti-program-links.json`.
2. When a leftover's state becomes known, move it into the state file and remove it from the leftovers.
3. Run `node scripts/aggregate-all-programs.js`.
4. Never edit the generated files by hand.

## How the Auto-Linker Loads

`inc/enqueue.php` loads `js/auto-linker.js` on the wiki editor page (before the wiki editor scripts). It reads:

1. **Primary**: `programs-array.json` + `search-index.json`
2. **Fallback**: `tti-program-links.json` when the generated files are missing

`loadStateFiles()` and `getAvailableStates()` (from `index.json`) can also read single state files on demand.

## Troubleshooting

- **Auto-linker not finding a program?** Run the aggregate script, then check the browser console for load errors.
- **How many programs are uncategorized?** The aggregate script prints it; also `metadata.json` -> `uncategorizedCount`.
- **Program listed twice?** The aggregate script removes duplicates and keeps the state file's version.
