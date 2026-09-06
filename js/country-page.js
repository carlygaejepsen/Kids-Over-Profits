/**
 * Country hub page script.
 *
 * GENERATED from js/state-page.js: the country REST route returns the same
 * facility/news/lawsuit/legislation shape as the state route, so the two pages
 * share one renderer. Edit state-page.js, then regenerate this file with the
 * substitution list in scripts/generate-country-page.py. Do not hand-edit.
 */
(function() {
    'use strict';

    const config = window.countryPageConfig;
    // Compat shim: this file is generated from state-page.js (see the header
    // comment) and its helpers read config.stateName / config.stateSlug.
    // Mirror the country fields so the shared code needs no changes.
    if (config) {
        if (!config.stateName && config.countryName) config.stateName = config.countryName;
        if (!config.stateSlug && config.countrySlug) config.stateSlug = config.countrySlug;
    }
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

    const normalizeFacilityLookupKey = value => {
        let text = normalizeText(value).toLowerCase();
        if (!text) return '';
        const colonPos = text.indexOf(':');
        if (colonPos !== -1) text = text.slice(0, colonPos);
        text = text
            .replace(/\s*[-,;]\s*/g, ' ')
            .replace(/\s*&\s*/g, ' and ')
            .replace(/[^a-z0-9\s]/g, ' ')
            .replace(/\s+/g, ' ')
            .trim();
        return text;
    };

    const normalizeDateKey = value => {
        const text = normalizeText(value);
        if (!text) return '';
        const direct = Date.parse(text);
        if (!Number.isNaN(direct)) return new Date(direct).toISOString().slice(0, 10);

        const match = text.match(/^(\d{1,2})\/(\d{1,2})\/(\d{2,4})$/);
        if (!match) return '';
        const month = match[1].padStart(2, '0');
        const day = match[2].padStart(2, '0');
        const year = match[3].length === 2 ? `20${match[3]}` : match[3];
        return `${year}-${month}-${day}`;
    };

    const chooseRicherText = (left, right) => {
        const a = normalizeText(left);
        const b = normalizeText(right);
        if (!a) return b;
        if (!b) return a;
        return b.length > a.length ? b : a;
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

    const hasJunkPlaceholderName = facility => {
        const name = normalizeText(facility && facility.name);
        const projectName = normalizeText(facility && facility.project_name);
        const operatorName = normalizeText(facility && facility.operator_name);
        if (pickBestFacilityLabel(name, projectName, operatorName)) return false;
        if (Array.isArray(facility && facility.inspections)) {
            for (const insp of facility.inspections) {
                const licensee = normalizeText(insp && insp.categories && insp.categories.licensee);
                if (licensee && !isAddressLikeText(licensee)) return false;
            }
        }
        return true;
    };

    const isLikelyBrokenFacilityRecord = facility => {
        const name = normalizeText(facility && facility.name);
        const projectName = normalizeText(facility && facility.project_name);
        const operatorName = normalizeText(facility && facility.operator_name);
        const address = normalizeText(facility && facility.address);
        const hasInspections = (facility && facility.inspection_count > 0) || (Array.isArray(facility && facility.inspections) && facility.inspections.length > 0);
        const hasUsableLabel = Boolean(pickBestFacilityLabel(name, projectName, operatorName));
        const addressLikeLabel = isAddressLikeText(name) || isAddressLikeText(projectName);
        const stateOnlyAddress = address && normalizeText(config.stateName).toLowerCase() === address.toLowerCase();

        if (!hasInspections && !hasUsableLabel && addressLikeLabel && !operatorName && (!address || stateOnlyAddress)) {
            return true;
        }
        // No salvageable label anywhere — would render as "Facility (…)" placeholder.
        return hasJunkPlaceholderName(facility);
    };

    // CCL batch rows carry source_url pointing at the CA transparency API,
    // which returns raw JSON if opened in a browser. Rewrite those to the
    // human-readable facility-detail page; leave any other URL untouched.
    const toCaFacilityDetailUrl = (sourceUrl, facilityNumber) => {
        const url = normalizeText(sourceUrl);
        if (!/transparencyapi/i.test(url)) return url;
        const num = normalizeText(facilityNumber).replace(/\D+/g, '')
            || (url.match(/(\d{6,})/) || [])[1] || '';
        return num ? `https://www.ccld.dss.ca.gov/carefacilitysearch/FacDetail/${num}` : '';
    };

    const normalizeCaliforniaDetailedReport = raw => {
        if (!raw || typeof raw !== 'object') return null;

        const categories = (raw.categories && typeof raw.categories === 'object') ? raw.categories : raw;
        const rawDate = normalizeText(raw.report_date || categories.report_date || categories.visit_date || raw.visit_date);

        const deficiencies = Array.isArray(categories.deficiencies)
            ? categories.deficiencies
            : (Array.isArray(raw.deficiencies) ? raw.deficiencies : []);

        return {
            facility_name: normalizeText(raw.facility_name || raw.program_name || categories.facility_name || categories.program_name),
            facility_number: normalizeText(raw.facility_number || categories.facility_number),
            report_type: normalizeText(raw.report_type || categories.report_type),
            visit_date: normalizeText(raw.visit_date || categories.visit_date || rawDate),
            report_date: rawDate,
            form_number: normalizeText(raw.form_number || categories.form_number),
            census: normalizeText(raw.census || categories.census),
            complaint_status: normalizeText(raw.complaint_status || categories.complaint_status),
            met_with: normalizeText(raw.met_with || categories.met_with),
            narrative: normalizeText(raw.narrative || categories.narrative || raw.raw_content),
            investigation_findings: normalizeText(raw.investigation_findings || categories.investigation_findings),
            deficiencies,
            source_url: toCaFacilityDetailUrl(
                raw.source_url || categories.source_url || raw.report_url,
                raw.facility_number || categories.facility_number
            ),
        };
    };

    const buildCaliforniaDetailIndex = payloads => {
        const byFacilityAndDate = new Map();

        const storeReport = report => {
            const normalized = normalizeCaliforniaDetailedReport(report);
            if (!normalized) return;

            const facilityKey = normalizeFacilityLookupKey(normalized.facility_name);
            const dateKey = normalizeDateKey(normalized.visit_date || normalized.report_date);
            if (!facilityKey || !dateKey) return;

            const mapKey = `${facilityKey}||${dateKey}`;
            const existing = byFacilityAndDate.get(mapKey);
            if (!existing) {
                byFacilityAndDate.set(mapKey, normalized);
                return;
            }

            const existingScore = (existing.narrative ? 1 : 0) + (existing.investigation_findings ? 1 : 0) + ((existing.deficiencies || []).length ? 1 : 0);
            const incomingScore = (normalized.narrative ? 1 : 0) + (normalized.investigation_findings ? 1 : 0) + ((normalized.deficiencies || []).length ? 1 : 0);
            if (incomingScore > existingScore) {
                byFacilityAndDate.set(mapKey, normalized);
            }
        };

        payloads.forEach(payload => {
            if (!Array.isArray(payload)) return;
            payload.forEach(storeReport);
        });

        return byFacilityAndDate;
    };

    const buildFacilityLookupCandidates = facility => {
        const candidates = [facility && facility.name, facility && facility.project_name, facility && facility.operator_name];
        if (Array.isArray(facility && facility.inspections)) {
            facility.inspections.forEach(insp => {
                if (insp && insp.categories && insp.categories.licensee) {
                    candidates.push(insp.categories.licensee);
                }
            });
        }
        return Array.from(new Set(candidates.map(normalizeFacilityLookupKey).filter(Boolean)));
    };

    const enrichFacilityWithCaliforniaDetails = (facility, detailIndex) => {
        if (!facility || !Array.isArray(facility.inspections) || !facility.inspections.length) return facility;
        const facilityKeys = buildFacilityLookupCandidates(facility);
        if (!facilityKeys.length) return facility;

        let violationCount = 0;
        const inspections = facility.inspections.map(insp => {
            const dateKeys = [
                normalizeDateKey(insp && insp.date),
                normalizeDateKey(insp && insp.categories && insp.categories.visit_date),
            ].filter(Boolean);

            let detail = null;
            for (const facilityKey of facilityKeys) {
                for (const dateKey of dateKeys) {
                    detail = detailIndex.get(`${facilityKey}||${dateKey}`);
                    if (detail) break;
                }
                if (detail) break;
            }

            const merged = {
                ...insp,
                report_url: normalizeText(insp && insp.report_url) || (detail && detail.source_url) || '',
                summary: chooseRicherText(insp && insp.summary, detail && detail.report_type),
                narrative: chooseRicherText(insp && insp.narrative, detail && detail.narrative),
                investigation_findings: chooseRicherText(insp && insp.investigation_findings, detail && detail.investigation_findings),
                deficiencies: Array.isArray(insp && insp.deficiencies) && insp.deficiencies.length
                    ? insp.deficiencies
                    : ((detail && Array.isArray(detail.deficiencies)) ? detail.deficiencies : []),
                finding_count: Number(insp && insp.finding_count) || ((detail && Array.isArray(detail.deficiencies)) ? detail.deficiencies.length : 0),
                categories: {
                    ...((insp && insp.categories && typeof insp.categories === 'object') ? insp.categories : {}),
                    ...(detail && detail.visit_date ? { visit_date: detail.visit_date } : {}),
                    ...(detail && detail.form_number ? { form_number: detail.form_number } : {}),
                    ...(detail && detail.census ? { census: detail.census } : {}),
                    ...(detail && detail.complaint_status ? { complaint_status: detail.complaint_status } : {}),
                    ...(detail && detail.met_with ? { met_with: detail.met_with } : {}),
                }
            };

            violationCount += merged.finding_count || 0;
            return merged;
        });

        return {
            ...facility,
            inspections,
            violation_count: facility.violation_count || violationCount,
        };
    };

    const enrichCaliforniaFacilities = async stateData => {
        const datasetUrls = Array.isArray(stateData?.inspections?.dataset_urls) ? stateData.inspections.dataset_urls : [];
        if (stateData?.state?.slug !== 'california' || !datasetUrls.length) return stateData;

        const payloads = await Promise.all(datasetUrls.map(async url => {
            try {
                const response = await fetch(url, { credentials: 'same-origin' });
                if (!response.ok) return [];
                return await response.json();
            } catch (error) {
                console.warn('California detail dataset load failed', error);
                return [];
            }
        }));

        const detailIndex = buildCaliforniaDetailIndex(payloads);
        if (!detailIndex.size) return stateData;

        const enrichList = list => Array.isArray(list) ? list.map(facility => enrichFacilityWithCaliforniaDetails(facility, detailIndex)) : [];
        return {
            ...stateData,
            facilities: {
                ...stateData.facilities,
                active: enrichList(stateData.facilities && stateData.facilities.active),
                closed: enrichList(stateData.facilities && stateData.facilities.closed),
            }
        };
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

        // Every other field: fill empty scalars, union lists, merge keyed maps,
        // so no detail is lost when a loose record folds into a named one.
        const skip = new Set(['inspection_count', 'violation_count', 'latest_inspection_date', 'inspections', 'name', 'in_master', 'in_inspections']);
        Object.keys(source).forEach(key => {
            if (skip.has(key)) return;
            const sv = source[key];
            const tv = target[key];
            if (sv === null || sv === undefined || sv === '') return;
            if (Array.isArray(sv)) {
                if (!sv.length) return;
                const merged = Array.isArray(tv) ? [...tv] : [];
                sv.forEach(item => {
                    const dup = (item && typeof item === 'object')
                        ? merged.some(have => JSON.stringify(have) === JSON.stringify(item))
                        : merged.some(have => normalizeText(have).toLowerCase() === normalizeText(item).toLowerCase());
                    if (!dup) merged.push(item);
                });
                target[key] = merged;
            } else if (typeof sv === 'object') {
                if (!Object.keys(sv).length) return;
                target[key] = (tv && typeof tv === 'object' && !Array.isArray(tv)) ? { ...sv, ...tv } : sv;
            } else if (tv === null || tv === undefined || tv === '' || (key === 'status' && String(tv).toLowerCase() === 'unknown')) {
                target[key] = sv;
            }
        });
        if (Array.isArray(target.addresses) && normalizeText(target.address) && !target.addresses.includes(target.address)) {
            target.addresses.unshift(target.address);
        }
        if (!normalizeText(target.address) && Array.isArray(target.addresses) && target.addresses.length) {
            target.address = target.addresses[0];
        }
    };

    // Detects names that look like URL slugs (all lowercase, no spaces, no punctuation).
    const isSlugLikeName = value => {
        const text = normalizeText(value);
        return text.length >= 10 && /^[a-z0-9]+$/.test(text);
    };

    const consolidateFacilitiesForDisplay = facilities => {
        const list = Array.isArray(facilities) ? facilities.map(f => ({ ...f, inspections: [...(f.inspections || [])] })) : [];
        if (!list.length) return [];

        const addrToNamed = new Map();
        const named = [];
        const loose = [];

        list.forEach(facility => {
            const candidate = pickBestFacilityLabel(facility.name, facility.project_name, facility.operator_name);
            const isLoose = !candidate;
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

        // Second pass: merge slug-like named records (e.g. "collierjuveniledetentioncenter")
        // into their proper-named counterparts by slug-key containment.
        const properNamed = [];
        const slugNamed = [];
        named.forEach(facility => {
            const label = pickBestFacilityLabel(facility.name, facility.project_name, facility.operator_name);
            (isSlugLikeName(label) ? slugNamed : properNamed).push(facility);
        });

        if (slugNamed.length) {
            // Build a lookup: strip-all-non-alphanum key -> facility for proper-named records.
            const properBySlugKey = new Map();
            properNamed.forEach(facility => {
                const label = pickBestFacilityLabel(facility.name, facility.project_name, facility.operator_name);
                const key = normalizeFolderText(label);
                if (key && !properBySlugKey.has(key)) properBySlugKey.set(key, facility);
            });

            slugNamed.forEach(facility => {
                const label = pickBestFacilityLabel(facility.name, facility.project_name, facility.operator_name);
                const slugKey = normalizeFolderText(label);
                let target = properBySlugKey.get(slugKey) || null;

                if (!target && slugKey) {
                    for (const [properKey, properFacility] of properBySlugKey.entries()) {
                        const shorter = slugKey.length < properKey.length ? slugKey : properKey;
                        const longer  = slugKey.length < properKey.length ? properKey : slugKey;
                        if (shorter.length >= 12 && longer.includes(shorter)) {
                            target = properFacility;
                            break;
                        }
                    }
                }

                if (target) {
                    mergeFacilityRecords(target, facility);
                } else {
                    properNamed.push(facility);
                }
            });
        }

        return properNamed;
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
    // mergeSameName=true asks the backend to also include any duplicate folders
    // that share this folder's name (used for facility document views, where the
    // same program is often filed under several organizational trees).
    const fetchFolderContent = async (folderId, { mergeSameName = false } = {}) => {
        if (!folderId || !config.folderContentUrl) return [];
        const cacheKey = `${folderId}|${mergeSameName ? 'name' : ''}`;
        if (folderContentCache.has(cacheKey)) return folderContentCache.get(cacheKey);
        try {
            const mergeParam = mergeSameName ? '&merge=name' : '';
            const res = await fetch(`${config.folderContentUrl}?id=${encodeURIComponent(folderId)}${mergeParam}`, { credentials: 'same-origin' });
            const data = await res.json();
            const files = Array.isArray(data) ? data : [];
            folderContentCache.set(cacheKey, files);
            return files;
        } catch (err) {
            console.warn('Folder content load failed', err);
            return [];
        }
    };

    // Facility documents fetched as a nested tree ({files, subfolders:[{name,
    // files, subfolders}]}) so the subfolder structure is preserved in the UI.
    const facilityTreeCache = new Map();
    const fetchFacilityDocTree = async folderId => {
        const empty = { files: [], subfolders: [] };
        if (!folderId || !config.folderContentUrl) return empty;
        const key = String(folderId);
        if (facilityTreeCache.has(key)) return facilityTreeCache.get(key);
        try {
            const res = await fetch(`${config.folderContentUrl}?id=${encodeURIComponent(folderId)}&merge=name&format=tree`, { credentials: 'same-origin' });
            const data = await res.json();
            const tree = (data && typeof data === 'object' && !Array.isArray(data))
                ? { files: Array.isArray(data.files) ? data.files : [], subfolders: Array.isArray(data.subfolders) ? data.subfolders : [] }
                : { files: Array.isArray(data) ? data : [], subfolders: [] };
            facilityTreeCache.set(key, tree);
            return tree;
        } catch (err) {
            console.warn('Facility doc tree load failed', err);
            return empty;
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

    // Total file count for a subfolder node, including nested subfolders.
    const countTreeFiles = node => {
        let total = Array.isArray(node && node.files) ? node.files.length : 0;
        if (Array.isArray(node && node.subfolders)) {
            node.subfolders.forEach(sf => { total += countTreeFiles(sf); });
        }
        return total;
    };

    // Render a facility doc tree: the root's own files, then each subfolder as a
    // collapsible <details> section so visitors can drill down, recursing into
    // deeper subfolders.
    const renderSubfolders = subs => {
        if (!Array.isArray(subs) || subs.length === 0) return '';
        return subs.map(sf => {
            const name = escapeHtml(sf.name || 'Folder');
            const files = Array.isArray(sf.files) ? sf.files : [];
            const count = countTreeFiles(sf);
            const inner = (files.length ? renderFileGrid(files) : '') + renderSubfolders(sf.subfolders);
            return `<details class="state-doc-subfolder">
                        <summary class="state-doc-subfolder-title">📁 ${name} <span class="state-doc-subfolder-count">(${count})</span></summary>
                        <div class="state-doc-subfolder-content">${inner}</div>
                    </details>`;
        }).join('');
    };

    const renderDocTree = tree => {
        const files = Array.isArray(tree && tree.files) ? tree.files : [];
        const subs = Array.isArray(tree && tree.subfolders) ? tree.subfolders : [];
        if (files.length === 0 && subs.length === 0) {
            return '<p class="docs-empty">No documents in this folder.</p>';
        }
        return (files.length ? renderFileGrid(files) : '') + renderSubfolders(subs);
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
            state.data = await enrichCaliforniaFacilities(stateRes);
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
            const deficiencies = Array.isArray(insp.deficiencies) ? insp.deficiencies : [];
            const cats = (insp.categories && typeof insp.categories === 'object') ? insp.categories : {};
            const hasCorrectiveActions = cats.corrective_actions && String(cats.corrective_actions).toLowerCase() !== 'none';
            const hasFindings = (insp.finding_count || 0) > 0 || findings.length > 0 || deficiencies.length > 0 || hasCorrectiveActions;
            const findingCount = insp.finding_count || findings.length || deficiencies.length;
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
                cats.status ? `<strong>Status:</strong> ${escapeHtml(cats.status)}` : '',
                cats.under_appeal ? `<strong>Under appeal:</strong> ${escapeHtml(cats.under_appeal)}` : '',
                insp.inspected_by ? `<strong>Inspected by:</strong> ${escapeHtml(insp.inspected_by)} authority (out-of-state)` : '',
                cats.visit_date ? `<strong>Visit:</strong> ${escapeHtml(cats.visit_date)}` : '',
                cats.form_number ? `<strong>Form:</strong> ${escapeHtml(cats.form_number)}` : '',
                cats.census ? `<strong>Census:</strong> ${escapeHtml(cats.census)}` : '',
                cats.complaint_status ? `<strong>Complaint Status:</strong> ${escapeHtml(cats.complaint_status)}` : '',
                cats.met_with ? `<strong>Met With:</strong> ${escapeHtml(cats.met_with)}` : '',
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

            const narrativeBlock = insp.narrative
                ? `<div class="narrative-row"><strong>Narrative:</strong><p>${escapeHtml(insp.narrative)}</p></div>`
                : '';

            const investigationFindingsBlock = insp.investigation_findings
                ? `<div class="narrative-row"><strong>Investigation Findings:</strong><p>${escapeHtml(insp.investigation_findings)}</p></div>`
                : '';

            const californiaDeficienciesBlock = deficiencies.length
                ? `<details class="violation-box" open>
                       <summary class="deficiency-header">${deficiencies.length} deficiency${deficiencies.length === 1 ? '' : 'ies'}</summary>
                       <div class="deficiency-content">
                           ${deficiencies.map((deficiency, index) => `
                               <div class="finding-item">
                                   <strong>Violation ${index + 1}</strong>
                                   ${deficiency.section_cited ? `<p><strong>Regulation:</strong> ${escapeHtml(String(deficiency.section_cited))}</p>` : ''}
                                   ${deficiency.description ? `<p>${escapeHtml(String(deficiency.description))}</p>` : ''}
                                   ${deficiency.plan_of_correction ? `<p><em>Plan of correction:</em> ${escapeHtml(String(deficiency.plan_of_correction))}</p>` : ''}
                               </div>
                           `).join('')}
                       </div>
                   </details>`
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
                        ${narrativeBlock}
                        ${investigationFindingsBlock}
                        ${complianceBlock}
                        ${programInfoBlock}
                        ${observationsBlock}
                        ${legalBlock}
                        ${correctiveBlock}
                        ${californiaDeficienciesBlock}
                        ${findingsBlock}
                    </div>
                </details>
            `;
        }).join('')}</div>`;
    };

    // For a given facility, find state-level news items whose facilities_mentioned
    // list contains this facility (case-insensitive, normalized name match).
    const findNewsForFacility = facilityName => {
        const stateNews = (state.data && Array.isArray(state.data.news)) ? state.data.news : [];
        if (!stateNews.length || !facilityName) return [];
        const targetKey = normalizeFolderText(facilityName);  // reuse the FileBird normalizer
        if (!targetKey) return [];
        return stateNews.filter(n => {
            const mentions = Array.isArray(n.facilities_mentioned) ? n.facilities_mentioned : [];
            for (const m of mentions) {
                if (!m) continue;
                // facilities_mentioned entries may be strings (legacy) or objects
                // shaped {name, facility_id}. Pull the name out either way.
                const name = typeof m === 'string' ? m : (m && m.name ? m.name : '');
                const mKey = normalizeFolderText(name);
                if (!mKey) continue;
                if (mKey === targetKey) return true;
                // Loose prefix match for variant phrasing.
                if (mKey.length >= 10 && targetKey.length >= 10 &&
                    (mKey.startsWith(targetKey) || targetKey.startsWith(mKey))) {
                    return true;
                }
            }
            return false;
        });
    };

    const renderFacilityNewsList = items => {
        if (!Array.isArray(items) || !items.length) {
            return '<p class="docs-empty">No news items mention this facility.</p>';
        }
        return `<ul class="facility-news-list">${items.map(n => `
            <li class="facility-news-item">
                <a href="${escapeHtml(n.article_url)}" target="_blank" rel="noopener" class="facility-news-title">
                    ${escapeHtml(n.display_title || n.article_title)}
                </a>
                <div class="facility-news-meta">
                    ${n.publication_name ? escapeHtml(n.publication_name) + ' · ' : ''}
                    ${formatDate(n.publication_date)}
                </div>
                ${n.summary ? `<p class="facility-news-summary">${escapeHtml(String(n.summary).slice(0, 240))}${String(n.summary).length > 240 ? '…' : ''}</p>` : ''}
            </li>
        `).join('')}</ul>`;
    };

    // Format operating-year(s) for the main card based on status.
    //   Open + has start year   -> "Est. 1994"
    //   Closed                  -> "1949–1990" / "1949–" if no end
    //   Anything else          -> the raw operating_period if it makes sense
    const formatOperatingYears = facility => {
        const period = String(facility.operating_period || '').trim();
        const status = String(facility.status || '').trim().toLowerCase();
        if (!period) return '';

        const isClosed = ['closed', 'shut down', 'shutdown', 'defunct'].includes(status);
        const rangeMatch = period.match(/(\d{4})\s*[-–—]\s*(\d{4})/);
        // End-only form from the API ("–2010"): a leading dash before a single
        // year means we only know the closure year, not the start year.
        const endOnlyMatch = !rangeMatch ? period.match(/^\s*[-–—]\s*(\d{4})/) : null;
        const startOnlyMatch = (!rangeMatch && !endOnlyMatch) ? period.match(/(\d{4})/) : null;

        if (isClosed) {
            // Closed: show the date range (never "Est.").
            if (rangeMatch) return `${rangeMatch[1]}–${rangeMatch[2]}`;
            if (endOnlyMatch) return `–${endOnlyMatch[1]}`;
            if (startOnlyMatch) return `${startOnlyMatch[1]}–`;
            return period;
        }

        // Open: "Est. YYYY". If somehow a range was captured for an open facility,
        // prefer the range so we don't lose info.
        if (rangeMatch) return `${rangeMatch[1]}–${rangeMatch[2]}`;
        if (endOnlyMatch) return `–${endOnlyMatch[1]}`;
        if (startOnlyMatch) return `Est. ${startOnlyMatch[1]}`;
        return period;
    };

    // Render a single list item: strings pass through; objects pull out the
    // common shape {name, role, employer, organization, url, label}.
    const renderListEntry = item => {
        if (item == null) return '';
        if (typeof item === 'string' || typeof item === 'number') {
            return escapeHtml(String(item));
        }
        if (typeof item !== 'object') return '';

        const url = item.url || item.link || item.href || '';
        const label = item.label || item.name || item.title || item.text || url;
        const role = item.role || '';
        const employer = item.employer || item.organization || '';

        let core;
        if (url) {
            core = `<a href="${escapeHtml(String(url))}" target="_blank" rel="noopener">${escapeHtml(String(label || url))}</a>`;
        } else {
            core = escapeHtml(String(label || ''));
        }
        const extras = [];
        if (role) extras.push(escapeHtml(String(role)));
        if (employer) extras.push(escapeHtml(String(employer)));
        return extras.length ? `${core} <span class="detail-sub">— ${extras.join(' · ')}</span>` : core;
    };

    // Render a detail sub-section if the array has at least one displayable item.
    const renderListSection = (label, items) => {
        if (!Array.isArray(items) || !items.length) return '';
        const lis = items
            .map(renderListEntry)
            .filter(s => s && s.trim() !== '')
            .map(s => `<li>${s}</li>`)
            .join('');
        if (!lis) return '';
        return `<div class="detail-row detail-list"><strong>${escapeHtml(label)}:</strong><ul>${lis}</ul></div>`;
    };

    const renderScalarRow = (label, value) => {
        if (value === null || value === undefined) return '';
        const text = String(value).trim();
        if (!text) return '';
        return `<div class="detail-row"><strong>${escapeHtml(label)}:</strong> ${escapeHtml(text)}</div>`;
    };

    const renderCompleteValue = (value, path, depth = 0) => {
        if (value === null || value === undefined || value === '') return '';
        const label = path || 'Value';
        if (typeof value !== 'object') {
            return renderScalarRow(label, value);
        }
        if (depth > 8) return renderScalarRow(label, '[nested data]');

        if (Array.isArray(value)) {
            const items = value.map((item, index) => renderCompleteValue(item, `${label} ${index + 1}`, depth + 1)).filter(Boolean);
            return items.length ? `<div class="detail-row detail-list"><strong>${escapeHtml(label)}:</strong><ul>${items.map(item => `<li>${item}</li>`).join('')}</ul></div>` : '';
        }

        const rows = Object.entries(value)
            .map(([key, child]) => renderCompleteValue(child, path ? `${path} → ${formatFieldLabel(key)}` : formatFieldLabel(key), depth + 1))
            .filter(Boolean);
        return rows.join('');
    };

    const renderCompleteSourceData = rawRecords => {
        if (!Array.isArray(rawRecords) || !rawRecords.length) return '';
        const records = rawRecords.map((record, index) => {
            if (!record || typeof record !== 'object' || !record.data || typeof record.data !== 'object') return '';
            const source = record.project_name ? ` (${record.project_name})` : '';
            return `<div class="facility-source-record"><strong>Source record${rawRecords.length > 1 ? ` ${index + 1}` : ''}${escapeHtml(source)}:</strong>${renderCompleteValue(record.data, '', 0)}</div>`;
        }).filter(Boolean);
        if (!records.length) return '';
        return `<div class="detail-row detail-complete-data"><strong>All source data</strong>${records.join('')}</div>`;
    };

    // "hasWildernessTherapy" / "has12Steps" / "hasEMDR" -> "Wilderness Therapy" / "12 Steps" / "EMDR"
    const humanizeFlagKey = key => String(key)
        .replace(/^has(?=[A-Z0-9])/, '')
        .replace(/([a-z0-9])([A-Z])/g, '$1 $2')
        .replace(/([A-Z]+)([A-Z][a-z])/g, '$1 $2')
        .trim()
        .replace(/^./, c => c.toUpperCase());

    // Checkbox-group sections (treatment types, philosophy, critical incidents)
    // arrive as arrays of raw has* keys — render them as chips like resources.
    const renderFlagChips = (label, keys, chipClass) => {
        if (!Array.isArray(keys) || !keys.length) return '';
        const chips = keys
            .map(k => `<span class="${chipClass || 'resource-chip'}">${escapeHtml(humanizeFlagKey(k))}</span>`)
            .join('');
        return `<div class="detail-row detail-resources"><strong>${escapeHtml(label)}:</strong> ${chips}</div>`;
    };

    // "has*" resource flag -> human label
    const RESOURCE_FLAG_LABELS = {
        hasNews: 'News articles', hasPressReleases: 'Press releases',
        hasInspections: 'Inspection reports', hasStateReports: 'State reports',
        hasRegulatoryFilings: 'Regulatory filings', hasLawsuits: 'Lawsuits',
        hasPoliceReports: 'Police reports', hasArticlesOfOrganization: 'Articles of organization',
        hasPropertyRecords: 'Property records', hasPromotionalMaterials: 'Promotional materials',
        hasEnrollmentDocuments: 'Enrollment documents', hasResearch: 'Academic research',
        hasFinancial: 'Financial reports', hasStudent: 'Student / resident manual',
        hasStaff: 'Staff manual', hasParent: 'Parent manual',
        hasWebsite: 'Archived website', hasNATSAP: 'NATSAP profile',
        hasSurvivorStories: 'Survivor stories', hasOther: 'Other documentation',
        hasVideo: 'Video', hasAudio: 'Audio', hasSocialMedia: 'Social media',
    };

    // locationDetails.formerLocations: {state, city, address, zip, fromYear, toYear}
    const renderFormerLocations = items => {
        if (!Array.isArray(items) || !items.length) return '';
        const lis = items.map(fl => {
            if (!fl || typeof fl !== 'object') return '';
            const stateZip = [fl.state, fl.zip].map(v => String(v || '').trim()).filter(Boolean).join(' ');
            const place = [fl.address, fl.city, stateZip].map(v => String(v || '').trim()).filter(Boolean).join(', ');
            const from = String(fl.fromYear || '').trim();
            const to = String(fl.toYear || '').trim();
            const years = (from && to) ? `${from}–${to}` : from ? `from ${from}` : to ? `until ${to}` : '';
            if (!place && !years) return '';
            return `<li>${escapeHtml(place || 'Location not recorded')}${years ? ` <span class="detail-sub">(${escapeHtml(years)})</span>` : ''}</li>`;
        }).filter(Boolean);
        if (!lis.length) return '';
        return `<div class="detail-row detail-list"><strong>Former locations:</strong><ul>${lis.join('')}</ul></div>`;
    };

    const LAWSUIT_STATUS_LABELS = {
        filed: 'Filed', in_progress: 'In progress', settled: 'Settled',
        dismissed: 'Dismissed', ruling: 'Ruling issued', appeal: 'On appeal',
        closed: 'Closed', unknown: ''
    };

    // Lawsuits attached to the tile by the server (linked_lawsuits[]): the
    // lawsuit_facility_links join plus published cases naming this facility.
    const renderFacilityLawsuitList = items => {
        if (!Array.isArray(items) || !items.length) {
            return '<p class="docs-empty">No lawsuits on record for this facility.</p>';
        }
        return `<ul class="facility-news-list facility-lawsuit-list">${items.map(l => {
            const meta = [];
            if (l.case_number) meta.push(escapeHtml(l.case_number));
            if (l.court) meta.push(escapeHtml(l.court));
            if (l.jurisdiction) meta.push(escapeHtml(l.jurisdiction));
            if (l.filing_date) meta.push(`Filed ${escapeHtml(formatDate(l.filing_date))}`);
            if (LAWSUIT_STATUS_LABELS[l.status]) meta.push(escapeHtml(LAWSUIT_STATUS_LABELS[l.status]));
            if (l.settlement_amount) meta.push(`Settlement: ${escapeHtml(l.settlement_amount)}`);
            const sources = (Array.isArray(l.source_urls) ? l.source_urls : []).filter(Boolean);
            return `<li class="facility-news-item facility-lawsuit-item">
                <span class="facility-news-title">${escapeHtml(l.case_name || '(unnamed case)')}</span>
                ${meta.length ? `<div class="facility-news-meta">${meta.join(' &middot; ')}</div>` : ''}
                ${l.summary ? `<p class="facility-news-summary">${escapeHtml(l.summary)}</p>` : ''}
                ${l.outcome ? `<p class="facility-news-summary"><strong>Outcome:</strong> ${escapeHtml(l.outcome)}</p>` : ''}
                ${sources.length ? `<div class="facility-news-meta">${sources.map((u, i) => `<a href="${escapeHtml(u)}" target="_blank" rel="noopener">Source${sources.length > 1 ? ` ${i + 1}` : ''}</a>`).join(' &middot; ')}</div>` : ''}
            </li>`;
        }).join('')}</ul>`;
    };

    // memorial_victims.date_precision says how much of the date is known.
    const formatMemorialDate = (value, precision) => {
        if (!value) return '';
        const ts = Date.parse(value);
        if (isNaN(ts)) return value;
        const d = new Date(ts);
        if (precision === 'year') return String(d.getUTCFullYear());
        if (precision === 'unknown') return `around ${d.getUTCFullYear()}`;
        if (precision === 'month') return d.toLocaleDateString(undefined, { year: 'numeric', month: 'long', timeZone: 'UTC' });
        return d.toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric', timeZone: 'UTC' });
    };

    // Deaths recorded against this program in the memorial (memorials[]).
    const renderFacilityMemorialList = items => {
        if (!Array.isArray(items) || !items.length) {
            return '<p class="docs-empty">No deaths on record for this facility.</p>';
        }
        return `<ul class="facility-news-list facility-memorial-list">${items.map(m => {
            const meta = [];
            if (m.age !== null && m.age !== undefined && m.age !== '') meta.push(`Age ${escapeHtml(String(m.age))}`);
            const when = formatMemorialDate(m.date_of_death, m.date_precision);
            if (when) meta.push(escapeHtml(when));
            if (m.location) meta.push(escapeHtml(m.location));
            const links = [];
            if (m.source_url) links.push(`<a href="${escapeHtml(m.source_url)}" target="_blank" rel="noopener">${escapeHtml(m.source_name || 'Source')}</a>`);
            if (m.kop_url) links.push(`<a href="${escapeHtml(m.kop_url)}">On Kids Over Profits</a>`);
            return `<li class="facility-news-item facility-memorial-item">
                <span class="facility-news-title">${escapeHtml(m.name || 'Name withheld')}</span>
                ${meta.length ? `<div class="facility-news-meta">${meta.join(' &middot; ')}</div>` : ''}
                ${m.cause_of_death ? `<p class="facility-news-summary">${escapeHtml(m.cause_of_death)}</p>` : ''}
                ${links.length ? `<div class="facility-news-meta">${links.join(' &middot; ')}</div>` : ''}
            </li>`;
        }).join('')}</ul>`;
    };

    // Union of the name-matched state news and the id-linked news the server
    // attaches (news_facility_links), deduped by article id / URL, newest first.
    const mergeNewsItems = (...lists) => {
        const seen = new Set();
        const out = [];
        lists.forEach(list => {
            (Array.isArray(list) ? list : []).forEach(n => {
                if (!n) return;
                const key = n.id ? `id:${n.id}` : (n.article_url ? `url:${String(n.article_url).toLowerCase()}` : '');
                if (key && seen.has(key)) return;
                if (key) seen.add(key);
                out.push(n);
            });
        });
        return out.sort((a, b) => (Date.parse(b.publication_date || '') || 0) - (Date.parse(a.publication_date || '') || 0));
    };

    const facilityCardHtml = facility => {
        const displayName = getFacilityDisplayName(facility);

        // Stats / metadata that go into the collapsible "Details" panel.
        const detailRows = [];

        // Identification & names
        if (facility.current_name && facility.current_name !== facility.name) {
            detailRows.push(renderScalarRow('Current name', facility.current_name));
        }
        detailRows.push(renderListSection('Other names', facility.other_names));
        detailRows.push(renderListSection('Past names', facility.past_names));

        // Operator / ownership
        if (facility.operator_name) {
            detailRows.push(`<div class="detail-row"><strong>Operator:</strong> ${escapeHtml(facility.operator_name)}</div>`);
        }
        detailRows.push(renderListSection('Other operators', facility.other_operators));
        if (facility.current_owner) {
            detailRows.push(renderScalarRow('Current owner', facility.current_owner));
        }
        detailRows.push(renderListSection('Current owners', facility.current_owners));
        detailRows.push(renderListSection('Past owners', facility.past_owners));

        // Facility details
        if (facility.type) {
            detailRows.push(`<div class="detail-row"><strong>Type:</strong> ${escapeHtml(facility.type)}</div>`);
        }
        const ageMin = facility.age_min;
        const ageMax = facility.age_max;
        if (ageMin != null || ageMax != null) {
            const ageText = (ageMin != null && ageMax != null) ? `${ageMin}–${ageMax}`
                : (ageMin != null) ? `${ageMin}+` : `up to ${ageMax}`;
            detailRows.push(renderScalarRow('Age range', ageText));
        }
        if (facility.gender) detailRows.push(renderScalarRow('Gender', facility.gender));
        if (facility.country) detailRows.push(renderScalarRow('Country', facility.country));

        // Licensing record (inspection_facilities): what the licensing authority
        // lists for this program beyond its reports.
        // Utah and Texas store the license number in program_name; a name that
        // is all digits is labelled as the number it is.
        const licensedName = String(facility.licensed_program_name || '').trim();
        detailRows.push(renderScalarRow(/^[\d\s-]+$/.test(licensedName) ? 'License number' : 'Licensed program name', licensedName));
        detailRows.push(renderScalarRow('Executive director (licensing record)', facility.executive_director));
        detailRows.push(renderScalarRow('Phone', facility.phone));
        detailRows.push(renderScalarRow('License expires', facility.license_expiration));
        detailRows.push(renderScalarRow('Relicensing visit', facility.relicense_visit_date));
        detailRows.push(renderScalarRow('License status', facility.licensing_action));
        if (facility.inspected_by) {
            detailRows.push(renderScalarRow('Inspected by', `${facility.inspected_by} licensing authority (out of state)`));
        }

        // Where it operated before moving (locationDetails.formerLocations).
        detailRows.push(renderFormerLocations(facility.former_locations));

        // Operating period notes (the headline yearLabel renders elsewhere)
        detailRows.push(renderListSection('Operational notes', facility.operating_notes));

        // Staff
        detailRows.push(renderListSection('Administrator', facility.administrator));
        detailRows.push(renderListSection('Notable staff', facility.notable_staff));
        detailRows.push(renderListSection('Past TTI employment', facility.past_tti_jobs));

        // Links & referrals
        detailRows.push(renderListSection('Profile links', facility.profile_links));
        detailRows.push(renderListSection('Known referrers', facility.known_referrers));

        // Credentials
        detailRows.push(renderListSection('Current accreditations', facility.accreditations_current));
        detailRows.push(renderListSection('Past accreditations', facility.accreditations_past));
        detailRows.push(renderListSection('Memberships', facility.memberships));
        detailRows.push(renderListSection('Certifications', facility.certifications));
        detailRows.push(renderListSection('Licensing', facility.licensing));

        // Program characteristics (checkbox groups from the admin form)
        detailRows.push(renderFlagChips('Treatment types', facility.treatment_types));
        detailRows.push(renderFlagChips('Philosophy', facility.philosophy_flags));
        detailRows.push(renderFlagChips('Critical incidents', facility.critical_incidents, 'resource-chip incident-chip'));

        // Resources: turn the populated has* flags into chips, plus any details strings
        if (Array.isArray(facility.resource_flags) && facility.resource_flags.length) {
            const chips = facility.resource_flags
                .map(f => RESOURCE_FLAG_LABELS[f] || f.replace(/^has/, ''))
                .map(label => `<span class="resource-chip">${escapeHtml(label)}</span>`)
                .join('');
            detailRows.push(`<div class="detail-row detail-resources"><strong>Resources on file:</strong> ${chips}</div>`);
        }
        const rd = facility.resource_details || {};
        if (rd.newsDetails)          detailRows.push(renderScalarRow('News details', rd.newsDetails));
        if (rd.pressReleasesDetails) detailRows.push(renderScalarRow('Press release details', rd.pressReleasesDetails));
        if (Array.isArray(rd.notes)) detailRows.push(renderListSection('Resource notes', rd.notes));
        else if (rd.notes)           detailRows.push(renderScalarRow('Resource notes', rd.notes));
        detailRows.push(renderListSection('Custom resources', rd.customResources));

        // Facility-level notes
        detailRows.push(renderListSection('Notes', facility.notes));

        // Per-field research notes, keyed by dotted field path
        // (e.g. "treatmentTypes.hasABA" -> "Treatment Types — ABA").
        const fieldNotes = facility.field_notes;
        if (fieldNotes && typeof fieldNotes === 'object' && !Array.isArray(fieldNotes)) {
            const noteItems = [];
            Object.keys(fieldNotes).forEach(key => {
                // Auto-generated "field-<timestamp>" keys carry no readable label.
                const label = /^field-\d/.test(String(key)) ? '' : String(key).split('.').map(humanizeFlagKey).join(' — ');
                const vals = Array.isArray(fieldNotes[key]) ? fieldNotes[key] : [fieldNotes[key]];
                vals.forEach(v => {
                    const text = (v && typeof v === 'object') ? String(v.text || '').trim() : String(v == null ? '' : v).trim();
                    if (text) noteItems.push(`<li>${label ? `<strong>${escapeHtml(label)}:</strong> ` : ''}${escapeHtml(text)}</li>`);
                });
            });
            if (noteItems.length) {
                detailRows.push(`<div class="detail-row detail-list"><strong>Field notes:</strong><ul>${noteItems.join('')}</ul></div>`);
            }
        }

        // Keep the normalized fields above readable, but also expose every
        // non-empty field from the original facility payload. This prevents
        // newer or uncommon form fields from disappearing from state tiles.
        const completeSourceData = renderCompleteSourceData(facility.raw_records);
        if (completeSourceData) detailRows.push(completeSourceData);

        // Inspection / violation summary chips (kept at the bottom of details)
        const statChips = [];
        if (facility.inspection_count > 0) {
            statChips.push(`<span class="stat">${facility.inspection_count} inspection${facility.inspection_count === 1 ? '' : 's'}</span>`);
        }
        if (facility.violation_count > 0) {
            statChips.push(`<span class="stat stat-violation">${facility.violation_count} violation${facility.violation_count === 1 ? '' : 's'}</span>`);
        }
        if (facility.latest_inspection_date) {
            statChips.push(`<span class="stat">Latest inspection: ${escapeHtml(facility.latest_inspection_date)}</span>`);
        }
        if (facility.record_updated_at) {
            statChips.push(`<span class="stat">Record updated: ${escapeHtml(formatDate(String(facility.record_updated_at).slice(0, 10)))}</span>`);
        }
        if (statChips.length) {
            detailRows.push(`<div class="detail-row detail-stats">${statChips.join('')}</div>`);
        }

        // Drop empty strings (renderListSection / renderScalarRow return '' when no data).
        const filteredDetailRows = detailRows.filter(Boolean);
        detailRows.length = 0;
        detailRows.push(...filteredDetailRows);

        const folderId = findFolderForFacility(displayName);
        const hasInspections = Array.isArray(facility.inspections) && facility.inspections.length > 0;
        const hasDetails = detailRows.length > 0;
        const newsItems = mergeNewsItems(findNewsForFacility(displayName), facility.linked_news);
        const hasNews = newsItems.length > 0;
        const lawsuitItems = Array.isArray(facility.linked_lawsuits) ? facility.linked_lawsuits : [];
        const memorialItems = Array.isArray(facility.memorials) ? facility.memorials : [];
        const yearLabel = formatOperatingYears(facility);

        const toggleButtons = [];
        const toggleButton = (panel, count, extraAttrs = '') => {
            const labels = PANEL_LABELS[panel];
            const countAttr = count ? ` data-count="${count}"` : '';
            return `<button type="button" class="facility-expand-btn" data-panel="${panel}"${countAttr}${extraAttrs}>${labels.show}${count ? ` (${count})` : ''}</button>`;
        };
        if (hasDetails) toggleButtons.push(toggleButton('details', 0));
        if (hasInspections) toggleButtons.push(toggleButton('inspections', facility.inspections.length));
        if (hasNews) toggleButtons.push(toggleButton('news', newsItems.length));
        if (lawsuitItems.length) toggleButtons.push(toggleButton('lawsuits', lawsuitItems.length));
        if (memorialItems.length) toggleButtons.push(toggleButton('memorials', memorialItems.length));
        if (folderId) toggleButtons.push(toggleButton('docs', 0, ` data-folder-id="${escapeHtml(String(folderId))}"`));

        const firstLetter = (displayName.match(/[A-Za-z0-9]/) || ['#'])[0].toUpperCase();
        const letterAttr = /[A-Z]/.test(firstLetter) ? firstLetter : '#';
        return `
            <li class="facility-card${(toggleButtons.length ? ' is-expandable' : '')}" data-letter="${letterAttr}" data-kop-bug-feature="country-page/facility-card" data-kop-bug-label="Facility: ${escapeHtml(displayName)}">
                <div class="facility-card-header">
                    <h3 class="facility-card-name">${escapeHtml(displayName)}</h3>
                    ${facility.status ? `<span class="status-pill status-${escapeHtml(String(facility.status).toLowerCase())}">${escapeHtml(facility.status)}</span>` : ''}
                </div>
                ${(facility.relocation && facility.relocation.other_state) ? `
                    <div class="facility-card-relocation facility-card-relocation-${escapeHtml(String(facility.relocation.direction || 'to'))}">
                        ${facility.relocation.direction === 'from'
                            ? `↘ Relocated from ${escapeHtml(facility.relocation.other_state)}`
                            : `↗ Relocated to ${escapeHtml(facility.relocation.other_state)}${facility.relocation.years ? ` (${escapeHtml(facility.relocation.years)})` : ''}`}
                    </div>` : ''}
                ${(() => {
                    const list = Array.isArray(facility.addresses) && facility.addresses.length
                        ? facility.addresses
                        : (facility.address ? [facility.address] : []);
                    if (list.length === 0) {
                        return '<div class="facility-card-address facility-card-address-missing">Address not on file</div>';
                    }
                    if (list.length === 1) {
                        return `<div class="facility-card-address">${escapeHtml(list[0])}</div>`;
                    }
                    return `
                        <ul class="facility-card-address facility-card-address-list">
                            ${list.map(a => `<li>${escapeHtml(a)}</li>`).join('')}
                        </ul>`;
                })()}
                ${yearLabel ? `<div class="facility-card-years">${escapeHtml(yearLabel)}</div>` : ''}
                ${(facility.capacity || facility.census) ? `
                    <div class="facility-card-capacity">
                        ${facility.capacity ? `<span><strong>Capacity:</strong> ${escapeHtml(String(facility.capacity))}</span>` : ''}
                        ${facility.census   ? `<span><strong>Census:</strong> ${escapeHtml(String(facility.census))}</span>`     : ''}
                    </div>` : ''}
                <div class="facility-card-actions">${toggleButtons.join('')}<button type="button" class="kop-submit-info-btn" data-kop-submit-type="facility" data-kop-submit-name="${escapeHtml(displayName)}">Submit info</button></div>
                ${toggleButtons.length ? `
                    <div class="facility-card-panels">
                        ${hasDetails ? `<div class="facility-panel" data-panel="details" hidden>${detailRows.join('')}</div>` : ''}
                        ${hasInspections ? `<div class="facility-panel" data-panel="inspections" hidden>${renderInspectionRecords(facility.inspections)}</div>` : ''}
                        ${hasNews ? `<div class="facility-panel" data-panel="news" hidden>${renderFacilityNewsList(newsItems)}</div>` : ''}
                        ${lawsuitItems.length ? `<div class="facility-panel" data-panel="lawsuits" hidden>${renderFacilityLawsuitList(lawsuitItems)}</div>` : ''}
                        ${memorialItems.length ? `<div class="facility-panel" data-panel="memorials" hidden>${renderFacilityMemorialList(memorialItems)}</div>` : ''}
                        ${folderId ? `<div class="facility-panel" data-panel="docs" data-folder-id="${escapeHtml(String(folderId))}" hidden><p class="loading">Loading documents…</p></div>` : ''}
                    </div>
                ` : ''}
            </li>
        `;
    };

    const PANEL_LABELS = {
        details:     { show: 'Show details',     hide: 'Hide details' },
        inspections: { show: 'Show inspections', hide: 'Hide inspections' },
        news:        { show: 'Show news',        hide: 'Hide news' },
        lawsuits:    { show: 'Show lawsuits',    hide: 'Hide lawsuits' },
        memorials:   { show: 'Deaths on record', hide: 'Hide deaths on record' },
        docs:        { show: 'Show documents',   hide: 'Hide documents' },
    };

    const wireFacilityToggles = container => {
        container.querySelectorAll('.facility-expand-btn').forEach(btn => {
            btn.addEventListener('click', async () => {
                const card = btn.closest('.facility-card');
                if (!card) return;
                const panelKind = btn.dataset.panel;
                const panel = card.querySelector(`.facility-panel[data-panel="${panelKind}"]`);
                if (!panel) return;

                const labels = PANEL_LABELS[panelKind] || { show: 'Show', hide: 'Hide' };
                const count = btn.dataset.count ? ` (${btn.dataset.count})` : '';

                if (panel.hidden) {
                    panel.hidden = false;
                    btn.textContent = labels.hide + count;
                } else {
                    panel.hidden = true;
                    btn.textContent = labels.show + count;
                    return;
                }

                if (panelKind === 'docs' && panel.dataset.loaded !== '1') {
                    const folderId = panel.dataset.folderId || btn.dataset.folderId;
                    btn.disabled = true;
                    const tree = await fetchFacilityDocTree(folderId);
                    panel.innerHTML = renderDocTree(tree);
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
            container.innerHTML = '<p class="empty">No facilities on file for this country yet.</p>';
            return;
        }

        const inspectionsLink = inspections.has_reports
            ? `<a href="${escapeHtml(inspections.page_url)}" class="full-page-link">View full inspection report search →</a>`
            : '';

        const consolidatedActive = (facilities.active || []).filter(facility => !isLikelyBrokenFacilityRecord(facility));
        const consolidatedClosed = (facilities.closed || []).filter(facility => !isLikelyBrokenFacilityRecord(facility));

        const activeHtml = consolidatedActive.length
            ? `<ul class="facility-list">${consolidatedActive.map(facilityCardHtml).join('')}</ul>`
            : '<p class="empty">No active facilities listed.</p>';

        const closedHtml = consolidatedClosed.length
            ? `<ul class="facility-list closed-list">${consolidatedClosed.map(facilityCardHtml).join('')}</ul>`
            : '';

        // Build the alphabet jump-list from letters that actually appear in the
        // facility list. Letters with no facilities are still rendered but greyed
        // out, matching the state-reports page convention.
        const presentLetters = new Set();
        [...consolidatedActive, ...consolidatedClosed].forEach(f => {
            const name = getFacilityDisplayName(f);
            const first = (name.match(/[A-Za-z0-9]/) || ['#'])[0].toUpperCase();
            presentLetters.add(/[A-Z]/.test(first) ? first : '#');
        });
        const alphabetLetters = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ'.split('');
        const alphabetHtml = `
            <nav class="facility-alphabet-filter" aria-label="Jump to letter">
                <a href="#" class="active" data-letter="ALL">All</a>
                ${alphabetLetters.map(l => `
                    <a href="#" data-letter="${l}"${presentLetters.has(l) ? '' : ' class="empty" aria-disabled="true"'}>${l}</a>
                `).join('')}
                ${presentLetters.has('#') ? `<a href="#" data-letter="#">#</a>` : ''}
            </nav>
        `;

        container.innerHTML = `
            <div class="facility-toolbar">
                <input type="text" class="section-search" id="facilitySearch" placeholder="Search facilities...">
                ${inspectionsLink}
            </div>

            ${alphabetHtml}

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
        const alphabetNav = container.querySelector('.facility-alphabet-filter');

        const refreshGroupVisibility = () => {
            container.querySelectorAll('.facility-group').forEach(group => {
                const visible = group.querySelectorAll('.facility-card:not([style*="display: none"])').length;
                group.style.display = visible === 0 ? 'none' : '';
            });
        };

        const applyFilter = (letter, term) => {
            const normalizedTerm = (term || '').trim().toLowerCase();
            container.querySelectorAll('.facility-card').forEach(card => {
                const matchesLetter = letter === 'ALL' || card.dataset.letter === letter;
                const matchesTerm = !normalizedTerm || card.textContent.toLowerCase().includes(normalizedTerm);
                card.style.display = (matchesLetter && matchesTerm) ? '' : 'none';
            });
            refreshGroupVisibility();
        };

        let currentLetter = 'ALL';

        search.addEventListener('input', () => {
            applyFilter(currentLetter, search.value);
        });

        alphabetNav.addEventListener('click', e => {
            const link = e.target.closest('a[data-letter]');
            if (!link) return;
            e.preventDefault();
            if (link.classList.contains('empty')) return;
            currentLetter = link.dataset.letter;
            alphabetNav.querySelectorAll('a').forEach(a => {
                a.classList.toggle('active', a === link);
            });
            applyFilter(currentLetter, search.value);
        });
    };

    // ---------- News ----------
    const submitNewsButton = () => {
        if (!config.newsSubmitUrl) return '';
        const url = `${config.newsSubmitUrl}${config.newsSubmitUrl.includes('?') ? '&' : '?'}prefill_country=${encodeURIComponent(config.stateName)}`;
        return `<a href="${escapeHtml(url)}" class="submit-news-btn">+ Submit news for ${escapeHtml(config.stateName)}</a>`;
    };

    const renderNews = () => {
        const container = document.querySelector('#section-news .section-content');
        const news = state.data.news || [];
        const submitBtn = submitNewsButton();
        if (news.length === 0) {
            container.innerHTML = `
                <p class="empty">No news items tagged for this country.</p>
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
            container.innerHTML = '<p class="empty">No lawsuits on record for this country yet.</p>';
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
            container.innerHTML = '<p class="empty">No legislation on record for this country yet.</p>';
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
