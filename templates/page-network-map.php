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

// A password-protected page (how the map is shared with beta testers) gets
// WordPress's own password form and nothing else. This template prints its
// own markup instead of the_content(), so without this check the protection
// would never be asked for.
if (post_password_required()) {
    ?>
    <div class="kop-network kop-network--locked">
        <header class="kop-network__intro">
            <h1 class="kop-network__title"><?php the_title(); ?></h1>
        </header>
        <?php echo get_the_password_form(); // Core markup, escaped by core. ?>
    </div>
    <?php
    get_footer();
    return;
}

$kop_net_meta    = function_exists('kop_network_map_meta') ? kop_network_map_meta() : array();
$kop_net_kinds   = !empty($kop_net_meta['kinds']) ? $kop_net_meta['kinds'] : array();
$kop_net_cats    = !empty($kop_net_meta['categories']) ? $kop_net_meta['categories'] : array();
$kop_net_chains  = !empty($kop_net_meta['chains']) ? $kop_net_meta['chains'] : array();
$kop_net_regions = !empty($kop_net_meta['regions']) ? $kop_net_meta['regions'] : array();
$kop_net_counts  = !empty($kop_net_meta['counts']) ? $kop_net_meta['counts'] : array();
$kop_net_views   = !empty($kop_net_meta['views']) ? $kop_net_meta['views'] : array();
$kop_net_ready   = !empty($kop_net_kinds);

// The location index lists every facility; the program index only operators and chains.
$kop_net_directory = function_exists('kop_facility_pages_page_url_by_template')
    ? kop_facility_pages_page_url_by_template('page-location-index.php', '/location-index/')
    : home_url('/location-index/');
?>

<div class="kop-network" data-kop-bug-feature="network-map" data-kop-bug-label="Network Map">

	<header class="kop-network__intro">
		<h1 class="kop-network__title"><?php the_title(); ?></h1>

		<?php
		// Two sentences and no more: what the lines are, and what they are not.
		// How to use the map is said where it is used, in the trail strip.
		?>
		<p class="kop-network__standfirst">
			Connections on record between programmes, the people who ran them and
			the companies behind them. A line means a relationship was documented,
			not an allegation of wrongdoing.
		</p>

		<?php if ($kop_net_ready && !empty($kop_net_counts['nodes'])) : ?>
			<p class="kop-network__tally">
				<?php echo esc_html(number_format_i18n((int) $kop_net_counts['nodes'])); ?> names,
				<?php echo esc_html(number_format_i18n((int) $kop_net_counts['edges'])); ?> connections
			</p>
		<?php endif; ?>

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
					<?php
					// Where the map opens (2b.10). Only printed when the board
					// offers more than the default; the lists are curated under
					// "views" in js/data/network/network-overrides.json.
					if (count($kop_net_views) > 1) :
					?>
						<label class="kop-network__colour">
							<span>Start from</span>
							<select id="kop-network-view" class="kop-network__select">
								<?php foreach ($kop_net_views as $kop_net_view) : ?>
									<option value="<?php echo esc_attr($kop_net_view['key']); ?>"<?php selected($kop_net_view['key'], 'default'); ?>><?php echo esc_html($kop_net_view['label']); ?></option>
								<?php endforeach; ?>
							</select>
						</label>
					<?php endif; ?>

					<?php
					// What a click does. Focus shows the clicked node's own
					// connections and nothing else; Expand adds them to whatever
					// is already on the board. Radios rather than a checkbox so
					// both states have a name.
					?>
					<fieldset class="kop-network__mode">
						<legend class="screen-reader-text">What a click does</legend>
						<label class="kop-network__mode-option">
							<input type="radio" name="kop-network-mode" value="focus" checked>
							<span>Focus</span>
						</label>
						<label class="kop-network__mode-option">
							<input type="radio" name="kop-network-mode" value="expand">
							<span>Expand</span>
						</label>
					</fieldset>

					<?php
					// How two names are connected (path.js). The form opens under
					// the button and closes again once it has an answer, which is
					// read in the drawer, so nothing sits over the route it found.
					?>
					<div class="kop-network__path-wrap">
						<button type="button" class="kop-network__button" id="kop-network-path-toggle"
							aria-expanded="false" aria-controls="kop-network-path">
							Path
						</button>
						<div class="kop-network__path" id="kop-network-path" hidden>
							<form id="kop-network-path-form" class="kop-network__path-form" autocomplete="off">
								<p class="kop-network__path-title">How are two names connected?</p>
								<div class="kop-network__search kop-network__path-field">
									<label for="kop-network-path-from">From</label>
									<input type="search" id="kop-network-path-from" class="kop-network__search-input"
										placeholder="A person, programme or company" autocomplete="off"
										role="combobox" aria-expanded="false"
										aria-controls="kop-network-path-from-results" aria-autocomplete="list">
									<ul id="kop-network-path-from-results" class="kop-network__search-results"
										role="listbox" aria-label="Names matching the first end" hidden></ul>
								</div>
								<div class="kop-network__search kop-network__path-field">
									<label for="kop-network-path-to">To</label>
									<input type="search" id="kop-network-path-to" class="kop-network__search-input"
										placeholder="Another name" autocomplete="off"
										role="combobox" aria-expanded="false"
										aria-controls="kop-network-path-to-results" aria-autocomplete="list">
									<ul id="kop-network-path-to-results" class="kop-network__search-results"
										role="listbox" aria-label="Names matching the second end" hidden></ul>
								</div>
								<div class="kop-network__path-actions">
									<button type="submit" class="kop-network__button kop-network__path-find">Find routes</button>
									<button type="button" class="kop-network__button" id="kop-network-path-swap">Swap</button>
									<button type="button" class="kop-network__button" id="kop-network-path-close">Close</button>
								</div>
								<p class="kop-network__path-message" id="kop-network-path-message" role="status"></p>
							</form>
						</div>
					</div>

					<button type="button" class="kop-network__button" id="kop-network-share">
						Copy link
					</button>
				</div>
			</div>

			<?php
			// The chain of nodes the visitor has clicked through, newest last.
			// Always one row and always present: a strip that appeared on the
			// first click would shrink the stage and re-lay the map out under
			// the visitor's pointer. Before the first click it says what a
			// click does instead.
			?>
			<nav class="kop-network__chain" id="kop-network-chain" aria-label="Your trail">
				<span class="kop-network__chain-label" aria-hidden="true">Trail</span>
				<button type="button" class="kop-network__chain-home" id="kop-network-whole-map" hidden>
					Start over
				</button>
				<ol class="kop-network__chain-list" id="kop-network-chain-list"></ol>
				<p class="kop-network__chain-empty" id="kop-network-chain-empty">
					Click a name to start a trail. Each click adds a step here.
				</p>
			</nav>

			<div class="kop-network__body">

				<div class="kop-network__stage" id="kop-network-stage">
					<canvas
						id="kop-network-canvas"
						class="kop-network__canvas"
						tabindex="0"
						role="application"
						aria-label="Network map. Use arrow keys to move between names, Enter to follow one, Escape to go back."></canvas>

					<p class="kop-network__loading" id="kop-network-loading">Loading the map...</p>

					<div class="kop-network__status" id="kop-network-status" role="status" aria-live="polite"></div>

					<?php
					// The key folds into a corner of the stage rather than
					// taking a column beside it. Opening or closing it leaves
					// the stage the same size, so the map is not re-laid out
					// underneath it, and closed it costs one small button.
					// filters.js fills the legend with only what is on screen.
					$kop_net_kind_labels = array();
					foreach ($kop_net_kinds as $kind) {
						$kop_net_kind_labels[$kind] = kop_network_map_label($kind, 'kind');
					}
					?>
					<?php
					// Reset view and Full screen act on the stage, so they sit on
					// it, in the corner opposite the Key, the way a map's own
					// controls do. app.js owns the second one's label.
					?>
					<div class="kop-network__view-controls">
						<button type="button" class="kop-network__stage-button" id="kop-network-reset-view">
							Reset view
						</button>
						<button type="button" class="kop-network__stage-button kop-network__button--full" id="kop-network-fullscreen"
							aria-pressed="false">
							Full screen
						</button>
					</div>

					<div class="kop-network__key">
						<button type="button" class="kop-network__key-toggle" id="kop-network-filters-toggle"
							aria-expanded="false" aria-controls="kop-network-rail">
							Key
						</button>
						<div class="kop-network__rail" id="kop-network-rail" role="region" aria-label="Key" hidden>
							<?php
							// What the colours stand for is chosen where they are
							// explained. It used to be a fourth control in the
							// toolbar, which is what pushed a phone's toolbar onto a
							// third row.
							?>
							<label class="kop-network__colour kop-network__colour--key">
								<span>Colour by</span>
								<select id="kop-network-colour-mode" class="kop-network__select">
									<option value="kind" selected>What it is</option>
									<option value="chain">Who owns it</option>
								</select>
							</label>
							<div class="kop-network__group kop-network__legend" id="kop-network-legend"
								role="group" aria-label="Legend"
								data-kind-labels="<?php echo esc_attr(wp_json_encode($kop_net_kind_labels)); ?>"></div>
						</div>
					</div>
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
