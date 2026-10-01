<?php
/**
 * Template Name: Personnel Profile
 * Template Post Type: post, page
 * Description: Editorial profile for a person connected to the troubled teen industry.
 * The article content is authored in the editor; the facts rail reads post metadata.
 */

if (!defined('ABSPATH')) {
    exit;
}

$kop_pp_css_path = get_stylesheet_directory() . '/css/facility-profile.css';
if (file_exists($kop_pp_css_path)) {
    wp_enqueue_style(
        'kop-person-profile',
        get_stylesheet_directory_uri() . '/css/facility-profile.css',
        array(),
        filemtime($kop_pp_css_path)
    );
}

if (!function_exists('kop_pp_meta_list')) {
    /** Return a trimmed list for a single-line or list-valued post field. */
    function kop_pp_meta_list($post_id, $key) {
        $value = get_post_meta($post_id, $key, true);
        if (!is_array($value)) {
            $value = preg_split('/\r\n|\r|\n/', (string) $value);
        }
        return array_values(array_filter(array_map('trim', array_map('strval', $value)), 'strlen'));
    }
}

if (!function_exists('kop_pp_citations')) {
    /** Print numbered links into this profile's Sources list. */
    function kop_pp_citations(array $sources) {
        $sources = array_values(array_unique(array_filter(array_map('intval', $sources), function ($source) {
            return $source > 0;
        })));
        if (!$sources) {
            return;
        }
        echo '<sup class="kop-cite">';
        foreach ($sources as $index => $source) {
            if ($index > 0) {
                echo ', ';
            }
            printf('<a href="#src-%1$d">%1$d</a>', $source);
        }
        echo '</sup>';
    }
}

$kop_pp_post_id = get_the_ID();
$kop_pp_role = (string) get_post_meta($kop_pp_post_id, 'person_role', true);
$kop_pp_active = (string) get_post_meta($kop_pp_post_id, 'person_active_years', true);
$kop_pp_organizations = kop_pp_meta_list($kop_pp_post_id, 'person_organizations');
$kop_pp_work = kop_pp_meta_list($kop_pp_post_id, 'person_notable_work');
$kop_pp_role_sources = kop_pp_meta_list($kop_pp_post_id, 'person_role_sources');
$kop_pp_active_sources = kop_pp_meta_list($kop_pp_post_id, 'person_active_years_sources');
$kop_pp_organization_sources = kop_pp_meta_list($kop_pp_post_id, 'person_organizations_sources');
$kop_pp_work_sources = kop_pp_meta_list($kop_pp_post_id, 'person_notable_work_sources');

get_header();

while (have_posts()) :
    the_post();
    ?>
<article id="post-<?php the_ID(); ?>" <?php post_class('entry content-bg single-entry kop-person-profile'); ?>>
    <header class="kop-fp-header">
        <p class="kop-fp-eyebrow">Personnel profile</p>
        <h1 class="entry-title kop-fp-title"><?php the_title(); ?></h1>
        <?php if ($kop_pp_role !== '') : ?>
            <p class="kop-fp-formerly"><?php echo esc_html($kop_pp_role); ?><?php kop_pp_citations($kop_pp_role_sources); ?></p>
        <?php endif; ?>
    </header>

    <div class="kop-fp-grid">
        <aside class="kop-fp-rail" aria-label="Profile facts">
            <h2>At a glance</h2>
            <dl class="kop-fp-facts">
                <?php if ($kop_pp_active !== '') : ?>
                    <div><dt>Active</dt><dd><?php echo esc_html($kop_pp_active); ?><?php kop_pp_citations($kop_pp_active_sources); ?></dd></div>
                <?php endif; ?>
                <?php if ($kop_pp_organizations) : ?>
                    <div>
                        <dt>Associated organizations</dt>
                        <dd>
                            <ul class="kop-fp-list">
                                <?php foreach ($kop_pp_organizations as $organization) : ?>
                                    <li><?php echo esc_html($organization); ?></li>
                                <?php endforeach; ?>
                            </ul>
                            <?php kop_pp_citations($kop_pp_organization_sources); ?>
                        </dd>
                    </div>
                <?php endif; ?>
                <?php if ($kop_pp_work) : ?>
                    <div>
                        <dt>Known for</dt>
                        <dd>
                            <ul class="kop-fp-list">
                                <?php foreach ($kop_pp_work as $item) : ?>
                                    <li><?php echo esc_html($item); ?></li>
                                <?php endforeach; ?>
                            </ul>
                            <?php kop_pp_citations($kop_pp_work_sources); ?>
                        </dd>
                    </div>
                <?php endif; ?>
            </dl>
        </aside>

        <div class="entry-content single-content kop-fp-body">
            <?php the_content(); ?>
            <?php wp_link_pages(array('before' => '<div class="page-links">', 'after' => '</div>')); ?>
        </div>
    </div>
</article>
    <?php
endwhile;

get_footer();
