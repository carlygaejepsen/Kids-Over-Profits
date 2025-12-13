// NOTE: This script requires wiki-parser.js to be loaded first
// Add <script src="js/wiki-parser.js"></script> before this file in your HTML

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

    // --- Helper: Render a Preview List ---
    const renderList = (array, outputElement, renderer) => {
        const outputDiv = document.getElementById(outputElement);
        if (!outputDiv) {
            console.warn(`Wiki Editor: #${outputElement} not found.`);
            return;
        }

        outputDiv.innerHTML = '';
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
            const type = document.getElementById('punishmentType')?.value || 'other';
            const action = document.getElementById('punishmentAction')?.value.trim() || '';
            const trigger = document.getElementById('punishmentTrigger')?.value.trim() || '';
            if (name && action) {
                punishments.push({ name, type, action, trigger });
                renderList(punishments, 'punishmentListOutput', item => `<strong>${escapeHtml(item.name)}</strong> — ${escapeHtml(item.action).substring(0, 40)}...`);
                clearInputs(['punishmentName', 'punishmentAction', 'punishmentTrigger']);
            } else {
                alert('Please enter at least a punishment name and what happens.');
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
                renderList(lawsuits, 'lawsuitListOutput', item => `<strong>${escapeHtml(item.year)}: ${escapeHtml(item.plaintiff)}</strong> vs. ${escapeHtml(item.defendant || 'program')}`);
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

    // --- MAIN GENERATE BUTTON ---
    const generateBtn = document.getElementById('generateBtn');
    const outputCode = document.getElementById('outputCode');
    if (generateBtn) {
        generateBtn.addEventListener('click', (e) => {
            e.preventDefault();
            // --- Get All Single-Field Values ---
            const vals = {};
            const inputs = document.querySelectorAll('#wikiForm input[type="text"], #wikiForm textarea, #wikiForm select');
            inputs.forEach(input => {
                if (input.id && !input.closest('.form-adder')) {
                    vals[input.id] = input.value.trim();
                }
            });

            const programName = vals.programName || '[Program Name]';

            // --- Helper: Create Link ---
            const createLink = (text, url) => {
                if (!text) return '';
                const safeUrl = sanitizeUrl(url);
                if (!safeUrl) return escapeMarkdown(text);
                return `[${escapeMarkdown(text)}](${safeUrl})`;
            };

            // --- Helper: Process Simple List from Textarea ---
            const processSimpleList = (text) => {
                if (!text.trim()) return '';
                return text.split('\n')
                    .filter(line => line.trim() !== '')
                    .map(line => `* ${escapeMarkdown(line.trim())}`)
                    .join('\n');
            };

            // --- Helper: Join With "and" for natural sentences ---
            const joinWithAnd = (items) => {
                if (items.length === 1) return items[0];
                if (items.length === 2) return `${items[0]} and ${items[1]}`;
                return `${items.slice(0, -1).join(', ')}, and ${items[items.length - 1]}`;
            };

            // --- Helper: Ensure trailing period for fragments ---
            const ensureSentence = (text) => {
                const trimmed = (text || '').trim();
                if (!trimmed) return '';
                return /[.!?]"?$/.test(trimmed) ? trimmed : `${trimmed}.`;
            };

    const roleVerb = (staffMember) => {
        if (!staffMember) return 'is';
        if (staffMember.isFormer) return 'was';
        const value = (staffMember.role || '').toLowerCase();
        return /\b(former|previous|ex[\s-])/.test(value) ? 'was' : 'is';
    };

            // --- Build History Section ---
            let historySection = '';

            // If we have the full imported history section, use it (preserves all content)
            // Otherwise, generate from structured data
            if (vals.historyNotes && vals.historyNotes.trim()) {
                historySection = vals.historyNotes.trim();
            } else {
                const historySentences = [];

                const descriptorParts = [];
                if (vals.programType) descriptorParts.push(`a ${escapeMarkdown(vals.programType)}`);
                if (vals.yearFounded) descriptorParts.push(`founded in ${escapeMarkdown(vals.yearFounded)}`);
                if (vals.cityState) descriptorParts.push(`based in ${escapeMarkdown(vals.cityState)}`);
                if (descriptorParts.length > 0) {
                    historySentences.push(`${escapeMarkdown(programName)} is ${joinWithAnd(descriptorParts)}.`);
                }

                if (vals.ownerName) {
                    historySentences.push(`The program is owned and operated by ${createLink(vals.ownerName, vals.ownerLink)}.`);
                }

                const audienceParts = [];
                if (vals.ageRange) audienceParts.push(`serves young people aged ${escapeMarkdown(vals.ageRange)}`);
                if (vals.diagnosesList) {
                    const diagnoses = vals.diagnosesList.split(',')
                        .map(item => item.trim())
                        .filter(Boolean)
                        .map(item => `"${escapeMarkdown(item)}"`).join(', ');
                    if (diagnoses) {
                        audienceParts.push(`lists ${diagnoses} as target diagnoses or behaviors`);
                    }
                }
                if (audienceParts.length > 0) {
                    historySentences.push(`The program ${joinWithAnd(audienceParts)}.`);
                }

                const operationsParts = [];
                if (vals.avgStay) operationsParts.push(`reports an average length of stay of around ${escapeMarkdown(vals.avgStay)}`);
                if (vals.tuition) operationsParts.push(`reports tuition of ${escapeMarkdown(vals.tuition)}`);
                // Build NATSAP status sentence from dropdown + year
                if (vals.natsapMember === 'yes' && vals.natsapYear) {
                    operationsParts.push(`has been a NATSAP member since ${escapeMarkdown(vals.natsapYear)}`);
                } else if (vals.natsapMember === 'yes') {
                    operationsParts.push(`is a NATSAP member`);
                } else if (vals.natsapMember === 'former') {
                    operationsParts.push(`is a former NATSAP member`);
                } else if (vals.natsapMember === 'no') {
                    operationsParts.push(`is not a NATSAP member`);
                }
                if (operationsParts.length > 0) {
                    historySentences.push(`It ${joinWithAnd(operationsParts)}.`);
                }

                if (vals.mainAddress) {
                    historySentences.push(`The main office is located at ${createLink(vals.mainAddress, vals.addressLink)}.`);
                }

                if (vals.accreditingBody) {
                    historySentences.push(`The program is accredited by the ${createLink(vals.accreditingBody, vals.accreditingBodyLink)}.`);
                }

                // Generate sentences for additional campuses
                if (campuses.length > 0) {
                    const campusList = campuses.map(c => `${escapeMarkdown(c.name)} in ${escapeMarkdown(c.location)}`);
                    historySentences.push(`The program also operates additional locations including ${joinWithAnd(campusList)}.`);
                }

                // Generate sentences for ownership changes
                if (ownershipChanges.length > 0) {
                    ownershipChanges.forEach(change => {
                        // Create markdown links if URLs are available
                        const prevText = change.previousLink
                            ? `[${escapeMarkdown(change.previous)}](${change.previousLink})`
                            : escapeMarkdown(change.previous);
                        const newText = change.newOwnerLink
                            ? `[${escapeMarkdown(change.newOwner)}](${change.newOwnerLink})`
                            : escapeMarkdown(change.newOwner);

                        if (change.previous && change.newOwner) {
                            historySentences.push(`In ${escapeMarkdown(change.year)}, the program changed ownership from ${prevText} to ${newText}.`);
                        } else if (change.newOwner) {
                            historySentences.push(`In ${escapeMarkdown(change.year)}, the program was acquired by ${newText}.`);
                        } else if (change.previous) {
                            historySentences.push(`In ${escapeMarkdown(change.year)}, ${prevText} divested from the program.`);
                        }
                    });
                }

                historySection = historySentences.length > 0 ? historySentences.join('\n\n') : getPlaceholder('History and Background Information', programName);
            }

            // --- Build Staff Section ---
            let staffSection;
            if (staffMembers.length > 0) {
                // Sort staff: current staff first, then former staff
                const sortedStaff = [...staffMembers].sort((a, b) => {
                    const aIsFormer = a.isFormer || /\b(former|previous|ex[\s-])/i.test(a.role || '');
                    const bIsFormer = b.isFormer || /\b(former|previous|ex[\s-])/i.test(b.role || '');
                    if (aIsFormer && !bIsFormer) return 1;  // a is former, b is current -> b first
                    if (!aIsFormer && bIsFormer) return -1; // a is current, b is former -> a first
                    return 0; // preserve original order within group
                });
                
                staffSection = sortedStaff.map(s => {
                    let roleText = escapeMarkdown(s.role);
                    
                    // Determine if this is a former staff member
                    const roleHasFormer = /\b(former|previous|ex[\s-])/i.test(s.role || '');
                    const isFormerStaff = s.isFormer || roleHasFormer;
                    
                    // If marked as former, remove "current" from role
                    if (isFormerStaff) {
                        roleText = roleText.replace(/\bcurrent\s+/gi, '').trim();
                    }
                    
                    const articleRole = roleText.toLowerCase().startsWith('the ') ? roleText : `the ${roleText}`;
                    
                    let verb, descriptor;
                    if (s.isFormer && !roleHasFormer) {
                        // Checkbox marked as former, but role doesn't say "former"
                        // Use: "is the former [Role]"
                        verb = 'is';
                        descriptor = articleRole.replace(/^the\s+/i, 'the former ');
                    } else if (roleHasFormer) {
                        // Role already contains "former/previous/ex-"
                        // Use: "was the [Role]" (role already has former in it)
                        verb = 'was';
                        descriptor = articleRole;
                    } else {
                        // Current staff member
                        // Use: "is the [Role]"
                        verb = 'is';
                        descriptor = articleRole;
                    }
                    
                    const roleSentence = ensureSentence(`**${escapeMarkdown(s.name)}** ${verb} ${descriptor}`);
                    
                    // Format previous roles - handle both old string format and new {role, employer} format
                    let previousSentence = '';
                    if (s.previousRoles && s.previousRoles.length) {
                        const formattedRoles = s.previousRoles.map(pr => {
                            if (typeof pr === 'string') return escapeMarkdown(pr);
                            if (pr.role && pr.employer) return `${escapeMarkdown(pr.role)} at ${escapeMarkdown(pr.employer)}`;
                            return escapeMarkdown(pr.role || pr.employer || '');
                        }).filter(Boolean);
                        if (formattedRoles.length > 0) {
                            previousSentence = ensureSentence(`Previously worked as ${joinWithAnd(formattedRoles)}`);
                        }
                    }
                    
                    // Bio can contain markdown links - don't escape it, just ensure sentence ending
                    const bioSentence = ensureSentence(s.bio || '');
                    return [roleSentence, previousSentence, bioSentence].filter(Boolean).join(' ');
                }).join('\n\n');
            } else {
                staffSection = getPlaceholder('Founders and Notable Staff', programName);
            }

            // --- Build Structure Section ---
            let structureSection = '';
            const structureParts = [];

                // Build level system description from dropdown selections and textarea
                const levelSystemTypes = { level: 'level system', phase: 'phase system', point: 'point system', tier: 'tier system' };
                if (vals.levelSystemType && vals.levelCount) {
                    structureParts.push(`Like other behavior-modification programs, ${escapeMarkdown(programName)} uses a ${levelSystemTypes[vals.levelSystemType] || vals.levelSystemType} consisting of ${escapeMarkdown(vals.levelCount)} ${vals.levelSystemType === 'phase' ? 'phases' : 'levels'}.`);
                } else if (vals.levelSystemType) {
                    structureParts.push(`Like other behavior-modification programs, ${escapeMarkdown(programName)} uses a ${levelSystemTypes[vals.levelSystemType] || vals.levelSystemType}.`);
                }

                // Add level system details from textarea
                if (vals.levelSystemDesc && vals.levelSystemDesc.trim()) {
                    structureParts.push(vals.levelSystemDesc.trim());
                }

                // Build level/phase descriptions from programLevels array
                if (programLevels.length > 0) {
                    const levelDescriptions = programLevels.map(level => {
                        // If we have the full description stored, use it
                        if (level.fullDesc) {
                            return `- **${escapeMarkdown(level.name)}:** ${level.fullDesc}`;
                        }

                        // Otherwise build from structured fields
                        const parts = [];
                        if (level.duration) parts.push(`Duration: ${escapeMarkdown(level.duration)}`);
                        if (level.privileges) parts.push(`Privileges: ${escapeMarkdown(level.privileges)}`);
                        if (level.restrictions) parts.push(`Restrictions: ${escapeMarkdown(level.restrictions)}`);

                        return `- **${escapeMarkdown(level.name)}**${parts.length > 0 ? ': ' + parts.join('. ') : ''}`;
                    }).join('\n\n');

                    if (levelDescriptions) {
                        structureParts.push(levelDescriptions);
                    }
                }

                // Build education section from dropdowns
                const educationTypes = {
                    accredited: 'an accredited on-site school',
                    online: 'online or computer-based education',
                    packet: 'packet-based or worksheet education',
                    limited: 'limited or sporadic educational instruction',
                    none: 'no formal educational instruction'
                };
                if (vals.educationType) {
                    let eduSentence = `The program provides ${educationTypes[vals.educationType] || vals.educationType}`;
                    if (vals.educationAccreditor) {
                        eduSentence += `, accredited by ${escapeMarkdown(vals.educationAccreditor)}`;
                    }
                    eduSentence += '.';
                    structureParts.push(eduSentence);
                }

                // Build therapy section from structured data
                if (therapies.length > 0) {
                    const therapyDescriptions = therapies.map(t => {
                        return t.frequency ? `${t.label} (${escapeMarkdown(t.frequency)})` : t.label;
                    });
                    structureParts.push(`The program offers ${joinWithAnd(therapyDescriptions)}.`);
                }

                // Append any additional structure notes (e.g., points system details)
                if (vals.structureMisc && vals.structureMisc.trim()) {
                    structureParts.push(vals.structureMisc.trim());
                }

                structureSection = structureParts.length > 0 ? structureParts.join('\n\n') : getPlaceholder('Program Structure', programName);

            // --- Build Rules & Punishments Section ---
            let rulesSection = '';

            // If we have the full imported rules/punishments section, use it (preserves all content)
            // Otherwise, generate from structured data
            if (vals.punishmentsMisc && vals.punishmentsMisc.trim()) {
                rulesSection = vals.punishmentsMisc.trim();
            } else {
                const rulesParts = [];

                // Build rules list from structured data
                if (rules.length > 0) {
                    const rulesList = rules.map(r => `- ${escapeMarkdown(r.name)}`).join('\n');
                    rulesParts.push(`${escapeMarkdown(programName)} is a very strict program with many rules. Some of these rules include:\n\n${rulesList}`);
                }

                // Build punishment descriptions from structured data
                if (punishments.length > 0) {
                    const punishmentTypes = {
                        physical: 'physical', isolation: 'isolation-based', restriction: 'restriction-based',
                        humiliation: 'humiliation-based', labor: 'labor-based', other: ''
                    };
                    const punishmentDescriptions = punishments.map(p => {
                        let sentence = `**${escapeMarkdown(p.name)}**`;
                        const typeDesc = punishmentTypes[p.type];
                        if (typeDesc) sentence += ` is a ${typeDesc} punishment where`;
                        else sentence += ` is a punishment where`;
                        sentence += ` ${escapeMarkdown(p.action)}`;
                        if (p.trigger) {
                            sentence += `. This punishment is typically used for ${escapeMarkdown(p.trigger)}`;
                        }
                        return ensureSentence(sentence);
                    }).join('\n\n');
                    rulesParts.push(`The program uses various punishments to enforce compliance:\n\n${punishmentDescriptions}`);
                }

                rulesSection = rulesParts.length > 0 ? rulesParts.join('\n\n') : getPlaceholder('Rules and Punishments', programName);
            }

            // --- Build Abuse Section ---
            let abuseSection = '';

            // If we have the full imported lawsuits/abuse section, use it (preserves all content)
            // Otherwise, generate from structured data
            if (vals.lawsuitsMisc && vals.lawsuitsMisc.trim()) {
                abuseSection = vals.lawsuitsMisc.trim();
            } else {
                const abuseParts = [];
                if (vals.mainComplaints) {
                    abuseParts.push(`Many survivors have reported that abuse and neglect have occurred at ${escapeMarkdown(programName)}. The main complaints are of ${escapeMarkdown(vals.mainComplaints)}.`);
                }

                // Build allegations from structured data
                if (allegations.length > 0) {
                    const allegationTypeLabels = {
                        physical: 'physical abuse', emotional: 'emotional abuse', sexual: 'sexual abuse',
                        medical: 'medical neglect', educational: 'educational neglect', isolation: 'improper isolation',
                        restraint: 'improper restraints', food: 'food deprivation', sleep: 'sleep deprivation',
                        lgbtq: 'LGBTQ+ discrimination', religious: 'religious coercion', other: 'other abuse'
                    };
                    // Group allegations by type
                    const groupedAllegations = {};
                    allegations.forEach(a => {
                        const typeLabel = allegationTypeLabels[a.type] || a.type;
                        if (!groupedAllegations[typeLabel]) groupedAllegations[typeLabel] = [];
                        groupedAllegations[typeLabel].push(a.detail);
                    });
                    const allegationsList = Object.entries(groupedAllegations).map(([type, details]) => {
                        return `- **${type}**: ${details.map(d => escapeMarkdown(d)).join('; ')}`;
                    }).join('\n');
                    abuseParts.push(`Specific allegations of abuse and neglect reported by survivors include:\n\n${allegationsList}`);
                }

                // Build lawsuit descriptions from structured data
                if (lawsuits.length > 0) {
                    const outcomeLabels = {
                        settled: 'was settled', dismissed: 'was dismissed', plaintiff: 'was decided in favor of the plaintiff',
                        defendant: 'was decided in favor of the defendant', ongoing: 'is still ongoing'
                    };
                    const lawsuitDescriptions = lawsuits.map(lawsuit => {
                        const defendant = lawsuit.defendant || programName;
                        let sentence = `In ${escapeMarkdown(lawsuit.year)}, ${escapeMarkdown(lawsuit.plaintiff)} filed a lawsuit against ${escapeMarkdown(defendant)}`;
                        if (lawsuit.court) sentence += ` in ${escapeMarkdown(lawsuit.court)}`;
                        sentence += ` alleging ${escapeMarkdown(lawsuit.claims)}.`;

                        if (lawsuit.outcome && outcomeLabels[lawsuit.outcome]) {
                            sentence += ` The case ${outcomeLabels[lawsuit.outcome]}`;
                            if (lawsuit.amount) {
                                sentence += ` for ${escapeMarkdown(lawsuit.amount)}`;
                            }
                            sentence += '.';
                        } else if (lawsuit.amount) {
                            sentence += ` The settlement was ${escapeMarkdown(lawsuit.amount)}.`;
                        }

                        return sentence;
                    }).join('\n\n');
                    abuseParts.push(lawsuitDescriptions);
                }

                abuseSection = abuseParts.length > 0 ? abuseParts.join('\n\n') : getPlaceholder('Abuse/Neglect Allegations and Lawsuits', programName);
            }

            // --- Build Media Section (Combined) ---
            let mediaSection;
            if (vals.mediaInfo && vals.mediaInfo.trim()) {
                // Use the full imported media section if available
                mediaSection = vals.mediaInfo.trim();
            } else {
                // Otherwise, generate from structured data
                const mediaList = processSimpleList(vals.mediaInfo).replace(/^\*/gm, '-');
                const newsList = newsArticles.map(a => {
                    let sourceDate = [a.source, a.date].filter(Boolean).join(', ');
                    if (sourceDate) sourceDate = ` (${sourceDate})`;
                    const safeUrl = sanitizeUrl(a.url);
                    const linkText = safeUrl ? `[${escapeMarkdown(a.title)}](${safeUrl})` : escapeMarkdown(a.title);
                    return `- ${linkText}${sourceDate}`;
                }).join('\n');
                const combinedMedia = [mediaList, newsList].filter(Boolean).join('\n\n');
                mediaSection = combinedMedia || getPlaceholder('Media Coverage', programName);
            }

            // --- Build Testimonies Section ---
            let testimoniesSection;
            if (vals.testimoniesMisc && vals.testimoniesMisc.trim()) {
                // Use the full imported testimonies section if available
                testimoniesSection = vals.testimoniesMisc.trim();
            } else if (testimonies.length > 0) {
                // Otherwise, generate from structured data
                testimoniesSection = testimonies.map(t => {
                    const datePart = t.date ? `${t.date}: ` : '';
                    const safeUrl = sanitizeUrl(t.url);
                    const sourceLink = safeUrl ? `[${escapeMarkdown(t.source)}](${safeUrl})` : escapeMarkdown(t.source);
                    return `**${datePart}(${t.type})** "${escapeMarkdown(t.quote)}" - ${sourceLink}`;
                }).join('\n\n');
            } else {
                testimoniesSection = getPlaceholder('Survivor Testimonies', programName);
            }

            // --- Build Related Media Section ---
            let relatedMediaSection;
            if (vals.relatedMediaMisc && vals.relatedMediaMisc.trim()) {
                // Use the full imported related media section if available
                relatedMediaSection = vals.relatedMediaMisc.trim();
            } else if (relatedMedia.length > 0) {
                // Otherwise, generate from structured data
                relatedMediaSection = relatedMedia.map(m => {
                    const safeUrl = sanitizeUrl(m.url);
                    const linkText = safeUrl ? `[${escapeMarkdown(m.title)}](${safeUrl})` : escapeMarkdown(m.title);
                    return `- ${linkText}`;
                }).join('\n\n');
            } else {
                relatedMediaSection = getPlaceholder('Related Media', programName);
            }

            const headerLine = `#**${escapeMarkdown(programName)}** (${vals.yearsActive || '[Years Active]'}) ${vals.cityState || '[City, ST]'}`;
            const sectionBreak = '\n***\n\n';

            // --- Assemble Final Output ---
            const output = `
${headerLine}
*${vals.programType || '[Program Type]'}*

***

##**History and Background Information**

${historySection}

***

##**Founders and Notable Staff**

${staffSection}

***

##**Program Structure**

${structureSection}

***

##**Rules and Punishments**

${rulesSection}

***

##**Abuse/Neglect Allegations and Lawsuits**

${abuseSection}

***

##**In the Media**

${mediaSection}

***

##**Survivor Testimonies**

${testimoniesSection}

***

##**Related Media**

${relatedMediaSection}
            `;

            if (outputCode) {
                let finalOutput = output.trim();

                // Apply auto-linking if enabled and available
                const autoLinkCheckbox = document.getElementById('autoLinkPrograms');
                if (autoLinkCheckbox && autoLinkCheckbox.checked &&
                    window.ttiAutoLinker && window.ttiAutoLinker.loaded) {
                    console.log('Applying auto-linking to generated wiki entry...');
                    finalOutput = window.ttiAutoLinker.autoLink(finalOutput, {
                        currentProgramName: programName,
                        linkCurrentProgram: false  // Don't link the program to itself
                    });
                }

                // Append any unparsed content from import at the end
                if (window.unparsedContentFromImport && window.unparsedContentFromImport.trim()) {
                    finalOutput += '\n\n***\n\n' + window.unparsedContentFromImport;
                    console.log('✓ Appended unparsed content to end of output');
                }

                outputCode.value = finalOutput;
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
            renderList(punishments, 'punishmentListOutput', item => `<strong>${escapeHtml(item.name)}</strong>`);
        }

        if (rules.length > 0) {
            renderList(rules, 'ruleListOutput', item => escapeHtml(item));
            console.log(`✓ Loaded ${rules.length} rules`);
        }

        if (lawsuits.length > 0) {
            renderList(lawsuits, 'lawsuitListOutput', item => `<strong>${escapeHtml(item.year)}: ${escapeHtml(item.plaintiff)}</strong>`);
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
});
