// js/data-form-modules/admin-data-page.js
// Admin-only (master mode) additions layered on top of data-page.js. This file
// is enqueued only for page-admin-data.php (see inc/enqueue.php), so everything
// here runs exclusively in master mode.
(function() {
    if (window.KOP_AdminDataPage) return;

    // ============================================
    // MOVE FACILITY TO A NEW STATE (relocation history)
    // ============================================

    /**
     * Snapshot the current primary location into locationDetails.formerLocations,
     * then clear the primary location fields so the user can enter the new state.
     * Keeps the facility visible in BOTH the old and new state directories.
     */
    function moveFacilityToNewState() {
        const notify = typeof window.showUploadStatus === 'function' ? window.showUploadStatus : () => {};
        const facility = window.formData?.facilities?.[window.currentFacilityIndex || 0];
        if (!facility) {
            notify('No facility loaded to move.', 'error');
            return;
        }

        facility.locationDetails = facility.locationDetails || {};
        const ld = facility.locationDetails;

        const currentState = String(ld.state || '').trim();
        const currentCity = String(ld.city || '').trim();
        const currentAddress = String(facility.address || '').trim();
        const currentZip = String(ld.zip || '').trim();

        if (!currentState && !currentCity && !currentAddress) {
            notify('No current location to record. Fill in the state/city first.', 'error');
            return;
        }

        const stateLabel = currentState || currentCity || 'this location';
        if (!confirm(`Record "${stateLabel}" as a FORMER location and clear the primary address so you can enter the new state?\n\nThe facility will still appear in the ${stateLabel} directory, marked as relocated.`)) {
            return;
        }

        // Optional years for the former entry. Pre-fill "from" with the facility's opening year.
        const defaultFrom = String(facility.operatingPeriod?.startYear || '').trim();
        const fromYear = (prompt('Year it STARTED operating in the old state (optional):', defaultFrom) || '').trim();
        const toYear = (prompt('Year it LEFT the old state (optional):', '') || '').trim();

        if (!Array.isArray(ld.formerLocations)) ld.formerLocations = [];
        ld.formerLocations.push({
            state: currentState,
            city: currentCity,
            address: currentAddress,
            zip: currentZip,
            fromYear: fromYear,
            toYear: toYear
        });

        // Clear the primary location so the new state can be entered fresh.
        facility.address = '';
        ld.city = '';
        ld.state = '';
        ld.zip = '';
        if (typeof facility.location === 'string') facility.location = '';

        if (typeof window.updateAllUI === 'function') window.updateAllUI();
        if (typeof window.autoSave === 'function') window.autoSave();

        notify(`Recorded former location in ${stateLabel}. Now enter the new state above.`, 'success');

        // Focus the (now empty) State field so the user can type the new state.
        const stateField = document.querySelector('.facility-field[data-field="locationDetails.state"]');
        if (stateField) {
            stateField.scrollIntoView({ behavior: 'smooth', block: 'center' });
            setTimeout(() => stateField.focus(), 300);
        }
    }

    function initializeMoveToNewStateButton() {
        const btn = document.getElementById('move-to-new-state-btn');
        if (btn && !btn.dataset.moveHandlerAttached) {
            btn.addEventListener('click', moveFacilityToNewState);
            btn.dataset.moveHandlerAttached = 'true';
        }
    }

    window.KOP_AdminDataPage = {
        init: function() {
            initializeMoveToNewStateButton();
        },
        moveFacilityToNewState
    };
    window.moveFacilityToNewState = moveFacilityToNewState;

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => window.KOP_AdminDataPage.init());
    } else {
        window.KOP_AdminDataPage.init();
    }
    // The form (and its helpers) may finish initializing after DOMContentLoaded.
    document.addEventListener('formReady', initializeMoveToNewStateButton, { once: true });
})();
