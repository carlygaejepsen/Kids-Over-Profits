/**
 * Network map: how two names are connected.
 *
 * The trail walks the graph a click at a time, which answers "how is A
 * connected to B" only for somebody who already suspects the route. This asks
 * the store instead (store.paths): name two ends, and every route between
 * them of six steps or fewer comes back, shortest first.
 *
 * Two pieces of page. The Path button in the toolbar opens a small form, two
 * search boxes and a button, which goes away again once it has an answer so
 * that it is never sitting over the route it found. The answer itself is
 * read in the drawer, which shows the route on the board as a list - each
 * name, and under it what the record says joins it to the next - and then
 * every other route found, each a button that puts that one on the board
 * instead. The drawer is where a name is already read about, it does not
 * cover the stage, and on a phone it is already a sheet.
 *
 * The board draws the route (focus.showPath); this module never touches the
 * canvas. describeRoute() and labelFor() are DOM-free so
 * scripts/test-network-modules.js can hold them to account.
 */
(function (root) {
    'use strict';

    var KIND_WORDS = {
        facility: 'program', parent: 'company', person: 'person',
        association: 'trade group', church: 'church', government: 'government body'
    };

    /**
     * A route as the record tells it: one entry per name, each but the last
     * carrying what joins it to the next.
     *
     *   [{ node, joins: ['Ownership: operated 1993-1999', ...] }, ..., { node, joins: [] }]
     */
    function describeRoute(store, ids, connection, canvasApi) {
        var styleFor = canvasApi && canvasApi.styleFor
            ? function (edge) { return canvasApi.styleFor(edge, false); }
            : null;
        return ids.map(function (id, index) {
            var joins = [];
            if (index < ids.length - 1 && connection && connection.describe) {
                var said = connection.describe(store.edgesBetween(id, ids[index + 1]), styleFor, canvasApi);
                ((said && said.items) || []).forEach(function (item) {
                    var text = item.kind === 'person'
                        ? item.name + (item.note ? ' ' + item.note : '')
                        : item.label + (item.text ? ': ' + item.text : '') +
                            (item.source ? ' (' + item.source + ')' : '');
                    if (joins.indexOf(text) === -1) joins.push(text);
                });
            }
            return { node: store.node(id), joins: joins };
        });
    }

    /** What a route is called in the list: the names it passes through. */
    function labelFor(store, route) {
        var between = route.ids.slice(1, -1).map(function (id) {
            var node = store.node(id);
            return node ? node.name : id;
        });
        return between.length ? 'through ' + between.join(', then ') : 'directly';
    }

    function stepsWord(hops) {
        return hops + (hops === 1 ? ' step' : ' steps');
    }

    function sameRoute(a, b) {
        return a.length === b.length && a.every(function (id, i) { return id === b[i]; });
    }

    function create(options) {
        var store = options.store;
        var focus = options.focus;
        var announce = options.announce || function () {};
        var document_ = options.document || root.document;
        var connection = options.connection || root.KOPNetworkConnection;
        var canvasApi = options.canvasApi || root.KOPNetworkCanvas;
        var searchApi = options.searchApi || root.KOPNetworkSearch;
        var els = options.elements || {};
        if (!store || !focus) return null;

        /* The two ends last asked about, and what the store found for them. */
        var asked = { from: null, to: null, routes: [] };
        var chosen = { from: null, to: null };

        function el(tag, className, text) {
            var node = document_.createElement(tag);
            if (className) node.className = className;
            if (text !== undefined) node.textContent = text;
            return node;
        }

        function say(text) {
            if (els.message) els.message.textContent = text || '';
        }

        /** Routes between two ends, found once per pair and per filter change. */
        function routesFor(fromId, toId) {
            var key = store.revision();
            if (asked.from !== fromId || asked.to !== toId || asked.revision !== key) {
                asked = { from: fromId, to: toId, revision: key, routes: store.paths(fromId, toId) };
            }
            return asked.routes;
        }

        /* ------------------------------------------------------ the form -- */

        function setOpen(open) {
            if (!els.panel) return;
            els.panel.hidden = !open;
            if (els.toggle) els.toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
            if (!open) return;
            say('');
            /* Whatever is open on the map is the likeliest first end. */
            var trail = focus.chain();
            if (els.from && !String(els.from.value || '').trim() && trail.length && !focus.isPath()) {
                var head = store.node(trail[trail.length - 1]);
                if (head) { chosen.from = head; els.from.value = head.name; }
            }
            var first = els.from && !String(els.from.value || '').trim() ? els.from : els.to;
            if (first && first.focus) first.focus();
        }

        /* A box holds a name somebody picked, or text they are still typing;
         * typed text is taken as its best match when the form is sent. */
        function endOf(which) {
            var input = els[which];
            var text = input ? String(input.value || '').trim() : '';
            if (chosen[which] && chosen[which].name === text) return chosen[which];
            if (!text || !searchApi) return null;
            var hit = searchApi.rank(store.nodes, text, 1)[0];
            if (hit && input) input.value = hit.node.name;
            return hit ? hit.node : null;
        }

        /**
         * Find the routes between two names and put the shortest on the
         * board. Returns the routes, so the tests can call it without a form.
         */
        function find(from, to) {
            if (!from || !to) {
                say('Choose two names from the map.');
                return [];
            }
            if (from.id === to.id) {
                say('Those are the same name. Choose two different ones.');
                return [];
            }
            var live = store.visible().nodeIds;
            if (!live[from.id] || !live[to.id]) {
                say((live[from.id] ? to.name : from.name) + ' is hidden by the filters in the Key.');
                return [];
            }
            var routes = routesFor(from.id, to.id);
            if (!routes.length) {
                say('No route of six steps or fewer joins ' + from.name + ' and ' + to.name +
                    '. Routes never pass through a trade association, and use only the connection types switched on in the Key.');
                announce('No route found between ' + from.name + ' and ' + to.name + '.');
                return [];
            }
            focus.showPath(routes[0].ids);
            setOpen(false);
            return routes;
        }

        if (searchApi && els.from && els.fromList) {
            searchApi.create({
                store: store, input: els.from, list: els.fromList, document: document_, announce: announce,
                onChoose: function (node) { chosen.from = node; if (els.to && els.to.focus) els.to.focus(); }
            });
        }
        if (searchApi && els.to && els.toList) {
            searchApi.create({
                store: store, input: els.to, list: els.toList, document: document_, announce: announce,
                onChoose: function (node) { chosen.to = node; }
            });
        }

        if (els.toggle) {
            els.toggle.addEventListener('click', function () {
                setOpen(els.panel ? els.panel.hidden : false);
            });
        }
        if (els.close) {
            els.close.addEventListener('click', function () {
                setOpen(false);
                if (els.toggle && els.toggle.focus) els.toggle.focus();
            });
        }
        if (els.swap) {
            els.swap.addEventListener('click', function () {
                var text = els.from.value;
                els.from.value = els.to.value;
                els.to.value = text;
                var node = chosen.from;
                chosen.from = chosen.to;
                chosen.to = node;
            });
        }
        if (els.form) {
            els.form.addEventListener('submit', function (event) {
                if (event && event.preventDefault) event.preventDefault();
                find(endOf('from'), endOf('to'));
            });
        }
        if (els.panel) {
            els.panel.addEventListener('keydown', function (event) {
                if (event.key !== 'Escape' && event.key !== 'Esc') return;
                /* A search list that was open takes Escape first. It has
                 * closed itself by the time the key bubbles up to here, so
                 * its preventDefault is the only sign it was ever open. */
                if (event.defaultPrevented) return;
                event.preventDefault();
                setOpen(false);
                if (els.toggle && els.toggle.focus) els.toggle.focus();
            });
        }

        /* ---------------------------------------------------- the drawer -- */

        /**
         * The route on the board, written out, and the other routes found.
         * Called by the drawer when the trail is a route.
         */
        function renderInto(body) {
            var ids = focus.chain();
            if (!focus.isPath() || ids.length < 2) return false;
            var from = store.node(ids[0]);
            var to = store.node(ids[ids.length - 1]);
            if (!from || !to) return false;
            var routes = routesFor(from.id, to.id);

            body.textContent = '';
            body.appendChild(el('h2', 'kop-network__drawer-title', from.name + ' to ' + to.name));
            body.appendChild(el('p', 'kop-network__drawer-meta',
                'A route of ' + stepsWord(ids.length - 1) + ', as recorded. Each line is a documented connection, not an allegation.'));

            var list = el('ol', 'kop-network__route');
            describeRoute(store, ids, connection, canvasApi).forEach(function (step) {
                if (!step.node) return;
                var item = el('li', 'kop-network__route-step');
                var open = el('button', 'kop-network__drawer-link', step.node.name);
                open.type = 'button';
                open.setAttribute('data-id', step.node.id);
                open.setAttribute('aria-label', 'Open ' + step.node.name + ' and leave this route');
                open.addEventListener('click', function () { focus.select(step.node); });
                item.appendChild(open);
                item.appendChild(el('span', 'kop-network__drawer-source', KIND_WORDS[step.node.kind] || step.node.kind || ''));
                step.joins.forEach(function (text) {
                    item.appendChild(el('span', 'kop-network__route-join', text));
                });
                list.appendChild(item);
            });
            body.appendChild(list);

            if (routes.length > 1 || (routes.length === 1 && !sameRoute(routes[0].ids, ids))) {
                body.appendChild(el('h3', 'kop-network__drawer-group',
                    (routes.length >= store.PATH_LIMIT ? 'The ' + routes.length + ' shortest routes' : 'All ' + routes.length + ' routes') +
                    ' of six steps or fewer'));
                var others = el('ul', 'kop-network__drawer-list');
                routes.forEach(function (route) {
                    var row = el('li');
                    var show = el('button', 'kop-network__drawer-link', labelFor(store, route));
                    show.type = 'button';
                    if (sameRoute(route.ids, ids)) show.setAttribute('aria-current', 'true');
                    show.addEventListener('click', function () { focus.showPath(route.ids); });
                    row.appendChild(show);
                    row.appendChild(el('span', 'kop-network__drawer-source', stepsWord(route.hops)));
                    others.appendChild(row);
                });
                body.appendChild(others);
            }
            return true;
        }

        /**
         * Open the form with one end already named: the drawer's "How is
         * this connected to...?" button. The other box is emptied, since
         * whatever was there was asked about a different name, and takes the
         * cursor.
         */
        function startFrom(node) {
            if (!node) return;
            chosen.from = node;
            chosen.to = null;
            if (els.from) els.from.value = node.name;
            if (els.to) els.to.value = '';
            setOpen(true);
            if (els.toggle && els.toggle.scrollIntoView) els.toggle.scrollIntoView({ block: 'nearest' });
        }

        return {
            find: find, renderInto: renderInto, open: function () { setOpen(true); }, startFrom: startFrom,
            close: function () { setOpen(false); }, routesFor: routesFor
        };
    }

    root.KOPNetworkPath = { create: create, describeRoute: describeRoute, labelFor: labelFor };
})(typeof self !== 'undefined' ? self : this);
