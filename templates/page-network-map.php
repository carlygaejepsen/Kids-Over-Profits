<?php
/**
 * Template Name: Network Map
 * Description: Interactive map of the connections between TTI facilities, the
 *              people who ran them, their owners and their trade groups.
 * Template Post Type: page
 *
 * The shell here is real HTML: toolbar, filter rail, drawer and legend all
 * exist before any script runs, so they are crawlable, styleable and usable
 * by a screen reader. Script fills the canvas and the lists inside the
 * drawer, and nothing else.
 *
 * The filter vocabulary comes from graph.json's meta block via
 * inc/network-map.php, so adding a chain to the board export adds a checkbox
 * here without anyone editing this file.
 */

get_header();

if (have_posts()) {
    the_post();
}

$kop_net_meta    = function_exists('kop_network_map_meta') ? kop_network_map_meta() : array();
$kop_net_kinds   = !empty($kop_net_meta['kinds']) ? $kop_net_meta['kinds'] : array();
$kop_net_cats    = !empty($kop_net_meta['categories']) ? $kop_net_meta['categories'] : array();
$kop_net_chains  = !empty($kop_net_meta['chains']) ? $kop_net_meta['chains'] : array();
$kop_net_regions = !empty($kop_net_meta['regions']) ? $kop_net_meta['regions'] : array();
$kop_net_counts  = !empty($kop_net_meta['counts']) ? $kop_net_meta['counts'] : array();
$kop_net_ready   = !empty($kop_net_kinds);

$kop_net_directory = function_exists('kop_facility_pages_page_url_by_template')
    ? kop_facility_pages_page_url_by_template('page-tti-program-index.php', '/tti-program-index/')
    : home_url('/tti-program-index/');
?>

<div class="kop-network" data-kop-bug-feature="network-map" data-kop-bug-label="Network Map">

	<header class="kop-network__intro">
		<h1 class="kop-network__title"><?php the_title(); ?></h1>

		<div class="kop-network__standfirst">
			<p>
				Every line on this map is a connection someone recorded: a person who
				worked at a programme, a company that bought another, a founder whose
				next venture opened under a different name. Hover a name to see who it
				touches, click to follow the trail from one to the next.
			</p>
			<p>
				This is research data, curated by the project from public records,
				survivor accounts and reporting. A connection here says that a
				relationship was recorded, and nothing more. It is not an allegation
				of wrongdoing against anyone named, and the absence of a connection
				means only that no one has documented one yet.
				<?php if ($kop_net_ready && !empty($kop_net_counts['nodes'])) : ?>
					<span class="kop-network__tally">
						Currently <?php echo esc_html(number_format_i18n((int) $kop_net_counts['nodes'])); ?>
						names and <?php echo esc_html(number_format_i18n((int) $kop_net_counts['edges'])); ?>
						connections.
					</span>
				<?php endif; ?>
			</p>
		</div>

		<?php
		// Anything an editor adds to the page body renders above the map.
		if (trim(get_the_content()) !== '') {
			echo '<div class="kop-network__editorial entry-content">';
			the_content();
			echo '</div>';
		}
		?>
	</header>

	<?php if (!$kop_net_ready) : ?>

		<p class="kop-network__unavailable">
			The map data has not been built yet. Run
			<code>node scripts/build-network-graph.js</code> and
			<code>node scripts/build-network-layout.js</code>, then deploy
			<code>js/data/network/</code>.
		</p>

	<?php else : ?>

		<noscript>
			<p class="kop-network__noscript">
				The map needs JavaScript to draw and explore. Without it, the
				<a href="<?php echo esc_url($kop_net_directory); ?>">facility directory</a>
				lists the same programmes with their ownership and history in plain
				text.
			</p>
		</noscript>

		<?php
		// Not hidden behind a script flag: if the map script fails to load at
		// all, the shell and its loading message still render and app.js's
		// timeout can swap in a link to the directory. A hidden shell would
		// leave a blank page with nothing to say.
		?>
		<div class="kop-network__app" id="kop-network-app" data-state="loading">

			<div class="kop-network__toolbar">

				<div class="kop-network__search" role="search">
					<label class="screen-reader-text" for="kop-network-search">Search names</label>
					<input
						type="search"
						id="kop-network-search"
						class="kop-network__search-input"
						placeholder="Search a person, programme or company"
						autocomplete="off"
						role="combobox"
						aria-expanded="false"
						aria-controls="kop-network-search-results"
						aria-autocomplete="list">
					<ul
						id="kop-network-search-results"
						class="kop-network__search-results"
						role="listbox"
						aria-label="Search results"
						hidden></ul>
				</div>

				<div class="kop-network__actions">
					<label class="kop-network__colour">
						<span>Colour by</span>
						<select id="kop-network-colour-mode" class="kop-network__select">
							<option value="kind" selected>What it is</option>
							<option value="chain">Who owns it</option>
						</select>
					</label>

					<button type="button" class="kop-network__button" id="kop-network-filters-toggle"
						aria-expanded="false" aria-controls="kop-network-rail">
						Filters
					</button>
					<button type="button" class="kop-network__button" id="kop-network-reset-view">
						Reset view
					</button>
					<button type="button" class="kop-network__button" id="kop-network-share">
						Copy link
					</button>
				</div>
			</div>

			<?php /* The chain of nodes the visitor has clicked through, newest last. */ ?>
			<nav class="kop-network__chain" id="kop-network-chain" aria-label="Your trail" hidden>
				<button type="button" class="kop-network__chain-home" id="kop-network-whole-map">
					Whole map
				</button>
				<ol class="kop-network__chain-list" id="kop-network-chain-list"></ol>
			</nav>

			<div class="kop-network__body">

				<aside class="kop-network__rail" id="kop-network-rail" aria-label="Filters">

					<div class="kop-network__rail-inner">

						<fieldset class="kop-network__group">
							<legend>Show</legend>
							<?php foreach ($kop_net_kinds as $kind) : ?>
								<label class="kop-network__check">
									<input type="checkbox" name="kop-network-kind" value="<?php echo esc_attr($kind); ?>" checked>
									<span><?php echo esc_html(kop_network_map_label($kind, 'kind')); ?></span>
									<?php if (!empty($kop_net_counts['kind_' . $kind])) : ?>
										<span class="kop-network__count"><?php echo esc_html(number_format_i18n((int) $kop_net_counts['kind_' . $kind])); ?></span>
									<?php endif; ?>
								</label>
							<?php endforeach; ?>
						</fieldset>

						<fieldset class="kop-network__group">
							<legend>Connections</legend>
							<?php foreach ($kop_net_cats as $cat) : ?>
								<label class="kop-network__check">
									<input type="checkbox" name="kop-network-category" value="<?php echo esc_attr($cat); ?>" checked>
									<span><?php echo esc_html(kop_network_map_label($cat, 'category')); ?></span>
									<?php if (!empty($kop_net_counts['edge_' . $cat])) : ?>
										<span class="kop-network__count"><?php echo esc_html(number_format_i18n((int) $kop_net_counts['edge_' . $cat])); ?></span>
									<?php endif; ?>
								</label>
							<?php endforeach; ?>
						</fieldset>

						<fieldset class="kop-network__group">
							<legend>Status</legend>
							<label class="kop-network__check">
								<input type="checkbox" name="kop-network-status" value="open" checked>
								<span>Open</span>
							</label>
							<label class="kop-network__check">
								<input type="checkbox" name="kop-network-status" value="closed" checked>
								<span>Closed or rebranded</span>
							</label>
							<label class="kop-network__check">
								<input type="checkbox" name="kop-network-status" value="unknown" checked>
								<span>Status unrecorded</span>
							</label>
							<label class="kop-network__check">
								<input type="checkbox" id="kop-network-natsap-only">
								<span>NATSAP members only</span>
							</label>
						</fieldset>

						<?php if ($kop_net_chains) : ?>
							<fieldset class="kop-network__group">
								<legend>Owner</legend>
								<label class="kop-network__check">
									<input type="checkbox" name="kop-network-chain" value="" checked>
									<span>No recorded owner</span>
								</label>
								<?php foreach ($kop_net_chains as $chain) : ?>
									<label class="kop-network__check">
										<input type="checkbox" name="kop-network-chain" value="<?php echo esc_attr($chain); ?>" checked>
										<span><?php echo esc_html($chain); ?></span>
									</label>
								<?php endforeach; ?>
							</fieldset>
						<?php endif; ?>

						<?php if ($kop_net_regions) : ?>
							<fieldset class="kop-network__group kop-network__group--collapsed">
								<legend>
									<button type="button" class="kop-network__legend-toggle"
										aria-expanded="false" aria-controls="kop-network-regions">
										Board grouping
									</button>
								</legend>
								<div id="kop-network-regions" hidden>
									<p class="kop-network__note">
										How the research board is laid out, not a statement of
										ownership. The two "Asst." frames are overflow space.
									</p>
									<?php foreach ($kop_net_regions as $region) : ?>
										<label class="kop-network__check">
											<input type="checkbox" name="kop-network-region" value="<?php echo esc_attr($region); ?>" checked>
											<span><?php echo esc_html($region); ?></span>
										</label>
									<?php endforeach; ?>
								</div>
							</fieldset>
						<?php endif; ?>

						<fieldset class="kop-network__group">
							<legend>Refine</legend>
							<label class="kop-network__range">
								<?php
								// Starts at zero, and zero reads as "any". A floor of one
								// would hide every unconnected name by default, and the
								// board records three: Judge Rotenberg Educational Center,
								// IECA and Accelerated Christian Education. Nobody has
								// documented a connection for them yet, which is not a
								// reason for the map to leave them out.
								?>
								<span>Minimum connections: <output id="kop-network-degree-out">any</output></span>
								<input type="range" id="kop-network-degree" min="0" max="10" step="1" value="0">
							</label>
							<label class="kop-network__check">
								<input type="checkbox" id="kop-network-cross-region">
								<span>Only connections that cross board groups</span>
							</label>
							<button type="button" class="kop-network__button kop-network__button--quiet" id="kop-network-reset-filters">
								Reset filters
							</button>
						</fieldset>

					</div>
				</aside>

				<div class="kop-network__stage" id="kop-network-stage">
					<canvas
						id="kop-network-canvas"
						class="kop-network__canvas"
						tabindex="0"
						role="application"
						aria-label="Network map. Use arrow keys to move between names, Enter to follow one, Escape to go back."></canvas>

					<p class="kop-network__loading" id="kop-network-loading">Loading the map...</p>

					<div class="kop-network__legend" id="kop-network-legend" aria-label="Legend"></div>

					<div class="kop-network__status" id="kop-network-status" role="status" aria-live="polite"></div>
				</div>

				<aside class="kop-network__drawer" id="kop-network-drawer" aria-label="Selected name" hidden>
					<button type="button" class="kop-network__drawer-close" id="kop-network-drawer-close" aria-label="Close">
						&times;
					</button>
					<div class="kop-network__drawer-body" id="kop-network-drawer-body"></div>
				</aside>

			</div>
		</div>

	<?php endif; ?>

</div>

<?php
get_footer();
