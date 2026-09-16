#!/usr/bin/env node
/**
 * Phase 0 baseline for the facility data model migration (docs/DATA-MODEL-MIGRATION.md).
 *
 * Records what every state and country page shows TODAY so any later change can
 * be diffed against it. The zero-loss gate is "every (page, facility) pair in
 * the baseline is still present afterwards".
 *
 * Usage:
 *   node scripts/snapshot-location-pages.js
 *   node scripts/snapshot-location-pages.js --out tmp/after-phase3.json --model v2
 *   node scripts/snapshot-location-pages.js --base https://kids-over-profits.local --insecure
 *
 * Options:
 *   --base <url>     Site to snapshot (default https://kidsoverprofits.org).
 *   --out <path>     Output file (default tmp/baseline-<YYYY-MM-DD>.json).
 *   --model <v1|v2>  Appended as ?model= to each route (phase 3 step 4).
 *   --concurrency N  Parallel requests (default 4).
 *   --insecure       Skip TLS verification (Flywheel Local self-signed certs).
 *
 * No dependencies. Plain curl is bot-walled on production, so every request
 * carries a browser User-Agent.
 */

const fs = require('fs');
const path = require('path');
const https = require('https');
const http = require('http');

const US_STATES = [
    'Alabama', 'Alaska', 'Arizona', 'Arkansas', 'California', 'Colorado', 'Connecticut',
    'Delaware', 'District of Columbia', 'Florida', 'Georgia', 'Hawaii', 'Idaho', 'Illinois',
    'Indiana', 'Iowa', 'Kansas', 'Kentucky', 'Louisiana', 'Maine', 'Maryland', 'Massachusetts',
    'Michigan', 'Minnesota', 'Mississippi', 'Missouri', 'Montana', 'Nebraska', 'Nevada',
    'New Hampshire', 'New Jersey', 'New Mexico', 'New York', 'North Carolina', 'North Dakota',
    'Ohio', 'Oklahoma', 'Oregon', 'Pennsylvania', 'Rhode Island', 'South Carolina',
    'South Dakota', 'Tennessee', 'Texas', 'Utah', 'Vermont', 'Virginia', 'Washington',
    'West Virginia', 'Wisconsin', 'Wyoming',
];

// The 16 country rows in locations_master as of 2026-09-16. A country with no
// row still answers the route (empty page), so an extra name here is harmless.
const COUNTRIES = [
    'Argentina', 'Australia', 'Canada', 'Costa Rica', 'Czech Republic', 'Dominican Republic',
    'Fiji', 'Israel', 'Italy', 'Jamaica', 'Jersey', 'Mexico', 'New Zealand', 'Samoa',
    'United Arab Emirates', 'United Kingdom',
];

const UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36';

function parseArgs(argv) {
    const opts = {
        base: 'https://kidsoverprofits.org',
        out: null,
        model: null,
        concurrency: 4,
        insecure: false,
    };
    for (let i = 2; i < argv.length; i++) {
        const a = argv[i];
        if (a === '--base') opts.base = argv[++i];
        else if (a === '--out') opts.out = argv[++i];
        else if (a === '--model') opts.model = argv[++i];
        else if (a === '--concurrency') opts.concurrency = parseInt(argv[++i], 10) || 4;
        else if (a === '--insecure') opts.insecure = true;
        else if (a === '--help' || a === '-h') {
            console.log(fs.readFileSync(__filename, 'utf8').split('*/')[0]);
            process.exit(0);
        } else {
            console.error('Unknown argument: ' + a);
            process.exit(2);
        }
    }
    opts.base = opts.base.replace(/\/+$/, '');
    if (!opts.out) {
        const d = new Date();
        const stamp = [
            d.getFullYear(),
            String(d.getMonth() + 1).padStart(2, '0'),
            String(d.getDate()).padStart(2, '0'),
        ].join('-');
        opts.out = path.join('tmp', 'baseline-' + stamp + '.json');
    }
    return opts;
}

function slugify(name) {
    return name.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '');
}

function fetchJson(url, opts, attempt = 1) {
    return new Promise((resolve, reject) => {
        const mod = url.startsWith('https:') ? https : http;
        const req = mod.get(url, {
            headers: {
                'User-Agent': UA,
                'Accept': 'application/json',
                'Accept-Language': 'en-US,en;q=0.9',
                'Accept-Encoding': 'identity',
            },
            rejectUnauthorized: !opts.insecure,
            timeout: 120000,
        }, (res) => {
            const chunks = [];
            res.on('data', (c) => chunks.push(c));
            res.on('end', () => {
                const body = Buffer.concat(chunks).toString('utf8');
                if (res.statusCode !== 200) {
                    reject(new Error('HTTP ' + res.statusCode + ' for ' + url + ' :: ' + body.slice(0, 200)));
                    return;
                }
                try {
                    resolve(JSON.parse(body));
                } catch (e) {
                    reject(new Error('Bad JSON from ' + url + ': ' + e.message));
                }
            });
        });
        req.on('timeout', () => req.destroy(new Error('timeout')));
        req.on('error', (err) => {
            // The host drops long transfers now and then (curl exit 18); retry.
            if (attempt < 4) {
                setTimeout(() => fetchJson(url, opts, attempt + 1).then(resolve, reject), 1500 * attempt);
            } else {
                reject(new Error(err.message + ' for ' + url));
            }
        });
    });
}

/** One tile to its identity fields. Tiles carry facility_ids as an array. */
function tileRecord(tile, bucket) {
    const ids = Array.isArray(tile.facility_ids)
        ? tile.facility_ids.map((n) => parseInt(n, 10)).filter((n) => Number.isFinite(n))
        : [];
    return {
        facility_ids: ids,
        facility_id: ids.length ? ids[0] : null,
        name: String(tile.name || ''),
        city: String(tile.city || ''),
        state: String(tile.state || ''),
        status: String(tile.status || ''),
        bucket: bucket,
        // Inspection-only tiles (in_master false) come from scraped reports,
        // not facility data; the migration cannot add or lose them.
        in_master: tile.in_master !== false,
        in_inspections: tile.in_inspections === true,
    };
}

function pageRecords(payload) {
    const out = [];
    const f = payload && payload.facilities ? payload.facilities : {};
    for (const bucket of ['active', 'closed']) {
        const list = Array.isArray(f[bucket]) ? f[bucket] : [];
        for (const tile of list) out.push(tileRecord(tile, bucket));
    }
    return out;
}

async function mapLimit(items, limit, fn) {
    const results = new Array(items.length);
    let next = 0;
    const workers = new Array(Math.min(limit, items.length)).fill(0).map(async () => {
        while (true) {
            const i = next++;
            if (i >= items.length) return;
            results[i] = await fn(items[i], i);
        }
    });
    await Promise.all(workers);
    return results;
}

async function main() {
    const opts = parseArgs(process.argv);
    const query = opts.model ? '?model=' + encodeURIComponent(opts.model) : '';

    const targets = []
        .concat(US_STATES.map((n) => ({ kind: 'state', name: n, slug: slugify(n) })))
        .concat(COUNTRIES.map((n) => ({ kind: 'country', name: n, slug: slugify(n) })));

    console.log('Snapshotting ' + targets.length + ' pages from ' + opts.base);

    const pages = {};
    const errors = [];
    let done = 0;

    await mapLimit(targets, opts.concurrency, async (t) => {
        const url = opts.base + '/wp-json/kop/v1/' + t.kind + '/' + t.slug + query;
        try {
            const payload = await fetchJson(url, opts);
            const records = pageRecords(payload);
            pages[t.kind + ':' + t.slug] = {
                kind: t.kind,
                name: t.name,
                slug: t.slug,
                counts: payload.counts || null,
                facilities: records,
            };
        } catch (e) {
            errors.push({ page: t.kind + ':' + t.slug, error: e.message });
            console.error('  FAIL ' + t.kind + '/' + t.slug + ': ' + e.message);
        }
        done++;
        if (done % 10 === 0) console.log('  ' + done + '/' + targets.length);
    });

    // Public program index: the directory reads kop/v1/facilities (operator
    // projects + locations_master, ref rows skipped). Record its size so a
    // migration that quietly drops projects is visible too.
    let index = null;
    try {
        const payload = await fetchJson(opts.base + '/wp-json/kop/v1/facilities' + query, opts);
        // The route answers {source, projects:{name:{...}}}; older builds
        // answered the project map directly.
        const projects = payload && payload.projects && typeof payload.projects === 'object'
            ? payload.projects
            : (payload && typeof payload === 'object' ? payload : {});
        const names = Object.keys(projects);
        let nested = 0;
        const perProject = {};
        for (const name of names) {
            const p = projects[name] || {};
            // Wrapper layouts differ per row: facilities sit under data, or at
            // the root on the older operator shape.
            const list = (p.data && Array.isArray(p.data.facilities)) ? p.data.facilities
                : (Array.isArray(p.facilities) ? p.facilities : []);
            perProject[name] = list.length;
            nested += list.length;
        }
        index = { project_count: names.length, nested_facility_count: nested, per_project: perProject };
        console.log('Program index: ' + names.length + ' projects, ' + nested + ' nested facilities');
    } catch (e) {
        errors.push({ page: 'program-index', error: e.message });
        console.error('  FAIL program index: ' + e.message);
    }

    const pageKeys = Object.keys(pages);
    const totalPairs = pageKeys.reduce((n, k) => n + pages[k].facilities.length, 0);
    const distinctIds = new Set();
    for (const k of pageKeys) {
        for (const r of pages[k].facilities) {
            for (const id of r.facility_ids) distinctIds.add(id);
        }
    }

    const snapshot = {
        meta: {
            base: opts.base,
            model: opts.model || 'v1',
            taken_at: new Date().toISOString(),
            pages_requested: targets.length,
            pages_captured: pageKeys.length,
            page_facility_pairs: totalPairs,
            distinct_facility_ids: distinctIds.size,
            errors: errors,
        },
        program_index: index,
        pages: pages,
    };

    const outPath = path.resolve(opts.out);
    fs.mkdirSync(path.dirname(outPath), { recursive: true });
    fs.writeFileSync(outPath, JSON.stringify(snapshot, null, 1));

    console.log('');
    console.log('Pages captured:      ' + pageKeys.length + '/' + targets.length);
    console.log('Page/facility pairs: ' + totalPairs);
    console.log('Distinct ids:        ' + distinctIds.size);
    if (errors.length) console.log('Errors:              ' + errors.length + ' (see meta.errors)');
    console.log('Written to           ' + outPath);
    if (errors.length) process.exitCode = 1;
}

main().catch((e) => {
    console.error(e);
    process.exit(1);
});
