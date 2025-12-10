/**
 * Rebuild Search Index for TTI Program Links
 *
 * This script reads the programs array from tti-program-links.json
 * and regenerates the searchIndex with all variants.
 *
 * Usage: node scripts/rebuild-search-index.js
 */

const fs = require('fs');
const path = require('path');

const LINKS_FILE = path.join(__dirname, '..', 'js', 'data', 'tti-program-links.json');

/**
 * Build search index with name variations
 */
function buildSearchIndex(programs) {
    const index = {};

    programs.forEach(({ name, url, state, operator }) => {
        const exactKey = name.toLowerCase().trim();

        // Add exact match
        index[exactKey] = { name, url, matchType: 'exact' };

        // Add variants without common suffixes
        const suffixes = [
            'School', 'Academy', 'Ranch', 'Center', 'Program', 'Institute',
            'Home', 'Facility', 'Services', 'Group', 'RTC', 'TBS',
            'Wilderness', 'Therapeutic', 'Boarding School', 'Treatment Center',
            'Residential', 'Treatment', 'Behavioral', 'Health', 'Campus'
        ];

        suffixes.forEach(suffix => {
            const regex = new RegExp(`\\s+${suffix}$`, 'i');
            const variant = name.replace(regex, '').trim();
            const variantKey = variant.toLowerCase();

            if (variantKey !== exactKey && variantKey.length >= 3 && !index[variantKey]) {
                index[variantKey] = { name, url, matchType: 'variant' };
            }
        });

        // Handle hyphenated variations (e.g., "Turn-About" → "Turnabout")
        if (name.includes('-')) {
            const unhyphenated = name.replace(/-/g, ' ');
            const key = unhyphenated.toLowerCase();
            if (!index[key]) {
                index[key] = { name, url, matchType: 'variant' };
            }

            const compressed = name.replace(/-/g, '');
            const compressedKey = compressed.toLowerCase();
            if (!index[compressedKey]) {
                index[compressedKey] = { name, url, matchType: 'variant' };
            }
        }

        // Generate acronym from multi-word names
        const words = name.split(/[\s-]+/);
        if (words.length >= 2 && words.length <= 5) {
            const acronym = words
                .filter(w => /^[A-Z]/i.test(w))
                .map(w => w[0])
                .join('')
                .toUpperCase();

            if (acronym.length >= 2 && acronym.length <= 6) {
                const acronymKey = acronym.toLowerCase();
                if (!index[acronymKey]) {
                    index[acronymKey] = { name, url, matchType: 'acronym' };
                }
            }
        }

        // Add existing acronyms in name
        const acronymMatches = name.match(/\b[A-Z]{2,}\b/g);
        if (acronymMatches) {
            acronymMatches.forEach(acronym => {
                const key = acronym.toLowerCase();
                if (!index[key]) {
                    index[key] = { name, url, matchType: 'acronym' };
                }
            });
        }

        // Handle possessives
        const possessive = name.replace(/'s$/i, '').trim();
        if (possessive !== name) {
            const possessiveKey = possessive.toLowerCase();
            if (!index[possessiveKey]) {
                index[possessiveKey] = { name, url, matchType: 'variant' };
            }
        }

        // Add "The X" variations if name starts with "The"
        if (name.startsWith('The ')) {
            const withoutThe = name.substring(4);
            const key = withoutThe.toLowerCase();
            if (!index[key]) {
                index[key] = { name, url, matchType: 'variant' };
            }
        }
    });

    return index;
}

/**
 * Main execution
 */
function main() {
    console.log('=== Rebuilding Search Index ===\n');

    if (!fs.existsSync(LINKS_FILE)) {
        console.error(`❌ File not found: ${LINKS_FILE}`);
        console.error('Please create the file first by copying tti-program-links-template.json');
        process.exit(1);
    }

    // Read existing file
    const data = JSON.parse(fs.readFileSync(LINKS_FILE, 'utf8'));

    if (!data.programs || !Array.isArray(data.programs)) {
        console.error('❌ Invalid file format: missing programs array');
        process.exit(1);
    }

    console.log(`Found ${data.programs.length} programs`);

    // Rebuild search index
    console.log('Rebuilding search index...');
    const searchIndex = buildSearchIndex(data.programs);

    // Update data
    data.searchIndex = searchIndex;
    data.searchIndexSize = Object.keys(searchIndex).length;
    data.programCount = data.programs.length;
    data.generated = new Date().toISOString();

    // Save back to file
    fs.writeFileSync(LINKS_FILE, JSON.stringify(data, null, 2), 'utf8');

    console.log(`✓ Generated ${data.searchIndexSize} searchable entries`);
    console.log(`✓ Saved to: ${LINKS_FILE}`);

    console.log('\nSample search index entries:');
    Object.entries(searchIndex).slice(0, 10).forEach(([key, value]) => {
        console.log(`  "${key}" → ${value.name} (${value.matchType})`);
    });

    console.log('\n✅ Search index rebuilt successfully!');
}

if (require.main === module) {
    main();
}

module.exports = { buildSearchIndex };
