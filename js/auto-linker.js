/**
 * TTI Program Auto-Linker
 *
 * Automatically creates markdown links for TTI program mentions in text.
 * Uses the tti-program-links.json database to find and link program names.
 */

class TTIProgramAutoLinker {
    constructor() {
        this.programLinks = null;
        this.searchIndex = null;
        this.loaded = false;
    }

    /**
     * Load the program links database
     * Tries component files first (reddit-wiki/), then falls back to monolithic file
     */
    async loadDatabase() {
        // Try loading from component files first (preferred method)
        const componentSuccess = await this.loadFromComponents();
        if (componentSuccess) {
            console.log(`✓ Loaded ${this.programLinks.length} TTI programs for auto-linking (from components)`);
            return true;
        }

        // Fall back to monolithic file
        console.log('⚠ Component files not found, falling back to monolithic file...');
        return await this.loadFromMonolithic();
    }

    /**
     * Load from component files (programs-array.json + search-index.json)
     */
    async loadFromComponents() {
        try {
                        const settings = window.autoLinkerSettings || {};
            const basePath = settings.basePath || '/wp-content/themes/child/js/data/reddit-wiki';

            // Load programs array
            const programsResponse = await fetch(`${basePath}/programs-array.json`);
            if (!programsResponse.ok) return false;

            const programsData = await programsResponse.json();

            // Load search index
            const searchResponse = await fetch(`${basePath}/search-index.json`);
            if (!searchResponse.ok) return false;

            const searchData = await searchResponse.json();

            this.programLinks = programsData.programs || [];
            this.searchIndex = searchData.searchIndex || {};
            this.loaded = true;

            return true;
        } catch (error) {
            console.warn('Failed to load component files:', error.message);
            return false;
        }
    }

    /**
     * Load from monolithic file (fallback)
     */
    async loadFromMonolithic() {
        try {
            const settings = window.autoLinkerSettings || {};
            const basePath = settings.basePath || '/wp-content/themes/child/js/data/reddit-wiki';
            const monoPath = basePath.replace(/\/reddit-wiki\/?$/, '/tti-program-links.json');
            const response = await fetch(monoPath);
            if (!response.ok) {
                console.warn('TTI program links database not found. Auto-linking disabled.');
                return false;
            }

            const data = await response.json();
            this.programLinks = data.programs || [];
            this.searchIndex = data.searchIndex || {};
            this.loaded = true;

            console.log(`✓ Loaded ${this.programLinks.length} TTI programs for auto-linking (from monolithic)`);
            return true;
        } catch (error) {
            console.error('Failed to load TTI program links:', error);
            return false;
        }
    }

    /**
     * Load specific state files on demand
     * Useful for lazy loading or state-specific features
     *
     * @param {string[]} states - Array of state codes (e.g., ['UT', 'CA', 'TX'])
     * @returns {Promise<object[]>} Array of programs from specified states
     */
    async loadStateFiles(states) {
                    const settings = window.autoLinkerSettings || {};
            const basePath = settings.basePath || '/wp-content/themes/child/js/data/reddit-wiki';
        const allPrograms = [];

        for (const state of states) {
            try {
                const response = await fetch(`${basePath}/programs-${state}.json`);
                if (!response.ok) {
                    console.warn(`State file not found: programs-${state}.json`);
                    continue;
                }

                const data = await response.json();
                allPrograms.push(...(data.programs || []));
            } catch (error) {
                console.warn(`Failed to load state ${state}:`, error.message);
            }
        }

        return allPrograms;
    }

    /**
     * Get available states (from index file)
     * @returns {Promise<object>} State information with program counts
     */
    async getAvailableStates() {
        try {
                        const settings = window.autoLinkerSettings || {};
            const basePath = settings.basePath || '/wp-content/themes/child/js/data/reddit-wiki';
            const response = await fetch(`${basePath}/index.json`);
            if (!response.ok) return null;

            const data = await response.json();
            return data.states || {};
        } catch (error) {
            console.warn('Failed to load state index:', error.message);
            return null;
        }
    }

    /**
     * Auto-link program mentions in text
     *
     * @param {string} text - The text to process
     * @param {object} options - Options for auto-linking
     * @returns {string} Text with program names linked
     */
    autoLink(text, options = {}) {
        if (!this.loaded || !text) {
            return text;
        }

        const {
            skipAlreadyLinked = true,      // Don't re-link text already in [...](...) or URLs
            minWordLength = 3,              // Minimum word length to consider
            caseSensitive = false,          // Match case
            linkCurrentProgram = false,     // Whether to link mentions of the current program
            currentProgramName = null       // Name of current program being edited
        } = options;

        // Create a copy to work with
        let result = text;

        // Protect existing markdown links and URLs by replacing them with placeholders
        const protectedSegments = [];
        let segmentIndex = 0;

        // Protect markdown links [text](url)
        result = result.replace(/\[([^\]]+)\]\(([^)]+)\)/g, (match) => {
            const placeholder = `__PROTECTED_LINK_${segmentIndex}__`;
            protectedSegments.push({ placeholder, original: match });
            segmentIndex++;
            return placeholder;
        });

        // Protect standalone URLs
        result = result.replace(/https?:\/\/[^\s\)]+/g, (match) => {
            const placeholder = `__PROTECTED_URL_${segmentIndex}__`;
            protectedSegments.push({ placeholder, original: match });
            segmentIndex++;
            return placeholder;
        });

        // Protect wiki-style links [[text]]
        result = result.replace(/\[\[([^\]]+)\]\]/g, (match) => {
            const placeholder = `__PROTECTED_WIKILINK_${segmentIndex}__`;
            protectedSegments.push({ placeholder, original: match });
            segmentIndex++;
            return placeholder;
        });

        // Build a list of programs to search for, sorted by length (longest first)
        // This prevents partial matches (e.g., "Academy" matching before "Wilderness Academy")
        const programsToLink = Object.entries(this.searchIndex)
            .map(([key, value]) => ({
                searchTerm: key,
                name: value.name,
                url: value.url,
                matchType: value.matchType
            }))
            // Filter out current program if requested
            .filter(p => linkCurrentProgram || !currentProgramName ||
                p.name.toLowerCase() !== currentProgramName.toLowerCase())
            // Sort by length (longest first)
            .sort((a, b) => b.searchTerm.length - a.searchTerm.length);

        // Track which positions have been linked to avoid overlapping links
        const linkedRanges = [];

        // Function to check if a range overlaps with existing links
        const overlapsExisting = (start, end) => {
            return linkedRanges.some(range =>
                (start >= range.start && start < range.end) ||
                (end > range.start && end <= range.end) ||
                (start <= range.start && end >= range.end)
            );
        };

        // Process each program name
        programsToLink.forEach(program => {
            const searchTerm = program.searchTerm;
            if (searchTerm.length < minWordLength) return;

            // Create regex for finding this term
            // Use word boundaries to avoid partial matches within words
            const flags = caseSensitive ? 'g' : 'gi';
            const escapedTerm = searchTerm.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
            const pattern = new RegExp(`\\b${escapedTerm}\\b`, flags);

            let match;
            const tempResult = result;
            let offset = 0;

            while ((match = pattern.exec(tempResult)) !== null) {
                const matchStart = match.index + offset;
                const matchEnd = matchStart + match[0].length;

                // Skip if this position overlaps with an existing link
                if (overlapsExisting(matchStart, matchEnd)) {
                    continue;
                }

                // Check if match is inside a protected segment (already a link)
                let isProtected = false;
                for (const segment of protectedSegments) {
                    const segmentPos = result.indexOf(segment.placeholder);
                    if (segmentPos !== -1 &&
                        matchStart >= segmentPos &&
                        matchEnd <= segmentPos + segment.placeholder.length) {
                        isProtected = true;
                        break;
                    }
                }

                if (isProtected) continue;

                // Create the markdown link
                const matchedText = result.substring(matchStart, matchEnd);
                const markdownLink = `[${matchedText}](${program.url})`;

                // Replace in result
                result = result.substring(0, matchStart) +
                        markdownLink +
                        result.substring(matchEnd);

                // Track this linked range (adjusted for the new link length)
                linkedRanges.push({
                    start: matchStart,
                    end: matchStart + markdownLink.length
                });

                // Adjust offset for the length change
                offset += markdownLink.length - matchedText.length;

                // Update pattern to continue from new position
                pattern.lastIndex = 0; // Reset regex
                break; // Restart search with modified string
            }
        });

        // Restore protected segments
        protectedSegments.forEach(({ placeholder, original }) => {
            result = result.replace(placeholder, original);
        });

        return result;
    }

    /**
     * Auto-link all sections of a wiki entry object
     *
     * @param {object} sections - Object with section names as keys and text as values
     * @param {string} currentProgramName - Name of the current program
     * @returns {object} Sections with auto-linked text
     */
    autoLinkSections(sections, currentProgramName = null) {
        if (!this.loaded) {
            console.warn('Auto-linker not loaded. Skipping auto-linking.');
            return sections;
        }

        const linkedSections = {};

        Object.entries(sections).forEach(([key, value]) => {
            if (typeof value === 'string') {
                linkedSections[key] = this.autoLink(value, {
                    currentProgramName: currentProgramName
                });
            } else {
                linkedSections[key] = value;
            }
        });

        return linkedSections;
    }

    /**
     * Get program info by name (for manual linking UI)
     */
    findProgram(name) {
        if (!this.loaded) return null;

        const key = name.toLowerCase().trim();
        return this.searchIndex[key] || null;
    }

    /**
     * Get all programs (for autocomplete/search UI)
     */
    getAllPrograms() {
        return this.programLinks || [];
    }

    /**
     * Search programs by partial name
     */
    searchPrograms(query) {
        if (!this.loaded || !query) return [];

        const lowerQuery = query.toLowerCase();
        return this.programLinks.filter(p =>
            p.name.toLowerCase().includes(lowerQuery) ||
            p.normalizedName.includes(lowerQuery)
        );
    }
}

// Create singleton instance
const autoLinker = new TTIProgramAutoLinker();

// Auto-load on page load if in browser
if (typeof window !== 'undefined' && typeof document !== 'undefined') {
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => autoLinker.loadDatabase());
    } else {
        autoLinker.loadDatabase();
    }
}

// Export for use in other modules
if (typeof module !== 'undefined' && module.exports) {
    module.exports = TTIProgramAutoLinker;
} else if (typeof window !== 'undefined') {
    window.TTIProgramAutoLinker = TTIProgramAutoLinker;
    window.ttiAutoLinker = autoLinker;
}
