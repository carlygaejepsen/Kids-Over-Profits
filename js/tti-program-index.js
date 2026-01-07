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
                'no data', 'no info', 'no information'
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
        return text ? text.replace(/[&<>'"']/g, char => htmlEscapeMap[char] || char) : '';
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
                }
                if (!isValueEmpty(val)) return cleanText(val);
            } else {
                if (!isValueEmpty(obj[key])) return cleanText(obj[key]);
            }
        }
        return null;
    };

    const renderInlineFieldNotes = (key, fieldNotes, usedKeys) => {
        const notes = getNotesForKey(fieldNotes, key);
        if (!notes.length) return '';
        if (usedKeys && key) usedKeys.add(key);
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
            if (!project || !project.data) {
                return;
            }

            const operator = project.data.operator || {};      
            const facilities = toArray(project.data.facilities);

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
            if (!name || seenFacilityNames.has(name)) return false;
            seenFacilityNames.add(name);
            return true;
        });

        // Check if this is a privately owned facility (has isPrivatelyOwned flag set)
        const hasPrivateOwner = facilities.some(f => f && f.isPrivatelyOwned === true);

        // Get the actual operator name (not the project name) 
        const actualOperatorName = getValueFromKeys(operator, ['name', 'currentName', 'companyName', 'title', 'operatorName', 'ownerName']);

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

        const operatorLocation = getValueFromKeys(operator, ['location', 'headquarters', 'address', 'cityState']);
        if (operatorLocation) {
            locationLines.push(`<div>${escapeHtml(operatorLocation)}</div>`);
        }
        
        const operatorOperatingPeriod = getValueFromKeys(operator, ['operatingPeriod', 'yearsActive']);
        const operatorFounded = getValueFromKeys(operator, ['founded', 'yearFounded']);
        
        if (operatorOperatingPeriod) {
            locationLines.push(`<div>${escapeHtml(operatorOperatingPeriod)}</div>`);
        } else if (operatorFounded) {
            const status = getValueFromKeys(operator, ['status']);
            const endYear = isValueEmpty(status) || status === 'Defunct' ? 'Defunct' : 'Present';
            locationLines.push(`<div>${escapeHtml(`${operatorFounded}-${endYear}`)}</div>`);
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

            // Skip these keys - they're already shown in the operator header
            const skipFullKeys = [
                'name', 'currentName', 'companyName', 'title', 'operatorName', 'ownerName',
                'location', 'headquarters', 'address', 'cityState',
                'status', 
                'operatingPeriod', 'founded', 'yearFounded', 'yearsActive'
            ];

            Object.keys(obj).forEach(key => {
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
                otherOperatorData += '<div class="field-row"><span class="field-label">' + escapeHtml(field.label) + '</span><span class="field-value">' + renderedValue + '</span></div>';  
            } else {
                otherOperatorData += '<div class="field-row"><span class="field-value">' + renderedValue + '</span></div>';   
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
                'name', 'programName', 'facilityName', 'title'
            ]);
            const facilityHeader = escapeHtml(facilityHeaderRaw || 'Unnamed Facility');

            // Data for display above the "cut"
            const facilityLocationRaw = getValueFromKeys(facility, ['location', 'address', 'cityState', 'physicalAddress', 'fullAddress']);
            const facilityLocation = facilityLocationRaw ? escapeHtml(facilityLocationRaw) : '';
            
            let yearRange = '';
            const startYear = getValueFromKeys(operatingPeriod, ['startYear']) || getValueFromKeys(facility, ['founded', 'yearFounded', 'opened', 'startYear']);
            if (startYear) {
                const endYear = getValueFromKeys(operatingPeriod, ['endYear']) || 'Present';
                yearRange = escapeHtml(`${startYear}-${endYear}`);
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

                // Skip these keys - they're already shown in the card header
                // Note: keys can be partial matches in this list if they are top-level or fully qualified paths
                const skipFullKeys = [
                    'identification.name', 'identification.currentName',
                    'name', 'programName', 'facilityName', 'title',
                    
                    'location', 'address', 'cityState', 'physicalAddress', 'fullAddress',
                    
                    'operatingPeriod.startYear', 'operatingPeriod.endYear', 'operatingPeriod.status',
                    'founded', 'yearFounded', 'opened', 'startYear', 'yearsActive',
                    
                    'resources', // Handled separately by dedicated Resources Available section
                    'fieldNotes' // Handled separately by field notes renderer
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
                    if (validItems.length > 1 || typeof validItems[0] === 'object') {
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
