#!/usr/bin/env node
/**
 * Article Discovery
 *
 * Polls Google News RSS and Reddit r/troubledteens for new articles about
 * known TTI facilities, runs a cheap keyword filter, then feeds the surviving
 * candidates through the existing AI processor + submission queue.
 *
 * Usage:
 *   node scripts/discover-articles.js              # full run
 *   node scripts/discover-articles.js --dry-run    # discover + score, skip submission
 *   node scripts/discover-articles.js --limit 5    # cap candidates submitted this run
 *
 * Environment:
 *   NEWS_API_BASE          (default: https://kidsoverprofits.com)
 *   AI_PROVIDER            (default: groq)
 *   FACILITIES_PER_RUN     (default: 60 — rotates through programs-array.json)
 */

const fs = require('fs');
const path = require('path');
const crypto = require('crypto');

const ROOT = path.join(__dirname, '..');
const PROGRAMS_FILE = path.join(ROOT, 'js', 'data', 'reddit-wiki', 'programs-array.json');
const STATE_FILE = path.join(__dirname, '.discovery-state.json');
const REJECT_LOG = path.join(__dirname, '.discovery-rejected.log');

const args = process.argv.slice(2);
const DRY_RUN = args.includes('--dry-run');
const LIMIT_ARG = args.indexOf('--limit');
const SUBMIT_LIMIT = LIMIT_ARG > -1 ? parseInt(args[LIMIT_ARG + 1], 10) : Infinity;

const API_BASE = (process.env.NEWS_API_BASE || 'https://kidsoverprofits.com').replace(/\/$/, '');
const AI_ENDPOINT = `${API_BASE}/wp-content/themes/child/api/process-news-ai.php`;
const SUBMIT_ENDPOINT = `${API_BASE}/wp-content/themes/child/api/save-news-submission.php`;
const AI_PROVIDER = process.env.AI_PROVIDER || 'groq';
const FACILITIES_PER_RUN = parseInt(process.env.FACILITIES_PER_RUN || '60', 10);

const SCORE_THRESHOLD = 3;
const REQUEST_DELAY_MS = 1500;
const AI_REQUEST_DELAY_MS = 4000;
const USER_AGENT = 'kids-over-profits-discovery/1.0 (+https://kidsoverprofits.com)';
const MAX_SEEN_URLS = 50000;

const ABUSE_KEYWORDS = [
    'abuse', 'abused', 'abusing', 'arrest', 'arrested', 'indict', 'indicted', 'indictment',
    'charged', 'charges', 'lawsuit', 'sued', 'suing', 'investigation', 'investigates',
    'closed', 'closes', 'closure', 'shut down', 'shutdown', 'raided', 'raid',
    'pleads guilty', 'guilty plea', 'convicted', 'conviction', 'sentenced', 'sentencing',
    'allegation', 'allegations', 'alleged', 'misconduct', 'death', 'died', 'killed',
    'restraint', 'seclusion', 'neglect', 'assault', 'molest', 'molestation',
    'survivor', 'whistleblower', 'class action', 'settlement', 'fined', 'fine',
    'license revoked', 'license suspended', 'shuttered', 'felony', 'felonies'
];

const DOMAIN_BLOCKLIST = new Set([
    'twitter.com', 'x.com', 'facebook.com', 'instagram.com',
    'tiktok.com', 'youtube.com', 'youtu.be', 'reddit.com',
    'pinterest.com', 'quora.com', 'medium.com'
]);

// ============================================================
// State
// ============================================================

function loadState() {
    try {
        const raw = fs.readFileSync(STATE_FILE, 'utf8');
        const parsed = JSON.parse(raw);
        if (!Array.isArray(parsed.seenUrls)) parsed.seenUrls = [];
        if (typeof parsed.facilityCursor !== 'number') parsed.facilityCursor = 0;
        if (!parsed.stats) parsed.stats = { discovered: 0, submitted: 0, rejected: 0 };
        return parsed;
    } catch {
        return {
            version: 1,
            lastRun: null,
            facilityCursor: 0,
            seenUrls: [],
            stats: { discovered: 0, submitted: 0, rejected: 0 }
        };
    }
}

function saveState(state) {
    if (state.seenUrls.length > MAX_SEEN_URLS) {
        state.seenUrls = state.seenUrls.slice(-MAX_SEEN_URLS);
    }
    fs.writeFileSync(STATE_FILE, JSON.stringify(state, null, 2));
}

function appendReject(entry) {
    fs.appendFileSync(REJECT_LOG, JSON.stringify(entry) + '\n');
}

// ============================================================
// URL helpers
// ============================================================

function normalizeUrl(url) {
    try {
        const u = new URL(url);
        u.hash = '';
        // Strip common tracking params
        ['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term',
         'fbclid', 'gclid', 'mc_cid', 'mc_eid'].forEach(p => u.searchParams.delete(p));
        return (u.host.replace(/^www\./, '') + u.pathname + (u.search || '')).toLowerCase().replace(/\/$/, '');
    } catch {
        return url.toLowerCase();
    }
}

function hashUrl(url) {
    return crypto.createHash('sha256').update(normalizeUrl(url)).digest('hex').slice(0, 16);
}

function domainOf(url) {
    try {
        return new URL(url).host.replace(/^www\./, '').toLowerCase();
    } catch {
        return '';
    }
}

// ============================================================
// HTTP
// ============================================================

async function fetchText(url, opts = {}) {
    const res = await fetch(url, {
        headers: { 'User-Agent': USER_AGENT, ...(opts.headers || {}) },
        redirect: 'follow'
    });
    if (!res.ok) throw new Error(`HTTP ${res.status} for ${url}`);
    return res.text();
}

async function postJson(url, body) {
    const res = await fetch(url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'User-Agent': USER_AGENT },
        body: JSON.stringify(body)
    });
    const text = await res.text();
    let parsed;
    try { parsed = JSON.parse(text); } catch { parsed = { rawText: text }; }
    return { status: res.status, ok: res.ok, body: parsed };
}

function sleep(ms) {
    return new Promise(resolve => setTimeout(resolve, ms));
}

// ============================================================
// RSS parsing (no external deps — XML is regular enough here)
// ============================================================

function decodeEntities(s) {
    return s
        .replace(/&amp;/g, '&')
        .replace(/&lt;/g, '<')
        .replace(/&gt;/g, '>')
        .replace(/&quot;/g, '"')
        .replace(/&apos;/g, "'")
        .replace(/&#(\d+);/g, (_, n) => String.fromCharCode(parseInt(n, 10)))
        .replace(/&#x([0-9a-f]+);/gi, (_, n) => String.fromCharCode(parseInt(n, 16)));
}

function stripCdata(s) {
    const m = /^<!\[CDATA\[([\s\S]*)\]\]>$/.exec(s.trim());
    return m ? m[1] : s;
}

function tagContent(xml, tag) {
    const m = new RegExp(`<${tag}[^>]*>([\\s\\S]*?)<\\/${tag}>`, 'i').exec(xml);
    return m ? decodeEntities(stripCdata(m[1])).trim() : '';
}

function parseRssItems(xml) {
    const items = [];
    const itemRegex = /<item[^>]*>([\s\S]*?)<\/item>/gi;
    let m;
    while ((m = itemRegex.exec(xml)) !== null) {
        const block = m[1];
        items.push({
            title: tagContent(block, 'title'),
            link: tagContent(block, 'link'),
            pubDate: tagContent(block, 'pubDate'),
            description: tagContent(block, 'description'),
            source: tagContent(block, 'source')
        });
    }
    return items;
}

// ============================================================
// Sources
// ============================================================

async function fetchGoogleNews(facilityName) {
    const query = `"${facilityName}" (abuse OR lawsuit OR arrested OR investigation OR closure OR indicted)`;
    const url = `https://news.google.com/rss/search?q=${encodeURIComponent(query)}&hl=en-US&gl=US&ceid=US:en`;
    try {
        const xml = await fetchText(url);
        return parseRssItems(xml).map(item => ({
            ...item,
            origin: 'google-news',
            originQuery: facilityName
        }));
    } catch (err) {
        console.warn(`  ! Google News failed for "${facilityName}": ${err.message}`);
        return [];
    }
}

async function fetchRedditPosts() {
    const url = `https://www.reddit.com/r/troubledteens/new.json?limit=100`;
    try {
        const res = await fetch(url, { headers: { 'User-Agent': USER_AGENT } });
        if (!res.ok) throw new Error(`HTTP ${res.status}`);
        const data = await res.json();
        const posts = (data.data && data.data.children) || [];
        const items = [];
        for (const p of posts) {
            const post = p.data;
            // Link posts: post.url points to the external article
            if (post.url && !post.is_self) {
                const d = domainOf(post.url);
                if (d && !DOMAIN_BLOCKLIST.has(d) && !d.endsWith('reddit.com') && !d.endsWith('redd.it')) {
                    items.push({
                        title: post.title || '',
                        link: post.url,
                        description: (post.selftext || '').slice(0, 500),
                        pubDate: new Date(post.created_utc * 1000).toUTCString(),
                        origin: 'reddit',
                        originQuery: `r/troubledteens post ${post.id}`
                    });
                }
            }
            // Self posts: scrape external URLs out of the body
            if (post.is_self && post.selftext) {
                const urls = post.selftext.match(/https?:\/\/[^\s)\]]+/g) || [];
                for (const u of urls) {
                    const cleaned = u.replace(/[.,;:!?]+$/, '');
                    const d = domainOf(cleaned);
                    if (!d || DOMAIN_BLOCKLIST.has(d) || d.endsWith('reddit.com') || d.endsWith('redd.it')) continue;
                    items.push({
                        title: post.title || '',
                        link: cleaned,
                        description: (post.selftext || '').slice(0, 500),
                        pubDate: new Date(post.created_utc * 1000).toUTCString(),
                        origin: 'reddit-body',
                        originQuery: `r/troubledteens post ${post.id}`
                    });
                }
            }
        }
        return items;
    } catch (err) {
        console.warn(`  ! Reddit fetch failed: ${err.message}`);
        return [];
    }
}

// ============================================================
// Scoring
// ============================================================

function scoreCandidate(candidate, facilityIndex) {
    const haystack = `${candidate.title} ${candidate.description}`.toLowerCase();
    let score = 0;
    const reasons = [];

    // Abuse keyword in title
    const titleLower = candidate.title.toLowerCase();
    const titleKeywords = ABUSE_KEYWORDS.filter(k => titleLower.includes(k));
    if (titleKeywords.length > 0) {
        score += 3;
        reasons.push(`title-kw:${titleKeywords[0]}`);
    }

    // Abuse keyword in body/snippet
    const descKeywords = ABUSE_KEYWORDS.filter(k => candidate.description.toLowerCase().includes(k));
    if (descKeywords.length > 0 && titleKeywords.length === 0) {
        score += 1;
        reasons.push(`desc-kw:${descKeywords[0]}`);
    }

    // Known facility name appears anywhere
    const matchedFacility = facilityIndex.find(f => haystack.includes(f));
    if (matchedFacility) {
        score += 2;
        reasons.push(`facility:${matchedFacility}`);
    }

    // Reddit link posts are inherently signal-rich (user thought it worth sharing)
    if (candidate.origin === 'reddit') {
        score += 1;
        reasons.push('reddit-link-post');
    }

    // Blocklisted domain — hard reject regardless
    const d = domainOf(candidate.link);
    if (DOMAIN_BLOCKLIST.has(d)) {
        score = -100;
        reasons.push(`blocked-domain:${d}`);
    }

    return { score, reasons };
}

// ============================================================
// Submission
// ============================================================

async function submitCandidate(candidate, scoreResult) {
    console.log(`  → AI processing: ${candidate.link.slice(0, 80)}`);
    const aiRes = await postJson(AI_ENDPOINT, {
        url: candidate.link,
        provider: AI_PROVIDER,
        customInstructions: ''
    });

    if (!aiRes.ok || !aiRes.body || !aiRes.body.success) {
        const err = (aiRes.body && (aiRes.body.error || aiRes.body.rawText)) || `HTTP ${aiRes.status}`;
        console.warn(`    AI processing failed: ${String(err).slice(0, 200)}`);
        return { ok: false, reason: 'ai-failed', error: err };
    }

    const data = aiRes.body.data || {};
    const submission = {
        title: data.title || candidate.title || '(untitled)',
        alternateTitle: data.alternateTitle || '',
        author: data.author || '',
        publicationName: data.publicationName || '',
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
        submissionNotes: `[auto-discovery] source=${candidate.origin} query="${candidate.originQuery}" score=${scoreResult.score} reasons=${scoreResult.reasons.join(',')}`
    };

    const subRes = await postJson(SUBMIT_ENDPOINT, submission);
    if (!subRes.ok || !subRes.body || !subRes.body.success) {
        const err = (subRes.body && (subRes.body.error || subRes.body.rawText)) || `HTTP ${subRes.status}`;
        console.warn(`    Submission failed: ${String(err).slice(0, 200)}`);
        return { ok: false, reason: 'submit-failed', error: err };
    }

    console.log(`    ✓ submission id=${subRes.body.id}`);
    return { ok: true, id: subRes.body.id };
}

// ============================================================
// Main
// ============================================================

async function main() {
    console.log(`Discovery starting (${new Date().toISOString()})`);
    console.log(`  API base: ${API_BASE}`);
    console.log(`  Dry run:  ${DRY_RUN}`);
    if (Number.isFinite(SUBMIT_LIMIT)) console.log(`  Submit limit: ${SUBMIT_LIMIT}`);

    const programsRaw = JSON.parse(fs.readFileSync(PROGRAMS_FILE, 'utf8'));
    const programs = programsRaw.programs || [];
    console.log(`  Loaded ${programs.length} facilities`);

    const state = loadState();
    const seen = new Set(state.seenUrls);

    // Lowercase facility name index for substring matching
    const facilityIndex = programs
        .map(p => (p.normalizedName || p.name || '').toLowerCase().trim())
        .filter(n => n.length >= 5); // ignore very short names (false-positive risk)

    // Rotating slice of facilities to query Google News for this run
    const cursor = state.facilityCursor % programs.length;
    const slice = [];
    for (let i = 0; i < FACILITIES_PER_RUN && i < programs.length; i++) {
        slice.push(programs[(cursor + i) % programs.length]);
    }
    state.facilityCursor = (cursor + FACILITIES_PER_RUN) % programs.length;
    console.log(`  Querying Google News for facilities ${cursor}..${cursor + slice.length - 1}`);

    const candidates = [];

    // Reddit pass (one fetch, lots of posts)
    console.log(`Polling Reddit...`);
    const redditItems = await fetchRedditPosts();
    console.log(`  ${redditItems.length} reddit items`);
    candidates.push(...redditItems);

    // Google News pass (one fetch per facility, with delay)
    for (const program of slice) {
        const name = (program.name || '').trim();
        if (!name) continue;
        await sleep(REQUEST_DELAY_MS);
        const items = await fetchGoogleNews(name);
        if (items.length > 0) console.log(`  [${name}] ${items.length} items`);
        candidates.push(...items);
    }

    console.log(`Total raw candidates: ${candidates.length}`);
    state.stats.discovered += candidates.length;

    // Dedupe + score
    const queue = [];
    const dedupeSeen = new Set();
    for (const c of candidates) {
        if (!c.link || !c.link.startsWith('http')) continue;
        const h = hashUrl(c.link);
        if (seen.has(h) || dedupeSeen.has(h)) continue;
        dedupeSeen.add(h);
        const result = scoreCandidate(c, facilityIndex);
        if (result.score >= SCORE_THRESHOLD) {
            queue.push({ candidate: c, score: result });
        } else {
            state.stats.rejected += 1;
            appendReject({
                ts: new Date().toISOString(),
                link: c.link,
                title: c.title,
                origin: c.origin,
                score: result.score,
                reasons: result.reasons
            });
        }
    }
    console.log(`After filter: ${queue.length} candidates above threshold (${SCORE_THRESHOLD})`);

    if (DRY_RUN) {
        console.log('\n--- DRY RUN — would submit ---');
        for (const q of queue.slice(0, 20)) {
            console.log(`  [score ${q.score.score}] ${q.candidate.link}`);
            console.log(`     title: ${q.candidate.title.slice(0, 100)}`);
            console.log(`     reasons: ${q.score.reasons.join(', ')}`);
        }
        // Don't update state in dry-run (so we can re-test against the same data)
        return;
    }

    // Submit (mark seen even on failure so we don't retry junk forever)
    let submittedCount = 0;
    for (const q of queue) {
        if (submittedCount >= SUBMIT_LIMIT) break;
        const h = hashUrl(q.candidate.link);
        seen.add(h);
        await sleep(AI_REQUEST_DELAY_MS);
        const result = await submitCandidate(q.candidate, q.score);
        if (result.ok) {
            submittedCount += 1;
            state.stats.submitted += 1;
        }
    }

    state.seenUrls = Array.from(seen);
    state.lastRun = new Date().toISOString();
    saveState(state);

    console.log(`\nDone. Submitted ${submittedCount} new articles to review queue.`);
    console.log(`Cumulative stats:`, state.stats);
}

main().catch(err => {
    console.error('Discovery failed:', err);
    process.exit(1);
});
