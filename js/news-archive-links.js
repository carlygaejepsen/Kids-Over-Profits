/**
 * An article's real link and its archived copy, for lists drawn in the browser.
 * Same rules as api/lib-news-archive.php (scripts/test-news-archive.php checks
 * they agree). Loaded on every page (inc/news-archive-links.php).
 *
 *   kopNewsLinks(article_url, archive_url) -> { original, archive }
 *   kopNewsArchiveLinkHtml(article_url, archive_url) -> ' <a class="kop-archived-link">archived copy</a>' or ''
 */
(function (root) {
    'use strict';

    var ARCHIVE_HOSTS = [
        'archive.today', 'archive.ph', 'archive.is', 'archive.li', 'archive.vn', 'archive.md', 'archive.fo',
        'ghostarchive.org', 'perma.cc', 'webcitation.org', 'archive.org.au', 'webarchive.org.uk'
    ];

    function parse(url) {
        var m = /^[a-z][a-z0-9+.\-]*:\/\/([^\/?#:]*)(?::\d+)?([^?#]*)/i.exec(String(url || '').trim());
        return m ? { host: m[1].toLowerCase().replace(/^www\./, ''), path: m[2] || '' } : null;
    }

    function isArchiveUrl(url) {
        var u = parse(url);
        if (!u || !u.host) return false;
        if (u.host === 'web.archive.org' || u.host === 'wayback.archive.org') return true;
        if (u.host === 'archive.org') return /^\/web\//.test(u.path);
        return ARCHIVE_HOSTS.indexOf(u.host) !== -1 || /(^|\.)webarchive\.(nla\.gov\.au|loc\.gov)$/.test(u.host);
    }

    function unwrap(url) {
        var m = /^https?:\/\/(?:www\.)?(?:web\.|wayback\.)?archive\.org\/web\/[0-9*]{1,16}[a-z_]*\/(.+)$/i.exec(String(url || '').trim());
        if (!m) return '';
        var inner = m[1], s = /^(https?):\/+(.*)$/i.exec(inner);
        if (s) inner = s[1].toLowerCase() + '://' + s[2];
        else if (/^[a-z0-9-]+(\.[a-z0-9-]+)+(\/|$)/i.test(inner)) inner = 'http://' + inner;
        else return '';
        try { new URL(inner); } catch (e) { return ''; }
        return inner;
    }

    function links(articleUrl, archiveUrl) {
        var article = String(articleUrl || '').trim();
        var archive = String(archiveUrl || '').trim();
        var original = '';
        if (article) {
            if (isArchiveUrl(article)) {
                if (!archive) archive = article;
                original = unwrap(article);
            } else {
                original = article;
            }
        }
        if (archive && !/^https?:\/\//i.test(archive)) archive = '';
        if (!original && archive) original = unwrap(archive);
        if (original && !/^https?:\/\//i.test(original)) original = '';
        return { original: original, archive: archive };
    }

    function esc(s) {
        return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#039;');
    }

    function archiveLinkHtml(articleUrl, archiveUrl, label) {
        var l = links(articleUrl, archiveUrl);
        if (!l.archive || !l.original) return '';
        return ' <a class="kop-archived-link" href="' + esc(l.archive) + '" target="_blank" rel="noopener noreferrer">' + esc(label || 'archived copy') + '</a>';
    }

    root.kopNewsIsArchiveUrl = isArchiveUrl;
    root.kopNewsUnwrapArchiveUrl = unwrap;
    root.kopNewsLinks = links;
    root.kopNewsArchiveLinkHtml = archiveLinkHtml;
    if (typeof module !== 'undefined' && module.exports) {
        module.exports = { isArchiveUrl: isArchiveUrl, unwrap: unwrap, links: links, archiveLinkHtml: archiveLinkHtml };
    }
})(typeof window !== 'undefined' ? window : globalThis);
