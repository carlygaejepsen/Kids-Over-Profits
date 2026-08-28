<?php
/**
 * Template Name: State Reports
 * Description: Template for state inspection reports (CT, AZ, TX, etc.)
 * Template Post Type: page
 */

get_header();
?>

<div id="content" class="site-content">
	<div id="primary" class="content-area">
		<main id="main" class="site-main">
			<?php
			if ( have_posts() ) {
				while ( have_posts() ) {
					the_post();
					?>
					<div class="entry-content">
						<div class="state-reports-wrapper">
							<div class="facility-report-container" data-kop-bug-feature="state-reports/viewer" data-kop-bug-label="Inspection Report Viewer">
								<h1 class="state-reports-title"><?php the_title(); ?></h1>

								<?php
								// Resolve a state hub page from the report slug (e.g. "ut-reports" -> "Utah" -> "/utah/")
								$report_slug = get_post_field('post_name', get_post());
								$state_lookup = preg_replace('/-?reports?$/i', '', $report_slug);
								$state_hub_name = function_exists('kop_state_slug_to_name') ? kop_state_slug_to_name($state_lookup) : null;
								if ($state_hub_name) {
									$hub_slug = kop_state_slug($state_hub_name);
									$hub_page = get_page_by_path($hub_slug);
									if ($hub_page) {
										$hub_url = get_permalink($hub_page);
										?>
										<p class="state-hub-back-link" style="margin: 0.5em 0 1.5em; padding: 0.65em 1em; background: #f0f3fa; border-left: 4px solid #00004d; border-radius: 4px;">
											<a href="<?php echo esc_url($hub_url); ?>" style="color: #00004d; font-weight: 600; text-decoration: none;">
												← View the full <?php echo esc_html($state_hub_name); ?> state page (programs, news, lawsuits, legislation)
											</a>
										</p>
										<?php
									}
								}
								?>

								<?php the_content(); ?>

								<div id="last-updated" class="last-updated"></div>

								<nav id="alphabet-filter" class="alphabet-filter">
									<!-- JavaScript will populate this -->
								</nav>

								<div class="controls" data-kop-bug-feature="state-reports/search" data-kop-bug-label="Report Search &amp; Filters">
									<input type="text" id="searchInput" placeholder="Search all facilities...">
									<select id="sortBy">
										<option value="">Default Order (A-Z)</option>
										<option value="name">Sort by Name</option>
										<option value="violations-only">Facilities with Violations Only</option>
										<option value="violations-desc">Most Violations First</option>
										<option value="recent-inspection">Most Recent Inspection</option>
									</select>
									<label class="new-only-toggle">
										<input type="checkbox" id="newReportsOnly"> New reports only (last 30 days)
									</label>
									<button id="clearSearch" onclick="clearSearch()" style="display: none;">Clear Search</button>
								</div>

								<div id="report-container" class="report-list">
									<p class="loading-message">Loading report data...</p>
								</div>
							</div>
						</div>
					</div>
					<?php
				}
			}
			?>
		</main>
	</div>
</div>

<?php
get_footer();
?>

