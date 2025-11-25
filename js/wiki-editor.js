document.addEventListener('DOMContentLoaded', () => {
    const wikiForm = document.getElementById('wikiForm');

    if (!wikiForm) {
        console.warn('Wiki Editor: #wikiForm not found, skipping initialization.');
        return;
    }

    // --- Data Storage ---
    let staffMembers = [];
    let programLevels = [];
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

    const staffNameInput = document.getElementById('staffName');
    const staffRoleInput = document.getElementById('staffRole');
    const staffBioInput = document.getElementById('staffBio');
    const staffPreviousRolesInput = document.getElementById('staffPreviousRoles');
    const staffIsFormerInput = document.getElementById('staffIsFormer');
    const addStaffBtn = document.getElementById('addStaffBtn');
    let editingStaffIndex = null;

    // --- Initialize empty list output containers ---
    const initializeEmptyLists = () => {
        const emptyHtml = '<p style="color:#999;">No items added yet</p>';
        const listIds = ['staffListOutput', 'levelListOutput', 'punishmentListOutput', 'lawsuitListOutput', 'articleListOutput', 'testimonyListOutput', 'mediaListOutput', 'campusListOutput', 'ownerChangeListOutput', 'ruleListOutput', 'allegationListOutput', 'therapyListOutput'];
        listIds.forEach(id => {
            const element = document.getElementById(id);
            if (element && !element.innerHTML.trim()) {
                element.innerHTML = emptyHtml;
            }
        });
    };
    initializeEmptyLists();

    function getStaffPreviousRolesFromInput() {
        if (!staffPreviousRolesInput || !staffPreviousRolesInput.value) {
            return [];
        }
        return staffPreviousRolesInput.value
            .split(/\r?\n|,/)
            .map(role => role.trim())
            .filter(Boolean);
    }

    function resetStaffForm() {
        if (staffNameInput) staffNameInput.value = '';
        if (staffRoleInput) staffRoleInput.value = '';
        if (staffBioInput) staffBioInput.value = '';
        if (staffPreviousRolesInput) staffPreviousRolesInput.value = '';
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
        if (staffPreviousRolesInput) staffPreviousRolesInput.value = (member.previousRoles || []).join('\n');
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
                prev.textContent = `Prev: ${member.previousRoles.join('; ')}`;
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
        return `No information is known about ${category} at ${name}. If you attended ${name} and would like to contribute information to help complete this page, please contact u/Signal-Strain8910.`;
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
                renderList(programLevels, 'levelListOutput', item => `<strong>${escapeHtml(item.name)}</strong>${item.duration ? ` (${escapeHtml(item.duration)})` : ''}`);
                clearInputs(['levelName', 'levelDuration', 'levelPrivileges', 'levelRestrictions']);
            } else {
                alert('Please enter at least a level name.');
            }
        });
    }

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
            const newOwner = document.getElementById('ownerChangeNew')?.value.trim() || '';
            if (year && (previous || newOwner)) {
                ownershipChanges.push({ year, previous, newOwner });
                renderList(ownershipChanges, 'ownerChangeListOutput', item => `<strong>${escapeHtml(item.year)}</strong>: ${escapeHtml(item.previous || '?')} → ${escapeHtml(item.newOwner || '?')}`);
                clearInputs(['ownerChangeYear', 'ownerChangePrevious', 'ownerChangeNew']);
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
            const source = document.getElementById('testimonySource').value.trim();
            const url = sanitizeUrl(document.getElementById('testimonyUrl').value);
            if (quote && source && url) {
                testimonies.push({ date, type, quote, source, url });
                renderList(testimonies, 'testimonyListOutput', item => `<strong>(${escapeHtml(item.type)})</strong> ${escapeHtml(item.quote.substring(0, 30))}... [${escapeHtml(item.source)}]`);
                clearInputs(['testimonyDate', 'testimonyQuote', 'testimonySource', 'testimonyUrl']);
            } else {
                alert('Please enter at least a quote, source name, and source URL.');
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
                    if (change.previous && change.newOwner) {
                        historySentences.push(`In ${escapeMarkdown(change.year)}, the program changed ownership from ${escapeMarkdown(change.previous)} to ${escapeMarkdown(change.newOwner)}.`);
                    } else if (change.newOwner) {
                        historySentences.push(`In ${escapeMarkdown(change.year)}, the program was acquired by ${escapeMarkdown(change.newOwner)}.`);
                    } else if (change.previous) {
                        historySentences.push(`In ${escapeMarkdown(change.year)}, ${escapeMarkdown(change.previous)} divested from the program.`);
                    }
                });
            }

            historySection = historySentences.length > 0 ? historySentences.join('\n\n') : getPlaceholder('History and Background Information', programName);

            // --- Build Staff Section ---
            let staffSection;
            if (staffMembers.length > 0) {
                staffSection = staffMembers.map(s => {
                    const roleText = escapeMarkdown(s.role);
                    const articleRole = roleText.toLowerCase().startsWith('the ') ? roleText : `the ${roleText}`;
                    const descriptor = s.isFormer
                        ? articleRole.replace(/^the\s+/i, 'the former ')
                        : articleRole;
                    const roleSentence = ensureSentence(`**${escapeMarkdown(s.name)}** ${roleVerb(s)} ${descriptor}`);
                    const previousSentence = (s.previousRoles && s.previousRoles.length)
                        ? ensureSentence(`Previously worked at ${joinWithAnd(s.previousRoles.map(pr => escapeMarkdown(pr)))}`)
                        : '';
                    const bioSentence = ensureSentence(escapeMarkdown(s.bio || ''));
                    return [roleSentence, previousSentence, bioSentence].filter(Boolean).join(' ');
                }).join('\n\n');
            } else {
                staffSection = getPlaceholder('Founders and Notable Staff', programName);
            }

            // --- Build Structure Section ---
            let structureSection = '';
            const structureParts = [];

            // Build level system description from dropdown selections
            const levelSystemTypes = { level: 'level system', phase: 'phase system', point: 'point system', tier: 'tier system' };
            if (vals.levelSystemType && vals.levelCount) {
                structureParts.push(`Like other behavior-modification programs, ${escapeMarkdown(programName)} uses a ${levelSystemTypes[vals.levelSystemType] || vals.levelSystemType} consisting of ${escapeMarkdown(vals.levelCount)} ${vals.levelSystemType === 'phase' ? 'phases' : 'levels'}.`);
            } else if (vals.levelSystemType) {
                structureParts.push(`Like other behavior-modification programs, ${escapeMarkdown(programName)} uses a ${levelSystemTypes[vals.levelSystemType] || vals.levelSystemType}.`);
            }

            // Build level descriptions from structured data
            if (programLevels.length > 0) {
                const levelDescriptions = programLevels.map(l => {
                    let desc = `**${escapeMarkdown(l.name)}**`;
                    if (l.duration) desc += ` (typically ${escapeMarkdown(l.duration)})`;
                    desc += ':';
                    const details = [];
                    if (l.restrictions) {
                        const restrictionList = l.restrictions.split(',').map(r => r.trim()).filter(Boolean);
                        if (restrictionList.length > 0) {
                            details.push(`Restrictions include ${joinWithAnd(restrictionList.map(r => escapeMarkdown(r)))}.`);
                        }
                    }
                    if (l.privileges) {
                        const privilegeList = l.privileges.split(',').map(p => p.trim()).filter(Boolean);
                        if (privilegeList.length > 0) {
                            details.push(`Privileges earned include ${joinWithAnd(privilegeList.map(p => escapeMarkdown(p)))}.`);
                        }
                    }
                    return desc + (details.length ? ' ' + details.join(' ') : ' No specific details available.');
                }).join('\n\n');
                structureParts.push(levelDescriptions);
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

            structureSection = structureParts.length > 0 ? structureParts.join('\n\n') : getPlaceholder('Program Structure', programName);

            // --- Build Rules & Punishments Section ---
            let rulesSection = '';
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

            // --- Build Abuse Section ---
            let abuseSection = '';
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

            // --- Build Media Section (Combined) ---
            const mediaList = processSimpleList(vals.mediaInfo).replace(/^\*/gm, '-');
            const newsList = newsArticles.map(a => {
                let sourceDate = [a.source, a.date].filter(Boolean).join(', ');
                if (sourceDate) sourceDate = ` (${sourceDate})`;
                const safeUrl = sanitizeUrl(a.url);
                const linkText = safeUrl ? `[${escapeMarkdown(a.title)}](${safeUrl})` : escapeMarkdown(a.title);
                return `- ${linkText}${sourceDate}`;
            }).join('\n');
            const combinedMedia = [mediaList, newsList].filter(Boolean).join('\n\n');
            const mediaSection = combinedMedia || getPlaceholder('In the Media', programName);

            // --- Build Testimonies Section ---
            let testimoniesSection;
            if (testimonies.length > 0) {
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
            if (relatedMedia.length > 0) {
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
                outputCode.value = output.trim();
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
    function parseAndPopulate(markdown) {
        console.log('parseAndPopulate called');
        staffMembers = [];
        programLevels = [];
        punishments = [];
        lawsuits = [];
        newsArticles = [];
        testimonies = [];
        relatedMedia = [];
        resetStaffForm();
        renderStaffList();

        // Normalize newlines so regex parsing works with Windows CRLF input
        const normalizedMarkdown = markdown.replace(/\r\n/g, '\n').replace(/\r/g, '\n');

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

        const escapeRegex = (str) => str.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');

        const getSection = (source, sectionTitle) => {
            // Simple approach: find ##**Title** and capture until next ## or end
            const escapedTitle = escapeRegex(sectionTitle);

            // Build pattern to match section header with various formats:
            // ##**Title**, ## **Title**, ##Title, etc.
            const headerPattern = `##\\s*\\*{0,2}\\s*${escapedTitle}\\s*\\*{0,2}\\s*`;

            // Capture everything after header until next ## section or end of string
            const regex = new RegExp( // Look for header, then capture until the next header OR a section break (***) OR end of string
                headerPattern + '\\n([\\s\\S]*?)(?=\\n##|\\n\\*\\*\\*|$)',
                'i'
            );

            const match = source.match(regex);
            let result = match ? match[1].trim() : '';

            // Remove trailing *** separator if present
            result = result.replace(/^\*\*\*|(?:\r\n|\n|\r)?\*\*\*$/g, '').trim();

            console.log(`getSection("${sectionTitle}"):`, result ? `Found (${result.length} chars)` : 'Not found');
            return result;
        };

        const getSectionAny = (source, titles) => {
            for (const title of titles) {
                const section = getSection(source, title);
                if (section) return section;
            }
            return '';
        };

        // Parse header
        const headerMatch = normalizedMarkdown.match(/^#{1,3}\s*\**(.+?)\**\s*\(([^)]+)\)\s+([^\r\n]+)/m);
        console.log('Header match:', headerMatch);
        if (headerMatch) {
            setValue('programName', headerMatch[1].trim());
            setValue('yearsActive', headerMatch[2].trim());
            setValue('cityState', headerMatch[3].trim());
        } else {
            console.warn('No header match found. Looking for pattern: ## ProgramName (Years) City, ST');
        }

        // Parse program type
        const typeMatch = normalizedMarkdown.match(/^\s*\*([^*]+)\*\s*$/m);
        if (typeMatch) {
            setValue('programType', typeMatch[1].trim());
        }

        // Parse History section
        const historySection = getSection(normalizedMarkdown, 'History and Background Information');
        if (historySection && !historySection.includes('No information is known')) {
            const normalizedHistory = historySection.replace(/[\u2013\u2014]/g, '-');

            const yearFoundedMatch = normalizedHistory.match(/founded in (\d{4})/i);
            if (yearFoundedMatch) {
                setValue('yearFounded', yearFoundedMatch[1].trim());
            }

            const assignOwner = (name, link) => {
                if (!name) return false;
                setValue('ownerName', name.trim());
                if (link) {
                    setValue('ownerLink', sanitizeUrl(link));
                }
                return true;
            };

            let ownerCaptured = false;
            const ownerPatternsWithLinks = [
                /owned by \[([^\]]+)\]\(([^)]+)\)/i,
                /operated by \[([^\]]+)\]\(([^)]+)\)/i,
                /run by \[([^\]]+)\]\(([^)]+)\)/i,
                /part of \[([^\]]+)\]\(([^)]+)\)/i,
                /was\s+(?:an?|the)?\s*\[([^\]]+)\]\(([^)]+)\)\s+(?:[^.\n]*?(?:program|school|facility|center))/i
            ];
            for (const pattern of ownerPatternsWithLinks) {
                const match = normalizedHistory.match(pattern);
                if (match && assignOwner(match[1], match[2])) {
                    ownerCaptured = true;
                    break;
                }
            }
            if (!ownerCaptured) {
                const ownerTextPatterns = [
                    /owned by ([^.\n]+)/i,
                    /operated by ([^.\n]+)/i,
                    /run by ([^.\n]+)/i,
                    /part of ([^.\n]+)/i,
                    /was\s+(?:an?|the)?\s*([^.\n]+?)\s+(?:behavior|residential|therapeutic|treatment)[^.\n]*program/i
                ];
                for (const pattern of ownerTextPatterns) {
                    const match = normalizedHistory.match(pattern);
                    if (match && assignOwner(match[1])) {
                        break;
                    }
                }
            }

            const agePatterns = [
                /aged?\s+(?:between\s+)?(\d{1,2})\s*(?:-|to)\s*(\d{1,2})/i,
                /ages?\s+(?:between\s+)?(\d{1,2})\s*(?:-|to)\s*(\d{1,2})/i,
                /age range\s+(?:of\s+)?(\d{1,2})\s*(?:-|to)\s*(\d{1,2})/i,
                /serves\s+[^.\n]*?ages?\s+(\d{1,2})\s*(?:-|to)\s*(\d{1,2})/i,
                /aged?\s+(\d{1,2})\s*\+/i
            ];
            for (const pattern of agePatterns) {
                const match = normalizedHistory.match(pattern);
                if (match) {
                    const normalizedRange = match[2]
                        ? `${match[1]}-${match[2]}`
                        : `${match[1]}+`;
                    setValue('ageRange', normalizedRange);
                    break;
                }
            }

            const diagnosisSnippets = [];
            const pushDiagnosis = (text) => {
                const cleaned = (text || '')
                    .replace(/["*_]/g, '')
                    .replace(/\s+/g, ' ')
                    .trim();
                if (cleaned) {
                    diagnosisSnippets.push(cleaned.replace(/\.$/, ''));
                }
            };
            const diagnosisPatterns = [
                /diagnoses\/behaviors:\s*([^\n]+)/i,
                /any of the following:\s*([^\.]+)\./i,
                /specializes? in treating\s+([^\.]+?)(?:\.|, but)/i,
                /specialized in treating\s+([^\.]+?)(?:\.|, but)/i,
                /treats?\s+(?:students|residents|clients|girls|boys|young people)[^:]*:\s*([^\.]+)\./i
            ];
            diagnosisPatterns.forEach(pattern => {
                const match = normalizedHistory.match(pattern);
                if (match && match[1]) {
                    pushDiagnosis(match[1]);
                }
            });
            if (diagnosisSnippets.length > 0) {
                const uniqueDiagnoses = Array.from(new Set(diagnosisSnippets));
                setValue('diagnosesList', uniqueDiagnoses.join('; '));
            }

            const stayMatch = normalizedHistory.match(/average length of stay[^0-9]*(\d+[^,\.\n]+)/i);
            if (stayMatch) {
                setValue('avgStay', stayMatch[1].trim());
            }

            const tuitionMatch = normalizedHistory.match(/tuition[^$]*(\$[^\.\n]+)/i);
            if (tuitionMatch) {
                setValue('tuition', tuitionMatch[1].trim());
            }

            const natsapMatch = normalizedHistory.match(/(NATSAP[^\.\n]+)/i);
            if (natsapMatch) {
                setValue('natsapStatus', natsapMatch[1].trim());
            }

            // Extract address (allow variations like "located at/as/in")
            const addressMatch = normalizedHistory.match(/located\s+(?:at|as|in)\s+\[([^\]]+)\]\(([^)]+)\)/i);
            if (addressMatch) {
                setValue('mainAddress', addressMatch[1]);
                setValue('addressLink', sanitizeUrl(addressMatch[2]));
            } else {
                const addressTextMatch = normalizedHistory.match(/located\s+(?:at|as|in)\s+([^\.\n]+)/i);
                if (addressTextMatch) {
                    setValue('mainAddress', addressTextMatch[1].trim());
                }
            }

            // Extract accrediting body
            const accreditMatch = normalizedHistory.match(/accredited through the \[([^\]]+)\]\(([^)]+)\)/i);
            if (accreditMatch) {
                setValue('accreditingBody', accreditMatch[1]);
                setValue('accreditingBodyLink', sanitizeUrl(accreditMatch[2]));
            }

            const historyParagraphs = historySection.split('\n\n')
                .map(line => line.trim())
                .filter(line => line && !line.includes('No information is known'));
            if (historyParagraphs.length > 0) {
                setValue('historyMisc', historyParagraphs.join('\n\n'));
            }
        }

        // Parse Staff section
        const staffSection = getSectionAny(normalizedMarkdown, [
            'Founders and Notable Staff',
            'Founders & Notable Staff',
            'Notable Staff',
            'Founders'
        ]);
        console.log('Staff section raw content:', staffSection ? `Found (${staffSection.length} chars)` : 'Not found');
        if (staffSection && !staffSection.includes('No information is known')) {
            const addStaff = (name, role, bio, previousRoles) => {
                if (!name || !role) return;
                const normalizedRole = role.trim();
                const isFormer = /\b(former|previous|ex[\s-])/i.test(normalizedRole.toLowerCase());
                staffMembers.push({
                    name: name.trim(),
                    role: normalizedRole,
                    bio: (bio || '').trim(),
                    previousRoles: previousRoles || [],
                    isFormer
                });
            };

            const cleanStaffBlock = (text) => text
                // Only strip bullet markers when they are followed by whitespace,
                // so regular bold names like **Name** stay intact.
                .replace(/^\s*[-*]\s+(?=\S)/, '')
                .replace(/\s+/g, ' ')
                .trim();

            // Split by blank lines to get individual staff entries
            const staffParagraphs = staffSection.split(/\n\n+/).filter(p => {
                const trimmed = p.trim();
                return trimmed.startsWith('**');
            });
            console.log(`Staff split into ${staffParagraphs.length} paragraphs starting with **`);

            staffParagraphs.forEach((block, idx) => {
                const normalized = cleanStaffBlock(block);
                console.log(`Staff paragraph ${idx + 1}:`, normalized.substring(0, 100));

                // Extract name from **Name** at the start
                const nameMatch = normalized.match(/^\*\*([^*]+)\*\*/);
                if (!nameMatch) {
                    console.warn(`  → No name match for paragraph ${idx + 1}`);
                    return;
                }

                const name = nameMatch[1].trim();
                console.log(`  → Found name: "${name}"`);

                // Extract role from first sentence - be flexible with verb phrases
                // Patterns: "is the X", "was the X", "currently works as", "works as", "served as"
                let roleMatch = normalized.match(/^\*\*[^*]+\*\*\s+(?:was|is)\s+(?:the|a|an)?\s+([^\.]+)\./i);

                let role = '';
                if (roleMatch) {
                    role = roleMatch[1].trim();
                    console.log(`  → Role (pattern match): "${role}"`);
                } else {
                    // Fallback: try to extract anything after the name until first period
                    const fallbackMatch = normalized.match(/^\*\*[^*]+\*\*\s+([^\.]+)\./);
                    if (fallbackMatch) {
                        role = fallbackMatch[1].trim();
                        console.log(`  → Role (fallback): "${role}"`);
                    } else {
                        // If still no match, use a default role but still add the person
                        console.warn(`  → Could not extract role for: ${name}, using default "Staff Member"`);
                        role = 'Staff Member';
                    }
                }

                // Extract previous roles - look for work history patterns
                const previousRoles = [];

                // Pattern: "Previously, X worked as Y at Z"
                const prevMatches = normalized.matchAll(/(?:Previously|She previously|He previously),?\s+\w+\s+worked\s+as\s+(?:a|an|the)?\s*([^\.]+?)\s+at\s+([^\.]+?)(?:\s+from\s+[^\.]+)?(?:\s+in\s+\d{4})?[\.]/gi);
                for (const match of prevMatches) {
                    previousRoles.push(`${match[1].trim()} at ${match[2].trim()}`);
                }

                // Pattern: "She/He then worked as Y at Z"
                const thenMatches = normalized.matchAll(/(?:She|He)\s+then\s+worked\s+as\s+(?:a|an|the)?\s*([^\.]+?)\s+at\s+([^\.]+?)(?:\s+from\s+[^\.]+)?[\.]/gi);
                for (const match of thenMatches) {
                    previousRoles.push(`${match[1].trim()} at ${match[2].trim()}`);
                }

                // Pattern: "After this, X worked as Y at Z"
                const afterMatches = normalized.matchAll(/After\s+this,\s+\w+\s+worked\s+as\s+(?:a|an|the)?\s*([^\.]+?)\s+at\s+([^\.]+?)(?:\s+from\s+[^\.]+)?[\.]/gi);
                for (const match of afterMatches) {
                    previousRoles.push(`${match[1].trim()} at ${match[2].trim()}`);
                }

                // Pattern: "began her/his career...as Y at Z"
                const beganMatches = normalized.matchAll(/(?:began|started)\s+(?:her|his)\s+career[^\.]*?\s+as\s+(?:a|an|the)?\s*([^\.]+?)\s+at\s+([^\.]+?)(?:\s+from\s+[^\.]+)?[\.]/gi);
                for (const match of beganMatches) {
                    previousRoles.push(`${match[1].trim()} at ${match[2].trim()}`);
                }

                // Pattern: "Before founding X, Y worked as Z"
                const beforeMatches = normalized.matchAll(/Before\s+(?:founding|starting)\s+[^,]+,\s+\w+\s+worked\s+as\s+(?:a|an|the)?\s*([^\.]+?)(?:\s+at\s+([^\.]+?))?[\.]/gi);
                for (const match of beforeMatches) {
                    if (match[2]) {
                        previousRoles.push(`${match[1].trim()} at ${match[2].trim()}`);
                    } else {
                        previousRoles.push(match[1].trim());
                    }
                }

                // Extract bio - everything after first sentence, excluding work history sentences
                const firstSentenceEnd = normalized.indexOf('.') + 1;
                const remainingText = normalized.substring(firstSentenceEnd).trim();
                const sentences = remainingText.split(/\.\s+/);
                const bioSentences = sentences.filter(s => {
                    const trimmed = s.trim();
                    if (trimmed.length === 0) return false;
                    // Exclude sentences that are about work history
                    if (/(?:worked|began|started|served)\s+(?:as|at|her|his)/i.test(trimmed)) return false;
                    if (/(?:Previously|She|He)\s+(?:then|previously)/i.test(trimmed)) return false;
                    if (/After\s+this/i.test(trimmed)) return false;
                    if (/Before\s+(?:founding|starting)/i.test(trimmed)) return false;
                    return true;
                });
                const bio = bioSentences.join('. ').trim();

                addStaff(name, role, bio, previousRoles);
            });

            renderStaffList();
            console.log(`✓ Parsed ${staffMembers.length} staff members`);
        } else {
            renderStaffList();
        }

        // Parse Structure section
        const structureSection = getSectionAny(normalizedMarkdown, [
            'Program Structure',
            'Structure',
            'Program Model',
            'Level System',
            'Level Systems',
            'Phase System',
            'Phases',
            'Program Phases'
        ]);
        if (structureSection && !structureSection.includes('No information is known')) {
            // Extract level system description
            const levelDescMatch = structureSection.match(/(?:uses|used|utilizes|utilized|implements|implemented)\s+([^\.]*level[^\.]+)/i);
            if (levelDescMatch) {
                setValue('levelSystemDesc', levelDescMatch[1].trim());
            }

            // Extract individual levels - handle multiple formats
            // Format 1: * **"Name"** - Description (generated format)
            // Format 2: - Name — Description (with em dash)
            // Format 3: - Name (simple format)
            // Format 4: * Name (simple format)

            const lines = structureSection.split('\n');
            lines.forEach(line => {
                const trimmed = line.trim();

                // Format 0a: **Name:** Description (bold name with colon)
                // Examples: **Green:** Description, **Phase 1:** Description
                let match = trimmed.match(/^\*\*([^:*]+):\*\*\s*(.*)$/);
                if (match) {
                    programLevels.push({
                        name: match[1].trim(),
                        desc: match[2].trim()
                    });
                    return;
                }

                // Format 0b: **Name** (bold name without colon - just the level name)
                // Examples: **Green**, **Phase 1**, **Yellow**
                match = trimmed.match(/^\*\*([^*]+)\*\*$/);
                if (match) {
                    const name = match[1].trim();
                    // Only add if it looks like a level name (short, starts with capital or number)
                    if (name.length < 50 && /^[A-Z0-9]/.test(name)) {
                        programLevels.push({
                            name: name,
                            desc: ''
                        });
                    }
                    return;
                }

                // Format 1: * **"Name"** - Description or - **"Name"** — Description
                match = trimmed.match(/^[*-]\s+\*\*"([^"]+)"\*\*\s*[—\-]\s*(.+)$/);
                if (match) {
                    programLevels.push({
                        name: match[1].trim(),
                        desc: match[2].trim()
                    });
                    return;
                }

                // Format 2: - Name — Description or - Name - Description
                match = trimmed.match(/^[*-]\s+([^—\-]+?)\s*[—\-]\s*(.+)$/);
                if (match && !match[1].includes('*')) {
                    programLevels.push({
                        name: match[1].trim(),
                        desc: match[2].trim()
                    });
                    return;
                }

                // Format 3: - Name or * Name (simple, no description)
                match = trimmed.match(/^[*-]\s+([^—\-\n]+)$/);
                if (match && !match[1].includes('*') && match[1].trim().length > 0) {
                    // Only add if it looks like a level name (short, capitalized)
                    const name = match[1].trim();
                    if (name.length < 50 && /^[A-Z]/.test(name)) {
                        programLevels.push({
                            name: name,
                            desc: ''
                        });
                    }
                }
            });

            renderList(programLevels, 'levelListOutput', item => `<strong>"${escapeHtml(item.name)}"</strong>`);
            console.log(`✓ Parsed ${programLevels.length} program levels`);

            // Extract misc structure info
            const miscStructureParts = structureSection.split('\n\n')
                .map(block => block.split('\n')
                    .filter(line => !line.trim().match(/^[*-]\s+/))
                    .join('\n')
                    .trim())
                .filter(block => block && !block.includes('No information is known'));
            if (miscStructureParts.length > 0) {
                setValue('structureMisc', miscStructureParts.join('\n\n'));
            }
        }

        // Parse Rules section
        const rulesSection = getSectionAny(normalizedMarkdown, [
            'Rules and Punishments',
            'Rules & Punishments',
            'Rules/Punishments',
            'Punishments'
        ]);
        if (rulesSection && !rulesSection.includes('No information is known')) {
            // Extract rules list - handle both * and - bullets
            const rulesMatch = rulesSection.match(/Some of these rules include:\s*\n((?:[*-][^\n]+\n?)+)/i);
            if (rulesMatch) {
                const rulesList = rulesMatch[1]
                    .split('\n')
                    .filter(line => {
                        const trimmed = line.trim();
                        return trimmed.startsWith('*') || trimmed.startsWith('-');
                    })
                    .map(line => line.replace(/^[*-]\s*/, '').trim())
                    .filter(Boolean)
                    .join('\n');
                setValue('rulesList', rulesList);
            }

            // Extract structured punishments - format: **Name**: Description
            const punishmentBlocks = rulesSection.match(/\*\*([^*:]+):\*\*\s*([^\n]+)/g);
            if (punishmentBlocks) {
                punishmentBlocks.forEach(block => {
                    const match = block.match(/\*\*([^*:]+):\*\*\s*(.+)/);
                    if (match) {
                        punishments.push({
                            name: match[1].trim(),
                            desc: match[2].trim()
                        });
                    }
                });
                renderList(punishments, 'punishmentListOutput', item => `<strong>${escapeHtml(item.name)}</strong>`);
            }

            // Extract misc punishment text (paragraphs that don't match structured format)
            const punishmentLines = rulesSection.split('\n\n').filter(para => {
                const trimmed = para.trim();
                return !para.includes('Some of these rules include') &&
                       !para.includes('uses various punishments') &&
                       !trimmed.match(/^\*\*[^*:]+:\*\*/) &&
                       !trimmed.startsWith('*') &&
                       !trimmed.startsWith('-') &&
                       trimmed.length > 0;
            });
            if (punishmentLines.length > 0) {
                setValue('punishmentsMisc', punishmentLines.join('\n\n').trim());
            }
        }

        // Parse Abuse section
        const abuseSection = getSectionAny(normalizedMarkdown, [
            'Abuse/Neglect Allegations and Lawsuits',
            'Abuse Allegations',
            'Abuse/Neglect Allegations',
            'Allegations and Lawsuits'
        ]);
        if (abuseSection && !abuseSection.includes('No information is known')) {
            // Extract main complaints
            const complaintsMatch = abuseSection.match(/main complaints are of ([^\.]+)/i);
            if (complaintsMatch) {
                setValue('mainComplaints', complaintsMatch[1].trim());
            }

            // Extract other allegations list - handle both * and - bullets
            const allegationsMatch = abuseSection.match(/reported by survivors included:\s*\n((?:[*-][^\n]+\n?)+)/i);
            if (allegationsMatch) {
                const allegationsList = allegationsMatch[1]
                    .split('\n')
                    .filter(line => {
                        const trimmed = line.trim();
                        return trimmed.startsWith('*') || trimmed.startsWith('-');
                    })
                    .map(line => line.replace(/^[*-]\s*/, '').trim())
                    .filter(Boolean)
                    .join('\n');
                setValue('otherAllegationsList', allegationsList);
            }

            // Extract structured lawsuits
            // Pattern: "In YEAR, PLAINTIFF sued PROGRAM alleging ALLEGATIONS. The lawsuit OUTCOME. DETAILS"
            const lawsuitParagraphs = abuseSection.split('\n\n');
            lawsuitParagraphs.forEach(para => {
                const trimmed = para.trim();

                // Look for lawsuit pattern
                const lawsuitMatch = trimmed.match(/In (\d{4}),\s*([^s]+?)\s+sued[^a]*alleging\s+([^\.]+)\./i);
                if (lawsuitMatch) {
                    const year = lawsuitMatch[1].trim();
                    const plaintiff = lawsuitMatch[2].trim();
                    const allegations = lawsuitMatch[3].trim();

                    // Try to extract outcome
                    let outcome = '';
                    const outcomeMatch = trimmed.match(/The lawsuit ([^\.]+)\./i);
                    if (outcomeMatch) {
                        outcome = outcomeMatch[1].trim();
                    }

                    // Extract remaining details (everything after outcome or allegations)
                    let details = '';
                    if (outcomeMatch) {
                        const afterOutcome = trimmed.substring(trimmed.indexOf(outcomeMatch[0]) + outcomeMatch[0].length).trim();
                        details = afterOutcome;
                    } else {
                        const afterAllegations = trimmed.substring(trimmed.indexOf(lawsuitMatch[0]) + lawsuitMatch[0].length).trim();
                        details = afterAllegations;
                    }

                    lawsuits.push({ year, plaintiff, allegations, outcome, details });
                }
            });

            if (lawsuits.length > 0) {
                renderList(lawsuits, 'lawsuitListOutput', item => `<strong>${escapeHtml(item.year)}: ${escapeHtml(item.plaintiff)}</strong>`);
            }

            // Extract misc lawsuit text (paragraphs that don't match structured format)
            const lawsuitLines = abuseSection.split('\n\n').filter(para => {
                const trimmed = para.trim();
                return !para.includes('main complaints are') &&
                       !para.includes('Other allegations') &&
                       !trimmed.match(/^In \d{4},/) &&
                       !trimmed.startsWith('*') &&
                       !trimmed.startsWith('-') &&
                       trimmed.length > 0;
            });
            if (lawsuitLines.length > 0) {
                setValue('lawsuitsMisc', lawsuitLines.join('\n\n').trim());
            }
        }

        // Parse Media section
        const mediaSection = getSectionAny(normalizedMarkdown, [
            'In the Media',
            'Media',
            'In the Media & News',
            'In the Media and News'
        ]);
        if (mediaSection && !mediaSection.includes('No information is known')) {
            const lines = mediaSection.split('\n');
            const mediaLines = [];

            lines.forEach(line => {
                const trimmed = line.trim();
                if (!trimmed) return;

                // Pattern 1: * [Article Title](url) (Source, Date)
                // Pattern 2: - [Article Title](url) (Source, Date)
                let match = trimmed.match(/^[*-]\s+\[([^\]]+)\]\(([^)]+)\)\s*(?:\(([^)]+)\))?/);
                if (match) {
                    const title = match[1].trim();
                    const url = sanitizeUrl(match[2].trim());
                    const sourceDate = match[3] || '';

                    // Try to split source and date
                    let source = '';
                    let date = '';
                    if (sourceDate) {
                        const parts = sourceDate.split(',').map(p => p.trim());
                        if (parts.length === 2) {
                            source = parts[0];
                            date = parts[1];
                        } else if (parts.length === 1) {
                            // Could be either source or date - try to detect
                            if (/\d{1,2}\/\d{1,2}\/\d{2,4}|\d{4}|(?:Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)/i.test(parts[0])) {
                                date = parts[0];
                            } else {
                                source = parts[0];
                            }
                        }
                    }

                    newsArticles.push({ title, url, source, date });
                    return;
                }

                // Pattern 3: * General media text (not a link)
                if (trimmed.startsWith('*') || trimmed.startsWith('-')) {
                    const text = trimmed.replace(/^[*-]\s*/, '').trim();
                    if (text && !text.startsWith('[')) {
                        mediaLines.push(text);
                    }
                }
            });

            if (mediaLines.length > 0) {
                setValue('mediaInfo', mediaLines.join('\n'));
            }

            renderList(newsArticles, 'articleListOutput', item => `<strong>${escapeHtml(item.title)}</strong> (${escapeHtml(item.source || 'No Source')})`);
        }

        // Parse Testimonies section
        const testimoniesSection = getSectionAny(normalizedMarkdown, [
            'Survivor Testimonies',
            'Survivor/Parent Testimonials',
            'Survivor/Parent Testimonies',
            'Survivor Testimonials',
            'Survivor and Parent Testimonials'
        ]);
        console.log('Testimonies section:', testimoniesSection ? `Found (${testimoniesSection.length} chars)` : 'Not found');
        if (testimoniesSection && !testimoniesSection.includes('No information is known')) {
            // Split by double newlines or single newlines with bold markers
            // Split by double newlines. This is more robust for varying formats.
            const blocks = testimoniesSection.split(/\n\n+/);
            console.log(`Testimonies split into ${blocks.length} blocks`);

            blocks.forEach((block, idx) => {
                const trimmed = block.trim();
                if (!trimmed || trimmed.length === 0) return;

                const cleaned = trimmed
                    // Drop bullet markers only if followed by whitespace
                    .replace(/^\s*[-*]\s+(?=\S)/, '')
                    .replace(/\n+/g, ' ') // flatten multiline entries
                    // Normalize quotes and dashes
                    .replace(/[""]/g, '"') // smart quotes to straight quotes
                    .replace(/[–—]/g, '-') // en-dash and em-dash to hyphen
                    .trim();

                console.log(`Testimony block ${idx + 1}:`, cleaned.substring(0, 80));

                // Pattern 1: **Date: (TYPE)** "quote" - [Source](url)
                let match = cleaned.match(/^\*\*(.*?):\s*\(([^)]+)\)\*\*\s+"([^"]+)"\s*-\s*\[([^\]]+)\]\(([^)]+)\)/i);
                if (match) {
                    console.log(`  → Matched pattern 1 (Date+Type)`);
                    testimonies.push({
                        date: match[1].trim(),
                        type: match[2].trim().toUpperCase(),
                        quote: match[3].trim(),
                        source: match[4].trim(),
                        url: sanitizeUrl(match[5].trim())
                    });
                    return;
                }

                // Pattern 2: **(TYPE)** "quote" - [Source](url)
                match = cleaned.match(/^\*\*\(([^)]+)\)\*\*\s+"([^"]+)"\s*-\s*\[([^\]]+)\]\(([^)]+)\)/i);
                if (match) {
                    console.log(`  → Matched pattern 2 (Type only)`);
                    testimonies.push({
                        date: '',
                        type: match[1].trim().toUpperCase(),
                        quote: match[2].trim(),
                        source: match[3].trim(),
                        url: sanitizeUrl(match[4].trim())
                    });
                    return;
                }

                // Pattern 1b: **Date: (TYPE)** "quote" - Source (no link)
                match = cleaned.match(/^\*\*(.*?):\s*\(([^)]+)\)\*\*\s+"([^"]+)"\s*-\s*([^\(\[]+)$/i);
                if (match) {
                    console.log('  ✓ Matched pattern 1b (Date+Type, no link)');
                    testimonies.push({
                        date: match[1].trim(),
                        type: match[2].trim().toUpperCase(),
                        quote: match[3].trim(),
                        source: match[4].trim(),
                        url: ''
                    });
                    return;
                }

                // Pattern 3: Date: (TYPE) "quote" - [Source](url) (no bold)
                match = cleaned.match(/^(.*?):\s*\(([^)]+)\)\s+"([^"]+)"\s*-\s*\[([^\]]+)\]\(([^)]+)\)/i);
                if (match) {
                    console.log(`  → Matched pattern 3 (Date+Type, no bold)`);
                    testimonies.push({
                        date: match[1].trim(),
                        type: match[2].trim().toUpperCase(),
                        quote: match[3].trim(),
                        source: match[4].trim(),
                        url: sanitizeUrl(match[5].trim())
                    });
                    return;
                }

                // Pattern 4: (TYPE) "quote" - [Source](url) (no date, no bold)
                match = cleaned.match(/^\(([^)]+)\)\s+"([^"]+)"\s*-\s*\[([^\]]+)\]\(([^)]+)\)/i);
                if (match) {
                    console.log(`  → Matched pattern 4 (Type only, no bold)`);
                    testimonies.push({
                        date: '',
                        type: match[1].trim().toUpperCase(),
                        quote: match[2].trim(),
                        source: match[3].trim(),
                        url: sanitizeUrl(match[4].trim())
                    });
                    return;
                }

                console.warn(`  → No pattern matched for block ${idx + 1}`);
            });

            renderList(testimonies, 'testimonyListOutput', item => {
                const dateStr = item.date ? `${escapeHtml(item.date)}: ` : '';
                return `<strong>${dateStr}(${escapeHtml(item.type)})</strong> ${escapeHtml(item.quote.substring(0, 30))}... [${escapeHtml(item.source)}]`;
            });
            console.log(`✓ Parsed ${testimonies.length} testimonies`);
        }

        // Parse Related Media section
        const relatedMediaSection = getSection(normalizedMarkdown, 'Related Media');
        console.log('Related Media section:', relatedMediaSection ? `Found (${relatedMediaSection.length} chars)` : 'Not found');
        if (relatedMediaSection && !relatedMediaSection.includes('No information is known')) {
            const lines = relatedMediaSection.split('\n');

            lines.forEach(line => {
                const trimmed = line.trim();
                if (!trimmed) return;

                // Pattern 1: * [Title](url) or - [Title](url)
                let match = trimmed.match(/^[*-]\s+\[([^\]]+)\]\(([^)]+)\)/);
                if (match) {
                    relatedMedia.push({
                        title: match[1].trim(),
                        url: sanitizeUrl(match[2].trim())
                    });
                    return;
                }

                // Pattern 2: [Title](url) without bullet
                match = trimmed.match(/^\[([^\]]+)\]\(([^)]+)\)/);
                if (match) {
                    relatedMedia.push({
                        title: match[1].trim(),
                        url: sanitizeUrl(match[2].trim())
                    });
                }
            });

            renderList(relatedMedia, 'mediaListOutput', item => `[${escapeHtml(item.title)}]`);
            console.log(`✓ Parsed ${relatedMedia.length} related media items`);
        }
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
        return String(text)
            .replace(/\\/g, '\\\\')
            .replace(/\*/g, '\\*')
            .replace(/_/g, '\\_')
            .replace(/\[/g, '\\[')
            .replace(/\]/g, '\\]')
            .replace(/`/g, '\\`');
    }
});
