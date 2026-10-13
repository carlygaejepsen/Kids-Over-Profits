<?php
/**
 * Document types: what kind of document each file in the media library is
 * (a student handbook, an inspection report, an enrollment agreement, a news
 * clipping, a legal filing...), so every document of one kind can be read
 * side by side across programs.
 *
 *   types     kop_dt_types(): slug => {label, plural, group, desc}. A document
 *             can have more than one (a handbook bound with an enrollment
 *             packet). Filterable; a new type is one more entry there
 *   store     {prefix}kop_doc_types: one row per document and type, with how
 *             it got there (rule / ai / person). Every change is an entry in
 *             the kop_doc_types_log option with each document's types before
 *             and after, so KOP Tools > Document Types can undo it exactly
 *   rules     kop_dt_classify(): the folder a document is filed in (nearest
 *             folder first: "Enrollment Materials", "Lawsuits", "va_pdfs"...)
 *             and very plain titles ("2012-2013 NATSAP Profile", "Deposition
 *             of ...") type most documents at once. Applied without review;
 *             spot-checked in Review inbox > Document types > Typed by rule
 *   AI        what the rules cannot type is read by the AI (title, folder,
 *             the first pages' text; kop_ai_generate_alternating()) hourly.
 *             Its guess waits in Review inbox > Document types until approved
 *   suggestions {prefix}kop_doc_type_suggestions, one row per document the
 *             rules have looked at and found nothing for: norule (waiting for
 *             the AI), pending (AI guess to review), unclear (AI could not
 *             tell), approved, rejected, cleared (a person took every type off:
 *             nothing types it again)
 *   readers   /documents/ (every type with its count) and /documents/<type>/
 *             (every document of that type, grouped by program, filterable
 *             by state, program and year; templates/document-types.php);
 *             a chip on every document tile and a type filter over the
 *             facility and company libraries (inc/features.php,
 *             js/doc-type-filter.js); the pencil on a tile sets the types
 *             (inc/inline-edit.php); 'types' in the app's document payloads
 *
 * php -d extension=pdo_sqlite -d extension=mbstring scripts/test-doc-types.php [--list]
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('KOP_DOC_TYPES_DB_VERSION')) define('KOP_DOC_TYPES_DB_VERSION', '1');
if (!defined('KOP_DOC_TYPES_REWRITE_VERSION')) define('KOP_DOC_TYPES_REWRITE_VERSION', '1');
// Bump after changing kop_dt_rules(): documents the old rules found nothing for are looked at again.
if (!defined('KOP_DOC_TYPES_RULES_VERSION')) define('KOP_DOC_TYPES_RULES_VERSION', '1');

// ---------------------------------------------------------------------------
// The types
// ---------------------------------------------------------------------------

if (!function_exists('kop_dt_types')) {
    /** slug => {label, plural, group, desc}, in the order pages list them. */
    function kop_dt_types() {
        static $memo = null;
        if ($memo !== null) return $memo;
        $g_prog = 'The programs\' own papers';
        $g_over = 'Oversight';
        $g_court = 'Courts';
        $g_press = 'Press, research and survivors';
        $g_biz = 'Business and industry';
        $types = array(
            'student-handbook' => array('Student or resident handbook', 'Student and resident handbooks', $g_prog,
                'Rulebooks, level systems and phase charts given to the young people in a program.'),
            'parent-handbook'  => array('Parent or family handbook', 'Parent and family handbooks', $g_prog,
                'Manuals, guides and booklets telling parents how the program works and what is expected of them.'),
            'staff-manual'     => array('Staff manual, training or policy', 'Staff manuals, training and policies', $g_prog,
                'Employee handbooks, training material and written policies for the people who worked there.'),
            'enrollment'       => array('Enrollment document', 'Enrollment documents', $g_prog,
                'Enrollment agreements, applications, intake forms, tuition contracts and consent forms.'),
            'marketing'        => array('Brochure or marketing', 'Brochures and marketing', $g_prog,
                'Brochures, viewbooks, flyers and other material written to sell a program to parents and referrers.'),
            'newsletter'       => array('Newsletter or press release', 'Newsletters and press releases', $g_prog,
                'A program\'s or company\'s own newsletters, statements and press releases.'),
            'website-capture'  => array('Website capture', 'Website captures', $g_prog,
                'Saved copies of program, company and directory web pages.'),
            'inspection-report' => array('Inspection or licensing report', 'Inspection and licensing reports', $g_over,
                'State inspection, licensing, monitoring and complaint investigation reports.'),
            'incident-report'  => array('Police or incident report', 'Police and incident reports', $g_over,
                'Police reports, incident reports and serious occurrence reports.'),
            'government-report' => array('Government report or investigation', 'Government reports and investigations', $g_over,
                'Congressional, GAO, Inspector General and state agency reports, audits and investigations.'),
            'legislation'      => array('Legislation or testimony', 'Legislation and testimony', $g_over,
                'Bills, statutes, regulations and testimony given to lawmakers.'),
            'legal-filing'     => array('Legal filing', 'Legal filings', $g_court,
                'Complaints, answers, motions, orders, rulings and settlements filed in court.'),
            'deposition'       => array('Deposition or discovery', 'Depositions and discovery', $g_court,
                'Deposition transcripts, declarations and documents produced in discovery.'),
            'news-clipping'    => array('News clipping', 'News clippings', $g_press,
                'Newspaper and magazine articles, saved as they were printed or published.'),
            'woodbury'         => array('Woodbury Reports page', 'Woodbury Reports pages', $g_press,
                'Pages of Woodbury Reports, the educational consultants\' trade newsletter.'),
            'research'         => array('Academic research', 'Academic research', $g_press,
                'Studies, theses, books and journal articles.'),
            'survivor-account' => array('Survivor account or testimony', 'Survivor accounts and testimony', $g_press,
                'Accounts written or given by people who were sent to programs, and their families.'),
            'business-record'  => array('Business or property record', 'Business and property records', $g_biz,
                'Corporate filings, business registrations, deeds, liens and property records.'),
            'tax-filing'       => array('Tax filing (Form 990)', 'Tax filings (Form 990)', $g_biz,
                'Nonprofit tax returns and other financial filings.'),
            'natsap-profile'   => array('NATSAP profile', 'NATSAP profiles', $g_biz,
                'A program\'s entry in the NATSAP directory, in the program\'s own words.'),
            'directory'        => array('Directory or member list', 'Directories and member lists', $g_biz,
                'Trade group member lists, referral directories and conference programs.'),
            'staff-profile'    => array('Staff profile or résumé', 'Staff profiles and résumés', $g_biz,
                'LinkedIn pages, résumés and staff biographies.'),
            'other'            => array('Other', 'Other documents', 'Other',
                'Documents that fit none of the types above.'),
        );
        $out = array();
        foreach ($types as $slug => $t) {
            $out[$slug] = array('label' => $t[0], 'plural' => $t[1], 'group' => $t[2], 'desc' => $t[3]);
        }
        $memo = function_exists('apply_filters') ? (array) apply_filters('kop_doc_types', $out) : $out;
        return $memo;
    }
}

if (!function_exists('kop_dt_label')) {
    function kop_dt_label($slug) {
        $t = kop_dt_types();
        return isset($t[$slug]) ? $t[$slug]['label'] : (string) $slug;
    }
}

if (!function_exists('kop_dt_clean_types')) {
    /** Known slugs only, each once, in the list's order. */
    function kop_dt_clean_types($types) {
        $want = array();
        foreach ((array) $types as $t) {
            $t = strtolower(trim((string) $t));
            if ($t !== '') $want[$t] = true;
        }
        return array_values(array_filter(array_keys(kop_dt_types()), function ($slug) use ($want) { return isset($want[$slug]); }));
    }
}

// ---------------------------------------------------------------------------
// Store
// ---------------------------------------------------------------------------

function kop_dt_table() {
    global $wpdb;
    return $wpdb->prefix . 'kop_doc_types';
}

function kop_dt_sugg_table() {
    global $wpdb;
    return $wpdb->prefix . 'kop_doc_type_suggestions';
}

if (!function_exists('kop_dt_install')) {
    function kop_dt_install() {
        if (!function_exists('get_option') || get_option('kop_doc_types_db') === KOP_DOC_TYPES_DB_VERSION) return;
        if (!function_exists('dbDelta')) {
            if (!defined('ABSPATH') || !file_exists(ABSPATH . 'wp-admin/includes/upgrade.php')) return;
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        }
        global $wpdb;
        $collate = $wpdb->get_charset_collate();
        $t = kop_dt_table();
        $s = kop_dt_sugg_table();
        dbDelta("CREATE TABLE $t (
            attachment_id BIGINT UNSIGNED NOT NULL,
            type VARCHAR(40) NOT NULL,
            source VARCHAR(10) NOT NULL DEFAULT '',
            note VARCHAR(255) NOT NULL DEFAULT '',
            created_by VARCHAR(60) NOT NULL DEFAULT '',
            created_at DATETIME NOT NULL,
            PRIMARY KEY  (attachment_id, type),
            KEY type (type)
        ) $collate;");
        dbDelta("CREATE TABLE $s (
            attachment_id BIGINT UNSIGNED NOT NULL,
            status VARCHAR(10) NOT NULL,
            types VARCHAR(191) NOT NULL DEFAULT '',
            why TEXT NULL,
            provider VARCHAR(20) NOT NULL DEFAULT '',
            rules_version VARCHAR(10) NOT NULL DEFAULT '',
            created_at DATETIME NOT NULL,
            reviewed_by VARCHAR(60) NOT NULL DEFAULT '',
            reviewed_at DATETIME NULL,
            PRIMARY KEY  (attachment_id),
            KEY status (status)
        ) $collate;");
        update_option('kop_doc_types_db', KOP_DOC_TYPES_DB_VERSION);
    }
    if (function_exists('add_action')) add_action('init', 'kop_dt_install', 5);
}

if (!function_exists('kop_dt_now')) {
    function kop_dt_now() {
        return gmdate('Y-m-d H:i:s');
    }
}

if (!function_exists('kop_dt_who')) {
    function kop_dt_who() {
        if (function_exists('wp_get_current_user')) {
            $u = wp_get_current_user();
            if ($u && !empty($u->user_login)) return (string) $u->user_login;
        }
        return 'system';
    }
}

if (!function_exists('kop_dt_types_of')) {
    /**
     * attachment id => [slugs] for the given ids (every id present, [] when it
     * has none). Remembered per request; kop_dt_types_of(null) forgets.
     */
    function kop_dt_types_of($ids) {
        static $memo = array();
        if ($ids === null) { $memo = array(); return array(); }
        global $wpdb;
        $ids = array_values(array_unique(array_filter(array_map('intval', (array) $ids))));
        $need = array_values(array_filter($ids, function ($id) use (&$memo) { return !isset($memo[$id]); }));
        foreach (array_chunk($need, 500) as $chunk) {
            foreach ($chunk as $id) $memo[$id] = array();
            $rows = $wpdb->get_results('SELECT attachment_id, type FROM ' . kop_dt_table() . ' WHERE attachment_id IN (' . implode(',', $chunk) . ')', ARRAY_A);
            foreach ((array) $rows as $r) $memo[(int) $r['attachment_id']][] = (string) $r['type'];
        }
        $out = array();
        foreach ($ids as $id) $out[$id] = kop_dt_clean_types($memo[$id] ?? array());
        return $out;
    }
}

if (!function_exists('kop_dt_types_for')) {
    function kop_dt_types_for($id) {
        $all = kop_dt_types_of(array((int) $id));
        return $all[(int) $id] ?? array();
    }
}

if (!function_exists('kop_dt_write_one')) {
    /** Replace one document's types. No log; kop_dt_change() is the way in. */
    function kop_dt_write_one($id, array $types, $source, $note, $who) {
        global $wpdb;
        $id = (int) $id;
        $wpdb->query($wpdb->prepare('DELETE FROM ' . kop_dt_table() . ' WHERE attachment_id = %d', $id));
        foreach (kop_dt_clean_types($types) as $t) {
            $wpdb->query($wpdb->prepare('INSERT INTO ' . kop_dt_table() . ' (attachment_id, type, source, note, created_by, created_at) VALUES (%d, %s, %s, %s, %s, %s)',
                $id, $t, substr((string) $source, 0, 10), mb_substr((string) $note, 0, 255), substr((string) $who, 0, 60), kop_dt_now()));
        }
    }
}

if (!function_exists('kop_dt_sugg_get')) {
    function kop_dt_sugg_get($id) {
        global $wpdb;
        $r = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . kop_dt_sugg_table() . ' WHERE attachment_id = %d', (int) $id), ARRAY_A);
        return $r ?: null;
    }
}

if (!function_exists('kop_dt_sugg_put')) {
    /** Write (or with $row null, delete) a document's suggestion row as given. */
    function kop_dt_sugg_put($id, $row) {
        global $wpdb;
        $wpdb->query($wpdb->prepare('DELETE FROM ' . kop_dt_sugg_table() . ' WHERE attachment_id = %d', (int) $id));
        if (!is_array($row)) return;
        $wpdb->query($wpdb->prepare('INSERT INTO ' . kop_dt_sugg_table() . ' (attachment_id, status, types, why, provider, rules_version, created_at, reviewed_by, reviewed_at)
            VALUES (%d, %s, %s, %s, %s, %s, %s, %s, ' . (empty($row['reviewed_at']) ? 'NULL' : '%s') . ')',
            array_merge(array((int) $id, (string) $row['status'], (string) ($row['types'] ?? ''), (string) ($row['why'] ?? ''),
                (string) ($row['provider'] ?? ''), (string) ($row['rules_version'] ?? ''), (string) ($row['created_at'] ?? kop_dt_now()),
                (string) ($row['reviewed_by'] ?? '')), empty($row['reviewed_at']) ? array() : array((string) $row['reviewed_at']))));
    }
}

if (!function_exists('kop_dt_sugg_status')) {
    /** Set the status of an existing suggestion row, with the reviewer. */
    function kop_dt_sugg_status($id, $status, $who = null) {
        $row = kop_dt_sugg_get($id);
        if (!$row) $row = array('status' => $status, 'types' => '', 'why' => '', 'provider' => '', 'rules_version' => KOP_DOC_TYPES_RULES_VERSION, 'created_at' => kop_dt_now());
        $row['status'] = $status;
        $row['reviewed_by'] = $who === null ? '' : (string) $who;
        $row['reviewed_at'] = $who === null ? '' : kop_dt_now();
        kop_dt_sugg_put($id, $row);
        return $row;
    }
}

if (!function_exists('kop_dt_title')) {
    function kop_dt_title($id) {
        global $wpdb;
        return (string) $wpdb->get_var($wpdb->prepare('SELECT post_title FROM ' . $wpdb->posts . ' WHERE ID = %d', (int) $id));
    }
}

if (!function_exists('kop_dt_change')) {
    /**
     * Set several documents' types at once and log it as one change.
     * $changes: id => [slugs] (an empty list takes every type off). Documents
     * whose types do not change are left out. A person taking every type off
     * marks the document 'cleared' so neither the rules nor the AI type it
     * again. Returns the log entry, or null when nothing changed.
     */
    function kop_dt_change(array $changes, $source, $label, $who = null, $note = '') {
        $who = $who === null ? kop_dt_who() : (string) $who;
        $before = kop_dt_types_of(array_keys($changes));
        $rows = array();
        foreach ($changes as $id => $after) {
            $id = (int) $id;
            $after = kop_dt_clean_types($after);
            $was = $before[$id] ?? array();
            if ($after === $was) continue;
            $sugg = kop_dt_sugg_get($id);
            kop_dt_write_one($id, $after, $source, $note, $who);
            if (!$after && $source === 'person') kop_dt_sugg_status($id, 'cleared', $who);
            $rows[] = array('id' => $id, 'title' => mb_substr(kop_dt_title($id), 0, 120), 'before' => $was, 'after' => $after,
                'sugg' => $sugg ? array('status' => $sugg['status'], 'reviewed_by' => $sugg['reviewed_by'], 'reviewed_at' => (string) $sugg['reviewed_at']) : null);
        }
        kop_dt_types_of(null);
        if (!$rows) return null;
        $entry = array(
            'id'      => substr(md5(uniqid('dt', true)), 0, 12),
            'at'      => kop_dt_now(),
            'who'     => $who,
            'source'  => (string) $source,
            'label'   => (string) $label,
            'changes' => $rows,
            'undone'  => false,
        );
        $log = function_exists('get_option') ? get_option('kop_doc_types_log', array()) : array();
        $log = is_array($log) ? $log : array();
        array_unshift($log, $entry);
        if (function_exists('update_option')) update_option('kop_doc_types_log', array_slice($log, 0, 150), false);
        kop_dt_changed();
        return $entry;
    }
}

if (!function_exists('kop_dt_log')) {
    function kop_dt_log() {
        $log = function_exists('get_option') ? get_option('kop_doc_types_log', array()) : array();
        return is_array($log) ? $log : array();
    }
}

if (!function_exists('kop_dt_undo')) {
    /**
     * Put back what one logged change replaced. A document changed again
     * since is left as it is now and counted. Documents a rule run typed go
     * back to waiting for the AI, so the rules do not type them again.
     */
    function kop_dt_undo($entry_id, $who = null) {
        $who = $who === null ? kop_dt_who() : (string) $who;
        $log = kop_dt_log();
        foreach ($log as $i => $e) {
            if (($e['id'] ?? '') !== (string) $entry_id) continue;
            if (!empty($e['undone'])) throw new RuntimeException('Already undone by ' . ($e['undone_by'] ?? 'someone') . '.');
            $now = kop_dt_types_of(array_column($e['changes'], 'id'));
            $done = 0;
            $skipped = 0;
            foreach ($e['changes'] as $c) {
                if (($now[(int) $c['id']] ?? array()) !== kop_dt_clean_types($c['after'])) { $skipped++; continue; }
                kop_dt_write_one($c['id'], $c['before'], 'person', 'Undo', $who);
                if (($e['source'] ?? '') === 'rule') {
                    kop_dt_sugg_put($c['id'], array('status' => 'norule', 'why' => 'A rule run was undone', 'rules_version' => KOP_DOC_TYPES_RULES_VERSION));
                } elseif (array_key_exists('sugg', $c)) {
                    if ($c['sugg'] === null) kop_dt_sugg_put($c['id'], null);
                    else {
                        $row = kop_dt_sugg_get($c['id']);
                        if ($row) {
                            $row['status'] = $c['sugg']['status'];
                            $row['reviewed_by'] = $c['sugg']['reviewed_by'];
                            $row['reviewed_at'] = $c['sugg']['reviewed_at'];
                            kop_dt_sugg_put($c['id'], $row);
                        }
                    }
                }
                $done++;
            }
            $log[$i]['undone'] = true;
            $log[$i]['undone_by'] = $who;
            $log[$i]['undone_at'] = kop_dt_now();
            if (function_exists('update_option')) update_option('kop_doc_types_log', $log, false);
            kop_dt_types_of(null);
            kop_dt_changed();
            return array('restored' => $done, 'skipped' => $skipped);
        }
        throw new RuntimeException('That change is no longer in the log.');
    }
}

if (!function_exists('kop_dt_rev')) {
    function kop_dt_rev() {
        return function_exists('get_option') ? (string) get_option('kop_doc_types_rev', '0') : '0';
    }
}

if (!function_exists('kop_dt_changed')) {
    function kop_dt_changed() {
        if (function_exists('update_option')) update_option('kop_doc_types_rev', (string) microtime(true), false);
        if (function_exists('do_action')) do_action('litespeed_purge_all');
    }
}

// ---------------------------------------------------------------------------
// The documents and the folders they are filed in
// ---------------------------------------------------------------------------

if (!function_exists('kop_dt_folder_name')) {
    /** A folder name as read: entities decoded, spaces folded. */
    function kop_dt_folder_name($name) {
        return trim(preg_replace('/\s+/u', ' ', html_entity_decode((string) $name, ENT_QUOTES, 'UTF-8')));
    }
}

if (!function_exists('kop_dt_folders')) {
    /** FileBird folder id => {name, parent}, once a request. */
    function kop_dt_folders() {
        static $memo = null;
        if ($memo !== null) return $memo;
        global $wpdb;
        $memo = array();
        $rows = $wpdb->get_results('SELECT id, name, parent FROM ' . $wpdb->prefix . 'fbv WHERE type = 0', ARRAY_A);
        foreach ((array) $rows as $r) $memo[(int) $r['id']] = array('name' => kop_dt_folder_name($r['name']), 'parent' => (int) $r['parent']);
        return $memo;
    }
}

if (!function_exists('kop_dt_chain')) {
    /** Folder ids from $fid up to its root, nearest first. */
    function kop_dt_chain($fid) {
        $folders = kop_dt_folders();
        $out = array();
        $fid = (int) $fid;
        while ($fid > 0 && isset($folders[$fid]) && !in_array($fid, $out, true)) {
            $out[] = $fid;
            $fid = $folders[$fid]['parent'];
        }
        return $out;
    }
}

if (!function_exists('kop_dt_doc_mimes_sql')) {
    /** The files that are documents: PDFs, Word files, plain text. Pictures and data files are not typed. */
    function kop_dt_doc_mimes_sql($col = 'p.post_mime_type') {
        return "($col = 'application/pdf' OR $col = 'application/msword' OR $col LIKE 'application/vnd.openxmlformats-officedocument.wordprocessingml%' OR $col = 'text/plain' OR $col = 'application/rtf')";
    }
}

if (!function_exists('kop_dt_load_docs')) {
    /**
     * {id, title, file, folders: [folder ids], woodbury} for the given ids, or
     * for every document when $ids is null.
     */
    function kop_dt_load_docs($ids = null) {
        global $wpdb;
        $p = $wpdb->posts;
        $pm = $wpdb->postmeta;
        $where = "p.post_type = 'attachment' AND " . kop_dt_doc_mimes_sql();
        if ($ids !== null) {
            $ids = array_values(array_filter(array_map('intval', (array) $ids)));
            if (!$ids) return array();
            $where .= ' AND p.ID IN (' . implode(',', $ids) . ')';
        }
        $docs = array();
        foreach ((array) $wpdb->get_results("SELECT p.ID, p.post_title, p.post_mime_type FROM $p p WHERE $where", ARRAY_A) as $r) {
            $docs[(int) $r['ID']] = array('id' => (int) $r['ID'], 'title' => (string) $r['post_title'], 'mime' => (string) $r['post_mime_type'],
                'file' => '', 'folders' => array(), 'woodbury' => false);
        }
        if (!$docs) return array();
        $in = ' IN (' . implode(',', array_keys($docs)) . ')';
        foreach ((array) $wpdb->get_results("SELECT post_id, meta_key, meta_value FROM $pm WHERE meta_key IN ('_wp_attached_file', '_kop_woodbury_key') AND post_id $in", ARRAY_A) as $m) {
            $id = (int) $m['post_id'];
            if ($m['meta_key'] === '_wp_attached_file') $docs[$id]['file'] = basename((string) $m['meta_value']);
            else $docs[$id]['woodbury'] = true;
        }
        foreach ((array) $wpdb->get_results('SELECT attachment_id, folder_id FROM ' . $wpdb->prefix . "fbv_attachment_folder WHERE attachment_id $in", ARRAY_A) as $r) {
            $docs[(int) $r['attachment_id']]['folders'][] = (int) $r['folder_id'];
        }
        // Extra memberships ("Also listed in") count after the folder it is filed in.
        $tags = $wpdb->prefix . 'kop_media_folder_tags';
        $rows = $wpdb->get_results("SELECT attachment_id, folder_id FROM $tags WHERE attachment_id $in", ARRAY_A);
        foreach ((array) $rows as $r) {
            $id = (int) $r['attachment_id'];
            if (!in_array((int) $r['folder_id'], $docs[$id]['folders'], true)) $docs[$id]['folders'][] = (int) $r['folder_id'];
        }
        return $docs;
    }
}

// ---------------------------------------------------------------------------
// Rules
// ---------------------------------------------------------------------------

if (!function_exists('kop_dt_rules')) {
    /**
     * {strong: [[type, title regex]], folders: [[type, folder name regex]],
     * titles: [[type, title regex]]}. Strong title rules win over any folder
     * (a NATSAP profile filed with a program's lawsuits is still a NATSAP
     * profile); then the nearest folder that names a type; then the weaker
     * title words. Type 'handbook' is settled by kop_dt_handbook_kind().
     */
    function kop_dt_rules() {
        return array(
            'strong' => array(
                array('natsap-profile', '/\bnatsap\b.{0,12}\bprofile\b|\bprofile\b.{0,12}\bnatsap\b|\bnatsap (?:19|20)\d\d\b|^(?:ya )?[a-z0-9 &\'.-]+ natsap (?:19|20)\d\d$/i'),
                array('deposition', '/\b(?:excerpts? (?:of|from) )?deposition (?:of|transcript)\b|^deposition\b|\bdeposed\b/i'),
                array('enrollment', '/\b(?:enrol+ment|tuition|parent|admissions?|placement|services) (?:agreement|contract|application|form|packet|checklist)s?\b|\badmission application\b/i'),
                array('staff-profile', '/\blinked ?in\b|\br[eé]sum[eé]\b|\bcurriculum vitae\b/i'),
                array('tax-filing', '/\bform 990\b|\b990s?\b(?!\d)|\birs (?:form|filing)/i'),
                array('inspection-report', '/\binspection report\b|\bcomplaint investigation\b|\bfacility evaluation report\b|\blicensing (?:report|survey|inspection|visit)\b|\bstatement of deficienc|\bcorrective action plan\b|\bmonitoring (?:report|visit)\b/i'),
                array('incident-report', '/\bpolice report\b|\bincident report\b|\bserious occurrence\b|\bsheriff\'?s? report\b/i'),
                array('business-record', '/\barticles of (?:incorporation|organi[sz]ation|amendment)\b|\bannual report\b.*\b(?:llc|inc|corp)|\bbusiness registration\b|\bstatement of change\b|\bcertificate of (?:formation|good standing)\b/i'),
                // "Lon Woodbury Expert Report" is a court filing about him, not his newsletter.
                array('woodbury', '/\bwoodbury\b(?!.*\b(?:expert|declaration|affidavit|testimony)\b)/i'),
                array('directory', '/\bnatsap (?:member list|directory)\b|\bmember (?:list|directory)\b/i'),
            ),
            'folders' => array(
                array('inspection-report', '/^[a-z]{2}_pdfs$|^(?:state )?licensing (?:records|& inspections)|^prea & inspections$|^inspections?(?: reports?)?$/i'),
                array('woodbury', '/woodbury/i'),
                array('natsap-profile', '/^natsap profiles$|^member program profiles$/i'),
                array('deposition', '/^discovery & depositions$|^depositions?$/i'),
                array('legal-filing', '/^lawsuits?$|^legal(?: filings)?$|^court(?: records| documents| filings)?$|^pleadings$|^motions(?: & requests)?$|^orders(?: & rulings)?$|^scheduling & continuances$|^appearances(?: & counsel)?$|\bvs?\.? [a-z]/i'),
                array('enrollment', '/enrollment|^admissions?$/i'),
                array('parent-handbook', '/^parent (?:& family handbooks|booklets?|guides?|manuals?)$/i'),
                array('staff-manual', '/^staff (?:manuals?|training(?: & policy)?|handbooks?)$|^polic(?:y|ies)$/i'),
                array('student-handbook', '/^student (?:& resident )?handbooks$/i'),
                array('handbook', '/^handbooks$/i'),
                array('news-clipping', '/^news(?: coverage| clippings| articles)?$/i'),
                array('newsletter', '/newsletters?|press releases|^statements & press/i'),
                array('marketing', '/^brochures|^marketing/i'),
                array('website-capture', '/^program websites|^websites?$|^web ?pages$/i'),
                array('directory', '/^directories$|referral services and directories/i'),
                array('business-record', '/^property records$|^business records$|^corporate (?:docs|filings|records)$/i'),
                array('tax-filing', '/^990s?$|^tax (?:returns|filings)$/i'),
                array('government-report', '/^government(?: reports)?$/i'),
                array('legislation', '/^legislative$|^lobbying & legislation$|^legislation$/i'),
                array('research', '/^academia$|^studies$|^research$/i'),
                array('survivor-account', '/^survivor (?:stories|accounts)$|^testimon(?:y|ies)$/i'),
            ),
            'titles' => array(
                array('handbook', '/\bhand ?book\b|\bmanual\b|\brule ?book\b|\blevels? (?:system|and privileges)\b|\bphase (?:system|chart)\b/i'),
                array('enrollment', '/\benrol+ment\b|\badmissions? (?:form|packet|agreement|application)\b|\btuition agreement\b|\bapplication (?:form|packet)\b|\bintake (?:form|packet)\b|\bauthori[sz]ation to release\b|\bregistration form\b|\bpre-?admission\b/i'),
                array('newsletter', '/\bnewsletter\b|\bpress release\b/i'),
                array('marketing', '/\bbrochure\b|\bviewbook\b|\bflyer\b/i'),
                array('legal-filing', '/\bcomplaint\b|\bmotion\b|\border (?:granting|denying|on)\b|\bpetition\b|\baffidavit\b|\bsettlement\b|\banswer to\b|\bsummons\b|\bindictment\b|\bverdict\b|\bjudgment\b|\bdocket\b|\bdeclaration of\b|\bruling\b|\bexpert (?:report|disclosure)\b|\bcourt filing\b/i'),
                array('inspection-report', '/\bnotice of (?:inspection|violation)\b|\bsurrender(?:ed|s)? (?:its |their )?licen[cs]e\b|\blicen[cs]e (?:surrender|revocation|denial|renewal)\b|\bdeny licen[cs]e\b/i'),
                array('research', '/\bthesis\b|\bdissertation\b/i'),
                array('website-capture', '/\bwebsite\b/i'),
                array('government-report', '/\bgao\b|\binspector general\b|\bsenate (?:report|investigation|finance)\b|\bcongressional\b|\bstate audit\b/i'),
                array('legislation', '/\b[hs]\.? ?b\.? ?\d{2,4}\b|\bhouse bill\b|\bsenate bill\b|\btestimony (?:before|to)\b/i'),
                array('news-clipping', '/\bnews ?(?:article|clipping|story)\b/i'),
            ),
        );
    }
}

if (!function_exists('kop_dt_handbook_kind')) {
    /** A handbook's reader from its title: parents, staff, or the young people. */
    function kop_dt_handbook_kind($title) {
        // Dorm and house parents are staff.
        if (preg_match('/\b(?:staff|employees?|personnel|training|polic(?:y|ies)|(?:dorm|house) parents?)\b/i', $title)) return 'staff-manual';
        if (preg_match('/\b(?:parents?|family|families)\b/i', $title)) return 'parent-handbook';
        return 'student-handbook';
    }
}

if (!function_exists('kop_dt_title_text')) {
    /** Title and file name as one line of words ("spring_creek-enrollment.pdf" -> "spring creek enrollment"). */
    function kop_dt_title_text(array $doc) {
        $file = preg_replace('/\.[a-z0-9]{2,5}$/i', '', (string) ($doc['file'] ?? ''));
        $text = trim((string) ($doc['title'] ?? '')) . ' | ' . $file;
        return trim(preg_replace('/[_\s]+/u', ' ', str_replace(array('-', '_'), ' ', $text)));
    }
}

if (!function_exists('kop_dt_classify')) {
    /**
     * {type, why} from the rules for one document (see kop_dt_load_docs()),
     * or null when no rule says. Never touches the database beyond the
     * folder names.
     */
    function kop_dt_classify(array $doc) {
        $rules = kop_dt_rules();
        $title = kop_dt_title_text($doc);
        $settle = function ($type) use ($doc) {
            return $type === 'handbook' ? kop_dt_handbook_kind((string) ($doc['title'] ?? '') . ' ' . (string) ($doc['file'] ?? '')) : $type;
        };
        if (!empty($doc['woodbury'])) return array('type' => 'woodbury', 'why' => 'Cut from a Woodbury Reports issue');
        foreach ($rules['strong'] as $r) {
            if (preg_match($r[1], $title, $m)) return array('type' => $settle($r[0]), 'why' => 'The title says "' . trim($m[0]) . '"');
        }
        $folders = kop_dt_folders();
        foreach ((array) ($doc['folders'] ?? array()) as $fid) {
            foreach (kop_dt_chain($fid) as $cid) {
                $name = $folders[$cid]['name'];
                foreach ($rules['folders'] as $r) {
                    if (preg_match($r[1], $name)) return array('type' => $settle($r[0]), 'why' => 'Filed in the folder "' . $name . '"');
                }
            }
        }
        foreach ($rules['titles'] as $r) {
            if (preg_match($r[1], $title, $m)) return array('type' => $settle($r[0]), 'why' => 'The title says "' . trim($m[0]) . '"');
        }
        return null;
    }
}

if (!function_exists('kop_dt_run_rules')) {
    /**
     * Type every document that has no type and that no one has looked at yet
     * (no suggestion row) by the rules, as one logged change. Those the rules
     * cannot type get a 'norule' row and wait for the AI. A rules version
     * bump looks again at 'norule' rows the AI has not reached.
     * Returns {typed, by_type, left, sample: [[id, title, type, why]]}.
     */
    function kop_dt_run_rules($apply = true) {
        global $wpdb;
        $t = kop_dt_table();
        $s = kop_dt_sugg_table();
        $p = $wpdb->posts;
        $ids = $wpdb->get_col("SELECT p.ID FROM $p p
            WHERE p.post_type = 'attachment' AND " . kop_dt_doc_mimes_sql() . "
              AND NOT EXISTS (SELECT 1 FROM $t d WHERE d.attachment_id = p.ID)
              AND NOT EXISTS (SELECT 1 FROM $s x WHERE x.attachment_id = p.ID
                              AND NOT (x.status = 'norule' AND x.rules_version <> '" . preg_replace('/[^0-9.]/', '', KOP_DOC_TYPES_RULES_VERSION) . "'))");
        $out = array('typed' => 0, 'by_type' => array(), 'left' => 0, 'sample' => array(), 'entry' => null);
        if (!$ids) return $out;
        $changes = array();
        $why = array();
        foreach (array_chunk(array_map('intval', $ids), 2000) as $chunk) {
            foreach (kop_dt_load_docs($chunk) as $doc) {
                $c = kop_dt_classify($doc);
                if ($c) {
                    $changes[$doc['id']] = array($c['type']);
                    $why[$doc['id']] = $c['why'];
                    $out['by_type'][$c['type']] = ($out['by_type'][$c['type']] ?? 0) + 1;
                    if (count($out['sample']) < 400) $out['sample'][] = array($doc['id'], $doc['title'], $c['type'], $c['why']);
                } else {
                    $out['left']++;
                    if ($apply) kop_dt_sugg_put($doc['id'], array('status' => 'norule', 'rules_version' => KOP_DOC_TYPES_RULES_VERSION));
                }
            }
        }
        $out['typed'] = count($changes);
        arsort($out['by_type']);
        if ($apply && $changes) {
            // One write per document carries its own reason, then one log entry for the run.
            $entry = kop_dt_change($changes, 'rule', 'Typed by folder and title rules', 'rules');
            foreach ($why as $id => $w) {
                $wpdb->query($wpdb->prepare('UPDATE ' . $t . ' SET note = %s WHERE attachment_id = %d', mb_substr($w, 0, 255), $id));
            }
            $out['entry'] = $entry ? $entry['id'] : null;
        }
        return $out;
    }
}

// ---------------------------------------------------------------------------
// AI suggestions
// ---------------------------------------------------------------------------

if (!function_exists('kop_dt_doc_text')) {
    /** The first pages' text of a document, '' when it has none (a scan) or the file is missing. */
    function kop_dt_doc_text($id, $chars = 3000) {
        if (!empty($GLOBALS['kop_dt_text']) && is_callable($GLOBALS['kop_dt_text'])) return (string) call_user_func($GLOBALS['kop_dt_text'], $id);
        if (!function_exists('get_attached_file')) return '';
        $path = get_attached_file((int) $id);
        if (!$path || !is_readable($path) || filesize($path) > 60 * 1024 * 1024) return '';
        require_once get_stylesheet_directory() . '/api/lawsuit-extraction-lib.php';
        try {
            $text = kop_extract_document_text($path, (string) get_post_mime_type((int) $id));
        } catch (Throwable $e) {
            return '';
        }
        $text = trim(preg_replace('/\s+/u', ' ', (string) $text));
        return function_exists('mb_substr') ? mb_substr($text, 0, $chars) : substr($text, 0, $chars);
    }
}

if (!function_exists('kop_dt_ai_prompt')) {
    function kop_dt_ai_prompt($title, $path, $text) {
        $list = array();
        foreach (kop_dt_types() as $slug => $t) $list[] = '- ' . $slug . ': ' . $t['label'] . '. ' . $t['desc'];
        return "You sort documents in an archive about the troubled teen industry (residential treatment centers, wilderness programs, therapeutic boarding schools and the companies that run them).\n"
            . "Pick the document type that fits this document. Use one slug from the list. Add a second slug only if the document really is two things at once (for example a handbook bound with an enrollment agreement).\n"
            . "If the text is missing or does not tell you what the document is, and the title does not either, answer \"unclear\".\n\n"
            . "Types:\n" . implode("\n", $list) . "\n\n"
            . "Document title: " . $title . "\n"
            . "Filed in folder: " . ($path !== '' ? $path : '(none)') . "\n"
            . "Start of its text: " . ($text !== '' ? $text : '(no text: a scan or a picture)') . "\n\n"
            . "Answer with JSON only, no other words: {\"types\": [\"slug\"], \"why\": \"one short sentence saying what the document is\"} or {\"types\": [], \"why\": \"unclear: the reason\"}";
    }
}

if (!function_exists('kop_dt_ai_parse')) {
    /** {types, why} from the AI's answer. Unknown slugs are dropped; none left = unclear. */
    function kop_dt_ai_parse($answer) {
        $answer = (string) $answer;
        if (!preg_match('/\{.*\}/s', $answer, $m)) throw new RuntimeException('The AI did not answer in JSON.');
        $j = json_decode($m[0], true);
        if (!is_array($j)) throw new RuntimeException('The AI\'s JSON could not be read.');
        $types = array_slice(kop_dt_clean_types($j['types'] ?? array()), 0, 2);
        $why = trim(preg_replace('/\s+/u', ' ', strip_tags((string) ($j['why'] ?? ''))));
        return array('types' => $types, 'why' => mb_substr($why, 0, 400));
    }
}

if (!function_exists('kop_dt_ai_ask')) {
    /** One AI call; a test can stand in with $GLOBALS['kop_dt_ai']. */
    function kop_dt_ai_ask($prompt) {
        if (!empty($GLOBALS['kop_dt_ai']) && is_callable($GLOBALS['kop_dt_ai'])) return (string) call_user_func($GLOBALS['kop_dt_ai'], $prompt);
        require_once get_stylesheet_directory() . '/api/ai-providers.php';
        return kop_ai_generate_alternating($prompt, array('maxTokens' => 200, 'temperature' => 0.1));
    }
}

if (!function_exists('kop_dt_folder_path')) {
    /** "Lawsuits / Sweidy v. SRA" for a document's first folder. */
    function kop_dt_folder_path(array $doc) {
        if (empty($doc['folders'])) return '';
        $folders = kop_dt_folders();
        $names = array();
        foreach (kop_dt_chain($doc['folders'][0]) as $cid) $names[] = $folders[$cid]['name'];
        return implode(' / ', array_reverse($names));
    }
}

if (!function_exists('kop_dt_ai_suggest')) {
    /** Ask about one document and store the guess as 'pending' (or 'unclear'). Returns the stored row. */
    function kop_dt_ai_suggest($id) {
        $docs = kop_dt_load_docs(array((int) $id));
        if (!$docs) throw new RuntimeException('That document is gone.');
        $doc = reset($docs);
        $text = kop_dt_doc_text($doc['id']);
        $got = kop_dt_ai_parse(kop_dt_ai_ask(kop_dt_ai_prompt(kop_dt_title_text($doc), kop_dt_folder_path($doc), $text)));
        $row = array(
            'status'   => $got['types'] ? 'pending' : 'unclear',
            'types'    => implode(',', $got['types']),
            'why'      => ($got['why'] !== '' ? $got['why'] : 'No reason given.') . ($text === '' ? ' (Read from the title and folder only: the file has no text.)' : ''),
            'provider' => (string) ($GLOBALS['kop_ai_last_provider'] ?? ''),
            'rules_version' => KOP_DOC_TYPES_RULES_VERSION,
            'created_at' => kop_dt_now(),
        );
        kop_dt_sugg_put($doc['id'], $row);
        return $row;
    }
}

if (!function_exists('kop_dt_ai_batch')) {
    /** Ask the AI about up to $limit documents waiting for it. {asked, pending, unclear, errors, left, error}. */
    function kop_dt_ai_batch($limit = 12) {
        global $wpdb;
        $s = kop_dt_sugg_table();
        $t = kop_dt_table();
        $ids = $wpdb->get_col("SELECT x.attachment_id FROM $s x WHERE x.status = 'norule'
            AND NOT EXISTS (SELECT 1 FROM $t d WHERE d.attachment_id = x.attachment_id)
            ORDER BY x.attachment_id DESC LIMIT " . (int) $limit);
        $out = array('asked' => 0, 'pending' => 0, 'unclear' => 0, 'errors' => 0, 'left' => 0, 'error' => '');
        foreach ($ids as $id) {
            try {
                $row = kop_dt_ai_suggest((int) $id);
                $out['asked']++;
                $out[$row['status']]++;
            } catch (Throwable $e) {
                $out['errors']++;
                $out['error'] = $e->getMessage();
                if ($out['errors'] >= 3) break; // the providers are down or out of quota: try next hour
            }
        }
        $out['left'] = (int) $wpdb->get_var("SELECT COUNT(*) FROM $s x WHERE x.status = 'norule' AND NOT EXISTS (SELECT 1 FROM $t d WHERE d.attachment_id = x.attachment_id)");
        return $out;
    }
}

if (function_exists('add_action')) {
    add_action('init', function () {
        if (!wp_next_scheduled('kop_doc_types_hourly')) wp_schedule_event(time() + 1200, 'hourly', 'kop_doc_types_hourly');
    });
    add_action('kop_doc_types_hourly', 'kop_dt_cron');
}

if (!function_exists('kop_dt_cron')) {
    function kop_dt_cron() {
        if (get_transient('kop_dt_lock')) return;
        set_transient('kop_dt_lock', 1, 15 * MINUTE_IN_SECONDS);
        try {
            kop_dt_install();
            kop_dt_run_rules(true);
            kop_dt_ai_batch((int) apply_filters('kop_doc_types_ai_per_hour', 12));
        } catch (Throwable $e) {
            error_log('kop_dt_cron: ' . $e->getMessage());
        }
        delete_transient('kop_dt_lock');
    }
}

// ---------------------------------------------------------------------------
// Counts and lists for readers
// ---------------------------------------------------------------------------

if (!function_exists('kop_dt_private_folder_ids')) {
    /** Folders whose documents are never listed publicly (site plumbing, user submissions) and everything under them. */
    function kop_dt_private_folder_ids() {
        static $memo = null;
        if ($memo !== null) return $memo;
        $skip = function_exists('kop_doc_archive_skip_names') ? kop_doc_archive_skip_names()
            : array('blog', 'stock photos', 'recent user submissions', 'uncategorized', 'google drive', 'focus locations');
        $skip = array_map('strtolower', $skip);
        // Google Drive and Focus Locations are only containers for real collections; their documents are public.
        $skip = array_values(array_diff($skip, array('google drive', 'focus locations')));
        $memo = array();
        foreach (kop_dt_folders() as $id => $f) {
            foreach (kop_dt_chain($id) as $cid) {
                if (in_array(strtolower(kop_dt_folders()[$cid]['name']), $skip, true)) { $memo[$id] = true; break; }
            }
        }
        return $memo;
    }
}

if (!function_exists('kop_dt_counts')) {
    /** type => number of documents with it, plus 'untyped' (documents with none). */
    function kop_dt_counts() {
        global $wpdb;
        $out = array_fill_keys(array_keys(kop_dt_types()), 0);
        foreach ((array) $wpdb->get_results('SELECT type, COUNT(*) AS n FROM ' . kop_dt_table() . ' GROUP BY type', ARRAY_A) as $r) {
            if (isset($out[$r['type']])) $out[$r['type']] = (int) $r['n'];
        }
        $out['untyped'] = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} p WHERE p.post_type = 'attachment' AND " . kop_dt_doc_mimes_sql()
            . ' AND NOT EXISTS (SELECT 1 FROM ' . kop_dt_table() . ' d WHERE d.attachment_id = p.ID)');
        return $out;
    }
}

if (!function_exists('kop_dt_facility_folder_map')) {
    /**
     * folder id => facility page id, and folder name key => facility page id,
     * from the facility pages' index (each page's own document folder; the
     * library shows same-name folders too). Folders with several pages' names
     * are left out.
     */
    function kop_dt_facility_folder_map() {
        static $memo = null;
        if ($memo !== null) return $memo;
        $memo = array('ids' => array(), 'names' => array(), 'pages' => array());
        if (!function_exists('kop_facility_pages_index')) return $memo;
        $index = kop_facility_pages_index();
        $folders = kop_dt_folders();
        $names = array();
        foreach ((array) ($index['ids'] ?? array()) as $fid => $e) {
            $folder = (int) ($e['folder'] ?? 0);
            if ($folder <= 0) continue;
            $memo['ids'][$folder] = (int) $fid;
            $memo['pages'][(int) $fid] = array('name' => (string) $e['name'], 'slug' => (string) $e['slug']);
            if (isset($folders[$folder])) $names[strtolower($folders[$folder]['name'])][(int) $fid] = true;
        }
        foreach ($names as $k => $fids) {
            if (count($fids) === 1) $memo['names'][$k] = (int) key($fids);
        }
        return $memo;
    }
}

if (!function_exists('kop_dt_facility_for')) {
    /** The facility page id a document's folders put it on (nearest folder first), 0 when none. */
    function kop_dt_facility_for(array $folder_ids) {
        $map = kop_dt_facility_folder_map();
        $folders = kop_dt_folders();
        foreach ($folder_ids as $fid) {
            foreach (kop_dt_chain($fid) as $cid) {
                if (isset($map['ids'][$cid])) return $map['ids'][$cid];
                $k = strtolower($folders[$cid]['name']);
                if (isset($map['names'][$k])) return $map['names'][$k];
            }
        }
        return 0;
    }
}

if (!function_exists('kop_dt_year')) {
    /** The year a document is from: the research library's year, else a year in its title. '' when neither. */
    function kop_dt_year($id, $title, array $research_years) {
        if (!empty($research_years[$id]) && preg_match('/^(1[89]|20)\d\d$/', (string) $research_years[$id])) return (string) $research_years[$id];
        if (preg_match_all('/(?<![\d-])((?:19[5-9]|20[0-3])\d)(?![\d])/', (string) $title, $m)) return $m[1][0];
        return '';
    }
}

if (!function_exists('kop_dt_page_items')) {
    /**
     * Every public document of one type: [{id, title, url, thumb, ext, year,
     * place: {kind: facility|folder, name, url, state}}], grouped and sorted by
     * the reader page. Cached until a type changes or the facility index is rebuilt.
     */
    function kop_dt_page_items($type) {
        global $wpdb;
        $types = kop_dt_types();
        if (!isset($types[$type])) return array();
        $index_built = function_exists('kop_facility_pages_index') ? (string) (kop_facility_pages_index()['built'] ?? '') : '';
        $key = 'kop_dt_items_' . md5($type . '|' . kop_dt_rev() . '|' . $index_built);
        if (function_exists('get_transient')) {
            $hit = get_transient($key);
            if (is_array($hit)) return $hit;
        }
        $ids = array_map('intval', $wpdb->get_col($wpdb->prepare('SELECT d.attachment_id FROM ' . kop_dt_table() . " d JOIN {$wpdb->posts} p ON p.ID = d.attachment_id AND p.post_type = 'attachment' WHERE d.type = %s", $type)));
        $docs = kop_dt_load_docs($ids);
        $private = kop_dt_private_folder_ids();
        $years = array();
        if ($docs) {
            foreach ((array) $wpdb->get_results("SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = 'kop_research_year' AND post_id IN (" . implode(',', array_keys($docs)) . ')', ARRAY_A) as $r) {
                $years[(int) $r['post_id']] = trim((string) $r['meta_value']);
            }
        }
        $map = kop_dt_facility_folder_map();
        $states = array();
        $fids = array();
        $items = array();
        $folders = kop_dt_folders();
        foreach ($docs as $doc) {
            if (!$doc['folders']) continue; // not filed anywhere: not on any public page either
            $hidden = false;
            foreach ($doc['folders'] as $f) if (isset($private[$f])) { $hidden = true; break; }
            if ($hidden) continue;
            $fid = kop_dt_facility_for($doc['folders']);
            if ($fid) {
                $fids[$fid] = true;
                $place = array('kind' => 'facility', 'id' => $fid, 'name' => $map['pages'][$fid]['name'],
                    'url' => function_exists('kop_facility_pages_url_for_slug') ? kop_facility_pages_url_for_slug($map['pages'][$fid]['slug']) : '', 'state' => '');
            } else {
                // Not on a program's page: named by the top folder it is filed under (a company, a trade group, "Government").
                $chain = kop_dt_chain($doc['folders'][0]);
                $root = $chain ? $folders[end($chain)]['name'] : '';
                if (preg_match('/^[a-z]{2}_pdfs$/i', $root)) $root = strtoupper(substr($root, 0, 2)) . ' state reports';
                $place = array('kind' => 'folder', 'id' => 0, 'name' => $root !== '' ? $root : 'Other', 'url' => '', 'state' => '');
            }
            $url = function_exists('wp_get_attachment_url') ? (string) wp_get_attachment_url($doc['id']) : '';
            $thumb = function_exists('kop_get_attachment_preview_url') ? (string) kop_get_attachment_preview_url($doc['id'], 'medium') : '';
            $title = function_exists('kop_doc_archive_title') ? kop_doc_archive_title($doc['title']) : $doc['title'];
            if (function_exists('kop_title_case')) $title = kop_title_case($title);
            $items[] = array(
                'id'    => $doc['id'],
                'title' => $title,
                'url'   => $url,
                'thumb' => $thumb,
                'ext'   => strtoupper(pathinfo((string) $doc['file'], PATHINFO_EXTENSION)),
                'year'  => kop_dt_year($doc['id'], $doc['title'] . ' ' . $doc['file'], $years),
                'place' => $place,
            );
        }
        if ($fids) {
            foreach ((array) $wpdb->get_results('SELECT id, state FROM facilities_v2 WHERE id IN (' . implode(',', array_map('intval', array_keys($fids))) . ')', ARRAY_A) as $r) {
                $states[(int) $r['id']] = strtoupper(trim((string) $r['state']));
            }
            foreach ($items as &$it) {
                if ($it['place']['kind'] === 'facility') $it['place']['state'] = $states[$it['place']['id']] ?? '';
            }
            unset($it);
        }
        usort($items, function ($a, $b) {
            $pa = ($a['place']['kind'] === 'facility' ? '0' : '1') . strtolower($a['place']['name']);
            $pb = ($b['place']['kind'] === 'facility' ? '0' : '1') . strtolower($b['place']['name']);
            if ($pa !== $pb) return strcmp($pa, $pb);
            if ($a['year'] !== $b['year']) return strcmp($a['year'] === '' ? '9999' : $a['year'], $b['year'] === '' ? '9999' : $b['year']);
            return strcasecmp($a['title'], $b['title']);
        });
        if (function_exists('set_transient')) set_transient($key, $items, 12 * HOUR_IN_SECONDS);
        return $items;
    }
}

// ---------------------------------------------------------------------------
// Route: /documents/ and /documents/<type>/
// ---------------------------------------------------------------------------

if (!function_exists('kop_dt_base')) {
    function kop_dt_base() {
        return 'documents';
    }
}

if (!function_exists('kop_dt_url')) {
    /** /documents/ or /documents/<type>/. */
    function kop_dt_url($type = '') {
        return home_url('/' . kop_dt_base() . '/' . ($type !== '' ? rawurlencode($type) . '/' : ''));
    }
}

if (function_exists('add_action')) {
    add_action('init', function () {
        add_rewrite_rule('^' . kop_dt_base() . '/?$', 'index.php?kop_doc_type=_index', 'top');
        add_rewrite_rule('^' . kop_dt_base() . '/([a-z0-9-]+)/?$', 'index.php?kop_doc_type=$matches[1]', 'top');
        if (get_option('kop_doc_types_rewrite') !== KOP_DOC_TYPES_REWRITE_VERSION) {
            flush_rewrite_rules(false);
            update_option('kop_doc_types_rewrite', KOP_DOC_TYPES_REWRITE_VERSION);
        }
    }, 30);
    add_filter('query_vars', function ($vars) {
        $vars[] = 'kop_doc_type';
        return $vars;
    });
}

if (!function_exists('kop_dt_request')) {
    /** '_index', a type slug, or '' when this is not a /documents/ request. */
    function kop_dt_request($query = null) {
        $v = $query === null ? (function_exists('get_query_var') ? (string) get_query_var('kop_doc_type') : '') : (string) $query->get('kop_doc_type');
        return $v;
    }
}

if (!function_exists('kop_dt_is_page')) {
    function kop_dt_is_page() {
        return isset($GLOBALS['kop_doc_types_page']) && is_array($GLOBALS['kop_doc_types_page']);
    }
}

if (!function_exists('kop_dt_page_data')) {
    /** What templates/document-types.php draws: {type: '' | slug, types, counts, items, label, url}. */
    function kop_dt_page_data($type) {
        $types = kop_dt_types();
        $counts = kop_dt_counts();
        $data = array('type' => '', 'types' => $types, 'counts' => $counts, 'items' => array(), 'url' => kop_dt_url(),
            'archive_url' => home_url('/document-archive/'));
        if ($type !== '_index' && isset($types[$type])) {
            $data['type'] = $type;
            $data['items'] = kop_dt_page_items($type);
        }
        return $data;
    }
}

if (function_exists('add_action')) {
    add_action('pre_get_posts', static function ($query) {
        if (is_admin() || !$query->is_main_query() || kop_dt_request($query) === '') return;
        $query->is_home = false;
        $query->is_posts_page = false;
        $query->set('posts_per_page', 1);
        $query->set('no_found_rows', true);
    });
    add_filter('posts_pre_query', static function ($posts, $query) {
        if (is_admin() || !$query->is_main_query() || kop_dt_request($query) === '') return $posts;
        return array();
    }, 10, 2);
    add_filter('pre_handle_404', static function ($preempt, $query) {
        $t = kop_dt_request($query);
        return ($t === '_index' || isset(kop_dt_types()[$t])) ? true : $preempt;
    }, 10, 2);
    add_action('template_redirect', static function () {
        $t = kop_dt_request();
        if ($t === '') return;
        if ($t !== '_index' && !isset(kop_dt_types()[$t])) {
            wp_safe_redirect(kop_dt_url(), 302);
            exit;
        }
        $GLOBALS['kop_doc_types_page'] = kop_dt_page_data($t);
        status_header(200);
    }, 0);
    add_filter('template_include', static function ($template) {
        if (!kop_dt_is_page()) return $template;
        $own = get_stylesheet_directory() . '/templates/document-types.php';
        return file_exists($own) ? $own : $template;
    }, 99);
    add_filter('body_class', static function ($classes) {
        if (kop_dt_is_page()) $classes[] = 'kop-document-types-page';
        return $classes;
    });
    add_action('wp_enqueue_scripts', static function () {
        if (kop_dt_request() === '') return;
        $dir = get_stylesheet_directory();
        $uri = get_stylesheet_directory_uri();
        wp_enqueue_style('kop-site-map', $uri . '/css/site-map.css', array(), (string) @filemtime($dir . '/css/site-map.css'));
        wp_enqueue_style('kop-document-types', $uri . '/css/document-types.css', array('kop-site-map'), (string) @filemtime($dir . '/css/document-types.css'));
        wp_enqueue_script('kop-document-types', $uri . '/js/document-types.js', array(), (string) @filemtime($dir . '/js/document-types.js'), true);
    }, 20);

    $kop_dt_title = static function ($title) {
        if (!kop_dt_is_page()) return $title;
        $d = $GLOBALS['kop_doc_types_page'];
        $name = $d['type'] !== '' ? $d['types'][$d['type']]['plural'] : 'Documents by type';
        return $name . ' | ' . get_bloginfo('name');
    };
    add_filter('pre_get_document_title', $kop_dt_title, 20);
    add_filter('wpseo_title', $kop_dt_title, 20);
    add_filter('wpseo_opengraph_title', $kop_dt_title, 20);
    $kop_dt_desc = static function ($desc) {
        if (!kop_dt_is_page()) return $desc;
        $d = $GLOBALS['kop_doc_types_page'];
        return $d['type'] !== ''
            ? $d['types'][$d['type']]['plural'] . ' from troubled teen industry programs, side by side: ' . $d['types'][$d['type']]['desc']
            : 'Every document in the Kids Over Profits archive sorted by kind: handbooks, enrollment papers, inspection reports, court filings, news clippings and more.';
    };
    add_filter('wpseo_metadesc', $kop_dt_desc, 20);
    add_filter('wpseo_opengraph_desc', $kop_dt_desc, 20);
    $kop_dt_canonical = static function ($url) {
        return kop_dt_is_page() ? kop_dt_url($GLOBALS['kop_doc_types_page']['type']) : $url;
    };
    add_filter('wpseo_canonical', $kop_dt_canonical, 20);
    add_filter('wpseo_opengraph_url', $kop_dt_canonical, 20);
    add_filter('wpseo_robots', static function ($robots) {
        return kop_dt_is_page() ? 'index, follow' : $robots;
    }, 20);
}

// ---------------------------------------------------------------------------
// Tiles: the chips and the data the library filter reads
// ---------------------------------------------------------------------------

if (!function_exists('kop_dt_tile_attr')) {
    /** ' data-doc-types="a b"' for a document tile, '' when it has no type. */
    function kop_dt_tile_attr($id) {
        $types = kop_dt_types_for($id);
        return $types ? ' data-doc-types="' . esc_attr(implode(' ', $types)) . '"' : '';
    }
}

if (!function_exists('kop_dt_tile_chips')) {
    /**
     * The type chips under a tile's title (plain text: the tile is already a
     * link). The library's filter (js/doc-type-filter.js) links each type to
     * its /documents/<type>/ page.
     */
    function kop_dt_tile_chips($id) {
        $types = array_values(array_diff(kop_dt_types_for($id), array('other')));
        if (!$types) return '';
        if (function_exists('wp_enqueue_style') && !(function_exists('is_admin') && is_admin())) {
            wp_enqueue_style('kop-doc-type-filter', get_stylesheet_directory_uri() . '/css/doc-type-filter.css', array(),
                (string) @filemtime(get_stylesheet_directory() . '/css/doc-type-filter.css'));
        }
        $out = '';
        foreach ($types as $t) {
            $out .= '<span class="kop-dt-chip">' . esc_html(kop_dt_label($t)) . '</span>';
        }
        return '<span class="kop-dt-chips">' . $out . '</span>';
    }
}

if (!function_exists('kop_dt_enqueue_filter')) {
    /** The library's type filter (js/doc-type-filter.js), once, from any library render. */
    function kop_dt_enqueue_filter() {
        if (!function_exists('wp_enqueue_script') || (function_exists('is_admin') && is_admin())) return;
        $dir = get_stylesheet_directory();
        $uri = get_stylesheet_directory_uri();
        wp_enqueue_style('kop-doc-type-filter', $uri . '/css/doc-type-filter.css', array(), (string) @filemtime($dir . '/css/doc-type-filter.css'));
        wp_enqueue_script('kop-doc-type-filter', $uri . '/js/doc-type-filter.js', array(), (string) @filemtime($dir . '/js/doc-type-filter.js'), true);
        static $done = false;
        if ($done) return;
        $done = true;
        $labels = array();
        foreach (kop_dt_types() as $slug => $t) $labels[$slug] = array('label' => $t['label'], 'plural' => $t['plural'], 'url' => kop_dt_url($slug));
        wp_localize_script('kop-doc-type-filter', 'KOP_DOC_TYPES', array('types' => $labels));
    }
}

// ---------------------------------------------------------------------------
// Pencil fields (the doc: dialog in inc/inline-edit.php)
// ---------------------------------------------------------------------------

if (!function_exists('kop_dt_pencil_fields')) {
    function kop_dt_pencil_fields($id) {
        $options = array();
        foreach (kop_dt_types() as $slug => $t) $options[] = array('value' => $slug, 'label' => $t['label']);
        $sugg = kop_dt_sugg_get($id);
        $help = 'Tick every kind this document is; most are one. The document is listed on the page of each kind ticked.';
        if ($sugg && $sugg['status'] === 'pending' && $sugg['types'] !== '') {
            $help .= ' The AI suggests: ' . implode(', ', array_map('kop_dt_label', explode(',', $sugg['types']))) . ' (' . $sugg['why'] . ').';
        }
        return array(kop_ie_field('doc_types', 'What kind of document it is', 'checks', kop_dt_types_for($id), array(
            'section' => 'Document type',
            'options' => $options,
            'wide'    => true,
            'help'    => $help,
        )));
    }
}

if (!function_exists('kop_dt_pencil_save')) {
    /** Saves the ticked types when they changed. Returns a message part, '' when nothing changed. */
    function kop_dt_pencil_save($id, array $v) {
        if (!array_key_exists('doc_types', $v)) return '';
        $after = kop_dt_clean_types(is_array($v['doc_types']) ? $v['doc_types'] : array());
        if ($after === kop_dt_types_for($id)) return '';
        $entry = kop_dt_change(array((int) $id => $after), 'person', 'Set with the pencil');
        if (!$entry) return '';
        // An AI guess waiting on this document is settled by the person's choice.
        $sugg = kop_dt_sugg_get($id);
        if ($sugg && in_array($sugg['status'], array('pending', 'unclear', 'norule'), true) && $after) kop_dt_sugg_status($id, 'approved', kop_dt_who());
        return $after ? 'Type: ' . implode(', ', array_map('kop_dt_label', $after)) . '.' : 'Type taken off.';
    }
}

// ---------------------------------------------------------------------------
// The Document Archive's "By type" row links here
// ---------------------------------------------------------------------------

if (function_exists('add_filter')) {
    add_filter('kop_doc_archive_types', static function ($types) {
        array_unshift($types, array('label' => 'Every document by type', 'url_template' => '__kop_route_documents__', 'url_fallback' => '/' . kop_dt_base() . '/',
            'accent' => 'teal', 'desc' => 'Handbooks, enrollment papers, inspection reports, court filings and more, each kind from every program side by side.'));
        return $types;
    });
}

// A deleted document takes its types and suggestion with it.
if (function_exists('add_action')) {
    add_action('delete_attachment', static function ($id) {
        global $wpdb;
        $wpdb->query($wpdb->prepare('DELETE FROM ' . kop_dt_table() . ' WHERE attachment_id = %d', (int) $id));
        $wpdb->query($wpdb->prepare('DELETE FROM ' . kop_dt_sugg_table() . ' WHERE attachment_id = %d', (int) $id));
    });
}
