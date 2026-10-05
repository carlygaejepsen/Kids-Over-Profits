<?php
/**
 * KOP Data Tools > Indigenous Schools: the records inc/indigenous-schools.php
 * keeps, managed from one screen.
 *
 *   Waiting for review  schools the news scan found; Approve lists them on
 *                       the page, Delete drops them
 *   Schools             every listed school; Edit opens its form, where its
 *                       articles are filed by searching their titles
 *   News about the schools in general
 *                       articles filed under no one school
 *   Move from the facility data
 *                       a school that was filed as a TTI facility, found by
 *                       name and moved over with its articles when the admin
 *                       presses "Move here" (and confirms)
 *   Names the news scan set aside
 *                       names from articles that look like one of these
 *                       schools; "Add as a school" makes the record
 *
 * Only plain text is posted (no HTML), so the host firewall lets it through.
 */

if (!defined('ABSPATH')) {
    exit;
}

define('KOP_ISCHOOLS_ADMIN_PAGE', 'kop-indigenous-schools');

function kop_register_ischools_menu() {
    if (!function_exists('kop_tools_parent_slug')) {
        return;
    }
    add_submenu_page(
        kop_tools_parent_slug(),
        'Indigenous Schools',
        'Indigenous Schools',
        'manage_options',
        KOP_ISCHOOLS_ADMIN_PAGE,
        'kop_render_ischools_admin'
    );
}
add_action('admin_menu', 'kop_register_ischools_menu', 21);

function kop_ischools_admin_url($args = array()) {
    return add_query_arg($args, admin_url('admin.php?page=' . KOP_ISCHOOLS_ADMIN_PAGE));
}

function kop_ischools_reviewer() {
    $u = wp_get_current_user();
    return $u && $u->exists() ? $u->display_name : '';
}

/** Names the news scan set aside that look like an Indigenous school. */
function kop_ischools_set_aside_names(PDO $pdo) {
    try {
        $rows = $pdo->query("SELECT c.id, c.mention, c.news_id, c.decision, n.article_title, n.article_url
                             FROM news_facility_candidates c LEFT JOIN news_submissions n ON n.id = c.news_id
                             WHERE c.decision IN ('not_facility', 'needs_place', 'unquoted', 'possible_duplicate', 'other_era')
                             ORDER BY c.id DESC")->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        return array();
    }
    $out = array();
    foreach ($rows as $r) {
        $m = $r['mention'];
        if (preg_match('/\b(indian|indigenous|native american|first nations?|tribal|alaska native|inuit|m[eé]tis)\b/i', $m)
            && preg_match('/\b(school|mission|academy|institute|home)\b/i', $m)
            && !preg_match('/\(|e\.g\.|schools\b/i', $m)
            && !kop_ischools_find_by_name($pdo, $m)) {
            $out[] = $r;
        }
    }
    return $out;
}

/* ---- Actions --------------------------------------------------------- */

function kop_ischools_admin_fields_from_post() {
    $f = array();
    foreach (array('name', 'other_names', 'country', 'region', 'city', 'nations', 'run_by', 'opened', 'closed', 'status', 'notes', 'links') as $k) {
        $f[$k] = isset($_POST['s'][$k]) ? (string) wp_unslash($_POST['s'][$k]) : '';
    }
    return $f;
}

/** Handle a posted action. Returns array(notice, error, redirect-view args). */
function kop_ischools_admin_handle(PDO $pdo) {
    check_admin_referer('kop_ischools');
    $do = sanitize_key(wp_unslash($_POST['kop_is_do'] ?? ''));
    $id = (int) ($_POST['kop_is_id'] ?? 0);
    $by = kop_ischools_reviewer();
    try {
        switch ($do) {
            case 'save':
                $f = kop_ischools_admin_fields_from_post();
                if (!empty($_POST['kop_is_approve'])) {
                    $f['review'] = 'approved';
                }
                $sid = kop_ischools_save($pdo, $f, $id, $by);
                kop_ischools_admin_purge();
                return array($id ? 'Saved. The page shows the change now.' : 'School added.', '', array('edit' => $sid));
            case 'approve':
                $pdo->prepare("UPDATE indigenous_schools SET review = 'approved', updated_at = ? WHERE id = ?")->execute(array(kop_ischools_now(), $id));
                kop_ischools_admin_purge();
                return array('Approved: it is on the page now.', '', array());
            case 'delete':
                $s = kop_ischools_get($pdo, $id);
                kop_ischools_delete($pdo, $id);
                kop_ischools_admin_purge();
                return array('Deleted' . ($s ? ' "' . $s['name'] . '"' : '') . '. Its articles are still in the news database.', '', array());
            case 'link':
                $nid = (int) ($_POST['kop_is_news'] ?? 0);
                if (!$nid) {
                    return array('', 'Pick an article from the search results first.', $id ? array('edit' => $id) : array());
                }
                kop_ischools_link_news($pdo, $id, $nid, $by);
                kop_ischools_admin_purge();
                return array('Article filed.', '', $id ? array('edit' => $id) : array());
            case 'unlink':
                kop_ischools_unlink_news($pdo, $id, (int) ($_POST['kop_is_news'] ?? 0));
                kop_ischools_admin_purge();
                return array('Article taken off.', '', $id ? array('edit' => $id) : array());
            case 'move':
                $fid = (int) ($_POST['kop_is_facility'] ?? 0);
                if (!$fid) {
                    return array('', 'Search for the facility record first.', array());
                }
                $sid = kop_ischools_move_facility($pdo, $fid, $by);
                return array('Moved out of the facility data. Check the details below and save.', '', array('edit' => $sid));
            case 'add_name':
                $sid = kop_ischools_add_from_candidate($pdo, (int) ($_POST['kop_is_candidate'] ?? 0), $by);
                if (!$sid) {
                    return array('', 'That name is no longer there.', array());
                }
                return array('Added, with its article. Check the details below, then save and approve.', '', array('edit' => $sid));
        }
    } catch (Throwable $e) {
        return array('', $e->getMessage(), $id ? array('edit' => $id) : array());
    }
    return array('', 'Unknown action.', array());
}

/**
 * "Add as a school" for a name the news scan set aside: a school record made
 * from what the scan read, with its article, and the name filed as an
 * Indigenous school. Returns the school id, or 0 when the name is gone.
 */
function kop_ischools_add_from_candidate(PDO $pdo, $candidate_id, $by) {
    $c = $pdo->prepare('SELECT c.*, n.article_title, n.publication_name, n.publication_date FROM news_facility_candidates c LEFT JOIN news_submissions n ON n.id = c.news_id WHERE c.id = ?');
    $c->execute(array((int) $candidate_id));
    $row = $c->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return 0;
    }
    $detail = json_decode((string) $row['detail'], true) ?: array();
    $entry = array_merge(array('name' => $row['mention']), $detail['entry'] ?? array());
    $entry['name'] = $row['mention'];
    $sid = kop_ischools_from_news($pdo, $entry, array(
        'id' => (int) $row['news_id'], 'article_title' => $row['article_title'],
        'publication_name' => $row['publication_name'], 'publication_date' => $row['publication_date'],
    ));
    $pdo->prepare("UPDATE news_facility_candidates SET decision = 'indigenous_school', reviewed_by = ?, updated_at = ? WHERE id = ?")
        ->execute(array($by, kop_ischools_now(), (int) $row['id']));
    return (int) $sid;
}

function kop_ischools_admin_purge() {
    if (function_exists('kop_page_text_purge')) {
        kop_page_text_purge(KOP_ISCHOOLS_PAGE);
    }
}

/** Article search for the "File an article" boxes. */
add_action('wp_ajax_kop_ischools_news_search', function () {
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Not authorized', 403);
    }
    check_ajax_referer('kop_ischools');
    $pdo = kop_ischools_pdo();
    $q = trim((string) wp_unslash($_POST['q'] ?? ''));
    if (!$pdo || mb_strlen($q) < 3) {
        wp_send_json_success(array());
    }
    $like = '%' . str_replace(array('\\', '%', '_'), array('\\\\', '\\%', '\\_'), $q) . '%';
    $stmt = $pdo->prepare("SELECT id, article_title, publication_name, publication_date, status FROM news_submissions
                           WHERE status NOT IN ('deleted') AND (article_title LIKE ? OR article_url LIKE ? OR publication_name LIKE ?)
                           ORDER BY publication_date DESC, id DESC LIMIT 15");
    $stmt->execute(array($like, $like, $like));
    wp_send_json_success($stmt->fetchAll(PDO::FETCH_ASSOC));
});

/* ---- Screen ------------------------------------------------------------ */

function kop_render_ischools_admin() {
    if (!current_user_can('manage_options')) {
        wp_die('Not authorized', 'Access Denied', array('response' => 403));
    }
    $pdo = kop_ischools_pdo();
    echo '<div class="wrap kop-is">';
    kop_ischools_admin_styles();
    echo '<h1>Indigenous Residential Schools</h1>';
    if (!$pdo) {
        echo '<div class="notice notice-error"><p>The records database is not reachable.</p></div></div>';
        return;
    }
    kop_ischools_install($pdo);

    $notice = $error = '';
    $view = array();
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['kop_is_do'])) {
        list($notice, $error, $view) = kop_ischools_admin_handle($pdo);
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
    $log = get_option('kop_ischools_migration_log');
    if (is_array($log) && !empty($log['lines']) && time() - (int) $log['time'] < 14 * DAY_IN_SECONDS) {
        echo '<div class="notice notice-info"><p><strong>First move (' . esc_html(wp_date('M j, g:i a', (int) $log['time'])) . '):</strong></p><ul style="list-style:disc;margin-left:20px">';
        foreach ($log['lines'] as $l) {
            echo '<li>' . esc_html($l) . '</li>';
        }
        echo '</ul></div>';
    }

    $page = get_page_by_path(KOP_ISCHOOLS_PAGE);
    echo '<p class="kop-is-muted">These records are listed on the ';
    if ($page) {
        $url = $page->post_status === 'publish' ? get_permalink($page) : get_preview_post_link($page);
        echo '<a href="' . esc_url($url) . '" target="_blank" rel="noopener">Indian Boarding Schools and Residential Schools page</a>'
            . ($page->post_status !== 'publish' ? ' (a draft: only admins can see it)' : '');
    } else {
        echo 'Indian Boarding Schools and Residential Schools page';
    }
    echo '. They are not troubled teen facilities and never appear with them. The page\'s own words are edited at <a href="'
        . esc_url(admin_url('admin.php?page=kop-page-text')) . '">Page Text</a>.</p>';

    if (!empty($view['edit']) || isset($_GET['add'])) {
        $school = !empty($view['edit']) ? kop_ischools_get($pdo, (int) $view['edit']) : null;
        kop_ischools_admin_form($pdo, $school);
    } else {
        kop_ischools_admin_lists($pdo);
    }
    kop_ischools_admin_script();
    echo '</div>';
}

function kop_ischools_admin_hidden($do, $id = 0) {
    wp_nonce_field('kop_ischools');
    echo '<input type="hidden" name="kop_is_do" value="' . esc_attr($do) . '"><input type="hidden" name="kop_is_id" value="' . (int) $id . '">';
}

function kop_ischools_admin_place(array $s) {
    $line = kop_ischools_meta_line($s);
    $country = kop_ischools_country_label($s['country']);
    return trim($line . ($country !== 'Other places' ? ' (' . $country . ')' : ''));
}

function kop_ischools_admin_lists(PDO $pdo) {
    $news = kop_ischools_news($pdo, true);

    $pending = kop_ischools_all($pdo, 'pending');
    echo '<div class="kop-is-box"><h2>Waiting for review' . ($pending ? ' (' . count($pending) . ')' : '') . '</h2>';
    if (!$pending) {
        echo '<p class="kop-is-muted">Nothing is waiting. When the hourly news scan finds one of these schools in an article, it is added here with the article.</p>';
    } else {
        echo '<p class="kop-is-muted">The news scan found these in articles. Check each one, then approve it to list it on the page.</p><table class="widefat striped"><tbody>';
        foreach ($pending as $s) {
            echo '<tr><td><strong>' . esc_html($s['name']) . '</strong><br><span class="kop-is-muted">' . esc_html(kop_ischools_admin_place($s)) . '</span>';
            if ($s['source'] !== '') {
                echo '<br><span class="kop-is-muted">' . esc_html($s['source']) . '</span>';
            }
            foreach ($news[(int) $s['id']] ?? array() as $a) {
                echo '<br><a href="' . esc_url($a['article_url']) . '" target="_blank" rel="noopener">' . esc_html($a['article_title']) . '</a>';
            }
            echo '</td><td class="kop-is-actions">';
            echo '<form method="post">';
            kop_ischools_admin_hidden('approve', $s['id']);
            echo '<button class="button button-primary">Approve</button></form> ';
            echo '<a class="button" href="' . esc_url(kop_ischools_admin_url(array('edit' => $s['id']))) . '">Edit</a> ';
            echo '<form method="post" onsubmit="return confirm(\'Delete this school? Its articles stay in the news database.\')">';
            kop_ischools_admin_hidden('delete', $s['id']);
            echo '<button class="button-link kop-is-danger">Delete</button></form></td></tr>';
        }
        echo '</tbody></table>';
    }
    echo '</div>';

    $schools = kop_ischools_all($pdo, 'approved');
    echo '<div class="kop-is-box"><h2>Schools (' . count($schools) . ')</h2>';
    echo '<p><a class="button button-primary" href="' . esc_url(kop_ischools_admin_url(array('add' => 1))) . '">Add a school</a></p>';
    if ($schools) {
        echo '<table class="widefat striped"><thead><tr><th>School</th><th>Place and years</th><th>Articles</th><th></th></tr></thead><tbody>';
        foreach ($schools as $s) {
            echo '<tr><td><strong>' . esc_html($s['name']) . '</strong></td><td>' . esc_html(kop_ischools_admin_place($s)) . '</td><td>'
                . count($news[(int) $s['id']] ?? array()) . '</td><td><a class="button" href="' . esc_url(kop_ischools_admin_url(array('edit' => $s['id']))) . '">Edit</a></td></tr>';
        }
        echo '</tbody></table>';
    }
    echo '</div>';

    echo '<div class="kop-is-box"><h2>News about the schools in general</h2><p class="kop-is-muted">Articles about Indian boarding schools or residential schools that are not about one school.</p>';
    kop_ischools_admin_article_list($news[0] ?? array(), 0);
    kop_ischools_admin_article_search(0);
    echo '</div>';

    echo '<div class="kop-is-box"><h2>Move a school out of the facility data</h2>'
        . '<p class="kop-is-muted">If one of these schools was saved as a troubled teen facility, find it here and move it. Its articles come with it, and it stops appearing as a facility.</p>'
        . '<form method="post" onsubmit="return confirm(\'Move this record out of the facility data? It will no longer be a facility record.\')">';
    kop_ischools_admin_hidden('move');
    echo 'Facility record: ' . kop_facility_finder_field('kop_is_facility') . ' <button class="button">Move here</button></form></div>';

    $names = kop_ischools_set_aside_names($pdo);
    if ($names) {
        echo '<div class="kop-is-box"><h2>Names the news scan set aside</h2><p class="kop-is-muted">These came up in articles but were not filed anywhere. If one is an Indigenous school, add it.</p><table class="widefat striped"><tbody>';
        foreach ($names as $r) {
            echo '<tr><td><strong>' . esc_html($r['mention']) . '</strong><br><a href="' . esc_url((string) $r['article_url']) . '" target="_blank" rel="noopener">'
                . esc_html($r['article_title'] ?: 'article #' . (int) $r['news_id']) . '</a></td><td class="kop-is-actions"><form method="post">';
            kop_ischools_admin_hidden('add_name');
            echo '<input type="hidden" name="kop_is_candidate" value="' . (int) $r['id'] . '"><button class="button">Add as a school</button></form></td></tr>';
        }
        echo '</tbody></table></div>';
    }
}

function kop_ischools_admin_article_list(array $articles, $school_id) {
    if (!$articles) {
        echo '<p class="kop-is-muted">No articles filed yet.</p>';
        return;
    }
    echo '<ul class="kop-is-articles">';
    foreach ($articles as $a) {
        echo '<li><a href="' . esc_url($a['article_url']) . '" target="_blank" rel="noopener">' . esc_html($a['article_title'] ?: 'Untitled') . '</a> '
            . '<span class="kop-is-muted">' . esc_html(trim($a['publication_name'] . ' ' . $a['publication_date']))
            . (!in_array($a['status'], array('approved', 'published'), true) ? ' - not shown: the article is ' . esc_html($a['status']) : '') . '</span> '
            . '<form method="post" class="kop-is-inline">';
        kop_ischools_admin_hidden('unlink', $school_id);
        echo '<input type="hidden" name="kop_is_news" value="' . (int) $a['id'] . '"><button class="button-link kop-is-danger">Take off</button></form></li>';
    }
    echo '</ul>';
}

function kop_ischools_admin_article_search($school_id) {
    echo '<form method="post" class="kop-is-search">';
    kop_ischools_admin_hidden('link', $school_id);
    echo '<label><strong>File an article:</strong> <input type="text" class="kop-is-q" placeholder="Type part of the title, outlet or web address" autocomplete="off"></label>'
        . '<input type="hidden" name="kop_is_news" class="kop-is-news" value="">'
        . '<ul class="kop-is-results"></ul>'
        . '<button class="button" disabled>File it</button></form>';
}

function kop_ischools_admin_form(PDO $pdo, $school) {
    $s = $school ?: array('id' => 0, 'name' => '', 'other_names' => '', 'country' => 'United States', 'region' => '', 'city' => '', 'nations' => '',
        'run_by' => '', 'opened' => '', 'closed' => '', 'status' => 'Closed', 'notes' => '', 'links' => '', 'review' => 'approved', 'source' => '');
    echo '<p><a href="' . esc_url(kop_ischools_admin_url()) . '">&larr; All schools</a></p>';
    echo '<div class="kop-is-box"><h2>' . ($school ? esc_html($s['name']) : 'Add a school') . '</h2>';
    if ($school && $s['review'] === 'pending') {
        echo '<p class="kop-is-pending">Waiting for review: it is not on the page yet. "Save and approve" lists it.</p>';
    }
    if (!empty($s['source'])) {
        echo '<p class="kop-is-muted">' . esc_html($s['source']) . '</p>';
    }
    echo '<form method="post"><table class="form-table kop-is-form"><tbody>';
    kop_ischools_admin_hidden('save', $s['id']);
    $text = function ($key, $label, $help = '', $width = '') use ($s) {
        echo '<tr><th><label for="kop-is-' . $key . '">' . esc_html($label) . '</label></th><td><input type="text" id="kop-is-' . $key . '" name="s[' . $key . ']" value="'
            . esc_attr((string) $s[$key]) . '"' . ($width ? ' style="width:' . $width . '"' : '') . '>' . ($help ? '<p class="description">' . esc_html($help) . '</p>' : '') . '</td></tr>';
    };
    $area = function ($key, $label, $help, $rows) use ($s) {
        echo '<tr><th><label for="kop-is-' . $key . '">' . esc_html($label) . '</label></th><td><textarea id="kop-is-' . $key . '" name="s[' . $key . ']" rows="' . (int) $rows . '">'
            . esc_textarea((string) $s[$key]) . '</textarea><p class="description">' . esc_html($help) . '</p></td></tr>';
    };
    $text('name', 'Name');
    $area('other_names', 'Other names', 'One per line: earlier names, other spellings.', 2);
    $text('country', 'Country', 'United States, Canada, or another country.', '240px');
    $text('region', 'State or province', 'For example PA, SD or British Columbia.', '240px');
    $text('city', 'Town or city', '', '240px');
    $text('opened', 'Opened', 'Year, if known.', '90px');
    $text('closed', 'Closed', 'Year, if known.', '90px');
    echo '<tr><th><label for="kop-is-status">Still open?</label></th><td><select id="kop-is-status" name="s[status]">';
    foreach (array('Closed' => 'Closed', 'Open' => 'Still open', 'Unknown' => 'Not known') as $v => $l) {
        echo '<option value="' . esc_attr($v) . '"' . selected($s['status'], $v, false) . '>' . esc_html($l) . '</option>';
    }
    echo '</select></td></tr>';
    $text('nations', 'Nations', 'The Nations whose children were sent there, as those Nations name themselves, if known.');
    $text('run_by', 'Run by', 'For example the federal government, or a church or order.');
    $area('notes', 'Notes', 'Plain text. A blank line starts a new paragraph; **bold**, *italic* and [link text](https://...) work.', 5);
    $area('links', 'Other links', 'One per line, as: What it is | https://address. For example a Nation\'s own page about the school, or a document in the media library.', 3);
    echo '</tbody></table><p><button class="button button-primary">Save</button> ';
    if ($school && $s['review'] === 'pending') {
        echo '<button class="button button-primary" name="kop_is_approve" value="1">Save and approve</button> ';
    }
    echo '</p></form>';
    if ($school) {
        echo '<form method="post" onsubmit="return confirm(\'Delete this school? Its articles stay in the news database.\')">';
        kop_ischools_admin_hidden('delete', $s['id']);
        echo '<button class="button-link kop-is-danger">Delete this school</button></form>';
    }
    echo '</div>';

    if ($school) {
        $news = kop_ischools_news($pdo, true);
        echo '<div class="kop-is-box"><h2>Articles about this school</h2>';
        kop_ischools_admin_article_list($news[(int) $s['id']] ?? array(), (int) $s['id']);
        kop_ischools_admin_article_search((int) $s['id']);
        echo '</div>';
    }
}

function kop_ischools_admin_styles() {
    ?>
    <style>
        .kop-is .kop-is-box { background: #fff; border: 1px solid #c3c4c7; padding: 12px 16px; margin: 16px 0; max-width: 1100px; }
        .kop-is .kop-is-box h2 { margin-top: 4px; }
        .kop-is .kop-is-muted { color: #646970; }
        .kop-is .kop-is-danger { color: #b32d2e; }
        .kop-is .kop-is-pending { background: #fcf9e8; border-left: 4px solid #dba617; padding: 6px 10px; }
        .kop-is .kop-is-actions { white-space: nowrap; width: 1%; }
        .kop-is .kop-is-actions form, .kop-is .kop-is-inline { display: inline; }
        .kop-is .kop-is-form input[type=text], .kop-is .kop-is-form textarea { width: 100%; max-width: 640px; }
        .kop-is .kop-is-articles li { margin-bottom: 6px; }
        .kop-is .kop-is-search { margin-top: 10px; position: relative; max-width: 640px; }
        .kop-is .kop-is-q { width: 420px; max-width: 100%; }
        .kop-is .kop-is-results { margin: 4px 0; padding: 0; list-style: none; border: 1px solid #c3c4c7; max-height: 260px; overflow-y: auto; display: none; background: #fff; }
        .kop-is .kop-is-results li { margin: 0; padding: 6px 8px; cursor: pointer; border-bottom: 1px solid #f0f0f1; }
        .kop-is .kop-is-results li:hover, .kop-is .kop-is-results li.on { background: #e8f4f6; }
    </style>
    <?php
}

function kop_ischools_admin_script() {
    ?>
    <script>
    (function () {
        var ajax = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;
        var nonce = <?php echo wp_json_encode(wp_create_nonce('kop_ischools')); ?>;
        document.querySelectorAll('.kop-is-search').forEach(function (form) {
            var q = form.querySelector('.kop-is-q'), list = form.querySelector('.kop-is-results');
            var hidden = form.querySelector('.kop-is-news'), button = form.querySelector('button');
            var timer = null;
            q.addEventListener('input', function () {
                hidden.value = '';
                button.disabled = true;
                clearTimeout(timer);
                if (q.value.trim().length < 3) { list.style.display = 'none'; return; }
                timer = setTimeout(function () {
                    var body = new FormData();
                    body.append('action', 'kop_ischools_news_search');
                    body.append('_ajax_nonce', nonce);
                    body.append('q', q.value.trim());
                    fetch(ajax, { method: 'POST', body: body, credentials: 'same-origin' })
                        .then(function (r) { return r.json(); })
                        .then(function (r) {
                            list.innerHTML = '';
                            var rows = (r && r.success) ? r.data : [];
                            if (!rows.length) {
                                var none = document.createElement('li');
                                none.textContent = 'No article matches. It has to be in the news database first.';
                                list.appendChild(none);
                            }
                            rows.forEach(function (a) {
                                var li = document.createElement('li');
                                li.textContent = (a.article_title || 'Untitled') + ' - ' + [a.publication_name, a.publication_date].filter(Boolean).join(', ');
                                li.addEventListener('click', function () {
                                    hidden.value = a.id;
                                    q.value = a.article_title || ('article ' + a.id);
                                    list.style.display = 'none';
                                    button.disabled = false;
                                });
                                list.appendChild(li);
                            });
                            list.style.display = 'block';
                        })
                        .catch(function () {});
                }, 300);
            });
        });
    })();
    </script>
    <?php
}
