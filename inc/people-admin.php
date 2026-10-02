<?php
/**
 * KOP Tools > People: the person ids inc/people.php keeps.
 *
 *   People      every person with an id, searchable by name, other name or id
 *   Person      one person: name, other names and notes; every record that
 *               names them; "Same person as" (merge into another id, for
 *               one person under two names) and "Separate" (give one entry
 *               its own id, for two people with one name)
 *
 * Only plain text is posted, so the host firewall lets it through.
 */

if (!defined('ABSPATH')) {
    exit;
}

define('KOP_PEOPLE_ADMIN_PAGE', 'kop-people');

add_action('admin_menu', function () {
    if (!function_exists('kop_tools_parent_slug')) return;
    add_submenu_page(kop_tools_parent_slug(), 'People', 'People', 'manage_options', KOP_PEOPLE_ADMIN_PAGE, 'kop_people_admin_page');
}, 22);

function kop_people_admin_url($args = array()) {
    return add_query_arg(array_merge(array('page' => KOP_PEOPLE_ADMIN_PAGE), $args), admin_url('admin.php'));
}

/** Handles a posted action; returns a notice, or ''. */
function kop_people_admin_handle() {
    if (empty($_POST['kop_people_do'])) return '';
    check_admin_referer('kop_people');
    $do = sanitize_key(wp_unslash($_POST['kop_people_do']));
    $id = (int) ($_POST['person'] ?? 0);
    $t = kop_people_table('people');
    if ($do === 'sync') {
        $s = kop_people_sync();
        return sprintf('Synced: %d entries on %d facilities, %d new ids, %d entries given an id.', $s['entries'], $s['facilities'], $s['created'], $s['stamped']);
    }
    if ($do === 'save' && $id > 0) {
        $state = kop_people_load();
        if (!isset($state['rows'][$id])) return 'No such person.';
        $row = $state['rows'][$id];
        $name = trim(sanitize_text_field(wp_unslash($_POST['name'] ?? '')));
        $key = kop_people_key($name);
        if ($key === '') return 'A name needs a first and a last name.';
        $aliases = kop_people_alias_list(sanitize_textarea_field(wp_unslash($_POST['aliases'] ?? '')));
        // The old name stays an other name, so the entries written that way keep this id.
        if ($key !== $row['name_key'] && !in_array($row['name'], $aliases, true)) $aliases[] = $row['name'];
        $aliases = array_values(array_filter($aliases, static function ($a) use ($name) { return strcasecmp($a, $name) !== 0; }));
        kop_facility_db_exec("UPDATE {$t} SET name = ?, name_key = ?, aliases = ?, notes = ? WHERE id = ?",
            array($name, $key, implode("\n", $aliases), sanitize_textarea_field(wp_unslash($_POST['notes'] ?? '')), $id));
        return 'Saved.';
    }
    if ($do === 'merge' && $id > 0) {
        $into = kop_people_admin_find(sanitize_text_field(wp_unslash($_POST['into'] ?? '')), $id);
        if (is_string($into)) return $into;
        if (!kop_people_merge($id, $into)) return 'Nothing to merge.';
        kop_people_sync();
        wp_safe_redirect(kop_people_admin_url(array('person' => $into, 'merged' => $id)));
        exit;
    }
    if ($do === 'separate' && $id > 0) {
        $new = kop_people_separate((int) ($_POST['facility'] ?? 0), sanitize_text_field(wp_unslash($_POST['list'] ?? '')), (int) ($_POST['position'] ?? 0));
        if (!$new) return 'That entry is not there any more. Sync and try again.';
        kop_people_sync();
        wp_safe_redirect(kop_people_admin_url(array('person' => $new, 'separated' => $id)));
        exit;
    }
    return '';
}

/** "#12", "12" or an exact name/other name -> a person id, or an error message. */
function kop_people_admin_find($text, $not) {
    $text = trim(ltrim(trim($text), '#'));
    if ($text === '') return 'Type the other person\'s id or name.';
    $state = kop_people_load();
    if (ctype_digit($text)) {
        $id = kop_people_resolve($state, (int) $text);
        if ($id === 0) return 'No person #' . $text . '.';
    } else {
        $ids = array_values(array_diff($state['by_key'][kop_people_key($text)] ?? array(), array((int) $not)));
        if (count($ids) !== 1) return $ids ? 'More than one person has that name: use the id.' : 'Nobody else has that name.';
        $id = $ids[0];
    }
    return $id === kop_people_resolve($state, $not) ? 'That is the same person.' : $id;
}

/** Name and page URL of a record, cached per request. */
function kop_people_admin_record($kind, $id) {
    static $names = array();
    $k = $kind . $id;
    if (isset($names[$k])) return $names[$k];
    $id = (int) $id;
    if ($kind === 'operator') {
        $rows = kop_facility_db_rows('SELECT name FROM ' . kop_facility_table('operators') . ' WHERE id = ?', array($id));
        return $names[$k] = array('name' => $rows ? (string) $rows[0]['name'] : 'Operator #' . $id, 'url' => '');
    }
    $rows = kop_facility_db_rows('SELECT name, state FROM facilities_v2 WHERE id = ?', array($id));
    $url = '';
    if (function_exists('kop_facility_pages_index')) {
        $index = kop_facility_pages_index();
        if (!empty($index['ids'][$id]['slug'])) $url = kop_facility_pages_url_for_slug($index['ids'][$id]['slug']);
    }
    $name = $rows ? $rows[0]['name'] . ($rows[0]['state'] ? ', ' . $rows[0]['state'] : '') : 'Facility #' . $id . ' (gone)';
    return $names[$k] = array('name' => $name, 'url' => $url);
}

function kop_people_admin_page() {
    if (!current_user_can('manage_options')) return;
    kop_people_install();
    $notice = kop_people_admin_handle();
    if (isset($_GET['merged'])) $notice = 'Person #' . (int) $_GET['merged'] . ' is now this person; their entries moved here.';
    if (isset($_GET['separated'])) $notice = 'This entry has its own id now, apart from person #' . (int) $_GET['separated'] . '.';
    echo '<div class="wrap kop-people">';
    kop_people_admin_styles();
    if ($notice !== '') echo '<div class="notice notice-info"><p>' . esc_html($notice) . '</p></div>';
    $id = (int) ($_GET['person'] ?? 0);
    if ($id > 0) {
        kop_people_admin_person($id);
    } else {
        kop_people_admin_list();
    }
    echo '</div>';
}

function kop_people_admin_hidden($do, $id = 0) {
    wp_nonce_field('kop_people');
    echo '<input type="hidden" name="kop_people_do" value="' . esc_attr($do) . '">';
    if ($id) echo '<input type="hidden" name="person" value="' . (int) $id . '">';
}

function kop_people_admin_list() {
    $t = kop_people_table('people');
    $r = kop_people_table('roles');
    $q = trim(sanitize_text_field(wp_unslash($_GET['q'] ?? '')));
    $paged = max(1, (int) ($_GET['paged'] ?? 1));
    $per = 100;
    $where = 'p.merged_into IS NULL';
    $params = array();
    if ($q !== '') {
        if (ctype_digit(ltrim($q, '#'))) {
            $where .= ' AND p.id = ?';
            $params[] = (int) ltrim($q, '#');
        } else {
            $where .= ' AND (p.name LIKE ? OR p.aliases LIKE ?)';
            $like = '%' . $GLOBALS['wpdb']->esc_like($q) . '%';
            $params[] = $like;
            $params[] = $like;
        }
    }
    $total = (int) (kop_facility_db_rows("SELECT COUNT(*) AS n FROM {$t} p WHERE {$where}", $params)[0]['n'] ?? 0);
    $rows = kop_facility_db_rows("SELECT p.id, p.name, p.aliases, COUNT(r.person_id) AS n, COUNT(DISTINCT CONCAT(r.record_kind, r.record_id)) AS records
        FROM {$t} p LEFT JOIN {$r} r ON r.person_id = p.id WHERE {$where}
        GROUP BY p.id ORDER BY records DESC, p.name LIMIT {$per} OFFSET " . (($paged - 1) * $per), $params);
    $last = get_option('kop_people_last_sync', array());
    ?>
    <h1>People</h1>
    <p class="kop-people__intro">Everyone named on a facility's or a company's staff list has a person id. One person keeps one id across
        every record, so their career can be followed from program to program. New names get an id within the hour.
        Open a person to fix their name, join two ids that are one person, or split one name that is two people.</p>
    <form method="post" class="kop-people__bar">
        <?php kop_people_admin_hidden('sync'); ?>
        <span><?php echo $last ? esc_html(sprintf('Last sync %s ago: %d people named %d times.', human_time_diff((int) $last['at']), $total, (int) $last['roles'])) : 'Not synced yet.'; ?></span>
        <button type="submit" class="button">Sync now</button>
    </form>
    <form method="get" class="kop-people__bar">
        <input type="hidden" name="page" value="<?php echo esc_attr(KOP_PEOPLE_ADMIN_PAGE); ?>">
        <label for="kop-people-q" class="screen-reader-text">Search people</label>
        <input type="search" id="kop-people-q" name="q" value="<?php echo esc_attr($q); ?>" placeholder="Name, other name or id">
        <button type="submit" class="button">Search</button>
        <span><?php echo esc_html(number_format($total) . ($total === 1 ? ' person' : ' people')); ?></span>
    </form>
    <table class="widefat striped">
        <thead><tr><th>Id</th><th>Name</th><th>Also written</th><th>Records</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $p) : ?>
            <tr>
                <td>#<?php echo (int) $p['id']; ?></td>
                <td><a href="<?php echo esc_url(kop_people_admin_url(array('person' => (int) $p['id']))); ?>"><?php echo esc_html($p['name']); ?></a></td>
                <td><?php echo esc_html(implode(', ', kop_people_alias_list($p['aliases']))); ?></td>
                <td><?php echo (int) $p['records'] ?: '<span class="kop-people__muted">none</span>'; ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php
    if ($total > $per) {
        echo '<p class="kop-people__bar">';
        for ($i = 1; $i <= (int) ceil($total / $per); $i++) {
            echo $i === $paged ? '<strong>' . $i . '</strong> ' : '<a href="' . esc_url(kop_people_admin_url(array('q' => $q, 'paged' => $i))) . '">' . $i . '</a> ';
        }
        echo '</p>';
    }
}

function kop_people_admin_person($id) {
    $state = kop_people_load();
    $resolved = kop_people_resolve($state, $id);
    if (!isset($state['rows'][$id])) {
        echo '<h1>People</h1><p>No person #' . (int) $id . '. <a href="' . esc_url(kop_people_admin_url()) . '">All people</a></p>';
        return;
    }
    if ($resolved !== $id) {
        echo '<h1>Person #' . (int) $id . '</h1><p>Joined into <a href="' . esc_url(kop_people_admin_url(array('person' => $resolved))) . '">person #' . (int) $resolved . '</a>.</p>';
        return;
    }
    $p = $state['rows'][$id];
    $roles = kop_people_roles_of($id);
    $labels = array('administrator' => 'Administrator', 'notableStaff' => 'Staff', 'founders' => 'Founder', 'keyExecutives' => 'Executive', 'ceo' => 'CEO');
    // Others with the same last name: the likely "same person" picks.
    $parts = explode(' ', $p['name_key']);
    $last = end($parts);
    $similar = array();
    foreach ($state['rows'] as $o) {
        if ($o['id'] === $id || $o['merged_into']) continue;
        $op = explode(' ', $o['name_key']);
        if (end($op) === $last) $similar[] = $o;
    }
    ?>
    <p><a href="<?php echo esc_url(kop_people_admin_url()); ?>">All people</a></p>
    <h1><?php echo esc_html($p['name']); ?> <span class="kop-people__muted">person #<?php echo (int) $id; ?></span></h1>

    <h2>Where they are named</h2>
    <?php if (!$roles) : ?>
        <p class="kop-people__muted">No record names this person now (the entry was renamed or removed).</p>
    <?php else : ?>
    <table class="widefat striped">
        <thead><tr><th>Record</th><th>List</th><th>Written as</th><th>Role</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($roles as $r) : $rec = kop_people_admin_record($r['record_kind'], $r['record_id']); ?>
            <tr>
                <td><?php echo $rec['url'] !== '' ? '<a href="' . esc_url($rec['url']) . '">' . esc_html($rec['name']) . '</a>' : esc_html($rec['name']); ?>
                    <span class="kop-people__muted"><?php echo esc_html(($r['record_kind'] === 'operator' ? 'company #' : 'facility #') . $r['record_id']); ?></span></td>
                <td><?php echo esc_html($labels[$r['list']] ?? $r['list']); ?></td>
                <td><?php echo esc_html($r['name']); ?></td>
                <td><?php echo esc_html($r['role']); ?></td>
                <td><?php if ($r['record_kind'] === 'facility' && count($roles) > 1) : ?>
                    <form method="post">
                        <?php kop_people_admin_hidden('separate', $id); ?>
                        <input type="hidden" name="facility" value="<?php echo (int) $r['record_id']; ?>">
                        <input type="hidden" name="list" value="<?php echo esc_attr($r['list']); ?>">
                        <input type="hidden" name="position" value="<?php echo (int) $r['position']; ?>">
                        <button type="submit" class="button" title="This entry is someone else with the same name">Separate</button>
                    </form>
                <?php endif; ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>

    <h2>Same person as</h2>
    <p>If this person already has another id (a nickname, a maiden name, a misspelling), join this id into it. This id then forwards there.</p>
    <?php foreach ($similar as $o) : ?>
        <form method="post" class="kop-people__bar">
            <?php kop_people_admin_hidden('merge', $id); ?>
            <input type="hidden" name="into" value="<?php echo (int) $o['id']; ?>">
            <button type="submit" class="button">Same person as <?php echo esc_html($o['name']); ?> (#<?php echo (int) $o['id']; ?>)</button>
        </form>
    <?php endforeach; ?>
    <form method="post" class="kop-people__bar">
        <?php kop_people_admin_hidden('merge', $id); ?>
        <label for="kop-people-into">Other person's name or id</label>
        <input type="text" id="kop-people-into" name="into" placeholder="Name or #id">
        <button type="submit" class="button">Same person</button>
    </form>

    <h2>Details</h2>
    <form method="post" class="kop-people__form">
        <?php kop_people_admin_hidden('save', $id); ?>
        <p><label for="kop-people-name">Name</label><br><input type="text" id="kop-people-name" name="name" value="<?php echo esc_attr($p['name']); ?>" class="regular-text"></p>
        <p><label for="kop-people-aliases">Also written as (one per line)</label><br><textarea id="kop-people-aliases" name="aliases" rows="3" class="large-text"><?php echo esc_textarea($p['aliases']); ?></textarea></p>
        <p><label for="kop-people-notes">Notes (admins only)</label><br><textarea id="kop-people-notes" name="notes" rows="3" class="large-text"><?php echo esc_textarea($p['notes']); ?></textarea></p>
        <p><button type="submit" class="button button-primary">Save</button></p>
    </form>
    <?php
}

function kop_people_admin_styles() {
    ?>
    <style>
        .kop-people__intro { max-width: 60rem; }
        .kop-people__bar { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; margin: 8px 0; }
        .kop-people__muted { color: var(--kop-text-muted, #4A5568); font-weight: normal; font-size: 0.9em; }
        .kop-people table form { margin: 0; }
    </style>
    <?php
}
