<?php
/**
 * Review inbox source: websites sent in with the "Send to KOP" browser
 * extension that are not articles, lawsuits or bills (inc/source-submissions.php,
 * kop_source posts waiting as 'pending'). Keep publishes the entry (the post
 * type is not public, so it only leaves the queue), Trash and Restore use
 * WordPress's own trash, and the tags stay in the entry's _kop_tags field.
 */

if (!defined('ABSPATH')) {
    exit;
}

kop_rinbox_register('websites', function () {
    if (!function_exists('kop_ext_source_fields')) return null;
    return array(
        'label'    => 'Websites sent in',
        'group'    => 'Sent in by readers',
        'help'     => 'Pages sent with the Send to KOP browser button that are not news, lawsuits or bills. Keep the useful ones, trash the rest; Restore brings a trashed one back.',
        'views'    => array('pending' => 'Waiting', 'publish' => 'Kept', 'trash' => 'Trashed'),
        'tool_url' => admin_url('edit.php?post_type=' . KOP_EXT_SOURCE_CPT . '&post_status=pending'),
        'count'    => function () {
            global $wpdb;
            return (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s AND post_status = 'pending'", KOP_EXT_SOURCE_CPT));
        },
        'list'     => 'kop_rinbox_websites_list',
        'get'      => function ($key) {
            $p = kop_rinbox_websites_post($key);
            return $p ? kop_rinbox_websites_item($p) : null;
        },
        'act'      => 'kop_rinbox_websites_act',
        'save'     => 'kop_rinbox_websites_save',
        'tags_get' => function ($key) {
            return array_values(array_filter(array_map('trim', explode(',', (string) get_post_meta((int) $key, '_kop_tags', true))), 'strlen'));
        },
        'tags_set' => function ($key, array $tags) {
            if (!kop_rinbox_websites_post($key)) throw new RuntimeException('That website is gone.');
            update_post_meta((int) $key, '_kop_tags', implode(', ', $tags));
        },
    );
});

function kop_rinbox_websites_post($id) {
    global $wpdb;
    return $wpdb->get_row($wpdb->prepare(
        "SELECT ID, post_title, post_content, post_status, post_date FROM {$wpdb->posts} WHERE ID = %d AND post_type = %s",
        (int) $id, KOP_EXT_SOURCE_CPT
    )) ?: null;
}

function kop_rinbox_websites_list(array $q) {
    global $wpdb;
    $status = in_array($q['view'], array('pending', 'publish', 'trash'), true) ? $q['view'] : 'pending';
    $where = $wpdb->prepare('post_type = %s AND post_status = %s', KOP_EXT_SOURCE_CPT, $status);
    if ($q['search'] !== '') {
        $like = '%' . $wpdb->esc_like($q['search']) . '%';
        $where .= $wpdb->prepare(" AND (post_title LIKE %s OR ID IN (SELECT post_id FROM {$wpdb->postmeta}
            WHERE meta_key IN ('_kop_url', '_kop_site_name', '_kop_facility') AND meta_value LIKE %s))", $like, $like);
    }
    $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE $where");
    $rows = $wpdb->get_results("SELECT ID, post_title, post_content, post_status, post_date FROM {$wpdb->posts} WHERE $where
        ORDER BY post_date DESC, ID DESC LIMIT " . (int) $q['limit'] . ' OFFSET ' . (int) $q['offset']);
    return array('items' => array_map('kop_rinbox_websites_item', (array) $rows), 'total' => $total);
}

function kop_rinbox_websites_item($p) {
    $id = (int) $p->ID;
    $meta = array();
    foreach (array_keys(kop_ext_source_fields()) as $k) $meta[$k] = (string) get_post_meta($id, '_kop_' . $k, true);
    $sub = array_filter(array(
        $meta['site_name'] !== '' ? $meta['site_name'] : (string) wp_parse_url($meta['url'], PHP_URL_HOST),
        $meta['facility'] !== '' ? 'About: ' . $meta['facility'] : '',
        $meta['submitted_by'] !== '' ? 'Sent by ' . $meta['submitted_by'] : '',
    ));
    $text = trim(implode("\n", array_filter(array(
        $meta['selection'] !== '' ? '"' . kop_rinbox_excerpt($meta['selection'], 500) . '"' : '',
        $meta['description'] !== '' ? kop_rinbox_excerpt($meta['description'], 300) : '',
        trim((string) $p->post_content) !== '' ? 'Note: ' . kop_rinbox_excerpt($p->post_content, 300) : '',
    ))));
    $actions = array();
    if ($p->post_status === 'pending') {
        $actions[] = array('id' => 'publish', 'label' => 'Keep', 'style' => 'approve');
        $actions[] = array('id' => 'trash', 'label' => 'Trash', 'style' => 'reject');
    } elseif ($p->post_status === 'trash') {
        $actions[] = array('id' => 'untrash', 'label' => 'Restore', 'style' => 'undo');
    } else {
        $actions[] = array('id' => 'unpublish', 'label' => 'Back to waiting', 'style' => 'undo');
        $actions[] = array('id' => 'trash', 'label' => 'Trash', 'style' => 'reject');
    }
    $labels = array('pending' => 'Waiting', 'publish' => 'Kept', 'trash' => 'Trashed');
    $field = function ($k, $type = 'text') use ($meta) {
        return array('name' => $k, 'label' => kop_ext_source_fields()[$k], 'type' => $type, 'value' => $meta[$k]);
    };
    $sent_by = $field('submitted_by');
    $sent_by['readonly'] = true;
    return array(
        'key'          => (string) $id,
        'title'        => (string) $p->post_title,
        'subtitle'     => implode(' · ', $sub),
        'url'          => $meta['url'],
        'text'         => $text,
        'created'      => (string) $p->post_date,
        'status'       => (string) $p->post_status,
        'status_label' => $labels[$p->post_status] ?? (string) $p->post_status,
        'fields'       => array(
            array('name' => 'title', 'label' => 'Title', 'type' => 'text', 'value' => (string) $p->post_title),
            $field('url'), $field('site_name'), $field('author'), $field('published'), $field('facility'),
            $field('description', 'textarea'), $field('selection', 'textarea'), $sent_by,
        ),
        'actions'      => $actions,
        'links'        => array(array('label' => 'Edit in WordPress', 'url' => admin_url('post.php?post=' . $id . '&action=edit'))),
    );
}

function kop_rinbox_websites_act($key, $action, array $params) {
    $p = kop_rinbox_websites_post($key);
    if (!$p) throw new RuntimeException('That website is gone.');
    $id = (int) $p->ID;
    $name = '"' . $p->post_title . '"';
    switch ($action) {
        case 'publish':
        case 'unpublish':
            $to = $action === 'publish' ? 'publish' : 'pending';
            $res = wp_update_post(array('ID' => $id, 'post_status' => $to), true);
            if (is_wp_error($res) || !$res) throw new RuntimeException('WordPress would not change it' . (is_wp_error($res) ? ': ' . $res->get_error_message() : '.'));
            kop_rinbox_flush_counts();
            return array('message' => $to === 'publish'
                ? 'Kept ' . $name . '. It is on the Kept tab, where Back to waiting undoes this.'
                : $name . ' is waiting for review again.');
        case 'trash':
            if (!wp_trash_post($id)) throw new RuntimeException('WordPress would not move it to the trash.');
            kop_rinbox_flush_counts();
            return array('message' => 'Moved ' . $name . ' to the trash. Restore (on the Trashed tab) brings it back; WordPress empties the trash after 30 days.');
        case 'untrash':
            // Back to the status it had before (WordPress would otherwise make it a draft).
            $hook = function_exists('wp_untrash_post_set_previous_status') ? 'wp_untrash_post_set_previous_status' : null;
            if ($hook) add_filter('wp_untrash_post_status', $hook, 10, 3);
            $ok = wp_untrash_post($id);
            if ($hook) remove_filter('wp_untrash_post_status', $hook, 10);
            if (!$ok) throw new RuntimeException('WordPress would not restore it.');
            kop_rinbox_flush_counts();
            $now = kop_rinbox_websites_post($id);
            return array('message' => 'Restored ' . $name . ($now && $now->post_status === 'publish' ? ' to the Kept tab.' : ' to the waiting list.'));
    }
    throw new RuntimeException('Unknown action.');
}

/** Title and the website fields, cleaned as the edit screen's save does. */
function kop_rinbox_websites_save($key, array $fields) {
    $p = kop_rinbox_websites_post($key);
    if (!$p) throw new RuntimeException('That website is gone.');
    $id = (int) $p->ID;
    $done = false;
    if (array_key_exists('title', $fields)) {
        $title = sanitize_text_field((string) $fields['title']);
        if ($title === '') throw new RuntimeException('The title cannot be empty.');
        if ($title !== $p->post_title) {
            $res = wp_update_post(array('ID' => $id, 'post_title' => $title), true);
            if (is_wp_error($res) || !$res) throw new RuntimeException('WordPress would not save the title.');
        }
        $done = true;
    }
    if (array_key_exists('url', $fields) && trim((string) $fields['url']) !== '' && !preg_match('#^https?://\S+$#i', trim((string) $fields['url']))) {
        throw new RuntimeException('Link: write the full address, starting with https://');
    }
    foreach (array_keys(kop_ext_source_fields()) as $k) {
        if ($k === 'submitted_by' || $k === 'tags' || !array_key_exists($k, $fields)) continue;
        $clean = in_array($k, array('description', 'selection'), true)
            ? sanitize_textarea_field((string) $fields[$k]) : sanitize_text_field((string) $fields[$k]);
        update_post_meta($id, '_kop_' . $k, $clean);
        if ($k === 'url') {
            // The duplicate check reads the normalised address (api/url-dedupe.php).
            if (!function_exists('kop_normalize_url')) require_once get_stylesheet_directory() . '/api/url-dedupe.php';
            update_post_meta($id, '_kop_url_norm', (string) kop_normalize_url($clean));
        }
        $done = true;
    }
    return array('message' => $done ? 'Saved.' : 'Nothing to save.');
}
