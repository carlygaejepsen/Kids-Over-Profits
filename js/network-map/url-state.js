/**
 * Network map: URL state.
 *
 * The trail and the click mode live in the location hash, so a view can be
 * linked to, bookmarked, and survives a reload:
 *
 *   #open=provo-canyon-school,wwasps&mode=expand
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

    /** {ids: [...], mode: 'focus'|'expand'|null} from a hash string. */
    function parse(hash) {
        var out = { ids: [], mode: null };
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
            } else if (key === 'mode' && (value === 'focus' || value === 'expand')) {
                out.mode = value;
            }
        });
        return out;
    }

    /** The hash for a trail, or '' for the opening view. */
    function format(ids, mode) {
        if (!ids || !ids.length) return '';
        var parts = ['open=' + ids.map(encodeURIComponent).join(',')];
        /* Focus is the default, so it is left out and a plain link stays
         * short. */
        if (mode === 'expand') parts.push('mode=expand');
        return '#' + parts.join('&');
    }

    function create(options) {
        var focus = options.focus;
        var location_ = options.location || root.location;
        var history_ = options.history || root.history;
        if (!focus || !location_) return null;

        var writing = false;

        /** Mirror the trail into the address bar. */
        function write() {
            var hash = format(focus.chain(), focus.mode());
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
            if (!state.ids.length && !focus.chain().length) return;
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
