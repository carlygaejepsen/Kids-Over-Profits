// Facilities Database Display - Drop-in JavaScript
// Usage: Include this file and call displayFacilities(jsonData, containerId)

(function() {

const getRestBase = () => {
    const configured = (window.ttiIndexConfig && typeof window.ttiIndexConfig.restUrl === 'string')
        ? window.ttiIndexConfig.restUrl.trim()
        : '';
    if (configured) return configured.endsWith('/') ? configured : configured + '/';
    return '/wp-json/kop/v1/';
};

const escapeHtmlValue = value => {
    if (value === null || value === undefined) return '';
    const text = typeof value === 'string' ? value : String(value);
    if (!text) return '';
    return text.replace(/[&<>\"']/g, char => ({
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '\"': '&quot;',
        "'": '&#39;'
    }[char] || char));
};

const toTitleCase = value => {
    if (value === null || value === undefined) return '';
    const text = typeof value === 'string' ? value : String(value);
    if (!text) return '';
    return text.split(/\s+/).map(word => {
        if (!word) return word;
        if (/^[A-Z0-9]{2,}$/.test(word)) return word;
        const lower = word.toLowerCase();
        return lower.charAt(0).toUpperCase() + lower.slice(1);
    }).join(' ');
};


const linkPreviewCache = new Map();
const linkPreviewInflight = new Map();
const urlRegex = /\bhttps?:\/\/[^\s<]+/gi;

const trimTrailingUrlPunct = url => url.replace(/[),.;!?]+$/g, '');

const extractUrlsFromText = text => {
    if (typeof text !== 'string') return [];
    const matches = text.match(urlRegex);
    if (!matches) return [];
    const unique = new Set();
    matches.forEach(match => {
        const cleaned = trimTrailingUrlPunct(match);
        if (cleaned) unique.add(cleaned);
    });
    return Array.from(unique);
};

const renderLinkPreviewCard = url => {
    const safeUrl = escapeHtmlValue(url);
    const domain = url.replace(/^https?:\/\//i, '').split('/')[0];
    const safeDomain = escapeHtmlValue(domain || url);
    return `
        <a class="field-note-link-card" data-url="${safeUrl}" href="${safeUrl}" target="_blank" rel="noopener">
            <span class="field-note-link-thumb"><span class="field-note-link-placeholder">Loading...</span></span>
            <span class="field-note-link-body">
                <span class="field-note-link-title">${safeDomain}</span>
                <span class="field-note-link-domain">${safeDomain}</span>
            </span>
        </a>
    `;
};

function displayFacilities(facilitiesData, containerId) {
    const container = document.getElementById(containerId);
    if (!container) {
        console.warn('Facilities display skipped - container not found:', containerId);
        return;
    }

    // --- Helper Functions ---

    const toArray = value => Array.isArray(value) ? value : [];

    const cleanText = value => {
        if (typeof value === 'string') return value.trim();
        if (typeof value === 'number') return String(value);
        return '';
    };

    // Robust check for empty/placeholder values
    const isValueEmpty = (value) => {
        if (value === null || value === undefined) return true;

        if (typeof value === 'boolean') return !value; // Hide false values

        if (typeof value === 'string') {
            const lower = value.trim().toLowerCase();
            // Standard empty strings
            if (!lower) return true;

            // Explicit placeholder list — only suppress values that are truly meaningless
            // NOTE: "no", "pending", "none reported", "not reported" removed — these are informative
            const placeholders = [
                'none', 'n/a', 'na', 'n.a.', 'n.a',
                'unknown', 'null', 'undefined', 'false', 'empty',
                '-', '--', '—', '–', 'tbd', 'tba', '[]', '{}',
                'not specified', 'not available', 'not applicable',
                'no data', 'no info', 'no information',
                'nil', 'unspecified'
            ];

            // Exact match check
            if (placeholders.includes(lower)) return true;

            // Check for placeholders with trailing punctuation
            // e.g. "None." or "No;"
            const stripped = lower.replace(/[.,;:\-–—]+$/, '');
            if (placeholders.includes(stripped)) return true;

            return false;
        }

        if (typeof value === 'number') return false; // Numbers are valid (even 0)

        if (Array.isArray(value)) {
            return value.length === 0 || value.every(item => isValueEmpty(item));
        }

        if (typeof value === 'object') {
            const keys = Object.keys(value);
            if (keys.length === 0) return true;
            // Recursively check values, but clean them first to ensure whitespace objects are caught
            return keys.every(k => isValueEmpty(value[k]));
        }

        return true;
    };

    const htmlEscapeMap = {
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#39;'
    };

    const escapeHtml = value => {
        const text = cleanText(value);
        return text ? text.replace(/[&<>"']/g, char => htmlEscapeMap[char] || char) : '';
    };

    const escapeAttribute = value => escapeHtml(value);
    const textCollator = new Intl.Collator(undefined, {
        numeric: true,
        sensitivity: 'base'
    });
    const normalizeTextKey = value => cleanText(value).replace(/\s+/g, ' ').trim().toLocaleLowerCase();
    const compareDisplayText = (a, b) => {
        const textA = cleanText(a);
        const textB = cleanText(b);

        if (!textA && !textB) return 0;
        if (!textA) return 1;
        if (!textB) return -1;

        return textCollator.compare(textA, textB);
    };
    const collectUniqueTexts = (...values) => {
        const seen = new Set();
        const items = [];

        values.forEach(value => {
            const candidates = Array.isArray(value) ? value : [value];
            candidates.forEach(candidate => {
                const text = cleanText(candidate);
                if (!text || isValueEmpty(text)) return;

                const key = normalizeTextKey(text);
                if (!key || seen.has(key)) return;

                seen.add(key);
                items.push(text);
            });
        });

        return items;
    };

    const joinList = values => toArray(values)
        .filter(item => !isValueEmpty(item))
        .map(item => cleanText(item))
        .filter(item => item); // Double check for empty strings

    const formatFieldLabel = (key) => {
        if (!key || typeof key !== 'string') return 'Field';

        const labelOverrides = {
            'accreditations.current': 'Current Accreditations',
            'accreditations.past': 'Past Accreditations'
        };

        if (labelOverrides[key]) {
            return labelOverrides[key];
        }

        // Strip common redundant prefixes
        let cleanKey = key
            .replace(/^(operator|facility|identification|facilityDetails|operatingPeriod|staff|accreditations|locationDetails|location_details)\./i, '')
            .replace(/\./g, ' ');

        return cleanKey
            .replace(/([A-Z])/g, ' $1')
            .replace(/has\s*/gi, '')
            .replace(/\s+/g, ' ')
            .trim()
            .split(' ')
            .map(word => word.charAt(0).toUpperCase() + word.slice(1).toLowerCase())
            .join(' ') || 'Field';
    };

    const getNotesForKey = (fieldNotes, key) => {
        if (!fieldNotes || typeof fieldNotes !== 'object' || !key) return [];
        const notes = fieldNotes[key];
        if (!notes) return [];
        const collected = [];
        const addNote = (text) => {
            if (text && text.trim()) collected.push(text.trim());
        };
        if (Array.isArray(notes)) {
            notes.forEach(note => {
                if (typeof note === 'string') {
                    addNote(note);
                } else if (note && typeof note === 'object' && note.text) {
                    addNote(note.text);
                }
            });
        } else if (typeof notes === 'string') {
            addNote(notes);
        } else if (notes && typeof notes === 'object' && notes.text) {
            addNote(notes.text);
        }
        return collected;
    };

    // Helper to find first non-empty value from a list of keys in an object
    const getValueFromKeys = (obj, keys) => {
        if (!obj || typeof obj !== 'object') return null;
        for (const key of keys) {
            // Support nested keys like 'identification.name'
            if (key.includes('.')) {
                const parts = key.split('.');
                let val = obj;
                for (const part of parts) {
                    val = val && val[part];
                    if (val === undefined || val === null) break;
                }
                if (!isValueEmpty(val)) return typeof val === 'string' ? cleanText(val) : val;
            } else {
                if (!isValueEmpty(obj[key])) return typeof obj[key] === 'string' ? cleanText(obj[key]) : obj[key];
            }
        }
        return null;
    };

    const getFacilityDisplayName = facility => getValueFromKeys(facility, [
        'identification.name', 'identification.currentName',
        'name', 'programName', 'facilityName', 'title',
        'program_name', 'facility_name'
    ]) || '';

    const getMergedLocation = (obj) => {
        if (!obj || typeof obj !== 'object') return null;

        // Prefer a full street address assembled from the structured parts. This is
        // more complete than a bare city/state string and lets the broken-out
        // addressParts.*/locationDetails.* fields stay suppressed in "More details"
        // without actually hiding the address from the card.
        const street = getValueFromKeys(obj, ['addressParts.street', 'address_parts.street', 'locationDetails.street', 'location_details.street', 'streetAddress', 'street_address', 'street']);
        const partsCity = getValueFromKeys(obj, ['addressParts.city', 'address_parts.city', 'locationDetails.city', 'location_details.city']);
        const partsState = getValueFromKeys(obj, ['addressParts.state', 'address_parts.state', 'locationDetails.state', 'location_details.state']);
        const zip = getValueFromKeys(obj, ['addressParts.zip', 'address_parts.zip', 'addressParts.zipcode', 'address_parts.zipcode', 'locationDetails.zip', 'location_details.zip', 'postalCode', 'postal_code', 'zip', 'zipcode']);

        // A populated free-text address is the most complete value we have (it
        // typically already includes street + city + state + zip), so it wins over
        // the bare "City, ST" location string. Listing `location` first here used
        // to mask street addresses stored in `address`.
        const fullAddress = getValueFromKeys(obj, ['fullAddress', 'full_address', 'address']);
        const loc = getValueFromKeys(obj, ['location', 'cityState', 'city_state', 'hq_location', 'headquarters']);
        const city = partsCity || getValueFromKeys(obj, ['city', 'locationCity', 'location_city', 'headquartersCity', 'hq_city']);
        const state = partsState || getValueFromKeys(obj, ['state', 'locationState', 'location_state', 'headquartersState', 'hq_state', 'province']);

        // Show the country only when it isn't the US default — that keeps domestic
        // cards clean while still surfacing the country for international facilities.
        const country = getValueFromKeys(obj, ['addressParts.country', 'address_parts.country', 'locationDetails.country', 'location_details.country', 'country']);
        const isUS = country && /^(us|usa|u\.s\.a?|united states( of america)?)$/i.test(cleanText(country).trim());
        const countrySuffix = (country && !isUS) ? cleanText(country) : '';

        if (street) {
            const cityStateZip = [city, [state, zip].filter(Boolean).join(' ').trim()].filter(Boolean).join(', ');
            return [street, cityStateZip, countrySuffix].filter(Boolean).join(', ');
        }
        if (fullAddress) return countrySuffix ? [fullAddress, countrySuffix].filter(Boolean).join(', ') : fullAddress;
        if (loc) return countrySuffix ? [loc, countrySuffix].filter(Boolean).join(', ') : loc;
        if (city && state) return [`${city}, ${state}`, countrySuffix].filter(Boolean).join(', ');
        return [city || state, countrySuffix].filter(Boolean).join(', ') || null;
    };

    const consumeFieldNotesForKeys = (fieldNotes, keys, usedKeys) => {
        if (!fieldNotes || typeof fieldNotes !== 'object') return [];
        const notes = [];

        keys.forEach(key => {
            if (!key || typeof key !== 'string') return;

            let matchedKey = key;
            let matchedNotes = getNotesForKey(fieldNotes, key);

            if (!matchedNotes.length && key.includes('.')) {
                matchedKey = key.split('.').pop();
                matchedNotes = getNotesForKey(fieldNotes, matchedKey);
            }

            if (!matchedNotes.length) return;

            if (usedKeys && matchedKey) usedKeys.add(matchedKey);
            notes.push(...matchedNotes);
        });

        return collectUniqueTexts(notes);
    };

    const renderDetailSection = (title, content, extraClass = '') => {
        if (!content) return '';
        const className = extraClass ? `facility-detail-section ${extraClass}` : 'facility-detail-section';
        return `
            <section class="${className}">
                <h4 class="facility-section-title">${escapeHtml(title)}</h4>
                ${content}
            </section>
        `;
    };

    const renderInlineFieldNotes = (key, fieldNotes, usedKeys) => {
        // Try full key
        let notes = getNotesForKey(fieldNotes, key);
        let matchedKey = key;
        
        // Try leaf key (e.g. 'startYear' from 'operatingPeriod.startYear')
        if (!notes.length && key.includes('.')) {
            const leafKey = key.split('.').pop();
            notes = getNotesForKey(fieldNotes, leafKey);
            if (notes.length) matchedKey = leafKey;
        }
        
        if (!notes.length) return '';
        
        if (usedKeys && matchedKey) usedKeys.add(matchedKey);
        
        const noteText = notes.map(n => escapeHtml(n)).join('; ');
        return `<div class="field-note-inline">${noteText}</div>`;
    };

    const mergeToolkit = window.KOPFacilityMerge;
    if (!mergeToolkit || typeof mergeToolkit.createApi !== 'function') {
        console.error('KOPFacilityMerge helper is missing. Include js/facility-merge.js before js/tti-program-index.js.');
        container.innerHTML = '<p>Error loading facility merge helpers.</p>';
        return;
    }

    const mergeApi = mergeToolkit.createApi({
        toArray,
        cleanText,
        isValueEmpty,
        normalizeTextKey,
        getFacilityDisplayName,
        getValueFromKeys,
    });

    const getProjectData = mergeToolkit.getProjectData;
    const isOperatorCategory = mergeToolkit.isOperatorCategory;
    const isLocationCategory = mergeToolkit.isLocationCategory;

    const buildLocationFacilityMap = projects => mergeApi.buildCategoryFacilityMapById(projects, isLocationCategory);
    const mergeAndDedupeFacilities = (rawFacilities, locationFacilitiesById) =>
        mergeApi.mergeAndDedupeFacilities(rawFacilities, locationFacilitiesById);

    // --- FileBird document-library matching (shared by operator + facility cards) ---
    // Find the FileBird folder that best matches a given name. Operators match
    // their parent folder (e.g. "Family Help & Wellness"); facilities match the
    // program subfolder nested beneath it.
    const findMatchingFolder = rawName => {
        if (!window.filebirdFolders || !Array.isArray(window.filebirdFolders)) return null;
        const name = cleanText(rawName).toLowerCase().trim();
        if (!name) return null;

        // Build parent lookup map lazily (once across all cards)
        if (!window.filebirdFolderMap) {
            window.filebirdFolderMap = {};
            window.filebirdFolders.forEach(f => { window.filebirdFolderMap[String(f.id)] = f; });
        }

        const normalize = s => s.replace(/[^\w\s]/g, '').trim();
        const normName = normalize(name);

        try {
            // 1. Exact match
            let match = window.filebirdFolders.find(f => f.name && f.name.toLowerCase().trim() === name);
            // 2. Normalized match
            if (!match) {
                match = window.filebirdFolders.find(f => f.name && normalize(f.name.toLowerCase()) === normName);
            }
            // 3. Folder name contains target name
            if (!match && normName.length > 6) {
                match = window.filebirdFolders.find(f => f.name && normalize(f.name.toLowerCase()).includes(normName));
            }
            // 4. Target name contains folder name
            if (!match) {
                match = window.filebirdFolders.find(f => {
                    if (!f.name) return false;
                    const nf = normalize(f.name.toLowerCase());
                    return nf.length > 6 && normName.includes(nf);
                });
            }
            // 5. Parent + child path match (subfolders organized under an operator)
            if (!match && window.filebirdFolderMap) {
                match = window.filebirdFolders.find(f => {
                    if (!f.name) return false;
                    const parentId = String(f.parent || '0');
                    if (parentId === '0') return false;
                    const parent = window.filebirdFolderMap[parentId];
                    if (!parent || !parent.name) return false;
                    const pathWords = (normalize(parent.name.toLowerCase()) + ' ' + normalize(f.name.toLowerCase()))
                        .split(/\s+/)
                        .filter(w => w.length > 2);
                    if (pathWords.length < 2) return false;
                    return pathWords.every(word => normName.includes(word));
                });
            }
            return match || null;
        } catch (e) {
            console.warn('[KOP] Folder matching error for "' + name + '":', e);
            return null;
        }
    };

    // Resolve a folder for a card: an explicit FileBird folder ID (set via the
    // wiki program picker) always wins over fuzzy name matching. Returns a folder
    // object with at least an `id`, or null.
    const resolveDocFolder = (explicitId, rawName) => {
        const idNum = parseInt(explicitId, 10);
        if (Number.isFinite(idNum) && idNum > 0) {
            if (window.filebirdFolderMap && window.filebirdFolderMap[String(idNum)]) {
                return window.filebirdFolderMap[String(idNum)];
            }
            return { id: idNum };
        }
        return findMatchingFolder(rawName);
    };

    const buildDocLibraryHtml = (folder, extraClass) => {
        if (!folder) return '';
        const cls = extraClass ? ' ' + extraClass : '';
        return `
            <div class="field-row full-width-grid facility-document-row${cls}" id="documents-${folder.id}" data-documents-container="true">
                <span class="field-value doc-library-btn-wrap">
                    <button type="button" class="kop-doc-button doc-library-button" data-folder-id="${folder.id}" data-container-id="documents-${folder.id}">
                        📂 View Document Library
                    </button>
                </span>
            </div>`;
    };

    // --- Main Rendering Logic ---

    let html = '<div class="facilities-database">';

    // Convert the new JSON structure to work with existing code
    let operatorGroups = [];
    if (facilitiesData && facilitiesData.projects) {
        // Handle new JSON structure
        const allProjects = Object.values(facilitiesData.projects)
            .filter(project => project && typeof project === 'object');
        const locationFacilitiesById = buildLocationFacilityMap(allProjects);
        const operatorProjects = allProjects.filter(isOperatorCategory);

        const seenOperatorKeys = new Set();

                operatorProjects.forEach(project => {
            if (!project || typeof project !== 'object') {
                return;
            }

            let projectData = (project.data && typeof project.data === 'object') ? project.data : project;
            let unwrapGuard = 0;
            while (projectData
                && typeof projectData === 'object'
                && !projectData.operator
                && !projectData.facilities
                && projectData.data
                && typeof projectData.data === 'object'
                && unwrapGuard < 2
            ) {
                projectData = projectData.data;
                unwrapGuard += 1;
            }
            const operator = projectData.operator || {};
            const facilities = mergeAndDedupeFacilities(
                projectData.facilities,
                locationFacilitiesById
            );

            // Corporate-chain filter: a parent company is identified by
            //   (1) a populated operator name, AND
            //   (2) at least 2 named facilities.
            // Drops single-program records, address-keyed location clusters,
            // and operator-less brand records.
            const operatorHasName = !!(cleanText(operator.name) || cleanText(operator.currentName));
            if (!operatorHasName) {
                return;
            }
            const namedFacilityCount = facilities.reduce((sum, f) => {
                const name = getFacilityDisplayName(f);
                return name && name.trim() ? sum + 1 : sum;
            }, 0);
            if (namedFacilityCount < 2) {
                return;
            }

            const operatorNameKeySource = cleanText(operator.name) || cleanText(operator.currentName) || cleanText(project.name);
            const operatorKey = operatorNameKeySource ? operatorNameKeySource.toLowerCase() : '';
            if (operatorKey) {
                if (seenOperatorKeys.has(operatorKey)) {
                    return;
                }
                seenOperatorKeys.add(operatorKey);
            }

            operatorGroups.push({
                operator,
                facilities,
                name: cleanText(project.name),
                // Explicit document-library folder ID (set via the wiki program
                // picker). Preferred over fuzzy folder-name matching when present.
                documentFolderId: projectData.documentFolderId || project.documentFolderId || null,
                linked_news: Array.isArray(project.linked_news) ? project.linked_news : []
            });
        });
    } else if (Array.isArray(facilitiesData)) {
        // Handle old structure (fallback)
        operatorGroups = facilitiesData;
    } else if (facilitiesData && typeof facilitiesData === 'object') {
        operatorGroups = Object.values(facilitiesData);
    }

    if (!Array.isArray(operatorGroups) || operatorGroups.length === 0) {
        container.innerHTML = '<p>No facility operators found in the provided data.</p>';
        return;
    }

    // Sort operators alphabetically by name (falling back to empty string)
    operatorGroups.sort((a, b) => {
        const operatorA = a && a.operator ? a.operator : {};
        const operatorB = b && b.operator ? b.operator : {};
        const nameA = cleanText(operatorA.name) || cleanText(operatorA.currentName) || cleanText(a && a.name) || '';
        const nameB = cleanText(operatorB.name) || cleanText(operatorB.currentName) || cleanText(b && b.name) || '';
        return compareDisplayText(nameA, nameB);
    });

    // Generate operator sections
    // Cross-reference rows: every past/alternate name becomes its own index
    // entry linking to the current profile ("Island View RTC — see Elevations
    // RTC"). Collected while rendering, emitted after the real sections.
    const aliasEntries = [];
    const renderedOperatorKeys = new Set();

    operatorGroups.forEach(operatorGroup => {
        const operator = operatorGroup && operatorGroup.operator ? operatorGroup.operator : {};
        const rawFacilities = toArray(operatorGroup && operatorGroup.facilities).slice();

        const facilities = rawFacilities.filter(f => {
            if (!f) return false;
            // Enhanced name check for deduplication
            const name = getFacilityDisplayName(f);

            // FILTER: exclude known corporate entities from being listed as facilities
            // This prevents data errors where a parent company is listed as a facility of another
            const corporateNames = [
                'acadia healthcare', 'acadia health care',
                'universal health services', 'uhs',
                'family help & wellness', 'family help and wellness', 'fhw',
                'sequel youth and family services', 'sequel',
                'aspen education group', 'aspen education',
                'kids centers of america',
                'innerchange',
                'altior healthcare'
            ];

            if (name) {
                const lowerName = name.toLowerCase().trim();
                // Check for exact match or name being just the corporate name
                if (corporateNames.includes(lowerName)) return false;
                // Check if name is exactly one of the corporate names but maybe with "Inc" etc.
                const strippedName = lowerName.replace(/\s+(inc|llc|ltd|corp|corporation)\.?$/g, '');
                if (corporateNames.includes(strippedName)) return false;
            }

            return !!normalizeTextKey(name);
        });

        // Check if this is a privately owned facility (has isPrivatelyOwned flag set)
        const hasPrivateOwner = facilities.some(f => f && f.isPrivatelyOwned === true);

        // Get the actual operator name (not the project name)
        const actualOperatorName = getValueFromKeys(operator, [
            'name', 'currentName', 'operatorName', 'ownerName', 'companyName',
            'current_name', 'operator_name', 'owner_name', 'company_name',
            'title'
        ]);

        // Determine display name: if privately owned, show that; otherwise use operator name or project name
        let operatorName;
        if (hasPrivateOwner && !actualOperatorName) {
            operatorName = 'Privately Owned';
        } else if (actualOperatorName) {
            operatorName = actualOperatorName;
        } else {
            operatorName = cleanText(operatorGroup && operatorGroup.name) || 'Unknown Parent Company';
        }

        // Do not display operators with an unknown name.
        if (operatorName === 'Unknown Parent Company') {
            return;
        }

        // Operator-level searchable text: display name + the organization's
        // alternate/past names + the project name, so searching an old or
        // alternate org name still surfaces the whole section (facility cards
        // already index their own former/other names separately). For search
        // purposes a comma-joined alias string works as-is, so plain array
        // coercion is enough here.
        const toNameArray = raw => (Array.isArray(raw) ? raw : (raw ? [raw] : []));
        const operatorAltNames = collectUniqueTexts(
            toNameArray(getValueFromKeys(operator, [
                'otherNames', 'other_names', 'aliases', 'alternateNames'
            ])),
            toNameArray(getValueFromKeys(operator, [
                'pastNames', 'past_names', 'formerNames', 'former_names'
            ]))
        );
        const operatorSearchText = collectUniqueTexts(
            [operatorName],
            operatorAltNames,
            [cleanText(operatorGroup && operatorGroup.name) || '']
        ).join(' | ');

        renderedOperatorKeys.add(normalizeTextKey(operatorName));
        operatorAltNames.forEach(alias => {
            aliasEntries.push({ alias, target: operatorName, facility: '' });
        });

        // Sort facilities alphabetically by name
        facilities.sort((a, b) => {
            return compareDisplayText(getFacilityDisplayName(a), getFacilityDisplayName(b));
        });

        // Build operator header with Name - add class for long names or long words
        const hasLongWord = operatorName.split(' ').some(word => word.length > 14);
        const isTooLong = operatorName.length > 25;
        const operatorNameClass = (hasLongWord || isTooLong) ? 'operator-name operator-name-long' : 'operator-name';
        const operatorHeaderName = escapeHtml(operatorName);
        let operatorHeader = `<span class="${operatorNameClass}">${operatorHeaderName}</span>`;
        // Build location and years - each on separate lines
        let locationYearsLine = '';
        const locationLines = [];

        const operatorLocation = getMergedLocation(operator);
        if (operatorLocation) {
            locationLines.push(`<div>${escapeHtml(operatorLocation)}</div>`);
        }

        let opYears = getValueFromKeys(operator, ['operatingPeriod', 'yearsActive', 'operating_period', 'years_active']);
        if (!opYears || typeof opYears === 'object') {
             const start = getValueFromKeys(operator, ['founded', 'yearFounded', 'year_founded', 'operatingPeriod.startYear', 'operating_period.start_year']);
             if (start) {
                 const end = getValueFromKeys(operator, ['operatingPeriod.endYear', 'operating_period.end_year']) || 'Present';
                 opYears = `${start}-${end}`;
             }
        }

        if (opYears && typeof opYears === 'string') {
            locationLines.push(`<div>${escapeHtml(opYears)}</div>`);
        }

        if (locationLines.length > 0) {
            locationYearsLine = `<span class="operator-location">${locationLines.join('')}</span>`;
        }

        const operatorFieldKeys = {
            status: ['status', 'operatingPeriod.status', 'operating_period.status'],
            headquarters: ['headquarters', 'hq_location', 'location', 'address'],
            founded: ['founded', 'yearFounded', 'year_founded'],
            parentCompanies: ['parentCompanies', 'parent_companies', 'parentCompany', 'parent_company', 'parents'],
            investors: ['investors', 'investor', 'investmentFirms', 'investment_firms'],
            owners: ['owners', 'owner', 'currentOwners', 'currentOwner', 'current_owners', 'current_owner', 'ownership', 'ownershipStructure', 'ownership_structure'],
            websites: ['websites', 'website', 'links', 'profileLinks', 'urls'],
            founders: ['keyStaff.founders', 'founders'],
            ceo: ['keyStaff.ceo', 'ceo', 'chiefExecutiveOfficer', 'chief_executive_officer'],
            executives: ['keyStaff.keyExecutives', 'keyStaff.executives', 'keyExecutives', 'executives']
        };

        // Build other operator data
        let otherOperatorData = '';

        // Helper to render a single item (handles objects in arrays)
        const renderItemOp = (item) => {
            if (isValueEmpty(item)) return '';
            if (typeof item === 'boolean') return item ? 'Yes' : ''; // strict boolean check
            if (typeof item === 'string') {
                // Double-check string isn't a placeholder
                if (isValueEmpty(item)) return '';
                return escapeHtml(item);
            }
            if (typeof item === 'number') return escapeHtml(String(item));
            if (typeof item === 'object' && !Array.isArray(item)) {

                // Handle clickable links in objects
                if (item.url && typeof item.url === 'string' && (item.url.startsWith('http') || item.url.startsWith('/'))) {
                    const label = item.displayText || item.name || item.text || item.url;
                    return `<a href="${escapeAttribute(item.url)}" target="_blank" rel="noopener">${escapeHtml(label)}</a>`;
                }

                const parts = [];
                Object.entries(item).forEach(([k, v]) => {     
                    if (!isValueEmpty(v)) {
                        if (k === 'name' || k === 'value' || k === 'text') {
                            parts.push(escapeHtml(v));
                        } else if (k !== 'role' || v.trim()) { 
                            parts.push(escapeHtml(v));
                        }
                    }
                });
                return parts.join(' - ');
            }
            return escapeHtml(String(item));
        };

        const normalizeDisplayItems = (value, splitComma = false) => {
            const items = Array.isArray(value)
                ? value
                : isValueEmpty(value)
                    ? []
                    : [value];

            return items.flatMap(item => {
                if (!splitComma || typeof item !== 'string') return [item];
                return item.split(/\s*,\s*/).map(part => part.trim()).filter(Boolean);
            }).filter(item => !isValueEmpty(item));
        };

        const operatorStatusValue = getValueFromKeys(operator, operatorFieldKeys.status);
        const operatorHeadquartersValue = getValueFromKeys(operator, operatorFieldKeys.headquarters) || operatorLocation;
        const operatorFoundedValue = getValueFromKeys(operator, operatorFieldKeys.founded);
        const operatorPeriodValue = typeof opYears === 'string' ? opYears : '';

        const operatorFactItems = [];
        if (!isValueEmpty(operatorStatusValue)) {
            operatorFactItems.push({ label: 'Status', value: escapeHtml(operatorStatusValue) });
        }
        if (!isValueEmpty(operatorFoundedValue)) {
            operatorFactItems.push({ label: 'Founded', value: escapeHtml(String(operatorFoundedValue)) });
        }
        if (!isValueEmpty(operatorHeadquartersValue) && normalizeTextKey(operatorHeadquartersValue) !== normalizeTextKey(operatorLocation)) {
            operatorFactItems.push({ label: 'Headquarters', value: escapeHtml(operatorHeadquartersValue) });
        }
        if (!isValueEmpty(operatorPeriodValue) && normalizeTextKey(operatorPeriodValue) !== normalizeTextKey(opYears)) {
            operatorFactItems.push({ label: 'Operating period', value: escapeHtml(operatorPeriodValue) });
        }

        const operatorFactsHtml = operatorFactItems.length
            ? renderDetailSection(
                'At a glance',
                `<div class="facility-facts-grid">${
                    operatorFactItems.map(item => `
                        <div class="facility-fact">
                            <span class="facility-fact-label">${item.label}</span>
                            <span class="facility-fact-value">${item.value}</span>
                        </div>
                    `).join('')
                }</div>`,
                'operator-facts-section'
            )
            : '';

        // Parent-company profiles surface their investors and owners instead of
        // a "parent companies" section (they ARE the parent company, so listing a
        // parent above them is misleading — see the FHW/CEDU mix-up).
        const investorItems = normalizeDisplayItems(
            getValueFromKeys(operator, operatorFieldKeys.investors),
            true
        ).map(item => renderItemOp(item)).filter(Boolean);

        const operatorInvestorsHtml = investorItems.length
            ? renderDetailSection(
                'Investors',
                `<div class="resource-chip-list">${
                    investorItems.map(item => `<span class="resource-chip">${item}</span>`).join('')
                }</div>`,
                'operator-investors-section'
            )
            : '';

        const ownerItems = normalizeDisplayItems(
            getValueFromKeys(operator, operatorFieldKeys.owners),
            true
        ).map(item => renderItemOp(item)).filter(Boolean);

        const operatorOwnersHtml = ownerItems.length
            ? renderDetailSection(
                'Owners',
                `<div class="resource-chip-list">${
                    ownerItems.map(item => `<span class="resource-chip">${item}</span>`).join('')
                }</div>`,
                'operator-owners-section'
            )
            : '';

        const websiteItems = normalizeDisplayItems(getValueFromKeys(operator, operatorFieldKeys.websites));
        const websiteLinks = websiteItems.map(item => {
            if (typeof item === 'string') {
                const safeUrl = escapeAttribute(item);
                let displayUrl = item.replace(/^https?:\/\/(www\.)?/, '');
                if (displayUrl.length > 60) displayUrl = displayUrl.substring(0, 57) + '...';
                return safeUrl ? `<a href="${safeUrl}" target="_blank" rel="noopener">${escapeHtml(displayUrl)}</a>` : '';
            }
            return renderItemOp(item);
        }).filter(Boolean);

        const operatorWebsitesHtml = websiteLinks.length
            ? renderDetailSection(
                'Websites',
                `<div class="operator-link-list">${
                    websiteLinks.map(link => `<div class="operator-link-item">${link}</div>`).join('')
                }</div>`,
                'operator-websites-section'
            )
            : '';

        const founderItems = normalizeDisplayItems(getValueFromKeys(operator, operatorFieldKeys.founders), true)
            .map(item => renderItemOp(item))
            .filter(Boolean);
        const executiveItems = normalizeDisplayItems(getValueFromKeys(operator, operatorFieldKeys.executives), true)
            .map(item => renderItemOp(item))
            .filter(Boolean);
        const ceoValueRaw = getValueFromKeys(operator, operatorFieldKeys.ceo);
        const ceoValue = isValueEmpty(ceoValueRaw) ? '' : renderItemOp(ceoValueRaw);

        let operatorPeopleRows = '';
        if (founderItems.length) {
            operatorPeopleRows += `<div class="field-row"><span class="field-label">Founders</span><span class="field-value">${founderItems.join(', ')}</span></div>`;
        }
        if (ceoValue) {
            operatorPeopleRows += `<div class="field-row"><span class="field-label">CEO</span><span class="field-value">${ceoValue}</span></div>`;
        }
        if (executiveItems.length) {
            operatorPeopleRows += `<div class="field-row"><span class="field-label">Key executives</span><span class="field-value">${executiveItems.join(', ')}</span></div>`;
        }

        const operatorPeopleHtml = operatorPeopleRows
            ? renderDetailSection(
                'Key staff',
                `<div class="facility-detail-grid">${operatorPeopleRows}</div>`,
                'operator-people-section'
            )
            : '';

        const suppressedOperatorFieldKeys = new Set([
            'name', 'currentName', 'operatorName', 'ownerName', 'companyName',
            'current_name', 'operator_name', 'owner_name', 'company_name',
            'title',
            'status', 'operatingPeriod.status', 'operating_period.status',
            'headquarters', 'hq_location', 'location', 'address',
            'founded', 'yearFounded', 'year_founded',
            'operatingPeriod', 'operating_period', 'yearsActive', 'years_active',
            'operatingPeriod.startYear', 'operatingPeriod.endYear',
            'operating_period.start_year', 'operating_period.end_year',
            ...operatorFieldKeys.parentCompanies,
            ...operatorFieldKeys.investors,
            ...operatorFieldKeys.owners,
            ...operatorFieldKeys.websites,
            ...operatorFieldKeys.founders,
            ...operatorFieldKeys.ceo,
            ...operatorFieldKeys.executives
        ]);

        const shouldSuppressOperatorField = key => {
            if (!key || typeof key !== 'string') return false;
            if (suppressedOperatorFieldKeys.has(key)) return true;
            if (/^(locationDetails|location_details)\.(city|state)$/i.test(key)) return true;
            const lower = key.toLowerCase();
            if (/(^|\.)(source_?project_?id|project_?id|post_?id|wp_?id|master_?id|record_?id|row_?id|_?id|slug|guid|uuid|created_?at|updated_?at|modified_?at)$/.test(lower)) return true;
            return false;
        };

        // Function to recursively render all fields from an object
        const renderAllObjectFieldsOp = (obj, prefix = '', depth = 0) => {
            if (!obj || typeof obj !== 'object' || depth > 3) return [];
            const fields = [];

                                    // Skip ONLY special handling keys. 
                                    // We want everything else to show in details, even if it repeats header info (completeness > aesthetics)
                                    const skipFullKeys = [
                                        'resources', // Handled separately
                                        'fieldNotes' // Handled separately
                                    ];            Object.keys(obj).forEach(key => {
                const fullKey = prefix ? `${prefix}.${key}` : key;
                const value = obj[key];

                // Skip keys already shown in header (top-level only for operators)
                if (depth === 0 && skipFullKeys.includes(key)) return;

                // Skip empty values
                if (isValueEmpty(value)) return;

                if (Array.isArray(value)) {
                    if (value.length > 0 && !value.every(isValueEmpty)) {
                        const label = formatFieldLabel(fullKey);
                        const isUrl = value.some(v => typeof v === 'string' && (v.startsWith('http://') || v.startsWith('https://'))); 
                        fields.push({ key: fullKey, label, value: value, isList: true, renderListAsLinks: isUrl });
                    }
                } else if (typeof value === 'object' && Object.keys(value).length > 0) {
                    fields.push(...renderAllObjectFieldsOp(value, fullKey, depth + 1));
                } else if (typeof value === 'boolean') {       
                    if (value) {
                         const label = formatFieldLabel(fullKey);
                         fields.push({ key: fullKey, label, value: 'Yes' });
                    }
                } else {
                    const label = formatFieldLabel(fullKey);
                    const textValue = cleanText(value);
                    // Double-check: skip empty/placeholder values like "No", "None", etc.
                    if (textValue && !isValueEmpty(textValue)) {
                        fields.push({ key: fullKey, label, value: textValue });
                    }
                }
            });

            return fields;
        };

        // Get all fields from the operator object
        const operatorFields = renderAllObjectFieldsOp(operator);

        operatorFields.forEach(field => {
            if (shouldSuppressOperatorField(field.key)) return;
            if (isValueEmpty(field.value)) return;

            let renderedValue = '';

            if (field.isList) {
                const items = Array.isArray(field.value) ? field.value : [field.value];
                const validItems = items.filter(i => !isValueEmpty(i));

                if (!validItems.length) return;

                if (field.renderListAsLinks) {
                    renderedValue = validItems.map(url => {    
                        if (typeof url !== 'string') return '';
                        const safeUrl = escapeAttribute(url);
                        let displayUrl = url.replace(/^https?:\/\/(www\.)?/, '');
                        if (displayUrl.length > 50) displayUrl = displayUrl.substring(0, 47) + '...';
                        return safeUrl ? `<a href="${safeUrl}" target="_blank" rel="noopener">${escapeHtml(displayUrl)}</a>` : '';
                    }).filter(Boolean).join('<br>');
                } else {
                    renderedValue = validItems.map(item => renderItemOp(item)).filter(Boolean).join(', ');
                }
            } else {
                renderedValue = escapeHtml(field.value);
            }

            if (!renderedValue || isValueEmpty(renderedValue)) return;

            if (field.label) {
                otherOperatorData += `<div class="field-row"><span class="field-label">${escapeHtml(field.label)}</span><span class="field-value">${renderedValue}</span></div>`;  
            } else {
                otherOperatorData += `<div class="field-row"><span class="field-value">${renderedValue}</span></div>`;   
            }
        });

        const additionalOperatorDetailsHtml = otherOperatorData
            ? renderDetailSection(
                'More details',
                `<div class="facility-detail-grid">${otherOperatorData}</div>`,
                'operator-additional-section'
            )
            : '';

        // Parent-company document library: match a FileBird folder by the
        // operator name. This is typically the top-level operator folder, so its
        // library surfaces every document across the operator's program subfolders.
        const operatorFolder = resolveDocFolder(
            operatorGroup.documentFolderId,
            cleanText(operator.name) || cleanText(operator.currentName) || operatorName
        );
        const operatorDocLibraryHtml = operatorFolder
            ? renderDetailSection(
                'Documents',
                buildDocLibraryHtml(operatorFolder, 'operator-document-row'),
                'operator-documents-section'
            )
            : '';

        // Operator-level linked news (articles linked to the company/operator row
        // itself, attached as project.linked_news by the server).
        const operatorLinkedNews = Array.isArray(operatorGroup.linked_news) ? operatorGroup.linked_news : [];
        let operatorLatestNewsHtml = '';
        let operatorNewsSectionHtml = '';
        if (operatorLinkedNews.length > 0) {
            const fmtDate = value => {
                if (!value) return '';
                const ts = Date.parse(value);
                if (isNaN(ts)) return value;
                return new Date(ts).toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' });
            };
            const itemsHtml = operatorLinkedNews.map(n => {
                const title = escapeHtml(n.display_title || n.article_title || '(untitled)');
                const titleHtml = n.article_url
                    ? `<a href="${escapeAttribute(n.article_url)}" target="_blank" rel="noopener" class="facility-news-title">${title}</a>`
                    : `<span class="facility-news-title">${title}</span>`;
                const meta = [];
                if (n.publication_name) meta.push(escapeHtml(n.publication_name));
                if (n.publication_date) meta.push(escapeHtml(fmtDate(n.publication_date)));
                return `<li class="facility-news-item">${titleHtml}${meta.length ? `<div class="facility-news-meta">${meta.join(' &middot; ')}</div>` : ''}</li>`;
            }).join('');
            operatorNewsSectionHtml = renderDetailSection(
                `News (${operatorLinkedNews.length})`,
                `<ul class="facility-news-list">${itemsHtml}</ul>`,
                'operator-news-section'
            );

            const latest = operatorLinkedNews[0];
            const latestTitle = escapeHtml(latest.display_title || latest.article_title || '(untitled)');
            const latestTitleHtml = latest.article_url
                ? `<a href="${escapeAttribute(latest.article_url)}" target="_blank" rel="noopener" class="facility-latest-news-title">${latestTitle}</a>`
                : `<span class="facility-latest-news-title">${latestTitle}</span>`;
            const latestMeta = [];
            if (latest.publication_name) latestMeta.push(escapeHtml(latest.publication_name));
            if (latest.publication_date) latestMeta.push(escapeHtml(fmtDate(latest.publication_date)));
            const moreCount = operatorLinkedNews.length - 1;
            operatorLatestNewsHtml = `<div class="facility-latest-news operator-latest-news">
                    <span class="facility-latest-news-label">📰 Latest news</span>
                    ${latestTitleHtml}
                    ${latestMeta.length ? `<span class="facility-latest-news-meta">${latestMeta.join(' &middot; ')}</span>` : ''}
                    ${moreCount > 0 ? `<span class="facility-latest-news-more">+${moreCount} more below</span>` : ''}
                </div>`;
        }

        const operatorSectionsHtml = [
            operatorNewsSectionHtml,
            operatorFactsHtml,
            operatorInvestorsHtml,
            operatorOwnersHtml,
            operatorWebsitesHtml,
            operatorPeopleHtml,
            operatorDocLibraryHtml,
            additionalOperatorDetailsHtml
        ].join('');

        const operatorDetailsDiv = operatorSectionsHtml
            ? `<div class="operator-details">${operatorSectionsHtml}</div>`
            : '';

        html += '<details class="operator-section" data-operator="' + escapeAttribute(operatorName) + '" data-operator-search="' + escapeAttribute(operatorSearchText) + '">' +
                '<summary class="operator-header">' +
                    operatorHeader +
                    locationYearsLine +
                '</summary>' +
                '<div class="operator-content-scrollable">' +
                    operatorLatestNewsHtml +
                    operatorDetailsDiv;

        facilities.forEach(facility => {
            const identification = facility && facility.identification ? facility.identification : {};
            const facilityDetails = facility && facility.facilityDetails ? facility.facilityDetails : {};
            const ageRange = facilityDetails && facilityDetails.ageRange ? facilityDetails.ageRange : {};
            const staff = facility && facility.staff ? facility.staff : {};
            const accreditations = facility && facility.accreditations ? facility.accreditations : {};
            const operatingPeriod = facility && facility.operatingPeriod ? facility.operatingPeriod : {};
            const memberships = joinList(facility && facility.memberships);
            const licensing = joinList(facility && facility.licensing);
            const profileLinks = joinList(facility && facility.profileLinks);
            const fieldNotes = (facility && facility.fieldNotes) || {};
            const usedFieldNoteKeys = new Set();

            const statusLabelRaw = cleanText(operatingPeriod.status) || 'Unknown';
            const statusClass = statusLabelRaw.toLowerCase().replace(/[^a-z0-9]+/g, '-');
            const statusLabel = escapeHtml(statusLabelRaw);
            // Only show the status badge when a real status exists; an absent or
            // "unknown"/placeholder status renders no badge at all.
            const hasStatus = !isValueEmpty(operatingPeriod.status);

            // Build facility header with Name - Enhanced lookup
            const facilityHeaderRaw = getFacilityDisplayName(facility);
            const facilityHeader = escapeHtml(facilityHeaderRaw || 'Unnamed Facility');

            // Data for display above the "cut"
            const facilityLocation = getMergedLocation(facility) ? escapeHtml(getMergedLocation(facility)) : '';

            let yearRange = '';
            let facYears = getValueFromKeys(facility, ['yearsOfOperation', 'yearsActive', 'years_active', 'operating_period_text']);
            if (facYears && typeof facYears === 'string') {
                yearRange = escapeHtml(facYears);
            } else {
                const startYear = getValueFromKeys(operatingPeriod, ['startYear', 'start_year']) || 
                                  getValueFromKeys(facility, ['founded', 'yearFounded', 'opened', 'startYear', 'year_founded', 'start_year', 'operatingPeriod.startYear']);
                if (startYear) {
                    const endYear = getValueFromKeys(operatingPeriod, ['endYear', 'end_year']) || 'Present';
                    yearRange = escapeHtml(`${startYear}-${endYear}`);
                }
            }

            // Subtext for former names, aliases, and current operator
            const cleanHeaderText = value => cleanText(value).replace(/\s*\($/, '').trim();
            // Source data frequently stores historical markers ("Previously X",
            // "Formerly Y") inside fields that are rendered as present-tense
            // (operator, owner, alias). These helpers detect and strip those markers
            // so each value lands in the right line ("Formerly:" / "Previously owned
            // by:") and reads grammatically instead of e.g. "Operated by: Previously…".
            const HISTORICAL_PREFIX_RE = /^\s*(?:previously|formerly|former|prior(?:\s+to)?)\b[\s:;,.\-–—]*/i;
            const isHistoricalText = value => /\b(?:previously|former(?:ly)?|prior)\b/i.test(cleanText(value));
            const stripHistoricalPrefix = value => cleanHeaderText(value).replace(HISTORICAL_PREFIX_RE, '').trim();
            // Some records pack several names into one string ("A, B, C" or "A; B"),
            // and merged cards combine name lists from multiple source records. Split
            // them into individual names so they can be de-duplicated against each
            // other and across the Formerly / Also-known-as lines. Bare corporate
            // suffixes (Inc, LLC, …) are re-attached so "Foo, Inc." is not split.
            const CORP_SUFFIX_RE = /^(?:inc|llc|l\.l\.c|ltd|co|corp|corporation|company|llp|lp|plc|pllc|pc|p\.c|n\.a)\.?$/i;
            const splitNameList = value => {
                const out = [];
                cleanText(value).split(/\s*[;,]\s*/).map(part => part.trim()).filter(Boolean).forEach(part => {
                    if (out.length && CORP_SUFFIX_RE.test(part)) {
                        out[out.length - 1] = `${out[out.length - 1]}, ${part}`;
                    } else {
                        out.push(part);
                    }
                });
                return out;
            };
            const expandNames = raw => (Array.isArray(raw) ? raw : (raw ? [raw] : [])).flatMap(splitNameList);

            const formerNames = collectUniqueTexts(
                expandNames(getValueFromKeys(facility, ['identification.pastNames', 'pastNames', 'identification.formerNames', 'formerNames']))
            );
            // Aliases that are really historical ("Previously X") belong under
            // "Formerly", not "Also known as" — reclassify them.
            const rawOtherNames = collectUniqueTexts(
                expandNames(getValueFromKeys(facility, ['identification.otherNames', 'otherNames']))
            ).map(cleanHeaderText);
            const historicalOtherNames = rawOtherNames.filter(isHistoricalText).map(stripHistoricalPrefix);
            const normalizedFormerNames = collectUniqueTexts(
                formerNames.map(stripHistoricalPrefix),
                historicalOtherNames
            );
            const formerNameKeys = new Set(normalizedFormerNames.map(normalizeTextKey));
            // Drop anything already shown as a former name, plus the facility's own
            // display name (the card header already shows it), from the alias line.
            const selfNameKey = normalizeTextKey(facilityHeaderRaw || '');
            const otherNames = rawOtherNames
                .filter(name => !isHistoricalText(name))
                .filter(name => !formerNameKeys.has(normalizeTextKey(name)))
                .filter(name => normalizeTextKey(name) !== selfNameKey);
            const currentOp = getValueFromKeys(facility, ['identification.currentOperator', 'currentOperator', 'current_operator']);
            // Current owner(s) — privately-owned facilities store this instead of a
            // corporate operator. These keys are suppressed from "More details", so
            // surface them here or the owner info would be hidden entirely.
            const currentOwners = collectUniqueTexts(
                getValueFromKeys(facility, ['identification.currentOwners', 'currentOwners', 'current_owners', 'identification.currentOwner', 'currentOwner', 'current_owner'])
            );
            const previousOwners = collectUniqueTexts(
                getValueFromKeys(facility, ['identification.previousOwners', 'previousOwners', 'previous_owners', 'identification.previousOwner', 'previousOwner', 'previous_owner', 'identification.formerOwners', 'formerOwners', 'former_owners', 'identification.formerOwner', 'formerOwner', 'former_owner'])
            );

            // Some datasets place historical ownership strings in current-owner
            // fields (e.g. "Previously Stone Mountain School"). Reclassify those
            // entries so card copy reflects temporal context accurately.
            const currentOwnerList = [];
            const previousOwnerList = [...previousOwners];
            const legalNameAliases = [];
            currentOwners.forEach(owner => {
                const ownerText = cleanText(owner);
                if (!ownerText) return;
                const legalNameMatch = ownerText.match(/\b(?:new\s+)?legal\s+name\b[\s:;,-]*(.+)$/i);
                if (legalNameMatch) {
                    const alias = cleanHeaderText(legalNameMatch[1]);
                    if (alias) legalNameAliases.push(alias);
                    return;
                }
                if (isHistoricalText(ownerText)) {
                        previousOwnerList.push(stripHistoricalPrefix(ownerText) || ownerText);
                } else {
                    currentOwnerList.push(ownerText);
                }
            });

            let subtextParts = [];

            if (normalizedFormerNames.length > 0) {
                subtextParts.push(`Formerly: ${escapeHtml(normalizedFormerNames.join(', '))}`);
            }

            const aliasNames = collectUniqueTexts(otherNames, legalNameAliases);
            if (aliasNames.length > 0) {
                subtextParts.push(`Also known as: ${escapeHtml(aliasNames.join(', '))}`);
            }

            const uniqueCurrentOwners = collectUniqueTexts(currentOwnerList);
            const uniquePreviousOwners = collectUniqueTexts(previousOwnerList);

            if (uniqueCurrentOwners.length > 0) {
                subtextParts.push(`Owned by: ${escapeHtml(uniqueCurrentOwners.join(', '))}`);
            }

            if (uniquePreviousOwners.length > 0) {
                subtextParts.push(`Previously owned by: ${escapeHtml(uniquePreviousOwners.join(', '))}`);
            }

            if (currentOp && !isValueEmpty(currentOp)) {
                const currentOpText = cleanText(currentOp);
                // Some datasets stuff historical strings ("Previously X; Y") into
                // the current-operator field. Those are already surfaced by the
                // "Previously owned by" line, so don't re-assert them as the
                // present-day operator (which also reads ungrammatically).
                if (isHistoricalText(currentOpText)) {
                    // skip: covered by previous-owner/former-name copy above
                } else if (statusClass === 'transferred' || statusClass === 'acquired') {
                    // For transferred/acquired records, avoid asserting this is the
                    // present-day operator because source data can be historical.
                    subtextParts.push(`<strong>Operator at transfer: ${escapeHtml(currentOp)}</strong>`);
                }
                // If open but listed under a different parent (historical context), might still be useful
                else if (statusClass === 'open' && cleanText(operatorName) !== currentOpText) {
                     subtextParts.push(`Currently operated by: ${escapeHtml(currentOp)}`);
                }
            }

            let otherNamesHtml = '';
            if (subtextParts.length > 0) {
                otherNamesHtml = `<div class="facility-header-subtext">${subtextParts.join('<br>')}</div>`;
            }

            const facilityFieldKeys = {
                type: [
                    'facilityDetails.type', 'type', 'programType', 'program_type',
                    'facilityType', 'facility_type', 'category', 'identification.type'
                ],
                capacity: [
                    'facilityDetails.capacity', 'capacity', 'licensedCapacity',
                    'licensed_capacity', 'maxCapacity', 'max_capacity'
                ],
                gender: [
                    'facilityDetails.gender', 'gender', 'servedGender',
                    'served_gender', 'sex'
                ],
                ageRangeText: [
                    'facilityDetails.ageRange.label', 'facilityDetails.ageRange.display',
                    'facilityDetails.ageRange.text', 'ageRange.label',
                    'ageRange.display', 'ageRange.text',
                    'facilityDetails.ageRange', 'ageRange', 'age_range'
                ],
                ageMin: [
                    'facilityDetails.ageRange.min', 'facilityDetails.ageRange.minimum',
                    'facilityDetails.ageRange.minAge', 'facilityDetails.ageRange.min_age',
                    'ageRange.min', 'ageRange.minimum', 'ageRange.minAge',
                    'ageRange.min_age', 'ageMin', 'age_min'
                ],
                ageMax: [
                    'facilityDetails.ageRange.max', 'facilityDetails.ageRange.maximum',
                    'facilityDetails.ageRange.maxAge', 'facilityDetails.ageRange.max_age',
                    'ageRange.max', 'ageRange.maximum', 'ageRange.maxAge',
                    'ageRange.max_age', 'ageMax', 'age_max'
                ],
                notes: [
                    'notes', 'note', 'summary', 'description',
                    'facilityDetails.notes', 'facilityDetails.note',
                    'facilityDetails.summary', 'facilityDetails.description',
                    'identification.notes'
                ]
            };

            const typeValue = getValueFromKeys(facility, facilityFieldKeys.type);
            const capacityValue = getValueFromKeys(facility, facilityFieldKeys.capacity);
            const genderValue = getValueFromKeys(facility, facilityFieldKeys.gender);

            const rawAgeRangeText = getValueFromKeys(facility, facilityFieldKeys.ageRangeText);
            const ageMinValue = getValueFromKeys(facility, facilityFieldKeys.ageMin);
            const ageMaxValue = getValueFromKeys(facility, facilityFieldKeys.ageMax);

            let ageDisplay = '';
            if (typeof rawAgeRangeText === 'string' && !isValueEmpty(rawAgeRangeText)) {
                ageDisplay = rawAgeRangeText;
            } else if (!isValueEmpty(ageMinValue) && !isValueEmpty(ageMaxValue)) {
                ageDisplay = `${ageMinValue}-${ageMaxValue}`;
            } else if (!isValueEmpty(ageMinValue)) {
                ageDisplay = `${ageMinValue}+`;
            } else if (!isValueEmpty(ageMaxValue)) {
                ageDisplay = `Up to ${ageMaxValue}`;
            }

            const typeFactNotes = consumeFieldNotesForKeys(fieldNotes, facilityFieldKeys.type, usedFieldNoteKeys);
            const ageFactNotes = collectUniqueTexts(
                consumeFieldNotesForKeys(fieldNotes, facilityFieldKeys.ageRangeText, usedFieldNoteKeys),
                consumeFieldNotesForKeys(fieldNotes, facilityFieldKeys.ageMin, usedFieldNoteKeys),
                consumeFieldNotesForKeys(fieldNotes, facilityFieldKeys.ageMax, usedFieldNoteKeys)
            );
            const genderFactNotes = consumeFieldNotesForKeys(fieldNotes, facilityFieldKeys.gender, usedFieldNoteKeys);
            const capacityFactNotes = consumeFieldNotesForKeys(fieldNotes, facilityFieldKeys.capacity, usedFieldNoteKeys);

            const factItems = [];
            if (!isValueEmpty(typeValue)) {
                factItems.push({ label: 'Type', value: escapeHtml(typeValue), note: typeFactNotes.join('; ') });
            }
            if (ageDisplay) {
                factItems.push({ label: 'Ages', value: escapeHtml(ageDisplay), note: ageFactNotes.join('; ') });
            }
            if (!isValueEmpty(genderValue)) {
                factItems.push({ label: 'Gender', value: escapeHtml(toTitleCase(genderValue)), note: genderFactNotes.join('; ') });
            }
            if (!isValueEmpty(capacityValue)) {
                factItems.push({ label: 'Capacity', value: escapeHtml(String(capacityValue)), note: capacityFactNotes.join('; ') });
            }

            const factsHtml = factItems.length
                ? renderDetailSection(
                    'At a glance',
                    `<div class="facility-facts-grid">${
                        factItems.map(item => `
                            <div class="facility-fact">
                                <span class="facility-fact-label">${item.label}</span>
                                <span class="facility-fact-value">${item.value}</span>
                                ${item.note ? `<span class="facility-fact-note">${escapeHtml(item.note)}</span>` : ''}
                            </div>
                        `).join('')
                    }</div>`,
                    'facility-facts-section'
                )
                : '';

            const noteItems = collectUniqueTexts(
                getValueFromKeys(facility, facilityFieldKeys.notes),
                consumeFieldNotesForKeys(fieldNotes, facilityFieldKeys.notes, usedFieldNoteKeys)
            );

            const notesHtml = noteItems.length
                ? renderDetailSection(
                    'Notes',
                    `<div class="facility-note-list">${
                        noteItems.map(item => `<div class="facility-note-item">${escapeHtml(item)}</div>`).join('')
                    }</div>`,
                    'facility-notes-section'
                )
                : '';

            // Build other facility data
            let otherFacilityData = '';

            // Helper to render a single item (handles objects in arrays)
            const renderItemFac = (item) => {
                if (isValueEmpty(item)) return '';
                if (typeof item === 'boolean') return item ? 'Yes' : '';
                if (typeof item === 'string') {
                    // Double-check string isn't a placeholder 
                    if (isValueEmpty(item)) return '';
                    return escapeHtml(item);
                }
                if (typeof item === 'number') return escapeHtml(String(item));
                if (typeof item === 'object' && !Array.isArray(item)) {
                    // Handle clickable links in objects
                    if (item.url && typeof item.url === 'string' && (item.url.startsWith('http') || item.url.startsWith('/'))) {
                        const label = item.displayText || item.name || item.text || item.url;
                        return `<a href="${escapeAttribute(item.url)}" target="_blank" rel="noopener">${escapeHtml(label)}</a>`;
                    }

                    // For objects like {role: "", name: "Scott Hess"}, extract meaningful values
                    const parts = [];
                    Object.entries(item).forEach(([k, v]) => { 
                        if (!isValueEmpty(v)) {
                            // If key is 'name' or 'value', just use the value
                            if (k === 'name' || k === 'value' || k === 'text') {
                                parts.push(escapeHtml(v));     
                            } else if (k !== 'role' || v.trim()) {
                                // Include role only if non-empty
                                parts.push(escapeHtml(v));     
                            }
                        }
                    });
                    return parts.join(' - ');
                }
                return escapeHtml(String(item));
            };

            const suppressedFieldKeys = new Set([
                'identification.name', 'identification.currentName',
                'name', 'programName', 'facilityName', 'title',
                'program_name', 'facility_name',
                'identification.otherNames', 'otherNames',
                'identification.pastNames', 'pastNames',
                'identification.formerNames', 'formerNames',
                'identification.currentOperator', 'currentOperator', 'current_operator',
                'identification.currentOwner', 'identification.currentOwners',
                'identification.current_owner', 'identification.current_owners',
                'identification.currentOwnership', 'identification.current_ownership',
                'currentOwner', 'currentOwners', 'current_owner', 'current_owners',
                'currentOwnership', 'current_ownership',
                'location', 'address', 'cityState', 'city_state',
                'fullAddress', 'full_address', 'city', 'state',
                'locationCity', 'location_city', 'locationState', 'location_state',
                'operatingPeriod.status', 'status',
                'yearsOfOperation', 'yearsActive', 'years_active',
                'operating_period_text', 'founded', 'yearFounded', 'opened',
                'startYear', 'year_founded', 'start_year',
                'operatingPeriod.startYear', 'operatingPeriod.start_year',
                'operatingPeriod.endYear', 'operatingPeriod.end_year',
                ...facilityFieldKeys.type,
                ...facilityFieldKeys.capacity,
                ...facilityFieldKeys.gender,
                ...facilityFieldKeys.ageRangeText,
                ...facilityFieldKeys.ageMin,
                ...facilityFieldKeys.ageMax,
                ...facilityFieldKeys.notes
            ]);

            const shouldSuppressFacilityField = key => {
                if (!key || typeof key !== 'string') return false;
                if (suppressedFieldKeys.has(key)) return true;
                const lower = key.toLowerCase();
                // Ownership keys are shown in header subtext; suppress every nested variant.
                if (/(^|\.)(current_?owners?|current_?owner|current_?ownership|previous_?owners?|previous_?owner|former_?owners?|former_?owner)$/.test(lower)) return true;
                // Broken-out address components duplicate the location in the header
                if (/(^|\.)address_?parts\./.test(lower)) return true;
                // Location-detail sub-fields that duplicate the header location
                if (/^(locationdetails|location_details)\.(city|state|country|zip|zip_?code|postal_?code|street|address|county|lat(itude)?|lng|long(itude)?)$/.test(lower)) return true;
                // Internal database identifiers, slugs, and timestamps
                if (/(^|\.)(facility_?id|location_?id|referrer_?id|source_?project_?id|project_?id|post_?id|wp_?id|master_?id|row_?id|record_?id|parent_?id|_?id|slug|guid|uuid|created_?at|updated_?at|modified_?at|date_?added|date_?modified|sort_?order)$/.test(lower)) return true;
                return false;
            };

            // Function to recursively render all fields from an object
            const renderAllObjectFieldsFac = (obj, prefix = '', depth = 0) => {
                if (!obj || typeof obj !== 'object' || depth > 3) return [];
                const fields = [];

                                                // Skip ONLY special handling keys.

                                                // We want everything else to show in details, even if it repeats header info (completeness > aesthetics)

                                                const skipFullKeys = [

                                                    'resources', // Handled separately

                                                    'fieldNotes', // Handled separately

                                                    'linked_news', // Rendered in the dedicated News section

                                                    'facility_id', // Internal identifier stamped on by the server

                                                    // Internal provenance recorded when a facility is aggregated
                                                    // into a location project (see api/save-master.php). The
                                                    // operator already shows in the header subtext.
                                                    'sourceOperator', 'source_operator',
                                                    'sourceProject', 'source_project',
                                                    'sourceProjectId', 'source_project_id',
                                                    'sourceCategory', 'source_category'

                                                ];

                Object.keys(obj).forEach(key => {
                    const fullKey = prefix ? `${prefix}.${key}` : key;
                    const value = obj[key];

                    // Skip keys already shown in header       
                    if (skipFullKeys.includes(fullKey)) return;

                    // Skip empty values (would show "None" or be blank)
                    if (isValueEmpty(value)) {
                        if (window.kopDebugFields) console.log('[KOP] Suppressed field:', fullKey, '=', JSON.stringify(value));
                        return;
                    }

                    if (Array.isArray(value)) {
                        if (value.length > 0 && !value.every(isValueEmpty)) {
                            const label = formatFieldLabel(fullKey);
                            fields.push({ key: fullKey, label, value: value, isList: true });
                        }
                    } else if (typeof value === 'object' && Object.keys(value).length > 0) {
                        // Recursively add nested object fields
                        fields.push(...renderAllObjectFieldsFac(value, fullKey, depth + 1));
                    } else if (typeof value === 'boolean') {   
                        // Only show boolean true values       
                        if (value) {
                             const label = formatFieldLabel(fullKey);
                             fields.push({ key: fullKey, label, value: 'Yes' });
                        }
                    } else {
                        const label = formatFieldLabel(fullKey);
                        const textValue = cleanText(value);
                        // Double-check: skip empty/placeholder values like "No", "None", etc.
                        if (textValue && !isValueEmpty(textValue)) {
                            fields.push({ key: fullKey, label, value: textValue });
                        }
                    }
                });

                return fields;
            };

            // Get all fields from the facility object
            const facilityFields = renderAllObjectFieldsFac(facility);

            facilityFields.forEach(field => {
                if (shouldSuppressFacilityField(field.key)) return;
                if (isValueEmpty(field.value)) return;

                let renderedValue = '';
                let isMultiColumn = false;

                if (field.isList) {
                    const items = Array.isArray(field.value) ? field.value : [field.value];
                    const validItems = items.filter(i => !isValueEmpty(i));

                    if (!validItems.length) return;

                    // Mark lists (like Staff, Accreditations) as multi-column eligible
                    // if they have more than 1 item or are complex objects
                    if (validItems.length > 1 || typeof validItems[0] === 'object' || ['certifications', 'licensing', 'memberships'].some(k => field.key.toLowerCase().includes(k))) {
                        isMultiColumn = true;
                    }

                    // Check if items look like URLs
                    const isUrlList = validItems.some(item =>  
                        typeof item === 'string' && (item.startsWith('http://') || item.startsWith('https://'))
                    );

                    if (isUrlList) {
                        renderedValue = validItems.map(url => {
                            if (typeof url !== 'string') return '';
                            const safeUrl = escapeAttribute(url);
                            // Shorten displayed URL for readability
                            let displayUrl = url.replace(/^https?:\/\/(www\.)?/, '');
                            if (displayUrl.length > 50) displayUrl = displayUrl.substring(0, 47) + '...';
                            return safeUrl ? `<a href="${safeUrl}" target="_blank" rel="noopener">${escapeHtml(displayUrl)}</a>` : '';
                        }).filter(Boolean).join('<br>');       
                    } else {
                        // Use renderItemFac which handles objects properly
                        renderedValue = validItems.map(item => renderItemFac(item)).filter(Boolean).join(', ');

                        // If it's a list of objects rendered as strings, separate them clearly for grid
                         if (isMultiColumn && typeof validItems[0] === 'object') {
                             renderedValue = validItems.map(item => `<div class="list-item">${renderItemFac(item)}</div>`).join('');
                         }
                    }
                } else {
                    renderedValue = escapeHtml(field.value);
                }

                if (!renderedValue || isValueEmpty(renderedValue)) return;

                const rowClass = isMultiColumn ? 'field-row full-width-grid' : 'field-row';

                if (field.label) {
                    otherFacilityData += `<div class="${rowClass}"><span class="field-label">${escapeHtml(field.label)}</span><span class="field-value">${renderedValue}</span></div>`;      
                    otherFacilityData += renderInlineFieldNotes(field.key, fieldNotes, usedFieldNoteKeys);
                } else {
                    otherFacilityData += `<div class="${rowClass}"><span class="field-value">${renderedValue}</span></div>`;  
                }
            });

            // FileBird Document Matching — match this facility's program folder
            // (often a subfolder nested under its operator's parent folder).
            const documentsHtml = buildDocLibraryHtml(resolveDocFolder(
                facility && facility.documentFolderId,
                facilityHeaderRaw
            ));

            // Build resources available section
            let resourcesSectionHtml = '';
            if (facility.resources) {
                const resources = [];
                const resourceMap = {
                    'hasNews': 'News',
                    'hasPressReleases': 'Press Releases',      
                    'hasInspections': 'Inspections',
                    'hasStateReports': 'State Reports',        
                    'hasRegulatoryFilings': 'Regulatory Filings',
                    'hasLawsuits': 'Lawsuits',
                    'hasSettlements': 'Settlements',
                    'hasViolations': 'Violations',
                    'hasResearch': 'Research',
                    'hasFinancial': 'Financial',
                    'hasNATSAP': 'NATSAP Profile',
                    'hasWebsite': 'Website Screenshots',       
                    'hasOther': 'Other'
                };

                Object.keys(resourceMap).forEach(key => {      
                    if (facility.resources[key] === true) {    
                        resources.push(resourceMap[key]);      
                    }
                });

                if (facility.resources.customResources && facility.resources.customResources.length > 0) {
                    resources.push(...facility.resources.customResources.map(item => cleanText(item)).filter(item => !isValueEmpty(item)));
                }

                if (resources.length > 0) {
                    resourcesSectionHtml = renderDetailSection(
                        'Resources',
                        `<div class="resource-chip-list">${
                            resources.map(item => `<span class="resource-chip">${escapeHtml(item)}</span>`).join('')
                        }</div>`,
                        'facility-resources-section'
                    );
                }
            }

            const documentsSectionHtml = documentsHtml
                ? renderDetailSection(
                    'Documents',
                    `<div class="facility-detail-grid">${documentsHtml}</div>`,
                    'facility-documents-section'
                )
                : '';

            const additionalDetailsHtml = otherFacilityData
                ? renderDetailSection(
                    'More details',
                    `<div class="facility-detail-grid">${otherFacilityData}</div>`,
                    'facility-additional-section'
                )
                : '';

            // News section: server attaches linked_news[] to each nested facility
            // entry whose name matches a facilities_master row (see
            // kop_attach_linked_news_to_projects in inc/database.php).
            const linkedNews = Array.isArray(facility.linked_news) ? facility.linked_news : [];
            let newsSectionHtml = '';
            let latestNewsHtml = '';
            if (linkedNews.length > 0) {
                const formatDate = value => {
                    if (!value) return '';
                    const ts = Date.parse(value);
                    if (isNaN(ts)) return value;
                    return new Date(ts).toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' });
                };

                // Most-recent item, shown on the card itself (before "Learn more").
                // linked_news arrives ordered newest-first from the server.
                const latest = linkedNews[0];
                const latestTitle = escapeHtml(latest.display_title || latest.article_title || '(untitled)');
                const latestTitleHtml = latest.article_url
                    ? `<a href="${escapeAttribute(latest.article_url)}" target="_blank" rel="noopener" class="facility-latest-news-title">${latestTitle}</a>`
                    : `<span class="facility-latest-news-title">${latestTitle}</span>`;
                const latestMeta = [];
                if (latest.publication_name) latestMeta.push(escapeHtml(latest.publication_name));
                if (latest.publication_date) latestMeta.push(escapeHtml(formatDate(latest.publication_date)));
                const moreCount = linkedNews.length - 1;
                latestNewsHtml = `<div class="facility-latest-news">
                        <span class="facility-latest-news-label">📰 Latest news</span>
                        ${latestTitleHtml}
                        ${latestMeta.length ? `<span class="facility-latest-news-meta">${latestMeta.join(' &middot; ')}</span>` : ''}
                        ${moreCount > 0 ? `<span class="facility-latest-news-more">+${moreCount} more below</span>` : ''}
                    </div>`;

                const itemsHtml = linkedNews.map(n => {
                    const title = escapeHtml(n.display_title || n.article_title || '(untitled)');
                    const titleHtml = n.article_url
                        ? `<a href="${escapeAttribute(n.article_url)}" target="_blank" rel="noopener" class="facility-news-title">${title}</a>`
                        : `<span class="facility-news-title">${title}</span>`;
                    const metaParts = [];
                    if (n.publication_name) metaParts.push(escapeHtml(n.publication_name));
                    if (n.publication_date) metaParts.push(escapeHtml(formatDate(n.publication_date)));
                    const metaHtml = metaParts.length
                        ? `<div class="facility-news-meta">${metaParts.join(' &middot; ')}</div>`
                        : '';
                    return `<li class="facility-news-item">${titleHtml}${metaHtml}</li>`;
                }).join('');
                newsSectionHtml = renderDetailSection(
                    `News (${linkedNews.length})`,
                    `<ul class="facility-news-list">${itemsHtml}</ul>`,
                    'facility-news-section'
                );
            }

            const facilityDatasetNameRaw = cleanText(identification.name) || cleanText(identification.currentName) || cleanText(facilityHeaderRaw) || 'Unnamed Facility';
            const facilityDatasetName = escapeAttribute(facilityDatasetNameRaw);

            // Build searchable text: facility name + former names + aliases + location
            const searchParts = [facilityDatasetNameRaw];
            formerNames.forEach(n => searchParts.push(n));
            otherNames.forEach(n => searchParts.push(n));
            const facLocation = getMergedLocation(facility);
            if (facLocation) searchParts.push(facLocation);
            const facilitySearchText = escapeAttribute(searchParts.join(' | '));

            // Cross-reference rows for this facility's past/alternate names,
            // pointing back to its card inside this operator's section. The
            // facility's previous operators/owners ride along — the old name
            // is usually remembered together with who ran it at the time.
            // (The data doesn't record which owner goes with which name, so
            // the tile lists them jointly.)
            const aliasPastOperators = collectUniqueTexts(previousOwnerList)
                .filter(op => normalizeTextKey(op) !== normalizeTextKey(operatorName))
                .slice(0, 3);
            collectUniqueTexts(normalizedFormerNames, aliasNames).forEach(alias => {
                if (normalizeTextKey(alias) === normalizeTextKey(facilityDatasetNameRaw)) return;
                aliasEntries.push({
                    alias,
                    target: operatorName,
                    facility: facilityDatasetNameRaw,
                    pastOperators: aliasPastOperators
                });
            });

            // Only render the "Learn more" disclosure when there's actually
            // expanded content — otherwise empty facility cards show a button
            // that opens to nothing.
            const facilityExtraContent = [
                factsHtml,
                notesHtml,
                newsSectionHtml,
                resourcesSectionHtml,
                documentsSectionHtml,
                additionalDetailsHtml
            ].join('');
            const hasExtraContent = facilityExtraContent.trim() !== '';

            const facilityDetailsHtml = hasExtraContent
                ? `<div class="facility-details">
                        <details class="facility-expanded-info">
                            <summary><span class="closed-text">+ Learn more</span><span class="open-text">- Collapse details</span></summary>
                            <div class="facility-extra-content">${facilityExtraContent}</div>
                        </details>
                    </div>`
                : '';

            html += `<div class="facility-card status-${statusClass}" data-facility="${facilityDatasetName}" data-search="${facilitySearchText}" data-status="${statusClass}">
                    <div class="facility-summary">
                        <h3 class="facility-name">${facilityHeader}</h3>
                        ${otherNamesHtml}
                        ${facilityLocation ? `<p class="facility-location">${facilityLocation}</p>` : ''}
                        ${yearRange ? `<p class="facility-years">${yearRange}</p>` : ''}
                        ${hasStatus ? `<p class="facility-status">
                            <span class="status-badge status-${statusClass}">${statusLabel}</span>
                        </p>` : ''}
                    </div>
                    ${latestNewsHtml}
                    ${facilityDetailsHtml}
                </div>`;
        });

        html += '</div>' +
            '</details>';
    });

    // Emit the cross-reference rows. They share .operator-section and
    // data-operator so the A–Z sort and alphabet filter slot them in
    // alphabetically under the OLD name. Aliases that match a real operator
    // entry are skipped (the real entry already covers them).
    const seenAliasKeys = new Set();
    aliasEntries.forEach(entry => {
        const aliasKey = normalizeTextKey(entry.alias);
        if (!aliasKey || renderedOperatorKeys.has(aliasKey)) return;
        const dedupeKey = aliasKey + '→' + normalizeTextKey(entry.target) + '→' + normalizeTextKey(entry.facility);
        if (seenAliasKeys.has(dedupeKey)) return;
        seenAliasKeys.add(dedupeKey);

        const showFacility = entry.facility
            && normalizeTextKey(entry.facility) !== normalizeTextKey(entry.target);
        const targetLabel = showFacility
            ? `${entry.target} › ${entry.facility}`
            : entry.target;

        const pastOperators = Array.isArray(entry.pastOperators) ? entry.pastOperators : [];
        const pastOperatorHtml = pastOperators.length
            ? `<span class="alias-entry-operator">past operator: ${escapeHtml(pastOperators.join(', '))}</span>`
            : '';

        html += `<div class="operator-section alias-entry" data-operator="${escapeAttribute(entry.alias)}"
                data-alias-target="${escapeAttribute(entry.target)}"
                data-alias-facility="${escapeAttribute(entry.facility || '')}"
                data-alias-operators="${escapeAttribute(pastOperators.join(' | '))}">
                <button type="button" class="alias-entry-row">
                    <span class="alias-entry-name">${escapeHtml(entry.alias)}</span>
                    ${pastOperatorHtml}
                    <span class="alias-entry-note">former / alternate name — see <strong>${escapeHtml(targetLabel)}</strong> →</span>
                </button>
            </div>`;
    });

    html += '</div>';        container.innerHTML = html;

        attachDocumentButtons(container);

        attachAliasEntryLinks(container);

        loadLinkPreviews(container);

        // Store data globally for filtering
    window.facilitiesData = facilitiesData;
}


// Clicking a cross-reference row jumps to (and opens) the current profile it
// points at, scrolling to the specific facility card when one is named.
function attachAliasEntryLinks(container) {
    container.querySelectorAll('.alias-entry .alias-entry-row').forEach(btn => {
        btn.addEventListener('click', () => {
            const entry = btn.closest('.alias-entry');
            const targetName = entry.dataset.aliasTarget || '';
            const facilityName = entry.dataset.aliasFacility || '';

            let target = null;
            document.querySelectorAll('.operator-section:not(.alias-entry)').forEach(sec => {
                if (!target && sec.dataset.operator === targetName) target = sec;
            });
            if (!target) return;

            // The target may be hidden by an active search/filter — reveal it.
            target.style.display = 'block';
            target.open = true;

            let scrollEl = target;
            if (facilityName) {
                target.querySelectorAll('.facility-card').forEach(card => {
                    if (scrollEl === target && card.dataset.facility === facilityName) {
                        card.style.display = 'block';
                        scrollEl = card;
                    }
                });
            }
            scrollEl.scrollIntoView({ behavior: 'smooth', block: 'start' });
            scrollEl.classList.add('alias-jump-highlight');
            setTimeout(() => scrollEl.classList.remove('alias-jump-highlight'), 2200);
        });
    });
}

function filterFacilities() {
    const searchInput = document.getElementById('searchInput');
    const searchTerm = searchInput ? searchInput.value.toLowerCase() : '';
    const statusFilterSelect = document.getElementById('statusFilter');
    const statusFilter = statusFilterSelect ? statusFilterSelect.value : '';
    const letterFilter = (window.currentLetterFilter || '').toLowerCase();

    const operatorSections = document.querySelectorAll('.operator-section');

    operatorSections.forEach(section => {
        const operatorName = section.dataset.operator.toLowerCase();

        // Cross-reference rows: no facility cards or status of their own.
        // Match on the old name (and its target); hide under a status filter.
        if (section.classList.contains('alias-entry')) {
            const aliasSearch = (operatorName + ' '
                + (section.dataset.aliasTarget || '') + ' '
                + (section.dataset.aliasFacility || '') + ' '
                + (section.dataset.aliasOperators || '')).toLowerCase();
            const show = (!letterFilter || operatorName.startsWith(letterFilter))
                && aliasSearch.includes(searchTerm)
                && !statusFilter;
            section.style.display = show ? 'block' : 'none';
            return;
        }
        // Includes the organization's alternate/past names, not just the
        // display name, so old org names still match.
        const operatorSearch = (section.dataset.operatorSearch || section.dataset.operator || '').toLowerCase();
        const facilityCards = section.querySelectorAll('.facility-card');
        let visibleFacilities = 0;

        // Alphabet filter matches on the FIRST letter of the operator name —
        // substring matching showed every record merely containing the letter.
        const matchesLetter = !letterFilter || operatorName.startsWith(letterFilter);

        facilityCards.forEach(card => {
            const facilitySearch = (card.dataset.search || card.dataset.facility || '').toLowerCase();
            const facilityStatus = card.dataset.status;

            const matchesSearch = operatorSearch.includes(searchTerm) || facilitySearch.includes(searchTerm);
            const matchesStatus = !statusFilter || facilityStatus === statusFilter;

            if (matchesLetter && matchesSearch && matchesStatus) {
                card.style.display = 'block';
                visibleFacilities++;
            } else {
                card.style.display = 'none';
            }
        });

        // Hide operator section if no facilities match        
        section.style.display = visibleFacilities > 0 ? 'block' : 'none';

        // Update facility count
        const countSpan = section.querySelector('.facility-count');
        if (countSpan) {
            countSpan.textContent = `(${visibleFacilities} facilities)`;
        }
    });
}


// Functions for your template features
function clearSearch() {
    const searchInput = document.getElementById('searchInput');
    const statusFilter = document.getElementById('statusFilter');
    const sortBy = document.getElementById('sortBy');
    const clearButton = document.getElementById('clearSearch');

    if (searchInput) searchInput.value = '';
    if (statusFilter) statusFilter.value = '';
    // The select has no ''-valued option; reset to its first (default) option.
    if (sortBy) sortBy.selectedIndex = 0;
    if (clearButton) clearButton.style.display = 'none';
    window.currentLetterFilter = '';
    filterFacilities();
}

// Expose to global scope for inline onclick handlers
window.clearSearch = clearSearch;

window.loadFacilityDocuments = async function(folderId, containerOrId) {
    const container = (containerOrId instanceof Element)
        ? containerOrId
        : document.getElementById(containerOrId);
    if (!container) return;

    const parsedId = parseInt(folderId, 10);
    if (!Number.isFinite(parsedId)) {
        console.warn('Facilities script: invalid folder id', folderId);
        return;
    }

    const restBase = getRestBase();

    // Keep the label, show loading in value
    const labelSpan = container.querySelector('.field-label') ? container.querySelector('.field-label').outerHTML : '';
    container.innerHTML = `${labelSpan}<span class="field-value">Loading Document Library...</span>`;

    try {
        // Use the shortcode renderer to get the full FileBird library display
        const response = await fetch(`${restBase}render-folder-shortcode?id=${parsedId}`);
        if (!response.ok) throw new Error('Failed to load library');
        const data = await response.json();

        if (!data.html || data.html.trim() === '') {
             // Fallback to simple list if shortcode returns nothing
             await loadFacilityDocumentsFallback(parsedId, container);
             return;
        }

        container.innerHTML = `<div class="filebird-library-container" style="grid-column: 1 / -1; margin-top: 10px;">${data.html}</div>`;

    } catch (e) {
        console.warn('Shortcode render failed, trying fallback list...', e);
        await loadFacilityDocumentsFallback(parsedId, container);
    }
};



const fetchLinkPreview = async url => {
    if (!url) return null;
    if (linkPreviewCache.has(url)) return linkPreviewCache.get(url);
    if (linkPreviewInflight.has(url)) return linkPreviewInflight.get(url);

    const restBase = getRestBase();
    const requestUrl = `${restBase}link-preview?url=${encodeURIComponent(url)}`;
    const promise = fetch(requestUrl, { credentials: 'same-origin' })
        .then(response => response.ok ? response.json() : null)
        .catch(() => null);

    linkPreviewInflight.set(url, promise);
    const data = await promise;
    linkPreviewInflight.delete(url);

    if (data && typeof data === 'object') {
        linkPreviewCache.set(url, data);
        return data;
    }

    return null;
};

const updateLinkPreviewCard = (card, preview, url) => {
    if (!card) return;
    const titleEl = card.querySelector('.field-note-link-title');
    const domainEl = card.querySelector('.field-note-link-domain');
    const thumbEl = card.querySelector('.field-note-link-thumb');

    const fallbackDomain = url.replace(/^https?:\/\//i, '').split('/')[0];
    const title = (preview && preview.title) ? preview.title : (fallbackDomain || url);
    const site = (preview && preview.site_name) ? preview.site_name : (fallbackDomain || '');

    if (titleEl) titleEl.textContent = title;
    if (domainEl) domainEl.textContent = site || fallbackDomain || '';

    if (thumbEl) {
        if (preview && preview.image) {
            const safeImage = escapeHtmlValue(preview.image);
            const safeAlt = escapeHtmlValue(title);
            thumbEl.innerHTML = `<img src="${safeImage}" alt="${safeAlt}">`;
        } else {
            const placeholderText = escapeHtmlValue(site || fallbackDomain || 'Link');
            thumbEl.innerHTML = `<span class="field-note-link-placeholder">${placeholderText}</span>`;
        }
    }
};


let docModalHandlersBound = false;

function ensureDocumentModal() {
    let modal = document.getElementById('kop-doc-modal');
    if (modal) return modal;

    modal = document.createElement('div');
    modal.id = 'kop-doc-modal';
    modal.className = 'kop-doc-modal';
    modal.setAttribute('aria-hidden', 'true');
    modal.innerHTML = `
        <div class="kop-doc-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="kop-doc-modal-title">
            <div class="kop-doc-modal__header">
                <h3 class="kop-doc-modal__title" id="kop-doc-modal-title">Document</h3>
                <button type="button" class="kop-doc-modal__close" aria-label="Close">&times;</button>
            </div>
            <div class="kop-doc-modal__body"></div>
            <div class="kop-doc-modal__footer">
                <a class="kop-doc-modal__btn kop-doc-modal__open" href="#" target="_blank" rel="noopener">Open in new tab</a>
                <a class="kop-doc-modal__btn kop-doc-modal__download" href="#" download>Download</a>
            </div>
        </div>
    `;

    document.body.appendChild(modal);

    const close = () => closeDocumentModal(modal);
    const closeBtn = modal.querySelector('.kop-doc-modal__close');
    if (closeBtn) closeBtn.addEventListener('click', close);
    modal.addEventListener('click', event => {
        if (event.target === modal) close();
    });

    document.addEventListener('keydown', event => {
        if (event.key === 'Escape' && modal.classList.contains('is-open')) {
            close();
        }
    });

    return modal;
}

function closeDocumentModal(modal) {
    const target = modal || document.getElementById('kop-doc-modal');
    if (!target) return;
    target.classList.remove('is-open');
    target.setAttribute('aria-hidden', 'true');
    document.body.classList.remove('kop-doc-modal-open');
}

function getFileExtension(url) {
    if (!url || typeof url !== 'string') return '';
    const cleaned = url.split('#')[0].split('?')[0];
    const parts = cleaned.split('.');
    if (parts.length < 2) return '';
    return parts.pop().toLowerCase();
}

function openDocumentModalFromLink(link) {
    if (!link) return;

    const url = link.getAttribute('href') || '';
    if (!url) return;

    const title = (link.dataset && link.dataset.title)
        ? link.dataset.title
        : (link.querySelector('.doc-title') ? link.querySelector('.doc-title').textContent.trim() : 'Document');

    const mime = (link.dataset && link.dataset.mime) ? link.dataset.mime : '';
    const ext = getFileExtension(url);
    const thumb = (link.dataset && link.dataset.thumb)
        ? link.dataset.thumb
        : (link.querySelector('.doc-thumbnail img') ? link.querySelector('.doc-thumbnail img').getAttribute('src') : '');

    const isPdf = ext === 'pdf' || mime === 'application/pdf';
    const isImage = (mime && mime.indexOf('image/') === 0) || ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg'].includes(ext);
    const isVideo = (mime && mime.indexOf('video/') === 0) || ['mp4', 'webm', 'mov', 'm4v', 'avi'].includes(ext);
    const isAudio = (mime && mime.indexOf('audio/') === 0) || ['mp3', 'wav', 'ogg', 'm4a'].includes(ext);

    const modal = ensureDocumentModal();
    const titleEl = modal.querySelector('.kop-doc-modal__title');
    const bodyEl = modal.querySelector('.kop-doc-modal__body');
    const openLink = modal.querySelector('.kop-doc-modal__open');
    const downloadLink = modal.querySelector('.kop-doc-modal__download');

    if (titleEl) titleEl.textContent = title || 'Document';
    if (openLink) openLink.href = url;
    if (downloadLink) downloadLink.href = url;

    if (bodyEl) {
        bodyEl.innerHTML = '';
        const viewer = document.createElement('div');
        viewer.className = 'kop-doc-modal__viewer';

        if (isPdf) {
            const iframe = document.createElement('iframe');
            iframe.src = url;
            iframe.setAttribute('title', title || 'PDF preview');
            iframe.setAttribute('loading', 'lazy');
            viewer.appendChild(iframe);
        } else if (isImage) {
            const img = document.createElement('img');
            img.src = url;
            img.alt = title || 'Image preview';
            viewer.appendChild(img);
        } else if (isVideo) {
            const video = document.createElement('video');
            video.controls = true;
            video.src = url;
            viewer.appendChild(video);
        } else if (isAudio) {
            const audio = document.createElement('audio');
            audio.controls = true;
            audio.src = url;
            viewer.appendChild(audio);
        } else if (thumb) {
            const img = document.createElement('img');
            img.src = thumb;
            img.alt = title || 'Document preview';
            viewer.appendChild(img);
        } else {
            const placeholder = document.createElement('div');
            placeholder.className = 'kop-doc-modal__placeholder';
            placeholder.textContent = 'Preview not available.';
            viewer.appendChild(placeholder);
        }

        bodyEl.appendChild(viewer);
    }

    modal.classList.add('is-open');
    modal.setAttribute('aria-hidden', 'false');
    document.body.classList.add('kop-doc-modal-open');
}

function attachDocumentModalHandlers() {
    if (docModalHandlersBound) return;
    docModalHandlersBound = true;

    document.addEventListener('click', event => {
        const link = event.target && event.target.closest ? event.target.closest('.tti-program-index-wrapper .doc-link') : null;
        if (!link) return;
        if (link.dataset && link.dataset.kopNoModal === 'true') return;
        if (event.metaKey || event.ctrlKey || event.shiftKey || event.button === 1) return;

        event.preventDefault();
        openDocumentModalFromLink(link);
    });
}

function loadLinkPreviews(scope) {
    const root = scope && scope.querySelectorAll ? scope : document;
    const cards = root.querySelectorAll('.field-note-link-card[data-url]');
    cards.forEach(card => {
        if (card.dataset && card.dataset.previewLoaded === 'true') return;
        if (card.dataset) card.dataset.previewLoaded = 'true';
        const url = card.dataset ? card.dataset.url : '';
        if (!url) return;
        fetchLinkPreview(url).then(preview => {
            updateLinkPreviewCard(card, preview, url);
        });
    });
}

function attachDocumentButtons(scope) {
    const root = scope && scope.querySelectorAll ? scope : document;
    const buttons = root.querySelectorAll('.doc-library-button');
    buttons.forEach(button => {
        if (button.dataset && button.dataset.kopBound === 'true') return;
        if (button.dataset) button.dataset.kopBound = 'true';
        button.addEventListener('click', event => {
            event.preventDefault();
            const folderId = button.dataset ? button.dataset.folderId : '';
            const container = button.closest('[data-documents-container="true"], .field-row');
            if (typeof window.loadFacilityDocuments === 'function') {
                window.loadFacilityDocuments(folderId, container);
            } else {
                console.warn('Facilities script: loadFacilityDocuments is not available');
            }
        });
    });
}

async function loadFacilityDocumentsFallback(folderId, container) {
    try {
        const restBase = getRestBase();
        const response = await fetch(`${restBase}folder-content?id=${folderId}`);
        if (!response.ok) throw new Error('Failed to load content');
        const files = await response.json();

        const labelSpan = container.querySelector('.field-label') ? container.querySelector('.field-label').outerHTML : '';

        if (Array.isArray(files) && files.length === 0) {
            container.innerHTML = `${labelSpan}<span class="field-value">No documents found.</span>`;
            return;
        }

        const safeFiles = Array.isArray(files) ? files : [];
        const fileItems = safeFiles.map(file => {
            if (!file || typeof file !== 'object') return '';

            const title = escapeHtmlValue(toTitleCase(file.title || 'Document'));
            const rawUrl = typeof file.url === 'string' ? file.url : '';
            const url = escapeHtmlValue(rawUrl || '');
            const mime = typeof file.mime_type === 'string' ? file.mime_type : '';
            const isImage = mime.includes('image');
            const extRaw = rawUrl.split('.').pop() ? rawUrl.split('.').pop().split('?')[0].split('#')[0] : '';
            const extText = escapeHtmlValue(extRaw ? extRaw.toUpperCase() : 'FILE');
            const extClass = extRaw ? extRaw.toLowerCase().replace(/[^a-z0-9]/g, '') : 'file';
            const date = file.date ? new Date(file.date).toLocaleDateString() : '';
            const dateHtml = date ? `<span class="doc-meta">${escapeHtmlValue(date)}</span>` : '';
            const thumbUrlRaw = file.thumb_url || file.thumbnail_url || (isImage ? rawUrl : '');
            const thumbUrl = escapeHtmlValue(thumbUrlRaw || '');
            const hasThumb = thumbUrl.length > 0;
            const thumbHtml = hasThumb
                ? `<img src="${thumbUrl}" alt="${title}">`
                : `<span class="doc-icon doc-icon-${extClass}">${extText}</span>`;

            return `
                <li class="doc-item" data-title="${title}">
                    <a href="${url}" class="doc-link" target="_blank" rel="noopener" data-title="${title}" data-mime="${escapeHtmlValue(mime)}" data-thumb="${thumbUrl}">
                        <div class="doc-thumbnail">${thumbHtml}</div>
                        <div class="doc-info">
                            <span class="doc-title">${title}</span>
                            ${dateHtml}
                        </div>
                    </a>
                </li>
            `;
        }).filter(Boolean).join('');

        const listHtml = `<ul class="doc-list doc-layout-grid">${fileItems}</ul>`;
        container.innerHTML = `${labelSpan}<span class="field-value">${listHtml}</span>`;
    } catch (e) {
        console.error(e);
        container.innerHTML = `<span class="field-value" style="color:#d45500">Error loading documents.</span>`;
    }
}

function setupAlphabetFilter() {
    const alphabetFilter = document.getElementById('alphabet-filter');
    if (!alphabetFilter) return;

    const letters = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ'.split('');    
    alphabetFilter.innerHTML = letters.map(letter =>
        `<button onclick="filterByLetter('${letter}')">${letter}</button>`
    ).join('') + '<button onclick="filterByLetter(\'\')">All</button>';
}

function filterByLetter(letter) {
    // Track the letter separately instead of stuffing it into the search box
    // (which substring-matched it anywhere in the name/location).
    window.currentLetterFilter = letter || '';
    filterFacilities();
}

// Expose to global scope for inline onclick handlers
window.filterByLetter = filterByLetter;

function setupEventListeners() {
    attachDocumentModalHandlers();
    // Setup search input
    const searchInput = document.getElementById('searchInput');
    if (searchInput) {
        searchInput.addEventListener('keyup', function() {     
            const clearBtn = document.getElementById('clearSearch');
            if (clearBtn) {
                clearBtn.style.display = this.value ? 'inline-block' : 'none';
            }
            filterFacilities();
        });
    }

    // Setup status filter
    const statusFilter = document.getElementById('statusFilter');
    if (statusFilter) {
        statusFilter.addEventListener('change', filterFacilities);
    }

    // Setup sort dropdown
    const sortBy = document.getElementById('sortBy');
    if (sortBy) {
        sortBy.addEventListener('change', handleSort);
    }
}

function handleSort() {
    const sortDropdown = document.getElementById('sortBy');
    const sortValue = sortDropdown ? sortDropdown.value : 'name';
    const operatorSections = Array.from(document.querySelectorAll('.operator-section'));
    const container = document.querySelector('.facilities-database');

    if (!container) return;

    // Local comparator: compareDisplayText is scoped inside displayFacilities
    // and is not reachable from this top-level function.
    const compareOperatorText = (a, b) => {
        const textA = (a || '').toString().trim();
        const textB = (b || '').toString().trim();
        if (!textA && !textB) return 0;
        if (!textA) return 1;
        if (!textB) return -1;
        return textA.localeCompare(textB, undefined, { numeric: true, sensitivity: 'base' });
    };

    switch(sortValue) {
        case 'name':
            operatorSections.sort((a, b) => compareOperatorText(a.dataset.operator, b.dataset.operator));
            break;
        case 'violations-only':
            // Filter to show only facilities with violations (you'll need to add violation data to your JSON)
            operatorSections.forEach(section => {
                const facilities = section.querySelectorAll('.facility-card');
                let hasViolations = false;
                facilities.forEach(facility => {
                    // Check if facility has violations in the resources or add violation indicator
                    const violationText = facility.textContent.toLowerCase();
                    if (violationText.includes('violation') || violationText.includes('violations')) {
                        hasViolations = true;
                    }
                });
                section.style.display = hasViolations ? 'block' : 'none';
            });
            return;
        case 'violations-desc':
            // Sort by most violations (you'll need violation count in your data)
            break;
        case 'recent-inspection':
            // Sort by recent inspection (you'll need inspection dates in your data)
            break;
        default:
            // Default A-Z sort
            operatorSections.sort((a, b) => compareOperatorText(a.dataset.operator, b.dataset.operator));
    }

    // Re-append sorted sections
    operatorSections.forEach(section => container.appendChild(section));
}

function toggleAllFacilityDetails(button) {
    const operatorSection = button.closest('.operator-section');
    const facilityDetails = operatorSection.querySelectorAll('.facility-expanded-info');
    const isExpanding = button.textContent.includes('Expand'); 

    facilityDetails.forEach(detail => {
        detail.open = isExpanding;
    });

    button.textContent = isExpanding ? 'Collapse All Facility Details' : 'Expand All Facility Details';
}

// Add this to the end of your facilities-display.js file      
document.addEventListener('DOMContentLoaded', function() {     
    console.log('Facilities script loaded');

    const facilitiesContainer = document.getElementById('facilities-container');
    if (!facilitiesContainer) {
        console.info('Facilities script: no facilities-container element present, skipping data fetch.');
        return;
    }

    // Setup your template features when the container exists  
    setupAlphabetFilter();
    setupEventListeners();

    const facilitiesConfig = window.facilitiesConfig || {};    
    const configUrls = Array.isArray(facilitiesConfig.jsonFileUrls) ? facilitiesConfig.jsonFileUrls : [];
    const defaultDatasetPath = '/wp-content/themes/child/js/data/facilities_master.json';
    const datasetCandidates = Array.from(new Set([
        facilitiesConfig.jsonDataUrl,
        ...configUrls,
        defaultDatasetPath
    ].filter(url => typeof url === 'string' && url.trim().length > 0)));

    if (!datasetCandidates.length) {
        console.error('Facilities script: no dataset URLs are configured.');
        facilitiesContainer.innerHTML = '<p>Error loading facilities data: no dataset URL is configured.</p>';
        return;
    }

    const decodeResponseAsJson = async (response) => {
        const text = await response.text();

        try {
            return JSON.parse(text);
        } catch (parseError) {
            throw new Error('Invalid JSON (' + parseError.message + ')');
        }
    };

    (async () => {
        const failureSummaries = [];

        for (const candidateUrl of datasetCandidates) {        
            try {
                console.log('Facilities script: attempting to load data from', candidateUrl);
                const response = await fetch(candidateUrl, { credentials: 'same-origin' });

                if (!response.ok) {
                    throw new Error('HTTP ' + response.status + ' ' + response.statusText);
                }

                const data = await decodeResponseAsJson(response);
                const facilityCount = data && data.projects ? Object.keys(data.projects).length : '?';
                console.log('[KOP] Data source:', candidateUrl, '| projects:', facilityCount);

                // NEW: Load FileBird folders for document matching
                try {
                    const restBase = getRestBase();
                    const foldersResponse = await fetch(`${restBase}folders`);
                    if (foldersResponse.ok) {
                        window.filebirdFolders = await foldersResponse.json();
                        console.log('Facilities script: loaded ' + (window.filebirdFolders ? window.filebirdFolders.length : 0) + ' folders.');
                    }
                } catch (e) {
                    console.warn('Facilities script: failed to load FileBird folders', e);
                }

                displayFacilities(data, 'facilities-container');

                // Re-setup event listeners after data is loaded to ensure they work
                setupEventListeners();

                // Deep link: ?search= (used by the site search results page)
                // pre-fills the filter once the facilities are rendered.
                const initialSearch = new URLSearchParams(window.location.search).get('search');
                if (initialSearch) {
                    const searchInput = document.getElementById('searchInput');
                    if (searchInput) {
                        searchInput.value = initialSearch;
                        const clearBtn = document.getElementById('clearSearch');
                        if (clearBtn) clearBtn.style.display = 'inline-block';
                        filterFacilities();
                    }
                }

                return;
            } catch (candidateError) {
                console.warn('Facilities script: failed to load dataset from', candidateUrl, candidateError);
                failureSummaries.push(candidateUrl + ' → ' + candidateError.message);
            }
        }

        const summaryMessage = failureSummaries.length
            ? 'Tried ' + failureSummaries.length + ' URL(s): ' + failureSummaries.join('; ')
            : 'No dataset URLs were available.';

        console.error('Facilities script: unable to load facilities data. ' + summaryMessage);
        facilitiesContainer.innerHTML = '<p>Error loading facilities data. ' + summaryMessage + '</p>';
    })();
});

})();
