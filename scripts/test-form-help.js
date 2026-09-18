/**
 * Offline checks for the submission form's help text.
 *
 * Two lists have to stay honest against the markup: the panel help lines in
 * inc/form-help.php (rendered by templates/data-form-public.php) and the
 * field tooltips in js/field-tooltips.js. Both are easy to break silently,
 * by renaming a field id or duplicating a key, so this reads the templates
 * and asserts against them.
 *
 *   node scripts/test-form-help.js
 */

const fs = require('fs');
const path = require('path');

const root = path.join(__dirname, '..');
const read = p => fs.readFileSync(path.join(root, p), 'utf8');

const publicForm = read('templates/data-form-public.php');
const adminForm = read('templates/data-form-admin.php');
const helpPhp = read('inc/form-help.php');
const tooltips = read('js/field-tooltips.js');

let checks = 0;
const failures = [];
const check = (label, condition, detail) => {
    checks++;
    if (!condition) failures.push(label + (detail ? ' -- ' + detail : ''));
};

// --- Panel help -------------------------------------------------------------

const helpKeys = [...helpPhp.matchAll(/^\s{8}'([a-z-]+)' =>/gm)].map(m => m[1]);
const helpDupes = helpKeys.filter((k, i) => helpKeys.indexOf(k) !== i);
check('help: no duplicate keys', helpDupes.length === 0, helpDupes.join(','));

const publicCalls = [...publicForm.matchAll(/kop_form_panel_help\('([a-z-]+)'/g)].map(m => m[1]);
const adminCalls = [...adminForm.matchAll(/kop_form_panel_help\('([a-z-]+)'/g)].map(m => m[1]);

const panelCount = (publicForm.match(/class="sub-section-header"/g) || []).length;
check('help: every collapsed panel has a line', publicCalls.length === panelCount,
    `${publicCalls.length} calls for ${panelCount} panels`);

const unknown = [...publicCalls, ...adminCalls].filter(k => !helpKeys.includes(k));
check('help: every call has text', unknown.length === 0, unknown.join(','));

const publicDupes = publicCalls.filter((k, i) => publicCalls.indexOf(k) !== i);
check('help: no panel gets two lines', publicDupes.length === 0, publicDupes.join(','));

// The admin form reuses the public form's wording rather than keeping its own.
check('help: admin form reuses the shared list', adminCalls.length > 0);
check('help: admin lines use the flat class',
    adminCalls.every(k => helpPhp.includes(k)) && /section-help'\)/.test(adminForm));

// Every line should read as a sentence and stay short enough to sit under a
// heading without wrapping three times.
const helpTexts = [...helpPhp.matchAll(/^\s{8}'[a-z-]+' => '(.*)',$/gm)].map(m => m[1]);
check('help: all lines captured', helpTexts.length === helpKeys.length,
    `${helpTexts.length} of ${helpKeys.length}`);
const tooLong = helpTexts.filter(t => t.length > 240);
check('help: lines stay short', tooLong.length === 0, `${tooLong.length} over 240 chars`);
const noStop = helpTexts.filter(t => !t.trim().endsWith('.'));
check('help: lines end in a full stop', noStop.length === 0, noStop.join(' | ').slice(0, 80));

// --- Tooltips ---------------------------------------------------------------

const tipKeys = [...tooltips.matchAll(/^\s{4}'([a-zA-Z0-9-]+)':/gm)].map(m => m[1]);
const tipDupes = tipKeys.filter((k, i) => tipKeys.indexOf(k) !== i);
check('tooltips: no duplicate keys', tipDupes.length === 0, tipDupes.join(','));

// Every tooltip keyed to a field id should match a field that exists, or it
// silently does nothing.
const formIds = new Set([...publicForm.matchAll(/id="([a-zA-Z0-9_-]+)"/g)].map(m => m[1]));
const adminIds = new Set([...adminForm.matchAll(/id="([a-zA-Z0-9_-]+)"/g)].map(m => m[1]));
const orphans = tipKeys.filter(k => !formIds.has(k) && !adminIds.has(k));
check('tooltips: every key matches a field', orphans.length === 0, orphans.join(','));

// The checkboxes the plan singled out: jargon a submitter cannot be expected
// to know.
const jargon = ['has-lgat', 'has-hpm', 'has-ppc', 'has-tc', 'has-feedback-hotseat',
    'has-rebirthing', 'has-attachment', 'has-conversion', 'has-loa', 'has-repressed-memory'];
const missingJargon = jargon.filter(k => !tipKeys.includes(k));
check('tooltips: the opaque terms are explained', missingJargon.length === 0, missingJargon.join(','));

// Label-matched tooltips must name a label that is really in one of the
// forms. Some panels exist only in the admin form, so both count.
const labelCalls = [...tooltips.matchAll(/addTooltipToLabel\('([^']+)'/g)].map(m => m[1]);
const labelTexts = new Set(
    [...(publicForm + adminForm).matchAll(/<label[^>]*>([\s\S]*?)<\/label>/g)]
        .map(m => m[1].replace(/<[^>]+>/g, '').replace(/\s+/g, ' ').trim())
);
const missingLabels = labelCalls.filter(l => !labelTexts.has(l));
check('tooltips: every label tooltip matches a label', missingLabels.length === 0,
    missingLabels.join(' | '));

// --- Report -----------------------------------------------------------------

if (!failures.length) {
    console.log(`form help tests: PASS (${checks} checks, ${helpKeys.length} panel lines, ${tipKeys.length} tooltips)`);
    process.exit(0);
}
console.log('form help tests: FAIL');
failures.forEach(f => console.log('  - ' + f));
console.log(`${failures.length} of ${checks} checks failed`);
process.exit(1);
