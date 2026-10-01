// Guided tour for the Network Map (templates/page-network-map.php,
// js/network-map/*). Reuses the shared TutorialOverlay class, which must
// load first (see the network map branch of kop_enqueue_template_assets in
// inc/enqueue.php).
(function () {
    'use strict';

    const initNetworkMapTutorial = () => {
        if (!document.getElementById('kop-network-app')) return;
        if (!window.TutorialOverlay) return;
        if (window.kopNetworkMapTutorial) return;

        const steps = [
            {
                title: 'The Network Map',
                content: 'Connections on record between TTI programs, the people who ran them, and the companies behind them. A line means a relationship was documented, not an allegation of wrongdoing. Click a name to see what it connects to.',
                target: null
            },
            {
                title: 'Search a Name',
                content: 'Type a person, program or company here to find it on the map instead of hunting for it by eye.',
                target: '#kop-network-search',
                position: 'bottom',
                highlightPadding: 6
            },
            {
                title: 'Focus or Expand',
                content: '<strong>Focus</strong> (the default) shows only the connections of the name you just clicked. <strong>Expand</strong> adds those connections to whatever is already on the board, so you can build up a bigger picture one click at a time.',
                target: 'input[name="kop-network-mode"]',
                highlightAll: true,
                position: 'bottom',
                highlightPadding: 6
            },
            {
                title: 'Connect Two Names',
                content: 'Not sure if two people or programs are linked? Enter both here and the map finds and lights up every route between them.',
                target: '#kop-network-path-toggle',
                position: 'bottom',
                highlightPadding: 6
            },
            {
                title: 'List and Timeline',
                content: '<strong>List</strong> switches the map to two plain, sortable tables of names and connections, each with a CSV download. <strong>Timeline</strong> opens a year slider so you can see the map as it stood at a point in the past.',
                target: '#kop-network-list-toggle, #kop-network-timeline-toggle',
                highlightAll: true,
                position: 'bottom',
                highlightPadding: 4
            },
            {
                title: 'Your Trail',
                content: 'Every name you click is added here, so you can always see how you got where you are, and step back to an earlier point.',
                target: '#kop-network-chain',
                position: 'bottom',
                highlightPadding: 4
            },
            {
                title: 'The Key',
                content: 'Open this to see what each colour and outline means: a program\'s status, a NATSAP member, and which company each coloured line belongs to.',
                target: '#kop-network-filters-toggle',
                position: 'left',
                highlightPadding: 6
            },
            {
                title: 'Reset, Zoom and Share',
                content: '<strong>Reset view</strong> and <strong>Fit to screen</strong> bring a lost view back; the <strong>+</strong> and <strong>&minus;</strong> buttons zoom. <strong>Copy link</strong> grabs a URL that reopens the map exactly as you have it set up, to share or save.',
                target: '#kop-network-reset-view, #kop-network-zoom-fit, #kop-network-share',
                highlightAll: true,
                position: 'top',
                highlightPadding: 4
            }
        ];

        window.kopNetworkMapTutorial = new window.TutorialOverlay(steps, {
            storageKey: 'kop_network_map_tutorial_seen'
        });
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initNetworkMapTutorial);
    } else {
        initNetworkMapTutorial();
    }

    // Last-chance delayed init in case markup arrives late (the map's app.js
    // builds the scene asynchronously).
    setTimeout(initNetworkMapTutorial, 1500);
})();
