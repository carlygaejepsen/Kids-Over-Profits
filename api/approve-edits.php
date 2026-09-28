<?php
/**
 * Retired: the old read-only list of pending facility edits. Submissions
 * Review (templates/page-admin-submissions.php, Data tab) does everything it
 * did plus approve, reject and edit, so this file only forwards old links and
 * bookmarks there.
 */
if (!function_exists('current_user_can')) {
    $kop_wp = __DIR__;
    for ($i = 0; $i < 6; $i++) {
        $kop_wp = dirname($kop_wp);
        if (file_exists($kop_wp . '/wp-load.php')) {
            require_once $kop_wp . '/wp-load.php';
            break;
        }
    }
}
$kop_review = function_exists('kop_submissions_review_url') ? kop_submissions_review_url('data') : '';
if ($kop_review === '' && function_exists('admin_url')) {
    $kop_review = admin_url('admin.php?page=kop-tools');
}
header('Location: ' . $kop_review, true, 301);
exit;
