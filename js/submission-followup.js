/**
 * "Email me when this has been reviewed" and "Also sign me up for the newsletter"
 * on the public forms. Neither box is ticked by default.
 *
 * PHP forms print the block with kop_followup_fields() (inc/submission-followup.php);
 * JS-built forms insert kopFollowup.html(id). Ticking either box shows the address
 * box (or, with data-kop-followup-for, uses an email box the form already has). On
 * submit a form calls kopFollowup.value(container) and posts `notify_email` and
 * `newsletter_email` (each '' unless its box is ticked); the endpoint passes both to
 * kop_followup_register().
 */
(function () {
    'use strict';

    var EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    var settings = window.kopFollowupSettings || {};

    function esc(s) {
        return String(s).replace(/[&<>"]/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c];
        });
    }

    function html(id, emailId) {
        id = String(id || 'kop-followup').replace(/[^A-Za-z0-9_-]/g, '');
        var out = '<div class="kop-followup" data-kop-followup' + (emailId ? ' data-kop-followup-for="' + esc(emailId) + '"' : '') + '>'
            + '<label class="kop-followup__check" for="' + id + '-check">'
            + '<input type="checkbox" id="' + id + '-check" data-kop-followup-check> Email me when this has been reviewed</label>';
        if (settings.newsletter) {
            out += '<label class="kop-followup__check" for="' + id + '-news">'
                + '<input type="checkbox" id="' + id + '-news" data-kop-followup-news> Also sign me up for the '
                + esc(settings.siteName || 'Kids Over Profits') + ' newsletter</label>';
        }
        if (!emailId) {
            out += '<div class="kop-followup__email" hidden>'
                + '<label for="' + id + '-email">Your email</label>'
                + '<input type="email" id="' + id + '-email" data-kop-followup-email maxlength="190" autocomplete="email" placeholder="you@example.com">'
                + '</div>';
        }
        return out
            + '<p class="kop-followup__hint" data-kop-followup-hint="notify" hidden>One email when it is added or turned down. Your address is never published, and we delete it once that email is sent.</p>'
            + '<p class="kop-followup__hint" data-kop-followup-hint="news" hidden>The newsletter is separate: you can unsubscribe from any issue.</p>'
            + '</div>';
    }

    function block(root) {
        if (!root) return null;
        if (root.matches && root.matches('[data-kop-followup]')) return root;
        return root.querySelector ? root.querySelector('[data-kop-followup]') : null;
    }

    function emailBox(b) {
        var forId = b.getAttribute('data-kop-followup-for');
        return forId ? document.getElementById(forId) : b.querySelector('[data-kop-followup-email]');
    }

    function ticked(b, attr) {
        var box = b.querySelector('[' + attr + ']');
        return !!(box && box.checked);
    }

    function say(b, text) {
        var err = b.querySelector('.kop-followup__error');
        if (!text) {
            if (err) err.parentNode.removeChild(err);
            return;
        }
        if (!err) {
            err = document.createElement('p');
            err.className = 'kop-followup__error';
            err.setAttribute('role', 'alert');
            b.appendChild(err);
        }
        err.textContent = text;
    }

    /**
     * What to post. { ok: true, email, newsletterEmail }, each '' unless its box
     * is ticked; { ok: false, message } when a box is ticked without a usable address.
     */
    function value(root) {
        var b = block(root);
        if (!b) return { ok: true, email: '', newsletterEmail: '' };
        var notify = ticked(b, 'data-kop-followup-check');
        var news = ticked(b, 'data-kop-followup-news');
        if (!notify && !news) {
            say(b, '');
            return { ok: true, email: '', newsletterEmail: '' };
        }
        var box = emailBox(b);
        var email = box ? box.value.trim() : '';
        if (!EMAIL_RE.test(email)) {
            var msg = email ? 'That email address does not look right.' : 'Add your email address, or untick the email boxes.';
            say(b, msg);
            if (box) box.focus();
            return { ok: false, message: msg };
        }
        say(b, '');
        return { ok: true, email: notify ? email : '', newsletterEmail: news ? email : '' };
    }

    function reset(root) {
        var b = block(root);
        if (!b) return;
        b.querySelectorAll('[data-kop-followup-check], [data-kop-followup-news]').forEach(function (c) { c.checked = false; });
        sync(b);
    }

    function sync(b, focus) {
        var notify = ticked(b, 'data-kop-followup-check');
        var news = ticked(b, 'data-kop-followup-news');
        var mine = b.querySelector('.kop-followup__email');
        if (mine) mine.hidden = !(notify || news);
        var hn = b.querySelector('[data-kop-followup-hint="notify"]');
        if (hn) hn.hidden = !notify;
        var hw = b.querySelector('[data-kop-followup-hint="news"]');
        if (hw) hw.hidden = !news;
        if (!notify && !news) say(b, '');
        if (focus && (notify || news) && mine) {
            var input = mine.querySelector('input');
            if (input && !input.value) input.focus();
        }
    }

    document.addEventListener('change', function (e) {
        var t = e.target;
        if (t && t.matches && t.matches('[data-kop-followup-check], [data-kop-followup-news]')) {
            var b = t.closest('[data-kop-followup]');
            if (b) sync(b, t.checked);
        }
    });

    window.kopFollowup = { html: html, value: value, reset: reset };
})();
