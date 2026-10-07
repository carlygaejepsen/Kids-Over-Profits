/**
 * /send/ (templates/send-page.php): posts a link to kop/v1/mobile/submit with via = 'web'.
 * Config comes from kopSendPage (inc/send-page.php): submitUrl, checkUrl, suggestUrl, prefill.
 * The "email me" + newsletter block is js/submission-followup.js (kopFollowup.value()).
 */
(function () {
    'use strict';

    var cfg = window.kopSendPage || {};
    var form = document.getElementById('kop-send-form');
    if (!form) return;

    var $ = function (id) { return document.getElementById(id); };
    var urlBox = $('kop-send-url');
    var kindBox = $('kop-send-kind');
    var titleBox = $('kop-send-title');
    var checkEl = $('kop-send-check');
    var resultEl = $('kop-send-result');
    var kindTouched = false;

    // ---- Guess the kind (port of browser-extension/send-to-kop/classify.js) ----

    var LAWSUIT_HOSTS = [
        /courtlistener\.com$/, /pacermonitor\.com$/, /uscourts\.gov$/, /dockets\.justia\.com$/,
        /law\.justia\.com$/, /unicourt\.com$/, /casetext\.com$/, /trellis\.law$/, /casemine\.com$/
    ];
    var LEG_HOSTS = [
        /congress\.gov$/, /legiscan\.com$/, /govtrack\.us$/, /openstates\.org$/,
        /legislature/, /(^|\.)legis\./, /(^|\.)leg\.state\./, /[a-z]{2}leg\.gov$/, /capitol\./
    ];
    var CASE_RE = /\b(\d{1,2}:\d{2}-[a-z]{2,4}-\d{3,6}(?:-[A-Z]{2,4})*)\b/i;
    var BILL_RE = /\b(H\.?\s?R\.?|H\.?\s?B\.?|S\.?\s?B\.?|A\.?\s?B\.?|L\.?\s?D\.?|H\.?\s?F\.?|S\.?\s?F\.?|S\.)\s?(\d{1,5})\b/;
    var CONGRESS_PREFIX = {
        'house-bill': 'HR', 'senate-bill': 'S',
        'house-joint-resolution': 'HJRES', 'senate-joint-resolution': 'SJRES',
        'house-resolution': 'HRES', 'senate-resolution': 'SRES',
        'house-concurrent-resolution': 'HCONRES', 'senate-concurrent-resolution': 'SCONRES'
    };

    function matches(host, list) {
        return list.some(function (r) { return r.test(host); });
    }

    function billFromUrl(host, path) {
        var m;
        if (/legiscan\.com$/.test(host) && (m = path.match(/^\/([A-Z]{2})\/bill\/([A-Z]+)(\d+)\/(\d{4})/i))) {
            return { jurisdiction: m[1].toUpperCase(), bill_number: m[2].toUpperCase() + ' ' + m[3], session: m[4] };
        }
        if (/congress\.gov$/.test(host) && (m = path.match(/\/bill\/(\d+(?:st|nd|rd|th))-congress\/([a-z-]+)\/(\d+)/))) {
            return { jurisdiction: 'US', bill_number: (CONGRESS_PREFIX[m[2]] || m[2]) + ' ' + m[3], session: m[1] + ' Congress' };
        }
        if (/govtrack\.us$/.test(host) && (m = path.match(/\/congress\/bills\/(\d+)\/([a-z]+)(\d+)/))) {
            return { jurisdiction: 'US', bill_number: m[2].toUpperCase() + ' ' + m[3], session: 'Congress ' + m[1] };
        }
        if (/openstates\.org$/.test(host) && (m = path.match(/^\/([a-z]{2})\/bills\/([^/]+)\/([A-Z]+)(\d+)/i))) {
            return { jurisdiction: m[1].toUpperCase(), bill_number: m[3].toUpperCase() + ' ' + m[4], session: m[2] };
        }
        m = host.match(/\.leg\.state\.([a-z]{2})\.us$/) || host.match(/^legis\.([a-z]{2})\.gov$/) || host.match(/^([a-z]{2})leg\.gov$/);
        if (m) return { jurisdiction: m[1].toUpperCase() };
        return {};
    }

    /** { type, fields } for a link and title: article, lawsuit, legislation or website. */
    function classify(rawUrl, title) {
        var u;
        try { u = new URL(rawUrl); } catch (e) { return null; }
        var host = u.hostname.replace(/^www\./, '').toLowerCase();
        var info = billFromUrl(host, u.pathname);
        var fields = {};
        if (info.jurisdiction) fields.jurisdiction = info.jurisdiction;
        if (matches(host, LAWSUIT_HOSTS) || CASE_RE.test(title || '')) {
            var c = CASE_RE.exec(title || '');
            if (c) fields.case_number = c[1];
            return { type: 'lawsuit', fields: fields };
        }
        if (matches(host, LEG_HOSTS) || info.bill_number) {
            var b = BILL_RE.exec(title || '');
            fields.bill_number = info.bill_number || (b ? b[1].replace(/[.\s]/g, '').toUpperCase() + ' ' + b[2] : '');
            if (info.session) fields.session = info.session;
            return { type: 'legislation', fields: fields };
        }
        // Without the page itself, the path is the only hint of a news article.
        var articleish = /\/(news|article|articles|story|stories)\b/i.test(u.pathname) || /\/\d{4}\/\d{2}\//.test(u.pathname);
        return { type: articleish ? 'article' : 'website', fields: fields };
    }

    function showKind() {
        var kind = kindBox.value;
        var rows = form.querySelectorAll('[data-kop-send-for]');
        for (var i = 0; i < rows.length; i++) {
            rows[i].hidden = rows[i].getAttribute('data-kop-send-for') !== kind;
        }
    }

    function setIfEmpty(name, value) {
        var el = form.elements[name];
        if (el && value && !el.value) el.value = value;
    }

    function guess() {
        var url = urlBox.value.trim();
        if (!/^https?:\/\//i.test(url)) return;
        var g = classify(url, titleBox.value);
        if (!g) return;
        if (!kindTouched) kindBox.value = g.type;
        showKind();
        Object.keys(g.fields).forEach(function (k) { setIfEmpty(k, g.fields[k]); });
    }

    kindBox.addEventListener('change', function () { kindTouched = true; showKind(); });

    // ---- Already on file ----

    var checkTimer = 0;
    var checkSeq = 0;

    function noteOnFile(data) {
        var dupes = (data && data.duplicates) || [];
        if (!dupes.length) {
            checkEl.hidden = true;
            checkEl.textContent = '';
            return;
        }
        var live = dupes.some(function (d) { return d.status === 'on the site'; });
        checkEl.textContent = live
            ? 'Already on file (on the site). You can still send it, but it will not be added again.'
            : 'Already on file (in review). You can still send it, but it will not be added again.';
        checkEl.hidden = false;
    }

    function check() {
        var url = urlBox.value.trim();
        var seq = ++checkSeq;
        if (!/^https?:\/\/\S+\.\S+/i.test(url) || !cfg.checkUrl) {
            noteOnFile(null);
            return;
        }
        var q = (cfg.checkUrl.indexOf('?') < 0 ? '?' : '&')
            + 'url=' + encodeURIComponent(url)
            + '&title=' + encodeURIComponent(titleBox.value.trim())
            + '&type=' + encodeURIComponent(kindBox.value);
        fetch(cfg.checkUrl + q, { headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (data) { if (seq === checkSeq) noteOnFile(data); })
            .catch(function () { /* the check is a courtesy; sending still works */ });
    }

    function onUrlChange() {
        guess();
        clearTimeout(checkTimer);
        checkTimer = setTimeout(check, 500);
    }

    urlBox.addEventListener('input', onUrlChange);
    urlBox.addEventListener('change', onUrlChange);
    titleBox.addEventListener('change', function () { guess(); });

    // ---- Facility names (kop/v1/facility-suggest) into a datalist ----

    var facBox = form.elements.facility;
    var facList = $('kop-send-facility-list');
    var facTimer = 0;
    var facSeq = 0;
    if (facBox && facList && cfg.suggestUrl) {
        facBox.addEventListener('input', function () {
            clearTimeout(facTimer);
            var phrase = facBox.value.trim();
            if (phrase.length < 3) return;
            facTimer = setTimeout(function () {
                var seq = ++facSeq;
                fetch(cfg.suggestUrl + (cfg.suggestUrl.indexOf('?') < 0 ? '?' : '&') + 'q=' + encodeURIComponent(phrase))
                    .then(function (r) { return r.ok ? r.json() : []; })
                    .then(function (rows) {
                        if (seq !== facSeq) return;
                        var list = Array.isArray(rows) ? rows : (rows && rows.results) || [];
                        facList.textContent = '';
                        list.slice(0, 8).forEach(function (row) {
                            if (!row || !row.name) return;
                            var o = document.createElement('option');
                            o.value = row.name;
                            if (row.place) o.label = row.name + ' (' + row.place + ')';
                            facList.appendChild(o);
                        });
                    })
                    .catch(function () { /* plain text still works */ });
            }, 300);
        });
    }

    // ---- Sending ----

    function say(kind, text) {
        resultEl.className = 'kop-send__result kop-send__result--' + kind;
        resultEl.textContent = text;
        resultEl.hidden = false;
        resultEl.focus();
    }

    function body() {
        var kind = kindBox.value;
        var f = form.elements;
        var out = {
            via: 'web',
            type: kind,
            url: urlBox.value.trim(),
            title: titleBox.value.trim(),
            facility: f.facility.value.trim(),
            notes: f.notes.value.trim(),
            website_hp: f.website_hp.value
        };
        if (kind === 'article') {
            out.site_name = f.site_name.value.trim();
            out.published = f.published.value;
        } else if (kind === 'lawsuit') {
            out.case_number = f.case_number.value.trim();
            out.court = f.court.value.trim();
        } else if (kind === 'legislation') {
            out.bill_number = f.bill_number.value.trim();
            out.jurisdiction = f.jurisdiction.value.trim();
            out.session = f.session.value.trim();
        }
        return out;
    }

    form.addEventListener('submit', function (e) {
        e.preventDefault();
        resultEl.hidden = true;
        var url = urlBox.value.trim();
        if (!/^https?:\/\/\S+\.\S+/i.test(url)) {
            say('error', 'Add the link first. It should start with http:// or https://.');
            urlBox.focus();
            return;
        }
        var follow = window.kopFollowup ? window.kopFollowup.value(form) : { ok: true, email: '', newsletterEmail: '' };
        if (!follow.ok) return;
        var data = body();
        if (follow.email) data.notify_email = follow.email;
        if (follow.newsletterEmail) data.newsletter_email = follow.newsletterEmail;

        var btn = form.querySelector('.kop-send__submit');
        btn.disabled = true;
        fetch(cfg.submitUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
            body: JSON.stringify(data)
        }).then(function (r) {
            return r.json().catch(function () { return {}; }).then(function (j) { return { status: r.status, json: j }; });
        }).then(function (res) {
            btn.disabled = false;
            if (res.status === 409 || (res.json && res.json.code === 'kop_duplicate')) {
                var live = ((res.json && res.json.duplicates) || []).some(function (d) { return d.status === 'on the site'; });
                say('dup', live
                    ? 'We already have this link, and it is on the site. Thank you for checking.'
                    : 'We already have this link, and it is waiting for review. Thank you for checking.');
            } else if (res.status >= 200 && res.status < 300) {
                say('ok', 'Sent. Thank you. A person will review it before anything appears on the site.');
                ['title', 'url', 'notes', 'facility', 'site_name', 'published', 'case_number', 'court', 'bill_number', 'jurisdiction', 'session']
                    .forEach(function (n) { if (form.elements[n]) form.elements[n].value = ''; });
                noteOnFile(null);
                if (window.kopFollowup) window.kopFollowup.reset(form);
            } else {
                say('error', (res.json && res.json.message) || 'The site could not take this just now. Please try again in a few minutes.');
            }
        }).catch(function () {
            btn.disabled = false;
            say('error', 'The site could not be reached. Check your connection and try again.');
        });
    });

    // ---- Bookmarklet code: copy ----

    var copyBtn = $('kop-send-copy');
    var code = $('kop-send-code');
    var copied = $('kop-send-copied');
    if (copyBtn && code) {
        copyBtn.addEventListener('click', function () {
            var done = function (ok) {
                if (copied) copied.textContent = ok ? 'Copied.' : 'Select the code above and copy it.';
            };
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(code.value).then(function () { done(true); }, function () {
                    code.select();
                    done(false);
                });
            } else {
                code.select();
                try { done(document.execCommand('copy')); } catch (err) { done(false); }
            }
        });
    }

    // ---- Prefill (also set in the markup; this covers a cached page) ----

    var pre = cfg.prefill || {};
    if (pre.url && !urlBox.value) urlBox.value = pre.url;
    if (pre.title && !titleBox.value) titleBox.value = pre.title;
    if (pre.text && !form.elements.notes.value) form.elements.notes.value = pre.text;
    showKind();
    if (urlBox.value) onUrlChange();
})();
