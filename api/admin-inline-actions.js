/**
 * Progressive enhancement for the /api admin tools (manage-story-arcs.php,
 * manage-duplicate-articles.php): forms marked data-inline-action submit via
 * fetch so the page never reloads and scroll position is kept. The server
 * detects the extra kop_ajax field and answers JSON instead of re-rendering.
 * Without JavaScript the same forms still work as normal POSTs.
 *
 * Form attributes:
 *   data-inline-action        opt in to fetch submission
 *   data-confirm="..."        confirm() before submitting
 *   data-remove="row|card"    on success remove the closest tr / .group/.arc
 *   data-remove-target="#id"  on success remove that element (and this form)
 *
 * After a successful action the form receives a "kop:inline-success"
 * CustomEvent whose detail is the server's JSON response, so a page can do
 * its own DOM updates (e.g. organize-uncategorized-media.php removes the
 * rows named in response.ids).
 */
(function () {
    'use strict';

    function toast(messages, isError) {
        var box = document.getElementById('kop-toast-box');
        if (!box) {
            box = document.createElement('div');
            box.id = 'kop-toast-box';
            box.style.cssText = 'position:fixed;right:16px;bottom:16px;z-index:1000;display:flex;flex-direction:column;gap:8px;max-width:360px;';
            document.body.appendChild(box);
        }
        (messages && messages.length ? messages : [isError ? 'Action failed.' : 'Done.']).forEach(function (msg) {
            var el = document.createElement('div');
            el.textContent = msg;
            el.style.cssText = 'padding:10px 14px;border-radius:8px;font:600 0.85rem system-ui,sans-serif;box-shadow:0 2px 8px rgba(0,0,0,.25);'
                + (isError ? 'background:#fff;color:#c0392b;border:2px solid #c0392b;'
                           : 'background:#FFF5CB;color:#000435;border:2px solid #33A7B5;');
            box.appendChild(el);
            setTimeout(function () { el.remove(); }, 6000);
        });
    }

    document.addEventListener('submit', function (e) {
        var form = e.target;
        if (!form.hasAttribute || !form.hasAttribute('data-inline-action')) {
            return;
        }
        e.preventDefault();
        var confirmMsg = form.getAttribute('data-confirm');
        if (confirmMsg && !window.confirm(confirmMsg)) {
            return;
        }

        var data = new FormData(form);
        data.append('kop_ajax', '1');
        // FormData skips the clicked submit button - forms with several
        // submit buttons (do_file / do_delete) need it carried over.
        if (e.submitter && e.submitter.name) {
            data.append(e.submitter.name, e.submitter.value || '1');
        }
        var buttons = Array.prototype.slice.call(form.querySelectorAll('button'));
        buttons.forEach(function (b) { b.disabled = true; });

        fetch(window.location.href, { method: 'POST', body: data, credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                if (!res.success) {
                    toast(res.errors, true);
                    return;
                }
                toast(res.notices, false);

                var target = form.getAttribute('data-remove-target');
                var removed = null;
                if (target) {
                    removed = document.querySelector(target);
                } else if (form.getAttribute('data-remove') === 'row') {
                    removed = form.closest('tr');
                } else if (form.getAttribute('data-remove') === 'card') {
                    removed = form.closest('.group, .arc');
                }
                if (removed) {
                    // Removing a row from a duplicate group can leave a single
                    // copy behind - then the whole group card can go too.
                    var card = removed.tagName === 'TR' ? removed.closest('.group') : null;
                    removed.remove();
                    if (target && !removed.contains(form)) {
                        form.remove(); // e.g. a "delete only #id" button below the table
                    }
                    if (card && card.querySelectorAll('tbody tr').length < 2) {
                        card.remove();
                    }
                }
                form.dispatchEvent(new CustomEvent('kop:inline-success', { detail: res }));
            })
            .catch(function () {
                toast(['Request failed - reload the page and try again.'], true);
            })
            .finally(function () {
                buttons.forEach(function (b) { b.disabled = false; });
            });
    });
})();
