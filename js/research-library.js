/**
 * Sort control for the Research & Reports card grid.
 *
 * The grid arrives in "most relevant" order (kop_research_compare_by_relevance
 * in inc/research-library.php). This reorders it in place from the three data
 * attributes each card carries, and reveals the control, which is hidden in the
 * markup so a visitor without JavaScript never sees a dead select.
 */
(function () {
    'use strict';

    var section = document.querySelector('.kop-research-library');
    if (!section) {
        return;
    }

    // A report that names dozens of programs shows the first few and a
    // button for the rest, so its card stays the size of the others.
    var CHIPS_SHOWN = 12;
    Array.prototype.forEach.call(section.querySelectorAll('.kop-rl-facilities'), function (list) {
        var chips = list.querySelectorAll('.kop-rl-chip');
        if (chips.length <= CHIPS_SHOWN + 2) {
            return;
        }
        var extra = Array.prototype.slice.call(chips, CHIPS_SHOWN);
        extra.forEach(function (chip) { chip.hidden = true; });
        var more = document.createElement('button');
        more.type = 'button';
        more.className = 'kop-rl-chip-more';
        more.textContent = '+' + extra.length + ' more';
        more.addEventListener('click', function () {
            extra.forEach(function (chip) { chip.hidden = false; });
            more.remove();
        });
        list.appendChild(more);
    });

    var grid = section.querySelector('.kop-rl-grid');
    var box = section.querySelector('.kop-rl-sort');
    var select = section.querySelector('.kop-rl-sort-by');
    if (!grid || !box || !select) {
        return;
    }

    var UNRATED = 99;   // an unrated document sorts after every rated one

    function tier(card) {
        return parseInt(card.getAttribute('data-relevance'), 10) || UNRATED;
    }

    function year(card) {
        return parseInt(card.getAttribute('data-year'), 10) || 0;
    }

    function title(card) {
        return (card.getAttribute('data-title') || '').toLowerCase();
    }

    // Undated last in both date orders, so an unknown year never leads.
    function byYear(a, b) {
        var ya = year(a);
        var yb = year(b);
        if (ya !== yb) {
            if (!ya) return 1;
            if (!yb) return -1;
            return yb - ya;
        }
        return title(a) < title(b) ? -1 : (title(a) > title(b) ? 1 : 0);
    }

    var orders = {
        relevance: function (a, b) {
            return tier(a) !== tier(b) ? tier(a) - tier(b) : byYear(a, b);
        },
        year: byYear,
        title: function (a, b) {
            return title(a) < title(b) ? -1 : (title(a) > title(b) ? 1 : 0);
        }
    };

    function apply() {
        var compare = orders[select.value] || orders.relevance;
        var cards = Array.prototype.slice.call(grid.children);
        cards.sort(compare);
        // appendChild moves the node, so the grid ends up in the new order
        // without anything being rebuilt or re-fetched.
        cards.forEach(function (card) {
            grid.appendChild(card);
        });
    }

    select.addEventListener('change', apply);
    box.removeAttribute('hidden');
}());
