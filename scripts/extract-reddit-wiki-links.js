/**
 * Reddit Wiki Program Link Extractor
 *
 * This script extracts all TTI program names and their wiki links from Reddit.
 * Run this periodically to update the program links reference file.
 *
 * Usage:
 * 1. Manual: Paste wiki index markdown into wikiIndexMarkdown variable
 * 2. Automated: Use Reddit API (requires credentials)
 */

const fs = require('fs');
const path = require('path');

// ============================================
// CONFIGURATION
// ============================================

// Option 1: Paste your Reddit wiki index markdown here
const wikiIndexMarkdown = `
// Paste the content from https://www.reddit.com/r/troubledteens/wiki/index here
// Or provide it via command line argument: node extract-reddit-wiki-links.js path/to/index.md
`;

// Output file path
const OUTPUT_FILE = path.join(__dirname, '..', 'js', 'data', 'tti-program-links.json');

// ============================================
// EXTRACTION LOGIC
// ============================================

/**
 * Extract program names and wiki links from Reddit markdown
 * Handles various markdown link formats:
 * - [Program Name](/r/troubledteens/wiki/index/programname)
 * - [Program Name](programname)
 * - [[Program Name]]
 */
function extractProgramLinks(markdown) {
    const programs = [];
    const seenNames = new Set();

    // Pattern 1: Standard markdown links [Name](url)
    // Matches: [Turn-About Ranch](/r/troubledteens/wiki/index/turnaboutranch)
    const standardLinkPattern = /\[([^\]]+)\]\(([^)]+)\)/g;
    let match;

    while ((match = standardLinkPattern.exec(markdown)) !== null) {
        const name = match[1].trim();
        let url = match[2].trim();

        // Skip if not a wiki link or if it's a general link (http, etc.)
        if (url.startsWith('http') && !url.includes('/wiki/')) continue;
        if (url.startsWith('#')) continue; // Skip anchor links

        // Normalize the URL
        if (!url.startsWith('/r/troubledteens/wiki/')) {
            if (url.startsWith('/')) {
                url = url; // Keep as-is
            } else {
                url = `/r/troubledteens/wiki/index/${url}`;
            }
        }

        // Skip duplicates
        const normalizedName = name.toLowerCase().trim();
        if (seenNames.has(normalizedName)) continue;

        // Filter out common non-program links
        const skipPatterns = [
            'index', 'home', 'wiki', 'main', 'page', 'list',
            'parent', 'companies', 'transport', 'educational consultant',
            'support group', 'resource', 'about', 'faq', 'glossary'
        ];
        if (skipPatterns.some(pattern => normalizedName.includes(pattern))) {
            continue;
        }

        seenNames.add(normalizedName);
        programs.push({
            name: name,
            url: url,
            normalizedName: normalizedName
        });
    }

    // Pattern 2: Wiki-style links [[Program Name]]
    const wikiStylePattern = /\[\[([^\]]+)\]\]/g;
    while ((match = wikiStylePattern.exec(markdown)) !== null) {
        const name = match[1].trim();
        const normalizedName = name.toLowerCase().trim();

        if (seenNames.has(normalizedName)) continue;

        const slug = name.toLowerCase()
            .replace(/[^a-z0-9]+/g, '')
            .replace(/\s+/g, '');

        seenNames.add(normalizedName);
        programs.push({
            name: name,
            url: `/r/troubledteens/wiki/index/${slug}`,
            normalizedName: normalizedName
        });
    }

    return programs.sort((a, b) => a.name.localeCompare(b.name));
}

/**
 * Build a search index for efficient auto-linking
 * Creates variations of names for better matching
 */
function buildSearchIndex(programs) {
    const index = {};

    programs.forEach(program => {
        const name = program.name;
        const url = program.url;

        // Add exact name
        index[name.toLowerCase()] = { name, url };

        // Add variations without common suffixes
        const variations = [
            name.replace(/\s+(School|Academy|Ranch|Center|Program|Institute|Home|Facility|Services|Group)$/i, ''),
            name.replace(/\s+(RTC|TBS|Wilderness|Therapeutic)$/i, ''),
            // Handle possessive forms
            name.replace(/'s$/i, ''),
        ];

        variations.forEach(variation => {
            const key = variation.toLowerCase().trim();
            if (key && !index[key]) {
                index[key] = { name, url, isVariation: true };
            }
        });

        // Add acronyms if name has multiple capital letters
        const acronymMatch = name.match(/\b[A-Z][A-Z]+\b/);
        if (acronymMatch) {
            const acronym = acronymMatch[0];
            if (!index[acronym.toLowerCase()]) {
                index[acronym.toLowerCase()] = { name, url, isAcronym: true };
            }
        }
    });

    return index;
}

// ============================================
// MAIN EXECUTION
// ============================================

function main() {
    let markdown = wikiIndexMarkdown;

    // Check if markdown file provided as argument
    if (process.argv[2]) {
        const inputFile = path.resolve(process.argv[2]);
        if (fs.existsSync(inputFile)) {
            markdown = fs.readFileSync(inputFile, 'utf8');
            console.log(`✓ Loaded markdown from: ${inputFile}`);
        } else {
            console.error(`✗ File not found: ${inputFile}`);
            process.exit(1);
        }
    }

    if (!markdown || markdown.trim().length < 100) {
        console.error('✗ No markdown content found!');
        console.error('Please either:');
        console.error('  1. Edit this file and paste wiki index markdown into the wikiIndexMarkdown variable');
        console.error('  2. Run: node extract-reddit-wiki-links.js path/to/wiki-index.md');
        process.exit(1);
    }

    console.log('Extracting program links from Reddit wiki...');
    const programs = extractProgramLinks(markdown);

    console.log(`✓ Found ${programs.length} unique programs`);

    const searchIndex = buildSearchIndex(programs);
    console.log(`✓ Built search index with ${Object.keys(searchIndex).length} entries`);

    const output = {
        generated: new Date().toISOString(),
        programCount: programs.length,
        programs: programs,
        searchIndex: searchIndex
    };

    // Ensure output directory exists
    const outputDir = path.dirname(OUTPUT_FILE);
    if (!fs.existsSync(outputDir)) {
        fs.mkdirSync(outputDir, { recursive: true });
    }

    fs.writeFileSync(OUTPUT_FILE, JSON.stringify(output, null, 2), 'utf8');
    console.log(`✓ Saved to: ${OUTPUT_FILE}`);
    console.log('\nSample programs:');
    programs.slice(0, 5).forEach(p => {
        console.log(`  - ${p.name} → ${p.url}`);
    });
}

if (require.main === module) {
    main();
}

module.exports = { extractProgramLinks, buildSearchIndex };
