<?php
/**
 * Template Name: Severe Reports
 * Description: Every severe finding from the state inspection reports that a
 * person has reviewed and approved (api/review-inspection-highlights.php), most
 * recent first. The home page and the inspection reports hub show only the
 * newest few; this is the whole list, and the state trackers flag the same
 * reports in their feeds and link here.
 *
 * Every quote is the state's own wording. Nothing a parser guessed is printed:
 * a finding is on this page only once an admin has read the report and
 * approved it. The page's editor content, if any, is printed above the list.
 */

if (!defined('ABSPATH')) {
    exit;
}

foreach (array('kop-home' => '/css/home.css', 'kop-severe-reports' => '/css/severe-reports.css') as $kop_sr_handle => $kop_sr_css) {
    if (file_exists(get_stylesheet_directory() . $kop_sr_css)) {
        wp_enqueue_style($kop_sr_handle, get_stylesheet_directory_uri() . $kop_sr_css, array('kop-colors'), filemtime(get_stylesheet_directory() . $kop_sr_css));
    }
}

get_header();

$kop_sr_categories = function_exists('kop_ih_categories') ? kop_ih_categories() : array();
$kop_sr_tally = function_exists('kop_ih_site_severe_tally') ? kop_ih_site_severe_tally() : array('all' => array('all' => 0));
$kop_sr_counts = array('all' => $kop_sr_tally['all']['all']);
$kop_sr_state = strtoupper(preg_replace('/[^A-Za-z]/', '', (string) ($_GET['state'] ?? '')));
if (!isset($kop_sr_tally[$kop_sr_state]) || $kop_sr_state === 'ALL') $kop_sr_state = '';
// category=all lists every kind; no category shows the kind tiles instead of the list.
$kop_sr_category_arg = (string) ($_GET['category'] ?? '');
$kop_sr_category = isset($kop_sr_categories[$kop_sr_category_arg]) ? $kop_sr_category_arg : '';
$kop_sr_listing = $kop_sr_category !== '' || $kop_sr_category_arg === 'all';
$kop_sr_scope = $kop_sr_tally[$kop_sr_state !== '' ? $kop_sr_state : 'all'];
$kop_sr_page = max(1, (int) ($_GET['pg'] ?? 1));
$kop_sr_per_page = 50;

// One more than a page, to know whether there is a next one without a second count.
$kop_sr_rows = $kop_sr_listing && function_exists('kop_ih_site_severe')
    ? kop_ih_site_severe($kop_sr_state, $kop_sr_category, $kop_sr_per_page + 1, ($kop_sr_page - 1) * $kop_sr_per_page) : array();
$kop_sr_has_next = count($kop_sr_rows) > $kop_sr_per_page;
$kop_sr_rows = array_slice($kop_sr_rows, 0, $kop_sr_per_page);

$kop_sr_tracker_slugs = function_exists('kop_state_inspection_page_map')
    ? array_values(kop_state_inspection_page_map()) : array();
$kop_sr_state_names = function_exists('kop_state_abbrev_to_name') ? kop_state_abbrev_to_name() : array();

$kop_sr_link = static function (array $change) use ($kop_sr_state, $kop_sr_category, $kop_sr_listing) {
    $current = $kop_sr_listing && $kop_sr_category === '' ? 'all' : $kop_sr_category;
    $args = array_filter(array_merge(array('state' => $kop_sr_state, 'category' => $current), $change), static function ($v) {
        return $v !== '' && $v !== null && $v !== 0;
    });
    return esc_url(add_query_arg($args, get_permalink()));
};

// States with approved findings, and every tracked state without one (shown
// greyed, so a reader can see it is covered), in name order.
$kop_sr_states = array();
foreach ($kop_sr_tally as $kop_sr_code => $kop_sr_by_kind) {
    if ($kop_sr_code !== 'all') $kop_sr_states[$kop_sr_code] = $kop_sr_by_kind['all'];
}
foreach ($kop_sr_tracker_slugs as $kop_sr_slug) {
    if (preg_match('/^([a-z]{2})-reports$/', $kop_sr_slug, $kop_sr_m) && !isset($kop_sr_states[strtoupper($kop_sr_m[1])])) {
        $kop_sr_states[strtoupper($kop_sr_m[1])] = 0;
    }
}
uksort($kop_sr_states, static function ($a, $b) use ($kop_sr_state_names) {
    return strcmp($kop_sr_state_names[$a] ?? $a, $kop_sr_state_names[$b] ?? $b);
});
$kop_sr_state_label = $kop_sr_state !== '' ? ($kop_sr_state_names[$kop_sr_state] ?? $kop_sr_state) : 'All states';
$kop_sr_has_tracker = $kop_sr_state !== '' && in_array(strtolower($kop_sr_state) . '-reports', $kop_sr_tracker_slugs, true);

// Each kind's tile: icon (inc/icons.php) and what it covers.
$kop_sr_kind_notes = array(
    'death'            => array('candle', 'A child died in care or after an incident there.'),
    'sexual_abuse'     => array('shield', 'Sexual abuse, assault or exploitation, and sexual contact by an adult.'),
    'physical_abuse'   => array('alert-triangle', 'Staff hitting, choking, kicking or otherwise assaulting a child.'),
    'restraint_injury' => array('lock', 'Holds, restraints and seclusion that left a child hurt.'),
    'suicide_attempt'  => array('life-buoy', 'A child tried to end their life.'),
    'self_harm'        => array('life-buoy', 'Self-harm: cutting, swallowing objects, overdoses.'),
    'medical_neglect'  => array('clipboard', 'Care withheld or delayed, and repeated medication errors.'),
    'hospitalization'  => array('hospital', 'A child taken to the hospital or emergency room.'),
    'missing'          => array('user-x', 'A child who ran away or went missing and died or was badly hurt.'),
    'police'           => array('siren', 'Police called, or a staff member arrested or charged.'),
);
$kop_sr_icon = static function ($name) {
    return function_exists('kop_icon') ? kop_icon($name) : '';
};
?>

<div class="kop-home kop-severe-reports">

    <section class="kop-home-hero">
        <h1 class="entry-title">Severe reports</h1>
        <p>The most serious findings in the state inspection reports we hold: deaths,
        sexual and physical abuse, restraints that injured a child, suicide attempts,
        medical neglect and hospitalisations. Every quote below is the licensing
        agency's own wording, and each one was read against its report by a person
        before it was listed here.</p>
    </section>

    <?php
    $kop_sr_content = trim(apply_filters('the_content', get_the_content(null, false, get_the_ID())));
    if ($kop_sr_content !== '') :
    ?>
    <section class="kop-home-mission"><?php echo $kop_sr_content; // phpcs:ignore WordPress.Security.EscapeOutput ?></section>
    <?php endif; ?>

    <?php if ($kop_sr_counts['all'] > 0) : ?>
    <nav class="kop-sr-crumbs" aria-label="Where you are">
        <?php if ($kop_sr_state !== '' || $kop_sr_listing) : ?>
            <a href="<?php echo esc_url(get_permalink()); ?>">All states</a>
        <?php else : ?>
            <span aria-current="page">All states</span>
        <?php endif; ?>
        <?php if ($kop_sr_state !== '') : ?>
            <span class="kop-sr-crumb-sep" aria-hidden="true">/</span>
            <?php if ($kop_sr_listing) : ?>
                <a href="<?php echo $kop_sr_link(array('category' => '', 'pg' => '')); // escaped in the closure ?>"><?php echo esc_html($kop_sr_state_label); ?></a>
            <?php else : ?>
                <span aria-current="page"><?php echo esc_html($kop_sr_state_label); ?></span>
            <?php endif; ?>
        <?php endif; ?>
        <?php if ($kop_sr_listing) : ?>
            <span class="kop-sr-crumb-sep" aria-hidden="true">/</span>
            <span aria-current="page"><?php echo esc_html($kop_sr_category !== '' ? $kop_sr_categories[$kop_sr_category]['label'] : 'Every kind of harm'); ?></span>
        <?php endif; ?>
    </nav>

    <?php if ($kop_sr_state === '' && !$kop_sr_listing) : ?>
    <section class="kop-sr-step" aria-labelledby="kop-sr-states-h">
        <h2 id="kop-sr-states-h">Choose a state</h2>
        <ul class="kop-sr-states">
            <?php foreach ($kop_sr_states as $kop_sr_code => $kop_sr_n) : ?>
            <li>
                <?php if ($kop_sr_n > 0) : ?>
                <a class="kop-sr-state" href="<?php echo $kop_sr_link(array('state' => $kop_sr_code)); ?>">
                <?php else : ?>
                <span class="kop-sr-state is-empty">
                <?php endif; ?>
                    <span class="kop-sr-state-code"><?php echo esc_html($kop_sr_code); ?></span>
                    <span class="kop-sr-state-name"><?php echo esc_html($kop_sr_state_names[$kop_sr_code] ?? $kop_sr_code); ?></span>
                    <span class="kop-sr-count"><?php echo $kop_sr_n > 0
                        ? esc_html(number_format($kop_sr_n) . ($kop_sr_n === 1 ? ' report' : ' reports')) : 'None approved yet'; ?></span>
                <?php echo $kop_sr_n > 0 ? '</a>' : '</span>'; ?>
            </li>
            <?php endforeach; ?>
        </ul>
    </section>
    <?php endif; ?>

    <?php if (!$kop_sr_listing) : ?>
    <section class="kop-sr-step" aria-labelledby="kop-sr-kinds-h">
        <h2 id="kop-sr-kinds-h"><?php echo $kop_sr_state !== ''
            ? esc_html($kop_sr_state_label . ': ' . number_format($kop_sr_scope['all']) . ' severe ' . ($kop_sr_scope['all'] === 1 ? 'report' : 'reports') . ' by kind of harm')
            : 'Or see every state by kind of harm'; ?></h2>
        <p class="kop-sr-step-note">One report can describe more than one kind of harm, so it can be counted under more than one.</p>
        <ul class="kop-sr-kinds">
            <?php foreach ($kop_sr_categories as $kop_sr_key => $kop_sr_cat) :
                $kop_sr_n = (int) ($kop_sr_scope[$kop_sr_key] ?? 0);
                $kop_sr_note = $kop_sr_kind_notes[$kop_sr_key] ?? array('alert-circle', '');
            ?>
            <li>
                <?php if ($kop_sr_n > 0) : ?>
                <a class="kop-sr-kind" href="<?php echo $kop_sr_link(array('category' => $kop_sr_key)); ?>">
                <?php else : ?>
                <span class="kop-sr-kind is-empty">
                <?php endif; ?>
                    <span class="kop-sr-kind-icon"><?php echo $kop_sr_icon($kop_sr_note[0]); // SVG from kop_icon() ?></span>
                    <span class="kop-sr-kind-text">
                        <span class="kop-sr-kind-name"><?php echo esc_html($kop_sr_cat['label']); ?></span>
                        <?php if ($kop_sr_note[1] !== '') : ?><span class="kop-sr-kind-note"><?php echo esc_html($kop_sr_note[1]); ?></span><?php endif; ?>
                    </span>
                    <span class="kop-sr-count"><?php echo esc_html(number_format($kop_sr_n)); ?></span>
                <?php echo $kop_sr_n > 0 ? '</a>' : '</span>'; ?>
            </li>
            <?php endforeach; ?>
            <li>
                <a class="kop-sr-kind kop-sr-kind-all" href="<?php echo $kop_sr_link(array('category' => 'all')); ?>">
                    <span class="kop-sr-kind-icon"><?php echo $kop_sr_icon('file-text'); ?></span>
                    <span class="kop-sr-kind-text">
                        <span class="kop-sr-kind-name">Every kind</span>
                        <span class="kop-sr-kind-note">Every severe report<?php echo $kop_sr_state !== '' ? ' in ' . esc_html($kop_sr_state_label) : ''; ?>, newest first.</span>
                    </span>
                    <span class="kop-sr-count"><?php echo esc_html(number_format($kop_sr_scope['all'])); ?></span>
                </a>
            </li>
        </ul>
        <?php if ($kop_sr_has_tracker) : ?>
        <p class="kop-sr-step-note">Every report we hold for <?php echo esc_html($kop_sr_state_label); ?>, flagged or not, is on the
            <a href="/<?php echo esc_attr(strtolower($kop_sr_state)); ?>-reports/"><?php echo esc_html($kop_sr_state); ?> tracker</a>.</p>
        <?php endif; ?>
    </section>
    <?php endif; ?>

    <?php if ($kop_sr_listing) : ?>
    <div class="kop-sr-listhead">
        <h2><?php echo esc_html(($kop_sr_category !== '' ? $kop_sr_categories[$kop_sr_category]['label'] : 'Every severe report')
            . ($kop_sr_state !== '' ? ' in ' . $kop_sr_state_label : ', all states')); ?></h2>
        <ul class="kop-sr-switch" aria-label="Other kinds of harm">
            <li><a href="<?php echo $kop_sr_link(array('category' => 'all', 'pg' => '')); ?>"<?php echo $kop_sr_category === '' ? ' aria-current="page"' : ''; ?>>Every kind (<?php echo (int) $kop_sr_scope['all']; ?>)</a></li>
            <?php foreach ($kop_sr_categories as $kop_sr_key => $kop_sr_cat) : $kop_sr_n = (int) ($kop_sr_scope[$kop_sr_key] ?? 0); if ($kop_sr_n < 1) continue; ?>
            <li><a href="<?php echo $kop_sr_link(array('category' => $kop_sr_key, 'pg' => '')); ?>"<?php echo $kop_sr_key === $kop_sr_category ? ' aria-current="page"' : ''; ?>><?php echo esc_html($kop_sr_cat['label'] . ' (' . $kop_sr_n . ')'); ?></a></li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php endif; ?>
    <?php endif; ?>

    <?php if ($kop_sr_rows) : ?>
    <section class="kop-sr-list">
        <?php foreach ($kop_sr_rows as $kop_sr_row) :
            $kop_sr_tracker = strtolower($kop_sr_row['state']) . '-reports';
            $kop_sr_date = $kop_sr_row['finding_date']
                ? date_i18n('F j, Y', strtotime($kop_sr_row['finding_date'] . ' 12:00:00')) : trim((string) $kop_sr_row['report_date']);
            $kop_sr_source = kop_ih_source_url($kop_sr_row);
        ?>
        <article class="kop-sr-finding" id="finding-<?php echo (int) $kop_sr_row['id']; ?>">
            <header>
                <h2><?php echo esc_html($kop_sr_row['facility_name']); ?>
                    <span class="kop-flagged-state"><?php echo esc_html($kop_sr_row['state']); ?></span></h2>
                <div class="kop-sr-meta">
                    <?php if ($kop_sr_date !== '') : ?><span>Inspected <?php echo esc_html($kop_sr_date); ?></span><?php endif; ?>
                    <?php foreach (explode(',', (string) $kop_sr_row['categories']) as $kop_sr_key) : if (isset($kop_sr_categories[$kop_sr_key])) : ?>
                        <a class="kop-sr-pill" href="<?php echo $kop_sr_link(array('category' => $kop_sr_key)); // escaped in the closure ?>"><?php echo esc_html($kop_sr_categories[$kop_sr_key]['label']); ?></a>
                    <?php endif; endforeach; ?>
                </div>
            </header>
            <blockquote class="kop-flagged-quote"><?php echo kop_ih_excerpt_html($kop_sr_row['excerpt']); // escaped in the helper ?></blockquote>
            <p class="kop-flagged-source">From the state's report<?php echo $kop_sr_row['state_label'] ? '. ' . esc_html($kop_sr_row['state_label']) : ''; ?><?php
                echo $kop_sr_row['standard'] ? '. Cited: ' . esc_html($kop_sr_row['standard']) : ''; ?><?php
                echo $kop_sr_row['corrected_on_site'] ? '. The state recorded it as corrected at the inspection' : ''; ?>.<?php
                echo strpos($kop_sr_row['excerpt'], ' [...] ') !== false ? ' "[...]" marks text left out between sentences.' : ''; ?><?php
                echo kop_ih_reader_labels($kop_sr_row['excerpt']) !== (string) $kop_sr_row['excerpt'] ? ' The state numbers or abbreviates the people in a report instead of naming them; we spell its codes out in square brackets, such as [Staff 1] for S1 or [Former client 3] for FC #3.' : ''; ?></p>
            <div class="kop-flagged-links">
                <?php if ($kop_sr_source !== '') : ?>
                    <a href="<?php echo esc_url($kop_sr_source); ?>" target="_blank" rel="noopener noreferrer">State source</a>
                <?php endif; ?>
                <?php if (in_array($kop_sr_tracker, $kop_sr_tracker_slugs, true)) : ?>
                    <a href="/<?php echo esc_attr($kop_sr_tracker); ?>/"><?php echo esc_html($kop_sr_row['state']); ?> tracker: every report for this state</a>
                <?php endif; ?>
            </div>
        </article>
        <?php endforeach; ?>
    </section>

    <p class="kop-sr-pager">
        <?php if ($kop_sr_page > 1) : ?><a href="<?php echo $kop_sr_link(array('pg' => $kop_sr_page - 1)); ?>">Newer</a><?php endif; ?>
        <?php if ($kop_sr_has_next) : ?><a href="<?php echo $kop_sr_link(array('pg' => $kop_sr_page + 1)); ?>">Older</a><?php endif; ?>
    </p>
    <?php elseif ($kop_sr_listing || $kop_sr_counts['all'] < 1) : ?>
    <section class="kop-home-help">
        <p><?php echo $kop_sr_counts['all'] > 0
            ? 'No severe reports of this kind have been approved here yet.'
            : 'Reports are being reviewed. Findings appear here as each one is confirmed against its report.'; ?>
        The full record for every state is on the <a href="/inspection-reports/">inspection reports</a> page.</p>
    </section>
    <?php endif; ?>

    <section class="kop-home-help">
        <h2>How a report gets on this page</h2>
        <p>A program reads every inspection report we collect and picks out findings
        that describe serious harm, using the state's own severity rating or
        complaint outcome where there is one. A person then reads the report and
        approves or rejects each one. Only findings the state substantiated are
        listed: a citation, or a complaint the investigator upheld. A child
        running away is listed only when it ended in a death or a serious injury.
        This page is not complete: it covers the states and reports reviewed so far,
        and a facility's absence here says nothing about its record. Every report we
        hold, flagged or not, is on the <a href="/inspection-reports/">state trackers</a>.</p>
    </section>

</div>

<?php
get_footer();
