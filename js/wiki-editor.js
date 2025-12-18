// NOTE: This script requires wiki-parser.js to be loaded first
// Add <script src="js/wiki-parser.js"></script> before this file in your HTML

const STATE_DISPLAY_NAMES = {
    AL: 'Alabama',
    AK: 'Alaska',
    AZ: 'Arizona',
    AR: 'Arkansas',
    CA: 'California',
    CO: 'Colorado',
    CT: 'Connecticut',
    DE: 'Delaware',
    FL: 'Florida',
    GA: 'Georgia',
    HI: 'Hawaii',
    ID: 'Idaho',
    IL: 'Illinois',
    IN: 'Indiana',
    IA: 'Iowa',
    KS: 'Kansas',
    KY: 'Kentucky',
    LA: 'Louisiana',
    ME: 'Maine',
    MD: 'Maryland',
    MA: 'Massachusetts',
    MI: 'Michigan',
    MN: 'Minnesota',
    MS: 'Mississippi',
    MO: 'Missouri',
    MT: 'Montana',
    NE: 'Nebraska',
    NV: 'Nevada',
    NH: 'New Hampshire',
    NJ: 'New Jersey',
    NM: 'New Mexico',
    NY: 'New York',
    NC: 'North Carolina',
    ND: 'North Dakota',
    OH: 'Ohio',
    OK: 'Oklahoma',
    OR: 'Oregon',
    PA: 'Pennsylvania',
    RI: 'Rhode Island',
    SC: 'South Carolina',
    SD: 'South Dakota',
    TN: 'Tennessee',
    TX: 'Texas',
    UT: 'Utah',
    VT: 'Vermont',
    VA: 'Virginia',
    WA: 'Washington',
    WI: 'Wisconsin',
    WV: 'West Virginia',
    WY: 'Wyoming',
    DC: 'District of Columbia',
    CORPORATE: 'Corporate Index',
    NONUSA: 'International / Non-USA'
};

const editorSettings = window.wikiEditorSettings || {};
const isAdminMode = !!editorSettings.isAdmin;

document.addEventListener('DOMContentLoaded', () => {
    const wikiForm = document.getElementById('wikiForm');

    if (!wikiForm) {
        console.warn('Wiki Editor: #wikiForm not found, skipping initialization.');
        return;
    }

    // --- Data Storage ---
    let staffMembers = [];
    let punishments = [];
    let lawsuits = [];
    let newsArticles = [];
    let testimonies = [];
    let relatedMedia = [];
    let campuses = [];
    let ownershipChanges = [];
    let rules = [];
    let allegations = [];
    let therapies = [];
    let programLevels = [];
    let importedMarkdown = ''; // Store original imported markdown


    const staffNameInput = document.getElementById('staffName');
    const staffRoleInput = document.getElementById('staffRole');
    const staffBioInput = document.getElementById('staffBio');
    const previousRolesContainer = document.getElementById('previousRolesContainer');
    const addPreviousRoleBtn = document.getElementById('addPreviousRoleBtn');
    const staffIsFormerInput = document.getElementById('staffIsFormer');
    const addStaffBtn = document.getElementById('addStaffBtn');
    let editingStaffIndex = null;

    // --- Initialize empty list output containers ---
    const initializeEmptyLists = () => {
        const emptyHtml = '<p style="color:#999;">No items added yet</p>';
        const listIds = ['staffListOutput', 'punishmentListOutput', 'lawsuitListOutput', 'articleListOutput', 'testimonyListOutput', 'mediaListOutput', 'campusListOutput', 'ownerChangeListOutput', 'ruleListOutput', 'allegationListOutput', 'therapyListOutput'];
        listIds.forEach(id => {
            const element = document.getElementById(id);
            if (element && !element.innerHTML.trim()) {
                element.innerHTML = emptyHtml;
            }
        });
    };
    initializeEmptyLists();

    if (typeof initializeAutocompleteFields === 'function') {
        initializeAutocompleteFields();
    }

    function getStaffPreviousRolesFromInput() {
        if (!previousRolesContainer) return [];
        const entries = previousRolesContainer.querySelectorAll('.previous-role-entry');
        const roles = [];
        entries.forEach(entry => {
            const roleInput = entry.querySelector('.prev-role-title');
            const employerInput = entry.querySelector('.prev-role-employer');
            const role = roleInput ? roleInput.value.trim() : '';
            const employer = employerInput ? employerInput.value.trim() : '';
            if (role || employer) {
                roles.push({ role, employer });
            }
        });
        return roles;
    }

    function addPreviousRoleEntry(role = '', employer = '') {
        if (!previousRolesContainer) return;
        const entries = previousRolesContainer.querySelectorAll('.previous-role-entry');
        const newIndex = entries.length;
        const entryDiv = document.createElement('div');
        entryDiv.className = 'previous-role-entry';
        entryDiv.dataset.index = newIndex;
        entryDiv.innerHTML = `
            <div class="field-row">
                <div class="field-group">
                    <input type="text" class="prev-role-title" placeholder="Role/Title (e.g., Clinical Director)" value="${escapeHtmlAttr(role)}">
                </div>
                <div class="field-group">
                    <input type="text" class="prev-role-employer" placeholder="Employer (e.g., Another Campus)" value="${escapeHtmlAttr(employer)}">
                </div>
                <button type="button" class="remove-prev-role-btn" title="Remove this role">×</button>
            </div>
        `;
        previousRolesContainer.appendChild(entryDiv);
        // Attach remove handler
        entryDiv.querySelector('.remove-prev-role-btn').addEventListener('click', () => {
            entryDiv.remove();
        });
        return entryDiv;
    }

    function clearPreviousRolesEntries() {
        if (!previousRolesContainer) return;
        previousRolesContainer.innerHTML = '';
        // Add one empty entry
        addPreviousRoleEntry();
    }

    function populatePreviousRoles(previousRoles) {
        if (!previousRolesContainer) return;
        previousRolesContainer.innerHTML = '';
        if (!previousRoles || previousRoles.length === 0) {
            addPreviousRoleEntry();
            return;
        }
        previousRoles.forEach(pr => {
            // Handle both old format (string) and new format ({role, employer})
            if (typeof pr === 'string') {
                // Try to parse "Role at Employer" format
                const atMatch = pr.match(/^(.+?)\s+at\s+(.+)$/i);
                if (atMatch) {
                    addPreviousRoleEntry(atMatch[1].trim(), atMatch[2].trim());
                } else {
                    addPreviousRoleEntry(pr, '');
                }
            } else {
                addPreviousRoleEntry(pr.role || '', pr.employer || '');
            }
        });
    }

    function escapeHtmlAttr(str) {
        if (!str) return '';
        return str.replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/'/g, '&#39;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    }

    function resetStaffForm() {
        if (staffNameInput) staffNameInput.value = '';
        if (staffRoleInput) staffRoleInput.value = '';
        if (staffBioInput) staffBioInput.value = '';
        clearPreviousRolesEntries();
        if (staffIsFormerInput) staffIsFormerInput.checked = false;
        editingStaffIndex = null;
        if (addStaffBtn) {
            addStaffBtn.textContent = 'Add Staff Member';
        }
    }

    function startEditStaffMember(index) {
        const member = staffMembers[index];
        if (!member) return;
        editingStaffIndex = index;
        if (staffNameInput) staffNameInput.value = member.name || '';
        if (staffRoleInput) staffRoleInput.value = member.role || '';
        if (staffBioInput) staffBioInput.value = member.bio || '';
        populatePreviousRoles(member.previousRoles || []);
        if (staffIsFormerInput) staffIsFormerInput.checked = !!member.isFormer;
        if (addStaffBtn) addStaffBtn.textContent = 'Save Staff Member';
        if (staffNameInput) staffNameInput.focus();
    }

    function removeStaffMember(index) {
        if (index < 0 || index >= staffMembers.length) return;
        staffMembers.splice(index, 1);
        if (editingStaffIndex !== null) {
            if (editingStaffIndex === index) {
                resetStaffForm();
            } else if (editingStaffIndex > index) {
                editingStaffIndex -= 1;
            }
        }
        renderStaffList();
    }

    function renderStaffList() {
        const container = document.getElementById('staffListOutput');
        if (!container) return;
        container.innerHTML = '';
        if (!staffMembers.length) {
            container.innerHTML = '<p style="color:#999;">No items added yet</p>';
            return;
        }

        staffMembers.forEach((member, index) => {
            const item = document.createElement('div');
            item.className = 'list-preview-item staff-preview-item';

            const summary = document.createElement('div');
            summary.className = 'staff-summary';
            const nameStrong = document.createElement('strong');
            nameStrong.textContent = member.name || '';
            summary.appendChild(nameStrong);

            const roleSpan = document.createElement('span');
            roleSpan.className = 'staff-role';
            roleSpan.textContent = ` — ${member.role || ''}`;
            summary.appendChild(roleSpan);

            if (member.isFormer) {
                const status = document.createElement('span');
                status.className = 'staff-status';
                status.textContent = 'Former';
                summary.appendChild(status);
            }

            item.appendChild(summary);

            if (member.previousRoles && member.previousRoles.length) {
                const prev = document.createElement('div');
                prev.className = 'staff-prev';
                const formatted = member.previousRoles.map(pr => {
                    if (typeof pr === 'string') return pr;
                    if (pr.role && pr.employer) return `${pr.role} at ${pr.employer}`;
                    return pr.role || pr.employer || '';
                }).filter(Boolean);
                prev.textContent = `Prev: ${formatted.join('; ')}`;
                item.appendChild(prev);
            }

            if (member.bio) {
                const bio = document.createElement('div');
                bio.className = 'staff-bio';
                bio.textContent = member.bio;
                item.appendChild(bio);
            }

            const actions = document.createElement('div');
            actions.className = 'staff-actions';

            const editBtn = document.createElement('button');
            editBtn.type = 'button';
            editBtn.textContent = 'Edit';
            editBtn.addEventListener('click', () => startEditStaffMember(index));
            actions.appendChild(editBtn);

            const removeBtn = document.createElement('button');
            removeBtn.type = 'button';
            removeBtn.className = 'remove-btn';
            removeBtn.textContent = 'Remove';
            removeBtn.addEventListener('click', () => removeStaffMember(index));
            actions.appendChild(removeBtn);

            item.appendChild(actions);
            container.appendChild(item);
        });
    }

    renderStaffList();

    const setFieldValue = (id, value = '') => {
        const el = document.getElementById(id);
        if (el) el.value = value || '';
    };

    const setCheckboxGroup = (name, selectedValues = []) => {
        const normalized = (selectedValues || []).map(value => (value || '').toString());
        document.querySelectorAll(`input[name="${name}"]`).forEach(cb => {
            cb.checked = normalized.includes(cb.value);
        });
    };

    function populateFormFromParsedData(parsedData = {}, overrides = {}) {
        const data = { ...parsedData, ...overrides };
        setFieldValue('programName', data.programName);
        setFieldValue('yearsActive', data.yearsActive);
        setFieldValue('cityState', data.cityState);
        setFieldValue('programType', data.programType);
        setFieldValue('yearFounded', data.yearFounded);
        setFieldValue('ageRange', data.ageRange);
        setFieldValue('capacity', data.capacity);
        setFieldValue('ownerName', data.ownerName);
        setFieldValue('ownerLink', data.ownerLink);
        setFieldValue('avgStay', data.avgStay);
        setFieldValue('tuition', data.tuition);
        setFieldValue('natsapMember', data.natsapMember);
        setFieldValue('natsapYear', data.natsapYear);
        setFieldValue('mainAddress', data.mainAddress);
        setFieldValue('addressLink', data.addressLink);
        setFieldValue('accreditingBody', data.accreditingBody);
        setFieldValue('accreditingBodyLink', data.accreditingBodyLink);
        setFieldValue('historyNotes', data.historyNotes);
        setFieldValue('levelSystemDesc', data.levelSystemDesc);
        setFieldValue('structureMisc', data.structureMisc);
        setFieldValue('punishmentsMisc', data.punishmentsMisc);
        setFieldValue('lawsuitsMisc', data.lawsuitsMisc);
        setFieldValue('mediaInfo', data.mediaInfo);
        setFieldValue('testimoniesMisc', data.testimoniesMisc);
        setFieldValue('relatedMediaMisc', data.relatedMediaMisc);
        setFieldValue('customDiagnoses', data.customDiagnoses);
        setFieldValue('customAllegations', data.customAllegations);

        staffMembers = Array.isArray(data.staffMembers) ? data.staffMembers : [];
        punishments = Array.isArray(data.punishments) ? data.punishments : [];
        lawsuits = Array.isArray(data.lawsuits) ? data.lawsuits : [];
        newsArticles = Array.isArray(data.newsArticles) ? data.newsArticles : [];
        testimonies = Array.isArray(data.testimonies) ? data.testimonies : [];
        relatedMedia = Array.isArray(data.relatedMedia) ? data.relatedMedia : [];
        campuses = Array.isArray(data.campuses) ? data.campuses : [];
        ownershipChanges = Array.isArray(data.ownershipChanges) ? data.ownershipChanges : [];
        rules = Array.isArray(data.rules) ? data.rules : [];
        allegations = Array.isArray(data.allegations) ? data.allegations : [];
        therapies = Array.isArray(data.therapies) ? data.therapies : [];

        renderStaffList();
        renderList(punishments, 'punishmentListOutput', item => `<strong>${escapeHtml(item.name)}</strong> — ${escapeHtml(item.description || '').substring(0, 60)}...`);
        renderList(lawsuits, 'lawsuitListOutput', item => formatLawsuitListItem(item));
        renderList(newsArticles, 'articleListOutput', item => `<strong>${escapeHtml(item.title)}</strong> (${escapeHtml(item.source || 'No Source')})`);
        renderList(testimonies, 'testimonyListOutput', item => {
            const dateStr = item.date ? `${escapeHtml(item.date)}: ` : '';
            return `<strong>${dateStr}(${escapeHtml(item.type)})</strong> ${escapeHtml(item.quote.substring(0, 30))}... [${escapeHtml(item.source || 'Unknown')}]`;
        });
        renderList(relatedMedia, 'mediaListOutput', item => `[${escapeHtml(item.title)}]`);
        renderList(campuses, 'campusListOutput', item => `<strong>${escapeHtml(item.name)}</strong> — ${escapeHtml(item.location)}`);
        renderList(ownershipChanges, 'ownerChangeListOutput', item => {
            const prevDisplay = item.previousLink
                ? `<a href="${escapeHtml(item.previousLink)}" target="_blank">${escapeHtml(item.previous || '?')}</a>`
                : escapeHtml(item.previous || '?');
            const newDisplay = item.newOwnerLink
                ? `<a href="${escapeHtml(item.newOwnerLink)}" target="_blank">${escapeHtml(item.newOwner || '?')}</a>`
                : escapeHtml(item.newOwner || '?');
            return `<strong>${escapeHtml(item.year)}</strong>: ${prevDisplay} → ${newDisplay}`;
        });
        renderList(rules, 'ruleListOutput', item => escapeHtml(item.name || item));
        renderList(therapies, 'therapyListOutput', item => `<strong>${escapeHtml(item.label || item.type || '')}</strong>${item.frequency ? ` (${escapeHtml(item.frequency)})` : ''}`);

        setCheckboxGroup('diagnoses', data.selectedDiagnoses);
        setCheckboxGroup('allegations', data.selectedAllegations);
    }

    const fetchSubmissionByName = async (programName) => {
        if (!programName) return null;
        try {
            const response = await fetch(`/wp-content/themes/child/api/save-wiki-submission.php?search=${encodeURIComponent(programName)}`);
            if (!response.ok) return null;
            const result = await response.json();
            if (result.success && Array.isArray(result.data) && result.data.length > 0) {
                return result.data[0];
            }
        } catch (error) {
            console.error(`Wiki Editor: failed to lookup "${programName}":`, error);
        }
        return null;
    };

    const loadLocalIndexMarkdown = async (slug) => {
        if (!slug) return '';
        const candidates = [
            `/wp-content/themes/child/markdown_output/index_${slug}.md`,
            `/wp-content/themes/child/markdown_output/index_${slug}_.md`
        ];
        for (const path of candidates) {
            try {
                const response = await fetch(path, { cache: 'no-cache' });
                if (response.ok) {
                    return response.text();
                }
            } catch (error) {
                console.warn(`Wiki Editor: failed to fetch ${path}:`, error);
            }
        }
        return '';
    };

    async function loadOrganizationIndexEntry(entry, button) {
        if (!entry) return;
        const originalLabel = button?.textContent || 'Edit';
        if (button) {
            button.disabled = true;
            button.textContent = 'Loading...';
        }

        try {
            const programName = entry.name || entry.normalizedName;
            const dbEntry = await fetchSubmissionByName(programName);
            if (dbEntry) {
                await loadEntryIntoForm(dbEntry.id);
                return;
            }

            const slug = getSlugFromEntryUrl(entry.url);
            if (!slug) {
                throw new Error('Unable to determine index slug');
            }

            const markdown = await loadLocalIndexMarkdown(slug);
            if (!markdown) {
                throw new Error('Local index markdown not found');
            }

            parseAndPopulate(markdown);
            // Regenerate clean markdown from the parsed data immediately
            updateMarkdownFromForm();
            
            importedMarkdown = markdown;
            if (programName) {
                setFieldValue('programName', programName);
            }
            finalizeEntryLoad(programName || entry.url);
        } catch (error) {
            console.error('Wiki Editor: failed to load organization entry:', error);
            alert(`Failed to load ${entry.name || 'organization'} index page: ${error.message || 'Entry not found'}`);
        } finally {
            if (button) {
                button.disabled = false;
                button.textContent = originalLabel;
            }
        }
    }

    function finalizeEntryLoad(name) {
        if (browserPanel) browserPanel.style.display = 'none';
        if (toggleBrowserBtn) toggleBrowserBtn.textContent = '📂 Browse Saved Entries';
        wikiForm.scrollIntoView({ behavior: 'smooth' });
        if (name) {
            alert(`Loaded entry: ${name}`);
        }
    }

    // --- Helper: Get Placeholder Text ---
    const getPlaceholder = (category, programName) => {
        const name = programName || '[Program Name]';
        const lowerCategory = (category || '').toLowerCase();
        if (lowerCategory.includes('media')) {
            return `No media coverage for ${name} has been noted yet. If you have seen a news item about ${name} and would like to contribute information to help complete this page, please contact u/Signal-Strain9810.`;
        }

        return `No information is known about ${category} at ${name} yet. If you have reliable updates or references, please contact u/Signal-Strain9810.`;
    };

    // --- Helper: Normalize/Sanitize URLs for Markdown ---
    const sanitizeUrl = (input) => {
        if (!input) return '';
        let url = input.trim();
        if (!url) return '';

        const isRelativePath = url.startsWith('/');
        if (url.startsWith('//')) {
            url = `https:${url}`;
        } else if (!isRelativePath && !/^[a-z]+:\/\//i.test(url)) {
            url = url.startsWith('www.') ? `https://${url}` : `https://${url}`;
        }

        url = url.replace(/\s+/g, '%20');
        url = url.replace(/\(/g, '%28').replace(/\)/g, '%29');
        return url;
    };

    // --- Helper: Format Lawsuit Entries for List Views ---
    const formatLawsuitListItem = (item, includeDefendant = false) => {
        const year = (item?.year || '').trim();
        const plaintiff = (item?.plaintiff || '').trim();
        const name = (item?.name || '').trim();
        const description = (item?.description || '').trim();
        const baseTitle = year && plaintiff
            ? `${year}: ${plaintiff}`
            : plaintiff
                ? plaintiff
                : name
                    ? name
                    : description
                        ? description.split('.')[0]
                        : 'Lawsuit record';
        const detailParts = [];
        if (includeDefendant && item?.defendant) detailParts.push(`vs. ${item.defendant.trim()}`);
        if (description) detailParts.push(description);
        const suffix = detailParts.length ? ` ${escapeHtml(detailParts.join(' - '))}` : '';
        return `<strong>${escapeHtml(baseTitle)}</strong>${suffix}`;
    };

    const renderList = (array, outputElement, renderer) => {
        const outputDiv = document.getElementById(outputElement);
        if (!outputDiv) {
            console.warn(`Wiki Editor: #${outputElement} not found.`);
            return;
        }

        outputDiv.innerHTML = '';
        if (!array || array.length === 0) {
            outputDiv.innerHTML = '<p style="color:#999;">No items added yet</p>';
            return;
        }
        array.forEach((item, index) => {
            const el = document.createElement('div');
            el.className = 'list-preview-item';
            el.innerHTML = renderer(item);

            const removeBtn = document.createElement('button');
            removeBtn.type = 'button';
            removeBtn.className = 'remove-btn';
            removeBtn.innerHTML = '✕ Remove';
            removeBtn.setAttribute('aria-label', `Remove item ${index + 1}`);
            removeBtn.onclick = (e) => {
                e.preventDefault();
                array.splice(index, 1);
                renderList(array, outputElement, renderer);
            };
            el.appendChild(removeBtn);
            outputDiv.appendChild(el);
        });
    };

    // --- Helper: Clear Input Fields ---
    const clearInputs = (ids) => {
        ids.forEach(id => {
            const element = document.getElementById(id);
            if (element) {
                element.value = '';
            }
        });
    };

    // --- Helper: Validate Required Fields ---
    const validateRequired = (fieldIds, errorMsg) => {
        const values = fieldIds.map(id => document.getElementById(id)?.value.trim() || '');
        if (values.some(v => !v)) {
            alert(errorMsg);
            return false;
        }
        return true;
    };

    // --- Add Button: Staff ---
    if (addStaffBtn) {
        addStaffBtn.addEventListener('click', (e) => {
            e.preventDefault();
            const name = staffNameInput ? staffNameInput.value.trim() : '';
            const role = staffRoleInput ? staffRoleInput.value.trim() : '';
            const bio = staffBioInput ? staffBioInput.value.trim() : '';
            const previousRoles = getStaffPreviousRolesFromInput();
            const isFormer = staffIsFormerInput ? staffIsFormerInput.checked : false;

            if (!name || !role) {
                alert('Please enter at least a name and role.');
                return;
            }

            const staffData = { name, role, bio, previousRoles, isFormer };

            if (editingStaffIndex !== null) {
                staffMembers[editingStaffIndex] = staffData;
            } else {
                staffMembers.push(staffData);
            }

            renderStaffList();
            resetStaffForm();
        });
    }

    // --- Add Button: Previous Roles (within staff form) ---
    if (addPreviousRoleBtn) {
        addPreviousRoleBtn.addEventListener('click', (e) => {
            e.preventDefault();
            addPreviousRoleEntry();
        });
    }

    // --- Initialize remove handlers for existing previous role entries ---
    if (previousRolesContainer) {
        previousRolesContainer.querySelectorAll('.remove-prev-role-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                const entry = btn.closest('.previous-role-entry');
                if (entry && previousRolesContainer.querySelectorAll('.previous-role-entry').length > 1) {
                    entry.remove();
                } else if (entry) {
                    // Clear inputs instead of removing if it's the only entry
                    entry.querySelectorAll('input').forEach(input => input.value = '');
                }
            });
        });
    }

    // Level system now uses simple textarea (levelSystemDesc) instead of structured fields

    // --- Add Button: Punishments ---
    const addPunishmentBtn = document.getElementById('addPunishmentBtn');
    if (addPunishmentBtn) {
        addPunishmentBtn.addEventListener('click', (e) => {
            e.preventDefault();
            const name = document.getElementById('punishmentName')?.value.trim() || '';
            const description = document.getElementById('punishmentDescription')?.value.trim() || '';
            if (name && description) {
                punishments.push({ name, description });
                renderList(punishments, 'punishmentListOutput', item => `<strong>${escapeHtml(item.name)}</strong> — ${escapeHtml(item.description).substring(0, 60)}...`);
                clearInputs(['punishmentName', 'punishmentDescription']);
            } else {
                alert('Please enter both a punishment name and description.');
            }
        });
    }

    // --- Add Button: Rules ---
    const addRuleBtn = document.getElementById('addRuleBtn');
    if (addRuleBtn) {
        addRuleBtn.addEventListener('click', (e) => {
            e.preventDefault();
            const ruleName = document.getElementById('ruleName')?.value.trim() || '';
            if (ruleName) {
                rules.push({ name: ruleName });
                renderList(rules, 'ruleListOutput', item => escapeHtml(item.name));
                clearInputs(['ruleName']);
            } else {
                alert('Please enter a rule.');
            }
        });
    }

    // --- Add Button: Allegations ---
    const addAllegationBtn = document.getElementById('addAllegationBtn');
    if (addAllegationBtn) {
        addAllegationBtn.addEventListener('click', (e) => {
            e.preventDefault();
            const type = document.getElementById('allegationType')?.value || 'other';
            const detail = document.getElementById('allegationDetail')?.value.trim() || '';
            if (detail) {
                allegations.push({ type, detail });
                const typeLabels = {
                    physical: 'Physical Abuse', emotional: 'Emotional Abuse', sexual: 'Sexual Abuse',
                    medical: 'Medical Neglect', educational: 'Educational Neglect', isolation: 'Improper Isolation',
                    restraint: 'Improper Restraints', food: 'Food Deprivation', sleep: 'Sleep Deprivation',
                    lgbtq: 'LGBTQ+ Discrimination', religious: 'Religious Coercion', other: 'Other'
                };
                renderList(allegations, 'allegationListOutput', item => `<strong>${escapeHtml(typeLabels[item.type] || item.type)}</strong>: ${escapeHtml(item.detail).substring(0, 40)}...`);
                clearInputs(['allegationDetail']);
            } else {
                alert('Please enter a specific detail for the allegation.');
            }
        });
    }

    // --- Add Button: Lawsuits ---
    const addLawsuitBtn = document.getElementById('addLawsuitBtn');
    if (addLawsuitBtn) {
        addLawsuitBtn.addEventListener('click', (e) => {
            e.preventDefault();
            const year = document.getElementById('lawsuitYear')?.value.trim() || '';
            const plaintiff = document.getElementById('lawsuitPlaintiff')?.value.trim() || '';
            const defendant = document.getElementById('lawsuitDefendant')?.value.trim() || '';
            const claims = document.getElementById('lawsuitClaims')?.value.trim() || '';
            const outcome = document.getElementById('lawsuitOutcome')?.value || '';
            const amount = document.getElementById('lawsuitAmount')?.value.trim() || '';
            const court = document.getElementById('lawsuitCourt')?.value.trim() || '';

            if (year && plaintiff && claims) {
                lawsuits.push({ year, plaintiff, defendant, claims, outcome, amount, court });
                renderList(lawsuits, 'lawsuitListOutput', item => formatLawsuitListItem(item, true));
                clearInputs(['lawsuitYear', 'lawsuitPlaintiff', 'lawsuitDefendant', 'lawsuitClaims', 'lawsuitAmount', 'lawsuitCourt']);
                const outcomeSelect = document.getElementById('lawsuitOutcome');
                if (outcomeSelect) outcomeSelect.value = '';
            } else {
                alert('Please enter at least the year, plaintiff(s), and claims.');
            }
        });
    }

    // --- Add Button: Campuses ---
    const addCampusBtn = document.getElementById('addCampusBtn');
    if (addCampusBtn) {
        addCampusBtn.addEventListener('click', (e) => {
            e.preventDefault();
            const name = document.getElementById('campusName')?.value.trim() || '';
            const location = document.getElementById('campusLocation')?.value.trim() || '';
            if (name && location) {
                campuses.push({ name, location });
                renderList(campuses, 'campusListOutput', item => `<strong>${escapeHtml(item.name)}</strong> — ${escapeHtml(item.location)}`);
                clearInputs(['campusName', 'campusLocation']);
            } else {
                alert('Please enter a campus name and location.');
            }
        });
    }

    // --- Add Button: Ownership Changes ---
    const addOwnerChangeBtn = document.getElementById('addOwnerChangeBtn');
    if (addOwnerChangeBtn) {
        addOwnerChangeBtn.addEventListener('click', (e) => {
            e.preventDefault();
            const year = document.getElementById('ownerChangeYear')?.value.trim() || '';
            const previous = document.getElementById('ownerChangePrevious')?.value.trim() || '';
            const previousLink = document.getElementById('ownerChangePreviousLink')?.value.trim() || '';
            const newOwner = document.getElementById('ownerChangeNew')?.value.trim() || '';
            const newOwnerLink = document.getElementById('ownerChangeNewLink')?.value.trim() || '';
            if (year && (previous || newOwner)) {
                ownershipChanges.push({ year, previous, previousLink, newOwner, newOwnerLink });
                renderList(ownershipChanges, 'ownerChangeListOutput', item => {
                    const prevDisplay = item.previousLink 
                        ? `<a href="${escapeHtml(item.previousLink)}" target="_blank">${escapeHtml(item.previous || '?')}</a>`
                        : escapeHtml(item.previous || '?');
                    const newDisplay = item.newOwnerLink
                        ? `<a href="${escapeHtml(item.newOwnerLink)}" target="_blank">${escapeHtml(item.newOwner || '?')}</a>`
                        : escapeHtml(item.newOwner || '?');
                    return `<strong>${escapeHtml(item.year)}</strong>: ${prevDisplay} → ${newDisplay}`;
                });
                clearInputs(['ownerChangeYear', 'ownerChangePrevious', 'ownerChangePreviousLink', 'ownerChangeNew', 'ownerChangeNewLink']);
            } else {
                alert('Please enter a year and at least one owner name.');
            }
        });
    }

    // --- Add Button: Therapy Types ---
    const addTherapyBtn = document.getElementById('addTherapyBtn');
    if (addTherapyBtn) {
        addTherapyBtn.addEventListener('click', (e) => {
            e.preventDefault();
            const type = document.getElementById('therapyType')?.value || 'other';
            const frequency = document.getElementById('therapyFrequency')?.value.trim() || '';
            const typeLabels = {
                individual: 'Individual Therapy', group: 'Group Therapy', family: 'Family Therapy',
                cbt: 'CBT', dbt: 'DBT', emdr: 'EMDR', equine: 'Equine Therapy', art: 'Art Therapy',
                wilderness: 'Wilderness Therapy', attack: 'Attack Therapy/Confrontation', other: 'Other'
            };
            therapies.push({ type, frequency, label: typeLabels[type] || type });
            renderList(therapies, 'therapyListOutput', item => `<strong>${escapeHtml(item.label)}</strong>${item.frequency ? ` (${escapeHtml(item.frequency)})` : ''}`);
            clearInputs(['therapyFrequency']);
        });
    }

    // --- Add Button: Levels ---
    const addLevelBtn = document.getElementById('addLevelBtn');
    if (addLevelBtn) {
        addLevelBtn.addEventListener('click', (e) => {
            e.preventDefault();
            const name = document.getElementById('levelName')?.value.trim() || '';
            const duration = document.getElementById('levelDuration')?.value.trim() || '';
            const privileges = document.getElementById('levelPrivileges')?.value.trim() || '';
            const restrictions = document.getElementById('levelRestrictions')?.value.trim() || '';

            if (name) {
                programLevels.push({ name, duration, privileges, restrictions });
                renderList(programLevels, 'levelListOutput', item => {
                    const parts = [`<strong>${escapeHtml(item.name)}</strong>`];
                    if (item.duration) parts.push(`Duration: ${escapeHtml(item.duration)}`);
                    return parts.join(' - ');
                });
                clearInputs(['levelName', 'levelDuration', 'levelPrivileges', 'levelRestrictions']);
            } else {
                alert('Please enter at least a level name.');
            }
        });
    }

    // --- Add Button: Articles ---
    const addArticleBtn = document.getElementById('addArticleBtn');
    if (addArticleBtn) {
        addArticleBtn.addEventListener('click', (e) => {
            e.preventDefault();
            const title = document.getElementById('articleTitle').value.trim();
            const url = sanitizeUrl(document.getElementById('articleUrl').value);
            const source = document.getElementById('articleSource').value.trim();
            const date = document.getElementById('articleDate').value.trim();
            if (title && url) {
                newsArticles.push({ title, url, source, date });
                renderList(newsArticles, 'articleListOutput', item => `<strong>${escapeHtml(item.title)}</strong> (${escapeHtml(item.source || 'No Source')})`);
                clearInputs(['articleTitle', 'articleUrl', 'articleSource', 'articleDate']);
            } else {
                alert('Please enter at least a title and URL.');
            }
        });
    }

    // --- Add Button: Testimonies ---
    const addTestimonyBtn = document.getElementById('addTestimonyBtn');
    if (addTestimonyBtn) {
        addTestimonyBtn.addEventListener('click', (e) => {
            e.preventDefault();
            const date = document.getElementById('testimonyDate').value.trim();
            const type = document.getElementById('testimonyType').value;
            const quote = document.getElementById('testimonyQuote').value.trim();
            const sourceName = document.getElementById('testimonySourceName').value.trim();
            const platform = document.getElementById('testimonyPlatform').value;
            const url = sanitizeUrl(document.getElementById('testimonyUrl').value);
            if (quote && (sourceName || platform) && url) {
                // Build combined source string for display/output
                let source = sourceName;
                if (platform && sourceName) {
                    source = `${sourceName} (${platform})`;
                } else if (platform && !sourceName) {
                    source = platform;
                }
                testimonies.push({ date, type, quote, sourceName, platform, source, url });
                renderList(testimonies, 'testimonyListOutput', item => `<strong>(${escapeHtml(item.type)})</strong> ${escapeHtml(item.quote.substring(0, 30))}... [${escapeHtml(item.source)}]`);
                clearInputs(['testimonyDate', 'testimonyQuote', 'testimonySourceName', 'testimonyUrl']);
                // Reset platform dropdown
                const platformSelect = document.getElementById('testimonyPlatform');
                if (platformSelect) platformSelect.selectedIndex = 0;
            } else {
                alert('Please enter at least a quote, source name or platform, and source URL.');
            }
        });
    }

    // --- Add Button: Related Media ---
    const addMediaBtn = document.getElementById('addMediaBtn');
    if (addMediaBtn) {
        addMediaBtn.addEventListener('click', (e) => {
            e.preventDefault();
            const title = document.getElementById('mediaTitle').value.trim();
            const url = sanitizeUrl(document.getElementById('mediaUrl').value);
            if (title && url) {
                relatedMedia.push({ title, url });
                renderList(relatedMedia, 'mediaListOutput', item => `[${escapeHtml(item.title)}]`);
                clearInputs(['mediaTitle', 'mediaUrl']);
            } else {
                alert('Please enter a title and URL.');
            }
        });
    }

    // --- MODE TOGGLE & GENERATION LOGIC ---
    const modeFormBtn = document.getElementById('modeFormBtn');
    const modeMarkdownBtn = document.getElementById('modeMarkdownBtn');
    const browserSection = document.querySelector('.entry-browser-section');
    const importSection = document.querySelector('.import-section');
    const bulkUploadSection = document.querySelector('.bulk-upload-section');
    
    function updateMarkdownFromForm() {
        // Check if generation module is loaded
        if (typeof generateWikiMarkdown !== 'function') {
            console.error('generateWikiMarkdown is not defined.');
            return '';
        }

        // Collect all form field values
        const vals = {};
        const inputs = document.querySelectorAll('#wikiForm input[type="text"], #wikiForm textarea, #wikiForm select');
        inputs.forEach(input => {
            if (input.id && !input.closest('.form-adder')) {
                vals[input.id] = input.value.trim();
            }
        });

        // Collect selected diagnoses from checkboxes
        const selectedDiagnoses = [];
        document.querySelectorAll('input[name="diagnoses"]:checked').forEach(cb => {
            selectedDiagnoses.push(cb.value);
        });

        // Collect selected allegations from checkboxes
        const selectedAllegations = [];
        document.querySelectorAll('input[name="allegations"]:checked').forEach(cb => {
            selectedAllegations.push(cb.value);
        });

        const programName = vals.programName || '[Program Name]';

        // Build form data object to pass to generation function
        const formData = {
            ...vals,
            staffMembers, punishments, lawsuits, newsArticles, testimonies,
            relatedMedia, campuses, ownershipChanges, rules, allegations,
            therapies, programLevels,
            selectedDiagnoses, selectedAllegations
        };

        // Generate the wiki markdown
        let output = generateWikiMarkdown(formData);

        // Apply auto-linking if enabled
        const autoLinkCheckbox = document.getElementById('autoLinkPrograms');
        if (autoLinkCheckbox && autoLinkCheckbox.checked && window.ttiAutoLinker && window.ttiAutoLinker.loaded) {
            output = window.ttiAutoLinker.autoLink(output, {
                currentProgramName: programName,
                linkCurrentProgram: false
            });
        }

        // Append unparsed content
        if (window.unparsedContentFromImport && window.unparsedContentFromImport.trim()) {
            output += '\n\n***\n\n' + window.unparsedContentFromImport;
        }

        if (outputCode) {
            outputCode.value = output.trim();
        }
        return output;
    }

    if (modeMarkdownBtn && modeFormBtn) {
        modeMarkdownBtn.addEventListener('click', () => {
            // Update markdown from current form state before switching
            updateMarkdownFromForm();
            
            // Toggle UI
            modeFormBtn.classList.remove('active');
            modeMarkdownBtn.classList.add('active');
            
            wikiForm.classList.add('hidden-section');
            if(browserSection) browserSection.classList.add('hidden-section');
            if(importSection) importSection.classList.add('hidden-section');
            if(bulkUploadSection) bulkUploadSection.classList.add('hidden-section');
            
            if(outputCode) outputCode.classList.add('markdown-mode-active');
            
            // Hide generate button in markdown mode (it's redundant/confusing)
            if(generateBtn) generateBtn.style.display = 'none';
        });

        modeFormBtn.addEventListener('click', () => {
            // Update form from current markdown state before switching
            if (outputCode && outputCode.value.trim()) {
                // Use a silent version or just call it (it logs to console)
                parseAndPopulate(outputCode.value);
            }

            // Toggle UI
            modeMarkdownBtn.classList.remove('active');
            modeFormBtn.classList.add('active');
            
            wikiForm.classList.remove('hidden-section');
            if(browserSection) browserSection.classList.remove('hidden-section');
            if(importSection) importSection.classList.remove('hidden-section');
            if(bulkUploadSection) bulkUploadSection.classList.remove('hidden-section');
            
            if(outputCode) outputCode.classList.remove('markdown-mode-active');
            
            // Show generate button again
            if(generateBtn) generateBtn.style.display = 'block';
        });
    }

    // --- MAIN GENERATE BUTTON ---
    if (generateBtn) {
        generateBtn.addEventListener('click', (e) => {
            e.preventDefault();
            updateMarkdownFromForm();
            if (outputCode) {
                outputCode.focus();
                outputCode.select();
            }
        });
    }

    // --- Copy Button ---
    const copyBtn = document.getElementById('copyBtn');
    if (copyBtn) {
        copyBtn.addEventListener('click', (e) => {
            e.preventDefault();
            if (!outputCode || !outputCode.value) {
                alert('Please generate the code first!');
                return;
            }
            outputCode.select();
            outputCode.setSelectionRange(0, 99999);
            
            navigator.clipboard.writeText(outputCode.value).then(() => {
                const originalText = copyBtn.textContent;
                copyBtn.textContent = '✓ Copied!';
                copyBtn.style.backgroundColor = '#B6E3D4';
                setTimeout(() => {
                    copyBtn.textContent = originalText;
                    copyBtn.style.backgroundColor = '';
                }, 2000);
            }).catch((err) => {
                console.warn('Clipboard API failed, using fallback:', err);
                // Fallback: use old selection method
                try {
                    outputCode.focus();
                    outputCode.select();
                    if (document.execCommand('copy')) {
                        const originalText = copyBtn.textContent;
                        copyBtn.textContent = '✓ Copied!';
                        copyBtn.style.backgroundColor = '#B6E3D4';
                        setTimeout(() => {
                            copyBtn.textContent = originalText;
                            copyBtn.style.backgroundColor = '';
                        }, 2000);
                    } else {
                        alert('Copy failed. Please select and copy manually.');
                    }
                } catch (fallbackErr) {
                    console.error('Fallback copy failed:', fallbackErr);
                    alert('Copy failed. Please select and copy manually.');
                }
            });
        });
    }

    const matchCaseReplacement = (source, replacement) => {
        if (!source) return replacement;
        if (source === source.toUpperCase()) {
            return replacement.toUpperCase();
        }
        if (source[0] === source[0].toUpperCase()) {
            return replacement.charAt(0).toUpperCase() + replacement.slice(1);
        }
        return replacement;
    };

    const convertToPastTense = (text) => {
        if (!text) return text;
        const replacements = [
            { pattern: /\bis located\b/gi, replacement: 'was located' },
            { pattern: /\bis based\b/gi, replacement: 'was based' },
            { pattern: /\bis owned\b/gi, replacement: 'was owned' },
            { pattern: /\bis operated\b/gi, replacement: 'was operated' },
            { pattern: /\bis accredited\b/gi, replacement: 'was accredited' },
            { pattern: /\bis\b/gi, replacement: 'was' },
            { pattern: /\bare\b/gi, replacement: 'were' },
            { pattern: /\bhas\b/gi, replacement: 'had' },
            { pattern: /\bhave\b/gi, replacement: 'had' },
            { pattern: /\breports\b/gi, replacement: 'reported' },
            { pattern: /\bserves\b/gi, replacement: 'served' },
            { pattern: /\blists\b/gi, replacement: 'listed' },
            { pattern: /\bincludes\b/gi, replacement: 'included' },
            { pattern: /\bprovides\b/gi, replacement: 'provided' },
            { pattern: /\boffers\b/gi, replacement: 'offered' },
            { pattern: /\bmaintains\b/gi, replacement: 'maintained' },
            { pattern: /\buses\b/gi, replacement: 'used' },
            { pattern: /\butilizes\b/gi, replacement: 'utilized' },
            { pattern: /\boperates\b/gi, replacement: 'operated' },
            { pattern: /\bruns\b/gi, replacement: 'ran' },
            { pattern: /\bcontinues\b/gi, replacement: 'continued' },
            { pattern: /\bexists\b/gi, replacement: 'existed' },
            { pattern: /\bremains\b/gi, replacement: 'remained' }
        ];

        let updated = text;
        replacements.forEach(({ pattern, replacement }) => {
            updated = updated.replace(pattern, (match) => matchCaseReplacement(match, replacement));
        });
        return updated;
    };

    const convertPastBtn = document.getElementById('convertPastBtn');
    if (convertPastBtn) {
        convertPastBtn.addEventListener('click', (e) => {
            e.preventDefault();
            if (!outputCode || !outputCode.value.trim()) {
                alert('Please generate the wiki entry before converting tenses.');
                return;
            }
            outputCode.value = convertToPastTense(outputCode.value);
            const originalText = convertPastBtn.textContent;
            convertPastBtn.textContent = 'Converted to Past';
            convertPastBtn.disabled = true;
            setTimeout(() => {
                convertPastBtn.textContent = originalText;
                convertPastBtn.disabled = false;
            }, 1500);
        });
    }

    // --- IMPORT FUNCTIONALITY ---
    const toggleImportBtn = document.getElementById('toggleImportBtn');
    const importPanel = document.getElementById('importPanel');
    const importBtn = document.getElementById('importBtn');
    const cancelImportBtn = document.getElementById('cancelImportBtn');
    const importTextarea = document.getElementById('importTextarea');

    // Toggle import panel
    if (toggleImportBtn && importPanel) {
        toggleImportBtn.addEventListener('click', (e) => {
            e.preventDefault();
            const computedStyle = window.getComputedStyle(importPanel);
            const isHidden = computedStyle.display === 'none' || importPanel.style.display === 'none';
            importPanel.style.display = isHidden ? 'block' : 'none';
            toggleImportBtn.textContent = isHidden ? '✖️ Close Import' : '📥 Import from Reddit Markdown';
        });
    }

    // Cancel import
    if (cancelImportBtn && importPanel) {
        cancelImportBtn.addEventListener('click', (e) => {
            e.preventDefault();
            importPanel.style.display = 'none';
            if (toggleImportBtn) toggleImportBtn.textContent = '📥 Import from Reddit Markdown';
            if (importTextarea) importTextarea.value = '';
        });
    }

    // Import and parse Reddit markdown
    if (importBtn) {
        importBtn.addEventListener('click', (e) => {
            e.preventDefault();
            const markdown = importTextarea ? importTextarea.value.trim() : '';
            if (!markdown) {
                alert('Please paste some Reddit markdown first!');
                return;
            }

            console.log('=== IMPORT DEBUG ===');
            console.log('Markdown length:', markdown.length);
            console.log('First 200 characters:', markdown.substring(0, 200));

            try {
                // Store the imported markdown before clearing
                importedMarkdown = markdown;
                parseAndPopulate(markdown);
                alert('Import successful! Form fields have been populated. Review and edit as needed.');
                if (importPanel) importPanel.style.display = 'none';
                if (toggleImportBtn) toggleImportBtn.textContent = '📥 Import from Reddit Markdown';
                if (importTextarea) importTextarea.value = '';
            } catch (error) {
                console.error('Import error:', error);
                alert('Error importing markdown. Please check the format and try again.\n\n' + error.message);
            }
        });
    }

    // --- PARSER FUNCTION ---
    // Uses the parseWikiMarkdown function from wiki-parser.js
    function parseAndPopulate(markdown) {
        console.log('parseAndPopulate called');

        // Check if parser module is loaded
        if (typeof parseWikiMarkdown !== 'function') {
            console.error('parseWikiMarkdown is not defined. Make sure wiki-parser.js is loaded before wiki-editor.js');
            alert('Parser module not loaded. Please check the console for errors.');
            return;
        }

        // Clear existing data
        staffMembers = [];
        punishments = [];
        lawsuits = [];
        newsArticles = [];
        testimonies = [];
        relatedMedia = [];
        campuses = [];
        ownershipChanges = [];
        rules = [];
        allegations = [];
        therapies = [];

        resetStaffForm();
        renderStaffList();

        // Clear list displays
        const ownerChangeListEl = document.getElementById('ownerChangeListOutput');
        if (ownerChangeListEl) ownerChangeListEl.innerHTML = '<p style="color:#999;">No items added yet</p>';
        const campusListEl = document.getElementById('campusListOutput');
        if (campusListEl) campusListEl.innerHTML = '<p style="color:#999;">No items added yet</p>';

        // Helper to safely set element values
        const setValue = (id, value) => {
            const el = document.getElementById(id);
            if (el) {
                el.value = value;
                console.log(`Set ${id} = "${value}"`);
            } else {
                console.warn(`Element not found: ${id}`);
            }
        };

        // Call the external parser
        const parsedData = parseWikiMarkdown(markdown);

        // Populate basic form fields
        setValue('programName', parsedData.programName);
        setValue('yearsActive', parsedData.yearsActive);
        setValue('cityState', parsedData.cityState);
        setValue('programType', parsedData.programType);
        setValue('yearFounded', parsedData.yearFounded);
        setValue('ownerName', parsedData.ownerName);
        setValue('ownerLink', parsedData.ownerLink);
        setValue('ageRange', parsedData.ageRange);
        setValue('capacity', parsedData.capacity);
        setValue('diagnosesList', parsedData.diagnosesList);
        setValue('avgStay', parsedData.avgStay);
        setValue('tuition', parsedData.tuition);
        setValue('natsapMember', parsedData.natsapMember);
        setValue('natsapYear', parsedData.natsapYear);
        setValue('mainAddress', parsedData.mainAddress);
        setValue('addressLink', parsedData.addressLink);
        setValue('accreditingBody', parsedData.accreditingBody);
        setValue('accreditingBodyLink', parsedData.accreditingBodyLink);
        // Store full history section in historyNotes (the field that actually exists and gets output)
        setValue('historyNotes', parsedData.historyMisc);
        setValue('levelSystemDesc', parsedData.levelSystemDesc);
        setValue('structureMisc', parsedData.structureMisc);
        setValue('punishmentsMisc', parsedData.punishmentsMisc);
        setValue('lawsuitsMisc', parsedData.lawsuitsMisc);
        setValue('rulesList', parsedData.rulesList);
        setValue('mainComplaints', parsedData.mainComplaints);
        setValue('otherAllegationsList', parsedData.otherAllegationsList);
        setValue('mediaInfo', parsedData.mediaInfo);
        setValue('testimoniesMisc', parsedData.testimoniesMisc);
        setValue('relatedMediaMisc', parsedData.relatedMediaMisc);

        // Store unparsed content to append at the end of generated output
        if (parsedData.unparsedContent && parsedData.unparsedContent.trim()) {
            // Store in a global variable so it can be appended during generation
            window.unparsedContentFromImport = parsedData.unparsedContent.trim();
            console.log(`✓ Stored ${parsedData.unparsedContent.length} chars of unparsed content for appending`);
        } else {
            window.unparsedContentFromImport = '';
        }

        // Populate structured data arrays
        staffMembers = parsedData.staffMembers || [];
        punishments = parsedData.punishments || [];
        lawsuits = parsedData.lawsuits || [];
        newsArticles = parsedData.newsArticles || [];
        testimonies = parsedData.testimonies || [];
        relatedMedia = parsedData.relatedMedia || [];
        campuses = parsedData.campuses || [];
        ownershipChanges = parsedData.ownershipChanges || [];
        rules = parsedData.rules || [];
        allegations = parsedData.allegations || [];
        therapies = parsedData.therapies || [];
        programLevels = parsedData.programLevels || [];

        // Render lists
        renderStaffList();

        if (punishments.length > 0) {
            renderList(punishments, 'punishmentListOutput', item => `<strong>${escapeHtml(item.name)}</strong> — ${escapeHtml(item.description || '').substring(0, 60)}...`);
        }

        if (rules.length > 0) {
            renderList(rules, 'ruleListOutput', item => escapeHtml(item));
            console.log(`✓ Loaded ${rules.length} rules`);
        }

        if (lawsuits.length > 0) {
            renderList(lawsuits, 'lawsuitListOutput', item => formatLawsuitListItem(item));
        }

        if (ownershipChanges.length > 0) {
            renderList(ownershipChanges, 'ownerChangeListOutput', item => {
                const prevDisplay = item.previousLink
                    ? `<a href="${escapeHtml(item.previousLink)}" target="_blank">${escapeHtml(item.previous || '?')}</a>`
                    : escapeHtml(item.previous || '?');
                const newDisplay = item.newOwnerLink
                    ? `<a href="${escapeHtml(item.newOwnerLink)}" target="_blank">${escapeHtml(item.newOwner || '?')}</a>`
                    : escapeHtml(item.newOwner || '?');
                return `<strong>${escapeHtml(item.year)}</strong>: ${prevDisplay} → ${newDisplay}`;
            });
        }

        if (campuses.length > 0) {
            renderList(campuses, 'campusListOutput', item => `<strong>${escapeHtml(item.name)}</strong> — ${escapeHtml(item.location)}`);
        }

        if (newsArticles.length > 0) {
            renderList(newsArticles, 'articleListOutput', item => `[${escapeHtml(item.title)}]`);
        }

        if (testimonies.length > 0) {
            renderList(testimonies, 'testimonyListOutput', item => {
                const dateStr = item.date ? `${escapeHtml(item.date)}: ` : '';
                return `<strong>${dateStr}(${escapeHtml(item.type)})</strong> ${escapeHtml(item.quote.substring(0, 30))}... [${escapeHtml(item.source)}]`;
            });
        }

        if (relatedMedia.length > 0) {
            renderList(relatedMedia, 'mediaListOutput', item => `[${escapeHtml(item.title)}]`);
        }

        if (therapies.length > 0) {
            renderList(therapies, 'therapyListOutput', item => `<strong>${escapeHtml(item.label || item.type)}</strong>${item.frequency ? ` (${escapeHtml(item.frequency)})` : ''}`);
        }

        if (programLevels.length > 0) {
            renderList(programLevels, 'levelListOutput', item => {
                const parts = [`<strong>${escapeHtml(item.name)}</strong>`];
                if (item.duration) parts.push(`Duration: ${escapeHtml(item.duration)}`);
                if (item.privileges) parts.push(`Privileges: ${escapeHtml(item.privileges.substring(0, 50))}...`);
                return parts.join(' | ');
            });
        }

        // Set diagnosis checkboxes from parsed data
        if (parsedData.selectedDiagnoses && parsedData.selectedDiagnoses.length > 0) {
            // First uncheck all diagnosis checkboxes
            document.querySelectorAll('input[name="diagnoses"]').forEach(cb => cb.checked = false);

            // Then check the ones that were parsed
            parsedData.selectedDiagnoses.forEach(diagnosis => {
                const checkbox = document.querySelector(`input[name="diagnoses"][value="${diagnosis}"]`);
                if (checkbox) {
                    checkbox.checked = true;
                } else {
                    console.log(`Diagnosis checkbox not found for: "${diagnosis}"`);
                }
            });
            console.log(`✓ Checked ${parsedData.selectedDiagnoses.length} diagnosis checkboxes`);
        }

        // Set custom diagnoses field
        if (parsedData.customDiagnoses) {
            setValue('customDiagnoses', parsedData.customDiagnoses);
            console.log(`✓ Set custom diagnoses: ${parsedData.customDiagnoses}`);
        }

        // Set allegation checkboxes from parsed data
        if (parsedData.selectedAllegations && parsedData.selectedAllegations.length > 0) {
            // First uncheck all allegation checkboxes
            document.querySelectorAll('input[name="allegations"]').forEach(cb => cb.checked = false);

            // Then check the ones that were parsed
            parsedData.selectedAllegations.forEach(allegation => {
                const checkbox = document.querySelector(`input[name="allegations"][value="${allegation}"]`);
                if (checkbox) {
                    checkbox.checked = true;
                } else {
                    console.log(`Allegation checkbox not found for: "${allegation}"`);
                }
            });
            console.log(`✓ Checked ${parsedData.selectedAllegations.length} allegation checkboxes`);
        }

        // Set custom allegations field
        if (parsedData.customAllegations) {
            setValue('customAllegations', parsedData.customAllegations);
            console.log(`✓ Set custom allegations: ${parsedData.customAllegations}`);
        }

        console.log('✓ Import complete!');
        console.log('Parsed data:', parsedData);
    }

    // --- UTILITY FUNCTIONS ---
    function escapeHtml(text) {
        const map = {
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#39;'
        };
        return String(text).replace(/[&<>"']/g, m => map[m]);
    }

    function escapeMarkdown(text) {
        // Don't escape - preserve markdown formatting including links
        // The bio and other fields may contain intentional markdown
        return String(text);
    }

    function escapeMarkdownBasic(text) {
        return String(text)
            .replace(/\\/g, '\\\\')
            .replace(/\*/g, '\\*')
            .replace(/_/g, '\\_')
            .replace(/`/g, '\\`');
    }

    // --- DATABASE SUBMISSION ---
    const submitToDbBtn = document.getElementById('submitToDbBtn');
    const submitModal = document.getElementById('submitModal');
    const cancelSubmitBtn = document.getElementById('cancelSubmitBtn');
    const confirmSubmitBtn = document.getElementById('confirmSubmitBtn');
    const submitStatus = document.getElementById('submitStatus');

    if (submitToDbBtn && submitModal) {
        submitToDbBtn.addEventListener('click', () => {
            submitModal.style.display = 'flex';
            submitStatus.innerHTML = '';
        });

        cancelSubmitBtn.addEventListener('click', () => {
            submitModal.style.display = 'none';
        });

        submitModal.addEventListener('click', (e) => {
            if (e.target === submitModal) {
                submitModal.style.display = 'none';
            }
        });

        confirmSubmitBtn.addEventListener('click', async () => {
            const programName = document.getElementById('programName')?.value || '';
            const outputCode = document.getElementById('outputCode')?.value || '';

            if (!programName.trim()) {
                submitStatus.innerHTML = '<span class="error">❌ Program name is required</span>';
                return;
            }

            if (!outputCode.trim()) {
                submitStatus.innerHTML = '<span class="error">❌ Please generate wiki code first</span>';
                return;
            }

            submitStatus.innerHTML = '<span class="loading">⏳ Submitting...</span>';
            confirmSubmitBtn.disabled = true;

            // Collect all form data
            const formData = {
                programName: programName,
                yearsActive: document.getElementById('yearsActive')?.value || '',
                cityState: document.getElementById('cityState')?.value || '',
                programType: document.getElementById('programType')?.value || '',
                yearFounded: document.getElementById('yearFounded')?.value || '',
                ageRange: document.getElementById('ageRange')?.value || '',
                capacity: document.getElementById('capacity')?.value || '',
                ownerName: document.getElementById('ownerName')?.value || '',
                ownerLink: document.getElementById('ownerLink')?.value || '',
                avgStay: document.getElementById('avgStay')?.value || '',
                tuition: document.getElementById('tuition')?.value || '',
                natsapMember: document.getElementById('natsapMember')?.value || '',
                natsapYear: document.getElementById('natsapYear')?.value || '',
                diagnosesList: document.getElementById('diagnosesList')?.value || '',
                mainAddress: document.getElementById('mainAddress')?.value || '',
                addressLink: document.getElementById('addressLink')?.value || '',
                accreditingBody: document.getElementById('accreditingBody')?.value || '',
                accreditingBodyLink: document.getElementById('accreditingBodyLink')?.value || '',
                mainComplaints: document.getElementById('mainComplaints')?.value || '',
                mediaInfo: document.getElementById('mediaInfo')?.value || '',
                // Arrays
                staffMembers: staffMembers,
                punishments: punishments,
                lawsuits: lawsuits,
                newsArticles: newsArticles,
                testimonies: testimonies,
                relatedMedia: relatedMedia,
                campuses: campuses,
                ownershipChanges: ownershipChanges,
                rules: rules,
                allegations: allegations,
                therapies: therapies,
                // Generated output
                generatedMarkdown: outputCode,
                // Original imported markdown
                originalMarkdown: importedMarkdown,
                // Submission metadata
                submittedBy: document.getElementById('submitterEmail')?.value || '',
                submissionNotes: document.getElementById('submissionNotes')?.value || ''
            };

            try {
                const response = await fetch('/wp-content/themes/child/api/save-wiki-submission.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify(formData)
                });

                const result = await response.json();

                if (result.success) {
                    submitStatus.innerHTML = `<span class="success">✅ Submitted successfully! (ID: ${result.id})</span>`;
                    setTimeout(() => {
                        submitModal.style.display = 'none';
                        submitStatus.innerHTML = '';
                    }, 3000);
                } else {
                    submitStatus.innerHTML = `<span class="error">❌ ${result.message || 'Submission failed'}</span>`;
                }
            } catch (error) {
                console.error('Submission error:', error);
                submitStatus.innerHTML = '<span class="error">❌ Network error. Please try again.</span>';
            } finally {
                confirmSubmitBtn.disabled = false;
            }
        });
    }

    // --- BULK UPLOAD FUNCTIONALITY ---
    const toggleBulkUploadBtn = document.getElementById('toggleBulkUploadBtn');
    const bulkUploadPanel = document.getElementById('bulkUploadPanel');
    const bulkFileInput = document.getElementById('bulkFileInput');
    const uploadFilesBtn = document.getElementById('uploadFilesBtn');
    const cancelBulkUploadBtn = document.getElementById('cancelBulkUploadBtn');
    const uploadProgress = document.getElementById('uploadProgress');
    const progressBarFill = document.getElementById('progressBarFill');
    const uploadStatus = document.getElementById('uploadStatus');
    const uploadResults = document.getElementById('uploadResults');

    // Toggle bulk upload panel
    if (toggleBulkUploadBtn && bulkUploadPanel) {
        toggleBulkUploadBtn.addEventListener('click', () => {
            const isHidden = bulkUploadPanel.style.display === 'none';
            bulkUploadPanel.style.display = isHidden ? 'block' : 'none';
            toggleBulkUploadBtn.textContent = isHidden ? '✖️ Close Bulk Upload' : '📤 Bulk Upload Markdown Files';
        });
    }

    // Cancel bulk upload
    if (cancelBulkUploadBtn && bulkUploadPanel) {
        cancelBulkUploadBtn.addEventListener('click', () => {
            bulkUploadPanel.style.display = 'none';
            if (toggleBulkUploadBtn) toggleBulkUploadBtn.textContent = '📤 Bulk Upload Markdown Files';
            if (bulkFileInput) bulkFileInput.value = '';
            if (uploadProgress) uploadProgress.style.display = 'none';
            if (uploadResults) uploadResults.innerHTML = '';
        });
    }

    // Upload files
    if (uploadFilesBtn && bulkFileInput) {
        uploadFilesBtn.addEventListener('click', async () => {
            const files = bulkFileInput.files;
            if (!files || files.length === 0) {
                alert('Please select at least one markdown file.');
                return;
            }

            // Show progress
            if (uploadProgress) uploadProgress.style.display = 'block';
            if (uploadResults) uploadResults.innerHTML = '';

            const results = {
                success: [],
                failed: []
            };

            uploadFilesBtn.disabled = true;

            for (let i = 0; i < files.length; i++) {
                const file = files[i];
                const progress = Math.round(((i + 1) / files.length) * 100);

                if (progressBarFill) progressBarFill.style.width = `${progress}%`;
                if (uploadStatus) uploadStatus.textContent = `Processing ${i + 1} of ${files.length}: ${file.name}`;

                try {
                    // Read file content
                    const content = await readFileAsText(file);

                    // Parse the markdown
                    if (typeof parseWikiMarkdown !== 'function') {
                        throw new Error('Parser not loaded');
                    }
                    const parsedData = parseWikiMarkdown(content);

                    // Check if we got a program name
                    if (!parsedData.programName || !parsedData.programName.trim()) {
                        throw new Error('Could not extract program name from markdown');
                    }

                    // Prepare submission data
                    const submissionData = {
                        programName: parsedData.programName,
                        yearsActive: parsedData.yearsActive || '',
                        cityState: parsedData.cityState || '',
                        programType: parsedData.programType || '',
                        yearFounded: parsedData.yearFounded || '',
                        ageRange: parsedData.ageRange || '',
                        capacity: parsedData.capacity || '',
                        ownerName: parsedData.ownerName || '',
                        ownerLink: parsedData.ownerLink || '',
                        avgStay: parsedData.avgStay || '',
                        tuition: parsedData.tuition || '',
                        natsapMember: parsedData.natsapMember || '',
                        natsapYear: parsedData.natsapYear || '',
                        mainAddress: parsedData.mainAddress || '',
                        addressLink: parsedData.addressLink || '',
                        accreditingBody: parsedData.accreditingBody || '',
                        accreditingBodyLink: parsedData.accreditingBodyLink || '',
                        staffMembers: parsedData.staffMembers || [],
                        punishments: parsedData.punishments || [],
                        lawsuits: parsedData.lawsuits || [],
                        newsArticles: parsedData.newsArticles || [],
                        testimonies: parsedData.testimonies || [],
                        relatedMedia: parsedData.relatedMedia || [],
                        campuses: parsedData.campuses || [],
                        ownershipChanges: parsedData.ownershipChanges || [],
                        rules: parsedData.rules || [],
                        allegations: parsedData.allegations || [],
                        therapies: parsedData.therapies || [],
                        selectedDiagnoses: parsedData.selectedDiagnoses || [],
                        customDiagnoses: parsedData.customDiagnoses || '',
                        selectedAllegations: parsedData.selectedAllegations || [],
                        customAllegations: parsedData.customAllegations || '',
                        originalMarkdown: content,
                        generatedMarkdown: content, // Store original as generated for now
                        submittedBy: 'bulk-upload',
                        submissionNotes: `Bulk uploaded from file: ${file.name}`
                    };

                    // Submit to database
                    const response = await fetch('/wp-content/themes/child/api/save-wiki-submission.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json'
                        },
                        body: JSON.stringify(submissionData)
                    });

                    const result = await response.json();

                    if (result.success) {
                        results.success.push({
                            file: file.name,
                            program: parsedData.programName,
                            id: result.id
                        });
                    } else {
                        throw new Error(result.error || 'Submission failed');
                    }

                } catch (error) {
                    console.error(`Error processing ${file.name}:`, error);
                    results.failed.push({
                        file: file.name,
                        error: error.message
                    });
                }
            }

            // Show results
            if (uploadStatus) uploadStatus.textContent = 'Upload complete!';
            if (uploadResults) {
                let resultsHtml = '<h4>Upload Results</h4>';

                if (results.success.length > 0) {
                    resultsHtml += `<div class="upload-success"><h5>✅ Successfully uploaded (${results.success.length}):</h5><ul>`;
                    results.success.forEach(item => {
                        resultsHtml += `<li><strong>${item.program}</strong> (${item.file}) - ID: ${item.id}</li>`;
                    });
                    resultsHtml += '</ul></div>';
                }

                if (results.failed.length > 0) {
                    resultsHtml += `<div class="upload-failed"><h5>❌ Failed (${results.failed.length}):</h5><ul>`;
                    results.failed.forEach(item => {
                        resultsHtml += `<li><strong>${item.file}</strong>: ${item.error}</li>`;
                    });
                    resultsHtml += '</ul></div>';
                }

                uploadResults.innerHTML = resultsHtml;
            }

            uploadFilesBtn.disabled = false;
            if (bulkFileInput) bulkFileInput.value = '';
        });
    }

    // Helper function to read file as text
    function readFileAsText(file) {
        return new Promise((resolve, reject) => {
            const reader = new FileReader();
            reader.onload = (e) => resolve(e.target.result);
            reader.onerror = (e) => reject(new Error('Failed to read file'));
            reader.readAsText(file);
        });
    }

    // --- ENTRY BROWSER FUNCTIONALITY ---
    const toggleBrowserBtn = document.getElementById('toggleBrowserBtn');
    const browserPanel = document.getElementById('browserPanel');
    const entriesList = document.getElementById('entriesList');
    const entrySearch = document.getElementById('entrySearch');
    const refreshEntriesBtn = document.getElementById('refreshEntriesBtn');
    const prevPageBtn = document.getElementById('prevPageBtn');
    const nextPageBtn = document.getElementById('nextPageBtn');
    const pageInfo = document.getElementById('pageInfo');

    let currentPage = 1;
    let totalEntries = 0;
    const entriesPerPage = 20;

    // Function to update view based on mode
    function updateBrowserView(viewMode) {
        const dbSection = document.querySelector('.database-entries-section');

        if (viewMode === 'operators') {
            // Hide database entries section
            if (dbSection) dbSection.style.display = 'none';
            if (document.getElementById('indexBrowser')) {
                document.getElementById('indexBrowser').style.display = 'block';
            }
            // Hide the dropdown selector since we're auto-loading CORPORATE
            if (indexSelect) indexSelect.style.display = 'none';

            // Automatically load CORPORATE organizations
            loadProgramsForState('CORPORATE');
        } else {
            // Show database entries section and dropdown for location selection
            if (dbSection) dbSection.style.display = 'block';
            if (document.getElementById('indexBrowser')) {
                document.getElementById('indexBrowser').style.display = 'block';
            }
            // Show the dropdown selector for state selection
            if (indexSelect) indexSelect.style.display = 'block';

            // Load the state options in dropdown
            loadIndexList(viewMode);
        }
    }

    // Toggle browser panel
    if (toggleBrowserBtn && browserPanel) {
        toggleBrowserBtn.addEventListener('click', () => {
            const isHidden = browserPanel.style.display === 'none';
            browserPanel.style.display = isHidden ? 'block' : 'none';
            toggleBrowserBtn.textContent = isHidden ? '✖️ Close Browser' : '📂 Browse Saved Entries';

            // Load entries when opening
            if (isHidden) {
                const currentViewMode = viewModeSelect ? viewModeSelect.value : 'operators';
                if (currentViewMode === 'all') {
                    loadEntries();
                }
                updateBrowserView(currentViewMode);
            }
        });
    }

    // Refresh entries
    if (refreshEntriesBtn) {
        refreshEntriesBtn.addEventListener('click', () => {
            currentPage = 1;
            loadEntries();
        });
    }

    // Search entries
    if (entrySearch) {
        let searchTimeout;
        entrySearch.addEventListener('input', () => {
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(() => {
                currentPage = 1;
                loadEntries();
            }, 500);
        });
    }

    // Pagination
    if (prevPageBtn) {
        prevPageBtn.addEventListener('click', () => {
            if (currentPage > 1) {
                currentPage--;
                loadEntries();
            }
        });
    }

    if (nextPageBtn) {
        nextPageBtn.addEventListener('click', () => {
            const totalPages = Math.ceil(totalEntries / entriesPerPage);
            if (currentPage < totalPages) {
                currentPage++;
                loadEntries();
            }
        });
    }

    const entryBrowserSection = document.querySelector('.entry-browser-section');
    const wikiIndexJsonUrl = entryBrowserSection?.dataset?.wikiIndexJson;
    const wikiProgramsBase = entryBrowserSection?.dataset?.wikiProgramsBase;
    const indexSelect = document.getElementById('indexSelect');
    const indexSearch = document.getElementById('indexSearch');
    const indexEntriesList = document.getElementById('indexEntriesList');
    const viewModeSelect = document.getElementById('viewModeSelect');

    let selectedIndexState = '';
    let currentIndexPrograms = [];
    const stateProgramsCache = {};
    let allIndexData = null; // Store full index data

    const setIndexSearchEnabled = (enabled) => {
        if (!indexSearch) return;
        indexSearch.disabled = !enabled;
    };

    const updateIndexEntriesMessage = (message, className = 'loading') => {
        if (!indexEntriesList || !message) return;
        indexEntriesList.innerHTML = `<p class="${className}">${escapeHtml(message)}</p>`;
    };

    // Helper function to determine if a code is an operator/special category
    const isOperatorCategory = (code) => {
        return code === 'CORPORATE';
    };

    // Helper function to get filtered index entries based on view mode
    const getFilteredIndexEntries = (states, viewMode) => {
        if (!states) return [];
        const entries = Object.entries(states);

        if (viewMode === 'operators') {
            // Show only operator categories (CORPORATE, NONUSA)
            return entries.filter(([code]) => isOperatorCategory(code));
        } else if (viewMode === 'all') {
            // Show only states (exclude operator categories)
            return entries.filter(([code]) => !isOperatorCategory(code));
        }
        // Default: show only states
        return entries.filter(([code]) => !isOperatorCategory(code));
    };

    function getSlugFromEntryUrl(url) {
        if (!url) return '';
        const cleaned = url.replace(/\/+$/, '');
        const match = cleaned.match(/wiki\/(?:index\/)?(.+)$/i);
        return match ? match[1] : '';
    }

    async function loadIndexList(viewMode = 'operators') {
        if (!indexSelect || !wikiIndexJsonUrl) {
            updateIndexEntriesMessage('Index metadata is unavailable.', 'error');
            return;
        }

        indexSelect.innerHTML = '';
        const defaultOption = document.createElement('option');
        defaultOption.value = '';
        defaultOption.textContent = 'Select an index page';
        indexSelect.appendChild(defaultOption);

        updateIndexEntriesMessage('Loading index data...');
        try {
            // Load index data if not already loaded
            if (!allIndexData) {
                const response = await fetch(wikiIndexJsonUrl, { cache: 'no-store' });
                if (!response.ok) {
                    throw new Error(`HTTP ${response.status}`);
                }
                allIndexData = await response.json();
            }

            // Filter based on view mode
            const filteredStates = getFilteredIndexEntries(allIndexData.states, viewMode);

            filteredStates.sort((a, b) => {
                const displayA = STATE_DISPLAY_NAMES[a[0]] || a[0];
                const displayB = STATE_DISPLAY_NAMES[b[0]] || b[0];
                return displayA.localeCompare(displayB);
            });

            filteredStates.forEach(([code, count]) => {
                const option = document.createElement('option');
                option.value = code;
                option.textContent = `${STATE_DISPLAY_NAMES[code] || code} (${count || 0})`;
                indexSelect.appendChild(option);
            });
            updateIndexEntriesMessage('Choose an index page above to see its entries.');
        } catch (error) {
            updateIndexEntriesMessage(
                `Failed to load Reddit index metadata: ${error.message || 'Unknown error'}`,
                'error'
            );
        }
    }

    async function loadProgramsForState(stateCode) {
        selectedIndexState = stateCode || '';
        setIndexSearchEnabled(!!stateCode);
        if (indexSearch) {
            indexSearch.value = '';
        }

        if (!stateCode) {
            currentIndexPrograms = [];
            updateIndexEntriesMessage('Choose an index page above to see its entries.');
            return;
        }

        const cached = stateProgramsCache[stateCode];
        if (cached) {
            currentIndexPrograms = cached;
            renderIndexEntries();
            return;
        }

        updateIndexEntriesMessage(`Loading entries for ${STATE_DISPLAY_NAMES[stateCode] || stateCode}...`);
        try {
            if (!wikiProgramsBase) {
                throw new Error('Programs base path is missing');
            }
            const listResponse = await fetch(`${wikiProgramsBase}programs-${stateCode}.json`, { cache: 'no-cache' });
            if (!listResponse.ok) {
                throw new Error(`HTTP ${listResponse.status}`);
            }
            const payload = await listResponse.json();
            const programs = Array.isArray(payload.programs) ? [...payload.programs] : [];
            programs.sort((a, b) => (a.name || '').localeCompare(b.name || ''));
            stateProgramsCache[stateCode] = programs;
            currentIndexPrograms = programs;
            renderIndexEntries();
        } catch (error) {
            currentIndexPrograms = [];
            updateIndexEntriesMessage(
                `Unable to load ${STATE_DISPLAY_NAMES[stateCode] || stateCode} entries: ${error.message || 'Unknown error'}`,
                'error'
            );
        }
    }

    function renderIndexEntries() {
        if (!indexEntriesList) return;
        if (!selectedIndexState) {
            updateIndexEntriesMessage('Choose an index page above to see its entries.');
            return;
        }

        if (!currentIndexPrograms.length) {
            updateIndexEntriesMessage('No entries available for this index.');
            return;
        }

        const filter = (indexSearch?.value || '').trim().toLowerCase();
        const matches = filter
            ? currentIndexPrograms.filter(item => {
                  const target = `${item.name || ''} ${item.normalizedName || ''}`.toLowerCase();
                  return target.includes(filter);
              })
            : currentIndexPrograms;

        if (matches.length === 0) {
            updateIndexEntriesMessage('No entries match that filter.');
            return;
        }

        indexEntriesList.innerHTML = '';
        matches.forEach(entry => {
            const row = document.createElement('div');
            row.className = 'index-entry-row';

            const nameSpan = document.createElement('span');
            nameSpan.className = 'index-entry-name';

            const slug = getSlugFromEntryUrl(entry.url);
            const entryName = entry.name || entry.normalizedName || 'Unnamed program';

            nameSpan.appendChild(document.createTextNode(entryName));

            const actions = document.createElement('div');
            actions.className = 'index-entry-actions';

            // Organizations show "View Programs" and allow editing the index page
            if (selectedIndexState === 'CORPORATE') {
                const viewProgramsBtn = document.createElement('button');
                viewProgramsBtn.type = 'button';
                viewProgramsBtn.textContent = 'View Programs';
                viewProgramsBtn.className = 'view-programs-btn';
                viewProgramsBtn.addEventListener('click', async (e) => {
                    e.preventDefault();
                    await loadOrgProgramsFromDatabase(entryName, viewProgramsBtn);
                });
                actions.appendChild(viewProgramsBtn);

                const editIndexBtn = document.createElement('button');
                editIndexBtn.type = 'button';
                editIndexBtn.textContent = 'Edit Index Page';
                editIndexBtn.className = 'edit-index-btn';
                editIndexBtn.addEventListener('click', (e) => {
                    e.preventDefault();
                    loadOrganizationIndexEntry(entry, editIndexBtn);
                });
                actions.appendChild(editIndexBtn);
            } else {
                const editButton = document.createElement('button');
                editButton.type = 'button';
                editButton.textContent = 'Edit';
                editButton.className = 'edit-entry-btn';
                editButton.addEventListener('click', (e) => {
                    e.preventDefault();
                    loadEntryFromReddit(entry, editButton);
                });
                actions.appendChild(editButton);
            }

            if (slug) {
                const viewLink = document.createElement('a');
                viewLink.href = `https://www.reddit.com/r/troubledteens/wiki/index/${slug}/`;
                viewLink.target = '_blank';
                viewLink.rel = 'noopener noreferrer';
                viewLink.textContent = 'View on Reddit';
                actions.appendChild(viewLink);
            }

            row.appendChild(nameSpan);
            row.appendChild(actions);
            indexEntriesList.appendChild(row);
        });
    }

    async function loadOrgProgramsFromDatabase(orgName, button) {
        const originalLabel = button?.textContent || 'View Programs';
        if (button) {
            button.disabled = true;
            button.textContent = 'Loading...';
        }

        try {
            updateIndexEntriesMessage(`Loading programs for ${orgName}...`);

            // Search DB for anything related to this org name
            const searchUrl = `/wp-content/themes/child/api/save-wiki-submission.php?search=${encodeURIComponent(orgName)}`;
            const searchResponse = await fetch(searchUrl);
            const searchResult = await searchResponse.json();

            // Store found programs here
            const programs = [];
            const processedNames = new Set();

            if (searchResult.success && searchResult.data) {
                
                // 1. DATABASE METHOD: Look for facilities with this organization set in the DB
                searchResult.data.forEach(submission => {
                    // Check if organization column matches
                    if (submission.organization && 
                        submission.organization.toLowerCase().includes(orgName.toLowerCase())) {
                        
                        // Exclude the organization page itself
                        if (submission.program_name.toLowerCase() !== orgName.toLowerCase()) {
                            const name = submission.program_name;
                            if (!processedNames.has(name.toLowerCase())) {
                                programs.push({
                                    name: name,
                                    url: '', // No URL for DB-only entries yet
                                    normalizedName: name.toLowerCase(),
                                    source: 'database',
                                    id: submission.id
                                });
                                processedNames.add(name.toLowerCase());
                            }
                        }
                    }
                });

                // 2. MARKDOWN METHOD: Parse the organization's page for links
                const orgPage = searchResult.data.find(e =>
                    e.program_name.toLowerCase() === orgName.toLowerCase()
                );

                if (orgPage && orgPage.original_markdown) {
                    const markdown = orgPage.original_markdown || '';
                    const linkRegex = /\[([^\]]+)\]\(([^)]+)\)/g;
                    let match;

                    while ((match = linkRegex.exec(markdown)) !== null) {
                        const name = match[1];
                        const url = match[2];

                        const isUserLink = url.includes('/u/') || url.includes('/user/');
                        const isExternal = url.includes('http');
                        const isWikiLink = url.includes('/wiki/');
                        const isJustIndexPage = url.endsWith('/index/') || url.endsWith('/index');

                        if (isWikiLink && !isUserLink && !isExternal && !isJustIndexPage && name.length > 2) {
                            if (!processedNames.has(name.toLowerCase())) {
                                programs.push({
                                    name: name,
                                    url: url,
                                    normalizedName: name.toLowerCase(),
                                    source: 'markdown'
                                });
                                processedNames.add(name.toLowerCase());
                            }
                        }
                    }
                }
            }

            if (programs.length === 0) {
                 // Fallback: If absolutely nothing found, check if we at least found the org page
                 // If not even the org page exists, then it's truly not found.
                 if (!searchResult.data || searchResult.data.length === 0) {
                     throw new Error('Organization not found in database');
                 } else {
                     throw new Error('No facilities found for this organization (checked database and wiki links)');
                 }
            }

            console.log(`Found ${programs.length} programs for "${orgName}"`);

            programs.sort((a, b) => (a.name || '').localeCompare(b.name || ''));

            // Update the view to show programs instead of organizations
            selectedIndexState = 'ORG_PROGRAMS';
            currentIndexPrograms = programs;
            renderIndexEntries();

        } catch (error) {
            console.error('Error loading organization programs:', error);
            updateIndexEntriesMessage(`Error: ${error.message}`, 'error');
        } finally {
            if (button) {
                button.disabled = false;
                button.textContent = originalLabel;
            }
        }
    }

    async function loadEntryFromReddit(entry, button) {
        if (!entry) return;

        const programName = entry.name || entry.normalizedName;

        const originalLabel = button?.textContent || 'Edit';
        if (button) {
            button.disabled = true;
            button.textContent = 'Loading...';
        }

        try {
            const dbEntry = await fetchSubmissionByName(programName);
            if (dbEntry) {
                await loadEntryIntoForm(dbEntry.id);
            } else {
                throw new Error('Entry not found in database');
            }
        } catch (error) {
            console.error('Error loading entry:', error);
            alert(`Failed to load ${programName}: ${error.message || 'Entry not found'}`);
        } finally {
            if (button) {
                button.disabled = false;
                button.textContent = originalLabel;
            }
        }
    }

    indexSelect?.addEventListener('change', event => loadProgramsForState(event.target.value));
    indexSearch?.addEventListener('input', () => renderIndexEntries());

    // Add event listener for view mode selector
    viewModeSelect?.addEventListener('change', (event) => {
        const viewMode = event.target.value;
        // Reset selection when changing view mode
        selectedIndexState = '';
        currentIndexPrograms = [];
        if (indexSelect) indexSelect.value = '';
        // Update view and reload the index list with the new filter
        updateBrowserView(viewMode);
        // Load database entries if switching to "all" mode
        if (viewMode === 'all') {
            loadEntries();
        }
    });

    setIndexSearchEnabled(false);
    // Index list will be loaded when browser panel is opened

    // Load entries from API
    async function loadEntries() {
        if (!entriesList) return;

        entriesList.innerHTML = '<p class="loading">Loading entries...</p>';

        try {
            const searchQuery = entrySearch ? entrySearch.value.trim() : '';
            const offset = (currentPage - 1) * entriesPerPage;

            let url = `/wp-content/themes/child/api/save-wiki-submission.php?limit=${entriesPerPage}&offset=${offset}`;
            if (searchQuery) {
                url += `&search=${encodeURIComponent(searchQuery)}`;
            }

            const response = await fetch(url);
            const result = await response.json();

            if (!result.success) {
                throw new Error(result.error || 'Failed to load entries');
            }

            totalEntries = result.total || 0;
            const entries = result.data || [];

            // Update pagination
            const totalPages = Math.ceil(totalEntries / entriesPerPage);
            if (pageInfo) pageInfo.textContent = `Page ${currentPage} of ${totalPages} (${totalEntries} total)`;
            if (prevPageBtn) prevPageBtn.disabled = currentPage <= 1;
            if (nextPageBtn) nextPageBtn.disabled = currentPage >= totalPages;

            // Render entries
            if (entries.length === 0) {
                entriesList.innerHTML = '<p class="no-entries">No entries found.</p>';
                return;
            }

            let html = '<div class="entries-table"><table><thead><tr><th>Program Name</th><th>Location</th><th>Type</th><th>Years Active</th><th>Created</th><th>Actions</th></tr></thead><tbody>';

            entries.forEach(entry => {
                const createdDate = new Date(entry.created_at).toLocaleDateString();

                // Check if entry content indicates it doesn't exist
                const content = entry.markdown || entry.content || '';
                const isEmptyPage = content.toLowerCase().includes('does not exist') ||
                                   content.toLowerCase().includes('not found') ||
                                   content.trim().length < 50; // Very short content likely means empty page

                const notFoundFlag = isEmptyPage ? '<span class="entry-not-found-flag" title="This page has no content">⚠️ EMPTY</span> ' : '';

                const actionButtons = `
                    <tr data-entry-id="${entry.id}">
                        <td class="entry-name" data-label="Program Name">${notFoundFlag}<strong>${escapeHtml(entry.program_name || 'Untitled')}</strong></td>
                        <td data-label="Location">${escapeHtml(entry.city_state || '-')}</td>
                        <td data-label="Type">${escapeHtml(entry.program_type || '-')}</td>
                        <td data-label="Years Active">${escapeHtml(entry.years_active || '-')}</td>
                        <td data-label="Created">${createdDate}</td>
                        <td data-label="Actions">
                        <button type="button" class="load-entry-btn" data-entry-id="${entry.id}">Load</button>
                        ${isAdminMode ? `<button type="button" class="delete-entry-btn" data-entry-id="${entry.id}">Delete</button>` : ''}
                    </td>
                </tr>
                `;
                html += actionButtons;
            });

            html += '</tbody></table></div>';
            entriesList.innerHTML = html;

            // Attach event listeners to load buttons
            entriesList.querySelectorAll('.load-entry-btn').forEach(btn => {
                btn.addEventListener('click', () => {
                    const entryId = btn.getAttribute('data-entry-id');
                    loadEntryIntoForm(entryId);
                });
            });

            // Attach event listeners to delete buttons
            entriesList.querySelectorAll('.delete-entry-btn').forEach(btn => {
                btn.addEventListener('click', () => {
                    const entryId = btn.getAttribute('data-entry-id');
                    deleteEntry(entryId);
                });
            });

        } catch (error) {
            console.error('Error loading entries:', error);
            entriesList.innerHTML = `<p class="error">Error loading entries: ${error.message}</p>`;
        }
    }

    // Load entry into form
    async function loadEntryIntoForm(entryId) {
        try {
            const response = await fetch(`/wp-content/themes/child/api/save-wiki-submission.php?id=${encodeURIComponent(entryId)}`);
            const result = await response.json();

            if (!result.success) {
                throw new Error(result.error || 'Failed to load entry');
            }

            const entry = result.data;
            
            // PRIORITIZE MARKDOWN RE-PARSE
            // This ensures that updates to the parser logic (wiki-parser.js) are immediately 
            // applied to existing entries when loaded, fixing issues like "Unparsed Content".
            if (entry.generated_markdown && entry.generated_markdown.trim()) {
                console.log('Loading entry via markdown re-parse (applying latest parser rules)...');
                parseAndPopulate(entry.generated_markdown);
                
                // Ensure critical DB fields override the parse if they are empty/different?
                // Actually, trust the parse, but ensure Program Name matches DB if parse failed to find it.
                const programNameInput = document.getElementById('programName');
                if (programNameInput && (!programNameInput.value || entry.program_name)) {
                    programNameInput.value = entry.program_name;
                }
            } else {
                console.warn('No generated markdown found for this entry. Falling back to stored JSON data.');
                const data = entry.json_data || {};
                populateFormFromParsedData(data, {
                    programName: entry.program_name,
                    yearsActive: entry.years_active,
                    cityState: entry.city_state,
                    programType: entry.program_type
                });
            }

            // Regenerate clean markdown from the parsed/loaded data
            updateMarkdownFromForm();

            importedMarkdown = entry.generated_markdown || '';
            finalizeEntryLoad(entry.program_name);
        } catch (error) {
            console.error('Error loading entry:', error);
            alert(`Failed to load entry: ${error.message}`);
        }
    }
    // Delete entry
    async function deleteEntry(entryId) {
        if (!isAdminMode) {
            alert('Only admins can delete entries.');
            return;
        }
        if (!confirm('Are you sure you want to delete this entry? This cannot be undone.')) {
            return;
        }

        try {
            // Note: We need to add DELETE endpoint to the API
            // For now, we can use a workaround by setting status to 'deleted'
            const response = await fetch('/wp-content/themes/child/api/save-wiki-submission.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    id: entryId,
                    status: 'deleted'
                })
            });

            const result = await response.json();

            if (result.success) {
                alert('Entry deleted successfully');
                loadEntries(); // Refresh the list
            } else {
                throw new Error(result.error || 'Delete failed');
            }

        } catch (error) {
            console.error('Error deleting entry:', error);
            alert(`Failed to delete entry: ${error.message}`);
        }
    }
});
