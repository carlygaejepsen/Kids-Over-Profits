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

    <!-- What an educational consultant is, and why the title matters -->
    <section class="referrer-intro" data-kop-bug-feature="referrer-index/intro" data-kop-bug-label="Consultant Directory Intro">
        <h2>What is an educational consultant?</h2>
        <p>
            In the troubled teen industry, an "educational consultant" (often shortened to "ed con" or "IEC")
            is a private advisor that parents pay to recommend where to send a struggling child. Despite the name,
            most of the placements they arrange are not schools. They are wilderness programs, residential
            treatment centers, therapeutic boarding schools, and similar facilities, many of which appear in our
            <a href="<?php echo esc_url(home_url('/tti-program-index/')); ?>">facility directory</a>.
        </p>
        <h3>Why the title is a red flag</h3>
        <p>
            The word "educational" makes the service sound like academic advising. In practice, these consultants
            are the industry's main referral pipeline. Many are former program staff, belong to industry trade
            groups such as NATSAP or IECA, receive hosted tours and marketing from the programs they recommend,
            or have financial and professional ties to specific facilities. Families are rarely told about those
            ties before a placement. A recommendation from an educational consultant is not an independent
            assessment and should not be treated as one.
        </p>
        <h3>What this directory records</h3>
        <p>
            This directory documents individuals and agencies with recorded referral activity into TTI programs:
            which facilities they have referred families to, where they worked before, who they are affiliated
            with, and any legal history we have found. A listing here is a record of that activity, not an
            endorsement and not an advertisement. If you were placed through one of these consultants, or have
            information to add or correct, use the submit button on any entry.
        </p>
    </section>

    <!-- Search & Filter Controls -->
    <div class="controls" data-kop-bug-feature="referrer-index/search" data-kop-bug-label="Consultant Search" style="margin-bottom: 30px; display: flex; flex-wrap: wrap; gap: 15px; justify-content: center; background: #fff; padding: 20px; border-radius: 10px; border: 1px solid #eee;">
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

<script src="<?php echo get_stylesheet_directory_uri(); ?>/js/submit-info.js?v=<?php echo time(); ?>"></script>
<script src="<?php echo get_stylesheet_directory_uri(); ?>/js/referrer-index-v2.js?v=<?php echo time(); ?>"></script>

<?php
get_footer();
