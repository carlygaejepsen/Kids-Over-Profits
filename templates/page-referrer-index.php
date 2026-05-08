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

<link rel="stylesheet" href="<?php echo get_stylesheet_directory_uri(); ?>/css/referrer-index.css?v=<?php echo time(); ?>">

<div class="referrer-directory-container">
    <div class="directory-header" style="margin-bottom: 2em; text-align: center;">
        <h1 style="color: #00004d; font-size: 2.5em; margin-bottom: 0.2em;">Educational Consultant Directory</h1>
        <p style="font-size: 1.1em; color: #666;">Searchable database of Educational Consultants, Ed Cons, and Referral Agencies</p>
    </div>

    <!-- Search & Filter Controls -->
    <div class="controls" style="margin-bottom: 30px; display: flex; flex-wrap: wrap; gap: 15px; justify-content: center; background: #fff; padding: 20px; border-radius: 10px; border: 1px solid #eee;">
        <input
            type="text"
            id="searchInput"
            placeholder="Search name, agency, or details..."
            style="flex: 1; min-width: 300px; padding: 12px; border: 1px solid #ddd; border-radius: 6px;"
        >

        <select id="statusFilter" style="padding: 12px; border: 1px solid #ddd; border-radius: 6px; min-width: 180px;">
            <option value="">All Locations</option>
        </select>

        <button id="clearSearch" onclick="window.clearSearch()" style="padding: 12px 24px; background: #00004d; color: white; border: none; border-radius: 6px; cursor: pointer; font-weight: bold;">
            Clear Search
        </button>
    </div>

    <!-- Alphabet Filter -->
    <div id="alphabet-filter" class="referrer-alpha-filter"></div>

    <!-- Container (populated by JavaScript) -->
    <div id="referrers-container">
        <div style="text-align: center; padding: 50px;">
            <p>Loading consultant directory...</p>
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

<script src="<?php echo get_stylesheet_directory_uri(); ?>/js/referrer-index-v2.js?v=<?php echo time(); ?>"></script>

<?php
get_footer();
