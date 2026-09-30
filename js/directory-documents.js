/**
 * "Documents on file" for directory cards (educational consultants,
 * transporters): the record's FileBird folder, read from
 * /wp-json/kop/v1/folder-content?format=tree when the card is first opened,
 * so a page of fifty cards does not fetch fifty folders up front.
 *
 * A card opts in with <div class="kop-dir-docs" data-folder="123"></div>
 * inside a <details>; call window.kopDirectoryDocs.attach(card) after it is
 * built. The folder comes from the record's documentFolderId, set when the
 * record is created (inc/woodbury-create.php) or by api/data-manager.php.
 */
(function () {
    'use strict';

    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    /** The record's folder id, wherever the form or a tool stored it. */
    function folderOf(payload) {
        if (!payload || typeof payload !== 'object') return 0;
        var id = payload.documentFolderId || (payload.data && payload.data.documentFolderId) || 0;
        id = parseInt(id, 10);
        return id > 0 ? id : 0;
    }

    function fileItem(f) {
        var date = f.date ? String(f.date).slice(0, 10) : '';
        return '<li class="kop-dir-docs-item"><a href="' + esc(f.url) + '" target="_blank" rel="noopener">'
            + esc(f.title || 'Document') + '</a>' + (date ? ' <span class="kop-dir-docs-date">' + esc(date) + '</span>' : '') + '</li>';
    }

    function renderNode(files, subfolders, depth) {
        var html = '';
        if (files && files.length) {
            html += '<ul class="kop-dir-docs-list">' + files.map(fileItem).join('') + '</ul>';
        }
        (subfolders || []).forEach(function (sub) {
            var inner = renderNode(sub.files, sub.subfolders, depth + 1);
            if (!inner) return;
            html += '<div class="kop-dir-docs-sub"><div class="kop-dir-docs-subname">' + esc(sub.name) + '</div>' + inner + '</div>';
        });
        return html;
    }

    function load(box) {
        if (box.getAttribute('data-loaded')) return;
        box.setAttribute('data-loaded', '1');
        var id = box.getAttribute('data-folder');
        box.innerHTML = '<div class="section-label">Documents on file</div><p class="kop-dir-docs-note">Loading...</p>';
        fetch('/wp-json/kop/v1/folder-content?format=tree&id=' + encodeURIComponent(id), { credentials: 'same-origin' })
            .then(function (r) { if (!r.ok) throw new Error(r.status); return r.json(); })
            .then(function (tree) {
                var html = renderNode(tree.files, tree.subfolders, 0);
                if (!html) {
                    box.remove();
                    return;
                }
                box.innerHTML = '<div class="section-label">Documents on file</div>' + html;
            })
            .catch(function () {
                box.innerHTML = '<div class="section-label">Documents on file</div><p class="kop-dir-docs-note">Could not load the documents.</p>';
            });
    }

    function attach(card) {
        var box = card.querySelector('.kop-dir-docs[data-folder]');
        if (!box) return;
        if (card.open) {
            load(box);
            return;
        }
        card.addEventListener('toggle', function () { if (card.open) load(box); });
    }

    /** The placeholder a card template drops in, or '' when the record has no folder. */
    function slot(payload) {
        var id = folderOf(payload);
        return id ? '<div class="kop-dir-docs" data-folder="' + id + '"></div>' : '';
    }

    window.kopDirectoryDocs = { attach: attach, slot: slot, folderOf: folderOf };
})();
