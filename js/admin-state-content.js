(function() {
    'use strict';

    const isLawsuitsPage = !!document.getElementById('lawsuitForm');
    const isLegislationPage = !!document.getElementById('legislationForm');

    if (!isLawsuitsPage && !isLegislationPage) return;

    const config = isLawsuitsPage
        ? { ...window.adminLawsuitsConfig, kind: 'lawsuit' }
        : { ...window.adminLegislationConfig, kind: 'legislation' };

    let folderCache = null;
    const loadFolders = async () => {
        if (folderCache) return folderCache;
        if (!config.foldersUrl) return [];
        try {
            const res = await fetch(config.foldersUrl, { credentials: 'same-origin' });
            const data = await res.json();
            folderCache = Array.isArray(data) ? data : [];
        } catch (err) {
            console.warn('Failed to load FileBird folders', err);
            folderCache = [];
        }
        return folderCache;
    };

    const buildFolderHierarchy = folders => {
        const byId = new Map(folders.map(f => [String(f.id), { ...f, children: [] }]));
        const roots = [];
        byId.forEach(f => {
            const parentId = String(f.parent || 0);
            if (parentId === '0' || !byId.has(parentId)) {
                roots.push(f);
            } else {
                byId.get(parentId).children.push(f);
            }
        });
        const flat = [];
        const walk = (nodes, depth) => {
            nodes
                .slice()
                .sort((a, b) => String(a.name).localeCompare(String(b.name)))
                .forEach(node => {
                    flat.push({ id: node.id, name: node.name, depth });
                    if (node.children.length) walk(node.children, depth + 1);
                });
        };
        walk(roots, 0);
        return flat;
    };

    const populateFolderSelect = async selectId => {
        const select = document.getElementById(selectId);
        if (!select) return;
        const folders = await loadFolders();
        const hierarchy = buildFolderHierarchy(folders);
        const currentValue = select.value;
        select.innerHTML = '<option value="">— No folder —</option>' +
            hierarchy.map(f => {
                const indent = '— '.repeat(f.depth);
                return `<option value="${f.id}">${indent}${escapeHtml(f.name)}</option>`;
            }).join('');
        if (currentValue) select.value = currentValue;
    };

    const linesToArray = value => {
        if (!value) return [];
        return String(value)
            .split(/\r?\n/)
            .map(s => s.trim())
            .filter(Boolean);
    };

    const arrayToLines = value => {
        if (!Array.isArray(value)) return '';
        return value.filter(Boolean).join('\n');
    };

    const setFieldValue = (id, value) => {
        const el = document.getElementById(id);
        if (!el) return;
        if (Array.isArray(value)) {
            el.value = arrayToLines(value);
        } else {
            el.value = value == null ? '' : value;
        }
    };

    const getFieldValue = id => {
        const el = document.getElementById(id);
        return el ? el.value : '';
    };

    // ---- Lawsuits ----
    if (isLawsuitsPage) {
        const form = document.getElementById('lawsuitForm');
        const list = document.getElementById('lawsuitList');
        const status = document.getElementById('lawsuitFormStatus');
        const resetBtn = document.getElementById('lawsuitResetBtn');
        const newBtn = document.getElementById('newLawsuitBtn');
        const reloadBtn = document.getElementById('reloadList');
        const filterJurisdiction = document.getElementById('filterJurisdiction');
        const filterPubStatus = document.getElementById('filterPublicationStatus');
        const fetchLawsuitBtn = document.getElementById('fetchLawsuitBtn');
        const extractLawsuitBtn = document.getElementById('extractLawsuitBtn');
        const complaintFileInput = document.getElementById('lawsuit-complaint-file');

        const fieldMap = {
            id: 'lawsuit-id',
            case_name: 'lawsuit-case-name',
            case_number: 'lawsuit-case-number',
            court: 'lawsuit-court',
            jurisdiction: 'lawsuit-jurisdiction',
            filing_date: 'lawsuit-filing-date',
            status: 'lawsuit-status',
            publication_status: 'lawsuit-pub-status',
            outcome: 'lawsuit-outcome',
            settlement_amount: 'lawsuit-settlement',
            summary: 'lawsuit-summary',
            filebird_folder_id: 'lawsuit-filebird-folder',
            reviewer_notes: 'lawsuit-reviewer-notes'
        };
        const arrayFieldMap = {
            plaintiffs: 'lawsuit-plaintiffs',
            defendants: 'lawsuit-defendants',
            facilities_mentioned: 'lawsuit-facilities',
            staff_mentioned: 'lawsuit-staff',
            organizations_mentioned: 'lawsuit-orgs',
            claims: 'lawsuit-claims',
            source_urls: 'lawsuit-sources',
            document_urls: 'lawsuit-docs',
            tags: 'lawsuit-tags'
        };

        const resetForm = () => {
            form.reset();
            document.getElementById('lawsuit-id').value = '';
            status.textContent = '';
        };

        const populateForm = record => {
            Object.entries(fieldMap).forEach(([key, id]) => setFieldValue(id, record[key]));
            Object.entries(arrayFieldMap).forEach(([key, id]) => setFieldValue(id, record[key]));
        };

        const collectPayload = () => {
            const payload = {};
            Object.entries(fieldMap).forEach(([key, id]) => {
                payload[key] = getFieldValue(id);
            });
            if (payload.id === '') delete payload.id;
            else payload.id = parseInt(payload.id, 10);
            Object.entries(arrayFieldMap).forEach(([key, id]) => {
                payload[key] = linesToArray(getFieldValue(id));
            });
            return payload;
        };

        const renderList = records => {
            if (!records || records.length === 0) {
                list.innerHTML = '<li class="empty">No records found.</li>';
                return;
            }
            list.innerHTML = records.map(rec => `
                <li class="record-row" data-id="${rec.id}">
                    <div class="record-row-main">
                        <strong>${escapeHtml(rec.case_name || '(no name)')}</strong>
                        <span class="record-meta">${escapeHtml(rec.jurisdiction || '—')} · ${escapeHtml(rec.publication_status)} · ${escapeHtml(rec.filing_date || '')}</span>
                    </div>
                    <button type="button" class="edit-btn" data-id="${rec.id}">Edit</button>
                </li>
            `).join('');
            list.querySelectorAll('.edit-btn').forEach(btn => {
                btn.addEventListener('click', () => loadRecord(parseInt(btn.dataset.id, 10)));
            });
        };

        const loadList = async () => {
            const params = new URLSearchParams();
            if (filterJurisdiction.value) params.set('jurisdiction', filterJurisdiction.value);
            if (filterPubStatus.value) params.set('publication_status', filterPubStatus.value);
            params.set('limit', '100');
            const res = await fetch(`${config.apiUrl}?${params.toString()}`, { credentials: 'same-origin' });
            const data = await res.json();
            renderList(data.success ? data.data : []);
        };

        const loadRecord = async id => {
            const res = await fetch(`${config.apiUrl}?id=${id}`, { credentials: 'same-origin' });
            const data = await res.json();
            if (!data.success) {
                status.textContent = 'Failed to load record.';
                return;
            }
            populateForm(data.data);
            window.scrollTo({ top: form.offsetTop - 20, behavior: 'smooth' });
        };

        // ----- Paste raw JSON from external AI --------------------------------
        const pasteJsonBtn    = document.getElementById('pasteJsonBtn');
        const pasteJsonPanel  = document.getElementById('pasteJsonPanel');
        const pasteJsonInput  = document.getElementById('pasteJsonInput');
        const pasteJsonApply  = document.getElementById('pasteJsonApplyBtn');
        const pasteJsonCancel = document.getElementById('pasteJsonCancelBtn');
        const pasteJsonStatus = document.getElementById('pasteJsonStatus');

        const EMPTY_LAWSUIT_JSON = JSON.stringify({
            case_name: "",
            case_number: "",
            court: "",
            jurisdiction: "",
            filing_date: "",
            status: "",
            outcome: "",
            settlement_amount: "",
            summary: "",
            plaintiffs: [],
            defendants: [],
            facilities_mentioned: [],
            staff_mentioned: [],
            organizations_mentioned: [],
            claims: [],
            source_urls: [],
            document_urls: [],
            tags: []
        }, null, 2);

        if (pasteJsonBtn && pasteJsonPanel) {
            pasteJsonBtn.addEventListener('click', () => {
                pasteJsonPanel.hidden = !pasteJsonPanel.hidden;
                if (!pasteJsonPanel.hidden) {
                    if (!pasteJsonInput.value.trim()) pasteJsonInput.value = EMPTY_LAWSUIT_JSON;
                    pasteJsonInput.focus();
                    pasteJsonInput.select();
                }
            });

            pasteJsonCancel.addEventListener('click', () => {
                pasteJsonPanel.hidden = true;
                pasteJsonInput.value = '';
                pasteJsonStatus.textContent = '';
            });

            pasteJsonApply.addEventListener('click', () => {
                pasteJsonStatus.textContent = '';
                pasteJsonStatus.style.color = '';
                let raw = pasteJsonInput.value.trim();

                // Strip markdown fences in case the AI wrapped it in ```json … ```
                raw = raw.replace(/^```(?:json)?\s*/i, '').replace(/\s*```$/, '');

                let parsed;
                try {
                    parsed = JSON.parse(raw);
                } catch (e) {
                    pasteJsonStatus.textContent = 'Invalid JSON: ' + e.message;
                    pasteJsonStatus.style.color = '#dc2626';
                    return;
                }

                // Coerce array fields that the AI might have returned as strings
                const arrayFields = ['plaintiffs','defendants','facilities_mentioned',
                    'staff_mentioned','organizations_mentioned','claims',
                    'source_urls','document_urls','tags'];
                arrayFields.forEach(k => {
                    if (parsed[k] && typeof parsed[k] === 'string') {
                        parsed[k] = parsed[k].split(/\n|,\s*/).map(s => s.trim()).filter(Boolean);
                    }
                });

                // Merge document_urls additively
                const existingDocs = linesToArray(getFieldValue('lawsuit-docs'));
                const newDocs = Array.isArray(parsed.document_urls) ? parsed.document_urls : [];
                parsed.document_urls = Array.from(new Set([...existingDocs, ...newDocs]));

                populateForm(parsed);
                pasteJsonStatus.textContent = 'Applied. Review fields and save.';
                pasteJsonPanel.hidden = true;
                pasteJsonInput.value = '';
            });
        }

        if (fetchLawsuitBtn) {
            fetchLawsuitBtn.addEventListener('click', async () => {
                const caseNumber = getFieldValue('lawsuit-case-number');
                const jurisdiction = getFieldValue('lawsuit-jurisdiction');
                const court = getFieldValue('lawsuit-court');

                if (!caseNumber || !jurisdiction) {
                    status.textContent = 'Please enter a case number and jurisdiction first.';
                    status.style.color = '#dc2626';
                    return;
                }

                fetchLawsuitBtn.disabled = true;
                const originalHTML = fetchLawsuitBtn.innerHTML;
                fetchLawsuitBtn.textContent = '✨ Fetching...';
                status.textContent = 'Fetching lawsuit details...';
                status.style.color = '';

                try {
                    const params = new URLSearchParams({
                        case_number: caseNumber,
                        jurisdiction: jurisdiction,
                        court: court
                    });
                    const res = await fetch(`${config.fetchApiUrl}?${params.toString()}`, { credentials: 'same-origin' });
                    const data = await res.json();

                    if (data.success) {
                        populateForm(data.data);
                        status.textContent = 'Lawsuit details populated.';
                    } else {
                        status.textContent = 'Fetch failed: ' + (data.error || 'Check case number and jurisdiction.');
                        status.style.color = '#dc2626';
                    }
                } catch (err) {
                    console.error('Fetch error:', err);
                    status.textContent = 'Error connecting to fetch API.';
                    status.style.color = '#dc2626';
                } finally {
                    fetchLawsuitBtn.disabled = false;
                    fetchLawsuitBtn.innerHTML = originalHTML;
                }
            });
        }

        // ----- Complaint upload + Gemini extraction --------------------------
        // The button is a UI hook; the actual file picker is the hidden
        // <input type="file">. We unify the flow here so the user just clicks
        // one button and the file picker opens.
        if (extractLawsuitBtn && complaintFileInput && config.extractApiUrl) {
            extractLawsuitBtn.addEventListener('click', () => {
                if (extractLawsuitBtn.disabled) return;
                complaintFileInput.value = '';  // reset so re-uploading the same file fires `change`
                complaintFileInput.click();
            });

            complaintFileInput.addEventListener('change', async () => {
                const file = complaintFileInput.files && complaintFileInput.files[0];
                if (!file) return;

                const folderId = getFieldValue('lawsuit-filebird-folder');

                extractLawsuitBtn.disabled = true;
                const originalHTML = extractLawsuitBtn.innerHTML;
                status.style.color = '';

                const postJson = body => fetch(config.extractApiUrl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(body),
                    signal: AbortSignal.timeout(90 * 1000)
                });

                try {
                    // Step 1: upload file and get job_id + total_chunks
                    extractLawsuitBtn.textContent = '📄 Uploading…';
                    status.textContent = `Uploading "${file.name}"…`;

                    const uploadForm = new FormData();
                    uploadForm.append('action', 'upload');
                    uploadForm.append('complaint', file);
                    if (folderId) uploadForm.append('filebird_folder_id', folderId);

                    const uploadRes = await fetch(config.extractApiUrl, {
                        method: 'POST',
                        credentials: 'same-origin',
                        body: uploadForm,
                        signal: AbortSignal.timeout(90 * 1000)
                    });
                    const uploadData = await uploadRes.json();
                    if (!uploadData.success) {
                        throw new Error(uploadData.error || 'Upload failed');
                    }

                    const { job_id, total_chunks } = uploadData;

                    // Step 2: process each chunk, waiting 65s between requests to
                    // stay within Groq's 6,000 token-per-minute free-tier limit.
                    for (let i = 0; i < total_chunks; i++) {
                        if (i > 0) {
                            // Wait 65s so we don't exceed the per-minute token quota.
                            extractLawsuitBtn.textContent = `📄 Waiting… (${i}/${total_chunks})`;
                            status.textContent = `Chunk ${i} of ${total_chunks} done. Waiting 65s before next chunk…`;
                            await new Promise(resolve => setTimeout(resolve, 65000));
                        }

                        extractLawsuitBtn.textContent = `📄 Chunk ${i + 1}/${total_chunks}…`;
                        status.textContent = `Processing chunk ${i + 1} of ${total_chunks}…`;

                        const chunkData = await postJson({ action: 'chunk', job_id, chunk_index: i });
                        const chunk = await chunkData.json();
                        if (!chunk.success) {
                            throw new Error(chunk.error || `Chunk ${i + 1} failed`);
                        }
                    }

                    // Step 3: merge all chunk results and register the attachment
                    extractLawsuitBtn.textContent = '📄 Finalizing…';
                    status.textContent = 'Merging extraction results…';

                    const finalRes = await postJson({ action: 'finalize', job_id });
                    const finalData = await finalRes.json();
                    if (!finalData.success) {
                        throw new Error(finalData.error || 'Finalize failed');
                    }

                    // Merge document_urls additively — never overwrite what the admin already has.
                    const existingDocs = linesToArray(getFieldValue('lawsuit-docs'));
                    const extractedDocs = Array.isArray(finalData.data.document_urls)
                        ? finalData.data.document_urls : [];
                    finalData.data.document_urls = Array.from(new Set([...existingDocs, ...extractedDocs]));

                    populateForm(finalData.data);
                    status.textContent = 'Extracted. Review the populated fields and save.';
                } catch (err) {
                    console.error('Extract error:', err);
                    status.textContent = 'Extraction failed: ' + err.message;
                    status.style.color = '#dc2626';
                } finally {
                    extractLawsuitBtn.disabled = false;
                    extractLawsuitBtn.innerHTML = originalHTML;
                }
            });
        }

        form.addEventListener('submit', async e => {
            e.preventDefault();
            status.textContent = 'Saving...';
            const payload = collectPayload();
            const res = await fetch(config.apiUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            });
            const data = await res.json();
            if (data.success) {
                status.textContent = data.created ? `Saved (id ${data.id})` : 'Updated.';
                document.getElementById('lawsuit-id').value = data.id;
                loadList();
            } else {
                status.textContent = 'Save failed: ' + (data.error || 'unknown');
            }
        });

        resetBtn.addEventListener('click', resetForm);
        newBtn.addEventListener('click', resetForm);
        reloadBtn.addEventListener('click', loadList);
        filterJurisdiction.addEventListener('change', loadList);
        filterPubStatus.addEventListener('change', loadList);
        populateFolderSelect('lawsuit-filebird-folder');
        loadList();
    }

    // ---- Legislation ----
    if (isLegislationPage) {
        const form = document.getElementById('legislationForm');
        const list = document.getElementById('legislationList');
        const status = document.getElementById('legislationFormStatus');
        const resetBtn = document.getElementById('legislationResetBtn');
        const newBtn = document.getElementById('newLegislationBtn');
        const reloadBtn = document.getElementById('reloadList');
        const filterJurisdiction = document.getElementById('filterJurisdiction');
        const filterPubStatus = document.getElementById('filterPublicationStatus');
        const fetchBtn = document.getElementById('fetchLegislationBtn');

        const fieldMap = {
            id: 'leg-id',
            bill_number: 'leg-bill-number',
            bill_type: 'leg-bill-type',
            bill_title: 'leg-bill-title',
            jurisdiction: 'leg-jurisdiction',
            chamber: 'leg-chamber',
            session_year: 'leg-session-year',
            status: 'leg-status',
            introduced_date: 'leg-introduced-date',
            last_action_date: 'leg-last-action-date',
            last_action_text: 'leg-last-action-text',
            summary: 'leg-summary',
            full_text_url: 'leg-full-text-url',
            official_url: 'leg-official-url',
            position: 'leg-position',
            publication_status: 'leg-pub-status',
            filebird_folder_id: 'leg-filebird-folder',
            reviewer_notes: 'leg-reviewer-notes'
        };
        const arrayFieldMap = {
            sponsors: 'leg-sponsors',
            subject_tags: 'leg-subject-tags',
            facilities_affected: 'leg-facilities-affected',
            tags: 'leg-tags'
        };

        const resetForm = () => {
            form.reset();
            document.getElementById('leg-id').value = '';
            status.textContent = '';
        };

        const populateSelectOptions = (selectId, options, currentValue) => {
            const sel = document.getElementById(selectId);
            if (!sel) return;
            const desired = currentValue !== undefined && currentValue !== null && currentValue !== ''
                ? String(currentValue)
                : sel.value;
            sel.innerHTML = '';
            const placeholder = document.createElement('option');
            placeholder.value = '';
            placeholder.textContent = '—';
            sel.appendChild(placeholder);
            options.forEach(opt => {
                const o = document.createElement('option');
                o.value = opt.value;
                o.textContent = opt.label;
                sel.appendChild(o);
            });
            // If the desired value isn't in the list, append it so existing records still display.
            if (desired && !Array.from(sel.options).some(o => o.value === desired)) {
                const extra = document.createElement('option');
                extra.value = desired;
                extra.textContent = desired + ' (custom)';
                sel.appendChild(extra);
            }
            sel.value = desired || '';
        };

        const loadJurisdictionMeta = async (jurisdiction, level, preserveValues = {}) => {
            const billTypeSel = document.getElementById('leg-bill-type');
            const sessionSel  = document.getElementById('leg-session-year');
            if (!jurisdiction) {
                if (billTypeSel) billTypeSel.innerHTML = '<option value="">— pick jurisdiction first —</option>';
                if (sessionSel)  sessionSel.innerHTML  = '<option value="">— pick jurisdiction first —</option>';
                return;
            }
            try {
                const params = new URLSearchParams({ jurisdiction, level: level || 'state' });
                const res = await fetch(`${config.metaApiUrl}?${params.toString()}`, { credentials: 'same-origin' });
                const data = await res.json();
                const billTypes = (data.bill_types || []).map(bt => ({ value: bt, label: bt }));
                const sessions  = (data.sessions  || []).map(s  => ({ value: s.identifier, label: s.name || s.identifier }));
                populateSelectOptions('leg-bill-type', billTypes, preserveValues.bill_type);
                populateSelectOptions('leg-session-year', sessions, preserveValues.session_year);
            } catch (err) {
                console.error('Meta fetch failed:', err);
            }
        };

        const populateForm = record => {
            Object.entries(fieldMap).forEach(([key, id]) => setFieldValue(id, record[key]));
            Object.entries(arrayFieldMap).forEach(([key, id]) => setFieldValue(id, record[key]));
            // Refresh per-jurisdiction dropdowns so saved session/bill_type render correctly.
            if (record.jurisdiction) {
                const level = record.jurisdiction === 'Federal' ? 'federal' : 'state';
                loadJurisdictionMeta(record.jurisdiction, level, {
                    bill_type:    record.bill_type,
                    session_year: record.session_year,
                });
            }
        };

        const collectPayload = () => {
            const payload = {};
            Object.entries(fieldMap).forEach(([key, id]) => {
                payload[key] = getFieldValue(id);
            });
            if (payload.id === '') delete payload.id;
            else payload.id = parseInt(payload.id, 10);
            Object.entries(arrayFieldMap).forEach(([key, id]) => {
                payload[key] = linesToArray(getFieldValue(id));
            });
            return payload;
        };

        const renderList = records => {
            if (!records || records.length === 0) {
                list.innerHTML = '<li class="empty">No records found.</li>';
                return;
            }
            list.innerHTML = records.map(rec => `
                <li class="record-row" data-id="${rec.id}">
                    <div class="record-row-main">
                        <strong>${escapeHtml(rec.bill_number || '')} ${escapeHtml(rec.bill_title || '')}</strong>
                        <span class="record-meta">${escapeHtml(rec.jurisdiction || '—')} · ${escapeHtml(rec.publication_status)} · ${escapeHtml(rec.status)}</span>
                    </div>
                    <button type="button" class="edit-btn" data-id="${rec.id}">Edit</button>
                </li>
            `).join('');
            list.querySelectorAll('.edit-btn').forEach(btn => {
                btn.addEventListener('click', () => loadRecord(parseInt(btn.dataset.id, 10)));
            });
        };

        const loadList = async () => {
            const params = new URLSearchParams();
            if (filterJurisdiction.value) params.set('jurisdiction', filterJurisdiction.value);
            if (filterPubStatus.value) params.set('publication_status', filterPubStatus.value);
            params.set('limit', '100');
            const res = await fetch(`${config.apiUrl}?${params.toString()}`, { credentials: 'same-origin' });
            const data = await res.json();
            renderList(data.success ? data.data : []);
        };

        const loadRecord = async id => {
            const res = await fetch(`${config.apiUrl}?id=${id}`, { credentials: 'same-origin' });
            const data = await res.json();
            if (!data.success) {
                status.textContent = 'Failed to load record.';
                return;
            }
            populateForm(data.data);
            window.scrollTo({ top: form.offsetTop - 20, behavior: 'smooth' });
        };

        form.addEventListener('submit', async e => {
            e.preventDefault();
            status.textContent = 'Saving...';
            const payload = collectPayload();
            const res = await fetch(config.apiUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            });
            const data = await res.json();
            if (data.success) {
                status.textContent = data.created ? `Saved (id ${data.id})` : 'Updated.';
                document.getElementById('leg-id').value = data.id;
                loadList();
            } else {
                status.textContent = 'Save failed: ' + (data.error || 'unknown');
            }
        });

        // Refresh per-jurisdiction dropdowns whenever the user changes jurisdiction or level.
        const jurisdictionSel = document.getElementById('leg-jurisdiction');
        const levelSel = document.getElementById('leg-level');
        const refreshMeta = () => {
            const j = getFieldValue('leg-jurisdiction');
            let lv = getFieldValue('leg-level');
            if (j === 'Federal') lv = 'federal';
            loadJurisdictionMeta(j, lv);
        };
        if (jurisdictionSel) jurisdictionSel.addEventListener('change', refreshMeta);
        if (levelSel) levelSel.addEventListener('change', refreshMeta);

        if (fetchBtn) {
            fetchBtn.addEventListener('click', async () => {
                const rawNumber  = getFieldValue('leg-bill-number').trim();
                const billType   = getFieldValue('leg-bill-type').trim();
                const jurisdiction = getFieldValue('leg-jurisdiction');
                const session    = getFieldValue('leg-session-year');
                let level        = getFieldValue('leg-level');
                if (jurisdiction === 'Federal') level = 'federal';

                if (!rawNumber || !jurisdiction) {
                    status.textContent = 'Please enter a bill number and jurisdiction first.';
                    status.style.color = '#dc2626';
                    return;
                }

                // Combine bill type + number if the user gave them separately
                // (e.g. type "SB" + number "1190" -> "SB 1190"). If they already
                // typed a prefix into the number field, leave it alone.
                const hasPrefix = /^[a-zA-Z]/.test(rawNumber);
                const combinedNumber = (billType && !hasPrefix)
                    ? `${billType} ${rawNumber}`
                    : rawNumber;

                fetchBtn.disabled = true;
                const originalHTML = fetchBtn.innerHTML;
                fetchBtn.textContent = '✨ Fetching...';
                status.textContent = 'Fetching bill details...';
                status.style.color = '';

                try {
                    const params = new URLSearchParams({
                        bill_number: combinedNumber,
                        jurisdiction: jurisdiction,
                        level: level
                    });
                    if (session) params.set('session', session);
                    const res = await fetch(`${config.fetchApiUrl}?${params.toString()}`, { credentials: 'same-origin' });
                    const data = await res.json();

                    if (data.success) {
                        populateForm(data.data);
                        status.textContent = 'Bill details populated.';
                    } else {
                        status.textContent = 'Fetch failed: ' + (data.error || 'Check bill number and jurisdiction.');
                        status.style.color = '#dc2626';
                    }
                } catch (err) {
                    console.error('Fetch error:', err);
                    status.textContent = 'Error connecting to fetch API.';
                    status.style.color = '#dc2626';
                } finally {
                    fetchBtn.disabled = false;
                    fetchBtn.innerHTML = originalHTML;
                }
            });
        }

        resetBtn.addEventListener('click', resetForm);
        newBtn.addEventListener('click', resetForm);
        reloadBtn.addEventListener('click', loadList);
        filterJurisdiction.addEventListener('change', loadList);
        filterPubStatus.addEventListener('change', loadList);
        populateFolderSelect('leg-filebird-folder');
        loadList();
    }

    function escapeHtml(value) {
        if (value == null) return '';
        return String(value).replace(/[&<>"']/g, ch => ({
            '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
        })[ch]);
    }
})();
