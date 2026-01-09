<?php
/**
 * Template Name: Referrer Index
 * Description: Public-facing searchable directory of Educational Consultants and Referrers
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

get_header();
?>

<link rel="stylesheet" href="<?php echo get_stylesheet_directory_uri(); ?>/css/tti-program-index.css">
<!-- We reuse the TTI styles as they are generic enough -->

<div class="tti-program-index-wrapper">
    <div class="facility-report-container">

        <!-- Page Header -->
        <div class="page-header" style="margin-bottom: 2em;">
            <h1 style="color: #00004d; font-size: 2.5em; margin-bottom: 0.5em;">Educational Consultant Directory</h1>
            <p style="font-size: 1.1em; color: #666;">Searchable database of Educational Consultants, Ed Cons, and Referral Agencies</p>
        </div>

        <!-- Search & Filter Controls -->
        <div class="controls">
            <div class="controls-row">
                <input
                    type="text"
                    id="searchInput"
                    placeholder="Search consultants or agencies..."
                >

                <select id="statusFilter">
                    <option value="">All Locations</option>
                    <!-- Populated via JS -->
                </select>

                <select id="sortBy">
                    <option value="name">Sort A-Z</option>
                    <!-- <option value="recent">Recently Added</option> -->
                </select>

                <button id="clearSearch" onclick="clearSearch()">
                    Clear
                </button>
            </div>

            <!-- Alphabet Filter -->
            <div id="alphabet-filter"></div>
        </div>

        <!-- Container (populated by JavaScript) -->
        <div id="referrers-container">
            <div class="loading-message">
                <p>Loading consultant data...</p>
            </div>
        </div>

    </div>
</div>

<script>
// Configure the JSON data source
window.referrerConfig = {
    // Re-use the same master data API which returns everything
    jsonFileUrls: [
        '<?php echo get_stylesheet_directory_uri(); ?>/api/get-master-data.php'
    ]
};
</script>

<script src="<?php echo get_stylesheet_directory_uri(); ?>/js/referrer-index.js?v=<?php echo time(); ?>"></script>

<?php
get_footer();
