/**
 * Location Form Module
 * Handles all location-specific functionality including:
 * - Location data structures and constants
 * - Location project management (deduplication, syncing)
 * - Location facilities overview UI
 * - Location TOC toggle
 */

// ============================================
// LOCATION CONSTANTS
// ============================================

const US_STATE_NAMES = [
    'alabama', 'alaska', 'arizona', 'arkansas', 'california', 'colorado', 'connecticut', 'delaware', 'florida', 'georgia',
    'hawaii', 'idaho', 'illinois', 'indiana', 'iowa', 'kansas', 'kentucky', 'louisiana', 'maine', 'maryland',
    'massachusetts', 'michigan', 'minnesota', 'mississippi', 'missouri', 'montana', 'nebraska', 'nevada', 'new hampshire', 'new jersey',
    'new mexico', 'new york', 'north carolina', 'north dakota', 'ohio', 'oklahoma', 'oregon', 'pennsylvania', 'rhode island', 'south carolina',
    'south dakota', 'tennessee', 'texas', 'utah', 'vermont', 'virginia', 'washington', 'west virginia', 'wisconsin', 'wyoming'
];

const COUNTRY_NAMES = [
    'canada', 'mexico', 'united kingdom', 'france', 'germany', 'italy', 'spain', 'russia', 'china', 'japan',
    'australia', 'brazil', 'argentina', 'india', 'south africa', 'nigeria', 'egypt', 'saudi arabia', 'iran', 'iraq',
    'norway', 'sweden', 'denmark', 'netherlands', 'belgium', 'switzerland', 'austria', 'poland', 'ukraine', 'turkey'
];

const US_STATE_SET = new Set(US_STATE_NAMES);
const COUNTRY_SET = new Set(COUNTRY_NAMES);

function normalizeLocationProjectName(name) {
    const trimmed = typeof name === 'string' ? name.trim() : '';
    if (!trimmed) return '';

    const upperName = trimmed.toUpperCase();
    return US_STATE_SET.has(trimmed.toLowerCase()) || COUNTRY_SET.has(trimmed.toLowerCase()) ? upperName : trimmed;
}

function buildLocationFacilityKey(facility) {
    if (!facility || typeof facility !== 'object') return '';

    const identification = facility.identification || {};
    const name = String(identification.name || facility.name || '').trim().toLowerCase();
    const locationDetails = facility.locationDetails || {};
    const address = String(facility.address || '').trim().toLowerCase();
    const location = String(facility.location || '').trim().toLowerCase();
    const city = String(locationDetails.city || facility.locationCity || '').trim().toLowerCase();
    const state = String(locationDetails.state || facility.locationState || '').trim().toLowerCase();
    return `${name}|${address}|${location}|${city}|${state}`;
}

function buildLocationReferrerKey(referrer) {
    if (!referrer || typeof referrer !== 'object') return '';

    const name = String(referrer.name || referrer.fullName || referrer.consultantName || '').trim().toLowerCase();
    const location = String(referrer.location || '').trim().toLowerCase();
    const city = String(referrer.city || '').trim().toLowerCase();
    const state = String(referrer.state || '').trim().toLowerCase();
    return `${name}|${location}|${city}|${state}`;
}

function mergeLocationProjectRecords(baseProject, incomingProject, canonicalName) {
    const merged = {
        ...(baseProject || {}),
        ...(incomingProject || {})
    };

    const baseData = (baseProject && typeof baseProject === 'object' && baseProject.data && typeof baseProject.data === 'object') ? baseProject.data : {};
    const incomingData = (incomingProject && typeof incomingProject === 'object' && incomingProject.data && typeof incomingProject.data === 'object') ? incomingProject.data : {};

    merged.name = canonicalName;
    merged.category = 'locations';
    merged.data = {
        ...baseData,
        ...incomingData
    };

    const facilityMap = new Map();
    [...(baseData.facilities || []), ...(incomingData.facilities || [])].forEach((facility) => {
        const key = buildLocationFacilityKey(facility) || `facility-${facilityMap.size}`;
        facilityMap.set(key, facility);
    });
    merged.data.facilities = Array.from(facilityMap.values());

    const referrerMap = new Map();
    [...(baseData.referrerConsultants || []), ...(incomingData.referrerConsultants || [])].forEach((referrer) => {
        const key = buildLocationReferrerKey(referrer) || `referrer-${referrerMap.size}`;
        referrerMap.set(key, referrer);
    });
    merged.data.referrerConsultants = Array.from(referrerMap.values());

    if (!merged.data.operator || typeof merged.data.operator !== 'object') {
        merged.data.operator = {};
    }
    if (!merged.data.operator.name) {
        merged.data.operator.name = canonicalName;
    }

    if (typeof merged.currentFacilityIndex !== 'number') {
        merged.currentFacilityIndex = 0;
    }

    return merged;
}

// ============================================
// LOCATION DATA FUNCTIONS
// ============================================

/**
 * Get all locations from projects (for autocomplete)
 */
function getAllLocations() {
    if (!window.aggregatedDataCache) {
        window.aggregatedDataCache = {};
    }

    if (!window.aggregatedDataCache.locations) {
        const locations = new Set();

        Object.values(window.projects || {}).forEach(project => {
            // Collect from location projects
            if (project.category === 'locations' && project.name) {
                locations.add(project.name);
            }

            // Collect from facilities in all projects
            project.data?.facilities?.forEach(facility => {
                if (facility.location) {
                    locations.add(facility.location);
                }
                if (facility.locationState) {
                    locations.add(facility.locationState);
                }
                if (facility.locationCity) {
                    locations.add(facility.locationCity);
                }
            });
        });

        window.aggregatedDataCache.locations = Array.from(locations).filter(l => l && typeof l === 'string' && l.trim()).sort();
    }

    return window.aggregatedDataCache.locations;
}

/**
 * Deduplicate location projects by normalizing state/country names
 */
function deduplicateLocationProjects(projectsObj) {
    const result = {};
    const locationNames = new Set([...US_STATE_NAMES, ...COUNTRY_NAMES].map(n => n.toUpperCase()));

    // First pass: identify all location project keys
    const locationKeys = {};  // Maps uppercase name -> array of actual keys

    Object.keys(projectsObj).forEach(key => {
        const upperKey = normalizeLocationProjectName(key).toUpperCase();
        if (locationNames.has(upperKey)) {
            if (!locationKeys[upperKey]) {
                locationKeys[upperKey] = [];
            }
            locationKeys[upperKey].push(key);
        } else {
            // Non-location project, keep as-is
            result[key] = projectsObj[key];
        }
    });

    // Second pass: for each location, pick the best version
    Object.keys(locationKeys).forEach(upperName => {
        const keys = locationKeys[upperName];

        if (keys.length === 1) {
            // Only one version exists, use it but normalize to uppercase
            const originalKey = keys[0];
            result[upperName] = mergeLocationProjectRecords(projectsObj[originalKey], null, upperName);
            if (typeof debugLog === 'function') {
                debugLog(`📍 Location project "${originalKey}" normalized to "${upperName}"`);
            }
        } else {
            let mergedProject = null;
            keys.forEach((key) => {
                mergedProject = mergeLocationProjectRecords(mergedProject, projectsObj[key], upperName);
            });

            result[upperName] = mergedProject;
            console.log(`🔀 Deduplicated location "${upperName}": merged variants ${keys.join(', ')}`);
        }
    });

    if (typeof debugLog === 'function') {
        debugLog(`📦 Deduplication complete: ${Object.keys(projectsObj).length} → ${Object.keys(result).length} projects`);
    }
    return result;
}

/**
 * Syncs location projects with facilities from company and referrer projects.
 * This automatically aggregates all facilities by state from other project types.
 * @param {Object} projectsObj - The projects object to sync (modified in place)
 */
function syncLocationProjectsFromSources(projectsObj) {
    if (typeof debugLog === 'function') {
        debugLog('🔄 Syncing location projects from company and referrer sources...');
    }

    // Collect facilities by state from company and referrer projects
    const facilitiesByState = {};

    Object.entries(projectsObj).forEach(([projectName, project]) => {
        // Skip location projects themselves
        if (project.category === 'locations') return;

        // Only process company and referrer projects
        if (project.category !== 'company' && project.category !== 'referrers' && project.category !== 'companies') return;

        // Process each facility in the project - note: data is nested in project.data
        const facilities = project.data?.facilities || [];
        facilities.forEach(facility => {
            // Try to determine the state from the facility
            let state = facility.locationState || '';

            // If no explicit state, try to parse from location field
            if (!state && facility.location) {
                // Parse "City, STATE" or "City, ST" format
                const locationParts = facility.location.split(',').map(p => p.trim());
                if (locationParts.length >= 2) {
                    state = locationParts[locationParts.length - 1];
                }
            }

            // Normalize state to uppercase
            state = state.toUpperCase().trim();

            // Skip if no valid state
            if (!state) return;

            // Check if it's a valid US state
            const stateLower = state.toLowerCase();
            if (!US_STATE_SET.has(stateLower)) {
                // Maybe it's a state abbreviation - try to expand it
                const stateAbbrevs = {
                    'AL': 'ALABAMA', 'AK': 'ALASKA', 'AZ': 'ARIZONA', 'AR': 'ARKANSAS',
                    'CA': 'CALIFORNIA', 'CO': 'COLORADO', 'CT': 'CONNECTICUT', 'DE': 'DELAWARE',
                    'FL': 'FLORIDA', 'GA': 'GEORGIA', 'HI': 'HAWAII', 'ID': 'IDAHO',
                    'IL': 'ILLINOIS', 'IN': 'INDIANA', 'IA': 'IOWA', 'KS': 'KANSAS',
                    'KY': 'KENTUCKY', 'LA': 'LOUISIANA', 'ME': 'MAINE', 'MD': 'MARYLAND',
                    'MA': 'MASSACHUSETTS', 'MI': 'MICHIGAN', 'MN': 'MINNESOTA', 'MS': 'MISSISSIPPI',
                    'MO': 'MISSOURI', 'MT': 'MONTANA', 'NE': 'NEBRASKA', 'NV': 'NEVADA',
                    'NH': 'NEW HAMPSHIRE', 'NJ': 'NEW JERSEY', 'NM': 'NEW MEXICO', 'NY': 'NEW YORK',
                    'NC': 'NORTH CAROLINA', 'ND': 'NORTH DAKOTA', 'OH': 'OHIO', 'OK': 'OKLAHOMA',
                    'OR': 'OREGON', 'PA': 'PENNSYLVANIA', 'RI': 'RHODE ISLAND', 'SC': 'SOUTH CAROLINA',
                    'SD': 'SOUTH DAKOTA', 'TN': 'TENNESSEE', 'TX': 'TEXAS', 'UT': 'UTAH',
                    'VT': 'VERMONT', 'VA': 'VIRGINIA', 'WA': 'WASHINGTON', 'WV': 'WEST VIRGINIA',
                    'WI': 'WISCONSIN', 'WY': 'WYOMING', 'DC': 'WASHINGTON DC'
                };
                if (stateAbbrevs[state]) {
                    state = stateAbbrevs[state];
                } else {
                    return; // Skip invalid states
                }
            } else {
                // Convert to uppercase full name
                state = state.toUpperCase();
            }

            // Initialize state array if needed
            if (!facilitiesByState[state]) {
                facilitiesByState[state] = [];
            }

            // Add facility to state (with source project info)
            facilitiesByState[state].push({
                ...facility,
                _sourceProject: projectName,
                _sourceCategory: project.category
            });
        });
    });

    if (typeof debugLog === 'function') {
        debugLog(`📍 Found facilities in ${Object.keys(facilitiesByState).length} states`);
    }

    // Now update or create location projects for each state with facilities
    Object.entries(facilitiesByState).forEach(([state, facilities]) => {
        const existingProject = projectsObj[state];

        if (existingProject) {
            // Ensure data structure exists
            if (!existingProject.data) {
                existingProject.data = { facilities: [] };
            }

            // Merge facilities: keep existing + add new unique ones
            const existingFacilities = existingProject.data.facilities || [];
            const existingIds = new Set(existingFacilities.map(f => f.id || `${f.name}-${f.location}`));

            // Add new facilities that don't already exist
            let addedCount = 0;
            facilities.forEach(facility => {
                const facilityId = facility.id || `${facility.name}-${facility.location}`;
                if (!existingIds.has(facilityId)) {
                    existingFacilities.push(facility);
                    existingIds.add(facilityId);
                    addedCount++;
                }
            });

            existingProject.data.facilities = existingFacilities;
            existingProject.currentFacilityIndex = existingProject.currentFacilityIndex || 0;

            if (addedCount > 0 && typeof debugLog === 'function') {
                debugLog(`📍 Updated location "${state}": added ${addedCount} new facilities (total: ${existingFacilities.length})`);
            }
        } else {
            // Create new location project with proper nested structure
            projectsObj[state] = {
                name: state,
                category: 'locations',
                data: {
                    facilities: facilities
                },
                currentFacilityIndex: 0,
                timestamp: new Date().toISOString()
            };
            if (typeof debugLog === 'function') {
                debugLog(`📍 Created location project "${state}" with ${facilities.length} facilities`);
            }
        }
    });

    if (typeof debugLog === 'function') {
        debugLog('✅ Location sync complete');
    }
}

// ============================================
// LOCATION UI FUNCTIONS
// ============================================

/**
 * Update location facilities overview/TOC list
 */
function updateLocationFacilitiesOverview() {
    const facilitiesList = document.getElementById('location-facilities-list');
    const facilitiesStats = document.getElementById('location-facilities-toc-stats');

    if (!facilitiesList || !facilitiesStats) return;

    const facilities = window.formData?.facilities || [];
    const currentIndex = window.currentFacilityIndex || 0;

    // Update stats
    facilitiesStats.textContent = `Total: ${facilities.length} facilit${facilities.length !== 1 ? 'ies' : 'y'}`;

    // Clear list
    facilitiesList.innerHTML = '';

    if (facilities.length === 0) {
        facilitiesList.innerHTML = '<div style="padding: 20px; text-align: center; color: #6b7280;">No facilities in this location yet</div>';
        return;
    }

    // Populate facility items
    facilities.forEach((facility, index) => {
        const facilityName = facility.identification?.name || `Facility ${index + 1}`;
        // Check for privately owned facilities - use isPrivatelyOwned flag
        const isPrivate = facility.isPrivatelyOwned === true;
        const operator = facility.identification?.currentOperator || (isPrivate ? 'Privately Owned' : 'Unknown Operator');
        const programType = facility.facilityDetails?.type || '';
        const status = facility.operatingPeriod?.status || '';

        const item = document.createElement('div');
        item.className = 'facility-item' + (index === currentIndex ? ' active' : '');
        item.tabIndex = 0;
        const accessibleFacilityName = facilityName !== 'Unnamed Facility' ? facilityName : `Facility ${index + 1}`;
        item.setAttribute('role', 'button');
        item.setAttribute('aria-label', `View ${accessibleFacilityName}`);

        // Escape HTML
        const div1 = document.createElement('div');
        div1.textContent = facilityName;
        const nameEscaped = div1.innerHTML;

        const div2 = document.createElement('div');
        div2.textContent = operator;
        const operatorEscaped = div2.innerHTML;

        const div3 = document.createElement('div');
        div3.textContent = programType;
        const typeEscaped = div3.innerHTML;

        const div4 = document.createElement('div');
        div4.textContent = status;
        const statusEscaped = div4.innerHTML;

        // Add private ownership badge
        const badgeHTML = isPrivate
            ? '<span class="facility-ownership-badge" title="Click to toggle ownership status">Private</span>'
            : '<span class="facility-ownership-badge not-private" title="Click to toggle ownership status">Chain/Corporate</span>';

        // Add source badge (Independent vs Company/Referrer)
        let sourceBadgeHTML = '';
        if (facility._sourceProject) {
            // Facility synced from another project
            const sourceCategory = facility._sourceCategory || '';
            const div5 = document.createElement('div');
            div5.textContent = facility._sourceProject;
            const sourceProjectEscaped = div5.innerHTML;

            if (sourceCategory === 'company' || sourceCategory === 'companies') {
                sourceBadgeHTML = `<span class="facility-source-badge source-company" title="From company project: ${sourceProjectEscaped}">Company</span>`;
            } else if (sourceCategory === 'referrers') {
                sourceBadgeHTML = `<span class="facility-source-badge source-referrer" title="From referrer project: ${sourceProjectEscaped}">Referrer</span>`;
            } else {
                sourceBadgeHTML = `<span class="facility-source-badge source-other" title="From project: ${sourceProjectEscaped}">Other</span>`;
            }
        } else {
            // Independent facility created directly in location
            sourceBadgeHTML = '<span class="facility-source-badge source-independent" title="Independent facility">Independent</span>';
        }

        item.innerHTML = `
            <div class="facility-item-number">${index + 1}</div>
            <div class="facility-item-info">
                <div class="facility-item-name">${nameEscaped}</div>
                <div class="facility-item-details">
                    ${operatorEscaped}${programType ? ' • ' + typeEscaped : ''}${status ? ' • ' + statusEscaped : ''}
                </div>
            </div>
            <div class="facility-badges">
                ${sourceBadgeHTML}
                ${badgeHTML}
            </div>
        `;

        const selectFacility = () => {
            window.currentFacilityIndex = index;
            if (typeof window.updateAllUI === 'function') {
                window.updateAllUI();
            }
            updateLocationFacilitiesOverview();
        };

        // Add click handler for ownership badge toggle
        const badge = item.querySelector('.facility-ownership-badge');
        if (badge) {
            badge.addEventListener('click', function(e) {
                e.stopPropagation(); // Prevent selecting the facility
                // Toggle private ownership status
                window.formData.facilities[index].isPrivatelyOwned = !window.formData.facilities[index].isPrivatelyOwned;

                // Update form UI if this is the current facility
                if (index === window.currentFacilityIndex) {
                    if (typeof window.updateAllUI === 'function') {
                        window.updateAllUI();
                    }
                }

                if (typeof updateJSON === 'function') updateJSON();
                if (typeof autoSave === 'function') autoSave();
                updateLocationFacilitiesOverview();
            });
        }

        item.addEventListener('click', selectFacility);
        item.addEventListener('keydown', function(event) { // Note: cannot be passive
            if (event.key === 'Enter' || event.key === ' ') {
                event.preventDefault();
                selectFacility();
            }
        });

        facilitiesList.appendChild(item);
    });
}

/**
 * Initialize location facilities TOC toggle button
 */
function initializeLocationFacilitiesToc() {
    const locationFacilitiesTocToggle = document.getElementById('location-facilities-toc-toggle-btn');
    if (locationFacilitiesTocToggle && !locationFacilitiesTocToggle.dataset.listenerAttached) {
        locationFacilitiesTocToggle.addEventListener('click', function() { // UI-only, can be passive
            const toc = document.getElementById('location-facilities-toc');
            const content = toc.querySelector('.toc-content');
            const isCollapsed = content.style.display === 'none';

            if (isCollapsed) {
                content.style.display = 'block';
                locationFacilitiesTocToggle.textContent = '🔎';
            } else {
                content.style.display = 'none';
                locationFacilitiesTocToggle.textContent = '👁️';
            }
        }, { passive: true });
        locationFacilitiesTocToggle.dataset.listenerAttached = 'true';
    }
}

// ============================================
// EXPORTS & INITIALIZATION
// ============================================

// Expose constants to window for global access
window.US_STATE_NAMES = US_STATE_NAMES;
window.COUNTRY_NAMES = COUNTRY_NAMES;
window.US_STATE_SET = US_STATE_SET;
window.COUNTRY_SET = COUNTRY_SET;
window.normalizeLocationProjectName = normalizeLocationProjectName;

// Expose functions to window for global access
window.getAllLocations = getAllLocations;
window.deduplicateLocationProjects = deduplicateLocationProjects;
window.syncLocationProjectsFromSources = syncLocationProjectsFromSources;
window.updateLocationFacilitiesOverview = updateLocationFacilitiesOverview;
window.initializeLocationFacilitiesToc = initializeLocationFacilitiesToc;

// Auto-initialize when DOM is ready
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => {
        console.log('📍 Location form module loaded');
    });
} else {
    console.log('📍 Location form module loaded');
}
