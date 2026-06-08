
// Location Index Display
// Styled to match TTI Program Index (Grouped by State/Country)

(function() {

const getRestBase = () => {
    const configured = (window.locationIndexConfig && typeof window.locationIndexConfig.restUrl === 'string')
        ? window.locationIndexConfig.restUrl.trim()
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
        '"': '&quot;',
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

function attachDocumentButtons(scope) {
    const root = scope && scope.querySelectorAll ? scope : document;
    const buttons = root.querySelectorAll('.doc-library-button');
    buttons.forEach(button => {
        if (button.dataset && button.dataset.kopBound === 'true') return;
        if (button.dataset) button.dataset.kopBound = 'true';
        button.addEventListener('click', event => {
            event.preventDefault();
            const folderId = button.dataset ? button.dataset.folderId : '';
            const container = button.closest('.field-row');
            if (typeof window.loadFacilityDocuments === 'function') {
                window.loadFacilityDocuments(folderId, container);
            } else {
                console.warn('Location index: loadFacilityDocuments is not available');
            }
        });
    });
}

async function loadFacilityDocumentsFallback(folderId, container) {
    try {
        const restBase = getRestBase();
        const response = await fetch(`${restBase}folder-content?id=${folderId}&merge=name`, { credentials: 'same-origin' });
        if (!response.ok) throw new Error('Failed to load content');
        const files = await response.json();

        const labelSpan = container.querySelector('.field-label') ? container.querySelector('.field-label').outerHTML : '<span class="field-label">Documents</span>';

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

window.loadFacilityDocuments = async function(folderId, containerOrId) {
    const container = (containerOrId instanceof Element)
        ? containerOrId
        : document.getElementById(containerOrId);
    if (!container) return;

    const parsedId = parseInt(folderId, 10);
    if (!Number.isFinite(parsedId)) {
        console.warn('Location index: invalid folder id', folderId);
        return;
    }

    const restBase = getRestBase();
    const labelSpan = container.querySelector('.field-label') ? container.querySelector('.field-label').outerHTML : '<span class="field-label">Documents</span>';
    container.innerHTML = `${labelSpan}<span class="field-value">Loading Document Library...</span>`;

    try {
        const response = await fetch(`${restBase}render-folder-shortcode?id=${parsedId}&merge=name`, { credentials: 'same-origin' });
        if (!response.ok) throw new Error('Failed to load library');
        const data = await response.json();

        if (!data.html || data.html.trim() === '') {
            await loadFacilityDocumentsFallback(parsedId, container);
            return;
        }

        container.innerHTML = `<div class="filebird-library-container" style="grid-column: 1 / -1; margin-top: 10px;">${data.html}</div>`;
    } catch (e) {
        console.warn('Location index: shortcode render failed, trying fallback list...', e);
        await loadFacilityDocumentsFallback(parsedId, container);
    }
};

document.addEventListener('DOMContentLoaded', function() {
    const containerId = 'locations-container';
    const searchInput = document.getElementById('searchInput');
    const typeFilter = document.getElementById('typeFilter');
    const sortBy = document.getElementById('sortBy');
    const clearSearchBtn = document.getElementById('clearSearch');
    const alphabetFilter = document.getElementById('alphabet-filter');

    let allLocations = [];
    let filteredLocations = [];

    const US_STATES = new Set([
        'ALABAMA', 'ALASKA', 'ARIZONA', 'ARKANSAS', 'CALIFORNIA', 'COLORADO', 'CONNECTICUT',
        'DELAWARE', 'FLORIDA', 'GEORGIA', 'HAWAII', 'IDAHO', 'ILLINOIS', 'INDIANA', 'IOWA',
        'KANSAS', 'KENTUCKY', 'LOUISIANA', 'MAINE', 'MARYLAND', 'MASSACHUSETTS', 'MICHIGAN',
        'MINNESOTA', 'MISSISSIPPI', 'MISSOURI', 'MONTANA', 'NEBRASKA', 'NEVADA', 'NEW HAMPSHIRE',
        'NEW JERSEY', 'NEW MEXICO', 'NEW YORK', 'NORTH CAROLINA', 'NORTH DAKOTA', 'OHIO',
        'OKLAHOMA', 'OREGON', 'PENNSYLVANIA', 'RHODE ISLAND', 'SOUTH CAROLINA', 'SOUTH DAKOTA',
        'TENNESSEE', 'TEXAS', 'UTAH', 'VERMONT', 'VIRGINIA', 'WASHINGTON', 'WEST VIRGINIA',
        'WISCONSIN', 'WYOMING'
    ]);

    const normalizeFolderMatchText = value => cleanText(value).toLowerCase().replace(/[^\w\s]/g, '').trim();

    const findMatchingFilebirdFolder = facilityName => {
        if (!window.filebirdFolders || !Array.isArray(window.filebirdFolders)) return null;

        if (!window.filebirdFolderMap) {
            window.filebirdFolderMap = {};
            window.filebirdFolders.forEach(folder => {
                window.filebirdFolderMap[String(folder.id)] = folder;
            });
        }

        const rawFacilityName = cleanText(facilityName).toLowerCase().trim();
        const normalizedFacilityName = normalizeFolderMatchText(rawFacilityName);
        if (!rawFacilityName || !normalizedFacilityName) return null;

        let matchingFolder = null;
        try {
            matchingFolder = window.filebirdFolders.find(folder => folder.name && folder.name.toLowerCase().trim() === rawFacilityName);

            if (!matchingFolder) {
                matchingFolder = window.filebirdFolders.find(folder => folder.name && normalizeFolderMatchText(folder.name.toLowerCase()) === normalizedFacilityName);
            }

            if (!matchingFolder && normalizedFacilityName.length > 6) {
                matchingFolder = window.filebirdFolders.find(folder => {
                    if (!folder.name) return false;
                    const normalizedFolderName = normalizeFolderMatchText(folder.name.toLowerCase());
                    return normalizedFolderName.includes(normalizedFacilityName);
                });
            }

            if (!matchingFolder) {
                matchingFolder = window.filebirdFolders.find(folder => {
                    if (!folder.name) return false;
                    const normalizedFolderName = normalizeFolderMatchText(folder.name.toLowerCase());
                    return normalizedFolderName.length > 6 && normalizedFacilityName.includes(normalizedFolderName);
                });
            }

            if (!matchingFolder && window.filebirdFolderMap) {
                matchingFolder = window.filebirdFolders.find(folder => {
                    if (!folder.name) return false;
                    const parentId = String(folder.parent || '0');
                    if (parentId === '0') return false;
                    const parentFolder = window.filebirdFolderMap[parentId];
                    if (!parentFolder || !parentFolder.name) return false;
                    const pathWords = (normalizeFolderMatchText(parentFolder.name.toLowerCase()) + ' ' + normalizeFolderMatchText(folder.name.toLowerCase()))
                        .split(/\s+/)
                        .filter(word => word.length > 2);
                    if (pathWords.length < 2) return false;
                    return pathWords.every(word => normalizedFacilityName.includes(word));
                });
            }
        } catch (error) {
            console.warn('[KOP] Folder matching error for facility "' + rawFacilityName + '":', error);
        }

        return matchingFolder;
    };

    // --- Shared utility helpers for rendering ---

    const toArray = value => Array.isArray(value) ? value : [];

    const cleanText = value => {
        if (typeof value === 'string') return value.trim();
        if (typeof value === 'number') return String(value);
        return '';
    };

    const isValueEmpty = (value) => {
        if (value === null || value === undefined) return true;
        if (typeof value === 'boolean') return !value; 
        if (typeof value === 'string') {
            const lower = value.trim().toLowerCase();
            if (!lower) return true; // empty or whitespace-only
            const placeholders = ['none', 'no', 'n/a', 'na', 'n.a.', 'n.a', 'unknown', 'null', 'undefined', 'false', 'empty', '-', '--', '—', '–', 'tbd', 'tba', '[]', '{}', 'not specified', 'not available', 'not applicable', 'no data', 'no info', 'no information', 'pending', 'none reported', 'not reported', 'no report', 'nil', 'unspecified'];
            if (placeholders.includes(lower)) return true;
            const stripped = lower.replace(/[.,;:\-–—]+$/, '');
            if (placeholders.includes(stripped)) return true;
            return false;
        }
        if (typeof value === 'number') return false; 
        if (Array.isArray(value)) return value.length === 0 || value.every(item => isValueEmpty(item));
        if (typeof value === 'object') {
            const keys = Object.keys(value);
            if (keys.length === 0) return true;
            return keys.every(k => isValueEmpty(value[k]));
        }
        return true;
    };

    const htmlEscapeMap = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' };
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

    const joinList = values => toArray(values).filter(item => !isValueEmpty(item)).map(item => cleanText(item)).filter(item => item);

    const formatFieldLabel = (key) => {
        if (!key || typeof key !== 'string') return 'Field';
        let cleanKey = key.replace(/^(operator|facility|identification|facilityDetails|operatingPeriod|staff|accreditations|locationDetails|location_details)\./i, '').replace(/\./g, ' ');
        return cleanKey.replace(/([A-Z])/g, ' $1').replace(/has\s*/gi, '').replace(/\s+/g, ' ').trim().split(' ').map(word => word.charAt(0).toUpperCase() + word.slice(1).toLowerCase()).join(' ') || 'Field';
    };

    const getNotesForKey = (fieldNotes, key) => {
        if (!fieldNotes || typeof fieldNotes !== 'object' || !key) return [];
        const notes = fieldNotes[key];
        if (!notes) return [];
        const collected = [];
        const addNote = (text) => { if (text && text.trim()) collected.push(text.trim()); };
        if (Array.isArray(notes)) { notes.forEach(note => { if (typeof note === 'string') addNote(note); else if (note && typeof note === 'object' && note.text) addNote(note.text); }); } 
        else if (typeof notes === 'string') addNote(notes); 
        else if (notes && typeof notes === 'object' && notes.text) addNote(notes.text);
        return collected;
    };

    const getValueFromKeys = (obj, keys) => {
        if (!obj || typeof obj !== 'object') return null;
        for (const key of keys) {
            if (key.includes('.')) {
                const parts = key.split('.');
                let val = obj;
                for (const part of parts) { val = val && val[part]; if (val === undefined || val === null) break; }
                if (!isValueEmpty(val)) return typeof val === 'string' ? cleanText(val) : val;
            } else {
                if (!isValueEmpty(obj[key])) return typeof obj[key] === 'string' ? cleanText(obj[key]) : obj[key];
            }
        }
        return null;
    };

    const getFacilityDisplayName = facility => getValueFromKeys(facility, [
        'identification.name', 'identification.currentName',
        'name', 'programName', 'facilityName', 'title'
    ]) || '';

    const mergeToolkit = window.KOPFacilityMerge;
    if (!mergeToolkit || typeof mergeToolkit.createApi !== 'function') {
        console.error('KOPFacilityMerge helper is missing. Include js/facility-merge.js before js/location-index.js.');
        document.getElementById(containerId).innerHTML = '<p>Error loading facility merge helpers.</p>';
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
    const buildCategoryFacilityMapById = (projects, predicate) =>
        mergeApi.buildCategoryFacilityMapById(projects, predicate);
    const mergeAndDedupeFacilities = (rawFacilities, facilitiesById) =>
        mergeApi.mergeAndDedupeFacilities(rawFacilities, facilitiesById);

    const sortFacilitiesAlphabetically = facilities => toArray(facilities)
        .slice()
        .sort((a, b) => compareDisplayText(getFacilityDisplayName(a), getFacilityDisplayName(b)));

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

    const renderInlineFieldNotes = (key, fieldNotes, usedKeys) => {
        let notes = getNotesForKey(fieldNotes, key);
        let matchedKey = key;
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

    // --- Fetch Data ---
    const extractProjects = data => {
        if (!data || typeof data !== 'object') return null;
        // REST kop/v1/facilities returns { source, projects }; get-master-data.php
        // returns { success, projects }. Accept either as long as projects exist.
        const projects = data.projects || (data.data && data.data.projects);
        if (!projects) return null;
        const count = Array.isArray(projects) ? projects.length : Object.keys(projects).length;
        return count > 0 ? projects : null;
    };

    async function fetchData() {
        const config = window.locationConfig || {};
        const urls = (Array.isArray(config.jsonFileUrls) && config.jsonFileUrls.length)
            ? config.jsonFileUrls
            : ['/api/get-master-data.php'];

        let projects = null;
        for (const url of urls) {
            try {
                const response = await fetch(url, { credentials: 'same-origin' });
                if (!response.ok) continue;
                projects = extractProjects(await response.json());
                if (projects) break;
            } catch (err) {
                console.warn('Location index: data source failed', url, err);
            }
        }

        if (!projects) {
            document.getElementById(containerId).innerHTML = '<p>Error loading data.</p>';
            return;
        }

        try {
            const restBase = getRestBase();
            const foldersResponse = await fetch(`${restBase}folders`, { credentials: 'same-origin' });
            if (foldersResponse.ok) {
                window.filebirdFolders = await foldersResponse.json();
                window.filebirdFolderMap = null;
                console.log('Location index: loaded ' + (window.filebirdFolders ? window.filebirdFolders.length : 0) + ' folders.');
            }
        } catch (error) {
            console.warn('Location index: failed to load FileBird folders', error);
        }

        processData(projects);
    }

    function processData(projects) {
        // Merge duplicate location projects (e.g. a "Utah" row in locations_master
        // plus a "Utah" aggregate derived from facilities_master) into a single
        // entry keyed by normalized name, then enrich/dedupe facilities via shared
        // facility_id merge logic from KOPFacilityMerge.
        const locationMap = new Map();
        const projectList = Object.values(projects).filter(project => project && typeof project === 'object');
        const operatorFacilitiesById = buildCategoryFacilityMapById(projectList, isOperatorCategory);

        projectList.forEach(project => {
            if (!project || !project.name) return;
            let isLocation = isLocationCategory(project);
            if (!isLocation && US_STATES.has(project.name.toUpperCase())) isLocation = true;
            if (!isLocation) return;

            const pData = getProjectData(project);
            const facilities = toArray(pData.facilities);
            if (facilities.length === 0) return;

            const mergeKey = normalizeTextKey(project.name);
            if (!mergeKey) return;

            if (locationMap.has(mergeKey)) {
                const existing = locationMap.get(mergeKey);
                existing.facilities = existing.facilities.concat(facilities);
            } else {
                locationMap.set(mergeKey, {
                    name: project.name,
                    type: US_STATES.has(project.name.toUpperCase()) ? 'state' : 'country',
                    facilities: facilities.slice()
                });
            }
        });

        allLocations = Array.from(locationMap.values()).map(loc => ({
            name: loc.name,
            type: loc.type,
            facilities: sortFacilitiesAlphabetically(
                mergeAndDedupeFacilities(loc.facilities, operatorFacilitiesById)
            )
        }));

        allLocations.sort((a, b) => compareDisplayText(a.name, b.name));
        renderAlphabetFilter();
        filterAndRender();
    }

    function renderAlphabetFilter() {
        if (!alphabetFilter) return;
        const alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ'.split('');
        let html = '';
        alphabet.forEach(char => {
            html += `<button class="alpha-btn" onclick="filterByChar('${char}')">${char}</button>`;
        });
        html += `<button class="alpha-btn" onclick="filterByChar('')">All</button>`;
        alphabetFilter.innerHTML = html;
        
        window.filterByChar = (char) => {
            document.querySelectorAll('.alpha-btn').forEach(btn => btn.classList.remove('active'));
            event.target.classList.add('active');
            currentAlphaFilter = char;
            filterAndRender();
        };
    }

    let currentAlphaFilter = '';

    function filterAndRender() {
        const query = searchInput.value.toLowerCase();
        const typeQuery = typeFilter.value;

        filteredLocations = allLocations.map(loc => {
            const matchesType = !typeQuery || loc.type === typeQuery;
            const matchesAlpha = !currentAlphaFilter || loc.name.toUpperCase().startsWith(currentAlphaFilter);
            if (!matchesType || !matchesAlpha) return null;

            const matchesLocationName = loc.name.toLowerCase().includes(query);
            if (!query || matchesLocationName) return loc;

            // Search within facility names, former names, and aliases
            const matchingFacilities = loc.facilities.filter(facility => {
                const name = getFacilityDisplayName(facility).toLowerCase();
                if (name.includes(query)) return true;
                const formerNames = collectUniqueTexts(
                    getValueFromKeys(facility, ['identification.pastNames', 'pastNames', 'identification.formerNames', 'formerNames'])
                );
                const otherNames = collectUniqueTexts(
                    getValueFromKeys(facility, ['identification.otherNames', 'otherNames'])
                );
                return formerNames.concat(otherNames).some(n => n.toLowerCase().includes(query));
            });

            if (matchingFacilities.length > 0) {
                return { ...loc, facilities: matchingFacilities };
            }
            return null;
        }).filter(l => l !== null);

        const sortVal = sortBy.value;
        filteredLocations.sort((a, b) => {
            if (sortVal === 'name') return compareDisplayText(a.name, b.name);
            if (sortVal === 'count') return b.facilities.length - a.facilities.length;
            return 0;
        });

        renderList();
    }

    function renderList() {
        const container = document.getElementById(containerId);
        container.innerHTML = '';

        if (filteredLocations.length === 0) {
            container.innerHTML = '<div class="no-results" style="grid-column: 1/-1; text-align: center; padding: 20px;">No locations found.</div>';
            return;
        }

        const grid = document.createElement('div');
        grid.className = 'facilities-database';

        filteredLocations.forEach(loc => {
            const details = document.createElement('details');
            details.className = 'operator-section'; 

            // -- Location Header --
            let headerHtml = `
                <summary class="operator-header">
                    <span class="operator-name" title="${loc.name}">${loc.name}</span>
                    <span class="operator-location">${loc.facilities.length} Facilities</span>
                </summary>
            `;

            // -- Location Content (Facilities) --
            let contentHtml = `<div class="operator-content-scrollable">`;
            
            loc.facilities.forEach(facility => {
                // Facility card rendering mirrors js/tti-program-index.js so both
                // directories present the same structured layout.
                const operatingPeriod = facility && facility.operatingPeriod ? facility.operatingPeriod : {};
                const fieldNotes = (facility && facility.fieldNotes) || {};
                const usedFieldNoteKeys = new Set();

                const statusLabelRaw = cleanText(operatingPeriod.status) || 'Unknown';
                const statusClass = statusLabelRaw.toLowerCase().replace(/[^a-z0-9]+/g, '-');
                const statusLabel = escapeHtml(statusLabelRaw);
                // Only show the status badge when a real status exists; an absent or
                // "unknown"/placeholder status renders no badge at all.
                const hasStatus = !isValueEmpty(operatingPeriod.status);

                const facilityHeaderRaw = getFacilityDisplayName(facility);
                const facilityHeader = escapeHtml(facilityHeaderRaw || 'Unnamed Facility');
                const facilityDatasetName = escapeAttribute(facilityHeaderRaw || 'Unnamed Facility');

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
                const formerNames = collectUniqueTexts(
                    getValueFromKeys(facility, ['identification.pastNames', 'pastNames', 'identification.formerNames', 'formerNames'])
                );
                const cleanHeaderText = value => cleanText(value).replace(/\s*\($/, '').trim();
                const normalizedFormerNames = collectUniqueTexts(formerNames.map(cleanHeaderText));
                const formerNameKeys = new Set(normalizedFormerNames.map(normalizeTextKey));
                const otherNames = collectUniqueTexts(
                    getValueFromKeys(facility, ['identification.otherNames', 'otherNames'])
                ).map(cleanHeaderText).filter(name => !formerNameKeys.has(normalizeTextKey(name)));
                const currentOp = getValueFromKeys(facility, ['identification.currentOperator', 'currentOperator', 'current_operator', 'sourceOperator.name']);
                // Current owner(s) for privately-owned facilities — suppressed from
                // "More details", so surface here or they'd be hidden entirely.
                const currentOwners = collectUniqueTexts(
                    getValueFromKeys(facility, ['identification.currentOwners', 'currentOwners', 'current_owners', 'identification.currentOwner', 'currentOwner', 'current_owner'])
                );
                const previousOwners = collectUniqueTexts(
                    getValueFromKeys(facility, ['identification.previousOwners', 'previousOwners', 'previous_owners', 'identification.previousOwner', 'previousOwner', 'previous_owner', 'identification.formerOwners', 'formerOwners', 'former_owners', 'identification.formerOwner', 'formerOwner', 'former_owner'])
                );

                // Some records place historical ownership phrases inside
                // current-owner fields. Reclassify those values to avoid
                // misleading "Owned by" copy.
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
                    if (/\b(previously|former(?:ly)?|prior)\b/i.test(ownerText)) {
                        previousOwnerList.push(ownerText.replace(/^\s*(previously|formerly|former|prior)\b[\s:;,-]*/i, '').trim() || ownerText);
                    } else {
                        currentOwnerList.push(ownerText);
                    }
                });
                const matchingFolder = findMatchingFilebirdFolder(facilityHeaderRaw);

                let subtextParts = [];
                if (normalizedFormerNames.length > 0) subtextParts.push(`Formerly: ${escapeHtml(normalizedFormerNames.join(', '))}`);
                const aliasNames = collectUniqueTexts(otherNames, legalNameAliases);
                if (aliasNames.length > 0) subtextParts.push(`Also known as: ${escapeHtml(aliasNames.join(', '))}`);
                const uniqueCurrentOwners = collectUniqueTexts(currentOwnerList);
                const uniquePreviousOwners = collectUniqueTexts(previousOwnerList);
                if (uniqueCurrentOwners.length > 0) subtextParts.push(`Owned by: ${escapeHtml(uniqueCurrentOwners.join(', '))}`);
                if (uniquePreviousOwners.length > 0) subtextParts.push(`Previously owned by: ${escapeHtml(uniquePreviousOwners.join(', '))}`);
                if (currentOp && !isValueEmpty(currentOp)) {
                    if (statusClass === 'transferred' || statusClass === 'acquired') {
                        subtextParts.push(`<strong>Operator at transfer: ${escapeHtml(currentOp)}</strong>`);
                    } else {
                        subtextParts.push(`Operated by: ${escapeHtml(currentOp)}`);
                    }
                }
                let otherNamesHtml = subtextParts.length > 0 ? `<div class="facility-header-subtext">${subtextParts.join('<br>')}</div>` : '';

                // "At a glance" facts (Type / Ages / Gender / Capacity)
                const facilityFieldKeys = {
                    type: ['facilityDetails.type', 'type', 'programType', 'program_type', 'facilityType', 'facility_type', 'category', 'identification.type'],
                    capacity: ['facilityDetails.capacity', 'capacity', 'licensedCapacity', 'licensed_capacity', 'maxCapacity', 'max_capacity'],
                    gender: ['facilityDetails.gender', 'gender', 'servedGender', 'served_gender', 'sex'],
                    ageRangeText: ['facilityDetails.ageRange.label', 'facilityDetails.ageRange.display', 'facilityDetails.ageRange.text', 'ageRange.label', 'ageRange.display', 'ageRange.text', 'facilityDetails.ageRange', 'ageRange', 'age_range'],
                    ageMin: ['facilityDetails.ageRange.min', 'facilityDetails.ageRange.minimum', 'facilityDetails.ageRange.minAge', 'facilityDetails.ageRange.min_age', 'ageRange.min', 'ageRange.minimum', 'ageRange.minAge', 'ageRange.min_age', 'ageMin', 'age_min'],
                    ageMax: ['facilityDetails.ageRange.max', 'facilityDetails.ageRange.maximum', 'facilityDetails.ageRange.maxAge', 'facilityDetails.ageRange.max_age', 'ageRange.max', 'ageRange.maximum', 'ageRange.maxAge', 'ageRange.max_age', 'ageMax', 'age_max'],
                    notes: ['notes', 'note', 'summary', 'description', 'facilityDetails.notes', 'facilityDetails.note', 'facilityDetails.summary', 'facilityDetails.description', 'identification.notes']
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
                if (!isValueEmpty(typeValue)) factItems.push({ label: 'Type', value: escapeHtml(typeValue), note: typeFactNotes.join('; ') });
                if (ageDisplay) factItems.push({ label: 'Ages', value: escapeHtml(ageDisplay), note: ageFactNotes.join('; ') });
                if (!isValueEmpty(genderValue)) factItems.push({ label: 'Gender', value: escapeHtml(toTitleCase(genderValue)), note: genderFactNotes.join('; ') });
                if (!isValueEmpty(capacityValue)) factItems.push({ label: 'Capacity', value: escapeHtml(String(capacityValue)), note: capacityFactNotes.join('; ') });

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

                // News section: the REST endpoint attaches linked_news[] to each nested
                // facility whose name matches a facilities_master row (see
                // kop_attach_linked_news_to_projects in inc/database.php). Mirrors the
                // News block in js/tti-program-index.js.
                const linkedNews = Array.isArray(facility.linked_news) ? facility.linked_news : [];
                let newsSectionHtml = '';
                if (linkedNews.length > 0) {
                    const formatNewsDate = value => {
                        if (!value) return '';
                        const ts = Date.parse(value);
                        if (isNaN(ts)) return value;
                        return new Date(ts).toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' });
                    };
                    const newsItemsHtml = linkedNews.map(n => {
                        const title = escapeHtml(n.display_title || n.article_title || '(untitled)');
                        const titleHtml = n.article_url
                            ? `<a href="${escapeAttribute(n.article_url)}" target="_blank" rel="noopener" class="facility-news-title">${title}</a>`
                            : `<span class="facility-news-title">${title}</span>`;
                        const metaParts = [];
                        if (n.publication_name) metaParts.push(escapeHtml(n.publication_name));
                        if (n.publication_date) metaParts.push(escapeHtml(formatNewsDate(n.publication_date)));
                        const metaHtml = metaParts.length
                            ? `<div class="facility-news-meta">${metaParts.join(' &middot; ')}</div>`
                            : '';
                        return `<li class="facility-news-item">${titleHtml}${metaHtml}</li>`;
                    }).join('');
                    newsSectionHtml = renderDetailSection(
                        `News (${linkedNews.length})`,
                        `<ul class="facility-news-list">${newsItemsHtml}</ul>`,
                        'facility-news-section'
                    );
                }

                // Suppress keys already shown in the header / facts / notes
                const suppressedFieldKeys = new Set([
                    'identification.name', 'identification.currentName',
                    'name', 'programName', 'facilityName', 'title', 'program_name', 'facility_name',
                    'identification.otherNames', 'otherNames',
                    'identification.pastNames', 'pastNames',
                    'identification.formerNames', 'formerNames',
                    'identification.currentOperator', 'currentOperator', 'current_operator',
                    'identification.currentOwner', 'identification.currentOwners',
                    'identification.current_owner', 'identification.current_owners',
                    'identification.currentOwnership', 'identification.current_ownership',
                    'currentOwner', 'currentOwners', 'current_owner', 'current_owners',
                    'currentOwnership', 'current_ownership',
                    'location', 'address', 'cityState', 'city_state', 'fullAddress', 'full_address',
                    'city', 'state', 'locationCity', 'location_city', 'locationState', 'location_state',
                    'operatingPeriod.status', 'status',
                    'yearsOfOperation', 'yearsActive', 'years_active', 'operating_period_text',
                    'founded', 'yearFounded', 'opened', 'startYear', 'year_founded', 'start_year',
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

                // Build remaining facility data ("More details")
                let otherFacilityData = '';
                const renderItemFac = (item) => {
                    if (isValueEmpty(item)) return '';
                    if (typeof item === 'boolean') return item ? 'Yes' : '';
                    if (typeof item === 'string') return isValueEmpty(item) ? '' : escapeHtml(item);
                    if (typeof item === 'number') return escapeHtml(String(item));
                    if (typeof item === 'object' && !Array.isArray(item)) {
                        if (item.url && typeof item.url === 'string' && (item.url.startsWith('http') || item.url.startsWith('/'))) {
                            const label = item.displayText || item.name || item.text || item.url;
                            return `<a href="${escapeAttribute(item.url)}" target="_blank" rel="noopener">${escapeHtml(label)}</a>`;
                        }
                        const parts = [];
                        Object.entries(item).forEach(([k, v]) => {
                            if (!isValueEmpty(v) && (k === 'name' || k === 'value' || k === 'text' || k !== 'role' || v.trim())) parts.push(escapeHtml(v));
                        });
                        return parts.join(' - ');
                    }
                    return escapeHtml(String(item));
                };

                const renderAllObjectFieldsFac = (obj, prefix = '', depth = 0) => {
                    if (!obj || typeof obj !== 'object' || depth > 3) return [];
                    const fields = [];
                    const skipFullKeys = [
                        'resources', 'fieldNotes', 'linked_news', 'facility_id',
                        // Internal provenance metadata recorded when a facility is
                        // aggregated into a location project (see api/save-master.php).
                        // The operator is already shown in the header subtext.
                        'sourceOperator', 'source_operator',
                        'sourceProject', 'source_project',
                        'sourceProjectId', 'source_project_id',
                        'sourceCategory', 'source_category'
                    ];
                    Object.keys(obj).forEach(key => {
                        const fullKey = prefix ? `${prefix}.${key}` : key;
                        const value = obj[key];
                        if (skipFullKeys.includes(fullKey)) return;
                        if (isValueEmpty(value)) return;
                        if (Array.isArray(value)) {
                            if (value.length > 0 && !value.every(isValueEmpty)) fields.push({ key: fullKey, label: formatFieldLabel(fullKey), value: value, isList: true });
                        } else if (typeof value === 'object' && Object.keys(value).length > 0) {
                            fields.push(...renderAllObjectFieldsFac(value, fullKey, depth + 1));
                        } else if (typeof value === 'boolean') {
                            if (value) fields.push({ key: fullKey, label: formatFieldLabel(fullKey), value: 'Yes' });
                        } else {
                            const textValue = cleanText(value);
                            if (textValue && !isValueEmpty(textValue)) fields.push({ key: fullKey, label: formatFieldLabel(fullKey), value: textValue });
                        }
                    });
                    return fields;
                };

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
                        if (validItems.length > 1 || typeof validItems[0] === 'object' || ['certifications', 'licensing', 'memberships'].some(k => field.key.toLowerCase().includes(k))) {
                            isMultiColumn = true;
                        }
                        const isUrlList = validItems.some(item => typeof item === 'string' && (item.startsWith('http://') || item.startsWith('https://')));
                        if (isUrlList) {
                            renderedValue = validItems.map(url => {
                                if (typeof url !== 'string') return '';
                                const safeUrl = escapeAttribute(url);
                                let displayUrl = url.replace(/^https?:\/\/(www\.)?/, '');
                                if (displayUrl.length > 50) displayUrl = displayUrl.substring(0, 47) + '...';
                                return safeUrl ? `<a href="${safeUrl}" target="_blank" rel="noopener">${escapeHtml(displayUrl)}</a>` : '';
                            }).filter(Boolean).join('<br>');
                        } else {
                            renderedValue = validItems.map(item => renderItemFac(item)).filter(Boolean).join(', ');
                            if (isMultiColumn && typeof validItems[0] === 'object') {
                                renderedValue = validItems.map(item => `<div class="list-item">${renderItemFac(item)}</div>`).join('');
                            }
                        }
                    } else {
                        renderedValue = escapeHtml(field.value);
                    }
                    if (!renderedValue || isValueEmpty(renderedValue)) return;
                    const rowClass = isMultiColumn ? 'field-row full-width-grid' : 'field-row';
                    otherFacilityData += `<div class="${rowClass}"><span class="field-label">${escapeHtml(field.label)}</span><span class="field-value">${renderedValue}</span></div>`;
                    otherFacilityData += renderInlineFieldNotes(field.key, fieldNotes, usedFieldNoteKeys);
                });

                const additionalDetailsHtml = otherFacilityData
                    ? renderDetailSection('More details', `<div class="facility-detail-grid">${otherFacilityData}</div>`, 'facility-additional-section')
                    : '';

                // Resources chips
                let resourcesSectionHtml = '';
                if (facility.resources) {
                    const resources = [];
                    const resourceMap = {
                        'hasNews': 'News', 'hasPressReleases': 'Press Releases', 'hasInspections': 'Inspections',
                        'hasStateReports': 'State Reports', 'hasRegulatoryFilings': 'Regulatory Filings', 'hasLawsuits': 'Lawsuits',
                        'hasSettlements': 'Settlements', 'hasViolations': 'Violations', 'hasResearch': 'Research',
                        'hasFinancial': 'Financial', 'hasNATSAP': 'NATSAP Profile', 'hasWebsite': 'Website Screenshots', 'hasOther': 'Other'
                    };
                    Object.keys(resourceMap).forEach(key => { if (facility.resources[key] === true) resources.push(resourceMap[key]); });
                    if (facility.resources.customResources && facility.resources.customResources.length > 0) {
                        resources.push(...facility.resources.customResources.map(item => cleanText(item)).filter(item => !isValueEmpty(item)));
                    }
                    if (resources.length > 0) {
                        resourcesSectionHtml = renderDetailSection(
                            'Resources',
                            `<div class="resource-chip-list">${resources.map(item => `<span class="resource-chip">${escapeHtml(item)}</span>`).join('')}</div>`,
                            'facility-resources-section'
                        );
                    }
                }

                // Documents (FileBird)
                let documentsSectionHtml = '';
                if (matchingFolder) {
                    const documentsHtml = `
                        <div class="field-row full-width-grid facility-document-row" id="documents-${matchingFolder.id}" data-documents-container="true">
                            <span class="field-value doc-library-btn-wrap">
                                <button type="button" class="kop-doc-button doc-library-button" data-folder-id="${matchingFolder.id}" data-container-id="documents-${matchingFolder.id}">
                                    📂 View Document Library
                                </button>
                            </span>
                        </div>`;
                    documentsSectionHtml = renderDetailSection('Documents', `<div class="facility-detail-grid">${documentsHtml}</div>`, 'facility-documents-section');
                }

                contentHtml += `
                    <div class="facility-card status-${statusClass}" data-facility="${facilityDatasetName}" data-status="${statusClass}">
                        <div class="facility-summary">
                            <h3 class="facility-name">${facilityHeader}</h3>
                            ${otherNamesHtml}
                            ${facilityLocation ? `<p class="facility-location">${facilityLocation}</p>` : ''}
                            ${yearRange ? `<p class="facility-years">${yearRange}</p>` : ''}
                            ${hasStatus ? `<p class="facility-status">
                                <span class="status-badge status-${statusClass}">${statusLabel}</span>
                            </p>` : ''}
                        </div>
                        <div class="facility-details">
                            <details class="facility-expanded-info">
                                <summary><span class="closed-text">+ Learn more</span><span class="open-text">- Collapse details</span></summary>
                                <div class="facility-extra-content">
                                    ${factsHtml}
                                    ${notesHtml}
                                    ${newsSectionHtml}
                                    ${resourcesSectionHtml}
                                    ${documentsSectionHtml}
                                    ${additionalDetailsHtml}
                                </div>
                            </details>
                        </div>
                    </div>
                `;
            });
            contentHtml += `</div>`;

            details.innerHTML = headerHtml + contentHtml;
            grid.appendChild(details);
        });

        container.appendChild(grid);
        attachDocumentButtons(container);
    }

    // Events
    searchInput.addEventListener('input', filterAndRender);
    typeFilter.addEventListener('change', filterAndRender);
    sortBy.addEventListener('change', filterAndRender);
    clearSearchBtn.addEventListener('click', window.clearSearch);

    fetchData();
});

window.clearSearch = function() {
    const searchInput = document.getElementById('searchInput');
    const typeFilter = document.getElementById('typeFilter');
    if (searchInput) searchInput.value = '';
    if (typeFilter) typeFilter.value = '';
    if (searchInput) searchInput.dispatchEvent(new Event('input'));
};

})();
