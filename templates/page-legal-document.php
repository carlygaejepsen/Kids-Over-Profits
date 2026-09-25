<?php
/**
 * Template Name: Legal Document
 * Description: Case context and readable presentation for a page that
 * reproduces a court or panel document as images.
 */

if (!defined('ABSPATH')) {
    exit;
}

get_header();

while (have_posts()) :
    the_post();
    $kop_legal_id = get_the_ID();
    $kop_legal_case = function_exists('kop_legal_document_lawsuit')
        ? kop_legal_document_lawsuit($kop_legal_id)
        : null;
    $kop_legal_case_name = !empty($kop_legal_case['case_name'])
        ? (string) $kop_legal_case['case_name']
        : get_the_title();
    $kop_legal_source = function_exists('kop_legal_document_source_pdf')
        ? kop_legal_document_source_pdf($kop_legal_id)
        : '';
    $kop_legal_content = apply_filters('the_content', get_the_content(null, false, $kop_legal_id));
    $kop_legal_page_number = 0;
    $kop_legal_content = preg_replace_callback(
        '/<img\b[^>]*>/i',
        static function ($match) use (&$kop_legal_page_number) {
            $kop_legal_page_number++;
            $tag = $match[0];
            $alt = 'Opinion page ' . $kop_legal_page_number;
            if (preg_match('/\salt\s*=/i', $tag)) {
                $tag = preg_replace('/\salt\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', ' alt="' . esc_attr($alt) . '"', $tag, 1);
            } else {
                $tag = preg_replace('/<img\b/i', '<img alt="' . esc_attr($alt) . '"', $tag, 1);
            }
            return $tag;
        },
        $kop_legal_content
    );
    ?>
<article id="post-<?php the_ID(); ?>" <?php post_class('entry content-bg single-entry kop-legal-document'); ?>>
    <div class="entry-content-wrap">
        <nav class="kop-legal-document__breadcrumb" aria-label="Breadcrumb">
            <ol>
                <li><a href="<?php echo esc_url(home_url('/')); ?>">Home</a></li>
                <li><a href="<?php echo esc_url(home_url('/lawsuits/')); ?>">Lawsuits</a></li>
                <li aria-current="page"><?php echo esc_html($kop_legal_case_name); ?></li>
            </ol>
        </nav>

        <header class="entry-header kop-legal-document__header">
            <p class="kop-legal-document__eyebrow">Prelitigation panel opinion</p>
            <h1 class="entry-title"><?php echo esc_html($kop_legal_case_name); ?></h1>
            <p class="kop-legal-document__summary">This page reproduces the prelitigation panel opinion as page images.</p>
        </header>

        <?php if (is_array($kop_legal_case)) : ?>
            <dl class="kop-legal-document__metadata">
                <?php if (!empty($kop_legal_case['court'])) : ?>
                    <div><dt>Court</dt><dd><?php echo esc_html($kop_legal_case['court']); ?></dd></div>
                <?php endif; ?>
                <?php if (!empty($kop_legal_case['filing_date'])) : ?>
                    <div><dt>Case filed</dt><dd><time datetime="<?php echo esc_attr(substr((string) $kop_legal_case['filing_date'], 0, 10)); ?>"><?php echo esc_html(mysql2date(get_option('date_format'), $kop_legal_case['filing_date'])); ?></time></dd></div>
                <?php endif; ?>
                <?php foreach (array('plaintiffs' => 'Plaintiff', 'defendants' => 'Defendant') as $kop_party_field => $kop_party_label) :
                    $kop_parties = isset($kop_legal_case[$kop_party_field]) ? json_decode($kop_legal_case[$kop_party_field], true) : array();
                    if (!is_array($kop_parties) || !$kop_parties) {
                        continue;
                    }
                    ?>
                    <div><dt><?php echo esc_html(count($kop_parties) === 1 ? $kop_party_label : $kop_party_label . 's'); ?></dt><dd><?php echo esc_html(implode(', ', array_map('strval', $kop_parties))); ?></dd></div>
                <?php endforeach; ?>
                <?php if (!empty($kop_legal_case['id'])) : ?>
                    <div><dt>Related case</dt><dd><a href="<?php echo esc_url(home_url('/lawsuits/#lawsuit-' . (int) $kop_legal_case['id'])); ?>"><?php echo esc_html($kop_legal_case_name); ?></a></dd></div>
                <?php endif; ?>
            </dl>
        <?php endif; ?>

        <?php if ($kop_legal_source !== '') : ?>
            <p class="kop-legal-document__source"><a href="<?php echo esc_url($kop_legal_source); ?>">Download the source PDF</a></p>
        <?php endif; ?>

        <div class="entry-content single-content kop-legal-document__pages">
            <?php echo $kop_legal_content; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- rendered through the_content filters. ?>
        </div>

        <?php edit_post_link('Edit this page', '<footer class="kop-legal-document__footer">', '</footer>'); ?>
    </div>
</article>
    <?php
endwhile;

get_footer();
