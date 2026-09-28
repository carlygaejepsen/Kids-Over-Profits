/**
 * Submission editor for the admin data form.
 *
 * Opening the admin data form with ?submission=<id> loads that pending data
 * submission (suggested_edits row) into the full form, on the tab it belongs
 * to. While it is open, saving writes back to the submission (edited_json_data
 * and the project name it will be filed under), never to the master tables.
 * "Save and approve" then runs the normal approval, which merges it into the
 * master data exactly as approving from the review queue does.
 *
 * Links come from api/approve-edits.php and the Submissions Review page.
 * Config: window.KOP_SUBMISSION_EDITOR = { manageApi, processEditApi, reviewUrl }
 * (localized in inc/enqueue.php).
 */
(function() {
    if (window.KOP_SubmissionEditor) return;

    const params = new URLSearchParams(window.location.search);
    const submissionId = parseInt(params.get('submission') || '', 10);
    const config = window.KOP_SUBMISSION_EDITOR || {};

    const session = {
        active: false,
        id: submissionId > 0 ? submissionId : 0,
        submission: null,
        category: 'companies',
        formData: null,
        originals: {},
        labels: []
    };

    function notify(message, type) {
        if (typeof window.showUploadStatus === 'function') window.showUploadStatus(message, type || 'info');
        setBannerStatus(message, type);
    }

    function unwrapProjectData(payload) {
        let data = payload && typeof payload === 'object' ? payload : {};
        let guard = 0;
        while (guard++ < 5 && data.data && typeof data.data === 'object' && !Array.isArray(data.data)
            && !Array.isArray(data.facilities) && !data.operator) {
            data = data.data;
        }
        return data;
    }

    function hasName(person) {
        return !!(person && (String(person.firstName || '').trim() || String(person.lastName || '').trim()));
    }

    /** The data-form tab a submission belongs on, matching approval's routing. */
    function submissionCategory(name, raw, data) {
        if (String(data.category || raw.category || '').toLowerCase() === 'providers') return 'providers';
        if (String(data.referrerAgency?.name || '').trim() || hasName(data.referrerConsultants?.[0])) return 'referrers';
        if (String(data.transporterCompany?.name || data.transporterAgency?.name || '').trim() || hasName(data.transporters?.[0])) return 'transporters';
        const lower = String(name || '').toLowerCase().trim();
        if (window.US_STATE_SET?.has(lower) || window.COUNTRY_SET?.has(lower)) return 'locations';
        return 'companies';
    }

    function projectNameInput() {
        if (session.category === 'referrers') return document.getElementById('referrer-project-name');
        if (session.category === 'transporters') return document.getElementById('transporter-project-name');
        return document.getElementById('project-name');
    }

    // ------------------------------------------------------------------
    // Banner
    // ------------------------------------------------------------------

    function buildBanner(submission) {
        const banner = document.createElement('div');
        banner.id = 'kop-submission-editor-banner';
        banner.setAttribute('role', 'region');
        banner.setAttribute('aria-label', 'Editing a submission');
        banner.style.cssText = [
            'background: var(--kop-soft-pastel-yellow, #FFF5CB)',
            'color: var(--kop-midnight-blue, #000435)',
            'border: 2px solid var(--kop-orange, #EF9034)',
            'border-radius: 8px',
            'padding: 14px 16px',
            'margin: 12px 0 16px',
            'position: sticky',
            'top: 0',
            'z-index: 50'
        ].join(';');

        const title = document.createElement('div');
        title.style.cssText = 'font-weight: 700; font-size: 16px; margin-bottom: 4px;';
        title.textContent = `Editing submission #${submission.id} (${submission.status || 'pending'})`;

        const meta = document.createElement('div');
        meta.style.cssText = 'font-size: 14px; margin-bottom: 4px;';
        const submitted = submission.created_at ? ` Submitted ${submission.created_at}.` : '';
        const unnamed = !submission.master_id || /^(unknown|unnamed) project$/i.test(String(submission.master_id).trim());
        meta.textContent = unnamed
            ? `No project name yet; on approval a provider is filed under its state, anything else under its operator or facility name.${submitted}`
            : `Filed under "${submission.master_id}".${submitted}`;

        const reason = document.createElement('div');
        reason.style.cssText = 'font-size: 14px; margin-bottom: 8px;';
        reason.textContent = `Submitter's reason: ${submission.reason || '(none given)'}`;

        const hint = document.createElement('div');
        hint.style.cssText = 'font-size: 13px; margin-bottom: 10px;';
        hint.textContent = 'Saving here changes the submission only. The live data changes when you approve. '
            + 'To file it under a different project, change the Project Name box before saving.';

        const actions = document.createElement('div');
        actions.style.cssText = 'display: flex; gap: 8px; flex-wrap: wrap; align-items: center;';

        const makeButton = (label, handler, background) => {
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'btn';
            btn.textContent = label;
            btn.style.cssText = `background: ${background}; color: var(--kop-white, #fff); border: none; border-radius: 6px; padding: 8px 14px; cursor: pointer; font-weight: 600;`;
            btn.addEventListener('click', handler);
            return btn;
        };

        const pending = String(submission.status || 'pending') === 'pending';
        actions.appendChild(makeButton('Save changes to submission', () => saveToSubmission(), 'var(--kop-teal-ink, #24757F)'));
        if (pending) {
            actions.appendChild(makeButton('Save and approve', () => saveAndApprove(), 'var(--kop-navy-blue, #000080)'));
            actions.appendChild(makeButton('Reject', () => rejectSubmission(), 'var(--kop-coral-pink-ink, #D9020F)'));
        }
        if (config.reviewUrl) {
            const back = document.createElement('a');
            back.href = config.reviewUrl;
            back.textContent = 'Back to review queue';
            back.style.cssText = 'margin-left: 4px; color: var(--kop-navy-blue, #000080); font-weight: 600;';
            actions.appendChild(back);
        }

        const status = document.createElement('div');
        status.id = 'kop-submission-editor-status';
        status.setAttribute('aria-live', 'polite');
        status.style.cssText = 'font-size: 14px; margin-top: 8px; font-weight: 600;';

        banner.append(title, meta, reason, hint, actions, status);
        return banner;
    }

    function setBannerStatus(message, type) {
        const el = document.getElementById('kop-submission-editor-status');
        if (!el) return;
        el.textContent = message || '';
        el.style.color = type === 'error' ? 'var(--kop-coral-pink-ink, #D9020F)'
            : type === 'success' ? 'var(--kop-chartreuse-ink, #5C7401)'
            : 'var(--kop-midnight-blue, #000435)';
    }

    function relabel(selector, text) {
        document.querySelectorAll(selector).forEach(el => {
            session.labels.push({ el, text: el.textContent, display: el.style.display });
            if (text === null) el.style.display = 'none';
            else el.textContent = text;
        });
    }

    // ------------------------------------------------------------------
    // Enter / leave submission mode
    // ------------------------------------------------------------------

    function enterSubmissionMode() {
        const api = window.KOP_API;
        if (!api) return false;
        session.originals.saveProjectToCloud = api.saveProjectToCloud;
        session.originals.persistProjectLocally = api.persistProjectLocally;

        // Every save button and the autosave go through window.KOP_API at call time.
        api.saveProjectToCloud = function(projectName, action = 'save') {
            if (action === 'delete') {
                notify('Deleting is off while a submission is open. Use Reject instead.', 'error');
                return Promise.resolve(false);
            }
            return saveToSubmission(projectName);
        };
        // No local drafts or autosave copies: they would land in window.projects
        // under the project's name and mix the submission into the saved project.
        api.persistProjectLocally = function() { return true; };

        relabel('#submission-section .section-title', 'Save to Submission');
        relabel('#save-project-btn, #save-referrer-project-btn, #save-transporter-project-btn', 'Save changes to submission');
        relabel('#save-draft-locally-btn, #delete-project-btn', null);
        relabel('.admin-warning', null);

        session.active = true;
        return true;
    }

    function leaveSubmissionMode(message) {
        if (!session.active) return;
        const api = window.KOP_API;
        if (api && session.originals.saveProjectToCloud) api.saveProjectToCloud = session.originals.saveProjectToCloud;
        if (api && session.originals.persistProjectLocally) api.persistProjectLocally = session.originals.persistProjectLocally;
        session.labels.forEach(({ el, text, display }) => {
            el.textContent = text;
            el.style.display = display;
        });
        session.labels = [];
        session.active = false;
        const banner = document.getElementById('kop-submission-editor-banner');
        if (banner) banner.remove();
        if (message && typeof window.showUploadStatus === 'function') window.showUploadStatus(message, 'info');
    }

    /** True while the form still holds the submission this session loaded. */
    function formStillHoldsSubmission() {
        return session.active && window.formData && window.formData === session.formData;
    }

    // ------------------------------------------------------------------
    // Server calls
    // ------------------------------------------------------------------

    async function postJson(url, body) {
        const response = await fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(body)
        });
        let result = null;
        try {
            result = await response.json();
        } catch (e) {
            throw new Error(`HTTP ${response.status}: the server did not return JSON`);
        }
        if (!response.ok || !result || result.success === false) {
            throw new Error((result && (result.error || result.message)) || `HTTP ${response.status}`);
        }
        return result;
    }

    async function loadSubmission() {
        const url = `${config.manageApi}?type=data&action=get&id=${encodeURIComponent(session.id)}`;
        const response = await fetch(url, { credentials: 'same-origin' });
        const result = await response.json().catch(() => null);
        if (!response.ok || !result || !result.success || !result.data) {
            throw new Error((result && result.error) || `HTTP ${response.status}`);
        }
        return result.data;
    }

    function buildPayload(projectName) {
        if (typeof window.flushPendingCustomListValues === 'function') window.flushPendingCustomListValues();
        const clone = typeof window.deepClone === 'function' ? window.deepClone : (v) => JSON.parse(JSON.stringify(v));
        const payload = clone(window.formData);
        if (session.category === 'providers') {
            payload.category = 'providers';
        } else {
            if (typeof window.stripProviderData === 'function') window.stripProviderData(payload);
            if (payload.category === 'providers') delete payload.category;
        }
        payload.projectName = projectName;
        payload.name = projectName;
        return payload;
    }

    let saving = false;

    async function saveToSubmission(nameFromCaller) {
        if (!formStillHoldsSubmission()) {
            leaveSubmissionMode('The form no longer holds the submission, so nothing was saved to it. Reopen it from the review queue to keep editing.');
            return false;
        }
        if (saving) return false;

        const input = projectNameInput();
        const projectName = String(nameFromCaller || (input ? input.value : '') || session.submission.master_id || '').trim();
        if (!projectName) {
            notify('Enter a project name before saving.', 'error');
            if (input) input.focus();
            return false;
        }

        saving = true;
        notify(`Saving submission #${session.id}...`, 'info');
        try {
            await postJson(config.manageApi, {
                type: 'data',
                action: 'update_fields',
                id: session.id,
                fields: {
                    edited_json_data: buildPayload(projectName),
                    master_id: projectName
                }
            });
            session.submission.master_id = projectName;
            notify(`Saved changes to submission #${session.id}, filed under "${projectName}".`, 'success');
            return true;
        } catch (error) {
            notify(`Could not save the submission: ${error.message}`, 'error');
            return false;
        } finally {
            saving = false;
        }
    }

    async function saveAndApprove() {
        if (!confirm(`Save your changes and approve submission #${session.id}? This updates the live data.`)) return;
        const saved = await saveToSubmission();
        if (!saved) return;
        notify(`Approving submission #${session.id}...`, 'info');
        try {
            const result = await postJson(config.processEditApi, { id: session.id, action: 'approve' });
            finish(`Approved submission #${session.id}. It is now in "${result.projectName || session.submission.master_id}".`);
        } catch (error) {
            notify(`Saved, but approval failed: ${error.message}`, 'error');
        }
    }

    async function rejectSubmission() {
        if (!confirm(`Reject submission #${session.id}? Nothing from it will be added.`)) return;
        try {
            await postJson(config.processEditApi, { id: session.id, action: 'reject' });
            finish(`Rejected submission #${session.id}.`);
        } catch (error) {
            notify(`Could not reject the submission: ${error.message}`, 'error');
        }
    }

    function finish(message) {
        leaveSubmissionMode();
        if (typeof window.clearForm === 'function') window.clearForm();
        const done = document.createElement('div');
        done.id = 'kop-submission-editor-banner';
        done.setAttribute('role', 'status');
        done.style.cssText = 'background: var(--kop-mint-green, #B6E3D4); color: var(--kop-midnight-blue, #000435); border-radius: 8px; padding: 14px 16px; margin: 12px 0 16px; font-weight: 600;';
        done.textContent = message + ' ';
        if (config.reviewUrl) {
            const back = document.createElement('a');
            back.href = config.reviewUrl;
            back.textContent = 'Back to review queue';
            back.style.color = 'var(--kop-navy-blue, #000080)';
            done.appendChild(back);
        }
        insertBanner(done);
        if (typeof window.showUploadStatus === 'function') window.showUploadStatus(message, 'success');
    }

    function insertBanner(banner) {
        const anchor = document.querySelector('.admin-warning') || document.querySelector('.admin-header');
        if (anchor && anchor.parentNode) anchor.parentNode.insertBefore(banner, anchor.nextSibling);
        else document.body.prepend(banner);
    }

    // ------------------------------------------------------------------
    // Start
    // ------------------------------------------------------------------

    async function start() {
        if (!session.id || !config.manageApi) return;
        if (typeof window.loadProject !== 'function') {
            console.error('Submission editor: loadProject is not available');
            return;
        }

        let submission;
        try {
            submission = await loadSubmission();
        } catch (error) {
            const failed = document.createElement('div');
            failed.id = 'kop-submission-editor-banner';
            failed.setAttribute('role', 'alert');
            failed.style.cssText = 'background: var(--kop-white, #fff); color: var(--kop-coral-pink-ink, #D9020F); border: 2px solid var(--kop-coral-pink, #FE8088); border-radius: 8px; padding: 14px 16px; margin: 12px 0 16px; font-weight: 600;';
            failed.textContent = `Could not open submission #${session.id}: ${error.message}`;
            insertBanner(failed);
            return;
        }

        session.submission = submission;
        const raw = submission.json_data && typeof submission.json_data === 'object' ? submission.json_data : {};
        const data = unwrapProjectData(raw);
        const name = String(submission.master_id || '').trim() || 'Unknown Project';
        session.category = submissionCategory(name, raw, data);

        if (!enterSubmissionMode()) return;
        insertBanner(buildBanner(submission));

        await window.loadProject(name, { data, category: session.category });
        session.formData = window.formData;

        const input = projectNameInput();
        if (input) input.value = name;
        notify(`Opened submission #${session.id} on the ${session.category} tab.`, 'success');
    }

    window.KOP_SubmissionEditor = { start, leave: leaveSubmissionMode, session };

    if (!session.id) return;
    if (window.formReady) {
        start();
    } else {
        document.addEventListener('formReady', start, { once: true });
    }
})();
