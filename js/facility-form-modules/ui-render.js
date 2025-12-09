// All rendering functions extracted from facility-form.v4.js

window.KOP_UI_Render = {
    renderArray: function(container, path, items) {
        if (!container) return;

        const { scope, normalizedPath, target } = resolvePathTarget(path);
        if (!target) {
            return;
        }

        if (!Array.isArray(window.noteFieldRegistry)) {
            window.noteFieldRegistry = [];
        } else {
            const pathPrefix = `${path}.`;
            window.noteFieldRegistry = window.noteFieldRegistry.filter(entry => {
                if (!entry || !entry.key) {
                    return false;
                }
                return !entry.key.startsWith(pathPrefix);
            });
        }

        // Set up event delegation on container if not already done
        if (!container.dataset.delegationInit) {
            container.addEventListener('click', (e) => { // Note: cannot be passive
                if (e.target.classList.contains('add-item-btn')) {
                    e.preventDefault();
                    e.stopPropagation();
                    e.stopImmediatePropagation();
                    const btnPath = e.target.dataset.arrayPath;
                    if (btnPath) {
                        addNewArrayItem(btnPath);
                    }
                }
            });
            container.dataset.delegationInit = 'true';
        }

        // Preserve the add button to avoid click issues, but remove it temporarily
        let addButton = container.querySelector('.add-item-btn');
        if (addButton) {
            addButton.remove();
        }

        // Remove only array items
        const existingItems = container.querySelectorAll('.array-item');
        existingItems.forEach(item => item.remove());

        const existingNoteWrappers = container.querySelectorAll('.array-item-notes');
        existingNoteWrappers.forEach(wrapper => wrapper.remove());

        const sourceItems = items !== undefined ? items : getNestedValue(target, normalizedPath);
        const itemsArray = Array.isArray(sourceItems) ? sourceItems : (sourceItems ? [sourceItems] : []);

        const isPastTTIJobs = /pastTTIJobs$/.test(path);
        const isStaff = /^staff\./.test(path) || /^operator\.keyStaff\./.test(path);
        const isStaffRoleName = isStaff && !isPastTTIJobs;
        const isAdditionalLocation = /locationDetails\.additionalLocations$/.test(path);

        // If array is empty, initialize it with one empty item so user input gets saved
        if (itemsArray.length === 0) {
            let array = getNestedValue(target, normalizedPath);

            // If array doesn't exist, create it
            if (!Array.isArray(array)) {
                array = [];
                setNestedValue(target, normalizedPath, array);
            }

            if (array.length === 0) {
                let emptyItem = '';
                if (isPastTTIJobs) {
                    emptyItem = { role: '', organization: '' };
                } else if (isStaffRoleName) {
                    emptyItem = { role: '', name: '' };
                } else if (isAdditionalLocation) {
                    emptyItem = { city: '', address: '' };
                }
                array.push(emptyItem);
                // Re-render with the updated array
                this.renderArray(container, path, array);
                return;
            }
        }

        // Normalize items: convert strings to structured objects for staff arrays
        const itemsToShow = itemsArray.map((item, idx) => {
            if (isPastTTIJobs && typeof item === 'string') {
                const normalizedItem = { role: '', organization: item, employer: item };
                const array = getNestedValue(target, normalizedPath);
                if (Array.isArray(array)) {
                    array[idx] = normalizedItem;
                }
                return normalizedItem;
            }
            if (isStaffRoleName && typeof item === 'string') {
                // Parse legacy string format - check for "Role: Name" pattern
                const colonMatch = item.match(/^([^:]+):\s*(.+)$/);
                let role = '';
                let name = item;
                if (colonMatch) {
                    role = colonMatch[1].trim();
                    name = colonMatch[2].trim();
                }
                // Update the actual data array to the new format
                const array = getNestedValue(target, normalizedPath);
                if (Array.isArray(array)) {
                    array[idx] = { role, name };
                }
                return { role, name };
            }
            return item;
        });

        itemsToShow.forEach((item, index) => {
            const itemDiv = document.createElement('div');
            itemDiv.className = 'array-item';
            const scopeForNotes = scope;
            const noteKey = `${path}.${index}`;

            if (isPastTTIJobs) {
                const roleInput = document.createElement('input');
                roleInput.type = 'text';
                roleInput.placeholder = 'Role';
                roleInput.value = (item && item.role) ? item.role : '';
                roleInput.className = 'array-input array-input-role';
                roleInput.oninput = () => updateArrayObjectItemValue(path, index, 'role', roleInput.value);
                attachCustomValueRecorder(roleInput, 'role');
                setTimeout(() => {
                    if (!roleInput.dataset.autocompleteInit) {
                        delegateCreateAutocomplete(roleInput, getAllStaffRoles, 'role');
                        roleInput.dataset.autocompleteInit = 'true';
                    }
                }, 100);
                itemDiv.appendChild(roleInput);

                const orgInput = document.createElement('input');
                orgInput.type = 'text';
                orgInput.placeholder = 'Employer';
                orgInput.value = (item && (item.employer || item.organization)) ? (item.employer || item.organization) : '';
                orgInput.className = 'array-input array-input-name';
                orgInput.oninput = () => updateArrayObjectItemValue(path, index, 'employer', orgInput.value);
                attachCustomValueRecorder(orgInput, 'operator');
                itemDiv.appendChild(orgInput);
                setTimeout(() => {
                    delegateCreateAutocomplete(orgInput, getAllOperators, 'operator');
                    orgInput.dataset.autocompleteInit = 'true';
                }, 100);
            } else if (isStaffRoleName) {
                const roleInput = document.createElement('input');
                roleInput.type = 'text';
                roleInput.placeholder = 'Role';
                roleInput.value = (item && item.role) ? item.role : '';
                roleInput.className = 'array-input array-input-role';
                roleInput.oninput = () => updateArrayObjectItemValue(path, index, 'role', roleInput.value);
                attachCustomValueRecorder(roleInput, 'role');
                setTimeout(() => {
                    if (!roleInput.dataset.autocompleteInit) {
                        delegateCreateAutocomplete(roleInput, getAllStaffRoles, 'role');
                        roleInput.dataset.autocompleteInit = 'true';
                    }
                }, 100);
                itemDiv.appendChild(roleInput);

                const nameInput = document.createElement('input');
                nameInput.type = 'text';
                nameInput.placeholder = 'Name';
                nameInput.value = (item && item.name) ? item.name : '';
                nameInput.className = 'array-input array-input-name';
                nameInput.oninput = () => updateArrayObjectItemValue(path, index, 'name', nameInput.value);
                attachCustomValueRecorder(nameInput, 'human');
                itemDiv.appendChild(nameInput);
                setTimeout(() => {
                    delegateCreateAutocomplete(nameInput, getAllHumanNames, 'human');
                    nameInput.dataset.autocompleteInit = 'true';
                }, 100);
            } else if (isAdditionalLocation) {
                const cityInput = document.createElement('input');
                cityInput.type = 'text';
                cityInput.placeholder = 'City';
                cityInput.value = (item && item.city) ? item.city : '';
                cityInput.className = 'array-input array-input-name';
                cityInput.oninput = () => updateArrayObjectItemValue(path, index, 'city', cityInput.value);
                itemDiv.appendChild(cityInput);

                const addressInput = document.createElement('input');
                addressInput.type = 'text';
                addressInput.placeholder = 'Address';
                addressInput.value = (item && item.address) ? item.address : '';
                addressInput.className = 'array-input array-input-name';
                addressInput.oninput = () => updateArrayObjectItemValue(path, index, 'address', addressInput.value);
                itemDiv.appendChild(addressInput);
            } else if (/websites$/.test(path) || /profileLinks$/.test(path)) {
                // URL fields with display text
                const urlInput = document.createElement('input');
                urlInput.type = 'url';
                urlInput.placeholder = 'URL (e.g., https://example.com)';
                // Extract URL from item (could be string or object)
                let urlValue = '';
                let displayTextValue = '';
                if (item) {
                    if (typeof item === 'string') {
                        urlValue = item;
                    } else if (typeof item === 'object') {
                        urlValue = item.url || item.link || item.href || '';
                        displayTextValue = item.displayText || item.text || item.label || item.title || '';
                    }
                }
                urlInput.value = urlValue;
                urlInput.className = 'array-input array-input-url';
                urlInput.style.flex = '2';
                urlInput.oninput = () => updateArrayObjectItemValue(path, index, 'url', urlInput.value);
                itemDiv.appendChild(urlInput);

                const displayTextInput = document.createElement('input');
                displayTextInput.type = 'text';
                displayTextInput.placeholder = 'Display Text (optional)';
                displayTextInput.value = displayTextValue;
                displayTextInput.className = 'array-input array-input-display-text';
                displayTextInput.style.flex = '1';
                displayTextInput.oninput = () => updateArrayObjectItemValue(path, index, 'displayText', displayTextInput.value);
                itemDiv.appendChild(displayTextInput);
            } else {
                const input = document.createElement('input');
                input.type = 'text';
                // Safely extract string value from item (handles objects that might show as [object Object])
                let displayValue = '';
                if (item !== null && item !== undefined) {
                    if (typeof item === 'string') {
                        displayValue = item;
                    } else if (typeof item === 'object') {
                        // Try common field names that might hold the actual value
                        displayValue = item.name || item.value || item.text || item.label || 
                                       item.title || item.url || item.link || '';
                        // If still an object, try to get first string property
                        if (typeof displayValue === 'object') {
                            displayValue = '';
                        }
                    } else {
                        displayValue = String(item);
                    }
                }
                input.value = displayValue;
                input.className = 'array-input';
                input.oninput = () => updateArrayItemValue(path, index, input.value);

                let category = null;
                let dataFunc = () => [];
                if (/identification\.otherNames$/.test(path)) {
                    category = 'facility';
                    dataFunc = getAllFacilityNames;
                } else if (/identification\.currentOwners$/.test(path)) {
                    category = 'human';
                    dataFunc = getAllHumanNames;
                } else if (/identification\.knownReferrers$/.test(path)) {
                    category = 'referrer';
                    dataFunc = getAllReferrers;
                } else if (/operator\.otherNames$/.test(path) || /operator\.parentCompanies$/.test(path) || /otherOperators$/.test(path)) {
                    category = 'operator';
                    dataFunc = getAllOperators;
                } else if (/operator\.keyStaff\.founders$/.test(path) || /operator\.keyStaff\.keyExecutives$/.test(path)) {
                    category = 'human';
                    dataFunc = getAllHumanNames;
                } else if (/accreditations\.current$/.test(path) || /accreditations\.past$/.test(path)) {
                    category = 'accreditation';
                    dataFunc = getAllAccreditations;
                } else if (/memberships$/.test(path)) {
                    category = 'membership';
                    dataFunc = getAllMemberships;
                } else if (/certifications$/.test(path)) {
                    category = 'certification';
                    dataFunc = getAllCertifications;
                } else if (/licensing$/.test(path)) {
                    category = 'licensing';
                    dataFunc = () => Array.from(customLicensing);
                } else if (/investors$/.test(path)) {
                    category = 'investor';
                    dataFunc = () => Array.from(customInvestors);
                } else if (/referrerIndividual\.knownReferrals$/.test(path)) {
                    category = 'facility';
                    dataFunc = getAllFacilityNames;
                } else if (/referrerGroup\.affiliations$/.test(path) || /referrerIndividual\.affiliations$/.test(path) || /consultant\.affiliations$/.test(path)) {
                    category = 'membership';
                    dataFunc = getAllMemberships;
                } else if (/consultant\.facilitiesReferred$/.test(path)) {
                    category = 'facility';
                    dataFunc = getAllFacilityNames;
                } else if (/consultant\.schoolDistricts$/.test(path)) {
                    category = 'location';
                    dataFunc = () => Array.from(window.US_STATE_SET);
                } else if (/referrerAgency\.keyPersonnel$/.test(path)) {
                    category = 'human';
                    dataFunc = getAllHumanNames;
                }

                if (category) {
                    itemDiv.appendChild(input); // Must be in DOM for createAutocomplete to find parent
                    setTimeout(() => {
                        if (!input.dataset.autocompleteInit) {
                            delegateCreateAutocomplete(input, dataFunc, category);
                            input.dataset.autocompleteInit = 'true';
                        }
                    }, 100);
                } else {
                    // No autocomplete, so we must wrap it ourselves for consistent styling
                    const wrapper = document.createElement('div');
                    // We can reuse the autocomplete-wrapper class as it provides the flex behavior we need
                    wrapper.className = 'autocomplete-wrapper';
                    wrapper.appendChild(input);
                    itemDiv.appendChild(wrapper);
                }
            }

            // Add Note button for each array item, unless it's a notes array
            const isNoteArray = /notes$/.test(path);
            if (!isNoteArray) {
                const addNoteBtn = document.createElement('button');
                addNoteBtn.type = 'button';
                addNoteBtn.className = 'note-add-btn field-note-btn';
                addNoteBtn.textContent = '+';
                addNoteBtn.setAttribute('aria-label', 'Add note');
                addNoteBtn.addEventListener('click', (e) => { // Note: cannot be passive
                    e.preventDefault();
                    e.stopImmediatePropagation();
                    addFieldNote(scopeForNotes, noteKey);
                });
                itemDiv.appendChild(addNoteBtn);
            }

            // Show remove button when there are 2+ items (so users can remove extras)
            // Don't show when there's only 1 item (removing it would just auto-create an empty one)
            if (itemsToShow.length > 1) {
                const removeBtn = document.createElement('button');
                removeBtn.className = 'btn remove-btn';
                removeBtn.textContent = '−';
                removeBtn.type = 'button';
                removeBtn.onclick = () => removeArrayItemAtIndex(path, index);
                itemDiv.appendChild(removeBtn);
            }

            // Container for notes for this specific item
            let notesContainer = null;
            let noteWrapper = null;

            if (!isNoteArray) {
                notesContainer = document.createElement('div');
                notesContainer.className = 'field-notes';
                notesContainer.dataset.noteContainerKey = noteKey;
                notesContainer.dataset.noteScope = scopeForNotes;

                noteWrapper = document.createElement('div');
                noteWrapper.className = 'array-item-notes';
                noteWrapper.dataset.arrayPath = path;
                noteWrapper.dataset.arrayIndex = `${index}`;
                noteWrapper.appendChild(notesContainer);

                // Register this container so it can be rendered
                window.noteFieldRegistry.push({ scope: scopeForNotes, key: noteKey, container: notesContainer });
            }

            container.appendChild(itemDiv);
            if (noteWrapper) {
                container.appendChild(noteWrapper);
            }
        });

        // Generate descriptive button label based on path
        let buttonLabel = 'Add More';
        if (/^staff\.administrator$/.test(path)) {
            buttonLabel = 'Add More Administrators';
        } else if (/^staff\.notableStaff$/.test(path)) {
            buttonLabel = 'Add More Notable Staff';
        } else if (/operator\.keyStaff\.founders$/.test(path)) {
            buttonLabel = 'Add More Founders';
        } else if (/operator\.keyStaff\.keyExecutives$/.test(path)) {
            buttonLabel = 'Add More Key Executives';
        } else if (/operator\.parentCompanies$/.test(path)) {
            buttonLabel = 'Add More Parent Companies';
        } else if (/operator\.investors$/.test(path)) {
            buttonLabel = 'Add More Investors';
        } else if (/operator\.otherNames$/.test(path)) {
            buttonLabel = 'Add More Names';
        } else if (/identification\.otherNames$/.test(path)) {
            buttonLabel = 'Add More Names';
        } else if (/^identification\.currentOwners$/.test(path)) {
            buttonLabel = 'Add More Owners';
        } else if (/identification\.knownReferrers$/.test(path)) {
            buttonLabel = 'Add More Referrers';
        } else if (/otherOperators$/.test(path)) {
            buttonLabel = 'Add More Operators';
        } else if (/accreditations\.current$/.test(path)) {
            buttonLabel = 'Add More Accreditations';
        } else if (/accreditations\.past$/.test(path)) {
            buttonLabel = 'Add More Past Accreditations';
        } else if (/memberships$/.test(path)) {
            buttonLabel = 'Add More Memberships';
        } else if (/certifications$/.test(path)) {
            buttonLabel = 'Add More Certifications';
        } else if (/licensing$/.test(path)) {
            buttonLabel = 'Add More Licensing Info';
        } else if (/websites$/.test(path)) {
            buttonLabel = 'Add More Websites';
        } else if (/profileLinks$/.test(path)) {
            buttonLabel = 'Add More Profile Links';
        } else if (/notes$/.test(path)) {
            buttonLabel = 'Add More Notes';
        } else if (/operatingPeriod\.notes$/.test(path)) {
            buttonLabel = 'Add More Operational Notes';
        } else if (/resources\.notes$/.test(path)) {
            buttonLabel = 'Add More Resource Notes';
        } else if (/locationDetails\.additionalLocations$/.test(path)) {
            buttonLabel = 'Add Another Location';
        } else if (/referrerGroup\.affiliations$/.test(path)) {
            buttonLabel = 'Add More Affiliations';
        } else if (/referrerIndividual\.affiliations$/.test(path)) {
            buttonLabel = 'Add More Affiliations';
        } else if (/referrerIndividual\.knownReferrals$/.test(path)) {
            buttonLabel = 'Add More Referrals';
        } else if (/pastTTIJobs$/.test(path)) {
            buttonLabel = 'Add More TTI Roles';
        }

        // Re-create the add button if it was removed
        if (!addButton) {
            addButton = document.createElement('button');
            addButton.className = 'add-item-btn';
            addButton.type = 'button';
        }

        // Update button text and ensure path is set BEFORE appending
        addButton.textContent = buttonLabel;
        addButton.dataset.arrayPath = path;
        if (!addButton.dataset.clickInit) {
            addButton.addEventListener('click', (e) => {
                e.preventDefault();
                e.stopPropagation();
                addNewArrayItem(addButton.dataset.arrayPath || path);
            });
            addButton.dataset.clickInit = 'true';
        }

        // Always append at the end to ensure correct position
        container.appendChild(addButton);

        // Render notes for the array items
        renderAllFieldNotes();
    },

    /**
     * Filter projects based on search query
     * Searches by project name, facility program type, and keywords
     */
    filterProjectsBySearch: function(projects, searchQuery) {
        if (!searchQuery || searchQuery.trim() === '') {
            return projects;
        }

        const query = searchQuery.toLowerCase().trim();
        const filtered = {};

        Object.entries(projects).forEach(([name, project]) => {
            const data = project.data || project;

            // Search in project name
            if (name.toLowerCase().includes(query)) {
                filtered[name] = project;
                return;
            }

            // Search in operator name
            if (data.operator?.name && data.operator.name.toLowerCase().includes(query)) {
                filtered[name] = project;
                return;
            }

            // Search in facilities
            const facilities = data.facilities || [];
            const hasMatch = facilities.some(facility => {
                // Search in facility name
                if (facility.identification?.name && facility.identification.name.toLowerCase().includes(query)) {
                    return true;
                }

                // Search in program type (facility type)
                if (facility.facilityDetails?.type && facility.facilityDetails.type.toLowerCase().includes(query)) {
                    return true;
                }

                // Search in operator name
                if (facility.identification?.currentOperator && facility.identification.currentOperator.toLowerCase().includes(query)) {
                    return true;
                }

                // Search in location
                if (facility.location && typeof facility.location === 'string' && facility.location.toLowerCase().includes(query)) {
                    return true;
                }

                if (facility.locationCity && facility.locationCity.toLowerCase().includes(query)) {
                    return true;
                }

                if (facility.locationState && facility.locationState.toLowerCase().includes(query)) {
                    return true;
                }

                // Search in status
                if (facility.operatingPeriod?.status && facility.operatingPeriod.status.toLowerCase().includes(query)) {
                    return true;
                }

                return false;
            });

            if (hasMatch) {
                filtered[name] = project;
            }
        });

        return filtered;
    },

    renderSavedProjectsList: function() {
        if (window.KOP_UI_Render) window.KOP_UI_Render.refreshSavedProjectPanels();
    },

    refreshSavedProjectPanels: function(searchQueries = {}) {
        const projects = window.projects || {};
        const projectNames = Object.keys(projects);
        const companyContainer = document.getElementById('company-saved-projects-list');
        const locationContainer = document.getElementById('location-saved-projects-list');
        const referrerContainer = document.getElementById('referrer-saved-projects-list');
        const quickProjectsContainer = document.getElementById('quick-projects-list');

        // DEBUG: Log project categorization
        console.log('🔍 refreshSavedProjectPanels called');
        console.log('Total projects:', projectNames.length);
        console.log('US_STATE_SET defined?', typeof window.US_STATE_SET !== 'undefined');
        console.log('COUNTRY_SET defined?', typeof window.COUNTRY_SET !== 'undefined');

        // Log each project's category
        projectNames.forEach(name => {
            const category = determineProjectCategory(name);
            console.log(`  - "${name}" → category: ${category}`);
        });

        if (!companyContainer && !locationContainer && !referrerContainer) {
            console.warn('⚠️ No project containers found in DOM');
            return;
        }

        const buildProjectCards = (names, emptyMessage) => {
            if (!names.length) {
                return `<div class="projects-empty">${emptyMessage}</div>`;
            }

            // Sort alphabetically by default
            const sortedNames = names.slice().sort((a, b) => a.localeCompare(b));

            return sortedNames.map(name => {
                const project = window.projects[name];
                const date = new Date(project.timestamp || 0);
                const dateStr = date.toLocaleDateString() + ' ' + date.toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'});
                
                const facilityCount = project.data?.facilities?.length || 0;
                const facilityLabel = determineProjectCategory(name) === 'referrers' ? (facilityCount === 1 ? 'individual' : 'individuals') : (facilityCount === 1 ? 'facility' : 'facilities');
                
                // Admin-only buttons
                const adminButtons = isSuggestionMode() ? '' : `
                            <button class="project-item-btn project-item-reclassify" onclick="event.stopPropagation(); recategorizeProject('${escapeHtmlForAttr(name)}')">Reclassify</button>
                            <button class="project-item-btn project-item-rename" onclick="event.stopPropagation(); renameProject('${escapeHtmlForAttr(name)}')">Rename</button>
                            <button class="project-item-btn project-item-delete" onclick="event.stopPropagation(); deleteProject('${escapeHtmlForAttr(name)}')">Delete</button>
                        `;

                return `<div class="project-item" onclick="loadProject('${escapeHtmlForAttr(name)}')">
                        <div class="project-item-name" title="${escapeHtmlForAttr(name)}">${escapeHtmlForAttr(name)}</div>
                        <div class="project-item-date">${escapeHtmlForAttr(dateStr)}<br><small>${facilityCount} ${facilityLabel}</small></div>
                        <div class="project-item-actions">
                            <button class="project-item-btn project-item-load" onclick="event.stopPropagation(); loadProject('${escapeHtmlForAttr(name)}')">Load</button>
                            ${adminButtons}
                        </div>
                    </div>`;
            }).join('');
        };

        if (companyContainer) {
            // Filter by category first, then by search query
            let companyProjects = {};
            projectNames.forEach(name => {
                if (determineProjectCategory(name) === 'companies') {
                    companyProjects[name] = projects[name];
                }
            });

            // Apply search filter if query exists
            if (searchQueries.company) {
                companyProjects = this.filterProjectsBySearch(companyProjects, searchQueries.company);
            }

            const companyNames = Object.keys(companyProjects);
            console.log(`📦 Company projects: ${companyNames.length}`, companyNames);
            const emptyMessage = searchQueries.company
                ? `📭 No company projects match "${searchQueries.company}"`
                : '📭 No saved company projects yet';
            companyContainer.innerHTML = buildProjectCards(companyNames, emptyMessage);
        }

        if (locationContainer) {
            // Filter by category first, then by search query
            let locationProjects = {};
            projectNames.forEach(name => {
                if (determineProjectCategory(name) === 'locations') {
                    locationProjects[name] = projects[name];
                }
            });

            // Apply search filter if query exists
            if (searchQueries.location) {
                locationProjects = this.filterProjectsBySearch(locationProjects, searchQueries.location);
            }

            const locationNames = Object.keys(locationProjects);
            console.log(`📍 Location projects: ${locationNames.length}`, locationNames);
            const emptyMessage = searchQueries.location
                ? `📭 No location projects match "${searchQueries.location}"`
                : '📭 No saved location projects yet';
            locationContainer.innerHTML = buildProjectCards(locationNames, emptyMessage);
        }

        if (referrerContainer) {
            // Filter by category first, then by search query
            let referrerProjects = {};
            projectNames.forEach(name => {
                if (determineProjectCategory(name) === 'referrers') {
                    referrerProjects[name] = projects[name];
                }
            });

            // Apply search filter if query exists
            if (searchQueries.referrer) {
                referrerProjects = this.filterProjectsBySearch(referrerProjects, searchQueries.referrer);
            }

            const referrerNames = Object.keys(referrerProjects);
            console.log(`👥 Referrer projects: ${referrerNames.length}`, referrerNames);
            const emptyMessage = searchQueries.referrer
                ? `📭 No referrer projects match "${searchQueries.referrer}"`
                : '📭 No saved referrer projects yet. Create one using the "New Project" button.';
            referrerContainer.innerHTML = buildProjectCards(referrerNames, emptyMessage);
        }

        // Populate quick projects list (all projects combined)
        if (quickProjectsContainer) {
            console.log(`🚀 Quick projects list: ${projectNames.length} total projects`);
            quickProjectsContainer.innerHTML = buildProjectCards(projectNames, '📭 No saved projects yet');
        }

        // Populate quick facilities list
        if (window.KOP_UI_Render) window.KOP_UI_Render.refreshQuickFacilitiesList();
    },

    refreshQuickFacilitiesList: function() {
        const quickFacilitiesContainer = document.getElementById('quick-facilities-list');
        if (!quickFacilitiesContainer) return;

        // Detect current view
        const activeTab = document.querySelector('.category-tab.active');
        const isReferrerView = activeTab && activeTab.dataset.category === 'referrers';

        // Update heading and label
        const heading = document.getElementById('quick-loader-heading');
        const label = document.getElementById('quick-loader-label');

        if (isReferrerView) {
            // Show consultants
            if (heading) heading.textContent = '👥 Jump to Consultant';
            if (label) label.textContent = 'All Consultants in Current Project';

            const consultants = window.formData?.referrerConsultants || [];

            if (!consultants.length) {
                quickFacilitiesContainer.innerHTML = '<div style="color: #6b7280; font-style: italic;">No consultants in current project</div>';
                return;
            }

            // Create array with consultant data and original index, then sort alphabetically
            const consultantsWithIndex = consultants.map((consultant, index) => ({
                consultant,
                originalIndex: index,
                name: (consultant.fullName || [consultant.firstName, consultant.lastName].filter(Boolean).join(' ') || `Consultant ${index + 1}`).toLowerCase()
            }));

            // Sort alphabetically by name (case-insensitive)
            consultantsWithIndex.sort((a, b) => {
                if (a.name.startsWith('consultant ') && !b.name.startsWith('consultant ')) return 1;
                if (b.name.startsWith('consultant ') && !a.name.startsWith('consultant ')) return -1;
                return a.name.localeCompare(b.name);
            });

            const consultantCards = consultantsWithIndex.map(({ consultant, originalIndex }) => {
                const index = originalIndex;
                const fullName = consultant.fullName || [consultant.firstName, consultant.lastName].filter(Boolean).join(' ') || `Consultant ${index + 1}`;
                const location = [consultant.city, consultant.state].filter(Boolean).join(', ') || '';
                const role = consultant.role || consultant.credentials || '';
                const metaParts = [];
                if (location) metaParts.push(escapeHtmlForAttr(location));
                if (role) metaParts.push(escapeHtmlForAttr(role));
                const meta = metaParts.join(' | ');

                return `<div class="facility-quick-item" onclick="jumpToItem(${index})">
                        <div class="facility-quick-number">${index + 1}</div>
                        <div class="facility-quick-content">
                            <div class="facility-quick-name">${escapeHtmlForAttr(fullName)}</div>
                            <div class="facility-quick-meta">${meta || ''}</div>
                        </div>
                    </div>`;
            }).join('');

            quickFacilitiesContainer.innerHTML = consultantCards;
        } else {
            // Show facilities
            if (heading) heading.textContent = '🏢 Jump to Facility';
            if (label) label.textContent = 'All Facilities in Current Project';

            const facilities = window.formData?.facilities || [];

            if (!facilities.length) {
                quickFacilitiesContainer.innerHTML = '<div style="color: #6b7280; font-style: italic;">No facilities in current project</div>';
                return;
            }

            // Create array with facility data and original index, then sort alphabetically
            const facilitiesWithIndex = facilities.map((facility, index) => ({
                facility,
                originalIndex: index,
                name: (facility.identification?.currentName || facility.identification?.name || `Facility ${index + 1}`).toLowerCase()
            }));

            // Sort alphabetically by name (case-insensitive)
            facilitiesWithIndex.sort((a, b) => {
                if (a.name.startsWith('facility ') && !b.name.startsWith('facility ')) return 1;
                if (b.name.startsWith('facility ') && !a.name.startsWith('facility ')) return -1;
                return a.name.localeCompare(b.name);
            });

            const facilityCards = facilitiesWithIndex.map(({ facility, originalIndex }) => {
                const index = originalIndex;
                const name = facility.identification?.currentName || facility.identification?.name || `Facility ${index + 1}`;
                // Safely extract location string (could be an object with city/state)
                let location = 'Unknown location';
                if (facility.location) {
                    if (typeof facility.location === 'string') {
                        location = facility.location;
                    } else if (typeof facility.location === 'object') {
                        // Try to build from city/state
                        const city = facility.location.city || facility.city || '';
                        const state = facility.location.state || facility.state || '';
                        location = [city, state].filter(Boolean).join(', ') || 'Unknown location';
                    }
                } else if (facility.city || facility.state) {
                    location = [facility.city, facility.state].filter(Boolean).join(', ');
                }
                const operator = facility.identification?.currentOperator || '';
                const metaParts = [];
                if (location) metaParts.push(escapeHtmlForAttr(location));
                if (operator) metaParts.push(escapeHtmlForAttr(operator));
                const meta = metaParts.join(' | ');

                return `<div class="facility-quick-item" onclick="jumpToItem(${index})">
                        <div class="facility-quick-number">${index + 1}</div>
                        <div class="facility-quick-content">
                            <div class="facility-quick-name">${escapeHtmlForAttr(name)}</div>
                            <div class="facility-quick-meta">${meta || ''}</div>
                        </div>
                    </div>`;
            }).join('');

            quickFacilitiesContainer.innerHTML = facilityCards;
        }
    },

    generateReportHTML: function(data, skipHeader = false) {
        if (typeof window.FacilityReportGenerator === 'function') {
            return window.FacilityReportGenerator.renderReportHTML(data, skipHeader);
        }
        return '';
    },

    updateFacilityControls: function() {
        const total = window.formData.facilities?.length || 0;
        const facilityCounter = document.getElementById('facility-counter');
        if (facilityCounter) facilityCounter.textContent = `${window.currentFacilityIndex + 1} of ${total}`;
        
        const name = window.formData.facilities?.[window.currentFacilityIndex]?.identification?.name || 'Unnamed Facility';
        const currentFacilityName = document.getElementById('current-facility-name');
        if (currentFacilityName) currentFacilityName.textContent = name !== 'Unnamed Facility' ? `(${name})` : '';

        const prevBtn = document.getElementById('prev-facility-btn');
        const nextBtn = document.getElementById('next-facility-btn');
        const removeBtn = document.getElementById('remove-facility-btn');

        const isMultipleFacilities = total > 1;
        if (prevBtn) {
            prevBtn.classList.toggle('facility-nav-btn-visible', isMultipleFacilities);
            prevBtn.classList.toggle('facility-nav-btn-hidden', !isMultipleFacilities);
        }
        if (nextBtn) {
            nextBtn.classList.toggle('facility-nav-btn-visible', isMultipleFacilities);
            nextBtn.classList.toggle('facility-nav-btn-hidden', !isMultipleFacilities);
        }
        if (removeBtn) {
            removeBtn.classList.toggle('facility-nav-btn-visible', isMultipleFacilities);
            removeBtn.classList.toggle('facility-nav-btn-hidden', !isMultipleFacilities);
        }
    },

    updateTableOfContents: function() {
        const facilityList = document.getElementById('facility-list');
        const tocStats = document.getElementById('toc-stats');
        const total = window.formData.facilities?.length || 0;

        // If no project is loaded, show a helpful message with a link.
        // The link and message are different for the admin page vs. the suggestions page.
        if (!window.currentProjectName) {
            if (tocStats) tocStats.textContent = 'No project loaded';
            if (facilityList) {
                if (isSuggestionMode()) {
                    facilityList.innerHTML = `
                        <div class="toc-no-project">Please <a href="#submission-section">create a draft or load a project</a> to see the list of facilities.</div>
                    `;
                } else {
                    facilityList.innerHTML = `
                        <div class="toc-no-project">Please <a href="#advanced-mode-section">load or create a project</a> to see the list of facilities.</div>
                    `;
                }
            }
            return;
        }

        if (tocStats) tocStats.textContent = `Total: ${total} facilit${total === 1 ? 'y' : 'ies'}`;
        if (facilityList) {
            facilityList.innerHTML = '';

            // Create array with facility data and original index, then sort alphabetically
            const facilitiesWithIndex = window.formData.facilities?.map((facility, index) => ({
                facility,
                originalIndex: index,
                name: facility.identification?.name || 'Unnamed Facility'
            })) || [];

            // Sort alphabetically by name (case-insensitive)
            facilitiesWithIndex.sort((a, b) => {
                const nameA = a.name.toLowerCase();
                const nameB = b.name.toLowerCase();
                if (nameA === 'unnamed facility' && nameB !== 'unnamed facility') return 1;
                if (nameB === 'unnamed facility' && nameA !== 'unnamed facility') return -1;
                return nameA.localeCompare(nameB);
            });

            // Display facilities in alphabetical order
            facilitiesWithIndex.forEach(({ facility, originalIndex, name }, alphabeticalIndex) => {
                const item = document.createElement('div');
                item.className = `facility-item ${originalIndex === window.currentFacilityIndex ? 'active' : ''}`;
                item.innerHTML = `<span class="facility-name ${name === 'Unnamed Facility' ? 'empty' : ''}">${escapeHtmlForAttr(name)}</span><span class="facility-index">${alphabeticalIndex + 1}</span>`;
                item.tabIndex = 0;
                const accessibleFacilityName = name !== 'Unnamed Facility' ? name : `Facility ${alphabeticalIndex + 1}`;
                item.setAttribute('role', 'button');
                item.setAttribute('aria-label', `View ${accessibleFacilityName}`);
                const goToFacility = () => window.KOP_UI_Actions.jumpToFacility(originalIndex);
                item.addEventListener('click', goToFacility);
                item.addEventListener('keydown', function(event) { // Note: cannot be passive
                    if (event.key === 'Enter' || event.key === ' ') {
                        event.preventDefault();
                        goToFacility();
                    }
                });
                facilityList.appendChild(item);
            });
        }

        // Also update the quick facilities list
        if (typeof this.refreshQuickFacilitiesList === 'function') {
            this.refreshQuickFacilitiesList();
        }
    },

    navigateToFacility: function(index) {
        if (index >= 0 && index < window.formData.facilities.length) {
            window.currentFacilityIndex = index;
            loadFacilityData();
            this.updateFacilityControls();
            this.updateTableOfContents();
            if (typeof window.updateToolbarFacilityInfo === 'function') {
                window.updateToolbarFacilityInfo();
            }
            this.scrollToFormInput();
        }
    },

    scrollToFormInput: function() {
        // Small delay to ensure DOM is updated
        setTimeout(() => {
            // Try to scroll to the facility controls (where input starts)
            const facilityControls = document.querySelector('.facility-controls');
            const operatorSection = document.getElementById('operator-section');
            const identificationSection = document.getElementById('identification-section');
            const facilityMainWrapper = document.getElementById('facility-main-wrapper');
            
            // Find the best target to scroll to
            const scrollTarget = facilityControls || operatorSection || identificationSection || facilityMainWrapper;
            
            if (scrollTarget) {
                // Account for fixed toolbar height
                const toolbarHeight = document.querySelector('.fixed-toolbar')?.offsetHeight || 0;
                const offset = toolbarHeight + 20; // Extra padding
                
                const targetPosition = scrollTarget.getBoundingClientRect().top + window.pageYOffset - offset;
                
                window.scrollTo({
                    top: targetPosition,
                    behavior: 'smooth'
                });
            }
        }, 50);
    }
};