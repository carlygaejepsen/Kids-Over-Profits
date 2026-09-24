<?php
/**
 * Reader feedback on glossary entries: "my program used this too" and
 * "suggest a correction".
 *
 * Each glossary entry carries two buttons (js/glossary.js) that open one
 * small form and POST it to /wp-json/kop/v1/glossary-feedback. Submissions
 * land in {prefix}kop_glossary_feedback for review under KOP Data Tools >
 * Glossary Feedback; nothing reaches the page until someone acts on it in
 * the Glossary Editor (inc/glossary-editor.php). The admin is emailed on each
 * submission.
 *
 * Plain text only: tags are stripped, lengths capped, a honeypot field and
 * a per-IP hourly limit keep the queue usable. No nonce, because the page is
 * cached for logged-out readers and a cached nonce would go stale.
 */

if (!defined('ABSPATH')) {
    exit;
}

define('KOP_GLOSSARY_FEEDBACK_DB_VERSION', '1');

function kop_glossary_feedback_table() {
    global $wpdb;
    return $wpdb->prefix . 'kop_glossary_feedback';
}

/** Create the table once per schema version. */
function kop_glossary_feedback_ensure_table() {
    if (get_option('kop_glossary_feedback_db') === KOP_GLOSSARY_FEEDBACK_DB_VERSION) {
        return;
    }
    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    $table = kop_glossary_feedback_table();
    $charset = $wpdb->get_charset_collate();
    dbDelta("CREATE TABLE {$table} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        created_at DATETIME NOT NULL,
        kind VARCHAR(20) NOT NULL,
        term_id VARCHAR(120) NOT NULL,
        term VARCHAR(200) NOT NULL,
        program VARCHAR(200) NULL,
        details TEXT NULL,
        source VARCHAR(500) NULL,
        contact VARCHAR(200) NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'new',
        ip_hash CHAR(64) NULL,
        PRIMARY KEY  (id),
        KEY status (status),
        KEY term_id (term_id),
        KEY ip_created (ip_hash, created_at)
    ) {$charset};");
    update_option('kop_glossary_feedback_db', KOP_GLOSSARY_FEEDBACK_DB_VERSION);
}

/** The kinds of feedback, as the form and the admin screen name them. */
function kop_glossary_feedback_kinds() {
    return array(
        'used_at'    => 'Used at another program',
        'correction' => 'Correction',
    );
}

function kop_glossary_feedback_statuses() {
    return array(
        'new'       => 'New',
        'added'     => 'Added to glossary',
        'dismissed' => 'Dismissed',
    );
}

/** The glossary entry with this id, or null. */
function kop_glossary_feedback_find_entry($id) {
    $data = function_exists('kop_glossary_data') ? kop_glossary_data() : null;
    if (!$data) {
        return null;
    }
    foreach (kop_glossary_all_entries($data) as $entry) {
        if ($entry['id'] === $id) {
            return $entry;
        }
    }
    return null;
}

add_action('rest_api_init', function () {
    register_rest_route('kop/v1', '/glossary-feedback', array(
        'methods'             => 'POST',
        'callback'            => 'kop_glossary_feedback_submit',
        'permission_callback' => '__return_true',
    ));
});

/** Plain text from a request field, tags stripped, capped at $max characters. */
function kop_glossary_feedback_text($value, $max) {
    $text = trim(wp_strip_all_tags((string) $value));
    $text = preg_replace("/\r\n?/", "\n", $text);
    return function_exists('mb_substr') ? mb_substr($text, 0, $max) : substr($text, 0, $max);
}

function kop_glossary_feedback_submit(WP_REST_Request $request) {
    $fail = function ($message, $code = 400) {
        return new WP_REST_Response(array('success' => false, 'error' => $message), $code);
    };

    /* A field no person fills in; a bot that fills every field is thanked
     * and dropped. */
    if (trim((string) $request->get_param('website')) !== '') {
        return new WP_REST_Response(array('success' => true), 200);
    }

    $kinds = kop_glossary_feedback_kinds();
    $kind = (string) $request->get_param('kind');
    if (!isset($kinds[$kind])) {
        return $fail('Unknown kind of feedback.');
    }
    $entry = kop_glossary_feedback_find_entry((string) $request->get_param('term_id'));
    if (!$entry) {
        return $fail('That glossary entry was not found. Reload the page and try again.');
    }

    $program = kop_glossary_feedback_text($request->get_param('program'), 200);
    $details = kop_glossary_feedback_text($request->get_param('details'), 4000);
    $source  = kop_glossary_feedback_text($request->get_param('source'), 500);
    $contact = kop_glossary_feedback_text($request->get_param('contact'), 200);

    if ($kind === 'used_at' && $program === '') {
        return $fail('Please name the program.');
    }
    if ($kind === 'correction' && $details === '') {
        return $fail('Please say what should change.');
    }
    if ($contact !== '' && !is_email($contact)) {
        return $fail('That email address does not look right. Leave it blank if you would rather not give one.');
    }

    kop_glossary_feedback_ensure_table();
    global $wpdb;
    $table = kop_glossary_feedback_table();

    $ip = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '';
    $ip_hash = $ip !== '' ? hash('sha256', $ip . wp_salt('auth')) : null;
    if ($ip_hash) {
        $recent = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE ip_hash = %s AND created_at > %s",
            $ip_hash,
            gmdate('Y-m-d H:i:s', time() - HOUR_IN_SECONDS)
        ));
        if ($recent >= 10) {
            return $fail('Thank you for all of these. Please wait an hour before sending more.', 429);
        }
    }

    $term = $entry['term'] . ($entry['note'] !== '' ? ' (' . $entry['note'] . ')' : '');
    $ok = $wpdb->insert($table, array(
        'created_at' => gmdate('Y-m-d H:i:s'),
        'kind'       => $kind,
        'term_id'    => $entry['id'],
        'term'       => $term,
        'program'    => $program !== '' ? $program : null,
        'details'    => $details !== '' ? $details : null,
        'source'     => $source !== '' ? $source : null,
        'contact'    => $contact !== '' ? $contact : null,
        'status'     => 'new',
        'ip_hash'    => $ip_hash,
    ));
    if (!$ok) {
        return $fail('Your note could not be saved. Please try again in a minute.', 500);
    }
    $id = (int) $wpdb->insert_id;

    /* Tell the admin. Reader text goes only to the site's own address. */
    $admin = get_option('admin_email');
    if ($admin) {
        $body = $kinds[$kind] . ' for "' . $term . "\"\n\n"
            . ($program !== '' ? 'Program: ' . $program . "\n" : '')
            . ($details !== '' ? "Details:\n" . $details . "\n\n" : '')
            . ($source !== '' ? 'Source: ' . $source . "\n" : '')
            . ($contact !== '' ? 'Contact: ' . $contact . "\n" : '')
            . "\nEntry: " . home_url('/' . KOP_GLOSSARY_SLUG . '/#' . $entry['id'])
            . "\nReview: " . admin_url('admin.php?page=kop-glossary-feedback') . "\n";
        @wp_mail($admin, '[KOP] Glossary: ' . strtolower($kinds[$kind]) . ' for "' . $term . '" (#' . $id . ')', $body);
    }

    return new WP_REST_Response(array('success' => true, 'id' => $id), 200);
}

/* ---- Review screen --------------------------------------------------- */

function kop_register_glossary_feedback_menu() {
    if (!function_exists('kop_tools_parent_slug')) {
        return;
    }
    add_submenu_page(
        kop_tools_parent_slug(),
        'Glossary Feedback',
        'Glossary Feedback',
        'manage_options',
        'kop-glossary-feedback',
        'kop_render_glossary_feedback_page'
    );
}
add_action('admin_menu', 'kop_register_glossary_feedback_menu', 21);

function kop_render_glossary_feedback_page() {
    global $wpdb;
    if (!current_user_can('manage_options')) {
        wp_die('Not authorized', 'Access Denied', array('response' => 403));
    }
    kop_glossary_feedback_ensure_table();
    $table    = kop_glossary_feedback_table();
    $statuses = kop_glossary_feedback_statuses();
    $kinds    = kop_glossary_feedback_kinds();

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['kop_gf_id'], $_POST['kop_gf_status'])) {
        check_admin_referer('kop_glossary_feedback');
        $id = (int) $_POST['kop_gf_id'];
        $status = sanitize_key($_POST['kop_gf_status']);
        if ($id > 0 && isset($statuses[$status])) {
            $wpdb->update($table, array('status' => $status), array('id' => $id));
            echo '<div class="notice notice-success is-dismissible"><p>#' . esc_html($id) . ' marked '
                . esc_html(strtolower($statuses[$status])) . '.</p></div>';
        }
    }

    $filter = isset($_GET['gf_status']) ? sanitize_key($_GET['gf_status']) : 'new';
    if ($filter !== 'all' && !isset($statuses[$filter])) {
        $filter = 'new';
    }

    $counts = array();
    foreach ($wpdb->get_results("SELECT status, COUNT(*) AS n FROM {$table} GROUP BY status") as $row) {
        $counts[$row->status] = (int) $row->n;
    }

    $base = admin_url('admin.php?page=kop-glossary-feedback');
    echo '<div class="wrap"><h1>Glossary Feedback</h1>';
    echo '<p>Reader notes from the buttons under each entry on <a href="' . esc_url(home_url('/' . KOP_GLOSSARY_SLUG . '/')) . '" target="_blank" rel="noopener">the glossary</a>. '
        . 'To act on one, follow its Edit entry link to the <a href="' . esc_url(admin_url('admin.php?page=kop-glossary-editor')) . '">Glossary Editor</a>; saving there marks the note added.</p>';
    echo '<ul class="subsubsub">';
    $tabs = array_merge($statuses, array('all' => 'All'));
    $i = 0;
    foreach ($tabs as $key => $label) {
        $n = $key === 'all' ? array_sum($counts) : ($counts[$key] ?? 0);
        echo '<li><a href="' . esc_url(add_query_arg('gf_status', $key, $base)) . '"' . ($filter === $key ? ' class="current"' : '') . '>'
            . esc_html($label) . ' (' . (int) $n . ')</a>' . (++$i < count($tabs) ? ' | ' : '') . '</li>';
    }
    echo '</ul><div style="clear:both"></div>';

    $rows = $filter === 'all'
        ? $wpdb->get_results("SELECT * FROM {$table} ORDER BY created_at DESC LIMIT 300")
        : $wpdb->get_results($wpdb->prepare("SELECT * FROM {$table} WHERE status = %s ORDER BY created_at DESC LIMIT 300", $filter));

    if (!$rows) {
        echo '<p>Nothing here.</p></div>';
        return;
    }

    echo '<table class="widefat striped"><thead><tr>'
        . '<th style="width:50px">#</th><th style="width:120px">When</th><th style="width:200px">Entry</th>'
        . '<th>Note</th><th style="width:110px">Status</th><th style="width:230px">Mark as</th>'
        . '</tr></thead><tbody>';
    foreach ($rows as $r) {
        echo '<tr>';
        echo '<td>' . (int) $r->id . '</td>';
        echo '<td>' . esc_html(get_date_from_gmt($r->created_at, 'M j, Y g:ia')) . '</td>';
        echo '<td><a href="' . esc_url(home_url('/' . KOP_GLOSSARY_SLUG . '/#' . $r->term_id)) . '" target="_blank" rel="noopener"><strong>'
            . esc_html($r->term) . '</strong></a><br><span style="color:#666">' . esc_html($kinds[$r->kind] ?? $r->kind) . '</span>'
            . '<br><a href="' . esc_url(add_query_arg(array('view' => 'edit', 'entry' => $r->term_id, 'feedback' => (int) $r->id), admin_url('admin.php?page=kop-glossary-editor'))) . '">Edit entry</a></td>';
        echo '<td>';
        if ($r->program) {
            echo '<p style="margin:0 0 6px"><em>Program:</em> <strong>' . esc_html($r->program) . '</strong></p>';
        }
        if ($r->details) {
            echo '<p style="margin:0 0 6px">' . nl2br(esc_html($r->details)) . '</p>';
        }
        if ($r->source) {
            echo '<p style="margin:0 0 6px"><em>Source:</em> ' . esc_html($r->source) . '</p>';
        }
        if ($r->contact) {
            echo '<p style="margin:0"><em>Contact:</em> <a href="mailto:' . esc_attr($r->contact) . '">' . esc_html($r->contact) . '</a></p>';
        }
        echo '</td>';
        echo '<td>' . esc_html($statuses[$r->status] ?? $r->status) . '</td>';
        echo '<td>';
        foreach ($statuses as $key => $label) {
            if ($key === $r->status) {
                continue;
            }
            echo '<form method="post" style="display:inline-block;margin:0 4px 4px 0">';
            wp_nonce_field('kop_glossary_feedback');
            echo '<input type="hidden" name="kop_gf_id" value="' . (int) $r->id . '">'
                . '<input type="hidden" name="kop_gf_status" value="' . esc_attr($key) . '">'
                . '<button type="submit" class="button button-small">' . esc_html($label) . '</button></form>';
        }
        echo '</td></tr>';
    }
    echo '</tbody></table></div>';
}
