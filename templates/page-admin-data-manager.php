<?php
/**
 * Template Name: Admin - Data Manager
 * Template Post Type: page
 *
 * A unified admin screen for managing master records: rename / change ID,
 * edit the document-library folder ID, move a record to a different category,
 * reassign a nested facility between operators, repoint/confirm/unlink wiki
 * links, delete records, and set a facility record's designation (home of a
 * program, young adult program, Indian boarding school).
 *
 * Backed by api/data-manager.php (+ save-master.php, facility-picker.php,
 * link-wiki-facility.php, facility-search.php). Admin-only.
 */

kop_require_page_capability('manage_options');

get_header();
?>

<div class="kop-dm-page">
    <header class="kop-dm-head">
        <h1>Data Manager</h1>
        <p class="kop-dm-sub">
            Search every record: companies, each facility record, programs and their homes, young adult
            programs, Indian boarding schools, referrers, transporters, providers, locations, people, news,
            lawsuits, bills, merged records and companies made into one program. People, news, lawsuits, bills
            and merges join "All categories" when you search, or pick their category.
            Reclassify makes a facility a home of a program, a young adult program (18+) or an Indian boarding
            school; makes a company that is only its own homes one program; and files an article under Indian
            boarding schools or a young adult program. Merge into joins two records that are one place;
            Facilities on a news item or lawsuit adds, moves or removes its facility links. The filters narrow
            the facility records by state, status, type and years. Every change has an Undo.
        </p>
    </header>

    <div class="kop-dm-toolbar">
        <input type="search" id="dmSearch" class="kop-dm-search" placeholder="Search by program / record name…" autocomplete="off">
        <select id="dmCategory" class="kop-dm-category">
            <option value="">All categories</option>
            <option value="companies">Companies / Operators</option>
            <option value="facilities">Facilities (each record)</option>
            <option value="program_homes">Programs and their homes</option>
            <option value="young_adult">Young adult programs (18+)</option>
            <option value="indigenous_schools">Indian boarding schools</option>
            <option value="referrers">Referrers</option>
            <option value="transporters">Transporters</option>
            <option value="providers">Mental Health Providers</option>
            <option value="locations">Locations</option>
            <option value="people">People (staff and executives)</option>
            <option value="news">News</option>
            <option value="lawsuits">Lawsuits</option>
            <option value="bills">Bills</option>
            <option value="merged">Merged away (duplicates)</option>
            <option value="converted">Companies made one program</option>
        </select>
        <button type="button" id="dmRefresh" class="kop-dm-btn kop-dm-btn-ghost">↻ Refresh</button>
        <button type="button" id="dmManageFolders" class="kop-dm-btn"><?php echo kop_icon('folder'); ?> Manage Folders</button>
        <button type="button" id="dmScrape" class="kop-dm-btn"><?php echo kop_icon('link'); ?> Initial Scrape</button>
        <span id="dmCount" class="kop-dm-result-count"></span>
    </div>

    <div id="dmFilters" class="kop-dm-filters" role="group" aria-label="Filter facility records">
        <span class="kop-dm-filters-label">Facility records:</span>
        <label class="screen-reader-text" for="dmState">State</label>
        <select id="dmState"><option value="">Any state</option></select>
        <label class="screen-reader-text" for="dmStatus">Status</label>
        <select id="dmStatus"><option value="">Any status</option></select>
        <label class="screen-reader-text" for="dmType">Type</label>
        <select id="dmType"><option value="">Any type</option></select>
        <label class="screen-reader-text" for="dmYears">Years</label>
        <select id="dmYears">
            <option value="">Any years</option>
            <option value="none">No opening year</option>
            <option value="closed_no_end">Closed, no closing year</option>
        </select>
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
