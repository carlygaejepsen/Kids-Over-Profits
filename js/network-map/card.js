/**
 * Network map: the hover card.
 *
 * Hovering a name lights its connections, but says nothing of what the name
 * is: to learn that a box is a closed 1990s program with two deaths the
 * reader had to click it, and a click re-lays the board out. The card says
 * it on the way past - what the name is, whether it still operates and
 * when, the group a program belongs to, NATSAP membership, other names, deaths,
 * and how many connections it has - and that a click opens the rest.
 *
 * It waits DELAY ms before showing, so a pointer crossing the board does not
 * flicker cards, and goes at once when the pointer leaves, the view pans or
 * zooms, or anything is opened. The keyboard cursor gets the same card,
 * since keys.js moves the hover. Touch never sees it: a tap is a click, and
 * the drawer is the answer to one.
 *
 * The first line is the drawer's own (KOPNetworkDrawer.factsFor), so the two
 * cannot disagree. summary() is DOM-free, so scripts/test-network-modules.js
 * can check what a card says without a document.
 */
(function (root) {
    'use strict';

    /* How long a name has to be rested on before its card shows, in ms. */
    var DELAY = 300;
    /* The gap between a name's bubble and its card, in pixels. */
    var GAP = 10;
    /* Past this many earlier names, "Formerly" says how many more. */
    var NAMES_SHOWN = 2;

    function listNames(names) {
        if (names.length <= NAMES_SHOWN) return names.join(', ');
        var more = names.length - NAMES_SHOWN;
        return names.slice(0, NAMES_SHOWN).join(', ') + ' and ' + more + (more === 1 ? ' other' : ' others');
    }

    /**
     * Does "Part of <group>" say something true of this name? Not for a
     * company: a group's colour takes in its owners and what it bought as
     * well as itself (Bain Capital is in Embark's, Acadia in Aspen's), so
     * the drawer's ownership lines say which way it runs. Not for a name
     * that is the group's own. Only whole names count, since "UHS of Provo
     * Canyon" is a program.
     */
    function belongsToGroup(node) {
        var chain = String(node.chain || '').toLowerCase();
        if (!chain || node.kind === 'parent') return false;
        return ![node.name].concat(node.aliases || [], node.otherNames || []).some(function (name) {
            return String(name).toLowerCase() === chain;
        });
    }

    /**
     * What the card says of a name, as plain data:
     *
     *   { name, facts, lines: [text], deaths, connections, hint }
     *
     * `scene` is what the board is showing (for the connections not on it
     * yet); `head` is the id opened last, whose card does not offer to open
     * it again; `keyboard` words the hint for the Enter key.
     */
    function summary(node, options) {
        options = options || {};
        var store = options.store;
        var scene = options.scene || null;
        var drawer = options.drawer || root.KOPNetworkDrawer;

        var facts = drawer && drawer.factsFor ? drawer.factsFor(node) : String(node.kind || '');

        var lines = [];
        if (belongsToGroup(node)) lines.push('Part of ' + node.chain);
        if (node.natsap) lines.push('NATSAP member');
        if (node.currentName) lines.push('Now called ' + node.currentName);
        if (node.formerNames && node.formerNames.length) lines.push('Formerly ' + listNames(node.formerNames));

        var deaths = node.deaths
            ? node.deaths + (node.deaths === 1 ? ' death' : ' deaths') + ' recorded in the memorial'
            : '';

        /* Distinct names joined to it through the lines the Key lets through,
         * which is what the drawer would list. */
        var count = 0;
        if (store) {
            var seen = Object.create(null);
            store.neighbours(node.id, true).forEach(function (link) {
                if (link.other && !seen[link.other.id]) { seen[link.other.id] = true; count++; }
            });
        }
        var hidden = scene && scene.hidden && scene.hidden[node.id] ? scene.hidden[node.id] : 0;
        var connections = count
            ? count + (count === 1 ? ' connection' : ' connections') +
                (hidden ? ', ' + hidden + ' not on the board yet' : '')
            : 'No connections recorded';

        var hint = options.head === node.id
            ? 'Already open: the panel has the rest'
            : (options.keyboard ? 'Press Enter to open' : 'Click to open');

        return { name: node.name, facts: facts, lines: lines, deaths: deaths, connections: connections, hint: hint };
    }

    /**
     * Where the card goes: beside the bubble on the right, or the left when
     * the right has no room, level with the bubble's top and kept on the
     * stage; below or above it when neither side fits (a phone-width stage).
     * `top` and `bottom` are the part of the stage the window shows, which
     * on a short window is less than the stage.
     */
    function placeBeside(box, width, height, stageW, bottom, top) {
        top = top || 0;
        var x, y;
        if (box[2] + GAP + width <= stageW - 4) {
            x = box[2] + GAP;
            y = box[1];
        } else if (box[0] - GAP - width >= 4) {
            x = box[0] - GAP - width;
            y = box[1];
        } else {
            x = (box[0] + box[2]) / 2 - width / 2;
            y = box[3] + GAP + height <= bottom - 4 ? box[3] + GAP : box[1] - GAP - height;
        }
        x = Math.min(Math.max(4, x), Math.max(4, stageW - width - 4));
        y = Math.max(top + 4, Math.min(y, bottom - height - 4));
        return { x: x, y: y };
    }

    function create(options) {
        var stage = options.stage;
        var store = options.store;
        var focus = options.focus;
        var renderer = options.renderer;
        var document_ = options.document || root.document;
        var setTimer = options.setTimeout || function (fn, ms) { return root.setTimeout(fn, ms); };
        var clearTimer = options.clearTimeout || function (id) { root.clearTimeout(id); };
        /* Is the keyboard cursor on a name, rather than the pointer? */
        var keyboardCursor = options.keyboardCursor || function () { return null; };
        if (!stage || !store || !focus || !renderer || !document_) return null;

        var box = document_.createElement('div');
        box.className = 'kop-network__card';
        box.hidden = true;
        /* The live region already says the name for the keyboard (keys.js),
         * and the drawer is the text version of all of it. */
        box.setAttribute('aria-hidden', 'true');
        stage.appendChild(box);

        var timer = 0;
        var pendingId = null;
        var shownId = null;

        function el(tag, className, text) {
            var node = document_.createElement(tag);
            if (className) node.className = className;
            if (text !== undefined) node.textContent = text;
            return node;
        }

        function render(said) {
            box.textContent = '';
            box.appendChild(el('p', 'kop-network__card-name', said.name));
            if (said.facts) box.appendChild(el('p', 'kop-network__card-facts', said.facts));
            said.lines.forEach(function (line) {
                box.appendChild(el('p', 'kop-network__card-line', line));
            });
            if (said.deaths) box.appendChild(el('p', 'kop-network__card-deaths', said.deaths));
            box.appendChild(el('p', 'kop-network__card-line', said.connections));
            box.appendChild(el('p', 'kop-network__card-hint', said.hint));
        }

        function show(node) {
            timer = 0;
            pendingId = null;
            /* The name may have left the board while the card waited. */
            var scene = focus.scene();
            if (!scene.nodeIds || !scene.nodeIds[node.id] || !renderer.screenBox) return;
            var chain = focus.chain();
            render(summary(node, {
                store: store,
                scene: scene,
                head: chain.length ? chain[chain.length - 1] : null,
                keyboard: keyboardCursor() === node.id
            }));
            box.hidden = false;
            var stageH = stage.clientHeight || renderer.height || 0;
            var top = 0, bottom = stageH;
            if (stage.getBoundingClientRect && root.innerHeight) {
                var rect = stage.getBoundingClientRect();
                top = Math.min(Math.max(0, -rect.top), stageH);
                bottom = Math.max(top, Math.min(stageH, root.innerHeight - rect.top));
            }
            var at = placeBeside(renderer.screenBox(node),
                box.offsetWidth || 240, box.offsetHeight || 120,
                stage.clientWidth || renderer.width || 0, bottom, top);
            box.style.left = at.x + 'px';
            box.style.top = at.y + 'px';
            shownId = node.id;
        }

        function hide() {
            if (timer) { clearTimer(timer); timer = 0; }
            pendingId = null;
            if (box.hidden) return;
            box.hidden = true;
            box.textContent = '';
            shownId = null;
        }

        return {
            element: box,
            /** A name is being rested on, or (null) nothing is. */
            hover: function (node) {
                if (!node) { hide(); return; }
                if (node.id === shownId || node.id === pendingId) return;
                hide();
                pendingId = node.id;
                timer = setTimer(function () { show(node); }, DELAY);
            },
            hide: hide,
            shownId: function () { return shownId; }
        };
    }

    var api = { create: create, summary: summary, placeBeside: placeBeside, DELAY: DELAY };

    if (typeof module === 'object' && module.exports) module.exports = api;
    else root.KOPNetworkCard = api;
})(typeof self !== 'undefined' ? self : this);
