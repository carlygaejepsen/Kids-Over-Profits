<?php
/**
 * Template for the autocomplete tester page.
 *
 * @package Kids_Over_Profits
 */

$autocomplete_endpoint = trailingslashit(get_stylesheet_directory_uri()) . 'api/get-autocomplete.php';

get_header();

?>

<main id="primary" class="site-main site-main--test-autocomplete">
    <?php if (have_posts()) : ?>
        <?php while (have_posts()) : the_post(); ?>
            <article id="post-<?php the_ID(); ?>" <?php post_class(); ?>>
                <div class="entry-content">
        <div class="autocomplete-tester">
            <h1>🧪 Autocomplete SQL Backend Tester</h1>
            <div class="summary" id="summary">
                <h2>Test Summary</h2>
                <p>This tool tests all autocomplete categories against the SQL backend endpoint.</p>
                <p><strong>Endpoint:</strong> <code data-autocomplete-endpoint="true"><?php echo esc_html($autocomplete_endpoint); ?></code></p>
                <p><strong>Status:</strong> <span id="overall-status">Ready to test</span></p>
            </div>
            <button class="test-all-btn" onclick="testAllCategories()">▶️ Test All Categories</button>
            <div class="test-section">
                <h2>Individual Category Tests</h2>
                <div class="test-category">
                    <button onclick="testCategory('operator', 'seq')">Test Operator</button>
                    <input type="text" id="operator-query" placeholder="Search query (e.g., seq)" value="seq">
                    <span class="status-badge pending" id="status-operator">Pending</span>
                </div>
                <div class="results" id="results-operator"></div>
                <div class="test-category">
                    <button onclick="testCategory('facility', 'academy')">Test Facility</button>
                    <input type="text" id="facility-query" placeholder="Search query (e.g., academy)" value="academy">
                    <span class="status-badge pending" id="status-facility">Pending</span>
                </div>
                <div class="results" id="results-facility"></div>
                <div class="test-category">
                    <button onclick="testCategory('human', 'john')">Test Human Names</button>
                    <input type="text" id="human-query" placeholder="Search query (e.g., john)" value="john">
                    <span class="status-badge pending" id="status-human">Pending</span>
                </div>
                <div class="results" id="results-human"></div>
                <div class="test-category">
                    <button onclick="testCategory('type', 'residential')">Test Facility Types</button>
                    <input type="text" id="type-query" placeholder="Search query (e.g., residential)" value="residential">
                    <span class="status-badge pending" id="status-type">Pending</span>
                </div>
                <div class="results" id="results-type"></div>
                <div class="test-category">
                    <button onclick="testCategory('role', 'director')">Test Staff Roles</button>
                    <input type="text" id="role-query" placeholder="Search query (e.g., director)" value="director">
                    <span class="status-badge pending" id="status-role">Pending</span>
                </div>
                <div class="results" id="results-role"></div>
                <div class="test-category">
                    <button onclick="testCategory('status', 'active')">Test Status</button>
                    <input type="text" id="status-query" placeholder="Search query (e.g., active)" value="active">
                    <span class="status-badge pending" id="status-status">Pending</span>
                </div>
                <div class="results" id="results-status"></div>
                <div class="test-category">
                    <button onclick="testCategory('gender', 'male')">Test Gender</button>
                    <input type="text" id="gender-query" placeholder="Search query (e.g., male)" value="male">
                    <span class="status-badge pending" id="status-gender">Pending</span>
                </div>
                <div class="results" id="results-gender"></div>
                <div class="test-category">
                    <button onclick="testCategory('location', 'utah')">Test Location</button>
                    <input type="text" id="location-query" placeholder="Search query (e.g., utah)" value="utah">
                    <span class="status-badge pending" id="status-location">Pending</span>
                </div>
                <div class="results" id="results-location"></div>
                <div class="test-category">
                    <button onclick="testCategory('certification', 'license')">Test Certification</button>
                    <input type="text" id="certification-query" placeholder="Search query" value="license">
                    <span class="status-badge pending" id="status-certification">Pending</span>
                </div>
                <div class="results" id="results-certification"></div>
                <div class="test-category">
                    <button onclick="testCategory('accreditation', 'carf')">Test Accreditation</button>
                    <input type="text" id="accreditation-query" placeholder="Search query" value="carf">
                    <span class="status-badge pending" id="status-accreditation">Pending</span>
                </div>
                <div class="results" id="results-accreditation"></div>
                <div class="test-category">
                    <button onclick="testCategory('membership', 'natsap')">Test Membership</button>
                    <input type="text" id="membership-query" placeholder="Search query" value="natsap">
                    <span class="status-badge pending" id="status-membership">Pending</span>
                </div>
                <div class="results" id="results-membership"></div>
                <div class="test-category">
                    <button onclick="testCategory('licensing', 'state')">Test Licensing</button>
                    <input type="text" id="licensing-query" placeholder="Search query" value="state">
                    <span class="status-badge pending" id="status-licensing">Pending</span>
                </div>
                <div class="results" id="results-licensing"></div>
                <div class="test-category">
                    <button onclick="testCategory('investor', 'capital')">Test Investor</button>
                    <input type="text" id="investor-query" placeholder="Search query" value="capital">
                    <span class="status-badge pending" id="status-investor">Pending</span>
                </div>
                <div class="results" id="results-investor"></div>
                <div class="test-category">
                    <button onclick="testCategory('operatingperiod', '199')">Test Operating Period</button>
                    <input type="text" id="operatingperiod-query" placeholder="Search query (e.g., 199)" value="199">
                    <span class="status-badge pending" id="status-operatingperiod">Pending</span>
                </div>
                <div class="results" id="results-operatingperiod"></div>
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
