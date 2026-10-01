/**
 * Offline checks for splitting a pasted comma list in the data forms' facility
 * lists ("Refers young people to", facilities referred) and the hint under a
 * name that will not link (js/data-form-modules/ui-render.js), against the
 * Billings Clinic list's shape: ~50 names, double spaces, a closing sentence.
 * The split must agree with the directory's (js/provider-index.js splitNames).
 *
 *   node scripts/test-facility-ref-split.js
 */

const fs = require('fs');
const path = require('path');
const vm = require('vm');

const source = fs.readFileSync(path.join(__dirname, '..', 'js', 'data-form-modules', 'ui-render.js'), 'utf8');
const window = { location: { origin: 'https://example.org' } };
vm.runInNewContext(source, { window, document: { addEventListener: () => {}, querySelectorAll: () => [] }, console, Map, Set, Array, String, Object, JSON });
const { split, hint } = window.KOP_FacilityRef;

let checks = 0;
const failures = [];
const check = (label, condition, detail) => {
    checks++;
    if (!condition) failures.push(label + (detail ? ' -- ' + detail : ''));
};

const billings = 'Adlebrook,  Anderson Center for Autism,  Arrow Ministries,  Provo Canyon,  Oasis Behavioral Health,  '
    + 'Youth Care,  Youth Health Associates,  Acadia Healthcare made several unspecified referrals as well.';
const names = split(billings);
check('the list splits', Array.isArray(names) && names.length === 8, JSON.stringify(names));
check('names trimmed, inner spaces kept', names && names[1] === 'Anderson Center for Autism');
check('the closing sentence is one entry', names && names[7] === 'Acadia Healthcare made several unspecified referrals as well.');
check('one name is not a list', split('Provo Canyon School') === null);
check('a name with one comma is not a list', split('Devereux, Texas') === null);
check('empty is not a list', split('') === null && split(null) === null);
check('trailing commas dropped', JSON.stringify(split('A, B, C,')) === '["A","B","C"]');

check('sentence flagged as a note', /reads as a note/.test(hint('Acadia Healthcare made several unspecified referrals as well.', 'unmatched')));
check('unmatched name gets a pick hint', /No exact facility match/.test(hint('Provo Canyon', 'unmatched')));
check('ambiguous name gets a pick hint', /more than one facility/.test(hint('Discovery Mood and Anxiety Program', 'ambiguous')));
check('linked name gets no hint', hint('Newport Academy', 'linked') === '');
check('record without a page gets no hint', hint('Some Place', 'nopage') === '');

// Same names as the directory's split.
const dirSource = fs.readFileSync(path.join(__dirname, '..', 'js', 'provider-index.js'), 'utf8');
const m = dirSource.match(/function splitNames\(entry\) \{[\s\S]*?\n    \}/);
check('directory splitNames found', !!m);
if (m) {
    const clean = v => (v == null ? '' : String(v).trim());
    const itemText = v => clean(v);
    const splitNames = new Function('clean', 'itemText', m[0] + '; return splitNames;')(clean, itemText);
    const dirNames = splitNames(billings).map(s => s.replace(/\s+/g, ' '));
    check('form and directory split alike', JSON.stringify(dirNames) === JSON.stringify(names),
        JSON.stringify(dirNames));
}

if (failures.length) {
    console.error(`FAIL ${failures.length} of ${checks}`);
    failures.forEach(f => console.error('  - ' + f));
    process.exit(1);
}
console.log(`OK ${checks} checks`);
