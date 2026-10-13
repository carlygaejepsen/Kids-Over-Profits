/**
 * Offline checks for which table an admin form save goes to.
 *
 * A project named after a state or country is that place's location profile,
 * but a provider, referrer or transporter project named "Montana" must be
 * saved to its own table, never over MONTANA's location profile (PLAN 3.8
 * item 3: provider saves made while the project was named MONTANA landed in
 * Montana's location profile). Runs resolveSaveCategory() from
 * js/data-form-modules/api.js in a bare VM and, when php is on the PATH (or
 * --php=<php.exe>), kop_save_master_category() from api/lib-save-category.php
 * on the same cases.
 *
 *   node scripts/test-save-category.js [--php=<php.exe>]
 */

const fs = require('fs');
const path = require('path');
const vm = require('vm');
const { execFileSync } = require('child_process');

const root = path.join(__dirname, '..');
let checks = 0;
const failures = [];
const check = (label, condition, detail) => {
    checks++;
    if (!condition) failures.push(label + (detail ? ' -- ' + detail : ''));
};

// The state and country lists the admin form loads (js/data-form/location-form.js).
const window = {};
const sandbox = { window, console, document: { querySelector: () => null }, localStorage: { setItem() {}, getItem() { return null; } } };
vm.createContext(sandbox);
vm.runInContext(fs.readFileSync(path.join(root, 'js/data-form/location-form.js'), 'utf8')
    + '\nwindow.US_STATE_SET = US_STATE_SET; window.COUNTRY_SET = COUNTRY_SET;', sandbox, { filename: 'location-form.js' });
vm.runInContext(fs.readFileSync(path.join(root, 'js/data-form-modules/api.js'), 'utf8'), sandbox, { filename: 'api.js' });
const resolve = window.KOP_API && window.KOP_API.resolveSaveCategory;
check('resolveSaveCategory is exposed on KOP_API', typeof resolve === 'function');

// [label, projectName, storedCategory, tabCategory, dataCategory, expected]
const cases = [
    ['location profile from the Locations tab', 'Montana', undefined, 'locations', undefined, 'locations'],
    ['state name typed on the Companies tab is the location profile', 'Montana', undefined, 'companies', undefined, 'locations'],
    ['stored location profile', 'MONTANA', 'locations', 'companies', undefined, 'locations'],
    ['country name', 'Canada', undefined, 'companies', undefined, 'locations'],
    ['provider project named after a state', 'Montana', undefined, 'providers', 'providers', 'providers'],
    ['stored provider project named after a state', 'MONTANA', 'providers', 'locations', 'providers', 'providers'],
    ['provider data over a stored location profile of the same name', 'MONTANA', 'locations', 'providers', 'providers', 'providers'],
    ['referrer project named after a state', 'Utah', undefined, 'referrers', undefined, 'referrers'],
    ['transporter project named after a state', 'Texas', 'transporters', 'companies', undefined, 'transporters'],
    ['provider project with a state in its name', 'MONTANA PROVIDERS', undefined, 'providers', 'providers', 'providers'],
    ['company project', 'Aspen Education Group', undefined, 'companies', undefined, 'companies'],
    ['Operators tab saves a company', 'Aspen Education Group', undefined, 'operators', undefined, 'companies'],
    ['Operators tab, state name', 'Utah', undefined, 'operators', undefined, 'locations'],
    ['no tab, no stored category', 'Some Company', undefined, '', undefined, 'companies'],
];

if (resolve) {
    for (const [label, name, stored, tab, dataCat, expected] of cases) {
        const got = resolve(name, stored, tab, dataCat);
        check('JS: ' + label, got === expected, `got ${got}, expected ${expected}`);
    }
}

// PHP: the server gets the category the form resolved, so check it keeps the
// non-location ones and still turns a company save with a state name into a
// location profile (an older form, or a request with no category).
const phpArg = process.argv.find(a => a.startsWith('--php='));
const php = phpArg ? phpArg.slice(6) : 'php';
const phpCases = [
    ['Montana', 'providers', 'providers', 'Montana'],
    ['Montana', 'referrers', 'referrers', 'Montana'],
    ['Texas', 'transporters', 'transporters', 'Texas'],
    ['Montana', 'companies', 'locations', 'MONTANA'],
    [' montana ', 'locations', 'locations', 'MONTANA'],
    ['Canada', 'companies', 'locations', 'CANADA'],
    ['MONTANA PROVIDERS', 'providers', 'providers', 'MONTANA PROVIDERS'],
    ['Aspen Education Group', 'companies', 'companies', 'Aspen Education Group'],
];
const phpCode = `
require ${JSON.stringify(path.join(root, 'api/lib-save-category.php'))};
$names = ['MONTANA', 'TEXAS', 'UTAH', 'CANADA'];
$cases = json_decode(stream_get_contents(STDIN), true);
$out = [];
foreach ($cases as $c) { $out[] = kop_save_master_category($c[0], $c[1], $names); }
echo json_encode($out);`;
let phpOut = null;
try {
    phpOut = JSON.parse(execFileSync(php, ['-r', phpCode], { input: JSON.stringify(phpCases), encoding: 'utf8' }));
} catch (err) {
    console.log(`PHP checks skipped (${php} not runnable: ${err.message.split('\n')[0]})`);
}
if (phpOut) {
    phpCases.forEach(([name, requested, expCat, expName], i) => {
        const [cat, outName] = phpOut[i];
        check(`PHP: ${name} / ${requested}`, cat === expCat && outName === expName,
            `got [${cat}, ${outName}], expected [${expCat}, ${expName}]`);
    });
    // Every non-location category the PHP keeps, the JS keeps too.
    if (resolve) {
        ['providers', 'referrers', 'transporters', 'wiki'].forEach(cat => {
            check(`JS keeps ${cat} for a state name, as PHP does`, resolve('Montana', cat, '', undefined) === cat);
        });
    }
}

if (failures.length) {
    console.log(`${failures.length} of ${checks} checks failed:`);
    failures.forEach(f => console.log('  FAIL ' + f));
    process.exit(1);
}
console.log(`All ${checks} checks passed.`);
