<?php
/**
 * Template Name: Location Index
 * Description: Public-facing searchable directory of Locations (States & Countries)
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

get_header();
?>

<link rel="stylesheet" href="<?php echo get_stylesheet_directory_uri(); ?>/css/tti-program-index.css">
<link rel="stylesheet" href="<?php echo get_stylesheet_directory_uri(); ?>/css/document-library.css">

<div class="tti-program-index-wrapper">
    <div class="facility-report-container">

        <!-- Page Header -->
        <div class="page-header" style="margin-bottom: 2em;">
            <h1 style="color: #00004d; font-size: 2.5em; margin-bottom: 0.5em;">Location Directory</h1>
            <p style="font-size: 1.1em; color: #666;">Browse TTI facilities and consultants by State or Country</p>
        </div>

        <!-- Search & Filter Controls -->
        <div class="controls">
            <div class="controls-row">
                <input
                    type="text"
                    id="searchInput"
                    placeholder="Search locations or facilities..."
                >

                <select id="typeFilter">
                    <option value="">All Types</option>
                    <option value="state">US States</option>
                    <option value="country">International</option>
                </select>

                <select id="sortBy">
                    <option value="name">Sort A-Z</option>
                    <option value="count">Most Facilities</option>
                </select>

                <button id="clearSearch" onclick="clearSearch()">
                    Clear
                </button>
            </div>

            <!-- Alphabet Filter -->
            <div id="alphabet-filter"></div>
        </div>

        <!-- Locations Container (populated by JavaScript) -->
        <div id="locations-container">
            <div class="loading-message">
                <p>Loading location data...</p>
            </div>
        </div>

    </div>
</div>

<script>
// Configure the JSON data source
window.locationConfig = {
    // REST endpoint first: it attaches linked_news[] (and stamps facility_id) onto
    // nested facilities so cards can show News sections, just like the TTI index.
    // get-master-data.php is kept as a fallback for environments where the REST
    // route is unavailable (it returns the same projects, minus linked news).
    jsonFileUrls: [
        '<?php echo esc_url_raw(rest_url('kop/v1/facilities')); ?>',
        '<?php echo get_stylesheet_directory_uri(); ?>/api/get-master-data.php'
    ]
};

window.locationIndexConfig = {
    restUrl: '<?php echo esc_url_raw(rest_url('kop/v1/')); ?>'
};
</script>

<script src="<?php echo get_stylesheet_directory_uri(); ?>/js/document-library.js?v=<?php echo time(); ?>"></script>
<script src="<?php echo get_stylesheet_directory_uri(); ?>/js/facility-merge.js?v=<?php echo time(); ?>"></script>
<script src="<?php echo get_stylesheet_directory_uri(); ?>/js/location-index.js?v=<?php echo time(); ?>"></script>

<?php
get_footer();
