/**
 * Network map: what a line says.
 *
 * Two places that shared a therapist are joined by one line, and the
 * therapist is not a name on the board: they are the line (focus.js,
 * foldConnectors). So is an owner's tenure, a rename, a board seat. A line
 * is where the record lives, and this module is how it is read - hover a
 * line and a popup says what joins its two ends: who, in what role at each
 * place, or which company owned which and when.
 *
 * Hovering shows the popup beside the pointer and takes it away again.
 * Clicking the line pins it, which is what makes the people in it buttons
 * that open them, and is the only way in at all on a touch screen, where
 * nothing hovers. A click anywhere else, Escape, or the view changing under
 * it puts it away.
 *
 * The people on a line are also drawn on it, as circles (canvas.js), and
 * pointing at a circle asks about that one person: the popup then carries
 * only them, with their role at each end. The "+N" pill on a line with
 * more people than room carries the rest.
 *
 * describe() is DOM-free, so scripts/test-network-modules.js can check what
 * a line says without a document.
 */
(function (root) {
    'use strict';

    /* How far from the pointer the popup sits, in pixels. */
    var OFFSET = 14;

    /**
     * A recorded edge's own words: the relationship as the board wrote it
     * ("cofounder/CEO"), or for a connection the build added, the roles it
     * read. Nothing for a line the board left unlabelled, which the build
     * files as "affiliated" - its reading, not the board's words.
     */
    function wordsOf(edge) {
        var text = edge.provenance ? (edge.roles || []).join(' / ') : String(edge.raw || '');
        text = text.replace(/\s+/g, ' ').trim();
        return /^affiliated$/i.test(text) ? '' : text;
    }

    /**
     * Everything the lines between two names say, as plain data:
     *
     *   { a, b, items: [...], text }
     *
     * a and b are the two nodes. Each item is either a person who joins
     * them - { kind: 'person', node, name, places: [{ name, role }] } - or
     * something the record says of the two directly - { kind: 'record',
     * label, text, source }. `text` is the whole of it in one sentence, for
     * the live region. People the staff list names in a line's text were
     * never nodes, so they come without one and cannot be opened.
     *
     * Each person carries a `key` - their node's id, or their name when
     * they have no node - which is what canvas.js keys the circles on a
     * line by. With `only` (a list of keys) the answer is just those
     * people: what a circle, or the "+N" pill, stands for.
     */
    function describe(edges, styleFor, canvasApi, only) {
        edges = (edges || []).filter(Boolean);
        if (!edges.length) return null;
        var a = edges[0].source;
        var b = edges[0].target;
        var items = [];
        var seenPerson = Object.create(null);

        edges.forEach(function (edge) {
            var peopleLine = canvasApi && canvasApi.isPeopleLine && canvasApi.isPeopleLine(edge);

            (edge.via || []).forEach(function (via) {
                if (seenPerson[via.person.id]) return;
                seenPerson[via.person.id] = true;
                items.push({
                    kind: 'person',
                    key: via.person.id,
                    node: via.person,
                    name: via.person.name,
                    places: [a, b].map(function (place) {
                        var roles = [];
                        (via.at[place.id] || []).forEach(function (e) {
                            var words = wordsOf(e);
                            if (words && roles.indexOf(words) === -1) roles.push(words);
                        });
                        return { name: place.name, role: roles.join('; ') };
                    })
                });
            });

            /* A line the map made up to carry folded people has nothing of
             * its own to add. */
            if (edge.provenance === 'fold') return;

            if (peopleLine) {
                /* "X worked at both A (role) and B (role) (staff list); Y ..." */
                String(edge.raw || '').split('; ').forEach(function (part) {
                    part = part.replace(/\s+/g, ' ').trim();
                    if (!part) return;
                    var name = part.split(/ \(| worked at | moved from /)[0].trim();
                    items.push({
                        kind: 'person', key: name || part, node: null, name: name || part,
                        places: [], note: part.slice(name.length).trim()
                    });
                });
                return;
            }

            var style = styleFor ? styleFor(edge) : null;
            var label = (style && style.label) || 'Connection';
            var text = wordsOf(edge);
            if (edge.direction === 'renamed') text = edge.source.name + ' became ' + edge.target.name;
            else if (edge.direction === 'acquirer') {
                text = edge.source.name + ' acquired ' + edge.target.name + (text ? ' (' + text + ')' : '');
            }
            items.push({
                kind: 'record', label: label, text: text,
                source: edge.provenance === 'profile' ? 'from the profile' : ''
            });
        });

        if (only) {
            items = items.filter(function (item) {
                return item.kind === 'person' && only.indexOf(item.key) !== -1;
            });
        }

        var said = items.map(function (item) {
            if (item.kind === 'person') return item.name;
            return item.label + (item.text ? ' (' + item.text + ')' : '');
        });
        return {
            a: a, b: b, items: items,
            text: a.name + ' and ' + b.name + ': ' + said.join('; ') + '.'
        };
    }

    function create(options) {
        var stage = options.stage;
        var focus = options.focus;
        var renderer = options.renderer;
        var announce = options.announce || function () {};
        var document_ = options.document || root.document;
        var canvasApi = options.canvasApi || root.KOPNetworkCanvas;
        if (!stage || !focus || !renderer || !document_) return null;

        var box = document_.createElement('div');
        box.className = 'kop-network__popup';
        box.hidden = true;
        stage.appendChild(box);

        /* The pair the popup currently describes, and whether it is pinned. */
        var shownKey = null;
        var pinned = false;

        function el(tag, className, text) {
            var node = document_.createElement(tag);
            if (className) node.className = className;
            if (text !== undefined) node.textContent = text;
            return node;
        }

        function keyOf(edge) {
            return edge.sourceId < edge.targetId
                ? edge.sourceId + '|' + edge.targetId : edge.targetId + '|' + edge.sourceId;
        }

        function styleOf(edge) {
            return renderer.styleOf ? renderer.styleOf(edge)
                : (canvasApi && canvasApi.styleFor ? canvasApi.styleFor(edge, false) : null);
        }

        function render(said, interactive, marker) {
            box.textContent = '';
            box.appendChild(el('p', 'kop-network__popup-title', said.a.name + ' – ' + said.b.name));

            var list = el('ul', 'kop-network__popup-list');
            said.items.forEach(function (item) {
                var row = el('li', 'kop-network__popup-item');
                if (item.kind === 'record') {
                    row.appendChild(el('span', 'kop-network__popup-label', item.label));
                    if (item.text) row.appendChild(el('span', 'kop-network__popup-text', item.text));
                    if (item.source) row.appendChild(el('span', 'kop-network__popup-source', item.source));
                    list.appendChild(row);
                    return;
                }
                if (item.node && interactive) {
                    var open = el('button', 'kop-network__popup-person', item.name);
                    open.type = 'button';
                    open.setAttribute('aria-label', 'Open ' + item.name);
                    open.addEventListener('click', function () {
                        hide();
                        focus.select(item.node);
                    });
                    row.appendChild(open);
                } else {
                    row.appendChild(el('span', 'kop-network__popup-person', item.name));
                }
                item.places.forEach(function (place) {
                    row.appendChild(el('span', 'kop-network__popup-text',
                        place.name + ': ' + (place.role || 'role not recorded')));
                });
                if (item.note) row.appendChild(el('span', 'kop-network__popup-text', item.note));
                list.appendChild(row);
            });
            box.appendChild(list);

            if (!interactive && said.items.some(function (item) { return item.kind === 'person' && item.node; })) {
                box.appendChild(el('p', 'kop-network__popup-hint', marker
                    ? (marker.kind === 'person' ? 'Click the circle to open them.' : 'Click for the others.')
                    : 'Click the line to open a person.'));
            }
        }

        /* Beside the pointer, on whichever side keeps it on the stage. */
        function place(point) {
            if (!point) return;
            var width = box.offsetWidth || 240;
            var height = box.offsetHeight || 80;
            var stageW = stage.clientWidth || renderer.width || 0;
            var stageH = stage.clientHeight || renderer.height || 0;
            var x = point.x + OFFSET;
            var y = point.y + OFFSET;
            if (stageW && x + width > stageW - 4) x = point.x - OFFSET - width;
            if (stageH && y + height > stageH - 4) y = point.y - OFFSET - height;
            box.style.left = Math.max(4, x) + 'px';
            box.style.top = Math.max(4, y) + 'px';
        }

        function show(edge, point, interactive, marker) {
            var only = marker ? marker.people.map(function (p) { return p.key; }) : null;
            var said = describe(focus.linesBetween(edge.sourceId, edge.targetId).concat([edge]).filter(
                function (e, i, all) { return all.indexOf(e) === i; }), styleOf, canvasApi, only);
            if (!said || !said.items.length) { hide(); return null; }
            var key = keyOf(edge) + (marker ? ':' + marker.key : '') + (interactive ? ':pinned' : '');
            if (key !== shownKey) {
                render(said, interactive, marker);
                shownKey = key;
            }
            box.hidden = false;
            box.setAttribute('data-pinned', interactive ? 'true' : 'false');
            place(point);
            return said;
        }

        function hide() {
            if (box.hidden && !pinned) return;
            box.hidden = true;
            box.textContent = '';
            shownKey = null;
            pinned = false;
            focus.hoverEdge(null);
        }

        var popup = {
            element: box,
            pinned: function () { return pinned; },
            describe: function (edge) {
                return describe(focus.linesBetween(edge.sourceId, edge.targetId), styleOf, canvasApi);
            },
            /** The pointer is over a line, or a circle on one (`marker`),
             * or (null) has left it. */
            hover: function (edge, point, marker) {
                if (pinned) return;
                if (!edge) { hide(); return; }
                /* Not while a click is still moving the lines about. */
                if (focus.hoverEdge(edge, marker) === false) { hide(); return; }
                show(edge, point, false, marker);
            },
            /** A line or a circle on one was clicked, or (null) the click
             * landed on nothing. */
            pin: function (edge, point, marker) {
                if (!edge) { hide(); return; }
                pinned = false;
                if (focus.hoverEdge(edge, marker) === false) { hide(); return; }
                var said = show(edge, point, true, marker);
                pinned = !!said;
                if (said) announce(said.text);
            },
            hide: hide
        };
        return popup;
    }

    var api = { create: create, describe: describe, wordsOf: wordsOf };

    if (typeof module === 'object' && module.exports) module.exports = api;
    else root.KOPNetworkConnection = api;
})(typeof self !== 'undefined' ? self : this);
