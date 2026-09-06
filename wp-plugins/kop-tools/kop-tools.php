<?php
/**
 * Plugin Name: KOP Tools
 * Description: Central dashboard for the Kids Over Profits admin tools that live in the child theme's api/ directory (folder linking, address identity, news story grouping, media re-filing, and the one-off maintenance scripts). Adds a "KOP Tools" menu to wp-admin so nothing has to be reached by memorized URL.
 * Version: 1.1.0
 * Author: Kids Over Profits
 * License: GPL-2.0-or-later
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The tool registry. Each entry:
 *   title    Human name shown on the dashboard.
 *   desc     One-line description of what it does.
 *   path     File path relative to the ACTIVE CHILD THEME root. The dashboard
 *            links tools through get_stylesheet_directory_uri(), so the plugin
 *            keeps working if the theme is redeployed or renamed, and simply
 *            greys out tools whose file is absent (useful when reusing this
 *            plugin on a site that only carries part of the toolset).
 *   type     'page'   — self-contained HTML tool, opened in a new tab.
 *            'action' — POST-only endpoint, run from the dashboard button;
 *                       the JSON response is shown inline.
 *
 * Extend or override from other code via the `kop_tools_registry` filter.
 */
function kop_tools_registry() {
    $tools = array(
        'Media & folders' => array(
            array(
                'title' => 'Sort Media',
                'desc'  => 'Re-file mis-filed documents: move or copy attachments between FileBird folders, including the theme\'s extra folder tags.',
                'path'  => 'api/sort-media.php',
                'type'  => 'page',
            ),
            array(
                'title' => 'Link Folders',
                'desc'  => 'Mark two FileBird folders as the same facility under different names (rename/rebrand), so document feeds show the merged contents under both.',
                'path'  => 'api/link-folders.php',
                'type'  => 'page',
            ),
            array(
                'title' => 'Organize Uncategorized Media',
                'desc'  => 'List attachments that are in no FileBird folder and file them, with a suggested destination matched from the filename.',
                'path'  => 'api/organize-uncategorized-media.php',
                'type'  => 'page',
            ),
        ),
        'News' => array(
            array(
                'title' => 'Manage Story Arcs',
                'desc'  => 'Create and edit the ongoing story arcs featured on the news feed; attach or detach member articles and scan the archive for candidates.',
                'path'  => 'api/manage-story-arcs.php',
                'type'  => 'page',
            ),
            array(
                'title' => 'Manage Duplicate Articles',
                'desc'  => 'Scan news_submissions for the same article recorded more than once and clean up: keep one copy, delete the rest.',
                'path'  => 'api/manage-duplicate-articles.php',
                'type'  => 'page',
            ),
            array(
                'title' => 'Rebuild Story Groups',
                'desc'  => 'Re-cluster every news submission into cross-outlet story groups. Idempotent repair; day-to-day grouping already happens on save.',
                'path'  => 'api/rebuild-news-story-groups.php',
                'type'  => 'action',
            ),
        ),
        'Submissions' => array(
            array(
                'title' => 'Approve Submissions',
                'desc'  => 'Review public suggested edits (facilities, locations, referrers) and approve them into the master tables or reject them.',
                'path'  => 'api/approve-edits.php',
                'type'  => 'page',
            ),
            array(
                'title' => 'Publish Approved Records',
                'desc'  => 'Move legislation and lawsuit rows still sitting in "approved" to "published". Dry run on open; the update runs only from the button on that page.',
                'path'  => 'api/publish-approved-records.php',
                'type'  => 'page',
            ),
        ),
        'Facilities & site data' => array(
            array(
                'title' => 'Manage Addresses',
                'desc'  => 'Give each physical campus a stable address ID and record which facilities stood there, independent of the names on the sign.',
                'path'  => 'api/manage-addresses.php',
                'type'  => 'page',
            ),
            array(
                'title' => 'Manage Featured Inspections',
                'desc'  => 'Curate the "inspections that demand attention" block on the home page: feature scraped inspection reports with a one-line note.',
                'path'  => 'api/manage-featured-inspections.php',
                'type'  => 'page',
            ),
        ),
        'Maintenance & repairs' => array(
            array(
                'title' => 'Consolidate FileBird Folders',
                'desc'  => 'Repair orphaned folder subtrees whose parent folder no longer exists.',
                'path'  => 'api/consolidate-filebird-folders.php',
                'type'  => 'page',
            ),
            array(
                'title' => 'Fix Document Folders',
                'desc'  => 'Repair facility records whose stored FileBird folder ID points at a deleted folder.',
                'path'  => 'api/fix-doc-folders.php',
                'type'  => 'page',
            ),
            array(
                'title' => 'Fix Slug Titles',
                'desc'  => 'Humanize attachment titles left as filename slugs by the Google Drive media restore.',
                'path'  => 'api/fix-slug-titles.php',
                'type'  => 'page',
            ),
            array(
                'title' => 'Retitle From Content',
                'desc'  => 'AI-title unclear attachments by reading the document (PDF/DOCX/TXT/images, vision OCR for scans): review, edit, and apply new titles. Files and URLs are untouched.',
                'path'  => 'api/retitle-from-content.php',
                'type'  => 'page',
            ),
            array(
                'title' => 'Clean Up Wiki Submissions',
                'desc'  => 'Purge rejected/deleted wiki submissions and collapse duplicates (dry-run first).',
                'path'  => 'api/cleanup-wiki-submissions.php',
                'type'  => 'page',
            ),
            array(
                'title' => 'Database Schema',
                'desc'  => 'Inspect every table and its columns (read-only JSON).',
                'path'  => 'api/get-database-schema.php',
                'type'  => 'page',
            ),
        ),
    );
    return apply_filters('kop_tools_registry', $tools);
}

/**
 * Admin-facing WordPress PAGES (assigned page templates in the child theme).
 * Their URLs depend on which page each template is assigned to, so the
 * dashboard resolves them live. Template metas may be stored with or without
 * the templates/ prefix depending on when the page was created.
 */
function kop_tools_admin_page_templates() {
    $templates = array(
        'page-news-processor.php'    => 'News Processor — submit and AI-process articles into the news feed',
        'page-admin-submissions.php' => 'Submissions Review — approve or reject news, wiki, and data submissions',
        'page-admin-data.php'        => 'Admin Data Form — edit facilities_master records directly',
        'page-admin-data-manager.php'=> 'Data Manager — cross-table listing, recategorize and reassign records',
        'page-admin-lawsuits.php'    => 'Lawsuit Admin — review and publish lawsuit records',
        'page-admin-legislation.php' => 'Legislation Admin — review and publish legislation records',
        'page-admin-volunteers.php'  => 'Volunteer Admin — review volunteer sign-ups',
        'page-wiki-editor.php'       => 'Wiki Editor — write and edit facility wiki entries',
    );
    return apply_filters('kop_tools_admin_page_templates', $templates);
}

/**
 * Resolve the admin page templates to the published/private pages that use
 * them. Returns a list of arrays: title, label, template, url. Only pages that
 * actually exist are returned, in the order of kop_tools_admin_page_templates().
 * Cached per request because both the sidebar and the admin bar ask for it.
 */
function kop_tools_admin_pages() {
    static $resolved = null;
    if ($resolved !== null) {
        return $resolved;
    }
    $templates = kop_tools_admin_page_templates();
    $meta_values = array();
    foreach (array_keys($templates) as $t) {
        $meta_values[] = $t;
        $meta_values[] = 'templates/' . $t;
    }
    $pages = get_posts(array(
        'post_type'      => 'page',
        'post_status'    => array('publish', 'private'),
        'posts_per_page' => -1,
        'meta_query'     => array(array(
            'key'     => '_wp_page_template',
            'value'   => $meta_values,
            'compare' => 'IN',
        )),
        'orderby'        => 'title',
        'order'          => 'ASC',
    ));
    $by_template = array();
    foreach ($pages as $page) {
        $tpl = basename((string) get_post_meta($page->ID, '_wp_page_template', true));
        $by_template[$tpl][] = array(
            'title'    => get_the_title($page),
            'label'    => isset($templates[$tpl]) ? $templates[$tpl] : $tpl,
            'template' => $tpl,
            'url'      => get_permalink($page),
        );
    }
    $resolved = array();
    foreach (array_keys($templates) as $tpl) {
        if (!empty($by_template[$tpl])) {
            foreach ($by_template[$tpl] as $row) {
                $resolved[] = $row;
            }
        }
    }
    return $resolved;
}

/**
 * Sidebar menu: the dashboard, then one submenu link per admin page so the
 * front-end tools are one click away. Full URLs as submenu slugs are treated
 * by WordPress as external links. The theme adds its own screens (Bug
 * Reports) under this same parent when the plugin is active.
 */
add_action('admin_menu', function () {
    add_menu_page(
        'KOP Tools',
        'KOP Tools',
        'manage_options',
        'kop-tools',
        'kop_tools_render_dashboard',
        'dashicons-admin-tools',
        6
    );
    add_submenu_page('kop-tools', 'All Tools', 'All Tools', 'manage_options', 'kop-tools', 'kop_tools_render_dashboard');
    foreach (kop_tools_admin_pages() as $page) {
        add_submenu_page('kop-tools', $page['title'], $page['title'], 'manage_options', $page['url'], '');
    }
});

/**
 * Admin bar dropdown (wp-admin and front end): every registered tool, grouped
 * by category, plus the admin pages. Missing tool files are skipped. Action
 * tools have no GET page, so they link to the dashboard where their Run
 * button lives.
 */
add_action('admin_bar_menu', function ($wp_admin_bar) {
    if (!current_user_can('manage_options')) {
        return;
    }
    $dashboard = admin_url('admin.php?page=kop-tools');
    $theme_dir = trailingslashit(get_stylesheet_directory());
    $theme_uri = trailingslashit(get_stylesheet_directory_uri());

    $wp_admin_bar->add_node(array(
        'id'    => 'kop-tools',
        'title' => 'KOP Tools',
        'href'  => $dashboard,
    ));
    $wp_admin_bar->add_node(array(
        'parent' => 'kop-tools',
        'id'     => 'kop-tools-dashboard',
        'title'  => 'All Tools (dashboard)',
        'href'   => $dashboard,
    ));

    $pages = kop_tools_admin_pages();
    if ($pages) {
        $wp_admin_bar->add_node(array(
            'parent' => 'kop-tools',
            'id'     => 'kop-tools-pages',
            'title'  => 'Admin pages',
            'href'   => $dashboard,
        ));
        foreach ($pages as $i => $page) {
            $wp_admin_bar->add_node(array(
                'parent' => 'kop-tools-pages',
                'id'     => 'kop-tools-page-' . $i,
                'title'  => $page['title'],
                'href'   => $page['url'],
            ));
        }
    }

    foreach (kop_tools_registry() as $category => $tools) {
        $cat_id = 'kop-tools-cat-' . sanitize_title($category);
        $added_category = false;
        foreach ($tools as $tool) {
            if (!file_exists($theme_dir . $tool['path'])) {
                continue;
            }
            if (!$added_category) {
                $wp_admin_bar->add_node(array(
                    'parent' => 'kop-tools',
                    'id'     => $cat_id,
                    'title'  => $category,
                    'href'   => $dashboard,
                ));
                $added_category = true;
            }
            $is_action = ($tool['type'] === 'action');
            $wp_admin_bar->add_node(array(
                'parent' => $cat_id,
                'id'     => 'kop-tools-' . sanitize_title($tool['title']),
                'title'  => $tool['title'] . ($is_action ? ' (run from dashboard)' : ''),
                'href'   => $is_action ? $dashboard : $theme_uri . $tool['path'],
                'meta'   => $is_action ? array() : array('target' => '_blank', 'rel' => 'noopener'),
            ));
        }
    }

    // Theme-provided wp-admin screens that hang off this menu.
    if (function_exists('kop_render_bug_reports_page')) {
        $wp_admin_bar->add_node(array(
            'parent' => 'kop-tools',
            'id'     => 'kop-tools-bug-reports',
            'title'  => 'Bug Reports',
            'href'   => admin_url('admin.php?page=kop-bug-reports'),
        ));
    }
}, 90);

function kop_tools_render_dashboard() {
    if (!current_user_can('manage_options')) {
        wp_die('Not authorized.');
    }

    $theme_dir = trailingslashit(get_stylesheet_directory());
    $theme_uri = trailingslashit(get_stylesheet_directory_uri());

    echo '<div class="wrap"><h1>KOP Tools</h1>';
    echo '<p>Admin tools shipped in the active theme (<code>' . esc_html(get_stylesheet()) . '</code>). '
       . 'Tools open in a new tab; each enforces its own admin login check. '
       . 'Greyed-out entries are registered here but missing from the theme on this site.</p>';

    foreach (kop_tools_registry() as $category => $tools) {
        echo '<h2>' . esc_html($category) . '</h2>';
        echo '<table class="widefat striped" style="max-width:960px;margin-bottom:18px"><tbody>';
        foreach ($tools as $tool) {
            $exists = file_exists($theme_dir . $tool['path']);
            $url = $theme_uri . $tool['path'];
            echo '<tr' . ($exists ? '' : ' style="opacity:.45"') . '>';
            echo '<td style="width:220px"><strong>' . esc_html($tool['title']) . '</strong><br>'
               . '<code style="font-size:11px">' . esc_html($tool['path']) . '</code></td>';
            echo '<td>' . esc_html($tool['desc']) . '</td>';
            echo '<td style="width:130px;text-align:right">';
            if (!$exists) {
                echo '<em>not installed</em>';
            } elseif ($tool['type'] === 'action') {
                echo '<button type="button" class="button button-secondary kop-tools-run" data-url="'
                   . esc_url($url) . '" data-title="' . esc_attr($tool['title']) . '">Run</button>';
            } else {
                echo '<a class="button button-primary" target="_blank" rel="noopener" href="'
                   . esc_url($url) . '">Open</a>';
            }
            echo '</td></tr>';
        }
        echo '</tbody></table>';
    }

    // Admin page templates resolved to actual published pages.
    $pages = kop_tools_admin_pages();

    echo '<h2>Admin pages</h2>';
    if (!$pages) {
        echo '<p><em>No pages found using the admin page templates.</em></p>';
    } else {
        echo '<table class="widefat striped" style="max-width:960px"><tbody>';
        foreach ($pages as $page) {
            echo '<tr>';
            echo '<td style="width:220px"><strong>' . esc_html($page['title']) . '</strong><br>'
               . '<code style="font-size:11px">' . esc_html($page['template']) . '</code></td>';
            echo '<td>' . esc_html($page['label']) . '</td>';
            echo '<td style="width:130px;text-align:right"><a class="button" target="_blank" rel="noopener" href="'
               . esc_url($page['url']) . '">Open</a></td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
    }

    // wp-admin screens the theme registers under this menu.
    if (function_exists('kop_render_bug_reports_page')) {
        echo '<h2>Other admin screens</h2>';
        echo '<table class="widefat striped" style="max-width:960px"><tbody><tr>';
        echo '<td style="width:220px"><strong>Bug Reports</strong></td>';
        echo '<td>Triage reports sent from the on-site bug widget.</td>';
        echo '<td style="width:130px;text-align:right"><a class="button" href="'
           . esc_url(admin_url('admin.php?page=kop-bug-reports')) . '">Open</a></td>';
        echo '</tr></tbody></table>';
    }

    // Output panel + runner for 'action' tools. Same-origin fetch carries the
    // WordPress auth cookies; the endpoints do their own capability checks.
    ?>
    <pre id="kop-tools-output" style="display:none;max-width:960px;background:#fff;border:1px solid #c3c4c7;padding:12px;white-space:pre-wrap"></pre>
    <script>
    (function () {
        var out = document.getElementById('kop-tools-output');
        document.querySelectorAll('.kop-tools-run').forEach(function (btn) {
            btn.addEventListener('click', function () {
                if (!window.confirm('Run "' + btn.dataset.title + '" now?')) return;
                btn.disabled = true;
                out.style.display = 'block';
                out.textContent = 'Running ' + btn.dataset.title + '…';
                fetch(btn.dataset.url, { method: 'POST', credentials: 'same-origin' })
                    .then(function (res) { return res.text().then(function (t) { return { status: res.status, text: t }; }); })
                    .then(function (r) {
                        var body = r.text;
                        try { body = JSON.stringify(JSON.parse(r.text), null, 2); } catch (e) {}
                        out.textContent = 'HTTP ' + r.status + '\n' + body;
                    })
                    .catch(function (err) { out.textContent = 'Request failed: ' + err; })
                    .finally(function () { btn.disabled = false; });
            });
        });
    })();
    </script>
    <?php
    echo '</div>';
}
