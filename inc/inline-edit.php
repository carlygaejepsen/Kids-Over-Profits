<?php
/**
 * Edit in place: for an admin viewing the site, a small pencil sits on every
 * piece of text a page prints. Clicking it opens that text's fields in a
 * dialog, and Save writes them to wherever the text really lives, through the
 * same save path its wp-admin screen uses. No bespoke editor to go to first.
 *
 * Markup: an element carries data-kop-edit="<ref>" (kop_ie_attr()). A ref
 * names a source and what in it, e.g.
 *
 *   post:12:page            title, excerpt and whole content of post 12
 *   post:12:block:3:ab12cd  the 4th top-level block of post 12's content (hash
 *                           of its source, so a changed page cannot be overwritten)
 *   pt:faq:intro            a Page Text section (inc/page-text.php)
 *   gl:<entry id>           a glossary entry (inc/glossary-editor.php's save)
 *   facility:45:staff       a group of a facility's fields (facilities_v2)
 *   facility:45:all|raw     every field, or the whole record as JSON
 *   fstatus:45:Closed       set a facility's status in one click, years kept (save
 *                           only; a button with data-kop-quick="<ref>", Undo offered)
 *   rec:lawsuit:9           a lawsuit, legislation or news row (saved by
 *                           api/manage-submissions.php update_fields, which also
 *                           re-syncs the facility links)
 *   cfg:hub:law-policy      every line of a hub's settings (inc/hub-shell.php)
 *   cfg:utility:links       a utility page's settings (inc/utility-pages.php)
 *   operator:7:all|raw      a parent company's fields (kop_operators), name excepted
 *   school:3                an indigenous school (inc/indigenous-schools.php)
 *   txt:<key>               a line or block of template text (kop_text(), kop_ie_html_start())
 *   site:name               the site name and tagline
 *   referrer:5:all|raw      a referrer / educational consultant record (referrers_master)
 *   row:memorial:4          a row of a table with no other editor (kop_ie_row_tables())
 *   ya:2                    a young adult program (inc/young-adult-programs.php)
 *   doc:812[:h45]           a document's title, caption, description, alt text, and
 *                           where it is filed (h45: the folder of the page it is on;
 *                           move it, take it off or delete it: inc/doc-placement.php)
 *   docundo:<token>         undo such a move (save only)
 *   rep:ch:<id>|note:<AB>   a reporting directory entry or state note (kop_reporting_edits)
 *
 * GET  kop/v1/inline-edit?ref=...   -> {title, fields: [{name, label, type, value, ...}]}
 * POST kop/v1/inline-edit           payload (a JSON file part: the host firewall
 *                                   rejects HTML in plain form fields) {ref, values}
 *                                   -> {message, redirect?}
 *
 * A load may name 'alt' => {ref, label}: another dialog for the same record
 * (every field, or the whole record as JSON), offered as a button.
 *
 * Field types the dialog draws (js/inline-edit.js): text, textarea, code,
 * lines (one item a line), items (a list of paragraphs), number, year,
 * select, checks, bool, rows (a small table; each row keeps __i, its index in
 * the stored list, so keys the table does not show survive the save).
 *
 * Text that a page prints from code with no editor behind it (footer, legal
 * pages, page-state intros) and pages drawn in the browser from JSON feeds
 * (state hubs, inspection pages, the network map) have no pencil yet.
 */

if (!defined('ABSPATH')) {
    exit;
}

define('KOP_IE_CAP', 'manage_options');

/** Whether pencils are drawn on this request. */
function kop_ie_can() {
    static $can = null;
    if ($can === null) {
        $can = !is_admin() && !wp_doing_ajax() && !(defined('REST_REQUEST') && REST_REQUEST)
            && is_user_logged_in() && current_user_can(KOP_IE_CAP);
    }
    return $can;
}

/** ' data-kop-edit="ref"' for an admin, '' for everyone else. */
function kop_ie_attr($ref, $label = '') {
    if (!kop_ie_can()) {
        return '';
    }
    return ' data-kop-edit="' . esc_attr($ref) . '"' . ($label !== '' ? ' data-kop-edit-label="' . esc_attr($label) . '"' : '');
}

/* ---- Small helpers ------------------------------------------------------- */

function kop_ie_field($name, $label, $type, $value, $extra = array()) {
    return array_merge(array('name' => $name, 'label' => $label, 'type' => $type, 'value' => $value), $extra);
}

function kop_ie_get_path(array $a, $path) {
    foreach (explode('.', $path) as $k) {
        if (!is_array($a) || !array_key_exists($k, $a)) {
            return null;
        }
        $a = $a[$k];
    }
    return $a;
}

function kop_ie_has_path(array $a, $path) {
    foreach (explode('.', $path) as $k) {
        if (!is_array($a) || !array_key_exists($k, $a)) {
            return false;
        }
        $a = $a[$k];
    }
    return true;
}

function kop_ie_set_path(array &$a, $path, $value) {
    $ref = &$a;
    foreach (explode('.', $path) as $k) {
        if (!isset($ref[$k]) || !is_array($ref[$k])) {
            $ref[$k] = isset($ref[$k]) && !is_array($ref[$k]) ? $ref[$k] : array();
        }
        $ref = &$ref[$k];
    }
    $ref = $value;
}

/** "one\ntwo\n\n" -> ['one', 'two']. */
function kop_ie_lines($text) {
    $out = array();
    foreach (preg_split('/\R/', (string) $text) as $line) {
        $line = trim($line);
        if ($line !== '') {
            $out[] = $line;
        }
    }
    return $out;
}

function kop_ie_clean_text($text) {
    $text = str_replace(array("\r\n", "\r"), "\n", (string) $text);
    return trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $text));
}

/** '' -> null, else an int in range, else an error. */
function kop_ie_int($value, $label, $min, $max) {
    $value = trim((string) $value);
    if ($value === '') {
        return null;
    }
    if (!preg_match('/^-?\d+$/', $value) || (int) $value < $min || (int) $value > $max) {
        throw new RuntimeException($label . ': a whole number from ' . $min . ' to ' . $max . ', or empty.');
    }
    return (int) $value;
}

function kop_ie_who() {
    $u = wp_get_current_user();
    return $u && $u->exists() ? $u->display_name : '';
}

function kop_ie_purge_post($post_id) {
    clean_post_cache($post_id);
    do_action('litespeed_purge_post', $post_id);
}

function kop_ie_purge_url($url) {
    if ($url !== '') {
        do_action('litespeed_purge_url', $url);
    }
}

/* ---- Sources ------------------------------------------------------------- */

/**
 * source => array(load => fn(array $parts): array(title, fields[, save_via, help]),
 *                 save => fn(array $parts, array $values): array(message[, redirect])).
 * $parts is the ref split on ':' after the source name.
 */
function kop_ie_sources() {
    return apply_filters('kop_inline_edit_sources', array(
        'post'     => array('load' => 'kop_ie_post_load', 'save' => 'kop_ie_post_save'),
        'pt'       => array('load' => 'kop_ie_pt_load', 'save' => 'kop_ie_pt_save'),
        'gl'       => array('load' => 'kop_ie_gl_load', 'save' => 'kop_ie_gl_save'),
        'facility' => array('load' => 'kop_ie_facility_load', 'save' => 'kop_ie_facility_save'),
        'fstatus'  => array('load' => 'kop_ie_fstatus_load', 'save' => 'kop_ie_fstatus_save'),
        'rec'     => array('load' => 'kop_ie_rec_load', 'save' => null),
        'cfg'      => array('load' => 'kop_ie_cfg_load', 'save' => 'kop_ie_cfg_save'),
        'operator' => array('load' => 'kop_ie_operator_load', 'save' => 'kop_ie_operator_save'),
        'school'   => array('load' => 'kop_ie_ischool_load', 'save' => 'kop_ie_ischool_save'),
        'txt'      => array('load' => 'kop_ie_txt_load', 'save' => 'kop_ie_txt_save'),
        'site'     => array('load' => 'kop_ie_site_load', 'save' => 'kop_ie_site_save'),
        'referrer' => array('load' => 'kop_ie_referrer_load', 'save' => 'kop_ie_referrer_save'),
        'row'      => array('load' => 'kop_ie_row_load', 'save' => 'kop_ie_row_save'),
        'ya'       => array('load' => 'kop_ie_ya_load', 'save' => 'kop_ie_ya_save'),
        'doc'      => array('load' => 'kop_ie_doc_load', 'save' => 'kop_ie_doc_save'),
        'docundo'  => array('load' => 'kop_ie_docundo_load', 'save' => 'kop_ie_docundo_save'),
        'rep'      => array('load' => 'kop_ie_rep_load', 'save' => 'kop_ie_rep_save'),
    ));
}

function kop_ie_resolve($ref) {
    $parts = explode(':', (string) $ref);
    $source = array_shift($parts);
    $sources = kop_ie_sources();
    if (!isset($sources[$source])) {
        throw new RuntimeException('Unknown kind of field: ' . $source);
    }
    return array($sources[$source], $parts);
}

/* ---- WordPress posts: title, excerpt, content, and each block ------------ */

function kop_ie_post_get($id) {
    $post = get_post((int) $id);
    if (!$post || !current_user_can('edit_post', $post->ID)) {
        throw new RuntimeException('That page cannot be edited here.');
    }
    return $post;
}

function kop_ie_block_hash(array $block) {
    return substr(md5(serialize_block($block)), 0, 8);
}

/** The top-level block a ref names, checked against its hash. */
function kop_ie_post_block($post, $index, $hash) {
    $blocks = parse_blocks($post->post_content);
    if (!isset($blocks[$index]) || kop_ie_block_hash($blocks[$index]) !== $hash) {
        throw new RuntimeException('This part of the page has changed since it was loaded. Reload the page and try again.');
    }
    return $blocks;
}

function kop_ie_post_load(array $p) {
    $post = kop_ie_post_get($p[0] ?? 0);
    $what = $p[1] ?? 'page';
    if ($what === 'block') {
        $blocks = kop_ie_post_block($post, (int) ($p[2] ?? -1), (string) ($p[3] ?? ''));
        $block = $blocks[(int) $p[2]];
        $name = $block['blockName'] ? $block['blockName'] : 'text';
        return array(
            'title'  => 'Part of "' . $post->post_title . '"',
            'help'   => 'This is the page\'s own source for this part (' . $name . '). Change the words; keep the <tags> and <!-- wp: --> markers as they are.',
            'fields' => array(kop_ie_field('source', 'Source', 'code', trim(serialize_block($block)), array('rows' => 12))),
        );
    }
    return array(
        'title'  => 'Page: ' . $post->post_title,
        'fields' => array(
            kop_ie_field('title', 'Title', 'text', $post->post_title),
            kop_ie_field('excerpt', 'Standfirst / excerpt', 'textarea', $post->post_excerpt, array('help' => 'The line under the title on hubs and article pages, and the summary in lists.')),
            kop_ie_field('content', 'Whole page content (source)', 'code', $post->post_content, array('rows' => 18, 'help' => 'Every part of the content at once. To change one paragraph, its own pencil is easier.')),
        ),
    );
}

function kop_ie_post_save(array $p, array $v) {
    $post = kop_ie_post_get($p[0] ?? 0);
    $what = $p[1] ?? 'page';
    $update = array('ID' => $post->ID);
    if ($what === 'block') {
        $index = (int) ($p[2] ?? -1);
        $blocks = kop_ie_post_block($post, $index, (string) ($p[3] ?? ''));
        $source = str_replace(array("\r\n", "\r"), "\n", (string) ($v['source'] ?? ''));
        $before = serialize_blocks(array_slice($blocks, 0, $index));
        $after = serialize_blocks(array_slice($blocks, $index + 1));
        // Keep the blank line that separated the block from its neighbours.
        $update['post_content'] = $before . ($source !== '' ? trim($source) : '') . $after;
    } else {
        $title = trim(preg_replace('/\s+/', ' ', (string) ($v['title'] ?? $post->post_title)));
        if ($title === '') {
            throw new RuntimeException('The title cannot be empty.');
        }
        $update['post_title'] = $title;
        $update['post_excerpt'] = kop_ie_clean_text($v['excerpt'] ?? $post->post_excerpt);
        $update['post_content'] = str_replace(array("\r\n", "\r"), "\n", (string) ($v['content'] ?? $post->post_content));
    }
    // wp_update_post expects slashed data.
    $result = wp_update_post(wp_slash($update), true);
    if (is_wp_error($result)) {
        throw new RuntimeException($result->get_error_message());
    }
    kop_ie_purge_post($post->ID);
    return array('message' => 'Saved.');
}

/*
 * Each top-level block of the page being viewed is rendered here, one at a
 * time, and wrapped so its pencil edits exactly that block (do_blocks() then
 * finds no block markup left and passes the HTML through). The hash in the
 * ref makes an edit refuse to save if the page changed meanwhile.
 */
add_filter('the_content', function ($content) {
    // The state and country hubs print their content outside the loop, so the
    // check is that this is the viewed post's own, unfiltered content.
    if (!kop_ie_can() || !is_singular()) {
        return $content;
    }
    $id = (int) get_the_ID();
    $post = $id ? get_post($id) : null;
    if (!$post || $id !== (int) get_queried_object_id() || $content !== $post->post_content || !current_user_can('edit_post', $id)) {
        return $content;
    }
    $blocks = has_blocks($content);
    if ($blocks) {
        // What do_blocks() does: no wpautop over block output.
        $priority = has_filter('the_content', 'wpautop');
        if ($priority !== false) {
            remove_filter('the_content', 'wpautop', $priority);
            add_filter('the_content', '_restore_wpautop_hook', $priority + 1);
        }
    }
    $out = '';
    foreach (parse_blocks($content) as $index => $block) {
        $html = $blocks ? render_block($block) : $block['innerHTML'];
        if (trim(wp_strip_all_tags($html)) === '' && !preg_match('/<(img|iframe|video|figure)/i', $html)) {
            $out .= $html;
            continue;
        }
        $ref = 'post:' . $id . ':block:' . $index . ':' . kop_ie_block_hash($block);
        $out .= "
<div class=\"kop-ie-block\"" . kop_ie_attr($ref) . ">
" . $html . "
</div>
";
    }
    return $out;
}, 8);

/* ---- Page Text sections (inc/page-text.php) -------------------------------- */

function kop_ie_pt_section($slug, $key) {
    if (!function_exists('kop_page_text_page') || !kop_page_text_page($slug)) {
        throw new RuntimeException('Unknown page.');
    }
    foreach (kop_page_text_sections($slug) as $s) {
        if ($s['key'] === $key) {
            return $s;
        }
    }
    throw new RuntimeException('That section is no longer on the page.');
}

function kop_ie_pt_load(array $p) {
    $s = kop_ie_pt_section($p[0] ?? '', $p[1] ?? '');
    return array(
        'title'  => $s['label'],
        'help'   => 'A blank line between paragraphs; "### " starts a subheading, "- " a list item; **bold**, *italic*, [link text](https://...).',
        'fields' => array(
            kop_ie_field('heading', 'Heading', 'text', $s['heading']),
            kop_ie_field('body', 'Text', 'textarea', $s['body'], array('rows' => 14)),
        ),
    );
}

/** Same rules as kop_page_text_editor_handle_post(), for one section. */
function kop_ie_pt_save(array $p, array $v) {
    $slug = $p[0] ?? '';
    $key = $p[1] ?? '';
    kop_ie_pt_section($slug, $key);
    $default = null;
    foreach (kop_page_text_defaults($slug) as $d) {
        if ($d['key'] === $key) {
            $default = $d;
        }
    }
    $heading = trim(preg_replace('/\s+/', ' ', (string) ($v['heading'] ?? '')));
    $body = kop_page_text_clean($v['body'] ?? '');
    $edits = kop_page_text_edits();
    $mine = isset($edits[$slug]) ? $edits[$slug] : array();
    if ($heading === $default['heading'] && $body === $default['body']) {
        unset($mine[$key]);
    } else {
        $mine[$key] = array('heading' => $heading, 'body' => $body, 'user' => kop_ie_who(), 'time' => time());
    }
    if ($mine) {
        $edits[$slug] = $mine;
    } else {
        unset($edits[$slug]);
    }
    kop_page_text_save_edits($edits);
    if (function_exists('kop_page_text_purge')) {
        kop_page_text_purge($slug);
    }
    return array('message' => 'Saved.');
}

/* ---- Glossary entries (inc/glossary-editor.php) ----------------------------- */

function kop_ie_gl_entry($id) {
    $state = function_exists('kop_glossary_editor_state') ? kop_glossary_editor_state() : null;
    if (!$state) {
        throw new RuntimeException('js/data/glossary/glossary.md is missing on the server.');
    }
    $entries = kop_glossary_entries_by_id($state['data']);
    if (!isset($entries[$id])) {
        throw new RuntimeException('That entry is no longer in the glossary. Reload the page.');
    }
    return array($state, $entries[$id]);
}

function kop_ie_gl_load(array $p) {
    list($state, $entry) = kop_ie_gl_entry($p[0] ?? '');
    $orig = kop_glossary_parse_entry($entry['source'], true);
    $containers = array();
    foreach (kop_glossary_containers($state['data']) as $path) {
        $label = kop_glossary_container_label($path);
        $containers[] = array('value' => $label, 'label' => $label);
    }
    return array(
        'title'  => 'Glossary: ' . $entry['term'],
        'fields' => array(
            kop_ie_field('term', 'Term', 'text', $orig['term']),
            kop_ie_field('note', 'Qualifier', 'text', $orig['note'], array('help' => 'Shown in brackets after the term. An entry has a qualifier or other names, not both.')),
            kop_ie_field('aka_list', 'Also called', 'lines', implode("\n", $orig['aka'])),
            kop_ie_field('text', 'Definition', 'textarea', $orig['text_raw'], array('rows' => 6, 'help' => '**Term** links to another entry by its name.')),
            kop_ie_field('used', 'Used at', 'lines', kop_glossary_tag_lines($orig['used'])),
            kop_ie_field('reported', 'Reportedly used at', 'lines', kop_glossary_tag_lines($orig['reported'])),
            kop_ie_field('container', 'Section', 'select', kop_glossary_container_label($entry['container']), array('options' => $containers)),
        ),
    );
}

/**
 * Saved through the glossary editor's own handler, so the build check, the
 * change list and "back to the original" behave exactly as in wp-admin.
 */
function kop_ie_gl_save(array $p, array $v) {
    kop_ie_gl_entry($p[0] ?? '');
    $post = array(
        'kop_ge_do'        => 'save',
        'kop_ge_entry'     => (string) $p[0],
        'kop_ge_feedback'  => '0',
        'kop_ge_term'      => (string) ($v['term'] ?? ''),
        'kop_ge_note'      => (string) ($v['note'] ?? ''),
        'kop_ge_aka'       => (string) ($v['aka_list'] ?? ''),
        'kop_ge_text'      => (string) ($v['text'] ?? ''),
        'kop_ge_used'      => (string) ($v['used'] ?? ''),
        'kop_ge_reported'  => (string) ($v['reported'] ?? ''),
        'kop_ge_container' => (string) ($v['container'] ?? ''),
        '_wpnonce'         => wp_create_nonce('kop_glossary_editor'),
    );
    $saved_post = $_POST;
    $saved_request = $_REQUEST;
    $_POST = wp_slash($post);
    $_REQUEST = $_POST;
    try {
        $result = kop_glossary_editor_handle_post();
    } finally {
        $_POST = $saved_post;
        $_REQUEST = $saved_request;
    }
    if (!empty($result['errors'])) {
        throw new RuntimeException(implode(' ', $result['errors']));
    }
    return array('message' => wp_strip_all_tags((string) ($result['notice'] ?? 'Saved.')));
}

/* ---- Facilities (facilities_v2) ------------------------------------------------ */

/**
 * group => array(label, fields). A field is array(path, label, type, extra).
 * 'rows' fields name their columns; 'checks' fields their checklist section.
 */
function kop_ie_facility_groups() {
    $addr = array('street' => 'Street', 'city' => 'City', 'state' => 'State', 'zip' => 'ZIP', 'country' => 'Country');
    return array(
        'names' => array('Names', array(
            array('identification.name', 'Name', 'text'),
            array('identification.currentName', 'Name it uses now, if different', 'text'),
            array('identification.pastNames', 'Past names', 'lines'),
            array('identification.otherNames', 'Also known as', 'lines'),
        )),
        'status' => array('Status and years', array(
            array('operatingPeriod.status', 'Status', 'select', array('options' => array('Open', 'Closed', 'Suspended', 'Transferred', 'Unknown'))),
            array('operatingPeriod.startYear', 'Opened (year)', 'year'),
            array('operatingPeriod.endYear', 'Closed (year)', 'year'),
            array('operatingPeriod.yearsOfOperation', 'Years of operation, as written', 'text', array('help' => 'Only shown when there is no opening or closing year.')),
            array('operatingPeriod.notes', 'Notes on its years', 'items'),
        )),
        'location' => array('Location', array(
            array('location.street', 'Street', 'text'),
            array('location.city', 'City', 'text'),
            array('location.state', 'State (two letters)', 'text'),
            array('location.zip', 'ZIP', 'text'),
            array('location.country', 'Country', 'text'),
            array('location.additionalLocations', 'Other current addresses', 'rows', array('columns' => $addr, 'address' => true)),
            array('location.formerLocations', 'Former locations', 'rows', array('columns' => $addr + array('fromYear' => 'From', 'toYear' => 'To'), 'address' => true)),
        )),
        'details' => array('Program details', array(
            array('facilityDetails.type', 'Type', 'text'),
            array('facilityDetails.gender', 'Serves (gender)', 'text'),
            array('facilityDetails.ageRange.min', 'Youngest age', 'number'),
            array('facilityDetails.ageRange.max', 'Oldest age', 'number'),
            array('facilityDetails.capacity', 'Capacity', 'number'),
            array('facilityDetails.currentCensus', 'Current census', 'number'),
            array('facilityDetails.isPrivatelyOwned', 'Ownership', 'select', array('options' => array(
                array('value' => '', 'label' => 'Not known'), array('value' => 'yes', 'label' => 'Privately owned'), array('value' => 'no', 'label' => 'Publicly operated')))),
        )),
        'people' => array('Operators, owners and referrers', array(
            array('identification.currentOperator', 'Operator', 'text'),
            array('identification.currentOwners', 'Owners', 'lines'),
            array('identification.otherOperators', 'Other operators', 'lines'),
            array('identification.pastOperators', 'Past operators', 'lines'),
            array('identification.investors', 'Investors', 'lines'),
            array('identification.knownReferrers', 'Known referrers', 'lines'),
        )),
        'credentials' => array('Accreditation and licensing', array(
            array('accreditations.current', 'Accreditation', 'lines'),
            array('accreditations.past', 'Past accreditation', 'lines'),
            array('memberships', 'Memberships', 'lines'),
            array('certifications', 'Certifications', 'lines'),
            array('licensing', 'Licensing', 'lines'),
        )),
        'practices' => array('Reported practices', array(
            array('treatmentTypes', 'Treatment methods', 'checks'),
            array('philosophy', 'Program philosophy', 'checks'),
            array('conditions', 'Conditions treated', 'checks'),
            array('criticalIncidents', 'Critical incidents on record', 'checks'),
        )),
        'staff' => array('Staff', array(
            array('staff.administrator', 'Administration', 'rows', array('columns' => array('name' => 'Name', 'role' => 'Role', 'pastJobs' => 'Past jobs'))),
            array('staff.notableStaff', 'Notable staff', 'rows', array('columns' => array('name' => 'Name', 'role' => 'Role', 'pastJobs' => 'Past jobs'))),
            array('staff.pastTTIJobs', 'Staff who came from other programs', 'rows', array('columns' => array('role' => 'Role', 'organization' => 'Program', 'employer' => 'Employer'))),
        )),
        'notes' => array('Research notes', array(
            array('notes', 'Research notes', 'items'),
        )),
        'links' => array('Links', array(
            array('profileLinks', 'Websites and profiles (one address a line)', 'lines'),
        )),
        'testimony' => array('Survivor testimony', array(
            array('survivorTestimony', 'Testimony', 'rows', array('columns' => array('text' => 'Account', 'date' => 'Date', 'publish' => 'OK to publish'),
                'column_types' => array('text' => 'textarea', 'publish' => 'bool'))),
        )),
    );
}

function kop_ie_facility_opts() {
    global $wpdb;
    $pdo = function_exists('kop_seed_pdo') ? kop_seed_pdo() : null;
    if (!$pdo) {
        throw new RuntimeException('No database connection.');
    }
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    require_once get_stylesheet_directory() . '/inc/facility-v2-writer.php';
    if (!kop_v2_writes_active($pdo, $wpdb->prefix)) {
        throw new RuntimeException('Facility saves are not on facilities_v2 yet.');
    }
    return array('pdo' => $pdo, 'prefix' => $wpdb->prefix);
}

/** Checklist options for a section: the form's labels plus any other true/false keys the record holds. */
function kop_ie_check_options($section, $map) {
    $opts = array();
    foreach (kop_facility_checklist_labels() as $k => $label) {
        if (strpos($k, $section . '.') === 0) {
            $opts[substr($k, strlen($section) + 1)] = $label;
        }
    }
    foreach ((array) $map as $k => $v) {
        $k = (string) $k;
        if ($k === '_legacy' || strpos($k, 'custom') === 0 || isset($opts[$k]) || !(is_bool($v) || is_string($v))) {
            continue;
        }
        $opts[$k] = kop_facility_pages_humanize_key($k);
    }
    $out = array();
    foreach ($opts as $k => $label) {
        $out[] = array('value' => $k, 'label' => $label);
    }
    return $out;
}

function kop_ie_checked($v) {
    return $v === true || (is_string($v) && trim($v) !== '' && !in_array(strtolower(trim($v)), array('false', '0', 'no'), true));
}

/** One stored value as the dialog's field. */
function kop_ie_facility_field(array $doc, array $def) {
    list($path, $label, $type) = $def;
    $extra = isset($def[3]) ? $def[3] : array();
    $raw = kop_ie_get_path($doc, $path);
    $name = $path;
    switch ($type) {
        case 'lines':
        case 'items':
            $list = is_array($raw) ? $raw : (is_string($raw) && trim($raw) !== '' ? array($raw) : array());
            $objects = array_filter($list, 'is_array');
            if ($objects) {
                // A list holding objects is edited as a table, so no key is lost.
                $cols = array();
                foreach ($objects as $o) {
                    foreach ($o as $k => $x) {
                        if (is_scalar($x) || $x === null) {
                            $cols[$k] = kop_facility_pages_humanize_key($k) ?: $k;
                        }
                    }
                }
                return kop_ie_field($name, $label, 'rows', kop_ie_rows_value($list, $cols), array('columns' => $cols, 'scalar_rows' => true));
            }
            if ($type === 'lines') {
                return kop_ie_field($name, $label, 'lines', implode("\n", array_map('strval', $list)), $extra);
            }
            return kop_ie_field($name, $label, 'items', array_values(array_map('strval', $list)), $extra);
        case 'rows':
            $list = is_array($raw) ? array_values($raw) : array();
            $cols = $extra['columns'];
            foreach ($list as $o) {
                if (is_array($o)) {
                    foreach ($o as $k => $x) {
                        // An address's raw line is rebuilt from its parts when they change (kop_ie_address_line()).
                        if (!isset($cols[$k]) && (is_scalar($x) || $x === null) && !in_array($k, array('id', 'timestamp', 'raw'), true)) {
                            $cols[$k] = kop_facility_pages_humanize_key($k) ?: $k;
                        }
                    }
                }
            }
            $extra['columns'] = $cols;
            return kop_ie_field($name, $label, 'rows', kop_ie_rows_value($list, $cols), $extra);
        case 'checks':
            $map = is_array($raw) ? $raw : array();
            $checked = array();
            foreach ($map as $k => $v) {
                if ($k !== '_legacy' && strpos((string) $k, 'custom') !== 0 && kop_ie_checked($v)) {
                    $checked[] = (string) $k;
                }
            }
            return kop_ie_field($name, $label, 'checks', $checked, array('options' => kop_ie_check_options($path, $map)));
        case 'bool':
            return kop_ie_field($name, $label, 'bool', $raw === true || $raw === 'true' || $raw === 1 || $raw === '1', $extra);
        case 'select':
            if ($path === 'facilityDetails.isPrivatelyOwned') {
                $raw = $raw === null ? '' : ($raw ? 'yes' : 'no');
            }
            return kop_ie_field($name, $label, 'select', (string) $raw, $extra);
        default:
            return kop_ie_field($name, $label, $type, $raw === null ? '' : (string) $raw, $extra);
    }
}

/** A stored list as table rows, each carrying __i (its place in the list). */
function kop_ie_rows_value(array $list, array $cols) {
    $rows = array();
    foreach (array_values($list) as $i => $o) {
        $row = array('__i' => $i);
        foreach ($cols as $k => $label) {
            $x = is_array($o) ? ($o[$k] ?? '') : ($k === array_key_first($cols) ? $o : '');
            $row[$k] = is_bool($x) ? $x : (string) $x;
        }
        $rows[] = $row;
    }
    return $rows;
}

/** The dialog's value back into the stored shape. */
function kop_ie_facility_apply(array &$doc, array $field, $value) {
    $path = $field['name'];
    $label = $field['label'];
    $before = kop_ie_get_path($doc, $path);
    switch ($field['type']) {
        case 'lines':
            $new = kop_ie_lines($value);
            // The same entries as stored (blank ones aside): left exactly as stored.
            if (is_array($before) && !array_filter($before, 'is_array')
                && array_values(array_filter(array_map('trim', array_map('strval', $before)), 'strlen')) === $new) {
                return;
            }
            break;
        case 'items':
            $new = array();
            foreach ((array) $value as $t) {
                $t = kop_ie_clean_text($t);
                if ($t !== '') {
                    $new[] = $t;
                }
            }
            break;
        case 'rows':
            $orig = is_array($before) ? array_values($before) : array();
            $new = array();
            $types = $field['column_types'] ?? array();
            foreach ((array) $value as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $i = isset($row['__i']) && $row['__i'] !== '' ? (int) $row['__i'] : -1;
                $item = $i >= 0 && isset($orig[$i]) ? $orig[$i] : array();
                if (!is_array($item)) {
                    $item = array();
                }
                $empty = true;
                foreach ($field['columns'] as $k => $l) {
                    $x = $row[$k] ?? '';
                    if (($types[$k] ?? '') === 'bool') {
                        $item[$k] = !empty($x);
                        continue;
                    }
                    $x = kop_ie_clean_text($x);
                    if (in_array($k, array('fromYear', 'toYear'), true)) {
                        $x = kop_ie_int($x, $l, 1800, (int) gmdate('Y') + 1);
                    }
                    if ($x !== '' && $x !== null) {
                        $empty = false;
                    }
                    $item[$k] = $x;
                }
                if (!$empty) {
                    $was = $i >= 0 && isset($orig[$i]) && is_array($orig[$i]) ? $orig[$i] : null;
                    if (!empty($field['address']) && (!$was || kop_ie_address_changed($was, $item))) {
                        $item['raw'] = kop_ie_address_line($item);
                    }
                    $new[] = $item;
                }
            }
            break;
        case 'checks':
            $map = is_array($before) ? $before : array();
            $on = array_flip(array_map('strval', (array) $value));
            foreach ($field['options'] as $o) {
                $k = $o['value'];
                if (isset($on[$k])) {
                    if (!kop_ie_checked($map[$k] ?? null)) {
                        $map[$k] = true;
                    }
                } elseif (array_key_exists($k, $map)) {
                    $map[$k] = false;
                }
            }
            $new = $map;
            break;
        case 'year':
            $new = kop_ie_int($value, $label, 1800, (int) gmdate('Y') + 1);
            break;
        case 'bool':
            $new = $value === true || $value === 'true' || $value === 1 || $value === '1';
            // Unticked, a flag the record never had stays absent.
            if (!$new && $before === null) {
                return;
            }
            if ($new === (bool) $before && !is_bool($before)) {
                return;   // "true" stored as text and still ticked: left as it was
            }
            break;
        case 'number':
            $new = kop_ie_int($value, $label, 0, 100000);
            break;
        case 'select':
            $value = (string) $value;
            if ($path === 'facilityDetails.isPrivatelyOwned') {
                $new = $value === '' ? null : $value === 'yes';
                break;
            }
            $allowed = array();
            foreach ($field['options'] as $o) {
                $allowed[] = is_array($o) ? $o['value'] : $o;
            }
            if (!in_array($value, $allowed, true)) {
                throw new RuntimeException($label . ': pick one of the choices.');
            }
            $new = $value;
            break;
        default:
            $new = trim(preg_replace('/[ \t]+/', ' ', kop_ie_clean_text($value)));
            if ($path === 'location.state') {
                $new = strtoupper($new);
                $new = $new === '' ? null : $new;
            } elseif ($path === 'location.country') {
                $new = $new === '' ? null : $new;
            }
    }
    // Left empty, a field the record never had is not added.
    if (($new === '' || $new === null || $new === array()) && !kop_ie_has_path($doc, $path)) {
        return;
    }
    kop_ie_set_path($doc, $path, $new);
}

/** An address as one line, from its parts (the stored raw line wins over the parts when a record is normalized and shown). */
function kop_ie_address_line(array $a) {
    $a['raw'] = '';
    return kop_facility_pages_format_address($a);
}

function kop_ie_address_changed(array $a, array $b) {
    foreach (array('street', 'city', 'state', 'zip', 'country') as $k) {
        if (trim((string) ($a[$k] ?? '')) !== trim((string) ($b[$k] ?? ''))) {
            return true;
        }
    }
    return false;
}

/**
 * The dialog's values applied to a document: the save's whole edit, without
 * the database (scripts/test-inline-edit.php runs it on every record).
 */
function kop_ie_facility_apply_values(array $doc, $group, array $values) {
    $before = $doc;
    foreach (kop_ie_facility_group_fields($doc, $group) as $field) {
        if (array_key_exists($field['name'], $values)) {
            kop_ie_facility_apply($doc, $field, $values[$field['name']]);
        }
    }
    // A changed address replaces its raw line and the free-text location, which would otherwise win.
    if (isset($doc['location']) && is_array($doc['location']) && kop_ie_address_changed((array) ($before['location'] ?? array()), $doc['location'])) {
        $doc['location']['raw'] = kop_ie_address_line($doc['location']);
        $doc['location']['text'] = '';
    }
    return $doc;
}

function kop_ie_facility_group_fields(array $doc, $group) {
    $groups = kop_ie_facility_groups();
    $names = $group === 'all' ? array_keys($groups) : array($group);
    $fields = array();
    foreach ($names as $g) {
        if (!isset($groups[$g])) {
            throw new RuntimeException('Unknown group of fields.');
        }
        foreach ($groups[$g][1] as $def) {
            $f = kop_ie_facility_field($doc, $def);
            if ($group === 'all') {
                $f['section'] = $groups[$g][0];
            }
            $fields[] = $f;
        }
    }
    return $fields;
}

function kop_ie_facility_load(array $p) {
    $fid = (int) ($p[0] ?? 0);
    $group = (string) ($p[1] ?? 'all');
    $opts = kop_ie_facility_opts();
    $stored = kop_facility_load($fid, $opts);
    if (!$stored) {
        throw new RuntimeException("Facility #{$fid} does not exist.");
    }
    $doc = $stored['doc'];
    $name = (string) ($doc['identification']['name'] ?? '');
    if ($group === 'raw') {
        return array(
            'title'  => $name . ': whole record',
            'help'   => 'Every field this facility holds, as stored. Keep the structure; change the values. The record is checked before it is saved.',
            'fields' => array(kop_ie_field('json', 'Record', 'code', wp_json_encode($doc, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), array('rows' => 30))),
        );
    }
    $groups = kop_ie_facility_groups();
    return array(
        'title'  => $name . ': ' . ($group === 'all' ? 'every field' : $groups[$group][0] ?? ''),
        'fields' => kop_ie_facility_group_fields($doc, $group),
        'alt'    => array('ref' => 'facility:' . $fid . ':' . ($group === 'all' ? 'raw' : 'all'), 'label' => $group === 'all' ? 'Whole record (JSON)' : 'Every field'),
    );
}

function kop_ie_facility_save(array $p, array $v) {
    $fid = (int) ($p[0] ?? 0);
    $group = (string) ($p[1] ?? 'all');
    $opts = kop_ie_facility_opts();
    $status_changed = false;
    kop_v2_with_write_lock($opts['pdo'], function () use ($fid, $group, $opts, $v, &$status_changed) {
        $stored = kop_facility_load($fid, $opts);
        if (!$stored) {
            throw new RuntimeException("Facility #{$fid} does not exist.");
        }
        $doc = $stored['doc'];
        $before_op = $doc['operatingPeriod'] ?? array();
        if ($group === 'raw') {
            $new = json_decode((string) ($v['json'] ?? ''), true);
            if (!is_array($new) || !isset($new['identification']) || !is_array($new['identification'])) {
                throw new RuntimeException('That is not a valid record: ' . (json_last_error() ? json_last_error_msg() : 'identification is missing') . '.');
            }
            $new['facility_id'] = $doc['facility_id'] ?? $fid;
            $doc = $new;
        } else {
            $doc = kop_ie_facility_apply_values($doc, $group, $v);
        }
        if (trim((string) ($doc['identification']['name'] ?? '')) === '') {
            throw new RuntimeException('The name cannot be empty.');
        }
        // Through the legacy shape and back, as the closure and Woodbury
        // saves do, so derived fields (nameKey, location text) come out current.
        $out = kop_facility_normalize(kop_facility_to_legacy($doc), array(
            'facility_id' => $doc['facility_id'] ?? $fid,
            'unique_name' => $doc['provenance']['uniqueName'] ?? $stored['unique_name'],
        ));
        $out['provenance'] = $doc['provenance'] ?? $out['provenance'];
        try {
            kop_facility_save($out, $opts);
        } catch (RuntimeException $e) {
            throw new RuntimeException(preg_replace('/^kop_facility_save: /', 'Not saved, the record does not check out: ', $e->getMessage()));
        }
        $after_op = $out['operatingPeriod'] ?? array();
        $status_changed = ($before_op['status'] ?? '') !== ($after_op['status'] ?? '') || ($before_op['endYear'] ?? null) !== ($after_op['endYear'] ?? null);
    });
    if ($status_changed) {
        do_action('kop_facility_status_changed', $fid);
    }
    if (function_exists('kop_facility_pages_flush_index')) {
        kop_facility_pages_flush_index();
    }
    $url = function_exists('kop_facility_page_url') ? (string) kop_facility_page_url($fid) : '';
    kop_ie_purge_url($url);
    return array('message' => 'Saved.', 'redirect' => $url);
}

/**
 * fstatus:<id>:<Status>, save only: one click sets the facility's status and
 * leaves its years as they are (a program known to be closed, year unknown).
 * The answer carries the ref that puts the old status back.
 */
function kop_ie_fstatus_load(array $p) {
    throw new RuntimeException('This button saves at once; there is nothing to open.');
}

function kop_ie_fstatus_save(array $p, array $v) {
    $fid = (int) ($p[0] ?? 0);
    $status = (string) ($p[1] ?? '');
    if (!in_array($status, array('Open', 'Closed', 'Suspended', 'Transferred', 'Unknown'), true)) {
        throw new RuntimeException('Unknown status.');
    }
    $stored = kop_facility_load($fid, kop_ie_facility_opts());
    if (!$stored) {
        throw new RuntimeException("Facility #{$fid} does not exist.");
    }
    $previous = (string) ($stored['doc']['operatingPeriod']['status'] ?? '');
    $previous = $previous !== '' ? $previous : 'Unknown';
    $out = kop_ie_facility_save(array($fid, 'status'), array('operatingPeriod.status' => $status));
    $out['message'] = $status === 'Closed' ? 'Marked closed. Add the year with the status pencil when you find it.' : 'Status set to ' . $status . '.';
    if ($previous !== $status) {
        $out['undo'] = 'fstatus:' . $fid . ':' . $previous;
    }
    return $out;
}

/* ---- Lawsuits, legislation and news rows ------------------------------------- */

/**
 * The fields of each record type, matching kop_editable_fields() in
 * api/manage-submissions.php, which does the save (and re-syncs the facility
 * links). Keep the two lists in step.
 */
function kop_ie_record_types() {
    return array(
        'lawsuit' => array('table' => 'lawsuits', 'title' => 'case_name', 'fields' => array(
            'case_name' => array('Case name', 'text'), 'case_number' => array('Case number', 'text'), 'court' => array('Court', 'text'),
            'jurisdiction' => array('Jurisdiction', 'text'), 'filing_date' => array('Filed (YYYY-MM-DD)', 'text'),
            'status' => array('Status', 'select', array('filed', 'in_progress', 'settled', 'dismissed', 'ruling', 'appeal', 'closed', 'unknown')),
            'plaintiffs' => array('Plaintiffs', 'lines'), 'defendants' => array('Defendants', 'lines'),
            'facilities_mentioned' => array('Facilities', 'lines'), 'staff_mentioned' => array('Staff named', 'lines'),
            'organizations_mentioned' => array('Organizations named', 'lines'), 'claims' => array('Claims', 'lines'),
            'outcome' => array('Outcome', 'textarea'), 'settlement_amount' => array('Settlement amount', 'text'),
            'summary' => array('Summary', 'textarea'), 'source_urls' => array('Source links', 'lines'),
            'document_urls' => array('Court document links', 'lines'), 'tags' => array('Tags', 'lines'),
            'reviewer_notes' => array('Internal notes (not shown)', 'textarea'),
        )),
        'legislation' => array('table' => 'legislation', 'title' => 'bill_number', 'fields' => array(
            'bill_number' => array('Bill number', 'text'), 'bill_title' => array('Title', 'text'), 'jurisdiction' => array('Jurisdiction', 'text'),
            'chamber' => array('Chamber', 'select', array('house', 'senate', 'assembly', 'joint', 'federal_house', 'federal_senate', 'other', 'unknown')),
            'session_year' => array('Session year', 'text'), 'bill_type' => array('Bill type', 'text'), 'sponsors' => array('Sponsors', 'lines'),
            'status' => array('Status', 'select', array('proposed', 'introduced', 'in_committee', 'passed_house', 'passed_senate', 'signed', 'vetoed', 'dead', 'enacted', 'unknown')),
            'introduced_date' => array('Introduced (YYYY-MM-DD)', 'text'), 'last_action_date' => array('Last action (YYYY-MM-DD)', 'text'),
            'last_action_text' => array('Last action', 'textarea'), 'subject_tags' => array('Subjects', 'lines'), 'summary' => array('Summary', 'textarea'),
            'full_text_url' => array('Full text link', 'text'), 'official_url' => array('Official page', 'text'),
            'position' => array('Our position', 'select', array('support', 'oppose', 'neutral', 'watch', 'unknown')),
            'facilities_affected' => array('Facilities affected', 'lines'), 'tags' => array('Tags', 'lines'),
            'reviewer_notes' => array('Internal notes (not shown)', 'textarea'),
        )),
        'news' => array('table' => 'news_submissions', 'title' => 'article_title', 'fields' => array(
            'article_title' => array('Headline', 'text'), 'alternate_title' => array('Headline shown instead', 'text'),
            'author' => array('Author', 'text'), 'publication_name' => array('Outlet', 'text'), 'publication_date' => array('Published (YYYY-MM-DD)', 'text'),
            'article_url' => array('Link', 'text'),
            'article_type' => array('Kind', 'select', array('lawsuit', 'event', 'expose', 'arrest', 'closure', 'corporate', 'general')),
            'facilities_mentioned' => array('Facilities', 'lines'), 'staff_mentioned' => array('Staff named', 'lines'),
            'survivors_mentioned' => array('Survivors named', 'lines'), 'content_warnings' => array('Content warnings', 'lines'),
            'summary' => array('Summary', 'textarea'), 'reviewer_notes' => array('Internal notes (not shown)', 'textarea'),
        )),
    );
}

function kop_ie_rec_load(array $p) {
    $types = kop_ie_record_types();
    $type = (string) ($p[0] ?? '');
    $id = (int) ($p[1] ?? 0);
    if (!isset($types[$type])) {
        throw new RuntimeException('Unknown kind of record.');
    }
    $t = $types[$type];
    $pdo = function_exists('kop_seed_pdo') ? kop_seed_pdo() : null;
    if (!$pdo) {
        throw new RuntimeException('No database connection.');
    }
    $stmt = $pdo->prepare('SELECT * FROM ' . $t['table'] . ' WHERE id = ?');
    $stmt->execute(array($id));
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        throw new RuntimeException('That record is gone.');
    }
    $fields = array();
    foreach ($t['fields'] as $col => $def) {
        if (!array_key_exists($col, $row)) {
            continue;
        }
        $value = (string) $row[$col];
        if ($def[1] === 'lines') {
            $list = json_decode($value, true);
            $items = array();
            foreach (is_array($list) ? $list : ($value !== '' ? array($value) : array()) as $item) {
                $items[] = is_array($item) ? (string) ($item['name'] ?? '') : (string) $item;
            }
            $value = implode("\n", array_filter($items, 'strlen'));
        }
        $extra = array();
        if ($def[1] === 'select') {
            $extra['options'] = $def[2];
            if ($value !== '' && !in_array($value, $def[2], true)) {
                $extra['options'][] = $value;
            }
        }
        $fields[] = kop_ie_field($col, $def[0], $def[1], $value, $extra);
    }
    return array(
        'title'    => ucfirst($type) . ': ' . $row[$t['title']],
        'fields'   => $fields,
        'save_via' => array(
            'url'  => get_stylesheet_directory_uri() . '/api/manage-submissions.php',
            'type' => $type,
            'id'   => $id,
        ),
    );
}

/* ---- Parent companies (kop_operators.json_data.operator) --------------------- */

/** Every field of an operator record, in the facility field format. The name is not here: renaming moves the page and its references (kop_v2_rename_operator()). */
function kop_ie_operator_fields() {
    $people = array('columns' => array('name' => 'Name', 'role' => 'Role', 'pastJobs' => 'Past jobs'));
    return array(
        array('Names and status', array(
            array('currentName', 'Name it uses now, if different', 'text'),
            array('otherNames', 'Also known as', 'lines'),
            array('type', 'Type', 'text'),
            array('status', 'Status (Active, Defunct, Acquired, Merged)', 'text'),
            array('founded', 'Founded', 'text'),
            array('operatingPeriod', 'Operating (years, as written)', 'text'),
        )),
        array('Where', array(
            array('headquarters', 'Headquarters, as one line', 'text', array('help' => 'Shown first; city and state below are used when it is empty.')),
            array('headquartersCity', 'Headquarters city', 'text'),
            array('headquartersState', 'Headquarters state', 'text'),
            array('location', 'Location, as one line', 'text'),
            array('locationCity', 'Location city', 'text'),
            array('locationState', 'Location state', 'text'),
        )),
        array('People and money', array(
            array('keyStaff.ceo', 'Chief executive', 'text'),
            array('keyStaff.founders', 'Founders', 'rows', $people),
            array('keyStaff.keyExecutives', 'Executives', 'rows', $people),
            array('owners', 'Owners', 'lines'),
            array('investors', 'Investors', 'lines'),
            array('parentCompanies', 'Parent companies', 'lines'),
        )),
        array('Notes and links', array(
            array('notes', 'Research notes', 'items'),
            array('websites', 'Websites (one address a line)', 'lines'),
        )),
    );
}

function kop_ie_operator_row(PDO $pdo, $id) {
    global $wpdb;
    $q = $pdo->prepare('SELECT id, name, json_data FROM `' . $wpdb->prefix . 'kop_operators` WHERE id = ?');
    $q->execute(array((int) $id));
    $row = $q->fetch(PDO::FETCH_ASSOC);
    $json = $row ? json_decode((string) $row['json_data'], true) : null;
    if (!$row || !is_array($json)) {
        throw new RuntimeException('That company record is gone.');
    }
    if (!isset($json['operator']) || !is_array($json['operator'])) {
        $json['operator'] = array();
    }
    return array($row, $json);
}

function kop_ie_operator_form(array $op) {
    $fields = array();
    foreach (kop_ie_operator_fields() as $section) {
        foreach ($section[1] as $def) {
            $f = kop_ie_facility_field($op, $def);
            $f['section'] = $section[0];
            $fields[] = $f;
        }
    }
    return $fields;
}

/** The dialog's values applied to an operator object (scripts/test-inline-edit.php runs it on every record). */
function kop_ie_operator_apply_values(array $op, array $values) {
    foreach (kop_ie_operator_form($op) as $field) {
        if (array_key_exists($field['name'], $values)) {
            kop_ie_facility_apply($op, $field, $values[$field['name']]);
        }
    }
    return $op;
}

function kop_ie_operator_load(array $p) {
    $opts = kop_ie_facility_opts();
    list($row, $json) = kop_ie_operator_row($opts['pdo'], (int) ($p[0] ?? 0));
    if (($p[1] ?? 'all') === 'raw') {
        return array(
            'title'  => $row['name'] . ': whole record',
            'help'   => 'Every field this company holds, as stored. Keep the structure; change the values.',
            'fields' => array(kop_ie_field('json', 'Record', 'code', wp_json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), array('rows' => 30))),
        );
    }
    return array(
        'title'  => $row['name'] . ': every field',
        'help'   => 'The company name is changed under KOP Data Tools, because renaming moves its page and the facilities that name it.',
        'fields' => kop_ie_operator_form($json['operator']),
        'alt'    => array('ref' => 'operator:' . (int) $row['id'] . ':raw', 'label' => 'Whole record (JSON)'),
    );
}

function kop_ie_operator_save(array $p, array $v) {
    global $wpdb;
    $id = (int) ($p[0] ?? 0);
    $raw = ($p[1] ?? 'all') === 'raw';
    $opts = kop_ie_facility_opts();
    kop_v2_with_write_lock($opts['pdo'], function () use ($opts, $id, $raw, $v, $wpdb) {
        list($row, $json) = kop_ie_operator_row($opts['pdo'], $id);
        if ($raw) {
            $new = json_decode((string) ($v['json'] ?? ''), true);
            if (!is_array($new) || !isset($new['operator']) || !is_array($new['operator'])) {
                throw new RuntimeException('That is not a valid record: ' . (json_last_error() ? json_last_error_msg() : 'operator is missing') . '.');
            }
            if ((string) ($new['operator']['name'] ?? '') !== (string) ($json['operator']['name'] ?? '')) {
                throw new RuntimeException('The name cannot be changed here; use KOP Data Tools.');
            }
            $json = $new;
        } else {
            $json['operator'] = kop_ie_operator_apply_values($json['operator'], $v);
        }
        $opts['pdo']->prepare('UPDATE `' . $wpdb->prefix . 'kop_operators` SET json_data = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?')
            ->execute(array(json_encode($json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $id));
    });
    if (function_exists('kop_operator_pages_flush_index')) {
        kop_operator_pages_flush_index();
    }
    $url = function_exists('kop_operator_page_url') ? (string) kop_operator_page_url($id) : '';
    kop_ie_purge_url($url);
    return array('message' => 'Saved.');
}

/* ---- Indigenous schools (inc/indigenous-schools.php) -------------------------- */

function kop_ie_ischool_get($id) {
    $pdo = function_exists('kop_ischools_pdo') ? kop_ischools_pdo() : null;
    $s = $pdo ? kop_ischools_get($pdo, (int) $id) : null;
    if (!$s) {
        throw new RuntimeException('That school record is gone.');
    }
    return array($pdo, $s);
}

function kop_ie_ischool_load(array $p) {
    list($pdo, $s) = kop_ie_ischool_get($p[0] ?? 0);
    return array(
        'title'  => $s['name'],
        'fields' => array(
            kop_ie_field('name', 'Name', 'text', $s['name']),
            kop_ie_field('other_names', 'Other names', 'lines', $s['other_names']),
            kop_ie_field('country', 'Country', 'text', $s['country']),
            kop_ie_field('region', 'State, province or territory', 'text', $s['region']),
            kop_ie_field('city', 'City or town', 'text', $s['city']),
            kop_ie_field('nations', 'Nations whose children were sent there', 'textarea', $s['nations']),
            kop_ie_field('run_by', 'Run by', 'text', $s['run_by']),
            kop_ie_field('opened', 'Opened (year)', 'year', (string) $s['opened']),
            kop_ie_field('closed', 'Closed (year)', 'year', (string) $s['closed']),
            kop_ie_field('status', 'Status', 'select', $s['status'], array('options' => array('Open', 'Closed', 'Unknown'))),
            kop_ie_field('notes', 'Notes', 'textarea', $s['notes'], array('rows' => 8, 'help' => 'A blank line between paragraphs; **bold**, *italic*, [link text](https://...).')),
            kop_ie_field('links', 'Links (one address a line)', 'lines', $s['links']),
        ),
    );
}

/** kop_ischools_save() writes every column, so the dialog's values go over the whole row. */
function kop_ie_ischool_save(array $p, array $v) {
    list($pdo, $s) = kop_ie_ischool_get($p[0] ?? 0);
    foreach (array('opened' => 'Opened', 'closed' => 'Closed') as $k => $label) {
        if (isset($v[$k])) {
            kop_ie_int($v[$k], $label, 1700, (int) gmdate('Y'));
        }
    }
    $f = array_merge($s, array_intersect_key($v, array_flip(array('name', 'other_names', 'country', 'region', 'city', 'nations', 'run_by', 'opened', 'closed', 'status', 'notes', 'links'))));
    unset($f['review']);
    kop_ischools_save($pdo, $f, (int) $s['id'], kop_ie_who());
    $page = get_page_by_path('indian-boarding-schools');
    if ($page) {
        kop_ie_purge_post($page->ID);
    }
    return array('message' => 'Saved.');
}

/* ---- Referrers and educational consultants (referrers_master) ----------------- */

/** A person's fields (the independent consultant, or one on an agency's list), relative to $base. */
function kop_ie_referrer_person_defs($base) {
    return array(
        array($base . '.fullName', 'Full name', 'text'),
        array($base . '.firstName', 'First name', 'text'),
        array($base . '.lastName', 'Last name', 'text'),
        array($base . '.role', 'Role', 'text'),
        array($base . '.status', 'Status', 'text'),
        array($base . '.credentials', 'Credentials', 'text'),
        array($base . '.education', 'Education', 'text'),
        array($base . '.city', 'City', 'text'),
        array($base . '.state', 'State', 'text'),
        array($base . '.email', 'Email (never shown publicly)', 'text'),
        array($base . '.phone', 'Phone (never shown publicly)', 'text'),
        array($base . '.website', 'Website', 'text'),
        array($base . '.websites', 'Other websites', 'lines'),
        array($base . '.isIndependent', 'Independent consultant', 'bool'),
        array($base . '.formerIndustryStaff', 'Worked at a troubled teen program', 'bool'),
        array($base . '.knownReferrals', 'Facility referrals', 'lines'),
        array($base . '.facilitiesReferred', 'Facilities referred (older list)', 'lines'),
        array($base . '.pastTTIJobs', 'Career history', 'rows', array('columns' => array('role' => 'Role', 'organization' => 'Program', 'employer' => 'Employer'))),
        array($base . '.affiliations', 'Affiliations', 'lines'),
        array($base . '.schoolDistricts', 'School districts', 'lines'),
        array($base . '.lawsuits', 'Lawsuits', 'textarea'),
        array($base . '.notes', 'Notes', 'textarea'),
    );
}

/** [section label, defs] for one referrer record: the organization, the individual, each consultant. */
function kop_ie_referrer_sections(array $json) {
    $d = isset($json['data']) && is_array($json['data']) ? $json['data'] : array();
    $sections = array(array('Record', array(
        array('data.referrerType', 'Type (agency, individual)', 'text'),
        array('data.isIndependentConsultant', 'Shown as an independent consultant', 'bool'),
    )));
    $sections[] = array('Organization', array(
        array('data.referrerAgency.name', 'Name', 'text'),
        array('data.referrerAgency.city', 'City', 'text'),
        array('data.referrerAgency.state', 'State', 'text'),
        array('data.referrerAgency.address', 'Address', 'text'),
        array('data.referrerAgency.founded', 'Founded', 'text'),
        array('data.referrerAgency.website', 'Website', 'text'),
        array('data.referrerAgency.websites', 'Other websites', 'lines'),
        array('data.referrerAgency.affiliations', 'Affiliations', 'lines'),
        array('data.referrerAgency.keyPersonnel', 'Key personnel', 'lines'),
        array('data.referrerAgency.notes', 'Notes', 'textarea'),
    ));
    if (isset($d['referrerIndividual']) && is_array($d['referrerIndividual'])) {
        $who = trim((string) ($d['referrerIndividual']['fullName'] ?? ''));
        $sections[] = array('Individual' . ($who !== '' ? ': ' . $who : ''), kop_ie_referrer_person_defs('data.referrerIndividual'));
    }
    foreach ((array) ($d['referrerConsultants'] ?? array()) as $i => $c) {
        if (!is_array($c)) {
            continue;
        }
        $who = trim((string) ($c['fullName'] ?? '')) ?: trim(($c['firstName'] ?? '') . ' ' . ($c['lastName'] ?? ''));
        $sections[] = array('Consultant ' . ($i + 1) . ($who !== '' ? ': ' . $who : ''), kop_ie_referrer_person_defs('data.referrerConsultants.' . $i));
    }
    return $sections;
}

function kop_ie_referrer_form(array $json) {
    $fields = array();
    foreach (kop_ie_referrer_sections($json) as $section) {
        foreach ($section[1] as $def) {
            // A person's field the record never had (formerIndustryStaff on most) is still offered.
            $f = kop_ie_facility_field($json, $def);
            $f['section'] = $section[0];
            $fields[] = $f;
        }
    }
    return $fields;
}

/** The dialog's values applied to a referrer record (scripts/test-inline-edit.php runs it on every record). */
function kop_ie_referrer_apply_values(array $json, array $values) {
    foreach (kop_ie_referrer_form($json) as $field) {
        if (array_key_exists($field['name'], $values)) {
            kop_ie_facility_apply($json, $field, $values[$field['name']]);
        }
    }
    return $json;
}

function kop_ie_referrer_row(PDO $pdo, $id) {
    $q = $pdo->prepare('SELECT id, unique_name, json_data FROM referrers_master WHERE id = ?');
    $q->execute(array((int) $id));
    $row = $q->fetch(PDO::FETCH_ASSOC);
    $json = $row ? json_decode((string) $row['json_data'], true) : null;
    if (!$row || !is_array($json)) {
        throw new RuntimeException('That referrer record is gone.');
    }
    return array($row, $json);
}

function kop_ie_referrer_pdo() {
    $pdo = function_exists('kop_seed_pdo') ? kop_seed_pdo() : null;
    if (!$pdo) {
        throw new RuntimeException('No database connection.');
    }
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    return $pdo;
}

function kop_ie_referrer_load(array $p) {
    list($row, $json) = kop_ie_referrer_row(kop_ie_referrer_pdo(), (int) ($p[0] ?? 0));
    if (($p[1] ?? 'all') === 'raw') {
        return array(
            'title'  => $row['unique_name'] . ': whole record',
            'help'   => 'Every field this record holds, as stored (add a consultant here). Keep the structure; change the values.',
            'fields' => array(kop_ie_field('json', 'Record', 'code', wp_json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), array('rows' => 30))),
        );
    }
    return array(
        'title'  => $row['unique_name'] . ': every field',
        'help'   => 'The card title is the record name and stays as it is. To add a consultant, open the whole record (JSON).',
        'fields' => kop_ie_referrer_form($json),
        'alt'    => array('ref' => 'referrer:' . (int) $row['id'] . ':raw', 'label' => 'Whole record (JSON)'),
    );
}

function kop_ie_referrer_save(array $p, array $v) {
    $pdo = kop_ie_referrer_pdo();
    $id = (int) ($p[0] ?? 0);
    list($row, $json) = kop_ie_referrer_row($pdo, $id);
    if (($p[1] ?? 'all') === 'raw') {
        $new = json_decode((string) ($v['json'] ?? ''), true);
        if (!is_array($new) || !isset($new['data']) || !is_array($new['data'])) {
            throw new RuntimeException('That is not a valid record: ' . (json_last_error() ? json_last_error_msg() : 'data is missing') . '.');
        }
        $json = $new;
    } else {
        $json = kop_ie_referrer_apply_values($json, $v);
    }
    $pdo->prepare('UPDATE referrers_master SET json_data = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?')
        ->execute(array(json_encode($json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $id));
    $page = get_page_by_path('referrers-educational-consultants');
    if ($page) {
        kop_ie_purge_post($page->ID);
    }
    return array('message' => 'Saved.');
}

/* ---- Plain table rows with no other editor (memorial, story arcs) ------------- */

/**
 * table key => array(table, title column, page slug to purge, columns). A
 * column is array(label, type[, options or extra]). Saved by a direct UPDATE
 * of the listed columns only; dates are YYYY-MM-DD or empty.
 */
function kop_ie_row_tables() {
    return array(
        'memorial' => array('memorial_victims', 'name', 'memorial', array(
            'name'               => array('Name as published ("Unknown" when not released)', 'text'),
            'age'                => array('Age at death', 'number'),
            'program'            => array('Program', 'text'),
            'date_of_death'      => array('Date of death (YYYY-MM-DD; use 01 for an unknown month or day)', 'date'),
            'date_precision'     => array('How much of the date is known', 'select', array('day', 'month', 'year', 'unknown')),
            'cause_of_death'     => array('Cause, as reported', 'textarea'),
            'cause_category'     => array('Cause, for the filter', 'select', array('', 'suicide', 'restraint', 'medical_neglect', 'overdose', 'escape_attempt', 'drowning', 'exposure', 'violence', 'accident', 'other', 'unknown')),
            'location'           => array('State, or region outside the US', 'text'),
            'source_name'        => array('Source name', 'text'),
            'source_url'         => array('Source link', 'text'),
            'kop_url'            => array('Related page on this site', 'text'),
            'notes'              => array('Internal notes (not shown)', 'textarea'),
            'publication_status' => array('Shown on the memorial', 'select', array('published', 'draft', 'archived')),
        )),
        'arc' => array('news_story_arcs', 'title', 'news', array(
            'title'          => array('Title', 'text'),
            'description'    => array('Description', 'textarea'),
            'match_terms'    => array('Phrases that file a new article under it (one a line)', 'textarea'),
            'facility_label' => array('Facility button label', 'text'),
            'facility_url'   => array('Facility button link (empty: no button)', 'text'),
            'status'         => array('Status', 'select', array('active', 'archived')),
            'display_order'  => array('Order (lower first)', 'number'),
        )),
    );
}

function kop_ie_row_get($key, $id) {
    $tables = kop_ie_row_tables();
    if (!isset($tables[$key])) {
        throw new RuntimeException('Unknown kind of record.');
    }
    $pdo = kop_ie_referrer_pdo();
    $q = $pdo->prepare('SELECT * FROM `' . $tables[$key][0] . '` WHERE id = ?');
    $q->execute(array((int) $id));
    $row = $q->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        throw new RuntimeException('That record is gone.');
    }
    return array($pdo, $tables[$key], $row);
}

function kop_ie_row_load(array $p) {
    list($pdo, $t, $row) = kop_ie_row_get($p[0] ?? '', $p[1] ?? 0);
    $fields = array();
    foreach ($t[3] as $col => $def) {
        if (!array_key_exists($col, $row)) {
            continue;
        }
        $type = $def[1] === 'date' ? 'text' : $def[1];
        $extra = $def[1] === 'select' ? array('options' => $def[2]) : array();
        $fields[] = kop_ie_field($col, $def[0], $type, (string) $row[$col], $extra);
    }
    return array('title' => (string) $row[$t[1]], 'fields' => $fields);
}

function kop_ie_row_save(array $p, array $v) {
    list($pdo, $t, $row) = kop_ie_row_get($p[0] ?? '', $p[1] ?? 0);
    $set = array();
    $params = array();
    foreach ($t[3] as $col => $def) {
        if (!array_key_exists($col, $v) || !array_key_exists($col, $row)) {
            continue;
        }
        $val = kop_ie_clean_text($v[$col]);
        switch ($def[1]) {
            case 'number':
                $val = kop_ie_int($val, $def[0], 0, 100000);
                break;
            case 'date':
                if ($val === '') {
                    $val = null;
                } elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $val) || !checkdate((int) substr($val, 5, 2), (int) substr($val, 8, 2), (int) substr($val, 0, 4))) {
                    throw new RuntimeException($def[0] . ': a date as YYYY-MM-DD, or empty.');
                }
                break;
            case 'select':
                if (!in_array($val, $def[2], true)) {
                    throw new RuntimeException($def[0] . ': pick one of the choices.');
                }
                $val = $val === '' ? null : $val;
                break;
            case 'text':
                $val = trim(preg_replace('/\s+/', ' ', $val));
                break;
        }
        if ($col === $t[1] && ($val === '' || $val === null)) {
            throw new RuntimeException($def[0] . ' cannot be empty.');
        }
        if ($val === '' && $row[$col] === null) {
            $val = null;   // left blank: stays empty, not ''
        }
        // Unchanged, or only cleaned (line endings, edge spaces): left exactly as stored.
        $stored = $row[$col] === null ? null : trim(str_replace(array("\r\n", "\r"), "\n", (string) $row[$col]));
        if ($def[1] === 'text' && $stored !== null) {
            $stored = trim(preg_replace('/\s+/', ' ', $stored));
        }
        if ($val === $row[$col] || ($val !== null && $stored !== null && (string) $val === $stored)) {
            continue;
        }
        $set[] = "`$col` = ?";
        $params[] = $val;
    }
    if ($set) {
        $params[] = (int) $row['id'];
        $pdo->prepare('UPDATE `' . $t[0] . '` SET ' . implode(', ', $set) . ' WHERE id = ?')->execute($params);
    }
    $page = get_page_by_path($t[2]);
    if ($page) {
        kop_ie_purge_post($page->ID);
    }
    return array('message' => 'Saved.');
}

/* ---- Text written in the templates --------------------------------------------- */

/*
 * A line or a section a template prints from code: kop_text('key', 'default')
 * for a line, kop_ie_html_start('key', 'label') ... kop_ie_html_end() around a
 * block of HTML. A saved version (option kop_inline_texts) replaces the code's
 * wording; saving it empty or unchanged puts the code's wording back. The
 * code's wording is noted (kop_inline_text_defaults) whenever an admin views
 * the page, so the dialog can show it and compare against it.
 */

function kop_ie_texts() {
    static $texts = null;
    if ($texts === null) {
        $texts = get_option('kop_inline_texts');
        $texts = is_array($texts) ? $texts : array();
    }
    return $texts;
}

function kop_ie_text_note_default($key, $default, $html, $label) {
    if (!kop_ie_can()) {
        return;
    }
    $all = get_option('kop_inline_text_defaults');
    $all = is_array($all) ? $all : array();
    $entry = array('default' => (string) $default, 'html' => (bool) $html, 'label' => (string) $label);
    if (($all[$key] ?? null) !== $entry) {
        $all[$key] = $entry;
        update_option('kop_inline_text_defaults', $all, false);
    }
}

/** A line of template text: the saved wording, or the code's. Escape it as usual. */
function kop_text($key, $default, $label = '') {
    kop_ie_text_note_default($key, $default, false, $label);
    $texts = kop_ie_texts();
    return isset($texts[$key]) ? (string) $texts[$key] : (string) $default;
}

/** The marker for the element holding kop_text($key). */
function kop_text_attr($key, $label = '') {
    return kop_ie_attr('txt:' . $key, $label);
}

$GLOBALS['kop_ie_html_stack'] = array();

/** Start a block of template HTML that admins can edit in place. */
function kop_ie_html_start($key, $label = '') {
    $GLOBALS['kop_ie_html_stack'][] = array($key, $label);
    ob_start();
}

/** End it: print the saved HTML, or the template's. */
function kop_ie_html_end() {
    $default = ob_get_clean();
    list($key, $label) = array_pop($GLOBALS['kop_ie_html_stack']);
    kop_ie_text_note_default($key, $default, true, $label);
    $texts = kop_ie_texts();
    $html = isset($texts[$key]) ? wp_kses_post($texts[$key]) : $default;
    if (kop_ie_can()) {
        $html = '<div class="kop-ie-block"' . kop_ie_attr('txt:' . $key, $label) . '>' . $html . '</div>';
    }
    echo $html;
}

function kop_ie_txt_entry($key) {
    $all = get_option('kop_inline_text_defaults');
    if (!is_array($all) || !isset($all[$key])) {
        throw new RuntimeException('This text is not known yet. Reload the page and try again.');
    }
    return $all[$key];
}

function kop_ie_txt_load(array $p) {
    $key = implode(':', $p);
    $entry = kop_ie_txt_entry($key);
    $texts = kop_ie_texts();
    $value = isset($texts[$key]) ? (string) $texts[$key] : $entry['default'];
    return array(
        'title'  => $entry['label'] !== '' ? $entry['label'] : 'Page text',
        'help'   => ($entry['html'] ? 'The HTML of this part of the page. Change the words; keep the <tags>. ' : '')
            . 'Saving it empty puts the original wording back.' . (isset($texts[$key]) ? ' It has been changed here before.' : ''),
        'fields' => array(kop_ie_field('text', $entry['html'] ? 'HTML' : 'Text', $entry['html'] ? 'code' : (strlen($value) > 90 ? 'textarea' : 'text'), $value,
            $entry['html'] ? array('rows' => 14) : array())),
    );
}

function kop_ie_txt_save(array $p, array $v) {
    $key = implode(':', $p);
    $entry = kop_ie_txt_entry($key);
    $text = (string) ($v['text'] ?? '');
    $text = $entry['html'] ? trim(wp_kses_post(str_replace(array("\r\n", "\r"), "\n", $text))) : trim(preg_replace('/\s+/', ' ', kop_ie_clean_text($text)));
    $all = get_option('kop_inline_texts');
    $all = is_array($all) ? $all : array();
    if ($text === '' || $text === trim($entry['default'])) {
        unset($all[$key]);
    } else {
        $all[$key] = $text;
    }
    update_option('kop_inline_texts', $all, false);
    do_action('litespeed_purge_all');
    return array('message' => 'Saved.');
}

/* ---- Site name and tagline (WordPress settings) ------------------------------- */

function kop_ie_site_load(array $p) {
    return array(
        'title'  => 'Site name and tagline',
        'fields' => array(
            kop_ie_field('blogname', 'Site name', 'text', get_option('blogname')),
            kop_ie_field('blogdescription', 'Tagline', 'text', get_option('blogdescription')),
        ),
    );
}

function kop_ie_site_save(array $p, array $v) {
    $name = trim(sanitize_text_field((string) ($v['blogname'] ?? '')));
    if ($name === '') {
        throw new RuntimeException('The site needs a name.');
    }
    update_option('blogname', $name);
    update_option('blogdescription', trim(sanitize_text_field((string) ($v['blogdescription'] ?? ''))));
    do_action('litespeed_purge_all');
    return array('message' => 'Saved.');
}

/* ---- Young adult programs (inc/young-adult-programs.php) ---------------------- */

function kop_ie_ya_get($id) {
    $pdo = function_exists('kop_ya_pdo') ? kop_ya_pdo() : null;
    $p = $pdo ? kop_ya_get($pdo, (int) $id) : null;
    if (!$p) {
        throw new RuntimeException('That program record is gone.');
    }
    return array($pdo, $p);
}

function kop_ie_ya_load(array $p) {
    list($pdo, $r) = kop_ie_ya_get($p[0] ?? 0);
    return array(
        'title'  => $r['name'],
        'help'   => 'Facts filed from Woodbury Reports are added and taken off under KOP Tools > Young Adult Programs.',
        'fields' => array(
            kop_ie_field('name', 'Name', 'text', $r['name']),
            kop_ie_field('other_names', 'Other names', 'lines', $r['other_names']),
            kop_ie_field('city', 'City', 'text', $r['city']),
            kop_ie_field('state', 'State', 'text', $r['state']),
            kop_ie_field('country', 'Country', 'text', $r['country']),
            kop_ie_field('ages', 'Ages', 'text', $r['ages']),
            kop_ie_field('program_type', 'Type of program', 'text', $r['program_type']),
            kop_ie_field('run_by', 'Run by', 'text', $r['run_by']),
            kop_ie_field('opened', 'Opened (year)', 'year', (string) $r['opened']),
            kop_ie_field('closed', 'Closed (year)', 'year', (string) $r['closed']),
            kop_ie_field('status', 'Status', 'select', $r['status'], array('options' => array('Open', 'Closed', 'Unknown'))),
            kop_ie_field('notes', 'Notes', 'textarea', $r['notes'], array('rows' => 8, 'help' => 'A blank line between paragraphs; **bold**, *italic*, [link text](https://...).')),
            kop_ie_field('links', 'Links (one address a line)', 'lines', $r['links']),
        ),
    );
}

/** kop_ya_save() writes every column, so the dialog's values go over the whole row. */
function kop_ie_ya_save(array $p, array $v) {
    list($pdo, $r) = kop_ie_ya_get($p[0] ?? 0);
    foreach (array('opened' => 'Opened', 'closed' => 'Closed') as $k => $label) {
        if (isset($v[$k])) {
            kop_ie_int($v[$k], $label, 1850, (int) gmdate('Y') + 1);
        }
    }
    $f = array_merge($r, array_intersect_key($v, array_flip(array('name', 'other_names', 'city', 'state', 'country', 'ages', 'program_type', 'run_by', 'opened', 'closed', 'status', 'notes', 'links'))));
    unset($f['review']);
    kop_ya_save($pdo, $f, (int) $r['id'], kop_ie_who());
    $page = get_page_by_path('young-adult-programs');
    if ($page) {
        kop_ie_purge_post($page->ID);
    }
    return array('message' => 'Saved.');
}

/* ---- Documents in the media library (every document tile) ---------------------- */

function kop_ie_doc_get($id) {
    $post = get_post((int) $id);
    if (!$post || $post->post_type !== 'attachment' || !current_user_can('edit_post', $post->ID)) {
        throw new RuntimeException('That document cannot be edited here.');
    }
    return $post;
}

/** doc:<id>:h<folder> -> the folder of the page the tile is on, 0 when the ref has none. */
function kop_ie_doc_home(array $p) {
    return isset($p[1]) && preg_match('/^h(\d+)$/', (string) $p[1], $m) ? (int) $m[1] : 0;
}

function kop_ie_doc_load(array $p) {
    $post = kop_ie_doc_get($p[0] ?? 0);
    $fields = array(
        kop_ie_field('title', 'Title', 'text', $post->post_title),
        kop_ie_field('caption', 'Caption (credit or source line)', 'textarea', $post->post_excerpt),
        kop_ie_field('description', 'Description', 'textarea', $post->post_content),
        kop_ie_field('alt', 'Alt text (what an image shows, for screen readers)', 'text', (string) get_post_meta($post->ID, '_wp_attachment_image_alt', true)),
    );
    // Where it is filed, and moving or removing it (inc/doc-placement.php).
    if (function_exists('kop_dp_fields')) {
        foreach ($fields as &$f) {
            $f['section'] = 'Title and description';
        }
        unset($f);
        $fields = array_merge($fields, kop_dp_fields($post->ID, kop_ie_doc_home($p)));
    }
    return array(
        'title'  => 'Document: ' . $post->post_title,
        'help'   => 'The title shows on every tile and list of this document across the site.',
        'fields' => $fields,
    );
}

function kop_ie_doc_save(array $p, array $v) {
    $post = kop_ie_doc_get($p[0] ?? 0);
    $title = trim(preg_replace('/\s+/', ' ', kop_ie_clean_text($v['title'] ?? $post->post_title)));
    if ($title === '') {
        throw new RuntimeException('The title cannot be empty.');
    }
    $result = wp_update_post(wp_slash(array(
        'ID'           => $post->ID,
        'post_title'   => $title,
        'post_excerpt' => kop_ie_clean_text($v['caption'] ?? $post->post_excerpt),
        'post_content' => kop_ie_clean_text($v['description'] ?? $post->post_content),
    )), true);
    if (is_wp_error($result)) {
        throw new RuntimeException($result->get_error_message());
    }
    if (array_key_exists('alt', $v)) {
        update_post_meta($post->ID, '_wp_attachment_image_alt', wp_slash(trim(preg_replace('/\s+/', ' ', kop_ie_clean_text($v['alt'])))));
    }
    do_action('litespeed_purge_all');
    // Then move, remove or delete it, when asked (inc/doc-placement.php).
    if (function_exists('kop_dp_apply')) {
        $placed = kop_dp_apply($post->ID, kop_ie_doc_home($p), $v);
        if ($placed) {
            return $placed;
        }
    }
    return array('message' => 'Saved.');
}

/* ---- Where to report abuse (inc/reporting-directory.php) ----------------------- */

/** A channel's fields: name => array(label, type[, options]). */
function kop_ie_rep_fields() {
    return array(
        'name'               => array('Agency or service', 'text'),
        'category'           => array('Kind', 'select', array('professional-board', 'facility-licensing', 'legal', 'oversight')),
        'profession'         => array('Profession it licenses (boards)', 'text'),
        'what_it_can_do'     => array('What it can do', 'textarea'),
        'what_it_cannot_do'  => array('What it cannot do', 'textarea'),
        'who_to_report'      => array('Who to report to it', 'textarea'),
        'how'                => array('How to report', 'textarea'),
        'complaint_url'      => array('Complaint form link', 'text'),
        'info_url'           => array('More information link', 'text'),
        'phone'              => array('Phone', 'text'),
        'phone_note'         => array('Phone note', 'text'),
        'email'              => array('Email', 'text'),
        'mail'               => array('Postal address', 'text'),
        'anonymous'          => array('Anonymous reports', 'select', array('unknown', 'allowed', 'discouraged', 'not-allowed')),
        'mandatory_reporter' => array('Mandatory reporters report here', 'bool'),
        'deadline'           => array('Deadline', 'textarea'),
        'note'               => array('Note', 'textarea'),
        'sources'            => array('Sources (one link a line; at least one)', 'lines'),
        'verified_on'        => array('Checked on (YYYY-MM-DD)', 'text'),
    );
}

/** The channel by id: [edited, as deployed]. */
function kop_ie_rep_channel($id) {
    $find = function ($dir) use ($id) {
        if (!$dir) {
            return null;
        }
        foreach (array_merge(array($dir['national']['channels'] ?? array()), array_map(function ($s) { return $s['channels'] ?? array(); }, $dir['states'])) as $list) {
            foreach ($list as $c) {
                if (($c['id'] ?? '') === $id) {
                    return $c;
                }
            }
        }
        return null;
    };
    $base = $find(kop_reporting_directory_raw());
    if (!$base) {
        throw new RuntimeException('That entry is no longer in the directory.');
    }
    return array($find(kop_reporting_directory()), $base);
}

function kop_ie_rep_note_record($key) {
    $dir = kop_reporting_directory_raw();
    if ($key === 'national') {
        return array('National', (string) ($dir['national']['note'] ?? ''));
    }
    foreach ((array) ($dir['states'] ?? array()) as $s) {
        if ($s['abbr'] === $key) {
            return array($s['state'], (string) ($s['note'] ?? ''));
        }
    }
    throw new RuntimeException('Unknown state.');
}

function kop_ie_rep_load(array $p) {
    $kind = $p[0] ?? '';
    $key = implode(':', array_slice($p, 1));
    $edits = kop_reporting_edits();
    $help = 'Saved here over the deployed js/data/reporting files and live at once; the next rebuild of directory.json keeps them.';
    if ($kind === 'note') {
        list($label, $base) = kop_ie_rep_note_record($key);
        return array('title' => $label . ': note', 'help' => $help . ' Saving it empty puts the original back.',
            'fields' => array(kop_ie_field('note', 'Note', 'textarea', $edits['note'][$key] ?? $base, array('rows' => 6))));
    }
    list($c) = kop_ie_rep_channel($key);
    $fields = array();
    foreach (kop_ie_rep_fields() as $name => $def) {
        $v = $c[$name] ?? '';
        if ($def[1] === 'lines') {
            $v = implode("\n", (array) $v);
        }
        $extra = $def[1] === 'select' ? array('options' => $def[2]) : array();
        $fields[] = kop_ie_field($name, $def[0], $def[1], $def[1] === 'bool' ? !empty($v) : (string) $v, $extra);
    }
    return array('title' => $c['name'], 'help' => $help, 'fields' => $fields);
}

function kop_ie_rep_save(array $p, array $v) {
    $kind = $p[0] ?? '';
    $key = implode(':', array_slice($p, 1));
    $edits = kop_reporting_edits();
    if ($kind === 'note') {
        list($label, $base) = kop_ie_rep_note_record($key);
        $note = trim(preg_replace('/\s+/', ' ', kop_ie_clean_text($v['note'] ?? '')));
        if ($note === '' || $note === $base) {
            unset($edits['note'][$key]);
        } else {
            $edits['note'][$key] = $note;
        }
    } else {
        list($c, $base) = kop_ie_rep_channel($key);
        $mine = kop_ie_rep_changes($base, $v);
        if ($mine) {
            $edits['ch'][$key] = $mine;
        } else {
            unset($edits['ch'][$key]);
        }
    }
    update_option('kop_reporting_edits', $edits, false);
    do_action('litespeed_purge_all');
    return array('message' => 'Saved.');
}

/** The dialog's values against the deployed entry: the fields that differ (checked), field => value ('' removes). */
function kop_ie_rep_changes(array $base, array $v) {
    $mine = array();
    foreach (kop_ie_rep_fields() as $name => $def) {
        if (!array_key_exists($name, $v)) {
            continue;
        }
        switch ($def[1]) {
            case 'lines':
                $new = kop_ie_lines($v[$name]);
                break;
            case 'bool':
                $new = $v[$name] === true || $v[$name] === 'true' || $v[$name] === '1';
                break;
            case 'select':
                $new = (string) $v[$name];
                if (!in_array($new, $def[2], true)) {
                    throw new RuntimeException($def[0] . ': pick one of the choices.');
                }
                break;
            default:
                $new = trim(preg_replace('/\s+/', ' ', kop_ie_clean_text($v[$name])));
        }
        $was = $base[$name] ?? ($def[1] === 'bool' ? false : ($def[1] === 'lines' ? array() : ''));
        if ($new === $was || ($def[1] === 'bool' && $new === (bool) $was)) {
            continue;
        }
        $mine[$name] = $def[1] === 'bool' && !$new ? '' : $new;
    }
    $after = array_merge($base, $mine);
    foreach (array('name', 'what_it_can_do', 'who_to_report') as $req) {
        if (trim((string) ($after[$req] ?? '')) === '') {
            throw new RuntimeException(kop_ie_rep_fields()[$req][0] . ' cannot be empty.');
        }
    }
    if (!array_filter((array) ($after['sources'] ?? array()))) {
        throw new RuntimeException('Every entry needs at least one source link.');
    }
    foreach ((array) $after['sources'] as $url) {
        if (!preg_match('#^https?://#i', $url)) {
            throw new RuntimeException('Sources: "' . $url . '" is not a link.');
        }
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($after['verified_on'] ?? ''))) {
        throw new RuntimeException('Checked on: a date as YYYY-MM-DD.');
    }
    return $mine;
}

/* ---- Hub and utility page settings (string lines in code) -------------------- */

/** Saved overrides: array('hub:<slug>' => array(dotted path => text)). */
function kop_ie_cfg_overrides() {
    $o = get_option('kop_inline_text_overrides');
    return is_array($o) ? $o : array();
}

$GLOBALS['kop_ie_cfg_raw'] = false;

function kop_ie_cfg_apply($config, $key) {
    if (!empty($GLOBALS['kop_ie_cfg_raw']) || !is_array($config)) {
        return $config;
    }
    $o = kop_ie_cfg_overrides();
    foreach ((array) ($o[$key] ?? array()) as $path => $text) {
        if (is_string(kop_ie_get_path($config, $path))) {
            kop_ie_set_path($config, $path, (string) $text);
        }
    }
    return $config;
}

add_filter('kop_hub_config', function ($hubs) {
    foreach ($hubs as $slug => $cfg) {
        $hubs[$slug] = kop_ie_cfg_apply($cfg, 'hub:' . $slug);
    }
    return $hubs;
}, 99);

add_filter('kop_utility_config', function ($config, $slug) {
    return kop_ie_cfg_apply($config, 'utility:' . $slug);
}, 99, 2);

function kop_ie_cfg_base($kind, $slug) {
    $GLOBALS['kop_ie_cfg_raw'] = true;
    try {
        if ($kind === 'hub' && function_exists('kop_hub_config')) {
            return kop_hub_config($slug);
        }
        if ($kind === 'utility' && function_exists('kop_utility_config')) {
            return kop_utility_config($slug);
        }
    } finally {
        $GLOBALS['kop_ie_cfg_raw'] = false;
    }
    throw new RuntimeException('Unknown settings.');
}

/** Every text line in a settings array: dotted path => text. Callbacks, slugs and template names are not text. */
function kop_ie_cfg_strings($config, $prefix = '') {
    $out = array();
    foreach ((array) $config as $k => $v) {
        $path = $prefix === '' ? (string) $k : $prefix . '.' . $k;
        if (is_array($v)) {
            $out += kop_ie_cfg_strings($v, $path);
        } elseif (is_string($v) && !in_array((string) $k, array('updated', 'slug', 'template', 'path'), true) && !is_callable($v)) {
            $out[$path] = $v;
        }
    }
    return $out;
}

function kop_ie_cfg_label($path) {
    $bits = array();
    foreach (explode('.', $path) as $b) {
        $bits[] = ctype_digit($b) ? '#' . ((int) $b + 1) : ucfirst(str_replace(array('_', '-'), ' ', $b));
    }
    return implode(' › ', $bits);
}

function kop_ie_cfg_load(array $p) {
    $kind = (string) ($p[0] ?? '');
    $slug = (string) ($p[1] ?? '');
    $base = kop_ie_cfg_strings(kop_ie_cfg_base($kind, $slug));
    if (!$base) {
        throw new RuntimeException('This page has no settings text.');
    }
    $o = kop_ie_cfg_overrides();
    $mine = (array) ($o[$kind . ':' . $slug] ?? array());
    $fields = array();
    foreach ($base as $path => $text) {
        $value = isset($mine[$path]) ? (string) $mine[$path] : $text;
        $fields[] = kop_ie_field($path, kop_ie_cfg_label($path), strlen($text) > 90 ? 'textarea' : 'text', $value,
            isset($mine[$path]) ? array('help' => 'Changed here. Original: ' . $text) : array());
    }
    return array(
        'title'  => 'Page settings: ' . $slug,
        'help'   => 'The lines this page prints around its content: the standfirst, headings, button labels and notes. Clearing a box puts the original back.',
        'fields' => $fields,
    );
}

function kop_ie_cfg_save(array $p, array $v) {
    $kind = (string) ($p[0] ?? '');
    $slug = (string) ($p[1] ?? '');
    $base = kop_ie_cfg_strings(kop_ie_cfg_base($kind, $slug));
    $o = kop_ie_cfg_overrides();
    $mine = (array) ($o[$kind . ':' . $slug] ?? array());
    foreach ($base as $path => $text) {
        if (!array_key_exists($path, $v)) {
            continue;
        }
        $new = kop_ie_clean_text($v[$path]);
        if ($new === '' || $new === $text) {
            unset($mine[$path]);
        } else {
            $mine[$path] = $new;
        }
    }
    if ($mine) {
        $o[$kind . ':' . $slug] = $mine;
    } else {
        unset($o[$kind . ':' . $slug]);
    }
    update_option('kop_inline_text_overrides', $o, false);
    $page = get_page_by_path($slug);
    if ($page) {
        kop_ie_purge_post($page->ID);
    }
    return array('message' => 'Saved.');
}

/* ---- REST --------------------------------------------------------------------- */

add_action('rest_api_init', function () {
    register_rest_route('kop/v1', '/inline-edit', array(
        array(
            'methods'             => 'GET',
            'permission_callback' => function () {
                return current_user_can(KOP_IE_CAP);
            },
            'callback'            => function (WP_REST_Request $r) {
                try {
                    list($source, $parts) = kop_ie_resolve((string) $r->get_param('ref'));
                    return rest_ensure_response(call_user_func($source['load'], $parts));
                } catch (Throwable $e) {
                    return new WP_Error('kop_ie', $e->getMessage(), array('status' => 400));
                }
            },
        ),
        array(
            'methods'             => 'POST',
            'permission_callback' => function () {
                return current_user_can(KOP_IE_CAP);
            },
            'callback'            => function (WP_REST_Request $r) {
                $files = $r->get_file_params();
                $raw = !empty($files['payload']['tmp_name']) && is_uploaded_file($files['payload']['tmp_name'])
                    ? (string) file_get_contents($files['payload']['tmp_name'])
                    : (string) $r->get_param('payload');
                $body = json_decode($raw, true);
                if (!is_array($body) || !isset($body['ref']) || !isset($body['values']) || !is_array($body['values'])) {
                    return new WP_Error('kop_ie', 'Nothing to save.', array('status' => 400));
                }
                try {
                    list($source, $parts) = kop_ie_resolve((string) $body['ref']);
                    if (empty($source['save'])) {
                        throw new RuntimeException('This kind of field saves elsewhere.');
                    }
                    return rest_ensure_response(call_user_func($source['save'], $parts, $body['values']));
                } catch (Throwable $e) {
                    return new WP_Error('kop_ie', $e->getMessage(), array('status' => 400));
                }
            },
        ),
    ));
});

/* ---- Front end ------------------------------------------------------------------ */

/** Page-level editors for the toolbar: [{ref, label}]. */
function kop_ie_page_refs() {
    $refs = array();
    if (!empty($GLOBALS['kop_facility_page']['id'])) {
        $fid = (int) $GLOBALS['kop_facility_page']['id'];
        $refs[] = array('ref' => 'facility:' . $fid . ':all', 'label' => 'Every field of this facility');
        $refs[] = array('ref' => 'facility:' . $fid . ':raw', 'label' => 'Whole record (JSON)');
        return $refs;
    }
    if (!empty($GLOBALS['kop_operator_page']['id'])) {
        $oid = (int) $GLOBALS['kop_operator_page']['id'];
        $refs[] = array('ref' => 'operator:' . $oid . ':all', 'label' => 'Every field of this company');
        $refs[] = array('ref' => 'operator:' . $oid . ':raw', 'label' => 'Whole record (JSON)');
        return $refs;
    }
    if (is_singular()) {
        $id = get_queried_object_id();
        if ($id && current_user_can('edit_post', $id)) {
            $refs[] = array('ref' => 'post:' . $id . ':page', 'label' => 'Title, standfirst and whole content');
        }
        $slug = (string) get_post_field('post_name', $id);
        $template = (string) get_page_template_slug($id);
        if ($template === 'templates/page-hub.php' && function_exists('kop_hub_config') && kop_ie_cfg_strings(kop_ie_cfg_base('hub', $slug))) {
            $refs[] = array('ref' => 'cfg:hub:' . $slug, 'label' => 'Hub settings (standfirst, buttons, lists)');
        }
        if ($template === 'templates/page-utility.php' && function_exists('kop_utility_config') && kop_ie_cfg_strings(kop_ie_cfg_base('utility', $slug))) {
            $refs[] = array('ref' => 'cfg:utility:' . $slug, 'label' => 'Page settings (standfirst)');
        }
        if ($id && current_user_can('edit_post', $id)) {
            $refs[] = array('ref' => '', 'label' => 'Open in the WordPress editor', 'url' => get_edit_post_link($id, 'raw'));
        }
    }
    return $refs;
}

add_action('wp_enqueue_scripts', function () {
    if (!kop_ie_can()) {
        return;
    }
    $dir = get_stylesheet_directory();
    $uri = get_stylesheet_directory_uri();
    wp_enqueue_style('kop-inline-edit', $uri . '/css/inline-edit.css', array(), filemtime($dir . '/css/inline-edit.css'));
    wp_enqueue_script('kop-inline-edit', $uri . '/js/inline-edit.js', array(), filemtime($dir . '/js/inline-edit.js'), true);
}, 50);

/* The page-level refs depend on the template's globals, so they are printed late. */
add_action('wp_footer', function () {
    if (!kop_ie_can() || !wp_script_is('kop-inline-edit', 'enqueued')) {
        return;
    }
    $config = array(
        'rest'     => esc_url_raw(rest_url('kop/v1/inline-edit')),
        'nonce'    => wp_create_nonce('wp_rest'),
        'pageRefs' => kop_ie_page_refs(),
        'placeSearch' => esc_url_raw(rest_url('kop/v1/doc-place')),
    );
    echo '<script>window.KOP_INLINE_EDIT = ' . wp_json_encode($config) . ';</script>' . "\n";
}, 5);
