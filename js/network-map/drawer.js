/**
 * Network map: the drawer.
 *
 * The panel beside the stage that describes whatever was opened last: what
 * it is, whether it is still operating, the facility profile if there is one,
 * and every connection it has, grouped by kind. Each connection is a button
 * that follows it, so the drawer is a way to walk the map in text as well as
 * a place to read about one name - which is also what makes the map usable
 * without a pointer. A facility in the lists carries a link to its own
 * profile page when it has one, and each person listed carries, open by
 * default, every other place they have been and in what role.
 *
 * The template prints the aside, hidden. This module fills it from the head
 * of the chain whenever the chain changes, and hides it when the chain is
 * empty. Closing it with the button keeps it closed until the next name is
 * opened.
 *
 * When the trail is a route between two names (focus.showPath), the drawer
 * is where the route is read: options.renderPath, which is path.js, fills
 * the body instead.
 */
(function (root) {
    'use strict';

    var KIND_WORDS = {
        facility: 'Program', parent: 'Company', person: 'Person',
        association: 'Trade group', church: 'Church', government: 'Government body'
    };
    var STATUS_WORDS = {
        open: 'Operating', closed: 'Closed', rebranded: 'Rebranded',
        unknown: 'Status not recorded'
    };

    /**
     * Where a name's profile lives: the facility page when the site has one,
     * otherwise the location index filtered to the name, which lists every
     * facility the project has a record of. People and companies with no
     * facility record get nothing rather than a search that finds nothing.
     */
    function profileFor(node, config) {
        if (!node) return null;
        var urls = (config && config.facilityUrls) || {};
        if (node.facilityId && urls[node.facilityId]) {
            return { url: urls[node.facilityId], label: 'Facility profile', own: true };
        }
        if (node.kind === 'facility' && config && config.directoryUrl) {
            var base = String(config.directoryUrl);
            return {
                url: base + (base.indexOf('?') === -1 ? '?' : '&') + 'search=' + encodeURIComponent(node.name),
                label: 'Find it in the location index',
                own: false
            };
        }
        return null;
    }

    /** "Program · Closed · 1985-2009": what a name is, as the drawer and
     * the hover card both head it. */
    function factsFor(node) {
        var facts = [KIND_WORDS[node.kind] || node.kind];
        if (node.status && STATUS_WORDS[node.status]) facts.push(STATUS_WORDS[node.status]);
        if (node.years) facts.push(node.years);
        return facts.filter(Boolean).join(' · ');
    }

    /* A connection's own words ("cofounder/CEO"), as the line popup says
     * them; connection.js loads first on the page. */
    var wordsOf = (root.KOPNetworkConnection && root.KOPNetworkConnection.wordsOf) || function (edge) {
        var text = edge.provenance ? (edge.roles || []).join(' / ') : String(edge.raw || '');
        text = text.replace(/\s+/g, ' ').trim();
        return /^affiliated$/i.test(text) ? '' : text;
    };

    /**
     * Everywhere else a person has been, and in what role: one row per
     * place, [{ node, role }], by name. Read over every recorded line, not
     * the filtered ones, since this is the person's record rather than what
     * the board is showing; the place the drawer is open on is left out.
     */
    function otherRolesFor(store, person, exceptId) {
        var byId = Object.create(null);
        var order = [];
        store.neighbours(person.id, false).forEach(function (link) {
            var other = link.other;
            if (!other || other.id === exceptId || other.kind === 'person') return;
            if (!byId[other.id]) { byId[other.id] = { node: other, roles: [] }; order.push(other.id); }
            var words = wordsOf(link.edge);
            if (words && byId[other.id].roles.indexOf(words) === -1) byId[other.id].roles.push(words);
        });
        return order.map(function (id) {
            return { node: byId[id].node, role: byId[id].roles.join('; ') };
        }).sort(function (a, b) { return String(a.node.name).localeCompare(String(b.node.name)); });
    }

    /** A node's connections, grouped by what the connection was, largest group first. */
    function groupsFor(store, node) {
        var byLabel = Object.create(null);
        var order = [];
        store.neighbours(node.id, true).forEach(function (link) {
            var style = root.KOPNetworkCanvas && root.KOPNetworkCanvas.styleFor
                ? root.KOPNetworkCanvas.styleFor(link.edge, false)
                : null;
            var label = (style && style.label) || 'Other connection';
            if (!byLabel[label]) { byLabel[label] = []; order.push(label); }
            byLabel[label].push({
                node: link.other, provenance: link.edge.provenance || '', raw: link.edge.raw || '',
                role: wordsOf(link.edge)
            });
        });
        order.sort(function (a, b) {
            return byLabel[b].length - byLabel[a].length || a.localeCompare(b);
        });
        return order.map(function (label) {
            var others = byLabel[label].slice().sort(function (a, b) {
                return String(a.node.name).localeCompare(String(b.node.name));
            });
            return {
                label: label,
                nodes: others.map(function (o) { return o.node; }),
                /* Where a connection came from, when it is not the research
                 * board's own: the profile, or the staff moves and staff list (whose raw
                 * text says who moved where, which is the whole point). */
                sources: others.map(function (o) {
                    if (o.provenance === 'profile') return 'from the profile';
                    if (o.provenance === 'staff-movement' || o.provenance === 'staff-list') return o.raw;
                    return '';
                }),
                /* The role on this connection, where the record gives one
                 * and the source line does not already say it. */
                roles: others.map(function (o) {
                    return (o.provenance === 'staff-movement' || o.provenance === 'staff-list') ? '' : o.role;
                })
            };
        });
    }

    function create(options) {
        var store = options.store;
        var focus = options.focus;
        var config = options.config || {};
        var aside = options.drawer;
        var body = options.body;
        var closeButton = options.close;
        var document_ = options.document || root.document;
        if (!store || !focus || !aside || !body) return null;

        var shownId = null;
        var dismissedId = null;
        /* People's other roles are listed under them unless the reader
         * turns that off; the choice holds from one name to the next. */
        var showRoles = true;

        function el(tag, className, text) {
            var node = document_.createElement(tag);
            if (className) node.className = className;
            if (text !== undefined) node.textContent = text;
            return node;
        }

        /** A facility's own profile page beside its name in a list, when it has one. */
        function appendProfileLink(parent, other) {
            var profile = profileFor(other, config);
            if (!profile || !profile.own) return;
            var link = el('a', 'kop-network__drawer-item-profile', 'Profile');
            link.href = profile.url;
            link.target = '_blank';
            link.rel = 'noopener';
            link.setAttribute('aria-label', 'Profile of ' + other.name + ' (opens in a new tab)');
            parent.appendChild(link);
        }

        function show(node) {
            body.textContent = '';
            shownId = node.id;

            body.appendChild(el('h2', 'kop-network__drawer-title', node.name));

            body.appendChild(el('p', 'kop-network__drawer-meta', factsFor(node)));

            /* Names, in order of what they claim. A past name says the
             * place traded under it and stopped; a current name says the
             * board's name is the one it stopped using; everything else is
             * only another name for the same thing, so it comes last and is
             * not dressed up as a history. */
            if (node.formerNames && node.formerNames.length) {
                body.appendChild(el('p', 'kop-network__drawer-aliases',
                    'Formerly ' + node.formerNames.join(', ')));
            }
            if (node.currentName) {
                body.appendChild(el('p', 'kop-network__drawer-aliases',
                    'Now called ' + node.currentName));
            }
            var alsoCalled = (node.aliases || []).concat(node.otherNames || []);
            if (alsoCalled.length) {
                body.appendChild(el('p', 'kop-network__drawer-aliases', 'Also called ' + alsoCalled.join(', ')));
            }

            if (node.deaths) {
                var deaths = el('p', 'kop-network__drawer-deaths');
                deaths.appendChild(el('span', '',
                    node.deaths + (node.deaths === 1 ? ' death' : ' deaths') + ' recorded in the memorial. '));
                if (config.memorialUrl) {
                    var memorial = el('a', '', 'In loving memory');
                    memorial.href = config.memorialUrl;
                    deaths.appendChild(memorial);
                }
                body.appendChild(deaths);
            }

            var profile = profileFor(node, config);
            if (profile) {
                var link = el('a', 'kop-network__drawer-profile', profile.label);
                link.href = profile.url;
                if (profile.own) {
                    link.target = '_blank';
                    link.rel = 'noopener';
                }
                body.appendChild(link);
            }

            var groups = groupsFor(store, node);
            if (!groups.length) {
                body.appendChild(el('p', 'kop-network__drawer-empty',
                    'No connections recorded, or the filters are hiding all of them.'));
            } else if (options.routeFrom) {
                /* The way into the Path form from a name being read about,
                 * with this name already filled in as one end. */
                var route = el('button', 'kop-network__drawer-route', 'How is this connected to...?');
                route.type = 'button';
                route.setAttribute('aria-label', 'Find how ' + node.name + ' is connected to another name');
                route.addEventListener('click', function () { options.routeFrom(node); });
                body.appendChild(route);
            }
            /* The people listed, with everywhere else each has been. Worked
             * out first so the switch is only offered when it hides something. */
            var alsoFor = Object.create(null);
            var anyAlso = false;
            groups.forEach(function (group) {
                group.nodes.forEach(function (other) {
                    if (other.kind !== 'person' || alsoFor[other.id]) return;
                    alsoFor[other.id] = otherRolesFor(store, other, node.id);
                    if (alsoFor[other.id].length) anyAlso = true;
                });
            });
            if (anyAlso) {
                var toggle = el('button', 'kop-network__drawer-toggle',
                    showRoles ? "Hide people's other roles" : "Show people's other roles");
                toggle.type = 'button';
                toggle.setAttribute('aria-pressed', showRoles ? 'true' : 'false');
                toggle.addEventListener('click', function () {
                    showRoles = !showRoles;
                    show(node);
                    var again = body.querySelector('.kop-network__drawer-toggle');
                    if (again && again.focus) again.focus();
                });
                body.appendChild(toggle);
            }

            groups.forEach(function (group) {
                body.appendChild(el('h3', 'kop-network__drawer-group',
                    group.label + ' (' + group.nodes.length + ')'));
                var list = el('ul', 'kop-network__drawer-list');
                group.nodes.forEach(function (other, index) {
                    var item = el('li');
                    var row = el('div', 'kop-network__drawer-row');
                    var button = el('button', 'kop-network__drawer-link', other.name);
                    button.type = 'button';
                    button.setAttribute('data-id', other.id);
                    button.addEventListener('click', function () { focus.select(other); });
                    row.appendChild(button);
                    appendProfileLink(row, other);
                    item.appendChild(row);
                    if (group.roles[index]) {
                        item.appendChild(el('span', 'kop-network__drawer-role', group.roles[index]));
                    }
                    if (group.sources[index]) {
                        /* Say where a connection came from when it is not the
                         * research board's own. */
                        item.appendChild(el('span', 'kop-network__drawer-source', group.sources[index]));
                    }
                    var also = alsoFor[other.id];
                    if (showRoles && also && also.length) {
                        var alsoList = el('ul', 'kop-network__drawer-also');
                        alsoList.setAttribute('aria-label', 'Other roles of ' + other.name);
                        also.forEach(function (entry) {
                            var alsoItem = el('li');
                            var place = el('button', 'kop-network__drawer-also-link', entry.node.name);
                            place.type = 'button';
                            place.setAttribute('data-id', entry.node.id);
                            place.addEventListener('click', function () { focus.select(entry.node); });
                            alsoItem.appendChild(place);
                            if (entry.role) alsoItem.appendChild(el('span', 'kop-network__drawer-role', entry.role));
                            appendProfileLink(alsoItem, entry.node);
                            alsoList.appendChild(alsoItem);
                        });
                        item.appendChild(alsoList);
                    }
                    list.appendChild(item);
                });
                body.appendChild(list);
            });

            aside.hidden = false;
        }

        function hide() {
            aside.hidden = true;
            body.textContent = '';
            shownId = null;
        }

        /** Follow the head of the chain, or the route when the chain is one. */
        function update() {
            var chain = focus.chain();
            if (!chain.length) {
                dismissedId = null;
                hide();
                return;
            }
            if (focus.isPath && focus.isPath() && options.renderPath) {
                var routeId = 'route:' + chain.join(',');
                if (routeId === dismissedId) return;
                dismissedId = null;
                if (options.renderPath(body)) {
                    shownId = routeId;
                    aside.hidden = false;
                    return;
                }
            }
            var head = store.node(chain[chain.length - 1]);
            if (!head) { hide(); return; }
            if (head.id === dismissedId) return;
            dismissedId = null;
            show(head);
        }

        if (closeButton) {
            closeButton.addEventListener('click', function () {
                dismissedId = shownId;
                hide();
            });
        }

        return { update: update, show: show, hide: hide, shownId: function () { return shownId; } };
    }

    root.KOPNetworkDrawer = { create: create, profileFor: profileFor, factsFor: factsFor, groupsFor: groupsFor, otherRolesFor: otherRolesFor };
})(typeof self !== 'undefined' ? self : this);
