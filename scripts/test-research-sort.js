/**
 * Offline test for js/research-library.js, the sort control on
 * /researchreports/.
 *
 *   node scripts/test-research-sort.js
 *
 * The script needs a page, so the DOM is stubbed down to the handful of methods
 * it touches. Asserts that "Most relevant" produces the same order as
 * kop_research_compare_by_relevance in inc/research-library.php (the PHP half
 * is covered by scripts/test-research-library.php), that "Newest" ignores the
 * tier and leaves undated documents last, and that no card is lost on the way.
 */
const fs = require('fs');
const path = require('path').join(__dirname, '..', 'js', 'research-library.js');

function card(title, year, relevance) {
    const attrs = { 'data-title': title, 'data-year': String(year), 'data-relevance': String(relevance) };
    return { getAttribute: k => attrs[k], attrs };
}

function makeDom(cards) {
    const grid = {
        children: cards.slice(),
        appendChild(node) {
            const i = this.children.indexOf(node);
            if (i >= 0) this.children.splice(i, 1);
            this.children.push(node);
        }
    };
    const select = { value: 'relevance', handlers: {}, addEventListener(ev, fn) { this.handlers[ev] = fn; } };
    const box = { hidden: true, removeAttribute(a) { if (a === 'hidden') this.hidden = false; } };
    const section = {
        querySelector(sel) {
            if (sel === '.kop-rl-grid') return grid;
            if (sel === '.kop-rl-sort') return box;
            if (sel === '.kop-rl-sort-by') return select;
            return null;
        }
    };
    global.document = { querySelector: sel => (sel === '.kop-research-library' ? section : null) };
    return { grid, select, box };
}

const cards = [
    card('Zebra unrated 2024', 2024, 0),
    card('Background 2020', 2020, 3),
    card('Start here 1999', 1999, 1),
    card('Important 2024', 2024, 2),
    card('Start here 2024', 2024, 1),
    card('Alpha unrated 2024', 2024, 0),
    card('Undated unrated', 0, 0),
];
const dom = makeDom(cards);
new Function(fs.readFileSync(path, 'utf8'))();

let fails = 0;
function check(label, got, want) {
    const ok = JSON.stringify(got) === JSON.stringify(want);
    if (!ok) { fails++; console.log('FAIL ' + label + '\n     got:  ' + JSON.stringify(got) + '\n     want: ' + JSON.stringify(want)); }
    else console.log('PASS ' + label);
}
const order = () => dom.grid.children.map(c => c.attrs['data-title']);

check('the control is revealed', dom.box.hidden, false);

dom.select.value = 'relevance';
dom.select.handlers.change();
check('most relevant matches the PHP order', order(), [
    'Start here 2024', 'Start here 1999', 'Important 2024', 'Background 2020',
    'Alpha unrated 2024', 'Zebra unrated 2024', 'Undated unrated',
]);

dom.select.value = 'year';
dom.select.handlers.change();
check('newest ignores the tier, undated last', order(), [
    'Alpha unrated 2024', 'Important 2024', 'Start here 2024', 'Zebra unrated 2024',
    'Background 2020', 'Start here 1999', 'Undated unrated',
]);

dom.select.value = 'title';
dom.select.handlers.change();
check('A to Z', order(), [
    'Alpha unrated 2024', 'Background 2020', 'Important 2024', 'Start here 1999',
    'Start here 2024', 'Undated unrated', 'Zebra unrated 2024',
]);

check('no card is lost', dom.grid.children.length, cards.length);
console.log(fails ? '\n' + fails + ' FAILURES' : '\nresearch sort: PASS');
process.exit(fails ? 1 : 0);
