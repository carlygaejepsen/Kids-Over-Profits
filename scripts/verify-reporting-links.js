#!/usr/bin/env node
/**
 * Verify Reporting Links
 *
 * Walks every URL in the reporting directory and reports what has rotted.
 * Government complaint forms move constantly - a board reorganises, a
 * department renames itself, a form moves behind a portal - and a dead link
 * on this page costs somebody the afternoon they finally worked up to it.
 *
 * It only reads. Nothing is rewritten: a 404 needs a human to find where the
 * form went, and a redirect may be the agency's own canonical URL or may be a
 * bounce to a homepage that has lost the form entirely.
 *
 * Usage:
 *   node scripts/verify-reporting-links.js
 *   node scripts/verify-reporting-links.js --state ut
 *   node scripts/verify-reporting-links.js --national
 *   node scripts/verify-reporting-links.js --concurrency 4 --timeout 20000
 *   node scripts/verify-reporting-links.js --json tmp/reporting-links.json
 */

const fs = require('fs');
const path = require('path');

const ROOT = path.join(__dirname, '..');
const DIRECTORY = path.join(ROOT, 'js', 'data', 'reporting', 'directory.json');
const REPORT_FILE = path.join(ROOT, 'tmp', 'reporting-links.md');

/* State sites are slow and several sit behind WAFs that punish a burst, so
 * this stays deliberately gentle. A full sweep takes a few minutes. */
const DEFAULTS = { concurrency: 6, timeout: 15000, retries: 1 };

/* Some state portals reject anything that does not look like a browser. */
const UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 ' +
    '(KHTML, like Gecko) Chrome/125.0.0.0 Safari/537.36';

function parseArgs(argv) {
    const args = { ...DEFAULTS, state: null, national: false, json: null };
    for (let i = 2; i < argv.length; i += 1) {
        const arg = argv[i];
        if (arg === '--state') args.state = String(argv[++i] || '').toUpperCase();
        else if (arg === '--national') args.national = true;
        else if (arg === '--concurrency') args.concurrency = Math.max(1, parseInt(argv[++i], 10) || DEFAULTS.concurrency);
        else if (arg === '--timeout') args.timeout = Math.max(1000, parseInt(argv[++i], 10) || DEFAULTS.timeout);
        else if (arg === '--retries') args.retries = Math.max(0, parseInt(argv[++i], 10) || 0);
        else if (arg === '--json') args.json = argv[++i];
        else {
            console.error(`Unknown argument: ${arg}`);
            process.exit(2);
        }
    }
    return args;
}

/**
 * Collect every URL in the directory, each tagged with where it came from so
 * a failure names the record a human has to go and fix.
 */
function collectTargets(directory, args) {
    const targets = [];
    const add = (scope, channel, field, url) => {
        targets.push({ scope, id: channel.id, name: channel.name, field, url });
    };
    const walk = (scope, channels) => {
        for (const channel of channels) {
            for (const field of ['complaint_url', 'info_url']) {
                if (channel[field]) add(scope, channel, field, channel[field]);
            }
            (channel.sources || []).forEach((url, i) => add(scope, channel, `sources[${i}]`, url));
        }
    };

    if (!args.state || args.national) {
        walk('national', directory.national.channels);
    }
    for (const state of directory.states) {
        if (args.state && state.abbr !== args.state) continue;
        if (args.national && !args.state) continue;
        walk(state.abbr, state.channels);
    }
    return targets;
}

/**
 * HEAD first, because a complaint form can be a large page and there are
 * hundreds of them. Plenty of government servers answer HEAD with 405 or
 * lie about it, so anything that is not a clean 2xx is retried as a GET
 * before it is called broken.
 */
async function check(url, args) {
    const attempt = async (method) => {
        const controller = new AbortController();
        const timer = setTimeout(() => controller.abort(), args.timeout);
        try {
            const res = await fetch(url, {
                method,
                redirect: 'follow',
                signal: controller.signal,
                headers: { 'User-Agent': UA, Accept: '*/*' }
            });
            return { status: res.status, finalUrl: res.url };
        } finally {
            clearTimeout(timer);
        }
    };

    let lastError = null;
    for (let i = 0; i <= args.retries; i += 1) {
        try {
            let result = await attempt('HEAD');
            if (result.status >= 400) result = await attempt('GET');
            return result;
        } catch (err) {
            lastError = err;
            if (i < args.retries) await new Promise((r) => setTimeout(r, 1500 * (i + 1)));
        }
    }
    try {
        return await attempt('GET');
    } catch (err) {
        const failure = lastError || err;
        if (failure.name === 'AbortError') {
            return { status: 0, finalUrl: url, error: `timed out after ${args.timeout}ms` };
        }
        const code = failure.cause && failure.cause.code;
        if (TLS_CHAIN_ERRORS.has(code)) {
            return { status: 0, finalUrl: url, tls: code, error: `TLS chain: ${code}` };
        }
        if (REFUSED_ERRORS.has(code)) {
            return { status: 0, finalUrl: url, refused: code, error: `connection ${code}` };
        }
        return { status: 0, finalUrl: url, error: failure.message };
    }
}

/* Status codes that mean "a filter refused us", not "there is nothing here". */
const BLOCKED_STATUSES = new Set([401, 403, 405, 429, 503]);

/* Bot-challenge services that answer 200 with an interstitial instead of the
 * page. A redirect onto one of these hosts is the same thing as a 403: the
 * agency's page is fine and the checker was turned away. Without this they
 * look like a redirect to an unrelated site, which is a more alarming finding
 * than it deserves. */
const CHALLENGE_HOSTS = [
    'validate.perfdrive.com',
    'geo.captcha-delivery.com',
    'challenges.cloudflare.com',
    'www.google.com/recaptcha'
];

function isChallenge(finalUrl) {
    return CHALLENGE_HOSTS.some((host) => finalUrl.includes(host));
}

/* The server dropped the connection instead of answering. Some state sites
 * (ocfs.ny.gov among them) do this to any client whose TLS fingerprint does
 * not look like a real browser, and serve the page perfectly to Chrome. That
 * is indistinguishable from a genuinely broken host at this level, so it is
 * reported as "could not tell" rather than asserted to be either. */
const REFUSED_ERRORS = new Set(['ECONNRESET', 'EPIPE', 'ECONNREFUSED', 'UND_ERR_SOCKET']);

/**
 * A server that does not send its intermediate certificate.
 *
 * Several state agency sites do this. Browsers paper over it by fetching the
 * missing certificate themselves, and curl often has it cached, so the page
 * works for almost everyone - but a strict client like Node refuses it. That
 * is a real defect worth telling the agency about, and it is not the same
 * thing as a dead link, so it is reported on its own rather than failing the
 * run and hiding a genuine 404 behind it.
 */
const TLS_CHAIN_ERRORS = new Set([
    'UNABLE_TO_VERIFY_LEAF_SIGNATURE',
    'DEPTH_ZERO_SELF_SIGNED_CERT',
    'SELF_SIGNED_CERT_IN_CHAIN',
    'CERT_HAS_EXPIRED',
    'ERR_TLS_CERT_ALTNAME_INVALID'
]);

/** Same page, different spelling of the URL - not worth reporting. */
function sameEnough(a, b) {
    const norm = (u) => u.replace(/^https?:\/\//, '').replace(/^www\./, '').replace(/\/+$/, '').toLowerCase();
    return norm(a) === norm(b);
}

/**
 * A redirect that lands on the site root has usually eaten the form: the
 * agency deleted the page and the server bounced it to the homepage. Those
 * are worth a human look even though they return 200.
 */
function landedOnRoot(finalUrl) {
    try {
        const u = new URL(finalUrl);
        return u.pathname === '/' || u.pathname === '';
    } catch (err) {
        return false;
    }
}

async function runPool(items, limit, worker) {
    const results = new Array(items.length);
    let next = 0;
    const runners = Array.from({ length: Math.min(limit, items.length) }, async () => {
        for (;;) {
            const i = next++;
            if (i >= items.length) return;
            results[i] = await worker(items[i], i);
        }
    });
    await Promise.all(runners);
    return results;
}

async function main() {
    const args = parseArgs(process.argv);

    if (typeof fetch !== 'function') {
        console.error('This script needs Node 18 or newer (global fetch).');
        process.exit(2);
    }
    if (!fs.existsSync(DIRECTORY)) {
        console.error(`${path.relative(ROOT, DIRECTORY)} is missing. Run: node scripts/build-reporting-directory.js`);
        process.exit(2);
    }

    const directory = JSON.parse(fs.readFileSync(DIRECTORY, 'utf8'));
    const targets = collectTargets(directory, args);
    if (!targets.length) {
        console.error('No URLs matched. Check --state.');
        process.exit(2);
    }

    console.log(`Checking ${targets.length} URLs, ${args.concurrency} at a time...`);
    let done = 0;
    const checked = await runPool(targets, args.concurrency, async (target) => {
        const result = await check(target.url, args);
        done += 1;
        if (done % 25 === 0 || done === targets.length) {
            process.stdout.write(`  ${done}/${targets.length}\r`);
        }
        return { ...target, ...result };
    });
    process.stdout.write('\n');

    const tls = checked.filter((r) => r.tls);
    /* A bot filter saying no is not a dead page. The Joint Commission and
     * several state portals sit behind one and refuse anything that is not a
     * real browser, however the User-Agent is dressed up. Reported separately
     * so a genuine 404 is not lost in the noise. */
    const blocked = checked.filter((r) => BLOCKED_STATUSES.has(r.status) || r.refused
        || isChallenge(r.finalUrl || ''));
    const broken = checked.filter((r) => !r.tls && !r.refused && !BLOCKED_STATUSES.has(r.status)
        && (r.status === 0 || r.status >= 400));
    const bounced = checked.filter((r) => r.status >= 200 && r.status < 400
        && !sameEnough(r.url, r.finalUrl) && !isChallenge(r.finalUrl) && landedOnRoot(r.finalUrl));
    const moved = checked.filter((r) => r.status >= 200 && r.status < 400
        && !sameEnough(r.url, r.finalUrl) && !isChallenge(r.finalUrl) && !landedOnRoot(r.finalUrl));

    writeReport({ checked, broken, bounced, moved, tls, blocked, args });
    if (args.json) {
        fs.mkdirSync(path.dirname(path.resolve(ROOT, args.json)), { recursive: true });
        fs.writeFileSync(path.resolve(ROOT, args.json), `${JSON.stringify(checked, null, 2)}\n`, 'utf8');
    }

    console.log('');
    console.log(`  ${checked.length - broken.length - bounced.length - tls.length - blocked.length} fine`);
    console.log(`  ${moved.length} redirected (probably fine, listed in the report)`);
    console.log(`  ${tls.length} with a TLS chain the agency has misconfigured (reachable in a browser)`);
    console.log(`  ${blocked.length} refused by a bot filter (the page is there; check by hand)`);
    console.log(`  ${bounced.length} bounced to a site root - the form has probably moved`);
    console.log(`  ${broken.length} broken`);
    console.log('');
    console.log(`Report: ${path.relative(ROOT, REPORT_FILE)}`);

    for (const r of broken) {
        console.log(`  ${r.scope} ${r.id} ${r.field}: ${r.error || `HTTP ${r.status}`}`);
    }

    /* Non-zero on a genuine break so this can gate a deploy if it is ever
     * wired to one. A redirect is not a failure. */
    process.exit(broken.length ? 1 : 0);
}

function writeReport({ checked, broken, bounced, moved, tls, blocked, args }) {
    const lines = [];
    lines.push('# Reporting directory link check');
    lines.push('');
    lines.push(`Run ${new Date().toISOString()} by \`scripts/verify-reporting-links.js\`.`);
    lines.push(`${checked.length} URLs, timeout ${args.timeout}ms, ${args.retries} retr${args.retries === 1 ? 'y' : 'ies'}.`);
    lines.push('Gitignored. Rewritten on every run.');
    lines.push('');

    const section = (title, rows, blurb) => {
        lines.push(`## ${title} (${rows.length})`);
        lines.push('');
        if (blurb) { lines.push(blurb); lines.push(''); }
        if (!rows.length) { lines.push('None.'); lines.push(''); return; }
        for (const r of rows) {
            lines.push(`- **${r.scope} / ${r.id}** - ${r.name}`);
            lines.push(`  - \`${r.field}\`: ${r.url}`);
            if (r.error) lines.push(`  - failed: ${r.error}`);
            else lines.push(`  - HTTP ${r.status}${sameEnough(r.url, r.finalUrl) ? '' : ` -> ${r.finalUrl}`}`);
        }
        lines.push('');
    };

    section('Broken', broken,
        'These returned an error or did not answer. Find where the form went and update the record, or remove it.');
    section('Refused by a bot filter', blocked,
        'A WAF turned the checker away, either with a status code or by dropping the connection outright. The page '
        + 'very likely still exists - open it in a browser to confirm rather than removing the record.');
    section('TLS chain misconfigured', tls,
        'The agency\'s server does not send a complete certificate chain. A browser will usually load these anyway, '
        + 'so the link is kept, but confirm each one by hand and consider telling the agency.');
    section('Bounced to a site root', bounced,
        'These answer 200 but the agency redirected them to its homepage, which usually means the page they pointed at is gone.');
    section('Redirected', moved,
        'These still resolve. Worth updating the record to the final URL when the agency has clearly moved the page for good.');

    fs.mkdirSync(path.dirname(REPORT_FILE), { recursive: true });
    fs.writeFileSync(REPORT_FILE, lines.join('\n'), 'utf8');
}

main().catch((err) => {
    console.error(err);
    process.exit(2);
});
