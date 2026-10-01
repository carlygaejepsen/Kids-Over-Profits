// Guided tour for the TTI Glossary (templates/page-glossary.php, built by
// inc/glossary.php from js/data/glossary/glossary.md). Reuses the shared
// TutorialOverlay class, which must load first (see the glossary tour
// enqueue in templates/page-glossary.php).
(function () {
    'use strict';

    const initGlossaryTutorial = () => {
        if (!document.querySelector('.kop-gl')) return;
        if (!window.TutorialOverlay) return;
        if (window.kopGlossaryTutorial) return;

        const steps = [
            {
                title: 'Welcome to the TTI Glossary',
                content: 'The language of the Troubled Teen Industry, drawn from program handbooks, staff manuals, state records and survivor accounts - so a term you read in a report or a court filing has a plain explanation here.',
                target: null
            },
            {
                title: 'Search for a Term',
                content: 'Type a word or a phrase to jump straight to the entries that mention it.',
                target: '#kop-gl-q',
                position: 'bottom',
                highlightPadding: 6
            },
            {
                title: 'Filter to One Program',
                content: 'Choose a program here to see only the terms that are tagged to it - useful when you are researching one facility in particular.',
                target: '#kop-gl-program',
                position: 'bottom',
                highlightPadding: 6
            },
            {
                title: 'Jump From a Program Name',
                content: 'Many entries list which programs used that term, like this one. Clicking a program\'s name filters the glossary to just that program, the same as using the dropdown above.',
                target: '.kop-gl-tag-filter',
                position: 'top',
                scrollTarget: '.kop-gl-tag-filter',
                highlightPadding: 6
            },
            {
                title: 'Preview a Linked Term',
                content: 'Underlined words like this one link to another entry. Hover or tap one to preview its definition in a small popup, without losing your place on the page.',
                target: 'a.kop-gl-ref',
                position: 'top',
                scrollTarget: 'a.kop-gl-ref',
                highlightPadding: 4
            },
            {
                title: 'Add What You Know',
                content: '<strong>My facility used this too</strong> tells us a term applied somewhere not yet listed. <strong>Suggest a correction</strong> flags something wrong. Either way, a person reads it before anything on the page changes.',
                target: '.kop-gl-fb-row',
                position: 'top',
                scrollTarget: '.kop-gl-fb-row',
                highlightPadding: 6
            }
        ];

        window.kopGlossaryTutorial = new window.TutorialOverlay(steps, {
            storageKey: 'kop_glossary_tutorial_seen'
        });
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initGlossaryTutorial);
    } else {
        initGlossaryTutorial();
    }

    // Last-chance delayed init in case markup arrives late.
    setTimeout(initGlossaryTutorial, 1500);
})();
