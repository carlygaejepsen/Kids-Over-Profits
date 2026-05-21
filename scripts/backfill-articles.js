#!/usr/bin/env node
/**
 * Article Backfill (historical)
 *
 * Companion to discover-articles.js. The daily discovery cron is forward-
 * looking (Google News RSS + r/troubledteens /new.json) and only catches
 * articles published recently. This script does the opposite — it sweeps
 * BACK in time for each facility.
 *
 * Two sources, both keyword-searched by facility name:
 *
 *   1. Reddit subreddit search — `/r/troubledteens/search.json?q=...&sort=top&t=all`
 *      Surfaces ~12 years of survivors / journalists / advocates posting
 *      article links. Free, no auth, paginated via `after` cursor.
 *
 *   2. GDELT 2.0 Doc API — `https://api.gdeltproject.org/api/v2/doc/doc`
 *      Indexes English-language news globally back to 2017, with proper
 *      date-range filtering and canonical publisher URLs. Free, no API key.
 *
 * Candidates from both sources flow through the SAME filter + resolver +
 * AI + submit pipeline used by the daily cron, so dedup works against the
 * existing seen-URL state and rows land in news_submissions with
 * submittedBy='backfill-discovery'.
 *
 * Usage:
 *   node scripts/backfill-articles.js                          # ALL active facilities (slow!)
 *   node scripts/backfill-articles.js --state=UT               # one state's facilities
 *   node scripts/backfill-articles.js --facility="Provo Canyon" # one specific name (substring match)
 *   node scripts/backfill-articles.js --max-facilities=5 --dry-run
 *   node scripts/backfill-articles.js --since=2018-01-01       # restrict GDELT lookback
 *   node scripts/backfill-articles.js --skip-reddit             # GDELT only
 *   node scripts/backfill-articles.js --skip-gdelt              # Reddit only
 *
 * Environment: same as discover-articles.js (NEWS_API_BASE, AI_PROVIDER).
 */

'use strict';

const fs = require('fs');
const path = require('path');
const https = require('https');
const urlMod = require('url');
const lib = require('./discover-articles.js');

// GDELT's TLS handshake uses cipher suites that Node's default SECLEVEL=2
// rejects, even though curl talks to it fine. Build a permissive agent we
// can pass to https.get() for GDELT specifically. The rest of the script
// uses normal fetchWithTimeout from discover-articles.js.
const GDELT_AGENT = new https.Agent({
    keepAlive: false,
    ciphers: 'DEFAULT@SECLEVEL=0'
});

/**
 * GET a URL via the Node https module with a permissive-cipher agent.
 * Returns a Promise<{status, body}>.
 */
function gdeltHttpsGet(url, { timeoutMs = 45000 } = {}) {
    return new Promise((resolve, reject) => {
        const opts = urlMod.parse(url);
        opts.agent = GDELT_AGENT;
        opts.headers = { 'User-Agent': lib.USER_AGENT, 'Accept': 'application/json,*/*;q=0.1' };
        const req = https.get(opts, res => {
            const chunks = [];
            res.on('data', c => chunks.push(c));
            res.on('end', () => resolve({ status: res.statusCode || 0, body: Buffer.concat(chunks).toString('utf8') }));
        });
        req.on('error', err => reject(err));
        req.setTimeout(timeoutMs, () => {
            req.destroy(new Error(`GDELT request timed out after ${timeoutMs}ms`));
        });
    });
}
const {
    USER_AGENT, STATE_NAMES, SCORE_THRESHOLD, AI_REQUEST_DELAY_MS,
    sleep, log, warn, fetchWithTimeout, fetchText, postJson,
    loadJson, saveJson, hashUrl, hostOf, articleHostOf,
    buildBlacklistMatcher, buildFacilityIndex,
    evaluateCandidate, resolveGoogleNewsUrl, submitCandidate,
    loadState, saveState, persistRejected
} = lib;

// ============================================================
// Paths & config
// ============================================================

const API_BASE = (process.env.NEWS_API_BASE || 'https://kidsoverprofits.org').replace(/\/$/, '');
const FACILITIES_URL = `${API_BASE}/wp-json/kop/v1/facilities`;

const REDDIT_SEARCH_DELAY_MS = 2000;     // be polite — Reddit rate-limits anonymous
const GDELT_DELAY_MS         = 1500;     // gdelt is fine with ~1s
const REDDIT_PAGES_PER_QUERY = 3;        // ~75 posts per facility (25/page)
const GDELT_MAX_RECORDS      = 100;
const PER_FACILITY_CAP       = 60;       // candidates considered per facility per source

// CLI flags
const args = process.argv.slice(2);
function flag(name) { return args.includes(`--${name}`); }
function arg(name, fallback = null) {
    const direct = args.find(a => a.startsWith(`--${name}=`));
    if (direct) return direct.slice(name.length + 3);
    const idx = args.indexOf(`--${name}`);
    if (idx > -1 && idx + 1 < args.length && !args[idx + 1].startsWith('--')) return args[idx + 1];
    return fallback;
}

const DRY_RUN        = flag('dry-run');
const SKIP_REDDIT    = flag('skip-reddit');
const SKIP_GDELT     = flag('skip-gdelt');
const STATE_FILTER   = arg('state');                 // e.g. UT
const FACILITY_QUERY = arg('facility');               // substring match against queryName
const SINCE_DATE     = arg('since', '2017-01-01');    // GDELT lower bound
const MAX_FAC        = arg('max-facilities') ? parseInt(arg('max-facilities'), 10) : null;
const SUBMIT_LIMIT   = arg('limit') ? parseInt(arg('limit'), 10) : Infinity;

// ============================================================
// Source: Reddit subreddit search (paginated)
// ============================================================

async function fetchRedditSearchForFacility(facility) {
    const out = [];
    const query = `"${facility.queryName}"`;
    let after = null;

    for (let page = 0; page < REDDIT_PAGES_PER_QUERY; page++) {
        const params = new URLSearchParams({
            q: query,
            restrict_sr: '1',
            sort: 'top',
            t: 'all',
            limit: '25',
            include_over_18: 'on'
        });
        if (after) params.set('after', after);
        const url = `https://www.reddit.com/r/troubledteens/search.json?${params.toString()}`;

        try {
            const res = await fetchWithTimeout(url, {
                headers: { 'User-Agent': USER_AGENT }
            });
            if (!res.ok) {
                if (res.status === 429) warn(`  ! Reddit rate-limited (429) for "${facility.queryName}" — backing off`);
                else warn(`  ! Reddit search HTTP ${res.status} for "${facility.queryName}"`);
                break;
            }
            const json = await res.json();
            const posts = (json.data && json.data.children) || [];
            after = json.data && json.data.after;

            for (const child of posts) {
                const post = child.data || {};
                const title = post.title || '';
                const permalink = post.permalink ? `https://www.reddit.com${post.permalink}` : '';
                const created = post.created_utc ? new Date(post.created_utc * 1000).toUTCString() : '';

                if (post.url && !post.is_self) {
                    const h = hostOf(post.url);
                    if (h && !isInternalRedditHost(h)) {
                        out.push({
                            title, link: post.url, pubDate: created,
                            description: (post.selftext || '').slice(0, 500),
                            sourceUrl: '', sourceName: '',
                            origin: 'reddit-search',
                            facilityQuery: facility.queryName,
                            facilityState: facility.state,
                            facilityCity: facility.city,
                            redditPermalink: permalink
                        });
                    }
                }
                if (post.selftext) {
                    const urls = post.selftext.match(/https?:\/\/[^\s()<>"'\[\]*_`]+/g) || [];
                    const dedup = new Set();
                    for (const raw of urls) {
                        const u = raw.replace(/[.,;:!?*_`)\]]+$/, '');
                        const h = hostOf(u);
                        if (!h || isInternalRedditHost(h) || dedup.has(u)) continue;
                        dedup.add(u);
                        out.push({
                            title, link: u, pubDate: created,
                            description: post.selftext.slice(0, 500),
                            sourceUrl: '', sourceName: '',
                            origin: 'reddit-search-selftext',
                            facilityQuery: facility.queryName,
                            facilityState: facility.state,
                            facilityCity: facility.city,
                            redditPermalink: permalink
                        });
                    }
                }
            }
            if (!after) break;        // no more pages
            await sleep(REDDIT_SEARCH_DELAY_MS);
        } catch (err) {
            warn(`  ! Reddit search failed for "${facility.queryName}": ${err.message}`);
            break;
        }
    }
    return out.slice(0, PER_FACILITY_CAP);
}

function isInternalRedditHost(h) {
    const REDDIT_INTERNAL = new Set([
        'reddit.com', 'redd.it', 'i.redd.it', 'v.redd.it', 'preview.redd.it',
        'imgur.com', 'i.imgur.com',
        'youtube.com', 'youtu.be', 'tiktok.com', 'instagram.com',
        'twitter.com', 'x.com', 'discord.gg', 'discord.com',
        't.me', 'telegram.me'
    ]);
    return REDDIT_INTERNAL.has(h);
}

// ============================================================
// Source: GDELT 2.0 Doc API
// ============================================================

/**
 * Build a GDELT date filter (YYYYMMDDHHMMSS format) from an ISO date string.
 */
function gdeltDateTime(isoDate, endOfDay = false) {
    const m = isoDate.match(/^(\d{4})-(\d{2})-(\d{2})$/);
    if (!m) return null;
    return `${m[1]}${m[2]}${m[3]}${endOfDay ? '235959' : '000000'}`;
}

async function fetchGdeltForFacility(facility) {
    // Query: just facility name + state, scoped to US sources. We deliberately
    // DON'T add abuse keywords here — the post-filter handles that and dropping
    // them roughly 5-10x's the result count. Same article corpus the filter
    // then trims.
    const stateClause = (facility.state && STATE_NAMES[facility.state])
        ? `"${STATE_NAMES[facility.state]}"`
        : '';
    const queryParts = [`"${facility.queryName}"`, stateClause].filter(Boolean);
    const q = queryParts.join(' ') + ' sourcecountry:US';

    const params = new URLSearchParams({
        query: q,
        mode: 'ArtList',
        format: 'json',
        maxrecords: String(GDELT_MAX_RECORDS),
        sort: 'DateDesc'
    });
    const start = gdeltDateTime(SINCE_DATE);
    const end   = gdeltDateTime(new Date().toISOString().slice(0, 10), true);
    if (start) params.set('startdatetime', start);
    if (end)   params.set('enddatetime', end);

    const url = `https://api.gdeltproject.org/api/v2/doc/doc?${params.toString()}`;

    try {
        // GDELT can be slow (15-25s). Use the permissive-cipher Node https
        // path because undici's default TLS settings reject GDELT's handshake.
        const { status, body: text } = await gdeltHttpsGet(url, { timeoutMs: 45000 });
        if (status !== 200) {
            warn(`  ! GDELT HTTP ${status} for "${facility.queryName}"`);
            return [];
        }
        if (!text || text.trim() === '') {
            log(`  gdelt 0 results for "${facility.queryName}"`);
            return [];
        }
        let json;
        try { json = JSON.parse(text); }
        catch (err) {
            warn(`  ! GDELT non-JSON response for "${facility.queryName}": ${text.slice(0, 120)}`);
            return [];
        }

        const articles = Array.isArray(json.articles) ? json.articles : [];
        if (articles.length === 0) log(`  gdelt 0 results for "${facility.queryName}"`);
        return articles.slice(0, PER_FACILITY_CAP).map(a => ({
            title: a.title || '',
            link: a.url || '',
            pubDate: a.seendate ? gdeltSeendateToHttpDate(a.seendate) : '',
            description: '',                                   // GDELT doesn't return snippets
            sourceUrl: a.url_mobile || '',
            sourceName: a.domain || '',
            origin: 'gdelt',
            facilityQuery: facility.queryName,
            facilityState: facility.state,
            facilityCity: facility.city
        })).filter(it => it.link);
    } catch (err) {
        warn(`  ! GDELT fetch failed for "${facility.queryName}": ${err.message}`);
        return [];
    }
}

function gdeltSeendateToHttpDate(s) {
    // GDELT seendate format: "20180312T140523Z" → "Mon, 12 Mar 2018 14:05:23 GMT"
    const m = String(s || '').match(/^(\d{4})(\d{2})(\d{2})T(\d{2})(\d{2})(\d{2})Z?$/);
    if (!m) return '';
    const d = new Date(Date.UTC(+m[1], +m[2] - 1, +m[3], +m[4], +m[5], +m[6]));
    return isFinite(d.getTime()) ? d.toUTCString() : '';
}

// ============================================================
// Main
// ============================================================

async function main() {
    log(`Backfill starting (${new Date().toISOString()})`);
    log(`  API base:   ${API_BASE}`);
    log(`  Dry run:    ${DRY_RUN}`);
    log(`  Sources:    ${[SKIP_REDDIT ? '' : 'reddit-search', SKIP_GDELT ? '' : 'gdelt'].filter(Boolean).join(' + ') || '(none — both skipped)'}`);
    if (STATE_FILTER)   log(`  State filter:   ${STATE_FILTER}`);
    if (FACILITY_QUERY) log(`  Facility filter: "${FACILITY_QUERY}" (substring)`);
    log(`  Since date: ${SINCE_DATE} (GDELT lower bound)`);
    if (MAX_FAC) log(`  Max facilities: ${MAX_FAC}`);
    if (Number.isFinite(SUBMIT_LIMIT)) log(`  Submit limit:   ${SUBMIT_LIMIT}`);

    const state = loadState();
    const seen = new Set(state.seenUrls);
    const blacklist = buildBlacklistMatcher();

    // -- Fetch facility data live from API --
    log('\nFetching facilities from API...');
    const facRes = await fetchWithTimeout(FACILITIES_URL, { timeoutMs: 60000 });
    if (!facRes.ok) throw new Error(`Facilities API HTTP ${facRes.status}`);
    const facJson = await facRes.json();

    const { facilities: facilityIndex, ownHosts: facilityOwnHosts, genericAliases } = buildFacilityIndex(facJson);
    log(`  total active facilities: ${facilityIndex.length}`);
    log(`  facility-owned hosts:    ${facilityOwnHosts.size}`);
    log(`  generic aliases:         ${genericAliases.size}`);

    // -- Apply CLI filters --
    let targets = facilityIndex;
    if (STATE_FILTER) {
        const want = STATE_FILTER.toUpperCase();
        targets = targets.filter(f => f.state === want);
    }
    if (FACILITY_QUERY) {
        const needle = FACILITY_QUERY.toLowerCase();
        targets = targets.filter(f =>
            f.queryName.toLowerCase().includes(needle) ||
            f.aliases.some(a => a.toLowerCase().includes(needle))
        );
    }
    if (MAX_FAC) targets = targets.slice(0, MAX_FAC);

    log(`  → targeting ${targets.length} facilit${targets.length === 1 ? 'y' : 'ies'} this run`);
    if (targets.length === 0) {
        log('Nothing to do.');
        return;
    }

    // -- Collect candidates from both sources --
    const candidates = [];
    for (let i = 0; i < targets.length; i++) {
        const fac = targets[i];
        const tag = `[${i + 1}/${targets.length}] ${fac.queryName}${fac.state ? '/' + fac.state : ''}`;

        if (!SKIP_REDDIT) {
            const reddit = await fetchRedditSearchForFacility(fac);
            if (reddit.length) log(`  ${tag}  reddit: ${reddit.length}`);
            candidates.push(...reddit);
            await sleep(REDDIT_SEARCH_DELAY_MS);
        }
        if (!SKIP_GDELT) {
            const gdelt = await fetchGdeltForFacility(fac);
            if (gdelt.length) log(`  ${tag}  gdelt:  ${gdelt.length}`);
            candidates.push(...gdelt);
            await sleep(GDELT_DELAY_MS);
        }
    }

    log(`\nTotal raw candidates: ${candidates.length}`);
    state.stats.discovered += candidates.length;

    // -- Filter (same logic as daily cron) --
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
    if (rejected.length) persistRejected(rejected);

    if (DRY_RUN) {
        log('\n--- DRY RUN — would submit (showing up to 30) ---');
        for (const q of queue.slice(0, 30)) {
            log(`  [score ${q.evalResult.score}] ${q.candidate.link}`);
            log(`     origin:  ${q.candidate.origin}`);
            log(`     title:   ${q.candidate.title.slice(0, 100)}`);
            log(`     reasons: ${q.evalResult.reasons.join(', ')}`);
            if (q.evalResult.match) log(`     match:   ${JSON.stringify(q.evalResult.match)}`);
        }
        log(`\nDry run done. Would attempt ${Math.min(queue.length, SUBMIT_LIMIT)} submissions.`);
        return;
    }

    // -- Submit (with GN URL resolution + post-resolve re-check, same as daily cron) --
    let submitted = 0, submitErrors = 0, postResolveRejected = 0;
    const postResolveLog = [];

    for (const q of queue) {
        if (submitted >= SUBMIT_LIMIT) break;

        const originalLink = q.candidate.link;
        const resolvedLink = await resolveGoogleNewsUrl(originalLink);

        if (resolvedLink.startsWith('https://news.google.com/')) {
            seen.add(q.urlHash);
            postResolveRejected++;
            postResolveLog.push({
                link: originalLink, reason: 'gn-resolution-failed',
                facilityQuery: q.candidate.facilityQuery || null
            });
            continue;
        }

        if (resolvedLink !== originalLink) {
            const newHash = hashUrl(resolvedLink);
            if (seen.has(newHash)) {
                seen.add(q.urlHash);
                postResolveRejected++;
                postResolveLog.push({ link: originalLink, resolvedTo: resolvedLink, reason: 'duplicate-after-resolution' });
                continue;
            }
            const newHost = articleHostOf(resolvedLink);
            if (newHost && blacklist.hostBlocked(newHost)) {
                seen.add(q.urlHash); postResolveRejected++;
                postResolveLog.push({ link: originalLink, resolvedTo: resolvedLink, reason: 'blacklist-host-post-resolve', host: newHost });
                continue;
            }
            if (newHost && facilityOwnHosts.has(newHost)) {
                seen.add(q.urlHash); postResolveRejected++;
                postResolveLog.push({ link: originalLink, resolvedTo: resolvedLink, reason: 'facility-own-website-post-resolve', host: newHost });
                continue;
            }
            const pathHit = blacklist.pathBlocked(resolvedLink);
            if (pathHit) {
                seen.add(q.urlHash); postResolveRejected++;
                postResolveLog.push({ link: originalLink, resolvedTo: resolvedLink, reason: 'blacklist-path-post-resolve', pattern: pathHit });
                continue;
            }
            q.candidate.link = resolvedLink;
            seen.add(newHash);
        }

        seen.add(q.urlHash);
        await sleep(AI_REQUEST_DELAY_MS);
        const r = await submitCandidate(q.candidate, q.evalResult, {
            submittedBy: 'backfill-discovery',
            noteLabel: 'backfill'
        });
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
    log(`  facilities queried:    ${targets.length}`);
    log(`  candidates collected:  ${candidates.length}`);
    log(`  accepted by filter:    ${queue.length}`);
    log(`  submitted (ok):        ${submitted}`);
    log(`  submitted (errors):    ${submitErrors}`);
    log(`  rejected (pre-fetch):  ${rejected.length}`);
    log(`  rejected (post-resolve): ${postResolveRejected}`);
    log(`  cumulative stats:      ${JSON.stringify(state.stats)}`);
}

main().catch(err => {
    console.error('Backfill failed:', err);
    process.exit(1);
});
