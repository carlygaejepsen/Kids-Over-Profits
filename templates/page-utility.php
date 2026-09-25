<?php
/**
 * Template Name: Utility Page
 * Description: Shared frame for pages whose forms, widgets and link cards live
 * in editor content and must continue to render through the_content().
 */

if (!defined('ABSPATH')) {
    exit;
}

get_header();

while (have_posts()) :
    the_post();
    $kop_utility = function_exists('kop_utility_config')
        ? kop_utility_config((string) get_post_field('post_name', get_the_ID()))
        : array();
    $kop_utility_standfirst = isset($kop_utility['standfirst'])
        ? trim((string) $kop_utility['standfirst'])
        : '';
    if ($kop_utility_standfirst === '' && has_excerpt()) {
        $kop_utility_standfirst = get_the_excerpt();
    }
    ?>
<article id="post-<?php the_ID(); ?>" <?php post_class('entry content-bg single-entry kop-utility'); ?>>
    <div class="entry-content-wrap">
        <nav class="kop-utility__breadcrumb" aria-label="Breadcrumb">
            <ol>
                <li><a href="<?php echo esc_url(home_url('/')); ?>">Home</a></li>
                <li aria-current="page"><?php the_title(); ?></li>
            </ol>
        </nav>

        <header class="entry-header kop-utility__header">
            <h1 class="entry-title"><?php the_title(); ?></h1>
            <?php if ($kop_utility_standfirst !== '') : ?>
                <p class="kop-utility__standfirst"><?php echo esc_html($kop_utility_standfirst); ?></p>
            <?php endif; ?>
        </header>

        <?php if (!empty($kop_utility['primary_action']['url']) && !empty($kop_utility['primary_action']['label'])) : ?>
            <p class="kop-utility__primary-action">
                <a class="kop-utility__button" href="<?php echo esc_url($kop_utility['primary_action']['url']); ?>">
                    <?php echo esc_html($kop_utility['primary_action']['label']); ?>
                </a>
            </p>
        <?php endif; ?>

        <div class="entry-content single-content kop-utility__content">
            <?php kop_utility_the_content(!empty($kop_utility['share'])); ?>
        </div>

        <?php if (!empty($kop_utility['other_ways']) && is_array($kop_utility['other_ways'])) : ?>
            <aside class="kop-utility__other-ways" aria-labelledby="kop-utility-other-ways-title">
                <h2 id="kop-utility-other-ways-title">Other ways to reach us</h2>
                <ul>
                    <?php foreach ($kop_utility['other_ways'] as $way) :
                        if (empty($way['label']) || empty($way['url'])) {
                            continue;
                        }
                        ?>
                        <li><a href="<?php echo esc_url($way['url']); ?>"><?php echo esc_html($way['label']); ?></a></li>
                    <?php endforeach; ?>
                </ul>
            </aside>
        <?php endif; ?>

        <?php edit_post_link('Edit this page', '<footer class="kop-utility__footer">', '</footer>'); ?>
    </div>
</article>
    <?php
endwhile;

get_footer();
