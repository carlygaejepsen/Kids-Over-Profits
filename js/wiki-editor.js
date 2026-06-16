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

    // "Additional Notes" textareas whose content is appended to (not substituted
    // for) the auto-generated section. Tracked so the generator knows which
    // fields may carry verbatim imported prose. Keep in sync with the matching
    // *IsImported handling in wiki-generation.js.
    const APPEND_NOTE_FIELD_IDS = ['historyNotes', 'structureMisc', 'lawsuitsMisc', 'testimoniesMisc', 'relatedMediaMisc'];

    // Record that a notes field currently holds verbatim imported content, so the
    // generator can substitute (not append) it and avoid round-trip duplication.
    // The marker is matched by value, so any manual edit silently clears it.
    const markFieldAsImported = (id) => {
        const el = document.getElementById(id);
        if (el) {
            el.dataset.importedValue = el.value;
        }
    };

    // Remember the address an entry was loaded with so a later edit can be
    // detected. If the user then changes the Main Address, collectCurrentFormData
    // emits the prior value as `formerAddress`, and the generator notes the old
    // location in plain language. Cleared by clearFormToEmpty for fresh entries.
    const markAddressAsLoaded = (address, link) => {
        const el = document.getElementById('mainAddress');
        if (el) {
            el.dataset.loadedAddress = address || '';
            el.dataset.loadedAddressLink = link || '';
        }
    };
    const clearLoadedAddress = () => {
        const el = document.getElementById('mainAddress');
        if (el) {
            delete el.dataset.loadedAddress;
            delete el.dataset.loadedAddressLink;
        }
    };
    let currentPage = 1;
    let totalEntries = 0;
    const entriesPerPage = 25;
    let currentNavType = '';
    let currentNavList = [];
    let currentNavIndex = -1;

    // --- Empty-slug mapping ---
    // Set of known-empty wiki slugs. Preferred source is a JSON list served from
    // the same directory as the program index data (reliably web-accessible);
    // markdown_output/empty_files_updated.md is a fallback.
    let emptySlugSet = null;
    let emptySlugPromise = null;
    let completedNamesSet = null; // normalized program names that already have a saved submission
    let completedSlugsSet = null; // wiki slugs whose submission recorded its source slug (precise completion)
    // Normalized program names that map to more than one distinct wiki slug across
    // the index (e.g. "Hyde School" -> hydeme + hydect). For these, a name-only
    // match cannot prove a *specific* slug is done, so completion requires a slug
    // match. Built from the index data in loadAllStubs().
    let ambiguousStubNames = new Set();
    // Slug of the entry currently loaded into the form, threaded into the saved
    // submission as `sourceSlug` so completion can be matched by slug, not name.
    let currentEntrySlug = '';

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

                // The Stubs tab drives the right pane directly (aggregated empties).
                // Switching to a location/org tab returns to normal index browsing.
                if (category === 'stub-indexes') {
                    loadAllStubs();
                } else if (browseMode === 'stubs') {
                    browseMode = 'index';
                    if (selectedIndexState) {
                        renderIndexEntries();
                    } else {
                        updateIndexEntriesMessage('Select a location or organization above to load facilities.');
                    }
                }
            });
        });

        // Initialize first tab
        const firstTab = categoryTabs[0];
        if (firstTab) {
            setTimeout(() => firstTab.click(), 50);
        }
    }

    // Normalize a program/entry name for matching against DB submissions.
    const normalizeEntryName = (s) => String(s || '').toLowerCase().replace(/[^a-z0-9]+/g, ' ').trim();

    function getStubsApiUrl() {
        if (editorSettings.stubsApi) return editorSettings.stubsApi;
        if (editorSettings.saveApi) return editorSettings.saveApi.replace(/[^\/]*$/, 'wiki-stubs.php');
        return '/wp-content/themes/child/api/wiki-stubs.php';
    }

    async function loadEmptySlugMapping() {
        emptySlugSet = new Set();
        completedNamesSet = new Set();
        completedSlugsSet = new Set();

        // 1. Preferred: server endpoint — auto-reads the current empty-file list
        //    AND reports which entries already have a saved submission (completed).
        try {
            const resp = await fetch(getStubsApiUrl(), { cache: 'no-cache' });
            if (resp.ok) {
                const data = await resp.json();
                (data.emptySlugs || []).forEach(slug => { if (slug) emptySlugSet.add(String(slug).toLowerCase()); });
                (data.completedNames || []).forEach(name => { const n = normalizeEntryName(name); if (n) completedNamesSet.add(n); });
                (data.completedSlugs || []).forEach(slug => { if (slug) completedSlugsSet.add(String(slug).toLowerCase()); });
                if (emptySlugSet.size > 0) {
                    console.log('Loaded ' + emptySlugSet.size + ' empty slugs, ' + completedNamesSet.size + ' completed, from wiki-stubs.php');
                    return;
                }
            }
        } catch (err) {
            console.warn('wiki-stubs.php unavailable, falling back to static list:', err);
        }

        // 2. Fallback: static empty-slugs.json next to the program index data.
        const programsBase = document.querySelector('.entry-browser-section')?.dataset?.wikiProgramsBase;
        if (programsBase) {
            try {
                const resp = await fetch(`${programsBase}empty-slugs.json`, { cache: 'no-cache' });
                if (resp.ok) {
                    const data = await resp.json();
                    const slugs = Array.isArray(data) ? data : (data.slugs || []);
                    slugs.forEach(slug => { if (slug) emptySlugSet.add(String(slug).toLowerCase()); });
                    if (emptySlugSet.size > 0) {
                        console.log('Loaded empty slug list (' + emptySlugSet.size + ' slugs) from empty-slugs.json');
                        return;
                    }
                }
            } catch (err) {
                console.warn('empty-slugs.json unavailable, falling back to markdown_output:', err);
            }
        }

        // 3. Fallback: legacy markdown_output/empty_files_updated.md
        try {
            const baseUrl = editorSettings.markdownBaseUrl || '/wp-content/themes/child/markdown_output/';
            const resp = await fetch(`${baseUrl}empty_files_updated.md`, { cache: 'no-cache' });
            if (resp.ok && resp.status === 200) {
                const text = await resp.text();
                text.split(/\r?\n/).forEach(line => {
                    const trimmed = line.trim();
                    if (!trimmed) return;
                    const name = trimmed.replace(/^[-\s]+/, '').split('/').pop();
                    if (!name) return;
                    if (name.startsWith('index_')) {
                        const slug = name.replace(/^index_/, '').replace(/\.md$/i, '').replace(/_$/, '');
                        if (slug) emptySlugSet.add(slug.toLowerCase());
                    } else if (/^[a-z0-9-]+\.md$/i.test(name)) {
                        emptySlugSet.add(name.replace(/\.md$/i, '').toLowerCase());
                    }
                });
                console.log('Loaded empty slug list (' + emptySlugSet.size + ' slugs) from markdown_output');
            } else {
                console.warn('Empty files list not found (HTTP ' + resp.status + ')');
            }
        } catch (err) {
            console.warn('Failed to load empty files list:', err);
        }
    }

    // Load once; callers that need the set ready should await ensureEmptySlugSet().
    function ensureEmptySlugSet() {
        if (!emptySlugPromise) emptySlugPromise = loadEmptySlugMapping();
        return emptySlugPromise;
    }
    ensureEmptySlugSet();

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

    function clearFormToEmpty(programName, options = {}) {
        const entryType = options.entryType === 'organization' ? 'organization' : 'facility';
        const preservedRelatedPrograms = entryType === 'organization' && Array.isArray(relatedPrograms)
            ? [...relatedPrograms]
            : [];

        // Basic fields to clear
        const fieldIds = ['programName','yearsActive','cityState','programType','yearFounded','ageRange','capacity','ownerName','ownerLink','avgStay','tuition','natsapMember','natsapYear','diagnosesList','avgStay','mainAddress','addressLink','accreditingBody','accreditingBodyLink','historyNotes','levelSystemDesc','structureMisc','punishmentsMisc','lawsuitsMisc','rulesList','mainComplaints','otherAllegationsList','mediaInfo','testimoniesMisc','relatedMediaMisc','customDiagnoses','customAllegations','rebrand','rebrandLink','headquarters','parentCompany','parentCompanyLink'];
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
        relatedPrograms = preservedRelatedPrograms;

        initializeEmptyLists();

        // Uncheck diagnosis/allegation boxes
        document.querySelectorAll('input[name="diagnoses"]').forEach(cb => cb.checked = false);
        document.querySelectorAll('input[name="allegations"]').forEach(cb => cb.checked = false);

        const entryTypeField = document.getElementById('entryType');
        if (entryTypeField) {
            entryTypeField.value = entryType;
        }

        // Set program name if provided, otherwise leave blank
        if (programName) {
            const pn = document.getElementById('programName');
            if (pn) pn.value = programName;
        }

        window.unparsedContentFromImport = '';
        clearLoadedAddress();
        detectAndToggleOrganizationMode();
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

    // Most repeating sections (staff, lawsuits, testimonies, etc.) only commit
    // their typed values to the entry when the user clicks that section's "Add"
    // button. If the fields are filled but Add isn't clicked before Generate or
    // Submit, the input would be silently dropped. To prevent that, fold in any
    // section whose required fields are already filled by triggering its existing
    // Add handler. Each readiness check mirrors that handler's own validation, so
    // a "please fill in..." alert is never shown.
    function flushPendingFormAdders() {
        const filled = (id) => {
            const el = document.getElementById(id);
            return !!(el && el.value && el.value.trim());
        };

        const pendingSections = [
            { btn: 'addStaffBtn',       ready: () => filled('staffName') && filled('staffRole') },
            { btn: 'addPunishmentBtn',  ready: () => filled('punishmentName') && filled('punishmentDescription') },
            { btn: 'addRuleBtn',        ready: () => filled('ruleName') },
            { btn: 'addLawsuitBtn',     ready: () => filled('lawsuitYear') && filled('lawsuitPlaintiff') && filled('lawsuitClaims') },
            { btn: 'addCampusBtn',      ready: () => filled('campusName') && filled('campusLocation') },
            { btn: 'addOwnerChangeBtn', ready: () => filled('ownerChangeYear') && (filled('ownerChangePrevious') || filled('ownerChangeNew')) },
            { btn: 'addTherapyBtn',     ready: () => filled('therapyFrequency') },
            { btn: 'addArticleBtn',     ready: () => filled('articleTitle') && filled('articleUrl') },
            { btn: 'addTestimonyBtn',   ready: () => filled('testimonyQuote') && filled('testimonySource') },
            { btn: 'addMediaBtn',       ready: () => filled('mediaTitle') && filled('mediaUrl') },
            { btn: 'addAffiliationBtn', ready: () => filled('affiliationName') },
            { btn: 'addRelatedProgBtn', ready: () => filled('relProgName') }
        ];

        pendingSections.forEach(({ btn, ready }) => {
            const button = document.getElementById(btn);
            if (button && ready()) {
                button.click();
            }
        });
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

    const inferEntryTypeFromData = (data = {}) => {
        const explicitEntryType = String(data.entryType || '').trim().toLowerCase();
        if (explicitEntryType === 'organization') return 'organization';
        if (explicitEntryType === 'facility') return 'facility';

        const programType = String(data.programType || '').trim().toLowerCase();
        const programName = String(data.programName || '').trim();
        const hasProgramsTable = Array.isArray(data.relatedPrograms) && data.relatedPrograms.length > 0;
        const hasOrgOnlyFields = !!String(data.headquarters || data.parentCompany || data.parentCompanyLink || '').trim();
        const hasFacilitySignals = !!(
            String(data.cityState || '').trim() ||
            String(data.mainAddress || '').trim() ||
            String(data.ageRange || '').trim() ||
            String(data.capacity || '').trim() ||
            String(data.avgStay || '').trim() ||
            String(data.tuition || '').trim() ||
            String(data.natsapMember || '').trim() ||
            (Array.isArray(data.selectedDiagnoses) && data.selectedDiagnoses.length > 0)
        );
        const nameLooksLikeOrganization = /association|company|corporation|corp\.|group|health(?:care| services)?|holdings|institute|network|partners|services|collective|natsap|ieca|wwasp|uhs/i.test(programName);
        const typeLooksLikeOrganization = /organization|association|company|corporation|group|operator|network|membership/i.test(programType);

        return (typeLooksLikeOrganization || hasOrgOnlyFields || (hasProgramsTable && !hasFacilitySignals) || (nameLooksLikeOrganization && !hasFacilitySignals))
            ? 'organization'
            : 'facility';
    };

    const setEntryTypeValue = (entryType) => {
        const entryTypeField = document.getElementById('entryType');
        if (!entryTypeField) return;
        entryTypeField.value = entryType === 'organization' ? 'organization' : 'facility';
    };

    const applyStoredEntryMetadata = (storedData = {}) => {
        if (!storedData || typeof storedData !== 'object') {
            return;
        }

        setFieldValue('headquarters', storedData.headquarters);
        setFieldValue('parentCompany', storedData.parentCompany);
        setFieldValue('parentCompanyLink', storedData.parentCompanyLink);

        const inferredEntryType = inferEntryTypeFromData(storedData);
        if (storedData.entryType || inferredEntryType === 'organization') {
            setEntryTypeValue(storedData.entryType || inferredEntryType);
        }

        detectAndToggleOrganizationMode();
    };

    function collectCurrentFormData() {
        // Fold in any section typed but not yet committed via its "Add" button.
        flushPendingFormAdders();

        const vals = {};
        const inputs = document.querySelectorAll('#wikiForm input[type="text"], #wikiForm textarea, #wikiForm select');
        inputs.forEach(input => {
            if (input.id && !input.closest('.form-adder')) {
                vals[input.id] = input.value.trim();
            }
        });

        const selectedDiagnoses = [];
        document.querySelectorAll('input[name="diagnoses"]:checked').forEach(cb => {
            selectedDiagnoses.push(cb.value);
        });

        const selectedAllegations = [];
        document.querySelectorAll('input[name="allegations"]:checked').forEach(cb => {
            selectedAllegations.push(cb.value);
        });

        const entryType = vals.entryType || inferEntryTypeFromData({
            ...vals,
            relatedPrograms,
            headquarters: vals.headquarters,
            parentCompany: vals.parentCompany
        });

        // "Additional Notes" fields are normally appended to their section so the
        // structured fields above them are never lost. The exception is content
        // that arrived verbatim from an import and has not been edited since: that
        // text already contains the full prose section, so appending the
        // re-derived structured sentences would duplicate it. A field is treated
        // as "imported" only while its value still matches what the import wrote
        // (see markFieldAsImported / populateFormFromParsedData).
        APPEND_NOTE_FIELD_IDS.forEach(id => {
            const el = document.getElementById(id);
            vals[`${id}IsImported`] = !!(
                el &&
                typeof el.dataset.importedValue === 'string' &&
                el.value === el.dataset.importedValue &&
                el.value.trim() !== ''
            );
        });

        // If the entry was loaded with an address and the user has since changed
        // it, surface the prior value so the generator can note the old location.
        const mainAddressEl = document.getElementById('mainAddress');
        if (mainAddressEl && typeof mainAddressEl.dataset.loadedAddress === 'string') {
            const loaded = mainAddressEl.dataset.loadedAddress.trim();
            const current = (vals.mainAddress || '').trim();
            if (loaded && loaded.toLowerCase() !== current.toLowerCase()) {
                vals.formerAddress = loaded;
                vals.formerAddressLink = mainAddressEl.dataset.loadedAddressLink || '';
            }
        }

        return {
            ...vals,
            entryType,
            isOrganizationEntry: entryType === 'organization' || isOrganizationEntry,
            staffMembers,
            punishments,
            lawsuits,
            newsArticles,
            testimonies,
            relatedMedia,
            campuses,
            ownershipChanges,
            rules,
            allegations,
            therapies,
            programLevels,
            affiliations,
            relatedPrograms,
            selectedDiagnoses,
            selectedAllegations,
            // Thread imported-but-unparsed sections (e.g. "Closure and Rebranding")
            // through to the generator, which places them just before Related Media.
            // (The generator owns placement now; see the removed manual append in
            // updateMarkdownFromForm.)
            unparsedContent: window.unparsedContentFromImport || ''
        };
    }

    const getOrganizationValueForSubmission = (formData = {}) => {
        if ((formData.entryType || '').toLowerCase() === 'organization') {
            return formData.parentCompany || formData.ownerName || '';
        }

        return formData.parentCompany || formData.ownerName || '';
    };

    function populateFormFromParsedData(parsedData = {}, overrides = {}) {
        const data = { ...parsedData, ...overrides };
        setFieldValue('entryType', data.entryType || inferEntryTypeFromData(data));
        setFieldValue('programName', data.programName);
        setFieldValue('yearsActive', data.yearsActive);
        setFieldValue('cityState', data.cityState);
        setFieldValue('programType', data.programType);
        setFieldValue('yearFounded', data.yearFounded);
        setFieldValue('headquarters', data.headquarters);
        setFieldValue('parentCompany', data.parentCompany);
        setFieldValue('parentCompanyLink', data.parentCompanyLink);
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
        markAddressAsLoaded(data.mainAddress, data.addressLink);
        setFieldValue('accreditingBody', data.accreditingBody);
        setFieldValue('accreditingBodyLink', data.accreditingBodyLink);
        setFieldValue('historyNotes', data.historyNotes || data.historyMisc);
        setFieldValue('levelSystemDesc', data.levelSystemDesc);
        setFieldValue('structureMisc', data.structureMisc);
        setFieldValue('punishmentsMisc', data.punishmentsMisc);
        setFieldValue('lawsuitsMisc', data.lawsuitsMisc);
        setFieldValue('mainComplaints', data.mainComplaints);
        setFieldValue('mediaInfo', data.mediaInfo);
        setFieldValue('testimoniesMisc', data.testimoniesMisc);
        setFieldValue('relatedMediaMisc', data.relatedMediaMisc);
        setFieldValue('customDiagnoses', data.customDiagnoses);
        setFieldValue('customAllegations', data.customAllegations);
        setFieldValue('rebrand', data.rebrand);
        setFieldValue('rebrandLink', data.rebrandLink);

        // These notes fields were just filled from imported/loaded data, so flag
        // them as imported. The generator will substitute (not append) them while
        // unchanged, preventing duplicate prose on a parse→generate round-trip;
        // the flag clears automatically once the user edits the field.
        APPEND_NOTE_FIELD_IDS.forEach(markFieldAsImported);

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
                finalizeEntryLoad(programName || entry.url, { source: 'index', sourceSlug: slug });
            }

            setEntryTypeValue('organization');
            detectAndToggleOrganizationMode();
            updateMarkdownFromForm();
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

        // Track which wiki slug this load corresponds to so a later save records it
        // as sourceSlug. Loads without a slug (manual/free-form entries) reset it.
        currentEntrySlug = options.sourceSlug ? String(options.sourceSlug).toLowerCase() : '';

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
                text: `Background information for ${name} has not been added yet. If you have reliable historical details or sources to share, please contact [u/Miss_Nobody89](/u/Miss_Nobody89).`
            },
            {
                match: ['founders', 'staff'],
                text: `Information about the founders or notable staff at ${name} has not been added yet. If you have reliable names, roles, or source material to share, please contact [u/Miss_Nobody89](/u/Miss_Nobody89).`
            },
            {
                match: ['structure'],
                text: `Information about the program structure at ${name} has not been added yet. If you have reliable descriptions or source material to share, please contact [u/Miss_Nobody89](/u/Miss_Nobody89).`
            },
            {
                match: ['rules', 'punishments'],
                text: `Information about the rules, consequences, or disciplinary practices at ${name} has not been added yet. If you have reliable source material to share, please contact [u/Miss_Nobody89](/u/Miss_Nobody89).`
            },
            {
                match: ['abuse', 'neglect', 'lawsuits'],
                text: `Information about abuse allegations, neglect, or lawsuits involving ${name} has not been added yet. If you have reliable reports or source material to share, please contact [u/Miss_Nobody89](/u/Miss_Nobody89).`
            },
            {
                match: ['survivor testimonies', 'survivor testimony', 'testimonies', 'testimonials'],
                text: `No survivor testimonies for ${name} have been added here yet. If you have a firsthand account or reliable source material to share, please contact [u/Miss_Nobody89](/u/Miss_Nobody89).`
            },
            {
                match: ['related media'],
                text: `No related media links for ${name} have been added yet. If you have reliable external resources to share, please contact [u/Miss_Nobody89](/u/Miss_Nobody89).`
            }
        ];

        const categoryPlaceholder = placeholderByCategory.find((entry) =>
            entry.match.some((term) => lowerCategory.includes(term))
        );

        if (categoryPlaceholder) {
            return categoryPlaceholder.text;
        }

        if (lowerCategory.includes('media')) {
            return `No media coverage for ${name} has been added yet. If you have seen a news item about ${name} and would like to share it, please contact [u/Miss_Nobody89](/u/Miss_Nobody89).`;
        }

        return `Additional information about ${name} has not been added yet. If you have reliable updates or references to share, please contact [u/Miss_Nobody89](/u/Miss_Nobody89).`;
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
            const date = document.getElementById('testimonyDate')?.value.trim() || '';
            const type = document.getElementById('testimonyType')?.value || '';
            const quote = document.getElementById('testimonyQuote')?.value.trim() || '';
            const source = document.getElementById('testimonySource')?.value.trim() || '';
            const url = sanitizeUrl(document.getElementById('testimonyUrl')?.value || '');
            if (quote && source) {
                testimonies.push({ date, type, quote, source, url });
                renderList(testimonies, 'testimonyListOutput', item => `<strong>(${escapeHtml(item.type)})</strong> ${escapeHtml(item.quote.substring(0, 30))}... [${escapeHtml(item.source)}]`);
                clearInputs(['testimonyDate', 'testimonyQuote', 'testimonySource', 'testimonyUrl']);
            } else {
                alert('Please enter at least a quote and a source name.');
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
    // Add event listeners to entry type and core fields to detect organization mode changes
    const entryTypeField = document.getElementById('entryType');
    const programTypeField = document.getElementById('programType');
    const programNameField = document.getElementById('programName');

    // Function to detect if current entry is an organization and toggle form layout
    function detectAndToggleOrganizationMode() {
        const pageContainer = document.querySelector('.wiki-editor-page');
        if (!pageContainer) return;

        const explicitEntryType = entryTypeField?.value?.trim().toLowerCase() || '';
        const programType = programTypeField?.value?.trim() || '';
        const programName = programNameField?.value?.trim() || '';
        const inferredEntryType = inferEntryTypeFromData({
            entryType: explicitEntryType,
            programType,
            programName,
            cityState: document.getElementById('cityState')?.value?.trim() || '',
            mainAddress: document.getElementById('mainAddress')?.value?.trim() || '',
            headquarters: document.getElementById('headquarters')?.value?.trim() || '',
            parentCompany: document.getElementById('parentCompany')?.value?.trim() || '',
            relatedPrograms
        });

        isOrganizationEntry = explicitEntryType
            ? explicitEntryType === 'organization'
            : inferredEntryType === 'organization';

        // Toggle the organization-mode class
        const wasOrgMode = pageContainer.classList.contains('organization-mode');
        if (isOrganizationEntry) {
            pageContainer.classList.add('organization-mode');
            if (!wasOrgMode) {
                // Brief flash class so CSS can animate the transition
                pageContainer.classList.add('mode-switching');
                setTimeout(() => pageContainer.classList.remove('mode-switching'), 350);
            }
            // Update facilities list when switching to organization mode
            updateOrganizationFacilitiesList();
        } else {
            if (wasOrgMode) {
                pageContainer.classList.add('mode-switching');
                setTimeout(() => pageContainer.classList.remove('mode-switching'), 350);
            }
            pageContainer.classList.remove('organization-mode');
        }

        // Swap dynamic labels for fields shared between both modes
        document.querySelectorAll('[data-facility-label][data-org-label]').forEach(el => {
            el.textContent = isOrganizationEntry
                ? el.dataset.orgLabel
                : el.dataset.facilityLabel;
        });

        // Update mode badge
        const modeBadge = document.getElementById('entryModeBadge');
        if (modeBadge) {
            if (isOrganizationEntry) {
                modeBadge.textContent = '🏢 Organization Mode';
                modeBadge.className = 'entry-mode-badge mode-org';
            } else {
                modeBadge.textContent = '📋 Facility Mode';
                modeBadge.className = 'entry-mode-badge mode-facility';
            }
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

        const renderList = (programs) =>
            '<ul style="list-style:none; padding:0; margin:0 0 12px;">' +
            programs.map(f => `<li style="padding:6px 8px; margin:4px 0; background:#fff; border-left:4px solid #33A7B5; border-radius:3px;"><strong style="color:#000080;">${escapeHtml(f.name)}</strong></li>`).join('') +
            '</ul>';

        if (openPrograms.length === 0 && closedPrograms.length === 0) {
            const fallbackPrograms = extractTableLinks(markdown);
            if (fallbackPrograms.length === 0) {
                facilitiesList.innerHTML = '<p style="color: #999; font-style: italic;">No facilities found in the organization\'s wiki page.</p>';
                return;
            }

            facilitiesList.innerHTML = `<h4 style="margin:0 0 6px; color:#000435;">Programs (${fallbackPrograms.length})</h4>${renderList(fallbackPrograms)}`;
            return;
        }

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

    if (entryTypeField) {
        entryTypeField.addEventListener('input', () => {
            detectAndToggleOrganizationMode();
        });
        entryTypeField.addEventListener('change', () => {
            detectAndToggleOrganizationMode();
            updateMarkdownFromForm();
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

        const formData = collectCurrentFormData();
        const programName = formData.programName || '[Program Name]';

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

        // NOTE: unparsed/imported sections are now emitted by the generator
        // (threaded via formData.unparsedContent), positioned just before Related
        // Media. The old append-at-the-end of window.unparsedContentFromImport was
        // removed so it no longer lands after Related Media (or duplicates it).

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

        // Boilerplate placeholder paragraphs (e.g. "...has not been added yet.
        // If you have ... to share, please contact [u/Miss_Nobody89](/u/Miss_Nobody89).") are an
        // invitation to contribute, not statements of fact, so they must stay in
        // the present tense. Skip any line carrying the placeholder signature.
        const isPlaceholderLine = (line) =>
            /Miss_Nobody89|Signal-Strain9810|been added (?:here )?yet/i.test(line);

        const convertLine = (line) => {
            let updated = line;
            replacements.forEach(({ pattern, replacement }) => {
                updated = updated.replace(pattern, (match) => matchCaseReplacement(match, replacement));
            });
            return updated;
        };

        return text
            .split('\n')
            .map((line) => (isPlaceholderLine(line) ? line : convertLine(line)))
            .join('\n');
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
        setValue('entryType', inferEntryTypeFromData(parsedData));
        setValue('programName', parsedData.programName);
        setValue('yearsActive', parsedData.yearsActive);
        setValue('cityState', parsedData.cityState);
        setValue('programType', parsedData.programType);
        setValue('yearFounded', parsedData.yearFounded);
        setValue('headquarters', parsedData.headquarters);
        setValue('parentCompany', parsedData.parentCompany);
        setValue('parentCompanyLink', parsedData.parentCompanyLink);
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
        markAddressAsLoaded(parsedData.mainAddress, parsedData.addressLink);
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

        // These notes fields were just filled verbatim from the parsed markdown,
        // so flag them as imported. Without this, collectCurrentFormData() computes
        // *IsImported=false and the generator APPENDS the re-derived structured
        // prose to this same text, duplicating Abuse/Testimonies/Related Media on
        // every load. (populateFormFromParsedData does the same at its tail.)
        // The marker clears automatically once the user edits a field.
        APPEND_NOTE_FIELD_IDS.forEach(markFieldAsImported);

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
        affiliations = parsedData.affiliations || [];
        relatedPrograms = parsedData.relatedPrograms || [];

        // Render lists
        renderStaffList();

        if (punishments.length > 0) {
            renderList(punishments, 'punishmentListOutput', item => `<strong>${escapeHtml(item.name)}</strong> — ${escapeHtml(item.description || '').substring(0, 60)}...`);
        }

        if (rules.length > 0) {
            renderList(rules, 'ruleListOutput', item => escapeHtml(item.name || item));
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
                return `<strong>${dateStr}(${escapeHtml(item.type)})</strong> ${escapeHtml((item.quote || '').substring(0, 30))}... [${escapeHtml(item.source || 'Unknown')}]`;
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

        if (affiliations.length > 0) {
            renderList(affiliations, 'affiliationListOutput', item => {
                const linkPart = item.link ? ` (<a href="${escapeHtml(item.link)}" target="_blank">Link</a>)` : '';
                return `<strong>${escapeHtml(item.name)}</strong>${linkPart}`;
            });
        }

        if (relatedPrograms.length > 0) {
            renderList(relatedPrograms, 'relatedProgramsListOutput', item => {
                const healPart = item.healLink ? ` <a href="${escapeHtml(item.healLink)}" target="_blank">[HEAL]</a>` : '';
                return `<strong>${escapeHtml(item.name)}</strong> (${escapeHtml(item.yearsActive || '?')}) - ${escapeHtml(item.location || '?')}${healPart}`;
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

        detectAndToggleOrganizationMode();
        console.log('✓ Import complete!');
        console.log('Parsed data:', parsedData);
        return parsedData;
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

            const formData = collectCurrentFormData();
            const submissionPayload = {
                ...formData,
                organization: getOrganizationValueForSubmission(formData),
                generatedMarkdown: outputCode,
                originalMarkdown: importedMarkdown,
                // Record which wiki slug this submission is for so stub completion can
                // be matched precisely by slug instead of by (collision-prone) name.
                sourceSlug: currentEntrySlug || '',
                submittedBy: document.getElementById('submitterEmail')?.value || '',
                submissionNotes: document.getElementById('submissionNotes')?.value || ''
            };

            try {
                const response = await fetch(editorSettings.saveApi || '/wp-content/themes/child/api/save-wiki-submission.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify(submissionPayload)
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
                    const parsedEntryType = inferEntryTypeFromData(parsedData);
                    const submissionData = {
                        programName: parsedData.programName,
                        entryType: parsedEntryType,
                        yearsActive: parsedData.yearsActive || '',
                        cityState: parsedData.cityState || '',
                        programType: parsedData.programType || '',
                        yearFounded: parsedData.yearFounded || '',
                        headquarters: parsedData.headquarters || '',
                        parentCompany: parsedData.parentCompany || '',
                        parentCompanyLink: parsedData.parentCompanyLink || '',
                        ageRange: parsedData.ageRange || '',
                        capacity: parsedData.capacity || '',
                        ownerName: parsedData.ownerName || '',
                        ownerLink: parsedData.ownerLink || '',
                        avgStay: parsedData.avgStay || '',
                        tuition: parsedData.tuition || '',
                        natsapMember: parsedData.natsapMember || '',
                        natsapYear: parsedData.natsapYear || '',
                        historyNotes: parsedData.historyMisc || '',
                        levelSystemDesc: parsedData.levelSystemDesc || '',
                        structureMisc: parsedData.structureMisc || '',
                        punishmentsMisc: parsedData.punishmentsMisc || '',
                        lawsuitsMisc: parsedData.lawsuitsMisc || '',
                        mainAddress: parsedData.mainAddress || '',
                        addressLink: parsedData.addressLink || '',
                        accreditingBody: parsedData.accreditingBody || '',
                        accreditingBodyLink: parsedData.accreditingBodyLink || '',
                        mainComplaints: parsedData.mainComplaints || '',
                        mediaInfo: parsedData.mediaInfo || '',
                        testimoniesMisc: parsedData.testimoniesMisc || '',
                        relatedMediaMisc: parsedData.relatedMediaMisc || '',
                        rebrand: parsedData.rebrand || '',
                        rebrandLink: parsedData.rebrandLink || '',
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
                        programLevels: parsedData.programLevels || [],
                        affiliations: parsedData.affiliations || [],
                        relatedPrograms: parsedData.relatedPrograms || [],
                        selectedDiagnoses: parsedData.selectedDiagnoses || [],
                        customDiagnoses: parsedData.customDiagnoses || '',
                        selectedAllegations: parsedData.selectedAllegations || [],
                        customAllegations: parsedData.customAllegations || '',
                        organization: getOrganizationValueForSubmission({
                            ...parsedData,
                            entryType: parsedEntryType
                        }),
                        originalMarkdown: content,
                        // Store the FORMATTED generator output, not the raw source.
                        // (parsedData carries historyMisc + the *IsImported flags +
                        // unparsedContent, which the generator reads directly.)
                        generatedMarkdown: generateWikiMarkdown(parsedData),
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
    let browseMode = 'index'; // 'index' (location/org) or 'stubs' (aggregated empties)
    let allStubsCache = null; // [{ entry, state }] of every empty entry across all indexes

    // True when an entry's wiki slug is in the known-empty set (a stub).
    function getEntrySlug(entry, stateCode) {
        const slugFromUrl = getSlugFromEntryUrl(entry.url);
        if (slugFromUrl) return slugFromUrl;
        if (stateCode === 'CORPORATE') return normalizeToSlug(entry.normalizedName || entry.name || '');
        return '';
    }
    function isStubEntry(entry, stateCode) {
        const slug = getEntrySlug(entry, stateCode);
        return !!(emptySlugSet && slug && emptySlugSet.has(slug.toLowerCase()));
    }
    function buildGroupHeader(text, isStub) {
        const header = document.createElement('div');
        header.className = 'index-entry-group-header' + (isStub ? ' is-stub' : '');
        header.textContent = text;
        return header;
    }

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
        browseMode = 'index';
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

    // Build a single facility row. globalIndex/orderedList drive Prev/Next nav.
    function buildEntryRow(entry, globalIndex, orderedList, isStub) {
        const row = document.createElement('div');
        row.className = 'index-entry-row';

        const slugFromUrl = getSlugFromEntryUrl(entry.url);
        const entryName = entry.name || entry.normalizedName || 'Unnamed program';

        const nameSpan = document.createElement('span');
        nameSpan.className = 'index-entry-name';
        nameSpan.appendChild(document.createTextNode(entryName));

        const actions = document.createElement('div');
        actions.className = 'index-entry-actions';

        if (selectedIndexState === 'CORPORATE') {
            // Organizations show "View Programs" and allow editing the index page.
            // (loadOrgProgramsFromDatabase already opens an empty form for empty orgs.)
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
                currentNavList = orderedList;
                currentNavIndex = globalIndex;
                updateNavButtons();
                loadOrganizationIndexEntry(entry, editIndexBtn);
            });
            actions.appendChild(editIndexBtn);
        } else if (isStub) {
            // Not yet created: open a fresh empty form prefilled with the name.
            const createButton = document.createElement('button');
            createButton.type = 'button';
            createButton.textContent = 'Create';
            createButton.className = 'edit-entry-btn';
            createButton.addEventListener('click', (e) => {
                e.preventDefault();
                createStubEntry(entry, selectedIndexState, createButton);
            });
            actions.appendChild(createButton);
        } else {
            const editButton = document.createElement('button');
            editButton.type = 'button';
            editButton.textContent = 'Edit';
            editButton.className = 'edit-entry-btn';
            editButton.addEventListener('click', (e) => {
                e.preventDefault();
                currentNavType = 'index';
                currentNavList = orderedList;
                currentNavIndex = globalIndex;
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
        return row;
    }

    function renderIndexEntries() {
        if (!indexEntriesList) return;
        if (browseMode === 'stubs') { renderAllStubs(); return; }
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

        // Separate created entries from stubs (not yet created). Keep one combined
        // order so Prev/Next navigation still walks the whole visible list.
        const created = [];
        const stubs = [];
        matches.forEach(entry => {
            (isStubEntry(entry, selectedIndexState) ? stubs : created).push(entry);
        });
        const ordered = [...created, ...stubs];

        indexEntriesList.innerHTML = '';
        if (created.length) {
            indexEntriesList.appendChild(buildGroupHeader(`Created (${created.length})`));
            created.forEach((entry, i) => {
                indexEntriesList.appendChild(buildEntryRow(entry, i, ordered, false));
            });
        }
        if (stubs.length) {
            indexEntriesList.appendChild(buildGroupHeader(`Not yet created (${stubs.length})`, true));
            stubs.forEach((entry, i) => {
                indexEntriesList.appendChild(buildEntryRow(entry, created.length + i, ordered, true));
            });
        }
    }

    // Open a blank form for an entry that hasn't been written yet.
    function createStubEntry(entry, stateCode, button) {
        const name = entry.name || entry.normalizedName || '';
        const entryType = stateCode === 'CORPORATE' ? 'organization' : 'facility';
        const slug = getEntrySlug(entry, stateCode);
        if (button) button.disabled = true;
        showEmptyBanner(name);

        // Organization creates should carry over the currently loaded facility
        // list so the parent-organization table doesn't disappear when a new
        // page is opened from the org browser.
        if (entryType === 'organization' && selectedIndexState === 'ORG_PROGRAMS' && Array.isArray(currentIndexPrograms)) {
            relatedPrograms = currentIndexPrograms
                .map(program => ({
                    name: program.name || program.normalizedName || '',
                    link: program.url || '',
                    yearsActive: program.yearsActive || '',
                    location: program.location || '',
                    healLink: program.healLink || '',
                    reopened: program.reopened || ''
                }))
                .filter(program => program.name);
        }

        clearFormToEmpty(name, { entryType });
        importedMarkdown = '';
        updateMarkdownFromForm();
        finalizeEntryLoad(name, { noScroll: true, source: 'index', sourceSlug: slug });
        document.getElementById('emptyEntryBanner')?.scrollIntoView({ behavior: 'smooth', block: 'center' });
        if (button) button.disabled = false;
    }

    // Aggregate every empty entry across all state/org indexes into one to-do list.
    async function loadAllStubs() {
        browseMode = 'stubs';
        setIndexSearchEnabled(true);

        updateIndexEntriesMessage('Scanning every index for entries that still need to be created...');
        try {
            // Re-fetch each time the tab opens so entries that were just submitted
            // move into the Completed group without a full page reload.
            await loadEmptySlugMapping();
            if (!allIndexData) { await initializeIndexes(); }

            const stateCodes = Object.keys((allIndexData && allIndexData.states) || {});
            const collected = [];
            const nameToSlugs = new Map(); // normalized name -> Set of distinct slugs
            await Promise.all(stateCodes.map(async (code) => {
                let programs = stateProgramsCache[code];
                if (!programs) {
                    try {
                        const resp = await fetch(`${wikiProgramsBase}programs-${code}.json`, { cache: 'no-cache' });
                        programs = resp.ok ? ((await resp.json()).programs || []) : [];
                        stateProgramsCache[code] = programs;
                    } catch (e) {
                        programs = [];
                    }
                }
                programs.forEach(entry => {
                    // Track every entry's name->slug so we know which names are ambiguous.
                    const nm = normalizeEntryName(entry.name || entry.normalizedName);
                    const eslug = getEntrySlug(entry, code);
                    if (nm && eslug) {
                        if (!nameToSlugs.has(nm)) nameToSlugs.set(nm, new Set());
                        nameToSlugs.get(nm).add(eslug.toLowerCase());
                    }
                    if (isStubEntry(entry, code)) collected.push({ entry, state: code });
                });
            }));

            // Names that resolve to more than one slug can't be completion-matched by
            // name alone (e.g. "Hyde School" -> hydeme + hydect).
            ambiguousStubNames = new Set();
            nameToSlugs.forEach((slugs, nm) => { if (slugs.size > 1) ambiguousStubNames.add(nm); });

            collected.sort((a, b) => (a.entry.name || '').localeCompare(b.entry.name || ''));
            allStubsCache = collected;
            renderAllStubs();
        } catch (error) {
            updateIndexEntriesMessage(`Unable to load stubs: ${error.message || 'Unknown error'}`, 'error');
        }
    }

    // A stub counts as "completed" once a submission for its specific slug exists.
    // Preference order:
    //   1. Slug match — precise; works for submissions saved with a sourceSlug.
    //   2. Name match — fallback for older submissions that predate slug tracking,
    //      BUT only when the name is unambiguous (maps to a single slug). For names
    //      shared across multiple slugs (Hyde School, Eckerd, etc.) a name match
    //      can't prove THIS slug is done, so we require the slug match.
    function isStubCompleted(entry, stateCode) {
        const slug = getEntrySlug(entry, stateCode);
        if (slug && completedSlugsSet && completedSlugsSet.has(slug.toLowerCase())) return true;
        const nm = normalizeEntryName(entry.name || entry.normalizedName);
        if (!nm) return false;
        if (ambiguousStubNames.has(nm)) return false;
        return !!(completedNamesSet && completedNamesSet.has(nm));
    }

    function buildStubRow(entry, state, completed) {
        const row = document.createElement('div');
        row.className = 'index-entry-row' + (completed ? ' stub-completed' : '');

        const nameSpan = document.createElement('span');
        nameSpan.className = 'index-entry-name';
        if (completed) {
            const check = document.createElement('span');
            check.className = 'stub-done-check';
            check.textContent = '✓ ';
            nameSpan.appendChild(check);
        }
        nameSpan.appendChild(document.createTextNode(entry.name || entry.normalizedName || 'Unnamed program'));
        const stateTag = document.createElement('span');
        stateTag.className = 'index-entry-state';
        stateTag.textContent = ` (${STATE_DISPLAY_NAMES[state] || state})`;
        nameSpan.appendChild(stateTag);

        const actions = document.createElement('div');
        actions.className = 'index-entry-actions';
        const actionBtn = document.createElement('button');
        actionBtn.type = 'button';
        actionBtn.className = 'edit-entry-btn';
        if (completed) {
            // Already saved — open the existing submission for editing.
            actionBtn.textContent = 'Edit';
            actionBtn.addEventListener('click', (e) => { e.preventDefault(); loadEntryFromReddit(entry, actionBtn); });
        } else {
            actionBtn.textContent = 'Create';
            actionBtn.addEventListener('click', (e) => { e.preventDefault(); createStubEntry(entry, state, actionBtn); });
        }
        actions.appendChild(actionBtn);

        const slug = getEntrySlug(entry, state);
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
        return row;
    }

    function renderAllStubs() {
        if (!indexEntriesList) return;
        if (!allStubsCache) { updateIndexEntriesMessage('Loading stubs...'); return; }

        const filter = (indexSearch?.value || '').trim().toLowerCase();
        const matches = filter
            ? allStubsCache.filter(({ entry, state }) => {
                  const target = `${entry.name || ''} ${entry.normalizedName || ''} ${STATE_DISPLAY_NAMES[state] || state}`.toLowerCase();
                  return target.includes(filter);
              })
            : allStubsCache;

        if (!matches.length) {
            updateIndexEntriesMessage(filter ? 'No stubs match that filter.' : 'No entries need creation. 🎉');
            return;
        }

        const needs = matches.filter(m => !isStubCompleted(m.entry, m.state));
        const done = matches.filter(m => isStubCompleted(m.entry, m.state));

        indexEntriesList.innerHTML = '';

        const summary = document.createElement('div');
        summary.className = 'stub-progress-summary';
        summary.textContent = `${done.length} completed · ${needs.length} still need creation`;
        indexEntriesList.appendChild(summary);

        if (needs.length) {
            indexEntriesList.appendChild(buildGroupHeader(`Needs creation (${needs.length})`, true));
            needs.forEach(({ entry, state }) => indexEntriesList.appendChild(buildStubRow(entry, state, false)));
        }
        if (done.length) {
            indexEntriesList.appendChild(buildGroupHeader(`Completed (${done.length})`));
            done.forEach(({ entry, state }) => indexEntriesList.appendChild(buildStubRow(entry, state, true)));
        }
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
                        clearFormToEmpty(orgName, { entryType: 'organization' });
                        importedMarkdown = '';
                        updateMarkdownFromForm();
                        // Hide browser but do not auto-scroll to the form; scroll banner into view instead
                        finalizeEntryLoad(orgName, { noScroll: true, source: 'index', sourceSlug: orgSlug });
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
                         clearFormToEmpty(orgName, { entryType: 'organization' });
                         importedMarkdown = '';
                         updateMarkdownFromForm();
                         finalizeEntryLoad(orgName, { noScroll: true, source: 'index', sourceSlug: normalizeToSlug(orgName || '') });
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
        setEntryTypeValue('facility');
        const _pageContainer = document.querySelector('.wiki-editor-page');
        if (_pageContainer) _pageContainer.classList.remove('organization-mode');

        try {
            const response = await fetch(`/wp-content/themes/child/api/save-wiki-submission.php?id=${encodeURIComponent(entryId)}`);
            const result = await response.json();

            if (!result.success) {
                throw new Error(result.error || 'Failed to load entry');
            }

            const entry = result.data;
            // Preserve the slug recorded when this submission was first saved so a
            // re-save keeps matching by slug rather than reverting to name-only.
            const savedSlug = (entry.json_data && entry.json_data.sourceSlug)
                ? String(entry.json_data.sourceSlug).toLowerCase()
                : (entry.slug ? String(entry.slug).toLowerCase() : '');
            currentEntrySlug = savedSlug;
            // If this entry corresponds to a known-empty wiki slug, show banner and load an empty form
            try {
                const candidateName = entry.program_name || entry.programName || '';
                const slug = savedSlug || entry.slug || normalizeToSlug(candidateName);
                if (emptySlugSet && slug && emptySlugSet.has(slug.toLowerCase())) {
                    console.log('Detected empty wiki slug for', slug);
                    showEmptyBanner(candidateName || '');
                    clearFormToEmpty(candidateName || '', {
                        entryType: inferEntryTypeFromData({
                            ...(entry.json_data || {}),
                            programName: candidateName,
                            programType: entry.program_type || '',
                            cityState: entry.city_state || ''
                        })
                    });
                    importedMarkdown = '';
                    updateMarkdownFromForm();
                    finalizeEntryLoad(candidateName || entry.program_name, { source: 'index', sourceSlug: slug });
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
                applyStoredEntryMetadata(entry.json_data || {});
                
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
