/**
 * Network map: bootstrap.
 *
 * Finds the shell the template printed, loads the data, and wires the store,
 * the renderer, the viewport and the focus chain together. The shell renders
 * before any of this runs, so if the data never arrives the page still has
 * something to say: the loading line becomes a link to the facility
 * directory rather than an empty stage.
 *
 * Everything that touches the page's own HTML lives here. focus.js owns the
 * chain but never reads the document, which keeps it testable; this module
 * renders the breadcrumb from the chain it reports.
 *
 * Filters, search, the drawer and URL state arrive in the steps after this
 * one and will hang off the same objects.
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

        if (!canvas || !window.KOPNetworkStore || !window.KOPNetworkCanvas ||
            !window.KOPNetworkViewport || !window.KOPNetworkFocus) {
            fail(shell, loading, 'The map could not start.');
            return;
        }

        var announce = function (text) {
            if (status) status.textContent = text;
        };

        var store = window.KOPNetworkStore.create();
        var renderer = window.KOPNetworkCanvas.create(canvas);

        /* focus is created after the viewport but referenced by its
         * callbacks, which only fire once the pointer moves. */
        var focus = null;
        var viewport = window.KOPNetworkViewport.create({
            canvas: canvas,
            renderer: renderer,
            onHover: function (node) {
                if (focus) focus.hover(node);
            },
            onSelect: function (node, event) {
                /* Ctrl- or Cmd-click opens the name's profile in a new tab
                 * instead of the map's own view of it, the way a link would. */
                if (event && (event.ctrlKey || event.metaKey) && window.KOPNetworkDrawer) {
                    var profile = window.KOPNetworkDrawer.profileFor(node, CONFIG);
                    if (profile) {
                        window.open(profile.url, '_blank', 'noopener');
                        return;
                    }
                }
                if (focus) focus.select(node);
            },
        });

        var filters = null;

        focus = window.KOPNetworkFocus.create({
            store: store,
            renderer: renderer,
            viewport: viewport,
            announce: announce,
            onChange: function () {
                renderChain(app);
                syncMode(app);
                if (app.drawer) app.drawer.update();
                if (app.urlState) app.urlState.write();
                /* A trail narrows what is on screen, and the legend lists
                 * only what is on screen. */
                if (filters) filters.renderLegend();
            }
        });

        var app = {
            store: store,
            renderer: renderer,
            viewport: viewport,
            focus: focus,
            elements: {
                shell: shell, canvas: canvas, stage: stage, status: status,
                chain: byId('kop-network-chain'),
                chainList: byId('kop-network-chain-list')
            },
            config: CONFIG,
            announce: announce,
            /**
             * Recompute what is on screen and hand it to both consumers.
             * The filters end up here. What is on screen is the opening
             * organisations plus whatever has been opened, so a filter
             * change can move the ground under an opened node; the chain
             * gets first refusal and re-settles itself when it does.
             */
            refresh: function () {
                if (focus.isFocused() && focus.refresh()) return;
                /* The opening view is laid out too, not just redrawn: a
                 * filter that hides one of its organisations would otherwise
                 * leave that organisation's cell standing empty in the
                 * block. reframe() re-packs what is left for the stage. */
                if (store.ready) {
                    focus.reframe();
                    return;
                }
                var scene = focus.scene();
                renderer.setScene(scene);
                viewport.setScene(scene);
                viewport.scheduleDraw();
            }
        };
        window.KOPNetworkMap = app;

        if (window.KOPNetworkFilters) {
            filters = window.KOPNetworkFilters.create({
                store: store,
                renderer: renderer,
                announce: announce,
                onChange: app.refresh,
                scene: function () { return focus.scene(); },
                redraw: viewport.scheduleDraw
            });
            app.filters = filters;
        }

        if (window.KOPNetworkDrawer) {
            app.drawer = window.KOPNetworkDrawer.create({
                store: store,
                focus: focus,
                config: CONFIG,
                drawer: byId('kop-network-drawer'),
                body: byId('kop-network-drawer-body'),
                close: byId('kop-network-drawer-close')
            });
        }

        if (window.KOPNetworkSearch) {
            app.search = window.KOPNetworkSearch.create({
                store: store,
                focus: focus,
                input: byId('kop-network-search'),
                list: byId('kop-network-search-results'),
                announce: announce,
                onPick: function () { canvas.focus(); }
            });
        }

        store.load(CONFIG).then(function () {
            renderer.useChainIndex(store.chainIndex);
            renderer.resize();
            focus.start();

            /* A shared link or a reload: open the trail the address names.
             * Created only now, so the first write cannot wipe the hash
             * before it has been read. */
            if (window.KOPNetworkUrlState) {
                app.urlState = window.KOPNetworkUrlState.create({
                    focus: focus, window: window
                });
                if (app.urlState) app.urlState.read();
            }

            if (filters) filters.start();

            if (loading) loading.hidden = true;
            shell.setAttribute('data-state', 'ready');

            var shown = focus.scene();
            var all = store.visible();
            announce('Map loaded. Showing ' + shown.nodes.length +
                ' organisations out of ' + all.nodes.length +
                ' names. Click one to see who it connects to, or search for a name.');

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
        wireKeyboard(app);
        wireResize(app);
        renderChain(app);
    }

    /* The map is the only thing that fails here; the intro above it stands on
     * its own, so this replaces the loading line rather than the page. */
    function fail(shell, loading, message) {
        shell.setAttribute('data-state', 'error');
        if (!loading) return;
        loading.hidden = false;
        loading.textContent = message + ' ';
        var url = (CONFIG && CONFIG.directoryUrl) || '/location-index/';
        var link = document.createElement('a');
        link.href = url;
        link.textContent = 'Browse the facility directory instead.';
        loading.appendChild(link);
    }

    /**
     * The trail, oldest first. Every crumb but the last truncates back to
     * itself; the last is where you are, so it is not a button anywhere. The
     * nav is hidden entirely on the whole map, where there is no trail to
     * name.
     */
    function renderChain(app) {
        var nav = app.elements.chain;
        var list = app.elements.chainList;
        if (!nav || !list) return;

        var chain = app.focus.chain();
        nav.hidden = chain.length === 0;
        list.textContent = '';
        if (!chain.length) return;

        chain.forEach(function (id, index) {
            var node = app.store.node(id);
            var name = node ? node.name : id;
            var item = document.createElement('li');

            if (index === chain.length - 1) {
                /* Where you already are: text, not a control. A disabled
                 * button would drop out of the tab order and be skipped by
                 * most screen readers, which is the opposite of what the
                 * last crumb is for. */
                var current = document.createElement('span');
                current.className = 'kop-network__chain-current';
                current.setAttribute('aria-current', 'step');
                current.textContent = name;
                item.appendChild(current);
            } else {
                var button = document.createElement('button');
                button.type = 'button';
                button.textContent = name;
                button.setAttribute('aria-label', 'Go back to ' + name);
                button.addEventListener('click', function () {
                    app.focus.truncateTo(index);
                });
                item.appendChild(button);
            }

            list.appendChild(item);
        });
    }

    /* The mode radios follow the chain: search can switch to expand when it
     * opens several names at once, and the toggle has to say so. */
    function syncMode(app) {
        var current = app.focus.mode();
        var modes = document.querySelectorAll('input[name="kop-network-mode"]');
        Array.prototype.forEach.call(modes, function (input) {
            input.checked = input.value === current;
        });
    }

    function wireControls(app) {
        var reset = byId('kop-network-reset-view');
        if (reset) {
            /* Re-lay out what is on screen and frame it, the same path a
             * click takes. fit() alone framed the nodes where they had been
             * dragged to and left the layout as it was. */
            reset.addEventListener('click', function () {
                app.focus.reframe();
            });
        }

        /* The colour-mode select is bound in filters.js, which owns the
         * legend that has to change with it. */

        /* Focus or expand: what a click does. Switching re-lays the current
         * trail out under the new rule rather than waiting for the next
         * click, so the change is visible at once. */
        var modes = document.querySelectorAll('input[name="kop-network-mode"]');
        Array.prototype.forEach.call(modes, function (input) {
            input.addEventListener('change', function () {
                if (!input.checked) return;
                app.focus.setMode(input.value);
                app.announce(input.value === 'expand'
                    ? 'Expand: each click adds to the board.'
                    : 'Focus: each click shows only that name\'s connections.');
            });
        });

        var whole = byId('kop-network-whole-map');
        if (whole) {
            whole.addEventListener('click', function () {
                app.focus.clear();
                app.elements.canvas.focus();
            });
        }
    }

    /**
     * Escape returns to the whole map from any depth, the same as the Start
     * over button. Stepping back one crumb at a time is what the breadcrumb is
     * for. It is bound to the canvas rather than the document so it cannot
     * steal Escape from the search box or a dialog elsewhere on the page.
     */
    function wireKeyboard(app) {
        app.elements.canvas.addEventListener('keydown', function (event) {
            if (event.key !== 'Escape' && event.key !== 'Esc') return;
            if (!app.focus.chain().length) return;
            event.preventDefault();
            app.focus.clear();
        });
    }

    /**
     * The stage is sized in viewport units, so a phone's address bar sliding
     * away resizes it without the window firing anything useful. Observe the
     * element where we can and fall back to the window event.
     */
    function wireResize(app) {
        var pending = 0;
        var onResize = function () {
            app.viewport.resize();
            /* What is on screen is laid out for the stage it was laid out
             * for, so a new stage needs a new arrangement. Debounced, because
             * dragging a window edge fires this continuously and re-settling
             * on every pixel would boil the map. */
            if (pending) window.clearTimeout(pending);
            pending = window.setTimeout(function () {
                pending = 0;
                app.focus.reframe();
            }, 180);
        };
        if (window.ResizeObserver && app.elements.stage) {
            new ResizeObserver(onResize).observe(app.elements.stage);
        } else {
            window.addEventListener('resize', onResize);
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
