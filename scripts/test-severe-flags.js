/**
 * Offline checks for the rule the state trackers use to recognise a severe
 * report in their feed (js/inspections/severe-flags.js).
 *
 * The trackers cannot match on report ids, so a report is a finding when its
 * text contains the finding's "needle" (inc/inspection-highlights.php,
 * kop_ih_flag_needle): the state's own words, lower-cased, spaces removed.
 *
 *   node scripts/test-severe-flags.js
 */

const fs = require('fs');
const path = require('path');
const vm = require('vm');

const source = fs.readFileSync(path.join(__dirname, '..', 'js', 'inspections', 'severe-flags.js'), 'utf8');
// No document in the sandbox: the script defines its pure functions and stops.
const sandbox = { window: {} };
vm.runInNewContext(source, sandbox);
const { find, squash, nameKey } = sandbox.window.KOP.severeFlags;

let checks = 0;
const failures = [];
const check = (label, condition) => {
    checks++;
    if (!condition) failures.push(label);
};

// What PHP sends for a finding: kop_ih_flag_needle() of its excerpt.
const needle = text => squash(text.split(' [...] ')[0]).slice(0, 160);

const shortText = 'The restraint resulted in the child being injured.';
const longText = 'Caregivers were unaware that a child in care, who had history of suicidal gestures, went into the restroom until the door was opened for another child.';
const findings = [
    { id: 1, facility: 'HMIH CEDAR CREST, LLC', label: 'Restraint or seclusion causing injury', needle: needle(shortText) },
    { id: 2, facility: 'HMIH CEDAR CREST, LLC', label: 'Death', needle: needle(longText + ' [...] The child was found unresponsive.') },
    { id: 3, facility: 'Example Ranch', label: 'Death', needle: 'tooshort' },
];

check('squash removes every space and lower-cases', squash(' A  child\n in\tCare ') === 'achildincare');
check('name keys ignore case and punctuation', nameKey('HMIH Cedar Crest, LLC') === nameKey('hmih cedar crest llc'));

check('a short finding is flagged under its own facility',
    (find(findings, 'HMIH Cedar Crest, LLC', '04/03/2026 - 748.2455 Citation Date: 04/03/2026 Deficiency Narrative: ' + shortText) || {}).id === 1);
check('a short finding is not flagged under another facility, even with the same words',
    find(findings, 'Another Operation Inc', 'Deficiency Narrative: ' + shortText) === null);
check('a long finding is flagged even when the viewer shows the facility under another name',
    (find(findings, 'Cedar Crest Hospital & RTC', 'Deficiency Narrative: ' + longText) || {}).id === 2);
check('line breaks and markup gaps in the viewer do not break the match',
    (find(findings, 'x', longText.replace(/, /g, ',\n').replace(/ went /, 'went ')) || {}).id === 2);
check('a report with other words is not flagged',
    find(findings, 'HMIH CEDAR CREST, LLC', 'Two smoke detectors had no batteries.') === null);
check('a needle too short to mean anything never matches',
    find(findings, 'Example Ranch', 'this text contains tooshort in it') === null);
check('an empty report is not flagged', find(findings, 'HMIH CEDAR CREST, LLC', '') === null);
check('no findings, no flags', find([], 'HMIH CEDAR CREST, LLC', shortText) === null);
// Texas lists a few citations twice (one copy with a note appended); the
// excerpt is the matching sentence, so both findings carry the same opening.
const twins = [
    { id: 7, facility: 'Example Ranch', needle: needle(longText) },
    { id: 8, facility: 'Example Ranch', needle: needle(longText) },
];
check('two findings with one opening: the first report takes the first', (find(twins, 'Example Ranch', longText, {}) || {}).id === 7);
check('and the second report takes the one still unclaimed', (find(twins, 'Example Ranch', longText, { 7: true }) || {}).id === 8);
check('a claimed finding is still better than none', (find(twins, 'Example Ranch', longText, { 7: true, 8: true }) || {}).id === 7);

if (failures.length) {
    console.error(failures.map(f => 'FAIL  ' + f).join('\n'));
}
console.log(`${checks} checks, ${failures.length} failed.`);
process.exit(failures.length ? 1 : 0);
