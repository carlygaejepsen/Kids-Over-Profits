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
    droppedRows: [], chainInferred: [], missingHeadline: []
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
        return { merges: [], aliases: {}, kinds: {}, relationships: {}, facilities: {}, acquirers: {}, headline: [] };
    }
    const raw = JSON.parse(fs.readFileSync(OVERRIDES_FILE, 'utf8'));
    return {
        merges: raw.merges || [], aliases: raw.aliases || {}, kinds: raw.kinds || {},
        headline: raw.headline || [],
        relationships: raw.relationships || {}, facilities: raw.facilities || {},
        acquirers: raw.acquirers || {}
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
            const rows = db.prepare('SELECT id, unique_name, name, name_key, state, status FROM facilities_v2').all();
            db.close();
            const index = new Map();
            const loose = new Map();
            rows.forEach(function (row) {
                const record = {
                    id: row.id, uniqueName: row.unique_name, name: row.name,
                    state: row.state || '', status: row.status || ''
                };
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
            return { source: 'facilities_v2', index: index, loose: loose, count: rows.length };
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

    /* --- 8. layout and output ------------------------------------ */
    rescaleBoard(nodes);
    nodes.forEach(function (node) { delete node.rawStatus; delete node.kindWeak; });
    nodes.sort(function (a, b) { return b.degree - a.degree || a.name.localeCompare(b.name); });

    const counts = { nodes: nodes.length, edges: edges.length, facilityMatches: matched };
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
            headline: headline
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
