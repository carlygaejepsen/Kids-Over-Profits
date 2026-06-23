<?php
/**
 * Template Name: Admin - Data Manager
 * Template Post Type: page
 *
 * A unified admin screen for managing master records: rename / change ID,
 * edit the document-library folder ID, move a record to a different category,
 * reassign a nested facility between operators, repoint/confirm/unlink wiki
 * links, and delete records.
 *
 * Backed by api/data-manager.php (+ save-master.php, facility-picker.php,
 * link-wiki-facility.php, facility-search.php). Admin-only.
 */

if (!current_user_can('manage_options')) {
    wp_die('You do not have permission to access this page.', 'Access Denied', array('response' => 403));
}

get_header();
?>

<div class="kop-dm-page">
    <header class="kop-dm-head">
        <h1>Data Manager</h1>
        <p class="kop-dm-sub">
            Search every master record across all categories. Use the row actions to rename
            (change ID), edit the document-library folder ID, move a record to a different
            category, reassign a facility, manage wiki links, or delete.
        </p>
    </header>

    <div class="kop-dm-toolbar">
        <input type="search" id="dmSearch" class="kop-dm-search" placeholder="Search by program / record name…" autocomplete="off">
        <select id="dmCategory" class="kop-dm-category">
            <option value="">All categories</option>
            <option value="companies">Companies / Operators</option>
            <option value="referrers">Referrers</option>
            <option value="transporters">Transporters</option>
            <option value="locations">Locations</option>
        </select>
        <button type="button" id="dmRefresh" class="kop-dm-btn kop-dm-btn-ghost">↻ Refresh</button>
        <button type="button" id="dmManageFolders" class="kop-dm-btn">📁 Manage Folders</button>
        <button type="button" id="dmScrape" class="kop-dm-btn">🔗 Initial Scrape</button>
        <span id="dmCount" class="kop-dm-result-count"></span>
    </div>

    <div id="dmTableWrap" class="kop-dm-table-wrap">
        <div class="kop-dm-loading">Loading records…</div>
    </div>

    <div class="kop-dm-pagination">
        <button type="button" id="dmPrev" class="kop-dm-btn kop-dm-btn-ghost" disabled>← Prev</button>
        <span id="dmPageInfo" class="kop-dm-page-info"></span>
        <button type="button" id="dmNext" class="kop-dm-btn kop-dm-btn-ghost" disabled>Next →</button>
    </div>
</div>

<!-- Generic action modal (filled by JS) -->
<div id="dmModal" class="kop-dm-modal" style="display:none;" aria-hidden="true">
    <div class="kop-dm-modal-dialog" role="dialog" aria-modal="true">
        <div class="kop-dm-modal-header">
            <h3 id="dmModalTitle">Action</h3>
            <button type="button" class="kop-dm-modal-close" aria-label="Close">&times;</button>
        </div>
        <div id="dmModalBody" class="kop-dm-modal-body"></div>
        <div id="dmModalStatus" class="kop-dm-modal-status"></div>
    </div>
</div>

<?php
get_footer();
