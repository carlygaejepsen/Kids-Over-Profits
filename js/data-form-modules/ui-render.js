// js/data-form-modules/ui-render.js
(function() {
    // Prevent double initialization
    if (window.KOP_UI_Render && window.KOP_UI_Render._initialized) return;

    const KOP_UI_Render = {
        _initialized: true,

        renderSavedProjectsList: function() {
            this.refreshSavedProjectPanels();
        },

        // Helper to recursively search and return the first matching value
        findMatchInObject: function(obj, query) {
            if (!obj || !query) return null;
            const lowerQuery = query.toLowerCase();

            const search = (value) => {
                if (typeof value === 'string') {
                    if (value.toLowerCase().includes(lowerQuery)) return value;
                }
                if (Array.isArray(value)) {
                    for (const item of value) {
                        const match = search(item);
                        if (match) return match;
                    }
                }
                if (typeof value === 'object' && value !== null) {
                    for (const val of Object.values(value)) {
                        const match = search(val);
                        if (match) return match;
                    }
                }
                return null;
            };

            return search(obj);
        },

        refreshSavedProjectPanels: function(searchQueries = {}) {
            const containers = {
                companies: document.getElementById('company-saved-projects-list'),
                locations: document.getElementById('location-saved-projects-list'),
                referrers: document.getElementById('referrer-saved-projects-list')
            };

            const normalizeDisplayKey = (name, project) => {
                const rawName = typeof name === 'string' ? name : '';
                const category = String(project?.category || '').toLowerCase();
                const candidateName = String(project?.name || rawName).trim();
                const isLocationProject = category === 'locations'
                    || category === 'location'
                    || window.US_STATE_SET?.has?.(candidateName.toLowerCase())
                    || window.COUNTRY_SET?.has?.(candidateName.toLowerCase());

                if (isLocationProject && typeof window.normalizeLocationProjectName === 'function') {
                    return window.normalizeLocationProjectName(candidateName) || candidateName;
                }

                return candidateName || rawName;
            };

            // Clear all containers
            Object.values(containers).forEach(container => {
                if (container) container.innerHTML = '';
            });

            // Merge local projects and database projects while collapsing canonical duplicates.
            const allProjects = {};

            Object.entries(window.projects || {}).forEach(([name, project]) => {
                const displayKey = normalizeDisplayKey(name, project);
                allProjects[displayKey] = {
                    ...project,
                    name: displayKey
                };
            });

            if (window.databaseProjects && Object.keys(window.databaseProjects).length > 0) {
                Object.entries(window.databaseProjects).forEach(([name, project]) => {
                    const displayKey = normalizeDisplayKey(name, project);
                    if (allProjects[displayKey]) {
                        return;
                    }

                    allProjects[displayKey] = {
                        ...project,
                        name: displayKey,
                        isDatabase: true
                    };
                });
            }

            // Check if projects exist
            if (!allProjects || Object.keys(allProjects).length === 0) {
                Object.keys(containers).forEach(key => {
                    const container = containers[key];
                    if (container) {
                        container.innerHTML = `<div class="projects-empty">No saved ${key} projects<small>Create a new project to get started</small></div>`;
                    }
                });
                return;
            }

            // Group projects by category
            const projectsByCategory = {
                companies: [],
                locations: [],
                referrers: []
            };

            Object.entries(allProjects).forEach(([name, project]) => {
                let category = project.category;
                if (!category && window.KOP_Project && typeof window.KOP_Project.determineProjectCategory === 'function') {
                    category = window.KOP_Project.determineProjectCategory(name);
                }
                category = (category || 'companies').toLowerCase();

                if (category === 'company') category = 'companies';
                if (category === 'location' || category === 'states') category = 'locations';
                if (category === 'referrer') category = 'referrers';

                // Determine search query for this category
                let searchQuery = '';
                if (category === 'companies') searchQuery = searchQueries.company;
                else if (category === 'locations') searchQuery = searchQueries.location;
                else if (category === 'referrers') searchQuery = searchQueries.referrer;

                let matchSnippet = null;

                // Apply search filter
                if (searchQuery && searchQuery.trim() !== '') {
                    const lowerQuery = searchQuery.toLowerCase().trim();
                    const nameMatch = name.toLowerCase().includes(lowerQuery);
                    
                    if (!nameMatch) {
                        const contentMatch = project.data ? this.findMatchInObject(project.data, lowerQuery) : null;
                        if (!contentMatch) {
                            return; // Skip if no match
                        }
                        matchSnippet = contentMatch;
                    }
                }

                if (projectsByCategory[category]) {
                    projectsByCategory[category].push({ name, ...project, matchSnippet });
                } else {
                    projectsByCategory.companies.push({ name, ...project, matchSnippet });
                }
            });

            // Admin check
            const isAdmin = (window.KOP_FormConfig && window.KOP_FormConfig.DATA_FORM_CONFIG && window.KOP_FormConfig.DATA_FORM_CONFIG.isAdmin) || 
                            (typeof window.FORM_MODE === 'string' && window.FORM_MODE === 'admin');

            // Render lists
            Object.keys(containers).forEach(category => {
                const container = containers[category];
                const projects = projectsByCategory[category];

                if (!container) return;

                if (projects.length === 0) {
                    container.innerHTML = `<div class="projects-empty">No saved ${category} projects<small>Projects you save will appear here</small></div>`;
                    return;
                }

                // Sort alphabetically
                projects.sort((a, b) => a.name.localeCompare(b.name));

                projects.forEach(project => {
                    const item = document.createElement('div');
                    item.className = 'project-item';
                    
                    const isCurrent = window.currentProjectName === project.name;
                    if (isCurrent) {
                        item.classList.add('active');
                        item.style.borderLeft = '4px solid #33A7B5';
                    }

                    // Date formatting
                    let dateStr = '';
                    if (project.timestamp) {
                        try {
                            const date = new Date(project.timestamp);
                            dateStr = date.toLocaleDateString();
                        } catch (e) {}
                    }

                    // Info Column
                    const infoDiv = document.createElement('div');
                    infoDiv.className = 'project-info'; // Helper class for flex
                    // Apply styles to ensure it takes space
                    infoDiv.style.flex = '1';
                    infoDiv.style.minWidth = '0';
                    infoDiv.style.cursor = 'pointer';
                    
                    const nameDiv = document.createElement('div');
                    nameDiv.className = 'project-item-name';
                    nameDiv.textContent = project.name;

                    // Add facility count
                    if (project.data && project.data.facilities && project.data.facilities.length > 1) {
                        const countSpan = document.createElement('span');
                        countSpan.style.fontWeight = 'normal';
                        countSpan.style.fontSize = '12px';
                        countSpan.style.color = '#6b7280';
                        countSpan.style.marginLeft = '6px';
                        countSpan.textContent = `(${project.data.facilities.length})`;
                        nameDiv.appendChild(countSpan);
                    }

                    const dateDiv = document.createElement('div');
                    dateDiv.className = 'project-item-date';
                    dateDiv.textContent = dateStr ? `Saved: ${dateStr}` : '';

                    // Display match snippet if available
                    if (project.matchSnippet) {
                        const snippetDiv = document.createElement('div');
                        snippetDiv.style.fontSize = '11px';
                        snippetDiv.style.color = '#059669'; // Green for match
                        snippetDiv.style.marginTop = '2px';
                        snippetDiv.style.fontStyle = 'italic';
                        snippetDiv.textContent = `Found: "${project.matchSnippet}"`;
                        infoDiv.appendChild(snippetDiv);
                    }

                    infoDiv.appendChild(nameDiv);
                    infoDiv.appendChild(dateDiv);
                    
                    // Click handler
                    infoDiv.onclick = (e) => {
                        e.stopPropagation();
                        if (project.isDatabase || project.source === 'database') {
                            // Load database project directly
                            this.loadDatabaseProject(project);
                        } else if (window.loadProject) {
                            window.loadProject(project.name);
                            if (window.KOP_UI_Render && typeof window.KOP_UI_Render.scrollToFormInput === 'function') {
                                window.KOP_UI_Render.scrollToFormInput();
                            }
                        }
                    };

                    // Actions Column
                    const actionsDiv = document.createElement('div');
                    actionsDiv.className = 'project-item-actions';

                    // Load Button
                    const loadBtn = document.createElement('button');
                    loadBtn.innerHTML = '📂 Load';
                    loadBtn.className = 'project-item-btn project-item-load';
                    loadBtn.title = 'Load Project';
                    loadBtn.onclick = (e) => {
                        e.stopPropagation();
                        if (project.isDatabase || project.source === 'database') {
                            // Load database project directly
                            this.loadDatabaseProject(project);
                        } else if (window.loadProject) {
                            window.loadProject(project.name);
                            if (window.KOP_UI_Render && typeof window.KOP_UI_Render.scrollToFormInput === 'function') {
                                window.KOP_UI_Render.scrollToFormInput();
                            }
                        }
                    };
                    actionsDiv.appendChild(loadBtn);

                    // Admin Buttons
                    if (isAdmin) {
                        const renameBtn = document.createElement('button');
                        renameBtn.innerHTML = '✏️';
                        renameBtn.className = 'project-item-btn project-item-rename';
                        renameBtn.title = 'Rename';
                        renameBtn.onclick = (e) => { 
                            e.stopPropagation();
                            if (window.renameProject) window.renameProject(project.name); 
                        };
                        actionsDiv.appendChild(renameBtn);

                        const deleteBtn = document.createElement('button');
                        deleteBtn.innerHTML = '🗑️';
                        deleteBtn.className = 'project-item-btn project-item-delete';
                        deleteBtn.title = 'Delete';
                        deleteBtn.onclick = (e) => { 
                            e.stopPropagation();
                            if (window.deleteProject) window.deleteProject(project.name); 
                        };
                        actionsDiv.appendChild(deleteBtn);
                    }

                    item.appendChild(infoDiv);
                    item.appendChild(actionsDiv);
                    container.appendChild(item);
                });
            });
        },

        refreshQuickFacilitiesList: function() {
            const list = document.getElementById('quick-facilities-list');
            if (!list) return;

            list.innerHTML = '';
            
            if (!window.formData || !window.formData.facilities || window.formData.facilities.length === 0) {
                list.innerHTML = '<div class="quick-facilities-empty">No facilities yet...</div>';
                return;
            }

            window.formData.facilities.forEach((fac, index) => {
                const item = document.createElement('div');
                item.className = 'facility-quick-item';
                if (window.currentFacilityIndex === index) item.classList.add('active');

                const name = fac.identification?.name || fac.identification?.currentName || `Facility #${index + 1}`;
                const location = fac.location || (fac.locationDetails?.city ? `${fac.locationDetails.city}, ${fac.locationDetails.state || ''}` : '');

                item.innerHTML = `
                    <div class="facility-quick-number">${index + 1}</div>
                    <div class="facility-quick-content">
                        <div class="facility-quick-name">${window.escapeHtmlForAttr ? window.escapeHtmlForAttr(name) : name}</div>
                        <div class="facility-quick-meta">${window.escapeHtmlForAttr ? window.escapeHtmlForAttr(location) : location}</div>
                    </div>
                `;

                item.onclick = () => {
                    window.currentFacilityIndex = index;
                    if (window.updateAllUI) window.updateAllUI();
                };

                list.appendChild(item);
            });
        },

        updateTableOfContents: function() {
            const list = document.getElementById('facility-list') || document.getElementById('consultants-list');
            const stats = document.getElementById('toc-stats') || document.getElementById('consultants-toc-stats');
            if (!list) return;

            list.innerHTML = '';
            
            const activeTab = document.querySelector('.category-tab.active');
            const category = activeTab ? activeTab.dataset.category : 'companies';

            const escapeFn = window.escapeHtmlForAttr || (s => s);

            if (category === 'referrers') {
                const consultants = window.formData?.referrerConsultants || [];
                if (stats) stats.textContent = `Total: ${consultants.length} consultant${consultants.length === 1 ? '' : 's'}`;
                
                consultants.forEach((c, index) => {
                    const item = document.createElement('div');
                    item.className = 'consultant-item';
                    if (window.currentConsultantIndex === index) item.classList.add('active');
                    
                    const name = (c.firstName || c.lastName) ? `${c.firstName} ${c.lastName}`.trim() : `Consultant #${index + 1}`;
                    item.innerHTML = `
                        <div class="consultant-item-number">${index + 1}</div>
                        <div class="consultant-item-info">
                            <div class="consultant-item-name">${escapeFn(name)}</div>
                        </div>
                    `;
                    item.onclick = () => {
                        window.currentConsultantIndex = index;
                        if (window.updateAllUI) window.updateAllUI();
                    };
                    list.appendChild(item);
                });
            } else {
                const facilities = window.formData?.facilities || [];
                if (stats) stats.textContent = `Total: ${facilities.length} facility${facilities.length === 1 ? '' : 's'}`;

                facilities.forEach((fac, index) => {
                    const item = document.createElement('div');
                    item.className = 'facility-item';
                    if (window.currentFacilityIndex === index) item.classList.add('active');

                    const name = fac.identification?.name || fac.identification?.currentName || `Facility #${index + 1}`;
                    item.innerHTML = `
                        <div class="facility-item-number">${index + 1}</div>
                        <div class="facility-item-info">
                            <div class="facility-item-name">${escapeFn(name)}</div>
                        </div>
                    `;
                    item.onclick = () => {
                        window.currentFacilityIndex = index;
                        if (window.updateAllUI) window.updateAllUI();
                    };
                    list.appendChild(item);
                });
            }
        },

        updateFacilityControls: function() {
            const counter = document.getElementById('facility-counter');
            const nameDisp = document.getElementById('current-facility-name');
            const dropdown = document.getElementById('facility-dropdown');

            if (counter && window.formData?.facilities) {
                counter.textContent = `Facility ${window.currentFacilityIndex + 1} of ${window.formData.facilities.length}`;
            }

            if (nameDisp && window.formData?.facilities?.[window.currentFacilityIndex]) {
                const fac = window.formData.facilities[window.currentFacilityIndex];
                nameDisp.textContent = fac.identification?.name || fac.identification?.currentName || 'Unnamed Facility';
            }

            if (dropdown && window.formData?.facilities) {
                dropdown.innerHTML = '';
                window.formData.facilities.forEach((fac, index) => {
                    const opt = document.createElement('option');
                    opt.value = index;
                    opt.selected = (index === window.currentFacilityIndex);
                    const fName = fac.identification?.name || fac.identification?.currentName;
                    opt.textContent = fName ? `${index + 1}. ${fName}` : `Facility ${index + 1}`;
                    dropdown.appendChild(opt);
                });
                
                if (!dropdown.dataset.listener) {
                    dropdown.onchange = (e) => {
                        window.currentFacilityIndex = parseInt(e.target.value);
                        if (window.updateAllUI) window.updateAllUI();
                    };
                    dropdown.dataset.listener = 'true';
                }
            }
            
            this.refreshQuickFacilitiesList();
        },

        navigateToFacility: function(index) {
            if (!window.formData?.facilities || index < 0 || index >= window.formData.facilities.length) return;
            window.currentFacilityIndex = index;
            if (window.updateAllUI) window.updateAllUI();
            this.scrollToFormInput();
        },

        jumpToFacility: function(index) { this.navigateToFacility(index); },
        jumpToItem: function(index) { this.navigateToFacility(index); },

        scrollToFormInput: function() {
            const target = document.querySelector('.facility-loader-panel') || document.getElementById('identification-section') || document.getElementById('operator-section');
            if (target) target.scrollIntoView({ behavior: 'smooth', block: 'start' });
        },

        renderArray: function(container, path, items) {
            if (!container) return;
            container.innerHTML = '';

            // If items is not an array, try to get/create it from formData via path resolution
            let data;
            if (Array.isArray(items)) {
                data = items;
            } else {
                // Resolve the path to get/create the array in formData
                if (typeof window.resolvePathTarget === 'function' && typeof window.getNestedValue === 'function' && typeof window.setNestedValue === 'function') {
                    const { target, normalizedPath } = window.resolvePathTarget(path);
                    if (target) {
                        data = window.getNestedValue(target, normalizedPath);
                        if (!Array.isArray(data)) {
                            data = [];
                            window.setNestedValue(target, normalizedPath, data);
                        }
                    } else {
                        data = [];
                    }
                } else {
                    data = [];
                }
            }

            const isPastTTIJobs = /pastTTIJobs$/.test(path);
            const isStaffRoleName = /^staff\./.test(path) || /^operator\.keyStaff\./.test(path);
            const isAdditionalLocation = /locationDetails\.additionalLocations$/.test(path);

            // Determine autocomplete category based on path
            let defaultCategory = null;
            if (path.includes('parentCompanies') || path === 'otherOperators') defaultCategory = 'operator';
            else if (path.includes('Owners') || path.includes('founders') || path.includes('keyExecutives') || path.includes('investors') || path.includes('keyPersonnel')) defaultCategory = 'human';
            else if (path.includes('Referrers') || path.includes('knownReferrers')) defaultCategory = 'referrer';
            else if (path.includes('knownReferrals') || path.includes('facilitiesReferred')) defaultCategory = 'facility';
            else if (path.includes('accreditations')) defaultCategory = 'accreditation';
            else if (path === 'memberships' || path.includes('affiliations')) defaultCategory = 'membership';
            else if (path === 'certifications') defaultCategory = 'certification';
            else if (path === 'licensing') defaultCategory = 'licensing';

            if (data.length === 0) {
                if (isPastTTIJobs) {
                    data.push({ role: '', employer: '', organization: '' });
                } else if (isStaffRoleName) {
                    data.push({ role: '', name: '' });
                } else if (isAdditionalLocation) {
                    data.push({ city: '', address: '' });
                } else {
                    data.push('');
                }
            }

            data.forEach((item, index) => {
                const row = document.createElement('div');
                row.className = 'array-item';

                if (isPastTTIJobs) {
                    let normalized = item;
                    if (typeof normalized !== 'object' || normalized === null) {
                        normalized = { role: '', employer: item || '', organization: item || '' };
                        data[index] = normalized;
                    }

                    const roleInp = document.createElement('input');
                    roleInp.type = 'text';
                    roleInp.className = 'array-input array-input-role';
                    roleInp.placeholder = 'Role';
                    roleInp.setAttribute('data-autocomplete-category', 'role');
                    roleInp.value = normalized.role || '';
                    roleInp.oninput = (e) => {
                        if (typeof window.updateArrayObjectItemValue === 'function') {
                            window.updateArrayObjectItemValue(path, index, 'role', e.target.value);
                        } else {
                            normalized.role = e.target.value;
                            if (window.updateJSON) window.updateJSON();
                        }
                    };

                    const employerInp = document.createElement('input');
                    employerInp.type = 'text';
                    employerInp.className = 'array-input array-input-name';
                    employerInp.placeholder = 'Employer';
                    employerInp.setAttribute('data-autocomplete-category', 'operator');
                    employerInp.value = normalized.employer || normalized.organization || '';
                    employerInp.oninput = (e) => {
                        if (typeof window.updateArrayObjectItemValue === 'function') {
                            window.updateArrayObjectItemValue(path, index, 'employer', e.target.value);
                        } else {
                            normalized.employer = e.target.value;
                            normalized.organization = e.target.value;
                            if (window.updateJSON) window.updateJSON();
                        }
                    };

                    row.appendChild(roleInp);
                    row.appendChild(employerInp);
                } else if (isStaffRoleName) {
                    let normalized = item;
                    if (typeof normalized !== 'object' || normalized === null) {
                        let role = '';
                        let name = typeof item === 'string' ? item : '';
                        const match = typeof name === 'string' ? name.match(/^([^:]+):\s*(.+)$/) : null;
                        if (match) {
                            role = match[1].trim();
                            name = match[2].trim();
                        }
                        normalized = { role, name, pastJobs: '', links: '' };
                        data[index] = normalized;
                    }

                    // Main Row
                    const mainRow = document.createElement('div');
                    mainRow.style.display = 'flex';
                    mainRow.style.gap = '10px';
                    mainRow.style.width = '100%';
                    mainRow.style.alignItems = 'center';

                    const roleInp = document.createElement('input');
                    roleInp.type = 'text';
                    roleInp.className = 'array-input array-input-role';
                    roleInp.placeholder = 'Role';
                    roleInp.setAttribute('data-autocomplete-category', 'role');
                    roleInp.value = normalized.role || '';
                    roleInp.oninput = (e) => {
                        if (typeof window.updateArrayObjectItemValue === 'function') {
                            window.updateArrayObjectItemValue(path, index, 'role', e.target.value);
                        } else {
                            normalized.role = e.target.value;
                            if (window.updateJSON) window.updateJSON();
                        }
                    };

                    const nameInp = document.createElement('input');
                    nameInp.type = 'text';
                    nameInp.className = 'array-input array-input-name';
                    nameInp.placeholder = 'Name';
                    nameInp.setAttribute('data-autocomplete-category', 'human');
                    nameInp.value = normalized.name || '';
                    nameInp.oninput = (e) => {
                        if (typeof window.updateArrayObjectItemValue === 'function') {
                            window.updateArrayObjectItemValue(path, index, 'name', e.target.value);
                        } else {
                            normalized.name = e.target.value;
                            if (window.updateJSON) window.updateJSON();
                        }
                    };

                    const detailsBtn = document.createElement('button');
                    detailsBtn.type = 'button';
                    detailsBtn.className = 'btn-secondary';
                    detailsBtn.innerHTML = '📝';
                    detailsBtn.title = 'Add details (Past Jobs, Links)';
                    detailsBtn.style.padding = '4px 8px';
                    detailsBtn.style.fontSize = '14px';
                    
                    mainRow.appendChild(roleInp);
                    mainRow.appendChild(nameInp);
                    mainRow.appendChild(detailsBtn);

                    // Details Container
                    const detailsDiv = document.createElement('div');
                    detailsDiv.className = 'staff-details-container';
                    detailsDiv.style.display = 'none';
                    detailsDiv.style.marginTop = '10px';
                    detailsDiv.style.padding = '10px';
                    detailsDiv.style.background = '#f9f9f9';
                    detailsDiv.style.border = '1px solid #eee';
                    detailsDiv.style.borderRadius = '6px';
                    detailsDiv.style.width = '100%';

                    // Past Jobs
                    const jobsLabel = document.createElement('label');
                    jobsLabel.textContent = 'Past TTI Employment (Role - Employer, one per line)';
                    jobsLabel.style.display = 'block';
                    jobsLabel.style.fontSize = '12px';
                    jobsLabel.style.marginBottom = '4px';
                    jobsLabel.style.color = '#666';
                    
                    const jobsInp = document.createElement('textarea');
                    jobsInp.className = 'input-form';
                    jobsInp.rows = 3;
                    jobsInp.style.fontSize = '13px';
                    jobsInp.value = normalized.pastJobs || '';
                    jobsInp.placeholder = 'e.g. Program Director - Old School\nTherapist - Another Place';
                    jobsInp.oninput = (e) => {
                        if (typeof window.updateArrayObjectItemValue === 'function') {
                            window.updateArrayObjectItemValue(path, index, 'pastJobs', e.target.value);
                        } else {
                            normalized.pastJobs = e.target.value;
                            if (window.updateJSON) window.updateJSON();
                        }
                    };

                    // Links
                    const linksLabel = document.createElement('label');
                    linksLabel.textContent = 'Profile Links (one per line)';
                    linksLabel.style.display = 'block';
                    linksLabel.style.fontSize = '12px';
                    linksLabel.style.marginBottom = '4px';
                    linksLabel.style.marginTop = '10px';
                    linksLabel.style.color = '#666';

                    const linksInp = document.createElement('textarea');
                    linksInp.className = 'input-form';
                    linksInp.rows = 2;
                    linksInp.style.fontSize = '13px';
                    linksInp.value = normalized.links || '';
                    linksInp.placeholder = 'https://linkedin.com/in/...\nhttps://news-article.com/...';
                    linksInp.oninput = (e) => {
                        if (typeof window.updateArrayObjectItemValue === 'function') {
                            window.updateArrayObjectItemValue(path, index, 'links', e.target.value);
                        } else {
                            normalized.links = e.target.value;
                            if (window.updateJSON) window.updateJSON();
                        }
                    };

                    detailsDiv.appendChild(jobsLabel);
                    detailsDiv.appendChild(jobsInp);
                    detailsDiv.appendChild(linksLabel);
                    detailsDiv.appendChild(linksInp);

                    // Toggle logic
                    detailsBtn.onclick = () => {
                        const isHidden = detailsDiv.style.display === 'none';
                        detailsDiv.style.display = isHidden ? 'block' : 'none';
                        detailsBtn.style.background = isHidden ? '#e2e8f0' : '';
                    };

                    // Auto-open if data exists
                    if (normalized.pastJobs || normalized.links) {
                        detailsDiv.style.display = 'block';
                        detailsBtn.style.background = '#e2e8f0';
                    }

                    row.style.flexWrap = 'wrap'; // Allow wrapping for details
                    row.appendChild(mainRow);
                    row.appendChild(detailsDiv);
                } else if (isAdditionalLocation) {
                    let normalized = item;
                    if (typeof normalized !== 'object' || normalized === null) {
                        normalized = { city: '', address: item || '' };
                        data[index] = normalized;
                    }

                    const cityInp = document.createElement('input');
                    cityInp.type = 'text';
                    cityInp.className = 'array-input array-input-name';
                    cityInp.placeholder = 'City';
                    cityInp.value = normalized.city || '';
                    cityInp.oninput = (e) => {
                        if (typeof window.updateArrayObjectItemValue === 'function') {
                            window.updateArrayObjectItemValue(path, index, 'city', e.target.value);
                        } else {
                            normalized.city = e.target.value;
                            if (window.updateJSON) window.updateJSON();
                        }
                    };

                    const addressInp = document.createElement('input');
                    addressInp.type = 'text';
                    addressInp.className = 'array-input array-input-name';
                    addressInp.placeholder = 'Address';
                    addressInp.value = normalized.address || '';
                    addressInp.oninput = (e) => {
                        if (typeof window.updateArrayObjectItemValue === 'function') {
                            window.updateArrayObjectItemValue(path, index, 'address', e.target.value);
                        } else {
                            normalized.address = e.target.value;
                            if (window.updateJSON) window.updateJSON();
                        }
                    };

                    row.appendChild(cityInp);
                    row.appendChild(addressInp);
                } else if (typeof item === 'object' && item !== null) {
                    const roleInp = document.createElement('input');
                    roleInp.type = 'text';
                    roleInp.className = 'array-input array-input-role';
                    roleInp.placeholder = 'Role';
                    roleInp.setAttribute('data-autocomplete-category', 'role');
                    roleInp.value = item.role || '';
                    roleInp.oninput = (e) => {
                        item.role = e.target.value;
                        if (window.updateJSON) window.updateJSON();
                    };

                    const nameInp = document.createElement('input');
                    nameInp.type = 'text';
                    nameInp.className = 'array-input array-input-name';
                    nameInp.placeholder = 'Name';
                    if (defaultCategory) nameInp.setAttribute('data-autocomplete-category', defaultCategory);
                    nameInp.value = item.name || item.organization || item.employer || '';
                    nameInp.oninput = (e) => {
                        item.name = e.target.value;
                        if (window.updateJSON) window.updateJSON();
                    };

                    row.appendChild(roleInp);
                    row.appendChild(nameInp);
                } else {
                    const inp = document.createElement('input');
                    inp.type = 'text';
                    inp.className = 'array-input';
                    if (defaultCategory) inp.setAttribute('data-autocomplete-category', defaultCategory);
                    inp.value = item || '';
                    inp.oninput = (e) => {
                        if (typeof window.updateArrayItemValue === 'function') {
                            window.updateArrayItemValue(path, index, e.target.value);
                        } else {
                            data[index] = e.target.value;
                            if (window.updateJSON) window.updateJSON();
                        }
                    };
                    row.appendChild(inp);
                }

                const delBtn = document.createElement('button');
                delBtn.type = 'button';
                delBtn.className = 'remove-btn';
                delBtn.textContent = '×';
                delBtn.onclick = () => {
                    data.splice(index, 1);
                    this.renderArray(container, path, data);
                    if (window.updateJSON) window.updateJSON();
                };
                row.appendChild(delBtn);
                container.appendChild(row);
            });

            const addBtn = document.createElement('button');
            addBtn.type = 'button';
            addBtn.className = 'add-item-btn';
            addBtn.textContent = '+ Add Item';
            addBtn.onclick = () => {
                if (isPastTTIJobs) {
                    data.push({ role: '', employer: '', organization: '' });
                } else if (isStaffRoleName) {
                    data.push({ role: '', name: '' });
                } else if (isAdditionalLocation) {
                    data.push({ city: '', address: '' });
                } else {
                    data.push('');
                }
                this.renderArray(container, path, data);
            };
            container.appendChild(addBtn);

            // Re-attach field note buttons to the newly rendered items
            if (window.NotesModule && typeof window.NotesModule.addNoteButtonsToArrayItems === 'function') {
                window.NotesModule.addNoteButtonsToArrayItems(container);
            }

            // Manually re-initialize autocomplete for the newly added fields
            if (typeof window.initializeAutocompleteFields === 'function') {
                window.initializeAutocompleteFields();
            }
        },

        generateReportHTML: function(data, skipHeader) {
            return '';
        },

        /**
         * Load a database project into the form
         * @param {Object} project The database project to load
         */
        loadDatabaseProject: function(project) {
            if (!project || !project.data) {
                console.error('Invalid database project');
                return;
            }

            // Clear current form data
            if (window.clearForm && typeof window.clearForm === 'function') {
                window.clearForm();
            }

            // Create a project structure compatible with the form loader
            const projectData = {
                name: project.name,
                label: project.label || project.name,
                data: project.data,
                category: project.category || 'companies',
                timestamp: null,
                currentFacilityIndex: 0,
                source: 'database'
            };

            // Set current project name and sync DOM input (save button reads from #project-name)
            window.currentProjectName = project.name;
            const projectNameInput = document.getElementById('project-name');
            if (projectNameInput) projectNameInput.value = project.name;

            // Set facility index
            window.currentFacilityIndex = 0;

            // Switch to the correct category tab
            const categoryMap = { locations: 'states', referrers: 'referrers', companies: 'companies' };
            const tabCategory = categoryMap[projectData.category] || projectData.category;
            const targetTab = document.querySelector(`.category-tab[data-category="${tabCategory}"]`);
            if (targetTab && !targetTab.classList.contains('active')) {
                targetTab.click();
            }

            // Load the project data into the form
            if (window.KOP_FormData && typeof window.KOP_FormData.loadProjectData === 'function') {
                window.KOP_FormData.loadProjectData(projectData);
            } else if (window.loadProjectData && typeof window.loadProjectData === 'function') {
                window.loadProjectData(projectData);
            } else if (window.populateForm && typeof window.populateForm === 'function') {
                window.populateForm(projectData.data);
            } else {
                // Fallback: set the data directly
                window.formData = projectData.data;
                if (window.updateJSON && typeof window.updateJSON === 'function') {
                    window.updateJSON();
                }
            }

            // Refresh all UI panels
            if (typeof window.updateAllUI === 'function') {
                window.updateAllUI();
            }

            // Scroll to form
            if (this.scrollToFormInput && typeof this.scrollToFormInput === 'function') {
                this.scrollToFormInput();
            }

            console.log(`Loaded database project: ${project.name}`);
        }
    };

    window.KOP_UI_Render = KOP_UI_Render;
})();
