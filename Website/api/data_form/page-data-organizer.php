<?php
/**
 * Template for the data organizer page.
 *
 * @package Kids_Over_Profits
 */

get_header();

?>

<main id="primary" class="site-main site-main--data-organizer">
    <?php if (have_posts()) : ?>
        <?php while (have_posts()) : the_post(); ?>
            <article id="post-<?php the_ID(); ?>" <?php post_class(); ?>>
                <div class="entry-content">
        <div class="data-organizer">
            <div class="container">
                <div class="header">
                    <h1>📊 Facility Data Organizer</h1>
                    <p>Organize and explore facility data by any data point - staff members, operators, locations, and more</p>
                </div>
                <div class="controls">
                    <div class="control-group">
                        <div class="control-label">Organize by:</div>
                        <div class="control-input">
                            <select id="organizeBy">
                                <option value="">Select data point...</option>
                                <option value="staff">Staff Member</option>
                                <option value="operator">Operator/Company</option>
                                <option value="location">Location</option>
                                <option value="programType">Program Type</option>
                                <option value="status">Operating Status</option>
                                <option value="year">Opening Year</option>
                                <option value="accreditation">Accreditation</option>
                                <option value="certification">Certification</option>
                            </select>
                        </div>
                    </div>
                    <div class="control-group" id="valueGroup">
                        <div class="control-label">Find entries with:</div>
                        <div class="control-input">
                            <select id="specificValue">
                                <option value="">Select value...</option>
                            </select>
                        </div>
                    </div>
                    <div class="control-group" id="searchGroup">
                        <div class="control-label">Or search:</div>
                        <div class="control-input">
                            <input type="text" id="searchValue" placeholder="Type to search...">
                        </div>
                        <button class="btn" id="searchBtn">Search</button>
                    </div>
                    <div class="search-info" id="searchInfo">
                        <strong>💡 How it works:</strong> Select a data point type (like "Staff Member"), then choose a specific value or search for one.
                        The system will show all facilities that contain that data point, highlighting where it appears.
                    </div>
                </div>
                <div class="results">
                    <div id="loadingState" class="loading">
                        <div class="loading-spinner"></div>
                        <div>Loading facility data...</div>
                    </div>
                    <div id="noDataState" class="no-results">
                        <div class="no-results-icon">📁</div>
                        <h3>Loading Data...</h3>
                        <p>Attempting to load facility data automatically. Please wait...</p>
                    </div>
                    <div id="resultsContainer">
                        <div class="results-header">
                            <div class="results-title" id="resultsTitle">Search Results</div>
                            <div class="results-count" id="resultsCount">0 facilities</div>
                        </div>
                        <div id="facilityGrid" class="facility-grid"></div>
                        <div id="noResults" class="no-results">
                            <div class="no-results-icon">🔍</div>
                            <h3>No matches found</h3>
                            <p>Try a different search term or data point.</p>
                        </div>
                    </div>
                </div>
            </div>
            <!-- Facility Detail Modal -->
            <div id="facilityModal" class="modal">
                <div class="modal-content">
                    <div class="modal-header">
                        <span class="modal-close" id="modalClose">&times;</span>
                        <h2 class="modal-title" id="modalTitle">Facility Details</h2>
                    </div>
                    <div class="modal-body" id="modalBody">
                        <!-- Facility details will be loaded here -->
                    </div>
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
