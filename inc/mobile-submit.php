<?php
/**
 * "Send to KOP" in the mobile app (github.com/carlygaejepsen/kids-over-profits-mobile), the
 * browser extension without an account, and the /send/ page (via = app | extension | web).
 *
 * The public version of the browser extension (inc/source-submissions.php):
 * anyone can send a link or facility information, and everything lands in
 * the review queue that already handles it. Nothing is published here.
 *
 *   article / lawsuit / legislation / website -> the extension's inserts
 *     (news_submissions 'submitted', lawsuits and legislation 'pending',
 *     kop_source posts 'pending'), same duplicate rules
 *   facility_new / facility_correction -> suggested_edits 'pending', like the
 *     public data form (api/save-suggestion.php)
 *
 * Signed-in reviewers in the app use the extension's own routes instead.
 * Duplicates are reported as a type and "on the site" / "in review" only:
 * never an id, a title or an admin link, since pending items are not public.
 *
 * Routes (public):
 *   POST /wp-json/kop/v1/mobile/submit
 *   GET  /wp-json/kop/v1/mobile/check?url=&title=&type=
 */

if (!defined('ABSPATH')) {
    exit;
}

const KOP_MSUB_HOURLY_CAP = 12;

function kop_msub_link_types() {
    return array('article', 'lawsuit', 'legislation', 'website');
}

function kop_msub_types() {
    return array_merge(kop_msub_link_types(), array('facility_new', 'facility_correction'));
}

/** One sender's count this hour, keyed by a hash of their address (never stored as is). */
function kop_msub_rate_key() {
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    return 'kop_msub_' . substr(hash_hmac('sha256', $ip, wp_salt('nonce')), 0, 24);
}

function kop_msub_rate_limited() {
    $count = (int) get_transient(kop_msub_rate_key());
    return $count >= KOP_MSUB_HOURLY_CAP;
}

function kop_msub_rate_count() {
    $key = kop_msub_rate_key();
    set_transient($key, (int) get_transient($key) + 1, HOUR_IN_SECONDS);
}

/** Where a public send came from: the app, the browser extension or the site's /send/ page. */
function kop_msub_via(array $p) {
    $labels = array('app' => 'mobile app', 'extension' => 'browser extension', 'web' => 'Send page');
    $via = sanitize_key((string) ($p['via'] ?? 'app'));
    return $labels[$via] ?? $labels['app'];
}

/** Who sent it, as the records tables write it. */
function kop_msub_submitter(array $p) {
    $name = kop_ext_text($p['submitter_name'] ?? '', 120);
    return mb_substr(($name !== '' ? $name . ' ' : '') . '(' . kop_msub_via($p) . ')', 0, 255);
}

/** Public form of the extension's duplicate list: type and whether it is live. */
function kop_msub_public_duplicates(array $dupes) {
    $live = array('approved', 'published', 'publish', 'active');
    $out = array();
    $seen = array();
    foreach ($dupes as $d) {
        $status = in_array(strtolower((string) ($d['status'] ?? '')), $live, true) ? 'on the site' : 'in review';
        $key = $d['type'] . '|' . $status;
        if (!isset($seen[$key])) {
            $seen[$key] = true;
            $out[] = array('type' => (string) $d['type'], 'status' => $status);
        }
    }
    return $out;
}

/** Year as text when it looks like one, else ''. */
function kop_msub_year($value) {
    $value = trim((string) $value);
    return preg_match('/^(1[89]|20)\d{2}$/', $value) ? $value : '';
}

/**
 * The suggested_edits document for a facility the app sends: the data form's
 * shape (facilities[].identification / locationDetails / operatingPeriod),
 * so the Submissions Review screen reads it like any other suggestion.
 */
function kop_msub_facility_document(array $p, $type) {
    $name = kop_ext_text($type === 'facility_new' ? ($p['name'] ?? '') : ($p['facility'] ?? ''), 255);
    if ($name === '') {
        return new WP_Error('kop_no_name', 'The facility name is required.', array('status' => 400));
    }
    $city  = kop_ext_text($p['city'] ?? '', 120);
    $state = strtoupper(kop_ext_text($p['state'] ?? '', 40));
    $facility = array(
        'identification' => array('name' => $name),
        'location'       => trim($city . ($city !== '' && $state !== '' ? ', ' : '') . $state),
        'locationDetails' => array_filter(array(
            'city'    => $city,
            'state'   => $state,
            'country' => kop_ext_text($p['country'] ?? '', 80),
        )),
    );
    if ($type === 'facility_correction') {
        $id = (int) ($p['facility_id'] ?? 0);
        if ($id > 0) {
            $facility['facilityId'] = $id;
        }
    } else {
        $other = kop_ext_list($p['other_names'] ?? array());
        if ($other) {
            $facility['identification']['otherNames'] = $other;
        }
        $operator = kop_ext_text($p['operator'] ?? '', 255);
        if ($operator !== '') {
            $facility['identification']['currentOperator'] = $operator;
        }
        $start = kop_msub_year($p['start_year'] ?? '');
        $end   = kop_msub_year($p['end_year'] ?? '');
        if ($start !== '' || $end !== '') {
            $facility['operatingPeriod'] = array_filter(array('startYear' => $start, 'endYear' => $end));
        }
        $website = esc_url_raw(trim((string) ($p['website'] ?? '')), array('http', 'https'));
        if ($website !== '') {
            $facility['website'] = $website;
        }
        $kind = kop_ext_text($p['program_type'] ?? '', 120);
        if ($kind !== '') {
            $facility['type'] = $kind;
        }
    }
    $data = array(
        'projectName' => $name,
        'name'        => $name,
        'facilities'  => array($facility),
        'source'      => kop_msub_via($p),
    );
    if (!empty($facility['identification']['currentOperator'])) {
        $data['operator'] = array('name' => $facility['identification']['currentOperator']);
    }
    return $data;
}

function kop_msub_insert_facility(array $p, $type, $submitter, $note) {
    if ($type === 'facility_correction' && trim($note) === '') {
        return new WP_Error('kop_no_notes', 'Say what is wrong or what to add.', array('status' => 400));
    }
    $data = kop_msub_facility_document($p, $type);
    if (is_wp_error($data)) {
        return $data;
    }
    $url = esc_url_raw(trim((string) ($p['url'] ?? '')), array('http', 'https'));
    $reason = array(($type === 'facility_new' ? 'New facility' : 'Correction') . ', sent from the ' . kop_msub_via($p) . '.');
    if ($note !== '') {
        $reason[] = $note;
    }
    if ($url !== '') {
        $reason[] = 'Source: ' . $url;
    }
    $reason[] = 'From: ' . $submitter;
    $reason = mb_substr(implode("\n\n", $reason), 0, 6000);

    $pdo = function_exists('kop_seed_pdo') ? kop_seed_pdo() : null;
    if (!$pdo) {
        return new WP_Error('kop_no_db', 'The records database is not reachable.', array('status' => 500));
    }
    $master_id = substr(preg_replace('/[^a-zA-Z0-9\s\-_]/', '', $data['projectName']), 0, 255);
    $stmt = $pdo->prepare(
        "INSERT INTO suggested_edits (master_id, edited_json_data, reason, submitter_ip, status, created_at)
         VALUES (?, ?, ?, ?, 'pending', NOW())"
    );
    $stmt->execute(array(
        $master_id,
        json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
        $reason,
        (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'),
    ));
    $id = (int) $pdo->lastInsertId();
    if (function_exists('kop_notify_admins')) {
        kop_notify_admins('suggested_edit', $master_id, '', array(
            'Reason'    => $reason,
            'Record'    => $master_id,
            'Reference' => '#' . $id,
        ));
    }
    return $id;
}

function kop_msub_rest_submit(WP_REST_Request $req) {
    $p = $req->get_json_params();
    if (!is_array($p)) {
        $p = array();
    }
    // Filled in only by bots: answer as if saved, store nothing.
    if (!empty($p['website_hp'])) {
        return new WP_REST_Response(array('ok' => true, 'type' => 'website', 'queue' => 'review'), 201);
    }
    if (kop_msub_rate_limited()) {
        return new WP_Error('kop_rate_limited', 'That is a lot of sending for one hour. Please try again later.', array('status' => 429));
    }
    $type = sanitize_key((string) ($p['type'] ?? ''));
    if (!in_array($type, kop_msub_types(), true)) {
        return new WP_Error('kop_bad_type', 'Unknown kind of submission.', array('status' => 400));
    }
    $p['type'] = $type;
    // The extension's description field holds the sender's summary; the app has none.
    unset($p['description'], $p['tags']);

    $url = esc_url_raw(trim((string) ($p['url'] ?? '')), array('http', 'https'));
    if ($url !== '' && !wp_http_validate_url($url)) {
        $url = '';
    }
    if ($url === '' && in_array($type, kop_msub_link_types(), true)) {
        return new WP_Error('kop_bad_url', 'A valid http or https link is required.', array('status' => 400));
    }
    $p['url'] = $url;
    if (trim((string) ($p['title'] ?? '')) === '' && $url !== '') {
        $p['title'] = $url;
    }

    kop_ext_load_record_libs();
    $submitter = kop_msub_submitter($p);
    $note = kop_ext_note($p);
    $queue_labels = array(
        'article' => 'news review', 'lawsuit' => 'lawsuit review', 'legislation' => 'legislation review',
        'website' => 'website review', 'facility_new' => 'facility review', 'facility_correction' => 'facility review',
    );

    try {
        if (in_array($type, kop_msub_link_types(), true)) {
            $pdo = function_exists('kop_seed_pdo') ? kop_seed_pdo() : null;
            if (!$pdo) {
                return new WP_Error('kop_no_db', 'The records database is not reachable.', array('status' => 500));
            }
            $dupes = kop_ext_find_duplicates($pdo, $p);
            if ($dupes) {
                return new WP_REST_Response(array(
                    'code'       => 'kop_duplicate',
                    'message'    => 'We already have this link.',
                    'duplicates' => kop_msub_public_duplicates($dupes),
                ), 409);
            }
            switch ($type) {
                case 'article':
                    $id = kop_ext_insert_news($pdo, $p, $submitter, $note);
                    $kind = 'news';
                    break;
                case 'lawsuit':
                    $id = kop_ext_insert_lawsuit($pdo, $p, $submitter, $note);
                    $kind = 'lawsuit';
                    break;
                case 'legislation':
                    $id = kop_ext_insert_legislation($pdo, $p, $submitter, $note);
                    $kind = 'legislation';
                    break;
                default:
                    $id = kop_ext_insert_website($p, $submitter, $note);
                    $kind = '';
            }
        } else {
            $id = kop_msub_insert_facility($p, $type, $submitter, $note);
            $kind = 'suggested_edit';
        }
    } catch (Throwable $e) {
        error_log('kop mobile submit failed: ' . $e->getMessage());
        return new WP_Error('kop_save_failed', 'The site could not save this. Please try again later.', array('status' => 500));
    }
    if (is_wp_error($id)) {
        return $id;
    }
    kop_msub_rate_count();

    // "Email me when this has been reviewed" and the newsletter box (inc/submission-followup.php).
    if ($kind !== '' && function_exists('kop_followup_register')) {
        kop_followup_register($kind, (int) $id, $p);
    } elseif (function_exists('kop_newsletter_queue') && function_exists('kop_newsletter_email_from')) {
        kop_newsletter_queue(kop_newsletter_email_from($p), 'website');
    }

    return new WP_REST_Response(array(
        'ok'    => true,
        'type'  => $type,
        'queue' => $queue_labels[$type],
    ), 201);
}

function kop_msub_rest_check(WP_REST_Request $req) {
    $url = esc_url_raw(trim((string) $req['url']), array('http', 'https'));
    if ($url === '') {
        return array('duplicate' => false, 'duplicates' => array());
    }
    kop_ext_load_record_libs();
    $pdo = function_exists('kop_seed_pdo') ? kop_seed_pdo() : null;
    if (!$pdo) {
        return new WP_Error('kop_no_db', 'The records database is not reachable.', array('status' => 500));
    }
    try {
        $dupes = kop_ext_find_duplicates($pdo, array(
            'url'   => $url,
            'title' => kop_ext_text($req['title'] ?? '', 500),
            'type'  => sanitize_key((string) $req['type']),
        ));
    } catch (Throwable $e) {
        error_log('kop mobile check failed: ' . $e->getMessage());
        return new WP_Error('kop_check_failed', 'The duplicate check failed.', array('status' => 500));
    }
    $public = kop_msub_public_duplicates($dupes);
    return array('duplicate' => !empty($public), 'duplicates' => $public);
}

add_action('rest_api_init', function () {
    register_rest_route('kop/v1', '/mobile/submit', array(
        'methods'             => 'POST',
        'permission_callback' => '__return_true',
        'callback'            => 'kop_msub_rest_submit',
    ));
    register_rest_route('kop/v1', '/mobile/check', array(
        'methods'             => 'GET',
        'permission_callback' => '__return_true',
        'callback'            => 'kop_msub_rest_check',
        'args'                => array(
            'url' => array('required' => true),
        ),
    ));
});
