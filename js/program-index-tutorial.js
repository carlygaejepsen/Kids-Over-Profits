// Guided tour for the TTI Facility Directory (templates/page-tti-program-index.php):
// one page, two tabs - by parent company and by location. Reuses the shared
// TutorialOverlay class, which must load first (see enqueue_tti_processor_scripts
// in inc/enqueue.php).
(function () {
    'use strict';

    const initProgramIndexTutorial = () => {
        if (!document.querySelector('.kop-dir-tabs')) return;
        if (!window.TutorialOverlay) return;
        if (window.kopProgramIndexTutorial) return;

        const showTab = (view) => {
            const tab = document.querySelector('.kop-dir-tab[data-view="' + view + '"]');
            if (tab && !tab.classList.contains('active')) tab.click();
        };

        const steps = [
            {
                title: 'The TTI Facility Directory',
                content: 'Every Troubled Teen Industry facility we have documented, searchable two ways: by the parent company that owns it, or by where it is. Switch tabs any time - your place in each one is kept.',
                target: null
            },
            {
                title: 'Two Ways to Browse',
                content: '<strong>By parent company</strong> groups facilities under the business that runs them. <strong>By location</strong> groups them by US state or country - useful when you know roughly where a program was, but not its name.',
                target: '#kop-dir-tab-company, #kop-dir-tab-location',
                highlightAll: true,
                position: 'bottom',
                highlightPadding: 4
            },
            {
                title: 'Search by Company',
                content: 'Type a facility or parent company name here to filter the list as you type.',
                target: '#searchInput',
                position: 'bottom',
                scrollTarget: '.kop-dir-tabs',
                highlightPadding: 6,
                onShow: () => showTab('company')
            },
            {
                title: 'Narrow and Sort',
                content: 'Filter to only open, closed or transferred facilities, or sort to put the ones with the most inspection reports first.',
                target: '#statusFilter, #sortBy',
                highlightAll: true,
                position: 'bottom',
                highlightPadding: 6,
                onShow: () => showTab('company')
            },
            {
                title: 'Jump to a Letter',
                content: 'Click a letter here to skip straight to the facilities and companies whose names start with it.',
                target: '#alphabet-filter',
                position: 'bottom',
                highlightPadding: 4,
                onShow: () => showTab('company')
            },
            {
                title: 'Browsing by Location',
                content: 'This tab has the same tools - a search box, a filter for US states versus international programs, and a sort - but lists states and countries instead of companies. Click one to see every facility documented there.',
                target: '#loc-searchInput, #loc-typeFilter, #loc-sortBy',
                highlightAll: true,
                position: 'bottom',
                scrollTarget: '.kop-dir-tabs',
                highlightPadding: 6,
                onShow: () => showTab('location')
            }
        ];

        window.kopProgramIndexTutorial = new window.TutorialOverlay(steps, {
            storageKey: 'kop_program_index_tutorial_seen'
        });
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initProgramIndexTutorial);
    } else {
        initProgramIndexTutorial();
    }

    // Last-chance delayed init in case markup arrives late.
    setTimeout(initProgramIndexTutorial, 1500);
})();
