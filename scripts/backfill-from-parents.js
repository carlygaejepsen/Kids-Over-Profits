const fs = require('fs');
const path = require('path');

// Configuration
const API_URL = process.env.API_URL || 'http://kids-over-profits.local/wp-content/themes/child/api/save-wiki-submission.php';
const CORPORATE_LIST_PATH = path.join(__dirname, '../js/data/reddit-wiki/programs-CORPORATE.json');

async function backfillFromParents() {
    console.log('Starting Backfill from Parent Organization Pages...');
    
    // 1. Load the list of Corporate/Parent Organizations
    if (!fs.existsSync(CORPORATE_LIST_PATH)) {
        console.error(`Error: Corporate list not found at ${CORPORATE_LIST_PATH}`);
        return;
    }
    const corporateData = JSON.parse(fs.readFileSync(CORPORATE_LIST_PATH, 'utf8'));
    const parentOrgs = corporateData.programs; // Array of { name, url, normalizedName }
    
    console.log(`Loaded ${parentOrgs.length} parent organizations to scan.`);

    // 2. Fetch ALL database entries to build a lookup map
    console.log('Fetching all database entries...');
    const listUrl = `${API_URL}?limit=5000`; // Fetch plenty
    let dbEntries = [];
    try {
        const response = await fetch(listUrl);
        const result = await response.json();
        if (result.success && result.data) {
            dbEntries = result.data;
        } else {
            throw new Error('Failed to load DB entries');
        }
    } catch (err) {
        console.error('Fatal API Error:', err.message);
        return;
    }

    console.log(`Loaded ${dbEntries.length} entries from database.`);

    // Build Lookups for fast access
    // Map: Normalized Name -> Entry Object
    const entryByName = new Map();
    dbEntries.forEach(entry => {
        if (entry.program_name) {
            entryByName.set(entry.program_name.toLowerCase().trim(), entry);
        }
    });

    let updatedCount = 0;
    let errorCount = 0;

    // 3. Process each Parent Organization
    for (const org of parentOrgs) {
        const orgName = org.name;
        const normalizedOrgName = org.normalizedName || orgName.toLowerCase();
        
        console.log(`\nProcessing Organization: "${orgName}"...`);

        // Find the Organization's own page in the DB
        const orgEntry = entryByName.get(normalizedOrgName) || entryByName.get(orgName.toLowerCase());

        if (!orgEntry) {
            console.log(`  [SKIP] Org page not found in DB.`);
            continue;
        }

        if (!orgEntry.original_markdown) {
            console.log(`  [SKIP] Org page has no markdown content.`);
            continue;
        }

        // Parse Markdown for Links
        // Regex to find [Name](URL)
        const linkRegex = /[[^\]]+]\]\(([^)]+)\)/g;
        const markdown = orgEntry.original_markdown;
        let match;
        const foundFacilities = [];

        while ((match = linkRegex.exec(markdown)) !== null) {
            const linkText = match[1].trim();
            const linkUrl = match[2].trim();

            // Filter for valid facility links
            // Must be a wiki link, not a user link, not the index itself
            const isWikiLink = linkUrl.includes('/wiki/') || linkUrl.includes('/r/troubledteens/');
            const isUserLink = linkUrl.includes('/u/') || linkUrl.includes('/user/');
            const isJustIndex = linkUrl.endsWith('/index/') || linkUrl.endsWith('/index');
            
            // Exclude the org itself (sometimes they link to themselves or sections)
            const isSelf = linkText.toLowerCase() === normalizedOrgName;

            if (isWikiLink && !isUserLink && !isJustIndex && !isSelf && linkText.length > 2) {
                foundFacilities.push({
                    name: linkText,
                    url: linkUrl
                });
            }
        }

        if (foundFacilities.length === 0) {
            console.log(`  No facility links found in markdown.`);
            continue;
        }

        console.log(`  Found ${foundFacilities.length} potential facilities linked.`);

        // Update each found facility
        for (const facility of foundFacilities) {
            const facilityEntry = entryByName.get(facility.name.toLowerCase());

            if (!facilityEntry) {
                // Facility linked in markdown but not found in DB
                // console.log(`    - Facility "${facility.name}" not in DB.`);
                continue;
            }

            // Check if update is needed
            const currentOrg = (facilityEntry.organization || '').trim();
            
            // Logic: Update if empty, or if different (assuming parent page is authoritative)
            // But maybe avoid overwriting if it's already set to something *else* that looks valid?
            // User said: "you can tell which ones are supposed to be categorized where because it's indicated on the markdown page"
            // This implies the Parent Page is the source of truth.
            
            if (currentOrg.toLowerCase() === orgName.toLowerCase()) {
                // Already set correctly
                continue;
            }

            if (currentOrg) {
                 console.log(`    [?] Conflict: "${facility.name}" is currently "${currentOrg}", parent says "${orgName}". Overwriting...`);
            }

            console.log(`    [UPDATE] "${facility.name}" -> Organization: "${orgName}"`);

            // Perform Update
            // We need to fetch the FULL data for the entry first to avoid data loss?
            // The list API endpoint returns partial data?
            // `api/save-wiki-submission.php` GET list returns: id, program_name, city_state, organization, program_type, years_active, status, original_markdown, json_data
            // It seems to return `json_data` (full JSON).
            
            // We need to parse json_data to modify it properly?
            let jsonData = {};
            if (typeof facilityEntry.json_data === 'string') {
                try { jsonData = JSON.parse(facilityEntry.json_data); } catch(e) {}
            } else {
                jsonData = facilityEntry.json_data || {};
            }

            // Update the JSON data AND the top-level field
            const payload = {
                ...jsonData,
                id: facilityEntry.id,
                programName: facilityEntry.program_name,
                organization: orgName, // The change
                originalMarkdown: facilityEntry.original_markdown,
                generatedMarkdown: facilityEntry.generated_markdown || facilityEntry.original_markdown,
                // Ensure required fields are present
                submittedBy: facilityEntry.submitted_by || 'backfill-script',
                status: facilityEntry.status || 'submitted'
            };

            try {
                const updateRes = await fetch(API_URL, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                });
                const updateResult = await updateRes.json();
                
                if (updateResult.success) {
                    updatedCount++;
                    // Update our local map so we don't re-process if linked multiple times
                    facilityEntry.organization = orgName; 
                } else {
                    console.error(`      Failed: ${updateResult.error}`);
                    errorCount++;
                }
            } catch (err) {
                console.error(`      Network Error: ${err.message}`);
                errorCount++;
            }
        }
    }

    console.log('------------------------------------------------');
    console.log('Backfill Complete.');
    console.log(`Total Updates: ${updatedCount}`);
    console.log(`Errors:        ${errorCount}`);
}

backfillFromParents();
