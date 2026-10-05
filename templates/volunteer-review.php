<?php
/**
 * /volunteer-review/: volunteers recommend approve or reject on review inbox
 * items through their personal link (inc/review-volunteers.php). Drawn by
 * js/volunteer-review.js; without a working link it says how to get one.
 */

if (!defined('ABSPATH')) {
    exit;
}

$kop_vol = kop_vol_current();
$kop_vol_preview = !$kop_vol && current_user_can('manage_options');
$kop_vol_projects = function_exists('kop_find_template_page_url') ? (string) kop_find_template_page_url('page-volunteers.php') : '';

get_header();
?>
<div class="kop-vol-page">
    <section class="kop-vol-panel kop-vol-intro">
        <h1>Help review submissions</h1>
        <?php if ($kop_vol || $kop_vol_preview) : ?>
            <p><?php echo $kop_vol ? 'Hi ' . esc_html($kop_vol['name']) . ', thank you for helping.' : 'Admin preview: this is what volunteers see. Recommending is turned off for you here.'; ?></p>
            <p>People and our news scanners send in articles, court cases, bills and program details. Read each one, open its link,
                and tell us whether it belongs on the site. <strong>Your answer is a recommendation:</strong> nothing changes on the site until an admin looks at it too,
                so it is fine to be unsure. Say so, and why, in the note.</p>
        <?php elseif (!empty($GLOBALS['kop_vol_bad_invite'])) : ?>
            <p>This link does not work any more. It may have been replaced with a new one. Ask the person who sent it for a fresh link.</p>
        <?php else : ?>
            <p>This page is for volunteers with a personal invite link. Open the link you were sent on this device and you will be signed in here, with no password.</p>
            <?php if ($kop_vol_projects) : ?>
                <p>Want to help? See our <a href="<?php echo esc_url($kop_vol_projects); ?>">volunteer opportunities</a>.</p>
            <?php endif; ?>
        <?php endif; ?>
    </section>
    <?php if ($kop_vol || $kop_vol_preview) : ?>
        <div id="kop-vol-app" class="kop-vol-app">
            <section class="kop-vol-panel"><p role="status">Loading…</p></section>
        </div>
        <noscript><section class="kop-vol-panel"><p>This page needs JavaScript turned on.</p></section></noscript>
    <?php endif; ?>
</div>
<?php
get_footer();
