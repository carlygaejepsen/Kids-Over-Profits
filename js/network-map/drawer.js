/**
 * Network map: the drawer.
 *
 * The panel beside the stage that describes whatever was opened last: what
 * it is, whether it is still operating, the facility profile if there is one,
 * and every connection it has, grouped by kind. Each connection is a button
 * that follows it, so the drawer is a way to walk the map in text as well as
 * a place to read about one name - which is also what makes the map usable
 * without a pointer.
 *
 * The template prints the aside, hidden. This module fills it from the head
 * of the chain whenever the chain changes, and hides it when the chain is
 * empty. Closing it with the button keeps it closed until the next name is
 * opened.
 */
(function (root) {
    'use strict';

    var KIND_WORDS = {
        facility: 'Programme', parent: 'Company', person: 'Person',
        trade: 'Trade group', church: 'Church', government: 'Government body'
    };
    var STATUS_WORDS = {
        open: 'Operating', closed: 'Closed or rebranded', rebranded: 'Rebranded',
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
            byLabel[label].push(link.other);
        });
        order.sort(function (a, b) {
            return byLabel[b].length - byLabel[a].length || a.localeCompare(b);
        });
        return order.map(function (label) {
            var others = byLabel[label].slice().sort(function (a, b) {
                return String(a.name).localeCompare(String(b.name));
            });
            return { label: label, nodes: others };
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

        function el(tag, className, text) {
            var node = document_.createElement(tag);
            if (className) node.className = className;
            if (text !== undefined) node.textContent = text;
            return node;
        }

        function show(node) {
            body.textContent = '';
            shownId = node.id;

            body.appendChild(el('h2', 'kop-network__drawer-title', node.name));

            var facts = [KIND_WORDS[node.kind] || node.kind];
            if (node.status && STATUS_WORDS[node.status]) facts.push(STATUS_WORDS[node.status]);
            if (node.years) facts.push(node.years);
            body.appendChild(el('p', 'kop-network__drawer-meta', facts.filter(Boolean).join(' · ')));

            if (node.aliases && node.aliases.length) {
                body.appendChild(el('p', 'kop-network__drawer-aliases', 'Also called ' + node.aliases.join(', ')));
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
            }
            groups.forEach(function (group) {
                body.appendChild(el('h3', 'kop-network__drawer-group',
                    group.label + ' (' + group.nodes.length + ')'));
                var list = el('ul', 'kop-network__drawer-list');
                group.nodes.forEach(function (other) {
                    var item = el('li');
                    var button = el('button', 'kop-network__drawer-link', other.name);
                    button.type = 'button';
                    button.setAttribute('data-id', other.id);
                    button.addEventListener('click', function () {
                        focus.select(other);
                    });
                    item.appendChild(button);
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

        /** Follow the head of the chain. */
        function update() {
            var chain = focus.chain();
            if (!chain.length) {
                dismissedId = null;
                hide();
                return;
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

    root.KOPNetworkDrawer = { create: create, profileFor: profileFor, groupsFor: groupsFor };
})(typeof self !== 'undefined' ? self : this);
