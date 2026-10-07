<?php
/**
 * One-time corrections to existing legislation rows (seeds/legislation-updates.json).
 *
 * kop_apply_legislation_seeds() only inserts new bills and never touches existing ones,
 * so a bill's later status (signed, chaptered, died) lands here instead: each update
 * matches a row by id AND its current bill_number, sets only the fields it lists and
 * appends its note to reviewer_notes. The whole file runs once per 'version'; new
 * bills in seeds/legislation.json are inserted in the same pass.
 */

if (!defined('ABSPATH')) {
    exit;
}

function kop_apply_legislation_updates() {
    $done = array('updated' => array(), 'skipped' => array(), 'inserted' => array());
    $path = trailingslashit(get_stylesheet_directory()) . 'seeds/legislation-updates.json';
    $spec = file_exists($path) ? json_decode((string) file_get_contents($path), true) : null;
    if (!is_array($spec) || empty($spec['updates']) || !is_array($spec['updates'])) {
        return $done;
    }
    $pdo = kop_seed_pdo();
    if (!$pdo) {
        return null;
    }
    try {
        kop_legislation_status_enum($pdo);
    } catch (Throwable $e) {
        error_log('kop_legislation_status_enum: ' . $e->getMessage());
    }
    $done['inserted'] = kop_apply_legislation_seeds();

    $columns = array(
        'bill_number', 'bill_title', 'jurisdiction', 'chamber', 'session_year', 'bill_type', 'sponsors',
        'status', 'introduced_date', 'last_action_date', 'last_action_text', 'subject_tags', 'summary',
        'full_text_url', 'official_url', 'position', 'facilities_affected', 'tags',
    );
    $lists = array('sponsors', 'subject_tags', 'facilities_affected', 'tags');
    try {
        foreach ($spec['updates'] as $u) {
            $id = (int) ($u['id'] ?? 0);
            if ($id <= 0 || empty($u['set']) || !is_array($u['set'])) {
                continue;
            }
            $set = array();
            $args = array();
            foreach ($u['set'] as $col => $val) {
                if (!in_array($col, $columns, true)) {
                    continue;
                }
                if ($col === 'status' && !in_array($val, kop_legislation_statuses(), true)) {
                    continue;
                }
                if (in_array($col, $lists, true)) {
                    $val = wp_json_encode(is_array($val) ? array_values($val) : array(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                }
                $set[] = "`{$col}` = ?";
                $args[] = ($val === '' && substr($col, -5) === '_date') ? null : $val;
            }
            if (!$set) {
                continue;
            }
            if (!empty($u['note'])) {
                $set[] = "reviewer_notes = CONCAT(COALESCE(reviewer_notes, ''), ?)";
                $args[] = "\n[claude " . (string) ($spec['version'] ?? '') . '] ' . (string) $u['note'];
            }
            $args[] = $id;
            $args[] = (string) ($u['bill_number'] ?? '');
            $stmt = $pdo->prepare('UPDATE legislation SET ' . implode(', ', $set) . ' WHERE id = ? AND bill_number = ?');
            $stmt->execute($args);
            if ($stmt->rowCount()) {
                $done['updated'][] = $id;
            } else {
                $done['skipped'][] = $id;
            }
        }
    } catch (Throwable $e) {
        error_log('kop_apply_legislation_updates: ' . $e->getMessage());
        return null;
    }
    return $done;
}

function kop_maybe_apply_legislation_updates() {
    $path = trailingslashit(get_stylesheet_directory()) . 'seeds/legislation-updates.json';
    if (!file_exists($path)) {
        return;
    }
    $spec = json_decode((string) file_get_contents($path), true);
    $version = is_array($spec) ? (string) ($spec['version'] ?? '') : '';
    if ($version === '' || get_option('kop_legislation_updates_applied') === $version) {
        return;
    }
    // One request at a time; a failed run (no database) tries again on a later request.
    if (get_transient('kop_legislation_updates_lock')) {
        return;
    }
    set_transient('kop_legislation_updates_lock', 1, 5 * MINUTE_IN_SECONDS);
    $result = kop_apply_legislation_updates();
    if ($result !== null) {
        update_option('kop_legislation_updates_applied', $version, false);
        update_option('kop_legislation_updates_result', $result, false);
    }
    delete_transient('kop_legislation_updates_lock');
}
add_action('init', 'kop_maybe_apply_legislation_updates', 25);
