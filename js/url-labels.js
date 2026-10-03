/**
 * Links read as words, never as long web addresses (the browser half of
 * inc/url-labels.php; kopUrlLabel() follows kop_url_label() rule for rule,
 * checked by scripts/test-url-labels.php).
 *
 * Lists drawn in the browser (referrers, transporters, state reports) are
 * relabelled as they appear: a link whose text is an address gets a label,
 * a bare address in text becomes a labelled link, and the full address
 * stays in the link's title. Code, pre, inputs, editable areas and anything
 * inside [data-kop-keep-url] are left alone.
 */
(function () {
    'use strict';

    var MAX = 70;
    var SKIP = 'code,pre,script,style,textarea,svg,kbd,samp,[contenteditable],[data-kop-keep-url],.CodeMirror';

    function textIsUrl(text) {
        var t = String(text || '').trim();
        if (t === '' || /\s/.test(t)) return false;
        if (/^(https?:\/\/|www\.)/i.test(t)) return true;
        return /^[a-z0-9-]+(\.[a-z0-9-]+)+\/\S*$/i.test(t);
    }

    function hostOf(url) {
        var m = /^[a-z][a-z0-9+.-]*:\/\/([^\/?#:]+)/i.exec(url);
        return m ? m[1].toLowerCase().replace(/^www\d*\./, '') : '';
    }

    function pathOf(url) {
        var m = /^[a-z][a-z0-9+.-]*:\/\/[^\/?#]*([^?#]*)/i.exec(url);
        return m ? m[1] : '';
    }

    function queryOf(url) {
        var m = /^[^?#]*\?([^#]*)/.exec(url);
        return m ? m[1] : '';
    }

    function decode(s) {
        try { return decodeURIComponent(s); } catch (e) { return s; }
    }

    function words(segment) {
        var s = decode(String(segment || ''));
        s = s.replace(/\.(html?|php|aspx?|jsp|cfm|pdf|docx?|xlsx?|pptx?|jpe?g|png|gif|txt)$/i, '');
        s = s.replace(/[-_+.~]+/g, ' ');
        var kept = s.trim().split(/\s+/).filter(function (w) {
            if (w === '') return false;
            if (/^\d{5,}$/.test(w)) return false;
            if (w.length >= 12 && /^[0-9a-f]+$/i.test(w) && /\d/.test(w)) return false;
            return true;
        });
        s = kept.join(' ');
        if ((s.match(/[a-z]/gi) || []).length < 3) return '';
        if (s.toUpperCase() === s || s.toLowerCase() === s) s = s.toLowerCase();
        return s.charAt(0).toUpperCase() + s.slice(1);
    }

    function trim(s) {
        var chars = Array.from(s);
        if (chars.length <= MAX) return s;
        var cut = chars.slice(0, MAX - 1).join('');
        var space = cut.lastIndexOf(' ');
        if (space !== -1 && space > MAX / 2) cut = cut.slice(0, space);
        return cut.replace(/[ ,.;:-]+$/, '') + '…';
    }

    function label(url) {
        url = String(url || '').trim();
        if (url === '') return '';
        if (/^www\./i.test(url)) url = 'https://' + url;
        else if (!/^[a-z][a-z0-9+.-]*:/i.test(url) && /^[a-z0-9-]+(\.[a-z0-9-]+)+(\/|$)/i.test(url)) url = 'https://' + url;
        if (!/^https?:\/\//i.test(url)) return url;

        var host = hostOf(url);
        var path = pathOf(url);

        if (/^(web\.)?archive\.org$/.test(host)) {
            var q = queryOf(url);
            var am = /^\/web\/(?:(\d{4})\d*[a-z_]*\/)?([a-z]+:\/.+|[a-z0-9-]+\.[a-z0-9.-]+.*)$/i.exec(path + (q ? '?' + q : ''));
            if (am) {
                var inner = label(/^[a-z]+:\/{1,2}/i.test(am[2]) ? am[2].replace(/^([a-z]+):\/(?!\/)/i, '$1://') : 'http://' + am[2]);
                var when = am[1] ? 'archived ' + am[1] : 'archived';
                var pm = /^(.*) \(([^()]*)\)$/.exec(inner);
                if (pm) return pm[1] + ' (' + pm[2] + ', ' + when + ')';
                return inner + ' (' + when + ')';
            }
        }

        var segments = path.split('/').filter(function (s) { return s.length > 0; });
        var isPdf = /\.pdf$/i.test(path);
        // "#page=18" on a PDF: the page the link opens at.
        var pageMatch = /#(?:[^#]*&)?page=(\d+)/.exec(url);
        var pdf = isPdf ? 'PDF' + (pageMatch ? ', p. ' + pageMatch[1] : '') : '';

        if (/(^|\.)reddit\.com$/.test(host) && segments.length > 1 && segments[0].toLowerCase() === 'r') {
            var sub = 'r/' + segments[1];
            var last = segments[segments.length - 1];
            if (segments[2] && segments[2].toLowerCase() === 'wiki') {
                var page = words(last);
                return trim((page !== '' && last.toLowerCase() !== 'wiki' ? page + ' ' : '') + '(' + sub + ' wiki)');
            }
            if (segments[2] && segments[4] && segments[2].toLowerCase() === 'comments') {
                var title = words(segments[4]);
                if (title !== '') return trim(title + ' (' + sub + ')');
            }
            return sub;
        }

        var w = '';
        for (var i = segments.length - 1; i >= 0 && w === ''; i--) {
            if (/^(index|default|home|main)\.(html?|php|aspx?)$/i.test(segments[i])) continue;
            w = words(segments[i]);
        }

        var own = host === 'kidsoverprofits.org' || host === hostOf(window.location ? String(window.location.href) : '');
        if (own) {
            if (w === '') return 'Kids Over Profits';
            return trim(w) + (isPdf ? ' (' + pdf + ')' : '');
        }
        var where = host + (isPdf ? ', ' + pdf : '');
        if (w === '') return isPdf ? pdf + ' (' + host + ')' : host;
        return trim(w) + ' (' + where + ')';
    }

    var BARE = /(^|[^\w@\/.-])((?:https?:\/\/|www\.)[^\s<>"'\[\]{}|\\^`]+|[a-z0-9-]+(?:\.[a-z0-9-]+)*\.(?:com|org|net|gov|edu|us|io|tv|info)\/[^\s<>"'\[\]{}|\\^`]+)/gi;
    var HINT = /https?:\/\/|www\.|\.(com|org|net|gov|edu|us|io|tv|info)\//i;

    function splitTail(url) {
        var tail = '';
        while (url !== '' && /[.,;:!?)’”]$/.test(url)) {
            var lastChar = url.slice(-1);
            if (lastChar === ')' && (url.split('(').length - 1) >= (url.split(')').length - 1)) break;
            tail = lastChar + tail;
            url = url.slice(0, -1);
        }
        return [url, tail];
    }

    function relabelLink(a) {
        if (a.dataset.kopUrlLabelled) return;
        if (a.children.length > 0) return;
        if (!textIsUrl(a.textContent)) return;
        var href = a.getAttribute('href');
        // A link with no href (a lightbox's copy) is labelled from its own text.
        var own = href === null;
        if (own) {
            href = a.textContent.trim();
            if (!/^https?:\/\//i.test(href)) href = 'https://' + href;
        }
        if (!/^https?:\/\//i.test(href)) return;
        a.dataset.kopUrlLabelled = '1';
        if (!a.title && !own) a.title = href;
        a.textContent = label(href);
    }

    function linkifyText(node) {
        var text = node.nodeValue;
        if (!text || !HINT.test(text)) return;
        BARE.lastIndex = 0;
        var frag = null, last = 0, m;
        while ((m = BARE.exec(text))) {
            var parts = splitTail(m[2]);
            var url = parts[0];
            if (url.length < 12) continue;
            var start = m.index + m[1].length;
            if (!frag) frag = document.createDocumentFragment();
            frag.appendChild(document.createTextNode(text.slice(last, start)));
            var href = /^https?:\/\//i.test(url) ? url : 'https://' + url;
            var a = document.createElement('a');
            a.href = href;
            a.title = href;
            a.rel = 'noopener';
            a.textContent = label(href);
            a.dataset.kopUrlLabelled = '1';
            frag.appendChild(a);
            last = start + url.length;
        }
        if (!frag) return;
        frag.appendChild(document.createTextNode(text.slice(last)));
        node.parentNode.replaceChild(frag, node);
    }

    function process(root) {
        if (!root || root.nodeType !== 1 && root.nodeType !== 3) return;
        if (root.nodeType === 3) {
            var p = root.parentElement;
            if (p && !p.closest(SKIP + ',a,input,select,option,button')) linkifyText(root);
            else if (p && p.tagName === 'A' && !p.closest(SKIP)) relabelLink(p);
            return;
        }
        if (root.closest && root.closest(SKIP)) return;
        if (!HINT.test(root.textContent || '')) return;
        if (root.tagName === 'A') { relabelLink(root); return; }
        root.querySelectorAll('a').forEach(function (a) {
            if (!a.closest(SKIP)) relabelLink(a);
        });
        var walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT, {
            acceptNode: function (n) {
                if (!HINT.test(n.nodeValue || '')) return NodeFilter.FILTER_REJECT;
                var el = n.parentElement;
                if (!el || el.closest(SKIP + ',a,input,select,option,button,title')) return NodeFilter.FILTER_REJECT;
                return NodeFilter.FILTER_ACCEPT;
            }
        });
        var nodes = [];
        while (walker.nextNode()) nodes.push(walker.currentNode);
        nodes.forEach(linkifyText);
    }

    window.kopUrlLabel = label;
    window.kopUrlLabels = { label: label, textIsUrl: textIsUrl, process: process };

    if (typeof document === 'undefined') return;
    if (!document.body) {
        document.addEventListener('DOMContentLoaded', start);
    } else {
        start();
    }

    function start() {
        process(document.body);
        if (!window.MutationObserver) return;
        var queue = [], scheduled = false;
        new MutationObserver(function (records) {
            records.forEach(function (r) {
                if (r.type === 'characterData') queue.push(r.target);
                r.addedNodes.forEach(function (n) { queue.push(n); });
            });
            if (scheduled) return;
            scheduled = true;
            (window.requestAnimationFrame || setTimeout)(function () {
                scheduled = false;
                var batch = queue; queue = [];
                batch.forEach(function (n) { if (n.isConnected) process(n); });
            });
        }).observe(document.body, { childList: true, subtree: true, characterData: true });
    }
})();
