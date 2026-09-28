#!/usr/bin/env node
/**
 * Pull CHILD USA's child sexual abuse statute-of-limitations summaries into
 * js/data/reporting/childusa-sol.json, one entry per state.
 *
 * CHILD USA (childusa.org/sol/) tracks every state's civil and criminal
 * deadlines for child sexual abuse, trafficking and CSAM and updates them as
 * legislatures act. Each state has a summary page with a one-paragraph
 * "Current Civil SOL" and "Current Criminal SOL" and a small snapshot table
 * under each. This copies those, credited, so the reporting page can show a
 * state's current law without us restating it.
 *
 * It does not publish. Run it, read `git diff` on the output, rebuild the
 * directory and commit: a scraper that keeps "succeeding" after the source
 * site is redesigned is worse than no scraper, so any state whose page does
 * not parse cleanly keeps its previous entry and the run exits non-zero.
 * Deadlines for physical abuse, assault and injury are not here; those are
 * researched per state in states/<abbr>.json ("deadlines").
 *
 * Usage: node scripts/pull-childusa-sol.js
 *        node scripts/pull-childusa-sol.js --state ut      # one state
 *        node scripts/pull-childusa-sol.js --dry-run       # print, write nothing
 */

const fs = require('fs');
const path = require('path');

const ROOT = path.join(__dirname, '..');
const OUTPUT_FILE = path.join(ROOT, 'js', 'data', 'reporting', 'childusa-sol.json');
const INDEX_URL = 'https://childusa.org/sol/';
const ORIGIN = 'https://childusa.org';
const USER_AGENT = 'Mozilla/5.0 (compatible; KidsOverProfits-reporting-directory/1.0; +https://kidsoverprofits.org/report-abuse/)';
const DELAY_MS = 1500;

/* The 50 states and DC - the same set the reporting directory covers. */
const STATES = [
    'AL', 'AK', 'AZ', 'AR', 'CA', 'CO', 'CT', 'DE', 'DC', 'FL', 'GA', 'HI', 'ID',
    'IL', 'IN', 'IA', 'KS', 'KY', 'LA', 'ME', 'MD', 'MA', 'MI', 'MN', 'MS', 'MO',
    'MT', 'NE', 'NV', 'NH', 'NJ', 'NM', 'NY', 'NC', 'ND', 'OH', 'OK', 'OR', 'PA',
    'RI', 'SC', 'SD', 'TN', 'TX', 'UT', 'VT', 'VA', 'WA', 'WV', 'WI', 'WY'
];

const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

async function get(url) {
    const res = await fetch(url, { headers: { 'User-Agent': USER_AGENT, Accept: 'text/html' }, redirect: 'follow' });
    if (!res.ok) throw new Error(`${res.status} ${res.statusText}`);
    return res.text();
}

function decodeEntities(text) {
    return text
        .replace(/&nbsp;|&#160;/g, ' ')
        .replace(/&amp;/g, '&')
        .replace(/&quot;/g, '"')
        .replace(/&#8217;|&rsquo;/g, '’')
        .replace(/&#8216;|&lsquo;/g, '‘')
        .replace(/&#8220;|&ldquo;/g, '“')
        .replace(/&#8221;|&rdquo;/g, '”')
        .replace(/&#8211;|&ndash;/g, '–')
        .replace(/&#8212;|&mdash;/g, '—')
        .replace(/&#(\d+);/g, (_, n) => String.fromCharCode(Number(n)))
        .replace(/&lt;/g, '<')
        .replace(/&gt;/g, '>');
}

/* Tags out, entities decoded, whitespace collapsed. */
function text(html) {
    return decodeEntities(html.replace(/<[^>]+>/g, ' ')).replace(/\s+/g, ' ').trim();
}

/* Block-level pieces of a cell as separate strings ("CSA: NONE", "CSAM: NONE"). */
function lines(html) {
    return html
        .split(/<\/p>|<br\s*\/?>|<\/li>|<\/div>/i)
        .map(text)
        .filter(Boolean);
}

/* A <br> in the middle of a sentence ("a very<br>narrow discovery rule")
 * splits it; join a piece onto the last one until that one ends a sentence. */
function sentences(pieces) {
    return pieces.reduce((out, piece) => {
        const last = out[out.length - 1];
        if (last !== undefined && !/[.:;!?)\]]$/.test(last)) {
            out[out.length - 1] = `${last} ${piece}`;
        } else {
            out.push(piece);
        }
        return out;
    }, []);
}

/** State abbreviation => that state's law index page, read from the tracker's own map. */
function stateUrls(indexHtml) {
    const urls = {};
    const re = /"id":"US-([A-Z]{2})","tooltipContent":"[^"]*","content":"([^"]+)"/g;
    let m;
    while ((m = re.exec(indexHtml))) {
        const slug = m[2].replace(/\\\//g, '/').replace(/\/+$/, '');
        if (!urls[m[1]] && slug.startsWith('/law/')) {
            urls[m[1]] = `${ORIGIN}${slug}/`;
        }
    }
    return urls;
}

/**
 * One "Current Civil SOL" / "Current Criminal SOL" column: the paragraph
 * under the heading and the snapshot table after it.
 */
function parseSection(html, heading) {
    const start = html.search(new RegExp(`<h1[^>]*>\\s*${heading}\\s*</h1>`, 'i'));
    if (start < 0) return null;
    /* The column ends where the next heading module starts. */
    const rest = html.slice(start + 10);
    const next = rest.search(/<h1 class="et_pb_module_heading">/i);
    const column = next < 0 ? rest : rest.slice(0, next);

    const inners = [...column.matchAll(/<div class="et_pb_text_inner">([\s\S]*?)<\/div>/gi)].map((m) => m[1]);
    const summaryHtml = inners.find((h) => !/<table/i.test(h));
    const tableHtml = (column.match(/<table[\s\S]*?<\/table>/i) || [])[0];
    if (!summaryHtml || !tableHtml) return null;

    const snapshot = [];
    for (const row of tableHtml.matchAll(/<tr[^>]*>([\s\S]*?)<\/tr>/gi)) {
        const cells = [...row[1].matchAll(/<td[^>]*>([\s\S]*?)<\/td>/gi)].map((c) => c[1]);
        /* The title row is one spanning cell. A third column, where there is
         * one, is the case or statute behind the value (Maryland). */
        if (cells.length < 2) continue;
        const label = text(cells[0]);
        const values = cells.slice(1).flatMap(lines);
        if (label && values.length) snapshot.push({ label, values });
    }
    return {
        summary: sentences(lines(summaryHtml)),
        snapshot
    };
}

function parseStatePage(html) {
    const civil = parseSection(html, 'Current Civil SOL');
    const criminal = parseSection(html, 'Current Criminal SOL');
    const problems = [];
    for (const [name, section] of [['civil', civil], ['criminal', criminal]]) {
        if (!section) { problems.push(`no "${name}" section`); continue; }
        if (!section.summary.length || section.summary.join(' ').length < 20) problems.push(`${name} summary is empty`);
        if (section.snapshot.length < 2) problems.push(`${name} snapshot has ${section.snapshot.length} rows`);
    }
    return { civil, criminal, problems };
}

function readPrevious() {
    try {
        return JSON.parse(fs.readFileSync(OUTPUT_FILE, 'utf8'));
    } catch (err) {
        return { states: {} };
    }
}

async function main() {
    const args = process.argv.slice(2);
    const dryRun = args.includes('--dry-run');
    const only = args.includes('--state') ? String(args[args.indexOf('--state') + 1] || '').toUpperCase() : '';
    const today = new Date().toISOString().slice(0, 10);

    const indexHtml = await get(INDEX_URL);
    const urls = stateUrls(indexHtml);
    const wanted = only ? [only] : STATES;
    const previous = readPrevious();
    const states = { ...previous.states };
    const failed = [];
    const changed = [];

    for (const abbr of wanted) {
        const indexUrl = urls[abbr];
        if (!indexUrl) {
            failed.push(`${abbr}: not on the tracker's map`);
            continue;
        }
        let parsed;
        let url = indexUrl;
        try {
            /* The summary's slug varies by state (child-sex-abuse-sol,
             * sex-abuse-sol, maine-child-sex-abuse-sol), so take the link the
             * state's own index page gives. */
            const index = await get(indexUrl);
            const escaped = indexUrl.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
            const link = index.match(new RegExp(`${escaped}[a-z-]*sex-abuse-sol/?`, 'i'));
            if (!link) throw new Error('no sex-abuse-sol link on the state page');
            url = link[0];
            await sleep(DELAY_MS);
            parsed = parseStatePage(await get(url));
        } catch (err) {
            failed.push(`${abbr}: ${err.message} (${url})`);
            await sleep(DELAY_MS);
            continue;
        }
        if (parsed.problems.length) {
            failed.push(`${abbr}: ${parsed.problems.join('; ')} (${url})`);
        } else {
            const entry = { url, civil: parsed.civil, criminal: parsed.criminal };
            const before = previous.states[abbr];
            const same = before && JSON.stringify({ ...before, pulled_on: undefined }) === JSON.stringify(entry);
            states[abbr] = { ...entry, pulled_on: same ? before.pulled_on : today };
            if (!same) changed.push(abbr);
            if (dryRun) console.log(JSON.stringify({ [abbr]: states[abbr] }, null, 2));
        }
        await sleep(DELAY_MS);
    }

    const out = {
        source: INDEX_URL,
        credit: 'CHILD USA',
        note: 'Generated by scripts/pull-childusa-sol.js from CHILD USA\'s state summaries. Child sexual abuse, trafficking and CSAM only. Do not edit by hand.',
        checked_on: today,
        states: Object.fromEntries(Object.keys(states).sort().map((k) => [k, states[k]]))
    };

    if (!dryRun) {
        fs.writeFileSync(OUTPUT_FILE, `${JSON.stringify(out, null, 2)}\n`, 'utf8');
        console.log(`Wrote ${path.relative(ROOT, OUTPUT_FILE)}`);
    }
    console.log(`  ${wanted.length - failed.length} of ${wanted.length} states parsed, ${changed.length} changed${changed.length ? ': ' + changed.join(' ') : ''}`);
    if (changed.length && !dryRun) {
        console.log('  Read the diff, then: node scripts/build-reporting-directory.js');
    }
    if (failed.length) {
        console.error(`\n${failed.length} state${failed.length === 1 ? '' : 's'} kept their previous entry:`);
        failed.forEach((f) => console.error(`  ${f}`));
        process.exit(1);
    }
}

main().catch((err) => {
    console.error(err);
    process.exit(1);
});
