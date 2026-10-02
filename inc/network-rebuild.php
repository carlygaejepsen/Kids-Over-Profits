<?php
/**
 * Rebuild the network map when the records it is drawn from change.
 *
 * The map reads js/data/network/graph.json, built by
 * scripts/build-network-graph.js from facilities_v2, the operator records,
 * the consultants and the memorial. The server has no Node, so the build runs
 * on GitHub (.github/workflows/build-network-map.yml), which copies those
 * tables over SSH, builds, tests, commits graph.json and deploys.
 *
 * This file decides when. Every hour WP-Cron hashes exactly the parts of
 * those tables the build reads (staff, owners, operators, past and other
 * names, years, status, consultants' past jobs, memorial counts). When the
 * hash moves, it asks GitHub to run the workflow. So staff added at KOP Tools
 * > Woodbury Facts reach the map within the hour plus the build (~10 min),
 * and nights with no change cost nothing.
 *
 * The GitHub token (fine-grained, this repo only, "Actions: read and write")
 * goes in api/config.local.php as define('KOP_GITHUB_DISPATCH_TOKEN', '...')
 * or in .env as KOP_GITHUB_DISPATCH_TOKEN=...; never in git. Without it the
 * check still runs and KOP Tools > Map Rebuild says what is missing.
 *
 * Test: php scripts/test-network-rebuild.php (against tmp/prod.sqlite).
 */

if (!defined('ABSPATH')) {
    exit;
}

define('KOP_NETWORK_REBUILD_REPO', 'carlygaejepsen/Kids-Over-Profits');
define('KOP_NETWORK_REBUILD_WORKFLOW', 'build-network-map.yml');
define('KOP_NETWORK_REBUILD_OPTION', 'kop_network_rebuild_state');

if (!function_exists('kop_network_rebuild_fingerprint')) {
    /**
     * Hash of what build-network-graph.js reads. $query runs one SELECT and
     * returns its rows as associative arrays; $prefix is the WP table prefix.
     * Facility rows are walked in id order a page at a time, keeping only the
     * json parts the build uses, so unrelated edits (inspections, documents,
     * descriptions) do not start a build.
     */
    function kop_network_rebuild_fingerprint(callable $query, $prefix) {
        $ctx = hash_init('sha256');
        $last = 0;
        do {
            $rows = $query('SELECT id, unique_name, name, name_key, state, status, start_year, end_year, json_data
                              FROM facilities_v2 WHERE id > ' . (int) $last . ' ORDER BY id LIMIT 500');
            foreach ($rows as $row) {
                $last = (int) $row['id'];
                $doc = json_decode((string) $row['json_data'], true);
                $f = is_array($doc) ? (isset($doc['facility']) && is_array($doc['facility']) ? $doc['facility'] : $doc) : array();
                unset($row['json_data']);
                $row['identification'] = $f['identification'] ?? null;
                $row['staff'] = $f['staff'] ?? null;
                $row['operatingPeriod'] = $f['operatingPeriod'] ?? null;
                hash_update($ctx, json_encode($row) . "\n");
            }
        } while (count($rows) === 500);

        $whole = array(
            'operators'           => "SELECT id, name, json_data FROM {$prefix}kop_operators ORDER BY id",
            'operator_facilities' => "SELECT operator_id, facility_id FROM {$prefix}kop_operator_facilities ORDER BY operator_id, facility_id",
            'consultants'         => 'SELECT json_data FROM referrers_master ORDER BY id',
            'memorial'            => "SELECT program, COUNT(*) AS n FROM memorial_victims WHERE publication_status = 'published' GROUP BY program ORDER BY program",
        );
        foreach ($whole as $label => $sql) {
            hash_update($ctx, '#' . $label . "\n");
            foreach ($query($sql) as $row) {
                hash_update($ctx, json_encode(array_values($row)) . "\n");
            }
        }
        // Person ids (inc/people.php): a merge draws two people as one. Absent before the table exists.
        try {
            $people = $query("SELECT id, name, name_key, aliases, merged_into FROM {$prefix}kop_people ORDER BY id");
            hash_update($ctx, "#people\n");
            foreach ($people as $row) hash_update($ctx, json_encode(array_values($row)) . "\n");
        } catch (Throwable $e) {
        }
        return hash_final($ctx);
    }
}

if (!function_exists('kop_network_rebuild_token')) {
    function kop_network_rebuild_token() {
        // api/config.php loads .env and config.local.php.
        if (function_exists('kop_seed_pdo')) {
            kop_seed_pdo();
        }
        if (defined('KOP_GITHUB_DISPATCH_TOKEN') && KOP_GITHUB_DISPATCH_TOKEN) {
            return (string) KOP_GITHUB_DISPATCH_TOKEN;
        }
        $env = getenv('KOP_GITHUB_DISPATCH_TOKEN');
        return $env ? (string) $env : '';
    }
}

if (!function_exists('kop_network_rebuild_dispatch')) {
    /** Ask GitHub to run the build. Returns '' on success, else the reason. */
    function kop_network_rebuild_dispatch($reason) {
        $token = kop_network_rebuild_token();
        if ($token === '') {
            return 'No GitHub token: add KOP_GITHUB_DISPATCH_TOKEN to api/config.local.php or .env.';
        }
        $url = 'https://api.github.com/repos/' . KOP_NETWORK_REBUILD_REPO . '/actions/workflows/'
            . KOP_NETWORK_REBUILD_WORKFLOW . '/dispatches';
        $resp = wp_remote_post($url, array(
            'timeout' => 20,
            'headers' => array(
                'Authorization'        => 'Bearer ' . $token,
                'Accept'               => 'application/vnd.github+json',
                'X-GitHub-Api-Version' => '2022-11-28',
                'User-Agent'           => 'kidsoverprofits-map-rebuild',
                'Content-Type'         => 'application/json',
            ),
            'body' => wp_json_encode(array('ref' => 'main', 'inputs' => array('reason' => substr($reason, 0, 200)))),
        ));
        if (is_wp_error($resp)) {
            return 'Could not reach GitHub: ' . $resp->get_error_message();
        }
        $code = (int) wp_remote_retrieve_response_code($resp);
        if ($code === 204) {
            return '';
        }
        $body = json_decode((string) wp_remote_retrieve_body($resp), true);
        return 'GitHub answered ' . $code . (is_array($body) && !empty($body['message']) ? ': ' . $body['message'] : '');
    }
}

if (!function_exists('kop_network_rebuild_check')) {
    /**
     * Compare the hash with the one the last build was started for, and start
     * one when it moved (or always, with $force). A failed request leaves the
     * old hash in place, so the next hour tries again.
     */
    function kop_network_rebuild_check($force = false, $reason = 'records changed') {
        global $wpdb;
        $state = get_option(KOP_NETWORK_REBUILD_OPTION, array());
        $state = is_array($state) ? $state : array();
        $state['checked'] = time();
        try {
            $hash = kop_network_rebuild_fingerprint(function ($sql) use ($wpdb) {
                $rows = $wpdb->get_results($sql, ARRAY_A);
                if ($wpdb->last_error) {
                    throw new RuntimeException($wpdb->last_error);
                }
                return $rows ?: array();
            }, $wpdb->prefix);
        } catch (Throwable $e) {
            $state['error'] = 'Could not read the records: ' . $e->getMessage();
            update_option(KOP_NETWORK_REBUILD_OPTION, $state, false);
            return $state;
        }
        $state['current'] = $hash;
        if ($force || $hash !== ($state['built'] ?? '')) {
            $error = kop_network_rebuild_dispatch($reason);
            $state['error'] = $error;
            if ($error === '') {
                $state['built'] = $hash;
                $state['dispatched'] = time();
                $state['reason'] = $reason;
            }
        } else {
            $state['error'] = '';
        }
        update_option(KOP_NETWORK_REBUILD_OPTION, $state, false);
        return $state;
    }
}

add_action('init', function () {
    if (!wp_next_scheduled('kop_network_rebuild_hourly')) {
        wp_schedule_event(time() + 900, 'hourly', 'kop_network_rebuild_hourly');
    }
});

add_action('kop_network_rebuild_hourly', function () {
    if (get_transient('kop_network_rebuild_lock')) {
        return;
    }
    set_transient('kop_network_rebuild_lock', 1, 10 * MINUTE_IN_SECONDS);
    kop_network_rebuild_check();
    delete_transient('kop_network_rebuild_lock');
});

/* ---- KOP Tools > Map Rebuild ------------------------------------------- */

add_action('admin_menu', function () {
    if (!function_exists('kop_tools_parent_slug')) return;
    add_submenu_page(kop_tools_parent_slug(), 'Map Rebuild', 'Map Rebuild', 'manage_options',
        'kop-network-rebuild', 'kop_network_rebuild_page');
}, 23);

add_action('admin_post_kop_network_rebuild_now', function () {
    if (!current_user_can('manage_options')) wp_die('Not allowed.');
    check_admin_referer('kop_network_rebuild_now');
    kop_network_rebuild_check(true, 'started by hand at KOP Tools > Map Rebuild');
    wp_safe_redirect(admin_url('admin.php?page=kop-network-rebuild&done=1'));
    exit;
});

if (!function_exists('kop_network_rebuild_page')) {
    function kop_network_rebuild_page() {
        if (!current_user_can('manage_options')) return;
        $state = get_option(KOP_NETWORK_REBUILD_OPTION, array());
        $state = is_array($state) ? $state : array();
        $when = function ($t) {
            return $t ? esc_html(wp_date('M j, Y g:i a', (int) $t)) : 'never';
        };
        $runs = 'https://github.com/' . KOP_NETWORK_REBUILD_REPO . '/actions/workflows/' . KOP_NETWORK_REBUILD_WORKFLOW;
        $pending = !empty($state['current']) && ($state['current'] !== ($state['built'] ?? ''));
        // A check from before the token was added leaves its message behind.
        if (!empty($state['error']) && strpos($state['error'], 'No GitHub token') === 0 && kop_network_rebuild_token() !== '') {
            $state['error'] = '';
        }
        echo '<div class="wrap"><h1>Map Rebuild</h1>';
        echo '<p>The network map is rebuilt on GitHub whenever the staff, owners, operators, names, years or status '
            . 'in the records change. This page checks every hour and starts a build only when something the map uses has changed. '
            . 'A build takes about ten minutes and then deploys by itself.</p>';
        if (!empty($_GET['done'])) {
            echo '<div class="notice notice-' . (empty($state['error']) ? 'success' : 'error') . '"><p>'
                . (empty($state['error']) ? 'Build started.' : esc_html($state['error'])) . '</p></div>';
        } elseif (!empty($state['error'])) {
            echo '<div class="notice notice-error"><p>' . esc_html($state['error']) . '</p></div>';
        }
        echo '<table class="widefat striped" style="max-width:720px"><tbody>'
            . '<tr><th>Last checked</th><td>' . $when($state['checked'] ?? 0) . '</td></tr>'
            . '<tr><th>Last build started</th><td>' . $when($state['dispatched'] ?? 0)
            . (!empty($state['reason']) ? ' (' . esc_html($state['reason']) . ')' : '') . '</td></tr>'
            . '<tr><th>Changes waiting</th><td>' . ($pending ? 'Yes, a build will start on the next check' : 'No') . '</td></tr>'
            . '<tr><th>GitHub token</th><td>' . (kop_network_rebuild_token() !== '' ? 'Set' : 'Missing') . '</td></tr>'
            . '</tbody></table>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin-top:16px">';
        wp_nonce_field('kop_network_rebuild_now');
        echo '<input type="hidden" name="action" value="kop_network_rebuild_now">';
        submit_button('Rebuild the map now', 'primary', 'submit', false);
        echo ' <a href="' . esc_url($runs) . '" target="_blank" rel="noopener">See the builds on GitHub</a></form></div>';
    }
}
