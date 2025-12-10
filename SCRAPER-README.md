# Automated Program Link Scraper

## ONE Command to Get ALL Program Links

Instead of manually visiting 100+ pages, just run:

```bash
node scripts/scrape-all-programs.js
```

## What This Does

The script automatically:
1. Fetches all 51 state pages from Reddit
2. Fetches all 54 company/organization pages
3. Extracts program links from each page
4. Removes duplicates
5. Compiles everything into `js/data/tti-program-links.json`
6. Rebuilds the search index automatically

**Total pages scraped:** 105 pages (51 states + 54 companies)

## Usage

```bash
# Run the scraper
node scripts/scrape-all-programs.js
```

You'll see output like:
```
=== Automated TTI Program Link Scraper ===

Starting scrape of 51 state pages + 54 company pages...
Total pages to scrape: 105

[1/105] Fetching ALABAMA... ✓ Found 12 new programs
[2/105] Fetching ALASKA... ✓ Found 3 new programs
[3/105] Fetching ARIZONA... ✓ Found 18 new programs
...
[105/105] Fetching YOUTHTRACKS... ✓ Found 5 new programs

=== Scraping Complete ===
✓ Successfully scraped: 105 pages
✗ Failed: 0 pages
📊 Total unique programs found: 450

✓ Saved to: js/data/tti-program-links.json

=== Rebuilding Search Index ===
✓ Generated 1350 searchable entries
✓ Saved to: js/data/tti-program-links.json

✅ All done! Your auto-linking database is ready to use.
```

## How Long Does It Take?

The script includes a 1-second delay between requests to be respectful to Reddit's servers. Total time: **~2 minutes**.

## What It Scrapes

### State Pages (51 total)
All 50 US states + "non-USA" programs:
- active-programs-alabama
- active-programs-alaska
- active-programs-arizona
- ... (all 50 states)
- active-programs-nonusa

### Company Pages (54 total)
Major TTI companies and organizations:
- Aspen Education Group
- Universal Health Services (UHS)
- WWASP
- CEDU
- CRC Health
- Sequel Youth Services
- Brown Schools
- ... (and 47 more)

## After Running

Once the script completes:

1. Your database file is at: `js/data/tti-program-links.json`
2. The search index is already built
3. Auto-linking is ready to use in the wiki editor
4. Test it by creating a wiki entry and mentioning a program name

## Troubleshooting

### "Module not found" error
Make sure you're in the project root directory.

### Network errors
The script will skip failed pages and continue. Run it again to retry failed pages.

### Some programs not found
Reddit's HTML structure might have changed. Check the extraction regex in the script.

## Manual Alternative

If you prefer the manual tools:
- **Browser tool:** Open `extract-links-tool.html` in your browser
- **Console script:** Copy/paste `scripts/extract-links-from-page.js` in browser console
- **Instructions:** See `GET-STARTED-NOW.md`

But really, just use the automated scraper. It's way easier.
