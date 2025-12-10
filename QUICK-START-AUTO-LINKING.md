# Quick Start: Auto-Linking Setup

This is a quick guide to get the auto-linking feature working in 5 minutes.

## Step 1: Create the Program Links File

Copy the template to create your program links database:

```bash
cp js/data/tti-program-links-template.json js/data/tti-program-links.json
```

Or on Windows:
```cmd
copy js\data\tti-program-links-template.json js\data\tti-program-links.json
```

## Step 2: Add Programs to the Database

Open `js/data/tti-program-links.json` in a text editor.

Find the `programs` array and add your TTI programs:

```json
{
  "generated": "2025-12-10T00:00:00.000Z",
  "source": "Reddit r/troubledteens wiki (manually compiled)",
  "programCount": 0,
  "searchIndexSize": 0,
  "programs": [
    {
      "name": "Turn-About Ranch",
      "url": "/r/troubledteens/wiki/index/turnaboutranch/",
      "state": "UT",
      "operator": "Aspen Education Group",
      "normalizedName": "turn-about ranch"
    },
    {
      "name": "Provo Canyon School",
      "url": "/r/troubledteens/wiki/index/provocanyon/",
      "state": "UT",
      "operator": "Universal Health Services",
      "normalizedName": "provo canyon school"
    },
    {
      "name": "KW Legacy",
      "url": "/r/troubledteens/wiki/index/kwlegacy/",
      "state": "FL",
      "operator": "",
      "normalizedName": "kw legacy"
    }
  ],
  "searchIndex": {}
}
```

### Finding Program Information

To get program names and URLs:

1. **Visit Reddit wiki state pages** - Example URLs:
   - Utah: `/r/troubledteens/wiki/index/utah/`
   - Florida: `/r/troubledteens/wiki/index/florida/`
   - etc.

2. **Visit operator/company pages** - Example:
   - Aspen Education: `/r/troubledteens/wiki/index/aspeneducation/`
   - UHS: `/r/troubledteens/wiki/index/uhs/`

3. **Look for links** in this format:
   ```markdown
   [Program Name](/r/troubledteens/wiki/index/programslug/)
   ```

4. **Copy the information**:
   - Program name: The text in brackets
   - URL: The path in parentheses
   - State: From the page you found it on
   - Operator: If mentioned

### Program Entry Format

Each program needs these fields:

```json
{
  "name": "Program Name",              // Required: Exact name as shown on Reddit
  "url": "/r/troubledteens/wiki/...",  // Required: Full wiki path ending with /
  "state": "XX",                       // Optional: Two-letter state code
  "operator": "Company Name",          // Optional: Parent company
  "normalizedName": "program name"     // Required: Lowercase version of name
}
```

## Step 3: Rebuild the Search Index

After adding programs, run this command to generate the search index:

```bash
node scripts/rebuild-search-index.js
```

You should see:
```
=== Rebuilding Search Index ===

Found X programs
Rebuilding search index...
✓ Generated XX searchable entries
✓ Saved to: js/data/tti-program-links.json

✅ Search index rebuilt successfully!
```

## Step 4: Test the Auto-Linking

1. **Open the wiki editor** in your browser

2. **Check the console** (F12 → Console tab) - you should see:
   ```
   ✓ Loaded X TTI programs for auto-linking
   ```

3. **Fill out a test entry**:
   - Program Name: "Test Program"
   - Add a staff member with bio: "Previously worked at Turn-About Ranch and Provo Canyon School"

4. **Ensure checkbox is checked**:
   ```
   ☑ Automatically link TTI program mentions
   ```

5. **Click "Generate Wiki Code"**

6. **Check the output** - you should see:
   ```markdown
   Previously worked at [Turn-About Ranch](/r/troubledteens/wiki/index/turnaboutranch/)
   and [Provo Canyon School](/r/troubledteens/wiki/index/provocanyon/).
   ```

## Quick Example: Adding 10 Programs

Here's a starter database with 10 common programs:

```json
{
  "generated": "2025-12-10T00:00:00.000Z",
  "source": "Reddit r/troubledteens wiki (manually compiled)",
  "programs": [
    {
      "name": "Turn-About Ranch",
      "url": "/r/troubledteens/wiki/index/turnaboutranch/",
      "state": "UT",
      "operator": "Aspen Education Group",
      "normalizedName": "turn-about ranch"
    },
    {
      "name": "Provo Canyon School",
      "url": "/r/troubledteens/wiki/index/provocanyon/",
      "state": "UT",
      "operator": "Universal Health Services",
      "normalizedName": "provo canyon school"
    },
    {
      "name": "Elan School",
      "url": "/r/troubledteens/wiki/index/elan/",
      "state": "ME",
      "operator": "",
      "normalizedName": "elan school"
    },
    {
      "name": "CEDU",
      "url": "/r/troubledteens/wiki/index/cedu/",
      "state": "CA",
      "operator": "",
      "normalizedName": "cedu"
    },
    {
      "name": "Tranquility Bay",
      "url": "/r/troubledteens/wiki/index/tranquilitybay/",
      "state": "Jamaica",
      "operator": "WWASP",
      "normalizedName": "tranquility bay"
    },
    {
      "name": "Redcliff Ascent",
      "url": "/r/troubledteens/wiki/index/redcliffascent/",
      "state": "UT",
      "operator": "",
      "normalizedName": "redcliff ascent"
    },
    {
      "name": "Shortridge Academy",
      "url": "/r/troubledteens/wiki/index/shortridgeacademy/",
      "state": "NH",
      "operator": "",
      "normalizedName": "shortridge academy"
    },
    {
      "name": "Discovery Academy",
      "url": "/r/troubledteens/wiki/index/discoveryacademy/",
      "state": "UT",
      "operator": "",
      "normalizedName": "discovery academy"
    },
    {
      "name": "Benchmark Behavioral Health",
      "url": "/r/troubledteens/wiki/index/benchmark/",
      "state": "UT",
      "operator": "",
      "normalizedName": "benchmark behavioral health"
    },
    {
      "name": "KW Legacy",
      "url": "/r/troubledteens/wiki/index/kwlegacy/",
      "state": "FL",
      "operator": "",
      "normalizedName": "kw legacy"
    }
  ],
  "searchIndex": {}
}
```

**After saving this**, run:
```bash
node scripts/rebuild-search-index.js
```

## Next Steps

- **Add more programs**: Visit Reddit wiki pages and add all programs
- **Test thoroughly**: Try different program mentions to ensure they link correctly
- **Maintain the database**: When new programs are added to Reddit, update your database

## Troubleshooting

**"Cannot find module" error when running rebuild script:**
- Make sure you're in the project root directory
- Check that Node.js is installed: `node --version`

**Auto-linker not loading:**
- Check browser console for errors
- Verify the file exists: `js/data/tti-program-links.json`
- Clear browser cache

**Programs not being linked:**
- Ensure you ran `rebuild-search-index.js`
- Check that the program name matches exactly
- Try the full program name including "School", "Ranch", etc.

## Need Help?

See the full [AUTO-LINKING-GUIDE.md](AUTO-LINKING-GUIDE.md) for detailed documentation.
