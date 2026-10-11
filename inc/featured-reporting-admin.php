<?php
/**
 * KOP Tools > Essential Reporting: pick news articles as essential reporting
 * (inc/featured-reporting.php) from one screen.
 *
 *   Find an article   approved articles by headline, outlet or byline;
 *                     Feature with an optional note
 *   Picked            every pick with its note: save a new note, take it off
 *   Recent changes    every pick, note change and removal, each with Undo
 *
 * The same picks are made with the pencil on any article's headline. Only
 * plain text is posted (no HTML), so the host firewall lets it through.
 */

if (!defined('ABSPATH')) {
    exit;
}

define('KOP_FEAT_ADMIN_PAGE', 'kop-essential-reporting');

add_action('admin_menu', function () {
    if (!function_exists('kop_tools_parent_slug')) {
        return;
    }
    add_submenu_page(kop_tools_parent_slug(), 'Essential Reporting', 'Essential Reporting', 'manage_options',
        KOP_FEAT_ADMIN_PAGE, 'kop_render_feat_admin');
}, 21);

function kop_feat_admin_url($args = array()) {
    return add_query_arg($args, admin_url('admin.php?page=' . KOP_FEAT_ADMIN_PAGE));
}

/** [notice, error] after a posted action. */
function kop_feat_admin_handle(PDO $pdo) {
    check_admin_referer('kop_feat');
    $do = sanitize_key(wp_unslash($_POST['kop_feat_do'] ?? ''));
    $id = (int) ($_POST['kop_feat_id'] ?? 0);
    $note = (string) wp_unslash($_POST['kop_feat_note'] ?? '');
    $who = wp_get_current_user()->display_name;
    try {
        switch ($do) {
            case 'feature':
            case 'note':
                $e = kop_feat_set($pdo, $id, $note, $who);
                if (!$e) return array('No change.', '');
                return array($e['action'] === 'feature' ? 'Featured "' . $e['title'] . '". It is on the Essential Reporting page now.' : 'Note saved for "' . $e['title'] . '".', '');
            case 'remove':
                $e = kop_feat_remove($pdo, $id, $who);
                return array($e ? 'Took "' . $e['title'] . '" off the list. Undo is under Recent changes.' : 'It was not on the list.', '');
            case 'undo':
                $e = kop_feat_undo($pdo, (string) wp_unslash($_POST['kop_feat_entry'] ?? ''), $who);
                return array('Undone: "' . $e['title'] . '" is back the way it was.', '');
        }
    } catch (Throwable $e) {
        return array('', $e->getMessage());
    }
    return array('', 'Unknown action.');
}

/** Approved articles whose headline, outlet or byline holds $q, newest first. */
function kop_feat_admin_search(PDO $pdo, $q) {
    $q = trim((string) $q);
    if ($q === '') return array();
    $like = '%' . str_replace(array('\\', '%', '_'), array('\\\\', '\\%', '\\_'), $q) . '%';
    $where = "status IN ('approved','published') AND (article_title LIKE ? OR alternate_title LIKE ? OR publication_name LIKE ? OR author LIKE ?)";
    $args = array($like, $like, $like, $like);
    if (ctype_digit($q)) {
        $where = "status IN ('approved','published') AND id = ?";
        $args = array((int) $q);
    }
    $st = $pdo->prepare("SELECT id, article_title, alternate_title, author, publication_name, publication_date, article_url, summary
                           FROM news_submissions WHERE $where ORDER BY publication_date DESC, id DESC LIMIT 30");
    $st->execute($args);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

function kop_feat_admin_form_open($do, $id, $extra = '') {
    echo '<form method="post" action="' . esc_url(kop_feat_admin_url(isset($_GET['q']) ? array('q' => wp_unslash($_GET['q'])) : array())) . '" class="kop-feat-form"' . $extra . '>';
    wp_nonce_field('kop_feat');
    echo '<input type="hidden" name="kop_feat_do" value="' . esc_attr($do) . '">';
    echo '<input type="hidden" name="kop_feat_id" value="' . (int) $id . '">';
}

function kop_render_feat_admin() {
    if (!current_user_can('manage_options')) {
        wp_die('Not authorized', 'Access Denied', array('response' => 403));
    }
    $pdo = kop_feat_pdo();
    echo '<div class="wrap kop-feat-admin">';
    kop_feat_admin_styles();
    echo '<h1>Essential Reporting</h1>';
    if (!$pdo) {
        echo '<div class="notice notice-error"><p>The records database is not reachable.</p></div></div>';
        return;
    }
    kop_feat_install($pdo);
    $notice = $error = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['kop_feat_do'])) {
        list($notice, $error) = kop_feat_admin_handle($pdo);
    }
    if ($notice !== '') echo '<div class="notice notice-success is-dismissible"><p>' . esc_html($notice) . '</p></div>';
    if ($error !== '') echo '<div class="notice notice-error"><p><strong>Not done.</strong> ' . esc_html($error) . '</p></div>';

    echo '<p class="kop-feat-muted">Articles picked here are listed on the <a href="' . esc_url(kop_feat_url()) . '" target="_blank" rel="noopener">Essential Reporting page</a>, '
        . 'the newest three at the top of the news feed and on the home page, and flagged with your note wherever the article is listed (program and company pages list them first, and the app gets the flag). '
        . 'The note is optional: a sentence or two on what the reporting uncovered or what changed after it ran. The byline is the article\'s author as printed. '
        . 'You can also pick an article with the pencil on its headline anywhere on the site.</p>';

    // ---- Find an article ----------------------------------------------------
    $q = isset($_GET['q']) ? trim((string) wp_unslash($_GET['q'])) : '';
    echo '<h2>Find an article</h2>';
    echo '<form method="get" class="kop-feat-search"><input type="hidden" name="page" value="' . esc_attr(KOP_FEAT_ADMIN_PAGE) . '">'
        . '<label class="screen-reader-text" for="kop-feat-q">Headline, outlet or reporter</label>'
        . '<input type="search" id="kop-feat-q" name="q" value="' . esc_attr($q) . '" placeholder="Headline, outlet or reporter" class="regular-text"> '
        . '<button type="submit" class="button">Search</button></form>';
    $map = kop_feat_map();
    if ($q !== '') {
        $hits = kop_feat_admin_search($pdo, $q);
        if (!$hits) {
            echo '<p class="kop-feat-muted">No approved article matches "' . esc_html($q) . '".</p>';
        } else {
            echo '<p class="kop-feat-muted">' . count($hits) . (count($hits) === 30 ? '+' : '') . ' approved articles, newest first.</p><div class="kop-feat-rows">';
            foreach ($hits as $r) {
                $picked = isset($map[(int) $r['id']]);
                kop_feat_admin_row($r, $picked ? $map[(int) $r['id']] : null);
            }
            echo '</div>';
        }
    }

    // ---- Picked -------------------------------------------------------------
    $items = kop_feat_items('picked');
    echo '<h2>Picked (' . count($items) . ')</h2>';
    if (!$items) {
        echo '<p class="kop-feat-muted">Nothing yet. Search above, or use the pencil on an article\'s headline.</p>';
    } else {
        echo '<div class="kop-feat-rows">';
        foreach ($items as $it) {
            kop_feat_admin_row(array(
                'id' => $it['id'], 'article_title' => $it['title'], 'alternate_title' => '', 'author' => $it['byline'],
                'publication_name' => $it['outlet'], 'publication_date' => $it['date'], 'article_url' => $it['url'], 'summary' => $it['summary'],
            ), $map[$it['id']]);
        }
        echo '</div>';
    }

    // ---- Recent changes -----------------------------------------------------
    $log = kop_feat_log();
    echo '<h2>Recent changes</h2>';
    if (!$log) {
        echo '<p class="kop-feat-muted">None yet.</p>';
    } else {
        $words = array('feature' => 'Featured', 'note' => 'Changed the note of', 'remove' => 'Took off');
        echo '<table class="widefat striped kop-feat-log"><thead><tr><th>When</th><th>Who</th><th>What</th><th></th></tr></thead><tbody>';
        foreach (array_slice($log, 0, 40) as $e) {
            echo '<tr><td>' . esc_html(get_date_from_gmt($e['at'], 'M j, Y g:i a')) . '</td><td>' . esc_html($e['who']) . '</td><td>'
                . esc_html(($words[$e['action']] ?? $e['action']) . ' "' . $e['title'] . '"') . '</td><td>';
            if (!empty($e['undone'])) {
                echo '<span class="kop-feat-muted">Undone' . (!empty($e['undone_by']) ? ' by ' . esc_html($e['undone_by']) : '') . '</span>';
            } else {
                kop_feat_admin_form_open('undo', $e['news_id']);
                echo '<input type="hidden" name="kop_feat_entry" value="' . esc_attr($e['id']) . '"><button type="submit" class="button button-small">Undo</button></form>';
            }
            echo '</td></tr>';
        }
        echo '</tbody></table>';
    }
    echo '</div>';
}

/** One article: headline, outlet, date, byline, then the note box and its buttons. */
function kop_feat_admin_row(array $r, $pick) {
    $title = trim((string) ($r['alternate_title'] ?: $r['article_title']));
    $byline = kop_feat_byline($r['author'] ?? '', $r['publication_name'] ?? '');
    $meta = array_filter(array(trim((string) $r['publication_name']), kop_feat_date_label($r['publication_date']), $byline !== '' ? 'By ' . $byline : ''), 'strlen');
    echo '<div class="kop-feat-row' . ($pick ? ' is-picked' : '') . '">';
    echo '<p class="kop-feat-row__title">';
    echo $r['article_url'] ? '<a href="' . esc_url($r['article_url']) . '" target="_blank" rel="noopener">' . esc_html($title) . '</a>' : esc_html($title);
    if ($pick) echo ' <span class="kop-feat-pill">Picked</span>';
    echo '</p><p class="kop-feat-muted">' . esc_html(implode(' | ', $meta)) . '</p>';
    if (!$pick && trim((string) $r['summary']) !== '') {
        echo '<p class="kop-feat-row__summary">' . esc_html(wp_trim_words((string) $r['summary'], 40)) . '</p>';
    }
    kop_feat_admin_form_open($pick ? 'note' : 'feature', $r['id']);
    echo '<label class="screen-reader-text" for="kop-feat-note-' . (int) $r['id'] . '">Why it matters</label>';
    echo '<textarea id="kop-feat-note-' . (int) $r['id'] . '" name="kop_feat_note" rows="2" placeholder="Why it matters (optional): what it uncovered, or what changed after it ran">' . esc_textarea($pick ? $pick['note'] : '') . '</textarea>';
    echo '<p class="kop-feat-row__buttons"><button type="submit" class="button ' . ($pick ? '' : 'button-primary') . '">' . ($pick ? 'Save note' : 'Feature') . '</button>';
    echo '</p></form>';
    if ($pick) {
        kop_feat_admin_form_open('remove', $r['id'], ' style="display:inline"');
        echo '<button type="submit" class="button-link kop-feat-remove">Take off the list</button></form>';
    }
    echo '</div>';
}

function kop_feat_admin_styles() {
    echo '<style>
        .kop-feat-admin .kop-feat-muted { color: #4A5568; max-width: 75em; }
        .kop-feat-admin h2 { margin-top: 1.6em; }
        .kop-feat-admin .kop-feat-search { margin: 0.5em 0 1em; }
        .kop-feat-admin .kop-feat-rows { display: grid; gap: 10px; max-width: 900px; }
        .kop-feat-admin .kop-feat-row { background: #fff; border: 1px solid #c3c4c7; border-left: 4px solid #c3c4c7; padding: 10px 14px; }
        .kop-feat-admin .kop-feat-row.is-picked { border-left-color: #EF9034; }
        .kop-feat-admin .kop-feat-row p { margin: 0.2em 0; }
        .kop-feat-admin .kop-feat-row__title { font-size: 14px; font-weight: 600; }
        .kop-feat-admin .kop-feat-row__summary { color: #1d2327; }
        .kop-feat-admin .kop-feat-row textarea { width: 100%; margin-top: 6px; }
        .kop-feat-admin .kop-feat-row__buttons { display: inline-block; margin-right: 12px !important; }
        .kop-feat-admin .kop-feat-pill { display: inline-block; padding: 0 8px; border: 1px solid #EF9034; border-radius: 999px; color: #A3570D; font-size: 12px; font-weight: 600; }
        .kop-feat-admin button.kop-feat-remove { color: #b32d2e; }
        .kop-feat-admin .kop-feat-log { max-width: 1100px; }
    </style>';
}
