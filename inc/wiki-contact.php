<?php
/**
 * Program wiki entries name the r/troubledteens modmail as their contact
 * (api/lib-wiki-contact.php). The saved entries are rewritten once per
 * KOP_WIKI_CONTACT_VERSION; the editor's generator writes the modmail link
 * into every entry saved after that.
 */

if (!defined('ABSPATH')) {
    exit;
}

define('KOP_WIKI_CONTACT_VERSION', '1');

add_action('init', 'kop_wiki_contact_maybe_migrate', 30);

function kop_wiki_contact_maybe_migrate() {
    if (get_option('kop_wiki_contact_version') === KOP_WIKI_CONTACT_VERSION) {
        return;
    }
    // One request does it; the others skip while it runs.
    if (get_transient('kop_wiki_contact_running')) {
        return;
    }
    set_transient('kop_wiki_contact_running', 1, 10 * MINUTE_IN_SECONDS);
    $pdo = function_exists('kop_seed_pdo') ? kop_seed_pdo() : null;
    if (!$pdo) {
        delete_transient('kop_wiki_contact_running');
        return;
    }
    require_once get_stylesheet_directory() . '/api/lib-wiki-contact.php';
    $result = kop_wiki_contact_migrate($pdo, true);
    if (empty($result['error'])) {
        update_option('kop_wiki_contact_version', KOP_WIKI_CONTACT_VERSION, true);
    }
    update_option('kop_wiki_contact_last_run', $result + array('at' => gmdate('c')), false);
    delete_transient('kop_wiki_contact_running');
}
