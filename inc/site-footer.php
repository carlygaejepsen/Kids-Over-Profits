<?php
/**
 * Site footer, on every page.
 *
 * Replaces Kadence's customizer footer (a five-column row where only the last
 * column held the copyright, so the text sat squeezed against the right edge
 * straight on the gradient). This one is a solid panel with the site's name,
 * a few links, the Givebutter donate button and the copyright / open data
 * line. Every child template and every Kadence page ends in get_footer(), so
 * the donate button is on every page, including the ones that drop the
 * sidebar (admin tools, the map, the glossary, the document archive).
 */

if (!defined('ABSPATH')) {
    exit;
}

// Kadence adds its footer markup when its template-hooks.php loads, which is
// after this child file; take it off once both are in.
add_action('after_setup_theme', 'kop_site_footer_swap', 20);
function kop_site_footer_swap() {
    remove_action('kadence_footer', 'Kadence\footer_markup');
    add_action('kadence_footer', 'kop_site_footer_render');
}

add_action('wp_enqueue_scripts', 'kop_site_footer_enqueue', 20);
function kop_site_footer_enqueue() {
    $path = get_stylesheet_directory() . '/css/site-footer.css';
    if (file_exists($path)) {
        wp_enqueue_style('kop-site-footer', get_stylesheet_directory_uri() . '/css/site-footer.css', array('kop-colors'), filemtime($path));
    }
}

/** Footer links: slug => label, shown only when the page is published. */
function kop_site_footer_links() {
    return apply_filters('kop_site_footer_links', array(
        'report-abuse'      => 'Report abuse',
        'document-archive'  => 'Document archive',
        'glossary'          => 'Glossary',
        'open-data'         => 'Open data',
        'volunteer'         => 'Volunteer',
        'contact'           => 'Contact',
        'privacy-policy'    => 'Privacy policy',
        'terms-of-service'  => 'Terms of service',
    ));
}

function kop_site_footer_render() {
    $tagline = trim((string) get_bloginfo('description'));
    $donate  = get_page_by_path('donate');
    $donate_url = $donate && $donate->post_status === 'publish' ? get_permalink($donate) : '';
    ?>
<footer id="colophon" class="site-footer kop-site-footer" role="contentinfo">
    <div class="kop-site-footer__inner">
        <div class="kop-site-footer__grid">
            <div class="kop-site-footer__about">
                <p class="kop-site-footer__name"><a href="<?php echo esc_url(home_url('/')); ?>"><?php echo esc_html(get_bloginfo('name')); ?></a></p>
                <?php if ($tagline !== '') : ?>
                    <p class="kop-site-footer__tagline"><?php echo esc_html($tagline); ?></p>
                <?php endif; ?>
            </div>
            <nav class="kop-site-footer__nav" aria-label="Footer">
                <ul>
                    <?php foreach (kop_site_footer_links() as $slug => $label) :
                        $page = get_page_by_path($slug);
                        if (!$page || $page->post_status !== 'publish') {
                            continue;
                        } ?>
                        <li><a href="<?php echo esc_url(get_permalink($page)); ?>"><?php echo esc_html($label); ?></a></li>
                    <?php endforeach; ?>
                </ul>
            </nav>
            <div class="kop-site-footer__donate">
                <p class="kop-site-footer__heading">Support this work</p>
                <p>Kids Over Profits is a 501(c)(3) nonprofit. Donations are tax-deductible.</p>
                <?php kop_donate_widget('kop-site-footer__widget'); ?>
                <?php if ($donate_url) : ?>
                    <p class="kop-site-footer__more"><a href="<?php echo esc_url($donate_url); ?>">What your donation funds</a></p>
                <?php endif; ?>
            </div>
        </div>
        <div class="kop-site-footer__bottom">
            <p>&copy; <?php echo esc_html(wp_date('Y')); ?> <?php echo esc_html(get_bloginfo('name')); ?></p>
            <?php if (function_exists('kop_open_data_footer_line')) {
                kop_open_data_footer_line();
            } ?>
        </div>
    </div>
</footer><!-- #colophon -->
    <?php
}
