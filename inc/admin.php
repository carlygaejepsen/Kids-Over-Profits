<?php
/**
 * Admin menu and page rendering functions.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Add approval page to admin menu
 */
function add_approval_page_to_menu() {
    add_menu_page(
        'Approve Facility Edits',           // Page title
        'Approve Edits',                     // Menu title
        'manage_options',                    // Capability (admin only)
        'approve-facility-edits',            // Menu slug
        'render_approval_page_iframe',       // Callback function
        'dashicons-yes-alt',                // Icon
        6
    );
}
add_action('admin_menu', 'add_approval_page_to_menu');

/**
 * Render approval page iframe
 */
function render_approval_page_iframe() {
    $url = get_stylesheet_directory_uri() . '/api/approve-edits.php';
    ?>
    <div class="wrap">
        <iframe src="<?php echo esc_url($url); ?>" style="width: 100%; height: calc(100vh - 100px); border: none;"></iframe>
    </div>
    <?php
}

/**
 * The front-end tool pages (Data Manager, Wiki Editor, etc.) are WordPress
 * Pages with a page template assigned, so their URL depends on the page slug
 * the editor chose. Resolve the permalink for whichever page uses a template.
 *
 * Returns the permalink, or '' if no page currently uses that template.
 */
function kop_find_template_page_url($template) {
    foreach (array($template, 'templates/' . $template) as $value) {
        $pages = get_pages(array(
            'meta_key'    => '_wp_page_template',
            'meta_value'  => $value,
            'number'      => 1,
            'post_status' => 'publish,private,draft',
        ));
        if (!empty($pages)) {
            return get_permalink($pages[0]->ID);
        }
    }
    return '';
}

/**
 * The KOP tool pages, keyed by their page template. Order = menu order.
 */
function kop_data_tool_pages() {
    return array(
        'page-admin-data-manager.php' => 'Data Manager',
        'page-wiki-editor.php'        => 'Wiki Editor',
        'page-admin-data.php'         => 'Admin Data Form',
        'page-admin-submissions.php'  => 'Submissions Review',
        'page-news-processor.php'     => 'News Processor',
        'page-admin-lawsuits.php'     => 'Lawsuit Admin',
        'page-admin-legislation.php'  => 'Legislation Admin',
        'page-admin-volunteers.php'   => 'Volunteer Admin',
    );
}

/**
 * The KOP Tools plugin (wp-plugins/kop-tools) owns the full tool registry:
 * every api/ tool plus the admin pages. When it is active the theme hangs its
 * own screens off the plugin's "KOP Tools" menu instead of registering a
 * second, thinner "KOP Data Tools" menu and admin bar dropdown.
 */
function kop_tools_plugin_active() {
    return function_exists('kop_tools_registry');
}

/**
 * Parent menu slug for the theme's wp-admin screens (Bug Reports).
 */
function kop_tools_parent_slug() {
    return kop_tools_plugin_active() ? 'kop-tools' : 'kop-data-tools';
}

/**
 * Add a "KOP Data Tools" group to the wp-admin sidebar with links straight to
 * the front-end tool pages. WordPress treats a full URL passed as the submenu
 * slug as an external link, so these jump directly to the pages.
 *
 * Fallback only: with the KOP Tools plugin active, the plugin's menu already
 * lists these pages and everything else.
 */
function kop_register_data_tools_menu() {
    if (kop_tools_plugin_active()) {
        return;
    }
    add_menu_page(
        'KOP Data Tools',
        'KOP Data Tools',
        'manage_options',
        'kop-data-tools',
        'kop_render_data_tools_landing',
        'dashicons-database-view',
        6
    );

    foreach (kop_data_tool_pages() as $template => $title) {
        $url = kop_find_template_page_url($template);
        add_submenu_page(
            'kop-data-tools',
            $title,
            $title,
            'manage_options',
            $url ? $url : 'kop-data-tools',
            $url ? '' : 'kop_render_data_tools_landing'
        );
    }
}
add_action('admin_menu', 'kop_register_data_tools_menu');

/**
 * Bug Reports triage screen under KOP Data Tools. Reads the bug_reports table
 * written by api/save-bug-report.php (front-end widget).
 */
function kop_register_bug_reports_menu() {
    add_submenu_page(
        kop_tools_parent_slug(),
        'Bug Reports',
        'Bug Reports',
        'manage_options',
        'kop-bug-reports',
        'kop_render_bug_reports_page'
    );
}
add_action('admin_menu', 'kop_register_bug_reports_menu', 20);

function kop_render_bug_reports_page() {
    global $wpdb;

    if (!current_user_can('manage_options')) {
        wp_die('Not authorized');
    }

    $statuses = array('new', 'in_progress', 'resolved', 'dismissed');

    // Handle a triage action (status change) from the buttons below.
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['kop_bug_id'], $_POST['kop_bug_status'])) {
        check_admin_referer('kop_bug_triage');
        $id = (int) $_POST['kop_bug_id'];
        $new_status = sanitize_key($_POST['kop_bug_status']);
        if ($id > 0 && in_array($new_status, $statuses, true)) {
            if (function_exists('kop_bug_ensure_notify_column')) {
                kop_bug_ensure_notify_column();
            }
            $existing = $wpdb->get_row($wpdb->prepare('SELECT * FROM bug_reports WHERE id = %d', $id));
            $wpdb->query($wpdb->prepare(
                'UPDATE bug_reports SET status = %s WHERE id = %d',
                $new_status,
                $id
            ));
            $notified = false;
            if ($existing && function_exists('kop_bug_report_notify_status_change')) {
                $notified = kop_bug_report_notify_status_change($existing, $new_status, (string) $existing->status);
            }
            echo '<div class="notice notice-success is-dismissible"><p>Report #' . esc_html($id)
                . ' marked as ' . esc_html(str_replace('_', ' ', $new_status)) . '.'
                . ($notified ? ' The reporter was emailed about the change.' : '')
                . '</p></div>';
        }
    }

    $filter = isset($_GET['bug_status']) ? sanitize_key($_GET['bug_status']) : '';
    if (!in_array($filter, $statuses, true)) {
        $filter = '';
    }

    // Table may not exist until the first report is submitted.
    $table_exists = $wpdb->get_var("SHOW TABLES LIKE 'bug_reports'") === 'bug_reports';

    echo '<div class="wrap"><h1>Bug Reports</h1>';

    if (!$table_exists) {
        echo '<p>No reports yet — the <code>bug_reports</code> table is created automatically when the first report is submitted from the site.</p></div>';
        return;
    }

    // Tables created before feature-scoped reporting lack the column; add it
    // here (admin context) so the public insert path never needs to run DDL.
    if (!$wpdb->get_var("SHOW COLUMNS FROM bug_reports LIKE 'feature'")) {
        $wpdb->query('ALTER TABLE bug_reports ADD COLUMN feature VARCHAR(120) NULL AFTER id');
    }
    if (function_exists('kop_bug_ensure_notify_column')) {
        kop_bug_ensure_notify_column();
    }

    // Status filter tabs with counts.
    $counts = array();
    foreach ($wpdb->get_results('SELECT status, COUNT(*) AS n FROM bug_reports GROUP BY status') as $row) {
        $counts[$row->status] = (int) $row->n;
    }
    $base_url = admin_url('admin.php?page=kop-bug-reports');
    echo '<ul class="subsubsub">';
    echo '<li><a href="' . esc_url($base_url) . '"' . ($filter === '' ? ' class="current"' : '') . '>All ('
        . array_sum($counts) . ')</a> | </li>';
    foreach ($statuses as $i => $s) {
        $label = ucwords(str_replace('_', ' ', $s));
        echo '<li><a href="' . esc_url(add_query_arg('bug_status', $s, $base_url)) . '"'
            . ($filter === $s ? ' class="current"' : '') . '>' . esc_html($label) . ' ('
            . (isset($counts[$s]) ? $counts[$s] : 0) . ')</a>'
            . ($i < count($statuses) - 1 ? ' | ' : '') . '</li>';
    }
    echo '</ul><div style="clear:both"></div>';

    if ($filter !== '') {
        $reports = $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM bug_reports WHERE status = %s ORDER BY created_at DESC LIMIT 200',
            $filter
        ));
    } else {
        $reports = $wpdb->get_results('SELECT * FROM bug_reports ORDER BY created_at DESC LIMIT 200');
    }

    if (empty($reports)) {
        echo '<p>No reports' . ($filter ? ' with this status' : '') . '.</p></div>';
        return;
    }

    $category_labels = array(
        'save-failed' => 'Saving/submitting failed',
        'load-failed' => 'Something didn’t load',
        'broken-ui'   => 'Broken UI',
        'wrong-data'  => 'Wrong/missing data',
        'other'       => 'Other',
    );

    echo '<table class="widefat striped"><thead><tr>'
        . '<th style="width:60px">#</th><th style="width:130px">When</th><th style="width:170px">Feature / Category</th>'
        . '<th>Report</th><th style="width:120px">Status</th><th style="width:220px">Actions</th>'
        . '</tr></thead><tbody>';

    foreach ($reports as $r) {
        $tech = json_decode($r->console_errors ? $r->console_errors : 'null', true);
        echo '<tr>';
        echo '<td>' . (int) $r->id . '</td>';
        echo '<td>' . esc_html(mysql2date('M j, Y g:ia', $r->created_at)) . '</td>';
        echo '<td>';
        if (!empty($r->feature)) {
            echo '<strong>' . esc_html($r->feature) . '</strong><br>';
        }
        echo '<span style="color:#666">' . esc_html(isset($category_labels[$r->category]) ? $category_labels[$r->category] : $r->category) . '</span></td>';

        echo '<td>';
        echo '<p style="margin:0 0 6px"><strong>' . nl2br(esc_html($r->description)) . '</strong></p>';
        if (!empty($r->steps)) {
            echo '<p style="margin:0 0 6px"><em>Steps:</em> ' . nl2br(esc_html($r->steps)) . '</p>';
        }
        if (!empty($r->page_url)) {
            echo '<p style="margin:0 0 6px"><em>Page:</em> <a href="' . esc_url($r->page_url) . '" target="_blank" rel="noopener">'
                . esc_html($r->page_url) . '</a></p>';
        }
        if (!empty($r->contact)) {
            echo '<p style="margin:0 0 6px"><em>Contact:</em> ' . esc_html($r->contact);
            if (!empty($r->notify_updates)) {
                echo ' <span style="display:inline-block;padding:1px 6px;border-radius:3px;background:#e6f4ea;color:#1b7e3c;font-size:11px;vertical-align:middle">wants status updates</span>';
            }
            echo '</p>';
        }
        if (!empty($tech) || !empty($r->user_agent)) {
            echo '<details><summary style="cursor:pointer">Technical details</summary><div style="font-size:12px;padding:6px 0">';
            if (!empty($r->user_agent)) {
                echo '<p style="margin:0 0 4px"><em>Browser:</em> ' . esc_html($r->user_agent)
                    . ($r->viewport ? ' — ' . esc_html($r->viewport) : '') . '</p>';
            }
            if (!empty($tech) && is_array($tech)) {
                echo '<ul style="margin:4px 0 0 16px;list-style:disc">';
                foreach ($tech as $err) {
                    if (is_array($err)) {
                        echo '<li><code>[' . esc_html(isset($err['type']) ? $err['type'] : '?') . ']</code> '
                            . esc_html(isset($err['message']) ? $err['message'] : '') . '</li>';
                    }
                }
                echo '</ul>';
            }
            echo '</div></details>';
        }
        echo '</td>';

        echo '<td>' . esc_html(ucwords(str_replace('_', ' ', $r->status))) . '</td>';

        echo '<td>';
        foreach ($statuses as $s) {
            if ($s === $r->status) {
                continue;
            }
            echo '<form method="post" style="display:inline-block;margin:0 4px 4px 0">';
            wp_nonce_field('kop_bug_triage');
            echo '<input type="hidden" name="kop_bug_id" value="' . (int) $r->id . '">';
            echo '<input type="hidden" name="kop_bug_status" value="' . esc_attr($s) . '">';
            echo '<button type="submit" class="button button-small">' . esc_html(ucwords(str_replace('_', ' ', $s))) . '</button>';
            echo '</form>';
        }
        echo '</td>';
        echo '</tr>';
    }

    echo '</tbody></table></div>';
}

/**
 * Landing page for the KOP Data Tools menu. Lists each tool and flags any that
 * don't yet have a Page assigned to their template (with setup guidance).
 */
function kop_render_data_tools_landing() {
    echo '<div class="wrap"><h1>KOP Data Tools</h1>';
    echo '<p>Quick links to the front-end data management tools.</p>';
    echo '<table class="widefat striped" style="max-width:760px"><thead><tr>'
        . '<th>Tool</th><th>Status</th><th>Link</th></tr></thead><tbody>';

    foreach (kop_data_tool_pages() as $template => $title) {
        $url = kop_find_template_page_url($template);
        echo '<tr><td><strong>' . esc_html($title) . '</strong></td>';
        if ($url) {
            echo '<td style="color:#1b7e3c">Ready</td>';
            echo '<td><a class="button button-primary" href="' . esc_url($url) . '">Open</a></td>';
        } else {
            echo '<td style="color:#b8860b">No page yet</td>';
            echo '<td><span style="color:#777">Create a Page and set its template to '
                . '“' . esc_html(kop_template_display_name($template)) . '”.</span></td>';
        }
        echo '</tr>';
    }
    echo '</tbody></table></div>';
}

/**
 * Human-readable "Template Name" for the guidance text above.
 */
function kop_template_display_name($template) {
    $names = array(
        'page-admin-data-manager.php' => 'Admin - Data Manager',
        'page-wiki-editor.php'        => 'Wiki Editor',
        'page-admin-data.php'         => 'Admin Data Form',
        'page-admin-submissions.php'  => 'Admin - Submissions Review',
    );
    return isset($names[$template]) ? $names[$template] : $template;
}

/**
 * Also expose the tools in the front-end/admin toolbar so they're reachable
 * while browsing the site, not just from wp-admin. Only existing pages appear.
 */
function kop_add_admin_bar_tool_links($wp_admin_bar) {
    if (!current_user_can('manage_options') || kop_tools_plugin_active()) {
        return;
    }
    $wp_admin_bar->add_node(array(
        'id'    => 'kop-data-tools',
        'title' => 'KOP Tools',
        'href'  => admin_url('admin.php?page=kop-data-tools'),
    ));
    foreach (kop_data_tool_pages() as $template => $title) {
        $url = kop_find_template_page_url($template);
        if (!$url) {
            continue;
        }
        $wp_admin_bar->add_node(array(
            'parent' => 'kop-data-tools',
            'id'     => 'kop-tool-' . sanitize_title($title),
            'title'  => $title,
            'href'   => $url,
        ));
    }
}
add_action('admin_bar_menu', 'kop_add_admin_bar_tool_links', 90);

/**
 * Old bookmarks to the theme's landing page keep working when the plugin has
 * taken over the menu.
 */
function kop_redirect_legacy_tools_page() {
    if (kop_tools_plugin_active() && isset($_GET['page']) && $_GET['page'] === 'kop-data-tools') {
        wp_safe_redirect(admin_url('admin.php?page=kop-tools'));
        exit;
    }
}
add_action('admin_init', 'kop_redirect_legacy_tools_page');

/**
 * Pages the theme will auto-create if they don't already exist. WordPress
 * stores a subdirectory template's path relative to the theme root, so the
 * meta value carries the "templates/" prefix.
 *
 * 'status' is 'private' for admin-only screens so they aren't publicly listed
 * (the templates also gate access in PHP). Pages that almost always already
 * exist on the site are listed too, but the ensure step skips any whose
 * template is already in use, so nothing gets duplicated.
 */
function kop_tool_page_specs() {
    return array(
        array('template' => 'page-admin-data-manager.php', 'title' => 'Data Manager',       'slug' => 'data-manager',       'status' => 'private'),
        array('template' => 'page-admin-data.php',         'title' => 'Admin Data Form',     'slug' => 'admin-data',         'status' => 'private'),
        array('template' => 'page-admin-submissions.php',  'title' => 'Submissions Review',  'slug' => 'submissions-review', 'status' => 'private'),
        array('template' => 'page-wiki-editor.php',        'title' => 'Wiki Editor',         'slug' => 'wiki-editor',        'status' => 'publish'),
        array('template' => 'page-submit-legislation.php', 'title' => 'Submit Legislation',  'slug' => 'submit-legislation', 'status' => 'publish'),
        array('template' => 'page-submit-lawsuit.php',     'title' => 'Submit a Lawsuit',    'slug' => 'submit-lawsuit',     'status' => 'publish'),
        array('template' => 'page-memorial.php',           'title' => 'In Loving Memory',    'slug' => 'in-loving-memory',   'status' => 'publish'),
    );
}

/**
 * Ensure each tool Page exists with the correct template assigned.
 *
 * Order of preference, per tool:
 *   1. If any Page already uses the template, leave it alone.
 *   2. If a Page with the intended slug exists, just assign the template.
 *   3. Otherwise create the Page and assign the template.
 *
 * Safe to run repeatedly.
 */
function kop_ensure_tool_pages() {
    foreach (kop_tool_page_specs() as $spec) {
        $template_value = 'templates/' . $spec['template'];

        // 1. A page already uses this template anywhere — nothing to do.
        if (kop_find_template_page_url($spec['template'])) {
            continue;
        }

        // 2. A page with the intended slug exists — assign the template to it.
        $existing = get_page_by_path($spec['slug']);
        if ($existing) {
            update_post_meta($existing->ID, '_wp_page_template', $template_value);
            continue;
        }

        // 3. Create the page.
        $page_id = wp_insert_post(array(
            'post_title'   => $spec['title'],
            'post_name'    => $spec['slug'],
            'post_status'  => $spec['status'],
            'post_type'    => 'page',
            'post_content' => '',
        ));
        if ($page_id && !is_wp_error($page_id)) {
            update_post_meta($page_id, '_wp_page_template', $template_value);
        }
    }
}
add_action('after_switch_theme', 'kop_ensure_tool_pages');

/**
 * The theme is already active, so after_switch_theme won't fire again. Run the
 * ensure step once (version-flagged) on the next admin load so existing sites
 * get the pages without needing to re-activate the theme.
 */
function kop_maybe_ensure_tool_pages() {
    $version = '3';
    if (get_option('kop_tool_pages_ensured') === $version) {
        return;
    }
    kop_ensure_tool_pages();
    update_option('kop_tool_pages_ensured', $version);
}
add_action('admin_init', 'kop_maybe_ensure_tool_pages');
/**
 * Pages whose template is fixed by slug. Unlike kop_tool_page_specs(), several
 * pages can share one template here (state and country hubs), so nothing is
 * skipped because "a page already uses this template". Pages that do not exist
 * are left alone; this list never creates pages.
 *
 * Slug => template file inside templates/ (a page), or
 * slug => array('template' => file, 'post_type' => 'post') for a post that
 * uses a "Template Post Type: post" template.
 */
function kop_template_assignments() {
    return array(
        // Facility Profile posts (phase 2). Hyde School was the pilot; the
        // rest followed once its render diff against the stock layout passed.
        'hyde'                       => array('template' => 'single-facility-profile.php', 'post_type' => 'post'),
        'long-creek'                 => array('template' => 'single-facility-profile.php', 'post_type' => 'post'),
        'the-buckeye-ranch'          => array('template' => 'single-facility-profile.php', 'post_type' => 'post'),
        'asheville-academy'          => array('template' => 'single-facility-profile.php', 'post_type' => 'post'),
        'robert-land-academy'        => array('template' => 'single-facility-profile.php', 'post_type' => 'post'),
        'sweetser'                   => array('template' => 'single-facility-profile.php', 'post_type' => 'post'),
        'me-new-horizons'            => array('template' => 'single-facility-profile.php', 'post_type' => 'post'),
        'good-will-hinckley-roundel' => array('template' => 'single-facility-profile.php', 'post_type' => 'post'),
        'elan'                       => array('template' => 'single-facility-profile.php', 'post_type' => 'post'),
        'summit-achievement'         => array('template' => 'single-facility-profile.php', 'post_type' => 'post'),
        'pathway-family-center'      => array('template' => 'single-facility-profile.php', 'post_type' => 'post'),

        // Hand-written facility pages (phase 4): same profile treatment, content untouched.
        'elevations-rtc'             => array('template' => 'single-facility-profile.php', 'post_type' => 'page'),
        'havenwood-academy'          => array('template' => 'single-facility-profile.php', 'post_type' => 'page'),
        'the-ridge-rtc-maine'        => array('template' => 'single-facility-profile.php', 'post_type' => 'page'),

        // Per-organization document libraries (phase 4). Three of these relied
        // on the inactive CatFolders block and rendered nothing.
        'document-library-discovery-ranch'     => 'page-document-folder.php',
        'document-library-hidden-lake-academy' => 'page-document-folder.php',
        'document-library-montana-academy'     => 'page-document-folder.php',
        'document-library-natsap'              => 'page-document-folder.php',
        'document-library-newport-healthcare'  => 'page-document-folder.php',

        // Navigation hub pages (phase 5): curated editor content plus live modules.
        'history'              => 'page-hub.php',
        'survivors'            => 'page-hub.php',
        'researchreports'      => 'page-hub.php',
        'families'             => 'page-hub.php',
        'where-are-the-kids'   => 'page-hub.php',
        'advocates'            => 'page-hub.php',
        'journalists'          => 'page-hub.php',
        'support'              => 'page-hub.php',
        'volunteer'            => 'page-hub.php',
        'law-policy'           => 'page-hub.php',
        'resources'            => 'page-hub.php',

        'wyoming'        => 'page-state.php',
        'australia'      => 'page-country.php',
        'canada'         => 'page-country.php',
        'costa-rica'     => 'page-country.php',
        'fiji'           => 'page-country.php',
        'jamaica'        => 'page-country.php',
        'mexico'         => 'page-country.php',
        'samoa'          => 'page-country.php',
        'united-kingdom' => 'page-country.php',
    );
}

/**
 * Slug renames applied before the assignments above. The country template
 * derives the country name from the slug, so "uk" would resolve to "Uk" and
 * match the wrong facilities. WordPress records the old slug and 301s it.
 *
 * Old slug => new slug. Only applied when the old page exists and no page
 * already owns the new slug.
 */
function kop_slug_renames() {
    return array(
        'uk' => 'united-kingdom',
    );
}

/**
 * Pages to move to the trash: empty shells and test scaffolding that a
 * template-driven page has replaced (inc/redirects.php carries their 301s).
 * Trash, not delete, so anything here can be restored from wp-admin.
 *
 * Slug => post type.
 */
function kop_pages_to_trash() {
    return array(
        'test-scripts' => 'page', // inline copy of the facility report viewer
        'admin-tools'  => 'page', // five buttons; the KOP Tools menu lists every tool
        '405-2'        => 'page', // a heading reading "Kids Over Profits" and nothing else
    );
}

/**
 * Lawsuit records whose document_urls still point at the retired single-file
 * pages on the staging path. Replaced with the files themselves (or the live
 * page, for the Richardson panel opinion, which is four images). Applied only
 * while the stored value still contains "/staging/", so a later manual edit
 * in the lawsuit admin is never overwritten.
 *
 * lawsuits.id => array of URLs.
 */
function kop_lawsuit_document_fixes() {
    return array(
        // Sherman v. Trinity Teen Solutions. The complaint PDF
        // (gov.uscourts.wyd.56518.106.0) is missing from uploads; re-add it
        // in the lawsuit admin once re-uploaded.
        6  => array(
            'https://kidsoverprofits.org/wp-content/uploads/2024/08/Class-Action-Approval-TTS-TCR.pdf',
        ),
        // Anonymous v. Hyde School at South Woodstock
        7  => array(
            'https://kidsoverprofits.org/wp-content/uploads/2024/11/doe-v-hyde-woodstock-complaint.pdf',
            'https://kidsoverprofits.org/wp-content/uploads/2024/11/doe-v-hyde-woodstock-amended-complaint-2.pdf',
        ),
        // Richardson v. Elevations RTC
        10 => array(
            'https://kidsoverprofits.org/wp-content/uploads/2024/08/Ryan-Faust_Elevations-Lawsuit-2024.pdf',
            'https://kidsoverprofits.org/richardson-v-elevations-rtc-prelitigation-panel-opinion/',
        ),
        // Shiver v. Southstone Behavioral Health and Acadia Healthcare
        11 => array(
            'https://kidsoverprofits.org/wp-content/uploads/2024/08/Shiver-v.-Southstone-Complaint.pdf',
            'https://kidsoverprofits.org/wp-content/uploads/2024/08/Shiver-v.-Southstone-Summons.pdf',
            'https://kidsoverprofits.org/wp-content/uploads/2024/08/SouthstoneDefaultMotion.pdf',
        ),
    );
}

function kop_apply_lawsuit_document_fixes() {
    $fixed  = array();
    $config = get_stylesheet_directory() . '/api/config.php';
    if (!file_exists($config)) {
        return $fixed;
    }
    require_once $config;
    if (!isset($pdo) && isset($GLOBALS['pdo'])) {
        $pdo = $GLOBALS['pdo']; // config.php was already loaded at global scope
    }
    if (!isset($pdo) || !($pdo instanceof PDO)) {
        return $fixed;
    }
    try {
        $read  = $pdo->prepare('SELECT document_urls FROM lawsuits WHERE id = ?');
        $write = $pdo->prepare('UPDATE lawsuits SET document_urls = ? WHERE id = ?');
        foreach (kop_lawsuit_document_fixes() as $id => $urls) {
            $read->execute(array((int) $id));
            $current = (string) $read->fetchColumn();
            if ($current === '' || strpos($current, '/staging/') === false) {
                continue;
            }
            $write->execute(array(wp_json_encode(array_values($urls)), (int) $id));
            $fixed[] = (int) $id;
        }
    } catch (Throwable $e) {
        // Leave the records alone; the lawsuit admin can fix them by hand.
    }
    return $fixed;
}

/**
 * Apply kop_slug_renames(), kop_template_assignments(), then
 * kop_pages_to_trash(). Idempotent. Returns a summary array for manual runs.
 */
function kop_apply_template_assignments() {
    $summary = array('renamed' => array(), 'assigned' => array(), 'missing' => array(), 'trashed' => array());

    $summary['lawsuit_docs'] = kop_apply_lawsuit_document_fixes();

    foreach (kop_pages_to_trash() as $slug => $post_type) {
        $page = get_page_by_path($slug, OBJECT, $post_type);
        if ($page && $page->post_status !== 'trash' && wp_trash_post($page->ID)) {
            $summary['trashed'][] = $slug;
        }
    }

    foreach (kop_slug_renames() as $old => $new) {
        $page = get_page_by_path($old, OBJECT, 'page');
        if (!$page || get_page_by_path($new, OBJECT, 'page')) {
            continue;
        }
        $result = wp_update_post(array('ID' => $page->ID, 'post_name' => $new), true);
        if (!is_wp_error($result)) {
            // wp_update_post stores _wp_old_slug when the slug changes, so the
            // old URL keeps working via wp_old_slug_redirect(). Record it
            // explicitly too in case the post_name was already sanitized.
            add_post_meta($page->ID, '_wp_old_slug', $old);
            $summary['renamed'][] = $old . ' -> ' . $new;
        }
    }

    foreach (kop_template_assignments() as $slug => $spec) {
        $template  = is_array($spec) ? $spec['template'] : $spec;
        $post_type = (is_array($spec) && !empty($spec['post_type'])) ? $spec['post_type'] : 'page';
        $page = get_page_by_path($slug, OBJECT, $post_type);
        if (!$page) {
            $summary['missing'][] = $slug;
            continue;
        }
        $template_value = 'templates/' . $template;
        if (get_post_meta($page->ID, '_wp_page_template', true) === $template_value) {
            continue;
        }
        update_post_meta($page->ID, '_wp_page_template', $template_value);
        $summary['assigned'][] = $slug . ' => ' . $template;
    }

    return $summary;
}

/**
 * Run the assignments once per version on any request, so a deploy takes
 * effect without waiting for someone to open wp-admin. Bump the version when
 * the lists above change.
 */
function kop_maybe_apply_template_assignments() {
    $version = '6';
    if (get_option('kop_template_assignments_applied') === $version) {
        return;
    }
    kop_apply_template_assignments();
    update_option('kop_template_assignments_applied', $version);
}
add_action('init', 'kop_maybe_apply_template_assignments', 20);
