// UI state management functions extracted from facility-form.v4.js

window.KOP_UI_State = {
    updateProjectStatus: function() {
        const statusTargets = [
            document.getElementById('project-status'),
            document.getElementById('project-status-location'),
            document.getElementById('referrer-project-status')
        ].filter(Boolean);

        if (!statusTargets.length) {
            return;
        }

        if (window.currentProjectName) {
            const facilityCount = window.formData?.facilities?.length || 0;
            const baseMessage = `<strong>📂 Current Project:</strong> <span style="color: #ff9500;">${escapeHtmlForAttr(window.currentProjectName)}</span> (${facilityCount} facilities)`;

            statusTargets.forEach(target => {
                if (target.id === 'referrer-project-status') {
                    target.innerHTML = `${baseMessage}<div style="margin-top: 6px; font-size: 13px; color: #6b7280;">Referrer profiles for this project are saved alongside the operator & facility data.</div>`;
                } else if (target.id === 'project-status-location') {
                    target.innerHTML = `${baseMessage}<div style="margin-top: 6px; font-size: 13px; color: #6b7280;">Location-focused projects load the same dataset for geographic review.</div>`;
                } else {
                    target.innerHTML = baseMessage;
                }
            });
        } else {
            statusTargets.forEach(target => {
                if (target.id === 'referrer-project-status') {
                    target.innerHTML = '⚠️ No project loaded - referrer entries will be stored temporarily';
                } else if (target.id === 'project-status-location') {
                    target.innerHTML = '⚠️ No project loaded - viewing temporary location data';
                } else {
                    target.innerHTML = '⚠️ No project loaded - working with temporary data';
                }
            });
        }
    },

    updateLabelsForProjectType: function() {
        const activeTab = document.querySelector('.category-tab.active');
        const category = activeTab ? activeTab.dataset.category : 'companies';
        const profitStatus = document.querySelector('input[name="profitStatus"]:checked')?.value || 'for-profit';

        // Define default and referrer-specific labels
        const labels = {
            toolbarTitle: { default: '🔧 Toolbar', referrer: '🔧 Toolbar' },
            operatorSectionTitle: { default: 'Parent Company Information', referrer: 'Group/Agency Information' },
            operatorNameLabel: { default: 'Parent Company Name', referrer: 'Group/Agency Name' },
            currentOperatorLabel: { default: 'Current Parent Company', referrer: 'Current Agency' },
            facilitiesOverviewTitle: { default: 'Facilities Overview', referrer: 'Individuals Overview' },
            addFacilityButton: { default: 'Add New Facility', referrer: 'Add New' },
            addFacilityTOC: { default: 'Add New Facility', referrer: 'Add New' },
            currentFacilityLabel: { default: 'Current Facility', referrer: 'Current' },
            addFacilityToolbar: { default: '📄<span class="toolbar-label">Add Entry</span>', referrer: '📄<span class="toolbar-label">Add Entry</span>' },
            cloneFacilityToolbar: { default: '📋', referrer: '📋' },
            removeFacilityToolbar: { default: '🗑️', referrer: '🗑️' },
            facilityNameLabel: { default: 'Facility Name', referrer: 'Individual\'s Name' },
            facilityIdentificationTitle: { default: 'Identification & Names', referrer: 'Individual Identification' },
            facilityDetailsTitle: { default: 'Facility Details', referrer: 'Individual Details' },
            facilityOperationsTitle: { default: 'Facility Operations', referrer: 'Individual\'s Operations' },
            cloneModalTitle: { default: 'Clone', referrer: 'Clone' },
            cloneModalButton: { default: 'Clone', referrer: 'Clone' }
        };

        // Override for Non-Profit
        if (profitStatus === 'non-profit') {
            labels.operatorSectionTitle.default = 'Parent Organization Information';
            labels.operatorNameLabel.default = 'Parent Organization Name';
            labels.currentOperatorLabel.default = 'Current Parent Organization';
        }

        const setLabel = (elementId, text) => {
            const el = document.getElementById(elementId);
            if (el) {
                // Preserve icons if they exist
                const icon = el.querySelector('span[aria-hidden="true"], i');
                if (icon) {
                    el.innerHTML = `${icon.outerHTML} ${text}`;
                } else {
                    // Use innerHTML if text contains HTML tags, otherwise use textContent
                    if (text.includes('<')) {
                        el.innerHTML = text;
                    } else {
                        el.textContent = text;
                    }
                }
            }
        };

        const setLabelForQuery = (selector, text) => {
            const el = document.querySelector(selector);
            if (el) el.textContent = text;
        };
        const mode = (category === 'referrers') ? 'referrer' : 'default';

        // Update main section titles
        setLabelForQuery('#operator-section .section-title', labels.operatorSectionTitle[mode]);
        setLabelForQuery('#operator-name + .field-note-btn + .field-notes + label', labels.operatorNameLabel[mode]); // This is tricky, might need better selector
        setLabelForQuery('label[for="operator-name"]', labels.operatorNameLabel[mode]);
        setLabelForQuery('#current-operator-label', labels.currentOperatorLabel[mode]);

        // Update TOC and Facility Controls
        setLabelForQuery('.facility-toc .toc-title', labels.facilitiesOverviewTitle[mode]);
        setLabel('add-facility-main-btn', labels.addFacilityTOC[mode]);
        setLabelForQuery('.facility-controls strong', `${labels.currentFacilityLabel[mode]}: `);

        // Update Toolbar
        setLabelForQuery('.toolbar-title strong', labels.toolbarTitle[mode]);
        setLabel('toolbar-current-item-label', `${labels.currentFacilityLabel[mode]}:`);
        setLabel('add-facility-btn-toolbar', labels.addFacilityToolbar[mode]);
        setLabel('clone-facility-btn-toolbar', labels.cloneFacilityToolbar[mode]);
        setLabel('remove-facility-btn-toolbar', labels.removeFacilityToolbar[mode]);
        setLabel('delete-facility-btn-toolbar', labels.removeFacilityToolbar[mode]); // Legacy ID fallback

        // Update Facility-specific sections
        setLabelForQuery('#identification-section .section-title', labels.facilityIdentificationTitle[mode]);
        setLabelForQuery('label[for="facility-name"]', labels.facilityNameLabel[mode]);

        // Update Clone Modal
        setLabelForQuery('#clone-facility-modal .modal-title', labels.cloneModalTitle[mode]);
        setLabel('clone-modal-confirm', labels.cloneModalButton[mode]);
    }
};