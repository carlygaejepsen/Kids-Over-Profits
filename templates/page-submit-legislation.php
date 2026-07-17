<?php
/**
 * Template Name: Submit Legislation
 *
 * Public form for submitting a bill/law to the Legislation Tracker. Submissions
 * are stored as "pending" and reviewed by an admin in Submissions Review before
 * they appear on the public tracker — the same workflow as wiki entries and
 * data-form suggestions.
 */

if (!defined('ABSPATH')) { exit; }

get_header();

$us_states = ['Alabama','Alaska','Arizona','Arkansas','California','Colorado','Connecticut','Delaware','Florida','Georgia','Hawaii','Idaho','Illinois','Indiana','Iowa','Kansas','Kentucky','Louisiana','Maine','Maryland','Massachusetts','Michigan','Minnesota','Mississippi','Missouri','Montana','Nebraska','Nevada','New Hampshire','New Jersey','New Mexico','New York','North Carolina','North Dakota','Ohio','Oklahoma','Oregon','Pennsylvania','Rhode Island','South Carolina','South Dakota','Tennessee','Texas','Utah','Vermont','Virginia','Washington','West Virginia','Wisconsin','Wyoming'];

$status_labels = [
    'introduced'    => 'Introduced',
    'in_committee'  => 'In committee',
    'passed_house'  => 'Passed House',
    'passed_senate' => 'Passed Senate',
    'signed'        => 'Signed',
    'enacted'       => 'Enacted',
    'vetoed'        => 'Vetoed',
    'dead'          => 'Dead',
    'unknown'       => 'Not sure',
];

$endpoint = get_stylesheet_directory_uri() . '/api/save-legislation-suggestion.php';

// Resolve the public tracker page by template (falls back to the conventional slug).
$tracker_url = function_exists('kop_find_template_page_url') ? kop_find_template_page_url('page-legislation.php') : '';
if (!$tracker_url) { $tracker_url = home_url('/legislation'); }
?>

<div class="kop-records-page kop-submit-page">
    <div class="kop-records-header">
        <h1>Submit Legislation</h1>
        <p>Know of a bill or law affecting Troubled Teen Industry facilities? Share it here. Submissions are reviewed by our team before they appear on the public <a href="<?php echo esc_url($tracker_url); ?>">Legislation Tracker</a>. You don't need to fill in everything — a title and a link are plenty.</p>
    </div>

    <form id="kop-submit-legislation-form" class="kop-submit-form" novalidate>
        <div class="kop-form-grid">
            <label class="kop-field kop-field-wide">
                <span class="kop-field-label">Bill title or short description <span class="req">*</span></span>
                <input type="text" name="bill_title" required maxlength="500" placeholder="e.g. Accountability for Congregate Care Act">
            </label>

            <label class="kop-field">
                <span class="kop-field-label">Bill number</span>
                <input type="text" name="bill_number" maxlength="100" placeholder="e.g. HB 1190, S.123">
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
                <span class="kop-field-label">Session / year</span>
                <input type="text" name="session_year" maxlength="20" placeholder="e.g. 2025">
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
                <span class="kop-field-label">Official tracker URL</span>
                <input type="url" name="official_url" placeholder="https://legislature.example.gov/bill/...">
            </label>

            <label class="kop-field kop-field-wide">
                <span class="kop-field-label">Full text URL</span>
                <input type="url" name="full_text_url" placeholder="Link to the bill text (PDF or page)">
            </label>

            <label class="kop-field kop-field-wide">
                <span class="kop-field-label">Sponsors <span class="hint">(one per line)</span></span>
                <textarea name="sponsors" rows="2" placeholder="Rep. Jane Doe&#10;Sen. John Smith"></textarea>
            </label>

            <label class="kop-field kop-field-wide">
                <span class="kop-field-label">Summary / what the bill does</span>
                <textarea name="summary" rows="4" placeholder="A few sentences in plain language."></textarea>
            </label>

            <label class="kop-field kop-field-wide">
                <span class="kop-field-label">Subject tags <span class="hint">(one per line — e.g. transparency, restraints, oversight)</span></span>
                <textarea name="subject_tags" rows="2"></textarea>
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
                    <textarea name="notes" rows="2" placeholder="Anything else we should know? Where did you hear about this bill?"></textarea>
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
    var form    = document.getElementById('kop-submit-legislation-form');
    var statusEl = document.getElementById('kop-submit-status');
    var thanks  = document.getElementById('kop-submit-thanks');
    var endpoint = <?php echo wp_json_encode($endpoint); ?>;
    if (!form) return;

    form.addEventListener('submit', function (e) {
        e.preventDefault();
        statusEl.textContent = '';
        statusEl.className = 'kop-submit-status';

        var fd = new FormData(form);
        var payload = {};
        fd.forEach(function (value, key) { payload[key] = typeof value === 'string' ? value.trim() : value; });

        if (!payload.bill_title) {
            statusEl.textContent = 'Please add a bill title or short description.';
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
