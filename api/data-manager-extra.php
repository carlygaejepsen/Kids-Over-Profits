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
 *
 * Lawsuit news vs court documents, legislation news vs bills:
 *   list category=lawsuit_news / legislation_news: articles typed 'lawsuit' or
 *               tagged Lawsuit / tagged Legislation, or tied to a case / bill
 *   record_detail of a lawsuit: its court documents (document_urls), other
 *               sources (source_urls) and news coverage (lawsuit_news_links);
 *               of a bill: its official pages and news coverage
 *               (legislation_news_links, api/legislation-news-links.php)
 * GET  ?action=find_record&kind=news|lawsuit|bill&q=
 * POST news_kind {id, to: general|lawsuit|legislation}
 * POST coverage_link {kind: lawsuit|bill, id, news_id, op: add|remove}
 *      (off on an automatic case match = link_type 'excluded', kept off)
 * POST lawsuit_doc_move {id, url, to: documents|sources}
 * POST undo_change {token}  exact Undo of the three above
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/legislation-news-links.php';

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
    if ($category === 'lawsuit_news' || $category === 'legislation_news') return 'news';
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

/** {lawsuit: {news id: true}, legislation: {news id: true}}: articles tied to a case or a bill. */
function kop_dm_news_linked_sets(PDO $pdo): array {
    $out = ['lawsuit' => [], 'legislation' => []];
    try {
        foreach ($pdo->query("SELECT DISTINCT news_id FROM lawsuit_news_links WHERE link_type <> 'excluded'")->fetchAll(PDO::FETCH_COLUMN) as $n) $out['lawsuit'][(int)$n] = true;
    } catch (PDOException $e) { /* table missing */ }
    try {
        foreach ($pdo->query('SELECT DISTINCT news_id FROM legislation_news_links')->fetchAll(PDO::FETCH_COLUMN) as $n) $out['legislation'][(int)$n] = true;
    } catch (PDOException $e) { /* table missing */ }
    return $out;
}

/** 'lawsuit', 'legislation' or '' (general) for one news row (article_type, tags, id). */
function kop_dm_news_kind(array $r, array $linked): string {
    $tags = json_decode((string)($r['tags'] ?? ''), true);
    $tags = is_array($tags) ? array_map(static function ($t) { return strtolower(trim((string)$t)); }, $tags) : [];
    if (($r['article_type'] ?? '') === 'lawsuit' || in_array('lawsuit', $tags, true) || in_array('lawsuits', $tags, true) || isset($linked['lawsuit'][(int)$r['id']])) return 'lawsuit';
    if (in_array('legislation', $tags, true) || isset($linked['legislation'][(int)$r['id']])) return 'legislation';
    return '';
}

/** List rows for one record kind matching $q (title, outlet/court, number). */
function kop_dm_record_items(PDO $pdo, string $kind, string $q, string $newsKind = ''): array {
    $d = kop_dm_record_kinds()[$kind];
    $linked = ['lawsuit' => [], 'legislation' => []];
    if ($kind === 'news') {
        $cols = "id, article_title AS title, publication_name AS sub, publication_date AS date, status AS st, article_url AS url, article_type, tags";
        $search = ['article_title', 'alternate_title', 'publication_name', 'article_url'];
        $where = "status NOT IN ('deleted')";
        $linked = kop_dm_news_linked_sets($pdo);
        // Lawsuit news / legislation news: typed or tagged so, or tied to a case / bill.
        if ($newsKind === 'lawsuit') {
            $where .= " AND (article_type = 'lawsuit' OR tags LIKE '%\"Lawsuit%'"
                . ($linked['lawsuit'] ? ' OR id IN (' . implode(',', array_keys($linked['lawsuit'])) . ')' : '') . ')';
        } elseif ($newsKind === 'legislation') {
            $where .= " AND (tags LIKE '%\"Legislation\"%'"
                . ($linked['legislation'] ? ' OR id IN (' . implode(',', array_keys($linked['legislation'])) . ')' : '') . ')';
        }
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
        $nk = $kind === 'news' ? kop_dm_news_kind($r, $linked) : '';
        $items[] = [
            'id'                 => $id,
            'unique_name'        => $title,
            'category'           => $nk !== '' ? $nk . '_news' : $d['category'],
            'news_kind'          => $nk,
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
    if ($kind === 'lawsuit' || $kind === 'bill') {
        $out += kop_dm_coverage_detail($pdo, $kind, $id);
    }
    if ($kind === 'news') {
        $out += kop_dm_news_ties($pdo, $id);
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
// Lawsuit news vs court documents, legislation news vs bills
// ---------------------------------------------------------------------------

/** 'court' or 'news': a guess from the address, for labelling a lawsuit's links. */
function kop_dm_url_looks(string $url): string {
    $u = strtolower($url);
    $host = (string)parse_url($u, PHP_URL_HOST);
    if (preg_match('/\.pdf(\?|#|$)/', $u)) return 'court';
    foreach (['court', 'uscourts', 'justia', 'courtlistener', 'pacer', 'casetext', 'law.cornell', 'leagle', 'casemine', 'govinfo', 'docket', 'unicourt', 'trellis', 'plainsite'] as $w) {
        if (strpos($host, $w) !== false || strpos($u, '/docket') !== false) return 'court';
    }
    return 'news';
}

function kop_dm_json_list($v): array {
    $d = is_string($v) ? json_decode($v, true) : $v;
    return is_array($d) ? array_values(array_filter(array_map('strval', $d), 'strlen')) : [];
}

/** A lawsuit's or bill's documents, other sources and news coverage. */
function kop_dm_coverage_detail(PDO $pdo, string $kind, int $id): array {
    $out = ['documents' => [], 'sources' => [], 'coverage' => []];
    if ($kind === 'lawsuit') {
        $stmt = $pdo->prepare('SELECT source_urls, document_urls FROM lawsuits WHERE id = ?');
        $stmt->execute([$id]);
        $r = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        foreach (kop_dm_json_list($r['document_urls'] ?? '') as $u) $out['documents'][] = ['url' => $u, 'label' => kop_dm_url_label($u)];
        foreach (kop_dm_json_list($r['source_urls'] ?? '') as $u) $out['sources'][] = ['url' => $u, 'label' => kop_dm_url_label($u), 'looks' => kop_dm_url_looks($u)];
        $sql = "SELECT n.id, n.article_title, n.alternate_title, n.publication_name, n.publication_date, n.article_url, n.status, l.link_type, l.match_reason
                  FROM lawsuit_news_links l JOIN news_submissions n ON n.id = l.news_id WHERE l.lawsuit_id = ? ORDER BY n.publication_date DESC, n.id DESC";
    } else {
        $stmt = $pdo->prepare('SELECT official_url, full_text_url FROM legislation WHERE id = ?');
        $stmt->execute([$id]);
        $r = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        if (!empty($r['official_url'])) $out['documents'][] = ['url' => (string)$r['official_url'], 'label' => 'Official tracker'];
        if (!empty($r['full_text_url'])) $out['documents'][] = ['url' => (string)$r['full_text_url'], 'label' => 'Full text'];
        kop_legislation_news_links_ensure_table($pdo);
        $sql = "SELECT n.id, n.article_title, n.alternate_title, n.publication_name, n.publication_date, n.article_url, n.status, 'manual' AS link_type, '' AS match_reason
                  FROM legislation_news_links l JOIN news_submissions n ON n.id = l.news_id WHERE l.legislation_id = ? ORDER BY n.publication_date DESC, n.id DESC";
    }
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$id]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $n) {
            $out['coverage'][] = [
                'news_id'   => (int)$n['id'],
                'title'     => (string)($n['alternate_title'] ?: $n['article_title']),
                'meta'      => implode(' · ', array_filter([(string)$n['publication_name'], substr((string)$n['publication_date'], 0, 10), (string)$n['status']])),
                'url'       => (string)$n['article_url'],
                'link_type' => (string)$n['link_type'],
                'reason'    => (string)$n['match_reason'],
            ];
        }
    } catch (PDOException $e) { /* table missing */ }
    return $out;
}

/** The words a link reads as (kop_url_label() when loaded), else its host. */
function kop_dm_url_label(string $url): string {
    if (function_exists('kop_url_label')) {
        $l = (string)kop_url_label($url);
        if ($l !== '') return $l;
    }
    return (string)(parse_url($url, PHP_URL_HOST) ?: $url);
}

/** An article's kind and the cases / bills it is tied to. */
function kop_dm_news_ties(PDO $pdo, int $id): array {
    $out = ['cases' => [], 'bills' => []];
    try {
        $stmt = $pdo->prepare("SELECT l.lawsuit_id AS id, s.case_name AS title, l.link_type FROM lawsuit_news_links l JOIN lawsuits s ON s.id = l.lawsuit_id
                                WHERE l.news_id = ? AND l.link_type <> 'excluded' ORDER BY s.case_name");
        $stmt->execute([$id]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) $out['cases'][] = ['id' => (int)$r['id'], 'title' => (string)$r['title'], 'link_type' => (string)$r['link_type']];
    } catch (PDOException $e) { /* table missing */ }
    try {
        kop_legislation_news_links_ensure_table($pdo);
        $stmt = $pdo->prepare("SELECT l.legislation_id AS id, CONCAT_WS(' ', b.bill_number, b.bill_title) AS title FROM legislation_news_links l JOIN legislation b ON b.id = l.legislation_id
                                WHERE l.news_id = ? ORDER BY b.bill_number");
        $stmt->execute([$id]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) $out['bills'][] = ['id' => (int)$r['id'], 'title' => (string)$r['title']];
    } catch (PDOException $e) { /* table missing */ }
    return $out;
}

/** Everything one change can touch, for an exact Undo: an article, a case or a bill. */
function kop_dm_snapshot(PDO $pdo, string $kind, int $id): array {
    $rows = static function (string $sql) use ($pdo, $id): array {
        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$id]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            return [];
        }
    };
    if ($kind === 'news') {
        return ['kind' => 'news', 'id' => $id,
            'row' => $rows('SELECT article_type, tags FROM news_submissions WHERE id = ?')[0] ?? null,
            'lawsuit_news_links' => ['news_id', $rows('SELECT * FROM lawsuit_news_links WHERE news_id = ?')],
            'legislation_news_links' => ['news_id', $rows('SELECT * FROM legislation_news_links WHERE news_id = ?')]];
    }
    if ($kind === 'lawsuit') {
        return ['kind' => 'lawsuit', 'id' => $id,
            'row' => $rows('SELECT source_urls, document_urls FROM lawsuits WHERE id = ?')[0] ?? null,
            'lawsuit_news_links' => ['lawsuit_id', $rows('SELECT * FROM lawsuit_news_links WHERE lawsuit_id = ?')]];
    }
    return ['kind' => 'bill', 'id' => $id, 'row' => null,
        'legislation_news_links' => ['legislation_id', $rows('SELECT * FROM legislation_news_links WHERE legislation_id = ?')]];
}

/** Saves a snapshot taken before a change; returns its Undo token. */
function kop_dm_save_undo(array $snap): string {
    $token = bin2hex(random_bytes(8));
    $log = get_option('kop_dm_change_undo', []);
    $log = is_array($log) ? $log : [];
    $log[$token] = $snap + ['at' => time()];
    if (count($log) > 100) $log = array_slice($log, -100, null, true);
    update_option('kop_dm_change_undo', $log, false);
    return $token;
}

/** Puts an article, case or bill and its links back exactly as the snapshot had them. */
function kop_dm_restore(PDO $pdo, string $token): string {
    $log = get_option('kop_dm_change_undo', []);
    if (!is_array($log) || empty($log[$token])) throw new RuntimeException('That change cannot be undone any more.');
    $u = $log[$token];
    $id = (int)$u['id'];
    kop_legislation_news_links_ensure_table($pdo);
    $pdo->beginTransaction();
    try {
        if ($u['row']) {
            $table = ['news' => 'news_submissions', 'lawsuit' => 'lawsuits'][$u['kind']];
            $cols = array_keys($u['row']);
            $pdo->prepare("UPDATE `$table` SET " . implode(', ', array_map(static function ($c) { return "`$c` = ?"; }, $cols)) . ' WHERE id = ?')
                ->execute(array_merge(array_values($u['row']), [$id]));
        }
        foreach (['lawsuit_news_links', 'legislation_news_links'] as $t) {
            if (!isset($u[$t])) continue;
            [$col, $rows] = $u[$t];
            $pdo->prepare("DELETE FROM `$t` WHERE `$col` = ?")->execute([$id]);
            foreach ($rows as $r) {
                $c = array_keys($r);
                $pdo->prepare("INSERT INTO `$t` (`" . implode('`, `', $c) . '`) VALUES (' . implode(', ', array_fill(0, count($c), '?')) . ')')
                    ->execute(array_values($r));
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    unset($log[$token]);
    update_option('kop_dm_change_undo', $log, false);
    return 'Undone: it is as it was.';
}

/**
 * An article's kind: 'lawsuit' (article_type lawsuit), 'legislation' (tagged
 * Legislation) or 'general'. Only the type and the Lawsuit/Legislation tags
 * change; its other tags stay.
 */
function kop_dm_set_news_kind(PDO $pdo, int $id, string $to): array {
    if (!in_array($to, ['general', 'lawsuit', 'legislation'], true)) throw new RuntimeException('Which kind?');
    $stmt = $pdo->prepare('SELECT article_type, tags FROM news_submissions WHERE id = ?');
    $stmt->execute([$id]);
    $r = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$r) throw new RuntimeException('That article is gone.');
    $snap = kop_dm_snapshot($pdo, 'news', $id);
    $tags = json_decode((string)$r['tags'], true);
    $tags = is_array($tags) ? $tags : [];
    $tags = array_values(array_filter($tags, static function ($t) {
        return !in_array(strtolower(trim((string)$t)), ['lawsuit', 'lawsuits', 'legislation'], true);
    }));
    $type = (string)$r['article_type'];
    if ($to === 'lawsuit') {
        $type = 'lawsuit';
        $tags[] = 'Lawsuit';
    } else {
        if ($type === 'lawsuit') $type = 'general';
        if ($to === 'legislation') $tags[] = 'Legislation';
    }
    $pdo->prepare('UPDATE news_submissions SET article_type = ?, tags = ? WHERE id = ?')
        ->execute([$type, json_encode($tags, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $id]);
    $label = ['general' => 'general news', 'lawsuit' => 'lawsuit news', 'legislation' => 'legislation news'][$to];
    return ['message' => 'Now filed as ' . $label . '.', 'undo_token' => kop_dm_save_undo($snap)];
}

/**
 * Ties an article to a case or bill, or takes the tie off. Off on an
 * automatic case match marks it excluded so the sync does not bring it back.
 */
function kop_dm_coverage_link(PDO $pdo, string $kind, int $id, int $newsId, string $op): array {
    if (!in_array($kind, ['lawsuit', 'bill'], true) || !in_array($op, ['add', 'remove'], true)) throw new RuntimeException('Unknown change.');
    $stmt = $pdo->prepare('SELECT article_title FROM news_submissions WHERE id = ?');
    $stmt->execute([$newsId]);
    $title = $stmt->fetchColumn();
    if ($title === false) throw new RuntimeException('That article is gone.');
    $snap = kop_dm_snapshot($pdo, $kind, $id);
    $by = kop_dm_by();
    if ($kind === 'lawsuit') {
        require_once __DIR__ . '/lawsuit-news-links.php';
        kop_lawsuit_news_links_allow_excluded($pdo);
        $has = $pdo->prepare('SELECT link_type FROM lawsuit_news_links WHERE lawsuit_id = ? AND news_id = ?');
        $has->execute([$id, $newsId]);
        $type = $has->fetchColumn();
        if ($op === 'add') {
            if ($type === false) {
                $pdo->prepare("INSERT INTO lawsuit_news_links (lawsuit_id, news_id, link_type, match_reason, created_by) VALUES (?, ?, 'manual', 'data-manager', ?)")
                    ->execute([$id, $newsId, $by]);
            } else {
                $pdo->prepare("UPDATE lawsuit_news_links SET link_type = 'manual', created_by = ? WHERE lawsuit_id = ? AND news_id = ?")->execute([$by, $id, $newsId]);
            }
        } elseif ($type === 'auto') {
            $pdo->prepare("UPDATE lawsuit_news_links SET link_type = 'excluded', created_by = ? WHERE lawsuit_id = ? AND news_id = ?")->execute([$by, $id, $newsId]);
        } else {
            $pdo->prepare('DELETE FROM lawsuit_news_links WHERE lawsuit_id = ? AND news_id = ?')->execute([$id, $newsId]);
        }
    } else {
        kop_legislation_news_links_ensure_table($pdo);
        if ($op === 'add') {
            $pdo->prepare('INSERT IGNORE INTO legislation_news_links (legislation_id, news_id, created_by) VALUES (?, ?, ?)')->execute([$id, $newsId, $by]);
        } else {
            $pdo->prepare('DELETE FROM legislation_news_links WHERE legislation_id = ? AND news_id = ?')->execute([$id, $newsId]);
        }
    }
    $where = $kind === 'lawsuit' ? 'the case' : 'the bill';
    return ['message' => ($op === 'add' ? '"' . $title . '" now shows as news coverage of ' . $where . '.' : '"' . $title . '" no longer shows on ' . $where . '.'),
        'undo_token' => kop_dm_save_undo($snap)];
}

/** Moves one of a lawsuit's links between its court documents and its other sources. */
function kop_dm_lawsuit_doc_move(PDO $pdo, int $id, string $url, string $to): array {
    if (!in_array($to, ['documents', 'sources'], true)) throw new RuntimeException('Move it where?');
    $stmt = $pdo->prepare('SELECT source_urls, document_urls FROM lawsuits WHERE id = ?');
    $stmt->execute([$id]);
    $r = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$r) throw new RuntimeException('That case is gone.');
    $snap = kop_dm_snapshot($pdo, 'lawsuit', $id);
    $src = kop_dm_json_list($r['source_urls']);
    $doc = kop_dm_json_list($r['document_urls']);
    $from = $to === 'documents' ? $src : $doc;
    if (!in_array($url, $from, true)) throw new RuntimeException('That link is not in that list any more. Close and open the case again.');
    $from = array_values(array_filter($from, static function ($u) use ($url) { return $u !== $url; }));
    if ($to === 'documents') {
        $src = $from;
        if (!in_array($url, $doc, true)) $doc[] = $url;
    } else {
        $doc = $from;
        if (!in_array($url, $src, true)) $src[] = $url;
    }
    $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
    $pdo->prepare('UPDATE lawsuits SET source_urls = ?, document_urls = ? WHERE id = ?')->execute([json_encode($src, $flags), json_encode($doc, $flags), $id]);
    return ['message' => $to === 'documents' ? 'Now listed as a court document.' : 'Now listed as a source (news or other page), not a court document.',
        'undo_token' => kop_dm_save_undo($snap)];
}

/** Records by title for the pickers: kind news|lawsuit|bill. */
function kop_dm_find_records(PDO $pdo, string $kind, string $q): array {
    if (!isset(kop_dm_record_kinds()[$kind]) || mb_strlen($q) < 2) return [];
    $out = [];
    foreach (array_slice(kop_dm_record_items($pdo, $kind, $q), 0, 15) as $it) {
        $out[] = ['id' => $it['id'], 'title' => $it['display_name'], 'meta' => $it['place'], 'status' => $it['status']];
    }
    return $out;
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
    if ($action === 'find_record') {
        return ['success' => true, 'results' => kop_dm_find_records($pdo, (string)($_GET['kind'] ?? ''), trim((string)($_GET['q'] ?? '')))];
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
    if ($action === 'news_kind') {
        return ['success' => true] + kop_dm_set_news_kind($pdo, (int)($input['id'] ?? 0), (string)($input['to'] ?? ''));
    }
    if ($action === 'coverage_link') {
        return ['success' => true] + kop_dm_coverage_link($pdo, (string)($input['kind'] ?? ''), (int)($input['id'] ?? 0),
            (int)($input['news_id'] ?? 0), (string)($input['op'] ?? ''));
    }
    if ($action === 'lawsuit_doc_move') {
        return ['success' => true] + kop_dm_lawsuit_doc_move($pdo, (int)($input['id'] ?? 0), (string)($input['url'] ?? ''), (string)($input['to'] ?? ''));
    }
    if ($action === 'undo_change') {
        return ['success' => true, 'message' => kop_dm_restore($pdo, (string)($input['token'] ?? ''))];
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
