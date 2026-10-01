// Guided tour for the state inspection report trackers (templates/page-state-reports.php,
// the shared js/inspections/report-page.js engine used by the migrated states).
// Reuses the shared TutorialOverlay class, which must load first (see the
// report_page branch of kop_enqueue_report_scripts in inc/enqueue.php).
//
// #newReportsOnly only exists on this template (the facility directory uses
// the same #searchInput/#sortBy/#alphabet-filter ids for its own, different
// controls), so it is the guard that tells the two apart.
(function () {
    'use strict';

    const initInspectionReportsTutorial = () => {
        if (!document.getElementById('newReportsOnly')) return;
        if (!window.TutorialOverlay) return;
        if (window.kopInspectionReportsTutorial) return;

        const steps = [
            {
                title: 'State Inspection Reports',
                content: 'Every licensing inspection report we hold for this state, facility by facility. Each report\'s wording below is the state\'s own, copied from the document it published.',
                target: null
            },
            {
                title: 'Search a Facility',
                content: 'Type a facility name to jump straight to it instead of scrolling the whole list.',
                target: '#searchInput',
                position: 'bottom',
                highlightPadding: 6
            },
            {
                title: 'Sort the List',
                content: 'Put facilities with violations first, the most inspected first, or leave it alphabetical.',
                target: '#sortBy',
                position: 'bottom',
                highlightPadding: 6
            },
            {
                title: 'New Reports Only',
                content: 'Check this to show only the reports added in the last 30 days - the fastest way to see what just came in.',
                target: '#newReportsOnly',
                position: 'bottom',
                highlightPadding: 6
            },
            {
                title: 'Jump to a Letter',
                content: 'Click a letter to skip straight to the facilities whose names start with it.',
                target: '#alphabet-filter',
                position: 'bottom',
                highlightPadding: 4
            },
            {
                title: 'Open a Facility, Then a Report',
                content: 'Click a facility\'s name to see its reports, then click a report to read it in full. Reports flagged for the most serious findings also appear on the <a href="/severe-reports/">Severe Reports</a> page.',
                target: '.kop-rp-facility-summary',
                position: 'bottom',
                scrollTarget: '#report-container',
                highlightPadding: 4
            }
        ];

        window.kopInspectionReportsTutorial = new window.TutorialOverlay(steps, {
            storageKey: 'kop_inspection_reports_tutorial_seen'
        });
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initInspectionReportsTutorial);
    } else {
        initInspectionReportsTutorial();
    }

    // Last-chance delayed init: the facility list loads over the network and
    // can take a few seconds, especially on the larger states.
    setTimeout(initInspectionReportsTutorial, 1500);
})();
