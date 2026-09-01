/**
 * KOP "Submit info" mini-form.
 *
 * Any page can render a button like:
 *   <button type="button" class="kop-submit-info-btn"
 *           data-kop-submit-type="facility|operator|transporter|referrer"
 *           data-kop-submit-name="Entry Name">✏️ Submit info</button>
 * This module (loaded once per page) catches the click, opens a modal with
 * only the fields that apply to that entry type, and posts the result to the
 * public suggestion pipeline (api/save-suggestion.php → suggested_edits),
 * where it waits for admin approval like any other public submission.
 */
(function () {
    'use strict';

    if (window.KOPSubmitInfo) return; // double-include guard

    var API_URL = '/wp-content/themes/child/api/save-suggestion.php';

    var esc = function (v) {
        return String(v == null ? '' : v).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c] || c;
        });
    };

    // "a, b; c" → ["a","b","c"] — the mini form takes lists as comma/semicolon-
    // separated text so it stays a quick single-input affair.
    var splitList = function (v) {
        return String(v || '').split(/[,;\n]+/).map(function (s) { return s.trim(); }).filter(Boolean);
    };

    // "role — name" pairs for staff-ish lists: keep as plain strings; the
    // review/approval step decides how to structure them.
    var FIELD_SETS = {
        facility: {
            heading: 'facility',
            category: 'companies',
            fields: [
                { id: 'currentName', label: 'Current name', hint: 'If it now operates under a different name' },
                { id: 'otherNames', label: 'Other / past names', hint: 'Separate with commas' },
                { id: 'operator', label: 'Operator / parent company' },
                { id: 'owners', label: 'Owner(s)', hint: 'Separate with commas' },
                { id: 'status', label: 'Status', hint: 'e.g. Open, Closed' },
                { id: 'years', label: 'Years of operation', hint: 'e.g. 1994–2010' },
                { id: 'type', label: 'Program type', hint: 'e.g. RTC, wilderness program' },
                { id: 'ages', label: 'Age range', hint: 'e.g. 12–17' },
                { id: 'gender', label: 'Gender served' },
                { id: 'capacity', label: 'Capacity' },
                { id: 'address', label: 'Address' },
                { id: 'links', label: 'Website / profile links', hint: 'Separate with commas' },
                { id: 'staff', label: 'Notable staff', hint: 'e.g. Jane Doe — Director. Separate with commas' },
                { id: 'referrers', label: 'Known referrers', hint: 'Separate with commas' },
                { id: 'notes', label: 'Anything else', textarea: true }
            ],
            build: function (v, name) {
                var ages = String(v.ages || '');
                var m = ages.match(/(\d+)\s*(?:[-–—]|to)\s*(\d+)/);
                var ageRange = m ? { min: parseInt(m[1], 10), max: parseInt(m[2], 10) } : { min: null, max: null };
                var notes = v.notes ? [v.notes] : [];
                if (!m && ages.trim()) notes.push('Ages: ' + ages.trim());
                return {
                    facilities: [{
                        identification: {
                            name: name,
                            currentName: v.currentName || '',
                            currentOperator: v.operator || '',
                            currentOwners: splitList(v.owners),
                            otherNames: splitList(v.otherNames),
                            knownReferrers: splitList(v.referrers)
                        },
                        operatingPeriod: { status: v.status || '', yearsOfOperation: v.years || '' },
                        facilityDetails: {
                            type: v.type || '',
                            capacity: v.capacity || null,
                            gender: v.gender || '',
                            ageRange: ageRange
                        },
                        address: v.address || '',
                        profileLinks: splitList(v.links),
                        staff: { notableStaff: splitList(v.staff) },
                        notes: notes
                    }]
                };
            }
        },
        operator: {
            heading: 'operator / company',
            category: 'companies',
            fields: [
                { id: 'founded', label: 'Founded', hint: 'Year' },
                { id: 'status', label: 'Status', hint: 'e.g. Active, Defunct' },
                { id: 'headquarters', label: 'Headquarters', hint: 'City, State' },
                { id: 'parentCompanies', label: 'Parent companies', hint: 'Separate with commas' },
                { id: 'investors', label: 'Investors', hint: 'Separate with commas' },
                { id: 'owners', label: 'Owners', hint: 'Separate with commas' },
                { id: 'websites', label: 'Websites', hint: 'Separate with commas' },
                { id: 'ceo', label: 'CEO' },
                { id: 'founders', label: 'Founders', hint: 'Separate with commas' },
                { id: 'keyExecutives', label: 'Key executives', hint: 'e.g. Jane Doe — CFO. Separate with commas' },
                { id: 'notes', label: 'Anything else', textarea: true }
            ],
            build: function (v, name) {
                return {
                    operator: {
                        name: name,
                        founded: v.founded || '',
                        status: v.status || '',
                        headquarters: v.headquarters || '',
                        parentCompanies: splitList(v.parentCompanies),
                        investors: splitList(v.investors),
                        owners: splitList(v.owners),
                        websites: splitList(v.websites),
                        keyStaff: {
                            ceo: v.ceo || '',
                            founders: splitList(v.founders),
                            keyExecutives: splitList(v.keyExecutives)
                        },
                        notes: v.notes ? [v.notes] : []
                    }
                };
            }
        },
        transporter: {
            heading: 'transporter',
            category: 'transporters',
            fields: [
                { id: 'individualName', label: 'Transporter name', hint: 'Person, if reporting an individual' },
                { id: 'individualRole', label: 'Their role' },
                { id: 'serviceAreas', label: 'Service areas', hint: 'Separate with commas' },
                { id: 'vehicleTypes', label: 'Vehicle types', hint: 'Separate with commas' },
                { id: 'pickupMethods', label: 'Pickup methods', hint: 'Separate with commas' },
                { id: 'restraintPractices', label: 'Restraint practices', hint: 'Separate with commas' },
                { id: 'licensing', label: 'Licensing', hint: 'Separate with commas' },
                { id: 'affiliations', label: 'Affiliations', hint: 'Separate with commas' },
                { id: 'knownFacilities', label: 'Facilities transported to', hint: 'Separate with commas' },
                { id: 'lawsuits', label: 'Lawsuits', hint: 'Separate with commas' },
                { id: 'websites', label: 'Websites', hint: 'Separate with commas' },
                { id: 'notes', label: 'Anything else', textarea: true }
            ],
            build: function (v, name) {
                var individuals = [];
                var indName = String(v.individualName || '').trim();
                if (indName) {
                    var parts = indName.split(/\s+/);
                    individuals.push({
                        firstName: parts.shift() || '',
                        lastName: parts.join(' '),
                        role: v.individualRole || '',
                        affiliations: [], pastTTIJobs: [], affiliatedCompanies: [name]
                    });
                }
                return {
                    transporterCompany: {
                        name: name,
                        serviceAreas: splitList(v.serviceAreas),
                        vehicleTypes: splitList(v.vehicleTypes),
                        pickupMethods: splitList(v.pickupMethods),
                        restraintPractices: splitList(v.restraintPractices),
                        licensing: splitList(v.licensing),
                        affiliations: splitList(v.affiliations),
                        knownFacilities: splitList(v.knownFacilities),
                        lawsuits: splitList(v.lawsuits),
                        websites: splitList(v.websites),
                        notes: v.notes || ''
                    },
                    transporters: individuals
                };
            }
        },
        referrer: {
            heading: 'referrer / consultant',
            category: 'referrers',
            fields: [
                { id: 'consultantName', label: 'Consultant name', hint: 'Person, if reporting an individual' },
                { id: 'role', label: 'Their role', hint: 'e.g. Educational Consultant' },
                { id: 'affiliations', label: 'Affiliations', hint: 'Separate with commas' },
                { id: 'referrals', label: 'Facilities they refer to', hint: 'Separate with commas' },
                { id: 'schoolDistricts', label: 'School districts', hint: 'Separate with commas' },
                { id: 'career', label: 'Career history', hint: 'e.g. Therapist at Provo Canyon. Separate with commas' },
                { id: 'websites', label: 'Websites', hint: 'Separate with commas' },
                { id: 'notes', label: 'Anything else', textarea: true }
            ],
            build: function (v, name) {
                var consultants = [];
                var cName = String(v.consultantName || '').trim() || name;
                var parts = cName.split(/\s+/);
                consultants.push({
                    firstName: parts.shift() || '',
                    lastName: parts.join(' '),
                    role: v.role || '',
                    affiliations: splitList(v.affiliations),
                    knownReferrals: splitList(v.referrals),
                    facilitiesReferred: splitList(v.referrals),
                    schoolDistricts: splitList(v.schoolDistricts),
                    pastTTIJobs: splitList(v.career),
                    notes: v.notes || ''
                });
                var data = { referrerConsultants: consultants };
                // Keep the directory entry name attached when it names an
                // agency/group rather than the person being described.
                if (String(v.consultantName || '').trim() && cName.toLowerCase() !== name.toLowerCase()) {
                    data.referrerAgency = { name: name, websites: splitList(v.websites) };
                } else if (splitList(v.websites).length) {
                    consultants[0].websites = splitList(v.websites);
                }
                return data;
            }
        }
    };

    var STYLE = [
        '.kop-submit-info-row{margin-top:12px;text-align:right;}',
        '.kop-submit-info-btn{display:inline-block;background:#fff;color:#000080;border:1px solid #000080;border-radius:999px;',
        'padding:0.3em 0.9em;font-size:0.85em;cursor:pointer;line-height:1.4;}',
        '.kop-submit-info-btn:hover{background:#000080;color:#fff;}',
        '.kop-si-overlay{position:fixed;inset:0;background:rgba(0,4,53,0.55);z-index:100000;display:flex;align-items:center;justify-content:center;padding:20px;}',
        '.kop-si-modal{background:#fff;color:#1f2937;border-radius:12px;max-width:560px;width:100%;max-height:88vh;display:flex;flex-direction:column;box-shadow:0 12px 40px rgba(0,0,0,0.35);}',
        '.kop-si-header{background:#000435;color:#fff;border-radius:12px 12px 0 0;padding:14px 18px;display:flex;justify-content:space-between;align-items:center;gap:12px;}',
        '.kop-si-header h3{margin:0;font-size:1.05em;color:#fff;}',
        '.kop-si-close{background:none;border:none;color:#fff;font-size:1.5em;line-height:1;cursor:pointer;padding:0 4px;}',
        '.kop-si-body{padding:16px 18px;overflow-y:auto;}',
        '.kop-si-intro{margin:0 0 12px;font-size:0.88em;color:#4b5563;}',
        '.kop-si-field{margin-bottom:10px;}',
        '.kop-si-field label{display:block;font-weight:600;font-size:0.85em;margin-bottom:2px;color:#000435;}',
        '.kop-si-field .kop-si-hint{font-weight:400;color:#6b7280;font-size:0.92em;}',
        '.kop-si-field input,.kop-si-field textarea{width:100%;box-sizing:border-box;border:1px solid #d1d5db;border-radius:6px;padding:6px 8px;font-size:0.92em;font-family:inherit;background:#fff;color:#1f2937;}',
        '.kop-si-field textarea{min-height:70px;resize:vertical;}',
        '.kop-si-footer{padding:12px 18px;border-top:1px solid #e5e7eb;display:flex;justify-content:flex-end;gap:10px;align-items:center;}',
        '.kop-si-status{margin-right:auto;font-size:0.85em;}',
        '.kop-si-status.error{color:#b91c1c;}',
        '.kop-si-status.ok{color:#166534;}',
        '.kop-si-submit{background:#000080;color:#fff;border:none;border-radius:6px;padding:8px 18px;font-size:0.92em;cursor:pointer;}',
        '.kop-si-submit:disabled{opacity:0.6;cursor:default;}',
        '.kop-si-cancel{background:none;border:1px solid #d1d5db;border-radius:6px;padding:8px 14px;font-size:0.92em;cursor:pointer;color:#374151;}'
    ].join('');

    function injectStyle() {
        if (document.getElementById('kop-submit-info-style')) return;
        var el = document.createElement('style');
        el.id = 'kop-submit-info-style';
        el.textContent = STYLE;
        document.head.appendChild(el);
    }

    // Style the buttons as soon as the module loads, not on first click.
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', injectStyle);
    } else {
        injectStyle();
    }

    var overlay = null;

    function close() {
        if (overlay && overlay.parentNode) overlay.parentNode.removeChild(overlay);
        overlay = null;
        document.removeEventListener('keydown', onKey);
    }

    function onKey(e) {
        if (e.key === 'Escape') close();
    }

    function open(type, name) {
        var set = FIELD_SETS[type];
        if (!set || !name) return;
        injectStyle();
        close();

        var fieldsHtml = set.fields.map(function (f) {
            var input = f.textarea
                ? '<textarea data-kop-si-field="' + esc(f.id) + '"></textarea>'
                : '<input type="text" data-kop-si-field="' + esc(f.id) + '">';
            return '<div class="kop-si-field"><label>' + esc(f.label) +
                (f.hint ? ' <span class="kop-si-hint">— ' + esc(f.hint) + '</span>' : '') +
                '</label>' + input + '</div>';
        }).join('');

        overlay = document.createElement('div');
        overlay.className = 'kop-si-overlay';
        overlay.innerHTML =
            '<div class="kop-si-modal" role="dialog" aria-modal="true" aria-label="Submit information">' +
                '<div class="kop-si-header"><h3>Submit info: ' + esc(name) + '</h3>' +
                '<button type="button" class="kop-si-close" aria-label="Close">&times;</button></div>' +
                '<div class="kop-si-body">' +
                    '<p class="kop-si-intro">Know something about this ' + esc(set.heading) + '? ' +
                    'Fill in whatever you can — every field is optional, and submissions are reviewed before publishing.</p>' +
                    fieldsHtml +
                    '<div class="kop-si-field"><label>Sources <span class="kop-si-hint">— links or where this info comes from</span></label>' +
                    '<textarea data-kop-si-field="__sources"></textarea></div>' +
                '</div>' +
                '<div class="kop-si-footer">' +
                    '<span class="kop-si-status"></span>' +
                    '<button type="button" class="kop-si-cancel">Cancel</button>' +
                    '<button type="button" class="kop-si-submit">Submit for review</button>' +
                '</div>' +
            '</div>';
        document.body.appendChild(overlay);
        document.addEventListener('keydown', onKey);

        overlay.addEventListener('click', function (e) { if (e.target === overlay) close(); });
        overlay.querySelector('.kop-si-close').addEventListener('click', close);
        overlay.querySelector('.kop-si-cancel').addEventListener('click', close);

        var statusEl = overlay.querySelector('.kop-si-status');
        var submitBtn = overlay.querySelector('.kop-si-submit');

        submitBtn.addEventListener('click', function () {
            var values = {};
            overlay.querySelectorAll('[data-kop-si-field]').forEach(function (el) {
                values[el.getAttribute('data-kop-si-field')] = el.value.trim();
            });
            var sources = values.__sources || '';
            delete values.__sources;

            var hasContent = Object.keys(values).some(function (k) { return values[k] !== ''; });
            if (!hasContent && !sources) {
                statusEl.textContent = 'Please fill in at least one field.';
                statusEl.className = 'kop-si-status error';
                return;
            }

            var data = set.build(values, name);
            data.name = name;

            var reason = 'Public mini-form submission (' + type + ') for "' + name + '" from ' +
                window.location.pathname + (sources ? '. Sources: ' + sources : '');

            submitBtn.disabled = true;
            statusEl.textContent = 'Submitting…';
            statusEl.className = 'kop-si-status';

            fetch(API_URL, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    projectName: name,
                    data: data,
                    reason: reason,
                    metadata: {
                        activeCategory: set.category,
                        actualProjectName: name,
                        source: 'submit-info-mini-form',
                        page: window.location.href
                    }
                })
            }).then(function (res) { return res.json().catch(function () { return {}; }); })
              .then(function (res) {
                if (res && res.success) {
                    statusEl.textContent = 'Thank you! Your submission is in the review queue.';
                    statusEl.className = 'kop-si-status ok';
                    setTimeout(close, 2200);
                } else {
                    submitBtn.disabled = false;
                    statusEl.textContent = (res && res.error) ? res.error : 'Submission failed — please try again.';
                    statusEl.className = 'kop-si-status error';
                }
            }).catch(function () {
                submitBtn.disabled = false;
                statusEl.textContent = 'Network error — please try again.';
                statusEl.className = 'kop-si-status error';
            });
        });
    }

    // Delegated handler: works for buttons rendered at any time by any script.
    document.addEventListener('click', function (e) {
        var btn = e.target.closest ? e.target.closest('.kop-submit-info-btn') : null;
        if (!btn) return;
        e.preventDefault();
        e.stopPropagation(); // don't toggle the surrounding <details>/card
        open(btn.getAttribute('data-kop-submit-type') || 'facility',
             btn.getAttribute('data-kop-submit-name') || '');
    });

    window.KOPSubmitInfo = { open: open };
})();
