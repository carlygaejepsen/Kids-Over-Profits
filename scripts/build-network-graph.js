#!/usr/bin/env node
/**
 * Build Network Graph
 *
 * Turns the Miro board export (tti_nodes.csv + tti_edges.csv) into a single
 * normalized graph.json for the network map, plus a QA report listing every
 * row the rules had to guess at.
 *
 * Architecture:
 * - js/data/network/tti_nodes.csv  = source of truth, exported from the board
 * - js/data/network/tti_edges.csv  = source of truth, exported from the board
 * - js/data/network/network-overrides.json = hand-curated corrections
 * - js/data/network/graph.json     = build output, committed
 * - tmp/network-qa.md              = build output, gitignored, review list
 *
 * The CSV "network" column is a Miro frame, not a corporate parent. It is kept
 * as `regions`. Real ownership lives in the `chain` column and in corporate
 * edges.
 *
 * Usage: node scripts/build-network-graph.js
 */

const fs = require('fs');
const path = require('path');
const crypto = require('crypto');

const ROOT = path.join(__dirname, '..');
const DATA_DIR = path.join(ROOT, 'js', 'data', 'network');
const NODES_CSV = path.join(DATA_DIR, 'tti_nodes.csv');
const EDGES_CSV = path.join(DATA_DIR, 'tti_edges.csv');
const OVERRIDES_FILE = path.join(DATA_DIR, 'network-overrides.json');
const OUTPUT_FILE = path.join(DATA_DIR, 'graph.json');
/* Reviewed rows from scripts/extract-staff-movement.js (2b.11). */
const STAFF_MOVEMENT_CSV = path.join(DATA_DIR, 'staff-movement.csv');
/* The owner's staff list, parsed by scripts/parse-staff-list.js. A backup. */
const STAFF_LIST_CSV = path.join(DATA_DIR, 'staff-list.csv');
const QA_FILE = path.join(ROOT, 'tmp', 'network-qa.md');
const SQLITE_FILE = path.join(ROOT, 'tmp', 'prod.sqlite');
const PROGRAMS_FILE = path.join(ROOT, 'js', 'data', 'reddit-wiki', 'programs-array.json');

/* Frames that are legend swatches, not entities. */
const DROP_REGIONS = ['Key'];
/* Frame holding nodes the board author could not attach to anything. */
const ISOLATED_REGION = 'Disconnected';

const CATEGORIES = [
    'corporate', 'family', 'survivor', 'referral', 'board',
    'leadership', 'clinical', 'admissions', 'staff', 'other', 'unknown'
];
const KINDS = ['person', 'facility', 'parent', 'association', 'government', 'church', 'other'];

const qa = {
    kindGuesses: [], ambiguousAcquirers: [], unmatchedFacilities: [], multiMatchFacilities: [],
    weakRelationships: [], weakKinds: [], looseMatches: [], rebrands: [], isolatedNodes: [], mergedNodes: [], duplicateEdges: [],
    droppedRows: [], chainInferred: [], missingHeadline: [], missingViewNames: [],
    rebrandGuesses: [], noYears: [], unmatchedDeaths: [],
    profileEdges: [], profileNames: [], staffMoves: [], staffUnresolved: [],
    staffListEdges: [], staffListUnresolved: [], addedPeople: []
};

/* ------------------------------------------------------------------ *
 * CSV
 * ------------------------------------------------------------------ */

/** RFC 4180 reader. Facility names carry quoted commas and doubled quotes. */
function parseCsv(text) {
    const rows = [];
    let row = [], field = '', inQuotes = false;
    if (text.charCodeAt(0) === 0xFEFF) text = text.slice(1);
    for (let i = 0; i < text.length; i++) {
        const c = text[i];
        if (inQuotes) {
            if (c === '"') {
                if (text[i + 1] === '"') { field += '"'; i++; } else { inQuotes = false; }
            } else { field += c; }
        } else if (c === '"') { inQuotes = true; }
        else if (c === ',') { row.push(field); field = ''; }
        else if (c === '\n') { row.push(field); rows.push(row); row = []; field = ''; }
        else if (c !== '\r') { field += c; }
    }
    if (field !== '' || row.length) { row.push(field); rows.push(row); }
    const header = rows.shift().map((h) => h.trim());
    return rows
        .filter((r) => r.length > 1 && r.some((v) => v !== ''))
        .map((r) => Object.fromEntries(header.map((k, i) => [k, (r[i] || '').trim()])));
}

function slugify(value) {
    return String(value || '')
        .toLowerCase()
        .replace(/['’]/g, '')
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-+|-+$/g, '') || 'node';
}

/** Loose key for matching a board name against facility records. */
function nameKey(value) {
    return String(value || '')
        .toLowerCase()
        .replace(/['’]/g, '')
        .replace(/&/g, ' and ')
        .replace(/\b(the|inc|llc|co)\b/g, ' ')
        .replace(/[^a-z0-9]+/g, ' ')
        .trim();
}

function truthy(value) {
    return String(value).trim().toLowerCase() === 'true';
}

function loadOverrides() {
    if (!fs.existsSync(OVERRIDES_FILE)) {
        return {
            merges: [], aliases: {}, kinds: {}, relationships: {}, facilities: {}, acquirers: {}, headline: [],
            statuses: {}, years: {}, deaths: {}, views: {}
        };
    }
    const raw = JSON.parse(fs.readFileSync(OVERRIDES_FILE, 'utf8'));
    return {
        merges: raw.merges || [], aliases: raw.aliases || {}, kinds: raw.kinds || {},
        headline: raw.headline || [],
        relationships: raw.relationships || {}, facilities: raw.facilities || {},
        acquirers: raw.acquirers || {},
        /* name -> "open" | "closed" | "rebranded", where the board's single
         * "closed or rebranded" and the rebrand rule below get it wrong. */
        statuses: raw.statuses || {},
        /* name -> "1971-2004", for a node no record dates. */
        years: raw.years || {},
        /* name -> count, for memorial rows the matcher cannot place. */
        deaths: raw.deaths || {},
        /* key -> {label, names}: the other ways the map can open (2b.10). */
        views: raw.views || {}
    };
}

/* ------------------------------------------------------------------ *
 * Node kind
 * ------------------------------------------------------------------ */

/* An unmarked row is a person unless its name reads like an organisation. */
const ORG_WORDS = /\b(academy|academies|school|schools|college|university|ranch|center|centre|hospital|rtc|tbs|home|homes|house|camp|camps|lodge|village|institute|program|programs|wilderness|treatment|recovery|expeditions|journey|journeys|quest|outdoor|adventure|campus|facility|services|service|group|groups|partners|capital|investments|enterprises|companies|company|holdings|corporation|healthcare|health|association|associations|council|commission|committee|foundation|ministries|ministry|church|temple|chapel|baptist|department|bureau|administration|society|anonymous|seminars|alternatives|solutions|systems|inc|llc|ltd|natsap|wwasps|certs|synanon|retreat|correctional|juvenile)\b/i;

const GOVERNMENT_WORDS = /\b(department|bureau|administration|sheriff|county|state board|board of education|human resources|human services|correctional facility|military school)\b/i;
const CHURCH_WORDS = /\b(church|ministries|ministry|temple|chapel|evangelistic|baptist)\b/i;
const ASSOCIATION_WORDS = /\b(association|council|commission|committee|society|natsap|anonymous|accreditation)\b/i;
const PARENT_WORDS = /\b(healthcare|health services|health group|behavioral health|behavioral solutions|youth services|family services|human services|and associates|group|partners|capital|investments|enterprises|companies|company|holdings|corporation|international|systems|transport|llc|inc)\b/i;
const FACILITY_WORDS = /\b(academy|academies|school|schools|ranch|center|centre|hospital|rtc|tbs|home|homes|house|camp|camps|lodge|village|villages|institute|program|programs|wilderness|treatment|recovery|expeditions|journey|journeys|quest|outdoor|adventure|campus|facility|retreat|seminars|alternatives|correctional)\b/i;

/**
 * Person vs organisation, then the organisation sub-kind.
 * `guessed` marks rows a human should confirm; they all land in the QA report.
 */
function classifyKind(node, overrides) {
    const name = node.name;
    if (overrides.kinds[name]) return { kind: overrides.kinds[name], guessed: false, reason: 'override' };

    const isOrgRow = node.rawStatus !== 'unmarked';
    if (!isOrgRow && !ORG_WORDS.test(name)) {
        return { kind: 'person', guessed: false, reason: 'unmarked row, personal name' };
    }
    const guessed = !isOrgRow; /* unmarked but reads like an org: always review */
    const reason = guessed ? 'unmarked row, organisation name' : 'status marks an organisation';

    if (GOVERNMENT_WORDS.test(name)) return { kind: 'government', guessed: true, reason: reason + ', government wording' };
    if (ASSOCIATION_WORDS.test(name)) return { kind: 'association', guessed: true, reason: reason + ', association wording' };
    if (CHURCH_WORDS.test(name) && !FACILITY_WORDS.test(name)) return { kind: 'church', guessed: true, reason: reason + ', church wording' };
    if (FACILITY_WORDS.test(name)) return { kind: 'facility', guessed, reason: reason + ', facility wording' };
    if (PARENT_WORDS.test(name)) return { kind: 'parent', guessed: true, reason: reason + ', company wording' };
    /* Most board entities are programs, so an organisation the wording rules
     * do not recognise is a facility. `weak` marks it as a bare default, and
     * the build reports the ones that behave like owners instead. */
    return { kind: 'facility', guessed: guessed, weak: true, reason: reason + ', no wording signal, defaulted' };
}

/* ------------------------------------------------------------------ *
 * Relationship normalisation
 * ------------------------------------------------------------------ */

const TYPOS = [
    [/execuitve/gi, 'executive'], [/psychiartirst/gi, 'psychiatrist'], [/presitdent/gi, 'president'],
    [/preseident/gi, 'president'], [/direcctor/gi, 'director'], [/direcrors/gi, 'directors'],
    [/clincical/gi, 'clinical'], [/sercvices/gi, 'services'], [/cofunder/gi, 'cofounder'],
    [/unliscenced/gi, 'unlicensed'], [/liscenced/gi, 'licensed'],
    [/\basst\./gi, 'assistant'], [/nat.l\b/gi, 'national'], [/\bcEO\b/g, 'CEO']
];

function cleanRole(role) {
    let out = ' ' + role.trim() + ' ';
    TYPOS.forEach(function (pair) { out = out.replace(pair[0], pair[1]); });
    return out.replace(/\s+/g, ' ').trim();
}

/** Split "cofounder/ / admissions director" into usable role strings. */
function splitRoles(raw) {
    return String(raw || '')
        .split('/')
        .map(cleanRole)
        .filter(function (r) { return r !== '' && r !== '?'; });
}

/* Within one role string the specific reading wins over the generic one, so
 * "clinical director" is clinical and only a bare "director" is leadership.
 * Stems are deliberately left open on the right: "therap" has to reach
 * "therapist" and "therapy". */
const ROLE_RULES = [
    ['corporate', /\b(rebrand|acquired|merger|shared campus|sister school|brother.sister|transferred clients|funded|precursor|property owner|website registrant|registered agent|transportation service provider)/i],
    ['family', /\b(married|divorced|spouse|son|daughter|father|mother|brother|sister|sibling|in-law)/i],
    ['survivor', /\bsurvivor/i],
    ['referral', /\b(referral|endorsed)/i],
    ['board', /\b(board|trustee|treasurer|secretary|chair|committee)/i],
    ['clinical', /\b(clinical|clinician|medical|psychiatr|psychiartirst|psycholog|therap|counsel|nurse|doctor|equine|pharmacy|health surveyor|direct support)/i],
    ['admissions', /\b(admission|marketing|enrollment|outreach|business development|recruit|customer relations|intake)/i],
    ['staff', /\b(field|guide|instructor|teacher|academic|education|training|mentor|coach|residential|house parent|dean|supervis|manager|intern(?!ational)|staff|employee|coordinator|operations|risk|human resources|deputy|officer|facilitator|workshop|consultant|research|science|aftercare|campus culture|admin|phase ii|team lead|team director|special projects|special services|photo displayed|prepared tax guides)/i],
    ['leadership', /\b(founder|cofounder|co-founder|ceo|coo|president|executive|director|owner|head of school|headmaster|principal|superintendent|pastor|leader|partner|commissioner|vice-president|management)/i],
    ['other', /\b(trained|member|advisor|advisory)/i]
];

/* Across the roles on one edge, the bigger fact wins. */
const CATEGORY_PRECEDENCE = [
    'corporate', 'family', 'survivor', 'referral', 'board',
    'leadership', 'clinical', 'admissions', 'staff', 'other', 'unknown'
];

function categoriseRole(role) {
    for (let i = 0; i < ROLE_RULES.length; i++) {
        if (ROLE_RULES[i][1].test(role)) return ROLE_RULES[i][0];
    }
    return null;
}

/**
 * One category per edge. A bare "member" only counts as a board tie when the
 * other end is an association, which is where the word actually means it.
 */
function categoriseEdge(raw, roles, sourceNode, targetNode, overrides) {
    if (Object.prototype.hasOwnProperty.call(overrides.relationships, raw)) {
        const o = overrides.relationships[raw];
        return { category: o.category, roles: o.roles || roles, matched: true };
    }
    const bothOrgs = sourceNode.kind !== 'person' && targetNode.kind !== 'person';

    if (roles.length === 0) {
        /* A plain line between two organisations is the board shorthand for
         * "part of the same group"; between people it says nothing. */
        if (bothOrgs) return { category: 'corporate', roles: ['affiliated'], matched: true };
        return { category: 'unknown', roles: [], matched: true };
    }

    const found = roles.map(categoriseRole).filter(Boolean);
    /* Some relationships only make sense unsplit ("brother/sister schools"), so
     * the whole string gets a vote too. */
    const wholeString = categoriseRole(raw);
    if (wholeString) found.push(wholeString);
    if (found.length === 0) return { category: 'unknown', roles: roles, matched: false };

    found.sort(function (a, b) {
        return CATEGORY_PRECEDENCE.indexOf(a) - CATEGORY_PRECEDENCE.indexOf(b);
    });
    let category = found[0];
    const joined = roles.join(' ');

    /* Ownership language between two organisations is a corporate fact. */
    if (bothOrgs && category === 'leadership' && /\bowner\b|\bco-owner\b/i.test(joined)) category = 'corporate';
    /* Membership of a trade association is a board-style affiliation. */
    if (category === 'other' && /\bmember\b/i.test(joined)
        && (sourceNode.kind === 'association' || targetNode.kind === 'association')) category = 'board';

    return { category: category, roles: roles, matched: true };
}

/* ------------------------------------------------------------------ *
 * Facility matching
 * ------------------------------------------------------------------ */

/* Same suffix stripping the program aggregator uses, so a board name like
 * "Cherokee Creek Boys School" can still reach "Cherokee Creek". */
const SUFFIXES = [
    'school', 'academy', 'ranch', 'center', 'centre', 'program', 'programs', 'institute',
    'home', 'facility', 'services', 'group', 'rtc', 'tbs', 'wilderness', 'therapeutic',
    'boarding school', 'treatment center', 'residential', 'treatment', 'behavioral',
    'health', 'campus', 'hospital', 'youth', 'boys', 'girls'
];

function keyVariants(name) {
    const base = nameKey(name);
    const out = new Set([base]);
    let trimmed = base;
    for (let pass = 0; pass < 3; pass++) {
        let changed = false;
        SUFFIXES.forEach(function (suffix) {
            if (trimmed.endsWith(' ' + suffix)) {
                trimmed = trimmed.slice(0, -(suffix.length + 1)).trim();
                changed = true;
            }
        });
        if (trimmed) out.add(trimmed);
        if (!changed) break;
    }
    return Array.from(out).filter(Boolean);
}

/* ------------------------------------------------------------------ *
 * Years, rebrands and deaths (the fix list of 2026-09-17, 2b.5 to 2b.7)
 * ------------------------------------------------------------------ */

/** "1971-2004", "from 1971", "until 2004", or '' from two years. */
function formatYears(start, end) {
    const a = Number(start) || 0;
    const b = Number(end) || 0;
    if (a && b) return a === b ? String(a) : a + '-' + b;
    if (a) return 'from ' + a;
    if (b) return 'until ' + b;
    return '';
}

/** Pull "1971" and "2004" out of free text such as "1971 - 2004" or "1998-present". */
function yearsFromText(text) {
    const m = /\b(1[89]\d\d|20\d\d)\s*(?:-|–|to)\s*(1[89]\d\d|20\d\d|present|current|now)\b/i.exec(String(text || ''));
    if (m) return formatYears(m[1], /^\d/.test(m[2]) ? m[2] : 0);
    const single = /\b(?:since|from|opened|founded|est\.?|established)\s+(1[89]\d\d|20\d\d)\b/i.exec(String(text || ''));
    return single ? formatYears(single[1], 0) : '';
}

/**
 * A facility's years: the columns first, then the record's own
 * operatingPeriod, then the free-text yearsOfOperation.
 */
function yearsFromFacility(row) {
    const fromColumns = formatYears(row.start_year, row.end_year);
    if (fromColumns) return fromColumns;
    let doc = null;
    try { doc = JSON.parse(row.json_data || 'null'); } catch (err) { doc = null; }
    const facility = doc && (doc.facility || doc);
    const period = facility && facility.operatingPeriod;
    if (!period || typeof period !== 'object') return '';
    return formatYears(period.startYear, period.endYear) || yearsFromText(period.yearsOfOperation);
}

/** Operator name key -> years, from wpdl_kop_operators, for company nodes. */
function loadOperatorYears() {
    const out = new Map();
    if (!fs.existsSync(SQLITE_FILE)) return out;
    try {
        const { DatabaseSync } = require('node:sqlite');
        const db = new DatabaseSync(SQLITE_FILE, { readOnly: true });
        const rows = db.prepare('SELECT name, json_data FROM wpdl_kop_operators').all();
        db.close();
        rows.forEach(function (row) {
            let doc = null;
            try { doc = JSON.parse(row.json_data || 'null'); } catch (err) { doc = null; }
            const op = doc && (doc.operator || doc);
            const period = op && op.operatingPeriod;
            const years = typeof period === 'string'
                ? yearsFromText(period)
                : (period && typeof period === 'object'
                    ? (formatYears(period.startYear, period.endYear) || yearsFromText(period.yearsOfOperation))
                    : '');
            if (years) out.set(nameKey(row.name), years);
        });
    } catch (err) {
        console.warn('  ! could not read operators: ' + err.message);
    }
    return out;
}

/** Published memorial rows as [{program, n}], one per program name. */
function loadMemorialPrograms() {
    if (!fs.existsSync(SQLITE_FILE)) return null;
    try {
        const { DatabaseSync } = require('node:sqlite');
        const db = new DatabaseSync(SQLITE_FILE, { readOnly: true });
        const rows = db.prepare(
            "SELECT program, COUNT(*) AS n FROM memorial_victims WHERE publication_status = 'published' GROUP BY program"
        ).all();
        db.close();
        return rows.filter(function (r) { return r.program; });
    } catch (err) {
        console.warn('  ! could not read memorial_victims: ' + err.message);
        return null;
    }
}

/**
 * Build name_key -> [{id, uniqueName, name}] from the local prod mirror, or
 * fall back to the committed program aggregate when the mirror is absent.
 * Returns null when neither source is available.
 */
function loadFacilityIndex() {
    if (fs.existsSync(SQLITE_FILE)) {
        try {
            const { DatabaseSync } = require('node:sqlite');
            const db = new DatabaseSync(SQLITE_FILE, { readOnly: true });
            const rows = db.prepare('SELECT id, unique_name, name, name_key, state, status, start_year, end_year, json_data FROM facilities_v2').all();
            db.close();
            const index = new Map();
            const loose = new Map();
            const byId = new Map();
            rows.forEach(function (row) {
                const record = {
                    id: row.id, uniqueName: row.unique_name, name: row.name,
                    state: row.state || '', status: row.status || '',
                    years: yearsFromFacility(row)
                };
                byId.set(row.id, record);
                /* the stored name_key normalises differently from ours, so
                 * register both or "SUWS of the Carolinas" misses itself */
                [row.name_key, nameKey(row.name)].forEach(function (key) {
                    if (!key) return;
                    if (!index.has(key)) index.set(key, []);
                    index.get(key).push(record);
                });
                keyVariants(row.name).forEach(function (variant) {
                    if (!loose.has(variant)) loose.set(variant, []);
                    loose.get(variant).push(record);
                });
            });
            return { source: 'facilities_v2', index: index, loose: loose, byId: byId, count: rows.length };
        } catch (err) {
            console.warn('  ! could not read ' + path.basename(SQLITE_FILE) + ': ' + err.message);
        }
    }
    if (fs.existsSync(PROGRAMS_FILE)) {
        const raw = JSON.parse(fs.readFileSync(PROGRAMS_FILE, 'utf8'));
        const list = Array.isArray(raw) ? raw : (raw.programs || Object.values(raw));
        const index = new Map();
        const loose = new Map();
        list.forEach(function (program, i) {
            const name = program.name || program.title || program.programName;
            if (!name) return;
            const record = { id: null, uniqueName: null, name: name, state: program.state || '', status: '', _i: i };
            const key = nameKey(name);
            if (!index.has(key)) index.set(key, []);
            index.get(key).push(record);
            keyVariants(name).forEach(function (variant) {
                if (!loose.has(variant)) loose.set(variant, []);
                loose.get(variant).push(record);
            });
        });
        return { source: 'reddit-wiki', index: index, loose: loose, count: list.length };
    }
    return null;
}

/**
 * Exact key first, then suffix-stripped on the board side, then suffix-stripped
 * on both sides. A key that reaches several records is left unmatched and
 * reported, because picking one silently would bind the map to the wrong
 * facility.
 */
function matchFacility(node, facilities, overrides) {
    if (Object.prototype.hasOwnProperty.call(overrides.facilities, node.name)) {
        const forced = overrides.facilities[node.name];
        if (forced === null) return { match: null, note: 'override: no match' };
        return { match: { id: forced, uniqueName: null, name: node.name }, note: 'override' };
    }
    if (!facilities) return { match: null, note: 'no facility source' };

    const names = [node.name].concat(node.aliases || []);
    const tiers = [
        { index: facilities.index, variants: names.map(nameKey), note: 'exact' },
        { index: facilities.index, variants: flatten(names.map(keyVariants)), note: 'board suffix stripped' }
    ];

    for (let t = 0; t < tiers.length; t++) {
        const tier = tiers[t];
        for (let i = 0; i < tier.variants.length; i++) {
            const key = tier.variants[i];
            /* a short stripped key such as "hyde" matches far too much */
            if (!key || key.length < 6) continue;
            const rows = dedupeRows(tier.index.get(key));
            if (!rows || rows.length === 0) continue;
            if (rows.length > 1) return { match: null, note: 'ambiguous', candidates: rows, key: key };
            return { match: rows[0], note: tier.note, key: key };
        }
    }
    /* Stripping suffixes on both sides pairs up genuinely different places
     * ("Gulf Coast Academy" with "Gulf Coast Treatment Center"), so its output
     * is only ever a suggestion for a human to accept. */
    const suggestions = [];
    flatten(names.map(keyVariants)).forEach(function (key) {
        if (!key || key.length < 6) return;
        (dedupeRows(facilities.loose.get(key)) || []).forEach(function (row) {
            if (suggestions.some(function (s) { return s.row.id === row.id; })) return;
            suggestions.push({ row: row, key: key });
        });
    });
    if (suggestions.length) return { match: null, note: 'suggestion', suggestions: suggestions };
    return { match: null, note: 'unmatched' };
}

function flatten(lists) {
    return lists.reduce(function (all, list) { return all.concat(list); }, []);
}

function dedupeRows(rows) {
    if (!rows) return rows;
    const seen = new Set();
    return rows.filter(function (row) {
        if (seen.has(row.id)) return false;
        seen.add(row.id);
        return true;
    });
}

/* ------------------------------------------------------------------ *
 * Edge direction
 * ------------------------------------------------------------------ */

const ACQUIRE_RE = /\bacquired\b/i;
const ACQUIRED_BY_RE = /\bacquired by\b/i;
const REBRAND_RE = /\b(rebrand|merger)\b/i;

/**
 * The CSV records takeovers both ways round ("UHS acquired X" and
 * "Keystone acquired by UHS") in the same from/to shape, so direction comes
 * from what the endpoints are, not from column order.
 */
function resolveDirection(edge, source, target, overrides) {
    const raw = edge.rawRelationship;
    if (Object.prototype.hasOwnProperty.call(overrides.acquirers, edge.id)) {
        const acquirerName = overrides.acquirers[edge.id];
        const flip = target.name === acquirerName;
        return { direction: 'acquirer', flip: flip, ambiguous: false };
    }
    if (REBRAND_RE.test(raw)) return { direction: 'renamed', flip: false, ambiguous: false };

    if (ACQUIRE_RE.test(raw)) {
        if (ACQUIRED_BY_RE.test(raw)) return { direction: 'acquirer', flip: true, ambiguous: false };
        const sourceParent = source.kind === 'parent';
        const targetParent = target.kind === 'parent';
        if (sourceParent && !targetParent) return { direction: 'acquirer', flip: false, ambiguous: false };
        if (targetParent && !sourceParent) return { direction: 'acquirer', flip: true, ambiguous: false };
        const flip = target.importance > source.importance;
        return { direction: 'acquirer', flip: flip, ambiguous: true };
    }
    /* People are always stored first on a person-to-organisation edge. */
    if (source.kind !== 'person' && target.kind === 'person') {
        return { direction: 'none', flip: true, ambiguous: false };
    }
    return { direction: 'none', flip: false, ambiguous: false };
}

/**
 * 2b.6. Years of operation where anything records them: an override, the
 * matched facility record, the operator record for a company, then the
 * board's own dates column. People are left alone; their board dates are
 * terms of office, not years of operation.
 */
function deriveYears(nodes, facilities, overrides) {
    const operators = loadOperatorYears();
    nodes.forEach(function (node) {
        if (node.kind === 'person') return;
        let years = '';
        if (Object.prototype.hasOwnProperty.call(overrides.years, node.name)) {
            years = String(overrides.years[node.name] || '');
        }
        if (!years && node.facilityId && facilities && facilities.byId) {
            const record = facilities.byId.get(node.facilityId);
            if (record && record.years) years = record.years;
        }
        if (!years) {
            const names = [node.name].concat(node.aliases || []);
            for (let i = 0; i < names.length && !years; i++) {
                years = operators.get(nameKey(names[i])) || '';
            }
        }
        if (!years && node.dates) years = yearsFromText(node.dates) || '';
        if (years) {
            node.years = years;
        } else if (node.kind === 'facility') {
            qa.noYears.push(node.name + ' [' + node.regions[0] + ']');
        }
    });
}

/**
 * 2b.5. The board has one status for "closed or rebranded". A rebrand edge
 * says which of its two ends is the old name, but the board does not draw
 * them consistently (Lifeline for Youth, still open, points at the closed
 * Life-Line Inc), so status decides first: the closed end of a rebrand is
 * the name that was dropped. Where both ends are closed the edge is read as
 * the board draws it, source became target, and the pair goes to the QA
 * report. Every other closed node is plain "closed". An override wins.
 */
function deriveRebrands(nodes, edges, nodeById, overrides) {
    const isClosed = function (node) { return /closed|rebrand/i.test(node.status || ''); };
    const rebranded = new Set();
    edges.forEach(function (edge) {
        if (edge.direction !== 'renamed') return;
        const source = nodeById.get(edge.source);
        const target = nodeById.get(edge.target);
        const a = isClosed(source);
        const b = isClosed(target);
        if (a && !b) rebranded.add(source.id);
        else if (b && !a) rebranded.add(target.id);
        else if (a && b) {
            rebranded.add(source.id);
            qa.rebrandGuesses.push(source.name + ' -> ' + target.name + ' (both closed; read as drawn)');
        }
    });
    nodes.forEach(function (node) {
        if (Object.prototype.hasOwnProperty.call(overrides.statuses, node.name)) {
            node.status = String(overrides.statuses[node.name]);
            return;
        }
        if (!isClosed(node)) return;
        node.status = rebranded.has(node.id) ? 'rebranded' : 'closed';
    });
}

/**
 * 2b.7. Deaths in the memorial, per node. memorial_victims names a program in
 * free text with no facility id, so each program is matched against every
 * node's name and aliases and the name of the facility record the node is
 * linked to. A program that names two nodes, or none, is reported instead of
 * guessed. An override sets the count outright.
 */
function deriveDeaths(nodes, facilities, overrides) {
    const programs = loadMemorialPrograms();
    if (!programs) return;
    const byKey = new Map();
    const add = function (key, node) {
        if (!key || key.length < 5) return;
        if (!byKey.has(key)) byKey.set(key, new Set());
        byKey.get(key).add(node);
    };
    nodes.forEach(function (node) {
        if (node.kind === 'person') return;
        [node.name].concat(node.aliases || []).forEach(function (name) { add(nameKey(name), node); });
        if (node.facilityId && facilities && facilities.byId) {
            const record = facilities.byId.get(node.facilityId);
            if (record) add(nameKey(record.name), node);
        }
    });
    const counts = new Map();
    programs.forEach(function (row) {
        const hits = byKey.get(nameKey(row.program));
        if (!hits || hits.size === 0) {
            qa.unmatchedDeaths.push(row.program + ' (' + row.n + ')');
            return;
        }
        if (hits.size > 1) {
            qa.unmatchedDeaths.push(row.program + ' (' + row.n + ', names ' +
                Array.from(hits).map(function (n) { return n.name; }).join(' / ') + ')');
            return;
        }
        const node = Array.from(hits)[0];
        counts.set(node.id, (counts.get(node.id) || 0) + Number(row.n || 0));
    });
    nodes.forEach(function (node) {
        let n = counts.get(node.id) || 0;
        if (Object.prototype.hasOwnProperty.call(overrides.deaths, node.name)) {
            n = Number(overrides.deaths[node.name]) || 0;
        }
        if (n > 0) node.deaths = n;
    });
}

/**
 * 2b.12. Connections the facility and operator profiles record that the
 * board does not: who owns or operates a facility, the company behind an
 * operator, the people who ran it, the consultants who referred to it.
 * Read in two steps. readProfileClaims() collects every name a profile puts
 * against a node, so addPeople() can see who the profiles name before any
 * edge is drawn; addProfileEdges() then draws a claim where both ends are
 * nodes and the map has no line between the pair. Each carries provenance
 * "profile", so the map can say where it came from.
 *
 * A facility's other and past names are deliberately not turned into edges.
 * A name that resolves to another node would assert a rebrand, and some of
 * those are sister programmes rather than one place renamed; they are listed
 * in the QA report for a person to decide.
 */
function profileResolver(nodes, overrides) {
    /* Keys under five letters are too easily somebody else's initials,
     * except an alias written into the overrides on purpose ("CEDU"). */
    const chosen = new Set();
    Object.keys(overrides.aliases || {}).forEach(function (name) {
        (overrides.aliases[name] || []).forEach(function (alias) { chosen.add(nameKey(alias)); });
    });
    const byKey = new Map();
    nodes.forEach(function (node) {
        [node.name].concat(node.aliases || []).forEach(function (name) {
            const key = nameKey(name);
            if (!key || (key.length < 5 && !chosen.has(key))) return;
            if (!byKey.has(key)) byKey.set(key, new Set());
            byKey.get(key).add(node);
        });
    });
    /* One node or nothing: a name two nodes share is not guessed at. */
    return function (name) {
        const hits = byKey.get(nameKey(name));
        return hits && hits.size === 1 ? Array.from(hits)[0] : null;
    };
}

/* Names out of a profile field: plain strings, or {name, role} rows. */
function profileTexts(value) {
    return (Array.isArray(value) ? value : (value ? [value] : [])).map(function (item) {
        if (typeof item === 'string') return item;
        return (item && (item.name || item.value || item.label)) || '';
    }).map(function (s) { return String(s).trim(); }).filter(Boolean);
}

function readProfileClaims(nodes, overrides) {
    const claims = [];
    if (!fs.existsSync(SQLITE_FILE)) return claims;
    let db;
    try {
        const { DatabaseSync } = require('node:sqlite');
        db = new DatabaseSync(SQLITE_FILE, { readOnly: true });
    } catch (err) {
        console.warn('  ! could not open the mirror for profile edges: ' + err.message);
        return claims;
    }
    const resolve = profileResolver(nodes, overrides);
    const byFacility = new Map();
    nodes.forEach(function (node) { if (node.facilityId) byFacility.set(node.facilityId, node); });
    /* person: the name is someone who worked there, so addPeople may make a
     * node for them. */
    const claim = function (name, node, category, role, source, person) {
        if (!name || !node) return;
        claims.push({ name: name, node: node, category: category, role: role, source: source, person: !!person });
    };

    try {
        db.prepare('SELECT id, json_data FROM facilities_v2').all().forEach(function (row) {
            const node = byFacility.get(row.id);
            if (!node) return;
            let doc = null;
            try { doc = JSON.parse(row.json_data || 'null'); } catch (err) { return; }
            const facility = (doc && (doc.facility || doc)) || {};
            const ident = facility.identification || {};
            const staff = facility.staff || {};
            profileTexts(ident.currentOwners).forEach(function (name) { claim(name, node, 'corporate', 'owner', 'facility profile'); });
            profileTexts(ident.pastOperators).forEach(function (name) { claim(name, node, 'corporate', 'past operator', 'facility profile'); });
            profileTexts(ident.otherOperators).forEach(function (name) { claim(name, node, 'corporate', 'operator', 'facility profile'); });
            profileTexts(ident.investors).forEach(function (name) { claim(name, node, 'corporate', 'investor', 'facility profile'); });
            profileTexts(ident.knownReferrers).forEach(function (name) { claim(name, node, 'referral', 'referrer', 'facility profile'); });
            profileTexts(staff.administrator).forEach(function (name) { claim(name, node, 'leadership', 'administrator', 'facility profile', true); });
            profileTexts(staff.notableStaff).forEach(function (name) { claim(name, node, 'staff', 'staff', 'facility profile', true); });
            profileTexts(ident.pastNames).concat(profileTexts(ident.otherNames)).forEach(function (name) {
                const other = resolve(name);
                if (other && other.id !== node.id) {
                    qa.profileNames.push(node.name + ' lists "' + name + '", which is the board node ' + other.name);
                }
            });
        });

        const operators = new Map(db.prepare('SELECT id, name, json_data FROM wpdl_kop_operators').all()
            .map(function (r) { return [r.id, r]; }));
        db.prepare('SELECT operator_id, facility_id FROM wpdl_kop_operator_facilities').all().forEach(function (r) {
            const op = operators.get(r.operator_id);
            claim(op && op.name, byFacility.get(r.facility_id), 'corporate', 'operator', 'operator profile');
        });
        operators.forEach(function (op) {
            let doc = null;
            try { doc = JSON.parse(op.json_data || 'null'); } catch (err) { return; }
            const record = (doc && (doc.operator || doc)) || {};
            const keyStaff = record.keyStaff || {};
            const opNode = resolve(op.name);
            profileTexts(record.parentCompanies).forEach(function (name) { claim(name, opNode, 'corporate', 'parent company', 'operator profile'); });
            /* Founders sit under keyStaff on the operator record; older
             * records carried them at the top level. */
            profileTexts(record.founders).concat(profileTexts(keyStaff.founders)).forEach(function (name) {
                claim(name, opNode, 'leadership', 'founder', 'operator profile', true);
            });
            profileTexts(keyStaff.ceo).forEach(function (name) { claim(name, opNode, 'leadership', 'CEO', 'operator profile', true); });
            (Array.isArray(keyStaff.keyExecutives) ? keyStaff.keyExecutives : []).forEach(function (row) {
                const name = String((row && row.name) || '').trim();
                claim(name, opNode, 'leadership', String((row && row.role) || '').trim() || 'executive', 'operator profile', true);
            });
        });
    } catch (err) {
        console.warn('  ! could not read profile relationships: ' + err.message);
    }
    db.close();
    return claims;
}

function addProfileEdges(nodes, edges, claims, overrides) {
    const resolve = profileResolver(nodes, overrides);
    const pairs = new Set(edges.map(function (e) { return [e.source, e.target].sort().join('|'); }));

    let n = 0;
    claims.forEach(function (c) {
        const from = resolve(c.name);
        const to = c.node;
        if (!from || !to || from.id === to.id) return;
        const key = [from.id, to.id].sort().join('|');
        if (pairs.has(key)) return;
        pairs.add(key);
        n++;
        /* People first on a person-to-organisation edge, as the board does. */
        const flip = from.kind !== 'person' && to.kind === 'person';
        const a = flip ? to : from;
        const b = flip ? from : to;
        edges.push({
            id: 'p' + String(n).padStart(4, '0'),
            source: a.id,
            target: b.id,
            category: c.category,
            roles: [c.role],
            raw: c.role + ' (' + c.source + ')',
            direction: 'none',
            crossesChain: false,
            crossesRegion: a.regions[0] !== b.regions[0],
            provenance: 'profile'
        });
        qa.profileEdges.push(a.name + ' -> ' + b.name + ': ' + c.role + ' (' + c.source + ')');
    });
    return n;
}

/**
 * People the board does not have, added as nodes (2026-09-18). The board
 * was the roster, which left out people every other source names - the
 * founders of CEDU among them. A person becomes a node when the staff list,
 * staff-movement.csv or a profile ties them to two or more nodes, or names
 * them founder, owner, CEO or president of one: those are the people a
 * connection runs through. Someone named at a single place in some other
 * role stays off, which keeps one facility's staff roster from swamping the
 * map. The edges themselves are drawn afterwards by the profile, staff-move
 * and staff-list steps, exactly as for a person the board already had.
 * Each added node carries addedFrom, which the QA report lists.
 */
const PERSON_NOT = /\b(academy|school|inc|llc|group|services|center|centre|program|programs|ranch|foundation|church|hospital|association|council|healthcare|company|institute|home|homes|committee|board|staff|unknown|various|several)\b/i;
const LEADS = /\b(founder|cofounder|co-founder|owner|co-owner|ceo|president)\b/i;

function cleanPersonName(raw) {
    const name = String(raw || '')
        .replace(/[“”"][^“”"]*[“”"]/g, ' ')
        .replace(/\([^)]*\)|\[[^\]]*\]/g, ' ')
        .split(/,| - | – /)[0]
        .replace(/\s+/g, ' ')
        .trim();
    const words = name.split(' ');
    if (words.length < 2 || words.length > 5 || name.length > 40) return '';
    if (/\d|@|\//.test(name) || PERSON_NOT.test(name)) return '';
    return name;
}

/* The board writes some people with a nickname or a middle name
 * (Glenda "Glen" Roach, Sarah Persha Koalkin); every other source leaves
 * them out. Each board person is also known without them, unless that
 * shorter name is already somebody else's. */
function addPersonShortNames(nodes) {
    const taken = new Map();
    nodes.forEach(function (n) {
        [n.name].concat(n.aliases || []).forEach(function (name) {
            const k = nameKey(name);
            taken.set(k, (taken.get(k) || 0) + 1);
        });
    });
    nodes.forEach(function (n) {
        if (n.kind !== 'person') return;
        const plain = n.name.replace(/[\u201c\u201d"][^\u201c\u201d"]*[\u201c\u201d"]/g, ' ')
            .replace(/\([^)]*\)/g, ' ').replace(/\s+/g, ' ').trim();
        const words = plain.split(' ');
        const short = [plain];
        if (words.length > 2 && !/^(jr|sr|ii|iii)\.?$/i.test(words[words.length - 1])) {
            short.push(words[0] + ' ' + words[words.length - 1]);
        }
        short.forEach(function (name) {
            const k = nameKey(name);
            if (!k || taken.has(k) || k.split(' ').length < 2) return;
            taken.set(k, 1);
            n.aliases.push(name);
        });
    });
}

function addPeople(nodes, nodeById, claims, overrides) {
    addPersonShortNames(nodes);
    const resolve = profileResolver(nodes, overrides);
    const people = new Map();
    const note = function (rawName, place, role, from) {
        if (!place || place.kind === 'person') return;
        const name = cleanPersonName(rawName);
        const key = nameKey(name);
        if (!key || resolve(name)) return;
        if (!people.has(key)) people.set(key, { name: name, places: new Map(), leads: false, from: new Set() });
        const p = people.get(key);
        p.places.set(place.id, place);
        if (LEADS.test(role || '')) p.leads = true;
        p.from.add(from);
    };

    claims.forEach(function (c) { if (c.person) note(c.name, c.node, c.role, 'profiles'); });
    if (fs.existsSync(STAFF_MOVEMENT_CSV)) {
        /* A move is only drawn when both ends are nodes, so only then does
         * it count towards a person's places. */
        parseCsv(fs.readFileSync(STAFF_MOVEMENT_CSV, 'utf8')).forEach(function (row) {
            const from = resolve(row.from), to = resolve(row.to);
            if (!from || !to) return;
            note(row.person, from, row.role, 'staff moves');
            note(row.person, to, row.role, 'staff moves');
        });
    }
    if (fs.existsSync(STAFF_LIST_CSV)) {
        parseCsv(fs.readFileSync(STAFF_LIST_CSV, 'utf8')).forEach(function (row) {
            note(row.person, resolve(row.place), row.role, 'staff list');
        });
    }

    const ids = new Set(nodes.map(function (n) { return n.id; }));
    /* Same surname and first initial as a board person: probably the same
     * person spelled differently, so the QA report asks. */
    const lookalike = new Map();
    const shape = function (name) {
        const parts = nameKey(name).split(' ');
        return parts[parts.length - 1] + ' ' + parts[0].charAt(0);
    };
    nodes.forEach(function (n) { if (n.kind === 'person') lookalike.set(shape(n.name), n.name); });

    let added = 0;
    Array.from(people.values()).sort(function (a, b) { return a.name.localeCompare(b.name); }).forEach(function (p) {
        const places = Array.from(p.places.values());
        if (places.length < 2 && !p.leads) return;
        let id = slugify(p.name), n = 2;
        while (ids.has(id)) { id = slugify(p.name) + '-' + n; n++; }
        ids.add(id);
        const node = {
            id: id,
            name: p.name,
            aliases: [],
            kind: 'person',
            status: '',
            chain: '',
            regions: [places[0].regions[0]],
            natsap: false,
            importance: 0,
            degree: 0,
            degreeByCategory: {},
            facilityId: null,
            uniqueName: null,
            dates: '',
            isolated: false,
            /* Beside the places they worked: the mean of their positions. */
            board: {
                x: places.reduce(function (s, pl) { return s + pl.board.x; }, 0) / places.length,
                y: places.reduce(function (s, pl) { return s + pl.board.y; }, 0) / places.length
            },
            addedFrom: Array.from(p.from).sort()
        };
        nodes.push(node);
        nodeById.set(id, node);
        added++;
        const near = lookalike.get(shape(p.name));
        qa.addedPeople.push(p.name + ' (' + places.map(function (pl) { return pl.name; }).join(', ') + '; from ' +
            node.addedFrom.join(', ') + ')' + (near ? ' - check: the board has ' + near : ''));
    });
    return added;
}

/**
 * 2b.11. Staff movement, from the reviewed js/data/network/staff-movement.csv
 * (person, from, to, role, year, source). The fact worth having is that
 * somebody worked at both places. Where the person is a board node, they
 * are connected to each place; where they are not - most are not, and the
 * board stays the roster - the two places are connected by a staff edge
 * that names them, which is what puts a second programme on screen beside
 * the first. A place that does not resolve to exactly one node is reported
 * and the row skipped. Edges carry provenance "staff-movement".
 */
function addStaffMovement(nodes, edges) {
    if (!fs.existsSync(STAFF_MOVEMENT_CSV)) return 0;
    const rows = parseCsv(fs.readFileSync(STAFF_MOVEMENT_CSV, 'utf8'));
    const byKey = new Map();
    nodes.forEach(function (node) {
        [node.name].concat(node.aliases || []).forEach(function (name) {
            const key = nameKey(name);
            if (!key) return;
            if (!byKey.has(key)) byKey.set(key, new Set());
            byKey.get(key).add(node);
        });
    });
    const resolve = function (name) {
        const hits = byKey.get(nameKey(name));
        return hits && hits.size === 1 ? Array.from(hits)[0] : null;
    };
    const pairs = new Map();
    edges.forEach(function (e) { pairs.set([e.source, e.target].sort().join('|'), e); });

    let n = 0;
    const push = function (a, b, role, raw) {
        const key = [a.id, b.id].sort().join('|');
        const existing = pairs.get(key);
        if (existing) {
            /* Already connected: note the move on the line that is there
             * rather than drawing a second one. */
            if (existing.provenance === 'staff-movement' && existing.raw.indexOf(raw) === -1) {
                existing.raw += '; ' + raw;
            }
            return;
        }
        n++;
        const edge = {
            id: 's' + String(n).padStart(4, '0'),
            source: a.id,
            target: b.id,
            category: 'staff',
            roles: [role],
            raw: raw,
            direction: 'none',
            crossesChain: false,
            crossesRegion: a.regions[0] !== b.regions[0],
            provenance: 'staff-movement'
        };
        edges.push(edge);
        pairs.set(key, edge);
    };

    rows.forEach(function (row) {
        const person = String(row.person || '').trim();
        const from = resolve(row.from);
        const to = resolve(row.to);
        if (!person || !from || !to) {
            qa.staffUnresolved.push(person + ': ' + row.from + (from ? '' : ' (not on the board)') +
                ' -> ' + row.to + (to ? '' : ' (not on the board)'));
            return;
        }
        if (from.id === to.id) return;
        const when = row.year ? ' in ' + row.year : '';
        const who = person + (row.role ? ' (' + row.role + ')' : '');
        const personNode = resolve(person);
        if (personNode && personNode.kind === 'person') {
            push(personNode, from, 'staff', who + ' worked at ' + from.name);
            push(personNode, to, 'staff', who + ' moved to ' + to.name + when);
        } else {
            push(from, to, 'staff moved', who + ' moved from ' + from.name + ' to ' + to.name + when);
        }
        qa.staffMoves.push(who + ': ' + from.name + ' -> ' + to.name + when);
    });
    return n;
}

/**
 * The owner's staff list, a backup source read after staff-movement.csv:
 * js/data/network/staff-list.csv (person, place, role, source), drafted by
 * scripts/parse-staff-list.js from staff-list.txt. It says where someone
 * worked, not in what order, so nothing here claims a move. A person who is
 * a board node is connected to each place. Otherwise the person's first
 * place in the list (usually the programme the list was compiled under) is
 * connected to each of the others by a staff edge that names them. Only
 * pairs the map has no line between are drawn; everything else is counted.
 * Survivor and family ties never join two places. Edges carry provenance
 * "staff-list".
 */
/* Staff-list pairs the map already had a line for (a count, not a QA list). */
let staffListAlreadyShown = 0;

function addStaffList(nodes, edges) {
    if (!fs.existsSync(STAFF_LIST_CSV)) return 0;
    const rows = parseCsv(fs.readFileSync(STAFF_LIST_CSV, 'utf8'));
    const byKey = new Map();
    nodes.forEach(function (node) {
        [node.name].concat(node.aliases || []).forEach(function (name) {
            const key = nameKey(name);
            if (!key) return;
            if (!byKey.has(key)) byKey.set(key, new Set());
            byKey.get(key).add(node);
        });
    });
    const resolve = function (name) {
        const hits = byKey.get(nameKey(name));
        return hits && hits.size === 1 ? Array.from(hits)[0] : null;
    };
    const pairs = new Map();
    edges.forEach(function (e) { pairs.set([e.source, e.target].sort().join('|'), e); });

    let n = 0;
    const push = function (a, b, category, role, raw) {
        const key = [a.id, b.id].sort().join('|');
        const existing = pairs.get(key);
        if (existing) {
            if (existing.provenance === 'staff-list' && existing.raw.indexOf(raw) === -1) {
                existing.raw += '; ' + raw;
            } else {
                staffListAlreadyShown++;
            }
            return;
        }
        n++;
        const edge = {
            id: 'l' + String(n).padStart(4, '0'),
            source: a.id,
            target: b.id,
            category: category,
            roles: role ? [role] : [],
            raw: raw,
            direction: 'none',
            crossesChain: false,
            crossesRegion: a.regions[0] !== b.regions[0],
            provenance: 'staff-list'
        };
        edges.push(edge);
        pairs.set(key, edge);
        qa.staffListEdges.push(raw);
    };

    const people = new Map();
    rows.forEach(function (row) {
        const person = String(row.person || '').trim();
        if (!person) return;
        if (!people.has(person)) people.set(person, []);
        people.get(person).push(row);
    });

    people.forEach(function (list, person) {
        const personNode = resolve(person);
        const places = [];
        list.forEach(function (row) {
            const place = resolve(row.place);
            if (!place) {
                qa.staffListUnresolved.push(person + ': ' + row.place + (row.role ? ' (' + row.role + ')' : ''));
                return;
            }
            if (personNode && place.id === personNode.id) return;
            if (places.some(function (p) { return p.node.id === place.id; })) return;
            places.push({ node: place, role: String(row.role || '').trim() });
        });

        if (personNode) {
            places.forEach(function (p) {
                const category = categoriseRole(p.role) || (p.role ? 'staff' : 'unknown');
                const what = p.role ? ' (' + p.role + ')' : '';
                /* People first on person-to-organisation edges. */
                const a = personNode.kind === 'person' ? personNode : p.node;
                const b = a === personNode ? p.node : personNode;
                push(a, b, personNode.kind === 'person' ? category : 'corporate', p.role,
                    person + what + ' at ' + p.node.name + ' (staff list)');
            });
            return;
        }
        const worked = places.filter(function (p) {
            const c = categoriseRole(p.role);
            return c !== 'survivor' && c !== 'family';
        });
        if (worked.length < 2) return;
        const hub = worked[0];
        const at = function (p) { return p.node.name + (p.role ? ' (' + p.role + ')' : ''); };
        worked.slice(1).forEach(function (p) {
            push(hub.node, p.node, 'staff', 'worked at both',
                person + ' worked at both ' + at(hub) + ' and ' + at(p) + ' (staff list)');
        });
    });
    return n;
}

/* ------------------------------------------------------------------ *
 * Layout
 * ------------------------------------------------------------------ */

/** Rescale the Miro coordinates into a centred 2000 x 2000 box, aspect kept. */
function rescaleBoard(nodes) {
    const xs = nodes.map(function (n) { return n.board.x; });
    const ys = nodes.map(function (n) { return n.board.y; });
    const minX = Math.min.apply(null, xs), maxX = Math.max.apply(null, xs);
    const minY = Math.min.apply(null, ys), maxY = Math.max.apply(null, ys);
    const span = Math.max(maxX - minX, maxY - minY) || 1;
    const scale = 2000 / span;
    const cx = (minX + maxX) / 2, cy = (minY + maxY) / 2;
    nodes.forEach(function (n) {
        n.board.x = Math.round((n.board.x - cx) * scale * 100) / 100;
        n.board.y = Math.round((n.board.y - cy) * scale * 100) / 100;
    });
}

/* ------------------------------------------------------------------ *
 * Build
 * ------------------------------------------------------------------ */

function build() {
    const overrides = loadOverrides();
    const nodeRows = parseCsv(fs.readFileSync(NODES_CSV, 'utf8'));
    const edgeRows = parseCsv(fs.readFileSync(EDGES_CSV, 'utf8'));
    const sourceHash = crypto.createHash('sha1')
        .update(fs.readFileSync(NODES_CSV)).update(fs.readFileSync(EDGES_CSV))
        .digest('hex').slice(0, 12);

    console.log('Read ' + nodeRows.length + ' node rows, ' + edgeRows.length + ' edge rows.');

    /* --- 1. drop legend frames ----------------------------------- */
    const kept = [];
    nodeRows.forEach(function (row) {
        if (DROP_REGIONS.indexOf(row.network) !== -1) {
            qa.droppedRows.push(row.name + ' (' + row.network + ' frame)');
            return;
        }
        kept.push(row);
    });

    /* --- 2. merge duplicates ------------------------------------- */
    /* One person drawn in two frames is one person. Two different orgs that
     * happen to share a name are not, so only listed groups are merged. */
    const mergeTarget = new Map(); /* "name|network" -> canonical name */
    overrides.merges.forEach(function (group) {
        const canonical = group.canonical;
        (group.rows || []).forEach(function (row) {
            mergeTarget.set(row.name + '|' + row.network, canonical);
        });
        qa.mergedNodes.push(canonical + ' <- ' + (group.rows || []).map(function (r) {
            return r.name + ' (' + r.network + ')';
        }).join(', '));
    });

    const byCanonical = new Map();
    const rowKeyToCanonical = new Map();
    kept.forEach(function (row) {
        const rowKey = row.name + '|' + row.network;
        const canonicalName = mergeTarget.get(rowKey) || row.name;
        if (!byCanonical.has(canonicalName)) byCanonical.set(canonicalName, []);
        byCanonical.get(canonicalName).push(row);
        rowKeyToCanonical.set(rowKey, canonicalName);
    });

    /* --- 3. build nodes ------------------------------------------ */
    /* Two different organisations share the name "Gateway Academy", so a name
     * used by more than one unmerged entity keeps its frame in the id. */
    const nameCounts = new Map();
    byCanonical.forEach(function (rows, canonicalName) {
        nameCounts.set(canonicalName, rows.length);
    });

    const nodes = [];
    const nodeByRowKey = new Map();
    byCanonical.forEach(function (rows, canonicalName) {
        const distinct = rows.length > 1 && !rows.every(function (r) {
            return mergeTarget.has(r.name + '|' + r.network);
        });
        rows.forEach(function (row) {
            const rowKey = row.name + '|' + row.network;
            const merged = mergeTarget.has(rowKey);
            if (merged) {
                /* fold into the first node built for this canonical name */
                const existing = nodes.find(function (n) { return n.name === canonicalName; });
                if (existing) {
                    if (existing.regions.indexOf(row.network) === -1) existing.regions.push(row.network);
                    if (row.name !== canonicalName && existing.aliases.indexOf(row.name) === -1) {
                        existing.aliases.push(row.name);
                    }
                    existing.importance = Math.max(existing.importance, Number(row.importance) || 0);
                    nodeByRowKey.set(rowKey, existing);
                    return;
                }
            }
            const id = distinct ? slugify(canonicalName) + '-' + slugify(row.network) : slugify(canonicalName);
            const node = {
                id: id,
                name: canonicalName,
                aliases: (overrides.aliases[canonicalName] || []).slice(),
                kind: null,
                status: row.status === 'unmarked' ? '' : row.status,
                rawStatus: row.status,
                chain: row.chain || '',
                regions: [row.network],
                natsap: truthy(row.natsap_member),
                importance: Number(row.importance) || 0,
                degree: 0,
                degreeByCategory: {},
                facilityId: null,
                uniqueName: null,
                dates: row.dates || '',
                isolated: row.network === ISOLATED_REGION,
                board: { x: Number(row.board_x) || 0, y: Number(row.board_y) || 0 }
            };
            nodes.push(node);
            nodeByRowKey.set(rowKey, node);
        });
    });

    /* ids must be unique even after the frame suffix */
    const seenIds = new Map();
    nodes.forEach(function (node) {
        let id = node.id, n = 2;
        while (seenIds.has(id)) { id = node.id + '-' + n; n++; }
        seenIds.set(id, node);
        node.id = id;
    });

    /* --- 4. classify kinds --------------------------------------- */
    nodes.forEach(function (node) {
        const verdict = classifyKind(node, overrides);
        node.kind = verdict.kind;
        node.kindWeak = Boolean(verdict.weak);
        if (verdict.guessed) {
            qa.kindGuesses.push({ name: node.name, kind: verdict.kind, reason: verdict.reason, region: node.regions[0] });
        }
    });

    /* --- 5. build edges ------------------------------------------ */
    const edges = [];
    const seenEdgeKeys = new Set();
    let unresolved = 0;
    edgeRows.forEach(function (row, i) {
        const fromKey = row.from + '|' + row.from_network;
        const toKey = row.to + '|' + row.to_network;
        const source = nodeByRowKey.get(fromKey);
        const target = nodeByRowKey.get(toKey);
        if (!source || !target) {
            /* legend rows were dropped; anything else is a real problem */
            if (DROP_REGIONS.indexOf(row.from_network) === -1 && DROP_REGIONS.indexOf(row.to_network) === -1) {
                unresolved++;
                console.error('  ! unresolved endpoint: ' + row.from + ' -> ' + row.to);
            }
            return;
        }
        if (source === target) return; /* self-loops carry no information here */

        const raw = row.relationship || '';
        const dedupeKey = [source.id, target.id].sort().join('|') + '|' + raw;
        if (seenEdgeKeys.has(dedupeKey)) {
            qa.duplicateEdges.push(source.name + ' -> ' + target.name + ' (' + (raw || 'unlabelled') + ')');
            return;
        }
        seenEdgeKeys.add(dedupeKey);

        const id = 'e' + String(i + 1).padStart(4, '0');
        const roles = splitRoles(raw);
        const verdict = categoriseEdge(raw, roles, source, target, overrides);
        if (!verdict.matched) {
            qa.weakRelationships.push({ raw: raw, edge: source.name + ' -> ' + target.name });
        }
        const dir = resolveDirection({ id: id, rawRelationship: raw }, source, target, overrides);
        if (dir.ambiguous) {
            qa.ambiguousAcquirers.push({
                id: id, from: source.name, to: target.name, raw: raw,
                chose: dir.flip ? target.name : source.name
            });
        }
        const a = dir.flip ? target : source;
        const b = dir.flip ? source : target;
        if (dir.direction === 'renamed') qa.rebrands.push(a.name + ' -> ' + b.name + ' (' + raw + ')');

        edges.push({
            id: id,
            source: a.id,
            target: b.id,
            category: verdict.category,
            roles: verdict.roles,
            raw: raw,
            direction: dir.direction,
            crossesChain: false,
            crossesRegion: a.regions[0] !== b.regions[0]
        });
    });
    if (unresolved > 0) throw new Error(unresolved + ' edge endpoints did not resolve to a node.');

    /* --- 6. parent upgrade, then chain resolution ----------------- */
    /* An organisation that owns three or more others is a parent company even
     * when its name gives nothing away, which is how Family Help & Wellness
     * and similar holding groups get classified. */
    const nodeById = new Map(nodes.map(function (n) { return [n.id, n]; }));
    const corporateOut = new Map();
    edges.forEach(function (edge) {
        if (edge.category !== 'corporate') return;
        const source = nodeById.get(edge.source), target = nodeById.get(edge.target);
        if (source.kind === 'person' || target.kind === 'person') return;
        corporateOut.set(edge.source, (corporateOut.get(edge.source) || 0) + 1);
    });
    nodes.forEach(function (node) {
        if (node.kind !== 'facility' && node.kind !== 'other') return;
        if (overrides.kinds[node.name]) return;
        const owns = corporateOut.get(node.id) || 0;
        if (node.kindWeak && owns === 2) {
            qa.weakKinds.push({ name: node.name, region: node.regions[0], owns: owns });
        }
        if (owns >= 3) {
            node.kind = 'parent';
            qa.kindGuesses.push({
                name: node.name, kind: 'parent', region: node.regions[0],
                reason: 'owns ' + owns + ' organisations by corporate edge'
            });
        }
    });

    /* A node with no chain of its own inherits the chain of the parent that
     * owns it. One hop only, so the result stays explainable. */
    nodes.forEach(function (node) {
        if (node.chain || node.kind === 'person') return;
        const owner = edges.find(function (edge) {
            if (edge.category !== 'corporate' || edge.target !== node.id) return false;
            const source = nodeById.get(edge.source);
            return source.kind === 'parent' && source.chain;
        });
        if (owner) {
            node.chain = nodeById.get(owner.source).chain;
            qa.chainInferred.push(node.name + ' -> ' + node.chain + ' (via ' + nodeById.get(owner.source).name + ')');
        }
    });

    edges.forEach(function (edge) {
        const source = nodeById.get(edge.source), target = nodeById.get(edge.target);
        edge.crossesChain = Boolean(source.chain && target.chain && source.chain !== target.chain);
        source.degree++; target.degree++;
        source.degreeByCategory[edge.category] = (source.degreeByCategory[edge.category] || 0) + 1;
        target.degreeByCategory[edge.category] = (target.degreeByCategory[edge.category] || 0) + 1;
    });
    nodes.forEach(function (node) {
        if (node.degree === 0) qa.isolatedNodes.push(node.name + ' (' + node.regions.join(', ') + ')');
    });

    /* --- 7. facility matching ------------------------------------ */
    const facilities = loadFacilityIndex();
    console.log(facilities
        ? 'Facility source: ' + facilities.source + ' (' + facilities.count + ' records).'
        : 'Facility source: none available, links will be empty.');

    let matched = 0;
    const matchNotes = {};
    nodes.forEach(function (node) {
        if (node.kind === 'person') return;
        const result = matchFacility(node, facilities, overrides);
        if (result.match) {
            node.facilityId = result.match.id;
            node.uniqueName = result.match.uniqueName;
            matched++;
            matchNotes[result.note] = (matchNotes[result.note] || 0) + 1;
        } else if (result.note === 'ambiguous') {
            qa.multiMatchFacilities.push({
                name: node.name, key: result.key,
                candidates: result.candidates.map(function (c) {
                    return c.name + ' [' + c.id + (c.state ? ', ' + c.state : '') + ']';
                })
            });
        } else if (result.note === 'suggestion') {
            qa.looseMatches.push({
                name: node.name, kind: node.kind, region: node.regions[0],
                candidates: result.suggestions.map(function (s) {
                    return s.row.name + ' [' + s.row.id + (s.row.state ? ', ' + s.row.state : '') + ']';
                })
            });
        } else if (result.note === 'unmatched') {
            qa.unmatchedFacilities.push({ name: node.name, kind: node.kind, region: node.regions[0] });
        }
    });

    /* --- 7b. years, rebrands, deaths ------------------------------ */
    deriveYears(nodes, facilities, overrides);
    deriveRebrands(nodes, edges, nodeById, overrides);
    deriveDeaths(nodes, facilities, overrides);

    /* --- 7c. connections the facility profiles record ------------- */
    const claims = readProfileClaims(nodes, overrides);
    const people = addPeople(nodes, nodeById, claims, overrides);
    const added = addProfileEdges(nodes, edges, claims, overrides) + addStaffMovement(nodes, edges) +
        addStaffList(nodes, edges);
    if (added || people) {
        /* The profile edges change who connects to whom, so the counts are
         * taken again from scratch rather than patched. */
        nodes.forEach(function (node) { node.degree = 0; node.degreeByCategory = {}; });
        edges.forEach(function (edge) {
            const source = nodeById.get(edge.source), target = nodeById.get(edge.target);
            edge.crossesChain = Boolean(source.chain && target.chain && source.chain !== target.chain);
            source.degree++; target.degree++;
            source.degreeByCategory[edge.category] = (source.degreeByCategory[edge.category] || 0) + 1;
            target.degreeByCategory[edge.category] = (target.degreeByCategory[edge.category] || 0) + 1;
        });
    }

    /* --- 8. layout and output ------------------------------------ */
    rescaleBoard(nodes);
    nodes.forEach(function (node) { delete node.rawStatus; delete node.kindWeak; });
    nodes.sort(function (a, b) { return b.degree - a.degree || a.name.localeCompare(b.name); });

    const counts = {
        nodes: nodes.length, edges: edges.length, facilityMatches: matched,
        withYears: nodes.filter(function (n) { return n.years; }).length,
        withDeaths: nodes.filter(function (n) { return n.deaths; }).length,
        rebranded: nodes.filter(function (n) { return n.status === 'rebranded'; }).length,
        profileEdges: edges.filter(function (e) { return e.provenance === 'profile'; }).length,
        staffMovementEdges: edges.filter(function (e) { return e.provenance === 'staff-movement'; }).length,
        staffListEdges: edges.filter(function (e) { return e.provenance === 'staff-list'; }).length,
        addedPeople: nodes.filter(function (n) { return n.addedFrom; }).length
    };
    KINDS.forEach(function (kind) {
        counts['kind_' + kind] = nodes.filter(function (n) { return n.kind === kind; }).length;
    });
    CATEGORIES.forEach(function (category) {
        counts['edge_' + category] = edges.filter(function (e) { return e.category === category; }).length;
    });

    /* The curated opening view. Resolved from names to ids here so the
     * browser never has to match strings, and so a name that no longer
     * exists on the board is caught by the build and reported rather than
     * quietly leaving the map one organisation short. */
    const byName = new Map(nodes.map(function (n) { return [n.name.toLowerCase(), n]; }));
    const headline = [];
    (overrides.headline || []).forEach(function (name) {
        const node = byName.get(String(name).toLowerCase());
        if (node) {
            headline.push(node.id);
        } else {
            qa.missingHeadline.push(String(name));
        }
    });

    /* The views the map can open on, headline first as "default". Resolved
     * to ids here for the same reason as the headline; a view that resolves
     * to nothing is left out rather than offered empty. */
    const views = [{ key: 'default', label: 'The largest networks', ids: headline }];
    Object.keys(overrides.views).forEach(function (key) {
        if (key === 'default' || !/^[a-z0-9-]+$/.test(key)) {
            qa.missingViewNames.push('view key "' + key + '" is reserved or not lowercase-with-hyphens');
            return;
        }
        const view = overrides.views[key] || {};
        const ids = [];
        (view.names || []).forEach(function (name) {
            const node = byName.get(String(name).toLowerCase());
            if (node) ids.push(node.id);
            else qa.missingViewNames.push(key + ': ' + String(name));
        });
        if (ids.length) views.push({ key: key, label: String(view.label || key), ids: ids });
    });

    const chains = Array.from(new Set(nodes.map(function (n) { return n.chain; }).filter(Boolean))).sort();
    const regions = Array.from(new Set(nodes.reduce(function (all, n) {
        return all.concat(n.regions);
    }, []))).sort();

    const graph = {
        meta: {
            /* deliberately no timestamp: it would make every rebuild a diff.
             * sourceHash identifies the inputs, and the QA report is dated. */
            sourceHash: sourceHash,
            facilitySource: facilities ? facilities.source : 'none',
            counts: counts,
            categories: CATEGORIES,
            kinds: KINDS,
            chains: chains,
            regions: regions,
            /* The organisations the map opens on, as node ids, in the order
             * the curator listed them. Names that match nothing are dropped
             * here rather than left for the browser to trip over. */
            headline: headline,
            /* [{key, label, ids}], "default" first: the headline. */
            views: views
        },
        nodes: nodes,
        edges: edges
    };

    fs.writeFileSync(OUTPUT_FILE, JSON.stringify(graph, null, 1) + '\n', 'utf8');
    writeQaReport(graph);

    console.log('');
    console.log('Wrote ' + path.relative(ROOT, OUTPUT_FILE) + ':');
    console.log('  ' + counts.nodes + ' nodes, ' + counts.edges + ' edges');
    console.log('  kinds: ' + KINDS.map(function (k) { return k + ' ' + counts['kind_' + k]; }).join(', '));
    console.log('  facility matches: ' + matched + ' (' + Object.keys(matchNotes).map(function (k) {
        return k + ' ' + matchNotes[k];
    }).join(', ') + ')');
    console.log('Wrote ' + path.relative(ROOT, QA_FILE) + ' with ' + qaTotal() + ' items to review.');
    return graph;
}

function qaTotal() {
    return Object.keys(qa).reduce(function (sum, key) { return sum + qa[key].length; }, 0);
}

/* ------------------------------------------------------------------ *
 * QA report
 * ------------------------------------------------------------------ */

function writeQaReport(graph) {
    const lines = [];
    lines.push('# Network graph QA report');
    lines.push('');
    lines.push('Generated ' + new Date().toISOString() + ' from source ' + graph.meta.sourceHash + '.');
    lines.push('Facility source: ' + graph.meta.facilitySource + '.');
    lines.push('');
    lines.push('Work the sections in order. Every fix belongs in');
    lines.push('`js/data/network/network-overrides.json`, never in the source CSVs.');
    lines.push('');

    section(lines, 'Kind guesses to confirm', qa.kindGuesses, function (item) {
        return '`' + item.name + '` -> **' + item.kind + '** (' + item.reason + ') [' + item.region + ']';
    }, 'Fix by adding `"' + 'NAME' + '": "kind"` under `kinds`.');

    section(lines, 'Ambiguous acquirers', qa.ambiguousAcquirers, function (item) {
        return item.id + ': `' + item.from + '` / `' + item.to + '` (' + item.raw + ') chose **' + item.chose + '**';
    }, 'Fix by adding `"EDGE_ID": "Acquirer Name"` under `acquirers`.');

    section(lines, 'Facilities matching more than one record', qa.multiMatchFacilities, function (item) {
        return '`' + item.name + '` (key `' + item.key + '`): ' + item.candidates.join(' | ');
    }, 'Fix by adding `"NAME": <facility id>` under `facilities`.');

    section(lines, 'Suggested matches, not applied', qa.looseMatches, function (item) {
        return '`' + item.name + '` (' + item.kind + ') [' + item.region + ']: ' + item.candidates.join(' | ');
    }, 'A shared word stem, nothing more. Roughly half are different places. '
     + 'Accept one by pinning its id under `facilities`, or set null to silence it.');

    section(lines, 'Unmatched organisations', qa.unmatchedFacilities, function (item) {
        return '`' + item.name + '` (' + item.kind + ') [' + item.region + ']';
    }, 'Add an alias under `aliases`, pin an id under `facilities`, or set null to silence.');

    section(lines, 'Relationships that fell through to unknown', qa.weakRelationships, function (item) {
        return '`' + (item.raw || '(blank)') + '` on ' + item.edge;
    }, 'Fix by adding `"RAW": {"category": "...", "roles": ["..."]}` under `relationships`.');

    section(lines, 'Organisations defaulted to facility that own others', qa.weakKinds, function (item) {
        return '`' + item.name + '` owns ' + item.owns + ' organisations [' + item.region + ']';
    }, 'These may be parent companies. Set the name to "parent" under `kinds` if so.');

    section(lines, 'Chains inferred from an owner', qa.chainInferred, function (item) { return item; },
        'These were blank in the CSV and filled from a corporate edge. Spot-check them.');

    section(lines, 'Rebrands, in the direction the board drew them', qa.rebrands, function (item) { return item; },
        'Read as "became". The build trusts the CSV here, so a backwards row stays backwards.');

    section(lines, 'Rebrands where both ends were closed', qa.rebrandGuesses, function (item) { return item; },
        'The source was taken to be the old name. Correct a wrong one under `statuses`.');

    section(lines, 'Memorial programs not placed on a node', qa.unmatchedDeaths, function (item) { return item; },
        'Unmatched or matching two nodes. Add the count under `deaths` against the right node name.');

    section(lines, 'Facilities with no years of operation', qa.noYears, function (item) { return item; },
        'Nothing records when these ran. Add `"NAME": "1971-2004"` under `years` where it is known.');

    section(lines, 'Connections added from the facility and operator profiles', qa.profileEdges,
        function (item) { return item; },
        'Both ends were already on the board and the board had no line between them. ' +
        'A wrong one means the profile record is wrong: fix it there.');

    section(lines, 'Profile names that are another board node', qa.profileNames, function (item) { return item; },
        'Not added. Either a rebrand the board is missing (draw it on the board) or a sister ' +
        'programme listed as a name by mistake (fix the profile).');

    section(lines, 'Staff moves drawn from staff-movement.csv', qa.staffMoves, function (item) { return item; },
        'Reviewed rows that resolved. Correct a row in the CSV, not here.');

    section(lines, 'Staff moves with a place not on the board', qa.staffUnresolved, function (item) { return item; },
        'Skipped. Add an alias under `aliases` if the place is on the board under another name.');

    section(lines, 'People added who are not on the board', qa.addedPeople, function (item) { return item; },
        'Named at two or more places, or as founder, owner, CEO or president of one. A "check" means ' +
        'the board has someone with the same surname and initial: if it is the same person, add the ' +
        'spelling under `aliases` against the board name.');

    section(lines, 'Connections added from the staff list', qa.staffListEdges, function (item) { return item; },
        'Drawn from staff-list.csv where the map had no line between the two. ' + staffListAlreadyShown +
        ' more pairs were already connected and were left alone. Correct a row in staff-list.txt and rerun ' +
        'scripts/parse-staff-list.js.');

    section(lines, 'Staff list places not on the board', qa.staffListUnresolved, function (item) { return item; },
        'Skipped. Add a spelling to SHORT_FORMS in scripts/parse-staff-list.js if the place is on the board, ' +
        'or draw it on the board if it belongs there.');

    section(lines, 'Starter-view names not found on the board', qa.missingViewNames, function (item) { return item; },
        'Fix the spelling under `views` in network-overrides.json, or drop the name.');

    section(lines, 'Merged nodes', qa.mergedNodes, function (item) { return item; }, '');
    section(lines, 'Duplicate edges dropped', qa.duplicateEdges, function (item) { return item; }, '');
    section(lines, 'Isolated nodes', qa.isolatedNodes, function (item) { return item; },
        'These have no edges and will not appear in the map unless it shows orphans.');
    section(lines, 'Rows dropped', qa.droppedRows, function (item) { return item; }, '');
    section(lines, 'Headline organisations not found on the board', qa.missingHeadline,
        function (item) { return item; },
        'The map opens on these. A name here matched no node, so the opening view is ' +
        'one organisation short: fix the spelling in network-overrides.json, or drop it.');

    fs.mkdirSync(path.dirname(QA_FILE), { recursive: true });
    fs.writeFileSync(QA_FILE, lines.join('\n') + '\n', 'utf8');
}

function section(lines, title, items, format, help) {
    lines.push('## ' + title + ' (' + items.length + ')');
    lines.push('');
    if (help) { lines.push(help); lines.push(''); }
    if (items.length === 0) {
        lines.push('Nothing to review.');
    } else {
        items.forEach(function (item) { lines.push('- ' + format(item)); });
    }
    lines.push('');
}

if (require.main === module) {
    try {
        build();
    } catch (err) {
        console.error('Build failed: ' + err.message);
        process.exit(1);
    }
}

module.exports = { build: build, parseCsv: parseCsv, nameKey: nameKey, slugify: slugify };
