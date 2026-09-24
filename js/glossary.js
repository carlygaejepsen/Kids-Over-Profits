/**
 * TTI glossary: instant filtering.
 *
 * The page works without this file: every entry is rendered on the server and
 * the search box and program picker are a plain GET form. This makes the same
 * two filters instant, with the same rules as inc/glossary.php (every word of
 * the search must appear; the program must be one of the entry's tags), and
 * keeps ?q= and ?program= in the address bar so a filtered view can be shared.
 *
 * It also keeps the contents rail and the sticky filter bar out of each
 * other's way, and makes a cross-reference to an entry the filter has hidden
 * clear the filter first, so a link never lands on nothing.
 */
(function () {
    'use strict';

    var root = document.querySelector('.kop-gl');
    var form = root && root.querySelector('.kop-gl-filter');
    if (!root || !form) {
        return;
    }

    var input = form.querySelector('#kop-gl-q');
    var select = form.querySelector('#kop-gl-program');
    var status = form.querySelector('.kop-gl-status');
    var clear = form.querySelector('.kop-gl-clear');
    var go = form.querySelector('.kop-gl-go');
    var none = root.querySelector('.kop-gl-none');
    var profileLink = form.querySelector('.kop-gl-profile-link');
    var entries = Array.prototype.slice.call(root.querySelectorAll('.kop-gl-entry'));
    var groups = Array.prototype.slice.call(root.querySelectorAll('.kop-gl-group'));
    var sections = Array.prototype.slice.call(root.querySelectorAll('.kop-gl-section'));
    var notes = Array.prototype.slice.call(root.querySelectorAll('.kop-gl-note, .kop-gl-letters'));
    var page = document.querySelector('.kop-gl-page') || document.documentElement;

    /* The button is only needed when this script has not run. */
    if (go) {
        go.hidden = true;
    }

    /* ---- Sticky offsets ------------------------------------------------ */

    /* Kadence's sticky header only turns fixed once the page scrolls, and
     * would then cover the filter bar; keep the bar below whatever part of
     * it is on screen. */
    var headers = document.querySelectorAll('.kadence-sticky-header, #masthead');
    function measure() {
        var top = 0;
        for (var i = 0; i < headers.length; i++) {
            var style = window.getComputedStyle(headers[i]);
            if (style.position !== 'fixed' && style.position !== 'sticky') {
                continue;
            }
            var rect = headers[i].getBoundingClientRect();
            if (rect.height > 0 && rect.top <= 0 && rect.bottom > top) {
                top = rect.bottom;
            }
        }
        page.style.setProperty('--kop-gl-sticky-top', Math.round(top) + 'px');
        page.style.setProperty('--kop-gl-filter-height', form.getBoundingClientRect().height + 'px');
    }
    var queued = false;
    function queueMeasure() {
        if (!queued) {
            queued = true;
            window.requestAnimationFrame(function () {
                queued = false;
                measure();
            });
        }
    }
    measure();
    window.addEventListener('resize', queueMeasure);
    window.addEventListener('scroll', queueMeasure, { passive: true });

    /* Contents open beside the list on a wide screen, folded on a phone. */
    var toc = root.querySelector('.kop-gl-toc details');
    if (toc && window.matchMedia) {
        toc.open = window.matchMedia('(min-width: 64rem)').matches;
    }

    /* ---- Filtering ----------------------------------------------------- */

    function programName(slug) {
        var option = select && select.querySelector('option[value="' + slug + '"]');
        return option ? option.textContent.replace(/\s*\(\d+\)\s*$/, '').trim() : '';
    }

    function statusText(shown, program, query) {
        var text = shown === 1 ? '1 term' : shown.toLocaleString() + ' terms';
        if (program) {
            text += ' tagged ' + programName(program);
        }
        if (query) {
            text += ' matching “' + query + '”';
        }
        return text;
    }

    function apply(push) {
        var query = input ? input.value.replace(/\s+/g, ' ').trim() : '';
        var words = query ? query.toLowerCase().split(' ') : [];
        var program = select ? select.value : '';
        var filtered = !!(query || program);
        var shown = 0;

        entries.forEach(function (entry) {
            var ok = true;
            if (program) {
                ok = (' ' + entry.getAttribute('data-programs') + ' ').indexOf(' ' + program + ' ') !== -1;
            }
            if (ok && words.length) {
                var hay = entry.getAttribute('data-search') || '';
                for (var i = 0; i < words.length; i++) {
                    if (hay.indexOf(words[i]) === -1) {
                        ok = false;
                        break;
                    }
                }
            }
            entry.hidden = !ok;
            if (ok) {
                shown++;
            }
        });

        /* Innermost first, so a family shows when any of its programs does. */
        groups.slice().reverse().concat(sections).forEach(function (block) {
            block.hidden = !block.querySelector('.kop-gl-entry:not([hidden])');
        });
        notes.forEach(function (note) {
            note.hidden = filtered;
        });

        root.querySelectorAll('.kop-gl-tag').forEach(function (tag) {
            tag.classList.toggle('is-active', !!program && tag.getAttribute('data-program') === program);
        });

        if (status) {
            status.textContent = filtered ? statusText(shown, program, query) : '';
        }
        if (clear) {
            clear.hidden = !filtered;
        }
        if (profileLink) {
            var option = program && select ? select.options[select.selectedIndex] : null;
            var profile = option ? option.getAttribute('data-profile') : '';
            profileLink.hidden = !profile;
            if (profile) {
                profileLink.href = profile;
                profileLink.textContent = 'Open the ' + programName(program) + ' profile';
            }
        }
        if (none) {
            none.hidden = !filtered || shown > 0;
        }
        measure();

        if (push !== false && window.history && window.history.replaceState) {
            var url = new URL(window.location.href);
            url.hash = '';
            url.searchParams.delete('q');
            url.searchParams.delete('program');
            if (query) {
                url.searchParams.set('q', query);
            }
            if (program) {
                url.searchParams.set('program', program);
            }
            window.history.replaceState(null, '', url.toString());
        }
    }

    function reset() {
        if (input) {
            input.value = '';
        }
        if (select) {
            select.value = '';
        }
        apply();
    }

    var timer = null;
    if (input) {
        input.addEventListener('input', function () {
            window.clearTimeout(timer);
            timer = window.setTimeout(apply, 150);
        });
    }
    if (select) {
        select.addEventListener('change', function () {
            apply();
        });
    }
    form.addEventListener('submit', function (event) {
        event.preventDefault();
        apply();
    });
    if (clear) {
        clear.addEventListener('click', function (event) {
            event.preventDefault();
            reset();
            if (input) {
                input.focus();
            }
        });
    }

    /* A program tag filters in place instead of reloading. */
    root.addEventListener('click', function (event) {
        var tag = event.target.closest('.kop-gl-tag-filter');
        if (tag && select) {
            event.preventDefault();
            select.value = tag.getAttribute('data-program') || '';
            if (input) {
                input.value = '';
            }
            apply();
            form.scrollIntoView({ block: 'start' });
            return;
        }

        /* A link to an entry the filter hides: clear the filter, then go. */
        var link = event.target.closest('a[href*="#"]');
        if (!link || link.closest('.kop-gl-filter')) {
            return;
        }
        var url = new URL(link.href, window.location.href);
        if (url.pathname !== window.location.pathname) {
            return;
        }
        var id = decodeURIComponent(url.hash.slice(1));
        var target = id && document.getElementById(id);
        if (!target || !root.contains(target)) {
            return;
        }
        event.preventDefault();
        if (target.hidden || target.closest('[hidden]')) {
            reset();
        }
        flash(target);
        target.scrollIntoView({ block: 'start' });
        if (window.history && window.history.pushState) {
            var here = new URL(window.location.href);
            here.hash = id;
            window.history.pushState(null, '', here.toString());
        }
    });

    /* :target only follows real navigation; mark the entry ourselves. */
    var flashed = null;
    function flash(el) {
        if (flashed) {
            flashed.classList.remove('is-target');
        }
        if (el.classList.contains('kop-gl-entry')) {
            el.classList.add('is-target');
            flashed = el;
        }
    }

    /* ---- Definition popups -------------------------------------------- */

    /* Pointing at a cross-reference shows the entry it names without leaving
     * the place you were reading; clicking still jumps there (above). Only
     * for a mouse or the keyboard: on a touch screen a tap is the jump. */
    var pop = document.createElement('div');
    pop.className = 'kop-gl-pop';
    pop.id = 'kop-gl-pop';
    pop.setAttribute('role', 'tooltip');
    pop.hidden = true;
    document.body.appendChild(pop);

    var popFor = null;
    var showTimer = null;
    var hideTimer = null;

    function entryFor(link) {
        var href = link.getAttribute('href') || '';
        var hash = href.indexOf('#');
        if (hash === -1) {
            return null;
        }
        var target = document.getElementById(decodeURIComponent(href.slice(hash + 1)));
        return target && target.classList.contains('kop-gl-entry') ? target : null;
    }

    function fillPop(entry) {
        pop.textContent = '';
        var head = document.createElement('p');
        head.className = 'kop-gl-pop-term';
        var term = entry.querySelector('.kop-gl-term dfn');
        var qualifier = entry.querySelector('.kop-gl-term .kop-gl-qualifier');
        head.textContent = (term ? term.textContent : '') + (qualifier ? ' ' + qualifier.textContent : '');
        pop.appendChild(head);
        entry.querySelectorAll('.kop-gl-def > p').forEach(function (p) {
            var copy = p.cloneNode(true);
            /* Read-only copy: nothing inside it takes focus or a click. */
            copy.querySelectorAll('a').forEach(function (a) {
                var span = document.createElement('span');
                span.className = a.className;
                span.innerHTML = a.innerHTML;
                a.parentNode.replaceChild(span, a);
            });
            copy.removeAttribute('id');
            pop.appendChild(copy);
        });
    }

    function placePop(link) {
        var r = link.getBoundingClientRect();
        pop.style.left = '0px';
        pop.style.top = '0px';
        var w = pop.offsetWidth;
        var h = pop.offsetHeight;
        var vw = document.documentElement.clientWidth;
        var left = Math.min(Math.max(8, r.left), vw - w - 8);
        var below = r.bottom + 8;
        var top = (below + h > window.innerHeight - 8 && r.top - h - 8 > 8) ? r.top - h - 8 : below;
        pop.style.left = Math.round(left + window.scrollX) + 'px';
        pop.style.top = Math.round(top + window.scrollY) + 'px';
    }

    function showPop(link) {
        var entry = entryFor(link);
        if (!entry || entry.contains(link)) {
            return;
        }
        window.clearTimeout(hideTimer);
        if (popFor !== link) {
            if (popFor) {
                popFor.removeAttribute('aria-describedby');
            }
            fillPop(entry);
            popFor = link;
            link.setAttribute('aria-describedby', 'kop-gl-pop');
        }
        pop.hidden = false;
        placePop(link);
    }

    function hidePop() {
        window.clearTimeout(showTimer);
        pop.hidden = true;
        if (popFor) {
            popFor.removeAttribute('aria-describedby');
            popFor = null;
        }
    }

    function hideSoon() {
        window.clearTimeout(showTimer);
        window.clearTimeout(hideTimer);
        hideTimer = window.setTimeout(hidePop, 200);
    }

    /* After a jump the page moves under a still pointer, which would open
     * a popup for whatever link lands beneath it. Wait for a real move. */
    var stillSinceJump = false;

    function hoverLink(event) {
        if (event.pointerType === 'touch') {
            return;
        }
        var link = event.target.closest && event.target.closest('a.kop-gl-ref');
        if (!link) {
            return;
        }
        window.clearTimeout(hideTimer);
        window.clearTimeout(showTimer);
        showTimer = window.setTimeout(function () {
            showPop(link);
        }, 250);
    }

    root.addEventListener('pointerover', function (event) {
        if (!stillSinceJump) {
            hoverLink(event);
        }
    });
    /* The first real move after a jump counts as arriving on the link. */
    document.addEventListener('pointermove', function (event) {
        if (stillSinceJump) {
            stillSinceJump = false;
            hoverLink(event);
        }
    }, { passive: true });
    root.addEventListener('pointerout', function (event) {
        var link = event.target.closest('a.kop-gl-ref');
        if (link && !link.contains(event.relatedTarget)) {
            hideSoon();
        }
    });
    /* The popup can be pointed at, to read a long one or select its text. */
    pop.addEventListener('pointerover', function () {
        window.clearTimeout(hideTimer);
    });
    pop.addEventListener('pointerout', function (event) {
        if (!pop.contains(event.relatedTarget)) {
            hideSoon();
        }
    });
    root.addEventListener('focusin', function (event) {
        var link = event.target.closest && event.target.closest('a.kop-gl-ref');
        if (link && link.matches(':focus-visible')) {
            showPop(link);
        }
    });
    root.addEventListener('focusout', function (event) {
        if (event.target.closest && event.target.closest('a.kop-gl-ref')) {
            hidePop();
        }
    });
    root.addEventListener('click', function () {
        hidePop();
        stillSinceJump = true;
    });
    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && !pop.hidden) {
            hidePop();
        }
    });
    window.addEventListener('scroll', function () {
        if (popFor) {
            placePop(popFor);
        }
    }, { passive: true });

    /* ---- Reader feedback ---------------------------------------------- */

    /* Two buttons under every entry open one shared form, posted to
     * inc/glossary-feedback.php for review. Nothing changes on the page
     * until a person has checked it. */
    var feedbackUrl = root.getAttribute('data-feedback');
    if (feedbackUrl && typeof HTMLDialogElement === 'function') {
        root.querySelectorAll('.kop-gl-fb-row').forEach(function (row) {
            row.hidden = false;
        });

        var dialog = document.createElement('dialog');
        dialog.className = 'kop-gl-fb-dialog';
        dialog.setAttribute('aria-labelledby', 'kop-gl-fb-title');
        dialog.innerHTML =
            '<form class="kop-gl-fb-form" novalidate>' +
            '<h2 id="kop-gl-fb-title"></h2>' +
            '<p class="kop-gl-fb-term"></p>' +
            '<div class="kop-gl-fb-field" data-for="used_at">' +
            '<label for="kop-gl-fb-program">Facility or program name <span>required</span></label>' +
            '<input id="kop-gl-fb-program" name="program" maxlength="200" autocomplete="off">' +
            '</div>' +
            '<div class="kop-gl-fb-field">' +
            '<label for="kop-gl-fb-details"></label>' +
            '<textarea id="kop-gl-fb-details" name="details" rows="4" maxlength="4000"></textarea>' +
            '</div>' +
            '<div class="kop-gl-fb-field">' +
            '<label for="kop-gl-fb-source">How do you know? <span>optional</span></label>' +
            '<input id="kop-gl-fb-source" name="source" maxlength="500" placeholder="I was there; a handbook; a link">' +
            '</div>' +
            '<div class="kop-gl-fb-field">' +
            '<label for="kop-gl-fb-contact">Email, if we may ask you about it <span>optional</span></label>' +
            '<input id="kop-gl-fb-contact" name="contact" type="email" maxlength="200" autocomplete="email">' +
            '</div>' +
            '<div class="kop-gl-fb-hp" aria-hidden="true"><label>Website <input name="website" tabindex="-1" autocomplete="off"></label></div>' +
            '<p class="kop-gl-fb-note">Everything is read by a person before anything changes on the page. Your email is never published.</p>' +
            '<p class="kop-gl-fb-status" role="status" aria-live="polite"></p>' +
            '<div class="kop-gl-fb-buttons">' +
            '<button type="button" class="kop-gl-fb-cancel">Cancel</button>' +
            '<button type="submit" class="kop-gl-fb-send">Send</button>' +
            '</div>' +
            '</form>';
        document.body.appendChild(dialog);

        var fbForm = dialog.querySelector('form');
        var fbTitle = dialog.querySelector('#kop-gl-fb-title');
        var fbTerm = dialog.querySelector('.kop-gl-fb-term');
        var fbProgramField = dialog.querySelector('[data-for="used_at"]');
        var fbProgram = dialog.querySelector('#kop-gl-fb-program');
        var fbDetails = dialog.querySelector('#kop-gl-fb-details');
        var fbDetailsLabel = dialog.querySelector('label[for="kop-gl-fb-details"]');
        var fbStatus = dialog.querySelector('.kop-gl-fb-status');
        var fbSend = dialog.querySelector('.kop-gl-fb-send');
        var fbCancel = dialog.querySelector('.kop-gl-fb-cancel');
        var fbKind = '';
        var fbEntry = null;
        var fbOpener = null;

        var fbCopy = {
            used_at: {
                title: 'My facility used this too',
                details: 'What was it called there, or how was it used? <span>optional</span>'
            },
            correction: {
                title: 'Suggest a correction',
                details: 'What is wrong, and what should it say? <span>required</span>'
            }
        };

        function fbSay(text, state) {
            fbStatus.textContent = text;
            fbStatus.className = 'kop-gl-fb-status' + (state ? ' is-' + state : '');
        }

        function openFeedback(kind, entry, opener) {
            fbKind = kind;
            fbEntry = entry;
            fbOpener = opener;
            fbForm.reset();
            fbSay('', '');
            fbSend.disabled = false;
            fbSend.hidden = false;
            fbCancel.textContent = 'Cancel';
            fbForm.querySelectorAll('.kop-gl-fb-field').forEach(function (f) {
                f.hidden = false;
            });
            fbTitle.textContent = fbCopy[kind].title;
            fbDetailsLabel.innerHTML = fbCopy[kind].details;
            fbProgramField.hidden = kind !== 'used_at';
            var term = entry.querySelector('.kop-gl-term dfn');
            var qualifier = entry.querySelector('.kop-gl-term .kop-gl-qualifier');
            fbTerm.textContent = (term ? term.textContent : '') + (qualifier ? ' ' + qualifier.textContent : '');
            hidePop();
            dialog.showModal();
            (kind === 'used_at' ? fbProgram : fbDetails).focus();
        }

        dialog.addEventListener('close', function () {
            if (fbOpener) {
                fbOpener.focus();
            }
        });
        fbCancel.addEventListener('click', function () {
            dialog.close();
        });
        /* A click on the backdrop lands on the dialog itself, outside the form. */
        dialog.addEventListener('click', function (event) {
            if (event.target === dialog) {
                dialog.close();
            }
        });

        root.addEventListener('click', function (event) {
            var button = event.target.closest('.kop-gl-fb');
            var entry = button && button.closest('.kop-gl-entry');
            if (entry) {
                openFeedback(button.getAttribute('data-kind'), entry, button);
            }
        });

        fbForm.addEventListener('submit', function (event) {
            event.preventDefault();
            var fields = {
                kind: fbKind,
                term_id: fbEntry ? fbEntry.id : '',
                program: fbProgram.value.trim(),
                details: fbDetails.value.trim(),
                source: fbForm.elements.source.value.trim(),
                contact: fbForm.elements.contact.value.trim(),
                website: fbForm.elements.website.value
            };
            if (fbKind === 'used_at' && !fields.program) {
                fbSay('Please name the facility or program.', 'error');
                fbProgram.focus();
                return;
            }
            if (fbKind === 'correction' && !fields.details) {
                fbSay('Please say what should change.', 'error');
                fbDetails.focus();
                return;
            }
            fbSend.disabled = true;
            fbSay('Sending...', '');
            fetch(feedbackUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(fields)
            }).then(function (response) {
                return response.json().catch(function () {
                    return { success: false };
                });
            }).then(function (result) {
                if (result && result.success) {
                    fbForm.querySelectorAll('.kop-gl-fb-field').forEach(function (f) {
                        f.hidden = true;
                    });
                    fbSay('Thank you. We will read it and update the glossary if it checks out.', 'done');
                    fbSend.hidden = true;
                    fbCancel.textContent = 'Close';
                    fbCancel.focus();
                } else {
                    fbSend.disabled = false;
                    fbSay((result && result.error) || 'That did not go through. Please try again.', 'error');
                }
            }).catch(function () {
                fbSend.disabled = false;
                fbSay('That did not go through. Check your connection and try again.', 'error');
            });
        });
    }

    /* ---- Contents rail: mark the section being read -------------------- */

    var tocLinks = root.querySelectorAll('.kop-gl-toc a');
    if ('IntersectionObserver' in window && tocLinks.length) {
        var byId = {};
        tocLinks.forEach(function (a) {
            byId[a.getAttribute('href').slice(1)] = a;
        });
        var observer = new IntersectionObserver(function (items) {
            items.forEach(function (item) {
                if (!item.isIntersecting) {
                    return;
                }
                var link = byId[item.target.id];
                if (!link) {
                    return;
                }
                tocLinks.forEach(function (a) {
                    a.removeAttribute('aria-current');
                });
                link.setAttribute('aria-current', 'true');
            });
        }, { rootMargin: '-20% 0px -70% 0px' });
        sections.concat(groups).forEach(function (block) {
            if (byId[block.id]) {
                observer.observe(block);
            }
        });
    }

    /* Arriving on /glossary/#term with a filter that hides it. */
    if (window.location.hash) {
        var arrived = document.getElementById(decodeURIComponent(window.location.hash.slice(1)));
        if (arrived && root.contains(arrived) && (arrived.hidden || arrived.closest('[hidden]'))) {
            reset();
            arrived.scrollIntoView({ block: 'start' });
        }
    }

    apply(false);
})();
