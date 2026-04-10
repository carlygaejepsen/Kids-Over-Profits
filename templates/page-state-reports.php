<?php
/**
 * Template Name: State Reports
 * Description: Template for state inspection reports (CT, AZ, TX, etc.)
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

get_header();
?>

<div id="content" class="site-content">
    <div id="primary" class="content-area">
        <main id="main" class="site-main">
            <?php
            if (have_posts()) :
                while (have_posts()) :
                    the_post();
                    ?>
                    <div class="entry-content">
                        <div class="facility-report-container">
                            <?php the_content(); ?>

                            <header class="report-header">
                                <nav id="alphabet-filter" class="alphabet-filter">
                                    <!-- JavaScript will populate this -->
                                </nav>

                                <div class="controls">
                                    <input type="text" id="searchInput" placeholder="Search all facilities...">
                                    <select id="sortBy">
                                        <option value="">Default Order (A-Z)</option>
                                        <option value="name">Sort by Name</option>
                                        <option value="violations-only">Facilities with Violations Only</option>
                                        <option value="violations-desc">Most Violations First</option>
                                        <option value="recent-inspection">Most Recent Inspection</option>
                                    </select>
                                    <button id="clearSearch" onclick="clearSearch()" style="display: none;">Clear Search</button>
                                </div>
                            </header>

                            <main id="report-container" class="facility-report-container">
                                <p class="loading-message">Loading report data...</p>
                            </main>
                        </div>
                    </div>
                    <?php
                endwhile;
            endif;
            ?>
        </main>
    </div>
</div>

<?php
get_footer();
?>
