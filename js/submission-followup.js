/**
 * "Email me when this has been reviewed" on the public forms.
 *
 * PHP forms print the block with kop_followup_fields() (inc/submission-followup.php);
 * JS-built forms insert kopFollowup.html(id). Ticking the box shows the address box
 * (or, with data-kop-followup-for, uses an email box the form already has). On submit
 * a form calls kopFollowup.value(container) and posts the address as `notify_email`;
 * the endpoint signs it up for one email when the submission is decided.
 */
(function () {
    'use strict';

    var EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

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
        if (!emailId) {
            out += '<div class="kop-followup__email" hidden>'
                + '<label for="' + id + '-email">Your email</label>'
                + '<input type="email" id="' + id + '-email" data-kop-followup-email maxlength="190" autocomplete="email" placeholder="you@example.com">'
                + '</div>';
        }
        return out + '<p class="kop-followup__hint" hidden>One email when it is added or turned down. Your address is never published, and we delete it once that email is sent.</p>'
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
     * What to post. { ok: true, email: '' } when the box is not ticked;
     * { ok: false, message } when it is ticked without a usable address.
     */
    function value(root) {
        var b = block(root);
        if (!b) return { ok: true, email: '' };
        var check = b.querySelector('[data-kop-followup-check]');
        if (!check || !check.checked) {
            say(b, '');
            return { ok: true, email: '' };
        }
        var box = emailBox(b);
        var email = box ? box.value.trim() : '';
        if (!EMAIL_RE.test(email)) {
            var msg = email ? 'That email address does not look right.' : 'Add your email address, or untick "Email me when this has been reviewed".';
            say(b, msg);
            if (box) box.focus();
            return { ok: false, message: msg };
        }
        say(b, '');
        return { ok: true, email: email };
    }

    function reset(root) {
        var b = block(root);
        if (!b) return;
        var check = b.querySelector('[data-kop-followup-check]');
        if (check) check.checked = false;
        sync(b);
    }

    function sync(b) {
        var check = b.querySelector('[data-kop-followup-check]');
        var on = !!(check && check.checked);
        var mine = b.querySelector('.kop-followup__email');
        if (mine) mine.hidden = !on;
        var hint = b.querySelector('.kop-followup__hint');
        if (hint) hint.hidden = !on;
        if (!on) say(b, '');
        if (on && mine) {
            var input = mine.querySelector('input');
            if (input && !input.value) input.focus();
        }
    }

    document.addEventListener('change', function (e) {
        var t = e.target;
        if (t && t.matches && t.matches('[data-kop-followup-check]')) {
            var b = t.closest('[data-kop-followup]');
            if (b) sync(b);
        }
    });

    window.kopFollowup = { html: html, value: value, reset: reset };
})();
