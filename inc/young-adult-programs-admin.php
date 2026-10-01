<?php
/**
 * KOP Tools > Young Adult Programs: the records inc/young-adult-programs.php
 * keeps, managed from one screen.
 *
 *   Programs      every program; Edit opens its form and its facts
 *   Edit          name, place, ages, years, notes and links; each fact
 *                 (from Woodbury Facts) can be taken off, which puts its
 *                 item back to review on Woodbury Facts
 *
 * Programs are added from the "Young adult programs (18+)" tab of
 * Woodbury Facts, or here by hand. Only plain text is posted (no HTML), so
 * the host firewall lets it through.
 */

if (!defined('ABSPATH')) {
    exit;
}

define('KOP_YA_ADMIN_PAGE', 'kop-young-adult-programs');

add_action('admin_menu', function () {
    if (!function_exists('kop_tools_parent_slug')) {
        return;
    }
    add_submenu_page(kop_tools_parent_slug(), 'Young Adult Programs', 'Young Adult Programs', 'manage_options',
        KOP_YA_ADMIN_PAGE, 'kop_render_ya_admin');
}, 21);

function kop_ya_admin_url($args = array()) {
    return add_query_arg($args, admin_url('admin.php?page=' . KOP_YA_ADMIN_PAGE));
}

/** Woodbury Facts items filed on a program go back to review (the fact or the program is gone). */
function kop_ya_admin_release_items($yid, $key = null) {
    global $wpdb;
    if (!function_exists('kop_wbf_table')) {
        return 0;
    }
    $n = 0;
    foreach ((array) $wpdb->get_results('SELECT pkey, applied FROM ' . kop_wbf_table() . " WHERE status = 'applied' AND applied LIKE '%\"young_adult\"%'", ARRAY_A) as $r) {
        $done = json_decode((string) $r['applied'], true);
        if ((int) ($done['id'] ?? 0) !== (int) $yid || ($key !== null && $r['pkey'] !== $key && ($done['done']['key'] ?? '') !== $key)) {
            continue;
        }
        $n += (int) $wpdb->update(kop_wbf_table(), array('status' => 'pending', 'applied' => null, 'ya' => 1), array('pkey' => $r['pkey']));
    }
    return $n;
}

function kop_ya_admin_handle(PDO $pdo) {
    check_admin_referer('kop_ya');
    $do = sanitize_key(wp_unslash($_POST['kop_ya_do'] ?? ''));
    $id = (int) ($_POST['kop_ya_id'] ?? 0);
    $by = wp_get_current_user()->display_name;
    try {
        switch ($do) {
            case 'save':
                $f = array();
                foreach (array('name', 'other_names', 'city', 'state', 'country', 'ages', 'program_type', 'run_by', 'opened', 'closed', 'status', 'notes', 'links') as $k) {
                    $f[$k] = isset($_POST['y'][$k]) ? (string) wp_unslash($_POST['y'][$k]) : '';
                }
                $f['review'] = !empty($_POST['y']['hidden']) ? 'pending' : 'approved';
                $yid = kop_ya_save($pdo, $f, $id, $by);
                return array($id ? 'Saved. The page shows the change now.' : 'Program added.', '', array('edit' => $yid));
            case 'drop_fact':
                $key = (string) wp_unslash($_POST['kop_ya_key'] ?? '');
                $gone = kop_ya_drop_fact($pdo, $id, $key);
                $back = $gone ? kop_ya_admin_release_items($id, $key) : 0;
                return array($gone ? 'Taken off.' . ($back ? ' Its item is back for review on Woodbury Facts.' : '') : 'That fact was not there.', '', array('edit' => $id));
            case 'delete':
                $p = kop_ya_get($pdo, $id);
                $back = kop_ya_admin_release_items($id);
                kop_ya_delete($pdo, $id);
                return array('Deleted' . ($p ? ' "' . $p['name'] . '"' : '') . '.' . ($back ? ' ' . $back . ' Woodbury items are back for review.' : ''), '', array());
        }
    } catch (Throwable $e) {
        return array('', $e->getMessage(), $id ? array('edit' => $id) : array());
    }
    return array('', 'Unknown action.', array());
}

function kop_render_ya_admin() {
    if (!current_user_can('manage_options')) {
        wp_die('Not authorized', 'Access Denied', array('response' => 403));
    }
    $pdo = kop_ya_pdo();
    echo '<div class="wrap kop-ya-admin">';
    kop_ya_admin_styles();
    echo '<h1>Young Adult Programs</h1>';
    if (!$pdo) {
        echo '<div class="notice notice-error"><p>The records database is not reachable.</p></div></div>';
        return;
    }
    kop_ya_install($pdo);
    $notice = $error = '';
    $view = array();
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['kop_ya_do'])) {
        list($notice, $error, $view) = kop_ya_admin_handle($pdo);
    }
    if (!$view && isset($_GET['edit'])) {
        $view = array('edit' => (int) $_GET['edit']);
    }
    if ($notice !== '') {
        echo '<div class="notice notice-success is-dismissible"><p>' . esc_html($notice) . '</p></div>';
    }
    if ($error !== '') {
        echo '<div class="notice notice-error"><p><strong>Not done.</strong> ' . esc_html($error) . '</p></div>';
    }
    $page = get_page_by_path(KOP_YA_PAGE);
    echo '<p class="kop-ya-muted">Programs for people 18 and older, kept apart from the troubled teen programs: they are never facility records, '
        . 'and appear on no facility page, map, hub or search. They are listed on the ';
    if ($page) {
        $url = $page->post_status === 'publish' ? get_permalink($page) : get_preview_post_link($page);
        echo '<a href="' . esc_url($url) . '" target="_blank" rel="noopener">Young Adult Programs page</a>'
            . ($page->post_status !== 'publish' ? ' (a draft: only admins can see it; publish it in Pages when it is ready)' : '');
    } else {
        echo 'Young Adult Programs page';
    }
    echo '. Most are added from the <a href="' . esc_url(admin_url('admin.php?page=kop-woodbury-facts&wbf_tab=youngadult')) . '">Young adult programs (18+)</a> tab of Woodbury Facts.</p>';

    if (!empty($view['edit']) || isset($_GET['add'])) {
        kop_ya_admin_form(!empty($view['edit']) ? kop_ya_get($pdo, (int) $view['edit']) : null);
    } else {
        $all = kop_ya_all($pdo, null);
        echo '<div class="kop-ya-box"><h2>Programs (' . count($all) . ')</h2><p><a class="button button-primary" href="'
            . esc_url(kop_ya_admin_url(array('add' => 1))) . '">Add a program</a></p>';
        if ($all) {
            echo '<table class="widefat striped"><thead><tr><th>Program</th><th>Place, ages and years</th><th>Facts</th><th></th></tr></thead><tbody>';
            foreach ($all as $p) {
                echo '<tr><td><strong>' . esc_html($p['name']) . '</strong>' . ($p['review'] === 'pending' ? ' <span class="kop-ya-muted">(hidden)</span>' : '')
                    . '</td><td>' . esc_html(kop_ya_meta_line($p)) . '</td><td>' . count(kop_ya_facts($p)) . '</td><td><a class="button" href="'
                    . esc_url(kop_ya_admin_url(array('edit' => $p['id']))) . '">Edit</a></td></tr>';
            }
            echo '</tbody></table>';
        }
        echo '</div>';
    }
    echo '</div>';
}

function kop_ya_admin_hidden($do, $id = 0) {
    wp_nonce_field('kop_ya');
    echo '<input type="hidden" name="kop_ya_do" value="' . esc_attr($do) . '"><input type="hidden" name="kop_ya_id" value="' . (int) $id . '">';
}

function kop_ya_admin_form($p) {
    $s = $p ?: array('id' => 0, 'name' => '', 'other_names' => '', 'city' => '', 'state' => '', 'country' => '', 'ages' => '', 'program_type' => '',
        'run_by' => '', 'opened' => '', 'closed' => '', 'status' => 'Unknown', 'notes' => '', 'links' => '', 'review' => 'approved', 'source' => '');
    echo '<p><a href="' . esc_url(kop_ya_admin_url()) . '">&larr; All programs</a></p>';
    echo '<div class="kop-ya-box"><h2>' . ($p ? esc_html($s['name']) : 'Add a program') . '</h2>';
    if (!empty($s['source'])) {
        echo '<p class="kop-ya-muted">From: ' . esc_html($s['source']) . '</p>';
    }
    echo '<form method="post"><table class="form-table kop-ya-form"><tbody>';
    kop_ya_admin_hidden('save', $s['id']);
    $text = function ($key, $label, $help = '', $width = '') use ($s) {
        echo '<tr><th><label for="kop-ya-' . $key . '">' . esc_html($label) . '</label></th><td><input type="text" id="kop-ya-' . $key . '" name="y[' . $key . ']" value="'
            . esc_attr((string) $s[$key]) . '"' . ($width ? ' style="width:' . $width . '"' : '') . '>' . ($help ? '<p class="description">' . esc_html($help) . '</p>' : '') . '</td></tr>';
    };
    $area = function ($key, $label, $help, $rows) use ($s) {
        echo '<tr><th><label for="kop-ya-' . $key . '">' . esc_html($label) . '</label></th><td><textarea id="kop-ya-' . $key . '" name="y[' . $key . ']" rows="' . (int) $rows . '">'
            . esc_textarea((string) $s[$key]) . '</textarea><p class="description">' . esc_html($help) . '</p></td></tr>';
    };
    $text('name', 'Name');
    $area('other_names', 'Other names', 'One per line: earlier names, other spellings.', 2);
    $text('city', 'Town or city', '', '240px');
    $text('state', 'State', 'For a US program: the state, e.g. UT or Utah.', '240px');
    $text('country', 'Country', 'Only outside the US.', '240px');
    $text('ages', 'Ages', 'As Woodbury or the program gives them, e.g. 18-26 or 18 and older.', '240px');
    $text('program_type', 'Described as', 'For example transitional living, college support, wilderness, substance use treatment.');
    $text('run_by', 'Run by', 'The company or owner, if known.');
    $text('opened', 'Opened', 'Year, if known.', '90px');
    $text('closed', 'Closed', 'Year, if known.', '90px');
    echo '<tr><th><label for="kop-ya-status">Still open?</label></th><td><select id="kop-ya-status" name="y[status]">';
    foreach (array('Unknown' => 'Not known', 'Open' => 'Still open', 'Closed' => 'Closed') as $v => $l) {
        echo '<option value="' . esc_attr($v) . '"' . selected($s['status'], $v, false) . '>' . esc_html($l) . '</option>';
    }
    echo '</select></td></tr>';
    $area('notes', 'Notes', 'Plain text. A blank line starts a new paragraph; **bold**, *italic* and [link text](https://...) work.', 4);
    $area('links', 'Other links', 'One per line, as: What it is | https://address.', 3);
    echo '<tr><th>Hide it</th><td><label><input type="checkbox" name="y[hidden]" value="1"' . ($s['review'] === 'pending' ? ' checked' : '')
        . '> Keep it off the public page for now</label></td></tr>';
    echo '</tbody></table><p><button class="button button-primary">Save</button></p></form>';
    if ($p) {
        echo '<form method="post" onsubmit="return confirm(\'Delete this program? Its Woodbury items go back for review.\')">';
        kop_ya_admin_hidden('delete', $s['id']);
        echo '<button class="button-link kop-ya-danger">Delete this program</button></form>';
    }
    echo '</div>';

    if ($p) {
        $facts = kop_ya_facts($p);
        $groups = kop_ya_fact_groups();
        echo '<div class="kop-ya-box"><h2>Facts (' . count($facts) . ')</h2>';
        if (!$facts) {
            echo '<p class="kop-ya-muted">None yet. Add them from the Young adult programs (18+) tab of Woodbury Facts.</p>';
        } else {
            echo '<table class="widefat striped"><tbody>';
            foreach ($facts as $f) {
                echo '<tr><td class="kop-ya-muted">' . esc_html($groups[$f['group'] ?? ''] ?? ($f['group'] ?? '')) . '</td><td>' . esc_html(kop_ya_fact_text($f['label'] ?? ''));
                foreach (array_slice((array) ($f['cites'] ?? array()), 0, 3) as $c) {
                    echo '<br><a href="' . esc_url($c['url']) . '" target="_blank" rel="noopener">' . esc_html('Woodbury Reports, ' . $c['label'] . ', p. ' . (int) $c['page']) . '</a>';
                }
                echo '</td><td class="kop-ya-actions"><form method="post">';
                kop_ya_admin_hidden('drop_fact', $s['id']);
                echo '<input type="hidden" name="kop_ya_key" value="' . esc_attr($f['key'] ?? '') . '"><button class="button-link kop-ya-danger">Take off</button></form></td></tr>';
            }
            echo '</tbody></table>';
        }
        echo '</div>';
    }
}

function kop_ya_admin_styles() {
    ?>
    <style>
        .kop-ya-admin .kop-ya-box { background: #fff; border: 1px solid #c3c4c7; padding: 12px 16px; margin: 16px 0; max-width: 1100px; }
        .kop-ya-admin .kop-ya-box h2 { margin-top: 4px; }
        .kop-ya-admin .kop-ya-muted { color: #646970; }
        .kop-ya-admin .kop-ya-danger { color: #b32d2e; }
        .kop-ya-admin .kop-ya-actions { white-space: nowrap; width: 1%; }
        .kop-ya-admin .kop-ya-form input[type=text], .kop-ya-admin .kop-ya-form textarea { width: 100%; max-width: 640px; }
    </style>
    <?php
}
