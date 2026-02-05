// Facilities Database Display - Drop-in JavaScript
// Usage: Include this file and call displayFacilities(jsonData, containerId)

(function() {

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

            // Explicit placeholder list
            const placeholders = [
                'none', 'no', 'n/a', 'na', 'n.a.', 'n.a',
                'unknown', 'null', 'undefined', 'false', 'empty',
                '-', '--', '—', '–', 'tbd', 'tba', '[]', '{}', 
                'not specified', 'not available', 'not applicable',
                'no data', 'no info', 'no information',
                'pending', 'none reported', 'not reported', 'no report',
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

    const joinList = values => toArray(values)
        .filter(item => !isValueEmpty(item))
        .map(item => cleanText(item))
        .filter(item => item); // Double check for empty strings

    const formatFieldLabel = (key) => {
        if (!key || typeof key !== 'string') return 'Field';

        // Strip common redundant prefixes
        let cleanKey = key
            .replace(/^(operator|facility|identification|facilityDetails|operatingPeriod|staff|accreditations)\./i, '')
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

    const getMergedLocation = (obj) => {
        if (!obj || typeof obj !== 'object') return null;

        const loc = getValueFromKeys(obj, ['location', 'address', 'cityState', 'city_state', 'fullAddress', 'full_address', 'hq_location', 'headquarters']);
        if (loc) return loc;

        const city = getValueFromKeys(obj, ['city', 'locationCity', 'location_city', 'headquartersCity', 'hq_city']);
        const state = getValueFromKeys(obj, ['state', 'locationState', 'location_state', 'headquartersState', 'hq_state', 'province']);

        if (city && state) return `${city}, ${state}`;
        return city || state || null;
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
        return `<div class="field-note-inline"><span class="field-note-label">Note:</span> ${noteText}</div>`;
    };

    const renderRemainingFieldNotes = (fieldNotes, usedKeys) => {
        if (!fieldNotes || typeof fieldNotes !== 'object') return '';
        const items = [];
        Object.keys(fieldNotes).forEach(key => {
            if (usedKeys && usedKeys.has(key)) return;
            const notes = getNotesForKey(fieldNotes, key);
            if (!notes.length) return;
            const label = formatFieldLabel(key);
            notes.forEach(text => {
                items.push({ label, text });
            });
        });
        if (!items.length) return '';
        const list = items.map(({ label, text }) =>
            `<li><strong>${escapeHtml(label)}:</strong> ${escapeHtml(text)}</li>`
        ).join('');
        return `
            <div class="facility-field-notes">
                <p><strong>Additional Field Notes</strong></p>
                <ul>${list}</ul>
            </div>
        `;
    };

    const normalizeProjectCategory = project => {
        if (!project || typeof project !== 'object') {
            return 'companies';
        }
        let rawCategory = '';
        if (typeof project.category === 'string' && project.category) {
            rawCategory = project.category;
        } else if (project.data && typeof project.data === 'object' && typeof project.data.category === 'string' && project.data.category) {
            rawCategory = project.data.category;
        }
        if (!rawCategory) return 'companies';
        return rawCategory.toLowerCase();
    };

    const isOperatorCategory = project => {
        const category = normalizeProjectCategory(project);
        return category === 'operators' || category === 'operator' || category === 'companies' || category === 'company';
    };

    // --- Main Rendering Logic ---

    let html = '<div class="facilities-database">';

    // Convert the new JSON structure to work with existing code
    let operatorGroups = [];
    if (facilitiesData && facilitiesData.projects) {
        // Handle new JSON structure
        const operatorProjects = Object.values(facilitiesData.projects).filter(isOperatorCategory);

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
            const facilities = toArray(projectData.facilities);

            operatorGroups.push({
                operator,
                facilities,
                name: cleanText(project.name)
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
        return nameA.localeCompare(nameB);
    });

    // Generate operator sections
    operatorGroups.forEach(operatorGroup => {
        const operator = operatorGroup && operatorGroup.operator ? operatorGroup.operator : {};
        const rawFacilities = toArray(operatorGroup && operatorGroup.facilities).slice();

        // Deduplicate facilities by name
        const seenFacilityNames = new Set();
        const facilities = rawFacilities.filter(f => {
            if (!f) return false;
            // Enhanced name check for deduplication
            const name = getValueFromKeys(f, ['identification.name', 'identification.currentName', 'name', 'programName', 'facilityName', 'title']) || '';

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

            if (!name || seenFacilityNames.has(name)) return false;
            seenFacilityNames.add(name);
            return true;
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

        // Sort facilities alphabetically by name
        facilities.sort((a, b) => {
            const nameA = getValueFromKeys(a, ['identification.name', 'identification.currentName', 'name', 'programName', 'facilityName', 'title']) || '';
            const nameB = getValueFromKeys(b, ['identification.name', 'identification.currentName', 'name', 'programName', 'facilityName', 'title']) || '';
            return nameA.localeCompare(nameB);
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

        // Only create the div if there's actual content       
        const operatorDetailsDiv = otherOperatorData ? `<div class="operator-details">${otherOperatorData}</div>` : '';       

        html += '<details class="operator-section" data-operator="' + escapeAttribute(operatorName) + '">' +
                '<summary class="operator-header">' +
                    operatorHeader +
                    locationYearsLine +
                '</summary>' +
                '<div class="operator-content-scrollable">' +
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

            // Build facility header with Name - Enhanced lookup
            const facilityHeaderRaw = getValueFromKeys(facility, [
                'identification.name', 'identification.currentName', 
                'name', 'programName', 'facilityName', 'title',
                'program_name', 'facility_name'
            ]);
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

            // Subtext for Other Names AND Current Operator
            const otherNames = getValueFromKeys(facility, ['identification.otherNames', 'otherNames', 'identification.pastNames', 'pastNames', 'formerNames']);
            const currentOp = getValueFromKeys(facility, ['identification.currentOperator', 'currentOperator', 'current_operator']);
            
            let subtextParts = [];
            
            if (otherNames) {
                const names = Array.isArray(otherNames) ? otherNames : [otherNames];
                const validNames = names.filter(n => !isValueEmpty(n));
                if (validNames.length > 0) {
                    subtextParts.push(`Formerly: ${escapeHtml(validNames.join(', '))}`);
                }
            }

            if (currentOp && !isValueEmpty(currentOp)) {
                // If transferred, show current operator prominently
                if (statusClass === 'transferred' || statusClass === 'acquired') {
                    subtextParts.push(`<strong>Current Operator: ${escapeHtml(currentOp)}</strong>`);
                } 
                // If open but listed under a different parent (historical context), might still be useful
                else if (statusClass === 'open' && cleanText(operatorName) !== cleanText(currentOp)) {
                     subtextParts.push(`Operated by: ${escapeHtml(currentOp)}`);
                }
            }

            let otherNamesHtml = '';
            if (subtextParts.length > 0) {
                otherNamesHtml = `<div class="facility-header-subtext">${subtextParts.join('<br>')}</div>`;
            }

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

            // Function to recursively render all fields from an object
            const renderAllObjectFieldsFac = (obj, prefix = '', depth = 0) => {
                if (!obj || typeof obj !== 'object' || depth > 3) return [];
                const fields = [];

                                                // Skip ONLY special handling keys.

                                                // We want everything else to show in details, even if it repeats header info (completeness > aesthetics)

                                                const skipFullKeys = [

                                                    'resources', // Handled separately

                                                    'fieldNotes' // Handled separately

                                                ];

                Object.keys(obj).forEach(key => {
                    const fullKey = prefix ? `${prefix}.${key}` : key;
                    const value = obj[key];

                    // Skip keys already shown in header       
                    if (skipFullKeys.includes(fullKey)) return;

                    // Skip empty values (would show "None" or be blank)
                    if (isValueEmpty(value)) return;

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

            // FileBird Document Matching (New)
            let documentsHtml = '';
            if (window.filebirdFolders && Array.isArray(window.filebirdFolders)) {
                const facName = cleanText(facilityHeaderRaw).toLowerCase().trim();
                
                // Normalize: remove punctuation, extra spaces
                const normalize = s => s.replace(/[^\w\s]/g, '').trim();
                const normFac = normalize(facName);
                
                // Debug matching for specific facilities (uncomment to debug globally)
                // if (facName.includes('agape')) console.log(`Attempting match for: "${facName}" (norm: "${normFac}")`);

                // 1. Try Exact Match
                let matchingFolder = window.filebirdFolders.find(f => f.name.toLowerCase().trim() === facName);
                
                // 2. Try Normalized Match
                if (!matchingFolder) {
                    matchingFolder = window.filebirdFolders.find(f => normalize(f.name.toLowerCase()) === normFac);
                }

                // 3. Try "Contains" for specific distinctive names (length > 6 to avoid 'Hope', 'New', etc.)
                if (!matchingFolder && normFac.length > 6) {
                     // Check if folder name contains facility name (e.g. Folder: "Agape Boarding School Documents", Fac: "Agape Boarding School")
                     matchingFolder = window.filebirdFolders.find(f => {
                         const normFolder = normalize(f.name.toLowerCase());
                         const match = normFolder.includes(normFac);
                         // if (match) console.log(`Match found via contains! Folder: "${f.name}"`);
                         return match;
                     });
                }

                if (matchingFolder) {
                    // console.log(`MATCHED! Facility: "${facName}" -> Folder: "${matchingFolder.name}" (ID: ${matchingFolder.id})`);
                    documentsHtml = `
                        <div class="field-row full-width-grid" id="documents-${matchingFolder.id}">
                            <span class="field-label">Documents</span>
                            <span class="field-value">
                                <button type="button" style="padding:5px 10px; cursor:pointer; background:#f0f0f0; border:1px solid #ccc; border-radius:4px; font-weight:600;" onclick="loadFacilityDocuments(${matchingFolder.id}, 'documents-${matchingFolder.id}')">
                                    📂 View Document Library
                                </button>
                            </span>
                        </div>`;
                }
            }

            // Build resources available section
            let resourcesAvailable = '';
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
                    const safeResources = resources.map(item => escapeHtml(item)).join(', ');
                    resourcesAvailable = `<div class="field-row full-width-grid"><span class="field-label">Resources Available</span><span class="field-value">${safeResources}</span></div>`;
                }
            }

            const facilityDatasetNameRaw = cleanText(identification.name) || cleanText(identification.currentName) || cleanText(facilityHeaderRaw) || 'Unnamed Facility';
            const facilityDatasetName = escapeAttribute(facilityDatasetNameRaw);

            html += `<div class="facility-card status-${statusClass}" data-facility="${facilityDatasetName}" data-status="${statusClass}">
                    <div class="facility-summary">
                        <h3 class="facility-name">${facilityHeader}</h3>
                        ${otherNamesHtml}
                        ${facilityLocation ? `<p class="facility-location">${facilityLocation}</p>` : ''}
                        ${yearRange ? `<p class="facility-years">${yearRange}</p>` : ''}
                        <p class="facility-status">
                            <span class="status-badge status-${statusClass}">${statusLabel}</span>
                        </p>
                    </div>
                    <div class="facility-details">
                        <details class="facility-expanded-info">
                            <summary><span class="closed-text">+ Learn more</span><span class="open-text">- Collapse details</span></summary>
                            <div class="facility-extra-content">
                                ${otherFacilityData}
                                ${documentsHtml}
                                ${resourcesAvailable}
                                ${renderRemainingFieldNotes(fieldNotes, usedFieldNoteKeys)}
                            </div>
                        </details>
                    </div>
                </div>`;
        });

        html += '</div>' + 
            '</details>';
    });

    html += '</div>';

    container.innerHTML = html;

    // Store data globally for filtering
    window.facilitiesData = facilitiesData;
}


function filterFacilities() {
    const searchInput = document.getElementById('searchInput');
    const searchTerm = searchInput ? searchInput.value.toLowerCase() : '';
    const statusFilterSelect = document.getElementById('statusFilter');
    const statusFilter = statusFilterSelect ? statusFilterSelect.value : '';

    const operatorSections = document.querySelectorAll('.operator-section');

    operatorSections.forEach(section => {
        const operatorName = section.dataset.operator.toLowerCase();
        const facilityCards = section.querySelectorAll('.facility-card');
        let visibleFacilities = 0;

        facilityCards.forEach(card => {
            const facilityName = card.dataset.facility.toLowerCase();
            const facilityStatus = card.dataset.status;

            const matchesSearch = operatorName.includes(searchTerm) || facilityName.includes(searchTerm);
            const matchesStatus = !statusFilter || facilityStatus === statusFilter;

            if (matchesSearch && matchesStatus) {
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
    if (sortBy) sortBy.value = '';
    if (clearButton) clearButton.style.display = 'none';       
    filterFacilities();
}

// Expose to global scope for inline onclick handlers
window.clearSearch = clearSearch;

window.loadFacilityDocuments = async function(folderId, containerId) {
    const container = document.getElementById(containerId);
    if (!container) return;
    
    // Keep the label, show loading in value
    const labelSpan = container.querySelector('.field-label') ? container.querySelector('.field-label').outerHTML : '<span class="field-label">Documents</span>';
    container.innerHTML = `${labelSpan}<span class="field-value">Loading Document Library...</span>`;
    
    try {
        // Use the shortcode renderer to get the full FileBird library display
        const response = await fetch(`/wp-json/kop/v1/render-folder-shortcode?id=${folderId}`);
        if (!response.ok) throw new Error('Failed to load library');
        const data = await response.json();
        
        if (!data.html || data.html.trim() === '') {
             // Fallback to simple list if shortcode returns nothing
             await loadFacilityDocumentsFallback(folderId, container);
             return;
        }
        
        container.innerHTML = `<div class="filebird-library-container" style="grid-column: 1 / -1; margin-top: 10px;">${data.html}</div>`;
        
    } catch (e) {
        console.warn('Shortcode render failed, trying fallback list...', e);
        await loadFacilityDocumentsFallback(folderId, container);
    }
};

async function loadFacilityDocumentsFallback(folderId, container) {
    try {
        const response = await fetch(`/wp-json/kop/v1/folder-content?id=${folderId}`);
        if (!response.ok) throw new Error('Failed to load content');
        const files = await response.json();
        
        const labelSpan = container.querySelector('.field-label') ? container.querySelector('.field-label').outerHTML : '<span class="field-label">Documents</span>';

        if (Array.isArray(files) && files.length === 0) {
            container.innerHTML = `${labelSpan}<span class="field-value">No documents found.</span>`;
            return;
        }
        
        const fileLinks = files.map(f => {
            const date = f.date ? new Date(f.date).toLocaleDateString() : '';
            let icon = '📄';
            if (f.mime_type && f.mime_type.includes('image')) icon = '🖼️';
            if (f.mime_type && f.mime_type.includes('pdf')) icon = '📕';
            
            return `<div class="document-link" style="margin-bottom:4px;">
                ${icon} <a href="${f.url}" target="_blank" rel="noopener"><strong>${f.title}</strong></a> 
                <span style="color:#777; font-size:0.85em;">(${date})</span>
            </div>`;
        }).join('');
        
        container.innerHTML = `${labelSpan}<span class="field-value">${fileLinks}</span>`;
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
    const searchInput = document.getElementById('searchInput');
    if (searchInput) {
        if (letter === '') {
            searchInput.value = '';
        } else {
            searchInput.value = letter;
        }
        filterFacilities();
    }
}

// Expose to global scope for inline onclick handlers
window.filterByLetter = filterByLetter;

function setupEventListeners() {
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

    switch(sortValue) {
        case 'name':
            operatorSections.sort((a, b) => a.dataset.operator.localeCompare(b.dataset.operator));
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
            operatorSections.sort((a, b) => a.dataset.operator.localeCompare(b.dataset.operator));
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
                console.log('Facilities script: data loaded successfully from', candidateUrl);

                // NEW: Load FileBird folders for document matching
                try {
                    const foldersResponse = await fetch('/wp-json/kop/v1/folders');
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
