/**
 * Network map: search.
 *
 * The box the template prints above the stage, as an ARIA combobox over the
 * listbox beside it. Typing ranks every name on the map, visible or not -
 * search is how somebody reaches a name the opening view does not show - and
 * picking one puts it on the board through focus.select(), so it arrives the
 * way a click brings anything: with its connections.
 *
 * The Path form (path.js) uses the same box twice over to name the two ends
 * of a route. There a pick does not open anything: options.onChoose takes the
 * name, and it stays written in the box.
 *
 * Ranking is its own function (rank) so the tests can hold it to account
 * without a document: a name that starts with what was typed beats one where
 * a later word does, which beats a match in the middle of a word; a name
 * beats an alias at the same level; and ties go to the better connected.
 */
(function (root) {
    'use strict';

    var LIMIT = 8;

    /* Case, accents, punctuation and "&" versus "and" do not decide a match. */
    function normalise(text) {
        var s = String(text || '').toLowerCase();
        if (s.normalize) s = s.normalize('NFD').replace(/[̀-ͯ]/g, '');
        return s.replace(/&/g, ' and ').replace(/[^a-z0-9]+/g, ' ').trim();
    }

    /** 0 prefix, 1 a later word starts with it, 2 anywhere, -1 no match. */
    function levelOf(haystack, needle) {
        if (!haystack || !needle) return -1;
        if (haystack.indexOf(needle) === 0) return 0;
        if (haystack.indexOf(' ' + needle) !== -1) return 1;
        if (haystack.indexOf(needle) !== -1) return 2;
        return -1;
    }

    /**
     * The best matches for a query, best first. Each result is
     * {node, level, via} where via is the alias that matched, or '' when the
     * name itself did.
     */
    function rank(nodes, query, limit) {
        var needle = normalise(query);
        if (!needle) return [];
        var max = limit || LIMIT;
        var hits = [];
        nodes.forEach(function (node) {
            var best = levelOf(normalise(node.name), needle);
            var via = '';
            var score = best === -1 ? Infinity : best * 2;
            (node.aliases || []).forEach(function (alias) {
                var level = levelOf(normalise(alias), needle);
                /* An alias match ranks just below a name match at the same
                 * level, so "Provo" finds Provo Canyon School before a place
                 * that was once called something Provo. */
                if (level !== -1 && level * 2 + 1 < score) {
                    score = level * 2 + 1;
                    via = alias;
                }
            });
            if (score === Infinity) return;
            hits.push({ node: node, level: score, via: via });
        });
        hits.sort(function (a, b) {
            if (a.level !== b.level) return a.level - b.level;
            if ((b.node.degree || 0) !== (a.node.degree || 0)) return (b.node.degree || 0) - (a.node.degree || 0);
            return String(a.node.name).localeCompare(String(b.node.name));
        });
        return hits.slice(0, max);
    }

    var KIND_WORDS = {
        facility: 'programme', parent: 'company', person: 'person',
        association: 'trade group', church: 'church', government: 'government body'
    };

    function create(options) {
        var store = options.store;
        var focus = options.focus;
        var input = options.input;
        var list = options.list;
        var announce = options.announce || function () {};
        var document_ = options.document || root.document;
        var onChoose = options.onChoose || null;
        if (!store || (!focus && !onChoose) || !input || !list) return null;
        /* More than one box on the page, so option ids hang off the list's own. */
        var idPrefix = (list.id || 'kop-network-search-results') + '-option-';

        var results = [];
        var active = -1;

        function close() {
            list.hidden = true;
            list.textContent = '';
            input.setAttribute('aria-expanded', 'false');
            input.removeAttribute('aria-activedescendant');
            results = [];
            active = -1;
        }

        function setActive(index) {
            active = index;
            var items = list.children || [];
            for (var i = 0; i < items.length; i++) {
                items[i].setAttribute('aria-selected', i === index ? 'true' : 'false');
            }
            if (index >= 0 && items[index]) {
                input.setAttribute('aria-activedescendant', items[index].id);
            } else {
                input.removeAttribute('aria-activedescendant');
            }
        }

        function render() {
            results = rank(store.nodes, input.value);
            list.textContent = '';
            active = -1;
            if (!results.length) {
                if (String(input.value).trim()) {
                    var none = document_.createElement('li');
                    none.className = 'kop-network__search-none';
                    none.setAttribute('role', 'option');
                    none.setAttribute('aria-disabled', 'true');
                    none.id = idPrefix + 'none';
                    none.textContent = 'No name on the map matches.';
                    list.appendChild(none);
                    list.hidden = false;
                    input.setAttribute('aria-expanded', 'true');
                } else {
                    close();
                }
                return;
            }
            results.forEach(function (hit, index) {
                var item = document_.createElement('li');
                item.id = idPrefix + index;
                item.className = 'kop-network__search-option';
                item.setAttribute('role', 'option');
                item.setAttribute('aria-selected', 'false');
                var name = document_.createElement('span');
                name.className = 'kop-network__search-name';
                name.textContent = hit.node.name;
                item.appendChild(name);
                var meta = document_.createElement('span');
                meta.className = 'kop-network__search-meta';
                meta.textContent = (KIND_WORDS[hit.node.kind] || hit.node.kind || '') +
                    (hit.via ? ' - also called ' + hit.via : '');
                item.appendChild(meta);
                item.addEventListener('mousedown', function (event) {
                    /* mousedown, not click: the input's blur would close the
                     * list before a click could land on it. */
                    event.preventDefault();
                    pick([hit.node]);
                });
                list.appendChild(item);
            });
            list.hidden = false;
            input.setAttribute('aria-expanded', 'true');
            announce(results.length + (results.length === 1 ? ' match.' : ' matches.') +
                ' Use the arrow keys to choose, Enter to open.');
        }

        /** Put the chosen names on the board, with their connections. */
        function pick(nodes) {
            close();
            if (onChoose) {
                /* Naming an end of a route: the name stays in the box. */
                if (!nodes.length) return;
                input.value = nodes[0].name;
                onChoose(nodes[0]);
                return;
            }
            input.value = '';
            if (!nodes.length) return;
            if (nodes.length === 1) {
                focus.select(nodes[0]);
            } else {
                focus.openAll(nodes);
            }
            if (options.onPick) options.onPick(nodes);
        }

        input.addEventListener('input', render);
        input.addEventListener('focus', function () {
            if (String(input.value).trim()) render();
        });
        input.addEventListener('blur', function () {
            /* After the mousedown on an option has had its turn. */
            root.setTimeout(close, 0);
        });
        input.addEventListener('keydown', function (event) {
            var key = event.key;
            if (key === 'ArrowDown' || key === 'Down') {
                if (!results.length) render();
                if (!results.length) return;
                event.preventDefault();
                setActive(active < results.length - 1 ? active + 1 : 0);
            } else if (key === 'ArrowUp' || key === 'Up') {
                if (!results.length) return;
                event.preventDefault();
                setActive(active > 0 ? active - 1 : results.length - 1);
            } else if (key === 'Enter') {
                if (!results.length) render();
                if (!results.length) return;
                event.preventDefault();
                /* A chosen option opens that one; Enter on the typed text
                 * alone opens every match together, so "Aspen" puts the
                 * Aspen programmes on the board side by side. */
                pick(active >= 0 ? [results[active].node] : results.map(function (h) { return h.node; }));
            } else if (key === 'Escape' || key === 'Esc') {
                if (list.hidden) return;
                event.preventDefault();
                close();
            }
        });

        return { rank: rank, close: close, render: render, pick: pick };
    }

    root.KOPNetworkSearch = { create: create, rank: rank, normalise: normalise, LIMIT: LIMIT };
})(typeof self !== 'undefined' ? self : this);
