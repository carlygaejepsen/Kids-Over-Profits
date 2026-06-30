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
    );
}

/**
 * Add a "KOP Data Tools" group to the wp-admin sidebar with links straight to
 * the front-end tool pages. WordPress treats a full URL passed as the submenu
 * slug as an external link, so these jump directly to the pages.
 */
function kop_register_data_tools_menu() {
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
    if (!current_user_can('manage_options')) {
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
    $version = '2';
    if (get_option('kop_tool_pages_ensured') === $version) {
        return;
    }
    kop_ensure_tool_pages();
    update_option('kop_tool_pages_ensured', $version);
}
add_action('admin_init', 'kop_maybe_ensure_tool_pages');