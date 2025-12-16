const fs = require('fs');
const path = require('path');

// Import the parser
const parserPath = path.join(__dirname, '../js/wiki-parser.js');
if (!fs.existsSync(parserPath)) {
    console.error(`Error: Could not find parser at ${parserPath}`);
    process.exit(1);
}
const { parseWikiMarkdown } = require(parserPath);

// Configuration
const API_URL = process.env.API_URL || 'http://kids-over-profits.local/wp-content/themes/child/api/save-wiki-submission.php';

async function backfillOrganizations() {
    console.log('Starting Organization Backfill...');
    console.log(`Target API: ${API_URL}`);

    // 1. Fetch all existing entries
    // We'll use a large limit to get everything. 
    // Pagination might be needed for thousands, but let's start with 1000.
    const listUrl = `${API_URL}?limit=2000`;
    
    try {
        const response = await fetch(listUrl);
        if (!response.ok) {
            throw new Error(`Failed to fetch entries: ${response.statusText}`);
        }
        const result = await response.json();
        
        if (!result.success || !result.data) {
            throw new Error('Invalid API response structure');
        }

        const entries = result.data;
        console.log(`Fetched ${entries.length} entries from database.`);
        console.log('------------------------------------------------');

        let updatedCount = 0;
        let skippedCount = 0;
        let errorCount = 0;

        for (const entry of entries) {
            // entry has: id, program_name, organization, original_markdown, etc.
            
            if (!entry.original_markdown) {
                console.log(`[SKIP] ID ${entry.id} (${entry.program_name}): No markdown content.`);
                skippedCount++;
                continue;
            }

            // Parse the markdown to find the owner
            const parsedData = parseWikiMarkdown(entry.original_markdown);
            const detectedOwner = parsedData.ownerName ? parsedData.ownerName.trim() : '';

            if (!detectedOwner) {
                // No owner found in markdown
                // console.log(`[SKIP] ID ${entry.id} (${entry.program_name}): No owner detected in markdown.`);
                skippedCount++;
                continue;
            }

            // Check if update is needed
            // We update if the DB organization is empty/null, OR if it differs from what's in the markdown
            const currentOrg = (entry.organization || '').trim();
            
            if (currentOrg === detectedOwner) {
                // Already correct
                skippedCount++;
                continue;
            }

            console.log(`[UPDATE] ID ${entry.id} (${entry.program_name})`);
            console.log(`         Current Org: "${currentOrg}"`);
            console.log(`         New Org:     "${detectedOwner}"`);

            // Prepare Update Payload
            // We essentially re-submit the parsed data to ensure consistency, 
            // attaching the ID to trigger an UPDATE instead of INSERT.
            const payload = {
                ...parsedData,
                id: entry.id,
                programName: entry.program_name, // Preserve exact DB name if preferred, or use parsedData.programName
                organization: detectedOwner,
                // Pass existing status/notes to prevent overwriting them with defaults
                // (Though API might handle defaults, safer to pass knowns if possible, 
                // but the API script doesn't expose them in the list view fully except status)
                status: entry.status || 'submitted',
                
                // IMPORTANT: Send back the original markdown so it doesn't get lost
                originalMarkdown: entry.original_markdown,
                // Use original markdown as generated for consistency unless we want to regenerate
                generatedMarkdown: entry.original_markdown 
            };

            // Send Update
            try {
                const updateResponse = await fetch(API_URL, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                });
                
                const updateResult = await updateResponse.json();
                
                if (updateResult.success) {
                    console.log(`         -> Success!`);
                    updatedCount++;
                } else {
                    console.error(`         -> Failed: ${updateResult.error}`);
                    errorCount++;
                }
            } catch (err) {
                console.error(`         -> Network/System Error: ${err.message}`);
                errorCount++;
            }
        }

        console.log('------------------------------------------------');
        console.log('Backfill Complete.');
        console.log(`Updated: ${updatedCount}`);
        console.log(`Skipped: ${skippedCount} (No change needed or no owner found)`);
        console.log(`Errors:  ${errorCount}`);

    } catch (err) {
        console.error('Fatal Error:', err.message);
    }
}

backfillOrganizations();
