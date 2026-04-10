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
            <article id="post-<?php the_ID(); ?>" <?php post_class(); ?>>
                <header class="entry-header">
                    <?php the_title( '<h1 class="entry-title">', '</h1>' ); ?>
                </header>

                <div class="entry-content">
                    <div class="facility-report-container">
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

                        <main id="report-container" class="report-list">
                            <p class="loading-message">Loading report data...</p>
                        </main>
                    </div>

                    <?php the_content(); ?>
                </div>
            </article>
        </main>
    </div>
</div>

<?php
get_footer();
?>
