<?php
/**
 * One-time corrections to existing lawsuit rows (seeds/lawsuit-updates.json).
 *
 * kop_apply_lawsuit_seeds() only inserts new cases and runs with the template
 * assignments, so a case's later status (settled, dismissed, a new ruling) lands
 * here instead: each update matches a row by id AND its current case_name, sets
 * only the fields it lists, adds 'add_source_urls' to the row's source_urls and
 * appends its note to reviewer_notes. The whole file runs once per 'version';
 * new cases in seeds/lawsuits.json are inserted in the same pass and linked to
 * their facilities by name.
 */

if (!defined('ABSPATH')) {
    exit;
}

function kop_apply_lawsuit_updates() {
    $done = array('updated' => array(), 'skipped' => array(), 'inserted' => array());
    $path = trailingslashit(get_stylesheet_directory()) . 'seeds/lawsuit-updates.json';
    $spec = file_exists($path) ? json_decode((string) file_get_contents($path), true) : null;
    if (!is_array($spec) || empty($spec['updates']) || !is_array($spec['updates'])) {
        return $done;
    }
    $pdo = kop_seed_pdo();
    if (!$pdo) {
        return null;
    }

    $done['inserted'] = kop_apply_lawsuit_seeds();
    $new_ids = array_values(array_filter($done['inserted'], 'is_int'));
    if ($new_ids) {
        $lib = get_stylesheet_directory() . '/api/lawsuit-facility-links.php';
        if (file_exists($lib)) {
            require_once $lib;
        }
        if (function_exists('kop_sync_lawsuit_facility_links')) {
            $mentions = $pdo->prepare('SELECT facilities_mentioned FROM lawsuits WHERE id = ?');
            foreach ($new_ids as $new_id) {
                try {
                    $mentions->execute(array($new_id));
                    kop_sync_lawsuit_facility_links($pdo, $new_id, (string) $mentions->fetchColumn(), 'seed');
                } catch (Throwable $e) {
                    error_log('kop_apply_lawsuit_updates link ' . $new_id . ': ' . $e->getMessage());
                }
            }
        }
    }

    $columns = array(
        'case_name', 'case_number', 'court', 'jurisdiction', 'filing_date', 'status', 'plaintiffs', 'defendants',
        'facilities_mentioned', 'staff_mentioned', 'organizations_mentioned', 'claims', 'outcome', 'settlement_amount',
        'summary', 'source_urls', 'document_urls', 'tags', 'publication_status',
    );
    $lists = array('plaintiffs', 'defendants', 'facilities_mentioned', 'staff_mentioned', 'organizations_mentioned',
        'claims', 'source_urls', 'document_urls', 'tags');
    $statuses = array('filed', 'in_progress', 'settled', 'dismissed', 'ruling', 'appeal', 'closed', 'unknown');
    $encode = static function ($v) {
        return wp_json_encode(is_array($v) ? array_values($v) : array(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    };
    try {
        $read = $pdo->prepare('SELECT source_urls FROM lawsuits WHERE id = ? AND case_name = ?');
        foreach ($spec['updates'] as $u) {
            $id = (int) ($u['id'] ?? 0);
            $name = (string) ($u['case_name'] ?? '');
            if ($id <= 0 || $name === '') {
                continue;
            }
            $read->execute(array($id, $name));
            $current_sources = $read->fetchColumn();
            if ($current_sources === false) {
                $done['skipped'][] = $id;
                continue;
            }
            $set = array();
            $args = array();
            foreach ((array) ($u['set'] ?? array()) as $col => $val) {
                if (!in_array($col, $columns, true)) {
                    continue;
                }
                if ($col === 'publication_status' && !in_array($val, array('draft', 'pending', 'approved', 'published', 'rejected'), true)) {
                    continue;
                }
                if ($col === 'status' && !in_array($val, $statuses, true)) {
                    continue;
                }
                if (in_array($col, $lists, true)) {
                    $val = $encode($val);
                }
                $set[] = "`{$col}` = ?";
                $args[] = ($val === '' && $col === 'filing_date') ? null : $val;
            }
            if (!empty($u['add_source_urls']) && is_array($u['add_source_urls']) && !isset($u['set']['source_urls'])) {
                $have = json_decode((string) $current_sources, true);
                $have = is_array($have) ? $have : array();
                $merged = array_values(array_unique(array_merge($have, array_values($u['add_source_urls']))));
                $set[] = '`source_urls` = ?';
                $args[] = $encode($merged);
            }
            if (!$set) {
                continue;
            }
            if (!empty($u['note'])) {
                $set[] = "reviewer_notes = CONCAT(COALESCE(reviewer_notes, ''), ?)";
                $args[] = "\n[claude " . (string) ($spec['version'] ?? '') . '] ' . (string) $u['note'];
            }
            $args[] = $id;
            $args[] = $name;
            $stmt = $pdo->prepare('UPDATE lawsuits SET ' . implode(', ', $set) . ' WHERE id = ? AND case_name = ?');
            $stmt->execute($args);
            if ($stmt->rowCount()) {
                $done['updated'][] = $id;
            } else {
                $done['skipped'][] = $id;
            }
        }
    } catch (Throwable $e) {
        error_log('kop_apply_lawsuit_updates: ' . $e->getMessage());
        return null;
    }
    return $done;
}

function kop_maybe_apply_lawsuit_updates() {
    $path = trailingslashit(get_stylesheet_directory()) . 'seeds/lawsuit-updates.json';
    if (!file_exists($path)) {
        return;
    }
    $spec = json_decode((string) file_get_contents($path), true);
    $version = is_array($spec) ? (string) ($spec['version'] ?? '') : '';
    if ($version === '' || get_option('kop_lawsuit_updates_applied') === $version) {
        return;
    }
    // One request at a time; a failed run (no database) tries again on a later request.
    if (get_transient('kop_lawsuit_updates_lock')) {
        return;
    }
    set_transient('kop_lawsuit_updates_lock', 1, 5 * MINUTE_IN_SECONDS);
    $result = kop_apply_lawsuit_updates();
    if ($result !== null) {
        update_option('kop_lawsuit_updates_applied', $version, false);
        update_option('kop_lawsuit_updates_result', $result, false);
    }
    delete_transient('kop_lawsuit_updates_lock');
}
add_action('init', 'kop_maybe_apply_lawsuit_updates', 25);
