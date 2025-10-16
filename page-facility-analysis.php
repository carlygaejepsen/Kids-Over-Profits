<?php
/**
 * Template for the facility data analysis page.
 *
 * @package Kids_Over_Profits
 */

get_header();

$theme_base = kidsoverprofits_normalize_theme_base_uri(get_stylesheet_directory_uri());
printf('<div data-kop-theme-base="%s" style="display:none;"></div>', esc_attr($theme_base));


?>

<main id="primary" class="site-main site-main--facility-analysis">
    <?php if (have_posts()) : ?>
        <?php while (have_posts()) : the_post(); ?>
            <article id="post-<?php the_ID(); ?>" <?php post_class(); ?>>
                <div class="entry-content">
        <div class="facility-analysis">
            <div class="container">
                <h1>Facility Data Analysis</h1>
                <div class="load-section">
                    <h3 class="load-section__title">Load Data</h3>
                    <input type="file" id="json-file" accept=".json">
                    <button class="btn btn--offset" id="load-from-db">Load from Database</button>
                </div>
                <div class="tabs">
                    <button class="tab active" data-tab="staff">Staff Connections</button>
                    <button class="tab" data-tab="states">By State</button>
                    <button class="tab" data-tab="operators">By Operator</button>
                </div>
                <!-- Staff Tab -->
                <div class="tab-content active" id="staff-tab">
                    <div class="stats-grid">
                        <div class="stat-card">
                            <div class="stat-number" id="staff-count">0</div>
                            <div class="stat-label">Unique Staff Members</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-number" id="staff-facilities-count">0</div>
                            <div class="stat-label">Total Facilities</div>
                        </div>
                    </div>
                    <div class="search-box">
                        <input type="text" id="staff-search" placeholder="Search staff by name...">
                    </div>
                    <div id="staff-list"></div>
                </div>
                <!-- States Tab -->
                <div class="tab-content" id="states-tab">
                    <div class="stats-grid">
                        <div class="stat-card">
                            <div class="stat-number" id="states-count">0</div>
                            <div class="stat-label">States Represented</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-number" id="states-facilities-count">0</div>
                            <div class="stat-label">Total Facilities</div>
                        </div>
                    </div>
                    <div class="search-box">
                        <input type="text" id="state-search" placeholder="Search states...">
                    </div>
                    <div id="states-list"></div>
                </div>
                <!-- Operators Tab -->
                <div class="tab-content" id="operators-tab">
                    <div class="stats-grid">
                        <div class="stat-card">
                            <div class="stat-number" id="operators-count">0</div>
                            <div class="stat-label">Unique Operators</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-number" id="operators-facilities-count">0</div>
                            <div class="stat-label">Total Facilities</div>
                        </div>
                    </div>
                    <div class="search-box">
                        <input type="text" id="operator-search" placeholder="Search operators...">
                    </div>
                    <div id="operators-list"></div>
                </div>
            </div>
        </div>
                </div>
            </article>
        <?php endwhile; ?>
    <?php endif; ?>
</main>

<?php
get_footer();
?>
