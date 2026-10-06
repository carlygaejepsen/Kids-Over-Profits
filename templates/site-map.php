<?php
/**
 * /site-map/: every public page, for readers (inc/site-map.php routes here with
 * $GLOBALS['kop_site_map'] = kop_site_map_data()). The filter box and the A to
 * Z jumps are js/site-map.js; without it the page is a plain list of links.
 */

if (!defined('ABSPATH')) {
    exit;
}

$kop_sm = isset($GLOBALS['kop_site_map']) && is_array($GLOBALS['kop_site_map'])
    ? $GLOBALS['kop_site_map']
    : array('sections' => array(), 'quick' => array(), 'counts' => array());
$kop_sm_sections = (array) ($kop_sm['sections'] ?? array());
$kop_sm_counts = (array) ($kop_sm['counts'] ?? array());
$kop_sm_search = home_url('/');

get_header();
?>
<article class="entry content-bg single-entry kop-site-map" data-kop-bug-feature="site-map" data-kop-bug-label="Site map">
    <div class="entry-content-wrap">

        <nav class="kop-sm-trail" aria-label="Breadcrumb">
            <ol>
                <li><a href="<?php echo esc_url(home_url('/')); ?>">Home</a></li>
                <li aria-current="page">Site map</li>
            </ol>
        </nav>

        <header class="entry-header kop-sm-header">
            <h1 class="entry-title">Site map</h1>
            <p class="kop-sm-standfirst">Every public page of the site in one place. Type in the box to narrow the list, or search inside every record, report and document instead.<?php if (function_exists('kop_how_to_use_url')) : ?> New here? Read <a href="<?php echo esc_url(kop_how_to_use_url()); ?>">how to use this site</a>.<?php endif; ?></p>
        </header>

        <form class="kop-sm-filter" role="search" action="<?php echo esc_url($kop_sm_search); ?>" method="get" data-kop-sm-filter>
            <label class="kop-sm-filter__label" for="kop-sm-filter-input">Find a page</label>
            <div class="kop-sm-filter__row">
                <input id="kop-sm-filter-input" class="kop-sm-filter__input" type="search" name="s" autocomplete="off"
                       placeholder="A program, company, state, topic..." aria-describedby="kop-sm-filter-status">
                <button type="submit" class="kop-sm-filter__button">Search everything</button>
            </div>
            <p id="kop-sm-filter-status" class="kop-sm-filter__status" aria-live="polite"></p>
        </form>

        <?php if (!empty($kop_sm['quick'])) : ?>
            <section class="kop-sm-section kop-sm-section--quick" aria-labelledby="kop-sm-quick-title" data-kop-sm-section>
                <h2 id="kop-sm-quick-title">Most used</h2>
                <?php kop_site_map_render_quick_links($kop_sm['quick']); ?>
            </section>
        <?php endif; ?>

        <?php if ($kop_sm_sections) : ?>
            <nav class="kop-sm-contents" aria-label="On this page">
                <p class="kop-sm-contents__title">On this page</p>
                <ul>
                    <?php foreach ($kop_sm_sections as $key => $section) : ?>
                        <li><a href="#kop-sm-<?php echo esc_attr($key); ?>"><?php echo esc_html($section['title']); ?></a></li>
                    <?php endforeach; ?>
                </ul>
            </nav>
        <?php endif; ?>

        <?php foreach ($kop_sm_sections as $key => $section) :
            $count = 0;
            if ($section['letters']) {
                foreach ($section['letters'] as $items) $count += count($items);
            }
            ?>
            <section class="kop-sm-section kop-sm-section--<?php echo esc_attr($key); ?>" id="kop-sm-<?php echo esc_attr($key); ?>" data-kop-sm-section>
                <h2><?php echo esc_html($section['title']); ?><?php if ($count) : ?> <span class="kop-sm-count">(<?php echo esc_html(number_format_i18n($count)); ?>)</span><?php endif; ?></h2>
                <?php if ($section['intro'] !== '') : ?>
                    <p class="kop-sm-intro"><?php echo esc_html($section['intro']); ?></p>
                <?php endif; ?>

                <?php if ($section['letters']) : ?>
                    <nav class="kop-sm-letters" aria-label="<?php echo esc_attr($section['title'] . ': jump to a letter'); ?>">
                        <?php foreach (array_keys($section['letters']) as $letter) : ?>
                            <a href="#<?php echo esc_attr(kop_site_map_letter_id($key, (string) $letter)); ?>"><?php echo esc_html((string) $letter); ?></a>
                        <?php endforeach; ?>
                    </nav>
                    <?php foreach ($section['letters'] as $letter => $items) : ?>
                        <details class="kop-sm-letter" id="<?php echo esc_attr(kop_site_map_letter_id($key, (string) $letter)); ?>" data-kop-sm-group>
                            <summary><span class="kop-sm-letter__name"><?php echo esc_html((string) $letter); ?></span> <span class="kop-sm-count"><?php echo esc_html(number_format_i18n(count($items))); ?></span></summary>
                            <?php kop_site_map_render_items($items, 'kop-sm-list kop-sm-list--columns'); ?>
                        </details>
                    <?php endforeach; ?>
                <?php elseif ($key === 'posts') : ?>
                    <?php foreach ($section['items'] as $cat) : ?>
                        <details class="kop-sm-letter" data-kop-sm-group data-sm="<?php echo esc_attr(kop_site_map_fold($cat['title'])); ?>">
                            <summary><a href="<?php echo esc_url($cat['url']); ?>"><?php echo esc_html($cat['title']); ?></a> <span class="kop-sm-count"><?php echo esc_html($cat['note']); ?></span></summary>
                            <?php kop_site_map_render_items($cat['children']); ?>
                        </details>
                    <?php endforeach; ?>
                <?php else : ?>
                    <?php kop_site_map_render_items($section['items'], $key === 'hubs' ? 'kop-sm-list kop-sm-list--hubs' : 'kop-sm-list kop-sm-list--columns'); ?>
                <?php endif; ?>
            </section>
        <?php endforeach; ?>

        <p class="kop-sm-empty" hidden data-kop-sm-empty>No page title matches. Use <strong>Search everything</strong> to look inside every record, report and document.</p>

        <footer class="kop-sm-footer">
            <p>
                <?php echo esc_html(sprintf(
                    '%s pages, %s facility pages and %s company pages.',
                    number_format_i18n((int) ($kop_sm_counts['pages'] ?? 0)),
                    number_format_i18n((int) ($kop_sm_counts['facilities'] ?? 0)),
                    number_format_i18n((int) ($kop_sm_counts['operators'] ?? 0))
                )); ?>
                Press <kbd>/</kbd> on any page to search the whole site.
            </p>
        </footer>
    </div>
</article>
<?php
get_footer();
