#!/usr/bin/env node
/**
 * Turns the owner's staff list (js/data/network/staff-list.txt) into
 * js/data/network/staff-list.csv: one row per person per place
 * (person, place, role, source).
 *
 * Each line is "Name (role place, role place, ...)". An entry's place is
 * found by looking for a board node's name (or alias, or a short form in
 * SHORT_FORMS below) inside it; what comes before the name is the role. An
 * entry that names no board node keeps its best-guess place text so the
 * build can list it in tmp/network-qa.md. Family ties ("married to",
 * "son-in-law") and places marked not TTI are left out.
 *
 * The list is a backup source: the build reads the CSV after
 * staff-movement.csv and draws only connections the map does not already
 * have (provenance "staff-list").
 *
 *   node scripts/parse-staff-list.js           rewrite the CSV
 *   node scripts/parse-staff-list.js --report  also print unmatched entries
 */
'use strict';

const fs = require('fs');
const path = require('path');

const DATA_DIR = path.join(__dirname, '..', 'js', 'data', 'network');
const LIST_TXT = path.join(DATA_DIR, 'staff-list.txt');
const LIST_CSV = path.join(DATA_DIR, 'staff-list.csv');
const GRAPH_JSON = path.join(DATA_DIR, 'graph.json');
const SOURCE = 'staff list 2026-09-18';

/* Short forms the list uses for places on the board. Keys and values are
 * compared after nameKey(); the value must be a board node name or alias. */
const SHORT_FORMS = {
    '2n': 'Second Nature',
    'second nature wilderness': 'Second Nature',
    'mba': 'Mount Bachelor Academy',
    'mt bachelor academy': 'Mount Bachelor Academy',
    'mt bachelor': 'Mount Bachelor Academy',
    'bca': 'Boulder Creek Academy',
    'boulder creek': 'Boulder Creek Academy',
    'nwa cedu': 'Northwest Academy',
    'northwest academy cedu': 'Northwest Academy',
    'sra': 'Spring Ridge Academy',
    'rma': 'Rocky Mountain Academy',
    'acs': 'Alabama Clinical Schools',
    'bba': 'Bethel Boys Academy',
    'aspen aa': 'Aspen Achievement Academy',
    'copper hills yc': 'Copper Hills Youth Center',
    'provo canyon': 'Provo Canyon School',
    'oakley': 'The Oakley School',
    'outback': 'Outback Therapeutic Expeditions',
    'ascent': 'Ascent Wilderness Therapy',
    'ascent wilderness': 'Ascent Wilderness Therapy',
    'ascent wilderness program': 'Ascent Wilderness Therapy',
    'island view': 'Island View RTC',
    'fulshear': 'Fulshear Treatment to Transition',
    'fulshear ranch': 'Fulshear Treatment to Transition',
    'fulshear ranch academy': 'Fulshear Treatment to Transition',
    'cross creek': 'Cross Creek Programs',
    'wwasp': 'WWASPS',
    'cedu hs': 'CEDU High School',
    'lonestar expeditions': 'Lone Star Expeditions',
    'aspen institute for ba': 'Aspen Institute for Behavioral Assessment',
    'the aspen institute': 'Aspen Institute for Behavioral Assessment',
    'aspiro wilderness': 'Aspiro Wilderness Adventure Therapy',
    'youth care': 'Youth Care of Utah',
    'youth care inc': 'Youth Care of Utah',
    'sagewalk': 'SageWalk Wilderness',
    'carlbrook': 'Carlbrook School',
    'bluefire wilderness': 'blueFire Wilderness Therapy',
    'northwoods': 'Northwoods Boarding School',
    'heritage rtc': 'Heritage Schools (Heritage RTC)',
    'heritage schools': 'Heritage Schools (Heritage RTC)',
    'heritage school/community': 'Heritage Schools (Heritage RTC)',
    'westridge academy': 'West Ridge Academy',
    'sequel': 'Sequel Youth and Family Services',
    'sequel youth & family services': 'Sequel Youth and Family Services',
    'uhs': 'Universal Health Services',
    'obhic': 'Outdoor Behavioral Healthcare Industry Council',
    'ohbic': 'Outdoor Behavioral Healthcare Industry Council',
    'laurel oaks bh center': 'Laurel Oaks Behavioral Health Center',
    'laurel oaks bhc': 'Laurel Oaks Behavioral Health Center',
    'san marcos tc': 'San Marcos Treatment Center',
    'gulf coast tc': 'Gulf Coast Treatment Center',
    'cinnamon hills': 'Cinnamon Hills Youth Crisis Center',
    'summit prep school': 'Summit Preparatory School',
    'rop': 'Rite of Passage, Inc',
    'wingate': 'WinGate Wilderness Therapy',
    'wingate wilderness': 'WinGate Wilderness Therapy',
    'elements wilderness': 'Elements Wilderness Program',
    'turning winds': 'Turning Winds Academic Institute',
    'hawks landing': "Hawk's Landing Therapeutic Home for Boys",
    'cedar crest hospital': 'Cedar Crest Hospital & RTC',
    'hill crest behavioral hospital': 'Hill Crest Behavioral Health',
    'rebekah home': 'Rebekah Home for Girls',
    'bethesda home': 'Bethesda Home for Girls',
    'timberlawn bh system': 'Timberlawn Behavioral Health System',
    'vivant': 'Vivant Behavioral Healthcare',
    'mountain springs prep academy': 'Mountain Springs Preparatory Academy',
    'turning points recovery center': "Crossroads' Turning Points Recovery Center",
    'aspen education': 'Aspen Education Group',
    'cedu': 'CEDU Family of Services',
    'family health & wellness': 'Family Help & Wellness',
    'spring creek lodge': 'Spring Creek Lodge Academy',
    'phoenix outdoor': 'Phoenix Outdoor',
    'first light wt': 'First Light Wilderness Therapy',
    'first light wilderness': 'First Light Wilderness Therapy',
    'first light': 'First Light Wilderness Therapy',
    'blue ridge tw': 'Blue Ridge Therapeutic Wilderness',
    'new vision wt': 'New Vision Wilderness',
    'new haven': 'New Haven RTC',
    'kolob canyon': 'Kolob Canyon RTC',
    'sedona sky': 'Sedona Sky Academy',
    'havenwood': 'Havenwood Academy',
    'red cliff ascent': 'RedCliff Ascent',
    'open sky wilderness': 'Open Sky Wilderness Program',
    'open sky wilderness therapy': 'Open Sky Wilderness Program',
    'discover ranch for girls': 'Discovery Ranch for Girls',
    'falcoln ridge ranch': 'Falcon Ridge Ranch',
    'arivica boys ranch': 'Arivaca Boys Ranch',
    'peninsula vilage': 'Peninsula Village',
    'greenbriar academy': 'Greenbrier Academy',
    'devereaux': 'Devereux Foundation',
    'devereaux foundation': 'Devereux Foundation',
    'straight': 'Straight Inc',
    'embark flathead valley': 'Embark at Flathead Valley',
    'embark': 'Embark Behavioral Health',
    'chrysalis': 'Chrysalis Therapeutic Boarding School',
    'chrysalis school': 'Chrysalis Therapeutic Boarding School',
    'calo': 'CALO Programs',
    'deschutes wilderness program': 'Deschutes Wilderness Therapy',
    'new leaf academy nc': 'New Leaf Academy of North Carolina',
    'cedar ridge rtc': 'Cedar Ridge Academy',
    'the pinnacle school': 'The Pinnacle Schools',
    'browning academy': 'Browning Distance Learning Academy',
    'suws of idaho': 'SUWS Idaho',
    'academy of eastern az': 'Academy of Eastern Arizona',
    'la europa': 'La Europa Academy',
    'cascade': 'Cascade School',
    'north tampa behavioral health': 'Tampa Behavioral Health',
    'red mountain co': 'Red Mountain Colorado',
    'roots transitions': 'ROOTs Transition',
    'solstice': 'Solstice RTC',
    'sandhill center': 'Sandhill Child Development Center',
    'copper canyon': 'Copper Canyon Academy',
    'new visions wilderness': 'New Vision Wilderness',
    'vista adolescent treatment centers': 'Vista Treatment Centers',
    'uita academy': 'Uinta Academy',
    'equinox': 'Equinox RTC',
    'vista rtc': 'Vista Treatment Centers',
    'vista residential treatment centers': 'Vista Treatment Centers',
    'suws carolinas': 'SUWS of the Carolinas',
    'agape': 'Agape Boarding School',
    'circle of hope': "Circle of Hope Girls' Ranch",
    'asheville academy': 'Asheville Academy for Girls',
    'roloff ministries': 'Roloff Evangelistic Enterprises',
    'roloff evangelical enterprises': 'Roloff Evangelistic Enterprises',
    'new beginnings girls home': "New Beginnings Girls' Academy",
    'cartisano': 'Steve Cartisano',
    'cedar county sheriffs dept': "Cedar County Sheriff's Department",
    'jim clemenson': '"Brother Jim" Clemenson'
};

/* Trailing words that may be missing from the list's spelling of a place. */
const OPTIONAL_TAIL = /\s(program|programs|wilderness therapy|wilderness program|therapy|rtc|center|residential treatment center|school)$/;

function nameKey(value) {
    return String(value || '')
        .toLowerCase()
        .replace(/['’]/g, '')
        .replace(/&/g, ' and ')
        .replace(/\b(the|inc|llc|co)\b/g, ' ')
        .replace(/[^a-z0-9]+/g, ' ')
        .trim();
}

function csvField(value) {
    const s = String(value == null ? '' : value);
    return /[",\n]/.test(s) ? '"' + s.replace(/"/g, '""') + '"' : s;
}

/* Split on commas that are not inside [brackets]. */
function splitTop(text) {
    const parts = [];
    let depth = 0, cur = '';
    for (const ch of text) {
        if (ch === '[') depth++;
        if (ch === ']') depth = Math.max(0, depth - 1);
        if (ch === ',' && depth === 0) { parts.push(cur); cur = ''; continue; }
        cur += ch;
    }
    parts.push(cur);
    return parts.map(function (p) { return p.trim(); }).filter(Boolean);
}

/* A bracket that lists places ("[Rocky Mountain Academy, Boulder Creek
 * Academy]") is part of the claim; one that describes the place ("[UHS]",
 * "[operated by ...]", "[rebrand ...]") is not. */
function unbracket(entry) {
    return entry.replace(/\[([^\]]*)\]?/g, function (m, inner) {
        return inner.indexOf(',') !== -1 ? ', ' + inner : ' ';
    }).replace(/\s+/g, ' ').trim();
}

function cleanPerson(raw) {
    return raw
        .replace(/\[[^\]]*\]/g, ' ')
        .replace(/[“"][^”"]*[”"]/g, ' ')
        .replace(/\s+aka\s+.*$/i, '')
        .replace(/\s+/g, ' ')
        .trim();
}

const FAMILY = /\b(married|wife|husband|son|daughter|father|mother|brother|sister|in-law|related)\b/i;
const NOT_TTI = /not tti|non-tti/i;

function buildMatcher(nodes) {
    const keys = new Map();
    const add = function (key, node) {
        if (!key) return;
        if (!keys.has(key)) keys.set(key, new Set());
        keys.get(key).add(node);
    };
    nodes.forEach(function (node) {
        /* People the build added from this list are not places. */
        if (node.addedFrom) return;
        [node.name].concat(node.aliases || []).forEach(function (name) {
            const key = nameKey(name);
            add(key, node);
            const short = key.replace(OPTIONAL_TAIL, '');
            if (short !== key && short.split(' ').length >= 2) add(short, node);
        });
    });
    const byName = new Map();
    nodes.forEach(function (node) { byName.set(nameKey(node.name), node); });
    Object.keys(SHORT_FORMS).forEach(function (form) {
        const node = byName.get(nameKey(SHORT_FORMS[form]));
        if (node) add(nameKey(form), node);
    });
    /* Longest first, so "Second Nature Blue Ridge" wins over "Second Nature". */
    const sorted = Array.from(keys.keys()).filter(function (k) { return keys.get(k).size === 1; })
        .sort(function (a, b) { return b.length - a.length; });
    return { keys: keys, sorted: sorted, missingShortForms: Object.keys(SHORT_FORMS).filter(function (f) {
        return !byName.has(nameKey(SHORT_FORMS[f]));
    }) };
}

/* Every board place named in one entry, left to right, with the role text
 * that precedes the first one. */
function findPlaces(entry, matcher) {
    let key = ' ' + nameKey(entry) + ' ';
    const hits = [];
    matcher.sorted.forEach(function (k) {
        const needle = ' ' + k + ' ';
        let at = key.indexOf(needle);
        while (at !== -1) {
            hits.push({ at: at, key: k, node: Array.from(matcher.keys.get(k))[0] });
            key = key.slice(0, at) + ' ' + '#'.repeat(k.length) + ' ' + key.slice(at + needle.length);
            at = key.indexOf(needle);
        }
    });
    hits.sort(function (a, b) { return a.at - b.at; });
    /* The role is the words before the first place, as written: drop words
     * off the front until what is left starts with the matched name. */
    let role = '';
    if (hits.length) {
        const words = entry.split(/\s+/);
        for (let i = 0; i < words.length; i++) {
            if ((nameKey(words.slice(i).join(' ')) + ' ').indexOf(hits[0].key + ' ') === 0) {
                role = words.slice(0, i).join(' ');
                break;
            }
        }
    }
    return { hits: hits, role: role };
}

/* No board node: split "role Place Name" at the first capitalised word. */
function guessPlace(entry) {
    const words = entry.split(/\s+/);
    const first = words.findIndex(function (w) { return /^[A-Z0-9]/.test(w); });
    if (first <= 0) return { role: '', place: entry };
    return { role: words.slice(0, first).join(' '), place: words.slice(first).join(' ') };
}

function main() {
    const graph = JSON.parse(fs.readFileSync(GRAPH_JSON, 'utf8'));
    const matcher = buildMatcher(graph.nodes);
    const lines = fs.readFileSync(LIST_TXT, 'utf8').split(/\r?\n/);
    const rows = [];
    const seen = new Set();
    const report = { unmatched: [], skipped: [] };

    lines.forEach(function (line) {
        line = line.trim();
        if (!line || line[0] === '#') return;
        const open = line.indexOf('(');
        if (open === -1) { report.skipped.push(line); return; }
        const person = cleanPerson(line.slice(0, open));
        let body = line.slice(open + 1);
        const close = body.lastIndexOf(')');
        if (close !== -1) body = body.slice(0, close);

        /* "family teacher, Compass Rose Academy": a role with no capitalised
         * word is the role for the entry after it. "Associates, Inc" is one
         * name. */
        const entries = [];
        splitTop(body).forEach(function (entry) {
            if (/^inc\.?$/i.test(entry)) return;
            const prev = entries[entries.length - 1];
            if (prev && prev.carry) { entries[entries.length - 1] = { text: prev.text + ' ' + entry }; return; }
            entries.push({ text: entry, carry: !/[A-Z0-9]/.test(entry) && !NOT_TTI.test(entry) });
        });
        entries.map(function (e) { return e.text; }).forEach(function (entry) {
            if (NOT_TTI.test(entry) || FAMILY.test(entry)) { report.skipped.push(person + ': ' + entry); return; }
            const text = unbracket(entry);
            const found = findPlaces(text, matcher);
            const role = found.role.replace(/^\?\s*/, '').replace(/\s+(at|of|for)( the)?$/i, '').trim();
            const add = function (place, r) {
                const k = nameKey(person) + '|' + nameKey(place);
                if (seen.has(k)) return;
                seen.add(k);
                rows.push([person, place, r, SOURCE]);
            };
            if (found.hits.length) {
                found.hits.forEach(function (hit) {
                    if (nameKey(hit.node.name) === nameKey(person)) return;
                    add(hit.node.name, role);
                });
            } else {
                const guess = guessPlace(text.replace(/^\?\s*/, ''));
                report.unmatched.push(person + ': ' + text);
                add(guess.place, guess.role);
            }
        });
    });

    const out = ['person,place,role,source'].concat(rows.map(function (r) {
        return r.map(csvField).join(',');
    }));
    fs.writeFileSync(LIST_CSV, out.join('\n') + '\n');

    const matched = rows.length - report.unmatched.length;
    console.log('staff-list.csv: ' + rows.length + ' rows (' + matched + ' name a board node, ' +
        report.unmatched.length + ' do not)');
    if (matcher.missingShortForms.length) {
        console.log('Short forms pointing at no board node: ' + matcher.missingShortForms.join(', '));
    }
    if (process.argv.indexOf('--report') !== -1) {
        console.log('\nNo board node:\n  ' + report.unmatched.join('\n  '));
        console.log('\nLeft out (family, not TTI, no parenthesis):\n  ' + report.skipped.join('\n  '));
    }
}

main();
