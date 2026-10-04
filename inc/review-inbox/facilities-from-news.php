<?php
/**
 * Review inbox source: facility names the hourly news scan found with no
 * record (inc/facility-discovery.php). Held-back names (possible duplicate,
 * other name era, no place) wait for a person: link the article to an
 * existing record (kop_facdisc_link_by_hand()) or create one from the
 * details (kop_facdisc_create_by_hand()). A record the scan created can be
 * removed again while nobody has edited it (kop_facdisc_remove()).
 */

if (!defined('ABSPATH')) {
    exit;
}

kop_rinbox_register('facilities-from-news', function () {
    if (!function_exists('kop_facdisc_create_by_hand')) return null;
    $waiting = kop_rinbox_facdisc_waiting();
    return array(
        'label'    => 'Facilities from news',
        'group'    => 'Found by the news scans',
        'help'     => 'Programs named in news articles that have no record yet. A youth residential program with a known state or country is added as a new record by the hourly scan; the names it held back wait here. Link the article to the right record, or check the details and create a new record. Remove takes out a record the scan made while nobody has edited it.'
            . ($waiting !== null ? ' ' . $waiting . ' saved articles are not scanned yet; the hourly run reads a handful at a time.' : ''),
        'views'    => array(
            'held'    => 'To decide',
            'created' => 'Created by the scan',
            'aside'   => 'Set aside (not a facility)',
            'matched' => 'Linked to a record',
            'removed' => 'Removed',
            'all'     => 'All recent',
        ),
        'view_counts' => 'kop_rinbox_facdisc_view_counts',
        'tools'    => array(array(
            'id' => 'scan', 'label' => 'Scan the next articles now', 'style' => 'neutral',
            'help' => 'Reads saved articles the hourly scan has not reached yet (about 15 at a time). Give article numbers to scan only those.',
            'params' => array(array('name' => 'news', 'label' => 'or only these article numbers', 'type' => 'text', 'value' => '', 'optional' => true)),
        )),
        'tool'     => 'kop_rinbox_facdisc_tool',
        'tool_url' => admin_url('admin.php?page=kop-facilities-from-news'),
        'count'    => function () {
            $pdo = kop_closure_pdo();
            kop_facdisc_ensure_tables($pdo);
            return (int) $pdo->query("SELECT COUNT(*) FROM news_facility_candidates WHERE decision IN ('possible_duplicate','other_era','needs_place')")->fetchColumn();
        },
        'list'     => 'kop_rinbox_facdisc_list',
        'get'      => function ($key) {
            $r = kop_rinbox_facdisc_row(kop_closure_pdo(), (int) $key);
            return $r ? kop_rinbox_facdisc_item($r) : null;
        },
        'act'      => 'kop_rinbox_facdisc_act',
        'save'     => 'kop_rinbox_facdisc_save',
    );
});

/** The decisions each view lists ('all' lists every one). */
function kop_rinbox_facdisc_view_decisions() {
    return array(
        'held'    => array('possible_duplicate', 'other_era', 'needs_place'),
        'created' => array('created'),
        'aside'   => array('provider', 'not_facility', 'indigenous_school'),
        'matched' => array('matched'),
        'removed' => array('removed'),
    );
}

function kop_rinbox_facdisc_view_counts(array $q = array()) {
    $by = array();
    foreach (kop_closure_pdo()->query('SELECT decision, COUNT(*) AS n FROM news_facility_candidates GROUP BY decision') as $row) $by[$row['decision']] = (int) $row['n'];
    $out = array();
    foreach (kop_rinbox_facdisc_view_decisions() as $view => $decisions) {
        $out[$view] = 0;
        foreach ($decisions as $d) $out[$view] += $by[$d] ?? 0;
    }
    $out['all'] = array_sum($by);
    return $out;
}

/** Saved articles the scan has not read yet, as the old screen counts them (cached a minute), or null. */
function kop_rinbox_facdisc_waiting() {
    $n = get_transient('kop_rinbox_facdisc_waiting');
    if ($n !== false) return (int) $n;
    try {
        $pdo = kop_closure_pdo();
        if (!$pdo) return null;
        kop_facdisc_ensure_tables($pdo);
        $n = (int) $pdo->query("SELECT COUNT(*) FROM news_submissions n LEFT JOIN news_facility_scans s ON s.news_id = n.id
                                 WHERE s.news_id IS NULL AND n.status NOT IN ('rejected','deleted','promotional')")->fetchColumn();
    } catch (Throwable $e) {
        return null;
    }
    set_transient('kop_rinbox_facdisc_waiting', $n, MINUTE_IN_SECONDS);
    return $n;
}

/** The old screen's scan form: the next articles, or only the article numbers given; one line per name it decided. */
function kop_rinbox_facdisc_tool($id, array $params) {
    if ($id !== 'scan') throw new RuntimeException('Unknown tool.');
    $pdo = kop_closure_pdo();
    $decisions = kop_facdisc_decisions();
    $ids = array_values(array_filter(array_map('intval', preg_split('/[\s,#]+/', (string) ($params['news'] ?? '')))));
    $lines = array();
    $log = static function ($news, $outcome, $decided, $detail) use (&$lines, $decisions) {
        if ($outcome === 'error') $lines[] = '#' . $news['id'] . ': failed (' . $detail . ')';
        foreach ($decided as $name => $d) {
            $lines[] = '#' . $news['id'] . ': ' . $name . ' - ' . ($decisions[$d[0]] ?? $d[0]) . ($d[1] ? ' #' . $d[1] : '');
        }
    };
    $r = kop_facdisc_scan_batch($pdo, $ids ? count($ids) : 15, 110, true, $ids, $log);
    delete_transient('kop_rinbox_facdisc_waiting');
    $c = $r['counts'];
    $msg = sprintf('Scanned %d articles: %d new facilities, %d closure reports matched, %d failed%s.',
        $c['scanned'], $c['created'], $c['rematched'] ?? 0, $c['error'],
        $c['error'] ? ' (Groq and Gemini allow a few articles a minute; the hourly run carries on)' : '');
    return array('message' => $msg . ($lines ? "\n" . implode("\n", array_slice($lines, 0, 40)) : ''));
}

/** "Closed, ended 2019" for a record as it is now (the old screen's facility cell), or ''. */
function kop_rinbox_facdisc_record_now(PDO $pdo, $fid) {
    $st = $pdo->prepare('SELECT status, end_year FROM facilities_v2 WHERE id = ?');
    $st->execute(array((int) $fid));
    $f = $st->fetch(PDO::FETCH_ASSOC);
    return $f ? ($f['status'] ?: 'Unknown') . ($f['end_year'] ? ', ended ' . (int) $f['end_year'] : '') : '';
}

/** The decisions a person can still turn into a record (what kop_facdisc_create_by_hand() accepts). */
function kop_rinbox_facdisc_open_decisions() {
    return array('possible_duplicate', 'other_era', 'needs_place', 'provider', 'not_facility', 'removed');
}

function kop_rinbox_facdisc_row(PDO $pdo, $id) {
    $st = $pdo->prepare('SELECT c.*, n.article_title, n.publication_name, n.publication_date, n.article_url
                           FROM news_facility_candidates c LEFT JOIN news_submissions n ON n.id = c.news_id WHERE c.id = ?');
    $st->execute(array((int) $id));
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

function kop_rinbox_facdisc_list(array $q) {
    $pdo = kop_closure_pdo();
    kop_facdisc_ensure_tables($pdo);
    $views = kop_rinbox_facdisc_view_decisions();
    $where = $q['view'] === 'all' ? '1=1'
        : "c.decision IN ('" . implode("','", $views[$q['view']] ?? $views['held']) . "')";
    $params = array();
    if ($q['search'] !== '') {
        $where .= ' AND (c.mention LIKE ? OR n.article_title LIKE ?)';
        $like = '%' . $q['search'] . '%';
        $params = array($like, $like);
    }
    $from = "FROM news_facility_candidates c LEFT JOIN news_submissions n ON n.id = c.news_id WHERE $where";
    $st = $pdo->prepare("SELECT COUNT(*) $from");
    $st->execute($params);
    $total = (int) $st->fetchColumn();
    $st = $pdo->prepare("SELECT c.*, n.article_title, n.publication_name, n.publication_date, n.article_url
                          $from ORDER BY c.updated_at DESC, c.id DESC LIMIT " . (int) $q['limit'] . ' OFFSET ' . (int) $q['offset']);
    $st->execute($params);
    return array('items' => array_map('kop_rinbox_facdisc_item', $st->fetchAll(PDO::FETCH_ASSOC)), 'total' => $total);
}

function kop_rinbox_facdisc_item(array $r) {
    $decisions = kop_facdisc_decisions();
    $detail = json_decode((string) $r['detail'], true) ?: array();
    $e = (array) ($detail['entry'] ?? array());
    $place = trim(implode(', ', array_filter(array($e['city'] ?? '', ($e['state'] ?? '') ?: ($e['country'] ?? '')))));
    $bits = array();
    if (!empty($e['officialName']) && $e['officialName'] !== $r['mention']) $bits[] = $e['officialName'];
    if ($place !== '') $bits[] = $place;
    if (!empty($e['type'])) $bits[] = $e['type'];
    if (!empty($e['status']) && $e['status'] !== 'Unknown') $bits[] = $e['status'];
    $hint = array(
        'possible_duplicate' => 'Looks like the record linked below. ',
        'other_era'          => 'May be an earlier or later name of the record linked below; each name gets its own record. ',
        'needs_place'        => 'The article gives no state or country. ',
    )[$r['decision']] ?? '';
    $evidence = trim((string) ($e['evidence'] ?? ''));
    $text = $hint
        . ($evidence !== '' ? '"' . kop_rinbox_excerpt($evidence, 2000) . '"' : '')
        . ($r['article_title'] ? "\nFrom: " . $r['article_title'] . ($r['publication_name'] ? ' (' . $r['publication_name'] . ($r['publication_date'] ? ', ' . $r['publication_date'] : '') . ')' : '') : '');

    $open = in_array($r['decision'], kop_rinbox_facdisc_open_decisions(), true);
    $actions = array();
    $fields = array();
    $links = array();
    if ($open) {
        $actions[] = array('id' => 'link', 'label' => 'Link the article to this record', 'style' => 'approve',
            'params' => array(array('name' => 'facility_id', 'label' => 'Record', 'type' => 'facility', 'value' => (int) $r['facility_id'])));
        // The old screen's "Not in the database? Create it" form: the details as the scan read them, changeable here.
        $types = kop_rinbox_options(array_combine(kop_facdisc_types(), kop_facdisc_types()));
        $actions[] = array('id' => 'create', 'label' => 'Create this facility', 'style' => 'neutral',
            'confirm' => 'Create a new facility record with these details?',
            'params' => array(
                array('name' => 'officialName', 'label' => 'Name', 'type' => 'text', 'value' => (string) (($e['officialName'] ?? '') ?: $r['mention'])),
                array('name' => 'city', 'label' => 'City', 'type' => 'text', 'value' => (string) ($e['city'] ?? ''), 'optional' => true),
                array('name' => 'state', 'label' => 'State', 'type' => 'text', 'value' => (string) ($e['state'] ?? ''), 'optional' => true),
                array('name' => 'country', 'label' => 'Country', 'type' => 'text', 'value' => (string) ($e['country'] ?? ''), 'optional' => true),
                array('name' => 'type', 'label' => 'Type', 'type' => 'select', 'options' => $types, 'value' => (string) ($e['type'] ?? ''), 'optional' => true),
            ));
        $fields = array(
            array('name' => 'officialName', 'label' => 'Name', 'type' => 'text', 'value' => (string) (($e['officialName'] ?? '') ?: $r['mention'])),
            array('name' => 'city', 'label' => 'City', 'type' => 'text', 'value' => (string) ($e['city'] ?? '')),
            array('name' => 'state', 'label' => 'State (two letters)', 'type' => 'text', 'value' => (string) ($e['state'] ?? '')),
            array('name' => 'country', 'label' => 'Country', 'type' => 'text', 'value' => (string) ($e['country'] ?? '')),
            array('name' => 'type', 'label' => 'Type', 'type' => 'select', 'options' => kop_rinbox_options(array_combine(kop_facdisc_types(), kop_facdisc_types())),
                'value' => (string) ($e['type'] ?? ''), 'category' => true),
        );
    } elseif ($r['decision'] === 'created') {
        $actions[] = array('id' => 'remove', 'label' => 'Remove the record it created', 'style' => 'undo',
            'confirm' => 'Remove the record the scan created for this? The scan will not create it again.');
    } elseif ($r['decision'] === 'indigenous_school') {
        $links[] = array('label' => 'Filed at Indigenous Schools', 'url' => admin_url('admin.php?page=kop-indigenous-schools'));
    }
    // What the old screen's middle column showed: what the scan read, and the record as it is now.
    $details = array();
    if (!empty($e['kind'])) $details[] = array('label' => 'The scan read it as', 'value' => ucfirst((string) $e['kind']));
    if (!empty($e['status']) && $e['status'] !== 'Unknown') $details[] = array('label' => 'Status in the article', 'value' => (string) $e['status']);
    if ((int) $r['facility_id'] > 0) {
        $now = kop_rinbox_facdisc_record_now(kop_closure_pdo(), (int) $r['facility_id']);
        $details[] = array('label' => 'Record now', 'value' => $now !== '' ? $now : 'gone (no such record)');
    }
    $details[] = array('label' => 'Article', 'value' => trim('#' . (int) $r['news_id'] . ' · ' . trim(($r['publication_name'] ?? '') . ' ' . ($r['publication_date'] ?? '')), ' ·'));
    if (!empty($r['reviewed_by'])) $details[] = array('label' => 'Decided by', 'value' => (string) $r['reviewed_by']);
    return array(
        'key'          => (string) $r['id'],
        'title'        => (string) $r['mention'],
        'details'      => $details,
        'subtitle'     => implode(' · ', $bits),
        'url'          => (string) ($r['article_url'] ?? ''),
        'text'         => trim($text),
        'created'      => (string) $r['updated_at'],
        'status'       => $r['decision'],
        'status_label' => $decisions[$r['decision']] ?? $r['decision'],
        'facility'     => kop_rinbox_facility($r['facility_id']),
        'fields'       => $fields,
        'actions'      => $actions,
        'links'        => $links,
    );
}

function kop_rinbox_facdisc_act($key, $action, array $params) {
    $pdo = kop_closure_pdo();
    $id = (int) $key;
    $user = kop_rinbox_reviewer();
    $r = kop_rinbox_facdisc_row($pdo, $id);
    if (!$r) throw new RuntimeException('That name is gone (someone may have handled it).');
    $article = $r['article_title'] ? '"' . $r['article_title'] . '"' : 'the article';
    switch ($action) {
        case 'link':
            $fid = (int) ($params['facility_id'] ?? 0);
            if ($fid <= 0) throw new RuntimeException('Pick the facility record to link first.');
            $fid = kop_facdisc_link_by_hand($pdo, $id, $fid, $user);
            kop_rinbox_flush_counts();
            return array('message' => 'Linked ' . $article . ' to ' . wp_strip_all_tags(kop_facility_finder_label($pdo, $fid)) . '. Its page lists the article now.');
        case 'create':
            // The details saved on the item, or any passed with the button.
            $detail = json_decode((string) $r['detail'], true) ?: array();
            $fields = array_merge((array) ($detail['entry'] ?? array()), array_intersect_key($params, array_flip(array('officialName', 'city', 'state', 'country', 'type'))));
            list($decision, $fid) = kop_facdisc_create_by_hand($pdo, $id, $fields, $user);
            kop_rinbox_flush_counts();
            $label = wp_strip_all_tags(kop_facility_finder_label($pdo, $fid));
            return array('message' => $decision === 'created'
                ? 'Created ' . $label . ' and linked ' . $article . ' to it. Remove (on the Created tab) takes it out again.'
                : 'That name and place is already in the database as ' . $label . ', so ' . $article . ' is linked to it. No new record was made.');
        case 'remove':
            $was = $r['facility_id'] ? wp_strip_all_tags(kop_facility_finder_label($pdo, (int) $r['facility_id'])) : '"' . $r['mention'] . '"';
            kop_facdisc_remove($pdo, $id, $user);
            kop_rinbox_flush_counts();
            return array('message' => 'Removed the record for ' . $was . '. The scan will not create it again; it waits on the Removed tab.');
    }
    throw new RuntimeException('Unknown action.');
}

/** Save the details a new record would be made from (the scan's entry in detail). */
function kop_rinbox_facdisc_save($key, array $fields) {
    $pdo = kop_closure_pdo();
    $r = kop_rinbox_facdisc_row($pdo, (int) $key);
    if (!$r) throw new RuntimeException('That name is gone.');
    if (!in_array($r['decision'], kop_rinbox_facdisc_open_decisions(), true)) {
        throw new RuntimeException('This name already has a record; edit the record in the data form instead.');
    }
    $detail = json_decode((string) $r['detail'], true) ?: array();
    $entry = (array) ($detail['entry'] ?? array());
    $changed = false;
    foreach (array('officialName', 'city', 'state', 'country', 'type') as $k) {
        if (!array_key_exists($k, $fields)) continue;
        $v = trim(preg_replace('/\s+/u', ' ', (string) $fields[$k]));
        if ($k === 'officialName' && $v === '') throw new RuntimeException('The name cannot be empty.');
        if ($k === 'state') {
            $v = strtoupper($v);
            if ($v !== '' && !preg_match('/^[A-Z]{2}$/', $v)) throw new RuntimeException('State: write the two-letter code, like UT or NC.');
        }
        if ($k === 'type' && $v !== '' && !in_array($v, kop_facdisc_types(), true)) throw new RuntimeException('Type: pick one from the list.');
        $entry[$k] = mb_substr($v, 0, 255);
        $changed = true;
    }
    if (!$changed) return array('message' => 'Nothing to save.');
    $entry += array('name' => $r['mention'], 'kind' => 'facility');
    $detail['entry'] = $entry;
    $pdo->prepare('UPDATE news_facility_candidates SET detail = ?, reviewed_by = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?')
        ->execute(array(wp_json_encode($detail), kop_rinbox_reviewer(), (int) $key));
    return array('message' => 'Saved. Create a new record uses these details.');
}
