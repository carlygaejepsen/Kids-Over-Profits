/**
 * In-place editor for the Research & Reports card grid.
 *
 * Loaded only for users who can edit the page (see kop_research_enqueue_editor
 * in inc/research-library.php). Each card carries a pencil button; clicking it
 * opens the one dialog on the page, filled from that card. Saving POSTs to
 * kop/v1/research-entry and writes the response straight back into the card,
 * so nothing reloads.
 */
(function () {
    'use strict';

    var config = window.KOP_RESEARCH_EDITOR;
    if (!config || !config.endpoint) {
        return;
    }

    var grid = document.querySelector('.kop-research-library');
    var dialog = document.getElementById('kop-rl-dialog');
    if (!grid || !dialog) {
        return;
    }

    var titleInput = dialog.querySelector('.kop-rl-input-title');
    var descInput = dialog.querySelector('.kop-rl-input-desc');
    var tierInput = dialog.querySelector('.kop-rl-input-relevance');
    var whyInput = dialog.querySelector('.kop-rl-input-why');
    var preview = dialog.querySelector('.kop-rl-photo-preview');
    var message = dialog.querySelector('.kop-rl-form-msg');
    var saveButton = dialog.querySelector('.kop-rl-save');

    var tagBox = dialog.querySelector('.kop-rl-tags');
    var tagQuery = dialog.querySelector('.kop-rl-tag-query');
    var tagResults = dialog.querySelector('.kop-rl-tag-results');

    var current = null;      // the card being edited
    var coverId = 0;         // chosen attachment id, 0 = fall back to the PDF
    var mediaFrame = null;
    var tags = [];           // [{id, name, place}] for the card being edited
    var searchTimer = null;

    /** Fall back to alert() where <dialog> is not supported. */
    function openDialog() {
        if (typeof dialog.showModal === 'function') {
            dialog.showModal();
        } else {
            dialog.setAttribute('open', 'open');
        }
    }

    function closeDialog() {
        if (typeof dialog.close === 'function') {
            dialog.close();
        } else {
            dialog.removeAttribute('open');
        }
        current = null;
    }

    function setMessage(text, isError) {
        message.textContent = text || '';
        message.classList.toggle('is-error', !!isError);
    }

    /** Draw the chips for whatever is in `tags`, each with a remove button. */
    function drawTags() {
        tagBox.innerHTML = '';
        if (!tags.length) {
            var none = document.createElement('p');
            none.className = 'kop-rl-tags-empty';
            none.textContent = 'No program tagged yet.';
            tagBox.appendChild(none);
            return;
        }
        tags.forEach(function (tag) {
            var chip = document.createElement('span');
            chip.className = 'kop-rl-tag';
            chip.setAttribute('role', 'listitem');

            var label = document.createElement('span');
            label.textContent = tag.name + (tag.place ? ' (' + tag.place + ')' : '');
            chip.appendChild(label);

            var remove = document.createElement('button');
            remove.type = 'button';
            remove.className = 'kop-rl-tag-remove';
            remove.setAttribute('data-id', tag.id);
            remove.innerHTML = '<span aria-hidden="true">&times;</span>';
            var sr = document.createElement('span');
            sr.className = 'screen-reader-text';
            sr.textContent = 'Remove ' + tag.name;
            remove.appendChild(sr);
            chip.appendChild(remove);

            tagBox.appendChild(chip);
        });
    }

    function addTag(tag) {
        var id = parseInt(tag.id, 10) || 0;
        if (!id || tags.some(function (t) { return t.id === id; })) {
            return;
        }
        tags.push({ id: id, name: tag.name, place: tag.place || '' });
        drawTags();
    }

    function removeTag(id) {
        tags = tags.filter(function (tag) { return tag.id !== id; });
        drawTags();
    }

    function clearResults() {
        tagResults.innerHTML = '';
        tagResults.setAttribute('hidden', 'hidden');
    }

    function searchFacilities() {
        var phrase = tagQuery.value.trim();
        if (phrase.length < 2 || !config.search) {
            clearResults();
            return;
        }
        fetch(config.search + '?q=' + encodeURIComponent(phrase), {
            credentials: 'same-origin',
            headers: { 'X-WP-Nonce': config.nonce }
        }).then(function (response) {
            return response.ok ? response.json() : { results: [] };
        }).then(function (body) {
            var results = (body && body.results) || [];
            tagResults.innerHTML = '';
            if (!results.length) {
                var empty = document.createElement('li');
                empty.className = 'kop-rl-tag-none';
                empty.textContent = 'No facility of that name.';
                tagResults.appendChild(empty);
            }
            results.forEach(function (row) {
                var li = document.createElement('li');
                var button = document.createElement('button');
                button.type = 'button';
                button.className = 'kop-rl-tag-add';
                button.setAttribute('data-id', row.id);
                button.setAttribute('data-name', row.name);
                button.setAttribute('data-place', row.place || '');
                button.textContent = row.name + (row.place ? ' - ' + row.place : '');
                li.appendChild(button);
                tagResults.appendChild(li);
            });
            tagResults.removeAttribute('hidden');
        }).catch(function () {
            clearResults();
        });
    }

    function cardFields(card) {
        return {
            titleLink: card.querySelector('.kop-rl-title a'),
            title: card.querySelector('.kop-rl-title'),
            desc: card.querySelector('.kop-rl-desc'),
            why: card.querySelector('.kop-rl-why'),
            tier: card.querySelector('.kop-rl-tier'),
            body: card.querySelector('.kop-rl-body'),
            image: card.querySelector('.kop-rl-cover img'),
            cover: card.querySelector('.kop-rl-cover'),
            facilities: card.querySelector('.kop-rl-facilities')
        };
    }

    function edit(card) {
        current = card;
        coverId = parseInt(card.getAttribute('data-cover-id'), 10) || 0;

        var fields = cardFields(card);
        // A card with nothing to link to prints its title as plain text.
        var titleNode = fields.titleLink || fields.title;
        titleInput.value = titleNode ? titleNode.textContent.trim() : '';
        descInput.value = fields.desc ? fields.desc.textContent.trim() : '';
        tierInput.value = card.getAttribute('data-relevance') || '0';
        whyInput.value = fields.why ? fields.why.textContent.trim() : '';

        // The chips on the card are the stored tags; read them back out of it
        // so the dialog needs no extra request.
        tags = [];
        if (fields.facilities) {
            Array.prototype.forEach.call(fields.facilities.querySelectorAll('.kop-rl-chip'), function (chip) {
                tags.push({
                    id: parseInt(chip.getAttribute('data-id'), 10) || 0,
                    name: chip.textContent.trim(),
                    place: chip.getAttribute('title') || ''
                });
            });
        }
        tags = tags.filter(function (tag) { return tag.id > 0; });
        drawTags();
        tagQuery.value = '';
        clearResults();
        preview.src = fields.image ? fields.image.getAttribute('src') : '';
        preview.style.visibility = preview.src ? 'visible' : 'hidden';

        setMessage('');
        saveButton.disabled = false;
        openDialog();
        titleInput.focus();
    }

    /** WordPress media modal, reused across cards. */
    function pickPhoto() {
        if (!window.wp || !window.wp.media) {
            setMessage('The media library did not load. Reload the page and try again.', true);
            return;
        }
        if (!mediaFrame) {
            mediaFrame = window.wp.media({
                title: 'Choose a cover image',
                button: { text: 'Use this image' },
                library: { type: 'image' },
                multiple: false
            });
            mediaFrame.on('select', function () {
                var attachment = mediaFrame.state().get('selection').first().toJSON();
                coverId = attachment.id;
                var sizes = attachment.sizes || {};
                var chosen = sizes.large || sizes.medium_large || sizes.medium || sizes.full;
                preview.src = chosen ? chosen.url : attachment.url;
                preview.style.visibility = 'visible';
                setMessage('');
            });
        }
        mediaFrame.open();
    }

    function clearPhoto() {
        coverId = 0;
        setMessage("Saving will go back to the document's own first page.");
    }

    function save() {
        if (!current) {
            return;
        }
        var title = titleInput.value.trim();
        if (!title) {
            setMessage('A title is required.', true);
            titleInput.focus();
            return;
        }

        saveButton.disabled = true;
        setMessage('Saving...');

        fetch(config.endpoint, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'X-WP-Nonce': config.nonce
            },
            body: JSON.stringify({
                key: current.getAttribute('data-key'),
                title: title,
                description: descInput.value.trim(),
                cover_id: coverId,
                relevance: parseInt(tierInput.value, 10) || 0,
                relevance_note: whyInput.value.trim(),
                facilities: tags.map(function (tag) { return tag.id; })
            })
        }).then(function (response) {
            return response.json().then(function (body) {
                if (!response.ok) {
                    throw new Error(body && body.message ? body.message : 'Save failed (' + response.status + ').');
                }
                return body;
            });
        }).then(function (body) {
            applyToCard(current, body);
            closeDialog();
        }).catch(function (error) {
            saveButton.disabled = false;
            setMessage(error.message || 'Save failed.', true);
        });
    }

    function applyToCard(card, body) {
        var fields = cardFields(card);

        if (fields.titleLink) {
            fields.titleLink.textContent = body.title;
        } else if (fields.title) {
            fields.title.textContent = body.title;
        }
        if (fields.desc) {
            fields.desc.textContent = body.description || '';
            if (body.description) {
                fields.desc.removeAttribute('hidden');
            } else {
                fields.desc.setAttribute('hidden', 'hidden');
            }
        }
        card.setAttribute('data-cover-id', body.cover_id || 0);
        card.setAttribute('data-relevance', body.relevance || 0);
        card.setAttribute('data-title', body.title);

        if (fields.why) {
            fields.why.textContent = body.relevance_note || '';
            if (body.relevance_note) {
                fields.why.removeAttribute('hidden');
            } else {
                fields.why.setAttribute('hidden', 'hidden');
            }
        }

        if (fields.facilities) {
            var chips = body.facilities || [];
            var label = fields.facilities.querySelector('.kop-rl-facilities-label');
            fields.facilities.innerHTML = '';
            if (label) {
                fields.facilities.appendChild(label);
            }
            chips.forEach(function (row) {
                var link = document.createElement('a');
                link.className = 'kop-rl-chip';
                link.setAttribute('href', row.url || '#');
                link.setAttribute('data-id', row.id);
                if (row.place) {
                    link.setAttribute('title', row.place);
                }
                link.textContent = row.name;
                fields.facilities.appendChild(link);
            });
            if (chips.length) {
                fields.facilities.removeAttribute('hidden');
            } else {
                fields.facilities.setAttribute('hidden', 'hidden');
            }
        }

        // The tier badge may need creating: an unrated card has none.
        if (fields.tier && !body.relevance) {
            fields.tier.remove();
        } else if (body.relevance) {
            var badge = fields.tier;
            if (!badge && fields.body) {
                badge = document.createElement('span');
                fields.body.insertBefore(badge, fields.body.firstChild);
            }
            if (badge) {
                badge.className = 'kop-rl-tier kop-rl-tier-' + body.relevance;
                badge.textContent = body.relevance_label || '';
            }
        }

        if (body.cover) {
            if (fields.image) {
                fields.image.setAttribute('src', body.cover);
            } else if (fields.cover) {
                // The card had no image at all (a placeholder letter box).
                fields.cover.innerHTML = '';
                var img = document.createElement('img');
                img.setAttribute('src', body.cover);
                img.setAttribute('alt', '');
                img.setAttribute('loading', 'lazy');
                fields.cover.appendChild(img);
            }
        }

        card.classList.add('is-saved');
        window.setTimeout(function () {
            card.classList.remove('is-saved');
        }, 1500);
    }

    grid.addEventListener('click', function (event) {
        var button = event.target.closest('.kop-rl-edit');
        if (button) {
            event.preventDefault();
            edit(button.closest('.kop-rl-card'));
            return;
        }

        var toggle = event.target.closest('.kop-rl-edit-toggle');
        if (toggle) {
            event.preventDefault();
            var on = grid.classList.toggle('is-editing');
            toggle.setAttribute('aria-pressed', on ? 'true' : 'false');
            toggle.textContent = on ? 'Done editing' : 'Edit entries';
        }
    });

    // A method="dialog" form closes on Enter in a text field, which would throw
    // the edit away. Save instead.
    dialog.querySelector('.kop-rl-form').addEventListener('submit', function (event) {
        event.preventDefault();
        save();
    });

    tagBox.addEventListener('click', function (event) {
        var remove = event.target.closest('.kop-rl-tag-remove');
        if (!remove) {
            return;
        }
        event.preventDefault();
        removeTag(parseInt(remove.getAttribute('data-id'), 10) || 0);
    });

    tagResults.addEventListener('click', function (event) {
        var add = event.target.closest('.kop-rl-tag-add');
        if (!add) {
            return;
        }
        event.preventDefault();
        addTag({
            id: add.getAttribute('data-id'),
            name: add.getAttribute('data-name'),
            place: add.getAttribute('data-place')
        });
        tagQuery.value = '';
        clearResults();
        tagQuery.focus();
    });

    // Typing settles before asking the server.
    tagQuery.addEventListener('input', function () {
        window.clearTimeout(searchTimer);
        searchTimer = window.setTimeout(searchFacilities, 250);
    });

    // Enter in the search box must not submit the dialog's form.
    tagQuery.addEventListener('keydown', function (event) {
        if (event.key === 'Enter') {
            event.preventDefault();
            window.clearTimeout(searchTimer);
            searchFacilities();
        }
    });

    dialog.querySelector('.kop-rl-photo-pick').addEventListener('click', pickPhoto);
    dialog.querySelector('.kop-rl-photo-clear').addEventListener('click', clearPhoto);
    dialog.querySelector('.kop-rl-cancel').addEventListener('click', closeDialog);
    saveButton.addEventListener('click', save);

    // Ctrl/Cmd+Enter saves from either field.
    dialog.addEventListener('keydown', function (event) {
        if (event.key === 'Enter' && (event.metaKey || event.ctrlKey)) {
            event.preventDefault();
            save();
        }
    });
}());
