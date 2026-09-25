<?php
/**
 * Presentation settings for utility pages that depend on editor content or
 * third-party integrations.
 */

if (!function_exists('kop_utility_config')) {
    /**
     * Get the presentation settings for a utility page.
     *
     * @param string|null $slug Page slug; defaults to the current page.
     * @return array
     */
    function kop_utility_config($slug = null) {
        if ($slug === null) {
            $slug = (string) get_post_field('post_name', get_queried_object_id());
        }

        $configs = array(
            'links' => array(
                'standfirst' => 'A collection of resources, records and tools related to the troubled teen industry.',
                'share' => true,
            ),
            'anon-submit' => array(
                'standfirst' => 'Submit documents securely for review by Kids Over Profits.',
                'share' => false,
            ),
            'donate' => array(
                'standfirst' => 'Your donation helps preserve public records and expand resources for survivors and families.',
                'share' => true,
                // Carried over from the Support hub, which now redirects here.
                'other_ways_heading' => 'Other ways to help',
                'other_ways' => array(
                    array('label' => 'Volunteer', 'url' => home_url('/volunteer/'),
                          'note' => 'Give time instead: research, data entry, writing and outreach.'),
                    array('label' => 'Tell us about a program', 'url' => home_url('/tti-data-submission/'),
                          'note' => 'Add or correct what we hold on a facility.'),
                ),
            ),
            'contact' => array(
                'share' => false,
            ),
            'no-access' => array(
                'share' => false,
            ),
        );

        $config = isset($configs[$slug]) ? $configs[$slug] : array();
        return apply_filters('kop_utility_config', $config, $slug);
    }
}

if (!function_exists('kop_utility_is_current')) {
    /** True while a utility page template is being served. */
    function kop_utility_is_current() {
        return is_singular('page') && get_page_template_slug() === 'templates/page-utility.php';
    }
}

add_filter('ez_toc_maybe_apply_the_content_filter', 'kop_utility_no_toc', 99);
add_filter('eztoc_maybe_apply_the_content_filter', 'kop_utility_no_toc', 99);
if (!function_exists('kop_utility_no_toc')) {
    /** Suppress an automatic contents box on short utility pages. */
    function kop_utility_no_toc($apply) {
        return kop_utility_is_current() ? false : $apply;
    }
}

/**
 * Print editor content while suppressing AddToAny on pages where sharing is
 * inappropriate. The plugin filter is restored immediately after the call.
 *
 * @param bool $share Whether the page should retain the automatic share row.
 */
function kop_utility_the_content($share = false) {
    if ($share) {
        the_content();
        return;
    }

    $priority = has_filter('the_content', 'A2A_SHARE_SAVE_add_to_content');
    if ($priority !== false) {
        remove_filter('the_content', 'A2A_SHARE_SAVE_add_to_content', $priority);
    }
    the_content();
    if ($priority !== false) {
        add_filter('the_content', 'A2A_SHARE_SAVE_add_to_content', $priority);
    }
}
