/**
 * Network map: the board as a list.
 *
 * The canvas is one element standing for every name on it. The arrow keys
 * (keys.js) and the drawer make it usable without a pointer, but there is
 * still no way to read the whole board as text, sort it, or take it away.
 * The Map / List switch shows what the board shows as two sortable tables
 * in place of the canvas - one row per name, one row per recorded line -
 * and each table downloads as CSV, which is how a researcher gets the
 * board into a spreadsheet. The list is also the screen-reader path to
 * the picture itself.
 *
 * The list follows the board: open a name, switch modes, turn on Simplify,
 * press Show all, light a route, and the tables re-render from the same
 * scene the canvas draws (app.js calls render() on every focus change).
 * The people folded into lines (focus.js, foldConnectors) are names here,
 * marked "(drawn on a line)", and their recorded lines are rows of the
 * connections table: the list is the record, not the drawing.
 *
 * rows(), sortRows() and toCsv() are DOM-free so
 * scripts/test-network-modules.js can hold them to account; create() builds
 * the tables with only what the test DOM stub supports (no classList, no
 * innerHTML, no dataset).
 */
(function (root) {
    'use strict';

    /* The drawer's own words for what a thing is and whether it still
     * operates; kept in step with drawer.js, whose factsFor() joins them. */
    var KIND_WORDS = {
        facility: 'Program', parent: 'Company', person: 'Person',
        association: 'Trade group', church: 'Church', government: 'Government body'
    };
    var STATUS_WORDS = {
        open: 'Operating', closed: 'Closed', rebranded: 'Rebranded',
        unknown: 'Status not recorded'
    };

    var NAME_COLUMNS = [
        { key: 'name', label: 'Name' },
        { key: 'shownAs', label: 'Shown as' },
        { key: 'kind', label: 'Kind' },
        { key: 'status', label: 'Status' },
        { key: 'years', label: 'Years' },
        { key: 'group', label: 'Group' },
        { key: 'natsap', label: 'NATSAP' },
        { key: 'connections', label: 'Connections on the board' },
        { key: 'hidden', label: 'Not on the board' },
        { key: 'deaths', label: 'Deaths recorded' },
        { key: 'profile', label: 'Profile' }
    ];
    var CONNECTION_COLUMNS = [
        { key: 'from', label: 'From' },
        { key: 'to', label: 'To' },
        { key: 'connection', label: 'Connection' },
        { key: 'role', label: 'Role' },
        { key: 'source', label: 'Source' }
    ];

    /* A connection's own words and where it came from, as the drawer says
     * them (drawer.js groupsFor): the staff list's raw text says who moved
     * where, so it is the source and the role stays empty. */
    function roleOf(edge) {
        if (edge.provenance === 'staff-movement' || edge.provenance === 'staff-list') return '';
        var words = root.KOPNetworkConnection && root.KOPNetworkConnection.wordsOf;
        return words ? words(edge) : '';
    }

    function sourceOf(edge) {
        if (edge.provenance === 'profile') return 'from the profile';
        if (edge.provenance === 'staff-movement' || edge.provenance === 'staff-list') {
            return String(edge.raw || '');
        }
        return '';
    }

    /** One connections-table row for a recorded edge. */
    function connectionRow(edge) {
        var style = root.KOPNetworkCanvas && root.KOPNetworkCanvas.styleFor
            ? root.KOPNetworkCanvas.styleFor(edge, false) : null;
        return {
            id: edge.id,
            fromId: edge.sourceId, toId: edge.targetId,
            from: edge.source.name, to: edge.target.name,
            connection: (style && style.label) || 'Other connection',
            role: roleOf(edge),
            source: sourceOf(edge)
        };
    }

    /**
     * The board as plain data: { names: [...], connections: [...] }.
     *
     * One connections row per recorded line between two names on the board,
     * never the synthetic line a folded person is drawn as: the person's own
     * recorded lines to each place are the rows, so someone drawn as one
     * line between two places is two rows here. One names row per name on
     * the board, the folded people included and said to be drawn on a line.
     */
    function rows(store, scene, config) {
        var connections = [];
        var linesOf = Object.create(null);
        var seenPerson = Object.create(null);
        var seenEdge = Object.create(null);
        var count = function (row) {
            connections.push(row);
            linesOf[row.fromId] = (linesOf[row.fromId] || 0) + 1;
            linesOf[row.toId] = (linesOf[row.toId] || 0) + 1;
        };
        scene.edges.forEach(function (edge) {
            /* A recorded line between two drawn names is a row of its own;
             * the line the map made up to carry folded people is not. */
            if (edge.provenance !== 'fold' && !seenEdge[edge.id]) {
                seenEdge[edge.id] = true;
                count(connectionRow(edge));
            }
            /* The people riding on the line: each one once, with every
             * recorded line they have to a place on the board. */
            (edge.via || []).forEach(function (via) {
                if (!via.person || seenPerson[via.person.id]) return;
                seenPerson[via.person.id] = true;
                Object.keys(via.at).forEach(function (placeId) {
                    via.at[placeId].forEach(function (recorded) {
                        if (seenEdge[recorded.id]) return;
                        seenEdge[recorded.id] = true;
                        count(connectionRow(recorded));
                    });
                });
            });
        });

        var profileFor = root.KOPNetworkDrawer && root.KOPNetworkDrawer.profileFor;
        var nameRow = function (node, folded) {
            var profile = profileFor ? profileFor(node, config) : null;
            /* The timeline's year: a dated name that was not operating is
             * faded on the board, and says so here. */
            var faded = store.year !== null && store.year !== undefined && store.yearState &&
                store.yearState(node, store.year) === 'off';
            var shown = [folded ? '(drawn on a line)' : '', faded ? 'not operating in ' + store.year : '']
                .filter(Boolean).join('; ');
            return {
                id: node.id,
                name: node.name,
                faded: faded,
                shownAs: shown,
                kind: KIND_WORDS[node.kind] || node.kind || '',
                status: STATUS_WORDS[node.status] || '',
                years: node.years || '',
                group: node.chain || '',
                natsap: node.natsap ? 'Yes' : '',
                connections: linesOf[node.id] || 0,
                hidden: scene.hidden[node.id] || 0,
                deaths: node.deaths || 0,
                profile: profile && profile.own ? profile.url : ''
            };
        };
        var names = scene.nodes.map(function (node) { return nameRow(node, false); });
        Object.keys(scene.folded).forEach(function (id) {
            var node = store.node(id);
            if (node) names.push(nameRow(node, true));
        });
        return { names: names, connections: connections };
    }

    /**
     * Sort a copy of the rows by one column. Stable, case-insensitive, and
     * numbers sort as numbers, so ten lines come after nine.
     */
    function sortRows(list, key, direction) {
        var sign = direction === 'desc' ? -1 : 1;
        return list.map(function (row, index) { return { row: row, index: index }; })
            .sort(function (a, b) {
                var va = a.row[key];
                var vb = b.row[key];
                var order;
                if (typeof va === 'number' && typeof vb === 'number') {
                    order = va - vb;
                } else {
                    var sa = String(va === undefined || va === null ? '' : va).toLowerCase();
                    var sb = String(vb === undefined || vb === null ? '' : vb).toLowerCase();
                    order = sa < sb ? -1 : (sa > sb ? 1 : 0);
                }
                return order * sign || a.index - b.index;
            })
            .map(function (entry) { return entry.row; });
    }

    /**
     * The rows as a CSV string: RFC 4180 quoting (every field quoted, inner
     * quotes doubled), CRLF line ends, and a byte order mark so Excel reads
     * the accents. A field that a spreadsheet would run as a formula is
     * prefixed with an apostrophe, so a name cannot smuggle one in.
     */
    function toCsv(columns, list) {
        var field = function (value) {
            var text = value === undefined || value === null ? '' : String(value);
            if (/^[=+\-@\t\r]/.test(text)) text = "'" + text;
            return '"' + text.replace(/"/g, '""') + '"';
        };
        var lines = [columns.map(function (c) { return field(c.label); }).join(',')];
        list.forEach(function (row) {
            lines.push(columns.map(function (c) { return field(row[c.key]); }).join(','));
        });
        return '﻿' + lines.join('\r\n') + '\r\n';
    }

    function create(options) {
        var store = options.store;
        var focus = options.focus;
        var config = options.config || {};
        var document_ = options.document || root.document;
        var panel = options.panel;
        var toggle = options.toggle;
        var announce = options.announce || function () {};
        if (!store || !focus || !panel) return null;

        var open = false;
        /* Each table's sort, kept while the list re-renders with the board.
         * Connections order by From and then To, which one stable sort by
         * To and another by From produce. */
        var sorts = {
            names: { key: 'name', direction: 'asc' },
            connections: { key: 'from', direction: 'asc' }
        };

        function el(tag, className, text) {
            var node = document_.createElement(tag);
            if (className) node.className = className;
            if (text !== undefined) node.textContent = text;
            return node;
        }

        function ordered(which, data) {
            var sort = sorts[which];
            var list = which === 'connections'
                ? sortRows(data, 'to', 'asc') : data;
            return sortRows(list, sort.key, sort.direction);
        }

        /** The slug the CSV files carry: what is being read, or the opening view. */
        function slug() {
            var trail = focus.chain();
            return trail.length ? trail[trail.length - 1] : 'opening-view';
        }

        /* Handing the browser a file needs Blob and URL, which the test DOM
         * does not have; the button only exists where they do. */
        function download(filename, text) {
            var blob = new root.Blob([text], { type: 'text/csv;charset=utf-8' });
            var url = root.URL.createObjectURL(blob);
            var link = document_.createElement('a');
            link.href = url;
            link.download = filename;
            document_.body.appendChild(link);
            link.click();
            document_.body.removeChild(link);
            root.URL.revokeObjectURL(url);
        }

        function table(which, title, columns, data) {
            var section = el('section', 'kop-network__list-section');
            var head = el('div', 'kop-network__list-head');
            head.appendChild(el('h3', 'kop-network__list-title', title + ' (' + data.length + ')'));
            if (root.Blob && root.URL && root.URL.createObjectURL) {
                var save = el('button', 'kop-network__button kop-network__list-download', 'Download CSV');
                save.type = 'button';
                save.setAttribute('aria-label', 'Download the ' + title.toLowerCase() + ' table as CSV');
                save.addEventListener('click', function () {
                    download('network-map-' + slug() + '-' + which + '.csv', toCsv(columns, ordered(which, data)));
                });
                head.appendChild(save);
            }
            section.appendChild(head);

            /* The table scrolls sideways inside its own wrapper on a phone,
             * so the page never does. */
            var scroll = el('div', 'kop-network__list-scroll');
            var grid = el('table', 'kop-network__list-table');
            var caption = el('caption', 'kop-network__list-caption',
                title + ' on the board. kidsoverprofits.org network map, ' +
                new Date().toISOString().slice(0, 10) + '.');
            grid.appendChild(caption);

            var thead = el('thead');
            var headRow = el('tr');
            columns.forEach(function (column) {
                var th = el('th');
                th.setAttribute('scope', 'col');
                var active = sorts[which].key === column.key;
                th.setAttribute('aria-sort', active
                    ? (sorts[which].direction === 'asc' ? 'ascending' : 'descending') : 'none');
                var sort = el('button', 'kop-network__list-sort', column.label);
                sort.type = 'button';
                sort.setAttribute('data-key', column.key);
                sort.addEventListener('click', function () {
                    sorts[which] = {
                        key: column.key,
                        direction: active && sorts[which].direction === 'asc' ? 'desc' : 'asc'
                    };
                    render();
                });
                th.appendChild(sort);
                headRow.appendChild(th);
            });
            thead.appendChild(headRow);
            grid.appendChild(thead);

            var tbody = el('tbody');
            ordered(which, data).forEach(function (row) {
                var tr = el('tr', row.faded ? 'is-faded' : '');
                columns.forEach(function (column) {
                    var td = el('td');
                    if (column.key === 'name') {
                        /* The name opens itself on the map and the list stays,
                         * re-rendered for the new board. */
                        var openIt = el('button', 'kop-network__list-name', row.name);
                        openIt.type = 'button';
                        openIt.setAttribute('data-id', row.id);
                        openIt.setAttribute('aria-label', 'Open ' + row.name + ' on the map');
                        openIt.addEventListener('click', function () {
                            var node = store.node(row.id);
                            if (node) focus.select(node);
                        });
                        td.appendChild(openIt);
                    } else if (column.key === 'profile' && row.profile) {
                        var link = el('a', 'kop-network__list-profile', 'Profile');
                        link.href = row.profile;
                        link.target = '_blank';
                        link.rel = 'noopener';
                        link.setAttribute('aria-label', 'Profile of ' + row.name + ' (opens in a new tab)');
                        td.appendChild(link);
                    } else {
                        var value = row[column.key];
                        td.textContent = value === 0 ? '' : String(value === undefined ? '' : value);
                    }
                    tr.appendChild(td);
                });
                tbody.appendChild(tr);
            });
            grid.appendChild(tbody);
            scroll.appendChild(grid);
            section.appendChild(scroll);
            return section;
        }

        var lastCounts = { names: 0, connections: 0 };

        function render() {
            if (!open) return;
            var data = rows(store, focus.scene(), config);
            lastCounts = { names: data.names.length, connections: data.connections.length };
            panel.textContent = '';
            var heading = el('h2', 'kop-network__list-heading', 'The map as a list');
            heading.setAttribute('tabindex', '-1');
            panel.appendChild(heading);
            panel.appendChild(table('names', 'Names', NAME_COLUMNS, data.names));
            panel.appendChild(table('connections', 'Connections', CONNECTION_COLUMNS, data.connections));
        }

        function setOpen(next) {
            next = !!next;
            if (next === open) return;
            open = next;
            panel.hidden = !open;
            if (toggle) toggle.setAttribute('aria-pressed', open ? 'true' : 'false');
            if (open) {
                render();
                announce('The map as a list: ' + lastCounts.names +
                    (lastCounts.names === 1 ? ' name, ' : ' names, ') + lastCounts.connections +
                    (lastCounts.connections === 1 ? ' connection.' : ' connections.'));
            } else {
                panel.textContent = '';
            }
        }

        return {
            render: render,
            isOpen: function () { return open; },
            setOpen: setOpen
        };
    }

    root.KOPNetworkList = {
        create: create, rows: rows, sortRows: sortRows, toCsv: toCsv,
        NAME_COLUMNS: NAME_COLUMNS, CONNECTION_COLUMNS: CONNECTION_COLUMNS
    };
})(typeof self !== 'undefined' ? self : this);
