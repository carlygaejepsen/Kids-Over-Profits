<?php
/**
 * Hub pages for the menu's group labels.
 *
 * The top-level menu items that only hold others (Monitor, Learn More, Get
 * Involved, ...) were custom links to "#": a label that went nowhere. Each one
 * now gets a hub page (templates/page-hub.php) named after it, and the menu
 * item is turned into a link to that page, in every menu it appears in. The
 * page lists what sits under the label in the menu, read live from the menu,
 * so moving, adding or renaming an entry in wp-admin changes the hub at once.
 *
 * Each entry is a card: its menu label, and one line on what it is, from
 * (first found) the menu item's own Description field in wp-admin, the
 * page's excerpt, or kop_nav_hub_notes() below. A second-level item that has
 * entries under it becomes a heading over its own cards.
 *
 * What was changed is kept in the kop_nav_hubs option (each item's old URL),
 * and kop_nav_hubs_undo() puts the menu back. The pages are left in place.
 *
 * Runs once per version on init, and again whenever a menu is saved, so a
 * new group label added later gets its hub too.
 */

if (!defined('ABSPATH')) {
    exit;
}

/** Bump to run the pass again on the next request. */
function kop_nav_hubs_version() {
    return '1';
}

/** Hub slug => one sentence under the title (kop_hub_config() standfirst). */
function kop_nav_hub_standfirsts() {
    return array(
        'monitor'      => 'The records we keep on the troubled teen industry: the programs, the companies behind them, what inspectors and courts found, and who refers and transports children to them.',
        'learn-more'   => 'Where the troubled teen industry came from, the words it uses, the research on it, and the data behind this site.',
        'get-involved' => 'Report abuse, send us what you know, and help keep these records growing.',
    );
}

/**
 * Page slug (the last part of a link's path) => one line, for a menu entry
 * with no Description of its own and a page with no excerpt.
 */
function kop_nav_hub_notes() {
    return array(
        'start-here'                         => 'How to use this site: where everything is and how to read a record.',
        'faq'                                => 'Short answers to the questions people ask us most.',
        'tti-program-index'                  => 'Every program we have a record for, by parent company or by location.',
        'operator'                           => 'The companies that own and run the programs, largest first.',
        'network-map'                        => 'Who owns, staffs and refers to which programs, and how people moved between them.',
        'inspection-reports'                 => 'State licensing inspections, state by state, searchable by facility.',
        'severe-reports'                     => 'The most serious inspection findings we have reviewed, in one list.',
        'lawsuits'                           => 'Cases filed against programs, companies and staff.',
        'legislation'                        => 'Bills that would regulate the industry, and where they stand.',
        'legislative-efforts'                => 'Bills that would regulate the industry, and where they stand.',
        'law-policy'                         => 'Lawsuits and legislation, tracked through the courts and legislatures.',
        'tti-news-feed'                      => 'Reporting on the industry, newest first.',
        'in-loving-memory'                   => 'Young people who died in these programs.',
        'referrers-educational-consultants'  => 'The educational consultants who refer families to programs.',
        'youth-transport-companies'          => 'The services hired to take teens to programs, often at night.',
        'mental-health-providers'            => 'Hospitals, clinics and therapists outside the industry that refer to it or use its methods.',
        'where-are-the-kids'                 => 'Programs state by state and country by country.',
        'young-adult-programs'               => 'Programs for people 18 and older, kept apart from the youth programs.',
        'indian-boarding-schools'            => 'Indian boarding schools and residential schools, and the Indigenous-led groups working on them.',
        'history'                            => 'Where the industry came from and how it grew.',
        'glossary'                           => 'The words programs use, and what they mean in practice.',
        'researchreports'                    => 'Government audits, studies and investigative reports, with our notes.',
        'document-archive'                   => 'Brochures, handbooks, court records and other papers, by program.',
        'open-data'                          => 'Download our records as CSV and JSON files.',
        'resources'                          => 'Crisis lines, support groups, advocacy organizations and further reading.',
        'editorials'                         => 'Our opinion pieces on the industry.',
        'investigatory-spotlight'            => 'Our own investigations into programs and companies.',
        'reputation-management'              => 'How programs control what a worried parent finds online.',
        'survivors'                          => 'For people who went through a program.',
        'families'                           => 'For friends and family supporting a survivor.',
        'advocates'                          => 'Research, history and data for anyone organizing for change.',
        'journalists'                        => 'Sources and guidance for reporting on the industry.',
        'report-abuse'                       => 'The agencies that take reports about a program or therapist, in every state.',
        'tti-data-submission'                => 'Add a program, company, staff member or transporter, or correct a record.',
        'anon-submit'                        => 'Send documents anonymously; they are encrypted before they leave your browser.',
        'volunteer'                          => 'Ways to help with research, records and news.',
        'wiki-editor'                        => 'Improve the program pages of the r/troubledteens wiki.',
        'news-processor'                     => 'Send us news articles about the industry.',
        'submit-lawsuit'                     => 'Tell us about a case against a program or its staff.',
        'submit-legislation'                 => 'Tell us about a bill, hearing or vote we should follow.',
        'contact'                            => 'Questions, corrections and tips.',
        'donate'                             => 'Kids Over Profits is survivor-led and runs on donations.',
        'links'                              => 'Other sites and groups working on the industry.',
    );
}

/** True when a menu item's link goes nowhere ("#", empty, or this site's home + "#"). */
function kop_nav_hubs_is_placeholder($item) {
    if ((isset($item->type) ? $item->type : '') !== 'custom') {
        return false;
    }
    $url = trim((string) $item->url);
    if ($url === '' || $url === '#' || stripos($url, 'javascript:') === 0) {
        return true;
    }
    return substr($url, -1) === '#' && rtrim(substr($url, 0, -1), '/') === rtrim(home_url('/'), '/');
}

/** The menus, the header's first (theme locations in Kadence's order), then the rest. */
function kop_nav_hubs_menus() {
    $menus = wp_get_nav_menus();
    if (!$menus) {
        return array();
    }
    $order = array();
    $locations = function_exists('get_nav_menu_locations') ? (array) get_nav_menu_locations() : array();
    foreach (array('primary', 'secondary', 'mobile') as $loc) {
        if (!empty($locations[$loc])) {
            $order[(int) $locations[$loc]] = count($order);
        }
    }
    usort($menus, function ($a, $b) use ($order) {
        $ra = isset($order[(int) $a->term_id]) ? $order[(int) $a->term_id] : 99;
        $rb = isset($order[(int) $b->term_id]) ? $order[(int) $b->term_id] : 99;
        return $ra - $rb;
    });
    return $menus;
}

function kop_nav_hubs_state() {
    $state = get_option('kop_nav_hubs');
    return is_array($state) ? $state : array();
}

/**
 * The hub page for a label: an existing page with its slug when it is a hub
 * already or an empty page, else a new published one. Null when the slug
 * belongs to some other page with content (that label is left alone).
 */
function kop_nav_hubs_page_for($label) {
    $slug = sanitize_title($label);
    if ($slug === '') {
        return null;
    }
    $page = get_page_by_path($slug);
    if ($page) {
        if ($page->post_status === 'trash') {
            return null;
        }
        $template = get_post_meta($page->ID, '_wp_page_template', true);
        if ($template === 'templates/page-hub.php') {
            return $page;
        }
        if (($template === '' || $template === 'default') && trim((string) $page->post_content) === '') {
            update_post_meta($page->ID, '_wp_page_template', 'templates/page-hub.php');
            return $page;
        }
        return null;
    }
    $id = wp_insert_post(array(
        'post_title'   => $label,
        'post_name'    => $slug,
        'post_status'  => 'publish',
        'post_type'    => 'page',
        'post_content' => '',
    ));
    if (!$id || is_wp_error($id)) {
        return null;
    }
    update_post_meta($id, '_wp_page_template', 'templates/page-hub.php');
    return get_post($id);
}

/**
 * Give every "#" group label a hub page and point it there.
 *
 * @return array Summary lines.
 */
function kop_nav_hubs_apply() {
    $done = array();
    if (!function_exists('wp_get_nav_menus') || !function_exists('wp_update_nav_menu_item')) {
        return $done;
    }
    $state = kop_nav_hubs_state();
    foreach (kop_nav_hubs_menus() as $menu) {
        $items = wp_get_nav_menu_items($menu->term_id);
        if (!$items) {
            continue;
        }
        $parents = array();
        foreach ($items as $item) {
            if ((int) $item->menu_item_parent !== 0) {
                $parents[(int) $item->menu_item_parent] = true;
            }
        }
        foreach ($items as $item) {
            if ((int) $item->menu_item_parent !== 0 || empty($parents[(int) $item->ID]) || !kop_nav_hubs_is_placeholder($item)) {
                continue;
            }
            $label = trim(wp_strip_all_tags($item->title));
            $page  = kop_nav_hubs_page_for($label);
            if (!$page) {
                $done[] = $label . ': skipped, /' . sanitize_title($label) . '/ is another page';
                continue;
            }
            $saved = wp_update_nav_menu_item($menu->term_id, (int) $item->ID, array(
                'menu-item-db-id'       => (int) $item->ID,
                'menu-item-object-id'   => (int) $page->ID,
                'menu-item-object'      => 'page',
                'menu-item-type'        => 'post_type',
                'menu-item-title'       => $item->title,
                'menu-item-parent-id'   => 0,
                'menu-item-position'    => (int) $item->menu_order,
                'menu-item-status'      => 'publish',
                'menu-item-description' => isset($item->description) ? $item->description : '',
                'menu-item-attr-title'  => isset($item->attr_title) ? $item->attr_title : '',
                'menu-item-target'      => isset($item->target) ? $item->target : '',
                'menu-item-classes'     => isset($item->classes) ? implode(' ', array_filter((array) $item->classes)) : '',
                'menu-item-xfn'         => isset($item->xfn) ? $item->xfn : '',
            ));
            if (!$saved || is_wp_error($saved)) {
                continue;
            }
            $slug = $page->post_name;
            if (!isset($state[$slug])) {
                $state[$slug] = array('page_id' => (int) $page->ID, 'label' => $label, 'items' => array());
            }
            $state[$slug]['items'][] = array('menu' => (int) $menu->term_id, 'item' => (int) $item->ID, 'url' => (string) $item->url);
            $done[] = sprintf('%s (%s) => /%s/', $label, $menu->name, $slug);
        }
    }
    update_option('kop_nav_hubs', $state, false);
    return $done;
}

/** Put the menu items back to the links they had. The pages stay. */
function kop_nav_hubs_undo() {
    $state = kop_nav_hubs_state();
    foreach ($state as $slug => $hub) {
        foreach ($hub['items'] as $ref) {
            $item = get_post($ref['item']);
            if (!$item) {
                continue;
            }
            $menu_item = wp_setup_nav_menu_item($item);
            wp_update_nav_menu_item($ref['menu'], (int) $ref['item'], array(
                'menu-item-db-id'     => (int) $ref['item'],
                'menu-item-type'      => 'custom',
                'menu-item-url'       => $ref['url'],
                'menu-item-title'     => $menu_item->title,
                'menu-item-parent-id' => 0,
                'menu-item-position'  => (int) $menu_item->menu_order,
                'menu-item-status'    => 'publish',
            ));
        }
    }
    update_option('kop_nav_hubs', array(), false);
}

function kop_nav_hubs_maybe_apply() {
    if (get_option('kop_nav_hubs_applied') === kop_nav_hubs_version()) {
        return;
    }
    update_option('kop_nav_hubs_applied', kop_nav_hubs_version());
    kop_nav_hubs_apply();
}
// After kop_maybe_apply_template_assignments() (init 20), which adds menu entries.
add_action('init', 'kop_nav_hubs_maybe_apply', 22);

/**
 * A menu saved in wp-admin or the Customizer: a group label added since gets
 * its hub. wp_update_nav_menu fires before the items are saved, so the pass
 * waits for the end of the request.
 */
add_action('wp_update_nav_menu', function () {
    static $queued = false;
    if (!$queued) {
        $queued = true;
        add_action('shutdown', 'kop_nav_hubs_apply');
    }
});

/* ---- On the hub page ---------------------------------------------------- */

/** The hub's module and settings, for every hub this file made. */
add_filter('kop_hub_modules', function ($modules) {
    foreach (array_keys(kop_nav_hubs_state()) as $slug) {
        if (!isset($modules[$slug])) {
            $modules[$slug] = 'kop_nav_hub_module';
        }
    }
    return $modules;
});

add_filter('kop_hub_config', function ($hubs) {
    $standfirsts = kop_nav_hub_standfirsts();
    foreach (kop_nav_hubs_state() as $slug => $hub) {
        $config = isset($hubs[$slug]) ? $hubs[$slug] : array();
        if (!isset($config['standfirst']) && isset($standfirsts[$slug])) {
            $config['standfirst'] = $standfirsts[$slug];
        }
        // The page is made empty; while it stays empty the list leads.
        if (!isset($config['content'])) {
            $page = get_post($hub['page_id']);
            if ($page && trim((string) $page->post_content) === '') {
                $config['content'] = false;
            }
        }
        $hubs[$slug] = $config;
    }
    return $hubs;
});

/** The last part of a link's path on this site, or ''. */
function kop_nav_hub_slug_of($url) {
    $home = wp_parse_url(home_url('/'));
    $u = wp_parse_url((string) $url);
    if (!$u || (!empty($u['host']) && isset($home['host']) && strcasecmp($u['host'], $home['host']) !== 0)) {
        return '';
    }
    $path = trim(isset($u['path']) ? $u['path'] : '', '/');
    if ($path === '') {
        return '';
    }
    $parts = explode('/', $path);
    return end($parts);
}

/** One line on a menu entry: its Description, its page's excerpt, or the notes. */
function kop_nav_hub_note($item) {
    $desc = trim(wp_strip_all_tags(isset($item->description) ? (string) $item->description : ''));
    if ($desc !== '') {
        return $desc;
    }
    if (isset($item->type) && $item->type === 'post_type' && !empty($item->object_id)) {
        $post = get_post((int) $item->object_id);
        if ($post && has_excerpt($post)) {
            return trim(wp_strip_all_tags(get_the_excerpt($post)));
        }
    }
    $notes = kop_nav_hub_notes();
    $slug = kop_nav_hub_slug_of($item->url);
    return isset($notes[$slug]) ? $notes[$slug] : '';
}

/**
 * The entries under this hub's label, from the first menu (header first)
 * where the label links here: array of array('item' => ..., 'children' => array).
 */
function kop_nav_hub_entries($page_id) {
    foreach (kop_nav_hubs_menus() as $menu) {
        $items = wp_get_nav_menu_items($menu->term_id);
        if (!$items) {
            continue;
        }
        $hub_item = null;
        foreach ($items as $item) {
            if ((int) $item->menu_item_parent === 0 && $item->type === 'post_type' && (int) $item->object_id === (int) $page_id) {
                $hub_item = $item;
                break;
            }
        }
        if (!$hub_item) {
            continue;
        }
        $by_parent = array();
        foreach ($items as $item) {
            $by_parent[(int) $item->menu_item_parent][] = $item;
        }
        $entries = array();
        foreach (isset($by_parent[(int) $hub_item->ID]) ? $by_parent[(int) $hub_item->ID] : array() as $child) {
            $kids = isset($by_parent[(int) $child->ID]) ? $by_parent[(int) $child->ID] : array();
            $entries[] = array('item' => $child, 'children' => $kids);
        }
        if ($entries) {
            return $entries;
        }
    }
    return array();
}

/** A card per entry; an entry with entries under it is a heading over them. */
function kop_nav_hub_module() {
    $page_id = get_the_ID();
    $entries = kop_nav_hub_entries($page_id);
    if (!$entries) {
        return;
    }
    $self = get_permalink($page_id);
    $card = function ($item) use ($self) {
        if (kop_nav_hubs_is_placeholder($item) || untrailingslashit((string) $item->url) === untrailingslashit((string) $self)) {
            return '';
        }
        $note = kop_nav_hub_note($item);
        return '<li class="kop-nav-hub-card"><a href="' . esc_url($item->url) . '">' . esc_html(wp_strip_all_tags($item->title)) . '</a>'
            . ($note !== '' ? '<p>' . esc_html($note) . '</p>' : '') . '</li>';
    };

    $loose = '';
    $groups = array();
    foreach ($entries as $e) {
        if ($e['children']) {
            $groups[] = $e;
        } else {
            $loose .= $card($e['item']);
        }
    }
    echo '<div class="kop-hub-module kop-nav-hub">';
    if ($loose !== '') {
        echo '<ul class="kop-nav-hub-cards">' . $loose . '</ul>';
    }
    foreach ($groups as $i => $g) {
        $id = 'kop-nav-hub-g' . ($i + 1);
        $cards = $card($g['item']);
        foreach ($g['children'] as $child) {
            $cards .= $card($child);
        }
        if ($cards === '') {
            continue;
        }
        echo '<section class="kop-nav-hub-group" aria-labelledby="' . esc_attr($id) . '">';
        echo '<h2 class="kop-hub-h" id="' . esc_attr($id) . '">' . esc_html(wp_strip_all_tags($g['item']->title)) . '</h2>';
        echo '<ul class="kop-nav-hub-cards">' . $cards . '</ul>';
        echo '</section>';
    }
    echo '</div>';
}
