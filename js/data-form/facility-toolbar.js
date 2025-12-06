(function(window) {
    const log = (...args) => {
        if (typeof window.debugLog === 'function') {
            window.debugLog(...args);
        }
    };

    const getRemoveButton = () =>
        document.getElementById('remove-facility-btn-toolbar') ||
        document.getElementById('delete-facility-btn-toolbar'); // Legacy ID in template

    const getElements = () => ({
        toolbar: document.getElementById('fixed-toolbar'),
        toolbarToggle: document.getElementById('toolbar-toggle-btn'),
        toolbarContent: document.getElementById('toolbar-content'),
        dropdown: document.getElementById('facility-dropdown'),
        projectName: document.getElementById('toolbar-project-name'),
        prevBtn: document.getElementById('prev-facility-btn-toolbar'),
        nextBtn: document.getElementById('next-facility-btn-toolbar'),
        addBtn: document.getElementById('add-facility-btn-toolbar'),
        scrollTopBtn: document.getElementById('scroll-to-top-btn-toolbar'),
        removeBtn: getRemoveButton()
    });

    const applyToolbarState = (isMinimized, elements) => {
        const { toolbarContent, toolbarToggle } = elements;
        document.body.classList.toggle('toolbar-minimized', isMinimized);
        if (toolbarContent) {
            toolbarContent.setAttribute('aria-hidden', isMinimized ? 'true' : 'false');
        }
        if (toolbarToggle) {
            toolbarToggle.textContent = isMinimized ? '▼' : '−';
            toolbarToggle.title = isMinimized ? 'Expand toolbar' : 'Minimize toolbar';
            toolbarToggle.setAttribute('aria-expanded', isMinimized ? 'false' : 'true');
        }
    };

    function updateToolbarNavButtons(elements = getElements()) {
        const { prevBtn, nextBtn, dropdown } = elements;
        if (!prevBtn || !nextBtn || !dropdown) return;

        const totalFacilities = dropdown.options.length;
        const currentIndex = dropdown.selectedIndex;

        prevBtn.disabled = currentIndex <= 0;
        nextBtn.disabled = currentIndex >= totalFacilities - 1;

        prevBtn.style.opacity = prevBtn.disabled ? '0.5' : '1';
        nextBtn.style.opacity = nextBtn.disabled ? '0.5' : '1';
    }

    function updateToolbarFacilityInfo() {
        const elements = getElements();
        const { dropdown, projectName, removeBtn } = elements;

        // Only proceed if toolbar elements exist (not all pages have the toolbar)
        if (!dropdown) return;

        const facilities = Array.isArray(window.formData?.facilities) ? window.formData.facilities : [];
        const currentIndex = Number.isInteger(window.currentFacilityIndex) ? window.currentFacilityIndex : 0;

        log('Updating toolbar facility info...', {
            hasFormData: !!window.formData,
            facilities: facilities.length,
            currentProjectName: window.currentProjectName
        });

        if (facilities.length) {
            const facilitiesWithIndex = facilities.map((facility, index) => ({
                facility,
                originalIndex: index,
                name: facility.identification?.name || 'Unnamed Facility'
            }));

            facilitiesWithIndex.sort((a, b) => {
                const nameA = a.name.toLowerCase();
                const nameB = b.name.toLowerCase();
                if (nameA === 'unnamed facility' && nameB !== 'unnamed facility') return 1;
                if (nameB === 'unnamed facility' && nameA !== 'unnamed facility') return -1;
                return nameA.localeCompare(nameB);
            });

            dropdown.innerHTML = '';
            facilitiesWithIndex.forEach(({ originalIndex, name }, alphabeticalIndex) => {
                const option = document.createElement('option');
                option.value = originalIndex;
                option.textContent = `${alphabeticalIndex + 1}. ${name}`;
                dropdown.appendChild(option);
            });

            dropdown.value = currentIndex;
        } else {
            dropdown.innerHTML = '<option>No facilities</option>';
        }

        if (projectName) {
            projectName.textContent = window.currentProjectName ? `(${window.currentProjectName})` : '';
        }

        if (removeBtn) {
            removeBtn.classList.toggle('d-none', facilities.length <= 1);
        }

        updateToolbarNavButtons(elements);
    }

    function initializeToolbarButtons() {
        const elements = getElements();
        const { toolbar, toolbarToggle, toolbarContent, dropdown, prevBtn, nextBtn, addBtn, scrollTopBtn, removeBtn } = elements;

        if (toolbar) {
            document.body.classList.add('toolbar-active');
            document.body.classList.toggle('toolbar-minimized', toolbar.classList.contains('minimized'));
            if (toolbarContent) {
                toolbarContent.setAttribute('aria-hidden', toolbar.classList.contains('minimized') ? 'true' : 'false');
            }
        }

        if (toolbarToggle && toolbar && !toolbarToggle.dataset.listenerAttached) {
            toolbarToggle.setAttribute('aria-controls', 'toolbar-content');
            applyToolbarState(toolbar.classList.contains('minimized'), elements);

            toolbarToggle.addEventListener('click', () => { // UI-only, can be passive
                const isMinimized = toolbar.classList.toggle('minimized');
                applyToolbarState(isMinimized, elements);
            }, { passive: true });

            toolbarToggle.dataset.listenerAttached = 'true';
        }

        if (dropdown && !dropdown.dataset.listenerAttached) {
            dropdown.addEventListener('change', (event) => {
                const newIndex = parseInt(event.target.value, 10);
                if (!Number.isNaN(newIndex) && typeof window.navigateToFacility === 'function') {
                    window.navigateToFacility(newIndex);
                }
            }, { passive: true });
            dropdown.dataset.listenerAttached = 'true';
        }

        if (prevBtn && !prevBtn.dataset.listenerAttached) {
            prevBtn.addEventListener('click', () => { // UI-only, can be passive
                const dropdownEl = elements.dropdown;
                if (dropdownEl && dropdownEl.selectedIndex > 0) {
                    dropdownEl.selectedIndex -= 1;
                    dropdownEl.dispatchEvent(new Event('change'));
                }
            }, { passive: true });
            prevBtn.dataset.listenerAttached = 'true';
        }

        if (nextBtn && !nextBtn.dataset.listenerAttached) {
            nextBtn.addEventListener('click', () => { // UI-only, can be passive
                const dropdownEl = elements.dropdown;
                if (dropdownEl && dropdownEl.selectedIndex < dropdownEl.options.length - 1) {
                    dropdownEl.selectedIndex += 1;
                    dropdownEl.dispatchEvent(new Event('change'));
                }
            }, { passive: true });
            nextBtn.dataset.listenerAttached = 'true';
        }

        if (addBtn && !addBtn.dataset.listenerAttached) {
            addBtn.addEventListener('click', () => {
                if (typeof window.addFacility === 'function') {
                    window.addFacility();
                }
            }, { passive: true });
            addBtn.dataset.listenerAttached = 'true';
        }

        if (scrollTopBtn && !scrollTopBtn.dataset.listenerAttached) {
            scrollTopBtn.addEventListener('click', () => {
                window.scrollTo({ top: 0, behavior: 'smooth' });
            }, { passive: true });
            scrollTopBtn.dataset.listenerAttached = 'true';
        }

        if (removeBtn && !removeBtn.dataset.listenerAttached) {
            removeBtn.addEventListener('click', () => {
                if (typeof window.removeFacility === 'function') {
                    window.removeFacility();
                }
            });
            removeBtn.dataset.listenerAttached = 'true';
        }

        log('Toolbar buttons initialized');
    }

    function initializeFixedToolbar() {
        const toolbar = document.getElementById('fixed-toolbar');
        if (!toolbar) {
            log('Fixed toolbar element not found, skipping initialization.');
            return;
        }
        initializeToolbarButtons();
    }

    function initializeFacilityToolbarToggle() {
        const toggleBtn = document.getElementById('facility-toolbar-toggle');
        const expandable = document.getElementById('facility-toolbar-expandable');

        if (!toggleBtn || !expandable) return;

        let isCollapsed = true;
        expandable.style.display = 'none';
        toggleBtn.textContent = '▼';
        toggleBtn.title = 'Expand toolbar';
        toggleBtn.setAttribute('aria-expanded', 'false');

        const newToggleBtn = toggleBtn.cloneNode(true);
        toggleBtn.parentNode.replaceChild(newToggleBtn, toggleBtn);

        newToggleBtn.addEventListener('click', (event) => {
            event.preventDefault();
            event.stopPropagation();

            isCollapsed = !isCollapsed;
            expandable.style.display = isCollapsed ? 'none' : 'block';
            newToggleBtn.textContent = isCollapsed ? '▼' : '▲';
            newToggleBtn.title = isCollapsed ? 'Expand toolbar' : 'Minimize toolbar';
            newToggleBtn.setAttribute('aria-expanded', isCollapsed ? 'false' : 'true');
        }, { passive: false });

        log('Facility toolbar toggle initialized (collapsed by default)');
    }

    const toolbarApi = {
        updateToolbarFacilityInfo,
        updateToolbarNavButtons,
        initializeToolbarButtons,
        initializeFixedToolbar,
        initializeFacilityToolbarToggle
    };

    window.KOPFacilityToolbar = toolbarApi;
    window.updateToolbarFacilityInfo = updateToolbarFacilityInfo;
    window.updateToolbarNavButtons = updateToolbarNavButtons;
    window.initializeToolbarButtons = initializeToolbarButtons;
    window.initializeFixedToolbar = initializeFixedToolbar;
    window.initializeFacilityToolbarToggle = initializeFacilityToolbarToggle;

    document.addEventListener('DOMContentLoaded', () => {
        initializeFixedToolbar();
    }, { once: true });
})(window);
