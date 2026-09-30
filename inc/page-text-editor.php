<?php
/**
 * Page Text editor (KOP Data Tools > Page Text): edit the words of the
 * pages listed in kop_page_text_pages() (inc/page-text.php) from wp-admin.
 *
 * One screen per page: every section is a box with its heading, a plain text
 * area, a few formatting buttons and a live preview drawn with the page's own
 * stylesheet. Save puts the changes live at once. "Put back the original"
 * drops a section's edit. "Download the page file" gives the merged JSON to
 * commit to the repo; once that deploys, the edits match the repo copy and
 * clear themselves.
 *
 * Only plain text is posted (the format has no HTML), so the host firewall
 * that rejects HTML in form fields lets every save through.
 */

if (!defined('ABSPATH')) {
    exit;
}

define('KOP_PAGE_TEXT_EDITOR_PAGE', 'kop-page-text');

function kop_register_page_text_editor_menu() {
    if (!function_exists('kop_tools_parent_slug')) {
        return;
    }
    add_submenu_page(
        kop_tools_parent_slug(),
        'Page Text',
        'Page Text',
        'manage_options',
        KOP_PAGE_TEXT_EDITOR_PAGE,
        'kop_render_page_text_editor'
    );
}
add_action('admin_menu', 'kop_register_page_text_editor_menu', 21);

function kop_page_text_editor_url($args = array()) {
    return add_query_arg($args, admin_url('admin.php?page=' . KOP_PAGE_TEXT_EDITOR_PAGE));
}

/** The page slug the screen is on (the only page, or ?kop_page=). */
function kop_page_text_editor_slug() {
    $pages = kop_page_text_pages();
    $slug = isset($_REQUEST['kop_page']) ? sanitize_key(wp_unslash($_REQUEST['kop_page'])) : '';
    if (isset($pages[$slug])) {
        return $slug;
    }
    return count($pages) === 1 ? key($pages) : '';
}

/** Where to look at the page: its permalink, or the preview while a draft. */
function kop_page_text_view_link($slug) {
    $post = get_page_by_path($slug);
    if (!$post) {
        return array('url' => '', 'status' => '');
    }
    $url = $post->post_status === 'publish' ? get_permalink($post) : get_preview_post_link($post);
    return array('url' => $url ? $url : '', 'status' => $post->post_status, 'id' => $post->ID);
}

function kop_page_text_purge($slug) {
    $post = get_page_by_path($slug);
    if ($post) {
        clean_post_cache($post->ID);
        do_action('litespeed_purge_post', $post->ID);
    }
    do_action('litespeed_purge_url', home_url('/' . $slug . '/'));
}

/** Drop edits that now match the deployed JSON. Returns how many cleared. */
function kop_page_text_clear_matching($slug) {
    $edits = kop_page_text_edits();
    if (empty($edits[$slug])) {
        return 0;
    }
    $cleared = 0;
    foreach (kop_page_text_defaults($slug) as $d) {
        $e = isset($edits[$slug][$d['key']]) ? $edits[$slug][$d['key']] : null;
        if ($e && (string) $e['heading'] === $d['heading'] && kop_page_text_clean($e['body']) === $d['body']) {
            unset($edits[$slug][$d['key']]);
            $cleared++;
        }
    }
    if ($cleared) {
        if (!$edits[$slug]) {
            unset($edits[$slug]);
        }
        kop_page_text_save_edits($edits);
    }
    return $cleared;
}

/** Save or reset from the form. Returns a notice. */
function kop_page_text_editor_handle_post($slug) {
    check_admin_referer('kop_page_text_' . $slug);
    $edits = kop_page_text_edits();
    $mine  = isset($edits[$slug]) ? $edits[$slug] : array();
    $user  = wp_get_current_user();
    $who   = $user && $user->exists() ? $user->display_name : '';

    /* Reset is a button in the same form, so the other sections' unsaved
     * edits are saved along with it rather than lost. */
    $reset = !empty($_POST['kop_pt_reset']) ? sanitize_key(wp_unslash($_POST['kop_pt_reset'])) : '';
    $posted = isset($_POST['sections']) && is_array($_POST['sections']) ? wp_unslash($_POST['sections']) : array();
    $changed = 0;
    foreach (kop_page_text_defaults($slug) as $d) {
        if ($d['key'] === $reset || !isset($posted[$d['key']]) || !is_array($posted[$d['key']])) {
            continue;
        }
        $heading = isset($posted[$d['key']]['heading'])
            ? trim(preg_replace('/\s+/', ' ', (string) $posted[$d['key']]['heading']))
            : $d['heading'];
        $body = kop_page_text_clean(isset($posted[$d['key']]['body']) ? $posted[$d['key']]['body'] : $d['body']);
        $before = isset($mine[$d['key']]) ? $mine[$d['key']] : null;
        if ($heading === $d['heading'] && $body === $d['body']) {
            if ($before) {
                $changed++;
            }
            unset($mine[$d['key']]);
        } elseif (!$before || $before['heading'] !== $heading || kop_page_text_clean($before['body']) !== $body) {
            $mine[$d['key']] = array('heading' => $heading, 'body' => $body, 'user' => $who, 'time' => time());
            $changed++;
        }
    }
    $saved = $changed === 1 ? '1 section saved.' : $changed . ' sections saved.';
    if ($reset !== '') {
        unset($mine[$reset]);
        $notice = 'That section is back to the original text.' . ($changed ? ' ' . $saved : '');
    } else {
        $notice = $changed ? $saved . ' The page shows the new text now.' : 'Nothing had changed, so nothing was saved.';
    }
    if ($mine) {
        $edits[$slug] = $mine;
    } else {
        unset($edits[$slug]);
    }
    kop_page_text_save_edits($edits);
    kop_page_text_purge($slug);
    return $notice;
}

/** The merged page file, as a download to commit. */
add_action('admin_post_kop_page_text_download', function () {
    if (!current_user_can('manage_options')) {
        wp_die('Not authorized', 'Access Denied', array('response' => 403));
    }
    $slug = kop_page_text_editor_slug();
    check_admin_referer('kop_page_text_download_' . $slug);
    $page = kop_page_text_page($slug);
    $path = get_stylesheet_directory() . '/' . $page['file'];
    $data = json_decode((string) file_get_contents($path), true);
    $data = is_array($data) ? $data : array();
    $data['sections'] = array_map(function ($s) {
        return array('key' => $s['key'], 'label' => $s['label'], 'style' => $s['style'], 'heading' => $s['heading'], 'body' => $s['body']);
    }, kop_page_text_sections($slug));
    nocache_headers();
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . basename($page['file']) . '"');
    echo wp_json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    exit;
});

/** Live preview of one section, drawn by the same renderer as the page. */
add_action('wp_ajax_kop_page_text_preview', function () {
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Not authorized', 403);
    }
    $slug = kop_page_text_editor_slug();
    check_ajax_referer('kop_page_text_' . $slug);
    $page = kop_page_text_page($slug);
    $key = isset($_POST['key']) ? sanitize_key(wp_unslash($_POST['key'])) : '';
    $section = null;
    foreach (kop_page_text_defaults($slug) as $d) {
        if ($d['key'] === $key) {
            $section = $d;
        }
    }
    if (!$page || !$section) {
        wp_send_json_error('Unknown section', 400);
    }
    $section['heading'] = isset($_POST['heading']) ? trim((string) wp_unslash($_POST['heading'])) : $section['heading'];
    $section['body'] = isset($_POST['body']) ? (string) wp_unslash($_POST['body']) : $section['body'];
    wp_send_json_success(array('html' => kop_page_text_section_html($section, $page['prefix'])));
});

/** The page's own stylesheet on the editor screen, for the previews. */
add_action('admin_enqueue_scripts', function ($hook) {
    if (strpos((string) $hook, KOP_PAGE_TEXT_EDITOR_PAGE) === false) {
        return;
    }
    $page = kop_page_text_page(kop_page_text_editor_slug());
    $dir = get_stylesheet_directory();
    $uri = get_stylesheet_directory_uri();
    if (file_exists($dir . '/css/colors.css')) {
        wp_enqueue_style('kop-colors', $uri . '/css/colors.css', array(), filemtime($dir . '/css/colors.css'));
    }
    if ($page && !empty($page['css']) && file_exists($dir . '/' . $page['css'])) {
        wp_enqueue_style('kop-page-text-preview', $uri . '/' . $page['css'], array('kop-colors'), filemtime($dir . '/' . $page['css']));
    }
});

/* ---- Screen ------------------------------------------------------------- */

function kop_render_page_text_editor() {
    if (!current_user_can('manage_options')) {
        wp_die('Not authorized', 'Access Denied', array('response' => 403));
    }
    $slug = kop_page_text_editor_slug();
    echo '<div class="wrap kop-pt">';
    kop_page_text_editor_styles();

    if ($slug === '') {
        echo '<h1>Page Text</h1><p>Choose a page to edit:</p><ul>';
        foreach (kop_page_text_pages() as $s => $p) {
            echo '<li><a href="' . esc_url(kop_page_text_editor_url(array('kop_page' => $s))) . '">' . esc_html($p['title']) . '</a></li>';
        }
        echo '</ul></div>';
        return;
    }
    $page = kop_page_text_page($slug);

    $notice = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['kop_pt_do'])) {
        $notice = kop_page_text_editor_handle_post($slug);
    }
    $cleared = kop_page_text_clear_matching($slug);
    $sections = kop_page_text_sections($slug);
    if (!$sections) {
        echo '<h1>Page Text</h1><div class="notice notice-error"><p>The text file <code>' . esc_html($page['file']) . '</code> is missing on the server.</p></div></div>';
        return;
    }
    $view = kop_page_text_view_link($slug);

    echo '<h1>' . esc_html($page['title']) . '</h1>';
    if ($notice !== '') {
        echo '<div class="notice notice-success is-dismissible"><p>' . esc_html($notice) . '</p></div>';
    }
    if ($cleared) {
        echo '<div class="notice notice-info is-dismissible"><p>' . esc_html($cleared === 1 ? '1 saved edit is now in the repository copy and was cleared.' : $cleared . ' saved edits are now in the repository copy and were cleared.') . '</p></div>';
    }

    echo '<div class="kop-pt-top">';
    if ($view['url']) {
        echo '<a class="button" href="' . esc_url($view['url']) . '" target="_blank" rel="noopener">View the page</a> ';
        if ($view['status'] !== 'publish') {
            echo '<span class="kop-pt-muted">This page is a <strong>' . esc_html($view['status']) . '</strong>: only logged-in admins can see it. Publish it from <a href="' . esc_url(get_edit_post_link($view['id'])) . '">the page settings</a> when it is ready.</span>';
        }
    } else {
        echo '<span class="kop-pt-muted">The page itself has not been created yet.</span>';
    }
    echo '</div>';

    ?>
    <details class="kop-pt-help">
        <summary>How to format the text</summary>
        <ul>
            <li>Leave an empty line between paragraphs.</li>
            <li>Select words and press <strong>Bold</strong>, <strong>Italic</strong> or <strong>Link</strong>, or type <code>**bold**</code>, <code>*italic*</code> and <code>[link text](https://address)</code>.</li>
            <li>A line starting with <code>- </code> is a bullet point. A line starting with <code>### </code> is a small heading.</li>
            <li>Phone numbers written like <code>1-866-925-4419</code> become tap-to-call links on their own.</li>
            <li>In the organizations section, write each one as <code>- [Name](https://address): what they do</code> and it becomes a card.</li>
            <li>Links to this site can start with a slash, like <code>[glossary](/glossary/)</code>. Do not type HTML: it shows up as plain text.</li>
        </ul>
    </details>

    <form method="post" id="kop-pt-form">
        <?php wp_nonce_field('kop_page_text_' . $slug); ?>
        <input type="hidden" name="kop_pt_do" value="1">
        <input type="hidden" name="kop_page" value="<?php echo esc_attr($slug); ?>">
        <?php foreach ($sections as $s) : ?>
            <div class="kop-pt-section" data-key="<?php echo esc_attr($s['key']); ?>">
                <div class="kop-pt-section-head">
                    <h2><?php echo esc_html($s['label']); ?></h2>
                    <?php if ($s['edit']) : ?>
                        <span class="kop-pt-badge">Edited<?php
                            echo $s['edit']['user'] !== '' ? ' by ' . esc_html($s['edit']['user']) : '';
                            echo !empty($s['edit']['time']) ? ', ' . esc_html(wp_date('M j, Y g:i a', (int) $s['edit']['time'])) : '';
                        ?></span>
                        <button type="submit" class="button-link kop-pt-reset" name="kop_pt_reset" value="<?php echo esc_attr($s['key']); ?>">Put back the original</button>
                    <?php endif; ?>
                </div>
                <div class="kop-pt-cols">
                    <div class="kop-pt-edit">
                        <?php if ($s['heading'] !== '' || $s['edit']) : ?>
                            <label>Heading
                                <input type="text" class="kop-pt-heading" name="sections[<?php echo esc_attr($s['key']); ?>][heading]" value="<?php echo esc_attr($s['heading']); ?>">
                            </label>
                        <?php endif; ?>
                        <div class="kop-pt-toolbar" role="toolbar" aria-label="Formatting">
                            <button type="button" class="button" data-do="bold"><strong>Bold</strong></button>
                            <button type="button" class="button" data-do="italic"><em>Italic</em></button>
                            <button type="button" class="button" data-do="link">Link</button>
                            <button type="button" class="button" data-do="list">Bullet point</button>
                            <button type="button" class="button" data-do="h3">Small heading</button>
                        </div>
                        <textarea class="kop-pt-body" name="sections[<?php echo esc_attr($s['key']); ?>][body]" rows="<?php echo (int) min(30, max(4, substr_count($s['body'], "\n") + 3)); ?>"><?php echo esc_textarea($s['body']); ?></textarea>
                    </div>
                    <div class="kop-pt-preview-wrap">
                        <div class="kop-pt-preview-label">Preview</div>
                        <div class="<?php echo esc_attr($page['prefix']); ?>-page kop-pt-preview"><?php echo kop_page_text_section_html($s, $page['prefix']); ?></div>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>

        <div class="kop-pt-savebar">
            <button type="submit" class="button button-primary button-hero">Save changes</button>
            <span class="kop-pt-muted" id="kop-pt-dirty">Saved changes appear on the page at once.</span>
        </div>
    </form>

    <div class="kop-pt-box">
        <h2>Keep your edits in the repository</h2>
        <p>Edits saved here are live, but they are not in git yet. When you are happy with them, download the page file and replace <code><?php echo esc_html($page['file']); ?></code> with it (or send it to Claude to commit). Once that is deployed, the saved edits clear themselves.</p>
        <p><a class="button" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=kop_page_text_download&kop_page=' . rawurlencode($slug)), 'kop_page_text_download_' . $slug)); ?>">Download the page file</a></p>
    </div>
    <?php
    kop_page_text_editor_script($slug);
    echo '</div>';
}

function kop_page_text_editor_styles() {
    ?>
    <style>
        .kop-pt .kop-pt-top { margin: 12px 0; display: flex; gap: 10px; align-items: center; flex-wrap: wrap; }
        .kop-pt .kop-pt-muted { color: #646970; }
        .kop-pt .kop-pt-help { background: #fff; border: 1px solid #c3c4c7; padding: 8px 16px; margin: 12px 0; max-width: 1200px; }
        .kop-pt .kop-pt-help summary { cursor: pointer; font-weight: 600; }
        .kop-pt .kop-pt-section { background: #fff; border: 1px solid #c3c4c7; padding: 12px 16px 16px; margin: 16px 0; max-width: 1200px; }
        .kop-pt .kop-pt-section-head { display: flex; gap: 12px; align-items: baseline; flex-wrap: wrap; }
        .kop-pt .kop-pt-section-head h2 { margin: 4px 0 8px; font-size: 1.15em; }
        .kop-pt .kop-pt-badge { display: inline-block; padding: 1px 8px; border-radius: 10px; font-size: 12px; background: #d1e7dd; }
        .kop-pt .kop-pt-reset { color: #b32d2e; }
        .kop-pt .kop-pt-cols { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
        @media (max-width: 1100px) { .kop-pt .kop-pt-cols { grid-template-columns: 1fr; } }
        .kop-pt .kop-pt-edit label { display: block; font-weight: 600; margin-bottom: 8px; }
        .kop-pt .kop-pt-heading { display: block; width: 100%; margin-top: 4px; font-weight: normal; }
        .kop-pt .kop-pt-toolbar { display: flex; gap: 4px; flex-wrap: wrap; margin-bottom: 6px; }
        .kop-pt .kop-pt-body { width: 100%; font-size: 14px; line-height: 1.55; font-family: inherit; overflow: hidden; resize: vertical; }
        .kop-pt .kop-pt-preview-label { font-size: 12px; color: #646970; text-transform: uppercase; letter-spacing: .04em; margin-bottom: 4px; }
        .kop-pt .kop-pt-preview { margin: 0; padding: 14px 18px; max-width: none; box-shadow: none; border: 1px dashed #c3c4c7; border-radius: 6px; font-size: 15px; }
        .kop-pt .kop-pt-preview section { margin-top: 0; }
        .kop-pt .kop-pt-savebar { position: sticky; bottom: 0; background: #f0f0f1; padding: 12px 0; display: flex; gap: 12px; align-items: center; z-index: 10; }
        .kop-pt .kop-pt-savebar.is-dirty #kop-pt-dirty { color: #b32d2e; font-weight: 600; }
        .kop-pt .kop-pt-box { background: #fff; border: 1px solid #c3c4c7; padding: 12px 16px; margin: 16px 0; max-width: 1200px; }
    </style>
    <?php
}

function kop_page_text_editor_script($slug) {
    $config = array(
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'nonce'   => wp_create_nonce('kop_page_text_' . $slug),
        'page'    => $slug,
    );
    ?>
    <script>
    (function () {
        var cfg = <?php echo wp_json_encode($config); ?>;
        var form = document.getElementById('kop-pt-form');
        var dirty = false;

        function markDirty() {
            if (dirty) { return; }
            dirty = true;
            form.querySelector('.kop-pt-savebar').classList.add('is-dirty');
            document.getElementById('kop-pt-dirty').textContent = 'You have changes that are not saved yet.';
        }
        window.addEventListener('beforeunload', function (e) {
            if (dirty) { e.preventDefault(); e.returnValue = ''; }
        });
        form.addEventListener('submit', function (e) {
            var reset = e.submitter && e.submitter.name === 'kop_pt_reset';
            if (reset && !window.confirm('Put this section back to the original text? Your edits to it will be lost.')) {
                e.preventDefault();
                return;
            }
            dirty = false;
        });

        function wrap(ta, before, after) {
            var s = ta.selectionStart, e = ta.selectionEnd, v = ta.value;
            var sel = v.slice(s, e) || 'text';
            ta.value = v.slice(0, s) + before + sel + after + v.slice(e);
            ta.setSelectionRange(s + before.length, s + before.length + sel.length);
        }
        function lineStart(ta, mark) {
            var s = ta.selectionStart, v = ta.value;
            var start = v.lastIndexOf('\n', s - 1) + 1;
            if (v.slice(start, start + mark.length) === mark) { return; }
            ta.value = v.slice(0, start) + mark + v.slice(start);
            ta.setSelectionRange(s + mark.length, s + mark.length);
        }

        document.querySelectorAll('.kop-pt-section').forEach(function (box) {
            var ta = box.querySelector('.kop-pt-body');
            var heading = box.querySelector('.kop-pt-heading');
            var preview = box.querySelector('.kop-pt-preview');
            var timer = null;

            function refresh() {
                clearTimeout(timer);
                timer = setTimeout(function () {
                    var body = new FormData();
                    body.append('action', 'kop_page_text_preview');
                    body.append('_ajax_nonce', cfg.nonce);
                    body.append('kop_page', cfg.page);
                    body.append('key', box.getAttribute('data-key'));
                    body.append('body', ta.value);
                    if (heading) { body.append('heading', heading.value); }
                    fetch(cfg.ajaxUrl, { method: 'POST', body: body, credentials: 'same-origin' })
                        .then(function (r) { return r.json(); })
                        .then(function (r) { if (r && r.success) { preview.innerHTML = r.data.html; } })
                        .catch(function () {});
                }, 350);
            }
            function grow() { ta.style.height = 'auto'; ta.style.height = (ta.scrollHeight + 4) + 'px'; }
            function changed() { markDirty(); grow(); refresh(); }
            grow();

            ta.addEventListener('input', changed);
            if (heading) { heading.addEventListener('input', changed); }

            box.querySelectorAll('.kop-pt-toolbar button').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    var what = btn.getAttribute('data-do');
                    ta.focus();
                    if (what === 'bold') { wrap(ta, '**', '**'); }
                    else if (what === 'italic') { wrap(ta, '*', '*'); }
                    else if (what === 'list') { lineStart(ta, '- '); }
                    else if (what === 'h3') { lineStart(ta, '### '); }
                    else if (what === 'link') {
                        var url = window.prompt('Paste the web address for the link (starting with https://):', 'https://');
                        if (!url || url === 'https://') { return; }
                        wrap(ta, '[', '](' + url.trim() + ')');
                    }
                    changed();
                });
            });
        });
    })();
    </script>
    <?php
}
