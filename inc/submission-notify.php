<?php
/**
 * Admin notifications for public submissions.
 *
 * Every public form used to insert its row silently, so a suggested edit, a
 * wiki or news submission, an anonymous document or a lawsuit or legislation
 * tip sat in its table until someone thought to open the review screen. Bug
 * reports were the only thing that sent mail (inc/bug-report-notify.php), and
 * this is that mailer generalised.
 *
 * Volunteer sign-ups are not here: templates/page-volunteers.php sends people
 * to an external form or a mailto:, and the only insert into
 * volunteer_projects (api/save-volunteer.php) is the admin creating a
 * project, not a member of the public signing up.
 *
 * Call one function at each insert site:
 *
 *   kop_notify_admins('suggested_edit', $facility_name, '', array(
 *       'Submitted by' => $name,
 *       'Fields'       => $count . ' changes',
 *   ));
 *
 * The type decides the subject line and which review screen is linked; pass
 * an explicit $admin_url only when the type's own screen is not where the
 * reviewer should land. Values are flattened to one plain-text line each and
 * truncated, so a form can never relay markup or a wall of text through the
 * notification.
 *
 * High-volume types are queued instead of sent one by one: see
 * KOP_SUBMISSION_DIGEST_TYPES below.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Types that are collected and sent as one daily digest rather than a mail
 * per row. Define KOP_SUBMISSION_DIGEST_TYPES in wp-config.php (or
 * api/config.local.php) as a comma-separated list of type keys to change it;
 * an empty string turns digesting off and mails everything immediately.
 *
 * Bot-found news defaults in: the nightly discovery run posts to the same
 * endpoint a person uses (scripts/discover-articles.php submit_candidate()),
 * dozens of rows at a time. A news article a person submitted is still mailed
 * straight away; the two are separate types for exactly this reason.
 */
if (!defined('KOP_SUBMISSION_DIGEST_TYPES')) {
    define('KOP_SUBMISSION_DIGEST_TYPES', 'news_auto');
}

const KOP_SUBMISSION_NOTIFY_RECIPIENTS_OPTION = 'kop_submission_notify_emails';
const KOP_SUBMISSION_NOTIFY_QUEUE_OPTION      = 'kop_submission_notify_queue';
const KOP_SUBMISSION_NOTIFY_DIGEST_HOOK       = 'kop_submission_notify_digest';

/**
 * The submission types, with the subject noun and the review screen each one
 * belongs to. 'template' is a page template basename resolved to a permalink
 * at send time (these review screens are front-end pages, not wp-admin
 * screens); 'admin_page' is a wp-admin menu slug used instead when set.
 * 'tab' opens Submissions Review on that type (its ?type= parameter).
 */
function kop_submission_types() {
    return array(
        // Facility edits, wiki entries and articles are all approved on
        // Submissions Review, not on the screens they were written in.
        'suggested_edit' => array(
            'label'    => 'facility edit suggestion',
            'template' => 'page-admin-submissions.php',
            'tab'      => 'data',
        ),
        'wiki' => array(
            'label'    => 'wiki submission',
            'template' => 'page-admin-submissions.php',
            'tab'      => 'wiki',
        ),
        'news' => array(
            'label'    => 'news submission',
            'template' => 'page-admin-submissions.php',
            'tab'      => 'news',
        ),
        // Same table and screen as 'news'; separate so the nightly discovery
        // run can be digested while a person's submission still mails at once.
        'news_auto' => array(
            'label'    => 'auto-discovered article',
            'template' => 'page-admin-submissions.php',
            'tab'      => 'news',
        ),
        'document' => array(
            'label'    => 'anonymous document',
            'template' => 'page-admin-submissions.php',
        ),
        'lawsuit' => array(
            'label'    => 'lawsuit suggestion',
            'template' => 'page-admin-lawsuits.php',
        ),
        'legislation' => array(
            'label'    => 'legislation suggestion',
            'template' => 'page-admin-legislation.php',
        ),
    );
}

/** Where a type's reviewer should land, or '' when the screen cannot be found. */
function kop_submission_review_url($type) {
    $types = kop_submission_types();
    if (!isset($types[$type])) {
        return '';
    }
    $spec = $types[$type];
    if (!empty($spec['admin_page']) && function_exists('admin_url')) {
        return admin_url('admin.php?page=' . $spec['admin_page']);
    }
    if (!empty($spec['template']) && function_exists('kop_find_template_page_url')) {
        $url = (string) kop_find_template_page_url($spec['template']);
        if ($url !== '') {
            if (!empty($spec['tab'])) {
                $url .= (strpos($url, '?') === false ? '?' : '&') . 'type=' . rawurlencode($spec['tab']);
            }
            return $url;
        }
    }
    // No page uses that template (yet): the tools menu lists them all.
    if (function_exists('kop_tools_parent_slug') && function_exists('admin_url')) {
        return admin_url('admin.php?page=' . kop_tools_parent_slug());
    }
    return '';
}

/**
 * Who gets notified. The option holds a comma or newline separated list;
 * with nothing set, the site admin address.
 */
function kop_submission_notify_recipients() {
    $raw = get_option(KOP_SUBMISSION_NOTIFY_RECIPIENTS_OPTION, '');
    $list = array();
    foreach (preg_split('/[\s,;]+/', (string) $raw) as $candidate) {
        $candidate = trim($candidate);
        if ($candidate !== '' && is_email($candidate)) {
            $list[] = $candidate;
        }
    }
    if (empty($list)) {
        $admin = get_option('admin_email');
        if ($admin && is_email($admin)) {
            $list[] = $admin;
        }
    }
    /**
     * Filter the notification recipients.
     *
     * @param string[] $list Email addresses.
     */
    return apply_filters('kop_submission_notify_recipients', array_values(array_unique($list)));
}

/** One submitted value as a single short plain-text line. */
function kop_submission_flatten_value($value) {
    if (is_array($value)) {
        $parts = array();
        foreach ($value as $item) {
            $flat = kop_submission_flatten_value($item);
            if ($flat !== '') {
                $parts[] = $flat;
            }
        }
        $value = implode(', ', $parts);
    } elseif (is_bool($value)) {
        $value = $value ? 'yes' : 'no';
    } elseif (is_scalar($value)) {
        $value = (string) $value;
    } else {
        $value = '';
    }
    $value = wp_strip_all_tags((string) $value);
    $value = trim(preg_replace('/\s+/u', ' ', $value));
    if (function_exists('mb_strlen') ? mb_strlen($value) > 300 : strlen($value) > 300) {
        $value = (function_exists('mb_substr') ? mb_substr($value, 0, 297) : substr($value, 0, 297)) . '...';
    }
    return $value;
}

/** Site name for subjects, matching the bug-report mailer. */
function kop_submission_site_name() {
    return function_exists('kop_bug_site_name') ? kop_bug_site_name() : 'Kids Over Profits';
}

/** True when this type is queued for the daily digest instead of mailed now. */
function kop_submission_type_is_digested($type) {
    $types = array_filter(array_map('trim', explode(',', (string) KOP_SUBMISSION_DIGEST_TYPES)));
    return in_array($type, $types, true);
}

/**
 * Tell the admins about a new public submission.
 *
 * Never throws and never blocks the caller: a failed send is reported as
 * false and the row is already saved by the time this runs.
 *
 * @param string $type      Key from kop_submission_types().
 * @param string $title     What was submitted (facility name, article title).
 * @param string $admin_url Review screen, or '' for the type's own screen.
 * @param array  $fields    Label => value lines for the body.
 * @return bool             True when mail was sent or the item was queued.
 */
function kop_notify_admins($type, $title, $admin_url = '', $fields = array()) {
    if (!function_exists('wp_mail')) {
        return false;
    }
    $types = kop_submission_types();
    if (!isset($types[$type])) {
        return false;
    }

    $title = kop_submission_flatten_value($title);
    if ($title === '') {
        $title = 'Untitled';
    }
    $clean_fields = array();
    foreach ((array) $fields as $label => $value) {
        $value = kop_submission_flatten_value($value);
        if ($value !== '') {
            $clean_fields[kop_submission_flatten_value($label)] = $value;
        }
    }
    if ($admin_url === '') {
        $admin_url = kop_submission_review_url($type);
    }

    if (kop_submission_type_is_digested($type)) {
        return kop_submission_queue_for_digest($type, $title, $clean_fields);
    }

    $recipients = kop_submission_notify_recipients();
    if (empty($recipients)) {
        return false;
    }

    $site  = kop_submission_site_name();
    $label = $types[$type]['label'];

    $subject = '[' . $site . '] New ' . $label . ': ' . $title;
    $body = 'A new ' . $label . ' was submitted on ' . $site . ".\n\n"
        . $title . "\n";
    foreach ($clean_fields as $flabel => $value) {
        $body .= $flabel . ': ' . $value . "\n";
    }
    $body .= "\nSubmitted: " . (function_exists('wp_date') ? wp_date('M j, Y g:i a') : gmdate('M j, Y g:i a')) . "\n";
    if ($admin_url !== '') {
        $body .= "Review it: " . $admin_url . "\n";
    }
    $body .= "\nYou are receiving this because this address is on the "
        . $site . " submission notification list.";

    return (bool) @wp_mail($recipients, $subject, $body);
}

/**
 * Hold a digested submission until the daily run. The queue is an option
 * rather than a table: it is small, drained once a day, and capped so a busy
 * discovery night cannot grow it without bound.
 */
function kop_submission_queue_for_digest($type, $title, $fields) {
    $queue = get_option(KOP_SUBMISSION_NOTIFY_QUEUE_OPTION, array());
    if (!is_array($queue)) {
        $queue = array();
    }
    $queue[] = array(
        'type'   => $type,
        'title'  => $title,
        'fields' => $fields,
        'time'   => time(),
    );
    if (count($queue) > 500) {
        $queue = array_slice($queue, -500);
    }
    update_option(KOP_SUBMISSION_NOTIFY_QUEUE_OPTION, $queue, false);
    return true;
}

/** Send one mail listing everything queued since the last run, then clear it. */
function kop_submission_send_digest() {
    $queue = get_option(KOP_SUBMISSION_NOTIFY_QUEUE_OPTION, array());
    if (!is_array($queue) || empty($queue)) {
        return false;
    }
    // Clear first: a mailer that times out must not leave the queue to be
    // sent twice on the next run.
    update_option(KOP_SUBMISSION_NOTIFY_QUEUE_OPTION, array(), false);

    $recipients = kop_submission_notify_recipients();
    if (empty($recipients) || !function_exists('wp_mail')) {
        return false;
    }

    $types = kop_submission_types();
    $by_type = array();
    foreach ($queue as $item) {
        $by_type[$item['type']][] = $item;
    }

    $site = kop_submission_site_name();
    $total = count($queue);
    $subject = '[' . $site . '] ' . $total . ' new submission' . ($total === 1 ? '' : 's') . ' today';
    $body = 'Submissions logged on ' . $site . " since the last digest.\n";

    foreach ($by_type as $type => $items) {
        $label = isset($types[$type]) ? $types[$type]['label'] : $type;
        $body .= "\n" . strtoupper($label) . ' (' . count($items) . ")\n";
        foreach ($items as $item) {
            $body .= '  - ' . $item['title'];
            if (!empty($item['fields'])) {
                $bits = array();
                foreach ($item['fields'] as $flabel => $value) {
                    $bits[] = $flabel . ': ' . $value;
                }
                $body .= ' (' . implode('; ', $bits) . ')';
            }
            $body .= "\n";
        }
        $url = kop_submission_review_url($type);
        if ($url !== '') {
            $body .= '  Review: ' . $url . "\n";
        }
    }

    $body .= "\nYou are receiving this because this address is on the "
        . $site . " submission notification list.";

    return (bool) @wp_mail($recipients, $subject, $body);
}
add_action(KOP_SUBMISSION_NOTIFY_DIGEST_HOOK, 'kop_submission_send_digest');

/** Schedule the digest once; harmless to call on every load. */
function kop_submission_schedule_digest() {
    if (!function_exists('wp_next_scheduled') || wp_next_scheduled(KOP_SUBMISSION_NOTIFY_DIGEST_HOOK)) {
        return;
    }
    // Tomorrow morning in site time, then daily.
    $first = strtotime('tomorrow 7:00am', current_time('timestamp'));
    wp_schedule_event($first ? $first - (int) (get_option('gmt_offset') * HOUR_IN_SECONDS) : time() + DAY_IN_SECONDS, 'daily', KOP_SUBMISSION_NOTIFY_DIGEST_HOOK);
}
add_action('init', 'kop_submission_schedule_digest');
