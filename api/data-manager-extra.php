<?php
/**
 * Data Manager, the later categories and actions (required by api/data-manager.php,
 * which has already checked the admin and opened $pdo).
 *
 * Every change goes through the module that owns it, with that module's Undo:
 *   merged      facility records merged into another (inc/facility-merge.php:
 *               kop_fmerge_do_merge() / kop_fmerge_do_undo())
 *   converted   parent companies converted into one program of homes
 *               (inc/program-homes-convert.php: kop_phc_convert() / kop_phc_undo())
 *   news, lawsuits, bills
 *               the records, with the facilities a news item or lawsuit is
 *               linked to; a link is added, moved or removed in the record's
 *               own facilities_mentioned list and its link table, then the
 *               record's own sync runs (kop_sync_news_facility_links(),
 *               kop_sync_lawsuit_facility_links()); the state before is kept
 *               for an exact Undo. Bills hold no facility links.
 *   filing news under Indian boarding schools or a young adult program goes
 *               through the review inbox's own move (kop_rinbox_native_move(),
 *               undone by kop_rinbox_native_unmove()).
 *
 * GET  ?action=filters                       states, statuses, facility types for the toolbar
 * GET  ?action=record_detail&kind=&id=       one news item / lawsuit / bill and its facilities
 * GET  ?action=company_convert&operator_id=  whether a company is one program of homes
 * GET  ?action=file_options                  schools and young adult programs to file news under
 * POST merge_facility {drop, keep} / undo_facility_merge {log}
 * POST convert_company {operator_id, program_name} / undo_convert {operator_id}
 * POST record_links {kind, id, op: add|move|remove, from?, to?} / undo_record_links {token}
 * POST file_news {id, to: indigenous|young_adult, school_id?, ya_id?, ya_name?} / undo_file_news {id}
 */

if (!defined('ABSPATH')) {
    exit;
}

/** The record tables: kind => table, title column(s), status column, link table. */
function kop_dm_record_kinds(): array {
    return [
        'news'    => ['table' => 'news_submissions', 'category' => 'news', 'status' => 'status', 'links' => 'news_facility_links', 'link_col' => 'news_id',
                      'admin' => '/admin-submissions/'],
        'lawsuit' => ['table' => 'lawsuits', 'category' => 'lawsuits', 'status' => 'publication_status', 'links' => 'lawsuit_facility_links', 'link_col' => 'lawsuit_id',
                      'admin' => '/admin-lawsuits/'],
        'bill'    => ['table' => 'legislation', 'category' => 'bills', 'status' => 'publication_status', 'links' => '', 'link_col' => '',
                      'admin' => '/admin-legislation/'],
    ];
}

function kop_dm_record_kind_of_category(string $category): string {
    foreach (kop_dm_record_kinds() as $k => $d) if ($d['category'] === $category) return $k;
    return '';
}

function kop_dm_admin_page(string $path): string {
    return home_url($path);
}

// ---------------------------------------------------------------------------
// Toolbar filters
// ---------------------------------------------------------------------------

/** {states, statuses, types: [[value, count]]} for the facility filters. */
function kop_dm_filter_options(PDO $pdo): array {
    $col = static function (string $c) use ($pdo): array {
        $out = [];
        foreach ($pdo->query("SELECT $c AS v, COUNT(*) AS n FROM facilities_v2 WHERE $c IS NOT NULL AND $c <> '' GROUP BY $c ORDER BY $c")->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[] = [(string)$r['v'], (int)$r['n']];
        }
        return $out;
    };
    return ['states' => $col('state'), 'statuses' => $col('status'), 'types' => $col('facility_type')];
}

/** The filters from the request: {state, status, type, years: 'none'|''}. Empty strings = not set. */
function kop_dm_filters(): array {
    $f = [];
    foreach (['state', 'status', 'type', 'years'] as $k) $f[$k] = trim((string)($_GET[$k] ?? ''));
    return $f;
}

function kop_dm_filters_set(array $f): bool {
    return $f['state'] !== '' || $f['status'] !== '' || $f['type'] !== '' || $f['years'] !== '';
}

/** SQL WHERE pieces for the filters, on facilities_v2. */
function kop_dm_filter_sql(array $f, array &$params): string {
    $w = [];
    if ($f['state'] !== '') { $w[] = 'state = ?'; $params[] = $f['state']; }
    if ($f['status'] !== '') { $w[] = 'status = ?'; $params[] = $f['status']; }
    if ($f['type'] === '-') {
        $w[] = "(facility_type IS NULL OR facility_type = '')";
    } elseif ($f['type'] !== '') {
        $w[] = 'facility_type = ?';
        $params[] = $f['type'];
    }
    if ($f['years'] === 'none') $w[] = '(start_year IS NULL OR start_year = 0)';
    if ($f['years'] === 'closed_no_end') $w[] = "(status = 'Closed' AND (end_year IS NULL OR end_year = 0))";
    return $w ? implode(' AND ', $w) : '';
}

/** "1998-2014", "1998-", "-2014" or ''. */
function kop_dm_years($start, $end): string {
    $s = (int)$start;
    $e = (int)$end;
    if (!$s && !$e) return '';
    return ($s ?: '?') . '-' . ($e ?: '');
}

// ---------------------------------------------------------------------------
// Merged records and converted companies
// ---------------------------------------------------------------------------

/** Facility records merged into another, newest first. */
function kop_dm_merged_items(string $q): array {
    if (!function_exists('kop_fmerge_log')) return [];
    $map = kop_facility_merged_into();
    $items = [];
    foreach (kop_fmerge_log() as $e) {
        if (!empty($e['undone']) || empty($e['drop']['id'])) continue;
        $drop = (int)$e['drop']['id'];
        if (!isset($map['ids'][$drop])) continue;
        $name = (string)($e['drop']['name'] ?: $e['drop']['unique_name']);
        $keepName = (string)($e['keep']['name'] ?: $e['keep']['unique_name']);
        if ($q !== '' && mb_stripos($name, $q) === false && mb_stripos($keepName, $q) === false) continue;
        $keep = kop_facility_merge_resolve($drop);
        $items[] = [
            'id'                 => $drop,
            'unique_name'        => (string)$e['drop']['unique_name'],
            'category'           => 'merged',
            'kind'               => 'merged',
            'table'              => 'facilities_v2',
            'display_name'       => $name,
            'designation'        => 'Merged into ' . $keepName . ' #' . $keep,
            'place'              => 'Merged ' . substr((string)$e['at'], 0, 10) . (!empty($e['by']) ? ' by ' . $e['by'] : ''),
            'facility_count'     => 0,
            'document_folder_id' => null,
            'is_stub'            => false,
            'page_url'           => kop_dm_facility_url($keep),
            'log_id'             => (string)$e['id'],
            'admin_url'          => admin_url('admin.php?page=kop-merge-duplicates'),
            'can_undo'           => !empty($e['undo']),
        ];
    }
    return $items;
}

/** Parent companies converted into one program of homes. */
function kop_dm_converted_items(PDO $pdo, string $q): array {
    if (!function_exists('kop_phc_rows')) return [];
    $rows = kop_phc_rows(true);
    $names = kop_dm_v2_names($pdo, array_map(static function ($r) { return (int)$r['program_id']; }, $rows));
    $items = [];
    foreach ($rows as $oid => $r) {
        $pid = (int)$r['program_id'];
        $program = $names[$pid] ?? ('#' . $pid);
        if ($q !== '' && mb_stripos((string)$r['name'], $q) === false && mb_stripos($program, $q) === false) continue;
        $items[] = [
            'id'                 => (int)$oid,
            'unique_name'        => (string)$r['name'],
            'category'           => 'converted',
            'kind'               => 'converted',
            'table'              => 'kop_program_conversions',
            'display_name'       => (string)$r['name'],
            'designation'        => 'Now the program ' . $program . ' #' . $pid,
            'place'              => 'Converted ' . substr((string)$r['converted_at'], 0, 10),
            'facility_count'     => 0,
            'document_folder_id' => null,
            'is_stub'            => false,
            'page_url'           => kop_dm_facility_url($pid),
            'admin_url'          => admin_url('admin.php?page=kop-program-homes'),
        ];
    }
    return $items;
}

// ---------------------------------------------------------------------------
// News, lawsuits, bills
// ---------------------------------------------------------------------------

/** List rows for one record kind matching $q (title, outlet/court, number). */
function kop_dm_record_items(PDO $pdo, string $kind, string $q): array {
    $d = kop_dm_record_kinds()[$kind];
    if ($kind === 'news') {
        $cols = "id, article_title AS title, publication_name AS sub, publication_date AS date, status AS st, article_url AS url";
        $search = ['article_title', 'alternate_title', 'publication_name', 'article_url'];
        $where = "status NOT IN ('deleted')";
    } elseif ($kind === 'lawsuit') {
        $cols = "id, case_name AS title, CONCAT_WS(' ', court, case_number) AS sub, filing_date AS date, publication_status AS st, status AS case_status";
        $search = ['case_name', 'case_number', 'court'];
        $where = '1=1';
    } else {
        $cols = "id, CONCAT_WS(' ', bill_number, bill_title) AS title, CONCAT_WS(' ', jurisdiction, session_year) AS sub, introduced_date AS date, publication_status AS st, status AS case_status";
        $search = ['bill_number', 'bill_title', 'jurisdiction'];
        $where = '1=1';
    }
    $params = [];
    if ($q !== '' && ctype_digit(ltrim($q, '#'))) {
        $where .= ' AND id = ?';
        $params[] = (int)ltrim($q, '#');
    } elseif ($q !== '') {
        $where .= ' AND (' . implode(' OR ', array_map(static function ($c) { return "$c LIKE ?"; }, $search)) . ')';
        foreach ($search as $c) $params[] = '%' . $q . '%';
    }
    try {
        $stmt = $pdo->prepare("SELECT $cols FROM `{$d['table']}` WHERE $where ORDER BY id DESC");
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        return [];
    }
    $counts = [];
    if ($d['links'] !== '') {
        try {
            foreach ($pdo->query("SELECT {$d['link_col']} AS rid, COUNT(*) AS n FROM `{$d['links']}` GROUP BY {$d['link_col']}")->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $counts[(int)$r['rid']] = (int)$r['n'];
            }
        } catch (PDOException $e) { /* table missing */ }
    }
    $items = [];
    foreach ($rows as $r) {
        $id = (int)$r['id'];
        $title = trim((string)$r['title']) ?: ('#' . $id);
        $items[] = [
            'id'                 => $id,
            'unique_name'        => $title,
            'category'           => $d['category'],
            'kind'               => $kind,
            'table'              => $d['table'],
            'display_name'       => $title,
            'place'              => implode(' · ', array_filter([trim((string)$r['sub']), substr((string)$r['date'], 0, 10), trim((string)($r['case_status'] ?? ''))])),
            'status'             => (string)$r['st'],
            'designation'        => (string)$r['st'],
            'home_role'          => in_array((string)$r['st'], ['approved', 'published'], true) ? 'x' : 'pending',
            'linked_count'       => $counts[$id] ?? 0,
            'facility_count'     => 0,
            'document_folder_id' => null,
            'is_stub'            => false,
            'source_url'         => (string)($r['url'] ?? ''),
            'admin_url'          => kop_dm_admin_page($d['admin']),
        ];
    }
    return $items;
}

/** One record and the facilities linked to it. */
function kop_dm_record_detail(PDO $pdo, string $kind, int $id): array {
    $kinds = kop_dm_record_kinds();
    if (!isset($kinds[$kind])) throw new RuntimeException('Unknown kind of record.');
    $d = $kinds[$kind];
    $items = kop_dm_record_items($pdo, $kind, '#' . $id);
    if (!$items) throw new RuntimeException('There is no ' . $kind . ' #' . $id . '.');
    $out = $items[0];
    $out['links'] = [];
    $out['can_link'] = $d['links'] !== '';
    if ($d['links'] !== '') {
        $stmt = $pdo->prepare("SELECT facility_id, link_type FROM `{$d['links']}` WHERE {$d['link_col']} = ? ORDER BY facility_id");
        $stmt->execute([$id]);
        $links = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $ids = array_map(static function ($r) { return (int)$r['facility_id']; }, $links);
        $info = [];
        if ($ids) {
            foreach ($pdo->query('SELECT id, name, unique_name, city, state FROM facilities_v2 WHERE id IN (' . implode(',', $ids) . ')')->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $info[(int)$r['id']] = $r;
            }
        }
        foreach ($links as $r) {
            $fid = (int)$r['facility_id'];
            $f = $info[$fid] ?? null;
            $out['links'][] = [
                'id'        => $fid,
                'name'      => $f ? (string)($f['name'] ?: $f['unique_name']) : 'Record #' . $fid . ' (gone)',
                'place'     => $f ? trim(implode(', ', array_filter([(string)$f['city'], (string)$f['state']]))) : '',
                'link_type' => (string)$r['link_type'],
                'page_url'  => $f ? kop_dm_facility_url($fid) : '',
            ];
        }
    }
    if ($kind === 'news') {
        $out['filed'] = [];
        if (function_exists('kop_ischools_pdo') && ($is = kop_ischools_pdo())) {
            try {
                $stmt = $is->prepare('SELECT school_id FROM indigenous_school_news WHERE news_id = ?');
                $stmt->execute([$id]);
                foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $sid) $out['filed'][] = 'Indian boarding schools' . ((int)$sid ? ' (school #' . (int)$sid . ')' : '');
            } catch (PDOException $e) { /* table missing */ }
        }
        $moves = function_exists('kop_rinbox_native_moves') ? kop_rinbox_native_moves() : [];
        $out['can_unfile'] = !empty($moves['news:' . $id]);
    }
    return $out;
}

/** The facilities_mentioned entries of one record, decoded as stored (a list). */
function kop_dm_mentions_raw($value): array {
    $v = is_string($value) ? json_decode($value, true) : $value;
    return is_array($v) ? array_values($v) : [];
}

/** The facility a stored mention points at: its facility_id, else what its name resolves to. */
function kop_dm_mention_target($m, array &$aliasIndex, PDO $pdo): int {
    if (is_array($m) && !empty($m['facility_id'])) return (int)$m['facility_id'];
    $name = is_array($m) ? trim((string)($m['name'] ?? '')) : trim((string)$m);
    if ($name === '') return 0;
    if (!$aliasIndex) $aliasIndex = kop_build_facility_alias_index($pdo);
    return (int)(kop_resolve_mention_to_facility($name, $aliasIndex) ?? 0);
}

/**
 * Adds, moves or removes one facility link of a news item or lawsuit:
 * in its facilities_mentioned list (so the record's own sync keeps it) and
 * in its link table (links an admin made by hand). Returns the message;
 * the state before is saved for undo_record_links.
 */
function kop_dm_record_links(PDO $pdo, string $kind, int $id, string $op, int $from, int $to): array {
    $kinds = kop_dm_record_kinds();
    if (!isset($kinds[$kind]) || $kinds[$kind]['links'] === '') throw new RuntimeException('This kind of record has no facility links.');
    $d = $kinds[$kind];
    require_once __DIR__ . '/news-mentions.php';
    require_once __DIR__ . '/lawsuit-facility-links.php';
    $stmt = $pdo->prepare("SELECT facilities_mentioned FROM `{$d['table']}` WHERE id = ?");
    $stmt->execute([$id]);
    $before = $stmt->fetchColumn();
    if ($before === false) throw new RuntimeException('That record is gone.');
    $stmt = $pdo->prepare("SELECT facility_id, link_type, created_by FROM `{$d['links']}` WHERE {$d['link_col']} = ?");
    $stmt->execute([$id]);
    $linksBefore = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $names = kop_dm_v2_names($pdo, [$from, $to]);
    if ($op !== 'remove' && !isset($names[$to])) throw new RuntimeException('Pick the facility record it should be linked to.');
    if ($op !== 'add' && $from <= 0) throw new RuntimeException('Which link?');
    if ($op === 'move' && $from === $to) throw new RuntimeException('That is the same record.');

    $mentions = kop_dm_mentions_raw($before);
    $alias = [];
    $out = [];
    $hit = false;
    foreach ($mentions as $m) {
        if ($op !== 'add' && kop_dm_mention_target($m, $alias, $pdo) === $from) {
            $hit = true;
            if ($op === 'remove') continue;
            $m = ['name' => $names[$to], 'facility_id' => $to];
        }
        $out[] = $m;
    }
    if ($op === 'add') $out[] = ['name' => $names[$to], 'facility_id' => $to];
    // Dedupe on facility id (a move onto a record already listed).
    $seen = [];
    $out = array_values(array_filter($out, static function ($m) use (&$seen) {
        $fid = is_array($m) ? (int)($m['facility_id'] ?? 0) : 0;
        if (!$fid) return true;
        if (isset($seen[$fid])) return false;
        return $seen[$fid] = true;
    }));

    $pdo->beginTransaction();
    try {
        $pdo->prepare("UPDATE `{$d['table']}` SET facilities_mentioned = ? WHERE id = ?")->execute([json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $id]);
        // Hand-made links (primary/related) are not the sync's; move or drop them here.
        if ($op === 'move') {
            $has = $pdo->prepare("SELECT COUNT(*) FROM `{$d['links']}` WHERE {$d['link_col']} = ? AND facility_id = ?");
            $has->execute([$id, $to]);
            if ((int)$has->fetchColumn()) {
                $pdo->prepare("DELETE FROM `{$d['links']}` WHERE {$d['link_col']} = ? AND facility_id = ?")->execute([$id, $from]);
            } else {
                $pdo->prepare("UPDATE `{$d['links']}` SET facility_id = ? WHERE {$d['link_col']} = ? AND facility_id = ?")->execute([$to, $id, $from]);
            }
        } elseif ($op === 'remove') {
            $pdo->prepare("DELETE FROM `{$d['links']}` WHERE {$d['link_col']} = ? AND facility_id = ?")->execute([$id, $from]);
        }
        if ($kind === 'news') {
            kop_sync_news_facility_links($pdo, $id, kop_normalize_facility_mentions($out), kop_dm_by());
        } else {
            kop_sync_lawsuit_facility_links($pdo, $id, $out, kop_dm_by());
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    $token = bin2hex(random_bytes(8));
    $log = get_option('kop_dm_link_undo', []);
    $log = is_array($log) ? $log : [];
    $log[$token] = ['kind' => $kind, 'id' => $id, 'mentions' => $before, 'links' => $linksBefore, 'at' => time()];
    if (count($log) > 100) $log = array_slice($log, -100, null, true);
    update_option('kop_dm_link_undo', $log, false);

    $fromName = $names[$from] ?? ('#' . $from);
    $msg = $op === 'add' ? 'Linked to ' . $names[$to] . '.'
        : ($op === 'move' ? 'Moved from ' . $fromName . ' to ' . $names[$to] . '.' : 'No longer linked to ' . $fromName . '.');
    if ($op !== 'add' && !$hit) $msg .= ' (It was a link made by hand, not one from the list of facilities the record names.)';
    return ['message' => $msg, 'undo_token' => $token];
}

/** Puts one record's facility list and links back exactly as they were. */
function kop_dm_undo_record_links(PDO $pdo, string $token): string {
    $log = get_option('kop_dm_link_undo', []);
    if (!is_array($log) || empty($log[$token])) throw new RuntimeException('That change cannot be undone any more.');
    $u = $log[$token];
    $d = kop_dm_record_kinds()[$u['kind']];
    $pdo->beginTransaction();
    try {
        $pdo->prepare("UPDATE `{$d['table']}` SET facilities_mentioned = ? WHERE id = ?")->execute([$u['mentions'], (int)$u['id']]);
        $pdo->prepare("DELETE FROM `{$d['links']}` WHERE {$d['link_col']} = ?")->execute([(int)$u['id']]);
        $ins = $pdo->prepare("INSERT INTO `{$d['links']}` ({$d['link_col']}, facility_id, link_type, created_by) VALUES (?, ?, ?, ?)");
        foreach ($u['links'] as $l) $ins->execute([(int)$u['id'], (int)$l['facility_id'], (string)$l['link_type'], $l['created_by']]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    unset($log[$token]);
    update_option('kop_dm_link_undo', $log, false);
    return 'Undone: the links are as they were.';
}

// ---------------------------------------------------------------------------
// Request handlers (called from api/data-manager.php)
// ---------------------------------------------------------------------------

/** GET actions of this file; returns the response, or null when $action is not one of them. */
function kop_dm_extra_get(PDO $pdo, string $action): ?array {
    if ($action === 'filters') {
        return ['success' => true] + kop_dm_filter_options($pdo);
    }
    if ($action === 'record_detail') {
        return ['success' => true, 'record' => kop_dm_record_detail($pdo, (string)($_GET['kind'] ?? ''), (int)($_GET['id'] ?? 0))];
    }
    if ($action === 'company_convert') {
        if (!function_exists('kop_phc_suggestions')) throw new RuntimeException('Program Homes is not set up on this site.');
        $oid = (int)($_GET['operator_id'] ?? 0);
        foreach (kop_phc_suggestions() as $s) {
            if ((int)$s['operator_id'] === $oid) return ['success' => true, 'suggestion' => $s];
        }
        return ['success' => true, 'suggestion' => null];
    }
    if ($action === 'file_options') {
        $opt = static function (array $o): array {
            $out = [];
            foreach ($o as $v => $label) $out[] = [(string)$v, (string)$label];
            return $out;
        };
        return ['success' => true,
            'schools' => function_exists('kop_rdest_school_options') ? $opt(kop_rdest_school_options()) : [],
            'young_adult' => function_exists('kop_rdest_ya_options') ? $opt(kop_rdest_ya_options()) : []];
    }
    return null;
}

/** POST actions of this file; returns the response, or null when $action is not one of them. */
function kop_dm_extra_post(PDO $pdo, string $action, array $input): ?array {
    if ($action === 'merge_facility' || $action === 'undo_facility_merge') {
        if (!function_exists('kop_fmerge_do_merge')) throw new RuntimeException('Merge Duplicates is not set up on this site.');
        $mpdo = kop_seed_pdo();
        $mpdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $prefix = $GLOBALS['wpdb']->prefix;
        if ($action === 'merge_facility') {
            $drop = (int)($input['drop'] ?? 0);
            $keep = kop_facility_merge_resolve((int)($input['keep'] ?? 0));
            if ($drop <= 0 || $keep <= 0 || $drop === $keep) throw new RuntimeException('Pick the other record, the one to keep.');
            $msg = kop_fmerge_do_merge($mpdo, $prefix, $keep, $drop, kop_dm_by());
            kop_fmerge_flush();
            return ['success' => true, 'message' => $msg, 'undo_log' => (string)(kop_fmerge_log()[0]['id'] ?? '')];
        }
        $msg = kop_fmerge_do_undo($mpdo, $prefix, (string)($input['log'] ?? ''));
        kop_fmerge_flush();
        return ['success' => true, 'message' => $msg];
    }
    if ($action === 'convert_company' || $action === 'undo_convert') {
        if (!function_exists('kop_phc_convert')) throw new RuntimeException('Program Homes is not set up on this site.');
        kop_phc_install();
        $oid = (int)($input['operator_id'] ?? 0);
        if ($action === 'undo_convert') {
            return ['success' => true, 'message' => kop_phc_undo($oid, kop_program_homes_opts())];
        }
        $pid = kop_phc_convert($oid, trim((string)($input['program_name'] ?? '')), [], kop_program_homes_opts());
        $c = kop_phc_rows(true)[$oid] ?? ['name' => '#' . $oid];
        return ['success' => true, 'program_id' => (int)$pid,
            'message' => $c['name'] . ' is now the program record #' . $pid . ', its homes listed under it; its company page sends readers there.'];
    }
    if ($action === 'record_links') {
        $op = (string)($input['op'] ?? '');
        if (!in_array($op, ['add', 'move', 'remove'], true)) throw new RuntimeException('Unknown change.');
        return ['success' => true] + kop_dm_record_links($pdo, (string)($input['kind'] ?? ''), (int)($input['id'] ?? 0), $op,
            (int)($input['from'] ?? 0), (int)($input['to'] ?? 0));
    }
    if ($action === 'undo_record_links') {
        return ['success' => true, 'message' => kop_dm_undo_record_links($pdo, (string)($input['token'] ?? ''))];
    }
    if ($action === 'file_news' || $action === 'undo_file_news') {
        if (!function_exists('kop_rinbox_native_move')) throw new RuntimeException('The review inbox is not set up on this site.');
        $id = (int)($input['id'] ?? 0);
        if ($action === 'undo_file_news') {
            return ['success' => true] + kop_rinbox_native_unmove('news', $id);
        }
        $to = (string)($input['to'] ?? '');
        if (!in_array($to, ['indigenous', 'young_adult'], true)) throw new RuntimeException('File it where?');
        return ['success' => true] + kop_rinbox_native_move('news', $id, $to, [
            'school_id' => (int)($input['school_id'] ?? 0),
            'ya_id'     => (int)($input['ya_id'] ?? 0),
            'ya_name'   => trim((string)($input['ya_name'] ?? '')),
        ]);
    }
    return null;
}
