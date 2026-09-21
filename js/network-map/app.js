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
        /* The popup that says what a line records; built once focus exists. */
        var popup = null;
        var viewport = window.KOPNetworkViewport.create({
            canvas: canvas,
            renderer: renderer,
            onHover: function (node) {
                if (focus) focus.hover(node);
            },
            onHoverEdge: function (edge, point) {
                if (popup) popup.hover(edge, point);
            },
            onSelectEdge: function (edge, point) {
                if (popup) popup.pin(edge, point);
            },
            /* A pan or a zoom moves the line out from under its popup. */
            onChange: function () {
                if (popup) popup.hide();
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
                /* The line the popup describes may not be in the new view. */
                if (popup) popup.hide();
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
                chainList: byId('kop-network-chain-list'),
                chainEmpty: byId('kop-network-chain-empty'),
                chainHome: byId('kop-network-whole-map')
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

        if (window.KOPNetworkConnection && stage) {
            popup = window.KOPNetworkConnection.create({
                stage: stage,
                focus: focus,
                renderer: renderer,
                announce: announce
            });
            app.popup = popup;
        }

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
                close: byId('kop-network-drawer-close'),
                /* A route is read in the drawer; path.js writes it. Looked
                 * up when called, since that module is created below. */
                renderPath: function (body) {
                    return app.path ? app.path.renderInto(body) : false;
                }
            });
        }

        if (window.KOPNetworkPath) {
            app.path = window.KOPNetworkPath.create({
                store: store,
                focus: focus,
                announce: announce,
                elements: {
                    toggle: byId('kop-network-path-toggle'),
                    panel: byId('kop-network-path'),
                    form: byId('kop-network-path-form'),
                    from: byId('kop-network-path-from'),
                    fromList: byId('kop-network-path-from-results'),
                    to: byId('kop-network-path-to'),
                    toList: byId('kop-network-path-to-results'),
                    swap: byId('kop-network-path-swap'),
                    close: byId('kop-network-path-close'),
                    message: byId('kop-network-path-message')
                }
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

        /* Arrow keys, Enter and zoom on the stage; Escape is bound below. */
        if (window.KOPNetworkKeys) {
            app.keys = window.KOPNetworkKeys.create({
                canvas: canvas, focus: focus, viewport: viewport, renderer: renderer, announce: announce
            });
        }

        store.load(CONFIG).then(function () {
            renderer.useChainIndex(store.chainIndex);
            renderer.useBoardColours(store.meta);
            renderer.resize();
            focus.start();

            /* A shared link or a reload: open the trail the address names.
             * Created only now, so the first write cannot wipe the hash
             * before it has been read. */
            if (window.KOPNetworkUrlState) {
                app.urlState = window.KOPNetworkUrlState.create({
                    focus: focus, window: window,
                    view: function () { return store.view; },
                    /* A link naming a starter view opens on it. */
                    onView: function (key) {
                        if (key === store.view) return false;
                        store.setView(key);
                        if (filters) filters.syncFromStore();
                        var select = byId('kop-network-view');
                        if (select) select.value = store.view;
                        return true;
                    }
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
     * itself; the last is where you are, so it is not a button anywhere.
     *
     * The strip itself never hides: it is one row that keeps its height
     * whether or not there is a trail, so the first click does not shrink
     * the stage. With no trail it carries a line saying what a click does.
     */
    function renderChain(app) {
        var nav = app.elements.chain;
        var list = app.elements.chainList;
        if (!nav || !list) return;

        var chain = app.focus.chain();
        list.textContent = '';
        list.hidden = chain.length === 0;
        if (app.elements.chainEmpty) app.elements.chainEmpty.hidden = chain.length > 0;
        if (app.elements.chainHome) app.elements.chainHome.hidden = chain.length === 0;
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

        /* A long trail scrolls sideways rather than wrapping onto a second
         * row. Keep the newest step, the one you are on, in view. */
        list.scrollLeft = list.scrollWidth;
    }

    /* The mode radios follow the chain: search can switch to expand when it
     * opens several names at once, and the toggle has to say so. With a route
     * on the board neither is checked: a click there leaves the route, in
     * Focus, whatever was set before. */
    function syncMode(app) {
        var current = app.focus.mode();
        var modes = document.querySelectorAll('input[name="kop-network-mode"]');
        Array.prototype.forEach.call(modes, function (input) {
            input.checked = input.value === current;
        });
    }

    /**
     * The map over the whole screen: toolbar, trail, stage and drawer, with
     * the page around them gone. The browser's own full screen where it has
     * one; where it does not (an iPhone), the app is pinned over the window
     * instead, which is the same thing less the address bar. Either way the
     * stage changes size, and the resize observer re-lays the view out.
     */
    function wireFullscreen(app) {
        var button = byId('kop-network-fullscreen');
        var shell = app.elements.shell || byId('kop-network-app');
        if (!button || !shell) return;

        var PINNED = 'kop-network__app--full';
        var request = shell.requestFullscreen || shell.webkitRequestFullscreen;
        var exit = document.exitFullscreen || document.webkitExitFullscreen;
        var native = function () {
            return (document.fullscreenElement || document.webkitFullscreenElement) === shell;
        };
        var isFull = function () {
            return native() || shell.classList.contains(PINNED);
        };
        var sync = function () {
            var full = isFull();
            button.setAttribute('aria-pressed', full ? 'true' : 'false');
            button.textContent = full ? 'Exit full screen' : 'Full screen';
        };
        var pin = function (on) {
            shell.classList.toggle(PINNED, on);
            /* The page behind a pinned map must not scroll under it. */
            document.documentElement.classList.toggle('kop-network-pinned', on);
            sync();
        };

        button.addEventListener('click', function () {
            if (native()) {
                exit.call(document);
            } else if (shell.classList.contains(PINNED)) {
                pin(false);
            } else if (request) {
                var asked = request.call(shell);
                /* Refused (an embedded frame, a browser policy): pin instead. */
                if (asked && asked.catch) asked.catch(function () { pin(true); });
            } else {
                pin(true);
            }
            if (app.elements.canvas) app.elements.canvas.focus();
        });
        document.addEventListener('fullscreenchange', sync);
        document.addEventListener('webkitfullscreenchange', sync);
        /* The browser gives Escape to its own full screen; the pinned map
         * has to take it itself, ahead of the handler that starts over. */
        document.addEventListener('keydown', function (event) {
            if (event.key !== 'Escape' || !shell.classList.contains(PINNED)) return;
            pin(false);
            event.stopImmediatePropagation();
        }, true);
    }

    /**
     * Copy link: the address bar already carries the trail, the mode and the
     * starter view (url-state.js), so the link to this view is the page's own
     * address. The button says it worked, because a copy shows nothing.
     */
    function wireShare(app) {
        var button = byId('kop-network-share');
        if (!button) return;
        var label = button.textContent;
        var timer = 0;

        var done = function (ok) {
            button.textContent = ok ? 'Link copied' : 'Copy failed';
            button.setAttribute('data-copied', ok ? 'true' : 'false');
            app.announce(ok ? 'A link to this view was copied.' : 'The link could not be copied. Copy it from the address bar.');
            if (timer) window.clearTimeout(timer);
            timer = window.setTimeout(function () {
                button.textContent = label;
                button.removeAttribute('data-copied');
            }, 2000);
        };

        /* For a page not served over https, or a browser without the
         * clipboard API: select the address in a field and copy that. */
        var legacy = function (text) {
            var field = document.createElement('textarea');
            field.value = text;
            field.setAttribute('readonly', '');
            field.style.position = 'fixed';
            field.style.opacity = '0';
            document.body.appendChild(field);
            field.select();
            var ok = false;
            try { ok = document.execCommand('copy'); } catch (e) { ok = false; }
            document.body.removeChild(field);
            return ok;
        };

        button.addEventListener('click', function () {
            if (app.urlState) app.urlState.write();
            var text = window.location.href;
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(text).then(function () { done(true); }, function () { done(legacy(text)); });
            } else {
                done(legacy(text));
            }
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

        wireFullscreen(app);
        wireShare(app);

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

        /* Where the map opens: swap the opening organisations and start
         * over on them. */
        var viewSelect = byId('kop-network-view');
        if (viewSelect) {
            viewSelect.addEventListener('change', function () {
                app.store.setView(viewSelect.value);
                if (app.filters) app.filters.syncFromStore();
                app.focus.restore([], app.focus.mode());
                app.announce('Starting from: ' + viewSelect.options[viewSelect.selectedIndex].text + '.');
            });
        }

        var whole = byId('kop-network-whole-map');
        if (whole) {
            whole.addEventListener('click', function () {
                app.focus.clear();
                app.elements.canvas.focus();
            });
        }
    }

    /**
     * The arrow keys, Enter and the zoom keys are keys.js. This is Escape.
     *
     * Escape returns to the whole map from any depth, the same as the Start
     * over button. Stepping back one crumb at a time is what the breadcrumb is
     * for. It is bound to the canvas rather than the document so it cannot
     * steal Escape from the search box or a dialog elsewhere on the page.
     */
    function wireKeyboard(app) {
        /* A pinned popup holds buttons, so Escape has to work from inside it
         * too, and hands the keyboard back to the map. */
        if (app.popup) {
            app.popup.element.addEventListener('keydown', function (event) {
                if (event.key !== 'Escape' && event.key !== 'Esc') return;
                event.preventDefault();
                app.popup.hide();
                app.elements.canvas.focus();
            });
        }
        app.elements.canvas.addEventListener('keydown', function (event) {
            if (event.key !== 'Escape' && event.key !== 'Esc') return;
            /* The popup first: Escape closes the nearest thing. */
            if (app.popup && app.popup.pinned()) {
                event.preventDefault();
                app.popup.hide();
                return;
            }
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
