<?php
/**
 * Template Name: Submit Lawsuit
 *
 * Public form for submitting a TTI-related court case to the Lawsuits tracker.
 * Submissions are stored as "pending" and reviewed by an admin in Submissions
 * Review before they appear on the public tracker — the same workflow as wiki
 * entries and data-form suggestions.
 */

if (!defined('ABSPATH')) { exit; }

get_header();

$us_states = ['Alabama','Alaska','Arizona','Arkansas','California','Colorado','Connecticut','Delaware','Florida','Georgia','Hawaii','Idaho','Illinois','Indiana','Iowa','Kansas','Kentucky','Louisiana','Maine','Maryland','Massachusetts','Michigan','Minnesota','Mississippi','Missouri','Montana','Nebraska','Nevada','New Hampshire','New Jersey','New Mexico','New York','North Carolina','North Dakota','Ohio','Oklahoma','Oregon','Pennsylvania','Rhode Island','South Carolina','South Dakota','Tennessee','Texas','Utah','Vermont','Virginia','Washington','West Virginia','Wisconsin','Wyoming'];

$status_labels = [
    'filed'       => 'Filed',
    'in_progress' => 'In progress',
    'settled'     => 'Settled',
    'dismissed'   => 'Dismissed',
    'ruling'      => 'Ruling issued',
    'appeal'      => 'On appeal',
    'closed'      => 'Closed',
    'unknown'     => 'Not sure',
];

$endpoint         = get_stylesheet_directory_uri() . '/api/save-lawsuit-suggestion.php';
$extract_endpoint = get_stylesheet_directory_uri() . '/api/extract-lawsuit-suggestion.php';
$lookup_endpoint  = get_stylesheet_directory_uri() . '/api/fetch-lawsuit-details.php';

// Resolve the public tracker page by template (falls back to the conventional slug).
$tracker_url = function_exists('kop_find_template_page_url') ? kop_find_template_page_url('page-lawsuits.php') : '';
if (!$tracker_url) { $tracker_url = home_url('/lawsuits'); }
?>

<div class="kop-records-page kop-submit-page">
    <div class="kop-records-header">
        <h1>Submit a Lawsuit</h1>
        <p>Know of a court case involving a Troubled Teen Industry facility, operator, or staff member? Share it here. Submissions are reviewed by our team before they appear on the public <a href="<?php echo esc_url($tracker_url); ?>">Lawsuits tracker</a>. Share only what you're comfortable with — a case name and a source link are enough to get started.</p>
    </div>

    <div class="kop-autofill-box" id="kop-autofill-box">
        <h2>Let us fill this in for you <span class="hint">(optional)</span></h2>
        <p>Upload the complaint or another court filing (PDF, DOCX, or TXT — digital, not a scanned image) and we'll read it and fill in the form below. Or, for federal cases, enter the docket number and we'll look it up. Either way, review the filled-in fields before submitting.</p>
        <div class="kop-autofill-actions">
            <button type="button" class="kop-submit-btn" id="kop-autofill-upload-btn">Upload a case document</button>
            <input type="file" id="kop-autofill-file" accept=".pdf,.doc,.docx,.txt" hidden>
            <span class="kop-autofill-or">or</span>
            <input type="text" id="kop-autofill-docket" placeholder="Federal docket no., e.g. 2:24-cv-00123" maxlength="255">
            <button type="button" class="kop-submit-btn" id="kop-autofill-lookup-btn">Look up case</button>
        </div>
        <div id="kop-autofill-status" class="kop-submit-status" role="status" aria-live="polite"></div>
    </div>

    <form id="kop-submit-lawsuit-form" class="kop-submit-form" novalidate data-kop-bug-feature="submit-lawsuit/form" data-kop-bug-label="Lawsuit Submission Form">
        <!-- Extra extracted fields the public form doesn't show; carried through
             to the submission so reviewers get the full extraction. -->
        <input type="hidden" name="staff_mentioned">
        <input type="hidden" name="organizations_mentioned">
        <input type="hidden" name="tags">
        <input type="hidden" name="document_urls">
        <div class="kop-form-grid">
            <label class="kop-field kop-field-wide">
                <span class="kop-field-label">Case name or short description <span class="req">*</span></span>
                <input type="text" name="case_name" required maxlength="500" placeholder="e.g. Doe v. Example Academy">
            </label>

            <label class="kop-field">
                <span class="kop-field-label">Case / docket number</span>
                <input type="text" name="case_number" maxlength="255" placeholder="e.g. 2:24-cv-00123">
            </label>

            <label class="kop-field">
                <span class="kop-field-label">Court</span>
                <input type="text" name="court" maxlength="255" placeholder="e.g. US District Court, D. Utah">
            </label>

            <label class="kop-field">
                <span class="kop-field-label">Jurisdiction</span>
                <select name="jurisdiction">
                    <option value="">— Select —</option>
                    <option value="Federal">Federal</option>
                    <optgroup label="US States">
                        <?php foreach ($us_states as $state): ?>
                            <option value="<?php echo esc_attr($state); ?>"><?php echo esc_html($state); ?></option>
                        <?php endforeach; ?>
                    </optgroup>
                </select>
            </label>

            <label class="kop-field">
                <span class="kop-field-label">Filing date</span>
                <input type="date" name="filing_date">
            </label>

            <label class="kop-field">
                <span class="kop-field-label">Current status</span>
                <select name="status">
                    <?php foreach ($status_labels as $val => $label): ?>
                        <option value="<?php echo esc_attr($val); ?>" <?php selected($val, 'unknown'); ?>><?php echo esc_html($label); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label class="kop-field kop-field-wide">
                <span class="kop-field-label">Facilities / programs mentioned <span class="hint">(one per line)</span></span>
                <textarea name="facilities_mentioned" rows="2"></textarea>
            </label>

            <label class="kop-field">
                <span class="kop-field-label">Plaintiffs <span class="hint">(one per line)</span></span>
                <textarea name="plaintiffs" rows="2"></textarea>
            </label>

            <label class="kop-field">
                <span class="kop-field-label">Defendants <span class="hint">(one per line)</span></span>
                <textarea name="defendants" rows="2"></textarea>
            </label>

            <label class="kop-field kop-field-wide">
                <span class="kop-field-label">Claims <span class="hint">(one per line — e.g. physical abuse, wrongful death, fraud)</span></span>
                <textarea name="claims" rows="2"></textarea>
            </label>

            <label class="kop-field kop-field-wide">
                <span class="kop-field-label">Summary</span>
                <textarea name="summary" rows="4" placeholder="A brief, factual description of the case."></textarea>
            </label>

            <label class="kop-field">
                <span class="kop-field-label">Outcome / disposition</span>
                <input type="text" name="outcome" placeholder="e.g. Settled out of court">
            </label>

            <label class="kop-field">
                <span class="kop-field-label">Settlement amount</span>
                <input type="text" name="settlement_amount" placeholder="e.g. $1.5 million">
            </label>

            <label class="kop-field kop-field-wide">
                <span class="kop-field-label">Source URLs <span class="hint">(one per line — news, court records)</span></span>
                <textarea name="source_urls" rows="2" placeholder="https://..."></textarea>
            </label>
        </div>

        <fieldset class="kop-submitter-box">
            <legend>About you <span class="hint">(optional)</span></legend>
            <div class="kop-form-grid">
                <label class="kop-field">
                    <span class="kop-field-label">Your name or email</span>
                    <input type="text" name="submitted_by" maxlength="255" placeholder="So we can follow up if needed">
                </label>
                <label class="kop-field kop-field-wide">
                    <span class="kop-field-label">Notes for our reviewer</span>
                    <textarea name="notes" rows="2" placeholder="Anything else we should know?"></textarea>
                </label>
            </div>
            <!-- Honeypot: leave empty. Hidden from people, tempting to bots. -->
            <div class="kop-hp" aria-hidden="true">
                <label>Website<input type="text" name="website_hp" tabindex="-1" autocomplete="off"></label>
            </div>
        </fieldset>

        <div class="kop-submit-actions">
            <button type="submit" class="kop-submit-btn">Submit for review</button>
            <span id="kop-submit-status" class="kop-submit-status" role="status" aria-live="polite"></span>
        </div>
    </form>

    <div id="kop-submit-thanks" class="kop-submit-thanks" hidden>
        <h2>Thank you!</h2>
        <p>Your submission has been received and will be reviewed before it appears on the tracker.</p>
        <p><button type="button" class="kop-submit-btn" id="kop-submit-another">Submit another</button></p>
    </div>
</div>

<link rel="stylesheet" href="<?php echo get_stylesheet_directory_uri(); ?>/css/public-records.css?v=<?php echo filemtime(get_stylesheet_directory() . '/css/public-records.css'); ?>">
<style>
.kop-submit-form { max-width: 880px; margin: 0 auto; }
.kop-autofill-box { max-width: 880px; margin: 0 auto 1.75rem; padding: 1rem 1.25rem 1.25rem; border: 1px solid var(--kop-mint-green, #b6e3d4); border-radius: 8px; background: #f3fbf8; }
.kop-autofill-box h2 { margin: 0 0 0.5rem; font-size: 1.15em; color: var(--kop-midnight-blue); }
.kop-autofill-box h2 .hint { font-weight: 400; color: #666; font-size: 0.8em; }
.kop-autofill-box p { margin: 0 0 0.85rem; }
.kop-autofill-actions { display: flex; flex-wrap: wrap; align-items: center; gap: 0.75rem; }
.kop-autofill-actions input[type="text"] { flex: 1 1 220px; padding: 0.55rem 0.65rem; border: 1px solid #c8c8c8; border-radius: 6px; font: inherit; background: #fff; box-sizing: border-box; }
.kop-autofill-actions input[type="text"]:focus { outline: 2px solid var(--kop-teal); border-color: var(--kop-teal); }
.kop-autofill-or { color: #666; font-weight: 600; }
#kop-autofill-status { display: block; margin-top: 0.6rem; min-height: 1.2em; }
.kop-form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem 1.25rem; }
.kop-field { display: flex; flex-direction: column; gap: 0.35rem; }
.kop-field-wide { grid-column: 1 / -1; }
.kop-field-label { font-weight: 600; color: var(--kop-midnight-blue); font-size: 0.95em; }
.kop-field .hint { font-weight: 400; color: #666; font-size: 0.85em; }
.kop-field .req { color: var(--kop-coral-pink, #d6336c); }
.kop-submit-form input,
.kop-submit-form select,
.kop-submit-form textarea {
    padding: 0.55rem 0.65rem; border: 1px solid #c8c8c8; border-radius: 6px;
    font: inherit; background: #fff; width: 100%; box-sizing: border-box;
}
.kop-submit-form textarea { resize: vertical; }
.kop-submit-form input:focus,
.kop-submit-form select:focus,
.kop-submit-form textarea:focus { outline: 2px solid var(--kop-teal); border-color: var(--kop-teal); }
.kop-submitter-box { margin-top: 1.5rem; border: 1px solid #e0ddcf; border-radius: 8px; padding: 1rem 1.25rem 1.25rem; background: var(--kop-sand, #f2eedf); }
.kop-submitter-box legend { font-weight: 700; color: var(--kop-midnight-blue); padding: 0 0.4rem; }
.kop-hp { position: absolute; left: -9999px; height: 0; overflow: hidden; }
.kop-submit-actions { display: flex; align-items: center; gap: 1rem; margin-top: 1.5rem; }
.kop-submit-btn {
    background: var(--kop-teal); color: #fff; border: none; border-radius: 6px;
    padding: 0.7rem 1.5rem; font-weight: 700; font-size: 1em; cursor: pointer;
}
.kop-submit-btn:hover { background: var(--kop-navy, #000080); }
.kop-submit-btn:disabled { opacity: 0.6; cursor: default; }
.kop-submit-status.error { color: #b00020; font-weight: 600; }
.kop-submit-status.ok { color: #1b7e3c; font-weight: 600; }
.kop-submit-thanks { max-width: 880px; margin: 2rem auto 0; padding: 1.5rem; border: 1px solid var(--kop-mint-green, #b6e3d4); border-radius: 8px; background: #f3fbf8; }
@media (max-width: 640px) { .kop-form-grid { grid-template-columns: 1fr; } }
</style>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var form     = document.getElementById('kop-submit-lawsuit-form');
    var statusEl = document.getElementById('kop-submit-status');
    var thanks   = document.getElementById('kop-submit-thanks');
    var endpoint = <?php echo wp_json_encode($endpoint); ?>;
    var extractEndpoint = <?php echo wp_json_encode($extract_endpoint); ?>;
    var lookupEndpoint  = <?php echo wp_json_encode($lookup_endpoint); ?>;
    if (!form) return;

    // ----- Autofill: document extraction + federal docket lookup -----------
    var uploadBtn      = document.getElementById('kop-autofill-upload-btn');
    var fileInput      = document.getElementById('kop-autofill-file');
    var docketInput    = document.getElementById('kop-autofill-docket');
    var lookupBtn      = document.getElementById('kop-autofill-lookup-btn');
    var autofillStatus = document.getElementById('kop-autofill-status');

    function setAutofillBusy(busy) {
        if (uploadBtn) uploadBtn.disabled = busy;
        if (lookupBtn) lookupBtn.disabled = busy;
    }
    function autofillMsg(msg, cls) {
        autofillStatus.textContent = msg;
        autofillStatus.className = 'kop-submit-status' + (cls ? ' ' + cls : '');
    }

    // Fill one field from an extracted value. Selects only accept known option
    // values; arrays become one-per-line text. Empty extractions never wipe
    // what the visitor already typed.
    function setIfExtracted(name, value) {
        var el = form.elements[name];
        if (!el || value === undefined || value === null) return;
        var v = Array.isArray(value)
            ? value.map(function (x) { return String(x).trim(); }).filter(Boolean).join('\n')
            : String(value).trim();
        if (v === '') return;
        if (el.tagName === 'SELECT') {
            for (var i = 0; i < el.options.length; i++) {
                if (el.options[i].value === v) { el.value = v; return; }
            }
            return;
        }
        el.value = v;
    }

    // URL lists merge additively so autofill never drops a link the visitor added.
    function mergeLines(name, extra) {
        var el = form.elements[name];
        if (!el || !Array.isArray(extra) || !extra.length) return;
        var existing = el.value.split(/\r?\n/).map(function (s) { return s.trim(); }).filter(Boolean);
        var cleaned  = extra.map(function (s) { return String(s).trim(); }).filter(Boolean);
        el.value = Array.from(new Set(existing.concat(cleaned))).join('\n');
    }

    function populateForm(data) {
        ['case_name', 'case_number', 'court', 'jurisdiction', 'filing_date', 'status',
         'summary', 'outcome', 'settlement_amount',
         'plaintiffs', 'defendants', 'facilities_mentioned', 'claims',
         'staff_mentioned', 'organizations_mentioned', 'tags'
        ].forEach(function (f) { setIfExtracted(f, data[f]); });
        mergeLines('source_urls', data.source_urls);
        mergeLines('document_urls', data.document_urls);
    }

    function autofillDone(msg) {
        autofillMsg(msg, 'ok');
        form.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }

    if (uploadBtn && fileInput && autofillStatus) {
        uploadBtn.addEventListener('click', function () {
            if (uploadBtn.disabled) return;
            fileInput.value = '';  // re-selecting the same file must still fire `change`
            fileInput.click();
        });

        fileInput.addEventListener('change', async function () {
            var file = fileInput.files && fileInput.files[0];
            if (!file) return;
            if (file.size > 10 * 1024 * 1024) {
                autofillMsg('That file is larger than 10 MB. Please upload the complaint itself rather than full exhibits.', 'error');
                return;
            }
            setAutofillBusy(true);
            try {
                autofillMsg('Uploading "' + file.name + '"…');
                var fd = new FormData();
                fd.append('action', 'upload');
                fd.append('complaint', file);
                var upRes = await fetch(extractEndpoint, { method: 'POST', body: fd });
                var up = await upRes.json();
                if (!up.success) throw new Error(up.error || 'Upload failed.');

                for (var i = 0; i < up.total_chunks; i++) {
                    if (i > 0) {
                        // Pace requests to stay inside the free AI tier's per-minute quota.
                        for (var s = 65; s > 0; s--) {
                            autofillMsg('Read part ' + i + ' of ' + up.total_chunks + '. Continuing in ' + s + 's (long documents are read in parts)…');
                            await new Promise(function (r) { setTimeout(r, 1000); });
                        }
                    }
                    autofillMsg('Reading part ' + (i + 1) + ' of ' + up.total_chunks + '…');
                    var chunkRes = await fetch(extractEndpoint, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ action: 'chunk', job_id: up.job_id, chunk_index: i })
                    });
                    var chunk = await chunkRes.json();
                    if (!chunk.success) throw new Error(chunk.error || ('Part ' + (i + 1) + ' failed.'));
                }

                autofillMsg('Filling in the form…');
                var finRes = await fetch(extractEndpoint, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'finalize', job_id: up.job_id })
                });
                var fin = await finRes.json();
                if (!fin.success) throw new Error(fin.error || 'Extraction failed.');

                populateForm(fin.data || {});
                autofillDone('Done. Please review the filled-in fields, fix anything that looks off, then submit.');
            } catch (err) {
                autofillMsg('Extraction failed: ' + err.message, 'error');
            } finally {
                setAutofillBusy(false);
            }
        });
    }

    if (lookupBtn && docketInput && autofillStatus) {
        var runLookup = async function () {
            var docket = docketInput.value.trim() || (form.elements.case_number ? form.elements.case_number.value.trim() : '');
            if (!docket) {
                autofillMsg('Enter a docket number first, e.g. 2:24-cv-00123. Federal cases only — for state cases, upload the complaint instead.', 'error');
                return;
            }
            setAutofillBusy(true);
            autofillMsg('Looking up docket ' + docket + '…');
            try {
                var params = new URLSearchParams({ case_number: docket, jurisdiction: 'Federal' });
                var courtHint = form.elements.court ? form.elements.court.value.trim() : '';
                if (courtHint) params.set('court', courtHint);
                var res = await fetch(lookupEndpoint + '?' + params.toString());
                var data = await res.json();
                if (!data.success) throw new Error(data.error || 'Lookup failed.');
                populateForm(data.data || {});
                autofillDone('Found it. Please review the filled-in fields, add anything missing (like a summary or the facilities involved), then submit.');
            } catch (err) {
                autofillMsg(err.message, 'error');
            } finally {
                setAutofillBusy(false);
            }
        };
        lookupBtn.addEventListener('click', runLookup);
        docketInput.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') { e.preventDefault(); runLookup(); }
        });
    }

    form.addEventListener('submit', function (e) {
        e.preventDefault();
        statusEl.textContent = '';
        statusEl.className = 'kop-submit-status';

        var fd = new FormData(form);
        var payload = {};
        fd.forEach(function (value, key) { payload[key] = typeof value === 'string' ? value.trim() : value; });

        if (!payload.case_name) {
            statusEl.textContent = 'Please add a case name or short description.';
            statusEl.className = 'kop-submit-status error';
            return;
        }

        var btn = form.querySelector('.kop-submit-btn');
        btn.disabled = true;
        statusEl.textContent = 'Submitting…';

        fetch(endpoint, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        })
        .then(function (r) { return r.json().catch(function () { return { success: false, error: 'Unexpected server response.' }; }); })
        .then(function (result) {
            if (result && result.success) {
                form.hidden = true;
                if (thanks) thanks.hidden = false;
            } else {
                // Duplicate rejections carry the readable text in `message`
                // (`error` is just the token 'duplicate').
                statusEl.textContent = (result && (result.message || result.error)) || 'Something went wrong. Please try again.';
                statusEl.className = 'kop-submit-status error';
                btn.disabled = false;
            }
        })
        .catch(function () {
            statusEl.textContent = 'Network error. Please try again.';
            statusEl.className = 'kop-submit-status error';
            btn.disabled = false;
        });
    });

    var again = document.getElementById('kop-submit-another');
    if (again) {
        again.addEventListener('click', function () {
            form.reset();
            form.hidden = false;
            if (thanks) thanks.hidden = true;
            statusEl.textContent = '';
            var btn = form.querySelector('.kop-submit-btn');
            if (btn) btn.disabled = false;
            form.scrollIntoView({ behavior: 'smooth', block: 'start' });
        });
    }
});
</script>

<?php get_footer(); ?>
