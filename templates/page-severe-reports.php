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
$kop_sr_counts = function_exists('kop_ih_site_severe_counts') ? kop_ih_site_severe_counts() : array('all' => 0);
$kop_sr_state = strtoupper(preg_replace('/[^A-Za-z]/', '', (string) ($_GET['state'] ?? '')));
if (!isset($kop_sr_counts[$kop_sr_state]) || $kop_sr_state === 'ALL') $kop_sr_state = '';
$kop_sr_category = isset($kop_sr_categories[$_GET['category'] ?? '']) ? (string) $_GET['category'] : '';
$kop_sr_page = max(1, (int) ($_GET['pg'] ?? 1));
$kop_sr_per_page = 50;

// One more than a page, to know whether there is a next one without a second count.
$kop_sr_rows = function_exists('kop_ih_site_severe')
    ? kop_ih_site_severe($kop_sr_state, $kop_sr_category, $kop_sr_per_page + 1, ($kop_sr_page - 1) * $kop_sr_per_page) : array();
$kop_sr_has_next = count($kop_sr_rows) > $kop_sr_per_page;
$kop_sr_rows = array_slice($kop_sr_rows, 0, $kop_sr_per_page);

$kop_sr_tracker_slugs = function_exists('kop_state_inspection_page_map')
    ? array_values(kop_state_inspection_page_map()) : array();
$kop_sr_state_names = function_exists('kop_state_abbrev_to_name') ? kop_state_abbrev_to_name() : array();

$kop_sr_link = static function (array $change) use ($kop_sr_state, $kop_sr_category) {
    $args = array_filter(array_merge(array('state' => $kop_sr_state, 'category' => $kop_sr_category), $change), static function ($v) {
        return $v !== '' && $v !== null && $v !== 0;
    });
    return esc_url(add_query_arg($args, get_permalink()));
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
    <form class="kop-sr-filters" method="get" action="<?php echo esc_url(get_permalink()); ?>">
        <label>State
            <select name="state">
                <option value="">All states (<?php echo (int) $kop_sr_counts['all']; ?>)</option>
                <?php foreach ($kop_sr_counts as $kop_sr_code => $kop_sr_n) : if ($kop_sr_code === 'all') continue; ?>
                    <option value="<?php echo esc_attr($kop_sr_code); ?>"<?php selected($kop_sr_code, $kop_sr_state); ?>><?php echo esc_html(($kop_sr_state_names[$kop_sr_code] ?? $kop_sr_code) . ' (' . $kop_sr_n . ')'); ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Kind of harm
            <select name="category">
                <option value="">All kinds</option>
                <?php foreach ($kop_sr_categories as $kop_sr_key => $kop_sr_cat) : ?>
                    <option value="<?php echo esc_attr($kop_sr_key); ?>"<?php selected($kop_sr_key, $kop_sr_category); ?>><?php echo esc_html($kop_sr_cat['label']); ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <button type="submit" class="kop-home-btn">Show</button>
    </form>
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
            <blockquote class="kop-flagged-quote"><?php echo esc_html($kop_sr_row['excerpt']); ?></blockquote>
            <p class="kop-flagged-source">From the state's report<?php echo $kop_sr_row['state_label'] ? '. ' . esc_html($kop_sr_row['state_label']) : ''; ?><?php
                echo $kop_sr_row['standard'] ? '. Cited: ' . esc_html($kop_sr_row['standard']) : ''; ?><?php
                echo $kop_sr_row['corrected_on_site'] ? '. The state recorded it as corrected at the inspection' : ''; ?>.<?php
                echo strpos($kop_sr_row['excerpt'], ' [...] ') !== false ? ' "[...]" marks text left out between sentences.' : ''; ?></p>
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
    <?php else : ?>
    <section class="kop-home-help">
        <p><?php echo $kop_sr_counts['all'] > 0
            ? 'No severe reports match these filters.'
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
        listed: a citation, or a complaint the investigator upheld. Assaults by
        another child are not listed, and a child running away is listed only
        when it ended in a death or a serious injury.
        This page is not complete: it covers the states and reports reviewed so far,
        and a facility's absence here says nothing about its record. Every report we
        hold, flagged or not, is on the <a href="/inspection-reports/">state trackers</a>.</p>
    </section>

</div>

<?php
get_footer();
