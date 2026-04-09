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
    const generateBtn = document.getElementById('generateBtn');
    const outputCode = document.getElementById('outputCode');
    const entriesList = document.getElementById('entriesList');
    const entrySearch = document.getElementById('entrySearch');
    const pageInfo = document.getElementById('pageInfo');
    const prevPageBtn = document.getElementById('prevPageBtn');
    const nextPageBtn = document.getElementById('nextPageBtn');
    const backBtn = document.getElementById('backBtn');

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
    let affiliations = [];
    let relatedPrograms = [];
    let importedMarkdown = ''; // Store original imported markdown
    let isOrganizationEntry = false; // Track if current entry is an organization
    let currentPage = 1;
    let totalEntries = 0;
    const entriesPerPage = 25;
    let currentNavType = '';
    let currentNavList = [];
    let currentNavIndex = -1;

    // --- Empty-slug mapping (loaded from markdown_output) ---
    // Set of known-empty wiki slugs (derived from markdown_output/empty_files_updated.md)
    let emptySlugSet = null;

    // --- Tab Switching Logic ---
    const categoryTabs = document.querySelectorAll('.category-tab');
    const categoryContents = document.querySelectorAll('.category-content');

    if (categoryTabs.length > 0) {
        categoryTabs.forEach(tab => {
            tab.addEventListener('click', () => {
                // Remove active class from all tabs
                categoryTabs.forEach(t => t.classList.remove('active'));
                // Add active class to clicked tab
                tab.classList.add('active');

                // Hide all content
                categoryContents.forEach(content => content.classList.add('view-hidden'));

                // Show target content
                const category = tab.getAttribute('data-category');
                const targetId = category + '-content';
                const targetContent = document.getElementById(targetId);
                if (targetContent) {
                    targetContent.classList.remove('view-hidden');
                }

                // Update the state or reset results message if switching
                // Clear the list if switching between types, to avoid confusion
                // But only if no selection is made yet? 
                // Let's just keep the facilities pane as is until a new selection is made.
            });
        });

        // Initialize first tab
        const firstTab = categoryTabs[0];
        if (firstTab) {
            setTimeout(() => firstTab.click(), 50);
        }
    }

    async function loadEmptySlugMapping() {
        try {
            const baseUrl = editorSettings.markdownBaseUrl || '/wp-content/themes/child/markdown_output/';
            const resp = await fetch(`${baseUrl}empty_files_updated.md`, { cache: 'no-cache' });
                if (resp.ok && resp.status === 200) {
                const text = await resp.text();
                emptySlugSet = new Set();
                text.split(/\r?\n/).forEach(line => {
                    const trimmed = line.trim();
                    if (!trimmed) return;
                    // Lines are formatted like "- filename.md"
                    const entry = trimmed.replace(/^[-\s]+/, '');
                    const name = entry.split('/').pop();
                    if (!name) return;
                    // We care about index_*.md files which map to wiki slugs
                    if (name.startsWith('index_')) {
                        let slug = name.replace(/^index_/, '').replace(/\.md$/i, '');
                        // Remove trailing underscores
                        slug = slug.replace(/_$/, '');
                        if (slug) emptySlugSet.add(slug.toLowerCase());
                    } else if (/^[a-z0-9-]+\.md$/i.test(name)) {
                        // Fallback: consider plain slug.md files as slugs
                        const slug = name.replace(/\.md$/i, '');
                        emptySlugSet.add(slug.toLowerCase());
                    }
                });
                console.log('Loaded empty slug list (' + (emptySlugSet.size || 0) + ' slugs)');
            } else {
                emptySlugSet = new Set();
                console.warn('Empty files list not found (HTTP ' + resp.status + ')');
            }
        } catch (err) {
            emptySlugSet = new Set();
            console.warn('Failed to load empty files list:', err);
        }
    }
    // Load mapping (no await so UI init continues)
    loadEmptySlugMapping();

    const emptyBannerEl = document.getElementById('emptyEntryBanner');
    const emptyBannerCreateBtn = document.getElementById('emptyBannerCreateBtn');
    const emptyBannerCloseBtn = document.getElementById('emptyBannerCloseBtn');

    const normalizeToSlug = (name) => {
        if (!name) return '';
        return name.toString().toLowerCase().replace(/[^a-z0-9]/g, '');
    };

    function showEmptyBanner(programName) {
        if (!emptyBannerEl) return;
        emptyBannerEl.style.display = 'block';
        // Update message with program name if available
        const strong = emptyBannerEl.querySelector('strong');
        if (strong) {
            strong.textContent = 'Reddit Wiki Entry Page has not yet been created.';
        }
    }

    function hideEmptyBanner() {
        if (!emptyBannerEl) return;
        emptyBannerEl.style.display = 'none';
    }

    if (emptyBannerCloseBtn) {
        emptyBannerCloseBtn.addEventListener('click', (e) => {
            e.preventDefault();
            hideEmptyBanner();
        });
    }

    if (emptyBannerCreateBtn) {
        emptyBannerCreateBtn.addEventListener('click', (e) => {
            e.preventDefault();
            hideEmptyBanner();
            // Focus program name field to start creating
            const programNameInput = document.getElementById('programName');
            if (programNameInput) programNameInput.focus();
        });
    }

    function clearFormToEmpty(programName) {
        // Basic fields to clear
        const fieldIds = ['programName','yearsActive','cityState','programType','yearFounded','ageRange','capacity','ownerName','ownerLink','avgStay','tuition','natsapMember','natsapYear','diagnosesList','avgStay','mainAddress','addressLink','accreditingBody','accreditingBodyLink','historyNotes','levelSystemDesc','structureMisc','punishmentsMisc','lawsuitsMisc','rulesList','mainComplaints','otherAllegationsList','mediaInfo','testimoniesMisc','relatedMediaMisc','customDiagnoses','customAllegations','rebrand','rebrandLink'];
        clearInputs(fieldIds);

        // Reset arrays and lists
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
        programLevels = [];
        affiliations = [];
        relatedPrograms = [];

        initializeEmptyLists();

        // Uncheck diagnosis/allegation boxes
        document.querySelectorAll('input[name="diagnoses"]').forEach(cb => cb.checked = false);
        document.querySelectorAll('input[name="allegations"]').forEach(cb => cb.checked = false);

        // Set program name if provided, otherwise leave blank
        if (programName) {
            const pn = document.getElementById('programName');
            if (pn) pn.value = programName;
        }
    }


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
        const listIds = ['staffListOutput', 'punishmentListOutput', 'lawsuitListOutput', 'articleListOutput', 'testimonyListOutput', 'mediaListOutput', 'campusListOutput', 'ownerChangeListOutput', 'ruleListOutput', 'allegationListOutput', 'therapyListOutput', 'affiliationListOutput', 'relatedProgramsListOutput'];
        listIds.forEach(id => {
            const element = document.getElementById(id);
            if (element) {
                element.style.display = 'none';
                element.innerHTML = '';
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
            container.style.display = 'none';
            return;
        }
        container.style.display = 'block';

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
        setFieldValue('rebrand', data.rebrand);
        setFieldValue('rebrandLink', data.rebrandLink);

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
        programLevels = Array.isArray(data.programLevels) ? data.programLevels : [];
        affiliations = Array.isArray(data.affiliations) ? data.affiliations : [];
        relatedPrograms = Array.isArray(data.relatedPrograms) ? data.relatedPrograms : [];

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
        
        renderList(affiliations, 'affiliationListOutput', item => {
            const linkPart = item.link ? ` (<a href="${escapeHtml(item.link)}" target="_blank">Link</a>)` : '';
            return `<strong>${escapeHtml(item.name)}</strong>${linkPart}`;
        });

        renderList(relatedPrograms, 'relatedProgramsListOutput', item => {
            const healPart = item.healLink ? ` <a href="${escapeHtml(item.healLink)}" target="_blank">[HEAL]</a>` : '';
            return `<strong>${escapeHtml(item.name)}</strong> (${escapeHtml(item.yearsActive || '?')}) - ${escapeHtml(item.location || '?')}${healPart}`;
        });

        setCheckboxGroup('diagnoses', data.selectedDiagnoses);
        setCheckboxGroup('allegations', data.selectedAllegations);

        // Detect and toggle organization mode after populating the form
        detectAndToggleOrganizationMode();
    }

    const fetchSubmissionByName = async (programName) => {
        if (!programName) return null;
        try {
            const apiBase = editorSettings.saveApi || '/wp-content/themes/child/api/save-wiki-submission.php';
            const response = await fetch(`${apiBase}?search=${encodeURIComponent(programName)}`);
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
        const baseUrl = editorSettings.markdownBaseUrl || '/wp-content/themes/child/markdown_output/';
        const candidates = [
            `${baseUrl}index_${slug}.md`,
            `${baseUrl}index_${slug}_.md`
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
            } else {
                const slug = getSlugFromEntryUrl(entry.url);
                if (!slug) {
                    throw new Error('Unable to determine index slug');
                }

                const markdown = await loadLocalIndexMarkdown(slug);
                if (!markdown) {
                    throw new Error('Local index markdown not found');
                }

                parseAndPopulate(markdown);
                updateMarkdownFromForm();

                importedMarkdown = markdown;
                if (programName) {
                    setFieldValue('programName', programName);
                }
                finalizeEntryLoad(programName || entry.url, { source: 'index' });
            }

            // Explicitly enable organization mode since we know this is an org index page
            isOrganizationEntry = true;
            const pageContainer = document.querySelector('.wiki-editor-page');
            if (pageContainer) {
                pageContainer.classList.add('organization-mode');
                updateOrganizationFacilitiesList();
            }
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

    // --- ENTRY BROWSER FUNCTIONALITY ---

    function finalizeEntryLoad(name) {
        // Backwards-compatible: accept optional options object as second arg
        const options = arguments[1] || {};

        // Older layouts used toggle buttons and hid the browser panels after load.
        // The current split-pane layout keeps the browser visible.
        const indexPanel = document.getElementById('indexBrowserPanel');
        const facilitiesPanel = document.getElementById('facilitiesBrowserPanel');

        const indexBtn = document.getElementById('toggleIndexBrowserBtn');
        const facilitiesBtn = document.getElementById('toggleFacilitiesBrowserBtn');
        const hasLegacyBrowserToggles = !!indexBtn || !!facilitiesBtn;

        if (hasLegacyBrowserToggles) {
            if (indexPanel) indexPanel.style.display = 'none';
            if (facilitiesPanel) facilitiesPanel.style.display = 'none';
            if (indexBtn) indexBtn.textContent = '📄 Browse Index Pages';
            if (facilitiesBtn) facilitiesBtn.textContent = '🏢 Browse Saved Facilities';
        } else {
            if (indexPanel) indexPanel.style.display = '';
            if (facilitiesPanel) facilitiesPanel.style.display = '';
        }

        // Flash loaded status on the appropriate button
        const flashBtn = options.source === 'index' ? indexBtn : facilitiesBtn;
        if (flashBtn) {
            const originalText = flashBtn.textContent;
            flashBtn.textContent = `✓ Loaded: ${name || 'Entry'}`;
            flashBtn.classList.add('loaded-flash');
            setTimeout(() => {
                flashBtn.textContent = originalText;
                flashBtn.classList.remove('loaded-flash');
            }, 3000);
        }

        if (!options.noScroll && wikiForm) wikiForm.scrollIntoView({ behavior: 'smooth' });
    }

    function updateNavButtons() {
        if (!backBtn) return;

        const showOrgBack = selectedIndexState === 'ORG_PROGRAMS';
        backBtn.style.display = showOrgBack ? 'inline-flex' : 'none';
        backBtn.textContent = '← Back';
    }

    const getPlaceholder = (category, programName) => {
        const name = programName || '[Program Name]';
        const lowerCategory = (category || '').toLowerCase();
        const placeholderByCategory = [
            {
                match: ['history', 'background'],
                text: `Background information for ${name} has not been added yet. If you have reliable historical details or sources to share, please contact u/Signal-Strain9810.`
            },
            {
                match: ['founders', 'staff'],
                text: `Information about the founders or notable staff at ${name} has not been added yet. If you have reliable names, roles, or source material to share, please contact u/Signal-Strain9810.`
            },
            {
                match: ['structure'],
                text: `Information about the program structure at ${name} has not been added yet. If you have reliable descriptions or source material to share, please contact u/Signal-Strain9810.`
            },
            {
                match: ['rules', 'punishments'],
                text: `Information about the rules, consequences, or disciplinary practices at ${name} has not been added yet. If you have reliable source material to share, please contact u/Signal-Strain9810.`
            },
            {
                match: ['abuse', 'neglect', 'lawsuits'],
                text: `Information about abuse allegations, neglect, or lawsuits involving ${name} has not been added yet. If you have reliable reports or source material to share, please contact u/Signal-Strain9810.`
            },
            {
                match: ['survivor testimonies', 'survivor testimony', 'testimonies', 'testimonials'],
                text: `No survivor testimonies for ${name} have been added here yet. If you have a firsthand account or reliable source material to share, please contact u/Signal-Strain9810.`
            },
            {
                match: ['related media'],
                text: `No related media links for ${name} have been added yet. If you have reliable external resources to share, please contact u/Signal-Strain9810.`
            }
        ];

        const categoryPlaceholder = placeholderByCategory.find((entry) =>
            entry.match.some((term) => lowerCategory.includes(term))
        );

        if (categoryPlaceholder) {
            return categoryPlaceholder.text;
        }

        if (lowerCategory.includes('media')) {
            return `No media coverage for ${name} has been added yet. If you have seen a news item about ${name} and would like to share it, please contact u/Signal-Strain9810.`;
        }

        return `Additional information about ${name} has not been added yet. If you have reliable updates or references to share, please contact u/Signal-Strain9810.`;
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
            outputDiv.style.display = 'none';
            return;
        }
        outputDiv.style.display = 'block';
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

    // --- Add Button: Affiliations ---
    const addAffiliationBtn = document.getElementById('addAffiliationBtn');
    if (addAffiliationBtn) {
        addAffiliationBtn.addEventListener('click', (e) => {
            e.preventDefault();
            const name = document.getElementById('affiliationName').value.trim();
            const link = sanitizeUrl(document.getElementById('affiliationLink').value);
            if (name) {
                affiliations.push({ name, link });
                renderList(affiliations, 'affiliationListOutput', item => {
                    const linkPart = item.link ? ` (<a href="${escapeHtml(item.link)}" target="_blank">Link</a>)` : '';
                    return `<strong>${escapeHtml(item.name)}</strong>${linkPart}`;
                });
                clearInputs(['affiliationName', 'affiliationLink']);
            } else {
                alert('Please enter an affiliation name.');
            }
        });
    }

    // --- Add Button: Related Programs ---
    const addRelatedProgBtn = document.getElementById('addRelatedProgBtn');
    if (addRelatedProgBtn) {
        addRelatedProgBtn.addEventListener('click', (e) => {
            e.preventDefault();
            const name = document.getElementById('relProgName').value.trim();
            const link = sanitizeUrl(document.getElementById('relProgLink').value);
            const yearsActive = document.getElementById('relProgYears').value.trim();
            const location = document.getElementById('relProgLocation').value.trim();
            const healLink = sanitizeUrl(document.getElementById('relProgHeal').value);
            const reopened = document.getElementById('relProgReopened').value.trim();

            if (name) {
                relatedPrograms.push({ name, link, yearsActive, location, healLink, reopened });
                renderList(relatedPrograms, 'relatedProgramsListOutput', item => {
                    const healPart = item.healLink ? ` <a href="${escapeHtml(item.healLink)}" target="_blank">[HEAL]</a>` : '';
                    return `<strong>${escapeHtml(item.name)}</strong> (${escapeHtml(item.yearsActive || '?')}) - ${escapeHtml(item.location || '?')}${healPart}`;
                });
                clearInputs(['relProgName', 'relProgLink', 'relProgYears', 'relProgLocation', 'relProgHeal', 'relProgReopened']);
            } else {
                alert('Please enter at least a program name.');
            }
        });
    }

    // --- Organization Mode Detection ---
    // Add event listeners to programType and programName fields to detect organization mode changes
    const programTypeField = document.getElementById('programType');
    const programNameField = document.getElementById('programName');

    // Function to detect if current entry is an organization and toggle form layout
    function detectAndToggleOrganizationMode() {
        const pageContainer = document.querySelector('.wiki-editor-page');
        if (!pageContainer) return;

        // Detect if this is an organization entry
        // Organizations typically:
        // 1. Have no program type (or explicitly say "Organization")
        // 2. Are known corporate entities (Acadia Healthcare, Universal Health Services, etc.)
        const programType = programTypeField?.value?.trim() || '';
        const programName = programNameField?.value?.trim() || '';

        // Check if this looks like an organization
        const isOrgByType = programType === '' || programType.toLowerCase() === 'organization';
        const isKnownOrg = /healthcare|health services|educational consultants|inc\.|corp\.|corporation|group/i.test(programName);

        isOrganizationEntry = isOrgByType && (isKnownOrg || programType.toLowerCase() === 'organization');

        // Toggle the organization-mode class
        if (isOrganizationEntry) {
            pageContainer.classList.add('organization-mode');
            console.log('Organization mode enabled for:', programName);
            // Update facilities list when switching to organization mode
            updateOrganizationFacilitiesList();
        } else {
            pageContainer.classList.remove('organization-mode');
        }
    }

    // Function to populate the organization facilities list from markdown
    function updateOrganizationFacilitiesList() {
        const facilitiesList = document.getElementById('organizationFacilitiesList');
        if (!facilitiesList) return;

        const markdown = importedMarkdown || document.getElementById('outputCode')?.value || '';

        if (!markdown.trim()) {
            facilitiesList.innerHTML = '<p style="color: #999; font-style: italic;">No facilities found. Facilities will appear here when this organization page is loaded.</p>';
            return;
        }

        // Extract wiki links only from table rows in a block of markdown
        function extractTableLinks(content) {
            const links = [];
            const tableRowRegex = /^\|.+\|/gm;
            const linkRegex = /\[([^\]]+)\]\(([^)]+)\)/g;
            let row;
            while ((row = tableRowRegex.exec(content)) !== null) {
                let lm;
                linkRegex.lastIndex = 0;
                while ((lm = linkRegex.exec(row[0])) !== null) {
                    let name = lm[1].replace(/\*\*/g, '').replace(/\*/g, '').replace(/__/g, '').replace(/_/g, '').trim();
                    const url = lm[2];
                    const isWikiLink = url.includes('/wiki/index/');
                    const isUserLink = url.includes('/u/') || url.includes('/user/');
                    const isExternal = url.startsWith('http') && !url.includes('/wiki/');
                    const isJustIndex = url.endsWith('/index/') || url.endsWith('/index');
                    if (isWikiLink && !isUserLink && !isExternal && !isJustIndex && name.length > 2) {
                        links.push({ name, url });
                    }
                }
            }
            return links;
        }

        // Split markdown into sections by headings and classify as open/closed
        const headingRegex = /^#{1,3}\s+(.+)$/gm;
        const sectionBoundaries = [];
        let m;
        while ((m = headingRegex.exec(markdown)) !== null) {
            sectionBoundaries.push({ index: m.index, end: m.index + m[0].length, heading: m[1].replace(/\*\*/g, '').trim() });
        }

        const openPrograms = [];
        const closedPrograms = [];

        sectionBoundaries.forEach((section, i) => {
            const contentStart = section.end;
            const contentEnd = i + 1 < sectionBoundaries.length ? sectionBoundaries[i + 1].index : markdown.length;
            const content = markdown.slice(contentStart, contentEnd);
            const heading = section.heading.toLowerCase();
            const links = extractTableLinks(content);
            if (/open|active|current/i.test(heading)) {
                openPrograms.push(...links);
            } else if (/clos|inactiv|former|past/i.test(heading)) {
                closedPrograms.push(...links);
            }
        });

        if (openPrograms.length === 0 && closedPrograms.length === 0) {
            facilitiesList.innerHTML = '<p style="color: #999; font-style: italic;">No facilities found in the organization\'s wiki page.</p>';
            return;
        }

        const renderList = (programs) =>
            '<ul style="list-style:none; padding:0; margin:0 0 12px;">' +
            programs.map(f => `<li style="padding:6px 8px; margin:4px 0; background:#fff; border-left:4px solid #33A7B5; border-radius:3px;"><strong style="color:#000080;">${escapeHtml(f.name)}</strong></li>`).join('') +
            '</ul>';

        let html = '';
        if (openPrograms.length > 0) {
            html += `<h4 style="margin:0 0 6px; color:#000435;">Open Programs (${openPrograms.length})</h4>${renderList(openPrograms)}`;
        }
        if (closedPrograms.length > 0) {
            html += `<h4 style="margin:10px 0 6px; color:#000435;">Closed Programs (${closedPrograms.length})</h4>${renderList(closedPrograms)}`;
        }
        facilitiesList.innerHTML = html;
    }

    if (programTypeField) {
        programTypeField.addEventListener('input', () => {
            detectAndToggleOrganizationMode();
        });
        programTypeField.addEventListener('change', () => {
            detectAndToggleOrganizationMode();
        });
    }

    if (programNameField) {
        programNameField.addEventListener('input', () => {
            detectAndToggleOrganizationMode();
        });
        programNameField.addEventListener('change', () => {
            detectAndToggleOrganizationMode();
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
            therapies, programLevels, affiliations, relatedPrograms,
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

        // Update organization facilities list if in organization mode
        if (isOrganizationEntry) {
            updateOrganizationFacilitiesList();
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
                const response = await fetch(editorSettings.saveApi || '/wp-content/themes/child/api/save-wiki-submission.php', {
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

                    // Handle empty files
                    if (parsedData.isEmpty) {
                        results.failed.push({
                            file: file.name,
                            error: 'Skipped: Reddit Wiki Entry Page has not yet been created.'
                        });
                        continue;
                    }

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
                    const response = await fetch(editorSettings.saveApi || '/wp-content/themes/child/api/save-wiki-submission.php', {
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

    const entryBrowserSection = document.querySelector('.entry-browser-section');
    const wikiIndexJsonUrl = entryBrowserSection?.dataset?.wikiIndexJson;
    const wikiProgramsBase = entryBrowserSection?.dataset?.wikiProgramsBase;
    const locationIndexSelect = document.getElementById('locationIndexSelect');
    const orgIndexSelect = document.getElementById('orgIndexSelect');
    const indexSearch = document.getElementById('locationSearch'); // Reusing existing search logic ID if possible, or new one
    const indexEntriesList = document.getElementById('indexEntriesList');

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

    function getSlugFromEntryUrl(url) {
        if (!url) return '';
        const cleaned = url.replace(/\/+$/, '');
        const match = cleaned.match(/wiki\/(?:index\/)?(.+)$/i);
        return match ? match[1] : '';
    }

    async function initializeIndexes() {
        if (!wikiIndexJsonUrl) {
            updateIndexEntriesMessage('Index metadata is unavailable.', 'error');
            return;
        }

        updateIndexEntriesMessage('Loading index data...');
        
        try {
            // 1. Load Master Index (States/Counts)
            if (!allIndexData) {
                const response = await fetch(wikiIndexJsonUrl, { cache: 'no-store' });
                if (!response.ok) throw new Error(`HTTP ${response.status}`);
                allIndexData = await response.json();
            }

            // 2. Populate Locations
            if (locationIndexSelect) {
                locationIndexSelect.innerHTML = '<option value="">Select a location</option>';
                const allStates = Object.entries(allIndexData.states || {});
                allStates.sort((a, b) => {
                    const displayA = STATE_DISPLAY_NAMES[a[0]] || a[0];
                    const displayB = STATE_DISPLAY_NAMES[b[0]] || b[0];
                    return displayA.localeCompare(displayB);
                });

                allStates.forEach(([code, count]) => {
                    if (code === 'CORPORATE') return; // Skip corporate in location list
                    const option = document.createElement('option');
                    option.value = code;
                    option.textContent = `${STATE_DISPLAY_NAMES[code] || code} (${count || 0})`;
                    locationIndexSelect.appendChild(option);
                });
            }

            // 3. Populate Organizations (from CORPORATE index)
            if (orgIndexSelect && wikiProgramsBase) {
                orgIndexSelect.innerHTML = '<option value="">Loading organizations...</option>';
                try {
                    const corpResponse = await fetch(`${wikiProgramsBase}programs-CORPORATE.json`, { cache: 'no-cache' });
                    if (corpResponse.ok) {
                        const corpData = await corpResponse.json();
                        const orgs = Array.isArray(corpData.programs) ? corpData.programs : [];
                        orgs.sort((a, b) => (a.name || '').localeCompare(b.name || ''));
                        
                        orgIndexSelect.innerHTML = '<option value="">Select an organization</option>';
                        orgs.forEach(org => {
                            const option = document.createElement('option');
                            option.value = org.name; // Use name for lookup
                            option.textContent = org.name;
                            orgIndexSelect.appendChild(option);
                        });
                    } else {
                        orgIndexSelect.innerHTML = '<option value="">Failed to load organizations</option>';
                    }
                } catch (e) {
                    console.error('Error loading corporate index:', e);
                    orgIndexSelect.innerHTML = '<option value="">Error loading organizations</option>';
                }
            }

            updateIndexEntriesMessage('Select a location or organization above to load facilities.');

        } catch (error) {
            updateIndexEntriesMessage(
                `Failed to load index metadata: ${error.message || 'Unknown error'}`,
                'error'
            );
        }
    }

    // Call init on load
    if (document.querySelector('.category-tab.active')?.dataset?.category === 'location-indexes') {
        initializeIndexes();
    } else {
        // Or just always init if tabs exist
        if (locationIndexSelect || orgIndexSelect) initializeIndexes();
    }

    // Event Listeners
    if (locationIndexSelect) {
        locationIndexSelect.addEventListener('change', (e) => {
            // Reset other dropdown
            if (orgIndexSelect) orgIndexSelect.value = '';
            
            // Clear current view
            currentIndexPrograms = [];
            selectedIndexState = ''; 
            updateIndexEntriesMessage('Loading...');
            
            // Clear search filter
            if (indexSearch) indexSearch.value = '';

            loadProgramsForState(e.target.value);
        });
    }

    if (orgIndexSelect) {
        orgIndexSelect.addEventListener('change', (e) => {
            // Reset other dropdown
            if (locationIndexSelect) locationIndexSelect.value = '';
            
            // Clear current view
            currentIndexPrograms = [];
            selectedIndexState = '';
            updateIndexEntriesMessage('Loading...');

            // Clear search filter
            if (indexSearch) indexSearch.value = '';

            const orgName = e.target.value;
            if (orgName) {
                loadOrgProgramsFromDatabase(orgName, null);
            } else {
                updateIndexEntriesMessage('Select an organization to load facilities.');
            }
        });
    }

    if (backBtn) {
        backBtn.addEventListener('click', () => {
            currentNavType = '';
            currentNavList = [];
            currentNavIndex = -1;

            if (selectedIndexState === 'ORG_PROGRAMS') {
                selectedIndexState = '';
                currentIndexPrograms = [];
                if (orgIndexSelect) orgIndexSelect.value = '';
                if (indexSearch) indexSearch.value = '';
                setIndexSearchEnabled(false);
                updateIndexEntriesMessage('Select an organization above to load facilities.');
            }

            updateNavButtons();
        });
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
        matches.forEach((entry, index) => {
            const row = document.createElement('div');
            row.className = 'index-entry-row';

            const nameSpan = document.createElement('span');
            nameSpan.className = 'index-entry-name';

            // Determine candidate slug:
            // Prefer explicit wiki slug from URL; if we're in CORPORATE view (organizations)
            // fall back to a normalized name-based slug to detect empty org index pages.
            const slugFromUrl = getSlugFromEntryUrl(entry.url);
            const entryName = entry.name || entry.normalizedName || 'Unnamed program';
            let candidateSlug = '';
            if (slugFromUrl) {
                candidateSlug = slugFromUrl;
            } else if (selectedIndexState === 'CORPORATE') {
                candidateSlug = normalizeToSlug(entry.normalizedName || entry.name || '');
            }

            nameSpan.appendChild(document.createTextNode(entryName));

            // Visual indicator if this entry is known-empty (only when candidateSlug is available)
            try {
                if (emptySlugSet && candidateSlug && emptySlugSet.has(candidateSlug.toLowerCase())) {
                    const emptyFlag = document.createElement('span');
                    emptyFlag.className = 'index-empty-flag';
                    emptyFlag.title = 'This wiki entry appears to be empty on Reddit';
                    emptyFlag.style.pointerEvents = 'none';
                    emptyFlag.style.marginLeft = '6px';
                    emptyFlag.textContent = '⚠️ EMPTY';
                    nameSpan.appendChild(emptyFlag);
                }
            } catch (e) {
                // Fail silently
            }

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
                    currentNavType = 'index';
                    currentNavList = matches;
                    currentNavIndex = index;
                    updateNavButtons();
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
                    currentNavType = 'index';
                    currentNavList = matches;
                    currentNavIndex = index;
                    updateNavButtons();
                    loadEntryFromReddit(entry, editButton);
                });
                actions.appendChild(editButton);
                

            }

            if (slugFromUrl) {
                const viewLink = document.createElement('a');
                viewLink.href = `https://www.reddit.com/r/troubledteens/wiki/index/${slugFromUrl}/`;
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
        // Delay changing the button state until after we check if the org/index is empty

            try {
                // If the organization's wiki index page is known-empty, show banner and open an empty form
                try {
                    const orgSlug = normalizeToSlug(orgName || '');
                    if (emptySlugSet && orgSlug && emptySlugSet.has(orgSlug.toLowerCase())) {
                        console.log('Organization index is empty for', orgName, orgSlug);
                        showEmptyBanner(orgName);
                        clearFormToEmpty(orgName);
                        importedMarkdown = '';
                        updateMarkdownFromForm();
                        // Hide browser but do not auto-scroll to the form; scroll banner into view instead
                        finalizeEntryLoad(orgName, { noScroll: true, source: 'index' });
                        document.getElementById('emptyEntryBanner')?.scrollIntoView({ behavior: 'smooth', block: 'center' });
                        // Restore button state before returning
                        if (button) {
                            button.disabled = false;
                            button.textContent = originalLabel;
                        }
                        return;
                    }
                } catch (e) {
                    console.warn('Error checking organization slug in empty list:', e);
                }
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
                    // Only extract links from table rows to avoid picking up
                    // links in staff bios, lawsuits, or other narrative sections
                    const tableRowRegex = /^\|.+\|/gm;
                    const linkRegex = /\[([^\]]+)\]\(([^)]+)\)/g;
                    let tableRow;

                    while ((tableRow = tableRowRegex.exec(markdown)) !== null) {
                        const rowText = tableRow[0];
                        let match;
                        linkRegex.lastIndex = 0;
                        while ((match = linkRegex.exec(rowText)) !== null) {
                            let name = match[1];
                            const url = match[2];

                            // Remove markdown bold/italic markers from name
                            name = name.replace(/\*\*/g, '').replace(/\*/g, '').replace(/__/g, '').replace(/_/g, '').trim();

                            const isUserLink = url.includes('/u/') || url.includes('/user/');
                            const isExternal = url.startsWith('http') && !url.includes('/wiki/');
                            const isWikiLink = url.includes('/wiki/index/');
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
            }

            if (programs.length === 0) {
                 // No child facilities found in database - check if the organization page exists
                 // and show it as the main entry instead of an empty list
                 const orgPage = searchResult.data?.find(e =>
                     e.program_name.toLowerCase() === orgName.toLowerCase()
                 );

                 if (orgPage) {
                     // Organization page found - treat it as a single "program" entry
                     console.log('No child facilities found, showing organization page as main entry:', orgName);
                     programs.push({
                         name: orgName,
                         url: '',
                         normalizedName: orgName.toLowerCase(),
                         source: 'database',
                         id: orgPage.id,
                         isOrgPage: true
                     });
                 } else {
                     // If nothing found at all, show empty form
                     if (!searchResult.data || searchResult.data.length === 0) {
                         console.warn('Organization not found in database for', orgName);
                     } else {
                         console.warn('No facilities found for organization', orgName);
                     }
                     try {
                         showEmptyBanner(orgName);
                         clearFormToEmpty(orgName);
                         importedMarkdown = '';
                         updateMarkdownFromForm();
                         finalizeEntryLoad(orgName, { noScroll: true, source: 'index' });
                         document.getElementById('emptyEntryBanner')?.scrollIntoView({ behavior: 'smooth', block: 'center' });
                     } catch (e) {
                         console.error('Error preparing empty form for organization:', e);
                     }
                     return;
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

    indexSearch?.addEventListener('input', () => renderIndexEntries());

    setIndexSearchEnabled(false);
    updateNavButtons();

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
                const status = String(entry.status || '').toLowerCase();

                // Check if entry content indicates it doesn't exist
                const content = entry.generated_markdown || entry.original_markdown || '';
                const isEmptyPage = content.toLowerCase().includes('does not exist') ||
                                   content.toLowerCase().includes('not found') ||
                                   content.trim().length < 50; // Very short content likely means empty page

                const notFoundFlag = isEmptyPage ? '<span class="entry-not-found-flag" title="This page has no content">⚠️ EMPTY</span> ' : '';
                const statusBadge = status === 'approved' || status === 'published'
                    ? `<span class="entry-status-badge entry-status-${escapeHtml(status)}" title="This markdown upload has already been ${escapeHtml(status)}">${escapeHtml(status === 'published' ? 'Published' : 'Approved')}</span> `
                    : '';

                const actionButtons = `
                    <tr data-entry-id="${entry.id}">
                        <td class="entry-name" data-label="Program Name">${statusBadge}${notFoundFlag}<strong>${escapeHtml(entry.program_name || 'Untitled')}</strong></td>
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
            entriesList.querySelectorAll('.load-entry-btn').forEach((btn, index) => {
                btn.addEventListener('click', () => {
                    currentNavType = 'facilities';
                    currentNavList = entries;
                    currentNavIndex = index;
                    updateNavButtons();
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
        // Reset to facility mode by default; org mode set explicitly if needed after load
        isOrganizationEntry = false;
        const _pageContainer = document.querySelector('.wiki-editor-page');
        if (_pageContainer) _pageContainer.classList.remove('organization-mode');

        try {
            const response = await fetch(`/wp-content/themes/child/api/save-wiki-submission.php?id=${encodeURIComponent(entryId)}`);
            const result = await response.json();

            if (!result.success) {
                throw new Error(result.error || 'Failed to load entry');
            }

            const entry = result.data;
            // If this entry corresponds to a known-empty wiki slug, show banner and load an empty form
            try {
                const candidateName = entry.program_name || entry.programName || '';
                const slug = entry.slug || normalizeToSlug(candidateName);
                if (emptySlugSet && slug && emptySlugSet.has(slug.toLowerCase())) {
                    console.log('Detected empty wiki slug for', slug);
                    showEmptyBanner(candidateName || '');
                    clearFormToEmpty(candidateName || '');
                    importedMarkdown = '';
                    updateMarkdownFromForm();
                    finalizeEntryLoad(candidateName || entry.program_name, { source: 'index' });
                    return;
                }
            } catch (e) {
                console.warn('Error checking empty slug mapping:', e);
            }
            
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
            finalizeEntryLoad(entry.program_name, { source: 'facilities' });

            // Update URL for deep linking
            const newUrl = new URL(window.location);
            newUrl.searchParams.set('entry_id', entryId);
            window.history.pushState({ entryId: entryId }, '', newUrl);

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
            const response = await fetch(editorSettings.saveApi || '/wp-content/themes/child/api/save-wiki-submission.php', {
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

    // Check URL params for auto-loading
    const urlParams = new URLSearchParams(window.location.search);
    if (urlParams.has('entry_id')) {
        loadEntryIntoForm(urlParams.get('entry_id'));
    } else if (urlParams.has('program_name')) {
        const name = urlParams.get('program_name');
        // Try to look up the ID by name
        fetchSubmissionByName(name).then(entry => {
            if (entry && entry.id) {
                loadEntryIntoForm(entry.id);
            } else {
                // If not in DB, is it an organization?
                // We can't easily replicate loadOrganizationIndexEntry without the 'entry' object
                // but we can try to infer it if it looks like an org.
                console.warn('Program not found in database via URL param:', name);
                // Optional: Show error or try to fuzzy match
                alert(`Could not find saved entry for "${name}". It may not have been imported to the database yet.`);
            }
        });
    }
});
