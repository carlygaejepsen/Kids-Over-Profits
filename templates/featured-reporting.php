<?php
/**
 * /essential-reporting/: the articles picked as essential reporting, newest
 * article first, grouped by year (inc/featured-reporting.php routes here with
 * $GLOBALS['kop_featured_page'] = kop_feat_page_data()). Styled by
 * css/featured-reporting.css on the site map's panel (css/site-map.css).
 */

if (!defined('ABSPATH')) {
    exit;
}

$kop_feat = isset($GLOBALS['kop_featured_page']) && is_array($GLOBALS['kop_featured_page'])
    ? $GLOBALS['kop_featured_page']
    : array('items' => array(), 'years' => array(), 'news_url' => home_url('/tti-news-feed/'));
$kop_feat_t = static function ($key, $default, $label) {
    return function_exists('kop_text') ? kop_text($key, $default, $label) : $default;
};
$kop_feat_a = static function ($key, $label) {
    return function_exists('kop_text_attr') ? kop_text_attr($key, $label) : '';
};
$kop_feat_intro_default = 'Some reporting on the troubled teen industry stands apart: reporters who spent months on records requests, found the families and former staff, followed the money to the companies behind a program, or kept at a story until a program closed or a law changed. These are the articles we send people to first. Each one links to the original, with an archived copy where there is one, and a note from us on why it matters.';
$kop_feat_by_year = array();
foreach ($kop_feat['items'] as $kop_feat_it) {
    $kop_feat_y = substr((string) $kop_feat_it['date'], 0, 4);
    $kop_feat_by_year[preg_match('/^\d{4}$/', $kop_feat_y) ? $kop_feat_y : 'Undated'][] = $kop_feat_it;
}
$kop_feat_is_admin = function_exists('current_user_can') && current_user_can('manage_options');

get_header();
?>
<article class="entry content-bg single-entry kop-site-map kop-feat-page" data-kop-bug-feature="essential-reporting" data-kop-bug-label="Essential reporting">
    <div class="entry-content-wrap">

        <nav class="kop-sm-trail" aria-label="Breadcrumb">
            <ol>
                <li><a href="<?php echo esc_url(home_url('/')); ?>">Home</a></li>
                <li><a href="<?php echo esc_url($kop_feat['news_url']); ?>">News</a></li>
                <li aria-current="page"><?php echo esc_html(kop_feat_label()); ?></li>
            </ol>
        </nav>

        <header class="entry-header kop-sm-header">
            <h1 class="entry-title"<?php echo $kop_feat_a('feat:page-title', 'Essential reporting page: title'); ?>><?php echo esc_html($kop_feat_t('feat:page-title', kop_feat_label(), 'Essential reporting page: title')); ?></h1>
            <p class="kop-sm-standfirst"<?php echo $kop_feat_a('feat:page-intro', 'Essential reporting page: introduction'); ?>><?php echo esc_html($kop_feat_t('feat:page-intro', $kop_feat_intro_default, 'Essential reporting page: introduction')); ?></p>
            <p class="kop-feat-page__more">Everything else we have collected is in the <a href="<?php echo esc_url($kop_feat['news_url']); ?>">news feed</a>.<?php if ($kop_feat_is_admin) : ?> Pick an article with the pencil on its headline in the news feed or on a program's page, or under <a href="<?php echo esc_url(admin_url('admin.php?page=kop-essential-reporting')); ?>">KOP Tools &gt; Essential Reporting</a>.<?php endif; ?></p>
        </header>

        <?php if (!$kop_feat['items']) : ?>
            <p class="kop-feat-page__empty">Nothing has been picked yet.</p>
        <?php else : ?>
            <?php if (count($kop_feat_by_year) > 1) : ?>
                <nav class="kop-feat-page__years" aria-label="Years">
                    <?php foreach ($kop_feat_by_year as $kop_feat_y => $kop_feat_list) : ?>
                        <a href="#y-<?php echo esc_attr(strtolower($kop_feat_y)); ?>"><?php echo esc_html($kop_feat_y); ?> <span>(<?php echo count($kop_feat_list); ?>)</span></a>
                    <?php endforeach; ?>
                </nav>
            <?php endif; ?>
            <?php foreach ($kop_feat_by_year as $kop_feat_y => $kop_feat_list) : ?>
                <section class="kop-feat-page__year" id="y-<?php echo esc_attr(strtolower($kop_feat_y)); ?>">
                    <h2><?php echo esc_html($kop_feat_y); ?></h2>
                    <div class="kop-feat-page__list">
                        <?php foreach ($kop_feat_list as $kop_feat_it) echo kop_feat_card_html($kop_feat_it, 'h3'); ?>
                    </div>
                </section>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</article>
<?php
get_footer();
