/**
 * Parity test: the PHP text readers (api/lib-inspection-text-signals.php) must
 * return exactly what the JavaScript readers in the state adapters return, on
 * every FL and NC report in tmp/prod.sqlite. The lite list
 * (inspections-read.php?lite=1) sends the PHP result in place of the text, so
 * any difference would change a badge, a count or a flag on the page.
 *
 *   node scripts/test-inspection-text-signals.js [--php=<php.exe>] [--db=tmp/prod.sqlite]
 *
 * --php defaults to $KOP_PHP, then "php". On Windows use the php.exe bundled
 * with Flywheel Local (lightning-services/php-8.2.*).
 */
'use strict';

const fs = require('fs');
const os = require('os');
const path = require('path');
const readline = require('readline');
const { execFileSync } = require('child_process');

const root = path.resolve(__dirname, '..');
const arg = (name) => {
    const hit = process.argv.find((a) => a.startsWith('--' + name + '='));
    return hit ? hit.slice(name.length + 3) : '';
};
const php = arg('php') || process.env.KOP_PHP || 'php';
const db = arg('db') || path.join(root, 'tmp', 'prod.sqlite');

// Load the two adapters with just enough of the engine for their readers.
global.window = {
    KOP: {
        reportPage: {
            safeString: (v) => (v === null || v === undefined ? '' : String(v).trim()),
            ui: {},
            mount: () => {},
            withText: () => '',
        },
    },
};
for (const file of ['fl.js', 'nc.js']) {
    // eslint-disable-next-line no-eval
    (0, eval)(fs.readFileSync(path.join(root, 'js', 'inspections', 'states', file), 'utf8'));
}
const js = window.KOP.textSignals;
if (!js || !js.FL || !js.NC) {
    console.error('The adapters did not register their text readers.');
    process.exit(1);
}

const phpDir = path.dirname(php);
const extDir = path.join(phpDir, 'ext');
const phpArgs = fs.existsSync(extDir)
    ? ['-n', '-d', 'extension_dir=' + extDir, '-d', 'extension=mbstring', '-d', 'extension=pdo_sqlite']
    : [];
const dump = path.join(os.tmpdir(), 'kop-text-signals-' + process.pid + '.jsonl');
execFileSync(php, phpArgs.concat([path.join(root, 'scripts', 'dump-inspection-text-signals.php'), dump, '--db=' + db]), { stdio: ['ignore', 'inherit', 'inherit'] });

const deep = (v) => JSON.stringify(v, (k, x) => (x && typeof x === 'object' && !Array.isArray(x)
    ? Object.keys(x).sort().reduce((o, key) => { o[key] = x[key]; return o; }, {}) : x));

(async () => {
    const counts = { FL: 0, NC: 0 };
    const diffs = [];
    let hasTextDiffs = 0;
    // How many reports carry a non-empty value, so a pass means something.
    const seen = { citations: 0, opening: 0, complaint: 0, prea_date: 0, spep_period: 0, facility_name: 0, qi_flag: 0 };
    const rl = readline.createInterface({ input: fs.createReadStream(dump, 'utf8'), crlfDelay: Infinity });
    for await (const line of rl) {
        if (!line) continue;
        const row = JSON.parse(line);
        counts[row.state]++;
        const mine = js[row.state](row.text);
        if (row.state === 'NC') {
            if (mine.citations) seen.citations++;
            if (mine.opening) seen.opening++;
            if (mine.complaint.substantiated || mine.complaint.unsubstantiated) seen.complaint++;
        } else {
            if (mine.prea_date) seen.prea_date++;
            if (mine.spep_period) seen.spep_period++;
            if (mine.facility_name) seen.facility_name++;
            if (mine.failed_or_limited || mine.satisfactory) seen.qi_flag++;
        }
        if (deep(mine) !== deep(row.php)) {
            diffs.push({ id: row.id, state: row.state, js: mine, php: row.php });
        }
        if ((row.text.trim() !== '') !== row.has_text) hasTextDiffs++;
    }
    fs.unlinkSync(dump);

    console.log(`FL ${counts.FL} reports, NC ${counts.NC} reports`);
    console.log('non-empty values compared: ' + Object.keys(seen).map((k) => k + ' ' + seen[k]).join(', '));
    if (hasTextDiffs) console.log(`has_text differs on ${hasTextDiffs} reports`);
    if (diffs.length) {
        console.log(`${diffs.length} reports differ. First ones:`);
        for (const d of diffs.slice(0, 8)) {
            console.log(`  ${d.state} #${d.id}`);
            console.log('    js : ' + deep(d.js).slice(0, 400));
            console.log('    php: ' + deep(d.php).slice(0, 400));
        }
    }
    const ok = !diffs.length && !hasTextDiffs && counts.FL > 0 && counts.NC > 0;
    console.log(ok ? 'text signals: PASS' : 'text signals: FAIL');
    process.exit(ok ? 0 : 1);
})();
