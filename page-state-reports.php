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
							<div class="facility-report-container">
								<h1 class="state-reports-title"><?php the_title(); ?></h1>
								<?php the_content(); ?>

								<div id="last-updated" class="last-updated"></div>

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

