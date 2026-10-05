<?php
/**
 * "Email me when this is reviewed" for public submissions.
 *
 * Every public form (suggested edits from the data form and the "Submit info"
 * buttons, news, wiki entries, lawsuit and legislation tips, glossary notes)
 * shows a checkbox (js/submission-followup.js, kop_followup_fields() here).
 * Ticked, the form posts `notify_email`, and the endpoint calls
 * kop_followup_register() after its insert. The same block carries "Also sign
 * me up for the newsletter" (never ticked by default): `newsletter_email`,
 * passed on to inc/newsletter-signup.php. Bug reports keep their own
 * opt-in (inc/bug-report-notify.php); the anonymous portal never asks.
 *
 * Nothing is hooked into the approve and reject paths. They are many
 * (api/manage-submissions.php, the review inbox, api/process-edit.php, the
 * glossary screens), so a cron reads each waiting item's own status instead:
 * the first time it sees a decision it notes the time, and it mails once the
 * decision has stood for KOP_FOLLOWUP_SETTLE seconds. An Undo inside that
 * window puts the item back to pending and nothing is sent.
 *
 * Mail never carries text the submitter typed: a decline names only the kind
 * of submission and its reference number, and the "added" mail names the item
 * by its title as the reviewer approved it. Once the update is sent (or the
 * person stops it, or the item is deleted) the address is blanked.
 */

if (!defined('ABSPATH')) {
    exit;
}

const KOP_FOLLOWUP_DB_VERSION = '1';
const KOP_FOLLOWUP_CRON_HOOK  = 'kop_followup_send';
/** Seconds a decision must stand before the update goes out (room for Undo). */
const KOP_FOLLOWUP_SETTLE     = 600;
/** Most follow-ups one address can be signed up for in a day. */
const KOP_FOLLOWUP_DAILY_CAP  = 15;

function kop_followup_table() {
    global $wpdb;
    return $wpdb->prefix . 'kop_submission_followups';
}

/** Create the table once per schema version. */
function kop_followup_ensure_table() {
    if (get_option('kop_followup_db') === KOP_FOLLOWUP_DB_VERSION) {
        return;
    }
    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    $table = kop_followup_table();
    $charset = $wpdb->get_charset_collate();
    dbDelta("CREATE TABLE {$table} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        kind VARCHAR(20) NOT NULL,
        item_id BIGINT UNSIGNED NOT NULL,
        email VARCHAR(190) NOT NULL DEFAULT '',
        token CHAR(32) NOT NULL,
        state VARCHAR(12) NOT NULL DEFAULT 'waiting',
        moved TINYINT(1) NOT NULL DEFAULT 0,
        outcome VARCHAR(12) NULL,
        decided_seen_at DATETIME NULL,
        created_at DATETIME NOT NULL,
        sent_at DATETIME NULL,
        PRIMARY KEY  (id),
        KEY state (state),
        KEY kind_item (kind, item_id),
        UNIQUE KEY token (token)
    ) {$charset};");
    update_option('kop_followup_db', KOP_FOLLOWUP_DB_VERSION);
}

/**
 * The kinds of submission: where each one's status lives and what its
 * statuses mean. 'db' => 'records' reads through kop_seed_pdo() (the tables
 * api/config.php creates), 'wp' through $wpdb with the site prefix.
 */
function kop_followup_kinds() {
    global $wpdb;
    return array(
        'suggested_edit' => array(
            'db' => 'records', 'table' => 'suggested_edits', 'status' => 'status', 'title' => 'master_id',
            'noun' => 'suggested edit',
            'added' => array('approved'), 'declined' => array('rejected'), 'gone' => array(),
        ),
        'news' => array(
            'db' => 'records', 'table' => 'news_submissions', 'status' => 'status', 'title' => 'article_title',
            'noun' => 'news article',
            'added' => array('approved', 'published'), 'declined' => array('rejected', 'promotional'), 'gone' => array('deleted'),
        ),
        'wiki' => array(
            'db' => 'records', 'table' => 'wiki_submissions', 'status' => 'status', 'title' => 'program_name',
            'noun' => 'wiki entry',
            'added' => array('approved', 'published'), 'declined' => array('rejected'), 'gone' => array('deleted'),
        ),
        'lawsuit' => array(
            'db' => 'records', 'table' => 'lawsuits', 'status' => 'publication_status', 'title' => 'case_name',
            'noun' => 'lawsuit',
            'added' => array('approved', 'published'), 'declined' => array('rejected'), 'gone' => array(),
        ),
        'legislation' => array(
            'db' => 'records', 'table' => 'legislation', 'status' => 'publication_status', 'title' => 'bill_title',
            'noun' => 'bill',
            'added' => array('approved', 'published'), 'declined' => array('rejected'), 'gone' => array(),
        ),
        'glossary' => array(
            'db' => 'wp', 'table' => $wpdb->prefix . 'kop_glossary_feedback', 'status' => 'status', 'title' => 'term',
            'noun' => 'glossary note',
            'added' => array('added'), 'declined' => array('dismissed'), 'gone' => array(),
        ),
    );
}

/**
 * The address a form posted, if the person ticked the box. Forms send
 * `notify_email` only when the box is ticked; '' means no follow-up.
 */
function kop_followup_email_from($input) {
    if (!is_array($input)) {
        return '';
    }
    $email = isset($input['notify_email']) ? trim((string) $input['notify_email']) : '';
    if ($email === '' && !empty($input['notifyEmail'])) {
        $email = trim((string) $input['notifyEmail']);
    }
    $email = substr($email, 0, 190);
    return ($email !== '' && is_email($email)) ? $email : '';
}

/**
 * Sign an address up for one update on this item, and send the receipt.
 * Best effort: a submission is saved whether or not this works.
 *
 * @param string     $kind    Key from kop_followup_kinds().
 * @param int        $item_id The row the form just saved.
 * @param array|string $input The decoded request (reads notify_email), or the address itself.
 * @return bool               True when the follow-up was stored.
 */
function kop_followup_register($kind, $item_id, $input) {
    try {
        // The newsletter box is separate: either can be ticked without the other.
        if (is_array($input) && function_exists('kop_newsletter_queue')) {
            kop_newsletter_queue(kop_newsletter_email_from($input), $kind);
        }
        $email = is_array($input) ? kop_followup_email_from($input) : kop_followup_email_from(array('notify_email' => $input));
        $kinds = kop_followup_kinds();
        $item_id = (int) $item_id;
        if ($email === '' || $item_id <= 0 || !isset($kinds[$kind])) {
            return false;
        }
        kop_followup_ensure_table();
        global $wpdb;
        $table = kop_followup_table();

        $exists = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE kind = %s AND item_id = %d AND email = %s AND state = 'waiting'",
            $kind, $item_id, $email
        ));
        if ($exists) {
            return true;
        }
        // An address typed into many forms in a day (or someone else's
        // address typed in to flood it) stops getting signed up.
        $today = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE email = %s AND created_at > %s",
            $email, gmdate('Y-m-d H:i:s', time() - DAY_IN_SECONDS)
        ));
        if ($today >= KOP_FOLLOWUP_DAILY_CAP) {
            return false;
        }

        $token = bin2hex(random_bytes(16));
        $ok = $wpdb->insert($table, array(
            'kind'       => $kind,
            'item_id'    => $item_id,
            'email'      => $email,
            'token'      => $token,
            'state'      => 'waiting',
            'created_at' => gmdate('Y-m-d H:i:s'),
        ), array('%s', '%d', '%s', '%s', '%s', '%s'));
        if (!$ok) {
            return false;
        }
        kop_followup_send_receipt($kind, $item_id, $email, $token);
        kop_followup_schedule();
        return true;
    } catch (Throwable $e) {
        error_log('kop_followup_register failed: ' . $e->getMessage());
        return false;
    }
}

/**
 * The review inbox moved a pending item to another queue (a news link that is
 * really a lawsuit, say). Its own row reads as rejected, so mark it: the update
 * then says it was kept and filed elsewhere, not turned down.
 */
function kop_followup_mark_moved($kind, $item_id) {
    if (get_option('kop_followup_db') !== KOP_FOLLOWUP_DB_VERSION) {
        return;
    }
    global $wpdb;
    $wpdb->update(kop_followup_table(), array('moved' => 1),
        array('kind' => $kind, 'item_id' => (int) $item_id, 'state' => 'waiting'), array('%d'), array('%s', '%d', '%s'));
}

/** The review inbox's native type name (inc/review-inbox/native.php) as a follow-up kind. */
function kop_followup_kind_for_native($type) {
    return $type === 'data' ? 'suggested_edit' : (string) $type;
}

function kop_followup_site_name() {
    return function_exists('kop_bug_site_name') ? kop_bug_site_name() : 'Kids Over Profits';
}

function kop_followup_stop_url($token) {
    return add_query_arg(array('kop_followup_stop' => $token), home_url('/'));
}

function kop_followup_footer($token) {
    return "\n\nYou are receiving this because this address was given, with \"Email me when this is reviewed\" ticked, "
        . "when the submission was sent in. We use it for this one update and then delete it.\n"
        . "Not you, or changed your mind? Stop it here: " . kop_followup_stop_url($token) . "\n\n"
        . "- The " . kop_followup_site_name() . " team";
}

/** Short receipt with no submitted text in it. */
function kop_followup_send_receipt($kind, $item_id, $email, $token) {
    if (!function_exists('wp_mail')) {
        return false;
    }
    $kinds = kop_followup_kinds();
    $noun = $kinds[$kind]['noun'];
    $site = kop_followup_site_name();
    $subject = '[' . $site . '] We got your ' . $noun . ' (#' . (int) $item_id . ')';
    $body = 'Thank you for sending a ' . $noun . ' to ' . $site . ".\n\n"
        . 'It is reference #' . (int) $item_id . '. A person reads every submission before anything changes on the site. '
        . 'We will email this address once, when it has been reviewed.'
        . kop_followup_footer($token);
    return (bool) @wp_mail($email, $subject, $body);
}

/** The update itself. $outcome is added, declined or moved. */
function kop_followup_compose($kind, $item_id, $outcome, $title, $token) {
    $kinds = kop_followup_kinds();
    $noun = $kinds[$kind]['noun'];
    $site = kop_followup_site_name();
    $ref = '#' . (int) $item_id;
    $title = trim(preg_replace('/\s+/u', ' ', wp_strip_all_tags((string) $title)));
    if (function_exists('mb_strlen') && mb_strlen($title) > 160) {
        $title = mb_substr($title, 0, 157) . '...';
    }

    if ($outcome === 'added') {
        $subject = '[' . $site . '] Your ' . $noun . ' was added';
        $body = 'Your ' . $noun . ' (' . $ref . ') has been reviewed and added to ' . $site . ".\n\n"
            . ($title !== '' ? $title . "\n\n" : '')
            . 'Thank you. Information like this is how the records stay complete and correct.' . "\n"
            . home_url('/');
    } elseif ($outcome === 'moved') {
        $subject = '[' . $site . '] Your ' . $noun . ' was reviewed';
        $body = 'Your ' . $noun . ' (' . $ref . ') has been reviewed. We kept it and filed it in another part of the site '
            . 'where it fits better (for example as a lawsuit, or as a link on a program\'s page).' . "\n\n"
            . 'Thank you for sending it.';
    } else {
        $subject = '[' . $site . '] Your ' . $noun . ' was reviewed';
        $body = 'Your ' . $noun . ' (' . $ref . ') has been reviewed and was not added to the site this time. '
            . 'Usually that is because we already have it, it falls outside what we track, or we could not confirm it from a source.' . "\n\n"
            . 'If you have more to go on, such as a link or a document, you are welcome to send it in again.' . "\n"
            . home_url('/');
    }
    return array($subject, $body . kop_followup_footer($token));
}

/** Reads statuses and titles for a set of items of one kind: id => [status, title]. */
function kop_followup_read_items($kind, array $ids) {
    $kinds = kop_followup_kinds();
    $k = $kinds[$kind];
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    if (!$ids) {
        return array();
    }
    $out = array();
    if ($k['db'] === 'wp') {
        global $wpdb;
        $rows = $wpdb->get_results("SELECT id, {$k['status']} AS st, {$k['title']} AS title FROM {$k['table']} WHERE id IN (" . implode(',', $ids) . ')', ARRAY_A);
    } else {
        $pdo = function_exists('kop_seed_pdo') ? kop_seed_pdo() : null;
        if (!$pdo) {
            return null;   // cannot tell; leave these waiting
        }
        $stmt = $pdo->prepare("SELECT id, {$k['status']} AS st, {$k['title']} AS title FROM {$k['table']} WHERE id IN (" . implode(',', array_fill(0, count($ids), '?')) . ')');
        $stmt->execute($ids);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    foreach ((array) $rows as $r) {
        $out[(int) $r['id']] = array((string) $r['st'], (string) $r['title']);
    }
    return $out;
}

/**
 * One pass: note new decisions, send the ones that have stood long enough,
 * drop the ones whose item is gone. Returns counts for the test script.
 *
 * @param int|null $now Unix time (tests pass a later one instead of waiting).
 */
function kop_followup_run($now = null) {
    $now = $now === null ? time() : (int) $now;
    $counts = array('sent' => 0, 'noted' => 0, 'reopened' => 0, 'gone' => 0, 'waiting' => 0);
    if (get_option('kop_followup_db') !== KOP_FOLLOWUP_DB_VERSION) {
        return $counts;
    }
    global $wpdb;
    $table = kop_followup_table();
    $rows = $wpdb->get_results("SELECT * FROM {$table} WHERE state = 'waiting' ORDER BY id LIMIT 500");
    if (!$rows) {
        return $counts;
    }
    $kinds = kop_followup_kinds();
    $by_kind = array();
    foreach ($rows as $r) {
        $by_kind[$r->kind][] = $r;
    }
    $stamp = gmdate('Y-m-d H:i:s', $now);
    foreach ($by_kind as $kind => $list) {
        if (!isset($kinds[$kind])) {
            continue;
        }
        $k = $kinds[$kind];
        try {
            $items = kop_followup_read_items($kind, wp_list_pluck($list, 'item_id'));
        } catch (Throwable $e) {
            error_log('kop_followup_run read ' . $kind . ' failed: ' . $e->getMessage());
            continue;
        }
        if ($items === null) {
            continue;
        }
        foreach ($list as $f) {
            $item = isset($items[(int) $f->item_id]) ? $items[(int) $f->item_id] : null;
            $status = $item ? $item[0] : '';
            if (!$item || in_array($status, $k['gone'], true)) {
                $wpdb->update($table, array('state' => 'gone', 'email' => ''), array('id' => $f->id));
                $counts['gone']++;
                continue;
            }
            $outcome = in_array($status, $k['added'], true) ? 'added'
                : (in_array($status, $k['declined'], true) ? ((int) $f->moved ? 'moved' : 'declined') : '');
            if ($outcome === '') {
                if ($f->decided_seen_at !== null) {
                    // Undone: back in the queue, so wait for the next decision.
                    $wpdb->update($table, array('decided_seen_at' => null, 'outcome' => null, 'moved' => 0), array('id' => $f->id));
                    $counts['reopened']++;
                } else {
                    $counts['waiting']++;
                }
                continue;
            }
            if ($f->decided_seen_at === null || $f->outcome !== $outcome) {
                $wpdb->update($table, array('decided_seen_at' => $stamp, 'outcome' => $outcome), array('id' => $f->id));
                $counts['noted']++;
                continue;
            }
            if (strtotime($f->decided_seen_at . ' UTC') > $now - KOP_FOLLOWUP_SETTLE) {
                $counts['waiting']++;
                continue;
            }
            list($subject, $body) = kop_followup_compose($kind, (int) $f->item_id, $outcome, $outcome === 'added' ? $item[1] : '', $f->token);
            $sent = function_exists('wp_mail') && is_email($f->email) ? (bool) @wp_mail($f->email, $subject, $body) : false;
            if (!$sent) {
                error_log('kop_followup_run: mail for follow-up ' . (int) $f->id . ' was not accepted');
            }
            $wpdb->update($table, array('state' => $sent ? 'sent' : 'failed', 'email' => '', 'sent_at' => $stamp), array('id' => $f->id));
            if ($sent) {
                $counts['sent']++;
            }
        }
    }
    return $counts;
}

add_filter('cron_schedules', function ($schedules) {
    if (!isset($schedules['kop_ten_minutes'])) {
        $schedules['kop_ten_minutes'] = array('interval' => 600, 'display' => 'Every 10 minutes (KOP)');
    }
    return $schedules;
});

/** Start the cron while anything is waiting; it stops itself when nothing is. */
function kop_followup_schedule() {
    if (!wp_next_scheduled(KOP_FOLLOWUP_CRON_HOOK)) {
        wp_schedule_event(time() + 300, 'kop_ten_minutes', KOP_FOLLOWUP_CRON_HOOK);
    }
}

add_action(KOP_FOLLOWUP_CRON_HOOK, function () {
    kop_followup_run();
    global $wpdb;
    if (get_option('kop_followup_db') === KOP_FOLLOWUP_DB_VERSION
        && !(int) $wpdb->get_var('SELECT COUNT(*) FROM ' . kop_followup_table() . " WHERE state = 'waiting'")) {
        wp_clear_scheduled_hook(KOP_FOLLOWUP_CRON_HOOK);
    }
});

/**
 * The stop link from the emails. A GET only shows a button (mail scanners
 * open links), the POST stops it.
 */
add_action('template_redirect', function () {
    if (!isset($_GET['kop_followup_stop'])) {
        return;
    }
    $token = preg_replace('/[^a-f0-9]/', '', strtolower((string) $_GET['kop_followup_stop']));
    $site = esc_html(kop_followup_site_name());
    $row = null;
    if (strlen($token) === 32 && get_option('kop_followup_db') === KOP_FOLLOWUP_DB_VERSION) {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . kop_followup_table() . ' WHERE token = %s', $token));
    }
    if (!$row || $row->state !== 'waiting') {
        wp_die('<p>There is nothing left to stop: this update has already been sent or stopped, and the address is no longer kept.</p>'
            . '<p><a href="' . esc_url(home_url('/')) . '">Back to ' . $site . '</a></p>', 'Email updates', array('response' => 200));
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        global $wpdb;
        $wpdb->update(kop_followup_table(), array('state' => 'stopped', 'email' => ''), array('id' => $row->id));
        wp_die('<p>Done. You will not get an email about this submission, and the address has been deleted.</p>'
            . '<p><a href="' . esc_url(home_url('/')) . '">Back to ' . $site . '</a></p>', 'Email updates', array('response' => 200));
    }
    wp_die('<p>Stop the one email about this submission and delete the address we kept for it?</p>'
        . '<form method="post" action="' . esc_url(kop_followup_stop_url($token)) . '"><button type="submit">Stop the email</button></form>',
        'Email updates', array('response' => 200));
});

/**
 * The checkbox and address box for a PHP-rendered form; js/submission-followup.js
 * draws the same markup for JS-built forms (kopFollowup.html()).
 *
 * @param string $id       Base id for the inputs.
 * @param string $email_id Id of an email box the form already has, used instead of a new one.
 */
function kop_followup_fields($id, $email_id = '') {
    $id = sanitize_html_class($id);
    $out = '<div class="kop-followup" data-kop-followup' . ($email_id !== '' ? ' data-kop-followup-for="' . esc_attr($email_id) . '"' : '') . '>'
        . '<label class="kop-followup__check" for="' . esc_attr($id) . '-check">'
        . '<input type="checkbox" id="' . esc_attr($id) . '-check" data-kop-followup-check> Email me when this has been reviewed</label>';
    if (kop_followup_newsletter_on()) {
        $out .= '<label class="kop-followup__check" for="' . esc_attr($id) . '-news">'
            . '<input type="checkbox" id="' . esc_attr($id) . '-news" data-kop-followup-news> Also sign me up for the ' . esc_html(kop_followup_site_name()) . ' newsletter</label>';
    }
    if ($email_id === '') {
        $out .= '<div class="kop-followup__email" hidden>'
            . '<label for="' . esc_attr($id) . '-email">Your email</label>'
            . '<input type="email" id="' . esc_attr($id) . '-email" data-kop-followup-email maxlength="190" autocomplete="email" placeholder="you@example.com">'
            . '</div>';
    }
    $out .= '<p class="kop-followup__hint" data-kop-followup-hint="notify" hidden>One email when it is added or turned down. Your address is never published, and we delete it once that email is sent.</p>'
        . '<p class="kop-followup__hint" data-kop-followup-hint="news" hidden>The newsletter is separate: you can unsubscribe from any issue.</p>'
        . '</div>';
    return $out;
}

/** True when the MailerLite plugin is connected, so the newsletter box can do something. */
function kop_followup_newsletter_on() {
    return function_exists('kop_newsletter_queue') && (bool) get_option('mailerlite_api_key');
}

/** The helper script + styles, on every front-end page (small; forms appear in modals anywhere). */
add_action('wp_enqueue_scripts', function () {
    $dir = get_stylesheet_directory();
    $uri = get_stylesheet_directory_uri();
    if (file_exists($dir . '/css/submission-followup.css')) {
        wp_enqueue_style('kop-submission-followup', $uri . '/css/submission-followup.css', array('kop-colors'), filemtime($dir . '/css/submission-followup.css'));
    }
    if (file_exists($dir . '/js/submission-followup.js')) {
        wp_enqueue_script('kop-submission-followup', $uri . '/js/submission-followup.js', array(), filemtime($dir . '/js/submission-followup.js'), false);
        wp_localize_script('kop-submission-followup', 'kopFollowupSettings', array(
            'newsletter' => kop_followup_newsletter_on(),
            'siteName'   => kop_followup_site_name(),
        ));
    }
});
