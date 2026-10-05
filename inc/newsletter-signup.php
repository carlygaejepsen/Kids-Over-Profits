<?php
/**
 * "Also sign me up for the newsletter" on the forms that ask for an email.
 *
 * The box is never ticked by default (js/submission-followup.js draws it next
 * to "Email me when this has been reviewed"; the bug reporter has its own).
 * Ticked, the form posts `newsletter_email` and the endpoint passes it here:
 * kop_followup_register() does that for every submission form.
 *
 * The address goes to MailerLite through the official plugin's own connection
 * (options mailerlite_api_key / mailerlite_platform, 2 = the new MailerLite),
 * in a WP-Cron event a moment later so the form's answer does not wait on
 * MailerLite. Someone who unsubscribed is never set back to active: the call
 * names no status.
 *
 * The group: KOP_NEWSLETTER_GROUP_ID (wp-config.php or api/config.local.php)
 * or the kop_newsletter_group_id option, else the groups the plugin's own
 * signup form adds people to, else none (the subscriber is still in the
 * account's list).
 */

if (!defined('ABSPATH')) {
    exit;
}

const KOP_NEWSLETTER_HOOK = 'kop_newsletter_subscribe';

/** The address from a form's request, '' when the box was not ticked. */
function kop_newsletter_email_from($input) {
    if (!is_array($input)) {
        return '';
    }
    $email = isset($input['newsletter_email']) ? trim((string) $input['newsletter_email']) : '';
    $email = substr($email, 0, 190);
    return ($email !== '' && is_email($email)) ? $email : '';
}

/**
 * Queue one signup. Best effort, never blocks or fails the form.
 *
 * @param string $email  Address the person typed and chose to sign up.
 * @param string $source Which form (kept as a MailerLite field when one exists).
 * @return bool          True when queued.
 */
function kop_newsletter_queue($email, $source = '') {
    $email = trim((string) $email);
    if ($email === '' || !is_email($email) || !get_option('mailerlite_api_key')) {
        return false;
    }
    // One try per address a day, so a form cannot be used to hammer the account.
    $key = 'kop_nl_' . md5(strtolower($email));
    if (get_transient($key)) {
        return true;
    }
    set_transient($key, 1, DAY_IN_SECONDS);
    wp_schedule_single_event(time(), KOP_NEWSLETTER_HOOK, array($email, (string) $source));
    if (function_exists('spawn_cron')) {
        spawn_cron();
    }
    return true;
}

/** Groups to add a subscriber to. */
function kop_newsletter_groups() {
    $configured = defined('KOP_NEWSLETTER_GROUP_ID') ? (string) KOP_NEWSLETTER_GROUP_ID : (string) get_option('kop_newsletter_group_id', '');
    $ids = array_filter(array_map('trim', explode(',', $configured)));
    if (!$ids) {
        $ids = kop_newsletter_plugin_form_groups();
    }
    return apply_filters('kop_newsletter_groups', array_values(array_unique($ids)));
}

/** The groups the MailerLite plugin's signup forms add people to (best effort). */
function kop_newsletter_plugin_form_groups() {
    global $wpdb;
    $table = $wpdb->prefix . 'mailerlite_forms';
    if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) {
        return array();
    }
    $ids = array();
    $walk = function ($value, $under_group = false) use (&$walk, &$ids) {
        if (is_array($value) || is_object($value)) {
            foreach ((array) $value as $k => $v) {
                $walk($v, $under_group || (is_string($k) && stripos($k, 'group') !== false));
            }
        } elseif ($under_group && (is_int($value) || (is_string($value) && preg_match('/^\d+(,\d+)*$/', $value)))) {
            foreach (explode(',', (string) $value) as $id) {
                if ($id !== '' && $id !== '0') {
                    $ids[] = $id;
                }
            }
        }
    };
    foreach ((array) $wpdb->get_col("SELECT data FROM {$table}") as $data) {
        $walk(maybe_unserialize($data));
    }
    return array_values(array_unique($ids));
}

/** The cron event: send the address to MailerLite. */
function kop_newsletter_subscribe_now($email, $source = '') {
    $key = (string) get_option('mailerlite_api_key');
    if ($key === '' || !is_email($email)) {
        return false;
    }
    $groups = kop_newsletter_groups();
    if ((string) get_option('mailerlite_platform') === '2') {
        // The new MailerLite (connect.mailerlite.com): upsert by email.
        $body = array('email' => $email);
        if ($groups) {
            $body['groups'] = array_map('strval', $groups);
        }
        $res = wp_remote_post('https://connect.mailerlite.com/api/subscribers', array(
            'timeout' => 15,
            'headers' => array('Authorization' => 'Bearer ' . $key, 'Content-Type' => 'application/json', 'Accept' => 'application/json'),
            'body'    => wp_json_encode($body),
        ));
    } else {
        // MailerLite Classic.
        $url = $groups
            ? 'https://api.mailerlite.com/api/v2/groups/' . rawurlencode($groups[0]) . '/subscribers'
            : 'https://api.mailerlite.com/api/v2/subscribers';
        $res = wp_remote_post($url, array(
            'timeout' => 15,
            'headers' => array('X-MailerLite-ApiKey' => $key, 'Content-Type' => 'application/json'),
            'body'    => wp_json_encode(array('email' => $email)),
        ));
    }
    $code = is_wp_error($res) ? 0 : (int) wp_remote_retrieve_response_code($res);
    if ($code < 200 || $code >= 300) {
        error_log('kop_newsletter_subscribe: MailerLite answered ' . ($code ?: $res->get_error_message())
            . ' for a signup from ' . ($source !== '' ? $source : 'a form'));
        return false;
    }
    return true;
}
add_action(KOP_NEWSLETTER_HOOK, 'kop_newsletter_subscribe_now', 10, 2);
