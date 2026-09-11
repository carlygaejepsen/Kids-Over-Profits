<?php
/**
 * Slug-level 301 redirects for retired or renamed pages. Kept in git so the
 * mapping deploys with the theme instead of living in a plugin's database.
 *
 * Old path (no slashes) => destination path or absolute URL.
 */

if (!defined('ABSPATH')) {
    exit;
}

function kop_redirect_map() {
    return array(
        // Renamed (phase 1): the country template derives the name from the slug.
        'uk'            => '/united-kingdom/',

        // Retired shells (phase 3). Each was an empty container or hand-typed
        // list that a template-driven page now covers.
        'edcons'        => '/referrers-educational-consultants/',
        'international' => '/location-index/?type=country',
        '405-2'         => '/',
        'test-scripts'  => '/',
        'admin-tools'   => '/wp-admin/admin.php?page=kop-tools',

        // Single-file court document pages (phase 4). Each page held one
        // file block and nothing else, so the page now resolves to the file
        // itself; the lawsuit records carry the same URLs. The Trinity
        // complaint PDF is missing from uploads, so that page lands on the
        // lawsuits directory until the file is re-uploaded.
        'richardson-complaint'                                                           => 'https://kidsoverprofits.org/wp-content/uploads/2024/08/Ryan-Faust_Elevations-Lawsuit-2024.pdf',
        'trinity-teen-solutions-trinity-cross-ranch'                                     => 'https://kidsoverprofits.org/wp-content/uploads/2024/08/Class-Action-Approval-TTS-TCR.pdf',
        'trinity-teen-trinity-cross-complaint'                                           => '/lawsuits/',
        'doe-v-hyde-complaint'                                                           => 'https://kidsoverprofits.org/wp-content/uploads/2024/11/doe-v-hyde-woodstock-complaint.pdf',
        'doe-v-hyde-complaint-amended'                                                   => 'https://kidsoverprofits.org/wp-content/uploads/2024/11/doe-v-hyde-woodstock-amended-complaint-2.pdf',
        'shiver-v-southstone-complaint'                                                  => 'https://kidsoverprofits.org/wp-content/uploads/2024/08/Shiver-v.-Southstone-Complaint.pdf',
        'shiver-v-southstone-summons'                                                    => 'https://kidsoverprofits.org/wp-content/uploads/2024/08/Shiver-v.-Southstone-Summons.pdf',
        'shiver-v-southstone-motion-for-default-judgement'                               => 'https://kidsoverprofits.org/wp-content/uploads/2024/08/SouthstoneDefaultMotion.pdf',
        'a-survivors-guide-to-legal-action-against-troubled-teen-industry-programs'      => 'https://kidsoverprofits.org/wp-content/uploads/2024/08/Survivors-Guide-to-Legal-Action-Against-Troubled-Teen-Industry-Programs.pdf',
        'overt-and-covert-conversion-therapy-practices-in-therapeutic-boarding-schools'  => 'https://kidsoverprofits.org/wp-content/uploads/2024/08/Overt-Covert-Conversion-Therapy.pdf',
    );
}

function kop_apply_redirect_map() {
    if (is_admin() || wp_doing_ajax()) {
        return;
    }
    $request = isset($_SERVER['REQUEST_URI']) ? wp_parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) : '';
    $path = trim((string) $request, '/');
    if ($path === '') {
        return;
    }
    $map = kop_redirect_map();
    if (!isset($map[$path])) {
        return;
    }
    $target = $map[$path];
    if (strpos($target, 'http') !== 0) {
        $target = home_url($target);
    }
    wp_safe_redirect($target, 301);
    exit;
}
add_action('template_redirect', 'kop_apply_redirect_map', 1);
