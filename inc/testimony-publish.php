<?php
/**
 * Survivor testimony is published by default: every account on file has
 * already been published elsewhere. New entries start with "OK to publish"
 * ticked (js/data-form/testimony.js, inc/fornits.php) and an entry with no
 * publish flag reads as published (kop_facility_testimony_list()); an editor
 * can still untick one to hide it.
 *
 * The entries saved before that change carry publish:false as the old
 * default, not as anyone's choice. kop_testimony_publish_existing() ticks
 * them once, through the facility save path, on the first admin page load
 * after deploy; KOP_TESTIMONY_PUBLISH_VERSION gates it.
 */

if (!defined('ABSPATH')) {
    exit;
}

const KOP_TESTIMONY_PUBLISH_VERSION = '1';

/**
 * Tick "OK to publish" on every unpublished testimony entry. Returns
 * {facilities, entries} changed; a dry run with $apply = false.
 */
function kop_testimony_publish_existing($apply = true) {
    $opts = kop_wbf_opts();
    $table = kop_facility_table('facilities', $opts);
    $stmt = $opts['pdo']->query("SELECT id FROM {$table} WHERE json_data LIKE '%\"publish\":false%'");
    $ids = $stmt ? array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)) : array();
    $done = array('facilities' => 0, 'entries' => 0);
    foreach ($ids as $fid) {
        kop_v2_with_write_lock($opts['pdo'], function () use ($fid, $opts, $apply, &$done) {
            $stored = kop_facility_load($fid, $opts);
            if (!$stored) return;
            $doc = $stored['doc'];
            $n = 0;
            foreach ((array) ($doc['survivorTestimony'] ?? array()) as $i => $t) {
                if (is_array($t) && ($t['publish'] ?? true) !== true) {
                    $doc['survivorTestimony'][$i]['publish'] = true;
                    $n++;
                }
            }
            if ($n === 0) return;
            if ($apply) kop_wbf_save($doc, $opts);
            $done['facilities']++;
            $done['entries'] += $n;
        });
    }
    return $done;
}

/**
 * The same for provider records (providers_master, the data form's
 * "providers" category), whose json_data carries survivorTestimony lists
 * at any depth. Returns how many entries were ticked.
 */
function kop_testimony_publish_providers(PDO $pdo, $apply = true) {
    $table = '';
    foreach (array($GLOBALS['wpdb']->prefix . 'providers_master', 'providers_master') as $t) {
        $q = $pdo->prepare('SHOW TABLES LIKE ?');
        $q->execute(array($t));
        if ($q->fetchColumn()) { $table = $t; break; }
    }
    if ($table === '') return 0;
    $tick = function (&$value) use (&$tick) {
        $n = 0;
        if (!is_array($value)) return 0;
        foreach ($value as $key => &$child) {
            if ($key === 'survivorTestimony' && is_array($child)) {
                foreach ($child as &$entry) {
                    if (is_array($entry) && ($entry['publish'] ?? true) !== true) {
                        $entry['publish'] = true;
                        $n++;
                    }
                }
                unset($entry);
            } elseif (is_array($child)) {
                $n += $tick($child);
            }
        }
        unset($child);
        return $n;
    };
    $rows = $pdo->query("SELECT id, json_data FROM `{$table}` WHERE json_data LIKE '%\"publish\":false%'")->fetchAll(PDO::FETCH_ASSOC);
    $update = $pdo->prepare("UPDATE `{$table}` SET json_data = ? WHERE id = ?");
    $total = 0;
    foreach ($rows as $row) {
        $data = json_decode((string) $row['json_data'], true);
        if (!is_array($data)) continue;
        $n = $tick($data);
        if ($n === 0) continue;
        if ($apply) $update->execute(array(wp_json_encode($data), (int) $row['id']));
        $total += $n;
    }
    return $total;
}

add_action('admin_init', function () {
    if (get_option('kop_testimony_publish_version') === KOP_TESTIMONY_PUBLISH_VERSION) return;
    if (!current_user_can('manage_options') || wp_doing_ajax()) return;
    if (!function_exists('kop_wbf_opts') || !function_exists('kop_facility_load')) return;
    try {
        $done = kop_testimony_publish_existing(true);
        $providers = kop_testimony_publish_providers(kop_wbf_opts()['pdo'], true);
        update_option('kop_testimony_publish_version', KOP_TESTIMONY_PUBLISH_VERSION, false);
        error_log(sprintf('KOP testimony: published %d entries on %d facilities and %d on providers.', $done['entries'], $done['facilities'], $providers));
    } catch (Throwable $e) {
        // Tried again on the next admin page load.
        error_log('KOP testimony publish failed: ' . $e->getMessage());
    }
});
