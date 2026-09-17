/**
 * Network map: bootstrap.
 *
 * Finds the shell the template printed, loads the data, and wires the store,
 * the renderer and the viewport together. The shell renders before any of
 * this runs, so if the data never arrives the page still has something to
 * say: the loading line becomes a link to the facility directory rather
 * than an empty stage.
 *
 * Step 3 of the build wires the map itself: it draws, pans, zooms and
 * reports what is under the pointer. The chain, filters, search, drawer and
 * URL state arrive in the steps after this one and will hang off the same
 * objects.
 */
(function () {
    'use strict';

    var CONFIG = window.KOP_NETWORK_CONFIG || null;

    function byId(id) {
        return document.getElementById(id);
    }

    function boot() {
        var shell = byId('kop-network-app');
        if (!shell) return;

        var canvas = byId('kop-network-canvas');
        var loading = byId('kop-network-loading');
        var status = byId('kop-network-status');
        var stage = byId('kop-network-stage');

        if (!canvas || !window.KOPNetworkStore || !window.KOPNetworkCanvas || !window.KOPNetworkViewport) {
            fail(shell, loading, 'The map could not start.');
            return;
        }

        var store = window.KOPNetworkStore.create();
        var renderer = window.KOPNetworkCanvas.create(canvas);
        var viewport = window.KOPNetworkViewport.create({
            canvas: canvas,
            renderer: renderer,
            onHover: function (node) {
                renderer.setEmphasis({ hoverId: node ? node.id : null });
            },
            onSelect: function (node) {
                /* Step 4 pushes this onto the chain. Until then, selecting a
                 * node says its name, which is what the live region is for
                 * and what makes the canvas answer a screen reader at all. */
                announce(status, node.name + ', ' + node.kind + ', ' +
                    (store.visible().degrees[node.id] || 0) + ' connections shown.');
            }
        });

        var app = {
            store: store,
            renderer: renderer,
            viewport: viewport,
            elements: {
                shell: shell, canvas: canvas, stage: stage, status: status
            },
            config: CONFIG,
            /* Recompute the visible subgraph and hand it to both consumers.
             * Filters, search and the chain all end up calling this. */
            refresh: function () {
                var scene = store.visible();
                renderer.setScene(scene);
                viewport.setScene(scene);
                viewport.scheduleDraw();
            }
        };
        window.KOPNetworkMap = app;

        store.load(CONFIG).then(function () {
            renderer.useChainIndex(store.chainIndex);
            renderer.resize();
            app.refresh();
            viewport.fit();

            if (loading) loading.hidden = true;
            shell.setAttribute('data-state', 'ready');

            var visible = store.visible();
            announce(status, 'Map loaded: ' + visible.nodes.length + ' names and ' +
                visible.edges.length + ' connections.');

            if (store.unplaced.length) {
                /* Only reachable when graph.json and layout.json came from
                 * different board exports, which the build test rejects. */
                window.console && console.warn(
                    'Network map: ' + store.unplaced.length + ' nodes have no layout position and were left out.');
            }
        }).catch(function (error) {
            window.console && console.error('Network map failed to load.', error);
            fail(shell, loading, 'The map data could not be loaded.');
        });

        wireControls(app);
        wireResize(app);
    }

    /* The map is the only thing that fails here; the intro above it stands on
     * its own, so this replaces the loading line rather than the page. */
    function fail(shell, loading, message) {
        shell.setAttribute('data-state', 'error');
        if (!loading) return;
        loading.hidden = false;
        loading.textContent = message + ' ';
        var url = (CONFIG && CONFIG.directoryUrl) || '/tti-program-index/';
        var link = document.createElement('a');
        link.href = url;
        link.textContent = 'Browse the facility directory instead.';
        loading.appendChild(link);
    }

    function announce(status, text) {
        if (status) status.textContent = text;
    }

    function wireControls(app) {
        var reset = document.getElementById('kop-network-reset-view');
        if (reset) {
            reset.addEventListener('click', function () {
                app.viewport.fit();
            });
        }

        var colour = document.getElementById('kop-network-colour-mode');
        if (colour) {
            colour.addEventListener('change', function () {
                app.renderer.setColourMode(colour.value);
                app.viewport.scheduleDraw();
            });
        }
    }

    /**
     * The stage is sized in viewport units, so a phone's address bar sliding
     * away resizes it without the window firing anything useful. Observe the
     * element where we can and fall back to the window event.
     */
    function wireResize(app) {
        var redraw = function () { app.viewport.resize(); };
        if (window.ResizeObserver && app.elements.stage) {
            new ResizeObserver(redraw).observe(app.elements.stage);
        } else {
            window.addEventListener('resize', redraw);
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
