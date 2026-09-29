<?php
/**
 * Template Name: TTI Program Index
 * Description: Public-facing searchable directory of TTI facilities, one page
 * with two tabs: by parent company (js/tti-program-index.js) and by location
 * (js/location-index.js). The old /location-index/ page redirects to the
 * location tab (?view=location, inc/redirects.php).
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

$kop_dir_view = (isset($_GET['view']) && $_GET['view'] === 'location') ? 'location' : 'company';

get_header();
?>


<div class="tti-program-index-wrapper">
    <div class="facility-report-container">

        <!-- Page Header -->
        <div class="page-header" style="margin-bottom: 1em;">
            <h1 style="color: #00004d; font-size: 2.5em; margin-bottom: 0.5em;">TTI Facility Directory</h1>
            <p style="font-size: 1.1em; color: #666;">Searchable database of Troubled Teen Industry facilities, by parent company or by state and country</p>
        </div>

        <nav class="kop-dir-tabs" role="tablist" aria-label="Browse the directory">
            <button type="button" class="kop-dir-tab<?php echo $kop_dir_view === 'company' ? ' active' : ''; ?>" id="kop-dir-tab-company" role="tab" data-view="company" aria-controls="kop-dir-panel-company" aria-selected="<?php echo $kop_dir_view === 'company' ? 'true' : 'false'; ?>">By parent company</button>
            <button type="button" class="kop-dir-tab<?php echo $kop_dir_view === 'location' ? ' active' : ''; ?>" id="kop-dir-tab-location" role="tab" data-view="location" aria-controls="kop-dir-panel-location" aria-selected="<?php echo $kop_dir_view === 'location' ? 'true' : 'false'; ?>">By location</button>
        </nav>

        <section id="kop-dir-panel-company" role="tabpanel" aria-labelledby="kop-dir-tab-company"<?php echo $kop_dir_view === 'company' ? '' : ' hidden'; ?>>
            <!-- Search & Filter Controls -->
            <div class="controls" data-kop-bug-feature="program-index/search" data-kop-bug-label="Program Search">
                <div class="controls-row">
                    <input
                        type="text"
                        id="searchInput"
                        placeholder="Search facilities or parent companies..."
                    >

                    <select id="statusFilter">
                        <option value="">All Statuses</option>
                        <option value="open">Open</option>
                        <option value="closed">Closed</option>
                        <option value="transferred">Transferred</option>
                    </select>

                    <select id="sortBy">
                        <option value="name">Sort A-Z</option>
                        <option value="violations-only">Violations / Inspections Only</option>
                        <option value="reports-desc">Most Inspection Reports</option>
                    </select>

                    <button id="clearSearch" onclick="clearSearch()">
                        Clear
                    </button>
                </div>

                <!-- Alphabet Filter -->
                <div id="alphabet-filter"></div>
            </div>

            <!-- Facilities Container (populated by JavaScript) -->
            <div id="facilities-container">
                <?php echo kop_loading_skeleton('the facility list', 5); ?>
            </div>
        </section>

        <section id="kop-dir-panel-location" role="tabpanel" aria-labelledby="kop-dir-tab-location"<?php echo $kop_dir_view === 'location' ? '' : ' hidden'; ?>>
            <div class="controls" data-kop-bug-feature="location-index/search" data-kop-bug-label="Location Search">
                <div class="controls-row">
                    <input
                        type="text"
                        id="loc-searchInput"
                        placeholder="Search states, countries or facilities..."
                    >

                    <select id="loc-typeFilter">
                        <option value="">All Types</option>
                        <option value="state">US States</option>
                        <option value="country">International</option>
                    </select>

                    <select id="loc-sortBy">
                        <option value="name">Sort A-Z</option>
                        <option value="count">Most Facilities</option>
                    </select>

                    <button type="button" id="loc-clearSearch">
                        Clear
                    </button>
                </div>

                <div id="loc-alphabet-filter"></div>
            </div>

            <!-- Locations Container (filled by js/location-index.js the first time the tab is shown) -->
            <div id="locations-container">
                <?php echo kop_loading_skeleton('the location list', 5); ?>
            </div>
        </section>

    </div>
</div>

<script>
// Configure the JSON data source
window.facilitiesConfig = {
    jsonDataUrl: '<?php echo esc_url_raw(rest_url('kop/v1/facilities')); ?>',
    jsonFileUrls: [
        '<?php echo get_stylesheet_directory_uri(); ?>/api/get-master-data.php'
    ]
};

// The location tab: REST first (it attaches linked_news[] to nested
// facilities), get-master-data.php as the fallback.
window.locationConfig = {
    jsonFileUrls: [
        '<?php echo esc_url_raw(rest_url('kop/v1/facilities')); ?>',
        '<?php echo get_stylesheet_directory_uri(); ?>/api/get-master-data.php'
    ]
};
window.locationIndexConfig = {
    restUrl: '<?php echo esc_url_raw(rest_url('kop/v1/')); ?>'
};

// Tab switcher. ?view=location stays in the address bar so the tab can be
// linked and survives a reload.
(function () {
    var tabs = document.querySelectorAll('.kop-dir-tab');
    function show(view) {
        tabs.forEach(function (tab) {
            var on = tab.getAttribute('data-view') === view;
            tab.classList.toggle('active', on);
            tab.setAttribute('aria-selected', on ? 'true' : 'false');
            var panel = document.getElementById(tab.getAttribute('aria-controls'));
            if (panel) panel.hidden = !on;
        });
        try {
            var url = new URL(window.location.href);
            if (view === 'location') url.searchParams.set('view', 'location');
            else url.searchParams.delete('view');
            window.history.replaceState(null, '', url.toString());
        } catch (e) {}
        if (view === 'location') document.dispatchEvent(new Event('kop:location-tab-shown'));
    }
    tabs.forEach(function (tab) {
        tab.addEventListener('click', function () { show(tab.getAttribute('data-view')); });
    });
})();
</script>


<?php
get_footer();
