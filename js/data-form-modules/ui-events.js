(function() {
    if (window.KOP_UI_Events) {
        return;
    }

    // Depends on KOP_UI_Actions for some button clicks
    const KOP_UI_Actions = window.KOP_UI_Actions || {};

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
        if (facilityWrapper && facilityWrapper.offsetParent !== null) {
            facilityWrapper.insertBefore(controlsBar.cloneNode(true), facilityWrapper.firstChild);
        }
        if (referrerWrapper) {
            referrerWrapper.insertBefore(controlsBar.cloneNode(true), referrerWrapper.firstChild);
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
        document.querySelectorAll('.section:not(.view-hidden):not(#submission-section):not(#referrer-submission-section):not(#advanced-mode-section)').forEach(section => {
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
    }

    function attachButtonListeners() {
        const facilityButtons = {
            'add-facility-btn': KOP_UI_Actions.addFacility,
            'add-facility-main-btn': KOP_UI_Actions.addFacility,
            'remove-facility-btn': KOP_UI_Actions.removeFacility,
            'clone-facility-btn': KOP_UI_Actions.cloneFacility,
            'prev-facility-btn': KOP_UI_Actions.previousFacility,
            'next-facility-btn': KOP_UI_Actions.nextFacility,
            'sort-facilities-btn': KOP_UI_Actions.sortFacilities
        };

        Object.keys(facilityButtons).forEach(id => {
            const btn = document.getElementById(id);
            if (btn && !btn.dataset.listenerAttached) {
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