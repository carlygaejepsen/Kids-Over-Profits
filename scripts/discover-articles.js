#!/usr/bin/env node
/**
 * Article Discovery
 *
 * Pulls candidate articles from Google News RSS (per active facility) and
 * r/troubledteens (new posts), pre-filters them with a state-aware scoring
 * pass, then feeds surviving URLs through the existing AI processor into
 * the news_submissions review queue.
 *
 * Facility data comes live from the WP REST API — NOT from any local JSON
 * snapshot, since the JSON snapshots are out of date.
 *
 * Usage:
 *   node scripts/discover-articles.js              # full run
 *   node scripts/discover-articles.js --dry-run    # discover + score, skip submission
 *   node scripts/discover-articles.js --limit 5    # cap candidates submitted this run
 *   node scripts/discover-articles.js --max-facilities 3   # smoke test
 *
 * Environment:
 *   NEWS_API_BASE          (default: https://kidsoverprofits.org)
 *   AI_PROVIDER            (default: groq)
 *   SHARD_COUNT            (default: 7 — facilities split into N daily shards)
 */

'use strict';

const fs = require('fs');
const path = require('path');
const crypto = require('crypto');

// ============================================================
// Paths & config
// ============================================================

const STATE_FILE     = path.join(__dirname, '.discovery-state.json');
const REJECTED_FILE  = path.join(__dirname, 'discovery-rejected.json');
const BLACKLIST_FILE = path.join(__dirname, 'discovery-blacklist.json');

const args = process.argv.slice(2);
const DRY_RUN = args.includes('--dry-run');
const LIMIT_ARG = args.indexOf('--limit');
const SUBMIT_LIMIT = LIMIT_ARG > -1 ? parseInt(args[LIMIT_ARG + 1], 10) : Infinity;
const MAX_FAC_ARG = args.indexOf('--max-facilities');
const MAX_FACILITIES = MAX_FAC_ARG > -1 ? parseInt(args[MAX_FAC_ARG + 1], 10) : null;

const API_BASE = (process.env.NEWS_API_BASE || 'https://kidsoverprofits.org').replace(/\/$/, '');
const FACILITIES_URL = `${API_BASE}/wp-json/kop/v1/facilities`;
const AI_ENDPOINT = `${API_BASE}/wp-content/themes/child/api/process-news-ai.php`;
const SUBMIT_ENDPOINT = `${API_BASE}/wp-content/themes/child/api/save-news-submission.php`;

const AI_PROVIDER = process.env.AI_PROVIDER || 'groq';
const SHARD_COUNT = parseInt(process.env.SHARD_COUNT || '7', 10);

const SCORE_THRESHOLD     = 3;
const RSS_REQUEST_DELAY_MS = 1500;
const AI_REQUEST_DELAY_MS  = 4000;
const MAX_SEEN_URLS        = 50000;
const PER_FACILITY_CAP     = 15;    // RSS items considered per facility
const REQUEST_TIMEOUT_MS   = 30000;
const AI_TIMEOUT_MS        = 90000;
// An alias appearing in this many deduped facility entries is considered
// "generic" (e.g., "Juvenile Detention Center"). Matches on generic aliases
// require positive state-signal confirmation downstream, not just
// absence-of-mismatch — otherwise unrelated facilities sharing the name
// constantly produce false positives.
const GENERIC_ALIAS_MIN_FACILITIES = 3;
const USER_AGENT = 'kids-over-profits-discovery/1.0 (+https://kidsoverprofits.org)';

// Statuses to skip when building the facility list
const EXCLUDED_FACILITY_STATUSES = new Set(['closed', 'transferred', 'adults only']);
const EXCLUDED_OPERATOR_STATUSES = new Set(['defunct']);

const ABUSE_KEYWORDS = [
    'abuse', 'abused', 'abusing', 'arrest', 'arrested', 'indict', 'indicted', 'indictment',
    'charged', 'charges', 'lawsuit', 'sued', 'suing', 'investigation', 'investigates',
    'closed', 'closes', 'closure', 'shut down', 'shutdown', 'raided', 'raid',
    'pleads guilty', 'guilty plea', 'convicted', 'conviction', 'sentenced', 'sentencing',
    'allegation', 'allegations', 'alleged', 'misconduct', 'death', 'died', 'killed',
    'restraint', 'seclusion', 'neglect', 'assault', 'molest', 'molestation',
    'survivor', 'whistleblower', 'class action', 'settlement', 'fined',
    'license revoked', 'license suspended', 'shuttered', 'felony', 'felonies'
];

// Used to build Google News queries and to extract state signals downstream
const STATE_NAMES = {
    AL: 'Alabama', AK: 'Alaska', AZ: 'Arizona', AR: 'Arkansas', CA: 'California',
    CO: 'Colorado', CT: 'Connecticut', DE: 'Delaware', FL: 'Florida', GA: 'Georgia',
    HI: 'Hawaii', ID: 'Idaho', IL: 'Illinois', IN: 'Indiana', IA: 'Iowa',
    KS: 'Kansas', KY: 'Kentucky', LA: 'Louisiana', ME: 'Maine', MD: 'Maryland',
    MA: 'Massachusetts', MI: 'Michigan', MN: 'Minnesota', MS: 'Mississippi', MO: 'Missouri',
    MT: 'Montana', NE: 'Nebraska', NV: 'Nevada', NH: 'New Hampshire', NJ: 'New Jersey',
    NM: 'New Mexico', NY: 'New York', NC: 'North Carolina', ND: 'North Dakota', OH: 'Ohio',
    OK: 'Oklahoma', OR: 'Oregon', PA: 'Pennsylvania', RI: 'Rhode Island', SC: 'South Carolina',
    SD: 'South Dakota', TN: 'Tennessee', TX: 'Texas', UT: 'Utah', VT: 'Vermont',
    VA: 'Virginia', WA: 'Washington', WV: 'West Virginia', WI: 'Wisconsin', WY: 'Wyoming',
    DC: 'District of Columbia'
};

// Hard-blocked URL hosts (social networks etc.) on top of the JSON blacklist —
// these are too generic to want in the blacklist file and don't host news.
const HARD_BLOCKED_HOSTS = new Set([
    'twitter.com', 'x.com', 'facebook.com', 'instagram.com',
    'tiktok.com', 'youtube.com', 'youtu.be', 'pinterest.com',
    'quora.com', 'reddit.com', 'redd.it', 'i.redd.it', 'v.redd.it',
    'preview.redd.it', 'i.imgur.com', 'imgur.com',
    // Chat / messaging / link shorteners — not articles
    'discord.gg', 'discord.com', 't.me', 'telegram.me',
    'bit.ly', 'tinyurl.com', 'ow.ly', 'buff.ly', 'goo.gl',
    // GoFundMe and similar — fundraising, not reporting
    'gofundme.com'
]);

// ============================================================
// Tiny helpers
// ============================================================

const log  = (...a) => console.log(...a);
const warn = (...a) => console.warn(...a);

function sleep(ms) { return new Promise(r => setTimeout(r, ms)); }

function normalizeName(s) {
    return String(s || '')
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, ' ')
        .replace(/\s+/g, ' ')
        .trim();
}

// Words/phrases that, when trailing, can be stripped to produce a useful
// shorter variant alias. e.g. "Tennyson Center for Children" → "Tennyson Center".
const TRAILING_DROP_WORDS = new Set([
    'children', 'child', 'youth', 'youths', 'teens', 'teen', 'adolescents',
    'adolescent', 'boys', 'girls', 'kids', 'minors', 'juveniles', 'inc',
    'llc', 'lp', 'corp', 'corporation', 'company', 'co'
]);
const TRAILING_CONNECTORS = new Set(['for', 'of', 'the', 'and', 'a', 'an']);

/**
 * Generate shorter-prefix variants of a facility name so the matcher catches
 * common abbreviated forms in news headlines. For "Tennyson Center for Children"
 * produces ["Tennyson Center"]. Won't produce variants shorter than 2 words.
 */
function generateAliasVariants(name) {
    const out = [];
    let words = String(name || '').trim().split(/\s+/).filter(Boolean);
    if (words.length < 3) return out;

    // Iteratively strip trailing drop-words and connectors
    let changed = true;
    while (changed && words.length > 2) {
        changed = false;
        const last = words[words.length - 1].toLowerCase().replace(/[^a-z0-9]+$/, '');
        if (TRAILING_DROP_WORDS.has(last) || TRAILING_CONNECTORS.has(last)) {
            words = words.slice(0, -1);
            changed = true;
        }
    }
    const variant = words.join(' ');
    if (variant && variant.split(/\s+/).length >= 2 && variant.length >= 6 && variant !== name) {
        out.push(variant);
    }
    return out;
}

function dayOfYearUTC() {
    const now = new Date();
    const start = Date.UTC(now.getUTCFullYear(), 0, 0);
    const today = Date.UTC(now.getUTCFullYear(), now.getUTCMonth(), now.getUTCDate());
    return Math.floor((today - start) / 86400000);
}

async function fetchWithTimeout(url, opts = {}) {
    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(), opts.timeoutMs || REQUEST_TIMEOUT_MS);
    try {
        return await fetch(url, {
            ...opts,
            signal: controller.signal,
            headers: { 'User-Agent': USER_AGENT, ...(opts.headers || {}) },
            redirect: 'follow'
        });
    } finally {
        clearTimeout(timer);
    }
}

async function fetchText(url, opts = {}) {
    const res = await fetchWithTimeout(url, opts);
    if (!res.ok) throw new Error(`HTTP ${res.status} for ${url}`);
    return res.text();
}

async function postJson(url, body, opts = {}) {
    const res = await fetchWithTimeout(url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(body),
        timeoutMs: opts.timeoutMs
    });
    const text = await res.text();
    let parsed;
    try { parsed = JSON.parse(text); } catch { parsed = { rawText: text }; }
    return { status: res.status, ok: res.ok, body: parsed };
}

function loadJson(file, fallback) {
    try { return JSON.parse(fs.readFileSync(file, 'utf8')); }
    catch { return fallback; }
}

function saveJson(file, data) {
    fs.writeFileSync(file, JSON.stringify(data, null, 2) + '\n');
}

// ============================================================
// URL helpers (preserved from prior version — these were solid)
// ============================================================

function normalizeUrl(url) {
    try {
        const u = new URL(url);
        u.hash = '';
        ['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term',
         'fbclid', 'gclid', 'mc_cid', 'mc_eid'].forEach(p => u.searchParams.delete(p));
        return (u.host.replace(/^www\./, '') + u.pathname + (u.search || ''))
            .toLowerCase().replace(/\/$/, '');
    } catch {
        return url.toLowerCase();
    }
}

function hashUrl(url) {
    return crypto.createHash('sha256').update(normalizeUrl(url)).digest('hex').slice(0, 16);
}

function hostOf(url) {
    try { return new URL(url).host.replace(/^www\./, '').toLowerCase(); }
    catch { return ''; }
}

/**
 * Extract the underlying article host from a URL. For web.archive.org Wayback
 * URLs (e.g. https://web.archive.org/web/19961227/http://www.cedu.com/), this
 * returns 'cedu.com' instead of 'web.archive.org' — so the host can be matched
 * against the facility-owned-website set.
 */
function articleHostOf(url) {
    const h = hostOf(url);
    if (h !== 'web.archive.org') return h;
    // Wayback path format: /web/<timestamp>/<original-url>
    try {
        const inner = new URL(url).pathname.replace(/^\/web\/[^/]+\//, '');
        return hostOf(inner.startsWith('http') ? inner : 'http://' + inner);
    } catch { return h; }
}

// ============================================================
// State
// ============================================================

function loadState() {
    const s = loadJson(STATE_FILE, null);
    if (s) {
        if (!Array.isArray(s.seenUrls)) s.seenUrls = [];
        if (!s.stats) s.stats = { discovered: 0, submitted: 0, rejected: 0 };
        return s;
    }
    return { version: 2, lastRun: null, seenUrls: [], stats: { discovered: 0, submitted: 0, rejected: 0 } };
}

function saveState(state) {
    if (state.seenUrls.length > MAX_SEEN_URLS) {
        state.seenUrls = state.seenUrls.slice(-MAX_SEEN_URLS);
    }
    saveJson(STATE_FILE, state);
}

function persistRejected(newEntries) {
    const existing = loadJson(REJECTED_FILE, { entries: [] });
    const merged = newEntries
        .map(r => ({ ts: new Date().toISOString(), ...r }))
        .concat(existing.entries || [])
        .slice(0, 500);   // keep only most recent 500
    saveJson(REJECTED_FILE, { lastUpdated: new Date().toISOString(), entries: merged });
}

// ============================================================
// Blacklist
// ============================================================

function buildBlacklistMatcher() {
    const bl = loadJson(BLACKLIST_FILE, {});
    const allDomains = []
        .concat(Array.isArray(bl.spamDomains) ? bl.spamDomains : [])
        .concat(Array.isArray(bl.pressReleaseWires) ? bl.pressReleaseWires : [])
        .concat(Array.isArray(bl.industryPromoDomains) ? bl.industryPromoDomains : []);
    const allowOverrides = new Set(
        (Array.isArray(bl.allowlistOverrides) ? bl.allowlistOverrides : [])
            .map(d => String(d).toLowerCase().trim())
    );
    const pathPatterns = (Array.isArray(bl.urlPathPatterns) ? bl.urlPathPatterns : [])
        .map(p => String(p || '').toLowerCase().trim())
        .filter(Boolean);

    const exact = new Set();
    const suffixes = [];
    for (const raw of allDomains) {
        const d = String(raw || '').toLowerCase().trim();
        if (!d) continue;
        if (d.startsWith('*.')) suffixes.push(d.slice(2));
        else exact.add(d);
    }

    function hostBlocked(host) {
        if (!host) return false;
        const h = host.toLowerCase().replace(/^www\./, '');
        if (allowOverrides.has(h)) return false;
        if (HARD_BLOCKED_HOSTS.has(h)) return true;
        if (exact.has(h)) return true;
        for (const sfx of suffixes) {
            if (h === sfx || h.endsWith('.' + sfx)) return true;
        }
        return false;
    }

    function pathBlocked(url) {
        if (!url || !pathPatterns.length) return false;
        try {
            const u = new URL(url);
            const p = u.pathname.toLowerCase();
            for (const pat of pathPatterns) {
                if (p.includes(pat)) return pat;
            }
        } catch { /* ignore */ }
        return false;
    }

    return { hostBlocked, pathBlocked };
}

// ============================================================
// Facility index (built fresh from API each run)
// ============================================================

function parseLocation(loc) {
    if (typeof loc !== 'string') return { city: '', state: '' };
    const trimmed = loc.trim();
    if (!trimmed) return { city: '', state: '' };

    const parts = trimmed.split(',').map(p => p.trim()).filter(Boolean);
    if (parts.length < 2) return { city: trimmed, state: '' };

    const city = parts[0];
    const tail = parts[parts.length - 1];

    if (/^[A-Z]{2}$/.test(tail) && STATE_NAMES[tail]) {
        return { city, state: tail };
    }
    for (const [abbr, full] of Object.entries(STATE_NAMES)) {
        if (tail.toLowerCase() === full.toLowerCase()) {
            return { city, state: abbr };
        }
    }
    return { city, state: '' };
}

/**
 * Walk every project in /facilities and build:
 *   - facilities: deduplicated flat list of facility + operator entries
 *   - ownHosts:   Set of hostnames that belong to facilities/operators (their
 *                 own websites, profile links, etc.) — used to reject
 *                 candidates that link to a facility's own marketing page
 *                 instead of independent reporting.
 *
 * Dedupes by (normalizedName + state). Merges aliases on collision so the
 * same physical facility appearing in both a companies project and a
 * locations aggregate doesn't get queried twice.
 *
 * Each facility item:
 *   { queryName, aliases[], city, state, bucket, operator, status }
 *   - queryName: the primary name to use in Google News quoted search
 *   - aliases:   all known names for matching candidate titles/snippets
 *   - bucket:    'facility' or 'operator'
 *   - state:     '' for operators (cross-state) and for unknown locations
 */
function buildFacilityIndex(apiResponse) {
    const projects = (apiResponse && apiResponse.projects) || {};
    const byKey = new Map();
    const ownHosts = new Set();

    function collectHosts(urls) {
        if (!Array.isArray(urls)) return;
        for (const u of urls) {
            if (typeof u !== 'string' || !u.trim()) continue;
            const h = articleHostOf(u.trim());
            if (h) ownHosts.add(h);
        }
    }

    function addEntry(entry) {
        if (!entry.queryName || entry.queryName.length < 4) return;
        const key = normalizeName(entry.queryName) + '|' + (entry.state || '');
        const existing = byKey.get(key);
        if (existing) {
            // Merge aliases
            const seen = new Set(existing.aliases.map(a => normalizeName(a)));
            for (const alias of entry.aliases) {
                const k = normalizeName(alias);
                if (k && !seen.has(k)) {
                    existing.aliases.push(alias);
                    seen.add(k);
                }
            }
            if (!existing.city && entry.city) existing.city = entry.city;
            return;
        }
        byKey.set(key, entry);
    }

    for (const project of Object.values(projects)) {
        const data = project.data || {};
        const operator = data.operator || {};
        const operatorStatus = String(operator.status || '').toLowerCase().trim();
        const operatorIsDefunct = EXCLUDED_OPERATOR_STATUSES.has(operatorStatus);
        const operatorName = operator.name || project.name || '';

        // Collect operator-owned hosts (websites, etc.) regardless of status —
        // even a defunct operator's old site shouldn't be submitted as news.
        collectHosts(operator.websites);

        // --- Per-facility entries (includes those from locations_master aggregates) ---
        const facilities = Array.isArray(data.facilities) ? data.facilities : [];
        for (const f of facilities) {
            const ident = f.identification || {};
            const period = f.operatingPeriod || {};
            const status = String(period.status || '').toLowerCase().trim();

            // Collect profile links for ALL facilities (even closed) — closed
            // facilities' old websites still aren't news sources.
            collectHosts(f.profileLinks);
            if (f.sourceOperator) collectHosts(f.sourceOperator.websites);

            if (EXCLUDED_FACILITY_STATUSES.has(status)) continue;
            if (operatorIsDefunct) continue;

            const primaryName = (ident.currentName && ident.currentName.trim()) || ident.name || '';
            if (!primaryName) continue;

            const aliases = [primaryName];
            if (ident.name && ident.name !== primaryName) aliases.push(ident.name);
            for (const list of [ident.otherNames, ident.pastNames]) {
                if (Array.isArray(list)) {
                    for (const n of list) {
                        if (typeof n === 'string' && n.trim()) aliases.push(n.trim());
                    }
                }
            }
            // Add shorter-prefix variants (e.g. "Tennyson Center for Children" → "Tennyson Center")
            const originalAliases = aliases.slice();
            for (const a of originalAliases) {
                for (const v of generateAliasVariants(a)) aliases.push(v);
            }

            const { city, state } = parseLocation(f.location);

            addEntry({
                queryName: primaryName,
                aliases,
                city,
                state,
                bucket: 'facility',
                operator: operatorName || (f.sourceOperator && f.sourceOperator.name) || '',
                status: period.status || ''
            });
        }

        // --- Operator-level entry (companies category only) ---
        if (project.category === 'companies' && operatorName && !operatorIsDefunct) {
            const aliases = [operatorName];
            if (Array.isArray(operator.otherNames)) {
                for (const n of operator.otherNames) {
                    if (typeof n === 'string' && n.trim()) aliases.push(n.trim());
                }
            }
            if (Array.isArray(operator.parentCompanies)) {
                for (const n of operator.parentCompanies) {
                    if (typeof n === 'string' && n.trim()) aliases.push(n.trim());
                }
            }
            const opOriginalAliases = aliases.slice();
            for (const a of opOriginalAliases) {
                for (const v of generateAliasVariants(a)) aliases.push(v);
            }
            addEntry({
                queryName: operatorName,
                aliases,
                city: '',
                state: '',    // operators span states — skip state-match validation
                bucket: 'operator',
                operator: operatorName,
                status: operator.status || ''
            });
        }
    }

    const facilities = Array.from(byKey.values());

    // Identify generic aliases — names shared across N+ deduped entries (e.g.,
    // "Juvenile Detention Center"). These get stricter state-validation in
    // evaluateCandidate so generic name collisions don't slip through.
    const aliasCounts = new Map();
    for (const fac of facilities) {
        const seen = new Set();
        for (const alias of fac.aliases) {
            const k = normalizeName(alias);
            if (!k || k.length < 5 || seen.has(k)) continue;
            seen.add(k);
            aliasCounts.set(k, (aliasCounts.get(k) || 0) + 1);
        }
    }
    const genericAliases = new Set();
    for (const [k, n] of aliasCounts) {
        if (n >= GENERIC_ALIAS_MIN_FACILITIES) genericAliases.add(k);
    }

    return { facilities, ownHosts, genericAliases };
}

// ============================================================
// RSS / Reddit fetchers
// ============================================================

function decodeEntities(s) {
    return String(s || '')
        .replace(/&amp;/g, '&').replace(/&lt;/g, '<').replace(/&gt;/g, '>')
        .replace(/&quot;/g, '"').replace(/&apos;/g, "'")
        .replace(/&#(\d+);/g, (_, n) => String.fromCharCode(parseInt(n, 10)))
        .replace(/&#x([0-9a-f]+);/gi, (_, n) => String.fromCharCode(parseInt(n, 16)));
}

function stripCdata(s) {
    return String(s || '').replace(/<!\[CDATA\[([\s\S]*?)\]\]>/g, '$1');
}

function tagContent(xml, tag) {
    const m = new RegExp(`<${tag}\\b[^>]*>([\\s\\S]*?)<\\/${tag}>`, 'i').exec(xml);
    return m ? decodeEntities(stripCdata(m[1])).trim() : '';
}

function parseRssItems(xml) {
    const items = [];
    const itemRegex = /<item\b[^>]*>([\s\S]*?)<\/item>/gi;
    let m;
    while ((m = itemRegex.exec(xml)) !== null) {
        const block = m[1];

        // <source url="...">Publication</source>
        let sourceUrl = '', sourceName = '';
        const sMatch = block.match(/<source\b([^>]*)>([\s\S]*?)<\/source>/i);
        if (sMatch) {
            const ua = sMatch[1].match(/\burl="([^"]+)"/i);
            sourceUrl = ua ? ua[1].trim() : '';
            sourceName = decodeEntities(stripCdata(sMatch[2])).trim();
        }

        items.push({
            title: tagContent(block, 'title'),
            link: tagContent(block, 'link'),
            pubDate: tagContent(block, 'pubDate'),
            description: tagContent(block, 'description').replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim(),
            sourceUrl,
            sourceName
        });
    }
    return items;
}

function googleNewsUrl(facility) {
    const nameToken = `"${facility.queryName}"`;
    const stateToken = (facility.state && STATE_NAMES[facility.state]) ? `"${STATE_NAMES[facility.state]}"` : '';
    const query = [nameToken, stateToken,
        '(abuse OR lawsuit OR arrested OR indicted OR investigation OR closure OR raid OR allegations OR survivor)'
    ].filter(Boolean).join(' ');
    const params = new URLSearchParams({ q: query, hl: 'en-US', gl: 'US', ceid: 'US:en' });
    return `https://news.google.com/rss/search?${params.toString()}`;
}

async function fetchGoogleNewsForFacility(facility) {
    try {
        const xml = await fetchText(googleNewsUrl(facility));
        return parseRssItems(xml).slice(0, PER_FACILITY_CAP).map(item => ({
            ...item,
            origin: 'google-news',
            facilityQuery: facility.queryName,
            facilityState: facility.state,
            facilityCity: facility.city
        }));
    } catch (err) {
        warn(`  ! Google News failed for "${facility.queryName}": ${err.message}`);
        return [];
    }
}

async function fetchRedditCandidates() {
    const url = 'https://www.reddit.com/r/troubledteens/new.json?limit=100';
    try {
        const res = await fetchWithTimeout(url);
        if (!res.ok) throw new Error(`HTTP ${res.status}`);
        const json = await res.json();
        const posts = (json.data && json.data.children) || [];
        const out = [];

        for (const p of posts) {
            const post = p.data || {};
            const title = post.title || '';
            const permalink = post.permalink ? `https://www.reddit.com${post.permalink}` : '';
            const created = post.created_utc ? new Date(post.created_utc * 1000).toUTCString() : '';

            // Link posts
            if (post.url && !post.is_self) {
                const h = hostOf(post.url);
                if (h && !HARD_BLOCKED_HOSTS.has(h)) {
                    out.push({
                        title, link: post.url, pubDate: created,
                        description: (post.selftext || '').slice(0, 500),
                        sourceUrl: '', sourceName: '',
                        origin: 'reddit-link',
                        facilityQuery: null, facilityState: '', facilityCity: '',
                        redditPermalink: permalink
                    });
                }
            }

            // External URLs in body — stop at whitespace, brackets, quotes, and
            // markdown delimiters (*, _, `, [, ])
            if (post.selftext) {
                const urls = post.selftext.match(/https?:\/\/[^\s()<>"'\[\]*_`]+/g) || [];
                const dedup = new Set();
                for (const raw of urls) {
                    const u = raw.replace(/[.,;:!?*_`)\]]+$/, '');
                    const h = hostOf(u);
                    if (!h || HARD_BLOCKED_HOSTS.has(h) || dedup.has(u)) continue;
                    dedup.add(u);
                    out.push({
                        title, link: u, pubDate: created,
                        description: post.selftext.slice(0, 500),
                        sourceUrl: '', sourceName: '',
                        origin: 'reddit-selftext',
                        facilityQuery: null, facilityState: '', facilityCity: '',
                        redditPermalink: permalink
                    });
                }
            }
        }
        return out;
    } catch (err) {
        warn(`  ! Reddit fetch failed: ${err.message}`);
        return [];
    }
}

// ============================================================
// Scoring + state-match validation
// ============================================================

/**
 * Match candidate text against the facility index. Returns the longest
 * matched alias (favors specificity) or null. Uses simple
 * non-alphanumeric-boundary checks to avoid substring false positives.
 */
function matchFacility(text, facilityIndex) {
    const hay = ' ' + text.toLowerCase() + ' ';
    let best = null;

    for (const fac of facilityIndex) {
        for (const alias of fac.aliases) {
            const needle = alias.toLowerCase().trim();
            if (needle.length < 5) continue;     // too-short = noise
            const padded = ` ${needle} `;
            if (hay.includes(padded) ||
                hay.includes(' ' + needle + ',') ||
                hay.includes(' ' + needle + '.') ||
                hay.includes(' ' + needle + "'") ||
                hay.includes(' ' + needle + ':')) {
                if (!best || alias.length > best.matchedAlias.length) {
                    best = { facility: fac, matchedAlias: alias };
                }
            }
        }
    }
    return best;
}

function extractStateSignals(text) {
    const found = new Set();
    if (!text) return found;
    const padded = ' ' + text + ' ';
    // Full state names (word-boundary, case-insensitive)
    for (const [abbr, full] of Object.entries(STATE_NAMES)) {
        const re = new RegExp(`\\b${full}\\b`, 'i');
        if (re.test(padded)) found.add(abbr);
    }
    // Two-letter abbreviations as standalone tokens, e.g. ", UT " or " (UT)"
    for (const abbr of Object.keys(STATE_NAMES)) {
        const re = new RegExp(`(?:[\\s,(])${abbr}(?:[\\s,.)])`);
        if (re.test(padded)) found.add(abbr);
    }
    return found;
}

function countAbuseKeywords(text) {
    const hay = text.toLowerCase();
    let n = 0;
    for (const kw of ABUSE_KEYWORDS) {
        if (hay.includes(kw)) n++;
    }
    return n;
}

/**
 * Decide whether a candidate clears the filter. Returns:
 *   { accept: true,  score, reasons[], match }            — submit
 *   { accept: false, reason, meta }                       — log + drop
 *
 * Scoring rules (all additive unless noted):
 *   +3   abuse keyword in title
 *   +1   abuse keyword in description (only if not already in title)
 *   +2   facility alias matched
 *   +2   matched facility's city appears in text (city-level boost)
 *   +1   reddit-link origin (someone thought it worth sharing)
 *   reject (no score) if blacklist hit or HARD_BLOCKED host
 *   reject (no score) if facility match but state CONTRADICTS facility's state
 */
function evaluateCandidate(candidate, facilityIndex, blacklist, facilityOwnHosts, genericAliases) {
    const text = `${candidate.title} ${candidate.description}`;
    const reasons = [];
    let score = 0;

    // Blacklist / hard-blocked. For Google News candidates the host check uses
    // sourceUrl (the publisher), not link (the news.google.com redirect).
    const candHost = hostOf(candidate.sourceUrl || candidate.link);
    if (!candHost) {
        return { accept: false, reason: 'invalid-url', meta: { link: candidate.link } };
    }
    if (blacklist.hostBlocked(candHost)) {
        return { accept: false, reason: 'blacklist-host', meta: { host: candHost } };
    }
    // Reject candidates that link to a facility's own website / marketing page.
    // For Reddit body links we have the raw URL; for Google News we check the
    // publisher (sourceUrl) and won't catch a facility hosting on a wire here,
    // but those are exceedingly rare.
    const articleHost = articleHostOf(candidate.link);
    if (articleHost && facilityOwnHosts.has(articleHost)) {
        return { accept: false, reason: 'facility-own-website', meta: { host: articleHost } };
    }
    // Path-pattern blacklist (e.g., wire-syndicated paths). For Google News
    // this only catches direct hits — the canonical-URL check happens again
    // after URL resolution.
    const pathHit = blacklist.pathBlocked(candidate.link);
    if (pathHit) {
        return { accept: false, reason: 'blacklist-path', meta: { pattern: pathHit, link: candidate.link } };
    }

    // Facility match
    const match = matchFacility(text, facilityIndex);
    let cityMatched = false;

    if (match) {
        const fac = match.facility;

        // Inferred facility-own-website check: if the candidate's host (SLD,
        // i.e. host without the public TLD) is a substring of the matched
        // facility's normalized name or vice versa, treat as the facility's
        // own site. Catches cases the API's profileLinks data doesn't cover.
        const candArticleHost = articleHostOf(candidate.link);
        if (candArticleHost) {
            const sld = candArticleHost.split('.').slice(0, -1).join('.').replace(/[^a-z0-9]/g, '');
            const normName = normalizeName(match.matchedAlias).replace(/\s+/g, '');
            if (sld && normName && sld.length >= 6 &&
                (normName.includes(sld) || sld.includes(normName))) {
                return {
                    accept: false,
                    reason: 'facility-own-website-inferred',
                    meta: { host: candArticleHost, matchedAlias: match.matchedAlias, facility: fac.queryName }
                };
            }
        }

        score += 2;
        reasons.push(`facility:${match.matchedAlias}`);

        // City match boost
        if (fac.city && text.toLowerCase().includes(fac.city.toLowerCase())) {
            score += 2;
            cityMatched = true;
            reasons.push(`city:${fac.city}`);
        }

        // State-match validation (skip for operators and entries without a known state)
        if (!cityMatched && fac.state && fac.bucket !== 'operator') {
            const signals = extractStateSignals(text);
            if (signals.size > 0 && !signals.has(fac.state)) {
                return {
                    accept: false,
                    reason: 'state-mismatch',
                    meta: {
                        matchedAlias: match.matchedAlias,
                        facility: fac.queryName,
                        expectedState: fac.state,
                        detectedStates: Array.from(signals)
                    }
                };
            }
            // Generic alias (shared by N+ facilities) requires POSITIVE state
            // confirmation, not just absence of mismatch. Without it, names
            // like "Juvenile Detention Center" would match unrelated facilities
            // in articles that don't happen to mention any state.
            if (genericAliases && genericAliases.has(normalizeName(match.matchedAlias)) && !signals.has(fac.state)) {
                return {
                    accept: false,
                    reason: 'generic-alias-unconfirmed',
                    meta: {
                        matchedAlias: match.matchedAlias,
                        facility: fac.queryName,
                        expectedState: fac.state,
                        detectedStates: Array.from(signals)
                    }
                };
            }
        }
    }

    // Abuse keyword scoring (title weighted higher than description)
    const titleLower = candidate.title.toLowerCase();
    const titleHit = ABUSE_KEYWORDS.find(k => titleLower.includes(k));
    if (titleHit) {
        score += 3;
        reasons.push(`title-kw:${titleHit}`);
    } else {
        const descHit = ABUSE_KEYWORDS.find(k => candidate.description.toLowerCase().includes(k));
        if (descHit) {
            score += 1;
            reasons.push(`desc-kw:${descHit}`);
        }
    }

    // Reddit link-post boost
    if (candidate.origin === 'reddit-link') {
        score += 1;
        reasons.push('reddit-link-post');
    }

    if (score >= SCORE_THRESHOLD) {
        return { accept: true, score, reasons, match: match ? { alias: match.matchedAlias, facility: match.facility.queryName, state: match.facility.state, city: match.facility.city, bucket: match.facility.bucket } : null };
    }
    return {
        accept: false,
        reason: 'low-score',
        meta: { score, reasons, threshold: SCORE_THRESHOLD, host: candHost }
    };
}

// ============================================================
// Google News URL resolution
// ============================================================

/**
 * Resolve a news.google.com redirect URL to its canonical publisher URL.
 *
 * Google News RSS items have links like
 *   https://news.google.com/rss/articles/CBMi...?oc=5
 * which redirect (via 302 → consent → article) to the actual article URL.
 * Resolving them up front gives us:
 *   - accurate dedup (same article won't be submitted under two redirect tokens)
 *   - clean URL in the submission record
 *   - ability to run path-pattern blacklist on the real URL
 *
 * On error or no-redirect, returns the original URL unchanged.
 */
async function resolveGoogleNewsUrl(url) {
    if (!url || !url.startsWith('https://news.google.com/')) return url;
    try {
        // Use GET with redirect: 'follow' — HEAD is unreliable on some Google
        // redirects. The response body is discarded; we only need response.url.
        const controller = new AbortController();
        const timer = setTimeout(() => controller.abort(), 15000);
        try {
            const res = await fetch(url, {
                method: 'GET',
                redirect: 'follow',
                signal: controller.signal,
                headers: { 'User-Agent': USER_AGENT }
            });
            // Drain a small amount of the body so the connection can close
            // cleanly (don't read large response bodies into memory).
            try { await res.body?.cancel(); } catch { /* ignore */ }
            const finalUrl = res.url || url;
            if (finalUrl && finalUrl !== url && !finalUrl.startsWith('https://news.google.com/')) {
                return finalUrl;
            }
        } finally {
            clearTimeout(timer);
        }
    } catch (err) {
        warn(`    URL resolution failed for ${url.slice(0, 80)}: ${err.message}`);
    }
    return url;
}

// ============================================================
// Submission
// ============================================================

async function submitCandidate(candidate, evalResult) {
    log(`  → AI processing: ${candidate.link.slice(0, 90)}`);
    const aiRes = await postJson(AI_ENDPOINT, {
        url: candidate.link,
        provider: AI_PROVIDER,
        customInstructions: ''
    }, { timeoutMs: AI_TIMEOUT_MS });

    if (!aiRes.ok || !aiRes.body || !aiRes.body.success) {
        const err = (aiRes.body && (aiRes.body.error || aiRes.body.rawText)) || `HTTP ${aiRes.status}`;
        warn(`    AI failed: ${String(err).slice(0, 200)}`);
        return { ok: false, stage: 'ai', error: String(err).slice(0, 500) };
    }

    const data = aiRes.body.data || {};

    const discoveryNote = [
        `auto-discovery via ${candidate.origin}`,
        `score=${evalResult.score}`,
        `reasons=${evalResult.reasons.join(',')}`,
        evalResult.match ? `match=${JSON.stringify(evalResult.match)}` : '',
        candidate.facilityQuery ? `query=${candidate.facilityQuery}` : '',
        candidate.redditPermalink ? `reddit=${candidate.redditPermalink}` : ''
    ].filter(Boolean).join(' | ');

    const submission = {
        title: data.title || candidate.title || '(untitled)',
        alternateTitle: data.alternateTitle || '',
        author: data.author || '',
        publicationName: data.publicationName || candidate.sourceName || '',
        publicationDate: data.publicationDate || '',
        url: candidate.link,
        location: data.location || '',
        tags: Array.isArray(data.tags) ? data.tags.join('\n') : (data.tags || ''),
        articleType: data.articleType || 'general',
        facilities: Array.isArray(data.facilities) ? data.facilities.join('\n') : (data.facilities || ''),
        staff: Array.isArray(data.staff) ? data.staff.join('\n') : (data.staff || ''),
        survivors: Array.isArray(data.survivors) ? data.survivors.join('\n') : (data.survivors || ''),
        contentWarnings: data.contentWarnings || [],
        summary: data.summary || '',
        needsAlternateTitle: !!data.alternateTitle,
        ...(data.typeSpecificData || {}),
        status: 'submitted',
        submittedBy: 'auto-discovery',
        submissionNotes: discoveryNote
    };

    const subRes = await postJson(SUBMIT_ENDPOINT, submission);
    if (!subRes.ok || !subRes.body || !subRes.body.success) {
        const err = (subRes.body && (subRes.body.error || subRes.body.rawText)) || `HTTP ${subRes.status}`;
        warn(`    Submit failed: ${String(err).slice(0, 200)}`);
        return { ok: false, stage: 'submit', error: String(err).slice(0, 500) };
    }
    log(`    ✓ submission id=${subRes.body.id}`);
    return { ok: true, id: subRes.body.id };
}

// ============================================================
// Main
// ============================================================

async function main() {
    log(`Discovery starting (${new Date().toISOString()})`);
    log(`  API base:  ${API_BASE}`);
    log(`  Dry run:   ${DRY_RUN}`);
    if (Number.isFinite(SUBMIT_LIMIT)) log(`  Submit limit: ${SUBMIT_LIMIT}`);
    if (MAX_FACILITIES) log(`  Max facilities: ${MAX_FACILITIES}`);

    const state = loadState();
    const seen = new Set(state.seenUrls);
    const blacklist = buildBlacklistMatcher();

    // -- Fetch facility data live from API --
    log('\nFetching facilities from API...');
    const facJson = await (async () => {
        const res = await fetchWithTimeout(FACILITIES_URL, { timeoutMs: 60000 });
        if (!res.ok) throw new Error(`Facilities API HTTP ${res.status}`);
        return res.json();
    })();
    const { facilities: facilityIndex, ownHosts: facilityOwnHosts, genericAliases } = buildFacilityIndex(facJson);
    log(`  built facility index: ${facilityIndex.length} unique active entries`);
    log(`  facility-owned hosts: ${facilityOwnHosts.size} (skipped as candidates)`);
    log(`  generic aliases:      ${genericAliases.size} (require positive state-signal match)`);

    // -- Today's shard (1/N of the active list) --
    const today = dayOfYearUTC();
    const shardIndex = today % SHARD_COUNT;
    const todaysShard = facilityIndex.filter((_, i) => i % SHARD_COUNT === shardIndex);
    const slice = MAX_FACILITIES ? todaysShard.slice(0, MAX_FACILITIES) : todaysShard;
    log(`  today = shard ${shardIndex}/${SHARD_COUNT} → ${slice.length} facilities to query` +
        (MAX_FACILITIES ? ` (capped from ${todaysShard.length})` : ''));

    const candidates = [];

    // -- Reddit pass --
    log('\nPolling Reddit...');
    const redditItems = await fetchRedditCandidates();
    log(`  ${redditItems.length} reddit items`);
    candidates.push(...redditItems);

    // -- Google News per facility (with politeness delay) --
    log('\nQuerying Google News per facility...');
    for (let i = 0; i < slice.length; i++) {
        const fac = slice[i];
        await sleep(RSS_REQUEST_DELAY_MS);
        const items = await fetchGoogleNewsForFacility(fac);
        if (items.length > 0) log(`  [${fac.queryName}${fac.state ? ' / ' + fac.state : ''}] ${items.length} items`);
        candidates.push(...items);
        if ((i + 1) % 25 === 0) log(`  ...${i + 1}/${slice.length} (running total: ${candidates.length})`);
    }

    log(`\nTotal raw candidates: ${candidates.length}`);
    state.stats.discovered += candidates.length;

    // -- Dedupe + filter --
    const queue = [];
    const rejected = [];
    const dedupeSeen = new Set();

    for (const c of candidates) {
        if (!c.link || !c.link.startsWith('http')) continue;
        const h = hashUrl(c.link);
        if (seen.has(h) || dedupeSeen.has(h)) continue;
        dedupeSeen.add(h);

        const result = evaluateCandidate(c, facilityIndex, blacklist, facilityOwnHosts, genericAliases);
        if (result.accept) {
            queue.push({ candidate: c, evalResult: result, urlHash: h });
        } else {
            state.stats.rejected += 1;
            rejected.push({
                link: c.link,
                title: c.title,
                origin: c.origin,
                host: hostOf(c.sourceUrl || c.link),
                facilityQuery: c.facilityQuery || null,
                reason: result.reason,
                meta: result.meta || null
            });
        }
    }
    log(`After filter: ${queue.length} accepted, ${rejected.length} rejected (threshold ${SCORE_THRESHOLD})`);

    // -- Persist rejected log right away (useful even if submit phase aborts) --
    if (rejected.length) persistRejected(rejected);

    if (DRY_RUN) {
        log('\n--- DRY RUN — would submit (showing up to 30) ---');
        for (const q of queue.slice(0, 30)) {
            log(`  [score ${q.evalResult.score}] ${q.candidate.link}`);
            log(`     title:   ${q.candidate.title.slice(0, 100)}`);
            log(`     reasons: ${q.evalResult.reasons.join(', ')}`);
            if (q.evalResult.match) log(`     match:   ${JSON.stringify(q.evalResult.match)}`);
        }
        log(`\nDry run done. Would attempt ${Math.min(queue.length, SUBMIT_LIMIT)} submissions.`);
        return;
    }

    // -- Submit --
    // Each accepted candidate goes through:
    //   1. Resolve Google News redirect → canonical URL
    //   2. Re-check dedup, host blacklist, path blacklist, facility-own-website
    //      on the canonical URL (Google News hid the real URL from earlier checks)
    //   3. AI process → submit
    let submitted = 0, submitErrors = 0, postResolveRejected = 0;
    const postResolveLog = [];

    for (const q of queue) {
        if (submitted >= SUBMIT_LIMIT) break;

        // Resolve if it's a Google News redirect (no-op otherwise)
        const originalLink = q.candidate.link;
        const resolvedLink = await resolveGoogleNewsUrl(originalLink);

        if (resolvedLink !== originalLink) {
            // Re-dedup against the canonical URL — same article may appear under
            // multiple Google News redirect tokens.
            const newHash = hashUrl(resolvedLink);
            if (seen.has(newHash)) {
                seen.add(q.urlHash);  // also record the redirect token as seen
                postResolveRejected++;
                postResolveLog.push({ link: originalLink, resolvedTo: resolvedLink, reason: 'duplicate-after-resolution' });
                continue;
            }

            // Re-check blacklists on the canonical URL
            const newHost = articleHostOf(resolvedLink);
            if (newHost && blacklist.hostBlocked(newHost)) {
                seen.add(q.urlHash);
                postResolveRejected++;
                postResolveLog.push({ link: originalLink, resolvedTo: resolvedLink, reason: 'blacklist-host-post-resolve', host: newHost });
                continue;
            }
            if (newHost && facilityOwnHosts.has(newHost)) {
                seen.add(q.urlHash);
                postResolveRejected++;
                postResolveLog.push({ link: originalLink, resolvedTo: resolvedLink, reason: 'facility-own-website-post-resolve', host: newHost });
                continue;
            }
            const pathHit = blacklist.pathBlocked(resolvedLink);
            if (pathHit) {
                seen.add(q.urlHash);
                postResolveRejected++;
                postResolveLog.push({ link: originalLink, resolvedTo: resolvedLink, reason: 'blacklist-path-post-resolve', pattern: pathHit });
                continue;
            }

            // Update candidate with canonical URL for submission
            q.candidate.link = resolvedLink;
            // Also mark the canonical URL as seen so a future run won't re-submit it
            seen.add(newHash);
        }

        // Mark the original/canonical link as seen before attempting submission,
        // so a flaky URL isn't retried every run.
        seen.add(q.urlHash);

        await sleep(AI_REQUEST_DELAY_MS);
        const r = await submitCandidate(q.candidate, q.evalResult);
        if (r.ok) {
            submitted++;
            state.stats.submitted += 1;
        } else {
            submitErrors++;
        }
    }

    if (postResolveLog.length) {
        state.stats.rejected += postResolveLog.length;
        persistRejected(postResolveLog);
    }

    state.seenUrls = Array.from(seen);
    state.lastRun = new Date().toISOString();
    saveState(state);

    log(`\n--- Done ---`);
    log(`  facilities queried:  ${slice.length}`);
    log(`  candidates found:    ${candidates.length}`);
    log(`  accepted by filter:  ${queue.length}`);
    log(`  submitted (ok):      ${submitted}`);
    log(`  submitted (errors):  ${submitErrors}`);
    log(`  rejected (pre-fetch):  ${rejected.length}`);
    log(`  rejected (post-resolve): ${postResolveRejected}`);
    log(`  cumulative stats:    ${JSON.stringify(state.stats)}`);

    if (submitErrors > 0 && submitted === 0) process.exitCode = 1;
}

main().catch(err => {
    console.error('Discovery failed:', err);
    process.exit(1);
});
