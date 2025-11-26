<?php
/**
 * Template Name: TTI Program Index
 * Description: Public-facing searchable directory of TTI facilities
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

get_header();
?>

<link rel="stylesheet" href="<?php echo get_stylesheet_directory_uri(); ?>/css/tti-program-index.css">

<div class="tti-program-index-wrapper">
    <div class="facility-report-container">

        <!-- Page Header -->
        <div class="page-header" style="margin-bottom: 2em;">
            <h1 style="color: #00004d; font-size: 2.5em; margin-bottom: 0.5em;">TTI Facility Directory</h1>
            <p style="font-size: 1.1em; color: #666;">Searchable database of Troubled Teen Industry facilities and operators</p>
        </div>

        <!-- Search & Filter Controls -->
        <div class="controls">
            <div style="display: flex; flex-wrap: wrap; gap: 10px; align-items: center; margin-bottom: 15px;">
                <input
                    type="text"
                    id="searchInput"
                    placeholder="Search facilities or operators..."
                    style="flex: 1; min-width: 250px; padding: 10px; border: 1px solid #ddd; border-radius: 4px;"
                >

                <select
                    id="statusFilter"
                    style="padding: 10px; border: 1px solid #ddd; border-radius: 4px; min-width: 150px;"
                >
                    <option value="">All Statuses</option>
                    <option value="open">Open</option>
                    <option value="closed">Closed</option>
                    <option value="transferred">Transferred</option>
                </select>

                <select
                    id="sortBy"
                    style="padding: 10px; border: 1px solid #ddd; border-radius: 4px; min-width: 150px;"
                >
                    <option value="name">Sort A-Z</option>
                    <option value="violations-only">Violations Only</option>
                    <option value="violations-desc">Most Violations</option>
                    <option value="recent-inspection">Recent Inspections</option>
                </select>

                <button
                    id="clearSearch"
                    onclick="clearSearch()"
                    style="display: none; padding: 10px 20px; background-color: #ff6600; color: white; border: none; border-radius: 4px; cursor: pointer; font-weight: bold;"
                >
                    Clear
                </button>
            </div>

            <!-- Alphabet Filter -->
            <div id="alphabet-filter"></div>
        </div>

        <!-- Facilities Container (populated by JavaScript) -->
        <div id="facilities-container">
            <div style="text-align: center; padding: 40px; color: #666;">
                <p>Loading facility data...</p>
            </div>
        </div>

    </div>
</div>

<script>
// Configure the JSON data source
window.facilitiesConfig = {
    jsonDataUrl: '<?php echo get_stylesheet_directory_uri(); ?>/api/get-master-data.php',
    jsonFileUrls: [
        '<?php echo get_stylesheet_directory_uri(); ?>/api/get-master-data.php'
    ]
};
</script>

<script src="<?php echo get_stylesheet_directory_uri(); ?>/js/tti-program-index.js"></script>

<?php
get_footer();
