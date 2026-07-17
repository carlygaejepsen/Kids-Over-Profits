(function() {
    if (window.KOP_UI_Events) {
        return;
    }

    // Depends on KOP_UI_Actions for some button clicks
    const KOP_UI_Actions = window.KOP_UI_Actions || {};

    // "Years of Operation" is a derived, read-only field: it always mirrors the
    // Opened/Closed year pickers so the three never disagree. This must produce
    // exactly the same string the REST layer builds from startYear/endYear
    // (see kop_state_collect_programs in inc/rest-api.php): "1994–2010" / "1994" / "–2010".
    window.kopFormatYearsOfOperation = function (startYear, endYear) {
        const s = (startYear === null || startYear === undefined) ? '' : String(startYear).trim();
        const e = (endYear === null || endYear === undefined) ? '' : String(endYear).trim();
        if (s !== '' && e !== '') return s + '–' + e; // en-dash, matches the PHP
        if (s !== '') return s;
        if (e !== '') return '–' + e;
        return '';
    };

    // Parse a legacy free-text "Years of Operation" string into {startYear, endYear}.
    // Mirrors migrate_parse_years() in api/migrate-operating-years.php. Returns null
    // when it can't parse confidently (e.g. multi-stint), so we never guess wrong.
    window.kopParseYearsOfOperation = function (text, status) {
        const t = String(text || '').trim();
        if (!t) return null;
        const norm = t.replace(/[–—]/g, '-'); // en/em dash -> hyphen
        const years = (norm.match(/\d{4}/g) || []).map(function (y) { return parseInt(y, 10); });
        if (years.length > 2) return null; // multi-stint / complex -> leave for manual
        const openEnded = /(present|current|now|ongoing|open)\s*$/i.test(norm) || /\d{4}\s*-\s*$/.test(norm);
        if (years.length === 2 && /\d{4}\s*-\s*\d{4}/.test(norm)) {
            return { startYear: years[0], endYear: years[1] };
        }
        if (years.length === 1) {
            if (/^\s*-\s*\d{4}/.test(norm)) return { startYear: null, endYear: years[0] };   // "-2010"
            if (openEnded) return { startYear: years[0], endYear: null };                      // "2010-present"
            const closed = ['closed', 'shut down', 'shutdown', 'defunct'].indexOf(String(status || '').trim().toLowerCase()) !== -1;
            return closed ? { startYear: null, endYear: years[0] } : { startYear: years[0], endYear: null };
        }
        return null;
    };

    // Regenerate facilities[idx].operatingPeriod.yearsOfOperation from the two
    // year pickers and reflect it in the (read-only) input. On edits we always
    // derive (so clearing both pickers clears the text); on load we pass
    // preserveLegacy so records that only have freeform years — no pickers — are
    // left untouched.
    window.kopSyncYearsOfOperation = function (facilityIndex, opts) {
        opts = opts || {};
        if (!window.formData || !Array.isArray(window.formData.facilities)) return;
        const idx = (typeof facilityIndex === 'number') ? facilityIndex : (window.currentFacilityIndex || 0);
        const facility = window.formData.facilities[idx];
        if (!facility || !facility.operatingPeriod) return;
        const op = facility.operatingPeriod;
        const hasPickers = (op.startYear !== '' && op.startYear != null) || (op.endYear !== '' && op.endYear != null);
        if (!hasPickers && opts.preserveLegacy) return;
        const derived = window.kopFormatYearsOfOperation(op.startYear, op.endYear);
        op.yearsOfOperation = derived;
        const input = document.querySelector('.facility-field[data-field="operatingPeriod.yearsOfOperation"]');
        if (input) input.value = derived;
    };

    // Whichever per-field input handler wins the shared `listenerAttached` guard
    // (this module's or the legacy data-form.v4.js one) writes startYear/endYear
    // during the target phase; this delegated bubble-phase listener then re-derives
    // Years of Operation and persists it — so the sync fires no matter which won.
    document.addEventListener('input', function (e) {
        const f = e.target;
        if (!f || !f.dataset) return;
        const path = f.dataset.field;
        if (path !== 'operatingPeriod.startYear' && path !== 'operatingPeriod.endYear') return;
        window.kopSyncYearsOfOperation(window.currentFacilityIndex || 0);
        if (typeof window.updateJSON === 'function') window.updateJSON();
        if (typeof window.autoSave === 'function') window.autoSave();
    });

    const STANDALONE_FIELD_BINDINGS = {
        'operator-name': {
            getTarget: () => window.formData,
            path: 'operator.name'
        },
        'operator-current-name': {
            getTarget: () => window.formData,
            path: 'operator.currentName'
        },
        'operator-location-city': {
            getTarget: () => window.formData,
            path: 'operator.locationCity',
            afterUpdate: () => {
                const resolved = typeof window.resolvePathTarget === 'function' ? window.resolvePathTarget('operator.location') : null;
                const operatorTarget = resolved?.target || window.formData?.operator;
                if (operatorTarget) {
                    operatorTarget.location = window.combineCityState(
                        operatorTarget.locationCity,
                        operatorTarget.locationState
                    );
                }
            }
        },
        'operator-location-state': {
            getTarget: () => window.formData,
            path: 'operator.locationState',
            afterUpdate: () => {
                const resolved = typeof window.resolvePathTarget === 'function' ? window.resolvePathTarget('operator.location') : null;
                const operatorTarget = resolved?.target || window.formData?.operator;
                if (operatorTarget) {
                    operatorTarget.location = window.combineCityState(
                        operatorTarget.locationCity,
                        operatorTarget.locationState
                    );
                }
            }
        },
        'operator-headquarters-city': {
            getTarget: () => window.formData,
            path: 'operator.headquartersCity',
            afterUpdate: () => {
                const resolved = typeof window.resolvePathTarget === 'function' ? window.resolvePathTarget('operator.headquarters') : null;
                const operatorTarget = resolved?.target || window.formData?.operator;
                if (operatorTarget) {
                    operatorTarget.headquarters = window.combineCityState(
                        operatorTarget.headquartersCity,
                        operatorTarget.headquartersState
                    );
                }
            }
        },
        'operator-headquarters-state': {
            getTarget: () => window.formData,
            path: 'operator.headquartersState',
            afterUpdate: () => {
                const resolved = typeof window.resolvePathTarget === 'function' ? window.resolvePathTarget('operator.headquarters') : null;
                const operatorTarget = resolved?.target || window.formData?.operator;
                if (operatorTarget) {
                    operatorTarget.headquarters = window.combineCityState(
                        operatorTarget.headquartersCity,
                        operatorTarget.headquartersState
                    );
                }
            }
        },
        'operator-founded': {
            getTarget: () => window.formData,
            path: 'operator.founded'
        },
        'operator-period': {
            getTarget: () => window.formData,
            path: 'operator.operatingPeriod'
        },
        'operator-status': {
            getTarget: () => window.formData,
            path: 'operator.status'
        },
        'operator-ceo': {
            getTarget: () => window.formData,
            path: 'operator.keyStaff.ceo'
        },
        'operator-notes': {
            getTarget: () => window.formData,
            path: 'operator.notes'
        },
        'facility-name': {
            getTarget: () => window.formData,
            path: () => `facilities.${window.currentFacilityIndex || 0}.identification.name`,
            afterUpdate: () => {
                if (typeof window.updateAllUI === 'function') {
                    window.updateAllUI();
                }
            }
        },
        'facility-type': {
            getTarget: () => window.formData,
            path: () => `facilities.${window.currentFacilityIndex || 0}.facilityDetails.type`
        }
    };

    function normalizeInputValue(field) {
        if (field.type === 'number') {
            return field.value === '' ? null : parseInt(field.value, 10);
        }

        return field.value;
    }

    function setValueAtPath(target, path, value) {
        if (!target || !path) {
            return;
        }

        if (typeof window.setNestedValue === 'function') {
            window.setNestedValue(target, path, value);
            return;
        }

        const parts = path.split('.');
        let current = target;
        for (let i = 0; i < parts.length - 1; i++) {
            if (!current[parts[i]] || typeof current[parts[i]] !== 'object') {
                current[parts[i]] = {};
            }
            current = current[parts[i]];
        }
        current[parts[parts.length - 1]] = value;
    }

    function ensureCurrentFacility() {
        if (!window.formData) {
            return;
        }

        const facilityIndex = window.currentFacilityIndex || 0;
        if (!Array.isArray(window.formData.facilities)) {
            window.formData.facilities = [];
        }
        if (!window.formData.facilities[facilityIndex]) {
            const defaultFacility = window.KOP_Project?.createNewProjectData?.().facilities?.[0] || {};
            window.formData.facilities[facilityIndex] = window.deepClone ? window.deepClone(defaultFacility) : defaultFacility;
        }
    }

    function persistStandaloneField(binding, field) {
        if (!binding || !window.formData) {
            return;
        }

        ensureCurrentFacility();

        const rawPath = typeof binding.path === 'function' ? binding.path() : binding.path;
        let target = typeof binding.getTarget === 'function' ? binding.getTarget() : window.formData;
        let path = rawPath;
        const value = normalizeInputValue(field);

        if (
            typeof rawPath === 'string' &&
            /^operator\./.test(rawPath) &&
            typeof window.resolvePathTarget === 'function'
        ) {
            const resolved = window.resolvePathTarget(rawPath);
            if (resolved && resolved.target && resolved.normalizedPath) {
                target = resolved.target;
                path = resolved.normalizedPath;
            }
        }

        setValueAtPath(target, path, value);

        if (typeof binding.afterUpdate === 'function') {
            binding.afterUpdate(value, field);
        }

        if (typeof window.updateJSON === 'function') {
            window.updateJSON();
        }
        if (typeof window.autoSave === 'function') {
            window.autoSave();
        }
    }

    function initializeSectionToggles() {
        const sections = document.querySelectorAll('.section');
        sections.forEach(section => {
            if (section.dataset.toggleInit === 'true') return;
            section.dataset.toggleInit = 'true';
            const header = section.querySelector('.section-header');
            const toggle = section.querySelector('.section-toggle');
            const content = section.querySelector('.section-content');
            if (!header || !toggle || !content) return;

            toggle.setAttribute('role', 'button');
            toggle.setAttribute('tabindex', '0');
            const setState = (expanded) => {
                section.classList.toggle('expanded', expanded);
                content.style.display = expanded ? 'block' : 'none';
                toggle.setAttribute('aria-expanded', expanded.toString());
                toggle.setAttribute('title', expanded ? 'Collapse section' : 'Expand section');
            };
            const isMobile = window.innerWidth <= 768;
            const shouldExpand = isMobile ? false : section.classList.contains('expanded');
            setState(shouldExpand);
            const handleToggle = (event) => {
                event.preventDefault();
                event.stopPropagation();
                setState(!section.classList.contains('expanded'));
            };
            toggle.addEventListener('click', handleToggle, { passive: false });
            header.addEventListener('click', (event) => {
                if (event.target.closest('.section-toggle')) return;
                handleToggle(event);
            });
        });
        initializeMobileSectionControls();
    }

    function initializeSubSectionToggles() {
        const subSections = document.querySelectorAll('.sub-section');
        subSections.forEach(subSection => {
            if (subSection.dataset.toggleInit === 'true') return;
            subSection.dataset.toggleInit = 'true';
            const header = subSection.querySelector('.sub-section-header');
            const toggle = subSection.querySelector('.sub-section-toggle');
            const content = subSection.querySelector('.sub-section-content');
            if (!header || !toggle || !content) return;

            toggle.setAttribute('role', 'button');
            toggle.setAttribute('tabindex', '0');
            const setState = (expanded) => {
                subSection.classList.toggle('expanded', expanded);
                content.style.display = expanded ? 'block' : 'none';
                toggle.setAttribute('aria-expanded', expanded.toString());
            };
            
            const shouldExpand = subSection.classList.contains('expanded');
            setState(shouldExpand);
            
            const handleToggle = (event) => {
                event.preventDefault();
                event.stopPropagation();
                setState(!subSection.classList.contains('expanded'));
            };
            
            toggle.addEventListener('click', handleToggle, { passive: false });
            header.addEventListener('click', (event) => {
                if (event.target.closest('.sub-section-toggle')) return;
                handleToggle(event);
            });
        });
    }

    function initializeMobileSectionControls() {
        if (window.innerWidth > 768 || document.querySelector('.mobile-section-controls')) return;
        const controlsBar = document.createElement('div');
        controlsBar.className = 'mobile-section-controls';
        controlsBar.innerHTML = `<span class="section-control-label">📋 Sections</span><div class="section-control-btns"><button class="btn-section-control" id="expand-all-sections">Expand All</button><button class="btn-section-control" id="collapse-all-sections">Collapse All</button></div>`;
        const facilityWrapper = document.getElementById('facility-main-wrapper');
        const referrerWrapper = document.getElementById('referrer-main-wrapper');
        const transporterWrapper = document.getElementById('transporter-main-wrapper');
        if (facilityWrapper && facilityWrapper.offsetParent !== null) {
            facilityWrapper.insertBefore(controlsBar.cloneNode(true), facilityWrapper.firstChild);
        }
        if (referrerWrapper) {
            referrerWrapper.insertBefore(controlsBar.cloneNode(true), referrerWrapper.firstChild);
        }
        if (transporterWrapper) {
            transporterWrapper.insertBefore(controlsBar.cloneNode(true), transporterWrapper.firstChild);
        }
        document.addEventListener('click', (e) => {
            if (e.target.id === 'expand-all-sections' || e.target.closest('#expand-all-sections')) {
                e.preventDefault();
                expandAllSections();
            }
            if (e.target.id === 'collapse-all-sections' || e.target.closest('#collapse-all-sections')) {
                e.preventDefault();
                collapseAllSections();
            }
        });
    }

    function expandAllSections() {
        document.querySelectorAll('.section:not(.view-hidden)').forEach(section => {
            const content = section.querySelector('.section-content');
            const toggle = section.querySelector('.section-toggle');
            if (content) {
                section.classList.add('expanded');
                content.style.display = 'block';
                if (toggle) {
                    toggle.setAttribute('aria-expanded', 'true');
                    toggle.setAttribute('title', 'Collapse section');
                }
            }
        });
    }

    function collapseAllSections() {
        document.querySelectorAll('.section:not(.view-hidden):not(#submission-section):not(#referrer-submission-section):not(#transporter-submission-section):not(#advanced-mode-section)').forEach(section => {
            const content = section.querySelector('.section-content');
            const toggle = section.querySelector('.section-toggle');
            if (content) {
                section.classList.remove('expanded');
                content.style.display = 'none';
                if (toggle) {
                    toggle.setAttribute('aria-expanded', 'false');
                    toggle.setAttribute('title', 'Expand section');
                }
            }
        });
    }

    function attachFieldListeners() {
        // Profit Status Badge Toggle Logic
        const profitBadge = document.getElementById('profit-status-badge');
        const profitInput = document.getElementById('profit-status-input');

        if (profitBadge && profitInput) {
            const updateProfitUI = (status) => {
                if (status === 'non-profit') {
                    profitBadge.textContent = 'Non-Profit';
                    profitBadge.classList.add('non-profit');
                } else {
                    profitBadge.textContent = 'For-Profit';
                    profitBadge.classList.remove('non-profit');
                }
            };

            profitBadge.addEventListener('click', () => {
                const currentStatus = profitInput.value;
                const newStatus = currentStatus === 'for-profit' ? 'non-profit' : 'for-profit';

                profitInput.value = newStatus;
                updateProfitUI(newStatus);

                // Sync with formData
                if (window.formData && window.formData.operator) {
                    window.formData.operator.profitStatus = newStatus;
                    if (typeof window.updateJSON === 'function') window.updateJSON();
                    if (typeof window.autoSave === 'function') window.autoSave();
                }

                // Trigger label update if needed
                if (window.KOP_UI_State && typeof window.KOP_UI_State.updateLabelsForProjectType === 'function') {
                    window.KOP_UI_State.updateLabelsForProjectType();
                }
            });

            // Initialize display from input value
            updateProfitUI(profitInput.value);
        }

        // Referrer field listeners - delegate to referrer-form.js module
        if (typeof window.attachReferrerFieldListeners === 'function') {
            window.attachReferrerFieldListeners();
        }

        // Consultant navigation - delegate to referrer-form.js module
        if (typeof window.initializeConsultantNavigation === 'function') {
            window.initializeConsultantNavigation();
        }

        // Consultants TOC toggle - delegate to referrer-form.js module
        if (typeof window.initializeConsultantsTocToggle === 'function') {
            window.initializeConsultantsTocToggle();
        }

        // Transporter field listeners - delegate to transporter-form.js module
        if (typeof window.attachTransporterFieldListeners === 'function') {
            window.attachTransporterFieldListeners();
        }

        // Transporter navigation - delegate to transporter-form.js module
        if (typeof window.initializeTransporterNavigation === 'function') {
            window.initializeTransporterNavigation();
        }

        // Transporters TOC toggle - delegate to transporter-form.js module
        if (typeof window.initializeTransportersTocToggle === 'function') {
            window.initializeTransportersTocToggle();
        }

        // Facility field listeners
        document.querySelectorAll('.facility-field').forEach(field => {
            if (!field.dataset.listenerAttached) {
                field.addEventListener('input', () => {
                    const path = field.dataset.field;
                    const value = normalizeInputValue(field);

                    if (path && window.formData) {
                        const facilityIndex = window.currentFacilityIndex || 0;
                        ensureCurrentFacility();
                        setValueAtPath(window.formData, `facilities.${facilityIndex}.${path}`, value);

                        if (typeof window.updateJSON === 'function') window.updateJSON();
                        if (typeof window.autoSave === 'function') window.autoSave();
                    }
                }, { passive: true });
                field.dataset.listenerAttached = 'true';
            }
        });

        Object.entries(STANDALONE_FIELD_BINDINGS).forEach(([id, binding]) => {
            const field = document.getElementById(id);
            if (!field || field.dataset.listenerAttached) {
                return;
            }

            field.addEventListener('input', () => {
                persistStandaloneField(binding, field);
            }, { passive: true });
            field.dataset.listenerAttached = 'true';
        });
    }

    function attachButtonListeners() {
        // Resolve handlers at attach time from the live global — the parse-time
        // `KOP_UI_Actions` snapshot above is {} when this file loads before
        // ui-actions.js, which would silently bind undefined handlers.
        const actions = window.KOP_UI_Actions || KOP_UI_Actions || {};
        const facilityButtons = {
            'add-facility-btn': actions.addFacility,
            'add-facility-main-btn': actions.addFacility,
            'remove-facility-btn': actions.removeFacility,
            'clone-facility-btn': actions.cloneFacility,
            'prev-facility-btn': actions.previousFacility,
            'next-facility-btn': actions.nextFacility,
            'sort-facilities-btn': actions.sortFacilities
        };

        Object.keys(facilityButtons).forEach(id => {
            const btn = document.getElementById(id);
            if (btn && typeof facilityButtons[id] === 'function' && !btn.dataset.listenerAttached) {
                btn.addEventListener('click', facilityButtons[id]);
                btn.dataset.listenerAttached = 'true';
            }
        });

        // Other button listeners can be added here
    }

    function initializeOverviewTabSwitching() {
        const initializeActiveTabOverview = () => {
            setTimeout(() => {
                const activeTab = document.querySelector('.category-tab.active');
                if (activeTab) {
                    if (activeTab.dataset.category === 'referrers' && typeof window.updateConsultantsUI === 'function') {
                        window.updateConsultantsUI();
                    } else if (activeTab.dataset.category === 'transporters' && typeof window.updateTransportersUI === 'function') {
                        window.updateTransportersUI();
                    } else if (activeTab.dataset.category === 'locations' && typeof window.updateLocationFacilitiesOverview === 'function') {
                        window.updateLocationFacilitiesOverview();
                    }
                }
            }, 100);
        };
        window.addEventListener('load', initializeActiveTabOverview);
        document.addEventListener('click', function(e) {
            const tab = e.target.closest('.category-tab');
            if (tab) {
                setTimeout(() => {
                    if (tab.dataset.category === 'referrers') {
                        if (typeof window.updateConsultantsUI === 'function') window.updateConsultantsUI();
                    } else if (tab.dataset.category === 'transporters') {
                        if (typeof window.updateTransportersUI === 'function') window.updateTransportersUI();
                    } else if (tab.dataset.category === 'locations') {
                        if (typeof window.updateLocationFacilitiesOverview === 'function') window.updateLocationFacilitiesOverview();
                    }
                }, 100);
            }
        });
    }

    window.KOP_UI_Events = {
        initializeSectionToggles,
        initializeSubSectionToggles,
        initializeMobileSectionControls,
        expandAllSections,
        collapseAllSections,
        attachFieldListeners,
        attachButtonListeners,
        initializeOverviewTabSwitching
    };
})();
