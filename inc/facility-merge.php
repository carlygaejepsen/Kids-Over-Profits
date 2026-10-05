<?php
/**
 * KOP Tools > Merge Duplicates: facility records that are one place under
 * two entries, found automatically and merged with one click.
 *
 * Finding: inc/facility-merge-match.php (names one word apart, spelled
 * differently, the company's name in front, the same street address).
 * Renamed programs are never offered: each name era is its own record.
 * "Not the same" hides a pair for good (option kop_facility_merge_dismissed).
 *
 * Merging (kop_fmerge_execute()) leaves one record. The kept record's
 * document takes everything the other one had (api/merge-facility-duplicates.php
 * kop_mfd_merge_docs: empty fields fill, lists join, the other name becomes an
 * otherName), every row anywhere that points at the dropped id or unique name
 * moves to the kept one (kop_fmerge_ref_tables()), and its document library
 * joins the kept one: files, tags and subfolders move into the kept record's
 * FileBird folder (same-named subfolders such as "Woodbury Reports Mentions"
 * are joined), and the emptied folders are deleted. The dropped record's page
 * address 301s to the kept page (option kop_facility_merged_into).
 *
 * Every merge can be undone from the Merged tab: the log (option
 * kop_facility_merge_log) holds both documents as they were and every row
 * that moved or was removed, and Undo puts them back.
 *
 * Offline test: php scripts/test-facility-merge.php (in-memory copy of
 * tmp/prod.sqlite).
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/facility-merge-match.php';

/* ---- Database helpers (plain PDO, so the test runs them on SQLite) ------ */

if (!function_exists('kop_fmerge_is_sqlite')) {
    function kop_fmerge_is_sqlite(PDO $pdo) {
        return $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
    }
}

if (!function_exists('kop_fmerge_table_columns')) {
    /** Column names of $table, or array() when it does not exist. */
    function kop_fmerge_table_columns(PDO $pdo, $table) {
        static $cache = array();
        $key = spl_object_hash($pdo) . '|' . $table;
        if (isset($cache[$key])) return $cache[$key];
        $cols = array();
        try {
            if (kop_fmerge_is_sqlite($pdo)) {
                foreach ($pdo->query('PRAGMA table_info(`' . $table . '`)')->fetchAll(PDO::FETCH_ASSOC) as $r) $cols[] = $r['name'];
            } else {
                $exists = $pdo->prepare('SHOW TABLES LIKE ?');
                $exists->execute(array($table));
                if ($exists->fetchColumn() !== false) {
                    foreach ($pdo->query('SHOW COLUMNS FROM `' . $table . '`')->fetchAll(PDO::FETCH_ASSOC) as $r) $cols[] = $r['Field'];
                }
            }
        } catch (Throwable $e) {
            $cols = array();
        }
        return $cache[$key] = $cols;
    }
}

if (!function_exists('kop_fmerge_primary_key')) {
    /** Primary key columns of $table (array() when it has none). */
    function kop_fmerge_primary_key(PDO $pdo, $table) {
        static $cache = array();
        $key = spl_object_hash($pdo) . '|' . $table;
        if (isset($cache[$key])) return $cache[$key];
        $pk = array();
        try {
            if (kop_fmerge_is_sqlite($pdo)) {
                $rows = $pdo->query('PRAGMA table_info(`' . $table . '`)')->fetchAll(PDO::FETCH_ASSOC);
                usort($rows, function ($a, $b) { return (int) $a['pk'] <=> (int) $b['pk']; });
                foreach ($rows as $r) if ((int) $r['pk'] > 0) $pk[] = $r['name'];
            } else {
                foreach ($pdo->query("SHOW KEYS FROM `{$table}` WHERE Key_name = 'PRIMARY'")->fetchAll(PDO::FETCH_ASSOC) as $r) $pk[] = $r['Column_name'];
            }
        } catch (Throwable $e) {
            $pk = array();
        }
        return $cache[$key] = $pk;
    }
}

if (!function_exists('kop_fmerge_where')) {
    /** "a <=> ? AND b <=> ?" for one row, by its primary key or else every column. */
    function kop_fmerge_where(PDO $pdo, $table, array $row, array $override = array()) {
        $cols = kop_fmerge_primary_key($pdo, $table);
        if (!$cols) $cols = array_keys($row);
        $op = kop_fmerge_is_sqlite($pdo) ? ' IS ' : ' <=> ';
        $parts = array();
        $params = array();
        foreach ($cols as $c) {
            $parts[] = '`' . $c . '`' . $op . '?';
            $params[] = array_key_exists($c, $override) ? $override[$c] : $row[$c];
        }
        return array(implode(' AND ', $parts), $params);
    }
}

if (!function_exists('kop_fmerge_move_rows')) {
    /**
     * Point every row of $table whose $col is $from at $to. A row that would
     * collide with one already there (the kept record already had that link)
     * is removed instead. $extra: other columns to rewrite on moved rows
     * (unique_name next to facility_id). Records what it did on $undo.
     * Returns [moved, removed].
     */
    function kop_fmerge_move_rows(PDO $pdo, $table, $col, $from, $to, array &$undo, array $extra = array(), $where_sql = '') {
        $cols = kop_fmerge_table_columns($pdo, $table);
        if (!$cols || !in_array($col, $cols, true)) return array(0, 0);
        $extra = array_intersect_key($extra, array_flip($cols));
        $sel = $pdo->prepare('SELECT * FROM `' . $table . '` WHERE `' . $col . '` = ?' . ($where_sql !== '' ? ' AND ' . $where_sql : ''));
        $sel->execute(array($from));
        $rows = $sel->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) return array(0, 0);
        $set = array('`' . $col . '` = ?');
        $set_params = array($to);
        foreach ($extra as $c => $v) {
            $set[] = '`' . $c . '` = ?';
            $set_params[] = $v;
        }
        $verb = kop_fmerge_is_sqlite($pdo) ? 'UPDATE OR IGNORE' : 'UPDATE IGNORE';
        $moved = array();
        $removed = array();
        foreach ($rows as $row) {
            list($w, $wp) = kop_fmerge_where($pdo, $table, $row);
            $up = $pdo->prepare($verb . ' `' . $table . '` SET ' . implode(', ', $set) . ' WHERE ' . $w);
            $up->execute(array_merge($set_params, $wp));
            if ($up->rowCount() > 0) {
                $moved[] = $row;
            } else {
                $del = $pdo->prepare('DELETE FROM `' . $table . '` WHERE ' . $w);
                $del->execute($wp);
                if ($del->rowCount() > 0) $removed[] = $row;
            }
        }
        $undo[] = array('table' => $table, 'col' => $col, 'to' => $to, 'extra' => $extra, 'moved' => $moved, 'removed' => $removed);
        return array(count($moved), count($removed));
    }
}

if (!function_exists('kop_fmerge_undo_rows')) {
    /** Put back what kop_fmerge_move_rows() recorded, newest step first. */
    function kop_fmerge_undo_rows(PDO $pdo, array $steps) {
        foreach (array_reverse($steps) as $s) {
            $table = $s['table'];
            if (!kop_fmerge_table_columns($pdo, $table)) continue;
            foreach ((array) ($s['moved'] ?? array()) as $row) {
                $override = array_merge(array($s['col'] => $s['to']), (array) ($s['extra'] ?? array()));
                list($w, $wp) = kop_fmerge_where($pdo, $table, $row, $override);
                $set = array();
                $params = array();
                foreach (array_merge(array($s['col']), array_keys((array) ($s['extra'] ?? array()))) as $c) {
                    $set[] = '`' . $c . '` = ?';
                    $params[] = $row[$c];
                }
                $pdo->prepare('UPDATE `' . $table . '` SET ' . implode(', ', $set) . ' WHERE ' . $w)->execute(array_merge($params, $wp));
            }
            foreach ((array) ($s['removed'] ?? array()) as $row) {
                kop_fmerge_insert_row($pdo, $table, $row);
            }
            foreach ((array) ($s['added_rows'] ?? array()) as $row) {
                list($w, $wp) = kop_fmerge_where($pdo, $table, $row);
                $pdo->prepare('DELETE FROM `' . $table . '` WHERE ' . $w)->execute($wp);
            }
            foreach ((array) ($s['deleted_rows'] ?? array()) as $row) {
                kop_fmerge_insert_row($pdo, $table, $row);
            }
            foreach ((array) ($s['updated'] ?? array()) as $u) {
                // {where: row before, set: {col: old value}}
                $set = array();
                $params = array();
                foreach ($u['old'] as $c => $v) {
                    $set[] = '`' . $c . '` = ?';
                    $params[] = $v;
                }
                list($w, $wp) = kop_fmerge_where($pdo, $table, $u['row'], $u['new']);
                $pdo->prepare('UPDATE `' . $table . '` SET ' . implode(', ', $set) . ' WHERE ' . $w)->execute(array_merge($params, $wp));
            }
        }
    }
}

if (!function_exists('kop_fmerge_insert_row')) {
    function kop_fmerge_insert_row(PDO $pdo, $table, array $row) {
        $cols = array_intersect(array_keys($row), kop_fmerge_table_columns($pdo, $table));
        if (!$cols) return;
        // Generated columns (facilities_v2 name, state...) are computed by MySQL.
        if (!kop_fmerge_is_sqlite($pdo) && $table === 'facilities_v2') {
            $cols = array_intersect($cols, array('id', 'unique_name', 'json_data', 'created_at', 'updated_at'));
        }
        $verb = kop_fmerge_is_sqlite($pdo) ? 'INSERT OR IGNORE' : 'INSERT IGNORE';
        $sql = $verb . ' INTO `' . $table . '` (`' . implode('`, `', $cols) . '`) VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ')';
        $vals = array();
        foreach ($cols as $c) $vals[] = $row[$c];
        $pdo->prepare($sql)->execute($vals);
    }
}

/* ---- What points at a facility ------------------------------------------ */

if (!function_exists('kop_fmerge_ref_tables')) {
    /**
     * Every table column that holds a facility id ('id') or unique name
     * ('name'), with the column to rewrite alongside ('with': unique_name
     * next to facility_id). A table that does not exist is skipped.
     */
    function kop_fmerge_ref_tables($prefix) {
        return kop_fmerge_filter('kop_facility_merge_ref_tables', array(
            array('t' => $prefix . 'kop_operator_facilities', 'c' => 'facility_id', 'k' => 'id'),
            array('t' => $prefix . 'kop_facility_locations', 'c' => 'facility_id', 'k' => 'id'),
            array('t' => 'news_facility_links', 'c' => 'facility_id', 'k' => 'id'),
            array('t' => 'lawsuit_facility_links', 'c' => 'facility_id', 'k' => 'id'),
            array('t' => 'wiki_submissions', 'c' => 'facility_unique_name', 'k' => 'name'),
            array('t' => 'facility_closure_reports', 'c' => 'facility_id', 'k' => 'id'),
            array('t' => 'news_facility_candidates', 'c' => 'facility_id', 'k' => 'id'),
            array('t' => $prefix . 'kop_woodbury_mentions', 'c' => 'facility_id', 'k' => 'id'),
            array('t' => $prefix . 'kop_woodbury_facts', 'c' => 'facility_id', 'k' => 'id'),
            array('t' => $prefix . 'kop_woodbury_facts', 'c' => 'applied_fid', 'k' => 'id'),
            // Fornits link proposals carry the id in their key: done one by one below.
            array('t' => $prefix . 'kop_fornits_items', 'c' => 'facility_id', 'k' => 'id', 'where' => "kind <> 'link'"),
            array('t' => $prefix . 'kop_fornits_items', 'c' => 'applied_fid', 'k' => 'id'),
            array('t' => $prefix . 'kop_gdoc_links', 'c' => 'facility_id', 'k' => 'id'),
            array('t' => $prefix . 'kop_gdoc_links', 'c' => 'applied_fid', 'k' => 'id'),
            // State Lists (inc/state-lists.php): the record a listed row was linked to or created as, and the one it matched when it left its list.
            array('t' => $prefix . 'kop_state_list_rows', 'c' => 'facility_id', 'k' => 'id'),
            array('t' => $prefix . 'kop_state_list_rows', 'c' => 'left_record_id', 'k' => 'id'),
            // Research library documents filed under a facility.
            array('t' => $prefix . 'postmeta', 'c' => 'meta_value', 'k' => 'id', 'where' => "meta_key = 'kop_research_facilities'"),
        ), $prefix);
    }
}

if (!function_exists('kop_fmerge_json_tables')) {
    /**
     * JSON columns holding facility ids under a key: {t, c, key}. Rewritten so
     * a re-save (which rebuilds news and lawsuit links from facilities_mentioned)
     * does not bring the dropped id back.
     */
    function kop_fmerge_json_tables($prefix) {
        return kop_fmerge_filter('kop_facility_merge_json_tables', array(
            array('t' => 'news_submissions', 'c' => 'facilities_mentioned', 'key' => 'facility_id'),
            array('t' => 'lawsuits', 'c' => 'facilities_mentioned', 'key' => 'facility_id'),
            array('t' => 'suggested_edits', 'c' => 'edited_json_data', 'key' => 'facility_id'),
            array('t' => $prefix . 'kop_fornits_topics', 'c' => 'facilities', 'key' => 'id'),
            array('t' => $prefix . 'kop_fornits_topics', 'c' => 'mentions', 'key' => 'id'),
            array('t' => $prefix . 'kop_fornits_items', 'c' => 'also', 'key' => 'id'),
            array('t' => $prefix . 'kop_state_list_rows', 'c' => 'candidates', 'key' => 'id'),
        ), $prefix);
    }
}

if (!function_exists('kop_fmerge_json_swap')) {
    /** $value with every $key: $from (number or numeric string) set to $to. */
    function kop_fmerge_json_swap($value, $key, $from, $to, &$changed) {
        if (!is_array($value)) return $value;
        foreach ($value as $k => $v) {
            if ($k === $key && (is_int($v) || (is_string($v) && ctype_digit($v))) && (int) $v === (int) $from) {
                $value[$k] = is_string($v) ? (string) $to : (int) $to;
                $changed = true;
            } elseif (is_array($v)) {
                $value[$k] = kop_fmerge_json_swap($v, $key, $from, $to, $changed);
            }
        }
        return $value;
    }
}

if (!function_exists('kop_fmerge_rewrite_json')) {
    /** Rewrite one JSON column; records the old text for Undo. Returns rows changed. */
    function kop_fmerge_rewrite_json(PDO $pdo, $table, $col, $key, $from, $to, array &$undo) {
        $cols = kop_fmerge_table_columns($pdo, $table);
        if (!$cols || !in_array($col, $cols, true)) return 0;
        $pk = kop_fmerge_primary_key($pdo, $table);
        if (!$pk) return 0;
        $sel = $pdo->prepare('SELECT `' . implode('`, `', $pk) . '`, `' . $col . '` FROM `' . $table . '` WHERE `' . $col . '` LIKE ?');
        $sel->execute(array('%' . (int) $from . '%'));
        $updated = array();
        foreach ($sel->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $data = json_decode((string) $r[$col], true);
            if (!is_array($data)) continue;
            $changed = false;
            $data = kop_fmerge_json_swap($data, $key, $from, $to, $changed);
            if (!$changed) continue;
            $text = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $row = array_intersect_key($r, array_flip($pk));
            list($w, $wp) = kop_fmerge_where($pdo, $table, $row);
            $pdo->prepare('UPDATE `' . $table . '` SET `' . $col . '` = ? WHERE ' . $w)->execute(array_merge(array($text), $wp));
            $updated[] = array('row' => $row, 'old' => array($col => $r[$col]), 'new' => array());
        }
        if ($updated) {
            $undo[] = array('table' => $table, 'col' => $col, 'to' => $to, 'extra' => array(), 'moved' => array(), 'removed' => array(), 'updated' => $updated);
        }
        return count($updated);
    }
}

if (!function_exists('kop_fmerge_rewrite_serialized')) {
    /**
     * PHP-serialized maps keyed by facility id: the research library's page
     * citations (postmeta kop_research_facility_pages) and the inspection
     * links option (kop_inspection_links: links/rejected). The dropped id's
     * entry joins the kept one's.
     */
    function kop_fmerge_rewrite_serialized(PDO $pdo, $prefix, $from, $to, array &$undo) {
        $n = 0;
        $join = function ($a, $b) {
            if (is_array($a) && is_array($b) && array_keys($a) === range(0, count($a) - 1) && array_keys($b) === range(0, count($b) - 1)) {
                return array_values(array_unique(array_merge($a, $b), SORT_REGULAR));
            }
            return $a === null || $a === '' || $a === array() ? $b : $a;
        };
        $swap_map = function ($map) use ($from, $to, $join) {
            if (!is_array($map) || !array_key_exists($from, $map)) return null;
            $map[$to] = $join($map[$to] ?? null, $map[$from]);
            unset($map[$from]);
            return $map;
        };
        $sets = array(
            array($prefix . 'postmeta', 'meta_id', 'meta_value', "meta_key = 'kop_research_facility_pages'", function ($v) use ($swap_map) { return $swap_map($v); }),
            array($prefix . 'options', 'option_id', 'option_value', "option_name = 'kop_inspection_links'", function ($v) use ($swap_map) {
                if (!is_array($v)) return null;
                $any = false;
                foreach (array('links', 'rejected') as $k) {
                    $m = $swap_map($v[$k] ?? null);
                    if ($m !== null) { $v[$k] = $m; $any = true; }
                }
                return $any ? $v : null;
            }),
        );
        foreach ($sets as $s) {
            list($table, $pk, $col, $where, $fn) = $s;
            if (!kop_fmerge_table_columns($pdo, $table)) continue;
            $sel = $pdo->query("SELECT `{$pk}`, `{$col}` FROM `{$table}` WHERE {$where}");
            $updated = array();
            foreach ($sel->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $v = @unserialize((string) $r[$col], array('allowed_classes' => false));
                $new = $fn($v);
                if ($new === null) continue;
                $pdo->prepare("UPDATE `{$table}` SET `{$col}` = ? WHERE `{$pk}` = ?")->execute(array(serialize($new), $r[$pk]));
                $updated[] = array('row' => array($pk => $r[$pk]), 'old' => array($col => $r[$col]), 'new' => array());
            }
            if ($updated) {
                $undo[] = array('table' => $table, 'col' => $col, 'to' => $to, 'extra' => array(), 'moved' => array(), 'removed' => array(), 'updated' => $updated);
                $n += count($updated);
            }
        }
        if ($n && function_exists('wp_cache_delete')) {
            wp_cache_delete('kop_inspection_links', 'options');
            wp_cache_delete('alloptions', 'options');
        }
        return $n;
    }
}

if (!function_exists('kop_fmerge_filter')) {
    function kop_fmerge_filter($hook, $value, ...$args) {
        return function_exists('apply_filters') ? apply_filters($hook, $value, ...$args) : $value;
    }
}

/* ---- Document libraries ------------------------------------------------- */

if (!function_exists('kop_fmerge_folder_rows')) {
    /** id => {id, name, parent} for every FileBird folder. */
    function kop_fmerge_folder_rows(PDO $pdo, $prefix) {
        $out = array();
        if (!kop_fmerge_table_columns($pdo, $prefix . 'fbv')) return $out;
        foreach ($pdo->query('SELECT id, name, parent FROM `' . $prefix . 'fbv` WHERE type = 0')->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[(int) $r['id']] = array('id' => (int) $r['id'], 'name' => (string) $r['name'], 'parent' => (int) $r['parent']);
        }
        return $out;
    }
}

if (!function_exists('kop_fmerge_folder_group')) {
    /**
     * The folders a page built on $folder shows (kop_get_equivalent_folder_ids()
     * in PDO): same-named folders and folders linked in kop_folder_links.
     */
    function kop_fmerge_folder_group(PDO $pdo, $prefix, $folder, array $folders) {
        $folder = (int) $folder;
        if ($folder <= 0 || !isset($folders[$folder])) return $folder > 0 ? array($folder) : array();
        $by_name = array();
        foreach ($folders as $f) $by_name[mb_strtolower(trim($f['name']))][] = $f['id'];
        $links = array();
        if (kop_fmerge_table_columns($pdo, $prefix . 'kop_folder_links')) {
            foreach ($pdo->query('SELECT folder_a, folder_b FROM `' . $prefix . 'kop_folder_links`')->fetchAll(PDO::FETCH_NUM) as $r) {
                $links[(int) $r[0]][] = (int) $r[1];
                $links[(int) $r[1]][] = (int) $r[0];
            }
        }
        $all = array($folder => true);
        $queue = array($folder);
        while ($queue) {
            $id = array_shift($queue);
            $next = array();
            if (isset($folders[$id])) $next = $by_name[mb_strtolower(trim($folders[$id]['name']))] ?? array();
            $next = array_merge($next, $links[$id] ?? array());
            foreach ($next as $n) {
                if (!isset($all[$n]) && isset($folders[$n])) {
                    $all[$n] = true;
                    $queue[] = $n;
                }
            }
        }
        return array_keys($all);
    }
}

if (!function_exists('kop_fmerge_folder_file_count')) {
    /** Files filed or tagged in $folder_ids and their subfolders. */
    function kop_fmerge_folder_file_count(PDO $pdo, $prefix, array $folder_ids, array $folders) {
        if (!$folder_ids) return 0;
        $children = array();
        foreach ($folders as $f) $children[$f['parent']][] = $f['id'];
        $ids = array();
        $queue = $folder_ids;
        while ($queue) {
            $id = (int) array_shift($queue);
            if (isset($ids[$id])) continue;
            $ids[$id] = true;
            foreach ($children[$id] ?? array() as $c) $queue[] = $c;
        }
        $in = implode(',', array_map('intval', array_keys($ids)));
        $seen = array();
        foreach (array($prefix . 'fbv_attachment_folder', $prefix . 'kop_media_folder_tags') as $t) {
            if (!kop_fmerge_table_columns($pdo, $t)) continue;
            foreach ($pdo->query("SELECT attachment_id FROM `{$t}` WHERE folder_id IN ({$in})")->fetchAll(PDO::FETCH_COLUMN) as $a) $seen[(int) $a] = true;
        }
        return count($seen);
    }
}

if (!function_exists('kop_fmerge_join_folder')) {
    /**
     * Move everything in folder $from into folder $into: its files and tags,
     * its subfolders (a same-named subfolder of $into takes the contents of
     * its twin), its folder links. $from is deleted once empty.
     */
    function kop_fmerge_join_folder(PDO $pdo, $prefix, $from, $into, array &$undo, array &$report, $depth = 0) {
        $from = (int) $from;
        $into = (int) $into;
        if ($from <= 0 || $into <= 0 || $from === $into || $depth > 8) return;
        $fbv = $prefix . 'fbv';
        list($m1) = kop_fmerge_move_rows($pdo, $prefix . 'fbv_attachment_folder', 'folder_id', $from, $into, $undo);
        list($m2) = kop_fmerge_move_rows($pdo, $prefix . 'kop_media_folder_tags', 'folder_id', $from, $into, $undo);
        $report['files'] += $m1 + $m2;

        $kids = $pdo->prepare("SELECT id, name FROM `{$fbv}` WHERE parent = ? AND type = 0");
        $kids->execute(array($into));
        $into_kids = array();
        foreach ($kids->fetchAll(PDO::FETCH_ASSOC) as $k) $into_kids[mb_strtolower(trim($k['name']))] = (int) $k['id'];
        $kids->execute(array($from));
        foreach ($kids->fetchAll(PDO::FETCH_ASSOC) as $k) {
            $twin = $into_kids[mb_strtolower(trim($k['name']))] ?? 0;
            if ($twin) {
                kop_fmerge_join_folder($pdo, $prefix, (int) $k['id'], $twin, $undo, $report, $depth + 1);
            } else {
                $row = array('id' => (int) $k['id']);
                $pdo->prepare("UPDATE `{$fbv}` SET parent = ? WHERE id = ?")->execute(array($into, (int) $k['id']));
                $undo[] = array('table' => $fbv, 'col' => 'parent', 'to' => $into, 'extra' => array(), 'moved' => array(), 'removed' => array(),
                    'updated' => array(array('row' => $row, 'old' => array('parent' => $from), 'new' => array())));
                $report['subfolders']++;
            }
        }

        $links = $prefix . 'kop_folder_links';
        if (kop_fmerge_table_columns($pdo, $links)) {
            kop_fmerge_move_rows($pdo, $links, 'folder_a', $from, $into, $undo);
            kop_fmerge_move_rows($pdo, $links, 'folder_b', $from, $into, $undo);
            $self = $pdo->prepare("SELECT * FROM `{$links}` WHERE folder_a = folder_b");
            $self->execute();
            $rows = $self->fetchAll(PDO::FETCH_ASSOC);
            if ($rows) {
                $pdo->exec("DELETE FROM `{$links}` WHERE folder_a = folder_b");
                $undo[] = array('table' => $links, 'col' => 'folder_a', 'to' => 0, 'extra' => array(), 'moved' => array(), 'removed' => array(), 'deleted_rows' => $rows);
            }
        }

        // Delete $from when nothing is left in it.
        $left = $pdo->prepare("SELECT (SELECT COUNT(*) FROM `{$fbv}` WHERE parent = ?) + (SELECT COUNT(*) FROM `{$prefix}fbv_attachment_folder` WHERE folder_id = ?)");
        $left->execute(array($from, $from));
        if ((int) $left->fetchColumn() === 0) {
            $row = $pdo->prepare("SELECT * FROM `{$fbv}` WHERE id = ?");
            $row->execute(array($from));
            $r = $row->fetch(PDO::FETCH_ASSOC);
            if ($r) {
                $pdo->prepare("DELETE FROM `{$fbv}` WHERE id = ?")->execute(array($from));
                $undo[] = array('table' => $fbv, 'col' => 'id', 'to' => 0, 'extra' => array(), 'moved' => array(), 'removed' => array(), 'deleted_rows' => array($r));
                $report['folders_deleted']++;
            }
        }
    }
}

/* ---- The merge ---------------------------------------------------------- */

if (!function_exists('kop_fmerge_load_mfd')) {
    function kop_fmerge_load_mfd() {
        if (!function_exists('kop_mfd_merge_docs')) {
            if (!defined('KOP_MERGE_FACILITY_DUPLICATES_LIB')) define('KOP_MERGE_FACILITY_DUPLICATES_LIB', true);
            require_once dirname(__DIR__) . '/api/merge-facility-duplicates.php';
        }
    }
}

if (!function_exists('kop_fmerge_row')) {
    /** {id, unique_name, doc, raw} of one facilities_v2 row, or null. */
    function kop_fmerge_row(PDO $pdo, $id) {
        $s = $pdo->prepare('SELECT * FROM facilities_v2 WHERE id = ?');
        $s->execute(array((int) $id));
        $r = $s->fetch(PDO::FETCH_ASSOC);
        if (!$r) return null;
        $doc = json_decode((string) $r['json_data'], true);
        if (!is_array($doc)) return null;
        return array('id' => (int) $r['id'], 'unique_name' => (string) $r['unique_name'], 'doc' => $doc, 'raw' => $r);
    }
}

if (!function_exists('kop_fmerge_execute')) {
    /**
     * Fold facility $drop into $keep. $opts: folder_keep / folder_drop (the
     * FileBird folder each page shows; found from the documents otherwise),
     * by (user login). Runs inside the caller's transaction (the screen wraps
     * it in kop_v2_with_write_lock()). Returns the log entry, undo included.
     */
    function kop_fmerge_execute(PDO $pdo, $prefix, $keep_id, $drop_id, array $opts = array()) {
        kop_fmerge_load_mfd();
        $keep_id = (int) $keep_id;
        $drop_id = (int) $drop_id;
        if ($keep_id === $drop_id) throw new RuntimeException('Pick two different records.');
        $keep = kop_fmerge_row($pdo, $keep_id);
        $drop = kop_fmerge_row($pdo, $drop_id);
        if (!$keep) throw new RuntimeException('Record #' . $keep_id . ' is not on file (already merged?).');
        if (!$drop) throw new RuntimeException('Record #' . $drop_id . ' is not on file (already merged?).');

        $doc = kop_mfd_merge_docs($keep['doc'], $drop['doc'], array('id' => $drop_id, 'unique_name' => $drop['unique_name']));
        $undo = array();
        $report = array('rows' => array(), 'files' => 0, 'subfolders' => 0, 'folders_deleted' => 0);

        // Document libraries.
        $folders = kop_fmerge_folder_rows($pdo, $prefix);
        $fk = (int) ($opts['folder_keep'] ?? 0) ?: (int) ($keep['doc']['documentFolderId'] ?? 0);
        $fd = (int) ($opts['folder_drop'] ?? 0) ?: (int) ($drop['doc']['documentFolderId'] ?? 0);
        if ($fk > 0 && !isset($folders[$fk])) $fk = 0;
        if ($fd > 0 && !isset($folders[$fd])) $fd = 0;
        if ($fk <= 0 && $fd > 0) {
            // The kept record had no library: the other one's becomes its library.
            $fk = $fd;
            $fd = 0;
        }
        if ($fk > 0) {
            $doc['documentFolderId'] = $fk;
            if ($fd > 0) {
                $keep_group = array_flip(kop_fmerge_folder_group($pdo, $prefix, $fk, $folders));
                // A same-named folder belongs to the dropped record's library
                // only while no third record has that name.
                $other_names = $pdo->prepare('SELECT COUNT(*) FROM facilities_v2 WHERE LOWER(name) = LOWER(?) AND id NOT IN (?, ?)');
                foreach (kop_fmerge_folder_group($pdo, $prefix, $fd, $folders) as $f) {
                    if (isset($keep_group[$f])) continue;
                    if ($f !== $fd) {
                        $other_names->execute(array($folders[$f]['name'] ?? '', $keep_id, $drop_id));
                        if ((int) $other_names->fetchColumn() > 0) continue;
                    }
                    // A folder inside the kept library (or an ancestor of it) is left where it is.
                    $p = $fk;
                    $inside = false;
                    for ($i = 0; $p > 0 && $i < 30; $i++) {
                        if ($p === $f) { $inside = true; break; }
                        $p = $folders[$p]['parent'] ?? 0;
                    }
                    if ($inside) continue;
                    kop_fmerge_join_folder($pdo, $prefix, $f, $fk, $undo, $report);
                }
            }
        }

        // Rows that point at the dropped record.
        foreach (kop_fmerge_ref_tables($prefix) as $ref) {
            $from = $ref['k'] === 'name' ? $drop['unique_name'] : $drop_id;
            $to = $ref['k'] === 'name' ? $keep['unique_name'] : $keep_id;
            $extra = array();
            if (!empty($ref['with'])) $extra[$ref['with']] = $keep['unique_name'];
            list($moved, $removed) = kop_fmerge_move_rows($pdo, $ref['t'], $ref['c'], $from, $to, $undo, $extra, (string) ($ref['where'] ?? ''));
            if ($moved || $removed) $report['rows'][$ref['t'] . '.' . $ref['c']] = $moved + $removed;
        }
        // Fornits link proposals: the key is made from the topic and the facility id.
        $fi = $prefix . 'kop_fornits_items';
        if (kop_fmerge_table_columns($pdo, $fi)) {
            $links = $pdo->prepare("SELECT id, topic_id FROM `{$fi}` WHERE facility_id = ? AND kind = 'link'");
            $links->execute(array($drop_id));
            foreach ($links->fetchAll(PDO::FETCH_ASSOC) as $l) {
                $pkey = substr(md5('link|' . (int) $l['topic_id'] . '|' . $keep_id), 0, 16);
                list($m, $r) = kop_fmerge_move_rows($pdo, $fi, 'facility_id', $drop_id, $keep_id, $undo, array('pkey' => $pkey), 'id = ' . (int) $l['id']);
                if ($m || $r) $report['rows'][$fi . '.facility_id'] = ($report['rows'][$fi . '.facility_id'] ?? 0) + $m + $r;
            }
        }
        foreach (kop_fmerge_json_tables($prefix) as $j) {
            $n = kop_fmerge_rewrite_json($pdo, $j['t'], $j['c'], $j['key'], $drop_id, $keep_id, $undo);
            if ($n) $report['rows'][$j['t'] . '.' . $j['c']] = $n;
        }
        $n = kop_fmerge_rewrite_serialized($pdo, $prefix, $drop_id, $keep_id, $undo);
        if ($n) $report['rows']['research pages / inspection links'] = $n;
        // Identity rows: facility_id is unique, so the dropped one's go when the kept one has its own.
        $identity = $prefix . 'kop_facility_identity';
        if (kop_fmerge_table_columns($pdo, $identity)) {
            kop_fmerge_move_rows($pdo, $identity, 'facility_id', $drop_id, $keep_id, $undo, array('unique_name' => $keep['unique_name']));
        }

        // The merged document, then the dropped row goes.
        $doc = kop_facility_normalize($doc, array('facility_id' => $keep_id, 'unique_name' => $keep['unique_name']));
        // The save rebuilds the kept record's location memberships from its
        // document; what that adds and removes is kept for Undo too.
        $save_opts = array('pdo' => $pdo, 'prefix' => $prefix, 'force' => true, 'skip_memberships' => kop_fmerge_is_sqlite($pdo));
        $memberships = $prefix . 'kop_facility_locations';
        $member_rows = function () use ($pdo, $memberships, $keep_id) {
            if (!kop_fmerge_table_columns($pdo, $memberships)) return array();
            $s = $pdo->prepare("SELECT * FROM `{$memberships}` WHERE facility_id = ?");
            $s->execute(array($keep_id));
            $out = array();
            foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) $out[json_encode($r)] = $r;
            return $out;
        };
        $was = $member_rows();
        $pdo->prepare('DELETE FROM facilities_v2 WHERE id = ?')->execute(array($drop_id));
        $status = null;
        kop_facility_save($doc, $save_opts, $status);
        $now = $member_rows();
        $gone = array_values(array_diff_key($was, $now));
        $added = array_values(array_diff_key($now, $was));
        if ($gone || $added) {
            $undo[] = array('table' => $memberships, 'col' => 'facility_id', 'to' => $keep_id, 'extra' => array(), 'moved' => array(),
                'removed' => array(), 'deleted_rows' => $gone, 'added_rows' => $added);
        }

        return array(
            'id'       => gmdate('YmdHis') . '-' . $keep_id . '-' . $drop_id,
            'keep'     => array('id' => $keep_id, 'name' => (string) ($keep['doc']['identification']['name'] ?? ''), 'unique_name' => $keep['unique_name']),
            'drop'     => array('id' => $drop_id, 'name' => (string) ($drop['doc']['identification']['name'] ?? ''), 'unique_name' => $drop['unique_name']),
            'at'       => gmdate('Y-m-d H:i:s'),
            'by'       => (string) ($opts['by'] ?? ''),
            'report'   => $report,
            'undo'     => array('keep_raw' => $keep['raw'], 'drop_raw' => $drop['raw'], 'steps' => $undo),
        );
    }
}

if (!function_exists('kop_fmerge_undo')) {
    /** Reverse one merge from its log entry. */
    function kop_fmerge_undo(PDO $pdo, $prefix, array $entry) {
        $u = $entry['undo'] ?? null;
        if (!is_array($u) || empty($u['keep_raw']) || empty($u['drop_raw'])) throw new RuntimeException('This merge has no undo record.');
        $keep_id = (int) $u['keep_raw']['id'];
        $drop_id = (int) $u['drop_raw']['id'];
        $exists = $pdo->prepare('SELECT COUNT(*) FROM facilities_v2 WHERE id = ?');
        $exists->execute(array($drop_id));
        if ((int) $exists->fetchColumn() > 0) throw new RuntimeException('Record #' . $drop_id . ' is already back.');
        kop_fmerge_insert_row($pdo, 'facilities_v2', $u['drop_raw']);
        $pdo->prepare('UPDATE facilities_v2 SET json_data = ? WHERE id = ?')->execute(array($u['keep_raw']['json_data'], $keep_id));
        if (kop_fmerge_is_sqlite($pdo)) {
            // The mirror's copies of MySQL's generated columns.
            $cols = array_diff(array_keys($u['keep_raw']), array('id', 'json_data'));
            foreach ($cols as $c) {
                $pdo->prepare('UPDATE facilities_v2 SET `' . $c . '` = ? WHERE id = ?')->execute(array($u['keep_raw'][$c], $keep_id));
            }
        }
        kop_fmerge_undo_rows($pdo, (array) $u['steps']);
        return array($keep_id, $drop_id);
    }
}

/* ---- WordPress: stores, redirects, the screen ---------------------------- */

if (!function_exists('kop_facility_merged_into')) {
    /** {ids: {drop id: keep id}, slugs: {old page slug: drop id}}. */
    function kop_facility_merged_into() {
        $v = function_exists('get_option') ? get_option('kop_facility_merged_into', array()) : array();
        $v = is_array($v) ? $v : array();
        return array('ids' => (array) ($v['ids'] ?? array()), 'slugs' => (array) ($v['slugs'] ?? array()));
    }
}

if (!function_exists('kop_facility_merge_resolve')) {
    /** The record a (possibly merged-away) id now lives in. */
    function kop_facility_merge_resolve($id) {
        $map = kop_facility_merged_into();
        $id = (int) $id;
        for ($i = 0; $i < 10 && isset($map['ids'][$id]); $i++) $id = (int) $map['ids'][$id];
        return $id;
    }
}

if (!function_exists('kop_facility_merge_expand_ids')) {
    /** $ids plus every id merged into one of them, for lists built per id (archive files). */
    function kop_facility_merge_expand_ids($ids) {
        $ids = array_values(array_unique(array_map('intval', (array) $ids)));
        $map = kop_facility_merged_into();
        if (!$map['ids']) return $ids;
        $want = array_flip($ids);
        foreach (array_keys($map['ids']) as $drop) {
            if (isset($want[kop_facility_merge_resolve($drop)])) $ids[] = (int) $drop;
        }
        return array_values(array_unique($ids));
    }
}

if (!function_exists('kop_facility_merge_redirect')) {
    /** /facility/<dropped id or its old slug>/ 301s to the kept record's page. */
    function kop_facility_merge_redirect() {
        if (!function_exists('kop_facility_pages_requested_slug')) return;
        $slug = strtolower((string) kop_facility_pages_requested_slug());
        if ($slug === '') return;
        $map = kop_facility_merged_into();
        if (!$map['ids']) return;
        $id = 0;
        if (isset($map['slugs'][$slug])) {
            $id = (int) $map['slugs'][$slug];
        } elseif (ctype_digit($slug)) {
            $id = (int) $slug;
        } elseif (preg_match('/-(\d+)$/', $slug, $m)) {
            $id = (int) $m[1];
        }
        if ($id <= 0 || !isset($map['ids'][$id])) return;
        $keep = kop_facility_merge_resolve($id);
        $url = function_exists('kop_facility_page_url') ? kop_facility_page_url($keep) : '';
        if ($url === '' && function_exists('kop_facility_pages_thin_target')) $url = kop_facility_pages_thin_target($keep);
        if ($url === '') return;
        wp_safe_redirect($url, 301);
        exit;
    }
    add_action('template_redirect', 'kop_facility_merge_redirect', -1);
}

if (!function_exists('kop_fmerge_flush')) {
    /** Caches that hold a facility list. */
    function kop_fmerge_flush() {
        foreach (array('kop_fmerge_screen', 'kop_facility_pages_index', 'kop_facility_pages_people', 'kop_operator_pages_index',
            'kop_network_map_status_overrides', 'kop_network_map_facility_urls', 'kop_network_map_facility_links', 'kop_network_map_meta') as $t) {
            delete_transient($t);
        }
    }
}

if (!function_exists('kop_fmerge_page_folder')) {
    /** The FileBird folder a record's page shows, as kop_facility_pages_build_index() settles it. */
    function kop_fmerge_page_folder($id, array $doc, $unique_name) {
        if (function_exists('kop_facility_pages_index')) {
            $index = kop_facility_pages_index();
            if (!empty($index['ids'][$id]['folder'])) return (int) $index['ids'][$id]['folder'];
        }
        if (!empty($doc['documentFolderId'])) return (int) $doc['documentFolderId'];
        static $map = null;
        if (!function_exists('kop_facility_pages_folder_map') || !function_exists('kop_facility_pages_doc_name_keys')) return 0;
        if ($map === null) $map = kop_facility_pages_folder_map();
        foreach (kop_facility_pages_doc_name_keys($doc, $unique_name) as $k) {
            if (isset($map[$k])) return (int) $map[$k];
        }
        return 0;
    }
}

if (!function_exists('kop_fmerge_dismissed')) {
    function kop_fmerge_dismissed() {
        $v = get_option('kop_facility_merge_dismissed', array());
        return is_array($v) ? $v : array();
    }
}

if (!function_exists('kop_fmerge_log')) {
    function kop_fmerge_log() {
        $v = get_option('kop_facility_merge_log', array());
        return is_array($v) ? $v : array();
    }
}

if (!function_exists('kop_fmerge_side')) {
    /** What a card shows about one record. $ctx from kop_fmerge_screen_data(). */
    function kop_fmerge_side(array $r, array &$ctx) {
        $id = (int) $r['id'];
        $doc = json_decode((string) $r['json_data'], true) ?: array();
        $idn = $doc['identification'] ?? array();
        $loc = $doc['location'] ?? array();
        $op = $doc['operatingPeriod'] ?? array();
        $folder = kop_fmerge_page_folder($id, $doc, (string) $r['unique_name']);
        if ($folder && !isset($ctx['doc_counts'][$folder])) {
            $ctx['doc_counts'][$folder] = kop_fmerge_folder_file_count($ctx['pdo'], $ctx['prefix'],
                kop_fmerge_folder_group($ctx['pdo'], $ctx['prefix'], $folder, $ctx['folders']), $ctx['folders']);
        }
        $companies = array();
        foreach ($ctx['ops'][$id] ?? array() as $oid) if (isset($ctx['op_names'][$oid])) $companies[] = $ctx['op_names'][$oid];
        foreach (array('currentOwners', 'otherOperators') as $k) {
            foreach ((array) ($idn[$k] ?? array()) as $n) if (is_string($n) && trim($n) !== '') $companies[] = trim($n);
        }
        $start = (string) ($op['startYear'] ?? '');
        $end = (string) ($op['endYear'] ?? '');
        $staff = 0;
        foreach ((array) ($doc['staff'] ?? array()) as $v) if (is_array($v)) $staff += count($v);
        $type = $doc['facilityDetails']['type'] ?? '';
        // Its past, other and current names ("Formerly X"), shown and searchable.
        $aka = array();
        foreach (array('pastNames' => 'past', 'otherNames' => 'other') as $k => $kind) {
            $list = function_exists('kop_v2_search_name_list') ? kop_v2_search_name_list($idn[$k] ?? null) : array();
            foreach ($list as $n) $aka[] = function_exists('kop_alias_label') ? kop_alias_label($kind, $n) : $n;
        }
        if (!empty($idn['currentName']) && is_string($idn['currentName'])) {
            $aka[] = function_exists('kop_alias_label') ? kop_alias_label('current', $idn['currentName']) : $idn['currentName'];
        }
        return array(
            'id'        => $id,
            'name'      => (string) ($idn['name'] ?? $r['unique_name']),
            'place'     => trim(implode(', ', array_filter(array((string) ($loc['city'] ?? ''), (string) ($loc['state'] ?? ($loc['country'] ?? '')))))),
            'address'   => (string) ($loc['address'] ?? ''),
            'status'    => (string) ($op['status'] ?? ''),
            'years'     => $start !== '' || $end !== '' ? trim($start . '-' . $end, '-') : '',
            'type'      => is_array($type) ? implode(', ', $type) : (string) $type,
            'companies' => array_values(array_unique($companies)),
            'aka'       => array_values(array_unique($aka)),
            'news'      => (int) ($ctx['counts'][$id]['news'] ?? 0),
            'lawsuits'  => (int) ($ctx['counts'][$id]['lawsuits'] ?? 0),
            'docs'      => $folder ? (int) $ctx['doc_counts'][$folder] : 0,
            'staff'     => $staff,
            'size'      => strlen((string) $r['json_data']),
            'page'      => function_exists('kop_facility_page_url') ? kop_facility_page_url($id) : '',
        );
    }
}

if (!function_exists('kop_fmerge_screen_data')) {
    /**
     * Everything the screen shows: {pairs: [{key, tab, reason, a, b, keep}], merged, dismissed}.
     * Cached for an hour; every action here rebuilds it.
     */
    function kop_fmerge_screen_data($fresh = false) {
        if (!$fresh) {
            $cached = get_transient('kop_fmerge_screen');
            if (is_array($cached)) return $cached;
        }
        global $wpdb;
        $pdo = kop_seed_pdo();
        if (!$pdo) return array('pairs' => array(), 'merged' => array(), 'dismissed' => array());
        $prefix = $wpdb->prefix;
        $ctx = array('pdo' => $pdo, 'prefix' => $prefix, 'op_names' => array(), 'ops' => array(), 'counts' => array(), 'doc_counts' => array());
        foreach ($pdo->query("SELECT id, name FROM `{$prefix}kop_operators`")->fetchAll(PDO::FETCH_NUM) as $o) $ctx['op_names'][(int) $o[0]] = (string) $o[1];
        foreach ($pdo->query("SELECT facility_id, operator_id FROM `{$prefix}kop_operator_facilities`")->fetchAll(PDO::FETCH_NUM) as $o) $ctx['ops'][(int) $o[0]][] = (int) $o[1];
        foreach (array('news_facility_links' => 'news', 'lawsuit_facility_links' => 'lawsuits') as $t => $k) {
            try {
                foreach ($pdo->query("SELECT facility_id, COUNT(*) FROM `{$t}` GROUP BY facility_id")->fetchAll(PDO::FETCH_NUM) as $c) $ctx['counts'][(int) $c[0]][$k] = (int) $c[1];
            } catch (Throwable $e) {
            }
        }
        $ctx['folders'] = kop_fmerge_folder_rows($pdo, $prefix);

        $rows = $pdo->query('SELECT id, unique_name, json_data FROM facilities_v2')->fetchAll(PDO::FETCH_ASSOC);
        $by_id = array();
        foreach ($rows as &$r) {
            $r['ops'] = $ctx['ops'][(int) $r['id']] ?? array();
            $by_id[(int) $r['id']] = $r;
        }
        unset($r);
        $dismissed = kop_fmerge_dismissed();
        $sides = array();
        $side = function ($id) use (&$sides, $by_id, &$ctx) {
            if (!isset($sides[$id])) $sides[$id] = isset($by_id[$id]) ? kop_fmerge_side($by_id[$id], $ctx) : array('id' => $id, 'name' => 'Record #' . $id . ' (gone)', 'companies' => array());
            return $sides[$id];
        };
        // Keep the record with more on it; with a company's name in front, the plain program name.
        $score = function ($s) {
            return $s['news'] * 3 + $s['lawsuits'] * 5 + $s['docs'] * 2 + $s['staff'] + ($s['page'] !== '' ? 3 : 0)
                + $s['size'] / 4000 + ($s['status'] !== '' && $s['status'] !== 'Unknown' ? 1 : 0) + ($s['companies'] ? 1.5 : 0);
        };
        $out = array();
        foreach (kop_fmerge_find_pairs($rows, $dismissed) as $p) {
            $a = $side($p['a']);
            $b = $side($p['b']);
            $sa = $score($a);
            $sb = $score($b);
            if ($p['reason']['code'] === 'prefix') {
                if (mb_strlen($a['name']) < mb_strlen($b['name'])) $sa += 4; else $sb += 4;
            }
            $tab = $p['reason']['code'] === 'address' ? 'address' : $p['reason']['level'];
            $reason = $p['reason']['label'];
            // "Program – Home A" and "Program – Home B": likely two homes of one program, not one place.
            if (function_exists('kop_program_homes_split_name')) {
                $xa = kop_program_homes_split_name($a['name']);
                $xb = kop_program_homes_split_name($b['name']);
                if ($xa[0] !== '' && $xb[0] !== '' && kop_program_homes_key($xa[0]) === kop_program_homes_key($xb[0])
                    && kop_program_homes_key($xa[1]) !== kop_program_homes_key($xb[1])) {
                    $tab = 'homes';
                    $reason = 'Named like two homes of one program (' . $xa[0] . ')';
                }
            }
            $out[] = array(
                'key'    => $p['a'] . ':' . $p['b'],
                'tab'    => $tab,
                'reason' => $reason,
                'a'      => $a,
                'b'      => $b,
                'keep'   => $sb > $sa ? $b['id'] : $a['id'],
                'homes'  => function_exists('kop_fmerge_homes_plan') ? kop_fmerge_homes_plan($a['id'], $a['name'], $b['id'], $b['name']) : null,
            );
        }
        $rank = array('likely' => 0, 'check' => 1, 'address' => 2, 'homes' => 3);
        usort($out, function ($x, $y) use ($rank) {
            return ($rank[$x['tab']] <=> $rank[$y['tab']]) ?: strcasecmp($x['a']['place'], $y['a']['place']) ?: strcasecmp($x['a']['name'], $y['a']['name']);
        });

        $merged = array();
        foreach (kop_fmerge_log() as $e) {
            $merged[] = array('id' => $e['id'], 'keep' => $e['keep'], 'drop' => $e['drop'], 'at' => $e['at'], 'by' => $e['by'],
                'report' => $e['report'], 'undone' => !empty($e['undone']), 'canUndo' => empty($e['undone']) && !empty($e['undo']),
                'page' => function_exists('kop_facility_page_url') ? kop_facility_page_url((int) $e['keep']['id']) : '');
        }
        // Pairs marked "Homes of one program" (not undone): their Undo is on the Not the same tab.
        $homes_of = array();
        foreach (function_exists('kop_fmerge_homes_log') ? kop_fmerge_homes_log() : array() as $h) {
            $hk = min((int) $h['a'], (int) $h['b']) . ':' . max((int) $h['a'], (int) $h['b']);
            if (empty($h['undone']) && !isset($homes_of[$hk])) $homes_of[$hk] = array('log' => $h['id'], 'program' => (int) $h['program']);
        }
        $dis = array();
        foreach ($dismissed as $key => $d) {
            list($x, $y) = array_map('intval', explode(':', $key));
            $dis[] = array('key' => $key, 'a' => $side($x), 'b' => $side($y), 'by' => $d['by'] ?? '', 'at' => $d['at'] ?? '', 'homes' => $homes_of[$key] ?? null);
        }
        $data = array('pairs' => $out, 'merged' => $merged, 'dismissed' => $dis);
        set_transient('kop_fmerge_screen', $data, HOUR_IN_SECONDS);
        return $data;
    }
}

if (!function_exists('kop_fmerge_do_merge')) {
    /** Merge from the screen: the database work, the log, the redirect. Returns the message. */
    function kop_fmerge_do_merge(PDO $pdo, $prefix, $keep, $drop, $login) {
        if (function_exists('kop_v2_writes_active') && !kop_v2_writes_active($pdo, $prefix)) {
            throw new RuntimeException('The v2 write switch is off, so a merge would not stick.');
        }
        $k = kop_fmerge_row($pdo, $keep);
        $d = kop_fmerge_row($pdo, $drop);
        if (!$k || !$d) throw new RuntimeException('One of these records is no longer on file. The list has been refreshed.');
        $old_slug = '';
        if (function_exists('kop_facility_pages_index')) {
            $index = kop_facility_pages_index();
            $old_slug = (string) ($index['ids'][$drop]['slug'] ?? '');
        }
        $opts = array(
            'folder_keep' => kop_fmerge_page_folder($keep, $k['doc'], $k['unique_name']),
            'folder_drop' => kop_fmerge_page_folder($drop, $d['doc'], $d['unique_name']),
            'by'          => $login,
        );
        $entry = kop_v2_with_write_lock($pdo, function () use ($pdo, $prefix, $keep, $drop, $opts) {
            return kop_fmerge_execute($pdo, $prefix, $keep, $drop, $opts);
        });
        $entry['old_slug'] = $old_slug;
        $log = kop_fmerge_log();
        array_unshift($log, $entry);
        // Undo data for the newest 200 merges; older ones keep their summary.
        foreach ($log as $i => $e) if ($i >= 200) unset($log[$i]['undo']);
        update_option('kop_facility_merge_log', array_slice($log, 0, 1000), false);
        $map = kop_facility_merged_into();
        $map['ids'][$drop] = $keep;
        if ($old_slug !== '') $map['slugs'][strtolower($old_slug)] = $drop;
        update_option('kop_facility_merged_into', $map, false);
        return 'Merged: "' . $entry['drop']['name'] . '" is now part of "' . $entry['keep']['name'] . '"'
            . ($entry['report']['files'] ? ', with ' . $entry['report']['files'] . ' documents moved into its library' : '') . '.';
    }
}

if (!function_exists('kop_fmerge_do_undo')) {
    /** Undo one merge from the log by its id: the record, its rows and its page come back. Returns the message. */
    function kop_fmerge_do_undo(PDO $pdo, $prefix, $log_id) {
        $log = kop_fmerge_log();
        $found = null;
        foreach ($log as $i => $e) if (($e['id'] ?? '') === (string) $log_id) $found = $i;
        if ($found === null) throw new RuntimeException('That merge is not in the log.');
        $entry = $log[$found];
        kop_v2_with_write_lock($pdo, function () use ($pdo, $prefix, $entry) {
            return kop_fmerge_undo($pdo, $prefix, $entry);
        });
        unset($log[$found]['undo']);
        $log[$found]['undone'] = gmdate('Y-m-d H:i:s');
        update_option('kop_facility_merge_log', $log, false);
        $map = kop_facility_merged_into();
        $drop = (int) $entry['drop']['id'];
        unset($map['ids'][$drop]);
        foreach ($map['slugs'] as $s => $v) if ((int) $v === $drop) unset($map['slugs'][$s]);
        update_option('kop_facility_merged_into', $map, false);
        return 'Undone: "' . $entry['drop']['name'] . '" is its own record again, with everything it had.';
    }
}

if (!function_exists('kop_fmerge_set_dismissed')) {
    /** Mark a pair "not the same" ($dismiss true) or put it back on the list. Returns the message. */
    function kop_fmerge_set_dismissed($a, $b, $dismiss, $login) {
        $a = (int) $a;
        $b = (int) $b;
        $key = min($a, $b) . ':' . max($a, $b);
        $dis = kop_fmerge_dismissed();
        if ($dismiss) $dis[$key] = array('by' => (string) $login, 'at' => gmdate('Y-m-d H:i:s'));
        else unset($dis[$key]);
        update_option('kop_facility_merge_dismissed', $dis, false);
        return $dismiss ? 'Marked not the same. This pair will not be offered again.' : 'Back on the list.';
    }
}

/* ---- Homes of one program (not duplicates) ------------------------------- */

if (!function_exists('kop_fmerge_homes_log')) {
    function kop_fmerge_homes_log() {
        $v = get_option('kop_facility_merge_homes_log', array());
        return is_array($v) ? $v : array();
    }
}

if (!function_exists('kop_fmerge_homes_plan')) {
    /**
     * What "Homes of one program" offers for two records named $name_a and
     * $name_b: {program_id (a program either is already in, or 0), program_name
     * (that program's name, else the part both names share: "Newport Academy"
     * from "Newport Academy – Acre" and "Newport Academy – Aracena"), homes:
     * {id: home name}}. Null when Program Homes is not installed.
     */
    function kop_fmerge_homes_plan($a, $name_a, $b, $name_b) {
        if (!function_exists('kop_program_homes_group')) return null;
        $a = (int) $a;
        $b = (int) $b;
        $split = array($a => kop_program_homes_split_name($name_a), $b => kop_program_homes_split_name($name_b));
        $base = '';
        if ($split[$a][0] !== '' && kop_program_homes_key($split[$a][0]) === kop_program_homes_key($split[$b][0])) {
            $base = $split[$a][0];
        } else {
            // The words both names start with.
            $wa = preg_split('/\s+/u', trim((string) $name_a));
            $wb = preg_split('/\s+/u', trim((string) $name_b));
            $common = array();
            foreach ($wa as $i => $w) {
                if (!isset($wb[$i]) || kop_program_homes_key($w) !== kop_program_homes_key($wb[$i])) break;
                $common[] = $w;
            }
            $base = trim(preg_replace('/[\s\x{2013}\x{2014}:-]+$/u', '', implode(' ', $common)));
        }
        $homes = array();
        foreach (array($a => $name_a, $b => $name_b) as $id => $name) {
            $homes[$id] = $split[$id][1] !== '' && $base !== '' && kop_program_homes_key($split[$id][0]) === kop_program_homes_key($base) ? $split[$id][1] : trim((string) $name);
        }
        $program = 0;
        foreach (array($a, $b) as $id) {
            $in = kop_program_homes_program_of($id);
            if ($in) { $program = (int) $in[0]; break; }
            // One of the two is a program record already: the other becomes its home.
            if (kop_program_homes_homes_of($id)) { $program = $id; break; }
        }
        $program_name = $base;
        if ($program) {
            global $wpdb;
            $program_name = (string) $wpdb->get_var($wpdb->prepare('SELECT name FROM facilities_v2 WHERE id = %d', $program));
        }
        return array('program_id' => $program, 'program_name' => $program_name, 'homes' => $homes);
    }
}

if (!function_exists('kop_fmerge_make_homes')) {
    /**
     * Two records of a Merge Duplicates pair are homes of one program, not one
     * place: both are tied to a program record through Program Homes
     * (kop_program_homes_group()) and the pair is marked "not the same". The
     * program is $program_id (an existing record; a program either home is in
     * already is the default) or a new record named $program_name. $homes:
     * {id: home name}; a home whose name is empty keeps its record name.
     * Logged in kop_facility_merge_homes_log for kop_fmerge_undo_homes().
     * Returns {message, log}.
     */
    function kop_fmerge_make_homes($a, $b, $program_id, $program_name, array $homes, $login) {
        if (!function_exists('kop_program_homes_group')) throw new RuntimeException('Program Homes is not installed here.');
        global $wpdb;
        $a = (int) $a;
        $b = (int) $b;
        $program_id = (int) $program_id;
        $names = array();
        foreach ((array) $wpdb->get_results('SELECT id, name FROM facilities_v2 WHERE id IN (' . $a . ',' . $b . ')', ARRAY_A) as $r) $names[(int) $r['id']] = (string) $r['name'];
        if (!isset($names[$a], $names[$b])) throw new RuntimeException('One of these records is no longer on file.');
        $plan = kop_fmerge_homes_plan($a, $names[$a], $b, $names[$b]);
        $tie = array();
        foreach (array($a, $b) as $id) {
            if ($id === $program_id) continue; // that record is the program itself
            $n = trim(sanitize_text_field((string) ($homes[$id] ?? $homes[(string) $id] ?? '')));
            $tie[$id] = $n !== '' ? $n : $plan['homes'][$id];
        }
        // How things stood, for Undo.
        $before = array();
        foreach (array_keys($tie) as $id) $before[$id] = kop_program_homes_program_of($id);
        // A new name can still find an existing record (kop_facility_resolve_identity), so look at every group.
        $groups = array_map('intval', (array) $wpdb->get_col('SELECT program_id FROM ' . kop_program_homes_table('groups')));
        $name = trim(sanitize_text_field((string) $program_name));
        if ($program_id <= 0 && $name === '') $name = $plan['program_name'];
        $pid = kop_program_homes_group($tie, $program_id, $name, kop_program_homes_opts());
        $had_group = in_array((int) $pid, $groups, true);
        kop_fmerge_set_dismissed($a, $b, true, $login);
        $entry = array(
            'id' => uniqid('fh', true), 'a' => $a, 'b' => $b, 'program' => (int) $pid, 'new_group' => !$had_group,
            'before' => $before, 'by' => (string) $login, 'at' => gmdate('Y-m-d H:i:s'),
        );
        $log = kop_fmerge_homes_log();
        array_unshift($log, $entry);
        update_option('kop_facility_merge_homes_log', array_slice($log, 0, 500), false);
        $pname = (string) $wpdb->get_var($wpdb->prepare('SELECT name FROM facilities_v2 WHERE id = %d', $pid));
        return array('log' => $entry['id'], 'program' => (int) $pid,
            'message' => 'Tied as homes of ' . ($pname !== '' ? $pname : 'program') . ' (record #' . $pid . '). This pair will not be offered as duplicates again.');
    }
}

if (!function_exists('kop_fmerge_undo_homes')) {
    /** Undo kop_fmerge_make_homes() by its log id: the homes as they were, the pair back on the list. Returns the message. */
    function kop_fmerge_undo_homes($log_id) {
        $log = kop_fmerge_homes_log();
        $found = null;
        foreach ($log as $i => $e) if (($e['id'] ?? '') === (string) $log_id) $found = $i;
        if ($found === null || !empty($log[$found]['undone'])) throw new RuntimeException('That is not in the log, or was undone already.');
        $e = $log[$found];
        if (!empty($e['new_group'])) {
            // The whole group came from this: untie it (and delete a program record it made, if untouched).
            kop_program_homes_undo((int) $e['program']);
        } else {
            foreach ((array) $e['before'] as $id => $_) kop_program_homes_remove_home((int) $id);
        }
        // Homes that were in another program before go back there.
        foreach ((array) $e['before'] as $id => $was) {
            if (is_array($was) && !empty($was[0])) kop_program_homes_group(array((int) $id => (string) ($was[1] ?? '')), (int) $was[0], '', kop_program_homes_opts());
        }
        kop_fmerge_set_dismissed((int) $e['a'], (int) $e['b'], false, '');
        $log[$found]['undone'] = gmdate('Y-m-d H:i:s');
        update_option('kop_facility_merge_homes_log', $log, false);
        return 'Undone: the two records are no longer homes of that program, and the pair is back on the list.';
    }
}

if (!function_exists('kop_fmerge_ajax')) {
    /**
     * POST action=kop_facility_merge, nonce, op:
     *   merge      keep, drop
     *   dismiss    a, b      (not the same place)
     *   undismiss  a, b
     *   undo       log       (a merge's log id)
     *   homes      a, b, program (existing record id, or 0 for a new one),
     *              program_name, home_a, home_b   (homes of one program)
     *   undo_homes log       (kop_facility_merge_homes_log id)
     * Answers with the screen's data, rebuilt.
     */
    function kop_fmerge_ajax() {
        if (!current_user_can('manage_options')) wp_send_json_error(array('message' => 'Only an administrator can merge records.'), 403);
        check_ajax_referer('kop_facility_merge', 'nonce');
        require_once __DIR__ . '/facility-v2-writer.php';
        global $wpdb;
        $pdo = kop_seed_pdo();
        if (!$pdo) wp_send_json_error(array('message' => 'No database connection.'), 500);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $prefix = $wpdb->prefix;
        $op = sanitize_key((string) ($_POST['op'] ?? ''));
        $user = wp_get_current_user();
        $login = $user ? (string) $user->user_login : '';
        try {
            if ($op === 'merge') {
                $note = kop_fmerge_do_merge($pdo, $prefix, (int) ($_POST['keep'] ?? 0), (int) ($_POST['drop'] ?? 0), $login);
            } elseif ($op === 'undo') {
                $note = kop_fmerge_do_undo($pdo, $prefix, (string) ($_POST['log'] ?? ''));
            } elseif ($op === 'dismiss' || $op === 'undismiss') {
                $note = kop_fmerge_set_dismissed((int) ($_POST['a'] ?? 0), (int) ($_POST['b'] ?? 0), $op === 'dismiss', $login);
            } elseif ($op === 'homes') {
                $a = (int) ($_POST['a'] ?? 0);
                $b = (int) ($_POST['b'] ?? 0);
                $names = array($a => wp_unslash((string) ($_POST['home_a'] ?? '')), $b => wp_unslash((string) ($_POST['home_b'] ?? '')));
                $note = kop_fmerge_make_homes($a, $b, (int) ($_POST['program'] ?? 0), wp_unslash((string) ($_POST['program_name'] ?? '')), $names, $login)['message']
                    . ' Undo is on the Not the same tab.';
            } elseif ($op === 'undo_homes') {
                $note = kop_fmerge_undo_homes((string) ($_POST['log'] ?? ''));
            } else {
                throw new RuntimeException('Unknown action.');
            }
        } catch (Throwable $e) {
            kop_fmerge_flush();
            wp_send_json_error(array('message' => $e->getMessage(), 'data' => kop_fmerge_screen_data(true)), 400);
        }
        kop_fmerge_flush();
        wp_send_json_success(array('message' => $note, 'data' => kop_fmerge_screen_data(true)));
    }
    add_action('wp_ajax_kop_facility_merge', 'kop_fmerge_ajax');
}

if (!function_exists('kop_fmerge_menu')) {
    function kop_fmerge_menu() {
        if (!function_exists('kop_tools_parent_slug')) return;
        add_submenu_page(kop_tools_parent_slug(), 'Merge Duplicates', 'Merge Duplicates', 'manage_options', 'kop-merge-duplicates', 'kop_fmerge_page');
    }
    add_action('admin_menu', 'kop_fmerge_menu', 22);
}

if (!function_exists('kop_fmerge_page')) {
    function kop_fmerge_page() {
        if (!current_user_can('manage_options')) return;
        $config = array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('kop_facility_merge'),
            'data'    => kop_fmerge_screen_data(),
        );
        ?>
        <div class="wrap kop-fm">
            <h1>Merge duplicate facilities</h1>
            <p class="kop-fm__intro">
                Each card is two records that look like one place: the same name written differently, one word apart,
                the company's name in front, or the same street address. Pick the record to keep and press
                <strong>Merge into one</strong>. Everything the other record had moves onto it: its facts and staff,
                articles, lawsuits, inspection links, Woodbury and Fornits items, and every document in its library.
                Its name is kept as another name and its page forwards to the kept one. Renamed programs
                (Copper Canyon / Sedona Sky) are never listed: each name stays its own record.
                Every merge can be undone from the <strong>Merged</strong> tab.
                Two records that are separate homes or cottages of one program are not duplicates: press
                <strong>Homes of one program</strong> to list both on the program's page instead (Undo on the <strong>Not the same</strong> tab).
            </p>
            <div class="kop-fm__any">
                <strong>Merge any two records:</strong>
                <label>keep <?php echo kop_facility_finder_field('', '', ' class="kop-fm__any-keep"'); ?></label>
                <label>fold in <?php echo kop_facility_finder_field('', '', ' class="kop-fm__any-drop"'); ?></label>
                <button type="button" class="button kop-fm__any-go">Merge into one</button>
            </div>
            <div class="kop-fm__bar">
                <div class="kop-fm__tabs" role="tablist">
                    <button type="button" data-tab="likely" aria-selected="true">Likely the same <span></span></button>
                    <button type="button" data-tab="check" aria-selected="false">Worth a look <span></span></button>
                    <button type="button" data-tab="address" aria-selected="false">Same street address <span></span></button>
                    <button type="button" data-tab="homes" aria-selected="false">Looks like homes of one program <span></span></button>
                    <button type="button" data-tab="dismissed" aria-selected="false">Not the same <span></span></button>
                    <button type="button" data-tab="merged" aria-selected="false">Merged <span></span></button>
                </div>
                <input type="search" class="kop-fm__filter" placeholder="Filter by name, state or company">
                <span class="kop-fm__status" role="status" aria-live="polite"></span>
            </div>
            <div class="kop-fm__list"></div>
        </div>
        <style>
            .kop-fm__intro { max-width: 62em; font-size: 14px; }
            .kop-fm__any { display: flex; flex-wrap: wrap; align-items: center; gap: 8px 14px; max-width: 1100px; padding: 10px 12px;
                background: #fff; border: 1px solid #dcdcde; border-radius: 6px; }
            .kop-fm__bar { position: sticky; top: 32px; z-index: 5; display: flex; flex-wrap: wrap; align-items: center; gap: 12px;
                margin: 16px 0; padding: 10px 12px; background: #fff; border: 1px solid #dcdcde; border-radius: 6px; max-width: 1100px; box-sizing: border-box; }
            .kop-fm__tabs { display: flex; flex-wrap: wrap; gap: 4px; }
            .kop-fm__tabs button { padding: 6px 12px; border: 1px solid #c3c4c7; border-radius: 999px; background: #f6f7f7; color: #1d2327; cursor: pointer; font-size: 13px; }
            .kop-fm__tabs button[aria-selected="true"] { background: #000080; border-color: #000080; color: #fff; }
            .kop-fm__tabs span { font-weight: 600; }
            .kop-fm__filter { min-width: 240px; }
            .kop-fm__status { color: #1d7a33; font-weight: 600; }
            .kop-fm__status.is-bad { color: #b32d2e; }
            .kop-fm__list { display: grid; gap: 12px; max-width: 1100px; }
            .kop-fm__card { padding: 14px 16px; background: #fff; border: 1px solid #dcdcde; border-left: 4px solid #33a7b5; border-radius: 6px; }
            .kop-fm__card.is-check { border-left-color: #b26200; }
            .kop-fm__card.is-busy { opacity: .55; pointer-events: none; }
            .kop-fm__why { margin: 0 0 10px; font-weight: 600; color: #1d2327; }
            .kop-fm__pair { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
            @media (max-width: 782px) { .kop-fm__pair { grid-template-columns: 1fr; } }
            .kop-fm__side { display: block; padding: 10px 12px; background: #f6f7f7; border: 2px solid transparent; border-radius: 6px; cursor: pointer; }
            .kop-fm__side.is-keep { border-color: #1d7a33; background: #edf7ef; }
            .kop-fm__pick { display: flex; align-items: center; gap: 6px; font-size: 12px; font-weight: 600; text-transform: uppercase; letter-spacing: .04em; color: #50575e; }
            .kop-fm__side.is-keep .kop-fm__pick { color: #14532d; }
            .kop-fm__name { margin: 4px 0; font-size: 16px; }
            .kop-fm__line { margin: 2px 0; color: #1d2327; }
            .kop-fm__muted { color: #50575e; }
            .kop-fm__company { font-weight: 600; }
            .kop-fm__actions { display: flex; flex-wrap: wrap; align-items: center; gap: 8px 12px; margin-top: 12px; }
            .kop-fm__homes { margin-top: 10px; padding: 10px 12px; background: #f6f7f7; border-radius: 6px; }
            .kop-fm__homes-row { display: flex; flex-wrap: wrap; align-items: center; gap: 6px 14px; margin: 6px 0; }
            .kop-fm__empty { padding: 24px; background: #fff; border: 1px dashed #c3c4c7; border-radius: 6px; text-align: center; }
        </style>
        <script>
        (function () {
            var C = <?php echo wp_json_encode($config); ?>;
            var D = C.data;
            var list = document.querySelector('.kop-fm__list');
            var status = document.querySelector('.kop-fm__status');
            var filter = document.querySelector('.kop-fm__filter');
            var tab = 'likely';
            var keepChoice = {};

            function el(tag, cls, text) {
                var n = document.createElement(tag);
                if (cls) n.className = cls;
                if (text !== undefined) n.textContent = text;
                return n;
            }
            function link(text, url) {
                var a = el('a', '', text); a.href = url; a.target = '_blank'; a.rel = 'noopener'; return a;
            }
            function plural(n, one, many) { return n + ' ' + (n === 1 ? one : many); }

            function send(params, card) {
                var body = new FormData();
                body.append('action', 'kop_facility_merge');
                body.append('nonce', C.nonce);
                Object.keys(params).forEach(function (k) { body.append(k, params[k]); });
                status.className = 'kop-fm__status';
                status.textContent = params.op === 'merge' ? 'Merging...' : 'Saving...';
                if (card) card.classList.add('is-busy');
                fetch(C.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body })
                    .then(function (res) { return res.json(); })
                    .then(function (json) {
                        var d = json && json.data ? json.data : {};
                        if (d.data) D = d.data;
                        if (!json || !json.success) throw new Error(d.message || 'Not saved.');
                        status.textContent = d.message || 'Saved.';
                        render();
                    })
                    .catch(function (e) {
                        status.className = 'kop-fm__status is-bad';
                        status.textContent = 'Not done: ' + e.message;
                        render();
                    });
            }

            function side(s, chosen, onPick, pickable) {
                var box = el(pickable ? 'label' : 'div', 'kop-fm__side' + (chosen ? ' is-keep' : ''));
                if (pickable) {
                    var pick = el('span', 'kop-fm__pick');
                    var radio = el('input'); radio.type = 'radio'; radio.checked = chosen;
                    radio.addEventListener('change', onPick);
                    pick.appendChild(radio);
                    pick.appendChild(document.createTextNode(chosen ? 'Keep this record' : 'Fold into the other'));
                    box.appendChild(pick);
                }
                box.appendChild(el('h2', 'kop-fm__name', s.name));
                if (s.aka && s.aka.length) box.appendChild(el('p', 'kop-fm__line kop-fm__muted', s.aka.join(' · ')));
                var where = [s.place, s.status, s.years].filter(Boolean).join(' · ');
                box.appendChild(el('p', 'kop-fm__line', where || 'No place on file'));
                if (s.address) box.appendChild(el('p', 'kop-fm__line kop-fm__muted', s.address));
                var co = el('p', 'kop-fm__line');
                co.appendChild(el('span', 'kop-fm__muted', 'Company: '));
                co.appendChild(el('span', 'kop-fm__company', s.companies && s.companies.length ? s.companies.join('; ') : 'none recorded'));
                box.appendChild(co);
                if (s.type) box.appendChild(el('p', 'kop-fm__line kop-fm__muted', s.type));
                if (s.news !== undefined) {
                    box.appendChild(el('p', 'kop-fm__line', [plural(s.news, 'article', 'articles'), plural(s.lawsuits, 'lawsuit', 'lawsuits'),
                        plural(s.docs, 'document', 'documents'), plural(s.staff, 'staff entry', 'staff entries')].join(', ')));
                }
                var links = el('p', 'kop-fm__line');
                links.appendChild(el('span', 'kop-fm__muted', 'Record #' + s.id + (s.page ? ' · ' : '')));
                if (s.page) links.appendChild(link('Facility page', s.page));
                box.appendChild(links);
                return box;
            }

            function pairCard(p) {
                var c = el('article', 'kop-fm__card is-' + p.tab);
                c.appendChild(el('p', 'kop-fm__why', p.reason));
                var keep = keepChoice[p.key] || p.keep;
                var pair = el('div', 'kop-fm__pair');
                [p.a, p.b].forEach(function (s) {
                    pair.appendChild(side(s, s.id === keep, function () { keepChoice[p.key] = s.id; render(); }, true));
                });
                c.appendChild(pair);
                var actions = el('div', 'kop-fm__actions');
                var go = el('button', 'button button-primary', 'Merge into one');
                go.type = 'button';
                go.addEventListener('click', function () {
                    var drop = keep === p.a.id ? p.b.id : p.a.id;
                    send({ op: 'merge', keep: keep, drop: drop }, c);
                });
                actions.appendChild(go);
                var no = el('button', 'button', 'Not the same place');
                no.type = 'button';
                no.addEventListener('click', function () { send({ op: 'dismiss', a: p.a.id, b: p.b.id }, c); });
                actions.appendChild(no);
                var k = keep === p.a.id ? p.a : p.b;
                var d = keep === p.a.id ? p.b : p.a;
                if (p.homes) {
                    var hb = el('button', 'button', 'Homes of one program');
                    hb.type = 'button';
                    hb.setAttribute('aria-expanded', 'false');
                    actions.appendChild(hb);
                }
                actions.appendChild(el('span', 'kop-fm__muted', 'Keeps "' + k.name + '"; "' + d.name + '" becomes another name for it.'));
                c.appendChild(actions);
                if (p.homes) {
                    var form = homesForm(p, c);
                    form.hidden = true;
                    c.appendChild(form);
                    hb.addEventListener('click', function () {
                        form.hidden = !form.hidden;
                        hb.setAttribute('aria-expanded', form.hidden ? 'false' : 'true');
                    });
                }
                return c;
            }

            // Two homes of one program, not one place: tie both to a program record (Program Homes).
            function homesForm(p, c) {
                var h = p.homes;
                var f = el('div', 'kop-fm__homes');
                f.appendChild(el('p', 'kop-fm__line', 'Not one place but two homes or cottages of one program: each keeps its own record and both are listed on the program\'s page. This pair is then not offered again.'));
                var uid = 'kop-fm-h-' + p.key.replace(/\D/g, '-');
                var choice = el('div', 'kop-fm__homes-row');
                function radio(value, text, checked) {
                    var l = el('label');
                    var r = el('input'); r.type = 'radio'; r.name = uid + '-prog'; r.value = value; r.checked = !!checked;
                    l.appendChild(r); l.appendChild(document.createTextNode(' ' + text));
                    choice.appendChild(l);
                    return r;
                }
                var rIn = h.program_id ? radio('in', 'Add to ' + (h.program_name || ('record #' + h.program_id)) + ' (#' + h.program_id + ')', true) : null;
                var rNew = radio('new', 'A new program record named', !h.program_id);
                var name = el('input'); name.type = 'text'; name.value = h.program_id ? '' : (h.program_name || ''); name.setAttribute('aria-label', 'Program name');
                choice.appendChild(name);
                var rOther = radio('other', 'Another existing record', false);
                var other = el('input'); other.type = 'number'; other.min = '1'; other.placeholder = 'id'; other.setAttribute('data-kop-facility-finder', '1');
                other.setAttribute('aria-label', 'Program record');
                choice.appendChild(other);
                f.appendChild(choice);
                var homes = el('div', 'kop-fm__homes-row');
                var inputs = {};
                [p.a, p.b].forEach(function (s) {
                    var l = el('label', '', 'Home name for ' + s.name + ' ');
                    var i = el('input'); i.type = 'text'; i.value = (h.homes && h.homes[s.id]) || s.name;
                    l.appendChild(i);
                    homes.appendChild(l);
                    inputs[s.id] = i;
                });
                f.appendChild(homes);
                var go = el('button', 'button button-primary', 'Tie them as homes of the program');
                go.type = 'button';
                go.addEventListener('click', function () {
                    var program = 0;
                    if (rIn && rIn.checked) program = h.program_id;
                    else if (rOther.checked) program = Number(other.value) || 0;
                    if (rOther.checked && !program) { status.className = 'kop-fm__status is-bad'; status.textContent = 'Find the program record first.'; return; }
                    if (rNew.checked && !name.value.trim()) { status.className = 'kop-fm__status is-bad'; status.textContent = 'Give the new program a name.'; return; }
                    send({ op: 'homes', a: p.a.id, b: p.b.id, program: program, program_name: rNew.checked ? name.value.trim() : '',
                        home_a: inputs[p.a.id].value.trim(), home_b: inputs[p.b.id].value.trim() }, c);
                });
                f.appendChild(go);
                if (typeof window.kopFacilityFinderAttach === 'function') window.kopFacilityFinderAttach(other);
                return f;
            }

            function dismissedCard(x) {
                var c = el('article', 'kop-fm__card');
                c.appendChild(el('p', 'kop-fm__why', 'Marked not the same' + (x.by ? ' by ' + x.by : '') + (x.at ? ' on ' + x.at.slice(0, 10) : '')));
                var pair = el('div', 'kop-fm__pair');
                pair.appendChild(side(x.a, false, null, false));
                pair.appendChild(side(x.b, false, null, false));
                c.appendChild(pair);
                var actions = el('div', 'kop-fm__actions');
                var back = el('button', 'button', 'Put back on the list');
                back.type = 'button';
                back.addEventListener('click', function () { send({ op: 'undismiss', a: x.a.id, b: x.b.id }, c); });
                if (x.homes) {
                    // Tied as homes of one program: Undo unties them and puts the pair back.
                    c.querySelector('.kop-fm__why').textContent += ' (tied as homes of program #' + x.homes.program + ')';
                    var uh = el('button', 'button', 'Undo: not homes of one program');
                    uh.type = 'button';
                    uh.addEventListener('click', function () { send({ op: 'undo_homes', log: x.homes.log }, c); });
                    actions.appendChild(uh);
                } else {
                    actions.appendChild(back);
                }
                c.appendChild(actions);
                return c;
            }

            function mergedCard(m) {
                var c = el('article', 'kop-fm__card');
                var h = el('p', 'kop-fm__why', '"' + m.drop.name + '" (#' + m.drop.id + ') merged into "' + m.keep.name + '" (#' + m.keep.id + ')');
                c.appendChild(h);
                var r = m.report || {};
                var moved = [];
                Object.keys(r.rows || {}).forEach(function (t) { moved.push(r.rows[t] + ' in ' + t.replace(/^wpdl_(kop_)?/, '').replace(/_/g, ' ')); });
                if (r.files) moved.push(plural(r.files, 'document', 'documents') + ' moved into the library');
                if (r.subfolders) moved.push(plural(r.subfolders, 'subfolder', 'subfolders') + ' moved');
                c.appendChild(el('p', 'kop-fm__line kop-fm__muted', (m.at || '') + (m.by ? ' by ' + m.by : '') + (moved.length ? ' · ' + moved.join(', ') : '')));
                var actions = el('div', 'kop-fm__actions');
                if (m.page) actions.appendChild(link('Facility page', m.page));
                if (m.undone) {
                    actions.appendChild(el('span', 'kop-fm__muted', 'Undone.'));
                } else if (m.canUndo) {
                    var undo = el('button', 'button', 'Undo');
                    undo.type = 'button';
                    undo.addEventListener('click', function () {
                        if (window.confirm('Split "' + m.drop.name + '" back out into its own record, with everything it had?')) send({ op: 'undo', log: m.id }, c);
                    });
                    actions.appendChild(undo);
                }
                c.appendChild(actions);
                return c;
            }

            function matches(x, q) {
                if (!q) return true;
                var text = JSON.stringify([x.a ? [x.a.name, x.a.aka, x.a.place, x.a.companies] : '', x.b ? [x.b.name, x.b.aka, x.b.place, x.b.companies] : '',
                    x.keep && x.keep.name ? x.keep.name : '', x.drop && x.drop.name ? x.drop.name : '']).toLowerCase();
                return text.indexOf(q) !== -1;
            }

            function render() {
                var counts = { likely: 0, check: 0, address: 0, homes: 0, dismissed: D.dismissed.length, merged: D.merged.filter(function (m) { return !m.undone; }).length };
                D.pairs.forEach(function (p) { counts[p.tab]++; });
                document.querySelectorAll('.kop-fm__tabs button').forEach(function (b) {
                    var t = b.getAttribute('data-tab');
                    b.querySelector('span').textContent = '(' + counts[t] + ')';
                    b.setAttribute('aria-selected', t === tab ? 'true' : 'false');
                });
                var q = (filter.value || '').trim().toLowerCase();
                list.textContent = '';
                var shown;
                if (tab === 'merged') shown = D.merged.filter(function (x) { return matches(x, q); }).map(mergedCard);
                else if (tab === 'dismissed') shown = D.dismissed.filter(function (x) { return matches(x, q); }).map(dismissedCard);
                else shown = D.pairs.filter(function (p) { return p.tab === tab && matches(p, q); }).map(pairCard);
                if (!shown.length) list.appendChild(el('p', 'kop-fm__empty', tab === 'merged' ? 'No merges yet.' : 'Nothing here.'));
                shown.forEach(function (n) { list.appendChild(n); });
            }

            document.querySelector('.kop-fm__any-go').addEventListener('click', function () {
                var keep = Number(document.querySelector('.kop-fm__any-keep').value) || 0;
                var drop = Number(document.querySelector('.kop-fm__any-drop').value) || 0;
                if (!keep || !drop || keep === drop) {
                    status.className = 'kop-fm__status is-bad';
                    status.textContent = 'Find the two records first (two different ones).';
                    return;
                }
                if (window.confirm('Fold record #' + drop + ' into record #' + keep + '?')) send({ op: 'merge', keep: keep, drop: drop }, null);
            });
            document.querySelectorAll('.kop-fm__tabs button').forEach(function (b) {
                b.addEventListener('click', function () { tab = b.getAttribute('data-tab'); status.textContent = ''; render(); });
            });
            filter.addEventListener('input', render);
            render();
        })();
        </script>
        <?php
    }
}
