const fs = require('fs');
const path = require('path');

const DEFAULT_OUTPUT_PATH = path.resolve(process.cwd(), 'location-projects-import.json');
const DEFAULT_BASE_URL = 'https://kidsoverprofits.org';

const STATE_ABBREVIATIONS = new Map([
    ['alabama', 'AL'],
    ['alaska', 'AK'],
    ['arizona', 'AZ'],
    ['arkansas', 'AR'],
    ['california', 'CA'],
    ['colorado', 'CO'],
    ['connecticut', 'CT'],
    ['delaware', 'DE'],
    ['florida', 'FL'],
    ['georgia', 'GA'],
    ['hawaii', 'HI'],
    ['idaho', 'ID'],
    ['illinois', 'IL'],
    ['indiana', 'IN'],
    ['iowa', 'IA'],
    ['kansas', 'KS'],
    ['kentucky', 'KY'],
    ['louisiana', 'LA'],
    ['maine', 'ME'],
    ['maryland', 'MD'],
    ['massachusetts', 'MA'],
    ['michigan', 'MI'],
    ['minnesota', 'MN'],
    ['mississippi', 'MS'],
    ['missouri', 'MO'],
    ['montana', 'MT'],
    ['nebraska', 'NE'],
    ['nevada', 'NV'],
    ['new hampshire', 'NH'],
    ['new jersey', 'NJ'],
    ['new mexico', 'NM'],
    ['new york', 'NY'],
    ['north carolina', 'NC'],
    ['north dakota', 'ND'],
    ['ohio', 'OH'],
    ['oklahoma', 'OK'],
    ['oregon', 'OR'],
    ['pennsylvania', 'PA'],
    ['rhode island', 'RI'],
    ['south carolina', 'SC'],
    ['south dakota', 'SD'],
    ['tennessee', 'TN'],
    ['texas', 'TX'],
    ['utah', 'UT'],
    ['vermont', 'VT'],
    ['virginia', 'VA'],
    ['washington', 'WA'],
    ['west virginia', 'WV'],
    ['wisconsin', 'WI'],
    ['wyoming', 'WY']
]);

const COMMON_HTML_ENTITIES = {
    amp: '&',
    apos: "'",
    nbsp: ' ',
    quot: '"',
    lt: '<',
    gt: '>',
    ndash: '-',
    mdash: '-',
    rsquo: "'",
    lsquo: "'",
    rdquo: '"',
    ldquo: '"',
    hellip: '...',
    middot: '·'
};

const RESOURCE_DEFAULTS = {
    hasNews: false,
    newsDetails: '',
    hasPressReleases: false,
    pressReleasesDetails: '',
    hasInspections: false,
    hasStateReports: false,
    hasRegulatoryFilings: false,
    hasLawsuits: false,
    hasPoliceReports: false,
    hasArticlesOfOrganization: false,
    hasPropertyRecords: false,
    hasPromotionalMaterials: false,
    hasEnrollmentDocuments: false,
    hasResearch: false,
    hasFinancial: false,
    hasStudent: false,
    hasStaff: false,
    hasVideo: false,
    hasAudio: false,
    hasSocialMedia: false,
    hasWebsite: false,
    hasNATSAP: false,
    hasOther: false,
    customResources: []
};

function printUsage() {
    console.log(`
Usage:
  node scripts/import-location-pages.js <url-or-file> [more urls/files]
  node scripts/import-location-pages.js --url-file urls.txt

Options:
  --output <file>    Write JSON output to this path
  --url-file <file>  Read page URLs from a text file (one per line)
    --all-states       Import all 50 state pages from the site
    --base-url <url>   Base site URL to use with --all-states
  --help             Show this help

Examples:
  node scripts/import-location-pages.js https://kidsoverprofits.org/alabama/
    node scripts/import-location-pages.js --all-states --output tmp/location-projects-import.json
  node scripts/import-location-pages.js --url-file state-pages.txt --output imports/location-pages.json

The output format matches the data form's multi-project JSON import structure.
`);
}

function parseArgs(argv) {
    const options = {
        output: DEFAULT_OUTPUT_PATH,
        urlFile: '',
        baseUrl: DEFAULT_BASE_URL,
        allStates: false,
        inputs: []
    };

    for (let index = 0; index < argv.length; index += 1) {
        const arg = argv[index];

        if (arg === '--help' || arg === '-h') {
            options.help = true;
            continue;
        }

        if (arg === '--output') {
            index += 1;
            options.output = argv[index] ? path.resolve(argv[index]) : DEFAULT_OUTPUT_PATH;
            continue;
        }

        if (arg === '--url-file') {
            index += 1;
            options.urlFile = argv[index] ? path.resolve(argv[index]) : '';
            continue;
        }

        if (arg === '--all-states') {
            options.allStates = true;
            continue;
        }

        if (arg === '--base-url') {
            index += 1;
            options.baseUrl = argv[index] || DEFAULT_BASE_URL;
            continue;
        }

        if (arg.startsWith('--')) {
            throw new Error(`Unknown option: ${arg}`);
        }

        options.inputs.push(arg);
    }

    return options;
}

function isUrl(value) {
    if (typeof value !== 'string') return false;

    try {
        const parsed = new URL(value);
        return parsed.protocol === 'http:' || parsed.protocol === 'https:';
    } catch (error) {
        return false;
    }
}

function normalizeWhitespace(value) {
    return String(value || '')
        .replace(/\u00a0/g, ' ')
        .replace(/[ \t]+/g, ' ')
        .trim();
}

function decodeHtmlEntities(value) {
    return String(value || '').replace(/&(#x?[0-9a-f]+|[a-z]+);/gi, (match, entity) => {
        if (!entity) return match;

        const lower = entity.toLowerCase();

        if (lower.startsWith('#x')) {
            const codePoint = Number.parseInt(lower.slice(2), 16);
            return Number.isFinite(codePoint) ? String.fromCodePoint(codePoint) : match;
        }

        if (lower.startsWith('#')) {
            const codePoint = Number.parseInt(lower.slice(1), 10);
            return Number.isFinite(codePoint) ? String.fromCodePoint(codePoint) : match;
        }

        return Object.prototype.hasOwnProperty.call(COMMON_HTML_ENTITIES, lower)
            ? COMMON_HTML_ENTITIES[lower]
            : match;
    });
}

function titleCaseWords(value) {
    return normalizeWhitespace(value)
        .split(/\s+/)
        .filter(Boolean)
        .map(word => {
            if (/^[A-Z]{2,}$/.test(word)) return word;
            return word.charAt(0).toUpperCase() + word.slice(1).toLowerCase();
        })
        .join(' ');
}

function slugToTitle(slug) {
    return titleCaseWords(String(slug || '').replace(/[-_]+/g, ' '));
}

function titleToSlug(value) {
    return normalizeWhitespace(value)
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-+|-+$/g, '');
}

function uniqueStrings(values) {
    const seen = new Set();
    const unique = [];

    values.forEach(value => {
        const normalized = normalizeWhitespace(value);
        const key = normalized.toLowerCase();
        if (!normalized || seen.has(key)) return;
        seen.add(key);
        unique.push(normalized);
    });

    return unique;
}

function createEmptyProject() {
    return {
        operator: {
            name: '',
            currentName: '',
            otherNames: [],
            location: '',
            locationCity: '',
            locationState: '',
            headquarters: '',
            headquartersCity: '',
            headquartersState: '',
            founded: '',
            operatingPeriod: '',
            status: '',
            parentCompanies: [],
            websites: [],
            investors: [],
            keyStaff: {
                ceo: '',
                founders: [],
                keyExecutives: []
            },
            notes: [],
            fieldNotes: {}
        },
        facilities: [],
        fieldNotes: {}
    };
}

function createEmptyFacility() {
    return {
        identification: {
            name: '',
            currentName: '',
            currentOperator: '',
            currentOwner: '',
            currentOwners: [],
            otherNames: [],
            pastNames: [],
            knownReferrers: []
        },
        locationDetails: {
            city: '',
            state: '',
            country: 'United States',
            additionalLocations: []
        },
        location: '',
        address: '',
        otherOperators: [],
        operatingPeriod: {
            startYear: null,
            endYear: null,
            status: '',
            yearsOfOperation: '',
            notes: []
        },
        staff: {
            administrator: [],
            notableStaff: [],
            pastTTIJobs: []
        },
        profileLinks: [],
        facilityDetails: {
            type: '',
            capacity: null,
            currentCensus: null,
            ageRange: {
                min: null,
                max: null
            },
            gender: ''
        },
        accreditations: {
            current: [],
            past: []
        },
        memberships: [],
        certifications: [],
        licensing: [],
        resources: { ...RESOURCE_DEFAULTS },
        notes: []
    };
}

function extractHtmlTitle(html) {
    const titleMatch = String(html || '').match(/<title[^>]*>([\s\S]*?)<\/title>/i);
    if (titleMatch) {
        const cleanTitle = normalizeWhitespace(decodeHtmlEntities(titleMatch[1]).replace(/\s*-\s*Kids Over Profits\s*$/i, ''));
        if (cleanTitle) return cleanTitle;
    }

    const h1Match = String(html || '').match(/<h1[^>]*>([\s\S]*?)<\/h1>/i);
    if (h1Match) {
        const cleanHeading = normalizeWhitespace(decodeHtmlEntities(h1Match[1]));
        if (cleanHeading) return cleanHeading;
    }

    return '';
}

function extractPrimaryHtml(html) {
    const source = String(html || '');
    const mainMatch = source.match(/<main\b[^>]*>([\s\S]*?)<\/main>/i);
    const work = mainMatch ? mainMatch[1] : source;

    const articleMatch = work.match(/<article\b[^>]*>([\s\S]*?)<\/article>/i);
    return articleMatch ? articleMatch[1] : work;
}

function htmlToText(html) {
    let work = extractPrimaryHtml(html);

    work = work
        .replace(/<script\b[^>]*>[\s\S]*?<\/script>/gi, '\n')
        .replace(/<style\b[^>]*>[\s\S]*?<\/style>/gi, '\n')
        .replace(/<noscript\b[^>]*>[\s\S]*?<\/noscript>/gi, '\n')
        .replace(/<svg\b[^>]*>[\s\S]*?<\/svg>/gi, '\n')
        .replace(/<header\b[^>]*>[\s\S]*?<\/header>/gi, '\n')
        .replace(/<footer\b[^>]*>[\s\S]*?<\/footer>/gi, '\n')
        .replace(/<nav\b[^>]*>[\s\S]*?<\/nav>/gi, '\n')
        .replace(/<br\s*\/?>/gi, '\n')
        .replace(/<\/(p|div|section|article|li|ul|ol|table|tbody|thead|tr|td|th|h[1-6])>/gi, '\n')
        .replace(/<(p|div|section|article|li|ul|ol|table|tbody|thead|tr|td|th|h[1-6])\b[^>]*>/gi, '\n')
        .replace(/<[^>]+>/g, ' ');

    return decodeHtmlEntities(work)
        .replace(/\r/g, '')
        .replace(/[ \t]+\n/g, '\n')
        .replace(/\n[ \t]+/g, '\n')
        .replace(/\n{3,}/g, '\n\n')
        .trim();
}

function splitBlocks(text) {
    return String(text || '')
        .split(/\n{2,}/)
        .map(block => block
            .split('\n')
            .map(line => normalizeWhitespace(line))
            .filter(Boolean)
            .join('\n'))
        .filter(Boolean);
}

function stripWrappingParentheses(value) {
    return normalizeWhitespace(String(value || '').replace(/^\(/, '').replace(/\)$/, ''));
}

function looksLikeAddress(line, stateAbbreviation) {
    const cleaned = normalizeWhitespace(line).replace(/[;.,]+$/, '');
    if (!cleaned) return false;

    const stateName = stateAbbreviation
        ? Array.from(STATE_ABBREVIATIONS.entries()).find(([, abbreviation]) => abbreviation === stateAbbreviation)?.[0] || ''
        : '';

    const statePattern = stateAbbreviation
        ? new RegExp(`\\b${stateAbbreviation}\\b(?:,?\\s+\\d{5}(?:-\\d{4})?)?$`, 'i')
        : /\b[A-Z]{2}\b(?:,?\s+\d{5}(?:-\d{4})?)?$/;

    const stateNamePattern = stateName
        ? new RegExp(`\\b${stateName.replace(/\s+/g, '\\s+')}\\b(?:,?\\s+\\d{5}(?:-\\d{4})?)?$`, 'i')
        : null;

    if (/^\d/.test(cleaned) && statePattern.test(cleaned)) return true;
    if (/^\d/.test(cleaned) && stateNamePattern && stateNamePattern.test(cleaned)) return true;
    if (/^[A-Za-z.'’\-/& ]+:\s*.+$/.test(cleaned) && statePattern.test(cleaned)) return true;
    if (/^[A-Za-z.'’\-/& ]+:\s*.+$/.test(cleaned) && stateNamePattern && stateNamePattern.test(cleaned)) return true;
    if (/^[A-Za-z.'’\-/& ]+,\s*[A-Z]{2}(?:,?\s+\d{5}(?:-\d{4})?)?$/i.test(cleaned)) return true;
    if (/,.+\b[A-Z]{2}\b(?:,?\s+\d{5}(?:-\d{4})?)?$/i.test(cleaned)) return true;
    if (statePattern.test(cleaned) && /,/.test(cleaned)) return true;
    if (stateNamePattern && /,/.test(cleaned) && stateNamePattern.test(cleaned)) return true;
    if (stateNamePattern && /^[A-Za-z.'’\-/& ]+,\s*[A-Za-z.'’\-/& ]+(?:,\s*)?$/.test(cleaned) && stateNamePattern.test(cleaned)) return true;

    return false;
}

function looksLikeMetadata(line, stateAbbreviation) {
    const cleaned = stripWrappingParentheses(line);
    if (!cleaned) return false;
    if (looksLikeAddress(cleaned, stateAbbreviation)) return false;

    return (
        /^fka\b/i.test(cleaned) ||
        /^aka\b/i.test(cleaned) ||
        /^a\.k\.a\./i.test(cleaned) ||
        /^formerly known as\b/i.test(cleaned) ||
        /^legal name:/i.test(cleaned) ||
        /^new legal name:/i.test(cleaned) ||
        /^est\.?\s*\d{4}/i.test(cleaned) ||
        /^\d{4}\s*-\s*(\d{4}|present)\b/i.test(cleaned) ||
        /^(closed|now adults only|adults only|inactive|transferred|open)\b/i.test(cleaned) ||
        /^\d{4}$/.test(cleaned) ||
        /^\(.+\)?$/.test(line)
    );
}

function isNoiseBlock(block, projectName) {
    const firstLine = normalizeWhitespace(block.split('\n')[0] || '');
    if (!firstLine) return true;

    const projectPattern = projectName ? projectName.toLowerCase() : '';
    const lower = firstLine.toLowerCase();

    return (
        lower === projectPattern ||
        lower === `home / ${projectPattern}` ||
        lower === 'toggle menu' ||
        lower === 'search' ||
        lower.startsWith('click here') ||
        lower.startsWith('residential facilities providing') ||
        lower.startsWith('this directory helps communities') ||
        lower.startsWith('essential information') ||
        lower.startsWith('below is a list') ||
        lower.startsWith('page last updated') ||
        lower.startsWith('last revised by') ||
        lower.startsWith('skip to content') ||
        lower.startsWith('kids over profits') ||
        lower.startsWith('for anti-tti') ||
        lower.startsWith('for family') ||
        lower.startsWith('for journalists') ||
        lower.startsWith('for survivors') ||
        lower.startsWith('support') ||
        lower.startsWith('history') ||
        lower.startsWith('research') ||
        lower.startsWith('law & policy') ||
        lower.startsWith('tti news feed') ||
        lower.startsWith('where are the kids') ||
        lower.startsWith('active programs') ||
        lower.startsWith('closed programs') ||
        lower.startsWith('copyright') ||
        lower.startsWith('©')
    );
}

function isLikelyEntryBlock(block, stateAbbreviation, projectName) {
    if (isNoiseBlock(block, projectName)) return false;

    const lines = block.split('\n').map(normalizeWhitespace).filter(Boolean);
    if (lines.length < 2) return false;

    const firstLine = lines[0];
    if (firstLine.length > 140 && /[.!?]/.test(firstLine)) return false;

    return lines.slice(1).some(line => looksLikeAddress(line, stateAbbreviation));
}

function blockHasAddress(block, stateAbbreviation) {
    return String(block || '')
        .split('\n')
        .map(normalizeWhitespace)
        .filter(Boolean)
        .some(line => looksLikeAddress(line, stateAbbreviation));
}

function blockHasMetadata(block, stateAbbreviation) {
    return String(block || '')
        .split('\n')
        .map(normalizeWhitespace)
        .filter(Boolean)
        .some(line => looksLikeMetadata(line, stateAbbreviation));
}

function isIgnorableNonAddressLine(line) {
    const cleaned = normalizeWhitespace(line);
    if (!cleaned) return true;

    return (
        /^facility profile\b/i.test(cleaned) ||
        /^facility profile here\.?$/i.test(cleaned) ||
        /^[\p{Extended_Pictographic}\p{Emoji_Presentation}\p{Symbol}\s]+$/u.test(cleaned)
    );
}

function extractLocationSegmentsFromLine(line, stateAbbreviation) {
    const segments = String(line || '')
        .split(';')
        .map(segment => normalizeWhitespace(segment))
        .filter(Boolean);

    if (!segments.length) return [];

    const addressLikeSegments = segments.filter(segment => looksLikeAddress(segment, stateAbbreviation));
    if (!addressLikeSegments.length) {
        return [];
    }

    if (segments.length === 1) {
        return addressLikeSegments;
    }

    return segments
        .filter(segment => !isIgnorableNonAddressLine(segment))
        .map(segment => {
            if (!stateAbbreviation) {
                return segment;
            }

            if (looksLikeAddress(segment, stateAbbreviation) || /\d/.test(segment) || /,/.test(segment)) {
                return segment;
            }

            return `${segment}, ${stateAbbreviation}`;
        });
}

function blockLooksLikeStandaloneName(block, stateAbbreviation, projectName) {
    if (isNoiseBlock(block, projectName)) return false;

    const lines = String(block || '').split('\n').map(normalizeWhitespace).filter(Boolean);
    if (!lines.length) return false;
    if (blockHasAddress(block, stateAbbreviation)) return false;
    if (lines.length > 2) return false;

    const firstLine = lines[0];
    if (firstLine.length > 160 && /[.!?]/.test(firstLine)) return false;

    return true;
}

function buildEntryBlocks(blocks, stateAbbreviation, projectName) {
    const entryBlocks = [];

    for (let index = 0; index < blocks.length; index += 1) {
        const block = blocks[index];

        if (isLikelyEntryBlock(block, stateAbbreviation, projectName)) {
            entryBlocks.push(block);
            continue;
        }

        if (!blockLooksLikeStandaloneName(block, stateAbbreviation, projectName)) {
            continue;
        }

        const combinedBlocks = [block];
        let lookahead = index + 1;

        while (lookahead < blocks.length) {
            const nextBlock = blocks[lookahead];

            if (isNoiseBlock(nextBlock, projectName)) {
                lookahead += 1;
                continue;
            }

            if (isLikelyEntryBlock(nextBlock, stateAbbreviation, projectName)) {
                break;
            }

            if (blockLooksLikeStandaloneName(nextBlock, stateAbbreviation, projectName) && !blockHasMetadata(nextBlock, stateAbbreviation)) {
                break;
            }

            if (blockHasAddress(nextBlock, stateAbbreviation) || blockHasMetadata(nextBlock, stateAbbreviation)) {
                combinedBlocks.push(nextBlock);
                lookahead += 1;
                continue;
            }

            break;
        }

        const combinedBlock = combinedBlocks.join('\n');
        if (isLikelyEntryBlock(combinedBlock, stateAbbreviation, projectName)) {
            entryBlocks.push(combinedBlock);
            index = lookahead - 1;
        }
    }

    return entryBlocks;
}

function extractParentheticalGroups(text) {
    const groups = [];
    let working = String(text || '');

    working = working.replace(/\(([^()]*)\)/g, (match, inner) => {
        const cleaned = normalizeWhitespace(inner);
        if (cleaned) groups.push(cleaned);
        return '';
    });

    const danglingIndex = working.indexOf('(');
    if (danglingIndex !== -1) {
        const dangling = normalizeWhitespace(working.slice(danglingIndex + 1));
        if (dangling) groups.push(dangling);
        working = working.slice(0, danglingIndex);
    }

    return {
        baseName: normalizeWhitespace(working).replace(/[-–—\s]+$/, ''),
        groups
    };
}

function splitNameList(value) {
    return uniqueStrings(
        normalizeWhitespace(value)
            .split(/\s*;\s*|\s*\/\s*|\s*,\s*/)
            .map(item => item.replace(/^["'“”‘’]+|["'“”‘’]+$/g, ''))
    );
}

function assignCurrentOperator(facility, value) {
    const cleaned = normalizeWhitespace(value);
    if (!cleaned) return;

    if (!facility.identification.currentOperator) {
        facility.identification.currentOperator = cleaned;
    }

    if (!facility.identification.currentOwner) {
        facility.identification.currentOwner = cleaned;
    }

    if (!facility.identification.currentOwners.some(owner => owner.toLowerCase() === cleaned.toLowerCase())) {
        facility.identification.currentOwners.push(cleaned);
    }
}

function setStatus(facility, value) {
    const cleaned = normalizeWhitespace(value);
    if (!cleaned) return;
    facility.operatingPeriod.status = cleaned;
}

function applyMetadataToFacility(facility, metadataItems) {
    metadataItems.forEach(item => {
        const cleaned = stripWrappingParentheses(item);
        if (!cleaned) return;

        let match = cleaned.match(/^fka\b[:\s-]*(.+)$/i);
        if (match) {
            facility.identification.pastNames = uniqueStrings([
                ...facility.identification.pastNames,
                ...splitNameList(match[1])
            ]);
            return;
        }

        match = cleaned.match(/^(?:aka|a\.k\.a\.|also known as)\b[:\s-]*(.+)$/i);
        if (match) {
            facility.identification.otherNames = uniqueStrings([
                ...facility.identification.otherNames,
                ...splitNameList(match[1])
            ]);
            return;
        }

        match = cleaned.match(/^formerly known as\b[:\s-]*(.+)$/i);
        if (match) {
            facility.identification.pastNames = uniqueStrings([
                ...facility.identification.pastNames,
                ...splitNameList(match[1])
            ]);
            return;
        }

        match = cleaned.match(/^legal name:\s*(.+)$/i);
        if (match) {
            assignCurrentOperator(facility, match[1]);
            return;
        }

        match = cleaned.match(/^est\.?\s*(\d{4})$/i);
        if (match) {
            facility.operatingPeriod.startYear = match[1];
            return;
        }

        match = cleaned.match(/^(\d{4})\s*-\s*(\d{4}|present)$/i);
        if (match) {
            facility.operatingPeriod.startYear = match[1];
            facility.operatingPeriod.endYear = /^present$/i.test(match[2]) ? null : match[2];
            facility.operatingPeriod.yearsOfOperation = `${match[1]}-${/^present$/i.test(match[2]) ? 'Present' : match[2]}`;
            if (!facility.operatingPeriod.status) {
                setStatus(facility, /^present$/i.test(match[2]) ? 'Open' : 'Closed');
            }
            return;
        }

        if (/^closed$/i.test(cleaned)) {
            setStatus(facility, 'Closed');
            return;
        }

        if (/^now adults only$/i.test(cleaned) || /^adults only$/i.test(cleaned)) {
            setStatus(facility, 'Adults Only');
            facility.operatingPeriod.notes = uniqueStrings([
                ...facility.operatingPeriod.notes,
                cleaned
            ]);
            return;
        }

        if (/^inactive$/i.test(cleaned)) {
            setStatus(facility, 'Inactive');
            return;
        }

        if (/^transferred$/i.test(cleaned)) {
            setStatus(facility, 'Transferred');
            return;
        }

        if (/^open$/i.test(cleaned)) {
            setStatus(facility, 'Open');
            return;
        }

        if (/^\d{4}$/.test(cleaned) && !facility.operatingPeriod.startYear) {
            facility.operatingPeriod.startYear = cleaned;
            return;
        }

        assignCurrentOperator(facility, cleaned);
    });
}

function extractLocationParts(address, fallbackStateAbbreviation) {
    const cleaned = normalizeWhitespace(address)
        .replace(/\s*\([^()]*\)\s*$/g, '')
        .replace(/[;.,]+$/, '');
    const match = cleaned.match(/(?:,\s*|^)([A-Za-z.'’\- ]+),?\s+([A-Z]{2})(?:\s+\d{5}(?:-\d{4})?)?$/);
    const fallbackStateName = fallbackStateAbbreviation
        ? Array.from(STATE_ABBREVIATIONS.entries()).find(([, abbreviation]) => abbreviation === fallbackStateAbbreviation)?.[0] || ''
        : '';
    const stateNameMatch = fallbackStateName
        ? cleaned.match(new RegExp(`(?:,\\s*|^)([A-Za-z.'’\\- ]+),?\\s+(${fallbackStateName.replace(/\s+/g, '\\s+')})(?:,?\\s+\\d{5}(?:-\\d{4})?)?$`, 'i'))
        : null;

    if (match) {
        const city = normalizeWhitespace(match[1].replace(/^.*:\s*/, ''));
        const state = normalizeWhitespace(match[2]);
        return {
            city,
            state,
            location: city ? `${city}, ${state}` : state
        };
    }

    if (stateNameMatch) {
        const city = normalizeWhitespace(stateNameMatch[1].replace(/^.*:\s*/, ''));
        return {
            city,
            state: fallbackStateAbbreviation,
            location: city && fallbackStateAbbreviation ? `${city}, ${fallbackStateAbbreviation}` : fallbackStateAbbreviation
        };
    }

    if (fallbackStateAbbreviation) {
        return {
            city: '',
            state: fallbackStateAbbreviation,
            location: fallbackStateAbbreviation
        };
    }

    return {
        city: '',
        state: '',
        location: ''
    };
}

function parseEntryBlock(block, projectName, stateAbbreviation) {
    const lines = block.split('\n').map(normalizeWhitespace).filter(Boolean);
    if (!lines.length) return null;

    const facility = createEmptyFacility();
    const metadataItems = [];
    const addressSegments = [];

    let nameLine = lines[0];
    const dbaMatch = nameLine.match(/^(.+?)\s+\b(?:d\/b\/a|dba|doing business as)\b\s+(.+)$/i);
    if (dbaMatch) {
        assignCurrentOperator(facility, dbaMatch[1]);
        nameLine = dbaMatch[2];
    }

    const nameParts = extractParentheticalGroups(nameLine);
    facility.identification.name = nameParts.baseName || nameLine;
    metadataItems.push(...nameParts.groups);

    for (const line of lines.slice(1)) {
        if (looksLikeMetadata(line, stateAbbreviation)) {
            metadataItems.push(stripWrappingParentheses(line));
            continue;
        }

        const locationSegments = extractLocationSegmentsFromLine(line, stateAbbreviation);
        if (locationSegments.length) {
            addressSegments.push(...locationSegments);
            continue;
        }

        if (addressSegments.length) {
            break;
        }

        if (isIgnorableNonAddressLine(line)) {
            continue;
        }
    }

    if (!facility.identification.name || !addressSegments.length) {
        return null;
    }

    applyMetadataToFacility(facility, metadataItems);

    const [firstAddress, ...remainingAddresses] = addressSegments;
    const firstLocation = extractLocationParts(firstAddress, stateAbbreviation);

    facility.address = firstAddress;
    facility.location = firstLocation.location;
    facility.locationDetails.city = firstLocation.city;
    facility.locationDetails.state = firstLocation.state || stateAbbreviation || '';

    facility.locationDetails.additionalLocations = remainingAddresses.map(address => {
        const derivedLocation = extractLocationParts(address, stateAbbreviation);
        return {
            city: derivedLocation.city,
            address
        };
    });

    if (!facility.operatingPeriod.status) {
        setStatus(facility, 'Open');
    }

    facility.identification.otherNames = uniqueStrings(facility.identification.otherNames);
    facility.identification.pastNames = uniqueStrings(facility.identification.pastNames);
    facility.operatingPeriod.notes = uniqueStrings(facility.operatingPeriod.notes);

    return facility;
}

function inferProjectName({ source, title, text }) {
    if (isUrl(source)) {
        const parsed = new URL(source);
        const slug = parsed.pathname.replace(/\/+$/, '').split('/').filter(Boolean).pop() || '';
        if (slug) {
            const slugTitle = slugToTitle(slug);
            if (STATE_ABBREVIATIONS.has(slugTitle.toLowerCase())) {
                return slugTitle;
            }
        }
    }

    if (title) {
        const normalizedTitle = normalizeWhitespace(title);
        if (STATE_ABBREVIATIONS.has(normalizedTitle.toLowerCase())) {
            return normalizedTitle;
        }
        return title;
    }

    if (isUrl(source)) {
        const parsed = new URL(source);
        const slug = parsed.pathname.replace(/\/+$/, '').split('/').filter(Boolean).pop() || '';
        if (slug) return slugToTitle(slug);
    }

    if (source) {
        const basename = path.basename(source, path.extname(source));
        if (basename) return slugToTitle(basename);
    }

    const firstTextLine = String(text || '').split('\n').map(normalizeWhitespace).find(Boolean);
    return firstTextLine || 'Imported Location Page';
}

function parseLocationPage(sourceContent, { source = '', title = '' } = {}) {
    const rawContent = String(sourceContent || '');
    const detectedTitle = title || extractHtmlTitle(rawContent);
    const text = /<[^>]+>/.test(rawContent) ? htmlToText(rawContent) : rawContent;
    const projectName = inferProjectName({ source, title: detectedTitle, text });
    const stateAbbreviation = STATE_ABBREVIATIONS.get(projectName.toLowerCase()) || '';

    const entryBlocks = buildEntryBlocks(splitBlocks(text), stateAbbreviation, projectName);
    if (!entryBlocks.length) {
        throw new Error(`No facility entries found for "${projectName}"`);
    }

    const facilities = entryBlocks
        .map(block => parseEntryBlock(block, projectName, stateAbbreviation))
        .filter(Boolean)
        .sort((left, right) => left.identification.name.localeCompare(right.identification.name, undefined, {
            numeric: true,
            sensitivity: 'base'
        }));

    const projectData = createEmptyProject();
    projectData.facilities = facilities;

    if (stateAbbreviation) {
        projectData.operator.locationState = stateAbbreviation;
    }

    return {
        projectName,
        facilityCount: facilities.length,
        project: {
            name: projectName,
            category: 'locations',
            currentFacilityIndex: 0,
            timestamp: new Date().toISOString(),
            data: projectData
        }
    };
}

async function loadSource(input) {
    if (isUrl(input)) {
        const response = await fetch(input);
        if (!response.ok) {
            throw new Error(`Failed to fetch ${input}: HTTP ${response.status}`);
        }

        return {
            source: input,
            content: await response.text()
        };
    }

    const resolvedPath = path.resolve(input);
    if (!fs.existsSync(resolvedPath)) {
        throw new Error(`Input not found: ${resolvedPath}`);
    }

    return {
        source: resolvedPath,
        content: fs.readFileSync(resolvedPath, 'utf8')
    };
}

function readUrlFile(filePath) {
    if (!filePath || !fs.existsSync(filePath)) {
        throw new Error(`URL file not found: ${filePath}`);
    }

    return fs.readFileSync(filePath, 'utf8')
        .split(/\r?\n/)
        .map(line => normalizeWhitespace(line))
        .filter(line => line && !line.startsWith('#'));
}

function getAllStateUrls(baseUrl) {
    const normalizedBaseUrl = String(baseUrl || DEFAULT_BASE_URL).replace(/\/+$/, '');

    return Array.from(STATE_ABBREVIATIONS.keys()).map(stateName => (
        `${normalizedBaseUrl}/${titleToSlug(stateName)}/`
    ));
}

async function runImport(options) {
    const inputList = uniqueStrings([
        ...(options.allStates ? getAllStateUrls(options.baseUrl) : []),
        ...options.inputs,
        ...(options.urlFile ? readUrlFile(options.urlFile) : [])
    ]);

    if (!inputList.length) {
        throw new Error('No input URLs or files provided.');
    }

    const projects = {};

    for (const input of inputList) {
        const loaded = await loadSource(input);
        const parsed = parseLocationPage(loaded.content, { source: loaded.source });

        projects[parsed.projectName] = parsed.project;
        console.log(`[OK] ${parsed.projectName}: ${parsed.facilityCount} facilities`);
    }

    const payload = {
        exportedAt: new Date().toISOString(),
        sourceType: 'location-pages',
        projectCount: Object.keys(projects).length,
        projects
    };

    fs.mkdirSync(path.dirname(options.output), { recursive: true });
    fs.writeFileSync(options.output, JSON.stringify(payload, null, 2));

    console.log(`\nWrote ${payload.projectCount} project(s) to ${options.output}`);
}

if (require.main === module) {
    (async () => {
        try {
            const options = parseArgs(process.argv.slice(2));

            if (options.help) {
                printUsage();
                return;
            }

            await runImport(options);
        } catch (error) {
            console.error(`Error: ${error.message}`);
            printUsage();
            process.exitCode = 1;
        }
    })();
}

module.exports = {
    htmlToText,
    splitBlocks,
    buildEntryBlocks,
    parseEntryBlock,
    parseLocationPage,
    applyMetadataToFacility,
    extractParentheticalGroups,
    extractLocationParts,
    getAllStateUrls
};
