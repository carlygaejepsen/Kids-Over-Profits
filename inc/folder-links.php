<?php
/**
 * Folder links: two or more FileBird folders marked as the SAME facility under
 * different names (a rename, a rebrand), so every facility document feed shows
 * their contents together. The admin tool is api/link-folders.php; the review
 * inbox lists its suggested pairs (inc/review-inbox/folder-links.php). Both
 * call these functions, so a link or a dismissal works the same from either.
 *
 * Tables: {prefix}kop_folder_links (folder_a < folder_b, note, created_at),
 * read by kop_get_linked_folder_ids() / kop_get_equivalent_folder_ids() in
 * inc/database.php, and {prefix}kop_folder_link_dismissals (pairs marked
 * "alternate name": a known other name, not one facility; they leave the
 * suggestions and never touch the feeds).
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('kop_folder_links_tables')) {
    /** [fbv, fbv_rel, tags, links, dismissals] table names. */
    function kop_folder_links_tables() {
        global $wpdb;
        return array(
            'fbv'        => $wpdb->prefix . 'fbv',
            'rel'        => $wpdb->prefix . 'fbv_attachment_folder',
            'tags'       => $wpdb->prefix . 'kop_media_folder_tags',
            'links'      => $wpdb->prefix . 'kop_folder_links',
            'dismissals' => $wpdb->prefix . 'kop_folder_link_dismissals',
        );
    }
}

if (!function_exists('kop_folder_links_install')) {
    /** Idempotent. Pairs are stored with folder_a < folder_b, so a pair exists once whatever order it was picked in. */
    function kop_folder_links_install() {
        global $wpdb;
        $t = kop_folder_links_tables();
        $wpdb->query("CREATE TABLE IF NOT EXISTS {$t['links']} (
            folder_a BIGINT UNSIGNED NOT NULL,
            folder_b BIGINT UNSIGNED NOT NULL,
            note VARCHAR(255) NOT NULL DEFAULT '',
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (folder_a, folder_b),
            KEY idx_b (folder_b)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        // A dismissal means the pair is a known alternate-name match, but NOT a
        // rebrand or shared facility. It stays separate from kop_folder_links so
        // it never affects document-feed equivalence.
        $wpdb->query("CREATE TABLE IF NOT EXISTS {$t['dismissals']} (
            folder_a BIGINT UNSIGNED NOT NULL,
            folder_b BIGINT UNSIGNED NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (folder_a, folder_b),
            KEY idx_b (folder_b)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }
}

if (!function_exists('kop_folder_links_folders')) {
    /** Every FileBird folder: id => {id, name, parent}. */
    function kop_folder_links_folders() {
        global $wpdb;
        $t = kop_folder_links_tables();
        $by_id = array();
        foreach ((array) $wpdb->get_results("SELECT id, name, parent FROM {$t['fbv']} WHERE type = 0") as $f) {
            $by_id[(int) $f->id] = $f;
        }
        return $by_id;
    }
}

if (!function_exists('kop_folder_links_path')) {
    /** Full "Parent / Child" path for a folder id. */
    function kop_folder_links_path($fid, array $by_id, $depth = 0) {
        if ($depth > 10 || !isset($by_id[(int) $fid])) {
            return '(deleted folder #' . (int) $fid . ')';
        }
        $f = $by_id[(int) $fid];
        $prefix = ((int) $f->parent !== 0) ? kop_folder_links_path($f->parent, $by_id, $depth + 1) . ' / ' : '';
        return $prefix . $f->name;
    }
}

if (!function_exists('kop_folder_links_pair_key')) {
    function kop_folder_links_pair_key($a, $b) {
        return min((int) $a, (int) $b) . ':' . max((int) $a, (int) $b);
    }
}

if (!function_exists('kop_folder_links_file_counts')) {
    /** Direct file counts (FileBird filings + theme tags) per folder. */
    function kop_folder_links_file_counts() {
        global $wpdb;
        $t = kop_folder_links_tables();
        $counts = array();
        foreach ((array) $wpdb->get_results("SELECT folder_id, COUNT(*) AS n FROM {$t['rel']} GROUP BY folder_id") as $r) {
            $counts[(int) $r->folder_id] = (int) $r->n;
        }
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $t['tags'])) === $t['tags']) {
            foreach ((array) $wpdb->get_results("SELECT folder_id, COUNT(*) AS n FROM {$t['tags']} GROUP BY folder_id") as $r) {
                $counts[(int) $r->folder_id] = ($counts[(int) $r->folder_id] ?? 0) + (int) $r->n;
            }
        }
        return $counts;
    }
}

if (!function_exists('kop_folder_links_rows')) {
    /** 'links' or 'dismissals' rows, newest first. */
    function kop_folder_links_rows($which) {
        global $wpdb;
        $t = kop_folder_links_tables();
        $cols = $which === 'links' ? 'folder_a, folder_b, note, created_at' : 'folder_a, folder_b, created_at';
        return (array) $wpdb->get_results("SELECT {$cols} FROM {$t[$which === 'links' ? 'links' : 'dismissals']} ORDER BY created_at DESC");
    }
}

if (!function_exists('kop_folder_links_suggestions')) {
    /**
     * Pairs of folders whose names are two known names or aliases of one
     * facility record (facilities_master), not linked yet (directly, through
     * other links or by having the same name) and not dismissed. Each:
     * {a, b (folder ids, a < b), a_name, b_name, basis (the record), matched_names}.
     * One suggestion per name pair; sorted by record; at most $limit (0: all).
     */
    function kop_folder_links_suggestions(array $by_id, $limit = 100) {
        global $wpdb;
        if (!function_exists('kop_normalize_name_key')) require_once get_stylesheet_directory() . '/api/facility-aliases.php';
        $links = kop_folder_links_rows('links');
        $dismissals = kop_folder_links_rows('dismissals');
        $dismissed_pairs = array();
        foreach ($dismissals as $dismissal) {
            $dismissed_pairs[kop_folder_links_pair_key($dismissal->folder_a, $dismissal->folder_b)] = true;
        }

        $suggestions = array();
        $suggestion_keys = array();
        $linked_groups = array();
        $group_for = static function ($id) use (&$linked_groups, &$group_for) {
            $id = (int) $id;
            if (!isset($linked_groups[$id])) $linked_groups[$id] = $id;
            if ($linked_groups[$id] !== $id) $linked_groups[$id] = $group_for($linked_groups[$id]);
            return $linked_groups[$id];
        };
        $join_groups = static function ($a, $b) use (&$linked_groups, $group_for) {
            $ga = $group_for($a);
            $gb = $group_for($b);
            if ($ga !== $gb) $linked_groups[$gb] = $ga;
        };
        foreach ($links as $link) $join_groups($link->folder_a, $link->folder_b);

        // Same-name folders (different parent trees) already merge in feeds via
        // kop_get_same_name_folder_ids(), so treat them as one group here too.
        // Otherwise linking "Alpha #10" to "Beta #30" leaves "Alpha #20" / "Beta #30"
        // unlinked and the same name pair reappears in the suggestion list.
        $same_name_ids = array();
        foreach ($by_id as $folder) {
            $same_key = strtolower(trim((string) $folder->name));
            if ($same_key !== '') $same_name_ids[$same_key][] = (int) $folder->id;
        }
        foreach ($same_name_ids as $ids) {
            for ($i = 1; $i < count($ids); $i++) $join_groups($ids[0], $ids[$i]);
        }

        // Dismissals are stored by ID pair, but a dismissal of one ID pair should
        // hide every same-name variant of that name pair as well.
        $dismissed_name_pairs = array();
        foreach ($dismissals as $dismissal) {
            $da = (int) $dismissal->folder_a;
            $db = (int) $dismissal->folder_b;
            if (!isset($by_id[$da]) || !isset($by_id[$db])) continue;
            $name_keys = array(
                kop_normalize_name_key((string) $by_id[$da]->name),
                kop_normalize_name_key((string) $by_id[$db]->name),
            );
            sort($name_keys, SORT_STRING);
            $dismissed_name_pairs[implode(':', $name_keys)] = true;
        }

        $folders_by_name = array();
        foreach ($by_id as $folder) {
            $key = kop_normalize_name_key((string) $folder->name);
            if ($key !== '') $folders_by_name[$key][] = (int) $folder->id;
        }

        $known_name_scopes = static function (array $decoded, $unique_name) {
            $scopes = array(array((string) $unique_name));
            $scopes[0] = array_merge($scopes[0], kop_collect_self_names($decoded), kop_collect_match_aliases($decoded));
            $nested = $decoded['data']['facilities'] ?? $decoded['facilities'] ?? array();
            if (is_array($nested)) {
                foreach ($nested as $facility) {
                    if (!is_array($facility)) continue;
                    $names = array();
                    $ident = $facility['identification'] ?? array();
                    if (!is_array($ident)) continue;
                    foreach (array('name', 'currentName') as $field) $names[] = (string) ($ident[$field] ?? '');
                    foreach (array('otherNames', 'pastNames', 'matchAliases') as $field) {
                        if (is_array($ident[$field] ?? null)) $names = array_merge($names, $ident[$field]);
                    }
                    $scopes[] = $names;
                }
            }
            return array_map(static function ($names) {
                $out = array();
                foreach ($names as $name) {
                    $name = trim((string) $name);
                    $key = kop_normalize_name_key($name);
                    if ($key !== '') $out[$key] = $name;
                }
                return $out;
            }, $scopes);
        };

        $master_rows = $wpdb->get_results('SELECT unique_name, json_data FROM facilities_master');
        foreach ((array) $master_rows as $row) {
            $decoded = json_decode((string) $row->json_data, true);
            if (!is_array($decoded)) continue;
            foreach ($known_name_scopes($decoded, $row->unique_name) as $scope) {
                $matched = array();
                foreach ($scope as $key => $known_name) {
                    foreach ($folders_by_name[$key] ?? array() as $folder_id) $matched[$folder_id] = $known_name;
                }
                $matched_ids = array_keys($matched);
                for ($i = 0; $i < count($matched_ids); $i++) {
                    for ($j = $i + 1; $j < count($matched_ids); $j++) {
                        $a = (int) $matched_ids[$i];
                        $b = (int) $matched_ids[$j];
                        if (kop_normalize_name_key((string) $by_id[$a]->name) === kop_normalize_name_key((string) $by_id[$b]->name)) continue;
                        if ($group_for($a) === $group_for($b)) continue;
                        $lo = min($a, $b);
                        $hi = max($a, $b);
                        // Multiple FileBird folders can represent the same name in
                        // different parent trees. Same-name folders already merge,
                        // so show one suggestion for the name pair, not every ID pair.
                        $id_key = kop_folder_links_pair_key($lo, $hi);
                        if (isset($dismissed_pairs[$id_key])) continue;
                        $name_keys = array(
                            kop_normalize_name_key((string) $by_id[$a]->name),
                            kop_normalize_name_key((string) $by_id[$b]->name),
                        );
                        sort($name_keys, SORT_STRING);
                        $key = implode(':', $name_keys);
                        if (isset($dismissed_name_pairs[$key])) continue;
                        if (isset($suggestion_keys[$key])) continue;
                        $suggestion_keys[$key] = true;
                        $suggestions[] = array(
                            'a' => $lo,
                            'b' => $hi,
                            'a_name' => $by_id[$lo]->name ?? ('folder #' . $lo),
                            'b_name' => $by_id[$hi]->name ?? ('folder #' . $hi),
                            'basis' => $row->unique_name,
                            'matched_names' => array($matched[$a], $matched[$b]),
                        );
                    }
                }
            }
        }
        usort($suggestions, static function ($left, $right) {
            return strcasecmp($left['basis'], $right['basis']);
        });
        return $limit > 0 ? array_slice($suggestions, 0, $limit) : $suggestions;
    }
}

if (!function_exists('kop_folder_links_link')) {
    /**
     * Link any number of folders as one facility: each to the first (feeds
     * expand links transitively, so a star merges the whole set, and unlinking
     * one leaf later removes only that folder). Same-name folders are skipped
     * (feeds already merge them). Returns {ok, created, messages: [...]}.
     */
    function kop_folder_links_link(array $picked, $note, array $by_id) {
        global $wpdb;
        $t = kop_folder_links_tables();
        $ids = array();
        $missing = false;
        foreach (array_map('intval', $picked) as $fid) {
            if ($fid <= 0 || !isset($by_id[$fid])) { $missing = true; continue; }
            $ids[$fid] = $fid;
        }
        $ids = array_values($ids);
        if ($missing || count($ids) < 2) {
            return array('ok' => false, 'created' => 0, 'messages' => array('Pick at least two different existing folders first.'));
        }
        $note = mb_substr(sanitize_text_field((string) $note), 0, 255);
        $hub = $ids[0];
        $created = 0;
        $already = 0;
        $same_name = array();
        for ($i = 1; $i < count($ids); $i++) {
            $other = $ids[$i];
            if (strcasecmp(trim($by_id[$hub]->name), trim($by_id[$other]->name)) === 0) {
                $same_name[] = $by_id[$other]->name;
                continue;
            }
            $lo = min($hub, $other);
            $hi = max($hub, $other);
            if ($wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$t['links']} WHERE folder_a = %d AND folder_b = %d", $lo, $hi))) {
                $already++;
            } elseif ($wpdb->insert($t['links'], array('folder_a' => $lo, 'folder_b' => $hi, 'note' => $note, 'created_at' => current_time('mysql')))) {
                $created++;
            } else {
                $already++;
            }
        }
        $names = array_map(static function ($fid) use ($by_id) { return '"' . $by_id[$fid]->name . '"'; }, $ids);
        $messages = array();
        if ($created > 0) {
            $messages[] = 'Linked ' . count($ids) . ' folders as the same facility: ' . implode(', ', $names)
                . ' — all of them now show the merged contents in facility document feeds.'
                . ($already ? " ({$already} pair" . ($already === 1 ? ' was' : 's were') . ' already linked.)' : '');
        } elseif ($already > 0) {
            $messages[] = 'Those folders are already linked.';
        } else {
            $messages[] = 'Those folders share the same name — feeds already merge them automatically, no link needed.';
        }
        if ($same_name) {
            $quoted = array_map(static function ($n) { return '"' . $n . '"'; }, $same_name);
            $messages[] = 'Skipped same-name folder' . (count($same_name) === 1 ? '' : 's') . ' ' . implode(', ', $quoted)
                . ': feeds already merge folders with identical names.';
        }
        return array('ok' => $created > 0, 'created' => $created, 'messages' => $messages);
    }
}

if (!function_exists('kop_folder_links_dismiss')) {
    /** Mark two folders as alternate names (not one facility): they leave the suggestions. Returns {ok, message}. */
    function kop_folder_links_dismiss($a, $b, array $by_id) {
        global $wpdb;
        $t = kop_folder_links_tables();
        $a = (int) $a;
        $b = (int) $b;
        if ($a <= 0 || $b <= 0 || !isset($by_id[$a]) || !isset($by_id[$b]) || $a === $b) {
            return array('ok' => false, 'message' => 'Pick two different existing folders first.');
        }
        $lo = min($a, $b);
        $hi = max($a, $b);
        $ins = !$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$t['dismissals']} WHERE folder_a = %d AND folder_b = %d", $lo, $hi))
            && $wpdb->insert($t['dismissals'], array('folder_a' => $lo, 'folder_b' => $hi, 'created_at' => current_time('mysql')));
        return array('ok' => (bool) $ins, 'message' => $ins
            ? 'Marked "' . $by_id[$a]->name . '" and "' . $by_id[$b]->name . '" as alternate names. They will stay separate and leave the suggestion list.'
            : 'That pair is already marked as an alternate-name match.');
    }
}

if (!function_exists('kop_folder_links_restore')) {
    /** Take back a dismissal: the pair can be suggested again. Returns {ok, message}. */
    function kop_folder_links_restore($a, $b) {
        global $wpdb;
        $t = kop_folder_links_tables();
        $deleted = $wpdb->delete($t['dismissals'], array('folder_a' => min((int) $a, (int) $b), 'folder_b' => max((int) $a, (int) $b)), array('%d', '%d'));
        return array('ok' => (bool) $deleted, 'message' => $deleted ? 'Alternate-name dismissal restored to the suggestion list.' : 'That dismissal no longer exists.');
    }
}

if (!function_exists('kop_folder_links_unlink')) {
    /** Remove one link: the folders keep their own files, feeds stop merging them. Returns {ok, message}. */
    function kop_folder_links_unlink($a, $b) {
        global $wpdb;
        $t = kop_folder_links_tables();
        $deleted = $wpdb->delete($t['links'], array('folder_a' => min((int) $a, (int) $b), 'folder_b' => max((int) $a, (int) $b)), array('%d', '%d'));
        return array('ok' => (bool) $deleted, 'message' => $deleted ? 'Link removed.' : 'That link no longer exists.');
    }
}
