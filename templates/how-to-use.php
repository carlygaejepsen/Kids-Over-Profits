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
$kop_htu_report = (string) ($kop_htu['urgent']['report'] ?? '');
$kop_htu_help = (string) ($kop_htu['urgent']['help'] ?? '');

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
            <p class="kop-sm-standfirst">Kids Over Profits documents the troubled teen industry: the wilderness programs, therapeutic boarding schools, residential treatment centers, boot camps and religious reform schools that take young people away from home, the companies that own them, and the consultants and transport companies that fill them.</p>
            <p class="kop-htu-lede">Most of what is here comes from records: state inspection reports, court filings, government investigations, news coverage, and the programs' own brochures and handbooks. Records carry a link to their source wherever there is one. The pages below are grouped by what people usually come here to do.</p>
        </header>

        <?php if ($kop_htu_report !== '' || $kop_htu_help !== '') : ?>
            <aside class="kop-htu-urgent" aria-label="If someone is in danger now">
                <p><strong>If a young person is in danger in a program now:</strong>
                    <?php if ($kop_htu_report !== '') : ?><a href="<?php echo esc_url($kop_htu_report); ?>">Report abuse</a> lists who takes a report in each state<?php endif; ?><?php if ($kop_htu_report !== '' && $kop_htu_help !== '') : ?>, and <?php endif; ?><?php if ($kop_htu_help !== '') : ?><a href="<?php echo esc_url($kop_htu_help); ?>">Resources</a> lists crisis lines that answer around the clock<?php endif; ?>.</p>
            </aside>
        <?php endif; ?>

        <?php if (!empty($kop_htu['groups'])) : ?>
            <div class="kop-htu-groups">
                <?php foreach ($kop_htu['groups'] as $group) : ?>
                    <section class="kop-htu-group">
                        <h2><?php echo esc_html($group['title']); ?></h2>
                        <?php if (($group['intro'] ?? '') !== '') : ?>
                            <p class="kop-htu-group__intro"><?php echo esc_html($group['intro']); ?></p>
                        <?php endif; ?>
                        <?php kop_site_map_render_items($group['items'], 'kop-sm-list kop-htu-list'); ?>
                    </section>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <section class="kop-sm-section kop-htu-search" aria-labelledby="kop-htu-search-title">
            <h2 id="kop-htu-search-title">Finding something</h2>
            <ul class="kop-htu-tips">
                <li><strong>Search the whole site.</strong> Press <kbd>/</kbd> on any page, or use the search in the header. It looks through programs, companies, people, inspection reports, lawsuits, news and documents at once, and finds a program under any name it has used, so a program that has been renamed turns up under its old name too.
                    <button type="button" class="kop-htu-search__button" data-kop-open-search>Open search</button></li>
                <li><strong>Every program has its own page.</strong> It gathers what is on file about that program: the names it has gone by, the company that ran it, the state's inspection findings, lawsuits, news coverage, deaths, staff and documents. A program that changed its name is shown in one section per name, each with the records from its own years.</li>
                <?php if ($kop_htu_map !== '') : ?>
                    <li><strong>Every page, A to Z.</strong> The <a href="<?php echo esc_url($kop_htu_map); ?>">site map</a> lists every page of the site, and every program and company page from A to Z.</li>
                <?php endif; ?>
                <li><strong>Something wrong or missing?</strong> The flag button on each page sends an error report straight to us. To add what you know about a program, use <em>Add or correct a program</em> above.</li>
            </ul>
        </section>
    </div>
</article>
<?php
get_footer();
