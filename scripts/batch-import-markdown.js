const fs = require('fs');
const path = require('path');

// Import the parser from the js directory
// We expect this script to be run from the project root or scripts/ directory
// so we resolve strictly relative to __dirname
const parserPath = path.join(__dirname, '../js/wiki-parser.js');

if (!fs.existsSync(parserPath)) {
    console.error(`Error: Could not find parser at ${parserPath}`);
    process.exit(1);
}

const { parseWikiMarkdown } = require(parserPath);

// The generator lives in a separate module (wiki-generation.js is the single
// source of truth). We run it so the stored generated_markdown is the formatted
// page, not the raw source.
const generationPath = path.join(__dirname, '../js/wiki-generation.js');
if (!fs.existsSync(generationPath)) {
    console.error(`Error: Could not find generator at ${generationPath}`);
    process.exit(1);
}
const { generateWikiMarkdown } = require(generationPath);

// Configuration
const API_URL = process.env.API_URL || 'http://127.0.0.1/wp-content/themes/child/api/save-wiki-submission.php';
// Default to the path provided by the user, but allow CLI override
const DEFAULT_SOURCE_DIR = 'C:\\Users\\daniu\\Local Sites\\kids-over-profits\\app\\public\\wp-content\\themes\\child\\markdown_output';
const SOURCE_DIR = process.argv[2] || DEFAULT_SOURCE_DIR;

async function importFiles() {
    console.log(`Source Directory: ${SOURCE_DIR}`);
    console.log(`Target API URL:   ${API_URL}`);
    
    if (!fs.existsSync(SOURCE_DIR)) {
        console.error(`Error: Directory not found: ${SOURCE_DIR}`);
        return;
    }

    const files = fs.readdirSync(SOURCE_DIR).filter(file => file.endsWith('.md') || file.endsWith('.txt'));
    
    if (files.length === 0) {
        console.log('No markdown (.md) or text (.txt) files found in the directory.');
        return;
    }

    console.log(`Found ${files.length} files to process.`);
    console.log('Starting import...');
    console.log('------------------------------------------------');

    let successCount = 0;
    let failCount = 0;

    for (const file of files) {
        const filePath = path.join(SOURCE_DIR, file);
        
        try {
            const content = fs.readFileSync(filePath, 'utf8');
            
            // Parse the markdown content
            const parsedData = parseWikiMarkdown(content);
            
            // Validation: Must have at least a program name
            if (!parsedData.programName) {
                console.warn(`[SKIP] ${file} - Could not extract Program Name`);
                failCount++;
                continue;
            }

            // CHECK FOR EXISTING ENTRY
            let existingId = null;
            let existingStatus = null;
            let existingSubmittedBy = null;
            try {
                const searchUrl = `${API_URL}?search=${encodeURIComponent(parsedData.programName)}`;
                const searchRes = await fetch(searchUrl);
                if (searchRes.ok) {
                    const searchResult = await searchRes.json();
                    if (searchResult.success && searchResult.data) {
                        const match = searchResult.data.find(e => e.program_name.toLowerCase() === parsedData.programName.toLowerCase());
                        if (match) {
                            existingId = match.id;
                            existingStatus = match.status;
                            existingSubmittedBy = match.submitted_by;
                            console.log(`[INFO] ${file} - Found existing entry ID: ${existingId} (status: ${existingStatus}). Updating...`);
                        }
                    }
                }
            } catch (e) {
                console.warn(`[WARN] Search check failed for ${parsedData.programName}: ${e.message}`);
            }

            // Prepare payload matching the API expectations
            const payload = {
                ...parsedData,
                id: existingId, // Include ID if updating
                originalMarkdown: content,
                // Store the FORMATTED output, not the raw source. The generator
                // reads historyMisc directly (fallback), so no manual bridge needed.
                generatedMarkdown: generateWikiMarkdown(parsedData),
                // Preserve the existing status when updating an entry; only brand-new
                // imports default to 'submitted'. (Avoids flipping approved/live
                // pages back to draft.)
                status: existingId ? (existingStatus || 'submitted') : 'submitted',
                submittedBy: existingId ? (existingSubmittedBy || 'batch-import-script') : 'batch-import-script',
                submissionNotes: `Batch imported from file: ${file}`
            };

            // Send to API
            // Use built-in fetch (Node 18+)
            const response = await fetch(API_URL, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify(payload)
            });

            const text = await response.text();
            let result;
            
            try {
                result = JSON.parse(text);
            } catch (e) {
                // If JSON parse fails, it might be a PHP error or 404 HTML page
                console.error(`[FAIL] ${file} - Invalid JSON response`);
                console.error(`       Response snippet: ${text.substring(0, 100)}...`);
                failCount++;
                continue;
            }

            if (!response.ok || !result.success) {
                const errorMsg = result.error || result.message || response.statusText;
                console.error(`[FAIL] ${file} - API Error: ${errorMsg}`);
                failCount++;
            } else {
                console.log(`[OK]   ${file} -> "${parsedData.programName}" (ID: ${result.id})`);
                successCount++;
            }

        } catch (err) {
            console.error(`[ERR]  ${file} - System Error: ${err.message}`);
            // console.error(err); // Uncomment for full stack trace
            failCount++;
        }
    }

    console.log('------------------------------------------------');
    console.log(`Import Complete.`);
    console.log(`Successful: ${successCount}`);
    console.log(`Failed:     ${failCount}`);
}

importFiles().catch(err => {
    console.error('Fatal Script Error:', err);
});
