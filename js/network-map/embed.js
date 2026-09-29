/**
 * Network map: the map on another page (Phase 4.1).
 *
 * A facility page prints a hidden figure holding a slice of the graph as
 * JSON (kop_network_map_embed_html in inc/network-map.php): the facility,
 * everything the map would show around it, and the lines among them. This
 * paints it with the map's own store, renderer, viewport and focus, so it is
 * the picture the map paints when that name is opened, not a second drawing
 * of it that would drift from the map.
 *
 * It asks nothing of the page but the figure. No trail, no modes, no
 * filters: a click on a name opens that name on the full map, Ctrl- or
 * Cmd-click its profile page where it has one. The wheel scrolls the page;
 * pinch, double click and the buttons zoom. The list of connections under
 * the figure is the text, so the canvas is an image with a label and stays
 * out of the tab order.
 *
 * The figure is hidden until the first paint, so a page without
 * JavaScript, or one where the scripts fail, shows the list alone as it
 * always has. Painting waits until the figure is near the screen.
 */
(function (root) {
    'use strict';

    var ZOOM_STEP = 1.25;

    function readSlice(figure) {
        var holder = figure.querySelector('.kop-network-embed__data');
        if (!holder) return null;
        try {
            var slice = JSON.parse(holder.textContent || '');
            return slice && slice.nodes && slice.root ? slice : null;
        } catch (e) {
            return null;
        }
    }

    /** The full map opened on one name. */
    function mapLink(slice, id) {
        return (slice.mapUrl || '/network-map/') + '#open=' + encodeURIComponent(id);
    }

    /** Node id => profile URL, from the [id, url] pairs the page printed. */
    function profileUrls(slice) {
        var out = Object.create(null);
        (slice.urls || []).forEach(function (pair) {
            if (pair && pair[0] && pair[1]) out[pair[0]] = pair[1];
        });
        return out;
    }

    /**
     * Paint one figure. Returns the parts, for the tests and for a page that
     * wants them, or null when the modules or the data are missing (the
     * figure then stays hidden).
     */
    function start(figure, options) {
        options = options || {};
        var win = options.window || root;
        var slice = options.slice || readSlice(figure);
        var canvas = figure.querySelector('canvas');
        if (!slice || !canvas || !root.KOPNetworkStore || !root.KOPNetworkCanvas ||
            !root.KOPNetworkViewport || !root.KOPNetworkFocus) {
            return null;
        }

        /* Measured from here on, so it has to be in the layout. */
        figure.hidden = false;

        var store = root.KOPNetworkStore.create();
        store.hydrate(slice, slice.layout || null);
        var rootNode = store.node(slice.root);
        if (!rootNode) {
            figure.hidden = true;
            return null;
        }
        var urls = profileUrls(slice);
        var navigate = options.navigate || function (url, newTab) {
            if (newTab) win.open(url, '_blank', 'noopener');
            else win.location.href = url;
        };

        var renderer = root.KOPNetworkCanvas.create(canvas);
        var focus = null;
        var viewport = root.KOPNetworkViewport.create({
            canvas: canvas,
            renderer: renderer,
            wheel: false,
            onHover: function (node) {
                if (focus) focus.hover(node);
            },
            onSelect: function (node, event) {
                if (!node) return;
                var newTab = !!(event && (event.ctrlKey || event.metaKey));
                if (newTab && urls[node.id]) {
                    navigate(urls[node.id], true);
                    return;
                }
                navigate(mapLink(slice, node.id), newTab);
            }
        });
        focus = root.KOPNetworkFocus.create({
            store: store,
            renderer: renderer,
            viewport: viewport
        });

        renderer.useChainIndex(store.chainIndex);
        renderer.useBoardColours(store.meta);
        renderer.resize();
        focus.select(rootNode);

        var buttons = figure.querySelectorAll('[data-kop-embed-zoom]');
        Array.prototype.forEach.call(buttons, function (button) {
            button.addEventListener('click', function () {
                var into = button.getAttribute('data-kop-embed-zoom') === 'in';
                viewport.zoomBy(into ? ZOOM_STEP : 1 / ZOOM_STEP);
            });
        });
        var fit = figure.querySelector('[data-kop-embed-fit]');
        if (fit) fit.addEventListener('click', function () { focus.fitAll(); });

        /* A phone turned on its side, or the column narrowing: frame the
         * names for the stage it has now. Debounced like the map's own. */
        var stage = figure.querySelector('.kop-network-embed__stage') || canvas;
        var pending = 0;
        var onResize = function () {
            viewport.resize();
            if (pending) win.clearTimeout(pending);
            pending = win.setTimeout(function () {
                pending = 0;
                focus.reframe();
            }, 180);
        };
        if (win.ResizeObserver) {
            new win.ResizeObserver(onResize).observe(stage);
        } else if (win.addEventListener) {
            win.addEventListener('resize', onResize);
        }

        figure.setAttribute('data-state', 'ready');
        return { store: store, renderer: renderer, viewport: viewport, focus: focus, slice: slice };
    }

    /** Start each figure once it comes near the screen, or at once. */
    function boot(doc, win) {
        var figures = doc.querySelectorAll('[data-kop-network-embed]');
        Array.prototype.forEach.call(figures, function (figure) {
            var go = function () {
                try {
                    start(figure, { window: win });
                } catch (e) {
                    figure.hidden = true;
                    if (win.console) win.console.error('Network map embed failed.', e);
                }
            };
            if (!win.IntersectionObserver) {
                go();
                return;
            }
            /* The figure is hidden, so it has no box to watch; its section
             * does. */
            var watch = figure.parentNode || figure;
            var observer = new win.IntersectionObserver(function (entries) {
                if (!entries.some(function (entry) { return entry.isIntersecting; })) return;
                observer.disconnect();
                go();
            }, { rootMargin: '300px 0px' });
            observer.observe(watch);
        });
    }

    var api = { start: start, boot: boot, readSlice: readSlice, mapLink: mapLink };
    root.KOPNetworkEmbed = api;

    if (root.document && !root.KOP_NETWORK_EMBED_MANUAL) {
        if (root.document.readyState === 'loading') {
            root.document.addEventListener('DOMContentLoaded', function () { boot(root.document, root); });
        } else {
            boot(root.document, root);
        }
    }
})(typeof self !== 'undefined' ? self : this);
