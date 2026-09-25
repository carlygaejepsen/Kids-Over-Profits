(function () {
    'use strict';

    var config = window.KOP_DOCUMENT_LINKS;
    if (!config || !config.list || !config.save) return;

    var resultsNode = document.getElementById('kop-dfl-results');
    var coverageNode = document.getElementById('kop-dfl-coverage');
    var paginationNode = document.getElementById('kop-dfl-pagination');
    var messageNode = document.getElementById('kop-dfl-message');
    var searchInput = document.getElementById('kop-dfl-search');
    var filterInput = document.getElementById('kop-dfl-filter');
    var dialog = document.getElementById('kop-dfl-dialog');
    var associationsNode = dialog.querySelector('.kop-dfl-associations');
    var facilityQuery = document.getElementById('kop-dfl-facility-query');
    var facilityResults = document.getElementById('kop-dfl-facility-results');
    var saveButton = document.getElementById('kop-dfl-save');
    var dialogMessage = dialog.querySelector('.kop-dfl-dialog-message');
    var page = 1;
    var pages = 1;
    var current = null;
    var associations = [];
    var searchTimer = null;
    var facilitySearchTimer = null;
    var requestToken = 0;

    function request(url, options) {
        options = options || {};
        options.credentials = 'same-origin';
        options.headers = Object.assign({}, options.headers || {}, { 'X-WP-Nonce': config.nonce });
        return fetch(url, options).then(function (response) {
            return response.json().then(function (body) {
                if (!response.ok) throw new Error(body && body.message ? body.message : 'Request failed (' + response.status + ').');
                return body;
            });
        });
    }

    function clear(node) {
        while (node.firstChild) node.removeChild(node.firstChild);
    }

    function make(tag, className, text) {
        var node = document.createElement(tag);
        if (className) node.className = className;
        if (text !== undefined) node.textContent = text;
        return node;
    }

    function load() {
        var token = ++requestToken;
        var query = new URLSearchParams({
            q: searchInput.value.trim(),
            status: filterInput.value,
            page: String(page),
            per_page: '25'
        });
        resultsNode.setAttribute('aria-busy', 'true');
        request(config.list + '?' + query.toString()).then(function (body) {
            if (token !== requestToken) return;
            renderCoverage(body.coverage || {});
            renderResults(body.results || []);
            page = parseInt(body.page, 10) || 1;
            pages = parseInt(body.pages, 10) || 1;
            renderPagination();
            resultsNode.removeAttribute('aria-busy');
        }).catch(function (error) {
            if (token !== requestToken) return;
            resultsNode.removeAttribute('aria-busy');
            clear(resultsNode);
            resultsNode.appendChild(make('p', 'notice notice-error', error.message));
        });
    }

    function renderCoverage(coverage) {
        var total = parseInt(coverage.total, 10) || 0;
        var reviewed = parseInt(coverage.reviewed, 10) || 0;
        var unreviewed = parseInt(coverage.unreviewed, 10) || 0;
        clear(coverageNode);
        var strong = make('strong', '', reviewed.toLocaleString() + ' reviewed');
        coverageNode.appendChild(strong);
        coverageNode.appendChild(make('span', '', unreviewed.toLocaleString() + ' still need review'));
        coverageNode.appendChild(make('span', '', total.toLocaleString() + ' total library documents'));
    }

    function renderResults(items) {
        clear(resultsNode);
        if (!items.length) {
            resultsNode.appendChild(make('p', 'kop-dfl-empty', 'No documents match this search.'));
            return;
        }
        var table = make('table', 'widefat striped kop-dfl-table');
        var head = make('thead');
        var tr = make('tr');
        ['Document', 'FileBird folders', 'Facility links', 'Review', ''].forEach(function (label) {
            tr.appendChild(make('th', '', label));
        });
        head.appendChild(tr);
        table.appendChild(head);
        var body = make('tbody');
        items.forEach(function (item) {
            var row = make('tr');
            row.setAttribute('data-key', item.key);

            var titleCell = make('td', 'kop-dfl-title-cell');
            var title = make(item.url ? 'a' : 'span', 'kop-dfl-title', item.title || 'Untitled document');
            if (item.url) {
                title.href = item.url;
                title.target = '_blank';
                title.rel = 'noopener';
            }
            titleCell.appendChild(title);
            titleCell.appendChild(make('small', '', item.mime === 'external' ? 'Publisher link' : (item.mime || 'File')));
            row.appendChild(titleCell);

            row.appendChild(make('td', 'kop-dfl-folders', (item.folders || []).join(' · ') || '—'));

            var linked = (item.associations || []).map(function (association) { return association.name; });
            row.appendChild(make('td', 'kop-dfl-linked', linked.length ? linked.join(', ') : 'No facilities tagged'));

            var reviewed = !!item.reviewed_at;
            var needsContext = linked.length && (item.context_status !== 'complete');
            var reviewLabel = reviewed ? (needsContext ? 'Reviewed · add note/page' : 'Reviewed') : 'Needs review';
            var reviewCell = make('td', 'kop-dfl-review');
            reviewCell.appendChild(make('span', reviewed ? (needsContext ? 'kop-dfl-status is-context' : 'kop-dfl-status is-reviewed') : 'kop-dfl-status is-pending', reviewLabel));
            row.appendChild(reviewCell);

            var actionCell = make('td', 'kop-dfl-action');
            var button = make('button', 'button', 'Edit links');
            button.type = 'button';
            button.addEventListener('click', function () { openEditor(item); });
            actionCell.appendChild(button);
            row.appendChild(actionCell);
            body.appendChild(row);
        });
        table.appendChild(body);
        resultsNode.appendChild(table);
    }

    function renderPagination() {
        clear(paginationNode);
        var previous = make('button', 'button', 'Previous');
        previous.type = 'button';
        previous.disabled = page <= 1;
        previous.addEventListener('click', function () { if (page > 1) { page--; load(); } });
        paginationNode.appendChild(previous);
        paginationNode.appendChild(make('span', 'kop-dfl-page-number', 'Page ' + page + ' of ' + pages));
        var next = make('button', 'button', 'Next');
        next.type = 'button';
        next.disabled = page >= pages;
        next.addEventListener('click', function () { if (page < pages) { page++; load(); } });
        paginationNode.appendChild(next);
    }

    function openEditor(item) {
        current = item;
        associations = (item.associations || []).map(function (association) {
            return {
                id: parseInt(association.id, 10) || 0,
                name: association.name || '',
                place: association.place || '',
                url: association.url || '',
                note: association.note || '',
                pages: association.pages || ''
            };
        });
        dialog.querySelector('.kop-dfl-doc-title').textContent = item.title || 'Untitled document';
        facilityQuery.value = '';
        clear(facilityResults);
        facilityResults.hidden = true;
        dialogMessage.textContent = '';
        saveButton.disabled = false;
        drawAssociations();
        if (typeof dialog.showModal === 'function') dialog.showModal();
        else dialog.setAttribute('open', 'open');
        facilityQuery.focus();
    }

    function closeEditor() {
        if (typeof dialog.close === 'function') dialog.close();
        else dialog.removeAttribute('open');
        current = null;
    }

    function drawAssociations() {
        clear(associationsNode);
        if (!associations.length) {
            associationsNode.appendChild(make('p', 'kop-dfl-no-associations', 'No facilities tagged. Saving will mark this document reviewed with no facility matches.'));
            return;
        }
        associations.forEach(function (association, index) {
            var fieldset = make('fieldset', 'kop-dfl-association');
            var legend = make('legend', '', association.name + (association.place ? ' · ' + association.place : ''));
            fieldset.appendChild(legend);
            var remove = make('button', 'kop-dfl-remove', 'Remove facility');
            remove.type = 'button';
            remove.addEventListener('click', function () {
                associations.splice(index, 1);
                drawAssociations();
            });
            fieldset.appendChild(remove);

            var noteLabel = make('label', 'kop-dfl-field');
            noteLabel.appendChild(make('span', '', 'What does the document say about this facility?'));
            var note = make('textarea');
            note.maxLength = 500;
            note.rows = 3;
            note.placeholder = 'For example: Describes the program as a foster-care placement and discusses its daily rate.';
            note.value = association.note;
            note.addEventListener('input', function () { association.note = note.value; });
            noteLabel.appendChild(note);
            fieldset.appendChild(noteLabel);

            var pagesLabel = make('label', 'kop-dfl-field');
            pagesLabel.appendChild(make('span', '', 'Page or page range'));
            var pagesInput = make('input');
            pagesInput.type = 'text';
            pagesInput.maxLength = 120;
            pagesInput.placeholder = 'e.g. p. 8 or pp. 21–23';
            pagesInput.value = association.pages;
            pagesInput.addEventListener('input', function () { association.pages = pagesInput.value; });
            pagesLabel.appendChild(pagesInput);
            fieldset.appendChild(pagesLabel);
            associationsNode.appendChild(fieldset);
        });
    }

    function addAssociation(row) {
        var id = parseInt(row.id, 10) || 0;
        if (!id || associations.some(function (association) { return association.id === id; })) return;
        associations.push({
            id: id,
            name: row.name || '',
            place: row.place || '',
            url: row.url || '',
            note: '',
            pages: ''
        });
        drawAssociations();
    }

    function searchFacilities() {
        var query = facilityQuery.value.trim();
        if (query.length < 2 || !config.facilitySearch) {
            clear(facilityResults);
            facilityResults.hidden = true;
            return;
        }
        request(config.facilitySearch + '?q=' + encodeURIComponent(query)).then(function (body) {
            clear(facilityResults);
            var rows = body.results || [];
            if (!rows.length) {
                facilityResults.appendChild(make('li', 'kop-dfl-no-result', 'No matching facilities.'));
            }
            rows.forEach(function (row) {
                if (associations.some(function (association) { return association.id === parseInt(row.id, 10); })) return;
                var li = make('li');
                var button = make('button', 'kop-dfl-facility-result', row.name + (row.place ? ' · ' + row.place : ''));
                button.type = 'button';
                button.addEventListener('click', function () {
                    addAssociation(row);
                    facilityQuery.value = '';
                    clear(facilityResults);
                    facilityResults.hidden = true;
                    facilityQuery.focus();
                });
                li.appendChild(button);
                facilityResults.appendChild(li);
            });
            facilityResults.hidden = false;
        }).catch(function (error) {
            dialogMessage.textContent = error.message;
        });
    }

    function save() {
        if (!current) return;
        saveButton.disabled = true;
        dialogMessage.textContent = 'Saving…';
        request(config.save, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                key: current.key,
                associations: associations.map(function (association) {
                    return { facility_id: association.id, note: association.note.trim(), pages: association.pages.trim() };
                })
            })
        }).then(function () {
            messageNode.textContent = 'Document links saved and review status updated.';
            closeEditor();
            load();
        }).catch(function (error) {
            saveButton.disabled = false;
            dialogMessage.textContent = error.message;
        });
    }

    searchInput.addEventListener('input', function () {
        window.clearTimeout(searchTimer);
        searchTimer = window.setTimeout(function () { page = 1; load(); }, 300);
    });
    filterInput.addEventListener('change', function () { page = 1; load(); });
    document.getElementById('kop-dfl-refresh').addEventListener('click', function () { load(); });
    facilityQuery.addEventListener('input', function () {
        window.clearTimeout(facilitySearchTimer);
        facilitySearchTimer = window.setTimeout(searchFacilities, 250);
    });
    facilityQuery.addEventListener('keydown', function (event) {
        if (event.key === 'Enter') { event.preventDefault(); searchFacilities(); }
    });
    document.getElementById('kop-dfl-cancel').addEventListener('click', closeEditor);
    saveButton.addEventListener('click', save);
    dialog.querySelector('.kop-dfl-form').addEventListener('submit', function (event) { event.preventDefault(); save(); });
    load();
}());
