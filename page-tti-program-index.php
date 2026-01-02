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
        <div class="page-header">
            <h1>TTI Facility Directory</h1>
            <p>Searchable database of Troubled Teen Industry facilities and parent companies</p>
        </div>

        <!-- Search & Filter Controls -->
        <div class="controls">
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
                    <option value="violations-only">Violations Only</option>
                    <option value="violations-desc">Most Violations</option>
                    <option value="recent-inspection">Recent Inspections</option>
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
            <div class="loading-message">
                <p>Loading facility data...</p>
            </div>
        </div>

    </div>
</div>

<?php
get_footer();
