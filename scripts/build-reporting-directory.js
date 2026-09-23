#!/usr/bin/env node
/**
 * Build Reporting Directory
 *
 * Turns the per-state reporting files into the single directory.json the
 * /report-abuse/ page reads, and refuses to build a record that a reader
 * could act on and be let down by.
 *
 * Architecture:
 * - js/data/reporting/national.json      = source of truth, national bodies
 * - js/data/reporting/states/<abbr>.json = source of truth, one per state
 * - js/data/reporting/directory.json     = build output, committed
 * - tmp/reporting-qa.md                  = build output, gitignored
 *
 * Validation is strict on purpose. Somebody uses these numbers on the worst
 * day of their life, so an unsourced channel, an unparseable phone number or
 * a category nobody renders is a build failure, not a warning. Warnings are
 * for the things a human has to judge: a stale verification, a state with no
 * file, a category a state has left uncovered.
 *
 * Usage: node scripts/build-reporting-directory.js
 *        node scripts/build-reporting-directory.js --quiet
 */

const fs = require('fs');
const path = require('path');

const ROOT = path.join(__dirname, '..');
const DATA_DIR = path.join(ROOT, 'js', 'data', 'reporting');
const STATES_DIR = path.join(DATA_DIR, 'states');
const NATIONAL_FILE = path.join(DATA_DIR, 'national.json');
const OUTPUT_FILE = path.join(DATA_DIR, 'directory.json');
const QA_FILE = path.join(ROOT, 'tmp', 'reporting-qa.md');

/* The four things a reader is actually choosing between. The order here is
 * the order the page renders them in, and it is deliberate: a licensing board
 * is what most people come looking for, but the facility licensor and the
 * legal channels are usually the ones with teeth. */
const CATEGORIES = [
    {
        key: 'professional-board',
        label: 'The therapist\'s licensing board',
        blurb: 'Holds the individual clinician\'s licence and can suspend or revoke it.'
    },
    {
        key: 'facility-licensing',
        label: 'The agency that licenses the program',
        blurb: 'Inspects the facility itself and can cite, fine, suspend or close it.'
    },
    {
        key: 'legal',
        label: 'Law enforcement and legal channels',
        blurb: 'Child protection, the Attorney General, and the criminal side.'
    },
    {
        key: 'oversight',
        label: 'Oversight and advocacy',
        blurb: 'Bodies with a right to investigate, and advocates who can act for you.'
    }
];
const CATEGORY_KEYS = CATEGORIES.map((c) => c.key);

/* Licence types a professional-board channel may claim. Kept closed so the
 * page can group by profession without a pile of near-duplicate labels. */
const PROFESSIONS = [
    'Psychologist',
    'Psychiatrist',
    'Social worker',
    'Professional counselor',
    'Marriage and family therapist',
    'Substance use counselor',
    'Nurse',
    'Behavior analyst',
    'Multiple professions'
];

const ANONYMOUS = ['allowed', 'discouraged', 'not-allowed', 'unknown'];

const STATES = {
    AL: 'Alabama', AK: 'Alaska', AZ: 'Arizona', AR: 'Arkansas', CA: 'California',
    CO: 'Colorado', CT: 'Connecticut', DE: 'Delaware', FL: 'Florida', GA: 'Georgia',
    HI: 'Hawaii', ID: 'Idaho', IL: 'Illinois', IN: 'Indiana', IA: 'Iowa',
    KS: 'Kansas', KY: 'Kentucky', LA: 'Louisiana', ME: 'Maine', MD: 'Maryland',
    MA: 'Massachusetts', MI: 'Michigan', MN: 'Minnesota', MS: 'Mississippi',
    MO: 'Missouri', MT: 'Montana', NE: 'Nebraska', NV: 'Nevada', NH: 'New Hampshire',
    NJ: 'New Jersey', NM: 'New Mexico', NY: 'New York', NC: 'North Carolina',
    ND: 'North Dakota', OH: 'Ohio', OK: 'Oklahoma', OR: 'Oregon', PA: 'Pennsylvania',
    RI: 'Rhode Island', SC: 'South Carolina', SD: 'South Dakota', TN: 'Tennessee',
    TX: 'Texas', UT: 'Utah', VT: 'Vermont', VA: 'Virginia', WA: 'Washington',
    WV: 'West Virginia', WI: 'Wisconsin', WY: 'Wyoming', DC: 'District of Columbia'
};

/* A verification older than this is still published - an old number is not a
 * wrong number - but the page says so and the QA report lists it. */
const STALE_DAYS = 365;

const errors = [];
const warnings = [];

function fail(where, message) {
    errors.push(`${where}: ${message}`);
}
function warn(where, message) {
    warnings.push(`${where}: ${message}`);
}

function readJson(file) {
    let raw;
    try {
        raw = fs.readFileSync(file, 'utf8');
    } catch (err) {
        fail(path.relative(ROOT, file), `cannot be read (${err.code})`);
        return null;
    }
    try {
        return JSON.parse(raw);
    } catch (err) {
        fail(path.relative(ROOT, file), `is not valid JSON - ${err.message}`);
        return null;
    }
}

function isIsoDate(value) {
    if (typeof value !== 'string' || !/^\d{4}-\d{2}-\d{2}$/.test(value)) return false;
    const d = new Date(`${value}T00:00:00Z`);
    return !Number.isNaN(d.getTime()) && d.toISOString().slice(0, 10) === value;
}

function daysSince(iso) {
    const then = new Date(`${iso}T00:00:00Z`).getTime();
    return Math.floor((Date.now() - then) / 86400000);
}

/* An https URL that is not a placeholder. http is allowed with a warning:
 * a few state boards still have no certificate, and dropping them would
 * leave a reader with nothing. */
function checkUrl(where, field, value) {
    if (typeof value !== 'string' || value.trim() === '') {
        fail(where, `${field} is present but empty - leave the key out instead`);
        return;
    }
    let url;
    try {
        url = new URL(value);
    } catch (err) {
        fail(where, `${field} is not a URL: ${value}`);
        return;
    }
    if (url.protocol === 'http:') {
        warn(where, `${field} is plain http, not https: ${value}`);
    } else if (url.protocol !== 'https:') {
        fail(where, `${field} is not http(s): ${value}`);
    }
    if (/example\.(com|org)|TODO|CHANGEME/i.test(value)) {
        fail(where, `${field} is still a placeholder: ${value}`);
    }
}

/* Phone numbers are stored as the agency prints them, so the check is on the
 * digits rather than the punctuation: 10 digits, or 11 starting with a 1.
 * Extensions ("x214") are allowed after the number. */
function checkPhone(where, value) {
    if (typeof value !== 'string' || value.trim() === '') {
        fail(where, 'phone is present but empty - leave the key out instead');
        return;
    }
    const [main] = value.split(/\s*(?:x|ext\.?)\s*/i);
    const digits = main.replace(/\D/g, '');
    if (digits.length === 10) return;
    if (digits.length === 11 && digits.startsWith('1')) return;
    /* 988, 911 and the like. */
    if (/^\d{3}$/.test(digits)) return;
    fail(where, `phone does not look like a US number: ${value}`);
}

function checkString(where, field, value, { min = 1 } = {}) {
    if (typeof value !== 'string') {
        fail(where, `${field} must be a string`);
        return false;
    }
    if (value.trim().length < min) {
        fail(where, `${field} is empty or too short`);
        return false;
    }
    if (value !== value.trim()) {
        warn(where, `${field} has leading or trailing whitespace`);
    }
    return true;
}

const KNOWN_FIELDS = new Set([
    'id', 'category', 'profession', 'name', 'what_it_can_do', 'what_it_cannot_do',
    'who_to_report', 'how', 'complaint_url', 'info_url', 'phone', 'phone_note',
    'email', 'mail', 'anonymous', 'mandatory_reporter', 'deadline',
    'verified_on', 'sources', 'note'
]);

/**
 * Validate one channel and return it normalized, or null if it is unusable.
 * `scope` is the state abbreviation, or 'national'.
 */
function normalizeChannel(channel, scope, seenIds) {
    if (!channel || typeof channel !== 'object' || Array.isArray(channel)) {
        fail(scope, 'a channel is not an object');
        return null;
    }
    const where = `${scope}/${channel.id || '(no id)'}`;

    if (!checkString(where, 'id', channel.id)) return null;
    if (!/^[a-z0-9]+(-[a-z0-9]+)*$/.test(channel.id)) {
        fail(where, 'id must be lower-case words joined by single hyphens');
        return null;
    }
    if (seenIds.has(channel.id)) {
        fail(where, 'id is used twice');
        return null;
    }
    seenIds.add(channel.id);

    const prefix = scope === 'national' ? 'us' : scope.toLowerCase();
    if (!channel.id.startsWith(`${prefix}-`)) {
        warn(where, `id does not start with "${prefix}-"`);
    }

    if (!CATEGORY_KEYS.includes(channel.category)) {
        fail(where, `category must be one of ${CATEGORY_KEYS.join(', ')} - got ${JSON.stringify(channel.category)}`);
        return null;
    }

    checkString(where, 'name', channel.name, { min: 4 });
    checkString(where, 'what_it_can_do', channel.what_it_can_do, { min: 12 });

    /* A licensing board must say whose licence it holds. An oversight body may
     * also name one - an association ethics committee answers the same question
     * a reader is asking - but the other two categories are about the facility
     * or the case, not about one clinician's credential. */
    if (channel.category === 'professional-board') {
        if (!PROFESSIONS.includes(channel.profession)) {
            fail(where, `profession must be one of ${PROFESSIONS.join(', ')} - got ${JSON.stringify(channel.profession)}`);
        }
    } else if (channel.profession !== undefined) {
        if (channel.category !== 'oversight') {
            fail(where, 'profession is only for a professional-board or oversight channel');
        } else if (!PROFESSIONS.includes(channel.profession)) {
            fail(where, `profession must be one of ${PROFESSIONS.join(', ')} - got ${JSON.stringify(channel.profession)}`);
        }
    }

    for (const field of ['complaint_url', 'info_url']) {
        if (channel[field] !== undefined) checkUrl(where, field, channel[field]);
    }
    if (channel.phone !== undefined) checkPhone(where, channel.phone);
    if (channel.email !== undefined && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(String(channel.email))) {
        fail(where, `email is not an address: ${channel.email}`);
    }
    if (channel.anonymous !== undefined && !ANONYMOUS.includes(channel.anonymous)) {
        fail(where, `anonymous must be one of ${ANONYMOUS.join(', ')} - got ${JSON.stringify(channel.anonymous)}`);
    }
    if (channel.mandatory_reporter !== undefined && typeof channel.mandatory_reporter !== 'boolean') {
        fail(where, 'mandatory_reporter must be true or false');
    }

    /* A channel nobody can reach is not a channel. */
    if (!channel.complaint_url && !channel.info_url && !channel.phone && !channel.email && !channel.mail) {
        fail(where, 'has no way to reach it - needs a URL, a phone number, an email or an address');
    }

    if (!isIsoDate(channel.verified_on)) {
        fail(where, `verified_on must be YYYY-MM-DD - got ${JSON.stringify(channel.verified_on)}`);
    } else {
        const age = daysSince(channel.verified_on);
        if (age < 0) {
            fail(where, `verified_on is in the future: ${channel.verified_on}`);
        } else if (age > STALE_DAYS) {
            warn(where, `last verified ${age} days ago (${channel.verified_on}) - re-check before trusting it`);
        }
    }

    if (!Array.isArray(channel.sources) || channel.sources.length === 0) {
        fail(where, 'needs at least one source URL - an unsourced number does not ship');
    } else {
        channel.sources.forEach((src, i) => checkUrl(where, `sources[${i}]`, src));
    }

    for (const key of Object.keys(channel)) {
        if (!KNOWN_FIELDS.has(key)) {
            fail(where, `unknown field "${key}" - add it to the README and the build before using it`);
        }
        if (channel[key] === '' || channel[key] === null) {
            fail(where, `"${key}" is empty - leave the key out instead`);
        }
    }

    return channel;
}

function loadStateFile(file) {
    const base = path.basename(file, '.json');
    const abbr = base.toUpperCase();
    if (!STATES[abbr]) {
        fail(path.relative(ROOT, file), `filename is not a state abbreviation ("${base}")`);
        return null;
    }
    const data = readJson(file);
    if (!data) return null;

    const where = abbr;
    if (data.abbr !== abbr) {
        fail(where, `"abbr" is ${JSON.stringify(data.abbr)} but the file is named ${base}.json`);
    }
    if (data.state !== STATES[abbr]) {
        fail(where, `"state" is ${JSON.stringify(data.state)} but ${abbr} is ${STATES[abbr]}`);
    }
    if (!isIsoDate(data.updated)) {
        fail(where, `"updated" must be YYYY-MM-DD - got ${JSON.stringify(data.updated)}`);
    }
    if (!Array.isArray(data.channels) || data.channels.length === 0) {
        fail(where, 'has no channels - delete the file rather than publishing an empty state');
        return null;
    }

    const seenIds = new Set();
    const channels = data.channels
        .map((c) => normalizeChannel(c, abbr, seenIds))
        .filter(Boolean);

    for (const { key, label } of CATEGORIES) {
        if (!channels.some((c) => c.category === key)) {
            warn(where, `nothing under "${label}" yet`);
        }
    }

    return {
        state: STATES[abbr],
        abbr,
        slug: STATES[abbr].toLowerCase().replace(/[^a-z0-9]+/g, '-'),
        updated: data.updated,
        note: data.note || undefined,
        channels
    };
}

function main() {
    const quiet = process.argv.includes('--quiet');

    /* National first, so a state file can be read against it later. */
    const nationalRaw = readJson(NATIONAL_FILE) || {};
    const nationalSeen = new Set();
    const national = Array.isArray(nationalRaw.channels)
        ? nationalRaw.channels.map((c) => normalizeChannel(c, 'national', nationalSeen)).filter(Boolean)
        : [];
    if (!national.length) fail('national.json', 'has no channels');
    if (nationalRaw.updated !== undefined && !isIsoDate(nationalRaw.updated)) {
        fail('national.json', `"updated" must be YYYY-MM-DD - got ${JSON.stringify(nationalRaw.updated)}`);
    }

    const files = fs.existsSync(STATES_DIR)
        ? fs.readdirSync(STATES_DIR).filter((f) => f.endsWith('.json')).sort()
        : [];
    const states = files.map((f) => loadStateFile(path.join(STATES_DIR, f))).filter(Boolean);
    const covered = new Set(states.map((s) => s.abbr));
    const missing = Object.keys(STATES).filter((a) => !covered.has(a)).sort();

    for (const abbr of missing) {
        warn('coverage', `${STATES[abbr]} (${abbr}) has no file yet`);
    }

    writeQaReport({ states, national, missing });

    if (errors.length) {
        console.error(`\n${errors.length} error${errors.length === 1 ? '' : 's'} - nothing was written:\n`);
        errors.forEach((e) => console.error(`  ${e}`));
        console.error(`\nQA report: ${path.relative(ROOT, QA_FILE)}`);
        process.exit(1);
    }

    const out = {
        generated: new Date().toISOString(),
        categories: CATEGORIES,
        professions: PROFESSIONS,
        national: {
            updated: nationalRaw.updated || null,
            note: nationalRaw.note || undefined,
            channels: national
        },
        states: states.sort((a, b) => a.state.localeCompare(b.state)),
        coverage: {
            covered: states.length,
            total: Object.keys(STATES).length,
            missing
        }
    };

    fs.mkdirSync(path.dirname(OUTPUT_FILE), { recursive: true });
    fs.writeFileSync(OUTPUT_FILE, `${JSON.stringify(out, null, 2)}\n`, 'utf8');

    if (!quiet) {
        const channelCount = national.length + states.reduce((n, s) => n + s.channels.length, 0);
        console.log(`Wrote ${path.relative(ROOT, OUTPUT_FILE)}`);
        console.log(`  ${channelCount} channels - ${national.length} national, ${states.length}/${Object.keys(STATES).length} states covered`);
        if (warnings.length) {
            console.log(`  ${warnings.length} warning${warnings.length === 1 ? '' : 's'} - see ${path.relative(ROOT, QA_FILE)}`);
        }
    }
}

function writeQaReport({ states, national, missing }) {
    const lines = [];
    lines.push('# Reporting directory QA');
    lines.push('');
    lines.push(`Generated ${new Date().toISOString()} by \`scripts/build-reporting-directory.js\`.`);
    lines.push('Gitignored. Rewritten on every build.');
    lines.push('');

    lines.push('## Coverage');
    lines.push('');
    lines.push('| State | Channels | ' + CATEGORIES.map((c) => c.label).join(' | ') + ' | Oldest check |');
    lines.push('| --- | --- | ' + CATEGORIES.map(() => '---').join(' | ') + ' | --- |');
    for (const s of states) {
        const counts = CATEGORIES.map((c) => s.channels.filter((ch) => ch.category === c.key).length || '-');
        const dates = s.channels.map((c) => c.verified_on).filter(isIsoDate).sort();
        lines.push(`| ${s.state} | ${s.channels.length} | ${counts.join(' | ')} | ${dates[0] || '-'} |`);
    }
    lines.push(`| **National** | ${national.length} | ` + CATEGORIES.map((c) => national.filter((ch) => ch.category === c.key).length || '-').join(' | ') + ' | - |');
    lines.push('');

    if (missing.length) {
        lines.push(`## Not covered yet (${missing.length})`);
        lines.push('');
        lines.push(missing.map((a) => `${STATES[a]} (${a})`).join(', '));
        lines.push('');
    }

    if (errors.length) {
        lines.push(`## Errors (${errors.length})`);
        lines.push('');
        lines.push('The build wrote nothing. Fix these first.');
        lines.push('');
        errors.forEach((e) => lines.push(`- ${e}`));
        lines.push('');
    }

    lines.push(`## Warnings (${warnings.length})`);
    lines.push('');
    if (warnings.length) {
        warnings.forEach((w) => lines.push(`- ${w}`));
    } else {
        lines.push('None.');
    }
    lines.push('');

    fs.mkdirSync(path.dirname(QA_FILE), { recursive: true });
    fs.writeFileSync(QA_FILE, lines.join('\n'), 'utf8');
}

main();
