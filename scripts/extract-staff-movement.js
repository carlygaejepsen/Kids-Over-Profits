#!/usr/bin/env node
/**
 * Staff movement: from the profile sentences to a reviewable CSV.
 *
 * The editorial facility profiles carry an ACF "Staff Movement" block
 * (staff_transfers, _2, _3) of free sentences such as
 *
 *   "Clint Dorny (executive director) began in admissions at Provo Canyon
 *    School; Dave Blackwell (therapist) and Brent Hall also came from Provo
 *    Canyon School"
 *
 * templates/single-facility-profile.php already sorts these into "came
 * from" and "went on to" for the profile page. This does the same sorting,
 * splits out the people and the organisations, and writes one row per
 * person per move to js/data/network/staff-movement.csv:
 *
 *   person, from, to, role, year, source
 *
 * where one of from/to is the profile's own facility. It is a first draft for
 * a person to correct, not data to trust: free text parses imperfectly, so
 * the CSV is the reviewed artefact and the build reads the CSV, never the
 * sentences. An existing CSV is not overwritten unless --force is given,
 * because the review lives in it; without --force the draft goes to
 * tmp/staff-movement.draft.csv for comparison.
 *
 * Sources: published posts and pages in tmp/prod.sqlite (scripts/
 * sync-prod-sqlite.py), and the seed records in seeds/*.json.
 *
 * Usage: node scripts/extract-staff-movement.js [--force]
 */

const fs = require('fs');
const path = require('path');

const ROOT = path.join(__dirname, '..');
const SQLITE_FILE = path.join(ROOT, 'tmp', 'prod.sqlite');
const SEEDS_DIR = path.join(ROOT, 'seeds');
const CSV_FILE = path.join(ROOT, 'js', 'data', 'network', 'staff-movement.csv');
const DRAFT_FILE = path.join(ROOT, 'tmp', 'staff-movement.draft.csv');
const KEYS = ['staff_transfers', 'staff_transfers_2', 'staff_transfers_3'];

/* The same two tests the profile template uses, plus "previously worked at"
 * and the arrow some profiles use for "went on to". */
const WENT = /\b(?:went on|moved (?:on )?to|left\b|founded|to found|to lead|to run|later (?:ran|led|joined|opened|founded|worked)|now (?:runs|leads|works)|transferred to|opened)\b|→/i;
const CAME = /\b(?:came from|came over from|began\b.*\bat\b|started\b.*\bat\b|before\b|previously|formerly (?:at|of|with)|transferred from|joined from|arrived from)\b/i;

const ROLE_WORDS = /^(?:(?:co-?founder|founder|clinical director|executive director|admissions director|program director|residential director|director|therapist|dorm parent)\s+)+/i;

function collectTexts() {
    const out = [];
    if (fs.existsSync(SQLITE_FILE)) {
        const { DatabaseSync } = require('node:sqlite');
        const db = new DatabaseSync(SQLITE_FILE, { readOnly: true });
        db.prepare(
            "SELECT p.ID AS id, p.post_title AS title, m.meta_value AS text FROM wpdl_postmeta m " +
            "JOIN wpdl_posts p ON p.ID = m.post_id " +
            "WHERE m.meta_key IN ('staff_transfers','staff_transfers_2','staff_transfers_3') " +
            "AND p.post_type IN ('post','page') AND p.post_status = 'publish' " +
            "ORDER BY p.ID, m.meta_key"
        ).all().forEach(function (row) {
            out.push({ facility: row.title, text: row.text, source: 'post ' + row.id });
        });
        db.close();
    }
    if (fs.existsSync(SEEDS_DIR)) {
        fs.readdirSync(SEEDS_DIR).filter(function (f) { return /\.json$/.test(f); }).forEach(function (file) {
            let seed;
            try { seed = JSON.parse(fs.readFileSync(path.join(SEEDS_DIR, file), 'utf8')); } catch (err) { return; }
            const meta = seed && seed.meta;
            if (!meta) return;
            KEYS.forEach(function (key) {
                if (meta[key]) out.push({ facility: seed.title || seed.post_title || file, text: meta[key], source: 'seed ' + file });
            });
        });
    }
    /* The same sentence can be in a seed and on the published post. */
    const seen = new Set();
    return out.filter(function (t) {
        const text = String(t.text || '').trim();
        if (!text || /^staff transfers:?$/i.test(text)) return false;
        const key = t.facility + '|' + text;
        if (seen.has(key)) return false;
        seen.add(key);
        return true;
    });
}

/** Organisation names out of the tail of a sentence. */
function orgsFrom(tail) {
    return tail
        .replace(/\([^)]*\)/g, ' ')
        .replace(/\b(?:in|from|since)\s+(?:19|20)\d\d\b/g, ' ')
        .split(/\s*,\s*(?:and\s+)?|\s+and\s+/)
        .map(function (s) { return s.replace(/^(?:at|to|the|also|each|went on to (?:run|lead)|went on to|to run|to lead|to found|founded)\s+/i, '').trim(); })
        .map(function (s) { return s.replace(/[.;]+$/, '').trim(); })
        .filter(function (s) { return /^[A-Z0-9]/.test(s) && s.length > 2; });
}

function parse(entry) {
    const rows = [];
    String(entry.text).split(/\s*;\s*/).forEach(function (item) {
        item = item.trim().replace(/\.$/, '');
        if (!item) return;
        const went = WENT.test(item);
        const came = !went && CAME.test(item);
        const year = (/\b((?:19|20)\d\d)\b/.exec(item) || [])[1] || '';

        /* Leading role words ("Clinical Director Eilean MacKenzie"), then
         * the people: one name, or two joined by "and", each optionally
         * followed by a parenthetical role. */
        let rest = item;
        let role = '';
        const lead = ROLE_WORDS.exec(rest);
        if (lead) { role = lead[0].trim(); rest = rest.slice(lead[0].length); }
        const word = "[A-Z][A-Za-z'’.-]*";
        const name = '(?:' + word + '\\s+)*' + word;
        const people = [];
        const first = new RegExp('^(' + name + ')\\s*(\\(([^)]*)\\))?').exec(rest);
        if (!first) return;
        people.push({ name: first[1], role: first[3] || role });
        rest = rest.slice(first[0].length);
        const second = new RegExp('^\\s*,?\\s*and\\s+(' + name + ')\\s*(\\(([^)]*)\\))?').exec(rest);
        if (second) {
            people.push({ name: second[1], role: second[3] || '' });
            rest = rest.slice(second[0].length);
        }

        /* What is left names the other places: after the arrow or verb. */
        const tail = rest.replace(/^.*?(?:→|went on to (?:run|lead)|went on to|moved to|left in (?:19|20)\d\d to found|left to found|to found|founded|to lead|to run|previously worked at|came from|also came from|began in [a-z ]+ at|began at|led|worked at)\s*/i, '');
        const orgs = orgsFrom(tail);
        people.forEach(function (person) {
            orgs.forEach(function (org) {
                rows.push({
                    person: person.name,
                    from: came ? org : entry.facility,
                    to: came ? entry.facility : org,
                    role: person.role,
                    year: year,
                    source: entry.source
                });
            });
            if (!orgs.length) {
                rows.push({ person: person.name, from: '', to: '', role: person.role, year: year, source: entry.source + ' (unparsed: ' + item + ')' });
            }
        });
    });
    return rows;
}

function csvCell(value) {
    const s = String(value == null ? '' : value);
    return /[",\n]/.test(s) ? '"' + s.replace(/"/g, '""') + '"' : s;
}

function main() {
    const force = process.argv.indexOf('--force') !== -1;
    const texts = collectTexts();
    const rows = [];
    texts.forEach(function (t) { parse(t).forEach(function (r) { rows.push(r); }); });
    const lines = ['person,from,to,role,year,source'].concat(rows.map(function (r) {
        return [r.person, r.from, r.to, r.role, r.year, r.source].map(csvCell).join(',');
    }));
    const target = (!fs.existsSync(CSV_FILE) || force) ? CSV_FILE : DRAFT_FILE;
    fs.mkdirSync(path.dirname(target), { recursive: true });
    fs.writeFileSync(target, lines.join('\n') + '\n', 'utf8');
    console.log(texts.length + ' sentences, ' + rows.length + ' rows -> ' + path.relative(ROOT, target));
    if (target === DRAFT_FILE) console.log('The reviewed CSV exists; compare the draft against it by hand.');
}

if (require.main === module) main();
module.exports = { parse: parse };
