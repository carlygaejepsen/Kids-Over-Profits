<?php
/**
 * Offline test for inc/submission-notify.php.
 *
 * wp_mail on NixiHost has no sandbox, so the mailer is exercised here against
 * WordPress stubs instead of on the live site: run it before deploying a
 * change to the notification code.
 *
 *   php scripts/test-submission-notify.php
 *
 * Captures every wp_mail() call and asserts the subject, the recipients, the
 * review link and the digest behaviour. Nothing is sent and nothing touches a
 * database.
 */

// --- WordPress stubs --------------------------------------------------------

define('ABSPATH', __DIR__ . '/');
define('HOUR_IN_SECONDS', 3600);
define('DAY_IN_SECONDS', 86400);

$GLOBALS['kop_test_options'] = array('admin_email' => 'admin@example.org', 'gmt_offset' => -6);
$GLOBALS['kop_test_mail']    = array();
$GLOBALS['kop_test_cron']    = array();

function get_option($key, $default = false) {
    return array_key_exists($key, $GLOBALS['kop_test_options']) ? $GLOBALS['kop_test_options'][$key] : $default;
}
function update_option($key, $value, $autoload = null) {
    $GLOBALS['kop_test_options'][$key] = $value;
    return true;
}
function is_email($value) {
    return (bool) filter_var((string) $value, FILTER_VALIDATE_EMAIL);
}
function wp_strip_all_tags($value) {
    return strip_tags((string) $value);
}
function apply_filters($hook, $value) {
    return $value;
}
function add_action($hook, $callback, $priority = 10, $args = 1) {
    $GLOBALS['kop_test_cron']['actions'][$hook][] = $callback;
}
function admin_url($path = '') {
    return 'https://example.org/wp-admin/' . ltrim($path, '/');
}
function wp_date($format) {
    return gmdate($format);
}
function current_time($type) {
    return $type === 'timestamp' ? time() : gmdate('Y-m-d H:i:s');
}
function wp_next_scheduled($hook) {
    return isset($GLOBALS['kop_test_cron']['scheduled'][$hook]) ? $GLOBALS['kop_test_cron']['scheduled'][$hook] : false;
}
function wp_schedule_event($timestamp, $recurrence, $hook) {
    $GLOBALS['kop_test_cron']['scheduled'][$hook] = array('at' => $timestamp, 'every' => $recurrence);
    return true;
}
function wp_mail($to, $subject, $body) {
    $GLOBALS['kop_test_mail'][] = array('to' => (array) $to, 'subject' => $subject, 'body' => $body);
    return true;
}
/** The review screens are front-end pages; inc/admin.php resolves them for real. */
function kop_tools_parent_slug() {
    return 'kop-tools';
}
function kop_find_template_page_url($template) {
    $map = array(
        'page-news-processor.php'     => 'https://example.org/news-processor/',
        'page-admin-submissions.php'  => 'https://example.org/submissions-review/',
        'page-admin-lawsuits.php'     => 'https://example.org/lawsuit-admin/',
    );
    return isset($map[$template]) ? $map[$template] : '';
}

require_once __DIR__ . '/../inc/submission-notify.php';

// --- Test helpers -----------------------------------------------------------

$tests = 0;
$failures = array();
function check($label, $condition, $detail = '') {
    global $tests, $failures;
    $tests++;
    if (!$condition) {
        $failures[] = $label . ($detail !== '' ? ' -- ' . $detail : '');
    }
}
function last_mail() {
    $mail = $GLOBALS['kop_test_mail'];
    return empty($mail) ? null : $mail[count($mail) - 1];
}
function reset_mail() {
    $GLOBALS['kop_test_mail'] = array();
}

// --- A plain submission sends one mail --------------------------------------

reset_mail();
$sent = kop_notify_admins('lawsuit', 'Doe v. Provo Canyon School', '', array(
    'Submitted by' => 'jamie@example.com',
    'Facilities' => array('Provo Canyon School', 'Aspen Education Group'),
));
$mail = last_mail();
check('lawsuit: reported sent', $sent === true);
check('lawsuit: one mail', count($GLOBALS['kop_test_mail']) === 1);
check('lawsuit: goes to admin_email', $mail && $mail['to'] === array('admin@example.org'), $mail ? implode(',', $mail['to']) : 'no mail');
check('lawsuit: subject names the type and title',
    $mail && strpos($mail['subject'], 'New lawsuit suggestion: Doe v. Provo Canyon School') !== false, $mail ? $mail['subject'] : '');
check('lawsuit: body lists fields', $mail && strpos($mail['body'], 'Submitted by: jamie@example.com') !== false);
check('lawsuit: array field flattened', $mail && strpos($mail['body'], 'Facilities: Provo Canyon School, Aspen Education Group') !== false);
check('lawsuit: links the review screen',
    $mail && strpos($mail['body'], 'https://example.org/lawsuit-admin/') !== false);

// --- Suggested edits link the wp-admin approval screen ----------------------

reset_mail();
kop_notify_admins('suggested_edit', 'Provo Canyon School', '', array('Changes' => 3));
$mail = last_mail();
check('suggested edit: links Submissions Review on the data tab',
    $mail && strpos($mail['body'], 'https://example.org/submissions-review/?type=data') !== false);

// --- Submitted text cannot carry markup or unbounded length -----------------

reset_mail();
kop_notify_admins('document', "<script>alert(1)</script>Leaked   memo\nsecond line", '', array(
    'Note' => str_repeat('x', 500),
));
$mail = last_mail();
check('document: tags stripped', $mail && strpos($mail['body'], '<script>') === false, $mail ? $mail['body'] : '');
check('document: newlines collapsed in title',
    $mail && strpos($mail['body'], 'alert(1)Leaked memo second line') !== false, $mail ? $mail['body'] : '');
check('document: long value truncated', $mail && strpos($mail['body'], str_repeat('x', 297) . '...') !== false);

// --- An explicit admin URL wins ---------------------------------------------

reset_mail();
kop_notify_admins('wiki', 'Hidden Lake Academy', 'https://example.org/custom-review/', array());
$mail = last_mail();
check('wiki: explicit url used', $mail && strpos($mail['body'], 'https://example.org/custom-review/') !== false);

// --- An unknown type is refused ---------------------------------------------

reset_mail();
check('unknown type refused', kop_notify_admins('not-a-type', 'x') === false);
check('unknown type sends nothing', count($GLOBALS['kop_test_mail']) === 0);

// --- Recipients option overrides, and bad addresses are dropped -------------

reset_mail();
update_option('kop_submission_notify_emails', "one@example.org, broken-address\ntwo@example.org");
kop_notify_admins('lawsuit', 'Roe v. Turn-About Ranch');
$mail = last_mail();
check('recipients: option list used', $mail && $mail['to'] === array('one@example.org', 'two@example.org'),
    $mail ? implode(',', $mail['to']) : 'no mail');
update_option('kop_submission_notify_emails', '');

// --- News is digested, not mailed one by one --------------------------------

reset_mail();
check('bot-found news is digested', kop_submission_type_is_digested('news_auto') === true);
check('human news is not digested', kop_submission_type_is_digested('news') === false);
for ($i = 1; $i <= 3; $i++) {
    kop_notify_admins('news_auto', 'Article ' . $i, '', array('Source' => 'example.com'));
}
check('news: nothing mailed immediately', count($GLOBALS['kop_test_mail']) === 0);
$queue = get_option('kop_submission_notify_queue', array());
check('news: three items queued', count($queue) === 3, 'queued ' . count($queue));

reset_mail();
kop_notify_admins('news', 'Reporter-submitted article', '', array('Source' => 'example.com'));
check('human news: mailed at once', count($GLOBALS['kop_test_mail']) === 1);
check('human news: queue untouched', count(get_option('kop_submission_notify_queue', array())) === 3);

reset_mail();
kop_submission_send_digest();
$mail = last_mail();
check('digest: one mail', count($GLOBALS['kop_test_mail']) === 1);
check('digest: subject counts the items',
    $mail && strpos($mail['subject'], '3 new submissions today') !== false, $mail ? $mail['subject'] : '');
check('digest: lists each item', $mail
    && strpos($mail['body'], 'Article 1') !== false
    && strpos($mail['body'], 'Article 3') !== false);
check('digest: links the review screen',
    $mail && strpos($mail['body'], 'https://example.org/submissions-review/?type=news') !== false);
check('digest: queue cleared', get_option('kop_submission_notify_queue', array()) === array());

reset_mail();
check('digest: empty queue sends nothing', kop_submission_send_digest() === false);
check('digest: really nothing sent', count($GLOBALS['kop_test_mail']) === 0);

// --- A type whose review page does not exist falls back to the tools menu ---

reset_mail();
kop_notify_admins('wiki', 'Turn-About Ranch');
$mail = last_mail();
check('wiki: links Submissions Review on the wiki tab',
    $mail && strpos($mail['body'], 'https://example.org/submissions-review/?type=wiki') !== false, $mail ? $mail['body'] : '');

reset_mail();
kop_notify_admins('legislation', 'HB 1234');
$mail = last_mail();
check('legislation: no review page, falls back to the tools menu',
    $mail && strpos($mail['body'], 'wp-admin/admin.php?page=kop-tools') !== false, $mail ? $mail['body'] : '');

// --- The daily event is scheduled once --------------------------------------

kop_submission_schedule_digest();
$first = wp_next_scheduled('kop_submission_notify_digest');
check('digest: event scheduled', $first !== false);
kop_submission_schedule_digest();
check('digest: not rescheduled', wp_next_scheduled('kop_submission_notify_digest') === $first);

// --- Report -----------------------------------------------------------------

if (empty($failures)) {
    echo "submission notify tests: PASS ({$tests} checks)\n";
    exit(0);
}
echo "submission notify tests: FAIL\n";
foreach ($failures as $f) {
    echo '  - ' . $f . "\n";
}
echo count($failures) . " of {$tests} checks failed\n";
exit(1);
