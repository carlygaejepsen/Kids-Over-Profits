(function() {
    'use strict';

    const config = window.statePageConfig;
    if (!config || !config.apiUrl) return;

    const state = {
        data: null,
        folders: null,        // FileBird folder list (cached on first need)
        folderByName: null,   // normalized name -> folder id lookup
    };

    const $ = sel => document.querySelector(sel);
    const $$ = sel => Array.from(document.querySelectorAll(sel));

    const escapeHtml = value => {
        if (value == null) return '';
        return String(value).replace(/[&<>"']/g, ch => ({
            '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
        })[ch]);
    };

    const formatDate = value => {
        if (!value) return '';
        const ts = Date.parse(value);
        if (isNaN(ts)) return value;
        return new Date(ts).toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' });
    };

    const normalizeText = value => String(value || '').replace(/\s+/g, ' ').trim();

    const isAddressLikeText = value => {
        const text = normalizeText(value);
        if (!text) return false;

        // Full address pattern: street + city/state + zip.
        if (/^\d{1,6}\s+[^,]+,\s*[A-Za-z .'-]+,\s*[A-Z]{2}\s+\d{5}(?:-\d{4})?$/i.test(text)) {
            return true;
        }

        // Street-address-like name that starts with a house number and street suffix.
        if (/^\d{1,6}\s+[A-Za-z0-9.'-]+(?:\s+[A-Za-z0-9.'-]+){0,6}\s+(street|st|avenue|ave|boulevard|blvd|road|rd|drive|dr|lane|ln|court|ct|circle|cir|way|place|pl|highway|hwy|trail|terrace|ter)\b/i.test(text)) {
            return true;
        }

        return false;
    };

    const pickBestFacilityLabel = (...candidates) => {
        for (const candidate of candidates) {
            const text = normalizeText(candidate);
            if (!text) continue;
            if (isAddressLikeText(text)) continue;
            return text;
        }
        return '';
    };

    const getFacilityLocationSuffix = facility => {
        const city = normalizeText(facility && facility.city);
        const stateCode = normalizeText(facility && facility.state);
        if (city && stateCode) return `${city}, ${stateCode}`;
        if (city) return city;

        const address = normalizeText(facility && facility.address);
        if (address && address.includes(',')) {
            const parts = address.split(',').map(p => normalizeText(p)).filter(Boolean);
            if (parts.length >= 2) {
                // Usually: street, city, state, zip
                const maybeCity = parts[1] || '';
                const maybeState = (parts[2] || '').split(/\s+/)[0] || '';
                if (maybeCity && maybeState && /^[A-Z]{2}$/i.test(maybeState)) return `${maybeCity}, ${maybeState.toUpperCase()}`;
                if (maybeCity) return maybeCity;
            }
        }

        return '';
    };

    const normalizeAddressForMatch = value => {
        let s = normalizeText(value).toLowerCase();
        if (!s) return '';

        s = s.replace(/\b(?:apt|apartment|suite|ste|unit|#)\s*[a-z0-9-]+\b/g, ' ');
        s = s
            .replace(/\bstreet\b/g, 'st')
            .replace(/\bavenue\b/g, 'ave')
            .replace(/\bboulevard\b/g, 'blvd')
            .replace(/\broad\b/g, 'rd')
            .replace(/\bdrive\b/g, 'dr')
            .replace(/\blane\b/g, 'ln')
            .replace(/\bcourt\b/g, 'ct')
            .replace(/\bcircle\b/g, 'cir')
            .replace(/\bplace\b/g, 'pl')
            .replace(/\bhighway\b/g, 'hwy')
            .replace(/\bterrace\b/g, 'ter')
            .replace(/\btrail\b/g, 'trl');

        s = s.replace(/[^a-z0-9\s]/g, ' ').replace(/\s+/g, ' ').trim();
        return s;
    };

    const inspectionFingerprint = insp => {
        if (!insp || typeof insp !== 'object') return '';
        if (insp.report_url) return `u:${String(insp.report_url).toLowerCase()}`;
        if (insp.pdf_url) return `p:${String(insp.pdf_url).toLowerCase()}`;
        return `d:${String(insp.date || '').toLowerCase()}|${String(insp.type || '').toLowerCase()}`;
    };

    const mergeInspectionLists = (left, right) => {
        const out = [];
        const seen = new Set();
        [...(left || []), ...(right || [])].forEach(insp => {
            const fp = inspectionFingerprint(insp);
            if (!fp || seen.has(fp)) return;
            seen.add(fp);
            out.push(insp);
        });
        out.sort((a, b) => (Date.parse(b.date || '') || 0) - (Date.parse(a.date || '') || 0));
        return out;
    };

    const mergeFacilityRecords = (target, source) => {
        target.inspection_count = (target.inspection_count || 0) + (source.inspection_count || 0);
        target.violation_count = (target.violation_count || 0) + (source.violation_count || 0);

        const tTs = Date.parse(target.latest_inspection_date || '') || 0;
        const sTs = Date.parse(source.latest_inspection_date || '') || 0;
        if (sTs > tTs) target.latest_inspection_date = source.latest_inspection_date;

        target.inspections = mergeInspectionLists(target.inspections, source.inspections);

        if (!normalizeText(target.address) && normalizeText(source.address)) {
            target.address = source.address;
        }
        if (!normalizeText(target.operator_name) && normalizeText(source.operator_name)) {
            target.operator_name = source.operator_name;
        }
        if (!normalizeText(target.project_name) && normalizeText(source.project_name)) {
            target.project_name = source.project_name;
        }
        if (!normalizeText(target.type) && normalizeText(source.type)) {
            target.type = source.type;
        }
        if (!normalizeText(target.operating_period) && normalizeText(source.operating_period)) {
            target.operating_period = source.operating_period;
        }
    };

    const consolidateFacilitiesForDisplay = facilities => {
        const list = Array.isArray(facilities) ? facilities.map(f => ({ ...f, inspections: [...(f.inspections || [])] })) : [];
        if (!list.length) return [];

        const addrToNamed = new Map();
        const named = [];
        const loose = [];

        list.forEach(facility => {
            const candidate = pickBestFacilityLabel(facility.name, facility.project_name, facility.operator_name);
            const isLoose = !candidate || isAddressLikeText(facility.name);
            if (isLoose) {
                loose.push(facility);
                return;
            }

            named.push(facility);
            const addrKey = normalizeAddressForMatch(facility.address);
            if (addrKey && !addrToNamed.has(addrKey)) {
                addrToNamed.set(addrKey, facility);
            }
        });

        loose.forEach(facility => {
            const addrKey = normalizeAddressForMatch(facility.address || facility.name);
            let target = null;

            if (addrKey) {
                if (addrToNamed.has(addrKey)) {
                    target = addrToNamed.get(addrKey);
                } else {
                    // Relaxed containment check for minor address-format differences.
                    for (const [known, namedFacility] of addrToNamed.entries()) {
                        if (known.length < 16 || addrKey.length < 16) continue;
                        if (known.includes(addrKey) || addrKey.includes(known)) {
                            target = namedFacility;
                            break;
                        }
                    }
                }
            }

            if (target) {
                mergeFacilityRecords(target, facility);
            } else {
                named.push(facility);
                if (addrKey && !addrToNamed.has(addrKey)) {
                    addrToNamed.set(addrKey, facility);
                }
            }
        });

        return named;
    };

    const getFacilityDisplayName = facility => {
        const explicitName = normalizeText(facility && facility.name);
        const projectName = normalizeText(facility && facility.project_name);
        const operatorName = normalizeText(facility && facility.operator_name);

        let inspectionLicensee = '';
        if (Array.isArray(facility?.inspections)) {
            for (const insp of facility.inspections) {
                const candidate = normalizeText(insp && insp.categories && insp.categories.licensee);
                if (candidate && !isAddressLikeText(candidate)) {
                    inspectionLicensee = candidate;
                    break;
                }
            }
        }

        // Primary choice: non-address explicit/program names.
        const baseName = pickBestFacilityLabel(explicitName, projectName, operatorName, inspectionLicensee);
        if (baseName) {
            // If explicit source was address-like but we have an operator/program name,
            // append location so multiple sites remain distinguishable.
            if (isAddressLikeText(explicitName)) {
                const suffix = getFacilityLocationSuffix(facility);
                return suffix ? `${baseName} (${suffix})` : baseName;
            }
            return baseName;
        }

        // Last resort: no usable name fields; use location-based placeholder.
        const suffix = getFacilityLocationSuffix(facility);
        return suffix ? `Facility (${suffix})` : 'Unknown Facility';
    };

    const folderContentCache = new Map();
    const fetchFolderContent = async folderId => {
        if (!folderId || !config.folderContentUrl) return [];
        const cacheKey = String(folderId);
        if (folderContentCache.has(cacheKey)) return folderContentCache.get(cacheKey);
        try {
            const res = await fetch(`${config.folderContentUrl}?id=${encodeURIComponent(folderId)}`, { credentials: 'same-origin' });
            const data = await res.json();
            const files = Array.isArray(data) ? data : [];
            folderContentCache.set(cacheKey, files);
            return files;
        } catch (err) {
            console.warn('Folder content load failed', err);
            return [];
        }
    };

    const renderFileGrid = files => {
        if (!Array.isArray(files) || files.length === 0) {
            return '<p class="docs-empty">No documents in this folder.</p>';
        }
        return `<ul class="state-doc-grid">${files.map(file => {
            const title = escapeHtml(file.title || 'Document');
            const url = escapeHtml(file.url || '#');
            const mime = String(file.mime_type || '');
            const isImage = mime.startsWith('image/');
            const ext = (file.url || '').split('.').pop().split('?')[0].split('#')[0].toUpperCase().slice(0, 4) || 'FILE';
            const thumb = file.thumb_url || (isImage ? file.url : '');
            return `
                <li class="state-doc-item">
                    <a href="${url}" target="_blank" rel="noopener">
                        ${thumb
                            ? `<img class="state-doc-thumb" src="${escapeHtml(thumb)}" alt="${title}">`
                            : `<span class="state-doc-icon">${escapeHtml(ext)}</span>`}
                        <span class="state-doc-title">${title}</span>
                    </a>
                </li>`;
        }).join('')}</ul>`;
    };

    const wireDocToggles = container => {
        container.querySelectorAll('.docs-toggle').forEach(btn => {
            btn.addEventListener('click', async () => {
                const folderId = btn.dataset.folderId;
                const target = btn.nextElementSibling;
                if (!target) return;
                if (target.dataset.loaded === '1') {
                    target.hidden = !target.hidden;
                    btn.textContent = target.hidden ? '📂 Show documents' : '📂 Hide documents';
                    return;
                }
                btn.disabled = true;
                btn.textContent = 'Loading documents…';
                const files = await fetchFolderContent(folderId);
                target.innerHTML = renderFileGrid(files);
                target.dataset.loaded = '1';
                target.hidden = false;
                btn.textContent = '📂 Hide documents';
                btn.disabled = false;
            });
        });
    };

    // ---- FileBird folder lookup for facilities ----
    const normalizeFolderText = s => String(s || '')
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, '')
        .trim();

    const ensureFoldersLoaded = async () => {
        if (state.folders) return state.folders;
        if (!config.foldersUrl) return [];
        try {
            const res = await fetch(config.foldersUrl, { credentials: 'same-origin' });
            const data = await res.json();
            state.folders = Array.isArray(data) ? data : [];
        } catch (err) {
            console.warn('Failed to load FileBird folders', err);
            state.folders = [];
        }
        // Build name lookup
        state.folderByName = new Map();
        state.folders.forEach(f => {
            if (!f || !f.name) return;
            const norm = normalizeFolderText(f.name);
            if (!state.folderByName.has(norm)) {
                state.folderByName.set(norm, f.id);
            }
        });
        return state.folders;
    };

    const findFolderForFacility = facilityName => {
        if (!state.folderByName) return null;
        const norm = normalizeFolderText(facilityName);
        if (!norm) return null;

        // 1. Exact normalized match
        if (state.folderByName.has(norm)) return state.folderByName.get(norm);

        // 2. Prefix match — covers "Newport Academy" folder vs "Newport Academy – Port Townsend"
        //    facility, or "Asheville Academy" folder vs "Asheville Academy For Girls" facility.
        //    Requires the shorter name to be at least 12 chars, so single shared words like
        //    "Trails" or "Academy" can't match facilities they don't actually belong to.
        const MIN_PREFIX_LEN = 12;
        for (const [folderNorm, id] of state.folderByName.entries()) {
            const shorter = norm.length < folderNorm.length ? norm : folderNorm;
            const longer  = norm.length < folderNorm.length ? folderNorm : norm;
            if (shorter.length >= MIN_PREFIX_LEN && longer.startsWith(shorter)) {
                return id;
            }
        }

        return null;
    };

    const init = async () => {
        wireTabs();
        try {
            const [stateRes] = await Promise.all([
                fetch(config.apiUrl, { credentials: 'same-origin' }).then(r => r.json()),
                ensureFoldersLoaded(),
            ]);
            state.data = stateRes;
            updateCounts();
            renderFacilities();
            renderNews();
            renderLawsuits();
            renderLegislation();
        } catch (err) {
            console.error('Failed to load state data', err);
            $$('.section-content').forEach(el => {
                el.innerHTML = '<p class="error">Failed to load data. Please try again.</p>';
            });
        }
    };

    const wireTabs = () => {
        $$('.state-tab').forEach(tab => {
            tab.addEventListener('click', () => {
                const target = tab.dataset.tab;
                $$('.state-tab').forEach(t => {
                    t.classList.toggle('active', t === tab);
                    t.setAttribute('aria-selected', t === tab ? 'true' : 'false');
                });
                $$('.state-section').forEach(section => {
                    const isMatch = section.dataset.section === target;
                    section.classList.toggle('active', isMatch);
                    section.hidden = !isMatch;
                });
            });
        });
    };

    const updateCounts = () => {
        const counts = state.data.counts || {};
        const map = {
            facilities: counts.facilities_total ?? 0,
            news: counts.news ?? 0,
            lawsuits: counts.lawsuits ?? 0,
            legislation: counts.legislation ?? 0
        };
        Object.entries(map).forEach(([section, value]) => {
            const pill = document.querySelector(`.count-pill[data-section="${section}"] em`);
            if (pill) pill.textContent = value;
        });

        const isEmpty = section => (map[section] ?? 0) === 0;
        ['facilities', 'news', 'lawsuits', 'legislation'].forEach(section => {
            const tab = document.querySelector(`.state-tab[data-tab="${section}"]`);
            const pill = document.querySelector(`.count-pill[data-section="${section}"]`);
            if (isEmpty(section)) {
                if (tab) tab.style.display = 'none';
                if (pill) pill.style.display = 'none';
                const sec = document.getElementById(`section-${section}`);
                if (sec) sec.hidden = true;
            }
        });

        const firstVisibleTab = $$('.state-tab').find(t => t.style.display !== 'none');
        if (firstVisibleTab && !firstVisibleTab.classList.contains('active')) {
            firstVisibleTab.click();
        }
    };

    // ---------- Facilities (merged Programs + Inspections) ----------
    const narrativeRow = (label, value) => {
        if (!value) return '';
        return `
            <div class="narrative-row">
                <strong>${escapeHtml(label)}:</strong>
                <p>${escapeHtml(String(value))}</p>
            </div>`;
    };

    // Render a single finding regardless of state-specific shape.
    // OR uses {rule, excerpt}; AZ uses {rule, evidence, findings};
    // WA mixes {rule, evidence, findings}; some states pass plain strings.
    const extractFindingHtml = f => {
        if (!f) return '';
        if (typeof f === 'string') {
            return `<div class="finding-item"><p>${escapeHtml(f)}</p></div>`;
        }
        if (typeof f !== 'object') return '';

        const rule = f.rule || f.rule_number || f.ruleNumber || f.rule_id || '';
        // For AZ/WA the long descriptive text often lives in the `findings` field
        // alongside an `evidence` field; try them in order.
        const description =
            f.excerpt ||
            f.description ||
            f.rule_description ||
            f.text ||
            f.findings ||
            f.evidence ||
            f.short_text ||
            '';

        if (!rule && !description) {
            // Last-resort: serialize so the user sees something rather than blank rows.
            return `<div class="finding-item"><pre style="white-space:pre-wrap;font-family:inherit;margin:0;">${escapeHtml(JSON.stringify(f, null, 2))}</pre></div>`;
        }

        const extra = (f.evidence && description !== f.evidence)
            ? `<p class="finding-evidence"><em>Evidence:</em> ${escapeHtml(String(f.evidence))}</p>`
            : '';

        return `
            <div class="finding-item">
                ${rule ? `<strong>${escapeHtml(rule)}</strong>` : ''}
                <p>${escapeHtml(String(description))}</p>
                ${extra}
            </div>`;
    };

    const renderInspectionRecords = inspections => {
        if (!Array.isArray(inspections) || inspections.length === 0) {
            return '<p class="docs-empty">No inspection records on file.</p>';
        }
        // Newest-first defensive sort (server already does this, but the cost is trivial).
        const sorted = [...inspections].sort((a, b) => {
            const ta = Date.parse(a.date || '') || 0;
            const tb = Date.parse(b.date || '') || 0;
            return tb - ta;
        });
        return `<div class="inspection-record-list">${sorted.map(insp => {
            const findings = Array.isArray(insp.findings) ? insp.findings : [];
            const cats = (insp.categories && typeof insp.categories === 'object') ? insp.categories : {};
            const hasCorrectiveActions = cats.corrective_actions && String(cats.corrective_actions).toLowerCase() !== 'none';
            const hasFindings = (insp.finding_count || 0) > 0 || findings.length > 0 || hasCorrectiveActions;
            const findingCount = insp.finding_count || findings.length;
            const klass = hasFindings ? 'inspection-box-violation' : 'inspection-box-clean';
            const dateStr = formatDate(insp.date) || '—';
            const typeStr = insp.type ? escapeHtml(insp.type) : 'Inspection';
            const findingsLabel = hasFindings
                ? (findingCount > 0
                    ? `${findingCount} finding${findingCount === 1 ? '' : 's'}`
                    : 'Has corrective actions')
                : 'No findings';

            const sourceLinks = [];
            if (insp.pdf_url) sourceLinks.push(`<a href="${escapeHtml(insp.pdf_url)}" target="_blank" rel="noopener">PDF</a>`);
            if (insp.report_url) sourceLinks.push(`<a href="${escapeHtml(insp.report_url)}" target="_blank" rel="noopener">Source page</a>`);

            const detailsRows = [
                cats.licensee ? `<strong>Licensee:</strong> ${escapeHtml(cats.licensee)}` : '',
                `<strong>Type:</strong> ${typeStr}`,
                `<strong>Date:</strong> ${escapeHtml(dateStr)}`,
                cats.visit_date ? `<strong>Visit:</strong> ${escapeHtml(cats.visit_date)}` : '',
                sourceLinks.length ? `<strong>Source:</strong> ${sourceLinks.join(' &middot; ')}` : '',
            ].filter(Boolean).join('<br>');

            const programInfoRows = [
                narrativeRow('Program Description', cats.program_description),
                narrativeRow('Program Services', cats.program_services),
                narrativeRow('Capacity & Age Range', cats.capacity_age_range),
                narrativeRow('Average Length of Stay', cats.average_length_of_stay),
                narrativeRow('Average Daily Population', cats.average_daily_population_served),
                narrativeRow('Children Served Annually', cats.number_of_children_served_annually),
                narrativeRow('Seclusion / Restraint', cats.use_of_seclusion_or_restraint),
            ].filter(Boolean).join('');
            const programInfoBlock = programInfoRows ? `
                <details class="violation-box">
                    <summary class="deficiency-header">Program Information</summary>
                    <div class="deficiency-content">${programInfoRows}</div>
                </details>` : '';

            const observationsRows = [
                narrativeRow('Interviews & Observations', cats.interviews_observations),
                narrativeRow('Interview Summary', cats.interview_summary),
                narrativeRow('Observations', cats.observations),
                narrativeRow('Program Strengths', cats.program_strengths),
                narrativeRow('Program Challenges', cats.program_challenges),
            ].filter(Boolean).join('');
            const observationsBlock = observationsRows ? `
                <details class="violation-box">
                    <summary class="deficiency-header">Observations & Interviews</summary>
                    <div class="deficiency-content">${observationsRows}</div>
                </details>` : '';

            const legalRows = [
                narrativeRow('Lawsuits', cats.lawsuits),
                narrativeRow('Grievances & Complaints', cats.grievances_and_complaints),
            ].filter(Boolean).join('');
            const legalBlock = legalRows ? `
                <details class="violation-box">
                    <summary class="deficiency-header">Lawsuits & Grievances</summary>
                    <div class="deficiency-content">${legalRows}</div>
                </details>` : '';

            const complianceBlock = cats.program_compliance
                ? `<div class="narrative-row"><strong>Program Compliance:</strong><p>${escapeHtml(cats.program_compliance)}</p></div>`
                : '';

            const correctiveBlock = hasCorrectiveActions
                ? `<div class="narrative-row"><strong>Corrective Actions:</strong><p>${escapeHtml(cats.corrective_actions)}</p></div>`
                : '';

            const findingsBlock = findings.length
                ? `<details class="violation-box" open>
                       <summary class="deficiency-header">${findings.length} compliance finding${findings.length === 1 ? '' : 's'}</summary>
                       <div class="deficiency-content">
                           ${findings.map(extractFindingHtml).join('')}
                       </div>
                   </details>`
                : '';

            return `
                <details class="inspection-box ${klass}">
                    <summary class="inspection-header">
                        <span class="inspection-summary-date">${escapeHtml(dateStr)}</span>
                        <span class="inspection-summary-type">${typeStr}</span>
                        <span class="inspection-summary-findings">${escapeHtml(findingsLabel)}</span>
                    </summary>
                    <div class="inspection-content">
                        <div class="inspection-details-block">${detailsRows}</div>
                        ${insp.summary ? `<div class="inspection-summary-text">${escapeHtml(insp.summary)}</div>` : ''}
                        ${complianceBlock}
                        ${programInfoBlock}
                        ${observationsBlock}
                        ${legalBlock}
                        ${correctiveBlock}
                        ${findingsBlock}
                    </div>
                </details>
            `;
        }).join('')}</div>`;
    };

    const facilityCardHtml = facility => {
        const displayName = getFacilityDisplayName(facility);
        const stats = [];
        if (facility.inspection_count > 0) {
            stats.push(`<span class="stat">${facility.inspection_count} inspection${facility.inspection_count === 1 ? '' : 's'}</span>`);
        }
        if (facility.violation_count > 0) {
            stats.push(`<span class="stat stat-violation">${facility.violation_count} violation${facility.violation_count === 1 ? '' : 's'}</span>`);
        }
        if (facility.latest_inspection_date) {
            stats.push(`<span class="stat">Latest: ${escapeHtml(facility.latest_inspection_date)}</span>`);
        }
        if (facility.type) {
            stats.push(`<span class="stat">${escapeHtml(facility.type)}</span>`);
        }
        if (facility.operating_period) {
            stats.push(`<span class="stat">${escapeHtml(facility.operating_period)}</span>`);
        }

        const folderId = findFolderForFacility(displayName);
        const hasInspections = Array.isArray(facility.inspections) && facility.inspections.length > 0;

        const toggleButtons = [];
        if (hasInspections) {
            toggleButtons.push(`<button type="button" class="facility-expand-btn" data-panel="inspections">📋 Show inspections</button>`);
        }
        if (folderId) {
            toggleButtons.push(`<button type="button" class="facility-expand-btn" data-panel="docs" data-folder-id="${escapeHtml(String(folderId))}">📂 Show documents</button>`);
        }

        return `
            <li class="facility-card${(toggleButtons.length ? ' is-expandable' : '')}">
                <div class="facility-card-header">
                    <h3 class="facility-card-name">${escapeHtml(displayName)}</h3>
                    ${facility.status ? `<span class="status-pill status-${escapeHtml(String(facility.status).toLowerCase())}">${escapeHtml(facility.status)}</span>` : ''}
                </div>
                ${facility.address ? `<div class="facility-card-address">${escapeHtml(facility.address)}</div>` : ''}
                ${facility.operator_name ? `<div class="facility-card-operator">Operator: ${escapeHtml(facility.operator_name)}</div>` : ''}
                ${stats.length ? `<div class="facility-card-stats">${stats.join('')}</div>` : ''}
                ${toggleButtons.length ? `
                    <div class="facility-card-actions">${toggleButtons.join('')}</div>
                    <div class="facility-card-panels">
                        ${hasInspections ? `<div class="facility-panel" data-panel="inspections" hidden>${renderInspectionRecords(facility.inspections)}</div>` : ''}
                        ${folderId ? `<div class="facility-panel" data-panel="docs" data-folder-id="${escapeHtml(String(folderId))}" hidden><p class="loading">Loading documents…</p></div>` : ''}
                    </div>
                ` : ''}
            </li>
        `;
    };

    const wireFacilityToggles = container => {
        container.querySelectorAll('.facility-expand-btn').forEach(btn => {
            btn.addEventListener('click', async () => {
                const card = btn.closest('.facility-card');
                if (!card) return;
                const panelKind = btn.dataset.panel;
                const panel = card.querySelector(`.facility-panel[data-panel="${panelKind}"]`);
                if (!panel) return;

                if (panel.hidden) {
                    panel.hidden = false;
                    btn.textContent = panelKind === 'inspections' ? '📋 Hide inspections' : '📂 Hide documents';
                } else {
                    panel.hidden = true;
                    btn.textContent = panelKind === 'inspections' ? '📋 Show inspections' : '📂 Show documents';
                    return;
                }

                if (panelKind === 'docs' && panel.dataset.loaded !== '1') {
                    const folderId = panel.dataset.folderId || btn.dataset.folderId;
                    btn.disabled = true;
                    const files = await fetchFolderContent(folderId);
                    panel.innerHTML = renderFileGrid(files);
                    panel.dataset.loaded = '1';
                    btn.disabled = false;
                }
            });
        });
    };

    const renderFacilities = () => {
        const container = document.querySelector('#section-facilities .section-content');
        const facilities = state.data.facilities || { active: [], closed: [], total: 0 };
        const inspections = state.data.inspections || {};

        if ((facilities.total ?? 0) === 0) {
            container.innerHTML = '<p class="empty">No facilities on file for this state yet.</p>';
            return;
        }

        const inspectionsLink = inspections.has_reports
            ? `<a href="${escapeHtml(inspections.page_url)}" class="full-page-link">View full inspection report search →</a>`
            : '';

        const consolidatedActive = consolidateFacilitiesForDisplay(facilities.active || []);
        const consolidatedClosed = consolidateFacilitiesForDisplay(facilities.closed || []);

        const activeHtml = consolidatedActive.length
            ? `<ul class="facility-list">${consolidatedActive.map(facilityCardHtml).join('')}</ul>`
            : '<p class="empty">No active facilities listed.</p>';

        const closedHtml = consolidatedClosed.length
            ? `<ul class="facility-list closed-list">${consolidatedClosed.map(facilityCardHtml).join('')}</ul>`
            : '';

        container.innerHTML = `
            <div class="facility-toolbar">
                <input type="text" class="section-search" id="facilitySearch" placeholder="Search facilities...">
                ${inspectionsLink}
            </div>

            <div class="facility-group facility-group-active">
                <h3 class="facility-group-heading">
                    Active <span class="count">(${consolidatedActive.length})</span>
                </h3>
                ${activeHtml}
            </div>

            ${consolidatedClosed.length ? `
                <div class="facility-group facility-group-closed">
                    <h3 class="facility-group-heading">
                        Closed <span class="count">(${consolidatedClosed.length})</span>
                    </h3>
                    ${closedHtml}
                </div>
            ` : ''}
        `;

        wireFacilityToggles(container);

        const search = container.querySelector('#facilitySearch');
        search.addEventListener('input', () => {
            const q = search.value.trim().toLowerCase();
            container.querySelectorAll('.facility-card').forEach(card => {
                card.style.display = card.textContent.toLowerCase().includes(q) ? '' : 'none';
            });
            // Hide group heading if all cards within are hidden.
            container.querySelectorAll('.facility-group').forEach(group => {
                const visible = group.querySelectorAll('.facility-card:not([style*="display: none"])').length;
                group.style.display = visible === 0 ? 'none' : '';
            });
        });
    };

    // ---------- News ----------
    const submitNewsButton = () => {
        if (!config.newsSubmitUrl) return '';
        const url = `${config.newsSubmitUrl}${config.newsSubmitUrl.includes('?') ? '&' : '?'}prefill_state=${encodeURIComponent(config.stateName)}`;
        return `<a href="${escapeHtml(url)}" class="submit-news-btn">+ Submit news for ${escapeHtml(config.stateName)}</a>`;
    };

    const renderNews = () => {
        const container = document.querySelector('#section-news .section-content');
        const news = state.data.news || [];
        const submitBtn = submitNewsButton();
        if (news.length === 0) {
            container.innerHTML = `
                <p class="empty">No news items tagged for this state.</p>
                ${submitBtn}
            `;
            return;
        }
        container.innerHTML = `
            <div class="news-toolbar">
                <input type="text" class="section-search" id="newsSearch" placeholder="Search news...">
                ${submitBtn}
            </div>
            <ul class="news-list">
                ${news.map(n => `
                    <li class="news-item">
                        <div class="news-item-header">
                            <span class="news-type-badge type-${escapeHtml(n.article_type)}">${escapeHtml(n.article_type)}</span>
                            <a href="${escapeHtml(n.article_url)}" target="_blank" rel="noopener" class="news-title">
                                ${escapeHtml(n.display_title || n.article_title)}
                            </a>
                        </div>
                        <div class="news-meta">
                            ${n.author ? escapeHtml(n.author) + ' · ' : ''}
                            ${n.publication_name ? escapeHtml(n.publication_name) + ' · ' : ''}
                            ${formatDate(n.publication_date)}
                        </div>
                        ${n.summary ? `<p class="news-summary">${escapeHtml(n.summary)}</p>` : ''}
                    </li>
                `).join('')}
            </ul>
        `;
        const search = container.querySelector('#newsSearch');
        search.addEventListener('input', () => {
            const q = search.value.trim().toLowerCase();
            container.querySelectorAll('.news-item').forEach(li => {
                li.style.display = li.textContent.toLowerCase().includes(q) ? '' : 'none';
            });
        });
    };

    // ---------- Lawsuits ----------
    const renderLawsuits = () => {
        const container = document.querySelector('#section-lawsuits .section-content');
        const lawsuits = state.data.lawsuits || [];
        if (lawsuits.length === 0) {
            container.innerHTML = '<p class="empty">No lawsuits on record for this state yet.</p>';
            return;
        }
        container.innerHTML = `
            <input type="text" class="section-search" id="lawsuitSearch" placeholder="Search lawsuits...">
            <ul class="lawsuit-list">
                ${lawsuits.map(l => `
                    <li class="lawsuit-item">
                        <div class="lawsuit-header">
                            <h3 class="lawsuit-name">${escapeHtml(l.case_name)}</h3>
                            <span class="status-pill status-${escapeHtml(l.status)}">${escapeHtml(l.status)}</span>
                        </div>
                        <div class="lawsuit-meta">
                            ${l.case_number ? `<span>${escapeHtml(l.case_number)}</span>` : ''}
                            ${l.court ? `<span>${escapeHtml(l.court)}</span>` : ''}
                            ${l.filing_date ? `<span>Filed ${formatDate(l.filing_date)}</span>` : ''}
                        </div>
                        ${(l.facilities_mentioned || []).length ? `<div class="lawsuit-facilities">Facilities: ${l.facilities_mentioned.map(escapeHtml).join(', ')}</div>` : ''}
                        ${l.summary ? `<p class="lawsuit-summary">${escapeHtml(l.summary)}</p>` : ''}
                        ${(l.source_urls || []).length ? `
                            <div class="lawsuit-sources">
                                ${l.source_urls.map(url => `<a href="${escapeHtml(url)}" target="_blank" rel="noopener">${escapeHtml(url)}</a>`).join(' · ')}
                            </div>` : ''}
                        ${l.filebird_folder_id ? `
                            <div class="docs-block">
                                <button type="button" class="docs-toggle" data-folder-id="${escapeHtml(String(l.filebird_folder_id))}" data-count="">📂 Show documents</button>
                                <div class="docs-target" hidden></div>
                            </div>` : ''}
                    </li>
                `).join('')}
            </ul>
        `;
        wireDocToggles(container);
        const search = container.querySelector('#lawsuitSearch');
        search.addEventListener('input', () => {
            const q = search.value.trim().toLowerCase();
            container.querySelectorAll('.lawsuit-item').forEach(li => {
                li.style.display = li.textContent.toLowerCase().includes(q) ? '' : 'none';
            });
        });
    };

    // ---------- Legislation ----------
    const renderLegislation = () => {
        const container = document.querySelector('#section-legislation .section-content');
        const bills = state.data.legislation || [];
        if (bills.length === 0) {
            container.innerHTML = '<p class="empty">No legislation on record for this state yet.</p>';
            return;
        }
        container.innerHTML = `
            <input type="text" class="section-search" id="legislationSearch" placeholder="Search legislation...">
            <ul class="legislation-list">
                ${bills.map(b => `
                    <li class="legislation-item">
                        <div class="legislation-header">
                            <h3 class="legislation-name">
                                ${b.bill_number ? `<span class="bill-number">${escapeHtml(b.bill_number)}</span>` : ''}
                                ${escapeHtml(b.bill_title)}
                            </h3>
                            <span class="status-pill status-${escapeHtml(b.status)}">${escapeHtml(b.status)}</span>
                            ${b.position && b.position !== 'unknown' ? `<span class="position-pill position-${escapeHtml(b.position)}">${escapeHtml(b.position)}</span>` : ''}
                        </div>
                        <div class="legislation-meta">
                            ${b.session_year ? `<span>${escapeHtml(b.session_year)}</span>` : ''}
                            ${b.chamber && b.chamber !== 'unknown' ? `<span>${escapeHtml(b.chamber.replace('_', ' '))}</span>` : ''}
                            ${b.last_action_date ? `<span>Last action ${formatDate(b.last_action_date)}</span>` : ''}
                        </div>
                        ${(b.sponsors || []).length ? `<div class="legislation-sponsors">Sponsors: ${b.sponsors.map(escapeHtml).join(', ')}</div>` : ''}
                        ${b.summary ? `<p class="legislation-summary">${escapeHtml(b.summary)}</p>` : ''}
                        <div class="legislation-links">
                            ${b.official_url ? `<a href="${escapeHtml(b.official_url)}" target="_blank" rel="noopener">Tracker</a>` : ''}
                            ${b.full_text_url ? `<a href="${escapeHtml(b.full_text_url)}" target="_blank" rel="noopener">Full text</a>` : ''}
                        </div>
                        ${b.filebird_folder_id ? `
                            <div class="docs-block">
                                <button type="button" class="docs-toggle" data-folder-id="${escapeHtml(String(b.filebird_folder_id))}" data-count="">📂 Show documents</button>
                                <div class="docs-target" hidden></div>
                            </div>` : ''}
                    </li>
                `).join('')}
            </ul>
        `;
        wireDocToggles(container);
        const search = container.querySelector('#legislationSearch');
        search.addEventListener('input', () => {
            const q = search.value.trim().toLowerCase();
            container.querySelectorAll('.legislation-item').forEach(li => {
                li.style.display = li.textContent.toLowerCase().includes(q) ? '' : 'none';
            });
        });
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
