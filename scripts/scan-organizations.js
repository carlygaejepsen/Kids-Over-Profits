const fs = require('fs');
const path = require('path');

// Configuration
// We are only scanning files for now, so no API URL needed yet.
const SOURCE_DIR = 'C:\\Users\\daniu\\Local Sites\\kids-over-profits\\app\\public\\wp-content\\themes\\child\\markdown_output';

// Sections that indicate this file is an Organization listing other programs
const TARGET_SECTIONS = [
    'Programs',
    'Facilities',
    'Schools',
    'Locations',
    'Affiliated Programs',
    'Affiliated Companies',
    'WWASP Programs',
    'WWASP-Affiliated',
    'Owned Programs',
    'Closed Programs',
    'Active Programs',
    'Residential Programs'
];

function scanForOrganizations() {
    console.log('Scanning markdown files for Organization/Program lists...');
    console.log(`Source Dir: ${SOURCE_DIR}`);
    console.log('------------------------------------------------');

    if (!fs.existsSync(SOURCE_DIR)) {
        console.error(`Error: Directory not found.`);
        return;
    }

    const files = fs.readdirSync(SOURCE_DIR).filter(f => f.endsWith('.md'));
    console.log(`Found ${files.length} markdown files in directory.`);
    if (files.length > 0) {
        console.log(`First file: ${files[0]}`);
    }
    let orgCount = 0;

    for (const file of files) {
        if (file === 'acadiahealth.md') console.log('Processing acadiahealth.md...');
        const filePath = path.join(SOURCE_DIR, file);
        const content = fs.readFileSync(filePath, 'utf8');

        // 1. Determine Potential Parent Name
        // Try to get it from the first header
        let parentName = '';
        const headerMatch = content.match(/^#{1,3}\s*\*{0,2}(.+?)\*{0,2}\s*(\(|$)/m);
        if (headerMatch) {
            parentName = headerMatch[1].trim();
        } else {
            parentName = path.parse(file).name;
        }

        // Skip generic names or templates
        if (!parentName || parentName.includes('Page title')) continue;

        // 2. Scan for Target Sections
        // We'll look for "## [Section Name]"
        let foundChildren = [];
        
        // Helper to extract links
        const extractLinks = (text) => {
            const links = [];
            // Match standard links [Text](URL)
            const linkRegex = /\[([^\]]+)\]\(([^)]+)\)/g;
            let match;
            while ((match = linkRegex.exec(text)) !== null) {
                // Remove ** bold markers from the name
                const name = match[1].replace(/\*\*/g, '').trim();
                const url = match[2].trim();
                
                // Filter: Must be internal wiki link, not user, not index
                const isWiki = url.includes('/wiki/') || url.includes('/r/troubledteens/');
                const isUser = url.includes('/u/') || url.includes('/user/');
                const isIndex = url.endsWith('/index') || url.endsWith('/index/');
                
                if (isWiki && !isUser && !isIndex && name.toLowerCase() !== parentName.toLowerCase()) {
                    links.push(name);
                }
            }
            return links;
        };

        const sections = content.split(/^##\s+/gm);
        let isOrganizationFile = false;

        for (const section of sections) {
            const firstLineEnd = section.indexOf('\n');
            if (firstLineEnd === -1) continue;
            
            const titleLine = section.substring(0, firstLineEnd).trim();
            // Remove bold/italics from title for checking
            const cleanTitle = titleLine.replace(/[\*_]/g, ''); 
            
            const body = section.substring(firstLineEnd);

            // Fuzzy check: Section title MUST contain one of the target keywords
            const isTargetSection = TARGET_SECTIONS.some(target => 
                cleanTitle.toLowerCase().includes(target.toLowerCase())
            );

            if (isTargetSection) {
                const links = extractLinks(body);
                if (links.length > 0) {
                    foundChildren.push(...links);
                    isOrganizationFile = true;
                }
            }
        }

        if (isOrganizationFile && foundChildren.length > 0) {
            orgCount++;
            console.log(`\n[ORGANIZATION] ${parentName} (File: ${file})`);
            console.log(`  Found ${foundChildren.length} programs:`);
            // List first 5 then summary
            foundChildren.slice(0, 5).forEach(child => console.log(`    - ${child}`));
            if (foundChildren.length > 5) console.log(`    ... and ${foundChildren.length - 5} more`);
        }
    }

    console.log('------------------------------------------------');
    console.log(`Scan Complete. Found ${orgCount} Organization files containing lists.`);
}

scanForOrganizations();
