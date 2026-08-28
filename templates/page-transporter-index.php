<?php
/**
 * Template Name: Transporter Index
 * Description: Public-facing searchable directory of Youth Transport Companies
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

get_header();
?>

<link rel="stylesheet" href="<?php echo get_stylesheet_directory_uri(); ?>/css/transporter-index.css?v=<?php echo time(); ?>">

<div class="transporter-directory-container" data-kop-bug-feature="transporter-index/directory" data-kop-bug-label="Transporter Directory">
    <div class="directory-header" style="margin-bottom: 2em; text-align: center;">
        <h1 style="color: #00004d; font-size: 2.5em; margin-bottom: 0.2em;">Youth Transport Company Directory</h1>
        <p style="font-size: 1.1em; color: #666;">Searchable database of companies and individuals that transport youth to facilities</p>
    </div>

    <!-- Search & Filter Controls -->
    <div class="controls" data-kop-bug-feature="transporter-index/search" data-kop-bug-label="Transporter Search" style="margin-bottom: 30px; display: flex; flex-wrap: wrap; gap: 15px; justify-content: center; background: #fff; padding: 20px; border-radius: 10px; border: 1px solid #eee;">
        <input
            type="text"
            id="searchInput"
            placeholder="Search name, company, vehicle types, or details..."
            style="flex: 1; min-width: 300px; padding: 12px; border: 1px solid #ddd; border-radius: 6px;"
        >

        <select id="statusFilter" style="padding: 12px; border: 1px solid #ddd; border-radius: 6px; min-width: 180px;">
            <option value="">All Locations</option>
        </select>

        <button id="clearSearch" onclick="window.clearSearch && window.clearSearch()" style="padding: 12px 24px; background: #00004d; color: white; border: none; border-radius: 6px; cursor: pointer; font-weight: bold;">
            Clear Search
        </button>
    </div>

    <!-- Alphabet Filter -->
    <div id="alphabet-filter" class="transporter-alpha-filter"></div>

    <!-- Container (populated by JavaScript) -->
    <div id="transporters-container">
        <div style="text-align: center; padding: 50px;">
            <p>Loading transporter directory...</p>
        </div>
    </div>
</div>

<script>
// Configure the JSON data source
window.transporterConfig = {
    // Re-use the master data API which returns everything
    jsonFileUrls: [
        '<?php echo get_stylesheet_directory_uri(); ?>/api/get-master-data.php'
    ]
};
</script>

<script src="<?php echo get_stylesheet_directory_uri(); ?>/js/transporter-index-v2.js?v=<?php echo time(); ?>"></script>

<?php
get_footer();
