/**
 * Parity test: what a state hub card says about a report (the PHP readers in
 * api/inspection-verdicts/<st>.php, through api/lib-inspection-verdicts.php)
 * must be exactly what the state's own report page says (report() and
 * countFlagged() of js/inspections/states/<st>.js), on every report of the
 * state in tmp/prod.sqlite.
 *
 *   node scripts/test-inspection-verdicts.js [--states=PA,VA] [--php=<php.exe>] [--db=tmp/prod.sqlite]
 *        [--ids=1,2] [--limit=N] [--show=8]
 *
 * --php defaults to $KOP_PHP, then "php". On Windows use the php.exe bundled
 * with Flywheel Local (lightning-services/php-8.2.*). Without --states every
 * state with a PHP reader is checked.
 *
 * Each report is run through the adapter alone: the adapter's load() is given
 * an inspections-read.php response holding one facility with one report (its
 * text left out when the adapter asks for ?lite=1, as the server does), then
 * report() and countFlagged() are called on what it built. An adapter that
 * builds no report from the row means the PHP reader must return null.
 */
'use strict';

const fs = require('fs');
const os = require('os');
const path = require('path');
const vm = require('vm');
const readline = require('readline');
const { execFileSync } = require('child_process');

const root = path.resolve(__dirname, '..');
const arg = (name) => {
    const hit = process.argv.find((a) => a.startsWith('--' + name + '='));
    return hit ? hit.slice(name.length + 3) : '';
};
const php = arg('php') || process.env.KOP_PHP || 'php';
const db = arg('db') || path.join(root, 'tmp', 'prod.sqlite');
const show = parseInt(arg('show'), 10) || 8;

const phpDir = path.dirname(php);
const extDir = path.join(phpDir, 'ext');
const phpArgs = fs.existsSync(extDir)
    ? ['-n', '-d', 'extension_dir=' + extDir, '-d', 'extension=mbstring', '-d', 'extension=pdo_sqlite', '-d', 'memory_limit=2G']
    : [];

let states = arg('states') ? arg('states').toUpperCase().split(',').filter(Boolean) : null;
if (!states) {
    states = fs.readdirSync(path.join(root, 'api', 'inspection-verdicts'))
        .filter((f) => /^[a-z]{2}\.php$/.test(f)).map((f) => f.slice(0, 2).toUpperCase()).sort();
}

/**
 * Per-state data the adapter's load() reads besides inspections-read.php:
 * window globals and the response for any other URL it fetches. A state that
 * reads its rows from a JSON file (MT) gets the row in that file's shape.
 */
const HOOKS = {
    MT: {
        globals: { mtReportsData: { jsonFileUrls: ['/mt_reports.json'] } },
        serve: (url, row) => [row.report.categories],
    },
    UT: {
        globals: { utReportsData: { jsonFileUrls: [] } },
    },
};

/** A fresh window with report-page.js and the adapter loaded; returns the adapter. */
function loadAdapter(st) {
    const sandbox = {
        console, Promise, Date, Math, JSON, RegExp, String, Number, Array, Object, Error, Map, Set,
        parseInt, parseFloat, isNaN, isFinite, encodeURIComponent, decodeURIComponent, URL, URLSearchParams,
        setTimeout, clearTimeout,
    };
    sandbox.window = sandbox;
    sandbox.self = sandbox;
    sandbox.document = {
        readyState: 'complete',
        getElementById: () => null,
        querySelector: () => null,
        querySelectorAll: () => [],
        addEventListener: () => {},
        createElement: () => ({ style: {}, setAttribute() {}, appendChild() {}, classList: { add() {}, remove() {} } }),
        body: { appendChild() {} },
    };
    sandbox.location = { search: '', pathname: '/' + st.toLowerCase() + '-reports/', href: '' };
    sandbox.localStorage = { getItem: () => null, setItem() {}, removeItem() {} };
    sandbox.fetch = (url) => sandbox.__fetch(String(url));
    Object.assign(sandbox, (HOOKS[st] && HOOKS[st].globals) || {});
    vm.createContext(sandbox);
    const run = (file) => vm.runInContext(fs.readFileSync(path.join(root, file), 'utf8'), sandbox, { filename: file });
    run('js/inspections/report-page.js');
    let adapter = null;
    sandbox.KOP.reportPage.mount = (a) => { adapter = a; };
    run('js/inspections/states/' + st.toLowerCase() + '.js');
    if (!adapter) throw new Error(st + ': the adapter did not call mount()');
    return { adapter, sandbox };
}

function plural(count, one, many) {
    return count + ' ' + (count === 1 ? one : (many || one + 's'));
}

function respond(data) {
    return Promise.resolve({ ok: true, status: 200, json: () => Promise.resolve(data), text: () => Promise.resolve(JSON.stringify(data)) });
}

async function expectedFor(st, loaded, row) {
    const { adapter, sandbox } = loaded;
    sandbox.__fetch = (url) => {
        if (/inspections-read\.php/.test(url) || /[?&]state=/.test(url)) {
            const report = Object.assign({}, row.report);
            if (/[?&]lite=1/.test(url)) delete report.raw_content;
            else { delete report.row_id; delete report.has_text; delete report.text_signals; }
            return respond({
                total_facilities: 1, source_state: st, scraped_timestamp: '',
                facilities: [{ facility_info: row.facility_info, reports: [report] }],
            });
        }
        const hook = HOOKS[st] && HOOKS[st].serve;
        return respond(hook ? hook(url, row) : []);
    };
    const result = await adapter.load();
    const facilities = (result && result.facilities) || [];
    const key = adapter.reportsKey || 'reports';
    const reports = [];
    facilities.forEach((f) => (Array.isArray(f[key]) ? f[key] : []).forEach((r) => reports.push({ f, r })));
    if (!reports.length) return null;
    const { f, r } = reports[0];
    const page = sandbox.KOP.reportPage;
    const countFlagged = (fac) => (typeof adapter.countFlagged === 'function'
        ? adapter.countFlagged(fac)
        : (fac[key] || []).filter(adapter.isFlagged).length);
    const ctx = {
        escapeHtml: page.escapeHtml,
        countFlagged,
        reports: (fac) => (Array.isArray(fac && fac[key]) ? fac[key] : []),
        formatDate: page.formatDate,
        plural,
        ui: page.ui,
        archiveLink: () => null,
    };
    const view = adapter.report(r, ctx) || {};
    return {
        tone: view.tone || 'neutral',
        // Whole numbers: MD adds safety citations / 1000 to its count only to sort.
        count: Math.floor(Number(countFlagged(f)) || 0),
        badges: (view.badges || []).filter(Boolean).map((b) => ({ text: String(b.text), tone: b.tone || 'neutral' })),
        ...(reports.length > 1 ? { extra_reports: reports.length - 1 } : {}),
    };
}

const same = (a, b) => JSON.stringify(a) === JSON.stringify(b);
const norm = (v) => (v && typeof v === 'object'
    ? { tone: v.tone, count: v.count, badges: (v.badges || []).map((b) => ({ text: b.text, tone: b.tone })) }
    : v);

(async () => {
    let failed = false;
    for (const st of states) {
        const file = path.join(root, 'api', 'inspection-verdicts', st.toLowerCase() + '.php');
        if (!fs.existsSync(file)) { console.log(`${st}: no PHP reader (${path.relative(root, file)})`); failed = true; continue; }
        const dump = path.join(os.tmpdir(), `kop-verdicts-${st}-${process.pid}.jsonl`);
        const extra = [];
        if (arg('ids')) extra.push('--ids=' + arg('ids'));
        if (arg('limit')) extra.push('--limit=' + arg('limit'));
        execFileSync(php, phpArgs.concat([path.join(root, 'scripts', 'dump-inspection-verdicts.php'), dump, '--state=' + st, '--db=' + db], extra),
            { stdio: ['ignore', 'inherit', 'inherit'], maxBuffer: 1 << 26 });
        const loaded = loadAdapter(st);
        let n = 0;
        const diffs = [];
        const tones = {};
        const rl = readline.createInterface({ input: fs.createReadStream(dump, 'utf8'), crlfDelay: Infinity });
        for await (const line of rl) {
            if (!line) continue;
            const row = JSON.parse(line);
            n++;
            let want;
            try {
                want = await expectedFor(st, loaded, row);
            } catch (e) {
                want = { error: String(e && e.stack || e).split('\n').slice(0, 3).join(' | ') };
            }
            const got = row.php === false ? { error: 'no PHP reader' } : row.php;
            const t = want && want.tone ? want.tone : (want === null ? 'dropped' : 'error');
            tones[t] = (tones[t] || 0) + 1;
            if (want && want.extra_reports) { /* the PHP verdict is for the first report only */ }
            if (!same(norm(want), norm(got))) diffs.push({ id: row.id, want: norm(want), got: norm(got) });
        }
        fs.unlinkSync(dump);
        console.log(`${st}: ${n} reports (${Object.keys(tones).map((k) => k + ' ' + tones[k]).join(', ')}), ${diffs.length} differ`);
        for (const d of diffs.slice(0, show)) {
            console.log(`  #${d.id}`);
            console.log('    page: ' + JSON.stringify(d.want));
            console.log('    php : ' + JSON.stringify(d.got));
        }
        if (diffs.length || !n) failed = true;
    }
    console.log(failed ? 'inspection verdicts: FAIL' : 'inspection verdicts: PASS');
    process.exit(failed ? 1 : 0);
})();
