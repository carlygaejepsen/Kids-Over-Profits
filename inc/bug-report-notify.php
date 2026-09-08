<?php
/**
 * Bug report status notifications.
 *
 * Reporters can leave an email and opt in to updates when they submit a bug
 * report (bug_reports.notify_updates = 1). Both triage paths — the wp-admin
 * Bug Reports screen (inc/admin.php) and the JSON API
 * (api/save-bug-report.php, action=update_status) — call
 * kop_bug_report_notify_status_change() after changing a status.
 *
 * Emails never include reporter-supplied text, so the form can't be used to
 * relay arbitrary content to an address the reporter typed in.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Human-readable status label ("in_progress" -> "In progress").
 */
function kop_bug_status_label($status) {
    $labels = array(
        'new'         => 'New',
        'in_progress' => 'In progress',
        'resolved'    => 'Resolved',
        'dismissed'   => 'Closed',
    );
    return isset($labels[$status]) ? $labels[$status] : ucwords(str_replace('_', ' ', (string) $status));
}

/**
 * Name of the site used in outgoing email subjects/bodies.
 */
function kop_bug_site_name() {
    $name = function_exists('get_bloginfo') ? get_bloginfo('name') : '';
    return $name ? $name : 'Kids Over Profits';
}

/**
 * Add the notify_updates column to a bug_reports table created before the
 * opt-in existed. Idempotent; safe to call on every admin page load.
 */
function kop_bug_ensure_notify_column() {
    global $wpdb;
    if (!$wpdb || $wpdb->get_var("SHOW TABLES LIKE 'bug_reports'") !== 'bug_reports') {
        return;
    }
    if (!$wpdb->get_var("SHOW COLUMNS FROM bug_reports LIKE 'notify_updates'")) {
        $wpdb->query('ALTER TABLE bug_reports ADD COLUMN notify_updates TINYINT(1) NOT NULL DEFAULT 0 AFTER contact');
    }
}

/**
 * Short receipt sent when a report is submitted with the updates opt-in.
 * Deliberately contains no reporter-supplied text.
 *
 * @param string $email     Validated reporter address.
 * @param int    $report_id New bug_reports.id.
 * @return bool             Whether wp_mail accepted the message.
 */
function kop_bug_report_send_receipt($email, $report_id) {
    if (!function_exists('wp_mail') || !is_email($email)) {
        return false;
    }
    $site = kop_bug_site_name();
    $subject = '[' . $site . '] Bug report #' . (int) $report_id . ' received';
    $body = "Thanks for reporting a problem on " . $site . ".\n\n"
        . "Your report has been logged as #" . (int) $report_id . ". "
        . "You asked to be kept in the loop, so we will email this address whenever the status of the report changes "
        . "(for example when someone starts looking into it, or when it is resolved).\n\n"
        . "If you did not submit this report, you can ignore this message; no further emails will be sent unless the report's status changes.\n\n"
        . "- The " . $site . " team";
    return (bool) @wp_mail($email, $subject, $body);
}

/**
 * Email the reporter about a status change, if they opted in.
 *
 * @param object|array $report     bug_reports row (needs id, contact,
 *                                 notify_updates, feature, status).
 * @param string       $new_status Status the report was just moved to.
 * @param string       $old_status Status it had before (no email if equal).
 * @param string       $note       Optional admin note to pass along.
 * @return bool                    True if an email was sent.
 */
function kop_bug_report_notify_status_change($report, $new_status, $old_status = '', $note = '') {
    $r = (object) $report;
    if (empty($r->notify_updates) || empty($r->contact) || !function_exists('wp_mail')) {
        return false;
    }
    if ($old_status !== '' && $old_status === $new_status) {
        return false;
    }
    $email = trim((string) $r->contact);
    if (!is_email($email)) {
        return false;
    }

    $site = kop_bug_site_name();
    $id = (int) $r->id;
    $label = kop_bug_status_label($new_status);

    $explanations = array(
        'new'         => 'The report has been reopened and is back in the queue.',
        'in_progress' => 'Someone is looking into it now.',
        'resolved'    => 'The problem has been fixed or otherwise resolved. If you still see it, feel free to submit a new report.',
        'dismissed'   => 'The report was reviewed and closed without changes. This usually means the behavior is expected, could not be reproduced, or is already covered by another report.',
    );
    $explanation = isset($explanations[$new_status]) ? $explanations[$new_status] : '';

    $subject = '[' . $site . '] Bug report #' . $id . ' is now ' . strtolower($label);
    $body = "An update on the problem you reported on " . $site . ".\n\n"
        . "Report #" . $id . "\n"
        . (!empty($r->feature) ? "Feature: " . $r->feature . "\n" : '')
        . "Status: " . $label . "\n\n"
        . ($explanation ? $explanation . "\n\n" : '')
        . (trim((string) $note) !== '' ? "Note from the team:\n" . trim((string) $note) . "\n\n" : '')
        . "You are receiving this because you asked for updates when you submitted the report.\n\n"
        . "- The " . $site . " team";

    return (bool) @wp_mail($email, $subject, $body);
}
