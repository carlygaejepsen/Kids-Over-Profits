/**
 * Reddit Wiki Link Extractor - Browser Console Script
 *
 * INSTRUCTIONS:
 * 1. Go to a Reddit wiki page (e.g., https://www.reddit.com/r/troubledteens/wiki/index/active-programs/)
 * 2. Open browser console (F12 → Console tab)
 * 3. Paste this entire script and press Enter
 * 4. The script will extract all program links from the page
 * 5. Copy the JSON output
 * 6. Repeat for each wiki page (state pages, operator pages, etc.)
 * 7. Combine all results into your tti-program-links.json file
 */

(function() {
    console.log('=== Reddit Wiki Link Extractor ===\n');

    // Get the current page's markdown content
    let markdown = '';

    // Try to get markdown from Reddit's source view
    const sourceTextarea = document.querySelector('textarea[name="content"]');
    if (sourceTextarea) {
        markdown = sourceTextarea.value;
        console.log('✓ Found markdown in editor (you\'re in edit mode)');
    } else {
        // Get rendered content and extract links
        const contentDiv = document.querySelector('.wiki-page-content, .md, [data-test-id="wiki-content"]');
        if (contentDiv) {
            // Extract all links from the rendered content
            const links = contentDiv.querySelectorAll('a[href*="/wiki/"]');
            console.log(`✓ Found ${links.length} wiki links in rendered content`);

            const programs = [];
            const seen = new Set();

            links.forEach(link => {
                const href = link.getAttribute('href');
                const text = link.textContent.trim();

                // Skip if not a program link
                if (!href || !text) return;
                if (href.includes('/edit') || href.includes('/revisions')) return;
                if (text.match(/^(index|home|wiki|back|return|top|main)/i)) return;

                // Normalize URL
                let url = href;
                if (!url.startsWith('http')) {
                    if (url.startsWith('/r/troubledteens')) {
                        url = url; // Keep as-is
                    } else if (url.startsWith('/')) {
                        url = url; // Keep as-is
                    } else {
                        url = `/r/troubledteens/wiki/${url}`;
                    }
                }

                // Ensure trailing slash
                if (!url.endsWith('/')) url += '/';

                // Skip duplicates
                const key = text.toLowerCase();
                if (seen.has(key)) return;
                seen.add(key);

                programs.push({
                    name: text,
                    url: url,
                    normalizedName: key
                });
            });

            if (programs.length === 0) {
                console.warn('⚠ No program links found. Make sure you\'re on a wiki index page.');
                return;
            }

            console.log(`\n✓ Extracted ${programs.length} programs:\n`);
            programs.slice(0, 5).forEach(p => {
                console.log(`  - ${p.name} → ${p.url}`);
            });
            if (programs.length > 5) {
                console.log(`  ... and ${programs.length - 5} more`);
            }

            console.log('\n📋 Copy this JSON and add to your programs array:\n');
            console.log(JSON.stringify(programs, null, 2));

            // Also copy to clipboard if available
            if (navigator.clipboard) {
                const json = JSON.stringify(programs, null, 2);
                navigator.clipboard.writeText(json).then(() => {
                    console.log('\n✅ JSON copied to clipboard!');
                }).catch(err => {
                    console.log('\n⚠ Could not copy to clipboard automatically');
                });
            }

            return programs;
        }
    }

    if (!markdown && !contentDiv) {
        console.error('❌ Could not find wiki content on this page.');
        console.log('Make sure you\'re on a Reddit wiki page like:');
        console.log('  - /r/troubledteens/wiki/index/active-programs/');
        console.log('  - /r/troubledteens/wiki/index/utah/');
        console.log('  - etc.');
        return;
    }

    if (markdown) {
        // Parse markdown
        const programs = [];
        const seen = new Set();
        const linkRegex = /\[([^\]]+)\]\(([^)]+)\)/g;
        let match;

        while ((match = linkRegex.exec(markdown)) !== null) {
            let name = match[1].trim();
            let url = match[2].trim();

            // Skip non-program links
            if (url.startsWith('http') && !url.includes('reddit.com/r/troubledteens/wiki')) continue;
            if (url.startsWith('#')) continue;
            if (name.match(/^(index|home|wiki|back|return|top|main)/i)) continue;

            // Normalize URL
            if (!url.startsWith('/r/troubledteens/wiki/')) {
                if (url.startsWith('/')) {
                    // Keep as-is
                } else {
                    url = `/r/troubledteens/wiki/index/${url}`;
                }
            }

            if (!url.endsWith('/')) url += '/';

            // Skip duplicates
            const key = name.toLowerCase();
            if (seen.has(key)) continue;
            seen.add(key);

            programs.push({
                name: name,
                url: url,
                normalizedName: key
            });
        }

        console.log(`✓ Extracted ${programs.length} programs from markdown\n`);
        programs.slice(0, 5).forEach(p => {
            console.log(`  - ${p.name} → ${p.url}`);
        });
        if (programs.length > 5) {
            console.log(`  ... and ${programs.length - 5} more`);
        }

        console.log('\n📋 Copy this JSON:\n');
        console.log(JSON.stringify(programs, null, 2));

        return programs;
    }
})();
