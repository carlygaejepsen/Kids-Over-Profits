/**
 * Network map: URL state.
 *
 * The trail and the click mode live in the location hash, so a view can be
 * linked to, bookmarked, and survives a reload:
 *
 *   #open=provo-canyon-school,wwasps&mode=expand
 *
 * A route between two names (focus.showPath) is a trail too, in its own mode:
 *
 *   #open=david-gilcrease,wwasps,provo-canyon-school&mode=path
 *
 * Simplify (focus.setSimple) rides along as simple=1, trail or no trail:
 *
 *   #open=sequel-youth-and-family-services&simple=1
 *
 * The hash rather than the query string, because nothing here needs the
 * server and a query change would reload the page. replaceState rather than
 * pushState: every click would otherwise be a Back step, and the breadcrumb
 * is already the way back through a trail.
 *
 * parse() and format() are pure so the tests can hold them to a round trip.
 */
(function (root) {
    'use strict';

    /** {ids: [...], mode: 'focus'|'expand'|'path'|null, view: key|null, simple: bool} from a hash string. */
    function parse(hash) {
        var out = { ids: [], mode: null, view: null, simple: false };
        var text = String(hash || '').replace(/^#/, '');
        if (!text) return out;
        text.split('&').forEach(function (pair) {
            var at = pair.indexOf('=');
            if (at === -1) return;
            var key = pair.slice(0, at);
            var value;
            try {
                value = decodeURIComponent(pair.slice(at + 1).replace(/\+/g, ' '));
            } catch (e) {
                return;   // a mangled link opens the default view, not an error
            }
            if (key === 'open') {
                out.ids = value.split(',').map(function (id) { return id.trim(); }).filter(Boolean);
            } else if (key === 'mode' && (value === 'focus' || value === 'expand' || value === 'path')) {
                out.mode = value;
            } else if (key === 'view' && /^[a-z0-9-]+$/.test(value)) {
                out.view = value;
            } else if (key === 'simple') {
                out.simple = value === '1';
            }
        });
        return out;
    }

    /** The hash for a trail, a starter view and Simplify, or '' for the plain opening view. */
    function format(ids, mode, view, simple) {
        var parts = [];
        if (ids && ids.length) {
            parts.push('open=' + ids.map(encodeURIComponent).join(','));
            /* Focus is the default, so it is left out and a plain link stays
             * short. */
            if (mode === 'expand' || mode === 'path') parts.push('mode=' + mode);
        }
        if (view && view !== 'default') parts.push('view=' + encodeURIComponent(view));
        if (simple) parts.push('simple=1');
        return parts.length ? '#' + parts.join('&') : '';
    }

    function create(options) {
        var focus = options.focus;
        var location_ = options.location || root.location;
        var history_ = options.history || root.history;
        if (!focus || !location_) return null;

        var writing = false;

        /** Mirror the trail into the address bar. */
        function write() {
            var hash = format(focus.chain(), focus.mode(), options.view ? options.view() : null,
                focus.isSimple ? focus.isSimple() : false);
            if ((location_.hash || '') === hash) return;
            writing = true;
            var base = String(location_.href || '').split('#')[0];
            if (history_ && history_.replaceState) {
                history_.replaceState(null, '', base + hash);
            } else {
                location_.hash = hash;
            }
            writing = false;
        }

        /** Open whatever the address bar describes. */
        function read() {
            var state = parse(location_.hash);
            var viewChanged = options.onView ? options.onView(state.view || 'default') : false;
            if (focus.setSimple && focus.isSimple() !== state.simple) focus.setSimple(state.simple);
            if (!state.ids.length && !focus.chain().length && !viewChanged) return;
            focus.restore(state.ids, state.mode);
        }

        if (options.window && options.window.addEventListener) {
            options.window.addEventListener('hashchange', function () {
                if (!writing) read();
            });
        }

        return { read: read, write: write };
    }

    root.KOPNetworkUrlState = { create: create, parse: parse, format: format };
})(typeof self !== 'undefined' ? self : this);
