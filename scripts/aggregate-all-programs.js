/**
 * Aggregate All Programs
 *
 * Combines all state-specific program files and uncategorized leftovers
 * into unified component files (programs-array.json and search-index.json).
 *
 * Architecture:
 * - State files (programs-AK.json, programs-AL.json, etc.) = Categorized programs
 * - tti-program-links.json = Uncategorized leftovers/inbox
 * - Component files = Aggregation of everything
 *
 * Usage: node scripts/aggregate-all-programs.js
 */

const fs = require('fs');
const path = require('path');

const REDDIT_WIKI_DIR = path.join(__dirname, '..', 'js', 'data', 'reddit-wiki');
const LEFTOVERS_FILE = path.join(__dirname, '..', 'js', 'data', 'tti-program-links.json');
const OUTPUT_DIR = REDDIT_WIKI_DIR;

/**
 * Build search index with name variations, acronyms, and aliases
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
 * Get all state program files
 */
function getStateFiles() {
    if (!fs.existsSync(REDDIT_WIKI_DIR)) {
        console.error(`❌ Directory not found: ${REDDIT_WIKI_DIR}`);
        process.exit(1);
    }

    const files = fs.readdirSync(REDDIT_WIKI_DIR);
    const stateFiles = files.filter(f =>
        f.startsWith('programs-') &&
        f.endsWith('.json') &&
        f !== 'programs-array.json'
    );

    return stateFiles.map(f => path.join(REDDIT_WIKI_DIR, f));
}

/**
 * Load programs from all state files
 */
function loadStatePrograms(stateFiles) {
    const allPrograms = [];
    const stateBreakdown = {};

    console.log(`\n📂 Loading programs from ${stateFiles.length} state files...\n`);

    stateFiles.forEach(filePath => {
        const fileName = path.basename(filePath);
        const state = fileName.replace('programs-', '').replace('.json', '');

        try {
            const data = JSON.parse(fs.readFileSync(filePath, 'utf8'));
            const programs = data.programs || [];

            console.log(`  ${state.padEnd(12)} - ${programs.length} programs`);

            programs.forEach(program => {
                allPrograms.push({
                    ...program,
                    state: program.state || state,
                    source: 'state-file'
                });
            });

            stateBreakdown[state] = programs.length;
        } catch (error) {
            console.error(`⚠ Error reading ${fileName}: ${error.message}`);
        }
    });

    return { programs: allPrograms, stateBreakdown };
}

/**
 * Load uncategorized leftover programs
 */
function loadLeftoverPrograms() {
    if (!fs.existsSync(LEFTOVERS_FILE)) {
        console.log('\n⚠ No leftovers file found, skipping...');
        return [];
    }

    try {
        const data = JSON.parse(fs.readFileSync(LEFTOVERS_FILE, 'utf8'));
        const programs = data.programs || [];

        console.log(`\n📥 Loading ${programs.length} uncategorized programs from leftovers...`);

        return programs.map(program => ({
            ...program,
            state: program.state || 'UNCATEGORIZED',
            source: 'leftovers'
        }));
    } catch (error) {
        console.error(`⚠ Error reading leftovers: ${error.message}`);
        return [];
    }
}

/**
 * Remove duplicates (prefer state files over leftovers)
 */
function deduplicatePrograms(programs) {
    const seen = new Map();
    const duplicates = [];

    programs.forEach(program => {
        const key = `${program.normalizedName}::${program.url}`;

        if (seen.has(key)) {
            const existing = seen.get(key);
            // Prefer state file over leftovers
            if (program.source === 'state-file' && existing.source === 'leftovers') {
                seen.set(key, program);
                duplicates.push({ removed: existing, kept: program });
            } else {
                duplicates.push({ removed: program, kept: existing });
            }
        } else {
            seen.set(key, program);
        }
    });

    if (duplicates.length > 0) {
        console.log(`\n⚠ Found ${duplicates.length} duplicates (kept state files, removed leftovers)`);
    }

    return Array.from(seen.values());
}

/**
 * Main execution
 */
function main() {
    console.log('═══════════════════════════════════════════════════════════');
    console.log('  Aggregating All Programs');
    console.log('═══════════════════════════════════════════════════════════');

    // Load state files
    const stateFiles = getStateFiles();
    const { programs: statePrograms, stateBreakdown } = loadStatePrograms(stateFiles);

    // Load leftovers
    const leftoverPrograms = loadLeftoverPrograms();

    // Combine and deduplicate
    const allPrograms = deduplicatePrograms([...statePrograms, ...leftoverPrograms]);

    console.log(`\n✓ Total programs after deduplication: ${allPrograms.length}`);
    console.log(`  - From state files: ${statePrograms.length}`);
    console.log(`  - From leftovers: ${leftoverPrograms.length}`);

    // Count uncategorized
    const uncategorized = allPrograms.filter(p => p.source === 'leftovers');
    if (uncategorized.length > 0) {
        console.log(`  - ⚠ Still uncategorized: ${uncategorized.length}`);
    }

    // Sort by name
    allPrograms.sort((a, b) => a.name.localeCompare(b.name));

    // Build search index
    console.log('\n🔍 Building search index...');
    const searchIndex = buildSearchIndex(allPrograms);
    console.log(`✓ Generated ${Object.keys(searchIndex).length} searchable entries`);

    // Write programs-array.json
    const programsFile = path.join(OUTPUT_DIR, 'programs-array.json');
    const programsData = {
        count: allPrograms.length,
        programs: allPrograms,
        generated: new Date().toISOString(),
        source: 'aggregated from state files and leftovers'
    };
    fs.writeFileSync(programsFile, JSON.stringify(programsData, null, 2), 'utf8');
    console.log(`\n✓ Saved: ${path.relative(process.cwd(), programsFile)}`);

    // Write search-index.json
    const searchFile = path.join(OUTPUT_DIR, 'search-index.json');
    const searchData = {
        count: Object.keys(searchIndex).length,
        searchIndex: searchIndex,
        generated: new Date().toISOString(),
        source: 'aggregated from state files and leftovers'
    };
    fs.writeFileSync(searchFile, JSON.stringify(searchData, null, 2), 'utf8');
    console.log(`✓ Saved: ${path.relative(process.cwd(), searchFile)}`);

    // Write metadata.json
    const metadataFile = path.join(OUTPUT_DIR, 'metadata.json');
    const metadata = {
        generated: new Date().toISOString(),
        programCount: allPrograms.length,
        searchIndexSize: Object.keys(searchIndex).length,
        stateFileCount: stateFiles.length,
        uncategorizedCount: uncategorized.length,
        stateBreakdown: stateBreakdown,
        components: {
            programsArray: 'programs-array.json',
            searchIndex: 'search-index.json',
            metadata: 'metadata.json',
            index: 'index.json'
        }
    };
    fs.writeFileSync(metadataFile, JSON.stringify(metadata, null, 2), 'utf8');
    console.log(`✓ Saved: ${path.relative(process.cwd(), metadataFile)}`);

    // Summary
    console.log('\n═══════════════════════════════════════════════════════════');
    console.log('  ✅ Aggregation Complete!');
    console.log('═══════════════════════════════════════════════════════════');
    console.log(`\n📊 Summary:`);
    console.log(`  Total programs: ${allPrograms.length}`);
    console.log(`  Search entries: ${Object.keys(searchIndex).length}`);
    console.log(`  State files: ${stateFiles.length}`);
    if (uncategorized.length > 0) {
        console.log(`  ⚠ Uncategorized: ${uncategorized.length} (in leftovers file)`);
    }

    console.log(`\n📂 Updated files:`);
    console.log(`  - programs-array.json`);
    console.log(`  - search-index.json`);
    console.log(`  - metadata.json`);

    if (uncategorized.length > 0) {
        console.log(`\n💡 Tip: Categorize remaining ${uncategorized.length} programs:`);
        console.log(`   1. Edit appropriate state file (programs-XX.json)`);
        console.log(`   2. Move program from leftovers to state file`);
        console.log(`   3. Run this script again`);
    }

    console.log('\n✨ Auto-linker will automatically use updated component files\n');
}

if (require.main === module) {
    main();
}

module.exports = { buildSearchIndex, deduplicatePrograms };
