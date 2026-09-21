/**
 * How every page links a program's, operator's, referrer's or transporter's
 * own website (docs/FIX-PLAN-2026-09.md, item 11).
 *
 * The archived snapshot (web.archive.org) is the primary link. The live site
 * is reachable only through /go/?u=<url>, which answers noindex/nofollow,
 * forwards with no referrer, and only to hosts in our own records (see
 * kop_program_go_route() in inc/facility-pages.php). Hosts that belong to
 * somebody other than the industry (the archives, this site, Reddit,
 * Wikipedia, social networks, public records) keep a plain link.
 *
 * The exempt list and the /go/ base come from PHP (KOP_PROGRAM_LINKS, set by
 * kop_program_links_register_script()), so both sides apply one list. The two
 * public-records patterns mirror kop_facility_pages_archive_exempt_host().
 *
 * Usage: KOP.programLinks.html(url, { label, max })
 */
(function (root) {
    'use strict';

    const config = root.KOP_PROGRAM_LINKS || {};
    const goBase = typeof config.goBase === 'string' && config.goBase ? config.goBase : '/go/';
    const exempt = Array.isArray(config.exempt) ? config.exempt.map((d) => String(d).toLowerCase()) : [];
    if (root.location && root.location.hostname) {
        exempt.push(String(root.location.hostname).toLowerCase().replace(/^www\./, ''));
    }

    const escapeHtml = (value) => String(value == null ? '' : value)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');

    const hostOf = (url) => {
        try {
            return new URL(String(url)).hostname.toLowerCase().replace(/^www\./, '');
        } catch (e) {
            return '';
        }
    };

    const isHttp = (url) => typeof url === 'string' && /^https?:\/\//i.test(url.trim());

    /** Mirrors kop_facility_pages_archive_exempt_host(). */
    const isExempt = (url) => {
        const host = hostOf(url);
        if (host === '') return true;
        for (const domain of exempt) {
            if (host === domain || host.endsWith('.' + domain)) return true;
        }
        if (/\.(gov|mil)$/.test(host)) return true;
        if (/\.(state|co|ci|k12)\.[a-z]{2}\.us$/.test(host)) return true;
        return false;
    };

    const archiveUrl = (url) => 'https://web.archive.org/web/' + String(url).trim();
    const goUrl = (url) => goBase + (goBase.indexOf('?') === -1 ? '?' : '&') + 'u=' + encodeURIComponent(String(url).trim());

    /** "https://www.example.com/path" -> "example.com/path", shortened to max. */
    const displayText = (url, max) => {
        let text = String(url).trim().replace(/^https?:\/\/(www\.)?/i, '').replace(/\/$/, '');
        const limit = max || 60;
        if (text.length > limit) text = text.substring(0, limit - 3) + '...';
        return text;
    };

    /**
     * Markup for one link. A program's site: the archived copy, then a small
     * "live site" link through /go/. An exempt host: a plain link. Anything
     * that is not an http(s) URL comes back as escaped text.
     */
    const html = (url, opts) => {
        const options = opts || {};
        if (!isHttp(url)) return escapeHtml(options.label || url || '');
        const clean = String(url).trim();
        const label = options.label || displayText(clean, options.max);
        if (isExempt(clean)) {
            return `<a href="${escapeHtml(clean)}" target="_blank" rel="noopener">${escapeHtml(label)}</a>`;
        }
        return `<a href="${escapeHtml(archiveUrl(clean))}" target="_blank" rel="noopener" title="Archived copy of ${escapeHtml(clean)}">${escapeHtml(label)} (archived)</a>`
            + ` <a class="kop-live-link" href="${escapeHtml(goUrl(clean))}" target="_blank" rel="nofollow noopener noreferrer">live site</a>`;
    };

    root.KOP = root.KOP || {};
    root.KOP.programLinks = { isExempt, archiveUrl, goUrl, displayText, html };
})(typeof window !== 'undefined' ? window : globalThis);
