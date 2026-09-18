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

    var current = null;      // the card being edited
    var coverId = 0;         // chosen attachment id, 0 = fall back to the PDF
    var mediaFrame = null;

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

    function cardFields(card) {
        return {
            titleLink: card.querySelector('.kop-rl-title a'),
            title: card.querySelector('.kop-rl-title'),
            desc: card.querySelector('.kop-rl-desc'),
            why: card.querySelector('.kop-rl-why'),
            tier: card.querySelector('.kop-rl-tier'),
            body: card.querySelector('.kop-rl-body'),
            image: card.querySelector('.kop-rl-cover img'),
            cover: card.querySelector('.kop-rl-cover')
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
                relevance_note: whyInput.value.trim()
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
