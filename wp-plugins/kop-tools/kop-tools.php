<?php
/**
 * Plugin Name: KOP Tools
 * Description: One menu for every Kids Over Profits admin tool: the admin pages (Submissions Review, Data Manager, Wiki Editor, ...), the theme's wp-admin screens (Bug Reports, Glossary Editor, ...) and the self-contained tools in the child theme's api/ directory. The wp-admin sidebar, the admin bar dropdown and the dashboard all read the same registry.
 * Version: 2.5.0
 * Author: Kids Over Profits
 * License: GPL-2.0-or-later
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The tool registry: category => list of tools. Every tool has a title, a
 * one-line desc and a type:
 *
 *   'wp-page' A WordPress page with an admin template assigned. 'template' is
 *             the template file; the URL is resolved live, through the theme's
 *             kop_find_template_page_url() when it is loaded so the menu,
 *             the dashboard and the notification emails pick the same page
 *             when several pages share one template.
 *   'screen'  A wp-admin screen the theme registers (admin.php?page=<screen>).
 *             'requires' names the function or class that renders it; the
 *             entry is hidden when that is not loaded.
 *   'page'    A self-contained HTML tool in the theme's api/ directory
 *             ('path', relative to the active child theme; optional 'query'
 *             for the tools that need ?run=1 and default to a dry run).
 *             Missing files are greyed out on the dashboard and left out of
 *             the menus.
 *   'action'  A POST-only api/ endpoint, run from the dashboard's Run button.
 *
 * Every tool enforces its own admin check; the registry only makes them
 * findable. Extend or override it via the `kop_tools_registry` filter.
 */
function kop_tools_registry() {
    $tools = array(
        'Review queue' => array(
            array(
                'title'    => 'Submissions Review',
                'desc'     => 'The Review Inbox: every approval queue in one place (news, data, wiki, lawsuits, legislation, the news scans, imports, merges, map years and renames, inspection findings, conflicts...), with Recently done and Undo, Later, Preview and volunteer recommendations.',
                'type'     => 'wp-page',
                'template' => 'page-admin-submissions.php',
            ),
            array(
                'title'    => 'Anonymous Docs',
                'desc'     => 'Documents sent through the anonymous portal (sealed uploads).',
                'type'     => 'screen',
                'screen'   => 'anonymous-docs',
                'requires' => 'AnonymousDocPortal',
            ),
            array(
                'title'    => 'Bug Reports',
                'desc'     => 'Triage reports sent from the on-site bug widget.',
                'type'     => 'screen',
                'screen'   => 'kop-bug-reports',
                'requires' => 'kop_render_bug_reports_page',
            ),
            array(
                'title'    => 'Glossary Feedback',
                'desc'     => 'Reader notes from the glossary\'s "My facility used this too" and "Suggest a correction" buttons.',
                'type'     => 'screen',
                'screen'   => 'kop-glossary-feedback',
                'requires' => 'kop_render_glossary_feedback_page',
            ),
            array(
                'title'    => 'Closure Reports',
                'desc'     => 'Facility closures the hourly news scan found. Confirming one sets the facility\'s status on its page and on the network map.',
                'type'     => 'screen',
                'screen'   => 'kop-closure-reports',
                'requires' => 'kop_render_closure_reports_page',
            ),
            array(
                'title'    => 'Facilities from News',
                'desc'     => 'Programs named in the news with no record: the ones the hourly scan created or matched, and the possible duplicates it held back.',
                'type'     => 'screen',
                'screen'   => 'kop-facilities-from-news',
                'requires' => 'kop_render_facilities_from_news_page',
            ),
            array(
                'title'    => 'Woodbury Reports',
                'desc'     => 'File the Woodbury Reports pages the scan cut for each program into its "Woodbury Reports Mentions" folder.',
                'type'     => 'screen',
                'screen'   => 'kop-woodbury-reports',
                'requires' => 'kop_render_woodbury_page',
            ),
            array(
                'title'    => 'Woodbury Facts',
                'desc'     => 'Staff, careers, incidents, openings, closings, names and owners read from every Woodbury Reports issue (HEAL and wiki facts too), with a tab for young adult programs; adding one is live at once, with Undo.',
                'type'     => 'screen',
                'screen'   => 'kop-woodbury-facts',
                'requires' => 'kop_render_woodbury_facts_page',
            ),
            array(
                'title'    => 'Fornits',
                'desc'     => 'Staff, incidents, survivor accounts and leads read from the Fornits survivor forum, with exact Undo; also checks the AI keys.',
                'type'     => 'screen',
                'screen'   => 'kop-fornits',
                'requires' => 'kop_render_fornits_page',
            ),
            array(
                'title'    => 'Drive Docs',
                'desc'     => 'Links from the Google Docs export, HEAL, the wiki, SCIAD NET and the data audit: news, court records and bills to their queues, the rest onto facility records.',
                'type'     => 'screen',
                'screen'   => 'kop-drive-docs',
                'requires' => 'kop_render_drive_docs_page',
            ),
            array(
                'title'    => 'State Lists',
                'desc'     => 'Facility lists states publish (MO registry, KY, AK, LA, IN, KS/MS PRTF) matched to our records: link, create, not TTI, with Undo.',
                'type'     => 'screen',
                'screen'   => 'kop-state-lists',
                'requires' => 'kop_sl_page',
            ),
            array(
                'title'    => 'Volunteer Reviewers',
                'desc'     => 'Personal review links for volunteers (no account): make or turn off a link and pick the queues each one sees.',
                'type'     => 'screen',
                'screen'   => 'kop-volunteer-reviewers',
                'requires' => 'kop_vol_admin_page',
            ),
        ),
        'Records & editors' => array(
            array(
                'title'    => 'Data Manager',
                'desc'     => 'Every record in one list (facilities, program homes, companies, referrers, transporters, providers, marketing companies, trade organizations, young adult programs, Indian boarding schools, people, news, lawsuits, bills, merged and converted records) with filters; merge, reclassify and reassign, with Undo.',
                'type'     => 'wp-page',
                'template' => 'page-admin-data-manager.php',
            ),
            array(
                'title'    => 'Admin Data Form',
                'desc'     => 'Edit parent company, operator, location, referrer, transporter, mental health provider, marketing company and trade organization records directly.',
                'type'     => 'wp-page',
                'template' => 'page-admin-data.php',
            ),
            array(
                'title'    => 'Wiki Editor',
                'desc'     => 'Write and edit facility wiki entries.',
                'type'     => 'wp-page',
                'template' => 'page-wiki-editor.php',
            ),
            array(
                'title'    => 'News Processor',
                'desc'     => 'Submit and AI-process articles into the news feed.',
                'type'     => 'wp-page',
                'template' => 'page-news-processor.php',
            ),
            array(
                'title'    => 'Lawsuit Admin',
                'desc'     => 'Add, edit and publish lawsuit records.',
                'type'     => 'wp-page',
                'template' => 'page-admin-lawsuits.php',
            ),
            array(
                'title'    => 'Legislation Admin',
                'desc'     => 'Add, edit and publish legislation records.',
                'type'     => 'wp-page',
                'template' => 'page-admin-legislation.php',
            ),
            array(
                'title'    => 'Volunteer Admin',
                'desc'     => 'Manage the volunteer projects listed on the public Volunteer page.',
                'type'     => 'wp-page',
                'template' => 'page-admin-volunteers.php',
            ),
            array(
                'title'    => 'Glossary Editor',
                'desc'     => 'Edit TTI glossary entries, sections and introduction; changes are live at once, with Undo.',
                'type'     => 'screen',
                'screen'   => 'kop-glossary-editor',
                'requires' => 'kop_render_glossary_editor_page',
            ),
            array(
                'title'    => 'Page Text',
                'desc'     => 'Edit the words of pages whose text lives in js/data/pages/ (Indian Boarding Schools, FAQ, the related industries pages); drafts stay private until published.',
                'type'     => 'screen',
                'screen'   => 'kop-page-text',
                'requires' => 'kop_render_page_text_editor',
            ),
            array(
                'title'    => 'Proper Names',
                'desc'     => 'Words and names the site always capitalises as written (NATSAP, WWASP, MacLaren, LLC...) wherever it changes the case of text; one per line, Undo for the last save.',
                'type'     => 'screen',
                'screen'   => 'kop-proper-names',
                'requires' => 'kop_proper_names_page',
            ),
            array(
                'title'    => 'Merge Duplicates',
                'desc'     => 'Facility records stored twice, found automatically and merged with one click (documents, links and folders follow); renamed programs merge only as a rename, each name keeping its years. Exact Undo from the Merged tab.',
                'type'     => 'screen',
                'screen'   => 'kop-merge-duplicates',
                'requires' => 'kop_fmerge_page',
            ),
            array(
                'title'    => 'People',
                'desc'     => 'One id per person named anywhere in the records (staff lists, owners, consultants, map people, lawsuits, journalists), in its pool (industry, harmed, press); where each is named, and separate a name that is two people.',
                'type'     => 'screen',
                'screen'   => 'kop-people',
                'requires' => 'kop_people_admin_page',
            ),
            array(
                'title'    => 'Merge People',
                'desc'     => 'One person under two spellings or names, found automatically and merged with one click; exact Undo.',
                'type'     => 'screen',
                'screen'   => 'kop-merge-people',
                'requires' => 'kop_pmerge_page',
            ),
            array(
                'title'    => 'Program Homes',
                'desc'     => 'Tie the licensed homes and cottages of one program to its record, and convert parent companies that are one program; Undo.',
                'type'     => 'screen',
                'screen'   => 'kop-program-homes',
                'requires' => 'kop_program_homes_page',
            ),
            array(
                'title'    => 'Young Adult Programs',
                'desc'     => 'Programs for people 18 and older, kept apart from the TTI facility records: add or edit one, its Woodbury facts, and whether /young-adult-programs/ lists it.',
                'type'     => 'screen',
                'screen'   => 'kop-young-adult-programs',
                'requires' => 'kop_render_ya_admin',
            ),
            array(
                'title'    => 'Indigenous Schools',
                'desc'     => 'Indian boarding, residential and mission schools and their articles, kept out of the facility records.',
                'type'     => 'screen',
                'screen'   => 'kop-indigenous-schools',
                'requires' => 'kop_render_ischools_admin',
            ),
            array(
                'title' => 'Manage Addresses',
                'desc'  => 'Give each physical campus a stable address ID and record which facilities stood there, independent of the names on the sign. Reads the facility records; re-seed after address edits.',
                'type'  => 'page',
                'path'  => 'api/manage-addresses.php',
            ),
            array(
                'title' => 'Journalists (internal)',
                'desc'  => 'Internal contact list of journalists covering the TTI, extracted from news bylines: contact details, outreach status, notes, CSV export. Never shown publicly.',
                'type'  => 'page',
                'path'  => 'api/manage-journalists.php',
            ),
        ),
        'News' => array(
            array(
                'title'    => 'Essential Reporting',
                'desc'     => 'Articles picked as especially good journalism, each with a note on why: find and pick, edit notes, Recent changes with Undo. Shown on /essential-reporting/, the news and home pages and facility pages; nominations wait in the Review Inbox (Nominated).',
                'type'     => 'screen',
                'screen'   => 'kop-essential-reporting',
                'requires' => 'kop_render_feat_admin',
            ),
            array(
                'title' => 'Manage Story Arcs',
                'desc'  => 'Create and edit the ongoing story arcs featured on the news feed; attach or detach member articles and scan the archive for candidates.',
                'type'  => 'page',
                'path'  => 'api/manage-story-arcs.php',
            ),
            array(
                'title' => 'Manage Duplicate Articles',
                'desc'  => 'Scan news_submissions for the same article recorded more than once and clean up: keep one copy, delete the rest.',
                'type'  => 'page',
                'path'  => 'api/manage-duplicate-articles.php',
            ),
            array(
                'title' => 'Normalize News Tags',
                'desc'  => 'Rewrite stored news tags to the canonical vocabulary. Dry run on open; add ?apply=1 to write.',
                'type'  => 'page',
                'path'  => 'api/normalize-news-tags.php',
            ),
            array(
                'title' => 'Rebuild Story Groups',
                'desc'  => 'Re-cluster every news submission into cross-outlet story groups. Idempotent repair; day-to-day grouping already happens on save.',
                'type'  => 'action',
                'path'  => 'api/rebuild-news-story-groups.php',
            ),
        ),
        'Network map' => array(
            array(
                'title'    => 'Map Years',
                'desc'     => 'Review researched operating years for map programs; accepted years go on the map at once.',
                'type'     => 'screen',
                'screen'   => 'kop-network-years',
                'requires' => 'kop_network_years_page',
            ),
            array(
                'title'    => 'Map Renames',
                'desc'     => 'Set the year a program was renamed (or swap a pair\'s order), which splits the two names\' years on the map and the facility page at once.',
                'type'     => 'screen',
                'screen'   => 'kop-network-renames',
                'requires' => 'kop_network_renames_page',
            ),
            array(
                'title'    => 'Map Rebuild',
                'desc'     => 'Status of the hourly network map rebuild (GitHub workflow), with a Rebuild now button.',
                'type'     => 'screen',
                'screen'   => 'kop-network-rebuild',
                'requires' => 'kop_network_rebuild_page',
            ),
        ),
        'Inspections' => array(
            array(
                'title' => 'Manage Featured Inspections',
                'desc'  => 'Curate the "inspections that demand attention" block on the home page: feature scraped inspection reports with a one-line note.',
                'type'  => 'page',
                'path'  => 'api/manage-featured-inspections.php',
            ),
            array(
                'title' => 'Review Inspection Highlights',
                'desc'  => 'Approve or reject the severe findings the parser pulled out of inspection reports; approved ones fill the home page and hub cards after the featured reports.',
                'type'  => 'page',
                'path'  => 'api/review-inspection-highlights.php',
            ),
            array(
                'title' => 'Scan Inspection Highlights',
                'desc'  => 'Scan reports not yet seen for serious findings and queue them for review. Dry run on open; ?apply=1 saves one batch.',
                'type'  => 'page',
                'path'  => 'api/scan-inspection-highlights.php',
            ),
            array(
                'title'    => 'Inspection Links',
                'desc'     => 'Tie facility records to state inspection rows filed under a different name (a program with homes links as one unit), or link any row by hand.',
                'type'     => 'screen',
                'screen'   => 'kop-inspection-links',
                'requires' => 'kop_render_inspection_links_page',
            ),
            array(
                'title'    => 'Inspection Rankings',
                'desc'     => 'Worst companies, facilities and states by approved serious findings and the states\' own verdicts.',
                'type'     => 'screen',
                'screen'   => 'kop-inspection-rankings',
                'requires' => 'kop_render_inspection_rankings_page',
            ),
        ),
        'Media & folders' => array(
            array(
                'title'    => 'Document Types',
                'desc'     => 'What kind each document is (handbook, inspection report, enrollment, news clipping, legal filing...): counts, tag many at once, run the rules, recent changes with Undo. Readers compare them at /documents/.',
                'type'     => 'screen',
                'screen'   => 'kop-document-types',
                'requires' => 'kop_render_dt_admin',
            ),
            array(
                'title' => 'Sort Media',
                'desc'  => 'Re-file mis-filed documents: move or copy attachments between FileBird folders, including the theme\'s extra folder tags.',
                'type'  => 'page',
                'path'  => 'api/sort-media.php',
            ),
            array(
                'title' => 'Link Folders',
                'desc'  => 'Link FileBird folders for renames/rebrands, or mark suggested pairs as alternate names without merging their document feeds.',
                'type'  => 'page',
                'path'  => 'api/link-folders.php',
            ),
            array(
                'title' => 'Organize Uncategorized Media',
                'desc'  => 'List attachments that are in no FileBird folder and file them, with a suggested destination matched from the filename.',
                'type'  => 'page',
                'path'  => 'api/organize-uncategorized-media.php',
            ),
            array(
                'title' => 'Dedupe Media',
                'desc'  => 'Clear duplicate tiles from the document libraries: imported PDF preview images (-pdf.jpg and .pdf.png) and byte-identical copies. Each tab previews first.',
                'type'  => 'page',
                'path'  => 'api/dedupe-media.php',
                'sidebar' => true,
            ),
            array(
                'title' => 'Regenerate PDF Previews',
                'desc'  => 'Render first-page covers for PDFs that have none. Dry run on open; ?apply=1 does one batch.',
                'type'  => 'page',
                'path'  => 'api/regenerate-pdf-previews.php',
            ),
            array(
                'title' => 'Propose Research Facility Tags',
                'desc'  => 'Read the Research & Reports library and propose which facilities each document is about. Writes a review file only; ?apply=1 saves it.',
                'type'  => 'page',
                'path'  => 'api/propose-research-facility-tags.php',
            ),
        ),
        'Maintenance & repairs' => array(
            array(
                'title' => 'Backfill FileBird Folders',
                'desc'  => 'Create FileBird folders for operators and the facilities they run that have no folder of their name anywhere. Opens as a dry run.',
                'type'  => 'page',
                'path'  => 'api/backfill-filebird-folders.php',
                'query' => 'run=1&dry=1',
            ),
            array(
                'title' => 'Retitle From Content',
                'desc'  => 'AI-title unclear attachments by reading the document (PDF/DOCX/TXT/images, vision OCR for scans): review, edit, and apply new titles. Files and URLs are untouched.',
                'type'  => 'page',
                'path'  => 'api/retitle-from-content.php',
            ),
            array(
                'title' => 'Standardize Facilities',
                'desc'  => 'Re-save every facility record through the standard shapes (staff, links, vocabularies). Opens as a dry run.',
                'type'  => 'page',
                'path'  => 'api/standardize-facilities.php',
                'query' => 'run=1',
            ),
            array(
                'title' => 'Clean Up Wiki Submissions',
                'desc'  => 'Purge rejected/deleted wiki submissions and collapse duplicates (dry-run first).',
                'type'  => 'page',
                'path'  => 'api/cleanup-wiki-submissions.php',
            ),
            array(
                'title' => 'Database Schema',
                'desc'  => 'Inspect every table and its columns (read-only JSON).',
                'type'  => 'page',
                'path'  => 'api/get-database-schema.php',
            ),
        ),
    );
    return apply_filters('kop_tools_registry', $tools);
}

/**
 * Categories whose tools are listed in the wp-admin sidebar: every one, so
 * every tool is one click away (the owner's choice, 2026-10-13). The flyout
 * scrolls when it is taller than the screen (kop_tools_sidebar_css()).
 */
function kop_tools_sidebar_categories() {
    return apply_filters('kop_tools_sidebar_categories', array_keys(kop_tools_registry()));
}

/** The dashboard row of a tool: actions in the sidebar link to it, where their Run button lives. */
function kop_tools_row_id(array $tool) {
    return 'kop-tool-' . sanitize_title($tool['title']);
}

/** A sidebar flyout longer than the window scrolls instead of running off the bottom. */
add_action('admin_head', 'kop_tools_sidebar_css');
function kop_tools_sidebar_css() {
    echo '<style id="kop-tools-sidebar">#adminmenu #toplevel_page_kop-tools.wp-not-current-submenu .wp-submenu'
       . '{max-height:calc(100vh - 48px);overflow-y:auto}</style>';
}

/**
 * Page URL for a template. Defers to the theme so every link on the site
 * agrees on which page a shared template means.
 */
function kop_tools_template_url($template) {
    if (function_exists('kop_find_template_page_url')) {
        return (string) kop_find_template_page_url($template);
    }
    $pages = get_posts(array(
        'post_type'      => 'page',
        'post_status'    => array('publish', 'private'),
        'posts_per_page' => 1,
        'orderby'        => 'ID',
        'order'          => 'ASC',
        'meta_query'     => array(array(
            'key'     => '_wp_page_template',
            'value'   => array($template, 'templates/' . $template),
            'compare' => 'IN',
        )),
    ));
    return $pages ? (string) get_permalink($pages[0]) : '';
}

/**
 * Where one tool lives right now. Returns:
 *   url       where to send the admin ('' when the tool is not available)
 *   available false when the file, page or screen is missing on this site
 *   external  true when the link leaves wp-admin (opened in a new tab from
 *             the dashboard and the admin bar)
 *   missing   why it is unavailable, for the dashboard
 */
function kop_tools_resolve(array $tool) {
    $type = isset($tool['type']) ? $tool['type'] : 'page';
    if ($type === 'wp-page') {
        $url = kop_tools_template_url($tool['template']);
        return array(
            'url'       => $url,
            'available' => $url !== '',
            'external'  => true,
            'missing'   => 'no page uses the ' . $tool['template'] . ' template',
        );
    }
    if ($type === 'screen') {
        $req = isset($tool['requires']) ? $tool['requires'] : '';
        $ok = $req === '' || function_exists($req) || class_exists($req);
        return array(
            'url'       => $ok ? admin_url('admin.php?page=' . $tool['screen']) : '',
            'available' => $ok,
            'external'  => false,
            'missing'   => 'screen not loaded by the theme',
        );
    }
    $exists = file_exists(trailingslashit(get_stylesheet_directory()) . $tool['path']);
    $url = trailingslashit(get_stylesheet_directory_uri()) . $tool['path'];
    if (!empty($tool['query'])) {
        $url .= '?' . $tool['query'];
    }
    return array(
        'url'       => $exists ? $url : '',
        'available' => $exists,
        'external'  => $type !== 'action',
        'missing'   => 'not installed',
    );
}

/**
 * Sidebar menu: All Tools, then the sidebar categories' tools in registry
 * order. The theme registers its own wp-admin screens (Bug Reports,
 * Glossary Editor, Glossary Feedback) under this parent;
 * kop_tools_order_sidebar() then sorts the whole submenu into registry order.
 * Full URLs as submenu slugs are treated by WordPress as external links.
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

    $registry = kop_tools_registry();
    $sidebar = kop_tools_sidebar_categories();
    foreach ($registry as $category => $tools) {
        foreach ($tools as $tool) {
            if (!in_array($category, $sidebar, true) && empty($tool['sidebar'])) {
                continue;
            }
            $type = isset($tool['type']) ? $tool['type'] : 'page';
            if ($type === 'screen') {
                continue;   // registered by the theme under this menu itself
            }
            $where = kop_tools_resolve($tool);
            if ($type === 'action') {
                // No page of its own: the entry opens the dashboard at its Run button.
                if ($where['available']) {
                    add_submenu_page('kop-tools', $tool['title'], $tool['title'] . ' (run)', 'manage_options',
                        admin_url('admin.php?page=kop-tools#' . kop_tools_row_id($tool)), '');
                }
                continue;
            }
            if ($where['available']) {
                add_submenu_page('kop-tools', $tool['title'], $tool['title'], 'manage_options', $where['url'], '');
            }
        }
    }
});

/**
 * Put the KOP Tools submenu in registry order once every screen is on it.
 * Screens from categories outside the sidebar list stay, at the end.
 */
function kop_tools_order_sidebar() {
    global $submenu;
    if (empty($submenu['kop-tools'])) {
        return;
    }
    $rank = array('kop-tools' => 0);
    $i = 1;
    foreach (kop_tools_registry() as $tools) {
        foreach ($tools as $tool) {
            $type = isset($tool['type']) ? $tool['type'] : 'page';
            $key = $type === 'screen' ? $tool['screen']
                : ($type === 'action' ? admin_url('admin.php?page=kop-tools#' . kop_tools_row_id($tool)) : kop_tools_resolve($tool)['url']);
            if ($key !== '' && !isset($rank[$key])) {
                $rank[$key] = $i;
            }
            $i++;
        }
    }
    $items = array_values($submenu['kop-tools']);
    $order = array();
    foreach ($items as $pos => $item) {
        $order[$pos] = isset($rank[$item[2]]) ? $rank[$item[2]] : 10000 + $pos;
    }
    asort($order);
    $sorted = array();
    foreach (array_keys($order) as $pos) {
        $sorted[] = $items[$pos];
    }
    $submenu['kop-tools'] = $sorted;
}
add_action('admin_menu', 'kop_tools_order_sidebar', 999);

/**
 * Admin bar dropdown (wp-admin and front end): every available tool, grouped
 * by category. Action tools have no GET page, so they link to the dashboard
 * where their Run button lives.
 */
add_action('admin_bar_menu', function ($wp_admin_bar) {
    if (!current_user_can('manage_options')) {
        return;
    }
    $dashboard = admin_url('admin.php?page=kop-tools');

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

    foreach (kop_tools_registry() as $category => $tools) {
        $cat_id = 'kop-tools-cat-' . sanitize_title($category);
        $added_category = false;
        foreach ($tools as $tool) {
            $where = kop_tools_resolve($tool);
            if (!$where['available']) {
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
            $is_action = isset($tool['type']) && $tool['type'] === 'action';
            $wp_admin_bar->add_node(array(
                'parent' => $cat_id,
                'id'     => 'kop-tools-' . sanitize_title($tool['title']),
                'title'  => $tool['title'] . ($is_action ? ' (run from dashboard)' : ''),
                'href'   => $is_action ? $dashboard : $where['url'],
                'meta'   => $where['external'] ? array('target' => '_blank', 'rel' => 'noopener') : array(),
            ));
        }
    }
}, 90);

function kop_tools_render_dashboard() {
    if (!current_user_can('manage_options')) {
        wp_die('Not authorized.');
    }

    echo '<div class="wrap"><h1>KOP Tools</h1>';
    echo '<p>Every Kids Over Profits admin tool on this site (theme <code>' . esc_html(get_stylesheet()) . '</code>). '
       . 'The sidebar, the KOP Tools dropdown in the admin bar and the All tools tab of the Review Inbox list the same tools. '
       . 'Pages and api tools open in a new tab; each enforces its own admin login check. '
       . 'Greyed-out entries are registered here but missing on this site.</p>';

    foreach (kop_tools_registry() as $category => $tools) {
        echo '<h2>' . esc_html($category) . '</h2>';
        echo '<table class="widefat striped" style="max-width:960px;margin-bottom:18px"><tbody>';
        foreach ($tools as $tool) {
            $type = isset($tool['type']) ? $tool['type'] : 'page';
            $where = kop_tools_resolve($tool);
            if ($type === 'wp-page') {
                $where_label = $where['available'] ? wp_make_link_relative($where['url']) : $tool['template'];
            } elseif ($type === 'screen') {
                $where_label = 'admin.php?page=' . $tool['screen'];
            } else {
                $where_label = $tool['path'] . (!empty($tool['query']) ? '?' . $tool['query'] : '');
            }
            echo '<tr id="' . esc_attr(kop_tools_row_id($tool)) . '"' . ($where['available'] ? '' : ' style="opacity:.45"') . '>';
            echo '<td style="width:240px"><strong>' . esc_html($tool['title']) . '</strong><br>'
               . '<code style="font-size:11px">' . esc_html($where_label) . '</code></td>';
            echo '<td>' . esc_html($tool['desc']) . '</td>';
            echo '<td style="width:130px;text-align:right">';
            if (!$where['available']) {
                echo '<em>' . esc_html($where['missing']) . '</em>';
            } elseif ($type === 'action') {
                echo '<button type="button" class="button button-secondary kop-tools-run" data-url="'
                   . esc_url($where['url']) . '" data-title="' . esc_attr($tool['title']) . '">Run</button>';
            } else {
                echo '<a class="button button-primary"'
                   . ($where['external'] ? ' target="_blank" rel="noopener"' : '')
                   . ' href="' . esc_url($where['url']) . '">Open</a>';
            }
            echo '</td></tr>';
        }
        echo '</tbody></table>';
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
