/**
 * Proper names in the browser: kopProperNames(text) spells every name on the
 * sitewide list (KOP Tools > Proper Names, window.KOP_PROPER_NAMES sent with
 * the page by inc/proper-names.php) the way it is written there. Every script
 * that changes the case of text passes its result through it as a last step.
 * Same rules as kop_proper_names_apply(): whole words, any capitals, longest
 * name first, any spacing inside a name of several words.
 */
(function (root) {
    'use strict';

    function key(text) {
        return String(text).replace(/\u2019/g, "'").replace(/\s+/g, ' ').trim().toLowerCase();
    }

    function build(names) {
        var map = {};
        (names || []).forEach(function (name) {
            var k = key(name || '');
            if (k && !Object.prototype.hasOwnProperty.call(map, k)) map[k] = String(name).replace(/\s+/g, ' ').trim();
        });
        var alts = Object.keys(map).sort(function (a, b) {
            return (b.length - a.length) || (a < b ? -1 : a > b ? 1 : 0);
        });
        if (!alts.length) return null;
        var parts = alts.map(function (k) {
            return k.replace(/[.*+?^${}()|[\]\\\/]/g, '\\$&').replace(/'/g, "['\u2019]").replace(/ /g, '\\s+');
        });
        return {
            re: new RegExp('(?<![\\p{L}\\p{N}])(?:' + parts.join('|') + ')(?![\\p{L}\\p{N}])', 'giu'),
            map: map
        };
    }

    var compiled;

    function apply(text, names) {
        if (typeof text !== 'string' || text === '') return text;
        var c;
        if (names) {
            c = build(names);
        } else {
            if (compiled === undefined) compiled = build(root.KOP_PROPER_NAMES || []);
            c = compiled;
        }
        if (!c) return text;
        return text.replace(c.re, function (m) {
            var k = key(m);
            return Object.prototype.hasOwnProperty.call(c.map, k) ? c.map[k] : m;
        });
    }

    root.kopProperNames = apply;
    if (typeof module !== 'undefined' && module.exports) module.exports = apply;
})(typeof window !== 'undefined' ? window : globalThis);
