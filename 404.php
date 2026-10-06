<?php
/**
 * Page not found. Instead of a dead end: the pages the address looks like it
 * was after (kop_site_map_404_suggestions(): facility and company pages by
 * name, then pages and posts), a search box prefilled with the address's
 * words, the most used destinations and a link to the site map
 * (inc/site-map.php). Also used when a /facility/ or /operator/ slug matches
 * nothing, so an old or mistyped program address lands on the program.
 */

if (!defined('ABSPATH')) {
    exit;
}

$kop_nf_path = isset($_SERVER['REQUEST_URI']) ? (string) wp_unslash($_SERVER['REQUEST_URI']) : '';
$kop_nf_phrase = function_exists('kop_site_map_404_phrase') ? kop_site_map_404_phrase($kop_nf_path) : '';
$kop_nf_suggestions = ($kop_nf_phrase !== '' && function_exists('kop_site_map_404_suggestions'))
    ? kop_site_map_404_suggestions($kop_nf_phrase)
    : array();
$kop_nf_quick = function_exists('kop_site_map_quick_links') ? kop_site_map_quick_links() : array();

get_header();
?>
<article class="entry content-bg single-entry kop-not-found" data-kop-bug-feature="not-found" data-kop-bug-label="Page not found">
    <div class="entry-content-wrap">
        <header class="entry-header">
            <h1 class="entry-title">We could not find that page</h1>
            <p class="kop-not-found__lede">It may have moved, been renamed, or the address may be mistyped. Here is where it might be.</p>
        </header>

        <?php if ($kop_nf_suggestions) : ?>
            <section class="kop-not-found__suggestions" aria-labelledby="kop-nf-suggest-title">
                <h2 id="kop-nf-suggest-title">Did you mean</h2>
                <ul class="kop-sm-list">
                    <?php foreach ($kop_nf_suggestions as $s) : ?>
                        <li>
                            <a href="<?php echo esc_url($s['url']); ?>"><?php echo esc_html($s['title']); ?></a>
                            <?php if ($s['note'] !== '') : ?><span class="kop-sm-note"><?php echo esc_html($s['note']); ?></span><?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </section>
        <?php endif; ?>

        <form class="kop-sm-filter" role="search" action="<?php echo esc_url(home_url('/')); ?>" method="get">
            <label class="kop-sm-filter__label" for="kop-nf-search">Search the whole site</label>
            <div class="kop-sm-filter__row">
                <input id="kop-nf-search" class="kop-sm-filter__input" type="search" name="s"
                       value="<?php echo esc_attr($kop_nf_phrase); ?>" placeholder="A program, company, state, topic...">
                <button type="submit" class="kop-sm-filter__button">Search</button>
            </div>
        </form>

        <?php if ($kop_nf_quick) : ?>
            <h2>Most used</h2>
            <?php kop_site_map_render_quick_links($kop_nf_quick); ?>
        <?php endif; ?>

        <?php if (function_exists('kop_site_map_url')) : ?>
            <p class="kop-sm-footer">Or browse every page on the <a href="<?php echo esc_url(kop_site_map_url()); ?>">site map</a>.</p>
        <?php endif; ?>
    </div>
</article>
<?php
get_footer();
