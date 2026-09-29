<?php
/**
 * Template Name: Open Data
 * Description: Bulk downloads of everything the site publishes, for
 * advocates, researchers and journalists, and the open-source notice.
 *
 * The list comes from the manifest.json the daily build writes
 * (inc/open-data.php), so a dataset appears here as soon as it is built.
 * The page's own editor content, if any, is printed under the introduction,
 * so a note can be added in wp-admin without touching this file.
 */

if (!defined('ABSPATH')) {
    exit;
}

$kop_od_css = get_stylesheet_directory() . '/css/open-data.css';
if (file_exists($kop_od_css)) {
    wp_enqueue_style(
        'kop-open-data',
        get_stylesheet_directory_uri() . '/css/open-data.css',
        array('kop-colors'),
        filemtime($kop_od_css)
    );
}

get_header();

$kop_od_manifest = kop_open_data_manifest();
$kop_od_icon = static function ($name) {
    return function_exists('kop_icon') ? kop_icon($name) : '';
};

// Datasets under their group headings, in the order the build lists them.
$kop_od_groups = array();
foreach ((array) ($kop_od_manifest['datasets'] ?? array()) as $kop_od_key => $kop_od_set) {
    $kop_od_groups[$kop_od_set['group'] ?? 'Other'][$kop_od_key] = $kop_od_set;
}
$kop_od_built = !empty($kop_od_manifest['generated_at']) ? strtotime($kop_od_manifest['generated_at']) : 0;
?>

<div class="kop-od-page">

    <header class="kop-od-header">
        <h1 class="kop-od-title"><?php the_title(); ?></h1>
        <p class="kop-od-summary">
            <?php if (has_excerpt()) : ?>
                <?php echo esc_html(get_the_excerpt()); ?>
            <?php else : ?>
                Everything Kids Over Profits has gathered about the Troubled Teen Industry is free to download, reuse and build on.
            <?php endif; ?>
        </p>
    </header>

    <section class="kop-od-intro" aria-labelledby="kop-od-open-heading">
        <h2 id="kop-od-open-heading" class="kop-od-intro-title">Open source, open data</h2>
        <p>
            This work belongs to everyone fighting for kids in residential programs.
            Take the data to your legislator, your newsroom, your research or your own organizing.
        </p>
        <ul class="kop-od-terms">
            <li>
                <strong>Data and writing:</strong>
                <a href="<?php echo esc_url(KOP_OPEN_DATA_LICENSE_URL); ?>" rel="license">Creative Commons Attribution-ShareAlike 4.0</a>.
                Credit <em>Kids Over Profits (kidsoverprofits.org)</em>, say what you changed, and share what you build from it under the same license.
            </li>
            <li>
                <strong>Code:</strong> the whole site is open source under the GNU GPL v2 or later.
                <a href="<?php echo esc_url(KOP_OPEN_DATA_SOURCE_URL); ?>">See the source on GitHub</a>.
            </li>
            <li>
                <strong>Not ours to license:</strong> news articles, court filings, state inspection reports and photos belong to their authors or are public records. The downloads link to them rather than copy them, except the inspection report text below.
            </li>
        </ul>
    </section>

    <?php
    while (have_posts()) :
        the_post();
        if (trim(get_the_content()) !== '') :
            ?>
            <div class="kop-od-editor-content"><?php the_content(); ?></div>
            <?php
        endif;
    endwhile;
    ?>

    <?php if (!$kop_od_manifest || empty($kop_od_manifest['datasets'])) : ?>

        <p class="kop-od-pending">
            The downloads are being prepared. They are rebuilt every day; check back in a few minutes.
        </p>

    <?php else : ?>

        <section class="kop-od-bulk" aria-labelledby="kop-od-bulk-heading">
            <h2 id="kop-od-bulk-heading" class="kop-od-section-title">Download everything</h2>
            <div class="kop-od-bulk-grid">
                <?php if (!empty($kop_od_manifest['zip'])) : ?>
                    <a class="kop-od-bulk-card" href="<?php echo esc_url(kop_open_data_file_url($kop_od_manifest['zip']['name'])); ?>" download>
                        <?php echo $kop_od_icon('download'); ?>
                        <span class="kop-od-bulk-name">All datasets</span>
                        <span class="kop-od-bulk-meta">
                            ZIP, <?php echo esc_html(kop_open_data_size($kop_od_manifest['zip']['bytes'])); ?>.
                            Every dataset below as CSV and JSON, with a README.
                        </span>
                    </a>
                <?php endif; ?>
                <?php if (!empty($kop_od_manifest['full_text'])) : ?>
                    <a class="kop-od-bulk-card" href="<?php echo esc_url(kop_open_data_file_url($kop_od_manifest['full_text']['name'])); ?>" download>
                        <?php echo $kop_od_icon('file-text'); ?>
                        <span class="kop-od-bulk-name">Full text of every inspection report</span>
                        <span class="kop-od-bulk-meta">
                            <?php echo esc_html(number_format_i18n((int) $kop_od_manifest['full_text']['rows'])); ?> reports,
                            gzipped JSON Lines, <?php echo esc_html(kop_open_data_size($kop_od_manifest['full_text']['bytes'])); ?>.
                            One report per line, for text search and research.
                        </span>
                    </a>
                <?php endif; ?>
            </div>
            <p class="kop-od-built">
                <?php echo $kop_od_icon('refresh'); ?>
                Rebuilt every day. Last built
                <time datetime="<?php echo esc_attr(gmdate('c', $kop_od_built)); ?>"><?php echo esc_html(wp_date(get_option('date_format') . ', ' . get_option('time_format'), $kop_od_built)); ?></time>.
                Checksums are in <a href="<?php echo esc_url(kop_open_data_file_url('manifest.json')); ?>">manifest.json</a>.
            </p>
        </section>

        <?php foreach ($kop_od_groups as $kop_od_group => $kop_od_sets) : ?>
            <section class="kop-od-group" aria-label="<?php echo esc_attr($kop_od_group); ?>">
                <h2 class="kop-od-section-title"><?php echo esc_html($kop_od_group); ?></h2>
                <ul class="kop-od-list">
                    <?php foreach ($kop_od_sets as $kop_od_key => $kop_od_set) : ?>
                        <li class="kop-od-item" id="<?php echo esc_attr('dataset-' . $kop_od_key); ?>">
                            <div class="kop-od-item-text">
                                <h3 class="kop-od-item-title"><?php echo esc_html($kop_od_set['title']); ?></h3>
                                <p class="kop-od-item-desc"><?php echo esc_html($kop_od_set['description']); ?></p>
                                <p class="kop-od-item-count"><?php echo esc_html(number_format_i18n((int) $kop_od_set['rows'])); ?> rows</p>
                            </div>
                            <div class="kop-od-item-files">
                                <?php foreach ((array) $kop_od_set['files'] as $kop_od_file) : ?>
                                    <a class="kop-od-file" href="<?php echo esc_url(kop_open_data_file_url($kop_od_file['name'])); ?>" download>
                                        <?php echo $kop_od_icon('download'); ?>
                                        <span><?php echo esc_html(strtoupper($kop_od_file['format'])); ?></span>
                                        <span class="kop-od-file-size"><?php echo esc_html(kop_open_data_size($kop_od_file['bytes'])); ?></span>
                                    </a>
                                <?php endforeach; ?>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </section>
        <?php endforeach; ?>

    <?php endif; ?>

    <section class="kop-od-notes" aria-labelledby="kop-od-notes-heading">
        <h2 id="kop-od-notes-heading" class="kop-od-section-title">Good to know</h2>
        <ul>
            <li>
                <strong>What is left out, and why.</strong>
                Records still under review, anything a survivor has not agreed to publish, the names of survivors in news records,
                referrers' phone numbers and emails, and who submitted or reviewed each record.
            </li>
            <li>
                <strong>Joining the files.</strong>
                <code>facility_id</code> in the link, lawsuit and news files matches <code>id</code> in facilities.
                In the CSV files a list is written as <code>a; b; c</code>; the JSON files keep lists as lists and carry the full facility and operator records.
            </li>
            <li>
                <strong>Found a mistake or have more?</strong>
                <?php $kop_od_suggest = function_exists('kop_find_template_page_url') ? kop_find_template_page_url('page-data.php') : ''; ?>
                <?php if ($kop_od_suggest) : ?>
                    <a href="<?php echo esc_url($kop_od_suggest); ?>">Suggest an edit</a>, or open
                <?php else : ?>
                    Open
                <?php endif; ?>
                an issue or pull request on
                <a href="<?php echo esc_url(KOP_OPEN_DATA_SOURCE_URL); ?>">GitHub</a>.
            </li>
            <li>
                <strong>For developers.</strong>
                The files are at stable addresses, so a script can fetch them straight from
                <code><?php echo esc_html(kop_open_data_dir()['url'] . '/'); ?></code>; start with <code>manifest.json</code>.
            </li>
        </ul>
    </section>

</div>

<?php
get_footer();
