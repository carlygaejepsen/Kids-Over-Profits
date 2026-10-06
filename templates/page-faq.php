<?php
/**
 * Template Name: Frequently Asked Questions
 * Description: The questions people ask Kids Over Profits most: what the
 * troubled teen industry is and what counts as part of it, where it came
 * from, survivors, politics, juvenile justice, red flags and how to help.
 *
 * The text is in js/data/pages/faq.json and is edited in wp-admin (KOP Data
 * Tools > Page Text); this file only lays it out. Every section with a
 * heading is a question: the headings make the list of questions at the top
 * and the FAQPage structured data for search engines. The answers lean on
 * the site's own history pages and link to them.
 * Published (it started as a draft so the owner could read every answer first).
 */

if (!defined('ABSPATH')) {
    exit;
}

$kop_faq_css = get_stylesheet_directory() . '/css/faq.css';
if (file_exists($kop_faq_css)) {
    wp_enqueue_style(
        'kop-faq',
        get_stylesheet_directory_uri() . '/css/faq.css',
        array('kop-colors'),
        filemtime($kop_faq_css)
    );
}

$kop_faq_sections = kop_page_text_sections('faq');
$kop_faq_questions = array_values(array_filter($kop_faq_sections, function ($s) {
    return trim((string) $s['heading']) !== '' && $s['style'] === 'plain';
}));

/* FAQPage structured data: the question and its answer as plain text. */
$kop_faq_ld = array();
foreach ($kop_faq_questions as $s) {
    $answer = trim(preg_replace('/\s+/u', ' ', wp_strip_all_tags(kop_page_text_body_html($s['body'], $s['style'], 'kop-faq'))));
    $kop_faq_ld[] = array(
        '@type'          => 'Question',
        'name'           => $s['heading'],
        'acceptedAnswer' => array('@type' => 'Answer', 'text' => $answer),
    );
}

get_header();
?>

<div class="kop-faq-page">

    <header class="kop-faq-header">
        <h1 class="kop-faq-title"><?php the_title(); ?></h1>
        <?php if (has_excerpt()) : ?>
            <p class="kop-faq-summary"><?php echo esc_html(get_the_excerpt()); ?></p>
        <?php endif; ?>
    </header>

    <?php kop_page_text_render('faq', array('only' => array('intro'))); ?>

    <?php if ($kop_faq_questions) : ?>
        <nav class="kop-faq-toc" aria-label="Questions on this page">
            <ol>
                <?php foreach ($kop_faq_questions as $s) : ?>
                    <li><a href="#kop-faq-<?php echo esc_attr($s['key']); ?>"><?php echo kop_page_text_inline($s['heading'], 'kop-faq'); ?></a></li>
                <?php endforeach; ?>
            </ol>
        </nav>
    <?php endif; ?>

    <?php
    while (have_posts()) :
        the_post();
        if (trim(get_the_content()) !== '') :
            ?>
            <div class="kop-faq-editor-content"><?php the_content(); ?></div>
            <?php
        endif;
    endwhile;

    kop_page_text_render('faq', array('skip' => array('intro')));
    ?>

    <?php if ($kop_faq_ld) : ?>
        <script type="application/ld+json"><?php echo wp_json_encode(array('@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => $kop_faq_ld), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG); ?></script>
    <?php endif; ?>

</div>

<?php
get_footer();
