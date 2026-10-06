<?php
/**
 * /how-to-use-this-site/: where to start (inc/how-to-use.php routes here with
 * $GLOBALS['kop_how_to_use'] = kop_how_to_use_data()). Styled with the site
 * map's panel (css/site-map.css) and css/how-to-use.css.
 */

if (!defined('ABSPATH')) {
    exit;
}

$kop_htu = isset($GLOBALS['kop_how_to_use']) && is_array($GLOBALS['kop_how_to_use'])
    ? $GLOBALS['kop_how_to_use']
    : array('groups' => array());
$kop_htu_map = function_exists('kop_site_map_url') ? kop_site_map_url() : '';

get_header();
?>
<article class="entry content-bg single-entry kop-site-map kop-how-to-use" data-kop-bug-feature="how-to-use" data-kop-bug-label="How to use this site">
    <div class="entry-content-wrap">

        <nav class="kop-sm-trail" aria-label="Breadcrumb">
            <ol>
                <li><a href="<?php echo esc_url(home_url('/')); ?>">Home</a></li>
                <li aria-current="page">How to use this site</li>
            </ol>
        </nav>

        <header class="entry-header kop-sm-header">
            <h1 class="entry-title">How to use this site</h1>
            <p class="kop-sm-standfirst">Kids Over Profits tracks the troubled teen industry: the programs, the companies that run them, what inspectors and courts found, and where to get help. Start with what you came to do.</p>
        </header>

        <?php if (!empty($kop_htu['groups'])) : ?>
            <div class="kop-htu-groups">
                <?php foreach ($kop_htu['groups'] as $group) : ?>
                    <section class="kop-htu-group">
                        <h2><?php echo esc_html($group['title']); ?></h2>
                        <?php kop_site_map_render_items($group['items'], 'kop-sm-list kop-htu-list'); ?>
                    </section>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <section class="kop-sm-section kop-htu-search" aria-labelledby="kop-htu-search-title">
            <h2 id="kop-htu-search-title">Finding something</h2>
            <ul class="kop-htu-tips">
                <li><strong>Search everything.</strong> Press <kbd>/</kbd> on any page, or use the search at the top, to look through every program, company, person, report and document. Programs also turn up under their former names.
                    <button type="button" class="kop-htu-search__button" data-kop-open-search>Open search</button></li>
                <li><strong>Every program has its own page.</strong> Open one from the directory or from search to see its inspections, lawsuits, news, staff and documents in one place.</li>
                <?php if ($kop_htu_map !== '') : ?>
                    <li><strong>Every page, listed.</strong> The <a href="<?php echo esc_url($kop_htu_map); ?>">site map</a> lists every page of the site, every program and every company, A to Z.</li>
                <?php endif; ?>
            </ul>
        </section>
    </div>
</article>
<?php
get_footer();
